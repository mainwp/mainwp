/* eslint complexity: ["error", 100] */

jQuery( document ).on( 'click', '.mainwp-extensions-add-menu', function ()
{
    var extensionSlug = jQuery( this ).parents( '.plugin-card' ).attr( 'extension_slug' );
    var data = mainwp_secure_data( {
        action: 'mainwp_extension_add_menu',
        slug: extensionSlug
    } );

    jQuery.post( ajaxurl, data, function ( response )
    {
        if ( response.result == 'SUCCESS' )
            location.reload();
        else if ( response.error )
        {
            alert( response.error );
        }
    }, 'json' );

    return false;
} );

jQuery( document ).on( 'click', '.mainwp-extensions-remove-menu', function ()
{
    var extensionSlug = jQuery( this ).parents( '.plugin-card' ).attr( 'extension_slug' );
    var data = mainwp_secure_data( {
        action: 'mainwp_extension_remove_menu',
        slug: extensionSlug
    } );

    jQuery.post( ajaxurl, data, function ( response )
    {
        if ( response.result == 'SUCCESS' )
            location.reload();
    }, 'json' );

    return false;
} );

jQuery( document ).ready( function () {
  jQuery( document ).on( 'click', '#mainwp-manage-extension-license', function () {
        jQuery( this ).closest( ".card" ).find( "#mainwp-extensions-api-form" ).toggle();
        return false;
  } );
} );

jQuery( document ).on( 'click', '.mainwp-extensions-activate', function () {
    mainwp_extensions_activate( this, false );
} );

function mainwp_extensions_activate( pObj, retring ) {
  var apiEl = jQuery( pObj ).closest( ".card" );
  var statusEl = apiEl.find( ".activate-api-status" );
  var loadingEl = apiEl.find( ".api-feedback" );

  loadingEl.hide();

    if ( jQuery( pObj ).attr( 'license-status' ) == 'activated' ) {
        loadingEl.show();
        loadingEl.find( '.message' ).html( __( 'Extension license allready activated.' ) );
            return;
    }

    if ( retring == true ) {
        loadingEl.show();
        loadingEl.find( '.message' ).html( __( 'Connection error detected. The Verify Certificate option has been switched to NO. Retrying...' ) );
    } else {
        var extensionSlug = jQuery( apiEl ).attr( 'extension-slug' );
        var key = apiEl.find( 'input[type="text"].extension-api-key' ).val();
        var email = apiEl.find( 'input[type="text"].extension-api-email' ).val();

        if ( key == '' || email == '' )
            return;

        var data = mainwp_secure_data( {
            action: 'mainwp_extension_api_activate',
            slug: extensionSlug,
            key: key,
            email: email
        } );
    }

  loadingEl.show();
  loadingEl.find( '.message' ).removeClass( 'red green' );
  loadingEl.find( '.message' ).html( '<i class="notched circle loading icon"></i>' + __( 'Activating...' ) );

  jQuery.post( ajaxurl, data, function ( response ) {

        var success = false;

        if ( response ) {
            if ( response.result == 'SUCCESS' ) {
        loadingEl.find( '.message' ).addClass( 'green' );
        loadingEl.find( '.message' ).html(  __( 'Extension license activated successfully,' ) );
        statusEl.html( '<i class="ui green empty circular label"></i> ' + __( 'License activated' ) );
                success = true;
            } else if ( response.error ) {
        loadingEl.find( '.message' ).addClass( 'red' );
        loadingEl.find( '.message' ).html( response.error );
            } else if ( response.retry_action && response.retry_action == 1 ) {
                jQuery( "#mainwp_api_sslVerifyCertificate" ).val( 0 );
                mainwp_extensions_activate( pObj, true );
                return false;
            } else {
        loadingEl.find( '.message' ).addClass( 'red' );
        loadingEl.find( '.message' ).html( __( 'Undefined error. Please try again. ') );
            }
        } else {
      loadingEl.find( '.message' ).addClass( 'red' );
      loadingEl.find( '.message' ).html( __( 'Undefined error. Please try again. ') );
        }

        if ( success ) {
      setTimeout( function () {
                location.href = 'admin.php?page=Extensions';
            }, 2000 );
        }
    }, 'json' );
    return false;
}

