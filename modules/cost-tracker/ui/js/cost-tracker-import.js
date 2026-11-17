// eslint-disable complexity
/* eslint complexity: ["error", 100] */

let import_cost_current = 0;
let import_cost_total = 0;
let import_cost_count_success = 0;
let import_cost_count_fails = 0;
let import_cost_stop_by_user = false;

// Uploaded CSV rows and server messages are data, not markup — escape
// everything of that origin before it lands in the progress log HTML.
const mainwp_cost_tracker_import_esc = function (text) {
    return jQuery('<span>').text(text ?? '').html();
};

jQuery(document).ready(function () {
    import_cost_total = Number.parseInt(jQuery('#mainwp_managecosts_total_import').val());
    if (1 ===  Number.parseInt(jQuery('#mainwp_managecosts_do_import').val())) {
        mainwp_cost_tracker_import_cost();
    }
});

jQuery(document).on('click', '#mainwp_managecosts_btn_import', function () {
    if (import_cost_stop_by_user) {
        import_cost_stop_by_user = false;
        jQuery('#mainwp_cost_tracker_import_logging .log').append('Continue import.<br/>');
        jQuery(this).val('Pause');
        mainwp_cost_tracker_import_cost();
    } else {
        import_cost_stop_by_user = true;
        jQuery('#mainwp_cost_tracker_import_logging .log').append('Paused import by user.<br/>');
        jQuery(this).val('Continue');
    }
});

jQuery(document).on('click', '#mainwp-import-costs-modal-try-again', function () {
    location.reload();
});

const mainwp_cost_tracker_import_cost = function () { // NOSONAR - complex.
    if (import_cost_stop_by_user) {
        return;
    }

    jQuery('#mainwp-importing-costs').hide();
    import_cost_current++;

    if (import_cost_current > import_cost_total) {
        jQuery('#mainwp-import-costs-status-message').hide();
        jQuery('#mainwp_managecosts_btn_import').attr('disabled', 'disabled');

        if (0 === import_cost_count_fails) {
            jQuery('#mainwp_cost_tracker_import_logging .log').html('<div style="text-align:center;margin:50px 0;"><h2 class="ui icon header"><i class="green check icon"></i><div class="content">Congratulations!<div class="sub header">' + import_cost_count_success + ' costs imported successfully.</div></div></h2></div>');
            jQuery('#mainwp_managecosts_btn_import').hide();
            setTimeout(function () {
                location.reload();
            }, 2000);
        } else {
            jQuery('#mainwp_cost_tracker_import_logging .log').append('<div class="ui yellow message">Process completed with errors. ' + import_cost_count_success + ' cost(s) imported successfully, ' + import_cost_count_fails + ' cost(s) failed to import. Please review logs to resolve problems and try again.</div>');
            jQuery('#mainwp_managecosts_btn_import').hide();
            jQuery('#mainwp-import-costs-modal-try-again').show();
        }

        jQuery('#mainwp_cost_tracker_import_logging').scrollTop(jQuery('#mainwp_cost_tracker_import_logging .log').height());
        return;
    }

    const row_element = jQuery('#mainwp_managecosts_import_csv_line_' + import_cost_current);
    const encoded_data = row_element.attr('encoded-data');
    const original_line = row_element.attr('original');

    let decoded_cost_val = {};
    try {
        decoded_cost_val = JSON.parse(encoded_data);
    } catch (error) {
        jQuery('#mainwp_cost_tracker_import_logging .log').append('<strong>[' + import_cost_current + '] &lt;&lt; ' + mainwp_cost_tracker_import_esc(original_line) + '</strong><br/>');
        jQuery('#mainwp_cost_tracker_import_logging .log').append('<span style="color:red;">ERROR: Invalid JSON data - ' + mainwp_cost_tracker_import_esc(error.message) + '</span><br/>');
        jQuery('#mainwp_cost_tracker_import_fail_logging').append(original_line + '\n');
        import_cost_count_fails++;
        jQuery('#mainwp_cost_tracker_import_logging').scrollTop(jQuery('#mainwp_cost_tracker_import_logging .log').height());
        mainwp_cost_tracker_import_cost();
        return;
    }

    const cost_name = decoded_cost_val?.['cost.name'] ?? '';
    const cost_url = decoded_cost_val?.['cost.url'] ?? '';
    const cost_type = decoded_cost_val?.['cost.type'] ?? '';
    const cost_product_type = decoded_cost_val?.['cost.product_type'] ?? '';
    const cost_license_type =  decoded_cost_val?.['cost.license_type'] ?? '';
    const cost_price = decoded_cost_val?.['cost.price'] ?? '';
    const cost_payment_method = decoded_cost_val?.['cost.payment_method'] ?? '';
    const cost_renewal_type = decoded_cost_val?.['cost.renewal_type'] ?? '';
    const cost_last_renewal = decoded_cost_val?.['cost.last_renewal'] ?? '';
    const cost_status = decoded_cost_val?.['cost.cost_status'] ?? '';
    const select_sites = decoded_cost_val?.['cost.select_sites'] ?? [];

    jQuery('#mainwp_cost_tracker_import_logging .log').append('<strong>[' + import_cost_current + '] &lt;&lt; ' + mainwp_cost_tracker_import_esc(original_line) + '</strong><br/>');

    const errors = [];
    if ('' === cost_name) {
        errors.push('Please enter the cost name.');
    }
    if ('' === cost_price) {
        errors.push('Please enter the cost price.');
    }

    if (errors.length > 0) {
        jQuery('#mainwp_cost_tracker_import_logging .log').append('<span style="color:red;">ERROR: ' + errors.join(' ') + '</span><br/>');
        jQuery('#mainwp_cost_tracker_import_fail_logging').append(original_line + '\n');
        import_cost_count_fails++;
        mainwp_cost_tracker_import_cost();
        return;
    }

    const cost_object = {
        cost: {
            name: cost_name,
            url: cost_url,
            type: cost_type,
            product_type: cost_product_type,
            license_type: cost_license_type,
            price: cost_price,
            payment_method: cost_payment_method,
            renewal_type: cost_renewal_type,
            last_renewal: cost_last_renewal,
            cost_status: cost_status,
            select_sites: select_sites
        }
    };

    const data = mainwp_secure_data({
        action: 'mainwp_cost_tracker_import_cost',
        encoded_data: JSON.stringify(cost_object)
    });

    jQuery.post(ajaxurl, data, function (response) {
        if (response?.success) {
            import_cost_count_success++;
            jQuery('#mainwp_cost_tracker_import_logging .log').append('<span style="color:green;">SUCCESS</span><br/>');
        } else {
            import_cost_count_fails++;
            const error_message = response?.data?.message ?? 'Unknown error occurred.';
            jQuery('#mainwp_cost_tracker_import_logging .log').append('<span style="color:red;">ERROR: ' + mainwp_cost_tracker_import_esc(error_message) + '</span><br/>');
            jQuery('#mainwp_cost_tracker_import_fail_logging').append(original_line + '\n');
        }
        jQuery('#mainwp_cost_tracker_import_logging').scrollTop(jQuery('#mainwp_cost_tracker_import_logging .log').height());
        mainwp_cost_tracker_import_cost();
    }, 'json');
};
