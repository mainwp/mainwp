jQuery( document ).ready( function () {
    jQuery( 'input[type=radio][name=mwp_setup_installation_hosting_type]' ).change( function () {
        if (this.value == 2) {
            jQuery('input[name="mwp_setup_installation_system_type"]').removeAttr("disabled");
        }
        else {
            jQuery('input[name="mwp_setup_installation_system_type"]').attr("disabled", "disabled");
        }
        mainwp_setup_showhide_os_settings(this.value == 2 ? true : false);
    } );

    jQuery( 'input[type=radio][name=mwp_setup_installation_system_type]' ).change( function () {
        mainwp_setup_showhide_ssl_settings( true );
    } );

    jQuery( '#mwp_setup_planning_backup' ).change( function () {
        if ( jQuery( this ).is( ':checked' ) ) {
            jQuery( '#mwp_setup_tr_backup_method' ).fadeIn( 500 );
            jQuery( '#mwp_setup_backup_method' ).removeAttr( 'disabled' );
        } else {
            jQuery( '#mwp_setup_tr_backup_method' ).fadeOut( 500 );
            jQuery( '#mwp_setup_backup_method' ).attr( 'disabled', 'disabled' );
        }
    } );

    jQuery( '#mwp_setup_backup_method' ).on( 'change', function () {
        var bkmethod = jQuery( this ).val();
        jQuery( '#mainwp-quick-setup-account-login').toggle( (bkmethod != '' ? true : false) );
    } );

    jQuery( '#mwp_setup_manage_planning' ).change( function () {
        if ( ( jQuery( this ).val() == 2 ) && ( jQuery( '#mwp_setup_type_hosting' ).val() == 3 ) ) {
            jQuery( '#mwp_setup_hosting_notice' ).fadeIn( 500 );
        } else {
            jQuery( '#mwp_setup_hosting_notice' ).fadeOut( 1000 );
        }
    } )

    jQuery( '#mwp_setup_manage_planning' ).change( function () {
        mainwp_setup_showhide_hosting_notice();
    } )
    jQuery( '#mwp_setup_type_hosting' ).change( function () {
        mainwp_setup_showhide_hosting_notice();
    } )
} );


mainwp_setup_showhide_os_settings = function( pShow ) {
    var row = jQuery('#mainwp-quick-setup-system-type');
    if ( pShow ) {
        row.fadeIn( 500 );
    } else {
        row.fadeOut( 500 );
    }
    mainwp_setup_showhide_ssl_settings( pShow );
}

mainwp_setup_showhide_ssl_settings = function( pShow ) {
    var show = false;
    if ( pShow ) {
        if (jQuery('input[name="mwp_setup_installation_system_type"]:checked').val() == 3) {
            show = true;
        }
    }

    var row = jQuery('#mainwp-quick-setup-opessl-location');
    if (show) {
        row.fadeIn( 500 );
    } else {
        row.fadeOut( 500 );
    }
}


mainwp_setup_auth_uptime_robot = function ( url ) {
    window.open( url, 'Authorize Uptime Robot', 'height=600,width=1000' );
    return false;
}


mainwp_setup_showhide_hosting_notice = function () {
    if ( ( jQuery( '#mwp_setup_manage_planning' ).val() == 2 ) && ( jQuery( '#mwp_setup_type_hosting' ).val() == 3 ) ) {
        jQuery( '#mwp_setup_hosting_notice' ).fadeIn( 500 );
    } else {
        jQuery( '#mwp_setup_hosting_notice' ).fadeOut( 500 );
    }
}

mainwp_setup_grab_extension = function ( retring ) {
    var parent = jQuery( "#mwp_setup_auto_install_loading" );
    var statusEl = parent.find( 'span.status' );
    var loadingEl = parent.find( ".ui.dimmer" );

    var extProductId = jQuery( '#mwp_setup_extension_product_id' ).val();
    if ( extProductId == '' ) {
        statusEl.css( 'color', 'red' );
        statusEl.html( ' ' + "ERROR: empty extension product id." ).fadeIn();
        return false;
    }

    var data = {
        action: 'mainwp_setup_extension_getextension',
        productId: extProductId
    };

    if ( retring == true ) {
        statusEl.css( 'color', '#0074a2' );
        statusEl.html( ' ' + "Connection error detected. The Verify Certificate option has been switched to NO. Retrying..." ).fadeIn();
    } else
        statusEl.hide();

    loadingEl.show();
    jQuery.post( ajaxurl, data, function ( response )
    {
        loadingEl.hide();
        var undefError = false;
        if ( response ) {
            if ( response.result == 'SUCCESS' ) {
                jQuery( '#mainwp-quick-setup-extension-activation' ).html( response.data );
                mainwp_setup_extension_install( );
            } else if ( response.error ) {
                statusEl.css( 'color', 'red' );
                statusEl.html( response.error ).fadeIn();
            } else if ( response.retry_action && response.retry_action == 1 ) {
                mainwp_setup_grab_extension( true );
                return false;
            } else {
                undefError = true;
            }
        } else {
            undefError = true;
        }

        if ( undefError ) {
            statusEl.css( 'color', 'red' );
            statusEl.html( '<i class="exclamation circle icon"></i> Undefined error!' ).fadeIn();
        }
    }, 'json' );
    return false;
}

