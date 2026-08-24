<?php
namespace MilardovichFMM\Admin;

use MilardovichFMM\Scanner\PluginScanner;
use MilardovichFMM\Scanner\ThemeScanner;
use MilardovichFMM\Core\Logger;

class AdminManager
{
    private $plugin_scanner;
    private $theme_scanner;

    /** Recurring background scan, scheduled from the frequency setting. */
    const CRON_HOOK = 'milardovich_fmm_scan';

    /** One-off background scan, queued when the cached map is missing/stale. */
    const CRON_HOOK_ONCE = 'milardovich_fmm_scan_now';

    private $changes_map_key  = 'milardovich_fmm_changes_map';
    private $legacy_cache_key = 'milardovich_fmm_changes_cache';
    private $last_check_key   = 'milardovich_fmm_last_check';
    private $scan_lock_key    = 'milardovich_fmm_scan_lock';

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

        add_action('wp_ajax_milardovich_fmm_get_diff', [$this, 'ajax_get_diff']);
        add_action('wp_ajax_milardovich_fmm_refresh_snapshot', [$this, 'ajax_refresh_snapshot']);
        add_action('wp_ajax_milardovich_fmm_scan_all', [$this, 'ajax_scan_all']);
        add_action('wp_ajax_milardovich_fmm_clear_snapshots', [$this, 'ajax_clear_snapshots']);
        add_action('wp_ajax_milardovich_fmm_rescan_all', [$this, 'ajax_rescan_all']);
        add_action('wp_ajax_milardovich_fmm_run_scan', [$this, 'ajax_run_scan']);
        add_action('wp_ajax_milardovich_fmm_scan_queue', [$this, 'ajax_scan_queue']);
        add_action('wp_ajax_milardovich_fmm_scan_item', [$this, 'ajax_scan_item']);
        add_action('wp_ajax_milardovich_fmm_scan_finish', [$this, 'ajax_scan_finish']);
        add_action('wp_ajax_milardovich_fmm_accept_changes', [$this, 'ajax_accept_changes']);
        add_action('wp_ajax_milardovich_fmm_restore_original', [$this, 'ajax_restore_original']);
        add_action('wp_ajax_milardovich_fmm_dismiss_welcome', [$this, 'ajax_dismiss_welcome']);

        add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
        add_action('admin_notices', [$this, 'show_update_warnings']);
        add_action('admin_notices', [$this, 'show_welcome_notice']);

