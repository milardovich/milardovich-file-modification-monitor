<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$plugin_scanner = wp_code_guardian()->plugin_scanner ?? null;
$theme_scanner  = wp_code_guardian()->theme_scanner ?? null;
$admin_manager  = wp_code_guardian()->admin_manager ?? null;

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
<div class="wrap wp-code-guardian-wrap">
    <h1><?php esc_html_e('WP Code Guardian', 'wp-code-guardian'); ?></h1>
    <p class="description"><?php esc_html_e('Detect unauthorized modifications in plugins and themes by comparing them against pristine copies from WordPress.org.', 'wp-code-guardian'); ?></p>

    <div class="wp-code-guardian-stats">
        <div class="stat-card">
            <span class="dashicons dashicons-yes-alt"></span>
            <h2><?php echo (int) $plugin_baselines; ?></h2>
            <p>
                <?php esc_html_e('Plugin baselines', 'wp-code-guardian'); ?>
                <?php if ($admin_manager) { $admin_manager->render_help_tip($admin_manager->baseline_help_text(), __('What is a baseline?', 'wp-code-guardian')); } ?>
            </p>
        </div>
        <div class="stat-card<?php echo $modified_plugins ? ' has-changes' : ''; ?>">
            <span class="dashicons dashicons-warning"></span>
            <h2><?php echo (int) $modified_plugins; ?></h2>
            <p>
                <?php esc_html_e('Modified plugins', 'wp-code-guardian'); ?>
                <?php if ($admin_manager) { $admin_manager->render_help_tip($admin_manager->modified_help_text(), __('What does modified mean?', 'wp-code-guardian')); } ?>
            </p>
        </div>
        <div class="stat-card">
            <span class="dashicons dashicons-yes-alt"></span>
            <h2><?php echo (int) $theme_baselines; ?></h2>
            <p>
                <?php esc_html_e('Theme baselines', 'wp-code-guardian'); ?>
                <?php if ($admin_manager) { $admin_manager->render_help_tip($admin_manager->baseline_help_text(), __('What is a baseline?', 'wp-code-guardian')); } ?>
            </p>
        </div>
        <div class="stat-card<?php echo $modified_themes ? ' has-changes' : ''; ?>">
            <span class="dashicons dashicons-warning"></span>
            <h2><?php echo (int) $modified_themes; ?></h2>
            <p>
                <?php esc_html_e('Modified themes', 'wp-code-guardian'); ?>
                <?php if ($admin_manager) { $admin_manager->render_help_tip($admin_manager->modified_help_text(), __('What does modified mean?', 'wp-code-guardian')); } ?>
            </p>
        </div>
    </div>

    <div class="wp-code-guardian-card">
        <h2><?php esc_html_e('Quick Actions', 'wp-code-guardian'); ?></h2>
        <p>
            <button type="button" class="button button-primary wp-code-guardian-scan-all" data-type="all"><?php esc_html_e('Scan All Items', 'wp-code-guardian'); ?></button>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-code-guardian-settings')); ?>" class="button"><?php esc_html_e('Settings', 'wp-code-guardian'); ?></a>
        </p>
    </div>

    <div class="wp-code-guardian-card">
        <h2><?php esc_html_e('How It Works', 'wp-code-guardian'); ?></h2>
        <ol>
            <li><?php esc_html_e('Create baselines by downloading original plugin and theme files from WordPress.org.', 'wp-code-guardian'); ?></li>
            <li><?php esc_html_e('WP Code Guardian compares the files on disk against the baseline using SHA-256 hashes.', 'wp-code-guardian'); ?></li>
            <li><?php esc_html_e('Modified plugins and themes are flagged in the WordPress admin, including a warning before any update.', 'wp-code-guardian'); ?></li>
            <li><?php esc_html_e('After an authorized update, the baseline is regenerated automatically.', 'wp-code-guardian'); ?></li>
        </ol>
        <div class="wp-code-guardian-info">
            <span class="dashicons dashicons-info"></span>
            <?php esc_html_e('Baselines come from WordPress.org, not from the current files on disk — that is what makes drift detectable.', 'wp-code-guardian'); ?>
        </div>
    </div>
</div>
