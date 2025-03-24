/* eslint-disable complexity */
// current complexity is the only way to achieve desired results, pull request solutions appreciated.
window.mainwpVars = window.mainwpVars || {};

let ugradingWebsiteAll = false;
let ignoredBackupBeforeUpdate = false;
let ugradingAllCurrentStep = '';
let managesitesContinueAfterBackup;

let managesitesBackupSites;
let managesitesBackupError;
let managesitesBackupDownloadRunning;

mainwpVars.bulkManageSitesTaskRunning = false;

let managesites_update_all_next_step = function () {
    let next = '';
    if ('wpcore' == ugradingAllCurrentStep) {
        next = 'plugin';
    } else if ('plugin' == ugradingAllCurrentStep) {
        next = 'theme';
    } else if ('theme' == ugradingAllCurrentStep) {
        next = 'translation';
    }
    ugradingAllCurrentStep = next;
    return ugradingAllCurrentStep;
}

let mainwp_update_get_selected_sitesids = function (selectedSitesIds, allSitesIds) {
    let selectedIds = [], excludeIds = [];
    if (selectedSitesIds instanceof Array) {
        jQuery.grep(allSitesIds, function (el) {
            if (jQuery.inArray(el, selectedSitesIds) !== -1) {
                selectedIds.push(el);
            } else {
                excludeIds.push(el);
            }
        });
        for (let id of excludeIds) {
            dashboard_update_site_hide(id);
        }
        allSitesIds = selectedIds;
    }
    return allSitesIds;
}
// global variable.
window.mainwp_update_pluginsthemes = function (updateType, updateSiteIds) {
    let allWebsiteIds = jQuery('.dashboard_wp_id[error-status=0]').map(function (indx, el) {
        return jQuery(el).val();
    });
    allWebsiteIds = mainwp_update_get_selected_sitesids(updateSiteIds, allWebsiteIds);
    let nrOfWebsites = allWebsiteIds.length;

    if (nrOfWebsites == 0) {
        managesites_reset_bulk_actions_params();
        return;
    }

    let siteNames = {};

    for (let id of allWebsiteIds) {
        dashboard_update_site_status(id, '<i class="clock outline icon"></i> ' + __('PENDING'));
        siteNames[id] = jQuery('.sync-site-status[siteid="' + id + '"]').attr('niceurl');
    }

    managesitesContinueAfterBackup = function (pType, sitesCount, pAllWebsiteIds) {
        return function () {
            let title = '';
            if (ugradingWebsiteAll) {
                if (pType == 'plugin')
                    title = __("Updating everything: Plugins...");
                else if (pType == 'theme') {
                    title = __("Updating everything: Themes...");
                } else if (pType == 'translation') {
                    title = __("Updating everything: Translations...");
                }
                mainwpPopup('#mainwp-sync-sites-modal').setTitle(title); // popup displayed.
                mainwpPopup('#mainwp-sync-sites-modal').setStatusText('0 / ' + nrOfWebsites + ' ' + __('updated')); // popup displayed.


            } else {
                if (pType == 'plugin')
                    title = __("Updating plugins...");
                else if (pType == 'theme') {
                    title = __("Updating themes...");
                } else if (pType == 'translation') {
                    title = __("Updating translations...");
                }
                let initData = {
                    progressMax: sitesCount,
                    statusText: __('updated'),
                    callback: function () {
                        mainwpVars.bulkManageSitesTaskRunning = false;
                    },
                    allowMultiple: true
                };
                if (title != '')
                    initData.title = title;

                mainwpPopup('#mainwp-sync-sites-modal').init(initData);
            }

            managesites_update_pluginsthemes(pType, pAllWebsiteIds);
            managesitesContinueAfterBackup = undefined;
        }
    }(updateType, nrOfWebsites, allWebsiteIds);

    if (!ignoredBackupBeforeUpdate) {
        mainwp_managesites_checkBackups(allWebsiteIds, siteNames);
    } else if (managesitesContinueAfterBackup != undefined) {
        managesitesContinueAfterBackup();
    }

};

let websitesUpdateError = 0;
let websitesEveryError;

