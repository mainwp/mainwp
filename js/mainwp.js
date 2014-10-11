jQuery(document).ready(function ()
{
    jQuery(document).tooltip({
        items:"img.tooltip",
        track:true,
        content:function ()
        {
            var element = jQuery(this);
            return element.parents('.tooltipcontainer').children('.tooltipcontent').html();
        }
    });

    if (jQuery('#mainwp_options_loadFilesBeforeZip_container').length > 0) initTriStateCheckBox('mainwp_options_loadFilesBeforeZip_container', 'mainwp_options_loadFilesBeforeZip', true);
});

/**
 * API
 */
jQuery(document).ready(function () {
    jQuery('#mainwp-api-submit').live('click', function () {
        return apiTest();
    });
    jQuery('.mainwp-api-refresh').live('click', function () {
        return apiRefresh(jQuery(this));
    });
});

apiTest = function () {
    var data = {
        action:'mainwp_api_test',
        username:jQuery('#mainwp_api_username').val(),
        password:jQuery('#mainwp_api_password').val()
    };

    jQuery('#mainwp-api-submit').attr('disabled', 'true'); //Disable
    setVisible('#mainwp_api_errors', false);
    if (data['username'] == '' && data['password'] == '')
    {
        setHtml('#mainwp_api_message', __('Updating settings.'));
    }
    else
    {
        setHtml('#mainwp_api_message', __('Testing login.'));
    }

    jQuery.post(ajaxurl, data, function (response) {
        if (response['api_status'] == "VALID")
        {
            setVisible('#mainwp_api_errors', false);
            setHtml('#mainwp_api_message', __('Your login is valid, settings have been updated.'));
        }
        else if (response['api_status'] == "INVALID")
        {
            setVisible('#mainwp_api_errors', false);
            if ((response['error'] != undefined) && (response['error'] != ''))
            {
                setHtml('#mainwp_api_message', response['error']);
            }
            else
            {
                setHtml('#mainwp_api_message', __('Your login is invalid.'));
            }
        }
        else if (response['api_status'] == "ERROR")
        {
            setVisible('#mainwp_api_message', false);
            setHtml('#mainwp_api_errors', response['error'] + ".");
        }
        else if (response['saved'] == 1)
        {
            setVisible('#mainwp_api_errors', false);
            setHtml('#mainwp_api_message', __('Your settings have been updated.'));
        }
        else
        {
            setVisible('#mainwp_api_message', false);
            setHtml('#mainwp_api_errors', __('An error occured, please contact us.'));
        }
        if (response['api_status'] == "VALID") jQuery('#mainwp-api-submit').removeAttr('disabled'); //Enable

        jQuery('#mainwp-api-submit').removeAttr('disabled'); //Enable

    }, 'json');
    return false;
};

apiRefresh = function (pElement) {
    var data = {
        action:'mainwp_api_refresh'
    };

    pElement.attr('disabled', true);
    pElement.text(__('Updating your plan...'));

    jQuery.post(ajaxurl, data, function (response) {
        pElement.text(__('Updated your plan'));
    }, 'json');

    return false;
};

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
        showRecentPostsList(jQuery(this), true, false, false, false);
        return false;
    });
    jQuery('.recent_posts_draft_lnk').live('click', function () {
        showRecentPostsList(jQuery(this), false, true, false, false);
        return false;
    });
    jQuery('.recent_posts_pending_lnk').live('click', function () {
        showRecentPostsList(jQuery(this), false, false, true, false);
        return false;
    });
    jQuery('.recent_posts_trash_lnk').live('click', function () {
        showRecentPostsList(jQuery(this), false, false, false, true);
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
            rowElement.html('<font color="red">'+response.error+'</font>');
        }
        else if (response.result) {
            rowElement.html(response.result);
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
            rowElement.html('<font color="red">'+response.error+'</font>');
        }
        else if (response && response.result) {
            rowElement.html(response.result);
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
            rowElement.html('<font color="red">'+response.error+'</font>');
        }
        else if (response && response.result) {
            rowElement.html(response.result);
        }
        else {
            rowElement.children('.mainwp-row-actions-working').hide();
        }
    }, 'json');

    return false;
};



showRecentPostsList = function (pElement, published, draft, pending, trash) {
    var recent_posts_published_lnk = pElement.parent().find(".recent_posts_published_lnk");
    if (published) recent_posts_published_lnk.addClass('mainwp_action_down');
    else recent_posts_published_lnk.removeClass('mainwp_action_down');

    var recent_posts_draft_lnk = pElement.parent().find(".recent_posts_draft_lnk");
    if (draft) recent_posts_draft_lnk.addClass('mainwp_action_down');
    else recent_posts_draft_lnk.removeClass('mainwp_action_down');

    var recent_posts_pending_lnk = pElement.parent().find(".recent_posts_pending_lnk");
    if (pending) recent_posts_pending_lnk.addClass('mainwp_action_down');
    else recent_posts_pending_lnk.removeClass('mainwp_action_down');

    var recent_posts_trash_lnk = pElement.parent().find(".recent_posts_trash_lnk");
    if (trash) recent_posts_trash_lnk.addClass('mainwp_action_down');
    else recent_posts_trash_lnk.removeClass('mainwp_action_down');

    var recent_posts_published = pElement.parent().find(".recent_posts_published");
    var recent_posts_draft = pElement.parent().find(".recent_posts_draft");
    var recent_posts_pending = pElement.parent().find(".recent_posts_pending");
    var recent_posts_trash = pElement.parent().find(".recent_posts_trash");

    if (published) recent_posts_published.show();
    if (draft) recent_posts_draft.show();
    if (pending) recent_posts_pending.show();
    if (trash) recent_posts_trash.show();

    if (!published) recent_posts_published.hide();
    if (!draft) recent_posts_draft.hide();
    if (!pending) recent_posts_pending.hide();
    if (!trash) recent_posts_trash.hide();
};

showPluginsList  = function (pElement, activate, inactivate) {
    var plugins_actived_lnk = pElement.parent().find(".plugins_actived_lnk");
    if (activate) plugins_actived_lnk.addClass('mainwp_action_down');
    else plugins_actived_lnk.removeClass('mainwp_action_down');

    var plugins_inactive_lnk = pElement.parent().find(".plugins_inactive_lnk");
    if (inactivate) plugins_inactive_lnk.addClass('mainwp_action_down');
    else plugins_inactive_lnk.removeClass('mainwp_action_down');

    var plugins_activate = pElement.parent().find(".mainwp_plugins_active");
    var plugins_inactivate = pElement.parent().find(".mainwp_plugins_inactive");

    if (activate) plugins_activate.show();
    if (inactivate) plugins_inactivate.show();

    if (!activate) plugins_activate.hide();
    if (!inactivate) plugins_inactivate.hide();

};


showThemesList  = function (pElement, activate, inactivate) {
    var themes_actived_lnk = pElement.parent().find(".themes_actived_lnk");
    if (activate) themes_actived_lnk.addClass('mainwp_action_down');
    else themes_actived_lnk.removeClass('mainwp_action_down');

    var themes_inactive_lnk = pElement.parent().find(".themes_inactive_lnk");
    if (inactivate) themes_inactive_lnk.addClass('mainwp_action_down');
    else themes_inactive_lnk.removeClass('mainwp_action_down');

    var themes_activate = pElement.parent().find(".mainwp_themes_active");
    var themes_inactivate = pElement.parent().find(".mainwp_themes_inactive");

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
    var data = {
        action:'mainwp_securityIssues_fix',
        feature:feature,
        id:jQuery('#securityIssueSite').val()
    };
    jQuery.post(ajaxurl, data, function (response) {
        securityIssues_handle(response);
    }, 'json');
};
var completedSecurityIssues = undefined;
jQuery(document).on('click', '.securityIssues_dashboard_allFixAll', function() {
    jQuery(this).hide();
    rightnow_show('securityissues');

    var sites = jQuery('#wp_securityissues').find('.mainwp-row');
    completedSecurityIssues = 0;
    for (var i = 0; i < sites.length; i++)
    {
        var site = jQuery(sites[i]);
        if (site.find('.securityIssues_dashboard_fixAll').val() != 'Fix All') continue;
        completedSecurityIssues++;
        mainwp_securityIssues_fixAll(site.attr('siteid'), false);
    }
});
jQuery(document).on('click', '.securityIssues_dashboard_fixAll', function() {
    mainwp_securityIssues_fixAll(jQuery(jQuery(this).parents('div')[0]).attr('siteid'), true);
});
mainwp_securityIssues_fixAll = function(siteId, refresh)
{
    var data = {
        action:'mainwp_securityIssues_fix',
        feature:'all',
        id:siteId
    };

    var el = jQuery('#wp_securityissues .mainwp-row[siteid="'+siteId+'"] .securityIssues_dashboard_fixAll');
    el.hide();
    el.next('.img-loader').show();
    jQuery('.securityIssues_dashboard_fixAll').attr('disabled', 'true');
    jQuery('.securityIssues_dashboard_unfixAll').attr('disabled', 'true');
    jQuery.post(ajaxurl, data, function(pRefresh, pElement) { return function (response) {
        el.next('.img-loader').hide();
        el.show();
        if (pRefresh || (completedSecurityIssues != undefined && --completedSecurityIssues <= 0))
        {
            location.reload();
        }
    } }(refresh, el), 'json');
};
jQuery(document).on('click', '.securityIssues_dashboard_unfixAll', function() {
    var data = {
        action:'mainwp_securityIssues_unfix',
        feature:'all',
        id:jQuery(jQuery(this).parents('div')[0]).attr('siteid')
    };

    jQuery(this).hide();
    jQuery(this).next('.img-loader').show();
    jQuery('.securityIssues_dashboard_fixAll').attr('disabled', 'true');
    jQuery('.securityIssues_dashboard_unfixAll').attr('disabled', 'true');

    jQuery.post(ajaxurl, data, function (response) {
        location.reload();
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

    var data = {
        action:'mainwp_securityIssues_unfix',
        feature:feature,
        id:jQuery('#securityIssueSite').val()
    };
    jQuery.post(ajaxurl, data, function (response) {
        securityIssues_handle(response);
    }, 'json');
};
securityIssues_request = function (websiteId) {
    var data = {
        action:'mainwp_securityIssues_request',
        id:websiteId
    };
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
            result = __('Undefined error');
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

        location.href = location.href.replace('&refresh=yes', '');
    });
    jQuery(document).on('click', '#rightnow-upgrade-status-close', function(event)
    {
        bulkTaskRunning = false;
        jQuery('#rightnow-upgrade-status-box').dialog('destroy');

        location.href = location.href.replace('&refresh=yes', '');
    });
});
mainwp_refresh_dashboard = function ()
{
    var allWebsiteIds = jQuery('.dashboard_wp_id').map(function(indx, el){ return jQuery(el).val(); });
    for (var i = 0; i < allWebsiteIds.length; i++)
    {
        dashboard_update_site_status(allWebsiteIds[i], __('PENDING'));
    }
    var nrOfWebsites = allWebsiteIds.length;
    jQuery('#refresh-status-progress').progressbar({value: 0, max: nrOfWebsites});
    jQuery('#refresh-status-box').dialog({
        resizable: false,
        height: 350,
        width: 500,
        modal: true,
        close: function(event, ui) {bulkTaskRunning = false; jQuery('#refresh-status-box').dialog('destroy'); location.href = location.href.replace('&refresh=yes', '');}});
    dashboard_update(allWebsiteIds);
};

