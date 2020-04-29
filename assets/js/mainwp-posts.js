
/**
 * MainWP_Page.page
 */
jQuery( document ).ready( function () {
    
// to fix issue not loaded calendar js library
if (jQuery( '.ui.calendar' ).length > 0 ) {
            if (mainwpParams.use_wp_datepicker == 1) {
                jQuery( '.ui.calendar input[type=text]' ).datepicker( { dateFormat: "yy-mm-dd" } );
            } else {
                jQuery( '.ui.calendar' ).calendar({
                        type: 'date',
                        monthFirst: false,
                        formatter: {
                            date: function ( date ) {
                                if (!date) return '';
                                var day = date.getDate();
                                var month = date.getMonth() + 1;
                                var year = date.getFullYear();

                                if (month < 10) {
                                    month = '0' + month;
                                }
                                if (day < 10) {
                                    day = '0' + day;
                                }
                                return year + '-' + month + '-' + day;
                            }
                        }
                });
            }
    }

    jQuery( document ).on( 'click', '#mainwp_show_pages', function () {
        mainwp_fetch_pages();
    } );
    jQuery( document ).on( 'click', '.page_submitpublish', function () {
        mainwppage_postAction( jQuery( this ), 'publish' );
        return false;
    } );
    jQuery( document ).on( 'click', '.page_submitdelete', function () {
        mainwppage_postAction( jQuery( this ), 'trash' );
        return false;
    } );
    jQuery( document ).on( 'click', '.page_submitdelete_perm', function () {
        mainwppage_postAction( jQuery( this ), 'delete' );
        return false;
    } );
    jQuery( document ).on( 'click', '.page_submitrestore', function () {
        mainwppage_postAction( jQuery( this ), 'restore' );
        return false;
    } );
    jQuery( document ).on( 'click', '#mainwp-do-pages-bulk-actions', function () {
        var action = jQuery( '#mainwp-bulk-actions' ).val();
        if ( action == 'none' )
            return false;

        var tmp = jQuery( "input[name='page[]']:checked" );
        countSent = tmp.length;

        if ( countSent == 0 )
            return false;

        var _callback = function() {
                jQuery( '#mainwp-do-pages-bulk-actions' ).attr( 'disabled', 'true' );
                tmp.each(
                    function ( index, elem ) {
                        mainwppage_postAction( elem, action );
                    }
                );
        };

        if ( action == 'delete' ) {
            var msg =  __( 'You are about to delete %1 page(s). Are you sure you want to proceed?', countSent );
            mainwp_confirm(msg, _callback);
            return false;
        }
        _callback();
        return false;
    } );
} );


mainwppage_postAction = function ( elem, what ) {
    var rowElement = jQuery( elem ).parents( 'tr' );
    var pageId = rowElement.find( '.pageId' ).val();
    var websiteId = rowElement.find( '.websiteId' ).val();

    if ( rowElement.find( '.allowedBulkActions' ).val().indexOf( '|' + what + '|' ) == -1 )
    {
        jQuery( elem ).removeAttr( 'checked' );
        countReceived++;

        if ( countReceived == countSent ) {
            countReceived = 0;
            countSent = 0;
            setTimeout( function () {
                jQuery( '#mainwp-do-pages-bulk-actions' ).removeAttr( 'disabled' );
            }, 50 );
        }

        return;
    }

    var data = mainwp_secure_data( {
        action: 'mainwp_page_' + what,
        postId: pageId,
        websiteId: websiteId
    } );

    rowElement.html( '<td colspan="99"><i class="notched circle loading icon"></i> Please wait...</td>' );
    jQuery.post( ajaxurl, data, function ( response ) {
        if ( response.error ) {
            rowElement.html( '<td colspan="99"><i class="times circle red icon"></i>' + response.error + '</td>' );
        } else if ( response.result ) {
            rowElement.html( '<td colspan="99"><i class="check circle green icon"></i> ' + response.result + '</td>' );
        }
        countReceived++;

        if ( countReceived == countSent ) {
            countReceived = 0;
            countSent = 0;
            jQuery( '#mainwp-do-pages-bulk-actions' ).removeAttr( 'disabled' );
        }
    }, 'json' );

    return false;
};

