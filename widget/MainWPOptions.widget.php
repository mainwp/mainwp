<?php
class MainWPOptions
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function handleSettingsPost()
    {
        if (isset($_POST['submit'])) {
            $userExtension = MainWPDB::Instance()->getUserExtension();
            $userExtension->user_email = $_POST['mainwp_options_email'];
            $userExtension->site_view = (!isset($_POST['mainwp_options_siteview']) ? 0 : 1);

            $userExtension->heatMap = (!isset($_POST['mainwp_options_footprint_heatmap']) ? 1 : 0);
            $userExtension->pluginDir = (isset($_POST['mainwp_options_footprint_plugin_folder']) ? $_POST['mainwp_options_footprint_plugin_folder'] : 'default');

            MainWPDB::Instance()->updateUserExtension($userExtension);
            if (MainWPUtility::isAdmin()) {
                MainWPUtility::update_option('mainwp_optimize', (!isset($_POST['mainwp_optimize']) ? 0 : 1));
                MainWPUtility::update_option('mainwp_seo', (!isset($_POST['mainwp_seo']) ? 0 : 1));
                $val = (!isset($_POST['mainwp_automaticDailyUpdate']) ? 2 : $_POST['mainwp_automaticDailyUpdate']);
                MainWPUtility::update_option('mainwp_automaticDailyUpdate', $val);
                $val = (!isset($_POST['mainwp_backup_before_upgrade']) ? 0 : 1);
                MainWPUtility::update_option('mainwp_backup_before_upgrade', $val);
                MainWPUtility::update_option('mainwp_maximumPosts', $_POST['mainwp_maximumPosts']);
                MainWPUtility::update_option('mainwp_maximumComments', $_POST['mainwp_maximumComments']);
                MainWPUtility::update_option('mainwp_cron_jobs', (!isset($_POST['mainwp_options_cron_jobs']) ? 0 : 1));
                MainWPUtility::update_option('mainwp_wp_cron', (!isset($_POST['mainwp_options_wp_cron']) ? 0 : 1));
            }

            return true;
        }
        return false;
    }

    public static function renderSettings()
    {
        $userExtension = MainWPDB::Instance()->getUserExtension();
        $pluginDir = (($userExtension == null) || (($userExtension->pluginDir == null) || ($userExtension->pluginDir == '')) ? 'default' : $userExtension->pluginDir);
        $user_email = MainWPUtility::getNotificationEmail();
        $siteview = $userExtension->site_view;
        $snAutomaticDailyUpdate = get_option('mainwp_automaticDailyUpdate');
        $backup_before_upgrade = get_option('mainwp_backup_before_upgrade');
        $lastAutomaticUpdate = MainWPDB::Instance()->getWebsitesLastAutomaticSync();

        if ($lastAutomaticUpdate == 0)
        {
            $nextAutomaticUpdate = 'Any minute';
        }
        else if (MainWPDB::Instance()->getWebsitesCountWhereDtsAutomaticSyncSmallerThenStart() > 0 || MainWPDB::Instance()->getWebsitesCheckUpdatesCount() > 0)
        {
            $nextAutomaticUpdate = 'Processing your websites.';
        }
        else
        {
            $nextAutomaticUpdate = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp(mktime(0, 0, 0, date('n'), date('j') + 1)));
        }

        if ($lastAutomaticUpdate == 0) $lastAutomaticUpdate = 'Never';
        else $lastAutomaticUpdate = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($lastAutomaticUpdate));
        ?>
    <div class="postbox" id="mainwp-hide-child-plugin-settings">
        <h3 class="mainwp_box_title"><span><?php _e('Hide MainWP Child Plugin','mainwp'); ?></span></h3>
        <div class="inside">
        <div class="mainwp_info-box-red" style="margin-top: 5px;"><?php _e('<strong>STOP BEFORE TURNING ON!</strong> Hiding the Child Plugin does require the plugin to make changes to your .htaccess file that in rare instances or server configurations could cause problems.','mainwp'); ?></div>
        <table class="form-table">
            <tbody>
            <tr>
                <th scope="row"><?php _e('Hide Network on Child Sites','mainwp'); ?></th>
                <td>
                    <table>
                        <tr>
                            <td valign="top" style="padding-left: 0; padding-right: 5px; padding-top: 0px; padding-bottom: 0px; vertical-align: top;">
                                <div class="mainwp-checkbox">
                                <input type="checkbox" value="hidden" name="mainwp_options_footprint_plugin_folder" id="mainwp_options_footprint_plugin_folder_default" <?php echo ($pluginDir == 'hidden' ? 'checked="true"' : ''); ?>/><label for="mainwp_options_footprint_plugin_folder_default"></label>
                            </div>
                            </td>
                            <td valign="top" style="padding: 0">
                              <label for="mainwp_options_footprint_plugin_folder_default">
                                  <em><?php _e('This will make anyone including Search Engines trying find your Child Plugin encounter a 404 page. Hiding the Child Plugin does require the plugin to make changes to your .htaccess file that in rare instances or server configurations could cause problems.','mainwp'); ?></em>
                              </label>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
    </div>

    <div class="postbox" id="mainwp-global-options-settings">
    <h3 class="mainwp_box_title"><span><?php _e('Global Options','mainwp'); ?></span></h3>
    <div class="inside">
    <table class="form-table">
        <tbody>
        <tr>
            <th scope="row"><?php _e('Notification Email','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('This address is used to send monitoring alerts.','mainwp')); ?></th>
            <td>
                <input type="text"  class="mainwp-field mainwp-email" name="mainwp_options_email" size="35" value="<?php echo $user_email; ?>"/><span class="mainwp-form_hint"><?php _e('This address is used to send monitoring alerts.','mainwp'); ?></span>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Use WP-Cron','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('When not using WP-Cron you will need to set up a cron job via your hosting.','mainwp'), 'http://docs.mainwp.com/disable-wp-cron/'); ?></th>
            <td>
                <div class="mainwp-checkbox">
                <input type="checkbox" name="mainwp_options_wp_cron"
                       id="mainwp_options_wp_cron" <?php echo ((get_option('mainwp_wp_cron') == 1) || (get_option('mainwp_wp_cron') === false) ? 'checked="true"' : ''); ?>/>
                <label for="mainwp_options_wp_cron"></label>
                </div>
            </td>
        </tr>
