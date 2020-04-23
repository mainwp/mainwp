
//Ignore plugin
jQuery( document ).ready( function () {
    jQuery( document ).on( 'click', 'input[name="plugins"]', function ()
    {
        if ( jQuery( this ).is( ':checked' ) )
        {
            jQuery( 'input[name="plugins"]' ).attr( 'checked', 'checked' );
            jQuery( 'input[name="plugin[]"]' ).attr( 'checked', 'checked' );
        } else
        {
            jQuery( 'input[name="plugins"]' ).removeAttr( 'checked' );
            jQuery( 'input[name="plugin[]"]' ).removeAttr( 'checked' );
        }
    } );
    jQuery( document ).on( 'click', 'input[name="themes"]', function ()
    {
        if ( jQuery( this ).is( ':checked' ) )
        {
            jQuery( 'input[name="themes"]' ).attr( 'checked', 'checked' );
            jQuery( 'input[name="theme[]"]' ).attr( 'checked', 'checked' );
        } else
        {
            jQuery( 'input[name="themes"]' ).removeAttr( 'checked' );
            jQuery( 'input[name="theme[]"]' ).removeAttr( 'checked' );
        }
    } );

  jQuery( document ).on( 'click', '#mainwp-bulk-trust-plugins-action-apply', function () {

    var action = jQuery( '#mainwp-bulk-actions' ).find( '.item.selected' ).attr( 'value' );

        if ( action == 'none' )
            return false;

        var slugs = jQuery.map( jQuery( "input[name='plugin[]']:checked" ), function ( el ) {
            return jQuery( el ).val();
        } );

        if ( slugs.length == 0 )
            return false;

        jQuery( '#mainwp-bulk-trust-plugins-action-apply' ).attr( 'disabled', 'true' );

        var data = mainwp_secure_data( {
            action: 'mainwp_trust_plugin',
            slugs: slugs,
            do: action
        } );

        jQuery.post( ajaxurl, data, function () {
            jQuery( '#mainwp-bulk-trust-plugins-action-apply' ).removeAttr( 'disabled' );
            mainwp_fetch_all_active_plugins();
        }, 'json' );

        return false;
    } );
    jQuery( document ).on( 'click', '#mainwp-bulk-trust-themes-action-apply', function () {
        var action = jQuery("#mainwp-bulk-actions").dropdown( "get value" );
        if ( action == 'none' )
            return false;

        var slugs = jQuery.map( jQuery( "input[name='theme[]']:checked" ), function ( el ) {
            return jQuery( el ).val();
        } );
        if ( slugs.length == 0 )
            return false;

        jQuery( '#mainwp-bulk-trust-themes-action-apply' ).attr( 'disabled', 'true' );

        var data = mainwp_secure_data( {
            action: 'mainwp_trust_theme',
            slugs: slugs,
            do: action
        } );

        jQuery.post( ajaxurl, data, function () {
            jQuery( '#mainwp-bulk-trust-themes-action-apply' ).removeAttr( 'disabled' );
            mainwp_fetch_all_themes();
        }, 'json' );

        return false;
    } );
} );

updatesoverview_ignore_plugintheme_by_site = function ( what, slug, name, id, pObj ) {
    var data = mainwp_secure_data( {
        action: 'mainwp_ignoreplugintheme',
        type: what,
        id: id,
        slug: slug,
        name: name
    } );

    jQuery.post( ajaxurl, data, function ( response ) {
        var parent = jQuery(pObj).closest('tr');
        if ( response.result ) {
            jQuery( 'div[' + what + '_slug="' + slug + '"] div[site_id="' + id + '"]' ).attr( 'updated', '-1' ); // ok
            parent.attr( 'updated', '-1' );
            parent.find('td:last-child').html(__( 'Ignored' ));
        } else
        {
            parent.find('td:last-child').html( getErrorMessage( response.error ) );
        }
    }, 'json' );
    return false;
};

// Unignore Plugin / Themse ignored per site
updatesoverview_unignore_plugintheme_by_site = function ( what, slug, id ) {
    var data = mainwp_secure_data( {
        action: 'mainwp_unignoreplugintheme',
        type: what,
        id: id,
        slug: slug
    } );

    jQuery.post( ajaxurl, data, function ( pWhat, pSlug, pId ) {
        return function ( response ) {
            if ( response.result ) {
                var siteElement;
        if ( pWhat == 'plugin' ) {
          siteElement = jQuery( 'tr[site-id="' + pId + '"][plugin-slug="' + pSlug + '"]' );
        } else {
          siteElement = jQuery( 'tr[site-id="' + pId + '"][theme-slug="' + pSlug + '"]' );
                }

        if ( !siteElement.find( 'div' ).is( ':visible' ) ) {
                    siteElement.remove();
                    return;
                }

                //Check if previous tr is same site..
                //Check if next tr is same site..
                var siteAfter = siteElement.next();
        if ( siteAfter.exists() && ( siteAfter.attr( 'site-id' ) == pId ) )   {
          siteAfter.find( 'div' ).show();
                    siteElement.remove();
                    return;
                }

                var parent = siteElement.parent();
                siteElement.remove();
                if ( parent.children( 'tr' ).size() == 0 ) {
          parent.append( '<tr><td colspan="999">' + __( 'No ignored %1s', pWhat ) + '</td></tr>' );
          jQuery( '.mainwp-unignore-detail-all' ).addClass( 'disabled' );
                }
            }
        }
    }( what, slug, id ), 'json' );
    return false;
};

// Unignore all Plugins / Themses ignored per site
updatesoverview_unignore_plugintheme_by_site_all = function ( what ) {
    var data = mainwp_secure_data( {
        action: 'mainwp_unignoreplugintheme',
        type: what,
        id: '_ALL_',
        slug: '_ALL_'
    } );

    jQuery.post( ajaxurl, data, function ( pWhat ) {
        return function ( response ) {
            if ( response.result ) {
                var tableElement = jQuery( '#ignored-' + pWhat + 's-list' );
                tableElement.find( 'tr' ).remove();
                tableElement.append( '<tr><td colspan="999">' + __( 'No ignored %1s', pWhat ) + '</td></tr>' );
                jQuery( '.mainwp-unignore-detail-all' ).addClass( 'disabled' );
            }
        }
    }( what ), 'json' );
    return false;
};

