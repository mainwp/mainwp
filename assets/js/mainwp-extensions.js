/* eslint complexity: ["error", 100] */
jQuery(document).on('click', '.item.extension-inactive', function () {
    jQuery('#mainwp-install-extensions-promo-modal').modal('show');
    return false;
});

jQuery(document).on( 'click', '#mainwp-extensions-show-all', function () {
    jQuery(this).addClass('disabled');
    jQuery('#mainwp-extensions-show-extensions').removeClass('disabled');
    jQuery('#mainwp-extensions-show-integrations').removeClass('disabled');
    jQuery('#mainwp-extensions-list').find('.card[extension-model="integration"]').fadeIn(200);
    jQuery('#mainwp-extensions-list').find('.card[extension-model="extension"]').fadeIn(200);
});

jQuery(document).on( 'click', '#mainwp-extensions-show-extensions', function () {
    jQuery(this).addClass('disabled');
    jQuery('#mainwp-extensions-show-all').removeClass('disabled');
    jQuery('#mainwp-extensions-show-integrations').removeClass('disabled');
    jQuery('#mainwp-extensions-list').find('.card[extension-model="integration"]').fadeOut(200);
    jQuery('#mainwp-extensions-list').find('.card[extension-model="extension"]').fadeIn(200);
});

jQuery(document).on( 'click', '#mainwp-extensions-show-integrations', function () {
    jQuery(this).addClass('disabled');
    jQuery('#mainwp-extensions-show-extensions').removeClass('disabled');
    jQuery('#mainwp-extensions-show-all').removeClass('disabled');
    jQuery('#mainwp-extensions-list').find('.card[extension-model="integration"]').fadeIn(200);
    jQuery('#mainwp-extensions-list').find('.card[extension-model="extension"]').fadeOut(200);
});

jQuery(document).on('click', '.mainwp-extensions-add-menu', function () {
    let extensionSlug = jQuery(this).parents('.plugin-card').attr('extension_slug');
    let data = mainwp_secure_data({
        action: 'mainwp_extension_add_menu',
        slug: extensionSlug
    });

    jQuery.post(ajaxurl, data, function (response) {
        if (response.result == 'SUCCESS')
            location.reload();
        else if (response.error) {
            alert(response.error);
        }
    }, 'json');

    return false;
});

jQuery(document).on('click', '.mainwp-extensions-remove-menu', function () {
    let extensionSlug = jQuery(this).parents('.plugin-card').attr('extension_slug');
    let data = mainwp_secure_data({
        action: 'mainwp_extension_remove_menu',
        slug: extensionSlug
    });

    jQuery.post(ajaxurl, data, function (response) {
        if (response.result == 'SUCCESS')
            location.reload();
    }, 'json');

    return false;
});

