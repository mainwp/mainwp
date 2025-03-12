let mainwp_module_cost_tracker_valid_input_data = function () {
    let errors = [];
    let selected_sites = [];
    let selected_groups = [];
    let selected_clients = [];

    if (jQuery.trim(jQuery('#mainwp_module_cost_tracker_edit_name').val()) == '') {
        errors.push('Title is required.');
    }

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function () {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push('Please select websites or groups or clients.');
        }
    } else if (jQuery('#select_by').val() == 'group') {
        jQuery("input[name='selected_groups[]']:checked").each(function () {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push('Please select websites or groups or clients.');
        }
    } else if (jQuery('#select_by').val() == 'client') {
        jQuery("input[name='selected_clients[]']:checked").each(function () {
            selected_clients.push(jQuery(this).val());
        });
        if (selected_clients.length == 0) {
            errors.push('Please select websites or groups or clients.');
        }
    }

    if (errors.length > 0) {
        jQuery('#mainwp-module-cost-tracker-error-zone').show();
        jQuery('#mainwp-module-cost-tracker-error-zone .error-message').html(errors.join('<br />'));
        return false;
    } else {
        jQuery('#mainwp-module-cost-tracker-error-zone').fadeOut(1000);
        jQuery('#mainwp-module-cost-tracker-error-zone .error-message').html("");
    }
    return true;
}

jQuery(document).on('click', '#mainwp-module-cost-tracker-save-tracker-button', function () {
    if (mainwp_module_cost_tracker_valid_input_data() === false) {
        scrollElementTop('mainwp-module-cost-tracker-error-zone');
        return false;
    }
});