/**Plugins part**/
updatesoverview_translations_detail = function ( slug ) {
    jQuery( 'div[translation_slug="' + slug + '"]' ).toggle( 100, 'linear' );
    return false;
};

updatesoverview_plugins_ignore_detail = function ( slug, name, id, obj ) {
  var msg = __( 'Are you sure you want to ignore the %1 plugin updates? The updates will no longer be visible in your MainWP Dashboard.', name );
  mainwp_confirm(msg, function(){
      return updatesoverview_ignore_plugintheme_by_site( 'plugin', slug, name, id, obj );
  }, false, 1 );
  return false;
};
updatesoverview_plugins_unignore_detail = function ( slug, id ) {
    return updatesoverview_unignore_plugintheme_by_site( 'plugin', slug, id );
};
updatesoverview_plugins_unignore_detail_all = function () {
    return updatesoverview_unignore_plugintheme_by_site_all( 'plugin' );
};
updatesoverview_themes_ignore_detail = function ( slug, name, id, obj ) {
    var msg = __( 'Are you sure you want to ignore the %1 theme updates? The updates will no longer be visible in your MainWP Dashboard.', name );
    mainwp_confirm(msg, function(){
        return updatesoverview_ignore_plugintheme_by_site( 'theme', slug, name, id, obj );
    }, false, 1);
    return false;
};
updatesoverview_themes_unignore_detail = function ( slug, id ) {
    return updatesoverview_unignore_plugintheme_by_site( 'theme', slug, id );
};
updatesoverview_themes_unignore_detail_all = function () {
    return updatesoverview_unignore_plugintheme_by_site_all( 'theme' );
};
updatesoverview_plugins_ignore_all = function ( slug, name, obj) {
    var msg = __( 'Are you sure you want to ignore the %1 plugin updates? The updates will no longer be visible in your MainWP Dashboard.', name );
    mainwp_confirm(msg, function(){
        var data = mainwp_secure_data( {
            action: 'mainwp_ignorepluginsthemes',
            type: 'plugin',
            slug: slug,
            name: name
        } );
        var parent = jQuery(obj).closest('tr');
        parent.find('td:last-child').html( __('Ignoring...') );
        jQuery.post( ajaxurl, data, function ( response ) {
            if ( response.result ) {
                parent.find('td:last-child').html( __('Ignored') );
                jQuery( 'tr[plugin_slug="' + slug + '"]' ).find( 'table tr td:last-child' ).html( __( 'Ignored' ) );
                jQuery( 'tr[plugin_slug="' + slug + '"]' ).find( 'table tr' ).attr( 'updated', '-1' );
            }
        }, 'json' );
    }, false, 1 );
    return false;
};

// Unignore all globally ignored plugins
updatesoverview_plugins_unignore_globally_all = function () {
    var data = mainwp_secure_data( {
        action: 'mainwp_unignorepluginsthemes',
        type: 'plugin',
        slug: '_ALL_'
    } );

    jQuery.post( ajaxurl, data, function ( response ) {
        if ( response.result ) {
            var tableElement = jQuery( '#globally-ignored-plugins-list' );
            tableElement.find( 'tr' ).remove();
      jQuery( '#mainwp-unignore-globally-all' ).addClass( 'disabled' );
      tableElement.append( '<tr><td colspan="999">' + __( 'No ignored plugins.' ) + '</td></tr>' );
        }
    }, 'json' );
    return false;
};

// Unignore globally ignored plugin
updatesoverview_plugins_unignore_globally = function ( slug ) {
    var data = mainwp_secure_data( {
        action: 'mainwp_unignorepluginsthemes',
        type: 'plugin',
        slug: slug
    } );
    jQuery.post( ajaxurl, data, function ( response ) {
        if ( response.result ) {
      var ignoreElement = jQuery( '#globally-ignored-plugins-list tr[plugin-slug="' + slug + '"]' );
            var parent = ignoreElement.parent();
            ignoreElement.remove();
            if ( parent.children( 'tr' ).size() == 0 ) {
        jQuery( '#mainwp-unignore-globally-all' ).addClass( 'disabled' );
        parent.append( '<tr><td colspan="999">' + __( 'No ignored plugins.' ) + '</td></tr>' );
            }
        }
    }, 'json' );
    return false;
};

updatesoverview_themes_ignore_all = function ( slug, name, obj ) {
    var msg =  __( 'Are you sure you want to ignore the %1 theme updates? The updates will no longer be visible in your MainWP Dashboard.', name );
    mainwp_confirm(msg, function(){

        var data = mainwp_secure_data( {
            action: 'mainwp_ignorepluginsthemes',
            type: 'theme',
            slug: slug,
            name: name
        } );
        var parent = jQuery(obj).closest('tr');
        parent.find('td:last-child').html( __('Ignoring...') );
        jQuery.post( ajaxurl, data, function ( response ) {
            if ( response.result ) {
                parent.find('td:last-child').html( __('Ignored') );
                jQuery( 'tr[theme_slug="' + slug + '"]' ).find('table tr td:last-child').html( __( 'Ignored' ) );
                jQuery( 'tr[theme_slug="' + slug + '"]' ).find('table tr').attr( 'updated', '-1' );
            }
        }, 'json' );
    }, false, 1);
    return false;
};

// Unignore all globally ignored themes
updatesoverview_themes_unignore_globally_all = function () {
    var data = mainwp_secure_data( {
        action: 'mainwp_unignorepluginsthemes',
        type: 'theme',
        slug: '_ALL_'
    } );

    jQuery.post( ajaxurl, data, function ( response ) {
        if ( response.result ) {
            var tableElement = jQuery( '#globally-ignored-themes-list' );
            tableElement.find( 'tr' ).remove();
      jQuery( '#mainwp-unignore-globally-all' ).addClass( 'disabled' );
      tableElement.append( '<tr><td colspan="999">' + __( 'No ignored themes.' ) + '</td></tr>' );
        }
    }, 'json' );

    return false;
};

