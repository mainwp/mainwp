/**
 * MainWP Clients.page
 */
/* eslint-disable complexity */
let import_client_current = 0;
let import_client_total = 0;
let import_client_count_success = 0;
let import_client_count_fails = 0;
let import_client_stop_by_user = false;
jQuery(function () {

  // Delete single client.
  jQuery(document).on('click', '.client_deleteitem', function () {
    let confirmation = confirm('Are you sure you want to proceed?');
    if (confirmation) {
      let parent = jQuery(this).closest('div.menu');
      let rowElement = jQuery(this).parents('tr');
      let clientid = parent.attr('clientid');
      let data = mainwp_secure_data({
        action: 'mainwp_clients_delete_client',
        clientid: clientid,
      });
      jQuery.post(ajaxurl, data, function (response) {
        if (response.success) {
          rowElement.html('<td colspan="8"><i class="green check icon"></i> ' + response.result + '</td>');
        }
      }, 'json');
    }

    return false;
  });

  jQuery(document).on('click', '.client-suspend-unsuspend-sites', function () {
    let new_status = jQuery(this).attr('suspend-status') == '0' ? 1 : 0;
    let clientid = jQuery(this).closest('.mainwp-widget-footer').attr('client-id');
    let bt = this;

    let data = mainwp_secure_data({
      action: 'mainwp_clients_suspend_client',
      clientid: clientid,
      suspend_status: new_status
    });
    jQuery(bt).attr('disabled', true);
    jQuery.post(ajaxurl, data, function (response) {
      jQuery(bt).attr('disabled', false);
      if (response == 'success') {
        jQuery(bt).text(new_status == 0 ? __('Suspend Sites') : __('Unsuspend Sites'));
        jQuery(bt).attr('suspend-status', new_status);
      }
    });
  });

  jQuery('#mainwp_edit_clients_icon_select').dropdown({
    onChange: function (val) {
      jQuery('#client_fields\\[default_field\\]\\[selected_icon\\]').val(val);
      jQuery('#client_fields\\[default_field\\]\\[selected_icon\\]').trigger('change');
    }
  });

  jQuery('.mainwp-edit-clients-select-contact-icon').dropdown({
    onChange: function (val) {
      let parent = jQuery(this).closest('.mainwp_edit_clients_contact_icon_wrapper');
      let inname = parent.attr('input-name');
      if (undefined !== inname) {
        jQuery(parent).find('#client_fields\\[' + inname + '\\]\\[selected_icon\\]\\[\\]').val(val);
        jQuery(parent).find('#client_fields\\[' + inname + '\\]\\[selected_icon\\]\\[\\]').trigger('change');
      }
    }
  });

  jQuery(document).on('click', '.mainwp-client-add-contact', function () {
    let templ = jQuery(this).attr('add-contact-temp');
    jQuery('.after-add-contact-field').after(templ);
    let justAdded = jQuery('.after-add-contact-field').next().next().next().next().next().next();
    jQuery(justAdded).find('.mainwp-edit-clients-select-contact-icon').dropdown({
      onChange: function (val) {
        let parent = jQuery(this).closest('.mainwp_edit_clients_contact_icon_wrapper');
        let inname = parent.attr('input-name');
        if (undefined !== inname) {
          jQuery(parent).find('#client_fields\\[' + inname + '\\]\\[selected_icon\\]\\[\\]').val(val);
          jQuery(parent).find('#client_fields\\[' + inname + '\\]\\[selected_icon\\]\\[\\]').trigger('change');
        }
      }
    });
  });

  jQuery(document).on('click', '.mainwp-client-remove-contact', function () {

    if (jQuery(this).attr('contact-id') > 0) {
      jQuery('.after-add-contact-field').before('<input type="hidden" value="' + jQuery(this).attr('contact-id') + '" name="client_fields[delele_contacts][]">'); // to delete contact when submit the client.
    }

    let parent = jQuery(this).closest('.remove-contact-field-parent');
    let limit = 0;
    while (!parent.prev().hasClass('top-contact-fields')) {
      limit++;
      parent.prev().remove(); // prev contact field.
      if (limit > 50) break;
    }
    if (parent.prev().hasClass('top-contact-fields')) {
      parent.prev().remove();
    }
    if (parent.next().hasClass('bottom-contact-fields')) {
      parent.next().remove();
    }
    parent.remove();
  });

  // Handel Modal import client
  import_client_total = jQuery('#mainwp_manageclients_total_import').val();
  if (jQuery('#mainwp_manageclients_do_import').val() == 1) {
    mainwp_manageclient_import_client();
  }

  jQuery(document).on('click', '#mainwp_manageclients_btn_import', function () {
    if (!import_client_stop_by_user) {
      import_client_stop_by_user = true;
      jQuery('#mainwp_manageclients_import_logging .log').append(__('Paused import by user.') + "\n");
      jQuery('#mainwp_manageclients_btn_import').val(__('Continue'));
    } else {
      import_client_stop_by_user = false;
      jQuery('#mainwp_manageclients_import_logging .log').append(__('Continue import.') + "\n");
      jQuery('#mainwp_manageclients_btn_import').val(__('Pause'));
      mainwp_manageclient_import_client();
    }
  });

});


let bulkManageClientsMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
let bulkManageClientsCurrentThreads = 0;
let bulkManageClientsTotal = 0;
let bulkManageClientsFinished = 0;
let bulkManageClientsTaskRunning = false;


// Trigger Manage Bulk Actions
jQuery(document).on('click', '#mainwp-do-clients-bulk-actions', function () {
  let action = jQuery("#mainwp-clients-bulk-actions-menu").dropdown("get value");
  if (action) {
    mainwp_manageclients_doaction(action);
  }
  return false;
});


let mainwp_manageclients_doaction = function (action) {
  if (action && !bulkManageClientsTaskRunning) {
    let confirmMsg = '';
    if (action === 'delete') {
      confirmMsg = __("You are about to remove the selected clients from your MainWP Dashboard?");
    }
    mainwp_confirm(confirmMsg, function () { mainwp_manageclients_doaction_process(action); });
  }
  return false;
}



let mainwp_manageclients_doaction_process = function (action) {

  manageclients_bulk_init();

  bulkManageClientsTotal = jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]').length;
  bulkManageClientsTaskRunning = true;

  if (action == 'delete') {
    mainwp_manageclients_bulk_remove_next();
    return false;
  }
}



let mainwp_manageclients_bulk_remove_next = function () {
  while ((checkedBox = jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]:first')) && (checkedBox.length > 0) && (bulkManageClientsCurrentThreads < bulkManageClientsMaxThreads)) { // NOSONAR -- modified out side the function.
    mainwp_manageclients_bulk_remove_specific(checkedBox);
  }
  if ((bulkManageClientsTotal > 0) && (bulkManageClientsFinished == bulkManageClientsTotal)) { // NOSONAR -- modified out side the function.
    setHtml('#mainwp-message-zone-client', __("Process completed. Reloading page..."));
    setTimeout(function () {
      window.location.reload()
    }, 3000);
  }
}

let mainwp_manageclients_bulk_remove_specific = function (pCheckedBox) {

  pCheckedBox.attr('status', 'running');
  let rowObj = pCheckedBox.closest('tr');
  bulkManageClientsCurrentThreads++;

  let id = rowObj.attr('clientid');

  rowObj.html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Removing ...' + '</td>');

  let data = mainwp_secure_data({
    action: 'mainwp_clients_delete_client',
    clientid: id
  });
  jQuery.post(ajaxurl, data, function (response) {
    bulkManageClientsCurrentThreads--;
    bulkManageClientsFinished++;
    rowObj.html('<td colspan="999"></td>');
    let result = '';
    let error = '';
    if (response.error != undefined) {
      error = response.error;
    } else if (response.success == 'SUCCESS') {
      result = __('The client has been removed.');
    }

    if (error != '') {
      rowObj.html('<td colspan="999"><i class="red times icon"></i> ' + error + '</td>');
    } else {
      rowObj.html('<td colspan="999"><i class="green check icon"></i> ' + result + '</td>');
    }
    setTimeout(function () {
      jQuery('tr[clientid=' + id + ']').fadeOut(1000);
    }, 3000);

    mainwp_manageclients_bulk_remove_next();
  }, 'json');
};