jQuery(function ($) {
    // Check all checkboxes
    jQuery('#mainwp-module-cost-tracker-sites-table th input[type="checkbox"]').change(function () {
        let checkboxes = jQuery('#mainwp-module-cost-tracker-sites-table').find(':checkbox');
        if (jQuery(this).prop('checked')) {
            checkboxes.prop('checked', true);
        } else {
            checkboxes.prop('checked', false);
        }
    });

    jQuery('.mainwp-module-cost-tracker-score.label').tab();

    jQuery(document).on('click', '.subscription_menu_item_delete', function () {
        let objDel = jQuery(this);
        mainwp_confirm(__('Are you sure.'), function () {
            mainwp_module_cost_tracker_delete_start_specific(objDel, '', false);
        }, false, false, true);
    })

    // Trigger the bulk actions
    jQuery('#mainwp_module_cost_tracker_action_btn').on('click', function () {
        let bulk_act = jQuery('#mwp_cost_tracker_bulk_action').dropdown("get value");
        mainwp_module_cost_tracker_table_bulk_action(bulk_act);
    });

    jQuery('#mainwp-module-cost-tracker-settings-form .ui.calendar').calendar({
        type: 'date',
        monthFirst: false,
        formatter: {
            date: function (date) {
                if (!date) return '';
                let day = date.getDate();
                let month = date.getMonth() + 1;
                let year = date.getFullYear();

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

    jQuery(document).on('click', '.mainwp-edit-sub-note', function () {
        let parent = jQuery(this).closest('tr');
        let id = jQuery(parent).attr('item-id');
        let note = jQuery('#sub-notes-' + id + '-note').html();
        jQuery('#mainwp-notes-subs-html').html(note == '' ? __('No saved notes. Click the Edit button to edit site notes.') : note);
        jQuery('#mainwp-notes-subs-note').val(note);
        jQuery('#mainwp-notes-subs-subid').val(id);
        mainwp_module_cost_tracker_notes_show();
        return false;
    });

    $(document).on('click', '.module-cost-tracker-add-custom-product-types', function () {
        jQuery('.cost-tracker-product-types-bottom').before(jQuery(this).attr('add-custom-product-types-tmpl'));
        let justAdded = jQuery(this).prev().prev();
        jQuery(justAdded).find('.mainwp-module-cost-tracker-select-custom-product-types-icons').dropdown({
            onChange: function (val) {
                let parent = jQuery(this).closest('.cost_tracker_settings_product_categories_icon_wrapper');
                jQuery(parent).find('input[name="cost_tracker_custom_product_types[icon][]"]').val('deficon:' + val);
            }
        });
    });

    $(document).on('click', '.module-cost-tracker-add-custom-payment-methods', function () {
        jQuery('.cost-tracker-payment-methods-bottom').before(jQuery(this).attr('add-custom-payment-methods-tmpl'));
    });
});

jQuery(document).on('click', '#mainwp-notes-subs-cancel', function () {
    jQuery('#mainwp-notes-subs-status').html('');
    jQuery('#mainwp-notes-subs-status').removeClass('red green');
    jQuery('#mainwp-notes-subs-modal').modal('hide');
    return false;
});

jQuery(document).on('click', '#mainwp-notes-subs-save', function () {
    mainwp_module_cost_tracker_notes_save();
    let newnote = jQuery('#mainwp-notes-subs-note').val();
    jQuery('#mainwp-notes-subs-html').html(newnote);
    return false;
});

let mainwp_module_cost_tracker_notes_show = function () {
    jQuery('#mainwp-notes-subs-modal').modal('setting', 'closable', false).modal('show');
    jQuery('#mainwp-notes-subs-html').show();
    jQuery('#mainwp-notes-subs-editor').hide();
    jQuery('#mainwp-notes-subs-save').hide();
    jQuery('#mainwp-notes-subs-edit').show();
};

jQuery(document).on('click', '#mainwp-notes-subs-edit', function () {
    jQuery('#mainwp-notes-subs-html').hide();
    jQuery('#mainwp-notes-subs-editor').show();
    jQuery(this).hide();
    jQuery('#mainwp-notes-subs-save').show();
    jQuery('#mainwp-notes-subs-status').html('');
    jQuery('#mainwp-notes-subs-status').removeClass('red green');
    return false;
});

let mainwp_module_cost_tracker_notes_save = function () {
    let normalid = jQuery('#mainwp-notes-subs-subid').val();
    let newnote = jQuery('#mainwp-notes-subs-note').val();
    newnote = newnote.replace(/(?:\r\n|\r|\n)/g, '<br>');
    let data = mainwp_secure_data({
        action: 'mainwp_module_cost_tracker_notes_save',
        subid: normalid,
        note: newnote,
    });

    jQuery('#mainwp-notes-subs-status').html('<i class="notched circle loading icon"></i> ' + __('Saving note. Please wait...')).show();

    jQuery.post(ajaxurl, data, function (response) {
        if (response.error != undefined) {
            jQuery('#mainwp-notes-subs-status').html(response.error).addClass('red');
        } else if (response.result == 'SUCCESS') {
            jQuery('#mainwp-notes-subs-status').html(__('Note saved successfully.')).addClass('green');
            if (jQuery('#mainwp-notes-subs-' + normalid + '-note').length > 0) {
                jQuery('#mainwp-notes-subs-' + normalid + '-note').html(jQuery('#mainwp-notes-subs-note').val());
            }
        } else {
            jQuery('#mainwp-notes-subs-status').html(__('Undefined error occured while saving your note!')).addClass('red');
        }
    }, 'json');

    setTimeout(function () {
        jQuery('#mainwp-notes-subs-status').fadeOut(300);
    }, 3000);

    jQuery('#mainwp-notes-subs-html').show();
    jQuery('#mainwp-notes-subs-editor').hide();
    jQuery('#mainwp-notes-subs-save').hide();
    jQuery('#mainwp-notes-subs-edit').show();

};

let mod_costtracker_bulkMaxThreads = 4;
let mod_costtracker_bulkTotalThreads = 0;
let mod_costtracker_bulkCurrentThreads = 0;
let mod_costtracker_bulkFinishedThreads = 0;

// Manage Bulk Actions
let mainwp_module_cost_tracker_table_bulk_action = function (act) {
    let selector = '';
    if( 'delete-sub' === act) {
        selector += '#mainwp-module-cost-tracker-sites-table tbody tr';
        jQuery(selector).addClass('queue');
        mainwp_module_cost_tracker_delete_start_next(selector);
    }
}

let mainwp_module_cost_tracker_delete_start_next = function (selector) {
    if (mod_costtracker_bulkTotalThreads == 0) {
        mod_costtracker_bulkTotalThreads = jQuery('#mainwp-module-cost-tracker-sites-table tbody').find('input[type="checkbox"]:checked').length;
    }
    while ((objProcess = jQuery(selector + '.queue:first')) && (objProcess.length > 0) && (mod_costtracker_bulkCurrentThreads < mod_costtracker_bulkMaxThreads)) { // NOSONAR - variable modified outside of the function.
        objProcess.removeClass('queue');
        if (objProcess.closest('tr').find('input[type="checkbox"]:checked').length == 0) {
            continue;
        }
        mainwp_module_cost_tracker_delete_start_specific(objProcess, selector, true);
    }
}

let mainwp_module_cost_tracker_delete_start_specific = function (pObj, selector, pBulk) {
    let row = pObj.closest('tr');
    let subid = jQuery(row).attr('item-id');
    let bulk = pBulk;

    if (bulk) {
        mod_costtracker_bulkCurrentThreads++;
    }

    let data = mainwp_secure_data({
        action: 'mainwp_module_cost_tracker_delete',
        sub_id: subid,
    });

    row.html('<td></td><td colspan="999"><i class="notched circle loading icon"></i> Please wait...</td>');

    jQuery.post(ajaxurl, data, function (response) {
        pObj.removeClass('queue');
        if (response) {
            if (response['error']) {
                row.html('<td></td><td colspan="999"><i class="times red icon"></i> ' + response['error'] + ' Page will reload in 3 seconds.</td>');
            } else if (response['status'] == 'success') {
                row.html('<td></td><td colspan="999"><i class="green check icon"></i> The Cost has been deleted.</td>');
            } else {
                row.html('<td></td><td colspan="999"><i class="times red icon"></i> The Cost could not be deleted.</td>');
            }
        } else {
            row.html('<td></td><td colspan="999"><i class="times red icon"></i> The Cost could not be deleted.</td>');
        }

        if (bulk) {
            mod_costtracker_bulkCurrentThreads--;
            mod_costtracker_bulkFinishedThreads++;
            mainwp_module_cost_tracker_delete_start_next(selector);
            if (mod_costtracker_bulkTotalThreads == mod_costtracker_bulkFinishedThreads) {
                setTimeout(function () {
                    window.location.reload(true);
                }, 3000);
            }
        }

    }, 'json');
    return false;
}