<!--        todo: RS: Re-enable-->
<!--        <tr>-->
<!--            <th scope="row">Tips on login</th>-->
<!--            <td>-->
<!--                <input type="checkbox" name="mainwp_options_tips"-->
<!--                       id="mainwp_options_tips" --><?php //echo ($userExtension->tips == 1 ? 'checked="true"' : ''); ?><!--"/>-->
<!--                <label for="mainwp_options_tips">Enable "Did you know" tips</label>-->
<!--            </td>-->
<!--        </tr>-->
        <?php if (MainWPUtility::isAdmin()) { ?>
        <tr>
            <th scope="row"><?php _e('Optimize for big networks','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('Updates will be cached for quick loading. A manual refresh from the Dashboard is required to view new plugins, themes, pages or users. Recommended for Networks over 50 sites.','mainwp')); ?></th>
            <td>
            	<div class="mainwp-checkbox">
                <input type="checkbox" name="mainwp_optimize"
                       id="mainwp_optimize" <?php echo ((get_option('mainwp_optimize') == 1) ? 'checked="true"' : ''); ?>"/>
                <label for="mainwp_optimize"></label>
               </div>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Basic SEO Stats','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('This requires your Dashboard to query the Google servers for this information.','mainwp')); ?></th>
            <td>
            	<div class="mainwp-checkbox">
                <input type="checkbox" name="mainwp_seo"
                       id="mainwp_seo" <?php echo ((get_option('mainwp_seo') == 1) ? 'checked="true"' : ''); ?>"/>
                <label for="mainwp_seo"></label>
               </div>
            </td>
        </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>
    </div>

    <div class="postbox" id="mainwp-upgrade-options-settings">
    <h3 class="mainwp_box_title"><span><?php _e('Upgrade Options','mainwp'); ?></span></h3>
    <div class="inside">
    <table class="form-table">
        <tbody>
        <tr>
            <th scope="row"><?php _e('View upgrades per site','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('When this is disabled, the upgrades are shown per plugin/theme with a sublist of sites. When this is enabled, all the sites are shown with the plugin/theme upgrades available per site.','mainwp')); ?></th>
            <td>
            	<div class="mainwp-checkbox">
                <input type="checkbox" name="mainwp_options_siteview" id="mainwp_options_siteview" size="35" <?php echo ($siteview == 1 ? 'checked="true"' : ''); ?>/> <label for="mainwp_options_siteview"></label>
               </div>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Require backup before upgrade','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('With this option enabled, when you try to upgrade a plugin, theme or WordPress core, MainWP will check if there is a full backup created for the site(s) you are trying to upgrade in last 7 days. If you have a fresh backup of the site(s) MainWP will proceed to the upgrade process, if not it will ask you to create a full backup.','mainwp')); ?></th>
            <td>
            	<div class="mainwp-checkbox">
                <input type="checkbox" name="mainwp_backup_before_upgrade" id="mainwp_backup_before_upgrade" size="35" <?php echo ($backup_before_upgrade == 1 ? 'checked="true"' : ''); ?>/> <label for="mainwp_backup_before_upgrade"></label>
               </div>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Automatic daily update','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('Choose to have MainWP install updates, or notify you by email of available updates.  Updates apply to WordPress Core files, Plugins and Themes.','mainwp')); ?></th>
            <td>
                <table class="mainwp-nomarkup">
                    <tr>
                        <td valign="top">
                            <span class="mainwp-select-bg"><select name="mainwp_automaticDailyUpdate" id="mainwp_automaticDailyUpdate">
                                <option value="2" <?php if (($snAutomaticDailyUpdate === false) || ($snAutomaticDailyUpdate == 2)) {
                                    ?>selected<?php } ?>>E-mail Notifications of New Updates
                                </option>
                                <option value="1" <?php if ($snAutomaticDailyUpdate == 1) {
                                    ?>selected<?php } ?>>Install Trusted Updates
                                </option>
                                <option value="0" <?php if ($snAutomaticDailyUpdate !== false && $snAutomaticDailyUpdate == 0) {
                                    ?>selected<?php } ?>>Off
                                </option>
                            </select><label></label></span>
                        </td>
                        <td>
                            &nbsp;&nbsp;Last run: <?php echo $lastAutomaticUpdate; ?>
                            <br />&nbsp;&nbsp;Next run: <?php echo $nextAutomaticUpdate; ?>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        </tbody>
    </table>
    </div>
    </div>
    
    <div class="postbox" id="mainwp-date-return-options-settings">
    <h3 class="mainwp_box_title"><span><?php _e('Data Return Options','mainwp'); ?></span></h3>
    <div class="inside">
    <table class="form-table">
        <tbody>
        <tr>
            <th scope="row"><?php _e('Maximum number of Posts/Pages','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('0 for unlimited, CAUTION: a large amount will decrease the speed and might crash the communication.','mainwp')); ?></th>
            <td>
                <input type="text" name="mainwp_maximumPosts"  class="mainwp-field mainwp-settings-icon"
                       id="mainwp_maximumPosts" value="<?php echo ((get_option('mainwp_maximumPosts') === false) ? 50 : get_option('mainwp_maximumPosts')); ?>"/>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Maximum number of Comments','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('0 for unlimited, CAUTION: a large amount will decrease the speed and might crash the communication.','mainwp')); ?></th>
            <td>
                <input type="text" name="mainwp_maximumComments" class="mainwp-field mainwp-settings-icon"
                       id="mainwp_maximumComments" value="<?php echo ((get_option('mainwp_maximumComments') === false) ? 50 : get_option('mainwp_maximumComments')); ?>"/>
            </td>
        </tr>
        </tbody>
    </table>
    </div>
    </div>
    <?php
    }
}

?>
