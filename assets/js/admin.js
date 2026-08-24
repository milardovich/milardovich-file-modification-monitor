jQuery(document).ready(function ($) {
    'use strict';


    function buildModalShell(id, extraClass) {
        if ($('#' + id).length) {
            return $('#' + id);
        }
        var html = '' +
            '<div id="' + id + '" class="milardovich-fmm-media-modal' + (extraClass ? ' ' + extraClass : '') + '">' +
                '<div class="media-modal-backdrop"></div>' +
                '<div class="media-modal wp-core-ui">' +
                    '<button type="button" class="media-modal-close"><span class="media-modal-icon"></span><span class="screen-reader-text">Close</span></button>' +
                    '<div class="media-frame-title"><h1></h1></div>' +
                    '<div class="media-frame-content"></div>' +
                    '<div class="media-frame-toolbar"><div class="media-toolbar"><div class="media-toolbar-secondary"><button type="button" class="button milardovich-fmm-modal-cancel">Cancel</button></div><div class="media-toolbar-primary"><button type="button" class="button button-primary milardovich-fmm-modal-proceed">Proceed</button></div></div></div>' +
                '</div>' +
            '</div>';
        var $modal = $(html);
        $('body').append($modal);
        $modal.on('click', '.media-modal-close, .media-modal-backdrop, .milardovich-fmm-modal-cancel', function () {
            hideModal($modal);
        });
        return $modal;
    }

    // Visibility rides on a class rather than .show()/.hide(): those write an
    // inline display:block, which would beat the stylesheet's flex centring.
    function showModal($modal, title, content) {
        $modal.find('.media-frame-title h1').text(title);
        $modal.find('.media-frame-content').html(content);
        $modal.addClass('is-open');
        $('body').addClass('modal-open');
    }

    function hideModal($modal) {
        $modal.removeClass('is-open');
        $('body').removeClass('modal-open');
    }

    function showAdminModal(title, message, onProceed) {
        var $modal = buildModalShell('milardovich-fmm-admin-modal');
        $modal.find('.milardovich-fmm-modal-proceed').off('click').on('click', function () {
            hideModal($modal);
            if (typeof onProceed === 'function') { onProceed(); }
        });
        showModal($modal, title, '<p>' + message + '</p>');
    }

    function hideAdminModal() {
        hideModal($('#milardovich-fmm-admin-modal'));
    }

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('.milardovich-fmm-media-modal:visible').each(function () { hideModal($(this)); });
            closeHelpTips();
        }
    });

    // Help tips. CSS already handles hover and keyboard focus; clicking pins
    // one open, which is the only way a touch device can read it.
    function closeHelpTips() {
        $('.milardovich-fmm-tip.is-open')
            .removeClass('is-open')
            .find('.milardovich-fmm-tip-toggle')
            .attr('aria-expanded', 'false');
    }

    $(document).on('click', '.milardovich-fmm-tip-toggle', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $toggle = $(this);
        var $tip    = $toggle.closest('.milardovich-fmm-tip');
        var wasOpen = $tip.hasClass('is-open');
        closeHelpTips();
        if (!wasOpen) {
            $tip.addClass('is-open');
            $toggle.attr('aria-expanded', 'true');
        }
    });

    $(document).on('click', function () {
        closeHelpTips();
    });

    // View changes
    $(document).on('click', '.milardovich-fmm-view-changes', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var type = $btn.data('type');
        var item = $btn.data('item');
        var $modal = buildModalShell('milardovich-fmm-modal');
        showModal($modal, 'Loading…', '<p>' + milardovichFMM.strings.loading + '</p>');
        $.ajax({
            url: milardovichFMM.ajax_url,
            type: 'POST',
            data: {
                action: 'milardovich_fmm_get_diff',
                type: type,
                item: item,
                nonce: milardovichFMM.nonce
            }
        }).done(function (resp) {
            if (resp && resp.success) {
                showModal($modal, item, resp.data.html);
                initDiffViewers();
                setDiffActions($modal, type, item, (resp.data.changes || []).length);
            } else {
                $modal.find('.media-frame-content').html('<p>' + (resp && resp.data ? resp.data : milardovichFMM.strings.error) + '</p>');
            }
        }).fail(function () {
            $modal.find('.media-frame-content').html('<p>' + milardovichFMM.strings.error + '</p>');
        });
    });

    // The diff modal's footer offers the two ways out of a detected change:
    // adopt the edits as the new baseline, or put the original files back.
    function setDiffActions($modal, type, item, count) {
        var $primary = $modal.find('.media-toolbar-primary').empty();
        $modal.find('.milardovich-fmm-modal-cancel').text(milardovichFMM.strings.close);
        if (!count) {
            return;
        }
        $primary.append(
            $('<button type="button" class="button milardovich-fmm-keep-changes">')
                .text(milardovichFMM.strings.keep_changes)
                .attr({ 'data-type': type, 'data-item': item }),
            $('<button type="button" class="button button-primary milardovich-fmm-restore-original">')
                .text(milardovichFMM.strings.restore_original)
                .attr({ 'data-type': type, 'data-item': item, 'data-count': count })
        );
    }

    function runItemAction(action, type, item, $btn) {
        var $toolbar = $btn.closest('.media-toolbar');
        $toolbar.find('button').prop('disabled', true);
        $btn.text(milardovichFMM.strings.working);
        $.post(milardovichFMM.ajax_url, {
            action: action,
            type: type,
            item: item,
            nonce: milardovichFMM.nonce
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.reload();
                return;
            }
            $toolbar.find('button').prop('disabled', false);
            alert(resp && resp.data ? resp.data : milardovichFMM.strings.error);
        }).fail(function () {
            $toolbar.find('button').prop('disabled', false);
            alert(milardovichFMM.strings.error);
        });
    }

    $(document).on('click', '.milardovich-fmm-keep-changes', function (e) {
        e.preventDefault();
        var $btn = $(this);
        showAdminModal(
            milardovichFMM.strings.keep_title,
            milardovichFMM.strings.keep_confirm,
            function () {
                runItemAction(
                    'milardovich_fmm_accept_changes',
                    $btn.data('type'),
                    $btn.data('item'),
                    $btn
                );
            }
        );
    });

    $(document).on('click', '.milardovich-fmm-restore-original', function (e) {
        e.preventDefault();
        var $btn = $(this);
        showAdminModal(
            milardovichFMM.strings.restore_title,
            milardovichFMM.strings.restore_confirm.replace('%d', $btn.data('count')),
            function () {
                runItemAction(
                    'milardovich_fmm_restore_original',
                    $btn.data('type'),
                    $btn.data('item'),
                    $btn
                );
            }
        );
    });

    // Refresh / create snapshot
    $(document).on('click', '.milardovich-fmm-refresh-snapshot', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var type = $btn.data('type');
        var item = $btn.data('item');
        var label = ($btn.text() || '').trim();
        var isCreate = label.indexOf('Create Baseline') !== -1;
        var title = isCreate ? 'Create Baseline' : 'Refresh Baseline';
        var msg = isCreate
            ? 'Create baseline by downloading original files from WordPress.org?'
            : milardovichFMM.strings.confirm_refresh;
        showAdminModal(title, msg, function () {
            $btn.prop('disabled', true);
            $.ajax({
                url: milardovichFMM.ajax_url,
                type: 'POST',
                data: {
                    action: 'milardovich_fmm_refresh_snapshot',
                    type: type,
                    item: item,
                    nonce: milardovichFMM.nonce
                }
            }).done(function (resp) {
                if (resp && resp.success) {
                    window.location.reload();
                } else {
                    $btn.prop('disabled', false);
                    alert(resp && resp.data ? resp.data : milardovichFMM.strings.error);
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                alert(milardovichFMM.strings.error);
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
    // Batch scan, one item per request. Walking the queue client-side is what
    // makes a real progress bar possible: a single request for the whole set
    // gives nothing to report until it is over, and risks the PHP time limit.
    $(document).on('click', '.milardovich-fmm-scan-all', function (e) {
        e.preventDefault();

        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        $btn.prop('disabled', true);

        var $box = $btn.siblings('.milardovich-fmm-progress');
        if (!$box.length) {
            $box = $(
                '<div class="milardovich-fmm-progress" role="status" aria-live="polite">' +
                    '<div class="milardovich-fmm-progress-track">' +
                        '<span class="milardovich-fmm-progress-fill"></span>' +
                    '</div>' +
                    '<p class="milardovich-fmm-progress-label"></p>' +
                '</div>'
            );
            $btn.after($box);
        }
        var $fill  = $box.find('.milardovich-fmm-progress-fill');
        var $label = $box.find('.milardovich-fmm-progress-label');
        $box.removeClass('has-error').show();

        function paint(done, total, text) {
            var pct = total ? Math.round((done / total) * 100) : 0;
            $fill.css('width', pct + '%');
            $label.text(total ? text + ' (' + done + '/' + total + ')' : text);
        }

        function abort(message) {
            $btn.prop('disabled', false);
            $box.addClass('has-error');
            $fill.css('width', '0%');
            $label.text(message || milardovichFMM.strings.error);
        }

        paint(0, 0, milardovichFMM.strings.scan_preparing);

        $.post(milardovichFMM.ajax_url, {
            action: 'milardovich_fmm_scan_queue',
            type: $btn.data('type'),
            nonce: milardovichFMM.nonce
        }).done(function (resp) {
            if (!resp || !resp.success || !resp.data) {
                abort(resp && resp.data ? resp.data : null);
                return;
            }

            var queue  = resp.data.items || [];
            var total  = queue.length;
            var done   = 0;
            var failed = 0;

            function finish() {
                paint(total, total, milardovichFMM.strings.scan_comparing);
                $.post(milardovichFMM.ajax_url, {
                    action: 'milardovich_fmm_scan_finish',
                    nonce: milardovichFMM.nonce
                }).always(function () {
                    if (failed) {
                        // Leave the page up so the failures stay readable.
                        abort(milardovichFMM.strings.scan_failed.replace('%d', failed));
                        return;
                    }
                    $label.text(milardovichFMM.strings.scan_done);
                    window.location.reload();
                });
            }

            function step() {
                if (!queue.length) {
                    finish();
                    return;
                }
                var current = queue.shift();
                paint(done, total, milardovichFMM.strings.scan_building + ' ' + current.label);
                $.post(milardovichFMM.ajax_url, {
                    action: 'milardovich_fmm_scan_item',
                    type: current.type,
                    item: current.item,
                    nonce: milardovichFMM.nonce
                }).done(function (r) {
                    if (!r || !r.success) {
                        failed++;
                    }
                }).fail(function () {
                    failed++;
                }).always(function () {
                    done++;
                    paint(done, total, milardovichFMM.strings.scan_building + ' ' + current.label);
                    step();
                });
            }

            if (!total) {
                finish();
                return;
            }
            step();
        }).fail(function () {
            abort();
        });
    });

    $(document).on('click', '.milardovich-fmm-clear-snapshots', function (e) {
        e.preventDefault();
        showAdminModal('Clear Snapshots', 'This will delete all baselines for every plugin and theme. Continue?', function () {
            $.ajax({
                url: milardovichFMM.ajax_url,
                type: 'POST',
                data: {
                    action: 'milardovich_fmm_clear_snapshots',
                    nonce: milardovichFMM.nonce
                }
            }).done(function (resp) {
                if (resp && resp.success) {
                    window.location.reload();
                } else {
                    alert(resp && resp.data ? resp.data : milardovichFMM.strings.error);
                }
            });
        });
    });

    $(document).on('click', '.milardovich-fmm-rescan-all', function (e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.ajax({
            url: milardovichFMM.ajax_url,
            type: 'POST',
            data: {
                action: 'milardovich_fmm_rescan_all',
                nonce: milardovichFMM.nonce
            }
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.reload();
            } else {
                $btn.prop('disabled', false);
                alert(resp && resp.data ? resp.data : milardovichFMM.strings.error);
            }
        });
    });

    // plugins.php: mark preceding row when we injected a changes row.
    $('.milardovich-fmm-changes').each(function () {
        $(this).prev('tr').addClass('milardovich-fmm-has-changes');
    });

    // themes.php: inject Modified notice on cards we flagged.
    $('.theme.milardovich-fmm-has-changes').each(function () {
        var $card = $(this);
        if ($card.find('.milardovich-fmm-theme-notice').length === 0) {
            $card.find('.theme-actions').before(
                '<div class="milardovich-fmm-theme-notice notice notice-warning notice-alt inline">' +
                    '<p><span class="dashicons dashicons-warning"></span> Modified</p>' +
                '</div>'
            );
        }
    });

    // Run the change scan out of band. The page is already rendered by the
    // time this fires, so comparing files never delays anything on screen.
    // This also covers installs where WP-Cron cannot spawn its own request.
    if (window.milardovichFMM && milardovichFMM.scan_pending) {
        $.post(milardovichFMM.ajax_url, {
            action: 'milardovich_fmm_run_scan',
            nonce: milardovichFMM.nonce,
            signature: milardovichFMM.scan_signature
        }, function (response) {
            if (!response || !response.success || !response.data || !response.data.updated) {
                return;
            }
            var $target = $('.wrap').first();
            if (!$target.length) {
                return;
            }
            $target.prepend(
                '<div class="notice notice-info is-dismissible"><p>' +
                    milardovichFMM.strings.scan_updated + ' ' +
                    '<a href="#" class="milardovich-fmm-reload">' +
                        milardovichFMM.strings.reload +
                    '</a>' +
                '</p></div>'
            );
        });
    }

    $(document).on('click', '.milardovich-fmm-reload', function (e) {
        e.preventDefault();
        window.location.reload();
    });

    // themes.php is rendered by WordPress itself, so the "Modified" badge has
    // to be added client-side. The slugs come from the localized data, not from
    // an inline script printed into the footer.
    function applyThemeBadges() {
        var slugs = (window.milardovichFMM && milardovichFMM.modified_themes) || [];
        if (!slugs.length) {
            return;
        }
        var label = milardovichFMM.strings.modified_badge;
        slugs.forEach(function (slug) {
            var $card = $('.theme[data-slug="' + slug + '"]');
            if (!$card.length) {
                return;
            }
            $card.addClass('milardovich-fmm-has-changes');
            if ($card.find('.milardovich-fmm-badge').length === 0) {
                $card.find('.theme-name').append(
                    ' ',
                    $('<span class="milardovich-fmm-badge"></span>').text(label)
                );
            }
        });
    }

    applyThemeBadges();

    $(document).on('click', '.milardovich-fmm-dismiss-welcome', function (e) {
        e.preventDefault();
        var $btn = $(this);
        $.post(milardovichFMM.ajax_url, {
            action: 'milardovich_fmm_dismiss_welcome',
            nonce: $btn.data('nonce')
        }, function () {
            $btn.closest('.milardovich-fmm-welcome').fadeOut();
        });
    });
});
