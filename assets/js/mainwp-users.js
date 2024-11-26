/**
 * MainWP_User.page
 */
let userCountSent = 0;
let userCountReceived = 0;


let import_user_stop_by_user = false;
let import_user_current_line_number = 0;
let import_user_total_import = 0;
let import_user_count_created_users = 0;
let import_user_count_create_fails = 0;

jQuery(function () {

    // Fetch users
    jQuery(document).on('click', '#mainwp_show_users', function () {
        mainwp_fetch_users();
    });

    // Delete single user
    jQuery(document).on('click', '.user_submitdelete', function () {
        let confirmation = confirm('Are you sure you want to proceed?');

        if (confirmation) {
            mainwpuser_postAction(jQuery(this), 'delete');
            return;
        }

        return false;
    });

    // Edit single user
    jQuery(document).on('click', '.user_getedit', function () {
        jQuery('td.check-column input[type="checkbox"]').each(function () {
            this.checked = false;
        });
        mainwp_edit_users_box_init();
        jQuery('#mainwp-edit-users-modal').modal('setting', 'closable', false).modal('show');
        mainwpuser_postAction(jQuery(this), 'edit');
        return false;
    });

    // Trigger Manage Users bulk actions
    jQuery(document).on('click', '#mainwp-do-users-bulk-actions', function () {
        let action = jQuery('#mainwp-bulk-actions').val();
        if (action == 'none')
            return;

        let tmp = jQuery("input[name='user[]']:checked");
        userCountSent = tmp.length;

        if (userCountSent == 0)
            return;

        let _callback = function () {

            if (action == 'edit') {
                jQuery('#mainwp-edit-users-modal').modal('setting', 'closable', false).modal('show');
                mainwp_edit_users_box_init();
                return;
            }

            jQuery('#mainwp-do-users-bulk-actions').attr('disabled', 'true');
            tmp.each(
                function (index, elem) {
                    mainwpuser_postAction(elem, action);
                }
            );
        };

        if (action == 'delete') {
            let msg = __('You are about to delete %1 user(s). Are you sure you want to proceed?', userCountSent);
            mainwp_confirm(msg, _callback);
            return;
        }

        _callback();
    });


    jQuery(document).on('click', '#mainwp_btn_update_user', function () {
        let errors = [];
        let tmp = jQuery("input[name='user[]']:checked");
        userCountSent = tmp.length;

        if (userCountSent == 0) {
            errors.push(__('Please search and select users.'));
        }

        if (errors.length > 0) {
            jQuery('#mainwp_update_password_error').html(errors.join('<br />'));
            jQuery('#mainwp_update_password_error').show();
            return;
        }

        jQuery('#mainwp_update_password_error').hide();
        jQuery('#mainwp_users_updating').show();

        jQuery('#mainwp-do-users-bulk-actions').attr('disabled', 'true');
        jQuery('#mainwp_btn_update_user').attr('disabled', 'true');

        tmp.each(
            function (index, elem) {
                mainwpuser_postAction(elem, 'update_user');
            }
        );
    });
});

let mainwp_edit_users_box_init = function () {

    jQuery('form#update_user_profile select#role option[value="donotupdate"]').prop('selected', true);
    jQuery('form#update_user_profile select#role').prop("disabled", false);
    jQuery('form#update_user_profile input#first_name').val('');
    jQuery('form#update_user_profile input#last_name').val('');
    jQuery('form#update_user_profile input#nickname').val('');
    jQuery('form#update_user_profile input#email').val('');
    jQuery('form#update_user_profile input#url').val('');
    jQuery('form#update_user_profile select#display_name').empty().attr('disabled', 'disabled');
    jQuery('form#update_user_profile #description').val('');
    jQuery('form#update_user_profile input#password').val('');
};