        add_action('load-plugins.php', [$this, 'add_plugin_labels']);
    }

    public function add_admin_menu()
    {
        add_menu_page(
            __('Milardovich File Modification Monitor', 'milardovich-file-modification-monitor'),
            __('File Monitor', 'milardovich-file-modification-monitor'),
            'manage_options',
            'milardovich-fmm',
            [$this, 'render_main_page'],
            'dashicons-shield',
            80
        );
        add_submenu_page('milardovich-fmm', __('Milardovich File Modification Monitor', 'milardovich-file-modification-monitor'), __('Dashboard', 'milardovich-file-modification-monitor'), 'manage_options', 'milardovich-fmm', [$this, 'render_main_page']);
        add_submenu_page('milardovich-fmm', __('Plugin Changes', 'milardovich-file-modification-monitor'), __('Plugin Changes', 'milardovich-file-modification-monitor'), 'manage_options', 'milardovich-fmm-plugins', [$this, 'render_plugins_page']);
        add_submenu_page('milardovich-fmm', __('Theme Changes', 'milardovich-file-modification-monitor'), __('Theme Changes', 'milardovich-file-modification-monitor'), 'manage_options', 'milardovich-fmm-themes', [$this, 'render_themes_page']);
        add_submenu_page('milardovich-fmm', __('Settings', 'milardovich-file-modification-monitor'), __('Settings', 'milardovich-file-modification-monitor'), 'manage_options', 'milardovich-fmm-settings', [$this, 'render_settings_page']);
    }

    /**
     * Cache-busting version for a bundled asset. The plugin version only
     * changes at release, so without the file's own mtime an edited
     * stylesheet keeps being served from the browser cache under the same
     * ?ver= string.
     */
    public static function asset_version($relative_path)
    {
        $file = MILARDOVICH_FMM_PLUGIN_DIR . ltrim($relative_path, '/');
        $time = file_exists($file) ? filemtime($file) : false;
        return $time ? MILARDOVICH_FMM_VERSION . '.' . $time : MILARDOVICH_FMM_VERSION;
    }

    /**
     * A small "?" affordance with an explanation attached. "Baseline" is the
     * central idea in this plugin and means nothing to someone opening it for
     * the first time, so every screen that uses the word explains it.
     *
     * @param string $text  The explanation.
     * @param string $label Accessible name for the button.
     */
    public function help_tip($text, $label = '')
    {
        if ($label === '') {
            $label = __('More information', 'milardovich-file-modification-monitor');
        }
        return sprintf(
            '<span class="milardovich-fmm-tip">'
                . '<button type="button" class="milardovich-fmm-tip-toggle" aria-expanded="false" aria-label="%1$s">'
                    . '<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>'
                . '</button>'
                . '<span class="milardovich-fmm-tip-text" role="tooltip">%2$s</span>'
            . '</span>',
            esc_attr($label),
            esc_html($text)
        );
    }

    /**
     * Echo a help tip. The views call this rather than echoing help_tip(),
     * which keeps the escaping inside this class where it can be seen.
     *
     * @param string $text  The explanation.
     * @param string $label Accessible name for the button.
     */
    public function render_help_tip($text, $label = '')
    {
        echo $this->help_tip($text, $label); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- help_tip() escapes both arguments as it builds the markup.
    }

    /**
     * Kept in one place so every screen explains a baseline the same way.
     */
    public function baseline_help_text()
    {
        return __('A baseline is the pristine copy of a plugin or theme, downloaded from WordPress.org the first time you scan it. This plugin compares the files on your site against that copy — anything that differs is a local modification.', 'milardovich-file-modification-monitor');
    }

    public function modified_help_text()
    {
        return __('These items have files that no longer match their baseline, which means someone edited them directly on the server. Updating them would overwrite those edits.', 'milardovich-file-modification-monitor');
    }

    public function status_help_text()
    {
        return __('No Baseline: there is nothing to compare against yet — create one first. Clean: every file matches the baseline. Modified: at least one file differs from it.', 'milardovich-file-modification-monitor');
    }

    public function render_main_page()
    {
        include MILARDOVICH_FMM_PLUGIN_DIR . 'admin/views/main-page.php';
    }

    public function render_plugins_page()
    {
        include MILARDOVICH_FMM_PLUGIN_DIR . 'admin/views/plugins-page.php';
    }

    public function render_themes_page()
    {
        include MILARDOVICH_FMM_PLUGIN_DIR . 'admin/views/themes-page.php';
    }

    public function render_settings_page()
    {
        include MILARDOVICH_FMM_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    public function register_settings()
    {
        register_setting('milardovich_fmm_settings', 'milardovich_fmm_scan_frequency', ['sanitize_callback' => [$this, 'sanitize_scan_frequency']]);
        register_setting('milardovich_fmm_settings', 'milardovich_fmm_email_notifications', ['sanitize_callback' => 'absint']);
        register_setting('milardovich_fmm_settings', 'milardovich_fmm_notification_email', ['sanitize_callback' => 'sanitize_email']);
        register_setting('milardovich_fmm_settings', 'milardovich_fmm_ignored_files', ['sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('milardovich_fmm_settings', 'milardovich_fmm_show_warnings', ['sanitize_callback' => 'absint']);
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
        if (get_option('milardovich_fmm_scan_frequency', 'daily') === 'disabled') {
            return false;
        }
        return (time() - $this->get_last_check()) >= $this->get_scan_interval();
    }

    public function get_scan_interval()
    {
        $frequency = get_option('milardovich_fmm_scan_frequency', 'daily');
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
        if (get_option('milardovich_fmm_scan_frequency', 'daily') === 'disabled') {
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
        if (get_option('milardovich_fmm_scan_frequency', 'daily') === 'disabled') {
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
        $is_guardian = strpos($hook, 'milardovich-fmm') !== false;
        $is_wp_page  = in_array($hook, ['plugins.php', 'themes.php', 'update.php', 'update-core.php'], true);
        if (!$is_guardian && !$is_wp_page) {
            return;
        }
        // The modal is laid out entirely by admin.css (see the Modal section
        // there); dashicons supplies its close glyph.
        wp_enqueue_style(
            'milardovich-fmm-admin',
            MILARDOVICH_FMM_PLUGIN_URL . 'assets/css/admin.css',
            ['dashicons'],
            self::asset_version('assets/css/admin.css')
        );
        wp_enqueue_style(
            'milardovich-fmm-diff',
            MILARDOVICH_FMM_PLUGIN_URL . 'assets/css/diff.css',
            [],
            self::asset_version('assets/css/diff.css')
        );
        wp_enqueue_script(
            'milardovich-fmm-admin',
            MILARDOVICH_FMM_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            self::asset_version('assets/js/admin.js'),
            true
        );
        wp_localize_script(
            'milardovich-fmm-admin',
            'milardovichFMM',
            [
                'ajax_url'       => admin_url('admin-ajax.php'),
                'nonce'          => wp_create_nonce('milardovich_fmm'),
                'scan_pending'   => $this->needs_background_scan(),
                'scan_signature' => $this->get_map_signature(),
                // Slugs of the themes flagged by the last scan. The badges on
                // themes.php are painted by admin.js from this list; the page
                // itself is rendered by WordPress, so there is nothing to hook
                // into server-side.
                'modified_themes' => array_values(array_keys($this->get_changes_map()['themes'])),
                'strings'  => [
                    'view_changes'     => __('View Changes', 'milardovich-file-modification-monitor'),
                    'refresh_snapshot' => __('Refresh Baseline', 'milardovich-file-modification-monitor'),
                    'confirm_refresh'  => __('Are you sure you want to refresh the baseline? This will overwrite the stored snapshot.', 'milardovich-file-modification-monitor'),
                    'loading'          => __('Loading…', 'milardovich-file-modification-monitor'),
                    'error'            => __('An error occurred.', 'milardovich-file-modification-monitor'),
                    'scan_updated'     => __('Milardovich File Modification Monitor finished checking for code changes in the background.', 'milardovich-file-modification-monitor'),
                    'reload'           => __('Reload to see the results', 'milardovich-file-modification-monitor'),
                    'scan_preparing'   => __('Preparing…', 'milardovich-file-modification-monitor'),
                    'scan_building'    => __('Building baseline for', 'milardovich-file-modification-monitor'),
                    'scan_comparing'   => __('Comparing files against the baselines…', 'milardovich-file-modification-monitor'),
                    'scan_done'        => __('Done. Reloading…', 'milardovich-file-modification-monitor'),
                    /* translators: %d: number of items that could not be scanned. */
                    'scan_failed'      => __('%d item(s) could not be scanned.', 'milardovich-file-modification-monitor'),
                    'close'            => __('Close', 'milardovich-file-modification-monitor'),
                    'keep_changes'     => __('Keep My Changes', 'milardovich-file-modification-monitor'),
                    'restore_original' => __('Restore Original', 'milardovich-file-modification-monitor'),
                    'keep_title'       => __('Keep your changes?', 'milardovich-file-modification-monitor'),
                    'keep_confirm'     => __('The files on disk become the new baseline, so these changes stop being reported. Nothing on disk is touched.', 'milardovich-file-modification-monitor'),
                    'restore_title'    => __('Restore the original files?', 'milardovich-file-modification-monitor'),
                    /* translators: %d: number of affected files. */
                    'restore_confirm'  => __('This overwrites %d file(s) on disk with the original version from the baseline. Your changes to them will be lost and this cannot be undone.', 'milardovich-file-modification-monitor'),
                    'working'          => __('Working…', 'milardovich-file-modification-monitor'),
                    'modified_badge'   => __('Modified', 'milardovich-file-modification-monitor'),
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
        <tr class="plugin-update-tr milardovich-fmm-changes" data-plugin="<?php echo esc_attr($plugin_file); ?>">
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
                                'milardovich-file-modification-monitor'
                            )),
                            (int) $count
                        );
                        ?>
                        &nbsp;
                        <a href="#" class="milardovich-fmm-view-changes" data-type="plugin" data-item="<?php echo esc_attr($plugin_file); ?>"><?php esc_html_e('View Changes', 'milardovich-file-modification-monitor'); ?></a>
                        &nbsp;|&nbsp;
                        <a href="#" class="milardovich-fmm-keep-changes" data-type="plugin" data-item="<?php echo esc_attr($plugin_file); ?>"><?php esc_html_e('Accept Changes', 'milardovich-file-modification-monitor'); ?></a>
                    </p>
                </div>
            </td>
        </tr>
        <?php
    }

    public function add_plugin_row_meta($plugin_meta, $plugin_file)
    {
        if (!$this->has_changes_cached($plugin_file, 'plugin')) {
            return $plugin_meta;
        }
        $plugin_meta[] = '<span class="milardovich-fmm-indicator">' . esc_html__('Modified', 'milardovich-file-modification-monitor') . '</span>';
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
            <p><strong><?php esc_html_e('Milardovich File Modification Monitor Alert:', 'milardovich-file-modification-monitor'); ?></strong></p>
            <?php if (!empty($modified_plugins)) : ?>
                <p><?php esc_html_e('Plugins with custom modifications:', 'milardovich-file-modification-monitor'); ?> <em><?php echo esc_html(implode(', ', $modified_plugins)); ?></em></p>
            <?php endif; ?>
            <?php if (!empty($modified_themes)) : ?>
                <p><?php esc_html_e('Themes with custom modifications:', 'milardovich-file-modification-monitor'); ?> <em><?php echo esc_html(implode(', ', $modified_themes)); ?></em></p>
            <?php endif; ?>
            <p>
                <?php esc_html_e('Updating these items will overwrite your custom changes.', 'milardovich-file-modification-monitor'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=milardovich-fmm')); ?>"><?php esc_html_e('Review changes before updating', 'milardovich-file-modification-monitor'); ?></a>
            </p>
        </div>
        <?php
    }

    public function show_welcome_notice()
    {
        if (!get_transient('milardovich_fmm_show_welcome_notice')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }
        $allowed = ['plugins', 'themes', 'toplevel_page_milardovich-fmm'];
        if (!in_array($screen->id, $allowed, true)) {
            return;
        }
        $plugins_url = admin_url('admin.php?page=milardovich-fmm-plugins');
        $themes_url  = admin_url('admin.php?page=milardovich-fmm-themes');
        $nonce       = wp_create_nonce('milardovich_fmm_dismiss_welcome');
        ?>
        <div class="notice notice-info is-dismissible milardovich-fmm-welcome">
            <h3><?php esc_html_e('Welcome to Milardovich File Modification Monitor!', 'milardovich-file-modification-monitor'); ?></h3>
            <p><?php esc_html_e('No baselines exist yet. Create them to start detecting unauthorized code modifications.', 'milardovich-file-modification-monitor'); ?></p>
            <p>
                <a href="<?php echo esc_url($plugins_url); ?>" class="button button-primary"><?php esc_html_e('Create Plugin Baselines', 'milardovich-file-modification-monitor'); ?></a>
                <a href="<?php echo esc_url($themes_url); ?>" class="button"><?php esc_html_e('Create Theme Baselines', 'milardovich-file-modification-monitor'); ?></a>
                <a href="#" class="button milardovich-fmm-dismiss-welcome" data-nonce="<?php echo esc_attr($nonce); ?>"><?php esc_html_e('Dismiss', 'milardovich-file-modification-monitor'); ?></a>
            </p>
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
                    'html'    => '<p>' . esc_html__('No changes detected.', 'milardovich-file-modification-monitor') . '</p>',
                    'changes' => [],
                ]);
            }
            ob_start();
            $diff_changes = $changes;
            $diff_item    = $item;
            $diff_type    = $type;
            include MILARDOVICH_FMM_PLUGIN_DIR . 'admin/views/diff-modal.php';
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
                ? __('Baseline created/updated successfully from WordPress.org', 'milardovich-file-modification-monitor')
                : __('Baseline updated from current files', 'milardovich-file-modification-monitor');
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
            wp_send_json_success(['message' => __('Scan completed successfully', 'milardovich-file-modification-monitor')]);
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
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}milardovich_fmm_plugins");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}milardovich_fmm_themes");
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
            $this->invalidate_changes_map();
            wp_send_json_success(['message' => __('All snapshots cleared successfully', 'milardovich-file-modification-monitor')]);
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
            wp_send_json_success(['message' => __('Rescan completed successfully', 'milardovich-file-modification-monitor')]);
        } catch (\Exception $e) {
            Logger::log('ajax_rescan_all: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Adopt the files on disk as the baseline: the local edits are kept and
     * stop being reported. The counterpart to ajax_restore_original().
     */
    public function ajax_accept_changes()
    {
        try {
            $this->verify_ajax_request();
            $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
            $item = isset($_POST['item']) ? sanitize_text_field(wp_unslash($_POST['item'])) : '';

            if ($type === 'plugin') {
                $this->plugin_scanner->accept_changes($item);
            } elseif ($type === 'theme') {
                $this->theme_scanner->accept_changes($item);
            } else {
                wp_send_json_error('Unknown type');
                return;
            }
            $this->invalidate_changes_map();
            $this->run_scan();
            wp_send_json_success([
                'message' => __('Your changes are now the baseline.', 'milardovich-file-modification-monitor'),
            ]);
        } catch (\Exception $e) {
            Logger::log('ajax_accept_changes: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Write the baseline copy back over the files on disk, discarding the
     * local edits. Destructive, so the browser confirms before calling.
     */
    public function ajax_restore_original()
    {
        try {
            $this->verify_ajax_request();
            $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
            $item = isset($_POST['item']) ? sanitize_text_field(wp_unslash($_POST['item'])) : '';

            if ($type === 'plugin') {
                $result = $this->plugin_scanner->restore_from_baseline($item);
            } elseif ($type === 'theme') {
                $result = $this->theme_scanner->restore_from_baseline($item);
            } else {
                wp_send_json_error('Unknown type');
                return;
            }
            $this->invalidate_changes_map();
            $this->run_scan();

            if (!empty($result['failed'])) {
                wp_send_json_error(sprintf(
                    /* translators: %d: number of files that could not be written. */
                    __('%d file(s) could not be written. Check the file permissions.', 'milardovich-file-modification-monitor'),
                    (int) $result['failed']
                ));
                return;
            }
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: 1: files rewritten, 2: files removed. */
                    __('Restored the original files: %1$d rewritten, %2$d removed.', 'milardovich-file-modification-monitor'),
                    (int) $result['restored'],
                    (int) $result['removed']
                ),
            ]);
        } catch (\Exception $e) {
            Logger::log('ajax_restore_original: ' . $e->getMessage());
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
            check_ajax_referer('milardovich_fmm_dismiss_welcome', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Forbidden', 403);
            }
            delete_transient('milardovich_fmm_show_welcome_notice');
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
        check_ajax_referer('milardovich_fmm', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden', 403);
        }
    }
}