let managesites_update_pluginsthemes = function (pType, websiteIds) {
    mainwpVars.websitesToUpdate = websiteIds;
    mainwpVars.currentWebsite = 0;
    mainwpVars.websitesDone = 0;
    websitesUpdateError = 0;
    websitesEveryError = 0;

    mainwpVars.websitesTotal = mainwpVars.websitesToUpdate.length;
    mainwpVars.websitesLeft = mainwpVars.websitesToUpdate.length;

    mainwpVars.bulkManageSitesTaskRunning = true;

    if (mainwpVars.websitesTotal == 0) {
        managesites_update_pluginsthemes_done(pType);
    } else {
        managesites_loop_pluginsthemes_next(pType);
    }
};

let managesites_loop_pluginsthemes_next = function (pType) {
    while (mainwpVars.bulkManageSitesTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxThreads) && (mainwpVars.websitesLeft > 0)) {
        managesites_update_pluginsthemes_next(pType);
    }
};

let managesites_update_pluginsthemes_done = function (pType) {
    mainwpVars.currentThreads--;
    if (!mainwpVars.bulkManageSitesTaskRunning)
        return;
    mainwpVars.websitesDone++;
    if (mainwpVars.websitesDone > mainwpVars.websitesTotal)
        mainwpVars.websitesDone = mainwpVars.websitesTotal;

    mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);

    if (mainwpVars.websitesDone == mainwpVars.websitesTotal) {
        setTimeout(function () {
            mainwpVars.bulkManageSitesTaskRunning = false;

            if (ugradingWebsiteAll) {
                // get next updating everything step.
                let nextStep = managesites_update_all_next_step();
                if ('' != nextStep) {
                    websitesEveryError += websitesUpdateError;
                    let selectedIds = jQuery('#sync_selected_site_ids').val().split(',');
                    setTimeout(function () {
                        // start next update step.
                        mainwp_update_pluginsthemes(nextStep, selectedIds);
                    }, 1000);
                    return; // do not close the popup.
                }
            }

            if (websitesUpdateError <= 0 && websitesEveryError <= 0 && mainwpVars.errorCount <= 0) {
                mainwpPopup('#mainwp-sync-sites-modal').close(true);
            } else {
                let message = websitesUpdateError + ' Site' + (websitesUpdateError > 1 ? 's' : '') + ' Timed / Errored out. <br/><span class="mainwp-small">(There was an error syncing some of your sites. <a href="https://mainwp.com/kb/potential-issues/">Please check this help doc for possible solutions.</a>)</span>'; // NOSONAR - noopener - open safe.
                mainwpPopup('#mainwp-sync-sites-modal').getContentEl().prepend('<span class="mainwp-red"><strong>' + message + '</strong></span><br /><br />');
            }
        }, 2000);
        return;
    }

    managesites_loop_pluginsthemes_next(pType);
};

let _tempVal = 0;

