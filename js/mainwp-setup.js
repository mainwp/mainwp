jQuery(document).ready(function () {

    // to fix conflict with bootstrap tooltip
    jQuery.widget.bridge('uitooltip', jQuery.ui.tooltip);
    jQuery(document).uitooltip({
        items:"span.tooltip",
        track:true,
        content:function ()
        {
            var element = jQuery(this);
            return element.parents('.tooltipcontainer').children('.tooltipcontent').html();
        }
    });

    jQuery('input[type=radio][name=mwp_setup_installation_hosting_type]').change(function() {
        if (this.value == 2) {
            jQuery('input[name="mwp_setup_installation_system_type"]').removeAttr("disabled");
            jQuery('#mwp_setup_os_type').fadeIn(500);
        }
        else {
            jQuery('input[name="mwp_setup_installation_system_type"]').attr("disabled", "disabled");
            jQuery('#mwp_setup_os_type').fadeOut(500);
        }
    });

    jQuery('#mwp_setup_planning_backup').change(function() {
        if (jQuery(this).is(':checked')) {
            jQuery('#mwp_setup_tr_backup_method').fadeIn(500);
            jQuery('#mwp_setup_backup_method').removeAttr('disabled');
        }
        else {
            jQuery('#mwp_setup_tr_backup_method').fadeOut(500);
            jQuery('#mwp_setup_backup_method').attr('disabled', 'disabled');
        }
    });

    jQuery('#mwp_setup_backup_method').on('change', function() {
        var bkmethod = jQuery(this).val();
        jQuery('.mainwp-backups-notice').hide();
        jQuery('.mainwp-backups-notice[method="' + bkmethod + '"]').show();
    });

    jQuery('#mwp_setup_manage_planning').change(function() {
        if ((jQuery(this).val() == 2) && (jQuery('#mwp_setup_type_hosting').val() == 3)) {
            jQuery('#mwp_setup_hosting_notice').fadeIn(500);
        } else {
            jQuery('#mwp_setup_hosting_notice').fadeOut(1000);
        }
    })

    jQuery('#mwp_setup_manage_planning').change(function() {
        mainwp_setup_showhide_hosting_notice();
    })
    jQuery('#mwp_setup_type_hosting').change(function() {
        mainwp_setup_showhide_hosting_notice();
    })
});

mainwp_setup_auth_uptime_robot = function(url) {
    window.open(url, 'Authorize Uptime Robot', 'height=600,width=700');
    return false;
}


mainwp_setup_showhide_hosting_notice = function() {
    if ((jQuery('#mwp_setup_manage_planning').val() == 2) && (jQuery('#mwp_setup_type_hosting').val() == 3)) {
        jQuery('#mwp_setup_hosting_notice').fadeIn(500);
    } else {
        jQuery('#mwp_setup_hosting_notice').fadeOut(500);
    }
}

mainwp_setup_grab_extension = function(retring, pRegisterLater) {
    var parent = jQuery("#mwp_setup_auto_install_loading");
    var statusEl = parent.find('span.status');
    var loadingEl = parent.find("i");

    var extProductId = jQuery('#mwp_setup_extension_product_id').val();
    if (extProductId == '') {
        statusEl.css('color', 'red');
        statusEl.html(' ' + "ERROR: empty extension product id.").fadeIn();
        return false;
    }

    var data = {
        action:'mainwp_setup_extension_getextension',
        productId: extProductId,
        register_later: pRegisterLater
    };

    if (retring == true) {
        statusEl.css('color', '#0074a2');
        statusEl.html(' ' + "Connection error detected. The Verify Certificate option has been switched to NO. Retrying...").fadeIn();
    } else
        statusEl.hide();

    loadingEl.show();
    jQuery.post(ajaxurl, data, function (response)
    {
        loadingEl.hide();
        var undefError = false;
        if (response) {
            if (response.result == 'SUCCESS') {
                jQuery('#mwp_setup-install-extension').html(response.data);
                mainwp_setup_extension_install(pRegisterLater);
            } else if (response.error) {
                statusEl.css('color', 'red');
                statusEl.html(response.error).fadeIn();
            } else if (response.retry_action && response.retry_action == 1){
                mainwp_setup_grab_extension(true, pRegisterLater);
                return false;
            } else {
                undefError = true;
            }
        } else {
            undefError = true;
        }

        if (undefError) {
            statusEl.css('color', 'red');
            statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').fadeIn();
        }
    }, 'json');
    return false;
}

