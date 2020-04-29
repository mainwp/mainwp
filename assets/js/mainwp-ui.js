
function reload_init()
{
    read_current_url();
    if ( typeof wpOnload == 'function' )
        wpOnload();
    managebackups_init();
    managesites_init();
    // Update form action URL, workaround for browser without History API
    jQuery( '#wpbody-content form' ).each( function () {
        if ( jQuery( this ).attr( 'action' ) == '' )
            jQuery( this ).attr( 'action', mainwp_current_url );
    } );
    stick_element_init();
}

/** AJAX page load **/
var mainwp_current_url = '';
function read_current_url()
{
    mainwp_current_url = document.location.href.replace( /^.*?\/([^/]*?)\/?$/i, '$1' );
    return mainwp_current_url;
}
function load_url( href, obj, e )
{
    var page = href.match( /page=/i ) ? href.replace( /^.*?page=([^&]+).*?$/i, '$1' ) : '';
    if ( page || href == 'index.php' )
    {
        if ( typeof e !== 'undefined' )
            e.preventDefault();
        jQuery( '#wpbody-content' ).html( '<div class="mainwp-loading"><img src="images/loading.gif" /> ' + __( 'Please wait...' ) + '</div>' );
        if ( jQuery( obj ).hasClass( 'menu-top' ) )
        {
            var top = jQuery( obj ).closest( 'li.menu-top' );
            jQuery( '#adminmenu .current' ).removeClass( 'current' ).addClass( 'wp-not-current-submenu' );
            jQuery( '.wp-has-current-submenu' ).removeClass( 'wp-has-current-submenu' ).addClass( 'wp-not-current-submenu' );
            if ( top.hasClass( 'wp-has-submenu' ) )
            {
                top.removeClass( 'wp-not-current-submenu' ).addClass( 'wp-has-current-submenu' );
                jQuery( obj ).removeClass( 'wp-not-current-submenu' ).addClass( 'wp-has-current-submenu' );
            } else
            {
                top.removeClass( 'wp-not-current-submenu' ).addClass( 'current' );
                jQuery( obj ).removeClass( 'wp-not-current-submenu' ).addClass( 'current' );
            }
            top.find( 'li.wp-first-item' ).addClass( 'current' );
        } else
        {
            jQuery( '#adminmenu .current' ).removeClass( 'current' );
            jQuery( obj ).closest( 'li' ).addClass( 'current' );
            var top = jQuery( obj ).closest( 'li.menu-top' );
            if ( top.hasClass( 'wp-not-current-submenu' ) )
            {
                jQuery( '.wp-has-current-submenu' ).removeClass( 'wp-has-current-submenu' ).addClass( 'wp-not-current-submenu' );
                top.removeClass( 'wp-not-current-submenu' ).addClass( 'wp-has-current-submenu' );
            }
            top.find( 'a.menu-top' ).removeClass( 'wp-not-current-submenu' ).addClass( 'wp-has-current-submenu' );
        }
        if ( page )
        {
            jQuery.get( ajaxurl, {
                action: 'mainwp_load_title',
                page: page,
                nonce: mainwp_ajax_nonce
            }, function ( data ) {
                document.title = data;
            } );
            jQuery.get( ajaxurl, {
                action: 'mainwp_load',
                page: page,
                nonce: mainwp_ajax_nonce
            }, function ( data ) {
                pagenow = page;
                data += '<div class="clear"></div>';
                jQuery( '#wpbody-content' ).html( data );
                reload_init();
            } );
        } else
        {
            jQuery.get( ajaxurl, {
                action: 'mainwp_load_dashboard_title',
                nonce: mainwp_ajax_nonce
            }, function ( data ) {
                document.title = data;
            } );
            jQuery.get( ajaxurl, {
                action: 'mainwp_load_dashboard',
                nonce: mainwp_ajax_nonce
            }, function ( data ) {
                pagenow = 'dashboard';
                data += '<div class="clear"></div>';
                jQuery( '#wpbody-content' ).html( data );
                reload_init();
                postboxes.init( pagenow );
                mainwp_ga_getstats();
            } );
        }

    }
}

window.onpopstate = function ( e ) {
    read_current_url();
    if ( e.state )
        load_url( mainwp_current_url, e.state.anchor );    
}




function scroll_element()
{
    var top = jQuery( this ).scrollTop();
    var start = 20;
    jQuery( '.stick-to-window' ).each( function () {
        var init = jQuery( this ).data( 'init-position' );
        if ( top > init.top )
            jQuery( this ).stop().animate( { top: ( top - init.top ) + init.top + start }, 1000 );
        else
            jQuery( this ).stop().css( { top: init.top } );
    } );
}
function stick_element_init()
{
    jQuery( '.stick-to-window' ).each( function () {
        var pos = jQuery( this ).position();
        jQuery( this ).css( {
            position: 'absolute',
            top: pos.top,
            left: pos.left
        } );
        jQuery( this ).data( 'init-position', pos );
    } );
}
function stick_element_reset()
{
    jQuery( '.stick-to-window' ).each( function () {
        jQuery( this ).css( {
            position: 'static',
            top: 0,
            left: 0
        } );
    } );
    stick_element_init();
    scroll_element();
}
jQuery( document ).ready( function () {
    jQuery( window ).scroll( scroll_element ).resize( stick_element_reset );
    stick_element_init();
} );