// Unignore globally ignored theme
updatesoverview_themes_unignore_globally = function ( slug ) {
    var data = mainwp_secure_data( {
        action: 'mainwp_unignorepluginsthemes',
        type: 'theme',
        slug: slug
    } );
    jQuery.post( ajaxurl, data, function ( response ) {
        if ( response.result ) {
      var ignoreElement = jQuery( '#globally-ignored-themes-list tr[theme-slug="' + slug + '"]' );
            var parent = ignoreElement.parent();
            ignoreElement.remove();
      if ( parent.children( 'tr' ).size() == 0 ){
        jQuery( '#mainwp-unignore-globally-all' ).addClass( 'disabled' );
        parent.append( '<tr><td colspan="999">' + __( 'No ignored themes.' ) + '</td></tr>' );
            }
        }
    }, 'json' );
    return false;
};

updatesoverview_upgrade_translation = function ( id, slug ) {
    var msg =  __( 'Are you sure you want to update the translation on the selected site?' );
    mainwp_confirm(msg, function(){
        return updatesoverview_translations_upgrade_int( slug, id );
    }, false, 1 );
};

updatesoverview_translations_upgrade = function ( slug, websiteid ) {
    var msg =  __( 'Are you sure you want to update the translation on the selected site?' );
    mainwp_confirm(msg, function(){
        return updatesoverview_translations_upgrade_int( slug, websiteid );
    }, false, 1 );
};

updatesoverview_plugins_upgrade = function ( slug, websiteid ) {
    var msg =  __( 'Are you sure you want to update the plugin on the selected site?' );
    mainwp_confirm(msg, function(){
        return updatesoverview_plugins_upgrade_int( slug, websiteid );
    }, false, 1 );
};

updatesoverview_themes_upgrade = function ( slug, websiteid ) {
    var msg =  __( 'Are you sure you want to update the theme on the selected site?' );
    mainwp_confirm(msg, function(){
        return updatesoverview_themes_upgrade_int( slug, websiteid );
    }, false, 1 );
};

/** END NEW **/

updatesoverview_wp_sync = function ( websiteid ) {
    var syncIds = [ ];
    syncIds.push( websiteid );
    mainwp_sync_sites_data( syncIds );
    return false;
};


updatesoverview_group_upgrade_translation = function ( id, slug, groupId ) {
    return updatesoverview_upgrade_plugintheme( 'translation', id, slug, groupId );
};

updatesoverview_upgrade_translation_all = function ( id ) {
    var msg =  __( 'Are you sure you want to update all translations?' );
    mainwp_confirm(msg, function(){
        return updatesoverview_upgrade_plugintheme_all( 'translation', id );
    }, false, 2 );
    return false;
};

updatesoverview_group_upgrade_translation_all = function ( id, groupId ) {
    var msg = __( 'Are you sure you want to update all translations?' );
    mainwp_confirm(msg, function(){
        return updatesoverview_group_upgrade_plugintheme_all( 'translation', id, false, groupId );
    }, false, 2 );
    return false;
};

updatesoverview_upgrade_plugin = function ( id, slug ) {
    var msg =  __( 'Are you sure you want to update the plugin on the selected site?' );
    mainwp_confirm(msg, function(){
        return updatesoverview_upgrade_plugintheme( 'plugin', id, slug );
    }, false, 1 );
};

updatesoverview_group_upgrade_plugin = function ( id, slug, groupId ) {
    return updatesoverview_upgrade_plugintheme( 'plugin', id, slug, groupId );
};

updatesoverview_upgrade_plugin_all = function ( id ) {
    var msg = __( 'Are you sure you want to update all plugins?' );
    mainwp_confirm(msg, function(){
          return updatesoverview_upgrade_plugintheme_all( 'plugin', id );
    }, false, 2 );
    return false;
};

updatesoverview_group_upgrade_plugin_all = function ( id, groupId ) {
    var msg = __( 'Are you sure you want to update all plugins?' );
    mainwp_confirm(msg, function(){
        return updatesoverview_group_upgrade_plugintheme_all( 'plugin', id, false, groupId );
    }, false, 2);
    return false;
};

updatesoverview_upgrade_theme = function ( id, slug ) {
    var msg =  __( 'Are you sure you want to update the theme on the selected site?' );
    mainwp_confirm(msg, function(){
        return updatesoverview_upgrade_plugintheme( 'theme', id, slug );
    }, false, 1);
};
updatesoverview_group_upgrade_theme = function ( id, slug, groupId ) {
    return updatesoverview_upgrade_plugintheme( 'theme', id, slug, groupId );
};

updatesoverview_upgrade_theme_all = function ( id ) {
    var msg = __( 'Are you sure you want to update all themes?' );
    mainwp_confirm(msg, function(){
        return updatesoverview_upgrade_plugintheme_all( 'theme', id );
    }, false, 2);
    return false;
};

updatesoverview_group_upgrade_theme_all = function ( id, groupId ) {
    var msg = __( 'Are you sure you want to update all themes?' );
    mainwp_confirm(msg, function(){
        return updatesoverview_group_upgrade_plugintheme_all( 'theme', id, false, groupId );
    }, false, 2);
    return false;
};

updatesoverview_upgrade_plugintheme = function ( what, id, name, groupId ) {
    updatesoverview_upgrade_plugintheme_list( what, id, [ name ], false, groupId );
    return false;
};
updatesoverview_upgrade_plugintheme_all = function ( what, id, noCheck ) {
    // ok: confirmed to do this
    updatesoverviewContinueAfterBackup = function ( pId, pWhat ) {
        return function ()
        {
            var list = [ ];
            var slug_att = pWhat + '_slug';
            jQuery( "#wp_" + pWhat + "_upgrades_" + pId + " tr[updated=0]" ).each( function () {
                var slug = jQuery(this).attr( slug_att );
                if ( slug ) {
                    list.push( slug );
                }
            } );

            var siteName = jQuery( "#wp_" + pWhat + "_upgrades_" + pId ).attr('site_name');
            updatesoverview_upgrade_plugintheme_list_popup(pWhat, pId, siteName, list);
            // proccessed by popup
            //updatesoverview_upgrade_plugintheme_list( what, pId, list, true );
        }
    }( id, what );

    if ( noCheck )
    {
        updatesoverviewContinueAfterBackup();
        return false;
    }

    var sitesToUpdate = [ ];
    var siteNames = [ ];

    sitesToUpdate.push( id );
    siteNames[id] = jQuery( 'tbody[site_id="' + id + '"]' ).attr( 'site_name' );

    return mainwp_updatesoverview_checkBackups( sitesToUpdate, siteNames );
};

