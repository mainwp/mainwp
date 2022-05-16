/* eslint complexity: ["error", 100] */
//Ignore plugin
jQuery(document).ready(function () {
    jQuery(document).on('click', 'input[name="plugins"]', function () {
        if (jQuery(this).is(':checked')) {
            jQuery('input[name="plugins"]').attr('checked', 'checked');
            jQuery('input[name="plugin[]"]').attr('checked', 'checked');
        } else {
            jQuery('input[name="plugins"]').removeAttr('checked');
            jQuery('input[name="plugin[]"]').removeAttr('checked');
        }
    });
    jQuery(document).on('click', 'input[name="themes"]', function () {
        if (jQuery(this).is(':checked')) {
            jQuery('input[name="themes"]').attr('checked', 'checked');
            jQuery('input[name="theme[]"]').attr('checked', 'checked');
        } else {
            jQuery('input[name="themes"]').removeAttr('checked');
            jQuery('input[name="theme[]"]').removeAttr('checked');
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
            jQuery('#mainwp-bulk-trust-plugins-action-apply').removeAttr('disabled');
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
            jQuery('#mainwp-bulk-trust-themes-action-apply').removeAttr('disabled');
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

    jQuery(document).on('change', '.mainwp_plugin_check_all', function () {
        var ch_val = jQuery(this).is(":checked");
        jQuery(".mainwp-selected-plugin[value='" + jQuery(this).val() + "'][version='" + jQuery(this).attr('version') + "']").prop('checked', ch_val);
        jQuery(".mainwp-selected-plugin[value='" + jQuery(this).val() + "'][version='" + jQuery(this).attr('version') + "']").each(function () {
            var rowIdx = jQuery(this).closest('tr').index();
            if (ch_val) {
                jQuery('#mainwp-manage-plugins-table').find('tr').eq(rowIdx + 2).find(".mainwp-selected-plugin[value='" + jQuery(this).val() + "'][version='" + jQuery(this).attr('version') + "']").prop('checked', true);
            }
        });

        if (jQuery('#mainwp-manage-plugins-table .mainwp_plugins_site_check_all:checkbox:checked').length > 0) {
            jQuery('#mainwp-install-to-selected-sites').show();
        } else {
            jQuery('#mainwp-install-to-selected-sites').fadeOut(1000);
        }
    });


    jQuery(document).on('click', '#mainwp-install-to-selected-sites', function () {
        var checkedVals = jQuery('.dataTables_scrollBody table .mainwp_plugins_site_check_all:checkbox:checked').map(function () {
            var rowElement = jQuery(this).parents('tr');
            var val = rowElement.find("input[type=hidden].websiteId").val();
            return val;
        }).get();

        if (checkedVals.length == 0) {
            feedback('mainwp-message-zone', __('Please select at least one website.'), 'yellow');
            return false;
        } else {
            jQuery('#mainwp-message-zone').fadeOut(5000);
            var ids = checkedVals.join("-");
            var kwd = jQuery('#mainwp_plugin_search_by_keyword').val();
            if ('' != kwd) {
                kwd = '&s=' + encodeURIComponent(kwd);
            }
            location.href = 'admin.php?page=PluginsInstall&selected_sites=' + ids + kwd;
        }
        return false;
    });

    // to fix compatible with table fixed columns.
    mainwp_plugins_table_site_check_all_init = function () {
        jQuery(document).on('change', '#mainwp-manage-plugins-table .mainwp_plugins_site_check_all', function () {
            var rowIdx = jQuery(this).closest('tr').index();
            var ch_val = jQuery(this).is(":checked");
            jQuery('#mainwp-manage-plugins-table').find('tr').eq(rowIdx + 2).find('.mainwp-selected-plugin').each(function () {
                jQuery(this).prop('checked', ch_val);
            });
            // to fix checkbox display.
            //jQuery('.DTFC_LeftBodyWrapper table').find('tr').eq( rowIdx + 1 ).find('.mainwp_plugins_site_check_all').prop( 'checked', ch_val);

            if (jQuery('#mainwp-manage-plugins-table .mainwp_plugins_site_check_all:checkbox:checked').length > 0) {
                jQuery('#mainwp-install-to-selected-sites').show();
            } else {
                jQuery('#mainwp-install-to-selected-sites').fadeOut(1000);
            }
        });
    };

    mainwp_plugins_table_site_check_all_init();

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
        if (action == '')
            return false;

        jQuery(this).attr('disabled', 'true');
        jQuery('#mainwp_bulk_action_loading').show();
        pluginResetAllowed = false;
        pluginCountSent = 0;
        pluginCountReceived = 0;

        //Find all checked boxes
        jQuery('.websiteId').each(function () {
            var websiteId = jQuery(this).val();
            var rowElement = jQuery(this).parents('tr');
            
            if (jQuery(rowElement).hasClass('parent')) {
                rowElement = jQuery(rowElement).next();
            }
            
            var selectedPlugins = rowElement.find('.mainwp-selected-plugin:checked');

            if (selectedPlugins.length == 0)
                return;

            if ((action == 'activate') || (action == 'delete') || (action == 'deactivate') || (action == 'ignore_updates')) {
                var pluginsToSend = [];
                var namesToSend = [];
                for (var i = 0; i < selectedPlugins.length; i++) {
                    pluginsToSend.push(jQuery(selectedPlugins[i]).val());
                    namesToSend.push(jQuery(selectedPlugins[i]).attr('name'));
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

// Manage Plugins -- Fetch plugins
mainwp_fetch_plugins = function () {
    var errors = [];
    var selected_sites = [];
    var selected_groups = [];

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function () {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push(__('Please select at least one website or group.'));
        }
    } else {
        jQuery("input[name='selected_groups[]']:checked").each(function () {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push(__('Please select at least one website or group.'));
        }
    }

    var _status;

    var statuses = jQuery("#mainwp_plugins_search_by_status").dropdown("get value");

    if (statuses == null)
        errors.push(__('Please select at least one plugin status.'));
    else {
        _status = statuses.join(',');
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
        'sites[]': selected_sites
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
        response = jQuery.trim(response);
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

    jQuery(document).on('change', '.mainwp_theme_check_all', function () {
        var ch_val = jQuery(this).is(":checked");
        jQuery(".mainwp-selected-theme[value='" + jQuery(this).val() + "'][version='" + jQuery(this).attr('version') + "']").prop('checked', ch_val);
        jQuery(".mainwp-selected-theme[value='" + jQuery(this).val() + "'][version='" + jQuery(this).attr('version') + "']").each(function () {
            var rowIdx = jQuery(this).closest('tr').index();
            if (ch_val) {
                jQuery('#mainwp-manage-themes-table').find('tr').eq(rowIdx + 2).find(".mainwp-selected-theme[value='" + jQuery(this).val() + "'][version='" + jQuery(this).attr('version') + "']").prop('checked', true);
            }
        });

        if (jQuery('#mainwp-manage-themes-table .mainwp_themes_site_check_all:checkbox:checked').length > 0) {
            jQuery('#mainwp-install-themes-to-selected-sites').show();
        } else {
            jQuery('#mainwp-install-themes-to-selected-sites').fadeOut(1000);
        }
    });

    mainwp_themes_table_site_check_all_init = function () {
        jQuery(document).on('change', '.dataTables_scrollBody .mainwp_themes_site_check_all', function () {
            var rowIdx = jQuery(this).closest('tr').index();
            var ch_val = jQuery(this).is(":checked");

            var action = jQuery("#mainwp-bulk-actions").dropdown("get value");
            if (!(action == 'activate' && ch_val)) {
                jQuery('#mainwp-manage-themes-table').find('tr').eq(rowIdx + 2).find('.mainwp-selected-theme').each(function () {
                    jQuery(this).prop('checked', ch_val);
                });
            }

            // to fix checkbox display.
            //jQuery('.DTFC_LeftBodyWrapper table').find('tr').eq( rowIdx + 1 ).find('.mainwp_themes_site_check_all').prop( 'checked', ch_val);

            if (jQuery('.dataTables_scrollBody .mainwp_themes_site_check_all:checkbox:checked').length > 0) {
                jQuery('#mainwp-install-themes-to-selected-sites').show();
            } else {
                jQuery('#mainwp-install-themes-to-selected-sites').fadeOut(1000);
            }
        });
    }
    mainwp_themes_table_site_check_all_init();

    jQuery(document).on('click', '#mainwp-install-themes-to-selected-sites', function () {
        var checkedVals = jQuery('.dataTables_scrollBody .mainwp_themes_site_check_all:checkbox:checked').map(function () {
            var rowElement = jQuery(this).parents('tr');
            var val = rowElement.find("input[type=hidden].websiteId").val();
            return val;
        }).get();

        if (checkedVals.length == 0) {
            feedback('mainwp-message-zone', __('Please select at least one website.'), 'yellow');
            return false;
        } else {
            jQuery('#mainwp-message-zone').fadeOut(5000);
            var ids = checkedVals.join("-");
            var kwd = jQuery('#mainwp_theme_search_by_keyword').val();
            if ('' != kwd) {
                kwd = '&s=' + encodeURIComponent(kwd);
            }
            location.href = 'admin.php?page=ThemesInstall&selected_sites=' + ids + kwd;
        }
        return false;
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
        if (action == '' || action == 'none')
            return;

        jQuery('#mainwp-do-themes-bulk-actions').attr('disabled', 'true');
        jQuery('#mainwp_bulk_action_loading').show();
        themeResetAllowed = false;
        themeCountSent = 0;
        themeCountReceived = 0;

        //Find all checked boxes
        jQuery('.websiteId').each(function () {
            var websiteId = jQuery(this).val();
            var rowElement = jQuery(this).parents('tr');

            if (jQuery(rowElement).hasClass('parent')) {
                rowElement = jQuery(rowElement).next();
            } 

            var selectedThemes = rowElement.find('.mainwp-selected-theme:checked');

            if (selectedThemes.length == 0)
                return;

            if (action == 'activate' || action == 'ignore_updates') {
                var themeToActivate = jQuery(selectedThemes[0]).attr('slug');
                var themesToSend = [];
                var namesToSend = [];

                var data = mainwp_secure_data({
                    action: 'mainwp_theme_' + action,
                    websiteId: websiteId
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
                        jQuery(selectedThemes[i]).attr('checked', false);
                        continue;
                    }
                    themesToDelete.push(jQuery(selectedThemes[i]).attr('slug'));
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

mainwp_themes_check_changed = function (elements) {
    var action = jQuery('#mainwp-bulk-actions').val();
    if (action != 'activate')
        return;

    for (var i = 0; i < elements.length; i++) {
        var element = jQuery(elements[i]);
        if (!element.is(':checked'))
            continue;

        var parent = jQuery(element.parents('tr')[0]);

        if (!parent)
            continue;
        var subElements = parent.find('.mainwp-selected-theme:checked');
        for (var j = 0; j < subElements.length; j++) {
            var subElement = subElements[j];
            if (subElement == element[0])
                continue;

            jQuery(subElement).removeAttr('checked');
        }
    }
}

// Manage Themes -- Fetch themes from child sites
mainwp_fetch_themes = function () {
    var errors = [];
    var selected_sites = [];
    var selected_groups = [];

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function () {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push(__('Please select at least one website or group.'));
        }
    } else {
        jQuery("input[name='selected_groups[]']:checked").each(function () {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push(__('Please select at least one website or group.'));
        }
    }

    var _status = '';
    var statuses = jQuery("#mainwp_themes_search_by_status").dropdown("get value");
    if (statuses == null) {
        errors.push(__('Please select at least one theme status.'));
    } else {
        _status = statuses.join(',');
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
        'sites[]': selected_sites
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
