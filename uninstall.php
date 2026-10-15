<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$wpef_uninstall_site = static function (): void {
    global $wpdb;

    $options = [
        'wpef_blocked_emails',
        'wpef_blocked_domains',
        'wpef_enable_elementor',
        'wpef_enable_wpforms',
        'wpef_enable_contact_form_7',
        'wpef_enable_fluent_forms',
        'wpef_enable_forminator',
        'wpef_enable_metform',
        'wpef_enable_ninja_forms',
        'wpef_enable_sureforms',
        'wpef_enable_formidable',
        'wpef_form_action',
        'wpef_enable_logging',
        'wpef_log_retention',
        'wpef_block_admin_emails',
        'wpef_db_version',
        'wpef_migrated_utc',
        // Removed setting: a site that hasn't run the DB version 3 upgrade may still have it.
        'wpef_enable_woocommerce',
    ];

    foreach ($options as $option) {
        delete_option($option);
    }

    wp_clear_scheduled_hook('wpef_cleanup_logs');

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removes the plugin's own log table.
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wpef_log");

    // The marks on logged Fluent Forms entries (FLUENT_FORMS_LOGGED_META), unless Fluent Forms has dropped its tables.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms may be inactive, so its API can't be relied on here.
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'fluentform_submission_meta'))) !== null) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See above.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fluentform_submission_meta WHERE meta_key = %s", 'wpef_logged'));
    }
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $wpef_site_id) {
        switch_to_blog((int) $wpef_site_id);
        $wpef_uninstall_site();
        restore_current_blog();
    }
} else {
    $wpef_uninstall_site();
}
