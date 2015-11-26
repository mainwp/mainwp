<?php
class MainWPOfflineChecks
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function initMenu()
    {       
        add_submenu_page('mainwp_tab', __('Offline Checks','mainwp'), ' <div class="mainwp-hidden">' . __('Offline Checks','mainwp') . '</div>', 'read', 'OfflineChecks', array(MainWPOfflineChecks::getClassName(), 'render'));
    }

    public static function renderFooter($shownPage) {
        ?>
          </div>
      </div>
        <?php
    }

    public static function render()
    {
        if (!mainwp_current_user_can("dashboard", "manage_offline_checks")) {
            mainwp_do_not_have_permissions("manage offline checks");
            return;
        }
        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
        $statusses = array('hourly', '2xday', 'daily', 'weekly');

         do_action("mainwp-pageheader-settings", "OfflineChecks");

        ?>
        <div class="mainwp_info-box-red">
        <strong>IMPORTANT:</strong> This feature is being retired and replaced by the Free MainWP Advanced Uptime Monitor Extension which provides more advanced monitoring system.<br/>
        <a href="https://extensions.mainwp.com/product/mainwp-advanced-uptime-monitor/">Get the Free MainWP Advanced Uptime Monitor Extension here!</a>
        </div>
        <div class="mainwp_info-box">
            <strong><?php _e('Notifications will be sent to','mainwp'); ?> <i><?php echo MainWPUtility::getNotificationEmail(); ?></i> (<a href="<?php echo admin_url(); ?>admin.php?page=Settings"><?php _e('change','mainwp'); ?></a>)</strong>
            <br /><br /><?php _e('MainWP performs two tests when checking your site for up-time.','mainwp'); ?>
            <br />
            <?php _e('The first test we do is to check that the domain is valid.','mainwp'); ?>
            <br />
            <?php _e('If this test passes we use a browser emulator to visit the website, this sends out a user agent (just like your web browser from your computer) and waits for a status message.','mainwp'); ?>
            <br />
            <?php _e('We report any http status code from 200-399 as a success. Any other http status code returned is considered "offline" which is treated as a failure.','mainwp'); ?>
        </div>
        <table class="wp-list-table widefat fixed" id="mainwp_offlinechecks">
            <thead>
                <tr>
                    <th scope="col" class="manage-column" style="text-align: left"><?php _e('Site','mainwp'); ?></th>
                    <th scope="col" id="col_status" class="manage-column"><?php _e('Status','mainwp'); ?></th>
                    <th scope="col" id="col_disabled" class="manage-column"><a href="#" class="mainwp_offline_check_bulk" value="disabled"><?php _e('Disabled','mainwp'); ?></a></th>
                    <th scope="col" id="col_hourly" class="manage-column"><a href="#" class="mainwp_offline_check_bulk" value="hourly"><?php _e('Hourly','mainwp'); ?></a></th>
                    <th scope="col" id="col_2timesday" class="manage-column"><a href="#" class="mainwp_offline_check_bulk" value="2xday"><?php _e('2x Day','mainwp'); ?></a></th>
                    <th scope="col" id="col_daily" class="manage-column"><a href="#" class="mainwp_offline_check_bulk" value="daily"><?php _e('Daily','mainwp'); ?></a></th>
                    <th scope="col" id="col_weekly" class="manage-column"><a href="#" class="mainwp_offline_check_bulk" value="weekly"><?php _e('Weekly','mainwp'); ?></a></th>
                    <th scope="col" id="col_test" class="manage-column"><a href="#" class="button button-primary" id="mainwp_offline_check_check_all"><?php _e('Check All','mainwp'); ?></a></th>
                </tr>
            </thead>
            <tbody id="the-list">
            <?php
            while ($websites && ($website = @MainWPDB::fetch_object($websites)))
            {
                ?>
                <tr>
                    <input type="hidden" name="offline_check_website_id" id="offline_check_website_id"
                           value="<?php echo $website->id; ?>" />
                    <td class="url"><a href="admin.php?page=managesites&dashboard=<?php echo $website->id; ?>"><?php echo stripslashes($website->name);?></a> <span class="offline_check_saved"><?php _e('Saved','mainwp'); ?></span></td>
                    <td>
                        <i class="fa fa-exclamation-circle fa-2x mwp-red" title="Site Offline" <?php echo ($website->offline_check_result == -1 ? '' : 'style="display:none;"'); ?>></i>
                        <i class="fa fa-check-circle fa-2x mwp-l-green" title="Site Online" <?php echo ($website->offline_check_result == 1 ? '' : 'style="display:none;"'); ?>></i>
                    </td>
                    <td class="column-rating"><input type="radio" id="disabled" class="mainwp_offline_check" value="disabled"
                               name="offline_check_<?php echo $website->id; ?>"
                        <?php echo (!in_array($website->offline_checks, $statusses) ? 'checked="true"' : ''); ?> /></td>
                    <td><input type="radio" id="hourly" class="mainwp_offline_check" value="hourly"
                               name="offline_check_<?php echo $website->id; ?>"
                        <?php echo ($website->offline_checks == 'hourly' ? 'checked="true"' : ''); ?> /></td>
                    <td><input type="radio" id="2xday" class="mainwp_offline_check" value="2xday"
                               name="offline_check_<?php echo $website->id; ?>"
                        <?php echo ($website->offline_checks == '2xday' ? 'checked="true"' : ''); ?> /></td>
                    <td><input type="radio" id="daily" class="mainwp_offline_check" value="daily"
                               name="offline_check_<?php echo $website->id; ?>"
                        <?php echo ($website->offline_checks == 'daily' ? 'checked="true"' : ''); ?> /></td>
                    <td><input type="radio" id="weekly" class="mainwp_offline_check" value="weekly"
                               name="offline_check_<?php echo $website->id; ?>"
                        <?php echo ($website->offline_checks == 'weekly' ? 'checked="true"' : ''); ?> /></td>
                    <td><a href="#" class="mainwp_offline_check_check"><?php _e('Check','mainwp'); ?></a></td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    <?php
        do_action("mainwp-pagefooter-settings", "OfflineChecks");
    }

    public static function updateWebsite()
    {
        if (!isset($_POST['websiteid']) || !isset($_POST['offline_check']))
        {
            return '0';
        }

        $website = MainWPDB::Instance()->getWebsiteById($_POST['websiteid']);
        if ($website == null) return 0;
        
        if (!MainWPUtility::can_edit_website($website))
        {
            return '0';
        }

        MainWPDB::Instance()->updateWebsiteOfflineCheckSetting($website->id, $_POST['offline_check']);


        return '1';
    }

    public static function updateWebsites()
    {
        if (!isset($_POST['offline_check']))
        {
            return '0';
        }

        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
        {
            MainWPDB::Instance()->updateWebsiteOfflineCheckSetting($website->id, $_POST['offline_check']);
        }
        @MainWPDB::free_result($websites);

        return '1';
    }

    public static function checkWebsite()
    {
        if (!isset($_POST['websiteid']))
        {
            //Check all websites
            $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
        }
        else
        {
            $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsiteById($_POST['websiteid']));
            if (!$websites) return 0;
        }

        $output = array();

        if (!$websites)
        {
            $emailOutput = '';
        }
        else
        {
            $emailOutput = null;
        }
        $errors = false;
        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
        {
            if (self::performCheck($website, true, $emailOutput))
                $output[$website->id] = 1;
            else
            {
                $output[$website->id] = -1;
                $errors = true;
            }
        }
        @MainWPDB::free_result($websites);
        if ($emailOutput != null)
        {
            if ($errors)
            {
                $emailOutput .= '<br /><br />Please take a look at the issues and make sure everything is ok.';
            }
            $email = MainWPDB::Instance()->getUserNotificationEmail($website->userid);
            wp_mail($email, ($errors ? 'Down Time Alert - MainWP' : 'Up Time Alert - MainWP'), MainWPUtility::formatEmail($email, $emailOutput), array('From: "'.get_option('admin_email').'" <'.get_option('admin_email').'>', 'content-type: text/html'));
        }

        return array('result' => $output);
    }

    public static function performAllChecks()
    {
        $websites = MainWPDB::Instance()->getOfflineChecks();
        foreach ($websites as $website)
        {
            if ($website->sync_errors != '')
            {
                try
                {
                    //Add
                    if (function_exists('openssl_pkey_new'))
                    {
                        $conf = array('private_key_bits' => 384);
	                    $conf_loc = MainWPSystem::get_openssl_conf();
	                    if (!empty($conf_loc))
		                    $conf['config'] = $conf_loc;

                        $res = openssl_pkey_new($conf);                                                          
                        @openssl_pkey_export($res, $privkey, NULL, $conf);
                        $pubkey = openssl_pkey_get_details($res);
                        $pubkey = $pubkey["key"];
                    }
                    else
                    {
                        $privkey = '-1';
                        $pubkey = '-1';
                    }

                    $information = MainWPUtility::fetchUrlNotAuthed($website->url, $website->adminname, 'register', array('pubkey' => $pubkey, 'server' => get_admin_url()), false, $website->verify_certificate, $website->http_user, $website->http_pass);

                    if (!isset($information['error']) || ($information['error'] == ''))
                    {
                        if (isset($information['register']) && $information['register'] == 'OK')
                        {
                            //Update website
                            MainWPDB::Instance()->updateWebsiteValues($website->id, array('pubkey' => base64_encode($pubkey), 'privkey' => base64_encode($privkey), 'nossl' => $information['nossl'], 'nosslkey' => (isset($information['nosslkey']) ? $information['nosslkey'] : ''), 'uniqueId' =>  (isset($information['uniqueId']) ? $information['uniqueId'] : '')));
                            $message = 'Site successfully reconnected';
                            MainWPSync::syncInformationArray($website, $information);
                        }
                    }
                }
                catch (Exception $e)
                {

                }
            }
            self::performCheck($website);
        }
    }

    public static function performCheck($website, $sendOnline = false, &$emailOutput = null)
    {
        $result = MainWPUtility::isWebsiteAvailable($website);
        if (!$result || (isset($result['error']) && ($result['error'] != '')) || ($result['httpCode'] != '200')) {
            MainWPDB::Instance()->updateWebsiteValues($website->id, array('offline_check_result' => '-1', 'offline_checks_last' => time()));
            $body = 'We\'ve had some issues trying to reach your website <a href="' . $website->url . '">' . stripslashes($website->name) . '</a>. ' . (isset($result['error']) && ($result['error'] != '') ? ' Error message: '. $result['error'] . '.' : 'Received HTTP-code: ' . $result['httpCode'] . ($result['httpCodeString'] != '' ? ' (' . $result['httpCodeString'] . ').' : ''));
            if ($emailOutput === null) {
                $email = MainWPDB::Instance()->getUserNotificationEmail($website->userid);
                wp_mail($email, 'Down Time Alert - MainWP', MainWPUtility::formatEmail($email, $body . '<br /><br />Please take a look at the <a href="' . $website->url . '">website</a> and make sure everything is ok.'), array('From: "'.get_option('admin_email').'" <'.get_option('admin_email').'>', 'content-type: text/html'));
            }
            else $emailOutput .= ($emailOutput != '' ? '<br />' : '') . $body;
            return false;
        }
        else
        {
            MainWPDB::Instance()->updateWebsiteValues($website->id, array('offline_check_result' => '1', 'offline_checks_last' => time()));
            $userExtension = MainWPDB::Instance()->getUserExtensionByUserId($website->userid);
            if ($sendOnline || $userExtension->offlineChecksOnlineNotification == 1)
            {
                $body = 'Your website <a href="' . $website->url . '">' . stripslashes($website->name) . '</a> is up and responding as expected!';
                //if set in config!
                if ($emailOutput === null) {
                    $email = MainWPDB::Instance()->getUserNotificationEmail($website->userid);
                    wp_mail($email, 'Up Time Alert - MainWP', MainWPUtility::formatEmail($email, $body), array('From: "'.get_option('admin_email').'" <'.get_option('admin_email').'>', 'content-type: text/html'));
                }
                else $emailOutput .= ($emailOutput != '' ? '<br />' : '') . $body;
            }
            return true;
        }
    }

    public static function handleSettingsPost()
    {
        if (isset($_POST['submit'])) {
            $userExtension = MainWPDB::Instance()->getUserExtension();
            $userExtension->offlineChecksOnlineNotification = (!isset($_POST['mainwp_options_offlinecheck_onlinenotification']) ? 0 : 1);
            MainWPDB::Instance()->updateUserExtension($userExtension);
            return true;
        }
        return false;
    }

    public static function renderSettings()
    {
        $userExtension = MainWPDB::Instance()->getUserExtension();
        $onlineNotifications = (($userExtension == null) || (($userExtension->offlineChecksOnlineNotification == null) || ($userExtension->offlineChecksOnlineNotification == '')) ? '0' : $userExtension->offlineChecksOnlineNotification);
        ?>
    <div class="postbox" id="mainwp-offline-check-options-settings">
    <h3 class="mainwp_box_title"><span><i class="fa fa-cog"></i> <?php _e('Offline Check Options','mainwp'); ?></span></h3>
    <div class="inside">
    <div class="mainwp_info-box-red">
        <strong>IMPORTANT:</strong> This feature is being retired and replaced by the Free MainWP Advanced Uptime Monitor Extension which provides more advanced monitoring system.<br/>
        <a href="https://extensions.mainwp.com/product/mainwp-advanced-uptime-monitor/">Get the Free MainWP Advanced Uptime Monitor Extension here!</a>
        </div>
    <table class="form-table">
        <tbody>
        <tr>
            <th scope="row"><?php _e('Online Notifications','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('Network will monitor your sites for downtime and uptime.  By default emails are only sent when your site is down.','mainwp')); ?></th>
            <td>
            	<div class="mainwp-checkbox">
                <input type="checkbox" name="mainwp_options_offlinecheck_onlinenotification"
                       id="mainwp_options_offlinecheck_onlinenotification" <?php echo ($onlineNotifications == 1 ? 'checked="true"' : ''); ?> />
                <label for="mainwp_options_offlinecheck_onlinenotification"></label>
               </div><?php _e('Enable notifications even when the website is online','mainwp'); ?>
            </td>
        </tr>
        </tbody>
    </table>
    </div>
    </div>
    <?php
    }
   
}