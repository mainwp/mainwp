jQuery(function () {

  // Init the groups menu
  jQuery('#mainwp-groups-menu').find('a.item').on('click', function () {
    jQuery(this).addClass('active green');
    jQuery(this).siblings().removeClass('active green');
    jQuery(this).find('.label').addClass('green');
    jQuery(this).siblings().find('.label').removeClass('green');
    jQuery('#mainwp-delete-group-button').removeClass('disabled');
    jQuery('#mainwp-rename-group-button').removeClass('disabled');
    jQuery('#mainwp-save-sites-groups-selection-button').removeClass('disabled');
    show_group_items(this);
  });

  // Trigger the create a new group modal
  jQuery(document).on('click', '#mainwp-new-sites-group-button', function () {
    jQuery('#mainwp-create-group-modal').modal({
      onHide: function () {
        window.location.href = location.href;
      },
      onShow: function () {
        jQuery('#mainwp-create-group-modal').find('input#mainwp-group-name').val('');
        jQuery('#mainwp-create-group-modal').find('input#mainwp-new-tag-color').val('');
      }
    }).modal('show');
  });

  // Trigger the edit group modal
  jQuery(document).on('click', '#mainwp-rename-group-button', function () {
    jQuery('#mainwp-rename-group-modal').modal({
      onHide: function () {
        window.location.href = location.href;
      },
      onShow: function () {
        let groupName = jQuery('#mainwp-groups-menu').find('.active').find('#mainwp-hidden-group-name').val();
        let groupColor = jQuery('#mainwp-groups-menu').find('.active').find('#mainwp-hidden-group-color').val();
        jQuery('#mainwp-rename-group-modal').find('input#mainwp-group-name').val(groupName);
        jQuery('#mainwp-rename-group-modal').find('input#mainwp-new-tag-color').val(groupColor);
      }
    }).modal('show');
  });

  // Create a new group
  jQuery(document).on('click', '#mainwp-save-new-group-button', function () {
    let newName = jQuery('#mainwp-create-group-modal').find('input#mainwp-group-name').val();
    let newColor = jQuery('#mainwp-create-group-modal').find('input#mainwp-new-tag-color').val();

    let data = mainwp_secure_data({
      action: 'mainwp_group_add',
      newName: newName,
      newColor: newColor
    });
    jQuery.post(ajaxurl, data, function (response) {
      try {
        let resp = JSON.parse(response);

        if (resp.error != undefined)
          return;
      } catch (err) {
        // to fix js error.
      }
      jQuery('#mainwp-create-group-modal').modal({
        onHide: function () {
          window.location.reload();
        }
      }).modal('hide');
    });
    return false;
  });

  // Delete a group
  jQuery(document).on('click', '#mainwp-delete-group-button', function () {
    let gruopItem = jQuery('#mainwp-groups-menu').find('.active');

    let _deltag_callback = function () {
      let groupID = gruopItem.attr('id');
      let data = mainwp_secure_data({
        action: 'mainwp_group_delete',
        groupId: groupID
      });
      jQuery.post(ajaxurl, data, function (response) {
        response = response.trim();
        if (response == 'OK') {
          gruopItem.fadeOut(300);
        }
      });
    };

    mainwp_confirm('Are you sure you want to delete this tag?', _deltag_callback);
    return false;
  });

  // Update group name
  jQuery(document).on('click', '#mainwp-update-new-group-button', function () {
    let groupID = jQuery('#mainwp-groups-menu').find('.active').attr('id');
    let newName = jQuery('#mainwp-rename-group-modal').find('input#mainwp-group-name').val();
    let newColor = jQuery('#mainwp-rename-group-modal').find('input#mainwp-new-tag-color').val();
    let data = mainwp_secure_data({
      action: 'mainwp_group_rename',
      groupId: groupID,
      newName: newName,
      newColor: newColor
    });

    jQuery.post(ajaxurl, data, function (response) {
      if (response.error) {
        return;
      }
      jQuery('#mainwp-create-group-modal').modal({
        onHide: function () {
          window.location.reload();
          return false;
        }
      }).modal('hide');
    }, 'json');

    return false;
  });

  // Select all sites
  jQuery('#mainwp-manage-groups-sites-table th input[type="checkbox"]').on('change', function () {
    let checkboxes = jQuery('#mainwp-manage-groups-sites-table').find(':checkbox');
    if (jQuery(this).prop('checked')) {
      checkboxes.prop('checked', true);
      checkboxes.parents('tr').addClass('selected');
    } else {
      checkboxes.prop('checked', false);
      checkboxes.parents('tr').removeClass('selected');
    }
  });

  // Set class 'active' to selected sites table row
  jQuery('.mainwp-site-checkbox').on('change', function () {
    if (jQuery(this).prop('checked')) {
      jQuery(this).parents('tr').addClass('selected');
    } else {
      jQuery(this).parents('tr').removeClass('selected');
    }
  });

  // Save selected sites for a group
  jQuery(document).on('click', '#mainwp-save-sites-groups-selection-button', function (e) {

    e.preventDefault();

    let groupID = jQuery('#mainwp-groups-menu').find('.active').attr('id');
    let sites = jQuery('#mainwp-manage-groups-sites-table').find('input.mainwp-site-checkbox:checked');
    let sitesIDs = [];

    for (let id of sites) {
      sitesIDs.push(jQuery(id).val());
    }

    if (groupID == undefined) {
      return;
    }

    let data = mainwp_secure_data({
      action: 'mainwp_group_updategroup',
      groupId: groupID,
      websiteIds: sitesIDs
    });

    jQuery(this).addClass('disabled');

    jQuery.post(ajaxurl, data, function () {
      jQuery('#mainwp-save-sites-groups-selection-button').removeClass('disabled');
    }, 'json');
  });

  // Load group sites
  let show_group_items = function (group) {
    let groupID = jQuery(group).attr('id');
    let data = mainwp_secure_data({
      action: 'mainwp_group_getsites',
      groupId: groupID
    });
    jQuery('.dimmer').addClass('active');
    jQuery.post(ajaxurl, data, function (response) {
      jQuery('.dimmer').removeClass('active');
      response = response.trim();
      if (response == 'ERROR') {
        return;
      }
      let dtApi = jQuery('#mainwp-manage-groups-sites-table').dataTable().api();
      mainwp_datatable_fix_to_update_selected_rows_status(dtApi, 'deselected'); // clear saved state.
      jQuery('input.mainwp-site-checkbox').prop('checked', false);
      jQuery('input.mainwp-site-checkbox').closest('tr').removeClass('selected');
      let sites = JSON.parse(response);
      for (let id of sites) {
        jQuery('input[value="' + id + '"].mainwp-site-checkbox').prop('checked', true);
        jQuery('input[value="' + id + '"].mainwp-site-checkbox').closest('tr').addClass('selected');
      }
      mainwp_datatable_fix_to_update_selected_rows_status(dtApi, 'selected'); // clear saved state.
    });
    return false;
  }

});
