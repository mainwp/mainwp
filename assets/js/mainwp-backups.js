
/**
 * Backup
 */
let backupDownloadRunning = false;
let backupError = false;
let backupCompleted = false;
let backupContinueRetries = 0;

jQuery(function () {
    jQuery('#backup_btnSubmit').on('click', function () {
        backup();
    });
    jQuery('#managesite-backup-status-close').on('click', function () {
        backupDownloadRunning = false;
        mainwpPopup('#managesite-backup-status-box').close(true);
    });

    // Keep each known-location exclude textarea hidden until its toggle is on,
    // so an empty box is not shown as a large blank area (L2).
    [
        { box: '#mainwp-known-backup-locations', area: '#mainwp-kbl-content' },
        { box: '#mainwp-known-cache-locations', area: '#mainwp-kcl-content' },
        { box: '#mainwp-non-wordpress-folders', area: '#mainwp-nwl-content' }
    ].forEach(function (pair) {
        let $box = jQuery(pair.box);
        let $row = jQuery(pair.area).closest('.field');
        if (!$box.length || !$row.length) {
            return;
        }
        let sync = function () {
            let on = $box.is(':checked');
            $row.toggle(on);
            jQuery(pair.area).prop('disabled', !on);
        };
        sync();
        $box.on('change', sync);
    });

});
let backup = function () {
    backupError = false;
    backupCompleted = false;
    backupContinueRetries = 0;

    jQuery('#backup_loading').show();
    let remote_destinations = jQuery('#backup_location_remote').hasClass('mainwp_action_down') ? jQuery.map(jQuery('#backup_destination_list').find('input[name="remote_destinations[]"]'), function (el) {
        return { id: jQuery(el).val(), title: jQuery(el).attr('title'), type: jQuery(el).attr('destination_type') };
    }) : [];

    let type = jQuery('#mainwp-backup-type').val();
    let sizeKey = (type == 'files') ? 'full' : type; // A files archive sizes like a full one.
    let size = jQuery('#backup_site_' + sizeKey + '_size').val();
    if (type == 'full' || type == 'files') {
        size = size * 1024 * 1024 / 2.4; //Guessing how large the zip will be
    }
    let fileName = jQuery('#backup_filename').val();
    let fileNameUID = mainwp_uid();
    let loadFilesBeforeZip = jQuery('[name="mainwp_options_loadFilesBeforeZip"]:checked').val();

    let backupPid = Math.round(new Date().getTime() / 1000);
    let data = mainwp_secure_data({
        action: 'mainwp_backup',
        site_id: jQuery('#backup_site_id').val(),
        pid: backupPid,
        type: type,
        exclude: jQuery('#excluded_folders_list').val(),
        excludebackup: (jQuery('#mainwp-known-backup-locations').attr('checked') ? 1 : 0),
        excludecache: (jQuery('#mainwp-known-cache-locations').attr('checked') ? 1 : 0),
        excludenonwp: (jQuery('#mainwp-non-wordpress-folders').attr('checked') ? 1 : 0),
        excludezip: (jQuery('#mainwp-zip-archives').attr('checked') ? 1 : 0),
        filename: fileName,
        fileNameUID: fileNameUID,
        archiveFormat: jQuery('#mainwp_archiveFormat').val(),
        maximumFileDescriptorsOverride: jQuery('#mainwp_options_maximumFileDescriptorsOverride_override').is(':checked') ? 1 : 0,
        maximumFileDescriptorsAuto: (jQuery('#mainwp_maximumFileDescriptorsAuto').attr('checked') ? 1 : 0),
        maximumFileDescriptors: jQuery('#mainwp_options_maximumFileDescriptors').val(),
        loadFilesBeforeZip: loadFilesBeforeZip,
        subfolder: jQuery('#backup_subfolder').val()
    }, true);

    mainwpPopup('#managesite-backup-status-box').getContentEl().html(dateToHMS(new Date()) + ' ' + MainWP.I18n.t('Creating the backup file on the child site, this might take a while depending on the size. Please be patient.') + ' <div id="managesite-createbackup-status-progress" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>');
    jQuery('#managesite-createbackup-status-progress').progress({ value: 0, total: size });

    mainwpPopup('#managesite-backup-status-box').init({
        callback: function () {
            // Refresh the downloadable-archives list in place instead of
            // reloading the whole page, and only after a clean completion.
            if (backupCompleted && !backupError) {
                let refreshData = mainwp_secure_data({
                    action: 'mainwp_backup_refresh_archives',
                    site_id: jQuery('#backup_site_id').val()
                });
                jQuery.post(ajaxurl, refreshData, function (response) {
                    if (response && response.result) {
                        jQuery('#mainwp-downloadable-archives-list').html(response.result);
                    }
                }, 'json');
            }
        }
    });
    let backsprocessContentEl = mainwpPopup('#managesite-backup-status-box').getContentEl();

    let fnc = function (pSiteId, pType, pFileName, pFileNameUID) {
        return function (pFunction) {
            let data2 = mainwp_secure_data({
                action: 'mainwp_createbackup_getfilesize',
                siteId: pSiteId,
                type: pType,
                fileName: pFileName,
                fileNameUID: pFileNameUID
            }, false);

            jQuery.ajax({
                url: ajaxurl,
                data: data2,
                method: 'POST',
                success: function (pFunc) {
                    return function (response) {
                        if (backupCreateRunning && response.error) {
                            setTimeout(function () {
                                pFunc(pFunc);
                            }, 1000);
                            return;
                        }

                        if (backupCreateRunning) {
                            let progressBar = jQuery('#managesite-createbackup-status-progress');
                            if (progressBar.progress('get value') < progressBar.progress('get total')) {
                                progressBar.progress('set progress', response.size);
                            }

                            setTimeout(function () {
                                pFunc(pFunc);
                            }, 1000);
                        }
                    }
                }(pFunction),
                error: function (pFunc) {
                    return function () {
                        if (backupCreateRunning) {
                            setTimeout(function () {
                                pFunc(pFunc);
                            }, 10000);
                        }
                    }
                }(pFunction),
                dataType: 'json'
            });
        }
    }(jQuery('#backup_site_id').val(), type, fileName, fileNameUID);

    setTimeout(function () {
        fnc(fnc);
    }, 1000);

    backupCreateRunning = true;
    jQuery.ajax({
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function (pSiteId, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, pData) {
            return function (response) {
                if (response.error || !response.result) {
                    backup_retry_fail(pSiteId, pData, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, response.error ? response.error : '');
                } else {
                    backupCreateRunning = false;

                    let progressBar = jQuery('#managesite-createbackup-status-progress');
                    progressBar.progress('set progress', parseFloat(progressBar.progress('get total')));

                    appendToDiv(backsprocessContentEl, MainWP.I18n.t('Backup file on child site created successfully!'));

                    backup_download_file(pSiteId, pType, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, pRemoteDestinations);
                }
            }
        }(jQuery('#backup_site_id').val(), remote_destinations, backupPid, type, jQuery('#backup_subfolder').val(), fileName, data),
        error: function (pSiteId, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, pData) {
            return function () {
                backup_retry_fail(pSiteId, pData, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename);
            }
        }(jQuery('#backup_site_id').val(), remote_destinations, backupPid, type, jQuery('#backup_subfolder').val(), fileName, data),
        dataType: 'json'
    });
};