let manageclients_bulk_init = function () {
  mainwp_set_message_zone('#mainwp-message-zone-client');
  if (!bulkManageClientsTaskRunning) {
    bulkManageClientsMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
    bulkManageClientsCurrentThreads = 0;
    bulkManageClientsTotal = 0;
    bulkManageClientsFinished = 0;
    jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox').each(function () {
      jQuery(this).attr('status', 'queue')
    });
  }
};

// Handle tab QSW add client
const mainwp_add_client_onvisible_callback = function (obj_item) {
  const tab = jQuery(obj_item).attr("data-tab");
  if (tab === 'multiple-client') {
    jQuery('#bulk_add_createclient').hide();
    jQuery('#mainwp_qsw_add_client_continue_button').show();
  } else if (tab === 'single-client') {
    jQuery('#mainwp_qsw_add_client_continue_button').show();
    jQuery('#bulk_add_multi_create_client').hide();
  }
}

// Handle remove row client
const mainwp_qsw_add_client_delete_row = function (index) {
  const row = jQuery("#mainwp-qsw-add-client-row-" + index);
  row.remove();
  return false;
}
// Handle show more input òn client multi client.
const mainwp_qsw_add_client_more_row = function (index) {
  jQuery(".mainwp-qsw-add-client-column-more-" + index).fadeToggle("slow");
  jQuery("#icon-visible-" + index).toggle();
  jQuery("#icon-hidden-" + index).toggle();
  return false;
}

// Track keyup events on Client Name and Client Email inputs.
jQuery(document).on('keyup change', '.mainwp-qsw-add-client-client-name, .mainwp-qsw-add-client-client-email, input[name^="client_fields"][name$="[client.contact.name][]"], input[name^="client_fields"][name$="[contact.email][]"]', function () {
  const current_row = jQuery(this).closest('.mainwp-qsw-add-client-rows');
  const client_name = current_row.find('.mainwp-qsw-add-client-client-name').val().trim();
  const client_email = current_row.find('.mainwp-qsw-add-client-client-email').val().trim();

  // If at least one of the two inputs Client Name or Client Email has data
  if (client_name !== '' || client_email !== '') {
    jQuery('#bulk_add_multi_create_client').show(); // Display Add Multi Client button.
    jQuery('#mainwp_qsw_add_client_continue_button').hide(); // Hide Continue button.
  } else {
    // If all fields are empty, hide the Add Multi Client button.
    let all_empty = true;
    jQuery('.mainwp-qsw-add-client-client-name, .mainwp-qsw-add-client-client-email, input[name^="client_fields"][name$="[client.contact.name][]"], input[name^="client_fields"][name$="[contact.email][]"]').each(function () {
      if (jQuery(this).val().trim() !== '') {
        all_empty = false;
      }
    });

    if (all_empty) {
      jQuery('#bulk_add_multi_create_client').hide(); // Hide Add Multi Client button.
      jQuery('#mainwp_qsw_add_client_continue_button').show(); // Display Continue button.
    }
  }
});

