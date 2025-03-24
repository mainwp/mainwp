/* eslint-disable complexity */

window.mainwpVars = window.mainwpVars || {};

//Ignore plugin
jQuery(function () {
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

        let action = jQuery("#mainwp-bulk-actions").dropdown("get value");

        if (action == 'none')
            return;

        let slugs = jQuery.map(jQuery("input[name='plugin[]']:checked"), function (el) {
            return jQuery(el).val();
        });

        if (slugs.length == 0)
            return;

        jQuery('#mainwp-bulk-trust-plugins-action-apply').attr('disabled', 'true');

        let data = mainwp_secure_data({
            action: 'mainwp_trust_plugin',
            slugs: slugs,
            do: action
        });

        jQuery.post(ajaxurl, data, function () {
            jQuery('#mainwp-bulk-trust-plugins-action-apply').prop("disabled", false);
            mainwp_fetch_all_active_plugins();
        }, 'json');
    });
    jQuery(document).on('click', '#mainwp-bulk-trust-themes-action-apply', function () {
        let action = jQuery("#mainwp-bulk-actions").dropdown("get value");
        if (action == 'none')
            return;

        let slugs = jQuery.map(jQuery("input[name='theme[]']:checked"), function (el) {
            return jQuery(el).val();
        });
        if (slugs.length == 0)
            return;

        jQuery('#mainwp-bulk-trust-themes-action-apply').attr('disabled', 'true');

        let data = mainwp_secure_data({
            action: 'mainwp_trust_theme',
            slugs: slugs,
            do: action
        });

        jQuery.post(ajaxurl, data, function () {
            jQuery('#mainwp-bulk-trust-themes-action-apply').prop("disabled", false);
            mainwp_fetch_all_themes();
        }, 'json');
    });
});


// Manage Plugins -- Fetch plugins
window.mainwp_fetch_plugins = function () {
    let errors = [];
    let selected_sites = [];
    let selected_groups = [];
    let selected_clients = [];

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


    let _status = jQuery("#mainwp_plugins_search_by_status").dropdown("get value");

    if (_status == null) {
        errors.push(__('Please select at least one plugin status.'));
    }

    if (errors.length > 0) {
        mainwp_set_message_zone('#mainwp-message-zone', errors.join('<br />'), 'yellow');
        return;
    } else {
        mainwp_set_message_zone('#mainwp-message-zone');
    }
    let data = mainwp_secure_data({
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

        if (response?.result) {
            jQuery('#mainwp-plugins-content').html(response.result);
            jQuery('#mainwp-plugins-bulk-actions-wapper').html(response.bulk_actions);
            jQuery('#mainwp-plugins-bulk-actions-wapper .ui.dropdown').dropdown();
        }
    }, 'json');
};


/**
 * MainWP_Plugins.page
 */
