/**
 * MainWP_User.page
 */
var userCountSent = 0;
var userCountReceived = 0;
jQuery( document ).ready( function () {

  // Fetch users
    jQuery( document ).on( 'click', '#mainwp_show_users', function () {
        mainwp_fetch_users();
    } );

  // Delete single user
    jQuery( document ).on( 'click', '.user_submitdelete', function () {
    var confirmation = confirm( 'Are you sure you want to proceed?' );

    if ( confirmation == true ) {
        mainwpuser_postAction( jQuery( this ), 'delete' );
      return;
    }

        return false;
    } );

  // Edit single user
    jQuery( document ).on( 'click', '.user_getedit', function () {
        jQuery( 'td.check-column input[type="checkbox"]' ).each( function () {
            this.checked = false;
        } );
        mainwp_edit_users_box_init();
        jQuery( '#mainwp-edit-users-modal' ).modal( 'setting', 'closable', false ).modal('show');
        mainwpuser_postAction( jQuery( this ), 'edit' );
        return false;
    } );

  // Trigger Manage Users bulk actions
    jQuery( document ).on( 'click', '#mainwp-do-users-bulk-actions', function () {
        var action = jQuery( '#mainwp-bulk-actions' ).val();
        if ( action == 'none' )
            return false;

        var tmp = jQuery( "input[name='user[]']:checked" );
        userCountSent = tmp.length;

        if ( userCountSent == 0 )
            return false;

    var _callback = function() {

            if ( action == 'edit' ) {
                jQuery( '#mainwp-edit-users-modal' ).modal( 'setting', 'closable', false ).modal('show');
                mainwp_edit_users_box_init();
                return;
            }

            jQuery( '#mainwp-do-users-bulk-actions' ).attr( 'disabled', 'true' );
            tmp.each(
                function ( index, elem ) {
                    mainwpuser_postAction( elem, action );
                }
            );
        };

        if ( action == 'delete' ) {
            var msg = __( 'You are about to delete %1 user(s). Are you sure you want to proceed?', userCountSent );
            mainwp_confirm( msg, _callback );
            return false;
        }

        _callback();

        return false;
    } );


    jQuery( document ).on( 'click', '#mainwp_btn_update_user', function () {
        var errors = [ ];
        var tmp = jQuery( "input[name='user[]']:checked" );
        userCountSent = tmp.length;

        if ( userCountSent == 0 ) {
            errors.push( __( 'Please search and select users.' ) );
        }

        if ( errors.length > 0 ) {
            jQuery( '#mainwp_update_password_error' ).html( errors.join( '<br />' ) );
            jQuery( '#mainwp_update_password_error' ).show();
            return false;
        }

        jQuery( '#mainwp_update_password_error' ).hide();
        jQuery( '#mainwp_users_updating' ).show();

        jQuery( '#mainwp-do-users-bulk-actions' ).attr( 'disabled', 'true' );
        jQuery( '#mainwp_btn_update_user' ).attr( 'disabled', 'true' );

        tmp.each(
            function ( index, elem ) {
                mainwpuser_postAction( elem, 'update_user' );
            }
        );

        return false;
    } );
} );

mainwp_edit_users_box_init = function () {

    jQuery( 'form#update_user_profile select#role option[value="donotupdate"]' ).prop( 'selected', true );
    jQuery( 'form#update_user_profile select#role' ).prop("disabled", false);
    jQuery( 'form#update_user_profile input#first_name' ).val( '' );
    jQuery( 'form#update_user_profile input#last_name' ).val( '' );
    jQuery( 'form#update_user_profile input#nickname' ).val( '' );
    jQuery( 'form#update_user_profile input#email' ).val( '' );
    jQuery( 'form#update_user_profile input#url' ).val( '' );
    jQuery( 'form#update_user_profile select#display_name' ).empty().attr( 'disabled', 'disabled' );
    jQuery( 'form#update_user_profile #description' ).val( '' );
    jQuery( 'form#update_user_profile input#password' ).val( '' );
};