let managesites_update_pluginsthemes_next_int = function (websiteId, data, errors) {
    // to enable chunk update, for manage sites page only
    data['chunk_support'] = 1;

    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function (pWebsiteId, pData, pErrors) {
            return function (response) { // NOSONAR - complex ok.
                if (response.error) {
                    mainwpVars.errorCount++;
                    dashboard_update_site_status(pWebsiteId, getErrorMessageInfo(response.error, 'ui'));
                    websitesUpdateError++;
                } else {
                    let res_error = response?.result_error;
                    let isError = false;
                    if (res_error) {

                        let _error = '';
                        let has_roll_error = false;
                        for (let e in res_error) {
                            let ro_error = mainwp_updates_get_rollback_msg(res_error[e]);
                            if (ro_error) {
                                _error += ro_error + '<br/>';
                                has_roll_error = true;
                            } else {
                                _error += res_error[e] + '<br/>';
                            }
                            mainwpVars.errorCount++;
                        }

                        if (_error) {
                            isError = true;
                            let _icon = '<i class="red times icon"></i>';
                            if (has_roll_error) {
                                _icon = mainwpParams.roll_ui_icon;
                            }
                            dashboard_update_site_status(pWebsiteId, '<span class="mainwp-html-popup" data-position="left center" data-html="">' + _icon + '</span>', false);
                            mainwp_init_html_popup('.sync-site-status[siteid="' + pWebsiteId + '"] .mainwp-html-popup', _error);
                        }

                    } else if (response.chunk_slugs) { // to support reduce update plugins/themes
                        let msg = '<i class="sync alternate loading icon"></i>';
                        _tempVal++;
                        if (_tempVal % 2)
                            msg = '<i class="fa fa-refresh fa-spin"></i>';
                        dashboard_update_site_status(pWebsiteId, msg);
                        pData['chunk_slugs'] = response.chunk_slugs;
                        managesites_update_pluginsthemes_next_int(pWebsiteId, pData, pErrors);
                        return;
                    }

                    if (!isError) {
                        dashboard_update_site_status(pWebsiteId, '<i class="green check icon"></i>', true);
                    }
                }

                managesites_update_pluginsthemes_done(pData['type']);
            }
        }(websiteId, data, errors),
        error: function (pWebsiteId, pData, pErrors) {
            return function () {
                if (pErrors > 5) {
                    dashboard_update_site_status(pWebsiteId, '<i class="red times icon"></i>');
                    websitesUpdateError++;
                    managesites_update_pluginsthemes_done(pData['type']);
                } else {
                    pErrors++;
                    managesites_update_pluginsthemes_next_int(pWebsiteId, pData, pErrors);
                }
            }
        }(websiteId, data, errors),
        dataType: 'json'
    });
};


let managesites_update_pluginsthemes_next = function (pType) {
    mainwpVars.currentThreads++;
    mainwpVars.websitesLeft--;
    let websiteId = mainwpVars.websitesToUpdate[mainwpVars.currentWebsite++];
    dashboard_update_site_status(websiteId, __('<i class="sync alternate loading icon"></i>'));
    let data = mainwp_secure_data({
        action: 'mainwp_upgradeplugintheme',
        websiteId: websiteId,
        type: pType
    });
    managesites_update_pluginsthemes_next_int(websiteId, data, 0);
};


jQuery(document).on('click', '#managesites-backup-ignore', function () {
    console.log(typeof managesitesContinueAfterBackup);
    if (managesitesContinueAfterBackup != undefined) {
        ignoredBackupBeforeUpdate = true;
        mainwpPopup('#managesites-backup-box').close();
        managesitesContinueAfterBackup();
        managesitesContinueAfterBackup = undefined;
    }
});

let mainwp_managesites_checkBackups = function (sitesToUpdate, siteNames) {
    if (!mainwpParams['backup_before_upgrade']) {
        if (managesitesContinueAfterBackup != undefined)
            managesitesContinueAfterBackup();
        return;
    }
    let managesitesShowBusyFunction = function () {
        let output = __('Checking if a backup is required for the selected updates...');
        mainwpPopup('#managesites-backup-box').getContentEl().html(output);
        jQuery('#managesites-backup-all').hide();
        jQuery('#managesites-backup-ignore').hide();
        mainwpPopup('#managesites-backup-box').init({
            title: __("Checking backup settings..."), callback: function () {
                mainwpVars.bulkManageSitesTaskRunning = false;
            },
            allowMultiple: true
        });

    };

    let managesitesShowBusyTimeout = setTimeout(managesitesShowBusyFunction, 300);

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
                clearTimeout(managesitesShowBusyTimeout);

                mainwpPopup('#managesites-backup-box').close();
                let siteFeedback;

                if (response?.result?.sites) {
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
                    mainwp_managesites_prepare_backup_popup(response, pSiteNames, siteFeedback);
                    mainwpPopup('#managesites-backup-box').init({
                        title: __("Full backup required!"), callback: function () {
                            managesitesContinueAfterBackup = undefined;
                        },
                        allowMultiple: true
                    });

                    return;
                }
                if (managesitesContinueAfterBackup != undefined)
                    managesitesContinueAfterBackup();
            }
        }(siteNames),
        error: function () {
            mainwpPopup('#managesites-backup-box').close(true);
        },
        dataType: 'json'
    });
};