mainwp_setup_extension_install = function () {
    var pExtToInstall = jQuery( '#mainwp-quick-setup-installation-progress .extension_to_install' );
    var loadingEl = pExtToInstall.find( '.ext_installing .install-running' );
    var statusEl = pExtToInstall.find( '.ext_installing .status' );
    loadingEl.show();
    statusEl.css( 'color', '#000' );
    statusEl.html( '' );

    var data = {
        action: 'mainwp_setup_extension_downloadandinstall',
        download_link: pExtToInstall.attr( 'download-link' ),
        security: mainwpSetupLocalize.nonce
    };

    jQuery.ajax( {
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function () {
            return function ( res_data ) {
                loadingEl.hide();
                var reg = new RegExp( '<mainwp>(.*)</mainwp>' );
                var matches = reg.exec( res_data );
                var response = '';
                var failed = true;
                if ( matches ) {
                    response_json = matches[1];
                    response = jQuery.parseJSON( response_json );
                }
                if ( response != '' ) {
                    if ( response.result == 'SUCCESS' ) {
                        failed = false;
                        statusEl.css( 'color', '#21759B' )
                        statusEl.html( response.output ).show();
                        jQuery( '#mainwp-quick-setup-installation-progress' ).append( '<span class="extension_installed_success" slug="' + response.slug + '"></span>' );

                        jQuery( '#mwp_setup_active_extension' ).fadeIn( 500 );
                        mainwp_setup_extension_activate( false );

                    } else if ( response.error ) {
                        statusEl.css( 'color', 'red' );
                        statusEl.html( '<strong><i class="exclamation circle icon"></i> ERROR:</strong> ' + response.error ).show();
                    } else {
                        statusEl.css( 'color', 'red' );
                        statusEl.html( '<i class="exclamation circle icon"></i> Undefined error!' ).show();
                    }
                } else {
                    statusEl.css( 'color', 'red' );
                    statusEl.html( '<i class="exclamation circle icon"></i> Undefined error!' ).show();
                }
                if ( failed ) {
                    jQuery( '#mainwp-quick-setup-extension-activation' ).append( jQuery( '#mwp_setup_extension_retry_install' )[0].innerHTML );
                }
            }
        }()
    } );
    return false;
}


mainwp_setup_extension_activate_plugin = function () {
    var plugins = [ ];
    jQuery( '.extension_installed_success' ).each( function () {
        plugins.push( jQuery( this ).attr( 'slug' ) );
    } );

    if ( plugins.length == 0 ) {
        return;
    }

    var data = {
        action: 'mainwp_setup_extension_activate_plugin',
        plugins: plugins,
        security: mainwpSetupLocalize.nonce
    };

    jQuery.post( ajaxurl, data, function ( response ) {
        if ( response == 'SUCCESS' ) {
            jQuery( '#mwp_setup_active_extension' ).fadeIn( 500 );
            mainwp_setup_extension_activate( false );
        } 
    } );
}

mainwp_setup_extension_activate = function ( retring )
{
    var parent = jQuery( "#mwp_setup_grabing_api_key_loading" );
    var statusEl = parent.find( 'span.status' );
    var loadingEl = parent.find( "i" );
    var extensionSlug = jQuery( '#mwp_setup_extension_product_id' ).attr( 'slug' );
    var data = {
        action: 'mainwp_setup_extension_grabapikey',
        slug: extensionSlug
    };

    if ( retring == true ) {
        statusEl.css( 'color', '#0074a2' );
        statusEl.html( ' ' + "Connection error detected. The Verify Certificate option has been switched to NO. Retrying..." ).fadeIn();
    } else
        statusEl.hide();

    loadingEl.show();
    jQuery.post( ajaxurl, data, function ( response )
    {
        loadingEl.hide();
        if ( response ) {
            if ( response.result == 'SUCCESS' ) {
                statusEl.css( 'color', '#0074a2' );
                statusEl.html( '<i class="check circle icon"></i> ' + "Extension has been activated successfully!" ).fadeIn();
            } else if ( response.error ) {
                statusEl.css( 'color', 'red' );
                statusEl.html( response.error ).fadeIn();
            } else if ( response.retry_action && response.retry_action == 1 ) {
                jQuery( "#mainwp_api_sslVerifyCertificate" ).val( 0 );
                mainwp_setup_extension_activate( true );
                return false;
            } else {
                statusEl.css( 'color', 'red' );
                statusEl.html( '<i class="exclamation circle icon"></i> Undefined error!' ).fadeIn();
            }
        } else {
            statusEl.css( 'color', 'red' );
            statusEl.html( '<i class="exclamation circle icon"></i> Undefined error!' ).fadeIn();
        }
    }, 'json' );
};

