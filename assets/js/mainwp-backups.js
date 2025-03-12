
/**
 * Backup
 */
let backupDownloadRunning = false;
let backupError = false;
let backupContinueRetries = 0;
let backupContinueRetriesUnique = [];

jQuery(function () {
    jQuery('#backup_btnSubmit').on('click', function () {
        backup();
    });
    jQuery('#managesite-backup-status-close').on('click', function () {
        backupDownloadRunning = false;
        mainwpPopup('#managesite-backup-status-box').close(true);
    });

});
let backup = function () {
    backupError = false;
    backupContinueRetries = 0;

    jQuery('#backup_loading').show();
    let remote_destinations = jQuery('#backup_location_remote').hasClass('mainwp_action_down') ? jQuery.map(jQuery('#backup_destination_list').find('input[name="remote_destinations[]"]'), function (el) {
        return { id: jQuery(el).val(), title: jQuery(el).attr('title'), type: jQuery(el).attr('destination_type') };
    }) : [];

    let type = jQuery('#mainwp-backup-type').val();
    let size = jQuery('#backup_site_' + type + '_size').val();
    if (type == 'full') {
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

    mainwpPopup('#managesite-backup-status-box').getContentEl().html(dateToHMS(new Date()) + ' ' + __('Creating the backup file on the child site, this might take a while depending on the size. Please be patient.') + ' <div id="managesite-createbackup-status-progress" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>');
    jQuery('#managesite-createbackup-status-progress').progress({ value: 0, total: size });

    mainwpPopup('#managesite-backup-status-box').init({
        callback: function () {
            if (!backupError) {
                location.reload();
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

                    appendToDiv(backsprocessContentEl, __('Backup file on child site created successfully!'));

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
                appendToDiv(backsprocessContentEl, __('Backup file on child site created successfully!'));

                backup_download_file(siteId, type, response.result.file, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, remoteDestinations);
            } else if (response.status == 'stalled') {
                backupContinueRetries++;

                if (backupContinueRetries > 15) {
                    if (responseError != undefined) {
                        appendToDiv(backsprocessContentEl, ' <span class="mainwp-red">ERROR: ' + getErrorMessage(responseError) + '</span>');
                    } else {
                        appendToDiv(backsprocessContentEl, ' <span class="mainwp-red">ERROR: Backup timed out! (stalled) - <a href="https://mainwp.com/help/docs/mainwp-introduction/resolving-system-requirement-issues/">Please check this help document for more information and possible fixes</a></span>'); // NOSONAR - noopener - open safe.
                    }
                } else {
                    appendToDiv(backsprocessContentEl, ' Backup stalled, trying to resume from last file...');
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

                                    appendToDiv(backsprocessContentEl, __('Backupfile on child site created successfully.'));

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
                    appendToDiv(backsprocessContentEl, ' <span class="mainwp-red">ERROR: ' + getErrorMessage(responseError) + '</span>');
                } else {
                    appendToDiv(backsprocessContentEl, ' <span class="mainwp-red">ERROR: Backup timed out! (invalid) - <a href="https://mainwp.com/help/docs/mainwp-introduction/resolving-system-requirement-issues/">Please check this help document for more information and possible fixes</a></span>'); // NOSONAR - noopener - open safe.
                }
            } else {
                // busy or other.
                //Try again in 5seconds
                setTimeout(function () {
                    backup_retry_fail(siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError);
                }, 10000);
            }
        },
        error: function () {
            //Try again in 10seconds
            setTimeout(function () {
                backup_retry_fail(siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError);
            }, 10000);
        },
        dataType: 'json'
    });
};

backup_download_file = function (pSiteId, type, url, file, regexfile, size, subfolder, remote_destinations) {
    let backsprocessContentEl = mainwpPopup('#managesite-backup-status-box').getContentEl();
    appendToDiv(backsprocessContentEl, __('Downloading the file.') + ' <div id="managesite-backup-status-progress" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>');
    jQuery('#managesite-backup-status-progress').progress({ value: 0, total: size });

    let fnc = function (file) {
        return function (pFunction) {
            let data = mainwp_secure_data({
                action: 'mainwp_backup_getfilesize',
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
                    appendToDiv(backsprocessContentEl, '<span class="mainwp-red">ERROR: ' + getErrorMessage(response.error) + '</span>');
                    appendToDiv(backsprocessContentEl, '<span class="mainwp-red">' + __('Backup failed!') + '</span>');

                    jQuery('#managesite-backup-status-close').prop('value', 'Close');
                    return;
                }

                jQuery('#managesite-backup-status-progress').progress('set progress', pSize);
                appendToDiv(backsprocessContentEl, __('Download from child site completed.'));

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
            return function () {
                //Try again in 10seconds
                /*setTimeout(function() {
                 download_retry_fail(pSiteId, pData, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pSize, pType, pUrl);
                 },10000);*/
            }
        }(pSiteId, file, regexfile, subfolder, remote_destinations, size, type, url),
        dataType: 'json'
    });
};

let backupUploadRunning = [];
backup_upload_file = function (pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize) {
    let backsprocessContentEl = mainwpPopup('#managesite-backup-status-box').getContentEl();
    if (pRemoteDestinations.length > 0) {
        let remote_destination = pRemoteDestinations[0];
        //upload..
        let unique = Date.now();
        appendToDiv(backsprocessContentEl, __('Uploading to remote destination: %1 (%2)', remote_destination.title, remote_destination.type) + '<div id="managesite-upload-status-progress-' + unique + '"  class="ui green progress"><div class="bar"><div class="progress"></div></div></div>');

        jQuery('#managesite-upload-status-progress-' + unique).progress({ value: 0, total: pSize });

        let fnc = function (pUnique) {
            return function (pFunction) {
                let data2 = mainwp_secure_data({
                    action: 'mainwp_backup_upload_getprogress',
                    unique: pUnique
                }, false);

                jQuery.ajax({
                    url: ajaxurl,
                    data: data2,
                    method: 'POST',
                    success: function (pFunc) {
                        return function (response) {
                            if (backupUploadRunning[pUnique] && response.error) {
                                setTimeout(function () {
                                    pFunc(pFunc);
                                }, 1000);
                                return;
                            }

                            if (backupUploadRunning[pUnique]) {
                                let progressBar = jQuery('#managesite-upload-status-progress-' + pUnique);
                                if ((progressBar.length > 0) && (progressBar.progress('get value') < progressBar.progress('get total')) && (progressBar.progress('get value') < parseInt(response.result))) {
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
                            if (backupUploadRunning[pUnique]) {
                                setTimeout(function () {
                                    pFunc(pFunc);
                                }, 10000);
                            }
                        }
                    }(pFunction),
                    dataType: 'json'
                });
            }
        }(unique);

        setTimeout(function () {
            fnc(fnc);
        }, 1000);

        backupUploadRunning[unique] = true;

        let data = mainwp_secure_data({
            action: 'mainwp_backup_upload_file',
            file: pFile,
            siteId: pSiteId,
            regexfile: pRegexFile,
            subfolder: pSubfolder,
            type: pType,
            remote_destination: remote_destination.id,
            unique: unique
        });

        pRemoteDestinations.shift();
        jQuery.ajax({
            type: 'POST',
            url: ajaxurl,
            data: data,
            success: function (pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pUnique, pRemoteDestId) {
                return function (response) {
                    if (!response || response.error || !response.result) {
                        backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, response && response.error ? response.error : '');
                    } else {
                        backupUploadRunning[pUnique] = false;

                        let progressBar = jQuery('#managesite-upload-status-progress-' + pUnique);
                        progressBar.progress('set progress', pSize);

                        let obj = response.result;
                        if (obj.error) {
                            backupError = true;
                            appendToDiv(backsprocessContentEl, '<span class="mainwp-red">' + __('Upload to %1 (%2) failed:', obj.title, obj.type) + ' ' + obj.error + '</span>');
                        } else {
                            appendToDiv(backsprocessContentEl, __('Upload to %1 (%2) successful!', obj.title, obj.type));
                        }

                        backup_upload_file(pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
                    }
                }
            }(pSiteId, pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, data, unique, remote_destination.id),
            error: function (pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pUnique, pRemoteDestId) {
                return function () {
                    backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId);
                }
            }(pSiteId, pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, data, unique, remote_destination.id),
            dataType: 'json'
        });
    } else {
        appendToDiv(backsprocessContentEl, __('Backup completed!'));
        jQuery('#managesite-backup-status-close').prop('value', 'Close');
        if (!backupError) {
            setTimeout(function () {
                mainwpPopup('#managesite-backup-status-box').close(true);
            }, 3000);
        }
        return;
    }
};

backup_upload_file_retry_fail = function (pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError) {
    let backsprocessContentEl = mainwpPopup('#managesite-backup-status-box').getContentEl();
    //we've got the pid file!!!!
    let data = mainwp_secure_data({
        action: 'mainwp_backup_upload_checkstatus',
        unique: pUnique,
        remote_destination: pRemoteDestId
    });

    jQuery.ajax({
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function (response) {
            if (response.status == 'done') {
                backupUploadRunning[pUnique] = false;

                let progressBar = jQuery('#managesite-upload-status-progress-' + pUnique);
                progressBar.progress('set progress', pSize);

                appendToDiv(backsprocessContentEl, __('Upload to %1 (%2) successful!', response.info.title, response.info.type));

                backup_upload_file(pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
            } else if (response.status == 'stalled') {
                if (backupContinueRetriesUnique[pUnique] == undefined) {
                    backupContinueRetriesUnique[pUnique] = 1;
                } else {
                    backupContinueRetriesUnique[pUnique]++;
                }

                if (backupContinueRetriesUnique[pUnique] > 10) {
                    if (responseError != undefined) {
                        backupError = true;
                        appendToDiv(backsprocessContentEl, '<span class="mainwp-red">' + __('Upload to %1 (%2) failed:', response.info.title, response.info.type) + ' ' + responseError + '</span>');
                    } else {
                        appendToDiv(backsprocessContentEl, ' <span class="mainwp-red">ERROR: Upload timed out! <a href="http://docs.mainwp.com/backup-failed-php-ini-settings/">Please check this help document for more information and possible fixes</a></span>');
                    }

                    backup_upload_file(pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
                } else {
                    appendToDiv(backsprocessContentEl, ' Upload stalled, trying to resume from last position...');

                    pData = mainwp_secure_data(pData); //Rescure

                    jQuery.ajax({
                        url: ajaxurl,
                        data: pData,
                        method: 'POST',
                        success: function (pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pRemoteDestId) {
                            return function (response) {
                                if (response.error || !response.result) {
                                    backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, response.error ? response.error : '');
                                } else {
                                    backupUploadRunning[pUnique] = false;

                                    let progressBar = jQuery('#managesite-upload-status-progress-' + pUnique);
                                    progressBar.progress('set progress', pSize);

                                    let obj = response.result;
                                    if (obj.error) {
                                        backupError = true;
                                        appendToDiv(backsprocessContentEl, '<span class="mainwp-red">' + __('Upload to %1 (%2) failed:', obj.title, obj.type) + ' ' + obj.error + '</span>');
                                    } else {
                                        appendToDiv(backsprocessContentEl, __('Upload to %1 (%2) successful!', obj.title, obj.type));
                                    }

                                    backup_upload_file(pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
                                }
                            }
                        }(pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pRemoteDestId),
                        error: function (pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pRemoteDestId) {
                            return function () {
                                backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId);
                            }
                        }(pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pRemoteDestId),
                        dataType: 'json'
                    });
                }
            } else {
                // busy or other.
                //Try again in 5seconds
                setTimeout(function () {
                    backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
                }, 10000);
            }
        },
        error: function () {
            //Try again in 10seconds
            setTimeout(function () {
                backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
            }, 10000);
        },
        dataType: 'json'
    });
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
        mainwpPopup('#managebackups-task-status-box').close(true);
    });
    managebackups_init();

});
managebackups_exclude_folder = function (pElement) {
    let folder = pElement.parent().attr('rel') + "\n";
    if (jQuery('#excluded_folders_list').val().indexOf(folder) !== -1)
        return;

    jQuery('#excluded_folders_list').val(jQuery('#excluded_folders_list').val() + folder);
};

