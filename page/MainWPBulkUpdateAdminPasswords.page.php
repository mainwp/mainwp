<?php
/**
 * @see MainWPBulkAdd
 */
class MainWPBulkUpdateAdminPasswords
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function initMenu()
    {
        add_submenu_page('mainwp_tab', __('Admin Passwords','mainwp'), '<div class="mainwp-hidden">' . __('Admin Passwords','mainwp') . '</div>', 'read', 'UpdateAdminPasswords', array(MainWPBulkUpdateAdminPasswords::getClassName(), 'render'));        
    }

    public static function renderHeader($shownPage) {
    ?>
        <div class="wrap">
            <a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50" alt="MainWP" /></a>
                <img src="<?php echo plugins_url('images/icons/mainwp-passwords.png', dirname(__FILE__)); ?>" style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Passwords" height="32"/>
                <h2><?php _e('Update Admin Passwords','mainwp'); ?></h2>
                <div class="clear"></div>
                    <div class="mainwp-tabs" id="mainwp-tabs">
                        <a class="nav-tab pos-nav-tab <?php if ($shownPage === 'UpdateAdminPasswords') { echo "nav-tab-active"; } ?>" href="admin.php?page=UpdateAdminPasswords"><?php _e('Bulk update admin passwords','mainwp'); ?></a>
                        <a style="float: right" class="mainwp-help-tab nav-tab pos-nav-tab <?php if ($shownPage === 'AdminPasswordsHelp') { echo "nav-tab-active"; } ?>" href="admin.php?page=AdminPasswordsHelp"><?php _e('Help','mainwp'); ?></a>
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

    public static function render() {
        $show_form = true;

        if (isset($_POST['updateadminpassword'])) {
            check_admin_referer('mainwp_updateadminpassword', 'security');

            $errors = array();
            if (isset($_POST['select_by'])) {
                $selected_sites = array();
                if (isset($_POST['selected_sites']) && is_array($_POST['selected_sites'])) {
                    foreach ($_POST['selected_sites'] as $selected) {
                        $selected_sites[] = $selected;
                    }
                }

                $selected_groups = array();
                if (isset($_POST['selected_groups']) && is_array($_POST['selected_groups'])) {
                    foreach ($_POST['selected_groups'] as $selected) {
                        $selected_groups[] = $selected;
                    }
                }
                if (($_POST['select_by'] == 'group' && count($selected_groups) == 0) || ($_POST['select_by'] == 'site' && count($selected_sites) == 0)) {
                    $errors[] = __('Please select the sites or groups where you want to change the admin password.','mainwp');
                }
            } else {
                $errors[] = __('Please select whether you want to change the admin password for specific sites or groups.','mainwp');
            }
            if (!isset($_POST['pass1']) || $_POST['pass1'] == '' || !isset($_POST['pass2']) || $_POST['pass2'] == '') {
                $errors[] = __('Please enter the password twice.','mainwp');
            } else if ($_POST['pass1'] != $_POST['pass2']) {
                $errors[] = __('Please enter the same password in the two password fields.','mainwp');
            }
            if (count($errors) == 0) {
                $show_form = false;

                $new_password = array(
                    'user_pass' => $_POST['pass1']
                );

                $dbwebsites = array();
                if ($_POST['select_by'] == 'site') { //Get all selected websites
                    foreach ($selected_sites as $k) {
                        if (MainWPUtility::ctype_digit($k)) {
                            $website = MainWPDB::Instance()->getWebsiteById($k);
                            $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                        }
                    }
                } else { //Get all websites from the selected groups
                    foreach ($selected_groups as $k) {
                        if (MainWPUtility::ctype_digit($k)) {
                            $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupId($k));
                            while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                            {
                                if ($website->sync_errors != '') continue;
                                $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                            }
                            @MainWPDB::free_result($websites);
                        }
                    }
                }

                if (count($dbwebsites) > 0) {
                    $post_data = array(
                        'new_password' => base64_encode(serialize($new_password))
                    );
                    $output = new stdClass();
                    $output->ok = array();
                    $output->errors = array();
                    MainWPUtility::fetchUrlsAuthed($dbwebsites, 'newadminpassword', $post_data, array(MainWPBulkAdd::getClassName(), 'PostingBulk_handler'), $output);
                }
            }
        }

        if (!$show_form) {
            //Added to..
            ?>
            <div class="wrap">
                <img src="<?php echo plugins_url('images/icons/mainwp-passwords.png', dirname(__FILE__)); ?>" style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Passwords" height="32"/><h2 id="add-new-user"> Update Admin Passwords</h2>
                <div id="message" class="updated">
                    <?php foreach ($dbwebsites as $website) { ?>
                        <p><a href="<?php echo admin_url('admin.php?page=managesites&dashboard=' . $website->id); ?>"><?php echo $website->name; ?></a>: <?php echo (isset($output->ok[$website->id]) && $output->ok[$website->id] == 1 ? __('Admin password updated.','mainwp') : __('ERROR: ','mainwp') . $output->errors[$website->id]); ?></p>
            <?php } ?>
                </div>
                <br />
                <a href="<?php echo get_admin_url() ?>admin.php?page=UpdateAdminPasswords" class="add-new-h2" target="_top"><?php _e('Update admin passwords','mainwp'); ?></a>
                <a href="<?php echo get_admin_url() ?>admin.php?page=mainwp_tab" class="add-new-h2" target="_top"><?php _e('Return to Dashboard','mainwp'); ?></a>
            </div>
            <?php
        } else {
			// header in User page
            MainWPUser::renderHeader('UpdateAdminPasswords');
            ?>
            <form action="" method="post" name="createuser" id="createuser" class="add:users: validate">

                <input type="hidden" name="security" value="<?php echo wp_create_nonce('mainwp_updateadminpassword'); ?>" />

                <div class="mainwp_config_box_right">
                    <?php MainWPUI::select_sites_box(__("Select Sites to Update", 'mainwp')); ?>
                </div>

                <div class="mainwp_config_box_left postbox mainwp-postbox">
                <h3 class="mainwp_box_title"><i class="fa fa-key"></i> <?php _e('Bulk Update Administrator Passwords','mainwp'); ?></h3>
                <div class="inside">
                <table class="form-table">
                    <tr class="form-field form-required">
                        <th scope="row"><label for="pass1"><?php _e('Enter New Password ','mainwp'); ?><br /><span class="description"><?php _e('(twice, required)','mainwp'); ?></span></label></th>
                        <td><input name="user_login" type="hidden" id="user_login" value="admin">
                            <input class="mainwp-field mainwp-password" name="pass1" type="password" id="pass1" autocomplete="off" />
                            <br />
                            <input class="mainwp-field mainwp-password" name="pass2" type="password" id="pass2" autocomplete="off" />
                            <br />
                            <div id="pass-strength-result" style="display: block;"><?php _e('Strength indicator','mainwp'); ?></div>
                            <p class="description indicator-hint" style="clear:both;"><?php _e('Hint: The password should be at least seven characters long. To make it stronger, use upper and lower case letters, numbers and symbols like ! " ? $ % ^ &amp; ).','mainwp'); ?></p>
                        </td>
                    </tr>
                    <tr><td></td><td colspan="2"><input type="submit" name="updateadminpassword" id="bulk_updateadminpassword" class="button-primary" value="<?php _e('Update Now','mainwp'); ?>"  /></td></tr>
                </table>
                </div>
                </div>

            </form>
            <?php
            MainWPUser::renderFooter('UpdateAdminPasswords');
        }
    }
	// this help moved to User page 
    public static function QSGManageAdminPasswords() {
        MainWPUser::renderHeader('AdminPasswordsHelp');
    ?><div style="text-align: center"><a href="#" class="button button-primary" id="mainwp-quick-start-guide"><?php _e('Show Quick Start Guide','mainwp'); ?></a></div>
                      <div  class="mainwp_info-box-yellow" id="mainwp-qsg-tips">
                          <span><a href="#" class="mainwp-show-qsg" number="1"><?php _e('Manage Admin Passwords','mainwp') ?></a></span><span><a href="#" id="mainwp-qsg-dismiss" style="float: right;"><?php _e('Dismiss','mainwp'); ?></a></span>
                      <div class="clear"></div>
                      <div id="mainwp-qsgs">
                        <div class="mainwp-qsg" number="1">
                            <h3>Manage Admin Passwords</h3>
                            <p>
                                <ol>
                                    <li>
                                        Enter a new password twice
                                    </li>
                                    <li>
                                        Select the sites in the Select Site Box
                                    </li>
                                    <li>
                                        Click Update Now button
                                    </li>
                                </ol>
                            </p>
                        </div>
                      </div>
                    </div>
    <?php
    MainWPUser::renderFooter('AdminPasswordsHelp');
    }

}
?>