let mainwp_get_primaryBackup_link = function (what) {
    let slug = '';
    switch (what) {
        case 'backupbuddy':
            slug = 'Extensions-Mainwp-Buddy-Extension&subpage=backup';
            break;
        case 'backwpup':
            slug = 'Extensions-Mainwp-Backwpup-Extension';
            break;
        case 'updraftplus':
            slug = 'Extensions-Mainwp-Updraftplus-Extension';
            break;
        default:
    }

    let pageSlug = '';

    if (slug != '')
        pageSlug = 'admin.php?page=' + slug;

    return pageSlug;
}
jQuery(document).on('click', '#managesites-backupnow-close', function () {
    if (jQuery(this).prop('cancel') == '1') {
        managesitesBackupSites = [];
        managesitesBackupError = false;
        managesitesBackupDownloadRunning = false;
        mainwpPopup('#managesites-backup-box').close(true);
    } else {
        mainwpPopup('#managesites-backup-box').close();
        if (managesitesContinueAfterBackup != undefined)
            managesitesContinueAfterBackup();
    }
});
jQuery(document).on('click', '#managesites-backup-all', function () {
    mainwpPopup('#managesites-backup-box').close();
    // change action buttons
    mainwpPopup('#managesites-backup-box').setActionButtons('<input id="managesites-backupnow-close" type="button" name="Ignore" value="' + __('Cancel') + '" class="button"/>');
    mainwpPopup('#managesites-backup-box').init({
        title: __("Full backup"), callback: function () {
            managesitesContinueAfterBackup = undefined;
            window.location.href = location.href;
        }
    });
    let sitesToBackup = mainwpPopup('#managesites-backup-box').getContentEl().find('.managesites-backup-site');
    managesitesBackupSites = [];
    for (let id of sitesToBackup) {
        let currentSite = { 'id': jQuery(id).attr('siteid'), 'name': jQuery(id).text() };
        managesitesBackupSites.push(currentSite);
    }
    managesites_backup_run();
});


let managesites_backup_run = function () {
    mainwpPopup('#managesites-backup-box').getContentEl().html(dateToHMS(new Date()) + ' ' + __('Starting required backup(s)...'));
    jQuery('#managesites-backupnow-close').prop('value', __('Cancel'));
    jQuery('#managesites-backupnow-close').prop('cancel', '1');
    managesites_backup_run_next();
};

let managesites_backup_run_next = function () {
    let backupContentEl = mainwpPopup('#managesites-backup-box').getContentEl();
    if (managesitesBackupSites.length == 0) {
        appendToDiv(backupContentEl, __('Required backup(s) completed') + (managesitesBackupError ? ' <span class="mainwp-red">' + __('with errors') + '</span>' : '') + '.');

        jQuery('#managesites-backupnow-close').prop('cancel', '0');
        if (managesitesBackupError) {
            //Error...
            jQuery('#managesites-backupnow-close').prop('value', __('Continue update anyway'));
        } else {
            jQuery('#managesites-backupnow-close').prop('value', __('Continue update'));
        }
        return;
    }

    let siteName = managesitesBackupSites[0]['name'];
    appendToDiv(backupContentEl, '[' + siteName + '] ' + __('Creating backup file...'));

    let siteId = managesitesBackupSites[0]['id'];
    managesitesBackupSites.shift();
    let data = mainwp_secure_data({
        action: 'mainwp_backup_run_site',
        site_id: siteId
    });

    jQuery.post(ajaxurl, data, function (pSiteId, pSiteName) {
        return function (response) {
            if (response.error) {
                appendToDiv(backupContentEl, '[' + pSiteName + '] <span class="mainwp-red">Error: ' + getErrorMessage(response.error) + '</span>');
                managesitesBackupError = true;
                managesites_backup_run_next();
            } else {
                appendToDiv(backupContentEl, '[' + pSiteName + '] ' + __('Backup file created successfully!'));

                managesites_backupnow_download_file({ 'id': pSiteId, 'name': pSiteName }, response.result.type, response.result.url, response.result.local, response.result.size);
            }

        }
    }(siteId, siteName), 'json');
};