// Handle event click button  Add Multi Client
jQuery(document).on('click', '#bulk_add_multi_create_client', function (e) {
  let all_rows_valid = true;
  let errors = []; // Array declaration containing error messages.
  let form_data = []; // Initialize array containing form data
  // eslint-disable-next-line complexity
  jQuery('.mainwp-qsw-add-client-rows').each(function () { //phpcs:ignore -- NOSONAR -- complex
    let website_id = null;
    const row_index = jQuery(this).attr('id').replace('mainwp-qsw-add-client-row-', '');

    if (jQuery('#mainwp-qsw-add-client-website-id-' + row_index).length > 0) {
      website_id = jQuery('#mainwp-qsw-add-client-website-id-' + row_index)?.val().trim();
    }
    const site_url = jQuery('#mainwp-qsw-add-client-site-url-' + row_index).val().trim();
    const client_name = jQuery('#mainwp-qsw-add-client-client-name-' + row_index).val().trim();
    const client_email = jQuery('#mainwp-qsw-add-client-client-email-' + row_index).val().trim();
    const contact_name = jQuery('input[name="client_fields[' + row_index + '][new_contacts_field][client.contact.name][]"]').val().trim();
    const contact_email = jQuery('input[name="client_fields[' + row_index + '][new_contacts_field][contact.email][]"]').val().trim();
    const contact_role = jQuery('input[name="client_fields[' + row_index + '][new_contacts_field][contact.role][]"]').val().trim();


    // Check if the line has Client Name or Client Email, but is missing data.
    if (client_name !== '' || client_email !== '') {
      if (site_url === '' || client_name === '' || client_email === '') {
        all_rows_valid = false;
        errors.push(`The data in row ${(parseInt(row_index) + 1)} is incomplete!`);
      }

      if (contact_name !== '' || contact_email !== '') {
        if (contact_name === '' || contact_email === '') {
          all_rows_valid = false;
          errors.push(`The data in row ${(parseInt(row_index) + 1)} is incomplete!`);
        }
      }

      if ((!mainwp_validate_email(client_email) && client_email !== '') || (contact_email !== '' && !mainwp_validate_email(contact_email))) {
        all_rows_valid = false;
        errors.push(`Field email in row ${(parseInt(row_index) + 1)} is invalid!`);
      }

      // If All rows valid then add data to form_data
      if (all_rows_valid) {
        form_data.push({
          website_id: website_id,
          website_url: site_url,
          client_name: client_name,
          client_email: client_email,
          contacts_field: {
            contact_name: contact_name,
            contact_email: contact_email,
            contact_role: contact_role
          }
        });
      }
    }
  });

  // If there is a column with missing data, prevent submission and display a message.
  if (!all_rows_valid) {
    e.preventDefault(); //Prevent form submission or further processing.
    mainwp_set_message_zone('#mainwp-message-zone', errors.join('<br />'), 'red');
    return false;
  } else {
    let msg = __('Creating the client. Please wait...');
    jQuery('#mainwp-message-zone').html('').hide(); // Hide message error
    mainwp_set_message_zone('#mainwp-message-zone-client', '<i class="notched circle loading icon"></i> ' + msg); // show message creating.
    jQuery('#bulk_add_multi_create_client').attr('disabled', 'disabled'); // disable button

    const data = mainwp_secure_data({
      action: "mainwp_clients_add_multi_client",
      data: form_data,
    });
    jQuery.post(ajaxurl, data, function (response) {
      if (response?.success) {
        window.location.href = 'admin.php?page=mainwp-setup&step=monitoring&message=1';
      } else if (response?.error) {
        mainwp_set_message_zone('#mainwp-message-zone', response.error, 'red');
      } else {
        mainwp_set_message_zone('#mainwp-message-zone', __('Undefined error. Please try again.'), 'red');
      }
      jQuery('#bulk_add_multi_create_client').attr('disabled', false); // enable button
    });
    return true;
  }
});

jQuery(document).on('click', '#bulk_add_createclient', function () {
  let currPage = jQuery(this).attr('current-page');
  mainwp_createclient(currPage);
});