mainwpuser_postAction = function ( elem, what ) {
    var rowElement = jQuery( elem ).parents( 'tr' );
    var userId = rowElement.find( '.userId' ).val();
    var userName = rowElement.find( '.userName' ).val();
    var websiteId = rowElement.find( '.websiteId' ).val();

    var data = mainwp_secure_data( {
        action: 'mainwp_user_' + what,
        userId: userId,
        userName: userName,
        websiteId: websiteId,
        update_password: encodeURIComponent( jQuery('#password').val() ) // to fix
    } );

    if ( what == 'update_user' ) {
        data['user_data'] = jQuery( 'form#update_user_profile' ).serialize();
    }

    rowElement.find( '.row-actions' ).hide();
    rowElement.find( '.row-actions-working' ).show();
    jQuery.post( ajaxurl, data, function ( response ) {
        if ( what == 'edit' && response && response.user_data ) {
            var roles_filter = [ 'administrator', 'subscriber', 'contributor', 'author', 'editor' ];
            var disabled_change_role = false;
            if ( response.user_data.role == '' || jQuery.inArray( response.user_data.role, roles_filter ) === -1 ) {
                jQuery( 'form#update_user_profile select#role option[value="donotupdate"]' ).prop( 'selected', true );
                disabled_change_role = true;
            } else {
                jQuery( 'form#update_user_profile select#role option[value="' + response.user_data.role + '"]' ).prop( 'selected', true );
                if ( response.is_secure_admin ) {
                    disabled_change_role = true;
                }
            }

            if ( disabled_change_role ) {
                jQuery( 'form#update_user_profile select#role' ).attr( 'disabled', 'disabled' );
            } else {
                jQuery( 'form#update_user_profile select#role' ).prop("disabled", false);
            }

            jQuery( 'form#update_user_profile input#first_name' ).val( response.user_data.first_name );
            jQuery( 'form#update_user_profile input#last_name' ).val( response.user_data.last_name );
            jQuery( 'form#update_user_profile input#nickname' ).val( response.user_data.nickname );
            jQuery( 'form#update_user_profile input#email' ).val( response.user_data.user_email );
            jQuery( 'form#update_user_profile input#url' ).val( response.user_data.user_url );
            jQuery( 'form#update_user_profile select#display_name' ).empty();
            jQuery( 'form#update_user_profile select#display_name' ).prop("disabled", false);
            if ( response.user_data.public_display ) {
                jQuery.each( response.user_data.public_display, function ( index, value ) {
                    var o = new Option( value );
                    if ( value == response.user_data.display_name ) {
                        o.selected = true;
                    }
                    jQuery( 'form#update_user_profile select#display_name' ).append( o );
                } );
                jQuery( 'form#update_user_profile select#display_name option[value="' + response.user_data.display_name + '"]' ).prop( 'selected', true );
            }

            jQuery( 'form#update_user_profile #description' ).val( response.user_data.description );
            rowElement.find( 'td.check-column input[type="checkbox"]' )[0].checked = true;
            rowElement.find( '.row-actions-working' ).hide();
            return;
        }

        if ( response.result ) {
            rowElement.html( '<td colspan="8"><i class="check circle icon"></i> ' + response.result + '</td>' );
        } else {
            rowElement.find( '.row-actions-working' ).hide();
        }
        userCountReceived++;
        if ( userCountReceived == userCountSent ) {
            userCountReceived = 0;
            userCountSent = 0;
            jQuery( '#mainwp-do-users-bulk-actions' ).prop("disabled", false);

            jQuery( '#mainwp_btn_update_user' ).prop("disabled", false);
            jQuery( '#mainwp_users_updating' ).hide();


            if ( what == 'update_user' || what == 'delete' ) {
                jQuery( '#mainwp_users_loading_info' ).show();
                //jQuery( '#mainwp-update-users-box' ).hide();
                jQuery( '#mainwp-edit-users-modal' ).modal('hide');
                mainwp_fetch_users();
            }
        }
    }, 'json' );

    return false;
};