let backup_retry_fail = function (siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError) {
    let backsprocessContentEl = mainwpPopup('#managesite-backup-status-box').getContentEl();
    //we've got the pid file!!!!
    let data = mainwp_secure_data({
        action: 'mainwp_backup_checkpid',
        site_id: siteId,
        pid: pid,
        type: type,
        subfolder: subfolder,
        filename: filename
    });

    jQuery.ajax({
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function (response) {
            if (response.status == 'done') {
                backupCreateRunning = false;

                let progressBar = jQuery('#managesite-createbackup-status-progress');
                progressBar.progress('set progress', parseFloat(progressBar.progress('get total')));

                //download!!!
                appendToDiv(backsprocessContentEl, MainWP.I18n.t('Backup file on child site created successfully!'));

                backup_download_file(siteId, type, response.result.file, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, remoteDestinations);
            } else if (response.status == 'stalled') {
                backupContinueRetries++;

                if (backupContinueRetries > 15) {
                    if (responseError != undefined) {
                        appendToDiv(backsprocessContentEl, ' ' + mainwp_backup_error_html(responseError));
                    } else {
                        appendToDiv(backsprocessContentEl, ' <span class="mainwp-red">' + MainWP.I18n.t('ERROR') + ': ' + MainWP.I18n.t('The backup timed out before the child site finished responding. The site may be overloaded, or the host may be limiting long-running requests.') + ' <a href="https://mainwp.com/help/docs/mainwp-introduction/resolving-system-requirement-issues/">' + MainWP.I18n.t('See this help document for possible fixes.') + '</a> (mainwp_backup_timeout)</span>'); // NOSONAR - noopener - open safe.
                    }
                } else {
                    appendToDiv(backsprocessContentEl, ' ' + MainWP.I18n.t('Backup stalled, trying to resume from last file...'));
                    pData['filename'] = response.result.file;
                    pData['append'] = 1;
                    pData = mainwp_secure_data(pData, true); //Rescure

                    jQuery.ajax({
                        url: ajaxurl,
                        data: pData,
                        method: 'POST',
                        success: function (pSiteId, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, pData) {
                            return function (response) {
                                if (response.error || !response.result) {
                                    backup_retry_fail(pSiteId, pData, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, response.error ? response.error : '');
                                } else {
                                    backupCreateRunning = false;

                                    let progressBar = jQuery('#managesite-createbackup-status-progress');
                                    progressBar.progress('set progress', parseFloat(progressBar.progress('get total')));

                                    appendToDiv(backsprocessContentEl, MainWP.I18n.t('Backupfile on child site created successfully.'));

                                    backup_download_file(pSiteId, pType, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, pRemoteDestinations);
                                }
                            }
                        }(siteId, remoteDestinations, pid, type, subfolder, filename, pData),
                        error: function (pSiteId, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, pData) {
                            return function () {
                                backup_retry_fail(pSiteId, pData, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename);
                            }
                        }(siteId, remoteDestinations, pid, type, subfolder, filename, pData),
                        dataType: 'json'
                    });
                }
            } else if (response.status == 'invalid') {
                backupCreateRunning = false;

                if (responseError != undefined) {
                    appendToDiv(backsprocessContentEl, ' ' + mainwp_backup_error_html(responseError));
                } else {
                    appendToDiv(backsprocessContentEl, ' <span class="mainwp-red">' + MainWP.I18n.t('ERROR') + ': ' + MainWP.I18n.t('The backup timed out before the child site finished responding. The site may be overloaded, or the host may be limiting long-running requests.') + ' <a href="https://mainwp.com/help/docs/mainwp-introduction/resolving-system-requirement-issues/">' + MainWP.I18n.t('See this help document for possible fixes.') + '</a> (mainwp_backup_timeout)</span>'); // NOSONAR - noopener - open safe.
                }
            } else {
                // busy or other.
                setTimeout(function () {
                    backup_retry_fail(siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError);
                }, 10000);
            }
        },
        error: function () {
            setTimeout(function () {
                backup_retry_fail(siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError);
            }, 10000);
        },
        dataType: 'json'
    });
};