let manageBackupsError = false;
let manageBackupsTaskRemoteDestinations;
let manageBackupsTaskId;
let manageBackupsTaskType;
let manageBackupsTaskError;
managebackups_run_now = function (el) {
    el = jQuery(el);
    el.hide();
    el.parent().find('.backup_run_loading').show();
    mainwpPopup('#managebackups-task-status-box').getContentEl().html(dateToHMS(new Date()) + ' ' + __('Starting the backup task...'));
    jQuery('#managebackups-task-status-close').prop('value', __('Cancel'));
    mainwpPopup('#managebackups-task-status-box').init({
        title: __('Running task'), callback: function () {
            location.reload();
        }
    });

    let taskId = el.attr('task_id');
    let taskType = el.attr('task_type');
    //Fetch the sites to backup
    let data = mainwp_secure_data({
        action: 'mainwp_backuptask_get_sites',
        task_id: taskId
    });

    manageBackupsError = false;

    jQuery.post(ajaxurl, data, function (pTaskId, pTaskType) {
        return function (response) {
            manageBackupTaskSites = response.result.sites;
            manageBackupsTaskRemoteDestinations = response.result.remoteDestinations;
            manageBackupsTaskId = pTaskId;
            manageBackupsTaskType = pTaskType;
            manageBackupsTaskError = false;

            managebackups_run_next();
        }
    }(taskId, taskType), 'json');
};
managebackups_run_next = function () {
    let backtaskContentEl = mainwpPopup('#managebackups-task-status-box').getContentEl();
    if (manageBackupTaskSites.length == 0) {
        appendToDiv(backtaskContentEl, __('Backup task completed') + (manageBackupsTaskError ? ' <span class="mainwp-red">' + __('with errors') + '</span>' : '') + '.');

        jQuery('#managebackups-task-status-close').prop('value', __('Close'));
        if (!manageBackupsError) {
            setTimeout(function () {
                mainwpPopup('#managebackups-task-status-box').close(true);
            }, 3000);
        }
        return;
    }

    let siteId = manageBackupTaskSites[0]['id'];
    let siteName = manageBackupTaskSites[0]['name'];
    let size = manageBackupTaskSites[0][manageBackupsTaskType + 'size'];
    let fileNameUID = mainwp_uid();
    appendToDiv(backtaskContentEl, '[' + siteName + '] ' + __('Creating backup file.') + '<div id="managebackups-task-status-create-progress" siteId="' + siteId + '" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>');

    manageBackupTaskSites.shift();
    let data = mainwp_secure_data({
        action: 'mainwp_backuptask_run_site',
        task_id: manageBackupsTaskId,
        site_id: siteId,
        fileNameUID: fileNameUID
    });

    jQuery('#managebackups-task-status-create-progress[siteId="' + siteId + '"]').progress({ value: 0, total: size });
    let interVal = setInterval(function () {
        let data = mainwp_secure_data({
            action: 'mainwp_createbackup_getfilesize',
            type: manageBackupsTaskType,
            siteId: siteId,
            fileName: '',
            fileNameUID: fileNameUID
        });
        jQuery.post(ajaxurl, data, function (pSiteId) {
            return function (response) {
                if (response.error)
                    return;

                if (backupCreateRunning) {
                    let progressBar = jQuery('#managebackups-task-status-create-progress[siteId="' + pSiteId + '"]');
                    if (progressBar.progress('get value') < progressBar.progress('get total')) {
                        progressBar.progress('set progress', response.size);
                    }
                }
            }
        }(siteId), 'json');
    }, 1000);

    backupCreateRunning = true;

    jQuery.ajax({
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function (pTaskId, pSiteId, pSiteName, pRemoteDestinations, pInterVal) {
            return function (response) {
                backupCreateRunning = false;
                clearInterval(pInterVal);

                let progressBar = jQuery('#managebackups-task-status-create-progress[siteId="' + pSiteId + '"]');
                progressBar.progress('set progress', parseFloat(progressBar.progress('get total')));

                if (response.error) {
                    appendToDiv(backtaskContentEl, '[' + pSiteName + '] <span class="mainwp-red">Error: ' + getErrorMessage(response.error) + '</span>');
                    manageBackupsTaskError = true;
                    managebackups_run_next();
                } else {
                    appendToDiv(backtaskContentEl, '[' + pSiteName + '] ' + __('Backup file created successfully.'));

                    managebackups_backup_download_file(pSiteId, pSiteName, response.result.type, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, pRemoteDestinations);
                }
            }
        }(manageBackupsTaskId, siteId, siteName, manageBackupsTaskRemoteDestinations.slice(0), interVal),
        error: function (pInterVal, pSiteName) {
            return function () {
                backupCreateRunning = false;
                clearInterval(pInterVal);
                appendToDiv(backtaskContentEl, '[' + pSiteName + '] ' + '<span class="mainwp-red">ERROR: Backup timed out (request) - <a href="https://mainwp.com/help/docs/mainwp-introduction/resolving-system-requirement-issues/">Please check this help document for more information and possible fixes</a></span>'); // NOSONAR - noopener - open safe.
            }
        }(interVal, siteName), dataType: 'json'
    });
};

