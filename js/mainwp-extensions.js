jQuery(document).on('click', '#mainwp-extensions-expand', function ()
{
    jQuery(this).addClass('mainwp_action_down');
    jQuery('#mainwp-extensions-collapse').removeClass('mainwp_action_down');
    jQuery('.plugin-card').removeClass('collapsed');
    mainwp_setCookie('mwp_ext_collapsed', '');
    return false;
});

jQuery(document).on('click', '#mainwp-extensions-collapse', function ()
{
    jQuery(this).addClass('mainwp_action_down');
    jQuery('#mainwp-extensions-expand').removeClass('mainwp_action_down');
    jQuery('.plugin-card').addClass('collapsed');
    mainwp_setCookie('mwp_ext_collapsed', 'yes');
    return false;
});

jQuery(document).ready(function () {
    if (mainwp_getCookie('mwp_ext_collapsed') == 'yes')
        jQuery('#mainwp-extensions-collapse').click();
    else
        jQuery('#mainwp-extensions-expand').click();
})
//
//jQuery(document).on('click', '.mainwp-extensions-enable-all', function ()
//{
//    var extensionHolders = jQuery('.mainwp-extensions-childHolder');
//
//    var allExtensionSlugs = [];
//    for (var i = 0; i < extensionHolders.length; i++)
//    {
//        var extensionEnableButton = jQuery(extensionHolders[i]).find('.mainwp-extensions-enable');
//        if (extensionEnableButton && extensionEnableButton.is(":disabled")) continue;
//
//        allExtensionSlugs.push(jQuery(extensionHolders[i]).attr('extension_slug'));
//    }
//    var data = {
//        action:'mainwp_extension_enable_all',
//        slugs:allExtensionSlugs
//    };
//
//    jQuery.post(ajaxurl, data, function (response)
//    {
//        if (response.result == 'SUCCESS') location.reload();
//    }, 'json');
//
//    return false;
//});

//jQuery(document).on('click', '.mainwp-extensions-disable-all', function ()
//{
//    var data = {
//        action:'mainwp_extension_disable_all'
//    };
//
//    jQuery.post(ajaxurl, data, function (response)
//    {
//        if (response.result == 'SUCCESS') location.reload();
//    }, 'json');
//
//    return false;
//});

jQuery(document).on('click', '.mainwp-extensions-add-menu', function ()
{
    var extensionSlug = jQuery(this).parents('.plugin-card').attr('extension_slug');
    var data = {
        action:'mainwp_extension_add_menu',
        slug:extensionSlug
    };

    jQuery.post(ajaxurl, data, function (response)
    {
        if (response.result == 'SUCCESS') location.reload();
        else if (response.error)
        {
            alert(response.error);
        }
    }, 'json');

    return false;
});

jQuery(document).on('click', '.mainwp-extensions-remove-menu', function ()
{
    var extensionSlug = jQuery(this).parents('.plugin-card').attr('extension_slug');
    var data = {
        action:'mainwp_extension_remove_menu',
        slug:extensionSlug
    };

    jQuery.post(ajaxurl, data, function (response)
    {
        if (response.result == 'SUCCESS') location.reload();
    }, 'json');

    return false;
});

jQuery(document).on('click', '.mainwp-extensions-activate', function ()
{
    mainwp_extensions_activate(this, false);
});

function mainwp_extensions_activate(pObj, retring) {
    var api_row = jQuery(pObj).closest("div.plugin-card");
    var statusEl = api_row.find(".activate-api-status");
    var loadingEl = api_row.find(".mainwp_loading");

    if (jQuery(pObj).attr('license-status') == 'activated') {
        loadingEl.hide();
        statusEl.css('color', '#0074a2');
        statusEl.html('<i class="fa fa-check-circle"></i> ' + __('Extension already activated!')).fadeIn();
        return;
    }

    if (retring == true) {
        statusEl.css('color', '#0074a2');
        statusEl.html(' ' + __("Connection error detected. The Verify Certificate option has been switched to NO. Retrying...")).fadeIn();
    } else
        statusEl.hide();
    loadingEl.show();
    var extensionSlug = jQuery(pObj).parents('.plugin-card').attr('extension_slug');
    var data = mainwp_secure_data({
        action:'mainwp_extension_activate',
        slug:extensionSlug,
        key: api_row.find('input.api_key:text').val(),
        email: api_row.find('input.api_email:text').val()
    });

    jQuery.post(ajaxurl, data, function (response)
    {
        loadingEl.hide();
        var success = false;
        if (response) {
            if (response.result == 'SUCCESS') {
                statusEl.css('color', '#0074a2');
                statusEl.html('<i class="fa fa-check-circle"></i> ' + __('Extension activated successfully!')).fadeIn();
                success = true;
            } else if (response.error) {
                statusEl.css('color', 'red');
                statusEl.html(response.error).fadeIn();
            } else if (response.retry_action && response.retry_action == 1){
                jQuery("#mainwp_api_sslVerifyCertificate").val(0);
                mainwp_extensions_activate(pObj, true);
                return false;
            } else {
                statusEl.css('color', 'red');
                statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').fadeIn();
            }
        } else {
            statusEl.css('color', 'red');
            statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').fadeIn();
        }

        if (success) {
            setTimeout(function ()
            {
                location.href = 'admin.php?page=Extensions';
            }, 2000);
        }

    }, 'json');

    return false;
}