let mainwp_createclient = function (currPage) {
  if (jQuery('input[name="client_fields[default_field][client.name]"]').val() == '') {
    feedback('mainwp-message-zone-client', __('Client name field is required! Please enter a Client name.'), 'yellow');
    return;
  }

  let valid_contact = true;
  jQuery('input[name="client_fields[new_contacts_field][client.contact.name][]"]').each(function () {
    if (jQuery(this).val() == '') {
      valid_contact = false;
    }
  });
  jQuery('input[name="client_fields[new_contacts_field][contact.email][]"]').each(function () {
    if (jQuery(this).val() == '') {
      valid_contact = false;
    }
  });


  jQuery('input[name="client_fields[contacts_field][client.contact.name][]"]').each(function () {
    if (jQuery(this).val() == '') {
      valid_contact = false;
    }
  });
  jQuery('input[name="client_fields[contacts_field][client.contact.email][]"]').each(function () {
    if (jQuery(this).val() == '') {
      valid_contact = false;
    }
  });


  if (!valid_contact) {
    feedback('mainwp-message-zone-client', __('Contact Name and Contact Email are required. Please enter a Contact Name and Contact Email.'), 'yellow');
    return;
  }

  let selected_sites = [];
  jQuery("input[name='selected_sites[]']:checked").each(function () {
    selected_sites.push(jQuery(this).val());
  });

  if (jQuery('#select_by').val() == 'site') {
    selected_sites = [];
    jQuery("input[name='selected_sites[]']:checked").each(function () {
      selected_sites.push(jQuery(this).val());
    });
  }

  let is_first_client = false;
  if (jQuery("input[name=selected_first_site]").length > 0) {
    selected_sites.push(jQuery("input[name=selected_first_site]").val());
    is_first_client = true;
  }

  mainwp_set_message_zone('#mainwp-message-zone-client');
  let msg = __('Creating the client. Please wait...');
  if (jQuery('input[name="client_fields[client_id]"]').val() != 0) {
    msg = __('Updating the client. Please wait...');
  }
  mainwp_set_message_zone('#mainwp-message-zone-client', '<i class="notched circle loading icon"></i> ' + msg);
  jQuery('#bulk_add_createclient').attr('disabled', 'disabled');

  //Add user via ajax!!
  let formdata = new FormData(jQuery('#createclient_form')[0]);
  formdata.append("action", 'mainwp_clients_add_client');
  formdata.append("select_by", jQuery('#select_by').val());
  formdata.append("selected_sites[]", selected_sites);
  formdata.append("is_first_client", is_first_client);
  formdata.append("security", security_nonces['mainwp_clients_add_client']);

  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: formdata,
    success: function (response) {
      mainwp_set_message_zone('#mainwp-message-zone-client');
      jQuery('#bulk_add_createclient').prop("disabled", false);
      if (response?.success) {
        if ('add-new' == currPage) {
          window.location.href = "admin.php?page=ManageClients";
        } else if ('qsw-add' == currPage) {
          window.location.href = 'admin.php?page=mainwp-setup&step=monitoring&message=1';
        } else {
          window.location.href = location.href;
        }
      } else if (response?.error) {
        feedback('mainwp-message-zone-client', response.error, 'red');
      } else {
        feedback('mainwp-message-zone-client', __('Undefined error. Please try again.'), 'red');
      }
    },
    error: function () {
    },
    contentType: false,
    cache: false,
    processData: false,
    enctype: 'multipart/form-data',
    dataType: 'json'
  });

};

// Trigger new client fields modal
jQuery(document).on('click', '#mainwp-clients-new-custom-field-button', function () {
  let parent = jQuery(this).parents('#mainwp-clients-custom-field-modal');
  jQuery(parent).find('input[name="field-name"]').val('');
  jQuery(parent).find('input[name="field-description"]').val('');
  jQuery(parent).find('input[name="field-id"]').val(0);
  jQuery('#mainwp-clients-custom-field-modal').modal({
    closable: false,
  }).modal('show');
});

