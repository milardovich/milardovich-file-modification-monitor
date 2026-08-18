<?php
if (!defined('ABSPATH')) { exit; }

$theme_scanner   = wp_code_guardian()->theme_scanner ?? null;
$admin_manager   = wp_code_guardian()->admin_manager ?? null;
$themes          = wp_get_themes();
$active_template = get_stylesheet();
?>
<div class="wrap wp-code-guardian-wrap">
    <h1><?php esc_html_e('Theme Changes', 'wp-code-guardian'); ?></h1>
    <p>
        <button type="button" class="button button-primary wp-code-guardian-scan-all" data-type="themes"><?php esc_html_e('Scan All Themes', 'wp-code-guardian'); ?></button>
    </p>
    <table class="wp-list-table widefat fixed striped wp-code-guardian-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Theme', 'wp-code-guardian'); ?></th>
                <th>
                    <?php esc_html_e('Status', 'wp-code-guardian'); ?>
                    <?php echo $admin_manager ? $admin_manager->help_tip($admin_manager->status_help_text(), __('What do these statuses mean?', 'wp-code-guardian')) : ''; ?>
                </th>
                <th><?php esc_html_e('Changes', 'wp-code-guardian'); ?></th>
                <th><?php esc_html_e('Actions', 'wp-code-guardian'); ?></th>
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
                $version_label .= ' | ' . __('Active', 'wp-code-guardian');
            }
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($theme->get('Name')); ?></strong>
                    <div class="row-actions"><span><?php echo esc_html($version_label); ?></span></div>
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
                        <button type="button" class="button button-primary wp-code-guardian-refresh-snapshot" data-type="theme" data-item="<?php echo esc_attr($slug); ?>"><?php esc_html_e('Create Baseline', 'wp-code-guardian'); ?></button>
                    <?php elseif ($is_modified) : ?>
                        <button type="button" class="button wp-code-guardian-view-changes" data-type="theme" data-item="<?php echo esc_attr($slug); ?>"><?php esc_html_e('View Changes', 'wp-code-guardian'); ?></button>
                        <button type="button" class="button wp-code-guardian-keep-changes" data-type="theme" data-item="<?php echo esc_attr($slug); ?>"><?php esc_html_e('Accept Changes', 'wp-code-guardian'); ?></button>
                    <?php else : ?>
                        <button type="button" class="button wp-code-guardian-refresh-snapshot" data-type="theme" data-item="<?php echo esc_attr($slug); ?>"><?php esc_html_e('Update Baseline', 'wp-code-guardian'); ?></button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
