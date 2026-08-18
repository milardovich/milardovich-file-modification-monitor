<?php
/**
 * Uninstall routine for Code Guardian.
 *
 * Runs only when the plugin is deleted from the WordPress admin. Removes the
 * snapshot tables, options and transients created by the plugin so that no
 * orphan data is left behind.
 *
 * @package WPCodeGuardian
 */

// Exit if this file is called directly or not during an uninstall.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop the snapshot tables.
$wpcg_tables = [
    $wpdb->prefix . 'code_guardian_plugins',
    $wpdb->prefix . 'code_guardian_themes',
];
foreach ($wpcg_tables as $wpcg_table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant, not user input.
    $wpdb->query("DROP TABLE IF EXISTS {$wpcg_table}");
}

// Delete plugin options.
$wpcg_options = [
    'code_guardian_scan_frequency',
    'code_guardian_email_notifications',
    'code_guardian_notification_email',
    'code_guardian_ignored_files',
    'code_guardian_show_warnings',
    'code_guardian_last_check',
    'code_guardian_changes_map',
];
foreach ($wpcg_options as $wpcg_option) {
    delete_option($wpcg_option);
}

// Delete plugin transients.
delete_transient('code_guardian_changes_cache');
delete_transient('code_guardian_scan_lock');
delete_transient('code_guardian_show_welcome_notice');

// Drop the background scan events.
wp_clear_scheduled_hook('code_guardian_scan');
wp_clear_scheduled_hook('code_guardian_scan_now');