var websitesToUpdate = [];
var websitesTotal = 0;
var websitesLeft = 0;
var websitesDone = 0;
var websitesError = 0;
var currentWebsite = 0;
var bulkTaskRunning = false;
var currentThreads = 0;
var maxThreads = 8;

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

dashboard_update_site_status = function(siteId, newStatus)
{
    jQuery('.refresh-status-wp[siteid="'+siteId+'"]').html(newStatus);
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
                location.href = location.href.replace('&refresh=yes', '');
            }
            else
            {
                var message = websitesError + ' Site' + (websitesError > 1 ? 's' : '') + ' Timed Out / Errored. (There was an error syncing some of your sites. <a href="http://docs.mainwp.com/sync-error/">Please check this help doc for possible solutions.</a>)';
                jQuery('#refresh-status-content').prepend('<font color="red"><strong>' + message + '</strong></font><br /><br />');
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
    dashboard_update_site_status(websiteId, __('SYNCING'));
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
        success: function(pWebsiteId) { return function(response) { if (response.error) { dashboard_update_site_status(pWebsiteId, '<font color="red">' + __('ERROR') + '</font>'); websitesError++; } else {dashboard_update_site_status(websiteId, __('DONE'));} dashboard_update_done(); } }(websiteId),
        error: function(pWebsiteId, pData, pErrors) { return function(response) {
            if (pErrors > 5)
            {
                dashboard_update_site_status(pWebsiteId, '<font color="red">' +  __('TIMEOUT') + '</font>');  websitesError++; dashboard_update_done();
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

rightnow_ignore_plugintheme_by_site = function (what, slug, name, id) {
    var data = {
        action:'mainwp_ignoreplugintheme',
        type:what,
        id:id,
        slug:slug,
        name:name
    };
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            jQuery(document.getElementById('wp_upgrade_' + what + '_' + id + '_' + slug)).html(__('Ignored'));
            jQuery(document.getElementById('wp_upgrade_' + what + '_' + id + '_' + slug)).siblings('.mainwp-right-col').html('');
            jQuery('div['+what+'_slug="'+slug+'"] div[site_id="'+id+'"]').find('.pluginsInfo').html(__('Ignored'));
            jQuery('div['+what+'_slug="'+slug+'"] div[site_id="'+id+'"]').find('.pluginsAction').html('');
            jQuery('div['+what+'_slug="'+slug+'"] div[site_id="'+id+'"]').attr('updated', '-1');
        }
        else
        {
            jQuery(document.getElementById('wp_upgrade_' + what + '_' + id + '_' + slug)).html(getErrorMessage(response.error));
        }
    }, 'json');
    return false;
};
rightnow_unignore_plugintheme_by_site = function (what, slug, id) {
    var data = {
        action:'mainwp_unignoreplugintheme',
        type:what,
        id:id,
        slug:slug
    };
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
    var data = {
        action:'mainwp_unignoreplugintheme',
        type:what,
        id:'_ALL_',
        slug:'_ALL_'
    };
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
jQuery(document).on('click', '.mainwp_conflict_ignore', function() {
   var parentEl = jQuery(jQuery(this).parents('.mainwp_conflict')[0]);
   if (parentEl.attr('plugin'))
   {
       pluginthemeconflict_ignore('plugin', parentEl.attr('plugin'), parentEl.attr('siteid'), parentEl);
   }
   else
   {
       pluginthemeconflict_ignore('theme', parentEl.attr('theme'), parentEl.attr('siteid'), parentEl);
   }
    return false;
});
jQuery(document).on('click', '.mainwp_conflict_ignore_globally', function() {
   var parentEl = jQuery(jQuery(this).parents('.mainwp_conflict')[0]);
   if (parentEl.attr('plugin'))
   {
       pluginthemeconflict_ignore('plugin', parentEl.attr('plugin'), undefined, parentEl);
   }
   else
   {
       pluginthemeconflict_ignore('theme', parentEl.attr('theme'), undefined, parentEl);
   }
    return false;
});
pluginthemeconflict_ignore = function (what, name, siteid, parentEl) {
    var data = mainwp_secure_data({
        action: 'mainwp_ignorepluginthemeconflict',
        type: what,
        name: name,
        siteid: (siteid == undefined ? '' : siteid)
    });

    jQuery.post(ajaxurl, data, function (pParentEl) {
        return function (response) {
            if (response.result) {
                var parent = pParentEl.parent();
                pParentEl.remove();
                if (parent.children('.mainwp_conflict').length == 0)
                {
                    parent.remove();
                }
            }
            return false;
        }
    }(parentEl), 'json');
    return false;
};
pluginthemeconflict_unignore = function(what, name, siteid) {
    var data = mainwp_secure_data({
        action:'mainwp_unignorepluginthemeconflicts',
        type: what,
        name: (name == undefined ? '' : name),
        siteid: (siteid == undefined ? '' : siteid)
    });
    jQuery.post(ajaxurl, data, function(pWhat, pName, pSiteId) { return function (response) {
        if (response.result)
        {
            if (pSiteId == undefined)
            {
                //Global
                if (pName == undefined)
                {
                    //all
                    var tableElement = jQuery('#globally-ignored-'+pWhat+'conflict-list');
                    tableElement.find('tr').remove();
                    jQuery('.mainwp-unignore-globally-all').hide();
                    tableElement.append('<tr><td colspan="2">'+__('No ignored %1 conflicts', pWhat)+'</td></tr>');
                }
                else
                {
                    //specific plugin
                    var ignoreElement = jQuery('#globally-ignored-'+pWhat+'conflict-list tr['+pWhat+'="'+pName+'"]');
                    var parent = ignoreElement.parent();
                    ignoreElement.remove();
                    if (parent.children('tr').size() == 0) {
                        jQuery('.mainwp-unignore-globally-all').hide();
                        parent.append('<tr><td colspan="2">'+__('No ignored %1 conflicts', pWhat)+'</td></tr>');
                    }
                }
            }
            else
            {
                if (pSiteId == '_ALL_')
                {
                    //all
                    var tableElement = jQuery('#ignored-'+pWhat+'conflict-list');
                    tableElement.find('tr').remove();
                    tableElement.append('<tr><td colspan="3">'+__('No ignored %1 conflicts', pWhat)+'</td></tr>');
                    jQuery('.mainwp-unignore-detail-all').hide();
                }
                else
                {
                    //specific plugin
                    var siteElement = jQuery('tr[site_id="'+pSiteId+'"]['+pWhat+'="'+pName+'"]');

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
                        parent.append('<tr><td colspan="3">'+__('No ignored %1 conflicts', pWhat)+'</td></tr>');
                        jQuery('.mainwp-unignore-detail-all').hide();
                    }
              }
            }
        }
        return false;
    } }(what, name, siteid), 'json');
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
rightnow_plugins_ignore_detail = function (slug, name, id) {
    return rightnow_ignore_plugintheme_by_site('plugin', slug, name, id);
};
rightnow_plugins_unignore_detail = function (slug, id) {
    return rightnow_unignore_plugintheme_by_site('plugin', slug, id);
};
rightnow_plugins_unignore_detail_all = function () {
    return rightnow_unignore_plugintheme_by_site_all('plugin');
};
rightnow_themes_ignore_detail = function (slug, name, id) {
    return rightnow_ignore_plugintheme_by_site('theme', slug, name, id);
};
rightnow_themes_unignore_detail = function (slug, id) {
    return rightnow_unignore_plugintheme_by_site('theme', slug, id);
};
rightnow_themes_unignore_detail_all = function () {
    return rightnow_unignore_plugintheme_by_site_all('theme');
};
rightnow_plugins_ignore_all = function (slug, name) {
    rightnow_plugins_detail_show(slug);
    var data = {
        action:'mainwp_ignorepluginsthemes',
        type: 'plugin',
        slug:slug,
        name:name
    };
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
    var data = {
        action:'mainwp_unignorepluginsthemes',
        type: 'plugin',
        slug: '_ALL_'
    };
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
    var data = {
        action:'mainwp_unignorepluginsthemes',
        type: 'plugin',
        slug:slug
    };
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
    rightnow_themes_detail_show(slug);
    var data = {
        action:'mainwp_ignorepluginsthemes',
        type: 'theme',
        slug:slug,
        name:name
    };
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
    var data = {
        action:'mainwp_unignorepluginsthemes',
        type: 'theme',
        slug:'_ALL_'
    };
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
    var data = {
        action:'mainwp_unignorepluginsthemes',
        type: 'theme',
        slug:slug
    };
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
rightnow_plugins_upgrade = function (slug, websiteid) {
    return rightnow_plugins_upgrade_int(slug, websiteid);
};

rightnow_themes_upgrade = function (slug, websiteid) {
    return rightnow_themes_upgrade_int(slug, websiteid);
};

/** /END NEW **/


rightnow_upgrade_plugin = function (id, slug) {
    return rightnow_upgrade_plugintheme('plugin', id, slug);
};

rightnow_upgrade_plugin_all = function (id) {
    rightnow_show_if_required('plugin_upgrades', false);
    return rightnow_upgrade_plugintheme_all('plugin', id);
};
rightnow_upgrade_theme = function (id, slug) {
    return rightnow_upgrade_plugintheme('theme', id, slug);
};
rightnow_upgrade_theme_all = function (id) {
    rightnow_show_if_required('theme_upgrades', false);
    return rightnow_upgrade_plugintheme_all('theme', id);
};
rightnow_upgrade_plugintheme = function (what, id, name) {
    rightnow_upgrade_plugintheme_list(what, id, [name]);
    return false;
};
rightnow_upgrade_plugintheme_all = function (what, id, noCheck) {
    rightnowContinueAfterBackup = function(pId, pWhat) { return function()
    {
//        if (pId == undefined) {
//            jQuery("#wp_" + pWhat + "_upgrades [id^=wp_upgrade_" + pWhat + "]").each(function (index, value) {
//                var re = new RegExp('^wp_upgrade_' + pWhat + '_([0-9]+)$');
//                if (divId = re.exec(value.id)) {
//                    if (jQuery('#wp_upgraded_' + pWhat + '_' + divId[1]).val() == 0) {
//                        rightnow_upgrade_plugintheme_all(pWhat, parseInt(divId[1]), true);
//                    }
//                }
//            });
//        }
//        else {
            rightnow_show_if_required(pWhat+'_upgrades_'+pId, true);
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
//        }
    } }(id, what);

    if (noCheck)
    {
        rightnowContinueAfterBackup();
        return false;
    }

    var sitesToUpdate = [];
    var siteNames = [];

//    if (id == undefined) {
//        jQuery("#wp_" + what + "_upgrades [id^=wp_upgrade_" + what + "]").each(function (index, value) {
//            var re = new RegExp('^wp_upgrade_' + what + '_([0-9]+)$');
//            if (divId = re.exec(value.id)) {
//                if (jQuery('#wp_upgraded_' + what + '_' + divId[1]).val() == 0) {
//                    sitesToUpdate.push(parseInt(divId[1]));
//                    siteNames[parseInt(divId[1])] = jQuery('.mainwp_wordpress_upgrade[site_id="' + parseInt(divId[1]) + '"]').attr('site_name');
//                }
//            }
//        });
//    }
//    else
//    {
        rightnow_show_if_required(what+'_upgrades_'+id, true);
        sitesToUpdate.push(id);
        siteNames[id] = jQuery('div[site_id="' + id + '"]').attr('site_name');
//    }

    return mainwp_rightnow_checkBackups(sitesToUpdate, siteNames);
};
rightnow_upgrade_plugintheme_list = function (what, id, list, noCheck)
{
    rightnowContinueAfterBackup = function(pWhat, pId, pList) { return function()
    {
        var newList = [];
        for (var i = pList.length - 1; i >= 0; i--) {
            var item = pList[i];
            if (document.getElementById('wp_upgraded_' + pWhat + '_' + pId + '_' + item).value == 0) {
                document.getElementById('wp_upgrade_' + pWhat + '_' + pId + '_' + item).innerHTML = __('Upgrading..');
                //jQuery('#wp_upgrade_'+pWhat+'_'+pId+'_'+item).html('Upgrading..');
                document.getElementById('wp_upgraded_' + pWhat + '_' + pId + '_' + item).value = 1;
                //jQuery('#wp_upgraded_'+pWhat+'_'+pId+'_'+item).val(1);
                document.getElementById('wp_upgradebuttons_' + pWhat + '_' + pId + '_' + item).style.display = 'none';
                //jQuery('#wp_upgradebuttons_'+pWhat+'_'+pId+'_'+item).hide();
                newList.push(item);
            }
        }
        if (newList.length > 0) {
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
                        if (res[item]) {
                            document.getElementById('wp_upgrade_' + pWhat + '_' + pId + '_' + item).innerHTML = __('Upgrade successful');
                        }
                        else {
                            document.getElementById('wp_upgrade_' + pWhat + '_' + pId + '_' + item).innerHTML = __('Upgrade failed');
                        }
                    }
                    success = true;
                }
                if (!success) {
                    for (var i = 0; i < newList.length; i++) {
                        var item = newList[i];
                        document.getElementById('wp_upgrade_' + pWhat + '_' + pId + '_' + item).innerHTML = result;
                    }
                }
            }, 'json');

        }

        rightnowContinueAfterBackup = undefined;
    } }(what, id, list);

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