backup_download_file = function (pSiteId, type, url, file, regexfile, size, subfolder, remote_destinations) {
    let backsprocessContentEl = mainwpPopup('#managesite-backup-status-box').getContentEl();
    appendToDiv(backsprocessContentEl, MainWP.I18n.t('Downloading the file.') + ' <div id="managesite-backup-status-progress" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>');
    jQuery('#managesite-backup-status-progress').progress({ value: 0, total: size });

    let fnc = function (file) {
        return function (pFunction) {
            let data = mainwp_secure_data({
                action: 'mainwp_backup_getfilesize',
                site_id: pSiteId,
                local: file
            });
            jQuery.ajax({
                url: ajaxurl,
                data: data,
                method: 'POST',
                success: function (pFunc) {
                    return function (response) {
                        if (backupCreateRunning && response.error) {
                            setTimeout(function () {
                                pFunc(pFunc);
                            }, 5000);
                            return;
                        }

                        if (backupDownloadRunning) {
                            let progressBar = jQuery('#managesite-backup-status-progress');
                            if (progressBar.progress('get value') < progressBar.progress('get total')) {
                                progressBar.progress('set progress', response.result);
                            }

                            setTimeout(function () {
                                pFunc(pFunc);
                            }, 1000);
                        }
                    }
                }(pFunction),
                error: function (pFunc) {
                    return function () {
                        if (backupCreateRunning) {
                            setTimeout(function () {
                                pFunc(pFunc);
                            }, 10000);
                        }
                    }
                }(pFunction),
                dataType: 'json'
            });
        }
    }(file);

    setTimeout(function () {
        fnc(fnc);
    }, 1000);

    let data = mainwp_secure_data({
        action: 'mainwp_backup_download_file',
        site_id: pSiteId,
        type: type,
        url: url,
        local: file
    });
    backupDownloadRunning = true;

    jQuery.ajax({
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function (pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pSize, pType, pUrl) {
            return function (response) {
                backupDownloadRunning = false;

                if (response.error) {
                    // The error HTML already states what failed, with severity and
                    // a diagnostic code; no generic "Backup failed" line on top.
                    appendToDiv(backsprocessContentEl, mainwp_backup_error_html(response.error));

                    jQuery('#managesite-backup-status-close').prop('value', MainWP.I18n.t('Close'));
                    return;
                }

                jQuery('#managesite-backup-status-progress').progress('set progress', pSize);
                appendToDiv(backsprocessContentEl, MainWP.I18n.t('Download from child site completed.'));

                let newData = mainwp_secure_data({
                    action: 'mainwp_backup_delete_file',
                    site_id: pSiteId,
                    file: pUrl
                });
                jQuery.post(ajaxurl, newData, function () { }, 'json');
                backup_upload_file(pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize);
            }
        }(pSiteId, file, regexfile, subfolder, remote_destinations, size, type, url),
        error: function () {
            // Clearing the flag also stops the file-size poller above, which
            // reschedules itself for as long as the download is running.
            backupDownloadRunning = false;
            backupError = true;
            appendToDiv(backsprocessContentEl, ' <span class="mainwp-red">' + MainWP.I18n.t('ERROR') + ': ' + MainWP.I18n.t('The download from the child site failed. Check that the site is reachable and try again.') + '</span>');
            jQuery('#managesite-backup-status-close').prop('value', MainWP.I18n.t('Close'));
        },
        dataType: 'json'
    });
};

// The client-driven remote-destination upload flow this function used to
// gate on (action: mainwp_backup_upload_file) has no PHP handler on this
// branch - server-side FTP/Dropbox/S3/GCS upload now happens inside the
// backup executor itself, and no template renders #backup_destination_list,
// so pRemoteDestinations is always empty here in practice. The dead branch
// and its retry-fail sibling (backup_upload_file_retry_fail) are removed;
// this always finishes the same way real backups already do today.
backup_upload_file = function (pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize) {
    let backsprocessContentEl = mainwpPopup('#managesite-backup-status-box').getContentEl();
    backupCompleted = true;
    appendToDiv(backsprocessContentEl, MainWP.I18n.t('Backup completed!'));
    jQuery('#managesite-backup-status-close').prop('value', MainWP.I18n.t('Close'));
    // The archive list refreshes in place when the user closes the modal
    // (see the init callback), so do not auto-close or reload the page.
};


/**
 * Manage backups page
 */
jQuery(function () {
    jQuery(document).on('click', '.backup_destination_exclude', function () {
        jQuery(this).parent().parent().animate({ height: 0 }, {
            duration: 'slow', complete: function () {
                jQuery(this).remove();
            }
        });
    });
    jQuery('#mainwp_managebackups_add').on('click', function (event) {
        mainwp_managebackups_add(event);
    });
    jQuery('#mainwp_managebackups_update').on('click', function (event) {
        mainwp_managebackups_update(event);
    });
    jQuery(document).on('click', '.backup_run_now', function () {
        managebackups_run_now(jQuery(this));
        return false;
    });
    jQuery(document).on('click', '#managebackups-task-status-close', function () {
        backupDownloadRunning = false;
        clearTimeout(manageBackupsPollTimer);
        mainwpPopup('#managebackups-task-status-box').close(true);
    });
    managebackups_init();

});
managebackups_exclude_folder = function (pElement) {
    let folder = pElement.parent().attr('rel') + "\n";
    if (jQuery('#excluded_folders_list').val().includes(folder))
        return;

    jQuery('#excluded_folders_list').val(jQuery('#excluded_folders_list').val() + folder);
};

let manageBackupsPollTimer = null;