updatesoverview_group_upgrade_plugintheme_all = function ( what, id, noCheck, groupId ) {
    // ok, confirmed to do this
    updatesoverviewContinueAfterBackup = function ( pId, pWhat ) {
        return function ()
        {
            var list = [ ];
            var slug_att = pWhat + '_slug';
            jQuery( "#wp_" + pWhat + "_upgrades_" + pId + '_group_' + groupId + " tr[updated=0]" ).each( function () {
                var slug = jQuery(this).attr( slug_att );
                if ( slug ) {
                    list.push( slug );
                }
            } );

            // proccessed by popup
            //updatesoverview_upgrade_plugintheme_list( what, pId, list, true, groupId );
            var siteName = jQuery( "#wp_" + pWhat + "_upgrades_" + pId + '_group_' + groupId).attr('site_name');
            updatesoverview_upgrade_plugintheme_list_popup(what, pId, siteName, list );
        }
    }( id, what );

    if ( noCheck )
    {
        updatesoverviewContinueAfterBackup();
        return false;
    }

    var sitesToUpdate = [ ];
    var siteNames = [ ];

    sitesToUpdate.push( id );
    siteNames[id] = jQuery( 'tbody[site_id="' + id + '"]' ).attr( 'site_name' );

    return mainwp_updatesoverview_checkBackups( sitesToUpdate, siteNames );
};

updatesoverview_upgrade_plugintheme_list = function ( what, id, list, noCheck, groupId )
{
    updatesoverviewContinueAfterBackup = function ( pWhat, pId, pList, pGroupId ) {
        return function ()
        {
            var strGroup = '';
            if ( typeof pGroupId !== 'undefined' ) {
                strGroup = '_group_' + pGroupId;
            }
            var newList = [ ];

            for ( var i = pList.length - 1; i >= 0; i-- ) {
                var item = pList[i];
                var elem = document.getElementById( 'wp_upgraded_' + pWhat + '_' + pId + strGroup + '_' + item );
                if ( elem && elem.value == 0 ) {
                    var parent = jQuery(elem).closest('tr');
                    parent.find( 'td:last-child' ).html( '<i class="notched circle loading icon"></i> ' + __( 'Updating. Please wait...' ) );
                    elem.value = 1;
                    parent.attr('updated', 1);
                    newList.push( item );
                }
            }

            var dateObj = new Date();
            starttimeDashboardAction = dateObj.getTime();
            if (pWhat == 'plugin')
                dashboardActionName = 'upgrade_all_plugins';
            else if (pWhat == 'translation')
                dashboardActionName = 'upgrade_all_translations';
            else
                dashboardActionName = 'upgrade_all_themes';            
            countRealItemsUpdated = 0;
            couttItemsToUpdate = 0;
            
            if ( newList.length > 0 ) {
                var data = mainwp_secure_data( {
                    action: 'mainwp_upgradeplugintheme',
                    websiteId: pId,
                    type: pWhat,
                    slug: newList.join( ',' )
                } );
                jQuery.post( ajaxurl, data, function ( response ) {
                    var success = false;
                    if ( response.error ) {
                        console.log( response.error );
                    }
                    else
                    {
                        var res = response.result;
                        for ( var i = 0; i < newList.length; i++ ) {
                            var item = newList[i];

                            couttItemsToUpdate++;
                            var elem = document.getElementById( 'wp_upgraded_' + pWhat + '_' + pId + strGroup + '_' + item );
                            var parent = jQuery(elem).closest('tr');
                            if ( res[item] ) {
                                parent.find( 'td:last-child' ).html( '<i class="green check icon"></i>');
                                countRealItemsUpdated++;
                            } else {
                                parent.find( 'td:last-child' ).html( '<i class="red times icon"></i>' );
                            }
                        }
                        success = true;

                        if (mainwpParams.enabledTwit == true) {
                            var dateObj = new Date();
                            var countSec = (dateObj.getTime() - starttimeDashboardAction) / 1000;
                            jQuery('#bulk_install_info').html('<i class="fa fa-spinner fa-pulse"></i>');
                            if (countSec <= mainwpParams.maxSecondsTwit) {
                                var data = mainwp_secure_data( {
                                    action: 'mainwp_twitter_dashboard_action',
                                    actionName: dashboardActionName,
                                    countSites: 1,
                                    countSeconds: countSec,
                                    countItems: couttItemsToUpdate,
                                    countRealItems: countRealItemsUpdated,
                                    showNotice: 1
                                } );
                                jQuery.post(ajaxurl, data, function (res) {
                                    if (res && res != '') {
                                        jQuery('#mainwp-dashboard-info-box').html(res);
                                        if (typeof twttr !== "undefined")
                                            twttr.widgets.load();
                                    } else {
                                        jQuery('#mainwp-dashboard-info-box').html('');
                                    }
                                });
                            }
                        }
                    }
                    if ( !success ) {
                        for ( var i = 0; i < newList.length; i++ ) {
                            var item = newList[i];
                            var elem = document.getElementById( 'wp_upgraded_' + pWhat + '_' + pId + strGroup + '_' + item );
                            var parent = jQuery(elem).closest('tr');
                            //document.getElementById( 'wp_upgrade_' + pWhat + '_' + pId + strGroup + '_' + item ).innerHTML = result;
                            parent.find( 'td:last-child' ).html( '<i class="red times icon"></i>' );
                        }
                    }
                }, 'json' );

            }

            updatesoverviewContinueAfterBackup = undefined;
        }
    }( what, id, list, groupId );

    if ( noCheck )
    {
        updatesoverviewContinueAfterBackup();
        return false;
    }

    var sitesToUpdate = [ id ];
    var siteNames = [ ];
    siteNames[id] = jQuery( 'tbody[site_id="' + id + '"]' ).attr( 'site_name' );
    return mainwp_updatesoverview_checkBackups( sitesToUpdate, siteNames );
};

