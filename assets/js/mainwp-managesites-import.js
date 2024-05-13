
/**
 * Bulk upload sites
 */

var import_current = 0;
var import_stop_by_user = false;
var import_total = 0;
var import_count_success = 0;
var import_count_fails = 0;

jQuery( document ).ready( function () {
    import_total = jQuery( '#mainwp_managesites_total_import' ).val();
    
    jQuery( document ).on( 'click', '#mainwp_managesites_btn_import', function () {
        if ( import_stop_by_user == false ) {
            import_stop_by_user = true;
            jQuery( '#mainwp_managesites_import_logging .log' ).append( __( 'Paused import by user.' ) + "\n" );
            jQuery( '#mainwp_managesites_btn_import' ).val( __( 'Continue' ) );
            jQuery( '#mainwp_managesites_btn_save_csv' ).prop("disabled", false); //Enable
            jQuery( '#mainwp-importing-sites' ).hide();
        } else
        {
            import_stop_by_user = false;
            jQuery( '#mainwp_managesites_import_logging .log' ).append( __( 'Continue import.' ) + "\n" );
            jQuery( '#mainwp_managesites_btn_import' ).val( __( 'Pause' ) );
            jQuery( '#mainwp_managesites_btn_save_csv' ).attr( 'disabled', 'true' ); // Disable
            jQuery( '#mainwp-importing-sites' ).show();
            mainwp_managesites_import_sites();
        }
    } );

    jQuery( document ).on( 'click', '#mainwp_managesites_btn_save_csv', function () {
        var fail_data = '';
        jQuery( '#mainwp_managesites_import_fail_logging span' ).each(function(){
            fail_data += jQuery(this).html() + "\r";
        });
        var blob = new Blob( [ fail_data ], { type: "text/plain;charset=utf-8" } );
        saveAs( blob, "import_sites_fails.csv" );
    } );

    if ( jQuery( '#mainwp_managesites_do_import' ).val() == 1 ) {
        jQuery( '#mainwp-importing-sites' ).show();
        mainwp_managesites_import_sites();
    }
} );

