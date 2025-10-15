jQuery(function () {

  // Auto-select newly created group from URL parameter
  let urlParams = new URLSearchParams(window.location.search);
  let newGroupId = urlParams.get('new-tag');

  jQuery(document).ready(function(){
    if (newGroupId) {
    // Find the menu item with the new group ID
    let newGroupItem = jQuery('#mainwp-groups-menu').find('a.item#' + newGroupId);
    
    if (newGroupItem.length > 0) {
      // Trigger click on the new group item to select it
      newGroupItem.trigger('click');
      
      // Show success message
      feedback('mainwp-message-zone', 'Tag created successfully.', 'ui green message');
      
      // Auto-hide message after 5 seconds
      setTimeout(() => {
        jQuery('#mainwp-message-zone').fadeOut();
      }, 5000);
      
      // Remove the URL parameter without reloading the page
      let cleanUrl = window.location.pathname + window.location.search.replace(/[?&]new-tag=[^&]+/, '').replace(/^&/, '?');
      if (cleanUrl.endsWith('?')) {
        cleanUrl = cleanUrl.slice(0, -1);
      }
      window.history.replaceState({}, document.title, cleanUrl);
    }
  }
  })

  // Init the groups menu
  jQuery('#mainwp-groups-menu').find('a.item').on('click', function () {
    if (!newGroupId) {
      jQuery(this).addClass('active green');
      jQuery(this).siblings().removeClass('active green');
      jQuery(this).find('.label').addClass('green');
      jQuery(this).siblings().find('.label').removeClass('green');
      jQuery('#mainwp-delete-group-button').removeClass('disabled');
      jQuery('#mainwp-rename-group-button').removeClass('disabled');
      jQuery('#mainwp-save-sites-groups-selection-button').removeClass('disabled');
      jQuery('#mainwp-message-zone').fadeOut();
    }
    show_group_items(this);
    apply_tag_fade_effect(this);
  });


  // Trigger the create a new group modal
  jQuery(document).on('click', '#mainwp-new-sites-group-button', function () {
    jQuery('#mainwp-create-group-modal').modal({
      onHide: function () {
        mainwp_forceReload();
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
        mainwp_forceReload();
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
    let selected_sites = [];
    jQuery( "input[name='sites']:checked" ).each( function () {
        selected_sites.push( jQuery( this ).val() );
    } );

    let data = mainwp_secure_data({
      //action: 'mainwp_group_add',
      action: 'mainwp_group_sites_add',
      selected_sites: selected_sites,
      newName: newName,
      newColor: newColor
    });
    jQuery.post(ajaxurl, data, function (response) {
      try {
        let resp = JSON.parse(response);

        if (resp.error != undefined)
          return;

        window.location.href = 'admin.php?page=ManageGroups&new-tag=' + resp.success;
  
      } catch (err) {
        // to fix js error.
      }
      //jQuery('#mainwp-create-group-modal').modal('hide');
    });
    return false;
  });

  // Delete a Tag
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
          mainwp_forceReload();
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
          mainwp_forceReload();
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
      checkboxes.parents('tr').addClass('selected active');
    } else {
      checkboxes.prop('checked', false);
      checkboxes.parents('tr').removeClass('selected active');
    }
  });

  // Set class 'active' to selected sites table row
  jQuery('.mainwp-site-checkbox').on('change', function () {
    if (jQuery(this).prop('checked')) {
      jQuery(this).parents('tr').addClass('selected active');
    } else {
      jQuery(this).parents('tr').removeClass('selected active');
    }
  });

// Keep update selected tag site ids.
jQuery(document).on('change', '#mainwp-manage-groups-sites-table .mainwp-site-checkbox', function() {
    if(typeof jQuery('#mainwp-save-sites-groups-selection-button').attr('selected-tag-siteids') !== "undefined"){
        let tag_siteids = jQuery('#mainwp-save-sites-groups-selection-button').attr('selected-tag-siteids');
        tag_siteids = '' != tag_siteids ? JSON.parse(tag_siteids) : [];

        const valSiteId = jQuery(this).val();
        if (jQuery(this).is(':checked')) {
            if (!tag_siteids.includes(valSiteId)) {
                tag_siteids.push(valSiteId);
            }
        } else {
            const index = tag_siteids.indexOf(valSiteId);
            if (index !== -1) {
                tag_siteids.splice(index,1);
            }
        }

        jQuery('#mainwp-save-sites-groups-selection-button').attr('selected-tag-siteids', tag_siteids.length ? JSON.stringify(tag_siteids) : '' )
    }
});


// Save selected sites for a group
  jQuery(document).on('click', '#mainwp-save-sites-groups-selection-button', function (e) {

    e.preventDefault();

    let groupID = jQuery('#mainwp-groups-menu').find('.active').attr('id');
    let tag_siteids = jQuery('#mainwp-save-sites-groups-selection-button').attr('selected-tag-siteids');

    if (groupID == undefined) {
      return;
    }
    let data = mainwp_secure_data({
      action: 'mainwp_group_updategroup',
      groupId: groupID,
      websiteIds: tag_siteids != '' ? JSON.parse(tag_siteids) : [],
    });

    jQuery(this).addClass('disabled');

    jQuery.post(ajaxurl, data, function (response) {
      jQuery('#mainwp-save-sites-groups-selection-button').removeClass('disabled');
      if (response && response.result === true) {
        feedback('mainwp-message-zone', 'Selection saved successfully.', 'ui green message');
        setTimeout(() => {
          jQuery('#mainwp-message-zone').fadeOut();
        }, 5000);
      } else {
        feedback('mainwp-message-zone', 'Undefined error occurred. Please try again.', 'ui green message');
      }
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
    var dtApi = jQuery('#mainwp-manage-groups-sites-table').dataTable().api();
    var searchValue = dtApi.search();
    dtApi.search('').draw();
    jQuery.post(ajaxurl, data, function (response) {
      jQuery('.dimmer').removeClass('active');
      response = response.trim();
      if (response == 'ERROR') {
        dtApi.search(searchValue).draw();
        return;
      }
      mainwp_datatable_fix_to_update_selected_rows_status(dtApi, 'deselected'); // clear saved state.
      jQuery('input.mainwp-site-checkbox').prop('checked', false);
      jQuery('input.mainwp-site-checkbox').closest('tr').removeClass('selected active');
      let sites = JSON.parse(response);
      for (let id of sites) {
        jQuery('input[value="' + id + '"].mainwp-site-checkbox').prop('checked', true);
        jQuery('input[value="' + id + '"].mainwp-site-checkbox').closest('tr').addClass('selected active');
      }
      jQuery('#mainwp-save-sites-groups-selection-button').attr('selected-tag-siteids', sites?.length ? response : '' );
      mainwp_datatable_fix_to_update_selected_rows_status(dtApi, 'selected active'); // clear saved state.
      dtApi.search(searchValue).draw();
    });
    return false;
  }

  // Apply fade effect to tags in the sites table
  let apply_tag_fade_effect = function (menuItem) {
    // Get the selected tag ID from the menu item
    let selectedTagId = jQuery(menuItem).attr('id');
    
    if (!selectedTagId) {
      // If no tag is selected, reset all tags to full opacity
      jQuery('#mainwp-manage-groups-sites-table span.ui.tag.label').css('opacity', '1');
      return;
    }
    
    // Fade all tags to 10% opacity
    jQuery('#mainwp-manage-groups-sites-table span.ui.tag.label').css('opacity', '0.1');
    
    // Restore full opacity for the selected tag
    jQuery('#mainwp-manage-groups-sites-table span.ui.tag.label[tag_id="' + selectedTagId + '"]').css('opacity', '1');
  }

});
