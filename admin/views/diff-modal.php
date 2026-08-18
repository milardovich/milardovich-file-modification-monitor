<?php
if (!defined('ABSPATH')) { exit; }

/** @var array $diff_changes */
/** @var string $diff_item */
/** @var string $diff_type */

$diff_generator = code_guardian()->diff_generator ?? null;

$total_additions = 0;
$total_deletions = 0;
foreach ($diff_changes as $change) {
    if ($diff_generator) {
        $stats = $diff_generator->get_stats($change['diff']);
        $total_additions += (int) $stats['additions'];
        $total_deletions += (int) $stats['deletions'];
    }
}
$files_changed = count($diff_changes);
?>
<div class="diff-header">
    <h3><?php echo esc_html($diff_item); ?></h3>
    <div class="diff-summary">
        <?php
        printf(
            /* translators: 1: number of files changed, 2: number of added lines, 3: number of removed lines. */
            esc_html(_n('%1$d file changed, %2$d addition(+), %3$d deletion(-)', '%1$d files changed, %2$d additions(+), %3$d deletions(-)', $files_changed, 'code-guardian')),
            (int) $files_changed,
            (int) $total_additions,
            (int) $total_deletions
        );
        ?>
    </div>
</div>

<div class="diff-files">
<?php foreach ($diff_changes as $index => $change) :
    $is_new     = !empty($change['is_new']);
    $is_deleted = !empty($change['is_deleted']);
    $badge_class = $is_new ? 'new' : ($is_deleted ? 'deleted' : 'modified');
    $badge_label = $is_new ? __('New', 'code-guardian') : ($is_deleted ? __('Deleted', 'code-guardian') : __('Modified', 'code-guardian'));
    $lines = $diff_generator ? $diff_generator->format_for_html($change['diff']) : [];
    $uid   = 'diff-' . $index . '-' . wp_generate_password(6, false);
?>
    <div class="diff-file" data-uid="<?php echo esc_attr($uid); ?>">
        <div class="diff-file-header">
            <span class="filename"><?php echo esc_html($change['file']); ?></span>
            <span class="badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_label); ?></span>
            <button type="button" class="button-link diff-toggle">
                <span class="dashicons dashicons-arrow-down"></span>
                <span class="diff-toggle-label"><?php esc_html_e('Show Changes', 'code-guardian'); ?></span>
            </button>
            <span class="diff-view-toggle">
                <label><input type="radio" name="view-<?php echo esc_attr($uid); ?>" value="unified" checked /> <?php esc_html_e('Unified', 'code-guardian'); ?></label>
                <label><input type="radio" name="view-<?php echo esc_attr($uid); ?>" value="split" /> <?php esc_html_e('Split', 'code-guardian'); ?></label>
            </span>
        </div>
        <div class="diff-file-body" style="display:none;">
            <div class="diff-view diff-view-unified">
                <?php foreach ($lines as $l) : ?>
                    <div class="diff-line <?php echo esc_attr($l['class']); ?>"><?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- DiffGenerator::format_for_html() already ran htmlspecialchars() over this; escaping again would render the entities.
                        echo $l['line'];
                    ?></div>
                <?php endforeach; ?>
            </div>
            <div class="diff-view diff-view-split" style="display:none;">
                <div class="diff-split-col">
                    <div class="diff-split-header"><?php esc_html_e('Original', 'code-guardian'); ?></div>
                    <pre><?php echo esc_html($change['old_content']); ?></pre>
                </div>
                <div class="diff-split-col">
                    <div class="diff-split-header"><?php esc_html_e('Modified', 'code-guardian'); ?></div>
                    <pre><?php echo esc_html($change['new_content']); ?></pre>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
