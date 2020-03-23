jQuery( document ).ready( function () {

    // review for new UI update
    jQuery( document ).on( 'click', '#mainwp-category-add-submit', function() {
        var newCat = jQuery( '#newcategory' ).val();
        if ( jQuery( '#categorychecklist' ).find( 'option[value="' + encodeURIComponent( newCat ) + '"]' ).length > 0 )
            return;
        jQuery( '#categorychecklist' ).append( '<option value="' + encodeURIComponent( newCat ) + '">' + newCat + '</option>' );
        jQuery( '#category-adder' ).addClass( 'wp-hidden-children' );
        jQuery( '#newcategory' ).val( '' );
    } );

    // Show/Hide new category field and button
    jQuery( '#category-add-toggle' ).on( 'click', function() {
      jQuery( '#newcategory-field' ).toggle();
      jQuery( '#mainwp-category-add-submit-field' ).toggle();
      return false;
    } );

} );

/**
 * Global
 */
jQuery( document ).ready( function () {
    jQuery( '.mainwp-row' ).live( {
        mouseenter: function () {
            rowMouseEnter( this );
        },
        mouseleave: function () {
            rowMouseLeave( this );
        }
    } );
} );
rowMouseEnter = function ( elem ) {
    if ( !jQuery( elem ).children( '.mainwp-row-actions-working' ).is( ":visible" ) )
        jQuery( elem ).children( '.mainwp-row-actions' ).show();
};
rowMouseLeave = function ( elem ) {
    if ( jQuery( elem ).children( '.mainwp-row-actions' ).is( ":visible" ) )
        jQuery( elem ).children( '.mainwp-row-actions' ).hide();
};

/**
 * Recent posts
 */
jQuery( document ).ready( function () {
    jQuery( '.mainwp-post-unpublish' ).live( 'click', function () {
        postAction( jQuery( this ), 'unpublish' );
        return false;
    } );
    jQuery( '.mainwp-post-publish' ).live( 'click', function () {
        postAction( jQuery( this ), 'publish' );
        return false;
    } );
    jQuery( '.mainwp-post-trash' ).live( 'click', function () {
        postAction( jQuery( this ), 'trash' );
        return false;
    } );
    jQuery( '.mainwp-post-restore' ).live( 'click', function () {
        postAction( jQuery( this ), 'restore' );
        return false;
    } );
    jQuery( '.mainwp-post-delete' ).live( 'click', function () {
        postAction( jQuery( this ), 'delete' );
        return false;
    } );
//    jQuery( '.recent_posts_published_lnk' ).live( 'click', function () {
//        showRecentPostsList( jQuery( this ), true, false, false, false, false );
//        return false;
//    } );
//    jQuery( '.recent_posts_draft_lnk' ).live( 'click', function () {
//        showRecentPostsList( jQuery( this ), false, true, false, false, false );
//        return false;
//    } );
//    jQuery( '.recent_posts_pending_lnk' ).live( 'click', function () {
//        showRecentPostsList( jQuery( this ), false, false, true, false, false );
//        return false;
//    } );
//    jQuery( '.recent_posts_trash_lnk' ).live( 'click', function () {
//        showRecentPostsList( jQuery( this ), false, false, false, true, false );
//        return false;
//    } );
//    jQuery( '.recent_posts_future_lnk' ).live( 'click', function () {
//        showRecentPostsList( jQuery( this ), false, false, false, false, true );
//        return false;
//    } );
    jQuery( '.plugins_actived_lnk' ).live( 'click', function () {
        showPluginsList( jQuery( this ), true, false );
        return false;
    } );
    jQuery( '.plugins_inactive_lnk' ).live( 'click', function () {
        showPluginsList( jQuery( this ), false, true );
        return false;
    } );
    jQuery( '.themes_actived_lnk' ).live( 'click', function () {
        showThemesList( jQuery( this ), true, false );
        return false;
    } );
    jQuery( '.themes_inactive_lnk' ).live( 'click', function () {
        showThemesList( jQuery( this ), false, true );
        return false;
    } );

} );

// Publish, Unpublish, Trash, ... posts and pages
postAction = function ( elem, what ) {
  var rowElement = jQuery( elem ).closest('.grid');
  var postId = rowElement.children( '.postId' ).val();
  var websiteId = rowElement.children( '.websiteId' ).val();

  var data = mainwp_secure_data( {
    action: 'mainwp_post_' + what,
    postId: postId,
    websiteId: websiteId
  } );
  rowElement.hide();
  rowElement.next( '.mainwp-row-actions-working' ).show();
  jQuery.post( ajaxurl, data, function ( response ) {
    if ( response.error ) {
      rowElement.show();
      rowElement.next( '.mainwp-row-actions-working' ).hide();
      rowElement.html( '<div class="sixteen wide column"><i class="times circle red icon"></i> ' + response.error + '</div>' );
    } else if ( response.result ) {
      rowElement.show();
      rowElement.next( '.mainwp-row-actions-working' ).hide();
      rowElement.html( '<div class="sixteen wide column"><i class="check circle green icon"></i>' + response.result + '</div>' );
    } else {
      rowElement.show();
      rowElement.next( '.mainwp-row-actions-working' ).hide();
    }
  }, 'json' );
  return false;
};

/**
 * Plugins Widget
 */
jQuery( document ).ready( function () {
  jQuery( '.mainwp-plugin-deactivate' ).live( 'click', function () {
    pluginAction( jQuery( this ), 'deactivate' );
    return false;
  } );
  jQuery( '.mainwp-plugin-activate' ).live( 'click', function () {
    pluginAction( jQuery( this ), 'activate' );
    return false;
  } );
  jQuery( '.mainwp-plugin-delete' ).live( 'click', function () {
    pluginAction( jQuery( this ), 'delete' );
    return false;
  } );
} );

pluginAction = function ( elem, what ) {
  var rowElement = jQuery( elem ).parent().parent();
  var plugin = rowElement.children( '.pluginSlug' ).val();
  var websiteId = rowElement.children( '.websiteId' ).val();

  var data = mainwp_secure_data( {
    action: 'mainwp_widget_plugin_' + what,
    plugin: plugin,
    websiteId: websiteId
  } );
  rowElement.children().hide();
  rowElement.children( '.mainwp-row-actions-working' ).show();
  jQuery.post( ajaxurl, data, function ( response ) {
    if ( response && response.error ) {
      rowElement.children().show();
      rowElement.html( response.error );
    } else if ( response && response.result ) {
      rowElement.children().show();
      rowElement.html( response.result );
    } else {
      rowElement.children( '.mainwp-row-actions-working' ).hide();
    }
  }, 'json' );

    return false;
};

/**
 * Themes Widget
 */
jQuery( document ).ready( function () {
  jQuery( '.mainwp-theme-activate' ).live( 'click', function () {
    themeAction( jQuery( this ), 'activate' );
    return false;
  } );
  jQuery( '.mainwp-theme-delete' ).live( 'click', function () {
    themeAction( jQuery( this ), 'delete' );
    return false;
  } );
} );

themeAction = function ( elem, what ) {
  var rowElement = jQuery( elem ).parent().parent();
  var theme = rowElement.children( '.themeSlug' ).val();
  var websiteId = rowElement.children( '.websiteId' ).val();

  var data = mainwp_secure_data( {
    action: 'mainwp_widget_theme_' + what,
    theme: theme,
    websiteId: websiteId
  } );
  rowElement.children().hide();
  rowElement.children( '.mainwp-row-actions-working' ).show();
  jQuery.post( ajaxurl, data, function ( response ) {
    if ( response && response.error ) {
      rowElement.children().show();
      rowElement.html( response.error );
    } else if ( response && response.result ) {
      rowElement.children().show();
      rowElement.html( response.result );
    } else {
      rowElement.children( '.mainwp-row-actions-working' ).hide();
    }
  }, 'json' );

  return false;
};

showPluginsList = function ( pElement, activate, inactivate ) {
  var plugins_actived_lnk = pElement.parent().parent().find( ".plugins_actived_lnk" );
  if ( activate )
    plugins_actived_lnk.addClass( 'mainwp_action_down' );
  else
    plugins_actived_lnk.removeClass( 'mainwp_action_down' );

  var plugins_inactive_lnk = pElement.parent().parent().find( ".plugins_inactive_lnk" );
  if ( inactivate )
    plugins_inactive_lnk.addClass( 'mainwp_action_down' );
  else
    plugins_inactive_lnk.removeClass( 'mainwp_action_down' );

  var plugins_activate = pElement.parent().parent().find( ".mainwp_plugins_active" );
  var plugins_inactivate = pElement.parent().parent().find( ".mainwp_plugins_inactive" );

  if ( activate )
    plugins_activate.show();
  if ( inactivate )
    plugins_inactivate.show();

  if ( !activate )
    plugins_activate.hide();
  if ( !inactivate )
    plugins_inactivate.hide();
};


showThemesList = function ( pElement, activate, inactivate ) {
    var themes_actived_lnk = pElement.parent().parent().find( ".themes_actived_lnk" );
    if ( activate )
        themes_actived_lnk.addClass( 'mainwp_action_down' );
    else
        themes_actived_lnk.removeClass( 'mainwp_action_down' );

    var themes_inactive_lnk = pElement.parent().parent().find( ".themes_inactive_lnk" );
    if ( inactivate )
        themes_inactive_lnk.addClass( 'mainwp_action_down' );
    else
        themes_inactive_lnk.removeClass( 'mainwp_action_down' );

    var themes_activate = pElement.parent().parent().find( ".mainwp_themes_active" );
    var themes_inactivate = pElement.parent().parent().find( ".mainwp_themes_inactive" );

    if ( activate )
        themes_activate.show();
    if ( inactivate )
        themes_inactivate.show();

    if ( !activate )
        themes_activate.hide();
    if ( !inactivate )
        themes_inactivate.hide();

};

// offsetRelative (or, if you prefer, positionRelative)
( function ( $ ) {
    $.fn.offsetRelative = function ( top ) {
        var $this = $( this );
        var $parent = $this.offsetParent();
        var offset = $this.position();
        if ( !top )
            return offset; // Didn't pass a 'top' element
        else if ( $parent.get( 0 ).tagName == "BODY" )
            return offset; // Reached top of document
        else if ( $( top, $parent ).length )
            return offset; // Parent element contains the 'top' element we want the offset to be relative to
        else if ( $parent[0] == $( top )[0] )
            return offset; // Reached the 'top' element we want the offset to be relative to
        else { // Get parent's relative offset
            var parent_offset = $parent.offsetRelative( top );
            offset.top += parent_offset.top;
            offset.left += parent_offset.left;
            return offset;
        }
    };
    $.fn.positionRelative = function ( top ) {
        return $( this ).offsetRelative( top );
    };
}( jQuery ) );

var hidingSubMenuTimers = { };
jQuery( document ).ready( function () {
    jQuery( 'span[id^=mainwp]' ).each( function () {
        jQuery( this ).parent().parent().hover( function () {
            var spanEl = jQuery( this ).find( 'span[id^=mainwp]' );
            var spanId = /^mainwp-(.*)$/.exec( spanEl.attr( 'id' ) );
            if ( spanId ) {
                if ( hidingSubMenuTimers[spanId[1]] ) {
                    clearTimeout( hidingSubMenuTimers[spanId[1]] );
                }
                var currentMenu = jQuery( '#menu-mainwp-' + spanId[1] );
                var offsetVal = jQuery( this ).offset();
                currentMenu.css( 'left', offsetVal.left + jQuery( this ).outerWidth() - 30 );

                currentMenu.css( 'top', offsetVal.top - 15 - jQuery( this ).outerHeight() ); // + tmp);
                subMenuIn( spanId[1] );
            }
        }, function () {
            var spanEl = jQuery( this ).find( 'span[id^=mainwp]' );
            var spanId = /^mainwp-(.*)$/.exec( spanEl.attr( 'id' ) );
            if ( spanId ) {
                hidingSubMenuTimers[spanId[1]] = setTimeout( function ( span ) {
                    return function () {
                        subMenuOut( span );
                    };
                }( spanId[1] ), 30 );
            }
        } );
    } );
    jQuery( '.mainwp-submenu-wrapper' ).on( {
        mouseenter: function () {
            var spanId = /^menu-mainwp-(.*)$/.exec( jQuery( this ).attr( 'id' ) );
            if ( spanId ) {
                if ( hidingSubMenuTimers[spanId[1]] ) {
                    clearTimeout( hidingSubMenuTimers[spanId[1]] );
                }
            }
        },
        mouseleave: function () {
            var spanId = /^menu-mainwp-(.*)$/.exec( jQuery( this ).attr( 'id' ) );
            if ( spanId ) {
                hidingSubMenuTimers[spanId[1]] = setTimeout( function ( span ) {
                    return function () {
                        subMenuOut( span );
                    };
                }( spanId[1] ), 30 );
            }
        }
    } );
} );
subMenuIn = function ( subName ) {
    jQuery( '#menu-mainwp-' + subName ).show();
    jQuery( '#mainwp-' + subName ).parent().parent().addClass( 'hoverli' );
    jQuery( '#mainwp-' + subName ).parent().parent().css( 'background-color', '#EAF2FA' );
    jQuery( '#mainwp-' + subName ).css( 'color', '#333' );
};
subMenuOut = function ( subName ) {
    jQuery( '#menu-mainwp-' + subName ).hide();
    jQuery( '#mainwp-' + subName ).parent().parent().css( 'background-color', '' );
    jQuery( '#mainwp-' + subName ).parent().parent().removeClass( 'hoverli' );
    jQuery( '#mainwp-' + subName ).css( 'color', '' );
};


function shake_element( select ) {
    var pos = jQuery( select ).position();
    var type = jQuery( select ).css( 'position' );

  if ( type == 'static' ) {
        jQuery( select ).css( {
            position: 'relative'
        } );
    }

  if ( type == 'static' || type == 'relative' ) {
        pos.top = 0;
        pos.left = 0;
    }

    jQuery( select ).data( 'init-type', type );

    var shake = [ [ 0, 5, 60 ], [ 0, 0, 60 ], [ 0, -5, 60 ], [ 0, 0, 60 ], [ 0, 2, 30 ], [ 0, 0, 30 ], [ 0, -2, 30 ], [ 0, 0, 30 ] ];

    for ( s = 0; s < shake.length; s++ ) {
        jQuery( select ).animate( {
            top: pos.top + shake[s][0],
            left: pos.left + shake[s][1]
        }, shake[s][2], 'linear' );
    }
}


/**
 * Required
 */
feedback = function ( id, text, type, append ) {
  if ( append == true ) {
    var currentHtml = jQuery( '#' + id ).html();
    if ( currentHtml == null )
      currentHtml = "";
    if ( currentHtml != '' ) {
      currentHtml += '<br />' + text;
    } else {
      currentHtml = text;
    }
    jQuery( '#' + id ).html( currentHtml );
    jQuery( '#' + id ).addClass( type );
  } else {
    jQuery( '#' + id ).html( text );
    jQuery( '#' + id ).addClass( type );
  }
  jQuery( '#' + id ).show();

  // automatically scroll to error message if it's not visible
  var scrolltop = jQuery( window ).scrollTop();
  var off = jQuery( '#' + id ).offset();
  if ( scrolltop > off.top - 40 )
      jQuery('html, body').animate({
          scrollTop:off.top - 40
      }, 1000, function () {
      shake_element( '#' + id )
      });
  else
      shake_element('#' + id); // shake the error message to get attention :)
};

hide_error = function ( id ) {
    var idElement = jQuery( '#' + id );
    idElement.html( "" );
    idElement.hide();
};
jQuery( document ).ready( function () {
    jQuery( 'div.mainwp-hidden' ).parent().parent().css( "display", "none" );
} );

/**
 * Security Issues
 */

var securityIssues_fixes = [ 'listing', 'wp_version', 'rsd', 'wlw', 'core_updates', 'plugin_updates', 'theme_updates', 'db_reporting', 'php_reporting', 'versions', 'registered_versions', 'admin', 'readme' ];
jQuery( document ).ready( function () {
  var securityIssueSite = jQuery( '#securityIssueSite' );
  if ( ( securityIssueSite.val() != null ) && ( securityIssueSite.val() != "" ) ) {
    jQuery( '#securityIssues_fixAll' ).live( 'click', function () {
      securityIssues_fix( 'all' );
    } );

    jQuery( '#securityIssues_refresh' ).live( 'click', function () {
      for ( var i = 0; i < securityIssues_fixes.length; i++ ) {
        var securityIssueCurrentIssue = jQuery( '#' + securityIssues_fixes[i] + '_fix' );
        if ( securityIssueCurrentIssue ) {
          securityIssueCurrentIssue.hide();
        }
        jQuery( '#' + securityIssues_fixes[i] + '_extra' ).hide();
        jQuery( '#' + securityIssues_fixes[i] + '_ok' ).hide();
        jQuery( '#' + securityIssues_fixes[i] + '_nok' ).hide();
        jQuery( '#' + securityIssues_fixes[i] + '_loading' ).show();
      }
      securityIssues_request( jQuery( '#securityIssueSite' ).val() );
    } );

    for ( var i = 0; i < securityIssues_fixes.length; i++ ) {
      jQuery( '#' + securityIssues_fixes[i] + '_fix' ).bind( 'click', function ( what ) {
        return function () {
          securityIssues_fix( what );
          return false;
        }
      }( securityIssues_fixes[i] ) );

      jQuery( '#' + securityIssues_fixes[i] + '_unfix' ).bind( 'click', function ( what ) {
        return function () {
          securityIssues_unfix( what );
          return false;
        }
      }( securityIssues_fixes[i] ) );
    }
    securityIssues_request( securityIssueSite.val() );
  }
} );
securityIssues_fix = function ( feature ) {
  if ( feature == 'all' ) {
    for ( var i = 0; i < securityIssues_fixes.length; i++ ) {
      if ( jQuery( '#' + securityIssues_fixes[i] + '_nok' ).css( 'display' ) != 'none' ) {
        if ( jQuery( '#' + securityIssues_fixes[i] + '_fix' ) ) {
          jQuery( '#' + securityIssues_fixes[i] + '_fix' ).hide();
        }
        jQuery( '#' + securityIssues_fixes[i] + '_extra' ).hide();
        jQuery( '#' + securityIssues_fixes[i] + '_ok' ).hide();
        jQuery( '#' + securityIssues_fixes[i] + '_nok' ).hide();
        jQuery( '#' + securityIssues_fixes[i] + '_loading' ).show();
      }
    }
  } else {
    if ( jQuery( '#' + feature + '_fix' ) ) {
      jQuery( '#' + feature + '_fix' ).hide();
    }
    jQuery( '#' + feature + '_extra' ).hide();
    jQuery( '#' + feature + '_ok' ).hide();
    jQuery( '#' + feature + '_nok' ).hide();
    jQuery( '#' + feature + '_loading' ).show();
  }

  var data = mainwp_secure_data( {
    action: 'mainwp_security_issues_fix',
    feature: feature,
    id: jQuery( '#securityIssueSite' ).val()
  } );

  jQuery.post( ajaxurl, data, function ( response ) {
    securityIssues_handle( response );
  }, 'json' );
};
var completedSecurityIssues = undefined;

// Securtiy issues Widget

// Show/Hide the list
jQuery( document ).on( 'click', '#show-security-issues-widget-list', function () {
  jQuery( '#mainwp-security-issues-widget-list' ).toggle();
  return false;
} );

// Fix all sites all security issues
jQuery( document ).on( 'click', '.fix-all-security-issues', function () {

  jQuery( '#mainwp-secuirty-issues-loader' ).show();

  jQuery( '#mainwp-security-issues-widget-list' ).show();

  var sites = jQuery( '#mainwp-security-issues-widget-list' ).find( '.item' );
  completedSecurityIssues = 0;
  for ( var i = 0; i < sites.length; i++ ) {
    var site = jQuery( sites[i] );
    completedSecurityIssues++;
    mainwp_fix_all_security_issues( site.attr( 'siteid' ), false );
  }
} );

// Fix all securtiy issues for a site
jQuery( document ).on( 'click', '.fix-all-site-security-issues', function () {
  jQuery( '#mainwp-secuirty-issues-loader' ).show();
  mainwp_fix_all_security_issues( jQuery( this ).closest( '.item' ).attr( 'siteid' ), true );
} );

mainwp_fix_all_security_issues = function ( siteId, refresh ) {
  var data = mainwp_secure_data( {
    action: 'mainwp_security_issues_fix',
    feature: 'all',
    id: siteId
  } );

  var el = jQuery( '#mainwp-security-issues-widget-list .item[siteid="' + siteId + '"] .fix-all-site-security-issues' );

  el.hide();

  jQuery( '.fix-all-site-security-issues' ).addClass( 'disabled' );
  jQuery( '.unfix-all-site-security-issues' ).addClass( 'disabled' );

  jQuery.post( ajaxurl, data, function ( pRefresh ) {
    return function () {
      el.show();
      if ( pRefresh || ( completedSecurityIssues != undefined && --completedSecurityIssues <= 0 ) ) {
        window.location.href = location.href;
      }
    }
  }( refresh, el ), 'json' );
};

jQuery( document ).on( 'click', '.unfix-all-site-security-issues', function () {

  jQuery( '#mainwp-secuirty-issues-loader' ).show();

  var data = mainwp_secure_data( {
    action: 'mainwp_security_issues_unfix',
    feature: 'all',
    id: jQuery( jQuery( this ).parents( '.item' )[0] ).attr( 'siteid' )
  } );

  jQuery( this ).hide();
  jQuery( '.fix-all-site-security-issues' ).addClass( 'disabled' );
  jQuery( '.unfix-all-site-security-issues' ).addClass( 'disabled' );

  jQuery.post( ajaxurl, data, function () {
    window.location.href = location.href;
  }, 'json' );
} );
securityIssues_unfix = function ( feature ) {
    if ( jQuery( '#' + feature + '_unfix' ) ) {
        jQuery( '#' + feature + '_unfix' ).hide();
    }
    jQuery( '#' + feature + '_extra' ).hide();
    jQuery( '#' + feature + '_ok' ).hide();
    jQuery( '#' + feature + '_nok' ).hide();
    jQuery( '#' + feature + '_loading' ).show();

    var data = mainwp_secure_data( {
        action: 'mainwp_security_issues_unfix',
        feature: feature,
        id: jQuery( '#securityIssueSite' ).val()
    } );
    jQuery.post( ajaxurl, data, function ( response ) {
        securityIssues_handle( response );
    }, 'json' );
};
securityIssues_request = function ( websiteId ) {
    var data = mainwp_secure_data( {
        action: 'mainwp_security_issues_request',
        id: websiteId
    } );
    jQuery.post( ajaxurl, data, function ( response ) {
        securityIssues_handle( response );
    }, 'json' );
};
securityIssues_handle = function ( response ) {
    var result = '';
    if ( response.error )
    {
        result = getErrorMessage( response.error );
    } else
    {
        try {
            var res = response.result;
            for ( var issue in res ) {
                if ( jQuery( '#' + issue + '_loading' ) ) {
                    jQuery( '#' + issue + '_loading' ).hide();
                    if ( res[issue] == 'Y' ) {
                        jQuery( '#' + issue + '_extra' ).hide();
                        jQuery( '#' + issue + '_nok' ).hide();
                        if ( jQuery( '#' + issue + '_fix' ) ) {
                            jQuery( '#' + issue + '_fix' ).hide();
                        }
                        if ( jQuery( '#' + issue + '_unfix' ) ) {
                            jQuery( '#' + issue + '_unfix' ).show();
                        }
                        jQuery( '#' + issue + '_ok' ).show();
                        jQuery( '#' + issue + '-status-ok' ).show();
                        jQuery( '#' + issue + '-status-nok' ).hide();
                        if (issue == 'readme') {
                            jQuery('#readme-wpe-nok').hide();
                        }
                    } else {
                        jQuery( '#' + issue + '_extra' ).hide();
                        jQuery( '#' + issue + '_ok' ).hide();
                        jQuery( '#' + issue + '_nok' ).show();
                        if ( jQuery( '#' + issue + '_fix' ) ) {
                            jQuery( '#' + issue + '_fix' ).show();
                        }
                        if ( jQuery( '#' + issue + '_unfix' ) ) {
                            jQuery( '#' + issue + '_unfix' ).hide();
                        }

                        if ( res[issue] != 'N' ) {
                            jQuery( '#' + issue + '_extra' ).html( res[issue] );
                            jQuery( '#' + issue + '_extra' ).show();
                        }
                    }
                }
            }
        } catch ( err ) {
            result = '<i class="exclamation circle icon"></i> ' + __( 'Undefined error!' );
        }
    }
    if ( result != '' ) {
        //show error!
    }
};

