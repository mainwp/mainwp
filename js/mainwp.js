jQuery(document).ready(function ()
{
    // to fix conflict with bootstrap tooltip
    jQuery.widget.bridge('uitooltip', jQuery.ui.tooltip);
    jQuery(document).uitooltip({
        items:"span.tooltip",
        track:true,
        tooltipClass: "mainwp-tooltip",
        content:function ()
        {
            var element = jQuery(this);
            return element.parents('.tooltipcontainer').children('.tooltipcontent').html();
        }
    });

//    if (jQuery('#mainwp_options_loadFilesBeforeZip_container').length > 0) initTriStateCheckBox('mainwp_options_loadFilesBeforeZip_container', 'mainwp_options_loadFilesBeforeZip', true);
});

/**
 * Global
 */
jQuery(document).ready(function () {
    jQuery('.mainwp-row').live({
        mouseenter:function () {
            rowMouseEnter(this);
        },
        mouseleave:function () {
            rowMouseLeave(this);
        }
    });
});
rowMouseEnter = function (elem) {
    if (!jQuery(elem).children('.mainwp-row-actions-working').is(":visible")) jQuery(elem).children('.mainwp-row-actions').show();
};
rowMouseLeave = function (elem) {
    if (jQuery(elem).children('.mainwp-row-actions').is(":visible")) jQuery(elem).children('.mainwp-row-actions').hide();
};

/**
 * Recent posts
 */
jQuery(document).ready(function () {
    jQuery('.mainwp-post-unpublish').live('click', function () {
        postAction(jQuery(this), 'unpublish');
        return false;
    });
    jQuery('.mainwp-post-publish').live('click', function () {
        postAction(jQuery(this), 'publish');
        return false;
    });
    jQuery('.mainwp-post-trash').live('click', function () {
        postAction(jQuery(this), 'trash');
        return false;
    });
    jQuery('.mainwp-post-restore').live('click', function () {
        postAction(jQuery(this), 'restore');
        return false;
    });
    jQuery('.mainwp-post-delete').live('click', function () {
        postAction(jQuery(this), 'delete');
        return false;
    });
    jQuery('.recent_posts_published_lnk').live('click', function () {
        showRecentPostsList(jQuery(this), true, false, false, false, false);
        return false;
    });
    jQuery('.recent_posts_draft_lnk').live('click', function () {
        showRecentPostsList(jQuery(this), false, true, false, false, false);
        return false;
    });
    jQuery('.recent_posts_pending_lnk').live('click', function () {
        showRecentPostsList(jQuery(this), false, false, true, false, false);
        return false;
    });
    jQuery('.recent_posts_trash_lnk').live('click', function () {
        showRecentPostsList(jQuery(this), false, false, false, true, false);
        return false;
    });
    jQuery('.recent_posts_future_lnk').live('click', function () {
        showRecentPostsList(jQuery(this), false, false, false, false, true);
        return false;
    });
    jQuery('.plugins_actived_lnk').live('click', function () {
        showPluginsList(jQuery(this), true, false);
        return false;
    });
    jQuery('.plugins_inactive_lnk').live('click', function () {
        showPluginsList(jQuery(this), false, true);
        return false;
    });
    jQuery('.themes_actived_lnk').live('click', function () {
        showThemesList(jQuery(this), true, false);
        return false;
    });
    jQuery('.themes_inactive_lnk').live('click', function () {
        showThemesList(jQuery(this), false, true);
        return false;
    });

});

postAction = function (elem, what) {
    var rowElement = jQuery(elem).parent().parent();
    var postId = rowElement.children('.postId').val();
    var websiteId = rowElement.children('.websiteId').val();

    var data = mainwp_secure_data({
        action:'mainwp_post_' + what,
        postId:postId,
        websiteId:websiteId
    });
    rowElement.children('.mainwp-row-actions').hide();
    rowElement.children('.mainwp-row-actions-working').show();
    jQuery.post(ajaxurl, data, function (response) {
        if (response.error) {
            rowElement.html('<span style="color: #a00;"><i class="fa fa-exclamation-circle"></i> '+response.error+'</span>');
        }
        else if (response.result) {
            rowElement.html('<span style="color: #0073aa;"><i class="fa fa-check-circle"></i> '+response.result+'</span>');
        }
        else {
            rowElement.children('.mainwp-row-actions-working').hide();
        }
    }, 'json');

    return false;
};



/**
 * Plugins Widget
 */
jQuery(document).ready(function () {
    jQuery('.mainwp-plugin-deactivate').live('click', function () {
        pluginAction(jQuery(this), 'deactivate');
        return false;
    });
    jQuery('.mainwp-plugin-activate').live('click', function () {
        pluginAction(jQuery(this), 'activate');
        return false;
    });
    jQuery('.mainwp-plugin-delete').live('click', function () {
        pluginAction(jQuery(this), 'delete');
        return false;
    });
});

pluginAction = function (elem, what) {
    var rowElement = jQuery(elem).parent().parent();
    var plugin = rowElement.children('.pluginSlug').val();
    var websiteId = rowElement.children('.websiteId').val();

    var data = mainwp_secure_data({
        action:'mainwp_widget_plugin_' + what,
        plugin:plugin,
        websiteId:websiteId
    });
    rowElement.children('.mainwp-row-actions-working').show();
    jQuery.post(ajaxurl, data, function (response) {
        if (response && response.error) {
            rowElement.html('<span style="color: #a00;"><i class="fa fa-exclamation-circle"></i> '+response.error+'</span>');
        }
        else if (response && response.result) {
            rowElement.html('<span style="color: #0073aa;"><i class="fa fa-check-circle"></i> '+response.result+'<span>');
        }
        else {
            rowElement.children('.mainwp-row-actions-working').hide();
        }
    }, 'json');

    return false;
};

/**
 * Themes Widget
 */
jQuery(document).ready(function () {
    jQuery('.mainwp-theme-activate').live('click', function () {
        themeAction(jQuery(this), 'activate');
        return false;
    });
    jQuery('.mainwp-theme-delete').live('click', function () {
        themeAction(jQuery(this), 'delete');
        return false;
    });
});

themeAction = function (elem, what) {
    var rowElement = jQuery(elem).parent().parent();
    var theme = rowElement.children('.themeName').val();
    var websiteId = rowElement.children('.websiteId').val();

    var data = mainwp_secure_data({
        action:'mainwp_widget_theme_' + what,
        theme:theme,
        websiteId:websiteId
    });
    rowElement.children('.mainwp-row-actions-working').show();
    jQuery.post(ajaxurl, data, function (response) {
        if (response && response.error) {
            rowElement.html('<span style="color: #a00;"><i class="fa fa-exclamation-circle"></i> '+response.error+'</span>');
        }
        else if (response && response.result) {
            rowElement.html('<span style="color: #0073aa;"><i class="fa fa-check-circle"></i> '+response.result+'<span>');
        }
        else {
            rowElement.children('.mainwp-row-actions-working').hide();
        }
    }, 'json');

    return false;
};



showRecentPostsList = function (pElement, published, draft, pending, trash, future) {
    var recent_posts_published_lnk = pElement.parent().parent().find(".recent_posts_published_lnk");
    if (published) recent_posts_published_lnk.addClass('mainwp_action_down');
    else recent_posts_published_lnk.removeClass('mainwp_action_down');

    var recent_posts_draft_lnk = pElement.parent().parent().find(".recent_posts_draft_lnk");
    if (draft) recent_posts_draft_lnk.addClass('mainwp_action_down');
    else recent_posts_draft_lnk.removeClass('mainwp_action_down');

    var recent_posts_pending_lnk = pElement.parent().parent().find(".recent_posts_pending_lnk");
    if (pending) recent_posts_pending_lnk.addClass('mainwp_action_down');
    else recent_posts_pending_lnk.removeClass('mainwp_action_down');

    var recent_posts_trash_lnk = pElement.parent().parent().find(".recent_posts_trash_lnk");
    if (trash) recent_posts_trash_lnk.addClass('mainwp_action_down');
    else recent_posts_trash_lnk.removeClass('mainwp_action_down');

    var recent_posts_future_lnk = pElement.parent().parent().find(".recent_posts_future_lnk");
    if (future) recent_posts_future_lnk.addClass('mainwp_action_down');
    else recent_posts_future_lnk.removeClass('mainwp_action_down');


    var recent_posts_published = pElement.parent().parent().find(".recent_posts_published");
    var recent_posts_draft = pElement.parent().parent().find(".recent_posts_draft");
    var recent_posts_pending = pElement.parent().parent().find(".recent_posts_pending");
    var recent_posts_trash = pElement.parent().parent().find(".recent_posts_trash");
    var recent_posts_future = pElement.parent().parent().find(".recent_posts_future");

    if (published) recent_posts_published.show();
    if (draft) recent_posts_draft.show();
    if (pending) recent_posts_pending.show();
    if (trash) recent_posts_trash.show();
    if (future) recent_posts_future.show();

    if (!published) recent_posts_published.hide();
    if (!draft) recent_posts_draft.hide();
    if (!pending) recent_posts_pending.hide();
    if (!trash) recent_posts_trash.hide();
    if (!future) recent_posts_future.hide();
};

showPluginsList  = function (pElement, activate, inactivate) {
    var plugins_actived_lnk = pElement.parent().parent().find(".plugins_actived_lnk");
    if (activate) plugins_actived_lnk.addClass('mainwp_action_down');
    else plugins_actived_lnk.removeClass('mainwp_action_down');

    var plugins_inactive_lnk = pElement.parent().parent().find(".plugins_inactive_lnk");
    if (inactivate) plugins_inactive_lnk.addClass('mainwp_action_down');
    else plugins_inactive_lnk.removeClass('mainwp_action_down');

    var plugins_activate = pElement.parent().parent().find(".mainwp_plugins_active");
    var plugins_inactivate = pElement.parent().parent().find(".mainwp_plugins_inactive");

    if (activate) plugins_activate.show();
    if (inactivate) plugins_inactivate.show();

    if (!activate) plugins_activate.hide();
    if (!inactivate) plugins_inactivate.hide();

};


showThemesList  = function (pElement, activate, inactivate) {
    var themes_actived_lnk = pElement.parent().parent().find(".themes_actived_lnk");
    if (activate) themes_actived_lnk.addClass('mainwp_action_down');
    else themes_actived_lnk.removeClass('mainwp_action_down');

    var themes_inactive_lnk = pElement.parent().parent().find(".themes_inactive_lnk");
    if (inactivate) themes_inactive_lnk.addClass('mainwp_action_down');
    else themes_inactive_lnk.removeClass('mainwp_action_down');

    var themes_activate = pElement.parent().parent().find(".mainwp_themes_active");
    var themes_inactivate = pElement.parent().parent().find(".mainwp_themes_inactive");

    if (activate) themes_activate.show();
    if (inactivate) themes_inactivate.show();

    if (!activate) themes_activate.hide();
    if (!inactivate) themes_inactivate.hide();

};

// offsetRelative (or, if you prefer, positionRelative)
(function ($) {
    $.fn.offsetRelative = function (top) {
        var $this = $(this);
        var $parent = $this.offsetParent();
        var offset = $this.position();
        if (!top) return offset; // Didn't pass a 'top' element
        else if ($parent.get(0).tagName == "BODY") return offset; // Reached top of document
        else if ($(top, $parent).length) return offset; // Parent element contains the 'top' element we want the offset to be relative to
        else if ($parent[0] == $(top)[0]) return offset; // Reached the 'top' element we want the offset to be relative to
        else { // Get parent's relative offset
            var parent_offset = $parent.offsetRelative(top);
            offset.top += parent_offset.top;
            offset.left += parent_offset.left;
            return offset;
        }
    };
    $.fn.positionRelative = function (top) {
        return $(this).offsetRelative(top);
    };
}(jQuery));

var hidingSubMenuTimers = {};
jQuery(document).ready(function () {
    jQuery('span[id^=mainwp]').each(function () {
        jQuery(this).parent().parent().hover(function () {
            var spanEl = jQuery(this).find('span[id^=mainwp]');
            var spanId;
            if (spanId = /^mainwp-(.*)$/.exec(spanEl.attr('id'))) {
                if (hidingSubMenuTimers[spanId[1]]) {
                    clearTimeout(hidingSubMenuTimers[spanId[1]]);
                }
                var currentMenu = jQuery('#menu-mainwp-' + spanId[1]);
                var offsetVal = jQuery(this).offset();
                currentMenu.css('left', offsetVal.left + jQuery(this).outerWidth() - 30);

                currentMenu.css('top', offsetVal.top - 15 - jQuery(this).outerHeight()); // + tmp);
                subMenuIn(spanId[1]);
            }
        }, function () {
            var spanEl = jQuery(this).find('span[id^=mainwp]');
            if (spanId = /^mainwp-(.*)$/.exec(spanEl.attr('id'))) {
                hidingSubMenuTimers[spanId[1]] = setTimeout(function (span) {
                    return function () {
                        subMenuOut(span);
                    };
                }(spanId[1]), 30);
            }
        });
    });
    jQuery('.mainwp-submenu-wrapper').on({
        mouseenter:function () {
            if (spanId = /^menu-mainwp-(.*)$/.exec(jQuery(this).attr('id'))) {
                if (hidingSubMenuTimers[spanId[1]]) {
                    clearTimeout(hidingSubMenuTimers[spanId[1]]);
                }
            }
        },
        mouseleave:function () {
            if (spanId = /^menu-mainwp-(.*)$/.exec(jQuery(this).attr('id'))) {
                hidingSubMenuTimers[spanId[1]] = setTimeout(function (span) {
                    return function () {
                        subMenuOut(span);
                    };
                }(spanId[1]), 30);
            }
        }
    });
});
subMenuIn = function (subName) {
    jQuery('#menu-mainwp-' + subName).show();
    jQuery('#mainwp-' + subName).parent().parent().addClass('hoverli');
    jQuery('#mainwp-' + subName).parent().parent().css('background-color', '#EAF2FA');
    jQuery('#mainwp-' + subName).css('color', '#333');
};
subMenuOut = function (subName) {
    jQuery('#menu-mainwp-' + subName).hide();
    jQuery('#mainwp-' + subName).parent().parent().css('background-color', '');
    jQuery('#mainwp-' + subName).parent().parent().removeClass('hoverli');
    jQuery('#mainwp-' + subName).css('color', '');
};

/**
 * Required
 */
show_error = function (id, text, append) {
    if (append == true) {
        var currentHtml = jQuery('#' + id).html();
        if (currentHtml == null) currentHtml = "";
        if (currentHtml.indexOf('<p>') == 0) {
            currentHtml = currentHtml.substr(3, currentHtml.length - 7);
        }
        if (currentHtml != '') {
            currentHtml += '<br />' + text;
        }
        else {
            currentHtml = text;
        }
        jQuery('#' + id).html('<p>' + currentHtml + '</p>');
    }
    else {
        jQuery('#' + id).html('<p>' + text + '</p>');
    }
    jQuery('#' + id).show();
    // automatically scroll to error message if it's not visible
    var scrolltop = jQuery(window).scrollTop();
    var off = jQuery('#' + id).offset();
    if (scrolltop > off.top - 40)
        jQuery('html, body').animate({
            scrollTop:off.top - 40
        }, 1000, function () {
            shake_element('#' + id)
        });
    else
        shake_element('#' + id); // shake the error message to get attention :)

};
hide_error = function (id) {
    var idElement = jQuery('#' + id);
    idElement.html("");
    idElement.hide();
};
jQuery(document).ready(function () {
    jQuery('div.mainwp-hidden').parent().parent().css("display", "none");
});

/**
 * SecurityIssues
 */
//var securityIssues_fixes = ['listing', 'wp_version', 'rsd', 'wlw', 'core_updates', 'plugin_updates', 'theme_updates', 'file_perms', 'db_reporting', 'php_reporting', 'versions', 'admin'];
var securityIssues_fixes = ['listing', 'wp_version', 'rsd', 'wlw', 'core_updates', 'plugin_updates', 'theme_updates', 'db_reporting', 'php_reporting', 'versions', 'admin', 'readme'];
jQuery(document).ready(function () {
    var securityIssueSite = jQuery('#securityIssueSite');
    if ((securityIssueSite.val() != null) && (securityIssueSite.val() != "")) {
        jQuery('#securityIssues_fixAll').live('click', function (event) {
            securityIssues_fix('all');
        });
        jQuery('#securityIssues_refresh').live('click', function (event) {
            for (var i = 0; i < securityIssues_fixes.length; i++) {
                var securityIssueCurrentIssue = jQuery('#' + securityIssues_fixes[i] + '_fix');
                if (securityIssueCurrentIssue) {
                    securityIssueCurrentIssue.hide();
                }
                jQuery('#' + securityIssues_fixes[i] + '_extra').hide();
                jQuery('#' + securityIssues_fixes[i] + '_ok').hide();
                jQuery('#' + securityIssues_fixes[i] + '_nok').hide();
                jQuery('#' + securityIssues_fixes[i] + '_loading').show();
            }
            securityIssues_request(jQuery('#securityIssueSite').val());
        });
        for (var i = 0; i < securityIssues_fixes.length; i++) {
            jQuery('#' + securityIssues_fixes[i] + '_fix').bind('click', function (what) {
                return function (event) {
                    securityIssues_fix(what);
                    return false;
                }
            }(securityIssues_fixes[i]));
            if (securityIssues_fixes[i] == 'readme') continue;
            jQuery('#' + securityIssues_fixes[i] + '_unfix').bind('click', function (what) {
                return function (event) {
                    securityIssues_unfix(what);
                    return false;
                }
            }(securityIssues_fixes[i]));
        }
        securityIssues_request(securityIssueSite.val());
    }
});
securityIssues_fix = function (feature) {
    if (feature == 'all') {
        for (var i = 0; i < securityIssues_fixes.length; i++) {
            if (jQuery('#' + securityIssues_fixes[i] + '_nok').css('display') != 'none') {
                if (jQuery('#' + securityIssues_fixes[i] + '_fix')) {
                    jQuery('#' + securityIssues_fixes[i] + '_fix').hide();
                }
                jQuery('#' + securityIssues_fixes[i] + '_extra').hide();
                jQuery('#' + securityIssues_fixes[i] + '_ok').hide();
                jQuery('#' + securityIssues_fixes[i] + '_nok').hide();
                jQuery('#' + securityIssues_fixes[i] + '_loading').show();
            }
        }
    }
    else {
        if (jQuery('#' + feature + '_fix')) {
            jQuery('#' + feature + '_fix').hide();
        }
        jQuery('#' + feature + '_extra').hide();
        jQuery('#' + feature + '_ok').hide();
        jQuery('#' + feature + '_nok').hide();
        jQuery('#' + feature + '_loading').show();
    }
    var data = mainwp_secure_data({
        action:'mainwp_securityIssues_fix',
        feature:feature,
        id:jQuery('#securityIssueSite').val()
    });
    jQuery.post(ajaxurl, data, function (response) {
        securityIssues_handle(response);
    }, 'json');
};
var completedSecurityIssues = undefined;
jQuery(document).on('click', '.securityIssues_dashboard_allFixAll', function() {
    jQuery(this).hide();
    jQuery('#wp_securityissues').show();

    var sites = jQuery('#wp_securityissues').find('.mainwp-sub-row');
    completedSecurityIssues = 0;
    for (var i = 0; i < sites.length; i++)
    {
        var site = jQuery(sites[i]);
        //if (site.find('.securityIssues_dashboard_fixAll').val() != 'Fix All') continue;
        completedSecurityIssues++;
        mainwp_securityIssues_fixAll(site.attr('siteid'), false);
    }
});
jQuery(document).on('click', '.securityIssues_dashboard_fixAll', function() {    
    mainwp_securityIssues_fixAll(jQuery(this).closest('.mainwp-sub-row').attr('siteid'), true);
});
mainwp_securityIssues_fixAll = function(siteId, refresh)
{
    var data = mainwp_secure_data({
        action:'mainwp_securityIssues_fix',
        feature:'all',
        id:siteId
    });

    var el = jQuery('#wp_securityissues .mainwp-sub-row[siteid="'+siteId+'"] .securityIssues_dashboard_fixAll');
    el.hide();
    el.next('.img-loader').show();
    jQuery('.securityIssues_dashboard_fixAll').attr('disabled', 'true');
    jQuery('.securityIssues_dashboard_unfixAll').attr('disabled', 'true');
    jQuery.post(ajaxurl, data, function(pRefresh, pElement) { return function (response) {
        el.next('.img-loader').hide();
        el.show();
        if (pRefresh || (completedSecurityIssues != undefined && --completedSecurityIssues <= 0))
        {
            location.href = location.href;
        }
    } }(refresh, el), 'json');
};
jQuery(document).on('click', '.securityIssues_dashboard_unfixAll', function() {
    var data = mainwp_secure_data({
        action:'mainwp_securityIssues_unfix',
        feature:'all',
        id:jQuery(jQuery(this).parents('div')[0]).attr('siteid')
    });

    jQuery(this).hide();
    jQuery(this).next('.img-loader').show();
    jQuery('.securityIssues_dashboard_fixAll').attr('disabled', 'true');
    jQuery('.securityIssues_dashboard_unfixAll').attr('disabled', 'true');

    jQuery.post(ajaxurl, data, function (response) {
        location.href = location.href;
    }, 'json');
});
securityIssues_unfix = function (feature) {
    if (jQuery('#' + feature + '_unfix')) {
        jQuery('#' + feature + '_unfix').hide();
    }
    jQuery('#' + feature + '_extra').hide();
    jQuery('#' + feature + '_ok').hide();
    jQuery('#' + feature + '_nok').hide();
    jQuery('#' + feature + '_loading').show();

    var data = mainwp_secure_data({
        action:'mainwp_securityIssues_unfix',
        feature:feature,
        id:jQuery('#securityIssueSite').val()
    });
    jQuery.post(ajaxurl, data, function (response) {
        securityIssues_handle(response);
    }, 'json');
};
securityIssues_request = function (websiteId) {
    var data = mainwp_secure_data({
        action:'mainwp_securityIssues_request',
        id:websiteId
    });
    jQuery.post(ajaxurl, data, function (response) {
        securityIssues_handle(response);
    }, 'json');
};
securityIssues_handle = function (response) {
    var result = '';
    if (response.error)
    {
        result = getErrorMessage(response.error);
    }
    else
    {
        try {
            var res = response.result;
            for (var issue in res) {
                if (jQuery('#' + issue + '_loading')) {
                    jQuery('#' + issue + '_loading').hide();
                    if (res[issue] == 'Y') {
                        jQuery('#' + issue + '_extra').hide();
                        jQuery('#' + issue + '_nok').hide();
                        if (jQuery('#' + issue + '_fix')) {
                            jQuery('#' + issue + '_fix').hide();
                        }
                        if (jQuery('#' + issue + '_unfix')) {
                            jQuery('#' + issue + '_unfix').show();
                        }
                        jQuery('#' + issue + '_ok').show();
                        jQuery('#' + issue + '-status-ok').show();
                        jQuery('#' + issue + '-status-nok').hide();
                    }
                    else {
                        jQuery('#' + issue + '_extra').hide();
                        jQuery('#' + issue + '_ok').hide();
                        jQuery('#' + issue + '_nok').show();
                        if (jQuery('#' + issue + '_fix')) {
                            jQuery('#' + issue + '_fix').show();
                        }
                        if (jQuery('#' + issue + '_unfix')) {
                            jQuery('#' + issue + '_unfix').hide();
                        }

                        if (res[issue] != 'N') {
                            jQuery('#' + issue + '_extra').html(res[issue]);
                            jQuery('#' + issue + '_extra').show();
                        }
                    }
                }
            }
        }
        catch (err) {
            result = '<i class="fa fa-exclamation-circle"></i> '+__('Undefined error!');
        }
    }
    if (result != '') {
        //show error!
    }
};

jQuery(document).ready(function () {
    jQuery('#dashboard_refresh').live('click', function (event) {
        mainwp_refresh_dashboard();
    });
    jQuery('#refresh-status-close').live('click', function(event)
    {
        bulkTaskRunning = false;
        jQuery('#refresh-status-box').dialog('destroy');

        location.href = location.href;
    });
    jQuery(document).on('click', '#rightnow-upgrade-status-close', function(event)
    {
        bulkTaskRunning = false;
        jQuery('#rightnow-upgrade-status-box').dialog('destroy');

        location.href = location.href;
    });
});
mainwp_refresh_dashboard = function (syncSiteIds)
{
    var allWebsiteIds = jQuery('.dashboard_wp_id').map(function(indx, el){ return jQuery(el).val(); });
    var globalSync = true;
    var selectedIds = [], excludeIds = [];
    if (syncSiteIds instanceof Array) {
        jQuery.grep(allWebsiteIds, function(el) {
            if (jQuery.inArray(el, syncSiteIds) !== -1) {
                selectedIds.push(el);
            } else {
                excludeIds.push(el);
            }
        });
        for (var i = 0; i < excludeIds.length; i++)
        {
            dashboard_update_site_hide(excludeIds[i]);
        }
        allWebsiteIds = selectedIds;
        jQuery('#refresh-status-total').text(allWebsiteIds.length);
        globalSync = false;
    }


    for (var i = 0; i < allWebsiteIds.length; i++)
    {
        dashboard_update_site_status(allWebsiteIds[i], '<i class="fa fa-clock-o" aria-hidden="true"></i> ' + __('PENDING'));
    }
    var nrOfWebsites = allWebsiteIds.length;
    jQuery('#refresh-status-progress').progressbar({value: 0, max: nrOfWebsites});
    jQuery('#refresh-status-box').dialog({
        resizable: false,
        height: 350,
        width: 500,
        modal: true,
        close: function(event, ui) {bulkTaskRunning = false; jQuery('#refresh-status-box').dialog('destroy'); location.href = location.href;}});
    dashboard_update(allWebsiteIds);
    if (globalSync && nrOfWebsites > 0) {
         var data = {
            action:'mainwp_status_saving',
            status: 'last_sync_sites'
        };
        jQuery.post(ajaxurl, mainwp_secure_data(data), function (res) {
        });
    }
};

var websitesToUpdate = [];
var websitesTotal = 0;
var websitesLeft = 0;
var websitesDone = 0;
var websitesError = 0;
var currentWebsite = 0;
var bulkTaskRunning = false;
var currentThreads = 0;
var maxThreads = mainwpParams['maximumSyncRequests'] == undefined ? 8 : mainwpParams['maximumSyncRequests'];

dashboard_update = function(websiteIds)
{
    websitesToUpdate = websiteIds;
    currentWebsite = 0;
    websitesDone = 0;
    websitesError = 0;
    websitesTotal = websitesLeft = websitesToUpdate.length;

    bulkTaskRunning = true;

    if (websitesTotal == 0)
    {
        dashboard_update_done();
    }
    else
    {
        dashboard_loop_next();
    }
};

dashboard_update_site_status = function(siteId, newStatus, isSuccess)
{
    jQuery('.refresh-status-wp[siteid="'+siteId+'"]').html(newStatus);
    if (typeof isSuccess !== 'undefined' && isSuccess) {
        var row = jQuery('.refresh-status-wp[siteid="'+siteId+'"]').closest('tr');
        jQuery(row).insertAfter(jQuery("#refresh-status-content table tr").not('.mainwp_wp_offline').last());
    }
};
dashboard_update_site_hide = function(siteId)
{
    jQuery('.refresh-status-wp[siteid="'+siteId+'"]').closest('tr').hide();
};

dashboard_loop_next = function()
{
    while(bulkTaskRunning && (currentThreads < maxThreads) && (websitesLeft > 0))
    {
        dashboard_update_next();
    }
};
dashboard_update_done = function()
{
    currentThreads--;
    if (!bulkTaskRunning) return;
    websitesDone++;
    if (websitesDone > websitesTotal) websitesDone = websitesTotal;

    jQuery('#refresh-status-progress').progressbar('value', websitesDone);
    jQuery('#refresh-status-current').html(websitesDone);

    if (websitesDone == websitesTotal)
    {
        setTimeout(function() {
            bulkTaskRunning = false;
            if (websitesError <= 0)
            {
                jQuery('#refresh-status-box').dialog('destroy');
                location.href = location.href;
            }
            else
            {
                var message = websitesError + ' Site' + (websitesError > 1 ? 's' : '') + ' Timed / Errored Out. <br/><span class="mainwp-small">(There was an error syncing some of your sites. <a href="http://mainwp.com/help/docs/potential-issues/">Please check this help document for possible solutions.</a>)</span>';
                jQuery('#refresh-status-content').prepend('<span class="mainwp-red"><strong>' + message + '</strong></span><br /><br />');
                jQuery('#mainwp-right-now-message-content').html(message);
                jQuery('#mainwp-right-now-message').show();
            }
        }, 2000);
        return;
    }

    dashboard_loop_next();
};
dashboard_update_next = function()
{
    currentThreads++;
    websitesLeft--;
    var websiteId = websitesToUpdate[currentWebsite++];
    dashboard_update_site_status(websiteId,'<i class="fa fa-refresh fa-spin"></i> ' + __('SYNCING'));
    var data = mainwp_secure_data({
        action:'mainwp_syncsites',
        wp_id: websiteId
    });
    dashboard_update_next_int(websiteId, data, 0);
};
dashboard_update_next_int = function(websiteId, data, errors)
{
    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function(pWebsiteId) { return function(response) { if (response.error) { dashboard_update_site_status(pWebsiteId, '<span class="mainwp-red"><i class="fa fa-exclamation" aria-hidden="true"></i> ' + __('ERROR') + '</span>'); websitesError++; } else {dashboard_update_site_status(websiteId, '<span class="mainwp-green"><i class="fa fa-check" aria-hidden="true"></i> ' + __('DONE') + '</span>', true);} dashboard_update_done(); } }(websiteId),
        error: function(pWebsiteId, pData, pErrors) { return function(response) {
            if (pErrors > 5)
            {
                dashboard_update_site_status(pWebsiteId, '<span class="mainwp-red"><i class="fa fa-exclamation" aria-hidden="true"></i> ' +  __('TIMEOUT') + '</span>');  websitesError++; dashboard_update_done();
            }
            else
            {
                pErrors++;
                dashboard_update_next_int(pWebsiteId, pData, pErrors);
            }
        } }(websiteId, data, errors),
        dataType: 'json'
    });
};


//Ignore plugin
jQuery(document).ready(function() {
    jQuery(document).on('click', 'input[name="plugins"]', function(event)
    {
        if (jQuery(this).is(':checked'))
        {
            jQuery('input[name="plugins"]').attr('checked','checked');
            jQuery('input[name="plugin[]"]').attr('checked','checked');
        }
        else
        {
            jQuery('input[name="plugins"]').removeAttr('checked');
            jQuery('input[name="plugin[]"]').removeAttr('checked');
        }
    });
    jQuery(document).on('click', 'input[name="themes"]', function(event)
    {
        if (jQuery(this).is(':checked'))
        {
            jQuery('input[name="themes"]').attr('checked','checked');
            jQuery('input[name="theme[]"]').attr('checked','checked');
        }
        else
        {
            jQuery('input[name="themes"]').removeAttr('checked');
            jQuery('input[name="theme[]"]').removeAttr('checked');
        }
    });
    jQuery(document).on('click', '#mainwp_bulk_trust_plugins_action_apply', function(event) {
        var action = jQuery('#mainwp_bulk_action').val();
        if (action == 'none') return false;

        var slugs = jQuery.map(jQuery("input[name='plugin[]']:checked"), function(el) { return jQuery(el).val(); });
        if (slugs.length == 0) return false;

        jQuery('#mainwp_bulk_trust_plugins_action_apply').attr('disabled', 'true');

        var data = mainwp_secure_data({
            action:'mainwp_trust_plugin',
            slugs: slugs,
            do: action
        });

        jQuery.post(ajaxurl, data, function (resp) {
            jQuery('#mainwp_bulk_trust_plugins_action_apply').removeAttr('disabled');
            mainwp_fetch_all_active_plugins();
        }, 'json');

        return false;
    });
    jQuery(document).on('click', '#mainwp_bulk_trust_themes_action_apply', function(event) {
        var action = jQuery('#mainwp_bulk_action').val();
        if (action == 'none') return false;

        var slugs = jQuery.map(jQuery("input[name='theme[]']:checked"), function(el) { return jQuery(el).val(); });
        if (slugs.length == 0) return false;

        jQuery('#mainwp_bulk_trust_themes_action_apply').attr('disabled', 'true');

        var data = mainwp_secure_data({
            action:'mainwp_trust_theme',
            slugs: slugs,
            do: action
        });

        jQuery.post(ajaxurl, data, function (resp) {
            jQuery('#mainwp_bulk_trust_themes_action_apply').removeAttr('disabled');
            mainwp_fetch_all_themes();
        }, 'json');

        return false;
    });
});
mainwp_active_plugins_table_reinit = function () {
    if (jQuery('#mainwp_active_plugins_table').hasClass('tablesorter-default'))
    {
        jQuery('#mainwp_active_plugins_table').trigger("updateAll").trigger('destroy.pager').tablesorterPager({container:jQuery("#pager")});
    }
    else
    {
        jQuery('#mainwp_active_plugins_table').tablesorter({
            cssAsc:"desc",
            cssDesc:"asc",
            sortInitialOrder: 'desc',
            textExtraction:function (node) {
                if (jQuery(node).find('abbr').length == 0) {
                    return node.innerHTML
                } else {
                    return jQuery(node).find('abbr')[0].title;
                }
            },
            selectorHeaders: "> thead th:not(:first), > thead td:not(:first)"
        }).tablesorterPager({container:jQuery("#pager")});
    }
};
mainwp_themes_all_table_reinit = function () {
    if (jQuery('#mainwp_themes_all_table').hasClass('tablesorter-default'))
    {
        jQuery('#mainwp_themes_all_table').trigger("updateAll").trigger('destroy.pager').tablesorterPager({container:jQuery("#pager")});
    }
    else
    {
        jQuery('#mainwp_themes_all_table').tablesorter({
            cssAsc:"desc",
            cssDesc:"asc",
            sortInitialOrder: 'desc',
            textExtraction:function (node) {
                if (jQuery(node).find('abbr').length == 0) {
                    return node.innerHTML
                } else {
                    return jQuery(node).find('abbr')[0].title;
                }
            },
            selectorHeaders: "> thead th:not(:first), > thead td:not(:first)"
        }).tablesorterPager({container:jQuery("#pager")});
    }
};

