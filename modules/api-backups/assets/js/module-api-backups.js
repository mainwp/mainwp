/* eslint complexity: ["error", 100] */
let apibackups_bulkMaxThreads = 1; // rate limiting.
let apibackups_bulkTotalThreads = 0;
let apibackups_bulkCurrentThreads = 0;
let apibackups_bulkFinishedThreads = 0;

let mainwp_api_backups_do_backups = function (pObj) {
    // init queue status.
    jQuery('#mainwp-3rd-party-backups-table tbody td.check-column INPUT[type=checkbox]').each(function () {
        jQuery(this).attr('status', 'queue');
    });

    // Add loading icon to all selected sites.
    jQuery('#mainwp-3rd-party-backups-table tbody td.check-column INPUT[type=checkbox]:checked').each(function () {
        let parent = jQuery(this).closest('tr');
        let statusEl = parent.find('.running');
        statusEl.html('<i class="clock outline icon"></i>');
    });

    // Get selected sites.
    let selector = '#mainwp-3rd-party-backups-table tbody td.check-column INPUT[type=checkbox]:checked[status=queue]';
    let selectedIds = jQuery.map(jQuery(selector), function (el) {
        return jQuery(el).val();
    });

    // Check if there are selected sites.
    if (selectedIds.length == 0) {

        // Show message.
        jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
        jQuery('#mainwp-api-backups-message-zone .content .message')
            .html('Please select at least one website.')
            ;
        return;
    }

    // Set queue metadata and proceed.
    apibackups_bulkTotalThreads = selectedIds.length;
    apibackups_bulkCurrentThreads = 0;
    apibackups_bulkFinishedThreads = 0;


    jQuery(pObj).addClass('disabled');
    mainwp_api_backups_do_backups_specific_next(selector);
}


let mainwp_api_backups_do_backups_specific_next = function (selector) {
    let objProcess = jQuery(selector + ':first');
    while (objProcess && objProcess.length > 0 && apibackups_bulkCurrentThreads < apibackups_bulkMaxThreads) { // NOSONAR - variables modified outside the function.
        objProcess.attr('status', 'proceed');
        mainwp_api_backups_do_backups_specific(objProcess, true, selector);
        objProcess = jQuery(selector + ':first');
    }

    if (apibackups_bulkTotalThreads > 0 && apibackups_bulkFinishedThreads == apibackups_bulkTotalThreads) {
        jQuery('#action_backup_selected_sites').removeClass('disabled');

        // Show message.
        jQuery('#mainwp-api-backups-message-zone').removeClass('red').addClass('green').show();
        jQuery('#mainwp-api-backups-message-zone .content .message')
            .html('API Backup requests sent. Please allow some time for the backup to complete, then Refresh Available Backups.')
            ;
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
let mainwp_api_backups_do_backups_specific = function (pObj, bulk, selector) {
    let parent = pObj.closest('tr');
    let statusEl = parent.find('.running');
    let providerName = parent.attr('provider-name');

    let data = mainwp_secure_data({
        action: 'mainwp_api_backups_selected_websites',
        websiteId: parent.attr('website-id'),
        bulk_backups: 1,
    });

    if (bulk) {
        apibackups_bulkCurrentThreads++;
    }

    jQuery(pObj).prop('checked', false);
    statusEl.html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        statusEl.html('');
        let err_msg = '';
        let succ_msg = '';
        let rsp = '';

        if (response && !response.success) {
            rsp = response.data;
            console.log(rsp);
            // Check for gridPane error..
            if (rsp && rsp['0'].code === 429) {
                err_msg = 'API rate limit has been met. Please wait 30 seconds and try again.';
            } else {
                // Everything else. (API provided error message)
                err_msg = rsp['0'].message;
            }
        } else if (response?.success) {
            succ_msg = __('Successfull');
        } else if (response?.error) { // Check for cPanel error..
            err_msg = __(response.error);
        } else {
            err_msg = _('Undefined error.');
        }

        if ('' != err_msg) {
            statusEl.html('<span data-inverted="" data-position="right center" data-tooltip="' + err_msg + '"><i class="red times icon"></i></span>');
        } else if ('' != succ_msg) {
            statusEl.html('<span data-inverted="" data-position="right center" data-tooltip="' + succ_msg + '"><i class="green check icon"></i></span>');
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