/**
 * Sync Sites
 */

jQuery( document ).ready( function () {
    jQuery( '#mainwp-sync-sites' ).on( 'click', function () {
      mainwp_sync_sites_data();
    } );

    // to compatible with extensions
    jQuery('#dashboard_refresh').on('click', function () {
        mainwp_sync_sites_data();
    });
});

mainwp_sync_sites_data = function ( syncSiteIds ) {
  var allWebsiteIds = jQuery( '.dashboard_wp_id' ).map( function ( indx, el ) {
    return jQuery( el ).val();
  } );
  var globalSync = true;
  var selectedIds = [ ], excludeIds = [ ];
  if ( syncSiteIds instanceof Array ) {
    jQuery.grep( allWebsiteIds, function ( el ) {
      if ( jQuery.inArray( el, syncSiteIds ) !== -1 ) {
        selectedIds.push( el );
      } else {
        excludeIds.push( el );
      }
    } );
    for ( var i = 0; i < excludeIds.length; i++ ){
      dashboard_update_site_hide( excludeIds[i] );
    }
    allWebsiteIds = selectedIds;
    globalSync = false;
  }

  for ( var i = 0; i < allWebsiteIds.length; i++ ){
    dashboard_update_site_status( allWebsiteIds[i], '<i class="clock outline icon"></i>' );
  }

  var nrOfWebsites = allWebsiteIds.length;

  mainwpPopup( '#mainwp-sync-sites-modal' ).init( {
    title: __( 'Data Synchronization' ),
    total: allWebsiteIds.length,
    pMax: nrOfWebsites,
    callback: function () {
      bulkTaskRunning = false;
      history.pushState("", document.title, window.location.pathname + window.location.search); // to fix issue for url with hash
      window.location.href = location.href;
  } } );

  dashboard_update(allWebsiteIds, globalSync);

    if ( nrOfWebsites > 0) {
        var data = {
            action:'mainwp_status_saving',
            status: 'last_sync_sites',
            isGlobalSync: globalSync
        };
        jQuery.post(ajaxurl, mainwp_secure_data(data), function () {

        });
    }

};

var websitesToUpdate = [ ];
var websitesTotal = 0;
var websitesLeft = 0;
var websitesDone = 0;
var currentWebsite = 0;
var bulkTaskRunning = false;
var currentThreads = 0;
var maxThreads = mainwpParams['maximumSyncRequests'] == undefined ? 8 : mainwpParams['maximumSyncRequests'];
var globalSync = true;

dashboard_update = function ( websiteIds, isGlobalSync) {
  websitesToUpdate = websiteIds;
  currentWebsite = 0;
  websitesDone = 0;
  websitesTotal = websitesLeft = websitesToUpdate.length;
  globalSync = isGlobalSync;

  bulkTaskRunning = true;

  if ( websitesTotal == 0 ) {
    dashboard_update_done();
  } else {
    dashboard_loop_next();
  }
};

dashboard_update_site_status = function ( siteId, newStatus, isSuccess ) {
  jQuery( '.sync-site-status[siteid="' + siteId + '"]' ).html( newStatus );
  // Move successfully synced site to the bottom of the sync list
  if ( typeof isSuccess !== 'undefined' && isSuccess ) {
    var row = jQuery( '.sync-site-status[siteid="' + siteId + '"]' ).closest( '.item' );
    jQuery( row ).insertAfter( jQuery( "#sync-sites-status .item" ).not( '.disconnected-site' ).last() );
  }
};

dashboard_update_site_hide = function ( siteId ) {
  jQuery( '.sync-site-status[siteid="' + siteId + '"]' ).closest( '.item' ).hide();
};

dashboard_loop_next = function () {
  while ( bulkTaskRunning && ( currentThreads < maxThreads ) && ( websitesLeft > 0 ) ) {
    dashboard_update_next();
  }
};

dashboard_update_done = function () {
  currentThreads--;
  if ( !bulkTaskRunning )
    return;
  websitesDone++;
  if ( websitesDone > websitesTotal )
    websitesDone = websitesTotal;

  mainwpPopup( '#mainwp-sync-sites-modal' ).setProgressValue( websitesDone );

  if ( websitesDone == websitesTotal ) {
    setTimeout( function () {
      bulkTaskRunning = false;
      mainwpPopup( '#mainwp-sync-sites-modal' ).close(true);
    }, 3000 );
    return;
  }

  dashboard_loop_next();
};

dashboard_update_next = function () {
  currentThreads++;
  websitesLeft--;
  var websiteId = websitesToUpdate[currentWebsite++];
  dashboard_update_site_status( websiteId, '<i class="sync alternate loading icon"></i>' );
  var data = mainwp_secure_data( {
    action: 'mainwp_syncsites',
    wp_id: websiteId,
    isGlobalSync: globalSync
  } );
  dashboard_update_next_int( websiteId, data, 0 );
};

dashboard_update_next_int = function ( websiteId, data, errors ) {
  jQuery.ajax( {
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function ( pWebsiteId ) {
      return function ( response ) {
        if ( response.error ) {
          dashboard_update_site_status( pWebsiteId, '<i class="exclamation red icon"></i>' );
        } else {
          dashboard_update_site_status( websiteId, '<i class="check green icon"></i>', true );
        }
          dashboard_update_done();
        }
    }( websiteId ),
    error: function ( pWebsiteId, pData, pErrors ) {
      return function () {
        if ( pErrors > 5 ) {
          dashboard_update_site_status( pWebsiteId, '<i class="exclamation yellow icon"></i>' );
          dashboard_update_done();
        } else {
          pErrors++;
          dashboard_update_next_int( pWebsiteId, pData, pErrors );
        }
      }
    }( websiteId, data, errors ),
    dataType: 'json'
  } );
};


mainwp_tool_disconnect_sites = function () {

  var allWebsiteIds = jQuery( '.dashboard_wp_id' ).map( function ( indx, el ) {
    return jQuery( el ).val();
  } );

  for ( var i = 0; i < allWebsiteIds.length; i++ ){
    dashboard_update_site_status( allWebsiteIds[i], '<i class="clock outline icon"></i>' );
  }

  var nrOfWebsites = allWebsiteIds.length;

  mainwpPopup( '#mainwp-sync-sites-modal' ).init( {
    title: __( 'Disconnect All Sites' ),
    total: allWebsiteIds.length,
    pMax: nrOfWebsites,
    statusText: __( 'disconnected' ),
    callback: function () {
      window.location.href = location.href;
  } } );

  websitesToUpdate = allWebsiteIds;
  currentWebsite = 0;
  websitesDone = 0;
  websitesTotal = websitesLeft = websitesToUpdate.length;

  bulkTaskRunning = true;

  if ( websitesTotal == 0 ) {
    mainwp_tool_disconnect_sites_done();
  } else {
    mainwp_tool_disconnect_sites_loop_next();
  }
};

mainwp_tool_disconnect_sites_done = function () {
  currentThreads--;
  if ( !bulkTaskRunning )
    return;
  websitesDone++;
  if ( websitesDone > websitesTotal )
    websitesDone = websitesTotal;

  mainwpPopup( '#mainwp-sync-sites-modal' ).setProgressValue( websitesDone );

  mainwp_tool_disconnect_sites_loop_next();
};

mainwp_tool_disconnect_sites_loop_next = function () {
  while ( bulkTaskRunning && ( currentThreads < maxThreads ) && ( websitesLeft > 0 ) ) {
    mainwp_tool_disconnect_sites_next();
  }
};

mainwp_tool_disconnect_sites_next = function () {
  currentThreads++;
  websitesLeft--;
  var websiteId = websitesToUpdate[currentWebsite++];
  dashboard_update_site_status( websiteId, '<i class="sync alternate loading icon"></i>' );
  var data = mainwp_secure_data( {
    action: 'mainwp_disconnect_site',
    wp_id: websiteId
  } );
  mainwp_tool_disconnect_sites_next_int( websiteId, data, 0 );
};

mainwp_tool_disconnect_sites_next_int = function ( websiteId, data, errors ) {
  jQuery.ajax( {
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function ( pWebsiteId ) {
      return function ( response ) {
            if ( response && response.error ) {
              dashboard_update_site_status( pWebsiteId, response.error + '<i class="exclamation red icon"></i>' );
            } else if (response && response.result == 'success') {
              dashboard_update_site_status( websiteId, '<i class="check green icon"></i>', true );
            } else {
                dashboard_update_site_status( pWebsiteId, __( 'Undefined error!' ) + ' <i class="exclamation red icon"></i>' );
            }
            mainwp_tool_disconnect_sites_done();
        }
    }( websiteId ),
    error: function ( pWebsiteId, pData, pErrors ) {
      return function () {
        if ( pErrors > 5 ) {
          dashboard_update_site_status( pWebsiteId, '<i class="exclamation yellow icon"></i>' );
          mainwp_tool_disconnect_sites_done();
        } else {
          pErrors++;
          mainwp_tool_disconnect_sites_next_int( pWebsiteId, pData, pErrors );
        }
      }
    }( websiteId, data, errors ),
    dataType: 'json'
  } );
};

// not used....
mainwp_links_visit_site_and_admin = function(pUrl, pSiteId ) {
    return '<a href="' + pUrl + '" target="_blank" class="mainwp-may-hide-referrer">View Site</a> | <a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=' + pSiteId + '" target="_blank">WP Admin</a>';
}

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

// not used ?
updatesoverview_show = function ( what, leave_text ) {
    jQuery( '#wp_' + what ).toggle( 100, 'linear', function () {
        if ( !leave_text ) {
            if ( jQuery( '#wp_' + what ).css( 'display' ) == 'none' ) {
                jQuery( '#mainwp_' + what + '_show' ).html( ( what == 'securityissues' ? '<i class="eye slash icon"></i> ' + __( 'Show all' ) : '<i class="eye slash icon"></i> ' + __( 'Show' ) ) );
            } else {
                jQuery( '#mainwp_' + what + '_show' ).html( ( what == 'securityissues' ? '<i class="eye slash icon"></i> ' + __( 'Hide all' ) : '<i class="eye slash icon"></i> ' + __( 'Hide' ) ) );
            }
        }
    } );
    return false;
};
/**
 * Manage backups page
 */
jQuery( document ).ready( function () {
    jQuery( '.backup_destination_exclude' ).live( 'click', function ()
    {
        jQuery( this ).parent().parent().animate( { height: 0 }, { duration: 'slow', complete: function () {
                jQuery( this ).remove();
            } } );
    } );
    jQuery( '#mainwp_managebackups_add' ).on( 'click', function ( event ) {
        mainwp_managebackups_add( event );
    } );
    jQuery( '#mainwp_managebackups_update' ).on( 'click', function ( event ) {
        mainwp_managebackups_update( event );
    } );
    jQuery( '.backup_run_now' ).live( 'click', function ()
    {
        managebackups_run_now( jQuery( this ) );
        return false;
    } );
    jQuery( '#managebackups-task-status-close' ).live( 'click', function ()
    {
        backupDownloadRunning = false;
        mainwpPopup( '#managebackups-task-status-box' ).close(true);
    } );
    managebackups_init();

} );
managebackups_exclude_folder = function ( pElement )
{
    var folder = pElement.parent().attr( 'rel' ) + "\n";
    if ( jQuery( '#excluded_folders_list' ).val().indexOf( folder ) !== -1 )
        return;

    jQuery( '#excluded_folders_list' ).val( jQuery( '#excluded_folders_list' ).val() + folder );
};

var manageBackupsError = false;
var manageBackupsTaskRemoteDestinations;
var manageBackupsTaskId;
var manageBackupsTaskType;
var manageBackupsTaskError;
managebackups_run_now = function ( el )
{
    el = jQuery( el );
    el.hide();
    el.parent().find( '.backup_run_loading' ).show();
    mainwpPopup( '#managebackups-task-status-box' ).getContentEl().html( dateToHMS( new Date() ) + ' ' + __( 'Starting the backup task...' ) );
    jQuery( '#managebackups-task-status-close' ).prop( 'value', __( 'Cancel' ) );
    mainwpPopup( '#managebackups-task-status-box' ).init( { title: __( 'Running task' ), callback: function () {
            location.reload();
        } } );

    var taskId = el.attr( 'task_id' );
    var taskType = el.attr( 'task_type' );
    //Fetch the sites to backup
    var data = mainwp_secure_data( {
        action: 'mainwp_backuptask_get_sites',
        task_id: taskId
    } );

    manageBackupsError = false;

    jQuery.post( ajaxurl, data, function ( pTaskId, pTaskType ) {
        return function ( response ) {
            manageBackupTaskSites = response.result.sites;
            manageBackupsTaskRemoteDestinations = response.result.remoteDestinations;
            manageBackupsTaskId = pTaskId;
            manageBackupsTaskType = pTaskType;
            manageBackupsTaskError = false;

            managebackups_run_next();
        }
    }( taskId, taskType ), 'json' );
};
managebackups_run_next = function ()
{
    var backtaskContentEl = mainwpPopup( '#managebackups-task-status-box' ).getContentEl();
    if ( manageBackupTaskSites.length == 0 )
    {
        appendToDiv( backtaskContentEl, __( 'Backup task completed' ) + ( manageBackupsTaskError ? ' <span class="mainwp-red">' + __( 'with errors' ) + '</span>' : '' ) + '.' );

        jQuery( '#managebackups-task-status-close' ).prop( 'value', __( 'Close' ) );
        if ( !manageBackupsError )
        {
            setTimeout( function () {
                mainwpPopup( '#managebackups-task-status-box' ).close(true);
            }, 3000 );
        }
        return;
    }

    var siteId = manageBackupTaskSites[0]['id'];
    var siteName = manageBackupTaskSites[0]['name'];
    var size = manageBackupTaskSites[0][manageBackupsTaskType + 'size'];
    var fileNameUID = mainwp_uid();
    appendToDiv( backtaskContentEl, '[' + siteName + '] ' + __( 'Creating backup file.' ) + '<div id="managebackups-task-status-create-progress" siteId="' + siteId + '" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>' );

    manageBackupTaskSites.shift();
    var data = mainwp_secure_data( {
        action: 'mainwp_backuptask_run_site',
        task_id: manageBackupsTaskId,
        site_id: siteId,
        fileNameUID: fileNameUID
    } );

    jQuery( '#managebackups-task-status-create-progress[siteId="' + siteId + '"]' ).progress( { value: 0, total: size } );
    var interVal = setInterval( function () {
        var data = mainwp_secure_data( {
            action: 'mainwp_createbackup_getfilesize',
            type: manageBackupsTaskType,
            siteId: siteId,
            fileName: '',
            fileNameUID: fileNameUID
        } );
        jQuery.post( ajaxurl, data, function ( pSiteId ) {
            return function ( response ) {
                if ( response.error )
                    return;

                if ( backupCreateRunning )
                {
                    var progressBar = jQuery( '#managebackups-task-status-create-progress[siteId="' + pSiteId + '"]' );
                    if ( progressBar.progress( 'get value' ) < progressBar.progress( 'get total' ) )
                    {
                        progressBar.progress( 'set progress', response.size );
                    }
                }
            }
        }( siteId ), 'json' );
    }, 1000 );

    backupCreateRunning = true;

    jQuery.ajax( { url: ajaxurl,
        data: data,
        method: 'POST',
        success: function ( pTaskId, pSiteId, pSiteName, pRemoteDestinations, pInterVal ) {
            return function ( response ) {
                backupCreateRunning = false;
                clearInterval( pInterVal );

                var progressBar = jQuery( '#managebackups-task-status-create-progress[siteId="' + pSiteId + '"]' );
                progressBar.progress( 'set progress', parseFloat( progressBar.progress( 'get total' ) ) );

                if ( response.error )
                {
                    appendToDiv( backtaskContentEl, '[' + pSiteName + '] <span class="mainwp-red">Error: ' + getErrorMessage( response.error ) + '</span>' );
                    manageBackupsTaskError = true;
                    managebackups_run_next();
                } else
                {
                    appendToDiv( backtaskContentEl, '[' + pSiteName + '] ' + __( 'Backup file created successfully.' ) );

                    managebackups_backup_download_file( pSiteId, pSiteName, response.result.type, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, pRemoteDestinations );
                }
            }
        }( manageBackupsTaskId, siteId, siteName, manageBackupsTaskRemoteDestinations.slice( 0 ), interVal ),
        error: function ( pInterVal, pSiteName ) {
            return function () {
                backupCreateRunning = false;
                clearInterval( pInterVal );
                appendToDiv( backtaskContentEl, '[' + pSiteName + '] ' + '<span class="mainwp-red">ERROR: Backup timed out - <a href="https://mainwp.com/help/docs/mainwp-introduction/resolving-system-requirement-issues/">Please check this help document for more information and possible fixes</a></span>' );
            }
        }( interVal, siteName ), dataType: 'json' } );
};

managebackups_backup_download_file = function ( pSiteId, pSiteName, type, url, file, regexfile, size, subfolder, remote_destinations )
{
    var backtaskContentEl = mainwpPopup( '#managebackups-task-status-box' ).getContentEl();
    appendToDiv( backtaskContentEl, '[' + pSiteName + '] Downloading the file. <div id="managebackups-task-status-progress" siteId="' + pSiteId + '" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>' );
    jQuery( '#managebackups-task-status-progress[siteId="' + pSiteId + '"]' ).progress( { value: 0, total: size } );
    var interVal = setInterval( function () {
        var data = mainwp_secure_data( {
            action: 'mainwp_backup_getfilesize',
            local: file
        } );
        jQuery.post( ajaxurl, data, function ( pSiteId ) {
            return function ( response ) {
                if ( response.error )
                    return;

                if ( backupDownloadRunning )
                {
                    var progressBar = jQuery( '#managebackups-task-status-progress[siteId="' + pSiteId + '"]' );
                    if ( progressBar.progress( 'get value' ) < progressBar.progress( 'get total' ) )
                    {
                        progressBar.progress( 'set progress', response.result );
                    }
                }
            }
        }( pSiteId ), 'json' );
    }, 500 );

    var data = mainwp_secure_data( {
        action: 'mainwp_backup_download_file',
        site_id: pSiteId,
        type: type,
        url: url,
        local: file
    } );
    backupDownloadRunning = true;
    jQuery.post( ajaxurl, data, function ( pFile, pRegexFile, pSubfolder, pRemoteDestinations, pSize, pType, pInterVal, pSiteName, pSiteId, pUrl ) {
        return function ( response ) {
            backupDownloadRunning = false;
            clearInterval( pInterVal );

            if ( response.error )
            {
                appendToDiv( backtaskContentEl, '[' + pSiteName + '] <span class="mainwp-red">ERROR: ' + getErrorMessage( response.error ) + '</span>' );
                appendToDiv( backtaskContentEl, '[' + pSiteName + '] <span class="mainwp-red">' + __( 'Backup failed!' ) + '</span>' );

                manageBackupsError = true;
                managebackups_run_next();
                return;
            }

            jQuery( '#managebackups-task-status-progress[siteId="' + pSiteId + '"]' ).progress( 'set progress', pSize );
            appendToDiv( backtaskContentEl, '[' + pSiteName + '] ' + __( 'Download from child site completed.' ) );


            var newData = mainwp_secure_data( {
                action: 'mainwp_backup_delete_file',
                site_id: pSiteId,
                file: pUrl
            } );
            jQuery.post( ajaxurl, newData, function () {}, 'json' );

            managebackups_backup_upload_file( pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize );
        }
    }( file, regexfile, subfolder, remote_destinations, size, type, interVal, pSiteName, pSiteId, url ), 'json' );
};

managebackups_backup_upload_file = function ( pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize )
{
    var backtaskContentEl = mainwpPopup( '#managebackups-task-status-box' ).getContentEl();
    if ( pRemoteDestinations.length > 0 )
    {
        var remote_destination = pRemoteDestinations[0];
        //upload..
        var unique = Date.now();
        appendToDiv( backtaskContentEl, '[' + pSiteName + '] ' + __( 'Uploading to selected remote destination: %1 (%2)', remote_destination.title, remote_destination.type ) + '<div id="managesite-upload-status-progress-' + unique + '" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>' );

        jQuery( '#managesite-upload-status-progress-' + unique ).progress( { value: 0, total: pSize } );

        var fnc = function ( pUnique ) {
            return function ( pFunction ) {
                var data2 = mainwp_secure_data( {
                    action: 'mainwp_backup_upload_getprogress',
                    unique: pUnique
                }, false );

                jQuery.ajax( {
                    url: ajaxurl,
                    data: data2,
                    method: 'POST',
                    success: function ( pFunc ) {
                        return function ( response ) {
                            if ( backupUploadRunning[pUnique] && response.error )
                            {
                                setTimeout( function () {
                                    pFunc( pFunc );
                                }, 1000 );
                                return;
                            }

                            if ( backupUploadRunning[pUnique] )
                            {
                                var progressBar = jQuery( '#managesite-upload-status-progress-' + pUnique );
                                if ( ( progressBar.length > 0 ) && ( progressBar.progress( 'get value' ) < progressBar.progress( 'get total' ) ) && ( progressBar.progress( 'get value' ) < parseInt( response.result ) ) )
                                {
                                    progressBar.progress( 'set progress', response.result );
                                }

                                setTimeout( function () {
                                    pFunc( pFunc );
                                }, 1000 );
                            }
                        }
                    }( pFunction ),
                    error: function ( pFunc ) {
                        return function () {
                            if ( backupUploadRunning[pUnique] ) {
                                setTimeout( function () {
                                    pFunc( pFunc );
                                }, 10000 );
                            }
                        }
                    }( pFunction ),
                    dataType: 'json' } );
            }
        }( unique );

        setTimeout( function () {
            fnc( fnc );
        }, 1000 );

        backupUploadRunning[unique] = true;

        var data = mainwp_secure_data( {
            action: 'mainwp_backup_upload_file',
            file: pFile,
            siteId: pSiteId,
            regexfile: pRegexFile,
            subfolder: pSubfolder,
            type: pType,
            remote_destination: remote_destination.id,
            unique: unique
        } );

        pRemoteDestinations.shift();
        jQuery.ajax( {
            type: 'POST',
            url: ajaxurl,
            data: data,
            success: function ( pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId ) {
                return function ( response ) {
                    if ( !response || response.error || !response.result )
                    {
                        managebackups_backup_upload_file_retry_fail( pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, response.error ? response.error : '' );
                    } else
                    {
                        backupUploadRunning[pUnique] = false;

                        var progressBar = jQuery( '#managesite-upload-status-progress-' + pUnique );
                        progressBar.progress( 'set progress', pSize );

                        var obj = response.result;
                        if ( obj.error )
                        {
                            manageBackupsError = true;
                            appendToDiv( backtaskContentEl, '<span class="mainwp-red">[' + pSiteName + '] ' + __( 'Upload to %1 (%2) failed:', obj.title, obj.type ) + ' ' + obj.error + '</span>' );
                        } else
                        {
                            appendToDiv( backtaskContentEl, '[' + pSiteName + '] ' + __( 'Upload to %1 (%2) successful', obj.title, obj.type ) );
                        }

                        managebackups_backup_upload_file( pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize );
                    }
                }
            }( pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, data, unique, remote_destination.id ),
            error: function ( pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId ) {
                return function () {
                    managebackups_backup_upload_file_retry_fail( pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId );
                }
            }( pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, data, unique, remote_destination.id ),
            dataType: 'json'
        } );
    } else
    {
        appendToDiv( backtaskContentEl, '[' + pSiteName + '] ' + __( 'Backup completed.' ) );
        managebackups_run_next();
    }
};

