/* eslint-disable complexity */

window.wp = window.wp || {};
window.mainwpVars = window.mainwpVars || {};

let executingUpdateCategories = false;
let queueUpdateCategories = 0;

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
let mainwp_current_url = '';
function read_current_url() {
    mainwp_current_url = document.location.href.replace(/^.*?\/([^/]*?)\/?$/i, '$1');
    return mainwp_current_url;
}
function load_url(href, obj, e) { // NOSONAR - complex.
    let page = href.match(/page=/i) ? href.replace(/^.*\?page=([^&]+).*?$/i, '$1') : ''; // NOSONAR - safe with run time error.
    if (page || href == 'index.php') {
        if (!jQuery('body').hasClass('mainwp-ui-page')) {
            return;
        }
        if (typeof e !== 'undefined')
            e.preventDefault();
        jQuery('#wpbody-content').html('<div class="mainwp-loading"><img src="images/loading.gif" /> ' + __('Please wait...') + '</div>');
        if (jQuery(obj).hasClass('menu-top')) {
            let top = jQuery(obj).closest('li.menu-top');
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
            let top = jQuery(obj).closest('li.menu-top');
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
                pagenow = page; // NOSONAR - wp variable.
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
    let top = jQuery(this).scrollTop();
    let start = 20;
    jQuery('.stick-to-window').each(function () {
        let init = jQuery(this).data('init-position');
        if (top > init.top)
            jQuery(this).stop().animate({ top: (top - init.top) + init.top + start }, 1000);
        else
            jQuery(this).stop().css({ top: init.top });
    });
}
function stick_element_init() {
    jQuery('.stick-to-window').each(function () {
        let pos = jQuery(this).position();
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
jQuery(function () {
    jQuery(window).trigger('scroll', scroll_element).trigger('resize', stick_element_reset);
    stick_element_init();
});

// eslint-disable-next-line complexity
window.mainwp_confirm = function (msg, confirmed_callback, cancelled_callback, updateType, multiple, extra) {    // updateType: 1 single update, 2 multi update
    let confVal;
    if (jQuery('#mainwp-disable-update-confirmations').length > 0) {
        confVal = jQuery('#mainwp-disable-update-confirmations').val();
    }
    if (jQuery('#mainwp-disable-update-confirmations').length > 0 && typeof updateType !== 'undefined' && updateType !== false && (confVal == 2 || (confVal == 1 && updateType == 1)) && confirmed_callback && typeof confirmed_callback == 'function') {
        confirmed_callback();
        return;
    }


    jQuery('#mainwp-modal-confirm .content-massage').html(msg);

    if (typeof extra !== 'undefined' && extra !== false) {
        jQuery('#mainwp-confirm-form').show();
        jQuery('#mainwp-confirm-form').find('label').html('Type ' + extra + ' to confirm');
    }

    let opts = {
        onApprove: function () {
            if (typeof extra !== 'undefined' && extra !== false) {
                let extraValue = jQuery('#mainwp-confirm-input').val();
                if (confirmed_callback && typeof confirmed_callback == 'function') {
                    if (extraValue === extra) {
                        confirmed_callback();
                    } else {
                        jQuery('#mainwp-confirm-input').val('').trigger('focus').transition('shake');
                        return;
                    }
                }
            } else if (confirmed_callback && typeof confirmed_callback == 'function') {
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
}


/**
 * Select sites
 */
jQuery(document).on('change', '.mainwp_selected_sites_item input:checkbox', function () {
    if (jQuery(this).is(':checked'))
        jQuery(this).parent().addClass('selected_sites_item_checked');
    else
        jQuery(this).parent().removeClass('selected_sites_item_checked');
    mainwp_site_select();
});

jQuery(document).on('change', '.mainwp_selected_groups_item input:checkbox', function () {
    if (jQuery(this).is(':checked'))
        jQuery(this).parent().addClass('selected_groups_item_checked');
    else
        jQuery(this).parent().removeClass('selected_groups_item_checked');
    mainwp_group_select();
});

jQuery(document).on('change', '.mainwp_selected_clients_item input:checkbox', function () {
    if (jQuery(this).is(':checked'))
        jQuery(this).parent().addClass('selected_clients_item_checked');
    else
        jQuery(this).parent().removeClass('selected_clients_item_checked');
    mainwp_client_select();
});


jQuery(function () {
    // seems not used.
    jQuery('.mainwp_selected_sites_item input:radio').on('change', function () {
        if (jQuery(this).is(':checked')) {
            jQuery(this).parent().addClass('selected_sites_item_checked');
            jQuery(this).parent().parent().find('.mainwp_selected_sites_item input:radio:not(:checked)').parent().removeClass('selected_sites_item_checked');
        } else
            jQuery(this).parent().removeClass('selected_sites_item_checked');
        mainwp_site_select();
    });
    // seems not used.
    jQuery('.mainwp_selected_groups_item input:radio').on('change', function () {
        if (jQuery(this).is(':checked')) {
            jQuery(this).parent().addClass('selected_groups_item_checked');
            jQuery(this).parent().parent().find('.mainwp_selected_groups_item input:radio:not(:checked)').parent().removeClass('selected_groups_item_checked');
        } else
            jQuery(this).parent().removeClass('selected_groups_item_checked');
    });
});

let mainwp_site_select = function () {
    mainwp_newpost_updateCategories();
};
let mainwp_group_select = function () {
    mainwp_newpost_updateCategories();
};
let mainwp_client_select = function () {
    mainwp_newpost_updateCategories();
};

let mainwp_ss_select = function (me, val) {
    let parent = jQuery(me).closest('.mainwp_select_sites_wrapper');
    let tab = parent.find('#select_sites_tab').val();
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
    if (val) {
        jQuery('.mainwp-ss-select').hide();
        jQuery('.mainwp-ss-deselect').show();
    } else {
        jQuery('.mainwp-ss-select').show();
        jQuery('.mainwp-ss-deselect').hide();
    }
    mainwp_newpost_updateCategories();
    return false;
};


let mainwp_ss_select_disconnected = function (me, val) {
    let parent = jQuery(me).closest('.mainwp_select_sites_wrapper');
    let tab = parent.find('#select_sites_tab').val();
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
    if (val) {
        jQuery('.mainwp-ss-select-disconnected').hide();
        jQuery('.mainwp-ss-deselect-disconnected').show();
    } else {
        jQuery('.mainwp-ss-select-disconnected').show();
        jQuery('.mainwp-ss-deselect-disconnected').hide();
    }
    mainwp_newpost_updateCategories();
    return false;
};

window.mainwp_sites_selection_onvisible_callback = function (me) {
    let selected_tab = jQuery(me).attr('select-by');
    let select_by = 'site';
    let parent = jQuery(me).closest('.mainwp_select_sites_wrapper');
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


let mainwp_newpost_updateCategories = function () {
    if (executingUpdateCategories) {
        queueUpdateCategories++;
        return;
    }
    executingUpdateCategories = true;
    console.log('mainwp_newpost_updateCategories');
    let catsSelection = jQuery('#categorychecklist');
    if (catsSelection.length > 0) {
        let tab = jQuery('#select_sites_tab').val();
        let sites = [];
        let groups = [];
        let clients = [];
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

        let selected_categories = catsSelection.dropdown('get value');

        console.log('selected cats:');
        console.log(selected_categories);

        let data = mainwp_secure_data({
            action: 'mainwp_get_categories',
            sites: encodeURIComponent(sites.join(',')),
            groups: encodeURIComponent(groups.join(',')),
            clients: encodeURIComponent(clients.join(',')),
            selected_categories: selected_categories ? encodeURIComponent(selected_categories) : '',
            post_id: jQuery('#post_ID').val(),
            custom_post_type: jQuery('#mainwp_custom_post_type_edit_post').length ? jQuery('#mainwp_custom_post_type_edit_post').val() : '' // support CPT ext.
        });

        jQuery.post(ajaxurl, data, function (pSelectedCategories) {
            return function (response) {
                if (response?.content) {
                    catsSelection.dropdown('remove selected');
                    catsSelection.find('.menu').html(response.content);
                    catsSelection.dropdown('refresh');
                    let arrVal = pSelectedCategories.split(",");
                    catsSelection.dropdown('set selected', arrVal);
                    console.log('re-selected: ' + pSelectedCategories);
                    updateCategoriesPostFunc();
                }
            };
        }(selected_categories), 'json');
    } else {
        updateCategoriesPostFunc();
    }
};
let updateCategoriesPostFunc = function () {
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
jQuery(document).on('keyup', '#mainwp-sites-menu-filter', function () {
    let filter = jQuery(this).val().toLowerCase();
    let parent = jQuery('#mainwp-sites-sidebar-menu');
    let siteItems = parent.find('.mainwp-site-menu-item');

    for (let ss of siteItems) {
        let currentElement = jQuery(ss);
        let value = currentElement.find('label').text().toLowerCase();
        if (value.indexOf(filter) > -1) {
            currentElement.show();
        } else {
            currentElement.hide();
        }
    }
});

// Accordion initialization on pre-existing markup
jQuery(function () {
    mainwp_sidebar_accordion_init();
});

window.mainwp_sidebar_accordion_init = function () {
    if (jQuery('.mainwp-sidebar-accordion').length > 0) {
        console.log('sidebar accordion init');
        jQuery('.mainwp-sidebar-accordion').accordion({
            "onOpening": function () {
                let parent = jQuery(this).closest('.mainwp-sidebar-accordion');
                let ident = jQuery('.mainwp-sidebar-accordion').index(parent);
                mainwp_accordion_on_collapse(ident, 1);
            },
            "onClosing": function () {
                let parent = jQuery(this).closest('.mainwp-sidebar-accordion');
                let ident = jQuery('.mainwp-sidebar-accordion').index(parent);
                mainwp_accordion_on_collapse(ident, 0);
            }
        });
        mainwp_accordion_init_collapse();
    }
}


let mainwp_accordion_on_collapse = function (ident, val) {
    if (typeof (Storage) !== 'undefined') {
        if (typeof (pagenow) !== 'undefined') {
            localStorage.setItem('mainwp-accordion[' + pagenow + '][' + ident + ']', val);
        }
    }
};
let mainwp_accordion_init_collapse = function () {
    jQuery('.mainwp-sidebar-accordion .title').addClass('active');
    jQuery('.mainwp-sidebar-accordion .content').addClass('active');

    jQuery('.mainwp-sidebar-accordion').each(function () {
        let ident = jQuery('.mainwp-sidebar-accordion').index(this);
        if (typeof (pagenow) !== 'undefined') {
            let val = localStorage.getItem('mainwp-accordion[' + pagenow + '][' + ident + ']');
            if (val === '0') {
                jQuery(this).find('.title').removeClass('active');
                jQuery(this).find('.content').removeClass('active');
            }
        }
    });
};

window.mainwp_ui_state_save = function (ident, val) {
    if (typeof (Storage) !== 'undefined') {
        localStorage.setItem('mainwp-dashboard[' + ident + ']', val);
    }
};

window.mainwp_ui_state_load = function (ident) {
    if (typeof (Storage) !== 'undefined') {
        return localStorage.getItem('mainwp-dashboard[' + ident + ']');
    }
    return '1'; // show if Storage undefined.
};

window.mainwp_ui_state_init = function (ident, callback) {
    if (typeof (Storage) !== 'undefined') {
        let state = mainwp_ui_state_load(ident);
        if (typeof callback === 'function') {
            callback(state);
        }
    }
};

window.mainwp_sites_filter_select = function (objInput) {
    let filter = jQuery(objInput).val().toLowerCase();
    let parent = jQuery(objInput).closest('.mainwp_select_sites_wrapper');
    let tab = jQuery('#select_sites_tab').val();
    let siteItems = [];

    if (tab == 'group') {
        siteItems = parent.find('.mainwp_selected_groups_item');
    } else if (tab == 'site' || tab == 'staging') {
        siteItems = parent.find('.mainwp_selected_sites_item');
    }

    for (let id of siteItems) {
        let currentElement = jQuery(id);
        let value = currentElement.find('label').text().toLowerCase();
        if (value.indexOf(filter) > -1) {
            currentElement.removeClass('no-select').show();
        } else {
            currentElement.addClass('no-select').hide();
        }
    }
    if (tab == 'site' || tab == 'staging') {
        mainwp_newpost_updateCategories();
    }
}

jQuery(document).on('keyup', '#mainwp-select-sites-filter', function () {
    mainwp_sites_filter_select(this);
});

jQuery(document).on('keyup', '#mainwp-screenshots-sites-filter', function () {
    mainwp_sites_filter_select(this);
});

jQuery(function () {
    if (jQuery(document).find('#mainwp-select-sites-header .ui.menu .item').length) {
        jQuery(document).find('#mainwp-select-sites-header .ui.menu .item').tab({ 'onVisible': function () { mainwp_sites_selection_onvisible_callback(this); } });
    }
});

window.mainwp_get_icon_start = function () {
    jQuery('.cached-icon-expired').attr('queue', 1);
    mainwpVars.bulkInstallTotal = jQuery('.cached-icon-expired[queue="1"]').length;
    console.log(mainwpVars.bulkInstallTotal + ': expired cached icons');
    if (0 === mainwpVars.bulkInstallTotal) {
        return;
    }
    mainwp_get_icon_start_next();
}

let mainwp_get_icon_start_next = function () {
    let itemIconProcess = jQuery('.cached-icon-expired[queue="1"]:first');
    if (itemIconProcess.length > 0) {
        mainwp_get_icon_start_specific(itemIconProcess);
    } else {
        console.log('Finished update icons.');
    }
};

let mainwp_get_icon_start_specific = function (itemIconProcess) {
    itemIconProcess.attr('queue', '0');
    let slug;
    let type = itemIconProcess.attr('icon-type');
    if (type == 'plugin' || type == 'theme') {
        slug = jQuery(itemIconProcess).attr('item-slug');
    } else {
        return;
    }

    if ('' === slug || '.' === slug) {
        mainwp_get_icon_start_next();
    }

    let data = mainwp_secure_data({
        action: 'mainwp_refresh_icon',
        slug: slug,
        type: type
    });
    jQuery.post(ajaxurl, data, function (response) {
        console.log(response + ': ' + slug);
        mainwp_get_icon_start_next();
    });
}

jQuery(function () {
    jQuery(document).on('click', '.cached-icon-customable', function () {
        let iconObj = jQuery(this);
        jQuery('#mainwp_delete_image_field').hide();
        jQuery('#mainwp-upload-custom-icon-modal').modal('setting', 'closable', false).modal('show');
        if (iconObj[0].hasAttribute('src') && iconObj.attr('src') != '') {
            jQuery('#mainwp_delete_image_field').find('.ui.image').attr('src', iconObj.attr('src'));
            jQuery('#mainwp_delete_image_field').show();
        }
        jQuery(document).on('click', '#update_custom_icon_btn', function () {
            mainwp_upload_custom_icon(iconObj);
            return false;
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


let mainwp_upload_custom_icon = function (iconObj) {

    let type = iconObj.attr('icon-type');

    if (type !== 'plugin' && type !== 'theme') {
        return false;
    }

    let slug = jQuery(iconObj).attr('item-slug');

    let deleteIcon = jQuery('#mainwp_delete_image_chk').is(':checked');

    let msg = __('Updating the icon. Please wait...');
    mainwp_set_message_zone('#mainwp-message-zone-upload', '<i class="notched circle loading icon"></i> ' + msg, '');
    jQuery('#update_custom_icon_btn').attr('disabled', 'disabled');

    //Add via ajax!!
    let formdata = new FormData(jQuery('#uploadicon_form')[0]);
    formdata.append("action", 'mainwp_upload_custom_icon');
    formdata.append("type", type);
    formdata.append("slug", slug);
    formdata.append("delete", deleteIcon ? 1 : 0);
    formdata.append("security", security_nonces['mainwp_upload_custom_icon']);
    formdata.append("delnonce", jQuery(iconObj).attr('del-icon-nonce'));

    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: formdata,
        success: function (response) {
            if (response && response.result == 'success') {
                let msg = '';

                if (type === 'plugin') {
                    if (jQuery('#mainwp-show-plugins').length > 0) {
                        msg = __('Loading...');
                        mainwp_fetch_plugins();
                    }
                } else if (jQuery('#mainwp_show_themes').length > 0) {
                    msg = __('Loading...');
                    mainwp_fetch_themes();
                }
                if (msg !== '') {
                    mainwp_set_message_zone('#mainwp-message-zone-upload', '<i class="notched circle loading icon"></i> ' + __('Loading...'), '');
                } else {
                    mainwp_set_message_zone('#mainwp-message-zone-upload');
                }
                setTimeout(function () {
                    window.location.href = location.href;
                }, 3000);
            } else if (response.error) {
                feedback('mainwp-message-zone-upload', response.error, 'red');
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

let mainwp_upload_custom_types_icon = function (iconObj, uploadAct, iconItemId, iconFileSlug, deleteIcon, callback_uploaded) {
    let msg = __('Updating the icon. Please wait...');
    mainwp_set_message_zone('#mainwp-message-zone-upload', '<i class="notched circle loading icon"></i> ' + msg, '');
    jQuery('#update_custom_icon_btn').attr('disabled', 'disabled');

    let upload_act = typeof uploadAct !== "undefined" && '' != uploadAct ? uploadAct : 'mainwp_upload_custom_types_icon';
    let elemid = undefined !== jQuery(iconObj).attr('data-element-id') ? jQuery(iconObj).attr('data-element-id') : '';
    let delNonce = undefined !== jQuery(iconObj).attr('del-icon-nonce') ? jQuery(iconObj).attr('del-icon-nonce') : '';

    //Add via ajax!!
    let formdata = new FormData(jQuery('#uploadicon_form')[0]);
    formdata.append("action", upload_act);
    formdata.append("iconFileSlug", iconFileSlug);
    formdata.append("iconItemId", iconItemId);
    formdata.append("delete", deleteIcon ? 1 : 0);
    formdata.append("security", security_nonces[upload_act]);
    formdata.append("elementid", elemid);
    formdata.append("delnonce", delNonce);

    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: formdata,
        success: function (response) {
            jQuery('#update_custom_icon_btn').removeAttr('disabled');
            if (response && response.result == 'success') {
                mainwp_set_message_zone('#mainwp-message-zone-upload');
                if (typeof callback_uploaded == 'function') {
                    callback_uploaded(response);
                }
            } else if (response.error) {
                feedback('mainwp-message-zone-upload', response.error, 'red');
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

let mainwp_guidedtours_onchange = function (me) {
    let data = mainwp_secure_data({
        action: 'mainwp_guided_tours_option_update',
        enable: jQuery(me).is(":checked") ? 1 : 0,
    });
    jQuery.post(ajaxurl, data, function () {
        setTimeout(() => {
            window.location.href = location.href;
        }, 1000);
    });
}

let mainwp_help_modal_content_onclick = function (hide, isToolsPage) {

    // to fix confict ids when current page is tool page.
    let enab_tour = jQuery('#mainwp-guided-tours-check').checkbox('is checked') ? 1 : 0;
    let enab_video = jQuery('#mainwp-guided-video-check').checkbox('is checked') ? 1 : 0;
    let enab_chatbase = jQuery('#mainwp-guided-chatbase-check').checkbox('is checked') ? 1 : 0;

    let data = mainwp_secure_data({
        action: 'mainwp_help_modal_content_update',
        enable_tour: enab_tour,
        enable_video: enab_video,
        enable_chatbase: enab_chatbase,
    });

    jQuery.post(ajaxurl, data, function () {
        if (typeof isToolsPage !== "undefined" && isToolsPage) {
            location.href = 'admin.php?page=MainWPTools';
        } else {
            jQuery('#revoke-third-party-perms').fadeIn('100');
            jQuery('#mainwp-help-modal-content').fadeIn('100');
            jQuery('#mainwp-help-modal-consent-content').fadeOut('100');

            if (enab_tour) {
                jQuery('#mainwp-start-tour-card').fadeIn('100');
            }
            if (enab_chatbase) {
                jQuery('#mainwp-start-chat-card').fadeIn('100');
            }
            if (enab_video) {
                jQuery('#mainwp-start-video-card').fadeIn('100');
            }
        }
    });
}

let mainwp_help_modal_start_content_onclick = function (tour_id, video_id, show_chat) {
    jQuery('#mainwp-chatbase-chat-screen').fadeOut('100');
    jQuery('#mainwp-chatbase-video-screen').fadeOut('100');
    jQuery('#mainwp-help-modal-options').fadeOut('50');

    if (tour_id) {
        window.USETIFUL.tour.start(tour_id);
    }

    if (video_id) {
        jQuery('#mainwp-chatbase-video-screen').fadeIn('100').find('iframe').attr('src', 'https://www.youtube.com/embed/' + video_id);
    }

    if (show_chat) {
        jQuery('#mainwp-chatbase-chat-screen').fadeIn('100').find('iframe').attr('src', 'https://supportassistant.mainwp.com/chatbot-iframe/Tv5dqV-xiQxwgPeMQFCZ4');
        jQuery('#mainwp-start-tour-button').fadeIn('100');
    }
}