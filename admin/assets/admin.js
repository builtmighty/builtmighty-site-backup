(function ($) {
    'use strict';

    var pollTimer = null;
    var pollInterval = 3000; // Poll every 3 seconds.
    var pollStartTime = null;
    var pollMaxDuration = 6 * 60 * 60 * 1000; // 6 hours.

    // Test Connection button.
    $('#bm-test-connection').on('click', function () {
        var $btn = $(this);
        var $result = $('#bm-test-result');

        $btn.prop('disabled', true);
        $result.removeClass('success error').addClass('loading').text('Testing...');

        $.post(bmBackup.ajaxUrl, {
            action: 'bm_backup_test_connection',
            nonce: bmBackup.nonce,
        })
        .done(function (response) {
            if (response.success) {
                $result.removeClass('loading').addClass('success').text(response.data);
            } else {
                $result.removeClass('loading').addClass('error').text(response.data);
            }
        })
        .fail(function () {
            $result.removeClass('loading').addClass('error').text('Request failed.');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    // Cancel Backup button.
    $('#bm-cancel-backup').on('click', function () {
        var $btn = $(this);

        if (!confirm('Cancel the running backup?')) {
            return;
        }

        $btn.prop('disabled', true);

        $.post(bmBackup.ajaxUrl, {
            action: 'bm_backup_cancel',
            nonce: bmBackup.nonce,
        })
        .done(function (response) {
            stopPolling();
            hideProgress();
            $btn.hide();
            $('#bm-run-backup').removeClass('running').prop('disabled', false);
            if (response.success) {
                showResult('success', response.data.message || 'Backup cancelled.');
            } else {
                showResult('error', response.data || 'Could not cancel backup.');
            }
        })
        .fail(function () {
            showResult('error', 'Cancel request failed.');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    // Run Backup Now button — schedules and starts polling.
    $('#bm-run-backup').on('click', function () {
        var $btn = $(this);

        if (!confirm('Start a full backup now? This runs in the background.')) {
            return;
        }

        $btn.addClass('running').prop('disabled', true);
        showResult('loading', '');
        showProgress('Scheduling backup...');

        $.post(bmBackup.ajaxUrl, {
            action: 'bm_backup_run_now',
            nonce: bmBackup.nonce,
        })
        .done(function (response) {
            if (response.success) {
                showProgress('Backup scheduled. Waiting for first step...');
                setTimeout(startPolling, 1000);
            } else {
                hideProgress();
                showResult('error', response.data);
                $btn.removeClass('running').prop('disabled', false);
            }
        })
        .fail(function () {
            hideProgress();
            showResult('error', 'Failed to schedule backup.');
            $btn.removeClass('running').prop('disabled', false);
        });
    });

    // --- Polling ---

    function startPolling() {
        if (pollTimer) return;
        pollStartTime = Date.now();
        pollTimer = setInterval(checkStatus, pollInterval);
        checkStatus(); // Check immediately too.
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        pollStartTime = null;
    }

    function checkStatus() {
        // Stop polling if it has been running longer than the max duration.
        if (pollStartTime && (Date.now() - pollStartTime) > pollMaxDuration) {
            stopPolling();
            hideProgress();
            showResult('error', 'Backup may be stuck — check the server or try cancelling.');
            $('#bm-run-backup').removeClass('running').prop('disabled', false);
            return;
        }

        $.post(bmBackup.ajaxUrl, {
            action: 'bm_backup_check_status',
            nonce: bmBackup.nonce,
        }).done(function (response) {
            if (!response.success) return;

            var s = response.data;

            if (!s.active) {
                stopPolling();
                $('#bm-cancel-backup').hide().prop('disabled', false);

                if (s.status === 'completed') {
                    hideProgress();
                    var msg = 'Backup completed!';
                    if (s.db_file_size) msg += '  DB: ' + formatBytes(s.db_file_size);
                    if (s.files_file_size) msg += '  Files: ' + formatBytes(s.files_file_size);
                    showResult('success', msg);
                    dismissStatus();
                    setTimeout(function () { location.reload(); }, 3000);

                } else if (s.status === 'failed') {
                    hideProgress();
                    showResult('error', 'Backup failed: ' + (s.error || 'Unknown error'));
                    dismissStatus();
                    $('#bm-run-backup').removeClass('running').prop('disabled', false);

                } else {
                    hideProgress();
                    showResult('', '');
                    $('#bm-run-backup').removeClass('running').prop('disabled', false);
                }
                return;
            }

            // Still active — show cancel button and update the progress bar.
            $('#bm-cancel-backup').show();
            showProgress(s.message || 'Running...', s.progress || 0);
        });
    }

    function dismissStatus() {
        $.post(bmBackup.ajaxUrl, {
            action: 'bm_backup_dismiss_status',
            nonce: bmBackup.nonce,
        });
    }

    // --- Progress bar UI ---

    function showProgress(text, percent) {
        var $box = $('#bm-progress-box');
        if (!$box.length) {
            $('#bm-run-backup').after(
                '<div id="bm-progress-box" class="bm-progress-box">' +
                '  <div class="bm-progress-bar-wrap"' +
                '       role="progressbar" aria-label="Backup progress"' +
                '       aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">' +
                '    <div class="bm-progress-bar"></div>' +
                '  </div>' +
                '  <span class="bm-progress-text" aria-live="polite"></span>' +
                '</div>'
            );
            $box = $('#bm-progress-box');
        }
        $box.show();
        $box.find('.bm-progress-text').text(text);
        if (typeof percent === 'number' && percent > 0) {
            $box.find('.bm-progress-bar').css('width', percent + '%');
            $box.find('.bm-progress-bar-wrap').attr('aria-valuenow', percent);
        }
    }

    function hideProgress() {
        var $box = $('#bm-progress-box');
        $box.hide();
        $box.find('.bm-progress-bar-wrap').attr('aria-valuenow', 0);
        $box.find('.bm-progress-bar').css('width', '0');
    }

    function showResult(type, message) {
        var $el = $('#bm-backup-result');
        $el.removeClass('success error loading');
        if (type) $el.addClass(type);
        $el.text(message);
    }

    // Generate / Regenerate API key.
    $('#bm-generate-key').on('click', function () {
        var $btn = $(this);
        var $result = $('#bm-key-result');

        if (!confirm('Generate a new Codespace bootstrap key? This will invalidate any existing key.')) {
            return;
        }

        $btn.prop('disabled', true);
        $result.removeClass('success error').addClass('loading').text('Generating...');

        $.post(bmBackup.ajaxUrl, {
            action: 'bm_backup_generate_api_key',
            nonce: bmBackup.nonce,
        })
        .done(function (response) {
            if (response.success) {
                var newKey = response.data.bootstrap_key;

                // Update existing field or reload so the full key section renders.
                var $field = $('#bm-bootstrap-key');
                if ($field.length) {
                    $field.val(newKey);
                    $btn.text('Regenerate Key');
                    $result.removeClass('loading').addClass('success').text('Key generated.');
                    setTimeout(function () { $result.text(''); }, 4000);
                } else {
                    $result.removeClass('loading').addClass('success').text('Key generated. Reloading...');
                    setTimeout(function () { location.reload(); }, 1200);
                }
            } else {
                $result.removeClass('loading').addClass('error').text(response.data || 'Failed to generate key.');
            }
        })
        .fail(function () {
            $result.removeClass('loading').addClass('error').text('Request failed.');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    // Copy bootstrap key to clipboard.
    $('#bm-copy-key').on('click', function () {
        var $btn = $(this);
        var $field = $('#' + $btn.data('target'));

        if (!$field.length || !$field.val()) return;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText($field.val()).then(function () {
                $btn.text('Copied!');
                setTimeout(function () { $btn.text('Copy'); }, 2000);
            });
        } else {
            $field[0].select();
            document.execCommand('copy');
            $btn.text('Copied!');
            setTimeout(function () { $btn.text('Copy'); }, 2000);
        }
    });

    // Download backup file via pre-signed URL.
    $(document).on('click', '.bm-download-link', function (e) {
        e.preventDefault();
        var $link = $(this);
        var key = $link.data('key');

        if (!key) return;

        $link.addClass('disabled').text('Loading...');

        $.post(bmBackup.ajaxUrl, {
            action: 'bm_backup_download',
            nonce: bmBackup.nonce,
            key: key,
        })
        .done(function (response) {
            if (response.success && response.data.url) {
                window.open(response.data.url, '_blank');
            } else {
                alert(response.data || 'Failed to generate download URL.');
            }
        })
        .fail(function () {
            alert('Download request failed.');
        })
        .always(function () {
            var label = key.indexOf('databases/') !== -1 ? 'DB' : 'Files';
            $link.removeClass('disabled').text(label);
        });
    });

    // Show/hide day-of-week when frequency is weekly.
    $('#bm_schedule_frequency').on('change', function () {
        $('#bm-schedule-day-row').toggle($(this).val() === 'weekly');
    });

    // On page load, check if a backup is already running.
    $(document).ready(function () {
        $.post(bmBackup.ajaxUrl, {
            action: 'bm_backup_check_status',
            nonce: bmBackup.nonce,
        }).done(function (response) {
            if (response.success && response.data.active) {
                $('#bm-run-backup').addClass('running').prop('disabled', true);
                $('#bm-cancel-backup').show();
                showProgress(response.data.message, response.data.progress);
                startPolling();
            }
        });
    });

    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        var sizes = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
    }

})(jQuery);