jQuery( document ).on( 'click', '.mainwp-extensions-deactivate', function () {
  var apiEl = jQuery( this ).closest( ".card" );
  var statusEl = apiEl.find( ".activate-api-status" );
  var loadingEl = apiEl.find( ".api-feedback" );

  if ( !apiEl.find( '.mainwp-extensions-deactivate-chkbox' ).is( ':checked' ) )
        return false;

  loadingEl.hide();

  var extensionSlug = jQuery( apiEl ).attr( 'extension-slug' );

    var data = mainwp_secure_data( {
        action: 'mainwp_extension_deactivate',
        slug: extensionSlug
    } );

    loadingEl.show();
  loadingEl.find( '.message' ).removeClass( 'red green' );
  loadingEl.find( '.message' ).html( '<i class="notched circle loading icon"></i>' + __( 'Deactivating...' ) );

  jQuery.post( ajaxurl, data, function ( response ) {

        if ( response ) {
            if ( response.result == 'SUCCESS' ) {
        loadingEl.find( '.message' ).addClass( 'green' );
        loadingEl.find( '.message' ).html(  __( 'Extension license Dectivated successfully.' ) );
        statusEl.html( '<i class="ui green empty circular label"></i> ' + __( 'License activated' ) );
        success = true;
            } else if ( response.error ) {
        loadingEl.find( '.message' ).addClass( 'red' );
        loadingEl.find( '.message' ).html( response.error );
            } else {
        loadingEl.find( '.message' ).addClass( 'red' );
        loadingEl.find( '.message' ).html( __( 'Undefined error. Please try again. ') );
            }
        } else {
      loadingEl.find( '.message' ).addClass( 'red' );
      loadingEl.find( '.message' ).html( __( 'Undefined error. Please try again. ') );
        }

    setTimeout( function () {
            location.href = 'admin.php?page=Extensions';
    }, 2000 );
    }, 'json' );
    return false;
} );


// Verify mainwp.com login credentials
jQuery( document ).on( 'click', '#mainwp-extensions-savelogin', function () {
    mainwp_extensions_savelogin( this, false );
} );

function mainwp_extensions_savelogin( pObj, retring ) {
  var grabingEl = jQuery( "#mainwp-extensions-api-fields" );

  var username = grabingEl.find( '#mainwp_com_username' ).val();
  var pwd = grabingEl.find( '#mainwp_com_password' ).val();

  var statusEl = jQuery( ".mainwp-extensions-api-loading" );

    var data = mainwp_secure_data( {
        action: 'mainwp_extension_saveextensionapilogin',
        username: username,
        password: pwd,
        saveLogin: jQuery( '#extensions_api_savemylogin_chk' ).is( ':checked' ) ? 1 : 0
    } );

  if ( username == '' || pwd == '' ) {
    statusEl.html( __( "Usenrname and Password fields are required." ) ).show();
    statusEl.addClass( 'yellow' );
  } else {

    if ( retring == true ) {
        statusEl.html( __( "Connection error detected. The Verify Certificate option has been switched to NO. Retrying..." ) ).fadeIn();
    } else
        statusEl.removeClass( 'red' );
        statusEl.removeClass( 'green' );
        statusEl.removeClass( 'yellow' );
        statusEl.show();
        statusEl.html( '<i class="notched circle loading icon"></i> ' + __( 'Verifying. Please wait...' ) );

    jQuery.post( ajaxurl, data, function ( response ) {
        var undefError = false;
        if ( response ) {
            if ( response.saved ) {
                statusEl.addClass( 'green' );
        statusEl.html( 'Login saved.' ).fadeIn();
            } else if ( response.result == 'SUCCESS' ) {
                statusEl.addClass( 'green' );
                statusEl.html( 'Account verification successful!' ).fadeIn();
                  setTimeout( function () {
                    statusEl.fadeOut();
                }, 3000 );
            } else if ( response.error ) {
                statusEl.addClass( 'red' );
                statusEl.html( response.error ).fadeIn();
            } else if ( response.retry_action && response.retry_action == 1 ) {
                jQuery( "#mainwp_api_sslVerifyCertificate" ).val( 0 );
                mainwp_extensions_savelogin( pObj, true );
                return false;
            } else {
                undefError = true;
            }
        } else {
            undefError = true;
        }

        if ( undefError ) {
            statusEl.addClass( 'red' );
            statusEl.html( __( 'Undefined error occurred. Please try again.' ) ).fadeIn();
        }
    }, 'json' );

  }
    return false;
}