// Fetch users from child sites
mainwp_fetch_users = function () {
  var errors = [ ];
  var selected_sites = [ ];
  var selected_groups = [ ];
  var selected_clients = [];

  if ( jQuery( '#select_by' ).val() == 'site' ) {
    jQuery( "input[name='selected_sites[]']:checked" ).each( function () {
      selected_sites.push( jQuery( this ).val() );
    } );
    if ( selected_sites.length == 0 ) {
      errors.push( __( 'Please select at least one website or group or clients.' )  );
    }
  } else if ( jQuery( '#select_by' ).val() == 'client' ) {
    jQuery( "input[name='selected_clients[]']:checked" ).each( function () {
        selected_clients.push( jQuery( this ).val() );
    } );
    if ( selected_clients.length == 0 ) {
      errors.push( __( 'Please select at least one website or group or clients.' )  );
    }
  } else{
    jQuery( "input[name='selected_groups[]']:checked" ).each( function () {
      selected_groups.push( jQuery( this ).val() );
    } );
    if ( selected_groups.length == 0 ) {
      errors.push( __( 'Please select at least one website or group or clients.' ) );
    }
  }

  var role = "";
  var roles = jQuery("#mainwp_user_roles").dropdown("get value");
  if (roles !== null) {
    role = roles.join(',');
  }

  if ( errors.length > 0 ) {
    jQuery( '#mainwp-message-zone' ).html( errors.join( '<br />' ) );
    jQuery( '#mainwp-message-zone' ).show();
    jQuery( '#mainwp-message-zone' ).addClass( 'yellow ');
    jQuery( '#mainwp_users_loading_info' ).hide();
    return;
  } else {
    jQuery( '#mainwp-message-zone' ).html( "" );
    jQuery( '#mainwp-message-zone' ).removeClass( 'yellow ');
    jQuery( '#mainwp-message-zone' ).hide();
  }

  var name = jQuery( '#mainwp_search_users' ).val();

  var data = mainwp_secure_data( {
    action: 'mainwp_users_search',
    role: role,
    search: name,
    'groups[]': selected_groups,
    'sites[]': selected_sites,
    'clients[]': selected_clients
  } );

  jQuery( '#mainwp-loading-users-row' ).show();

  jQuery.post( ajaxurl, data, function ( response ) {
    response = response.trim();
    jQuery( '#mainwp-loading-users-row' ).hide();
    jQuery( '#mainwp_users_loading_info' ).hide();
    jQuery( '#mainwp_users_main' ).show();
    // var matches = ( response == null ? null : response.match( /user\[\]/g ) );
    // jQuery( '#mainwp_users_total' ).html( matches == null ? 0 : matches.length );
    jQuery( '#mainwp_users_wrap_table' ).html( response );
    // re-initialize datatable
    jQuery("#mainwp-users-table").DataTable().destroy();
    jQuery('#mainwp-users-table').DataTable( {
        "responsive" : true,
        "colReorder": {
            fixedColumnsLeft: 1,
            fixedColumnsRight: 1
        },
        "stateSave":  true,
        "pagingType": "full_numbers",
        "order": [],
        "scrollX" : true,
        "lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
        "columnDefs": [ {
            "targets": 'no-sort',
            "orderable": false
        } ],
        "preDrawCallback": function() {
            jQuery( '#mainwp_users_wrap_table table .ui.dropdown' ).dropdown();
            jQuery( '#mainwp_users_wrap_table table .ui.checkbox' ).checkbox();
            mainwp_datatable_fix_menu_overflow();
            mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
        }
    } );
  } );
};




/**
 * Bulk upload new user
 */
var import_user_stop_by_user = false;
var import_user_current_line_number = 0;
var import_user_total_import = 0;
var import_user_count_created_users = 0;
var import_user_count_create_fails = 0;