updatesoverview_upgrade_plugintheme_list_popup  = function ( what, pId, pSiteName, list)
{
    var updateCount = list.length;
    if ( updateCount == 0 )
        return;

    var updateWhat = (what == 'plugin') ? __('plugins') : ( what == 'theme' ? __('themes') : __('translations'));

    mainwpPopup( '#mainwp-sync-sites-modal' ).clearList();
    mainwpPopup( '#mainwp-sync-sites-modal' ).appendItemsList( decodeURIComponent( pSiteName ) + ' (' + updateCount + ' ' + updateWhat + ')', '<span class="updatesoverview-upgrade-status-wp" siteid="' + pId + '">' + '<i class="clock outline icon"></i> ' + '</span>' );

    var initData = {
        title: __( 'Updating all' ),
        total: 1, // update for one site
        pMax: 1
    };
    updatesoverview_update_popup_init( initData );
    var data = mainwp_secure_data( {
        action: 'mainwp_upgradeplugintheme',
        websiteId: pId,
        type: what,
        slug: list.join( ',' )
    } );

    updatesoverview_plugins_upgrade_all_update_site_status( pId, '<i class="sync loading icon"></i>' );

    jQuery.post( ajaxurl, data, function ( response ) {
        if ( response.error ) {
            updatesoverview_plugins_upgrade_all_update_site_status( pId, '<i class="red times icon"></i>' );
        }
        else
        {
            updatesoverview_plugins_upgrade_all_update_site_status( pId, '<i class="green check icon"></i>' + ' ' + mainwp_links_visit_site_and_admin('', pId) );
        }
        mainwpPopup( '#mainwp-sync-sites-modal' ).setProgressValue( 1 );
        setTimeout( function ()
        {
            mainwpPopup( '#mainwp-sync-sites-modal' ).close(true);
        }, 3000 );
    });


}



/**
 * MainWP_Plugins.page
 */
