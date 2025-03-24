
window.mainwpVars = window.mainwpVars || {};

// Trigger Manage Sites Bulk Actions
jQuery(document).on('click', '#mainwp-do-sites-bulk-actions', function () {
  let action = jQuery("#mainwp-sites-bulk-actions-menu").dropdown("get value");
  if (action) {
    mainwp_managesites_doaction(action);
  }
  return false;
});

jQuery(document).on('click', '#mainwp-manage-sites-filter-toggle-button', function () {
  jQuery('#mainwp-sites-filters-row').toggle(300);
  return false;
});


// Manage Sites Bulk Actions
/* eslint-disable complexity */
let mainwp_managesites_doaction = function (action) { // NOSONAR - complex.

  if (action == 'delete' || action == 'test_connection' || action == 'sync' || action == 'reconnect' || action == 'update_plugins' || action == 'update_themes' || action == 'update_wpcore' || action == 'update_translations' || action == 'refresh_favico' || action == 'checknow' || action == 'update_everything' || action == 'check_abandoned_plugin' || action == 'check_abandoned_theme' || action == 'suspend' || action == 'unsuspend') {

    if (mainwpVars.bulkManageSitesTaskRunning) {
      return;
    }

    let confirmMsg = '';
    let _selection_cancelled = false;

    if (action == 'delete' || action == 'update_plugins' || action == 'update_themes' || action == 'update_wpcore' || action == 'update_translations' || action == 'update_everything' || action == 'check_abandoned_plugin' || action == 'check_abandoned_theme' || action == 'suspend') {

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
          break;
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

      if (confirmMsg == '') {
        return;
      }

      let _cancelled_callback = null;
      if (_selection_cancelled) {
        _cancelled_callback = function () {
          jQuery('#mainwp-sites-bulk-actions-menu').dropdown("set selected", "sync");
        };
      }

      let updateType; // undefined

      if (action == 'update_plugins' || action == 'update_themes' || action == 'update_translations' || action == 'update_everything') {
        updateType = 2; // multi update
      }

      mainwp_confirm(confirmMsg, function () { mainwp_managesites_doaction_process(action); }, _cancelled_callback, updateType);

      return; // return those case
    }
    mainwp_managesites_doaction_process(action); // other case callback
  }

  mainwp_managesites_doaction_open(action);

};
/* eslint-enable complexity */

let mainwp_managesites_doaction_open = function (action) {
  jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked').each(function () {
    let row = jQuery(this).closest('tr');
    let url = '';
    if (action === 'open_wpadmin') {
      url = row.find('a.open_newwindow_wpadmin').attr('href');
      window.open(url, '_blank');
    } else if (action === 'open_frontpage') {
      url = row.find('a.open_site_url').attr('href');
      window.open(url, '_blank');
    }
  });
}

window.managesites_reset_bulk_actions_params = function () {
  mainwpVars.bulkManageSitesTaskRunning = false;
  mainwpVars.bulkManageSitesCurrentThreads = 0;
  mainwpVars.bulkManageSitesFinished = 0;
  mainwpVars.bulkManageSitesTotal = 0;
};

let mainwp_managesites_doaction_process = function (action) {

  managesites_bulk_init(false);

  bulkManageSitesTotal = jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]').length;
  mainwpVars.bulkManageSitesTaskRunning = true;

  let selectedIds = jQuery.map(jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked'), function (el) {
    return jQuery(el).val();
  });

  console.log(selectedIds);

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
    mainwp_managesites_bulk_suspend_status(selectedIds, action);
  }
}


jQuery(document).on('click', '.managesites_syncdata', function () {
  let syncIds = [];
  let row = jQuery(this).closest('tr');
  let sid = 0;
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
  let row = jQuery(this).closest('.menu');
  let syncIds = [];
  syncIds.push(row.attr('siteid'));
  mainwp_sync_sites_data(syncIds, 'checknow');
  return false;
});

jQuery(document).on('change', '#mainwp-add-new-button', function () {
  let url = jQuery('#mainwp-add-new-button :selected').attr('item-url');
  if (typeof url !== 'undefined' && url != '')
    location.href = url;
  return false;
});

let mainwp_managesites_bulk_reconnect_next = function () {
  while ((checkedBox = jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]:first')) && (checkedBox.length > 0) && (bulkManageSitesCurrentThreads < bulkManageSitesMaxThreads)) { // NOSONAR -- modified out side the function.
    mainwp_managesites_bulk_reconnect_specific(checkedBox);
  }
  if ((bulkManageSitesTotal > 0) && (bulkManageSitesFinished == bulkManageSitesTotal)) {
    setHtml('#mainwp-message-zone', __("Process completed. Reloading page..."));
    setTimeout(function () {
      window.location.reload()
    }, 3000);
  }
}

