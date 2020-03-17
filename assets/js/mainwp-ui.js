
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
    mainwp_current_url = document.location.href.replace( /^.*?\/([^\/]*?)\/?$/i, '$1' );
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

function mapGetSelector()
{
    var el_class = jQuery( this ).attr( 'class' );
    var el_id = jQuery( this ).attr( 'id' );
    return el_selector = this.tagName + ( el_id ? '#' + el_id : '' ) +
        ( !el_id && el_class ? '.' + el_class.match( /^\S+/ ) : '' );
}
window.onpopstate = function ( e ) {
    read_current_url();
    if ( e.state )
        load_url( mainwp_current_url, e.state.anchor );
    //alert(mainwp_current_url);
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