mainwp_confirm = function( msg, confirmed_callback, cancelled_callback, updateType ) {    // updateType: 1 single update, 2 multi update
    if ( jQuery('#mainwp-disable-update-confirmations').length > 0 ) {            
        var confVal = jQuery('#mainwp-disable-update-confirmations').val();
        if ( typeof updateType !== 'undefined' && (confVal == 2 || (confVal == 1 && updateType == 1 ) ) ) // disable for all update or disable for single updates only
        {
            // do not show confirm box
            if (confirmed_callback && typeof confirmed_callback == 'function')
                confirmed_callback();
            return false;
        }
    }
    
    jQuery('#mainwp-modal-confirm .content-massage').html(msg);
        
    jQuery('#mainwp-modal-confirm').modal({
        onApprove : function() {
            if (confirmed_callback && typeof confirmed_callback == 'function')
                confirmed_callback();
        },
        onDeny() {
            if (cancelled_callback && typeof cancelled_callback == 'function')
                cancelled_callback();
        }
    }).modal('show');
    
    // if it is update confirm and then display the update confirm notice text
    if ( typeof updateType !== 'undefined' && ( updateType == 1 || updateType == 2) ) { 
        jQuery('#mainwp-modal-confirm .update-confirm-notice').show();
    }
    
    return false;
}


/**
 * Select sites
 */
jQuery( document ).ready( function () {
    jQuery( '.mainwp_selected_sites_item input:checkbox' ).on( 'change', function () {
        if ( jQuery( this ).is( ':checked' ) )
            jQuery( this ).parent().addClass( 'selected_sites_item_checked' );
        else
            jQuery( this ).parent().removeClass( 'selected_sites_item_checked' );

        mainwp_site_select( this );
        //mainwp_selected_refresh_count( this );
    } );
    jQuery( '.mainwp_selected_sites_item input:radio' ).on( 'change', function () {
        if ( jQuery( this ).is( ':checked' ) )
        {
            jQuery( this ).parent().addClass( 'selected_sites_item_checked' );
            jQuery( this ).parent().parent().find( '.mainwp_selected_sites_item input:radio:not(:checked)' ).parent().removeClass( 'selected_sites_item_checked' );
        } else
            jQuery( this ).parent().removeClass( 'selected_sites_item_checked' );

        mainwp_site_select( this );
        //mainwp_selected_refresh_count( this );
    } );
    jQuery( '.mainwp_selected_groups_item input:checkbox' ).on( 'change', function () {
        if ( jQuery( this ).is( ':checked' ) )
            jQuery( this ).parent().addClass( 'selected_groups_item_checked' );
        else
            jQuery( this ).parent().removeClass( 'selected_groups_item_checked' );

        //mainwp_selected_refresh_count( this );
    } );
    jQuery( '.mainwp_selected_groups_item input:radio' ).on( 'change', function () {
        if ( jQuery( this ).is( ':checked' ) )
        {
            jQuery( this ).parent().addClass( 'selected_groups_item_checked' );
            jQuery( this ).parent().parent().find( '.mainwp_selected_groups_item input:radio:not(:checked)' ).parent().removeClass( 'selected_groups_item_checked' );
        } else
            jQuery( this ).parent().removeClass( 'selected_groups_item_checked' );
        //mainwp_selected_refresh_count( this );
    } );

} );
mainwp_selected_refresh_count = function ( me )
{
    var parent = jQuery( me ).closest( '.mainwp_select_sites_wrapper' );
    var value = 0;
    if ( parent.find( '#select_by' ).val() == 'site' )
    {
        value = parent.find( '.selected_sites_item_checked' ).length;
    } else
    {
        value = parent.find( '.selected_groups_item_checked' ).length;
    }
    parent.find( '.mainwp_sites_selectcount' ).html( value );
};

mainwp_site_select = function () {
    mainwp_newpost_updateCategories();
};
mainwp_group_select = function () {
    mainwp_newpost_updateCategories();
};

mainwp_ss_select = function ( me, val ) {
    var parent = jQuery( me ).closest( '.mainwp_select_sites_wrapper' );
    var tab = parent.find( '#select_sites_tab' ).val();
    if ( tab == 'site' ) {
      parent.find( '#mainwp-select-sites-list .item:not(.no-select) INPUT:enabled:checkbox' ).attr( 'checked', val ).change();
    } else if ( tab == 'staging' ) {
      parent.find( '#mainwp-select-staging-sites-list .item:not(.no-select) INPUT:enabled:checkbox' ).attr( 'checked', val ).change();
    } else { //group
      parent.find( '#mainwp-select-groups-list .item:not(.no-select) INPUT:enabled:checkbox' ).attr( 'checked', val ).change();
    }
    mainwp_newpost_updateCategories();
    return false;
};