// Edit client custom fields.
jQuery(document).on('click', '#mainwp-clients-edit-custom-field', function () {
  let parent = jQuery(this).closest('.mainwp-field');
  let field_name = parent.find('.field-name').html();
  let field_desc = parent.find('.field-description').html();
  let field_id = parent.attr('field-id');

  field_name = field_name.replace(/\[|\]/gi, ""); // NOSONAR.

  jQuery('#mainwp-clients-custom-field-modal input[name="field-name"]').val(field_name);
  jQuery('#mainwp-clients-custom-field-modal input[name="field-description"]').val(field_desc);
  jQuery('#mainwp-clients-custom-field-modal input[name="field-id"]').val(field_id);

  jQuery('#mainwp-clients-custom-field-modal').modal({
    closable: false,
  }).modal('show');
  return false;
});

// Save/Update custom fields
jQuery(document).on('click', '#mainwp-clients-save-new-custom-field', function () {
  mainwp_clients_update_custom_field(this);
  return false;
});

let mainwp_clients_update_custom_field = function (me) {
  let parent = jQuery(me).parents('#mainwp-clients-custom-field-modal');
  let errors = [];
  let client_id = jQuery(me).attr('client-id');

  if (parent.find('input[name="field-name"]').val().trim() == '') {
    errors.push('Field name is required.');
  }

  if (parent.find('input[name="field-description"]').val().trim() == '') {
    errors.push('Field description is required.');
  }

  if (errors.length > 0) {
    parent.find('.ui.message').html(errors.join('<br />')).show();
    return false;
  }

  let fields = mainwp_secure_data({
    field_name: parent.find('input[name="field-name"]').val(),
    field_desc: parent.find('input[name="field-description"]').val(),
    field_id: parent.find('input[name="field-id"]').val(),
    client_id: client_id,
    action: 'mainwp_clients_save_field',
  });

  parent.find('.ui.message').html('<i class="notched circle loading icon"></i> Saving field. Please wait...').show().removeClass('yellow');

  jQuery.post(ajaxurl, fields, function (response) {
    if (response) {
      if (response.success) {
        window.location.href = location.href;
      } else if (response.error) {
        parent.find('.ui.message').html(response.error).show().removeClass('yellow').addClass('red');
      } else {
        parent.find('.ui.message').html('Undefined error occurred. Please try again.').show().removeClass('yellow').addClass('red');
      }
    } else {
      parent.find('.ui.message').html('Undefined error occurred. Please try again.').show().removeClass('yellow').addClass('red');
    }
  }, 'json');
}

// Delete Custom field
jQuery(document).on('click', '#mainwp-clients-delete-general-field', function () {
  if (confirm(__('Are you sure you want to delete this field?'))) {

    let parent = jQuery(this).closest('.mainwp-field');
    jQuery.post(ajaxurl, mainwp_secure_data({
      action: 'mainwp_clients_delete_general_field',
      field_id: parent.attr('field-id'),
    }), function (data) {
      mainwp_set_message_zone('#mainwp-message-zone-client');
      if (data?.success) {
        parent.html('<td colspan="3"><i class="green check icon"></i> ' + __('Field has been deleted successfully.') + '</td>').fadeOut(3000);
      } else {
        mainwp_set_message_zone('#mainwp-message-zone-client', __('Field can not be deleted.'), 'red');
      }
    }, 'json');
  }
  return false;
});

// Delete Custom field
jQuery(document).on('click', '#mainwp-clients-delete-individual-field', function () {
  if (confirm(__('Are you sure you want to delete this field?'))) {

    let parent = jQuery(this).closest('.mainwp-field');

    jQuery.post(ajaxurl, mainwp_secure_data({
      action: 'mainwp_clients_delete_field',
      field_id: parent.attr('field-id'),
      client_id: jQuery(this).attr('client-id'),
    }), function (data) {
      if (data?.success) {
        parent.html('<td colspan="3"><i class="green check icon"></i> ' + __('Field has been deleted successfully.') + '</td>').fadeOut(3000);
      } else {
        mainwp_set_message_zone('#mainwp-message-zone-client', __('Field can not be deleted.'), 'red');
      }
    }, 'json');
  }
  return false;
});