var maxActivateThreads = 8;
var totalActivateThreads = 0;
var currentActivateThreads = 0;
var finishedActivateThreads = 0;
var countSuccessActivation = 0;

// Bulk grab API keys
jQuery( document ).on( 'click', '#mainwp-extensions-grabkeys', function () {
    mainwp_extensions_grabkeys( this, false );
} );

function mainwp_extensions_grabkeys( pObj, retring ) {
  var grabingEl = jQuery( "#mainwp-extensions-api-fields" );
  var username = grabingEl.find( '#mainwp_com_username' ).val();
  var pwd = grabingEl.find( '#mainwp_com_password' ).val();
  var statusEl = jQuery( ".mainwp-extensions-api-loading" );

    var data = mainwp_secure_data( {
        action: 'mainwp_extension_testextensionapilogin',
        username: username,
        password: pwd
    } );

  if ( username == '' || pwd == '' ) {
    statusEl.html( __( "Username and Password fields are required." ) ).show();
    statusEl.addClass( 'yellow' );
  } else {
    if ( retring == true ) {
    statusEl.html( __( "Connection error detected. The Verify Certificate option has been switched to NO. Retrying..." ) ).fadeIn();
  } else {
    statusEl.removeClass( 'red' );
      statusEl.removeClass( 'yellow' );
    statusEl.removeClass( 'green' );
    statusEl.show();
      statusEl.html( '<i class="notched circle loading icon"></i> ' + __( 'Verifying. Please wait...' ) );
  }

  jQuery.post( ajaxurl, data, function ( response ) {
        var undefError = false;
        if ( response ) {
            if ( response.result == 'SUCCESS' ) {
      statusEl.addClass( 'green' );
            statusEl.html( 'Account verification successful!' ).fadeIn();
        setTimeout( function () {
                    statusEl.fadeOut();
                }, 3000 );
      totalActivateThreads = jQuery( '#mainwp-extensions-list .card[status="queue"]' ).length;
                if ( totalActivateThreads > 0 )
                    extensions_loop_next();
            } else if ( response.error ) {
      statusEl.addClass( 'red' );
                statusEl.html( response.error ).fadeIn();
            } else if ( response.retry_action && response.retry_action == 1 ) {
                jQuery( "#mainwp_api_sslVerifyCertificate" ).val( 0 );
                mainwp_extensions_grabkeys( pObj, true );
                return false;
            } else {
                undefError = true;
            }
        } else {
            undefError = true;
        }
        if ( undefError ) {
      statusEl.addClass( 'red' );
      statusEl.html( __( 'Undefined error occurred. Please try again.' ) ).fadeIn();
        }
    }, 'json' );
    }
    return false;
}

extensions_loop_next = function () {
  while ( ( extToActivate = jQuery( '#mainwp-extensions-list .card[status="queue"]:first' ) ) && ( extToActivate.length > 0 ) && ( currentActivateThreads < maxActivateThreads ) ) {
        extensions_activate_next( extToActivate );
    }
    if ( ( finishedActivateThreads == totalActivateThreads ) && ( countSuccessActivation == totalActivateThreads ) ) {
        setTimeout( function () {
                location.href = 'admin.php?page=Extensions';
        }, 2000 );
    }
};

