<?php
if (!defined('ABSPATH')) { exit; }

// This template is included from inside a method of AdminManager, so the
// variables below are local to that method, not globals. PHPCS cannot see
// the include site and reports them as unprefixed globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$plugin_scanner = milardovich_fmm()->plugin_scanner ?? null;
$theme_scanner  = milardovich_fmm()->theme_scanner ?? null;
$admin_manager  = milardovich_fmm()->admin_manager ?? null;

$plugin_baselines = 0;
$modified_plugins = 0;
$theme_baselines  = 0;
$modified_themes  = 0;

if ($plugin_scanner) {
    foreach (array_keys(get_plugins()) as $plugin_file) {
        if ($plugin_scanner->has_stored_snapshots($plugin_file)) {
            $plugin_baselines++;
            if ($admin_manager && $admin_manager->has_changes_cached($plugin_file, 'plugin')) {
                $modified_plugins++;
            }
        }
    }
}
if ($theme_scanner) {
    foreach (wp_get_themes() as $slug => $theme) {
        if ($theme_scanner->has_stored_snapshots($slug)) {
            $theme_baselines++;
            if ($admin_manager && $admin_manager->has_changes_cached($slug, 'theme')) {
                $modified_themes++;
            }
        }
    }
}
?>
<div class="wrap milardovich-fmm-wrap">
    <h1><?php esc_html_e('Milardovich File Modification Monitor', 'milardovich-file-modification-monitor'); ?></h1>
    <p class="description"><?php esc_html_e('Detect unauthorized modifications in plugins and themes by comparing them against pristine copies from WordPress.org.', 'milardovich-file-modification-monitor'); ?></p>

    <div class="milardovich-fmm-stats">
        <div class="stat-card">
            <span class="dashicons dashicons-yes-alt"></span>
            <h2><?php echo (int) $plugin_baselines; ?></h2>
            <p>
                <?php esc_html_e('Plugin baselines', 'milardovich-file-modification-monitor'); ?>
                <?php if ($admin_manager) { $admin_manager->render_help_tip($admin_manager->baseline_help_text(), __('What is a baseline?', 'milardovich-file-modification-monitor')); } ?>
            </p>
        </div>
        <div class="stat-card<?php echo $modified_plugins ? ' has-changes' : ''; ?>">
            <span class="dashicons dashicons-warning"></span>
            <h2><?php echo (int) $modified_plugins; ?></h2>
            <p>
                <?php esc_html_e('Modified plugins', 'milardovich-file-modification-monitor'); ?>
                <?php if ($admin_manager) { $admin_manager->render_help_tip($admin_manager->modified_help_text(), __('What does modified mean?', 'milardovich-file-modification-monitor')); } ?>
            </p>
        </div>
        <div class="stat-card">
            <span class="dashicons dashicons-yes-alt"></span>
            <h2><?php echo (int) $theme_baselines; ?></h2>
            <p>
                <?php esc_html_e('Theme baselines', 'milardovich-file-modification-monitor'); ?>
                <?php if ($admin_manager) { $admin_manager->render_help_tip($admin_manager->baseline_help_text(), __('What is a baseline?', 'milardovich-file-modification-monitor')); } ?>
            </p>
        </div>
        <div class="stat-card<?php echo $modified_themes ? ' has-changes' : ''; ?>">
            <span class="dashicons dashicons-warning"></span>
            <h2><?php echo (int) $modified_themes; ?></h2>
            <p>
                <?php esc_html_e('Modified themes', 'milardovich-file-modification-monitor'); ?>
                <?php if ($admin_manager) { $admin_manager->render_help_tip($admin_manager->modified_help_text(), __('What does modified mean?', 'milardovich-file-modification-monitor')); } ?>
            </p>
        </div>
    </div>

    <div class="milardovich-fmm-card">
        <h2><?php esc_html_e('Quick Actions', 'milardovich-file-modification-monitor'); ?></h2>
        <p>
            <button type="button" class="button button-primary milardovich-fmm-scan-all" data-type="all"><?php esc_html_e('Scan All Items', 'milardovich-file-modification-monitor'); ?></button>
            <a href="<?php echo esc_url(admin_url('admin.php?page=milardovich-fmm-settings')); ?>" class="button"><?php esc_html_e('Settings', 'milardovich-file-modification-monitor'); ?></a>
        </p>
    </div>

    <div class="milardovich-fmm-card">
        <h2><?php esc_html_e('How It Works', 'milardovich-file-modification-monitor'); ?></h2>
        <ol>
            <li><?php esc_html_e('Create baselines by downloading original plugin and theme files from WordPress.org.', 'milardovich-file-modification-monitor'); ?></li>
            <li><?php esc_html_e('The plugin compares the files on disk against the baseline using SHA-256 hashes.', 'milardovich-file-modification-monitor'); ?></li>
            <li><?php esc_html_e('Modified plugins and themes are flagged in the WordPress admin, including a warning before any update.', 'milardovich-file-modification-monitor'); ?></li>
            <li><?php esc_html_e('After an authorized update, the baseline is regenerated automatically.', 'milardovich-file-modification-monitor'); ?></li>
        </ol>
        <div class="milardovich-fmm-info">
            <span class="dashicons dashicons-info"></span>
            <?php esc_html_e('Baselines come from WordPress.org, not from the current files on disk — that is what makes drift detectable.', 'milardovich-file-modification-monitor'); ?>
        </div>
    </div>
</div>