jQuery(function () {
    jQuery(document).on('click', '.mainwp-manage-extension-license', function () {
        let currentCard = jQuery(this).closest(".card");
        currentCard.find("#mainwp-extensions-api-form").toggle();
        if (jQuery(this).attr('api-actived') == '0') {
            extensions_activate_next(currentCard, false);
        }
        return false;
    });

    jQuery(document).on('click', '.extension-privacy-info-link', function () {
        let slug = jQuery(this).attr('base-slug');
        let title = '';
        if (jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").length > 0) {
            title = jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").attr('extension_title');
        } else {
            title = jQuery(this).closest('.ui.card').attr('extension-title');
        }
        let privacy = jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").attr('privacy');
        let integration = jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").attr('integration');
        let integration_url = jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").attr('integration_url');
        let integration_owner = jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").attr('integration_owner');
        let integration_owner_pp = jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").attr('integration_owner_pp');

        jQuery('#mainwp-privacy-info-modal').modal({
            allowMultiple: true,
            onShow: function () {
                jQuery('#mainwp-privacy-info-modal').find('.header').html(title + ' Privacy Info');
                if (0 == privacy) {
                    jQuery('#mainwp-privacy-info-modal').find('.content').html('Standalone Add-on. This Add-on does not use any 3rd party plugins or API\'s to integrate with your Dashboard. This add-on falls under the <a href="https://mainwp.com/mainwp-plugin-privacy-policy/" target="_blank">MainWP Plugin Privacy Policy</a>.'); // NOSONAR - noopener - open safe.
                } else if (1 == privacy) {
                    if (slug == 'advanced-uptime-monitor-extension') {
                        jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>Add-on integrates with a 3rd party API.</strong>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="' + integration_url + '" target="_blank">' + integration + '</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="' + integration_owner_pp + '" target="_blank">' + integration_owner + '</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="https://betteruptime.com/" target="_blank">Better Uptime API</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="https://betterstack.com/privacy" target="_blank">Better Stack, Inc.</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="https://nodeping.com" target="_blank">NodePing API</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="https://nodeping.com/privacy.html" target="_blank">NodePing LLC</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="https://www.site24x7.com/" target="_blank">Site24x7 API</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="https://www.zoho.com/privacy.html" target="_blank">Zoho Corporation Pvt. Ltd.</a>');
                    } else if (slug == 'mainwp-vulnerability-checker-extension') {
                        jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>Add-on integrates with a 3rd party API.</strong>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="' + integration_url + '" target="_blank">' + integration + '</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="' + integration_owner_pp + '" target="_blank">' + integration_owner + '</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="https://nvd.nist.gov/" target="_blank">NVD NIST API</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="https://www.nist.gov/privacy-policy" target="_blank">National Institute of Standards and Technology</a>');
                    } else if (slug == 'mainwp-api-backups-extension') {
                        jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>Add-on integrates with a 3rd party API.</strong>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="' + integration_url + '" target="_blank">' + integration + '</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="' + integration_owner_pp + '" target="_blank">' + integration_owner + '</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="https://gridpane.com/" target="_blank">GridPane API</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="https://gridpane.com/privacy/" target="_blank">GridPane Inc.</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="https://www.digitalocean.com/" target="_blank">Digital Ocean API</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="https://www.digitalocean.com/legal/privacy-policy" target="_blank">DigitalOcean, LLC.</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="https://www.linode.com/" target="_blank">Linode API</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="https://www.linode.com/legal-privacy/" target="_blank">Akamai Technologies</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="https://www.vultr.com/" target="_blank">Vultr API</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="https://www.vultr.com/legal/privacy/" target="_blank">Constant Company, LLC.</a>');
                    } else {
                        jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>Add-on integrates with a 3rd party API.</strong>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="' + integration_url + '" target="_blank">' + integration + '</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="' + integration_owner_pp + '" target="_blank">' + integration_owner + '</a>');
                    }
                } else if (2 == privacy) {
                    jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>Add-on integrates with a 3rd party Plugin.</strong>');
                    jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                    jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="' + integration_url + '" target="_blank">' + integration + '</a>');
                    jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                    jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="' + integration_owner_pp + '" target="_blank">' + integration_owner + '</a>');
                } else if (slug == 'mainwp-page-speed-extension') {
                    jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>Add-on integrates with a 3rd party Plugin.</strong>');
                    jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                    jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="https://wordpress.org/plugins/google-pagespeed-insights/" target="_blank">Insights from Google PageSpeed</a>');
                    jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                    jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="https://mattkeys.me/" target="_blank">Matt Keys</a>');
                } else {
                    jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>This add-on is not developed by MainWP. Privacy info is not available.</strong>');
                }
            },
            onHide: function () {
                jQuery('#mainwp-privacy-info-modal').find('.header').html('');
                jQuery('#mainwp-privacy-info-modal').find('.content').html('');
            },
        }).modal('show');

        return false;
    });

    jQuery(document).on('click', '.extension-the-plugin-action', function () {
        let parent = jQuery(this).closest(".card");
        let slug = jQuery(parent).attr('extension-slug');
        let loadingEl = parent.find(".action-feedback");
        let whatAct = jQuery(this).attr("plugin-action");

        loadingEl.show();
        loadingEl.find('.message').removeClass('red green');

        let msg = __('Deactivating...');
        if (whatAct == 'active') {
            msg = __('Activating...');
        } else if (whatAct == 'remove') {
            msg = __('Removing...');
        }

        loadingEl.find('.message').html('<i class="notched circle loading icon"></i> ' + msg);

        let data = mainwp_secure_data({
            action: 'mainwp_extension_plugin_action',
            slug: slug,
            what: whatAct,
        });

        jQuery(this).attr('disabled', true);
        jQuery.post(ajaxurl, data, function (response) {
            jQuery(this).attr('disabled', false);
            let success = false;
            if (response) {
                if (response.result == 'SUCCESS') {
                    loadingEl.find('.message').addClass('green');
                    msg = __('Add-on deactivated.');
                    if (whatAct == 'active') {
                        msg = __('Add-on activated.');
                    } else if (whatAct == 'remove') {
                        msg = __('Add-on removed.');
                    }
                    loadingEl.find('.message').html(msg);
                    success = true;
                } else if (response.error) {
                    loadingEl.find('.message').addClass('red');
                    loadingEl.find('.message').html(response.error);
                } else {
                    loadingEl.find('.message').addClass('red');
                    loadingEl.find('.message').html(__('Undefined error. '));
                }
            } else {
                loadingEl.find('.message').addClass('red');
                loadingEl.find('.message').html(__('Undefined error. '));
            }

            if (success) {
                setTimeout(function () {
                    location.href = 'admin.php?page=Extensions';
                }, 2000);
            }
        }, 'json');
        return false;
    });
});

