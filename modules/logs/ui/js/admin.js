/* logs module js */
jQuery(function ($) {

    if (jQuery('.mainwp-module-logs-content-wrap .ui.calendar').length > 0) {
        if (mainwpParams.use_wp_datepicker == 1) {
            jQuery('.mainwp-module-logs-content-wrap .ui.calendar input[type=text]').datepicker({ dateFormat: "yy-mm-dd" });
        } else {
            mainwp_init_ui_calendar('.mainwp-module-logs-content-wrap .ui.calendar');
        }
    }

    $('#logs_delete_records_button').on('click', function () {
        mainwp_set_message_zone('#mainwp-message-zone');

        let str_startdate = jQuery('#log_delete_records_startdate').val();
        let str_enddate = jQuery('#log_delete_records_enddate').val();
        let errors = [];
        if (str_startdate == '' || str_enddate == '') {
            errors.push(__('Please select Start Date and End Date.'));
        }
        if (errors.length > 0) {
            mainwp_set_message_zone('#mainwp-message-zone', '<i class="close icon"></i>' + errors, 'red');
            return;
        }

        let msg = __('Are you sure you want to delete logs for selected date?');

        mainwp_confirm(msg, function () {
            let data = mainwp_secure_data({
                action: 'mainwp_module_log_delete_records',
                startdate: jQuery('#log_delete_records_startdate').val(),
                enddate: jQuery('#log_delete_records_enddate').val(),
            });
            mainwp_set_message_zone('#mainwp-message-zone', __('Running ...'), 'green');
            jQuery.post(ajaxurl, data, function (response) {
                mainwp_set_message_zone('#mainwp-message-zone');
                if (response.error) {
                    mainwp_set_message_zone('#mainwp-message-zone', '<i class="close icon"></i>' + response.error, 'red');
                } else if (response.result) {
                    mainwp_set_message_zone('#mainwp-message-zone', '<i class="close icon"></i>' + __('Logs records has been deleted successfully.'), 'green');
                } else {
                    mainwp_set_message_zone('#mainwp-message-zone', '<i class="close icon"></i>' + __('Undefined error. Please try again.'), 'red');
                }
            }, 'json');
        });

        return false;
    });

    jQuery('#logs_compact_records_button').on('click', function () {
        let year = jQuery('#mainwp_module_log_compact_year').dropdown('get value');
        mainwp_set_message_zone('#mainwp-message-zone');
        if (0 == year) {
            mainwp_set_message_zone('#mainwp-message-zone', '<i class="close icon"></i>' + __('Please select year.'), 'red');
            return;
        }

        let msg = __('Are you sure you want to compact logs for selected year?');

        mainwp_confirm(msg, function () {
            let data = mainwp_secure_data({
                action: 'mainwp_module_log_compact_records',
                year: year,
            });
            mainwp_set_message_zone('#mainwp-message-zone', __('Running ...'), 'green');
            jQuery.post(ajaxurl, data, function (response) {
                mainwp_set_message_zone('#mainwp-message-zone');
                if (response.error) {
                    mainwp_set_message_zone('#mainwp-message-zone', '<i class="close icon"></i>' + response.error, 'red');
                } else if (response.result) {
                    mainwp_set_message_zone('#mainwp-message-zone', '<i class="close icon"></i>' + __('Logs records has been compact successfully.'), 'green');
                } else {
                    mainwp_set_message_zone('#mainwp-message-zone', '<i class="close icon"></i>' + __('Undefined error. Please try again.'), 'red');
                }
            }, 'json');
        });
        return false;
    });

});
