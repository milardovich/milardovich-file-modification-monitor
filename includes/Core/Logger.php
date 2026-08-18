<?php
namespace WPCodeGuardian\Core;

/**
 * Lightweight logger that only writes to the PHP error log when WordPress
 * debugging is enabled. Keeps production installs silent while preserving
 * useful diagnostics during development (WP_DEBUG + WP_DEBUG_LOG).
 */
class Logger
{
    /**
     * Log a message, prefixed with the plugin name, when debugging is on.
     *
     * @param string $message
     */
    public static function log($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Code Guardian: ' . $message);
        }
    }
}
