/* eslint-disable complexity */
// current complexity is the only way to achieve desired results, pull request solutions appreciated.

window.mainwpVars = window.mainwpVars || {};

mainwpVars.errorCount = 0;

mainwpVars.actionsErrors = {};

window.mainwp_put_actions_errors_msg = function (action, itemId, msgType, errorMsg) {
    mainwpVars.actionsErrors[action] = mainwpVars.actionsErrors?.[action] || {};
    mainwpVars.actionsErrors[action][itemId] = mainwpVars.actionsErrors[action]?.[itemId] || {};
    mainwpVars.actionsErrors[action][itemId][msgType] = mainwpVars.actionsErrors[action]?.[itemId]?.[msgType] || [];
    mainwpVars.actionsErrors[action][itemId][msgType].push(errorMsg);
}

window.mainwp_get_actions_errors_msg = function (action, itemId, msgType) {
    let errors = mainwpVars.actionsErrors?.[action]?.[itemId]?.[msgType] || [];
    let array = errors.map(function (val) {
        return val;
    });
    array = array.filter(function (el) {
        return el;
    });
    return array.join('<br/>');
}

window.mainwp_updates_get_rollback_msg = function (error) {
    if (error && typeof error == "string") {
        if (error.startsWith('[Roll]')) {
            return error.replace('[Roll]', '');
        }
    }
    return '';
}

window.mainwp_init_html_popup = function (popupSelector, content) {
    jQuery(popupSelector).popup({
        html: function () {
            if (typeof content === 'undefined') {
                if (typeof popupSelector !== 'string') {
                    // popup selector is object.
                    content = jQuery(popupSelector).attr('html-popup-content') ?? ''
                }
            }
            return '<div class="mainwp-html-popup-body">' + content + '</div>';
        }
    });
};

// Init Per Group data
let updatesoverview_updates_init_group_view = function () {
    jQuery('.element_ui_view_values').each(function () {
        let parent = jQuery(this).parent();
        let uid = jQuery(this).attr('elem-uid');
        let total = jQuery(this).attr('total');
        let can_update = jQuery(this).attr('can-update');

        if (total == 0) {
            // carefully remove this, or it will causing error with display or according sorting
            jQuery(parent).find("[row-uid='" + uid + "']").next().remove(); // remove content according part
            jQuery(parent).find("[row-uid='" + uid + "']").remove(); // remove title according part
        } else {
            jQuery(parent).find("[total-uid='" + uid + "']").html(total + ' ' + (total == 1 ? __('Update') : __('Updates')));
            jQuery(parent).find("[total-uid='" + uid + "']").attr('sort-value', total);
        }

        if (can_update) {
            jQuery(parent).find("[btn-all-uid='" + uid + "']").text(total == 1 ? __('Update') : __('Update All')).show();
        }
    });
}

// Update individual WP
let updatesoverview_upgrade = function (id, obj) {

    let parent = jQuery(obj).closest('.mainwp-wordpress-update');
    let upgradeElement = jQuery(parent).find('#wp-updated-' + id);

    if (upgradeElement.val() != 0)
        return false;


    updatesoverviewContinueAfterBackup = function (pId, pUpgradeElement) {
        return function () {
            jQuery('.mainwp-wordpress-update[site_id="' + pId + '"] > td:last-child').html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Updating...', 'mainwp') + '"><i class="notched circle loading icon"></i></span> ' + __('Updating. Please wait...'));
            pUpgradeElement.val(1);
            let data = mainwp_secure_data({
                action: 'mainwp_upgradewp',
                id: pId
            });

            jQuery.post(ajaxurl, data, function (response) {
                if (response.error) {
                    let err_msg = '';
                    if (response.error.extra) {
                        err_msg = response.error.extra + ' ';
                    }
                    jQuery('.mainwp-wordpress-update[site_id="' + pId + '"] > td:last-child').html('<span data-inverted="" data-position="left center" data-tooltip="' + err_msg + '"><i class="red times icon"></i></span>' + ' ' + mainwp_links_visit_site_and_admin('', pId));
                } else {
                    jQuery('.mainwp-wordpress-update[site_id="' + pId + '"] > td:last-child').html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Update successful', 'mainwp') + '">' + response.result + '</span>' + ' ' + mainwp_links_visit_site_and_admin('', pId));
                }


            }, 'json');
        }
    }(id, upgradeElement);

    let sitesToUpdate = [id];
    let siteNames = [];

    siteNames[id] = jQuery('.mainwp-wordpress-update[site_id="' + id + '"]').attr('site_name');

    let msg = __('Are you sure you want to update the Wordpress core files on the selected site?');
    mainwp_confirm(msg, function () {
        return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
    }, false, 1);
};

/** Update bulk **/

mainwpVars.websitesToUpgrade = [];
let updatesoverviewContinueAfterBackup;
let limitUpdateAll = 0;
let continueUpdatesAll = '', continueUpdatesSlug = '';
let continueUpdating = false;
let updatesoverview_update_popup_init = function (data) {
    data = data || {};
    data.allowMultiple = true;
    data.callback = function () {
        mainwpVars.bulkTaskRunning = false;
        window.location.href = location.href;
    };
    data.statusText = __('updated');
    mainwpPopup('#mainwp-sync-sites-modal').init(data);
}

let updatesmanage_link_to_site = function (name, siteid) {
    return '<a href="admin.php?page=managesites&dashboard=' + siteid + '">' + name + '</a>';
}

// Update Group
let updatesoverview_wordpress_global_upgrade_all = function (groupId, updatesSelected) {
    if (mainwpVars.bulkTaskRunning)
        return;

    //Step 1: build form
    let sitesToUpdate = [];
    let siteNames = {};
    let foundChildren = updatesoverview_wordpress_get_global_upgrade_all(groupId, updatesSelected);

    if (foundChildren.length == 0)
        return;

    let sitesCount = 0;

    mainwpPopup('#mainwp-sync-sites-modal').clearList();

    for (let i = 0; i < foundChildren.length; i++) {
        if (limitUpdateAll > 0 && i >= limitUpdateAll && typeof groupId === 'undefined') {
            continueUpdatesAll = 'wpcore_global_upgrade_all';
            break;
        }

        let child = foundChildren[i];
        let siteId = jQuery(child).attr('site_id');
        let siteName = jQuery(child).attr('site_name');

        if (sitesToUpdate.indexOf(siteId) == -1) {
            sitesCount++;
            sitesToUpdate.push(siteId);
            siteNames[siteId] = siteName;
        }
    }

    let _callback = function () {
        for (let id of sitesToUpdate) {
            mainwpPopup('#mainwp-sync-sites-modal').appendItemsList(mainwp_links_visit_site_and_admin('', id) + ' ' + updatesmanage_link_to_site(decodeURIComponent(siteNames[id]), id) + ' (WordPress update)', '<span class="updatesoverview-upgrade-status-wp" siteid="' + id + '">' + '<i class="clock outline icon"></i> ' + '</span>');
        }
        updatesoverviewContinueAfterBackup = function (pSitesCount, pSitesToUpdate) {
            return function () {
                let initData = {
                    title: __('Updating All'),
                    progressMax: pSitesCount,
                };

                updatesoverview_update_popup_init(initData);
                //Step 3: start updates
                updatesoverview_wordpress_upgrade_all_int(pSitesToUpdate);

                updatesoverviewContinueAfterBackup = undefined;
            }
        }(sitesCount, sitesToUpdate);

        return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
    };

    // new confirm message
    if (!continueUpdating) {
        if (jQuery(siteNames).length > 0) {
            let sitesList = [];
            jQuery.each(siteNames, function (index, value) {
                if (value) { // to fix
                    sitesList.push(decodeURIComponent(value));
                }
            });
            let confirmMsg = __('You are about to update %1 on the following site(s): <br/><div class="ui message">%2</div> <strong>Do you want to proceed?</strong>', __('WordPress Core'), sitesList.join('<br />'));
            mainwp_confirm(confirmMsg, _callback, false, 2);
        }
        return;
    }
    _callback();
};


let mainwp_updates_get_selected_rows_values = function (selector, item_selector) {
    let foundChildren = [];
    if (selector && item_selector) {
        jQuery(selector).find(item_selector).each(
            function () {
                if (jQuery(this).find('.child.checkbox').checkbox('is checked')) {
                    foundChildren.push(this);
                }
            }
        );
    } else {
        jQuery(selector).each(
            function () {
                if (jQuery(this).find('.child.checkbox').checkbox('is checked')) {
                    foundChildren.push(this);
                }
            }
        );
    }
    return foundChildren;
}

let updatesoverview_wordpress_get_global_upgrade_all = function (groupId, updatesSelected) {
    let foundChildren = [];
    if (typeof updatesSelected !== 'undefined' && updatesSelected) {
        if (typeof groupId !== 'undefined' && false !== groupId) {
            foundChildren = mainwp_updates_get_selected_rows_values('#update_wrapper_wp_upgrades_group_' + groupId, 'tr.mainwp-wordpress-update[updated="0"]');
        } else {
            foundChildren = mainwp_updates_get_selected_rows_values('tr.mainwp-wordpress-update[updated="0"]');
        }
        if (foundChildren.length == 0) {
            updates_please_select_items_notice();
            return false;
        }
    } else if (typeof groupId !== 'undefined' && false !== groupId) {
        // groups selector is only one for each screen.
        foundChildren = jQuery('#update_wrapper_wp_upgrades_group_' + groupId).find('tr.mainwp-wordpress-update[updated="0"]');
    } else {
        // childs selector is only one for each screen.
        foundChildren = jQuery('tr.mainwp-wordpress-update[updated="0"]');
    }
    return foundChildren;
}

let updatesoverview_wordpress_upgrade_all_int = function (websiteIds) {
    mainwpVars.websitesToUpgrade = websiteIds;
    mainwpVars.currentWebsite = 0;
    mainwpVars.websitesDone = 0;
    mainwpVars.websitesTotal = mainwpVars.websitesLeft = mainwpVars.websitesToUpgrade.length;

    mainwpVars.bulkTaskRunning = true;
    updatesoverview_wordpress_upgrade_all_loop_next();
};
let updatesoverview_wordpress_upgrade_all_loop_next = function () {
    while (mainwpVars.bulkTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxThreads) && (mainwpVars.websitesLeft > 0)) {
        updatesoverview_wordpress_upgrade_all_upgrade_next();
    }
};
let updatesoverview_wordpress_upgrade_all_update_site_status = function (siteId, newStatus) {
    jQuery('.updatesoverview-upgrade-status-wp[siteid="' + siteId + '"]').html(newStatus);
};
let updatesoverview_wordpress_upgrade_all_upgrade_next = function () {
    mainwpVars.currentThreads++;
    mainwpVars.websitesLeft--;

    let websiteId = mainwpVars.websitesToUpgrade[mainwpVars.currentWebsite++];
    updatesoverview_wordpress_upgrade_all_update_site_status(websiteId, __('<i class="notched circle loading icon"></i>'));

    updatesoverview_wordpress_upgrade_int(websiteId, true);
};
let updatesoverview_wordpress_upgrade_all_update_done = function () {
    mainwpVars.currentThreads--;
    if (!mainwpVars.bulkTaskRunning)
        return;
    mainwpVars.websitesDone++;
    mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);

    updatesoverview_wordpress_upgrade_all_loop_next();
};
let updatesoverview_wordpress_upgrade_int = function (websiteId, bulkMode) {

    let data = mainwp_secure_data({
        action: 'mainwp_upgradewp',
        id: websiteId
    });
    jQuery.post(ajaxurl, data, function (pWebsiteId, pBulkMode) {
        return function (response) {
            if (response.error) {
                let err_msg = '';
                if (response.error.extra) {
                    err_msg = response.error.extra + ' ';
                }
                if (pBulkMode)
                    updatesoverview_wordpress_upgrade_all_update_site_status(pWebsiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + err_msg + '"><i class="red times icon"></i></span>');
            } else if (pBulkMode) {
                updatesoverview_wordpress_upgrade_all_update_site_status(pWebsiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Update successful', 'mainwp') + '">' + response.result + '</span>');
            }
            updatesoverview_wordpress_upgrade_all_update_done();
        }
    }(websiteId, bulkMode), 'json');

    return false;
};

let currentTranslationSlugToUpgrade;
let websitesTranslationSlugsToUpgrade;
let updatesoverview_translations_global_upgrade_all = function (groupId, updatesSelected) { // NOSONAR - Complexity 18/15.
    if (mainwpVars.bulkTaskRunning)
        return;

    //Step 1: build form
    let sitesToUpdate = [];
    let siteNames = {};
    let sitesTranslationSlugs = {};
    let foundChildren = updatesoverview_translations_get_global_upgrade_all(groupId, updatesSelected);
    if (foundChildren.length == 0)
        return;
    let sitesCount = 0;

    mainwpPopup('#mainwp-sync-sites-modal').clearList();

    for (let i = 0; i < foundChildren.length; i++) {
        if (limitUpdateAll > 0 && i >= limitUpdateAll && typeof groupId === 'undefined') {
            continueUpdatesAll = 'translations_global_upgrade_all';
            break;
        }
        let child = jQuery(foundChildren[i]);
        let parent = child.parent(); // to fix

        let siteElement;
        let translationElement;

        let checkAttr = child.attr('site_id');
        if ((typeof checkAttr !== 'undefined') && (checkAttr !== false)) {
            siteElement = child;
            translationElement = parent;
        } else {
            siteElement = parent;
            translationElement = child;
        }

        let siteId = siteElement.attr('site_id');
        let siteName = siteElement.attr('site_name');
        let translationSlug = translationElement.attr('translation_slug');

        if (sitesToUpdate.indexOf(siteId) == -1) {
            sitesCount++;
            sitesToUpdate.push(siteId);
            siteNames[siteId] = siteName;
        }
        if (sitesTranslationSlugs[siteId] == undefined) {
            sitesTranslationSlugs[siteId] = translationSlug;
        } else {
            sitesTranslationSlugs[siteId] += ',' + translationSlug;
        }
    }

    let _callback = function () {
        for (let id of sitesToUpdate) {
            let updateCount = sitesTranslationSlugs[id].match(/,/g);
            if (updateCount == null)
                updateCount = 1;
            else
                updateCount = updateCount.length + 1;

            mainwpPopup('#mainwp-sync-sites-modal').appendItemsList(mainwp_links_visit_site_and_admin('', id) + ' ' + updatesmanage_link_to_site(decodeURIComponent(siteNames[id]), id) + ' (' + updateCount + ' translations)', '<span class="updatesoverview-upgrade-status-wp" siteid="' + id + '">' + '<i class="clock outline icon"></i> ' + '</span>');
        }

        updatesoverviewContinueAfterBackup = function (pSitesCount, pSitesToUpdate, pSitesTranslationSlugs) {
            return function () {

                let initData = {
                    title: __('Updating all...'),
                    progressMax: pSitesCount
                };
                updatesoverview_update_popup_init(initData);

                //Step 3: start updates
                updatesoverview_translations_upgrade_all_int(undefined, pSitesToUpdate, pSitesTranslationSlugs);

                updatesoverviewContinueAfterBackup = undefined;
            }
        }(sitesCount, sitesToUpdate, sitesTranslationSlugs);


        return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
    }


    // new confirm message
    if (!continueUpdating) {
        if (jQuery(siteNames).length > 0) {
            let sitesList = [];
            jQuery.each(siteNames, function (index, value) {
                if (value) { // to fix
                    sitesList.push(decodeURIComponent(value));
                }
            });
            let confirmMsg = __('You are about to update %1 on the following site(s):\n%2?', 'translations', sitesList.join(', '));
            mainwp_confirm(confirmMsg, _callback, false, 2);
        }
        return false;
    }
    _callback();
};

let updatesoverview_translations_get_global_upgrade_all = function (groupId, updatesSelected) {
    let foundChildren = [];
    if (typeof updatesSelected !== 'undefined' && updatesSelected) {
        if (typeof groupId !== 'undefined' && false !== groupId) {
            foundChildren = mainwp_updates_get_selected_rows_values('#update_wrapper_translation_upgrades_group_' + groupId, 'tr.mainwp-translation-update[updated="0"]');
        } else {
            foundChildren = mainwp_updates_get_selected_rows_values('#translations-updates-global', 'table tr[updated="0"]');
        }
        if (foundChildren.length == 0) {
            updates_please_select_items_notice();
            return;
        }
    } else if (typeof groupId !== 'undefined' && false !== groupId) {
        foundChildren = jQuery('#update_wrapper_translation_upgrades_group_' + groupId).find('tr.mainwp-translation-update[updated="0"]');
    } else {
        foundChildren = jQuery('#translations-updates-global').find('table tr[updated="0"]');
    }
    return foundChildren;
}

let updatesoverview_translations_upgrade_all = function (slug, translationName) {
    if (mainwpVars.bulkTaskRunning)
        return;
    //Step 1: build form
    let sitesToUpdate = [];
    let siteNames = [];
    let foundChildren = jQuery('.translations-bulk-updates[translation_slug="' + slug + '"]').find('tr[updated="0"]');

    if (foundChildren.length == 0)
        return;

    mainwpPopup('#mainwp-sync-sites-modal').clearList();

    for (let i = 0; i < foundChildren.length; i++) {
        if (limitUpdateAll > 0 && i >= limitUpdateAll) {
            continueUpdatesAll = 'translations_upgrade_all';
            continueUpdatesSlug = slug;
            break;
        }
        let child = foundChildren[i];
        let siteId = jQuery(child).attr('site_id');
        let siteName = jQuery(child).attr('site_name');
        siteNames[siteId] = siteName;
        sitesToUpdate.push(siteId);
    }

    translationName = decodeURIComponent(translationName);
    translationName = translationName.replace(/\+/g, ' ');

    let _callback = function () {

        for (let id of sitesToUpdate) {
            mainwpPopup('#mainwp-sync-sites-modal').appendItemsList(mainwp_links_visit_site_and_admin('', id) + ' ' + updatesmanage_link_to_site(decodeURIComponent(siteNames[id]), id), '<span class="updatesoverview-upgrade-status-wp" siteid="' + id + '">' + '<i class="clock outline icon"></i> ' + '</span>');
        }

        let sitesCount = sitesToUpdate.length;

        updatesoverviewContinueAfterBackup = function (pSitesCount, pSlug, pSitesToUpdate) {
            return function () {

                // init and show popup

                let initData = {
                    title: __('Updating %1', decodeURIComponent(translationName)),
                    progressMax: pSitesCount
                };
                updatesoverview_update_popup_init(initData);
                //Step 3: start updates
                updatesoverview_translations_upgrade_all_int(pSlug, pSitesToUpdate);

                updatesoverviewContinueAfterBackup = undefined;
            }
        }(sitesCount, slug, sitesToUpdate);
        return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
    }

    // new confirm message
    if (!continueUpdating) {
        if (jQuery(siteNames).length > 0) {
            let sitesList = [];
            jQuery.each(siteNames, function (index, value) {
                if (value) { // to fix
                    sitesList.push(decodeURIComponent(value));
                }
            });
            let confirmMsg = __('You are about to update the %1 translation on the following site(s): <br/><div class="ui message">%2</div> <strong>Do you want to proceed?</strong>', translationName, sitesList.join('<br />'));
            mainwp_confirm(confirmMsg, _callback, false, 2);
        }
        return;
    }

    _callback();
};
let updatesoverview_translations_upgrade_all_int = function (slug, websiteIds, sitesTranslationSlugs) {
    currentTranslationSlugToUpgrade = slug;
    websitesTranslationSlugsToUpgrade = sitesTranslationSlugs;
    mainwpVars.websitesToUpdateTranslations = websiteIds;
    mainwpVars.currentWebsite = 0;
    mainwpVars.websitesDone = 0;
    mainwpVars.websitesTotal = mainwpVars.websitesLeft = mainwpVars.websitesToUpdateTranslations.length;

    mainwpVars.bulkTaskRunning = true;
    updatesoverview_translations_upgrade_all_loop_next();
};
let updatesoverview_translations_upgrade_all_loop_next = function () {
    while (mainwpVars.bulkTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxThreads) && (mainwpVars.websitesLeft > 0)) {
        updatesoverview_translations_upgrade_all_upgrade_next();
    }
};
let updatesoverview_translations_upgrade_all_update_site_status = function (siteId, newStatus) {
    jQuery('.updatesoverview-upgrade-status-wp[siteid="' + siteId + '"]').html(newStatus);
};
let updatesoverview_translations_upgrade_all_upgrade_next = function () {
    mainwpVars.currentThreads++;
    mainwpVars.websitesLeft--;

    let websiteId = mainwpVars.websitesToUpdateTranslations[mainwpVars.currentWebsite++];
    updatesoverview_translations_upgrade_all_update_site_status(websiteId, '<i class="notched circle loading icon"></i>');

    let slugToUpgrade = currentTranslationSlugToUpgrade;
    if (slugToUpgrade == undefined)
        slugToUpgrade = websitesTranslationSlugsToUpgrade[websiteId];
    updatesoverview_translations_upgrade_int(slugToUpgrade, websiteId, true, true);
};

