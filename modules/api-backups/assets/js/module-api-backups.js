var apibackups_bulkMaxThreads = 1; // rate limiting.
var apibackups_bulkTotalThreads = 0;
var apibackups_bulkCurrentThreads = 0;
var apibackups_bulkFinishedThreads = 0;

mainwp_api_backups_do_backups = function (pObj) {
    // init queue status.
    jQuery('#mainwp-3rd-party-backups-table tbody td.check-column INPUT[type=checkbox]').each(function () {
        jQuery(this).attr('status', 'queue');
    });

    // Add loading icon to all selected sites.
    jQuery('#mainwp-3rd-party-backups-table tbody td.check-column INPUT[type=checkbox]:checked').each(function () {
        var parent = jQuery(this).closest('tr');
        var statusEl = parent.find('.running');
        statusEl.html('<i class="clock outline icon"></i>');
    });

    // Get selected sites.
    var selector = '#mainwp-3rd-party-backups-table tbody td.check-column INPUT[type=checkbox]:checked[status=queue]';
    var selectedIds = jQuery.map(jQuery(selector), function (el) {
        return jQuery(el).val();
    });

    // Check if there are selected sites.
    if (selectedIds.length == 0) {
        jQuery('#backups_site_toast').removeClass('green');
        jQuery('#backups_site_toast').addClass('red')
            .toast({
                class: 'warning',
                position: 'top right',
                displayTime: 5000,
                message: 'Please select at least one website.',
            });
        return;
    }

    // Set queue metadata and proceed.
    apibackups_bulkTotalThreads = selectedIds.length;
    apibackups_bulkCurrentThreads = 0;
    apibackups_bulkFinishedThreads = 0;

    jQuery(pObj).addClass('disabled');
    mainwp_api_backups_do_backups_specific_next(selector);
}


mainwp_api_backups_do_backups_specific_next = function (selector) {
    while ((objProcess = jQuery(selector + ':first')) && (objProcess.length > 0) && (objProcess.length > 0) && (apibackups_bulkCurrentThreads < apibackups_bulkMaxThreads)) {
        objProcess.attr('status', 'proceed');
        mainwp_api_backups_do_backups_specific(objProcess, true, selector);
    }

    if (apibackups_bulkTotalThreads > 0 && apibackups_bulkFinishedThreads == apibackups_bulkTotalThreads) {
        jQuery('#action_backup_selected_sites').removeClass('disabled');
    }


}

/**
 * Do backup for specific site.
 *
 * This function is triggered by the mainwp_api_backups_do_backups_specific_next() function & is called recursively
 * until all sites are processed.
 *
 * mainwp_api_backups_selected_websites() `action` is defined in assets/classes/class-api-backups-handler.php admin_init(),
 * & fires off ajax_backups_selected_websites() function also defined in assets/classes/class-api-backups-handler.php
 */
mainwp_api_backups_do_backups_specific = function (pObj, bulk, selector) {
    var parent = pObj.closest('tr');
    var statusEl = parent.find('.running');
    var providerName = parent.attr('provider-name');

    var data = mainwp_secure_data({
        action: 'mainwp_api_backups_selected_websites',
        websiteId: parent.attr('website-id'),
    });

    if (bulk) {
        apibackups_bulkCurrentThreads++;
    }

    jQuery(pObj).prop('checked', false);
    statusEl.html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        statusEl.html('');
        var err_msg = '';
        var succ_msg = '';
        var rsp = '';

        //console.log ( response );

        if (response && response.success == false) {
            rsp = response.data;
            console.log(rsp);
            // Check for gridPane error..
            if (rsp && rsp['0'].code === 429) {
                err_msg = 'API rate limit has been met. Please wait 30 seconds and try again.';
            } else {
                // Everything else. (API provided error message)
                err_msg = rsp['0'].message;
            }
        } else if (response && response.success) {
            succ_msg = __('Successfull');
        } else if (response && response.error) { // Check for cPanel error..
            err_msg = __(response.error);
        } else {
            err_msg = _('Undefined error.');
        }

        if ('' != err_msg) {
            statusEl.html('<span data-inverted="" data-position="right center" data-tooltip="' + err_msg + '"><i class="red times icon"></i></span>');
        } else if ('' != succ_msg) {
            statusEl.html('<span data-inverted="" data-position="right center" data-tooltip="' + succ_msg + '"><i class="green check icon"></i></span>');
            jQuery('#backups_site_toast').addClass('green')
            if (providerName === 'GridPane') {
                jQuery.toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 29000,
                    message: 'Backup requested. Next backup will start in 30 seconds.',
                });
            } else {
                jQuery.toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Backup requested.',
                });
            }

        }

        if (bulk) {
            apibackups_bulkCurrentThreads--;
            apibackups_bulkFinishedThreads++;
            if (providerName === 'GridPane') {
                // GridPane API BETA has Strict rate limits. However, rate limits will increase and vary between
                // endpoints once the API is out of BETA.
                setTimeout(function () {
                    mainwp_api_backups_do_backups_specific_next(selector);
                }, 30000); // 30 second Rate limit.
            } else {
                mainwp_api_backups_do_backups_specific_next(selector);
            }
        }

    }, 'json');
    return false;
}