managebackups_backup_upload_file_retry_fail = function ( pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError )
{
    var backtaskContentEl = mainwpPopup( '#managebackups-task-status-box' ).getContentEl();
    //we've got the pid file!!!!
    var data = mainwp_secure_data( {
        action: 'mainwp_backup_upload_checkstatus',
        unique: pUnique,
        remote_destination: pRemoteDestId
    } );

    jQuery.ajax( {
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function ( response ) {
            if ( response.status == 'done' )
            {
                backupUploadRunning[pUnique] = false;

                var progressBar = jQuery( '#managesite-upload-status-progress-' + pUnique );
                progressBar.progress( 'set progress', pSize );

                appendToDiv( backtaskContentEl, '[' + pSiteName + '] ' + __( 'Upload to %1 (%2) successful', response.info.title, response.info.type ) );

                managebackups_backup_upload_file( pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize );
            } else if ( response.status == 'busy' )
            {
                //Try again in 10seconds
                setTimeout( function () {
                    managebackups_backup_upload_file_retry_fail( pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError );
                }, 10000 );
            } else if ( response.status == 'stalled' )
            {
                if ( backupContinueRetriesUnique[pUnique] == undefined )
                {
                    backupContinueRetriesUnique[pUnique] = 1;
                } else
                {
                    backupContinueRetriesUnique[pUnique]++;
                }

                if ( backupContinueRetriesUnique[pUnique] > 10 )
                {
                    if ( responseError != undefined )
                    {
                        manageBackupsError = true;
                        appendToDiv( backtaskContentEl, '<span class="mainwp-red">[' + pSiteName + '] ' + __( 'Upload to %1 (%2) failed:', response.info.title, response.info.type ) + ' ' + responseError + '</span>' );
                    } else
                    {
                        appendToDiv( backtaskContentEl, ' <span class="mainwp-red">[' + pSiteName + '] ERROR: Upload timed out - <a href="http://docs.mainwp.com/backup-failed-php-ini-settings/">Please check this help document for more information and possible fixes</a></span>' );
                    }

                    managebackups_backup_upload_file( pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize );
                } else
                {
                    appendToDiv( backtaskContentEl, ' [' + pSiteName + '] Upload stalled, trying to resume from last position.' );

                    pData = mainwp_secure_data( pData ); //Rescure

                    jQuery.ajax( {
                        url: ajaxurl,
                        data: pData,
                        method: 'POST',
                        success: function ( pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId ) {
                            return function ( response ) {
                                if ( response.error || !response.result )
                                {
                                    managebackups_backup_upload_file_retry_fail( pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, response.error ? response.error : '' );
                                } else
                                {
                                    backupUploadRunning[pUnique] = false;

                                    var progressBar = jQuery( '#managesite-upload-status-progress-' + pUnique );
                                    progressBar.progress( 'set progress', pSize );

                                    var obj = response.result;
                                    if ( obj.error )
                                    {
                                        manageBackupsError = true;
                                        appendToDiv( backtaskContentEl, '<span class="mainwp-red">[' + pSiteName + '] ' + __( 'Upload to %1 (%2) failed:', obj.title, obj.type ) + ' ' + obj.error + '</span>' );
                                    } else
                                    {
                                        appendToDiv( backtaskContentEl, '[' + pSiteName + '] ' + __( 'Upload to %1 (%2) successful', obj.title, obj.type ) );
                                    }

                                    managebackups_backup_upload_file( pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize );
                                }
                            }
                        }( pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId ),
                        error: function ( pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId ) {
                            return function () {
                                managebackups_backup_upload_file_retry_fail( pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError );
                            }
                        }( pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId ),
                        dataType: 'json'
                    } );
                }
            } else
            {
                //Try again in 5seconds
                setTimeout( function () {
                    managebackups_backup_upload_file_retry_fail( pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError );
                }, 10000 );
            }
        },
        error: function () {
            //Try again in 10seconds
            setTimeout( function () {
                managebackups_backup_upload_file_retry_fail( pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError );
            }, 10000 );
        },
        dataType: 'json'
    } );
};

managebackups_init = function () {
    setVisible( '#mainwp_managebackups_add_errors', false );

    jQuery( '#mainwp_managebackups_add_errors' ).html();
    jQuery( '#mainwp_managebackups_add_message' ).html();
};

