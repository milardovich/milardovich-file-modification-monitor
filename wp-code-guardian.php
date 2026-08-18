<?php
/**
 * Plugin Name: WP Code Guardian
 * Plugin URI: https://milardovich.com.ar/plugins/wp-code-guardian
 * Description: Detects local code modifications in installed plugins and themes by comparing files against pristine copies from WordPress.org, and warns before updates would overwrite them.
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Author: Sergio Milardovich
 * Author URI: https://milardovich.com.ar
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-code-guardian
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_CODE_GUARDIAN_VERSION', '1.0.0');
define('WP_CODE_GUARDIAN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_CODE_GUARDIAN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_CODE_GUARDIAN_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once WP_CODE_GUARDIAN_PLUGIN_DIR . 'vendor/autoload.php';

use WPCodeGuardian\Core\Plugin;

function wp_code_guardian() {
    return Plugin::instance();
}

add_action('plugins_loaded', function () {
    wp_code_guardian()->init();
});