jQuery(function () {
    jQuery(document).on('click', '#mainwp-show-plugins', function () {
        mainwp_fetch_plugins();
    });

    jQuery(document).on('click', '#mainwp-install-to-selected-sites', function () {
        let checkedVals = jQuery('.mainwp-manage-plugin-item-website .mainwp-selected-plugin-site:checked').map(function () {
            let rowElement = jQuery(this).closest('.mainwp-manage-plugin-item-website');
            let val = rowElement.attr("site-id");
            return val;
        }).get();

        let selectedIds = [];
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
        } else {
            jQuery('#mainwp-message-zone').fadeOut(5000);
            let ids = selectedIds.join("-");
            let kwd = jQuery('#mainwp_plugin_search_by_keyword').val();
            if ('' != kwd) {
                kwd = '&s=' + encodeURIComponent(kwd);
            }
            location.href = 'admin.php?page=PluginsInstall&selected_sites=' + ids + kwd;
        }
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

    let pluginCountSent;
    let pluginCountReceived;
    let pluginResetAllowed = true;

    jQuery(document).on('click', '#mainwp-do-plugins-bulk-actions', function () {
        let action = jQuery("#mainwp-bulk-actions").dropdown("get value");
        console.log(action);
        if (action == '') {
            return false;
        }

        jQuery(this).attr('disabled', 'true');
        jQuery('#mainwp_bulk_action_loading').show();
        pluginResetAllowed = false;
        pluginCountSent = 0;
        pluginCountReceived = 0;
        let selectedSites = [];
        let selectedSitePlugins = [];

        //Find all checked boxes
        jQuery('.mainwp-selected-plugin-site:checked').each(function () {
            let rowElement = jQuery(this).closest('.mainwp-manage-plugin-item-website');
            let websiteId = jQuery(rowElement).attr('site-id');
            let pluginSlug = jQuery(rowElement).attr('plugin-slug');
            selectedSitePlugins.push({ 'siteid': websiteId, 'plugin': pluginSlug });
            if (selectedSites.indexOf(websiteId) < 0) {
                selectedSites.push(websiteId);
            }
        });

        jQuery(selectedSites).each(function (idx, val) {
            let websiteId = val;
            let selectedPlugins = [];

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
                let pluginsToSend = [];
                let namesToSend = [];
                for (let ss of selectedPlugins) {
                    pluginsToSend.push(jQuery(ss).attr('plugin-slug'));
                    namesToSend.push(jQuery(ss).attr('plugin-name'));
                }

                let data = mainwp_secure_data({
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
        let rowEl = jQuery(jQuery(this).parents('tr')[0]);
        let slug = rowEl.attr('plugin-slug');
        let name = rowEl.attr('plugin-name');
        let note = rowEl.find('.esc-content-note').html();
        jQuery('#mainwp-notes-title').html(decodeURIComponent(name));
        jQuery('#mainwp-notes-html').html(note == '' ? __('No saved notes. Click the Edit button to edit plugin notes.') : note);
        jQuery('#mainwp-notes-note').val(note);
        jQuery('#mainwp-notes-slug').val(slug);
        mainwp_notes_show();
    });

    window.mainwp_notes_plugin_save = function () {
        let slug = jQuery('#mainwp-notes-slug').val();
        let newnote = jQuery('#mainwp-notes-note').val();
        newnote = newnote.replace(/(?:\r\n|\r|\n)/g, '<br>');
        let data = mainwp_secure_data({
            action: 'mainwp_trusted_plugin_notes_save',
            slug: slug,
            note: newnote
        });

        jQuery('#mainwp-notes-status').html('<i class="notched circle loading icon"></i> ' + __('Saving note. Please wait...')).show();

        jQuery.post(ajaxurl, data, function (pSlug) {
            return function (response) {
                let rowEl = jQuery('tr[plugin-slug="' + pSlug + '"]');
                if (response.result == 'SUCCESS') {
                    jQuery('#mainwp-notes-status').html('<i class="green check icon"></i> ' + __('Note saved!'));
                    rowEl.find('.esc-content-note').html(jQuery('#mainwp-notes-note').val());

                    if (newnote == '') {
                        rowEl.find('.mainwp-edit-plugin-note').html('<i class="sticky note outline icon"></i>');
                    } else {
                        rowEl.find('.mainwp-edit-plugin-note').html('<i class="sticky green note icon"></i>');
                    }

                } else if (response.error != undefined) {
                    jQuery('#mainwp-notes-status').html('<i class="times red icon"></i> ' + __('Undefined error occured while saving your note') + ': ' + response.error);
                } else {
                    jQuery('#mainwp-notes-status').html('<i class="times red icon"></i> ' + __('Undefined error occured while saving your note') + '.');
                }
            }
        }(slug), 'json');
        return false;
    }

    jQuery(document).on('click', '.mainwp-edit-theme-note', function () {
        let rowEl = jQuery(jQuery(this).parents('tr')[0]);
        let slug = rowEl.attr('theme-slug');
        let name = rowEl.attr('theme-name');
        let note = rowEl.find('.esc-content-note').html();
        jQuery('#mainwp-notes-modal').removeClass('edit-mode');
        jQuery('#mainwp-notes-title').html(decodeURIComponent(name));
        jQuery('#mainwp-notes-html').html(note == '' ? 'No saved notes. Click the Edit button to edit theme notes.' : note);
        jQuery('#mainwp-notes-note').val(note);
        jQuery('#mainwp-notes-slug').val(slug);
        mainwp_notes_show();
    });

    window.mainwp_notes_theme_save = function () {
        let slug = jQuery('#mainwp-notes-slug').val();
        let newnote = jQuery('#mainwp-notes-note').val();
        newnote = newnote.replace(/(?:\r\n|\r|\n)/g, '<br>');
        let data = mainwp_secure_data({
            action: 'mainwp_trusted_theme_notes_save',
            slug: slug,
            note: newnote
        });

        jQuery('#mainwp-notes-status').html('<i class="notched circle loading icon"></i> ' + __('Saving note. Please wait...'));

        jQuery.post(ajaxurl, data, function (pSlug) {
            return function (response) {
                let rowEl = jQuery('tr[theme-slug="' + pSlug + '"]');
                if (response.result == 'SUCCESS') {
                    jQuery('#mainwp-notes-status').html('<i class="green check icon"></i> ' + __('Note saved!'));
                    rowEl.find('.esc-content-note').html(jQuery('#mainwp-notes-note').val());
                    if (newnote == '') {
                        rowEl.find('.mainwp-edit-theme-note').html('<i class="sticky note outline icon"></i>');
                    } else {
                        rowEl.find('.mainwp-edit-theme-note').html('<i class="sticky green note icon"></i>');
                    }
                } else if (response.error != undefined) {
                    jQuery('#mainwp-notes-status').html('<i class="times red icon"></i> ' + __('Undefined error occured while saving your note!') + ': ' + response.error);
                } else {
                    jQuery('#mainwp-notes-status').html('<i class="times red icon"></i> ' + __('Undefined error occured while saving your note!'));
                }
            }
        }(slug), 'json');
        return false;
    }
});

window.mainwp_show_hide_install_to_selected_sites = function (what) {
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

// Fetch plugins for the Auto Update feature
let mainwp_fetch_all_active_plugins = function () {
    let data = mainwp_secure_data({
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
let mainwp_fetch_all_themes = function () {
    let data = mainwp_secure_data({
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
jQuery(function () {
    jQuery(document).on('click', '#mainwp_show_themes', function () {
        mainwp_fetch_themes();
    });

    jQuery(document).on('click', '#mainwp-install-themes-to-selected-sites', function () {
        let checkedVals = jQuery('.mainwp-manage-theme-item-website .mainwp-selected-theme-site:checked').map(function () {
            let rowElement = jQuery(this).closest('.mainwp-manage-theme-item-website');
            let val = rowElement.attr('site-id');
            return val;
        }).get();

        let selectedIds = [];
        if (checkedVals instanceof Array) {
            jQuery.grep(checkedVals, function (val) {
                if (jQuery.inArray(val, selectedIds) == -1) {
                    selectedIds.push(val);
                }
            });
        }

        if (selectedIds.length == 0) {
            feedback('mainwp-message-zone', __('Please select at least one website.'), 'yellow');
        } else {
            jQuery('#mainwp-message-zone').fadeOut(5000);
            let ids = selectedIds.join("-");
            let kwd = jQuery('#mainwp_theme_search_by_keyword').val();
            if ('' != kwd) {
                kwd = '&s=' + encodeURIComponent(kwd);
            }
            location.href = 'admin.php?page=ThemesInstall&selected_sites=' + ids + kwd;
        }
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

    let themeCountSent;
    let themeCountReceived;
    let themeResetAllowed = true;

    jQuery(document).on('click', '#mainwp-do-themes-bulk-actions', function () {
        let action = jQuery("#mainwp-bulk-actions").dropdown("get value");
        console.log(action);
        if (action == '' || action == 'none')
            return;

        jQuery('#mainwp-do-themes-bulk-actions').attr('disabled', 'true');
        jQuery('#mainwp_bulk_action_loading').show();
        themeResetAllowed = false;
        themeCountSent = 0;
        themeCountReceived = 0;
        let selectedSites = [];
        let selectedSiteThemes = [];

        //Find all checked boxes
        jQuery('.mainwp-selected-theme-site:checked').each(function () {
            let rowElement = jQuery(this).closest('.mainwp-manage-theme-item-website');
            let websiteId = jQuery(rowElement).attr('site-id');
            let theme = jQuery(rowElement).attr('theme-slug');
            if (action == 'activate' && jQuery(rowElement).attr('is-actived') == 1) {
                jQuery(rowElement).find('.mainwp-selected-theme-site').attr('checked', false);
                return;
            }
            selectedSiteThemes.push({ 'siteid': websiteId, 'theme': theme });
            if (selectedSites.indexOf(websiteId) < 0) {
                selectedSites.push(websiteId);
            }
        });


        jQuery(selectedSites).each(function (idx, val) { // NOSONAR - complex.
            let websiteId = val;
            let selectedThemes = [];

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
                let themeToActivate = jQuery(selectedThemes[0]).attr('theme-slug');
                let themesToSend = [];
                let namesToSend = [];

                let data = mainwp_secure_data({
                    action: 'mainwp_theme_' + action,
                    websiteId: websiteId
                });

                if (action == 'ignore_updates') {
                    for (let ss of selectedThemes) {
                        themesToSend.push(jQuery(ss).attr('theme-slug'));
                        namesToSend.push(jQuery(ss).attr('theme-name'));
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
                let themesToDelete = [];
                for (let ss of selectedThemes) {
                    if (jQuery(ss).attr('not-delete') == 1 || jQuery(ss).attr('is-actived') == 1) {
                        jQuery(ss).find('.mainwp-selected-theme-site').attr('checked', false);
                        continue;
                    }
                    themesToDelete.push(jQuery(ss).attr('theme-slug'));
                }
                if (themesToDelete.length == 0) {
                    return;
                }
                let data = mainwp_secure_data({
                    action: 'mainwp_theme_delete',
                    themes: themesToDelete,
                    websiteId: websiteId
                });

                themeCountSent++;
                jQuery.post(ajaxurl, data, function (response) {
                    if (response.error != undefined && response.error == Object(response.error)) { // check if .error is object.
                        let entries = Object.entries(response.error);
                        for (let entry of entries) {
                            let warnings = __(entry[0], encodeURIComponent(entry[1])); // entry[0]:id message, entry[1] string value.
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
window.mainwp_fetch_themes = function () {
    let errors = [];
    let selected_sites = [];
    let selected_groups = [];
    let selected_clients = [];

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

    let _status = jQuery("#mainwp_themes_search_by_status").dropdown("get value");
    if (_status == null) {
        errors.push(__('Please select at least one theme status.'));
    }

    if (errors.length > 0) {
        mainwp_set_message_zone('#mainwp-message-zone', errors.join('<br />'), 'yellow');
        return;
    } else {
        mainwp_set_message_zone('#mainwp-message-zone');
    }

    let data = mainwp_secure_data({
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
        if (response?.result) {
            jQuery('#mainwp-themes-content').html(response.result);
            jQuery('#mainwp-themes-bulk-actions-wapper').html(response.bulk_actions);
            jQuery('#mainwp-themes-bulk-actions-wapper .ui.dropdown').dropdown();
        }
    }, 'json');
};

/**
 * Plugins manages.
 */
jQuery(function () {
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

let manage_plugin_Action = function (elem, what) {
    let rowElement = jQuery(elem).closest('.mainwp-manage-plugin-item-website');
    let plugin = rowElement.attr('plugin-slug');
    let websiteId = rowElement.attr('site-id');

    let data = mainwp_secure_data({
        action: 'mainwp_widget_plugin_' + what, // same with the widgets.
        plugin: plugin,
        websiteId: websiteId
    });
    let start_row = '<div class="one wide center aligned middle aligned column"></div><div class="thirteen wide left aligned middle aligned column">';
    let end_row = '</div>';
    jQuery(rowElement).html(start_row + '<i class="notched circle loading icon"></i>' + __('Please wait...') + end_row);
    jQuery.post(ajaxurl, data, function (response) {
        if (response?.error) {
            jQuery(rowElement).html(start_row + '<span data-tooltip="' + response.error + '" data-inverted="" data-position="left center"><i class="times red icon"></i></span>' + end_row);
        } else if (response?.result) {
            if (what == 'delete') {
                jQuery(rowElement).html(start_row + '<i class="green check icon"></i> ' + response.result + '</div>');
                setTimeout(function () {
                    jQuery(rowElement).fadeOut(1000);
                }, 1000);

            } else {
                jQuery(rowElement).html(start_row + '<i class="green check icon"></i> ' + response.result + end_row);
            }
            setTimeout(function () {
                mainwp_fetch_plugins();
            }, 3000);
        } else {
            jQuery(rowElement).html(start_row + '<span data-tooltip="Undefined error occured. Please try again." data-inverted="" data-position="left center"><i class="times red icon"></i></span>' + end_row);
        }
    }, 'json');

    return false;
};


let manage_plugins_upgrade = function (slug, websiteid) {
    let msg = __('Are you sure you want to update the plugin on the selected site?');
    mainwp_confirm(msg, function () {
        return manage_plugins_upgrade_int(slug, websiteid);
    }, false, 1);
};

let manage_plugins_upgrade_continueAfterBackup = function (slug, websiteId, websiteHolder) {
    console.log('plugin upgrade continue');
    let siteHolder = websiteHolder;
    return function () {
        let data = mainwp_secure_data({
            action: 'mainwp_upgradeplugintheme',
            websiteId: websiteId,
            type: 'plugin',
            slug: slug
        });
        jQuery.ajax({
            type: "POST",
            url: ajaxurl,
            data: data,
            success: function (response) {
                if (response.error) {
                    let extErr = getErrorMessageInfo(response.error, 'ui')
                    siteHolder.find('.column.update-column').html(extErr);
                } else {
                    let res = response.result;
                    let res_error = response.result_error;
                    if (res[slug]) {
                        siteHolder.attr('updated', 1);
                        siteHolder.find('.column.update-column').html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Update successful', 'mainwp') + '"><i class="green check icon"></i></span>');
                    } else if (res_error[slug]) {
                        let _error = res_error[slug];
                        let _icon = '<i class="red times icon"></i>';
                        let roll_error = mainwp_updates_get_rollback_msg(_error);
                        if (roll_error) {
                            _error = roll_error;
                            _icon = mainwpParams.roll_ui_icon;
                        }
                        siteHolder.find('.column.update-column').html('<span data-inverted="" data-position="left center" data-tooltip="' + _error + '">' + _icon + '</span>');
                    } else {
                        siteHolder.find('.column.update-column').html('<i class="red times icon"></i>');
                    }
                    setTimeout(function () {
                        mainwp_fetch_plugins();
                    }, 3000);
                }
            },
            tryCount: 0,
            retryLimit: 3,
            endError: function () {
                siteHolder.find('.column.update-column').html('<i class="red times icon"></i>');
            },
            error: function (xhr) {
                this.tryCount++;
                if (this.tryCount >= this.retryLimit) {
                    this.endError();
                    return;
                }

                setTimeout((pRqst, pXhr) => {
                    if (pXhr.status == 404) {
                        //handle error
                        jQuery.ajax(pRqst);
                    } else if (pXhr.status == 500) {
                        //handle error
                    } else {
                        //handle error
                    }
                }, 500, this, xhr);
            },
            dataType: 'json'
        });
    }();
}


let manage_plugins_upgrade_int = function (slug, websiteId) {
    let websiteHolder = jQuery('.mainwp-manage-plugin-item-website[plugin-slug="' + slug + '"][site-id="' + websiteId + '"]');
    websiteHolder.find('.column.update-column').html('<span data-tooltip="Updating..." data-inverted="" data-position="left center"><i class="notched circle loading icon"></i></span>');

    let _callbackAfterBackup = function () {
        return manage_plugins_upgrade_continueAfterBackup(slug, websiteId, websiteHolder);
    };

    if (mainwpParams['disable_checkBackupBeforeUpgrade']) {
        _callbackAfterBackup();
        return false;
    }

    let sitesToUpdate = [websiteId];
    let siteNames = [];
    siteNames[websiteId] = jQuery(websiteHolder).attr('site-name');

    return mainwp_manages_checkBackups(sitesToUpdate, siteNames, _callbackAfterBackup);
};


/**
 * Themes manage.
 */
jQuery(function () {
    jQuery(document).on('click', '.mainwp-manages-theme-activate', function () {
        manages_themeAction(jQuery(this), 'activate');
        return false;
    });
    jQuery(document).on('click', '.mainwp-manages-theme-delete', function () {
        manages_themeAction(jQuery(this), 'delete');
        return false;
    });
});

let manages_themeAction = function (elem, what) {
    let rowElement = jQuery(elem).closest('.mainwp-manage-theme-item-website');
    let theme = rowElement.attr('theme-slug');
    let websiteId = rowElement.attr('site-id');

    let data = mainwp_secure_data({
        action: 'mainwp_widget_theme_' + what, // same with theme widget.
        theme: theme,
        websiteId: websiteId
    });

    let start_row = '<div class="one wide center aligned middle aligned column"></div><div class="thirteen wide left aligned middle aligned column">';
    let end_row = '</div>';

    jQuery(rowElement).html(start_row + '<i class="notched circle loading icon"></i>' + __('Please wait...') + end_row);
    jQuery.post(ajaxurl, data, function (response) {
        if (response?.error) {
            jQuery(rowElement).html(start_row + '<span data-tooltip="' + response.error + '" data-inverted="" data-position="left center"><i class="times red icon"></i></span>' + end_row);
        } else if (response?.result) {
            if (what == 'delete') {
                jQuery(rowElement).html(start_row + '<i class="green check icon"></i> ' + response.result + end_row);
                setTimeout(function () {
                    jQuery(rowElement).fadeOut(1000);
                }, 1000);

            } else {
                jQuery(rowElement).html(start_row + '<i class="green check icon"></i> ' + response.result + end_row);
            }
            setTimeout(function () {
                mainwp_fetch_themes();
            }, 3000);
        } else {
            jQuery(rowElement).html(start_row + '<span data-tooltip="Undefined error occured. Please try again." data-inverted="" data-position="left center"><i class="times red icon"></i></span>' + end_row);
        }
    }, 'json');

    return false;
};


let manage_themes_upgrade_theme = function (slug, websiteid) {
    let msg = __('Are you sure you want to update the theme on the selected site?');
    mainwp_confirm(msg, function () {
        return manage_themes_upgrade_int(slug, websiteid);
    }, false, 1);
};

let manage_themes_upgrade_continueAfterBackup = function (slug, websiteId, websiteHolder) {
    console.log('theme upgrade continue');
    let siteHolder = websiteHolder;
    let pSlug = slug;
    return function () {
        let data = mainwp_secure_data({
            action: 'mainwp_upgradeplugintheme',
            websiteId: websiteId,
            type: 'theme',
            slug: slug
        });
        jQuery.ajax({
            type: "POST",
            url: ajaxurl,
            data: data,
            success: function (response) {
                if (response.error) {
                    let extErr = getErrorMessageInfo(response.error, 'ui')
                    siteHolder.find('.column.update-column').html(extErr);
                } else {
                    let res = response.result;
                    let res_error = response.result_error;
                    if (res[pSlug]) {
                        siteHolder.attr('updated', 1);
                        siteHolder.find('.column.update-column').html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Update successful', 'mainwp') + '"><i class="green check icon"></i></span>');
                    } else if (res_error ? res_error[slug] : false) {
                        let _error = res_error[slug];
                        let _icon = '<i class="red times icon"></i>';
                        let roll_error = mainwp_updates_get_rollback_msg(_error);
                        if (roll_error) {
                            _error = roll_error;
                            _icon = mainwpParams.roll_ui_icon;
                        }
                        siteHolder.find('.column.update-column').html('<span data-inverted="" data-position="left center" data-tooltip="' + _error + '">' + _icon + '</span>');
                    } else {
                        siteHolder.find('.column.update-column').html('<i class="red times icon"></i>');
                    }
                    setTimeout(function () {
                        mainwp_fetch_themes();
                    }, 3000);
                }
            },
            tryCount: 0,
            retryLimit: 3,
            endError: function () {
                siteHolder.find('.column.update-column').html('<i class="red times icon"></i>');
            },
            error: function (xhr) {
                this.tryCount++;
                if (this.tryCount >= this.retryLimit) {
                    this.endError();
                    return;
                }
                setTimeout((pRqst, pXhr) => {
                    if (pXhr.status == 404) {
                        //handle error
                        jQuery.ajax(pRqst);
                    } else if (pXhr.status == 500) {
                        //handle error
                    } else {
                        //handle error
                    }
                }, 500, this, xhr);
            },
            dataType: 'json'
        });
    }();
}

let manage_themes_upgrade_int = function (slug, websiteId) {
    let websiteHolder = jQuery('.mainwp-manage-theme-item-website[theme-slug="' + slug + '"][site-id="' + websiteId + '"]');
    websiteHolder.find('.column.update-column').html('<span data-tooltip="Updating..." data-inverted="" data-position="left center"><i class="notched circle loading icon"></i></span');

    let _callbackAfterBackup = manage_themes_upgrade_continueAfterBackup(slug, websiteId, websiteHolder);

    if (mainwpParams['disable_checkBackupBeforeUpgrade']) {
        _callbackAfterBackup();
        return false;
    }

    let sitesToUpdate = [websiteId];
    let siteNames = [];
    siteNames[websiteId] = jQuery(websiteHolder).attr('site-name');

    return mainwp_manages_checkBackups(sitesToUpdate, siteNames, _callbackAfterBackup);
};


/**
 * Check Backups.
 */
let mainwp_manages_checkBackups = function (sitesToUpdate, siteNames, continueAfterBackup) {
    let managesitesShowBusyFunction = function () {
        let output = __('Checking if a backup is required for the selected updates...');
        mainwpPopup('#managesites-backup-box').getContentEl().html(output);
        jQuery('#managesites-backup-all').hide();
        jQuery('#managesites-backup-ignore').hide();
        mainwpPopup('#managesites-backup-box').init({
            title: __("Checking backup settings..."), callback: function () {
                mainwpVars.bulkManageSitesTaskRunning = false;
                window.location.href = location.href;
            }
        });

    };

    let managesitesShowBusyTimeout = setTimeout(managesitesShowBusyFunction, 300);

    //Step 2: Check if backups are ok.
    let data = mainwp_secure_data({
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
                let siteFeedback;

                if (response?.result?.sites) {
                    siteFeedback = [];
                    for (let currSiteId in response['result']['sites']) {
                        if (!response['result']['sites'][currSiteId]) {
                            siteFeedback.push(currSiteId);
                        }
                    }
                    if (siteFeedback.length == 0)
                        siteFeedback = undefined;
                }

                if (siteFeedback != undefined) {
                    mainwp_managesites_prepare_backup_popup(response, pSiteNames, siteFeedback);
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