rightnow_show = function (what, leave_text) {
    jQuery('#wp_' + what).toggle(100, 'linear', function () {
        if (!leave_text) {
            if (jQuery('#wp_' + what).css('display') == 'none') {
                jQuery('#mainwp_' + what + '_show').text((what == 'securityissues' ? __('Show All') : __('Show')));
            }
            else {
                jQuery('#mainwp_' + what + '_show').text((what == 'securityissues' ? __('Hide All') : __('Hide')));
            }
        }
    });
    return false;
};

rightnow_show_if_required = function (what, leave_text) {
    jQuery('#wp_' + what).show(100, function() {
        if (!leave_text) {
            jQuery('#mainwp_' + what + '_show').text(__('Hide'));
        }
    });
    return false;
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

    var siteId = jQuery('#backup_exclude_folders').attr('siteid');
    var sites = jQuery('#backup_exclude_folders').attr('sites');
    var groups = jQuery('#backup_exclude_folders').attr('groups');
    if (jQuery('#backup_task_id').val() == undefined) jQuery('#backup_exclude_folders').fileTree({ root: '', script: ajaxurl + '?action=mainwp_site_dirs&site='+encodeURIComponent(siteId == undefined ? '' : siteId)+'&sites='+encodeURIComponent(sites == undefined ? '' : sites)+'&groups='+encodeURIComponent(groups == undefined ? '' : groups), multiFolder: false, postFunction: updateExcludedFolders});
    jQuery('.jqueryFileTree li a').live('mouseover', function() { jQuery(this).children('.exclude_folder_control').show() });
    jQuery('.jqueryFileTree li a').live('mouseout', function() { jQuery(this).children('.exclude_folder_control').hide() });
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

    jQuery('#managebackups-task-status-text').html(dateToHMS(new Date()) + ' ' + __('Starting backup task.'));
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
        appendToDiv('#managebackups-task-status-text', __('Backup task complete') + (manageBackupsTaskError ? ' <font color="red">'+__('with errors')+'</font>' : '') + '.');

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
    appendToDiv('#managebackups-task-status-text', '[' + siteName + '] '+__('Creating backupfile.') + '<div id="managebackups-task-status-create-progress" siteId="'+siteId+'" style="margin-top: 1em;"></div>');

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
                            appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] <font color="red">Error: ' + getErrorMessage(response.error) + '</font>');
                            manageBackupsTaskError = true;
                            managebackups_run_next();
                        }
                        else
                        {
                            appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] '+__('Backupfile created successfully.'));

                            managebackups_backup_download_file(pSiteId, pSiteName, response.result.type, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, pRemoteDestinations);
                        }
                    } }(manageBackupsTaskId, siteId, siteName, manageBackupsTaskRemoteDestinations.slice(0), interVal),
                error: function(pInterVal, pSiteName) { return function() {
                    backupCreateRunning = false;
                    clearInterval(pInterVal);
                    appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] ' + '<font color="red">Error: Backup timed out - <a href="http://docs.mainwp.com/backup-failed-php-ini-settings/">Please check this help document for more information and possible fixes</a></font>');
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
    jQuery.post(ajaxurl, data, function(pFile, pRegexFile, pSubfolder, pRemoteDestinations, pSize, pType, pInterVal, pSiteName, pSiteId) { return function (response) {
        backupDownloadRunning = false;
        clearInterval(pInterVal);
        jQuery('#managebackups-task-status-progress[siteId="'+pSiteId+'"]').progressbar();
        jQuery('#managebackups-task-status-progress[siteId="'+pSiteId+'"]').progressbar('value', pSize);
        appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] '+__('Download from child site completed.'));
        managebackups_backup_upload_file(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize);
    } }(file, regexfile, subfolder, remote_destinations, size, type, interVal, pSiteName, pSiteId), 'json');
};