let updatesoverview_translations_upgrade_all_update_done = function () {
    mainwpVars.currentThreads--;
    if (!mainwpVars.bulkTaskRunning)
        return;
    mainwpVars.websitesDone++;
    mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);

    if (mainwpVars.websitesDone == mainwpVars.websitesTotal) {
        updatesoverview_check_to_continue_updates();
        return;
    }

    updatesoverview_translations_upgrade_all_loop_next();
};
let updatesoverview_translations_upgrade_int = function (slug, websiteId, bulkMode, noCheck) {

    updatesoverviewContinueAfterBackup = function () {
        // need declare variables.
        let pSlug = slug;
        let pWebsiteId = websiteId;
        let pBulkMode = bulkMode;

        let slugParts = pSlug.split(',');

        for (let sid of slugParts) {
            let websiteHolder = jQuery('.translations-bulk-updates[translation_slug="' + sid + '"] tr[site_id="' + pWebsiteId + '"]');
            if (!websiteHolder.exists()) {
                websiteHolder = jQuery('.translations-bulk-updates[site_id="' + pWebsiteId + '"] tr[translation_slug="' + sid + '"]');
            }

            websiteHolder.find('td:last-child').html('<i class="notched circle loading icon"></i> ' + __('Updating. Please wait...'));
        }

        let data = mainwp_secure_data({
            action: 'mainwp_upgradeplugintheme',
            websiteId: pWebsiteId,
            type: 'translation',
            slug: pSlug
        });

        jQuery.ajax({
            type: "POST",
            url: ajaxurl,
            data: data,
            success: function (pSlug, pWebsiteId, pBulkMode) {
                return function (response) { // NOSONAR - complex.
                    let slugParts = pSlug.split(',');
                    let done = false;
                    for (let sid of slugParts) {
                        let websiteHolder = jQuery('.translations-bulk-updates[translation_slug="' + sid + '"] tr[site_id="' + pWebsiteId + '"]');
                        if (!websiteHolder.exists()) {
                            websiteHolder = jQuery('.translations-bulk-updates[site_id="' + pWebsiteId + '"] tr[translation_slug="' + sid + '"]');
                        }

                        if (response.error) {
                            if (!done && pBulkMode)
                                updatesoverview_translations_upgrade_all_update_site_status(pWebsiteId, '<i class="red times icon"></i>');
                            websiteHolder.find('td:last-child').html('<i class="red times icon"></i>');
                        } else {
                            let res = response.result;
                            let regression_icon = render_html_regression_icon(res);
                            if (res[sid]) {
                                let _success_icon = `<i class="green check icon"></i> ${regression_icon}`;
                                if (!done && pBulkMode)
                                    updatesoverview_translations_upgrade_all_update_site_status(pWebsiteId, _success_icon);
                                websiteHolder.attr('updated', 1);
                                websiteHolder.find('td:last-child').html(_success_icon);
                            } else {
                                if (!done && pBulkMode)
                                    updatesoverview_translations_upgrade_all_update_site_status(pWebsiteId, '<i class="red times icon"></i>');
                                websiteHolder.find('td:last-child').html('<i class="red times icon"></i>');
                            }
                        }
                        if (!done && pBulkMode) {
                            updatesoverview_translations_upgrade_all_update_done();
                            done = true;
                        }
                    }
                }
            }(pSlug, pWebsiteId, pBulkMode),
            tryCount: 0,
            retryLimit: 3,
            endError: function (pSlug, pWebsiteId, pBulkMode) {
                return function () {
                    let slugParts = pSlug.split(',');
                    let done = false;
                    for (let sid of slugParts) {
                        let result;
                        let websiteHolder = jQuery('.translations-bulk-updates[translation_slug="' + sid + '"] tr[site_id="' + pWebsiteId + '"]');
                        if (!websiteHolder.exists()) {
                            websiteHolder = jQuery('.translations-bulk-updates[site_id="' + pWebsiteId + '"] tr[translation_slug="' + sid + '"]');
                        }

                        result = __('FAILED');
                        if (!done && pBulkMode) {
                            updatesoverview_translations_upgrade_all_update_site_status(pWebsiteId, '<span class="mainwp-red"><i class="exclamation icon"></i> ' + __('FAILED') + '</span>');
                            updatesoverview_translations_upgrade_all_update_done();
                            done = true;
                        }

                        websiteHolder.find('td:last-child').html(result);
                    }
                }
            }(pSlug, pWebsiteId, pBulkMode),
            error: function (xhr) {
                this.tryCount++;
                if (this.tryCount >= this.retryLimit) {
                    this.endError();
                    return;
                }

                let pRqst = this;
                let pXhr = xhr;

                setTimeout(function () {
                    if (pXhr.status == 404) {
                        //handle error
                        jQuery.ajax(pRqst);
                    } else if (pXhr.status == 500) {
                        //handle error
                    } else {
                        //handle error
                    }
                }, 500);
            },
            dataType: 'json'
        });
        updatesoverviewContinueAfterBackup = undefined;
    };

    if (noCheck) {
        updatesoverviewContinueAfterBackup();
        return false;
    }

    let sitesToUpdate = [websiteId];
    let siteNames = [];
    siteNames[websiteId] = jQuery('div[site_id="' + websiteId + '"]').attr('site_name');

    return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
};

let currentPluginSlugToUpgrade;
let websitesPluginSlugsToUpgrade;
let updatesoverview_plugins_global_upgrade_all = function (groupId, updatesSelected) { // NOSONAR - Complexity 18/15.
    if (mainwpVars.bulkTaskRunning)
        return;

    //Step 1: build form
    let sitesToUpdate = [];
    let siteNames = {};
    let sitesPluginSlugs = {};

    let foundChildren = updatesoverview_plugins_get_global_upgrade_all(groupId, updatesSelected);

    if (foundChildren.length == 0)
        return;
    let sitesCount = 0;

    mainwpPopup('#mainwp-sync-sites-modal').clearList();

    for (let i = 0; i < foundChildren.length; i++) {
        if (limitUpdateAll > 0 && i >= limitUpdateAll && typeof groupId === 'undefined') {
            continueUpdatesAll = 'plugins_global_upgrade_all';
            break;
        }
        let child = jQuery(foundChildren[i]);
        let parent = child.parent(); // to fix

        let siteElement;
        let pluginElement;

        let checkAttr = child.attr('site_id');
        if ((typeof checkAttr !== 'undefined') && (checkAttr !== false)) {
            siteElement = child;
            pluginElement = parent;
        } else {
            siteElement = parent;
            pluginElement = child;
        }

        let siteId = siteElement.attr('site_id');
        let siteName = siteElement.attr('site_name');
        let pluginSlug = pluginElement.attr('plugin_slug');

        if (sitesToUpdate.indexOf(siteId) == -1) {
            sitesCount++;
            sitesToUpdate.push(siteId);
            siteNames[siteId] = siteName;
        }
        if (sitesPluginSlugs[siteId] == undefined) {
            sitesPluginSlugs[siteId] = pluginSlug;
        } else {
            sitesPluginSlugs[siteId] += ',' + pluginSlug;
        }
    }

    let _callback = function () {

        for (let id of sitesToUpdate) {
            let updateCount = sitesPluginSlugs[id].match(/,/g);
            if (updateCount == null)
                updateCount = 1;
            else
                updateCount = updateCount.length + 1;
            mainwpPopup('#mainwp-sync-sites-modal').appendItemsList(mainwp_links_visit_site_and_admin('', id) + ' ' + updatesmanage_link_to_site(decodeURIComponent(siteNames[id]), id) + ' (' + updateCount + ' plugins)', '<span class="updatesoverview-upgrade-status-wp" siteid="' + id + '">' + '<i class="clock outline icon"></i> ' + '</span>');
        }

        updatesoverviewContinueAfterBackup = function (pSitesCount, pSitesToUpdate, pSitesPluginSlugs) {
            return function () {

                let initData = {
                    title: __('Updating all'),
                    progressMax: pSitesCount
                };
                updatesoverview_update_popup_init(initData);

                //Step 3: start updates
                updatesoverview_plugins_upgrade_all_int(undefined, pSitesToUpdate, pSitesPluginSlugs);

                updatesoverviewContinueAfterBackup = undefined;
            }
        }(sitesCount, sitesToUpdate, sitesPluginSlugs);


        return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
    }

    // new confirm message
    if (!continueUpdating) {
        if (jQuery(siteNames).length > 0) {
            let sitesList = [];
            jQuery.each(siteNames, function (index, value) {
                if (value) { // to fix
                    sitesList.push(decodeURIComponent(value));
                }
            });
            let confirmMsg = __('You are about to update %1 on the following site(s): <br/><div class="ui message">%2</div> <strong>Do you want to proceed?</strong>', __('plugins'), sitesList.join('<br />'));
            mainwp_confirm(confirmMsg, _callback, false, 2);
        }
        return;
    }
    _callback();
};

let updatesoverview_plugins_get_global_upgrade_all = function (groupId, updatesSelected) {
    let foundChildren = [];
    if (typeof updatesSelected !== 'undefined' && updatesSelected) {
        if (typeof groupId !== 'undefined' && false !== groupId) {
            foundChildren = mainwp_updates_get_selected_rows_values('#update_wrapper_plugin_upgrades_group_' + groupId, 'tr.mainwp-plugin-update[updated="0"]');
        } else {
            foundChildren = mainwp_updates_get_selected_rows_values('#plugins-updates-global', 'table tr[updated="0"]');
        }
        if (foundChildren.length == 0) {
            updates_please_select_items_notice();
            return;
        }
    } else if (typeof groupId !== 'undefined' && false !== groupId) {
        foundChildren = jQuery('#update_wrapper_plugin_upgrades_group_' + groupId).find('tr.mainwp-plugin-update[updated="0"]');
    } else {
        foundChildren = jQuery('#plugins-updates-global').find('table tr[updated="0"]');
    }
    return foundChildren;
}

let updatesoverview_plugins_upgrade_all = function (slug, pluginName, updatesSelected) {
    if (mainwpVars.bulkTaskRunning)
        return;

    //Step 1: build form
    let sitesToUpdate = [];
    let siteNames = [];
    let foundChildren = updatesoverview_plugins_get_upgrade_all(slug, updatesSelected);

    if (foundChildren.length == 0)
        return;
    mainwpPopup('#mainwp-sync-sites-modal').clearList();

    for (let i = 0; i < foundChildren.length; i++) {
        if (limitUpdateAll > 0 && i >= limitUpdateAll) {
            continueUpdatesAll = 'plugins_upgrade_all';
            continueUpdatesSlug = slug;
            break;
        }
        let child = foundChildren[i];
        let siteId = jQuery(child).attr('site_id');
        let siteName = jQuery(child).attr('site_name');
        siteNames[siteId] = siteName;
        sitesToUpdate.push(siteId);
    }

    pluginName = decodeURIComponent(pluginName);
    pluginName = pluginName.replace(/\+/g, ' ');

    let _callback = function () {

        for (let id of sitesToUpdate) {
            mainwpPopup('#mainwp-sync-sites-modal').appendItemsList(mainwp_links_visit_site_and_admin('', id) + ' ' + updatesmanage_link_to_site(decodeURIComponent(siteNames[id]), id), '<span class="updatesoverview-upgrade-status-wp" siteid="' + id + '">' + '<span data-inverted="" data-position="left center" data-tooltip="' + __('Pending', 'mainwp') + '"><i class="clock outline icon"></i></span> ' + '</span>');
        }

        let sitesCount = sitesToUpdate.length;

        updatesoverviewContinueAfterBackup = function (pSitesCount, pSlug, pSitesToUpdate) {
            return function () {

                let initData = {
                    title: __('Updating %1', decodeURIComponent(pluginName)),
                    progressMax: pSitesCount
                };
                updatesoverview_update_popup_init(initData);

                //Step 3: start updates
                updatesoverview_plugins_upgrade_all_int(pSlug, pSitesToUpdate);

                updatesoverviewContinueAfterBackup = undefined;
            }
        }(sitesCount, slug, sitesToUpdate);

        return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
    }

    // new confirm message
    if (!continueUpdating) {
        if (siteNames.length > 0) {
            let sitesList = [];
            jQuery.each(siteNames, function (index, value) {
                if (value) { // to fix
                    sitesList.push(decodeURIComponent(value));
                }
            });
            let confirmMsg = __('You are about to update the %1 plugin on the following site(s): <br/><div class="ui message">%2</div> <strong>Do you want to proceed?</strong>', pluginName, sitesList.join('<br />'));
            mainwp_confirm(confirmMsg, _callback, false, 2);
        }
        return;
    }
    _callback();
};

