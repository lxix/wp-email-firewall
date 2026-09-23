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
