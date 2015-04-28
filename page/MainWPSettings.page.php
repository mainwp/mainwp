<?php

class MainWPSettings
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static $subPages;

    public static function init()
    {
        add_action('mainwp-pageheader-settings', array(MainWPSettings::getClassName(), 'renderHeader'));
        add_action('mainwp-pagefooter-settings', array(MainWPSettings::getClassName(), 'renderFooter'));
    }

    public static function initMenu()
    {
        add_submenu_page('mainwp_tab', __('Settings Global options','mainwp'), ' <span id="mainwp-Settings">'. __('Settings','mainwp') .'</span>', 'read', 'Settings', array(MainWPSettings::getClassName(), 'render'));
        add_submenu_page('mainwp_tab', __('Settings Help','mainwp'), ' <div class="mainwp-hidden">'. __('Settings Help','mainwp') .'</div>', 'read', 'SettingsHelp', array(MainWPSettings::getClassName(), 'QSGManageSettings'));

        self::$subPages = apply_filters('mainwp-getsubpages-settings', array(array('title'=> __('Advanced Options', 'mainwp'), 'slug' => 'Advanced', 'callback' =>  array(MainWPSettings::getClassName(), 'renderAdvanced'))));
        if (isset(self::$subPages) && is_array(self::$subPages))
        {
            foreach (self::$subPages as $subPage)
            {
                add_submenu_page('mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Settings' . $subPage['slug'], $subPage['callback']);
            }
        }
    }

    public static function initMenuSubPages()
    {
        if (isset(self::$subPages) && is_array(self::$subPages) && (count(self::$subPages) > 0))
        {
        ?>
        <div id="menu-mainwp-Settings" class="mainwp-submenu-wrapper">
            <div class="wp-submenu sub-open" style="">
                <div class="mainwp_boxout">
                    <div class="mainwp_boxoutin"></div>
                    <a href="<?php echo admin_url('admin.php?page=Settings'); ?>" class="mainwp-submenu"><?php _e('Global Options','mainwp'); ?></a>
                    <?php
                        foreach (self::$subPages as $subPage)
                        {
                        ?>
                          <a href="<?php echo admin_url('admin.php?page=Settings' . $subPage['slug']); ?>"
                               class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
                        <?php
                        }
                    ?>
                    <a href="<?php echo admin_url('admin.php?page=OfflineChecks'); ?>" class="mainwp-submenu"><?php _e('Offline Checks','mainwp'); ?></a>
                </div>
            </div>
        </div>
        <?php
        }
    }

    public static function renderHeader($shownPage)
    {
        ?>
    <div class="wrap">
        <a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
                src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50"
                alt="MainWP"/></a>
        <h2><i class="fa fa-cogs"></i> <?php _e('MainWP Settings','mainwp'); ?></h2><div style="clear: both;"></div><br/>
         <div id="mainwp-tip-zone">
          <?php if ($shownPage == '') { ?>
             <?php if (MainWPUtility::showUserTip('mainwp-settings-tips')) { ?>
                <div class="mainwp-tips mainwp_info-box-blue"><span class="mainwp-tip" id="mainwp-settings-tips"><strong><?php _e('MainWP Tip','mainwp'); ?>: </strong><?php _e('The majority of these default settings can also be tweaked on the Site level by visiting Manage Sites &rarr; Edit Site.','mainwp'); ?></span><span><a href="#" class="mainwp-dismiss" ><i class="fa fa-times-circle"></i> <?php _e('Dismiss','mainwp'); ?></a></span></div>
              <?php } ?>   
          <?php } ?>
          <?php if ($shownPage == 'OfflineChecks') { ?>
                <?php if (MainWPUtility::showUserTip('mainwp-aumrecommend-tips')) { ?>
                <div class="mainwp-tips mainwp_info-box-blue"><span class="mainwp-tip" id="mainwp-aumrecommend-tips"><strong><?php _e('MainWP Tip','mainwp'); ?>: </strong><?php _e('We currently recommend the free <a href="https://extensions.mainwp.com/product/mainwp-advanced-uptime-monitor/" target="_blank">Advanced Uptime Monitor Extension</a> to perform more frequent tests.','mainwp'); ?></span><span><a href="#" class="mainwp-dismiss" ><i class="fa fa-times-circle"></i> <?php _e('Dismiss','mainwp'); ?></a></span></div>
                <?php } ?>
          <?php } ?>
        </div>
        <div class="mainwp-tabs" id="mainwp-tabs">
            <a class="nav-tab pos-nav-tab <?php if ($shownPage === '') { echo "nav-tab-active"; } ?>" href="admin.php?page=Settings"><?php _e('Global Options','mainwp'); ?></a>
            <a style="float: right" class="mainwp-help-tab nav-tab pos-nav-tab <?php if ($shownPage === 'SettingsHelp') { echo "nav-tab-active"; } ?>" href="admin.php?page=SettingsHelp"><?php _e('Help','mainwp'); ?></a>
            <?php
            if (isset(self::$subPages) && is_array(self::$subPages))
            {
                foreach (self::$subPages as $subPage)
                {
                ?>
                    <a class="nav-tab pos-nav-tab <?php if ($shownPage === $subPage['slug']) { echo "nav-tab-active"; } ?>" href="admin.php?page=Settings<?php echo $subPage['slug']; ?>"><?php echo $subPage['title']; ?></a>
                <?php
                }
            }
            ?>
            <a class="nav-tab pos-nav-tab <?php if ($shownPage === 'OfflineChecks') { echo "nav-tab-active"; } ?>" href="admin.php?page=OfflineChecks"><?php _e('Offline Checks','mainwp'); ?></a>
        </div>
        <div id="mainwp_wrap-inside">
        <?php
    }

    public static function renderFooter($shownPage)
    {
        ?>
        </div>
    </div>
        <?php
    }

    public static function renderAdvanced()
    {
        if (!mainwp_current_user_can("dashboard", "manage_dashboard_settings")) {
            mainwp_do_not_have_permissions("manage dashboard settings");
            return;
        }

        if (isset($_POST['submit']))
        {
            MainWPUtility::update_option('mainwp_maximumRequests', $_POST['mainwp_maximumRequests']);
            MainWPUtility::update_option('mainwp_minimumDelay', $_POST['mainwp_minimumDelay']);
            MainWPUtility::update_option('mainwp_maximumIPRequests', $_POST['mainwp_maximumIPRequests']);
            MainWPUtility::update_option('mainwp_minimumIPDelay', $_POST['mainwp_minimumIPDelay']);
            MainWPUtility::update_option('mainwp_sslVerifyCertificate', isset($_POST['mainwp_sslVerifyCertificate']) ? 1 : 0);
        }

        self::renderHeader('Advanced');
        ?>
    <form method="POST" action="" id="mainwp-settings-page-form">
    <div class="postbox" id="mainwp-advanced-options">
        <h3 class="mainwp_box_title"><span><i class="fa fa-cog"></i> <?php _e('Cross IP Settings','mainwp'); ?></span></h3>
        <div class="inside">

        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><?php _e('Maximum simultaneous requests','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('Maximum simultaneous requests. When too many requests are sent out, they will begin to time out. This will cause child sites to be shown as offline while they are online. With a typical shared host you should set this at 4, set to 0 for unlimited.','mainwp')); ?></th>
                    <td>
                        <input type="text" name="mainwp_maximumRequests" class="mainwp-field mainwp-settings-icon"
                               id="mainwp_maximumRequests" value="<?php echo ((get_option('mainwp_maximumRequests') === false) ? 4 : get_option('mainwp_maximumRequests')); ?>"/> <i>Default: 4</i>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Minimum delay between requests (milliseconds)','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('Minimum delay between requests (milliseconds). With a typical shared host you should set this at 200.','mainwp')); ?></th>
                    <td>
                        <input type="text" name="mainwp_minimumDelay"  class="mainwp-field mainwp-settings-icon"
                               id="mainwp_minimumDelay" value="<?php echo ((get_option('mainwp_minimumDelay') === false) ? 200 : get_option('mainwp_minimumDelay')); ?>"/> <i>Default: 200</i>
                    </td>
                </tr>
                 </tbody>
        </table>
        </div>
    </div>
    <div class="postbox" id="mainwp-advanced-options">
        <h3 class="mainwp_box_title"><span><i class="fa fa-cog"></i> <?php _e('IP Settings','mainwp'); ?></span></h3>
        <div class="inside">
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><?php _e('Maximum simultaneous requests per ip','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('Maximum simultaneous requests per IP. When too many requests are sent out, they will begin to time out. This will cause child sites to be shown as offline while they are online. With a typical shared host you should set this at 1, set to 0 for unlimited.','mainwp')); ?></th>
                    <td>
                        <input type="text" name="mainwp_maximumIPRequests"  class="mainwp-field mainwp-settings-icon"
                               id="mainwp_maximumIPRequests" value="<?php echo ((get_option('mainwp_maximumIPRequests') === false) ? 1 : get_option('mainwp_maximumIPRequests')); ?>"/> <i>Default: 1</i>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Minimum delay between requests to the same ip (milliseconds)','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('Minimum delay between requests (milliseconds) per IP. With a typical shared host you should set this at 1000.','mainwp')); ?></th>
                    <td>
                        <input type="text" name="mainwp_minimumIPDelay"  class="mainwp-field mainwp-settings-icon"
                               id="mainwp_minimumIPDelay" value="<?php echo ((get_option('mainwp_minimumIPDelay') === false) ? 1000 : get_option('mainwp_minimumIPDelay')); ?>"/> <i>Default: 1000</i>
                    </td>
                </tr>
                </tbody>
        </table>
        </div>
    </div>
    <div class="postbox" id="mainwp-advanced-options">
        <h3 class="mainwp_box_title"><span><i class="fa fa-cog"></i> <?php _e('SSL Settings','mainwp'); ?></span></h3>
        <div class="inside">
        <table class="form-table">
            </tbody>
                <tr><th scope="row"><?php _e('Verify certificate','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('Verify the childs SSL certificate. This should be disabled if you are using out of date or self signed certificates.','mainwp')); ?></th>
                   <td  style="width: 100px;">
                        <div class="mainwp-checkbox">
                            <input type="checkbox" name="mainwp_sslVerifyCertificate"
                               id="mainwp_sslVerifyCertificate" value="checked" <?php echo ((get_option('mainwp_sslVerifyCertificate') === false) || (get_option('mainwp_sslVerifyCertificate') == 1)) ? 'checked="checked"' : ''; ?>/><label for="mainwp_sslVerifyCertificate"></label>
                        </div>
                   </td>
                   <td><em><?php _e('Default: YES','mainwp'); ?></em></td>
                </tr>
            </tbody>
        </table>
    </div>
    </div>
    <p class="submit"><input type="submit" name="submit" id="submit" class="button-primary" value="<?php _e('Save Settings','mainwp'); ?>"/></p>
    </form>
        <?php
        self::renderFooter('Advanced');
    }

    public static function render()
    {
        if (!mainwp_current_user_can("dashboard", "manage_dashboard_settings")) {
            mainwp_do_not_have_permissions("manage dashboard settings");
            return;
        }

        $updated = MainWPOptions::handleSettingsPost();
        $updated |= MainWPManageSites::handleSettingsPost();
        $updated |= MainWPOfflineChecks::handleSettingsPost();
        $updated |= MainWPFootprint::handleSettingsPost();

        self::renderHeader(''); ?>
    <div class="mainwp_info-box"><strong><?php _e('Use this to set Global options.','mainwp'); ?></strong></div>
        <br/>
        <!--
            <div class="wrap"><a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50" alt="MainWP" /></a>
                <img src="<?php echo plugins_url('images/icons/mainwp-settings.png', dirname(__FILE__)); ?>" style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Settings" height="32"/><h2>MainWP Settings</h2>
              -->

        <?php if ($updated)
        {
            ?>
        <div id="ajax-information-zone" class="updated"><p><?php _e('Your settings have been saved.','mainwp'); ?></p></div>
        <?php
        }

        MainWPAPISettingsView::renderForumSignup();
            ?>

        <form method="POST" action="admin.php?page=Settings" id="mainwp-settings-page-form">
            <?php           
            
            MainWPOptions::renderSettings();
            
            MainWPManageSites::renderSettings();
            
            MainWPOfflineChecks::renderSettings();
            
            MainWPFootprint::renderSettings();            
            
            MainWPAPISettingsView::renderSettings();
            
            ?>
            <p class="submit"><input type="submit" name="submit" id="submit" class="button-primary" value="<?php _e('Save Settings','mainwp'); ?>"/>
            </p>             
        </form>
    <?php
        self::renderFooter('');
    }

    public static function QSGManageSettings() {
        self::renderHeader('SettingsHelp');
    ?><div style="text-align: center"><a href="#" class="button button-primary" id="mainwp-quick-start-guide"><?php _e('Show Quick Start Guide','mainwp'); ?></a></div>
                      <div  class="mainwp_info-box-yellow" id="mainwp-qsg-tips">
                          <span><a href="#" class="mainwp-show-qsg" number="1"><?php _e('Settings Overview','mainwp') ?></a></span><span><a href="#" id="mainwp-qsg-dismiss" style="float: right;"><i class="fa fa-times-circle"></i> <?php _e('Dismiss','mainwp'); ?></a></span>
                      <div class="clear"></div>
                      <div id="mainwp-qsgs">
                        <div class="mainwp-qsg" number="1">
                            <h3>Settings Overview</h3>
                            <p>
                                <ol>
                                    <li>
                                        <strong>Notification Email</strong> enables you to enter your email address where you want to receive Offline Monitoring alerts, Available Updates notifications and Backups notifications (when backup fails or starts if set in Backup Options section).
                                    <li>
                                        <strong>Allow us to count your sites</strong> - provides you ability to enable/disable MainWP from counting your managed sites. This info is used only to show a count of managed blogs. No other information is gathered but number of sites you have connected. Setting it to YES means that you allow us to get this info from your dashboard.
                                    </li>
                                    <li>
                                        <strong>Optimize for big networks</strong> uses a caching function. This option is recommended for networks with 50+ sites. If enabled (Set to YES)  updates will be cached for quick loading. A manual refresh from the Dashboard is required to view new plugins, themes, posts, pages, comments and users.
                                    </li>
                                    <li>
                                        <strong>Maximum request / 30 seconds</strong> sets a limit of requests sent to child sites per 30 seconds. Too menu requests can lead to child sites timing out and showing as offline while they are online. On the other side, lower number of requests leads to slower MainWP performance.
                                    </li>
                                    <li>
                                        <strong>View Upgrades per site</strong> - option enables you to choose whether you like to see your updates Per Site or Per Theme/Plugin. If enabled (set to YES) updates in the Right Now widget will be displayed per site, if disabled Updates will be displayed per Theme/Plugin.
                                    </li>
                                    <li>
                                        <strong>Require backup before upgrade</strong> with this option enabled, when you try to upgrade a plugin, theme or WordPress core, MainWP will check if there is a full backup created for the site(s) you are trying to upgrade in last 7 days. If you have a fresh backup of the site(s) MainWP will proceed to the upgrade process, if not it will ask you to create a full backup.
                                    </li>
                                    <li>
                                        <strong>Automatic daily updates</strong> MainWP gives you ability to set automatic updates.
                                    </li>
                                    <li>
                                        <strong>Data Return Options</strong> - In case you have large number of posts/comments, fetching all of them from a child site at once can overload the dashboard and decrease the speed. In worst case scenario, it can crash communication. Here you can set the maximum the maximum number of posts/comments per search
                                    </li>
                                    <li>
                                        <strong>Backups on server</strong> enables you to limit the number of backups you want to store on your server. If set to 3, MainWP will keep only 3 full backups for each of your sites. MainWP always replaces the oldest backup file. This option doesn't affect external sources
                                    </li>
                                    <li>
                                        <strong>Backups on external sources</strong> enables you to limit the number of stored backups on external sources such as Dropbox, Amazon S3 or FTP. This option does not affect the backups on server options. If you don't want to limit the number of backups on external sources, set this option to 0
                                    </li>
                                    <li>
                                        <strong>Send email when backup starts</strong> when scheduled backup starts, MainWP will notify you via email notification if this option is enabled (Set to YES). Notification will be sent to email address set in the Notification Email field.
                                    </li>
                                    <li>
                                        <strong>Execute backups in chunks</strong> - when setting a backup tasks with 5+ scheduled  sites, executing backups in chunks means that MainWP will backup 5 by 5 sites with 2 minutes pause between chunks.  By enabling this option, you can avoid server timing out while executing scheduled backup tasks.
                                    </li>
                                    <li>
                                        <strong>Online Notification</strong> by default MainWP sends notifications only when your sites are offline. With this option enabled, MainWP will send an email even if your site is online notifying you that everything is okay. Frequency of this emails depends on your settings in the MainWP > Offline Check page.
                                    </li>
                                    <li>
                                        <strong>New Account</strong> enables you to add new Google Analytics account. Here you can add multiple accounts. You need to be logged in your account, once you are logged click the Add GA Account and allow MainWP to access it. To add additional accounts, log out of you current GA account, log into another one and lick the button again.
                                    </li>
                                    <li>
                                        <strong>Accounts</strong> this option shows only when a GA account(s) are added to MainWP. Here you can Disconnect selected account by clicking the Disconnect button.
                                    </li>
                                    <li>
                                        <strong>Time Interval</strong> select the time interval for your GA account. You can choose between Weekly and Monthly setup. This will determine the way how your MainWP  GA widget displays statistics.
                                    </li>
                                    <li>
                                       <strong>Refresh Rate</strong> here you can set how often you want MainWP to check for new traffic data and new sites. Also use the Refresh Now button to refresh data on demand.
                                    </li>
                                    <li>
                                        <strong>Client Plugin folder options</strong> By default, files and folders on child sites are viewable. If you set to Hidden, MainWP will hide your files and folders. When hidden, if somebody tries to view your files it will return 404 file. However footprint does still exist.
                                    </li>
                                    <li>
                                        <strong>Turn Off Heatmap</strong> - By disabling Heatmaps (set to YES), you will remove the heatmap javascript footprint in the managed sites.
                                    </li>
                                </ol>
                            </p>
                        </div>
                      </div>
                    </div>
    <?php
    self::renderFooter('SettingsHelp');
    }
}

?>