let updatesoverview_plugins_get_upgrade_all = function (slug, updatesSelected) {
    let foundChildren = [];
    if (typeof updatesSelected !== 'undefined' && updatesSelected) {
        jQuery('tr[plugin_slug="' + slug + '"]').find('table tr[updated="0"]').each(
            function () {
                if (jQuery(this).find('.child.checkbox').checkbox('is checked')) {
                    foundChildren.push(this);
                }
            }
        );
        if (foundChildren.length == 0) {
            updates_please_select_items_notice();
            return false;
        }
    } else {
        foundChildren = jQuery('tr[plugin_slug="' + slug + '"]').find('table tr[updated="0"]');
    }
    return foundChildren;
}

let updatesoverview_plugins_upgrade_all_int = function (slug, websiteIds, sitesPluginSlugs) {
    currentPluginSlugToUpgrade = slug;
    websitesPluginSlugsToUpgrade = sitesPluginSlugs;
    mainwpVars.websitesToUpdatePlugins = websiteIds;
    mainwpVars.currentWebsite = 0;
    mainwpVars.websitesDone = 0;
    mainwpVars.errorCount = 0;
    mainwpVars.websitesTotal = mainwpVars.websitesLeft = mainwpVars.websitesToUpdatePlugins.length;

    mainwpVars.bulkTaskRunning = true;
    updatesoverview_plugins_upgrade_all_loop_next();
};
let updatesoverview_plugins_upgrade_all_loop_next = function () {
    while (mainwpVars.bulkTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxThreads) && (mainwpVars.websitesLeft > 0)) {
        updatesoverview_plugins_upgrade_all_upgrade_next();
    }
};
let updatesoverview_plugins_upgrade_all_update_site_status = function (siteId, newStatus) {
    jQuery('.updatesoverview-upgrade-status-wp[siteid="' + siteId + '"]').html(newStatus);
};
let updatesoverview_plugins_upgrade_all_upgrade_next = function () {
    mainwpVars.currentThreads++;
    mainwpVars.websitesLeft--;

    let websiteId = mainwpVars.websitesToUpdatePlugins[mainwpVars.currentWebsite++];

    updatesoverview_plugins_upgrade_all_update_site_status(websiteId, '<i class="notched circle loading icon"></i>');

    let slugToUpgrade = currentPluginSlugToUpgrade;
    if (slugToUpgrade == undefined)
        slugToUpgrade = websitesPluginSlugsToUpgrade[websiteId];
    updatesoverview_plugins_upgrade_int(slugToUpgrade, websiteId, true, true);
};

let updatesoverview_check_to_continue_updates = function () {
    mainwpVars.bulkTaskRunning = false;
    setTimeout(function () {
        if (!mainwpVars?.errorCount && jQuery('.updates-regression-score-red-flag').length === 0) {
            mainwpPopup('#mainwp-sync-sites-modal').close(true);
        }
    }, 3000);
    return false;
}

let updatesoverview_plugins_upgrade_all_update_done = function () {
    mainwpVars.currentThreads--;
    if (!mainwpVars.bulkTaskRunning)
        return;
    mainwpVars.websitesDone++;

    mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);

    if (mainwpVars.websitesDone == mainwpVars.websitesTotal) {
        updatesoverview_check_to_continue_updates();
        return;
    }

    updatesoverview_plugins_upgrade_all_loop_next();
};


let updatesoverview_plugins_upgrade_int_after_backup = function (pSlug, pWebsiteId, pBulkMode) {
    return function () {
        let slugParts = pSlug.split(',');
        for (let sid of slugParts) {
            let websiteHolder = jQuery('.plugins-bulk-updates[plugin_slug="' + sid + '"] tr[site_id="' + pWebsiteId + '"]');
            if (!websiteHolder.exists()) {
                websiteHolder = jQuery('.plugins-bulk-updates[site_id="' + pWebsiteId + '"] tr[plugin_slug="' + sid + '"]');
            }
            websiteHolder.find('td:last-child').html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Updating...', 'mainwp') + '"><i class="notched circle loading icon"></i></span> ' + __('Updating. Please wait...'));
        }

        let data = mainwp_secure_data({
            action: 'mainwp_upgradeplugintheme',
            websiteId: pWebsiteId,
            type: 'plugin',
            slug: pSlug
        });
        jQuery.ajax({
            type: "POST",
            url: ajaxurl,
            data: data,
            success: function (pSlug, pWebsiteId, pBulkMode) {
                return function (response) { // NOSONAR - complex.
                    let slugParts = pSlug.split(',');
                    let done = false;
                    let bulk_errors = [];
                    let _icon = '<i class="red times icon"></i>';
                    for (let sid of slugParts) {
                        let websiteHolder = jQuery('.plugins-bulk-updates[plugin_slug="' + sid + '"] tr[site_id="' + pWebsiteId + '"]');
                        if (!websiteHolder.exists()) {
                            websiteHolder = jQuery('.plugins-bulk-updates[site_id="' + pWebsiteId + '"] tr[plugin_slug="' + sid + '"]');
                        }

                        if (response.error || response.notices) {
                            let extErr = getErrorMessageInfo(response.error, 'ui');
                            if (!done && pBulkMode)
                                updatesoverview_plugins_upgrade_all_update_site_status(pWebsiteId, extErr);
                            websiteHolder.find('td:last-child').html(extErr);
                            mainwpVars.errorCount++;
                        } else {
                            let res = response.result;
                            let res_error = response.result_error;
                            let _success_icon = `<i class="green check icon"></i>`;
                            let regression_icon = render_html_regression_icon(res);
                            if (res[sid]) {
                                if (!done && pBulkMode)
                                    updatesoverview_plugins_upgrade_all_update_site_status(pWebsiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Update successful', 'mainwp') + '">' + _success_icon + '</span>' + regression_icon);
                                websiteHolder.attr('updated', 1);
                                websiteHolder.find('td:last-child').html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Update successful', 'mainwp') + '">' + _success_icon + '</span>' + regression_icon + ' ' + mainwp_links_visit_site_and_admin('', pWebsiteId));
                            } else if (res_error[sid]) {
                                let _error = res_error[sid];
                                let roll_error = mainwp_updates_get_rollback_msg(_error);
                                if (roll_error) {
                                    _error = roll_error;
                                    _icon = mainwpParams.roll_ui_icon;
                                }
                                bulk_errors.push(_error);
                                websiteHolder.find('td:last-child').html('<span data-inverted="" data-position="left center" data-tooltip="' + _error + '">' + _icon + '</span>');
                                mainwpVars.errorCount++;
                            } else {
                                websiteHolder.find('td:last-child').html('<i class="red times icon"></i>');
                                mainwpVars.errorCount++;
                            }
                        }
                    }

                    if (pBulkMode && bulk_errors.length) {
                        jQuery('.updatesoverview-upgrade-status-wp[siteid="' + pWebsiteId + '"]').html('<span class="mainwp-html-popup" data-position="left center" data-html="">' + _icon + '</span>');
                        mainwp_init_html_popup('.updatesoverview-upgrade-status-wp[siteid="' + pWebsiteId + '"] .mainwp-html-popup', bulk_errors.join('<br />'));
                    }

                    if (pBulkMode) {
                        updatesoverview_plugins_upgrade_all_update_done();
                    }
                }
            }(pSlug, pWebsiteId, pBulkMode),
            tryCount: 0,
            retryLimit: 3,
            endError: function (pSlug, pWebsiteId, pBulkMode) {
                return function () {
                    let slugParts = pSlug.split(',');
                    let done = false;
                    for (let sid of slugParts) {
                        //Siteview
                        let websiteHolder = jQuery('div[plugin_slug="' + sid + '"] div[site_id="' + pWebsiteId + '"]');
                        if (!websiteHolder.exists()) {
                            websiteHolder = jQuery('div[site_id="' + pWebsiteId + '"] div[plugin_slug="' + sid + '"]');
                        }

                        if (!done && pBulkMode) {
                            updatesoverview_plugins_upgrade_all_update_site_status(pWebsiteId, '<i class="red times icon"></i>');
                            updatesoverview_plugins_upgrade_all_update_done();
                            done = true;
                        }
                        websiteHolder.find('td:last-child').html('<i class="red times icon"></i>');
                    }
                }
            }(pSlug, pWebsiteId, pBulkMode),
            error: function (xhr) {
                this.tryCount++;
                if (this.tryCount >= this.retryLimit) {
                    this.endError();
                    return;
                }
                let pRqst = this;
                let pXhr = xhr;
                setTimeout(function () {
                    if (pXhr.status == 404) {
                        //handle error
                        jQuery.ajax(pRqst);
                    } else if (pXhr.status == 500) {
                        //handle error
                    } else {
                        //handle error
                    }
                }, 500);
            },
            dataType: 'json'
        });

        updatesoverviewContinueAfterBackup = undefined;
    }
}

let updatesoverview_plugins_upgrade_int = function (slug, websiteId, bulkMode, noCheck) {

    updatesoverviewContinueAfterBackup = function (pSlug, pWebsiteId, pBulkMode) {
        return updatesoverview_plugins_upgrade_int_after_backup(pSlug, pWebsiteId, pBulkMode);
    }(slug, websiteId, bulkMode);

    if (noCheck) {
        updatesoverviewContinueAfterBackup();
        return false;
    }

    let sitesToUpdate = [websiteId];
    let siteNames = [];
    siteNames[websiteId] = jQuery('div[site_id="' + websiteId + '"]').attr('site_name');

    return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
};

let currentThemeSlugToUpgrade;
let websitesThemeSlugsToUpgrade;
let updatesoverview_themes_global_upgrade_all = function (groupId, updatesSelected) { // NOSONAR - complexity.
    if (mainwpVars.bulkTaskRunning)
        return;

    //Step 1: build form
    let sitesToUpdate = [];
    let siteNames = {};
    let sitesPluginSlugs = {};
    let foundChildren = updatesoverview_themes_get_global_upgrade_all(groupId, updatesSelected);
    if (foundChildren.length == 0){
        updates_please_select_items_notice();
        return false;
    }
    let sitesCount = 0;

    mainwpPopup('#mainwp-sync-sites-modal').clearList();

    for (let i = 0; i < foundChildren.length; i++) {
        if (limitUpdateAll > 0 && i >= limitUpdateAll && typeof groupId === 'undefined') {
            continueUpdatesAll = 'themes_global_upgrade_all';
            break;
        }
        let child = jQuery(foundChildren[i]);
        let parent = child.parent(); // to fix

        let siteElement;
        let themeElement;

        let checkAttr = child.attr('site_id');
        if ((typeof checkAttr !== 'undefined') && (checkAttr !== false)) {
            siteElement = child;
            themeElement = parent;
        } else {
            siteElement = parent;
            themeElement = child;
        }

        let siteId = siteElement.attr('site_id');
        let siteName = siteElement.attr('site_name');
        let themeSlug = themeElement.attr('theme_slug');

        if (sitesToUpdate.indexOf(siteId) == -1) {
            sitesCount++;
            sitesToUpdate.push(siteId);
            siteNames[siteId] = siteName;
        }
        if (sitesPluginSlugs[siteId] == undefined) {
            sitesPluginSlugs[siteId] = themeSlug;
        } else {
            sitesPluginSlugs[siteId] += ',' + themeSlug;
        }
    }

    let _callback = function () {

        for (let id of sitesToUpdate) {
            let updateCount = sitesPluginSlugs[id].match(/,/g);
            if (updateCount == null)
                updateCount = 1;
            else
                updateCount = updateCount.length + 1;
            mainwpPopup('#mainwp-sync-sites-modal').appendItemsList(mainwp_links_visit_site_and_admin('', id) + ' ' + updatesmanage_link_to_site(decodeURIComponent(siteNames[id]), id) + ' (' + updateCount + ' themes)', '<span class="updatesoverview-upgrade-status-wp" siteid="' + id + '">' + '<i class="clock outline icon"></i> ' + '</span>');
        }

        updatesoverviewContinueAfterBackup = function (pSitesCount, pSitesToUpdate, pSitesPluginSlugs) {
            return function () {

                let initData = {
                    title: __('Updating all...'),
                    progressMax: pSitesCount
                };
                updatesoverview_update_popup_init(initData);

                //Step 3: start updates
                updatesoverview_themes_upgrade_all_int(undefined, pSitesToUpdate, pSitesPluginSlugs);

                updatesoverviewContinueAfterBackup = undefined;
            }
        }(sitesCount, sitesToUpdate, sitesPluginSlugs);

        return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
    }

    // new confirm message
    if (!continueUpdating) {
        if (jQuery(siteNames).length > 0) {
            let sitesList = [];
            jQuery.each(siteNames, function (index, value) {
                if (value) { // to fix
                    sitesList.push(decodeURIComponent(value));
                }
            });
            let confirmMsg = __('You are about to update %1 on the following site(s): <br/><div class="ui message">%2</div> <strong>Do you want to proceed?</strong>', __('themes'), sitesList.join('<br />'));
            mainwp_confirm(confirmMsg, _callback, false, 2);
        }
        return;
    }
    _callback();
};

let updatesoverview_themes_get_global_upgrade_all = function (groupId, updatesSelected) {
    let foundChildren = [];
    if (typeof updatesSelected !== 'undefined' && updatesSelected) {
        if (typeof groupId !== 'undefined' && false !== groupId) {
            jQuery('#update_wrapper_theme_upgrades_group_' + groupId).find('tr.mainwp-theme-update[updated="0"]').each(
                function () {
                    if (jQuery(this).find('.child.checkbox').checkbox('is checked')) {
                        foundChildren.push(this);
                    }
                }
            );
        } else {
            jQuery('#themes-updates-global').find('table tr[updated="0"]').each(
                function () {
                    if (jQuery(this).find('.child.checkbox').checkbox('is checked')) {
                        foundChildren.push(this);
                    }
                }
            );
        }
    } else if (typeof groupId !== 'undefined' && false !== groupId) {
        foundChildren = jQuery('#update_wrapper_theme_upgrades_group_' + groupId).find('tr.mainwp-theme-update[updated="0"]');
    } else {
        foundChildren = jQuery('#themes-updates-global').find('table tr[updated="0"]');
    }
    return foundChildren;
}

let updates_please_select_items_notice = function () {
    let msg = __('Please, select items to update.');
    jQuery('#mainwp-modal-confirm-select .content-massage').html(msg);
    jQuery('#mainwp-modal-confirm-select').modal('show');
    return false;
}

let updatesoverview_themes_upgrade_all = function (slug, themeName, updatesSelected) {
    if (mainwpVars.bulkTaskRunning)
        return;

    //Step 1: build form
    let sitesToUpdate = [];
    let siteNames = [];
    let foundChildren = updatesoverview_themes_get_upgrade_all(slug, updatesSelected);

    if (foundChildren.length == 0)
        return;

    mainwpPopup('#mainwp-sync-sites-modal').clearList();

    for (let i = 0; i < foundChildren.length; i++) {
        if (limitUpdateAll > 0 && i >= limitUpdateAll) {
            continueUpdatesAll = 'themes_upgrade_all';
            continueUpdatesSlug = slug;
            break;
        }
        let child = foundChildren[i];
        let siteId = jQuery(child).attr('site_id');
        let siteName = jQuery(child).attr('site_name');
        siteNames[siteId] = siteName;
        sitesToUpdate.push(siteId);
        mainwpPopup('#mainwp-sync-sites-modal').appendItemsList(mainwp_links_visit_site_and_admin('', siteId) + ' ' + updatesmanage_link_to_site(decodeURIComponent(siteName), siteId), '<span class="updatesoverview-upgrade-status-wp" siteid="' + siteId + '">' + '<i class="clock outline icon"></i> ' + '</span>');
    }

    themeName = decodeURIComponent(themeName);
    themeName = themeName.replace(/\+/g, ' ');

    let _callback = function () {

        let sitesCount = sitesToUpdate.length;
        updatesoverviewContinueAfterBackup = function (pSitesCount, pSlug, pSitesToUpdate) {
            return function () {
                //Step 2: show form

                let initData = {
                    title: __('Updating %1', decodeURIComponent(themeName)),
                    progressMax: pSitesCount
                };
                updatesoverview_update_popup_init(initData);

                //Step 3: start updates
                updatesoverview_themes_upgrade_all_int(pSlug, pSitesToUpdate);

                updatesoverviewContinueAfterBackup = undefined;
            }
        }(sitesCount, slug, sitesToUpdate);

        return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
    }

    // new confirm message
    if (!continueUpdating) {
        if (jQuery(siteNames).length > 0) {
            let sitesList = [];
            jQuery.each(siteNames, function (index, value) {
                if (value) { // to fix
                    sitesList.push(decodeURIComponent(value));
                }
            });
            let confirmMsg = __('You are about to update the %1 theme on the following site(s): <br/><div class="ui message">%2</div> <strong>Do you want to proceed?</strong>', themeName, sitesList.join('<br />'));
            mainwp_confirm(confirmMsg, _callback, false, 2);
        }
        return;
    }
    _callback();
};

let updatesoverview_themes_get_upgrade_all = function (slug, updatesSelected) {
    let foundChildren = [];
    if (typeof updatesSelected !== 'undefined' && updatesSelected) {
        jQuery('tr[theme_slug="' + slug + '"]').find('table tr[updated="0"]').each(
            function () {
                if (jQuery(this).find('.child.checkbox').checkbox('is checked')) {
                    foundChildren.push(this);
                }
            }
        );
        if (foundChildren.length == 0) {
            updates_please_select_items_notice();
            return false;
        }
    } else {
        foundChildren = jQuery('tr[theme_slug="' + slug + '"]').find('table tr[updated="0"]');
    }

    return foundChildren;
}

let updatesoverview_themes_upgrade_all_int = function (slug, websiteIds, sitesThemeSlugs) {
    currentThemeSlugToUpgrade = slug;
    websitesThemeSlugsToUpgrade = sitesThemeSlugs;
    mainwpVars.websitesToUpdate = websiteIds;
    mainwpVars.currentWebsite = 0;
    mainwpVars.websitesDone = 0;
    mainwpVars.websitesTotal = mainwpVars.websitesLeft = mainwpVars.websitesToUpdate.length;

    mainwpVars.bulkTaskRunning = true;
    updatesoverview_themes_upgrade_all_loop_next();
};
let updatesoverview_themes_upgrade_all_loop_next = function () {
    while (mainwpVars.bulkTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxThreads) && (mainwpVars.websitesLeft > 0)) {
        updatesoverview_themes_upgrade_all_upgrade_next();
    }
};
let updatesoverview_themes_upgrade_all_update_site_status = function (siteId, newStatus) {
    jQuery('.updatesoverview-upgrade-status-wp[siteid="' + siteId + '"]').html(newStatus);
};
let updatesoverview_themes_upgrade_all_upgrade_next = function () {
    mainwpVars.currentThreads++;
    mainwpVars.websitesLeft--;

    let websiteId = mainwpVars.websitesToUpdate[mainwpVars.currentWebsite++];
    updatesoverview_themes_upgrade_all_update_site_status(websiteId, '<i class="notched circle loading icon"></i>');

    let slugToUpgrade = currentThemeSlugToUpgrade;
    if (slugToUpgrade == undefined)
        slugToUpgrade = websitesThemeSlugsToUpgrade[websiteId];
    updatesoverview_themes_upgrade_int(slugToUpgrade, websiteId, true);
};
let updatesoverview_themes_upgrade_all_update_done = function () {
    mainwpVars.currentThreads--;
    if (!mainwpVars.bulkTaskRunning)
        return;
    mainwpVars.websitesDone++;

    mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);

    if (mainwpVars.websitesDone == mainwpVars.websitesTotal) {
        updatesoverview_check_to_continue_updates();
        return;
    }

    updatesoverview_themes_upgrade_all_loop_next();
};
let updatesoverview_themes_upgrade_int = function (slug, websiteId, bulkMode) {
    let slugParts = slug.split(',');
    for (let sid of slugParts) {
        let websiteHolder = jQuery('.themes-bulk-updates[theme_slug="' + sid + '"] tr[site_id="' + websiteId + '"]');
        if (!websiteHolder.exists()) {
            websiteHolder = jQuery('.themes-bulk-updates[site_id="' + websiteId + '"] tr[theme_slug="' + sid + '"]');
        }
        websiteHolder.find('td:last-child').html('<i class="notched circle loading icon"></i> ' + __('Updating. Please wait...'));

    }

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
        success: function (pSlug, pWebsiteId, pBulkMode) {
            return function (response) { // NOSONAR - complex.
                let slugParts = pSlug.split(',');
                let done = false;

                let bulk_errors = [];
                let bulk_icon = '<i class="red times icon"></i>';

                for (let sid of slugParts) {
                    let websiteHolder = jQuery('.themes-bulk-updates[theme_slug="' + sid + '"] tr[site_id="' + websiteId + '"]');
                    if (!websiteHolder.exists()) {
                        websiteHolder = jQuery('.themes-bulk-updates[site_id="' + websiteId + '"] tr[theme_slug="' + sid + '"]');
                    }
                    if (response.error) {
                        let extErr = getErrorMessageInfo(response.error, 'ui')
                        if (!done && pBulkMode)
                            updatesoverview_themes_upgrade_all_update_site_status(pWebsiteId, extErr);
                        websiteHolder.find('td:last-child').html(extErr);
                    } else {
                        let res = response.result;
                        let res_error = response.result_error;
                        let regression_icon = render_html_regression_icon(res);
                        if (res[sid]) {
                            let _success_icon = `<i class="green check icon"></i>`;
                            if (!done && pBulkMode)
                                updatesoverview_themes_upgrade_all_update_site_status(pWebsiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Update successful', 'mainwp') + '">' + _success_icon + '</span>' + regression_icon + ' ' + mainwp_links_visit_site_and_admin('', websiteId));
                            websiteHolder.attr('updated', 1);
                            websiteHolder.find('td:last-child').html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Update successful', 'mainwp') + '">' + _success_icon + '</span>' + regression_icon + ' ' + mainwp_links_visit_site_and_admin('', websiteId));
                        } else {
                            let _error = '';
                            let _icon = '';
                            if (res_error[sid]) {
                                _error = res_error[sid];
                                _icon = '<i class="red times icon"></i>';
                                let roll_error = mainwp_updates_get_rollback_msg(_error);
                                if (roll_error) {
                                    _error = roll_error;
                                    _icon = mainwpParams.roll_ui_icon;
                                    bulk_icon = mainwpParams.roll_ui_icon;
                                }
                                bulk_errors.push(_error);
                                mainwpVars.errorCount++;
                            }
                            if (_error) {
                                websiteHolder.find('td:last-child').html('<span class="mainwp-html-popup" data-position="left center" data-html="">' + _icon + '</span>');
                                mainwp_init_html_popup(websiteHolder.find('td:last-child').find('.mainwp-html-popup'), _error);
                            }
                        }
                    }

                }

                if (bulk_errors.length) {
                    if (pBulkMode) {
                        jQuery('.updatesoverview-upgrade-status-wp[siteid="' + pWebsiteId + '"]').html('<span class="mainwp-html-popup" data-position="left center" data-html="">' + bulk_icon + '</span>');
                        mainwp_init_html_popup('.updatesoverview-upgrade-status-wp[siteid="' + pWebsiteId + '"] .mainwp-html-popup', bulk_errors.join('<br />'));
                    }
                }

                if (pBulkMode) {
                    updatesoverview_themes_upgrade_all_update_done();
                }
            }
        }(slug, websiteId, bulkMode),
        tryCount: 0,
        retryLimit: 3,
        endError: function (pSlug, pWebsiteId, pBulkMode) {
            return function () {
                let slugParts = pSlug.split(',');
                let done = false;
                for (let sid of slugParts) {
                    let websiteHolder = jQuery('div[theme_slug="' + sid + '"] div[site_id="' + pWebsiteId + '"]');
                    if (!websiteHolder.exists()) {
                        websiteHolder = jQuery('div[site_id="' + pWebsiteId + '"] div[theme_slug="' + sid + '"]');
                    }

                    if (!done && pBulkMode) {
                        updatesoverview_themes_upgrade_all_update_site_status(pWebsiteId, '<i class="red times icon"></i>');
                        updatesoverview_themes_upgrade_all_update_done();
                        done = true;
                    }
                    websiteHolder.find('td:last-child').html('<i class="red times icon"></i>');
                }
            }
        }(slug, websiteId, bulkMode),
        error: function (xhr) {
            this.tryCount++;
            if (this.tryCount >= this.retryLimit) {
                this.endError();
                return;
            }

            let pRqst = this;
            let pXhr = xhr;
            setTimeout(function () {
                if (pXhr.status == 404) {
                    //handle error
                    jQuery.ajax(pRqst);
                } else if (pXhr.status == 500) {
                    //handle error
                } else {
                    //handle error
                }
            }, 500);
        },
        dataType: 'json'
    });

    return false;
};


