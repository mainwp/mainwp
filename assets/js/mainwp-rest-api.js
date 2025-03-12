
let bulk_RestAPIMaxThreads = 1;
let bulk_RestAPICurrentThreads = 0;
let bulk_RestAPITotal = 0;
let bulk_RestAPIFinished = 0;
let bulk_RestAPITaskRunning = false;

jQuery(function($) {
    $('body').on('click', '.copy-to-clipboard', function () {
        alert('Copied!');
    });
    // Trigger Manage Bulk Actions
    jQuery(document).on('click', '#mainwp-do-rest-api-bulk-actions', function () {
        let action = jQuery("#mainwp-rest-api-bulk-actions-menu").dropdown("get value");
        if (action == 'delete' && ! bulk_RestAPITaskRunning ) {
            mainwp_restapi_bulk_remove_keys_confirm();
        }
        return false;
    });

});

let mainwp_restapi_remove_key_confirm = function (pCheckedBox) {
    let confirmMsg = __("You are about to delete the selected REST API Key?");
    mainwp_confirm(confirmMsg, function () { mainwp_restapi_bulk_remove_specific(pCheckedBox); });
}

let mainwp_restapi_bulk_remove_keys_confirm = function () {
    let confirmMsg = __("You are about to delete the selected REST API Key(s)?");
    mainwp_confirm(confirmMsg, function () { mainwp_restapi_bulk_init(); mainwp_restapi_remove_keys_next(); });
}

let mainwp_restapi_bulk_init = function () {
    jQuery('#mainwp-message-zone-apikeys').hide();
    if (!bulk_RestAPITaskRunning) {
        bulk_RestAPICurrentThreads = 0;
        bulk_RestAPITotal = 0;
        bulk_RestAPIFinished = 0;
        jQuery('.mainwp-rest-api-body-table-manage .check-column INPUT:checkbox').each(function () {
            jQuery(this).attr('status', 'queue')
        });
    }
};


let mainwp_restapi_remove_keys_next = function () {
    while ((checkedBox = jQuery('.mainwp-rest-api-body-table-manage .check-column INPUT:checkbox:checked[status="queue"]:first')) && (checkedBox.length > 0) && (bulk_RestAPICurrentThreads < bulk_RestAPIMaxThreads)) { // NOSONAR - variables modified in other functions.
        mainwp_restapi_bulk_remove_specific(checkedBox);
    }
    if ((bulk_RestAPITotal > 0) && (bulk_RestAPIFinished == bulk_RestAPITotal)) { // NOSONAR - modified outside the function.
        setHtml('#mainwp-message-zone-apikeys', __("Process completed. Reloading page..."));
        setTimeout(function () {
            window.location.href = location.href;
        }, 3000);
    }
}

let mainwp_restapi_bulk_remove_specific = function (pCheckedBox) {
    pCheckedBox.attr('status', 'running');
    let rowObj = pCheckedBox.closest('tr');
    bulk_RestAPICurrentThreads++;

    let id = rowObj.attr('key-ck-id');

    rowObj.html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Deleting ...' + '</td>');

    let data = mainwp_secure_data({
        action: 'mainwp_rest_api_remove_keys',
        keyId: id,
        api_ver: rowObj.closest('tbody').attr('id') === 'mainwp-rest-api-v2-body-table' ? 'v2': 'v1'
    });
    jQuery.post(ajaxurl, data, function (response) {
        bulk_RestAPICurrentThreads--;
        bulk_RestAPIFinished++;
        rowObj.html('<td colspan="999"></td>');
        let result = '';
        let error = '';
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