jQuery(document).on('click', '.mainwp-extensions-activate', function () {
    mainwp_extensions_activate(this, false);
});

function mainwp_extensions_activate(pObj, retring) {
    let apiEl = jQuery(pObj).closest(".card");
    let statusEl = apiEl.find(".activate-api-status");
    let loadingEl = apiEl.find(".api-feedback");

    loadingEl.hide();

    if (jQuery(pObj).attr('license-status') == 'activated') {
        loadingEl.show();
        loadingEl.find('.message').html(__('Already activated.'));
        return;
    }

    let data = false;

    if (retring) {
        loadingEl.show();
        loadingEl.find('.message').html(__('Connection error detected. The Verify Certificate option has been switched to NO. Retrying...'));
    } else {
        let extensionSlug = jQuery(apiEl).attr('extension-slug');
        let key = apiEl.find('input[type="text"].extension-api-key').val();
        let email = apiEl.find('input[type="text"].extension-api-email').val();

        if (key == '')
            return;

        data = mainwp_secure_data({
            action: 'mainwp_extension_api_activate',
            slug: extensionSlug,
            key: key,
            email: email
        });
    }

    loadingEl.show();
    loadingEl.find('.message').removeClass('red green');
    loadingEl.find('.message').html('<i class="notched circle loading icon"></i>' + __('Activating...'));

    jQuery.post(ajaxurl, data, function (response) {

        let success = false;

        if (response) {
            if (response.result == 'SUCCESS') {
                loadingEl.find('.message').addClass('green');
                loadingEl.find('.message').html(__('License activated. '));
                statusEl.html('<i class="green check icon"></i> License');
                success = true;
            } else if (response.error) {
                loadingEl.find('.message').addClass('red');
                loadingEl.find('.message').html(response.error);
            } else if (response.retry_action && response.retry_action == 1) {
                jQuery("#mainwp_api_sslVerifyCertificate").val(0);
                mainwp_extensions_activate(pObj, true);
                return false;
            } else {
                loadingEl.find('.message').addClass('red');
                loadingEl.find('.message').html(__('Undefined error. '));
            }
        } else {
            loadingEl.find('.message').addClass('red');
            loadingEl.find('.message').html(__('Undefined error. '));
        }

        if (success) {
            setTimeout(function () {
                location.href = 'admin.php?page=Extensions';
            }, 2000);
        }
    }, 'json');
    return false;
}