/* eslint-disable complexity */
let updatesoverview_global_upgrade_all = function (which) { // NOSONAR - Complexity.

    if (mainwpVars.bulkTaskRunning)
        return;

    //Step 1: build form
    let sitesToUpdate = [];
    let sitesToUpgrade = [];
    let sitesPluginSlugs = {};
    let sitesThemeSlugs = {};
    let sitesTranslationSlugs = {};
    let siteNames = {};

    mainwpPopup('#mainwp-sync-sites-modal').clearList();

    let sitesCount = 0;
    let foundChildren;

    if (which == 'all' || which == 'wp') {
        //Find wordpress to update
        foundChildren = jQuery('#wp_upgrades').find('div[updated="0"]');
        if (foundChildren.length != 0) {
            for (let child of foundChildren) {
                let siteElement = jQuery(child);
                let siteId = siteElement.attr('site_id');
                let siteName = siteElement.attr('site_name');
                if (sitesToUpdate.indexOf(siteId) == -1) {
                    sitesCount++;
                    sitesToUpdate.push(siteId);
                    siteNames[siteId] = siteName;
                }
                if (sitesToUpgrade.indexOf(siteId) == -1)
                    sitesToUpgrade.push(siteId);
            }
        }
    }

    if (which == 'all' || which == 'plugin') {
        //Find plugins to update
        foundChildren = jQuery('#wp_plugin_upgrades').find('div[updated="0"]');
        if (foundChildren.length != 0) {
            for (let item of foundChildren) {
                let siteElement = jQuery(item);
                let siteId = siteElement.attr('site_id');
                let siteName = siteElement.attr('site_name');
                let pluginSlug = siteElement.attr('plugin_slug');

                if (sitesToUpdate.indexOf(siteId) == -1) {
                    sitesCount++;
                    sitesToUpdate.push(siteId);
                    siteNames[siteId] = siteName;
                }

                if (sitesPluginSlugs[siteId] == undefined) {
                    sitesPluginSlugs[siteId] = pluginSlug;
                } else {
                    sitesPluginSlugs[siteId] += ',' + pluginSlug;
                }
            }
        }
    }

    if (which == 'all' || which == 'theme') {
        //Find themes to update
        foundChildren = jQuery('#wp_theme_upgrades').find('div[updated="0"]');
        if (foundChildren.length != 0) {
            for (let item of foundChildren) {
                let siteElement = jQuery(item);
                let siteId = siteElement.attr('site_id');
                let siteName = siteElement.attr('site_name');
                let themeSlug = siteElement.attr('theme_slug');

                if (sitesToUpdate.indexOf(siteId) == -1) {
                    sitesCount++;
                    sitesToUpdate.push(siteId);
                    siteNames[siteId] = siteName;
                }

                if (sitesThemeSlugs[siteId] == undefined) {
                    sitesThemeSlugs[siteId] = themeSlug;
                } else {
                    sitesThemeSlugs[siteId] += ',' + themeSlug;
                }
            }
        }
    }

    if (which == 'all' || which == 'translation') {
        //Find translation to update
        foundChildren = jQuery('#wp_translation_upgrades').find('div[updated="0"]');
        if (foundChildren.length != 0) {
            for (let item of foundChildren) {
                let siteElement = jQuery(item);
                let siteId = siteElement.attr('site_id');
                let siteName = siteElement.attr('site_name');
                let transSlug = siteElement.attr('translation_slug');

                if (sitesToUpdate.indexOf(siteId) == -1) {
                    sitesCount++;
                    sitesToUpdate.push(siteId);
                    siteNames[siteId] = siteName;
                }

                if (sitesTranslationSlugs[siteId] == undefined) {
                    sitesTranslationSlugs[siteId] = transSlug;
                } else {
                    sitesTranslationSlugs[siteId] += ',' + transSlug;
                }
            }
        }
    }

    let _callback = function () { // NOSONAR - Complexity.
        //Build form
        for (let siteId of sitesToUpdate) {
            let whatToUpgrade = '';
            if (sitesToUpgrade.indexOf(siteId) != -1)
                whatToUpgrade = '<span class="wordpress">WordPress core files</span>';

            if (sitesPluginSlugs[siteId] != undefined) {
                let updateCount = sitesPluginSlugs[siteId].match(/,/g);
                if (updateCount == null)
                    updateCount = 1;
                else
                    updateCount = updateCount.length + 1;
                if (whatToUpgrade != '')
                    whatToUpgrade += ', ';
                whatToUpgrade += '<span class="plugin">' + updateCount + ' plugin' + (updateCount > 1 ? 's' : '') + '</span>';
            }

            if (sitesThemeSlugs[siteId] != undefined) {
                let updateCount = sitesThemeSlugs[siteId].match(/,/g);

                if (updateCount == null)
                    updateCount = 1;
                else
                    updateCount = updateCount.length + 1;

                if (whatToUpgrade != '')
                    whatToUpgrade += ', ';
                whatToUpgrade += '<span class="theme">' + updateCount + ' theme' + (updateCount > 1 ? 's' : '') + '</span>';
            }


            if (sitesTranslationSlugs[siteId] != undefined) {
                let updateCount = sitesTranslationSlugs[siteId].match(/,/g);
                if (updateCount == null)
                    updateCount = 1;
                else
                    updateCount = updateCount.length + 1;

                if (whatToUpgrade != '')
                    whatToUpgrade += ', ';

                whatToUpgrade += '<span class="translation">' + updateCount + ' translation' + (updateCount > 1 ? 's' : '') + '</span>';
            }
            mainwpPopup('#mainwp-sync-sites-modal').appendItemsList(mainwp_links_visit_site_and_admin('', siteId) + ' ' + updatesmanage_link_to_site(decodeURIComponent(siteNames[siteId]), siteId) + ' (' + whatToUpgrade + ')', '<span class="updatesoverview-upgrade-status-wp" siteid="' + siteId + '">' + '<i class="clock outline icon"></i> ' + '</span>');
        }

        updatesoverviewContinueAfterBackup = updatesoverview_global_upgrade_all_after_backup(sitesCount, sitesToUpdate, sitesToUpgrade, sitesPluginSlugs, sitesThemeSlugs, sitesTranslationSlugs);
        return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
    } // end _callback()

    updatesoverview_global_upgrade_all_start(which, siteNames, _callback);

};

let updatesoverview_global_upgrade_all_start = function (which, siteNames, _callback) {
    // new confirm message
    if (jQuery(siteNames).length > 0) {
        let sitesList = [];
        jQuery.each(siteNames, function (index, value) {
            if (value) { // to fix
                sitesList.push(decodeURIComponent(value));
            }
        });

        let whichUpdates = __('WordPress core files, plugins, themes and translations');
        if (which == 'wp') {
            whichUpdates = __('WordPress core files');
        } else if (which == 'plugin') {
            whichUpdates = __('plugins');
        } else if (which == 'theme') {
            whichUpdates = __('themes');
        } else if (which == 'translation') {
            whichUpdates = __('translations');
        }

        let confirmMsg = __('You are about to update %1 on the following site(s): <br/><div class="ui message">%2</div> <strong>Do you want to proceed?</strong>', whichUpdates, sitesList.join('<br />'));

        mainwp_confirm(confirmMsg, _callback, false, 2);
    }
}

let updatesoverview_global_upgrade_all_after_backup = function (pSitesCount, pSitesToUpdate, pSitesToUpgrade, pSitesPluginSlugs, pSitesThemeSlugs, psitesTranslationSlugs) {
    return function () {
        //Step 2: show form

        let initData = {
            title: __('Updating All'),
            progressMax: pSitesCount
        };
        updatesoverview_update_popup_init(initData);

        //Step 3: start updates
        updatesoverview_upgrade_all_int(pSitesToUpdate, pSitesToUpgrade, pSitesPluginSlugs, pSitesThemeSlugs, psitesTranslationSlugs);

        updatesoverviewContinueAfterBackup = undefined;
    };
}

/* eslint-enable complexity */
let websitesTransSlugsToUpgrade;

