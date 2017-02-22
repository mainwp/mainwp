//jQuery(document).on('click', '#mainwp_managesites_content #doaction', function(){
//    var action = jQuery('#bulk-action-selector-top').val();    
//    if (action == -1)
//        return false; 
//    mainwp_managesites_doaction2(action);
//});
//
//jQuery(document).on('click', '#mainwp_managesites_content #doaction2', function(){
//    var action = jQuery('#bulk-action-selector-bottom').val();    
//    if (action == -1)
//        return false;     
//    mainwp_managesites_doaction2(action);     
//});
//
//mainwp_managesites_doaction2 = function(action) {
//    if (action == 'update_plugins' || action == 'update_themes') {
//          if (bulkManageSitesTaskRunning)
//            return false;
//
//        managesites_bulk_init();
//        bulkManageSitesTotal = jQuery('#the-list .check-column INPUT:checkbox:checked[status="queue"]').length;
//
//        bulkManageSitesTaskRunning = true;
//
//        if (action == 'update_plugins') {
//            var selectedIds = jQuery.map(jQuery('#the-list .check-column INPUT:checkbox:checked'), function(el) { return jQuery(el).val(); });        
//            mainwp_update_pluginsthemes('plugin', selectedIds);
//        } else if (action == 'update_themes') {
//            var selectedIds = jQuery.map(jQuery('#the-list .check-column INPUT:checkbox:checked'), function(el) { return jQuery(el).val(); });        
//            mainwp_update_pluginsthemes('theme', selectedIds);
//        }        
//    } 
//}


mainwp_update_pluginsthemes = function (updateType, updateSiteIds)
{
    var allWebsiteIds = jQuery('.dashboard_wp_id').map(function(indx, el){ return jQuery(el).val(); });

    var selectedIds = [], excludeIds = [];
    if (updateSiteIds instanceof Array) {
        jQuery.grep(allWebsiteIds, function(el) {
            if (jQuery.inArray(el, updateSiteIds) !== -1) {
                selectedIds.push(el);
            } else {
                excludeIds.push(el);
            }
        });
        for (var i = 0; i < excludeIds.length; i++)
        {
            dashboard_update_site_hide(excludeIds[i]);
        }
        allWebsiteIds = selectedIds;
        jQuery('#refresh-status-total').text(allWebsiteIds.length);
    }
    var nrOfWebsites = allWebsiteIds.length;

    if (nrOfWebsites == 0)
        return false;

    var siteNames = {};

    for (var i = 0; i < allWebsiteIds.length; i++)
    {
        dashboard_update_site_status(allWebsiteIds[i], '<i class="fa fa-clock-o" aria-hidden="true"></i> ' + __('PENDING'));
        siteNames[allWebsiteIds[i]] =  jQuery('.refresh-status-wp[siteid="'+allWebsiteIds[i]+'"]').attr('niceurl');
    }

    managesitesContinueAfterBackup = function(pType, sitesCount, pAllWebsiteIds) { return function()
    {
        if (pType == 'plugin')
            jQuery('#refresh-status-box').attr('title', __("Updating plugins..."));
        else if (pType == 'theme') {
            jQuery('#refresh-status-box').attr('title', __("Updating themes..."));
        }
        else if (pType == 'translation') {
            jQuery('#refresh-status-box').attr('title', __("Updating translations..."));
        }
        jQuery('#refresh-status-text').html(__('updated'));
        jQuery('#refresh-status-progress').progressbar({value: 0, max: sitesCount});
        jQuery('#refresh-status-box').dialog({
            resizable: false,
            height: 350,
            width: 500,
            modal: true,
            close: function(event, ui) {bulkManageSitesTaskRunning = false; jQuery('#refresh-status-box').dialog('destroy'); location.href = location.href;}});
        managesites_update_pluginsthemes(pType, pAllWebsiteIds);

        managesitesContinueAfterBackup = undefined;
    } } (updateType, nrOfWebsites, allWebsiteIds);

    return mainwp_managesites_checkBackups(allWebsiteIds, siteNames);

};