jQuery(document).on('click', '.mainwp-edit-client-note', function () {
  let id = jQuery(this).attr('id').substring(13);
  let note = jQuery('#mainwp-notes-' + id + '-note').html();
  jQuery('#mainwp-notes-html').html(note == '' ? __('No saved notes. Click the Edit button to edit site notes.') : note);
  jQuery('#mainwp-notes-note').val(note);
  jQuery('#mainwp-notes-itemid').val(id);
  jQuery('#mainwp-which-note').val('client'); // to fix conflict.
  mainwp_notes_show(true);
  return false;
});


let mainwp_notes_client_save = function () {
  let normalid = jQuery('#mainwp-notes-itemid').val();
  let newnote = jQuery('#mainwp-notes-note').val();
  newnote = newnote.replace(/(?:\r\n|\r|\n)/g, '<br>');
  let data = mainwp_secure_data({
    action: 'mainwp_clients_notes_save',
    clientid: normalid,
    note: newnote
  });

  jQuery('#mainwp-notes-status').html('<i class="notched circle loading icon"></i> ' + __('Saving note. Please wait...')).show();

  jQuery.post(ajaxurl, data, function (response) {
    if (response.error != undefined) {
      jQuery('#mainwp-notes-status').html(response.error).addClass('red');
    } else if (response.result == 'SUCCESS') {
      jQuery('#mainwp-notes-status').html(__('Note saved successfully.')).addClass('green');
      if (jQuery('#mainwp-notes-' + normalid + '-note').length > 0) {
        jQuery('#mainwp-notes-' + normalid + '-note').html(jQuery('#mainwp-notes-note').val());
      }
    } else {
      jQuery('#mainwp-notes-status').html(__('Undefined error occured while saving your note!')).addClass('red');
    }
  }, 'json');

  setTimeout(function () {
    jQuery('#mainwp-notes-status').fadeOut(300);
  }, 3000);

  jQuery('#mainwp-notes-html').show();
  jQuery('#mainwp-notes-editor').hide();
  jQuery('#mainwp-notes-save').hide();
  jQuery('#mainwp-notes-edit').show();

};