let mainwp_managesites_bulk_reconnect_specific = function (pCheckedBox) {

  pCheckedBox.attr('status', 'running');
  let rowObj = pCheckedBox.closest('tr');
  let siteUrl = rowObj.attr('site-url');
  let siteId = rowObj.attr('siteid');

  // skip reconnect sites without sync error
  if (rowObj.find('td.site-sync-error').length == 0) {
    bulkManageSitesFinished++;
    mainwp_managesites_bulk_reconnect_next();
    return;
  }

  bulkManageSitesCurrentThreads++;


  rowObj.html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Trying to reconnect. Please wait...' + '</td>');

  let data = mainwp_secure_data({
    action: 'mainwp_reconnectwp',
    siteid: siteId
  });

  jQuery.post(ajaxurl, data, function (response) {
    bulkManageSitesCurrentThreads--;
    bulkManageSitesFinished++;
    rowObj.html('<td colspan="999"></td>');

    response = response.trim();
    let msg = '', error = '';
    if (response.substring(0, 5) == 'ERROR') {
      if (response.length == 5) {
        error = __('Undefined error occured. Please try again.');
        error = siteUrl + ' - ' + error;
      } else {
        error = response.substring(6);
        let err = mainwp_js_get_error_not_detected_connect(error, 'html_msg', false, true);
        if (true !== err && '' != err) {
          error = err; // decoded error.
        }
      }
    } else {
      msg = siteUrl + ' - ' + mainwp_get_reconnect_error(response, siteId);
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
};


let mainwp_managesites_bulk_remove_next = function () {
  while ((checkedBox = jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]:first')) && (checkedBox.length > 0) && (bulkManageSitesCurrentThreads < bulkManageSitesMaxThreads)) { // NOSONAR -- modified out side the function.
    mainwp_managesites_bulk_remove_specific(checkedBox);
  }
  if ((bulkManageSitesTotal > 0) && (bulkManageSitesFinished == bulkManageSitesTotal)) { // NOSONAR - modified outside the function.
    setHtml('#mainwp-message-zone', __("Process completed. Reloading page..."));
    setTimeout(function () {
      window.location.reload()
    }, 3000);
  }
}

let mainwp_managesites_bulk_remove_specific = function (pCheckedBox) {
  pCheckedBox.attr('status', 'running');
  let rowObj = pCheckedBox.closest('tr');
  bulkManageSitesCurrentThreads++;

  let id = rowObj.attr('siteid');

  rowObj.html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Removing and deactivating the MainWP Child plugin...' + '</td>');

  let data = mainwp_secure_data({
    action: 'mainwp_removesite',
    id: id
  });
  jQuery.post(ajaxurl, data, function (response) {
    bulkManageSitesCurrentThreads--;
    bulkManageSitesFinished++;
    rowObj.html('<td colspan="999"></td>');
    let result = '';
    let error = '';
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


let bulkManageSitesMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
let bulkManageSitesCurrentThreads = 0;
let bulkManageSitesTotal = 0;
let bulkManageSitesFinished = 0;
mainwpVars.bulkManageSitesTaskRunning = false;


let managesites_bulk_init = function (isMonitorsBulk) {
  mainwp_set_message_zone('#mainwp-message-zone-client');
  if (!mainwpVars.bulkManageSitesTaskRunning) {
    bulkManageSitesCurrentThreads = 0;
    bulkManageSitesTotal = 0;
    bulkManageSitesFinished = 0;

    if (isMonitorsBulk) {
      bulkManageSitesMaxThreads = mainwpParams?.maximumUptimeMonitoringRequests ? mainwpParams.maximumUptimeMonitoringRequests : 10;
      jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:not(.sub-pages-checkbox)').each(function () {
        jQuery(this).attr('status', 'queue')
      });
    } else {
      bulkManageSitesMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
      jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox').each(function () {
        jQuery(this).attr('status', 'queue')
      });
    }

  }
};


let mainwp_managesites_bulk_refresh_favico = function (siteIds) {
  let allWebsiteIds = jQuery('.dashboard_wp_id[error-status=0]').map(function (indx, el) {
    return jQuery(el).val();
  });
  console.log(allWebsiteIds);

  let selectedIds = [], excludeIds = [];
  if (siteIds instanceof Array) {
    jQuery.grep(allWebsiteIds, function (el) {
      if (jQuery.inArray(el, siteIds) !== -1) {
        selectedIds.push(el);
      } else {
        excludeIds.push(el);
      }
    });
    for (let id of excludeIds) {
      dashboard_update_site_hide(id);
    }
    allWebsiteIds = selectedIds;
  }

  let nrOfWebsites = allWebsiteIds.length;

  if (nrOfWebsites == 0) {
    managesites_reset_bulk_actions_params();
    return;
  }

  let siteNames = {};

  for (let id of allWebsiteIds) {
    dashboard_update_site_status(id, '<i class="clock outline icon"></i>');
    siteNames[id] = jQuery('.sync-site-status[siteid="' + id + '"]').attr('niceurl');
  }
  let initData = {
    progressMax: nrOfWebsites,
    title: 'Refresh Favicon',
    statusText: __('updated'),
    callback: function () {
      mainwpVars.bulkManageSitesTaskRunning = false;
      window.location.href = location.href;
    }
  };
  mainwpPopup('#mainwp-sync-sites-modal').init(initData);

  mainwp_managesites_refresh_favico_all_int(allWebsiteIds);
};

let mainwp_managesites_refresh_favico_all_int = function (websiteIds) {
  mainwpVars.websitesToUpgrade = websiteIds;
  mainwpVars.currentWebsite = 0;
  mainwpVars.websitesDone = 0;
  mainwpVars.websitesTotal = mainwpVars.websitesToUpgrade.length;
  mainwpVars.websitesLeft = mainwpVars.websitesToUpgrade.length;

  mainwpVars.bulkTaskRunning = true;
  mainwp_managesites_refresh_favico_all_loop_next();
};

let mainwp_managesites_refresh_favico_all_loop_next = function () {
  while (mainwpVars.bulkTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxThreads) && (mainwpVars.websitesLeft > 0)) {
    mainwp_managesites_refresh_favico_all_upgrade_next();
  }
};

let mainwp_managesites_refresh_favico_all_upgrade_next = function () {
  mainwpVars.currentThreads++;
  mainwpVars.websitesLeft--;

  let websiteId = mainwpVars.websitesToUpgrade[mainwpVars.currentWebsite++];
  dashboard_update_site_status(websiteId, '<i class="sync alternate loading icon"></i>');

  mainwp_managesites_refresh_favico_int(websiteId);
};

let mainwp_managesites_refresh_favico_int = function (siteid) {

  let data = mainwp_secure_data({
    action: 'mainwp_get_site_icon',
    siteId: siteid
  });

  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function (pSiteid) {
      return function (response) {
        mainwpVars.currentThreads--;
        mainwpVars.websitesDone++;
        mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);
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
let mainwp_managesites_bulk_suspend_status = function (siteIds, status) {

  let allWebsiteIds = jQuery('.dashboard_wp_id[error-status=0]').map(function (indx, el) {
    return jQuery(el).val();
  });
  let selectedIds = [], excludeIds = [];
  if (siteIds instanceof Array) {
    jQuery.grep(allWebsiteIds, function (el) {
      if (jQuery.inArray(el, siteIds) !== -1) {
        selectedIds.push(el);
      } else {
        excludeIds.push(el);
      }
    });
    for (let id of excludeIds) {
      dashboard_update_site_hide(id);
    }
    allWebsiteIds = selectedIds;
  }

  let nrOfWebsites = allWebsiteIds.length;

  if (nrOfWebsites == 0) {
    managesites_reset_bulk_actions_params();
    return;
  }

  let siteNames = {};

  for (let id of allWebsiteIds) {
    dashboard_update_site_status(id, '<i class="clock outline icon"></i>');
    siteNames[id] = jQuery('.sync-site-status[siteid="' + id + '"]').attr('niceurl');
  }
  let initData = {
    progressMax: nrOfWebsites,
    title: 'Suspend Site',
    statusText: __('suspended'),
    callback: function () {
      mainwpVars.bulkManageSitesTaskRunning = false;
      window.location.href = location.href;
    }
  };
  mainwpPopup('#mainwp-sync-sites-modal').init(initData);

  mainwp_managesites_suspend_status_all_int(allWebsiteIds, status);
};

let mainwp_managesites_suspend_status_all_int = function (websiteIds, status) {
  mainwpVars.websitesToUpgrade = websiteIds;
  mainwpVars.currentWebsite = 0;
  mainwpVars.websitesDone = 0;
  mainwpVars.websitesTotal = mainwpVars.websitesToUpgrade.length;
  mainwpVars.websitesLeft = mainwpVars.websitesToUpgrade.length;

  mainwpVars.bulkTaskRunning = true;
  mainwp_managesites_suspend_status_all_loop_next(status);
};

let mainwp_managesites_suspend_status_all_loop_next = function (status) {
  while (mainwpVars.bulkTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxThreads) && (mainwpVars.websitesLeft > 0)) {
    mainwp_managesites_suspend_status_all_upgrade_next(status);
  }
};
let mainwp_managesites_suspend_status_all_upgrade_next = function (status) {
  mainwpVars.currentThreads++;
  mainwpVars.websitesLeft--;

  let websiteId = mainwpVars.websitesToUpgrade[mainwpVars.currentWebsite++];
  dashboard_update_site_status(websiteId, '<i class="sync alternate loading icon"></i>');

  mainwp_managesites_suspend_status_int(websiteId, status);
};

let mainwp_managesites_suspend_status_int = function (siteid, status) {
  let data = mainwp_secure_data({
    action: 'mainwp_manage_sites_suspend_site',
    suspended: (status == 'suspend') ? 1 : 0,
    siteid: siteid,
  });

  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function (pSiteid) {
      return function (response) {
        mainwpVars.currentThreads--;
        mainwpVars.websitesDone++;
        mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);
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
