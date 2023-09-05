
/* eslint complexity: ["error", 100] */

function reload_init() {
    read_current_url();
    if (typeof wpOnload == 'function')
        wpOnload();
    managebackups_init();
    managesites_init();
    // Update form action URL, workaround for browser without History API
    jQuery('#wpbody-content form').each(function () {
        if (jQuery(this).attr('action') == '')
            jQuery(this).attr('action', mainwp_current_url);
    });
    stick_element_init();
}

/** AJAX page load **/
var mainwp_current_url = '';
function read_current_url() {
    mainwp_current_url = document.location.href.replace(/^.*?\/([^/]*?)\/?$/i, '$1');
    return mainwp_current_url;
}
function load_url(href, obj, e) {
    var page = href.match(/page=/i) ? href.replace(/^.*?page=([^&]+).*?$/i, '$1') : '';
    if (page || href == 'index.php') {
        if (!jQuery('body').hasClass('mainwp-ui-page')) {
            return;
        }
        if (typeof e !== 'undefined')
            e.preventDefault();
        jQuery('#wpbody-content').html('<div class="mainwp-loading"><img src="images/loading.gif" /> ' + __('Please wait...') + '</div>');
        if (jQuery(obj).hasClass('menu-top')) {
            var top = jQuery(obj).closest('li.menu-top');
            jQuery('#adminmenu .current').removeClass('current').addClass('wp-not-current-submenu');
            jQuery('.wp-has-current-submenu').removeClass('wp-has-current-submenu').addClass('wp-not-current-submenu');
            if (top.hasClass('wp-has-submenu')) {
                top.removeClass('wp-not-current-submenu').addClass('wp-has-current-submenu');
                jQuery(obj).removeClass('wp-not-current-submenu').addClass('wp-has-current-submenu');
            } else {
                top.removeClass('wp-not-current-submenu').addClass('current');
                jQuery(obj).removeClass('wp-not-current-submenu').addClass('current');
            }
            top.find('li.wp-first-item').addClass('current');
        } else {
            jQuery('#adminmenu .current').removeClass('current');
            jQuery(obj).closest('li').addClass('current');
            var top = jQuery(obj).closest('li.menu-top');
            if (top.hasClass('wp-not-current-submenu')) {
                jQuery('.wp-has-current-submenu').removeClass('wp-has-current-submenu').addClass('wp-not-current-submenu');
                top.removeClass('wp-not-current-submenu').addClass('wp-has-current-submenu');
            }
            top.find('a.menu-top').removeClass('wp-not-current-submenu').addClass('wp-has-current-submenu');
        }
        if (page) {
            jQuery.get(ajaxurl, {
                action: 'mainwp_load_title',
                page: page,
                nonce: mainwp_ajax_nonce
            }, function (data) {
                document.title = data;
            });
            jQuery.get(ajaxurl, {
                action: 'mainwp_load',
                page: page,
                nonce: mainwp_ajax_nonce
            }, function (data) {
                pagenow = page;
                data += '<div class="clear"></div>';
                jQuery('#wpbody-content').html(data);
                reload_init();
            });
        } else {
            jQuery.get(ajaxurl, {
                action: 'mainwp_load_dashboard_title',
                nonce: mainwp_ajax_nonce
            }, function (data) {
                document.title = data;
            });
            jQuery.get(ajaxurl, {
                action: 'mainwp_load_dashboard',
                nonce: mainwp_ajax_nonce
            }, function (data) {
                pagenow = 'dashboard';
                data += '<div class="clear"></div>';
                jQuery('#wpbody-content').html(data);
                reload_init();
                postboxes.init(pagenow);
                mainwp_ga_getstats();
            });
        }

    }
}

window.onpopstate = function (e) {
    read_current_url();
    if (e.state)
        load_url(mainwp_current_url, e.state.anchor);
}




function scroll_element() {
    var top = jQuery(this).scrollTop();
    var start = 20;
    jQuery('.stick-to-window').each(function () {
        var init = jQuery(this).data('init-position');
        if (top > init.top)
            jQuery(this).stop().animate({ top: (top - init.top) + init.top + start }, 1000);
        else
            jQuery(this).stop().css({ top: init.top });
    });
}
function stick_element_init() {
    jQuery('.stick-to-window').each(function () {
        var pos = jQuery(this).position();
        jQuery(this).css({
            position: 'absolute',
            top: pos.top,
            left: pos.left
        });
        jQuery(this).data('init-position', pos);
    });
}
function stick_element_reset() {
    jQuery('.stick-to-window').each(function () {
        jQuery(this).css({
            position: 'static',
            top: 0,
            left: 0
        });
    });
    stick_element_init();
    scroll_element();
}
jQuery(document).ready(function () {
    jQuery(window).trigger('scroll', scroll_element).trigger('resize', stick_element_reset);
    stick_element_init();
});

