/* eslint complexity: ["error", 100] */
//Ignore plugin
jQuery(document).ready(function () {
    jQuery(document).on('click', 'input[name="plugins"]', function () {
        if (jQuery(this).is(':checked')) {
            jQuery('input[name="plugins"]').attr('checked', 'checked');
            jQuery('input[name="plugin[]"]').attr('checked', 'checked');
        } else {
            jQuery('input[name="plugins"]').prop("checked", false);
            jQuery('input[name="plugin[]"]').prop("checked", false);
        }
    });
    jQuery(document).on('click', 'input[name="themes"]', function () {
        if (jQuery(this).is(':checked')) {
            jQuery('input[name="themes"]').attr('checked', 'checked');
            jQuery('input[name="theme[]"]').attr('checked', 'checked');
        } else {
            jQuery('input[name="themes"]').prop("checked", false);
            jQuery('input[name="theme[]"]').prop("checked", false);
        }
    });

    jQuery(document).on('click', '#mainwp-bulk-trust-plugins-action-apply', function () {

        var action = jQuery("#mainwp-bulk-actions").dropdown("get value");

        if (action == 'none')
            return false;

        var slugs = jQuery.map(jQuery("input[name='plugin[]']:checked"), function (el) {
            return jQuery(el).val();
        });

        if (slugs.length == 0)
            return false;

        jQuery('#mainwp-bulk-trust-plugins-action-apply').attr('disabled', 'true');

        var data = mainwp_secure_data({
            action: 'mainwp_trust_plugin',
            slugs: slugs,
            do: action
        });

        jQuery.post(ajaxurl, data, function () {
            jQuery('#mainwp-bulk-trust-plugins-action-apply').prop("disabled", false);
            mainwp_fetch_all_active_plugins();
        }, 'json');

        return false;
    });
    jQuery(document).on('click', '#mainwp-bulk-trust-themes-action-apply', function () {
        var action = jQuery("#mainwp-bulk-actions").dropdown("get value");
        if (action == 'none')
            return false;

        var slugs = jQuery.map(jQuery("input[name='theme[]']:checked"), function (el) {
            return jQuery(el).val();
        });
        if (slugs.length == 0)
            return false;

        jQuery('#mainwp-bulk-trust-themes-action-apply').attr('disabled', 'true');

        var data = mainwp_secure_data({
            action: 'mainwp_trust_theme',
            slugs: slugs,
            do: action
        });

        jQuery.post(ajaxurl, data, function () {
            jQuery('#mainwp-bulk-trust-themes-action-apply').prop("disabled", false);
            mainwp_fetch_all_themes();
        }, 'json');

        return false;
    });
});


/**
 * MainWP_Plugins.page
 */
