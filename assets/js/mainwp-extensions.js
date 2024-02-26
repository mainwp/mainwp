/* eslint complexity: ["error", 100] */
jQuery(document).on('click', '.item.extension-inactive', function () {
    jQuery('#mainwp-install-extensions-promo-modal').modal('show');
    return false;
} );

jQuery(document).on('click', '.mainwp-extensions-add-menu', function () {
    var extensionSlug = jQuery(this).parents('.plugin-card').attr('extension_slug');
    var data = mainwp_secure_data({
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
    var extensionSlug = jQuery(this).parents('.plugin-card').attr('extension_slug');
    var data = mainwp_secure_data({
        action: 'mainwp_extension_remove_menu',
        slug: extensionSlug
    });

    jQuery.post(ajaxurl, data, function (response) {
        if (response.result == 'SUCCESS')
            location.reload();
    }, 'json');

    return false;
});

jQuery(document).ready(function () {
    jQuery(document).on('click', '.mainwp-manage-extension-license', function () {
        var currentCard = jQuery(this).closest(".card");
        currentCard.find("#mainwp-extensions-api-form").toggle();
        if (jQuery(this).attr('api-actived') == '0') {
            extensions_activate_next(currentCard, false);
        }
        return false;
    });

    jQuery(document).on('click', '.extension-privacy-info-link', function () {
        var slug = jQuery(this).attr('base-slug');
        var title = '';
        if (jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").length > 0) {
            title = jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").attr('extension_title');
        } else {
            title = jQuery(this).closest('.ui.card').attr('extension-title');
        }
        var privacy = jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").attr('privacy');
        var integration = jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").attr('integration');
        var integration_url = jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").attr('integration_url');
        var integration_owner = jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").attr('integration_owner');
        var integration_owner_pp = jQuery('#mainwp-extensions-privacy-info').find("input[base-slug='" + slug + "']").attr('integration_owner_pp');

        jQuery('#mainwp-privacy-info-modal').modal({
            allowMultiple: true,
            onShow: function () {
                jQuery('#mainwp-privacy-info-modal').find('.header').html(title + ' Privacy Info');
                if (0 == privacy) {
                    jQuery('#mainwp-privacy-info-modal').find('.content').html('Standalone Extension. This Extension does not use any 3rd party plugins or API\'s to integrate with your Dashboard. This extension falls under the <a href="https://mainwp.com/mainwp-plugin-privacy-policy/" target="_blank">MainWP Plugin Privacy Policy</a>.');
                } else if (1 == privacy) {
                    if (slug == 'advanced-uptime-monitor-extension') {
                        jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>Extension integrates with a 3rd party API.</strong>');
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
                        jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>Extension integrates with a 3rd party API.</strong>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="' + integration_url + '" target="_blank">' + integration + '</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="' + integration_owner_pp + '" target="_blank">' + integration_owner + '</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="https://nvd.nist.gov/" target="_blank">NVD NIST API</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="https://www.nist.gov/privacy-policy" target="_blank">National Institute of Standards and Technology</a>');
                    } else if (slug == 'mainwp-api-backups-extension') {
                        jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>Extension integrates with a 3rd party API.</strong>');
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
                        jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>Extension integrates with a 3rd party API.</strong>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="' + integration_url + '" target="_blank">' + integration + '</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="' + integration_owner_pp + '" target="_blank">' + integration_owner + '</a>');
                    }
                } else if (2 == privacy) {
                    jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>Extension integrates with a 3rd party Plugin.</strong>');
                    jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                    jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="' + integration_url + '" target="_blank">' + integration + '</a>');
                    jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                    jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="' + integration_owner_pp + '" target="_blank">' + integration_owner + '</a>');
                } else {
                    if (slug == 'mainwp-page-speed-extension') {
                        jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>Extension integrates with a 3rd party Plugin.</strong>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<div class="ui hidden divider"></div>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Integrates with: <a href="https://wordpress.org/plugins/google-pagespeed-insights/" target="_blank">Insights from Google PageSpeed</a>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('<br/>');
                        jQuery('#mainwp-privacy-info-modal').find('.content').append('Owned by: <a href="https://mattkeys.me/" target="_blank">Matt Keys</a>');
                    } else {
                        jQuery('#mainwp-privacy-info-modal').find('.content').html('<strong>This extension is not developed by MainWP. Privacy info is not available.</strong>');
                    }
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
        var parent = jQuery(this).closest(".card");
        var slug = jQuery(parent).attr('extension-slug');
        var loadingEl = parent.find(".action-feedback");
        var whatAct = jQuery(this).attr("plugin-action");

        loadingEl.show();
        loadingEl.find('.message').removeClass('red green');

        var msg = __('Deactivating...');
        if (whatAct == 'active') {
            msg = __('Activating...');
        } else if (whatAct == 'remove') {
            msg = __('Removing...');
        }

        loadingEl.find('.message').html('<i class="notched circle loading icon"></i> ' + msg);

        var data = mainwp_secure_data({
            action: 'mainwp_extension_plugin_action',
            slug: slug,
            what: whatAct,
        });

        jQuery(this).attr('disabled', true);
        jQuery.post(ajaxurl, data, function (response) {
            jQuery(this).attr('disabled', false);
            var success = false;
            if (response) {
                if (response.result == 'SUCCESS') {
                    loadingEl.find('.message').addClass('green');
                    msg = __('Extension deactivated.');
                    if (whatAct == 'active') {
                        msg = __('Extension activated.');
                    } else if (whatAct == 'remove') {
                        msg = __('Extension removed.');
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
    var apiEl = jQuery(pObj).closest(".card");
    var statusEl = apiEl.find(".activate-api-status");
    var loadingEl = apiEl.find(".api-feedback");

    loadingEl.hide();

    if (jQuery(pObj).attr('license-status') == 'activated') {
        loadingEl.show();
        loadingEl.find('.message').html(__('Already activated.'));
        return;
    }

    if (retring == true) {
        loadingEl.show();
        loadingEl.find('.message').html(__('Connection error detected. The Verify Certificate option has been switched to NO. Retrying...'));
    } else {
        var extensionSlug = jQuery(apiEl).attr('extension-slug');
        var key = apiEl.find('input[type="text"].extension-api-key').val();
        var email = apiEl.find('input[type="text"].extension-api-email').val();

        if (key == '')
            return;

        var data = mainwp_secure_data({
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

        var success = false;

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
    var apiEl = jQuery(this).closest(".card");
    var statusEl = apiEl.find(".activate-api-status");
    var loadingEl = apiEl.find(".api-feedback");

    if (!apiEl.find('.mainwp-extensions-deactivate-chkbox').is(':checked'))
        return false;

    loadingEl.hide();

    var extensionSlug = jQuery(apiEl).attr('extension-slug');
    var extensionApiKey = jQuery(apiEl).find('.extension-api-key').val();
    var data = mainwp_secure_data({
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

        setTimeout(function () {
            location.href = 'admin.php?page=Extensions';
        }, 2000);
    }, 'json');
    return false;
});


// Verify mainwp.com login credentials
jQuery(document).on('click', '#mainwp-extensions-savelogin', function () {
    mainwp_extensions_savelogin(this, false);
});

function mainwp_extensions_savelogin(pObj, retring) {
    var grabingEl = jQuery("#mainwp-extensions-api-fields");
    var api_key = grabingEl.find('#mainwp_com_api_key').val();
    var statusEl = jQuery(".mainwp-extensions-api-loading");
    var data = mainwp_secure_data({
        action: 'mainwp_extension_saveextensionapilogin',
        api_key: api_key,
        saveLogin: jQuery('#extensions_api_savemylogin_chk').is(':checked') ? 1 : 0
    });

    if ( retring == true ) {
        statusEl.find( '.text' ).html(__("Connection error detected. The Verify Certificate option has been switched to NO. Retrying...")).fadeIn();
    } else {
        
        statusEl.find( '.text' ).html( __( 'Validating...' ) );
    }

    statusEl.show();

    jQuery.post(ajaxurl, data, function (response) {
        var undefError = false;
        if (response) {
            if (response.saved) {
                statusEl.find( '.text' ).html( 'Your API license key has been successfully saved!' );
            } else if (response.result == 'SUCCESS') {
                statusEl.find( '.text' ).html('API license key verification successful!');
            } else if ( response.error ) {
                statusEl.find( '.text' ).html(response.error);
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
            statusEl.find( '.text' ).html( __( 'Undefined error. Please try again.' ) );
        }
        setTimeout( function () {
            statusEl.fadeOut();
        }, 3000 );

    }, 'json');
    return false;
}

var maxActivateThreads = 8;
var totalActivateThreads = 0;
var currentActivateThreads = 0;
var finishedActivateThreads = 0;
var countSuccessActivation = 0;

// Bulk grab API keys
jQuery(document).on('click', '#mainwp-extensions-grabkeys', function () {
    mainwp_extensions_grabkeys(false);
});

function mainwp_extensions_grabkeys(retring) {
    var grabingEl = jQuery("#mainwp-extensions-api-fields");
    var master_api_key = grabingEl.find('#mainwp_com_api_key').val();
    var statusEl = jQuery(".mainwp-extensions-api-loading");
    var data = mainwp_secure_data({
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
        if (retring == true) {
            statusEl.find('.text').html(__("Connection error detected. The Verify Certificate option has been switched to NO. Retrying...")).fadeIn();
        } else {
            statusEl.removeClass('red');
            statusEl.removeClass('yellow');
            statusEl.removeClass('green');
            statusEl.find('.text').html( __('Validating. Please wait...')).show();
        }
        statusEl.show();
        jQuery.post(ajaxurl, data, function (response) {
            var undefError = false;
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

extensions_loop_next = function () {
    while ((extToActivate = jQuery('#mainwp-extensions-list .card[status="queue"]:first')) && (extToActivate.length > 0) && (currentActivateThreads < maxActivateThreads)) {
        extensions_activate_next(extToActivate, true);
    }
    if ((finishedActivateThreads == totalActivateThreads) && (countSuccessActivation == totalActivateThreads)) {
        setTimeout(function () {
            location.href = 'admin.php?page=Extensions';
        }, 2000);
    }
};

extensions_activate_next = function (pObj, bulkAct) {

    var grabingEl = jQuery("#mainwp-extensions-api-fields");
    var apiEl = pObj;
    var statusEl = apiEl.find(".activate-api-status");
    var loadingEl = apiEl.find(".api-feedback");

    var master_api_key = grabingEl.find('#mainwp_com_api_key').val();

    apiEl.attr("status", "running");

    var extensionSlug = apiEl.attr('extension-slug');
    var data = mainwp_secure_data({
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
    var grabingEl = jQuery("#mainwp-extensions-api-fields");
    var api_key = grabingEl.find('#mainwp_com_api_key').val().trim();
    if (api_key == '') {
        mainwp_extension_grab_org_extensions(this);
    } else {
        mainwp_extension_grab_purchased(this, false);
    }
})

mainwp_extension_grab_purchased = function (pObj, retring) {

    var grabingEl = jQuery("#mainwp-extensions-api-fields");
    var api_key = grabingEl.find('#mainwp_com_api_key').val().trim();

    var statusEl = jQuery(".mainwp-extensions-api-loading");
    var data = mainwp_secure_data({
        action: 'mainwp_extension_getpurchased',
        api_key: api_key
    });

    
    if ( api_key == '' ) {        
        statusEl.find('.text').html( __( "Main API Key is required." ) );
        statusEl.show();
        setTimeout(function () {
            statusEl.fadeOut();
        }, 3000);
    } else {
        if ( retring == true ) {
            statusEl.find('.text').html( __( "Connection error detected. The Verify Certificate option has been switched to NO. Retrying..." ) );
            setTimeout(function () {
                statusEl.fadeOut();
            }, 3000);
        } else {
            statusEl.show();
            statusEl.find( '.text' ).html( __( 'Loading extensions info...' ) );
            jQuery.post(ajaxurl, data, function (response) {
                var undefError = false;
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
                        statusEl.find( '.text' ).html(response.error);
                    } else if (response.retry_action && response.retry_action == 1) {
                        jQuery("#mainwp_api_sslVerifyCertificate").val(0);
                        statusEl.fadeOut();
                        mainwp_extension_grab_purchased(pObj, true);
                        return false;
                    } else {
                        undefError = true;
                    }
                } else {
                    undefError = true;
                }
                if ( undefError ) {
                    statusEl.find( '.text' ).html( __( 'Undefined error occurred. Please try again.' ) );
                }
                setTimeout(function () {
                    statusEl.fadeOut();
                }, 3000);
            }, 'json');
        }
    }
    return false;
}

mainwp_extension_select_to_install = function () {
    var inst_ext = jQuery('.item.extension[slug="' + jQuery('#extension_install_ext_slug').val() + '"]');
    console.log(inst_ext);
    if (jQuery(inst_ext).length > 0) {
        jQuery('mainwp-installing-extensions .ui.tab').removeClass('active');
        var curtab = jQuery(inst_ext[0]).closest('.ui.tab').attr('data-tab');
        jQuery('#mainwp-install-extensions-menu .item[data-tab=' + curtab + ']').trigger('click');
        var install_chk = jQuery(inst_ext).find('input[type=checkbox]');
        if (install_chk.length > 0) {
            jQuery(install_chk[0]).trigger('click');
        }
    }
}

mainwp_extension_grab_org_extensions = function () {

    var statusEl = jQuery(".mainwp-extensions-api-loading");
    var data = mainwp_secure_data({
        action: 'mainwp_extension_getpurchased',
    });

    statusEl.removeClass('green');
    statusEl.removeClass('yellow');
    statusEl.show();
    statusEl.find('.text').html( __('Running. Please wait...'));
    jQuery.post(ajaxurl, data, function (response) {
        var undefError = false;
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

bulkExtensionsMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
bulkExtensionsCurrentThreads = 0;
bulkExtensionsTotal = 0;
bulkExtensionsFinished = 0;
bulkExtensionsRunning = false;

mainwp_extension_bulk_install = function () {
    if (bulkExtensionsRunning)
        return;

    jQuery('.mainwp-installing-extensions input[type="checkbox"][status="queue"]:not(:checked)').closest('.extension-to-install').find('.installing-extension[status="queue"]').html('<span data-tooltip="Skipped" data-position="left center" data-inverted=""><i class="stop circle outline grey icon"></i></span>');

    bulkExtensionsTotal = jQuery('.mainwp-installing-extensions input[type="checkbox"][status="queue"]:checked').length;

    if (bulkExtensionsTotal == 0)
        return false;

    jQuery('.mainwp-installing-extensions input[type="checkbox"][status="queue"]:checked').closest('.extension-to-install').find('.installing-extension[status="queue"]').html('<i class="clock outline icon"></i> ' + __('Queued'));

    mainwp_extension_bulk_install_next();
}

mainwp_extension_bulk_install_next = function () {
    while ((extToInstall = jQuery('.mainwp-installing-extensions input[type="checkbox"][status="queue"]:checked:first').closest('.extension-to-install')) && (extToInstall.length > 0) && (bulkExtensionsCurrentThreads < bulkExtensionsMaxThreads)) {
        mainwp_extension_bulk_install_specific(extToInstall);
    }

    if ((bulkExtensionsTotal > 0) && (bulkExtensionsFinished == bulkExtensionsTotal)) {
        mainwp_extension_bulk_activate();
    }
}

mainwp_extension_bulk_install_specific = function (pExtToInstall) {
    bulkExtensionsRunning = true;
    pExtToInstall.find('input[type="checkbox"]').attr('status', 'running');
    bulkExtensionsCurrentThreads++;

    var statusEl = pExtToInstall.find('.installing-extension');

    statusEl.html('<span data-tooltip="Installing extension. Please wait..." data-position="left center" data-inverted=""><i class="notched circle loading icon"></i></span>');

    var data = mainwp_secure_data({
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

                var reg = new RegExp('<mainwp>(.*)</mainwp>');
                var matches = reg.exec(res_data);
                var response = '';

                if (matches) {
                    response_json = matches[1];
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

mainwp_extension_bulk_activate = function () {
    var plugins = [];

    jQuery('.extension-installed-success').each(function () {
        plugins.push(jQuery(this).attr('slug'));
    });

    if (plugins.length == 0) {
        mainwp_extension_bulk_install_done();
        return;
    }

    var data = mainwp_secure_data({
        action: 'mainwp_extension_bulk_activate',
        plugins: plugins
    });

    var statusEl = jQuery('#mainwp-bulk-activating-extensions-status');

    statusEl.html('<i class="notched circle loading icon"></i>' + __('Activating extensions. Please wait...')).show();
    jQuery.post(ajaxurl, data, function (response) {
        statusEl.html('');
        if (response == 'SUCCESS') {
            statusEl.addClass('green');
            statusEl.html(__('Extensions have been activated successfully!'));
            statusEl.fadeOut(3000);
        }
        mainwp_extension_bulk_install_done();
    });
}

mainwp_extension_bulk_install_done = function () {
    bulkExtensionsRunning = false;

    var statusEl = jQuery('#mainwp-bulk-activating-extensions-status');

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

    var parent = jQuery(this).closest(".extension_api_sslverify_loading");
    var statusEl = parent.find('span.status');
    var loadingEl = parent.find("i");

    var data = mainwp_secure_data({
        action: 'mainwp_extension_apisslverifycertificate',
        api_sslverify: jQuery("#mainwp_api_sslVerifyCertificate").val()
    });

    statusEl.hide();
    loadingEl.show();
    jQuery.post(ajaxurl, data, function (response) {
        loadingEl.hide();
        var undefError = false;
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

jQuery(document).ready(function ($) {
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

        var gr = $(this).attr('group');
        var selectedEl = $('#mainwp-available-extensions-list .mainwp-availbale-extension-holder.group-' + gr);
        var installedGroup = $('.installed-group-exts');
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
