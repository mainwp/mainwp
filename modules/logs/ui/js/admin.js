/* logs module js */
jQuery(document).ready(function ($) {

    if (jQuery('.mainwp-module-logs-content-wrap .ui.calendar').length > 0) {
        if (mainwpParams.use_wp_datepicker == 1) {
            jQuery('.mainwp-module-logs-content-wrap .ui.calendar input[type=text]').datepicker({ dateFormat: "yy-mm-dd" });
        } else {
            jQuery('.mainwp-module-logs-content-wrap .ui.calendar').calendar({
                type: 'date',
                monthFirst: false,
                today: true,
                touchReadonly: false,
                formatter: {
                    date: function (date) {
                        if (!date) return '';
                        var day = date.getDate();
                        var month = date.getMonth() + 1;
                        var year = date.getFullYear();

                        if (month < 10) {
                            month = '0' + month;
                        }
                        if (day < 10) {
                            day = '0' + day;
                        }
                        return year + '-' + month + '-' + day;
                    }
                }
            });
        }
    }

    $('#logs_delete_records_button').on('click', function () {
        jQuery('#mainwp-message-zone').html("").hide();
        jQuery('#mainwp-message-zone').removeClass('red yellow green');

        var str_startdate = jQuery('#log_delete_records_startdate').val();
        var str_enddate = jQuery('#log_delete_records_enddate').val();
        var errors = [];
        if (str_startdate == '' || str_enddate == '') {
            errors.push(__('Please select Start Date and End Date.'));
        }
        if (errors.length > 0) {
            jQuery('#mainwp-message-zone').html('<i class="close icon"></i>' + errors).addClass('red').show();
            return;
        }

        var msg = __('Are you sure you want to delete logs for selected date?');

        mainwp_confirm(msg, function () {
            var data = mainwp_secure_data({
                action: 'mainwp_module_log_delete_records',
                startdate: jQuery('#log_delete_records_startdate').val(),
                enddate: jQuery('#log_delete_records_enddate').val(),
            });
            jQuery('#mainwp-message-zone').html(__('Running ...')).addClass('green').show();
            jQuery.post(ajaxurl, data, function (response) {
                jQuery('#mainwp-message-zone').removeClass('red yellow green');
                if (response.error) {
                    jQuery('#mainwp-message-zone').html('<i class="close icon"></i>' + response.error).addClass('red');
                } else if (response.result) {
                    jQuery('#mainwp-message-zone').html('<i class="close icon"></i>' + __('Logs records has been deleted successfully.')).addClass('green');
                } else {
                    jQuery('#mainwp-message-zone').html('<i class="close icon"></i>' + __('Undefined error. Please try again.')).addClass('red');
                }
            }, 'json');
        });

        return false;
    });

    jQuery('#logs_compact_records_button').on('click', function () {
        var year = jQuery('#mainwp_module_log_compact_year').dropdown('get value');
        jQuery('#mainwp-message-zone').html("").hide();
        jQuery('#mainwp-message-zone').removeClass('red yellow green');

        if (0 == year) {
            jQuery('#mainwp-message-zone').html('<i class="close icon"></i>' + __('Please select year.')).addClass('red').show();
            return;
        }

        var msg = __('Are you sure you want to compact logs for selected year?');

        mainwp_confirm(msg, function () {
            var data = mainwp_secure_data({
                action: 'mainwp_module_log_compact_records',
                year: year,
            });

            jQuery('#mainwp-message-zone').html(__('Running ...')).addClass('green').show();
            jQuery.post(ajaxurl, data, function (response) {
                jQuery('#mainwp-message-zone').removeClass('red yellow green');
                if (response.error) {
                    jQuery('#mainwp-message-zone').html('<i class="close icon"></i>' + response.error).addClass('red');
                } else if (response.result) {
                    jQuery('#mainwp-message-zone').html('<i class="close icon"></i>' + __('Logs records has been compact successfully.')).addClass('green');
                } else {
                    jQuery('#mainwp-message-zone').html('<i class="close icon"></i>' + __('Undefined error. Please try again.')).addClass('red');
                }
            }, 'json');
        });
        return false;
    });

});