managesites_update_pluginsthemes = function(pType, websiteIds)
{
    websitesToUpdate = websiteIds;
    currentWebsite = 0;
    websitesDone = 0;
    websitesError = 0;
    websitesTotal = websitesLeft = websitesToUpdate.length;

    bulkManageSitesTaskRunning = true;

    if (pType == 'plugin')
        dashboardActionName = 'upgrade_all_plugins';
    else if (pType == 'translation')
        dashboardActionName = 'upgrade_all_translations';
    else
        dashboardActionName = 'upgrade_all_themes';
    var dateObj = new Date();
    starttimeDashboardAction = dateObj.getTime();
    countRealItemsUpdated = 0;
    itemsToUpdate = [];

    if (websitesTotal == 0)
    {
        managesites_update_pluginsthemes_done(pType);
    }
    else
    {
        managesites_loop_pluginsthemes_next(pType);
    }
};

managesites_loop_pluginsthemes_next = function(pType)
{
    while(bulkManageSitesTaskRunning && (currentThreads < maxThreads) && (websitesLeft > 0))
    {
        managesites_update_pluginsthemes_next(pType);
    }
};

managesites_update_pluginsthemes_done = function(pType)
{
    currentThreads--;
    if (!bulkManageSitesTaskRunning) return;
    websitesDone++;
    if (websitesDone > websitesTotal) websitesDone = websitesTotal;

    jQuery('#refresh-status-progress').progressbar('value', websitesDone);
    jQuery('#refresh-status-current').html(websitesDone);

    if (websitesDone == websitesTotal)
    {
        couttItemsToUpdate = itemsToUpdate.length;
        rightnow_send_twitt_info();
        setTimeout(function() {
            bulkManageSitesTaskRunning = false;
            if (websitesError <= 0)
            {
                jQuery('#refresh-status-box').dialog('destroy');
                location.href = location.href;
            }
            else
            {
                var message = websitesError + ' Site' + (websitesError > 1 ? 's' : '') + ' Timed / Errored out. <br/><span class="mainwp-small">(There was an error syncing some of your sites. <a href="http://mainwp.com/help/docs/potential-issues/">Please check this help doc for possible solutions.</a>)</span>';
                jQuery('#refresh-status-content').prepend('<span class="mainwp-red"><strong>' + message + '</strong></span><br /><br />');
                jQuery('#mainwp-right-now-message-content').html(message);
                jQuery('#mainwp-right-now-message').show();
            }
        }, 2000);
        return;
    }

    managesites_loop_pluginsthemes_next(pType);
};
managesites_update_pluginsthemes_next = function(pType)
{
    currentThreads++;
    websitesLeft--;
    var websiteId = websitesToUpdate[currentWebsite++];
    dashboard_update_site_status(websiteId, __('UPGRADING'));
    var data = mainwp_secure_data({
        action:'mainwp_upgradeplugintheme',
        websiteId: websiteId,
        type: pType
    });
    managesites_update_pluginsthemes_next_int(websiteId, data, 0);
};

managesites_update_pluginsthemes_next_int = function(websiteId, data, errors)
{
    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function(pWebsiteId) { return function(response) {
            if (response.error) {
                dashboard_update_site_status(pWebsiteId, '<span class="mainwp-red"><i class="fa fa-exclamation" aria-hidden="true"></i> ' + __('ERROR') + '</span>'); websitesError++;
            } else {
                dashboard_update_site_status(websiteId, '<span class="mainwp-green"><i class="fa fa-check" aria-hidden="true"></i> ' + __('DONE') + '</span>', true );
                if (response.result) {
                    for (slug in response.result) {
                        if (response.result[slug] == 1) {
                            countRealItemsUpdated++;
                            if (itemsToUpdate.indexOf(slug) == -1) itemsToUpdate.push(slug);
                        }
                    }
                }
            }

            managesites_update_pluginsthemes_done();
        } }(websiteId),
        error: function(pWebsiteId, pData, pErrors) { return function(response) {
            if (pErrors > 5)
            {
                dashboard_update_site_status(pWebsiteId, '<span class="mainwp-red"><i class="fa fa-exclamation" aria-hidden="true"></i> ' +  __('TIMEOUT') + '</span>');
                websitesError++;
                managesites_update_pluginsthemes_done();
            }
            else
            {
                pErrors++;
                managesites_update_pluginsthemes_next_int(pWebsiteId, pData, pErrors);
            }
        } }(websiteId, data, errors),
        dataType: 'json'
    });
};

