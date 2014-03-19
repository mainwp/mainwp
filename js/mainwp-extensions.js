jQuery(document).on('click', '#mainwp-extensions-expand', function ()
{
    jQuery(this).addClass('mainwp_action_down');
    jQuery('#mainwp-extensions-collapse').removeClass('mainwp_action_down');
    var extensionImgs = jQuery('.mainwp-extensions-img');
    extensionImgs.addClass('large');
    extensionImgs.removeClass('small');
    jQuery('.mainwp-extensions-extra').show();
    jQuery('.mainwp-extensions-childHolder').removeClass('collapsed');
    return false;
});

jQuery(document).on('click', '#mainwp-extensions-collapse', function ()
{
    jQuery(this).addClass('mainwp_action_down');
    jQuery('#mainwp-extensions-expand').removeClass('mainwp_action_down');
    var extensionImgs = jQuery('.mainwp-extensions-img');
    extensionImgs.removeClass('large');
    extensionImgs.addClass('small');
    jQuery('.mainwp-extensions-extra').hide();
    jQuery('.mainwp-extensions-childHolder').addClass('collapsed');
    return false;
});

jQuery(document).on('click', '.mainwp-extensions-enable-all', function ()
{
    var extensionHolders = jQuery('.mainwp-extensions-childHolder');

    var allExtensionSlugs = [];
    for (var i = 0; i < extensionHolders.length; i++)
    {
        var extensionEnableButton = jQuery(extensionHolders[i]).find('.mainwp-extensions-enable');
        if (extensionEnableButton && extensionEnableButton.is(":disabled")) continue;

        allExtensionSlugs.push(jQuery(extensionHolders[i]).attr('extension_slug'));
    }
    var data = {
        action:'mainwp_extension_enable_all',
        slugs:allExtensionSlugs
    };

    jQuery.post(ajaxurl, data, function (response)
    {
        if (response.result == 'SUCCESS') location.reload();
    }, 'json');

    return false;
});

jQuery(document).on('click', '.mainwp-extensions-disable-all', function ()
{
    var data = {
        action:'mainwp_extension_disable_all'
    };

    jQuery.post(ajaxurl, data, function (response)
    {
        if (response.result == 'SUCCESS') location.reload();
    }, 'json');

    return false;
});

jQuery(document).on('click', '.mainwp-extensions-enable', function ()
{
    var extensionSlug = jQuery(this).parents('.mainwp-extensions-childHolder').attr('extension_slug');
    var data = {
        action:'mainwp_extension_enable',
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

jQuery(document).on('click', '.mainwp-extensions-disable', function ()
{
    var extensionSlug = jQuery(this).parents('.mainwp-extensions-childHolder').attr('extension_slug');
    var data = {
        action:'mainwp_extension_disable',
        slug:extensionSlug
    };

    jQuery.post(ajaxurl, data, function (response)
    {
        if (response.result == 'SUCCESS') location.reload();
    }, 'json');

    return false;
});

jQuery(document).on('click', '.mainwp-extensions-trash', function ()
{
    var extensionSlug = jQuery(this).parents('.mainwp-extensions-childHolder').attr('extension_slug');
    var data = {
        action:'mainwp_extension_trash',
        slug:extensionSlug
    };

    jQuery.post(ajaxurl, data, function (response)
    {
        if (response.result == 'SUCCESS') location.reload();
    }, 'json');

    return false;
});

jQuery(document).on('click', '.mainwp-extension-widget-switch-list', function() {
    jQuery('#mainwp-extensions-widget-list').show();
    jQuery('#mainwp-extensions-widget-grid').hide();
    jQuery('.mainwp-extension-widget-switch-list').hide();
    jQuery('.mainwp-extension-widget-switch-grid').show();

    var data = mainwp_secure_data({
        action:'mainwp_extension_change_view',
        view: 'list'
    });
    jQuery.post(ajaxurl, data, function(response) {});

    return false;
});

jQuery(document).on('click', '.mainwp-extension-widget-switch-grid', function() {
    jQuery('#mainwp-extensions-widget-list').hide();
    jQuery('#mainwp-extensions-widget-grid').show();
    jQuery('.mainwp-extension-widget-switch-grid').hide();
    jQuery('.mainwp-extension-widget-switch-list').show();

    var data = mainwp_secure_data({
        action:'mainwp_extension_change_view',
        view: 'grid'
    });
    jQuery.post(ajaxurl, data, function(response) {});

    return false;
});