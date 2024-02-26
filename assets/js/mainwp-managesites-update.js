/* eslint complexity: ["error", 100] */
// current complexity is the only way to achieve desired results, pull request solutions appreciated.

ugradingWebsiteAll = false;
ugradingAllCurrentStep = '';
managesites_update_all_next_step = function () {
    var next = '';
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

mainwp_update_pluginsthemes = function (updateType, updateSiteIds) {
    var allWebsiteIds = jQuery('.dashboard_wp_id').map(function (indx, el) {
        return jQuery(el).val();
    });

    var selectedIds = [], excludeIds = [];
    if (updateSiteIds instanceof Array) {
        jQuery.grep(allWebsiteIds, function (el) {
            if (jQuery.inArray(el, updateSiteIds) !== -1) {
                selectedIds.push(el);
            } else {
                excludeIds.push(el);
            }
        });
        for (var i = 0; i < excludeIds.length; i++) {
            dashboard_update_site_hide(excludeIds[i]);
        }
        allWebsiteIds = selectedIds;
    }
    var nrOfWebsites = allWebsiteIds.length;

    if (nrOfWebsites == 0)
        return false;

    var siteNames = {};

    for (var i = 0; i < allWebsiteIds.length; i++) {
        dashboard_update_site_status(allWebsiteIds[i], '<i class="clock outline icon"></i> ' + __('PENDING'));
        siteNames[allWebsiteIds[i]] = jQuery('.sync-site-status[siteid="' + allWebsiteIds[i] + '"]').attr('niceurl');
    }

    managesitesContinueAfterBackup = function (pType, sitesCount, pAllWebsiteIds) {
        return function () {
            var title = '';
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
                var initData = {
                    progressMax: sitesCount,
                    statusText: __('updated'),
                    callback: function () {
                        bulkManageSitesTaskRunning = false;
                        window.location.href = location.href;
                    }
                };
                if (title != '')
                    initData.title = title;

                mainwpPopup('#mainwp-sync-sites-modal').init(initData);
            }

            managesites_update_pluginsthemes(pType, pAllWebsiteIds);
            managesitesContinueAfterBackup = undefined;
        }
    }(updateType, nrOfWebsites, allWebsiteIds);

    console.log(typeof managesitesContinueAfterBackup);
    return mainwp_managesites_checkBackups(allWebsiteIds, siteNames);

};

managesites_update_pluginsthemes = function (pType, websiteIds) {
    websitesToUpdate = websiteIds;
    currentWebsite = 0;
    websitesDone = 0;
    websitesError = 0;
    websitesEveryError = 0;
    websitesTotal = websitesLeft = websitesToUpdate.length;

    bulkManageSitesTaskRunning = true;

    if (websitesTotal == 0) {
        managesites_update_pluginsthemes_done(pType);
    } else {
        managesites_loop_pluginsthemes_next(pType);
    }
};

managesites_loop_pluginsthemes_next = function (pType) {
    while (bulkManageSitesTaskRunning && (currentThreads < maxThreads) && (websitesLeft > 0)) {
        managesites_update_pluginsthemes_next(pType);
    }
};