jQuery( document ).ready( function () {
    jQuery( document ).on( 'click', '#mainwp-show-plugins', function () {
        mainwp_fetch_plugins();
    } );
    jQuery( document ).on( 'change', '.mainwp_plugin_check_all', function() {
            jQuery( ".mainwp-selected-plugin[value='" + jQuery( this ).val() + "'][version='" + jQuery( this ).attr( 'version' ) + "']" ).prop( 'checked', jQuery( this ).prop( 'checked' ) );
    });


    jQuery( document ).on( 'click', '#mainwp-install-to-selected-sites', function() {
        var checkedVals = jQuery('.mainwp_plugins_site_check_all:checkbox:checked').map(function() {
            var rowElement = jQuery( this ).parents( 'tr' );
            var val = rowElement.find( "input[type=hidden].websiteId" ).val();
            return val;
        }).get();

        if ( checkedVals.length == 0 ) {
            feedback( 'mainwp-message-zone', __( 'Please select at least one website.' ), 'yellow' );
            return false;
        } else {
            jQuery( '#mainwp-message-zone' ).fadeOut(5000);
            var ids = checkedVals.join("-");
            location.href = 'admin.php?page=PluginsInstall&selected_sites=' + ids;
        }
        return false;
    });

    jQuery( document ).on( 'change', '.mainwp_plugins_site_check_all', function() {
        var rowElement = jQuery( this ).parents( 'tr' );
        rowElement.find( '.mainwp-selected-plugin' ).prop( 'checked', jQuery( this ).prop( 'checked' ) );

        if (jQuery('.mainwp_plugins_site_check_all:checkbox:checked').length > 0) {
            jQuery('#mainwp-install-to-selected-sites').show();
        } else {
            jQuery('#mainwp-install-to-selected-sites').fadeOut(1000);
        }

        if ( jQuery( '#mainwp-do-plugins-bulk-actions' ) ) {
            if ( jQuery( '#mainwp-bulk-actions' ).val() == 'activate' )
                return;
            rowElement.find( '.mainwp-selected-plugin' ).prop( 'checked', jQuery( this ).prop( 'checked' ) );
        }
    } );
  jQuery( '#mainwp_show_all_active_plugins' ).on( 'click', function () {
    mainwp_fetch_all_active_plugins();
    return false;
  } );

    var pluginCountSent;
    var pluginCountReceived;
    var pluginResetAllowed = true;

    jQuery( document ).on( 'click', '#mainwp-do-plugins-bulk-actions', function () {
        var action = jQuery("#mainwp-bulk-actions").dropdown( "get value" );
        console.log( action );
        if ( action == '' )
            return false;

        jQuery( this ).attr( 'disabled', 'true' );
        jQuery( '#mainwp_bulk_action_loading' ).show();
        pluginResetAllowed = false;
        pluginCountSent = 0;
        pluginCountReceived = 0;

        //Find all checked boxes
        jQuery( '.websiteId' ).each( function () {
            var websiteId = jQuery( this ).val();
            var rowElement = jQuery( this ).parents( 'tr' );
            var selectedPlugins = rowElement.find( '.mainwp-selected-plugin:checked' );

            if ( selectedPlugins.length == 0 )
                return;

            if ( ( action == 'activate' ) || ( action == 'delete' ) || ( action == 'deactivate' ) || ( action == 'ignore_updates' ) ) {
                var pluginsToSend = [ ];
                var namesToSend = [ ];
                for ( var i = 0; i < selectedPlugins.length; i++ ) {
                    pluginsToSend.push( jQuery( selectedPlugins[i] ).val() );
                    namesToSend.push( jQuery( selectedPlugins[i] ).attr( 'name' ) );
                }

                var data = mainwp_secure_data( {
                    action: 'mainwp_plugin_' + action,
                    plugins: pluginsToSend,
                    websiteId: websiteId
                } );

                if ( action == 'ignore_updates' ) {
                    data['names'] = namesToSend;
                }

                pluginCountSent++;
                jQuery.post( ajaxurl, data, function () {
                    pluginCountReceived++;
                    if ( pluginResetAllowed && pluginCountReceived == pluginCountSent ) {
                        pluginCountReceived = 0;
                        pluginCountSent = 0;
                        jQuery( '#mainwp_bulk_action_loading' ).hide();
                        jQuery( '#mainwp_plugins_loading_info' ).show();
                        mainwp_fetch_plugins();
                    }
                }, 'json' );
            }
        } );

        pluginResetAllowed = true;
        if ( pluginCountReceived == pluginCountSent ) {
            pluginCountReceived = 0;
            pluginCountSent = 0;
            jQuery( '#mainwp_bulk_action_loading' ).hide();
            mainwp_fetch_plugins();
        }
    } );

  jQuery( document ).on( 'click', '.mainwp-edit-plugin-note', function () {
        var rowEl = jQuery( jQuery( this ).parents( 'tr' )[0] );
        var slug = rowEl.attr( 'plugin-slug' );
        var name = rowEl.attr( 'plugin-name' );
        var note = rowEl.find( '.esc-content-note' ).html();
        jQuery( '#mainwp-notes-title' ).html( decodeURIComponent( name ) );
        jQuery( '#mainwp-notes-html' ).html( note == '' ? __( 'No saved notes. Click the Edit button to edit plugin notes.' ) : note );
        jQuery( '#mainwp-notes-note' ).val( note );
        jQuery( '#mainwp-notes-slug' ).val( slug );
        mainwp_notes_show();
    } );

  mainwp_notes_plugin_save = function () {
    var slug = jQuery( '#mainwp-notes-slug' ).val();
    var newnote = jQuery( '#mainwp-notes-note' ).val();
        var data = mainwp_secure_data( {
            action: 'mainwp_trusted_plugin_notes_save',
            slug: slug,
            note: newnote
        } );

    jQuery( '#mainwp-notes-status' ).html( '<i class="notched circle loading icon"></i> ' + __( 'Saving note. Please wait...' ) );

        jQuery.post( ajaxurl, data, function ( pSlug ) {
            return function ( response ) {
                var rowEl = jQuery( 'tr[plugin-slug="' + pSlug + '"]' );
                if ( response.result == 'SUCCESS' ) {
                    jQuery( '#mainwp-notes-status' ).html( '<i class="check circle green icon"></i> ' + __( 'Note saved!' ) );
                    rowEl.find( '.esc-content-note' ).html(jQuery( '#mainwp-notes-note' ).val());

                    if ( newnote == '' ) {
                        rowEl.find( '.mainwp-edit-plugin-note' ).html('<i class="sticky note outline icon"></i>');
                    } else {
                        rowEl.find( '.mainwp-edit-plugin-note' ).html('<i class="sticky green note icon"></i>');
                    }

                } else if ( response.error != undefined ) {
                    jQuery( '#mainwp-notes-status' ).html('<i class="times circle red icon"></i> ' + __( 'Undefined error occured while saving your note' ) + ': ' + response.error );
                } else {
                    jQuery( '#mainwp-notes-status' ).html( '<i class="times circle red icon"></i> ' + __( 'Undefined error occured while saving your note' ) + '.' );
                }
            }
        }( slug ), 'json' );
        return false;
    }

    jQuery( document ).on( 'click', '.mainwp-edit-theme-note', function () {
        var rowEl = jQuery( jQuery( this ).parents( 'tr' )[0] );
        var slug = rowEl.attr( 'theme-slug' );
        var name = rowEl.attr( 'theme-name' );
        var note = rowEl.find( '.esc-content-note' ).html();
        jQuery( '#mainwp-notes' ).removeClass( 'edit-mode' );
        jQuery( '#mainwp-notes-title' ).html( decodeURIComponent( name ) );
        jQuery( '#mainwp-notes-html' ).html( note == '' ? 'No saved notes. Click the Edit button to edit theme notes.' : note );
        jQuery( '#mainwp-notes-note' ).val( note );
        jQuery( '#mainwp-notes-slug' ).val( slug );
        mainwp_notes_show();
    } );

    mainwp_notes_theme_save = function () {
        var slug = jQuery( '#mainwp-notes-slug' ).val();
        var newnote = jQuery( '#mainwp-notes-note' ).val();
        var data = mainwp_secure_data( {
            action: 'mainwp_trusted_theme_notes_save',
            slug: slug,
            note: newnote
        } );

        jQuery( '#mainwp-notes-status' ).html( '<i class="notched circle loading icon"></i> ' + __( 'Saving note. Please wait...' ) );

        jQuery.post( ajaxurl, data, function ( pSlug ) {
            return function ( response ) {
                var rowEl = jQuery( 'tr[theme-slug="' + pSlug + '"]' );
                if ( response.result == 'SUCCESS' ) {
                    jQuery( '#mainwp-notes-status' ).html( '<i class="check circle green icon"></i> ' + __( 'Note saved!' ) );
                    rowEl.find( '.esc-content-note' ).html(jQuery( '#mainwp-notes-note' ).val());
                    if ( newnote == '' ) {
                        rowEl.find( '.mainwp-edit-theme-note' ).html('<i class="sticky note outline icon"></i>');
                    } else {
                        rowEl.find( '.mainwp-edit-theme-note' ).html('<i class="sticky green note icon"></i>');
                    }
                } else if ( response.error != undefined ) {
                    jQuery( '#mainwp-notes-status' ).html( '<i class="times circle red icon"></i> ' + __( 'Undefined error occured while saving your note!' ) + ': ' + response.error );
                } else {
                    jQuery( '#mainwp-notes-status' ).html( '<i class="times circle red icon"></i> ' + __( 'Undefined error occured while saving your note!' ) );
                }
            }
        }( slug ), 'json' );
        return false;
    }
} );

