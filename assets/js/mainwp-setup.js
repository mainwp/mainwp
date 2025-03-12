jQuery(function () {
  jQuery('.mainwp-field-tab-connect input[name=tab_connect]').change(function () {
    const tab_active = this.value;
    if (tab_active !== '') {
      jQuery('#mainwp-qsw-connect-site-form').fadeIn(500);
      jQuery('#mainwp_managesites_add').show();
      jQuery('#mainwp-qsw-toggle-verify-mainwp-child-active').show();
      jQuery('#mainwp_addsite_continue_button').hide();
      jQuery('.menu-connect-first-site .item').tab('change tab', tab_active);
    }
  });

  jQuery('#mainwp-qsw-verify-mainwp-child-active').on('change', function () {
    if (jQuery(this).is(':checked')) {
      jQuery('#mainwp_managesites_add').attr("disabled", false);
      jQuery('#mainwp_managesites_add_import').attr("disabled", false);
    } else {
      jQuery('#mainwp_managesites_add').attr("disabled", true);
      jQuery('#mainwp_managesites_add_import').attr("disabled", true);
    }
  });

  // Handle submit import file CVS.
  jQuery(document).on('click', '#mainwp_managesites_add_import', function () {
    let error_messages = mainwp_managesites_import_handle_form_before_submit();
    // If there is an error, prevent submission and display the error
    if (error_messages.length > 0) {
      feedback('mainwp-message-zone', error_messages.join("<br/>"), "red");
    } else {
      jQuery('#mainwp_connect_first_site_form').trigger('submit');
    }

    return false;
  });

  jQuery('#mainwp_qsw_client_name_field').on('keyup', function () {
    if (jQuery(this).val()) {
      jQuery('#bulk_add_createclient').show();
      jQuery('#mainwp_qsw_add_client_continue_button').hide();
    } else {
      jQuery('#bulk_add_createclient').hide();
      jQuery('#mainwp_qsw_add_client_continue_button').show();
    }
  });

  jQuery('#mainwp-toggle-optional-settings').on('click', function () {
    jQuery('#mainwp-qsw-optional-settings-form').toggle(300);
    return false;
  });

  jQuery('.ui.checkbox:not(.not-auto-init)').checkbox();
  jQuery('.ui.dropdown:not(.not-auto-init)').dropdown();

  jQuery('.mainwp-checkbox-showhide-elements').on('click', function () {
    let hiel = jQuery(this).attr('hide-parent');
    // if semantic ui checkbox is checked.
    if (jQuery(this).find('input').is(':checked')) {
      jQuery('[hide-element=' + hiel + ']').fadeIn(500);
    } else {
      jQuery('[hide-element=' + hiel + ']').fadeOut(500);
    }
  });

  jQuery(document).on('click', '#mainwp_managesites_add', function () {
    mainwp_setup_managesites_add();
  });

  jQuery(document).on('change', '#mainwp_managesites_add_wpurl', function () {
    let url = jQuery('#mainwp_managesites_add_wpurl').val().trim();
    let protocol = jQuery('#mainwp_managesites_add_wpurl_protocol').val();

    if (url.lastIndexOf('http://') === 0) {
      protocol = 'http';
      url = url.substring(7);
    } else if (url.lastIndexOf('https://') === 0) {
      protocol = 'https';
      url = url.substring(8);
    }

    if (jQuery('#mainwp_managesites_add_wpname').val() == '') {
      jQuery('#mainwp_managesites_add_wpname').val(url);
    }

    jQuery('#mainwp_managesites_add_wpurl').val(url);
    jQuery('#mainwp_managesites_add_wpurl_protocol').val(protocol).trigger("change");
  });

});