// Render the recorded phase and percent in the site's existing progress bar.
let managebackups_render_task_phase = function (siteId, progress) {
    let parts = [progress.label || ''];
    let pct = (progress.percent !== null && progress.percent !== undefined) ? progress.percent : null;
    if (pct !== null) {
        parts.push(pct + '%');
    }
    jQuery('.managebackups-task-status-phase[siteId="' + siteId + '"]').text(parts.join(' '));
    let progressBar = jQuery('.managebackups-task-status-progress[siteId="' + siteId + '"]');
    if (pct !== null) {
        progressBar.progress('set percent', pct);
    }
};
managebackups_run_now = function (el) {
    const POLL_INTERVAL_MS = 3000;
    const QUEUED_NOTICE_MS = 2 * 60 * 1000;
    const QUEUED_LIMIT_MS = 15 * 60 * 1000;
    const UPLOAD_LIMIT_MS = 10 * 60 * 1000;
    el = jQuery(el);
    clearTimeout(manageBackupsPollTimer);
    el.hide();
    el.parent().find('.backup_run_loading').show();
    let content = mainwpPopup('#managebackups-task-status-box').getContentEl();
    content.html(dateToHMS(new Date()) + ' ' + MainWP.I18n.t('Starting the backup task...'));
    jQuery('#managebackups-task-status-close').prop('value', MainWP.I18n.t('Cancel'));
    mainwpPopup('#managebackups-task-status-box').init({
        title: MainWP.I18n.t('Running task'), callback: function () {
            clearTimeout(manageBackupsPollTimer);
            location.reload();
        }
    });

    let taskId = el.attr('task_id');
    let started = 0;
    let click = 0;
    let baseline = null;
    let failures = 0;
    let queuedSince = null;
    let closedSince = null;
    let lastResponse = null;
    let rows = {};
    let waiting = jQuery('<div>').appendTo(content);
    let restoreLink = function () {
        el.parent().find('.backup_run_loading').hide();
        el.show();
        jQuery('#managebackups-task-status-close').prop('value', MainWP.I18n.t('Close'));
    };
    let startFailed = function (error) {
        restoreLink();
        appendToDiv(content, mainwp_backup_error_html(error || MainWP.I18n.t('The backup task could not be started. Reload the page and try again.')));
    };
    let statusFailed = function (error) {
        waiting.empty();
        el.parent().find('.backup_run_loading').hide();
        jQuery('#managebackups-task-status-close').prop('value', MainWP.I18n.t('Close'));
        appendToDiv(content, mainwp_backup_error_html(error || MainWP.I18n.t('The backup status could not be read. The backup may still be running. Reload the page to check its status.')));
    };
    let schedulePoll = function () {
        manageBackupsPollTimer = setTimeout(poll, POLL_INTERVAL_MS);
    };
    let queuedExpired = function () {
        if (queuedSince === null || Date.now() - queuedSince < QUEUED_LIMIT_MS) {
            return false;
        }
        restoreLink();
        waiting.text(MainWP.I18n.t('The backup could not get a free slot. The task was not run. Try again later.'));
        return true;
    };
    let render = function (response) {
        lastResponse = response;
        let queued = response.status === 'queued' && Number(started) === 0 && click > 0;
        queuedSince = queued ? (queuedSince ?? Date.now()) : null;
        if (queuedExpired()) {
            return;
        }
        waiting.text(response.status === 'queued' ? MainWP.I18n.t('Waiting for a free backup slot...') : '');
        if (queued && Date.now() - queuedSince >= QUEUED_NOTICE_MS) {
            waiting.text(MainWP.I18n.t('Still waiting for a free backup slot. Elapsed wait: %d minutes.').replace('%d', Math.floor((Date.now() - queuedSince) / 60000)));
        }
        let closed = response.status === 'completed' || response.status === 'completed_with_errors';
        if (closed && closedSince === null) {
            closedSince = Date.now();
        }
        let uploadsPending = closed && (response.sites || []).some(function (site) {
            return site.uploads && site.uploads.pending > 0;
        });
        let uploadsExpired = uploadsPending && Date.now() - closedSince >= UPLOAD_LIMIT_MS;
        (response.sites || []).forEach(function (site) {
            if (!rows[site.id]) {
                let row = jQuery('<div>').appendTo(content);
                jQuery('<span>').text('[' + site.name + '] ').appendTo(row);
                let label = jQuery('<span class="managebackups-task-status-phase">').attr('siteId', site.id).appendTo(row);
                let bar = jQuery('<div class="ui green progress managebackups-task-status-progress"><div class="bar"><div class="progress"></div></div></div>').attr('siteId', site.id).appendTo(row);
                bar.progress({value: 0, total: 100});
                rows[site.id] = {label: label, bar: bar};
            }
            let row = rows[site.id];
            row.label.removeClass('mainwp-red');
            row.bar.show();
            managebackups_render_task_phase(site.id, site);
            if (site.status === 'completed') {
                let label = MainWP.I18n.t('Snapshot recorded') + ' (' + site.snapshot.type + ')';
                if (site.uploads && site.uploads.total > 0) {
                    if (uploadsExpired && site.uploads.pending > 0) {
                        label += ' — ' + MainWP.I18n.t('Remaining uploads continue in the background. Check the Snapshots page for their state.');
                    } else {
                        label += ' — ' + MainWP.I18n.t('%1$d of %2$d uploaded').replace('%1$d', site.uploads.complete).replace('%2$d', site.uploads.total);
                    }
                    if (site.uploads.failed > 0) {
                        label += ' — ' + MainWP.I18n.t('%d upload(s) failed.').replace('%d', site.uploads.failed);
                        row.label.addClass('mainwp-red');
                    }
                }
                row.label.text(label);
                row.bar.progress('set percent', 100);
            } else if (site.status === 'failed') {
                row.label.addClass('mainwp-red').text(site.error || MainWP.I18n.t('Backup task failed.'));
                row.bar.hide();
            }
        });
        if (response.status === 'running' || response.status === 'queued' || (uploadsPending && !uploadsExpired)) {
            schedulePoll();
            return;
        }
        restoreLink();
        let message;
        if (response.status === 'completed') {
            message = MainWP.I18n.t('Backup task completed.');
        } else if (response.status === 'completed_with_errors') {
            message = MainWP.I18n.t('Backup task completed with errors.');
        } else {
            message = MainWP.I18n.t('Backup task failed.');
        }
        jQuery('<div>').text(message).appendTo(content);
        if (response.errors) {
            jQuery('<div class="mainwp-red">').text(response.errors).appendTo(content);
        }
        if (response.notices) {
            jQuery('<div>').text(response.notices).appendTo(content);
        }
        if (response.status === 'completed' && !uploadsPending) {
            manageBackupsPollTimer = setTimeout(function () {
                mainwpPopup('#managebackups-task-status-box').close(true);
            }, 3000);
        }
    };
    let poll = function () {
        // A status request can claim a slot, so enforce the wait limit before posting.
        if (queuedExpired()) {
            return;
        }
        if (closedSince !== null && Date.now() - closedSince >= UPLOAD_LIMIT_MS) {
            render(lastResponse);
            return;
        }
        jQuery.post(ajaxurl, mainwp_secure_data({action: 'mainwp_backuptask_run_status', task_id: taskId, started: started, click: click, baseline: baseline}), function (response) {
            failures = 0;
            if (!response || response.error || !response.status) {
                statusFailed(response && response.error);
                return;
            }
            started = response.started;
            click = response.click;
            baseline = response.baseline ?? baseline;
            render(response);
        }, 'json').fail(function () {
            if (++failures >= 3) {
                statusFailed();
            } else {
                schedulePoll();
            }
        });
    };
    jQuery.post(ajaxurl, mainwp_secure_data({action: 'mainwp_backuptask_run_now', task_id: taskId}), function (response) {
        if (!response || response.error || !response.job_id) {
            startFailed(response && response.error);
            return;
        }
        started = response.started;
        click = response.click;
        baseline = response.baseline ?? null;
        render(response);
    }, 'json').fail(function () {
        startFailed();
    });
};

managebackups_init = function () {
    setVisible('#mainwp_managebackups_add_errors', false);

    jQuery('#mainwp_managebackups_add_errors').html();
    jQuery('#mainwp_managebackups_add_message').html();
};