mainwp_setup_extension_install = function(pRegisterLater) {
    var pExtToInstall = jQuery('.mwp_setup_extension_installing .extension_to_install');
    var loadingEl = pExtToInstall.find('.ext_installing i');
    var statusEl = pExtToInstall.find('.ext_installing .status');
    loadingEl.show();
    statusEl.css('color', '#000');
    statusEl.html('Installing...');

    var data = {
        action:'mainwp_setup_extension_downloadandinstall',
        download_link: pExtToInstall.attr('download-link'),
        security: mainwpSetupLocalize.nonce
    };

    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function() { return function (res_data) {
            loadingEl.hide();
            var reg = new RegExp('<mainwp>(.*)</mainwp>');
            var matches = reg.exec(res_data);
            var response = '';
            var failed = true;
            if (matches) {
                response_json = matches[1];
                response = jQuery.parseJSON(response_json );
            }
            if (response != '') {
                if (response.result == 'SUCCESS') {
                    failed = false;
                    statusEl.css('color', '#21759B')
                    statusEl.html(response.output).show();
                    jQuery('.mwp_setup_extension_installing').append('<span class="extension_installed_success" slug="' + response.slug + '"></span>');
                    if (!pRegisterLater) {
                        jQuery('#mwp_setup_active_extension').fadeIn(500);
                        mainwp_setup_extension_activate(false);
                    }
                } else if (response.error) {
                    statusEl.css('color', 'red');
                    statusEl.html('<strong><i class="fa fa-exclamation-circle"></i> ERROR:</strong> ' + response.error).show();
                } else {
                    statusEl.css('color', 'red');
                    statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').show();
                }
            } else {
                statusEl.css('color', 'red');
                statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').show();
            }
            if (failed) {
                jQuery('#mwp_setup-install-extension').append(jQuery('#mwp_setup_extension_retry_install')[0].innerHTML);
            }
        } }()
    });
    return false;
}


mainwp_setup_extension_activate_plugin = function(pRegisterLater) {
    var plugins = [];
    jQuery('.extension_installed_success').each(function() {
        plugins.push(jQuery(this).attr('slug'));
    });

    if (plugins.length == 0) {
        return;
    }

    var data = {
        action:'mainwp_setup_extension_activate_plugin',
        plugins: plugins,
        security: mainwpSetupLocalize.nonce
    };

    jQuery.post(ajaxurl, data,  function(response) {
        if (response == 'SUCCESS') {
            if (!pRegisterLater) {
                jQuery('#mwp_setup_active_extension').fadeIn(500);
                mainwp_setup_extension_activate(false);
            }
        } else {

        }
    });
}

mainwp_setup_extension_activate = function(retring)
{
    var parent = jQuery("#mwp_setup_grabing_api_key_loading");
    var statusEl = parent.find('span.status');
    var loadingEl = parent.find("i");
    var extensionSlug = jQuery('#mwp_setup_extension_product_id').attr('slug');
    var data = {
        action:'mainwp_setup_extension_grabapikey',
        slug:extensionSlug
    };

    if (retring == true) {
        statusEl.css('color', '#0074a2');
        statusEl.html(' ' + "Connection error detected. The Verify Certificate option has been switched to NO. Retrying...").fadeIn();
    } else
        statusEl.hide();

    loadingEl.show();
    jQuery.post(ajaxurl, data, function (response)
    {
        loadingEl.hide();
        if (response) {
            if (response.result == 'SUCCESS') {
                statusEl.css('color', '#0074a2');
                statusEl.html('<i class="fa fa-check-circle"></i> ' + "Extension has been activated successfully!").fadeIn();
            } else if (response.error) {
                statusEl.css('color', 'red');
                statusEl.html(response.error).fadeIn();
            } else if (response.retry_action && response.retry_action == 1){
                jQuery("#mainwp_api_sslVerifyCertificate").val(0);
                mainwp_setup_extension_activate(true);
                return false;
            } else {
                statusEl.css('color', 'red');
                statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').fadeIn();
            }
        } else {
            statusEl.css('color', 'red');
            statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').fadeIn();
        }
    }, 'json');
};

jQuery(document).ready(function () {
    jQuery('.mwp_remove_email').live('click', function () {
        jQuery(this).closest('.mwp_email_box').remove();
        return false;
    });
    jQuery('#mwp_add_other_email').live('click', function () {
        jQuery('#mwp_add_other_email').before('<div class="mwp_email_box"><input type="text" name="mainwp_options_email[]" size="35" value=""/>&nbsp;&nbsp;<a href="#" class="mwp_remove_email"><i class="fa fa-minus-circle fa-lg mainwp-red" aria-hidden="true"></i></a></div>');
        return false;
    });
});

