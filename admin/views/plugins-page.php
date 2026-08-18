<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$plugin_scanner = code_guardian()->plugin_scanner ?? null;
$admin_manager  = code_guardian()->admin_manager ?? null;
$plugins        = get_plugins();
?>
<div class="wrap code-guardian-wrap">
    <h1><?php esc_html_e('Plugin Changes', 'code-guardian'); ?></h1>
    <p>
        <button type="button" class="button button-primary code-guardian-scan-all" data-type="plugins"><?php esc_html_e('Scan All Plugins', 'code-guardian'); ?></button>
    </p>
    <table class="wp-list-table widefat fixed striped code-guardian-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Plugin', 'code-guardian'); ?></th>
                <th>
                    <?php esc_html_e('Status', 'code-guardian'); ?>
                    <?php if ($admin_manager) { $admin_manager->render_help_tip($admin_manager->status_help_text(), __('What do these statuses mean?', 'code-guardian')); } ?>
                </th>
                <th><?php esc_html_e('Changes', 'code-guardian'); ?></th>
                <th><?php esc_html_e('Actions', 'code-guardian'); ?></th>
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
                        <span class="code-guardian-status no-baseline"><span class="dashicons dashicons-info"></span> <?php esc_html_e('No Baseline', 'code-guardian'); ?></span>
                    <?php elseif ($is_modified) : ?>
                        <span class="code-guardian-status modified"><span class="dashicons dashicons-warning"></span> <?php esc_html_e('Modified', 'code-guardian'); ?></span>
                    <?php else : ?>
                        <span class="code-guardian-status clean"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Clean', 'code-guardian'); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$has_baseline) : ?>
                        <?php esc_html_e('Create baseline first', 'code-guardian'); ?>
                    <?php elseif ($is_modified) : ?>
                        <?php /* translators: %d: number of changed files. */ ?>
                        <?php echo esc_html(sprintf(_n('%d file changed', '%d files changed', $change_count, 'code-guardian'), $change_count)); ?>
                    <?php else : ?>
                        <?php esc_html_e('No changes', 'code-guardian'); ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$has_baseline) : ?>
                        <button type="button" class="button button-primary code-guardian-refresh-snapshot" data-type="plugin" data-item="<?php echo esc_attr($plugin_file); ?>"><?php esc_html_e('Create Baseline', 'code-guardian'); ?></button>
                    <?php elseif ($is_modified) : ?>
                        <button type="button" class="button code-guardian-view-changes" data-type="plugin" data-item="<?php echo esc_attr($plugin_file); ?>"><?php esc_html_e('View Changes', 'code-guardian'); ?></button>
                        <button type="button" class="button code-guardian-keep-changes" data-type="plugin" data-item="<?php echo esc_attr($plugin_file); ?>"><?php esc_html_e('Accept Changes', 'code-guardian'); ?></button>
                    <?php else : ?>
                        <button type="button" class="button code-guardian-refresh-snapshot" data-type="plugin" data-item="<?php echo esc_attr($plugin_file); ?>"><?php esc_html_e('Update Baseline', 'code-guardian'); ?></button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