jQuery(document).on('click', '.mainwp-extensions-deactivate', function ()
{
    var api_row = jQuery(this).parents("div.plugin-card");
    var statusEl = api_row.find(".activate-api-status");

    if (!api_row.find('.mainwp-extensions-deactivate-chkbox').is(':checked'))
        return false;

    var extensionSlug = jQuery(this).parents('.plugin-card').attr('extension_slug');
    var data = mainwp_secure_data({
        action:'mainwp_extension_deactivate',
        slug:extensionSlug
    });

    var loadingEl = api_row.find(".mainwp_loading");
    statusEl.hide();
    loadingEl.show();
    jQuery.post(ajaxurl, data, function (response)
    {
        loadingEl.hide();
        if (response) {
            if (response.result == 'SUCCESS') {
                statusEl.css('color', '#0074a2');
                var msg = '<i class="fa fa-check-circle"></i> ' + __('Extension has been deactivated!');
//                if (response.activations_remaining)
//                    msg += ' ' + response.activations_remaining;
                statusEl.html(msg).fadeIn();
            } else if (response.error) {
                statusEl.css('color', 'red');
                statusEl.html(response.error).fadeIn();
            } else {
                statusEl.css('color', 'red');
                statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').fadeIn();
            }
        } else {
            statusEl.css('color', 'red');
            statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').fadeIn();
        }

        setTimeout(function ()
        {
            location.href = 'admin.php?page=Extensions';
        }, 1000);

    }, 'json');

    return false;
});

jQuery(document).on('click', '#mainwp-extensions-savelogin', function ()
{
    mainwp_extensions_savelogin(this, false);
});

