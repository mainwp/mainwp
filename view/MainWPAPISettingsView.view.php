<?php
class MainWPAPISettingsView
{
    public static function initMenu()
    {
//        add_submenu_page('plugins.php', __('MainWP Configuration', 'mainwp'), __('MainWP Configuration', 'mainwp'), 'manage_options', 'mainwp-config', array(MainWPAPISettings::getClassName(), 'render'));
    }
    public static function renderForumSignup()
    {
        ?>
        <div class="postbox" style="padding: 1em;">
        <h3 style="border-bottom: none !important;"><?php _e('Have a question? Would you like to discuss MainWP with other users?', 'mainwp'); ?>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
        <a href="https://mainwp.com/member/signup/index/c/forumsignup" target="_blank" class="button button-primary mainwp-upgrade-button button-hero" style="margin-top: -1em"><?php _e('Sign Up Here', 'mainwp'); ?></a></h3>
        </div>
        <?php
    }

}