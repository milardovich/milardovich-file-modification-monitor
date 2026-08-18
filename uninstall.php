<?php
/**
 * Uninstall routine for WP Code Guardian.
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
$tables = [
    $wpdb->prefix . 'code_guardian_plugins',
    $wpdb->prefix . 'code_guardian_themes',
];
foreach ($tables as $table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant, not user input.
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Delete plugin options.
$options = [
    'wp_code_guardian_scan_frequency',
    'wp_code_guardian_email_notifications',
    'wp_code_guardian_notification_email',
    'wp_code_guardian_ignored_files',
    'wp_code_guardian_show_warnings',
    'wp_code_guardian_last_check',
    'wp_code_guardian_changes_map',
];
foreach ($options as $option) {
    delete_option($option);
}

// Delete plugin transients.
delete_transient('wp_code_guardian_changes_cache');
delete_transient('wp_code_guardian_scan_lock');
delete_transient('wp_code_guardian_show_welcome_notice');

// Drop the background scan events.
wp_clear_scheduled_hook('wp_code_guardian_scan');
wp_clear_scheduled_hook('wp_code_guardian_scan_now');