extensions_activate_next = function ( pObj ) {

  var grabingEl = jQuery( "#mainwp-extensions-api-fields" );
  var username = grabingEl.find( '#mainwp_com_username' ).val();
  var pwd = grabingEl.find( '#mainwp_com_password' ).val();
  var apiEl = pObj;
  var statusEl = apiEl.find( ".activate-api-status" );
  var loadingEl = apiEl.find( ".api-feedback" );


  apiEl.attr( "status", "running" );

  var extensionSlug = apiEl.attr( 'extension-slug' );
  var data = mainwp_secure_data( {
    action: 'mainwp_extension_grabapikey',
    username: username,
    password: pwd,
    slug: extensionSlug
  } );

  currentActivateThreads++;

  loadingEl.show();
  loadingEl.find( '.message' ).removeClass( 'red green' );
  loadingEl.find( '.message' ).html( '<i class="notched circle loading icon"></i>' + __( 'Activating...' ) );

  if ( apiEl.attr( 'license-status' ) == 'activated' ) {
    finishedActivateThreads++;
    currentActivateThreads--;
    loadingEl.find( '.message' ).addClass( 'green' );
    loadingEl.find( '.message' ).html(  __( 'Extension already activated.' ) );
    countSuccessActivation++;
    extensions_loop_next();
    return;
  }

    jQuery.post( ajaxurl, data, function ( response ){
          finishedActivateThreads++;
          currentActivateThreads--;

          if ( response ) {
              if ( response.result == 'SUCCESS' ) {
                  countSuccessActivation++;
            loadingEl.find( '.message' ).addClass( 'green' );
            loadingEl.find( '.message' ).html(  __( 'Extension license activated successfully,' ) );
            statusEl.html( '<i class="ui green empty circular label"></i> ' + __( 'License activated' ) );
            apiEl.find( '.mainwp-extensions-deactivate-chkbox' ).attr( 'checked', false );
              } else if ( response.error ) {
            loadingEl.find( '.message' ).addClass( 'red' );
            loadingEl.find( '.message' ).html( response.error );
              } else {
            loadingEl.find( '.message' ).addClass( 'red' );
            loadingEl.find( '.message' ).html( __( 'Undefined error. Please try again. ') );
              }
          } else {
        loadingEl.find( '.message' ).addClass( 'red' );
        loadingEl.find( '.message' ).html( __( 'Undefined error. Please try again. ') );
          }

          extensions_loop_next();
      }, 'json' );
};

jQuery( document ).on( 'click', '#mainwp-extensions-bulkinstall', function () {
    mainwp_extension_grab_purchased( this, false );
} )

mainwp_extension_grab_purchased = function ( pObj, retring ) {

  var grabingEl = jQuery( "#mainwp-extensions-api-fields" );
  var username = grabingEl.find( '#mainwp_com_username' ).val();
  var pwd = grabingEl.find( '#mainwp_com_password' ).val();
  var statusEl = jQuery( ".mainwp-extensions-api-loading" );
    var data = mainwp_secure_data( {
        action: 'mainwp_extension_getpurchased',
        username: username,
        password: pwd
    } );

  if ( username == '' || pwd == '' ) {
    statusEl.html( __( "Username and Password fields are required." ) ).show();
    statusEl.addClass( 'yellow' );
  } else {
    if ( retring == true ) {
        statusEl.html( __( "Connection error detected. The Verify Certificate option has been switched to NO. Retrying..." ) ).fadeIn();
    } else
        statusEl.removeClass( 'red' );
        statusEl.removeClass( 'green' );
      statusEl.removeClass( 'yellow' );
        statusEl.show();
      statusEl.html( '<i class="notched circle loading icon"></i> ' + __( 'Verifying. Please wait...' ) );

    jQuery.post( ajaxurl, data, function ( response ) {
          var undefError = false;
          if ( response ) {
              if ( response.result == 'SUCCESS' ) {
                    jQuery( '#mainwp-get-purchased-extensions-modal' ).modal({
                        closable: false,
                        onHide: function() {
                            location.href = 'admin.php?page=Extensions';
                        }
                    }).modal('show');
                    jQuery( '#mainwp-get-purchased-extensions-modal' ).find( '.content' ).html( response.data );
                    statusEl.hide();
              } else if ( response.error ) {
                    statusEl.addClass( 'red' );
                    statusEl.html( response.error ).fadeIn();
              } else if ( response.retry_action && response.retry_action == 1 ) {
                    jQuery( "#mainwp_api_sslVerifyCertificate" ).val( 0 );
                    mainwp_extension_grab_purchased( pObj, true );
                    return false;
              } else {
                    undefError = true;
              }
          } else {
              undefError = true;
          }

          if ( undefError ) {
        statusEl.addClass( 'red' );
        statusEl.html( __( 'Undefined error occurred. Please try again.' ) ).fadeIn();
          }
      }, 'json' );
    }
      return false;
}

