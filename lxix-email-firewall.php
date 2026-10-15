<?php

/**
 * Plugin Name: lxix Email Firewall
 * Plugin URI: https://github.com/lxix/wp-email-firewall
 * Description: Suppresses emails sent to blocked email addresses and domains.
 * Version: 1.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: lxix
 * Author URI: https://github.com/lxix
 * License: Apache-2.0
 * License URI: https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain: lxix-email-firewall
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// The log is the plugin's own table, which no WordPress API covers. Its queries aren't cached:
// every blocked email changes it, and the log page has to show it as it is.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

final class WPEF_Plugin
{
    private const PER_PAGE = 20;

    private const DB_VERSION = 3;

    /**
     * Added to the wp_mail() arguments when every recipient has been removed,
     * so blockPreWpMail() knows the email must not be sent.
     */
    private const SUPPRESSED_KEY = 'wpef_suppressed';

    /**
     * Fluent Forms entry meta: the entry's blocked address has been logged. Its notifications may be
     * sent in later requests (after a payment, from the async queue), which must not log it again.
     */
    private const FLUENT_FORMS_LOGGED_META = 'wpef_logged';

    private static ?self $instance = null;

    /**
     * Set while a form submission from a blocked address is processed:
     * every email sent during that submission is suppressed.
     */
    private bool $suppressRequestMail = false;

    /** Set while a Fluent Forms notification of a blocked entry is suppressed outside its submission. */
    private bool $suppressingFluentFormsNotification = false;

    /** @var string[] The REST routes (prefixes) whose request ends a form submission. */
    private array $restSubmissionRoutes = [];

    /** @var array<string, array{0: string, 1: string[]}> */
    private array $listCache = [];

    /** @var array{0: string, 1: array<string, true>} The WooCommerce email being sent, and the addresses logged for it. */
    private array $wooCommerceLogContext = ['', []];

    /** @var array<string, true> */
    private array $reportedInvalid = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'install']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('plugins_loaded', [$this, 'maybeUpgrade'], 5);
        add_action('wpef_cleanup_logs', [$this, 'cleanupLogs']);
        add_filter('wpmu_drop_tables', [$this, 'addLogTableToDroppedTables']);

        add_action('admin_menu', [$this, 'registerAdminPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_init', [$this, 'addPrivacyPolicyContent']);

        // Late priorities: recipients added by other plugins are checked too,
        // and a later callback can't undo the short-circuit.
        add_filter('wp_mail', [$this, 'filterWpMail'], PHP_INT_MAX);
        add_filter('pre_wp_mail', [$this, 'blockPreWpMail'], PHP_INT_MAX, 2);

        add_action('plugins_loaded', [$this, 'maybeInitElementor'], 20);
        add_action('plugins_loaded', [$this, 'maybeInitWpForms'], 20);
        add_action('plugins_loaded', [$this, 'maybeInitContactForm7'], 20);
        add_action('plugins_loaded', [$this, 'maybeInitFluentForms'], 20);
        add_action('plugins_loaded', [$this, 'maybeInitForminator'], 20);
        add_action('plugins_loaded', [$this, 'maybeInitMetForm'], 20);
        add_action('plugins_loaded', [$this, 'maybeInitNinjaForms'], 20);
        add_action('plugins_loaded', [$this, 'maybeInitSureForms'], 20);
        add_action('plugins_loaded', [$this, 'maybeInitFormidable'], 20);
        add_filter('woocommerce_email_classes', [$this, 'registerWooCommerceFilters'], PHP_INT_MAX);

        add_filter(
                'plugin_action_links_' . plugin_basename(__FILE__),
                [$this, 'addSettingsLink']
        );

        add_action('update_option_wpef_enable_logging', [$this, 'handleLoggingToggle'], 10, 2);
        // On the very first save WordPress adds the option instead of updating it.
        add_action('add_option_wpef_enable_logging', [$this, 'handleLoggingAdded'], 10, 2);
    }

    /* ==========================
     * Install & Cron
     * ========================== */

    private function table(): string
    {
        global $wpdb;

        // Resolved on every call, so switch_to_blog() on multisite logs to the right site.
        // SQL strings spell out {$wpdb->prefix}wpef_log instead: the WordPress coding standards
        // only accept $wpdb properties interpolated into a query.
        return $wpdb->prefix . 'wpef_log';
    }

    public function install(): void
    {
        global $wpdb;

        $table = $this->table();
        $installedVersion = (int) get_option('wpef_db_version', 0);
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            context VARCHAR(30) NOT NULL,
            email VARCHAR(190) NOT NULL,
            ip VARCHAR(45) NULL,
            PRIMARY KEY  (id),
            KEY email (email),
            KEY created_at (created_at),
            KEY context (context)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Up to 1.0.0 the log stored the site's local time; it is UTC since DB version 2.
        if ($installedVersion < 2 && $this->claimMigration('wpef_migrated_utc')) {
            $this->migrateLogTimestampsToUtc();
        }

        // The WooCommerce integration can't be turned off since DB version 3.
        if ($installedVersion < 3) {
            delete_option('wpef_enable_woocommerce');
        }

        update_option('wpef_db_version', self::DB_VERSION);

        if ($this->isLoggingEnabled()) {
            $this->scheduleCleanup();
        }
    }

    /**
     * Creates or upgrades the log table for the current site. Also covers plugin updates
     * without reactivation and the sites of a network-activated multisite install.
     */
    public function maybeUpgrade(): void
    {
        if ((int) get_option('wpef_db_version', 0) < self::DB_VERSION) {
            $this->install();
            return;
        }

        if ($this->isLoggingEnabled()) {
            $this->scheduleCleanup();
        }
    }

    /**
     * Lets exactly one request run a migration, even when several upgrade at the same time:
     * only one of them can insert the marker row.
     */
    private function claimMigration(string $marker): bool
    {
        global $wpdb;

        $inserted = $wpdb->query(
                $wpdb->prepare(
                        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
                        $marker,
                        '1',
                        'no'
                )
        );

        return $inserted === 1;
    }

    /**
     * Converts every entry with the UTC offset that was in effect at its own time, so entries from
     * both sides of a daylight saving time change are converted correctly.
     */
    private function migrateLogTimestampsToUtc(): void
    {
        global $wpdb;

        $range = $wpdb->get_row("SELECT MIN(created_at), MAX(created_at) FROM {$wpdb->prefix}wpef_log", ARRAY_N);
        if (!is_array($range) || $range[0] === null) {
            return;
        }

        $offsets = $this->localTimeOffsets((string) $range[0], (string) $range[1]);
        if (array_filter(array_column($offsets, 1)) === []) {
            return;
        }

        // A single statement: every entry is converted once, from its original value, even when
        // the conversion moves it into another offset's period.
        $shift = 'DATE_SUB(created_at, INTERVAL %d SECOND)';
        $conditions = '';
        $args = [];

        foreach (array_reverse(array_slice($offsets, 1)) as [$start, $offset]) {
            $conditions .= " WHEN created_at >= %s THEN {$shift}";
            array_push($args, $start, $offset);
        }

        $args[] = $offsets[0][1];
        $newValue = $conditions === '' ? $shift : "CASE{$conditions} ELSE {$shift} END";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $newValue holds only $shift and placeholders.
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}wpef_log SET created_at = {$newValue}", $args));
    }

    /**
     * The UTC offsets of the site's timezone between two local times, oldest first.
     *
     * @return array<int, array{0: string, 1: int}> The local time each offset starts at (empty for
     *                                              the first one) and the offset in seconds.
     */
    private function localTimeOffsets(string $oldest, string $newest): array
    {
        $timezone = wp_timezone();
        $utc = new DateTimeZone('UTC');

        // The bounds are local times, so the range is widened by a day to cover any offset.
        $transitions = $timezone->getTransitions(
                (new DateTimeImmutable($oldest, $utc))->getTimestamp() - DAY_IN_SECONDS,
                (new DateTimeImmutable($newest, $utc))->getTimestamp() + DAY_IN_SECONDS
        );

        // A fixed offset (e.g. "UTC+2" in the settings) has no transitions.
        if (!is_array($transitions) || $transitions === []) {
            return [['', $timezone->getOffset(new DateTimeImmutable('now', $timezone))]];
        }

        $offsets = [];
        foreach ($transitions as $index => $transition) {
            // The first entry is the offset in effect at the start of the range, not a change.
            $start = $index === 0 ? '' : gmdate('Y-m-d H:i:s', $transition['ts'] + $transition['offset']);
            $offsets[] = [$start, (int) $transition['offset']];
        }

        return $offsets;
    }

    public function scheduleCleanup(): void
    {
        if (!wp_next_scheduled('wpef_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'wpef_cleanup_logs');
        }
    }

    /**
     * @param mixed $networkWide
     */
    public function deactivate($networkWide = false): void
    {
        if (is_multisite() && $networkWide) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                wp_clear_scheduled_hook('wpef_cleanup_logs');
                restore_current_blog();
            }

            return;
        }

        wp_clear_scheduled_hook('wpef_cleanup_logs');
    }

    /**
     * Deleting a site of a multisite network drops the tables in this list.
     *
     * @param mixed $tables
     * @return mixed
     */
    public function addLogTableToDroppedTables($tables)
    {
        if (is_array($tables)) {
            $tables[] = $this->table();
        }

        return $tables;
    }

    public function cleanupLogs(): void
    {
        if (!$this->isLoggingEnabled()) {
            return;
        }

        global $wpdb;

        $days = absint(get_option('wpef_log_retention', 30));
        if ($days <= 0) {
            return;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

        $wpdb->query(
                $wpdb->prepare(
                        "DELETE FROM {$wpdb->prefix}wpef_log WHERE created_at < %s",
                        $cutoff
                )
        );
    }

    /* ==========================
     * Settings
     * ========================== */

    public function registerSettings(): void
    {
        register_setting('wpef_settings', 'wpef_blocked_emails', [
                'sanitize_callback' => [$this, 'sanitizeEmailList'],
        ]);

        register_setting('wpef_settings', 'wpef_blocked_domains', [
                'sanitize_callback' => [$this, 'sanitizeDomainList'],
        ]);

        register_setting('wpef_settings', 'wpef_enable_elementor', [
                'sanitize_callback' => 'intval',
                'default' => 1,
        ]);

        register_setting('wpef_settings', 'wpef_enable_wpforms', [
                'sanitize_callback' => 'intval',
                'default' => 1,
        ]);

        register_setting('wpef_settings', 'wpef_enable_contact_form_7', [
                'sanitize_callback' => 'intval',
                'default' => 1,
        ]);

        register_setting('wpef_settings', 'wpef_enable_fluent_forms', [
                'sanitize_callback' => 'intval',
                'default' => 1,
        ]);

        register_setting('wpef_settings', 'wpef_enable_forminator', [
                'sanitize_callback' => 'intval',
                'default' => 1,
        ]);

        register_setting('wpef_settings', 'wpef_enable_metform', [
                'sanitize_callback' => 'intval',
                'default' => 1,
        ]);

        register_setting('wpef_settings', 'wpef_enable_ninja_forms', [
                'sanitize_callback' => 'intval',
                'default' => 1,
        ]);

        register_setting('wpef_settings', 'wpef_enable_sureforms', [
                'sanitize_callback' => 'intval',
                'default' => 1,
        ]);

        register_setting('wpef_settings', 'wpef_enable_formidable', [
                'sanitize_callback' => 'intval',
                'default' => 1,
        ]);

        register_setting('wpef_settings', 'wpef_form_action', [
                'sanitize_callback' => [$this, 'sanitizeFormAction'],
                'default' => 'silent',
        ]);

        // Must stay before wpef_log_retention: sanitizeRetention() reads the new logging state.
        register_setting('wpef_settings', 'wpef_enable_logging', [
                'sanitize_callback' => 'intval',
                'default' => 1,
        ]);

        register_setting('wpef_settings', 'wpef_log_retention', [
                'sanitize_callback' => [$this, 'sanitizeRetention'],
                'default' => 30,
        ]);

        register_setting('wpef_settings', 'wpef_block_admin_emails', [
                'sanitize_callback' => 'intval',
                'default' => 1,
        ]);
    }

    public function isLoggingEnabled(): bool
    {
        return (int) get_option('wpef_enable_logging', 1) === 1;
    }

    public function isElementorEnabled(): bool
    {
        return (int) get_option('wpef_enable_elementor', 1) === 1;
    }

    public function isWpFormsEnabled(): bool
    {
        return (int) get_option('wpef_enable_wpforms', 1) === 1;
    }

    public function isContactForm7Enabled(): bool
    {
        return (int) get_option('wpef_enable_contact_form_7', 1) === 1;
    }

    public function isFluentFormsEnabled(): bool
    {
        return (int) get_option('wpef_enable_fluent_forms', 1) === 1;
    }

    public function isForminatorEnabled(): bool
    {
        return (int) get_option('wpef_enable_forminator', 1) === 1;
    }

    public function isMetFormEnabled(): bool
    {
        return (int) get_option('wpef_enable_metform', 1) === 1;
    }

    public function isNinjaFormsEnabled(): bool
    {
        return (int) get_option('wpef_enable_ninja_forms', 1) === 1;
    }

    public function isSureFormsEnabled(): bool
    {
        return (int) get_option('wpef_enable_sureforms', 1) === 1;
    }

    public function isFormidableEnabled(): bool
    {
        return (int) get_option('wpef_enable_formidable', 1) === 1;
    }

    public function shouldBlockAdminEmails(): bool
    {
        return (int) get_option('wpef_block_admin_emails', 1) === 1;
    }

    /**
     * What happens to a form submission that contains a blocked address: 'silent' or 'reject'.
     */
    public function getFormAction(): string
    {
        return get_option('wpef_form_action', 'silent') === 'reject' ? 'reject' : 'silent';
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function splitList($value): array
    {
        if (is_array($value)) {
            $value = implode("\n", array_filter($value, 'is_scalar'));
        }

        $parts = preg_split('/[\s,]+/', is_scalar($value) ? (string) $value : '') ?: [];

        $values = array_map(
                static function (string $v): string {
                    return strtolower(trim($v));
                },
                $parts
        );

        return array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
    }

    /**
     * @return string[]
     */
    private function parseListOption(string $option): array
    {
        $raw = (string) get_option($option, '');

        if (!isset($this->listCache[$option]) || $this->listCache[$option][0] !== $raw) {
            $this->listCache[$option] = [$raw, $this->splitList($raw)];
        }

        return $this->listCache[$option][1];
    }

    /**
     * @param mixed $value
     */
    public function sanitizeRetention($value): int
    {
        $current = max(1, absint(get_option('wpef_log_retention', 30)));

        // The field is not on the form while logging is disabled, so keep the previous value.
        if (!$this->isLoggingEnabled() || $value === null || $value === '') {
            return $current;
        }

        return max(1, absint($value));
    }

    /**
     * @param mixed $value
     */
    public function sanitizeFormAction($value): string
    {
        return $value === 'reject' ? 'reject' : 'silent';
    }

    /**
     * @param mixed $value
     */
    public function sanitizeEmailList($value): string
    {
        $valid = [];
        $invalid = [];

        foreach ($this->splitList($value) as $item) {
            if (is_email($item)) {
                $valid[] = $item;
            } else {
                $invalid[] = $item;
            }
        }

        $this->reportInvalidEntries('wpef_blocked_emails', $invalid);

        return implode("\n", array_unique($valid));
    }

    /**
     * @param mixed $value
     */
    public function sanitizeDomainList($value): string
    {
        $valid = [];
        $invalid = [];

        foreach ($this->splitList($value) as $item) {
            $domain = $this->normalizeDomain($item);

            if ($this->isValidDomain($domain)) {
                $valid[] = $domain;
            } else {
                $invalid[] = $item;
            }
        }

        $this->reportInvalidEntries('wpef_blocked_domains', $invalid);

        return implode("\n", array_unique($valid));
    }

    /**
     * Accepts the usual ways of writing a domain: "*.spam.com", ".spam.com", "@spam.com", "https://spam.com/".
     */
    private function normalizeDomain(string $item): string
    {
        if (strpos($item, '://') !== false) {
            $item = (string) wp_parse_url($item, PHP_URL_HOST);
        }

        return rtrim(ltrim($item, '*.@'), '.');
    }

    private function isValidDomain(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        if (strpos($domain, '@') !== false) {
            return false;
        }

        if (strpos($domain, '.') === false) {
            return false;
        }

        return (bool) filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    /**
     * @param string[] $invalid
     */
    private function reportInvalidEntries(string $option, array $invalid): void
    {
        // WordPress may run the sanitize callback twice for one save; add_settings_error()
        // only exists in wp-admin.
        if ($invalid === [] || isset($this->reportedInvalid[$option]) || !function_exists('add_settings_error')) {
            return;
        }

        $this->reportedInvalid[$option] = true;

        add_settings_error(
                $option,
                'wpef_invalid_entries',
                esc_html(sprintf(
                        /* translators: %s: comma-separated list of the ignored entries */
                        __('Settings saved, but these entries were invalid and have been ignored: %s', 'lxix-email-firewall'),
                        implode(', ', array_unique($invalid))
                )),
                'warning'
        );
    }

    /**
     * @return string[]
     */
    public function getBlockedEmails(): array
    {
        return $this->parseListOption('wpef_blocked_emails');
    }

    /**
     * @return string[]
     */
    public function getBlockedDomains(): array
    {
        return $this->parseListOption('wpef_blocked_domains');
    }

    private function extractDomain(string $email): string
    {
        $pos = strrpos($email, '@');
        if ($pos === false) {
            return '';
        }

        return substr($email, $pos + 1);
    }

    private function isBlocked(string $email): bool
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return false;
        }

        if (in_array($email, $this->getBlockedEmails(), true)) {
            return true;
        }

        $domain = $this->extractDomain($email);
        if ($domain === '') {
            return false;
        }

        $blockedDomains = $this->getBlockedDomains();

        // Check the domain and each parent domain, so subdomains are blocked too.
        $labels = explode('.', rtrim($domain, '.'));
        while (count($labels) > 1) {
            if (in_array(implode('.', $labels), $blockedDomains, true)) {
                return true;
            }

            array_shift($labels);
        }

        return false;
    }

    /**
     * Blocked, and not exempt as an administrator address.
     */
    private function isSuppressed(string $email): bool
    {
        if (!$this->isBlocked($email)) {
            return false;
        }

        return $this->shouldBlockAdminEmails() || !$this->isAdminRecipient($email);
    }

    private function isAdminRecipient(string $email): bool
    {
        $adminEmail = strtolower((string) get_option('admin_email'));

        if ($email === $adminEmail) {
            return true;
        }

        return user_can(email_exists($email), 'manage_options');
    }

    /* ==========================
     * Logging
     * ========================== */

    public function handleLoggingToggle($oldValue, $newValue): void
    {
        if ((int) $oldValue === 1 && (int) $newValue === 0) {
            $this->clearLog();

            wp_clear_scheduled_hook('wpef_cleanup_logs');
        }

        if ((int) $oldValue === 0 && (int) $newValue === 1) {
            $this->scheduleCleanup();
        }
    }

    /**
     * @param mixed $option
     * @param mixed $value
     */
    public function handleLoggingAdded($option, $value): void
    {
        // Before the first save the option held its default, which is "enabled".
        $this->handleLoggingToggle(1, $value);
    }

    private function clearLog(): void
    {
        global $wpdb;

        // Not TRUNCATE: that needs the DROP privilege, which hardened hosts don't grant.
        $wpdb->query("DELETE FROM {$wpdb->prefix}wpef_log");
    }

    private function logBlock(string $context, string $email): void
    {
        if (!$this->isLoggingEnabled()) {
            return;
        }

        global $wpdb;

        // The table may not exist yet on this site (e.g. a switched multisite blog).
        $this->maybeUpgrade();

        $wpdb->insert(
                $this->table(),
                [
                        'created_at' => current_time('mysql', true),
                        'context'    => sanitize_text_field($context),
                        'email'      => sanitize_email($email),
                        'ip'         => isset($_SERVER['REMOTE_ADDR'])
                                ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR']))
                                : null,
                ],
                ['%s', '%s', '%s', '%s']
        );
    }

    public function addPrivacyPolicyContent(): void
    {
        $content = '<p class="privacy-policy-tutorial">'
                . esc_html__('lxix Email Firewall stores personal data only while logging is turned on in its settings, and it sends no data to external services.', 'lxix-email-firewall')
                . '</p><p><strong class="privacy-policy-tutorial">'
                . esc_html__('Suggested text:', 'lxix-email-firewall')
                . '</strong> '
                . esc_html__('When this site stops an email to a blocked address, it records the address, the IP address of the request that tried to send the email, the time, and where the email came from (for example, a contact form). These records are deleted automatically after the retention period set by the site administrator.', 'lxix-email-firewall')
                . '</p>';

        wp_add_privacy_policy_content(__('lxix Email Firewall', 'lxix-email-firewall'), $content);
    }

    /* ==========================
     * Admin UI
     * ========================== */

    public function registerAdminPage(): void
    {
        add_options_page(
                __('lxix Email Firewall', 'lxix-email-firewall'),
                __('lxix Email Firewall', 'lxix-email-firewall'),
                'manage_options',
                'lxix-email-firewall',
                [$this, 'adminPage']
        );
    }

    public function addSettingsLink(array $links): array
    {
        $links[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('options-general.php?page=lxix-email-firewall')),
                esc_html__('Settings', 'lxix-email-firewall')
        );

        return $links;
    }

    public function adminPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $loggingEnabled = $this->isLoggingEnabled();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects the tab to show.
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'settings';
        if (!in_array($tab, ['settings', 'log'], true)) {
            $tab = 'settings';
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('lxix Email Firewall', 'lxix-email-firewall'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>"
                   href="<?php echo esc_url(admin_url('options-general.php?page=lxix-email-firewall&tab=settings')); ?>">
                    <?php echo esc_html__('Settings', 'lxix-email-firewall'); ?>
                </a>

                <?php if ($loggingEnabled) : ?>
                    <a class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>"
                       href="<?php echo esc_url(admin_url('options-general.php?page=lxix-email-firewall&tab=log')); ?>">
                        <?php echo esc_html__('Log', 'lxix-email-firewall'); ?>
                    </a>
                <?php endif; ?>
            </h2>

            <?php
            if ($tab === 'log' && $loggingEnabled) {
                $this->renderLogTab();
            } else {
                $this->renderSettingsTab();
            }
            ?>
        </div>
        <?php
    }

    private function renderSettingsTab(): void
    {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('wpef_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__('Blocked email addresses', 'lxix-email-firewall'); ?></th>
                    <td>
                        <textarea name="wpef_blocked_emails" rows="6" class="large-text code"><?php echo esc_textarea((string) get_option('wpef_blocked_emails', '')); ?></textarea>
                        <p class="description"><?php echo esc_html__('Separate values with commas or new lines.', 'lxix-email-firewall'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo esc_html__('Blocked domains', 'lxix-email-firewall'); ?></th>
                    <td>
                        <textarea name="wpef_blocked_domains" rows="6" class="large-text code"><?php echo esc_textarea((string) get_option('wpef_blocked_domains', '')); ?></textarea>
                        <p class="description"><?php echo esc_html__('Example: spamdomain.com (its subdomains are blocked too)', 'lxix-email-firewall'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo esc_html__('Administrators', 'lxix-email-firewall'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="wpef_block_admin_emails"
                                   value="1"
                                    <?php checked($this->shouldBlockAdminEmails(), true); ?>>
                            <?php echo esc_html__('Also block emails sent to administrators', 'lxix-email-firewall'); ?>
                        </label>
                        <p class="description"><?php echo esc_html__('When unchecked, the administration email address and the administrators get their emails even if their address or domain is blocked.', 'lxix-email-firewall'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo esc_html__('Enable logging', 'lxix-email-firewall'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="wpef_enable_logging"
                                   value="1"
                                    <?php checked($this->isLoggingEnabled(), true); ?>>
                            <?php echo esc_html__('Store blocked email events in the database', 'lxix-email-firewall'); ?>
                        </label>
                        <p class="description"><?php echo esc_html__('Turning logging off deletes all existing log entries.', 'lxix-email-firewall'); ?></p>
                    </td>
                </tr>

                <?php if ($this->isLoggingEnabled()) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Log retention (days)', 'lxix-email-firewall'); ?></th>
                        <td>
                            <input type="number"
                                   min="1"
                                   name="wpef_log_retention"
                                   value="<?php echo esc_attr((string) get_option('wpef_log_retention', 30)); ?>">
                        </td>
                    </tr>
                <?php endif; ?>

                <tr>
                    <th scope="row"><?php echo esc_html__('Elementor integration', 'lxix-email-firewall'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpef_enable_elementor" value="1" <?php checked($this->isElementorEnabled(), true); ?>>
                            <?php echo esc_html__('Enable Elementor form blocking', 'lxix-email-firewall'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo esc_html__('WPForms integration', 'lxix-email-firewall'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpef_enable_wpforms" value="1" <?php checked($this->isWpFormsEnabled(), true); ?>>
                            <?php echo esc_html__('Enable WPForms form blocking', 'lxix-email-firewall'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo esc_html__('Contact Form 7 integration', 'lxix-email-firewall'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpef_enable_contact_form_7" value="1" <?php checked($this->isContactForm7Enabled(), true); ?>>
                            <?php echo esc_html__('Enable Contact Form 7 form blocking', 'lxix-email-firewall'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo esc_html__('Fluent Forms integration', 'lxix-email-firewall'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpef_enable_fluent_forms" value="1" <?php checked($this->isFluentFormsEnabled(), true); ?>>
                            <?php echo esc_html__('Enable Fluent Forms form blocking', 'lxix-email-firewall'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo esc_html__('Forminator integration', 'lxix-email-firewall'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpef_enable_forminator" value="1" <?php checked($this->isForminatorEnabled(), true); ?>>
                            <?php echo esc_html__('Enable Forminator form blocking', 'lxix-email-firewall'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo esc_html__('MetForm integration', 'lxix-email-firewall'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpef_enable_metform" value="1" <?php checked($this->isMetFormEnabled(), true); ?>>
                            <?php echo esc_html__('Enable MetForm form blocking', 'lxix-email-firewall'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo esc_html__('Ninja Forms integration', 'lxix-email-firewall'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpef_enable_ninja_forms" value="1" <?php checked($this->isNinjaFormsEnabled(), true); ?>>
                            <?php echo esc_html__('Enable Ninja Forms form blocking', 'lxix-email-firewall'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo esc_html__('SureForms integration', 'lxix-email-firewall'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpef_enable_sureforms" value="1" <?php checked($this->isSureFormsEnabled(), true); ?>>
                            <?php echo esc_html__('Enable SureForms form blocking', 'lxix-email-firewall'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo esc_html__('Formidable Forms integration', 'lxix-email-firewall'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpef_enable_formidable" value="1" <?php checked($this->isFormidableEnabled(), true); ?>>
                            <?php echo esc_html__('Enable Formidable Forms form blocking', 'lxix-email-firewall'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="wpef-form-action"><?php echo esc_html__('Blocked form submissions', 'lxix-email-firewall'); ?></label></th>
                    <td>
                        <select id="wpef-form-action" name="wpef_form_action">
                            <option value="silent" <?php selected($this->getFormAction(), 'silent'); ?>>
                                <?php echo esc_html__('Accept the submission, but send no emails', 'lxix-email-firewall'); ?>
                            </option>
                            <option value="reject" <?php selected($this->getFormAction(), 'reject'); ?>>
                                <?php echo esc_html__('Reject the submission with an error message', 'lxix-email-firewall'); ?>
                            </option>
                        </select>
                        <p class="description"><?php echo esc_html__('Applies to the forms of the enabled integrations above when a field contains a blocked email address.', 'lxix-email-firewall'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save settings', 'lxix-email-firewall')); ?>
        </form>
        <?php
    }

    private function renderLogTab(): void
    {
        global $wpdb;

        if (isset($_POST['wpef_clear_log']) && check_admin_referer('wpef_clear_log')) {
            $this->clearLog();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Log cleared.', 'lxix-email-firewall') . '</p></div>';
        }

        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $offset = ($paged - 1) * self::PER_PAGE;

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';

            $total = (int) $wpdb->get_var(
                    $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->prefix}wpef_log WHERE email LIKE %s OR context LIKE %s OR ip LIKE %s",
                            $like,
                            $like,
                            $like
                    )
            );
            $logs = $wpdb->get_results(
                    $wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}wpef_log WHERE email LIKE %s OR context LIKE %s OR ip LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d",
                            $like,
                            $like,
                            $like,
                            self::PER_PAGE,
                            $offset
                    )
            );
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpef_log");
            $logs = $wpdb->get_results(
                    $wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}wpef_log ORDER BY id DESC LIMIT %d OFFSET %d",
                            self::PER_PAGE,
                            $offset
                    )
            );
        }

        $totalPages = (int) ceil($total / self::PER_PAGE);

        $baseUrl = admin_url('options-general.php?page=lxix-email-firewall&tab=log');
        if ($search !== '') {
            $baseUrl = add_query_arg('s', rawurlencode($search), $baseUrl);
        }
        ?>
        <form method="get">
            <input type="hidden" name="page" value="lxix-email-firewall">
            <input type="hidden" name="tab" value="log">
            <p class="search-box">
                <label class="screen-reader-text" for="wpef-search"><?php echo esc_html__('Search logs', 'lxix-email-firewall'); ?></label>
                <input id="wpef-search" type="search" name="s" value="<?php echo esc_attr($search); ?>">
                <input type="submit" class="button" value="<?php echo esc_attr__('Search', 'lxix-email-firewall'); ?>">
            </p>
        </form>

        <form method="post">
            <?php wp_nonce_field('wpef_clear_log'); ?>
            <p>
                <button
                        type="submit"
                        name="wpef_clear_log"
                        class="button"
                        onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete all log entries?', 'lxix-email-firewall')); ?>')"
                >
                    <?php echo esc_html__('Clear log', 'lxix-email-firewall'); ?>
                </button>
            </p>
        </form>

        <table class="widefat striped">
            <thead>
            <tr>
                <th><?php echo esc_html__('ID', 'lxix-email-firewall'); ?></th>
                <th><?php echo esc_html__('Date', 'lxix-email-firewall'); ?></th>
                <th><?php echo esc_html__('Context', 'lxix-email-firewall'); ?></th>
                <th><?php echo esc_html__('Email', 'lxix-email-firewall'); ?></th>
                <th><?php echo esc_html__('IP address', 'lxix-email-firewall'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)) : ?>
                <tr>
                    <td colspan="5"><?php echo esc_html__('No log entries found.', 'lxix-email-firewall'); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ($logs as $log) : ?>
                    <tr>
                        <td><?php echo (int) $log->id; ?></td>
                        <td><?php echo esc_html(get_date_from_gmt((string) $log->created_at)); ?></td>
                        <td><?php echo esc_html((string) $log->context); ?></td>
                        <td><?php echo esc_html((string) $log->email); ?></td>
                        <td><?php echo esc_html((string) $log->ip); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1) : ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                echo wp_kses_post((string) paginate_links([
                        'base'    => add_query_arg('paged', '%#%', $baseUrl),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $totalPages,
                ]));
                ?>
            </div>
        </div>
    <?php endif; ?>
        <?php
    }

    /* ==========================
     * Email Block
     * ========================== */

    /**
     * Mirrors how wp_mail() reads an address: "Name <user@example.com>" or a bare address.
     */
    private function normalizeAddress(string $address): string
    {
        $address = trim($address);

        if (preg_match('/(.*)<(.+)>/', $address, $matches)) {
            $address = $matches[2];
        }

        return strtolower(trim($address));
    }

    /**
     * Removes addresses from a recipient list in either form wp_mail() accepts.
     *
     * @param mixed                   $list         Comma-separated string or array of addresses.
     * @param string[]                $removed      Collects the removed addresses.
     * @param callable(string): bool  $shouldRemove
     * @return array{0: mixed, 1: int} The list in its original form, and how many valid addresses were kept.
     */
    private function filterRecipientList($list, array &$removed, callable $shouldRemove): array
    {
        $items = is_array($list) ? $list : explode(',', is_scalar($list) ? (string) $list : '');
        $kept = [];
        $keptValid = 0;

        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                continue;
            }

            $email = $this->normalizeAddress($item);

            if ($shouldRemove($email)) {
                $removed[] = $email;
                continue;
            }

            $kept[] = $item;

            // wp_mail() splits on every comma, so '"Doe, John" <a@b.c>' leaves a '"Doe' fragment
            // that is not a recipient.
            if (is_email($email)) {
                $keptValid++;
            }
        }

        return [is_array($list) ? $kept : implode(',', $kept), $keptValid];
    }

    /**
     * Rebuilds a header line, keeping the "\r" of a CRLF-separated header string.
     */
    private function headerLine(string $name, string $value, string $originalLine): string
    {
        return trim($name) . ': ' . trim($value) . (substr($originalLine, -1) === "\r" ? "\r" : '');
    }

    /**
     * Removes addresses from the Cc and Bcc headers, leaving every other header untouched.
     *
     * @param mixed                   $headers      String or array of header lines, as wp_mail() accepts them.
     * @param string[]                $removed      Collects the removed addresses.
     * @param callable(string): bool  $shouldRemove
     * @return array{0: mixed, 1: int} The headers in their original form, and how many Cc/Bcc addresses were kept.
     */
    private function filterRecipientHeaders($headers, array &$removed, callable $shouldRemove): array
    {
        if (empty($headers) || (!is_string($headers) && !is_array($headers))) {
            return [$headers, 0];
        }

        $lines = is_array($headers) ? $headers : explode("\n", $headers);
        $keptTotal = 0;

        foreach ($lines as $index => $line) {
            if (!is_string($line) || strpos($line, ':') === false) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);

            if (!in_array(strtolower(trim($name)), ['cc', 'bcc'], true)) {
                continue;
            }

            $removedBefore = count($removed);
            [$value, $kept] = $this->filterRecipientList(rtrim($value, "\r"), $removed, $shouldRemove);
            $keptTotal += $kept;

            if (count($removed) === $removedBefore) {
                continue;
            }

            if ($kept === 0) {
                unset($lines[$index]);
            } else {
                $lines[$index] = $this->headerLine($name, $value, $line);
            }
        }

        return [is_array($headers) ? array_values($lines) : implode("\n", $lines), $keptTotal];
    }

    /**
     * Takes the first valid address out of the Cc headers.
     *
     * @param mixed $headers String or array of header lines.
     * @return array{0: string|null, 1: mixed} The address (null if there is none) and the remaining headers.
     */
    private function takeFirstCc($headers): array
    {
        if (!is_string($headers) && !is_array($headers)) {
            return [null, $headers];
        }

        $lines = is_array($headers) ? $headers : explode("\n", $headers);

        foreach ($lines as $index => $line) {
            if (!is_string($line) || strpos($line, ':') === false) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);

            if (strtolower(trim($name)) !== 'cc') {
                continue;
            }

            $items = array_map('trim', explode(',', rtrim($value, "\r")));

            foreach ($items as $position => $item) {
                if (!is_email($this->normalizeAddress($item))) {
                    continue;
                }

                unset($items[$position]);
                $rest = implode(', ', array_filter($items, static fn (string $v): bool => $v !== ''));

                if ($rest === '') {
                    unset($lines[$index]);
                } else {
                    $lines[$index] = $this->headerLine($name, $rest, $line);
                }

                return [$item, is_array($headers) ? array_values($lines) : implode("\n", $lines)];
            }
        }

        return [null, $headers];
    }

    /**
     * Every valid recipient address (To, Cc, Bcc) of a wp_mail() call.
     *
     * @return string[]
     */
    private function collectRecipients(array $atts): array
    {
        $recipients = [];
        $all = static fn (string $email): bool => true;

        $this->filterRecipientList($atts['to'] ?? '', $recipients, $all);
        $this->filterRecipientHeaders($atts['headers'] ?? '', $recipients, $all);

        return array_values(array_filter($recipients, static fn (string $email): bool => (bool) is_email($email)));
    }

    /**
     * Removes blocked recipients from To, Cc and Bcc. Mailer plugins that replace wp_mail() without
     * applying pre_wp_mail (e.g. Mailgun, Brevo) or send from inside it (e.g. Site Mailer, Gmail SMTP)
     * get the cleaned list too; they may report an email without any recipient left as failed.
     *
     * @param mixed $atts
     * @return mixed
     */
    public function filterWpMail($atts)
    {
        if (!is_array($atts)) {
            return $atts;
        }

        $shouldRemove = $this->suppressRequestMail
                ? static fn (string $email): bool => true
                : fn (string $email): bool => $this->isSuppressed($email);

        $removed = [];
        [$to, $keptTo] = $this->filterRecipientList($atts['to'] ?? '', $removed, $shouldRemove);
        $removedFromTo = count($removed);
        [$headers, $keptHeaders] = $this->filterRecipientHeaders($atts['headers'] ?? '', $removed, $shouldRemove);

        if ($removed === [] && !$this->suppressRequestMail) {
            return $atts;
        }

        // Many mailers (Site Mailer, most HTTP API mailers) refuse an email without a To address.
        // A Bcc address is never promoted, that would reveal it.
        if ($keptTo === 0 && $removedFromTo > 0 && $keptHeaders > 0) {
            [$promoted, $headers] = $this->takeFirstCc($headers);

            if ($promoted !== null) {
                $to = is_array($to) ? [$promoted] : $promoted;
                $keptTo = 1;
            }
        }

        $atts['to'] = $to;
        $atts['headers'] = $headers;

        if ($keptTo + $keptHeaders === 0) {
            $atts[self::SUPPRESSED_KEY] = true;
        }

        // A suppressed form submission is logged once, by the form integration.
        if (!$this->suppressRequestMail) {
            foreach (array_unique($removed) as $email) {
                $this->logBlock('wp_mail', $email);
            }
        }

        return $atts;
    }

    /**
     * Stops the email when no recipient is left. Returns true, so senders treat a suppressed
     * email as delivered (no "server error" on forms, no "failed to send" notes).
     *
     * @param mixed $return
     * @param mixed $atts
     * @return mixed
     */
    public function blockPreWpMail($return, $atts = [])
    {
        if ($return !== null || !is_array($atts)) {
            return $return;
        }

        if ($this->suppressRequestMail || !empty($atts[self::SUPPRESSED_KEY])) {
            return true;
        }

        // Some mailers (e.g. Post SMTP) apply pre_wp_mail before the wp_mail filter. Drop the
        // email here if every recipient is blocked; partial lists are cleaned by filterWpMail().
        $recipients = $this->collectRecipients($atts);
        if ($recipients === []) {
            return $return;
        }

        foreach ($recipients as $email) {
            if (!$this->isSuppressed($email)) {
                return $return;
            }
        }

        foreach (array_unique($recipients) as $email) {
            $this->logBlock('wp_mail', $email);
        }

        return true;
    }

    /* ==========================
     * Form submissions
     * ========================== */

    /**
     * Looks for a blocked address among the submitted form fields.
     *
     * @return array{0: string, 1: string}|null Field ID and the blocked address.
     */
    private function findBlockedSubmitter(array $fields): ?array
    {
        foreach ($fields as $key => $field) {
            if (!is_array($field) || !isset($field['value']) || !is_scalar($field['value'])) {
                continue;
            }

            $email = $this->normalizeAddress((string) $field['value']);

            if (is_email($email) && $this->isSuppressed($email)) {
                return [(string) ($field['id'] ?? $key), $email];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $values Submitted values by field name.
     * @return array{0: string, 1: string}|null Field name and the blocked address.
     */
    private function findBlockedValue(array $values): ?array
    {
        return $this->findBlockedSubmitter(array_map(static fn ($value): array => ['value' => $value], $values));
    }

    private function getRejectMessage(): string
    {
        return __('This email address cannot be used.', 'lxix-email-firewall');
    }

    public function endFormSubmission(): void
    {
        $this->suppressRequestMail = false;
    }

    /**
     * For form plugins that submit to a REST route of their own, and have no hook at the end of every submission.
     */
    private function endFormSubmissionWithRestRoute(string $routePrefix): void
    {
        if ($this->restSubmissionRoutes === []) {
            add_filter('rest_request_after_callbacks', [$this, 'endRestFormSubmission'], PHP_INT_MAX, 3);
        }

        $this->restSubmissionRoutes[] = $routePrefix;
    }

    /**
     * @param mixed $response
     * @param mixed $handler
     * @param mixed $request
     * @return mixed
     */
    public function endRestFormSubmission($response, $handler = null, $request = null)
    {
        if (!$request instanceof WP_REST_Request) {
            return $response;
        }

        // A REST request made inside another form plugin's submission must not end its suppression.
        foreach ($this->restSubmissionRoutes as $routePrefix) {
            if (strpos($request->get_route(), $routePrefix) === 0) {
                $this->endFormSubmission();
                break;
            }
        }

        return $response;
    }

    /* ==========================
     * Elementor
     * ========================== */

    public function maybeInitElementor(): void
    {
        if (!$this->isElementorEnabled()) {
            return;
        }

        // Validation runs before every form action (email, Collect Submissions, webhooks...);
        // new_record runs after all of them.
        add_action('elementor_pro/forms/validation', [$this, 'handleElementorValidation'], 10, 2);
        add_action('elementor_pro/forms/new_record', [$this, 'endFormSubmission'], PHP_INT_MAX);
    }

    /**
     * @param mixed $record
     * @param mixed $ajaxHandler
     */
    public function handleElementorValidation($record, $ajaxHandler): void
    {
        if (!is_object($record) || !method_exists($record, 'get')) {
            return;
        }

        $fields = $record->get('fields');
        $blocked = is_array($fields) ? $this->findBlockedSubmitter($fields) : null;
        if ($blocked === null) {
            return;
        }

        [$fieldId, $email] = $blocked;

        $this->logBlock('elementor', $email);

        if ($this->getFormAction() === 'reject' && is_object($ajaxHandler) && method_exists($ajaxHandler, 'add_error')) {
            $ajaxHandler->add_error($fieldId, $this->getRejectMessage());
            return;
        }

        $this->suppressRequestMail = true;
    }

    /* ==========================
     * WPForms
     * ========================== */

    public function maybeInitWpForms(): void
    {
        if (!$this->isWpFormsEnabled()) {
            return;
        }

        // wpforms_process runs after field validation, before the entry is saved and emailed.
        add_action('wpforms_process', [$this, 'handleWpFormsProcess'], 10, 3);
        add_filter('wpforms_entry_email', [$this, 'filterWpFormsEntryEmail'], PHP_INT_MAX);
        add_action('wpforms_process_complete', [$this, 'endFormSubmission'], PHP_INT_MAX);
        // When processing stops early (another error, spam), the flag must not linger.
        // wpforms_process_after covers that since WPForms 1.9.0. Older versions fire no hook then,
        // but they process non-AJAX submissions on 'wp' (AJAX requests end right after processing).
        add_action('wpforms_process_after', [$this, 'endFormSubmission'], PHP_INT_MAX);
        add_action('wp', [$this, 'endFormSubmission'], PHP_INT_MAX);
    }

    /**
     * @param mixed $fields
     * @param mixed $entry
     * @param mixed $formData
     */
    public function handleWpFormsProcess($fields, $entry, $formData): void
    {
        $blocked = is_array($fields) ? $this->findBlockedSubmitter($fields) : null;
        if ($blocked === null) {
            return;
        }

        [$fieldId, $email] = $blocked;

        $this->logBlock('wpforms', $email);

        $process = $this->getWpFormsProcess();
        $formId = is_array($formData) ? (int) ($formData['id'] ?? 0) : 0;

        if ($this->getFormAction() === 'reject' && $process !== null && $formId > 0) {
            $process->errors[$formId][$fieldId] = $this->getRejectMessage();
            return;
        }

        $this->suppressRequestMail = true;
    }

    /**
     * Skips WPForms notifications, including ones queued by "Optimize email sending".
     *
     * @param mixed $send
     * @return mixed
     */
    public function filterWpFormsEntryEmail($send)
    {
        return $this->suppressRequestMail ? false : $send;
    }

    private function getWpFormsProcess(): ?object
    {
        if (!function_exists('wpforms')) {
            return null;
        }

        $wpforms = wpforms();
        $process = null;

        if (method_exists($wpforms, 'obj')) {
            $process = $wpforms->obj('process');
        } elseif (method_exists($wpforms, 'get')) {
            $process = $wpforms->get('process');
        }

        return is_object($process) ? $process : null;
    }

    /* ==========================
     * Contact Form 7
     * ========================== */

    public function maybeInitContactForm7(): void
    {
        if (!$this->isContactForm7Enabled()) {
            return;
        }

        // Validation runs before the spam check and the mail; wpcf7_submit runs after every
        // submission, whether it was sent, rejected or marked as spam.
        add_filter('wpcf7_validate', [$this, 'filterContactForm7Validation'], 10, 2);
        add_action('wpcf7_submit', [$this, 'endFormSubmission'], PHP_INT_MAX);
    }

    /**
     * @param mixed $result
     * @param mixed $tags
     * @return mixed
     */
    public function filterContactForm7Validation($result, $tags)
    {
        $submission = class_exists('WPCF7_Submission') ? WPCF7_Submission::get_instance() : null;

        if (!$result instanceof WPCF7_Validation || !is_array($tags) || $submission === null) {
            return $result;
        }

        $tagsByName = [];
        $values = [];

        foreach ($tags as $tag) {
            if ($tag instanceof WPCF7_FormTag && !empty($tag->name)) {
                $tagsByName[$tag->name] = $tag;
                $values[$tag->name] = $submission->get_posted_data($tag->name);
            }
        }

        $blocked = $this->findBlockedValue($values);
        if ($blocked === null) {
            return $result;
        }

        [$fieldName, $email] = $blocked;

        $this->logBlock('contact_form_7', $email);

        if ($this->getFormAction() === 'reject') {
            $result->invalidate($tagsByName[$fieldName], $this->getRejectMessage());
            return $result;
        }

        $this->suppressRequestMail = true;

        return $result;
    }

    /* ==========================
     * Fluent Forms
     * ========================== */

    public function maybeInitFluentForms(): void
    {
        if (!$this->isFluentFormsEnabled()) {
            return;
        }

        // The validation errors are filtered before the entry is saved and the notifications are sent;
        // before_submission_confirmation runs after all of them. Each submission is a request of its
        // own (AJAX or REST), so one that stops early (spam, errors) ends the request anyway.
        add_filter('fluentform/validation_errors', [$this, 'filterFluentFormsValidationErrors'], 10, 2);
        add_action('fluentform/before_submission_confirmation', [$this, 'endFormSubmission'], PHP_INT_MAX);
        // Fired right after the entry is saved, on every way of submitting, before any notification is sent or
        // queued. A payment form submitted to a hosted checkout ends its request before the later form actions.
        add_action('fluentform/notify_on_form_submit', [$this, 'markLoggedFluentFormsEntry']);
        // Fluent Forms sends the notifications from this action (at priority 10), with the entry's stored
        // values, also outside the submission request: after a payment, or from its async queue. (The
        // "on submit" notifications of payment forms skip it, but they are sent during the submission.)
        add_action('fluentform/integration_notify_notifications', [$this, 'beginFluentFormsNotification'], 9, 3);
        add_action('fluentform/integration_notify_notifications', [$this, 'endFluentFormsNotification'], PHP_INT_MAX);
        // When a notification throws, the action above stops before the suppression ends. Action Scheduler
        // catches the exception and runs the next action of the batch, whose emails must not be suppressed.
        add_action('action_scheduler_failed_execution', [$this, 'endFluentFormsNotification']);
    }

    /**
     * @param mixed $errors   Error messages by field name.
     * @param mixed $formData Submitted values by field name.
     * @return mixed
     */
    public function filterFluentFormsValidationErrors($errors, $formData)
    {
        $blocked = is_array($errors) && is_array($formData) ? $this->findBlockedValue($formData) : null;
        if ($blocked === null) {
            return $errors;
        }

        [$fieldName, $email] = $blocked;

        $this->logBlock('fluent_forms', $email);

        if ($this->getFormAction() === 'reject') {
            $errors[$fieldName] = ['wpef_blocked' => $this->getRejectMessage()];
            return $errors;
        }

        $this->suppressRequestMail = true;

        return $errors;
    }

    /**
     * @param mixed $feed
     * @param mixed $formData The entry's stored values by field name.
     * @param mixed $entry
     */
    public function beginFluentFormsNotification($feed, $formData, $entry = null): void
    {
        // During the submission, the validation has already decided and logged it.
        if ($this->suppressRequestMail || !is_array($formData)) {
            return;
        }

        $blocked = $this->findBlockedValue($formData);
        if ($blocked === null) {
            return;
        }

        // An entry is logged once, like a submission: by its validation, or by its first suppressed notification
        // when the address was blocked after the submission.
        $entryId = is_object($entry) ? (int) ($entry->id ?? 0) : 0;
        if (!$this->isFluentFormsEntryLogged($entryId)) {
            $this->logBlock('fluent_forms', $blocked[1]);
            $this->setFluentFormsEntryLogged($entryId);
        }

        $this->suppressRequestMail = true;
        $this->suppressingFluentFormsNotification = true;
    }

    /**
     * @param mixed $entryId
     */
    public function markLoggedFluentFormsEntry($entryId): void
    {
        // Only the validation of this submission sets the flag before the entry is saved, and it has logged the entry.
        if ($this->suppressRequestMail) {
            $this->setFluentFormsEntryLogged((int) $entryId);
        }
    }

    private function isFluentFormsEntryLogged(int $entryId): bool
    {
        if ($entryId <= 0 || !class_exists('\FluentForm\App\Helpers\Helper')) {
            return false;
        }

        return (bool) \FluentForm\App\Helpers\Helper::getSubmissionMeta($entryId, self::FLUENT_FORMS_LOGGED_META);
    }

    private function setFluentFormsEntryLogged(int $entryId): void
    {
        if ($entryId > 0 && class_exists('\FluentForm\App\Helpers\Helper')) {
            \FluentForm\App\Helpers\Helper::setSubmissionMeta($entryId, self::FLUENT_FORMS_LOGGED_META, 1);
        }
    }

    /**
     * Only ends a suppression begun by beginFluentFormsNotification(): during the submission, the flag stays
     * set until before_submission_confirmation, and in an Action Scheduler batch it must not reach the next action.
     */
    public function endFluentFormsNotification(): void
    {
        if (!$this->suppressingFluentFormsNotification) {
            return;
        }

        $this->suppressingFluentFormsNotification = false;
        $this->suppressRequestMail = false;
    }

    /* ==========================
     * Forminator
     * ========================== */

    public function maybeInitForminator(): void
    {
        if (!$this->isForminatorEnabled()) {
            return;
        }

        // Forminator applies this filter after its field validation, before the captcha and spam checks, the entry
        // and the emails.
        add_filter('forminator_custom_form_submit_errors', [$this, 'filterForminatorSubmitErrors'], 10, 3);
        // The first one ends an AJAX submission, the second one a submission without AJAX.
        add_action('forminator_form_after_save_entry', [$this, 'endFormSubmission']);
        add_action('forminator_form_after_handle_submit', [$this, 'endFormSubmission']);
        // Every notification of an entry passes here, also outside the submission: after a Stripe Checkout payment
        // (its return, its webhook or a scheduled check), or when an administrator resends it.
        add_filter('forminator_form_get_admin_email_recipients', [$this, 'filterForminatorNotificationRecipients'], PHP_INT_MAX, 5);
    }

    /**
     * @param mixed $errors    A list of [field ID => message] arrays.
     * @param mixed $formId
     * @param mixed $fieldData The submitted fields: ['name' => field ID, 'value' => value, ...].
     * @return mixed
     */
    public function filterForminatorSubmitErrors($errors, $formId = null, $fieldData = null)
    {
        // Forminator applies the filter again after each pass over the uploaded files. A saved draft sends no
        // email, and its final submission is checked.
        if ($this->suppressRequestMail || $this->isForminatorDraft()) {
            return $errors;
        }

        $blocked = is_array($errors) && is_array($fieldData) ? $this->findBlockedForminatorField($fieldData) : null;
        if ($blocked === null) {
            return $errors;
        }

        [$fieldId, $email] = $blocked;

        $this->logBlock('forminator', $email);

        if ($this->getFormAction() === 'reject') {
            // Forminator's front end inserts the message as HTML.
            $errors[] = [$fieldId => esc_html($this->getRejectMessage())];
            return $errors;
        }

        $this->suppressRequestMail = true;

        return $errors;
    }

    /**
     * @param array<mixed> $fieldData
     * @return array{0: string, 1: string}|null Field ID and the blocked address.
     */
    private function findBlockedForminatorField(array $fieldData): ?array
    {
        $fields = [];

        foreach ($fieldData as $field) {
            if (is_array($field) && isset($field['name'])) {
                // A field copied in a repeated group is shown under the ID of the original.
                $fields[] = [
                        'id'    => !empty($field['field_array']['original_id']) ? $field['field_array']['original_id'] : $field['name'],
                        'value' => $field['value'] ?? null,
                ];
            }
        }

        return $this->findBlockedSubmitter($fields);
    }

    /**
     * Forminator's own condition: a visitor can't skip the check by posting "save_draft" to a form without drafts.
     */
    private function isForminatorDraft(): bool
    {
        if (!class_exists('Forminator_Front_Action')) {
            return false;
        }

        $data = Forminator_Front_Action::$prepared_data;
        $settings = Forminator_Front_Action::$module_settings;

        return is_array($data) && is_array($settings)
                && filter_var($data['save_draft'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && filter_var($settings['use_save_and_continue'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param mixed $recipients
     * @param mixed $notification
     * @param mixed $preparedData
     * @param mixed $form
     * @param mixed $entry
     * @return mixed
     */
    public function filterForminatorNotificationRecipients($recipients, $notification = null, $preparedData = null, $form = null, $entry = null)
    {
        // During the submission, the validation has already decided and logged it.
        if ($this->suppressRequestMail || !is_object($entry) || !isset($entry->meta_data) || !is_array($entry->meta_data)) {
            return $recipients;
        }

        $values = array_map(static fn ($meta) => is_array($meta) ? ($meta['value'] ?? null) : null, $entry->meta_data);

        // Forminator skips a notification without recipients.
        return $this->findBlockedValue($values) === null ? $recipients : [];
    }

    /* ==========================
     * MetForm
     * ========================== */

    public function maybeInitMetForm(): void
    {
        if (!$this->isMetFormEnabled()) {
            return;
        }

        // MetForm applies this filter (since 3.7.0) after its own field validation, before the captcha check,
        // the integrations, the entry and the emails. A late priority leaves a rejection by another check alone.
        add_filter('mf_after_validation_check', [$this, 'filterMetFormValidation'], PHP_INT_MAX);
        // No MetForm hook runs at the end of every submission.
        $this->endFormSubmissionWithRestRoute('/metform/v1/entries/insert/');
    }

    /**
     * @param mixed $result ['is_valid' => bool, 'form_data' => submitted values by field name, 'file_data' => array]
     * @return mixed
     */
    public function filterMetFormValidation($result)
    {
        if (!is_array($result) || empty($result['is_valid']) || !isset($result['form_data']) || !is_array($result['form_data'])) {
            return $result;
        }

        $blocked = $this->findBlockedValue($result['form_data']);
        if ($blocked === null) {
            return $result;
        }

        $email = $blocked[1];

        if ($this->getFormAction() === 'reject') {
            $this->logBlock('metform', $email);
            // MetForm can only show a form-level error message, not one at the field.
            $result['is_valid'] = false;
            $result['message'] = $this->getRejectMessage();
            return $result;
        }

        // A payment-first form (MetForm Payment Assistant) is submitted again once the payment is completed:
        // that submission is logged.
        if (!$this->isMetFormPaymentFirstRequest($result['form_data'])) {
            $this->logBlock('metform', $email);
        }

        $this->suppressRequestMail = true;

        return $result;
    }

    /**
     * MetForm's own condition, except for the payment method it reads from the form: the visitor-supplied flag
     * alone must not hide a blocked submission from the log.
     *
     * @param array<mixed> $formData
     */
    private function isMetFormPaymentFirstRequest(array $formData): bool
    {
        return ($formData['mf_payment_first'] ?? '') === '1'
                && is_callable(['Metform\Utils\Util', 'is_plugin_active'])
                && \Metform\Utils\Util::is_plugin_active('metform-payment-assistant/metform-payment-assistant.php');
    }

    /* ==========================
     * Ninja Forms
     * ========================== */

    public function maybeInitNinjaForms(): void
    {
        if (!$this->isNinjaFormsEnabled()) {
            return;
        }

        // Ninja Forms applies this filter before it validates the fields one by one: an error added here is reported
        // when the validation reaches that field, and no action (saving, emails) runs.
        add_filter('ninja_forms_submit_data', [$this, 'filterNinjaFormsSubmitData']);
        // A submission halted by a payment add-on is resumed in a later request, which skips the filter above and
        // runs the remaining actions, the emails among them, with the stored values.
        add_filter('ninja_forms_submission_actions', [$this, 'filterNinjaFormsSubmissionActions'], 10, 3);
        // Every response to a submission, success or error, fires this right before the request ends.
        add_action('ninja_forms_before_response', [$this, 'endFormSubmission']);
    }

    /**
     * @param mixed $formData ['fields' => [field ID => ['value' => value, ...]], ...]
     * @return mixed
     */
    public function filterNinjaFormsSubmitData($formData)
    {
        $blocked = is_array($formData) ? $this->findBlockedValue($this->ninjaFormsValues($formData)) : null;
        if ($blocked === null) {
            return $formData;
        }

        $this->logBlock('ninja_forms', $blocked[1]);

        if ($this->getFormAction() === 'reject') {
            // Ninja Forms only reports the errors of the form's own fields, so every blocked value gets one: a field
            // added to the request can't take the error away from the real one. With this slug, the front end
            // clears the error once the visitor changes the form; it would stay until a reload otherwise. The
            // message is inserted as HTML.
            foreach ($this->ninjaFormsValues($formData) as $fieldId => $value) {
                if ($this->findBlockedValue([$fieldId => $value]) !== null) {
                    $formData['errors']['fields'][$fieldId] = ['slug' => 'unique_field', 'message' => esc_html($this->getRejectMessage())];
                }
            }

            return $formData;
        }

        $this->suppressRequestMail = true;

        return $formData;
    }

    /**
     * @param mixed $actions
     * @param mixed $formCache
     * @param mixed $formData
     * @return mixed
     */
    public function filterNinjaFormsSubmissionActions($actions, $formCache = null, $formData = null)
    {
        // A resumed submission was logged when it was received.
        if (is_array($formData) && $this->findBlockedValue($this->ninjaFormsValues($formData)) !== null) {
            $this->suppressRequestMail = true;
        }

        return $actions;
    }

    /**
     * @param array<mixed> $formData
     * @return array<mixed> The submitted values by field ID.
     */
    private function ninjaFormsValues(array $formData): array
    {
        $fields = isset($formData['fields']) && is_array($formData['fields']) ? $formData['fields'] : [];

        return array_map(static fn ($field) => is_array($field) ? ($field['value'] ?? null) : null, $fields);
    }

    /* ==========================
     * SureForms
     * ========================== */

    public function maybeInitSureForms(): void
    {
        if (!$this->isSureFormsEnabled()) {
            return;
        }

        // SureForms applies this filter (since 1.8.0) after its field validation, captcha and honeypot checks,
        // before the entry and the emails. Priority 1: its Stripe integration captures the payment at priority 5.
        add_filter('srfm_form_submit_data', [$this, 'filterSureFormsSubmitData'], 1);
        $this->endFormSubmissionWithRestRoute('/sureforms/v1/submit-form');
        // After a successful submission the browser makes another request, which runs the integrations of the
        // stored entry (Pro, OttoKit) on this action; they may send emails. Forms that store no entries run
        // it at the end of the submission request.
        add_action('srfm_after_submission_process', [$this, 'beginSureFormsAfterSubmission'], 1);
        add_action('srfm_after_submission_process', [$this, 'endFormSubmission'], PHP_INT_MAX);
    }

    /**
     * @param mixed $formData Every submitted parameter, by name.
     * @return mixed
     */
    public function filterSureFormsSubmitData($formData)
    {
        $blocked = is_array($formData) ? $this->findBlockedValue($this->sureFormsFields($formData)) : null;
        if ($blocked === null) {
            return $formData;
        }

        [$fieldName, $email] = $blocked;

        $this->logBlock('sureforms', $email);

        if ($this->getFormAction() === 'reject') {
            // The response SureForms sends for its own validation errors; its front end shows the message
            // (as HTML) above the submit button, not at the field. Returning an error from the filter wouldn't
            // stop the Stripe capture.
            $message = esc_html($this->getRejectMessage());
            wp_send_json_error(['message' => $message, 'field_errors' => [$fieldName => $message]]);
        }

        $this->suppressRequestMail = true;

        return $formData;
    }

    /**
     * @param mixed $formData The stored entry's values by field name.
     */
    public function beginSureFormsAfterSubmission($formData): void
    {
        // Logged at the submission already.
        if (is_array($formData) && $this->findBlockedValue($this->sureFormsFields($formData)) !== null) {
            $this->suppressRequestMail = true;
        }
    }

    /**
     * The submitted form fields: their names are "srfm-{type}-{block ID}-lbl-{label}-{slug}". The other
     * parameters (form ID, honeypot, captcha responses) are left out.
     *
     * @param array<mixed> $formData
     * @return array<mixed>
     */
    private function sureFormsFields(array $formData): array
    {
        return array_filter(
                $formData,
                static fn ($name): bool => is_string($name) && strpos($name, '-lbl-') !== false,
                ARRAY_FILTER_USE_KEY
        );
    }

    /* ==========================
     * Formidable Forms
     * ========================== */

    public function maybeInitFormidable(): void
    {
        if (!$this->isFormidableEnabled()) {
            return;
        }

        // Formidable applies this filter once it has validated every field and checked the submission for spam,
        // before the entry is created and its actions (emails) run in the same request.
        add_filter('frm_validate_entry', [$this, 'filterFormidableValidation'], 10, 3);
        // Fired once the entry has been created, before the confirmation (which may redirect and exit). A submission
        // that fails ends its request without sending any email.
        add_action('frm_process_entry', [$this, 'endFormSubmission'], PHP_INT_MAX);
        // Every action of an entry passes here, also outside the submission: on a payment event (a webhook or a
        // scheduled check), or when an administrator marks a spam entry as not spam.
        add_filter('frm_skip_form_action', [$this, 'filterFormidableSkipFormAction'], 10, 2);
    }

    /**
     * @param mixed $errors Error messages, by "field{ID}" for a field.
     * @param mixed $values The submitted data, with the field values by field ID in "item_meta".
     * @param mixed $args   "posted_fields": the fields of the submitted form (since Formidable 5.0.04).
     * @return mixed
     */
    public function filterFormidableValidation($errors, $values, $args = [])
    {
        // A submission with errors (spam included) is checked when it is sent again. An update edits an existing
        // entry (Formidable Pro); only "create" makes Formidable create one, whatever else is posted.
        if ($errors !== [] || !is_array($values) || ($values['frm_action'] ?? '') === 'update' || !isset($values['item_meta']) || !is_array($values['item_meta'])) {
            return $errors;
        }

        $blocked = $this->findBlockedValue($this->formidableFieldValues($values['item_meta'], $args));
        if ($blocked === null) {
            return $errors;
        }

        [$fieldId, $email] = $blocked;

        $this->logBlock('formidable', $email);

        if ($this->getFormAction() === 'reject') {
            // The front end inserts the message as HTML.
            return ['field' . $fieldId => esc_html($this->getRejectMessage())];
        }

        $this->suppressRequestMail = true;

        return $errors;
    }

    /**
     * Formidable posts its honeypot as a field too, so only the fields of the form are checked.
     *
     * @param array<mixed> $itemMeta Submitted values by field ID.
     * @param mixed        $args
     * @return array<mixed>
     */
    private function formidableFieldValues(array $itemMeta, $args): array
    {
        if (!is_array($args) || !isset($args['posted_fields']) || !is_array($args['posted_fields'])) {
            return $itemMeta;
        }

        $fieldIds = [];
        foreach ($args['posted_fields'] as $field) {
            if (is_object($field) && isset($field->id)) {
                $fieldIds[(int) $field->id] = true;
            }
        }

        return array_intersect_key($itemMeta, $fieldIds);
    }

    /**
     * @param mixed $skip
     * @param mixed $args ['action' => the action's post, 'entry' => entry or entry ID, 'form' => ..., 'event' => ...]
     * @return mixed
     */
    public function filterFormidableSkipFormAction($skip, $args = [])
    {
        // During the submission, the validation has already decided and logged it.
        if ($skip || $this->suppressRequestMail || !is_array($args) || !isset($args['action']->post_excerpt) || $args['action']->post_excerpt !== 'email') {
            return $skip;
        }

        $entry = $args['entry'] ?? null;
        $entryId = is_object($entry) ? (int) ($entry->id ?? 0) : (int) $entry;

        // The entry may come without its values (or as an ID only).
        if ((!is_object($entry) || !isset($entry->metas)) && $entryId > 0 && class_exists('FrmEntry')) {
            $entry = FrmEntry::getOne($entryId, true);
        }

        if (!is_object($entry) || !isset($entry->metas) || !is_array($entry->metas)) {
            return $skip;
        }

        return $this->findBlockedValue($entry->metas) !== null;
    }

    /* ==========================
     * WooCommerce
     * ========================== */

    /**
     * Hooks the recipient filter of every registered WooCommerce email. Emptying the recipient
     * makes WooCommerce skip the email, instead of adding a "sent"/"failed" order note.
     *
     * @param mixed $emails
     * @return mixed
     */
    public function registerWooCommerceFilters($emails)
    {
        if (!is_array($emails)) {
            return $emails;
        }

        // Partial refunds switch the refund email's ID when it is sent.
        $ids = ['customer_partially_refunded_order'];

        foreach ($emails as $email) {
            if (is_object($email) && !empty($email->id)) {
                $ids[] = (string) $email->id;
            }
        }

        foreach (array_unique($ids) as $id) {
            add_filter('woocommerce_email_recipient_' . $id, [$this, 'filterWooCommerceRecipients'], 10, 3);
        }

        return $emails;
    }

    /**
     * @param mixed $recipients Null until a customer email has been triggered.
     * @param mixed $object     Order, user, or whatever object the email is about; null when
     *                          WooCommerce only displays the recipients (e.g. Settings > Emails).
     * @param mixed $email      The WC_Email instance.
     * @return mixed
     */
    public function filterWooCommerceRecipients($recipients, $object = null, $email = null)
    {
        // Not an email being sent: leave the list as it is. Whatever does get sent is
        // still checked by filterWpMail().
        if (!is_object($object) || !is_scalar($recipients)) {
            return $recipients;
        }

        $valid = [];

        foreach (explode(',', (string) $recipients) as $recipient) {
            $recipient = trim($recipient);

            if ($recipient === '') {
                continue;
            }

            $address = $this->normalizeAddress($recipient);

            if ($this->isSuppressed($address)) {
                $this->logWooCommerceBlock($address, $object, $email);
                continue;
            }

            $valid[] = $recipient;
        }

        return implode(',', $valid);
    }

    /**
     * @param mixed $email
     */
    private function logWooCommerceBlock(string $address, object $object, $email): void
    {
        if (method_exists($object, 'get_id')) {
            $objectId = (string) $object->get_id();
        } elseif (isset($object->ID)) {
            $objectId = (string) $object->ID;
        } else {
            $objectId = (string) spl_object_id($object);
        }

        $sendKey = (is_object($email) && isset($email->id) ? (string) $email->id : '') . '|' . $objectId;

        // WooCommerce reads the recipient more than once per email (e.g. for its email log):
        // log each address once per email and object. Only the current email is remembered.
        if ($this->wooCommerceLogContext[0] !== $sendKey) {
            $this->wooCommerceLogContext = [$sendKey, []];
        }

        if (isset($this->wooCommerceLogContext[1][$address])) {
            return;
        }

        $this->wooCommerceLogContext[1][$address] = true;
        $this->logBlock('woocommerce', $address);
    }
}

WPEF_Plugin::instance();