function mainwp_extensions_savelogin(pObj, retring) {
    var grabingEl = jQuery(".api-grabbing-fields");
    var username = grabingEl.find('input.username:text').val();
    var pwd = grabingEl.find('input.passwd:password').val();

    var parent = jQuery(pObj).closest(".extension_api_loading");
    var statusEl = parent.find('span.status');
    var loadingEl = parent.find("i");

    var data = mainwp_secure_data({
        action:'mainwp_extension_saveextensionapilogin',
        username: username,
        password: pwd,
        saveLogin: jQuery('#extensions_api_savemylogin_chk').is(':checked') ? '1' : '0'
    });

    if (retring == true) {
        statusEl.css('color', '#0074a2');
        statusEl.html(' ' + __("Connection error detected. The Verify Certificate option has been switched to NO. Retrying...")).fadeIn();
    } else
        statusEl.hide();

    loadingEl.show();
    jQuery.post(ajaxurl, data, function (response)
    {
        loadingEl.hide();
        var undefError = false;
        if (response) {
            if (response.saved) {
                statusEl.css('color', '#0074a2');
                statusEl.html('<i class="fa fa-check-circle"></i> Login saved!').fadeIn();
            } else if (response.result == 'SUCCESS') {
                statusEl.css('color', '#0074a2');
                statusEl.html('<i class="fa fa-check-circle"></i> Login valid!').fadeIn();
                setTimeout(function ()
                {
                    statusEl.fadeOut();
                }, 3000);
            } else if (response.error) {
                statusEl.css('color', 'red');
                statusEl.html(response.error).fadeIn();
            } else if (response.retry_action && response.retry_action == 1){
                jQuery("#mainwp_api_sslVerifyCertificate").val(0);
                mainwp_extensions_savelogin(pObj, true);
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

var maxActivateThreads = 8;
var totalActivateThreads = 0;
var currentActivateThreads = 0;
var finishedActivateThreads = 0;
var countSuccessActivation = 0;

jQuery(document).on('click', '#mainwp-extensions-grabkeys', function ()
{
    mainwp_extensions_grabkeys(this, false);
});

function mainwp_extensions_grabkeys(pObj, retring) {
    var grabingEl = jQuery(".api-grabbing-fields");
    var username = grabingEl.find('input.username:text').val();
    var pwd = grabingEl.find('input.passwd:password').val();

    var parent = jQuery(pObj).parent().closest(".extension_api_loading");
    var statusEl = parent.find('span.status');
    var loadingEl = parent.find("i");

    var data = {
        action:'mainwp_extension_testextensionapilogin',
        username: username,
        password: pwd
    };

    if (retring == true) {
        statusEl.css('color', '#0074a2');
        statusEl.html(' ' + __("Connection error detected. The Verify Certificate option has been switched to NO. Retrying...")).fadeIn();
    } else
        statusEl.hide();

    loadingEl.show();
    jQuery.post(ajaxurl, data, function (response)
    {
        loadingEl.hide();
        var undefError = false;
        if (response) {
            if (response.result == 'SUCCESS') {
                statusEl.css('color', '#0074a2');
                statusEl.html('<i class="fa fa-check-circle"></i> Login valid!').fadeIn();
                setTimeout(function ()
                {
                    statusEl.fadeOut();
                }, 3000);
                totalActivateThreads = jQuery('#mainwp-extensions-list .plugin-card[status="queue"]').length;
                if (totalActivateThreads > 0)
                    extensions_loop_next();
            } else if (response.error) {
                statusEl.css('color', 'red');
                statusEl.html(response.error).fadeIn();
            } else if (response.retry_action && response.retry_action == 1){
                jQuery("#mainwp_api_sslVerifyCertificate").val(0);
                mainwp_extensions_grabkeys(pObj, true);
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

extensions_loop_next = function()
{
    while((extToActivate = jQuery('#mainwp-extensions-list .plugin-card[status="queue"]:first')) && (extToActivate.length > 0) && (currentActivateThreads < maxActivateThreads))
    {
        extensions_activate_next(extToActivate);
    }

    if ((finishedActivateThreads == totalActivateThreads) && (countSuccessActivation == totalActivateThreads)) {
        setTimeout(function ()
        {
            location.href = 'admin.php?page=Extensions';
        }, 3000);
    }
};

extensions_activate_next = function(pObj)
{
    var grabingEl = jQuery(".api-grabbing-fields");
    var username = grabingEl.find('input.username:text').val();
    var pwd = grabingEl.find('input.passwd:password').val();

    var api_row = pObj;
    var statusEl = api_row.find(".activate-api-status");
    var loadingEl = api_row.find(".mainwp_loading");
    api_row.attr("status", "running");
    var extensionSlug = api_row.attr('extension_slug');
    var data = {
        action:'mainwp_extension_grabapikey',
        username: username,
        password: pwd,
        slug:extensionSlug
    };
    currentActivateThreads++;
    statusEl.hide();
    loadingEl.show();
    if (api_row.attr('license-status') == 'activated') {
        loadingEl.hide();
        finishedActivateThreads++;
        currentActivateThreads--;
        statusEl.css('color', '#0074a2');
        statusEl.html('<i class="fa fa-check-circle"></i> ' + __('Extension already activated!')).fadeIn();
        countSuccessActivation++;
        extensions_loop_next();
        return;
    }
    api_row.find('.api-row-div').show();
    jQuery.post(ajaxurl, data, function (response)
    {
        loadingEl.hide();
        finishedActivateThreads++;
        currentActivateThreads--;
        if (response) {
            if (response.result == 'SUCCESS') {
                countSuccessActivation++;
                if (response.api_key)
                    api_row.find('input.api_key:text').val(response.api_key);
                if (response.activation_email)
                    api_row.find('input.api_email:text').val(response.activation_email);
                statusEl.css('color', '#0074a2');
                statusEl.html('<i class="fa fa-check-circle"></i> ' + __("Extension has been activated successfully!")).fadeIn();
                api_row.find('.mainwp-extensions-deactivate-chkbox').attr('checked', false);
                var acts = api_row.find("td.mainwp-extensions-childActions");
                acts.find('a.api-status').text(__('Activated'));
                acts.find('a.api-status').removeClass('mainwp-red').addClass('mainwp-green');
                acts.find('img.image-api-status').attr("src", mainwpParams['image_url'] + 'extensions/unlock.png');
                acts.find('img.image-api-status').attr("title", __('Activated'));
            } else if (response.error) {
                statusEl.css('color', 'red');
                statusEl.html(response.error).fadeIn();
            } else {
                statusEl.css('color', 'red');
                statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').fadeIn();
            }
        } else {
            statusEl.css('color', 'red');
            statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').fadeIn();
        }
        extensions_loop_next();
    }, 'json');
};

jQuery(document).on('click', '#mainwp-extensions-bulkinstall', function () {
    mainwp_extension_grab_purchased(this, false);
})

mainwp_extension_grab_purchased = function(pObj, retring) {
    var grabingEl = jQuery(".api-grabbing-fields");
    var username = grabingEl.find('input.username:text').val();
    var pwd = grabingEl.find('input.passwd:password').val();

    var parent = jQuery(pObj).closest(".extension_api_loading");
    var statusEl = parent.find('span.status');
    var loadingEl = parent.find("i");

    var data = {
        action:'mainwp_extension_getpurchased',
        username: username,
        password: pwd
    };
    if (retring == true) {
        statusEl.css('color', '#0074a2');
        statusEl.html(' ' + __("Connection error detected. The Verify Certificate option has been switched to NO. Retrying...")).fadeIn();
    } else
        statusEl.hide();

    loadingEl.show();
    jQuery.post(ajaxurl, data, function (response)
    {
        loadingEl.hide();
        var undefError = false;
        if (response) {
            if (response.result == 'SUCCESS') {
                jQuery('#mainwp-install-purchased-extensions').html(response.data);
            } else if (response.error) {
                statusEl.css('color', 'red');
                statusEl.html(response.error).fadeIn();
            } else if (response.retry_action && response.retry_action == 1){
                jQuery("#mainwp_api_sslVerifyCertificate").val(0);
                mainwp_extension_grab_purchased(pObj, true);
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

jQuery(document).on('click', '#mainwp-extensions-installnow', function () {
    mainwp_extension_bulk_install();
    return false;
})

bulkExtensionsMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
bulkExtensionsCurrentThreads = 0;
bulkExtensionsTotal = 0;
bulkExtensionsFinished = 0;
bulkExtensionsRunning = false;

mainwp_extension_bulk_install = function() {
    if (bulkExtensionsRunning)
        return;
    jQuery('.mainwp_extension_installing INPUT:checkbox:not(:checked)[status="queue"]').closest('.extension_to_install').find('.ext_installing .status').html(__('Skipped')).show();
    bulkExtensionsTotal = jQuery('.mainwp_extension_installing INPUT:checkbox:checked[status="queue"]').length;
    if (bulkExtensionsTotal == 0)
        return false;
    jQuery('.extension_to_install .ext_installing .status').show();
    mainwp_extension_bulk_install_next();
}

mainwp_extension_bulk_install_done = function() {
    bulkExtensionsRunning = false;
    jQuery('#mainwp-install-purchased-extensions').append('<div class="mainwp-notice mainwp-notice-green"><i class="fa fa-check-circle"></i> ' + __("Installation completed!") + '</div><div class="mainwp-notice mainwp-notice-green">' + __('Refreshing the page for Step 3 "Grab API Keys" in 5 seconds... if refresh fails please %1click here%2.', '<a href="admin.php?page=Extensions" title="Extensions page">', '</a>') + '</div>');
    setTimeout(function ()
    {
        location.href = 'admin.php?page=Extensions';
    }, 5000);
}

mainwp_extension_bulk_install_next = function() {
    while ((extToInstall = jQuery('.mainwp_extension_installing INPUT:checkbox:checked[status="queue"]:first').closest('.extension_to_install')) && (extToInstall.length > 0)  && (bulkExtensionsCurrentThreads < bulkExtensionsMaxThreads))
    {
        mainwp_extension_bulk_install_specific(extToInstall);
    }
    if ((bulkExtensionsTotal > 0) && (bulkExtensionsFinished == bulkExtensionsTotal)) {
        mainwp_extension_bulk_activate();
    }
}

mainwp_extension_bulk_activate = function() {
    var plugins = [];
    jQuery('.extension_installed_success').each(function() {
        plugins.push(jQuery(this).attr('slug'));
    });

    if (plugins.length == 0) {
        mainwp_extension_bulk_install_done();
        return;
    }

    var data = mainwp_secure_data({
        action:'mainwp_extension_bulk_activate',
        plugins: plugins
    });
    var loadingEl = jQuery('#extBulkActivate i');
    var statusEl = jQuery('#extBulkActivate .status');
    loadingEl.show();
    statusEl.html(__('Activating plugins...')).show();
    jQuery.post(ajaxurl, data,  function(response) {
        loadingEl.hide();
        if (response == 'SUCCESS') {
            statusEl.css('color', '#21759B');
            statusEl.html('<i class="fa fa-check-circle"></i> Plugins have been activated successfully!').show();
            statusEl.fadeOut(1000);
        }
        mainwp_extension_bulk_install_done();
    });
}

mainwp_extension_bulk_install_specific = function(pExtToInstall) {
    bulkExtensionsRunning = true;
    pExtToInstall.find('INPUT[type="checkbox"]').attr('status', 'running');
    bulkExtensionsCurrentThreads++;
    var loadingEl = pExtToInstall.find('.ext_installing i');
    var statusEl = pExtToInstall.find('.ext_installing .status');
    loadingEl.show();
    statusEl.css('color', '#000');
    statusEl.html(__('Installing...'));
    var data = mainwp_secure_data({
        action:'mainwp_extension_downloadandinstall',
        download_link: pExtToInstall.attr('download-link')
    });
    jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: data,
        success: function() { return function (res_data) {
            bulkExtensionsCurrentThreads--;
            bulkExtensionsFinished++;
            loadingEl.hide();
            var reg = new RegExp('<mainwp>(.*)</mainwp>');
            var matches = reg.exec(res_data);
            var response = '';
            if (matches) {
                response_json = matches[1];
                response = jQuery.parseJSON(response_json );
            }
            if (response != '') {
                if (response.result == 'SUCCESS') {
                    statusEl.css('color', '#21759B')
                    statusEl.html(response.output).show();
                    jQuery('.mainwp_extension_installing').append('<span class="extension_installed_success" slug="' + response.slug + '"></span>')
                } else if (response.error) {
                    statusEl.css('color', 'red');
                    statusEl.html('<strong><i class="fa fa-exclamation-circle"></i> Error:</strong> ' + response.error).show();
                } else {
                    statusEl.css('color', 'red');
                    statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').show();
                }
            } else {
                statusEl.css('color', 'red');
                statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').show();
            }
            mainwp_extension_bulk_install_next();
        } }()
    });
    return false;
}


jQuery(document).on('click', '#mainwp-extensions-api-sslverify-certificate', function ()
{

    var parent = jQuery(this).closest(".extension_api_sslverify_loading");
    var statusEl = parent.find('span.status');
    var loadingEl = parent.find("i");

    var data = {
        action:'mainwp_extension_apisslverifycertificate',
        api_sslverify: jQuery("#mainwp_api_sslVerifyCertificate").val()
    };

    statusEl.hide();
    loadingEl.show();
    jQuery.post(ajaxurl, data, function (response)
    {
        loadingEl.hide();
        var undefError = false;
        if (response) {
            if (response.saved) {
                statusEl.css('color', '#0074a2');
                statusEl.html('<i class="fa fa-check-circle"></i> Saved!').fadeIn();
                setTimeout(function ()
                {
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
            statusEl.html('<i class="fa fa-exclamation-circle"></i> Undefined error!').fadeIn();
        }
    }, 'json');
    return false;
});

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

jQuery(document).ready(function($) {
    $('#mainwp-check-all-ext').live('click', function() {
        $('.extension_to_install').find("input:checkbox").each(function(){
            $(this).attr('checked', true);
        });
    });
    $('#mainwp-uncheck-all-ext').live('click', function() {
        $('.extension_to_install').find("input:checkbox").each(function(){
            $(this).attr('checked', false);
        });
    });
    $('#mainwp-check-all-sync-ext').live('click', function() {
        $('.sync-ext-row').find("input:checkbox").each(function(){
            $(this).attr('checked', true);
        });
    });
    $('#mainwp-uncheck-all-sync-ext').live('click', function() {
        $('.sync-ext-row').find("input:checkbox").each(function(){
            $(this).attr('checked', false);
        });
    });

    $('.mainwp-show-extensions').live('click', function() {
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

