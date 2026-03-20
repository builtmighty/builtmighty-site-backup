(function ($) {
    'use strict';

    var pollTimer = null;
    var pollInterval = 3000;
    var pollStartTime = null;
    var pollMaxDuration = 6 * 60 * 60 * 1000; // 6 hours.
    var lastLogIndex = 0;

    var stepLabels = ['Starting', 'Exporting DB', 'Archiving Files', 'Uploading DB', 'Uploading Files', 'Cleanup'];
    var stepKeys = ['start', 'export_db', 'archive_files', 'upload_db', 'upload_files', 'cleanup'];

    // --- Tabs ---

    function initTabs() {
        var saved = localStorage.getItem('bm_backup_active_tab');
        if (saved && $('.bm-tab-panel[data-tab="' + saved + '"]').length) {
            switchTab(saved);
        }
    }

    function switchTab(tab) {
        $('.nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
        $('.nav-tab-wrapper .nav-tab[data-tab="' + tab + '"]').addClass('nav-tab-active');
        $('.bm-tab-panel').removeClass('active');
        $('.bm-tab-panel[data-tab="' + tab + '"]').addClass('active');
        localStorage.setItem('bm_backup_active_tab', tab);
    }

    $('.nav-tab-wrapper .nav-tab').on('click', function (e) {
        e.preventDefault();
        switchTab($(this).data('tab'));
    });

    // --- Confirmation Modal ---

    var modalResolve = null;

    function bmConfirm(message) {
        return new Promise(function (resolve) {
            modalResolve = resolve;
            $('#bm-modal-message').text(message);
            $('#bm-modal').show();
        });
    }

    $('#bm-modal-confirm').on('click', function () {
        $('#bm-modal').hide();
        if (modalResolve) modalResolve(true);
        modalResolve = null;
    });

    $('#bm-modal-cancel').on('click', function () {
        $('#bm-modal').hide();
        if (modalResolve) modalResolve(false);
        modalResolve = null;
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#bm-modal').is(':visible')) {
            $('#bm-modal').hide();
            if (modalResolve) modalResolve(false);
            modalResolve = null;
        }
    });

    // --- Show/Hide Password ---

    $(document).on('click', '.bm-toggle-password', function () {
        var $btn = $(this);
        var $input = $('#' + $btn.data('target'));
        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $btn.text('Hide');
        } else {
            $input.attr('type', 'password');
            $btn.text('Show');
        }
    });

    // --- Inline Validation ---

    function showFieldError($input, message) {
        var $error = $input.siblings('.bm-field-error');
        if (!$error.length) {
            $error = $('<span class="bm-field-error"></span>');
            $input.after($error);
        }
        $error.text(message);
    }

    function clearFieldError($input) {
        $input.siblings('.bm-field-error').remove();
    }

    $('#bm_spaces_endpoint').on('blur', function () {
        var val = $(this).val().trim();
        if (val && !val.match(/\.digitaloceanspaces\.com$/)) {
            showFieldError($(this), 'Endpoint should end with .digitaloceanspaces.com');
        } else {
            clearFieldError($(this));
        }
    });

    $('#bm_retention_count').on('blur', function () {
        var val = parseInt($(this).val(), 10);
        if (isNaN(val) || val < 1 || val > 365) {
            showFieldError($(this), 'Must be between 1 and 365.');
        } else {
            clearFieldError($(this));
        }
    });

    $('#bm_notification_email').on('blur', function () {
        var val = $(this).val().trim();
        if (val && !val.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
            showFieldError($(this), 'Enter a valid email address.');
        } else {
            clearFieldError($(this));
        }
    });

    // --- Save Button Loading State ---

    $('#bm-settings-form').on('submit', function () {
        var $btn = $(this).find(':submit');
        $btn.val('Saving...').addClass('bm-saving');
    });

    // --- Test Connection ---

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
                showResultTimed($result, 'success', response.data, 4000);
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

    // --- Cancel Backup ---

    $('#bm-cancel-backup').on('click', function () {
        var $btn = $(this);

        bmConfirm('Cancel the running backup?').then(function (confirmed) {
            if (!confirmed) return;

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
                    autoDismissResult(4000);
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
    });

    // --- Run Backup Now ---

    $('#bm-run-backup').on('click', function () {
        var $btn = $(this);

        bmConfirm('Start a full backup now? This runs in the background and may take 30+ minutes depending on site size.').then(function (confirmed) {
            if (!confirmed) return;

            $btn.addClass('running').prop('disabled', true);
            showResult('loading', '');
            showProgress('Scheduling backup...', 0, '');

            $.post(bmBackup.ajaxUrl, {
                action: 'bm_backup_run_now',
                nonce: bmBackup.nonce,
            })
            .done(function (response) {
                if (response.success) {
                    lastLogIndex = 0;
                    showProgress('Backup scheduled. Waiting for first step...', 0, '');
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
    });

    // --- Polling ---

    function startPolling() {
        if (pollTimer) return;
        pollStartTime = Date.now();
        pollTimer = setInterval(checkStatus, pollInterval);
        checkStatus();
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        pollStartTime = null;
    }

    function checkStatus() {
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
            since: lastLogIndex,
        }).done(function (response) {
            if (!response.success) return;

            var s = response.data;

            // Append any new log entries.
            if (s.log_entries && s.log_entries.length > 0) {
                appendLogEntries(s.log_entries);
            }
            if (typeof s.log_index !== 'undefined') {
                lastLogIndex = s.log_index;
            }

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
                    setTimeout(function () { location.reload(); }, 5000);

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

            // Still active.
            $('#bm-cancel-backup').show();
            showProgress(s.message || 'Running...', s.progress || 0, s.step || '');
        });
    }

    function dismissStatus() {
        $.post(bmBackup.ajaxUrl, {
            action: 'bm_backup_dismiss_status',
            nonce: bmBackup.nonce,
        });
    }

    // --- Progress Bar + Step Indicators ---

    function buildStepsHtml() {
        var html = '<div class="bm-steps">';
        for (var i = 0; i < stepLabels.length; i++) {
            html += '<span class="bm-step" data-step="' + stepKeys[i] + '">' + stepLabels[i] + '</span>';
        }
        html += '</div>';
        return html;
    }

    function showProgress(text, percent, currentStep) {
        var $box = $('#bm-progress-box');
        if (!$box.length) {
            $('#bm-run-backup').after(
                '<div id="bm-progress-box" class="bm-progress-box">' +
                buildStepsHtml() +
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

        // Update step indicators.
        if (currentStep) {
            var activeIndex = stepKeys.indexOf(currentStep);
            $box.find('.bm-step').each(function (i) {
                var $step = $(this);
                $step.removeClass('bm-step-active bm-step-done');
                if (i < activeIndex) {
                    $step.addClass('bm-step-done');
                } else if (i === activeIndex) {
                    $step.addClass('bm-step-active');
                }
            });
        }
    }

    function hideProgress() {
        var $box = $('#bm-progress-box');
        $box.hide();
        $box.find('.bm-progress-bar-wrap').attr('aria-valuenow', 0);
        $box.find('.bm-progress-bar').css('width', '0');
        $box.find('.bm-step').removeClass('bm-step-active bm-step-done');
        $box.find('.bm-log-box').empty();
        lastLogIndex = 0;
    }

    // --- Live Log Box ---

    function ensureLogBox() {
        var $box = $('#bm-progress-box');
        if (!$box.find('.bm-log-box').length) {
            $box.append(
                '<div class="bm-log-wrap">' +
                '  <div class="bm-log-header">' +
                '    <span class="bm-log-title">Live Log</span>' +
                '    <button type="button" class="bm-log-toggle">Collapse</button>' +
                '  </div>' +
                '  <div class="bm-log-box" aria-live="polite" role="log"></div>' +
                '</div>'
            );
        }
    }

    function appendLogEntries(entries) {
        var $box = $('#bm-progress-box');
        if (!$box.length) return;

        ensureLogBox();
        var $logBox = $box.find('.bm-log-box');

        for (var i = 0; i < entries.length; i++) {
            var entry = entries[i];
            var $line = $('<div class="bm-log-line">')
                .append($('<span class="bm-log-time">').text(entry.time))
                .append($('<span class="bm-log-message">').text(entry.message));
            $logBox.append($line);
        }

        // Auto-scroll to bottom.
        $logBox.scrollTop($logBox[0].scrollHeight);
    }

    $(document).on('click', '.bm-log-toggle', function () {
        var $wrap = $(this).closest('.bm-log-wrap');
        var $logBox = $wrap.find('.bm-log-box');
        if ($logBox.is(':visible')) {
            $logBox.slideUp(200);
            $(this).text('Expand');
        } else {
            $logBox.slideDown(200);
            $(this).text('Collapse');
            $logBox.scrollTop($logBox[0].scrollHeight);
        }
    });

    // --- Result Messages ---

    function showResult(type, message) {
        var $el = $('#bm-backup-result');
        $el.removeClass('success error loading');
        if (type) $el.addClass(type);
        $el.text(message);
    }

    function autoDismissResult(ms) {
        setTimeout(function () {
            showResult('', '');
        }, ms || 4000);
    }

    function showResultTimed($el, type, message, ms) {
        $el.removeClass('success error loading').addClass(type).text(message);
        setTimeout(function () {
            $el.removeClass(type).text('');
        }, ms || 4000);
    }

    // --- Generate / Regenerate API Key ---

    $('#bm-generate-key').on('click', function () {
        var $btn = $(this);
        var $result = $('#bm-key-result');

        bmConfirm('Generate a new Codespace bootstrap key? This will invalidate any existing key.').then(function (confirmed) {
            if (!confirmed) return;

            $btn.prop('disabled', true);
            $result.removeClass('success error').addClass('loading').text('Generating...');

            $.post(bmBackup.ajaxUrl, {
                action: 'bm_backup_generate_api_key',
                nonce: bmBackup.nonce,
            })
            .done(function (response) {
                if (response.success) {
                    var newKey = response.data.bootstrap_key;
                    var $field = $('#bm-bootstrap-key');
                    if ($field.length) {
                        $field.val(newKey);
                        $btn.text('Regenerate Key');
                        showResultTimed($result, 'success', 'Key generated.', 4000);
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
    });

    // --- Copy Bootstrap Key ---

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

    // --- Download Backup ---

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

    // --- Expandable Error Messages ---

    $(document).on('click', '.bm-error-toggle', function (e) {
        e.preventDefault();
        var $cell = $(this).closest('.bm-error-cell');
        $cell.toggleClass('expanded');
        $(this).text($cell.hasClass('expanded') ? 'Show less' : 'Show more');
    });

    // --- Exit Dev Mode ---

    $('#bm-exit-dev-mode').on('click', function () {
        var $btn = $(this);
        var $result = $('#bm-dev-mode-result');

        $btn.prop('disabled', true).text('Enabling...');
        $result.removeClass('success error').text('');

        $.post(bmBackup.ajaxUrl, {
            action: 'bm_backup_exit_dev_mode',
            nonce: bmBackup.nonce,
        })
        .done(function (response) {
            if (response.success) {
                $result.addClass('success').text(response.data.message || 'Automatic backups re-enabled.');
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                $result.addClass('error').text(response.data || 'Failed to exit dev mode.');
                $btn.prop('disabled', false).text('Enable Automatic Backups');
            }
        })
        .fail(function () {
            $result.addClass('error').text('Request failed.');
            $btn.prop('disabled', false).text('Enable Automatic Backups');
        });
    });

    // --- Devcontainer: Check Version ---

    $('#bm-devcontainer-check').on('click', function () {
        var $btn = $(this);
        var $status = $('#bm-devcontainer-status');
        var $updateSection = $('#bm-devcontainer-update-section');
        var $versionInfo = $('#bm-devcontainer-version-info');

        $btn.prop('disabled', true);
        $status.removeClass('success error').addClass('loading').text('Checking...');
        $updateSection.hide();

        $.post(bmBackup.ajaxUrl, {
            action: 'bm_backup_devcontainer_check',
            nonce: bmBackup.nonce,
        })
        .done(function (response) {
            if (!response.success) {
                $status.removeClass('loading').addClass('error').text(response.data);
                return;
            }

            var d = response.data;

            if (d.status === 'up_to_date') {
                showResultTimed($status, 'success', 'Up to date (v' + d.latest + ')', 6000);
                $updateSection.hide();
            } else if (d.status === 'outdated') {
                $status.removeClass('loading').addClass('error').text('Out of date');
                $versionInfo.text('Current: v' + d.current + '  —  Latest: v' + d.latest);
                $updateSection.show();
            } else if (d.status === 'not_installed') {
                $status.removeClass('loading').addClass('error').text('Not installed');
                $versionInfo.text('Latest available: v' + d.latest);
                $updateSection.show();
            }
        })
        .fail(function () {
            $status.removeClass('loading').addClass('error').text('Request failed.');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    // --- Devcontainer: Install / Update ---

    $('#bm-devcontainer-update').on('click', function () {
        var $btn = $(this);
        var $result = $('#bm-devcontainer-update-result');

        bmConfirm('Create a PR to update .devcontainer to the latest version?').then(function (confirmed) {
            if (!confirmed) return;

            $btn.prop('disabled', true);
            $result.removeClass('success error').addClass('loading').text('Creating PR...');

            $.post(bmBackup.ajaxUrl, {
                action: 'bm_backup_devcontainer_update',
                nonce: bmBackup.nonce,
            })
            .done(function (response) {
                if (response.success) {
                    $result.removeClass('loading').addClass('success').html(
                        'PR created! <a href="' + response.data.pr_url + '" target="_blank" rel="noopener">View Pull Request</a>'
                    );
                } else {
                    $result.removeClass('loading').addClass('error').text(response.data);
                    $btn.prop('disabled', false);
                }
            })
            .fail(function () {
                $result.removeClass('loading').addClass('error').text('Request failed.');
                $btn.prop('disabled', false);
            });
        });
    });

    // --- Day-of-Week Smooth Toggle ---

    $('#bm_schedule_frequency').on('change', function () {
        var $row = $('#bm-schedule-day-row');
        if ($(this).val() === 'weekly') {
            $row.removeClass('bm-hidden').slideDown(200);
        } else {
            $row.slideUp(200, function () {
                $row.addClass('bm-hidden');
            });
        }
    });

    // --- Page Load: Check Running Backup + Init Tabs ---

    $(document).ready(function () {
        initTabs();

        $.post(bmBackup.ajaxUrl, {
            action: 'bm_backup_check_status',
            nonce: bmBackup.nonce,
            since: 0,
        }).done(function (response) {
            if (response.success && response.data.active) {
                // Switch to backup tab if a backup is running.
                switchTab('backup');
                $('#bm-run-backup').addClass('running').prop('disabled', true);
                $('#bm-cancel-backup').show();
                showProgress(response.data.message, response.data.progress, response.data.step);

                // Load existing log entries.
                if (response.data.log_entries && response.data.log_entries.length > 0) {
                    appendLogEntries(response.data.log_entries);
                }
                if (typeof response.data.log_index !== 'undefined') {
                    lastLogIndex = response.data.log_index;
                }
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
