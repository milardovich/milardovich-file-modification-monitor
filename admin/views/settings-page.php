<?php
if (!defined('ABSPATH')) { exit; }

$frequency        = get_option('milardovich_fmm_scan_frequency', 'daily');
$email_notif      = (int) get_option('milardovich_fmm_email_notifications', 0);
$notif_email      = get_option('milardovich_fmm_notification_email', get_option('admin_email'));
$ignored_files    = get_option('milardovich_fmm_ignored_files', "*.log\n*.tmp\n.DS_Store\nThumbs.db");
$show_warnings    = (int) get_option('milardovich_fmm_show_warnings', 1);
$frequencies      = [
    'disabled'   => __('Disabled', 'milardovich-file-modification-monitor'),
    'hourly'     => __('Hourly', 'milardovich-file-modification-monitor'),
    'twicedaily' => __('Twice Daily', 'milardovich-file-modification-monitor'),
    'daily'      => __('Daily', 'milardovich-file-modification-monitor'),
    'weekly'     => __('Weekly', 'milardovich-file-modification-monitor'),
];
?>
<div class="wrap milardovich-fmm-wrap">
    <h1><?php esc_html_e('Milardovich File Modification Monitor Settings', 'milardovich-file-modification-monitor'); ?></h1>
    <form method="post" action="options.php">
        <?php settings_fields('milardovich_fmm_settings'); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="milardovich_fmm_scan_frequency"><?php esc_html_e('Scan Frequency', 'milardovich-file-modification-monitor'); ?></label></th>
                <td>
                    <select name="milardovich_fmm_scan_frequency" id="milardovich_fmm_scan_frequency">
                        <?php foreach ($frequencies as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($frequency, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('How often to check for code changes on admin page loads.', 'milardovich-file-modification-monitor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Email Notifications', 'milardovich-file-modification-monitor'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="milardovich_fmm_email_notifications" value="1" <?php checked($email_notif, 1); ?> />
                        <?php esc_html_e('Send email when code changes are detected', 'milardovich-file-modification-monitor'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="milardovich_fmm_notification_email"><?php esc_html_e('Notification Email', 'milardovich-file-modification-monitor'); ?></label></th>
                <td>
                    <input type="email" name="milardovich_fmm_notification_email" id="milardovich_fmm_notification_email" value="<?php echo esc_attr($notif_email); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="milardovich_fmm_ignored_files"><?php esc_html_e('Ignored Files', 'milardovich-file-modification-monitor'); ?></label></th>
                <td>
                    <textarea name="milardovich_fmm_ignored_files" id="milardovich_fmm_ignored_files" rows="5" cols="50" class="large-text"><?php echo esc_textarea($ignored_files); ?></textarea>
                    <p class="description"><?php esc_html_e('One pattern per line.', 'milardovich-file-modification-monitor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Show Warnings', 'milardovich-file-modification-monitor'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="milardovich_fmm_show_warnings" value="1" <?php checked($show_warnings, 1); ?> />
                        <?php esc_html_e('Show update warnings on plugins/themes/update screens', 'milardovich-file-modification-monitor'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>

    <hr />

    <h2><?php esc_html_e('Maintenance', 'milardovich-file-modification-monitor'); ?></h2>
    <p>
        <button type="button" class="button milardovich-fmm-clear-snapshots"><?php esc_html_e('Clear All Snapshots', 'milardovich-file-modification-monitor'); ?></button>
        <button type="button" class="button milardovich-fmm-rescan-all"><?php esc_html_e('Rescan All Plugins &amp; Themes', 'milardovich-file-modification-monitor'); ?></button>
    </p>
</div>