mainwp_confirm = function (msg, confirmed_callback, cancelled_callback, updateType, multiple, extra) {    // updateType: 1 single update, 2 multi update
    if (jQuery('#mainwp-disable-update-confirmations').length > 0) {
        var confVal = jQuery('#mainwp-disable-update-confirmations').val();
        if (typeof updateType !== 'undefined' && updateType !== false && (confVal == 2 || (confVal == 1 && updateType == 1))) {
            if (confirmed_callback && typeof confirmed_callback == 'function')
                confirmed_callback();
            return false;
        }
    }

    jQuery('#mainwp-modal-confirm .content-massage').html(msg);

    if (typeof extra !== 'undefined' && extra !== false) {
        jQuery('#mainwp-confirm-form').show();
        jQuery('#mainwp-confirm-form').find('label').html('Type ' + extra + ' to confirm');
    }

    var opts = {
        onApprove: function () {
            if (typeof extra !== 'undefined' && extra !== false) {
                var extraValue = jQuery('#mainwp-confirm-input').val();
                if (confirmed_callback && typeof confirmed_callback == 'function') {
                    if (extraValue === extra) {
                        confirmed_callback();
                    } else {
                        jQuery('#mainwp-confirm-input').val('').trigger('focus').transition('shake');
                        return false;
                    }
                }
            } else {
                if (confirmed_callback && typeof confirmed_callback == 'function')
                    confirmed_callback();
            }
        },
        onDeny() {
            if (cancelled_callback && typeof cancelled_callback == 'function')
                cancelled_callback();
        }
    }

    if (multiple) {
        opts.allowMultiple = true;
    }

    jQuery('#mainwp-modal-confirm').modal(opts).modal('show');

    // if it is update confirm and then display the update confirm notice text
    if (typeof updateType !== 'undefined' && updateType !== false && (updateType == 1 || updateType == 2)) {
        jQuery('#mainwp-modal-confirm .update-confirm-notice ').show();
    }

    return false;
}


/**
 * Select sites
 */
jQuery(document).ready(function () {
    jQuery('.mainwp_selected_sites_item input:checkbox').on('change', function () {
        if (jQuery(this).is(':checked'))
            jQuery(this).parent().addClass('selected_sites_item_checked');
        else
            jQuery(this).parent().removeClass('selected_sites_item_checked');
        mainwp_site_select();
    });
    // seems not used.
    jQuery('.mainwp_selected_sites_item input:radio').on('change', function () {
        if (jQuery(this).is(':checked')) {
            jQuery(this).parent().addClass('selected_sites_item_checked');
            jQuery(this).parent().parent().find('.mainwp_selected_sites_item input:radio:not(:checked)').parent().removeClass('selected_sites_item_checked');
        } else
            jQuery(this).parent().removeClass('selected_sites_item_checked');
        mainwp_site_select();
    });

    jQuery('.mainwp_selected_groups_item input:checkbox').on('change', function () {
        if (jQuery(this).is(':checked'))
            jQuery(this).parent().addClass('selected_groups_item_checked');
        else
            jQuery(this).parent().removeClass('selected_groups_item_checked');
        mainwp_group_select();
    });

    // seems not used.
    jQuery('.mainwp_selected_groups_item input:radio').on('change', function () {
        if (jQuery(this).is(':checked')) {
            jQuery(this).parent().addClass('selected_groups_item_checked');
            jQuery(this).parent().parent().find('.mainwp_selected_groups_item input:radio:not(:checked)').parent().removeClass('selected_groups_item_checked');
        } else
            jQuery(this).parent().removeClass('selected_groups_item_checked');
    });


    jQuery('.mainwp_selected_clients_item input:checkbox').on('change', function () {
        if (jQuery(this).is(':checked'))
            jQuery(this).parent().addClass('selected_clients_item_checked');
        else
            jQuery(this).parent().removeClass('selected_clients_item_checked');
        mainwp_client_select();
    });

});