jQuery(document).on('click', '.mainwp-extensions-deactivate', function () {
    let apiEl = jQuery(this).closest(".card");
    let statusEl = apiEl.find(".activate-api-status");
    let loadingEl = apiEl.find(".api-feedback");

    if (!apiEl.find('.mainwp-extensions-deactivate-chkbox').is(':checked'))
        return;

    loadingEl.hide();

    let extensionSlug = jQuery(apiEl).attr('extension-slug');
    let extensionApiKey = jQuery(apiEl).find('.extension-api-key').val();
    let data = mainwp_secure_data({
        action: 'mainwp_extension_deactivate',
        slug: extensionSlug,
        api_key: extensionApiKey
    });

    loadingEl.show();
    loadingEl.find('.message').removeClass('red green');
    loadingEl.find('.message').html('<i class="notched circle loading icon"></i>' + __('Deactivating...'));

    jQuery.post(ajaxurl, data, function (response) {

        if (response) {
            if (response.result == 'SUCCESS') {
                loadingEl.find('.message').addClass('green');
                loadingEl.find('.message').html(__('License deactivated.'));
                statusEl.html('<i class="green check icon"></i> License');
            } else if (response.error) {
                loadingEl.find('.message').addClass('red');
                loadingEl.find('.message').html(response.error);
            } else {
                loadingEl.find('.message').addClass('red');
                loadingEl.find('.message').html(__('Undefined error. '));
            }
        } else {
            loadingEl.find('.message').addClass('red');
            loadingEl.find('.message').html(__('Undefined error. '));
        }

        setTimeout(function () {
            location.href = 'admin.php?page=Extensions';
        }, 2000);
    }, 'json');
});


// Verify mainwp.com login credentials
jQuery(document).on('click', '#mainwp-extensions-savelogin', function () {
    mainwp_extensions_savelogin(this, false);
});

function mainwp_extensions_savelogin(pObj, retring) {
    let grabingEl = jQuery("#mainwp-extensions-api-fields");
    let api_key = grabingEl.find('#mainwp_com_api_key').val();
    let statusEl = jQuery(".mainwp-extensions-api-loading");
    let data = mainwp_secure_data({
        action: 'mainwp_extension_saveextensionapilogin',
        api_key: api_key,
        saveLogin: jQuery('#extensions_api_savemylogin_chk').is(':checked') ? 1 : 0
    });

    if (retring) {
        statusEl.find('.text').html(__("Connection error detected. The Verify Certificate option has been switched to NO. Retrying...")).fadeIn();
    } else {

        statusEl.find('.text').html(__('Validating...'));
    }

    statusEl.show();

    jQuery.post(ajaxurl, data, function (response) {
        let undefError = false;
        if (response) {
            if (response.saved) {
                statusEl.find('.text').html('Your API license key has been successfully saved!');
            } else if (response.result == 'SUCCESS') {
                statusEl.find('.text').html('API license key verification successful!');
            } else if (response.error) {
                statusEl.find('.text').html(response.error);
            } else if (response.retry_action && response.retry_action == 1) {
                jQuery("#mainwp_api_sslVerifyCertificate").val(0);
                statusEl.fadeOut();
                mainwp_extensions_savelogin(pObj, true);
                return false;
            } else {
                undefError = true;
            }
        } else {
            undefError = true;
        }

        if (undefError) {
            statusEl.find('.text').html(__('Undefined error. Please try again.'));
        }
        setTimeout(function () {
            statusEl.fadeOut();
        }, 3000);

    }, 'json');
    return false;
}

let maxActivateThreads = 8;
let totalActivateThreads = 0;
let currentActivateThreads = 0;
let finishedActivateThreads = 0;
let countSuccessActivation = 0;

// Bulk grab API keys
jQuery(document).on('click', '#mainwp-extensions-grabkeys', function () {
    mainwp_extensions_grabkeys(false);
});

