/* eslint-disable complexity */
// current complexity is the only way to achieve desired results, pull request solutions appreciated.

window.mainwpVars = window.mainwpVars || {};

mainwpVars.maxRunThreads = mainwpParams?.maximumUptimeMonitoringRequests ? mainwpParams.maximumUptimeMonitoringRequests : 10;

// Trigger Manage Sites Bulk Actions
jQuery(document).on('click', '#mainwp-do-monitors-bulk-actions', function () {
  let action = jQuery("#mainwp-uptime-monitoring-bulk-actions-menu").dropdown("get value");
  if (action) {
    mainwp_managemonitors_doaction(action);
  }
  return false;
});

jQuery(document).on('click', '.managemonitors_uptime_checknow', function () {
  let row = jQuery(this).closest('tr');
  let checkItems = [];
  checkItems.push({ siteid: row.attr('siteid'), id: row.attr('itemid'), url: row.attr('urlpage'), niceurl: row.attr('niceurl') });
  mainwp_uptime_monitor_check_now(checkItems);
  return false;
});

window.mainwp_uptime_monitor_check_now = function (itemsData) {
  let numOfItems = itemsData.length;
  jQuery('#mainwp-sync-sites-modal #sync-sites-status').html('');
  mainwpPopup('#mainwp-sync-sites-modal').init({
    title: __('Checking Uptime Status'),
    progressMax: numOfItems,
    statusText: 'checked',
    callback: function () {
      mainwpVars.bulkTaskRunning = false;
      history.pushState("", document.title, window.location.pathname + window.location.search); // to fix issue for url with hash
      window.location.href = location.href;
    }
  });
  uptime_monitoring_prepare_items_rows(itemsData);
  mainwp_uptime_monitoring_check_uptime(itemsData);
};

let uptime_monitoring_prepare_items_rows = function (itemsData) {
  itemsData.forEach(function (item) {
    jQuery("#sync-sites-status").append(`
      <div class="item">
          <div class="right floated content">
              <div class="running-item-status" itemid="` + item.id + `" siteid="` + item.siteid + `" niceurl="` + item.niceurl + `"><span data-position="left center" data-inverted="" data-tooltip="` + __('Pending') + `"><i class="clock outline icon"></i></span></div>
          </div>
          <div class="content">` + item.url + `</div>
    </div>`);
  });
}

window.mainwp_uptime_monitoring_check_uptime = function (itemsData) {
  mainwpVars.itemsToCheck = [];
  mainwpVars.sitesIds = [];

  itemsData.forEach(function (item) {
    mainwpVars.itemsToCheck.push(item.id);
    if (item?.siteid) {
      mainwpVars.sitesIds.push(item.siteid);
    } else {
      mainwpVars.sitesIds.push(0);
    }
  });

  mainwpVars.currentItem = 0;
  mainwpVars.itemsDone = 0;
  mainwpVars.itemsTotal = mainwpVars.itemsLeft = mainwpVars.itemsToCheck.length;
  mainwpVars.bulkTaskRunning = true;
  if (mainwpVars.itemsTotal == 0) {
    uptime_monitoring_check_done();
  } else {
    uptime_monitoring_loop_next();
  }
};

window.mainwp_uptime_monitoring_check_set_item_status = function (itemId, newStatus, isSuccess) {
  jQuery('.running-item-status[itemid="' + itemId + '"]').html(newStatus);
  // Move successfully synced site to the bottom of the sync list.
  if (typeof isSuccess !== 'undefined' && isSuccess) {
    let row = jQuery('.running-item-status[itemid="' + itemId + '"]').closest('.item');
    jQuery(row).insertAfter(jQuery("#sync-sites-status .item").last());
  }
};