jQuery(document).ready(function () {
    jQuery(document).on('click', '#mainwp-show-plugins', function () {
        mainwp_fetch_plugins();
    });

    jQuery(document).on('click', '#mainwp-install-to-selected-sites', function () {
        var checkedVals = jQuery('.mainwp-manage-plugin-item-website .mainwp-selected-plugin-site:checked').map(function () {
            var rowElement = jQuery(this).closest('.mainwp-manage-plugin-item-website');
            var val = rowElement.attr("site-id");
            return val;
        }).get();

        var selectedIds = [];
        if (checkedVals instanceof Array) {
            jQuery.grep(checkedVals, function (val) {
                if (jQuery.inArray(val, selectedIds) == -1) {
                    selectedIds.push(val);
                }
            });
        }
        console.log(selectedIds);
        if (selectedIds.length == 0) {
            feedback('mainwp-message-zone', __('Please select at least one website.'), 'yellow');
            return false;
        } else {
            jQuery('#mainwp-message-zone').fadeOut(5000);
            var ids = selectedIds.join("-");
            var kwd = jQuery('#mainwp_plugin_search_by_keyword').val();
            if ('' != kwd) {
                kwd = '&s=' + encodeURIComponent(kwd);
            }
            location.href = 'admin.php?page=PluginsInstall&selected_sites=' + ids + kwd;
        }
        return false;
    });

    jQuery('#mainwp-plugins-content .checkbox').on('click', function () {
        if (jQuery('.mainwp-manage-plugin-item-website .checkbox.checked').length > 0) {
            jQuery('#mainwp-install-to-selected-sites').show();
        } else {
            jQuery('#mainwp-install-to-selected-sites').hide();
        }
    });

    jQuery('#mainwp_show_all_active_plugins').on('click', function () {
        mainwp_fetch_all_active_plugins();
        return false;
    });

    var pluginCountSent;
    var pluginCountReceived;
    var pluginResetAllowed = true;

    jQuery(document).on('click', '#mainwp-do-plugins-bulk-actions', function () {
        var action = jQuery("#mainwp-bulk-actions").dropdown("get value");
        console.log(action);
        if (action == '') {
            return false;
        }

        jQuery(this).attr('disabled', 'true');
        jQuery('#mainwp_bulk_action_loading').show();
        pluginResetAllowed = false;
        pluginCountSent = 0;
        pluginCountReceived = 0;
        var selectedSites = [];
        var selectedSitePlugins = [];

        //Find all checked boxes
        jQuery('.mainwp-selected-plugin-site:checked').each(function () {
            var rowElement = jQuery(this).closest('.mainwp-manage-plugin-item-website');
            var websiteId = jQuery(rowElement).attr('site-id');
            var pluginSlug = jQuery(rowElement).attr('plugin-slug');
            selectedSitePlugins.push({ 'siteid': websiteId, 'plugin': pluginSlug });
            if (selectedSites.indexOf(websiteId) < 0) {
                selectedSites.push(websiteId);
            }
        });

        jQuery(selectedSites).each(function (idx, val) {
            var websiteId = val;
            var selectedPlugins = [];

            jQuery(selectedSitePlugins).each(function (idx, val) {
                if (val.siteid == websiteId) {
                    if (jQuery('.mainwp-manage-plugin-item-website[site-id="' + val.siteid + '"][plugin-slug="' + val.plugin + '"]').length > 0) {
                        selectedPlugins.push(jQuery('.mainwp-manage-plugin-item-website[site-id="' + val.siteid + '"][plugin-slug="' + val.plugin + '"]')[0]);
                    }
                }
            });

            if (selectedPlugins.length == 0)
                return;


            if ((action == 'activate') || (action == 'delete') || (action == 'deactivate') || (action == 'ignore_updates')) {
                var pluginsToSend = [];
                var namesToSend = [];
                for (var i = 0; i < selectedPlugins.length; i++) {
                    pluginsToSend.push(jQuery(selectedPlugins[i]).attr('plugin-slug'));
                    namesToSend.push(jQuery(selectedPlugins[i]).attr('plugin-name'));
                }

                var data = mainwp_secure_data({
                    action: 'mainwp_plugin_' + action,
                    plugins: pluginsToSend,
                    websiteId: websiteId
                });

                if (action == 'ignore_updates') {
                    data['names'] = namesToSend;
                }

                pluginCountSent++;
                jQuery.post(ajaxurl, data, function () {
                    pluginCountReceived++;
                    if (pluginResetAllowed && pluginCountReceived == pluginCountSent) {
                        pluginCountReceived = 0;
                        pluginCountSent = 0;
                        jQuery('#mainwp_bulk_action_loading').hide();
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
            mainwp_fetch_plugins();
        }
    });

    jQuery(document).on('click', '.mainwp-edit-plugin-note', function () {
        var rowEl = jQuery(jQuery(this).parents('tr')[0]);
        var slug = rowEl.attr('plugin-slug');
        var name = rowEl.attr('plugin-name');
        var note = rowEl.find('.esc-content-note').html();
        jQuery('#mainwp-notes-title').html(decodeURIComponent(name));
        jQuery('#mainwp-notes-html').html(note == '' ? __('No saved notes. Click the Edit button to edit plugin notes.') : note);
        jQuery('#mainwp-notes-note').val(note);
        jQuery('#mainwp-notes-slug').val(slug);
        mainwp_notes_show();
    });

    mainwp_notes_plugin_save = function () {
        var slug = jQuery('#mainwp-notes-slug').val();
        var newnote = jQuery('#mainwp-notes-note').val();
        newnote = newnote.replace(/(?:\r\n|\r|\n)/g, '<br>');
        var data = mainwp_secure_data({
            action: 'mainwp_trusted_plugin_notes_save',
            slug: slug,
            note: newnote
        });

        jQuery('#mainwp-notes-status').html('<i class="notched circle loading icon"></i> ' + __('Saving note. Please wait...'));

        jQuery.post(ajaxurl, data, function (pSlug) {
            return function (response) {
                var rowEl = jQuery('tr[plugin-slug="' + pSlug + '"]');
                if (response.result == 'SUCCESS') {
                    jQuery('#mainwp-notes-status').html('<i class="check circle green icon"></i> ' + __('Note saved!'));
                    rowEl.find('.esc-content-note').html(jQuery('#mainwp-notes-note').val());

                    if (newnote == '') {
                        rowEl.find('.mainwp-edit-plugin-note').html('<i class="sticky note outline icon"></i>');
                    } else {
                        rowEl.find('.mainwp-edit-plugin-note').html('<i class="sticky green note icon"></i>');
                    }

                } else if (response.error != undefined) {
                    jQuery('#mainwp-notes-status').html('<i class="times circle red icon"></i> ' + __('Undefined error occured while saving your note') + ': ' + response.error);
                } else {
                    jQuery('#mainwp-notes-status').html('<i class="times circle red icon"></i> ' + __('Undefined error occured while saving your note') + '.');
                }
            }
        }(slug), 'json');
        return false;
    }

    jQuery(document).on('click', '.mainwp-edit-theme-note', function () {
        var rowEl = jQuery(jQuery(this).parents('tr')[0]);
        var slug = rowEl.attr('theme-slug');
        var name = rowEl.attr('theme-name');
        var note = rowEl.find('.esc-content-note').html();
        jQuery('#mainwp-notes-modal').removeClass('edit-mode');
        jQuery('#mainwp-notes-title').html(decodeURIComponent(name));
        jQuery('#mainwp-notes-html').html(note == '' ? 'No saved notes. Click the Edit button to edit theme notes.' : note);
        jQuery('#mainwp-notes-note').val(note);
        jQuery('#mainwp-notes-slug').val(slug);
        mainwp_notes_show();
    });

    mainwp_notes_theme_save = function () {
        var slug = jQuery('#mainwp-notes-slug').val();
        var newnote = jQuery('#mainwp-notes-note').val();
        newnote = newnote.replace(/(?:\r\n|\r|\n)/g, '<br>');
        var data = mainwp_secure_data({
            action: 'mainwp_trusted_theme_notes_save',
            slug: slug,
            note: newnote
        });

        jQuery('#mainwp-notes-status').html('<i class="notched circle loading icon"></i> ' + __('Saving note. Please wait...'));

        jQuery.post(ajaxurl, data, function (pSlug) {
            return function (response) {
                var rowEl = jQuery('tr[theme-slug="' + pSlug + '"]');
                if (response.result == 'SUCCESS') {
                    jQuery('#mainwp-notes-status').html('<i class="check circle green icon"></i> ' + __('Note saved!'));
                    rowEl.find('.esc-content-note').html(jQuery('#mainwp-notes-note').val());
                    if (newnote == '') {
                        rowEl.find('.mainwp-edit-theme-note').html('<i class="sticky note outline icon"></i>');
                    } else {
                        rowEl.find('.mainwp-edit-theme-note').html('<i class="sticky green note icon"></i>');
                    }
                } else if (response.error != undefined) {
                    jQuery('#mainwp-notes-status').html('<i class="times circle red icon"></i> ' + __('Undefined error occured while saving your note!') + ': ' + response.error);
                } else {
                    jQuery('#mainwp-notes-status').html('<i class="times circle red icon"></i> ' + __('Undefined error occured while saving your note!'));
                }
            }
        }(slug), 'json');
        return false;
    }
});

mainwp_show_hide_install_to_selected_sites = function (what) {
    if ('plugin' == what) {
        jQuery('#mainwp-plugins-content .checkbox').on('click', function () {
            if (jQuery('.mainwp-manage-plugin-item-website .checkbox.checked').length > 0) {
                jQuery('#mainwp-install-to-selected-sites').show();
            } else {
                jQuery('#mainwp-install-to-selected-sites').hide();
            }
        });
    } else {
        jQuery('#mainwp-themes-content .checkbox').on('click', function () {
            if (jQuery('.mainwp-manage-theme-item-website .checkbox.checked').length > 0) {
                jQuery('#mainwp-install-themes-to-selected-sites').show();
            } else {
                jQuery('#mainwp-install-themes-to-selected-sites').hide();
            }
        });
    }
}

// Manage Plugins -- Fetch plugins
mainwp_fetch_plugins = function () {
    var errors = [];
    var selected_sites = [];
    var selected_groups = [];
    var selected_clients = [];

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function () {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push(__('Please select at least one website or group or client.'));
        }
    } else if (jQuery('#select_by').val() == 'client') {
        jQuery("input[name='selected_clients[]']:checked").each(function () {
            selected_clients.push(jQuery(this).val());
        });
        if (selected_clients.length == 0) {
            errors.push(__('Please select at least one website or group or client.'));
        }
    } else {
        jQuery("input[name='selected_groups[]']:checked").each(function () {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push(__('Please select at least one website or group or client.'));
        }
    }


    var _status = jQuery("#mainwp_plugins_search_by_status").dropdown("get value");

    if (_status == null) {
        errors.push(__('Please select at least one plugin status.'));
    }

    if (errors.length > 0) {
        jQuery('#mainwp-message-zone').html(errors.join('<br />'));
        jQuery('#mainwp-message-zone').addClass('yellow');
        jQuery('#mainwp-message-zone').show();
        return;
    } else {
        jQuery('#mainwp-message-zone').html('');
        jQuery('#mainwp-message-zone').removeClass('yellow');
        jQuery('#mainwp-message-zone').hide();
    }
    var data = mainwp_secure_data({
        action: 'mainwp_plugins_search',
        keyword: jQuery('#mainwp_plugin_search_by_keyword').val(),
        status: _status,
        not_criteria: jQuery('#display_sites_not_meeting_criteria').is(':checked'),
        'groups[]': selected_groups,
        'sites[]': selected_sites,
        'clients[]': selected_clients
    });

    jQuery('#mainwp-loading-plugins-row').show();

    jQuery.post(ajaxurl, data, function (response) {
        jQuery('#mainwp-loading-plugins-row').hide();
        jQuery('#mainwp-plugins-main-content').show();

        if (response && response.result) {
            jQuery('#mainwp-plugins-content').html(response.result);
            jQuery('#mainwp-plugins-bulk-actions-wapper').html(response.bulk_actions);
            jQuery('#mainwp-plugins-bulk-actions-wapper .ui.dropdown').dropdown();
        }
    }, 'json');
};

// Fetch plugins for the Auto Update feature
mainwp_fetch_all_active_plugins = function () {
    var data = mainwp_secure_data({
        action: 'mainwp_plugins_search_all_active',
        keyword: jQuery("#mainwp_au_plugin_keyword").val(),
        status: jQuery("#mainwp_au_plugin_trust_status").val(),
        plugin_status: jQuery("#mainwp_au_plugin_status").val()
    });

    jQuery('#mainwp-auto-updates-plugins-content').find('.dimmer').addClass('active');

    jQuery.post(ajaxurl, data, function (response) {
        response = response.trim();
        jQuery('#mainwp-auto-updates-plugins-content').find('.dimmer').removeClass('active');
        jQuery('#mainwp-auto-updates-plugins-table-wrapper').html(response);
    });
};

// Fetch themes for the Auto Update feature
mainwp_fetch_all_themes = function () {
    var data = mainwp_secure_data({
        action: 'mainwp_themes_search_all',
        keyword: jQuery("#mainwp_au_theme_keyword").val(),
        status: jQuery("#mainwp_au_theme_trust_status").val(),
        theme_status: jQuery("#mainwp_au_theme_status").val()
    });

    jQuery('#mainwp-auto-updates-themes-content').find('.dimmer').addClass('active');

    jQuery.post(ajaxurl, data, function (response) {
        jQuery('#mainwp-auto-updates-themes-content').find('.dimmer').removeClass('active');
        jQuery('#mainwp-auto-updates-themes-table-wrapper').html(response);
    });
};



/**
 * MainWP_Themes.page
 */
jQuery(document).ready(function () {
    jQuery(document).on('click', '#mainwp_show_themes', function () {
        mainwp_fetch_themes();
    });

    jQuery(document).on('click', '#mainwp-install-themes-to-selected-sites', function () {
        var checkedVals = jQuery('.mainwp-manage-theme-item-website .mainwp-selected-theme-site:checked').map(function () {
            var rowElement = jQuery(this).closest('.mainwp-manage-theme-item-website');
            var val = rowElement.attr('site-id');
            return val;
        }).get();

        var selectedIds = [];
        if (checkedVals instanceof Array) {
            jQuery.grep(checkedVals, function (val) {
                if (jQuery.inArray(val, selectedIds) == -1) {
                    selectedIds.push(val);
                }
            });
        }

        if (selectedIds.length == 0) {
            feedback('mainwp-message-zone', __('Please select at least one website.'), 'yellow');
            return false;
        } else {
            jQuery('#mainwp-message-zone').fadeOut(5000);
            var ids = selectedIds.join("-");
            var kwd = jQuery('#mainwp_theme_search_by_keyword').val();
            if ('' != kwd) {
                kwd = '&s=' + encodeURIComponent(kwd);
            }
            location.href = 'admin.php?page=ThemesInstall&selected_sites=' + ids + kwd;
        }
        return false;
    });

    jQuery('#mainwp-themes-content .checkbox').on('click', function () {
        if (jQuery('.mainwp-manage-theme-item-website .checkbox.checked').length > 0) {
            jQuery('#mainwp-install-themes-to-selected-sites').show();
        } else {
            jQuery('#mainwp-install-themes-to-selected-sites').hide();
        }
    });


    jQuery(document).on('click', '#mainwp_show_all_active_themes', function () {
        mainwp_fetch_all_themes();
        return false;
    });

    var themeCountSent;
    var themeCountReceived;
    var themeResetAllowed = true;

    jQuery(document).on('click', '#mainwp-do-themes-bulk-actions', function () {
        var action = jQuery("#mainwp-bulk-actions").dropdown("get value");
        console.log(action);
        if (action == '' || action == 'none')
            return;

        jQuery('#mainwp-do-themes-bulk-actions').attr('disabled', 'true');
        jQuery('#mainwp_bulk_action_loading').show();
        themeResetAllowed = false;
        themeCountSent = 0;
        themeCountReceived = 0;
        var selectedSites = [];
        var selectedSiteThemes = [];

        //Find all checked boxes
        jQuery('.mainwp-selected-theme-site:checked').each(function () {
            var rowElement = jQuery(this).closest('.mainwp-manage-theme-item-website');
            var websiteId = jQuery(rowElement).attr('site-id');
            var theme = jQuery(rowElement).attr('theme-slug');
            selectedSiteThemes.push({ 'siteid': websiteId, 'theme': theme });
            if (selectedSites.indexOf(websiteId) < 0) {
                selectedSites.push(websiteId);
            }
        });


        jQuery(selectedSites).each(function (idx, val) {
            var websiteId = val;
            var selectedThemes = [];

            jQuery(selectedSiteThemes).each(function (idx, val) {
                if (val.siteid == websiteId) {
                    if (jQuery('.mainwp-manage-theme-item-website[site-id="' + val.siteid + '"][theme-slug="' + val.theme + '"]').length > 0) {
                        selectedThemes.push(jQuery('.mainwp-manage-theme-item-website[site-id="' + val.siteid + '"][theme-slug="' + val.theme + '"]')[0]);
                    }
                }
            });

            if (selectedThemes.length == 0)
                return;

            if (action == 'activate' || action == 'ignore_updates') {
                var themeToActivate = jQuery(selectedThemes[0]).attr('theme-slug');
                var themesToSend = [];
                var namesToSend = [];

                var data = mainwp_secure_data({
                    action: 'mainwp_theme_' + action,
                    websiteId: websiteId
                });

                if (action == 'ignore_updates') {
                    for (var i = 0; i < selectedThemes.length; i++) {
                        themesToSend.push(jQuery(selectedThemes[i]).attr('theme-slug'));
                        namesToSend.push(jQuery(selectedThemes[i]).attr('theme-name'));
                    }
                    data['themes'] = themesToSend;
                    data['names'] = namesToSend;
                } else {
                    data['theme'] = themeToActivate;
                }

                themeCountSent++;
                jQuery.post(ajaxurl, data, function () {
                    themeCountReceived++;
                    if (themeResetAllowed && themeCountReceived == themeCountSent) {
                        themeCountReceived = 0;
                        themeCountSent = 0;
                        jQuery('#mainwp_bulk_action_loading').hide();
                        jQuery('#mainwp_themes_loading_info').show();
                        mainwp_fetch_themes();
                    }
                });
            } else if (action == 'delete') {
                var themesToDelete = [];
                for (var i = 0; i < selectedThemes.length; i++) {
                    if (jQuery(selectedThemes[i]).attr('not-delete') == 1) {
                        jQuery(selectedThemes[i]).find('.mainwp-selected-theme-site').attr('checked', false);
                        continue;
                    }
                    themesToDelete.push(jQuery(selectedThemes[i]).attr('theme-slug'));
                }
                if (themesToDelete.length == 0) {
                    return;
                }
                var data = mainwp_secure_data({
                    action: 'mainwp_theme_delete',
                    themes: themesToDelete,
                    websiteId: websiteId
                });

                themeCountSent++;
                jQuery.post(ajaxurl, data, function (response) {
                    if (response.error != undefined && response.error == Object(response.error)) { // check if .error is object.
                        entries = Object.entries(response.error);
                        for (var entry of entries) {
                            warnings = __(entry[0], encodeURIComponent(entry[1])); // entry[0]:id message, entry[1] string value.
                            jQuery('#mainwp-message-zone').after('<div class="ui info message yellow"><i class="ui close icon"></i><span>' + warnings + '</span></div>');
                        }
                    }
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
    });

});


// Manage Themes -- Fetch themes from child sites
mainwp_fetch_themes = function () {
    var errors = [];
    var selected_sites = [];
    var selected_groups = [];
    var selected_clients = [];

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function () {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push(__('Please select at least one website or group or client.'));
        }
    } else if (jQuery('#select_by').val() == 'client') {
        jQuery("input[name='selected_clients[]']:checked").each(function () {
            selected_clients.push(jQuery(this).val());
        });
        if (selected_clients.length == 0) {
            errors.push(__('Please select at least one website or group or client.'));
        }
    } else {
        jQuery("input[name='selected_groups[]']:checked").each(function () {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push(__('Please select at least one website or group or client.'));
        }
    }

    var _status = jQuery("#mainwp_themes_search_by_status").dropdown("get value");
    if (_status == null) {
        errors.push(__('Please select at least one theme status.'));
    }

    if (errors.length > 0) {
        jQuery('#mainwp-message-zone').html(errors.join('<br />'));
        jQuery('#mainwp-message-zone').addClass('yellow');
        jQuery('#mainwp-message-zone').show();
        return;
    } else {
        jQuery('#mainwp-message-zone').html('');
        jQuery('#mainwp-message-zone').removeClass('yellow');
        jQuery('#mainwp-message-zone').hide();
    }

    var data = mainwp_secure_data({
        action: 'mainwp_themes_search',
        keyword: jQuery('#mainwp_theme_search_by_keyword').val(),
        status: _status,
        not_criteria: jQuery('#display_sites_not_meeting_criteria').is(':checked'),
        'groups[]': selected_groups,
        'sites[]': selected_sites,
        'clients[]': selected_clients
    });

    jQuery('#mainwp-loading-themes-row').show();

    jQuery.post(ajaxurl, data, function (response) {
        jQuery('#mainwp-loading-themes-row').hide();
        jQuery('#mainwp-themes-main-content').show();
        if (response && response.result) {
            jQuery('#mainwp-themes-content').html(response.result);
            jQuery('#mainwp-themes-bulk-actions-wapper').html(response.bulk_actions);
            jQuery('#mainwp-themes-bulk-actions-wapper .ui.dropdown').dropdown();
        }
    }, 'json');
};

/**
 * Plugins manages.
 */
jQuery(document).ready(function () {
    jQuery(document).on('click', '.mainwp-manage-plugin-deactivate', function () {
        manage_plugin_Action(jQuery(this), 'deactivate');
        return false;
    });
    jQuery(document).on('click', '.mainwp-manage-plugin-activate', function () {
        manage_plugin_Action(jQuery(this), 'activate');
        return false;
    });
    jQuery(document).on('click', '.mainwp-manage-plugin-delete', function () {
        manage_plugin_Action(jQuery(this), 'delete');
        return false;
    });
});

manage_plugin_Action = function (elem, what) {
    var rowElement = jQuery(elem).closest('.mainwp-manage-plugin-item-website');
    var plugin = rowElement.attr('plugin-slug');
    var websiteId = rowElement.attr('site-id');

    var data = mainwp_secure_data({
        action: 'mainwp_widget_plugin_' + what, // same with the widgets.
        plugin: plugin,
        websiteId: websiteId
    });
    var parent = jQuery(elem).closest('.column.column-actions');
    jQuery(parent).html('<i class="notched circle loading icon"></i>' + __('Please wait...') + '</div>');
    jQuery.post(ajaxurl, data, function (response) {
        if (response && response.error) {
            jQuery(parent).html('<span data-tooltip="' + response.error + '" data-inverted="" data-position="left center"><i class="times red icon"></i></span>');
        } else if (response && response.result) {
            if (what == 'delete') {
                jQuery(rowElement).html('<i class="check circle green icon"></i> ' + response.result);
                setTimeout(function () {
                    jQuery(rowElement).fadeOut(1000);
                }, 1000);

            } else {
                jQuery(parent).html('<i class="check circle green icon"></i> ' + response.result);
            }
            setTimeout(function () {
                mainwp_fetch_plugins();
            }, 3000);
        } else {
            jQuery(parent).html('<span data-tooltip="Undefined error occured. Please try again." data-inverted="" data-position="left center"><i class="times red icon"></i></span>');
        }
    }, 'json');

    return false;
};


manage_plugins_upgrade = function (slug, websiteid) {
    var msg = __('Are you sure you want to update the plugin on the selected site?');
    mainwp_confirm(msg, function () {
        return manage_plugins_upgrade_int(slug, websiteid);
    }, false, 1);
};


manage_plugins_upgrade_int = function (slug, websiteId) {
    var websiteHolder = jQuery('.mainwp-manage-plugin-item-website[plugin-slug="' + slug + '"][site-id="' + websiteId + '"]');
    websiteHolder.find('.column.update-column').html('<i class="notched circle loading icon"></i> ' + __('Updating. Please wait...'));

    manage_plugins_upgrade_continueAfterBackup = function () {
        console.log('plugin upgrade continue');
        return function () {
            var data = mainwp_secure_data({
                action: 'mainwp_upgradeplugintheme',
                websiteId: websiteId,
                type: 'plugin',
                slug: slug
            });
            jQuery.ajax({
                type: "POST",
                url: ajaxurl,
                data: data,
                success: function (siteHolder) {
                    return function (response) {
                        if (response.error) {
                            var extErr = getErrorMessageInfo(response.error, 'ui')
                            siteHolder.find('.column.update-column').html(extErr);
                        } else {
                            var res = response.result;
                            var res_error = response.result_error;
                            if (res[slug]) {
                                siteHolder.attr('updated', 1);
                                siteHolder.find('.column.update-column').html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Update successful', 'mainwp') + '"><i class="green check icon"></i></span>');
                            } else if (res_error[slug]) {
                                siteHolder.find('.column.update-column').html('<span data-inverted="" data-position="left center" data-tooltip="' + res_error[slug] + '"><i class="red times icon"></i></span>');
                            } else {
                                siteHolder.find('.column.update-column').html('<i class="red times icon"></i>');
                            }
                            setTimeout(function () {
                                mainwp_fetch_plugins();
                            }, 3000);
                        }
                    }
                }(websiteHolder),
                tryCount: 0,
                retryLimit: 3,
                endError: function (siteHolder) {
                    return function () {
                        siteHolder.find('.column.update-column').html('<i class="red times icon"></i>');
                    }
                }(websiteHolder),
                error: function (xhr) {
                    this.tryCount++;
                    if (this.tryCount >= this.retryLimit) {
                        this.endError();
                        return;
                    }

                    var fnc = function (pRqst, pXhr) {
                        return function () {
                            if (pXhr.status == 404) {
                                //handle error
                                jQuery.ajax(pRqst);
                            } else if (pXhr.status == 500) {
                                //handle error
                            } else {
                                //handle error
                            }
                        }
                    }(this, xhr);
                    setTimeout(fnc, 500);
                },
                dataType: 'json'
            });

            manage_plugins_upgrade_continueAfterBackup = undefined;
        }();
    };

    if (mainwpParams['disable_checkBackupBeforeUpgrade'] == true) {
        if (manage_plugins_upgrade_continueAfterBackup != undefined) {
            manage_plugins_upgrade_continueAfterBackup();
        }
        return false;
    }

    var sitesToUpdate = [websiteId]; var siteNames = []
    var siteNames = []
    siteNames[websiteId] = jQuery(websiteHolder).attr('site-name');

    return mainwp_manages_checkBackups(sitesToUpdate, siteNames, manage_plugins_upgrade_continueAfterBackup);
};


/**
 * Themes manage.
 */
jQuery(document).ready(function () {
    jQuery(document).on('click', '.mainwp-manages-theme-activate', function () {
        manages_themeAction(jQuery(this), 'activate');
        return false;
    });
    jQuery(document).on('click', '.mainwp-manages-theme-delete', function () {
        manages_themeAction(jQuery(this), 'delete');
        return false;
    });
});

manages_themeAction = function (elem, what) {
    var rowElement = jQuery(elem).closest('.mainwp-manage-theme-item-website');
    var theme = rowElement.attr('theme-slug');
    var websiteId = rowElement.attr('site-id');

    var data = mainwp_secure_data({
        action: 'mainwp_widget_theme_' + what, // same with theme widget.
        theme: theme,
        websiteId: websiteId
    });

    var parent = jQuery(elem).closest('.column.column-actions');
    jQuery(parent).html('<i class="notched circle loading icon"></i>' + __('Please wait...') + '</div>');

    jQuery.post(ajaxurl, data, function (response) {
        if (response && response.error) {
            jQuery(parent).html('<span data-tooltip="' + response.error + '" data-inverted="" data-position="left center"><i class="times red icon"></i></span>');
        } else if (response && response.result) {
            if (what == 'delete') {
                jQuery(rowElement).html('<i class="check circle green icon"></i> ' + response.result);
                setTimeout(function () {
                    jQuery(rowElement).fadeOut(1000);
                }, 1000);

            } else {
                jQuery(parent).html('<i class="check circle green icon"></i> ' + response.result);
            }
            setTimeout(function () {
                mainwp_fetch_themes();
            }, 3000);
        } else {
            jQuery(parent).html('<span data-tooltip="Undefined error occured. Please try again." data-inverted="" data-position="left center"><i class="times red icon"></i></span>');
        }
    }, 'json');

    return false;
};


manage_themes_upgrade_theme = function (slug, websiteid) {
    var msg = __('Are you sure you want to update the theme on the selected site?');
    mainwp_confirm(msg, function () {
        return manage_themes_upgrade_int(slug, websiteid);
    }, false, 1);
};


manage_themes_upgrade_int = function (slug, websiteId) {
    var websiteHolder = jQuery('.mainwp-manage-theme-item-website[theme-slug="' + slug + '"][site-id="' + websiteId + '"]');
    websiteHolder.find('.column.update-column').html('<i class="notched circle loading icon"></i> ' + __('Updating. Please wait...'));
    manage_themes_upgrade_continueAfterBackup = function () {
        console.log('theme upgrade continue');
        return function () {
            var data = mainwp_secure_data({
                action: 'mainwp_upgradeplugintheme',
                websiteId: websiteId,
                type: 'theme',
                slug: slug
            });
            jQuery.ajax({
                type: "POST",
                url: ajaxurl,
                data: data,
                success: function (pSlug, siteHolder) {
                    return function (response) {
                        if (response.error) {
                            var extErr = getErrorMessageInfo(response.error, 'ui')
                            siteHolder.find('.column.update-column').html(extErr);
                        } else {
                            var res = response.result;
                            if (res[pSlug]) {
                                siteHolder.attr('updated', 1);
                                siteHolder.find('.column.update-column').html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Update successful', 'mainwp') + '"><i class="green check icon"></i></span>');
                            } else {
                                siteHolder.find('.column.update-column').html('<i class="red times icon"></i>');
                            }
                            setTimeout(function () {
                                mainwp_fetch_themes();
                            }, 3000);
                        }
                    }
                }(slug, websiteHolder),
                tryCount: 0,
                retryLimit: 3,
                endError: function (siteHolder) {
                    return function () {
                        siteHolder.find('.column.update-column').html('<i class="red times icon"></i>');
                    }
                }(websiteHolder),
                error: function (xhr) {
                    this.tryCount++;
                    if (this.tryCount >= this.retryLimit) {
                        this.endError();
                        return;
                    }
                    var fnc = function (pRqst, pXhr) {
                        return function () {
                            if (pXhr.status == 404) {
                                //handle error
                                jQuery.ajax(pRqst);
                            } else if (pXhr.status == 500) {
                                //handle error
                            } else {
                                //handle error
                            }
                        }
                    }(this, xhr);
                    setTimeout(fnc, 500);
                },
                dataType: 'json'
            });
            manage_themes_upgrade_continueAfterBackup = undefined;
        }();
    };

    if (mainwpParams['disable_checkBackupBeforeUpgrade'] == true) {
        if (manage_themes_upgrade_continueAfterBackup != undefined) {
            manage_themes_upgrade_continueAfterBackup();
        }
        return false;
    }

    var sitesToUpdate = [websiteId];
    var siteNames = []
    siteNames[websiteId] = jQuery(websiteHolder).attr('site-name');

    return mainwp_manages_checkBackups(sitesToUpdate, siteNames, manage_themes_upgrade_continueAfterBackup);
};


/**
 * Check Backups.
 */
mainwp_manages_checkBackups = function (sitesToUpdate, siteNames, continueAfterBackup) {
    managesitesShowBusyFunction = function () {
        var output = __('Checking if a backup is required for the selected updates...');
        mainwpPopup('#managesites-backup-box').getContentEl().html(output);
        jQuery('#managesites-backup-all').hide();
        jQuery('#managesites-backup-ignore').hide();
        mainwpPopup('#managesites-backup-box').init({
            title: __("Checking backup settings..."), callback: function () {
                bulkManageSitesTaskRunning = false;
                window.location.href = location.href;
            }
        });

    };

    var managesitesShowBusyTimeout = setTimeout(managesitesShowBusyFunction, 300);

    //Step 2: Check if backups are ok.
    var data = mainwp_secure_data({
        action: 'mainwp_checkbackups',
        sites: sitesToUpdate
    });

    jQuery.ajax({
        type: "POST",
        url: ajaxurl,
        data: data,
        success: function (pSiteNames) {
            return function (response) {
                clearTimeout(managesitesShowBusyTimeout);

                mainwpPopup('#managesites-backup-box').close();
                var siteFeedback = undefined;

                if (response['result'] && response['result']['sites'] != undefined) {
                    siteFeedback = [];
                    for (var currSiteId in response['result']['sites']) {
                        if (response['result']['sites'][currSiteId] == false) {
                            siteFeedback.push(currSiteId);
                        }
                    }
                    if (siteFeedback.length == 0)
                        siteFeedback = undefined;
                }

                if (siteFeedback != undefined) {
                    var backupPrimary = '';
                    if (response['result']['primary_backup'] && response['result']['primary_backup'] != undefined)
                        backupPrimary = response['result']['primary_backup'];

                    if (backupPrimary == '') {
                        jQuery('#managesites-backup-all').show();
                        jQuery('#managesites-backup-ignore').show();
                    } else {
                        var backupLink = mainwp_get_primaryBackup_link(backupPrimary);
                        jQuery('#managesites-backup-now').attr('href', backupLink).show();
                        jQuery('#managesites-backup-ignore').val(__('Proceed with Updates')).show();
                    }

                    var output = '<span class="mainwp-red">' + __('A full backup has not been taken in the last days for the following sites:') + '</span><br /><br />';
                    if (backupPrimary == '') { // default backup feature
                        for (var j = 0; j < siteFeedback.length; j++) {
                            output += '<span class="managesites-backup-site" siteid="' + siteFeedback[j] + '">' + decodeURIComponent(pSiteNames[siteFeedback[j]]) + '</span><br />';
                        }
                    } else {
                        for (var j = 0; j < siteFeedback.length; j++) {
                            output += '<span>' + decodeURIComponent(pSiteNames[siteFeedback[j]]) + '</span><br />';
                        }
                    }
                    mainwpPopup('#managesites-backup-box').getContentEl().html(output);
                    console.log(typeof continueAfterBackup);
                    mainwpPopup('#managesites-backup-box').init({
                        title: __("Full backup required!"), callback: function () {
                            continueAfterBackup = undefined;
                            window.location.href = location.href;
                        }
                    });

                    return false;
                }
                if (continueAfterBackup != undefined) {
                    continueAfterBackup();
                }

            }
        }(siteNames),
        error: function () {
            mainwpPopup('#managesites-backup-box').close(true);
        },
        dataType: 'json'
    });

    return false;
};