// Manage Plugins -- Fetch plugins
mainwp_fetch_plugins = function () {
    var errors = [ ];
    var selected_sites = [ ];
    var selected_groups = [ ];

    if ( jQuery( '#select_by' ).val() == 'site' ) {
        jQuery( "input[name='selected_sites[]']:checked" ).each( function () {
            selected_sites.push( jQuery( this ).val() );
        } );
        if ( selected_sites.length == 0 ) {
      errors.push( __( 'Please select at least one website or group.' ) );
        }
    } else {
        jQuery( "input[name='selected_groups[]']:checked" ).each( function () {
            selected_groups.push( jQuery( this ).val() );
        } );
        if ( selected_groups.length == 0 ) {
      errors.push( __( 'Please select at least one website or group.' ) );
        }
    }

    var _status;

    var statuses = jQuery( "#mainwp_plugins_search_by_status" ).dropdown( "get value" );

    if ( statuses == null )
        errors.push( __( 'Please select at least one plugin status.' ) );
    else {
        _status = statuses.join( ',' );
    }

    if ( errors.length > 0 ) {
        jQuery( '#mainwp-message-zone' ).html( errors.join( '<br />' ) );
        jQuery( '#mainwp-message-zone' ).addClass( 'yellow' );
        jQuery( '#mainwp-message-zone' ).show();
        return;
    } else {
        jQuery( '#mainwp-message-zone' ).html( '' );
        jQuery( '#mainwp-message-zone' ).removeClass( 'yellow' );
        jQuery( '#mainwp-message-zone' ).hide();
    }

    var data = mainwp_secure_data( {
        action: 'mainwp_plugins_search',
        keyword: jQuery( '#mainwp_plugin_search_by_keyword' ).val(),
        status: _status,
        'groups[]': selected_groups,
        'sites[]': selected_sites
    } );

    jQuery( '#mainwp-loading-plugins-row' ).show();

    jQuery.post( ajaxurl, data, function ( response ) {
        jQuery( '#mainwp-loading-plugins-row' ).hide();
    jQuery( '#mainwp-plugins-main-content' ).show();

        if ( response && response.result ) {
            jQuery( '#mainwp-plugins-content' ).html( response.result );
            jQuery( '#mainwp-plugins-bulk-actions-wapper' ).html( response.bulk_actions );
            jQuery( '#mainwp-plugins-bulk-actions-wapper .ui.dropdown').dropdown();
        }
    }, 'json' );
};

// Fetch plugins for the Auto Update feature
mainwp_fetch_all_active_plugins = function () {
    var data = mainwp_secure_data( {
        action: 'mainwp_plugins_search_all_active',
        keyword: jQuery( "#mainwp_au_plugin_keyword" ).val(),
        status: jQuery( "#mainwp_au_plugin_trust_status" ).val(),
        plugin_status: jQuery( "#mainwp_au_plugin_status" ).val()
    } );

  jQuery( '#mainwp-auto-updates-plugins-content' ).find( '.dimmer' ).addClass( 'active' );

    jQuery.post( ajaxurl, data, function ( response ) {
        response = jQuery.trim( response );
    jQuery( '#mainwp-auto-updates-plugins-content' ).find( '.dimmer' ).removeClass( 'active' );
    jQuery( '#mainwp-auto-updates-plugins-table-wrapper' ).html( response );
    } );
};

// Fetch themes for the Auto Update feature
mainwp_fetch_all_themes = function () {
    var data = mainwp_secure_data( {
        action: 'mainwp_themes_search_all',
        keyword: jQuery( "#mainwp_au_theme_keyword" ).val(),
        status: jQuery( "#mainwp_au_theme_trust_status" ).val(),
        theme_status: jQuery( "#mainwp_au_theme_status" ).val()
    } );

  jQuery( '#mainwp-auto-updates-themes-content' ).find( '.dimmer' ).addClass( 'active' );

    jQuery.post( ajaxurl, data, function ( response ) {
    jQuery( '#mainwp-auto-updates-themes-content' ).find( '.dimmer' ).removeClass( 'active' );
    jQuery( '#mainwp-auto-updates-themes-table-wrapper' ).html( response );
    } );
};



/**
 * MainWP_Themes.page
 */