var managesitesContinueAfterBackup = undefined;
jQuery(document).on('click', '#managesites-backup-ignore', function() {
    if (managesitesContinueAfterBackup != undefined)
    {
        jQuery('#managesites-backup-box').dialog('destroy');
        managesitesContinueAfterBackup();
        managesitesContinueAfterBackup = undefined;
    }
});

var managesitesShowBusyFunction;
var managesitesShowBusyTimeout;
var managesitesShowBusy;
mainwp_managesites_checkBackups = function(sitesToUpdate, siteNames)
{
//    if (mainwpParams['backup_before_upgrade'] != true)
//    {
//        if (managesitesContinueAfterBackup != undefined) managesitesContinueAfterBackup();
//        return false;
//    }

    managesitesShowBusy = true;
    managesitesShowBusyFunction = function()
    {
        var backupContent = jQuery('#managesites-backup-content');
        var output = __('Checking if a backup is required for the selected updates...');
        backupContent.html(output);

        jQuery('#managesites-backup-all').hide();
        jQuery('#managesites-backup-ignore').hide();

        var backupBox = jQuery('#managesites-backup-box');
        backupBox.attr('title', __('Checking backup settings'));
        jQuery('div[aria-describedby="managesites-backup-box"]').find('.ui-dialog-title').html(__('Checking backup settings...'));
        if (managesitesShowBusy)
        {
            backupBox.dialog({
                resizable:false,
                height:350,
                width:500,
                modal:true,
                close:function (event, ui)
                {
                    jQuery('#managesites-backup-box').dialog('destroy');
                }});
        }
    };

    managesitesShowBusyTimeout = setTimeout(managesitesShowBusyFunction, 300);

    //Step 2: Check if backups are ok.
    var data = mainwp_secure_data({
        action:'mainwp_checkbackups',
        sites:sitesToUpdate
    });

    jQuery.ajax({
        type: "POST",
        url: ajaxurl,
        data: data,
        success: function(pSiteNames) { return function (response)
        {
            managesitesShowBusy = false;
            clearTimeout(managesitesShowBusyTimeout);
            var backupBox = jQuery('#managesites-backup-box');
            try
            {
                backupBox.dialog('destroy');
            }
            catch (e) {}

            //jQuery('#managesites-backup-all').show();
            //jQuery('#managesites-backup-ignore').show();

            backupBox.attr('title', __('Full backup required!'));
            jQuery('div[aria-describedby="managesites-backup-box"]').find('.ui-dialog-title').html(__('Full backup required!'));


            var siteFeedback = undefined;

            if (response.error != undefined)
            {
            }
            else if (response['result'] == true)
            {
                //Continue..
            }
            else if (response['result']['sites'] != undefined)
            {
                siteFeedback = [];
                for (var currSiteId in response['result']['sites'])
                {
                    if (response['result']['sites'][currSiteId] == false)
                    {
                        siteFeedback.push(currSiteId);
                    }
                }
                if (siteFeedback.length == 0) siteFeedback = undefined;
            }

            if (siteFeedback != undefined)
            {
                var backupContent = jQuery('#managesites-backup-content');
                var backupPrimary = '';
                if (response['result']['primary_backup'] && response['result']['primary_backup'] != undefined)
                    backupPrimary = response['result']['primary_backup'];

                if(backupPrimary == '') {
                    jQuery('#managesites-backup-all').show();
                    jQuery('#managesites-backup-ignore').show();
                } else {
                    var backupLink = mainwp_get_primaryBackup_link(backupPrimary);
                    jQuery('#managesites-backup-now').attr('href', backupLink).show();
                    jQuery('#managesites-backup-ignore').val(__('Proceed with Updates')).show();
                }

                var output = '<span class="mainwp-red">'+__('A full backup has not been taken in the last days for the following sites:')+'</span><br /><br />';
                if (backupPrimary == '') { // default backup feature
                    for (var j = 0; j < siteFeedback.length; j++)
                    {
                        output += '<span class="managesites-backup-site" siteid="' + siteFeedback[j] + '">' + decodeURIComponent(pSiteNames[siteFeedback[j]]) + '</span><br />';
                    }
                } else {
                    for (var j = 0; j < siteFeedback.length; j++)
                    {
                        output += '<span>' + decodeURIComponent(pSiteNames[siteFeedback[j]]) + '</span><br />';
                    }
                }
                backupContent.html(output);

                //backupBox = jQuery('#managesites-backup-box');
                backupBox.dialog({
                    resizable:false,
                    height:350,
                    width:500,
                    modal:true,
                    close:function (event, ui)
                    {
                        jQuery('#managesites-backup-box').dialog('destroy');
                        managesitesContinueAfterBackup = undefined;
                    }});

                return false;
            }

            if (managesitesContinueAfterBackup != undefined) managesitesContinueAfterBackup();
        } }(siteNames),
        error: function()
        {
            backupBox = jQuery('#managesites-backup-box');
            backupBox.dialog('destroy');

            //if (managesitesContinueAfterBackup != undefined) managesitesContinueAfterBackup();
        },
        dataType: 'json'
    });

    return false;
};

