jQuery(document).ready(function ($) {
    'use strict';

    console.log('WP Code Guardian Admin JS - Updated version loaded');

    function buildModalShell(id, extraClass) {
        if ($('#' + id).length) {
            return $('#' + id);
        }
        var html = '' +
            '<div id="' + id + '" class="wp-code-guardian-media-modal' + (extraClass ? ' ' + extraClass : '') + '" style="display:none;">' +
                '<div class="media-modal-backdrop"></div>' +
                '<div class="media-modal wp-core-ui">' +
                    '<button type="button" class="media-modal-close"><span class="media-modal-icon"></span><span class="screen-reader-text">Close</span></button>' +
                    '<div class="media-frame-title"><h1></h1></div>' +
                    '<div class="media-frame-content"></div>' +
                    '<div class="media-frame-toolbar"><div class="media-toolbar"><div class="media-toolbar-secondary"><button type="button" class="button wp-code-guardian-modal-cancel">Cancel</button></div><div class="media-toolbar-primary"><button type="button" class="button button-primary wp-code-guardian-modal-proceed">Proceed</button></div></div></div>' +
                '</div>' +
            '</div>';
        var $modal = $(html);
        $('body').append($modal);
        $modal.on('click', '.media-modal-close, .media-modal-backdrop, .wp-code-guardian-modal-cancel', function () {
            hideModal($modal);
        });
        return $modal;
    }

    function showModal($modal, title, content) {
        $modal.find('.media-frame-title h1').text(title);
        $modal.find('.media-frame-content').html(content);
        $modal.show();
        $('body').addClass('modal-open');
    }

    function hideModal($modal) {
        $modal.hide();
        $('body').removeClass('modal-open');
    }

    function showAdminModal(title, message, onProceed) {
        var $modal = buildModalShell('wp-code-guardian-admin-modal');
        $modal.find('.wp-code-guardian-modal-proceed').off('click').on('click', function () {
            hideModal($modal);
            if (typeof onProceed === 'function') { onProceed(); }
        });
        showModal($modal, title, '<p>' + message + '</p>');
    }

    function hideAdminModal() {
        hideModal($('#wp-code-guardian-admin-modal'));
    }

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('.wp-code-guardian-media-modal:visible').each(function () { hideModal($(this)); });
        }
    });

    // View changes
    $(document).on('click', '.wp-code-guardian-view-changes', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var type = $btn.data('type');
        var item = $btn.data('item');
        var $modal = buildModalShell('wp-code-guardian-modal');
        showModal($modal, 'Loading…', '<p>' + wpCodeGuardian.strings.loading + '</p>');
        $.ajax({
            url: wpCodeGuardian.ajax_url,
            type: 'POST',
            data: {
                action: 'wp_code_guardian_get_diff',
                type: type,
                item: item,
                nonce: wpCodeGuardian.nonce
            }
        }).done(function (resp) {
            if (resp && resp.success) {
                showModal($modal, item, resp.data.html);
                initDiffViewers();
            } else {
                $modal.find('.media-frame-content').html('<p>' + (resp && resp.data ? resp.data : wpCodeGuardian.strings.error) + '</p>');
            }
        }).fail(function () {
            $modal.find('.media-frame-content').html('<p>' + wpCodeGuardian.strings.error + '</p>');
        });
    });

    // Refresh / create snapshot
    $(document).on('click', '.wp-code-guardian-refresh-snapshot', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var type = $btn.data('type');
        var item = $btn.data('item');
        var label = ($btn.text() || '').trim();
        var isCreate = label.indexOf('Create Baseline') !== -1;
        var title = isCreate ? 'Create Baseline' : 'Refresh Baseline';
        var msg = isCreate
            ? 'Create baseline by downloading original files from WordPress.org?'
            : wpCodeGuardian.strings.confirm_refresh;
        showAdminModal(title, msg, function () {
            $btn.prop('disabled', true);
            $.ajax({
                url: wpCodeGuardian.ajax_url,
                type: 'POST',
                data: {
                    action: 'wp_code_guardian_refresh_snapshot',
                    type: type,
                    item: item,
                    nonce: wpCodeGuardian.nonce
                }
            }).done(function (resp) {
                if (resp && resp.success) {
                    window.location.reload();
                } else {
                    $btn.prop('disabled', false);
                    alert(resp && resp.data ? resp.data : wpCodeGuardian.strings.error);
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                alert(wpCodeGuardian.strings.error);
            });
        });
    });

    function initDiffViewers() {
        $('.diff-file').each(function (i) {
            var $file = $(this);
            if (i === 0) {
                $file.find('.diff-file-body').show();
                $file.find('.diff-toggle-label').text('Hide Changes');
                $file.find('.diff-toggle .dashicons')
                    .removeClass('dashicons-arrow-down')
                    .addClass('dashicons-arrow-up');
            }
        });

        $('.diff-toggle').off('click').on('click', function () {
            var $file = $(this).closest('.diff-file');
            var $body = $file.find('.diff-file-body');
            $body.slideToggle(150, function () {
                var open = $body.is(':visible');
                $file.find('.diff-toggle-label').text(open ? 'Hide Changes' : 'Show Changes');
                $file.find('.diff-toggle .dashicons')
                    .toggleClass('dashicons-arrow-up', open)
                    .toggleClass('dashicons-arrow-down', !open);
            });
        });

        $('.diff-view-toggle input[type="radio"]').off('change').on('change', function () {
            var $file = $(this).closest('.diff-file');
            var mode  = $(this).val();
            $file.find('.diff-view-unified').toggle(mode === 'unified');
            $file.find('.diff-view-split').toggle(mode === 'split');
        });
    }

    // Scan all
    $(document).on('click', '.wp-code-guardian-scan-all', function (e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.ajax({
            url: wpCodeGuardian.ajax_url,
            type: 'POST',
            data: {
                action: 'wp_code_guardian_scan_all',
                type: $btn.data('type'),
                nonce: wpCodeGuardian.nonce
            }
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.reload();
            } else {
                $btn.prop('disabled', false);
                alert(resp && resp.data ? resp.data : wpCodeGuardian.strings.error);
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            alert(wpCodeGuardian.strings.error);
        });
    });

    $(document).on('click', '.wp-code-guardian-clear-snapshots', function (e) {
        e.preventDefault();
        showAdminModal('Clear Snapshots', 'This will delete all baselines for every plugin and theme. Continue?', function () {
            $.ajax({
                url: wpCodeGuardian.ajax_url,
                type: 'POST',
                data: {
                    action: 'wp_code_guardian_clear_snapshots',
                    nonce: wpCodeGuardian.nonce
                }
            }).done(function (resp) {
                if (resp && resp.success) {
                    window.location.reload();
                } else {
                    alert(resp && resp.data ? resp.data : wpCodeGuardian.strings.error);
                }
            });
        });
    });

    $(document).on('click', '.wp-code-guardian-rescan-all', function (e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.ajax({
            url: wpCodeGuardian.ajax_url,
            type: 'POST',
            data: {
                action: 'wp_code_guardian_rescan_all',
                nonce: wpCodeGuardian.nonce
            }
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.reload();
            } else {
                $btn.prop('disabled', false);
                alert(resp && resp.data ? resp.data : wpCodeGuardian.strings.error);
            }
        });
    });

    // plugins.php: mark preceding row when we injected a changes row.
    $('.wp-code-guardian-changes').each(function () {
        $(this).prev('tr').addClass('wp-code-guardian-has-changes');
    });

    // themes.php: inject Modified notice on cards we flagged.
    $('.theme.wp-code-guardian-has-changes').each(function () {
        var $card = $(this);
        if ($card.find('.wp-code-guardian-theme-notice').length === 0) {
            $card.find('.theme-actions').before(
                '<div class="wp-code-guardian-theme-notice notice notice-warning notice-alt inline">' +
                    '<p><span class="dashicons dashicons-warning"></span> Modified</p>' +
                '</div>'
            );
        }
    });
});