function mainwp_extensions_grabkeys(retring) {
    let grabingEl = jQuery("#mainwp-extensions-api-fields");
    let master_api_key = grabingEl.find('#mainwp_com_api_key').val();
    let statusEl = jQuery(".mainwp-extensions-api-loading");
    let data = mainwp_secure_data({
        action: 'mainwp_extension_testextensionapilogin',
        master_api_key: master_api_key
    });

    if (master_api_key == '') {
        statusEl.find('.text').html(__("Main API Key is required."));
        statusEl.addClass('yellow');
        statusEl.fadeOut();
        setTimeout(function () {
            statusEl.fadeOut();
        }, 3000);
    } else {
        if (retring) {
            statusEl.find('.text').html(__("Connection error detected. The Verify Certificate option has been switched to NO. Retrying...")).fadeIn();
        } else {
            statusEl.removeClass('red');
            statusEl.removeClass('yellow');
            statusEl.removeClass('green');
            statusEl.find('.text').html(__('Validating. Please wait...')).show();
        }
        statusEl.show();
        jQuery.post(ajaxurl, data, function (response) {
            let undefError = false;
            if (response) {
                if (response.result == 'SUCCESS') {
                    statusEl.addClass('green');
                    statusEl.find('.text').html('MainWP Main API Key is valid!').fadeIn();
                    setTimeout(function () {
                        statusEl.fadeOut();
                    }, 3000);
                    totalActivateThreads = jQuery('#mainwp-extensions-list .card[status="queue"]').length;
                    console.log(totalActivateThreads);
                    if (totalActivateThreads > 0)
                        extensions_loop_next();
                } else if (response.error) {
                    statusEl.addClass('red');
                    statusEl.find('.text').html(response.error).fadeIn();
                } else if (response.retry_action && response.retry_action == 1) {
                    jQuery("#mainwp_api_sslVerifyCertificate").val(0);
                    mainwp_extensions_grabkeys(true);
                    return false;
                } else {
                    undefError = true;
                }
            } else {
                undefError = true;
            }
            if (undefError) {
                statusEl.addClass('red');
                statusEl.find('.text').html(__('Undefined error. Please try again.')).fadeIn();
            }

            setTimeout(function () {
                statusEl.fadeOut();
            }, 3000);
        }, 'json');
    }
    return false;
}

let extensions_loop_next = function () {
    let extToActivate = jQuery('#mainwp-extensions-list .card[status="queue"]:first');
    while (extToActivate.length > 0 && currentActivateThreads < maxActivateThreads) { // NOSONAR - variable modified outside the function.
        extensions_activate_next(extToActivate, true);
        extToActivate = jQuery('#mainwp-extensions-list .card[status="queue"]:first');
    }
    if ((finishedActivateThreads == totalActivateThreads) && (countSuccessActivation == totalActivateThreads)) {
        setTimeout(function () {
            location.href = 'admin.php?page=Extensions';
        }, 2000);
    }
};

let extensions_activate_next = function (pObj, bulkAct) {
    pObj.attr("status", "running");

    let grabingEl = jQuery("#mainwp-extensions-api-fields");
    let apiEl = pObj;
    let statusEl = apiEl.find(".activate-api-status");
    let loadingEl = apiEl.find(".api-feedback");

    let master_api_key = grabingEl.find('#mainwp_com_api_key').val();


    let extensionSlug = apiEl.attr('extension-slug');
    let data = mainwp_secure_data({
        action: 'mainwp_extension_grabapikey',
        master_api_key: master_api_key,
        slug: extensionSlug
    });

    currentActivateThreads++;

    loadingEl.show();
    loadingEl.find('.message').removeClass('red green');
    loadingEl.find('.message').html('<i class="notched circle loading icon"></i>' + __('Activating...'));

    if (apiEl.attr('license-status') == 'activated') {
        finishedActivateThreads++;
        currentActivateThreads--;
        loadingEl.find('.message').addClass('green');
        loadingEl.find('.message').html(__('Already activated.'));
        countSuccessActivation++;
        if (bulkAct) {
            extensions_loop_next();
        }
        return;
    }

    jQuery.post(ajaxurl, data, function (response) {
        finishedActivateThreads++;
        currentActivateThreads--;

        if (response) {
            if (response.result == 'SUCCESS') {
                countSuccessActivation++;
                loadingEl.find('.message').addClass('green');
                loadingEl.find('.message').html(__('License activated.'));
                statusEl.html('<i class="green check icon"></i> License');
                apiEl.find('.mainwp-extensions-deactivate-chkbox').attr('checked', false);
                if (!bulkAct) {
                    setTimeout(function () {
                        location.href = 'admin.php?page=Extensions';
                    }, 2000);
                }
            } else if (response.error) {
                loadingEl.find('.message').addClass('red');
                loadingEl.find('.message').html(response.error);
            } else {
                loadingEl.find('.message').addClass('red');
                loadingEl.find('.message').html(__('Undefined error. '));
            }
        } else {
            loadingEl.find('.message').addClass('red');
            loadingEl.find('.message').html(__('Undefined error. '));
        }
        if (bulkAct) {
            extensions_loop_next();
        }
    }, 'json');
};

