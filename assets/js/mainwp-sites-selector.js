
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

