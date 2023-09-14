/**
 * MainWP Clients.page
 */


jQuery(document).ready(function () {

  // Delete single client.
  jQuery(document).on('click', '.client_deleteitem', function () {
    var confirmation = confirm('Are you sure you want to proceed?');
    if (confirmation == true) {
      var parent = jQuery(this).closest('div.menu');
      var rowElement = jQuery(this).parents('tr');
      var clientid = parent.attr('clientid');
      var data = mainwp_secure_data({
        action: 'mainwp_clients_delete_client',
        clientid: clientid,
      });
      jQuery.post(ajaxurl, data, function (response) {
        if (response.success) {
          rowElement.html('<td colspan="8"><i class="check circle icon"></i> ' + response.result + '</td>');
        }
      }, 'json');
    }

    return false;
  });

  jQuery(document).on('click', '.client-suspend-unsuspend-sites', function () {
    var new_status = jQuery(this).attr('suspend-status') == '0' ? 1 : 0;
    var clientid = jQuery(this).closest('.mainwp-widget-footer').attr('client-id');
    var bt = this;

    var data = mainwp_secure_data({
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

  jQuery(document).on('click', '.mainwp-client-add-contact', function () {
    var templ = jQuery(this).attr('add-contact-temp');
    jQuery('.after-add-contact-field').after(templ);
  });

  jQuery(document).on('click', '.mainwp-client-remove-contact', function () {

    if (jQuery(this).attr('contact-id') > 0) {
      jQuery('.after-add-contact-field').before('<input type="hidden" value="' + jQuery(this).attr('contact-id') + '" name="client_fields[delele_contacts][]">'); // to delete contact when submit the client.
    }

    var parent = jQuery(this).closest('.remove-contact-field-parent');
    var limit = 0;
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


bulkManageClientsMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
bulkManageClientsCurrentThreads = 0;
bulkManageClientsTotal = 0;
bulkManageClientsFinished = 0;
bulkManageClientsTaskRunning = false;


// Trigger Manage Bulk Actions
jQuery(document).on('click', '#mainwp-do-clients-bulk-actions', function () {
  var action = jQuery("#mainwp-clients-bulk-actions-menu").dropdown("get value");
  if (action == '')
    return false;
  mainwp_manageclients_doaction(action);
  return false;
});


mainwp_manageclients_doaction = function (action) {

  if (action == 'delete') {

    if (bulkManageClientsTaskRunning)
      return false;
    var confirmMsg = '';

    switch (action) {
      case 'delete':
        confirmMsg = __("You are about to remove the selected clients from your MainWP Dashboard?");
        break;
    }

    mainwp_confirm(confirmMsg, _callback = function () { mainwp_manageclients_doaction_process(action); });
    return false; // return those case
  }
  return false;
}



mainwp_manageclients_doaction_process = function (action) {

  manageclients_bulk_init();

  bulkManageClientsTotal = jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]').length;
  bulkManageClientsTaskRunning = true;

  if (action == 'delete') {
    mainwp_manageclients_bulk_remove_next();
    return false;
  }
}



mainwp_manageclients_bulk_remove_next = function () {
  while ((checkedBox = jQuery('#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]:first')) && (checkedBox.length > 0) && (bulkManageClientsCurrentThreads < bulkManageClientsMaxThreads)) {
    mainwp_manageclients_bulk_remove_specific(checkedBox);
  }
  if ((bulkManageClientsTotal > 0) && (bulkManageClientsFinished == bulkManageClientsTotal)) {
    managesites_bulk_done();
    setHtml('#mainwp-message-zone-client', __("Process completed. Reloading page..."));
    setTimeout(function () {
      window.location.reload()
    }, 3000);
  }
}

mainwp_manageclients_bulk_remove_specific = function (pCheckedBox) {

  pCheckedBox.attr('status', 'running');
  var rowObj = pCheckedBox.closest('tr');
  bulkManageClientsCurrentThreads++;

  var id = rowObj.attr('clientid');

  rowObj.html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Removing ...' + '</td>');

  var data = mainwp_secure_data({
    action: 'mainwp_clients_delete_client',
    clientid: id
  });
  jQuery.post(ajaxurl, data, function (response) {
    bulkManageClientsCurrentThreads--;
    bulkManageClientsFinished++;
    rowObj.html('<td colspan="999"></td>');
    var result = '';
    var error = '';
    if (response.error != undefined) {
      error = response.error;
    } else if (response.success == 'SUCCESS') {
      result = __('The client has been removed.');
    }

    if (error != '') {
      rowObj.html('<td colspan="999"><i class="red times icon"></i>' + error + '</td>');
    } else {
      rowObj.html('<td colspan="999"><i class="green check icon"></i>' + result + '</td>');
    }
    setTimeout(function () {
      jQuery('tr[clientid=' + id + ']').fadeOut(1000);
    }, 3000);

    mainwp_manageclients_bulk_remove_next();
  }, 'json');
};

manageclients_bulk_init = function () {
  jQuery('#mainwp-message-zone-client').hide();
  if (bulkManageClientsTaskRunning == false) {
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
  var currPage = jQuery(this).attr('current-page');
  mainwp_createclient(currPage);
});

mainwp_createclient = function (currPage) {
  if (jQuery('input[name="client_fields[default_field][client.name]"]').val() == '') {
    feedback('mainwp-message-zone-client', __('Client name field is required! Please enter a Client name.'), 'yellow');
    return;
  }

  var valid_contact = true;
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

  var selected_sites = [];
  jQuery("input[name='selected_sites[]']:checked").each(function () {
    selected_sites.push(jQuery(this).val());
  });

  if (jQuery('#select_by').val() == 'site') {
    var selected_sites = [];
    jQuery("input[name='selected_sites[]']:checked").each(function () {
      selected_sites.push(jQuery(this).val());
    });
  }

  var is_first_client = false;
  if (jQuery("input[name=selected_first_site]").length > 0) {
    selected_sites.push(jQuery("input[name=selected_first_site]").val());
    is_first_client = true;
  }

  jQuery('#mainwp-message-zone-client').removeClass('red green yellow');
  var msg = __('Creating the client. Please wait...');
  if (jQuery('input[name="client_fields[client_id]"]').val() != 0) {
    var msg = __('Updating the client. Please wait...');
  }
  jQuery('#mainwp-message-zone-client').html('<i class="notched circle loading icon"></i> ' + msg);
  jQuery('#mainwp-message-zone-client').show();
  jQuery('#bulk_add_createclient').attr('disabled', 'disabled');

  //Add user via ajax!!
  var formdata = new FormData(jQuery('#createclient_form')[0]);
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
      jQuery('#mainwp-message-zone-client').hide();
      jQuery('#bulk_add_createclient').prop("disabled", false);
      if (response && response.success) {
        if ('add-new' == currPage) {
          window.location.href = "admin.php?page=ManageClients";
        } else if ('qsw-add' == currPage) {
          window.location.href = 'admin.php?page=mainwp-setup&step=monitoring&message=1';
        } else {
          window.location.href = location.href;
        }
      } else if (response && response.error) {
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
  var parent = jQuery(this).parents('#mainwp-clients-custom-field-modal');
  jQuery(parent).find('input[name="field-name"]').val('');
  jQuery(parent).find('input[name="field-description"]').val('');
  jQuery(parent).find('input[name="field-id"]').val(0);
  jQuery('#mainwp-clients-custom-field-modal').modal({
    closable: false,
  }).modal('show');
});

// Edit client custom fields.
jQuery(document).on('click', '#mainwp-clients-edit-custom-field', function () {
  var parent = jQuery(this).closest('.mainwp-field');
  var field_name = parent.find('.field-name').html();
  var field_desc = parent.find('.field-description').html();
  var field_id = parent.attr('field-id');

  field_name = field_name.replace(/\[|\]/gi, "");

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

mainwp_clients_update_custom_field = function (me) {
  var parent = jQuery(me).parents('#mainwp-clients-custom-field-modal');
  var errors = [];
  var client_id = jQuery(me).attr('client-id');

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

  var fields = mainwp_secure_data({
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
      } else {
        if (response.error) {
          parent.find('.ui.message').html(response.error).show().removeClass('yellow').addClass('red');
        } else {
          parent.find('.ui.message').html('Undefined error occurred. Please try again.').show().removeClass('yellow').addClass('red');
        }
      }
    } else {
      parent.find('.ui.message').html('Undefined error occurred. Please try again.').show().removeClass('yellow').addClass('red');
    }
  }, 'json');
}

// Delete Custom field
jQuery(document).on('click', '#mainwp-clients-delete-general-field', function () {
  if (confirm(__('Are you sure you want to delete this field?'))) {

    var parent = jQuery(this).closest('.mainwp-field');

    jQuery.post(ajaxurl, mainwp_secure_data({
      action: 'mainwp_clients_delete_general_field',
      field_id: parent.attr('field-id'),
    }), function (data) {
      if (data && data.success) {
        parent.html('<td colspan="3">' + __('Field has been deleted successfully.') + '</td>').fadeOut(3000);
      } else {
        jQuery('#mainwp-message-zone-client').html(__('Field can not be deleted.')).addClass('red').show();
      }
    }, 'json');
    return false;
  }
  return false;
});

// Delete Custom field
jQuery(document).on('click', '#mainwp-clients-delete-individual-field', function () {
  if (confirm(__('Are you sure you want to delete this field?'))) {

    var parent = jQuery(this).closest('.mainwp-field');

    jQuery.post(ajaxurl, mainwp_secure_data({
      action: 'mainwp_clients_delete_field',
      field_id: parent.attr('field-id'),
      client_id: jQuery(this).attr('client-id'),
    }), function (data) {
      if (data && data.success) {
        parent.html('<td colspan="3">' + __('Field has been deleted successfully.') + '</td>').fadeOut(3000);
      } else {
        jQuery('#mainwp-message-zone-client').html(__('Field can not be deleted.')).addClass('red').show();
      }
    }, 'json');
    return false;
  }
  return false;
});


jQuery(document).on('click', '.mainwp-edit-client-note', function () {
  var id = jQuery(this).attr('id').substr(13);
  var note = jQuery('#mainwp-notes-' + id + '-note').html();
  jQuery('#mainwp-notes-html').html(note == '' ? __('No saved notes. Click the Edit button to edit site notes.') : note);
  jQuery('#mainwp-notes-note').val(note);
  jQuery('#mainwp-notes-itemid').val(id);
  jQuery('#mainwp-which-note').val('client'); // to fix conflict.
  mainwp_notes_show(true);
  return false;
});


mainwp_notes_client_save = function () {
  var normalid = jQuery('#mainwp-notes-itemid').val();
  var newnote = jQuery('#mainwp-notes-note').val();
  newnote = newnote.replace(/(?:\r\n|\r|\n)/g, '<br>');
  var data = mainwp_secure_data({
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

