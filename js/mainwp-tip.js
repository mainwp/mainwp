jQuery(document).ready(function()
{
    jQuery('#mainwp_tips_next').bind('click', function(event)
    {
        mainwp_tips_next();
    });
    jQuery('#mainwp_tips_close').bind('click', function(event)
    {
        mainwp_tips_hide();
        return false;
    });
    jQuery('#mainwp_tips_closeX').bind('click', function(event)
    {
        mainwp_tips_hide();
        return false;
    });
    mainwp_tips_show();
});
mainwp_tips_show = function()
{
    jQuery('#mainwp_tips_overlay').height(jQuery(document).height());
    jQuery('#mainwp_tips_overlay').show();
    jQuery('#mainwp_tips').show();
};
mainwp_tips_hide = function()
{
    jQuery('#mainwp_tips').hide();
    jQuery('#mainwp_tips_overlay').hide();

    var data = mainwp_secure_data({
        action: 'mainwp_managetips_update',
        status: (jQuery('#mainwp_tips_show').attr('checked') == 'checked' ? 0 : 1)
    });
    jQuery.post(ajaxurl, data, function(response)
    {
    });
};
mainwp_tips_next = function()
{
    var currTip = jQuery('#mainwp_tips_current').val();
    jQuery('#mainwp_tips_content_' + currTip).hide();
    currTip++;
    if (currTip > jQuery('#mainwp_tips_max').val()) currTip = 1;

    jQuery('#mainwp_tips_content_' + currTip).show();
    jQuery('#mainwp_tips_current').val(currTip);
    jQuery('#mainwp_tips_current_label').html(currTip);
};
