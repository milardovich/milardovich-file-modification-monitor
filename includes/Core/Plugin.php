<?php
namespace WPCodeGuardian\Core;

use WPCodeGuardian\Storage\SnapshotStorage;
use WPCodeGuardian\Diff\DiffGenerator;
use WPCodeGuardian\Scanner\PluginScanner;
use WPCodeGuardian\Scanner\ThemeScanner;
use WPCodeGuardian\Admin\AdminManager;

class Plugin
{
    private static $instance = null;

    private $storage;
    private $diff_generator;
    private $plugin_scanner;
    private $theme_scanner;
    private $admin_manager;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->storage        = new SnapshotStorage();
        $this->diff_generator = new DiffGenerator();
        $this->plugin_scanner = new PluginScanner($this->storage, $this->diff_generator);
        $this->theme_scanner  = new ThemeScanner($this->storage, $this->diff_generator);
        $this->admin_manager  = new AdminManager($this->plugin_scanner, $this->theme_scanner);
    }

    public function __get($name)
    {
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        return null;
    }

    public function init()
    {
        $this->load_textdomain();
        $this->register_hooks();
        $this->ensure_tables_exist();
        $this->admin_manager->init();
    }

    public function load_textdomain()
    {
        load_plugin_textdomain(
            'wp-code-guardian',
            false,
            dirname(WP_CODE_GUARDIAN_PLUGIN_BASENAME) . '/languages'
        );
    }

    private function register_hooks()
    {
        register_activation_hook(WP_CODE_GUARDIAN_PLUGIN_DIR . 'wp-code-guardian.php', [$this, 'activate']);
        register_deactivation_hook(WP_CODE_GUARDIAN_PLUGIN_DIR . 'wp-code-guardian.php', [$this, 'deactivate']);

        add_action('upgrader_process_complete', [$this, 'handle_upgrade'], 10, 2);
        add_filter('plugin_action_links', [$this, 'add_plugin_action_links'], 10, 2);
        add_filter('theme_action_links', [$this, 'add_theme_action_links'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_update_warning_scripts']);
        add_filter('upgrader_pre_install', [$this, 'check_before_update'], 10, 2);
    }

    private function ensure_tables_exist()
    {
        global $wpdb;
        $plugins_table = $wpdb->prefix . 'code_guardian_plugins';
        $themes_table  = $wpdb->prefix . 'code_guardian_themes';

        $plugins_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $plugins_table));
        $themes_exists  = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $themes_table));

        if (!$plugins_exists || !$themes_exists) {
            $this->storage->create_tables();
            Logger::log('Created missing database tables');
        }
    }

    public function activate()
    {
        $this->storage->create_tables();
        set_transient('wp_code_guardian_show_welcome_notice', true, 30 * DAY_IN_SECONDS);
    }

    public function deactivate()
    {
        // Cleanup if needed
    }

    public function handle_upgrade($upgrader_object, $options)
    {
        if (!is_array($options) || empty($options['action']) || $options['action'] !== 'update') {
            return;
        }
        if (!empty($options['type']) && $options['type'] === 'plugin' && !empty($options['plugins']) && is_array($options['plugins'])) {
            foreach ($options['plugins'] as $plugin_file) {
                $this->plugin_scanner->create_snapshot($plugin_file);
            }
        } elseif (!empty($options['type']) && $options['type'] === 'theme' && !empty($options['themes']) && is_array($options['themes'])) {
            foreach ($options['themes'] as $theme_slug) {
                $this->theme_scanner->create_snapshot($theme_slug);
            }
        }
    }

    public function add_plugin_action_links($actions, $plugin_file)
    {
        // Red label removed per user request
        return $actions;
    }

    public function add_theme_action_links($actions, $theme)
    {
        // Red label removed per user request
        return $actions;
    }

    public function enqueue_update_warning_scripts($hook)
    {
        if (!in_array($hook, ['plugins.php', 'themes.php', 'update-core.php'], true)) {
            return;
        }
        wp_enqueue_script(
            'wp-code-guardian-update-warnings',
            WP_CODE_GUARDIAN_PLUGIN_URL . 'assets/js/update-warnings.js',
            ['jquery'],
            WP_CODE_GUARDIAN_VERSION,
            true
        );

        $changes = $this->admin_manager->get_cached_changes_for_warnings();

        wp_localize_script(
            'wp-code-guardian-update-warnings',
            'wpCodeGuardianWarnings',
            [
                'plugins_with_changes' => array_keys($changes['plugins'] ?? []),
                'themes_with_changes'  => array_keys($changes['themes'] ?? []),
                'warning_message'      => __('⚠️ Code Changes Detected! This item has custom modifications that will be lost during the update.', 'wp-code-guardian'),
                'ajax_url'             => admin_url('admin-ajax.php'),
                'nonce'                => wp_create_nonce('wp_code_guardian'),
            ]
        );
    }

    public function check_before_update($return, $package)
    {
        // Enforcement is handled in JavaScript; this hook is reserved.
        return $return;
    }
}