mainwp_fetch_pages = function () {
    var errors = [ ];
    var selected_sites = [ ];
    var selected_groups = [ ];

    if ( jQuery( '#select_by' ).val() == 'site' ) {
        jQuery( "input[name='selected_sites[]']:checked" ).each( function () {
            selected_sites.push( jQuery( this ).val() );
        } );
        if ( selected_sites.length == 0 ) {
            errors.push( '<div class="mainwp-notice mainwp-notice-red">' + __( 'Please select websites or groups.' ) + '</div>' );
        }
    } else {
        jQuery( "input[name='selected_groups[]']:checked" ).each( function () {
            selected_groups.push( jQuery( this ).val() );
        } );
        if ( selected_groups.length == 0 ) {
            errors.push( '<div class="mainwp-notice mainwp-notice-red">' + __( 'Please select websites or groups.' ) + '</div>' );
        }
    }

    var _status = '';
    var statuses = jQuery("#mainwp_page_search_type").dropdown("get value");
    if (statuses == null)
        errors.push( 'Please select a page status.' );
    else {
        _status = statuses.join(',');
    }

    if ( errors.length > 0 ) {
        jQuery( '#mainwp_pages_error' ).html( errors.join( '<br />' ) );
        jQuery( '#mainwp_pages_error' ).show();
        return;
    } else {
        jQuery( '#mainwp_pages_error' ).html( "" );
        jQuery( '#mainwp_pages_error' ).hide();
    }

    var data = mainwp_secure_data( {
        action: 'mainwp_pages_search',
        keyword: jQuery( '#mainwp_page_search_by_keyword' ).val(),
        dtsstart: jQuery( '#mainwp_page_search_by_dtsstart' ).val(),
        dtsstop: jQuery( '#mainwp_page_search_by_dtsstop' ).val(),
        status: _status,
        'groups[]': selected_groups,
        'sites[]': selected_sites,
        maximum: jQuery( "#mainwp_maximumPages" ).val(),
        search_on: jQuery( "#mainwp_page_search_on" ).val(),
    } );

    jQuery( '#mainwp-loading-pages-row' ).show();
    jQuery.post( ajaxurl, data, function ( response ) {
        response = jQuery.trim( response );
        jQuery( '#mainwp-loading-pages-row' ).hide();
        jQuery( '#mainwp_pages_main' ).show();
//        var matches = ( response == null ? null : response.match( /page\[\]/g ) );
//        jQuery( '#mainwp_pages_total' ).html( matches == null ? 0 : matches.length );
        jQuery( '#mainwp_pages_wrap_table' ).html( response );

        // re-initialize datatable
        jQuery("#mainwp-pages-table").DataTable().destroy();
        jQuery('#mainwp-pages-table').DataTable({
            "colReorder" : true,
            "stateSave":  true,
            "pagingType": "full_numbers",
            "scrollX" : true,
            "lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
            "order": [],
            "columnDefs": [ {
                "targets": 'no-sort',
                "orderable": false
            } ]
        });
        mainwp_table_check_columns_init(); // ajax: to fix checkbox all
    } );
};

/**
 * MainWP_Post.page
 */
var countSent = 0;
var countReceived = 0;
jQuery( document ).ready( function () {
    jQuery( document ).on( 'click', '#mainwp_show_posts', function() {
        mainwp_fetch_posts();
    } );
    jQuery( document ).on( 'click', '.post_submitdelete', function() {
        mainwppost_postAction( jQuery( this ), 'trash' );
        return false;
    } );
    jQuery( document ).on( 'click', '.post_submitpublish', function() {
        mainwppost_postAction( jQuery( this ), 'publish' );
        return false;
    } );
    jQuery( document ).on( 'click', '.post_submitunpublish', function() {
        mainwppost_postAction( jQuery( this ), 'unpublish' );
        return false;
    } );
    jQuery( document ).on( 'click', '.post_submitapprove', function() {
        mainwppost_postAction( jQuery( this ), 'approve' );
        return false;
    } );
    jQuery( document ).on( 'click', '.post_submitdelete_perm', function() {
        mainwppost_postAction( jQuery( this ), 'delete' );
        return false;
    } );
    jQuery( document ).on( 'click', '.post_submitrestore', function() {
        mainwppost_postAction( jQuery( this ), 'restore' );
        return false;
    } );

    jQuery( document ).on( 'click', '.post_getedit', function() {
        mainwppost_postAction( jQuery( this ), 'get_edit', 'post' );
        return false;
    } );

    jQuery( document ).on( 'click', '.page_getedit', function() {
        mainwppost_postAction( jQuery( this ), 'get_edit', 'page' );
        return false;
    } );

    jQuery( document ).on( 'click', '#mainwp-do-posts-bulk-actions', function() {
        var action = jQuery( '#mainwp-bulk-actions' ).val();
        console.log(action);

        if ( action == 'none' )
            return false;

        var tmp = jQuery( "input[name='post[]']:checked" );
        countSent = tmp.length;

        if ( countSent == 0 )
            return false;

        var _callback = function() {
                jQuery( '#mainwp-do-posts-bulk-actions' ).attr( 'disabled', 'true' );
                tmp.each(
                    function ( index, elem ) {
                        mainwppost_postAction( elem, action );
                    }
                );
        }
        if ( action == 'delete' ) {
            var msg =   __( 'You are about to delete %1 post(s). Are you sure you want to proceed?', countSent ) ;
            mainwp_confirm(msg, _callback );
            return false;
        }
        _callback();
        return false;
    } );
} );