jQuery(document).on('click', '#mainwp-extensions-bulkinstall', function () {
    let grabingEl = jQuery("#mainwp-extensions-api-fields");
    let api_key = grabingEl.find('#mainwp_com_api_key').val().trim();
    if (api_key == '') {
        mainwp_extension_grab_org_extensions();
    } else {
        mainwp_extension_grab_purchased(false);
    }
})

let mainwp_extension_grab_purchased = function (retring) {

    let grabingEl = jQuery("#mainwp-extensions-api-fields");
    let api_key = grabingEl.find('#mainwp_com_api_key').val().trim();

    let statusEl = jQuery(".mainwp-extensions-api-loading");
    let data = mainwp_secure_data({
        action: 'mainwp_extension_getpurchased',
        api_key: api_key
    });


    if (api_key == '') {
        statusEl.find('.text').html(__("Main API Key is required."));
        statusEl.show();
        setTimeout(function () {
            statusEl.fadeOut();
        }, 3000);
    } else if (retring) {
        statusEl.find('.text').html(__("Connection error detected. The Verify Certificate option has been switched to NO. Retrying..."));
        setTimeout(function () {
            statusEl.fadeOut();
        }, 3000);
    } else {
        statusEl.show();
        statusEl.find('.text').html(__('Loading add-ons info...'));
        jQuery.post(ajaxurl, data, function (response) {
            let undefError = false;
            if (response) {
                if (response.result == 'SUCCESS') {
                    statusEl.hide();
                    jQuery('#mainwp-get-purchased-extensions-modal').modal({
                        allowMultiple: true,
                        closable: false,
                        onHide: function () {
                            location.href = 'admin.php?page=Extensions';
                        }
                    }).modal('show');
                    jQuery('#mainwp-get-purchased-extensions-modal').find('.content').html(response.data);
                    if (jQuery('#extension_install_ext_slug').length > 0) {
                        mainwp_extension_select_to_install();
                    }
                } else if (response.error) {
                    statusEl.find('.text').html(response.error);
                } else if (response.retry_action && response.retry_action == 1) {
                    jQuery("#mainwp_api_sslVerifyCertificate").val(0);
                    statusEl.fadeOut();
                    mainwp_extension_grab_purchased(true);
                    return false;
                } else {
                    undefError = true;
                }
            } else {
                undefError = true;
            }
            if (undefError) {
                statusEl.find('.text').html(__('Undefined error occurred. Please try again.'));
            }
            setTimeout(function () {
                statusEl.fadeOut();
            }, 3000);
        }, 'json');
    }
    return false;
}

let mainwp_extension_select_to_install = function () {
    let inst_ext = jQuery('.item.extension[slug="' + jQuery('#extension_install_ext_slug').val() + '"]');
    console.log(inst_ext);
    if (jQuery(inst_ext).length > 0) {
        jQuery('mainwp-installing-extensions .ui.tab').removeClass('active');
        let curtab = jQuery(inst_ext[0]).closest('.ui.tab').attr('data-tab');
        jQuery('#mainwp-install-extensions-menu .item[data-tab=' + curtab + ']').trigger('click');
        let install_chk = jQuery(inst_ext).find('input[type=checkbox]');
        if (install_chk.length > 0) {
            jQuery(install_chk[0]).trigger('click');
        }
    }
}

let mainwp_extension_grab_org_extensions = function () {

    let statusEl = jQuery(".mainwp-extensions-api-loading");
    let data = mainwp_secure_data({
        action: 'mainwp_extension_getpurchased',
    });

    statusEl.removeClass('green');
    statusEl.removeClass('yellow');
    statusEl.show();
    statusEl.find('.text').html(__('Running. Please wait...'));
    jQuery.post(ajaxurl, data, function (response) {
        let undefError = false;
        if (response) {
            if (response.result == 'SUCCESS') {
                jQuery('#mainwp-get-purchased-extensions-modal').modal({
                    allowMultiple: true,
                    closable: false,
                    onHide: function () {
                        location.href = 'admin.php?page=Extensions';
                    }
                }).modal('show');
                jQuery('#mainwp-get-purchased-extensions-modal').find('.content').html(response.data);
                if (jQuery('#extension_install_ext_slug').length > 0) {
                    mainwp_extension_select_to_install();
                }
                statusEl.hide();
            } else if (response.error) {
                statusEl.addClass('red');
                statusEl.find('.text').html(response.error).fadeIn();
            } else {
                undefError = true;
            }
        } else {
            undefError = true;
        }

        if (undefError) {
            statusEl.addClass('red');
            statusEl.find('.text').html(__('Undefined error. Please try again.')).fadeIn();
        }
        setTimeout(function () {
            statusEl.fadeOut();
        }, 3000);
    }, 'json');

    return false;
}