rightnow_ignore_plugintheme_by_site = function (what, slug, name, id, pGroupId) {
    var data = mainwp_secure_data({
        action:'mainwp_ignoreplugintheme',
        type:what,
        id:id,
        slug:slug,
        name:name
    });
    var strGroup = '';
    if (typeof pGroupId !== 'undefined') {
        strGroup = '_group_' + pGroupId;
    }

    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            jQuery(document.getElementById('wp_upgrade_' + what + '_' + id + strGroup + '_' + slug)).html(__('Ignored'));
            jQuery(document.getElementById('wp_upgrade_' + what + '_' + id + strGroup + '_' + slug)).siblings('.mainwp-right-col').html('');
            jQuery('div['+what+'_slug="'+slug+'"] div[site_id="'+id+'"]').find('.pluginsInfo').html(__('Ignored'));
            jQuery('div['+what+'_slug="'+slug+'"] div[site_id="'+id+'"]').find('.pluginsAction').html('');
            jQuery('div['+what+'_slug="'+slug+'"] div[site_id="'+id+'"]').attr('updated', '-1');
        }
        else
        {
            jQuery(document.getElementById('wp_upgrade_' + what + '_' + id + strGroup + '_' + slug)).html(getErrorMessage(response.error));
        }
    }, 'json');
    return false;
};
rightnow_unignore_plugintheme_by_site = function (what, slug, id) {
    var data = mainwp_secure_data({
        action:'mainwp_unignoreplugintheme',
        type:what,
        id:id,
        slug:slug
    });
    jQuery.post(ajaxurl, data, function (pWhat, pSlug, pId) { return function (response) {
        if (response.result) {
            var siteElement;
            if (pWhat == 'plugin')
            {
                siteElement = jQuery('tr[site_id="'+pId+'"][plugin_slug="'+pSlug+'"]');
            }
            else
            {
                siteElement = jQuery('tr[site_id="'+pId+'"][theme_slug="'+pSlug+'"]');
            }

            if (!siteElement.find('.websitename').is(':visible'))
            {
                siteElement.remove();
                return;
            }

            //Check if previous tr is same site..
            //Check if next tr is same site..
            var siteAfter = siteElement.next();
            if (siteAfter.exists() && (siteAfter.attr('site_id') == pId))
            {
                siteAfter.find('.websitename').show();
                siteElement.remove();
                return;
            }

            var parent = siteElement.parent();
            siteElement.remove();
            if (parent.children('tr').size() == 0) {
                parent.append('<tr><td colspan="3">'+__('No ignored %1s', pWhat)+'</td></tr>');
                jQuery('.mainwp-unignore-detail-all').hide();
            }
        }
    } }(what, slug, id), 'json');
    return false;
};
rightnow_unignore_plugintheme_by_site_all = function (what) {
    var data = mainwp_secure_data({
        action:'mainwp_unignoreplugintheme',
        type:what,
        id:'_ALL_',
        slug:'_ALL_'
    });
    jQuery.post(ajaxurl, data, function (pWhat) { return function (response) {
        if (response.result) {
            var tableElement = jQuery('#ignored-'+pWhat+'s-list');
            tableElement.find('tr').remove();
            tableElement.append('<tr><td colspan="3">'+__('No ignored %1s', pWhat)+'</td></tr>');
            jQuery('.mainwp-unignore-detail-all').hide();
        }
    } }(what), 'json');
    return false;
};

/**Plugins part**/
rightnow_translations_detail = function (slug) {
    jQuery('div[translation_slug="'+slug+'"]').toggle(100, 'linear');
    return false;
};
rightnow_translations_detail_show = function (slug) {
    jQuery('div[translation_slug="'+slug+'"]').show(100, 'linear');
    return false;
};
rightnow_plugins_detail = function (slug) {
    jQuery('div[plugin_slug="'+slug+'"]').toggle(100, 'linear');
    return false;
};
rightnow_plugins_detail_show = function (slug) {
    jQuery('div[plugin_slug="'+slug+'"]').show(100, 'linear');
    return false;
};
rightnow_themes_detail = function (slug) {
    jQuery('div[theme_slug="'+slug+'"]').toggle(100, 'linear');
    return false;
};
rightnow_themes_detail_show = function (slug) {
    jQuery('div[theme_slug="'+slug+'"]').show(100, 'linear');
    return false;
};
rightnow_plugins_ignore_detail = function (slug, name, id, groupId) {
    if (!confirm(__('Are you sure you want to ignore this plugin updates? The updates will no longer be visible in your MainWP Dashboard.')))
        return false;
    return rightnow_ignore_plugintheme_by_site('plugin', slug, name, id, groupId);
};
rightnow_plugins_unignore_detail = function (slug, id) {
    return rightnow_unignore_plugintheme_by_site('plugin', slug, id);
};
rightnow_plugins_unignore_detail_all = function () {
    return rightnow_unignore_plugintheme_by_site_all('plugin');
};
rightnow_themes_ignore_detail = function (slug, name, id, groupId) {
    if (!confirm(__('Are you sure you want to ignore this theme updates? The updates will no longer be visible in your MainWP Dashboard.')))
        return false;
    return rightnow_ignore_plugintheme_by_site('theme', slug, name, id, groupId);
};
rightnow_themes_unignore_detail = function (slug, id) {
    return rightnow_unignore_plugintheme_by_site('theme', slug, id);
};
rightnow_themes_unignore_detail_all = function () {
    return rightnow_unignore_plugintheme_by_site_all('theme');
};
rightnow_plugins_ignore_all = function (slug, name) {
    if (!confirm(__('Are you sure you want to ignore this plugin updates? The updates will no longer be visible in your MainWP Dashboard.')))
        return false;
    rightnow_plugins_detail_show(slug);
    var data = mainwp_secure_data({
        action:'mainwp_ignorepluginsthemes',
        type: 'plugin',
        slug:slug,
        name:name
    });
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            jQuery('div[plugin_slug="'+slug+'"]').find('.pluginsInfo').html(__('Ignored'));
            jQuery('div[plugin_slug="'+slug+'"]').find('.pluginsAction').hide();
            jQuery('div[plugin_slug="'+slug+'"]').find('div[updated="0"]').attr('updated', '-1');
        }
    }, 'json');
    return false;
};
rightnow_plugins_unignore_globally_all = function() {
    var data = mainwp_secure_data({
        action:'mainwp_unignorepluginsthemes',
        type: 'plugin',
        slug: '_ALL_'
    });
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            var tableElement = jQuery('#globally-ignored-plugins-list');
            tableElement.find('tr').remove();
            jQuery('.mainwp-unignore-globally-all').hide();
            tableElement.append('<tr><td colspan="2">'+__('No ignored plugins')+'</td></tr>');
        }
    }, 'json');
    return false;
};
rightnow_plugins_unignore_globally = function (slug) {
    var data = mainwp_secure_data({
        action:'mainwp_unignorepluginsthemes',
        type: 'plugin',
        slug:slug
    });
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            var ignoreElement = jQuery('#globally-ignored-plugins-list tr[plugin_slug="'+slug+'"]');
            var parent = ignoreElement.parent();
            ignoreElement.remove();
            if (parent.children('tr').size() == 0) {
                jQuery('.mainwp-unignore-globally-all').hide();
                parent.append('<tr><td colspan="2">'+__('No ignored plugins')+'</td></tr>');
            }
        }
    }, 'json');
    return false;
};
rightnow_themes_ignore_all = function (slug, name) {
    if (!confirm(__('Are you sure you want to ignore this theme updates? The updates will no longer be visible in your MainWP Dashboard.')))
        return false;
    rightnow_themes_detail_show(slug);
    var data = mainwp_secure_data({
        action:'mainwp_ignorepluginsthemes',
        type: 'theme',
        slug:slug,
        name:name
    });
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            jQuery('div[theme_slug="'+slug+'"]').find('.pluginsInfo').html(__('Ignored'));
            jQuery('div[theme_slug="'+slug+'"]').find('.pluginsAction').hide();
            jQuery('div[theme_slug="'+slug+'"]').find('div[updated="0"]').attr('updated', '-1');
        }
    }, 'json');
    return false;
};
rightnow_themes_unignore_globally_all = function (slug) {
    var data = mainwp_secure_data({
        action:'mainwp_unignorepluginsthemes',
        type: 'theme',
        slug:'_ALL_'
    });
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            var tableElement = jQuery('#globally-ignored-themes-list');
            tableElement.find('tr').remove();
            jQuery('.mainwp-unignore-globally-all').hide();
            tableElement.append('<tr><td colspan="2">'+__('No ignored themes')+'</td></tr>');
        }
    }, 'json');
    return false;
};
rightnow_themes_unignore_globally = function (slug) {
    var data = mainwp_secure_data({
        action:'mainwp_unignorepluginsthemes',
        type: 'theme',
        slug:slug
    });
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            var ignoreElement = jQuery('#globally-ignored-themes-list tr[theme_slug="'+slug+'"]');
            var parent = ignoreElement.parent();
            ignoreElement.remove();
            if (parent.children('tr').size() == 0)
            {
                jQuery('.mainwp-unignore-globally-all').hide();
                parent.append('<tr><td colspan="2">'+__('No ignored themes')+'</td></tr>');
            }
        }
    }, 'json');
    return false;
};
rightnow_translations_upgrade = function (slug, websiteid) {
    return rightnow_translations_upgrade_int(slug, websiteid);
};
rightnow_plugins_upgrade = function (slug, websiteid) {
    return rightnow_plugins_upgrade_int(slug, websiteid);
};

rightnow_themes_upgrade = function (slug, websiteid) {
    return rightnow_themes_upgrade_int(slug, websiteid);
};

/** /END NEW **/

rightnow_wp_sync = function (websiteid) {
    var syncIds = [];
    syncIds.push(websiteid);
    mainwp_refresh_dashboard(syncIds);
    return false;
};

rightnow_upgrade_translation = function (id, slug) {
    return rightnow_upgrade_plugintheme('translation', id, slug);
};

rightnow_group_upgrade_translation = function (id, slug, groupId) {
    return rightnow_upgrade_plugintheme('translation', id, slug, groupId);
};

rightnow_upgrade_translation_all = function (id) {
    if (!confirm(__('Are you sure you want to update all translations?')))
        return false;
    rightnow_show_if_required('translation_upgrades', false);
    return rightnow_upgrade_plugintheme_all('translation', id);
};

rightnow_group_upgrade_translation_all = function (id, groupId) {
    if (!confirm(__('Are you sure you want to update all translations?')))
        return false;
    rightnow_group_show_if_required('translation_upgrades', id, groupId);
    return rightnow_group_upgrade_plugintheme_all('translation', id, false, groupId);
};

rightnow_upgrade_plugin = function (id, slug) {
    return rightnow_upgrade_plugintheme('plugin', id, slug);
};

rightnow_group_upgrade_plugin = function (id, slug, groupId) {
    return rightnow_upgrade_plugintheme('plugin', id, slug, groupId);
};

rightnow_upgrade_plugin_all = function (id) {
    if (!confirm(__('Are you sure you want to update all plugins?')))
        return false;
    rightnow_show_if_required('plugin_upgrades', false);
    return rightnow_upgrade_plugintheme_all('plugin', id);
};

rightnow_group_upgrade_plugin_all = function (id, groupId) {
    if (!confirm(__('Are you sure you want to update all plugins?')))
        return false;
    rightnow_group_show_if_required('plugin_upgrades', id, groupId);
    return rightnow_group_upgrade_plugintheme_all('plugin', id, false, groupId);
};

rightnow_upgrade_theme = function (id, slug) {
    return rightnow_upgrade_plugintheme('theme', id, slug);
};
rightnow_group_upgrade_theme = function (id, slug, groupId) {
    return rightnow_upgrade_plugintheme('theme', id, slug, groupId);
};

rightnow_upgrade_theme_all = function (id) {
    if (!confirm(__('Are you sure you want to update all themes?')))
        return false;
    rightnow_show_if_required('theme_upgrades', false);
    return rightnow_upgrade_plugintheme_all('theme', id);
};
rightnow_group_upgrade_theme_all = function (id, groupId) {
    if (!confirm(__('Are you sure you want to update all themes?')))
        return false;
    rightnow_group_show_if_required('theme_upgrades', id, groupId);
    return rightnow_group_upgrade_plugintheme_all('theme', id, false, groupId);
};

rightnow_upgrade_plugintheme = function (what, id, name, groupId) {
    rightnow_upgrade_plugintheme_list(what, id, [name], false, groupId);
    return false;
};
rightnow_upgrade_plugintheme_all = function (what, id, noCheck) {

    rightnowContinueAfterBackup = function(pId, pWhat) { return function()
    {
        var list = [];
        jQuery("#wp_" + pWhat + "_upgrades_" + pId + " [id^=wp_upgrade_" + pWhat + "_" + pId + "]").each(function (index, value) {
            var re = new RegExp('^wp_upgrade_' + pWhat + '_' + pId + '_(.*)$');
            if (divId = re.exec(value.id)) {
                if (document.getElementById('wp_upgraded_' + pWhat + '_' + pId + '_' + divId[1]).value == 0) {
                    //value.parent().attr('premium')
                    list.push(divId[1]);
                }
            }
        });

        rightnow_upgrade_plugintheme_list(what, pId, list, true);
    } }(id, what);

    if (noCheck)
    {
        rightnowContinueAfterBackup();
        return false;
    }

    var sitesToUpdate = [];
    var siteNames = [];

    rightnow_show_if_required(what+'_upgrades_'+id, true);
    sitesToUpdate.push(id);
    siteNames[id] = jQuery('div[site_id="' + id + '"]').attr('site_name');

    return mainwp_rightnow_checkBackups(sitesToUpdate, siteNames);
};

rightnow_group_upgrade_plugintheme_all = function (what, id, noCheck, groupId) {

    rightnowContinueAfterBackup = function(pId, pWhat) { return function()
    {
        //rightnow_show_if_required(pWhat+'_upgrades_'+pId, true);                        
        var list = [];
        jQuery("#wp_" + pWhat + "_upgrades_" + pId + '_group_' + groupId + " [id^=wp_upgrade_" + pWhat + "_" + pId + "_group_" + groupId + "]").each(function (index, value) {
            var re = new RegExp('^wp_upgrade_' + pWhat + '_' + pId + '_group_' + groupId + '_(.*)$');
            if (divId = re.exec(value.id)) {
                if (document.getElementById('wp_upgraded_' + pWhat + '_' + pId + '_group_' + groupId + '_' + divId[1]).value == 0) {
                    //value.parent().attr('premium')
                    list.push(divId[1]);
                }
            }
        });

        rightnow_upgrade_plugintheme_list(what, pId, list, true, groupId);
    } }(id, what);

    if (noCheck)
    {
        rightnowContinueAfterBackup();
        return false;
    }

    var sitesToUpdate = [];
    var siteNames = [];

    rightnow_show_if_required(what+'_upgrades_'+id, true);
    sitesToUpdate.push(id);
    siteNames[id] = jQuery('div[site_id="' + id + '"]').attr('site_name');

    return mainwp_rightnow_checkBackups(sitesToUpdate, siteNames);
};

rightnow_upgrade_plugintheme_list = function (what, id, list, noCheck, groupId)
{
    rightnowContinueAfterBackup = function(pWhat, pId, pList, pGroupId) { return function()
    {
        var strGroup = '';
        if (typeof pGroupId !== 'undefined') {
            strGroup = '_group_' + pGroupId;
        }
        var newList = [];
        for (var i = pList.length - 1; i >= 0; i--) {
            var item = pList[i];
            if (document.getElementById('wp_upgraded_' + pWhat + '_' + pId + strGroup + '_' + item).value == 0) {
                document.getElementById('wp_upgrade_' + pWhat + '_' + pId + strGroup + '_' + item).innerHTML = __('Updating...');
                document.getElementById('wp_upgraded_' + pWhat + '_' + pId + strGroup + '_' + item).value = 1;
                document.getElementById('wp_upgradebuttons_' + pWhat + '_' + pId + strGroup + '_' + item).style.display = 'none';
                newList.push(item);
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

        if (newList.length <= 0) {
        } else {
            var data = mainwp_secure_data({
                action:'mainwp_upgradeplugintheme',
                websiteId:pId,
                type:pWhat,
                slug:newList.join(',')
            });
            jQuery.post(ajaxurl, data, function (response) {
                var result, success = false;
                if (response.error) {
                    result = getErrorMessage(response.error)
                } else
                {
                    var res = response.result;
                    for (var i = 0; i < newList.length; i++) {
                        var item = newList[i];
                        couttItemsToUpdate++;
                        if (res[item]) {
                            var msg = __('Update successful!');
                            if (response.site_url)
                                msg = msg + '<br/>' +  '<a href="' + response.site_url + '" target="_blank">View Site</a> | <a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=' + pId + '" target="_blank">WP Admin</a>';
                            document.getElementById('wp_upgrade_' + pWhat + '_' + pId + strGroup + '_' + item ).innerHTML = '<span style="color: #0073aa;"><i class="fa fa-check-circle"></i> '+ msg + '</span>';
                            countRealItemsUpdated++;
                        }
                        else {
                            document.getElementById('wp_upgrade_' + pWhat + '_' + pId + strGroup + '_' + item ).innerHTML = '<span style="color: #a00;"><i class="fa fa-exclamation-circle"></i> ' + __('Update failed!') + '</span>';
                        }
                    }

                    if (mainwpParams.enabledTwit == true) {
                        var dateObj = new Date();
                        var countSec = (dateObj.getTime() - starttimeDashboardAction) / 1000;
                        jQuery('#bulk_install_info').html('<i class="fa fa-spinner fa-pulse"></i>');
                        if (countSec <= mainwpParams.maxSecondsTwit) {
                            var data = {
                                action: 'mainwp_twitter_dashboard_action',
                                actionName: dashboardActionName,
                                countSites: 1,
                                countSeconds: countSec,
                                countItems: couttItemsToUpdate,
                                countRealItems: countRealItemsUpdated,
                                showNotice: 1
                            };
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

                    success = true;
                }
                if (!success) {
                    for (var i = 0; i < newList.length; i++) {
                        var item = newList[i];
                        document.getElementById('wp_upgrade_' + pWhat + '_' + pId + strGroup + '_' + item).innerHTML = result;
                    }
                }
            }, 'json');

        }

        rightnowContinueAfterBackup = undefined;
    } }(what, id, list, groupId);

    if (noCheck)
    {
        rightnowContinueAfterBackup();
        return false;
    }

    var sitesToUpdate = [id];
    var siteNames = [];
    siteNames[id] = jQuery('div[site_id="' + id + '"]').attr('site_name');

    return mainwp_rightnow_checkBackups(sitesToUpdate, siteNames);
};

rightnow_group_show = function (what, groupId) {
    jQuery('.wp_' + what + '_' + groupId).toggle(100, 'linear', function () {
    })
    return false;
};

rightnow_show = function (what, leave_text) {
    jQuery('#wp_' + what).toggle(100, 'linear', function () {
        if (!leave_text) {
            if (jQuery('#wp_' + what).css('display') == 'none') {
                jQuery('#mainwp_' + what + '_show').html((what == 'securityissues' ? '<i class="fa fa-eye-slash"></i> ' + __('Show all') : '<i class="fa fa-eye-slash"></i> ' + __('Show')));
            }
            else {
                jQuery('#mainwp_' + what + '_show').html((what == 'securityissues' ? '<i class="fa fa-eye-slash"></i> ' + __('Hide all') : '<i class="fa fa-eye-slash"></i> ' + __('Hide')));
            }
        }
    });
    return false;
};

rightnow_show_if_required = function (what, leave_text, groupId) {
    if (typeof groupId !== 'undefined') {
        var parent = jQuery('.wp_' + what + '_' + groupId);
        parent.show(100);
        parent.find('.show-child-row').show();
        return false;
    }
    jQuery('#wp_' + what).show(100, function() {
        if (!leave_text) {
            jQuery('#mainwp_' + what + '_show').html('<i class="fa fa-eye-slash"></i> ' + __('Hide'));
        }
    });
    return false;
};

rightnow_group_show_if_required = function (what, id, groupId) {
    jQuery('#wp_' + what + '_' + id + '_group_' + groupId).show(100);
};

rightnow_updates_group_set_status = function(what, groupId, total, show_btn) {
    jQuery('#total_' + what + '_group_' + groupId).html(total + ' ' + (total == 1 ? __( 'Update' ) : __( 'Updates' )) );
    if (show_btn)
        jQuery('#' + what + '_all_btn_group_' + groupId).text(total == 1 ? __( 'Update' ) : __( 'Update All' )).show();
};

rightnow_updates_group_remove_empty_rows = function(what, groupId) {
    jQuery('#top_row_' + what + '_group_' + groupId).remove();
    jQuery('.wp_' + what + '_group_' + groupId).remove();
};

/**
 * Manage backups page
 */
jQuery(document).ready(function () {
    jQuery('.backup_destination_exclude').live('click', function(event)
    {
        jQuery(this).parent().parent().animate({height:0}, {duration: 'slow', complete: function() { jQuery(this).remove();}});
    });
    jQuery('#mainwp_managebackups_add').live('click', function (event) {
        mainwp_managebackups_add(event);
    });
    jQuery('#mainwp_managebackups_update').live('click', function (event) {
        mainwp_managebackups_update(event);
    });
    jQuery('.backup_run_now').live('click', function(event)
    {
        managebackups_run_now(jQuery(this));
        return false;
    });
    jQuery('#managebackups-task-status-close').live('click', function(event)
    {
        backupDownloadRunning = false;
        jQuery('#managebackups-task-status-box').dialog('destroy');
        location.reload();
    });
    managebackups_init();

    var elem = jQuery('#backup_exclude_folders');
    if (elem.length) {
        var siteId = jQuery('#backup_exclude_folders').attr('siteid');
        var sites = jQuery('#backup_exclude_folders').attr('sites');
        var groups = jQuery('#backup_exclude_folders').attr('groups');
        if (jQuery('#backup_task_id').val() == undefined) jQuery('#backup_exclude_folders').fileTree({ root: '', script: ajaxurl + '?action=mainwp_site_dirs&site='+encodeURIComponent(siteId == undefined ? '' : siteId)+'&sites='+encodeURIComponent(sites == undefined ? '' : sites)+'&groups='+encodeURIComponent(groups == undefined ? '' : groups), multiFolder: false, postFunction: updateExcludedFolders});
        jQuery('.jqueryFileTree li a').live('mouseover', function() { jQuery(this).children('.exclude_folder_control').show() });
        jQuery('.jqueryFileTree li a').live('mouseout', function() { jQuery(this).children('.exclude_folder_control').hide() });
    }
});
managebackups_exclude_folder = function(pElement, pEvent)
{
    var folder = pElement.parent().attr('rel') + "\n";
    if (jQuery('#excluded_folders_list').val().indexOf(folder) !== -1) return;

    jQuery('#excluded_folders_list').val(jQuery('#excluded_folders_list').val() + folder);
};

var manageBackupsTaskSites;
var manageBackupsError = false;
var manageBackupsTaskRemoteDestinations;
var manageBackupsTaskId;
var manageBackupsTaskType;
var manageBackupsTaskError;
managebackups_run_now = function(el)
{
    el = jQuery(el);
    el.hide();
    el.parent().find('.backup_run_loading').show();

    jQuery('#managebackups-task-status-text').html(dateToHMS(new Date()) + ' ' + __('Starting the backup task...'));
    jQuery('#managebackups-task-status-close').prop('value', __('Cancel'));
    jQuery('#managebackups-task-status-box').dialog({
        resizable: false,
        height: 350,
        width: 750,
        modal: true,
        close: function(event, ui) { if (!manageBackupsError) { location.reload();}}});

    var taskId = el.attr('task_id');
    var taskType = el.attr('task_type');
    //Fetch the sites to backup
    var data = {
        action:'mainwp_backuptask_get_sites',
        task_id: taskId
    };

    manageBackupsError = false;

    jQuery.post(ajaxurl, data, function(pTaskId, pTaskType) { return function (response) {
        manageBackupTaskSites = response.result.sites;
        manageBackupsTaskRemoteDestinations = response.result.remoteDestinations;
        manageBackupsTaskId = pTaskId;
        manageBackupsTaskType = pTaskType;
        manageBackupsTaskError = false;

        managebackups_run_next();
    } }(taskId, taskType), 'json');
};
managebackups_run_next = function()
{
    if (manageBackupTaskSites.length == 0)
    {
        appendToDiv('#managebackups-task-status-text', __('Backup task completed') + (manageBackupsTaskError ? ' <span class="mainwp-red">'+__('with errors')+'</span>' : '') + '.');

        jQuery('#managebackups-task-status-close').prop('value', __('Close'));
        if (!manageBackupsError)
        {
            setTimeout(function() {
                jQuery('#managebackups-task-status-box').dialog('destroy');
                location.reload();
            }, 3000);
        }
        return;
    }

    var siteId = manageBackupTaskSites[0]['id'];
    var siteName = manageBackupTaskSites[0]['name'];
    var size = manageBackupTaskSites[0][manageBackupsTaskType + 'size'];
    var fileNameUID = mainwp_uid();
    appendToDiv('#managebackups-task-status-text', '[' + siteName + '] '+__('Creating backup file.') + '<div id="managebackups-task-status-create-progress" siteId="'+siteId+'" style="margin-top: 1em;"></div>');

    manageBackupTaskSites.shift();
    var data = mainwp_secure_data({
        action: 'mainwp_backuptask_run_site',
        task_id: manageBackupsTaskId,
        site_id: siteId,
        fileNameUID: fileNameUID
    });

    jQuery('#managebackups-task-status-create-progress[siteId="'+siteId+'"]').progressbar({value: 0, max: size});
    var interVal = setInterval(function() {
        var data = mainwp_secure_data({
            action:'mainwp_createbackup_getfilesize',
            type: manageBackupsTaskType,
            siteId: siteId,
            fileName: '',
            fileNameUID: fileNameUID
        });
        jQuery.post(ajaxurl, data, function(pSiteId) { return function (response) {
            if (response.error) return;

            if (backupCreateRunning)
            {
                var progressBar = jQuery('#managebackups-task-status-create-progress[siteId="'+pSiteId+'"]');
                if (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max'))
                {
                    progressBar.progressbar('value', response.size);
                }
            }
        } }(siteId), 'json');
    }, 1000);

    backupCreateRunning = true;

    jQuery.ajax({url: ajaxurl,
        data: data,
        method: 'POST',
        success: function(pTaskId, pSiteId, pSiteName, pRemoteDestinations, pInterVal) { return function (response) {
            backupCreateRunning = false;
            clearInterval(pInterVal);

            var progressBar = jQuery('#managebackups-task-status-create-progress[siteId="'+pSiteId+'"]');
            progressBar.progressbar('value', parseFloat(progressBar.progressbar('option', 'max')));

            if (response.error)
            {
                appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] <span class="mainwp-red">Error: ' + getErrorMessage(response.error) + '</span>');
                manageBackupsTaskError = true;
                managebackups_run_next();
            }
            else
            {
                appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] '+__('Backup file created successfully.'));

                managebackups_backup_download_file(pSiteId, pSiteName, response.result.type, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, pRemoteDestinations);
            }
        } }(manageBackupsTaskId, siteId, siteName, manageBackupsTaskRemoteDestinations.slice(0), interVal),
        error: function(pInterVal, pSiteName) { return function() {
            backupCreateRunning = false;
            clearInterval(pInterVal);
            appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] ' + '<span class="mainwp-red">ERROR: Backup timed out - <a href="https://mainwp.com/help/docs/mainwp-introduction/resolving-system-requirement-issues/">Please check this help document for more information and possible fixes</a></span>');
        } }(interVal, siteName), dataType: 'json'});
};

managebackups_backup_download_file = function(pSiteId, pSiteName, type, url, file, regexfile, size, subfolder, remote_destinations)
{
    appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] Downloading the file. <div id="managebackups-task-status-progress" siteId="'+pSiteId+'" style="margin-top: 1em;"></div>');
    jQuery('#managebackups-task-status-progress[siteId="'+pSiteId+'"]').progressbar({value: 0, max: size});
    var interVal = setInterval(function() {
        var data = mainwp_secure_data({
            action:'mainwp_backup_getfilesize',
            local: file
        });
        jQuery.post(ajaxurl, data, function(pSiteId) { return function (response) {
            if (response.error) return;

            if (backupDownloadRunning)
            {
                var progressBar = jQuery('#managebackups-task-status-progress[siteId="'+pSiteId+'"]');
                if (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max'))
                {
                    progressBar.progressbar('value', response.result);
                }
            }
        } }(pSiteId), 'json');
    }, 500);

    var data = mainwp_secure_data({
        action:'mainwp_backup_download_file',
        site_id: pSiteId,
        type: type,
        url: url,
        local: file
    });
    backupDownloadRunning = true;
    jQuery.post(ajaxurl, data, function(pFile, pRegexFile, pSubfolder, pRemoteDestinations, pSize, pType, pInterVal, pSiteName, pSiteId, pUrl) { return function (response) {
        backupDownloadRunning = false;
        clearInterval(pInterVal);

        if (response.error)
        {
            appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] <span class="mainwp-red">ERROR: '+ getErrorMessage(response.error) + '</span>');
            appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] <span class="mainwp-red">'+__('Backup failed!') + '</span>');

            manageBackupsError = true;
            managebackups_run_next();
            return;
        }

        jQuery('#managebackups-task-status-progress[siteId="'+pSiteId+'"]').progressbar();
        jQuery('#managebackups-task-status-progress[siteId="'+pSiteId+'"]').progressbar('value', pSize);
        appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] '+__('Download from child site completed.'));


        var newData = mainwp_secure_data({
            action:'mainwp_backup_delete_file',
            site_id: pSiteId,
            file: pUrl
        });
        jQuery.post(ajaxurl, newData, function() {}, 'json');

        managebackups_backup_upload_file(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize);
    } }(file, regexfile, subfolder, remote_destinations, size, type, interVal, pSiteName, pSiteId, url), 'json');
};

managebackups_backup_upload_file = function(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize)
{
    if (pRemoteDestinations.length > 0)
    {
        var remote_destination = pRemoteDestinations[0];
        //upload..
        var unique = Date.now();
        appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] '+ __('Uploading to selected remote destination: %1 (%2)', remote_destination.title, remote_destination.type) + '<div id="managesite-upload-status-progress-' + unique + '" style="margin-top: 1em;"></div>');

        jQuery('#managesite-upload-status-progress-'+unique).progressbar({value: 0, max: pSize});

        var fnc = function(pUnique) { return function(pFunction) {
            var data2 = mainwp_secure_data({
                action:'mainwp_backup_upload_getprogress',
                unique: pUnique
            }, false);

            jQuery.ajax({
                url: ajaxurl,
                data: data2,
                method: 'POST',
                success: function(pFunc) { return function (response) {
                    if (backupUploadRunning[pUnique] && response.error)
                    {
                        setTimeout(function() { pFunc(pFunc); }, 1000);
                        return;
                    }

                    if (backupUploadRunning[pUnique])
                    {
                        var progressBar = jQuery('#managesite-upload-status-progress-'+pUnique);
                        if ((progressBar.length > 0) && (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max')) && (progressBar.progressbar('option', 'value') < parseInt(response.result)))
                        {
                            progressBar.progressbar('value', response.result);
                        }

                        setTimeout(function() { pFunc(pFunc); }, 1000);
                    }
                } }(pFunction),
                error:function(pFunc) { return function() {
                    if (backupUploadRunning[pUnique]) { setTimeout(function() { pFunc(pFunc); }, 10000); }
                } }(pFunction),
                dataType: 'json'});
        } }(unique);

        setTimeout(function() { fnc(fnc); }, 1000);

        backupUploadRunning[unique] = true;

        var data = mainwp_secure_data({
            action:'mainwp_backup_upload_file',
            file: pFile,
            siteId: pSiteId,
            regexfile: pRegexFile,
            subfolder: pSubfolder,
            type: pType,
            remote_destination: remote_destination.id,
            unique: unique
        });

        pRemoteDestinations.shift();
        jQuery.ajax({
            type: 'POST',
            url: ajaxurl,
            data: data,
            success: function(pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId) { return function (response) {
                if (!response || response.error || !response.result)
                {
                    managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, response.error ? response.error : '');
                }
                else
                {
                    backupUploadRunning[pUnique] = false;

                    var progressBar = jQuery('#managesite-upload-status-progress-'+pUnique);
                    progressBar.progressbar();
                    progressBar.progressbar('value', pSize);

                    var obj = response.result;
                    if (obj.error)
                    {
                        manageBackupsError = true;
                        appendToDiv('#managebackups-task-status-text', '<span class="mainwp-red">[' + pSiteName + '] '+__('Upload to %1 (%2) failed:', obj.title, obj.type) + ' ' + obj.error + '</span>');
                    }
                    else
                    {
                        appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] '+__('Upload to %1 (%2) successful',  obj.title, obj.type));
                    }

                    managebackups_backup_upload_file(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
                }
            } }(pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, data, unique, remote_destination.id),
            error: function(pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId) { return function (response) {
                managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId);
            } }(pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, data, unique, remote_destination.id),
            dataType: 'json'
        });
    }
    else
    {
        appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] '+__('Backup completed.'));
        managebackups_run_next();
    }
};