mainwp_site_select = function () {
    mainwp_newpost_updateCategories();
};
mainwp_group_select = function () {
    mainwp_newpost_updateCategories();
};
mainwp_client_select = function () {
    mainwp_newpost_updateCategories();
};

mainwp_ss_select = function (me, val) {
    var parent = jQuery(me).closest('.mainwp_select_sites_wrapper');
    var tab = parent.find('#select_sites_tab').val();
    if (tab == 'site') {
        parent.find('#mainwp-select-sites-list .item:not(.no-select) INPUT:enabled:checkbox').each(function () {
            jQuery(this).attr('checked', val).trigger("change");
            if (val) {
                jQuery(this).closest('.item.checkbox').checkbox('set checked');
            }
            else {
                jQuery(this).closest('.item.checkbox').checkbox('set unchecked');
            }
        });
    } else if (tab == 'staging') {
        parent.find('#mainwp-select-staging-sites-list .item:not(.no-select) INPUT:enabled:checkbox').each(function () {
            jQuery(this).attr('checked', val).trigger("change");
            if (val) {
                jQuery(this).closest('.item.checkbox').checkbox('set checked');
            }
            else {
                jQuery(this).closest('.item.checkbox').checkbox('set unchecked');
            }
        });
    } else if (tab == 'client') {
        parent.find('#mainwp-select-clients-list .item:not(.no-select) INPUT:enabled:checkbox').each(function () {
            jQuery(this).attr('checked', val).trigger("change");
            if (val) {
                jQuery(this).closest('.item.checkbox').checkbox('set checked');
            }
            else {
                jQuery(this).closest('.item.checkbox').checkbox('set unchecked');
            }
        });

    } else { //group
        parent.find('#mainwp-select-groups-list .item:not(.no-select) INPUT:enabled:checkbox').each(function () {
            jQuery(this).attr('checked', val).trigger("change");
            if (val) {
                jQuery(this).closest('.item.checkbox').checkbox('set checked');
            }
            else {
                jQuery(this).closest('.item.checkbox').checkbox('set unchecked');
            }
        });
    }
    if (val == true) {
        jQuery('.mainwp-ss-select').hide();
        jQuery('.mainwp-ss-deselect').show();
    } else {
        jQuery('.mainwp-ss-select').show();
        jQuery('.mainwp-ss-deselect').hide();
    }
    mainwp_newpost_updateCategories();
    return false;
};


mainwp_ss_select_disconnected = function (me, val) {
    var parent = jQuery(me).closest('.mainwp_select_sites_wrapper');
    var tab = parent.find('#select_sites_tab').val();
    if (tab == 'site') {
        parent.find('#mainwp-select-sites-list .warning.item:not(.no-select) INPUT:enabled:checkbox').each(function () {
            jQuery(this).attr('checked', val).trigger("change");
            if (val) {
                jQuery(this).closest('.item.warning.checkbox').checkbox('set checked');
            }
            else {
                jQuery(this).closest('.item.warning.checkbox').checkbox('set unchecked');
            }
        });
    }
    if (val == true) {
        jQuery('.mainwp-ss-select-disconnected').hide();
        jQuery('.mainwp-ss-deselect-disconnected').show();
    } else {
        jQuery('.mainwp-ss-select-disconnected').show();
        jQuery('.mainwp-ss-deselect-disconnected').hide();
    }
    mainwp_newpost_updateCategories();
    return false;
};

