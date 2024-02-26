
// Trigger Manage Sites Bulk Actions
jQuery(document).on('click', '#mainwp-do-sites-bulk-actions', function () {
  var action = jQuery("#mainwp-sites-bulk-actions-menu").dropdown("get value");
  if (action == '')
    return false;
  mainwp_managesites_doaction(action);
  return false;
});

jQuery( document ).on( 'click', '#mainwp-manage-sites-filter-toggle-button', function () {
  jQuery( '#mainwp-sites-filters-row' ).toggle( 300 );
  return false;
} );



// Manage Sites Bulk Actions
/* eslint-disable complexity */
mainwp_managesites_doaction = function (action) {

  if (action == 'delete' || action == 'test_connection' || action == 'sync' || action == 'reconnect' || action == 'update_plugins' || action == 'update_themes' || action == 'update_wpcore' || action == 'update_translations' || action == 'refresh_favico' || action == 'checknow' || action == 'update_everything' || action == 'check_abandoned_plugin' || action == 'check_abandoned_theme' || action == 'suspend' || action == 'unsuspend' ) {

    if (bulkManageSitesTaskRunning)
      return false;

    if (action == 'delete' || action == 'update_plugins' || action == 'update_themes' || action == 'update_wpcore' || action == 'update_translations' || action == 'update_everything' || action == 'check_abandoned_plugin' || action == 'check_abandoned_theme' || action == 'suspend') {
      var confirmMsg = '';
      var _selection_cancelled = false;
      switch (action) {
        case 'delete':
          confirmMsg = __("You are about to remove the selected sites from your MainWP Dashboard?");
          break;
        case 'update_plugins':
          confirmMsg = __("You are about to update plugins on the selected sites?");
          _selection_cancelled = true;
          break;
        case 'update_themes':
          confirmMsg = __("You are about to update themes on the selected sites?");
          _selection_cancelled = true;
          break;
        case 'update_wpcore':
          confirmMsg = __("You are about to update WordPress core files on the selected sites?");
          _selection_cancelled = true;
          break;
        case 'update_translations':
          confirmMsg = __("You are about to update translations on the selected sites?");
          _selection_cancelled = true;
        case 'update_everything':
          confirmMsg = __("You are about to update everything on the selected sites?");
          _selection_cancelled = true;
          break;
        case 'check_abandoned_plugin':
          confirmMsg = __("You are about to check abandoned plugin on the selected sites?");
          _selection_cancelled = true;
          break;
        case 'check_abandoned_theme':
          confirmMsg = __("You are about to check abandoned theme on the selected sites?");
          _selection_cancelled = true;
          break;
        case 'suspend':
          confirmMsg = __("You are about to suspend the selected sites?");
          _selection_cancelled = true;
          break;
      }

      if (confirmMsg == '')
        return false;
      var _cancelled_callback = null;
      if (_selection_cancelled) {
        _cancelled_callback = function () {
          jQuery('#mainwp-sites-bulk-actions-menu').dropdown("set selected", "sync");
        };
      }

      var updateType; // undefined

      if (action == 'update_plugins' || action == 'update_themes' || action == 'update_translations' || action == 'update_everything') {
        updateType = 2; // multi update
      }

      mainwp_confirm(confirmMsg, _callback = function () { mainwp_managesites_doaction_process(action); }, _cancelled_callback, updateType);
      return false; // return those case
    }

    mainwp_managesites_doaction_process(action); // other case callback

    return false;
  }

  mainwp_managesites_doaction_open(action);

  return false;

};
/* eslint-enable complexity */

mainwp_managesites_doaction_open = function (action) {
  jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked').each(function () {
    var row = jQuery(this).closest('tr');
    switch (action) {
      case 'open_wpadmin':
        var url = row.find('a.open_newwindow_wpadmin').attr('href');
        window.open(url, '_blank');
        break;
      case 'open_frontpage':
        var url = row.find('a.open_site_url').attr('href');
        window.open(url, '_blank');
        break;
    }
  });
}