jQuery( document ).ready( function () {
    import_user_total_import = jQuery( '#import_user_total_import' ).val();

    jQuery( '#import_user_btn_import' ).on( 'click', function () {
        if ( import_user_stop_by_user == false ) {
            import_user_stop_by_user = true;
            jQuery( '#import_user_import_logging .log' ).append( _( 'Paused import by user.' ) + "\n" );
            jQuery( '#import_user_btn_import' ).val( __( 'Continue' ) );
            jQuery( '#MainWPBulkUploadUserLoading' ).hide();
            jQuery( '#import_user_btn_save_csv' ).prop("disabled", false); //Enable
        } else
        {
            import_user_stop_by_user = false;
            jQuery( '#import_user_import_logging .log' ).append( __( 'Continue import.' ) + "\n" );
            jQuery( '#import_user_btn_import' ).val( __( 'Pause' ) );
            jQuery( '#MainWPBulkUploadUserLoading' ).show();
            jQuery( '#import_user_btn_save_csv' ).attr( 'disabled', 'true' ); // Disable
            mainwp_import_users_next();
        }
    } );


    jQuery( '#import_user_btn_save_csv' ).on( 'click', function () {
        var fail_data = '';
        jQuery( '#import_user_import_failed_rows span' ).each(function(){
            fail_data += jQuery(this).html() + "\r\n";
        });
        var blob = new Blob( [ fail_data ], { type: "text/plain;charset=utf-8" } );
        saveAs( blob, "import_users_fails.csv" );
    } );

    if ( jQuery( '#import_user_do_import' ).val() == 1 ) {
        jQuery( '#MainWPBulkUploadUserLoading' ).show();
        mainwp_import_users_next();
    }
} );


mainwp_bulkupload_users = function () {
    if ( jQuery( '#import_user_file_bulkupload' ).val() == '' ) {
        feedback( 'mainwp-message-zone', __( 'Please enter CSV file for upload.' ), 'yellow' );
        jQuery( '#import_user_file_bulkupload' ).parent().parent().addClass( 'form-invalid' );
    } else {
        jQuery( '#createuser' ).submit();
    }
};

mainwp_import_users_next = function () {

    if ( import_user_stop_by_user == true )
        return;

    import_user_current_line_number++;

    if ( import_user_current_line_number > import_user_total_import ) {
        mainwp_import_users_finished();
        return;
    }

    var import_data = jQuery( '#user_import_csv_line_' + import_user_current_line_number ).attr('encoded-data');
    var original_line = jQuery( '#user_import_csv_line_' + import_user_current_line_number ).attr('original-line');

    var decoded_data = false;
	var pos_data = [ ];
    var errors = [ ];

    try {
        decoded_data = JSON.parse( import_data );
    } catch(e) {
		decoded_data = false;
        errors.push( __( 'Invalid import data.' ) );
    }

    if ( false != decoded_data ) {
		jQuery( '#import_user_import_logging .log' ).append( '[' + import_user_current_line_number + '] ' + original_line + '\n');
		var valid = mainwp_import_users_valid_data( decoded_data );
		pos_data = valid.data;
		errors = valid.errors;
    }

    if ( errors.length > 0 ) {
        jQuery( '#import_user_import_failed_rows' ).append( '<span>' + original_line + '</span>' );
        jQuery( '#import_user_import_logging .log' ).append( '[' + import_user_current_line_number + ']>> Error - ' + errors.join( " " ) + '\n' );
        import_user_count_create_fails++;
        mainwp_import_users_next();
        return;
    }

	if ( 0 == pos_data.length ) {
		console.log( 'Error: import user data!' );
		return;
	}

	pos_data.action = 'mainwp_importuser';
	pos_data.line_number = import_user_current_line_number;
	var data = mainwp_secure_data( pos_data );

    //Add user via ajax!!
	jQuery.post( ajaxurl, data, function ( response_data ) {
            if ( response_data.error != undefined )
                return;
			mainwp_import_users_response( response_data );
            mainwp_import_users_next();
    }, 'json' );
};