let updatesoverview_upgrade_all_int = function (pSitesToUpdate, pSitesToUpgrade, pSitesPluginSlugs, pSitesThemeSlugs, psitesTranslationSlugs) {
    mainwpVars.websitesToUpdate = pSitesToUpdate;

    mainwpVars.websitesToUpgrade = pSitesToUpgrade;

    websitesPluginSlugsToUpgrade = pSitesPluginSlugs;
    currentPluginSlugToUpgrade = undefined;

    websitesThemeSlugsToUpgrade = pSitesThemeSlugs;
    currentThemeSlugToUpgrade = undefined;

    websitesTransSlugsToUpgrade = psitesTranslationSlugs;

    mainwpVars.currentWebsite = 0;
    mainwpVars.websitesDone = 0;
    mainwpVars.errorCount = 0;
    mainwpVars.websitesTotal = mainwpVars.websitesLeft = mainwpVars.websitesToUpdate.length;

    mainwpVars.bulkTaskRunning = true;

    updatesoverview_upgrade_all_loop_next();
};

let updatesoverview_upgrade_all_loop_next = function () {
    while (mainwpVars.bulkTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxThreads) && (mainwpVars.websitesLeft > 0)) {
        updatesoverview_upgrade_all_upgrade_next();
    }
};
let updatesoverview_upgrade_all_update_site_status = function (siteId, newStatus) {
    jQuery('.updatesoverview-upgrade-status-wp[siteid="' + siteId + '"]').html(newStatus);
};
let updatesoverview_upgrade_all_update_site_bold = function (siteId, sub) {
    jQuery('.updatesoverview-upgrade-status-wp[siteid="' + siteId + '"]').parent().parent().find('.' + sub).css('font-weight', 'bold');
};
let updatesoverview_upgrade_all_upgrade_next = function () {
    mainwpVars.currentThreads++;
    mainwpVars.websitesLeft--;

    let websiteId = mainwpVars.websitesToUpdate[mainwpVars.currentWebsite++];
    updatesoverview_upgrade_all_update_site_status(websiteId, '<i class="notched circle loading icon"></i>');

    let params = {
        'websiteId': websiteId,
        'pThemeSlugToUpgrade': websitesThemeSlugsToUpgrade[websiteId],
        'pPluginSlugToUpgrade': websitesPluginSlugsToUpgrade[websiteId],
        'pWordpressUpgrade': mainwpVars.websitesToUpgrade.indexOf(websiteId) != -1,
        'pTransSlugToUpgrade': websitesTransSlugsToUpgrade[websiteId],
    };
    updatesoverview_upgrade_int(params);
};

let updatesoverview_upgrade_int = function (params) {
    let new_params = {
        'pWebsiteId': params['websiteId'],
        'pThemeSlugToUpgrade': params['pThemeSlugToUpgrade'],
        'pPluginSlugToUpgrade': params['pPluginSlugToUpgrade'],
        'pWordpressUpgrade': params['pWordpressUpgrade'],
        'pThemeDone': params['pThemeSlugToUpgrade'] == undefined,
        'pPluginDone': params['pPluginSlugToUpgrade'] == undefined,
        'pUpgradeDone': !params['pWordpressUpgrade'],
        'pErrorMessage': undefined,
        'pTransSlugToUpgrade': params['pTransSlugToUpgrade'],
        'pTransDone': params['pTransSlugToUpgrade'] == undefined
    };
    updatesoverview_upgrade_int_flow(new_params);
    return false;
};

let updatesoverview_upgrade_int_loop_flow = function (params) {
    updatesoverview_upgrade_int_flow(params);
    return false;
};

let updatesoverview_upgrade_all_update_done = function () {
    mainwpVars.currentThreads--;
    if (!mainwpVars.bulkTaskRunning)
        return;
    mainwpVars.websitesDone++;

    mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);

    if (mainwpVars.websitesDone == mainwpVars.websitesTotal) {
        mainwpVars.bulkTaskRunning = false;
        if (!mainwpVars?.errorCount) {
            setTimeout(function () {
                // close and refresh page
                mainwpPopup('#mainwp-sync-sites-modal').close(true);
            }, 3000);
        }
        return;
    }

    updatesoverview_upgrade_all_loop_next();
};

let updatesoverview_upgrade_int_flow = function (params) {
    let pWebsiteId = params['pWebsiteId'];
    let pThemeSlugToUpgrade = params['pThemeSlugToUpgrade'];
    let pPluginSlugToUpgrade = params['pPluginSlugToUpgrade'];
    let pWordpressUpgrade = params['pWordpressUpgrade'];
    let pThemeDone = params['pThemeDone'];
    let pPluginDone = params['pPluginDone'];
    let pUpgradeDone = params['pUpgradeDone'];
    let pErrorMessage = params['pErrorMessage'];
    let pTransSlugToUpgrade = params['pTransSlugToUpgrade'];
    let pTransDone = params['pTransDone'];
    if (!pThemeDone) {
        let data = mainwp_secure_data({
            action: 'mainwp_upgradeplugintheme',
            websiteId: pWebsiteId,
            type: 'theme',
            slug: pThemeSlugToUpgrade
        });

        jQuery.ajax({
            type: "POST",
            url: ajaxurl,
            data: data,
            success: function (pWebsiteId, pSlug, pPluginSlugToUpgrade, pWordpressUpgrade, pPluginDone, pUpgradeDone, pErrorMessage, pTransSlugToUpgrade, pTransDone) { // NOSONAR - compatible.
                return function (response) { // NOSONAR - complex ok.
                    let slugParts = pSlug.split(',');
                    for (let sid of slugParts) {
                        let result;
                        let websiteHolder = jQuery('div[theme_slug="' + sid + '"] div[site_id="' + pWebsiteId + '"]');
                        if (!websiteHolder.exists()) {
                            websiteHolder = jQuery('div[site_id="' + pWebsiteId + '"] div[theme_slug="' + sid + '"]');
                        }


                        let isError = true;
                        if (response.error) {
                            result = getErrorMessage(response.error);
                            pErrorMessage = result;
                        } else {
                            let res = response.result;

                            if (res[sid]) {
                                websiteHolder.attr('updated', 1);
                                isError = false;
                            } else {
                                result = __('Update failed!');
                                pErrorMessage = result;
                            }
                        }

                        if (isError) {
                            let res_error = response?.result_error ? response.result_error : '';
                            if (res_error ? res_error[encodeURIComponent(sid)] : false) {
                                let _msg = res_error[encodeURIComponent(sid)];
                                let roll_error = mainwp_updates_get_rollback_msg(_msg);
                                mainwp_put_actions_errors_msg('updateall', pWebsiteId, (roll_error ? 'roll' : 'default'), roll_error || _msg);// save errors to show later.
                            }
                        }

                    }
                    updatesoverview_upgrade_all_update_site_bold(pWebsiteId, 'theme');

                    //If all done: continue, else delay 400ms to not stress the server
                    let fnc = function () {
                        let params = {
                            'pWebsiteId': pWebsiteId,
                            'pThemeSlugToUpgrade': pSlug,
                            'pPluginSlugToUpgrade': pPluginSlugToUpgrade,
                            'pWordpressUpgrade': pWordpressUpgrade,
                            'pThemeDone': true,
                            'pPluginDone': pPluginDone,
                            'pUpgradeDone': pUpgradeDone,
                            'pErrorMessage': pErrorMessage,
                            'pTransSlugToUpgrade': pTransSlugToUpgrade,
                            'pTransDone': pTransDone
                        };
                        updatesoverview_upgrade_int_loop_flow(params);
                    };

                    if (pPluginDone && pUpgradeDone && pTransDone)
                        fnc();
                    else
                        setTimeout(fnc, 400);
                }
            }(pWebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pPluginDone, pUpgradeDone, pErrorMessage, pTransSlugToUpgrade, pTransDone),
            tryCount: 0,
            retryLimit: 3,
            endError: function (pWebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pTransSlugToUpgrade) {
                return function () {
                    let params = {
                        'pWebsiteId': pWebsiteId,
                        'pThemeSlugToUpgrade': pThemeSlugToUpgrade,
                        'pPluginSlugToUpgrade': pPluginSlugToUpgrade,
                        'pWordpressUpgrade': pWordpressUpgrade,
                        'pThemeDone': true,
                        'pPluginDone': true,
                        'pUpgradeDone': true,
                        'pErrorMessage': 'Error processing request',
                        'pTransSlugToUpgrade': pTransSlugToUpgrade,
                        'pTransDone': true
                    };
                    updatesoverview_upgrade_int_loop_flow(params);
                }
            }(pWebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pTransSlugToUpgrade),
            error: function (xhr) {
                this.tryCount++;
                if (this.tryCount >= this.retryLimit) {
                    this.endError();
                    return;
                }
                let pRqst = this;
                let pXhr = xhr;
                setTimeout(function () {
                    if (pXhr.status == 404) {
                        //handle error
                        jQuery.ajax(pRqst);
                    } else if (pXhr.status == 500) {
                        //handle error
                    } else {
                        //handle error
                    }
                }, 1000);
            },
            dataType: 'json'
        });
    } else if (!pPluginDone) {
        let data = mainwp_secure_data({
            action: 'mainwp_upgradeplugintheme',
            websiteId: pWebsiteId,
            type: 'plugin',
            slug: pPluginSlugToUpgrade
        });

        jQuery.ajax({
            type: "POST",
            url: ajaxurl,
            data: data,
            success: function (pWebsiteId, pThemeSlugToUpgrade, pSlug, pWordpressUpgrade, pThemeDone, pUpgradeDone, pErrorMessage, pTransSlugToUpgrade, pTransDone) { // NOSONAR - compatible.
                return function (response) { // NOSONAR - complex ok.
                    let slugParts = pSlug.split(',');
                    for (let sid of slugParts) {
                        let result;
                        let websiteHolder = jQuery('div[plugin_slug="' + sid + '"] div[site_id="' + pWebsiteId + '"]');
                        if (!websiteHolder.exists()) {
                            websiteHolder = jQuery('div[site_id="' + pWebsiteId + '"] div[plugin_slug="' + sid + '"]');
                        }
                        if (response.error) {
                            result = getErrorMessage(response.error);
                            pErrorMessage = result;
                        }
                        else {
                            let res = response.result;   // result is an object
                            let res_error = response.result_error;
                            if (res[encodeURIComponent(sid)]) {
                                websiteHolder.attr('updated', 1);
                            } else if (res_error[encodeURIComponent(sid)]) {
                                pErrorMessage = res_error[encodeURIComponent(sid)];
                                let roll_error = mainwp_updates_get_rollback_msg(pErrorMessage);
                                mainwp_put_actions_errors_msg('updateall', pWebsiteId, roll_error ? 'roll' : 'default', roll_error || pErrorMessage);// save errors to show later.
                            } else {
                                result = __('Update failed!');
                                pErrorMessage = result;
                            }
                        }
                    }
                    updatesoverview_upgrade_all_update_site_bold(pWebsiteId, 'plugin');

                    //If all done: continue, else delay 400ms to not stress the server
                    let fnc = function () {
                        let params = {
                            'pWebsiteId': pWebsiteId,
                            'pThemeSlugToUpgrade': pThemeSlugToUpgrade,
                            'pPluginSlugToUpgrade': pSlug,
                            'pWordpressUpgrade': pWordpressUpgrade,
                            'pThemeDone': pThemeDone,
                            'pPluginDone': true,
                            'pUpgradeDone': pUpgradeDone,
                            'pErrorMessage': pErrorMessage,
                            'pTransSlugToUpgrade': pTransSlugToUpgrade,
                            'pTransDone': pTransDone
                        };
                        updatesoverview_upgrade_int_loop_flow(params);
                    };

                    if (pThemeDone && pUpgradeDone && pTransDone)
                        fnc();
                    else
                        setTimeout(fnc, 400);
                }
            }(pWebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pThemeDone, pUpgradeDone, pErrorMessage, pTransSlugToUpgrade, pTransDone),
            tryCount: 0,
            retryLimit: 3,
            endError: function (pWebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pTransSlugToUpgrade) {
                return function () {
                    let params = {
                        'pWebsiteId': pWebsiteId,
                        'pThemeSlugToUpgrade': pThemeSlugToUpgrade,
                        'pPluginSlugToUpgrade': pPluginSlugToUpgrade,
                        'pWordpressUpgrade': pWordpressUpgrade,
                        'pThemeDone': true,
                        'pPluginDone': true,
                        'pUpgradeDone': true,
                        'pErrorMessage': 'Error processing request',
                        'pTransSlugToUpgrade': pTransSlugToUpgrade,
                        'pTransDone': true
                    };
                    updatesoverview_upgrade_int_loop_flow(params);
                }
            }(pWebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pTransSlugToUpgrade),
            error: function (xhr) {
                this.tryCount++;
                if (this.tryCount >= this.retryLimit) {
                    this.endError();
                    return;
                }
                setTimeout(function (pRqst, pXhr) {
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
                }(this, xhr), 1000);
            },
            dataType: 'json'
        });
    } else if (!pUpgradeDone) {
        let data = mainwp_secure_data({
            action: 'mainwp_upgradewp',
            id: pWebsiteId
        });

        jQuery.ajax({
            type: "POST",
            url: ajaxurl,
            data: data,
            success: function (WebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pThemeDone, pPluginDone, pErrorMessage, pTransSlugToUpgrade, pTransDone) { // NOSONAR - compatible.
                return function (response) {
                    let result;
                    let websiteHolder = jQuery('div[site_id="' + pWebsiteId + '"]');
                    if (response.error) {
                        result = getErrorMessage(response.error);
                        pErrorMessage = result;
                    } else {
                        websiteHolder.attr('updated', 1);
                    }
                    updatesoverview_upgrade_all_update_site_bold(pWebsiteId, 'wordpress');

                    //If all done: continue, else delay 400ms to not stress the server
                    let fnc = function () {
                        let params = {
                            'pWebsiteId': pWebsiteId,
                            'pThemeSlugToUpgrade': pThemeSlugToUpgrade,
                            'pPluginSlugToUpgrade': pPluginSlugToUpgrade,
                            'pWordpressUpgrade': pWordpressUpgrade,
                            'pThemeDone': pThemeDone,
                            'pPluginDone': pPluginDone,
                            'pUpgradeDone': true,
                            'pErrorMessage': pErrorMessage,
                            'pTransSlugToUpgrade': pTransSlugToUpgrade,
                            'pTransDone': pTransDone
                        };
                        updatesoverview_upgrade_int_loop_flow(params);
                    };

                    if (pThemeDone && pPluginDone && pTransDone)
                        fnc();
                    else
                        setTimeout(fnc, 400);
                }
            }(pWebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pThemeDone, pPluginDone, pErrorMessage, pTransSlugToUpgrade, pTransDone),
            tryCount: 0,
            retryLimit: 3,
            endError: function (WebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pTransSlugToUpgrade) {
                return function () {
                    let params = {
                        'pWebsiteId': WebsiteId,
                        'pThemeSlugToUpgrade': pThemeSlugToUpgrade,
                        'pPluginSlugToUpgrade': pPluginSlugToUpgrade,
                        'pWordpressUpgrade': pWordpressUpgrade,
                        'pThemeDone': true,
                        'pPluginDone': true,
                        'pUpgradeDone': true,
                        'pErrorMessage': 'Error processing request',
                        'pTransSlugToUpgrade': pTransSlugToUpgrade,
                        'pTransDone': true
                    };
                    updatesoverview_upgrade_int_loop_flow(params);
                }
            }(pWebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pTransSlugToUpgrade),
            error: function (xhr) {
                this.tryCount++;
                if (this.tryCount >= this.retryLimit) {
                    this.endError();
                    return;
                }

                setTimeout(function (pRqst, pXhr) {
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
                }(this, xhr), 1000);
            },
            dataType: 'json'
        });
    } else if (!pTransDone) {
        let data = mainwp_secure_data({
            action: 'mainwp_upgradeplugintheme',
            websiteId: pWebsiteId,
            type: 'translation',
            slug: pTransSlugToUpgrade
        });

        jQuery.ajax({
            type: "POST",
            url: ajaxurl,
            data: data,
            success: function (pWebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pThemeDone, pUpgradeDone, pErrorMessage, pSlug) { // NOSONAR - compatible.
                return function (response) {
                    let slugParts = pSlug.split(',');
                    for (let sid of slugParts) {
                        let result;
                        let websiteHolder = jQuery('div[translation_slug="' + sid + '"] div[site_id="' + pWebsiteId + '"]');
                        if (!websiteHolder.exists()) {
                            websiteHolder = jQuery('div[site_id="' + pWebsiteId + '"] div[translation_slug="' + sid + '"]');
                        }
                        if (response.error) {
                            result = getErrorMessage(response.error);
                            pErrorMessage = result;
                        } else {
                            let res = response.result;
                            if (res[sid]) {
                                websiteHolder.attr('updated', 1);
                            } else {
                                result = __('Update failed!');
                                pErrorMessage = result;
                            }

                        }
                    }

                    updatesoverview_upgrade_all_update_site_bold(pWebsiteId, 'translation');

                    //If all done: continue, else delay 400ms to not stress the server
                    let fnc = function () {
                        let params = {
                            'pWebsiteId': pWebsiteId,
                            'pThemeSlugToUpgrade': pSlug,
                            'pPluginSlugToUpgrade': pPluginSlugToUpgrade,
                            'pWordpressUpgrade': pWordpressUpgrade,
                            'pThemeDone': pThemeDone,
                            'pPluginDone': pPluginDone,
                            'pUpgradeDone': pUpgradeDone,
                            'pErrorMessage': pErrorMessage,
                            'pTransSlugToUpgrade': pSlug,
                            'pTransDone': true
                        };
                        updatesoverview_upgrade_int_loop_flow(params);
                    };

                    if (pThemeDone && pUpgradeDone && pPluginDone)
                        fnc();
                    else
                        setTimeout(fnc, 400);
                }
            }(pWebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pThemeDone, pUpgradeDone, pErrorMessage, pTransSlugToUpgrade),
            tryCount: 0,
            retryLimit: 3,
            endError: function (pWebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pTransSlugToUpgrade) {
                return function () {
                    let params = {
                        'pWebsiteId': pWebsiteId,
                        'pThemeSlugToUpgrade': pThemeSlugToUpgrade,
                        'pPluginSlugToUpgrade': pPluginSlugToUpgrade,
                        'pWordpressUpgrade': pWordpressUpgrade,
                        'pThemeDone': true,
                        'pPluginDone': true,
                        'pUpgradeDone': true,
                        'pErrorMessage': 'Error processing request',
                        'pTransSlugToUpgrade': pTransSlugToUpgrade,
                        'pTransDone': true
                    };
                    updatesoverview_upgrade_int_loop_flow(params);
                }
            }(pWebsiteId, pThemeSlugToUpgrade, pPluginSlugToUpgrade, pWordpressUpgrade, pTransSlugToUpgrade),
            error: function (xhr) {
                this.tryCount++;
                if (this.tryCount >= this.retryLimit) {
                    this.endError();
                    return;
                }
                setTimeout(function (pRqst, pXhr) {
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
                }(this, xhr), 1000);
            },
            dataType: 'json'
        });
    } else {
        if ((pErrorMessage != undefined) && (pErrorMessage != '')) {
            mainwpVars.errorCount++;

            let rollErrors = mainwp_get_actions_errors_msg('updateall', pWebsiteId, 'roll');
            let otherErrors = mainwp_get_actions_errors_msg('updateall', pWebsiteId, 'default');

            let _icon = '<i class="red times icon"></i>';
            let _error = '';
            if (rollErrors) {
                _icon = mainwpParams.roll_ui_icon;
                _error = rollErrors + otherErrors;
            } else {
                _error = otherErrors
            }

            updatesoverview_upgrade_all_update_site_status(pWebsiteId, '<span class="mainwp-html-popup" data-position="left center" data-html="">' + _icon + '</span>');
            mainwp_init_html_popup('.updatesoverview-upgrade-status-wp[siteid="' + pWebsiteId + '"] .mainwp-html-popup', _error);
        } else {
            updatesoverview_upgrade_all_update_site_status(pWebsiteId, '<i class="green check icon"></i>');
        }
        updatesoverview_upgrade_all_update_done();

        return false;
    }
};