let uptime_monitoring_check_done = function () {
  mainwpVars.currentThreads--;
  if (!mainwpVars.bulkTaskRunning)
    return;
  mainwpVars.itemsDone++;
  if (mainwpVars.itemsDone > mainwpVars.itemsTotal)
    mainwpVars.itemsDone = mainwpVars.itemsTotal;
  mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.itemsDone);
  if (mainwpVars.itemsDone == mainwpVars.itemsTotal) {
    let successSites = jQuery('#mainwp-sync-sites-modal .check.green.icon').length;
    if (mainwpVars.itemsDone == successSites) {
      mainwpVars.bulkTaskRunning = false;
      setTimeout(function () {
        mainwpPopup('#mainwp-sync-sites-modal').close(true);
      }, 3000);
    } else {
      mainwpVars.bulkTaskRunning = false;
    }
    return;
  }
  uptime_monitoring_loop_next();
};

let uptime_monitoring_loop_next = function () {
  while (mainwpVars.bulkTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxRunThreads) && (mainwpVars.itemsLeft > 0)) {
    uptime_monitoring_check_next();
  }
};

let uptime_monitoring_check_next = function () {
  mainwpVars.currentThreads++;
  mainwpVars.itemsLeft--;
  let itemId = mainwpVars.itemsToCheck[mainwpVars.currentItem];
  let siteId = mainwpVars.sitesIds[mainwpVars.currentItem];

  mainwpVars.currentItem++;

  mainwp_uptime_monitoring_check_set_item_status(itemId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Checking uptime status...', 'mainwp') + '"><i class="sync alternate loading icon"></i></span>');
  let data = mainwp_secure_data({
    action: 'mainwp_uptime_monitoring_uptime_check',
    mo_id: itemId,
    wp_id: siteId
  });
  uptime_monitoring_check_next_int(itemId, data, 0);

};

let uptime_monitoring_check_next_int = function (itemId, data, errors) {
  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function (pItemId) {
      return function (response) {
        if (response.error) {
          let extErr = response.error;
          mainwp_uptime_monitoring_check_set_item_status(pItemId, '<span data-inverted="" data-position="left center" data-tooltip="' + extErr + '"><i class="exclamation red icon"></i></span>');
        } else {
          mainwp_uptime_monitoring_check_set_item_status(pItemId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Checking process completed successfully.', 'mainwp') + '"><i class="check green icon"></i></span>', true);
        }
        uptime_monitoring_check_done();
      }
    }(itemId),
    error: function (pItemId, pData, pErrors) {
      return function () {
        if (pErrors > 5) {
          mainwp_uptime_monitoring_check_set_item_status(pItemId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Process timed out. Please try again.', 'mainwp') + '"><i class="exclamation yellow icon"></i></span>');
          uptime_monitoring_check_done();
        } else {
          pErrors++;
          uptime_monitoring_check_next_int(pItemId, pData, pErrors);
        }
      }
    }(itemId, data, errors),
    dataType: 'json'
  });
};


// Manage Sites Bulk Actions
/* eslint-disable complexity */
let mainwp_managemonitors_doaction = function (action) { // NOSONAR - complex.

  if (mainwpVars.bulkTaskRunning) {
    return;
  }

  if (action == 'checknow') {
    let checkItems = [];
    jQuery('#mainwp-manage-sites-body-table .check-column .cb-uptime-monitor INPUT:checkbox:checked').each(function () {
      let row = jQuery(this).closest('tr');
      checkItems.push({ siteid: row.attr('siteid'), id: row.attr('itemid'), url: row.attr('urlpage'), niceurl: row.attr('niceurl') });
    });
    // if number monitors checked boxes is empty, unchecked all others and return.
    if (checkItems.length === 0) {
      jQuery('#mainwp-manage-sites-body-table .check-column .ui.checkbox').each(function () {
        jQuery(this).checkbox('set unchecked');
      });
      return;
    }

    console.log(checkItems);
    mainwp_uptime_monitor_check_now(checkItems);
    return false;
  } else if ('sync' === action) {
    managesites_bulk_init(true); // to sync sites not sub pages.
    mainwpVars.bulkManageSitesTaskRunning = true; // to compatible with: mainwp_sync_sites_data().
    let selectedIds = jQuery.map(jQuery("#mainwp-manage-sites-body-table .check-column INPUT:checkbox:not(.sub-pages-checkbox):checked"), function (el) {
      return jQuery(el).val();
    });
    mainwp_sync_sites_data(selectedIds);
  }
}