jQuery(function () {
  jQuery( '#mainwp-qsw-verify-mainwp-child-active' ).on('change', function () {
    if ( jQuery( this ).is( ':checked' ) ) {
      jQuery( '#mainwp-qsw-connect-site-form' ).fadeIn( 500 );
      jQuery( '#mainwp_managesites_add' ).show();
      jQuery( '#mainwp_addsite_continue_button' ).hide();
    } else {
      jQuery( '#mainwp-qsw-connect-site-form' ).fadeOut( 500 );
      jQuery( '#mainwp_managesites_add' ).hide();
      jQuery( '#mainwp_addsite_continue_button' ).show();
    }
  } );

  jQuery( '#mainwp_qsw_client_name_field' ).on('keyup', function () {
    if ( jQuery( this ).val() ) {
      jQuery( '#bulk_add_createclient' ).show();
      jQuery( '#mainwp_qsw_add_client_continue_button' ).hide();
    } else {
      jQuery( '#bulk_add_createclient' ).hide();
      jQuery( '#mainwp_qsw_add_client_continue_button' ).show();
    }
  } );

  jQuery( '#mainwp-toggle-optional-settings' ).on( 'click', function () {
    jQuery( '#mainwp-qsw-optional-settings-form' ).toggle( 300 );
    return false;
  } );

  jQuery( '.ui.checkbox:not(.not-auto-init)' ).checkbox();
  jQuery('.ui.dropdown:not(.not-auto-init)').dropdown();

  jQuery( '.mainwp-checkbox-showhide-elements' ).on( 'click', function () {
    var hiel = jQuery( this ).attr( 'hide-parent' );
    // if semantic ui checkbox is checked.
    if ( jQuery( this ).find( 'input' ).is( ':checked' ) ) {
     jQuery( '[hide-element=' + hiel + ']' ).fadeIn( 500 );
    } else {
     jQuery( '[hide-element=' + hiel + ']' ).fadeOut( 500 );
    }
  } );

  jQuery( document ).on( 'click', '#mainwp_managesites_add', function ( event ) {
    mainwp_setup_managesites_add( event );
  } );

  jQuery( document ).on( 'change', '#mainwp_managesites_add_wpurl', function() {
    var url = jQuery( '#mainwp_managesites_add_wpurl' ).val().trim();
    var protocol = jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val();
      
    if ( url.lastIndexOf( 'http://' ) === 0 ) {
      protocol = 'http';
      url = url.substring( 7 );
    } else if ( url.lastIndexOf( 'https://' ) === 0 ) {
      protocol = 'https';
      url = url.substring( 8 );
    }

    if ( jQuery( '#mainwp_managesites_add_wpname' ).val() == '' ) {
      jQuery( '#mainwp_managesites_add_wpname' ).val( url );
    }

    jQuery( '#mainwp_managesites_add_wpurl' ).val( url );
    jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val( protocol ).trigger( "change" );
   } );

} );