let updatesoverview_plugins_dismiss_outdate_detail = function (slug, name, id, obj) {
    return updatesoverview_dismiss_outdate_plugintheme_by_site('plugin', slug, name, id, obj);
};
let updatesoverview_themes_dismiss_outdate_detail = function (slug, name, id, obj) {
    return updatesoverview_dismiss_outdate_plugintheme_by_site('theme', slug, name, id, obj);
};

let updatesoverview_plugins_unignore_abandoned_detail = function (slug, id) {
    return updatesoverview_unignore_plugintheme_abandoned_by_site('plugin', slug, id);
};
let updatesoverview_plugins_unignore_abandoned_detail_all = function () {
    return updatesoverview_unignore_plugintheme_abandoned_by_site_all('plugin');
};
let updatesoverview_themes_unignore_abandoned_detail = function (slug, id) {
    return updatesoverview_unignore_plugintheme_abandoned_by_site('theme', slug, id);
};
let updatesoverview_themes_unignore_abandoned_detail_all = function () {
    return updatesoverview_unignore_plugintheme_abandoned_by_site_all('theme');
};

let updatesoverview_dismiss_outdate_plugintheme_by_site = function (what, slug, name, id, pObj) {
    let data = mainwp_secure_data({
        action: 'mainwp_dismissoutdateplugintheme',
        type: what,
        id: id,
        slug: slug,
        name: name
    });
    let parent = jQuery(pObj).closest('tr');
    parent.find('td:last-child').html('<span data-tooltip="Please wait..." data-position="left center" data-inverted=""><i class="notched circle loading icon"></i></span>');
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            parent.attr('dismissed', '-1');
            parent.find('td:last-child').html(__('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>'));

        } else {
            parent.find('td:last-child').html(getErrorMessage(response.error));
        }
    }, 'json');
    return false;
};

// Unignore abandoned Plugin/Theme ignored per site basis
let updatesoverview_unignore_plugintheme_abandoned_by_site = function (what, slug, id) {
    let data = mainwp_secure_data({
        action: 'mainwp_unignoreabandonedplugintheme',
        type: what,
        id: id,
        slug: slug
    });

    jQuery.post(ajaxurl, data, function (pWhat, pSlug, pId) {
        return function (response) {
            if (response.result) {
                let siteElement;
                if (pWhat == 'plugin') {
                    siteElement = jQuery('tr[site-id="' + pId + '"][plugin-slug="' + pSlug + '"]');
                } else {
                    siteElement = jQuery('tr[site-id="' + pId + '"][theme-slug="' + pSlug + '"]');
                }

                if (!siteElement.find('div').is(':visible')) {
                    siteElement.remove();
                    return;
                }

                //Check if previous tr is same site..
                //Check if next tr is same site..
                let siteAfter = siteElement.next();

                if (siteAfter.exists() && (siteAfter.attr('site-id') == pId)) {
                    siteAfter.find('div').show();
                    siteElement.remove();
                    return;
                }

                let parent = siteElement.parent();

                siteElement.remove();

                if (parent.children('tr').length == 0) {
                    jQuery('#mainwp-unignore-detail-all').addClass('disabled');
                    parent.append('<tr><td colspan="999">' + __('No ignored abandoned %1s', pWhat) + '</td></tr>');
                }
            }
        }
    }(what, slug, id), 'json');
    return false;
};

// Unignore all per site ignored abandoned Plugins / Themese
let updatesoverview_unignore_plugintheme_abandoned_by_site_all = function (what) {
    let data = mainwp_secure_data({
        action: 'mainwp_unignoreabandonedplugintheme',
        type: what,
        id: '_ALL_',
        slug: '_ALL_'
    });

    jQuery.post(ajaxurl, data, function (pWhat) {
        return function (response) {
            if (response.result) {
                let tableElement = jQuery('#ignored-abandoned-' + pWhat + 's-list');
                tableElement.find('tr').remove();
                tableElement.append('<tr><td colspan="999">' + __('No ignored abandoned %1s', pWhat) + '</td></tr>');
                jQuery('#mainwp-unignore-detail-all').addClass('disabled');
            }
        }
    }(what), 'json');
    return false;
};

let updatesoverview_plugins_abandoned_ignore_all = function (slug, name, pObj) {
    let parent = jQuery(pObj).closest('tr');
    parent.find('td:last-child').html('<span data-tooltip="Please wait..." data-position="left center" data-inverted=""><i class="notched circle loading icon"></i></span>');
    let data = mainwp_secure_data({
        action: 'mainwp_dismissoutdatepluginsthemes',
        type: 'plugin',
        slug: slug,
        name: name
    });
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            parent.find('td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
            jQuery('.abandoned-plugins-ignore-global[plugin_slug="' + slug + '"]').find('tr td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
            jQuery('.abandoned-plugins-ignore-global[plugin_slug="' + slug + '"]').find('tr[dismissed=0]').attr('dismissed', 1);
        } else {
            parent.find('td:last-child').html(getErrorMessage(response.error));
        }
    }, 'json');
    return false;
};

// Unignore all globally ignored abandoned plugins
let updatesoverview_plugins_abandoned_unignore_globally_all = function () {

    let data = mainwp_secure_data({
        action: 'mainwp_unignoreabandonedpluginsthemes',
        type: 'plugin',
        slug: '_ALL_'
    });

    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            let tableElement = jQuery('#ignored-globally-abandoned-plugins-list');
            tableElement.find('tr').remove();
            jQuery('#mainwp-unignore-globally-all').addClass('disabled');
            tableElement.append('<tr><td colspan="999">' + __('No ignored abandoned plugins.') + '</td></tr>');
        }
    }, 'json');

    return false;

};

// Unignore globally ignored abandoned plugin
let updatesoverview_plugins_abandoned_unignore_globally = function (slug) {

    let data = mainwp_secure_data({
        action: 'mainwp_unignoreabandonedpluginsthemes',
        type: 'plugin',
        slug: slug
    });

    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            let ignoreElement = jQuery('#ignored-globally-abandoned-plugins-list tr[plugin-slug="' + slug + '"]');
            let parent = ignoreElement.parent();
            ignoreElement.remove();
            if (parent.children('tr').length == 0) {
                jQuery('.mainwp-unignore-globally-all').addClass('disabled');
                parent.append('<tr><td colspan="999">' + __('No ignored abandoned plugins.') + '</td></tr>');
            }
        }
    }, 'json');
    return false;
};
let updatesoverview_themes_abandoned_ignore_all = function (slug, name, pObj) {
    let parent = jQuery(pObj).closest('tr');
    parent.find('td:last-child').html('<span data-tooltip="Please wait..." data-position="left center" data-inverted=""><i class="notched circle loading icon"></i></span>');

    let data = mainwp_secure_data({
        action: 'mainwp_dismissoutdatepluginsthemes',
        type: 'theme',
        slug: slug,
        name: name
    });
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            parent.find('td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
            jQuery('.abandoned-themes-ignore-global[theme_slug="' + slug + '"]').find('tr td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span> ');
            jQuery('.abandoned-themes-ignore-global[theme_slug="' + slug + '"]').find('tr[dismissed=0]').attr('dismissed', 1);
        } else {
            parent.find('td:last-child').html(getErrorMessage(response.error));
        }
    }, 'json');
    return false;
};

// Unignore all globablly ignored themes
let updatesoverview_themes_abandoned_unignore_globally_all = function () {
    let data = mainwp_secure_data({
        action: 'mainwp_unignoreabandonedpluginsthemes',
        type: 'theme',
        slug: '_ALL_'
    });

    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            let tableElement = jQuery('#globally-ignored-themes-list');
            tableElement.find('tr').remove();
            jQuery('.mainwp-unignore-globally-all').addClass('disabled');
            tableElement.append('<tr><td colspan="999">' + __('No ignored abandoned themes.') + '</td></tr>');
        }
    }, 'json');

    return false;
};

// Unignore globally ignored theme
let updatesoverview_themes_abandoned_unignore_globally = function (slug) {
    let data = mainwp_secure_data({
        action: 'mainwp_unignoreabandonedpluginsthemes',
        type: 'theme',
        slug: slug
    });

    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            let ignoreElement = jQuery('#globally-ignored-themes-list tr[theme-slug="' + slug + '"]');
            let parent = ignoreElement.parent();
            ignoreElement.remove();
            if (parent.children('tr').length == 0) {
                jQuery('.mainwp-unignore-globally-all').addClass('disabled');
                parent.append('<tr><td colspan="999">' + __('No ignored abandoned themes.') + '</td></tr>');
            }
        }
    }, 'json');

    return false;
};

let mainwp_siteview_onchange = function (me) {
    jQuery(me).closest("form").submit();
}

jQuery(function () {
    if (jQuery('#updatesoverview_limit_updates_all').length > 0 && jQuery('#updatesoverview_limit_updates_all').val() > 0) {
        limitUpdateAll = jQuery('#updatesoverview_limit_updates_all').val();
        if (jQuery('.updatesoverview_continue_update_me').length > 0) {
            continueUpdating = true;
            jQuery('.updatesoverview_continue_update_me')[0].trigger('click');
        }
    }
});

let updatesoverview_recheck_http = function (elem, id) {
    let data = mainwp_secure_data({
        action: 'mainwp_recheck_http',
        websiteid: id
    });
    jQuery(elem).attr('disabled', 'true');
    jQuery('#wp_http_response_code_' + id + ' .http-code').html('<i class="ui active inline loader tiny"></i>');
    jQuery.post(ajaxurl, data, function (response) {
        jQuery(elem).prop("disabled", false);
        if (response) {
            let hc = response.httpcode ? response.httpcode : '';
            jQuery('#wp_http_response_code_' + id + ' .http-code').html('HTTP ' + hc);
            if (response.status) {
                jQuery('#wp_http_response_code_' + id).addClass('http-response-ok');
            } else {
                jQuery('#wp_http_response_code_' + id).removeClass('http-response-ok');
            }
        } else {
            jQuery('#wp_http_response_code_' + id + ' .http-code').html(__('Undefined error!'));
        }
    }, 'json');
    return false;
};

let updatesoverview_ignore_http_response = function (elem, id) {
    let data = mainwp_secure_data({
        action: 'mainwp_ignore_http_response',
        websiteid: id
    });
    jQuery(elem).attr('disabled', 'true');
    jQuery('#wp_http_response_code_' + id + ' .http-code').html('<i class="ui active inline loader tiny"></i>');
    jQuery.post(ajaxurl, data, function (response) {
        jQuery(elem).prop("disabled", false);
        if (response?.ok) {
            jQuery(elem).closest('.mainwp-sub-row').remove();
        }
    }, 'json');
    return false;
};