let mainwpuser_postAction = function (elem, what) {
    let rowElement = jQuery(elem).parents('tr');
    let userId = rowElement.find('.userId').val();
    let userName = rowElement.find('.userName').val();
    let websiteId = rowElement.find('.websiteId').val();

    let data = mainwp_secure_data({
        action: 'mainwp_user_' + what,
        userId: userId,
        userName: userName,
        websiteId: websiteId,
        update_password: encodeURIComponent(jQuery('#password').val()) // to fix
    });

    if (what == 'update_user') {
        data['user_data'] = jQuery('form#update_user_profile').serialize();
    }




    rowElement.find('.row-actions').hide();
    if (what === 'delete') {
        rowElement.html('<td colspan="8"><i class="ui active inline loader tiny"></i>  Please wait</td>');
    }
    jQuery.post(ajaxurl, data, function (response) { // NOSONAR - complex.
        if (what == 'edit' && response?.user_data) {
            let roles_filter = ['administrator', 'subscriber', 'contributor', 'author', 'editor'];
            let disabled_change_role = false;
            if (response.user_data.role == '' || jQuery.inArray(response.user_data.role, roles_filter) === -1) {
                jQuery('form#update_user_profile select#role option[value="donotupdate"]').prop('selected', true);
                disabled_change_role = true;
            } else {
                jQuery('form#update_user_profile select#role option[value="' + response.user_data.role + '"]').prop('selected', true);
                if (response.is_secure_admin) {
                    disabled_change_role = true;
                }
            }

            if (disabled_change_role) {
                jQuery('form#update_user_profile select#role').attr('disabled', 'disabled');
            } else {
                jQuery('form#update_user_profile select#role').prop("disabled", false);
            }

            jQuery('form#update_user_profile input#first_name').val(response.user_data.first_name);
            jQuery('form#update_user_profile input#last_name').val(response.user_data.last_name);
            jQuery('form#update_user_profile input#nickname').val(response.user_data.nickname);
            jQuery('form#update_user_profile input#email').val(response.user_data.user_email);
            jQuery('form#update_user_profile input#url').val(response.user_data.user_url);
            jQuery('form#update_user_profile select#display_name').empty();
            jQuery('form#update_user_profile select#display_name').prop("disabled", false);
            if (response.user_data.public_display) {
                jQuery.each(response.user_data.public_display, function (index, value) {
                    let o = new Option(value);
                    if (value == response.user_data.display_name) {
                        o.selected = true;
                    }
                    jQuery('form#update_user_profile select#display_name').append(o);
                });
                jQuery('form#update_user_profile select#display_name option[value="' + response.user_data.display_name + '"]').prop('selected', true);
            }

            jQuery('form#update_user_profile #description').val(response.user_data.description);
            rowElement.find('td.check-column input[type="checkbox"]')[0].checked = true;
            return;
        }

        if (response.result) {
            rowElement.html('<td colspan="8"><i class="green check icon"></i> ' + response.result + '</td>');
        } else {
            rowElement.html('<td colspan="8"><i class="times red icon"></i> Undefined error. Please try again.</td>');
        }

        userCountReceived++;
        if (userCountReceived == userCountSent) {
            userCountReceived = 0;
            userCountSent = 0;
            jQuery('#mainwp-do-users-bulk-actions').prop("disabled", false);

            jQuery('#mainwp_btn_update_user').prop("disabled", false);
            jQuery('#mainwp_users_updating').hide();


            if (what == 'update_user' || what == 'delete') {
                jQuery('#mainwp_users_loading_info').show();
                jQuery('#mainwp-edit-users-modal').modal('hide');
                mainwp_fetch_users();
            }
        }
    }, 'json');

    return false;
};

// Fetch users from child sites
let mainwp_fetch_users = function () {
    let errors = [];
    let selected_sites = [];
    let selected_groups = [];
    let selected_clients = [];

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function () {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push(__('Please select at least one website or group or clients.'));
        }
    } else if (jQuery('#select_by').val() == 'client') {
        jQuery("input[name='selected_clients[]']:checked").each(function () {
            selected_clients.push(jQuery(this).val());
        });
        if (selected_clients.length == 0) {
            errors.push(__('Please select at least one website or group or clients.'));
        }
    } else {
        jQuery("input[name='selected_groups[]']:checked").each(function () {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push(__('Please select at least one website or group or clients.'));
        }
    }

    let role = "";
    let roles = jQuery("#mainwp_user_roles").dropdown("get value");
    if (roles !== null) {
        role = roles.join(',');
    }

    if (errors.length > 0) {
        mainwp_set_message_zone('#mainwp-message-zone', errors.join('<br />'), 'yellow');
        jQuery('#mainwp_users_loading_info').hide();
        return;
    } else {
        mainwp_set_message_zone('#mainwp-message-zone');
    }

    let name = jQuery('#mainwp_search_users').val();

    let data = mainwp_secure_data({
        action: 'mainwp_users_search',
        urole: role,
        search: name,
        'groups[]': selected_groups,
        'sites[]': selected_sites,
        'clients[]': selected_clients
    });

    jQuery('#mainwp-loading-users-row').show();

    jQuery.post(ajaxurl, data, function (response) {
        response = response.trim();
        jQuery('#mainwp-loading-users-row').hide();
        jQuery('#mainwp_users_loading_info').hide();
        jQuery('#mainwp_users_main').show();
        jQuery('#mainwp_users_wrap_table').html(response);
        // re-initialize datatable
        jQuery("#mainwp-users-table").DataTable().destroy();
        jQuery('#mainwp-users-table').DataTable({
            "responsive": true,
            "colReorder": { columns: ":not(.check-column):not(#mainwp-users-actions)" },
            "stateSave": true,
            "pagingType": "full_numbers",
            "order": [],
            "scrollX": true,
            "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            "columnDefs": [{
                "targets": 'no-sort',
                "orderable": false
            }],
            "preDrawCallback": function () {
                setTimeout(() => {
                    jQuery('#mainwp_users_wrap_table table .ui.dropdown').dropdown();
                    jQuery('#mainwp_users_wrap_table table .ui.checkbox').checkbox();
                    mainwp_datatable_fix_menu_overflow();
                    mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
                }, 1000);
            },
            select: {
                items: 'row',
                style: 'multi+shift',
                selector: 'tr>td:not(.not-selectable)'
            }
        }).on('select', function (e, dt, type, indexes) {
            if ('row' == type) {
                dt.rows(indexes)
                    .nodes()
                    .to$().find('td.check-column .ui.checkbox').checkbox('set checked');
            }
        }).on('deselect', function (e, dt, type, indexes) {
            if ('row' == type) {
                dt.rows(indexes)
                    .nodes()
                    .to$().find('td.check-column .ui.checkbox').checkbox('set unchecked');
            }
        }).on('columns-reordered', function () {
            console.log('columns-reordered');
            setTimeout(() => {
                jQuery('#mainwp_users_wrap_table table .ui.dropdown').dropdown();
                jQuery('#mainwp_users_wrap_table table .ui.checkbox').checkbox();
                mainwp_datatable_fix_menu_overflow();
                mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
            }, 1000);
        });
    });
};