mainwppost_postAction = function ( elem, what, postType ) {
    var rowElement = jQuery( elem ).parents( 'tr' );
    var postId = rowElement.find( '.postId' ).val();
    var websiteId = rowElement.find( '.websiteId' ).val();
    if ( rowElement.find( '.allowedBulkActions' ).val().indexOf( '|' + what + '|' ) == -1 )
    {
        jQuery( elem ).removeAttr( 'checked' );
        countReceived++;

        if ( countReceived == countSent ) {
            countReceived = 0;
            countSent = 0;
            setTimeout( function () {
                jQuery( '#mainwp-do-posts-bulk-actions' ).removeAttr( 'disabled' );
            }, 50 );
        }

        return;
    }

    if ( what == 'get_edit' && postType === 'page' ) {
        postId = rowElement.find( '.pageId' ).val();
    }

    var data = {
        action: 'mainwp_post_' + what,
        postId: postId,
        websiteId: websiteId
    };
    if ( typeof postType !== "undefined" ) {
        data['postType'] = postType;
    }
    data = mainwp_secure_data( data );

    rowElement.html( '<td colspan="99"><i class="notched circle loading icon"></i> Please wait...</td>' );
    jQuery.post( ajaxurl, data, function ( response ) {
        if ( response.error ) {
            rowElement.html( '<td colspan="99"><i class="times circle red icon"></i>' + response.error + '</td>' );
        } else if ( response.result ) {
            rowElement.html( '<td colspan="99"><i class="check circle green icon"></i> ' + response.result + '</td>' );
        } else {
            rowElement.hide();
            if ( what == 'get_edit' && response.id ) {
                if ( postType == 'post' ) {
                    location.href = 'admin.php?page=PostBulkEdit&post_id=' + response.id;
                } else if ( postType == 'page' ) {
                    location.href = 'admin.php?page=PageBulkEdit&post_id=' + response.id;
                }
            }
        }
        countReceived++;
        if ( countReceived == countSent ) {
            countReceived = 0;
            countSent = 0;
            jQuery( '#mainwp-do-posts-bulk-actions' ).removeAttr( 'disabled' );
        }
    }, 'json' );

    return false;
};

mainwp_show_post = function ( siteId, postId, userId )
{
    var siteElement = jQuery( 'input[name="selected_sites[]"][siteid="' + siteId + '"]' );
    siteElement.prop( 'checked', true );
    siteElement.trigger( "change" );
    mainwp_fetch_posts( postId, userId );
};

mainwp_fetch_posts = function ( postId, userId ) {
    var errors = [ ];
    var selected_sites = [ ];
    var selected_groups = [ ];

    if ( jQuery( '#select_by' ).val() == 'site' ) {
        jQuery( "input[name='selected_sites[]']:checked" ).each( function () {
            selected_sites.push( jQuery( this ).val() );
        } );
        if ( selected_sites.length == 0 ) {
            errors.push( '<div class="ui yellow message">' + __( 'Please select at least one website or group.' ) + '</div>' );
        }
    } else {
        jQuery( "input[name='selected_groups[]']:checked" ).each( function () {
            selected_groups.push( jQuery( this ).val() );
        } );
        if ( selected_groups.length == 0 ) {
            errors.push( '<div class="ui yellow message">' + __( 'Please select at least one website or group.' ) + '</div>' );
        }
    }
    var _status = '';
    var statuses = jQuery("#mainwp_post_search_type").dropdown("get value");
    if (statuses == null)
        errors.push( '<div class="ui yellow message">' + __( 'Please select at least one post status.' ) + '</div>' );
    else {
        _status = statuses.join(',');
    }


    if ( errors.length > 0 ) {
        jQuery( '#mainwp-message-zone' ).html( errors );
        jQuery( '#mainwp-message-zone' ).show();
        return;
    } else {
        jQuery( '#mainwp-message-zone' ).html("");
        jQuery( '#mainwp-message-zone' ).hide();
    }

    var data = mainwp_secure_data( {
        action: 'mainwp_posts_search',
        keyword: jQuery( '#mainwp_post_search_by_keyword' ).val(),
        dtsstart: jQuery( '#mainwp_post_search_by_dtsstart' ).val(),
        dtsstop: jQuery( '#mainwp_post_search_by_dtsstop' ).val(),
        status: _status,
        'groups[]': selected_groups,
        'sites[]': selected_sites,
        postId: ( postId == undefined ? '' : postId ),
        userId: ( userId == undefined ? '' : userId ),
        post_type: jQuery( "#mainwp_get_custom_post_types_select" ).val(),
        maximum: jQuery( "#mainwp_maximumPosts" ).val(),
        search_on: jQuery( "#mainwp_post_search_on" ).val()
    } );

    jQuery( '#mainwp-loading-posts-row' ).show();
    jQuery.post( ajaxurl, data, function ( response ) {
        response = jQuery.trim( response );
        jQuery( '#mainwp-loading-posts-row' ).hide();
        jQuery( '#mainwp_posts_main' ).show();
        jQuery( '#mainwp-posts-table-wrapper' ).empty();
        jQuery( '#mainwp-posts-table-wrapper' ).html( response );
        // re-initialize datatable
        jQuery("#mainwp-posts-table").DataTable().destroy();
        jQuery('#mainwp-posts-table').DataTable({
            "colReorder" : true,
            "stateSave":  true,
            "pagingType": "full_numbers",
            "order": [],
            "scrollX" : true,
            "lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
            "columnDefs": [ {
              "targets": 'no-sort',
              "orderable": false
            } ]
        });
        mainwp_table_check_columns_init(); // ajax: to fix checkbox all
    } );
};