jQuery(document).on('click', '#mainwp-extensions-installnow', function () {
    mainwp_extension_bulk_install();
    return false;
})

let bulkExtensionsMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
let bulkExtensionsCurrentThreads = 0;
let bulkExtensionsTotal = 0;
let bulkExtensionsFinished = 0;
let bulkExtensionsRunning = false;

let mainwp_extension_bulk_install = function () {
    if (bulkExtensionsRunning)
        return;

    jQuery('.mainwp-installing-extensions input[type="checkbox"][status="queue"]:not(:checked)').closest('.extension-to-install').find('.installing-extension[status="queue"]').html('<span data-tooltip="Skipped" data-position="left center" data-inverted=""><i class="stop circle outline grey icon"></i></span>');

    bulkExtensionsTotal = jQuery('.mainwp-installing-extensions input[type="checkbox"][status="queue"]:checked').length;

    if (bulkExtensionsTotal == 0)
        return false;

    jQuery('.mainwp-installing-extensions input[type="checkbox"][status="queue"]:checked').closest('.extension-to-install').find('.installing-extension[status="queue"]').html('<i class="clock outline icon"></i> ' + __('Queued'));

    mainwp_extension_bulk_install_next();
}

let mainwp_extension_bulk_install_next = function () {
    while ((extToInstall = jQuery('.mainwp-installing-extensions input[type="checkbox"][status="queue"]:checked:first').closest('.extension-to-install')) && (extToInstall.length > 0) && (bulkExtensionsCurrentThreads < bulkExtensionsMaxThreads)) { // NOSONAR -- modified out side the function.
        mainwp_extension_bulk_install_specific(extToInstall);
    }

    if ((bulkExtensionsTotal > 0) && (bulkExtensionsFinished == bulkExtensionsTotal)) { // NOSONAR -- modified out side the function.
        mainwp_extension_bulk_activate();
    }
}

let mainwp_extension_bulk_install_specific = function (pExtToInstall) {
    bulkExtensionsRunning = true;
    pExtToInstall.find('input[type="checkbox"]').attr('status', 'running');
    bulkExtensionsCurrentThreads++;

    let statusEl = pExtToInstall.find('.installing-extension');

    statusEl.html('<span data-tooltip="Installing add-on. Please wait..." data-position="left center" data-inverted=""><i class="notched circle loading icon"></i></span>');

    let data = mainwp_secure_data({
        action: 'mainwp_extension_downloadandinstall',
        download_link: pExtToInstall.attr('download-link'),
        plugin_slug: pExtToInstall.attr('plugin-slug')
    });

    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function () {
            return function (res_data) {
                bulkExtensionsCurrentThreads--;
                bulkExtensionsFinished++;

                statusEl.html('');

                let matches = res_data.match('<mainwp>(.*)</mainwp>');
                let response = '';

                if (matches) {
                    let response_json = matches[1];
                    response = JSON.parse(response_json);
                }

                if (response != '') {
                    if (response.result == 'SUCCESS') {
                        statusEl.html('<span data-tooltip="' + response.output + '" data-inverted="" data-position="left center"><i class="check green icon"></i></span>');
                        jQuery('.mainwp-installing-extensions').append('<span class="extension-installed-success" slug="' + response.slug + '"></span>')
                    } else if (response.error) {
                        statusEl.html('<span data-tooltip="' + response.error + '" data-inverted="" data-position="left center"><i class="times red icon"></i></span>');
                    } else {
                        statusEl.html('<span data-tooltip="Undefined error occured. Please try again." data-inverted="" data-position="left center"><i class="times red icon"></i></span>');
                    }
                } else {
                    statusEl.html('<span data-tooltip="Undefined error occured. Please try again." data-inverted="" data-position="left center"><i class="times red icon"></i></span>');
                }

                mainwp_extension_bulk_install_next();
            }
        }()
    });

    return false;
}