let updatesoverview_ignore_plugintheme_by_site = function (what, slug, name, id, pObj, ignore_ver) {
    let data = mainwp_secure_data({
        action: 'mainwp_ignoreplugintheme',
        type: what,
        id: id,
        slug: slug,
        name: name,
        ignore_ver: ignore_ver
    });

    jQuery.post(ajaxurl, data, function (response) {
        let parent = jQuery(pObj).closest('tr');
        if (response.result) {
            jQuery('div[' + what + '_slug="' + slug + '"] div[site_id="' + id + '"]').attr('updated', '-1'); // ok
            parent.attr('updated', '-1');
            parent.find('td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
        } else {
            parent.find('td:last-child').html(getErrorMessage(response.error));
        }
    }, 'json');
    return false;
};


// Unignore Plugin / Themse ignored per site
let updatesoverview_unignore_plugintheme_by_site = function (what, slug, id, ver) {
    let data = mainwp_secure_data({
        action: 'mainwp_unignoreplugintheme',
        type: what,
        id: id,
        slug: slug,
        ignore_ver: ver
    });

    jQuery.post(ajaxurl, data, function (pWhat, pSlug, pId) {
        return function (response) {
            if (response.result) {
                let siteElement;
                if (pWhat == 'plugin') {
                    siteElement = jQuery('tr[site-id="' + pId + '"][plugin-slug="' + pSlug + '"]');
                } else {
                    siteElement = jQuery('tr[site-id="' + pId + '"][theme-slug="' + pSlug + '"]');
                }

                if (!siteElement.find('div').is(':visible')) {
                    mainwp_responsive_fix_remove_child_row(siteElement);
                    siteElement.remove();
                    return;
                }

                //Check if previous tr is same site..
                //Check if next tr is same site..
                let siteAfter = siteElement.next();
                if (siteAfter.exists() && (siteAfter.attr('site-id') == pId)) {
                    siteAfter.find('div').show();
                    mainwp_responsive_fix_remove_child_row(siteElement);
                    siteElement.remove();
                    return;
                }

                let parent = siteElement.parent();
                mainwp_responsive_fix_remove_child_row(siteElement);
                siteElement.remove();
                if (parent.children('tr').length == 0) {
                    parent.append('<tr><td colspan="999">' + __('No ignored %1s', pWhat) + '</td></tr>');
                    jQuery('.mainwp-unignore-detail-all').addClass('disabled');
                }
            }
        }
    }(what, slug, id), 'json');
    return false;
};


// Unignore all Plugins / Themses ignored per site
let updatesoverview_unignore_plugintheme_by_site_all = function (what) {
    let data = mainwp_secure_data({
        action: 'mainwp_unignoreplugintheme',
        type: what,
        id: '_ALL_',
        slug: '_ALL_'
    });

    jQuery.post(ajaxurl, data, function (pWhat) {
        return function (response) {
            if (response.result) {
                let tableElement = jQuery('#ignored-' + pWhat + 's-list');
                tableElement.find('tr').remove();
                tableElement.append('<tr><td colspan="999">' + __('No ignored %1s', pWhat) + '</td></tr>');
                jQuery('.mainwp-unignore-detail-all').addClass('disabled');
            }
        }
    }(what), 'json');
    return false;
};

/**Plugins part**/
let updatesoverview_plugins_ignore_detail = function (slug, name, id, obj, ignore_ver) {
    const row = jQuery(obj).closest("tr");
    const site_name = jQuery(row).attr("site_name");
    let msg = __(
        "Are you sure you want to ignore %1 plugin updates on %2? The updates will no longer be visible in your MainWP Dashboard.",
        decodeURIComponent(name),
        site_name ?? ''
    );
    mainwp_confirm(
        msg,
        function () {
            return updatesoverview_ignore_plugintheme_by_site('plugin', slug, name, id, obj, ignore_ver);
        },
        false
    );
    return false;
};
let updatesoverview_plugins_unignore_detail = function (slug, id, ver) {
    return updatesoverview_unignore_plugintheme_by_site('plugin', slug, id, ver);
};
let updatesoverview_plugins_unignore_detail_all = function () {
    return updatesoverview_unignore_plugintheme_by_site_all('plugin');
};
let updatesoverview_themes_ignore_detail = function (slug, name, id, obj, ignore_ver) {
    let msg = __("Are you sure you want to ignore the %1 theme updates? The updates will no longer be visible in your MainWP Dashboard.", name);
    mainwp_confirm(msg, function () {
        return updatesoverview_ignore_plugintheme_by_site('theme', slug, name, id, obj, ignore_ver);
    }, false);
    return false;
};
let updatesoverview_themes_unignore_detail = function (slug, id, ver) {
    return updatesoverview_unignore_plugintheme_by_site('theme', slug, id, ver);
};
let updatesoverview_themes_unignore_detail_all = function () {
    return updatesoverview_unignore_plugintheme_by_site_all('theme');
};
let updatesoverview_plugins_ignore_all = function (slug, name, obj, ver) {
    const row = jQuery(obj).closest("tr");
    const site_name = jQuery(row).attr("site_name");
    let msg = '';
    if (site_name !== undefined) {
        msg = __(
            "Are you sure you want to ignore %1 plugin updates on %2 site? The updates will no longer be visible in your MainWP Dashboard.",
            decodeURIComponent(name),
            site_name
        );
    } else {
        msg = __(
            "Are you sure you want to ignore the %1 plugin updates? The updates will no longer be visible in your MainWP Dashboard.",
            decodeURIComponent(name)
        );
    }
    mainwp_confirm(msg, function () {
        let data = mainwp_secure_data({
            action: 'mainwp_ignorepluginsthemes',
            type: 'plugin',
            slug: slug,
            name: name,
            ignore_ver: ver
        });
        let parent = jQuery(obj).closest('tr');
        parent.find('td:last-child').html('<span data-tooltip="Please wait..." data-position="left center" data-inverted=""><i class="notched circle loading icon"></i></span>');
        jQuery.post(ajaxurl, data, function (response) {
            if (response.result) {
                if (ver != undefined && ver != '') { // ignore this version.
                    console.log('ver' + ver);
                    parent.find('td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
                    jQuery('tr[plugin_slug="' + slug + '"][last-version="' + ver + '"]').find('td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
                    jQuery('tr[plugin_slug="' + slug + '"][last-version="' + ver + '"]').attr('updated', '-1');
                } else {
                    console.log('not ver');
                    parent.find('td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
                    jQuery('tr[plugin_slug="' + slug + '"]').find('table tr td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
                    jQuery('tr[plugin_slug="' + slug + '"]').find('table tr').attr('updated', '-1');
                }
            }
        }, 'json');
    }, false);
    return false;
};

// Unignore all globally ignored plugins
let updatesoverview_plugins_unignore_globally_all = function () {
    let data = mainwp_secure_data({
        action: 'mainwp_unignorepluginsthemes',
        type: 'plugin',
        slug: '_ALL_'
    });

    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            let tableElement = jQuery('#globally-ignored-plugins-list');
            tableElement.find('tr').remove();
            jQuery('#mainwp-unignore-globally-all').addClass('disabled');
            tableElement.append('<tr><td colspan="999">' + __('No ignored plugins.') + '</td></tr>');
        }
    }, 'json');
    return false;
};

// Unignore globally ignored plugin
let updatesoverview_plugins_unignore_globally = function (slug, ver) {
    let data = mainwp_secure_data({
        action: 'mainwp_unignorepluginsthemes',
        type: 'plugin',
        slug: slug,
        ignore_ver: ver
    });
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            let ignoreElement = jQuery('#globally-ignored-plugins-list tr[plugin-slug="' + slug + '"]');
            let parent = ignoreElement.parent();
            ignoreElement.remove();
            if (parent.children('tr').length == 0) {
                jQuery('#mainwp-unignore-globally-all').addClass('disabled');
                parent.append('<tr><td colspan="999">' + __('No ignored plugins.') + '</td></tr>');
            }
        }
    }, 'json');
    return false;
};

let updatesoverview_themes_ignore_all = function (slug, name, obj, ver) {
    let msg = __('Are you sure you want to ignore the %1 theme updates? The updates will no longer be visible in your MainWP Dashboard.', name);
    mainwp_confirm(msg, function () {

        let data = mainwp_secure_data({
            action: 'mainwp_ignorepluginsthemes', // ignore global or this version global.
            type: 'theme',
            slug: slug,
            name: name,
            ignore_ver: ver
        });
        let parent = jQuery(obj).closest('tr');
        parent.find('td:last-child').html('<span data-tooltip="Please wait..." data-position="left center" data-inverted=""><i class="notched circle loading icon"></i></span>');
        jQuery.post(ajaxurl, data, function (response) {
            if (response.result) {
                if (ver != undefined && ver != '') { // ignore this version.
                    parent.find('td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
                    jQuery('tr[theme_slug="' + slug + '"][last-version="' + ver + '"]').find('td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
                    jQuery('tr[theme_slug="' + slug + '"][last-version="' + ver + '"]').attr('updated', '-1');
                } else {
                    parent.find('td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
                    jQuery('tr[theme_slug="' + slug + '"]').find('table tr td:last-child').html('<span data-tooltip="Ignored" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
                    jQuery('tr[theme_slug="' + slug + '"]').find('table tr').attr('updated', '-1');
                }
            }
        }, 'json');
    }, false);
    return false;
};

// Unignore all globally ignored themes
let updatesoverview_themes_unignore_globally_all = function () {
    let data = mainwp_secure_data({
        action: 'mainwp_unignorepluginsthemes',
        type: 'theme',
        slug: '_ALL_'
    });

    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            let tableElement = jQuery('#globally-ignored-themes-list');
            tableElement.find('tr').remove();
            jQuery('#mainwp-unignore-globally-all').addClass('disabled');
            tableElement.append('<tr><td colspan="999">' + __('No ignored themes.') + '</td></tr>');
        }
    }, 'json');

    return false;
};

// Unignore globally ignored theme
let updatesoverview_themes_unignore_globally = function (slug, ver) {
    let data = mainwp_secure_data({
        action: 'mainwp_unignorepluginsthemes',
        type: 'theme',
        slug: slug,
        ignore_ver: ver
    });
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            let ignoreElement = jQuery('#globally-ignored-themes-list tr[theme-slug="' + slug + '"]');
            let parent = ignoreElement.parent();
            ignoreElement.remove();
            if (parent.children('tr').length == 0) {
                jQuery('#mainwp-unignore-globally-all').addClass('disabled');
                parent.append('<tr><td colspan="999">' + __('No ignored themes.') + '</td></tr>');
            }
        }
    }, 'json');
    return false;
};


let manageupdates_ignore_updates = function (what, ignore, slug, name, site_id, ignore_ver, ignored_callback) {
    let data = mainwp_secure_data({
        action: 'mainwp_updates_ignore_upgrades',
        type: what,
        ignore: ignore,
        site_id: site_id, // empty is global ignore.
        name: name,
        slug: slug,
        ignore_ver: ignore_ver
    });
    jQuery.post(ajaxurl, data, function (response) {
        if (typeof ignored_callback == 'function') {
            ignored_callback(response);
        }
    }, 'json');
    return false;
};

let updatesoverview_upgrade_ignore = function (site_id, obj, ignore_ver) {
    let ignored_callback = function () {
        jQuery(obj).closest('tr').find('td:last-child').html('<span data-tooltip="Ignored this version" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
    };
    manageupdates_ignore_updates('wp', 'this_version', 'core', 'WordPress', site_id, ignore_ver, ignored_callback)
    return false;
};


let updatesoverview_upgrade_ignore_this_version_globally = function (ignore_ver) {
    let ignored_callback = function () {
        jQuery('tr.mainwp-wordpress-update[last-version="' + ignore_ver + '"]').find('td:last-child').html('<span data-tooltip="Ignored this version globally" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
        jQuery('tr.mainwp-wordpress-update[last-version="' + ignore_ver + '"]').attr('updated', '-1');
    };
    manageupdates_ignore_updates('wp', 'this_version_global', 'core', 'WordPress', 0, ignore_ver, ignored_callback)
    return false;
};

let updatesoverview_upgrade_ignore_all_version = function (site_id, obj) {
    let ignored_callback = function () {
        jQuery(obj).closest('tr').find('td:last-child').html('<span data-tooltip="Ignored this version" data-position="left center" data-inverted=""><i class="check green icon"></i></span>');
    };
    manageupdates_ignore_updates('wp', 'all_versions', 'core', 'WordPress', site_id, 'all_versions', ignored_callback)
    return false;
};

// Unignore cores per site
let updatesoverview_unignore_cores_by_site = function (id, ver) {
    let data = mainwp_secure_data({
        action: 'mainwp_updates_unignore_upgrades',
        id: id,
        ignore_ver: ver
    });
    jQuery.post(ajaxurl, data, function (pVer, pId) {
        return function (response) {
            if (response.result && 'success' == response.result) {
                let siteElement = jQuery('tr[site-id="' + pId + '"][ignored-ver="' + pVer + '"]');

                if (!siteElement.find('div').is(':visible')) {
                    mainwp_responsive_fix_remove_child_row(siteElement);
                    siteElement.remove();
                    return;
                }

                //Check if previous tr is same site..
                //Check if next tr is same site..
                let siteAfter = siteElement.next();
                if (siteAfter.exists() && (siteAfter.attr('site-id') == pId)) {
                    siteAfter.find('div').show();
                    mainwp_responsive_fix_remove_child_row(siteElement);
                    siteElement.remove();
                    return;
                }

                let parent = siteElement.parent();
                mainwp_responsive_fix_remove_child_row(siteElement);
                siteElement.remove();
                if (parent.children('tr').length == 0) {
                    parent.append('<tr><td colspan="999">' + __('No ignored WordPress') + '</td></tr>');
                    jQuery('#mainwp-unignore-cores-detail-all').addClass('disabled');
                }
            }
        }
    }(ver, id), 'json');
    return false;
};

// Unignore all Plugins / Themses ignored per site
let updatesoverview_unignore_cores_by_site_all = function () {
    let data = mainwp_secure_data({
        action: 'mainwp_updates_unignore_upgrades',
        id: '_ALL_',
    });

    jQuery.post(ajaxurl, data, function () {
        return function (response) {
            if (response.result && 'success' == response.result) {
                let parent = jQuery('#ignored-cores-list');
                parent.find('tr').remove();
                parent.append('<tr><td colspan="999">' + __('No ignored WordPress') + '</td></tr>');
                jQuery('.mainwp-unignore-detail-all').addClass('disabled');
            }
        }
    }, 'json');
    return false;
};



// Unignore globally ignored cores.
let updatesoverview_cores_unignore_globally = function (ver) {
    let data = mainwp_secure_data({
        action: 'mainwp_updates_unignore_global_upgrades',
        ignore_ver: ver
    });
    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            let ignoreElement = jQuery('#globally-ignored-cores-list tr[ignored-ver="' + ver + '"]');
            let parent = ignoreElement.parent();
            ignoreElement.remove();
            if (parent.children('tr').length == 0) {
                jQuery('#mainwp-unignore-globally-all').addClass('disabled');
                parent.append('<tr><td colspan="999">' + __('No ignored WordPress.') + '</td></tr>');
            }
        }
    }, 'json');
    return false;
};


// Unignore all globally ignored cores.
let updatesoverview_cores_unignore_globally_all = function () {
    let data = mainwp_secure_data({
        action: 'mainwp_updates_unignore_global_upgrades',
        slug: '_ALL_'
    });

    jQuery.post(ajaxurl, data, function (response) {
        if (response.result) {
            let tableElement = jQuery('#globally-ignored-cores-list');
            tableElement.find('tr').remove();
            jQuery('#mainwp-unignore-globally-all').addClass('disabled');
            tableElement.append('<tr><td colspan="999">' + __('No ignored WordPress.') + '</td></tr>');
        }
    }, 'json');
    return false;
};

let updatesoverview_upgrade_translation = function (id, slug) {
    let msg = __('Are you sure you want to update the translation on the selected site?');
    mainwp_confirm(msg, function () {
        return updatesoverview_translations_upgrade_int(slug, id);
    }, false, 1);
};

let updatesoverview_translations_upgrade = function (slug, websiteid) {
    let msg = __('Are you sure you want to update the translation on the selected site?');
    mainwp_confirm(msg, function () {
        return updatesoverview_translations_upgrade_int(slug, websiteid);
    }, false, 1);
};

let updatesoverview_plugins_upgrade = function (slug, websiteid) {
    let msg = __('Are you sure you want to update the plugin on the selected site?');
    mainwp_confirm(msg, function () {
        return updatesoverview_plugins_upgrade_int(slug, websiteid);
    }, false, 1);
};

let updatesoverview_themes_upgrade = function (slug, websiteid) {
    let msg = __('Are you sure you want to update the theme on the selected site?');
    mainwp_confirm(msg, function () {
        return updatesoverview_themes_upgrade_int(slug, websiteid);
    }, false, 1);
};

/** END NEW **/

let updatesoverview_wp_sync = function (websiteid) {
    let syncIds = [];
    syncIds.push(websiteid);
    mainwp_sync_sites_data(syncIds);
    return false;
};


let updatesoverview_group_upgrade_translation = function (id, slug, groupId) {
    return updatesoverview_upgrade_plugintheme('translation', id, slug, groupId);
};

let updatesoverview_upgrade_translation_all = function (id, updatesSelected) {
    let msg = __('Are you sure you want to update all translations?');
    if (typeof updatesSelected !== 'undefined' && updatesSelected) {
        msg = __('Are you sure you want to update selected translations?');
    }
    mainwp_confirm(msg, function () {
        return updatesoverview_upgrade_plugintheme_all('translation', id, false, updatesSelected);
    }, false, 2);
    return false;
};

let updatesoverview_group_upgrade_translation_all = function (id, groupId, updatesSelected) {
    let msg = __('Are you sure you want to update all translations?');
    if (typeof updatesSelected !== 'undefined' && updatesSelected) {
        msg = __('Are you sure you want to update selected translations?');
    }
    mainwp_confirm(msg, function () {
        return updatesoverview_group_upgrade_plugintheme_all('translation', id, false, groupId, updatesSelected);
    }, false, 2);
    return false;
};

let updatesoverview_upgrade_plugin = function (id, slug) {
    let msg = __('Are you sure you want to update the plugin on the selected site?');
    mainwp_confirm(msg, function () {
        return updatesoverview_upgrade_plugintheme('plugin', id, slug);
    }, false, 1);
};

let updatesoverview_upgrade_plugin_all = function (id, updatesSelected) {
    let msg = __('Are you sure you want to update all plugins?');
    if (typeof updatesSelected !== 'undefined' && updatesSelected) {
        msg = __('Are you sure you want to update selected plugins?');
    }
    mainwp_confirm(msg, function () {
        return updatesoverview_upgrade_plugintheme_all('plugin', id, false, updatesSelected);
    }, false, 2);
    return false;
};

let updatesoverview_group_upgrade_plugin_all = function (id, groupId, updatesSelected) {
    let msg = __('Are you sure you want to update all plugins?');
    if (typeof updatesSelected !== 'undefined' && updatesSelected) {
        msg = __('Are you sure you want to update selected plugins?');
    }
    mainwp_confirm(msg, function () {
        return updatesoverview_group_upgrade_plugintheme_all('plugin', id, false, groupId, updatesSelected);
    }, false, 2);
    return false;
};