managebackups_backup_upload_file_retry_fail = function(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError)
{
    //we've got the pid file!!!!
    var data = mainwp_secure_data({
        action:'mainwp_backup_upload_checkstatus',
        unique: pUnique,
        remote_destination: pRemoteDestId
    });

    jQuery.ajax({
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function(response) {
            if (response.status == 'done')
            {
                backupUploadRunning[pUnique] = false;

                var progressBar = jQuery('#managesite-upload-status-progress-'+pUnique);
                progressBar.progressbar();
                progressBar.progressbar('value', pSize);

                appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] '+__('Upload to %1 (%2) successful',  response.info.title, response.info.type));

                managebackups_backup_upload_file(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
            }
            else if (response.status == 'busy')
            {
                //Try again in 10seconds
                setTimeout(function() {
                    managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
                },10000);
            }
            else if (response.status == 'stalled')
            {
                if (backupContinueRetriesUnique[pUnique] == undefined)
                {
                    backupContinueRetriesUnique[pUnique] = 1;
                }
                else
                {
                    backupContinueRetriesUnique[pUnique]++;
                }

                if (backupContinueRetriesUnique[pUnique] > 10)
                {
                    if (responseError != undefined)
                    {
                        manageBackupsError = true;
                        appendToDiv('#managebackups-task-status-text', '<span class="mainwp-red">[' + pSiteName + '] '+__('Upload to %1 (%2) failed:', response.info.title, response.info.type) + ' ' + responseError + '</span>');
                    }
                    else
                    {
                        appendToDiv('#managebackups-task-status-text', ' <span class="mainwp-red">[' + pSiteName + '] ERROR: Upload timed out - <a href="http://docs.mainwp.com/backup-failed-php-ini-settings/">Please check this help document for more information and possible fixes</a></span>');
                    }

                    managebackups_backup_upload_file(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
                }
                else
                {
                    appendToDiv('#managebackups-task-status-text', ' [' + pSiteName + '] Upload stalled, trying to resume from last position.');

                    pData = mainwp_secure_data(pData); //Rescure

                    jQuery.ajax({
                        url: ajaxurl,
                        data: pData,
                        method: 'POST',
                        success: function(pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId) { return function (response) {
                            if (response.error || !response.result)
                            {
                                managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, response.error ? response.error : '');
                            }
                            else
                            {
                                backupUploadRunning[pUnique] = false;

                                var progressBar = jQuery('#managesite-upload-status-progress-'+pUnique);
                                progressBar.progressbar();
                                progressBar.progressbar('value', pSize);

                                var obj = response.result;
                                if (obj.error)
                                {
                                    manageBackupsError = true;
                                    appendToDiv('#managebackups-task-status-text', '<span class="mainwp-red">[' + pSiteName + '] '+__('Upload to %1 (%2) failed:', obj.title, obj.type) + ' ' + obj.error + '</span>');
                                }
                                else
                                {
                                    appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] '+__('Upload to %1 (%2) successful',  obj.title, obj.type));
                                }

                                managebackups_backup_upload_file(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
                            }
                        } }(pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId),
                        error: function(pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId) { return function (response) {
                            managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
                        } }(pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pData, pUnique, pRemoteDestId),
                        dataType: 'json'
                    });
                }
            }
            else
            {
                //Try again in 5seconds
                setTimeout(function() {
                    managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
                },10000);
            }
        },
        error: function() {
            //Try again in 10seconds
            setTimeout(function() {
                managebackups_backup_upload_file_retry_fail(pData, pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
            },10000);
        },
        dataType: 'json'
    });
};

managebackups_init = function () {
    setVisible('#mainwp_managebackups_add_errors', false);

    jQuery('#mainwp_managebackups_add_errors').html();
    jQuery('#mainwp_managebackups_add_message').html();
};

mainwp_managebackups_update = function (event) {
    managebackups_init();

    var errors = [];
    if (jQuery('#mainwp_managebackups_add_name').val() == '') {
        errors.push(__('Please enter a valid name for your backup task'));
        jQuery('#mainwp_managebackups_add_name').parent().parent().addClass('form-invalid');
    }
    else {
        jQuery('#mainwp_managebackups_add_name').parent().parent().removeClass('form-invalid');
    }

    if (!jQuery('#mainwp_managebackups_schedule_daily').hasClass('mainwp_action_down') && !jQuery('#mainwp_managebackups_schedule_weekly').hasClass('mainwp_action_down') && !jQuery('#mainwp_managebackups_schedule_monthly').hasClass('mainwp_action_down')) {
        errors.push('Please select a schedule');
        jQuery('#mainwp_managebackups_schedule_daily').parent().parent().addClass('form-invalid');
    }
    else {
        jQuery('#mainwp_managebackups_schedule_daily').parent().parent().removeClass('form-invalid');
    }
    if (!jQuery('#backup_type_full').hasClass('mainwp_action_down') && !jQuery('#backup_type_db').hasClass('mainwp_action_down')) {
        errors.push('Please select a backup type');
        jQuery('#backup_type_full').parent().parent().addClass('form-invalid');
    }
    else {
        jQuery('#backup_type_full').parent().parent().removeClass('form-invalid');
    }

    if (jQuery('#select_by').val() == 'site') {
        var selected_sites = [];
        jQuery("input[name='selected_sites[]']:checked").each(function (i) {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push(__('Please select websites or groups to add a backup task.'));
        }
    }
    else {
        var selected_groups = [];
        jQuery("input[name='selected_groups[]']:checked").each(function (i) {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push(__('Please select websites or groups to add a backup task.'));
        }
    }
    if (errors.length > 0) {
        setHtml('#mainwp_managebackups_add_errors', errors.join('<br />'));
    }
    else {
        setHtml('#mainwp_managebackups_add_message', __('Adding the task...'));

        jQuery('#mainwp_managebackups_update').attr('disabled', 'true'); //disable button to add..

        var loadFilesBeforeZip = jQuery('[name="mainwp_options_loadFilesBeforeZip"]:checked').val();
        var name = jQuery('#mainwp_managebackups_add_name').val();
        name = name.replace(/"/g, '&quot;');
        var data = mainwp_secure_data({
            action:'mainwp_updatebackup',
            id:jQuery('#mainwp_managebackups_edit_id').val(),
            name:name,
            schedule: (jQuery('#mainwp_managebackups_schedule_daily').hasClass('mainwp_action_down') ? 'daily' : (jQuery('#mainwp_managebackups_schedule_weekly').hasClass('mainwp_action_down') ? 'weekly' : 'monthly')),
            type:(jQuery('#backup_type_full').hasClass('mainwp_action_down') ? 'full' : 'db'),
            exclude:jQuery('#excluded_folders_list').val(),
            excludebackup: (jQuery('#mainwp-known-backup-locations').attr('checked') ? 1 : 0),
            excludecache: (jQuery('#mainwp-known-cache-locations').attr('checked') ? 1 : 0),
            excludenonwp: (jQuery('#mainwp-non-wordpress-folders').attr('checked') ? 1 : 0),
            excludezip: (jQuery('#mainwp-zip-archives').attr('checked') ? 1 : 0),
            'groups[]':selected_groups,
            'sites[]':selected_sites,
            subfolder:jQuery('#mainwp_managebackups_add_subfolder').val(),
            remote_destinations:(jQuery('#backup_location_remote').hasClass('mainwp_action_down') ? jQuery.map(jQuery('#backup_destination_list').find('input[name="remote_destinations[]"]'), function(el) { return jQuery(el).val(); }) : []),
            filename: jQuery('#backup_filename').val(),
            archiveFormat: jQuery('#mainwp_archiveFormat').val(),
            maximumFileDescriptorsOverride: jQuery('#mainwp_options_maximumFileDescriptorsOverride_override').is(':checked') ? 1 : 0,
            maximumFileDescriptorsAuto: (jQuery('#mainwp_maximumFileDescriptorsAuto').attr('checked') ? 1 : 0),
            maximumFileDescriptors: jQuery('#mainwp_options_maximumFileDescriptors').val(),
            loadFilesBeforeZip: loadFilesBeforeZip
        });
        jQuery.post(ajaxurl, data, function (response) {
            managebackups_init();
            if (response.error != undefined) {
                setHtml('#mainwp_managebackups_add_errors', response.error);
            }
            else {
                //Message the backup task was added
                setHtml('#mainwp_managebackups_add_message', response.result);
            }

            jQuery('#mainwp_managebackups_update').removeAttr('disabled'); //Enable add button
        }, 'json');
    }
};
mainwp_managebackups_add = function (event) {
    managebackups_init();

    var errors = [];
    if (jQuery('#mainwp_managebackups_add_name').val() == '') {
        errors.push(__('Please enter a valid name for your backup task'));
        jQuery('#mainwp_managebackups_add_name').parent().parent().addClass('form-invalid');
    }
    else {
        jQuery('#mainwp_managebackups_add_name').parent().parent().removeClass('form-invalid');
    }
    if (!jQuery('#mainwp_managebackups_schedule_daily').hasClass('mainwp_action_down') && !jQuery('#mainwp_managebackups_schedule_weekly').hasClass('mainwp_action_down') && !jQuery('#mainwp_managebackups_schedule_monthly').hasClass('mainwp_action_down')) {
        errors.push('Please select a schedule');
        jQuery('#mainwp_managebackups_schedule_daily').parent().parent().addClass('form-invalid');
    }
    else {
        jQuery('#mainwp_managebackups_schedule_daily').parent().parent().removeClass('form-invalid');
    }
    if (!jQuery('#backup_type_full').hasClass('mainwp_action_down') && !jQuery('#backup_type_db').hasClass('mainwp_action_down')) {
        errors.push('Please select a backup type');
        jQuery('#backup_type_full').parent().parent().addClass('form-invalid');
    }
    else {
        jQuery('#backup_type_full').parent().parent().removeClass('form-invalid');
    }

    if (jQuery('#select_by').val() == 'site') {
        var selected_sites = [];
        jQuery("input[name='selected_sites[]']:checked").each(function (i) {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push('<div class="mainwp-notice mainwp-notice-red">'+__('Please select websites or groups.')+'</div>');
            jQuery('#selected_sites').addClass('form-invalid');
        }
        else {
            jQuery('#selected_sites').removeClass('form-invalid');
        }
    }
    else {
        var selected_groups = [];
        jQuery("input[name='selected_groups[]']:checked").each(function (i) {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push('<div class="mainwp-notice mainwp-notice-red">'+__('Please select websites or groups.')+'</div>');
            jQuery('#selected_groups').addClass('form-invalid');
        }
        else {
            jQuery('#selected_groups').removeClass('form-invalid');
        }
    }

    if (errors.length > 0) {
        setHtml('#mainwp_managebackups_add_errors', errors.join('<br />'));
    }
    else {
        setHtml('#mainwp_managebackups_add_message', __('Adding the task...'));

        jQuery('#mainwp_managebackups_add').attr('disabled', 'true'); //disable button to add..

        jQuery('#mainwp_managesites_add').attr('disabled', 'true'); //Disable add button
        var loadFilesBeforeZip = jQuery('[name="mainwp_options_loadFilesBeforeZip"]:checked').val();
        var name = jQuery('#mainwp_managebackups_add_name').val();
        name = name.replace(/"/g, '&quot;');
        var data = mainwp_secure_data({
            action:'mainwp_addbackup',
            name:name,
            schedule:(jQuery('#mainwp_managebackups_schedule_daily').hasClass('mainwp_action_down') ? 'daily' : (jQuery('#mainwp_managebackups_schedule_weekly').hasClass('mainwp_action_down') ? 'weekly' : 'monthly')),
            type:(jQuery('#backup_type_full').hasClass('mainwp_action_down') ? 'full' : 'db'),
            exclude:(jQuery('#backup_type_full').hasClass('mainwp_action_down') ? jQuery('#excluded_folders_list').val() : ''),
            excludebackup: (jQuery('#mainwp-known-backup-locations').attr('checked') ? 1 : 0),
            excludecache: (jQuery('#mainwp-known-cache-locations').attr('checked') ? 1 : 0),
            excludenonwp: (jQuery('#mainwp-non-wordpress-folders').attr('checked') ? 1 : 0),
            excludezip: (jQuery('#mainwp-zip-archives').attr('checked') ? 1 : 0),
            'groups[]':selected_groups,
            'sites[]':selected_sites,
            subfolder: jQuery('#mainwp_managebackups_add_subfolder').val(),
            remote_destinations:(jQuery('#backup_location_remote').hasClass('mainwp_action_down') ? jQuery.map(jQuery('#backup_destination_list').find('input[name="remote_destinations[]"]'), function(el) { return jQuery(el).val(); }) : []),
            filename: jQuery('#backup_filename').val(),
            archiveFormat: jQuery('#mainwp_archiveFormat').val(),
            maximumFileDescriptorsOverride: jQuery('#mainwp_options_maximumFileDescriptorsOverride_override').is(':checked') ? 1 : 0,
            maximumFileDescriptorsAuto: (jQuery('#mainwp_maximumFileDescriptorsAuto').attr('checked') ? 1 : 0),
            maximumFileDescriptors: jQuery('#mainwp_options_maximumFileDescriptors').val(),
            loadFilesBeforeZip: loadFilesBeforeZip
        });
        jQuery.post(ajaxurl, data, function (response) {
            managebackups_init();
            if (response.error != undefined) {
                setHtml('#mainwp_managebackups_add_errors', response.error);
            }
            else {
                //Message the backup task was added
                location.href = 'admin.php?page=ManageBackups&a=1';
                setHtml('#mainwp_managebackups_add_message', response.result);
//                jQuery('#mainwp_managbackups_cont').hide();
                hide_error('ajax-information-zone');
                hide_error('ajax-error-zone');
            }

            jQuery('#mainwp_managebackups_add').removeAttr('disabled'); //Enable add button
        }, 'json');
    }
};
managebackups_remove = function (element) {
    var id = jQuery(element).attr('task_id');
    managebackups_init();

    var q = confirm(__('Are you sure you want to delete this backup task?'));
    if (q) {
        jQuery('#task-status-' + id).html(__('Removing the task...'));
        var data = mainwp_secure_data({
            action:'mainwp_removebackup',
            id:id
        });
        jQuery.post(ajaxurl, data, function(pElement) { return function (response) {
            managebackups_init();
            var result = '';
            var error = '';
            if (response.error != undefined)
            {
                error = response.error;
            }
            else if (response.result == 'SUCCESS') {
                result = __('The task has been removed.');
            }
            else {
                error = __('An undefined error occured.');
            }

            if (error != '') {
                setHtml('#mainwp_managebackups', error);
            }
            if (result != '') {
                setHtml('#mainwp_managebackups_add_message', result);
            }
            jQuery('#task-status-' + id).html('');
            if (error == '') {
                jQuery(pElement).closest('tr').remove();
            }
        } }(element), 'json');
    }

    return false;
};
managebackups_resume = function (element) {
    var id = jQuery(element).attr('task_id');
    managebackups_init();

    jQuery('#task-status-' + id).html(__('Resuming the task...'));
    var data = mainwp_secure_data({
        action:'mainwp_resumebackup',
        id:id
    });
    jQuery.post(ajaxurl, data, function(pElement, pId) { return function (response) {
        managebackups_init();
        var result = '';
        var error = '';
        if (response.error != undefined)
        {
            error = response.error;
        }
        else if (response.result == 'SUCCESS') {
            result = __('The task has been resumed.');
        }
        else {
            error = __('An undefined error occured.');
        }

        if (error != '') {
            setHtml('#mainwp_managebackups', error);
        }
        if (result != '') {
            setHtml('#mainwp_managebackups_add_message', result);
        }
        jQuery('#task-status-' + id).html('');

        if (error == '')
        {
            jQuery(pElement).after('<a href="#" task_id="'+pId+'" onClick="return managebackups_pause(this)">' + __('Pause') + '</a>');
            jQuery(pElement).remove();
        }
    } }(element, id), 'json');

    return false;
};
managebackups_pause = function (element) {
    var id = jQuery(element).attr('task_id');
    managebackups_init();

    jQuery('#task-status-' + id).html(__('Pausing the task...'));
    var data = mainwp_secure_data({
        action:'mainwp_pausebackup',
        id:id
    });
    jQuery.post(ajaxurl, data, function(pElement, pId) { return function (response) {
        managebackups_init();
        var result = '';
        var error = '';
        if (response.error != undefined)
        {
            error = response.error;
        }
        else if (response.result == 'SUCCESS') {
            result = __('The task has been paused.');
        }
        else {
            error = __('An undefined error occured.');
        }

        if (error != '') {
            setHtml('#mainwp_managebackups', error);
        }
        if (result != '') {
            setHtml('#mainwp_managebackups_add_message', result);
        }
        jQuery('#task-status-' + id).html('');
        if (error == '')
        {
            jQuery(pElement).after('<a href="#" task_id="'+pId+'" onClick="return managebackups_resume(this)">' + __('Resume') + '</a>');
            jQuery(pElement).remove();
        }
    } }(element, id), 'json');

    return false;
};


/**
 * Manage sites page
 */
jQuery(document).on('click', '#mainwp_backup_destinations', function() {
    jQuery('.mainwp_backup_destinations').toggle();
    return false;
});
jQuery(document).on('click', '.mainwp_action#backup_type_full', function() {
    jQuery('.mainwp_action#backup_type_db').removeClass('mainwp_action_down');
    jQuery(this).addClass('mainwp_action_down');
    jQuery('[class^=mainwp-exclude]').show();
    jQuery('.mainwp_backup_exclude_files_content').show();
    return false;
});

/*   Suggested Excludes   */

jQuery(document).on('click', '#mainwp-show-kbl-folders', function(){
    jQuery('#mainwp-show-kbl-folders').hide();
    jQuery('#mainwp-hide-kbl-folders').show();
    jQuery('#mainwp-kbl-content').show();
    return false;
});
jQuery(document).on('click', '#mainwp-hide-kbl-folders', function(){
    jQuery('#mainwp-show-kbl-folders').show();
    jQuery('#mainwp-hide-kbl-folders').hide();
    jQuery('#mainwp-kbl-content').hide();
    return false;
});

jQuery(document).on('click', '#mainwp-show-kcl-folders', function(){
    jQuery('#mainwp-show-kcl-folders').hide();
    jQuery('#mainwp-hide-kcl-folders').show();
    jQuery('#mainwp-kcl-content').show();
    return false;
});
jQuery(document).on('click', '#mainwp-hide-kcl-folders', function(){
    jQuery('#mainwp-show-kcl-folders').show();
    jQuery('#mainwp-hide-kcl-folders').hide();
    jQuery('#mainwp-kcl-content').hide();
    return false;
});

jQuery(document).on('click', '#mainwp-show-nwl-folders', function(){
    jQuery('#mainwp-show-nwl-folders').hide();
    jQuery('#mainwp-hide-nwl-folders').show();
    jQuery('#mainwp-nwl-content').show();
    return false;
});
jQuery(document).on('click', '#mainwp-hide-nwl-folders', function(){
    jQuery('#mainwp-show-nwl-folders').show();
    jQuery('#mainwp-hide-nwl-folders').hide();
    jQuery('#mainwp-nwl-content').hide();
    return false;
});

jQuery(document).on('click', '.mainwp_action#backup_type_db', function() {
    jQuery('.mainwp_action#backup_type_full').removeClass('mainwp_action_down');
    jQuery(this).addClass('mainwp_action_down');
    jQuery('[class^=mainwp-exclude]').hide();
    jQuery('.mainwp_backup_exclude_files_content').hide();
    return false;
});
jQuery(document).on('click', '.mainwp_action#backup_location_remote', function() {
    jQuery('.mainwp_action#backup_location_local').removeClass('mainwp_action_down');
    jQuery(this).addClass('mainwp_action_down');
    var backupDestinations = jQuery('.mainwp_backup_destinations');
    backupDestinations.show();
    if (jQuery('input[name="remote_destinations[]"]').length == 0) jQuery('#addremotebackupdestination').trigger('click');
    return false;
});
jQuery(document).on('click', '.mainwp_action#backup_location_local', function() {
    jQuery('.mainwp_action#backup_location_remote').removeClass('mainwp_action_down');
    jQuery(this).addClass('mainwp_action_down');
    jQuery('.mainwp_backup_destinations').hide();
    return false;
});
jQuery(document).on('click', '.backuptaskschedule', function() {
    jQuery('.backuptaskschedule').removeClass('mainwp_action_down');
    jQuery(this).addClass('mainwp_action_down');
    return false;
});
jQuery(document).ready(function () {

    jQuery('#mainwp_managesites_add_wpurl').live('change', function (event) {
        var url = jQuery('#mainwp_managesites_add_wpurl').val();
        var protocol = jQuery('#mainwp_managesites_add_wpurl_protocol').val();
        if (url.lastIndexOf('http://') === 0) {
            protocol = 'http';
            url = url.substring(7);
        }
        else if (url.lastIndexOf('https://') === 0) {
            protocol = 'https';
            url = url.substring(8);
        }
        if (jQuery('#mainwp_managesites_add_wpname').val() == '') {
            jQuery('#mainwp_managesites_add_wpname').val(url);
        }
        jQuery('#mainwp_managesites_add_wpurl').val(url);
        jQuery('#mainwp_managesites_add_wpurl_protocol').val(protocol).trigger("change");
    });
    jQuery('.mainwp_site_reconnect').live('click', function(event)
    {
        mainwp_managesites_reconnect(jQuery(this), false);
        return false;
    });
    jQuery('.mainwp_rightnow_site_reconnect').live('click', function(event)
    {
        mainwp_managesites_reconnect(jQuery(this), true);
        return false;
    });
    jQuery(document).on('click', '.mainwp_site_testconnection', function(event)
    {
        if (jQuery(this).attr('href') != '#') return;
        managesites_bulk_init();
        var thisEl = jQuery(this);
        var loadingEl = thisEl.parent().find('span');
        jQuery('.mainwp_site_testconnection').removeAttr('href');

        thisEl.hide();
        loadingEl.show();

        var data = mainwp_secure_data({
            action:'mainwp_testwp',
            siteid: jQuery(thisEl.parents('tr')[0]).attr('siteid')
        });
        jQuery.ajax({
            type: 'POST',
            url: ajaxurl,
            data: data,
            success: function(pThisEl, pLoadingEl) { return function (response) {
                pLoadingEl.hide();
                pThisEl.show();
                jQuery('.mainwp_site_testconnection').attr('href', '#');
                if (response.error)
                {
                    if (response.httpCode)
                    {
                        setHtml('#mainwp_managesites_add_errors', response.sitename+ ': '+__('Connection test failed.')+' '+__('URL:')+' '+response.host+' - '+__('HTTP-code:')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ' - ' + __('Error message:') + ' ' + response.error + ' <br/> <em>' + __('To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', '<a href="http://docs.mainwp.com/http-status-codes/" target="_blank">', '</a>', response.httpCode) + '</em>');
                    }
                    else
                    {
                        setHtml('#mainwp_managesites_add_errors', response.sitename+ ': '+__('Connection test failed.')+ ' '+__('URL:')+' '+response.host+' - '+__('Error message:') + ' ' + response.error);
                    }
                }
                else if (response.httpCode)
                {
                    if (response.httpCode == '200')
                    {
                        setHtml('#mainwp_managesites_add_message', response.sitename + ': ' + __('Connection test successful.') + ' ' + __('URL:') + ' ' + response.host + (response.ip != undefined ? ' (IP: ' + response.ip + ')' : '') + ' (' + __('Received HTTP-code:') + ' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ')' + ' <br/> <em>' + __('To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', '<a href="http://docs.mainwp.com/http-status-codes/" target="_blank">', '</a>', response.httpCode) + '</em>');
                    }
                    else
                    {
                        setHtml('#mainwp_managesites_add_errors', response.sitename+ ': '+__('Connection test failed.')+' '+__('URL:')+' '+response.host + (response.ip != undefined ? ' (IP: ' + response.ip + ')' : '') +' '+__('Received HTTP-code:')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ' <br/> <em>' + __('To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', '<a href="http://docs.mainwp.com/http-status-codes/" target="_blank">', '</a>', response.httpCode) + '</em>');
                    }
                }
                else
                {
                    var hint = '<br/>' + __('Hint: In case your dashboard and child sites are on the same server, please contact your host support and verify if your server allows loop-back connections.');
                    setHtml('#mainwp_managesites_add_errors', response.sitename+ ': ' + __('Invalid response from the server, please try again.') + hint);
                }
            } }(thisEl, loadingEl),
            dataType: 'json'});
        return false;
    });

    jQuery(".chk-sync-install-plugin").change(function() {
        var parent = jQuery(this).closest('.sync-ext-row');
        var opts = parent.find(".sync-options input[type='checkbox']");
        if (jQuery(this).is(':checked')) {
            opts.removeAttr( "disabled");
            opts.prop( "checked", true);
        } else {
            opts.prop( "checked", false);
            opts.attr( "disabled", "disabled");
        }
    });

    managesites_init();
});

managesites_init = function () {
    setVisible('#mainwp_managesites_add_errors', false);
    setVisible('#mainwp_managesites_add_message', false);

    jQuery('#mainwp_managesites_add_errors').html();
    jQuery('#mainwp_managesites_add_message').html();

    setVisible('#mainwp_managesites_test_errors', false);
    setVisible('#mainwp_managesites_test_message', false);

    jQuery('#mainwp_managesites_test_errors').html();
    jQuery('#mainwp_managesites_test_message').html();
    jQuery('.sync-ext-row span.status').html('');
    jQuery('.sync-ext-row span.status').css('color', '#0073aa');

    managesites_bulk_init();

};
mainwp_managesites_reconnect = function(pElement, pRightNow)
{
    var parent = pElement.parent();
    parent.html('<i class="fa fa-spinner fa-pulse"></i> '+'Trying to reconnect...');

    var data = {
        action:'mainwp_reconnectwp',
        siteid: pElement.attr('siteid')
    };

    jQuery.post(ajaxurl, data, function(parentElement) { return function (response) {
        response = jQuery.trim(response);
        parentElement.hide();
        if (response.substr(0, 5) == 'ERROR') {
            var error;
            if (response.length == 5) {
                error = 'Undefined error!';
            }
            else {
                error = 'ERROR: ' + response.substr(6);
            }
            if (pRightNow)
            {
                show_error('mainwp_main_errors', error);
            }
            else
            {
                show_error('mainwp_managesites_add_errors', error);
            }
        }
        else
        {
            if (pRightNow) location.reload();
            else show_error('mainwp_managesites_add_message', response);
        }
    } }(parent));
};
mainwp_managesites_add = function (event) {
    managesites_init();

    var errors = [];

    if (jQuery('#mainwp_managesites_add_wpname').val() == '') {
        errors.push(__('Please enter a name for the website.'));
    }
    if (jQuery('#mainwp_managesites_add_wpurl').val() == '') {
        errors.push(__('Please enter a valid URL for your site.'));
    }
    else {
        var url = jQuery('#mainwp_managesites_add_wpurl').val();
        if (url.substr(-1) != '/') {
            url += '/';
        }
        jQuery('#mainwp_managesites_add_wpurl').val(url);
        if (!isUrl(jQuery('#mainwp_managesites_add_wpurl_protocol').val() + '://' + jQuery('#mainwp_managesites_add_wpurl').val())) {
            errors.push(__('Please enter a valid URL for your site.'));
        }
    }
    if (jQuery('#mainwp_managesites_add_wpadmin').val() == '') {
        errors.push(__('Please enter a username of the website administrator.'));
    }

    if (errors.length > 0) {
        setHtml('#mainwp_managesites_add_errors', errors.join('<br />'));
    }
    else {
        setHtml('#mainwp_managesites_add_message', __('Adding the site to MainWP...'));

        jQuery('#mainwp_managesites_add').attr('disabled', 'true'); //disable button to add..

        //Check if valid user & rulewp is installed?
        var url = jQuery('#mainwp_managesites_add_wpurl_protocol').val() + '://' + jQuery('#mainwp_managesites_add_wpurl').val();
        if (url.substr(-1) != '/') {
            url += '/';
        }
        var name = jQuery('#mainwp_managesites_add_wpname').val();
        name = name.replace(/"/g, '&quot;');
        var data = mainwp_secure_data({
            action:'mainwp_checkwp',
            name:name,
            url:url,
            admin:jQuery('#mainwp_managesites_add_wpadmin').val(),
            verify_certificate:jQuery('#mainwp_managesites_verify_certificate').val(),
            ssl_version:jQuery('#mainwp_managesites_ssl_version').val(),
            http_user:jQuery('#mainwp_managesites_add_http_user').val(),
            http_pass:jQuery('#mainwp_managesites_add_http_pass').val()
        });
        var hint_msg = __('If you are experiencing an issue with connecting your website to your MainWP Dashboard, please check <a href="https://mainwp.com/help/category/troubleshooting/adding-a-child-site-issues/" target="_blank">this help document</a>.');
        jQuery.post(ajaxurl, data, function (res_things) {
            response = res_things.response;
            response = jQuery.trim(response);
            var url = jQuery('#mainwp_managesites_add_wpurl_protocol').val() + '://' + jQuery('#mainwp_managesites_add_wpurl').val();
            if (url.substr(-1) != '/') {
                url += '/';
            }
            url = url.replace(/"/g, '&quot;');

            if (response == 'HTTPERROR') {
                errors.push('HTTP error - website does not exist!');
                jQuery('#mainwp-add-site-notice .curl-notice').fadeIn(1000);
            } else if (response == 'NOMAINWP') {
                var hint = '<br/>' + __('Hint: On your child site, go to the Server Information page and verify that the SSL extension is enabled.');
                errors.push(__('MainWP Child Plugin not detected, first install and activate the MainWP Child plugin and add your site to MainWP Dashboard afterwards. Click <a href="%1" target="_blank">here</a> to install <a href="%2" target="_blank">MainWP</a> plugin (do not forget to activate it after installation).', url + 'wp-admin/plugin-install.php?tab=search&type=term&s=mainwp&plugin-search-input=Search+Plugins', url + 'wp-admin/plugin-install.php?tab=search&type=term&s=mainwp&plugin-search-input=Search+Plugins') + hint);
            } else if (response.substr(0, 5) == 'ERROR') {
                if (response.length == 5) {
                    errors.push(__('Undefined error!'));
                }
                else {
                    errors.push('Error - ' + response.substr(6));
                }
            } else if (response == 'OK') {
                jQuery('#mainwp_managesites_add').attr('disabled', 'true'); //Disable add button

                var name = jQuery('#mainwp_managesites_add_wpname').val();
                name = name.replace(/"/g, '&quot;');
                var data = mainwp_secure_data({
                    action:'mainwp_addwp',
                    managesites_add_wpname:name,
                    managesites_add_wpurl:url,
                    managesites_add_wpadmin:jQuery('#mainwp_managesites_add_wpadmin').val(),
                    managesites_add_uniqueId:jQuery('#mainwp_managesites_add_uniqueId').val(),
                    groupids:jQuery('#mainwp_managesites_add_addgroups').val(),
                    verify_certificate:jQuery('#mainwp_managesites_verify_certificate').val(),
                    ssl_version:jQuery('#mainwp_managesites_ssl_version').val(),
                    managesites_add_http_user:jQuery('#mainwp_managesites_add_http_user').val(),
                    managesites_add_http_pass:jQuery('#mainwp_managesites_add_http_pass').val(),
                });

                jQuery.post(ajaxurl, data, function (res_things) {
                    var site_id = 0;
                    if (res_things.error)
                    {
                        response = 'ERROR: ' + res_things.error;
                    }
                    else
                    {
                        response = res_things.response;
                        site_id = res_things.siteid;
                    }
                    response = jQuery.trim(response);
                    managesites_init();

                    if (response.substr(0, 5) == 'ERROR') {
                        setHtml('#mainwp_managesites_add_errors', response.substr(6));
                        setHtml('#mainwp_managesites_add_message', hint_msg);
                    }
                    else {
                        //Message the WP was added
                        setHtml('#mainwp_managesites_add_message', response);
                        if (site_id > 0) {
                            mainwp_get_site_icon(site_id);
                            jQuery('.sync-ext-row').attr('status', 'queue');
                            jQuery('#mainwp_managesites_add_message').append( '<div id="mwp_applying_ext_settings"><i class="fa fa-spinner fa-pulse"></i> ' + __('Applying extensions settings...') + '<br/>');
                            setTimeout(function(){
                                mainwp_managesites_sync_extension_start_next(site_id);
                            }, 1000);
                        }

                        //Reset fields
                        jQuery('#mainwp_managesites_add_wpname').val('');
                        jQuery('#mainwp_managesites_add_wpurl').val('');
                        jQuery('#mainwp_managesites_add_wpurl_protocol').val('http');
                        jQuery('#mainwp_managesites_add_wpadmin').val('');
                        jQuery('#mainwp_managesites_add_uniqueId').val('');
                        jQuery('#mainwp_managesites_add_addgroups').val('');
                        jQuery('#mainwp_managesites_add_addgroups > option' ).removeAttr("selected").trigger("change");
                        jQuery("input[name='selected_groups[]']:checked").attr('checked', false);
                        jQuery('#mainwp_managesites_verify_certificate').val(1);
                        jQuery('#mainwp_managesites_ssl_version').val('auto');
                        if (res_things.redirectUrl != undefined)
                        {
                            setTimeout(function(pUrl) { return function() { location.href = pUrl; } }(res_things.redirectUrl), 1000);
                        }
                    }

                    jQuery('#mainwp_managesites_add').removeAttr('disabled'); //Enable add button
                }, 'json');
            }
            if (errors.length > 0) {
                managesites_init();
                jQuery('#mainwp_managesites_add').removeAttr('disabled'); //Enable add button

                setHtml('#mainwp_managesites_add_errors', errors.join('<br />'), false);
                setHtml('#mainwp_managesites_add_message', hint_msg, false);
            }
        }, 'json');
    }
};

mainwp_managesites_sync_extension_start_next = function(siteId)
{
    while ((pluginToInstall = jQuery('.sync-ext-row[status="queue"]:first')) && (pluginToInstall.length > 0)  && (bulkInstallCurrentThreads < bulkInstallMaxThreads))
    {
        mainwp_managesites_sync_extension_start_specific(pluginToInstall, siteId);
    }

    if ((pluginToInstall.length == 0) && (bulkInstallCurrentThreads == 0))
    {
        jQuery('#mwp_applying_ext_settings').remove();
    }
};

mainwp_managesites_sync_extension_start_specific = function (pPluginToInstall, pSiteId)
{
    pPluginToInstall.attr('status', 'progress');
    var syncGlobalSettings = pPluginToInstall.find(".sync-global-options input[type='checkbox']:checked").length > 0 ? true : false;
    var install_plugin = pPluginToInstall.find(".sync-install-plugin input[type='checkbox']:checked").length > 0 ? true : false;

    if (syncGlobalSettings) {
        mainwp_extension_apply_plugin_settings(pPluginToInstall, pSiteId, true);
    } else if (install_plugin) {
        mainwp_extension_prepareinstallplugin(pPluginToInstall, pSiteId);
    } else {
        mainwp_managesites_sync_extension_start_next(pSiteId);
        return;
    }
};

mainwp_extension_prepareinstallplugin = function(pPluginToInstall, pSiteId) {
    var site_Ids = [];
    site_Ids.push(pSiteId);
    bulkInstallCurrentThreads++;
    var plugin_slug = pPluginToInstall.find(".sync-install-plugin").attr('slug');
    var workingEl = pPluginToInstall.find(".sync-install-plugin i");
    var statusEl = pPluginToInstall.find(".sync-install-plugin span.status");

    var data = {
        action: 'mainwp_ext_prepareinstallplugintheme',
        type: 'plugin',
        slug: plugin_slug,
        'selected_sites[]': site_Ids,
        selected_by: 'site',
    };

    workingEl.show();
    statusEl.css('color','#0073aa');
    statusEl.html(__('Preparing for installation...'));

    jQuery.post(ajaxurl, data, function (response) {
        workingEl.hide();
        if (response.sites && response.sites[pSiteId]) {
            statusEl.html(__('Installing...'));
            var data = mainwp_secure_data({
                action: 'mainwp_ext_performinstallplugintheme',
                type: 'plugin',
                url: response.url,
                siteId: pSiteId,
                activatePlugin: true,
                overwrite: false,
            });
            workingEl.show();
            jQuery.post(ajaxurl, data, function (response) {
                workingEl.hide();
                var apply_settings = false;
                var syc_msg = '';
                var _success = false;
                if ((response.ok != undefined) && (response.ok[pSiteId] != undefined)) {
                    syc_msg = __( 'Installation successful!' );
                    statusEl.html( syc_msg );
                    apply_settings = pPluginToInstall.find(".sync-options input[type='checkbox']:checked").length > 0 ? true : false;
                    if (apply_settings) {
                        mainwp_extension_apply_plugin_settings(pPluginToInstall, pSiteId, false);
                    }
                    _success = true;
                } else if ((response.errors != undefined) && (response.errors[pSiteId] != undefined)) {
                    syc_msg = __( 'Installation failed!' ) + ': ' + response.errors[pSiteId][1];
                    statusEl.html( syc_msg );
                    statusEl.css( 'color', 'red' );
                } else {
                    syc_msg = __( 'Installation failed!' );
                    statusEl.html( syc_msg );
                    statusEl.css( 'color', 'red' );
                }

                if (syc_msg != '') {
                    if (_success)
                        syc_msg = '<span style="color:#0073aa">' + syc_msg + '!</span>';
                    else
                        syc_msg = '<span style="color:red">' + syc_msg + '!</span>';
                    jQuery('#mainwp_managesites_add_message').append( pPluginToInstall.find(".sync-install-plugin").attr('plugin_name') + ' ' + syc_msg + '<br/>');
                }

                if (!apply_settings) {
                    bulkInstallCurrentThreads--;
                    mainwp_managesites_sync_extension_start_next( pSiteId );
                }
            }, 'json');
        } else {
            statusEl.css('color','red');
            statusEl.html(__('Error while preparing the installation. Please, try again.'));
            bulkInstallCurrentThreads--;
        }
    }, 'json');
}

mainwp_extension_apply_plugin_settings = function(pPluginToInstall, pSiteId, pGlobal) {
    var extSlug = pPluginToInstall.attr('slug');
    var workingEl = pPluginToInstall.find(".options-row i");
    var statusEl = pPluginToInstall.find(".options-row span.status");
    if (pGlobal)
        bulkInstallCurrentThreads++;

    var data = mainwp_secure_data({
        action: 'mainwp_ext_applypluginsettings',
        ext_dir_slug: extSlug,
        siteId: pSiteId
    });

    workingEl.show();
    statusEl.html( __( 'Applying settings...' ) );
    jQuery.post(ajaxurl, data, function (response) {
        workingEl.hide();
        var syc_msg = '';
        var _success = false;
        if (response) {
            if (response.result && response.result == 'success') {
                var msg = '';
                if (response.message != undefined) {
                    msg = ' ' + response.message;
                }
                statusEl.html( __( 'Applying settings successful!' ) + msg );
                syc_msg = __( 'Successful' );
                _success = true
            } else if (response.error != undefined) {
                statusEl.html( __( 'Applying settings failed!' ) + ': ' + response.error);
                statusEl.css( 'color', 'red' );
                syc_msg = __('failed');
            } else {
                statusEl.html( __( 'Applying settings failed!' ) );
                statusEl.css( 'color', 'red' );
                syc_msg = __('failed');
            }
        } else {
            statusEl.html( __( 'Undefined error!' ) );
            statusEl.css( 'color', 'red' );
            syc_msg = __('failed');
        }

        if (syc_msg != '') {
            if (_success)
                syc_msg = '<span style="color:#0073aa">' + syc_msg + '!</span>';
            else
                syc_msg = '<span style="color:red">' + syc_msg + '!</span>';
            if (pGlobal) {
                syc_msg = __('Apply global %1 options', pPluginToInstall.attr('ext_name')) + ' ' + syc_msg + '<br/>';
            } else {
                syc_msg = __('Apply %1 settings', pPluginToInstall.find('.sync-install-plugin').attr('plugin_name')) + ' ' + syc_msg + '<br/>';
            }
            jQuery('#mainwp_managesites_add_message').append( syc_msg );
        }
        bulkInstallCurrentThreads--;
        mainwp_managesites_sync_extension_start_next( pSiteId );
    }, 'json');
}

mainwp_get_site_icon = function(siteId) {
    jQuery('#mainwp_managesites_add_message').append( '<span id="download_icon_working">' + '<br/>' + __('Downloading site icon...') + ' <i class="fa fa-spinner fa-pulse"></i>' + '</span>');
    var data = mainwp_secure_data({
        action: 'mainwp_get_site_icon',
        siteId: siteId
    });
    jQuery.post(ajaxurl, data, function (response) {
        jQuery('#mainwp_managesites_add_message').find('#download_icon_working').html('');
        if (response) {
            if (response.result && response.result == 'success') {
                jQuery('#mainwp_managesites_add_message').find('#download_icon_working').html('<br/>' + __('Download site icon successful!'));
            } else if (response.error != undefined) {
                jQuery('#mainwp_managesites_add_errors').append(' ' + __('Download site icon failed') + ': ' + response.error);
            } else {
                jQuery('#mainwp_managesites_add_errors').append(' ' + __('Download site icon failed'));
            }
        } else {
            jQuery('#mainwp_managesites_add_errors').append( ' ' + __('Download site icon failed') + ': ' + __( 'Undefined error!' ) );
        }
    }, 'json');
}


mainwp_managesites_test = function (event) {
    managesites_init();

    var errors = [];

    if (jQuery('#mainwp_managesites_test_wpurl').val() == '') {
        errors.push(__('Please enter a valid URL for your site.'));
    }
    else {
        var url = jQuery('#mainwp_managesites_test_wpurl').val();
        if (url.substr(0, 4) != 'http') {
            url = 'http://' + url;
        }
        if (url.substr(-1) != '/') {
            url += '/';
        }
        jQuery('#mainwp_managesites_test_wpurl').val(url);
        if (!isUrl(jQuery('#mainwp_managesites_test_wpurl').val())) {
            errors.push(__('Please enter a valid URL for your site'));
        }
    }

    if (errors.length > 0) {
        setHtml('#mainwp_managesites_test_errors', errors.join('<br />'));
    }
    else {
        setHtml('#mainwp_managesites_test_message', __('Testing connection...'));

        jQuery('#mainwp_managesites_test').attr('disabled', 'true'); //disable button to add..

        var url = jQuery('#mainwp_managesites_test_wpurl').val();
        if (url.substr(0, 4) != 'http') {
            url = 'http://' + url;
        }
        if (url.substr(-1) != '/') {
            url += '/';
        }
        var data = mainwp_secure_data({
            action:'mainwp_testwp',
            url:url,
            test_verify_cert: jQuery('#mainwp_managesites_test_verifycertificate').val(),
            test_ssl_version: jQuery('#mainwp_managesites_test_ssl_version').val(),
            http_user: jQuery('#mainwp_managesites_test_http_user').val(),
            http_pass: jQuery('#mainwp_managesites_test_http_pass').val()
        });
        jQuery.post(ajaxurl, data, function (response) {
            managesites_init();
            jQuery('#mainwp_managesites_test').removeAttr('disabled'); //Enable add button

            if (response.error)
            {
                if (response.httpCode)
                {
                    setHtml('#mainwp_managesites_test_errors',
                        __('Connection test failed!')+' '+__('URL:')+' '+response.host+' - '+__('HTTP-code:')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ' - '+__('Error message:')+' ' + response.error + ' <br/> <em>' + __('To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', '<a href="http://docs.mainwp.com/http-status-codes/" target="_blank">', '</a>', response.httpCode) + '</em>');
                }
                else
                {
                    setHtml('#mainwp_managesites_test_errors',
                        __('Connection test failed!')+' '+__('Error message:')+' ' + response.error);
                }
            }
            else if (response.httpCode)
            {
                if (response.httpCode == '200')
                {
                    setHtml('#mainwp_managesites_test_message', __('Connection test successful!') + ' ('+__('URL:')+' '+response.host + (response.ip != undefined ? ' (IP: ' + response.ip + ')' : '') + ' - '+__('Received HTTP-code')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ')' + ' <br/> <em>' + __('To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', '<a href="http://docs.mainwp.com/http-status-codes/" target="_blank">', '</a>', response.httpCode) + '</em>');
                }
                else
                {
                    setHtml('#mainwp_managesites_test_errors',
                        __('Connection test failed.')+' '+__('URL:')+' '+response.host + (response.ip != undefined ? ' (IP: ' + response.ip + ')' : '') +' - '+ __('Received HTTP-code:')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ' <br/> <em>' + __('To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', '<a href="http://docs.mainwp.com/http-status-codes/" target="_blank">', '</a>', response.httpCode) + '</em>');
                }
            }
            else
            {
                var hint = '<br/>' + __('Hint: In case your dashboard and child sites are on the same server, please contact your host support and verify if your server allows loop-back connections.');
                setHtml('#mainwp_managesites_test_errors',
                    __('Invalid response from the server, please try again.') + hint);
            }
        }, 'json');
    }
};
managesites_remove = function (id) {
    managesites_init();

    var q = confirm(__('Are you sure you want to delete this site?'));
    if (q) {
        jQuery('#site-status-' + id).html('<i class="fa fa-spinner fa-pulse"></i> '+__('Removing and deactivating the MainWP Child plugin...'));
        var data = mainwp_secure_data({
            action:'mainwp_removesite',
            id:id
        });
        jQuery.post(ajaxurl, data, function (response) {
            managesites_init();

            var result = '';
            var error = '';
            if (response.error != undefined)
            {
                error = response.error;
            }
            else if (response.result == 'SUCCESS') {
                result = __('The site has been removed and the MainWP Child plugin has been disabled.');
            } else if (response.result == 'NOSITE') {
                error = __('The requested site has not been found.');
            }
            else {
                result = __('The site has been removed but the MainWP Child plugin could not be disabled.');
            }

            if (error != '') {
                setHtml('#mainwp_managesites_add_errors', error);
            }
            if (result != '') {
                setHtml('#mainwp_managesites_add_message', result);
            }

            jQuery('#site-status-' + id).html('');
            jQuery('tr[siteid=' + id + ']').remove();

        }, 'json');
    }
};


/**
 * Bulk upload sites
 */
var import_current = 0;
var import_stop_by_user = false;
var import_total = 0;
var import_count_success = 0;
var import_count_fails = 0;

jQuery(document).ready(function () {
    import_total = jQuery('#mainwp_managesites_total_import').val();

    jQuery('#mainwp_managesites_add').live('click', function (event) {
        mainwp_managesites_add(event);
    });

    jQuery('#mainwp_managesites_bulkadd').live('click', function (event) {
        if (jQuery('#mainwp_managesites_file_bulkupload').val() == '') {
            setHtml('#mainwp_managesites_add_errors', __('Please enter csv file for upload.'), false);
        } else {
            jQuery('#mainwp_managesites_bulkadd_form').submit();
        }
        return false;
    });

    jQuery('#mainwp_managesites_test').live('click', function (event) {
        mainwp_managesites_test(event);
    });


    jQuery('#mainwp_managesites_btn_import').live('click', function () {
        if (import_stop_by_user == false) {
            import_stop_by_user = true;
            jQuery('#mainwp_managesites_import_logging .log').append(__('Paused import by user.')+"\n");
            jQuery('#mainwp_managesites_btn_import').val(__('Continue'));
            jQuery('#mainwp_managesites_btn_save_csv').removeAttr("disabled"); //Enable
            jQuery('#MainWPBulkUploadSitesLoading').hide();
        }
        else
        {
            import_stop_by_user = false;
            jQuery('#mainwp_managesites_import_logging .log').append(__('Continue import.')+"\n");
            jQuery('#mainwp_managesites_btn_import').val(__('Pause'));
            jQuery('#mainwp_managesites_btn_save_csv').attr('disabled', 'true'); // Disable 
            jQuery('#MainWPBulkUploadSitesLoading').show();
            mainwp_managesites_import_sites();
        }
    });

    jQuery('#mainwp_managesites_btn_save_csv').live('click', function () {
        var fail_data = jQuery('#mainwp_managesites_import_fail_logging .log').html();
        var blob = new Blob([fail_data], {type: "text/plain;charset=utf-8"});
        saveAs(blob, "import_fails.csv");
    });

    if (jQuery('#mainwp_managesites_do_import').val() == 1) {
        jQuery('#MainWPBulkUploadSitesLoading').show();
        mainwp_managesites_import_sites();
    }
});

mainwp_managesites_import_sites = function () {
    if (import_stop_by_user == true)
        return;

    import_current++;

    if (import_current > import_total)
    {
        jQuery('#mainwp_managesites_btn_import').val(__('Finished!'));
        jQuery('#mainwp_managesites_btn_import').attr('disabled', 'true'); //Disable
        if (import_count_success < import_total) {
            jQuery('#mainwp_managesites_btn_save_csv').removeAttr("disabled"); //Enable
        }
        jQuery('#mainwp_managesites_import_logging .log').append('\n' + __('Number of sites to Import: %1 Created sites: %2 Failed: %3', import_total, import_count_success, import_count_fails) + "\n");
        jQuery('#mainwp_managesites_import_logging').scrollTop(jQuery('#mainwp_managesites_import_logging .log').height());
        jQuery('#MainWPBulkUploadSitesLoading').hide();
        return;
    }

    var import_line = jQuery('#mainwp_managesites_import_csv_line_' + import_current).val();
    var import_line_orig = jQuery('#mainwp_managesites_import_csv_line_' + import_current).attr('original');
    var import_items = import_line.split(',');

    var import_wpname = import_items[0];
    var import_wpurl = import_items[1];
    var import_wpadmin = import_items[2];
    var import_wpgroups = import_items[3];
    var import_uniqueId = import_items[4];
    var import_http_username = import_items[5];
    var import_http_password = import_items[6];
    var import_verify_certificate = import_items[7];
    var import_ssl_version = import_items[8];

    if (import_wpname == undefined)
        import_wpname = '';
    if (typeof(import_wpurl) == "undefined")
        import_wpurl = '';
    if (typeof(import_wpadmin) == "undefined")
        import_wpadmin = '';
    if (typeof(import_wpgroups) == "undefined")
        import_wpgroups = '';
    if (typeof(import_uniqueId) == "undefined")
        import_uniqueId = '';

    jQuery('#mainwp_managesites_import_logging .log').append('[' + import_current + '] ' + import_line_orig);

    var errors = [];

    if (import_wpname == '') {
        errors.push(__('Please enter the site name.'));
    }

    if (import_wpurl == '') {
        errors.push(__('Please enter the site URL.'));
    }

    if (import_wpadmin == '') {
        errors.push(__('Please enter username of the site administrator.'));
    }

    if (errors.length > 0) {
        jQuery('#mainwp_managesites_import_logging .log').append('[' + import_current + ']>> Error - ' + errors.join(" ") + '\n');
        jQuery('#mainwp_managesites_import_fail_logging .log').append(import_line_orig);
        import_count_fails++;
        mainwp_managesites_import_sites();
        return;
    }

    var data = mainwp_secure_data({
        action:'mainwp_checkwp',
        name: import_wpname,
        url: import_wpurl,
        admin: import_wpadmin,
        check_me: import_current,

        verify_certificate:import_verify_certificate,
        ssl_version:import_ssl_version,
        http_user:import_http_username,
        http_pass:import_http_password
    });

    jQuery.post(ajaxurl, data, function (res_things) {
        response = res_things.response;

        var check_result = '[' + res_things.check_me + ']>> ';

        response = jQuery.trim(response);
        var url = import_wpurl;
        if (url.substr(0, 4) != 'http') {
            url = 'http://' + url;
        }
        if (url.substr(-1) != '/') {
            url += '/';
        }
        url = url.replace(/"/g, '&quot;');

        if (response == 'HTTPERROR') {
            errors.push(check_result + __('HTTP error: website does not exist!'));
        } else if (response == 'NOMAINWP') {
            errors.push(check_result + __('MainWP Child plugin not detected! First install and activate the MainWP Child plugin and add your site to MainWP afterwards. Click <a href="%1" target="_blank">here</a> to install <a href="%2" target="_blank">MainWP</a> plugin (do not forget to activate it after installation)', url + 'wp-admin/plugin-install.php?tab=search&type=term&s=mainwp&plugin-search-input=Search+Plugins', url + 'wp-admin/plugin-install.php?tab=search&type=term&s=mainwp&plugin-search-input=Search+Plugins'));
        } else if (response.substr(0, 5) == 'ERROR') {
            if (response.length == 5) {
                errors.push(check_result + __('Undefined error!'));
            }
            else {
                errors.push(check_result + 'ERROR: ' + response.substr(6));
            }
        } else if (response == 'OK') {
            var groupids = [];
            var data = mainwp_secure_data({
                action:'mainwp_addwp',
                managesites_add_wpname: import_wpname,
                managesites_add_wpurl: url,
                managesites_add_wpadmin: import_wpadmin,
                managesites_add_uniqueId: import_uniqueId,
                'groupids[]':groupids,
                groupnames_import: import_wpgroups,
                add_me: import_current,

                verify_certificate:import_verify_certificate,
                ssl_version:import_ssl_version,
                managesites_add_http_user:import_http_username,
                managesites_add_http_pass:import_http_password
            });

            jQuery.post(ajaxurl, data, function (res_things) {
                if (res_things.error)
                {
                    response = 'ERROR: ' + res_things.error;
                }
                else
                {
                    response = res_things.response;
                }
                var add_result = '[' + res_things.add_me + ']>> ';

                response = jQuery.trim(response);

                if (response.substr(0, 5) == 'ERROR') {
                    jQuery('#mainwp_managesites_import_fail_logging .log').append(import_line_orig);
                    jQuery('#mainwp_managesites_import_logging .log').append(add_result + response.substr(6) + "\n");
                    import_count_fails++;
                }
                else {
                    //Message the WP was added
                    jQuery('#mainwp_managesites_import_logging .log').append(add_result + response + "\n");
                    import_count_success++;
                }
                mainwp_managesites_import_sites();
            }, 'json').fail(function (xhr, textStatus, errorThrown) {
                jQuery('#mainwp_managesites_import_fail_logging .log').append(import_line_orig);
                jQuery('#mainwp_managesites_import_logging .log').append("error: " + errorThrown +"\n");
                import_count_fails++;
                mainwp_managesites_import_sites();
            });
        }

        if (errors.length > 0) {
            jQuery('#mainwp_managesites_import_fail_logging .log').append(import_line_orig);
            jQuery('#mainwp_managesites_import_logging .log').append(errors.join("\n") + '\n');
            import_count_fails++;
            mainwp_managesites_import_sites();
        }
        jQuery('#mainwp_managesites_import_logging').scrollTop(jQuery('#mainwp_managesites_import_logging .log').height());
    }, 'json');

};


/**
 * Select sites
 */
jQuery(document).ready(function () {
    jQuery('.mainwp_selected_sites_item input:checkbox').live('change', function () {
        if (jQuery(this).is(':checked'))
            jQuery(this).parent().addClass('selected_sites_item_checked');
        else
            jQuery(this).parent().removeClass('selected_sites_item_checked');

        mainwp_selected_refresh_count(this);
    });
    jQuery('.mainwp_selected_sites_item input:radio').live('change', function () {
        if (jQuery(this).is(':checked'))
        {
            jQuery(this).parent().addClass('selected_sites_item_checked');
            jQuery(this).parent().parent().find('.mainwp_selected_sites_item input:radio:not(:checked)').parent().removeClass('selected_sites_item_checked');
        }
        else
            jQuery(this).parent().removeClass('selected_sites_item_checked');

        mainwp_selected_refresh_count(this);
    });
    jQuery('.mainwp_selected_groups_item input:checkbox').live('change', function () {
        if (jQuery(this).is(':checked'))
            jQuery(this).parent().addClass('selected_groups_item_checked');
        else
            jQuery(this).parent().removeClass('selected_groups_item_checked');

        mainwp_selected_refresh_count(this);
    });
    jQuery('.mainwp_selected_groups_item input:radio').live('change', function () {
        if (jQuery(this).is(':checked'))
        {
            jQuery(this).parent().addClass('selected_groups_item_checked');
            jQuery(this).parent().parent().find('.mainwp_selected_groups_item input:radio:not(:checked)').parent().removeClass('selected_groups_item_checked');
        }
        else
            jQuery(this).parent().removeClass('selected_groups_item_checked');

        mainwp_selected_refresh_count(this);
    });

});
mainwp_selected_refresh_count = function(me)
{
    var parent = jQuery(me).closest('.mainwp_select_sites_wrapper');
    var value = 0;
    if (parent.find('#select_by').val() == 'site')
    {
        value = parent.find('.selected_sites_item_checked').length;
    }
    else
    {
        value = parent.find('.selected_groups_item_checked').length;
    }
    parent.find('.mainwp_sites_selectcount').html(value);
};
mainwp_site_select = function (elem) {
    mainwp_managebackups_updateExcludefolders();
    mainwp_newpost_updateCategories();
};
mainwp_group_select = function (elem) {
    mainwp_managebackups_updateExcludefolders();
    mainwp_newpost_updateCategories();
};
mainwp_ss_select = function (me, val) {
    var parent = jQuery(me).closest('.mainwp_select_sites_wrapper');
    if (parent.find('#select_by').val() == 'site') {
        parent.find('#selected_sites INPUT:enabled:checkbox').attr('checked', val).change();
    }
    else { //group
        parent.find('#selected_groups INPUT:enabled:checkbox').attr('checked', val).change();
    }
    mainwp_managebackups_updateExcludefolders();
    mainwp_newpost_updateCategories();
    return false;
};

mainwp_ss_select_mbox = function (me ,val) {
    mainwp_remove_all_cats(me);
    var parent  = jQuery(me).closest("div.mainwp_select_sites_box");
    if (parent.find('.select_by').val() == 'site') {
        parent.find('.selected_sites INPUT:enabled:checkbox').attr('checked', val).change();
    }
    else { //group
        parent.find('.selected_groups INPUT:enabled:checkbox').attr('checked', val).change();
    }
    return false;
};

mainwp_remove_all_cats = function (me) {
    var parent = jQuery(me).closest('.keyword');
    if (parent.length === 0)
        parent  = jQuery(me).closest('.keyword-bulk');
    if (parent.length === 0)
        return;
    parent.find('.selected_cats').html('<p class="remove">'+__('No selected categories!')+'</p>');  // remove all categories
};

var executingUpdateExcludeFolders = false;
var queueUpdateExcludeFolders = 0;
mainwp_managebackups_updateExcludefolders = function()
{
    if (executingUpdateExcludeFolders)
    {
        queueUpdateExcludeFolders++;
        return;
    }

    executingUpdateExcludeFolders = true;

    var elem = jQuery('#backup_exclude_folders');
    if (elem)
    {
        var sites = [];
        var groups = [];
        if (jQuery('#select_by').val() == 'site') {
            sites = jQuery.map(jQuery('#selected_sites INPUT:checkbox:checked'), function(el) { return jQuery(el).val(); });
        }
        else { //group
            groups = jQuery.map(jQuery('#selected_groups INPUT:checkbox:checked'), function(el, i) { return jQuery(el).val(); });
        }
        elem.fileTree({ root: '', script: ajaxurl + '?action=mainwp_site_dirs&sites='+encodeURIComponent(sites.join(','))+'&groups='+encodeURIComponent(groups.join(',')), multiFolder: false, postFunction: updateExcludedFoldersPostFunc});
    }
};
updateExcludedFoldersPostFunc = function()
{
    if (queueUpdateExcludeFolders > 0)
    {
        queueUpdateExcludeFolders--;
        executingUpdateExcludeFolders = false;
        mainwp_managebackups_updateExcludefolders();
    }
    else
    {
        executingUpdateExcludeFolders = false;
        updateExcludedFolders();
    }
};
var executingUpdateCategories = false;
var queueUpdateCategories = 0;
mainwp_newpost_updateCategories = function()
{
    if (executingUpdateCategories)
    {
        queueUpdateCategories++;
        return;
    }

    executingUpdateCategories = true;

    var elem = jQuery('.post_add_categories');
    if (elem)
    {
        var sites = [];
        var groups = [];
        if (jQuery('#select_by').val() == 'site') {
            sites = jQuery.map(jQuery('#selected_sites INPUT:checkbox:checked'), function(el) { return jQuery(el).val(); });
        }
        else { //group
            groups = jQuery.map(jQuery('#selected_groups INPUT:checkbox:checked'), function(el, i) { return jQuery(el).val(); });
        }

        //Get all post categories that are not from a website. post_category[]
        var site_categories = elem.find('li.sitecategory').find('input[name="post_category[]"]');
        var selected_categories = jQuery.map(elem.find('li'), function(el, i) { return jQuery(el).find('input[name="post_category[]"]:checked').val()});

        var data = {
            action:'mainwp_get_categories',
            sites: encodeURIComponent(sites.join(',')),
            groups: encodeURIComponent(groups.join(',')),
            selected_categories: encodeURIComponent(selected_categories.join(',')),
            post_id: jQuery('#post_ID').val()
        };

        jQuery.post(ajaxurl, data, function(pSiteCategories) {
            return function (response) {
                response = jQuery.trim(response);
                jQuery(pSiteCategories).each(function (key, value) { jQuery(value).parent().parent().remove() });

                jQuery('#categorychecklist').append(response);
                updateCategoriesPostFunc();
            }
        }(site_categories));
    }
    else
    {
        updateCategoriesPostFunc();
    }
};
updateCategoriesPostFunc = function()
{
    if (queueUpdateCategories > 0)
    {
        queueUpdateCategories--;
        executingUpdateCategories = false;
        mainwp_newpost_updateCategories();
    }
    else
    {
        executingUpdateCategories = false;
    }
};

jQuery(document).on('keydown', 'form[name="post"]', function(event) {
    if (event.keyCode == 13 && event.srcElement.tagName.toLowerCase() == "input")
    {
        event.preventDefault();
    }
});
jQuery(document).on('keyup', '#selected_sites-filter', function() {
    var filter = jQuery(this).val();
    var siteItems = jQuery(this).parents().find('.mainwp_selected_sites_item');
    for (var i = 0; i < siteItems.length; i++)
    {
        var currentElement = jQuery(siteItems[i]);
        var value = currentElement.find('label').text();
        if (value.indexOf(filter) > -1)
        {
            currentElement.show();
        }
        else
        {
            currentElement.hide();
            //20131124; changed, no deselect on other filter
//            currentElement.removeClass('selected_sites_item_checked');
//            currentElement.find('input').prop('checked', false);
        }
    }

    mainwp_managebackups_updateExcludefolders();
    mainwp_newpost_updateCategories();
});
jQuery(document).on('keyup', '#selected_groups-filter', function() {
    var filter = jQuery(this).val();
    var siteItems = jQuery(this).parents().find('.mainwp_selected_groups_item');
    for (var i = 0; i < siteItems.length; i++)
    {
        var currentElement = jQuery(siteItems[i]);
        var value = currentElement.find('label').text();
        if (value.indexOf(filter) > -1)
        {
            currentElement.show();
        }
        else
        {
            currentElement.hide();
            //20131124; changed, no deselect on other filter
//            currentElement.removeClass('selected_groups_item_checked');
//            currentElement.find('input').prop('checked', false);
        }
    }

    mainwp_managebackups_updateExcludefolders();
    mainwp_newpost_updateCategories();
});

jQuery(document).on('keyup', '#mainwp-fly-manu-filter', function() {
    var filter = jQuery(this).val();
    var siteItems = jQuery('#mainwp-sites-menu').find('.mwp-child-site-item');
    for (var i = 0; i < siteItems.length; i++)
    {
        var currentElement = jQuery(siteItems[i]);
        var value = currentElement.find('a').text();
        if (value.indexOf(filter) > -1)
        {
            currentElement.show();
        }
        else
        {
            currentElement.hide();
            //20131124; changed, no deselect on other filter
//            currentElement.removeClass('selected_groups_item_checked');
//            currentElement.find('input').prop('checked', false);
        }
    }

    mainwp_managebackups_updateExcludefolders();
    mainwp_newpost_updateCategories();
});

mainwp_ss_select_by = function (me, what) {
    var parent = jQuery(me).closest('.mainwp_select_sites_wrapper');
    parent.find('#mainwp_ss_site_link').css('display', (what == 'site' ? 'none' : 'inline'));
    parent.find('#mainwp_ss_site_text').css('display', (what == 'site' ? 'inline' : 'none'));

    parent.find('#mainwp_ss_group_link').css('display', (what == 'group' ? 'none' : 'inline'));
    parent.find('#mainwp_ss_group_text').css('display', (what == 'group' ? 'inline' : 'none'));

    parent.find('#selected_sites').css('display', (what == 'site' ? 'block' : 'none'));
    parent.find('#selected_groups').css('display', (what == 'group' ? 'block' : 'none'));

    parent.find('#selected_sites-filter').css('display', (what == 'site' ? 'block' : 'none'));
    parent.find('#selected_groups-filter').css('display', (what == 'group' ? 'block' : 'none'));

    if (what == 'site') {
        parent.find('#selected_groups INPUT:checkbox').attr('checked', false);
        parent.find('#selected_groups .selected_groups_item_checked').removeClass('selected_groups_item_checked');

        mainwp_selected_refresh_count(me);
    }
    else { //group
        parent.find('#selected_sites INPUT:checkbox').attr('checked', false);
        parent.find('#selected_sites .selected_sites_item_checked').removeClass('selected_sites_item_checked');

        mainwp_selected_refresh_count(me);
    }

    parent.find('#select_by').val(what);
    return false;
};

mainwp_ss_cats_select_by = function (me, what) {
    var parent = jQuery(me).closest("div.mainwp_select_sites_box");
    parent.find('.mainwp_ss_site_link').css('display', (what == 'site' ? 'none' : 'inline'));
    parent.find('.mainwp_ss_site_text').css('display', (what == 'site' ? 'inline' : 'none'));

    parent.find('.mainwp_ss_group_link').css('display', (what == 'group' ? 'none' : 'inline'));
    parent.find('.mainwp_ss_group_text').css('display', (what == 'group' ? 'inline' : 'none'));

    parent.find('.selected_sites').css('display', (what == 'site' ? 'block' : 'none'));
    parent.find('.selected_groups').css('display', (what == 'group' ? 'block' : 'none'));

    if (what == 'site') {
        parent.find('.selected_groups  input[type=checkbox]').attr('disabled', 'disabled');
        parent.find('.selected_sites  input[type=checkbox]').removeAttr('disabled');
    }
    else //group
    {
        parent.find('.selected_sites  input[type=checkbox]').attr('disabled', 'disabled');
        parent.find('.selected_groups  input[type=checkbox]').removeAttr('disabled');
    }

    parent.find('.select_by').val(what);
    jQuery('#mainwp-option-form').data('changed', 1);
    return false;
};

/**
 * Add new user
 */
jQuery(document).ready(function () {
    jQuery('#bulk_add_createuser').live('click', function (event) {
        mainwp_createuser(event);
    });

    jQuery('#bulk_import_createuser').live('click', function (event) {
        mainwp_bulkupload_users();
    });
});

mainwp_createuser = function () {
    var cont = true;
    if (jQuery('#user_login').val() == '') {
        jQuery('#user_login').parent().parent().addClass('form-invalid');
        cont = false;
    }
    else {
        jQuery('#user_login').parent().parent().removeClass('form-invalid');
    }

    if (jQuery('#email').val() == '') {
        jQuery('#email').parent().parent().addClass('form-invalid');
        cont = false;
    }
    else {
        jQuery('#email').parent().parent().removeClass('form-invalid');
    }

    if (jQuery('#pass1').val() == '') {
        jQuery('div.pw-wrap').addClass('form-invalid');
        jQuery('#pass1').parent().parent().addClass('form-invalid');
        cont = false;
    }
    else {
        jQuery('div.pw-wrap').removeClass('form-invalid');
        jQuery('#pass1').parent().parent().removeClass('form-invalid');
    }

    if (jQuery('#select_by').val() == 'site') {
        var selected_sites = [];
        jQuery("input[name='selected_sites[]']:checked").each(function (i) {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            show_error('ajax-error-zone', __('Please select websites or groups to add a user.'));
            jQuery('#selected_sites').addClass('form-invalid');
            cont = false;
        }
        else {
            hide_error('ajax-error-zone');
            jQuery('#selected_sites').removeClass('form-invalid');
        }
    }
    else {
        var selected_groups = [];
        jQuery("input[name='selected_groups[]']:checked").each(function (i) {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            show_error('ajax-error-zone', __('Please select websites or groups to add a user.'));
            jQuery('#selected_sites').addClass('form-invalid');
            cont = false;
        }
        else {
            hide_error('ajax-error-zone');
            jQuery('#selected_sites').removeClass('form-invalid');
        }
    }

    if (cont) {
        jQuery('#MainWP_Bulk_AddUserLoading').show();
        jQuery('#bulk_add_createuser').attr('disabled', 'disabled');
        //Add user via ajax!!
        var data = mainwp_secure_data({
            action:'mainwp_bulkadduser',
            'select_by':jQuery('#select_by').val(),
            'selected_groups[]':selected_groups,
            'selected_sites[]':selected_sites,
            'user_login':jQuery('#user_login').val(),
            'email':jQuery('#email').val(),
            'url':jQuery('#url').val(),
            'first_name':jQuery('#first_name').val(),
            'last_name':jQuery('#last_name').val(),
            'pass1':jQuery('#pass1').val(),
            'pass2':jQuery('#pass2').val(),
            'send_password':jQuery('#send_password').attr('checked'),
            'role':jQuery('#role').val()
        });
        jQuery.post(ajaxurl, data, function (response) {
            response = jQuery.trim(response);
            jQuery('#MainWP_Bulk_AddUserLoading').hide();
            jQuery('#bulk_add_createuser').removeAttr('disabled');
            if (response.substring(0, 5) == 'ERROR')
            {
                var responseObj = jQuery.parseJSON(response.substring(6));
                if (responseObj.error != undefined)
                {

                }
                else
                {
                    var errorFieldList = responseObj[0];
                    for (var i = 0; i < errorFieldList.length; i++) {
                        jQuery('#' + errorFieldList[i]).parent().parent().addClass('form-invalid');
                    }
                    var errorMessageList = responseObj[1];
                    var errorMessage = '';
                    for (var i = 0; i < errorMessageList.length; i++) {
                        if (errorMessage != '') errorMessage = errorMessage + "<br />";
                        errorMessage = errorMessage + errorMessageList[i];
                    }
                    if (errorMessage != '') {
                        show_error('ajax-error-zone', errorMessage);
                    }
                }
            }
            else {
                jQuery('#MainWP_Bulk_AddUser').html(response);
            }
        });
    }
};


/**
 * Bulk upload new user
 */
var import_user_stop_by_user = false;
var import_user_current_line_number = 0;
var import_user_total_import = 0;
var import_user_count_created_users = 0;
var import_user_count_create_fails = 0;

jQuery(document).ready(function () {
    import_user_total_import = jQuery('#import_user_total_import').val();

    jQuery('#import_user_btn_import').live('click', function () {
        if (import_stop_by_user == false) {
            import_stop_by_user = true;
            jQuery('#import_user_import_logging .log').append(_('Paused import by user.')+"\n");
            jQuery('#import_user_btn_import').val(__('Continue'));
            jQuery('#MainWPBulkUploadUserLoading').hide();
            jQuery('#import_user_btn_save_csv').removeAttr('disabled'); //Enable
        }
        else
        {
            import_stop_by_user = false;
            jQuery('#import_user_import_logging .log').append(__('Continue import.')+"\n");
            jQuery('#import_user_btn_import').val(__('Pause'));
            jQuery('#MainWPBulkUploadUserLoading').show();
            jQuery('#import_user_btn_save_csv').attr('disabled', 'true'); // Disable
            mainwp_import_users();
        }
    });


    jQuery('#import_user_btn_save_csv').live('click', function () {
        var fail_data = jQuery('#import_user_import_fail_logging .log').html();
        var blob = new Blob([fail_data], {type: "text/plain;charset=utf-8"});
        saveAs(blob, "import_fails.csv");
    });

    if (jQuery('#import_user_do_import').val() == 1) {
        jQuery('#MainWPBulkUploadUserLoading').show();
        mainwp_import_users();
    }
});


mainwp_bulkupload_users = function () {
    if (jQuery('#import_user_file_bulkupload').val() == '') {
        show_error('ajax-error-zone', __('Please enter CSV file for upload.'));
        jQuery('#import_user_file_bulkupload').parent().parent().addClass('form-invalid');
    } else {
        jQuery('#createuser').submit();
    }
};

mainwp_import_users = function () {

    if (import_stop_by_user == true)
        return;

    import_user_current_line_number++;

    if (import_user_current_line_number > import_user_total_import) {
        jQuery('#import_user_btn_import').val('Finished').attr('disabled', 'true');
        jQuery('#MainWPBulkUploadUserLoading').hide();
        jQuery('#import_user_import_logging .log').append('\n' + __('Number of users to import: %1 Created users: %2 Failed: %3', import_user_total_import, import_user_count_created_users, import_user_count_create_fails) + '\n');
        if (import_user_count_create_fails > 0) {
            jQuery('#import_user_btn_save_csv').removeAttr("disabled"); //Enable
        }
        jQuery('#import_user_import_logging').scrollTop(jQuery('#import_user_import_logging .log').height());
        return;
    }

    var import_user_line = jQuery('#user_import_csv_line_' + import_user_current_line_number).val();
    var import_user_items = import_user_line.split(',');

    var import_user_username = import_user_items[0];
    var import_user_email = import_user_items[1];
    var import_user_fname = import_user_items[2];
    var import_user_lname = import_user_items[3];
    var import_user_website = import_user_items[4];
    var import_user_passw = import_user_items[5];
    var import_user_send_passw = import_user_items[6];
    var import_user_role = import_user_items[7];
    var import_user_select_sites = import_user_items[8];
    var import_user_select_groups = import_user_items[9];
    var import_user_select_by = '';

    if (import_user_username == undefined)
        import_user_username = '';
    if (import_user_email == undefined)
        import_user_email = '';
    if (import_user_fname == undefined)
        import_user_fname = '';
    if (import_user_lname == undefined)
        import_user_lname = '';
    if (import_user_website == undefined)
        import_user_website = '';
    if (import_user_passw == undefined)
        import_user_passw = '';
    if (import_user_send_passw == undefined)
        import_user_send_passw = '';
    if (import_user_role == undefined)
        import_user_role = '';
    if (import_user_select_sites == undefined)
        import_user_select_sites = '';
    if (import_user_select_groups == undefined)
        import_user_select_groups = '';

    var import_user_current_line = import_user_username + ',' + import_user_email + ',' + import_user_fname + ',' + import_user_lname + ',' + import_user_website + ',' + import_user_passw +',' + import_user_send_passw + ',' + import_user_role + ',' + import_user_select_sites + ',' + import_user_select_groups + '\n';
    jQuery('#import_user_import_logging .log').append('[' + import_user_current_line_number + '] ' + import_user_current_line);

    var errors = [];

    if (import_user_username == '') {
        errors.push(__('Please enter a username.'));
    }

    if (import_user_email == '') {
        errors.push(__('Please enter an email.'));
    }

    if (import_user_passw == '') {
        errors.push(__('Please enter a password.'));
    }

    allowed_roles = ['subscriber', 'administrator', 'editor', 'author', 'contributor'];
    if (jQuery.inArray(import_user_role, allowed_roles) == -1) {
        errors.push(__('Please select a valid role.'));
    }

    if (import_user_select_sites != '') {
        var selected_sites = import_user_select_sites.split(';');
        if (selected_sites.length == 0) {
            errors.push(__('Please select websites or groups to add a user.'));
        }
        else
            import_user_select_by = 'site';
    }
    else {
        var selected_groups = import_user_select_groups.split(';');
        if (selected_groups.length == 0) {
            errors.push(__('Please select websites or groups to add a user.'));
        }
        else
            import_user_select_by = 'group';
    }

    if (errors.length > 0) {
        jQuery('#import_user_import_fail_logging .log').append(import_user_current_line);
        jQuery('#import_user_import_logging .log').append('[' + import_user_current_line_number + ']>> Error - ' + errors.join(" ") + '\n');
        import_user_count_create_fails++;
        mainwp_import_users();
        return;
    }

    //Add user via ajax!!
    var data = mainwp_secure_data({
        action:'mainwp_importuser',
        'select_by': import_user_select_by,
        'selected_groups[]':selected_groups,
        'selected_sites[]':selected_sites,
        'user_login': import_user_username,
        'email': import_user_email,
        'url': import_user_website,
        'first_name': import_user_fname,
        'last_name': import_user_lname,
        'pass1': import_user_passw,
        'send_password': import_user_send_passw,
        'role': import_user_role,
        'line_number': import_user_current_line_number
    });
    jQuery.post(ajaxurl, data, function (response_data) {
        if (response_data.error != undefined) return;

        var line_num = response_data.line_number;
        var okList = response_data.ok_list;
        var errorList = response_data.error_list;
        if (okList != undefined)
            for (var i = 0; i < okList.length; i++) {
                import_user_count_created_users++;
                jQuery('#import_user_import_logging .log').append('[' + line_num + ']>> ' + okList[i] + '\n');
            }

        if (errorList != undefined)
            for (var i = 0; i < errorList.length; i++) {
                import_user_count_create_fails++;
                jQuery('#import_user_import_logging .log').append('[' + line_num + ']>> ' + errorList[i] + '\n');
            }

        if (response_data.failed_logging != '' && response_data.failed_logging != undefined) {
            jQuery('#import_user_import_fail_logging .log').append( response_data.failed_logging + '\n');
        }
        jQuery('#import_user_import_logging').scrollTop(jQuery('#import_user_import_logging .log').height());
        mainwp_import_users();
    }, 'json');

};



/**
 * InstallPlugins/Themes
 */
jQuery(document).ready(function () {
    jQuery('#MainWPInstallBulkNavSearch').live('click', function (event) {
        event.preventDefault();
        jQuery( 'body' ).removeClass( 'show-upload-plugin' );
        jQuery( '#MainWPInstallBulkNavUpload' ).removeClass('mainwp_action_down');
        jQuery(this).addClass('mainwp_action_down');
    });
    jQuery('#MainWPInstallBulkNavUpload').live('click', function (event) {
        event.preventDefault();
        jQuery( 'body' ).addClass( 'show-upload-plugin' );
        jQuery( '#MainWPInstallBulkNavSearch' ).removeClass('mainwp_action_down');
        jQuery(this).addClass('mainwp_action_down');
    });
    jQuery('.filter-links li.plugin-install a').live('click', function (event) {
        event.preventDefault();
        jQuery('.filter-links li.plugin-install a').removeClass('current');
        jQuery(this).addClass('current');
        var tab = jQuery(this).parent().attr('tab');
        if (tab == 'search') {
            mainwp_install_search(event);
        } else {
            jQuery('#mainwp_installbulk_s').val('')
            jQuery('#mainwp_installbulk_tab').val(tab);
            mainwp_install_plugin_tab_search('tab:' + tab);
        }
    });

    jQuery('#mainwp_plugin_bulk_install_btn').live('click', function (event) {
        var selected = jQuery("input[type='radio'][name='install-plugin']:checked");
        if (selected.length == 0) {
            show_error('ajax-error-zone', __('Please select plugin to install files.'));
        } else if (selectedId = /^install-([^\-]*)-(.*)$/.exec(selected.attr('id'))) {
            mainwp_install_bulk('plugin', selectedId[2]);
        }
        return false;
    });

    jQuery('#mainwp_theme_bulk_install_btn').live('click', function (event) {
        var selected = jQuery("input[type='radio'][name='install-theme']:checked");
        if (selected.length == 0) {
            show_error('ajax-error-zone', __('Please select theme to install files.'));
        } else if (selectedId = /^install-([^\-]*)-(.*)$/.exec(selected.attr('id'))) {
            mainwp_install_bulk('theme', selectedId[2]);
        }
        return false;
    });
});

bulkInstallTotal = 0;
bulkInstallDone = 0;

mainwp_install_bulk = function (type, slug) {
    var data = {
        action:'mainwp_preparebulkinstallplugintheme',
        type:type,
        slug:slug,
        selected_by:jQuery('#select_by').val()
    };

    if (jQuery('#select_by').val() == 'site') {
        var selected_sites = [];
        jQuery("input[name='selected_sites[]']:checked").each(function (i) {
            selected_sites.push(jQuery(this).val());
        });

        if (selected_sites.length == 0) {
            show_error('ajax-error-zone', __('Please select websites or groups to install files.'));
            jQuery('#selected_sites').addClass('form-invalid');
            return;
        }
        else {
            jQuery('#selected_sites').removeClass('form-invalid');
        }
        data['selected_sites[]'] = selected_sites;
    }
    else {
        var selected_groups = [];
        jQuery("input[name='selected_groups[]']:checked").each(function (i) {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            show_error('ajax-error-zone', __('Please select websites or groups to install files.'));
            jQuery('#selected_groups').addClass('form-invalid');
            return;
        }
        else {
            jQuery('#selected_groups').removeClass('form-invalid');
        }
        data['selected_groups[]'] = selected_groups;
    }

    jQuery.post(ajaxurl, data, function (pType, pActivatePlugin, pOverwrite) { return function (response) {
        var installQueue = '<div class="postbox"><div class="inside">';
        installQueue += '<h3>Installing ' + type + '</h3>';

        var dateObj = new Date();
        starttimeDashboardAction = dateObj.getTime();
        if (pType == 'plugin')
            dashboardActionName = 'installing_new_plugin';
        else
            dashboardActionName = 'installing_new_theme';
        countRealItemsUpdated = 0;
        bulkInstallDone = 0;
        for (var siteId in response.sites)
        {
            var site = response.sites[siteId];
            installQueue += '<span class="siteBulkInstall" siteid="' + siteId + '" status="queue"><strong>' + site['name'].replace(/\\(.)/mg, "$1") + '</strong>: <span class="queue"><i class="fa fa-clock-o"></i> '+__('Queued')+'</span><span class="progress"><i class="fa fa-spinner fa-pulse"></i> '+__('Installing...')+'</span><span class="status"></span></span><br />';
            bulkInstallTotal++;
        }
        installQueue += '<div id="bulk_install_info"></div>';
        installQueue += '</div></div>';

        jQuery('#mainwp_wrap-inside').html(installQueue);
        mainwp_install_bulk_start_next(pType, response.url, pActivatePlugin, pOverwrite);
    } }(type, jQuery('#chk_activate_plugin').is(':checked'), jQuery('#chk_overwrite').is(':checked')), 'json');
    jQuery('#mainwp_wrap-inside').html('<div class="postbox"><div class="inside"><h3><i class="fa fa-spinner fa-pulse"></i> '+ __('Preparing %1 installation...', type) + '</h3></div></div>');
};

bulkInstallMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
bulkInstallCurrentThreads = 0;


mainwp_install_bulk_start_next = function(pType, pUrl, pActivatePlugin, pOverwrite)
{
    while ((siteToInstall = jQuery('.siteBulkInstall[status="queue"]:first')) && (siteToInstall.length > 0)  && (bulkInstallCurrentThreads < bulkInstallMaxThreads))
    {
        mainwp_install_bulk_start_specific(pType, pUrl, pActivatePlugin, pOverwrite, siteToInstall);
    }

    if (bulkInstallDone == bulkInstallTotal && bulkInstallTotal != 0) {
        if (mainwpParams.enabledTwit == true) {
            var dateObj = new Date();
            var countSec = (dateObj.getTime() - starttimeDashboardAction) / 1000;
            jQuery('#bulk_install_info').html('<i class="fa fa-spinner fa-pulse"></i>');
            if (countSec <= mainwpParams.maxSecondsTwit) {
                var data = {
                    action:'mainwp_twitter_dashboard_action',
                    actionName: dashboardActionName,
                    countSites: countRealItemsUpdated,
                    countSeconds: countSec,
                    countItems: 1,
                    countRealItems: countRealItemsUpdated,
                    showNotice: 1
                };
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
        jQuery('#bulk_install_info').before('<div class="mainwp-notice mainwp-notice-blue">' + mainwp_install_bulk_you_know_msg(pType, 1) + '</div>');
    }
};

mainwp_install_bulk_start_specific = function (pType, pUrl, pActivatePlugin, pOverwrite, pSiteToInstall)
{
    bulkInstallCurrentThreads++;
    pSiteToInstall.attr('status', 'progress');

    pSiteToInstall.find('.queue').hide();
    pSiteToInstall.find('.progress').show();

    var data = mainwp_secure_data({
        action:'mainwp_installbulkinstallplugintheme',
        type:pType,
        url: pUrl,
        activatePlugin: pActivatePlugin,
        overwrite: pOverwrite,
        siteId: pSiteToInstall.attr('siteid')
    });

    jQuery.post(ajaxurl, data, function (pType, pUrl, pActivatePlugin, pOverwrite, pSiteToInstall)
    {
        return function (response)
        {
            pSiteToInstall.attr('status', 'done');

            pSiteToInstall.find('.progress').hide();
            var statusEl = pSiteToInstall.find('.status');
            statusEl.show();

            if (response.error != undefined)
            {
                statusEl.html(response.error);
                statusEl.css('color', 'red');
            }
            else if ((response.ok != undefined) && (response.ok[pSiteToInstall.attr('siteid')] != undefined))
            {
                statusEl.html('<span style="color: #0073aa;"><i class="fa fa-check-circle"></i> '+__('Installation successful!')+'</span>');
                countRealItemsUpdated++;
            }
            else if ((response.errors != undefined) && (response.errors[pSiteToInstall.attr('siteid')] != undefined))
            {
                statusEl.html('<i class="fa fa-exclamation-circle"></i> '+__('Installation failed!')+': ' + response.errors[pSiteToInstall.attr('siteid')][1]);
                statusEl.css('color', 'red');
            }
            else
            {
                statusEl.html('<i class="fa fa-exclamation-circle"></i> '+__('Installation failed!'));
                statusEl.css('color', 'red');
            }

            bulkInstallCurrentThreads--;
            bulkInstallDone++;

            mainwp_install_bulk_start_next(pType, pUrl, pActivatePlugin, pOverwrite);
        }
    }(pType, pUrl, pActivatePlugin, pOverwrite, pSiteToInstall), 'json');
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

mainwp_upload_bulk = function (type) {
    if (type == 'plugins') {
        type = 'plugin';
    }
    else {
        type = 'theme';
    }

//    if (typeof(mainwp_onPrepareBulkUploadPluginTheme) == 'function') {
//        mainwp_onPrepareBulkUploadPluginTheme(type, path = 'bulk');
//    }
    var files = [];
    jQuery(".qq-upload-file").each(function (i) {
        if (jQuery(this).parent().attr('class').replace(/^\s+|\s+$/g, "") == 'qq-upload-success') {
            files.push(jQuery(this).attr('filename'));
        }
    });
    if (files.length == 0) {
        if (type == 'plugin')
            show_error('ajax-error-zone', __('Please upload plugins to install.'));
        else
            show_error('ajax-error-zone', __('Please upload themes to install.'));
        return;
    }

    var data = {
        action:'mainwp_preparebulkuploadplugintheme',
        type:type,
        selected_by:jQuery('#select_by').val()
    };
    if (jQuery('#select_by').val() == 'site') {
        var selected_sites = [];
        jQuery("input[name='selected_sites[]']:checked").each(function (i) {
            selected_sites.push(jQuery(this).val());
        });

        if (selected_sites.length == 0) {
            show_error('ajax-error-zone', __('Please select websites or groups to install files.'));
            jQuery('#selected_sites').addClass('form-invalid');
            return;
        }
        else {
            jQuery('#selected_sites').removeClass('form-invalid');
        }
        data['selected_sites[]'] = selected_sites;
    }
    else {
        var selected_groups = [];
        jQuery("input[name='selected_groups[]']:checked").each(function (i) {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            show_error('ajax-error-zone', __('Please select websites or groups to install files.'));
            jQuery('#selected_groups').addClass('form-invalid');
            return;
        }
        else {
            jQuery('#selected_groups').removeClass('form-invalid');
        }
        data['selected_groups[]'] = selected_groups;
    }

    data['files[]'] = files;

    jQuery.post(ajaxurl, data, function(pType, pFiles, pActivatePlugin, pOverwrite) { return function (response) {
        var installQueue = '<div class="postbox"><div class="inside"><h3>Installing ' + pFiles.length + ' ' + pType + (pFiles.length > 1 ? 's' : '') + '</h3>';
        for (var siteId in response.sites)
        {
            var site = response.sites[siteId];
            installQueue += '<span class="siteBulkInstall" siteid="' + siteId + '" status="queue"><strong>' + site['name'].replace(/\\(.)/mg, "$1") + '</strong>: <span class="queue"><i class="fa fa-clock-o"></i> '+__('Queued')+'</span><span class="progress"><i class="fa fa-spinner fa-pulse"></i> '+__('Installing...')+'</span><span class="status"></span></span><br />';
        }
        installQueue += '<div id="bulk_upload_info" number-files="' + pFiles.length + '"></div>';
        installQueue += '</div></div>';

        jQuery('#mainwp_wrap-inside').html(installQueue);
        mainwp_upload_bulk_start_next(pType, response.urls, pActivatePlugin, pOverwrite);
    } }(type, files, jQuery('#chk_activate_plugin').is(':checked'), jQuery('#chk_overwrite').is(':checked')), 'json');

    jQuery('#mainwp_wrap-inside').html('<div class="postbox"><div class="inside"><h3><i class="fa fa-spinner fa-pulse"></i> '+__('Preparing %1 installation...', type) + '</h3></div></div>');
    return false;
};

mainwp_upload_bulk_start_next = function(pType, pUrls, pActivatePlugin, pOverwrite)
{
    while ((siteToInstall = jQuery('.siteBulkInstall[status="queue"]:first')) && (siteToInstall.length > 0)  && (bulkInstallCurrentThreads < bulkInstallMaxThreads))
    {
        mainwp_upload_bulk_start_specific(pType, pUrls, pActivatePlugin, pOverwrite, siteToInstall);
    }

    if ((siteToInstall.length == 0) && (bulkInstallCurrentThreads == 0))
    {
        var data = mainwp_secure_data({
            action:'mainwp_cleanbulkuploadplugintheme'
        });
        jQuery.post(ajaxurl, data, function (resp)
        {
        });
        var msg = mainwp_install_bulk_you_know_msg(pType, jQuery('#bulk_upload_info').attr('number-files'));
        jQuery('#bulk_upload_info').html('<div class="mainwp-notice mainwp-notice-blue">' + msg + '</div>');
    }
};

mainwp_upload_bulk_start_specific = function (pType, pUrls, pActivatePlugin, pOverwrite, pSiteToInstall)
{
    bulkInstallCurrentThreads++;
    pSiteToInstall.attr('status', 'progress');

    pSiteToInstall.find('.queue').hide();
    pSiteToInstall.find('.progress').show();

    var data = mainwp_secure_data({
        action:'mainwp_installbulkuploadplugintheme',
        type:pType,
        urls: pUrls,
        activatePlugin: pActivatePlugin,
        overwrite: pOverwrite,
        siteId: pSiteToInstall.attr('siteid')
    });

    jQuery.post(ajaxurl, data, function (pType, pUrls, pActivatePlugin, pOverwrite, pSiteToInstall)
    {
        return function (response)
        {
            pSiteToInstall.attr('status', 'done');

            pSiteToInstall.find('.progress').hide();
            var statusEl = pSiteToInstall.find('.status');
            statusEl.show();

            if (response.error != undefined)
            {
                statusEl.html(response.error);
                statusEl.css('color', 'red');
            }
            else if ((response.ok != undefined) && (response.ok[pSiteToInstall.attr('siteid')] != undefined))
            {
                statusEl.html('<span style="color: #0073aa;"><i class="fa fa-check-circle"></i>  '+__('Installation successful!')+'</span>');
            }
            else if ((response.errors != undefined) && (response.errors[pSiteToInstall.attr('siteid')] != undefined))
            {
                statusEl.html('<i class="fa fa-exclamation-circle"></i>'+__('Installation failed!')+': ' + response.errors[pSiteToInstall.attr('siteid')][1]);
                statusEl.css('color', 'red');
            }
            else
            {
                statusEl.html('<i class="fa fa-exclamation-circle"></i>'+__('Installation failed!'));
                statusEl.css('color', 'red');
            }

            bulkInstallCurrentThreads--;
            mainwp_upload_bulk_start_next(pType, pUrls, pActivatePlugin, pOverwrite);
        }
    }(pType, pUrls, pActivatePlugin, pOverwrite, pSiteToInstall), 'json');
};

/**
 * Backup
 */
var backupDownloadRunning = false;
var backupError = false;
var backupContinueRetries = 0;
var backupContinueRetriesUnique = [];

jQuery(document).ready(function () {
    jQuery('#backup_btnSubmit').live('click', function () {
        backup();
    });
    jQuery('#managesite-backup-status-close').live('click', function(event)
    {
        backupDownloadRunning = false;
        jQuery('#managesite-backup-status-box').dialog('destroy');
        location.reload();
    });

});
backup = function ()
{
    backupError = false;
    backupContinueRetries = 0;

    jQuery('#backup_loading').show();
    var remote_destinations = jQuery('#backup_location_remote').hasClass('mainwp_action_down') ? jQuery.map(jQuery('#backup_destination_list').find('input[name="remote_destinations[]"]'), function(el) { return {id: jQuery(el).val(), title: jQuery(el).attr('title'), type: jQuery(el).attr('destination_type')}; }) : [];

    var type = (jQuery('#backup_type_full').hasClass('mainwp_action_down') ? 'full' : 'db');
    var size = jQuery('#backup_site_' + type + '_size').val();
    if (type == 'full')
    {
        size = size * 1024 * 1024 / 2.4; //Guessing how large the zip will be
    }
    var fileName = jQuery('#backup_filename').val();
    var fileNameUID = mainwp_uid();
    var loadFilesBeforeZip = jQuery('[name="mainwp_options_loadFilesBeforeZip"]:checked').val();

    var backupPid = Math.round(new Date().getTime() / 1000);
    var data = mainwp_secure_data({
        action:'mainwp_backup',
        site_id:jQuery('#backup_site_id').val(),
        pid: backupPid,
        type:type,
        exclude:jQuery('#excluded_folders_list').val(),
        excludebackup: (jQuery('#mainwp-known-backup-locations').attr('checked') ? 1 : 0),
        excludecache: (jQuery('#mainwp-known-cache-locations').attr('checked') ? 1 : 0),
        excludenonwp: (jQuery('#mainwp-non-wordpress-folders').attr('checked') ? 1 : 0),
        excludezip: (jQuery('#mainwp-zip-archives').attr('checked') ? 1 : 0),
        filename: fileName,
        fileNameUID: fileNameUID,

        archiveFormat: jQuery('#mainwp_archiveFormat').val(),
        maximumFileDescriptorsOverride: jQuery('#mainwp_options_maximumFileDescriptorsOverride_override').is(':checked') ? 1 : 0,
        maximumFileDescriptorsAuto: (jQuery('#mainwp_maximumFileDescriptorsAuto').attr('checked') ? 1 : 0),
        maximumFileDescriptors: jQuery('#mainwp_options_maximumFileDescriptors').val(),
        loadFilesBeforeZip: loadFilesBeforeZip,

        subfolder:jQuery('#backup_subfolder').val()
    }, true);

    jQuery('#managesite-backup-status-text').html(dateToHMS(new Date()) + ' '+__('Creating the backup file on the child site, this might take a while depending on the size. Please be patient.') +' <div id="managesite-createbackup-status-progress" style="margin-top: 1em;"></div>');
    jQuery('#managesite-createbackup-status-progress').progressbar({value: 0, max: size});
    jQuery('#managesite-backup-status-box').dialog({
        resizable: false,
        height: 350,
        width: 500,
        modal: true,
        close: function(event, ui) { if (!backupError) { location.reload(); }}});

    var fnc = function(pSiteId, pType, pFileName, pFileNameUID) { return function(pFunction) {
        var data2 = mainwp_secure_data({
            action:'mainwp_createbackup_getfilesize',
            siteId: pSiteId,
            type: pType,
            fileName: pFileName,
            fileNameUID: pFileNameUID
        }, false);

        jQuery.ajax({
            url: ajaxurl,
            data: data2,
            method: 'POST',
            success: function(pFunc) { return function (response) {
                if (backupCreateRunning && response.error)
                {
                    setTimeout(function() { pFunc(pFunc); }, 1000);
                    return;
                }

                if (backupCreateRunning)
                {
                    var progressBar = jQuery('#managesite-createbackup-status-progress');
                    if (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max'))
                    {
                        progressBar.progressbar('value', response.size);
                    }

                    setTimeout(function() { pFunc(pFunc); }, 1000);
                }
            } }(pFunction),
            error:function(pFunc) { return function() {
                if (backupCreateRunning) { setTimeout(function() { pFunc(pFunc); }, 10000); }
            } }(pFunction),
            dataType: 'json'});
    } }(jQuery('#backup_site_id').val(), type, fileName, fileNameUID);

    setTimeout(function() { fnc(fnc); }, 1000);

    backupCreateRunning = true;
    jQuery.ajax({
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function(pSiteId, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, pData) { return function (response) {
            if (response.error || !response.result)
            {
                backup_retry_fail(pSiteId, pData, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, response.error ? response.error : '');
            }
            else
            {
                backupCreateRunning = false;

                var progressBar = jQuery('#managesite-createbackup-status-progress');
                progressBar.progressbar('value', parseFloat(progressBar.progressbar('option', 'max')));

                appendToDiv('#managesite-backup-status-text', __('Backup file on child site created successfully!'));

                backup_download_file(pSiteId, pType, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, pRemoteDestinations);
            }
        } }(jQuery('#backup_site_id').val(), remote_destinations, backupPid, type, jQuery('#backup_subfolder').val(), fileName, data),
        error: function(pSiteId, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, pData) { return function() {
            backup_retry_fail(pSiteId, pData, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename);
        } }(jQuery('#backup_site_id').val(), remote_destinations, backupPid, type, jQuery('#backup_subfolder').val(), fileName, data),
        dataType: 'json'
    });
};

backup_retry_fail = function(siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError)
{
    //we've got the pid file!!!!
    var data = mainwp_secure_data({
        action:'mainwp_backup_checkpid',
        site_id: siteId,
        pid: pid,
        type: type,
        subfolder: subfolder,
        filename: filename
    });

    jQuery.ajax({
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function(response) {
            if (response.status == 'done')
            {
                backupCreateRunning = false;

                var progressBar = jQuery('#managesite-createbackup-status-progress');
                progressBar.progressbar('value', parseFloat(progressBar.progressbar('option', 'max')));

                //download!!!
                appendToDiv('#managesite-backup-status-text', __('Backup file on child site created successfully!'));

                backup_download_file(siteId, type, response.result.file, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, remoteDestinations);
            }
            else if (response.status == 'busy')
            {
                //Try again in 5seconds
                setTimeout(function() {
                    backup_retry_fail(siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError);
                },10000);
            }
            else if (response.status == 'stalled')
            {
                backupContinueRetries++;

                if (backupContinueRetries > 10)
                {
                    if (responseError != undefined)
                    {
                        appendToDiv('#managesite-backup-status-text', ' <span class="mainwp-red">ERROR: ' + getErrorMessage(responseError) + '</span>');
                    }
                    else
                    {
                        appendToDiv('#managesite-backup-status-text', ' <span class="mainwp-red">ERROR: Backup timed out! <a href="https://mainwp.com/help/docs/mainwp-introduction/resolving-system-requirement-issues/">Please check this help document for more information and possible fixes</a></span>');
                    }
                }
                else
                {
                    appendToDiv('#managesite-backup-status-text', ' Backup stalled, trying to resume from last file...');
                    //retrying file: response.result.file !

                    pData['filename'] = response.result.file;
                    pData['append'] = 1;
                    pData = mainwp_secure_data(pData, true); //Rescure

                    jQuery.ajax({
                        url: ajaxurl,
                        data: pData,
                        method: 'POST',
                        success: function(pSiteId, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, pData) { return function (response) {
                            if (response.error || !response.result)
                            {
                                backup_retry_fail(pSiteId, pData, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, response.error ? response.error : '');
                            }
                            else
                            {
                                backupCreateRunning = false;

                                var progressBar = jQuery('#managesite-createbackup-status-progress');
                                progressBar.progressbar('value', parseFloat(progressBar.progressbar('option', 'max')));

                                appendToDiv('#managesite-backup-status-text', __('Backupfile on child site created successfully.'));

                                backup_download_file(pSiteId, pType, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, pRemoteDestinations);
                            }
                        } }(siteId, remoteDestinations, pid, type, subfolder, filename, pData),
                        error: function(pSiteId, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename, pData) { return function() {
                            backup_retry_fail(pSiteId, pData, pRemoteDestinations, pBackupPid, pType, pSubfolder, pFilename);
                        } }(siteId, remoteDestinations, pid, type, subfolder, filename, pData),
                        dataType: 'json'
                    });
                }
            }
            else if (response.status == 'invalid')
            {
                backupCreateRunning = false;

                if (responseError != undefined)
                {
                    appendToDiv('#managesite-backup-status-text', ' <span class="mainwp-red">ERROR: ' + getErrorMessage(responseError) + '</span>');
                }
                else
                {
                    appendToDiv('#managesite-backup-status-text', ' <span class="mainwp-red">ERROR: Backup timed out! <a href="https://mainwp.com/help/docs/mainwp-introduction/resolving-system-requirement-issues/">Please check this help document for more information and possible fixes</a></span>');
                }
            }
            else
            {
                //Try again in 5seconds
                setTimeout(function() {
                    backup_retry_fail(siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError);
                },10000);
            }
        },
        error: function() {
            //Try again in 10seconds
            setTimeout(function() {
                backup_retry_fail(siteId, pData, remoteDestinations, pid, type, subfolder, filename, responseError);
            },10000);
        },
        dataType: 'json'
    });
};

backup_download_file = function(pSiteId, type, url, file, regexfile, size, subfolder, remote_destinations)
{
    appendToDiv('#managesite-backup-status-text', __('Downloading the file.')+' <div id="managesite-backup-status-progress" style="margin-top: 1em;"></div>');
    jQuery('#managesite-backup-status-progress').progressbar({value: 0, max: size});

    var fnc = function(pFile) { return function(pFunction) {
        var data = mainwp_secure_data({
            action:'mainwp_backup_getfilesize',
            local: file
        });
        jQuery.ajax({
            url: ajaxurl,
            data: data,
            method: 'POST',
            success: function(pFunc) { return function (response) {
                if (backupCreateRunning && response.error)
                {
                    setTimeout(function() { pFunc(pFunc); }, 5000);
                    return;
                }

                if (backupDownloadRunning)
                {
                    var progressBar = jQuery('#managesite-backup-status-progress');
                    if (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max'))
                    {
                        progressBar.progressbar('value', response.result);
                    }

                    setTimeout(function() { pFunc(pFunc); }, 1000);
                }
            } }(pFunction),
            error:function(pFunc) { return function() {
                if (backupCreateRunning) { setTimeout(function() { pFunc(pFunc); }, 10000); }
            } }(pFunction),
            dataType: 'json'
        });
    } }(file);

    setTimeout(function() { fnc(fnc); }, 1000);

    var data = mainwp_secure_data({
        action:'mainwp_backup_download_file',
        site_id: pSiteId,
        type: type,
        url: url,
        local: file
    });
    backupDownloadRunning = true;

    jQuery.ajax({
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function(pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pSize, pType, pUrl, pData) { return function (response) {
            backupDownloadRunning = false;

            if (response.error)
            {
                appendToDiv('#managesite-backup-status-text', '<span class="mainwp-red">ERROR: '+ getErrorMessage(response.error)+ '</span>');
                appendToDiv('#managesite-backup-status-text', '<span class="mainwp-red">'+__('Backup failed!') + '</span>');

                jQuery('#managesite-backup-status-close').prop('value', 'Close');
                return;
            }

            jQuery('#managesite-backup-status-progress').progressbar();
            jQuery('#managesite-backup-status-progress').progressbar('value', pSize);
            appendToDiv('#managesite-backup-status-text', __('Download from child site completed.'));

            var newData = mainwp_secure_data({
                action:'mainwp_backup_delete_file',
                site_id: pSiteId,
                file: pUrl
            });
            jQuery.post(ajaxurl, newData, function() {}, 'json');
            backup_upload_file(pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize);
        } }(pSiteId, file, regexfile, subfolder, remote_destinations, size, type, url, data),
        error:function(pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pSize, pType, pUrl, pData) { return function() {
            //Try again in 10seconds
            /*setTimeout(function() {
             download_retry_fail(pSiteId, pData, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pSize, pType, pUrl);
             },10000);*/
        } }(pSiteId, file, regexfile, subfolder, remote_destinations, size, type, url, data),
        dataType: 'json'});
};

var backupUploadRunning = [];
backup_upload_file = function(pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize)
{
    if (pRemoteDestinations.length > 0)
    {
        var remote_destination = pRemoteDestinations[0];
        //upload..
        var unique = Date.now();
        appendToDiv('#managesite-backup-status-text', __('Uploading to remote destination: %1 (%2)', remote_destination.title, remote_destination.type) + '<div id="managesite-upload-status-progress-' + unique + '"  style="margin-top: 1em;"></div>');

        jQuery('#managesite-upload-status-progress-'+unique).progressbar({value: 0, max: pSize});

        var fnc = function(pUnique) { return function(pFunction) {
            var data2 = mainwp_secure_data({
                action:'mainwp_backup_upload_getprogress',
                unique: pUnique
            }, false);

            jQuery.ajax({
                url: ajaxurl,
                data: data2,
                method: 'POST',
                success: function(pFunc) { return function (response) {
                    if (backupUploadRunning[pUnique] && response.error)
                    {
                        setTimeout(function() { pFunc(pFunc); }, 1000);
                        return;
                    }

                    if (backupUploadRunning[pUnique])
                    {
                        var progressBar = jQuery('#managesite-upload-status-progress-'+pUnique);
                        if ((progressBar.length > 0) && (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max')) && (progressBar.progressbar('option', 'value') < parseInt(response.result)))
                        {
                            progressBar.progressbar('value', response.result);
                        }

                        setTimeout(function() { pFunc(pFunc); }, 1000);
                    }
                } }(pFunction),
                error:function(pFunc) { return function() {
                    if (backupUploadRunning[pUnique]) { setTimeout(function() { pFunc(pFunc); }, 10000); }
                } }(pFunction),
                dataType: 'json'});
        } }(unique);

        setTimeout(function() { fnc(fnc); }, 1000);

        backupUploadRunning[unique] = true;

        var data = mainwp_secure_data({
            action:'mainwp_backup_upload_file',
            file: pFile,
            siteId: pSiteId,
            regexfile: pRegexFile,
            subfolder: pSubfolder,
            type: pType,
            remote_destination: remote_destination.id,
            unique: unique
        });

        pRemoteDestinations.shift();
        jQuery.ajax({
            type: 'POST',
            url: ajaxurl,
            data: data,
            success: function(pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pUnique, pRemoteDestId) { return function (response) {
                if (!response || response.error || !response.result)
                {
                    backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, response && response.error ? response.error : '');
                }
                else
                {
                    backupUploadRunning[pUnique] = false;

                    var progressBar = jQuery('#managesite-upload-status-progress-'+pUnique);
                    progressBar.progressbar();
                    progressBar.progressbar('value', pSize);

                    var obj = response.result;
                    if (obj.error)
                    {
                        backupError = true;
                        appendToDiv('#managesite-backup-status-text', '<span class="mainwp-red">' + __('Upload to %1 (%2) failed:', obj.title, obj.type)+' ' + obj.error + '</span>');
                    }
                    else
                    {
                        appendToDiv('#managesite-backup-status-text', __('Upload to %1 (%2) successful!', obj.title, obj.type));
                    }

                    backup_upload_file(pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
                }
            } }(pSiteId, pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, data, unique,  remote_destination.id),
            error: function(pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pUnique, pRemoteDestId) { return function (response) {
                backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId);
            } }(pSiteId, pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, data, unique,  remote_destination.id),
            dataType: 'json'
        });
    }
    else
    {
        appendToDiv('#managesite-backup-status-text', __('Backup completed!'));
        jQuery('#managesite-backup-status-close').prop('value', 'Close');
        if (!backupError)
        {
            setTimeout(function() {
                jQuery('#managesite-backup-status-box').dialog('destroy');
                location.reload();
            }, 3000);
        }
    }
};

backup_upload_file_retry_fail = function(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError)
{
    //we've got the pid file!!!!
    var data = mainwp_secure_data({
        action:'mainwp_backup_upload_checkstatus',
        unique: pUnique,
        remote_destination: pRemoteDestId
    });

    jQuery.ajax({
        url: ajaxurl,
        data: data,
        method: 'POST',
        success: function(response) {
            if (response.status == 'done')
            {
                backupUploadRunning[pUnique] = false;

                var progressBar = jQuery('#managesite-upload-status-progress-'+pUnique);
                progressBar.progressbar();
                progressBar.progressbar('value', pSize);

                appendToDiv('#managesite-backup-status-text', __('Upload to %1 (%2) successful!', response.info.title, response.info.type));

                backup_upload_file(pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
            }
            else if (response.status == 'busy')
            {
                //Try again in 10seconds
                setTimeout(function() {
                    backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
                },10000);
            }
            else if (response.status == 'stalled')
            {
                if (backupContinueRetriesUnique[pUnique] == undefined)
                {
                    backupContinueRetriesUnique[pUnique] = 1;
                }
                else
                {
                    backupContinueRetriesUnique[pUnique]++;
                }

                if (backupContinueRetriesUnique[pUnique] > 10)
                {
                    if (responseError != undefined)
                    {
                        backupError = true;
                        appendToDiv('#managesite-backup-status-text', '<span class="mainwp-red">' + __('Upload to %1 (%2) failed:', response.info.title, response.info.type)+' ' + responseError + '</span>');
                    }
                    else
                    {
                        appendToDiv('#managesite-backup-status-text', ' <span class="mainwp-red">ERROR: Upload timed out! <a href="http://docs.mainwp.com/backup-failed-php-ini-settings/">Please check this help document for more information and possible fixes</a></span>');
                    }

                    backup_upload_file(pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
                }
                else
                {
                    appendToDiv('#managesite-backup-status-text', ' Upload stalled, trying to resume from last position...');

                    pData = mainwp_secure_data(pData); //Rescure

                    jQuery.ajax({
                        url: ajaxurl,
                        data: pData,
                        method: 'POST',
                        success: function(pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pRemoteDestId) { return function (response) {
                            if (response.error || !response.result)
                            {
                                backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, response.error ? response.error : '');
                            }
                            else
                            {
                                backupUploadRunning[pUnique] = false;

                                var progressBar = jQuery('#managesite-upload-status-progress-'+pUnique);
                                progressBar.progressbar();
                                progressBar.progressbar('value', pSize);

                                var obj = response.result;
                                if (obj.error)
                                {
                                    backupError = true;
                                    appendToDiv('#managesite-backup-status-text', '<span class="mainwp-red">' + __('Upload to %1 (%2) failed:', obj.title, obj.type)+' ' + obj.error + '</span>');
                                }
                                else
                                {
                                    appendToDiv('#managesite-backup-status-text', __('Upload to %1 (%2) successful!', obj.title, obj.type));
                                }

                                backup_upload_file(pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
                            }
                        } }(pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pRemoteDestId),
                        error: function(pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pRemoteDestId) { return function (response) {
                            backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId);
                        } }(pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pData, pRemoteDestId),
                        dataType: 'json'
                    });
                }
            }
            else
            {
                //Try again in 5seconds
                setTimeout(function() {
                    backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
                },10000);
            }
        },
        error: function() {
            //Try again in 10seconds
            setTimeout(function() {
                backup_upload_file_retry_fail(pData, pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize, pUnique, pRemoteDestId, responseError);
            },10000);
        },
        dataType: 'json'
    });
};


jQuery(document).on('click', '#mainwp_clone_enabled', function() {
    if (jQuery(this).is(':checked'))
    {
        jQuery('#mainwp_clone_disabled_text').hide();
        jQuery('#mainwp_clone_enabled_text').show();
    }
    else
    {
        jQuery('#mainwp_clone_enabled_text').hide();
        jQuery('#mainwp_clone_disabled_text').show();
    }
});
jQuery(document).on('click', '.mainwp_displayby_sitename', function() {
    return cloneShowByName(jQuery(this));
});

jQuery(document).on('click', '.mainwp_displayby_url', function() {
    return cloneShowByUrl(jQuery(this));
});
cloneShowByUrl = function(pElement)
{
    var parentElement = pElement.parent().parent();
    parentElement.find('.mainwp_name_label').hide();
    parentElement.find('.mainwp_url_label').show();
    parentElement.find('.mainwp_displayby_sitename').removeClass('mainwp_action_down');
    pElement.addClass('mainwp_action_down');
    return false;
}
cloneShowByName = function(pElement)
{
    var parentElement = pElement.parent().parent();
    parentElement.find('.mainwp_url_label').hide();
    parentElement.find('.mainwp_name_label').show();
    parentElement.find('.mainwp_displayby_url').removeClass('mainwp_action_down');
    pElement.addClass('mainwp_action_down');
    return false;
}
jQuery(document).on('click', '.clonesite_sites_item', function() {
    if (jQuery(this).hasClass('selected'))
    {
        jQuery(this).removeClass('selected');
    }
    else
    {
        jQuery(this).addClass('selected');
    }
});

jQuery(document).on('click', '#mainwp_clone_disallow', function() {
    var selectedSites = jQuery('.mainwp_clonesite_sites_container.allowed .clonesite_sites_item.selected');
    if (selectedSites.length == 0) return false;
    selectedSites.removeClass('selected');
    var disallowedContainer = jQuery('.mainwp_clonesite_sites_container.disallowed');
    disallowedContainer.append(selectedSites);

    var showByNameEl = disallowedContainer.parent().find('.mainwp_displayby_sitename');
    if (showByNameEl.hasClass('mainwp_action_down'))
    {
        cloneShowByName(showByNameEl);
    }
    else
    {
        cloneShowByUrl(disallowedContainer.parent().find('.mainwp_displayby_url'))
    }
    return false;
});
jQuery(document).on('click', '#mainwp_clone_allow', function() {
    var selectedSites = jQuery('.mainwp_clonesite_sites_container.disallowed .clonesite_sites_item.selected');
    if (selectedSites.length == 0) return false;
    selectedSites.removeClass('selected');
    var allowedContainer = jQuery('.mainwp_clonesite_sites_container.allowed');
    allowedContainer.append(selectedSites);

    var showByNameEl = allowedContainer.parent().find('.mainwp_displayby_sitename');
    if (showByNameEl.hasClass('mainwp_action_down'))
    {
        cloneShowByName(showByNameEl);
    }
    else
    {
        cloneShowByUrl(allowedContainer.parent().find('.mainwp_displayby_url'))
    }
    return false;
});

jQuery(document).on('click', '.clone-saveAll', function ()
{
    var disallowedSites = jQuery('.mainwp_clonesite_sites_container.disallowed .clonesite_sites_item');
    var disallowedSiteIds = [];
    for (var i = 0; i < disallowedSites.length; i++)
    {
        disallowedSiteIds.push(jQuery(disallowedSites[i]).attr('id'));
    }
    var data = {
        action:'mainwp_clone_updatesettings',
        cloneEnabled: (jQuery('#mainwp_clone_enabled').is(':checked') ? 1 : 0),
        websiteIds:disallowedSiteIds
    };
    jQuery.post(ajaxurl, data, function (response)
    {
        jQuery('#clone-saved').stop(true, true);
        jQuery('#clone-saved').show();
        jQuery('#clone-saved').fadeOut(2000);
        return;
    }, 'json');
    return false;
});

/**
 * Utility
 */
function isUrl(s) {
    var regexp = /(ftp|http|https):\/\/(\w+:{0,1}\w*@)?(\S+)(:[0-9]+)?(\/|\/([\w#!:.?+=&%@!\-\/]))?/;
    return regexp.test(s);
}
function setVisible(what, vis) {
    if (vis) {
        jQuery(what).show();
    }
    else {
        jQuery(what).hide();
    }
}
function setHtml(what, text, ptag) {
    if (typeof ptag == "undefined")
        ptag = true;

    setVisible(what, true);
    if (ptag)
        jQuery(what).html('<span>' + text + '</span>');
    else
        jQuery(what).html(text);
    scrollToElement(what);
}
function getUrlParameters() {
    var map = {};
    var parts = window.location.href.replace(/[?&]+([^=&]+)=([^&]*)/gi, function (m, key, value) {
        map[key] = value;
    });
    return map;
}

/**
 * MainWP_Site_Open.page
 */
jQuery(document).ready(function () {
    jQuery('#mainwp_notes_show').live('click', function () {
        mainwp_notes_show();
        return false;
    });
    jQuery('#mainwp_notes_cancel').live('click', function () {
        mainwp_notes_hide();
        return false;
    });
    jQuery('#mainwp_notes_closeX').live('click', function () {
        mainwp_notes_hide();
        return false;
    });
    jQuery('#mainwp_notes_save').live('click', function () {
        mainwp_notes_save();
        return false;
    });
    jQuery('.mainwp_notes_show_all').live('click', function () {
        mainwp_notes_show_all(jQuery(this).attr('id').substr(13));
        return false;
    });

    jQuery('#mainwp_notes_edit').live('click', function () {
        jQuery('#mainwp_notes').addClass('edit-mode');
        return false;
    });
    jQuery('#mainwp_notes_view').live('click', function () {
        jQuery('#mainwp_notes_html').html(jQuery('#mainwp_notes_note').val());
        jQuery('#mainwp_notes').removeClass('edit-mode');
        return false;
    });
    jQuery('#redirectForm').submit();
});

mainwp_notes_show_all = function (id) {
    jQuery('#mainwp_notes').removeClass('edit-mode');
    var url = jQuery('#mainwp_notes_' + id + '_url').html();
    var note = jQuery('#mainwp_notes_' + id + '_note').html();
    jQuery('#mainwp_notes_title').html(url);
    jQuery('#mainwp_notes_html').html( note == '' ? 'No Saved Notes' : note );
    jQuery('#mainwp_notes_note').val(note);
    jQuery('#mainwp_notes_websiteid').val(id);
    mainwp_notes_show();
};


mainwp_notes_show = function () {
    jQuery('#mainwp_notes_status').html('');
    jQuery('#mainwp_notes_overlay').height(jQuery(document).height());
    jQuery('#mainwp_notes_overlay').show();
    jQuery('#mainwp_notes').show();
};
mainwp_notes_hide = function () {
    jQuery('#mainwp_notes').hide();
    jQuery('#mainwp_notes_overlay').hide();
    jQuery('#mainwp_notes_status').html('');
};
mainwp_notes_save = function () {
    var normalid = jQuery('#mainwp_notes_websiteid').val();
    var newnote = jQuery('#mainwp_notes_note').val();
    var data = mainwp_secure_data({
        action:'mainwp_notes_save',
        websiteid:jQuery('#mainwp_notes_websiteid').val(),
        note:newnote
    });
    jQuery('#mainwp_notes_status').html('<i class="fa fa-spinner fa-pulse"></i> '+__('Please wait while we are saving your note...'));
    jQuery.post(ajaxurl, data, function (response) {
        if (response.error != undefined)
        {
            jQuery('#mainwp_notes_status').html(response.error);
        }
        else if (response.result == 'SUCCESS') {
            jQuery('#mainwp_notes_status').html(__('Note saved.'));
            if (jQuery('#mainwp_notes_' + normalid + '_note')) {
                jQuery('#mainwp_notes_' + normalid + '_note').html(jQuery('#mainwp_notes_note').val());
            }
            if (newnote == '') {
                jQuery('#mainwp_notes_img_' + normalid).hide();
            }
            else {
                jQuery('#mainwp_notes_img_' + normalid).show();
            }
        }
        else
        {
            jQuery('#mainwp_notes_status').html(__('Undefined error occured while saving your note!') + '.');
        }
    }, 'json');
};

/**
 * MainWP_Page.page
 */
jQuery(document).ready(function () {
    jQuery('.mainwp_datepicker').datepicker({dateFormat:"yy-mm-dd"});
    jQuery('#mainwp_show_pages').live('click', function () {
        mainwp_fetch_pages();
    });
    jQuery('.page_submitdelete').live('click', function () {
        mainwppage_postAction(jQuery(this), 'trash');
        return false;
    });
    jQuery('.page_submitdelete_perm').live('click', function () {
        mainwppage_postAction(jQuery(this), 'delete');
        return false;
    });
    jQuery('.page_submitrestore').live('click', function () {
        mainwppage_postAction(jQuery(this), 'restore');
        return false;
    });
    jQuery('#mainwp_bulk_page_action_apply').live('click', function () {
        var action = jQuery('#mainwp_bulk_action').val();
        if (action == 'none') return false;

        var tmp = jQuery("input[name='page[]']:checked");
        countSent = tmp.length;

        if (countSent == 0)
            return false;

        if (action == 'delete') {
            if (!confirm(__('You are about to delete %1 page(s). Are you sure you want to proceed?', countSent))) {
                return false;
            }
        }

        jQuery('#mainwp_bulk_page_action_apply').attr('disabled', 'true');

        tmp.each(
            function (index, elem) {
                mainwppage_postAction(elem, action);
            }
        );

        return false;
    });
});


mainwppage_postAction = function (elem, what) {
    var rowElement = jQuery(elem).parents('tr');
    var pageId = rowElement.find('.pageId').val();
    var websiteId = rowElement.find('.websiteId').val();

    if (rowElement.find('.allowedBulkActions').val().indexOf('|'+what+'|') == -1)
    {
        jQuery(elem).removeAttr('checked');
        countReceived++;

        if (countReceived == countSent) {
            countReceived = 0;
            countSent = 0;
            setTimeout(function() { jQuery('#mainwp_bulk_page_action_apply').removeAttr('disabled'); }, 50);
        }

        return;
    }

    var data = mainwp_secure_data({
        action:'mainwp_page_' + what,
        postId:pageId,
        websiteId:websiteId
    });
    rowElement.find('.row-actions').hide();
    rowElement.find('.row-actions-working').show();
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            rowElement.html('<td colspan="7"><i class="fa fa-check-circle"></i> ' + response.result + '</td>');
        }
        else {
            rowElement.find('.row-actions-working').hide();
        }
        countReceived++;

        if (countReceived == countSent) {
            countReceived = 0;
            countSent = 0;
            jQuery('#mainwp_bulk_page_action_apply').removeAttr('disabled');
        }
    }, 'json');

    return false;
};

mainwp_pages_table_reinit = function () {
    if (jQuery('#mainwp_pages_table').hasClass('tablesorter-default'))
    {
        jQuery('#mainwp_pages_table').trigger("updateAll").trigger('destroy.pager').tablesorterPager({container:jQuery("#pager")});
    }
    else
    {
        jQuery('#mainwp_pages_table').tablesorter({
            cssAsc:"desc",
            cssDesc:"asc",
            textExtraction:function (node) {
                if (jQuery(node).find('abbr').length == 0) {
                    return node.innerHTML
                } else {
                    var raw = jQuery(node).find('abbr')[0].raw_value;
                    if (typeof raw !== typeof undefined && raw !== false) {
                        return raw;
                    }
                    return jQuery(node).find('abbr')[0].title;
                }
            },
            selectorHeaders: "> thead th:not(:first), > thead td:not(:first), > tfoot th:not(:first), > tfoot td:not(:first)"
        }).tablesorterPager({container:jQuery("#pager")});
    }
};
mainwp_fetch_pages = function () {
    var errors = [];
    var selected_sites = [];
    var selected_groups = [];

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function (i) {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push('<div class="mainwp-notice mainwp-notice-red">'+__('Please select websites or groups.')+'</div>');
            jQuery('#selected_sites').addClass('form-invalid');
        }
        else {
            jQuery('#selected_sites').removeClass('form-invalid');
        }
    }
    else {
        jQuery("input[name='selected_groups[]']:checked").each(function (i) {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push('<div class="mainwp-notice mainwp-notice-red">'+__('Please select websites or groups.')+'</div>');
            jQuery('#selected_groups').addClass('form-invalid');
        }
        else {
            jQuery('#selected_groups').removeClass('form-invalid');
        }
    }

    var status = "";
    var statuses = ['publish', 'pending', 'private', 'future', 'draft', 'trash'];
    for (var i = 0; i < statuses.length; i++) {
        if (jQuery('#mainwp_page_search_type_' + statuses[i]).attr('checked')) {
            if (status != "") status += ",";
            status += statuses[i];
        }
    }
    if (status == "") {
        errors.push('Please select a page status.');
    }

    if (errors.length > 0) {
        jQuery('#mainwp_pages_error').html(errors.join('<br />'));
        jQuery('#mainwp_pages_error').show();
        return;
    }
    else {
        jQuery('#mainwp_pages_error').html("");
        jQuery('#mainwp_pages_error').hide();
    }

    var data = {
        action:'mainwp_pages_search',
        keyword:jQuery('#mainwp_page_search_by_keyword').val(),
        dtsstart:jQuery('#mainwp_page_search_by_dtsstart').val(),
        dtsstop:jQuery('#mainwp_page_search_by_dtsstop').val(),
        status:status,
        'groups[]':selected_groups,
        'sites[]':selected_sites,
        maximum: jQuery("#mainwp_maximumPages").val()
    };

    jQuery('#mainwp_pages_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_pages_loading').hide();
        jQuery('#mainwp_pages_main').show();
        var matches = (response == null ? null : response.match(/page\[\]/g));
        jQuery('#mainwp_pages_total').html(matches == null ? 0 : matches.length);
        jQuery('#mainwp_pages_wrap_table').html(response);
        mainwp_table_sort_draggable_init('page', 'mainwp_pages_table'); // pagesColOrder not work
        mainwp_pages_table_reinit();
    });
};

/**
 * MainWP_Plugins.page
 */
jQuery(document).ready(function () {
    jQuery('#mainwp_show_plugins').live('click', function () {
        mainwp_fetch_plugins();
    });
    jQuery('#mainwp_show_all_themes').live('click', function () {
        mainwp_fetch_all_themes();
        return false;
    });
    jQuery('#mainwp_show_all_active_plugins').live('click', function () {
        mainwp_fetch_all_active_plugins();
        return false;
    });
    jQuery('.mainwp_plugin_check_all').live('change', function () {
        jQuery(".selected_plugin[value='"+jQuery(this).val()+"']").prop('checked', jQuery(this).prop('checked'));
    });
    jQuery('.mainwp_site_check_all').live('change', function () {
        var rowElement = jQuery(this).parents('tr');
        rowElement.find('.selected_plugin').prop('checked', jQuery(this).prop('checked'));

        if (jQuery('#mainwp_bulk_theme_action_apply'))
        {
            if (jQuery('#mainwp_bulk_action').val() == 'activate') return;

            rowElement.find('.selected_theme').prop('checked', jQuery(this).prop('checked'));
        }
    });

    var pluginCountSent;
    var pluginCountReceived;
    var pluginResetAllowed = true;
    jQuery('#mainwp_bulk_plugins_action_apply').live('click', function () {
        var action = jQuery('#mainwp_bulk_action').val();
        if (action == 'none') return;

        jQuery('#mainwp_bulk_plugins_action_apply').attr('disabled', 'true');
        jQuery('#mainwp_bulk_action_loading').show();
        pluginResetAllowed = false;
        pluginCountSent = 0;
        pluginCountReceived = 0;

        //Find all checked boxes
        jQuery('.websiteId').each(function () {
            var websiteId = jQuery(this).val();
            var rowElement = jQuery(this).parents('tr');

            var selectedPlugins = rowElement.find('.selected_plugin:checked');
            if (selectedPlugins.length == 0) return;

            if ((action == 'activate') || (action == 'delete') || (action == 'deactivate') || (action == 'ignore_updates')) {
                var pluginsToSend = [];
                var namesToSend = [];
                for (var i = 0; i < selectedPlugins.length; i++) {
                    pluginsToSend.push(jQuery(selectedPlugins[i]).val());
                    namesToSend.push(jQuery(selectedPlugins[i]).attr('name'));
                }

                var data = mainwp_secure_data({
                    action:'mainwp_plugin_'+action,
                    plugins:pluginsToSend,
                    websiteId:websiteId
                });

                if (action == 'ignore_updates') {
                    data['names'] = namesToSend;
                }

                pluginCountSent++;
                jQuery.post(ajaxurl, data, function (response) {
                    pluginCountReceived++;

                    if (pluginResetAllowed && pluginCountReceived == pluginCountSent) {
                        pluginCountReceived = 0;
                        pluginCountSent = 0;
                        jQuery('#mainwp_bulk_action_loading').hide();
                        jQuery('#mainwp_plugins_loading_info').show();
                        mainwp_fetch_plugins();
                    }
                }, 'json');
            }
        });

        pluginResetAllowed = true;
        if (pluginCountReceived == pluginCountSent) {
            pluginCountReceived = 0;
            pluginCountSent = 0;
            jQuery('#mainwp_bulk_action_loading').hide();
            jQuery('#mainwp_plugins_loading_info').show();
            mainwp_fetch_plugins();
        }
    });


    jQuery(document).on('click', '.mainwp_trusted_plugin_notes_show', function () {
        var rowEl = jQuery(jQuery(this).parents('tr')[0]);
        var slug = rowEl.attr('plugin_slug');
        var name = rowEl.attr('plugin_name');
        var note = rowEl.find('.note').html();
        jQuery('#mainwp_notes').removeClass('edit-mode');
        jQuery('#mainwp_notes_title').html(decodeURIComponent(name));
        jQuery('#mainwp_notes_html').html(note == '' ? 'No Saved Notes' : note);
        jQuery('#mainwp_notes_note').val(note);
        jQuery('#mainwp_notes_slug').val(slug);
        mainwp_notes_show();
    });

    jQuery(document).on('click', '#mainwp_trusted_plugin_notes_save', function () {
        var slug = jQuery('#mainwp_notes_slug').val();
        var newnote = jQuery('#mainwp_notes_note').val();
        var data = mainwp_secure_data({
            action:'mainwp_trusted_plugin_notes_save',
            slug:slug,
            note:newnote
        });
        jQuery('#mainwp_notes_status').html('<i class="fa fa-spinner fa-pulse"></i> '+__('Please wait while we are saving your note...'));
        jQuery.post(ajaxurl, data, function(pSlug) { return function (response) {
            var rowEl = jQuery('tr[plugin_slug="'+pSlug+'"]');
            if (response.result == 'SUCCESS') {
                jQuery('#mainwp_notes_status').html('<i class="fa fa-check-circle"></i> '+__('Note saved!'));
                rowEl.find('.note').html(jQuery('#mainwp_notes_note').val());

                if (newnote == '') {
                    rowEl.find('.mainwp_notes_img').hide();
                }
                else {
                    rowEl.find('.mainwp_notes_img').show();
                }
            }
            else if (response.error != undefined) {
                jQuery('#mainwp_notes_status').html(__('Undefined error occured while saving your note!') + ': ' + response.error);
            }
            else {
                jQuery('#mainwp_notes_status').html(__('Undefined error occured while saving your note!') + '.');
            }
        } }(slug), 'json');
        return false;
    });

    jQuery(document).on('click', '.mainwp_trusted_theme_notes_show', function () {
        var rowEl = jQuery(jQuery(this).parents('tr')[0]);
        var slug = rowEl.attr('theme_slug');
        var name = rowEl.attr('theme_name');
        var note = rowEl.find('.note').html();
        jQuery('#mainwp_notes').removeClass('edit-mode');
        jQuery('#mainwp_notes_title').html(decodeURIComponent(name));
        jQuery('#mainwp_notes_html').html(note == '' ? 'No Saved Notes' : note);
        jQuery('#mainwp_notes_note').val(note);
        jQuery('#mainwp_notes_slug').val(slug);
        mainwp_notes_show();
    });

    jQuery(document).on('click', '#mainwp_trusted_theme_notes_save', function () {
        var slug = jQuery('#mainwp_notes_slug').val();
        var newnote = jQuery('#mainwp_notes_note').val();
        var data = mainwp_secure_data({
            action:'mainwp_trusted_theme_notes_save',
            slug:slug,
            note:newnote
        });
        jQuery('#mainwp_notes_status').html('<i class="fa fa-spinner fa-pulse"></i> '+__('Please wait while we are saving your note...'));
        jQuery.post(ajaxurl, data, function(pSlug) { return function (response) {
            var rowEl = jQuery('tr[theme_slug="'+pSlug+'"]');
            if (response.result == 'SUCCESS') {
                jQuery('#mainwp_notes_status').html(__('Note saved!'));
                rowEl.find('.note').html(jQuery('#mainwp_notes_note').val());

                if (newnote == '') {
                    rowEl.find('.mainwp_notes_img').hide();
                }
                else {
                    rowEl.find('.mainwp_notes_img').show();
                }
            }
            else if (response.error != undefined) {
                jQuery('#mainwp_notes_status').html(__('Undefined error occured while saving your note!') + ': ' + response.error);
            }
            else {
                jQuery('#mainwp_notes_status').html(__('Undefined error occured while saving your note!')) + '.';
            }
        } }(slug), 'json');
        return false;
    });
});

mainwp_fetch_plugins = function () {
    var errors = [];
    var selected_sites = [];
    var selected_groups = [];

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function (i) {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push('<div class="mainwp-notice mainwp-notice-red">'+__('Please select websites or groups.')+'</div>');
            jQuery('#selected_sites').addClass('form-invalid');
        }
        else {
            jQuery('#selected_sites').removeClass('form-invalid');
        }
    }
    else {
        jQuery("input[name='selected_groups[]']:checked").each(function (i) {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push('<div class="mainwp-notice mainwp-notice-red">'+__('Please select websites or groups.')+'</div>');
            jQuery('#selected_groups').addClass('form-invalid');
        }
        else {
            jQuery('#selected_groups').removeClass('form-invalid');
        }
    }

    if (errors.length > 0) {
        jQuery('#mainwp_plugins_error').html(errors.join('<br />'));
        jQuery('#mainwp_plugins_error').show();
        return;
    }
    else {
        jQuery('#mainwp_plugins_error').html("");
        jQuery('#mainwp_plugins_error').hide();
    }

    var data = mainwp_secure_data({
        action:'mainwp_plugins_search',
        keyword:jQuery('#mainwp_plugin_search_by_keyword').val(),
        status:jQuery('#mainwp_plugin_search_by_status').val(),
        'groups[]':selected_groups,
        'sites[]':selected_sites
    });

    jQuery('#mainwp_plugins_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_plugins_loading').hide();
        jQuery('#mainwp_plugins_main').show();
        jQuery('#mainwp_plugins_content').html(response);
        jQuery('#mainwp_plugins_loading_info').hide();
        mainwp_table_draggable_init('plugin', 'plugins_fixedtable');
    });
};

mainwp_fetch_all_active_plugins = function () {
    var data = mainwp_secure_data({
        action:'mainwp_plugins_search_all_active',
        keyword: jQuery("#mainwp_au_plugin_keyword").val(),
        status: jQuery("#mainwp_au_plugin_trust_status").val(),
        plugin_status: jQuery("#mainwp_au_plugin_status").val()
    });

    jQuery('#mainwp_plugins_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_plugins_loading').hide();
        jQuery('#mainwp_plugins_main').show();
        jQuery('#mainwp_plugins_content').html(response);
        mainwp_active_plugins_table_reinit();
    });
};

mainwp_fetch_all_themes = function (pSearch) {
    var data = mainwp_secure_data({
        action:'mainwp_themes_search_all',
        keyword: jQuery("#mainwp_au_theme_keyword").val(),
        status: jQuery("#mainwp_au_theme_trust_status").val(),
        theme_status: jQuery("#mainwp_au_theme_status").val()
    });

    jQuery('#mainwp_themes_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_themes_loading').hide();
        jQuery('#mainwp_themes_main').show();
        jQuery('#mainwp_themes_content').html(response);
        mainwp_themes_all_table_reinit();
    });
};

/**
 * MainWP_Post.page
 */
var countSent = 0;
var countReceived = 0;
jQuery(document).ready(function () {
    jQuery('.mainwp_datepicker').datepicker({dateFormat:"yy-mm-dd"});
    jQuery('#mainwp_show_posts').live('click', function () {
        mainwp_fetch_posts();
    });
    jQuery('.post_submitdelete').live('click', function () {
        mainwppost_postAction(jQuery(this), 'trash');
        return false;
    });
    jQuery('.post_submitpublish').live('click', function () {
        mainwppost_postAction(jQuery(this), 'publish');
        return false;
    });
    jQuery('.post_submitunpublish').live('click', function () {
        mainwppost_postAction(jQuery(this), 'unpublish');
        return false;
    });
    jQuery('.post_submitapprove').live('click', function () {
        mainwppost_postAction(jQuery(this), 'approve');
        return false;
    });
    jQuery('.post_submitdelete_perm').live('click', function () {
        mainwppost_postAction(jQuery(this), 'delete');
        return false;
    });
    jQuery('.post_submitrestore').live('click', function () {
        mainwppost_postAction(jQuery(this), 'restore');
        return false;
    });

    jQuery('.post_getedit').live('click', function () {
        mainwppost_postAction(jQuery(this), 'get_edit', 'post');
        return false;
    });

    jQuery('.page_getedit').live('click', function () {
        mainwppost_postAction(jQuery(this), 'get_edit', 'page');
        return false;
    });

    jQuery('#mainwp_bulk_post_action_apply').live('click', function () {
        var action = jQuery('#mainwp_bulk_action').val();
        if (action == 'none') return false;

        var tmp = jQuery("input[name='post[]']:checked");
        countSent = tmp.length;

        if (countSent == 0)
            return false;

        if (action == 'delete') {
            if (!confirm(__('You are about to delete %1 post(s). Are you sure you want to proceed?', countSent))) {
                return false;
            }
        }

        jQuery('#mainwp_bulk_post_action_apply').attr('disabled', 'true');

        tmp.each(
            function (index, elem) {
                mainwppost_postAction(elem, action);
            }
        );

        return false;
    });

    jQuery('#mainwp-category-add-submit').live('click', function()
    {
        var newCat = jQuery('#newcategory').val();
        if (jQuery('#categorychecklist').find('input[value="'+encodeURIComponent(newCat)+'"]').length > 0) return;
        jQuery('#categorychecklist').append('<li class="popular-category"><label class="selectit"><input value="'+encodeURIComponent(newCat)+'" type="checkbox" name="post_category[]"> '+newCat+'</label></li>');
        jQuery('#category-adder').addClass('wp-hidden-children');
        jQuery('#newcategory').val('');
    })
});

mainwppost_postAction = function (elem, what, postType) {
    var rowElement = jQuery(elem).parents('tr');
    var postId = rowElement.find('.postId').val();
    var websiteId = rowElement.find('.websiteId').val();
    if (rowElement.find('.allowedBulkActions').val().indexOf('|'+what+'|') == -1)
    {
        jQuery(elem).removeAttr('checked');
        countReceived++;

        if (countReceived == countSent) {
            countReceived = 0;
            countSent = 0;
            setTimeout(function() { jQuery('#mainwp_bulk_post_action_apply').removeAttr('disabled'); }, 50);
        }

        return;
    }

    if ( what == 'get_edit' && postType === 'page' ) {
        postId = rowElement.find('.pageId').val();
    }

    var data = {
        action:'mainwp_post_' + what,
        postId:postId,
        websiteId:websiteId
    };
    if (typeof postType !== "undefined") {
        data['postType'] = postType;
    }
    data = mainwp_secure_data(data);

    rowElement.find('.row-actions').hide();
    rowElement.find('.row-actions-working').show();
    jQuery.post(ajaxurl, data, function (response) {
        if (response.error) {
            rowElement.html('<td colspan="9"><span class="mainwp-red">'+response.error+'</span></td>');
        }
        else if (response.result) {
            rowElement.html('<td colspan="9"><i class="fa fa-check-circle"></i> ' + response.result + '</td>');
        }
        else {
            rowElement.find('.row-actions-working').hide();
            if (what == 'get_edit' && response.id) {
                if (postType == 'post') {
                    location.href = 'admin.php?page=PostBulkEdit&post_id=' + response.id;
                } else if (postType == 'page') {
                    location.href = 'admin.php?page=PageBulkEdit&post_id=' + response.id;
                }
            }
        }

        countReceived++;

        if (countReceived == countSent) {
            countReceived = 0;
            countSent = 0;
            jQuery('#mainwp_bulk_post_action_apply').removeAttr('disabled');
        }
    }, 'json');

    return false;
};

mainwp_table_sort_draggable_init = function (table, tableId, pState, pStatusName ) {
    try{
        savedState = JSON.parse(pState);
    } catch(e) {
        savedState = JSON.parse("{}");
    }

    var statusName = '';

    if (table == 'post') {
        statusName = 'posts_col_order';
    } else if (table == 'page') {
        statusName = 'pages_col_order';
    } else if (table == 'user') {
        statusName = 'users_col_order';
    } else if (typeof pStatusName !== "undefined") {
        statusName = pStatusName;
    }

    jQuery('#' + tableId).dragtable({
        sortClass: '.sorter',
        dragaccept: '.drag-enable',
        persistState: function(table) {
            var order = [];
            table.el.find('th').each(function(i) {
                if(this.id != '' && this.id != 'cb') {
                    if(order.indexOf(this.id) === -1)
                        order.push(this.id);
                }
            });
            var data = {
                action:'mainwp_saving_status',
                saving_status: statusName,
                value: JSON.stringify(order),
                nonce: mainwp_ajax_nonce
            };
            jQuery.post(ajaxurl, data, function (res) {
            });
        },
        restoreState: savedState
    });
}


mainwp_table_draggable_init = function (table, tableId, pState, pStatusName) {
    try{
        savedState = JSON.parse(pState);
    } catch(e) {
        savedState = JSON.parse("{}");
    }

    var statusName = '';
    if (table == 'site') {
        statusName = 'sites_col_order';
    } else if (table == 'plugin') {
        statusName = 'plugins_col_order';
    } else if (table == 'theme') {
        statusName = 'themes_col_order';
    } else if (typeof pStatusName !== "undefined") {
        statusName = pStatusName;
    }

    var tblSelector = '#' + tableId;

    if (table == 'site') {
        tblSelector = tableId;
    }

    try{
        jQuery(tblSelector).dragtable({
            dragaccept: '.drag-enable',
            dragHandle: '.table-handle',
            persistState: function(table) {
                var order = [];
                table.el.find('th').each(function(i) {
                    if(this.id != '' && this.id != 'cb') {
                        if(order.indexOf(this.id) === -1)
                            order.push(this.id);
                    }
                });
                var data = {
                    action:'mainwp_saving_status',
                    saving_status: statusName,
                    value: JSON.stringify(order),
                    nonce: mainwp_ajax_nonce
                };
                jQuery.post(ajaxurl, data, function (res) {
                });
            },
            restoreState: savedState
        });
    } catch(e) {
        // to fix
        console.log('Error:');
        console.log(e);
        var order = JSON.parse("{}");
        var data = {
            action:'mainwp_saving_status',
            saving_status: statusName,
            value: JSON.stringify(order),
            nonce: mainwp_ajax_nonce
        };
        jQuery.post(ajaxurl, data, function (res) {
        });
    }
}

mainwp_posts_table_reinit = function () {

    if (jQuery('#mainwp_posts_table').hasClass('tablesorter-default'))
    {
        jQuery('#mainwp_posts_table').trigger("updateAll").trigger('destroy.pager').tablesorterPager({container:jQuery("#pager")});
    }
    else
    {
        jQuery('#mainwp_posts_table').tablesorter({
            cssAsc:"desc",
            cssDesc:"asc",
            //selectorSort: '.sorter',
            textExtraction:function (node) {
                if (jQuery(node).find('abbr').length == 0) {
                    return node.innerHTML
                } else {
                    var raw = jQuery(node).find('abbr')[0].raw_value;
                    if (typeof raw !== typeof undefined && raw !== false) {
                        return raw;
                    }
                    return jQuery(node).find('abbr')[0].title;
                }
            },
            selectorHeaders: "> thead th:not(:first), > thead td:not(:first), > tfoot th:not(:first), > tfoot td:not(:first)"
        }).tablesorterPager({container:jQuery("#pager")});
    }
};
mainwp_show_post = function(siteId, postId, userId)
{
    var siteElement = jQuery('input[name="selected_sites[]"][siteid="'+siteId+'"]');
    siteElement.prop('checked', true);
    siteElement.trigger("change");
    mainwp_fetch_posts(postId, userId);
};

mainwp_fetch_posts = function (postId, userId) {
    var errors = [];
    var selected_sites = [];
    var selected_groups = [];

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function (i) {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push('<div class="mainwp-notice mainwp-notice-red">'+__('Please select websites or groups.')+'</div>');
            jQuery('#selected_sites').addClass('form-invalid');
        }
        else {
            jQuery('#selected_sites').removeClass('form-invalid');
        }
    }
    else {
        jQuery("input[name='selected_groups[]']:checked").each(function (i) {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push('<div class="mainwp-notice mainwp-notice-red">'+__('Please select websites or groups.')+'</div>');
            jQuery('#selected_groups').addClass('form-invalid');
        }
        else {
            jQuery('#selected_groups').removeClass('form-invalid');
        }
    }

    var status = "";
    var statuses = ['publish', 'pending', 'private', 'future', 'draft', 'trash'];
    for (var i = 0; i < statuses.length; i++) {
        if (jQuery('#mainwp_post_search_type_' + statuses[i]).attr('checked')) {
            if (status != "") status += ",";
            status += statuses[i];
        }
    }
    if (status == "") {
        errors.push('Please select a post status.');
    }

    if (errors.length > 0) {
        jQuery('#mainwp_posts_error').html(errors.join('<br />'));
        jQuery('#mainwp_posts_error').show();
        return;
    }
    else {
        jQuery('#mainwp_posts_error').html("");
        jQuery('#mainwp_posts_error').hide();
    }

    var data = {
        action:'mainwp_posts_search',
        keyword:jQuery('#mainwp_post_search_by_keyword').val(),
        dtsstart:jQuery('#mainwp_post_search_by_dtsstart').val(),
        dtsstop:jQuery('#mainwp_post_search_by_dtsstop').val(),
        status:status,
        'groups[]':selected_groups,
        'sites[]':selected_sites,
        postId: (postId == undefined ? '' : postId),
        userId: (userId == undefined ? '' : userId),
        post_type: jQuery("#mainwp_get_custom_post_types_select").val(),
        maximum: jQuery("#mainwp_maximumPosts").val()
    };

    jQuery('#mainwp_posts_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_posts_loading').hide();
        jQuery('#mainwp_posts_main').show();
        var matches = (response == null ? null : response.match(/post\[\]/g));
        jQuery('#mainwp_posts_total').html(matches == null ? 0 : matches.length);
        jQuery('#mainwp_posts_wrap_table').empty();
        jQuery('#mainwp_posts_wrap_table').html(response);
        mainwp_table_sort_draggable_init('post', 'mainwp_posts_table'); // postsColOrder not works 
        mainwp_posts_table_reinit();
    });
};

/**
 * MainWP_Themes.page
 */
jQuery(document).ready(function () {
    jQuery('#mainwp_show_themes').live('click', function () {
        mainwp_fetch_themes();
    });
    jQuery('.mainwp_theme_check_all').live('change', function () {
        var elements = jQuery(".selected_theme[value='"+jQuery(this).val()+"'][version='"+jQuery(this).attr('version')+"']");

        elements.prop('checked', jQuery(this).prop('checked'));
        mainwp_themes_check_changed(elements);
    });
    jQuery('.selected_theme').live('change', function () {
        mainwp_themes_check_changed([jQuery(this)]);
    });
    jQuery('#mainwp_bulk_action').live('change', function () {
        if (jQuery(this).val() == 'activate')
        {
            mainwp_themes_check_changed(jQuery(".selected_theme:checked"));
        }
    });

    var themeCountSent;
    var themeCountReceived;
    var themeResetAllowed = true;
    jQuery('#mainwp_bulk_theme_action_apply').live('click', function () {
        var action = jQuery('#mainwp_bulk_action').val();
        if (action == 'none') return;

        jQuery('#mainwp_bulk_theme_action_apply').attr('disabled', 'true');
        jQuery('#mainwp_bulk_action_loading').show();
        themeResetAllowed = false;
        themeCountSent = 0;
        themeCountReceived = 0;

        //Find all checked boxes
        jQuery('.websiteId').each(function () {
            var websiteId = jQuery(this).val();
            var rowElement = jQuery(this).parents('tr');

            var selectedThemes = rowElement.find('.selected_theme:checked');
            if (selectedThemes.length == 0) return;

            if (action == 'activate' || action == 'ignore_updates') {
                //Only activate the first
                var themeToActivate = jQuery(selectedThemes[0]).val();
                var themesToSend = [];
                var namesToSend = [];

                var data = mainwp_secure_data({
                    action:'mainwp_theme_' + action,
                    websiteId:websiteId
                });

                if (action == 'ignore_updates') {
                    for (var i = 0; i < selectedThemes.length; i++) {
                        themesToSend.push(jQuery(selectedThemes[i]).attr('slug'));
                        namesToSend.push(jQuery(selectedThemes[i]).val());
                    }
                    data['themes'] = themesToSend;
                    data['names'] = namesToSend;
                } else {
                    data['theme'] = themeToActivate;
                }


                themeCountSent++;
                jQuery.post(ajaxurl, data, function (response) {
                    themeCountReceived++;

                    if (themeResetAllowed && themeCountReceived == themeCountSent) {
                        themeCountReceived = 0;
                        themeCountSent = 0;
                        jQuery('#mainwp_bulk_action_loading').hide();
                        jQuery('#mainwp_themes_loading_info').show();
                        mainwp_fetch_themes();
                    }
                });
            }
            else if (action == 'delete') {
                var themesToDelete = [];
                for (var i = 0; i < selectedThemes.length; i++) {
                    themesToDelete.push(jQuery(selectedThemes[i]).val());
                }
                var data = mainwp_secure_data({
                    action:'mainwp_theme_delete',
                    themes:themesToDelete,
                    websiteId:websiteId
                });

                themeCountSent++;
                jQuery.post(ajaxurl, data, function (response) {
                    themeCountReceived++;

                    if (themeResetAllowed && themeCountReceived == themeCountSent) {
                        themeCountReceived = 0;
                        themeCountSent = 0;
                        jQuery('#mainwp_bulk_action_loading').hide();
                        jQuery('#mainwp_themes_loading_info').show();
                        mainwp_fetch_themes();
                    }
                });
            }
        });

        themeResetAllowed = true;
        if (themeCountReceived == themeCountSent) {
            themeCountReceived = 0;
            themeCountSent = 0;
            jQuery('#mainwp_bulk_action_loading').hide();
            jQuery('#mainwp_themes_loading_info').show();
            mainwp_fetch_themes();
        }
    })
});

mainwp_themes_check_changed = function(elements)
{
    var action = jQuery('#mainwp_bulk_action').val();
    if (action != 'activate') return;

    for (var i = 0; i < elements.length; i++)
    {
        var element = jQuery(elements[i]);
        if (!element.is(':checked')) continue;

        var parent = jQuery(element.parents('tr')[0]);

        if (!parent) continue;
        var subElements = parent.find('.selected_theme:checked');
        for (var j = 0; j < subElements.length; j++)
        {
            var subElement = subElements[j];
            if (subElement == element[0]) continue;

            jQuery(subElement).removeAttr('checked');
        }
    }
}

mainwp_fetch_themes = function () {
    var errors = [];
    var selected_sites = [];
    var selected_groups = [];

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function (i) {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push('<div class="mainwp-notice mainwp-notice-red">'+__('Please select websites or groups.')+'</div>');
            jQuery('#selected_sites').addClass('form-invalid');
        }
        else {
            jQuery('#selected_sites').removeClass('form-invalid');
        }
    }
    else {
        jQuery("input[name='selected_groups[]']:checked").each(function (i) {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push('<div class="mainwp-notice mainwp-notice-red">'+__('Please select websites or groups.')+'</div>');
            jQuery('#selected_groups').addClass('form-invalid');
        }
        else {
            jQuery('#selected_groups').removeClass('form-invalid');
        }
    }

    if (errors.length > 0) {
        jQuery('#mainwp_themes_error').html(errors.join('<br />'));
        jQuery('#mainwp_themes_error').show();
        return;
    }
    else {
        jQuery('#mainwp_themes_error').html("");
        jQuery('#mainwp_themes_error').hide();
    }

    var data = mainwp_secure_data({
        action:'mainwp_themes_search',
        keyword:jQuery('#mainwp_theme_search_by_keyword').val(),
        status:jQuery('#mainwp_theme_search_by_status').val(),
        'groups[]':selected_groups,
        'sites[]':selected_sites
    });

    jQuery('#mainwp_themes_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_themes_loading').hide();
        jQuery('#mainwp_themes_main').show();
        jQuery('#mainwp_themes_content').html(response);
        jQuery('#mainwp_themes_loading_info').hide();
        mainwp_table_draggable_init('theme', 'themes_fixedtable');
    });
};

/**
 * MainWP_User.page
 */
var userCountSent = 0;
var userCountReceived = 0;
jQuery(document).ready(function () {
    jQuery('#mainwp_show_users').live('click', function () {
        jQuery('#mainwp-update-users-box').hide();
        mainwp_fetch_users();
    });

    jQuery('.user_submitdelete').live('click', function () {
        mainwpuser_postAction(jQuery(this), 'delete');
        return false;
    });

    jQuery('.user_getedit').live('click', function () {
        jQuery('th.check-column input[type="checkbox"]').each(function () {
            this.checked = false;
        });
        jQuery('#mainwp-update-users-box').show();
        mainwpuser_postAction(jQuery(this), 'edit');
        return false;
    });

    jQuery('#mainwp_bulk_user_action_apply').live('click', function () {
        var action = jQuery('#mainwp_bulk_action').val();
        if (action == 'none') return false;

        var tmp = jQuery("input[name='user[]']:checked");
        userCountSent = tmp.length;

        if (userCountSent == 0)
            return false;

        if (action == 'delete') {
            if (!confirm(__('You are about to delete %1 user(s). Are you sure you want to proceed?', userCountSent))) {
                return false;
            }
        }

        jQuery('#mainwp_bulk_user_action_apply').attr('disabled', 'true');

        if (action == 'edit') {
            mainwp_edit_users_box_init();
            return;
        }

        tmp.each(
            function (index, elem) {
                mainwpuser_postAction(elem, action);
            }
        );

        return false;
    });


    jQuery('#mainwp_btn_update_user').live('click', function () {
        var errors= [];
        var tmp = jQuery("input[name='user[]']:checked");
        userCountSent = tmp.length;

        if (jQuery('#pass1').val() !== '' || jQuery('#pass2').val() !== '') {
            if (jQuery('#pass1').val() != jQuery('#pass2').val()) {
                jQuery('#pass1').parent().addClass('form-invalid');
                jQuery('#pass2').parent().addClass('form-invalid');
                errors.push('Passwords do not match.');
            }
            else {
                if (jQuery('#pass1').val() != '' )
                    jQuery('#pass1').parent().removeClass('form-invalid');
                if (jQuery('#pass2').val() != '' )
                    jQuery('#pass2').parent().removeClass('form-invalid');
            }
        }

        if (userCountSent == 0) {
            errors.push(__('Please search and select users.'));
        }

        if (errors.length > 0) {
            jQuery('#mainwp_update_password_error').html(errors.join('<br />'));
            jQuery('#mainwp_update_password_error').show();
            return false;
        }

        jQuery('#mainwp_update_password_error').hide();
        jQuery('#mainwp_users_updating').show();

        jQuery('#mainwp_bulk_user_action_apply').attr('disabled', 'true');
        jQuery('#mainwp_btn_update_user').attr('disabled', 'true');

        tmp.each(
            function (index, elem) {
                mainwpuser_postAction(elem, 'update_user');
            }
        );

        return false;
    });
});

mainwp_edit_users_box_init = function () {
    jQuery('#mainwp-update-users-box').show();
    jQuery('html, body').animate({
        scrollTop: jQuery("#mainwp_users_table").offset().top + jQuery("#mainwp_users_table").height()
    }, 500);
    jQuery('form#update_user_profile select#role option[value="donotupdate"]').prop('selected',true);
    jQuery('form#update_user_profile select#role').removeAttr('disabled');
    jQuery('form#update_user_profile input#first_name').val('');
    jQuery('form#update_user_profile input#last_name').val('');
    jQuery('form#update_user_profile input#nickname').val('');
    jQuery('form#update_user_profile input#email').val('');
    jQuery('form#update_user_profile input#url').val('');
    jQuery('form#update_user_profile select#display_name').empty().attr('disabled', 'disabled');
    jQuery('form#update_user_profile #description').val('');
};

mainwpuser_postAction = function (elem, what) {
    var rowElement = jQuery(elem).parents('tr');
    var userId = rowElement.find('.userId').val();
    var userName = rowElement.find('.userName').val();
    var websiteId = rowElement.find('.websiteId').val();

    var data = mainwp_secure_data({
        action:'mainwp_user_' + what,
        userId:userId,
        userName:userName,
        websiteId:websiteId,
        update_password: jQuery('#pass1').val()
    });

    if (what == 'update_user') {
        data['user_data'] = jQuery('form#update_user_profile').serialize();
    }

    rowElement.find('.row-actions').hide();
    rowElement.find('.row-actions-working').show();
    jQuery.post(ajaxurl, data, function (response) {
        if (what == 'edit' && response && response.user_data) {
            var roles_filter = ['administrator', 'subscriber', 'contributor', 'author', 'editor'];
            var disabled_change_role = false;
            if (response.user_data.role  == '' || jQuery.inArray( response.user_data.role, roles_filter) === -1) {
                jQuery('form#update_user_profile select#role option[value="donotupdate"]').prop('selected',true);
                disabled_change_role = true;
            } else {
                jQuery('form#update_user_profile select#role option[value="' + response.user_data.role + '"]').prop('selected', true);
                if (response.is_secure_admin) {
                    disabled_change_role = true;
                }
            }

            if (disabled_change_role) {
                jQuery('form#update_user_profile select#role').attr('disabled', 'disabled');
            } else {
                jQuery('form#update_user_profile select#role').removeAttr('disabled');
            }

            jQuery('form#update_user_profile input#first_name').val(response.user_data.first_name);
            jQuery('form#update_user_profile input#last_name').val(response.user_data.last_name);
            jQuery('form#update_user_profile input#nickname').val(response.user_data.nickname);
            jQuery('form#update_user_profile input#email').val(response.user_data.user_email);
            jQuery('form#update_user_profile input#url').val(response.user_data.user_url);
            jQuery('form#update_user_profile select#display_name').empty();
            jQuery('form#update_user_profile select#display_name').removeAttr('disabled');
            if(response.user_data.public_display) {
                jQuery.each(response.user_data.public_display, function (index, value) {
                    var o = new Option(value);
                    if (value == response.user_data.display_name) {
                        o.selected = true;
                    }
                    jQuery('form#update_user_profile select#display_name').append(o);
                });
                jQuery('form#update_user_profile select#display_name option[value="' + response.user_data.display_name + '"]').prop('selected', true);
            }

            jQuery('form#update_user_profile #description').val(response.user_data.description);
            rowElement.find('th.check-column input[type="checkbox"]')[0].checked = true;

            jQuery('html, body').animate({
                scrollTop: jQuery("#mainwp_users_table").offset().top + jQuery("#mainwp_users_table").height()
            }, 500);
            rowElement.find('.row-actions-working').hide();
            return;
        }

        if (response.result) {
            rowElement.html('<td colspan="8"><i class="fa fa-check-circle"></i> ' + response.result + '</td>');
        }
        else {
            rowElement.find('.row-actions-working').hide();
        }
        userCountReceived++;
        if (userCountReceived == userCountSent) {
            userCountReceived = 0;
            userCountSent = 0;
            jQuery('#mainwp_bulk_user_action_apply').removeAttr('disabled');

            jQuery('#mainwp_btn_update_user').removeAttr('disabled');
            jQuery('#mainwp_users_updating').hide();

            if (what == 'update_user' || what == 'delete' ) {
                jQuery('#mainwp_users_loading_info').show();
                jQuery('#mainwp-update-users-box').hide();
                mainwp_fetch_users();
            }
        }
    }, 'json');

    return false;
};

mainwp_users_table_reinit = function () {
    if (jQuery('#mainwp_users_table').hasClass('tablesorter-default'))
    {
        jQuery('#mainwp_users_table').trigger("updateAll").trigger('destroy.pager').tablesorterPager({container:jQuery("#pager")});
    }
    else
    {
        jQuery('#mainwp_users_table').tablesorter({
            cssAsc:"desc",
            cssDesc:"asc",
            textExtraction:function (node) {
                if (jQuery(node).find('abbr').length == 0) {
                    return node.innerHTML
                } else {
                    return jQuery(node).find('abbr')[0].title;
                }
            },
            selectorHeaders: "> thead th:not(:first), > thead td:not(:first), > tfoot th:not(:first), > tfoot td:not(:first)"
        }).tablesorterPager({container:jQuery("#pager")});
    }
};

mainwp_fetch_users = function () {
    var errors = [];
    var selected_sites = [];
    var selected_groups = [];

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function (i) {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push('<div class="mainwp-notice mainwp-notice-red">'+__('Please select websites or groups.')+'</div>');
            jQuery('#selected_sites').addClass('form-invalid');
        }
        else {
            jQuery('#selected_sites').removeClass('form-invalid');
        }
    }
    else {
        jQuery("input[name='selected_groups[]']:checked").each(function (i) {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push('<div class="mainwp-notice mainwp-notice-red">'+__('Please select websites or groups.')+'</div>');
            jQuery('#selected_groups').addClass('form-invalid');
        }
        else {
            jQuery('#selected_groups').removeClass('form-invalid');
        }
    }

    var role = "";
    var roles = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
    for (var i = 0; i < roles.length; i++) {
        if (jQuery('#mainwp_user_role_' + roles[i]).attr('checked')) {
            if (role != "") role += ",";
            role += roles[i];
        }
    }

//    if (role == "") {
//        errors.push('Please select a user role.');
//    }

    if (errors.length > 0) {
        jQuery('#mainwp_users_error').html(errors.join('<br />'));
        jQuery('#mainwp_users_error').show();
        jQuery('#mainwp_users_loading_info').hide();
        return;
    }
    else {
        jQuery('#mainwp_users_error').html("");
        jQuery('#mainwp_users_error').hide();
    }

    var name = jQuery('#mainwp_search_users').val();

    var data = mainwp_secure_data({
        action:'mainwp_users_search',
        role:role,
        search: name,
        'groups[]':selected_groups,
        'sites[]':selected_sites
    });

    jQuery('#mainwp_users_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_users_loading').hide();
        jQuery('#mainwp_users_loading_info').hide();
        jQuery('#mainwp_users_main').show();
        var matches = (response == null ? null : response.match(/user\[\]/g));
        jQuery('#mainwp_users_total').html(matches == null ? 0 : matches.length);
        jQuery('#mainwp_users_wrap_table').html(response);
        mainwp_table_sort_draggable_init('user', 'mainwp_users_table'); // usersColOrder not working                 
        mainwp_users_table_reinit();
    });
};

// to fix select all for ajax generate table
jQuery('table.fix-select-all-ajax-table .column-cb input[type="checkbox"]').live('click', function () {
    var selAll = jQuery(this).is(':checked');
    jQuery('table.fix-select-all-ajax-table .check-column input[type="checkbox"]').each(function () {
        this.checked = false;
    })
    jQuery('table.fix-select-all-ajax-table .check-column input[type="checkbox"]:visible').each(function () {
        this.checked = selAll;
    });
})
jQuery('table.fix-select-all-ajax-table tbody .check-column input[type="checkbox"]').live('click', function () {
    var count = jQuery('table.fix-select-all-ajax-table tbody .check-column input[type="checkbox"]:visible').length;
    var countChecked = 0;
    jQuery('table.fix-select-all-ajax-table tbody .check-column input[type="checkbox"]:visible').each(function () {
        if (this.checked)
            countChecked++;
    });
    if (count == countChecked) {
        jQuery('table.fix-select-all-ajax-table .column-cb input[type="checkbox"]').prop('checked',true);
    } else {
        jQuery('table.fix-select-all-ajax-table .column-cb input[type="checkbox"]').prop('checked',false);
    }

})
// end

jQuery(document).ready(function(){
    jQuery('.mainwp_datepicker').datepicker();
});


getErrorMessage = function(pError)
{
    if (pError.message == 'HTTPERROR') {
        return __('HTTP error')+'! ' + pError.extra;
    }
    else if (pError.message == 'NOMAINWP') {
        var error = '';
        if (pError.extra)
        {
            error = __('MainWP Child plugin not detected! First install and activate the MainWP Child plugin and add your site to MainWP Dashboard afterwards. If you continue experiencing this issue please test your connection <a href="admin.php?page=managesites&do=test&site=%1">here</a> or post as much information as possible on the error in the <a href="https://mainwp.com/forum/">support forum</a>.', encodeURIComponent(pError.extra));
        }
        else
        {
            error = __('MainWP Child plugin not detected! First install and activate the MainWP Child plugin and add your site to MainWP Dashboard afterwards.');
        }

        return error;
    }
    else if (pError.message == 'ERROR') {
        return 'ERROR' + ((pError.extra != '') && (pError.extra != undefined) ? ': ' + pError.extra : '');
    }
    else if (pError.message == 'WPERROR') {
        return __('ERROR on the child site') + ((pError.extra != '') && (pError.extra != undefined) ? ': ' + pError.extra : '');
    }
    else if (pError.message != undefined && pError.message != '')
    {
        return pError.message;
    }
    else
    {
        return pError;
    }
};
dateToYMD = function(date) {
    if (mainwpParams != undefined && mainwpParams['date_format'] != undefined)
    {
        var time = moment(date);
        var format = mainwpParams['date_format'];
        format = format.replace('g', 'h');
        format = format.replace('i', 'm');
        format = format.replace('F', 'MMMM');
        format = format.replace('j', 'D');
        format = format.replace('Y', 'YYYY');
        return time.format(format);
    }

    var d = date.getDate();
    var m = date.getMonth() + 1;
    var y = date.getFullYear();
    return '' + y + '-' + (m<=9 ? '0' + m : m) + '-' + (d <= 9 ? '0' + d : d);
};
dateToHMS = function(date) {
    if (mainwpParams != undefined && mainwpParams['time_format'] != undefined)
    {
        var time = moment(date);
        var format = mainwpParams['time_format'];
        format = format.replace('g', 'h');
        format = format.replace('i', 'mm');
        format = format.replace('s', 'ss');
        format = format.replace('F', 'MMMM');
        format = format.replace('j', 'D');
        format = format.replace('Y', 'YYYY');
        return time.format(format);
    }
    var h = date.getHours();
    var m = date.getMinutes();
    var s = date.getSeconds();
    return '' + (h <= 9 ? '0' + h : h) + ':' + (m<=9 ? '0' + m : m) + ':' + (s <= 9 ? '0' + s : s);
};
appendToDiv = function(pSelector, pText, pScrolldown, pShowTime)
{
    if (pScrolldown == undefined) pScrolldown = true;
    if (pShowTime == undefined) pShowTime = true;

    var theDiv = jQuery(pSelector);
    theDiv.append('<br />' + (pShowTime ? dateToHMS(new Date()) + ' ' : '') + pText);
    if (pScrolldown) theDiv.animate({scrollTop: theDiv.prop("scrollHeight")}, 100);
};

jQuery.fn.exists = function () {
    return (this.length !== 0);
};

jQuery(document).ready(function() {
    jQuery('.mainwp_autocomplete').each(function(key, value) {
        var autocompleteList = jQuery(value).attr('autocompletelist');
        var realList = jQuery('#' + autocompleteList);
        var text = [];
        var foundOptions = realList.find('option');

        for (var i = 0; i < foundOptions.length; i++)
        {
            text.push(jQuery(foundOptions[i]).val());
        }
        jQuery(value).autocomplete({source:text});
    });
});

function __(text, _var1, _var2, _var3)
{
    if (text == undefined || text == '') return text;
    var strippedText = text.replace(/ /g, '_');
    strippedText = strippedText.replace(/[^A-Za-z0-9_]/g, '');

    if (strippedText == '') return text.replace('%1', _var1).replace('%2', _var2).replace('%3', _var3);

    if (mainwpTranslations == undefined) return text.replace('%1', _var1).replace('%2', _var2).replace('%3', _var3);
    if (mainwpTranslations[strippedText] == undefined) return text.replace('%1', _var1).replace('%2', _var2).replace('%3', _var3);

    return mainwpTranslations[strippedText].replace('%1', _var1).replace('%2', _var2).replace('%3', _var3);
}

jQuery(document).on('change', '#mainwp_serverInformation_child', function()
{
    var siteId = jQuery(this).val();
    if (siteId == '-1' || siteId == '')
    {
        jQuery('#mainwp_serverInformation_child_resp').hide();
        return;
    }

    jQuery('#mainwp_serverInformation_child_loading').show();
    jQuery('#mainwp_serverInformation_child_resp').hide();

    var data = {
        action:'mainwp_serverInformation',
        siteId: siteId
    };

    jQuery.post(ajaxurl, data, function(resp)
    {
        jQuery('#mainwp_serverInformation_child_resp').html(resp);
        jQuery('#mainwp_serverInformation_child_loading').hide();
        jQuery('#mainwp_serverInformation_child_resp').show();
    }, 'html');
});

jQuery(document).on('change', '#mainwp-server-info-filter', function()
{
    var info = jQuery('#mainwp-server-info-filter').val();

    if (info == "error-log") {
        jQuery('#mainwp-server-information-section').hide();
        jQuery('#mainwp-cron-schedules-section').hide();
        jQuery('#mainwp-wp-config-section').hide();
        jQuery('#mainwp-error-log-section').show();
    } else if (info == "server-information") {
        jQuery('#mainwp-server-information-section').show();
        jQuery('#mainwp-cron-schedules-section').hide();
        jQuery('#mainwp-wp-config-section').hide();
        jQuery('#mainwp-error-log-section').hide();
    } else if (info == "cron-schedules") {
        jQuery('#mainwp-server-information-section').hide();
        jQuery('#mainwp-cron-schedules-section').show();
        jQuery('#mainwp-wp-config-section').hide();
        jQuery('#mainwp-error-log-section').hide();
    } else if (info == "wp-config") {
        jQuery('#mainwp-server-information-section').hide();
        jQuery('#mainwp-cron-schedules-section').hide();
        jQuery('#mainwp-wp-config-section').show();
        jQuery('#mainwp-error-log-section').hide();
    } else {
        jQuery('#mainwp-server-information-section').show();
        jQuery('#mainwp-cron-schedules-section').show();
        jQuery('#mainwp-wp-config-section').show();
        jQuery('#mainwp-error-log-section').show();
    }
});

mainwp_secure_data = function(data, includeDts)
{
    if (data['action'] == undefined) return data;

    data['security'] = security_nonces[data['action']];
    if (includeDts) data['dts'] = Math.round(new Date().getTime() / 1000);
    return data;
};

/**
 * MainwPHelp.widget
 */

jQuery(document).ready(function () {
    jQuery('#mainwp-quick-start-tab').live('click', function () {
        showDocsLists(true, false, false, false, false, false, false);
        return false;
    });
    jQuery('#mainwp-manage-tab').live('click', function () {
        showDocsLists(false, true, false, false, false, false, false);
        return false;
    });
    jQuery('#mainwp-sites-tab').live('click', function () {
        showDocsLists(false, false, true, false, false, false, false);
        return false;
    });
    jQuery('#mainwp-backups-tab').live('click', function () {
        showDocsLists(false, false, false, true, false, false, false);
        return false;
    });
    jQuery('#mainwp-clone-tab').live('click', function () {
        showDocsLists(false, false, false, false, true, false, false);
        return false;
    });
    jQuery('#mainwp-misc-tab').live('click', function () {
        showDocsLists(false, false, false, false, false, true, false);
        return false;
    });
    jQuery('#mainwp-extensions-tab').live('click', function () {
        showDocsLists(false, false, false, false, false, false, true);
        return false;
    });
});

showDocsLists = function (start, manage, sites, backups, clone, misc, extensions) {
    var mainwp_quick_start_tab = jQuery("#mainwp-quick-start-tab");
    if (start) mainwp_quick_start_tab.addClass('mainwp_action_down');
    else mainwp_quick_start_tab.removeClass('mainwp_action_down');

    var mainwp_manage_tab = jQuery("#mainwp-manage-tab");
    if (manage) mainwp_manage_tab.addClass('mainwp_action_down');
    else mainwp_manage_tab.removeClass('mainwp_action_down');

    var mainwp_sites_tab = jQuery("#mainwp-sites-tab");
    if (sites) mainwp_sites_tab.addClass('mainwp_action_down');
    else mainwp_sites_tab.removeClass('mainwp_action_down');

    var mainwp_backups_tab = jQuery("#mainwp-backups-tab");
    if (backups) mainwp_backups_tab.addClass('mainwp_action_down');
    else mainwp_backups_tab.removeClass('mainwp_action_down');

    var mainwp_clone_tab = jQuery("#mainwp-clone-tab");
    if (clone) mainwp_clone_tab.addClass('mainwp_action_down');
    else mainwp_clone_tab.removeClass('mainwp_action_down');

    var mainwp_misc_tab = jQuery("#mainwp-misc-tab");
    if (misc) mainwp_misc_tab.addClass('mainwp_action_down');
    else mainwp_misc_tab.removeClass('mainwp_action_down');

    var mainwp_extensions_tab = jQuery("#mainwp-extensions-tab");
    if (extensions) mainwp_extensions_tab.addClass('mainwp_action_down');
    else mainwp_extensions_tab.removeClass('mainwp_action_down');

    var mainwp_start_docs = jQuery("#mainwp-quick-start-docs");
    var mainwp_manage_docs = jQuery("#mainwp-manage-docs");
    var mainwp_sites_docs = jQuery("#mainwp-sites-docs");
    var mainwp_backups_docs = jQuery("#mainwp-backups-docs");
    var mainwp_clone_docs = jQuery("#mainwp-clone-docs");
    var mainwp_misc_docs = jQuery("#mainwp-misc-docs");
    var mainwp_extensions_docs = jQuery("#mainwp-extensions-docs");


    if (start) mainwp_start_docs.show();
    if (manage) mainwp_manage_docs.show();
    if (sites) mainwp_sites_docs.show();
    if (backups) mainwp_backups_docs.show();
    if (clone) mainwp_clone_docs.show();
    if (misc) mainwp_misc_docs.show();
    if (extensions) mainwp_extensions_docs.show();

    if (!start) mainwp_start_docs.hide();
    if (!manage) mainwp_manage_docs.hide();
    if (!sites) mainwp_sites_docs.hide();
    if (!backups) mainwp_backups_docs.hide();
    if (!clone) mainwp_clone_docs.hide();
    if (!misc) mainwp_misc_docs.hide();
    if (!extensions) mainwp_extensions_docs.hide();
};

jQuery(document).on('click', '#mainwp-add-site-notice-dismiss', function()
{
    jQuery('#mainwp-add-site-notice').hide();
    jQuery('#mainwp-add-site-notice-show').show();

    return false;
});

jQuery(document).on('click', '#mainwp-add-site-notice-show-link', function()
{
    jQuery('#mainwp-add-site-notice').show();
    jQuery('#mainwp-add-site-notice-show').hide();

    return false;
});

jQuery(document).on('click', '.mainwp-news-tab', function()
{
    jQuery('.mainwp-news-tab').removeClass('mainwp_action_down');
    jQuery('.mainwp-news-items').hide();
    jQuery(this).addClass('mainwp_action_down');
    jQuery('.mainwp-news-items[name="'+jQuery(this).attr('name')+'"]').show();

    return false;
});

function mainwp_setCookie(c_name, value, expiredays)
{
    var exdate = new Date();
    exdate.setDate(exdate.getDate() + expiredays);
    document.cookie = c_name + "=" + escape(value) + ((expiredays == null) ? "" : ";expires=" + exdate.toUTCString());
}
function mainwp_getCookie(c_name)
{
    if (document.cookie.length > 0)
    {
        var c_start = document.cookie.indexOf(c_name + "=");
        if (c_start != -1)
        {
            c_start = c_start + c_name.length + 1;
            var c_end = document.cookie.indexOf(";", c_start);
            if (c_end == -1)
                c_end = document.cookie.length;
            return unescape(document.cookie.substring(c_start, c_end));
        }
    }
    return "";
}

mainwp_uid = function() {
    // always start with a letter (for DOM friendlyness)
    var idstr=String.fromCharCode(Math.floor((Math.random()*25)+65));
    do {
        // between numbers and characters (48 is 0 and 90 is Z (42-48 = 90)
        var ascicode=Math.floor((Math.random()*42)+48);
        if (ascicode<58 || ascicode>64){
            // exclude all chars between : (58) and @ (64)
            idstr+=String.fromCharCode(ascicode);
        }
    } while (idstr.length<32);

    return (idstr);
};

scrollToElement = function(pElement) {
    jQuery('html,body').animate({
        scrollTop: 0
    }, 1000);

    return false;
};

jQuery(document).ready(function() {
    jQuery('#backup_filename').keypress(function (e)
    {
        var chr = String.fromCharCode(e.which);
        return ("$^&*/".indexOf(chr) < 0);
    });
    jQuery('#backup_filename').change( function() {
        var value = jQuery(this).val();
        var notAllowed = ['$', '^', '&', '*', '/'];
        for (var i = 0; i < notAllowed.length; i++)
        {
            var char = notAllowed[i];
            if (value.indexOf(char) >= 0)
            {
                value = value.replace(new RegExp('\\' + char, 'g'), '');
                jQuery(this).val(value);
            }
        }
    });
});

jQuery('a.mwp-get-system-report-btn').live('click', function(){
    var report = "";
    jQuery('.mwp_server_info_box thead, .mwp_server_info_box tbody').each(function(){
        var td_len = [35, 55, 45, 12, 12];
        var th_count = 0;
        var i;
        if ( jQuery( this ).is('thead') ) {
            i = 0;
            report = report + "\n### ";
            th_count = jQuery( this ).find('th:not(".mwp-not-generate-row")').length;
            jQuery( this ).find('th:not(".mwp-not-generate-row")').each(function(){
                var len = td_len[i];
                if (i == 0 || i == th_count -1)
                    len = len - 4;
                report =  report + jQuery.mwp_strCut(jQuery.trim( jQuery( this ).text()), len, ' ' );
                i++;
            });
            report = report + " ###\n\n";
        } else {
            jQuery('tr', jQuery( this )).each(function(){
                if (jQuery( this ).hasClass('mwp-not-generate-row'))
                    return;
                i = 0;
                jQuery( this ).find('td:not(".mwp-not-generate-row")').each(function(){
                    if (jQuery( this ).hasClass('mwp-hide-generate-row')) {
                        report =  report + jQuery.mwp_strCut(' ', td_len[i], ' ' );
                        i++;
                        return;
                    }
                    report =  report + jQuery.mwp_strCut(jQuery.trim( jQuery( this ).text()), td_len[i], ' ' );
                    i++;
                });
                report = report + "\n";
            });

        }
    } );

    try {
        jQuery("#mwp-server-information").slideDown();
        jQuery("#mwp-server-information textarea").val( report ).focus().select();
        jQuery(this).fadeOut();
        jQuery('.mwp_close_srv_info').show();
        return false;
    } catch(e){ }
});

jQuery('a#mwp_close_srv_info').live('click', function(){
    jQuery('#mwp-server-information').hide();
    jQuery('.mwp_close_srv_info').hide();
    jQuery('a.mwp-get-system-report-btn').show();
    return false;
});

jQuery('#mwp_download_srv_info').live('click', function () {
    var server_info = jQuery('#mwp-server-information textarea').val();
    var blob = new Blob([server_info], {type: "text/plain;charset=utf-8"});
    saveAs(blob, "server_information.txt");
});

jQuery.mwp_strCut = function(i,l,s,w) {
    var o = i.toString();
    if (!s) { s = '0'; }
    while (o.length < parseInt(l)) {
        // empty
        if(w == 'undefined'){
            o = s + o;
        }else{
            o = o + s;
        }
    }
    return o;
};

updateExcludedFolders = function()
{
    var excludedBackupFiles = jQuery('#excludedBackupFiles').html();
    jQuery('#mainwp-kbl-content').val(excludedBackupFiles == undefined ? '' : excludedBackupFiles);

    var excludedCacheFiles = jQuery('#excludedCacheFiles').html();
    jQuery('#mainwp-kcl-content').val(excludedCacheFiles == undefined ? '' : excludedCacheFiles);

    var excludedNonWPFiles = jQuery('#excludedNonWPFiles').html();
    jQuery('#mainwp-nwl-content').val(excludedNonWPFiles == undefined ? '' : excludedNonWPFiles);
};


jQuery(document).on('click', '.mainwp-events-notice-dismiss', function()
{
    var notice = jQuery(this).attr('notice');
    jQuery(this).closest('.mainwp-events-notice').fadeOut(500);
    var data = mainwp_secure_data({
        action:'mainwp_events_notice_hide',
        notice: notice
    });
    jQuery.post(ajaxurl, data, function (res) {
    });
    return false;
});

jQuery(document).on('click', '#mainwp_btn_autoupdate_and_trust', function()
{
    jQuery(this).attr('disabled', 'true');
    var data = mainwp_secure_data({
        action:'mainwp_autoupdate_and_trust_child'
    });
    jQuery.post(ajaxurl, data, function (res) {
        if (res == 'ok') {
            location.reload(true);
        } else {
            jQuery(this).removeAttr('disabled');
        }
    });
    return false;
});

jQuery(document).on('click', '#remove-mainwp-installation-warning', function()
{
    jQuery('#mainwp-installation-warning').hide();
    var data = mainwp_secure_data({
        action:'mainwp_installation_warning_hide'
    });
    jQuery.post(ajaxurl, data, function (res) {    });
    return false;
});

jQuery(document).on('click', '.mainwp-dismiss', function(){
    jQuery('.mainwp-tips').fadeOut("slow");
    var data = mainwp_secure_data({
        action:'mainwp_tips_update',
        tipId: jQuery(this).closest('.mainwp-tips').find('.mainwp-tip').attr('id')
    });
    jQuery.post(ajaxurl, data, function (res) {
    });
    return false;
});

jQuery(document).on('click', '.mainwp-notice-dismiss', function(){
    var notice_id = jQuery(this).attr('notice-id');
    jQuery(this).closest('.mainwp-notice-wrap').fadeOut("slow");
    var data = {
        action:'mainwp_notice_status_update'
    };
    if (notice_id.indexOf('tour_') === 0) {
        data['tour_id'] = notice_id.replace('tour_', '');
    } else {
        data['notice_id'] = notice_id;
    }
    jQuery.post(ajaxurl, mainwp_secure_data(data), function (res) {
    });
    return false;
});

jQuery(document).on('click', '.mainwp-activate-notice-dismiss', function(){
    jQuery(this).closest('tr').fadeOut("slow");
    var data = mainwp_secure_data({
        action:'mainwp_dismiss_activate_notice',
        slug: jQuery(this).closest('tr').attr('slug')
    });
    jQuery.post(ajaxurl, data, function (res) {
    });
    return false;
});

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
    });
    jQuery.post(ajaxurl, data, function (res) {

    });
};

jQuery(document).on('change', '#mainwp-quick-jump-page', function()
{
    var siteId = jQuery('#mainwp-quick-jump-child').val();
    var pageSlug = jQuery('#mainwp-quick-jump-page').val();
    if (pageSlug.search('ManageBackups') != -1){
        window.location = 'admin.php?page='+ pageSlug;
    } else {
        window.location = 'admin.php?page=managesites&'+ pageSlug +'=' + siteId;
    }
});


mainwp_managesites_update_childsite_value = function(siteId, uniqueId) {
    var data = mainwp_secure_data({
        action:'mainwp_updatechildsite_value',
        site_id: siteId,
        unique_id: uniqueId
    });
    jQuery.post(ajaxurl, data, function (res) {
    });
    return false;
};

jQuery(document).on('keyup', '#managegroups-filter', function() {
    var filter = jQuery(this).val();
    var groupItems = jQuery(this).parent().find('li.managegroups-listitem');
    for (var i = 0; i < groupItems.length; i++)
    {
        var currentElement = jQuery(groupItems[i]);
        if (currentElement.hasClass('managegroups-group-add'))
            continue;
        var value = currentElement.find('span.text').text();
        if (value.indexOf(filter) > -1)
        {
            currentElement.show();
        }
        else
        {
            currentElement.hide();
        }
    }
});

jQuery(document).on('keyup', '#managegroups_site-filter', function() {
    var filter = jQuery(this).val();
    var siteItems = jQuery(this).parent().find('li.managegroups_site-listitem');
    for (var i = 0; i < siteItems.length; i++)
    {
        var currentElement = jQuery(siteItems[i]);
        var value = currentElement.find('span.website_name').text();
        if (value.indexOf(filter) > -1)
        {
            currentElement.show();
        }
        else
        {
            currentElement.hide();
        }
    }


});

mainwp_managegroups_ss_select = function (me, val) {
    var parent = jQuery(me).closest('.mainwp_managegroups-insidebox').find('#managegroups-listsites');
    parent.find('INPUT:checkbox').attr('checked', val).change();
    return false;
};


bulkManageSitesMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
bulkManageSitesCurrentThreads = 0;
bulkManageSitesTotal = 0;
bulkManageSitesFinished = 0;
bulkManageSitesTaskRunning = false;


managesites_bulk_init = function () {
    jQuery('.mainwp_append_error').remove();
    jQuery('.mainwp_append_message').remove();
    jQuery('#mainwp_managesites_add_other_message').hide();

    if (bulkManageSitesTaskRunning == false) {
        bulkManageSitesMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
        bulkManageSitesCurrentThreads = 0;
        bulkManageSitesTotal = 0;
        bulkManageSitesFinished = 0;
        jQuery('#the-list .check-column INPUT:checkbox').each(function(){jQuery(this).attr('status', 'queue')});
    }
};


managesites_bulk_done = function () {
    bulkManageSitesTaskRunning = false;
};

mainwp_managesites_bulk_remove_next = function() {
    while ((checkedBox = jQuery('#the-list .check-column INPUT:checkbox:checked[status="queue"]:first')) && (checkedBox.length > 0)  && (bulkManageSitesCurrentThreads < bulkManageSitesMaxThreads))
    {
        mainwp_managesites_bulk_remove_specific(checkedBox);
    }

    if ((bulkManageSitesTotal > 0) && (bulkManageSitesFinished == bulkManageSitesTotal)) {
        managesites_bulk_done();
        setHtml('#mainwp_managesites_add_other_message', __("Deleting sites finished."));
    }
}

mainwp_managesites_bulk_remove_specific  = function (pCheckedBox) {
    pCheckedBox.attr('status', 'running');
    var rowObj = pCheckedBox.closest('tr');
    bulkManageSitesCurrentThreads++;
    var loadingEl = rowObj.find('.column-site .bulk_running i');
    var id = rowObj.attr('siteid');
    loadingEl.show();

    jQuery('#site-status-' + id).html(__('Removing and deactivating the MainWP Child plugin...'));
    var data = mainwp_secure_data({
        action:'mainwp_removesite',
        id:id
    });
    jQuery.post(ajaxurl, data, function (response) {
        bulkManageSitesCurrentThreads--;
        bulkManageSitesFinished++;
        loadingEl.hide();
        var result = '';
        var error = '';
        if (response.error != undefined)
        {
            error = response.error;
        }
        else if (response.result == 'SUCCESS') {
            result = __('The site has been removed and the MainWP Child plugin has been disabled.');
        } else if (response.result == 'NOSITE') {
            error = __('The requested site has not been found');
        }
        else {
            result = __('The site has been removed but the MainWP Child plugin could not be disabled.');
        }

        if (error != '') {
            err = '<div class="mainwp-notice mainwp-notice-red mainwp_append_error">' + error + '</div>';
            jQuery('#mainwp_managesites_add_other_message').after(err);
        }
        //if (error == '') {
        jQuery('#site-status-' + id).html('');
        jQuery('tr[siteid=' + id + ']').html('<td colspan="6">' + result + '</td>');
        setTimeout(function() { jQuery('tr[siteid=' + id + ']').fadeOut(1000);}, 3000);
        //}
        mainwp_managesites_bulk_remove_next();
    }, 'json');
};

mainwp_managesites_bulk_test_connection_next = function() {
    while ((checkedBox = jQuery('#the-list .check-column INPUT:checkbox:checked[status="queue"]:first')) && (checkedBox.length > 0)  && (bulkManageSitesCurrentThreads < bulkManageSitesMaxThreads))
    {
        mainwp_managesites_bulk_test_connection_specific(checkedBox);
    }
    if ((bulkManageSitesTotal > 0) && (bulkManageSitesFinished == bulkManageSitesTotal)) {
        managesites_bulk_done();
        setHtml('#mainwp_managesites_add_other_message', __("Connection test finished."));
    }
}

mainwp_managesites_bulk_test_connection_specific = function(pCheckedBox) {
    pCheckedBox.attr('status', 'running');
    var rowObj = pCheckedBox.closest('tr');
    bulkManageSitesCurrentThreads++;
    var loadingEl = rowObj.find('.column-site .bulk_running i');
    loadingEl.show();
    var data = mainwp_secure_data({
        action:'mainwp_testwp',
        siteid: rowObj.attr('siteid')
    });
    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function(pLoadingEl) { return function (response) {
            bulkManageSitesCurrentThreads--;
            bulkManageSitesFinished++;
            pLoadingEl.hide();
            var msg = '', err = '';
            if (response.error)
            {
                if (response.httpCode)
                {
                    err = response.sitename+ ': '+__('Connection test failed!')+' '+__('URL:')+' '+response.host+' - '+__('HTTP-code:')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ' - '+__('Error message:')+' ' + response.error + ' <br/> <em>' + __('To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', '<a href="http://docs.mainwp.com/http-status-codes/" target="_blank">', '</a>', response.httpCode) + '</em>';
                }
                else
                {
                    err = response.sitename+ ': '+__('Connection test failed!')+ ' '+__('URL:')+' '+response.host + (response.ip != undefined ? ' (IP: ' + response.ip + ')' : '') +' - '+__('Error message:') + ' ' + response.error;
                }
            }
            else if (response.httpCode)
            {
                if (response.httpCode == '200')
                {
                    msg = response.sitename+ ': '+__('Connection test successful!')+' '+__('URL:')+' '+response.host + (response.ip != undefined ? ' (IP: ' + response.ip + ')' : '') + ' ('+__('Received HTTP-code:')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ')' + ' <br/> <em>' + __('To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', '<a href="http://docs.mainwp.com/http-status-codes/" target="_blank">', '</a>', response.httpCode) + '</em>';
                }
                else
                {
                    err = response.sitename+ ': '+__('Connection test failed!')+' '+__('URL:')+' '+response.host + (response.ip != undefined ? ' (IP: ' + response.ip + ')' : '') +' '+__('Received HTTP-code:')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ' <br/> <em>' + __('To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', '<a href="http://docs.mainwp.com/http-status-codes/" target="_blank">', '</a>', response.httpCode) + '</em>';
                }
            }
            else
            {
                var hint = '<br/>' + __('Hint: In case your dashboard and child sites are on the same server, please contact your host support and verify if your server allows loop-back connections.');
                err = response.sitename+ ': '+__('Invalid response from the server, please try again.') + hint;
            }

            if (msg != '') {
                msg = '<div class="mainwp-notice mainwp-notice-green mainwp_append_message">' + msg + '</div>';
                jQuery('#mainwp_managesites_add_other_message').after(msg);
            } else if (err != '') {
                err = '<div class="mainwp-notice mainwp-notice-red mainwp_append_error">' + err + '</div>';
                jQuery('#mainwp_managesites_add_other_message').after(err);
            }
            mainwp_managesites_bulk_test_connection_next();
        } }(loadingEl),
        dataType: 'json'});
    return false;
};

jQuery(document).on('click', '#mainwp_managesites_content #doaction', function(){
    var action = jQuery('#bulk-action-selector-top').val();
    if (action == -1)
        return false;
    mainwp_managesites_doaction(action);
    return false;
});

jQuery(document).on('click', '#mainwp_managesites_content #doaction2', function(){
    var action = jQuery('#bulk-action-selector-bottom').val();
    if (action == -1)
        return false;
    mainwp_managesites_doaction(action);
    return false;
});

mainwp_managesites_doaction = function(action) {

    if (action == 'delete' || action == 'test_connection' || action == 'sync' || action == 'reconnect' || action == 'update_plugins' || action == 'update_themes' || action == 'update_wpcore' || action == 'update_translations' ) {

        if (bulkManageSitesTaskRunning)
            return false;

        if (action == 'delete' || action == 'update_plugins' || action == 'update_themes' || action == 'update_wpcore' || action == 'update_translations' ) {
            if (!confirm("Are you sure?"))
                return false;
        }
        managesites_bulk_init();
        bulkManageSitesTotal = jQuery('#the-list .check-column INPUT:checkbox:checked[status="queue"]').length;

        bulkManageSitesTaskRunning = true;

        if (action == 'delete') {
            mainwp_managesites_bulk_remove_next();
            return false;
        } else if (action == 'test_connection') {
            mainwp_managesites_bulk_test_connection_next();
            return false;
        } else if (action == 'sync') {
            var syncIds = jQuery.map(jQuery('#the-list .check-column INPUT:checkbox:checked'), function(el) { return jQuery(el).val(); });
            mainwp_refresh_dashboard(syncIds);
        } else if (action == 'reconnect') {
            var selectedIds = jQuery.map(jQuery('#the-list .check-column INPUT:checkbox:checked'), function(el) { return jQuery(el).val(); });
            mainwp_managesites_bulk_reconnect_next(selectedIds);
        } else if (action == 'update_plugins') {
            var selectedIds = jQuery.map(jQuery('#the-list .check-column INPUT:checkbox:checked'), function(el) { return jQuery(el).val(); });
            mainwp_update_pluginsthemes('plugin', selectedIds);
        } else if (action == 'update_themes') {
            var selectedIds = jQuery.map(jQuery('#the-list .check-column INPUT:checkbox:checked'), function(el) { return jQuery(el).val(); });
            mainwp_update_pluginsthemes('theme', selectedIds);
        } else if (action == 'update_wpcore') {
            var selectedIds = jQuery.map(jQuery('#the-list .check-column INPUT:checkbox:checked'), function(el) { return jQuery(el).val(); });
            managesites_wordpress_global_upgrade_all(selectedIds);
        } else if (action == 'update_translations') {
            var selectedIds = jQuery.map(jQuery('#the-list .check-column INPUT:checkbox:checked'), function(el) { return jQuery(el).val(); });
            mainwp_update_pluginsthemes('translation', selectedIds);
        }
    }

    jQuery('#the-list .check-column INPUT:checkbox:checked').each(function() {
        var row = jQuery(this).closest('tr');
        switch(action) {
            case 'open_wpadmin':
                var url = row.find('.column-url a.open_newwindow_wpadmin').attr('href');
                window.open(url, '_blank');
                break;
            case 'open_frontpage':
                var url = row.find('.column-url a.site_url').attr('href');
                window.open(url, '_blank');
                break;
        }

    });
    return false;

}

jQuery(document).on('click', '.managesites_syncdata', function(){
    var row = jQuery(this).closest('tr');
    var syncIds = [];
    syncIds.push(row.attr('siteid'));
    mainwp_refresh_dashboard(syncIds);
    return false;
});


jQuery(document).ready(function() {
    jQuery('.mainwp-extensions-api-activation').live('click', function () {
        jQuery(this).closest("div.plugin-card").find("div.mainwp-extensions-api-row .api-row-div").toggle();
        return false;
    });
});

jQuery(document).on('click', '.mainwpactionlogsline', function(){
    jQuery(this).next().toggle();
});

jQuery(document).ready(function($) {
    mainwp_check_showhide_sections();
    $('.mainwp_postbox .handlediv').live('click', function(){
        var pr = $(this).parent();
        if (pr.hasClass('closed'))
            mainwp_set_showhide_section(pr, true);
        else
            mainwp_set_showhide_section(pr, false);
    });

    $('#mainwp-link-showhide-welcome-shortcuts').live('click', function(){
        var status = $(this).attr('status');
        var shortcuts = jQuery('#mainwp-welcome-bar-shotcuts');
        if (status == 'show') {
            $(this).attr('status', 'hide');
            $(this).html('<i class="fa fa-eye-slash" aria-hidden="true"></i> '+__('Show Quick Start shortcuts'));
            shortcuts.hide();
            status = 'hide';
        } else {
            $(this).attr('status', 'show');
            $(this).html('<i class="fa fa-eye-slash" aria-hidden="true"></i> '+__('Hide Quick Start shortcuts'));
            shortcuts.show();
            status = 'show';
        }
        mainwp_save_showhide_sections('welcome_shortcuts', status);
        return false;
    });

    $('#mainwp-link-showhide-synced-sites').live('click', function(){
        var status = $(this).attr('status');
        var wrap = jQuery('#mainwp-synced-status-sites-wrap');
        if (status == 'show') {
            $(this).attr('status', 'hide');
            $(this).html('<i class="fa fa-eye-slash" aria-hidden="true"></i> '+__('Show online sites'));
            wrap.hide();
            status = 'hide';
        } else {
            $(this).attr('status', 'show');
            $(this).html('<i class="fa fa-eye-slash" aria-hidden="true"></i> '+__('Hide online sites'));
            wrap.show();
            status = 'show';
        }
        mainwp_save_showhide_sections('synced_sites', status);
        return false;
    });
});

mainwp_save_showhide_sections = function(pSec, pStatus) {
    var data = {
        action:'mainwp_showhide_sections',
        sec: pSec,
        status: pStatus
    };
    jQuery.post(ajaxurl, data, function (res) {
    });
    return false;
}


mainwp_set_showhide_section = function(obj, show) {
    var sec = obj.attr('section');
    if (show) {
        obj.removeClass('closed');
        mainwp_setCookie('mainwp_showhide_section_' + sec, 'show');
    } else {
        obj.addClass('closed');
        mainwp_setCookie('mainwp_showhide_section_' + sec, '');
    }
};

mainwp_check_showhide_sections = function() {
    var pr, sec;
    jQuery('.mainwp_postbox .handlediv').each(function() {
        pr = jQuery(this).parent();
        sec = pr.attr('section');
        if (mainwp_getCookie('mainwp_showhide_section_' + sec) == 'show') {
            mainwp_set_showhide_section(pr, true);
        } else {
            mainwp_set_showhide_section(pr, false);
        }
    });
};


jQuery(document).on('click', '#mainwp-sites-menu-button', function(){
    jQuery('#mainwp-sites-menu').slideToggle();
    jQuery("#mainwp-sites-menu").scrollTop( 10000000 );
    jQuery("#mainwp-fly-manu-filter").focus();
    return false;
});

jQuery(document).bind('keypress', function(e){
    if(e.keyCode == 19 && e.shiftKey && e.ctrlKey) {
        jQuery('#mainwp-sites-menu').slideToggle();
        jQuery("#mainwp-sites-menu").scrollTop( 10000000 );
        jQuery("#mainwp-fly-manu-filter").focus();
        return false;
    }
});

jQuery(document).on('click', '#mainwp-add-new-button', function(){
    jQuery('#mainwp-add-new-links').slideToggle();
    return false;
});

mainwp_managesites_bulk_reconnect_next = function() {
    while ((checkedBox = jQuery('#the-list .check-column INPUT:checkbox:checked[status="queue"]:first')) && (checkedBox.length > 0)  && (bulkManageSitesCurrentThreads < bulkManageSitesMaxThreads))
    {
        mainwp_managesites_bulk_reconnect_specific(checkedBox);
    }
    if ((bulkManageSitesTotal > 0) && (bulkManageSitesFinished == bulkManageSitesTotal)) {
        managesites_bulk_done();
        setHtml('#mainwp_managesites_add_other_message', __("Reconnection finished."));
    }
}

mainwp_managesites_bulk_reconnect_specific = function(pCheckedBox) {
    pCheckedBox.attr('status', 'running');
    var rowObj = pCheckedBox.closest('tr');
    var pElement = rowObj.find('td.column-site .mainwp_site_reconnect');
    if (pElement.length == 0) {
        bulkManageSitesFinished++;
        mainwp_managesites_bulk_reconnect_next();
        return;
    }

    bulkManageSitesCurrentThreads++;
    pElement.parent().remove();

    var siteUrl = rowObj.attr('site-url');

    var statusEl = rowObj.find('.column-site .bulk_running .status');
    statusEl.html('<i class="fa fa-spinner fa-pulse"></i> '+'Trying to reconnect...').show();

    var data = {
        action:'mainwp_reconnectwp',
        siteid: rowObj.attr('siteid')
    };

    jQuery.post(ajaxurl, data, function(response) {
        bulkManageSitesCurrentThreads--;
        bulkManageSitesFinished++;
        statusEl.html('').hide();

        response = jQuery.trim(response);
        var msg = '', error = '';
        if (response.substr(0, 5) == 'ERROR') {
            if (response.length == 5) {
                error = __('Undefined error!');
            }
            else {
                error = 'Error - ' + response.substr(6);
            }
            error = siteUrl + '<br />' + error;
        }
        else
        {
            msg = siteUrl + '<br />' + response;
        }

        if (msg != '') {
            msg = '<div class="mainwp-notice mainwp-notice-green mainwp_append_message">' + msg + '</div>';
            jQuery('#mainwp_managesites_add_other_message').after(msg);
        } else if (error != '') {
            error = '<div class="mainwp-notice mainwp-notice-red mainwp_append_error">' + error + '</div>';
            jQuery('#mainwp_managesites_add_other_message').after(error);
        }
        mainwp_managesites_bulk_reconnect_next();
    });

    return;
};


mainwp_force_destroy_sessions = function() {
    var q = confirm(__('Are you sure?'));
    if (q) {
        jQuery('#refresh-status-box').dialog({
            resizable: false,
            height: 350,
            width: 500,
            modal: true
        });

        mainwp_force_destroy_sessions_websites = jQuery('.dashboard_wp_id').map(function(indx, el){ return jQuery(el).val(); });
        jQuery('#refresh-status-progress').progressbar({value: 0, max: mainwp_force_destroy_sessions_websites.length});

        mainwp_force_destroy_sessions_part_2(0);
    }
};

mainwp_force_destroy_sessions_part_2 = function(id) {
    if (id >= mainwp_force_destroy_sessions_websites.length) {
        mainwp_force_destroy_sessions_websites = [];
        if (mainwp_force_destroy_sessions_successed == mainwp_force_destroy_sessions_websites.length) {
            setTimeout(function ()
            {
                jQuery('#refresh-status-box').dialog('destroy');
                location.href = location.href;
            }, 3000);
        }
        jQuery('#refresh-status-box').dialog('destroy');
        location.href = location.href;

        return;
    }

    var website_id = mainwp_force_destroy_sessions_websites[id];
    dashboard_update_site_status(website_id, '<i class="fa fa-refresh fa-spin"></i> ' + __('SYNCING'));

    jQuery.post(ajaxurl, {'action': 'mainwp_force_destroy_sessions', 'website_id': website_id, 'security': security_nonces['mainwp_force_destroy_sessions']}, function(response) {
        var counter = id+1;
        mainwp_force_destroy_sessions_part_2(counter);

        jQuery('#refresh-status-progress').progressbar('value', counter);
        jQuery('#refresh-status-current').html(counter);

        if ('error' in response) {
            dashboard_update_site_status(website_id, '<span class="mainwp-red"><i class="fa fa-exclamation" aria-hidden="true"></i> ' + __('ERROR') + '</span>');
        } else if ('success' in response) {
            mainwp_force_destroy_sessions_successed += 1;
            dashboard_update_site_status(website_id, '<span class="mainwp-green"><i class="fa fa-check" aria-hidden="true"></i> ' + __('DONE' + '</span>'), true);
        } else {
            dashboard_update_site_status(website_id, '<span class="mainwp-red">' + __('UNKNOWN') + '</span>');
        }
    }, 'json').fail(function() {
        var counter = id+1;
        mainwp_force_destroy_sessions_part_2(counter);

        jQuery('#refresh-status-progress').progressbar('value', counter);
        jQuery('#refresh-status-current').html(counter);

        dashboard_update_site_status(website_id, '<span class="mainwp-red"><i class="fa fa-exclamation" aria-hidden="true"></i> ' + __('RESPONSE ERROR') + '</span>');
    });

};

var mainwp_force_destroy_sessions_successed = 0;

// MainWP Tools
jQuery(document).ready(function () {
    var mainwp_force_destroy_sessions_websites = [];
    jQuery('#force-destroy-sessions-button').live('click', function (event) {
        mainwp_force_destroy_sessions();
    });
});







/**
 * MainWP Child Scan
 **/

jQuery(document).on('click', '.mwp-child-scan', function() { mwp_start_childscan(); });
var childsToScan = [];
mwp_start_childscan = function()
{
    jQuery('#mwp_child_scan_childsites tr').each(function () {
        var id = jQuery(this).attr('siteid');
        if (id == undefined || id == '') return;
        childsToScan.push(id);
    });

    mwp_childscan_next();
};

mwp_childscan_next = function()
{
    if (childsToScan.length == 0) return;

    var childId = childsToScan.shift();

    jQuery('tr[siteid="' + childId + '"]').children().last().html('Scanning');

    var data = {
        action:'mainwp_childscan',
        childId:childId
    };

    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function(pId) { return function(response) {
            var tr = jQuery('tr[siteid="' + pId + '"]');
            if (response.success) {
                tr.children().last().html(response.success);
                tr.attr('siteid', '');
            } else if (response.error) {
                tr.children().last().html('Error: ' + response.error);
            } else {
                tr.children().last().html('Error while contacting site!');
            }
            mwp_childscan_next();
        }
        }(childId),
        error: function(pId) { return function(response) {
            jQuery('tr[siteid="' + pId + '"]').children().last().html('Error while contacting site!');
            mwp_childscan_next();
        } }(childId),
        dataType: 'json'
    });
};

jQuery('button.mainwp_tweet_this').live('click', function(){
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

jQuery(document).ready(function () {
    if ( typeof postboxes !== "undefined" && typeof mainwp_postbox_page !== "undefined") {
        postboxes.add_postbox_toggles( mainwp_postbox_page);
    }
});

jQuery(document).on('click', '#mainwp-most-common-reasons', function(){
    jQuery('#mainwp-most-common-reasons-content').toggle();
    return false;
});

mainwp_get_blogroll = function(reLoad) {
    jQuery('#mainwp_blogroll_content').html('<i class="fa fa-spinner fa-pulse"></i> ' + __('Loading...')).show();
    var data = {
        action:'mainwp_get_blogroll',
        nonce: mainwp_ajax_nonce,
    };
    if (typeof reLoad !== "undefined" && reLoad) {
        data['reload'] = 1;
    }
    jQuery.post(ajaxurl, data, function (res) {
        jQuery('#mainwp_blogroll_content').html(res);
    });
};

jQuery(document).ready(function() {

    jQuery('.mainwp_leftmenu_content').on("click", '.mainwp-menu-item div.handle', function(event){
        var pr = jQuery( this ).closest('li.mainwp-menu-item');
        var closed = pr.hasClass('closed');
        jQuery( '.mainwp_leftmenu_content li.mainwp-menu-item' ).addClass('closed');
        if (closed) {
            pr.removeClass( 'closed' );
        } else {
            pr.addClass( 'closed' );
        }
    });

    jQuery( '.mainwp_leftmenu_content .mainwp-menu-sub-item .handlediv' ).live('click', function () {
            var pr = jQuery( this ).closest('li');
            var closed = pr.hasClass('closed');
            mainwp_leftmenu_close_sub_menus();
            if (closed) {
                pr.removeClass( 'closed' );
            } else {
                pr.addClass( 'closed' );
            }
    });

    jQuery('.mainwp_leftmenu_content li.mainwp-menu-sub-item.mainwp-menu-has-submenu > .mainwp-menu-name a').live("click", function(event){
        var pr = jQuery( this ).closest('li.mainwp-menu-sub-item');
        var closed = pr.hasClass('closed');
        if (closed) {
            jQuery(pr).removeClass('closed');
        }
    });

    jQuery('#mainwp-leftmenu-group-filter').on('change', function() {
        var selgroup = this.value;
        if (selgroup == ''){
            jQuery(".menu-sites-wrap .mainwp-menu-sub-item").each(function (i) {
                jQuery(this).show();
            });
            return;
        } else {
                var data = {
                    action:'mainwp_leftmenu_filter_group',
                    group_id: selgroup
                };
                jQuery('.menu-sites-wrap #menu-sites-working').show();
                jQuery.post(ajaxurl, mainwp_secure_data(data), function (res) {
                    jQuery('.menu-sites-wrap #menu-sites-working').hide();
                    if (res != '') {
                        var ids = res.split(',');
                        var siteItems = jQuery('.menu-sites-wrap').find('.mainwp-menu-sub-item');
                        for (var i = 0; i < siteItems.length; i++)
                        {
                            var currentElement = jQuery(siteItems[i]);
                            var site_id = currentElement.attr('site-id');
                            if (ids.indexOf(site_id) > -1)
                            {
                                currentElement.show();
                            }
                            else
                            {
                                currentElement.hide();

                            }
                        }
                    } else {
                        jQuery(".menu-sites-wrap .mainwp-menu-sub-item").each(function (i) {
                            jQuery(this).hide();
                        });
                    }
                });
        }
    })
})

mainwp_leftmenu_change_status = function(row, value) {
    var data = {
        action:'mainwp_status_saving',
        status: 'status_leftmenu',
        key: jQuery(row).attr('item-key'),
        value: value ? 1 : 0 // 1 open
    };
    jQuery.post(ajaxurl, mainwp_secure_data(data), function (res) {
    });
};

mainwp_leftmenu_close_sub_menus = function(row, value) {
    // close all sub menu
    jQuery('li.mainwp-menu-sub-item.mainwp-menu-has-submenu').each(function() {
        if (!jQuery(this).hasClass('closed')) {
            jQuery(this).addClass('closed');
        }
    });
}

jQuery(document).on('keyup', '#mainwp-lefmenu-sites-filter', function() {
    jQuery('li.mainwp-menu-item').addClass('closed');
    jQuery('li.menu-sites-wrap').removeClass('closed');
    jQuery('#mainwp-leftmenu-group-filter').val('').trigger('change');
    var filter = jQuery(this).val();
    var siteItems = jQuery('.menu-sites-wrap').find('.mainwp-menu-sub-item');
    for (var i = 0; i < siteItems.length; i++)
    {
        var currentElement = jQuery(siteItems[i]);
        var value = currentElement.find('.mainwp-menu-name a').text();

        if (value.indexOf(filter) > -1)
        {
            currentElement.show();
        }
        else
        {
            currentElement.hide();

        }
    }
//    mainwp_managebackups_updateExcludefolders();
//    mainwp_newpost_updateCategories();
});