mainwp_sites_selection_onvisible_callback = function (me) {
    var selected_tab = jQuery(me).attr('select-by');
    var select_by = 'site';
    var parent = jQuery(me).closest('.mainwp_select_sites_wrapper');
    if (selected_tab == 'staging') {
        // uncheck live sites
        parent.find('#mainwp-select-sites-list INPUT:checkbox').prop('checked', false);
        parent.find('#mainwp-select-groups-list INPUT:checkbox').prop('checked', false);
        parent.find('#mainwp-select-clients-list INPUT:checkbox').prop('checked', false);
    } else if (selected_tab == 'site') {
        // uncheck staging sites
        parent.find('#mainwp-select-staging-sites-list INPUT:checkbox').prop('checked', false);
        parent.find('#mainwp-select-groups-list INPUT:checkbox').prop('checked', false);
        parent.find('#mainwp-select-clients-list INPUT:checkbox').prop('checked', false);
    } else if (selected_tab == 'group') {
        // uncheck sites
        parent.find('#mainwp-select-sites-list INPUT:checkbox').prop('checked', false);
        parent.find('#mainwp-select-staging-sites-list INPUT:checkbox').prop('checked', false);
        parent.find('#mainwp-select-clients-list INPUT:checkbox').prop('checked', false);
        select_by = 'group';
    } else if (selected_tab == 'client') {
        // uncheck sites
        parent.find('#mainwp-select-sites-list INPUT:checkbox').prop('checked', false);
        parent.find('#mainwp-select-groups-list INPUT:checkbox').prop('checked', false);
        parent.find('#mainwp-select-staging-sites-list INPUT:checkbox').prop('checked', false);
        select_by = 'client';
    }

    jQuery('.mainwp-ss-select').show();
    jQuery('.mainwp-ss-deselect').hide();

    console.log('select by: ' + select_by);

    parent.find('#select_by').val(select_by);
    parent.find('#select_sites_tab').val(selected_tab);
}


var executingUpdateCategories = false;
var queueUpdateCategories = 0;
mainwp_newpost_updateCategories = function () {
    if (executingUpdateCategories) {
        queueUpdateCategories++;
        return;
    }
    executingUpdateCategories = true;
    console.log('mainwp_newpost_updateCategories');
    var catsSelection = jQuery('#categorychecklist');
    if (catsSelection.length > 0) {
        var tab = jQuery('#select_sites_tab').val();
        var sites = [];
        var groups = [];
        var clients = [];
        if (tab == 'site') {
            sites = jQuery.map(jQuery('#mainwp-select-sites-list INPUT:checkbox:checked'), function (el) {
                return jQuery(el).val();
            });
        } else if (tab == 'staging') {
            sites = jQuery.map(jQuery('#mainwp-select-staging-sites-list INPUT:checkbox:checked'), function (el) {
                return jQuery(el).val();
            });
        } else if (tab == 'client') {
            clients = jQuery.map(jQuery('#mainwp-select-clients-list INPUT:checkbox:checked'), function (el) {
                return jQuery(el).val();
            });
        } else { //group
            groups = jQuery.map(jQuery('#mainwp-select-groups-list INPUT:checkbox:checked'), function (el) {
                return jQuery(el).val();
            });
        }

        var selected_categories = catsSelection.dropdown('get value');

        var data = mainwp_secure_data({
            action: 'mainwp_get_categories',
            sites: encodeURIComponent(sites.join(',')),
            groups: encodeURIComponent(groups.join(',')),
            clients: encodeURIComponent(clients.join(',')),
            selected_categories: selected_categories ? encodeURIComponent(selected_categories.join(',')) : '',
            post_id: jQuery('#post_ID').val()
        });

        jQuery.post(ajaxurl, data, function (pSelectedCategories) {
            return function (response) {
                response = response.trim();
                catsSelection.dropdown('remove selected');
                catsSelection.find('.sitecategory').remove();
                catsSelection.append(response);
                catsSelection.dropdown('set selected', pSelectedCategories);
                updateCategoriesPostFunc();
            };
        }(selected_categories));
    } else {
        updateCategoriesPostFunc();
    }
};
updateCategoriesPostFunc = function () {
    if (queueUpdateCategories > 0) {
        queueUpdateCategories--;
        executingUpdateCategories = false;
        mainwp_newpost_updateCategories();
    } else {
        executingUpdateCategories = false;
    }
};

jQuery(document).on('keydown', 'form[name="post"]', function (event) {
    if (event.keyCode == 13 && event.srcElement.tagName.toLowerCase() == "input") {
        event.preventDefault();
    }
});
jQuery(document).on('keyup', '#mainwp-select-sites-filter', function () {
    var filter = jQuery(this).val().toLowerCase();
    var parent = jQuery(this).closest('.mainwp_select_sites_wrapper');
    var tab = jQuery('#select_sites_tab').val();
    var siteItems = [];

    if (tab == 'group') {
        siteItems = parent.find('.mainwp_selected_groups_item');
    } else if (tab == 'site' || tab == 'staging') {
        siteItems = parent.find('.mainwp_selected_sites_item');
    }

    for (var i = 0; i < siteItems.length; i++) {
        var currentElement = jQuery(siteItems[i]);
        var value = currentElement.find('label').text().toLowerCase();
        if (value.indexOf(filter) > -1) {
            currentElement.removeClass('no-select').show();
        } else {
            currentElement.addClass('no-select').hide();
        }
    }
    if (tab == 'site' || tab == 'staging') {
        mainwp_newpost_updateCategories();
    }
});

