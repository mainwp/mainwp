/** Manage tips **/
jQuery(document).ready(function()
{
    jQuery('#mainwp_managetips_add').live('click', function(event)
    {
        mainwp_managetips_addTip(event);
    });
    jQuery('.mainwp_managetips_remove').each(function() {
        jQuery(this).bind('click', function(event)
        {
            mainwp_managetips_removeTip(this);
            return false;
        });
    });
});
mainwp_managetips_currId = 90000;
mainwp_managetips_addTip = function(event)
{
    var currId = ++mainwp_managetips_currId;
    jQuery('#mainwp_managetips_tbody').append('<tr><td valign="top"><input type="text" name="tip_'+currId+'_seq" size="1" value="" class="mainwp_managetips_tip_seq"/></td><td><textarea rows="4" cols="150" name="tip_'+currId+'_content" class="mainwp_managetips_tip_content"></textarea></td><td valign="top"><a href="#" class="mainwp_managetips_remove" onclick="mainwp_managetips_removeTip(this); return false;">'+__('Remove')+'</a></td></tr>');
};
mainwp_managetips_removeTip = function(_this)
{
    var container = jQuery(_this).parent().parent();
    container.hide();
    container.find('.mainwp_managetips_tip_seq').val('');
    container.find('.mainwp_managetips_tip_content').val('');
};