let managebackups_backup_download_file = function (pSiteId, pSiteName, type, url, file, regexfile, size, subfolder, remote_destinations) {
    let backtaskContentEl = mainwpPopup('#managebackups-task-status-box').getContentEl();
    appendToDiv(backtaskContentEl, '[' + pSiteName + '] Downloading the file. <div id="managebackups-task-status-progress" siteId="' + pSiteId + '" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>');
    jQuery('#managebackups-task-status-progress[siteId="' + pSiteId + '"]').progress({ value: 0, total: size });
    let interVal = setInterval(function () {
        let data = mainwp_secure_data({
            action: 'mainwp_backup_getfilesize',
            local: file
        });
        jQuery.post(ajaxurl, data, function (pSiteId) {
            return function (response) {
                if (response.error)
                    return;

                if (backupDownloadRunning) {
                    let progressBar = jQuery('#managebackups-task-status-progress[siteId="' + pSiteId + '"]');
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
    backupDownloadRunning = true;
    jQuery.post(ajaxurl, data, function (pFile, pRegexFile, pSubfolder, pRemoteDestinations, pSize, pType, pInterVal, pSiteName, pSiteId, pUrl) {
        return function (response) {
            backupDownloadRunning = false;
            clearInterval(pInterVal);

            if (response.error) {
                appendToDiv(backtaskContentEl, '[' + pSiteName + '] <span class="mainwp-red">ERROR: ' + getErrorMessage(response.error) + '</span>');
                appendToDiv(backtaskContentEl, '[' + pSiteName + '] <span class="mainwp-red">' + __('Backup failed!') + '</span>');

                manageBackupsError = true;
                managebackups_run_next();
                return;
            }

            jQuery('#managebackups-task-status-progress[siteId="' + pSiteId + '"]').progress('set progress', pSize);
            appendToDiv(backtaskContentEl, '[' + pSiteName + '] ' + __('Download from child site completed.'));


            let newData = mainwp_secure_data({
                action: 'mainwp_backup_delete_file',
                site_id: pSiteId,
                file: pUrl
            });
            jQuery.post(ajaxurl, newData, function () { }, 'json');

            managebackups_backup_upload_file(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize);
        }
    }(file, regexfile, subfolder, remote_destinations, size, type, interVal, pSiteName, pSiteId, url), 'json');
};

managebackups_backup_upload_file = function (pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize) {
    let backtaskContentEl = mainwpPopup('#managebackups-task-status-box').getContentEl();
    if (pRemoteDestinations.length > 0) {
        let remote_destination = pRemoteDestinations[0];
        //upload..
        let unique = Date.now();
        appendToDiv(backtaskContentEl, '[' + pSiteName + '] ' + __('Uploading to selected remote destination: %1 (%2)', remote_destination.title, remote_destination.type) + '<div id="managesite-upload-status-progress-' + unique + '" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>');

        jQuery('#managesite-upload-status-progress-' + unique).progress({ value: 0, total: pSize });

        let fnc = function (pUnique) {
            return function (pFunction) {
                let data2 = mainwp_secure_data({
                    action: 'mainwp_backup_upload_getprogress',
                    unique: pUnique
                }, false);

                jQuery.ajax({
                    url: ajaxurl,
                    data: data2,
                    method: 'POST',
                    success: function (pFunc) {
                        return function (response) {
                            if (backupUploadRunning[pUnique] && response.error) {
                                setTimeout(function () {
                                    pFunc(pFunc);
                                }, 1000);
                                return;
                            }

                            if (backupUploadRunning[pUnique]) {
                                let progressBar = jQuery('#managesite-upload-status-progress-' + pUnique);
                                if ((progressBar.length > 0) && (progressBar.progress('get value') < progressBar.progress('get total')) && (progressBar.progress('get value') < parseInt(response.result))) {
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
                            if (backupUploadRunning[pUnique]) {
                                setTimeout(function () {
                                    pFunc(pFunc);
                                }, 10000);
                            }
                        }
                    }(pFunction),
                    dataType: 'json'
                });
            }
        }(unique);

        setTimeout(function () {
            fnc(fnc);
        }, 1000);

        backupUploadRunning[unique] = true;

        let data = mainwp_secure_data({
            action: 'mainwp_backup_upload_file',
            file: pFile,
            siteId: pSiteId,
            regexfile: pRegexFile,
            subfolder: pSubfolder,
            type: pType,
            remote_destination: remote_destination.id,
            unique: unique
        });

        pRemoteDestinations.shift();
        jQuery.ajax({
            type: 'POST',
            url: ajaxurl,
            data: data,
            success: function (pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId) {
                return function (response) {
                    if (!response || response.error || !response.result) {
                        managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, response.error ? response.error : '');
                    } else {
                        backupUploadRunning[pUnique] = false;

                        let progressBar = jQuery('#managesite-upload-status-progress-' + pUnique);
                        progressBar.progress('set progress', pSize);

                        let obj = response.result;
                        if (obj.error) {
                            manageBackupsError = true;
                            appendToDiv(backtaskContentEl, '<span class="mainwp-red">[' + pSiteName + '] ' + __('Upload to %1 (%2) failed:', obj.title, obj.type) + ' ' + obj.error + '</span>');
                        } else {
                            appendToDiv(backtaskContentEl, '[' + pSiteName + '] ' + __('Upload to %1 (%2) successful', obj.title, obj.type));
                        }

                        managebackups_backup_upload_file(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
                    }
                }
            }(pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, data, unique, remote_destination.id),
            error: function (pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId) {
                return function () {
                    managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId);
                }
            }(pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, data, unique, remote_destination.id),
            dataType: 'json'
        });
    } else {
        appendToDiv(backtaskContentEl, '[' + pSiteName + '] ' + __('Backup completed.'));
        managebackups_run_next();
    }
};

managebackups_backup_upload_file_retry_fail = function (pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError) {
    let backtaskContentEl = mainwpPopup('#managebackups-task-status-box').getContentEl();
    //we've got the pid file!!!!
    let data = mainwp_secure_data({
        action: 'mainwp_backup_upload_checkstatus',
        unique: pUnique,
        remote_destination: pRemoteDestId
    });

    jQuery.ajax({
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function (response) {
            if (response.status == 'done') {
                backupUploadRunning[pUnique] = false;

                let progressBar = jQuery('#managesite-upload-status-progress-' + pUnique);
                progressBar.progress('set progress', pSize);

                appendToDiv(backtaskContentEl, '[' + pSiteName + '] ' + __('Upload to %1 (%2) successful', response.info.title, response.info.type));

                managebackups_backup_upload_file(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
            } else if (response.status == 'busy') {
                //Try again in 10seconds
                setTimeout(function () {
                    managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
                }, 10000);
            } else if (response.status == 'stalled') {
                if (backupContinueRetriesUnique[pUnique] == undefined) {
                    backupContinueRetriesUnique[pUnique] = 1;
                } else {
                    backupContinueRetriesUnique[pUnique]++;
                }

                if (backupContinueRetriesUnique[pUnique] > 10) {
                    if (responseError != undefined) {
                        manageBackupsError = true;
                        appendToDiv(backtaskContentEl, '<span class="mainwp-red">[' + pSiteName + '] ' + __('Upload to %1 (%2) failed:', response.info.title, response.info.type) + ' ' + responseError + '</span>');
                    } else {
                        appendToDiv(backtaskContentEl, ' <span class="mainwp-red">[' + pSiteName + '] ERROR: Upload timed out - <a href="http://docs.mainwp.com/backup-failed-php-ini-settings/">Please check this help document for more information and possible fixes</a></span>');
                    }

                    managebackups_backup_upload_file(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
                } else {
                    appendToDiv(backtaskContentEl, ' [' + pSiteName + '] Upload stalled, trying to resume from last position.');

                    pData = mainwp_secure_data(pData); //Rescure

                    jQuery.ajax({
                        url: ajaxurl,
                        data: pData,
                        method: 'POST',
                        success: function (pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId) {
                            return function (response) {
                                if (response.error || !response.result) {
                                    managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, response.error ? response.error : '');
                                } else {
                                    backupUploadRunning[pUnique] = false;

                                    let progressBar = jQuery('#managesite-upload-status-progress-' + pUnique);
                                    progressBar.progress('set progress', pSize);

                                    let obj = response.result;
                                    if (obj.error) {
                                        manageBackupsError = true;
                                        appendToDiv(backtaskContentEl, '<span class="mainwp-red">[' + pSiteName + '] ' + __('Upload to %1 (%2) failed:', obj.title, obj.type) + ' ' + obj.error + '</span>');
                                    } else {
                                        appendToDiv(backtaskContentEl, '[' + pSiteName + '] ' + __('Upload to %1 (%2) successful', obj.title, obj.type));
                                    }

                                    managebackups_backup_upload_file(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
                                }
                            }
                        }(pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId),
                        error: function (pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId) {
                            return function () {
                                managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
                            }
                        }(pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId),
                        dataType: 'json'
                    });
                }
            } else {
                //Try again in 5seconds
                setTimeout(function () {
                    managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
                }, 10000);
            }
        },
        error: function () {
            //Try again in 10seconds
            setTimeout(function () {
                managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
            }, 10000);
        },
        dataType: 'json'
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
        errors.push(__('Please enter a valid name for your backup task'));
    }
    let selected_groups = [];
    let selected_sites = [];
    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function () {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push(__('Please select websites or groups to add a backup task.'));
        }
    } else {
        jQuery("input[name='selected_groups[]']:checked").each(function () {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push(__('Please select websites or groups to add a backup task.'));
        }
    }

    if (errors.length > 0) {
        feedback('mainwp-message-zone', errors.join('<br />'), 'red');
    } else {
        feedback('mainwp-message-zone', __('Adding the task...'), 'green');

        jQuery('#mainwp_managebackups_update').attr('disabled', 'true'); //disable button to add..

        //let loadFilesBeforeZip = jQuery( '[name="mainwp_options_loadFilesBeforeZip"]:checked' ).val();
        let name = jQuery('#mainwp_managebackups_add_name').val();
        name = name.replace(/"/g, '&quot;');
        let data = mainwp_secure_data({
            action: 'mainwp_updatebackup',
            id: jQuery('#mainwp_managebackups_edit_id').val(),
            name: name,
            schedule: jQuery('#mainwp-backup-task-schedule').val(),
            type: jQuery('#mainwp-backup-type').val(),
            exclude: (jQuery('#mainwp-backup-type').val() == 'full' ? jQuery('#excluded_folders_list').val() : ''),
            excludebackup: (jQuery('#mainwp-known-backup-locations').attr('checked') ? 1 : 0),
            excludecache: (jQuery('#mainwp-known-cache-locations').attr('checked') ? 1 : 0),
            excludenonwp: (jQuery('#mainwp-non-wordpress-folders').attr('checked') ? 1 : 0),
            excludezip: (jQuery('#mainwp-zip-archives').attr('checked') ? 1 : 0),
            'groups[]': selected_groups,
            'sites[]': selected_sites,
            subfolder: jQuery('#mainwp_managebackups_add_subfolder').val(),
            filename: jQuery('#backup_filename').val(),
            archiveFormat: jQuery('#mainwp_archiveFormat').val(),
            maximumFileDescriptorsOverride: jQuery('#mainwp_options_maximumFileDescriptorsOverride_override').is(':checked') ? 1 : 0,
            maximumFileDescriptorsAuto: (jQuery('#mainwp_maximumFileDescriptorsAuto').attr('checked') ? 1 : 0),
            maximumFileDescriptors: jQuery('#mainwp_options_maximumFileDescriptors').val(),
            //loadFilesBeforeZip: loadFilesBeforeZip
        });
        jQuery.post(ajaxurl, data, function (response) {
            managebackups_init();
            if (response.error != undefined) {
                feedback('mainwp-message-zone', response.error, 'red');
            } else {
                //Message the backup task was added
                feedback('mainwp-message-zone', response.result, 'green');
            }

            jQuery('#mainwp_managebackups_update').prop("disabled", false); //Enable add button
        }, 'json');
    }
};
mainwp_managebackups_add = function () {
    managebackups_init();

    let errors = [];
    if (jQuery('#mainwp_managebackups_add_name').val() == '') {
        errors.push(__('Please enter a valid name for your backup task'));
    }
    let selected_sites = [];
    let selected_groups = [];
    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function () {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push(__('Please select websites or groups.'));
        }
    } else {
        jQuery("input[name='selected_groups[]']:checked").each(function () {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push(__('Please select websites or groups.'));
        }
    }

    console.log(errors);

    if (errors.length > 0) {
        feedback('mainwp-message-zone', errors.join('<br />'), 'red');
    } else {
        feedback('mainwp-message-zone', __('Adding the task...'), 'green');

        jQuery('#mainwp_managebackups_add').attr('disabled', 'true'); //disable button to add..

        jQuery('#mainwp_managesites_add').attr('disabled', 'true'); //Disable add button
        let loadFilesBeforeZip = jQuery('[name="mainwp_options_loadFilesBeforeZip"]:checked').val();
        let name = jQuery('#mainwp_managebackups_add_name').val();
        name = name.replace(/"/g, '&quot;');
        let data = mainwp_secure_data({
            action: 'mainwp_addbackup',
            name: name,
            schedule: jQuery('#mainwp-backup-task-schedule').val(),
            type: jQuery('#mainwp-backup-type').val(),
            exclude: (jQuery('#mainwp-backup-type').val() == 'full' ? jQuery('#excluded_folders_list').val() : ''),
            excludebackup: (jQuery('#mainwp-known-backup-locations').attr('checked') ? 1 : 0),
            excludecache: (jQuery('#mainwp-known-cache-locations').attr('checked') ? 1 : 0),
            excludenonwp: (jQuery('#mainwp-non-wordpress-folders').attr('checked') ? 1 : 0),
            excludezip: (jQuery('#mainwp-zip-archives').attr('checked') ? 1 : 0),
            'groups[]': selected_groups,
            'sites[]': selected_sites,
            subfolder: jQuery('#mainwp_managebackups_add_subfolder').val(),
            filename: jQuery('#backup_filename').val(),
            archiveFormat: jQuery('#mainwp_archiveFormat').val(),
            maximumFileDescriptorsOverride: jQuery('#mainwp_options_maximumFileDescriptorsOverride_override').is(':checked') ? 1 : 0,
            maximumFileDescriptorsAuto: (jQuery('#mainwp_maximumFileDescriptorsAuto').attr('checked') ? 1 : 0),
            maximumFileDescriptors: jQuery('#mainwp_options_maximumFileDescriptors').val(),
            loadFilesBeforeZip: loadFilesBeforeZip
        });
        jQuery.post(ajaxurl, data, function (response) {
            managebackups_init();
            if (response.error != undefined) {
                feedback('mainwp-message-zone', response.error, 'red');
            } else {
                //Message the backup task was added
                location.href = 'admin.php?page=ManageBackups&a=1';
                feedback('mainwp-message-zone', response.result, 'green');
            }
            jQuery('#mainwp_managebackups_add').prop("disabled", false); //Enable add button
        }, 'json');
    }
};
managebackups_remove = function (element) {
    let id = jQuery(element).attr('task_id');
    managebackups_init();

    let msg = __('Are you sure you want to delete this backup task?');
    mainwp_confirm(msg, function () {
        jQuery('#task-status-' + id).html(__('Removing the task...'));
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
                    result = __('The task has been removed.');
                } else {
                    error = __('An undefined error occured.');
                }

                if (error != '') {
                    setHtml('#mainwp_managebackups', error);
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

    jQuery('#task-status-' + id).html(__('Resuming the task...'));
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
                result = __('The task has been resumed.');
            } else {
                error = __('An undefined error occured.');
            }

            if (error != '') {
                setHtml('#mainwp_managebackups', error);
            }
            if (result != '') {
                setHtml('#mainwp_managebackups_add_message', result);
            }
            jQuery('#task-status-' + id).html('');

            if (error == '') {
                jQuery(pElement).after('<a href="#" task_id="' + pId + '" onClick="return managebackups_pause(this)">' + __('Pause') + '</a>');
                jQuery(pElement).remove();
            }
        }
    }(element, id), 'json');

    return false;
};
managebackups_pause = function (element) {
    let id = jQuery(element).attr('task_id');
    managebackups_init();

    jQuery('#task-status-' + id).html(__('Pausing the task...'));
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
                result = __('The task has been paused.');
            } else {
                error = __('An undefined error occured.');
            }

            if (error != '') {
                setHtml('#mainwp_managebackups', error);
            }
            if (result != '') {
                setHtml('#mainwp_managebackups_add_message', result);
            }
            jQuery('#task-status-' + id).html('');
            if (error == '') {
                jQuery(pElement).after('<a href="#" task_id="' + pId + '" onClick="return managebackups_resume(this)">' + __('Resume') + '</a>');
                jQuery(pElement).remove();
            }
        }
    }(element, id), 'json');

    return false;
};


jQuery(document).on('click', '#updatesoverview-backup-ignore', function () {
    if (updatesoverviewContinueAfterBackup != undefined) {
        mainwpPopup('#updatesoverview-backup-box').close();
        console.log(updatesoverviewContinueAfterBackup);
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

        let output = __('Checking if a backup is required for the selected updates...');
        mainwpPopup('#updatesoverview-backup-box').getContentEl().html(output);
        jQuery('#updatesoverview-backup-all').hide();
        jQuery('#updatesoverview-backup-ignore').hide();

        mainwpPopup('#updatesoverview-backup-box').init({
            title: __("Checking backup settings..."), callback: function () {
                window.location.href = location.href
            }
        });
    };

    updatesoverviewShowBusyTimeout = setTimeout(updatesoverviewShowBusyFunction, 300);

    //Step 2: Check if backups are ok.
    let data = mainwp_secure_data({
        action: 'mainwp_checkbackups',
        sites: sitesToUpdate
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
                        jQuery('#updatesoverview-backup-ignore').val(__('Proceed with Updates')).show();
                    }

                    let output = '<span class="mainwp-red">' + __('A full backup has not been taken in the last days for the following sites:') + '</span><br /><br />';

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
                        title: __("Full backup required!"), callback: function () {
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
    mainwpPopup('#updatesoverview-backup-box').setActionButtons('<input id="updatesoverview-backupnow-close" type="button" name="Ignore" value="' + __('Cancel') + '" class="button"/>');
    mainwpPopup('#updatesoverview-backup-box').init({
        title: __("Full backup"), callback: function () {
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
    mainwpPopup('#updatesoverview-backup-box').getContentEl().html(dateToHMS(new Date()) + ' ' + __('Starting required backup(s)...'));
    jQuery('#updatesoverview-backupnow-close').prop('value', __('Cancel'));
    jQuery('#updatesoverview-backupnow-close').prop('cancel', '1');
    updatesoverview_backup_run_next();
};

updatesoverview_backup_run_next = function () {
    let backupContentEl = mainwpPopup('#updatesoverview-backup-box').getContentEl();
    if (updatesoverviewBackupSites.length == 0) {
        appendToDiv(backupContentEl, __('Required backup(s) completed') + (updatesoverviewBackupError ? ' <span class="mainwp-red">' + __('with errors') + '</span>' : '') + '.');

        jQuery('#updatesoverview-backupnow-close').prop('cancel', '0');
        if (updatesoverviewBackupError) {
            jQuery('#updatesoverview-backupnow-close').prop('value', __('Continue update anyway'));
        } else {
            jQuery('#updatesoverview-backupnow-close').prop('value', __('Continue update'));
        }
        return;
    }

    let siteName = updatesoverviewBackupSites[0]['name'];
    appendToDiv(backupContentEl, '[' + siteName + '] ' + __('Creating backup file...'));

    let siteId = updatesoverviewBackupSites[0]['id'];
    updatesoverviewBackupSites.shift();
    let data = mainwp_secure_data({
        action: 'mainwp_backup_run_site',
        site_id: siteId
    });

    jQuery.post(ajaxurl, data, function (pSiteId, pSiteName) {
        return function (response) {
            if (response.error) {
                appendToDiv(backupContentEl, '[' + pSiteName + '] <span class="mainwp-red">ERROR: ' + getErrorMessage(response.error) + '</span>');
                updatesoverviewBackupError = true;
                updatesoverview_backup_run_next();
            } else {
                appendToDiv(backupContentEl, '[' + pSiteName + '] ' + __('Backup file created successfully!'));

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
                appendToDiv(backupContentEl, '[' + pSiteName + '] <span class="mainwp-red">ERROR: ' + getErrorMessage(response.error) + '</span>');
                appendToDiv(backupContentEl, '[' + pSiteName + '] <span class="mainwp-red">' + __('Backup failed!') + '</span>');

                updatesoverviewBackupError = true;
                updatesoverview_backup_run_next();
                return;
            }

            jQuery('#updatesoverview-backupnow-status-progress[siteId="' + pSiteId + '"]').progress('set progress', pSize);
            appendToDiv(backupContentEl, '[' + pSiteName + '] ' + __('Download from the child site completed.'));
            appendToDiv(backupContentEl, '[' + pSiteName + '] ' + __('Backup completed.'));

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