mainwp_managebackups_update = function () {
    managebackups_init();

    var errors = [ ];
    if ( jQuery( '#mainwp_managebackups_add_name' ).val() == '' ) {
        errors.push( __( 'Please enter a valid name for your backup task' ) );
    }

    if ( jQuery( '#select_by' ).val() == 'site' ) {
        var selected_sites = [ ];
        jQuery( "input[name='selected_sites[]']:checked" ).each( function () {
            selected_sites.push( jQuery( this ).val() );
        } );
        if ( selected_sites.length == 0 ) {
            errors.push( __( 'Please select websites or groups to add a backup task.' ) );
        }
    } else {
        var selected_groups = [ ];
        jQuery( "input[name='selected_groups[]']:checked" ).each( function () {
            selected_groups.push( jQuery( this ).val() );
        } );
        if ( selected_groups.length == 0 ) {
            errors.push( __( 'Please select websites or groups to add a backup task.' ) );
        }
    }

    if ( errors.length > 0 ) {
        feedback( 'mainwp-message-zone', errors.join( '<br />' ), 'red' );
    } else {
        feedback( 'mainwp-message-zone', __( 'Adding the task...' ), 'green' );

        jQuery( '#mainwp_managebackups_update' ).attr( 'disabled', 'true' ); //disable button to add..

        //var loadFilesBeforeZip = jQuery( '[name="mainwp_options_loadFilesBeforeZip"]:checked' ).val();
        var name = jQuery( '#mainwp_managebackups_add_name' ).val();
        name = name.replace( /"/g, '&quot;' );
        var data = mainwp_secure_data( {
            action: 'mainwp_updatebackup',
            id: jQuery( '#mainwp_managebackups_edit_id' ).val(),
            name: name,
            schedule: jQuery( '#mainwp-backup-task-schedule' ).val(),
            type: jQuery( '#mainwp-backup-type' ).val(),
            exclude: ( jQuery( '#mainwp-backup-type' ).val() == 'full' ? jQuery( '#excluded_folders_list' ).val() : '' ),
            excludebackup: ( jQuery( '#mainwp-known-backup-locations' ).attr( 'checked' ) ? 1 : 0 ),
            excludecache: ( jQuery( '#mainwp-known-cache-locations' ).attr( 'checked' ) ? 1 : 0 ),
            excludenonwp: ( jQuery( '#mainwp-non-wordpress-folders' ).attr( 'checked' ) ? 1 : 0 ),
            excludezip: ( jQuery( '#mainwp-zip-archives' ).attr( 'checked' ) ? 1 : 0 ),
            'groups[]': selected_groups,
            'sites[]': selected_sites,
            subfolder: jQuery( '#mainwp_managebackups_add_subfolder' ).val(),
//            remote_destinations: ( jQuery( '#backup_location_remote' ).hasClass( 'mainwp_action_down' ) ? jQuery.map( jQuery( '#backup_destination_list' ).find( 'input[name="remote_destinations[]"]' ), function ( el ) {
//                return jQuery( el ).val();
//            } ) : [ ] ),
            filename: jQuery( '#backup_filename' ).val(),
            archiveFormat: jQuery( '#mainwp_archiveFormat' ).val(),
            maximumFileDescriptorsOverride: jQuery( '#mainwp_options_maximumFileDescriptorsOverride_override' ).is( ':checked' ) ? 1 : 0,
            maximumFileDescriptorsAuto: ( jQuery( '#mainwp_maximumFileDescriptorsAuto' ).attr( 'checked' ) ? 1 : 0 ),
            maximumFileDescriptors: jQuery( '#mainwp_options_maximumFileDescriptors' ).val(),
            //loadFilesBeforeZip: loadFilesBeforeZip
        } );
        jQuery.post( ajaxurl, data, function ( response ) {
            managebackups_init();
            if ( response.error != undefined ) {
                feedback( 'mainwp-message-zone', response.error, 'red' );
            } else {
                //Message the backup task was added
                feedback( 'mainwp-message-zone', response.result, 'green' );
            }

            jQuery( '#mainwp_managebackups_update' ).removeAttr( 'disabled' ); //Enable add button
        }, 'json' );
    }
};
mainwp_managebackups_add = function () {
    managebackups_init();

    var errors = [ ];
    if ( jQuery( '#mainwp_managebackups_add_name' ).val() == '' ) {
        errors.push( __( 'Please enter a valid name for your backup task' ) );
    }

    if ( jQuery( '#select_by' ).val() == 'site' ) {
        var selected_sites = [ ];
        jQuery( "input[name='selected_sites[]']:checked" ).each( function () {
            selected_sites.push( jQuery( this ).val() );
        } );
        if ( selected_sites.length == 0 ) {
            errors.push( __( 'Please select websites or groups.' ) );
        }
    } else {
        var selected_groups = [ ];
        jQuery( "input[name='selected_groups[]']:checked" ).each( function () {
            selected_groups.push( jQuery( this ).val() );
        } );
        if ( selected_groups.length == 0 ) {
            errors.push(  __( 'Please select websites or groups.' ) );
        }
    }

    console.log(errors);

    if ( errors.length > 0 ) {
        feedback( 'mainwp-message-zone', errors.join( '<br />' ), 'red' );
    } else {
        feedback( 'mainwp-message-zone', __( 'Adding the task...' ), 'green' );

        jQuery( '#mainwp_managebackups_add' ).attr( 'disabled', 'true' ); //disable button to add..

        jQuery( '#mainwp_managesites_add' ).attr( 'disabled', 'true' ); //Disable add button
        var loadFilesBeforeZip = jQuery( '[name="mainwp_options_loadFilesBeforeZip"]:checked' ).val();
        var name = jQuery( '#mainwp_managebackups_add_name' ).val();
        name = name.replace( /"/g, '&quot;' );
        var data = mainwp_secure_data( {
            action: 'mainwp_addbackup',
            name: name,
            schedule: jQuery( '#mainwp-backup-task-schedule' ).val(),
            type: jQuery( '#mainwp-backup-type' ).val(),
            exclude: ( jQuery( '#mainwp-backup-type' ).val() == 'full' ? jQuery( '#excluded_folders_list' ).val() : '' ),
            excludebackup: ( jQuery( '#mainwp-known-backup-locations' ).attr( 'checked' ) ? 1 : 0 ),
            excludecache: ( jQuery( '#mainwp-known-cache-locations' ).attr( 'checked' ) ? 1 : 0 ),
            excludenonwp: ( jQuery( '#mainwp-non-wordpress-folders' ).attr( 'checked' ) ? 1 : 0 ),
            excludezip: ( jQuery( '#mainwp-zip-archives' ).attr( 'checked' ) ? 1 : 0 ),
            'groups[]': selected_groups,
            'sites[]': selected_sites,
            subfolder: jQuery( '#mainwp_managebackups_add_subfolder' ).val(),
//            remote_destinations: ( jQuery( '#backup_location_remote' ).hasClass( 'mainwp_action_down' ) ? jQuery.map( jQuery( '#backup_destination_list' ).find( 'input[name="remote_destinations[]"]' ), function ( el ) {
//                return jQuery( el ).val();
//            } ) : [ ] ),
            filename: jQuery( '#backup_filename' ).val(),
            archiveFormat: jQuery( '#mainwp_archiveFormat' ).val(),
            maximumFileDescriptorsOverride: jQuery( '#mainwp_options_maximumFileDescriptorsOverride_override' ).is( ':checked' ) ? 1 : 0,
            maximumFileDescriptorsAuto: ( jQuery( '#mainwp_maximumFileDescriptorsAuto' ).attr( 'checked' ) ? 1 : 0 ),
            maximumFileDescriptors: jQuery( '#mainwp_options_maximumFileDescriptors' ).val(),
            loadFilesBeforeZip: loadFilesBeforeZip
        } );
        jQuery.post( ajaxurl, data, function ( response ) {
            managebackups_init();
            if ( response.error != undefined ) {
                feedback( 'mainwp-message-zone', response.error, 'red' );
            } else {
                //Message the backup task was added
                location.href = 'admin.php?page=ManageBackups&a=1';
                feedback( 'mainwp-message-zone', response.result, 'green' );
            }
            jQuery( '#mainwp_managebackups_add' ).removeAttr( 'disabled' ); //Enable add button
        }, 'json' );
    }
};
managebackups_remove = function ( element ) {
    var id = jQuery( element ).attr( 'task_id' );
    managebackups_init();

    var msg = __( 'Are you sure you want to delete this backup task?' );
    mainwp_confirm(msg, function(){
            jQuery( '#task-status-' + id ).html( __( 'Removing the task...' ) );
            var data = mainwp_secure_data( {
                action: 'mainwp_removebackup',
                id: id
            } );
            jQuery.post( ajaxurl, data, function ( pElement ) {
                return function ( response ) {
                    managebackups_init();
                    var result = '';
                    var error = '';
                    if ( response.error != undefined )
                    {
                        error = response.error;
                    } else if ( response.result == 'SUCCESS' ) {
                        result = __( 'The task has been removed.' );
                    } else {
                        error = __( 'An undefined error occured.' );
                    }

                    if ( error != '' ) {
                        setHtml( '#mainwp_managebackups', error );
                    }
                    if ( result != '' ) {
                        setHtml( '#mainwp_managebackups_add_message', result );
                    }
                    jQuery( '#task-status-' + id ).html( '' );
                    if ( error == '' ) {
                        jQuery( pElement ).closest( 'tr' ).remove();
                    }
                }
            }( element ), 'json' );
    });
    return false;
};
managebackups_resume = function ( element ) {
    var id = jQuery( element ).attr( 'task_id' );
    managebackups_init();

    jQuery( '#task-status-' + id ).html( __( 'Resuming the task...' ) );
    var data = mainwp_secure_data( {
        action: 'mainwp_resumebackup',
        id: id
    } );
    jQuery.post( ajaxurl, data, function ( pElement, pId ) {
        return function ( response ) {
            managebackups_init();
            var result = '';
            var error = '';
            if ( response.error != undefined )
            {
                error = response.error;
            } else if ( response.result == 'SUCCESS' ) {
                result = __( 'The task has been resumed.' );
            } else {
                error = __( 'An undefined error occured.' );
            }

            if ( error != '' ) {
                setHtml( '#mainwp_managebackups', error );
            }
            if ( result != '' ) {
                setHtml( '#mainwp_managebackups_add_message', result );
            }
            jQuery( '#task-status-' + id ).html( '' );

            if ( error == '' )
            {
                jQuery( pElement ).after( '<a href="#" task_id="' + pId + '" onClick="return managebackups_pause(this)">' + __( 'Pause' ) + '</a>' );
                jQuery( pElement ).remove();
            }
        }
    }( element, id ), 'json' );

    return false;
};
managebackups_pause = function ( element ) {
    var id = jQuery( element ).attr( 'task_id' );
    managebackups_init();

    jQuery( '#task-status-' + id ).html( __( 'Pausing the task...' ) );
    var data = mainwp_secure_data( {
        action: 'mainwp_pausebackup',
        id: id
    } );
    jQuery.post( ajaxurl, data, function ( pElement, pId ) {
        return function ( response ) {
            managebackups_init();
            var result = '';
            var error = '';
            if ( response.error != undefined )
            {
                error = response.error;
            } else if ( response.result == 'SUCCESS' ) {
                result = __( 'The task has been paused.' );
            } else {
                error = __( 'An undefined error occured.' );
            }

            if ( error != '' ) {
                setHtml( '#mainwp_managebackups', error );
            }
            if ( result != '' ) {
                setHtml( '#mainwp_managebackups_add_message', result );
            }
            jQuery( '#task-status-' + id ).html( '' );
            if ( error == '' )
            {
                jQuery( pElement ).after( '<a href="#" task_id="' + pId + '" onClick="return managebackups_resume(this)">' + __( 'Resume' ) + '</a>' );
                jQuery( pElement ).remove();
            }
        }
    }( element, id ), 'json' );

    return false;
};


/**
 * Manage sites page
 */
jQuery( document ).on( 'click', '#mainwp_backup_destinations', function () {
    jQuery( '.mainwp_backup_destinations' ).toggle();
    return false;
} );

jQuery( document ).ready( function () {
    jQuery( '#mainwp-backup-type' ).change( function () {
        if (jQuery(this).val() == 'full')
            jQuery( '.mainwp-backup-full-exclude' ).show();
        else
            jQuery( '.mainwp-backup-full-exclude' ).hide();
    } );
});

jQuery( document ).on( 'click', '.mainwp_action#backup_type_full', function () {
    jQuery( '.mainwp_action#backup_type_db' ).removeClass( 'mainwp_action_down' );
    jQuery( this ).addClass( 'mainwp_action_down' );
    jQuery( '[class^=mainwp-exclude]' ).show();
    jQuery( '.mainwp_backup_exclude_files_content' ).show();
    return false;
} );

/*   Suggested Excludes   */

jQuery( document ).on( 'click', '#mainwp-show-kbl-folders', function () {
    jQuery( '#mainwp-show-kbl-folders' ).hide();
    jQuery( '#mainwp-hide-kbl-folders' ).show();
    jQuery( '#mainwp-kbl-content' ).show();
    return false;
} );
jQuery( document ).on( 'click', '#mainwp-hide-kbl-folders', function () {
    jQuery( '#mainwp-show-kbl-folders' ).show();
    jQuery( '#mainwp-hide-kbl-folders' ).hide();
    jQuery( '#mainwp-kbl-content' ).hide();
    return false;
} );

jQuery( document ).on( 'click', '#mainwp-show-kcl-folders', function () {
    jQuery( '#mainwp-show-kcl-folders' ).hide();
    jQuery( '#mainwp-hide-kcl-folders' ).show();
    jQuery( '#mainwp-kcl-content' ).show();
    return false;
} );
jQuery( document ).on( 'click', '#mainwp-hide-kcl-folders', function () {
    jQuery( '#mainwp-show-kcl-folders' ).show();
    jQuery( '#mainwp-hide-kcl-folders' ).hide();
    jQuery( '#mainwp-kcl-content' ).hide();
    return false;
} );

jQuery( document ).on( 'click', '#mainwp-show-nwl-folders', function () {
    jQuery( '#mainwp-show-nwl-folders' ).hide();
    jQuery( '#mainwp-hide-nwl-folders' ).show();
    jQuery( '#mainwp-nwl-content' ).show();
    return false;
} );
jQuery( document ).on( 'click', '#mainwp-hide-nwl-folders', function () {
    jQuery( '#mainwp-show-nwl-folders' ).show();
    jQuery( '#mainwp-hide-nwl-folders' ).hide();
    jQuery( '#mainwp-nwl-content' ).hide();
    return false;
} );

jQuery( document ).on( 'click', '.mainwp_action#backup_type_db', function () {
    jQuery( '.mainwp_action#backup_type_full' ).removeClass( 'mainwp_action_down' );
    jQuery( this ).addClass( 'mainwp_action_down' );
    jQuery( '[class^=mainwp-exclude]' ).hide();
    jQuery( '.mainwp_backup_exclude_files_content' ).hide();
    return false;
} );
jQuery( document ).on( 'click', '.mainwp_action#backup_location_remote', function () {
    jQuery( '.mainwp_action#backup_location_local' ).removeClass( 'mainwp_action_down' );
    jQuery( this ).addClass( 'mainwp_action_down' );
    var backupDestinations = jQuery( '.mainwp_backup_destinations' );
    backupDestinations.show();
    if ( jQuery( 'input[name="remote_destinations[]"]' ).length == 0 )
        jQuery( '#addremotebackupdestination' ).trigger( 'click' );
    return false;
} );
jQuery( document ).on( 'click', '.mainwp_action#backup_location_local', function () {
    jQuery( '.mainwp_action#backup_location_remote' ).removeClass( 'mainwp_action_down' );
    jQuery( this ).addClass( 'mainwp_action_down' );
    jQuery( '.mainwp_backup_destinations' ).hide();
    return false;
} );
jQuery( document ).on( 'click', '.backuptaskschedule', function () {
    jQuery( '.backuptaskschedule' ).removeClass( 'mainwp_action_down' );
    jQuery( this ).addClass( 'mainwp_action_down' );
    return false;
} );
jQuery( document ).ready( function () {

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

    // Trigger the single site reconnect process
    jQuery('#mainwp-manage-sites-table').on( 'click', '.mainwp_site_reconnect', function () {
      mainwp_managesites_reconnect( jQuery( this ) );
      return false;
    } );

    jQuery( '.mainwp-updates-overview-reconnect-site' ).on( 'click', function () {
        mainwp_overview_reconnect( jQuery( this ) );
        return false;
    } );

    jQuery( ".chk-sync-install-plugin" ).change( function () {
        var parent = jQuery( this ).closest( '.sync-ext-row' );
        var opts = parent.find( ".sync-options input[type='checkbox']" );
        if ( jQuery( this ).is( ':checked' ) ) {
            //opts.removeAttr( "disabled" );
            opts.prop( "checked", true );
        } else {
            opts.prop( "checked", false );
            ///opts.attr( "disabled", "disabled" );
        }
    } );

    managesites_init();
} );

managesites_init = function () {
    jQuery( '#mainwp-message-zone' ).hide();
    jQuery( '.sync-ext-row span.status' ).html( '' );
    jQuery( '.sync-ext-row span.status' ).css( 'color', '#0073aa' );

    managesites_bulk_init();

};

mainwp_overview_reconnect = function ( pElement ) {
    var wrapElement = pElement.closest('.mainwp_wp_sync');
    var parent = pElement.parent();
    parent.html( '<i class="notched circle loading icon"></i> ' + 'Trying to reconnect. Please wait...' );

    var data = mainwp_secure_data( {
        action: 'mainwp_reconnectwp',
        siteid: wrapElement.attr( 'site_id' )
    } );

    jQuery.post( ajaxurl, data, function () {
        return function ( response ) {
            parent.hide();
            response = jQuery.trim( response );
            console.log(response);
            if ( response.substr( 0, 5 ) == 'ERROR' ) {
                var error;
                if ( response.length == 5 ) {
                  error = 'Undefined error! Please try again. If the process keeps failing, please contact the MainWP support.';
                } else {
                  error = response.substr( 6 );
                }
                feedback( 'mainwp-message-zone', error, 'red' );
            } else {
                location.reload();
            }
        }
    }());
};

mainwp_managesites_reconnect = function ( pElement ) {
  var wrapElement = pElement.closest('tr');
  wrapElement.html( '<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Trying to reconnect. Please wait...' + '</td>' );
  var data = mainwp_secure_data( {
    action: 'mainwp_reconnectwp',
    siteid: wrapElement.attr( 'siteid' )
  } );

  jQuery.post( ajaxurl, data, function ( pWrapElement ) {
    return function ( response ) {
      response = jQuery.trim( response );
      pWrapElement.hide(); // hide reconnect item
      if ( response.substr( 0, 5 ) == 'ERROR' ) {
        var error;
        if ( response.length == 5 ) {
          error = 'Undefined error! Please try again. If the process keeps failing, please contact the MainWP support.';
        } else {
          error = response.substr( 6 );
        }
        feedback( 'mainwp-message-zone', error, 'red' );
      } else {
        feedback( 'mainwp-message-zone', response, 'green' );
      }
      setTimeout( function () {
        window.location.reload()
      }, 6000 );
    }

  }( wrapElement ) );
};

// Connect a new website
mainwp_managesites_add = function () {
    managesites_init();

    var errors = [ ];

    if ( jQuery( '#mainwp_managesites_add_wpname' ).val() == '' ) {
      errors.push( __( 'Please enter a name for the website.' ) );
    }
    if ( jQuery( '#mainwp_managesites_add_wpurl' ).val() == '' ) {
      errors.push( __( 'Please enter a valid URL for your site.' ) );
    } else {
      var url = jQuery( '#mainwp_managesites_add_wpurl' ).val();
      if ( url.substr( -1 ) != '/' ) {
        url += '/';
      }

      jQuery( '#mainwp_managesites_add_wpurl' ).val( url );

      if ( !isUrl( jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val() + '://' + jQuery( '#mainwp_managesites_add_wpurl' ).val() ) ) {
        errors.push( __( 'Please enter a valid URL for your site.' ) );
      }
    }
    if ( jQuery( '#mainwp_managesites_add_wpadmin' ).val() == '' ) {
      errors.push( __( 'Please enter a username of the website administrator.' ) );
    }

    if ( errors.length > 0 ) {
      feedback( 'mainwp-message-zone', errors.join( '<br />' ), 'yellow' );
    } else {
      feedback( 'mainwp-message-zone', __( 'Adding the site to your MainWP Dashboard. Please wait...' ), 'green' );

      jQuery( '#mainwp_managesites_add' ).attr( 'disabled', 'true' ); //disable button to add..

      //Check if valid user & rulewp is installed?
      var url = jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val() + '://' + jQuery( '#mainwp_managesites_add_wpurl' ).val();
      if ( url.substr( -1 ) != '/' ) {
          url += '/';
      }

      var name = jQuery( '#mainwp_managesites_add_wpname' ).val();
          name = name.replace( /"/g, '&quot;' );

      var data = mainwp_secure_data( {
        action: 'mainwp_checkwp',
        name: name,
        url: url,
        admin: jQuery( '#mainwp_managesites_add_wpadmin' ).val(),
        verify_certificate: jQuery( '#mainwp_managesites_verify_certificate' ).val(),
        force_use_ipv4: jQuery( '#mainwp_managesites_force_use_ipv4' ).val(),
        ssl_version: jQuery( '#mainwp_managesites_ssl_version' ).val(),
        http_user: jQuery( '#mainwp_managesites_add_http_user' ).val(),
        http_pass: jQuery( '#mainwp_managesites_add_http_pass' ).val()
      } );

      jQuery.post( ajaxurl, data, function ( res_things ) {
        response = res_things.response;
        response = jQuery.trim( response );

        var url = jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val() + '://' + jQuery( '#mainwp_managesites_add_wpurl' ).val();
        if ( url.substr( -1 ) != '/' ) {
          url += '/';
        }

        url = url.replace( /"/g, '&quot;' );

        if ( response == 'HTTPERROR' ) {
          errors.push( __( 'This site can not be reached! Please use the Test Connection feature and see if the positive response will be returned. For additional help, contact the MainWP Support.' ) );
        } else if ( response == 'NOMAINWP' ) {
          errors.push( __( 'MainWP Child Plugin not detected! Please make sure that the MainWP Child plugin is installed and activated on the child site. For additional help, contact the MainWP Support.' ) );
        } else if ( response.substr( 0, 5 ) == 'ERROR' ) {
          if ( response.length == 5 ) {
            errors.push( __( 'Undefined error occurred. Please try again. If the issue does not resolve, please contact the MainWP Support.' ) );
          } else {
            errors.push( __( 'Error detected: ' ) + response.substr( 6 ) );
          }
        } else if ( response == 'OK' ) {
          jQuery( '#mainwp_managesites_add' ).attr( 'disabled', 'true' ); //Disable add button

          var name = jQuery( '#mainwp_managesites_add_wpname' ).val();
          name = name.replace( /"/g, '&quot;' );
          var group_ids = jQuery( '#mainwp_managesites_add_addgroups' ).dropdown('get value');
          var data = mainwp_secure_data( {
            action: 'mainwp_addwp',
            managesites_add_wpname: name,
            managesites_add_wpurl: url,
            managesites_add_wpadmin: jQuery( '#mainwp_managesites_add_wpadmin' ).val(),
            managesites_add_uniqueId: jQuery( '#mainwp_managesites_add_uniqueId' ).val(),
            groupids: group_ids,
            verify_certificate: jQuery( '#mainwp_managesites_verify_certificate' ).val(),
            force_use_ipv4: jQuery( '#mainwp_managesites_force_use_ipv4' ).val(),
            ssl_version: jQuery( '#mainwp_managesites_ssl_version' ).val(),
            managesites_add_http_user: jQuery( '#mainwp_managesites_add_http_user' ).val(),
            managesites_add_http_pass: jQuery( '#mainwp_managesites_add_http_pass' ).val(),
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
            var site_id = 0;
            if ( res_things.error ) {
              response = 'Error detected: ' + res_things.error;
            } else {
              response = res_things.response;
              site_id = res_things.siteid;
            }
            response = jQuery.trim( response );
            managesites_init();

            if ( response.substr( 0, 5 ) == 'ERROR' ) {
              jQuery( '#mainwp-message-zone' ).removeClass( 'green' );
              feedback( 'mainwp-message-zone', response.substr( 6 ), 'red' );
            } else {
              //Message the WP was added
              jQuery( '#mainwp-message-zone' ).removeClass( 'red' );
              feedback( 'mainwp-message-zone', response, 'green' );

              if ( site_id > 0 ) {
                jQuery( '.sync-ext-row' ).attr( 'status', 'queue' );
                setTimeout( function () {
                  mainwp_managesites_sync_extension_start_next( site_id );
                }, 1000 );
              }

              //Reset fields
              jQuery( '#mainwp_managesites_add_wpname' ).val( '' );
              jQuery( '#mainwp_managesites_add_wpurl' ).val( '' );
              jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val( 'https' );
              jQuery( '#mainwp_managesites_add_wpadmin' ).val( '' );
              jQuery( '#mainwp_managesites_add_uniqueId' ).val( '' );
              jQuery( '#mainwp_managesites_add_addgroups').dropdown('clear');
              jQuery( '#mainwp_managesites_verify_certificate' ).val( 1 );
              jQuery( '#mainwp_managesites_force_use_ipv4' ).val( 0 );
              jQuery( '#mainwp_managesites_ssl_version' ).val( 'auto' );

              jQuery( "input[name^='creport_token_']" ).each(function(){
                jQuery( this ).val( '' );
              } );

              // support hooks fields
              jQuery( ".mainwp_addition_fields_addsite input" ).each( function() {
                jQuery( this ).val('');
              } );

              //if ( res_things.redirectUrl != undefined ) {
              //  setTimeout( function ( pUrl ) {
              //    return function () {
              //      location.href = pUrl;
              //    }
              //  }( res_things.redirectUrl ), 1000 );
              //}
              }

              jQuery( '#mainwp_managesites_add' ).removeAttr( 'disabled' ); //Enable add button
              }, 'json' );
            }
            if ( errors.length > 0 ) {
              jQuery( '#mainwp-message-zone' ).removeClass( 'green' );
              managesites_init();
              jQuery( '#mainwp_managesites_add' ).removeAttr( 'disabled' ); //Enable add button
              feedback( 'mainwp-message-zone', errors.join( '<br />' ), 'red' );
            }
        }, 'json' );
    }
};

mainwp_managesites_sync_extension_start_next = function ( siteId ) {
    while ( ( pluginToInstall = jQuery( '.sync-ext-row[status="queue"]:first' ) ) && ( pluginToInstall.length > 0 ) && ( bulkInstallCurrentThreads <  1 /* bulkInstallMaxThreads // to fix install plugins and apply settings failed issue */ ))
    {
        mainwp_managesites_sync_extension_start_specific( pluginToInstall, siteId );
    }

    if ( ( pluginToInstall.length == 0 ) && ( bulkInstallCurrentThreads == 0 ) )
    {
        jQuery( '#mwp_applying_ext_settings' ).remove();
    }
};

mainwp_managesites_sync_extension_start_specific = function ( pPluginToInstall, pSiteId ) {
    pPluginToInstall.attr( 'status', 'progress' );
    var syncGlobalSettings = pPluginToInstall.find( ".sync-global-options input[type='checkbox']:checked" ).length > 0 ? true : false;
    var install_plugin = pPluginToInstall.find( ".sync-install-plugin input[type='checkbox']:checked" ).length > 0 ? true : false;
    var apply_settings = pPluginToInstall.find( ".sync-options input[type='checkbox']:checked" ).length > 0 ? true : false;

    if ( syncGlobalSettings ) {
        mainwp_extension_apply_plugin_settings( pPluginToInstall, pSiteId, true );
    } else if ( install_plugin ) {
        mainwp_extension_prepareinstallplugin( pPluginToInstall, pSiteId );
    } else if ( apply_settings ) {
        mainwp_extension_apply_plugin_settings( pPluginToInstall, pSiteId, false );
    } else {
        mainwp_managesites_sync_extension_start_next( pSiteId );
        return;
    }
};

mainwp_extension_prepareinstallplugin = function ( pPluginToInstall, pSiteId ) {
    var site_Ids = [ ];
    site_Ids.push( pSiteId );
    bulkInstallCurrentThreads++;
    var plugin_slug = pPluginToInstall.find( ".sync-install-plugin" ).attr( 'slug' );
    var workingEl = pPluginToInstall.find( ".sync-install-plugin i" );
    var statusEl = pPluginToInstall.find( ".sync-install-plugin span.status" );

    var data = {
        action: 'mainwp_ext_prepareinstallplugintheme',
        type: 'plugin',
        slug: plugin_slug,
        'selected_sites[]': site_Ids,
        selected_by: 'site',
    };

    workingEl.show();
    statusEl.html( __( 'Preparing for installation...' ) );

    jQuery.post( ajaxurl, data, function ( response ) {
        workingEl.hide();
        if ( response.sites && response.sites[pSiteId] ) {
            statusEl.html( __( 'Installing...' ) );
            var data = mainwp_secure_data( {
                action: 'mainwp_ext_performinstallplugintheme',
                type: 'plugin',
                url: response.url,
                siteId: pSiteId,
                activatePlugin: true,
                overwrite: false,
            } );
            workingEl.show();
            jQuery.post( ajaxurl, data, function ( response ) {
                workingEl.hide();
                var apply_settings = false;
                var syc_msg = '';
                var _success = false;
                if ( ( response.ok != undefined ) && ( response.ok[pSiteId] != undefined ) ) {
                    syc_msg = __( 'Installation successful!' );
                    statusEl.html( syc_msg );
                    apply_settings = pPluginToInstall.find( ".sync-options input[type='checkbox']:checked" ).length > 0 ? true : false;
                    if ( apply_settings ) {
                        mainwp_extension_apply_plugin_settings( pPluginToInstall, pSiteId, false );
                    }
                    _success = true;
                } else if ( ( response.errors != undefined ) && ( response.errors[pSiteId] != undefined ) ) {
                    syc_msg = __( 'Installation failed!' ) + ': ' + response.errors[pSiteId][1];
                    statusEl.html( syc_msg );
                    statusEl.css( 'color', 'red' );
                } else {
                    syc_msg = __( 'Installation failed!' );
                    statusEl.html( syc_msg );
                    statusEl.css( 'color', 'red' );
                }

                if ( syc_msg != '' ) {
                    if ( _success )
                        syc_msg = '<span style="color:#0073aa">' + syc_msg + '!</span>';
                    else
                        syc_msg = '<span style="color:red">' + syc_msg + '!</span>';
                    jQuery( '#mainwp-message-zone' ).append( pPluginToInstall.find( ".sync-install-plugin" ).attr( 'plugin_name' ) + ' ' + syc_msg + '<br/>' );
                }

                if ( !apply_settings ) {
                    bulkInstallCurrentThreads--;
                    mainwp_managesites_sync_extension_start_next( pSiteId );
                }
            }, 'json' );
        } else {
            statusEl.css( 'color', 'red' );
            statusEl.html( __( 'Error while preparing the installation. Please, try again.' ) );
            bulkInstallCurrentThreads--;
        }
    }, 'json' );
}

mainwp_extension_apply_plugin_settings = function ( pPluginToInstall, pSiteId, pGlobal ) {
    var extSlug = pPluginToInstall.attr( 'slug' );
    var workingEl = pPluginToInstall.find( ".options-row i" );
    var statusEl = pPluginToInstall.find( ".options-row span.status" );
    if ( pGlobal )
        bulkInstallCurrentThreads++;

    var data = mainwp_secure_data( {
        action: 'mainwp_ext_applypluginsettings',
        ext_dir_slug: extSlug,
        siteId: pSiteId
    } );

    workingEl.show();
    statusEl.html( __( 'Applying settings...' ) );
    jQuery.post( ajaxurl, data, function ( response ) {
        workingEl.hide();
        var syc_msg = '';
        var _success = false;
        if ( response ) {
            if ( response.result && response.result == 'success' ) {
                var msg = '';
                if ( response.message != undefined ) {
                    msg = ' ' + response.message;
                }
                statusEl.html( __( 'Applying settings successful!' ) + msg );
                syc_msg = __( 'Successful' );
                _success = true
            } else if ( response.error != undefined ) {
                statusEl.html( __( 'Applying settings failed!' ) + ': ' + response.error );
                statusEl.css( 'color', 'red' );
                syc_msg = __( 'failed' );
            } else {
                statusEl.html( __( 'Applying settings failed!' ) );
                statusEl.css( 'color', 'red' );
                syc_msg = __( 'failed' );
            }
        } else {
            statusEl.html( __( 'Undefined error!' ) );
            statusEl.css( 'color', 'red' );
            syc_msg = __( 'failed' );
        }

        if ( syc_msg != '' ) {
            if ( _success )
                syc_msg = '<span style="color:#0073aa">' + syc_msg + '!</span>';
            else
                syc_msg = '<span style="color:red">' + syc_msg + '!</span>';
            if ( pGlobal ) {
                syc_msg = __( 'Apply global %1 options', pPluginToInstall.attr( 'ext_name' ) ) + ' ' + syc_msg + '<br/>';
            } else {
                syc_msg = __( 'Apply %1 settings', pPluginToInstall.find( '.sync-install-plugin' ).attr( 'plugin_name' ) ) + ' ' + syc_msg + '<br/>';
            }
            jQuery( '#mainwp-message-zone' ).append( syc_msg );
        }
        bulkInstallCurrentThreads--;
        mainwp_managesites_sync_extension_start_next( pSiteId );
    }, 'json' );
}

// Test Connection
mainwp_managesites_test = function () {
    var errors = [ ];

    if ( jQuery( '#mainwp_managesites_add_wpurl' ).val() == '' ) {
      errors.push( __( 'Please enter a valid URL for your site.' ) );
    } else {
      var clean_url = jQuery( '#mainwp_managesites_add_wpurl' ).val();
      var protocol = jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val();

      url = protocol + '://' + clean_url;

      if ( url.substr( -1 ) != '/' ) {
        url += '/';
      }

      if ( !isUrl( url ) ) {
        errors.push( __( 'Please enter a valid URL for your site' ) );
      }
    }

    if ( errors.length > 0 ) {
      feedback( 'mainwp-message-zone', errors.join( '<br />' ), 'red' );
    } else {
      jQuery( '#mainwp-test-connection-modal' ).modal( 'show' );
      jQuery( '#mainwp-test-connection-modal .dimmer' ).show();
      jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result' ).hide();

      var clean_url = jQuery( '#mainwp_managesites_add_wpurl' ).val();
      var protocol = jQuery( '#mainwp_managesites_add_wpurl_protocol' ).val();

      url = protocol + '://' + clean_url;

      if ( url.substr( -1 ) != '/' ) {
        url += '/';
      }

      var data = mainwp_secure_data( {
        action: 'mainwp_testwp',
        url: url,
        test_verify_cert: jQuery( '#mainwp_managesites_verify_certificate' ).val(),
        test_force_use_ipv4: jQuery( '#mainwp_managesites_force_use_ipv4' ).val(),
        test_ssl_version: jQuery( '#mainwp_managesites_ssl_version' ).val(),
        http_user: jQuery( '#mainwp_managesites_add_http_user' ).val(),
        http_pass: jQuery( '#mainwp_managesites_add_http_pass' ).val()
      } );

      jQuery.post( ajaxurl, data, function ( response ) {
        jQuery( '#mainwp-test-connection-modal .dimmer' ).hide();
        jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result i' ).removeClass( 'red green check times' );
        jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span' ).html( '' );
        jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header' ).html( '' );
        if ( response.error ) {
          if ( response.httpCode ) {
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result' ).show();
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result i' ).addClass( 'red times' );
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span' ).html( __( 'Connection failed!' ) );
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header' ).html( __( 'URL:' ) + ' ' + response.host + ' - ' + __( 'HTTP-code:' ) + ' ' + response.httpCode + ( response.httpCodeString ? ' (' + response.httpCodeString + ')' : '' ) + ' - ' + __( 'Error message: ' ) + ' ' + response.error );
          } else {
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result' ).show();
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result i' ).addClass( 'red times' );
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span' ).html( __( 'Connection test failed.' ) );
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header' ).html( __( 'Error message:' ) + ' ' + response.error );
          }
        } else if ( response.httpCode ) {
          if ( response.httpCode == '200' ) {
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result' ).show();
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result i' ).addClass( 'green check' );
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span' ).html( __( 'Connection successful!' ) );
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header' ).html( __( 'URL:' ) + ' ' + response.host + ( response.ip != undefined ? ' (IP: ' + response.ip + ')' : '' ) + ' - ' + __( 'Received HTTP-code' ) + ' ' + response.httpCode + ( response.httpCodeString ? ' (' + response.httpCodeString + ')' : '' ) );
          } else {
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result' ).show();
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result i' ).addClass( 'red times' );
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span' ).html( __( 'Connection test failed.' ) );
            jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header' ).html( __( 'URL:' ) + ' ' + response.host + ( response.ip != undefined ? ' (IP: ' + response.ip + ')' : '' ) + ' - ' + __( 'Received HTTP-code:' ) + ' ' + response.httpCode + ( response.httpCodeString ? ' (' + response.httpCodeString + ')' : '' ) );
          }
        } else {
          jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result' ).show( '' );
          jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result i' ).addClass( 'red times' );
          jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span' ).html( __( 'Connection test failed.' ) );
          jQuery( '#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header' ).html( __( 'Invalid response from the server, please try again.' ) );
        }
      }, 'json' );
    }
};
managesites_remove = function ( id ) {
  managesites_init();

  var msg = __( 'Are you sure you want to remove this site from your MainWP Dashboard?' );

  mainwp_confirm( msg, function() {
    jQuery( 'tr#child-site-' + id ).html( '<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Removing and deactivating the MainWP Child plugin! Please wait...' + '</td>' );
    var data = mainwp_secure_data( {
      action: 'mainwp_removesite',
      id: id
    } );

    jQuery.post( ajaxurl, data, function ( response ) {

      managesites_init();

      var result = '';
      var error = '';

      if ( response.error != undefined ) {
        error = response.error;
      } else if ( response.result == 'SUCCESS' ) {
        result = '<i class="close icon"></i>' + __( 'The site has been removed and the MainWP Child plugin has been disabled.' );
      } else if ( response.result == 'NOSITE' ) {
        error = '<i class="close icon"></i>' + __( 'The requested site has not been found.' );
      } else {
        result = '<i class="close icon"></i>' + __( 'The site has been removed but the MainWP Child plugin could not be disabled. Please deactive the MainWP Child plugin manually!' );
      }

      if ( error != '' ) {
        feedback( 'mainwp-message-zone', error, 'red' );
      }

      if ( result != '' ) {
        feedback( 'mainwp-message-zone', result, 'green' );
      }

      jQuery( 'tr#child-site-' + id ).remove();

    }, 'json' );
    });
    return false;
};


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
    jQuery( document ).on( 'click', '#mainwp_managesites_add', function (event) {
        mainwp_managesites_add( event );
    } );

    jQuery( '#mainwp_managesites_bulkadd' ).live( 'click', function () {
        if ( jQuery( '#mainwp_managesites_file_bulkupload' ).val() == '' ) {
            setHtml( '#mainwp-message-zone', __( 'Please enter csv file for upload.' ), false );
        } else {
            jQuery( '#mainwp_managesites_bulkadd_form' ).submit();
        }
        return false;
    } );

    // Trigger Connection Test
    jQuery( document ).on( 'click', '#mainwp_managesites_test', function (event) {
        mainwp_managesites_test( event );
    } );


    jQuery( '#mainwp_managesites_btn_import' ).live( 'click', function () {
        if ( import_stop_by_user == false ) {
            import_stop_by_user = true;
            jQuery( '#mainwp_managesites_import_logging .log' ).append( __( 'Paused import by user.' ) + "\n" );
            jQuery( '#mainwp_managesites_btn_import' ).val( __( 'Continue' ) );
            jQuery( '#mainwp_managesites_btn_save_csv' ).removeAttr( "disabled" ); //Enable
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

    jQuery( '#mainwp_managesites_btn_save_csv' ).live( 'click', function () {
        var fail_data = '';
        jQuery( '#mainwp_managesites_import_fail_logging span' ).each(function(){
            fail_data += jQuery(this).html() + "\r\n";
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
            jQuery( '#mainwp_managesites_btn_save_csv' ).removeAttr( "disabled" ); //Enable
        }
        jQuery( '#mainwp_managesites_import_logging .log' ).append( '\n' + __( 'Number of sites to Import: %1 Created sites: %2 Failed: %3', import_total, import_count_success, import_count_fails ) + "\n" );
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
    var import_ssl_version = decodedVal.ssl_version;

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

    jQuery( '#mainwp_managesites_import_logging .log' ).append( '[' + import_current + '] ' + import_line_orig );

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
        ssl_version: import_ssl_version,
        http_user: import_http_username,
        http_pass: import_http_password
    } );

    jQuery.post( ajaxurl, data, function ( res_things ) {
        response = res_things.response;

        var check_result = '[' + res_things.check_me + ']>> ';

        response = jQuery.trim( response );
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
                ssl_version: import_ssl_version,
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

                response = jQuery.trim( response );

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

mainwp_remove_all_cats = function ( me ) {
    var parent = jQuery( me ).closest( '.keyword' );
    if ( parent.length === 0 )
        parent = jQuery( me ).closest( '.keyword-bulk' );
    if ( parent.length === 0 )
        return;
    parent.find( '.selected_cats' ).html( '<p class="remove">' + __( 'No selected categories!' ) + '</p>' );  // remove all categories
};

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


/**
 * Add new user
 */
jQuery( document ).ready( function () {
    jQuery( '#bulk_add_createuser' ).live( 'click', function ( event ) {
        mainwp_createuser( event );
    } );
    jQuery( '#bulk_import_createuser' ).on( 'click', function () {
        mainwp_bulkupload_users();
    } );
} );

mainwp_createuser = function () {
    var cont = true;
    if ( jQuery( '#user_login' ).val() == '' ) {
      feedback( 'mainwp-message-zone', __( 'Username field is required! Please enter a username.' ), 'yellow' );
        cont = false;
    }

    if ( jQuery( '#email' ).val() == '' ) {
      feedback( 'mainwp-message-zone', __( 'E-mail field is required! Please enter an email address.' ), 'yellow' );
        cont = false;
    }

    if ( jQuery( '#password' ).val() == '' ) {
      feedback( 'mainwp-message-zone', __( 'Password field is required! Please enter the wanted password or generate a random one.' ), 'yellow' );
        cont = false;
    }

    if ( jQuery( '#select_by' ).val() == 'site' ) {
        var selected_sites = [ ];
        jQuery( "input[name='selected_sites[]']:checked" ).each( function () {
            selected_sites.push( jQuery( this ).val() );
        } );
        if ( selected_sites.length == 0 ) {
        feedback( 'mainwp-message-zone', __( 'Please select at least one website or group.' ), 'yellow' );
            cont = false;
        }
    } else {
        var selected_groups = [ ];
        jQuery( "input[name='selected_groups[]']:checked" ).each( function () {
            selected_groups.push( jQuery( this ).val() );
        } );
        if ( selected_groups.length == 0 ) {
        feedback( 'mainwp-message-zone', __( 'Please select at least one website or group.' ), 'yellow' );
            cont = false;
        }
    }

    if ( cont ) {
      jQuery( '#mainwp-message-zone' ).removeClass( 'red green yellow' );
      jQuery( '#mainwp-message-zone' ).html( '<i class="notched circle loading icon"></i> ' + __( 'Creating the user. Please wait...' ) );
      jQuery( '#mainwp-message-zone' ).show();
        jQuery( '#bulk_add_createuser' ).attr( 'disabled', 'disabled' );
        //Add user via ajax!!
        var data = mainwp_secure_data( {
            action: 'mainwp_bulkadduser',
            'select_by': jQuery( '#select_by' ).val(),
            'selected_groups[]': selected_groups,
            'selected_sites[]': selected_sites,
            'user_login': jQuery( '#user_login' ).val(),
            'email': jQuery( '#email' ).val(),
            'url': jQuery( '#url' ).val(),
            'first_name': jQuery( '#first_name' ).val(),
            'last_name': jQuery( '#last_name' ).val(),
            'pass1': jQuery( '#password' ).val(),
            'pass2': jQuery( '#password' ).val(),
            'send_password': jQuery( '#send_password' ).attr( 'checked' ),
            'role': jQuery( '#role' ).val()
        } );

        jQuery.post( ajaxurl, data, function ( response ) {
            response = jQuery.trim( response );
            jQuery( '#mainwp-message-zone' ).hide();
            jQuery( '#bulk_add_createuser' ).removeAttr( 'disabled' );
            if ( response.substring( 0, 5 ) == 'ERROR' ) {
                var responseObj = jQuery.parseJSON( response.substring( 6 ) );
                if ( responseObj.error == undefined ) {
                    var errorMessageList = responseObj[1];
                    var errorMessage = '';
                    for ( var i = 0; i < errorMessageList.length; i++ ) {
                        if ( errorMessage != '' )
                            errorMessage = errorMessage + "<br />";
                        errorMessage = errorMessage + errorMessageList[i];
                    }
                    if ( errorMessage != '' ) {
                        feedback( 'mainwp-message-zone', errorMessage, 'red' );
                    }
                }
            } else {
        jQuery( '#mainwp-add-new-user-form' ).append( response );
        jQuery( '#mainwp-creating-new-user-modal' ).modal( 'show' );

            }
        } );
    }
};


/**
 * Bulk upload new user
 */
var import_user_current_line_number = 0;
var import_user_total_import = 0;
var import_user_count_created_users = 0;
var import_user_count_create_fails = 0;

jQuery( document ).ready( function () {
    import_user_total_import = jQuery( '#import_user_total_import' ).val();

    jQuery( '#import_user_btn_import' ).on( 'click', function () {
        if ( import_stop_by_user == false ) {
            import_stop_by_user = true;
            jQuery( '#import_user_import_logging .log' ).append( _( 'Paused import by user.' ) + "\n" );
            jQuery( '#import_user_btn_import' ).val( __( 'Continue' ) );
            jQuery( '#MainWPBulkUploadUserLoading' ).hide();
            jQuery( '#import_user_btn_save_csv' ).removeAttr( 'disabled' ); //Enable
        } else
        {
            import_stop_by_user = false;
            jQuery( '#import_user_import_logging .log' ).append( __( 'Continue import.' ) + "\n" );
            jQuery( '#import_user_btn_import' ).val( __( 'Pause' ) );
            jQuery( '#MainWPBulkUploadUserLoading' ).show();
            jQuery( '#import_user_btn_save_csv' ).attr( 'disabled', 'true' ); // Disable
            mainwp_import_users();
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
        mainwp_import_users();
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

mainwp_import_users = function () {

    if ( import_stop_by_user == true )
        return;

    import_user_current_line_number++;

    if ( import_user_current_line_number > import_user_total_import ) {
        jQuery( '#import_user_btn_import' ).val( 'Finished' ).attr( 'disabled', 'true' );
        jQuery( '#MainWPBulkUploadUserLoading' ).hide();
        jQuery( '#import_user_import_logging .log' ).append( '\n' + __( 'Number of users to import: %1 Created users: %2 Failed: %3', import_user_total_import, import_user_count_created_users, import_user_count_create_fails ) + '\n' );
        if ( import_user_count_create_fails > 0 ) {
            jQuery( '#import_user_btn_save_csv' ).removeAttr( "disabled" ); //Enable
        }
        jQuery( '#import_user_import_logging' ).scrollTop( jQuery( '#import_user_import_logging .log' ).height() );
        return;
    }

    var import_data = jQuery( '#user_import_csv_line_' + import_user_current_line_number ).attr('encoded-data');
    var original_line = jQuery( '#user_import_csv_line_' + import_user_current_line_number ).attr('original-line');

    var decoded_data = false;
    var errors = [ ];

    try {
        var decoded_data = JSON.parse( import_data );
    } catch(e) {
        decoded_data = false
        errors.push( __( 'Invalid import data.' ) );
    }

    if ( decoded_data != false ) {
        var import_user_username = decoded_data.user_login == undefined ? '' : decoded_data.user_login;
        var import_user_email = decoded_data.email == undefined ? '' : decoded_data.email;
        var import_user_fname = decoded_data.first_name == undefined ? '' : decoded_data.first_name;
        var import_user_lname = decoded_data.last_name == undefined ? '' : decoded_data.last_name ;
        var import_user_website = decoded_data.url == undefined ? '' : decoded_data.url;
        var import_user_passw = decoded_data.pass1 == undefined ? '' : decoded_data.pass1;
        var import_user_send_passw = decoded_data.send_password == undefined ? '' : decoded_data.send_password;
        var import_user_role = decoded_data.role == undefined ? '' : decoded_data.role;
        var import_user_select_sites = decoded_data.select_sites == undefined ? '' : decoded_data.select_sites;
        var import_user_select_groups = decoded_data.select_groups == undefined ? '' : decoded_data.select_groups;
        var import_user_select_by = '';

        jQuery( '#import_user_import_logging .log' ).append( '[' + import_user_current_line_number + '] ' + original_line + '\n');

        if ( import_user_username == '' ) {
            errors.push( __( 'Please enter a username.' ) );
        }

        if ( import_user_email == '' ) {
            errors.push( __( 'Please enter an email.' ) );
        }

        if ( import_user_passw == '' ) {
            errors.push( __( 'Please enter a password.' ) );
        }

        allowed_roles = [ 'subscriber', 'administrator', 'editor', 'author', 'contributor' ];
        if ( jQuery.inArray( import_user_role, allowed_roles ) == -1 ) {
            errors.push( __( 'Please select a valid role.' ) );
        }

        if ( import_user_select_sites != '' ) {
            var selected_sites = import_user_select_sites.split( ';' );
            if ( selected_sites.length == 0 ) {
                errors.push( __( 'Please select websites or groups to add a user.' ) );
            } else
                import_user_select_by = 'site';
        } else {
            var selected_groups = import_user_select_groups.split( ';' );
            if ( selected_groups.length == 0 ) {
                errors.push( __( 'Please select websites or groups to add a user.' ) );
            } else
                import_user_select_by = 'group';
        }
    }

    if ( errors.length > 0 ) {
        jQuery( '#import_user_import_failed_rows' ).append( '<span>' + original_line + '</span>' );
        jQuery( '#import_user_import_logging .log' ).append( '[' + import_user_current_line_number + ']>> Error - ' + errors.join( " " ) + '\n' );
        import_user_count_create_fails++;
        mainwp_import_users();
        return;
    }

    //Add user via ajax!!
    var data = mainwp_secure_data( {
        action: 'mainwp_importuser',
        'select_by': import_user_select_by,
        'selected_groups[]': selected_groups,
        'selected_sites[]': selected_sites,
        'user_login': import_user_username,
        'email': import_user_email,
        'url': import_user_website,
        'first_name': import_user_fname,
        'last_name': import_user_lname,
        'pass1': import_user_passw,
        'send_password': import_user_send_passw,
        'role': import_user_role,
        'line_number': import_user_current_line_number
    } );

    jQuery.post( ajaxurl, data, function ( response_data ) {
            if ( response_data.error != undefined )
                return;

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
            mainwp_import_users();
    }, 'json' );
};



/**
 * InstallPlugins/Themes
 */
jQuery( document ).ready( function () {
    jQuery( '#MainWPInstallBulkNavSearch' ).on( 'click', function ( event ) {
        event.preventDefault();
        jQuery( '#mainwp_plugin_bulk_install_btn' ).attr( 'bulk-action', 'install' );
        jQuery( '.mainwp-browse-plugins' ).show();
        jQuery( '.mainwp-upload-plugin' ).hide();
        jQuery( '#mainwp-search-plugins-form' ).show();
    } );
    jQuery( '#MainWPInstallBulkNavUpload' ).on( 'click', function ( event ) {
        event.preventDefault();
        jQuery( '#mainwp_plugin_bulk_install_btn' ).attr( 'bulk-action', 'upload' );
        jQuery( '.mainwp-upload-plugin' ).show();
        jQuery( '.mainwp-browse-plugins' ).hide();
        jQuery( '#mainwp-search-plugins-form' ).hide();
    } );

// not used?
    jQuery( '.filter-links li.plugin-install a' ).live( 'click', function ( event ) {
        event.preventDefault();
        jQuery( '.filter-links li.plugin-install a' ).removeClass( 'current' );
        jQuery( this ).addClass( 'current' );
        var tab = jQuery( this ).parent().attr( 'tab' );
        if ( tab == 'search' ) {
            mainwp_install_search( event );
        } else {
            jQuery( '#mainwp_installbulk_s' ).val( '' );
            jQuery( '#mainwp_installbulk_tab' ).val( tab );
            mainwp_install_plugin_tab_search( 'tab:' + tab );
        }
    } );

    jQuery( document ).on( 'click', '#mainwp_plugin_bulk_install_btn', function () {
        var act = jQuery(this).attr('bulk-action');
        if ( act == 'install' ) {
            var selected = jQuery( "input[type='radio'][name='install-plugin']:checked" );
            if ( selected.length == 0 ) {
                feedback( 'mainwp-message-zone', __( 'Please select plugin to install files.' ), 'yellow' );
            } else {
                var selectedId = /^install-([^-]*)-(.*)$/.exec( selected.attr( 'id' ) );
                if ( selectedId ) {
                    mainwp_install_bulk( 'plugin', selectedId[2] );
                }
            }
        } else if ( act == 'upload' ) {
            mainwp_upload_bulk( 'plugins' );
        }

        return false;
    } );

    jQuery( document ).on( 'click', '#mainwp_theme_bulk_install_btn', function () {
        var act = jQuery(this).attr('bulk-action');
        if (act == 'install') {
            var selected = jQuery( "input[type='radio'][name='install-theme']:checked" );
            if ( selected.length == 0 ) {
                feedback( 'mainwp-message-zone', __( 'Please select theme to install files.' ), 'yellow' );
            } else {
                var selectedId = /^install-([^-]*)-(.*)$/.exec( selected.attr( 'id' ) );
                if ( selectedId )
                    mainwp_install_bulk( 'theme', selectedId[2] );
            }
        } else if (act == 'upload') {
            mainwp_upload_bulk('themes');
        }
        return false;
    } );
} );

mainwp_links_visit_site_and_admin = function(pUrl, pSiteId ) {
    var links = '';
    if (pUrl != '' )
        links += '<a href="' + pUrl + '" target="_blank" class="mainwp-may-hide-referrer">View Site</a> | ';
    links += '<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=' + pSiteId + '" target="_blank">Go to WP Admin</a>';
    return links;
}

bulkInstallTotal = 0;
bulkInstallDone = 0;

mainwp_install_bulk = function ( type, slug ) {
    var data = mainwp_secure_data( {
        action: 'mainwp_preparebulkinstallplugintheme',
        type: type,
        slug: slug,
        selected_by: jQuery( '#select_by' ).val()
    } );

    if ( jQuery( '#select_by' ).val() == 'site' ) {
        var selected_sites = [ ];
        jQuery( "input[name='selected_sites[]']:checked" ).each( function () {
            selected_sites.push( jQuery( this ).val() );
        } );

        if ( selected_sites.length == 0 ) {
            feedback( 'mainwp-message-zone', __( 'Please select at least one website or group.' ), 'yellow' );
            return;
        }

        data['selected_sites[]'] = selected_sites;

    } else {
        var selected_groups = [ ];

        jQuery( "input[name='selected_groups[]']:checked" ).each( function () {
            selected_groups.push( jQuery( this ).val() );
        } );

        if ( selected_groups.length == 0 ) {
            feedback( 'mainwp-message-zone', __( 'Please select at least one website or group.' ), 'yellow' );
            return;
        }

        data['selected_groups[]'] = selected_groups;
    }

    jQuery( '#plugintheme-installation-queue' ).html('');

    jQuery.post( ajaxurl, data, function ( pType, pActivatePlugin, pOverwrite ) {
        return function ( response ) {
            var installQueueContent = '<div class="ui middle aligned divided list">';

            var dateObj = new Date();
            starttimeDashboardAction = dateObj.getTime();
            if (pType == 'plugin')
                dashboardActionName = 'installing_new_plugin';
            else
                dashboardActionName = 'installing_new_theme';
            countRealItemsUpdated = 0;
            
            bulkInstallDone = 0;

            for ( var siteId in response.sites ) {
                      var site = response.sites[siteId];
                      installQueueContent += '<div class="siteBulkInstall item" siteid="' + siteId + '" status="queue">' +
                      '<div class="ui grid">' +
                      '<div class="two column row">' +
                      '<div class="column">' + site['name'].replace( /\\(.)/mg, "$1" ) + '</div>' +
                      '<div class="column">' +
                      '<span class="queue"><i class="clock outline icon"></i> ' + __( 'Queued' ) + '</span>' +
                      '<span class="progress" style="display:none"><i class="notched circle loading icon"></i> ' + __( 'Installing...' ) + '</span>' +
                      '<span class="status"></span>' +
                      '</div>' +
                      '</div>' +
                      '</div>' +
                      '</div>';
                      bulkInstallTotal++;
              }
            installQueueContent += '<div id="bulk_install_info"></div>';
            installQueueContent += '</div>';

            jQuery( '#plugintheme-installation-queue' ).html( installQueueContent );
            mainwp_install_bulk_start_next( pType, response.url, pActivatePlugin, pOverwrite );
        }
    }( type, jQuery( '#chk_activate_plugin' ).is( ':checked' ), jQuery( '#chk_overwrite' ).is( ':checked' ) ), 'json' );

    jQuery( '#plugintheme-installation-progress-modal' ).modal( 'show' );

};

bulkInstallMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
bulkInstallCurrentThreads = 0;


mainwp_install_bulk_start_next = function ( pType, pUrl, pActivatePlugin, pOverwrite ) {
    while ( ( siteToInstall = jQuery( '.siteBulkInstall[status="queue"]:first' ) ) && ( siteToInstall.length > 0 ) && ( bulkInstallCurrentThreads < bulkInstallMaxThreads ) ) {
        mainwp_install_bulk_start_specific( pType, pUrl, pActivatePlugin, pOverwrite, siteToInstall );
    }

    if (bulkInstallDone == bulkInstallTotal && bulkInstallTotal != 0) {
        if (mainwpParams.enabledTwit == true) {
            var dateObj = new Date();
            var countSec = (dateObj.getTime() - starttimeDashboardAction) / 1000;
            jQuery('#bulk_install_info').html('<i class="fa fa-spinner fa-pulse"></i>');
            if (countSec <= mainwpParams.maxSecondsTwit) {
                var data = mainwp_secure_data( {
                    action:'mainwp_twitter_dashboard_action',
                    actionName: dashboardActionName,
                    countSites: countRealItemsUpdated,
                    countSeconds: countSec,
                    countItems: 1,
                    countRealItems: countRealItemsUpdated,
                    showNotice: 1
                } );
                jQuery.post(ajaxurl, data, function (res) {
                    if (res && res != ''){
                        jQuery('#bulk_install_info').html(res);
                        if (typeof twttr !== "undefined")
                            twttr.widgets.load();
                    } else {
                        jQuery('#bulk_install_info').html('');
                    }
                });
            }
        }
        jQuery('#bulk_install_info').before('<div class="ui info message">' + mainwp_install_bulk_you_know_msg(pType, 1) + '</div>');
    }

};

mainwp_install_bulk_start_specific = function ( pType, pUrl, pActivatePlugin, pOverwrite, pSiteToInstall ) {
    bulkInstallCurrentThreads++;

    pSiteToInstall.attr( 'status', 'progress' );
    pSiteToInstall.find( '.queue' ).hide();
    pSiteToInstall.find( '.progress' ).show();

    var data = mainwp_secure_data( {
        action: 'mainwp_installbulkinstallplugintheme',
        type: pType,
        url: pUrl,
        activatePlugin: pActivatePlugin,
        overwrite: pOverwrite,
        siteId: pSiteToInstall.attr( 'siteid' )
    } );

  jQuery.post( ajaxurl, data, function ( pType, pUrl, pActivatePlugin, pOverwrite, pSiteToInstall ) {
    return function ( response ) {
            pSiteToInstall.attr( 'status', 'done' );
      pSiteToInstall.find( '.progress' ).hide();

            var statusEl = pSiteToInstall.find( '.status' );
            statusEl.show();

            if ( response.error != undefined ) {
                statusEl.html( response.error );
                statusEl.css( 'color', 'red' );
            } else if ( ( response.ok != undefined ) && ( response.ok[pSiteToInstall.attr( 'siteid' )] != undefined ) ) {
              statusEl.html( '<i class="check circle green icon"></i> ' + __( 'Installed successfully.' ) + ' ' + mainwp_links_visit_site_and_admin('', pSiteToInstall.attr( 'siteid' )) + '</span>' );
              countRealItemsUpdated++;
            } else if ( ( response.errors != undefined ) && ( response.errors[pSiteToInstall.attr( 'siteid' )] != undefined ) ) {
              statusEl.html( '<i class="times circle red icon"></i> ' + __( 'Installation failed!' ) + '(' + response.errors[pSiteToInstall.attr( 'siteid' )][1] + ')' ) ;
            } else {
              statusEl.html( '<i class="times circle red icon"></i> ' + __( 'Installation failed!' ) );
            }

            bulkInstallCurrentThreads--;
            bulkInstallDone++;

            mainwp_install_bulk_start_next( pType, pUrl, pActivatePlugin, pOverwrite );
        }
    }( pType, pUrl, pActivatePlugin, pOverwrite, pSiteToInstall ), 'json' );
};


mainwp_install_bulk_you_know_msg = function(pType, pTotal) {
    var msg = '';
    if (mainwpParams.installedBulkSettingsManager && mainwpParams.installedBulkSettingsManager == 1) {
        if (pType == 'plugin') {
            if (pTotal == 1)
                msg = __('Would you like to use the Bulk Settings Manager with this plugin? Check out the %1Documentation%2.', '<a href="http://docs.mainwp.com/category/mainwp-extensions/mainwp-bulk-settings-manager/" target="_blank">', '</a>');
            else
                msg = __('Would you like to use the Bulk Settings Manager with these plugins? Check out the %1Documentation%2.', '<a href="http://docs.mainwp.com/category/mainwp-extensions/mainwp-bulk-settings-manager/" target="_blank">', '</a>');
        } else {
            if (pTotal == 1)
                msg = __('Would you like to use the Bulk Settings Manager with this theme? Check out the %1Documentation%2.', '<a href="http://docs.mainwp.com/category/mainwp-extensions/mainwp-bulk-settings-manager/" target="_blank">', '</a>');
            else
                msg = __('Would you like to use the Bulk Settings Manager with these themes? Check out the %1Documentation%2.', '<a href="http://docs.mainwp.com/category/mainwp-extensions/mainwp-bulk-settings-manager/" target="_blank">', '</a>');
        }
    } else {
        if (pType == 'plugin') {
            if (pTotal == 1)
                msg = __('Did you know with the %1 you can control the settings of this plugin directly from your MainWP Dashboard?', '<a href="https://mainwp.com/extensions/bulk-settings-manager" target="_blank">Bulk Settings Extension</a>');
            else
                msg = __('Did you know with the %1 you can control the settings of these plugins directly from your MainWP Dashboard?', '<a href="https://mainwp.com/extensions/bulk-settings-manager" target="_blank">Bulk Settings Extension</a>');
        } else {
            if (pTotal == 1)
                msg = __('Did you know with the %1 you can control the settings of this theme directly from your MainWP Dashboard?', '<a href="https://mainwp.com/extensions/bulk-settings-manager" target="_blank">Bulk Settings Extension</a>');
            else
                msg = __('Did you know with the %1 you can control the settings of these themes directly from your MainWP Dashboard?', '<a href="https://mainwp.com/extensions/bulk-settings-manager" target="_blank">Bulk Settings Extension</a>');
        }
    }
    return msg;
}

// Install by Upload
mainwp_upload_bulk = function ( type ) {

    if ( type == 'plugins' ) {
        type = 'plugin';
    } else {
        type = 'theme';
    }

    var files = [ ];

    jQuery( ".qq-upload-file" ).each( function () {
        if ( jQuery( this ).closest('.file-uploaded-item').hasClass( 'qq-upload-success' )) {
            files.push( jQuery( this ).attr( 'filename' ) );
        }
    } );

    if ( files.length == 0 ) {
        if ( type == 'plugin' ) {
                feedback( 'mainwp-message-zone', __( 'Please upload plugins to install.' ), 'yellow' );
        } else {
                feedback( 'mainwp-message-zone', __( 'Please upload themes to install.' ), 'yellow' );
        }
        return;
    }

    var data = mainwp_secure_data( {
        action: 'mainwp_preparebulkuploadplugintheme',
        type: type,
        selected_by: jQuery( '#select_by' ).val()
    } );
    if ( jQuery( '#select_by' ).val() == 'site' ) {
        var selected_sites = [ ];
        jQuery( "input[name='selected_sites[]']:checked" ).each( function () {
            selected_sites.push( jQuery( this ).val() );
        } );

        if ( selected_sites.length == 0 ) {
            feedback( 'mainwp-message-zone', __( 'Please select websites or groups to install files.' ), 'yellow' );
            return;
        }
        data['selected_sites[]'] = selected_sites;
    } else {
        var selected_groups = [ ];
        jQuery( "input[name='selected_groups[]']:checked" ).each( function () {
            selected_groups.push( jQuery( this ).val() );
        } );
        if ( selected_groups.length == 0 ) {
            feedback( 'mainwp-message-zone', __( 'Please select websites or groups to install files.' ), 'yellow' );
            return;
        }
        data['selected_groups[]'] = selected_groups;
    }

    data['files[]'] = files;

    jQuery.post( ajaxurl, data, function ( pType, pFiles, pActivatePlugin, pOverwrite ) {
        return function ( response ) {
            var installQueue = '<div class="ui middle aligned divided list">';
            for ( var siteId in response.sites ) {
                var site = response.sites[siteId];
                //installQueue += '<span class="siteBulkInstall" siteid="' + siteId + '" status="queue"><strong>' + site['name'].replace( /\\(.)/mg, "$1" ) + '</strong>: <span class="queue"><i class="clock outline icon"></i> ' + __( 'Queued' ) + '</span><span class="progress"><i class="ui active inline loader tiny"></i> ' + __( 'Installing...' ) + '</span><span class="status"></span></span><br />';

                installQueue += '<div class="siteBulkInstall item" siteid="' + siteId + '" status="queue">' +
                      '<div class="ui grid">' +
                      '<div class="two column row">' +
                      '<div class="column">' + site['name'].replace( /\\(.)/mg, "$1" ) + '</div>' +
                      '<div class="column">' +
                      '<span class="queue"><i class="clock outline icon"></i> ' + __( 'Queued' ) + '</span>' +
                      '<span class="progress" style="display:none"><i class="notched circle loading icon"></i> ' + __( 'Installing...' ) + '</span>' +
                      '<span class="status"></span>' +
                      '</div>' +
                      '</div>' +
                      '</div>' +
                      '</div>';

            }
            //installQueue += '<div id="bulk_upload_info" number-files="' + pFiles.length + '"></div>';
            installQueue += '</div>';

            jQuery( '#plugintheme-installation-queue' ).html( installQueue );

            mainwp_upload_bulk_start_next( pType, response.urls, pActivatePlugin, pOverwrite );
        }
    }( type, files, jQuery( '#chk_activate_plugin' ).is( ':checked' ), jQuery( '#chk_overwrite' ).is( ':checked' ) ), 'json' );

    //jQuery( '#plugintheme-installation-queue' ).html( '<h3><i class="ui active inline loader tiny"></i> ' + __( 'Preparing %1 installation...', type ) + '</h3>' );

    jQuery( '#plugintheme-installation-progress-modal' ).modal('show');
//    .modal({onHide: function(){
//            if ( type == 'plugin' ) {
//                location.href = 'admin.php?page=PluginsInstall';
//            } else {
//                location.href = 'admin.php?page=ThemesInstall';
//            };
//    }}).modal('show');

     jQuery('.qq-upload-list').html(''); // empty files list
    return false;
};

mainwp_upload_bulk_start_next = function ( pType, pUrls, pActivatePlugin, pOverwrite ) {
    while ( ( siteToInstall = jQuery( '.siteBulkInstall[status="queue"]:first' ) ) && ( siteToInstall.length > 0 ) && ( bulkInstallCurrentThreads < bulkInstallMaxThreads ) ) {
        mainwp_upload_bulk_start_specific( pType, pUrls, pActivatePlugin, pOverwrite, siteToInstall );
    }

    if ( ( siteToInstall.length == 0 ) && ( bulkInstallCurrentThreads == 0 ) ) {
        var data = mainwp_secure_data( {
            action: 'mainwp_cleanbulkuploadplugintheme'
        } );

        jQuery.post( ajaxurl, data, function () { } );
        var msg = mainwp_install_bulk_you_know_msg(pType, jQuery('#bulk_upload_info').attr('number-files'));
        jQuery('#bulk_upload_info').html('<div class="mainwp-notice mainwp-notice-blue">' + msg + '</div>');
    }
};

mainwp_upload_bulk_start_specific = function ( pType, pUrls, pActivatePlugin, pOverwrite, pSiteToInstall )
{
    bulkInstallCurrentThreads++;
    pSiteToInstall.attr( 'status', 'progress' );

    pSiteToInstall.find( '.queue' ).hide();
    pSiteToInstall.find( '.progress' ).show();

    var data = mainwp_secure_data( {
        action: 'mainwp_installbulkuploadplugintheme',
        type: pType,
        urls: pUrls,
        activatePlugin: pActivatePlugin,
        overwrite: pOverwrite,
        siteId: pSiteToInstall.attr( 'siteid' )
    } );

    jQuery.post( ajaxurl, data, function ( pType, pUrls, pActivatePlugin, pOverwrite, pSiteToInstall )
    {
        return function ( response )
        {
            pSiteToInstall.attr( 'status', 'done' );

            pSiteToInstall.find( '.progress' ).hide();
            var statusEl = pSiteToInstall.find( '.status' );
            statusEl.show();

            if ( response.error != undefined )
            {
                statusEl.html( response.error );
                statusEl.css( 'color', 'red' );
            } else if ( ( response.ok != undefined ) && ( response.ok[pSiteToInstall.attr( 'siteid' )] != undefined ) )
            {
                statusEl.html( '<i class="check circle green icon"></i> ' + __( 'Installed successfully.' ) + '</span>' );
            } else if ( ( response.errors != undefined ) && ( response.errors[pSiteToInstall.attr( 'siteid' )] != undefined ) )
            {
                statusEl.html( '<i class="times circle red icon"></i> ' + __( 'Installation failed!' ) + '(' + response.errors[pSiteToInstall.attr( 'siteid' )][1]  + ')' );

            } else
            {
                statusEl.html( '<i class="times circle red icon"></i> ' + __( 'Installation failed!' ) );
            }

            bulkInstallCurrentThreads--;
            mainwp_upload_bulk_start_next( pType, pUrls, pActivatePlugin, pOverwrite );
        }
    }( pType, pUrls, pActivatePlugin, pOverwrite, pSiteToInstall ), 'json' );
};

/**
 * Backup
 */
var backupDownloadRunning = false;
var backupError = false;
var backupContinueRetries = 0;
var backupContinueRetriesUnique = [ ];

jQuery( document ).ready( function () {
    jQuery( '#backup_btnSubmit' ).on( 'click', function () {
        backup();
    } );
    jQuery( '#managesite-backup-status-close' ).on( 'click', function ()
    {
        backupDownloadRunning = false;
        mainwpPopup( '#managesite-backup-status-box' ).close(true);
    } );

} );
backup = function ()
{
    backupError = false;
    backupContinueRetries = 0;

    jQuery( '#backup_loading' ).show();
    var remote_destinations = jQuery( '#backup_location_remote' ).hasClass( 'mainwp_action_down' ) ? jQuery.map( jQuery( '#backup_destination_list' ).find( 'input[name="remote_destinations[]"]' ), function ( el ) {
        return { id: jQuery( el ).val(), title: jQuery( el ).attr( 'title' ), type: jQuery( el ).attr( 'destination_type' ) };
    } ) : [ ];

    //var type = ( jQuery( '#backup_type_full' ).hasClass( 'mainwp_action_down' ) ? 'full' : 'db' );
    var type = jQuery( '#mainwp-backup-type' ).val();
    var size = jQuery( '#backup_site_' + type + '_size' ).val();
    if ( type == 'full' )
    {
        size = size * 1024 * 1024 / 2.4; //Guessing how large the zip will be
    }
    var fileName = jQuery( '#backup_filename' ).val();
    var fileNameUID = mainwp_uid();
    var loadFilesBeforeZip = jQuery( '[name="mainwp_options_loadFilesBeforeZip"]:checked' ).val();

    var backupPid = Math.round( new Date().getTime() / 1000 );
    var data = mainwp_secure_data( {
        action: 'mainwp_backup',
        site_id: jQuery( '#backup_site_id' ).val(),
        pid: backupPid,
        type: type,
        exclude: jQuery( '#excluded_folders_list' ).val(),
        excludebackup: ( jQuery( '#mainwp-known-backup-locations' ).attr( 'checked' ) ? 1 : 0 ),
        excludecache: ( jQuery( '#mainwp-known-cache-locations' ).attr( 'checked' ) ? 1 : 0 ),
        excludenonwp: ( jQuery( '#mainwp-non-wordpress-folders' ).attr( 'checked' ) ? 1 : 0 ),
        excludezip: ( jQuery( '#mainwp-zip-archives' ).attr( 'checked' ) ? 1 : 0 ),
        filename: fileName,
        fileNameUID: fileNameUID,
        archiveFormat: jQuery( '#mainwp_archiveFormat' ).val(),
        maximumFileDescriptorsOverride: jQuery( '#mainwp_options_maximumFileDescriptorsOverride_override' ).is( ':checked' ) ? 1 : 0,
        maximumFileDescriptorsAuto: ( jQuery( '#mainwp_maximumFileDescriptorsAuto' ).attr( 'checked' ) ? 1 : 0 ),
        maximumFileDescriptors: jQuery( '#mainwp_options_maximumFileDescriptors' ).val(),
        loadFilesBeforeZip: loadFilesBeforeZip,
        subfolder: jQuery( '#backup_subfolder' ).val()
    }, true );

    mainwpPopup( '#managesite-backup-status-box' ).getContentEl().html( dateToHMS( new Date() ) + ' ' + __( 'Creating the backup file on the child site, this might take a while depending on the size. Please be patient.' ) + ' <div id="managesite-createbackup-status-progress" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>' );
    jQuery( '#managesite-createbackup-status-progress' ).progress( { value: 0, total: size } );

    mainwpPopup( '#managesite-backup-status-box' ).init( { callback: function () {
            if ( !backupError ) {
                location.reload();
            }
        } } );
    var backsprocessContentEl = mainwpPopup( '#managesite-backup-status-box' ).getContentEl();

    var fnc = function ( pSiteId, pType, pFileName, pFileNameUID ) {
        return function ( pFunction ) {
            var data2 = mainwp_secure_data( {
                action: 'mainwp_createbackup_getfilesize',
                siteId: pSiteId,
                type: pType,
                fileName: pFileName,
                fileNameUID: pFileNameUID
            }, false );

            jQuery.ajax( {
                url: ajaxurl,
                data: data2,
                method: 'POST',
                success: function ( pFunc ) {
                    return function ( response ) {
                        if ( backupCreateRunning && response.error )
                        {
                            setTimeout( function () {
                                pFunc( pFunc );
                            }, 1000 );
                            return;
                        }

                        if ( backupCreateRunning )
                        {
                            var progressBar = jQuery( '#managesite-createbackup-status-progress' );
                            if ( progressBar.progress( 'get value' ) < progressBar.progress( 'get total' ) )
                            {
                                progressBar.progress( 'set progress', response.size );
                            }

                            setTimeout( function () {
                                pFunc( pFunc );
                            }, 1000 );
                        }
                    }
                }( pFunction ),
                error: function ( pFunc ) {
                    return function () {
                        if ( backupCreateRunning ) {
                            setTimeout( function () {
                                pFunc( pFunc );
                            }, 10000 );
                        }
                    }
                }( pFunction ),
                dataType: 'json' } );
        }
    }( jQuery( '#backup_site_id' ).val(), type, fileName, fileNameUID );

    setTimeout( function () {
        fnc( fnc );
    }, 1000 );

    backupCreateRunning = true;
    jQuery.ajax( {
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function ( pSiteId, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, pData ) {
            return function ( response ) {
                if ( response.error || !response.result )
                {
                    backup_retry_fail( pSiteId, pData, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, response.error ? response.error : '' );
                } else
                {
                    backupCreateRunning = false;

                    var progressBar = jQuery( '#managesite-createbackup-status-progress' );
                    progressBar.progress( 'set progress', parseFloat( progressBar.progress( 'get total' ) ) );

                    appendToDiv( backsprocessContentEl, __( 'Backup file on child site created successfully!' ) );

                    backup_download_file( pSiteId, pType, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, pRemoteDestinations );
                }
            }
        }( jQuery( '#backup_site_id' ).val(), remote_destinations, backupPid, type, jQuery( '#backup_subfolder' ).val(), fileName, data ),
        error: function ( pSiteId, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, pData ) {
            return function () {
                backup_retry_fail( pSiteId, pData, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename );
            }
        }( jQuery( '#backup_site_id' ).val(), remote_destinations, backupPid, type, jQuery( '#backup_subfolder' ).val(), fileName, data ),
        dataType: 'json'
    } );
};

backup_retry_fail = function ( siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError )
{
    var backsprocessContentEl = mainwpPopup( '#managesite-backup-status-box' ).getContentEl();
    //we've got the pid file!!!!
    var data = mainwp_secure_data( {
        action: 'mainwp_backup_checkpid',
        site_id: siteId,
        pid: pid,
        type: type,
        subfolder: subfolder,
        filename: filename
    } );

    jQuery.ajax( {
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function ( response ) {
            if ( response.status == 'done' )
            {
                backupCreateRunning = false;

                var progressBar = jQuery( '#managesite-createbackup-status-progress' );
                progressBar.progress( 'set progress', parseFloat( progressBar.progress( 'get total' ) ) );

                //download!!!
                appendToDiv( backsprocessContentEl, __( 'Backup file on child site created successfully!' ) );

                backup_download_file( siteId, type, response.result.file, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, remoteDestinations );
            } else if ( response.status == 'busy' )
            {
                //Try again in 5seconds
                setTimeout( function () {
                    backup_retry_fail( siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError );
                }, 10000 );
            } else if ( response.status == 'stalled' )
            {
                backupContinueRetries++;

                if ( backupContinueRetries > 10 )
                {
                    if ( responseError != undefined )
                    {
                        appendToDiv( backsprocessContentEl, ' <span class="mainwp-red">ERROR: ' + getErrorMessage( responseError ) + '</span>' );
                    } else
                    {
                        appendToDiv( backsprocessContentEl, ' <span class="mainwp-red">ERROR: Backup timed out! <a href="https://mainwp.com/help/docs/mainwp-introduction/resolving-system-requirement-issues/">Please check this help document for more information and possible fixes</a></span>' );
                    }
                } else
                {
                    appendToDiv( backsprocessContentEl, ' Backup stalled, trying to resume from last file...' );
                    //retrying file: response.result.file !

                    pData['filename'] = response.result.file;
                    pData['append'] = 1;
                    pData = mainwp_secure_data( pData, true ); //Rescure

                    jQuery.ajax( {
                        url: ajaxurl,
                        data: pData,
                        method: 'POST',
                        success: function ( pSiteId, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, pData ) {
                            return function ( response ) {
                                if ( response.error || !response.result )
                                {
                                    backup_retry_fail( pSiteId, pData, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, response.error ? response.error : '' );
                                } else
                                {
                                    backupCreateRunning = false;

                                    var progressBar = jQuery( '#managesite-createbackup-status-progress' );
                                    progressBar.progress( 'set progress', parseFloat( progressBar.progress( 'get total' ) ) );

                                    appendToDiv( backsprocessContentEl, __( 'Backupfile on child site created successfully.' ) );

                                    backup_download_file( pSiteId, pType, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, pRemoteDestinations );
                                }
                            }
                        }( siteId, remoteDestinations, pid, type, subfolder, filename, pData ),
                        error: function ( pSiteId, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, pData ) {
                            return function () {
                                backup_retry_fail( pSiteId, pData, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename );
                            }
                        }( siteId, remoteDestinations, pid, type, subfolder, filename, pData ),
                        dataType: 'json'
                    } );
                }
            } else if ( response.status == 'invalid' )
            {
                backupCreateRunning = false;

                if ( responseError != undefined )
                {
                    appendToDiv( backsprocessContentEl, ' <span class="mainwp-red">ERROR: ' + getErrorMessage( responseError ) + '</span>' );
                } else
                {
                    appendToDiv( backsprocessContentEl, ' <span class="mainwp-red">ERROR: Backup timed out! <a href="https://mainwp.com/help/docs/mainwp-introduction/resolving-system-requirement-issues/">Please check this help document for more information and possible fixes</a></span>' );
                }
            } else
            {
                //Try again in 5seconds
                setTimeout( function () {
                    backup_retry_fail( siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError );
                }, 10000 );
            }
        },
        error: function () {
            //Try again in 10seconds
            setTimeout( function () {
                backup_retry_fail( siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError );
            }, 10000 );
        },
        dataType: 'json'
    } );
};

backup_download_file = function ( pSiteId, type, url, file, regexfile, size, subfolder, remote_destinations )
{
    var backsprocessContentEl = mainwpPopup( '#managesite-backup-status-box' ).getContentEl();
    appendToDiv( backsprocessContentEl, __( 'Downloading the file.' ) + ' <div id="managesite-backup-status-progress" class="ui green progress"><div class="bar"><div class="progress"></div></div></div>' );
    jQuery( '#managesite-backup-status-progress' ).progress( { value: 0, total: size } );

    var fnc = function () {
        return function ( pFunction ) {
            var data = mainwp_secure_data( {
                action: 'mainwp_backup_getfilesize',
                local: file
            } );
            jQuery.ajax( {
                url: ajaxurl,
                data: data,
                method: 'POST',
                success: function ( pFunc ) {
                    return function ( response ) {
                        if ( backupCreateRunning && response.error )
                        {
                            setTimeout( function () {
                                pFunc( pFunc );
                            }, 5000 );
                            return;
                        }

                        if ( backupDownloadRunning )
                        {
                            var progressBar = jQuery( '#managesite-backup-status-progress' );
                            if ( progressBar.progress( 'get value' ) < progressBar.progress( 'get total' ) )
                            {
                                progressBar.progress( 'set progress', response.result );
                            }

                            setTimeout( function () {
                                pFunc( pFunc );
                            }, 1000 );
                        }
                    }
                }( pFunction ),
                error: function ( pFunc ) {
                    return function () {
                        if ( backupCreateRunning ) {
                            setTimeout( function () {
                                pFunc( pFunc );
                            }, 10000 );
                        }
                    }
                }( pFunction ),
                dataType: 'json'
            } );
        }
    }( file );

    setTimeout( function () {
        fnc( fnc );
    }, 1000 );

    var data = mainwp_secure_data( {
        action: 'mainwp_backup_download_file',
        site_id: pSiteId,
        type: type,
        url: url,
        local: file
    } );
    backupDownloadRunning = true;

    jQuery.ajax( {
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function ( pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pSize, pType, pUrl ) {
            return function ( response ) {
                backupDownloadRunning = false;

                if ( response.error )
                {
                    appendToDiv( backsprocessContentEl, '<span class="mainwp-red">ERROR: ' + getErrorMessage( response.error ) + '</span>' );
                    appendToDiv( backsprocessContentEl, '<span class="mainwp-red">' + __( 'Backup failed!' ) + '</span>' );

                    jQuery( '#managesite-backup-status-close' ).prop( 'value', 'Close' );
                    return;
                }

                jQuery( '#managesite-backup-status-progress' ).progress( 'set progress', pSize );
                appendToDiv( backsprocessContentEl, __( 'Download from child site completed.' ) );

                var newData = mainwp_secure_data( {
                    action: 'mainwp_backup_delete_file',
                    site_id: pSiteId,
                    file: pUrl
                } );
                jQuery.post( ajaxurl, newData, function () {}, 'json' );
                backup_upload_file( pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize );
            }
        }( pSiteId, file, regexfile, subfolder, remote_destinations, size, type, url ),
        error: function () {
            return function () {
                //Try again in 10seconds
                /*setTimeout(function() {
                 download_retry_fail(pSiteId, pData, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pSize, pType, pUrl);
                 },10000);*/
            }
        }( pSiteId, file, regexfile, subfolder, remote_destinations, size, type, url ),
        dataType: 'json' } );
};

var backupUploadRunning = [ ];
backup_upload_file = function ( pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize )
{
    var backsprocessContentEl = mainwpPopup( '#managesite-backup-status-box' ).getContentEl();
    if ( pRemoteDestinations.length > 0 )
    {
        var remote_destination = pRemoteDestinations[0];
        //upload..
        var unique = Date.now();
        appendToDiv( backsprocessContentEl, __( 'Uploading to remote destination: %1 (%2)', remote_destination.title, remote_destination.type ) + '<div id="managesite-upload-status-progress-' + unique + '"  class="ui green progress"><div class="bar"><div class="progress"></div></div></div>' );

        jQuery( '#managesite-upload-status-progress-' + unique ).progress( { value: 0, total: pSize } );

        var fnc = function ( pUnique ) {
            return function ( pFunction ) {
                var data2 = mainwp_secure_data( {
                    action: 'mainwp_backup_upload_getprogress',
                    unique: pUnique
                }, false );

                jQuery.ajax( {
                    url: ajaxurl,
                    data: data2,
                    method: 'POST',
                    success: function ( pFunc ) {
                        return function ( response ) {
                            if ( backupUploadRunning[pUnique] && response.error )
                            {
                                setTimeout( function () {
                                    pFunc( pFunc );
                                }, 1000 );
                                return;
                            }

                            if ( backupUploadRunning[pUnique] )
                            {
                                var progressBar = jQuery( '#managesite-upload-status-progress-' + pUnique );
                                if ( ( progressBar.length > 0 ) && ( progressBar.progress( 'get value' ) < progressBar.progress( 'get total' ) ) && ( progressBar.progress( 'get value' ) < parseInt( response.result ) ) )
                                {
                                    progressBar.progress( 'set progress', response.result );
                                }

                                setTimeout( function () {
                                    pFunc( pFunc );
                                }, 1000 );
                            }
                        }
                    }( pFunction ),
                    error: function ( pFunc ) {
                        return function () {
                            if ( backupUploadRunning[pUnique] ) {
                                setTimeout( function () {
                                    pFunc( pFunc );
                                }, 10000 );
                            }
                        }
                    }( pFunction ),
                    dataType: 'json' } );
            }
        }( unique );

        setTimeout( function () {
            fnc( fnc );
        }, 1000 );

        backupUploadRunning[unique] = true;

        var data = mainwp_secure_data( {
            action: 'mainwp_backup_upload_file',
            file: pFile,
            siteId: pSiteId,
            regexfile: pRegexFile,
            subfolder: pSubfolder,
            type: pType,
            remote_destination: remote_destination.id,
            unique: unique
        } );

        pRemoteDestinations.shift();
        jQuery.ajax( {
            type: 'POST',
            url: ajaxurl,
            data: data,
            success: function ( pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pUnique, pRemoteDestId ) {
                return function ( response ) {
                    if ( !response || response.error || !response.result )
                    {
                        backup_upload_file_retry_fail( pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, response && response.error ? response.error : '' );
                    } else
                    {
                        backupUploadRunning[pUnique] = false;

                        var progressBar = jQuery( '#managesite-upload-status-progress-' + pUnique );
                        progressBar.progress( 'set progress', pSize );

                        var obj = response.result;
                        if ( obj.error )
                        {
                            backupError = true;
                            appendToDiv( backsprocessContentEl, '<span class="mainwp-red">' + __( 'Upload to %1 (%2) failed:', obj.title, obj.type ) + ' ' + obj.error + '</span>' );
                        } else
                        {
                            appendToDiv( backsprocessContentEl, __( 'Upload to %1 (%2) successful!', obj.title, obj.type ) );
                        }

                        backup_upload_file( pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize );
                    }
                }
            }( pSiteId, pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, data, unique, remote_destination.id ),
            error: function ( pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pUnique, pRemoteDestId ) {
                return function () {
                    backup_upload_file_retry_fail( pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId );
                }
            }( pSiteId, pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, data, unique, remote_destination.id ),
            dataType: 'json'
        } );
    } else
    {
        appendToDiv( backsprocessContentEl, __( 'Backup completed!' ) );
        jQuery( '#managesite-backup-status-close' ).prop( 'value', 'Close' );
        if ( !backupError )
        {
            setTimeout( function () {
                mainwpPopup( '#managesite-backup-status-box' ).close(true);
            }, 3000 );
        }
        return;
    }
};

backup_upload_file_retry_fail = function ( pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError )
{
    var backsprocessContentEl = mainwpPopup( '#managesite-backup-status-box' ).getContentEl();
    //we've got the pid file!!!!
    var data = mainwp_secure_data( {
        action: 'mainwp_backup_upload_checkstatus',
        unique: pUnique,
        remote_destination: pRemoteDestId
    } );

    jQuery.ajax( {
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function ( response ) {
            if ( response.status == 'done' )
            {
                backupUploadRunning[pUnique] = false;

                var progressBar = jQuery( '#managesite-upload-status-progress-' + pUnique );
                progressBar.progress( 'set progress', pSize );

                appendToDiv( backsprocessContentEl, __( 'Upload to %1 (%2) successful!', response.info.title, response.info.type ) );

                backup_upload_file( pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize );
            } else if ( response.status == 'busy' )
            {
                //Try again in 10seconds
                setTimeout( function () {
                    backup_upload_file_retry_fail( pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError );
                }, 10000 );
            } else if ( response.status == 'stalled' )
            {
                if ( backupContinueRetriesUnique[pUnique] == undefined )
                {
                    backupContinueRetriesUnique[pUnique] = 1;
                } else
                {
                    backupContinueRetriesUnique[pUnique]++;
                }

                if ( backupContinueRetriesUnique[pUnique] > 10 )
                {
                    if ( responseError != undefined )
                    {
                        backupError = true;
                        appendToDiv( backsprocessContentEl, '<span class="mainwp-red">' + __( 'Upload to %1 (%2) failed:', response.info.title, response.info.type ) + ' ' + responseError + '</span>' );
                    } else
                    {
                        appendToDiv( backsprocessContentEl, ' <span class="mainwp-red">ERROR: Upload timed out! <a href="http://docs.mainwp.com/backup-failed-php-ini-settings/">Please check this help document for more information and possible fixes</a></span>' );
                    }

                    backup_upload_file( pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize );
                } else
                {
                    appendToDiv( backsprocessContentEl, ' Upload stalled, trying to resume from last position...' );

                    pData = mainwp_secure_data( pData ); //Rescure

                    jQuery.ajax( {
                        url: ajaxurl,
                        data: pData,
                        method: 'POST',
                        success: function ( pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pRemoteDestId ) {
                            return function ( response ) {
                                if ( response.error || !response.result )
                                {
                                    backup_upload_file_retry_fail( pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, response.error ? response.error : '' );
                                } else
                                {
                                    backupUploadRunning[pUnique] = false;

                                    var progressBar = jQuery( '#managesite-upload-status-progress-' + pUnique );
                                    progressBar.progress( 'set progress', pSize );

                                    var obj = response.result;
                                    if ( obj.error )
                                    {
                                        backupError = true;
                                        appendToDiv( backsprocessContentEl, '<span class="mainwp-red">' + __( 'Upload to %1 (%2) failed:', obj.title, obj.type ) + ' ' + obj.error + '</span>' );
                                    } else
                                    {
                                        appendToDiv( backsprocessContentEl, __( 'Upload to %1 (%2) successful!', obj.title, obj.type ) );
                                    }

                                    backup_upload_file( pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize );
                                }
                            }
                        }( pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pRemoteDestId ),
                        error: function ( pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pRemoteDestId ) {
                            return function () {
                                backup_upload_file_retry_fail( pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId );
                            }
                        }( pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pRemoteDestId ),
                        dataType: 'json'
                    } );
                }
            } else
            {
                //Try again in 5seconds
                setTimeout( function () {
                    backup_upload_file_retry_fail( pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError );
                }, 10000 );
            }
        },
        error: function () {
            //Try again in 10seconds
            setTimeout( function () {
                backup_upload_file_retry_fail( pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError );
            }, 10000 );
        },
        dataType: 'json'
    } );
};

/**
 * Utility
 */
function isUrl( s ) {
    var regexp = /(ftp|http|https):\/\/(\w+:{0,1}\w*@)?(\S+)(:[0-9]+)?(\/|\/([\w#!:.?+=&%@!\-/]))?/;
    return regexp.test( s );
}
function setVisible( what, vis ) {
    if ( vis ) {
        jQuery( what ).show();
    } else {
        jQuery( what ).hide();
    }
}
function setHtml( what, text, ptag ) {
    if ( typeof ptag == "undefined" )
        ptag = true;

    setVisible( what, true );
    if ( ptag )
        jQuery( what ).html( '<span>' + text + '</span>' );
    else
        jQuery( what ).html( text );
    scrollToElement( what );
}


/**
 * Notes
 */
jQuery( document ).ready( function () {

    jQuery( document ).on( 'click', '#mainwp-notes-cancel', function () {
        jQuery( '#mainwp-notes-status' ).html('');
        mainwp_notes_hide();
        return false;
    } );

    jQuery( document ).on( 'click', '#mainwp-notes-save', function () {
        var which = jQuery( '#mainwp-which-note' ).val();
        if (which == 'site') {
            mainwp_notes_site_save();
        } else if ( which == 'theme' ) {
            mainwp_notes_theme_save();
        } else if ( which == 'plugin' ) {
            mainwp_notes_plugin_save()
        }
        var newnote = jQuery( '#mainwp-notes-note' ).val();
        jQuery( '#mainwp-notes-html' ).html( newnote );
        return false;
    } );

    jQuery( document ).on( 'click', '.mainwp-edit-site-note', function () {
        var id = jQuery( this ).attr( 'id' ).substr( 13 );
        var note = jQuery( '#mainwp-notes-' + id + '-note' ).html();
        jQuery( '#mainwp-notes-html' ).html( note == '' ? __( 'No saved notes. Click the Edit button to edit site notes.' ) : note );
        jQuery( '#mainwp-notes-note' ).val( note );
        jQuery( '#mainwp-notes-websiteid' ).val( id );
        mainwp_notes_show();
        return false;
    } );

jQuery( document ).on( 'click', '#mainwp-notes-edit', function () {
        //var value = jQuery( '#mainwp-notes-html').html();
        jQuery( '#mainwp-notes-html' ).hide();
        jQuery( '#mainwp-notes-editor' ).show();
        //jQuery( '#mainwp-notes-note').val( value );
        jQuery( this ).hide();
        jQuery( '#mainwp-notes-save' ).show();
        jQuery( '#mainwp-notes-status' ).html('');
        // jQuery( '#mainwp-notes' ).addClass( 'edit-mode' );
        return false;
    } );
    jQuery( '#redirectForm' ).submit();
} );

mainwp_notes_show = function () {
  jQuery( '#mainwp-notes' ).modal( 'show' );
  jQuery( '#mainwp-notes-html' ).show();
  jQuery( '#mainwp-notes-editor' ).hide();
  jQuery( '#mainwp-notes-save' ).hide();
  jQuery( '#mainwp-notes-edit' ).show();
};
mainwp_notes_hide = function () {
  jQuery( '#mainwp-notes' ).modal( 'hide' );
};
mainwp_notes_site_save = function () {
    var normalid = jQuery( '#mainwp-notes-websiteid' ).val();
    var newnote = jQuery( '#mainwp-notes-note' ).val();
    var data = mainwp_secure_data( {
        action: 'mainwp_notes_save',
        websiteid: normalid,
        note: newnote
    } );

    jQuery( '#mainwp-notes-status' ).html( '<i class="notched circle loading icon"></i> ' + __( 'Saving note. Please wait...' ) );

    jQuery.post( ajaxurl, data, function ( response ) {
      if ( response.error != undefined ){
        jQuery( '#mainwp-notes-status' ).html( '<i class="times circle red icon"></i> ' +  response.error );
      } else if ( response.result == 'SUCCESS' ) {
        jQuery( '#mainwp-notes-status' ).html( '<i class="check circle green icon"></i> ' +  __( 'Note saved.' ) );
        if ( jQuery( '#mainwp-notes-' + normalid + '-note' ).length > 0 ) {
            jQuery( '#mainwp-notes-' + normalid + '-note' ).html( jQuery( '#mainwp-notes-note' ).val() );
        }
      } else {
        jQuery( '#mainwp-notes-status' ).html( '<i class="times circle red icon"></i> ' + __( 'Undefined error occured while saving your note!' ) + '.' );
      }
    }, 'json' );


    jQuery( '#mainwp-notes-html' ).show();
    jQuery( '#mainwp-notes-editor' ).hide();
    jQuery( '#mainwp-notes-save' ).hide();
    jQuery( '#mainwp-notes-edit' ).show();

};

/**
 * MainWP_Page.page
 */
jQuery( document ).ready( function () {
    //jQuery( '.mainwp_datepicker' ).datepicker( { dateFormat: "yy-mm-dd" } );

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

    jQuery( '#mainwp_show_pages' ).live( 'click', function () {
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
//    rowElement.find( '.row-actions' ).hide();
//    rowElement.find( '.row-actions-working' ).show();

    rowElement.html( '<td colspan="99"><i class="notched circle loading icon"></i> Please wait...</td>' );
    jQuery.post( ajaxurl, data, function ( response ) {
        if ( response.error ) {
            rowElement.html( '<td colspan="99"><i class="times circle red icon"></i>' + response.error + '</td>' );
        } else if ( response.result ) {
            rowElement.html( '<td colspan="99"><i class="check circle green icon"></i> ' + response.result + '</td>' );
        }
//        else {
//            rowElement.find( '.row-actions-working' ).hide();
//        }
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

    //rowElement.find('td').hide();
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
//        var matches = ( response == null ? null : response.match( /post\[\]/g ) );
//        jQuery( '#mainwp_posts_total' ).html( matches == null ? 0 : matches.length );
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
        jQuery( '#mainwp-edit-users-modal' ).modal('show');
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
                jQuery( '#mainwp-edit-users-modal' ).modal('show');
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

//        if (jQuery('#pass1').val() !== '' || jQuery('#pass2').val() !== '') {
//            if (jQuery('#pass1').val() != jQuery('#pass2').val()) {
//                jQuery('#pass1').parent().addClass('form-invalid');
//                jQuery('#pass2').parent().addClass('form-invalid');
//                errors.push('Passwords do not match.');
//            }
//            else {
//                if (jQuery('#pass1').val() != '' )
//                    jQuery('#pass1').parent().removeClass('form-invalid');
//                if (jQuery('#pass2').val() != '' )
//                    jQuery('#pass2').parent().removeClass('form-invalid');
//            }
//        }

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
    //jQuery( '#mainwp-update-users-box' ).show();
//    jQuery( 'html, body' ).animate( {
//        scrollTop: jQuery( "#mainwp-users-table" ).offset().top + jQuery( "#mainwp-users-table" ).height()
//    }, 500 );
    jQuery( 'form#update_user_profile select#role option[value="donotupdate"]' ).prop( 'selected', true );
    jQuery( 'form#update_user_profile select#role' ).removeAttr( 'disabled' );
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
                jQuery( 'form#update_user_profile select#role' ).removeAttr( 'disabled' );
            }

            jQuery( 'form#update_user_profile input#first_name' ).val( response.user_data.first_name );
            jQuery( 'form#update_user_profile input#last_name' ).val( response.user_data.last_name );
            jQuery( 'form#update_user_profile input#nickname' ).val( response.user_data.nickname );
            jQuery( 'form#update_user_profile input#email' ).val( response.user_data.user_email );
            jQuery( 'form#update_user_profile input#url' ).val( response.user_data.user_url );
            jQuery( 'form#update_user_profile select#display_name' ).empty();
            jQuery( 'form#update_user_profile select#display_name' ).removeAttr( 'disabled' );
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

//            jQuery( 'html, body' ).animate( {
//                scrollTop: jQuery( "#mainwp-users-table" ).offset().top + jQuery( "#mainwp-users-table" ).height()
//            }, 500 );
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
            jQuery( '#mainwp-do-users-bulk-actions' ).removeAttr( 'disabled' );

            jQuery( '#mainwp_btn_update_user' ).removeAttr( 'disabled' );
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

  if ( jQuery( '#select_by' ).val() == 'site' ) {
    jQuery( "input[name='selected_sites[]']:checked" ).each( function () {
      selected_sites.push( jQuery( this ).val() );
    } );
    if ( selected_sites.length == 0 ) {
      errors.push( __( 'Please select at least one website or group.' )  );
    }
  } else {
    jQuery( "input[name='selected_groups[]']:checked" ).each( function () {
      selected_groups.push( jQuery( this ).val() );
    } );
    if ( selected_groups.length == 0 ) {
      errors.push( __( 'Please select at least one website or group.' ) );
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
    'sites[]': selected_sites
  } );

  jQuery( '#mainwp-loading-users-row' ).show();
  jQuery.post( ajaxurl, data, function ( response ) {
    response = jQuery.trim( response );
    jQuery( '#mainwp-loading-users-row' ).hide();
    jQuery( '#mainwp_users_loading_info' ).hide();
    jQuery( '#mainwp_users_main' ).show();
    // var matches = ( response == null ? null : response.match( /user\[\]/g ) );
    // jQuery( '#mainwp_users_total' ).html( matches == null ? 0 : matches.length );
    jQuery( '#mainwp_users_wrap_table' ).html( response );
    // re-initialize datatable
    jQuery("#mainwp-users-table").DataTable().destroy();
    jQuery('#mainwp-users-table').DataTable({
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
  } );
};


getErrorMessage = function ( pError )
{
    if ( pError.message == 'HTTPERROR' ) {
        return __( 'HTTP error' ) + '! ' + pError.extra;
    } else if ( pError.message == 'NOMAINWP' || pError == 'NOMAINWP' ) {
        var error = '';
        if ( pError.extra )
        {
            error = __( 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to your MainWP Dashboard afterward. If you continue experiencing this issue, check the child site for <a href="https://meta.mainwp.com/t/known-plugin-conflicts/402">known plugin conflicts</a>, or check the <a href="https://meta.mainwp.com/c/community-support/5">MainWP Community</a> for help.', pError.extra ); // to fix incorrect encoding
        } else
        {
            error = __( 'MainWP Child plugin not detected! First install and activate the MainWP Child plugin and add your site to MainWP Dashboard afterwards.' );
        }

        return error;
    } else if ( pError.message == 'ERROR' ) {
        return 'ERROR' + ( ( pError.extra != '' ) && ( pError.extra != undefined ) ? ': ' + pError.extra : '' );
    } else if ( pError.message == 'WPERROR' ) {
        return __( 'ERROR on the child site' ) + ( ( pError.extra != '' ) && ( pError.extra != undefined ) ? ': ' + pError.extra : '' );
    } else if ( pError.message != undefined && pError.message != '' )
    {
        return pError.message;
    } else
    {
        return pError;
    }
};
dateToYMD = function ( date ) {
    if ( mainwpParams != undefined && mainwpParams['date_format'] != undefined )
    {
        var time = moment( date );
        var format = mainwpParams['date_format'];
        format = format.replace( 'g', 'h' );
        format = format.replace( 'i', 'm' );
        format = format.replace( 'F', 'MMMM' );
        format = format.replace( 'j', 'D' );
        format = format.replace( 'Y', 'YYYY' );
        return time.format( format );
    }

    var d = date.getDate();
    var m = date.getMonth() + 1;
    var y = date.getFullYear();
    return '' + y + '-' + ( m <= 9 ? '0' + m : m ) + '-' + ( d <= 9 ? '0' + d : d );
};
dateToHMS = function ( date ) {
    if ( mainwpParams != undefined && mainwpParams['time_format'] != undefined )
    {
        var time = moment( date );
        var format = mainwpParams['time_format'];
        format = format.replace( 'g', 'h' );
        format = format.replace( 'i', 'mm' );
        format = format.replace( 's', 'ss' );
        format = format.replace( 'F', 'MMMM' );
        format = format.replace( 'j', 'D' );
        format = format.replace( 'Y', 'YYYY' );
        return time.format( format );
    }
    var h = date.getHours();
    var m = date.getMinutes();
    var s = date.getSeconds();
    return '' + ( h <= 9 ? '0' + h : h ) + ':' + ( m <= 9 ? '0' + m : m ) + ':' + ( s <= 9 ? '0' + s : s );
};
appendToDiv = function ( pSelector, pText, pScrolldown, pShowTime )
{
    if ( pScrolldown == undefined )
        pScrolldown = true;
    if ( pShowTime == undefined )
        pShowTime = true;

    var theDiv = jQuery( pSelector );
    theDiv.append( '<br />' + ( pShowTime ? dateToHMS( new Date() ) + ' ' : '' ) + pText );
    if ( pScrolldown )
        theDiv.animate( { scrollTop: theDiv.prop( "scrollHeight" ) }, 100 );
};

jQuery.fn.exists = function () {
    return ( this.length !== 0 );
};


function __( text, _var1, _var2, _var3 )
{
    if ( text == undefined || text == '' )
        return text;
    var strippedText = text.replace( / /g, '_' );
    strippedText = strippedText.replace( /[^A-Za-z0-9_]/g, '' );

    if ( strippedText == '' )
        return text.replace( '%1', _var1 ).replace( '%2', _var2 ).replace( '%3', _var3 );

    if ( mainwpTranslations == undefined )
        return text.replace( '%1', _var1 ).replace( '%2', _var2 ).replace( '%3', _var3 );
    if ( mainwpTranslations[strippedText] == undefined )
        return text.replace( '%1', _var1 ).replace( '%2', _var2 ).replace( '%3', _var3 );

    return mainwpTranslations[strippedText].replace( '%1', _var1 ).replace( '%2', _var2 ).replace( '%3', _var3 );
}

mainwp_secure_data = function ( data, includeDts )
{
    if ( data['action'] == undefined )
        return data;

    data['security'] = security_nonces[data['action']];
    if ( includeDts )
        data['dts'] = Math.round( new Date().getTime() / 1000 );
    return data;
};

jQuery( document ).on( 'click', '#mainwp-add-site-notice-dismiss', function ()
{
    jQuery( '#mainwp-add-site-notice' ).hide();
    jQuery( '#mainwp-add-site-notice-show' ).show();

    return false;
} );

jQuery( document ).on( 'click', '#mainwp-add-site-notice-show-link', function ()
{
    jQuery( '#mainwp-add-site-notice' ).show();
    jQuery( '#mainwp-add-site-notice-show' ).hide();

    return false;
} );

jQuery( document ).on( 'click', '.mainwp-news-tab', function ()
{
    jQuery( '.mainwp-news-tab' ).removeClass( 'mainwp_action_down' );
    jQuery( '.mainwp-news-items' ).hide();
    jQuery( this ).addClass( 'mainwp_action_down' );
    jQuery( '.mainwp-news-items[name="' + jQuery( this ).attr( 'name' ) + '"]' ).show();

    return false;
} );

// phpcs:ignore -- required to compatible with some extensions
function mainwp_setCookie( c_name, value, expiredays )
{
    var exdate = new Date();
    exdate.setDate( exdate.getDate() + expiredays );
    document.cookie = c_name + "=" + escape( value ) + ( ( expiredays == null ) ? "" : ";expires=" + exdate.toUTCString() );
}

// phpcs:ignore -- required to compatible with some extensions
function mainwp_getCookie( c_name )
{
    if ( document.cookie.length > 0 )
    {
        var c_start = document.cookie.indexOf( c_name + "=" );
        if ( c_start != -1 )
        {
            c_start = c_start + c_name.length + 1;
            var c_end = document.cookie.indexOf( ";", c_start );
            if ( c_end == -1 )
                c_end = document.cookie.length;
            return unescape( document.cookie.substring( c_start, c_end ) );
        }
    }
    return "";
}

mainwp_uid = function () {
    // always start with a letter (for DOM friendlyness)
    var idstr = String.fromCharCode( Math.floor( ( Math.random() * 25 ) + 65 ) );
    do {
        // between numbers and characters (48 is 0 and 90 is Z (42-48 = 90)
        var ascicode = Math.floor( ( Math.random() * 42 ) + 48 );
        if ( ascicode < 58 || ascicode > 64 ) {
            // exclude all chars between : (58) and @ (64)
            idstr += String.fromCharCode( ascicode );
        }
    } while ( idstr.length < 32 );

    return ( idstr );
};

scrollToElement = function () {
    jQuery( 'html,body' ).animate( {
        scrollTop: 0
    }, 1000 );

    return false;
};

jQuery( document ).ready( function () {
    jQuery( '#backup_filename' ).keypress( function ( e )
    {
        var chr = String.fromCharCode( e.which );
        return ( "$^&*/".indexOf( chr ) < 0 );
    } );
    jQuery( '#backup_filename' ).change( function () {
        var value = jQuery( this ).val();
        var notAllowed = [ '$', '^', '&', '*', '/' ];
        for ( var i = 0; i < notAllowed.length; i++ )
        {
            var char = notAllowed[i];
            if ( value.indexOf( char ) >= 0 )
            {
                value = value.replace( new RegExp( '\\' + char, 'g' ), '' );
                jQuery( this ).val( value );
            }
        }
    } );
} );

/*
 * Server Info
 */

serverinfo_prepare_download_info = function ( communi ) {
    var report = "";
    jQuery( '.mainwp-system-info-table thead, .mainwp-system-info-table tbody' ).each( function () {
        var td_len = [ 35, 55, 45, 12, 12 ];
        var th_count = 0;
        var i;
        if ( jQuery( this ).is( 'thead' ) ) {
            i = 0;
            report = report + "\n### ";
            th_count = jQuery( this ).find( 'th:not(".mwp-not-generate-row")' ).length;
            jQuery( this ).find( 'th:not(".mwp-not-generate-row")' ).each( function () {
                var len = td_len[i];
                if ( i == 0 || i == th_count - 1 )
                    len = len - 4;
                report = report + jQuery.mwp_strCut( jQuery.trim( jQuery( this ).text() ), len, ' ' );
                i++;
            } );
            report = report + " ###\n\n";
        } else {
            jQuery( 'tr', jQuery( this ) ).each( function () {
                if ( communi && jQuery( this ).hasClass( 'mwp-not-generate-row' ) )
                    return;
                i = 0;
                jQuery( this ).find( 'td:not(".mwp-not-generate-row")' ).each( function () {
                    if ( jQuery( this ).hasClass( 'mwp-hide-generate-row' ) ) {
                        report = report + jQuery.mwp_strCut( ' ', td_len[i], ' ' );
                        i++;
                        return;
                    }
                    report = report + jQuery.mwp_strCut( jQuery.trim( jQuery( this ).text() ), td_len[i], ' ' );
                    i++;
                } );
                report = report + "\n";
            } );

        }
    } );

    try {
            if ( communi ) {
                report = '```' +  "\n" + report +  "\n" + '```';
            }

            //jQuery( "#download-server-information" ).slideDown();
            jQuery( "#download-server-information textarea" ).val( report ).focus().select();
    } catch ( e ) {
        console.log('Error:');
    }
    return false;
}

jQuery( document ).on( 'click', '#mainwp-download-system-report', function () {
    serverinfo_prepare_download_info( false );
    var server_info = jQuery( '#download-server-information textarea' ).val();
    var blob = new Blob( [ server_info ], { type: "text/plain;charset=utf-8" } );
    saveAs( blob, "mainwp-system-report.txt" );
    return false;
} );

jQuery( document ).on( 'click', '#mainwp-copy-meta-system-report', function () {
    jQuery( "#download-server-information" ).slideDown(); // to able to select and copy
    serverinfo_prepare_download_info( true );
    jQuery( "#download-server-information" ).slideUp();
    try {
        var successful = document.execCommand('copy');
        var msg = successful ? 'successful' : 'unsuccessful';
        console.log('Copying text command was ' + msg);
    } catch (err) {
        console.log('Oops, unable to copy');
    }
    return false;
} );


jQuery.mwp_strCut = function ( i, l, s, w ) {
    var o = i.toString();
    if ( !s ) {
        s = '0';
    }
    while ( o.length < parseInt( l ) ) {
        // empty
        if ( w == 'undefined' ) {
            o = s + o;
        } else {
            o = o + s;
        }
    }
    return o;
};

updateExcludedFolders = function ()
{
    var excludedBackupFiles = jQuery( '#excludedBackupFiles' ).html();
    jQuery( '#mainwp-kbl-content' ).val( excludedBackupFiles == undefined ? '' : excludedBackupFiles );

    var excludedCacheFiles = jQuery( '#excludedCacheFiles' ).html();
    jQuery( '#mainwp-kcl-content' ).val( excludedCacheFiles == undefined ? '' : excludedCacheFiles );

    var excludedNonWPFiles = jQuery( '#excludedNonWPFiles' ).html();
    jQuery( '#mainwp-nwl-content' ).val( excludedNonWPFiles == undefined ? '' : excludedNonWPFiles );
};


jQuery( document ).on( 'click', '.mainwp-events-notice-dismiss', function ()
{
    var notice = jQuery( this ).attr( 'notice' );
    jQuery( this ).closest( '.ui.message' ).fadeOut( 500 );
    var data = mainwp_secure_data( {
        action: 'mainwp_events_notice_hide',
        notice: notice
    } );
    jQuery.post( ajaxurl, data, function () {
    } );
    return false;
} );

// Turn On child plugin auto update
jQuery( document ).on( 'click', '#mainwp_btn_autoupdate_and_trust', function () {
  jQuery( this ).attr( 'disabled', 'true' );
  var data = mainwp_secure_data( {
      action: 'mainwp_autoupdate_and_trust_child'
  } );
  jQuery.post( ajaxurl, data, function ( res ) {
    if ( res == 'ok' ) {
      location.reload( true );
    } else {
      jQuery( this ).removeAttr( 'disabled' );
    }
  } );
  return false;
} );

// Hide installation warning
jQuery( document ).on( 'click', '#remove-mainwp-installation-warning', function () {
  jQuery(this).closest('.ui.message').fadeOut("slow");
  var data = mainwp_secure_data( {
    action: 'mainwp_installation_warning_hide'
  } );
  jQuery.post( ajaxurl, data, function () { } );
  return false;
} );

jQuery( document ).on( 'click', '.mainwp-notice-hide', function () {
  jQuery(this).closest('.ui.message').fadeOut("slow");
  return false;
} );

// Hide after installtion notices (PHP version, Trust MainWP Child, Multisite Warning and OpenSSL warning)
jQuery( document ).on( 'click', '.mainwp-notice-dismiss', function () {
  var notice_id = jQuery( this ).attr( 'notice-id' );
  jQuery(this).closest('.ui.message').fadeOut("slow");
  var data = {
    action: 'mainwp_notice_status_update'
  };
  data['notice_id'] = notice_id;
  jQuery.post( ajaxurl, mainwp_secure_data( data ), function () { } );
  return false;
} );

jQuery( document ).on( 'click', '.mainwp-activate-notice-dismiss', function () {
    jQuery( this ).closest( 'tr' ).fadeOut( "slow" );
    var data = mainwp_secure_data( {
        action: 'mainwp_dismiss_activate_notice',
        slug: jQuery( this ).closest( 'tr' ).attr( 'slug' )
    } );
    jQuery.post( ajaxurl, data, function () {
    } );
    return false;
} );


jQuery(document).on('click', '.mainwp-dismiss-twit', function(){
    jQuery(this).closest('.mainwp-tips').fadeOut("slow");
    mainwp_twitter_dismiss(this);
    return false;
});

mainwp_twitter_dismiss = function(obj) {
    var data = mainwp_secure_data({
        action:'mainwp_dismiss_twit',
        twitId: jQuery(obj).closest('.mainwp-tips').find('.mainwp-tip').attr('twit-id'),
        what: jQuery(obj).closest('.mainwp-tips').find('.mainwp-tip').attr('twit-what')
    } );

    jQuery.post( ajaxurl, data, function () {
        // Ok.
    });
};

jQuery( document ).on( 'click', 'button.mainwp_tweet_this', function(){
    var url = mainwpTweetUrlBuilder({
        text: jQuery(this).attr('msg')
    });
    window.open(url, 'Tweet', 'height=450,width=700');
    mainwp_twitter_dismiss(this);
});

mainwpTweetUrlBuilder = function(o){
    return [
        'https://twitter.com/intent/tweet?tw_p=tweetbutton',
        '&url=" "',
        '&text=', o.text
    ].join('');
};

mainwp_managesites_update_childsite_value = function ( siteId, uniqueId ) {
    var data = mainwp_secure_data( {
        action: 'mainwp_updatechildsite_value',
        site_id: siteId,
        unique_id: uniqueId
    } );
    jQuery.post( ajaxurl, data, function () {
    } );
    return false;
};

jQuery( document ).on( 'keyup', '#managegroups-filter', function () {
    var filter = jQuery( this ).val();
    var groupItems = jQuery( this ).parent().parent().find( 'li.managegroups-listitem' );
    for ( var i = 0; i < groupItems.length; i++ )
    {
        var currentElement = jQuery( groupItems[i] );
        if ( currentElement.hasClass( 'managegroups-group-add' ) )
            continue;
        var value = currentElement.find( 'span.text' ).text();
        if ( value.indexOf( filter ) > -1 )
        {
            currentElement.show();
        } else
        {
            currentElement.hide();
        }
    }
} );

bulkManageSitesMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
bulkManageSitesCurrentThreads = 0;
bulkManageSitesTotal = 0;
bulkManageSitesFinished = 0;
bulkManageSitesTaskRunning = false;


managesites_bulk_init = function () {
    jQuery( '.mainwp_append_error' ).remove();
    jQuery( '.mainwp_append_message' ).remove();
    jQuery( '#mainwp-message-zone' ).hide();

    if ( bulkManageSitesTaskRunning == false ) {
        bulkManageSitesMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
        bulkManageSitesCurrentThreads = 0;
        bulkManageSitesTotal = 0;
        bulkManageSitesFinished = 0;
        jQuery( '#mainwp-manage-sites-body-table .check-column INPUT:checkbox' ).each( function () {
            jQuery( this ).attr( 'status', 'queue' )
        } );
    }
};


managesites_bulk_done = function () {
  bulkManageSitesTaskRunning = false;
};

mainwp_managesites_bulk_remove_next = function () {
  while ( ( checkedBox = jQuery( '#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]:first' ) ) && ( checkedBox.length > 0 ) && ( bulkManageSitesCurrentThreads < bulkManageSitesMaxThreads ) ) {
    mainwp_managesites_bulk_remove_specific( checkedBox );
  }
  if ( ( bulkManageSitesTotal > 0 ) && ( bulkManageSitesFinished == bulkManageSitesTotal ) ) {
    managesites_bulk_done();
    setHtml( '#mainwp-message-zone', __( "Process completed. Reloading page..." ) );
    setTimeout( function () {
      window.location.reload()
    }, 3000 );
  }
}

mainwp_managesites_bulk_remove_specific = function ( pCheckedBox ) {
    pCheckedBox.attr( 'status', 'running' );
    var rowObj = pCheckedBox.closest( 'tr' );
    bulkManageSitesCurrentThreads++;

    var id = rowObj.attr( 'siteid' );

    rowObj.html( '<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Removing and deactivating the MainWP Child plugin...' + '</td>' );

    var data = mainwp_secure_data( {
      action: 'mainwp_removesite',
      id: id
    } );
    jQuery.post( ajaxurl, data, function ( response ) {
      bulkManageSitesCurrentThreads--;
      bulkManageSitesFinished++;
      rowObj.html( '<td colspan="999"></td>' );
      var result = '';
      var error = '';
      if ( response.error != undefined ) {
        error = response.error;
      } else if ( response.result == 'SUCCESS' ) {
        result = __( 'The site has been removed and the MainWP Child plugin has been disabled.' );
      } else if ( response.result == 'NOSITE' ) {
        error = __( 'Site not found. Please try again.' );
      } else {
        result = __( 'The site has been removed but the MainWP Child plugin could not be disabled.' );
      }

      if ( error != '' ) {
        rowObj.html( '<td colspan="999"><i class="red times icon"></i>' + error + '</td>' );
      }

      rowObj.html( '<td colspan="999"><i class="green check icon"></i>' + result + '</td>' );
      setTimeout( function () {
        jQuery( 'tr[siteid=' + id + ']' ).fadeOut( 1000 );
      }, 3000 );

      mainwp_managesites_bulk_remove_next();
    }, 'json' );
};


mainwp_managesites_bulk_refresh_favico = function ( siteIds )
{
    var allWebsiteIds = jQuery( '.dashboard_wp_id' ).map( function ( indx, el ) {
        return jQuery( el ).val();
    } );

    var selectedIds = [ ], excludeIds = [ ];
    if ( siteIds instanceof Array ) {
        jQuery.grep( allWebsiteIds, function ( el ) {
            if ( jQuery.inArray( el, siteIds ) !== -1 ) {
                selectedIds.push( el );
            } else {
                excludeIds.push( el );
            }
        } );
        for ( var i = 0; i < excludeIds.length; i++ )
        {
            dashboard_update_site_hide( excludeIds[i] );
        }
        allWebsiteIds = selectedIds;
        //jQuery('#refresh-status-total').text(allWebsiteIds.length);
    }

    var nrOfWebsites = allWebsiteIds.length;

    if ( nrOfWebsites == 0 )
        return false;

    var siteNames = { };

    for ( var i = 0; i < allWebsiteIds.length; i++ )
    {
        dashboard_update_site_status( allWebsiteIds[i], '<i class="clock outline icon"></i>');
        siteNames[allWebsiteIds[i]] = jQuery( '.sync-site-status[siteid="' + allWebsiteIds[i] + '"]' ).attr( 'niceurl' );
    }
    var initData = {
        total: allWebsiteIds.length,
        pMax: nrOfWebsites,
        title: 'Refresh Favicon',
        statusText: __( 'updated' ),
        callback: function () {
            bulkManageSitesTaskRunning = false;
            window.location.href = location.href;
        }
    };
    mainwpPopup( '#mainwp-sync-sites-modal' ).init( initData );

    mainwp_managesites_refresh_favico_all_int(allWebsiteIds);
};

mainwp_managesites_refresh_favico_all_int = function ( websiteIds )
{
    websitesToUpgrade = websiteIds;
    currentWebsite = 0;
    websitesDone = 0;
    websitesTotal = websitesLeft = websitesToUpgrade.length;

    bulkTaskRunning = true;
    mainwp_managesites_refresh_favico_all_loop_next();
};

mainwp_managesites_refresh_favico_all_loop_next = function ()
{
    while ( bulkTaskRunning && ( currentThreads < maxThreads ) && ( websitesLeft > 0 ) )
    {
        mainwp_managesites_refresh_favico_all_upgrade_next();
    }
};
mainwp_managesites_refresh_favico_all_upgrade_next = function ()
{
    currentThreads++;
    websitesLeft--;

    var websiteId = websitesToUpgrade[currentWebsite++];
    dashboard_update_site_status( websiteId, '<i class="sync alternate loading icon"></i>' );

    mainwp_managesites_refresh_favico_int( websiteId );
};

mainwp_managesites_refresh_favico_int = function( siteid ) {

    var data = mainwp_secure_data({
        action:'mainwp_get_site_icon',
        siteId: siteid
    });

    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function(pSiteid) { return function (response) {
            currentThreads--;
            if (response.error != undefined)
            {
                dashboard_update_site_status( pSiteid, '<i class="red times icon"></i>' );
            } else if (response.result && response.result == 'success') {
                dashboard_update_site_status(pSiteid, '<i class="green check icon"></i>', true );
            } else {
                dashboard_update_site_status( pSiteid, '<i class="red times icon"></i>' );
            }
            mainwp_managesites_refresh_favico_all_loop_next();
        } }( siteid),
        dataType: 'json'});
    return false;
};

// for normal checkboxes
jQuery( document ).on( 'change', '#cb-select-all-top, #cb-select-all-bottom', function () {
        var $this = jQuery(this),
        $table = $this.closest('table'),
        controlChecked = $this.prop('checked');

        //console.log($table);

        if ( $table.length == 0)
            return false;

        $table.children( 'tbody' ).filter(':visible')
        .children().children('.check-column').find(':checkbox')
        .prop('checked', function() {
            if ( jQuery(this).is(':hidden,:disabled') ) {
                    return false;
            }
            if ( controlChecked ) {
                    return true;
            }
            return false;
        });

        $table.children('thead,  tfoot').filter(':visible')
        .children().children('.check-column').find(':checkbox')
        .prop('checked', function() {
            if ( controlChecked ) {
                    return true;
            }
            return false;
        });
} );


// Trigger Manage Sites Bulk Actions
jQuery( document ).on( 'click', '#mainwp-do-sites-bulk-actions', function () {
  var action = jQuery( "#mainwp-sites-bulk-actions-menu" ).dropdown( "get value" );
  console.log(action);
  if ( action == '' )
    return false;
  mainwp_managesites_doaction( action );
  return false;
} );

// Manage Sites Bulk Actions
mainwp_managesites_doaction = function ( action ) {

  if ( action == 'delete' || action == 'test_connection' || action == 'sync' || action == 'reconnect' || action == 'update_plugins' || action == 'update_themes' || action == 'update_wpcore' || action == 'update_translations' || action == 'refresh_favico' ) {

    if ( bulkManageSitesTaskRunning )
      return false;

    if ( action == 'delete' || action == 'update_plugins' || action == 'update_themes' || action == 'update_wpcore' || action == 'update_translations' ) {
      var confirmMsg = '';
      var _selection_cancelled = false;      
      switch ( action ) {
            case 'delete':
                confirmMsg = __( "You are about to remove the selected sites from your MainWP Dashboard?" );
            break;
            case 'update_plugins':
                confirmMsg = __( "You are about to update plugins on the selected sites?" );
                _selection_cancelled = true;
            break;
            case 'update_themes':
                confirmMsg = __( "You are about to update themes on the selected sites?" );
                _selection_cancelled = true;
            break;
            case 'update_wpcore':
                confirmMsg = __( "You are about to update WordPress core files on the selected sites?" );
                _selection_cancelled = true;
            break;
            case 'update_translations':
                confirmMsg = __( "You are about to update translations on the selected sites?" );
                _selection_cancelled = true;
            break;            
      }
      
      if ( confirmMsg == '' )
        return false;
      var _cancelled_callback  = null;
      if ( _selection_cancelled ) {
        _cancelled_callback  = function() {
          jQuery('#mainwp-sites-bulk-actions-menu').dropdown("set selected", "sync");
        };
      }

      var updateType; // undefined

      if ( action == 'update_plugins' || action == 'update_themes'  || action == 'update_translations' ) {
          updateType = 2; // multi update
      }

      mainwp_confirm( confirmMsg, _managesites_doaction_callback, _cancelled_callback, updateType );
      return false; // return those case
    }

    _managesites_doaction_callback(); // other case callback

    return false;
  }

  jQuery( '#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked' ).each( function () {
    var row = jQuery( this ).closest( 'tr' );
    switch ( action ) {
      case 'open_wpadmin':
        var url = row.find( 'a.open_newwindow_wpadmin' ).attr( 'href' );
        window.open( url, '_blank' );
        break;
      case 'open_frontpage':
        var url = row.find( 'a.open_site_url' ).attr( 'href' );
        window.open( url, '_blank' );
        break;
      }
  } );
  return false;
}

_managesites_doaction_callback  = function() {
      managesites_bulk_init();
      bulkManageSitesTotal = jQuery( '#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]' ).length;
      bulkManageSitesTaskRunning = true;

      if ( action == 'delete' ) {
        mainwp_managesites_bulk_remove_next();
        return false;
      } else if ( action == 'sync' ) {
        var syncIds = jQuery.map( jQuery( '#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked' ), function ( el ) {
          return jQuery( el ).val();
        } );
        mainwp_sync_sites_data( syncIds );
      } else if ( action == 'reconnect' ) {
        mainwp_managesites_bulk_reconnect_next();
      } else if ( action == 'update_plugins' ) {
        var selectedIds = jQuery.map( jQuery( '#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked' ), function ( el ) {
          return jQuery( el ).val();
        } );
        mainwp_update_pluginsthemes( 'plugin', selectedIds );
      } else if ( action == 'update_themes' ) {
        var selectedIds = jQuery.map( jQuery( '#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked' ), function ( el ) {
          return jQuery( el ).val();
        } );
        mainwp_update_pluginsthemes( 'theme', selectedIds );
      } else if ( action == 'update_wpcore' ) {
        var selectedIds = jQuery.map( jQuery( '#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked' ), function ( el ) {
          return jQuery( el ).val();
        } );
        managesites_wordpress_global_upgrade_all( selectedIds );
      } else if ( action == 'update_translations' ) {
        var selectedIds = jQuery.map( jQuery( '#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked' ), function ( el ) {
          return jQuery( el ).val();
        } );
        mainwp_update_pluginsthemes( 'translation', selectedIds );
      } else if (action == 'refresh_favico') {
        var selectedIds = jQuery.map( jQuery( '#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked' ), function( el ) { return jQuery( el ).val(); });
        mainwp_managesites_bulk_refresh_favico(selectedIds);
      }
}
    

jQuery( document ).on( 'click', '.managesites_syncdata', function () {
    var row = jQuery( this ).closest( 'tr' );
    var syncIds = [ ];
    syncIds.push( row.attr( 'siteid' ) );
    mainwp_sync_sites_data( syncIds );
    return false;
} );

jQuery( document ).on( 'click', '.mainwpactionlogsline', function () {
    jQuery( this ).next().toggle();
} );

jQuery(document).on( 'change', '#mainwp-add-new-button', function(){
    var url = jQuery( '#mainwp-add-new-button :selected' ).attr('item-url');
    if ( typeof url !== 'undefined' && url != '')
        location.href = url;
    return false;
});

mainwp_managesites_bulk_reconnect_next = function () {
  while ( ( checkedBox = jQuery( '#mainwp-manage-sites-body-table .check-column INPUT:checkbox:checked[status="queue"]:first' ) ) && ( checkedBox.length > 0 ) && ( bulkManageSitesCurrentThreads < bulkManageSitesMaxThreads ) ) {
    mainwp_managesites_bulk_reconnect_specific( checkedBox );
  }
  if ( ( bulkManageSitesTotal > 0 ) && ( bulkManageSitesFinished == bulkManageSitesTotal ) ) {
    managesites_bulk_done();
    setHtml( '#mainwp-message-zone', __( "Process completed. Reloading page..." ) );
    setTimeout( function () {
      window.location.reload()
    }, 3000 );
  }
}

mainwp_managesites_bulk_reconnect_specific = function ( pCheckedBox ) {

    pCheckedBox.attr( 'status', 'running' );
    var rowObj = pCheckedBox.closest( 'tr' );
    var siteUrl = rowObj.attr( 'site-url' );
    var siteId = rowObj.attr( 'siteid' );

    // skip reconnect sites without sync error
    if ( rowObj.find('td.site-sync-error' ).length == 0 ) {
      bulkManageSitesFinished++;
      mainwp_managesites_bulk_reconnect_next();
      return;
    }

    bulkManageSitesCurrentThreads++;


    rowObj.html( '<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Trying to reconnect. Please wait...' + '</td>' );

    var data = mainwp_secure_data( {
      action: 'mainwp_reconnectwp',
      siteid: siteId
    } );

    jQuery.post( ajaxurl, data, function ( response ) {
      bulkManageSitesCurrentThreads--;
      bulkManageSitesFinished++;
      rowObj.html( '<td colspan="999"></td>' );

      response = jQuery.trim( response );
        var msg = '', error = '';
        if ( response.substr( 0, 5 ) == 'ERROR' ) {
          if ( response.length == 5 ) {
            error = __( 'Undefined error occured. Please try again.' );
          } else {
            error = response.substr( 6 );
          }
          error = siteUrl + ' - ' + error;
        } else {
          msg = siteUrl + ' - ' + response;
        }

        if ( msg != '' ) {
          rowObj.removeClass( 'error' );
          rowObj.addClass( 'positive' );
          rowObj.html( '<td colspan="999"><i class="green check icon"></i>' + msg + '</td>' );
        } else if ( error != '' ) {
          rowObj.html( '<td colspan="999"><i class="red times icon"></i>' + error + '</td>' );
        }
        mainwp_managesites_bulk_reconnect_next();
    } );

    return;
};

function mainwp_according_table_sorting( pObj ) {
    var table, th, rows, switching, i, x, y, xVal, yVal, campare = false, shouldSwitch = false, dir, switchcount = 0, n, skip = 1;
    table = jQuery(pObj).closest('table')[0];

    // get TH element
    if (jQuery(pObj)[0].tagName == 'TH') {
        th = jQuery(pObj)[0];
    } else {
        th = jQuery(pObj).closest('th')[0];
    }

    n = th.cellIndex;
    switching = true;

    // check header and footer of according table
    if( jQuery(table).children('thead,tfoot').length > 0)
        skip+=jQuery(table).children('thead,tfoot').length; // skip sorting header, footer

    dir = "asc";
    /* loop until switching has been done: */
    while (switching) {
      switching = false;
      rows = table.rows;

      /* Loop through all table rows */
      for (i = 1; i < (rows.length - skip); i+=2) {  // skip content according rows, sort by title rows only
            shouldSwitch = false;
            /* Get the two elements you want to compare,
            one from current row and one from the next-next: */
            x = rows[i].getElementsByTagName("TD")[n];
            y = rows[i + 2].getElementsByTagName("TD")[n];

            // if sort value attribute existed then sorting on that else sorting on cell value
            if (x.hasAttribute('sort-value')) {
                xVal = parseInt(x.getAttribute('sort-value'));
                yVal = parseInt(y.getAttribute('sort-value'));
                campare = ( xVal == yVal ) ? 0 : ( xVal > yVal ? -1 : 1 );
            } else {
                // to prevent text() clear text content
                xVal = '<p>' + x.innerHTML + '</p>';
                yVal = '<p>' + y.innerHTML + '</p>';
                xVal = jQuery(xVal).text().trim().toLowerCase();
                yVal = jQuery(yVal).text().trim().toLowerCase();
                campare = yVal.localeCompare(xVal);
            }

            /* Check if the two rows should switch place */
            if (dir == "asc") {
              if ( campare < 0 ) { //xVal > yVal
                shouldSwitch = true;
                // break the loop:
                break;
              }
            } else if (dir == "desc") {
              if (campare > 0) { //xVal < yVal
                // break the loop:
                shouldSwitch = true;
                break;
              }
            }
      }
      if (shouldSwitch) {
            rows[i].parentNode.insertBefore(rows[i + 2], rows[i]);
            rows[i+1].parentNode.insertBefore(rows[i + 3], rows[i+1]);
            switching = true;
            // increase this count by 1, that is ok
            switchcount ++;
      } else {
            /* If no switching has been done AND the direction is "asc",
            set the direction to "desc" and run the while loop again. */
            if (switchcount == 0 && dir == "asc") {
              dir = "desc";
              switching = true;
            }
      }
    }

    // no row sorting so change direction for arrows switch
    if (switchcount == 0) {
        if (jQuery(pObj).hasClass('ascending')){
            dir = "desc";
        } else {
            dir = "asc";
        }
    }

    // add/remove class for arrows displaying
    if (dir == "asc") {
        jQuery(pObj).addClass('ascending');
        jQuery(pObj).removeClass('descending');
    } else {
        jQuery(pObj).removeClass('ascending');
        jQuery(pObj).addClass('descending');
    }
}

jQuery( document ).ready( function () {
    jQuery( '.handle-accordion-sorting' ).on( 'click', function() {
            mainwp_according_table_sorting( this );
            return false;
    } );
} );
                                
mainwp_force_destroy_sessions = function () {
  var confirmMsg = __( 'Forces your dashboard to reconnect with your child sites?' );
  mainwp_confirm( confirmMsg, function() {
    mainwp_force_destroy_sessions_websites = jQuery( '.dashboard_wp_id' ).map( function ( indx, el ) {
      return jQuery( el ).val();
    } );
    mainwpPopup( '#mainwp-sync-sites-modal' ).init( { pMax: mainwp_force_destroy_sessions_websites.length } );
    mainwp_force_destroy_sessions_part_2( 0 );
  });
};

mainwp_force_destroy_sessions_part_2 = function ( id ) {
  if ( id >= mainwp_force_destroy_sessions_websites.length ) {
    mainwp_force_destroy_sessions_websites = [ ];
    if ( mainwp_force_destroy_sessions_successed == mainwp_force_destroy_sessions_websites.length ) {
      setTimeout( function ()
      {
       mainwpPopup( '#mainwp-sync-sites-modal' ).close(true);
      }, 3000 );
    }
    mainwpPopup( '#mainwp-sync-sites-modal' ).close(true);
    return;
  }

  var website_id = mainwp_force_destroy_sessions_websites[id];
  dashboard_update_site_status( website_id, '<i class="sync alternate loading icon"></i>' );

  jQuery.post( ajaxurl, { 'action': 'mainwp_force_destroy_sessions', 'website_id': website_id, 'security': security_nonces['mainwp_force_destroy_sessions'] }, function ( response ) {
    var counter = id + 1;
    mainwp_force_destroy_sessions_part_2( counter );

    mainwpPopup( '#mainwp-sync-sites-modal' ).setProgressValue( counter );

    if ( 'error' in response ) {
      dashboard_update_site_status( website_id, '<i class="exclamation red icon"></i>' );
    } else if ( 'success' in response ) {
      mainwp_force_destroy_sessions_successed += 1;
      dashboard_update_site_status( website_id, '<i class="check green icon"></i>', true );
    } else {
      dashboard_update_site_status( website_id, '<i class="exclamation yellow icon"></i>' );
    }
  }, 'json' ).fail( function () {
    var counter = id + 1;
    mainwp_force_destroy_sessions_part_2( counter );
    mainwpPopup( '#mainwp-sync-sites-modal' ).setProgressValue( counter );

    dashboard_update_site_status( website_id, '<i class="exclamation red icon"></i>' );
  } );

};

var mainwp_force_destroy_sessions_successed = 0;
var mainwp_force_destroy_sessions_websites = [];


jQuery( document ).on( 'change', '#mainwp_archiveFormat', function ()
{
    var zipMethod = jQuery( this ).val();
    zipMethod = zipMethod.replace( /\./g, '\\.' );
    jQuery( 'span.archive_info' ).hide();
    jQuery( 'span#info_' + zipMethod ).show();

    jQuery( 'tr.archive_method' ).hide();
    jQuery( 'tr.archive_' + zipMethod ).show();

    // compare new layout
    jQuery( 'div.archive_method' ).hide();
    jQuery( 'div.archive_' + zipMethod ).show();
} );


// MainWP Tools
jQuery( document ).ready( function () {
    jQuery( document ).on( 'click', '#force-destroy-sessions-button', function () {
        mainwp_force_destroy_sessions();
    } );
} );


jQuery( document ).ready( function () {
    if ( jQuery('body.mainwp-ui').length > 0 ) {
        jQuery('.mainwp-ui-page .ui.dropdown:not(.not-auto-init)').dropdown();
        jQuery('.mainwp-ui-page .ui.checkbox:not(.not-auto-init)').checkbox();
        jQuery('.mainwp-ui-page .ui.dropdown').filter('[init-value]').each(function(){
            var values = jQuery(this).attr('init-value').split(',');
            jQuery(this).dropdown('set selected',values);
        });
    }

} );


/**
 * MainWP Child Scan
 **/

jQuery( document ).on( 'click', '.mwp-child-scan', function () {
    mwp_start_childscan();
} );
var childsToScan = [ ];
mwp_start_childscan = function ()
{
    jQuery( '#mwp_child_scan_childsites tr' ).each( function () {
        var id = jQuery( this ).attr( 'siteid' );
        if ( id == undefined || id == '' )
            return;
        childsToScan.push( id );
    } );

    mwp_childscan_next();
};

mwp_childscan_next = function ()
{
    if ( childsToScan.length == 0 )
        return;

    var childId = childsToScan.shift();

    jQuery( 'tr[siteid="' + childId + '"]' ).children().last().html( 'Scanning' );

    var data = mainwp_secure_data( {
        action: 'mainwp_childscan',
        childId: childId
    } );

    jQuery.ajax( {
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function ( pId ) {
            return function ( response ) {
                var tr = jQuery( 'tr[siteid="' + pId + '"]' );
                if ( response.success ) {
                    tr.children().last().html( response.success );
                    tr.attr( 'siteid', '' );
                } else if ( response.error ) {
                    tr.children().last().html( 'Error: ' + response.error );
                } else {
                    tr.children().last().html( 'Error while contacting site!' );
                }
                mwp_childscan_next();
            }
        }( childId ),
        error: function ( pId ) {
            return function () {
                jQuery( 'tr[siteid="' + pId + '"]' ).children().last().html( 'Error while contacting site!' );
                mwp_childscan_next();
            }
        }( childId ),
        dataType: 'json'
    } );
};

jQuery( document ).ready( function () {
    if ( typeof postboxes !== "undefined" && typeof mainwp_postbox_page !== "undefined" ) {
        postboxes.add_postbox_toggles( mainwp_postbox_page );
    }
} );

jQuery( document ).on( 'click', '.close.icon', function () {
    jQuery( this ).parent().hide();
} );