// Connect a new website
mainwp_setup_managesites_add = function () {

  jQuery( '#mainwp-message-zone' ).hide();

  var errors = [ ];

  if ( jQuery( '#mainwp_managesites_add_wpname' ).val().trim() == '' ) {
    errors.push( 'Please enter a title for the website.' );
  }

  if ( jQuery( '#mainwp_managesites_add_wpurl' ).val().trim() == '' ) {
    errors.push( 'Please enter a valid URL for the site.' );
  } else {
    var url = jQuery( '#mainwp_managesites_add_wpurl' ).val().trim();
    if ( url.substr( -1 ) != '/' ) {
      url += '/';
    }

    jQuery( '#mainwp_managesites_add_wpurl' ).val( url );

    if ( !isUrl( jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val() + '://' + jQuery( '#mainwp_managesites_add_wpurl' ).val() ) ) {
      errors.push( 'Please enter a valid URL for the site.' );
    }
  }

  if ( jQuery( '#mainwp_managesites_add_wpadmin' ).val().trim() == '' ) {
    errors.push( 'Please enter a username of the website administrator.' );
  }

  if ( errors.length > 0 ) {
    jQuery( '#mainwp-message-zone' ).html( errors.join( '<br />' )  ).addClass( 'yellow' ).show();
  } else {
    jQuery( '#mainwp-message-zone' ).html( 'Adding the site to your MainWP Dashboard. Please wait...'  ).removeClass( 'green red yellow' ).show();
    jQuery( '#mainwp_managesites_add' ).attr( 'disabled', 'true' ); //disable button to add..

    var url = jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val() + '://' + jQuery( '#mainwp_managesites_add_wpurl' ).val().trim();

    if ( url.substr( -1 ) != '/' ) {
      url += '/';
    }

    var name = jQuery( '#mainwp_managesites_add_wpname' ).val().trim();
        name = name.replace( /"/g, '&quot;' );

    var data = mainwp_setup_secure_data( {
      action: 'mainwp_checkwp',
      name: name,
      url: url,
      admin: jQuery( '#mainwp_managesites_add_wpadmin' ).val().trim(),
    } );

    jQuery.post( ajaxurl, data, function ( res_things ) {
      response = res_things.response;
      response = response.trim();

      var url = jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val() + '://' + jQuery( '#mainwp_managesites_add_wpurl' ).val().trim();
      if ( url.substr( -1 ) != '/' ) {
        url += '/';
      }

      url = url.replace( /"/g, '&quot;' );

      if ( response == 'HTTPERROR' ) {
        errors.push( 'This site can not be reached! Please use the Test Connection feature and see if the positive response will be returned. For additional help, please review <a href="https://kb.mainwp.com/">MainWP Knowledgebase</a>, and if you still have issues, please let us know in the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a>.' );
      } else if ( response == 'NOMAINWP' ) {
        errors.push( 'MainWP Child plugin not detected or could not be reached! Ensure the MainWP Child plugin is installed and activated on the child site, and there are no security rules blocking requests.  If you continue experiencing this issue, check the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a> for help.' );
      } else if ( response.substr( 0, 5 ) == 'ERROR' ) {
        if ( response.length == 5 ) {
          errors.push( 'Undefined error occurred. Please try again. If the issue does not resolve, please review <a href="https://kb.mainwp.com/">MainWP Knowledgebase</a>, and if you still have issues, please let us know in the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a>.' );
        } else {
          errors.push( response.substr( 6 ) );
        }
      } else if ( response == 'OK' ) {
        jQuery( '#mainwp_managesites_add' ).attr( 'disabled', 'true' );

        var name = jQuery( '#mainwp_managesites_add_wpname' ).val();
        name = name.replace( /"/g, '&quot;' );
        var group_ids = '';
        var data = mainwp_setup_secure_data( {
          action: 'mainwp_addwp',
          managesites_add_wpname: name,
          managesites_add_wpurl: url,
          managesites_add_wpadmin: jQuery( '#mainwp_managesites_add_wpadmin' ).val(),
          managesites_add_uniqueId: jQuery( '#mainwp_managesites_add_uniqueId' ).val(),
          groupids: group_ids,
          qsw_page: true,
        } );

        // to support add client reports tokens values
        jQuery( "input[name^='creport_token_']" ).each( function(){
          var tname = jQuery( this ).attr( 'name' );
          var tvalue = jQuery( this ).val();
          data[tname] = tvalue;
        } );

        // support hooks fields
        jQuery( ".mainwp_addition_fields_addsite input" ).each( function() {
            var tname = jQuery( this ).attr( 'name' );
            var tvalue = jQuery( this ).val();
            data[tname] = tvalue;
        } );

        jQuery.post( ajaxurl, data, function ( res_things ) {

          if ( res_things.error ) {
            response = res_things.error;
          } else {
            response = res_things.response;
          }

          response = response.trim();

          jQuery( '#mainwp-message-zone' ).hide();
          jQuery( '#mainwp-info-zone' ).hide();

          if ( response.substr( 0, 5 ) == 'ERROR' ) {
            jQuery( '#mainwp-message-zone' ).removeClass( 'green yellow green' );
            jQuery( '#mainwp-message-zone' ).html( response.substr( 6 ) ).addClass( 'red' ).show();
          } else {
            //Message the WP was added
            jQuery( '#mainwp-message-zone' ).removeClass( 'green yellow green' );
            jQuery( '#mainwp-message-zone' ).html( response ).addClass( 'green' ).show();

            //Reset fields
            jQuery( '#mainwp_managesites_add_wpname' ).val( '' );
            jQuery( '#mainwp_managesites_add_wpurl' ).val( '' );
            jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val( 'https' );
            jQuery( '#mainwp_managesites_add_wpadmin' ).val( '' );
            jQuery( '#mainwp_managesites_add_uniqueId' ).val( '' );

            jQuery( "input[name^='creport_token_']" ).each( function() {
              jQuery( this ).val( '' );
            } );

            // support hooks fields
            jQuery( ".mainwp_addition_fields_addsite input" ).each( function() {
              jQuery( this ).val('');
            } );
            
            setTimeout(function () {
              window.location.href = 'admin.php?page=mainwp-setup&step=connect_first_site';
            }, 1000);
          }

          jQuery( '#mainwp_managesites_add' ).prop("disabled", false);
        }, 'json' );
      }
      if ( errors.length > 0 ) {
        jQuery( '#mainwp-message-zone' ).removeClass( 'green yellow green' );
        jQuery( '#mainwp-message-zone' ).hide();
        jQuery( '#mainwp_managesites_add' ).prop("disabled", false);
        jQuery( '#mainwp-message-zone' ).html( errors.join( '<br />' ) ).addClass( 'red' ).show();
      }
    }, 'json' );
  }
};

// Check if the URL field is valid value
function isUrl( s ) {
  var regexp = /(ftp|http|https):\/\/(\w+:{0,1}\w*@)?(\S+)(:[0-9]+)?(\/|\/([\w#!:.?+=&%@!\-/]))?/;
  return regexp.test( s );
}

mainwp_setup_secure_data = function ( data ) {
  if ( data['action'] == undefined )
    return data;

  data['security'] = jQuery( '#nonce_secure_data' ).attr( data['action'] );

  return data;
};
