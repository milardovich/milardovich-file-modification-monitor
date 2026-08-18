<?php
namespace WPCodeGuardian\Admin;

use WPCodeGuardian\Scanner\PluginScanner;
use WPCodeGuardian\Scanner\ThemeScanner;
use WPCodeGuardian\Core\Logger;

class AdminManager
{
    private $plugin_scanner;
    private $theme_scanner;

    /** Recurring background scan, scheduled from the frequency setting. */
    const CRON_HOOK = 'wp_code_guardian_scan';

    /** One-off background scan, queued when the cached map is missing/stale. */
    const CRON_HOOK_ONCE = 'wp_code_guardian_scan_now';

    private $changes_map_key  = 'wp_code_guardian_changes_map';
    private $legacy_cache_key = 'wp_code_guardian_changes_cache';
    private $last_check_key   = 'wp_code_guardian_last_check';
    private $scan_lock_key    = 'wp_code_guardian_scan_lock';

    /** Per-request memo of the stored map: null = unread, false = absent. */
    private $changes_map = null;

    public function __construct(PluginScanner $plugin_scanner, ThemeScanner $theme_scanner)
    {
        $this->plugin_scanner = $plugin_scanner;
        $this->theme_scanner  = $theme_scanner;
    }

    public function init()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        add_action('wp_ajax_wp_code_guardian_get_diff', [$this, 'ajax_get_diff']);
        add_action('wp_ajax_wp_code_guardian_refresh_snapshot', [$this, 'ajax_refresh_snapshot']);
        add_action('wp_ajax_wp_code_guardian_scan_all', [$this, 'ajax_scan_all']);
        add_action('wp_ajax_wp_code_guardian_clear_snapshots', [$this, 'ajax_clear_snapshots']);
        add_action('wp_ajax_wp_code_guardian_rescan_all', [$this, 'ajax_rescan_all']);
        add_action('wp_ajax_wp_code_guardian_run_scan', [$this, 'ajax_run_scan']);
        add_action('wp_ajax_wp_code_guardian_scan_queue', [$this, 'ajax_scan_queue']);
        add_action('wp_ajax_wp_code_guardian_scan_item', [$this, 'ajax_scan_item']);
        add_action('wp_ajax_wp_code_guardian_scan_finish', [$this, 'ajax_scan_finish']);
        add_action('wp_ajax_wp_code_guardian_dismiss_welcome', [$this, 'ajax_dismiss_welcome']);

        add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
        add_action('admin_notices', [$this, 'show_update_warnings']);
        add_action('admin_notices', [$this, 'show_welcome_notice']);