/**
 * Bulk upload new user
 */
jQuery(function () {
    import_user_total_import = jQuery('#import_user_total_import').val();

    jQuery('#import_user_btn_import').on('click', function () {
        if (!import_user_stop_by_user) {
            import_user_stop_by_user = true;
            jQuery('#import_user_import_logging .log').append(_('Paused import by user.') + "\n");
            jQuery('#import_user_btn_import').val(__('Continue'));
            jQuery('#MainWPBulkUploadUserLoading').hide();
            if (import_user_count_create_fails > 0) {
                jQuery('#import_user_btn_save_csv').attr("style", 'display:inline-block;'); //Enable
            }
        } else {
            import_user_stop_by_user = false;
            jQuery('#import_user_import_logging .log').append(__('Continue import.') + "\n");
            jQuery('#import_user_btn_import').val(__('Pause'));
            jQuery('#MainWPBulkUploadUserLoading').show();
            if (import_user_count_create_fails > 0) {
                jQuery('#import_user_btn_save_csv').attr("style", 'display:inline-block;'); //Enable
            }
            mainwp_import_users_next();
        }
    });


    jQuery('#import_user_btn_save_csv').on('click', function () {
        let fail_data = '';
        jQuery('#import_user_import_failed_rows span').each(function () {
            fail_data += jQuery(this).html() + "\r\n";
        });
        let blob = new Blob([fail_data], { type: "text/plain;charset=utf-8" });
        saveAs(blob, "import_users_fails.csv");
    });

    if (jQuery('#import_user_do_import').val() == 1) {
        jQuery('#MainWPBulkUploadUserLoading').show();
        mainwp_import_users_next();
    }
});


window.mainwp_bulkupload_users = function () {
    if (jQuery('#import_user_file_bulkupload').val() == '') {
        feedback('mainwp-message-zone', __('Please enter CSV file for upload.'), 'yellow');
        jQuery('#import_user_file_bulkupload').parent().parent().addClass('form-invalid');
    } else {
        jQuery('#createuser').submit();
    }
};

let mainwp_import_users_next = function () {

    if (import_user_stop_by_user)
        return;

    import_user_current_line_number++;

    if (import_user_current_line_number > import_user_total_import) {
        mainwp_import_users_finished();
        return;
    }

    let import_data = jQuery('#user_import_csv_line_' + import_user_current_line_number).attr('encoded-data');
    let original_line = jQuery('#user_import_csv_line_' + import_user_current_line_number).attr('original-line');

    let decoded_data = false;
    let pos_data = [];
    let errors = [];

    try {
        decoded_data = JSON.parse(import_data);
    } catch (e) {
        decoded_data = false;
        errors.push(__('Invalid import data.'));
    }

    if (decoded_data) {
        jQuery('#import_user_import_logging .log').append('[' + import_user_current_line_number + '] ' + original_line + '\n');
        let valid = mainwp_import_users_valid_data(decoded_data);
        pos_data = valid.data;
        errors = valid.errors;
    }

    if (errors.length > 0) {
        jQuery('#import_user_import_failed_rows').append('<span>' + original_line + '</span>');
        jQuery('#import_user_import_logging .log').append('[' + import_user_current_line_number + ']>> Error - ' + errors.join(" ") + '\n');
        import_user_count_create_fails++;
        mainwp_import_users_next();
        return;
    }

    if (0 == pos_data.length) {
        console.log('Error: import user data!');
        return;
    }

    pos_data.action = 'mainwp_importuser';
    pos_data.line_number = import_user_current_line_number;
    let data = mainwp_secure_data(pos_data);

    //Add user via ajax!!
    jQuery.post(ajaxurl, data, function (response_data) {
        if (response_data.error != undefined)
            return;
        mainwp_import_users_response(response_data);
        mainwp_import_users_next();
    }, 'json');
};

