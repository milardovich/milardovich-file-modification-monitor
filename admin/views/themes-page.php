<?php
if (!defined('ABSPATH')) { exit; }

$theme_scanner   = code_guardian()->theme_scanner ?? null;
$admin_manager   = code_guardian()->admin_manager ?? null;
$themes          = wp_get_themes();
$active_template = get_stylesheet();
?>
<div class="wrap code-guardian-wrap">
    <h1><?php esc_html_e('Theme Changes', 'code-guardian'); ?></h1>
    <p>
        <button type="button" class="button button-primary code-guardian-scan-all" data-type="themes"><?php esc_html_e('Scan All Themes', 'code-guardian'); ?></button>
    </p>
    <table class="wp-list-table widefat fixed striped code-guardian-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Theme', 'code-guardian'); ?></th>
                <th>
                    <?php esc_html_e('Status', 'code-guardian'); ?>
                    <?php if ($admin_manager) { $admin_manager->render_help_tip($admin_manager->status_help_text(), __('What do these statuses mean?', 'code-guardian')); } ?>
                </th>
                <th><?php esc_html_e('Changes', 'code-guardian'); ?></th>
                <th><?php esc_html_e('Actions', 'code-guardian'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($themes as $slug => $theme) :
            $has_baseline = $theme_scanner ? $theme_scanner->has_stored_snapshots($slug) : false;
            $change_count = 0;
            $is_modified  = false;
            if ($has_baseline && $admin_manager && $admin_manager->has_changes_cached($slug, 'theme')) {
                $change_count = $theme_scanner->count_changes($slug);
                $is_modified  = $change_count > 0;
            }
            $version_label = (string) $theme->get('Version');
            if ($slug === $active_template) {
                $version_label .= ' | ' . __('Active', 'code-guardian');
            }
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($theme->get('Name')); ?></strong>
                    <div class="row-actions"><span><?php echo esc_html($version_label); ?></span></div>
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
                        <button type="button" class="button button-primary code-guardian-refresh-snapshot" data-type="theme" data-item="<?php echo esc_attr($slug); ?>"><?php esc_html_e('Create Baseline', 'code-guardian'); ?></button>
                    <?php elseif ($is_modified) : ?>
                        <button type="button" class="button code-guardian-view-changes" data-type="theme" data-item="<?php echo esc_attr($slug); ?>"><?php esc_html_e('View Changes', 'code-guardian'); ?></button>
                        <button type="button" class="button code-guardian-keep-changes" data-type="theme" data-item="<?php echo esc_attr($slug); ?>"><?php esc_html_e('Accept Changes', 'code-guardian'); ?></button>
                    <?php else : ?>
                        <button type="button" class="button code-guardian-refresh-snapshot" data-type="theme" data-item="<?php echo esc_attr($slug); ?>"><?php esc_html_e('Update Baseline', 'code-guardian'); ?></button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