        add_action('load-plugins.php', [$this, 'add_plugin_labels']);
        add_action('load-themes.php', [$this, 'add_theme_labels']);
    }

    public function add_admin_menu()
    {
        add_menu_page(
            __('Code Guardian', 'wp-code-guardian'),
            __('Code Guardian', 'wp-code-guardian'),
            'manage_options',
            'wp-code-guardian',
            [$this, 'render_main_page'],
            'dashicons-shield',
            80
        );
        add_submenu_page('wp-code-guardian', __('Code Guardian', 'wp-code-guardian'), __('Dashboard', 'wp-code-guardian'), 'manage_options', 'wp-code-guardian', [$this, 'render_main_page']);
        add_submenu_page('wp-code-guardian', __('Plugin Changes', 'wp-code-guardian'), __('Plugin Changes', 'wp-code-guardian'), 'manage_options', 'wp-code-guardian-plugins', [$this, 'render_plugins_page']);
        add_submenu_page('wp-code-guardian', __('Theme Changes', 'wp-code-guardian'), __('Theme Changes', 'wp-code-guardian'), 'manage_options', 'wp-code-guardian-themes', [$this, 'render_themes_page']);
        add_submenu_page('wp-code-guardian', __('Settings', 'wp-code-guardian'), __('Settings', 'wp-code-guardian'), 'manage_options', 'wp-code-guardian-settings', [$this, 'render_settings_page']);
    }

    public function render_main_page()
    {
        include WP_CODE_GUARDIAN_PLUGIN_DIR . 'admin/views/main-page.php';
    }

    public function render_plugins_page()
    {
        include WP_CODE_GUARDIAN_PLUGIN_DIR . 'admin/views/plugins-page.php';
    }

    public function render_themes_page()
    {
        include WP_CODE_GUARDIAN_PLUGIN_DIR . 'admin/views/themes-page.php';
    }

    public function render_settings_page()
    {
        include WP_CODE_GUARDIAN_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    public function register_settings()
    {
        register_setting('wp_code_guardian_settings', 'wp_code_guardian_scan_frequency', ['sanitize_callback' => [$this, 'sanitize_scan_frequency']]);
        register_setting('wp_code_guardian_settings', 'wp_code_guardian_email_notifications', ['sanitize_callback' => 'absint']);
        register_setting('wp_code_guardian_settings', 'wp_code_guardian_notification_email', ['sanitize_callback' => 'sanitize_email']);
        register_setting('wp_code_guardian_settings', 'wp_code_guardian_ignored_files', ['sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('wp_code_guardian_settings', 'wp_code_guardian_show_warnings', ['sanitize_callback' => 'absint']);
    }

    public function sanitize_scan_frequency($value)
    {
        $allowed = ['disabled', 'hourly', 'twicedaily', 'daily', 'weekly'];
        return in_array($value, $allowed, true) ? $value : 'daily';
    }

    /**
     * Whether the cached map is older than the configured scan frequency.
     * Purely a read: unlike the previous implementation it has no side
     * effects and never triggers a scan by itself.
     */
    public function is_scan_due()
    {
        if (get_option('wp_code_guardian_scan_frequency', 'daily') === 'disabled') {
            return false;
        }
        return (time() - $this->get_last_check()) >= $this->get_scan_interval();
    }

    public function get_scan_interval()
    {
        $frequency = get_option('wp_code_guardian_scan_frequency', 'daily');
        $intervals = [
            'hourly'     => HOUR_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'daily'      => DAY_IN_SECONDS,
            'weekly'     => WEEK_IN_SECONDS,
        ];
        return isset($intervals[$frequency]) ? $intervals[$frequency] : DAY_IN_SECONDS;
    }

    public function get_last_check()
    {
        return (int) get_option($this->last_check_key, 0);
    }

    /**
     * The cached result of the last scan, shaped as
     * ['plugins' => [plugin_file => changed_file_count], 'themes' => [...]].
     *
     * This is the only thing the admin screens read. It never scans: when the
     * map is missing or stale a scan is queued to run in a separate request
     * and the last known result (or an empty map) is returned immediately, so
     * rendering an admin page never waits on file hashing.
     */
    public function get_changes_map()
    {
        if ($this->changes_map === null) {
            $stored = get_option($this->changes_map_key, null);
            $this->changes_map = is_array($stored)
                ? array_merge(['plugins' => [], 'themes' => []], $stored)
                : false;
        }
        if ($this->changes_map === false || $this->is_scan_due()) {
            $this->queue_background_scan();
        }
        return $this->changes_map === false
            ? ['plugins' => [], 'themes' => []]
            : $this->changes_map;
    }

    public function has_changes_cached($item, $type)
    {
        return $this->get_change_count_cached($item, $type) > 0;
    }

    /**
     * Number of changed files recorded for an item by the last scan. Zero
     * also covers "no baseline" and "never scanned yet".
     */
    public function get_change_count_cached($item, $type)
    {
        $map    = $this->get_changes_map();
        $bucket = $type === 'plugin' ? 'plugins' : 'themes';
        return isset($map[$bucket][$item]) ? (int) $map[$bucket][$item] : 0;
    }

    public function get_cached_changes_for_warnings()
    {
        return $this->get_changes_map();
    }

    /**
     * Fingerprint of the rendered map, so a background scan can tell the
     * browser whether the page it is looking at went out of date.
     */
    public function get_map_signature($map = null)
    {
        if ($map === null) {
            $map = $this->get_changes_map();
        }
        return md5(wp_json_encode($map));
    }

    /**
     * True when the screen is rendering without a fresh map. The browser uses
     * this to kick the scan over AJAX, which keeps the work off the page
     * request even where WP-Cron cannot spawn its own loopback request.
     */
    public function needs_background_scan()
    {
        if (get_option('wp_code_guardian_scan_frequency', 'daily') === 'disabled') {
            return false;
        }
        if (get_transient($this->scan_lock_key)) {
            return false;
        }
        return !is_array(get_option($this->changes_map_key, null)) || $this->is_scan_due();
    }

    /**
     * Queue the scan as a one-off WP-Cron event and return immediately. No
     * scanning happens here; the event fires in a later, separate request.
     */
    public function queue_background_scan()
    {
        if (get_option('wp_code_guardian_scan_frequency', 'daily') === 'disabled') {
            return;
        }
        if (get_transient($this->scan_lock_key)) {
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK_ONCE)) {
            wp_schedule_single_event(time(), self::CRON_HOOK_ONCE);
        }
    }

    /**
     * The expensive pass. Only ever reached from the WP-Cron handler or the
     * dedicated AJAX endpoint -- never from a page render. A short-lived lock
     * keeps concurrent requests from scanning on top of each other.
     */
    public function run_scan()
    {
        if (get_transient($this->scan_lock_key)) {
            return false;
        }
        set_transient($this->scan_lock_key, time(), 10 * MINUTE_IN_SECONDS);
        try {
            $map = $this->build_changes_map();
            update_option($this->changes_map_key, $map, false);
            update_option($this->last_check_key, time(), false);
            delete_transient($this->legacy_cache_key);
            $this->changes_map = $map;
            Logger::log(sprintf(
                'Background scan finished: %d modified plugins, %d modified themes',
                count($map['plugins']),
                count($map['themes'])
            ));
        } finally {
            delete_transient($this->scan_lock_key);
        }
        return true;
    }

    /**
     * Drop the cached map and queue a fresh scan. Used whenever baselines
     * change underneath it: manual refresh, rescan, or a WordPress update.
     */
    public function invalidate_changes_map()
    {
        delete_option($this->changes_map_key);
        delete_option($this->last_check_key);
        delete_transient($this->legacy_cache_key);
        $this->changes_map = null;
        $this->queue_background_scan();
    }

    /**
     * Compare every plugin and theme against its baseline. Hash-only: file
     * contents are never loaded and no diffs are generated here.
     */
    private function build_changes_map()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $map = ['plugins' => [], 'themes' => []];

        foreach (array_keys(get_plugins()) as $plugin_file) {
            if (!$this->plugin_scanner->has_stored_snapshots($plugin_file)) {
                continue;
            }
            $count = $this->plugin_scanner->count_changes($plugin_file);
            if ($count > 0) {
                $map['plugins'][$plugin_file] = $count;
            }
        }
        foreach (array_keys(wp_get_themes()) as $slug) {
            if (!$this->theme_scanner->has_stored_snapshots($slug)) {
                continue;
            }
            $count = $this->theme_scanner->count_changes($slug);
            if ($count > 0) {
                $map['themes'][$slug] = $count;
            }
        }
        return $map;
    }

    public function enqueue_admin_assets($hook)
    {
        $is_guardian = strpos($hook, 'wp-code-guardian') !== false;
        $is_wp_page  = in_array($hook, ['plugins.php', 'themes.php', 'update.php', 'update-core.php'], true);
        if (!$is_guardian && !$is_wp_page) {
            return;
        }
        // The diff modal is built out of core's .media-modal markup, which is
        // positioned by this stylesheet. Without it the modal renders as an
        // unstyled block at the foot of the page and clicking "View Changes"
        // looks like it does nothing at all.
        wp_enqueue_style('media-views');
        wp_enqueue_style(
            'wp-code-guardian-admin',
            WP_CODE_GUARDIAN_PLUGIN_URL . 'assets/css/admin.css',
            ['media-views'],
            WP_CODE_GUARDIAN_VERSION
        );
        wp_enqueue_style(
            'wp-code-guardian-diff',
            WP_CODE_GUARDIAN_PLUGIN_URL . 'assets/css/diff.css',
            [],
            WP_CODE_GUARDIAN_VERSION
        );
        wp_enqueue_script(
            'wp-code-guardian-admin',
            WP_CODE_GUARDIAN_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WP_CODE_GUARDIAN_VERSION,
            true
        );
        wp_localize_script(
            'wp-code-guardian-admin',
            'wpCodeGuardian',
            [
                'ajax_url'       => admin_url('admin-ajax.php'),
                'nonce'          => wp_create_nonce('wp_code_guardian'),
                'scan_pending'   => $this->needs_background_scan(),
                'scan_signature' => $this->get_map_signature(),
                'strings'  => [
                    'view_changes'     => __('View Changes', 'wp-code-guardian'),
                    'refresh_snapshot' => __('Refresh Baseline', 'wp-code-guardian'),
                    'confirm_refresh'  => __('Are you sure you want to refresh the baseline? This will overwrite the stored snapshot.', 'wp-code-guardian'),
                    'loading'          => __('Loading…', 'wp-code-guardian'),
                    'error'            => __('An error occurred.', 'wp-code-guardian'),
                    'scan_updated'     => __('Code Guardian finished checking for code changes in the background.', 'wp-code-guardian'),
                    'reload'           => __('Reload to see the results', 'wp-code-guardian'),
                    'scan_preparing'   => __('Preparing…', 'wp-code-guardian'),
                    'scan_building'    => __('Building baseline for', 'wp-code-guardian'),
                    'scan_comparing'   => __('Comparing files against the baselines…', 'wp-code-guardian'),
                    'scan_done'        => __('Done. Reloading…', 'wp-code-guardian'),
                    /* translators: %d: number of items that could not be scanned. */
                    'scan_failed'      => __('%d item(s) could not be scanned.', 'wp-code-guardian'),
                ],
            ]
        );
    }

    public function add_plugin_labels()
    {
        add_action('after_plugin_row', [$this, 'show_plugin_changes_row'], 10, 3);
    }

    public function show_plugin_changes_row($plugin_file, $plugin_data, $status)
    {
        // Cached lookup only. The comparison that produced this count ran in a
        // background request; rendering the row must never scan or diff.
        $count = $this->get_change_count_cached($plugin_file, 'plugin');
        if ($count === 0) {
            return;
        }
        ?>
        <tr class="plugin-update-tr wp-code-guardian-changes" data-plugin="<?php echo esc_attr($plugin_file); ?>">
            <td colspan="4" class="plugin-update colspanchange">
                <div class="notice notice-warning notice-alt inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %d: number of modified files. */
                            esc_html(_n(
                                'Code changes detected: %d file has been modified.',
                                'Code changes detected: %d files have been modified.',
                                $count,
                                'wp-code-guardian'
                            )),
                            (int) $count
                        );
                        ?>
                        &nbsp;
                        <a href="#" class="wp-code-guardian-view-changes" data-type="plugin" data-item="<?php echo esc_attr($plugin_file); ?>"><?php esc_html_e('View Changes', 'wp-code-guardian'); ?></a>
                        &nbsp;|&nbsp;
                        <a href="#" class="wp-code-guardian-refresh-snapshot" data-type="plugin" data-item="<?php echo esc_attr($plugin_file); ?>"><?php esc_html_e('Accept Changes', 'wp-code-guardian'); ?></a>
                    </p>
                </div>
            </td>
        </tr>
        <?php
    }

    public function add_theme_labels()
    {
        add_action('admin_footer', [$this, 'inject_theme_labels_script']);
    }

    public function inject_theme_labels_script()
    {
        $map            = $this->get_changes_map();
        $modified_slugs = array_keys($map['themes']);
        if (empty($modified_slugs)) {
            return;
        }
        $badge_label = esc_js(__('Modified', 'wp-code-guardian'));
        ?>
        <script>
        jQuery(function ($) {
            var modified = <?php echo wp_json_encode($modified_slugs); ?>;
            modified.forEach(function (slug) {
                var $card = $('.theme[data-slug="' + slug + '"]');
                $card.addClass('wp-code-guardian-has-changes');
                if ($card.find('.wp-code-guardian-badge').length === 0) {
                    $card.find('.theme-name').append(' <span class="wp-code-guardian-badge"><?php echo $badge_label; ?></span>');
                }
            });
        });
        </script>
        <?php
    }

    public function add_plugin_row_meta($plugin_meta, $plugin_file)
    {
        if (!$this->has_changes_cached($plugin_file, 'plugin')) {
            return $plugin_meta;
        }
        $plugin_meta[] = '<span class="wp-code-guardian-indicator">' . esc_html__('Modified', 'wp-code-guardian') . '</span>';
        return $plugin_meta;
    }

    public function show_update_warnings()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['update-core', 'update'], true)) {
            return;
        }
        $map = $this->get_changes_map();
        if (empty($map['plugins']) && empty($map['themes'])) {
            return;
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();

        $modified_plugins = [];
        foreach (array_keys($map['plugins']) as $plugin_file) {
            $modified_plugins[] = isset($plugins[$plugin_file]['Name'])
                ? $plugins[$plugin_file]['Name']
                : $plugin_file;
        }
        $modified_themes = [];
        foreach (array_keys($map['themes']) as $slug) {
            $theme             = wp_get_theme($slug);
            $modified_themes[] = $theme->exists() ? $theme->get('Name') : $slug;
        }
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><strong><?php esc_html_e('Code Guardian Alert:', 'wp-code-guardian'); ?></strong></p>
            <?php if (!empty($modified_plugins)) : ?>
                <p><?php esc_html_e('Plugins with custom modifications:', 'wp-code-guardian'); ?> <em><?php echo esc_html(implode(', ', $modified_plugins)); ?></em></p>
            <?php endif; ?>
            <?php if (!empty($modified_themes)) : ?>
                <p><?php esc_html_e('Themes with custom modifications:', 'wp-code-guardian'); ?> <em><?php echo esc_html(implode(', ', $modified_themes)); ?></em></p>
            <?php endif; ?>
            <p>
                <?php esc_html_e('Updating these items will overwrite your custom changes.', 'wp-code-guardian'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-code-guardian')); ?>"><?php esc_html_e('Review changes before updating', 'wp-code-guardian'); ?></a>
            </p>
        </div>
        <?php
    }

    public function show_welcome_notice()
    {
        if (!get_transient('wp_code_guardian_show_welcome_notice')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }
        $allowed = ['plugins', 'themes', 'toplevel_page_wp-code-guardian'];
        if (!in_array($screen->id, $allowed, true)) {
            return;
        }
        $plugins_url = esc_url(admin_url('admin.php?page=wp-code-guardian-plugins'));
        $themes_url  = esc_url(admin_url('admin.php?page=wp-code-guardian-themes'));
        $nonce       = wp_create_nonce('wp_code_guardian_dismiss_welcome');
        ?>
        <div class="notice notice-info is-dismissible wp-code-guardian-welcome">
            <h3><?php esc_html_e('Welcome to WP Code Guardian!', 'wp-code-guardian'); ?></h3>
            <p><?php esc_html_e('No baselines exist yet. Create them to start detecting unauthorized code modifications.', 'wp-code-guardian'); ?></p>
            <p>
                <a href="<?php echo $plugins_url; ?>" class="button button-primary"><?php esc_html_e('Create Plugin Baselines', 'wp-code-guardian'); ?></a>
                <a href="<?php echo $themes_url; ?>" class="button"><?php esc_html_e('Create Theme Baselines', 'wp-code-guardian'); ?></a>
                <a href="#" class="button wp-code-guardian-dismiss-welcome" data-nonce="<?php echo esc_attr($nonce); ?>"><?php esc_html_e('Dismiss', 'wp-code-guardian'); ?></a>
            </p>
            <script>
            jQuery(function ($) {
                $('.wp-code-guardian-dismiss-welcome').on('click', function (e) {
                    e.preventDefault();
                    var $btn = $(this);
                    $.post(ajaxurl, {
                        action: 'wp_code_guardian_dismiss_welcome',
                        nonce: $btn.data('nonce')
                    }, function () {
                        $btn.closest('.wp-code-guardian-welcome').fadeOut();
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public function ajax_get_diff()
    {
        try {
            $this->verify_ajax_request();
            $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
            $item = isset($_POST['item']) ? sanitize_text_field(wp_unslash($_POST['item'])) : '';
            Logger::log('ajax_get_diff type=' . $type . ' item=' . $item);

            $changes = [];
            if ($type === 'plugin') {
                $changes = $this->plugin_scanner->get_changes($item);
            } elseif ($type === 'theme') {
                $changes = $this->theme_scanner->get_changes($item);
            }
            if (empty($changes)) {
                wp_send_json_success([
                    'html'    => '<p>' . esc_html__('No changes detected.', 'wp-code-guardian') . '</p>',
                    'changes' => [],
                ]);
            }
            ob_start();
            $diff_changes = $changes;
            $diff_item    = $item;
            $diff_type    = $type;
            include WP_CODE_GUARDIAN_PLUGIN_DIR . 'admin/views/diff-modal.php';
            $html = ob_get_clean();
            wp_send_json_success(['html' => $html, 'changes' => $changes]);
        } catch (\Exception $e) {
            Logger::log('ajax_get_diff: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    public function ajax_refresh_snapshot()
    {
        try {
            $this->verify_ajax_request();
            $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
            $item = isset($_POST['item']) ? sanitize_text_field(wp_unslash($_POST['item'])) : '';
            $result = ['remote' => false];
            if ($type === 'plugin') {
                $result = $this->plugin_scanner->refresh_snapshot($item);
            } elseif ($type === 'theme') {
                $result = $this->theme_scanner->refresh_snapshot($item);
            } else {
                wp_send_json_error('Unknown type');
            }
            $this->invalidate_changes_map();
            $msg = !empty($result['remote'])
                ? __('Baseline created/updated successfully from WordPress.org', 'wp-code-guardian')
                : __('Baseline updated from current files', 'wp-code-guardian');
            Logger::log('refreshed ' . $type . ' ' . $item);
            wp_send_json_success(['message' => $msg]);
        } catch (\Exception $e) {
            Logger::log('ajax_refresh_snapshot: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    public function ajax_scan_all()
    {
        try {
            $this->verify_ajax_request();
            $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';

            if ($type === 'plugins') {
                $this->plugin_scanner->scan_all();
            } elseif ($type === 'themes') {
                $this->theme_scanner->scan_all();
            } else {
                $this->plugin_scanner->scan_all();
                $this->theme_scanner->scan_all();
            }
            // Baselines just changed, so recompute the map here rather than
            // leaving the next admin page load to notice it is stale.
            $this->invalidate_changes_map();
            $this->run_scan();
            wp_send_json_success(['message' => __('Scan completed successfully', 'wp-code-guardian')]);
        } catch (\Exception $e) {
            Logger::log('ajax_scan_all: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    public function ajax_clear_snapshots()
    {
        try {
            $this->verify_ajax_request();
            global $wpdb;
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names cannot be parameterized; values are internal constants.
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}code_guardian_plugins");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}code_guardian_themes");
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
            $this->invalidate_changes_map();
            wp_send_json_success(['message' => __('All snapshots cleared successfully', 'wp-code-guardian')]);
        } catch (\Exception $e) {
            Logger::log('ajax_clear_snapshots: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    public function ajax_rescan_all()
    {
        try {
            $this->verify_ajax_request();
            $this->plugin_scanner->scan_all();
            $this->theme_scanner->scan_all();
            $this->invalidate_changes_map();
            $this->run_scan();
            wp_send_json_success(['message' => __('Rescan completed successfully', 'wp-code-guardian')]);
        } catch (\Exception $e) {
            Logger::log('ajax_rescan_all: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Step 1 of a batch scan: the list of items the browser will walk. Sending
     * the queue up front is what lets the progress bar show a real total
     * instead of an indeterminate spinner.
     */
    public function ajax_scan_queue()
    {
        try {
            $this->verify_ajax_request();
            $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'all';
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $items = [];
            if ($type === 'plugins' || $type === 'all') {
                foreach (get_plugins() as $plugin_file => $data) {
                    $items[] = [
                        'type'  => 'plugin',
                        'item'  => $plugin_file,
                        'label' => $data['Name'],
                    ];
                }
            }
            if ($type === 'themes' || $type === 'all') {
                foreach (wp_get_themes() as $slug => $theme) {
                    $items[] = [
                        'type'  => 'theme',
                        'item'  => $slug,
                        'label' => $theme->get('Name'),
                    ];
                }
            }
            wp_send_json_success(['items' => $items, 'total' => count($items)]);
        } catch (\Exception $e) {
            Logger::log('ajax_scan_queue: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Step 2: rebuild the baseline for a single item. One request per item
     * keeps each one well inside the PHP time limit and gives the browser
     * something to count.
     */
    public function ajax_scan_item()
    {
        try {
            $this->verify_ajax_request();
            $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
            $item = isset($_POST['item']) ? sanitize_text_field(wp_unslash($_POST['item'])) : '';

            if ($type === 'plugin') {
                $result = $this->plugin_scanner->refresh_snapshot($item);
            } elseif ($type === 'theme') {
                $result = $this->theme_scanner->refresh_snapshot($item);
            } else {
                wp_send_json_error('Unknown type');
                return;
            }
            wp_send_json_success([
                'item'   => $item,
                'remote' => !empty($result['remote']),
            ]);
        } catch (\Exception $e) {
            Logger::log('ajax_scan_item: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Step 3: every baseline is current, so recompute the change map once.
     */
    public function ajax_scan_finish()
    {
        try {
            $this->verify_ajax_request();
            $this->invalidate_changes_map();
            $this->run_scan();
            $map = $this->get_changes_map();
            wp_send_json_success([
                'plugins' => count($map['plugins']),
                'themes'  => count($map['themes']),
            ]);
        } catch (\Exception $e) {
            Logger::log('ajax_scan_finish: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Runs the change scan out of band, triggered by the browser after the
     * admin page has already rendered. Nothing the user is waiting on blocks
     * on this request.
     */
    public function ajax_run_scan()
    {
        try {
            $this->verify_ajax_request();
            $rendered = isset($_POST['signature']) ? sanitize_text_field(wp_unslash($_POST['signature'])) : '';
            $ran      = $this->run_scan();
            $map      = $this->get_changes_map();
            wp_send_json_success([
                'ran'     => (bool) $ran,
                'updated' => $ran && $rendered !== '' && $rendered !== $this->get_map_signature($map),
                'plugins' => count($map['plugins']),
                'themes'  => count($map['themes']),
            ]);
        } catch (\Exception $e) {
            Logger::log('ajax_run_scan: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    public function ajax_dismiss_welcome()
    {
        try {
            check_ajax_referer('wp_code_guardian_dismiss_welcome', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Forbidden', 403);
            }
            delete_transient('wp_code_guardian_show_welcome_notice');
            wp_send_json_success();
        } catch (\Exception $e) {
            Logger::log('ajax_dismiss_welcome: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Shared guard for the admin AJAX endpoints: a valid nonce plus the
     * manage_options capability. Sends a JSON error and halts on failure.
     */
    private function verify_ajax_request()
    {
        check_ajax_referer('wp_code_guardian', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden', 403);
        }
    }
}
