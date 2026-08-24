<?php
/**
 * Uninstall routine for Milardovich File Modification Monitor.
 *
 * Runs only when the plugin is deleted from the WordPress admin. Removes the
 * snapshot tables, options and transients created by the plugin so that no
 * orphan data is left behind.
 *
 * @package MilardovichFMM
 */

// Exit if this file is called directly or not during an uninstall.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop the snapshot tables.
$milardovich_fmm_tables = [
    $wpdb->prefix . 'milardovich_fmm_plugins',
    $wpdb->prefix . 'milardovich_fmm_themes',
];
foreach ($milardovich_fmm_tables as $milardovich_fmm_table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant, not user input.
    $wpdb->query("DROP TABLE IF EXISTS {$milardovich_fmm_table}");
}

// Delete plugin options.
$milardovich_fmm_options = [
    'milardovich_fmm_scan_frequency',
    'milardovich_fmm_email_notifications',
    'milardovich_fmm_notification_email',
    'milardovich_fmm_ignored_files',
    'milardovich_fmm_show_warnings',
    'milardovich_fmm_last_check',
    'milardovich_fmm_changes_map',
];
foreach ($milardovich_fmm_options as $milardovich_fmm_option) {
    delete_option($milardovich_fmm_option);
}

// Delete plugin transients.
delete_transient('milardovich_fmm_changes_cache');
delete_transient('milardovich_fmm_scan_lock');
delete_transient('milardovich_fmm_show_welcome_notice');

// Drop the background scan events.
wp_clear_scheduled_hook('milardovich_fmm_scan');
wp_clear_scheduled_hook('milardovich_fmm_scan_now');