managebackups_backup_upload_file = function(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize)
{
    if (pRemoteDestinations.length > 0)
    {
        var remote_destination = pRemoteDestinations[0];
        //upload..
        var unique = Date.now();
        appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] '+ __('Uploading to remote destination: %1 (%2)', remote_destination.title, remote_destination.type) + '<div id="managesite-upload-status-progress-' + unique + '" style="margin-top: 1em;"></div>');

        var interVal = undefined;
        jQuery('#managesite-upload-status-progress-'+unique).progressbar({value: 0, max: pSize});
        interVal = setInterval(function() {
            var data = mainwp_secure_data({
                action:'mainwp_backup_upload_getprogress',
                unique: unique
            });
            jQuery.post(ajaxurl, data,  function(pUnique) { return function (response) {
                if (response.error) return;

                if (backupUploadRunning[pUnique])
                {
                    var progressBar = jQuery('#managesite-upload-status-progress-'+pUnique);
                    if ((progressBar.length > 0) && (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max')) && (progressBar.progressbar('option', 'value') < parseInt(response.result)))
                    {
                        progressBar.progressbar('value', response.result);
                    }
                }
            } }(unique), 'json');
        }, 1000);

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
            success: function(pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, pInterval, pUnique) { return function (response) {
                if (response.error) return;

                if (pInterval != undefined)
                {
                    backupUploadRunning[pUnique] = false;
                    clearInterval(pInterval);
                    var progressBar = jQuery('#managesite-upload-status-progress-'+pUnique);
                    progressBar.progressbar();
                    progressBar.progressbar('value', pSize);
                }

                var obj = response.result;
                if (obj.error)
                {
                    manageBackupsError = true;
                    appendToDiv('#managebackups-task-status-text', '<font color="red">[' + pSiteName + '] '+__('Upload to %1 (%2) failed:', obj.title, obj.type) + ' ' + obj.error + '</font>');
                }
                else
                {
                    appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] '+__('Upload to %1 (%2) succesful',  obj.title, obj.type));
                }
                managebackups_backup_upload_file(pSiteId, pSiteName, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize)
            } }(pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSiteName, pSiteId, pSize, interVal, unique),
            dataType: 'json'
        });
    }
    else
    {
        appendToDiv('#managebackups-task-status-text', '[' + pSiteName + '] '+__('Backup complete.'));
        managebackups_run_next();
    }
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
        errors.push('Please enter a valid name for your backup task');
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
        setHtml('#mainwp_managebackups_add_message', __('Adding the task to MainWP'));

        jQuery('#mainwp_managebackups_update').attr('disabled', 'true'); //disable button to add..

        var data = mainwp_secure_data({
            action:'mainwp_updatebackup',
            id:jQuery('#mainwp_managebackups_edit_id').val(),
            name:jQuery('#mainwp_managebackups_add_name').val(),
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
            filename: jQuery('#backup_filename').val()
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
        errors.push('Please enter a valid name for your backup task');
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
            errors.push('Please select websites or groups.');
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
            errors.push(__('Please select websites or groups.'));
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
        setHtml('#mainwp_managebackups_add_message', __('Adding the task to MainWP'));

        jQuery('#mainwp_managebackups_add').attr('disabled', 'true'); //disable button to add..

        jQuery('#mainwp_managesites_add').attr('disabled', 'true'); //Disable add button
        var data = mainwp_secure_data({
            action:'mainwp_addbackup',
            name:jQuery('#mainwp_managebackups_add_name').val(),
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
            filename: jQuery('#backup_filename').val()
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
        jQuery('#task-status-' + id).html(__('Removing the task..'));
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
                result = __('The task has been removed');
            }
            else {
                error = __('An unspecified error occured');
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

    jQuery('#task-status-' + id).html(__('Resuming the task..'));
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
            result = __('The task has been resumed');
        }
        else {
            error = __('An unspecified error occured');
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

    jQuery('#task-status-' + id).html(__('Pausing the task..'));
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
            result = __('The task has been paused');
        }
        else {
            error = __('An unspecified error occured');
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
        if (jQuery('#mainwp_managesites_add_wpname').val() == '') {
            jQuery('#mainwp_managesites_add_wpname').val(jQuery('#mainwp_managesites_add_wpurl').val());
        }
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
                    setHtml('#mainwp_managesites_add_errors', response.sitename+ ': '+__('Connection test failed.')+' '+__('URL:')+' '+response.host+' - '+__('HTTP-code:')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ' - '+__('Error message:')+' ' + response.error + ' <br/> <em>To find out more about what your HTTP status code means please <a href="http://docs.mainwp.com/http-status-codes/" target="_blank">click here</a> to locate your number (' + response.httpCode + ')</em>');
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
                    setHtml('#mainwp_managesites_add_message', response.sitename+ ': '+__('Connection test successful.')+' '+__('URL:')+' '+response.host+' ('+__('Received HTTP-code:')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ')' + ' <br/> <em>To find out more about what your HTTP status code means please <a href="http://docs.mainwp.com/http-status-codes/" target="_blank">click here</a> to locate your number (' + response.httpCode + ')</em>');
                }
                else
                {
                    setHtml('#mainwp_managesites_add_errors', response.sitename+ ': '+__('Connection test failed.')+' '+__('URL:')+' '+response.host+' '+__('Received HTTP-code:')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ' <br/> <em>To find out more about what your HTTP status code means please <a href="http://docs.mainwp.com/http-status-codes/" target="_blank">click here</a> to locate your number (' + response.httpCode + ')</em>');
                }
            }
            else
            {
                setHtml('#mainwp_managesites_add_errors', response.sitename+ ': '+__('Invalid response from the server, please try again.'));
            }
        } }(thisEl, loadingEl),
            dataType: 'json'});
        return false;
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
    
    
};
mainwp_managesites_reconnect = function(pElement, pRightNow)
{
    var parent = pElement.parent();
    parent.html('Trying to reconnect...');

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
                error = 'Undefined error.';
            }
            else {
                error = 'Error - ' + response.substr(6);
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
     
    if (jQuery('#mainwp_managesites_chk_bulkupload').attr('checked')) {        
        if (jQuery('#mainwp_managesites_file_bulkupload').val() == '') {                             
            setHtml('#mainwp_managesites_add_errors', __('Please enter csv file for upload'));
        } else {
            jQuery('#mainwp_managesites_add_form').submit();            
        }
        return;
    }
    
    if (jQuery('#mainwp_managesites_add_wpname').val() == '') {
        errors.push(__('Please enter a name for the website'));
    }
    if (jQuery('#mainwp_managesites_add_wpurl').val() == '') {
        errors.push(__('Please enter a valid URL for your site'));
    }
    else {
        var url = jQuery('#mainwp_managesites_add_wpurl').val();
        if (url.substr(0, 4) != 'http') {
            url = 'http://' + url;
        }
        if (url.substr(-1) != '/') {
            url += '/';
        }
        jQuery('#mainwp_managesites_add_wpurl').val(url);
        if (!isUrl(jQuery('#mainwp_managesites_add_wpurl').val())) {
            errors.push(__('Please enter a valid URL for your site'));
        }
    }
    if (jQuery('#mainwp_managesites_add_wpadmin').val() == '') {
        errors.push(__('Please enter a username for the administrator'));
    }

    if (errors.length > 0) {
        setHtml('#mainwp_managesites_add_errors', errors.join('<br />'));
    }
    else {
        setHtml('#mainwp_managesites_add_message', __('Adding the site to MainWP'));

        jQuery('#mainwp_managesites_add').attr('disabled', 'true'); //disable button to add..

        //Check if valid user & rulewp is installed?
        var url = jQuery('#mainwp_managesites_add_wpurl').val();
        if (url.substr(0, 4) != 'http') {
            url = 'http://' + url;
        }
        if (url.substr(-1) != '/') {
            url += '/';
        }
        var data = mainwp_secure_data({
            action:'mainwp_checkwp',
            name:jQuery('#mainwp_managesites_add_wpname').val(),
            url:url,
            admin:jQuery('#mainwp_managesites_add_wpadmin').val(),
            verify_certificate:jQuery('#mainwp_managesites_verify_certificate').val()
        });

        jQuery.post(ajaxurl, data, function (res_things) {
            response = res_things.response;
            response = jQuery.trim(response);
            var url = jQuery('#mainwp_managesites_add_wpurl').val();
            if (url.substr(0, 4) != 'http') {
                url = 'http://' + url;
            }
            if (url.substr(-1) != '/') {
                url += '/';
            }

            if (response == 'HTTPERROR') {
                errors.push('HTTP error - website does not exist');
            } else if (response == 'NOMAINWP') {
                errors.push(__('No MainWP Child Plugin detected, first install and activate the plugin and add your site to MainWP Dashboard afterwards. Click <a href="%1" target="_blank">here</a> to install <a href="%2" target="_blank">MainWP</a> plugin (do not forget to activate it after installation).', url + 'wp-admin/plugin-install.php?tab=search&type=term&s=mainwp&plugin-search-input=Search+Plugins', url + 'wp-admin/plugin-install.php?tab=search&type=term&s=mainwp&plugin-search-input=Search+Plugins'));
            } else if (response.substr(0, 5) == 'ERROR') {
                if (response.length == 5) {
                    errors.push(__('Undefined error.'));
                }
                else {
                    errors.push('Error - ' + response.substr(6));
                }
            } else if (response == 'OK') {
                jQuery('#mainwp_managesites_add').attr('disabled', 'true'); //Disable add button
                var groupids = [];
                jQuery("input[name='selected_groups[]']:checked").each(function (i) {
                    groupids.push(jQuery(this).val());
                });

                var data = mainwp_secure_data({
                    action:'mainwp_addwp',
                    managesites_add_wpname:jQuery('#mainwp_managesites_add_wpname').val(),
                    managesites_add_wpurl:url,
                    managesites_add_wpadmin:jQuery('#mainwp_managesites_add_wpadmin').val(),
                    managesites_add_uniqueId:jQuery('#mainwp_managesites_add_uniqueId').val(),
                    'groupids[]':groupids,
                    groupnames:jQuery('#mainwp_managesites_add_addgroups').val(),
                    verify_certificate:jQuery('#mainwp_managesites_verify_certificate').val()
                });

                jQuery.post(ajaxurl, data, function (res_things) {
					if (res_things.error)
					{
						response = 'ERROR ' + res_things.error;
					}
					else
					{
						response = res_things.response;
					}
                    response = jQuery.trim(response);
                    managesites_init();

                    if (response.substr(0, 5) == 'ERROR') {
                        setHtml('#mainwp_managesites_add_errors', response.substr(6));
                    }
                    else {
                        //Message the WP was added
                        setHtml('#mainwp_managesites_add_message', response);

                        //Reset fields
                        jQuery('#mainwp_managesites_add_wpname').val('');
                        jQuery('#mainwp_managesites_add_wpurl').val('http://');
                        jQuery('#mainwp_managesites_add_wpadmin').val('');
                        jQuery('#mainwp_managesites_add_uniqueId').val('');
                        jQuery('#mainwp_managesites_add_addgroups').val('');
                        jQuery("input[name='selected_groups[]']:checked").attr('checked', false);
                        jQuery('#mainwp_managesites_verify_certificate').val(1);                        
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

                setHtml('#mainwp_managesites_add_errors', errors.join('<br />'));
            }
        }, 'json');
    }
};
mainwp_managesites_test = function (event) {
    managesites_init();

    var errors = [];

    if (jQuery('#mainwp_managesites_test_wpurl').val() == '') {
        errors.push(__('Please enter a valid URL for your site'));
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
        setHtml('#mainwp_managesites_test_message', __('Testing the connection'));

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
            test_verify_cert: jQuery('#mainwp_managesites_test_verifycertificate').val()
        });
        jQuery.post(ajaxurl, data, function (response) {
            managesites_init();
            jQuery('#mainwp_managesites_test').removeAttr('disabled'); //Enable add button

            if (response.error)
            {
                if (response.httpCode)
                {
                    setHtml('#mainwp_managesites_test_errors', __('Connection test failed.')+' '+__('URL:')+' '+response.host+' - '+__('HTTP-code:')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ' - '+__('Error message:')+' ' + response.error + ' <br/> <em>To find out more about what your HTTP status code means please <a href="http://docs.mainwp.com/http-status-codes/" target="_blank">click here</a> to locate your number (' + response.httpCode + ')</em>');
                }
                else
                {
                    setHtml('#mainwp_managesites_test_errors', __('Connection test failed.')+' '+__('Error message:')+' ' + response.error);
                }
            }
            else if (response.httpCode)
            {
                if (response.httpCode == '200')
                {
                    setHtml('#mainwp_managesites_test_message', __('Connection test successful') + ' ('+__('URL:')+' '+response.host+' - '+__('Received HTTP-code')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ')' + ' <br/> <em>To find out more about what your HTTP status code means please <a href="http://docs.mainwp.com/http-status-codes/" target="_blank">click here</a> to locate your number (' + response.httpCode + ')</em>');
                }
                else
                {
                    setHtml('#mainwp_managesites_test_errors', __('Connection test failed.')+' '+__('URL:')+' '+response.host+' - '+ __('Received HTTP-code:')+' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ' <br/> <em>To find out more about what your HTTP status code means please <a href="http://docs.mainwp.com/http-status-codes/" target="_blank">click here</a> to locate your number (' + response.httpCode + ')</em>');
                }
            }
            else
            {
                setHtml('#mainwp_managesites_test_errors', __('Invalid response from the server, please try again.'));
            }
        }, 'json');
    }
};
managesites_remove = function (id) {
    managesites_init();

    var q = confirm(__('Are you sure you want to delete this site?'));
    if (q) {
        jQuery('#site-status-' + id).html(__('Removing and deactivating the MainWP Child plugin..'));
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
                result = __('The site has been removed and the MainWP Child plugin has been disabled');
            } else if (response.result == 'NOSITE') {
                error = __('The requested site has not been found');
            }
            else {
                result = __('The site has been removed but the MainWP Child plugin could not be disabled');
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

    jQuery('#mainwp_managesites_test').live('click', function (event) {
        mainwp_managesites_test(event);
    });
    
    jQuery('#mainwp_managesites_chk_bulkupload').live('click', function (event) {
        if (jQuery(this).attr('checked')) {
            jQuery('#mainwp_managesites_file_bulkupload').removeAttr('disabled'); //Enable
            jQuery('#mainwp_managesites_chk_header_first').removeAttr('disabled'); //Enable
        } else {
            jQuery('#mainwp_managesites_file_bulkupload').attr('disabled', 'true'); // Disable
            jQuery('#mainwp_managesites_chk_header_first').attr('disabled', 'true'); // Disable            
        }
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
        jQuery('#mainwp_managesites_btn_import').val(__('Finished'));
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
    var import_items = import_line.split(',');

    var import_wpname = import_items[0];
    var import_wpurl = import_items[1];
    var import_wpadmin = import_items[2];
    var import_wpgroups = import_items[3];    
    var import_uniqueId = import_items[4];

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
  
    var import_current_line = import_wpname + ',' + import_wpurl + ',' + import_wpadmin + ',' + import_wpgroups + ',' + import_uniqueId + '\r';
    
    jQuery('#mainwp_managesites_import_logging .log').append('[' + import_current + '] ' + import_current_line);
        
    var errors = [];
    
    if (import_wpname == '') {
        errors.push(__('Please enter the Site name.'));
    }
   
    if (import_wpurl == '') {        
        errors.push(__('Please enter the Site url.'));
    }
   
    if (import_wpadmin == '') {  
        errors.push(__('Please enter Admin name of the site.'));
    }
    
    if (errors.length > 0) {        
        jQuery('#mainwp_managesites_import_logging .log').append('[' + import_current + ']>> Error - ' + errors.join(" ") + '\n');
        jQuery('#mainwp_managesites_import_fail_logging .log').append(import_current_line);
        import_count_fails++;
        mainwp_managesites_import_sites();
        return;
    }
    
    var data = mainwp_secure_data({
            action:'mainwp_checkwp',
            name: import_wpname,
            url: import_wpurl,
            admin: import_wpadmin,
            check_me: import_current   
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
                         
            if (response == 'HTTPERROR') {                
                errors.push(check_result + __('HTTP error - website does not exist'));
            } else if (response == 'NOMAINWP') {
                errors.push(check_result + __('No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. Click <a href="%1" target="_blank">here</a> to install <a href="%2" target="_blank">MainWP</a> plugin (do not forget to activate it after installation)', url + 'wp-admin/plugin-install.php?tab=search&type=term&s=mainwp&plugin-search-input=Search+Plugins', url + 'wp-admin/plugin-install.php?tab=search&type=term&s=mainwp&plugin-search-input=Search+Plugins'));
            } else if (response.substr(0, 5) == 'ERROR') {
                if (response.length == 5) {
                    errors.push(check_result + __('Undefined error.'));
                }
                else {
                    errors.push(check_result + 'Error - ' + response.substr(6));
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
                    add_me: import_current                    
                });

                jQuery.post(ajaxurl, data, function (res_things) {
					if (res_things.error)
					{
						response = 'ERROR ' + res_things.error;
					}
					else
					{
						response = res_things.response;
					}
                    var add_result = '[' + res_things.add_me + ']>> ';
                    
                    response = jQuery.trim(response);
                    
                    if (response.substr(0, 5) == 'ERROR') {
                        jQuery('#mainwp_managesites_import_fail_logging .log').append(import_current_line);
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
                        jQuery('#mainwp_managesites_import_fail_logging .log').append(import_current_line);
                        jQuery('#mainwp_managesites_import_logging .log').append("error: " + errorThrown +"\n");
                        import_count_fails++;
                        mainwp_managesites_import_sites();
                });
            }
                        
            if (errors.length > 0) {    
                jQuery('#mainwp_managesites_import_fail_logging .log').append(import_current_line);
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
        parent.find('.selected_cats').html('<p class="remove">'+__('No selected Categories')+'</p>');  // remove all categories
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
            selected_categories: encodeURIComponent(selected_categories.join(','))
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
    var siteItems = jQuery(this).prev().find('.mainwp_selected_sites_item');
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
    var siteItems = jQuery(this).prev().find('.mainwp_selected_groups_item');
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
        if (jQuery('#user_chk_bulkupload').is(':checked')) {  
            mainwp_bulkupload_users();           
        } else
            mainwp_createuser(event);
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
        jQuery('#pass1').parent().parent().addClass('form-invalid');
        cont = false;
    }
    else {
        jQuery('#pass1').parent().parent().removeClass('form-invalid');
    }

    if (jQuery('#pass2').val() == '') {
        jQuery('#pass2').parent().parent().addClass('form-invalid');
        cont = false;
    }
    else {
        jQuery('#pass2').parent().parent().removeClass('form-invalid');
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
        jQuery('#MainWPBulkAddUserLoading').show();
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
            jQuery('#MainWPBulkAddUserLoading').hide();
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
                jQuery('#MainWPBulkAddUser').html(response);
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
  
    jQuery('#user_chk_bulkupload').live('click', function () {
        if (jQuery(this).attr('checked')) {
            jQuery('#import_user_file_bulkupload').removeAttr('disabled'); //Enable
            jQuery('#import_user_chk_header_first').removeAttr('disabled'); //Enable
        } else {
            jQuery('#import_user_file_bulkupload').attr('disabled', 'true'); // Disable
            jQuery('#import_user_chk_header_first').attr('disabled', 'true'); // Disable            
        }
    });
     
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
        show_error('ajax-error-zone', __('Please enter csv file for upload.'));
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
        jQuery('#import_user_import_logging .log').append('\n' + __('Number of Users to Import: %1 Created users: %2 Failed: %3', import_user_total_import, import_user_count_created_users, import_user_count_create_fails) + '\n');
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
        errors.push(__('Please enter the username.'));
    }
   
    if (import_user_email == '') {        
        errors.push(__('Please enter the email.'));
    }
   
    if (import_user_passw == '') {  
        errors.push(__('Please enter the password.'));
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
        if (response_data.error != undefined) return; //todo: add handling

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
        return mainwp_install_nav(event, 'search');
    });
    jQuery('#MainWPInstallBulkNavUpload').live('click', function (event) {
        return mainwp_install_nav(event, 'upload');
    });
});
mainwp_install_nav = function (ev, what) {
    var params = getUrlParameters();
    var data = {
        action:'mainwp_installbulknav' + what,
        page:params['page']
    };
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        if (what == 'upload') {
            jQuery('#MainWPInstallBulkNavSearch').removeClass('mainwp_action_down');
            jQuery('#MainWPInstallBulkNavUpload').addClass('mainwp_action_down');
        }
        else {
            jQuery('#MainWPInstallBulkNavUpload').removeClass('mainwp_action_down');
            jQuery('#MainWPInstallBulkNavSearch').addClass('mainwp_action_down');
        }
        jQuery('#MainWPInstallBulkAjax').html(response);
    });
    return false;
};
mainwp_install_prev = function (ev) {
    newp = parseInt(jQuery('#MainWPInstallBulkPage').html()) - 1;
    if (newp > 0) {
        jQuery('#MainWPInstallBulkStatus').html(__('Loading previous page..'));
        jQuery('#MainWPInstallBulkStatusExtra').css('display', 'inline-block');
        mainwp_install_searchhelp(jQuery('#mainwp_installbulk_s').val(), jQuery('#mainwp_installbulk_typeselector').val(), newp);
    }
    return false;
};
mainwp_install_next = function (ev) {
    newp = parseInt(jQuery('#MainWPInstallBulkPage').html()) + 1;
    maxp = parseInt(jQuery('#MainWPInstallBulkPages').html());
    if (newp <= maxp) {
        jQuery('#MainWPInstallBulkStatus').html(__('Loading next page..'));
        jQuery('#MainWPInstallBulkStatusExtra').css('display', 'inline-block');
        mainwp_install_searchhelp(jQuery('#mainwp_installbulk_s').val(), jQuery('#mainwp_installbulk_typeselector').val(), newp);
    }
    return false;
};
mainwp_install_search = function (ev) {
    jQuery('#MainWPInstallBulkStatus').html(__('Searching the Wordpress repository..'));
    jQuery('#MainWPInstallBulkStatusExtra').css('display', 'inline-block');
    mainwp_install_searchhelp(jQuery('#mainwp_installbulk_s').val(), jQuery('#mainwp_installbulk_typeselector').val(), 1);
    return false;
};
mainwp_install_searchhelp = function (in_s, in_type, in_currpage) {
    var params = getUrlParameters();
    var data = {
        action:'mainwp_installbulksearch',
        s:in_s,
        type:in_type,
        currpage:in_currpage,
        page:params['page']
    };
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#MainWPInstallBulkStatus').html('');
        jQuery('#MainWPInstallBulkStatusExtra').css('display', 'none');
        if (splitted = /^([0-9]+) ([0-9]+) ([0-9]+) ([\s\S]*)/.exec(response)) {
            if (splitted[3] == 0) {
                jQuery('#MainWPInstallBulkSearchAjax').html(splitted[4]);
                jQuery('#MainWPInstallBulkNav').css('display', 'none');
                jQuery('#MainWPInstallBulkPage').html(0);
            }
            else {
                jQuery('#MainWPInstallBulkPage').html(splitted[1]);
                jQuery('#MainWPInstallBulkPages').html(splitted[2]);
                jQuery('#MainWPInstallBulkResults').html(splitted[3] + ' items');
                jQuery('#MainWPInstallBulkSearchAjax').html(splitted[4]);
                jQuery('#MainWPInstallBulkNav').css('display', 'inline-block');
            }
            mainwp_install_set_install_links();
        }
    });
    return false;
};
mainwp_install_set_install_links = function (event) {
    jQuery('a[id^="install-"]').each(function (index, value) {
        if (divId = /^install-([^\-]*)-(.*)$/.exec(value.id)) {
            jQuery(value).bind('click', function (event, what, slug) {
                return function () {
                    mainwp_install_bulk(what, slug);
                    return false;
                }
            }(event, divId[1], divId[2]));
        }
    });
};
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
            show_error('ajax-error-zone', __('Please select websites or groups on the right side to install files.'));
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
            show_error('ajax-error-zone', __('Please select websites or groups on the right side to install files.'));
            jQuery('#selected_groups').addClass('form-invalid');
            return;
        }
        else {
            jQuery('#selected_groups').removeClass('form-invalid');
        }
        data['selected_groups[]'] = selected_groups;
    }

    jQuery.post(ajaxurl, data, function (pType, pActivatePlugin, pOverwrite) { return function (response) {
        var installQueue = '<h3>Installing ' + type + '</h3>';
        for (var siteId in response.sites)
        {
            var site = response.sites[siteId];
            installQueue += '<span class="siteBulkInstall" siteid="' + siteId + '" status="queue"><strong>' + site['name'] + '</strong>: <span class="queue">'+__('Queued')+'</span><span class="progress"><img src="' + mainwpParams['image_url'] + 'loader.gif"> '+__('In progress')+'</span><span class="status"></span></span><br />';
        }

        jQuery('#mainwp_wrap-inside').html(installQueue);
        mainwp_install_bulk_start_next(pType, response.url, pActivatePlugin, pOverwrite);
    } }(type, jQuery('#chk_activate_plugin').is(':checked'), jQuery('#chk_overwrite').is(':checked')), 'json');
    jQuery('#mainwp_wrap-inside').html('<img src="' + mainwpParams['image_url'] + 'loader.gif"> '+__('Preparing %1 installation.', type));
};

