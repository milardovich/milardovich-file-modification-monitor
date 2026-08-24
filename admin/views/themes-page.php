<?php
if (!defined('ABSPATH')) { exit; }

// This template is included from inside a method of AdminManager, so the
// variables below are local to that method, not globals. PHPCS cannot see
// the include site and reports them as unprefixed globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$theme_scanner   = milardovich_fmm()->theme_scanner ?? null;
$admin_manager   = milardovich_fmm()->admin_manager ?? null;
$themes          = wp_get_themes();
$active_template = get_stylesheet();
?>
<div class="wrap milardovich-fmm-wrap">
    <h1><?php esc_html_e('Theme Changes', 'milardovich-file-modification-monitor'); ?></h1>
    <p>
        <button type="button" class="button button-primary milardovich-fmm-scan-all" data-type="themes"><?php esc_html_e('Scan All Themes', 'milardovich-file-modification-monitor'); ?></button>
    </p>
    <table class="wp-list-table widefat fixed striped milardovich-fmm-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Theme', 'milardovich-file-modification-monitor'); ?></th>
                <th>
                    <?php esc_html_e('Status', 'milardovich-file-modification-monitor'); ?>
                    <?php if ($admin_manager) { $admin_manager->render_help_tip($admin_manager->status_help_text(), __('What do these statuses mean?', 'milardovich-file-modification-monitor')); } ?>
                </th>
                <th><?php esc_html_e('Changes', 'milardovich-file-modification-monitor'); ?></th>
                <th><?php esc_html_e('Actions', 'milardovich-file-modification-monitor'); ?></th>
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
                $version_label .= ' | ' . __('Active', 'milardovich-file-modification-monitor');
            }
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($theme->get('Name')); ?></strong>
                    <div class="row-actions"><span><?php echo esc_html($version_label); ?></span></div>
                </td>
                <td>
                    <?php if (!$has_baseline) : ?>
                        <span class="milardovich-fmm-status no-baseline"><span class="dashicons dashicons-info"></span> <?php esc_html_e('No Baseline', 'milardovich-file-modification-monitor'); ?></span>
                    <?php elseif ($is_modified) : ?>
                        <span class="milardovich-fmm-status modified"><span class="dashicons dashicons-warning"></span> <?php esc_html_e('Modified', 'milardovich-file-modification-monitor'); ?></span>
                    <?php else : ?>
                        <span class="milardovich-fmm-status clean"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Clean', 'milardovich-file-modification-monitor'); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$has_baseline) : ?>
                        <?php esc_html_e('Create baseline first', 'milardovich-file-modification-monitor'); ?>
                    <?php elseif ($is_modified) : ?>
                        <?php /* translators: %d: number of changed files. */ ?>
                        <?php echo esc_html(sprintf(_n('%d file changed', '%d files changed', $change_count, 'milardovich-file-modification-monitor'), $change_count)); ?>
                    <?php else : ?>
                        <?php esc_html_e('No changes', 'milardovich-file-modification-monitor'); ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$has_baseline) : ?>
                        <button type="button" class="button button-primary milardovich-fmm-refresh-snapshot" data-type="theme" data-item="<?php echo esc_attr($slug); ?>"><?php esc_html_e('Create Baseline', 'milardovich-file-modification-monitor'); ?></button>
                    <?php elseif ($is_modified) : ?>
                        <button type="button" class="button milardovich-fmm-view-changes" data-type="theme" data-item="<?php echo esc_attr($slug); ?>"><?php esc_html_e('View Changes', 'milardovich-file-modification-monitor'); ?></button>
                        <button type="button" class="button milardovich-fmm-keep-changes" data-type="theme" data-item="<?php echo esc_attr($slug); ?>"><?php esc_html_e('Accept Changes', 'milardovich-file-modification-monitor'); ?></button>
                    <?php else : ?>
                        <button type="button" class="button milardovich-fmm-refresh-snapshot" data-type="theme" data-item="<?php echo esc_attr($slug); ?>"><?php esc_html_e('Update Baseline', 'milardovich-file-modification-monitor'); ?></button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
