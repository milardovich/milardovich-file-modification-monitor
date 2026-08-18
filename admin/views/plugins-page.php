<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$plugin_scanner = wp_code_guardian()->plugin_scanner ?? null;
$admin_manager  = wp_code_guardian()->admin_manager ?? null;
$plugins        = get_plugins();
?>
<div class="wrap wp-code-guardian-wrap">
    <h1><?php esc_html_e('Plugin Changes', 'wp-code-guardian'); ?></h1>
    <p>
        <button type="button" class="button button-primary wp-code-guardian-scan-all" data-type="plugins"><?php esc_html_e('Scan All Plugins', 'wp-code-guardian'); ?></button>
    </p>
    <table class="wp-list-table widefat fixed striped wp-code-guardian-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Plugin', 'wp-code-guardian'); ?></th>
                <th><?php esc_html_e('Status', 'wp-code-guardian'); ?></th>
                <th><?php esc_html_e('Changes', 'wp-code-guardian'); ?></th>
                <th><?php esc_html_e('Actions', 'wp-code-guardian'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($plugins as $plugin_file => $data) :
            $has_baseline = $plugin_scanner ? $plugin_scanner->has_stored_snapshots($plugin_file) : false;
            $change_count = 0;
            $is_modified  = false;
            if ($has_baseline && $admin_manager && $admin_manager->has_changes_cached($plugin_file, 'plugin')) {
                $change_count = $plugin_scanner->count_changes($plugin_file);
                $is_modified  = $change_count > 0;
            }
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($data['Name']); ?></strong>
                    <div class="row-actions"><span><?php echo esc_html($data['Version']); ?></span></div>
                </td>
                <td>
                    <?php if (!$has_baseline) : ?>
                        <span class="wp-code-guardian-status no-baseline"><span class="dashicons dashicons-info"></span> <?php esc_html_e('No Baseline', 'wp-code-guardian'); ?></span>
                    <?php elseif ($is_modified) : ?>
                        <span class="wp-code-guardian-status modified"><span class="dashicons dashicons-warning"></span> <?php esc_html_e('Modified', 'wp-code-guardian'); ?></span>
                    <?php else : ?>
                        <span class="wp-code-guardian-status clean"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Clean', 'wp-code-guardian'); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$has_baseline) : ?>
                        <?php esc_html_e('Create baseline first', 'wp-code-guardian'); ?>
                    <?php elseif ($is_modified) : ?>
                        <?php /* translators: %d: number of changed files. */ ?>
                        <?php printf(esc_html(_n('%d file changed', '%d files changed', $change_count, 'wp-code-guardian')), $change_count); ?>
                    <?php else : ?>
                        <?php esc_html_e('No changes', 'wp-code-guardian'); ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$has_baseline) : ?>
                        <button type="button" class="button button-primary wp-code-guardian-refresh-snapshot" data-type="plugin" data-item="<?php echo esc_attr($plugin_file); ?>"><?php esc_html_e('Create Baseline', 'wp-code-guardian'); ?></button>
                    <?php elseif ($is_modified) : ?>
                        <button type="button" class="button wp-code-guardian-view-changes" data-type="plugin" data-item="<?php echo esc_attr($plugin_file); ?>"><?php esc_html_e('View Changes', 'wp-code-guardian'); ?></button>
                        <button type="button" class="button wp-code-guardian-refresh-snapshot" data-type="plugin" data-item="<?php echo esc_attr($plugin_file); ?>"><?php esc_html_e('Accept Changes', 'wp-code-guardian'); ?></button>
                    <?php else : ?>
                        <button type="button" class="button wp-code-guardian-refresh-snapshot" data-type="plugin" data-item="<?php echo esc_attr($plugin_file); ?>"><?php esc_html_e('Update Baseline', 'wp-code-guardian'); ?></button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