bulkInstallMaxThreads = 3;
bulkInstallCurrentThreads = 0;

mainwp_install_bulk_start_next = function(pType, pUrl, pActivatePlugin, pOverwrite)
{
    while ((siteToInstall = jQuery('.siteBulkInstall[status="queue"]:first')) && (siteToInstall.length > 0)  && (bulkInstallCurrentThreads < bulkInstallMaxThreads))
    {
        mainwp_install_bulk_start_specific(pType, pUrl, pActivatePlugin, pOverwrite, siteToInstall);
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
                statusEl.html(__('Installation successful'));
            }
            else if ((response.errors != undefined) && (response.errors[pSiteToInstall.attr('siteid')] != undefined))
            {
                statusEl.html(__('Installation failed')+': ' + response.errors[pSiteToInstall.attr('siteid')][1]);
                statusEl.css('color', 'red');
            }
            else
            {
                statusEl.html(__('Installation failed'));
                statusEl.css('color', 'red');
            }

            bulkInstallCurrentThreads--;
            mainwp_install_bulk_start_next(pType, pUrl, pActivatePlugin, pOverwrite);
        }
    }(pType, pUrl, pActivatePlugin, pOverwrite, pSiteToInstall), 'json');
};

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

    var files = [];
    jQuery(".qq-upload-file").each(function (i) {
        if (jQuery(this).parent().attr('class').replace(/^\s+|\s+$/g, "") == 'qq-upload-success') {
            files.push(jQuery(this).html());
        }
    });
    data['files[]'] = files;

    jQuery.post(ajaxurl, data, function(pType, pFiles, pActivatePlugin, pOverwrite) { return function (response) {
        var installQueue = '<h3>Installing ' + pFiles.length + ' ' + pType + (pFiles.length > 1 ? 's' : '') + '</h3>';
        for (var siteId in response.sites)
        {
            var site = response.sites[siteId];
            installQueue += '<span class="siteBulkInstall" siteid="' + siteId + '" status="queue"><strong>' + site['name'] + '</strong>: <span class="queue">'+__('Queued')+'</span><span class="progress"><img src="' + mainwpParams['image_url'] + 'loader.gif"> '+__('In progress')+'</span><span class="status"></span></span><br />';
        }

        jQuery('#mainwp_wrap-inside').html(installQueue);
        mainwp_upload_bulk_start_next(pType, response.urls, pActivatePlugin, pOverwrite);
    } }(type, files, jQuery('#chk_activate_plugin_upload').is(':checked'), jQuery('#chk_overwrite_upload').is(':checked')), 'json');

    jQuery('#mainwp_wrap-inside').html('<img src="' + mainwpParams['image_url'] + 'loader.gif"> '+__('Preparing %1 installation.', type));
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
                statusEl.html(__('Installation successful'));
            }
            else if ((response.errors != undefined) && (response.errors[pSiteToInstall.attr('siteid')] != undefined))
            {
                statusEl.html(__('Installation failed')+': ' + response.errors[pSiteToInstall.attr('siteid')][1]);
                statusEl.css('color', 'red');
            }
            else
            {
                statusEl.html(__('Installation failed'));
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
    jQuery('#backup_btnSubmit').attr('disabled', 'true');
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
    var data = mainwp_secure_data({
        action:'mainwp_backup',
        site_id:jQuery('#backup_site_id').val(),

        type:type,
        exclude:jQuery('#excluded_folders_list').val(),
        excludebackup: (jQuery('#mainwp-known-backup-locations').attr('checked') ? 1 : 0),
        excludecache: (jQuery('#mainwp-known-cache-locations').attr('checked') ? 1 : 0),
        excludenonwp: (jQuery('#mainwp-non-wordpress-folders').attr('checked') ? 1 : 0),
        excludezip: (jQuery('#mainwp-zip-archives').attr('checked') ? 1 : 0),
        filename: fileName,
        fileNameUID: fileNameUID,

        subfolder:jQuery('#backup_subfolder').val()
    });

    jQuery('#managesite-backup-status-text').html(dateToHMS(new Date()) + ' '+__('Creating the backupfile on the child installation, this might take a while depending on the size. Please be patient.') +' <div id="managesite-createbackup-status-progress" style="margin-top: 1em;"></div>');
    jQuery('#managesite-createbackup-status-progress').progressbar({value: 0, max: size});
    jQuery('#managesite-backup-status-box').dialog({
        resizable: false,
        height: 350,
        width: 500,
        modal: true,
        close: function(event, ui) { if (!backupError) { location.reload(); }}});

    var interVal = setInterval(function() {
        var data = mainwp_secure_data({
            action:'mainwp_createbackup_getfilesize',
            siteId: jQuery('#backup_site_id').val(),
            type: type,
            fileName: fileName,
            fileNameUID: fileNameUID
        });
        jQuery.post(ajaxurl, data,function (response) {
            if (response.error) return;

            if (backupCreateRunning)
            {
                var progressBar = jQuery('#managesite-createbackup-status-progress');
                if (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max'))
                {
                    progressBar.progressbar('value', response.size);
                }
            }
        }, 'json');
    }, 1000);

    backupCreateRunning = true;
    jQuery.ajax({url: ajaxurl,
                data: data,
                method: 'POST',
                success: function(pSiteId, pRemoteDestinations, pInterVal) { return function (response) {
                    backupCreateRunning = false;
                    clearInterval(pInterVal);

                    var progressBar = jQuery('#managesite-createbackup-status-progress');
                    progressBar.progressbar('value', parseFloat(progressBar.progressbar('option', 'max')));

        if (response.error)
        {
            appendToDiv('#managesite-backup-status-text', ' <font color="red">Error:' + getErrorMessage(response.error) + '</font>');
        }
        else
        {
            appendToDiv('#managesite-backup-status-text', __('Backupfile on child site created successfully.'));

            backup_download_file(pSiteId, response.result.type, response.result.url, response.result.local, response.result.regexfile, response.result.size, response.result.subfolder, pRemoteDestinations);
        }

    } }(jQuery('#backup_site_id').val(), remote_destinations, interVal), error: function(pInterVal) { return function() {backupCreateRunning = false;clearInterval(pInterVal);appendToDiv('#managesite-backup-status-text', ' <font color="red">Error: Backup timed out - <a href="http://docs.mainwp.com/backup-failed-php-ini-settings/">Please check this help document for more information and possible fixes</a></font>');} }(interVal), dataType: 'json'});
};

backup_download_file = function(pSiteId, type, url, file, regexfile, size, subfolder, remote_destinations)
{
    appendToDiv('#managesite-backup-status-text', __('Downloading the file.')+' <div id="managesite-backup-status-progress" style="margin-top: 1em;"></div>');
    jQuery('#managesite-backup-status-progress').progressbar({value: 0, max: size});
    var interVal = setInterval(function() {
        var data = mainwp_secure_data({
            action:'mainwp_backup_getfilesize',
            local: file
        });
        jQuery.post(ajaxurl, data,function (response) {
            if (response.error) return;

            if (backupDownloadRunning)
            {
                var progressBar = jQuery('#managesite-backup-status-progress');
                if (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max'))
                {
                    progressBar.progressbar('value', response.result);
                }
            }
        }, 'json');
    }, 500);

    var data = mainwp_secure_data({
        action:'mainwp_backup_download_file',
        site_id: pSiteId,
        type: type,
        url: url,
        local: file
    });
    backupDownloadRunning = true;
    jQuery.post(ajaxurl, data, function(pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pSize, pType, pInterVal) { return function (response) {
        backupDownloadRunning = false;
        clearInterval(pInterVal);
        jQuery('#managesite-backup-status-progress').progressbar();
        jQuery('#managesite-backup-status-progress').progressbar('value', pSize);
        appendToDiv('#managesite-backup-status-text', __('Download from child site completed.'));
        backup_upload_file(pSiteId, pFile, pRegexFile, pSubfolder, pRemoteDestinations, pType, pSize);
    } }(pSiteId, file, regexfile, subfolder, remote_destinations, size, type, interVal), 'json');
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

        var interVal = undefined;
        jQuery('#managesite-upload-status-progress-'+unique).progressbar({value: 0, max: pSize});
        interVal = setInterval(function() {
            var data = mainwp_secure_data({
                action:'mainwp_backup_upload_getprogress',
                unique: unique
            });
            jQuery.post(ajaxurl, data,  function(pUnique) { return function (response) {
                if (response.error) return;

                if (backupUploadRunning[pUnique])
                {
                    var progressBar = jQuery('#managesite-upload-status-progress-'+pUnique);
                    if ((progressBar.length > 0) && (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max')) && (progressBar.progressbar('option', 'value') < parseInt(response.result)))
                    {
                        progressBar.progressbar('value', response.result);
                    }
                }
            } }(unique), 'json');
        }, 1000);

        backupUploadRunning[unique] = true;

        var data = mainwp_secure_data({
            action:'mainwp_backup_upload_file',
            siteId: pSiteId,
            file: pFile,
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
            success: function(pSiteId, pNewRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, pInterVal, pUnique) { return function (response) {
                if (response.error) return;

                if (interVal != undefined)
                {
                    backupUploadRunning[pUnique] = false;
                    clearInterval(pInterVal);
                    var progressBar = jQuery('#managesite-upload-status-progress-'+pUnique);
                    progressBar.progressbar();
                    progressBar.progressbar('value', pSize);
                }

                var obj = response.result;
                if (obj.error)
                {
                    backupError = true;
                    appendToDiv('#managesite-backup-status-text', '<font color="red">' + __('Upload to %1 (%2) failed:', obj.title, obj.type)+' ' + obj.error + '</font>');
                }
                else
                {
                    appendToDiv('#managesite-backup-status-text', __('Upload to %1 (%2) successful.', obj.title, obj.type));
                }
                backup_upload_file(pSiteId, pFile, pRegexFile, pSubfolder, pNewRemoteDestinations, pType, pSize);
            } }(pSiteId, pRemoteDestinations, pFile, pRegexFile, pSubfolder, pType, pSize, interVal, unique),
            dataType: 'json'
        });
    }
    else
    {
        appendToDiv('#managesite-backup-status-text', __('Backup complete.'));
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
 * Offline checks
 */
jQuery(document).ready(function () {
    jQuery('.mainwp_offline_check').live('click', function () {
        offline_check_save(this);
    });

    jQuery('.mainwp_offline_check_bulk').live('click', function () {
        offline_check_save_bulk(this);
    });

    jQuery('.mainwp_offline_check_check').live('click', function () {
        return offline_check_check(this);
    });
    jQuery('#mainwp_offline_check_check_all').live('click', function () {
        return mainwp_offline_check_check_all(this);
    });
});

offline_check_save = function (_this) {
    var parentEl = jQuery(_this).parent().parent();
    var data = {
        action:'mainwp_offline_check_save',
        websiteid:parentEl.find("#offline_check_website_id").val(),
        offline_check:jQuery(_this).val()
    };
    jQuery.post(ajaxurl, data, function(pParentEl) { return function (response) {
        if (jQuery.trim(response) == '1')
        {
            pParentEl.find('.offline_check_saved').stop(true, true);
            pParentEl.find('.offline_check_saved').show();
            pParentEl.find('.offline_check_saved').fadeOut(2000);
        }
    } }(parentEl));
};

offline_check_save_bulk = function (_this) {
    var newVal = jQuery(_this).attr('value');
    jQuery('.mainwp_offline_check').prop('checked', false);
    jQuery('.mainwp_offline_check[value="'+newVal+'"]').prop('checked', true);
    var data = {
        action:'mainwp_offline_check_save_bulk',
        offline_check:newVal
    };
    jQuery.post(ajaxurl, data, function() { return function (response) {
        if (jQuery.trim(response) == '1')
        {
            jQuery('.offline_check_saved').stop(true, true);
            jQuery('.offline_check_saved').show();
            jQuery('.offline_check_saved').fadeOut(2000);
        }
    } }());
};

offline_check_check = function (_this) {
    if (jQuery(_this).text() != 'Check') return false;

    var data = {
        action:'mainwp_offline_check_check',
        websiteid:jQuery(_this).parent().parent().find("#offline_check_website_id").val()
    };
    jQuery(_this).text('Checking');
    jQuery(_this).removeAttr('href');
    jQuery(_this).css('color', 'gray');
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result == 0) return;

        for (var websiteid in response.result)
        {
            var element = jQuery('input[name="offline_check_website_id"][value="'+websiteid+'"]');
            element = element.parent().find('.mainwp_offline_check_check');
            element.text('E-mail sent');
            if (response.result[websiteid] == -1)
            {
                element.parent().parent().find('.down-img').show();
                element.parent().parent().find('.up-img').hide();
            }
            else if (response.result[websiteid] == 1)
            {
                element.parent().parent().find('.down-img').hide();
                element.parent().parent().find('.up-img').show();
            }
        }
    }, 'json');
    return false;
};

mainwp_offline_check_check_all = function (_this) {
    if (jQuery(_this).text() != 'Check All') return false;

    var data = {
        action:'mainwp_offline_check_check'
    };

    jQuery(_this).text('Checking');
    jQuery('.mainwp_offline_check_check').text('Checking');
    jQuery('.mainwp_offline_check_check').css('color', 'gray');

    jQuery.post(ajaxurl, data, function (response) {
        jQuery(_this).text('E-mail sent');

        if (response.result == 0) return;

        for (var websiteid in response.result)
        {
            var element = jQuery('input[name="offline_check_website_id"][value="'+websiteid+'"]');
            element = element.parent().find('.mainwp_offline_check_check');
            element.text('E-mail sent');
            if (response.result[websiteid] == -1)
            {
                element.parent().parent().find('.down-img').show();
                element.parent().parent().find('.up-img').hide();
            }
            else if (response.result[websiteid] == 1)
            {
                element.parent().parent().find('.down-img').hide();
                element.parent().parent().find('.up-img').show();
            }
        }
    }, 'json');
    return false;
};


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
function setHtml(what, text) {
    setVisible(what, true);
    jQuery(what).html('<p>' + text + '</p>');
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
  * MainWPSiteOpen.page
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
    jQuery('#redirectForm').submit();
});
mainwp_notes_show_all = function (id) {
    var url = jQuery('#mainwp_notes_' + id + '_url').html();
    var note = jQuery('#mainwp_notes_' + id + '_note').html();
    jQuery('#mainwp_notes_title').html(url);
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
        note:jQuery('#mainwp_notes_note').val()
    });
    jQuery('#mainwp_notes_status').html('<img src="' + mainwpParams['image_url'] + 'loader.gif"> '+__('Please wait while we are saving your note'));
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
            jQuery('#mainwp_notes_status').html(__('An error occured while saving your message.'));
        }
    }, 'json');
};