jQuery(document).on('keyup', '#mainwp-sites-menu-filter', function () {
    var filter = jQuery(this).val().toLowerCase();
    var parent = jQuery('#mainwp-sites-sidebar-menu');
    var siteItems = parent.find('.mainwp-site-menu-item');

    for (var i = 0; i < siteItems.length; i++) {
        var currentElement = jQuery(siteItems[i]);
        var value = currentElement.find('label').text().toLowerCase();
        if (value.indexOf(filter) > -1) {
            currentElement.show();
        } else {
            currentElement.hide();
        }
    }
});

// Accordion initialization on pre-existing markup
jQuery(document).ready(function () {
    if (jQuery('.mainwp-sidebar-accordion').length > 0) {
        jQuery('.mainwp-sidebar-accordion').accordion({
            "onOpening": function () {
                var parent = jQuery(this).closest('.mainwp-sidebar-accordion');
                var ident = jQuery('.mainwp-sidebar-accordion').index(parent);
                mainwp_accordion_on_collapse(ident, 1);
            },
            "onClosing": function () {
                var parent = jQuery(this).closest('.mainwp-sidebar-accordion');
                var ident = jQuery('.mainwp-sidebar-accordion').index(parent);
                mainwp_accordion_on_collapse(ident, 0);
            }
        });
        mainwp_accordion_init_collapse();
    }
});

mainwp_accordion_on_collapse = function (ident, val) {
    if (typeof (Storage) !== 'undefined') {
        if (typeof (pagenow) !== 'undefined') {
            localStorage.setItem('mainwp-accordion[' + pagenow + '][' + ident + ']', val);
        }
    }
};
mainwp_accordion_init_collapse = function () {
    jQuery('.mainwp-sidebar-accordion .title').addClass('active');
    jQuery('.mainwp-sidebar-accordion .content').addClass('active');

    jQuery('.mainwp-sidebar-accordion').each(function () {
        var ident = jQuery('.mainwp-sidebar-accordion').index(this);
        if (typeof (pagenow) !== 'undefined') {
            val = localStorage.getItem('mainwp-accordion[' + pagenow + '][' + ident + ']');
            if (val === '0') {
                jQuery(this).find('.title').removeClass('active');
                jQuery(this).find('.content').removeClass('active');
            }
        }
    });
};

mainwp_ui_state_save = function (ident, val) {
    if (typeof (Storage) !== 'undefined') {
        localStorage.setItem('mainwp-dashboard[' + ident + ']', val);
    }
};

mainwp_ui_state_load = function (ident) {
    if (typeof (Storage) !== 'undefined') {
        return localStorage.getItem('mainwp-dashboard[' + ident + ']');
    }
    return 1; // show if Storage undefined.
};

jQuery(document).on('keyup', '#mainwp-screenshots-sites-filter', function () {
    var filter = jQuery(this).val().toLowerCase();
    var parent = jQuery(this).closest('.mainwp_select_sites_wrapper');
    var tab = jQuery('#select_sites_tab').val();
    var siteItems = [];

    if (tab == 'group') {
        siteItems = parent.find('.mainwp_selected_groups_item');
    } else if (tab == 'site' || tab == 'staging') {
        siteItems = parent.find('.mainwp_selected_sites_item');
    }

    for (var i = 0; i < siteItems.length; i++) {
        var currentElement = jQuery(siteItems[i]);
        var value = currentElement.find('label').text().toLowerCase();
        if (value.indexOf(filter) > -1) {
            currentElement.removeClass('no-select').show();
        } else {
            currentElement.addClass('no-select').hide();
        }
    }
    if (tab == 'site' || tab == 'staging') {
        mainwp_newpost_updateCategories();
    }
});

mainwp_get_icon_start = function () {
    jQuery('.cached-icon-expired').attr('queue', 1);
    bulkInstallTotal = jQuery('.cached-icon-expired[queue="1"]').length;
    console.log(bulkInstallTotal + ': expired cached icons');
    if (0 === bulkInstallTotal) {
        return;
    }
    mainwp_get_icon_start_next();
}