let mainwp_extension_bulk_activate = function () {
    let plugins = [];

    jQuery('.extension-installed-success').each(function () {
        plugins.push(jQuery(this).attr('slug'));
    });

    if (plugins.length == 0) {
        mainwp_extension_bulk_install_done();
        return;
    }

    let data = mainwp_secure_data({
        action: 'mainwp_extension_bulk_activate',
        plugins: plugins
    });

    let statusEl = jQuery('#mainwp-bulk-activating-extensions-status');

    statusEl.html('<i class="notched circle loading icon"></i>' + __('Activating add-ons. Please wait...')).show();
    jQuery.post(ajaxurl, data, function (response) {
        statusEl.html('');
        if (response == 'SUCCESS') {
            statusEl.addClass('green');
            statusEl.html(__('Add-ons have been activated successfully!'));
            statusEl.fadeOut(3000);
        }
        mainwp_extension_bulk_install_done();
    });
}

let mainwp_extension_bulk_install_done = function () {
    bulkExtensionsRunning = false;

    let statusEl = jQuery('#mainwp-bulk-activating-extensions-status');

    statusEl.addClass('green');
    statusEl.html(__("Installation completed successfully. Page will reload automatically in 3 seconds.")).show();
    if (jQuery('.extension-installed-success').length == bulkExtensionsFinished) {
        setTimeout(function () {
            location.href = 'admin.php?page=Extensions';
        }, 3000);
    }
}


// Is this function still in use???
jQuery(document).on('click', '#mainwp-extensions-api-sslverify-certificate', function () {

    let parent = jQuery(this).closest(".extension_api_sslverify_loading");
    let statusEl = parent.find('span.status');
    let loadingEl = parent.find("i");

    let data = mainwp_secure_data({
        action: 'mainwp_extension_apisslverifycertificate',
        api_sslverify: jQuery("#mainwp_api_sslVerifyCertificate").val()
    });

    statusEl.hide();
    loadingEl.show();
    jQuery.post(ajaxurl, data, function (response) {
        loadingEl.hide();
        let undefError = false;
        if (response) {
            if (response.saved) {
                statusEl.css('color', '#0074a2');
                statusEl.html('MainWP Main API Key saved!').fadeIn();
                setTimeout(function () {
                    statusEl.fadeOut();
                }, 3000);
            } else if (response.error) {
                statusEl.css('color', 'red');
                statusEl.html(response.error).fadeIn();
            } else {
                undefError = true;
            }
        } else {
            undefError = true;
        }

        if (undefError) {
            statusEl.css('color', 'red');
            statusEl.html('<i class="exclamation circle icon"></i> Undefined error!').fadeIn();
        }
    }, 'json');
    return false;
});

jQuery(function ($) {
    jQuery(document).on('click', '#mainwp-check-all-sync-ext', function () {
        $('.sync-ext-row').find("input:checkbox").each(function () {
            $(this).attr('checked', true);
        });
    });
    jQuery(document).on('click', '#mainwp-uncheck-all-sync-ext', function () {
        $('.sync-ext-row').find("input:checkbox").each(function () {
            $(this).attr('checked', false);
        });
    });

    jQuery(document).on('click', '.mainwp-show-extensions', function () {
        $('a.mainwp-show-extensions').removeClass('mainwp_action_down');
        $(this).addClass('mainwp_action_down');

        let gr = $(this).attr('group');
        let selectedEl = $('#mainwp-available-extensions-list .mainwp-availbale-extension-holder.group-' + gr);
        let installedGroup = $('.installed-group-exts');
        installedGroup.hide();

        if (gr == 'all') {
            $('#mainwp-available-extensions-list .mainwp-availbale-extension-holder').fadeIn(500);
        } else {
            $('#mainwp-available-extensions-list .mainwp-availbale-extension-holder').hide();
            if (selectedEl.length > 0) {
                selectedEl.fadeIn(500);
            } else {
                installedGroup.fadeIn(500);
            }
        }

        return false;
    });
});