/**
  * MainWPPage.page
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
            rowElement.html('<td colspan="7">' + response.result + '</td>');
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
            errors.push('Please select websites or groups.');
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
            errors.push('Please select websites or groups.');
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
        'sites[]':selected_sites
    };

    jQuery('#mainwp_pages_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_pages_loading').hide();
        jQuery('#mainwp_pages_main').show();
        var matches = (response == null ? null : response.match(/page\[\]/g));
        jQuery('#mainwp_pages_total').html(matches == null ? 0 : matches.length);
        jQuery('#the-posts-list').html(response);
        mainwp_pages_table_reinit();
    });
};

/**
 * MainWPPlugins.page
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

        jQuery('#mainwp_notes_title').html(decodeURIComponent(name));
        jQuery('#mainwp_notes_note').val(note);
        jQuery('#mainwp_notes_slug').val(slug);
        mainwp_notes_show();
    });

    jQuery(document).on('click', '#mainwp_trusted_plugin_notes_save', function () {
        var slug = jQuery('#mainwp_notes_slug').val();
        var newnote = jQuery('#mainwp_notes_note').val();
        var data = {
            action:'mainwp_trusted_plugin_notes_save',
            slug:slug,
            note:jQuery('#mainwp_notes_note').val()
        };
        jQuery('#mainwp_notes_status').html('<img src="' + mainwpParams['image_url'] + 'loader.gif"> '+__('Please wait while we are saving your note'));
        jQuery.post(ajaxurl, data, function(pSlug) { return function (response) {
            var rowEl = jQuery('tr[plugin_slug="'+pSlug+'"]');
            if (response.result == 'SUCCESS') {
                jQuery('#mainwp_notes_status').html(__('Note saved.'));
                rowEl.find('.note').html(jQuery('#mainwp_notes_note').val());

                if (newnote == '') {
                    rowEl.find('.mainwp_notes_img').hide();
                }
                else {
                    rowEl.find('.mainwp_notes_img').show();
                }
            }
            else if (response.error != undefined) {
                jQuery('#mainwp_notes_status').html(__('An error occured while saving your message: ') + response.error);
            }
            else {
                jQuery('#mainwp_notes_status').html(__('An error occured while saving your message.'));
            }
        } }(slug), 'json');
        return false;
    });

    jQuery(document).on('click', '.mainwp_trusted_theme_notes_show', function () {
        var rowEl = jQuery(jQuery(this).parents('tr')[0]);
        var slug = rowEl.attr('theme_slug');
        var name = rowEl.attr('theme_name');
        var note = rowEl.find('.note').html();

        jQuery('#mainwp_notes_title').html(decodeURIComponent(name));
        jQuery('#mainwp_notes_note').val(note);
        jQuery('#mainwp_notes_slug').val(slug);
        mainwp_notes_show();
    });

    jQuery(document).on('click', '#mainwp_trusted_theme_notes_save', function () {
        var slug = jQuery('#mainwp_notes_slug').val();
        var newnote = jQuery('#mainwp_notes_note').val();
        var data = {
            action:'mainwp_trusted_theme_notes_save',
            slug:slug,
            note:jQuery('#mainwp_notes_note').val()
        };
        jQuery('#mainwp_notes_status').html('<img src="' + mainwpParams['image_url'] + 'loader.gif"> '+__('Please wait while we are saving your note'));
        jQuery.post(ajaxurl, data, function(pSlug) { return function (response) {
            var rowEl = jQuery('tr[theme_slug="'+pSlug+'"]');
            if (response.result == 'SUCCESS') {
                jQuery('#mainwp_notes_status').html(__('Note saved.'));
                rowEl.find('.note').html(jQuery('#mainwp_notes_note').val());

                if (newnote == '') {
                    rowEl.find('.mainwp_notes_img').hide();
                }
                else {
                    rowEl.find('.mainwp_notes_img').show();
                }
            }
            else if (response.error != undefined) {
                jQuery('#mainwp_notes_status').html(__('An error occured while saving your message: ') + response.error);
            }
            else {
                jQuery('#mainwp_notes_status').html(__('An error occured while saving your message.'));
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
            errors.push(__('Please select websites or groups.'));
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
            errors.push(__('Please select websites or groups.'));
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

    var data = {
        action:'mainwp_plugins_search',
        keyword:jQuery('#mainwp_plugin_search_by_keyword').val(),
        status:jQuery('#mainwp_plugin_search_by_status').val(),
        'groups[]':selected_groups,
        'sites[]':selected_sites
    };

    jQuery('#mainwp_plugins_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_plugins_loading').hide();
        jQuery('#mainwp_plugins_main').show();
        jQuery('#mainwp_plugins_content').html(response);
        jQuery('#mainwp_plugins_loading_info').hide();
    });
};

mainwp_fetch_all_active_plugins = function () {
    var data = {
        action:'mainwp_plugins_search_all_active'
    };

    jQuery('#mainwp_plugins_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_plugins_loading').hide();
        jQuery('#mainwp_plugins_main').show();
        jQuery('#mainwp_plugins_content').html(response);
        mainwp_active_plugins_table_reinit();
    });
};

mainwp_fetch_all_themes = function () {
    var data = {
        action:'mainwp_themes_search_all'
    };

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
  * MainWPPost.page
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
    jQuery('#mainwp_bulk_post_action_apply').live('click', function () {
        var action = jQuery('#mainwp_bulk_action').val();
        if (action == 'none') return false;

        var tmp = jQuery("input[name='post[]']:checked");
        countSent = tmp.length;

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

mainwppost_postAction = function (elem, what) {
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
    var data = mainwp_secure_data({
        action:'mainwp_post_' + what,
        postId:postId,
        websiteId:websiteId
    });
    rowElement.find('.row-actions').hide();
    rowElement.find('.row-actions-working').show();
    jQuery.post(ajaxurl, data, function (response) {
        if (response.error) {
            rowElement.html('<td colspan="9"><font color="red">'+response.error+'</font></td>');
        }
        else if (response.result) {
            rowElement.html('<td colspan="9">' + response.result + '</td>');
        }
        else {
            rowElement.find('.row-actions-working').hide();
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
            errors.push('Please select websites or groups.');
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
            errors.push('Please select websites or groups.');
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
        userId: (userId == undefined ? '' : userId)
    };

    jQuery('#mainwp_posts_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_posts_loading').hide();
        jQuery('#mainwp_posts_main').show();
        var matches = (response == null ? null : response.match(/post\[\]/g));
        jQuery('#mainwp_posts_total').html(matches == null ? 0 : matches.length);
        jQuery('#the-posts-list').empty();
        jQuery('#the-posts-list').html(response);
        mainwp_posts_table_reinit();
    });
};

/**
  * MainWPThemes.page
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
            errors.push(__('Please select websites or groups.'));
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
            errors.push(__('Please select websites or groups.'));
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

    var data = {
        action:'mainwp_themes_search',
        keyword:jQuery('#mainwp_theme_search_by_keyword').val(),
        status:jQuery('#mainwp_theme_search_by_status').val(),
        'groups[]':selected_groups,
        'sites[]':selected_sites
    };

    jQuery('#mainwp_themes_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_themes_loading').hide();
        jQuery('#mainwp_themes_main').show();
        jQuery('#mainwp_themes_content').html(response);
        jQuery('#mainwp_themes_loading_info').hide();
    });
};

/**
  * MainWPUser.page
  */