/* eslint-disable complexity */
mainwp_import_users_valid_data = function( decoded_data ) {

	var errors = []; // array.
	var val_data = {}; // object.

	val_data.user_login = decoded_data.user_login == undefined ? '' : decoded_data.user_login;
	val_data.email = decoded_data.email == undefined ? '' : decoded_data.email;
	val_data.first_name = decoded_data.first_name == undefined ? '' : decoded_data.first_name;
	val_data.last_name = decoded_data.last_name == undefined ? '' : decoded_data.last_name ;
	val_data.url = decoded_data.url == undefined ? '' : decoded_data.url;
	val_data.pass1 = decoded_data.pass1 == undefined ? '' : decoded_data.pass1;
	val_data.send_password = decoded_data.send_password == undefined ? '' : decoded_data.send_password;
	val_data.role = decoded_data.role == undefined ? '' : decoded_data.role;
	val_data.select_sites = decoded_data.select_sites == undefined ? '' : decoded_data.select_sites;
	val_data.select_groups = decoded_data.select_groups == undefined ? '' : decoded_data.select_groups;
	val_data.select_by = '';

	if ( val_data.user_login == '' ) {
		errors.push( __( 'Please enter a username.' ) );
	}

	if ( val_data.email == '' ) {
		errors.push( __( 'Please enter an email.' ) );
	}

	if ( val_data.pass1 == '' ) {
		errors.push( __( 'Please enter a password.' ) );
	}

	var allowed_roles = [ 'subscriber', 'administrator', 'editor', 'author', 'contributor' ];
	if ( jQuery.inArray( val_data.role, allowed_roles ) == -1 ) {
		errors.push( __( 'Please select a data role.' ) );
	}

	if ( val_data.select_sites != '' ) {
		var selected_sites = val_data.select_sites.split( ';' );
		if ( selected_sites.length == 0 ) {
			errors.push( __( 'Please select websites or groups to add a user.' ) );
		} else {
			val_data.select_sites = selected_sites;
			val_data.select_by = 'site';
		}
	} else {
		var selected_groups = val_data.select_groups.split( ';' );
		if ( selected_groups.length == 0 ) {
			errors.push( __( 'Please select websites or groups to add a user.' ) );
		} else {
			val_data.select_groups = selected_groups;
			val_data.select_by = 'group';
		}
	}
	return {
		errors: errors,
		data: val_data
	};

};
/* eslint-enable complexity */

mainwp_import_users_response = function( response_data ) {
		var line_num = response_data.line_number;
		var okList = response_data.ok_list;
		var errorList = response_data.error_list;
		if ( okList != undefined )
			for ( var i = 0; i < okList.length; i++ ) {
				import_user_count_created_users++;
				jQuery( '#import_user_import_logging .log' ).append( '[' + line_num + ']>> ' + okList[i] + '\n' );
			}

		if ( errorList != undefined )
			for ( var i = 0; i < errorList.length; i++ ) {
				import_user_count_create_fails++;
				jQuery( '#import_user_import_logging .log' ).append( '[' + line_num + ']>> ' + errorList[i] + '\n' );
			}

		if ( response_data.failed_logging != '' && response_data.failed_logging != undefined ) {
			jQuery( '#import_user_import_failed_rows' ).append( '<span>' + response_data.failed_logging + '</span>' );
		}
		jQuery( '#import_user_import_logging' ).scrollTop( jQuery( '#import_user_import_logging .log' ).height() );
};

mainwp_import_users_finished = function() {
	jQuery( '#import_user_btn_import' ).val( 'Finished' ).attr( 'disabled', 'true' );
	jQuery( '#MainWPBulkUploadUserLoading' ).hide();
	jQuery( '#import_user_import_logging .log' ).append( '\n' + __( 'Number of users to import: %1 Created users: %2 Failed: %3', import_user_total_import, import_user_count_created_users, import_user_count_create_fails ) + '\n' );
	if ( import_user_count_create_fails > 0 ) {
		jQuery( '#import_user_btn_save_csv' ).prop("disabled", false); //Enable
	}
	jQuery( '#import_user_import_logging' ).scrollTop( jQuery( '#import_user_import_logging .log' ).height() );
}