mainwp_sites_selection_onvisible_callback = function ( me ) {
    var selected_tab = jQuery(me).attr('select-by');
    var select_by = 'site';

    var parent = jQuery( me ).closest( '.mainwp_select_sites_wrapper' );
    if (selected_tab == 'staging') {
        // uncheck live sites
        parent.find( '#mainwp-select-sites-list INPUT:checkbox' ).attr( 'checked', false );
        parent.find( '#mainwp-select-groups-list INPUT:checkbox' ).attr( 'checked', false );
    } else if (selected_tab == 'site') {
        // uncheck staging sites
        parent.find( '#mainwp-select-staging-sites-list INPUT:checkbox' ).attr( 'checked', false );
        parent.find( '#mainwp-select-groups-list INPUT:checkbox' ).attr( 'checked', false );
    } else if (selected_tab == 'group') {
        // uncheck sites
        parent.find( '#mainwp-select-sites-list INPUT:checkbox' ).attr( 'checked', false );
        parent.find( '#mainwp-select-staging-sites-list INPUT:checkbox' ).attr( 'checked', false );
        select_by = 'group';
    }

    console.log('select by: ' + select_by );

    parent.find( '#select_by' ).val( select_by );
    parent.find( '#select_sites_tab' ).val( selected_tab );
    //mainwp_selected_refresh_count( me );
}


var executingUpdateCategories = false;
var queueUpdateCategories = 0;
mainwp_newpost_updateCategories = function ()
{
    if ( executingUpdateCategories )
    {
        queueUpdateCategories++;
        return;
    }
    executingUpdateCategories = true;
    console.log('mainwp_newpost_updateCategories');
    var catsSelection = jQuery( '#categorychecklist' );
    if ( catsSelection.length > 0 )
    {
        var tab = jQuery( '#select_sites_tab' ).val();
        var sites = [ ];
        var groups = [ ];
        if ( tab == 'site' ) {
            sites = jQuery.map( jQuery( '#mainwp-select-sites-list INPUT:checkbox:checked' ), function ( el ) {
                return jQuery( el ).val();
            } );
        } else if ( tab == 'staging') {
            sites = jQuery.map( jQuery( '#mainwp-select-staging-sites-list INPUT:checkbox:checked' ), function ( el ) {
                return jQuery( el ).val();
            } );
        } else { //group
            groups = jQuery.map( jQuery( '#mainwp-select-staging-sites-list INPUT:checkbox:checked' ), function ( el ) {
                return jQuery( el ).val();
            } );
        }

        var selected_categories = catsSelection.dropdown('get value');

        var data = mainwp_secure_data( {
            action: 'mainwp_get_categories',
            sites: encodeURIComponent( sites.join( ',' ) ),
            groups: encodeURIComponent( groups.join( ',' ) ),
            selected_categories: selected_categories ? encodeURIComponent( selected_categories.join( ',' ) ) : '',
            post_id: jQuery( '#post_ID' ).val()
        } );

        jQuery.post( ajaxurl, data, function ( pSelectedCategories ) {
            return function ( response ) {
                response = jQuery.trim( response );
                catsSelection.dropdown('remove selected');
                catsSelection.find('.sitecategory').remove();
                catsSelection.append( response );
                catsSelection.dropdown('set selected', pSelectedCategories);
                updateCategoriesPostFunc();
            };
        }( selected_categories ) );
    } else
    {
        updateCategoriesPostFunc();
    }
};
updateCategoriesPostFunc = function ()
{
    if ( queueUpdateCategories > 0 )
    {
        queueUpdateCategories--;
        executingUpdateCategories = false;
        mainwp_newpost_updateCategories();
    } else
    {
        executingUpdateCategories = false;
    }
};

jQuery( document ).on( 'keydown', 'form[name="post"]', function ( event ) {
    if ( event.keyCode == 13 && event.srcElement.tagName.toLowerCase() == "input" )
    {
        event.preventDefault();
    }
} );
jQuery( document ).on( 'keyup', '#mainwp-select-sites-filter', function () {
    var filter = jQuery(this).val().toLowerCase();
    var parent = jQuery( this ).closest( '.mainwp_select_sites_wrapper' );
    var tab = jQuery( '#select_sites_tab' ).val();
    var siteItems = [];

    if (tab == 'group') {
      siteItems = parent.find( '.mainwp_selected_groups_item' );
    } else if (tab == 'site' || tab == 'staging') {
      siteItems =  parent.find( '.mainwp_selected_sites_item' );
    }

    for ( var i = 0; i < siteItems.length; i++ ) {
        var currentElement = jQuery( siteItems[i] );
        var value = currentElement.find('label').text().toLowerCase();
        if ( value.indexOf( filter ) > -1 ) {
            currentElement.removeClass( 'no-select' ).show();
        } else {
            currentElement.addClass( 'no-select' ).hide();
        }
    }
    if (tab == 'site' || tab == 'staging') {
      mainwp_newpost_updateCategories();
    }
} );