managesites_update_pluginsthemes_done = function (pType) {
    currentThreads--;
    if (!bulkManageSitesTaskRunning)
        return;
    websitesDone++;
    if (websitesDone > websitesTotal)
        websitesDone = websitesTotal;

    mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(websitesDone);

    if (websitesDone == websitesTotal) {
        setTimeout(function () {
            bulkManageSitesTaskRunning = false;

            if (ugradingWebsiteAll) {
                // get next updating everything step.
                var nextStep = managesites_update_all_next_step();
                if ('' != nextStep) {
                    websitesEveryError += websitesError;
                    var selectedIds = jQuery('#sync_selected_site_ids').val().split(',');
                    setTimeout(function () {
                        // start next update step.
                        mainwp_update_pluginsthemes(nextStep, selectedIds);
                    }, 1000);
                    return; // do not close the popup.
                }
            }

            if (websitesError <= 0 && websitesEveryError <= 0) {
                mainwpPopup('#mainwp-sync-sites-modal').close(true);
            } else {
                var message = websitesError + ' Site' + (websitesError > 1 ? 's' : '') + ' Timed / Errored out. <br/><span class="mainwp-small">(There was an error syncing some of your sites. <a href="https://kb.mainwp.com/docs/potential-issues/">Please check this help doc for possible solutions.</a>)</span>';
                mainwpPopup('#mainwp-sync-sites-modal').getContentEl().prepend('<span class="mainwp-red"><strong>' + message + '</strong></span><br /><br />');
            }
        }, 2000);
        return;
    }

    managesites_loop_pluginsthemes_next(pType);
};
managesites_update_pluginsthemes_next = function (pType) {
    currentThreads++;
    websitesLeft--;
    var websiteId = websitesToUpdate[currentWebsite++];
    dashboard_update_site_status(websiteId, __('<i class="sync alternate loading icon"></i>'));
    var data = mainwp_secure_data({
        action: 'mainwp_upgradeplugintheme',
        websiteId: websiteId,
        type: pType
    });
    managesites_update_pluginsthemes_next_int(websiteId, data, 0);
};

