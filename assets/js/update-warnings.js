jQuery(document).ready(function ($) {
    'use strict';

    if (typeof milardovichFMMWarnings === 'undefined') {
        return;
    }

    var pluginsWithChanges = milardovichFMMWarnings.plugins_with_changes || [];
    var themesWithChanges  = milardovichFMMWarnings.themes_with_changes || [];
    var warningMessage     = milardovichFMMWarnings.warning_message || '⚠️ Code Changes Detected!';

    function buildWarningModal() {
        if ($('#milardovich-fmm-modal').length) {
            return $('#milardovich-fmm-modal');
        }
        var html = '' +
            '<div id="milardovich-fmm-modal" class="milardovich-fmm-media-modal update-warning" style="display:none;">' +
                '<div class="media-modal-backdrop"></div>' +
                '<div class="media-modal wp-core-ui">' +
                    '<button type="button" class="media-modal-close"><span class="media-modal-icon"></span><span class="screen-reader-text">Close</span></button>' +
                    '<div class="media-frame-title"><h1>⚠️ Code Changes Detected</h1></div>' +
                    '<div class="media-frame-content">' +
                        '<div class="notice notice-warning"><p>' + warningMessage + '</p></div>' +
                        '<p class="milardovich-fmm-warning-details"></p>' +
                    '</div>' +
                    '<div class="media-frame-toolbar"><div class="media-toolbar">' +
                        '<div class="media-toolbar-secondary"><button type="button" class="button milardovich-fmm-cancel">Cancel</button></div>' +
                        '<div class="media-toolbar-primary"><button type="button" class="button button-primary milardovich-fmm-proceed">Proceed with Update</button></div>' +
                    '</div></div>' +
                '</div>' +
            '</div>';
        var $modal = $(html);
        $('body').append($modal);
        $modal.on('click', '.media-modal-close, .media-modal-backdrop, .milardovich-fmm-cancel', function () {
            hideWarningModal();
        });
        return $modal;
    }

    function showWarningModal(message, onProceed) {
        var $modal = buildWarningModal();
        $modal.find('.milardovich-fmm-warning-details').html(message);
        $modal.find('.milardovich-fmm-proceed').off('click').on('click', function () {
            hideWarningModal();
            if (typeof onProceed === 'function') { onProceed(); }
        });
        $modal.show();
        $('body').addClass('modal-open');
    }

    function hideWarningModal() {
        $('#milardovich-fmm-modal').hide();
        $('body').removeClass('modal-open');
    }

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            hideWarningModal();
        }
    });

    function getQueryParam(url, name) {
        try {
            var u = new URL(url, window.location.origin);
            return u.searchParams.get(name);
        } catch (e) {
            var m = url.match(new RegExp('[?&]' + name + '=([^&]+)'));
            return m ? decodeURIComponent(m[1]) : null;
        }
    }

    // Single plugin update
    $(document).on('click', 'a[href*="action=upgrade-plugin"]', function (e) {
        var href = $(this).attr('href') || '';
        var plugin = getQueryParam(href, 'plugin');
        if (plugin && pluginsWithChanges.indexOf(plugin) !== -1) {
            e.preventDefault();
            showWarningModal('Plugin: <strong>' + plugin + '</strong>', function () {
                window.location.href = href;
            });
        }
    });

    // Single theme update
    $(document).on('click', 'a[href*="action=upgrade-theme"]', function (e) {
        var href = $(this).attr('href') || '';
        var slug = getQueryParam(href, 'theme');
        if (slug && themesWithChanges.indexOf(slug) !== -1) {
            e.preventDefault();
            showWarningModal('Theme: <strong>' + slug + '</strong>', function () {
                window.location.href = href;
            });
        }
    });

    // Bulk update form
    $(document).on('submit', 'form[action="update-core.php"]', function (e) {
        var $form = $(this);
        var warnings = [];
        $form.find('input[name="checked[]"]:checked').each(function () {
            var v = $(this).val();
            if (pluginsWithChanges.indexOf(v) !== -1) { warnings.push('Plugin: ' + v); }
            if (themesWithChanges.indexOf(v) !== -1) { warnings.push('Theme: ' + v); }
        });
        if (warnings.length) {
            e.preventDefault();
            showWarningModal(warnings.map(function (w) { return '<div>' + w + '</div>'; }).join(''), function () {
                $form.off('submit').submit();
            });
        }
    });

    // Delegation for inline update links
    $(document).on('click', '.update-link, .plugin-update-tr .update-link', function (e) {
        var href = $(this).attr('href') || '';
        var plugin = getQueryParam(href, 'plugin');
        if (!plugin) {
            var $row = $(this).closest('.plugin-update-tr');
            plugin = $row.data('plugin');
        }
        if (plugin && pluginsWithChanges.indexOf(plugin) !== -1) {
            e.preventDefault();
            showWarningModal('Plugin: <strong>' + plugin + '</strong>', function () {
                if (href) { window.location.href = href; }
            });
        }
    });
});