let updatesoverview_upgrade_theme = function (id, slug) {
    let msg = __('Are you sure you want to update the theme on the selected site?');
    mainwp_confirm(msg, function () {
        return updatesoverview_upgrade_plugintheme('theme', id, slug);
    }, false, 1);
};
let updatesoverview_upgrade_theme_all = function (id, updatesSelected) {
    let msg = __('Are you sure you want to update all themes?');
    if (typeof updatesSelected !== 'undefined' && updatesSelected) {
        msg = __('Are you sure you want to update selected themes?');
    }
    mainwp_confirm(msg, function () {
        return updatesoverview_upgrade_plugintheme_all('theme', id, false, updatesSelected);
    }, false, 2);
    return false;
};

let updatesoverview_group_upgrade_theme_all = function (id, groupId, updatesSelected) {
    let msg = __('Are you sure you want to update all themes?');
    if (typeof updatesSelected !== 'undefined' && updatesSelected) {
        msg = __('Are you sure you want to update selected themes?');
    }
    mainwp_confirm(msg, function () {
        return updatesoverview_group_upgrade_plugintheme_all('theme', id, false, groupId, updatesSelected);
    }, false, 2);
    return false;
};

let updatesoverview_upgrade_plugintheme = function (what, id, name, groupId) {
    updatesoverview_upgrade_plugintheme_list(what, id, [name], false, groupId);
    return false;
};
let updatesoverview_upgrade_plugintheme_all = function (what, id, noCheck, updatesSelected) {

    // ok: confirmed to do this
    updatesoverviewContinueAfterBackup = function (pId, pWhat) {
        return function () {
            let list = [];
            let slug_att = pWhat + '_slug';
            let slug = '';
            if (typeof updatesSelected !== 'undefined' && updatesSelected) {
                jQuery("#wp_" + pWhat + "_upgrades_" + pId + " tr[updated=0]").each(function () {
                    if (jQuery(this).find('.child.checkbox').checkbox('is checked')) {
                        slug = jQuery(this).attr(slug_att);
                        if (slug) {
                            list.push(slug);
                        }
                    }
                });
                if (list.length == 0) {
                    updates_please_select_items_notice();
                    return false;
                }
            } else {
                jQuery("#wp_" + pWhat + "_upgrades_" + pId + " tr[updated=0]").each(function () {
                    slug = jQuery(this).attr(slug_att);
                    if (slug) {
                        list.push(slug);
                    }

                });
            }
            let siteName = jQuery("#wp_" + pWhat + "_upgrades_" + pId).attr('site_name');
            updatesoverview_upgrade_plugintheme_list_popup(pWhat, pId, siteName, list);
        }
    }(id, what);

    if (noCheck) {
        updatesoverviewContinueAfterBackup();
        return false;
    }

    let sitesToUpdate = [];
    let siteNames = [];

    sitesToUpdate.push(id);
    siteNames[id] = jQuery('tbody[site_id="' + id + '"]').attr('site_name');

    return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
};

let updatesoverview_group_upgrade_plugintheme_all = function (what, id, noCheck, groupId, updatesSelected) {
    // ok, confirmed to do this
    updatesoverviewContinueAfterBackup = function (pId, pWhat) {
        return function () {
            let list = [];
            let slug_att = pWhat + '_slug';
            jQuery("#wp_" + pWhat + "_upgrades_" + pId + '_group_' + groupId + " tr[updated=0]").each(function () {
                let slug = jQuery(this).attr(slug_att);
                if (typeof updatesSelected !== 'undefined' && updatesSelected) {
                    if (jQuery(this).find('.child.checkbox').checkbox('is checked') && slug) {
                        list.push(slug);
                    }
                    if (list.length == 0) {
                        updates_please_select_items_notice();
                        return false;
                    }
                } else if (slug) {
                    list.push(slug);
                }
            });

            // processed by popup
            //updatesoverview_upgrade_plugintheme_list( what, pId, list, true, groupId );
            let siteName = jQuery("#wp_" + pWhat + "_upgrades_" + pId + '_group_' + groupId).attr('site_name');
            updatesoverview_upgrade_plugintheme_list_popup(what, pId, siteName, list);
        }
    }(id, what);

    if (noCheck) {
        updatesoverviewContinueAfterBackup();
        return false;
    }

    let sitesToUpdate = [];
    let siteNames = [];

    sitesToUpdate.push(id);
    siteNames[id] = jQuery('tbody[site_id="' + id + '"]').attr('site_name');

    return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
};

let updatesoverview_upgrade_plugintheme_list = function (what, id, list, noCheck, groupId) {
    updatesoverviewContinueAfterBackup = function (pWhat, pId, pList, pGroupId) {
        return function () {
            let strGroup = '';
            if (typeof pGroupId !== 'undefined') {
                strGroup = '_group_' + pGroupId;
            }
            let newList = [];

            for (let i = pList.length - 1; i >= 0; i--) {
                let item = pList[i];
                let elem = document.getElementById('wp_upgraded_' + pWhat + '_' + pId + strGroup + '_' + item);
                if (elem && elem.value == 0) {
                    let parent = jQuery(elem).closest('tr');
                    parent.find('td:last-child').html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Updating...', 'mainwp') + '"><i class="notched circle loading icon"></i></span> ' + __('Updating. Please wait...'));
                    elem.value = 1;
                    parent.attr('updated', 1);
                    newList.push(item);
                }
            }

            if (newList.length > 0) {
                let data = mainwp_secure_data({
                    action: 'mainwp_upgradeplugintheme',
                    websiteId: pId,
                    type: pWhat,
                    slug: newList.join(',')
                });
                jQuery.post(ajaxurl, data, function (response) { // NOSONAR - complex ok.
                    let success = false;
                    let extErr = '';
                    let _icon_success = '<i class="green check icon"></i>';
                    if (response.error) {
                        extErr = getErrorMessageInfo(response.error, 'ui')
                    }
                    else {
                        let res = response.result;
                        let res_error = response.result_error;
                        let regression_icon = render_html_regression_icon(res);
                        _icon_success = `<i class="green check icon"></i>`;
                        for (let item of newList) {
                            let elem = document.getElementById('wp_upgraded_' + pWhat + '_' + pId + strGroup + '_' + item);
                            let parent = jQuery(elem).closest('tr');
                            if (res[item]) {
                                parent.find('td:last-child').html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Update successful.', 'mainwp') + '">' + _icon_success + '</span>' + regression_icon);
                            } else if ((what == 'plugin' || what == 'theme') && res_error[item]) {
                                let _error = res_error[item];
                                let _icon = '<i class="red times icon"></i>';
                                let roll_error = mainwp_updates_get_rollback_msg(_error);
                                if (roll_error) {
                                    _error = roll_error;
                                    _icon = mainwpParams.roll_ui_icon;
                                }
                                parent.find('td:last-child').html('<span data-inverted="" data-position="left center" data-tooltip="' + _error + '">' + _icon + '</span>');
                            } else {
                                parent.find('td:last-child').html('<i class="red times icon"></i>');
                            }
                        }
                        success = true;
                    }
                    if (!success) {
                        for (let item of newList) {
                            let elem = document.getElementById('wp_upgraded_' + pWhat + '_' + pId + strGroup + '_' + item);
                            let parent = jQuery(elem).closest('tr');
                            parent.find('td:last-child').html(extErr);
                        }
                    }
                }, 'json');

            }

            updatesoverviewContinueAfterBackup = undefined;
        }
    }(what, id, list, groupId);

    if (noCheck) {
        updatesoverviewContinueAfterBackup();
        return false;
    }

    let sitesToUpdate = [id];
    let siteNames = [];
    siteNames[id] = jQuery('tbody[site_id="' + id + '"]').attr('site_name');
    return mainwp_updatesoverview_checkBackups(sitesToUpdate, siteNames);
};

let updatesoverview_upgrade_plugintheme_list_popup = function (what, pId, pSiteName, list) {
    let updateCount = list.length;
    if (updateCount == 0)
        return;

    let updateWhat = what == 'theme' ? __('themes') : __('translations');
    updateWhat = (what == 'plugin') ? __('plugins') : updateWhat;

    mainwpPopup('#mainwp-sync-sites-modal').clearList();
    mainwpPopup('#mainwp-sync-sites-modal').appendItemsList(mainwp_links_visit_site_and_admin('', pId) + ' ' + updatesmanage_link_to_site(decodeURIComponent(pSiteName), pId) + ' (' + updateCount + ' ' + updateWhat + ')', '<span class="updatesoverview-upgrade-status-wp" siteid="' + pId + '">' + '<i class="clock outline icon"></i> ' + '</span>');

    let initData = {
        title: __('Updating all'),
        progressMax: 1
    };
    updatesoverview_update_popup_init(initData);
    let data = mainwp_secure_data({
        action: 'mainwp_upgradeplugintheme',
        websiteId: pId,
        type: what,
        slug: list.join(',')
    });

    updatesoverview_plugins_upgrade_all_update_site_status(pId, '<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) { // NOSONAR - complex.
        let res_error = response.result_error;
        let bulk_errors = [];
        let _icon = '<i class="red times icon"></i>';
        let hasError = false;

        if (response.error) {
            let extErr = getErrorMessageInfo(response.error, 'ui');
            updatesoverview_plugins_upgrade_all_update_site_status(pId, extErr);
            hasError = true;
        } else if (res_error) {
            for (let item of list) {
                if (res_error[item]) {
                    let _error = res_error[item];
                    let roll_error = mainwp_updates_get_rollback_msg(_error);
                    if (roll_error) {
                        _error = roll_error;
                        _icon = mainwpParams.roll_ui_icon;
                    }
                    bulk_errors.push(_error);
                    hasError = true;
                }
            }
        }

        if (bulk_errors.length) {
            jQuery('.updatesoverview-upgrade-status-wp[siteid="' + pId + '"]').html('<span class="mainwp-html-popup" data-position="left center" data-html="">' + _icon + '</span>');
            mainwp_init_html_popup('.updatesoverview-upgrade-status-wp[siteid="' + pId + '"] .mainwp-html-popup', bulk_errors.join('<br />'));
        }

        mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(1);
        if (!hasError) {
            let regression_icon = render_html_regression_icon(response.result);
            _icon = `<i class="green check icon"></i> ${regression_icon}`;

            updatesoverview_plugins_upgrade_all_update_site_status(pId, _icon);

            if (jQuery('.updates-regression-score-red-flag').length === 0) {
                setTimeout(function () {
                    mainwpPopup('#mainwp-sync-sites-modal').close();
                    window.location.href = location.href;
                }, 3000);
            }

        }
    }, 'json');
}

// for semantic ui checkboxes
jQuery(function () {
    mainwp_table_check_columns_init(); // call as function to support tables with ajax, may check and call at extensions
    mainwp_master_checkbox_init(jQuery);
});

let mainwp_master_checkbox_init = function ($) {
    // Master Checkboxes.
    $('.master-checkbox .master.checkbox').checkbox();
    $('.master-checkbox .master.checkbox').on('click', function (e) {
        if ($(this).checkbox('is checked')) {
            let $childCheckbox = $(this).closest('.master-checkbox').next('.child-checkbox').find('.checkbox');
            $childCheckbox.checkbox('check');
        } else {
            let $childCheckbox = $(this).closest('.master-checkbox').next('.child-checkbox').find('.checkbox');
            $childCheckbox.checkbox('uncheck');
        }
        e.stopPropagation();
    });
    // Child Checkboxes.
    $('.child-checkbox .child.checkbox')
        .checkbox(
            {
                // Fire on load to set parent value
                fireOnInit: true,
                // Change parent state on each child checkbox change
                onChange: function () {
                    let $listGroup = $(this).closest('.child-checkbox'),
                        $parentCheckbox = $listGroup.prev('.master-checkbox').find('.checkbox'),
                        $checkbox = $listGroup.find('.checkbox'),
                        allChecked = true,
                        allUnchecked = true
                        ;
                    // check to see if all other siblings are checked or unchecked
                    $checkbox.each(function () {
                        if ($(this).checkbox('is checked')) {
                            allUnchecked = false;
                        }
                        else {
                            allChecked = false;
                        }
                    });
                    // set parent checkbox state, but dont trigger its onChange callback
                    if (allChecked) {
                        $parentCheckbox.checkbox('set checked');
                    }
                    else if (allUnchecked) {
                        $parentCheckbox.checkbox('set unchecked');
                    }
                    else {
                        $parentCheckbox.checkbox('set indeterminate');
                    }
                }
            }
        );

    // Main Master Checkboxes.
    $('.main-master-checkbox .main-master.checkbox').checkbox();
    $('.main-master-checkbox .main-master.checkbox').on('click', function (e) {
        console.log('main-master click');
        if ($(this).checkbox('is checked')) {
            $(this).closest('.main-master-checkbox').next('.main-child-checkbox').find('.checkbox').checkbox('check');
            $(this).closest('.main-master-checkbox').find('.checkbox').checkbox('check');
        } else {
            $(this).closest('.main-master-checkbox').next('.main-child-checkbox').find('.checkbox').checkbox('uncheck');
            $(this).closest('.main-master-checkbox').find('.checkbox').checkbox('uncheck');
        }
        e.stopPropagation();
    });

}
// This function need to update when datatable changed it's style.
window.mainwp_table_check_columns_init = function (pTableSelector) {
    let tblSelect = pTableSelector ?? 'table';
    jQuery(document).find(tblSelect + ' th.check-column .checkbox').checkbox({ // table headers.
        // check all children
        onChecked: function () {
            console.log('parent checked.');
            let $table = jQuery(this).closest('table');
            if ($table.parent().parent().hasClass('dt-scroll-head') || $table.parent().parent().hasClass('dt-scroll-foot')) {
                $table = jQuery(this).closest('.dt-scroll'); // to compatible with datatable scroll
            }

            if ($table.length > 0) {
                let $childCheckbox = $table.find('td.check-column .checkbox');
                $childCheckbox.checkbox('check');
            }
        },
        // uncheck all children
        onUnchecked: function () {
            let $table = jQuery(this).closest('table');
            if ($table.parent().parent().hasClass('dt-scroll-head') || $table.parent().parent().hasClass('dt-scroll-foot')) {
                $table = jQuery(this).closest('.dt-scroll'); // to compatible with datatable scroll
            }
            if ($table.length > 0) {
                let $childCheckbox = $table.find('tbody td.check-column .checkbox');
                $childCheckbox.checkbox('uncheck');
            }
        }
    });

    jQuery(document).find('td.check-column .checkbox').checkbox({
        // Fire on load to set parent value
        fireOnInit: true,
        // Change parent state on each child checkbox change
        onChange: function () {
            console.log('child checked.');
            let $table = jQuery(this).closest('table');

            if ($table.parent().hasClass('dt-scroll-body'))
                $table = jQuery(this).closest('.dt-scroll'); // to compatible with datatable scroll

            let $parentCheckbox = $table.find('th.check-column .checkbox'),
                $checkbox = $table.find('td.check-column .checkbox'),
                allChecked = true,
                allUnchecked = true
                ;

            $checkbox.each(function () {
                if (jQuery(this).checkbox('is checked')) {
                    allUnchecked = false;
                }
                else {
                    allChecked = false;
                }
            });

            if (allChecked) {
                $parentCheckbox.checkbox('set checked');
            }
            else if (allUnchecked) {
                $parentCheckbox.checkbox('set unchecked');
            }
        }
    });
}

// Sync score icon.
const render_html_regression_sync_score_icon = function (score, change_score, website_id) {
    score = Number(score) || 0;
    change_score = Number(change_score) || 0;
    let icon_html = "";
    if (score <= change_score) {
        icon_html = '<i class="check green icon"></i>'; // Minimal change.
    } else {
        icon_html = '<i class="fire alternate red icon updates-regression-score-red-flag"></i>'; // Major differences.
    }

    if (icon_html !== "" && Number.isInteger(website_id)) {
        let msg = 'Change score changed. Click to review changes.';
        // eslint-disable-next-line no-constant-condition
        if (typeof mainwpTranslations) {
            msg = mainwpTranslations?.Change_score_changed_Click_to_review_changes || msg;
        }
        icon_html = `<a href="admin.php?page=ManageSitesHTMLRegression&id=${website_id}" target="_blank" data-tooltip="${msg}" data-inverted="" data-position="left center">${icon_html}</a>`;
    }

    return icon_html;
};

// Render Icon
const render_html_regression_icon = function (result) {
    let _icon = '';
    if (result && result.html_regression_max_scope && typeof result.html_regression_max_scope === 'object' && !Array.isArray(result.html_regression_max_scope) && Object.keys(result.html_regression_max_scope).length > 0) { // NOSONAR
        const regression_scope = result.html_regression_max_scope;
        _icon = render_html_regression_sync_score_icon(parseInt(regression_scope.change_score_current), parseInt(regression_scope.change_score), regression_scope.website_id);
    }

    return _icon;
}