jQuery( document ).on( 'click', '#mainwp-extensions-installnow', function () {
    mainwp_extension_bulk_install();
    return false;
} )

bulkExtensionsMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
bulkExtensionsCurrentThreads = 0;
bulkExtensionsTotal = 0;
bulkExtensionsFinished = 0;
bulkExtensionsRunning = false;

mainwp_extension_bulk_install = function () {
    if ( bulkExtensionsRunning )
        return;

    jQuery( '.mainwp-installing-extensions input[type="checkbox"][status="queue"]:not(:checked)' ).closest( '.extension-to-install' ).find( '.installing-extension[status="queue"]' ).html( __( 'Skipped' ) );

    bulkExtensionsTotal = jQuery( '.mainwp-installing-extensions input[type="checkbox"][status="queue"]:checked' ).length;

    if ( bulkExtensionsTotal == 0 )
        return false;

    jQuery( '.mainwp-installing-extensions input[type="checkbox"][status="queue"]:checked' ).closest( '.extension-to-install' ).find( '.installing-extension[status="queue"]' ).html( '<i class="clock outline icon"></i> ' + __( 'Queued' ) );

    mainwp_extension_bulk_install_next();
}

mainwp_extension_bulk_install_next = function () {
    while ( ( extToInstall = jQuery( '.mainwp-installing-extensions input[type="checkbox"][status="queue"]:checked:first' ).closest( '.extension-to-install' ) ) && ( extToInstall.length > 0 ) && ( bulkExtensionsCurrentThreads < bulkExtensionsMaxThreads ) ) {
        mainwp_extension_bulk_install_specific( extToInstall );
    }

    if ( ( bulkExtensionsTotal > 0 ) && ( bulkExtensionsFinished == bulkExtensionsTotal ) ) {
        mainwp_extension_bulk_activate();
    }
}

mainwp_extension_bulk_install_specific = function ( pExtToInstall ) {
  bulkExtensionsRunning = true;
  pExtToInstall.find( 'input[type="checkbox"]' ).attr( 'status', 'running' );
  bulkExtensionsCurrentThreads++;

  var statusEl = pExtToInstall.find( '.installing-extension' );

  statusEl.html( '<i class="notched circle loading icon"></i>' + __( 'Installing extension. Please wait...' ) );

    var data = mainwp_secure_data( {
        action: 'mainwp_extension_downloadandinstall',
        download_link: pExtToInstall.attr( 'download-link' )
    } );

    jQuery.ajax( {
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function () {
            return function ( res_data ) {
                bulkExtensionsCurrentThreads--;
                bulkExtensionsFinished++;

                statusEl.html( '' );

                var reg = new RegExp( '<mainwp>(.*)</mainwp>' );
                var matches = reg.exec( res_data );
                var response = '';

                if ( matches ) {
                    response_json = matches[1];
                    response = jQuery.parseJSON( response_json );
                }

                if ( response != '' ) {
                    if ( response.result == 'SUCCESS' ) {
                        statusEl.html( '<i class="check green circle icon"></i> ' + response.output );
                        jQuery( '.mainwp-installing-extensions' ).append( '<span class="extension-installed-success" slug="' + response.slug + '"></span>' )
                    } else if ( response.error ) {
                        statusEl.html( '<i class="times red circle icon"></i> ' + response.error );
                    } else {
                        statusEl.html( '<i class="times red circle icon"></i> Undefined error occured. Please try again.' );
                    }
                } else {
                    statusEl.html( '<i class="times red circle icon"></i> Undefined error occured. Please try again.' );
                }

                mainwp_extension_bulk_install_next();
            }
        }()
    } );

    return false;
}