mainwp_managebackups_update = function () {
    managebackups_init();

    let errors = [];
    if (jQuery('#mainwp_managebackups_add_name').val() == '') {
        errors.push(MainWP.I18n.t('Please enter a valid name for your backup task'));
    }
    let selected_groups = [];
    let selected_sites = [];
    if (jQuery('input[name="select_by"]').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function () {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push(MainWP.I18n.t('Please select websites or groups to add a backup task.'));
        }
    } else {
        jQuery("input[name='selected_groups[]']:checked").each(function () {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push(MainWP.I18n.t('Please select websites or groups to add a backup task.'));
        }
    }

    if (errors.length > 0) {
        feedback('mainwp-message-zone', errors.join('<br />'), 'red');
    } else {
        feedback('mainwp-message-zone', MainWP.I18n.t('Adding the task...'), 'green');

        jQuery('#mainwp_managebackups_update').attr('disabled', 'true');

        //let loadFilesBeforeZip = jQuery( '[name="mainwp_options_loadFilesBeforeZip"]:checked' ).val();
        let name = jQuery('#mainwp_managebackups_add_name').val();
        name = name.replace(/"/g, '&quot;');
        let data = mainwp_secure_data({
            action: 'mainwp_updatebackup',
            id: jQuery('#mainwp_managebackups_edit_id').val(),
            name: name,
            schedule: jQuery('#mainwp-backup-task-schedule').val(),
            type: jQuery('#mainwp-backup-type').val(),
            exclude: (jQuery('#mainwp-backup-type').val() != 'db' ? jQuery('#excluded_folders_list').val() : ''),
            excludebackup: (jQuery('#mainwp-known-backup-locations').attr('checked') ? 1 : 0),
            excludecache: (jQuery('#mainwp-known-cache-locations').attr('checked') ? 1 : 0),
            excludenonwp: (jQuery('#mainwp-non-wordpress-folders').attr('checked') ? 1 : 0),
            excludezip: (jQuery('#mainwp-zip-archives').attr('checked') ? 1 : 0),
            'groups[]': selected_groups,
            'sites[]': selected_sites,
            subfolder: jQuery('#mainwp_managebackups_add_subfolder').val(),
            filename: jQuery('#backup_filename').val(),
            archiveFormat: jQuery('#mainwp_archiveFormat').val(),
            zipForceOverride: (jQuery('#mainwp-backup-zip-force-override').is(':checked') ? 1 : 0),
            maximumFileDescriptorsOverride: jQuery('#mainwp_options_maximumFileDescriptorsOverride_override').is(':checked') ? 1 : 0,
            maximumFileDescriptorsAuto: (jQuery('#mainwp_maximumFileDescriptorsAuto').attr('checked') ? 1 : 0),
            maximumFileDescriptors: jQuery('#mainwp_options_maximumFileDescriptors').val(),
            'destinations[]': (jQuery('#mainwp-backup-task-destinations').length ? (jQuery('#mainwp-backup-task-destinations').val() || []) : []),
            schedule_interval_hours: jQuery('#mainwp-schedule-interval-hours').val(),
            schedule_time_of_day: jQuery('#mainwp-schedule-time-of-day').val(),
            schedule_day_of_week: jQuery('#mainwp-schedule-day-of-week').val(),
            schedule_day_of_month: jQuery('#mainwp-schedule-day-of-month').val(),
            retention_count: jQuery('#mainwp-backup-retention-count').val(),
            retention_days: jQuery('#mainwp-backup-retention-days').val(),
            retention_storage_mb: jQuery('#mainwp-backup-retention-storage-mb').val(),
            max_chain_length: jQuery('#mainwp-backup-max-chain-length').val(),
            delete_cloud_copies: (jQuery('#mainwp-backup-delete-cloud-copies').is(':checked') ? 1 : 0),
            //loadFilesBeforeZip: loadFilesBeforeZip
        });
        jQuery.post(ajaxurl, data, function (response) {
            managebackups_init();
            if (response.error != undefined) {
                feedback('mainwp-message-zone', response.error, 'red');
            } else {
                feedback('mainwp-message-zone', response.result, 'green');
            }

            jQuery('#mainwp_managebackups_update').prop("disabled", false);
        }, 'json').fail(function () {
            managebackups_init();
            feedback('mainwp-message-zone', MainWP.I18n.t('The backup task could not be saved. Reload the page and try again.'), 'red');
            jQuery('#mainwp_managebackups_update').prop("disabled", false);
        });
    }
};
mainwp_managebackups_add = function () {
    managebackups_init();

    let errors = [];
    if (jQuery('#mainwp_managebackups_add_name').val() == '') {
        errors.push(MainWP.I18n.t('Please enter a valid name for your backup task'));
    }
    let selected_sites = [];
    let selected_groups = [];
    if (jQuery('input[name="select_by"]').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function () {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push(MainWP.I18n.t('Please select websites or groups.'));
        }
    } else {
        jQuery("input[name='selected_groups[]']:checked").each(function () {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push(MainWP.I18n.t('Please select websites or groups.'));
        }
    }


    if (errors.length > 0) {
        feedback('mainwp-message-zone', errors.join('<br />'), 'red');
    } else {
        feedback('mainwp-message-zone', MainWP.I18n.t('Adding the task...'), 'green');

        jQuery('#mainwp_managebackups_add').attr('disabled', 'true');

        let loadFilesBeforeZip = jQuery('[name="mainwp_options_loadFilesBeforeZip"]:checked').val();
        let name = jQuery('#mainwp_managebackups_add_name').val();
        name = name.replace(/"/g, '&quot;');
        let data = mainwp_secure_data({
            action: 'mainwp_addbackup',
            name: name,
            schedule: jQuery('#mainwp-backup-task-schedule').val(),
            type: jQuery('#mainwp-backup-type').val(),
            exclude: (jQuery('#mainwp-backup-type').val() != 'db' ? jQuery('#excluded_folders_list').val() : ''),
            excludebackup: (jQuery('#mainwp-known-backup-locations').attr('checked') ? 1 : 0),
            excludecache: (jQuery('#mainwp-known-cache-locations').attr('checked') ? 1 : 0),
            excludenonwp: (jQuery('#mainwp-non-wordpress-folders').attr('checked') ? 1 : 0),
            excludezip: (jQuery('#mainwp-zip-archives').attr('checked') ? 1 : 0),
            'groups[]': selected_groups,
            'sites[]': selected_sites,
            subfolder: jQuery('#mainwp_managebackups_add_subfolder').val(),
            filename: jQuery('#backup_filename').val(),
            archiveFormat: jQuery('#mainwp_archiveFormat').val(),
            zipForceOverride: (jQuery('#mainwp-backup-zip-force-override').is(':checked') ? 1 : 0),
            maximumFileDescriptorsOverride: jQuery('#mainwp_options_maximumFileDescriptorsOverride_override').is(':checked') ? 1 : 0,
            maximumFileDescriptorsAuto: (jQuery('#mainwp_maximumFileDescriptorsAuto').attr('checked') ? 1 : 0),
            maximumFileDescriptors: jQuery('#mainwp_options_maximumFileDescriptors').val(),
            'destinations[]': (jQuery('#mainwp-backup-task-destinations').length ? (jQuery('#mainwp-backup-task-destinations').val() || []) : []),
            schedule_interval_hours: jQuery('#mainwp-schedule-interval-hours').val(),
            schedule_time_of_day: jQuery('#mainwp-schedule-time-of-day').val(),
            schedule_day_of_week: jQuery('#mainwp-schedule-day-of-week').val(),
            schedule_day_of_month: jQuery('#mainwp-schedule-day-of-month').val(),
            retention_count: jQuery('#mainwp-backup-retention-count').val(),
            retention_days: jQuery('#mainwp-backup-retention-days').val(),
            retention_storage_mb: jQuery('#mainwp-backup-retention-storage-mb').val(),
            max_chain_length: jQuery('#mainwp-backup-max-chain-length').val(),
            delete_cloud_copies: (jQuery('#mainwp-backup-delete-cloud-copies').is(':checked') ? 1 : 0),
            loadFilesBeforeZip: loadFilesBeforeZip
        });
        jQuery.post(ajaxurl, data, function (response) {
            managebackups_init();
            if (response.error != undefined) {
                feedback('mainwp-message-zone', response.error, 'red');
            } else {
                location.href = 'admin.php?page=ManageBackups&a=1';
                feedback('mainwp-message-zone', response.result, 'green');
            }
            jQuery('#mainwp_managebackups_add').prop("disabled", false);
        }, 'json').fail(function () {
            managebackups_init();
            feedback('mainwp-message-zone', MainWP.I18n.t('The backup task could not be saved. Reload the page and try again.'), 'red');
            jQuery('#mainwp_managebackups_add').prop("disabled", false);
        });
    }
};
managebackups_remove = function (element) {
    let id = jQuery(element).attr('task_id');
    managebackups_init();

    let msg = MainWP.I18n.t('Are you sure you want to delete this backup task? Its existing backups and cloud copies will be removed too.');
    mainwp_confirm(msg, function () {
        jQuery('#task-status-' + id).html(MainWP.I18n.t('Removing the task...'));
        let data = mainwp_secure_data({
            action: 'mainwp_removebackup',
            id: id
        });
        jQuery.post(ajaxurl, data, function (pElement) {
            return function (response) {
                managebackups_init();
                let result = '';
                let error = '';
                if (response.error != undefined) {
                    error = response.error;
                } else if (response.result == 'SUCCESS') {
                    result = MainWP.I18n.t('The task has been removed.');
                } else {
                    error = MainWP.I18n.t('An undefined error occurred.');
                }

                if (error != '') {
                    managebackups_show_message(error, 'red');
                }
                if (result != '') {
                    setHtml('#mainwp_managebackups_add_message', result);
                }
                jQuery('#task-status-' + id).html('');
                if (error == '') {
                    jQuery(pElement).closest('tr').remove();
                }
            }
        }(element), 'json');
    });
    return false;
};
managebackups_resume = function (element) {
    let id = jQuery(element).attr('task_id');
    managebackups_init();

    jQuery('#task-status-' + id).html(MainWP.I18n.t('Resuming the task...'));
    let data = mainwp_secure_data({
        action: 'mainwp_resumebackup',
        id: id
    });
    jQuery.post(ajaxurl, data, function (pElement, pId) {
        return function (response) {
            managebackups_init();
            let result = '';
            let error = '';
            if (response.error != undefined) {
                error = response.error;
            } else if (response.result == 'SUCCESS') {
                result = MainWP.I18n.t('The task has been resumed.');
            } else {
                error = MainWP.I18n.t('An undefined error occurred.');
            }

            if (error != '') {
                managebackups_show_message(error, 'red');
            }
            if (result != '') {
                setHtml('#mainwp_managebackups_add_message', result);
            }
            jQuery('#task-status-' + id).html('');

            if (error == '') {
                jQuery(pElement).after('<a href="#" task_id="' + pId + '" onClick="return managebackups_pause(this)">' + MainWP.I18n.t('Pause') + '</a>');
                jQuery(pElement).remove();
            }
        }
    }(element, id), 'json');

    return false;
};
managebackups_pause = function (element) {
    let id = jQuery(element).attr('task_id');
    managebackups_init();

    jQuery('#task-status-' + id).html(MainWP.I18n.t('Pausing the task...'));
    let data = mainwp_secure_data({
        action: 'mainwp_pausebackup',
        id: id
    });
    jQuery.post(ajaxurl, data, function (pElement, pId) {
        return function (response) {
            managebackups_init();
            let result = '';
            let error = '';
            if (response.error != undefined) {
                error = response.error;
            } else if (response.result == 'SUCCESS') {
                result = MainWP.I18n.t('The task has been paused.');
            } else {
                error = MainWP.I18n.t('An undefined error occurred.');
            }

            if (error != '') {
                managebackups_show_message(error, 'red');
            }
            if (result != '') {
                setHtml('#mainwp_managebackups_add_message', result);
            }
            jQuery('#task-status-' + id).html('');
            if (error == '') {
                jQuery(pElement).after('<a href="#" task_id="' + pId + '" onClick="return managebackups_resume(this)">' + MainWP.I18n.t('Resume') + '</a>');
                jQuery(pElement).remove();
            }
        }
    }(element, id), 'json');

    return false;
};
managebackups_show_message = function (text, color) {
    jQuery('#mainwp_managebackups_add_message').removeClass('green yellow red').addClass(color);
    setHtml('#mainwp_managebackups_add_message', text);
};
// Reset a scheduled task's stuck "currently running" state once the list
// view has flagged it stale (elapsed past the executor's stall threshold).
// The handler also cancels the run child-side on every site in the task;
// a site whose cancel does not land falls back to the dashboard-only
// bookkeeping close.
managebackups_reset_stale = function (element) {
    let id = jQuery(element).attr('task_id');

    mainwp_confirm(MainWP.I18n.t('Reset this task\'s stuck run? Only do this if you are sure the scheduled backup crashed.'), function () {
        let data = mainwp_secure_data({
            action: 'mainwp_native_backup_task_reset',
            task_id: id
        });
        jQuery.post(ajaxurl, data, function (response) {
            if (response.error != undefined) {
                managebackups_show_message(response.error, 'red');
                return;
            }
            let cancelled = response.cancelled != undefined ? response.cancelled : 0;
            let uncancelled = response.uncancelled != undefined ? response.uncancelled : 0;
            if (uncancelled > 0) {
                managebackups_show_message(MainWP.I18n.t('Task reset. Cancelled %1 child backup run(s); %2 site(s) could not be stopped. See the task\'s Last run notes.', cancelled, uncancelled), 'yellow');
                setTimeout(function () {
                    location.reload();
                }, 5000);
            } else {
                managebackups_show_message(MainWP.I18n.t('Task reset. Cancelled %1 child backup run(s).', cancelled), 'green');
                setTimeout(function () {
                    location.reload();
                }, 1500);
            }
        }, 'json');
    });

    return false;
};


jQuery(document).on('click', '#updatesoverview-backup-ignore', function () {
    if (updatesoverviewContinueAfterBackup != undefined) {
        mainwpPopup('#updatesoverview-backup-box').close();
        updatesoverviewContinueAfterBackup();
        updatesoverviewContinueAfterBackup = undefined;
    }
});

let updatesoverviewShowBusyFunction;
let updatesoverviewShowBusyTimeout;
mainwp_updatesoverview_checkBackups = function (sitesToUpdate, siteNames) {
    if (mainwpParams['disable_checkBackupBeforeUpgrade']) {
        if (updatesoverviewContinueAfterBackup != undefined) {
            updatesoverviewContinueAfterBackup();
        }
        return false;
    }

    updatesoverviewShowBusyFunction = function () {

        let output = MainWP.I18n.t('Checking if a backup is required for the selected updates...');
        mainwpPopup('#updatesoverview-backup-box').getContentEl().html(output);
        jQuery('#updatesoverview-backup-all').hide();
        jQuery('#updatesoverview-backup-ignore').hide();

        mainwpPopup('#updatesoverview-backup-box').init({
            title: MainWP.I18n.t("Checking backup settings..."), callback: function () {
                window.location.href = location.href
            }
        });
    };

    updatesoverviewShowBusyTimeout = setTimeout(updatesoverviewShowBusyFunction, 300);

    // A suspended site is skipped later in the upgrade loop, so it must never
    // enter the native-backup preflight: the executor rejects it
    // (ERR_SUSPENDED_SITE) and the user hits a needless "backup required"/error
    // detour before the loop skips the site anyway. The updates-overview page
    // carries an is-suspended flag on a hidden element per pending site; drop
    // those from the set the check runs over. The caller keeps its full list
    // for its own progress and suspended-status display.
    let sitesToCheck = [];
    for (let id of sitesToUpdate) {
        if (jQuery('[site_id="' + id + '"][is-suspended="1"]').length > 0)
            continue;
        sitesToCheck.push(id);
    }

    let data = mainwp_secure_data({
        action: 'mainwp_checkbackups',
        sites: sitesToCheck
    });

    jQuery.ajax({
        type: "POST",
        url: ajaxurl,
        data: data,
        success: function (pSiteNames) {
            return function (response) {
                clearTimeout(updatesoverviewShowBusyTimeout);

                mainwpPopup('#updatesoverview-backup-box').close();

                let siteFeedback = undefined;

                if (response['result'] && response['result']['sites'] != undefined) {
                    siteFeedback = [];
                    for (let currSiteId in response['result']['sites']) {
                        if (!response['result']['sites'][currSiteId]) {
                            siteFeedback.push(currSiteId);
                        }
                    }
                    if (siteFeedback.length == 0)
                        siteFeedback = undefined;
                }

                if (siteFeedback != undefined) {
                    let backupPrimary = '';
                    if (response['result']['primary_backup'] && response['result']['primary_backup'] != undefined)
                        backupPrimary = response['result']['primary_backup'];

                    if (backupPrimary == '') {
                        jQuery('#updatesoverview-backup-all').show();
                        jQuery('#updatesoverview-backup-ignore').show();
                    } else {
                        let backupLink = mainwp_get_primaryBackup_link(backupPrimary);
                        jQuery('#updatesoverview-backup-now').attr('href', backupLink).show();
                        jQuery('#updatesoverview-backup-ignore').val(MainWP.I18n.t('Proceed with Updates')).show();
                    }

                    let output = '<span class="mainwp-red">' + MainWP.I18n.t('A full backup has not been taken in the last days for the following sites:') + '</span><br /><br />';

                    if (backupPrimary == '') { // default backup feature
                        for (let id of siteFeedback) {
                            output += '<span class="updatesoverview-backup-site" siteid="' + id + '">' + decodeURIComponent(pSiteNames[id]) + '</span><br />';
                        }
                    } else {
                        for (let id of siteFeedback) {
                            output += '<span>' + decodeURIComponent(pSiteNames[id]) + '</span><br />';
                        }
                    }

                    mainwpPopup('#updatesoverview-backup-box').getContentEl().html(output);

                    mainwpPopup('#updatesoverview-backup-box').init({
                        title: MainWP.I18n.t("Full backup required!"), callback: function () {
                            if (updatesoverviewContinueAfterBackup != undefined) {
                                updatesoverviewContinueAfterBackup = undefined;
                            }
                            window.location.href = location.href
                        }
                    });
                    return false;
                }

                if (updatesoverviewContinueAfterBackup != undefined) {
                    updatesoverviewContinueAfterBackup();
                }
            }
        }(siteNames),
        error: function () {

            mainwpPopup('#updatesoverview-backup-box').close(true);
        },
        dataType: 'json'
    });

    return false;
};


jQuery(document).on('click', '#updatesoverview-backupnow-close', function () {
    if (jQuery(this).prop('cancel') == '1') {
        updatesoverviewBackupSites = [];
        updatesoverviewBackupError = false;
        updatesoverviewBackupDownloadRunning = false;
        mainwpPopup('#updatesoverview-backup-box').close(true);
    } else {
        mainwpPopup('#updatesoverview-backup-box').close();
        if (updatesoverviewContinueAfterBackup != undefined)
            updatesoverviewContinueAfterBackup();
    }
});
jQuery(document).on('click', '#updatesoverview-backup-all', function () {

    // change action buttons
    mainwpPopup('#updatesoverview-backup-box').setActionButtons('<input id="updatesoverview-backupnow-close" type="button" name="Ignore" value="' + MainWP.I18n.t('Cancel') + '" class="button"/>');
    mainwpPopup('#updatesoverview-backup-box').init({
        title: MainWP.I18n.t("Full backup"), callback: function () {
            updatesoverviewContinueAfterBackup = undefined;
            window.location.href = location.href
        }
    });

    let sitesToBackup = jQuery('.updatesoverview-backup-site');
    updatesoverviewBackupSites = [];
    for (let id of sitesToBackup) {
        let currentSite = [];
        currentSite['id'] = jQuery(id).attr('siteid');
        currentSite['name'] = jQuery(id).text();
        updatesoverviewBackupSites.push(currentSite);
    }
    updatesoverview_backup_run();
});

let updatesoverviewBackupSites;
let updatesoverviewBackupError;
let updatesoverviewBackupDownloadRunning;

updatesoverview_backup_run = function () {
    mainwpPopup('#updatesoverview-backup-box').getContentEl().html(dateToHMS(new Date()) + ' ' + MainWP.I18n.t('Starting required backup(s)...'));
    jQuery('#updatesoverview-backupnow-close').prop('value', MainWP.I18n.t('Cancel'));
    jQuery('#updatesoverview-backupnow-close').prop('cancel', '1');
    updatesoverview_backup_run_next();
};

updatesoverview_backup_run_next = function () {
    let backupContentEl = mainwpPopup('#updatesoverview-backup-box').getContentEl();
    if (updatesoverviewBackupSites.length == 0) {
        appendToDiv(backupContentEl, MainWP.I18n.t('Required backup(s) completed') + (updatesoverviewBackupError ? ' <span class="mainwp-red">' + MainWP.I18n.t('with errors') + '</span>' : '') + '.');

        jQuery('#updatesoverview-backupnow-close').prop('cancel', '0');
        if (updatesoverviewBackupError) {
            jQuery('#updatesoverview-backupnow-close').prop('value', MainWP.I18n.t('Continue update anyway'));
        } else {
            jQuery('#updatesoverview-backupnow-close').prop('value', MainWP.I18n.t('Continue update'));
        }
        return;
    }

    let siteName = updatesoverviewBackupSites[0]['name'];
    appendToDiv(backupContentEl, '[' + siteName + '] ' + MainWP.I18n.t('Creating backup file...'));

    let siteId = updatesoverviewBackupSites[0]['id'];
    updatesoverviewBackupSites.shift();
    let data = mainwp_secure_data({
        action: 'mainwp_backup_run_site',
        site_id: siteId
    });

    jQuery.post(ajaxurl, data, function (pSiteId, pSiteName) {
        return function (response) {
            if (response.error) {
                appendToDiv(backupContentEl, '[' + pSiteName + '] ' + mainwp_backup_error_html(response.error));
                updatesoverviewBackupError = true;
                updatesoverview_backup_run_next();
            } else {
                appendToDiv(backupContentEl, '[' + pSiteName + '] ' + MainWP.I18n.t('Backup file created successfully!'));

                updatesoverview_backupnow_download_file(pSiteId, pSiteName, response.result.type, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder);
            }

        }
    }(siteId, siteName), 'json');
};
updatesoverview_backupnow_download_file = function (pSiteId, pSiteName, type, url, file, regexfile, size, subfolder) {
    let backupContentEl = mainwpPopup('#updatesoverview-backup-box').getContentEl();
    appendToDiv(backupContentEl, '[' + pSiteName + '] Downloading the file... <div id="updatesoverview-backupnow-status-progress" siteId="' + pSiteId + '" class="ui green progress"><div class="bar"><div class="progress"></div></div>');
    jQuery('#updatesoverview-backupnow-status-progress[siteId="' + pSiteId + '"]').progress({ value: 0, total: size });
    let interVal = setInterval(function () {
        let data = mainwp_secure_data({
            action: 'mainwp_backup_getfilesize',
            site_id: pSiteId,
            local: file
        });
        jQuery.post(ajaxurl, data, function (pSiteId) {
            return function (response) {
                if (response.error)
                    return;

                if (updatesoverviewBackupDownloadRunning) {
                    let progressBar = jQuery('#updatesoverview-backupnow-status-progress[siteId="' + pSiteId + '"]');
                    if (progressBar.progress('get value') < progressBar.progress('get total')) {
                        progressBar.progress('set progress', response.result);
                    }
                }
            }
        }(pSiteId), 'json');
    }, 500);

    let data = mainwp_secure_data({
        action: 'mainwp_backup_download_file',
        site_id: pSiteId,
        type: type,
        url: url,
        local: file
    });
    updatesoverviewBackupDownloadRunning = true;
    jQuery.post(ajaxurl, data, function (pFile, pRegexFile, pSubfolder, pSize, pType, pInterVal, pSiteName, pSiteId, pUrl) {
        return function (response) {
            updatesoverviewBackupDownloadRunning = false;
            clearInterval(pInterVal);

            if (response.error) {
                appendToDiv(backupContentEl, '[' + pSiteName + '] ' + mainwp_backup_error_html(response.error));
                appendToDiv(backupContentEl, '[' + pSiteName + '] <span class="mainwp-red">' + MainWP.I18n.t('Backup failed!') + '</span>');

                updatesoverviewBackupError = true;
                updatesoverview_backup_run_next();
                return;
            }

            jQuery('#updatesoverview-backupnow-status-progress[siteId="' + pSiteId + '"]').progress('set progress', pSize);
            appendToDiv(backupContentEl, '[' + pSiteName + '] ' + MainWP.I18n.t('Download from the child site completed.'));
            appendToDiv(backupContentEl, '[' + pSiteName + '] ' + MainWP.I18n.t('Backup completed.'));

            let newData = mainwp_secure_data({
                action: 'mainwp_backup_delete_file',
                site_id: pSiteId,
                file: pUrl
            });
            jQuery.post(ajaxurl, newData, function () { }, 'json');

            updatesoverview_backup_run_next();
        }
    }(file, regexfile, subfolder, size, type, interVal, pSiteName, pSiteId, url), 'json');
};