let managesites_backupnow_download_file = function (siteInfo, type, url, file, size) {
    let pSiteId = siteInfo['id'];
    let pSiteName = siteInfo['name'];
    let backupContentEl = mainwpPopup('#managesites-backup-box').getContentEl();
    appendToDiv(backupContentEl, '[' + pSiteName + '] Downloading the file. <div id="managesites-backupnow-status-progress" siteId="' + pSiteId + '" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>');
    jQuery('#managesites-backupnow-status-progress[siteId="' + pSiteId + '"]').progress({ value: 0, total: size });
    let interVal = setInterval(function () {
        let data = mainwp_secure_data({
            action: 'mainwp_backup_getfilesize',
            local: file
        });
        jQuery.post(ajaxurl, data, function (pSiteId) {
            return function (response) {
                if (response.error)
                    return;

                if (managesitesBackupDownloadRunning) {
                    let progressBar = jQuery('#managesites-backupnow-status-progress[siteId="' + pSiteId + '"]');
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
    managesitesBackupDownloadRunning = true;
    jQuery.post(ajaxurl, data, function (pSize, pInterVal, pSiteName, pSiteId, pUrl) {
        return function (response) {
            managesitesBackupDownloadRunning = false;
            clearInterval(pInterVal);

            if (response.error) {
                appendToDiv(backupContentEl, '[' + pSiteName + '] <span class="error">' + getErrorMessage(response.error) + '</span>');
                appendToDiv(backupContentEl, '[' + pSiteName + '] <span class="error">' + __('Backup failed') + '</span>');

                managesitesBackupError = true;
                managesites_backup_run_next();
                return;
            }

            jQuery('#managesites-backupnow-status-progress[siteId="' + pSiteId + '"]').progress('set progress', pSize);
            appendToDiv(backupContentEl, '[' + pSiteName + '] ' + __('Download from the child site completed.'));
            appendToDiv(backupContentEl, '[' + pSiteName + '] ' + __('Backup completed.'));

            let newData = mainwp_secure_data({
                action: 'mainwp_backup_delete_file',
                site_id: pSiteId,
                file: pUrl
            });
            jQuery.post(ajaxurl, newData, function () { }, 'json');

            managesites_backup_run_next();
        }
    }(size, interVal, pSiteName, pSiteId, url), 'json');
};

window.managesites_wordpress_global_upgrade_all = function (updateSiteIds, updateEverything) {
    let allWebsiteIds = jQuery('.dashboard_wp_id[error-status=0]').map(function (indx, el) {
        return jQuery(el).val();
    });
    allWebsiteIds = mainwp_update_get_selected_sitesids(updateSiteIds, allWebsiteIds);

    let nrOfWebsites = allWebsiteIds.length;

    if (nrOfWebsites == 0) {
        managesites_reset_bulk_actions_params();
        return;
    }

    let progressLen = nrOfWebsites;
    let title = __("Updating WordPress");

    if (updateEverything) {
        ugradingWebsiteAll = true;
        ugradingAllCurrentStep = 'wpcore'; // to get next step.
        title = __("Updating everything: WordPress");
        progressLen = nrOfWebsites * 4; // 4 looping on number of sites.
    }

    let siteNames = {};

    for (let id of allWebsiteIds) {
        dashboard_update_site_status(id, '<i class="clock outline icon"></i> ' + __('PENDING'));
        siteNames[id] = jQuery('.sync-site-status[siteid="' + id + '"]').attr('niceurl');
    }

    managesitesContinueAfterBackup = function (sitesCount, pAllWebsiteIds) {
        return function () {
            mainwpPopup('#mainwp-sync-sites-modal').init({
                title: title,
                progressMax: progressLen,
                totalSites: nrOfWebsites,
                allowMultiple: true,
                statusText: __('updated'),
                callback: function () {
                    mainwpVars.bulkManageSitesTaskRunning = false;
                },
            });
            managesites_wordpress_upgrade_all_int(pAllWebsiteIds);
            managesites_wordpress_upgrade_all_loop_next();
            managesitesContinueAfterBackup = undefined;
        }
    }(nrOfWebsites, allWebsiteIds);
    mainwp_managesites_checkBackups(allWebsiteIds, siteNames);
};

let managesites_wordpress_upgrade_all_int = function (websiteIds) {
    mainwpVars.websitesToUpgrade = websiteIds;
    mainwpVars.currentWebsite = 0;
    mainwpVars.websitesDone = 0;
    mainwpVars.websitesTotal = mainwpVars.websitesToUpgrade.length;
    mainwpVars.websitesLeft = mainwpVars.websitesToUpgrade.length;

    mainwpVars.bulkManageSitesTaskRunning = true;
};
let managesites_wordpress_upgrade_all_loop_next = function () {
    while (mainwpVars.bulkManageSitesTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxThreads) && (mainwpVars.websitesLeft > 0)) {
        managesites_wordpress_upgrade_all_upgrade_next();
    }
};
let managesites_wordpress_upgrade_all_upgrade_next = function () {
    mainwpVars.currentThreads++;
    mainwpVars.websitesLeft--;

    let websiteId = mainwpVars.websitesToUpgrade[mainwpVars.currentWebsite++];
    dashboard_update_site_status(websiteId, '<i class="sync alternate loading icon"></i>');

    managesites_wordpress_upgrade_int(websiteId);
};
let managesites_wordpress_upgrade_all_update_done = function () {
    mainwpVars.currentThreads--;
    if (!mainwpVars.bulkManageSitesTaskRunning)
        return;
    mainwpVars.websitesDone++;

    mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);

    if (mainwpVars.websitesDone == mainwpVars.websitesTotal) {
        if (ugradingWebsiteAll) {
            // get next updating everything step.
            let nextStep = managesites_update_all_next_step();
            let selectedIds = jQuery('#sync_selected_site_ids').val().split(',');
            setTimeout(function () {
                // start next update step.
                mainwp_update_pluginsthemes(nextStep, selectedIds);
            }, 1000);
            return; // do not close the popup.
        }

        setTimeout(function () {
            mainwpVars.bulkManageSitesTaskRunning = false;
            mainwpPopup('#mainwp-sync-sites-modal').close(true);
        }, 3000);
        return;
    }

    managesites_wordpress_upgrade_all_loop_next();
};
let managesites_wordpress_upgrade_int = function (websiteId) {
    let data = mainwp_secure_data({
        action: 'mainwp_upgradewp',
        id: websiteId
    });
    jQuery.post(ajaxurl, data, function (pWebsiteId) {
        return function (response) {
            if (response.error) {
                websitesUpdateError++;
                dashboard_update_site_status(pWebsiteId, '<i class="red times icon"></i>' + ' ' + mainwp_links_visit_site_and_admin('', websiteId), true);
            } else {
                dashboard_update_site_status(pWebsiteId, '<i class="green check icon"></i>' + ' ' + mainwp_links_visit_site_and_admin('', websiteId));
            }

            managesites_wordpress_upgrade_all_update_done();
        }
    }(websiteId), 'json');

    return false;
};

window.mainwp_managesites_prepare_backup_popup = function (response, pSiteNames, siteFeedback) {
    let backupPrimary = '';
    if (response['result']['primary_backup'] && response['result']['primary_backup'] != undefined)
        backupPrimary = response['result']['primary_backup'];

    if (backupPrimary == '') {
        jQuery('#managesites-backup-all').show();
        jQuery('#managesites-backup-ignore').show();
    } else {
        let backupLink = mainwp_get_primaryBackup_link(backupPrimary);
        jQuery('#managesites-backup-now').attr('href', backupLink).show();
        jQuery('#managesites-backup-ignore').val(__('Proceed with Updates')).show();
    }

    let output = '<span class="mainwp-red">' + __('A full backup has not been taken in the last days for the following sites:') + '</span><br /><br />';
    if (backupPrimary == '') { // default backup feature
        for (let id of siteFeedback) {
            output += '<span class="managesites-backup-site" siteid="' + id + '">' + decodeURIComponent(pSiteNames[id]) + '</span><br />';
        }
    } else {
        for (let id of siteFeedback) {
            output += '<span>' + decodeURIComponent(pSiteNames[id]) + '</span><br />';
        }
    }
    mainwpPopup('#managesites-backup-box').getContentEl().html(output);
}
