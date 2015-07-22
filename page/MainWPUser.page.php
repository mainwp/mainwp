<?php
/**
 * @see MainWPBulkAdd
 */
class MainWPUser
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static $subPages;

    public static function init()
    {
        add_action('mainwp-pageheader-user', array(MainWPUser::getClassName(), 'renderHeader'));
        add_action('mainwp-pagefooter-user', array(MainWPUser::getClassName(), 'renderFooter'));
    }

    public static function initMenu()
    {
        add_submenu_page('mainwp_tab', __('Users','mainwp'), '<span id="mainwp-Users">' . __('Users','mainwp') .'</span>', 'read', 'UserBulkManage', array(MainWPUser::getClassName(), 'render'));
        add_submenu_page('mainwp_tab', __('Users','mainwp'), '<div class="mainwp-hidden">' . __('Add New','mainwp') . '</div>', 'read', 'UserBulkAdd', array(MainWPUser::getClassName(), 'renderBulkAdd'));				
        add_submenu_page('mainwp_tab', __('Users Help','mainwp'), '<div class="mainwp-hidden">' . __('Users Help','mainwp') . '</div>', 'read', 'UsersHelp', array(MainWPUser::getClassName(), 'QSGManageUsers'));

        self::$subPages = apply_filters('mainwp-getsubpages-user', array());
        if (isset(self::$subPages) && is_array(self::$subPages))
        {
            foreach (self::$subPages as $subPage)
            {
                add_submenu_page('mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'UserBulk' . $subPage['slug'], $subPage['callback']);
            }
        }
    }

    public static function initMenuSubPages()
    {
    ?>
    <div id="menu-mainwp-Users" class="mainwp-submenu-wrapper">
        <div class="wp-submenu sub-open" style="">
            <div class="mainwp_boxout">
                <div class="mainwp_boxoutin"></div>
                <?php if (mainwp_current_user_can("dashboard", "manage_users")) { ?>
                <a href="<?php echo admin_url('admin.php?page=UserBulkManage'); ?>" class="mainwp-submenu"><?php _e('Manage Users','mainwp'); ?></a>
                <?php } ?>
                <a href="<?php echo admin_url('admin.php?page=UserBulkAdd'); ?>" class="mainwp-submenu"><?php _e('Add New','mainwp'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=UpdateAdminPasswords'); ?>" class="mainwp-submenu"><?php _e('Admin Passwords','mainwp'); ?></a>
                <?php
                if (isset(self::$subPages) && is_array(self::$subPages))
                {
                    foreach (self::$subPages as $subPage)
                    {
                    ?>
                     <a href="<?php echo admin_url('admin.php?page=UserBulk' . $subPage['slug']); ?>"
                         class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
                    <?php
                    }
                }
                ?>
            </div>
        </div>
    </div>
    <?php
    }

    public static function renderHeader($shownPage)
    {
        ?>
    <div class="wrap">
        <a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
                src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50"
                alt="MainWP"/></a>
        <h2><i class="fa fa-user"></i> <?php _e('Users','mainwp'); ?></h2><div style="clear: both;"></div><br/>
        <div class="mainwp-tabs" id="mainwp-tabs">
            <?php if (mainwp_current_user_can("dashboard", "manage_users")) { ?>
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == '') { echo "nav-tab-active"; } ?>" href="admin.php?page=UserBulkManage"><?php _e('Manage','mainwp'); ?></a>
            <?php } ?>
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'Add') { echo "nav-tab-active"; } ?>" href="admin.php?page=UserBulkAdd"><?php _e('Add New','mainwp'); ?></a>
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'UpdateAdminPasswords') { echo "nav-tab-active"; } ?>" href="admin.php?page=UpdateAdminPasswords"><?php _e('Admin Passwords','mainwp'); ?></a>
            <a style="float: right" class="mainwp-help-tab nav-tab pos-nav-tab <?php if ($shownPage === 'UsersHelp') { echo "nav-tab-active"; } ?>" href="admin.php?page=UsersHelp"><?php _e('Help','mainwp'); ?></a>
            <?php if ($shownPage == 'UserBulkUpload') { ?><a class="nav-tab pos-nav-tab nav-tab-active" href="#"><?php _e('Bulk Upload','mainwp'); ?></a><?php } ?>

            <?php
            if (isset(self::$subPages) && is_array(self::$subPages))
            {
                foreach (self::$subPages as $subPage)
                {
                ?>
                    <a class="nav-tab pos-nav-tab <?php if ($shownPage === $subPage['slug']) { echo "nav-tab-active"; } ?>" href="admin.php?page=UserBulk<?php echo $subPage['slug']; ?>"><?php echo $subPage['title']; ?></a>
                <?php
                }
            }
            ?>
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

    public static function render()
    {
        if (!mainwp_current_user_can("dashboard", "manage_users")) {
            mainwp_do_not_have_permissions("manage users");
            return;
        }
        
        $cachedSearch = MainWPCache::getCachedContext('Users');
        self::renderHeader(''); ?>
         <div>
            <div class="postbox mainwp-postbox" style="width: 555px !important;">
            <h3 class="mainwp_box_title"><i class="fa fa-binoculars"></i> <?php _e('Search Users','mainwp'); ?></h3>
            <div class="inside">                  
            <div class="mainwp-search-box">
                <input type="text" aria-required="true" value="<?php if ($cachedSearch != null && isset($cachedSearch['keyword'])) { echo $cachedSearch['keyword']; } ?>"
                        id="mainwp_search_users" name="mainwp_search_users">
                <input type="button" value="<?php _e('Search Users','mainwp'); ?>" class="button"
                        id="mainwp_btn_search_users" name="mainwp_btn_search_users">
                <span id="mainwp_users_searching">
                    <i class="fa fa-spinner fa-pulse"></i>
                </span>                 
            </div>
            <h3><?php _e('Show Users','mainwp'); ?></h3>
            <ul class="mainwp_checkboxes">
                <li>
                    <input type="checkbox" id="mainwp_user_role_administrator" <?php echo ($cachedSearch == null || ($cachedSearch != null && in_array('administrator', $cachedSearch['status']))) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2"/>
                    <label for="mainwp_user_role_administrator" class="mainwp-label2"><?php _e('Administrator','mainwp'); ?></label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_user_role_editor" <?php echo ($cachedSearch != null && in_array('editor', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2"/>
                    <label for="mainwp_user_role_editor" class="mainwp-label2"><?php _e('Editor','mainwp'); ?></label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_user_role_author" <?php echo ($cachedSearch != null && in_array('author', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2"/>
                    <label for="mainwp_user_role_author" class="mainwp-label2"><?php _e('Author','mainwp'); ?></label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_user_role_contributor" <?php echo ($cachedSearch != null && in_array('contributor', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2"/>
                    <label for="mainwp_user_role_contributor" class="mainwp-label2"><?php _e('Contributor','mainwp'); ?></label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_user_role_subscriber" <?php echo ($cachedSearch != null && in_array('subscriber', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2"/>
                    <label for="mainwp_user_role_subscriber" class="mainwp-label2"><?php _e('Subscriber','mainwp'); ?></label>
                </li>
            </ul>
            </div>
            </div>
            <?php MainWPUI::select_sites_box(__("Select Sites", 'mainwp'), 'checkbox', true, true, 'mainwp_select_sites_box_left'); ?>
            <div class="postbox" style="float: left; width: 255px; margin-left: 2em;">
            <h3 class="box_title mainwp_box_title"><i class="fa fa-key"></i> <?php _e('Update Password','mainwp'); ?></h3>
            <div class="inside mainwp_inside" style="padding-bottom: .2em !important;">
            <div class="form-field">
               <label for="pass1"><?php _e('Twice Required','mainwp'); ?></label>
                <input name="user_login" type="hidden" id="user_login" value="admin">
               <div><input name="pass1" type="password" id="pass1" autocomplete="off"/></div>
               <div><input name="pass2" type="password" id="pass2" autocomplete="off"/></div>
            </div>
            <div id="pass-strength-result" style="display: block"><?php _e('Strength Indicator','mainwp'); ?></div>
            <br><br>
            <p class="description indicator-hint"><?php _e('Hint: The password should be at least seven
                characters long. To make it stronger, use upper and lower case letters, numbers and
                symbols like ! " ? $ % ^ &amp; ).','mainwp'); ?></p>
             <p style="text-align: center;"><input type="button" value="<?php _e('Update Password','mainwp'); ?>" class="button-primary"
                    id="mainwp_btn_update_password" name="mainwp_btn_update_password">
                <span id="mainwp_users_password_updating"><i class="fa fa-spinner fa-pulse"></i></span>   
             </p> 
             <p><div id="mainwp_update_password_error" style="display: none"></div></p>
             </div>
        </div>
            <div style="clear: both;"></div>
            <input type="button" name="mainwp_show_users" id="mainwp_show_users" class="button-primary" value="<?php _e('Show Users','mainwp'); ?>"/>
            <span id="mainwp_users_loading"><i class="fa fa-spinner fa-pulse"></i> <em><?php _e('Grabbing information from Child Sites','mainwp') ?></em></span>
            <br/><br/>
        </div>
        <div class="clear"></div>

        <div id="mainwp_users_error"></div>
        <div id="mainwp_users_main" <?php if ($cachedSearch != null) { echo 'style="display: block;"'; } ?>>
            <div class="alignleft">
                <select name="bulk_action" id="mainwp_bulk_action">
                    <option value="none"><?php _e('Bulk Action','mainwp'); ?></option>
                    <option value="delete"><?php _e('Delete','mainwp'); ?></option>
                </select> <input type="button" name="" id="mainwp_bulk_user_action_apply" class="button" value="<?php _e('Apply','mainwp'); ?>"/>
                <select name="bulk_action" id="mainwp_bulk_role_action">
                    <option value="none"><?php _e('Change Role to ...','mainwp'); ?></option>
                    <option value="role_to_administrator"> <?php _e('Administrator','mainwp'); ?></option>
                    <option value="role_to_editor"> <?php _e('Editor','mainwp'); ?></option>
                    <option value="role_to_author"> <?php _e('Author','mainwp'); ?></option>
                    <option value="role_to_contributor"> <?php _e('Contributor','mainwp'); ?></option>
                    <option value="role_to_subscriber"> <?php _e('Subscriber','mainwp'); ?></option>
                </select> <input type="button" name="" id="mainwp_bulk_role_action_apply" class="button" value="<?php _e('Change','mainwp'); ?>"/>
            </div>
            <div class="alignright" id="mainwp_users_total_results">
                <?php _e('Total Results:','mainwp'); ?> <span id="mainwp_users_total"><?php echo $cachedSearch != null ? $cachedSearch['count'] : '0'; ?></span>
            </div>
            <div class="clear"></div>
            <div id="mainwp_users_content">
                <table class="wp-list-table widefat fixed pages tablesorter" id="mainwp_users_table"
                       cellspacing="0">
                    <thead>
                    <tr>
                        <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input
                                type="checkbox"></th>
                        <th scope="col" id="username" class="manage-column column-username sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Username','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="name" class="manage-column column-author sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Name','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="email" class="manage-column column-email sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('E-mail','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="role" class="manage-column column-role sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Role','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="posts" class="manage-column column-posts sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Posts','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="website" class="manage-column column-website sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Website','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                    </tr>
                    </thead>

                    <tfoot>
                    <tr>
                        <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input
                                type="checkbox"></th>
                        <th scope="col" id="username" class="manage-column column-username sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Username','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="name" class="manage-column column-author sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Name','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="email" class="manage-column column-email sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('E-mail','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="role" class="manage-column column-role sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Role','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="posts" class="manage-column column-posts sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Posts','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="website" class="manage-column column-website sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Website','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                    </tr>
                    </tfoot>

                    <tbody id="the-list" class="list:user">
                        <?php MainWPCache::echoBody('Users'); ?>
                    </tbody>
                </table>
                <div class="pager" id="pager">
                    <form>
                        <img src="<?php echo plugins_url('images/first.png', dirname(__FILE__)); ?>" class="first">
                        <img src="<?php echo plugins_url('images/prev.png', dirname(__FILE__)); ?>" class="prev">
                        <input type="text" class="pagedisplay">
                        <img src="<?php echo plugins_url('images/next.png', dirname(__FILE__)); ?>" class="next">
                        <img src="<?php echo plugins_url('images/last.png', dirname(__FILE__)); ?>" class="last">
                        <span>&nbsp;&nbsp;<?php _e('Show:','mainwp'); ?> </span><select class="pagesize">
                            <option selected="selected" value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="1000000000">All</option>
                        </select><span> <?php _e('Users per page','mainwp'); ?></span>
                    </form>
                </div>
                <div class="clear"></div>
            </div>
        </div>
    <?php
        if ($cachedSearch != null) { echo '<script>mainwp_users_table_reinit();</script>'; }
        self::renderFooter('');
    }

    public static function renderTable($role, $groups, $sites, $search = null)
    {
        MainWPCache::initCache('Users');

        $output = new stdClass();
        $output->errors = array();
        $output->users = 0;

        if (get_option('mainwp_optimize') == 1)
        {
            //Search in local cache
            if ($sites != '') {
                foreach ($sites as $k => $v) {
                    if (MainWPUtility::ctype_digit($v)) {
                        $website = MainWPDB::Instance()->getWebsiteById($v);
                        $allUsers = json_decode($website->users, true);
                        for ($i = 0; $i < count($allUsers); $i++) {
                            $user = $allUsers[$i];
                            if ($role)
                            {
                                $roles = explode(',', $_POST['role']);
                                if (is_array($roles))
                                {
                                    $found = false;
                                    foreach ($roles as $role)
                                    {
                                        if (stristr($user['role'], $role))
                                        {
                                            $found = true;
                                            break;
                                        }
                                    }
                                    if (!$found) continue;
                                }
                                else
                                {
                                    continue;
                                }
                            }
                            else if ($search !== null)
                            {
                                if ($search != '' && !stristr($user['login'], trim($search)) && !stristr($user['display_name'], trim($search)) && !stristr($user['email'], trim($search))) continue;
                            }
                            else
                            {
                                continue;
                            }

                            $tmpUsers = array($user);
                            $output->users += self::usersSearchHandlerRenderer($tmpUsers, $website);
                        }
                    }
                }
            }
            if ($groups != '') {
                foreach ($groups as $k => $v) {
                    if (MainWPUtility::ctype_digit($v)) {
                        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupId($v));
                        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                        {
                            if ($website->sync_errors != '') continue;
                            $allUsers = json_decode($website->users, true);
                            for ($i = 0; $i < count($allUsers); $i++) {
                                $user = $allUsers[$i];
                                if ($role)
                                {
                                    $roles = explode(',', $_POST['role']);
                                    if (is_array($roles))
                                    {
                                        $found = false;
                                        foreach ($roles as $role)
                                        {
                                            if (stristr($user['role'], $role))
                                            {
                                                $found = true;
                                                break;
                                            }
                                        }
                                        if (!$found) continue;
                                    }
                                    else
                                    {
                                        continue;
                                    }
                                }
                                else if ($search !== null)
                                {
                                    if ($search != '' && !stristr($user['login'], trim($search)) && !stristr($user['display_name'], trim($search)) && !stristr($user['email'], trim($search))) continue;
                                }
                                else
                                {
                                    continue;
                                }

                                $tmpUsers = array($user);
                                $output->users += self::usersSearchHandlerRenderer($tmpUsers, $website);
                            }
                        }
                        @MainWPDB::free_result($websites);
                    }
                }
            }
        }
        else
        {
            //Fetch all!
            //Build websites array
            $dbwebsites = array();
            if ($sites != '') {
                foreach ($sites as $k => $v) {
                    if (MainWPUtility::ctype_digit($v)) {
                        $website = MainWPDB::Instance()->getWebsiteById($v);
                        $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                    }
                }
            }
            if ($groups != '') {
                foreach ($groups as $k => $v) {
                    if (MainWPUtility::ctype_digit($v)) {
                        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupId($v));
                        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                        {
                            if ($website->sync_errors != '') continue;
                            $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                        }
                        @MainWPDB::free_result($websites);
                    }
                }
            }

            if ($role) {
                $post_data = array(
                    'role' => $role
                );
                MainWPUtility::fetchUrlsAuthed($dbwebsites, 'get_all_users', $post_data, array(MainWPUser::getClassName(), 'UsersSearch_handler'), $output);
            }
            else if ($search !== null)
            {
                $post_data = array(
                    'search' => '*'.trim($search).'*',
                    'search_columns' => 'user_login,display_name,user_email'
                );
                MainWPUtility::fetchUrlsAuthed($dbwebsites, 'search_users', $post_data, array(MainWPUser::getClassName(), 'UsersSearch_handler'), $output);
            }
        }

        MainWPCache::addContext('Users', array('count' => $output->users, 'keyword' => $search, 'status' => (isset($_POST['role']) ? $_POST['role'] : 'administrator')));
        //Sort if required

        if ($output->users == 0) {
            ob_start();
            ?>
        <tr>
            <td colspan="7">No users found</td>
        </tr>
        <?php
            $newOutput = ob_get_clean();
            echo $newOutput;
            MainWPCache::addBody('Users', $newOutput);
            return;
        }
    }

    private static function getRole($role)
    {       
        if (is_array($role)) {
            $allowed_roles = array('subscriber', 'administrator', 'editor', 'author', 'contributor');
            $ret = "";
            foreach($role as $ro) {
                if (in_array($ro, $allowed_roles))  
                    $ret .= ucfirst($ro).', ';
            } 
            $ret = rtrim($ret, ', '); 
            if ($ret == "")
                $ret = "None";
            return $ret;      
        }            
        return ucfirst($role);
    }

    protected static function usersSearchHandlerRenderer($users, $website)
    {
        $return = 0;
        foreach ($users as $user) {
            ob_start();
            ?>
        <tr id="user-1" class="alternate">
            <th scope="row" class="check-column"><input type="checkbox" name="user[]" value="1"></th>
            <td class="username column-username">
                <input class="userId" type="hidden" name="id" value="<?php echo $user['id']; ?>"/>
                <input class="userName" type="hidden" name="name" value="<?php echo $user['login']; ?>"/>
                <input class="websiteId" type="hidden" name="id"
                       value="<?php echo $website->id; ?>"/>

                <?php if (isset($user['avatar'])) echo $user['avatar']; ?>
                <strong><abbr title="<?php echo $user['login']; ?>"><?php echo $user['login']; ?></abbr></strong>

                <div class="row-actions">
                    <span class="edit"><a
                            href="admin.php?page=SiteOpen&websiteid=<?php echo $website->id; ?>&location=<?php echo base64_encode('user-edit.php?user_id=' . $user['id']); ?>"
                            title="Edit this user"><?php _e('Edit','mainwp'); ?></a>
                    </span>
                    <?php if (($user['id'] != 1) && ($user['login'] != $website->adminname)) { ?>
                    <span class="trash">
                        | <a class="user_submitdelete" title="Delete this user" href="#"><?php _e('Delete','mainwp'); ?></a>
                    </span>
                    <?php } else if (($user['id'] == 1) || ($user['login'] == $website->adminname)) { ?>
                    <span class="trash">
                        | <span title="This user is used for our secure link, it can not be deleted." style="color: gray"><?php _e('Delete','mainwp'); ?>&nbsp;&nbsp;<?php MainWPUtility::renderToolTip(__('This user is used for our secure link, it can not be deleted.','mainwp'), 'http://docs.mainwp.com/deleting-secure-link-admin', 'images/info.png', 'float: none !important;'); ?></span>
                    </span>
                    <?php } ?>
                </div>
                <div class="row-actions-working"><i class="fa fa-spinner fa-pulse"></i> <?php _e('Please wait','mainwp'); ?>
                </div>
            </td>
            <td class="name column-name"><?php echo $user['display_name']; ?></td>
            <td class="email column-email"><a
                    href="mailto:<?php echo $user['email']; ?>"><?php echo $user['email']; ?></a></td>
            <td class="role column-role"><?php  echo self::getRole($user['role']); ?></td>
            <td class="posts column-posts" style="text-align: left; padding-left: 1.7em ;"><a href="<?php echo admin_url('admin.php?page=PostBulkManage&siteid='.$website->id.'&userid='.$user['id']); ?>"><?php echo $user['post_count']; ?></a></td>
            <td class="website column-website"><a
                    href="<?php echo $website->url; ?>"><?php echo $website->url; ?></a>
                    <div class="row-actions">
                        <span class="edit"><a href="admin.php?page=managesites&dashboard=<?php echo $website->id; ?>"><?php _e('Dashboard','mainwp'); ?></a> | <a href="admin.php?page=SiteOpen&websiteid=<?php echo $website->id; ?>"><?php _e('WP Admin','mainwp'); ?></a></span>
                    </div>
            </td>
        </tr>
        <?php
            $newOutput = ob_get_clean();
            echo $newOutput;
            MainWPCache::addBody('Users', $newOutput);
            $return++;
        }
        return $return;
    }
    public static function UsersSearch_handler($data, $website, &$output)
    {
        if (preg_match('/<mainwp>(.*)<\/mainwp>/', $data, $results) > 0) {
            $users = unserialize(base64_decode($results[1]));
            unset($results);
            $output->users += self::usersSearchHandlerRenderer($users, $website);
            unset($users);
        } else {
            $output->errors[$website->id] = MainWPErrorHelper::getErrorMessage(new MainWPException('NOMAINWP', $website->url));
        }
    }

    public static function delete()
    {
        MainWPUser::action('delete');
        die(json_encode(array('result' => 'User has been deleted')));
    }
    
    public static function updatePassword()
    {
        MainWPUser::action('update_password');
        die(json_encode(array('result' => 'User password has been updated')));
    }    

    public static function changeRole($role)
    {
        MainWPUser::action('changeRole', $role);
        die(json_encode(array('result' => 'User role has been changed to '.ucfirst($role))));
    }

    public static function action($pAction, $extra = '')
    {
        $userId = $_POST['userId'];
        $userName = $_POST['userName'];
        $websiteIdEnc = $_POST['websiteId'];
        $pass = $_POST['update_password'];

        if (!MainWPUtility::ctype_digit($userId)) die(json_encode(array('error' => 'Invalid Request.')));
        $websiteId = $websiteIdEnc;
        if (!MainWPUtility::ctype_digit($websiteId)) die(json_encode(array('error' => 'Invalid Request.')));

        $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        if (!MainWPUtility::can_edit_website($website)) die(json_encode(array('error' => 'You can not edit this website.')));

        if (($pAction == 'delete') && ($website->adminname  == $userName)) die(json_encode(array('error' => __('This user is used for our secure link, it can not be deleted.'))));
        if (($pAction == 'changeRole') && ($website->adminname  == $userName)) die(json_encode(array('error' => __('This user is used for our secure link, you can not change the role.'))));

        try
        {
            $information = MainWPUtility::fetchUrlAuthed($website, 'user_action', array(
                'action' => $pAction,
                'id' => $userId,
                'extra' => $extra,
                'user_pass' => $pass));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => $e->getMessage())));
        }

        if (!isset($information['status']) || ($information['status'] != 'SUCCESS')) die(json_encode(array('error' => 'Unexpected error.')));
    }

    public static function renderBulkAdd()
    {        
        if (isset($_POST['user_chk_bulkupload']) && $_POST['user_chk_bulkupload']) {
            self::renderBulkUpload();
            return;            
        }        
        ?>
        <?php self::renderHeader('Add'); ?>
        <?php if (isset($errors) && count($errors) > 0) { ?>
        <div class="error below-h2">
            <?php foreach ($errors as $error) { ?>
            <p><strong>ERROR</strong>: <?php echo $error ?></p>
            <?php } ?>
        </div>
        <?php } ?>
        <div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
        <!--            <div id="ajax-response"></div>-->
        <div id="MainWPBulkAddUserLoading" class="updated">
            <div><img src="images/loading.gif"/> <?php _e('Adding the user','mainwp'); ?></div>
        </div>
        <div id="MainWPBulkAddUser">

            <div class="mainwp_info-box"><strong><?php _e('Create a brand new user and add it to your sites.','mainwp'); ?></strong></div>

            <form action="" method="post" name="createuser" id="createuser" class="add:users: validate" enctype="multipart/form-data">
                <div class="mainwp_config_box_right">
                    <?php MainWPUI::select_sites_box(__("Select Sites", 'mainwp')); ?>
                </div>
                <div class="mainwp_config_box_left">
                	<div class="postbox">
                	<h3 class="mainwp_box_title"><span><?php _e('Add a Single User','mainwp'); ?></span></h3>
                    <div class="inside">
                    <table class="form-table">
                        <tr class="form-field form-required">
                            <th scope="row"><label for="user_login"><?php _e('Username','mainwp'); ?> <span class="description"><?php _e('(required)','mainwp'); ?></span></label>
                            </th>
                            <td><input  class="mainwp-field mainwp-username"  name="user_login" type="text" id="user_login" value="<?php
                                if (isset($_POST['user_login'])) {
                                    echo $_POST['user_login'];
                                }
                                ?>" aria-required="true"/></td>
                        </tr>
                        <tr class="form-field form-required">
                            <th scope="row"><label for="email"><?php _e('E-mail','mainwp'); ?> <span
                                    class="description"><?php _e('(required)','mainwp'); ?></span></label></th>
                            <td><input class="mainwp-field mainwp-email" name="email" type="text" id="email" value="<?php
                                if (isset($_POST['email'])) {
                                    echo $_POST['email'];
                                }
                                ?>"/></td>
                        </tr>
                        <tr class="form-field">
                            <th scope="row"><label for="first_name"><?php _e('First Name','mainwp'); ?> </label></th>
                            <td><input class="mainwp-field mainwp-name" name="first_name" type="text" id="first_name" value="<?php
                                if (isset($_POST['first_name'])) {
                                    echo $_POST['first_name'];
                                }
                                ?>"/></td>
                        </tr>
                        <tr class="form-field">
                            <th scope="row"><label for="last_name"><?php _e('Last Name','mainwp'); ?> </label></th>
                            <td><input class="mainwp-field mainwp-name" name="last_name" type="text" id="last_name" value="<?php
                                if (isset($_POST['last_name'])) {
                                    echo $_POST['last_name'];
                                }
                                ?>"/></td>
                        </tr>
                        <tr class="form-field">
                            <th scope="row"><label for="url"><?php _e('Website','mainwp'); ?></label></th>
                            <td><input class="mainwp-field mainwp-site" name="url" type="text" id="url" class="code" value="<?php
                                if (isset($_POST['url'])) {
                                    echo $_POST['url'];
                                }
                                ?>"/></td>
                        </tr>
                        <tr class="form-field form-required">
                            <th scope="row"><label for="pass1"><?php _e('Password','mainwp'); ?> <span
                                    class="description"><?php _e('(twice, required)','mainwp'); ?></span></label></th>
                            <td><input class="mainwp-field mainwp-password" name="pass1" type="password" id="pass1" autocomplete="off"/>
                                <br/>
                                <input class="mainwp-field mainwp-password" name="pass2" type="password" id="pass2" autocomplete="off"/>
                                <br/>

                                <div id="pass-strength-result" style="display: block"><?php _e('Strength Indicator','mainwp'); ?></div><br><br>
                                <p class="description indicator-hint"><?php _e('Hint: The password should be at least seven
                                    characters long. To make it stronger, use upper and lower case letters, numbers and
                                    symbols like ! " ? $ % ^ &amp; ).','mainwp'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="send_password"><?php _e('Send Password?','mainwp'); ?></label></th>
                            <td><label for="send_password"><input type="checkbox" name="send_password"
                                                                  id="send_password" <?php
                                if (isset($_POST['send_password'])) {
                                    echo 'checked';
                                }
                                ?> /> <?php _e('Send this password to the new user by email.','mainwp'); ?></label></td>
                        </tr>
                        <tr class="form-field">
                            <th scope="row"><label for="role"><?php _e('Role','mainwp'); ?></label></th>
                            <td>
                                <select name="role" id="role">
                                    <option value='subscriber' <?php
                                        if (isset($_POST['role']) && $_POST['role'] == 'subscriber') {
                                            echo 'selected';
                                        }
                                        ?>><?php _e('Subscriber','mainwp'); ?>
                                    </option>
                                    <option value='administrator' <?php
                                        if (isset($_POST['role']) && $_POST['role'] == 'administrator') {
                                            echo 'selected';
                                        }
                                        ?>><?php _e('Administrator','mainwp'); ?>
                                    </option>
                                    <option value='editor' <?php
                                        if (isset($_POST['role']) && $_POST['role'] == 'editor') {
                                            echo 'selected';
                                        }
                                        ?>><?php _e('Editor','mainwp'); ?>
                                    </option>
                                    <option value='author' <?php
                                        if (isset($_POST['role']) && $_POST['role'] == 'author') {
                                            echo 'selected';
                                        }
                                        ?>><?php _e('Author','mainwp'); ?>
                                    </option>
                                    <option value='contributor' <?php
                                        if (isset($_POST['role']) && $_POST['role'] == 'contributor') {
                                            echo 'selected';
                                        }
                                        ?>><?php _e('Contributor','mainwp'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        </table>
                        </div>
                        </div>
                        <div class="postbox">
                        <h3 class="mainwp_box_title"><span><?php _e('Bulk Upload','mainwp'); ?></span></h3>
                        <div class="inside">
                        <table>
                        <tr>
                            <th scope="row"></th>
                            <td>
                                <input type="file" name="import_user_file_bulkupload"
                                       id="import_user_file_bulkupload" accept="text/comma-separated-values"
                                       class="regular-text" disabled="disabled"/> 
                                       <span
                                    class="description"><?php _e('File must be in CSV format.','mainwp'); ?> <a href="https://mainwp.com/csv/sample_users.csv" target="_blank"><?php _e('Click here to download sample CSV file.','mainwp'); ?></a></span>
                                    <div>
                                        <p>
                                            <input type="checkbox" name="user_chk_bulkupload"
                                                    id="user_chk_bulkupload" value="1" />
                                            <span class="description"><?php _e('Upload file','mainwp'); ?></span>
                                        </p>
                                        <p>
                                            <input type="checkbox" name="import_user_chk_header_first" disabled="disabled" checked="checked"
                                                    id="import_user_chk_header_first" value="1" />
                                            <span class="description"><?php _e('CSV file contains a header.','mainwp'); ?></span>
                                        </p>
                                    </div>
                            </td>
                        </tr>                    
                    
                    </table>
                    </div>
                </div>
                </div>

                <p class="submit"><input type="button" name="createuser" id="bulk_add_createuser" class="button-primary"
                                         value="<?php _e('Add New User','mainwp'); ?> "/></p>
            </form>
        </div>
    <?php
        self::renderFooter('Add');
    }

    public static function doPost()
    {
        $errors = array();
        $errorFields = array();
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
                $errors[] = 'Please select the sites or groups you want to add the new user to.';
            }
        } else {
            $errors[] = 'Please select whether you want to add the user to specific sites or groups.';
        }
        if (!isset($_POST['user_login']) || $_POST['user_login'] == '') {
            $errorFields[] = 'user_login';
        }
        if (!isset($_POST['email']) || $_POST['email'] == '') {
            $errorFields[] = 'email';
        }
        if (!isset($_POST['pass1']) || $_POST['pass1'] == '' || !isset($_POST['pass2']) || $_POST['pass2'] == '') {
            $errorFields[] = 'pass1';
        } else if ($_POST['pass1'] != $_POST['pass2']) {
            $errorFields[] = 'pass2';
        }
        $allowed_roles = array('subscriber', 'administrator', 'editor', 'author', 'contributor');
        if (!isset($_POST['role']) || !in_array($_POST['role'], $allowed_roles)) {
            $errorFields[] = 'role';
        }

        if ((count($errors) == 0) && (count($errorFields) == 0)) {
            $user_to_add = array(
                'user_pass' => $_POST['pass1'],
                'user_login' => $_POST['user_login'],
                'user_url' => $_POST['url'],
                'user_email' => $_POST['email'],
                'first_name' => $_POST['first_name'],
                'last_name' => $_POST['last_name'],
                'role' => $_POST['role']
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
                            $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                        }
                        @MainWPDB::free_result($websites);
                    }
                }
            }

            if (count($dbwebsites) > 0) {
                $post_data = array(
                    'new_user' => base64_encode(serialize($user_to_add)),
                    'send_password' => (isset($_POST['send_password']) ? $_POST['send_password'] : '')
                );
                $output = new stdClass();
                $output->ok = array();
                $output->errors = array();
                MainWPUtility::fetchUrlsAuthed($dbwebsites, 'newuser', $post_data, array(MainWPBulkAdd::getClassName(), 'PostingBulk_handler'), $output);
            }

            ?>
        <div id="message" class="updated">
            <?php foreach ($dbwebsites as $website) { ?>
            <p><a href="<?php echo admin_url('admin.php?page=managesites&dashboard=' . $website->id); ?>"><?php echo stripslashes($website->name); ?></a>
                : <?php echo (isset($output->ok[$website->id]) && $output->ok[$website->id] == 1 ? 'New user created.' : 'ERROR: ' . $output->errors[$website->id]); ?></p>
            <?php } ?>
        </div>
        <br/>
        <a href="<?php echo get_admin_url() ?>admin.php?page=UserBulkAdd" class="add-new-h2" target="_top"><?php _e('Add New','mainwp'); ?></a>
        <a href="<?php echo get_admin_url() ?>admin.php?page=mainwp_tab" class="add-new-h2" target="_top"><?php _e('Return to
            Dashboard','mainwp'); ?></a>
        <?php
        } else {
            echo 'ERROR ' . json_encode(array($errorFields, $errors));
        }
    }
    
    public static function renderBulkUpload ()
    {
        self::renderHeader('UserBulkUpload'); ?>
        <div id="MainWPBulkUploadUserLoading" class="updated" style="display: none;">
            <div><img src="images/loading.gif"/> <?php _e('Importing users','mainwp'); ?></div>
        </div>
        <div id="MainWPBulkUploadUser">
             <?php
                 $errors = array();                    
                 if($_FILES['import_user_file_bulkupload']['error'] == UPLOAD_ERR_OK) {                          
                    if (is_uploaded_file($_FILES['import_user_file_bulkupload']['tmp_name']))
                    {
                          $content = file_get_contents($_FILES['import_user_file_bulkupload']['tmp_name']); 
                          $lines = explode("\r", $content);
                          
                          if (is_array($lines) && count($lines) > 0) {
                                $i = 0;    
                                if ($_POST['import_user_chk_header_first']) {
                                    $header_line = trim($lines[0])."\n";
                                    unset($lines[0]);
                                }

                                foreach($lines as $line) {
                                    $line = trim($line);                                   
                                    $items = explode(",", $line);
                                    
                                    $line = trim($items[0]) . "," . trim($items[1]) .",". trim($items[2]) . "," . trim($items[3]) . "," . trim($items[4]) . "," . trim($items[5]). "," . intval($items[6]) . "," . trim(strtolower($items[7])). "," . trim($items[8]) . "," . trim($items[9]) ;

                                ?>
                                    <input type="hidden" id="user_import_csv_line_<?php echo ($i + 1) // to starting by 1 ?>"  value="<?php echo $line ?>"/>
                                <?php
                                    $i++;
                                }

                                ?>  
                                <div class="mainwp_info-box"><strong><?php _e('Importing new users and add them to your sites.','mainwp'); ?></strong></div>
                                <input type="hidden" id="import_user_do_import" value="1"/>
                                <input type="hidden" id="import_user_total_import" value="<?php echo $i ?>"/>                            
                                
                                 <p><div class="import_user_import_listing" id="import_user_import_logging">
                                     <pre class="log"><?php echo $header_line; ?></pre>
                                 </div></p>                
                                 
                                 <p class="submit"><input type="button" name="import_user_btn_import"
                                                 id="import_user_btn_import"
                                                 class="button-primary" value="<?php _e('Pause','mainwp'); ?>"/>
                                                 <input type="button" name="import_user_btn_save_csv"
                                                 id="import_user_btn_save_csv" disabled="disabled"
                                                 class="button-primary" value="<?php _e('Save failed','mainwp'); ?>"/>
                                 </p>
                                 
                                 <p><div class="import_user_import_listing" id="import_user_import_fail_logging" style="display: none;">
                                     <pre class="log"><?php echo $header_line; ?></pre>
                                 </div></p>                 
                            
                            <?php
                            
                          }                          
                          else 
                          {                                  
                            $errors[] = __("Data is not valid. <br />", 'mainwp');
                          }
                    }
                    else
                    {
                       $errors[] = __("Upload error. <br />",'mainwp'); 
                    }
                 }
                 else 
                 {
                    $errors[] = __("Upload error. <br />",'mainwp'); 
                 }
                 
                 if (count($errors) > 0) {                    
                 ?>
                    <div class="error below-h2">
                        <?php foreach ($errors as $error) { ?>
                        <p><strong>ERROR</strong>: <?php echo $error ?></p>
                        <?php } ?>
                    </div>                
                    <br/>
                    <a href="<?php echo get_admin_url() ?>admin.php?page=UserBulkAdd" class="add-new-h2" target="_top"><?php _e('Add New','mainwp'); ?></a>
                    <a href="<?php echo get_admin_url() ?>admin.php?page=mainwp_tab" class="add-new-h2" target="_top"><?php _e('Return to Dashboard','mainwp'); ?></a>
                    <?php 
                    }
                ?>
                
         </div>
        <?php
        self::renderFooter('UserBulkUpload');
    }
    
    public static function doImport()
    {
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
        } 
        $user_to_add = array(
            'user_pass' => $_POST['pass1'],
            'user_login' => $_POST['user_login'],
            'user_url' => $_POST['url'],
            'user_email' => $_POST['email'],
            'first_name' => $_POST['first_name'],
            'last_name' => $_POST['last_name'],
            'role' => $_POST['role']
        );
        
        $ret = array();
        $dbwebsites = array();
        $not_valid = array();
        $error_sites = "";
        if ($_POST['select_by'] == 'site') { //Get all selected websites            
            foreach ($selected_sites as $url) { 
                if (!empty($url)) {                  
                    $website = MainWPDB::Instance()->getWebsitesByUrl($url);
                    if ($website) {
                       $dbwebsites[$website[0]->id] = MainWPUtility::mapSite($website[0], array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                    }
                    else {
                        $not_valid[] = "Error - The website doesn't exist in the Network. " . $url; ;
                        $error_sites .= $url.";";
                    }    
                }
            }
        } else { //Get all websites from the selected groups
            foreach ($selected_groups as $group) {
                if (MainWPDB::Instance()->getGroupsByName($group)) {
                    $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupName($group));
                    if ($websites)
                    {
                        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                        {
                            $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                        }
                        @MainWPDB::free_result($websites);
                    }
                    else
                    {
                        $not_valid[] = __("Error - These are not websites in the group. ",'mainwp') . $group;
                        $error_sites .= $group.";";
                    }    
                }
                else {
                   $not_valid[] = __("Error - The group doesn't exist in the Network. " , 'mainwp') . $group;
                   $error_sites .= $group.";";
                }   
            }
        }    
        
            
        if (count($dbwebsites) > 0) {
            $post_data = array(
                'new_user' => base64_encode(serialize($user_to_add)),
                'send_password' => (isset($_POST['send_password']) ? $_POST['send_password'] : '')
            );
            $output = new stdClass();
            $output->ok = array();
            $output->errors = array();            
            MainWPUtility::fetchUrlsAuthed($dbwebsites, 'newuser', $post_data, array(MainWPBulkAdd::getClassName(), 'PostingBulk_handler'), $output);
        }
        
         $ret['ok_list'] = $ret['error_list'] = array(); 
         foreach ($dbwebsites as $website) {
            if (isset($output->ok[$website->id]) && $output->ok[$website->id] == 1) {
                $ret['ok_list'][] = 'New user(s) created: '. stripslashes($website->name); 
            } 
            else {
                $ret['error_list'][] = $output->errors[$website->id] . " " . stripslashes($website->name);          
                $error_sites .= $website->url . ";";                         
            }    
         }
         
         foreach($not_valid as $val) {                
            $ret['error_list'][] = $val;
         }
         
         $ret['failed_logging'] = "";
         if (!empty($error_sites)) {
             $error_sites = rtrim($error_sites, ';');             
             $ret['failed_logging'] = $_POST['user_login'] . "," . $_POST['email'] . "," . $_POST['first_name'] . "," . $_POST['last_name'] . "," . $_POST['url'] . "," . $_POST['pass1'] . "," . intval($_POST['send_password']) . "," . $_POST['role'] . "," . $error_sites . ",";
         }          
                    
         $ret['line_number'] = $_POST['line_number']; 
         die(json_encode($ret));
    }

    public static function QSGManageUsers() {
        self::renderHeader('UsersHelp');
    ?><div style="text-align: center"><a href="#" class="button button-primary" id="mainwp-quick-start-guide"><?php _e('Show Quick Start Guide','mainwp'); ?></a></div>
                      <div  class="mainwp_info-box-yellow" id="mainwp-qsg-tips">
                          <span><a href="#" class="mainwp-show-qsg" number="1"><?php _e('Manage Users','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg"  number="2"><?php _e('How to add an User','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg"  number="3"><?php _e('How to bulk add users','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg"  number="4"><?php _e('Manage Admin Passwords','mainwp') ?></a></span><span><a href="#" id="mainwp-qsg-dismiss" style="float: right;"><i class="fa fa-times-circle"></i> <?php _e('Dismiss','mainwp'); ?></a></span>
                      <div class="clear"></div>
                      <div id="mainwp-qsgs">
                        <div class="mainwp-qsg" number="1">
                            <h3>Manage Users</h3>
                            <p>
                                <ol>
                                    <li>
                                        Select the roles you want and select sites you want to search on<br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-manage-users.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Hit the Show Users button. Plugin will gather all users with selected role(s) from selected sites in one table.
                                    </li>
                                    <li>
                                        To Edit user details click the Edit quick link under the username in the list
                                    </li>
                                    <li>
                                        To change the user(s) password select the user or users from the results table and enter a new password to the right <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-user-passwords-1024x507.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        To Delete user(s) use bulk action menu above the list
                                    </li>
                                    <li>
                                        To Change Role of user(s), select user(s) in the list and, select the new role in the menu above the table and click Change button <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-edit-user-1024x130.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg" number="2">
                            <h3>How to add an User</h3>
                            <p>
                                <ol>
                                    <li>
                                        Click the Add New tab
                                    </li>
                                    <li>
                                        Enter:
                                        <ol>
                                            <li>Username for the new user</li>
                                            <li>Email of the new user</li>
                                            <li>First Name of the new user</li>
                                            <li>Last Name</li>
                                            <li>Users website</li>
                                            <li>Password for the new user. Type the password second time to confirm.</li>
                                        </ol>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-add-user.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Select a role for the user <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-add-user-role.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>Select a site(s) to add user to</li>
                                    <li>Click the Add New User button</li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg" number="3">
                            <h3>How to bulk add users</h3>
                            <p>
                                <ol>
                                    <li>
                                        To bulk add users you will need to properly format a csv file. A sample csv file can be downloaded from your add users page
                                    </li>
                                    <li>
                                        Go to: MainWP > Users > Add New and scroll down to Bulk Upload Section
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/03/new-bulk-add-users-1024x202.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        The fields in the csv file are as follows
                                        <ol>
                                            <li>Username - The new username to create</li>
                                            <li>Email Address - The email address for the new user</li>
                                            <li>First Name - The first name of the new user</li>
                                            <li>Last Name - The last name of the new user</li>
                                            <li>Users Web Site - The home site of the new user (NOT THE SITE YOU WISH TO ADD THE USER TO)</li>
                                            <li>Send Welcome Email - 0 for No, 1 for Yes</li>
                                            <li>Role - The role you would like the new user to have (subscriber, administrator, editor, author, contributor)</li>
                                            <li>Child Site to add User to - The site you would like the new user added to. (multiple sites can be chosen use ; to separate sites.  http://site1.com;http:site2.com;http://site3.com</li>
                                        </ol>
                                    </li>
                                </ol>
                            </p>
                        </div>
						<div class="mainwp-qsg" number="4">
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
    self::renderFooter('UsersHelp');
    }
    
}

?>
