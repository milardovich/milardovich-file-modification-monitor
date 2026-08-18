<?php
if (!defined('ABSPATH')) { exit; }

$frequency        = get_option('wp_code_guardian_scan_frequency', 'daily');
$email_notif      = (int) get_option('wp_code_guardian_email_notifications', 0);
$notif_email      = get_option('wp_code_guardian_notification_email', get_option('admin_email'));
$ignored_files    = get_option('wp_code_guardian_ignored_files', "*.log\n*.tmp\n.DS_Store\nThumbs.db");
$show_warnings    = (int) get_option('wp_code_guardian_show_warnings', 1);
$frequencies      = [
    'disabled'   => __('Disabled', 'wp-code-guardian'),
    'hourly'     => __('Hourly', 'wp-code-guardian'),
    'twicedaily' => __('Twice Daily', 'wp-code-guardian'),
    'daily'      => __('Daily', 'wp-code-guardian'),
    'weekly'     => __('Weekly', 'wp-code-guardian'),
];
?>
<div class="wrap wp-code-guardian-wrap">
    <h1><?php esc_html_e('WP Code Guardian Settings', 'wp-code-guardian'); ?></h1>
    <form method="post" action="options.php">
        <?php settings_fields('wp_code_guardian_settings'); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="wp_code_guardian_scan_frequency"><?php esc_html_e('Scan Frequency', 'wp-code-guardian'); ?></label></th>
                <td>
                    <select name="wp_code_guardian_scan_frequency" id="wp_code_guardian_scan_frequency">
                        <?php foreach ($frequencies as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($frequency, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('How often to check for code changes on admin page loads.', 'wp-code-guardian'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Email Notifications', 'wp-code-guardian'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wp_code_guardian_email_notifications" value="1" <?php checked($email_notif, 1); ?> />
                        <?php esc_html_e('Send email when code changes are detected', 'wp-code-guardian'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wp_code_guardian_notification_email"><?php esc_html_e('Notification Email', 'wp-code-guardian'); ?></label></th>
                <td>
                    <input type="email" name="wp_code_guardian_notification_email" id="wp_code_guardian_notification_email" value="<?php echo esc_attr($notif_email); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wp_code_guardian_ignored_files"><?php esc_html_e('Ignored Files', 'wp-code-guardian'); ?></label></th>
                <td>
                    <textarea name="wp_code_guardian_ignored_files" id="wp_code_guardian_ignored_files" rows="5" cols="50" class="large-text"><?php echo esc_textarea($ignored_files); ?></textarea>
                    <p class="description"><?php esc_html_e('One pattern per line.', 'wp-code-guardian'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Show Warnings', 'wp-code-guardian'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wp_code_guardian_show_warnings" value="1" <?php checked($show_warnings, 1); ?> />
                        <?php esc_html_e('Show update warnings on plugins/themes/update screens', 'wp-code-guardian'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>

    <hr />

    <h2><?php esc_html_e('Maintenance', 'wp-code-guardian'); ?></h2>
    <p>
        <button type="button" class="button wp-code-guardian-clear-snapshots"><?php esc_html_e('Clear All Snapshots', 'wp-code-guardian'); ?></button>
        <button type="button" class="button wp-code-guardian-rescan-all"><?php esc_html_e('Rescan All Plugins &amp; Themes', 'wp-code-guardian'); ?></button>
    </p>
</div>