mainwp_get_icon_start_next = function () {
    itemIconProcess = jQuery('.cached-icon-expired[queue="1"]:first');
    if (itemIconProcess.length > 0) {
        mainwp_get_icon_start_specific(itemIconProcess);
    } else {
        console.log('Finished update icons.');
    }
};

mainwp_get_icon_start_specific = function (itemIconProcess) {
    itemIconProcess.attr('queue', '0');
    var type = itemIconProcess.attr('icon-type');
    if (type == 'plugin' || type == 'theme') {
        var slug = jQuery(itemIconProcess).attr('item-slug');
    } else {
        return;
    }

    if ('' === slug || '.' === slug) {
        mainwp_get_icon_start_next();
    }

    var data = mainwp_secure_data({
        action: 'mainwp_refresh_icon',
        slug: slug,
        type: type
    });
    jQuery.post(ajaxurl, data, function (response) {
        console.log(response + ': ' + slug);
        mainwp_get_icon_start_next();
    });
}

jQuery(document).ready(function () {
    jQuery(document).on('click', '.cached-icon-customable', function () {
        var iconObj = jQuery(this);
        jQuery('#mainwp_delete_image_field').hide();
        jQuery('#mainwp-upload-custom-icon-modal').modal('setting', 'closable', false).modal('show');
        if (iconObj[0].hasAttribute('src') && iconObj.attr('src') != '') {
            jQuery('#mainwp_delete_image_field').find('.ui.image').attr('src', iconObj.attr('src'));
            jQuery('#mainwp_delete_image_field').show();
        }
        jQuery(document).on('click', '#update_custom_icon_btn', function () {
            mainwp_upload_custom_icon(iconObj);
        });
        return false;
    });


    jQuery(document).on('click', '.mainwp-selecte-theme-button', function () {
        jQuery('#mainwp-select-mainwp-themes-modal').modal({
            allowMultiple: false,
            closable: true,
            onHide: function () {
                window.location.href = location.href;
            }
        }).modal('show');

        return false;
    });

});


mainwp_upload_custom_icon = function (iconObj) {

    var type = iconObj.attr('icon-type');

    if (type !== 'plugin' && type !== 'theme') {
        return false;
    }

    var slug = jQuery(iconObj).attr('item-slug');

    var deleteIcon = jQuery('#mainwp_delete_image_chk').is(':checked') ? true : false;

    jQuery('#mainwp-message-zone-upload').removeClass('red green yellow');
    var msg = __('Updating the icon. Please wait...');

    jQuery('#mainwp-message-zone-upload').html('<i class="notched circle loading icon"></i> ' + msg);
    jQuery('#mainwp-message-zone-upload').show();
    jQuery('#update_custom_icon_btn').attr('disabled', 'disabled');

    //Add via ajax!!
    var formdata = new FormData(jQuery('#uploadicon_form')[0]);
    formdata.append("action", 'mainwp_upload_custom_icon');
    formdata.append("type", type);
    formdata.append("slug", slug);
    formdata.append("delete", deleteIcon ? 1 : 0);
    formdata.append("security", security_nonces['mainwp_upload_custom_icon']);

    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: formdata,
        success: function (response) {
            if (response && response.result == 'success') {
                var msg = '';

                if (type === 'plugin') {
                    if (jQuery('#mainwp-show-plugins').length > 0) {
                        msg = __('Loading...');
                        mainwp_fetch_plugins();
                    }
                } else {
                    if (jQuery('#mainwp_show_themes').length > 0) {
                        msg = __('Loading...');
                        mainwp_fetch_themes();
                    }
                }
                if (msg !== '') {
                    jQuery('#mainwp-message-zone-upload').html('<i class="notched circle loading icon"></i> ' + __('Loading...'));
                } else {
                    jQuery('#mainwp-message-zone-upload').hide();
                }
                setTimeout(function () {
                    window.location.href = location.href;
                }, 3000);
            } else {
                feedback('mainwp-message-zone-upload', __('Undefined error. Please try again.'), 'red');
            }
        },
        error: function () {
        },
        contentType: false,
        cache: false,
        processData: false,
        enctype: 'multipart/form-data',
        dataType: 'json'
    });

};

mainwp_guidedtours_onchange = function (me) {
    var data = mainwp_secure_data({
        action: 'mainwp_guided_tours_option_update',
        enable: jQuery(me).is(":checked") ? 1 : 0,
    });
    jQuery.post(ajaxurl, data, function () {
        window.location.href = location.href;
    });
}