/* logs module js */
jQuery(function ($) {

    if (jQuery('.mainwp-module-logs-content-wrap .ui.calendar').length > 0) {
        if (mainwpParams.use_wp_datepicker == 1) {
            jQuery('.mainwp-module-logs-content-wrap .ui.calendar input[type=text]').datepicker({ dateFormat: "yy-mm-dd" });
        } else {
            mainwp_init_ui_calendar('.mainwp-module-logs-content-wrap .ui.calendar');
        }
    }

    jQuery('.mainwp-acts-logs-big-child-db-cleanup-btn').on('click', function () {
        let msg = MainWP.I18n.t('Are you sure you want to clean up activity logs for the selected site?');
        mainwp_confirm(msg, () => {
            let data = mainwp_secure_data({
                action: 'mainwp_module_log_clean_up_child_logs',
                siteid: jQuery(this).data('siteid'),
            });

            $(this).attr('disabled', 'disabled');

            let btn = $(this);

            mainwp_set_message_zone('#mainwp-message-zone', MainWP.I18n.t('Running ...'), 'green');
            jQuery.post(ajaxurl, data, function (response) {
                mainwp_set_message_zone('#mainwp-message-zone');
                if (response?.error) {
                    mainwp_set_message_zone('#mainwp-message-zone', '<i class="close icon"></i>' + response.error, 'red');
                } else if (response?.success) {
                    mainwp_set_message_zone('#mainwp-message-zone', '<i class="close icon"></i>' + MainWP.I18n.t('Log records were cleaned up successfully.'), 'green');
                    $(btn).closest('.item').fadeOut(3000);
                    return;
                } else {
                    mainwp_set_message_zone('#mainwp-message-zone', '<i class="close icon"></i>' + MainWP.I18n.t('Undefined error. Please try again.'), 'red');
                }
                $(btn).attr('disabled', false);
            }, 'json');
        });
        return false;
    });

});