/* eslint-disable complexity */
let mainwp_import_users_valid_data = function (decoded_data) { // NOSONAR  - complexity, multi user's fields.

    let errors = []; // array.
    let val_data = {}; // object.

    val_data.user_login = decoded_data.user_login == undefined ? '' : decoded_data.user_login;
    val_data.email = decoded_data.email == undefined ? '' : decoded_data.email;
    val_data.first_name = decoded_data.first_name == undefined ? '' : decoded_data.first_name;
    val_data.last_name = decoded_data.last_name == undefined ? '' : decoded_data.last_name;
    val_data.url = decoded_data.url == undefined ? '' : decoded_data.url;
    val_data.pass1 = decoded_data.pass1 == undefined ? '' : decoded_data.pass1;
    val_data.send_password = decoded_data.send_password == undefined ? '' : decoded_data.send_password;
    val_data.role = decoded_data.role == undefined ? '' : decoded_data.role;
    val_data.select_sites = decoded_data.select_sites == undefined ? '' : decoded_data.select_sites;
    val_data.select_groups = decoded_data.select_groups == undefined ? '' : decoded_data.select_groups;
    val_data.select_by = '';

    if (val_data.user_login == '') {
        errors.push(__('Please enter a username.'));
    }

    if (val_data.email == '') {
        errors.push(__('Please enter an email.'));
    }

    if (val_data.pass1 == '') {
        errors.push(__('Please enter a password.'));
    }

    let allowed_roles = ['subscriber', 'administrator', 'editor', 'author', 'contributor'];
    if (jQuery.inArray(val_data.role, allowed_roles) == -1) {
        errors.push(__('Please select a data role.'));
    }

    if (val_data.select_sites != '') {
        let selected_sites = val_data.select_sites.split(';');
        if (selected_sites.length == 0) {
            errors.push(__('Please select websites or groups to add a user.'));
        } else {
            val_data.select_sites = selected_sites;
            val_data.select_by = 'site';
        }
    } else {
        let selected_groups = val_data.select_groups.split(';');
        if (selected_groups.length == 0) {
            errors.push(__('Please select websites or groups to add a user.'));
        } else {
            val_data.select_groups = selected_groups;
            val_data.select_by = 'group';
        }
    }
    return {
        errors: errors,
        data: val_data
    };

};
/* eslint-enable complexity */

let mainwp_import_users_response = function (response_data) {
    let line_num = response_data.line_number;
    let okList = response_data.ok_list;
    let errorList = response_data.error_list;
    if (okList != undefined)
        for (let iok of okList) {
            import_user_count_created_users++;
            jQuery('#import_user_import_logging .log').append('[' + line_num + ']>> ' + iok + '\n');
        }

    if (errorList != undefined)
        for (let ie of errorList) {
            import_user_count_create_fails++;
            jQuery('#import_user_import_logging .log').append('[' + line_num + ']>> ' + ie + '\n');
        }

    if (response_data.failed_logging != '' && response_data.failed_logging != undefined) {
        jQuery('#import_user_import_failed_rows').append('<span>' + response_data.failed_logging + '</span>');
    }
    jQuery('#import_user_import_logging').scrollTop(jQuery('#import_user_import_logging .log').height());
};

let mainwp_import_users_finished = function () {
    jQuery('#import_user_btn_import').val('Finished').attr('disabled', 'true');
    jQuery('#MainWPBulkUploadUserLoading').hide();
    jQuery('#import_user_import_logging .log').append('\n' + __('Number of users to import: %1 Created users: %2 Failed: %3', import_user_total_import, import_user_count_created_users, import_user_count_create_fails) + '\n');
    if (import_user_count_create_fails > 0) {
        jQuery('#import_user_btn_save_csv').attr("style", 'display:inline-block;'); //Enable
    }
    jQuery('#import_user_import_logging').scrollTop(jQuery('#import_user_import_logging .log').height());
}