// Handle tab onvisible.
const mainwp_menu_connect_first_site_onvisible_callback = function (objItem) {
  const tab = jQuery(objItem).attr("data-tab");
  jQuery('.mainwp-field-tab-connect input[name=tab_connect]').filter(`[value="${tab}"]`).parent().trigger('click'); // set checked class ui checkbox
  if (tab === 'multiple-site') {
    jQuery('#mainwp_managesites_add_import').show();
    jQuery('#mainwp_managesites_add').hide();
  } else if (tab === 'single-site') {
    jQuery('#mainwp_managesites_add').show();
    jQuery('#mainwp_managesites_add_import').hide();
  }
}
// Connect a new website
let mainwp_setup_managesites_add = function () {
  mainwp_set_message_zone('#mainwp-message-zone');
  let errors = [];

  if (jQuery('#mainwp_managesites_add_wpname').val().trim() == '') {
    errors.push('Please enter a title for the website.');
  }

  if (jQuery('#mainwp_managesites_add_wpurl').val().trim() == '') {
    errors.push('Please enter a valid URL for the site.');
  } else {
    let url = jQuery('#mainwp_managesites_add_wpurl').val().trim();
    if (!url.endsWith('/')) {
      url += '/';
    }

    jQuery('#mainwp_managesites_add_wpurl').val(url);

    if (!isUrl(jQuery('#mainwp_managesites_add_wpurl_protocol').val() + '://' + jQuery('#mainwp_managesites_add_wpurl').val())) {
      errors.push('Please enter a valid URL for the site.');
    }
  }

  if (jQuery('#mainwp_managesites_add_wpadmin').val().trim() == '') {
    errors.push('Please enter a username of the website administrator.');
  }

  if (errors.length > 0) {
    mainwp_set_message_zone('#mainwp-message-zone', errors.join('<br />'), 'yellow');
  } else {
    mainwp_set_message_zone('#mainwp-message-zone', 'Adding the site to your MainWP Dashboard. Please wait...', '');
    jQuery('#mainwp_managesites_add').attr('disabled', 'true'); //disable button to add..

    let url = jQuery('#mainwp_managesites_add_wpurl_protocol').val() + '://' + jQuery('#mainwp_managesites_add_wpurl').val().trim();

    if (!url.endsWith('/')) {
      url += '/';
    }

    let name = jQuery('#mainwp_managesites_add_wpname').val().trim();
    name = name.replace(/"/g, '&quot;');

    let data = mainwp_setup_secure_data({
      action: 'mainwp_checkwp',
      name: name,
      url: url,
      admin: jQuery('#mainwp_managesites_add_wpadmin').val().trim(),
    });

    jQuery.post(ajaxurl, data, function (res_things) {
      let response = res_things.response;
      response = response.trim();

      let url = jQuery('#mainwp_managesites_add_wpurl_protocol').val() + '://' + jQuery('#mainwp_managesites_add_wpurl').val().trim();
      if (!url.endsWith('/')) {
        url += '/';
      }

      url = url.replace(/"/g, '&quot;');

      if (response == 'HTTPERROR') {
        errors.push('This site can not be reached! Please use the Test Connection feature and see if the positive response will be returned. For additional help, please review <a href="https://mainwp.com/kb/">MainWP Knowledgebase</a>, and if you still have issues, please let us know in the <a href="https://community.mainwp.com/c/community-support/5">MainWP Community</a>.'); // NOSONAR - noopener - open safe.
      } else if (response == 'NOMAINWP') {
        errors.push(mainwp_js_get_error_not_detected_connect());
      } else if (response.substring(0, 5) == 'ERROR') {
        if (response.length == 5) {
          errors.push('Undefined error occurred. Please try again. If the issue does not resolve, please review <a href="https://mainwp.com/kb/">MainWP Knowledgebase</a>, and if you still have issues, please let us know in the <a href="https://community.mainwp.com/c/community-support/5">MainWP Community</a>.'); // NOSONAR - noopener - open safe.
        } else {
          let error = response.substring(6);
          let err = mainwp_js_get_error_not_detected_connect(error, 'html_msg', false, true); // return text error.
          if (false === err) {
            errors.push(error); // it is not json string error.
          } else if (true !== err && '' != err) {
            errors.push(err); // decoded error.
          }
        }
      } else if (response == 'OK') {
        jQuery('#mainwp_managesites_add').attr('disabled', 'true');

        let name = jQuery('#mainwp_managesites_add_wpname').val();
        name = name.replace(/"/g, '&quot;');
        let group_ids = '';
        let data = mainwp_setup_secure_data({
          action: 'mainwp_addwp',
          managesites_add_wpname: name,
          managesites_add_wpurl: url,
          managesites_add_wpadmin: jQuery('#mainwp_managesites_add_wpadmin').val(),
          managesites_add_adminpwd: encodeURIComponent(jQuery('#mainwp_managesites_add_admin_pwd').val()),
          managesites_add_uniqueId: jQuery('#mainwp_managesites_add_uniqueId').val(),
          groupids: group_ids,
          qsw_page: true,
        });

        // to support add client reports tokens values
        jQuery("input[name^='creport_token_']").each(function () {
          let tname = jQuery(this).attr('name');
          let tvalue = jQuery(this).val();
          data[tname] = tvalue;
        });

        // support hooks fields
        jQuery(".mainwp_addition_fields_addsite input").each(function () {
          let tname = jQuery(this).attr('name');
          let tvalue = jQuery(this).val();
          data[tname] = tvalue;
        });

        jQuery.post(ajaxurl, data, function (res_things) {

          if (res_things.error) {
            response = res_things.error;
          } else {
            response = res_things.response;
          }

          response = response.trim();

          mainwp_set_message_zone('#mainwp-message-zone');
          jQuery('#mainwp-info-zone').hide();

          if (response.substring(0, 5) == 'ERROR') {
            mainwp_set_message_zone('#mainwp-message-zone', response.substring(6), 'red');
          } else {
            //Message the WP was added
            mainwp_set_message_zone('#mainwp-message-zone', response, 'green');

            //Reset fields
            jQuery('#mainwp_managesites_add_wpname').val('');
            jQuery('#mainwp_managesites_add_wpurl').val('');
            jQuery('#mainwp_managesites_add_wpurl_protocol').val('https');
            jQuery('#mainwp_managesites_add_wpadmin').val('');
            jQuery('#mainwp_managesites_add_admin_pwd').val('');
            jQuery('#mainwp_managesites_add_uniqueId').val('');

            jQuery("input[name^='creport_token_']").each(function () {
              jQuery(this).val('');
            });

            // support hooks fields
            jQuery(".mainwp_addition_fields_addsite input").each(function () {
              jQuery(this).val('');
            });

            setTimeout(function () {
              window.location.href = 'admin.php?page=mainwp-setup&step=add_client';
            }, 3000);
          }

          jQuery('#mainwp_managesites_add').prop("disabled", false);
        }, 'json');
      }
      if (errors.length > 0) {
        mainwp_set_message_zone('#mainwp-message-zone', errors.join('<br />'), 'red');
        jQuery('#mainwp_managesites_add').prop("disabled", false);
      }
    }, 'json');
  }
};

// Check if the URL field is valid value
function isUrl(url) {
  try {
    new URL(url);
    return true;
  } catch (e) {
    return false;
  }
}

let mainwp_setup_secure_data = function (data) {
  if (data['action'] == undefined)
    return data;

  data['security'] = jQuery('#nonce_secure_data').attr(data['action']);

  return data;
};