managesites_update_pluginsthemes_next_int = function (websiteId, data, errors) {
    // to enable chunk update, for manage sites page only
    data['chunk_support'] = 1;

    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function (pWebsiteId, pData, pErrors) {
            return function (response) {
                if (response.error) {
                    dashboard_update_site_status(pWebsiteId, getErrorMessageInfo(response.error, 'ui'));
                    websitesError++;
                } else {
                    if (response.result) {
                        for (slug in response.result) {
                            if (response.result[slug] == 1) {
                                //ok.
                            }
                        }
                    }

                    dashboard_update_site_status(websiteId, '<i class="green check icon"></i>', true);
                    // to support reduce update plugins/themes
                    if (response.chunk_slugs) {
                        var msg = '<i class="sync alternate loading icon"></i>';
                        _tempVal++;
                        if (_tempVal % 2)
                            msg = '<i class="fa fa-refresh fa-spin"></i>';
                        dashboard_update_site_status(pWebsiteId, msg);
                        pData['chunk_slugs'] = response.chunk_slugs;
                        managesites_update_pluginsthemes_next_int(pWebsiteId, pData, pErrors);
                        return;
                    } else {
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
                    websitesError++;
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

var managesitesContinueAfterBackup = undefined;
jQuery(document).on('click', '#managesites-backup-ignore', function () {
    console.log(typeof managesitesContinueAfterBackup);
    if (managesitesContinueAfterBackup != undefined) {
        mainwpPopup('#managesites-backup-box').close();
        managesitesContinueAfterBackup();
        managesitesContinueAfterBackup = undefined;
    }
});

var managesitesShowBusyFunction;
var managesitesShowBusyTimeout;

mainwp_managesites_checkBackups = function (sitesToUpdate, siteNames) {
    if (mainwpParams['backup_before_upgrade'] == false) {
        if (managesitesContinueAfterBackup != undefined)
            managesitesContinueAfterBackup();
        return false;
    }
    managesitesShowBusyFunction = function () {
        var output = __('Checking if a backup is required for the selected updates...');
        mainwpPopup('#managesites-backup-box').getContentEl().html(output);
        jQuery('#managesites-backup-all').hide();
        jQuery('#managesites-backup-ignore').hide();
        mainwpPopup('#managesites-backup-box').init({
            title: __("Checking backup settings..."), callback: function () {
                bulkManageSitesTaskRunning = false;
                window.location.href = location.href;
            }
        });

    };

    managesitesShowBusyTimeout = setTimeout(managesitesShowBusyFunction, 300);

    //Step 2: Check if backups are ok.
    var data = mainwp_secure_data({
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
                var siteFeedback = undefined;

                if (response['result'] && response['result']['sites'] != undefined) {
                    siteFeedback = [];
                    for (var currSiteId in response['result']['sites']) {
                        if (response['result']['sites'][currSiteId] == false) {
                            siteFeedback.push(currSiteId);
                        }
                    }
                    if (siteFeedback.length == 0)
                        siteFeedback = undefined;
                }

                if (siteFeedback != undefined) {
                    var backupPrimary = '';
                    if (response['result']['primary_backup'] && response['result']['primary_backup'] != undefined)
                        backupPrimary = response['result']['primary_backup'];

                    if (backupPrimary == '') {
                        jQuery('#managesites-backup-all').show();
                        jQuery('#managesites-backup-ignore').show();
                    } else {
                        var backupLink = mainwp_get_primaryBackup_link(backupPrimary);
                        jQuery('#managesites-backup-now').attr('href', backupLink).show();
                        jQuery('#managesites-backup-ignore').val(__('Proceed with Updates')).show();
                    }

                    var output = '<span class="mainwp-red">' + __('A full backup has not been taken in the last days for the following sites:') + '</span><br /><br />';
                    if (backupPrimary == '') { // default backup feature
                        for (var j = 0; j < siteFeedback.length; j++) {
                            output += '<span class="managesites-backup-site" siteid="' + siteFeedback[j] + '">' + decodeURIComponent(pSiteNames[siteFeedback[j]]) + '</span><br />';
                        }
                    } else {
                        for (var j = 0; j < siteFeedback.length; j++) {
                            output += '<span>' + decodeURIComponent(pSiteNames[siteFeedback[j]]) + '</span><br />';
                        }
                    }
                    mainwpPopup('#managesites-backup-box').getContentEl().html(output);
                    console.log(typeof managesitesContinueAfterBackup);
                    mainwpPopup('#managesites-backup-box').init({
                        title: __("Full backup required!"), callback: function () {
                            managesitesContinueAfterBackup = undefined;
                            window.location.href = location.href;
                        }
                    });

                    return false;
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

    return false;
};

mainwp_get_primaryBackup_link = function (what) {
    var slug = '';
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

    var pageSlug = '';

    if (slug != '')
        pageSlug = 'admin.php?page=' + slug;

    return pageSlug;
}
jQuery(document).on('click', '#managesites-backupnow-close', function () {
    if (jQuery(this).prop('cancel') == '1') {
        //jQuery('#managesites-backupnow-box').dialog('destroy');
        managesitesBackupSites = [];
        managesitesBackupError = false;
        managesitesBackupDownloadRunning = false;
        mainwpPopup('#managesites-backup-box').close(true);
        //location.reload();
    } else {
        //jQuery('#managesites-backupnow-box').dialog('destroy');

        mainwpPopup('#managesites-backup-box').close();
        if (managesitesContinueAfterBackup != undefined)
            managesitesContinueAfterBackup();
    }
});
jQuery(document).on('click', '#managesites-backup-all', function () {
    //jQuery('#managesites-backup-box').dialog('destroy');

    mainwpPopup('#managesites-backup-box').close();
    // change action buttons
    mainwpPopup('#managesites-backup-box').setActionButtons('<input id="managesites-backupnow-close" type="button" name="Ignore" value="' + __('Cancel') + '" class="button"/>');
    mainwpPopup('#managesites-backup-box').init({
        title: __("Full backup"), callback: function () {
            managesitesContinueAfterBackup = undefined;
            window.location.href = location.href;
        }
    });
    //var sitesToBackup = jQuery('.managesites-backup-site');
    var sitesToBackup = mainwpPopup('#managesites-backup-box').getContentEl().find('.managesites-backup-site');
    managesitesBackupSites = [];
    for (var i = 0; i < sitesToBackup.length; i++) {
        var currentSite = [];
        currentSite['id'] = jQuery(sitesToBackup[i]).attr('siteid');
        currentSite['name'] = jQuery(sitesToBackup[i]).text();
        managesitesBackupSites.push(currentSite);
    }
    managesites_backup_run();
});

var managesitesBackupSites;
var managesitesBackupError;
var managesitesBackupDownloadRunning;

managesites_backup_run = function () {
    mainwpPopup('#managesites-backup-box').getContentEl().html(dateToHMS(new Date()) + ' ' + __('Starting required backup(s)...'));
    jQuery('#managesites-backupnow-close').prop('value', __('Cancel'));
    jQuery('#managesites-backupnow-close').prop('cancel', '1');
    managesites_backup_run_next();
};

managesites_backup_run_next = function () {
    var backupContentEl = mainwpPopup('#managesites-backup-box').getContentEl();
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

    var siteName = managesitesBackupSites[0]['name'];
    appendToDiv(backupContentEl, '[' + siteName + '] ' + __('Creating backup file...'));

    var siteId = managesitesBackupSites[0]['id'];
    managesitesBackupSites.shift();
    var data = mainwp_secure_data({
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

                managesites_backupnow_download_file(pSiteId, pSiteName, response.result.type, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder);
            }

        }
    }(siteId, siteName), 'json');
};

managesites_backupnow_download_file = function (pSiteId, pSiteName, type, url, file, regexfile, size, subfolder) {
    var backupContentEl = mainwpPopup('#managesites-backup-box').getContentEl();
    appendToDiv(backupContentEl, '[' + pSiteName + '] Downloading the file. <div id="managesites-backupnow-status-progress" siteId="' + pSiteId + '" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>');
    jQuery('#managesites-backupnow-status-progress[siteId="' + pSiteId + '"]').progress({ value: 0, total: size });
    var interVal = setInterval(function () {
        var data = mainwp_secure_data({
            action: 'mainwp_backup_getfilesize',
            local: file
        });
        jQuery.post(ajaxurl, data, function (pSiteId) {
            return function (response) {
                if (response.error)
                    return;

                if (managesitesBackupDownloadRunning) {
                    var progressBar = jQuery('#managesites-backupnow-status-progress[siteId="' + pSiteId + '"]');
                    if (progressBar.progress('get value') < progressBar.progress('get total')) {
                        progressBar.progress('set progress', response.result);
                    }
                }
            }
        }(pSiteId), 'json');
    }, 500);

    var data = mainwp_secure_data({
        action: 'mainwp_backup_download_file',
        site_id: pSiteId,
        type: type,
        url: url,
        local: file
    });
    managesitesBackupDownloadRunning = true;
    jQuery.post(ajaxurl, data, function (pFile, pRegexFile, pSubfolder, pSize, pType, pInterVal, pSiteName, pSiteId, pUrl) {
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

            var newData = mainwp_secure_data({
                action: 'mainwp_backup_delete_file',
                site_id: pSiteId,
                file: pUrl
            });
            jQuery.post(ajaxurl, newData, function () { }, 'json');

            managesites_backup_run_next();
        }
    }(file, regexfile, subfolder, size, type, interVal, pSiteName, pSiteId, url), 'json');
};

managesites_wordpress_global_upgrade_all = function (updateSiteIds, updateEverything) {
    var allWebsiteIds = jQuery('.dashboard_wp_id').map(function (indx, el) {
        return jQuery(el).val();
    });

    var selectedIds = [], excludeIds = [];
    if (updateSiteIds instanceof Array) {
        jQuery.grep(allWebsiteIds, function (el) {
            if (jQuery.inArray(el, updateSiteIds) !== -1) {
                selectedIds.push(el);
            } else {
                excludeIds.push(el);
            }
        });
        for (var i = 0; i < excludeIds.length; i++) {
            dashboard_update_site_hide(excludeIds[i]);
        }
        allWebsiteIds = selectedIds;
    }
    var nrOfWebsites = allWebsiteIds.length;
    if (nrOfWebsites == 0)
        return false;

    var progressLen = nrOfWebsites;
    var title = __("Updating WordPress");

    if (updateEverything) {
        ugradingWebsiteAll = true;
        ugradingAllCurrentStep = 'wpcore'; // to get next step.
        title = __("Updating everything: WordPress");
        progressLen = nrOfWebsites * 4; // 4 looping on number of sites.
    }

    var siteNames = {};

    for (var i = 0; i < allWebsiteIds.length; i++) {
        dashboard_update_site_status(allWebsiteIds[i], '<i class="clock outline icon"></i> ' + __('PENDING'));
        siteNames[allWebsiteIds[i]] = jQuery('.sync-site-status[siteid="' + allWebsiteIds[i] + '"]').attr('niceurl');
    }

    managesitesContinueAfterBackup = function (sitesCount, pAllWebsiteIds) {
        return function () {
            mainwpPopup('#mainwp-sync-sites-modal').init({
                title: title,
                progressMax: progressLen,
                totalSites: nrOfWebsites,
                statusText: __('updated'),
                callback: function () {
                    bulkManageSitesTaskRunning = false;
                    window.location.href = location.href;
                }
            });
            managesites_wordpress_upgrade_all_int(pAllWebsiteIds);
            managesites_wordpress_upgrade_all_loop_next();
            managesitesContinueAfterBackup = undefined;
        }
    }(nrOfWebsites, allWebsiteIds);
    return mainwp_managesites_checkBackups(allWebsiteIds, siteNames);
};

managesites_wordpress_upgrade_all_int = function (websiteIds) {
    websitesToUpgrade = websiteIds;
    currentWebsite = 0;
    websitesDone = 0;
    websitesTotal = websitesLeft = websitesToUpgrade.length;

    bulkManageSitesTaskRunning = true;
};
managesites_wordpress_upgrade_all_loop_next = function () {
    while (bulkManageSitesTaskRunning && (currentThreads < maxThreads) && (websitesLeft > 0)) {
        managesites_wordpress_upgrade_all_upgrade_next();
    }
};
managesites_wordpress_upgrade_all_upgrade_next = function () {
    currentThreads++;
    websitesLeft--;

    var websiteId = websitesToUpgrade[currentWebsite++];
    dashboard_update_site_status(websiteId, '<i class="sync alternate loading icon"></i>');

    managesites_wordpress_upgrade_int(websiteId);
};
managesites_wordpress_upgrade_all_update_done = function () {
    currentThreads--;
    if (!bulkManageSitesTaskRunning)
        return;
    websitesDone++;

    mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(websitesDone);

    if (websitesDone == websitesTotal) {
        if (ugradingWebsiteAll) {
            // get next updating everything step.
            var nextStep = managesites_update_all_next_step();
            var selectedIds = jQuery('#sync_selected_site_ids').val().split(',');
            setTimeout(function () {
                // start next update step.
                mainwp_update_pluginsthemes(nextStep, selectedIds);
            }, 1000);
            return; // do not close the popup.          
        }

        setTimeout(function () {
            bulkManageSitesTaskRunning = false;
            mainwpPopup('#mainwp-sync-sites-modal').close(true);
        }, 3000);
        return;
    }

    managesites_wordpress_upgrade_all_loop_next();
};
managesites_wordpress_upgrade_int = function (websiteId) {
    var data = mainwp_secure_data({
        action: 'mainwp_upgradewp',
        id: websiteId
    });
    jQuery.post(ajaxurl, data, function (pWebsiteId) {
        return function (response) {
            if (response.error) {
                result = getErrorMessage(response.error);
                dashboard_update_site_status(pWebsiteId, '<i class="red times icon"></i>' + ' ' + mainwp_links_visit_site_and_admin('', websiteId), true);
            } else {
                dashboard_update_site_status(pWebsiteId, '<i class="green check icon"></i>' + ' ' + mainwp_links_visit_site_and_admin('', websiteId));
            }

            managesites_wordpress_upgrade_all_update_done();
        }
    }(websiteId), 'json');

    return false;
};