jQuery( document ).ready( function () {
    jQuery( document ).on( 'click', '#mainwp_show_themes', function() {
        mainwp_fetch_themes();
    } );

    jQuery( document ).on( 'change', '.mainwp_theme_check_all', function() {
        jQuery( ".mainwp-selected-theme[value='" + jQuery( this ).val() + "'][version='" + jQuery( this ).attr( 'version' ) + "']" ).prop( 'checked', jQuery( this ).prop( 'checked' ) );
    } );

    jQuery( document ).on( 'change', '.mainwp_themes_site_check_all', function() {
        var rowElement = jQuery( this ).parents( 'tr' );
        rowElement.find( '.mainwp-selected-theme' ).prop( 'checked', jQuery( this ).prop( 'checked' ) );

        if (jQuery('.mainwp_themes_site_check_all:checkbox:checked').length > 0) {
            jQuery('#mainwp-install-themes-to-selected-sites').show();
        } else {
            jQuery('#mainwp-install-themes-to-selected-sites').fadeOut(1000);
        }

        if ( jQuery( '#mainwp-do-themes-bulk-actions' ) ) {
            if ( jQuery( '#mainwp-bulk-actions' ).val() == 'activate' )
                return;
            rowElement.find( '.mainwp-selected-theme' ).prop( 'checked', jQuery( this ).prop( 'checked' ) );
        }
    } );

     jQuery( document ).on( 'click', '#mainwp-install-themes-to-selected-sites', function() {
        var checkedVals = jQuery('.mainwp_themes_site_check_all:checkbox:checked').map(function() {
            var rowElement = jQuery( this ).parents( 'tr' );
            var val = rowElement.find( "input[type=hidden].websiteId" ).val();
            return val;
        }).get();

        if ( checkedVals.length == 0 ) {
            feedback( 'mainwp-message-zone', __( 'Please select at least one website.' ), 'yellow' );
            return false;
        } else {
            jQuery( '#mainwp-message-zone' ).fadeOut(5000);
            var ids = checkedVals.join("-");
            location.href = 'admin.php?page=ThemesInstall&selected_sites=' + ids;
        }
        return false;
    });

  jQuery( document ).on( 'click', '#mainwp_show_all_active_themes', function () {
    mainwp_fetch_all_themes();
    return false;
  } );

    var themeCountSent;
    var themeCountReceived;
    var themeResetAllowed = true;

    jQuery( document ).on( 'click', '#mainwp-do-themes-bulk-actions', function () {
        var action = jQuery("#mainwp-bulk-actions").dropdown( "get value" );
        console.log(action);
        if ( action == '' || action == 'none' )
            return;

        jQuery( '#mainwp-do-themes-bulk-actions' ).attr( 'disabled', 'true' );
        jQuery( '#mainwp_bulk_action_loading' ).show();
        themeResetAllowed = false;
        themeCountSent = 0;
        themeCountReceived = 0;

        //Find all checked boxes
        jQuery( '.websiteId' ).each( function () {
            var websiteId = jQuery( this ).val();
            var rowElement = jQuery( this ).parents( 'tr' );
            var selectedThemes = rowElement.find( '.mainwp-selected-theme:checked' );

            if ( selectedThemes.length == 0 )
                return;

            if ( action == 'activate' || action == 'ignore_updates' ) {
                var themeToActivate = jQuery(selectedThemes[0]).attr('slug');
                var themesToSend = [ ];
                var namesToSend = [ ];

                var data = mainwp_secure_data( {
                    action: 'mainwp_theme_' + action,
                    websiteId: websiteId
                } );

                if ( action == 'ignore_updates' ) {
                    for ( var i = 0; i < selectedThemes.length; i++ ) {
                        themesToSend.push( jQuery( selectedThemes[i] ).attr( 'slug' ) );
                        namesToSend.push( jQuery( selectedThemes[i] ).val() );
                    }
                    data['themes'] = themesToSend;
                    data['names'] = namesToSend;
                } else {
                    data['theme'] = themeToActivate;
                }

                themeCountSent++;
                jQuery.post( ajaxurl, data, function () {
                    themeCountReceived++;
                    if ( themeResetAllowed && themeCountReceived == themeCountSent ) {
                        themeCountReceived = 0;
                        themeCountSent = 0;
                        jQuery( '#mainwp_bulk_action_loading' ).hide();
                        jQuery( '#mainwp_themes_loading_info' ).show();
                        mainwp_fetch_themes();
                    }
                } );
            } else if ( action == 'delete' ) {
                var themesToDelete = [ ];
                for ( var i = 0; i < selectedThemes.length; i++ ) {
          themesToDelete.push( jQuery( selectedThemes[i] ).attr( 'slug' ) );
                }
                var data = mainwp_secure_data( {
                    action: 'mainwp_theme_delete',
                    themes: themesToDelete,
                    websiteId: websiteId
                } );

                themeCountSent++;
                jQuery.post( ajaxurl, data, function () {
                    themeCountReceived++;
                    if ( themeResetAllowed && themeCountReceived == themeCountSent ) {
                        themeCountReceived = 0;
                        themeCountSent = 0;
                        jQuery( '#mainwp_bulk_action_loading' ).hide();
                        jQuery( '#mainwp_themes_loading_info' ).show();
                        mainwp_fetch_themes();
                    }
                } );
            }
        } );

        themeResetAllowed = true;
        if ( themeCountReceived == themeCountSent ) {
            themeCountReceived = 0;
            themeCountSent = 0;
            jQuery( '#mainwp_bulk_action_loading' ).hide();
            jQuery( '#mainwp_themes_loading_info' ).show();
            mainwp_fetch_themes();
        }
} );

} );

mainwp_themes_check_changed = function ( elements )
{
    var action = jQuery( '#mainwp-bulk-actions' ).val();
    if ( action != 'activate' )
        return;

    for ( var i = 0; i < elements.length; i++ )
    {
        var element = jQuery( elements[i] );
        if ( !element.is( ':checked' ) )
            continue;

        var parent = jQuery( element.parents( 'tr' )[0] );

        if ( !parent )
            continue;
        var subElements = parent.find( '.mainwp-selected-theme:checked' );
        for ( var j = 0; j < subElements.length; j++ )
        {
            var subElement = subElements[j];
            if ( subElement == element[0] )
                continue;

            jQuery( subElement ).removeAttr( 'checked' );
        }
    }
}

// Manage Themes -- Fetch themes from child sites
mainwp_fetch_themes = function () {
    var errors = [ ];
    var selected_sites = [ ];
    var selected_groups = [ ];

    if ( jQuery( '#select_by' ).val() == 'site' ) {
        jQuery( "input[name='selected_sites[]']:checked" ).each( function () {
            selected_sites.push( jQuery( this ).val() );
        } );
        if ( selected_sites.length == 0 ) {
      errors.push( __( 'Please select at least one website or group.' ) );
        }
    } else {
        jQuery( "input[name='selected_groups[]']:checked" ).each( function () {
            selected_groups.push( jQuery( this ).val() );
        } );
        if ( selected_groups.length == 0 ) {
      errors.push( __( 'Please select at least one website or group.' ) );
        }
    }

    var _status = '';
    var statuses = jQuery( "#mainwp_themes_search_by_status" ).dropdown( "get value" );
    if ( statuses == null ) {
        errors.push( __( 'Please select at least one theme status.' ) );
    } else {
        _status = statuses.join(',');
    }

    if ( errors.length > 0 ) {
      jQuery( '#mainwp-message-zone' ).html( errors.join( '<br />' ) );
      jQuery( '#mainwp-message-zone' ).addClass( 'yellow' );
      jQuery( '#mainwp-message-zone' ).show();
        return;
    } else {
      jQuery( '#mainwp-message-zone' ).html( '' );
      jQuery( '#mainwp-message-zone' ).removeClass( 'yellow' );
      jQuery( '#mainwp-message-zone' ).hide();
    }

    var data = mainwp_secure_data( {
        action: 'mainwp_themes_search',
        keyword: jQuery( '#mainwp_theme_search_by_keyword' ).val(),
        status: _status,
        'groups[]': selected_groups,
        'sites[]': selected_sites
    } );

    jQuery( '#mainwp-loading-themes-row' ).show();

    jQuery.post( ajaxurl, data, function ( response ) {
    jQuery( '#mainwp-loading-themes-row' ).hide();
    jQuery( '#mainwp-themes-main-content' ).show();
    if ( response && response.result ) {
        jQuery( '#mainwp-themes-content' ).html( response.result );
            jQuery( '#mainwp-themes-bulk-actions-wapper' ).html( response.bulk_actions );
            jQuery( '#mainwp-themes-bulk-actions-wapper .ui.dropdown').dropdown();
        }
    }, 'json' );
};
