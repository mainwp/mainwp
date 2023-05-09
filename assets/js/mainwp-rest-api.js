
bulk_RestAPIMaxThreads = 1;
bulk_RestAPICurrentThreads = 0;
bulk_RestAPITotal = 0;
bulk_RestAPIFinished = 0;
bulk_RestAPITaskRunning = false;

jQuery(document).ready(function ($) {
    $('body').on('click', '.copy-to-clipboard', function () {
        alert('Copied!');
    });
    // Trigger Manage Bulk Actions
    jQuery(document).on('click', '#mainwp-do-rest-api-bulk-actions', function () {
        var action = jQuery("#mainwp-rest-api-bulk-actions-menu").dropdown("get value");
        if (action == 'delete') {

            if (bulk_RestAPITaskRunning) {
                return false;
            }
            mainwp_restapi_bulk_remove_keys_confirm();
            return false;
        }
        return false;
    });

});

mainwp_restapi_remove_key_confirm = function (pCheckedBox) {
    confirmMsg = __("You are about to delete the selected REST API Key?");
    mainwp_confirm(confirmMsg, _callback = function () {mainwp_restapi_bulk_remove_specific(pCheckedBox); });
}

mainwp_restapi_bulk_remove_keys_confirm = function () {
    confirmMsg = __("You are about to delete the selected REST API Key(s)?");
    mainwp_confirm(confirmMsg, _callback = function () { mainwp_restapi_bulk_init(); mainwp_restapi_remove_keys_next(); });
}

mainwp_restapi_bulk_init = function () {
    jQuery('#mainwp-message-zone-apikeys').hide();
    if (bulk_RestAPITaskRunning == false) {
        bulk_RestAPICurrentThreads = 0;
        bulk_RestAPITotal = 0;
        bulk_RestAPIFinished = 0;
        jQuery('#mainwp-rest-api-body-table .check-column INPUT:checkbox').each(function () {
            jQuery(this).attr('status', 'queue')
        });
    }
};


mainwp_restapi_remove_keys_next = function () {
    while ((checkedBox = jQuery('#mainwp-rest-api-body-table .check-column INPUT:checkbox:checked[status="queue"]:first')) && (checkedBox.length > 0) && (bulk_RestAPICurrentThreads < bulk_RestAPIMaxThreads)) {
        mainwp_restapi_bulk_remove_specific(checkedBox);
    }
    if ((bulk_RestAPITotal > 0) && (bulk_RestAPIFinished == bulk_RestAPITotal)) {
        setHtml('#mainwp-message-zone-apikeys', __("Process completed. Reloading page..."));
        setTimeout(function () {
            window.location.href = location.href;
        }, 3000);
    }
}

mainwp_restapi_bulk_remove_specific = function (pCheckedBox) {
    pCheckedBox.attr('status', 'running');
    var rowObj = pCheckedBox.closest('tr');
    bulk_RestAPICurrentThreads++;

    var id = rowObj.attr('key-ck-id');

    rowObj.html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Deleting ...' + '</td>');

    var data = mainwp_secure_data({
        action: 'mainwp_rest_api_remove_keys',
        keyId: id
    });
    jQuery.post(ajaxurl, data, function (response) {
        bulk_RestAPICurrentThreads--;
        bulk_RestAPIFinished++;
        rowObj.html('<td colspan="999"></td>');
        var result = '';
        var error = '';
        if (response.error != undefined) {
            error = response.error;
        } else if (response.success == 'SUCCESS') {
            result = __('The REST API Key has been deleted.');
        }
        if (error != '') {
            rowObj.html('<td colspan="999"><i class="red times icon"></i>' + error + '</td>');
        } else {
            rowObj.html('<td colspan="999"><i class="green check icon"></i>' + result + '</td>');
        }
        setTimeout(function () {
            jQuery('tr[key-ck-id=' + id + ']').fadeOut(1000);
        }, 3000);

        mainwp_restapi_remove_keys_next();
    }, 'json');
};

