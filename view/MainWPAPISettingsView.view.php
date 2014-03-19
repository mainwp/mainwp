<?php
class MainWPAPISettingsView
{
    public static function initMenu()
    {
//        add_submenu_page('plugins.php', __('MainWP Configuration', 'mainwp'), __('MainWP Configuration', 'mainwp'), 'manage_options', 'mainwp-config', array(MainWPAPISettings::getClassName(), 'render'));
    }

    public static function maximumInstallationsReached()
    {
        return __('Maximum number of main installations on your MainWP plan have been reached. <a href="http://mainwp.com/member/login/index" target="_blank">Upgrade your plan for more sites</a>','mainwp');
    }

    public static function render()
    {
        $username = get_option("mainwp_api_username");
        $password = get_option("mainwp_api_password");
        $userExtension = MainWPDB::Instance()->getUserExtension();
        $pluginDir = (($userExtension == null) || (($userExtension->pluginDir == null) || ($userExtension->pluginDir == '')) ? 'default' : $userExtension->pluginDir);
        ?>
    <div class="wrap"><a href="http://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
            src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50" alt="MainWP"/></a>
        <img src="<?php echo plugins_url('images/icons/mainwp-passwords.png', dirname(__FILE__)); ?>"
             style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Password" height="32"/>

        <h2><?php _e('MainWP Login Settings','mainwp'); ?></h2>

        <div id="mainwp_api_errors" class="mainwp_error error" style="display: none"></div>
        <div id="mainwp_api_message" class="mainwp_updated updated" style="display: none"></div>
        <br />
        <div id="" class="mainwp_info-box-red"><font size="3"><strong><?php _e('Stop, before installing!','mainwp'); ?></strong></font><br/><br/>

                            <strong><?php _e('We HIGHLY recommend a NEW WordPress install for your Main Dashboard.','mainwp'); ?></strong><br/><br/>

                            <?php _e('Using a new WordPress install will help to cut down on Plugin Conflicts and other issues that can be caused by trying to run your MainWP Main Dashboard off an active site. Most hosting companies provide free subdomains ("<strong>demo.yourdomain.com</strong>") and we recommend creating one if you do not have a specific dedicated domain to run your Network Main Dashboard.<br/><br/>

                            If you are not sure how to set up a subdomain here is a quick step by step with <a href="http://docs.mainwp.com/creating-a-subdomain-in-cpanel/">cPanel</a>, <a href="http://docs.mainwp.com/creating-a-subdomain-in-plesk/">Plesk</a> or <a href="http://docs.mainwp.com/creating-a-subdomain-in-directadmin-control-panel/">Direct Admin</a>. If you are not sure what you have, contact your hosting companies support.','mainwp'); ?></div>
        <br />
        <h3><?php _e('Initial MainWP Settings','mainwp'); ?></h3>
        <table class="form-table">
            <tbody>
            <tr>
                <th scope="row"><?php _e('Hide Network on Child Sites','mainwp'); ?></th>
                <td>
                    <table>
                        <tr>
                            <td valign="top" style="padding-left: 0; padding-right: 5px; padding-top: 0px; padding- bottom: 0px;">
                                <input type="checkbox" value="default" name="mainwp_options_footprint_plugin_folder" id="mainwp_options_footprint_plugin_folder_default" <?php echo ($pluginDir == 'hidden' ? 'checked="true"' : ''); ?>/>
                            </td>
                            <td valign="top" style="padding: 0">
                              <label for="mainwp_options_footprint_plugin_folder_default">
                                  <?php _e('This will make anyone including Search Engines trying find your Child Plugin encounter a 404 page. Hiding the Child Plugin does require the plugin to make changes to your .htaccess file that in rare instances or server configurations could cause problems.','mainwp'); ?>
                              </label>
                              <div class="mainwp_info-box" style="width: 650px; font-weight: bold; margin-top: 5px;"><?php _e('We recommend you have this option checked. You can change these settings any time on the settings page.','mainwp'); ?></div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>

        <h3><?php _e('MainWP login','mainwp'); ?></h3>
        <table class="form-table">
            <tbody>
            <tr>
                <th scope="row"><label for="mainwp_api_username"><?php _e('Username','mainwp'); ?></label></th>
                <td>
                    <input type="text" name="mainwp_api_username" id="mainwp_api_username" size="35"
                           value="<?php echo $username; ?>"/>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="mainwp_api_password"><?php _e('Password','mainwp'); ?></label></th>
                <td>
                    <input type="password" name="mainwp_api_password" id="mainwp_api_password" size="35"
                           value="<?php echo $password; ?>"/>
                </td>
            </tr>
            </tbody>
        </table>
        <p class="submit">
            <span style="font-size: 14px; font-weight: bold; ">Step 1:  </span><input type="button" name="submit" id="mainwp-api-test" class="button-primary" value="<?php _e('Test Login','mainwp'); ?>"/><br/><br/>
            <span style="font-size: 14px; font-weight: bold; ">Step 2:  </span><input type="button" name="submit" id="mainwp-api-submit" class="button-primary" value="<?php _e('Save Settings','mainwp'); ?>" <?php if (!MainWPSystem::Instance()->isAPIValid()) { ?> disabled="disabled" <?php } ?>/>
        </p>
    </div>
    <?php
    }

    public static function renderSettings() {
        $username = get_option("mainwp_api_username");
        $password = get_option("mainwp_api_password");
        ?>
        <fieldset class="mainwp-fieldset-box">
        <div id="mainwp_api_errors" class="mainwp_error error" style="display: none"></div>
        <div id="mainwp_api_message" class="mainwp_updated updated" style="display: none"></div>
        <legend><?php _e('MainWP Account Information','mainwp'); ?></legend>
        <div id="" class="mainwp_info-box-red"><font size="3"><strong><?php _e('Stop, before installing!','mainwp'); ?></strong></font><br/><br/>

                            <strong><?php _e('We HIGHLY recommend a NEW WordPress install for your Main Dashboard.','mainwp'); ?></strong><br/><br/>

                            <?php _e('Using a new WordPress install will help to cut down on Plugin Conflicts and other issues that can be caused by trying to run your MainWP Main Dashboard off an active site. Most hosting companies provide free subdomains ("<strong>demo.yourdomain.com</strong>") and we recommend creating one if you do not have a specific dedicated domain to run your Network Main Dashboard.<br/><br/>

                            If you are not sure how to set up a subdomain here is a quick step by step with <a href="http://docs.mainwp.com/creating-a-subdomain-in-cpanel/">cPanel</a>, <a href="http://docs.mainwp.com/creating-a-subdomain-in-plesk/">Plesk</a> or <a href="http://docs.mainwp.com/creating-a-subdomain-in-directadmin-control-panel/">Direct Admin</a>. If you are not sure what you have, contact your hosting companies support.','mainwp'); ?></div>
        <br />
        <h3><?php _e('MainWP Account - <em>Required for Support, Extensions, Ideas and Automated Cron Jobs</em>','mainwp'); ?>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="http://mainwp.com/dashboard-signup" target="_blank" class="button button-primary mainwp-upgrade-button button-hero" style="margin-top: -1em"><?php _e('Create MainWP Account', 'mainwp'); ?></a></h3>
        <table class="form-table">
            <tbody>
            <tr>
                <th scope="row"><label for="mainwp_api_username"><?php _e('Username','mainwp'); ?></label></th>
                <td>
                    <input type="text" name="mainwp_api_username" id="mainwp_api_username" size="35"
                           value="<?php echo $username; ?>"/>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="mainwp_api_password"><?php _e('Password','mainwp'); ?></label></th>
                <td>
                    <input type="password" name="mainwp_api_password" id="mainwp_api_password" size="35"
                           value="<?php echo $password; ?>"/>
                </td>
            </tr>
            <span class="submit">
            <tr>
            <th scope="row"><label style="font-size: 14px; font-weight: bold; ">Step 1:</label></th><td><input type="button" name="submit" id="mainwp-api-test" class="button-primary" value="<?php _e('Test Login','mainwp'); ?>"/></td>
            </tr>
            <tr>
            <th scope="row"><label style="font-size: 14px; font-weight: bold; ">Step 2:</label></th><td><input type="button" name="submit" id="mainwp-api-submit" class="button-primary" value="<?php _e('Save Settings','mainwp'); ?>" <?php if (!MainWPSystem::Instance()->isAPIValid()) { ?> disabled="disabled" <?php } ?>/></td>
            </tr>
            </span>
            </tbody>
            </table>
        </p>
    </fieldset>
        <?php
    }
}