/**
 * MainWP Clients.page
 */


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
    }
  });

  jQuery('.mainwp-edit-clients-select-contact-icon').dropdown({
    onChange: function (val) {
      let parent = jQuery(this).closest('.mainwp_edit_clients_contact_icon_wrapper');
      let inname = parent.attr('input-name');
      if (undefined !== inname) {
        jQuery(parent).find('#client_fields\\[' + inname + '\\]\\[selected_icon\\]\\[\\]').val(val);
      }
    }
  });

  jQuery(document).on('click', '.mainwp-client-add-contact', function () {
    let templ = jQuery(this).attr('add-contact-temp');
    jQuery('.after-add-contact-field').after(templ);
    let justAdded = jQuery('.after-add-contact-field').next().next().next().next().next();
    jQuery(justAdded).find('.mainwp-edit-clients-select-contact-icon').dropdown({
      onChange: function (val) {
        let parent = jQuery(this).closest('.mainwp_edit_clients_contact_icon_wrapper');
        let inname = parent.attr('input-name');
        if (undefined !== inname) {
          jQuery(parent).find('#client_fields\\[' + inname + '\\]\\[selected_icon\\]\\[\\]').val(val);
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

