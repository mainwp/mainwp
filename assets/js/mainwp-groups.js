jQuery( document ).ready( function () {

  // Init the groups menu
  jQuery( '#mainwp-groups-menu' ).find( 'a.item' ).on( 'click', function () {
    jQuery( this ).addClass( 'active green' );
    jQuery( this ).siblings().removeClass( 'active green' );
    jQuery( this ).find( '.label' ).addClass( 'green' );
    jQuery( this ).siblings().find( '.label' ).removeClass( 'green' );
    jQuery( '#mainwp-delete-group-button' ).removeClass( 'disabled' );
    jQuery( '#mainwp-rename-group-button' ).removeClass( 'disabled' );
    jQuery( '#mainwp-save-sites-groups-selection-button' ).removeClass( 'disabled' );
    show_group_items( this );
  } );

  // Trigger the create a new group modal
  jQuery( document ).on( 'click', '#mainwp-new-sites-group-button', function () {
    jQuery( '#mainwp-create-group-modal' ).modal( {
      onHide: function () {
        window.location.reload();
        return false;
      },
      onShow: function () {
        jQuery( '#mainwp-create-group-modal' ).find( 'input' ).val( '' );
        return false;
      }
    } ).modal( 'show' );
  } );

  // Trigger the edit group modal
  jQuery( document ).on( 'click', '#mainwp-rename-group-button', function () {
    jQuery( '#mainwp-rename-group-modal' ).modal( {
      onHide: function () {
        window.location.reload();
        return false;
      },
      onShow: function () {
        var groupName = jQuery( '#mainwp-groups-menu' ).find( '.active' ).find( '#mainwp-hidden-group-name' ).val();
        jQuery( '#mainwp-rename-group-modal' ).find( 'input' ).val( groupName );
        return false;
      }
    } ).modal( 'show' );
  } );

  // Create a new group
  jQuery( document ).on( 'click', '#mainwp-save-new-group-button', function () {
    var newName = jQuery( '#mainwp-create-group-modal' ).find( 'input' ).val();
    var data = mainwp_secure_data( {
      action: 'mainwp_group_add',
      newName: newName
    } );
    jQuery.post( ajaxurl, data, function ( response ) {
      try {
        resp = jQuery.parseJSON( response );

        if ( resp.error != undefined )
          return;
      } catch ( err ) {

      }
      jQuery( '#mainwp-create-group-modal' ).modal( {
        onHide: function () {
          window.location.reload();
          return false;
        }
      } ).modal( 'hide' );
    } );
    return false;
  } );

  // Delete a group
  jQuery( document ).on( 'click', '#mainwp-delete-group-button', function () {
    var gruopItem = jQuery( '#mainwp-groups-menu' ).find( '.active' );
    mainwp_confirm( 'Are you sure you want to delete this group?', function() {
      var groupID = gruopItem.attr( 'id' );
      var data = mainwp_secure_data( {
        action: 'mainwp_group_delete',
        groupId: groupID
      } );
      jQuery.post( ajaxurl, data, function ( gruopItem ) {
        return function ( response ) {
          response = jQuery.trim( response );
          if ( response == 'OK' ) {
            gruopItem.fadeOut( 300 );
          }
        }
      }( gruopItem ) );
    } );
    return false;
  } );

  // Update group name
  jQuery( document ).on( 'click', '#mainwp-update-new-group-button', function () {
    var groupID = jQuery( '#mainwp-groups-menu' ).find( '.active' ).attr( 'id' );
    var newName = jQuery( '#mainwp-rename-group-modal' ).find( 'input' ).val();
    var data = mainwp_secure_data( {
      action: 'mainwp_group_rename',
      groupId: groupID,
      newName: newName
    } );
    jQuery.post( ajaxurl, data, function () {
      return function ( response ) {
        if ( response.error ) {
          return;
        }
        response = jQuery.trim( response.result );
        jQuery( '#mainwp-create-group-modal' ).modal( {
          onHide: function () {
            window.location.reload();
            return false;
          }
        } ).modal( 'hide' );
      }
    }(), 'json' );
    return false;
  } );

  // Select all sites
  jQuery( '#mainwp-manage-groups-sites-table th input[type="checkbox"]' ).on( 'change', function () {
    var checkboxes = jQuery( '#mainwp-manage-groups-sites-table' ).find( ':checkbox' );
    if ( jQuery( this ).prop( 'checked' ) ) {
      checkboxes.prop( 'checked', true );
      checkboxes.parents( 'tr' ).addClass( 'active' );
    } else {
      checkboxes.prop( 'checked', false );
      checkboxes.parents( 'tr' ).removeClass( 'active' );
    }
  } );

  // Set class 'active' to selected sites table row
  jQuery( '.mainwp-site-checkbox' ).on( 'change', function () {
    if ( jQuery( this ).prop( 'checked' ) ) {
      jQuery( this ).parents( 'tr' ).addClass( 'active' );
    } else {
      jQuery( this ).parents( 'tr' ).removeClass( 'active' );
    }
  } );

  // Save selected sites for a group
  jQuery( document ).on( 'click', '#mainwp-save-sites-groups-selection-button', function () {
    var groupID = jQuery( '#mainwp-groups-menu' ).find( '.active' ).attr( 'id' );
    var sites = jQuery( '#mainwp-manage-groups-sites-table' ).find( 'input.mainwp-site-checkbox:checked' );
    var sitesIDs = [ ];

    for ( var i = 0; i < sites.length; i++ ) {
      sitesIDs.push( jQuery( sites[i] ).val() );
    }

    if ( groupID == undefined ) {
      return;
    }

    var data = mainwp_secure_data( {
      action: 'mainwp_group_updategroup',
      groupId: groupID,
      websiteIds: sitesIDs
    } );

    jQuery( this ).addClass( 'disabled' );

    jQuery.post( ajaxurl, data, function ( response ) {
      jQuery( this ).removeClass( 'disabled' );
      jQuery( '#mainwp-message-zone' ).stop( true, true );
      jQuery( '#mainwp-message-zone' ).show();
      jQuery( '#mainwp-message-zone' ).fadeOut( 3000 );
      return;
    }, 'json' );

  } );

  // Load group sites
  show_group_items = function( group ) {
    var groupID = jQuery( group ).attr( 'id' );
    var data = {
  		action: 'mainwp_group_getsites',
  		groupId: groupID
  	}
  	jQuery( '.dimmer' ).addClass( 'active' );
    jQuery.post( ajaxurl, data, function ( response ) {
  		jQuery( '.dimmer' ).removeClass( 'active' );
  		response = jQuery.trim( response );
  		if ( response == 'ERROR' ) {
  			return;
  		}
  		jQuery( 'input.mainwp-site-checkbox' ).prop( 'checked', false );
  		jQuery( 'input.mainwp-site-checkbox' ).closest( 'tr' ).removeClass( 'active' );
  		var sites = jQuery.parseJSON( response );
  		for ( var i = 0; i < sites.length; i++ ) {
  			jQuery( 'input[value="' + sites[i] + '"].mainwp-site-checkbox' ).prop( 'checked', true );
  			jQuery( 'input[value="' + sites[i] + '"].mainwp-site-checkbox' ).closest( 'tr' ).addClass( 'active' );
  		}
  	} );
  	return false;
  }

} );