mainwp_managesites_doaction_process = function (action) {

  managesites_bulk_init();

  bulkManageSitesTotal = jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]').length;
  bulkManageSitesTaskRunning = true;

  var selectedIds = jQuery.map(jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked'), function (el) {
    return jQuery(el).val();
  });

  if (action == 'delete') {
    mainwp_managesites_bulk_remove_next();
    return false;
  } else if (action == 'sync') {
    mainwp_sync_sites_data(selectedIds);
  } else if (action == 'reconnect') {
    mainwp_managesites_bulk_reconnect_next();
  } else if (action == 'update_plugins') {
    mainwp_update_pluginsthemes('plugin', selectedIds);
  } else if (action == 'update_themes') {
    mainwp_update_pluginsthemes('theme', selectedIds);
  } else if (action == 'update_wpcore') {
    managesites_wordpress_global_upgrade_all(selectedIds, false);
  } else if (action == 'update_translations') {
    mainwp_update_pluginsthemes('translation', selectedIds);
  } else if (action == 'update_everything') {
    jQuery('#sync_selected_site_ids').val(selectedIds.join(','));
    managesites_wordpress_global_upgrade_all(selectedIds, true); // Update everything, start update wpcore first.
  } else if (action == 'refresh_favico') {
    mainwp_managesites_bulk_refresh_favico(selectedIds);
  } else if (action == 'checknow') {
    mainwp_sync_sites_data(selectedIds, 'checknow');
  } else if (action == 'check_abandoned_plugin') {
    mainwp_managesites_bulk_check_abandoned(selectedIds, 'plugin');
  } else if (action == 'check_abandoned_theme') {
    mainwp_managesites_bulk_check_abandoned(selectedIds, 'theme');
  } else if (action == 'suspend' || action == 'unsuspend') {
    mainwp_managesites_bulk_suspend_status(selectedIds, action );
  } 
}


jQuery(document).on('click', '.managesites_syncdata', function () {
  var syncIds = [];
  var row = jQuery(this).closest('tr');
  var sid = 0;
  if (jQuery(row).hasClass('child')) {
    row = jQuery(row).prev();
    sid = row.attr('siteid');
  } else {
    sid = row.attr('siteid');
  }
  if (sid) {
    syncIds.push(row.attr('siteid'));
  }
  mainwp_sync_sites_data(syncIds);
  return false;
});

jQuery(document).on('click', '.managesites_checknow', function () {
  var row = jQuery(this).closest('.menu');
  var syncIds = [];
  syncIds.push(row.attr('siteid'));
  mainwp_sync_sites_data(syncIds, 'checknow');
  return false;
});

jQuery(document).on('change', '#mainwp-add-new-button', function () {
  var url = jQuery('#mainwp-add-new-button :selected').attr('item-url');
  if (typeof url !== 'undefined' && url != '')
    location.href = url;
  return false;
});

mainwp_managesites_bulk_reconnect_next = function () {
  while ((checkedBox = jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]:first')) && (checkedBox.length > 0) && (bulkManageSitesCurrentThreads < bulkManageSitesMaxThreads)) {
    mainwp_managesites_bulk_reconnect_specific(checkedBox);
  }
  if ((bulkManageSitesTotal > 0) && (bulkManageSitesFinished == bulkManageSitesTotal)) {
    managesites_bulk_done();
    setHtml('#mainwp-message-zone', __("Process completed. Reloading page..."));
    setTimeout(function () {
      window.location.reload()
    }, 3000);
  }
}

mainwp_managesites_bulk_reconnect_specific = function (pCheckedBox) {

  pCheckedBox.attr('status', 'running');
  var rowObj = pCheckedBox.closest('tr');
  var siteUrl = rowObj.attr('site-url');
  var siteId = rowObj.attr('siteid');

  // skip reconnect sites without sync error
  if (rowObj.find('td.site-sync-error').length == 0) {
    bulkManageSitesFinished++;
    mainwp_managesites_bulk_reconnect_next();
    return;
  }

  bulkManageSitesCurrentThreads++;


  rowObj.html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Trying to reconnect. Please wait...' + '</td>');

  var data = mainwp_secure_data({
    action: 'mainwp_reconnectwp',
    siteid: siteId
  });

  jQuery.post(ajaxurl, data, function (response) {
    bulkManageSitesCurrentThreads--;
    bulkManageSitesFinished++;
    rowObj.html('<td colspan="999"></td>');

    response = response.trim();
    var msg = '', error = '';
    if (response.substr(0, 5) == 'ERROR') {
      if (response.length == 5) {
        error = __('Undefined error occured. Please try again.');
      } else {
        error = response.substr(6);
      }
      error = siteUrl + ' - ' + error;
    } else {
      msg = siteUrl + ' - ' + response;
    }

    if (msg != '') {
      rowObj.removeClass('error');
      rowObj.addClass('positive');
      rowObj.html('<td colspan="999"><i class="green check icon"></i>' + msg + '</td>');
    } else if (error != '') {
      rowObj.html('<td colspan="999"><i class="red times icon"></i>' + error + '</td>');
    }
    mainwp_managesites_bulk_reconnect_next();
  });

  return;
};