var userCountSent = 0;
var userCountReceived = 0;
jQuery(document).ready(function () {
    jQuery('#mainwp_show_users').live('click', function () {
        mainwp_fetch_users();
    });

    jQuery('.user_submitdelete').live('click', function () {
        mainwpuser_postAction(jQuery(this), 'delete');
        return false;
    });

    jQuery('#mainwp_bulk_user_action_apply').live('click', function () {
        var action = jQuery('#mainwp_bulk_action').val();
        if (action == 'none') return false;

        var tmp = jQuery("input[name='user[]']:checked");
        userCountSent = tmp.length;

        jQuery('#mainwp_bulk_user_action_apply').attr('disabled', 'true');
        jQuery('#mainwp_bulk_role_action_apply').attr('disabled', 'true');

        tmp.each(
                function (index, elem) {
                    mainwpuser_postAction(elem, action);
                }
        );

        return false;
    });

    jQuery('#mainwp_bulk_role_action_apply').live('click', function () {
        var action = jQuery('#mainwp_bulk_role_action').val();
        if (action == 'none') return false;

        var tmp = jQuery("input[name='user[]']:checked");
        userCountSent = tmp.length;

        jQuery('#mainwp_bulk_role_action_apply').attr('disabled', 'true');
        jQuery('#mainwp_bulk_role_action_apply').attr('disabled', 'true');

        tmp.each(
                function (index, elem) {
                    mainwpuser_postAction(elem, action);
                }
        );

        return false;
    });
    
    jQuery('#mainwp_btn_search_users').live('click', function () {
        mainwp_search_users();
    });
    jQuery('#mainwp_btn_update_password').live('click', function () {
        var errors= [];
        var tmp = jQuery("input[name='user[]']:checked");
        userCountSent = tmp.length;        
        
        if (jQuery('#pass1').val() == '' || jQuery('#pass2').val() == '') {
            errors.push('Password can\'t be empty.');
        }
            
        if (jQuery('#pass1').val() == '') {
            jQuery('#pass1').parent().addClass('form-invalid');           
        }
        else {
            jQuery('#pass1').parent().removeClass('form-invalid');
        }
    
        if (jQuery('#pass2').val() == '') {
            jQuery('#pass2').parent().addClass('form-invalid');            
        }
        else {
            jQuery('#pass2').parent().removeClass('form-invalid');
        }
        
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
            
        if (userCountSent == 0) {
            errors.push(__('Please search and select users for update password.'));
        }
            
        if (errors.length > 0) {
            jQuery('#mainwp_update_password_error').html(errors.join('<br />'));
            jQuery('#mainwp_update_password_error').show();
            return false;
        }
                
        jQuery('#mainwp_update_password_error').hide();
        jQuery('#mainwp_users_password_updating').show();
                
        jQuery('#mainwp_bulk_user_action_apply').attr('disabled', 'true');
        jQuery('#mainwp_bulk_role_action_apply').attr('disabled', 'true');
        jQuery('#mainwp_btn_update_password').attr('disabled', 'true');

        tmp.each(
                function (index, elem) {
                    mainwpuser_postAction(elem, 'update_password');
                }
        );

        return false;
    }); 
});

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

    rowElement.find('.row-actions').hide();
    rowElement.find('.row-actions-working').show();
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            rowElement.html('<td colspan="7">' + response.result + '</td>');
        }
        else {
            rowElement.find('.row-actions-working').hide();
        }
        userCountReceived++;

        if (userCountReceived == userCountSent) {
            userCountReceived = 0;
            userCountSent = 0;
            jQuery('#mainwp_bulk_user_action_apply').removeAttr('disabled');
            jQuery('#mainwp_bulk_role_action_apply').removeAttr('disabled');
            
            jQuery('#mainwp_btn_update_password').removeAttr('disabled');            
            jQuery('#mainwp_users_password_updating').hide();
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
            errors.push(__('Please select websites or groups.'));
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
            errors.push(__('Please select websites or groups.'));
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
    if (role == "") {
        errors.push('Please select a user role.');
    }

    if (errors.length > 0) {
        jQuery('#mainwp_users_error').html(errors.join('<br />'));
        jQuery('#mainwp_users_error').show();
        return;
    }
    else {
        jQuery('#mainwp_users_error').html("");
        jQuery('#mainwp_users_error').hide();
    }

    var data = {
        action:'mainwp_users_search',
        role:role,
        'groups[]':selected_groups,
        'sites[]':selected_sites
    };

    jQuery('#mainwp_users_loading').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_users_loading').hide();
        jQuery('#mainwp_users_main').show();
        var matches = (response == null ? null : response.match(/user\[\]/g));
        jQuery('#mainwp_users_total').html(matches == null ? 0 : matches.length);
        jQuery('#the-list').html(response);
        mainwp_users_table_reinit();
    });
};