mainwp_extension_bulk_activate = function () {
  var plugins = [ ];

  jQuery( '.extension-installed-success' ).each( function () {
    plugins.push( jQuery( this ).attr( 'slug' ) );
  } );

  if ( plugins.length == 0 ) {
    mainwp_extension_bulk_install_done();
    return;
  }

  var data = mainwp_secure_data( {
      action: 'mainwp_extension_bulk_activate',
      plugins: plugins
  } );

  var statusEl = jQuery( '#mainwp-bulk-activating-extensions-status' );

  statusEl.html( '<i class="notched circle loading icon"></i>' + __( 'Activating extensions. Please wait...' ) ).show();
  jQuery.post( ajaxurl, data, function ( response ) {
    statusEl.html( '' );
    if ( response == 'SUCCESS' ) {
      statusEl.addClass( 'green' );
      statusEl.html( __( 'Extensions have been activated successfully!' ) );
      statusEl.fadeOut( 3000 );
    }
    mainwp_extension_bulk_install_done();
  } );
}

mainwp_extension_bulk_install_done = function () {
  bulkExtensionsRunning = false;

  var statusEl = jQuery( '#mainwp-bulk-activating-extensions-status' );

  statusEl.addClass( 'green' );
  statusEl.html( __( "Installation completed successfully. Page will reload automatically in 3 seconds." ) ).show();
}


// Is this function still in use???
jQuery( document ).on( 'click', '#mainwp-extensions-api-sslverify-certificate', function () {

    var parent = jQuery( this ).closest( ".extension_api_sslverify_loading" );
    var statusEl = parent.find( 'span.status' );
    var loadingEl = parent.find( "i" );

    var data = mainwp_secure_data( {
        action: 'mainwp_extension_apisslverifycertificate',
        api_sslverify: jQuery( "#mainwp_api_sslVerifyCertificate" ).val()
    } );

    statusEl.hide();
    loadingEl.show();
    jQuery.post( ajaxurl, data, function ( response )
    {
        loadingEl.hide();
        var undefError = false;
        if ( response ) {
            if ( response.saved ) {
                statusEl.css( 'color', '#0074a2' );
                statusEl.html( '<i class="check circle icon"></i> Saved!' ).fadeIn();
                setTimeout( function ()
                {
                    statusEl.fadeOut();
                }, 3000 );
            } else if ( response.error ) {
                statusEl.css( 'color', 'red' );
                statusEl.html( response.error ).fadeIn();
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
} );

jQuery( document ).ready( function ( $ ) {
    jQuery( document ).on( 'click', '#mainwp-check-all-ext', function () {
        $( '.extension-to-install' ).find( "input:checkbox" ).each( function () {
            $( this ).attr( 'checked', true );
        } );
    } );
    jQuery( document ).on( 'click', '#mainwp-uncheck-all-ext', function () {
        $( '.extension-to-install' ).find( "input:checkbox" ).each( function () {
            $( this ).attr( 'checked', false );
        } );
    } );

    jQuery( document ).on( 'click', '#mainwp-check-all-sync-ext', function () {
        $( '.sync-ext-row' ).find( "input:checkbox" ).each( function () {
            $( this ).attr( 'checked', true );
        } );
    } );
    jQuery( document ).on( 'click', '#mainwp-uncheck-all-sync-ext', function () {
        $( '.sync-ext-row' ).find( "input:checkbox" ).each( function () {
            $( this ).attr( 'checked', false );
        } );
    } );

    jQuery( document ).on( 'click', '.mainwp-show-extensions', function () {
        $( 'a.mainwp-show-extensions' ).removeClass( 'mainwp_action_down' );
        $( this ).addClass( 'mainwp_action_down' );

        var gr = $( this ).attr( 'group' );
        var selectedEl = $( '#mainwp-available-extensions-list .mainwp-availbale-extension-holder.group-' + gr );
        var installedGroup = $( '.installed-group-exts' );
        installedGroup.hide();

        if ( gr == 'all' ) {
            $( '#mainwp-available-extensions-list .mainwp-availbale-extension-holder' ).fadeIn( 500 );
        } else {
            $( '#mainwp-available-extensions-list .mainwp-availbale-extension-holder' ).hide();
            if ( selectedEl.length > 0 ) {
                selectedEl.fadeIn( 500 );
            } else {
                installedGroup.fadeIn( 500 );
            }
        }

        return false;
    } );
} );