mainwp_managesites_import_sites = function () {
    if ( import_stop_by_user == true )
        return;

    import_current++;

    if ( import_current > import_total )
    {
        jQuery( '#mainwp_managesites_btn_import' ).val( __( 'Finished!' ) );
        jQuery( '#mainwp_managesites_btn_import' ).attr( 'disabled', 'true' ); //Disable
        if ( import_count_success < import_total ) {
            jQuery( '#mainwp_managesites_btn_save_csv' ).prop("disabled", false); //Enable
        }
        jQuery( '#mainwp_managesites_import_logging .log' ).append( '<div class="ui divider"></div>' + __( 'Number of sites to Import: %1 Created sites: %2 Failed: %3', import_total, import_count_success, import_count_fails ) );
        jQuery( '#mainwp_managesites_import_logging' ).scrollTop( jQuery( '#mainwp_managesites_import_logging .log' ).height() );
        jQuery( '#mainwp-importing-sites' ).hide();
        return;
    }

    var import_data = jQuery( '#mainwp_managesites_import_csv_line_' + import_current ).attr('encoded-data');
    var import_line_orig = jQuery( '#mainwp_managesites_import_csv_line_' + import_current ).attr( 'original' );
    var decodedVal = JSON.parse( import_data );

    var import_wpname = decodedVal.name;
    var import_wpurl = decodedVal.url;
    var import_wpadmin = decodedVal.adminname;
    var import_wpgroups = decodedVal.wpgroups;
    var import_uniqueId = decodedVal.uniqueId;
    var import_http_username = decodedVal.http_user;
    var import_http_password = decodedVal.http_pass;
    var import_verify_certificate = decodedVal.verify_certificate;

    if ( typeof ( import_wpname ) == "undefined" )
        import_wpname = '';
    if ( typeof ( import_wpurl ) == "undefined" )
        import_wpurl = '';
    if ( typeof ( import_wpadmin ) == "undefined" )
        import_wpadmin = '';
    if ( typeof ( import_wpgroups ) == "undefined" )
        import_wpgroups = '';
    if ( typeof ( import_uniqueId ) == "undefined" )
        import_uniqueId = '';

    jQuery( '#mainwp_managesites_import_logging .log' ).append( '[' + import_current + '] ' + import_line_orig + '<br/>');

    var errors = [ ];

    if ( import_wpname == '' ) {
        errors.push( __( 'Please enter the site name.' ) );
    }

    if ( import_wpurl == '' ) {
        errors.push( __( 'Please enter the site URL.' ) );
    }

    if ( import_wpadmin == '' ) {
        errors.push( __( 'Please enter username of the site administrator.' ) );
    }

    if ( errors.length > 0 ) {
        jQuery( '#mainwp_managesites_import_logging .log' ).append( '[' + import_current + ']>> Error - ' + errors.join( " " ) + '\n' );
        jQuery( '#mainwp_managesites_import_fail_logging' ).append( '<span>' + import_line_orig + '</span>');
        import_count_fails++;
        mainwp_managesites_import_sites();
        return;
    }

    var data = mainwp_secure_data( {
        action: 'mainwp_checkwp',
        name: import_wpname,
        url: import_wpurl,
        admin: import_wpadmin,
        check_me: import_current,
        verify_certificate: import_verify_certificate,
        http_user: import_http_username,
        http_pass: import_http_password
    } );

    jQuery.post( ajaxurl, data, function ( res_things ) {
        response = res_things.response;

        var check_result = '[' + res_things.check_me + ']>> ';

        response = response.trim();
        var url = import_wpurl;
        if ( url.substr( 0, 4 ) != 'http' ) {
            url = 'http://' + url;
        }
        if ( url.substr( -1 ) != '/' ) {
            url += '/';
        }
        url = url.replace( /"/g, '&quot;' );

        if ( response == 'HTTPERROR' ) {
            errors.push( check_result + __( 'HTTP error: website does not exist!' ) );
        } else if ( response == 'NOMAINWP' ) {
            errors.push( check_result + __( 'MainWP Child plugin not detected! First install and activate the MainWP Child plugin and add your site to MainWP afterwards. Click <a href="%1" target="_blank">here</a> to install <a href="%2" target="_blank">MainWP</a> plugin (do not forget to activate it after installation)', url + 'wp-admin/plugin-install.php?tab=search&type=term&s=mainwp&plugin-search-input=Search+Plugins', url + 'wp-admin/plugin-install.php?tab=search&type=term&s=mainwp&plugin-search-input=Search+Plugins' ) );
        } else if ( response.substr( 0, 5 ) == 'ERROR' ) {
            if ( response.length == 5 ) {
                errors.push( check_result + __( 'Undefined error!' ) );
            } else {
                errors.push( check_result + 'ERROR: ' + response.substr( 6 ) );
            }
        } else if ( response == 'OK' ) {
            var groupids = [ ];
            var data = mainwp_secure_data( {
                action: 'mainwp_addwp',
                managesites_add_wpname: import_wpname,
                managesites_add_wpurl: url,
                managesites_add_wpadmin: import_wpadmin,
                managesites_add_uniqueId: import_uniqueId,
                'groupids[]': groupids,
                groupnames_import: import_wpgroups,
                add_me: import_current,
                verify_certificate: import_verify_certificate,
                managesites_add_http_user: import_http_username,
                managesites_add_http_pass: import_http_password
            } );

            jQuery.post( ajaxurl, data, function ( res_things ) {
                if ( res_things.error )
                {
                    response = 'ERROR: ' + res_things.error;
                } else
                {
                    response = res_things.response;
                }
                var add_result = '[' + res_things.add_me + ']>> ';

                response = response.trim();

                if ( response.substr( 0, 5 ) == 'ERROR' ) {
                    jQuery( '#mainwp_managesites_import_fail_logging' ).append( '<span>' + import_line_orig + '</span>' );
                    jQuery( '#mainwp_managesites_import_logging .log' ).append( add_result + response.substr( 6 ) + "\n" );
                    import_count_fails++;
                } else {
                    //Message the WP was added
                    jQuery( '#mainwp_managesites_import_logging .log' ).append( add_result + response + "\n" );
                    import_count_success++;
                }
                mainwp_managesites_import_sites();
            }, 'json' ).fail( function ( xhr, textStatus, errorThrown ) {
                jQuery( '#mainwp_managesites_import_fail_logging' ).append( '<span>' + import_line_orig + '</span>' );
                jQuery( '#mainwp_managesites_import_logging .log' ).append( "error: " + errorThrown + "\n" );
                import_count_fails++;
                mainwp_managesites_import_sites();
            } );
        }

        if ( errors.length > 0 ) {
            jQuery( '#mainwp_managesites_import_fail_logging' ).append( '<span>' + import_line_orig + '</span>' );
            jQuery( '#mainwp_managesites_import_logging .log' ).append( errors.join( "\n" ) + '\n' );
            import_count_fails++;
            mainwp_managesites_import_sites();
        }
        jQuery( '#mainwp_managesites_import_logging' ).scrollTop( jQuery( '#mainwp_managesites_import_logging .log' ).height() );
    }, 'json' );

};