// Handle import client for csv.
// eslint-disable-next-line complexity
const mainwp_manageclient_import_client = function () { // NOSONAR
  if (import_client_stop_by_user)
    return;

  jQuery('#mainwp-importing-clients').hide();
  import_client_current++;
  if (import_client_current > import_client_total) {
    jQuery('#mainwp-import-clients-status-message').hide();
    jQuery('#mainwp_manageclients_btn_import').attr('disabled', 'true'); //Disable
    if (import_client_count_success < import_client_total) {
      jQuery('#mainwp_manageclients_btn_save_csv').prop("disabled", false); //Enable
    }

    if (import_client_count_fails == 0) {
      jQuery('#mainwp_manageclients_import_logging .log').html('<div style="text-align:center;margin:50px 0;"><h2 class="ui icon header"><i class="green check icon"></i><div class="content">Congratulations!<div class="sub header">' + import_client_count_success + ' clients imported successfully.</div></div></h2></div>');
      jQuery('#mainwp_manageclients_btn_import').hide();
      setTimeout(function () {
        location.reload();
      }, 2000);
    } else {
      jQuery('#mainwp_manageclients_import_logging .log').append('<div class="ui yellow message">Process completed with errors. ' + import_client_count_fails + ' client(s) failed to import. Please review logs to resolve problems and try again.</div>');
      jQuery('#mainwp_manageclients_btn_import').hide();
      jQuery('#mainwp-import-clients-modal-try-again').show();
      jQuery('#mainwp-import-clients-modal-continue').show();
    }

    jQuery('#mainwp_manageclients_import_logging').scrollTop(jQuery('#mainwp_manageclients_import_logging .log').height());
    return;
  }

  let import_client_data = jQuery('#mainwp_manageclients_import_csv_line_' + import_client_current).attr('encoded-data');
  let import_client_line_orig = jQuery('#mainwp_manageclients_import_csv_line_' + import_client_current).attr('original');
  let decoded_client_val = JSON.parse(import_client_data);

  let import_city = decoded_client_val['client.city'];
  let import_address_1 = decoded_client_val['client.contact.address.1'];
  let import_address_2 = decoded_client_val['client.contact.address.2'];
  let import_country = decoded_client_val['client.country'];
  let import_email = decoded_client_val['client.email'];
  let import_name = decoded_client_val['client.name'];
  let import_state = decoded_client_val['client.state'];
  let import_suspended = decoded_client_val['client.suspended'];
  let import_zip = decoded_client_val['client.zip'];
  let import_url = decoded_client_val['client.url'];

  if (typeof (import_city) == "undefined")
    import_city = '';
  if (typeof (import_address_1) == "undefined")
    import_address_1 = '';
  if (typeof (import_address_2) == "undefined")
    import_address_2 = '';
  if (typeof (import_country) == "undefined")
    import_country = '';
  if (typeof (import_email) == "undefined")
    import_email = '';
  if (typeof (import_name) == "undefined") {
    import_name = '';
  }
  if (typeof (import_state) == "undefined") {
    import_state = '';
  }
  if (typeof (import_suspended) == "undefined") {
    import_suspended = '';
  }
  if (typeof (import_zip) == "undefined") {
    import_zip = '';
  }
  if (typeof (import_url) == "undefined") {
    import_url = [];
  }

  jQuery('#mainwp_manageclients_import_logging .log').append('<strong>[' + import_client_current + '] << ' + import_client_line_orig + '</strong><br/>');

  let errors = [];

  if (import_name == '') {
    errors.push(__('Please enter the client name.'));
  }

  if (import_url == '') {
    errors.push(__('Please enter the site URL.'));
  }

  if (import_email == '') {
    errors.push(__('Please enter email of the client.'));
  }

  if (errors.length > 0) {
    jQuery('#mainwp_manageclients_import_logging .log').append('[' + import_client_current + '] >> Error - ' + errors.join(" ") + '<br/>');
    jQuery('#mainwp_manageclients_import_fail_logging').append('<span>' + import_client_line_orig + '</span>');
    import_client_count_fails++;
    mainwp_manageclient_import_client();
    return;
  }

  // Checking client exist yet
  let data_check_client = mainwp_secure_data({
    action: 'mainwp_clients_check_client',
    email: import_email,
  });
  jQuery.post(ajaxurl, data_check_client, function (response) {
    if (response.success) {
      // Create new client.
      let data = mainwp_secure_data({
        action: 'mainwp_clients_import_client',
        name: import_name,
        email: import_email,
        urls: import_url,
        city: import_city,
        address_1: import_address_1,
        address_2: import_address_2,
        country: import_country,
        state: import_state,
        suspended: import_suspended,
        zip: import_zip,
      });
      jQuery.post(ajaxurl, data, function (res_client) {
        let add_result = 'Create a new [' + import_name + '] >> ';
        if (res_client.success) {
          import_client_count_success++;
        } else {
          jQuery('#mainwp_manageclients_import_fail_logging').append('<span>' + import_client_line_orig + '</span>');
          import_client_count_fails++;
        }
        jQuery('#mainwp_manageclients_import_logging .log').append(add_result + res_client.data.message + "<br/>");
        mainwp_manageclient_import_client();
      });
    } else {
      let check_result = response?.data?.error;
      errors.push(check_result);
    }

    if (errors.length > 0) {
      jQuery('#mainwp_manageclients_import_fail_logging').append('<span>' + import_client_line_orig + '</span>');
      jQuery('#mainwp_manageclients_import_logging .log').append(errors.join("\n") + '<br/>');
      import_client_count_fails++;
      mainwp_manageclient_import_client();
    }
    jQuery('#mainwp_manageclients_import_logging').scrollTop(jQuery('#mainwp_manageclients_import_logging .log').height());
  }, 'json');
}