managesites_bulk_done = function () {
  bulkManageSitesTaskRunning = false;
};

mainwp_managesites_bulk_remove_next = function () {
  while ((checkedBox = jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]:first')) && (checkedBox.length > 0) && (bulkManageSitesCurrentThreads < bulkManageSitesMaxThreads)) {
    mainwp_managesites_bulk_remove_specific(checkedBox);
  }
  if ((bulkManageSitesTotal > 0) && (bulkManageSitesFinished == bulkManageSitesTotal)) {
    managesites_bulk_done();
    setHtml('#mainwp-message-zone', __("Process completed. Reloading page..."));
    setTimeout(function () {
      window.location.reload()
    }, 3000);
  }
}

mainwp_managesites_bulk_remove_specific = function (pCheckedBox) {
  pCheckedBox.attr('status', 'running');
  var rowObj = pCheckedBox.closest('tr');
  bulkManageSitesCurrentThreads++;

  var id = rowObj.attr('siteid');

  rowObj.html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Removing and deactivating the MainWP Child plugin...' + '</td>');

  var data = mainwp_secure_data({
    action: 'mainwp_removesite',
    id: id
  });
  jQuery.post(ajaxurl, data, function (response) {
    bulkManageSitesCurrentThreads--;
    bulkManageSitesFinished++;
    rowObj.html('<td colspan="999"></td>');
    var result = '';
    var error = '';
    if (response.error != undefined) {
      error = response.error;
    } else if (response.result == 'SUCCESS') {
      result = __('The site has been removed and the MainWP Child plugin has been disabled.');
    } else if (response.result == 'NOSITE') {
      error = __('Site not found. Please try again.');
    } else {
      result = __('The site has been removed but the MainWP Child plugin could not be disabled.');
    }

    if (error != '') {
      rowObj.html('<td colspan="999"><i class="red times icon"></i>' + error + '</td>');
    }

    rowObj.html('<td colspan="999"><i class="green check icon"></i>' + result + '</td>');
    setTimeout(function () {
      jQuery('tr[siteid=' + id + ']').fadeOut(1000);
    }, 3000);

    mainwp_managesites_bulk_remove_next();
  }, 'json');
};


bulkManageSitesMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
bulkManageSitesCurrentThreads = 0;
bulkManageSitesTotal = 0;
bulkManageSitesFinished = 0;
bulkManageSitesTaskRunning = false;


managesites_bulk_init = function () {
  jQuery('#mainwp-message-zone').hide();
  if (bulkManageSitesTaskRunning == false) {
    bulkManageSitesMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
    bulkManageSitesCurrentThreads = 0;
    bulkManageSitesTotal = 0;
    bulkManageSitesFinished = 0;
    jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox').each(function () {
      jQuery(this).attr('status', 'queue')
    });
  }
};


mainwp_managesites_bulk_refresh_favico = function (siteIds) {
  var allWebsiteIds = jQuery('.dashboard_wp_id').map(function (indx, el) {
    return jQuery(el).val();
  });

  var selectedIds = [], excludeIds = [];
  if (siteIds instanceof Array) {
    jQuery.grep(allWebsiteIds, function (el) {
      if (jQuery.inArray(el, siteIds) !== -1) {
        selectedIds.push(el);
      } else {
        excludeIds.push(el);
      }
    });
    for (var i = 0; i < excludeIds.length; i++) {
      dashboard_update_site_hide(excludeIds[i]);
    }
    allWebsiteIds = selectedIds;
    //jQuery('#refresh-status-total').text(allWebsiteIds.length);
  }

  var nrOfWebsites = allWebsiteIds.length;

  if (nrOfWebsites == 0)
    return false;

  var siteNames = {};

  for (var i = 0; i < allWebsiteIds.length; i++) {
    dashboard_update_site_status(allWebsiteIds[i], '<i class="clock outline icon"></i>');
    siteNames[allWebsiteIds[i]] = jQuery('.sync-site-status[siteid="' + allWebsiteIds[i] + '"]').attr('niceurl');
  }
  var initData = {
    progressMax: nrOfWebsites,
    title: 'Refresh Favicon',
    statusText: __('updated'),
    callback: function () {
      bulkManageSitesTaskRunning = false;
      window.location.href = location.href;
    }
  };
  mainwpPopup('#mainwp-sync-sites-modal').init(initData);

  mainwp_managesites_refresh_favico_all_int(allWebsiteIds);
};

mainwp_managesites_refresh_favico_all_int = function (websiteIds) {
  websitesToUpgrade = websiteIds;
  currentWebsite = 0;
  websitesDone = 0;
  websitesTotal = websitesLeft = websitesToUpgrade.length;

  bulkTaskRunning = true;
  mainwp_managesites_refresh_favico_all_loop_next();
};

