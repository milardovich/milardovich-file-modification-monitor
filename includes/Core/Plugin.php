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
        $this->register_hooks();
        $this->ensure_tables_exist();
        $this->ensure_scan_scheduled();
        $this->admin_manager->init();
    }

    // No load_plugin_textdomain() call: since WordPress 4.6 translations load
    // just in time from the Domain Path declared in the plugin header, and
    // calling it explicitly is flagged by Plugin Check.

    private function register_hooks()
    {
        register_activation_hook(CODE_GUARDIAN_PLUGIN_DIR . 'code-guardian.php', [$this, 'activate']);
        register_deactivation_hook(CODE_GUARDIAN_PLUGIN_DIR . 'code-guardian.php', [$this, 'deactivate']);

        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        add_action(AdminManager::CRON_HOOK, [$this, 'run_background_scan']);
        add_action(AdminManager::CRON_HOOK_ONCE, [$this, 'run_background_scan']);
        add_action('update_option_code_guardian_scan_frequency', [$this, 'reschedule_scan'], 10, 0);
        add_action('add_option_code_guardian_scan_frequency', [$this, 'reschedule_scan'], 10, 0);

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

    /**
     * WordPress ships hourly/twicedaily/daily only on older releases, so the
     * weekly option gets its own namespaced interval rather than assuming a
     * core 'weekly' schedule exists.
     */
    public function add_cron_schedules($schedules)
    {
        if (!isset($schedules['code_guardian_weekly'])) {
            $schedules['code_guardian_weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Once Weekly (Code Guardian)', 'code-guardian'),
            ];
        }
        return $schedules;
    }

    private function get_cron_schedule_name()
    {
        $frequency = get_option('code_guardian_scan_frequency', 'daily');
        $schedules = [
            'hourly'     => 'hourly',
            'twicedaily' => 'twicedaily',
            'daily'      => 'daily',
            'weekly'     => 'code_guardian_weekly',
        ];
        // 'disabled' (and anything unrecognised) maps to no schedule at all.
        return isset($schedules[$frequency]) ? $schedules[$frequency] : '';
    }

    /**
     * Make sure the recurring scan is registered and matches the configured
     * frequency. Called on every load so the schedule self-heals.
     */
    public function ensure_scan_scheduled()
    {
        $wanted  = $this->get_cron_schedule_name();
        $current = wp_get_schedule(AdminManager::CRON_HOOK);

        if ($wanted === '') {
            if ($current !== false) {
                wp_clear_scheduled_hook(AdminManager::CRON_HOOK);
            }
            return;
        }
        if ($current === $wanted) {
            return;
        }
        if ($current !== false) {
            wp_clear_scheduled_hook(AdminManager::CRON_HOOK);
        }
        wp_schedule_event(time() + MINUTE_IN_SECONDS, $wanted, AdminManager::CRON_HOOK);
    }

    public function reschedule_scan()
    {
        wp_clear_scheduled_hook(AdminManager::CRON_HOOK);
        $this->ensure_scan_scheduled();
        $this->admin_manager->queue_background_scan();
    }

    /**
     * Cron entry point for both the recurring and the one-off events.
     */
    public function run_background_scan()
    {
        $this->admin_manager->run_scan();
    }

    public function activate()
    {
        $this->storage->create_tables();
        $this->ensure_scan_scheduled();
        $this->admin_manager->queue_background_scan();
        set_transient('code_guardian_show_welcome_notice', true, 30 * DAY_IN_SECONDS);
    }

    public function deactivate()
    {
        wp_clear_scheduled_hook(AdminManager::CRON_HOOK);
        wp_clear_scheduled_hook(AdminManager::CRON_HOOK_ONCE);
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
        } else {
            return;
        }
        $this->admin_manager->invalidate_changes_map();
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
            'code-guardian-update-warnings',
            CODE_GUARDIAN_PLUGIN_URL . 'assets/js/update-warnings.js',
            ['jquery'],
            AdminManager::asset_version('assets/js/update-warnings.js'),
            true
        );

        $changes = $this->admin_manager->get_cached_changes_for_warnings();

        wp_localize_script(
            'code-guardian-update-warnings',
            'codeGuardianWarnings',
            [
                'plugins_with_changes' => array_keys($changes['plugins'] ?? []),
                'themes_with_changes'  => array_keys($changes['themes'] ?? []),
                'warning_message'      => __('⚠️ Code Changes Detected! This item has custom modifications that will be lost during the update.', 'code-guardian'),
                'ajax_url'             => admin_url('admin-ajax.php'),
                'nonce'                => wp_create_nonce('code_guardian'),
            ]
        );
    }

    public function check_before_update($return, $package)
    {
        // Enforcement is handled in JavaScript; this hook is reserved.
        return $return;
    }
}