mainwp_get_primaryBackup_link = function(what, site_id){
    var slug = '';
    switch(what) {
        case 'backupbuddy':
            slug = 'Extensions-Mainwp-Buddy-Extension&subpage=backup';
            break;
        case 'backupwp':
            slug = 'Extensions-Mainwp-Backupwordpress-Extension&tab=schedules';
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
jQuery(document).on('click', '#managesites-backupnow-close', function() {
    if (jQuery(this).prop('cancel') == '1')
    {
        jQuery('#managesites-backupnow-box').dialog('destroy');
        managesitesBackupSites = [];
        managesitesBackupError = false;
        managesitesBackupDownloadRunning = false;
        location.reload();
    }
    else
    {
        jQuery('#managesites-backupnow-box').dialog('destroy');
        if (managesitesContinueAfterBackup != undefined) managesitesContinueAfterBackup();
    }
});
jQuery(document).on('click', '#managesites-backup-all', function() {
    jQuery('#managesites-backup-box').dialog('destroy');

    var backupNowBox = jQuery('#managesites-backupnow-box');
    backupNowBox.dialog({
        resizable:false,
        height:350,
        width:500,
        modal:true,
        close:function (event, ui)
        {
            jQuery('#managesites-backupnow-box').dialog('destroy');
            managesitesContinueAfterBackup = undefined;
        }});

    var sitesToBackup = jQuery('.managesites-backup-site');
    managesitesBackupSites = [];
    for (var i = 0; i < sitesToBackup.length; i++)
    {
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

managesites_backup_run = function()
{
    jQuery('#managesites-backupnow-content').html(dateToHMS(new Date()) + ' ' + __('Starting required backup(s)...'));
    jQuery('#managesites-backupnow-close').prop('value', __('Cancel'));
    jQuery('#managesites-backupnow-close').prop('cancel', '1');
    managesites_backup_run_next();
};

managesites_backup_run_next = function()
{
    if (managesitesBackupSites.length == 0)
    {
        appendToDiv('#managesites-backupnow-content', __('Required backup(s) completed') + (managesitesBackupError ? ' <span class="mainwp-red">'+__('with errors')+'</span>' : '') + '.');

        jQuery('#managesites-backupnow-close').prop('cancel', '0');
        if (managesitesBackupError)
        {
            //Error...
            jQuery('#managesites-backupnow-close').prop('value', __('Continue update anyway'));
        }
        else
        {
            jQuery('#managesites-backupnow-close').prop('value', __('Continue update'));
        }
//        setTimeout(function() {
//                    jQuery('#managebackups-task-status-box').dialog('destroy');
//                    location.reload();
//                }, 3000);
        return;
    }

    var siteName = managesitesBackupSites[0]['name'];
    appendToDiv('#managesites-backupnow-content', '[' + siteName + '] '+__('Creating backup file...'));

    var siteId = managesitesBackupSites[0]['id'];
    managesitesBackupSites.shift();
    var data = mainwp_secure_data({
        action: 'mainwp_backup_run_site',
        site_id: siteId
    });

    jQuery.post(ajaxurl, data, function(pSiteId, pSiteName) { return function (response) {
        if (response.error)
        {
            appendToDiv('#managesites-backupnow-content', '[' + pSiteName + '] <span class="mainwp-red">Error: ' + getErrorMessage(response.error) + '</span>');
            managesitesBackupError = true;
            managesites_backup_run_next();
        }
        else
        {
            appendToDiv('#managesites-backupnow-content', '[' + pSiteName + '] '+__('Backup file created successfully!'));

            managesites_backupnow_download_file(pSiteId, pSiteName, response.result.type, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder);
        }

    } }(siteId, siteName), 'json');
};

managesites_backupnow_download_file = function(pSiteId, pSiteName, type, url, file, regexfile, size, subfolder)
{
    appendToDiv('#managesites-backupnow-content', '[' + pSiteName + '] Downloading the file. <div id="managesites-backupnow-status-progress" siteId="'+pSiteId+'" style="height: 10px !important;"></div>');
    jQuery('#managesites-backupnow-status-progress[siteId="'+pSiteId+'"]').progressbar({value: 0, max: size});
    var interVal = setInterval(function() {
        var data = mainwp_secure_data({
            action:'mainwp_backup_getfilesize',
            local: file
        });
        jQuery.post(ajaxurl, data, function(pSiteId) { return function (response) {
            if (response.error) return;

            if (managesitesBackupDownloadRunning)
            {
                var progressBar = jQuery('#managesites-backupnow-status-progress[siteId="'+pSiteId+'"]');
                if (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max'))
                {
                    progressBar.progressbar('value', response.result);
                }
            }
        } }(pSiteId), 'json');
    }, 500);

    var data = mainwp_secure_data({
        action:'mainwp_backup_download_file',
        site_id: pSiteId,
        type: type,
        url: url,
        local: file
    });
    managesitesBackupDownloadRunning = true;
    jQuery.post(ajaxurl, data, function(pFile, pRegexFile, pSubfolder, pSize, pType, pInterVal, pSiteName, pSiteId, pUrl) { return function (response) {
        managesitesBackupDownloadRunning = false;
        clearInterval(pInterVal);

        if (response.error)
        {
            appendToDiv('#managesites-backupnow-content', '[' + pSiteName + '] <span class="mainwp-red">ERROR: '+ getErrorMessage(response.error) + '</span>');
            appendToDiv('#managesites-backupnow-content', '[' + pSiteName + '] <span class="mainwp-red">'+__('Backup failed') + '</span>');

            managesitesBackupError = true;
            managesites_backup_run_next();
            return;
        }

        jQuery('#managesites-backupnow-status-progress[siteId="'+pSiteId+'"]').progressbar();
        jQuery('#managesites-backupnow-status-progress[siteId="'+pSiteId+'"]').progressbar('value', pSize);
        appendToDiv('#managesites-backupnow-content', '[' + pSiteName + '] '+__('Download from the child site completed.'));
        appendToDiv('#managesites-backupnow-content', '[' + pSiteName + '] '+__('Backup completed.'));

        var newData = mainwp_secure_data({
            action:'mainwp_backup_delete_file',
            site_id: pSiteId,
            file: pUrl
        });
        jQuery.post(ajaxurl, newData, function() {}, 'json');

        managesites_backup_run_next();
    } }(file, regexfile, subfolder, size, type, interVal, pSiteName, pSiteId, url), 'json');
};

managesites_wordpress_global_upgrade_all = function (updateSiteIds)
{
    console.log(updateSiteIds);

    var allWebsiteIds = jQuery('.dashboard_wp_id').map(function(indx, el){ return jQuery(el).val(); });

    var selectedIds = [], excludeIds = [];
    if (updateSiteIds instanceof Array) {
        jQuery.grep(allWebsiteIds, function(el) {
            if (jQuery.inArray(el, updateSiteIds) !== -1) {
                selectedIds.push(el);
            } else {
                excludeIds.push(el);
            }
        });
        for (var i = 0; i < excludeIds.length; i++)
        {
            dashboard_update_site_hide(excludeIds[i]);
        }
        allWebsiteIds = selectedIds;
        jQuery('#refresh-status-total').text(allWebsiteIds.length);
    }
    var nrOfWebsites = allWebsiteIds.length;

    if (nrOfWebsites == 0)
        return false;

    var siteNames = {};

    for (var i = 0; i < allWebsiteIds.length; i++)
    {
        dashboard_update_site_status(allWebsiteIds[i], '<i class="fa fa-clock-o" aria-hidden="true"></i> ' + __('PENDING'));
        siteNames[allWebsiteIds[i]] =  jQuery('.refresh-status-wp[siteid="'+allWebsiteIds[i]+'"]').attr('niceurl');
    }

    managesitesContinueAfterBackup = function(sitesCount, pAllWebsiteIds) { return function()
    {

        jQuery('#refresh-status-box').attr('title', __("Updating WordPress"));
        jQuery('#refresh-status-text').html(__('updated'));
        jQuery('#refresh-status-progress').progressbar({value: 0, max: sitesCount});
        jQuery('#refresh-status-box').dialog({
            resizable: false,
            height: 350,
            width: 500,
            modal: true,
            close: function(event, ui) {bulkManageSitesTaskRunning = false; jQuery('#refresh-status-box').dialog('destroy'); location.href = location.href;}});

        var dateObj = new Date();
        dashboardActionName = 'upgrade_all_wp_core';
        starttimeDashboardAction = dateObj.getTime();
        countRealItemsUpdated = 0;

        managesites_wordpress_upgrade_all_int(pAllWebsiteIds);

        managesitesContinueAfterBackup = undefined;
    } } (nrOfWebsites, allWebsiteIds);

    return mainwp_managesites_checkBackups(allWebsiteIds, siteNames);


};
managesites_wordpress_upgrade_all_int = function (websiteIds)
{
    websitesToUpgrade = websiteIds;
    currentWebsite = 0;
    websitesDone = 0;
    websitesTotal = websitesLeft = websitesToUpgrade.length;

    bulkManageSitesTaskRunning = true;
    managesites_wordpress_upgrade_all_loop_next();
};
managesites_wordpress_upgrade_all_loop_next = function ()
{
    while (bulkManageSitesTaskRunning && (currentThreads < maxThreads) && (websitesLeft > 0))
    {
        managesites_wordpress_upgrade_all_upgrade_next();
    }
};
managesites_wordpress_upgrade_all_upgrade_next = function ()
{
    currentThreads++;
    websitesLeft--;

    var websiteId = websitesToUpgrade[currentWebsite++];
    dashboard_update_site_status(websiteId, __('UPGRADING'));

    managesites_wordpress_upgrade_int(websiteId);
};
managesites_wordpress_upgrade_all_update_done = function ()
{
    currentThreads--;
    if (!bulkManageSitesTaskRunning) return;
    websitesDone++;

    jQuery('#refresh-status-progress').progressbar('value', websitesDone);
    jQuery('#refresh-status-current').html(websitesDone);

    if (websitesDone == websitesTotal)
    {
        setTimeout(function ()
        {
            bulkManageSitesTaskRunning = false;
            jQuery('#refresh-status-box').dialog('destroy');
            location.href = location.href;
        }, 3000);
        return;
    }

    managesites_wordpress_upgrade_all_loop_next();
};
managesites_wordpress_upgrade_int = function (websiteId)
{
    var websiteHolder = jQuery('div.mainwp_wordpress_upgrade[site_id="' + websiteId + '"]');

    websiteHolder.find('.wordpressAction').hide();
    websiteHolder.find('.wordpressInfo').html('<i class="fa fa-spinner fa-pulse"></i> '+__('Updating...'));

    var data = mainwp_secure_data({
        action:'mainwp_upgradewp',
        id:websiteId
    });
    jQuery.post(ajaxurl, data, function (pWebsiteId)
    {
        return function (response)
        {
            var result;
            var websiteHolder = jQuery('div.mainwp_wordpress_upgrade[site_id="' + pWebsiteId + '"]');

            if (response.error)
            {
                result = getErrorMessage(response.error);
                dashboard_update_site_status(pWebsiteId, '<span class="mainwp-red"><i class="fa fa-exclamation" aria-hidden="true"></i> ' +  __('FAILED') + '</span>', true );
            }
            else
            {
                result = response.result;
                dashboard_update_site_status(pWebsiteId, '<span class="mainwp-green"><i class="fa fa-check" aria-hidden="true"></i> ' + __('DONE') + '</span>' );
                websiteHolder.attr('updated', 1);
                countRealItemsUpdated++;
                couttItemsToUpdate++;
            }

            managesites_wordpress_upgrade_all_update_done();
            websiteHolder.find('.wordpressInfo').html(result);

            if (websitesDone == websitesTotal)
            {
                rightnow_send_twitt_info();
            }
        }
    }(websiteId), 'json');

    return false;
};