mainwp_managesites_refresh_favico_all_loop_next = function () {
  while (bulkTaskRunning && (currentThreads < maxThreads) && (websitesLeft > 0)) {
    mainwp_managesites_refresh_favico_all_upgrade_next();
  }
};
mainwp_managesites_refresh_favico_all_upgrade_next = function () {
  currentThreads++;
  websitesLeft--;

  var websiteId = websitesToUpgrade[currentWebsite++];
  dashboard_update_site_status(websiteId, '<i class="sync alternate loading icon"></i>');

  mainwp_managesites_refresh_favico_int(websiteId);
};

mainwp_managesites_refresh_favico_int = function (siteid) {

  var data = mainwp_secure_data({
    action: 'mainwp_get_site_icon',
    siteId: siteid
  });

  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function (pSiteid) {
      return function (response) {
        currentThreads--;
        websitesDone++;
        mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(websitesDone);
        if (response.error != undefined) {
          dashboard_update_site_status(pSiteid, '<i class="red times icon"></i>');
        } else if (response.result && response.result == 'success') {
          dashboard_update_site_status(pSiteid, '<i class="green check icon"></i>', true);
        } else {
          dashboard_update_site_status(pSiteid, '<i class="red times icon"></i>');
        }
        mainwp_managesites_refresh_favico_all_loop_next();
      }
    }(siteid),
    dataType: 'json'
  });
  return false;
};


/* Suspend sites */
mainwp_managesites_bulk_suspend_status = function (siteIds , status) {

  var allWebsiteIds = jQuery('.dashboard_wp_id').map(function (indx, el) {
    return jQuery(el).val();
  });

  var selectedIds = [], excludeIds = [];
  if (siteIds instanceof Array) {
    jQuery.grep(allWebsiteIds, function (el) {
      if (jQuery.inArray(el, siteIds) !== -1) {
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
    dashboard_update_site_status(allWebsiteIds[i], '<i class="clock outline icon"></i>');
    siteNames[allWebsiteIds[i]] = jQuery('.sync-site-status[siteid="' + allWebsiteIds[i] + '"]').attr('niceurl');
  }
  var initData = {
    progressMax: nrOfWebsites,
    title: 'Suspend Site',
    statusText: __('suspended'),
    callback: function () {
      bulkManageSitesTaskRunning = false;
      window.location.href = location.href;
    }
  };
  mainwpPopup('#mainwp-sync-sites-modal').init(initData);

  mainwp_managesites_suspend_status_all_int(allWebsiteIds, status);
};

mainwp_managesites_suspend_status_all_int = function (websiteIds, status) {
  websitesToUpgrade = websiteIds;
  currentWebsite = 0;
  websitesDone = 0;
  websitesTotal = websitesLeft = websitesToUpgrade.length;

  bulkTaskRunning = true;
  mainwp_managesites_suspend_status_all_loop_next(status);
};

mainwp_managesites_suspend_status_all_loop_next = function (status) {
  while (bulkTaskRunning && (currentThreads < maxThreads) && (websitesLeft > 0)) {
    mainwp_managesites_suspend_status_all_upgrade_next(status);
  }
};
mainwp_managesites_suspend_status_all_upgrade_next = function (status) {
  currentThreads++;
  websitesLeft--;

  var websiteId = websitesToUpgrade[currentWebsite++];
  dashboard_update_site_status(websiteId, '<i class="sync alternate loading icon"></i>');

  mainwp_managesites_suspend_status_int(websiteId, status);
};

mainwp_managesites_suspend_status_int = function (siteid, status) {
  var data = mainwp_secure_data({
    action: 'mainwp_manage_sites_suspend_site',
    suspended: (status == 'suspend' ) ? 1 : 0,
    siteid: siteid,
  });

  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function (pSiteid) {
      return function (response) {
        currentThreads--;
        websitesDone++;
        mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(websitesDone);
        if (response.error != undefined) {
          dashboard_update_site_status(pSiteid, '<span data-inverted="" data-position="left center" data-tooltip="' + response.error + '"><i class="times red icon"></i></span>');
        } else if (response.result && response.result == 'success') {
          dashboard_update_site_status(pSiteid, '<i class="green check icon"></i>', true);
        } else {
          dashboard_update_site_status(pSiteid, '<i class="red times icon"></i>');
        }
        mainwp_managesites_suspend_status_all_loop_next(status);
      }
    }(siteid),
    dataType: 'json'
  });
  return false;
};