// Search users
mainwp_search_users = function () {
    var errors = [];
    var selected_sites = [];
    var selected_groups = [];
    var name = jQuery('#mainwp_search_users').val();    
      
    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function (i) {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push(__('Please select websites or groups.'));
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
            errors.push(__('Please select websites or groups.'));
            jQuery('#selected_groups').addClass('form-invalid');
        }
        else {
            jQuery('#selected_groups').removeClass('form-invalid');
        }
    }

    if (errors.length > 0) {
        jQuery('#mainwp_users_error').html(errors.join('<br />'));
        jQuery('#mainwp_users_error').show();
        return;
    }
    else {
        jQuery('#mainwp_users_error').html("");
        jQuery('#mainwp_users_error').hide();
    }
    
    var data = {
        action:'mainwp_users_query',
        search: name,        
        'groups[]':selected_groups,
        'sites[]':selected_sites
    };
    
    jQuery('#mainwp_users_searching').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        jQuery('#mainwp_users_searching').hide();
        jQuery('#mainwp_users_main').show();
        var matches = (response == null ? null : response.match(/user\[\]/g));
        jQuery('#mainwp_users_total').html(matches == null ? 0 : matches.length);
        jQuery('#the-list').html(response);
        mainwp_users_table_reinit();
    });    
};

jQuery(document).ready(function(){
	jQuery('.mainwp_datepicker').datepicker();
});


getErrorMessage = function(pError)
{
    if (pError.message == 'HTTPERROR') {
        return __('HTTP error')+' - ' + pError.extra;
    }
    else if (pError.message == 'NOMAINWP') {
        var error = '';
        if (pError.extra)
        {
            error = __('No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. If you continue experiencing this issue please  test your connection <a href="admin.php?page=managesites&do=test&site=%1">here</a> or post as much information as possible on the error in the <a href="http://mainwp.com/forum/">support forum</a>.', encodeURIComponent(pError.extra));
        }
        else
        {
            error = __('No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. If you continue experiencing this issue please post as much information as possible on the error in the <a href="http://mainwp.com/forum/">support forum</a>.');
        }

        return error;
    }
    else if (pError.message == 'ERROR') {
        return 'Error' + ((pError.extra != '') && (pError.extra != undefined) ? ' - ' + pError.extra : '');
    }
    else if (pError.message == 'WPERROR') {
        return __('Error on your child wordpress') + ((pError.extra != '') && (pError.extra != undefined) ? ' - ' + pError.extra : '');
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
    if (siteId == '-1')
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

mainwp_secure_data = function(data)
{
    if (data['action'] == undefined) return data;

    data['security'] = security_nonces[data['action']];
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



jQuery(document).ready(function($) {
    jQuery('.mainwp-show-qsg').on('click', function(){
        jQuery('.mainwp-qsg').hide();
        var num = jQuery(this).attr('number');
        jQuery('.mainwp-qsg[number="' + num + '"]').show();
        mainwp_setCookie('qsg_number', jQuery(this).attr('number'));
        return false;
    });

    jQuery('#mainwp-quick-start-guide').on('click', function () {
       // if(mainwp_getCookie('mainwp_quick_guide') == 'on')
       //     mainwp_setCookie('mainwp_quick_guide', '');
       // else
            mainwp_setCookie('mainwp_quick_guide', 'on');
            mainwp_showhide_quick_guide();

        return false;
    });
    jQuery('#mainwp-qsg-dismiss').on('click', function () {
        mainwp_setCookie('mainwp_quick_guide', '');
        mainwp_showhide_quick_guide();
        return false;
    });

});

mainwp_showhide_quick_guide = function(show, tut) {
    var show = mainwp_getCookie('mainwp_quick_guide');
    var tut = mainwp_getCookie('qsg_number');
    if (typeof tut == "undefined" || !tut)
        tut = 1;
    if (show == 'on') {
        jQuery('#mainwp-qsg-tips').show();
        jQuery('#mainwp-quick-start-guide').hide();
        mainwp_showhide_quick_tut();
 } else {
        jQuery('#mainwp-qsg-tips').hide();
        jQuery('#mainwp-quick-start-guide').show();
 }
};

mainwp_showhide_quick_tut = function() {
    var tut = mainwp_getCookie('qsg_number');
    jQuery('.mainwp-qsg').hide();
    jQuery('.mainwp-qsg[number="' + tut + '"]').show();
};


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

jQuery(document).on('click', '#mainwp-ext-dismiss', function()
{
    jQuery('#mainwp-ext-notice').hide();

 return false;
});

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
    } catch(e){ console.log( e ); }
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


jQuery(document).on('click', '#mainwp-events-notice-dismiss', function()
{
    jQuery('#mainwp-events-notice').hide();
    var data = {
        action:'mainwp_events_notice_hide'        
    };
    jQuery.post(ajaxurl, data, function (res) {
    });
    return false;
});