// Connect a new website
mainwp_setup_managesites_add = function () {    
    
    jQuery( '#mainwp-message-zone' ).hide();
    var errors = [ ];
    if ( jQuery( '#mainwp_managesites_add_wpname' ).val() == '' ) {
      errors.push( 'Please enter a title for the website.' );
    }
    if ( jQuery( '#mainwp_managesites_add_wpurl' ).val() == '' ) {
      errors.push( 'Please enter a valid URL for the site.' );
    } else {
      var url = jQuery( '#mainwp_managesites_add_wpurl' ).val();
      if ( url.substr( -1 ) != '/' ) {
        url += '/';
      }

      jQuery( '#mainwp_managesites_add_wpurl' ).val( url );

      if ( !isUrl( jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val() + '://' + jQuery( '#mainwp_managesites_add_wpurl' ).val() ) ) {
        errors.push( 'Please enter a valid URL for the site.' );
      }
    }
    if ( jQuery( '#mainwp_managesites_add_wpadmin' ).val() == '' ) {
      errors.push( 'Please enter a username of the website administrator.' );
    } 
    if ( errors.length > 0 ) {      
      jQuery( '#mainwp-message-zone' ).html( errors.join( '<br />' )  ).addClass('yellow' ).show();
    } else {      
      jQuery( '#mainwp-message-zone' ).html( 'Adding the site to your MainWP Dashboard. Please wait...'  ).addClass( 'green' ).show();

      jQuery( '#mainwp_managesites_add' ).attr( 'disabled', 'true' ); //disable button to add..

      //Check if valid user & rulewp is installed?
      var url = jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val() + '://' + jQuery( '#mainwp_managesites_add_wpurl' ).val();

      if ( url.substr( -1 ) != '/' ) {
          url += '/';
      }

      var name = jQuery( '#mainwp_managesites_add_wpname' ).val();
          name = name.replace( /"/g, '&quot;' );

      var data = mainwp_setup_secure_data( {
        action: 'mainwp_checkwp',
        name: name,
        url: url,
        admin: jQuery( '#mainwp_managesites_add_wpadmin' ).val(),
        verify_certificate: jQuery( '#mainwp_managesites_verify_certificate' ).val(),
//        force_use_ipv4: jQuery( '#mainwp_managesites_force_use_ipv4' ).val(),
//        ssl_version: jQuery( '#mainwp_managesites_ssl_version' ).val(),
//        http_user: jQuery( '#mainwp_managesites_add_http_user' ).val(),
//        http_pass: jQuery( '#mainwp_managesites_add_http_pass' ).val()        
      });

      jQuery.post( ajaxurl, data, function ( res_things ) {
        response = res_things.response;
        response = jQuery.trim( response );

        var url = jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val() + '://' + jQuery( '#mainwp_managesites_add_wpurl' ).val();
        if ( url.substr( -1 ) != '/' ) {
          url += '/';
        }

        url = url.replace( /"/g, '&quot;' );

        if ( response == 'HTTPERROR' ) {
          errors.push( 'This site can not be reached! Please use the Test Connection feature and see if the positive response will be returned. For additional help, contact the MainWP Support.' );
        } else if ( response == 'NOMAINWP' ) {
          errors.push( 'MainWP Child Plugin not detected! Please make sure that the MainWP Child plugin is installed and activated on the child site. For additional help, contact the MainWP Support.' );
        } else if ( response.substr( 0, 5 ) == 'ERROR' ) {
          if ( response.length == 5 ) {
            errors.push( 'Undefined error occurred. Please try again. If the issue does not resolve, please contact the MainWP Support.' );
          } else {
            errors.push( response.substr( 6 ) );
          }
        } else if ( response == 'OK' ) {
          jQuery( '#mainwp_managesites_add' ).attr( 'disabled', 'true' ); //Disable add button

          var name = jQuery( '#mainwp_managesites_add_wpname' ).val();
          name = name.replace( /"/g, '&quot;' );
//          var group_ids = jQuery( '#mainwp_managesites_add_addgroups' ).dropdown('get value');
          var group_ids = '';
          var data = mainwp_setup_secure_data( {
            action: 'mainwp_addwp',
            managesites_add_wpname: name,
            managesites_add_wpurl: url,
            managesites_add_wpadmin: jQuery( '#mainwp_managesites_add_wpadmin' ).val(),
            managesites_add_uniqueId: jQuery( '#mainwp_managesites_add_uniqueId' ).val(),
            groupids: group_ids,
            verify_certificate: jQuery( '#mainwp_managesites_verify_certificate' ).val(),
            qsw_page: true,            
//            force_use_ipv4: jQuery( '#mainwp_managesites_force_use_ipv4' ).val(),
//            ssl_version: jQuery( '#mainwp_managesites_ssl_version' ).val(),
//            managesites_add_http_user: jQuery( '#mainwp_managesites_add_http_user' ).val(),
//            managesites_add_http_pass: jQuery( '#mainwp_managesites_add_http_pass' ).val()            
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
            response = jQuery.trim( response );

            jQuery( '#mainwp-message-zone' ).hide();
            jQuery( '#mainwp-info-zone' ).hide();

            if ( response.substr( 0, 5 ) == 'ERROR' ) {
              jQuery( '#mainwp-message-zone' ).removeClass( 'green' );
              jQuery( '#mainwp-message-zone' ).html(  response.substr( 6 ) ).addClass('green' ).show();
            } else {
              //Message the WP was added
              jQuery( '#mainwp-message-zone' ).removeClass( 'red' );
              jQuery( '#mainwp-message-zone' ).html( response ).addClass('green' ).show();
              jQuery( '#mainwp-info-zone' ).html( 'You can also add more sites now or <a href="admin.php?page=mainwp-setup&step=hosting_setup" class="ui blue mini button">Continue with Quick Setup Wizard</a>' ).show();

              //Reset fields
              jQuery( '#mainwp_managesites_add_wpname' ).val( '' );
              jQuery( '#mainwp_managesites_add_wpurl' ).val( '' );
              jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val( 'https' );
              jQuery( '#mainwp_managesites_add_wpadmin' ).val( '' );
              jQuery( '#mainwp_managesites_add_uniqueId' ).val( '' );
//              jQuery( '#mainwp_managesites_add_addgroups').dropdown('clear');
              jQuery( '#mainwp_managesites_verify_certificate' ).val( 1 );
//              jQuery( '#mainwp_managesites_force_use_ipv4' ).val( 0 );
//              jQuery( '#mainwp_managesites_ssl_version' ).val( 'auto' );

              jQuery( "input[name^='creport_token_']" ).each(function(){
                jQuery( this ).val( '' );
              } );

              // support hooks fields
              jQuery( ".mainwp_addition_fields_addsite input" ).each( function() {
                jQuery( this ).val('');
              } );
              }

              jQuery( '#mainwp_managesites_add' ).removeAttr( 'disabled' ); //Enable add button
              }, 'json' );
            }
            if ( errors.length > 0 ) {
              jQuery( '#mainwp-message-zone' ).removeClass( 'green' );
              jQuery( '#mainwp-message-zone' ).hide();
              jQuery( '#mainwp_managesites_add' ).removeAttr( 'disabled' ); //Enable add button              
              jQuery( '#mainwp-message-zone' ).html( errors.join( '<br />' ) ).addClass('red' ).show();
            }
        }, 'json' );
    }
};

function isUrl( s ) {
    var regexp = /(ftp|http|https):\/\/(\w+:{0,1}\w*@)?(\S+)(:[0-9]+)?(\/|\/([\w#!:.?+=&%@!\-\/]))?/;
    return regexp.test( s );
}

mainwp_setup_secure_data = function ( data )
{
    if ( data['action'] == undefined )
        return data;
    data['security'] = jQuery( '#nonce_secure_data' ).attr(data['action']);     
    return data;
};

jQuery( document ).ready( function () {

  jQuery( document ).on( 'click', '#mainwp-multi-emails-add', function () {
      jQuery( '#mainwp-multi-emails-add' ).before( '<div id="mainwp-multi-emails"><input type="text" name="mainwp_options_email[]" value=""/><a href="#" id="mainwp-multi-emails-remove" class="ui button basic red">Remove Email</a></div>' );
      return false;
  } );

  jQuery( document ).on( 'click', '#mainwp-multi-emails-remove', function () {
      jQuery( this ).closest( '#mainwp-multi-emails' ).remove();
      return false;
  } );
  
    jQuery( document ).on( 'click', '#mainwp_managesites_add', function (event) {
        mainwp_setup_managesites_add( event );
    } );
    
    jQuery(document).on('change', '#mainwp_managesites_add_wpurl', function() {
        var url = jQuery( '#mainwp_managesites_add_wpurl' ).val();
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
