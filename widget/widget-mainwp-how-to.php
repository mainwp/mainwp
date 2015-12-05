<?php
//todo: rename
class MainWP_How_To
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function getName()
    {
        return __('<i class="fa fa-graduation-cap"></i> How To','mainwp');
    }

    public static function render()
    {
        ?>
        <div>
            <div id="mainwp-docs-search" class="mainwp-row-top">
                <form name="advanced-search-form" method="get" action="//blog.mainwp.com/" class="auto-complete" autocomplete="off" target="_blank">
                    <input type="text" style="width: 75%;" class="input-text input-txt" name="s" id="s" value="" placeholder="<?php _e('Search the MainWP Blog','mainwp'); ?>" />
                    <button type="submit" class="button button-primary mainwp-upgrade-button" style="padding-left: 3em !important; padding-right: 3em !important;"><?php _e('Search','mainwp'); ?></button>
                </form>
            </div>
            <div id="mainwp-how-tos">
                <ul>
                    <li><a href="http://blog.mainwp.com/how-to-set-two-factor-authentication-on-your-mainwp-dashboard/" target="_blank"><i class="fa fa-book"></i> How to set free two-factor authentication on your MainWP Dashboard</a></li>
                    <li><a href="http://blog.mainwp.com/set-backupwordpress-extension-as-your-primary-backup-system/" target="_blank"><i class="fa fa-book"></i> How to set a Backup Extension as your primary backup system</a></li>
                    <li><a href="http://blog.mainwp.com/how-you-can-make-sure-that-your-dashboard-can-communicate-with-your-child-site/" target="_blank"><i class="fa fa-book"></i> How to make sure that your MainWP Dashboard can communicate with your Child site</a></li>
                    <li><a href="http://blog.mainwp.com/did-you-know-mainwp-can-install-updates-for-you-automatically/" target="_blank"><i class="fa fa-book"></i> Did you know MainWP can install updates for you automatically</a></li>
                    <li><a href="http://blog.mainwp.com/how-to-use-desktopserver-to-run-your-mainwp-dashboard-locally/" target="_blank"><i class="fa fa-book"></i> How to use DesktopServer to run your MainWP Dashboard locally</a></li>
                </ul>
            </div>
        </div>
    <?php
}
}
?>