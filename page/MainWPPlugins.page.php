<?php
/**
 * @see MainWPInstallBulk
 */
class MainWPPlugins
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static $subPages;

    public static function init()
    {
        add_action('mainwp-pageheader-plugins', array(MainWPPlugins::getClassName(), 'renderHeader'));
        add_action('mainwp-pagefooter-plugins', array(MainWPPlugins::getClassName(), 'renderFooter'));
    }

    public static function initMenu()
    {
        add_submenu_page('mainwp_tab', __('Plugins','mainwp'), '<span id="mainwp-Plugins">' . __('Plugins','mainwp') . '</span>', 'read', 'PluginsManage', array(MainWPPlugins::getClassName(), 'render'));
        if (mainwp_current_user_can("dashboard", "install_plugins")) {
            add_submenu_page('mainwp_tab', __('Plugins','mainwp'), '<div class="mainwp-hidden">Install</div>', 'read', 'PluginsInstall', array(MainWPPlugins::getClassName(), 'renderInstall'));
        }
        add_submenu_page('mainwp_tab', __('Plugins','mainwp'), '<div class="mainwp-hidden">Auto Updates</div>', 'read', 'PluginsAutoUpdate', array(MainWPPlugins::getClassName(), 'renderAutoUpdate'));
        add_submenu_page('mainwp_tab', __('Plugins','mainwp'), '<div class="mainwp-hidden">Ignored Updates</div>', 'read', 'PluginsIgnore', array(MainWPPlugins::getClassName(), 'renderIgnore'));
        add_submenu_page('mainwp_tab', __('Plugins','mainwp'), '<div class="mainwp-hidden">Ignored Conflicts</div>', 'read', 'PluginsIgnoredConflicts', array(MainWPPlugins::getClassName(), 'renderIgnoredConflicts'));
        add_submenu_page('mainwp_tab', __('Plugins Help','mainwp'), '<div class="mainwp-hidden">Plugins Help</div>', 'read', 'PluginsHelp', array(MainWPPlugins::getClassName(), 'QSGManagePlugins'));

        self::$subPages = apply_filters('mainwp-getsubpages-plugins', array());
        if (isset(self::$subPages) && is_array(self::$subPages))
        {
            foreach (self::$subPages as $subPage)
            {
                add_submenu_page('mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Plugins' . $subPage['slug'], $subPage['callback']);
            }
        }
    }

    public static function initMenuSubPages()
    {
        ?>
    <div id="menu-mainwp-Plugins" class="mainwp-submenu-wrapper" xmlns="http://www.w3.org/1999/html">
            <div class="wp-submenu sub-open" style="">
                <div class="mainwp_boxout">
                    <div class="mainwp_boxoutin"></div>
                    <a href="<?php echo admin_url('admin.php?page=PluginsManage'); ?>" class="mainwp-submenu"><?php _e('Manage Plugins','mainwp'); ?></a>
                    <?php if (mainwp_current_user_can("dashboard", "install_plugins")) { ?>
                    <a href="<?php echo admin_url('admin.php?page=PluginsInstall'); ?>" class="mainwp-submenu"><?php _e('Install','mainwp'); ?></a>
                    <?php } ?>
                    <a href="<?php echo admin_url('admin.php?page=PluginsAutoUpdate'); ?>" class="mainwp-submenu"><?php _e('Auto Updates','mainwp'); ?></a>
                    <a href="<?php echo admin_url('admin.php?page=PluginsIgnore'); ?>" class="mainwp-submenu"><?php _e('Ignored Updates','mainwp'); ?></a>
                    <a href="<?php echo admin_url('admin.php?page=PluginsIgnoredConflicts'); ?>" class="mainwp-submenu"><?php _e('Ignored Conflicts','mainwp'); ?></a>
                    <?php
                    if (isset(self::$subPages) && is_array(self::$subPages))
                    {
                        foreach (self::$subPages as $subPage)
                        {
                    ?>
                            <a href="<?php echo admin_url('admin.php?page=Plugins'.$subPage['slug']); ?>" class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
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
        <a href="http://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50" alt="MainWP" /></a>
        <img src="<?php echo plugins_url('images/icons/mainwp-plugin.png', dirname(__FILE__)); ?>" style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Plugin" height="32"/>
        <h2><?php _e('Plugins','mainwp'); ?></h2><div style="clear: both;"></div><br/>
        <div id="mainwp-tip-zone">
          <?php if ($shownPage == 'Manage') { ?> 
                <div class="mainwp-tips mainwp_info-box-blue"><span class="mainwp-tip"><strong><?php _e('MainWP Tip','mainwp'); ?>: </strong><?php _e('You can also quickly activate and deactivate installed Plugins for a single site from your Individual Site Dashboard Plugins widget by visiting Sites &rarr; Manage Sites &rarr; Child Site &rarr; Dashboard.','mainwp'); ?></span><span><a href="#" class="mainwp-dismiss" ><?php _e('Dismiss','mainwp'); ?></a></span></div>
          <?php } ?>
          <?php if ($shownPage == 'Install') { ?> 
                <div class="mainwp-tips mainwp_info-box-blue"><span class="mainwp-tip"><strong><?php _e('MainWP Tip','mainwp'); ?>: </strong><?php _e('If you check the “Overwrite Existing” option while installing a plugin you can easily update or rollback the plugin on your child sites.','mainwp'); ?></span><span><a href="#" class="mainwp-dismiss" ><?php _e('Dismiss','mainwp'); ?></a></span></div>
          <?php } ?>
        </div>
        <div class="mainwp-tabs" id="mainwp-tabs">
                <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'Manage') { echo "nav-tab-active"; } ?>" href="admin.php?page=PluginsManage"><?php _e('Manage','mainwp'); ?></a>
                <?php if (mainwp_current_user_can("dashboard", "install_plugins")) { ?>
                <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'Install') { echo "nav-tab-active"; } ?>" href="admin.php?page=PluginsInstall"><?php _e('Install','mainwp'); ?></a>
                <?php } ?>
                <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'AutoUpdate') { echo "nav-tab-active"; } ?>" href="admin.php?page=PluginsAutoUpdate"><?php _e('Auto Updates','mainwp'); ?></a>
                <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'Ignore') { echo "nav-tab-active"; } ?>" href="admin.php?page=PluginsIgnore"><?php _e('Ignored Updates','mainwp'); ?></a>
                <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'IgnoredConflicts') { echo "nav-tab-active"; } ?>" href="admin.php?page=PluginsIgnoredConflicts"><?php _e('Ignored Conflicts','mainwp'); ?></a>
                <a style="float: right" class="mainwp-help-tab nav-tab pos-nav-tab <?php if ($shownPage === 'PluginsHelp') { echo "nav-tab-active"; } ?>" href="admin.php?page=PluginsHelp"><?php _e('Help','mainwp'); ?></a>
                <?php
                if (isset(self::$subPages) && is_array(self::$subPages))
                {
                    foreach (self::$subPages as $subPage)
                    {
                    ?>
                        <a class="nav-tab pos-nav-tab <?php if ($shownPage === $subPage['slug']) { echo "nav-tab-active"; } ?>" href="admin.php?page=Plugins<?php echo $subPage['slug']; ?>"><?php echo $subPage['title']; ?></a>
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
        $cachedSearch = MainWPCache::getCachedContext('Plugins');
        ?>
        <?php self::renderHeader('Manage'); ?>
        <div class="mainwp_info-box"><strong><?php _e('Use this to bulk (de)activate or delete plugins. To add new plugins click on the "Install" tab.','mainwp'); ?></strong></div>
        <br/>
        <div class="mainwp-search-form">
            <div class="postbox mainwp-postbox">
            <h3 class="mainwp_box_title"><?php _e('Search Plugins','mainwp'); ?></h3>
            <div class="inside">
            <p>
                <?php _e('Status:','mainwp'); ?><br />
                <select name="mainwp_plugin_search_by_status" id="mainwp_plugin_search_by_status">
                    <option value="active" <?php if ($cachedSearch != null && $cachedSearch['the_status'] == 'active') { echo 'selected'; } ?>><?php _e('Active','mainwp'); ?></option>
                    <option value="inactive" <?php if ($cachedSearch != null && $cachedSearch['the_status'] == 'inactive') { echo 'selected'; } ?>><?php _e('Inactive','mainwp'); ?></option>
                </select>
            </p>
            <p>
                <?php _e('Containing Keyword:','mainwp'); ?><br/>
                <input type="text" id="mainwp_plugin_search_by_keyword"  class="mainwp-field mainwp-keyword"  size="50" value="<?php if ($cachedSearch != null) { echo $cachedSearch['keyword']; } ?>"/>
            </p>
            </div>
            </div>
            <?php MainWPUI::select_sites_box(__("Select Sites", 'mainwp'), 'checkbox', true, true, 'mainwp_select_sites_box_left'); ?>
            <div style="clear: both;"></div>
            <input type="button" name="mainwp_show_plugins" id="mainwp_show_plugins" class="button-primary" value="<?php _e('Show Plugins','mainwp'); ?>"/>
            <span id="mainwp_plugins_loading">&nbsp;<em><?php _e('Grabbing information from Child Sites','mainwp') ?></em>&nbsp;&nbsp;<img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/></span> <span id="mainwp_plugins_loading_info"><?php _e('Automatically refreshing to get up to date information.','mainwp'); ?></span>
        <br><br>
        </div>
        <div class="clear"></div>
        <div id="mainwp_plugins_error"></div>
        <div id="mainwp_plugins_main" <?php if ($cachedSearch != null) { echo 'style="display: block;"'; } ?>>
            <div id="mainwp_plugins_content">
                <?php MainWPCache::echoBody('Plugins'); ?>
            </div>
        </div>
    <?php
        if ($cachedSearch != null) { echo '<script>mainwp_plugins_all_table_reinit();</script>'; }
        self::renderFooter('Manage');
    }

    public static function renderAllActiveTable($output = null)
    {  
        $keyword = null;
        $search_status = 'all';
        
        if ($output == null)
        {
            $keyword = isset($_POST['keyword']) && !empty($_POST['keyword']) ? trim($_POST["keyword"]) : null;
            $search_status = isset($_POST['status']) ? $_POST['status'] : "all";
            $search_plugin_status = isset($_POST['plugin_status']) ? $_POST['plugin_status'] : "all";
            
            $output = new stdClass();
            $output->errors = array();
            $output->plugins = array();

            if (get_option('mainwp_optimize') == 1)
            {
                //Fetch all!
                //Build websites array
                //Search in local cache
                $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
                while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                {
                    $allPlugins = json_decode($website->plugins, true);
                    for ($i = 0; $i < count($allPlugins); $i++)
                    {
                        $plugin = $allPlugins[$i];                        
                        if ($search_plugin_status != "all") {
                            if ($plugin['active'] == 1 && $search_plugin_status !== "active")
                                continue;
                            else if ($plugin['active'] != 1 && $search_plugin_status !== "inactive")
                                continue;
                        }
                        
                        if ($keyword != '' && stristr($theme['name'], $keyword) === false) 
                            continue;
                        $plugin['websiteid'] = $website->id;
                        $plugin['websiteurl'] = $website->url;
                        $output->plugins[] = $plugin;
                    }
                }
                @MainWPDB::free_result($websites);
            }
            else
            {
                //Fetch all!
                //Build websites array
                $dbwebsites = array();
                $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
                while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                {
                    $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                }
                @MainWPDB::free_result($websites);

                $post_data = array(
                    'keyword' => $keyword                    
                );
                
                if ($search_plugin_status == "active" || $search_plugin_status == "inactive") {
                    $post_data['status'] = $search_plugin_status;
                    $post_data['filter'] = true;
                } else {
                    $post_data['filter'] = false;
                } 
                MainWPUtility::fetchUrlsAuthed($dbwebsites, 'get_all_plugins', $post_data, array(MainWPPlugins::getClassName(), 'PluginsSearch_handler'), $output);

                if (count($output->errors) > 0)
                {
                    foreach ($output->errors as $siteid => $error)
                    {
                        echo '<strong>Error on ' . MainWPUtility::getNiceURL($dbwebsites[$siteid]->url) . ': ' . $error . ' <br /></strong>';
                    }
                    echo '<br />';

                    if (count($output->errors) == count($dbwebsites))
                    {
                        session_start();
                        $_SESSION['MainWPPluginsActive'] = $output;
                        $_SESSION['MainWPPluginsActiveStatus'] = array('keyword' => $keyword, 'status' => $search_status , 'plugin_status' => $search_plugin_status);
                        return;
                    }
                }
            }

            if (session_id() == '') session_start();
            $_SESSION['MainWPPluginsActive'] = $output;
            $_SESSION['MainWPPluginsActiveStatus'] = array('keyword' => $keyword, 'status' => $search_status, 'plugin_status' => $search_plugin_status);
            
        } else {
            if (isset($_SESSION['MainWPPluginsActiveStatus'])) {
                $keyword = $_SESSION['MainWPPluginsActiveStatus']['keyword'];
                $search_status = $_SESSION['MainWPPluginsActiveStatus']['status'];
                $search_plugin_status = $_SESSION['MainWPPluginsActiveStatus']['plugin_status'];               
            }
        }
        
        if ($search_plugin_status != 'inactive') {
            if (empty($keyword) || (!empty($keyword) && stristr("MainWP Child", $keyword) !== false)) 
                $output->plugins[] = array('slug' => 'mainwp-child/mainwp-child.php', 'name' => 'MainWP Child', 'active' => 1);
        }
        
        if (count($output->plugins) == 0)
        {
            _e('No plugins found', 'mainwp');
            return;
        }
        ?>
    <div class="alignleft">
        <select name="bulk_action" id="mainwp_bulk_action">
            <option value="none"><?php _e('Choose Action','mainwp'); ?></option>
            <option value="trust"><?php _e('Trust','mainwp'); ?></option>
            <option value="untrust"><?php _e('Untrust','mainwp'); ?></option>
        </select> <input type="button" name="" id="mainwp_bulk_trust_plugins_action_apply" class="button" value="<?php _e('Confirm','mainwp'); ?>"/> <span id="mainwp_bulk_action_loading"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/></span>
    </div>
    <div class="clear"></div>

    <?php        
        //Map per siteId
        $plugins = array(); //name_version -> slug
        foreach ($output->plugins as $plugin) {
            $plugins[$plugin['slug']] = $plugin;
        }
        asort($plugins);

        $userExtension = MainWPDB::Instance()->getUserExtension();
        $decodedIgnoredPlugins = json_decode($userExtension->ignored_plugins, true);
        $trustedPlugins = json_decode($userExtension->trusted_plugins, true);
        if (!is_array($trustedPlugins)) $trustedPlugins = array();
        $trustedPluginsNotes = json_decode($userExtension->trusted_plugins_notes, true);
        if (!is_array($trustedPluginsNotes)) $trustedPluginsNotes = array();

        ?>
        <table id="mainwp_active_plugins_table" class="wp-list-table widefat fixed posts tablesorter" cellspacing="0">
            <thead>
            <tr>
                <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input name="plugins" type="checkbox"></th>
                <th scope="col" id="info" class="manage-column column-cb check-column" style=""></th>
                <th scope="col" id="plugin" class="manage-column column-title sortable desc" style="">
                    <a href="#"><span><?php _e('Plugin','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                </th>
                <th scope="col" id="plgstatus" class="manage-column column-title sortable desc" style="">
                    <a href="#"><span><?php _e('Status','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                </th>
                <th scope="col" id="trustlvl" class="manage-column column-title sortable desc" style="">
                    <a href="#"><span><?php _e('Trust Level','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                </th>
                <th scope="col" id="ignoredstatus" class="manage-column column-title sortable desc" style="">
                    <a href="#"><span><?php _e('Ignored Status','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                </th>
                <th scope="col" id="notes" class="manage-column column-posts" style=""><?php _e('Notes','mainwp'); ?></th>
            </tr>
            </thead>

            <tfoot>
            <tr>
                <th scope="col" class="manage-column column-cb check-column" style=""><input name="plugins" type="checkbox"></th>
                <th scope="col" id="info_footer" class="manage-column column-cb check-column" style=""></th>
                <th scope="col" id="plugin_footer" class="manage-column column-title desc" style=""><span><?php _e('Plugin','mainwp'); ?></span></th>
                <th scope="col" id="plgstatus_footer" class="manage-column column-posts" style=""><?php _e('Status','mainwp'); ?></th>
                <th scope="col" id="trustlvl_footer" class="manage-column column-posts" style=""><?php _e('Trust Level','mainwp'); ?></th>
                <th scope="col" id="ignoredstatus_footer" class="manage-column column-posts" style=""><?php _e('Ignored Status','mainwp'); ?></th>
                <th scope="col" id="notes_footer" class="manage-column column-posts" style=""><?php _e('Notes','mainwp'); ?></th>
            </tr>
            </tfoot>

            <tbody id="the-posts-list" class="list:posts">
                <?php
                    foreach ($plugins as $slug => $plugin)
                    {
                        $name = $plugin['name'];
                        if (!empty($search_status) && $search_status != "all") {
                            if ($search_status == "trust" && !in_array($slug, $trustedPlugins))
                                continue;
                            else if ($search_status == "untrust" && in_array($slug, $trustedPlugins))
                                continue;
                            else if ($search_status == "ignored" && !isset($decodedIgnoredPlugins[$slug]))
                                continue;
                        }
                     ?>
                    <tr id="post-1" class="post-1 post type-post status-publish format-standard hentry category-uncategorized alternate iedit author-self" valign="top" plugin_slug="<?php echo rawurlencode($slug); ?>" plugin_name="<?php echo urlencode($name); ?>">
                        <th scope="row" class="check-column"><input type="checkbox" name="plugin[]" value="<?php echo urlencode($slug); ?>"></th>
                        <td scope="col" id="info_content" class="manage-column" style=""> <?php if (isset($decodedIgnoredPlugins[$slug])) { MainWPUtility::renderToolTip('Ignored plugins will NOT be auto-updated.', null, 'images/icons/mainwp-red-info-16.png'); } ?></td>
                        <td scope="col" id="plugin_content" class="manage-column sorted" style="">
                            <a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin='.urlencode(dirname($slug)).'&TB_iframe=true&width=640&height=477'; ?>" target="_blank" class="thickbox" title="More information about <?php echo $name; ?>"><?php echo $name; ?></a>
                        </td>
                        <td scope="col" id="plgstatus_content" class="manage-column" style="">
                            <?php echo ($plugin['active'] == 1) ? __("Active", "mainwp") : __("Inactive", "mainwp"); ?>
                        </td>
                        <td scope="col" id="trustlvl_content" class="manage-column" style="">
                            <?php
                                if (in_array($slug, $trustedPlugins))
                                {
                                    echo '<font color="#7fb100">Trusted</font>';
                                }
                                else
                                {
                                    echo '<font color="#c00">Not Trusted</font>';
                                }
                            ?>
                        </td>
                        <td scope="col" id="ignoredstatus_content" class="manage-column" style="">
                            <?php if (isset($decodedIgnoredPlugins[$slug])) { echo '<font color="#c00">Ignored</font>'; } ?>
                        </td>
                        <td scope="col" id="notes_content" class="manage-column" style="">
                            <img src="<?php echo plugins_url('images/notes.png', dirname(__FILE__)); ?>" class="mainwp_notes_img" <?php if (!isset($trustedPluginsNotes[$slug]) || $trustedPluginsNotes[$slug] == '') { echo 'style="display: none;"'; } ?> />
                            <a href="#" class="mainwp_trusted_plugin_notes_show"><?php _e('Open','mainwp'); ?></a>
                            <div style="display: none" class="note"><?php if (isset($trustedPluginsNotes[$slug])) { echo $trustedPluginsNotes[$slug]; } ?></div>
                        </td>
                    </tr>
                            <?php
                    }
                ?>
            </tbody>
        </table>
        <div id="mainwp_notes_overlay" class="mainwp_overlay"></div>
        <div id="mainwp_notes" class="mainwp_popup">
            <a id="mainwp_notes_closeX" class="mainwp_closeX" style="display: inline; "></a>

            <div id="mainwp_notes_title" class="mainwp_popup_title"></span>
            </div>
            <div id="mainwp_notes_content">
                <textarea style="width: 580px !important; height: 300px;"
                          id="mainwp_notes_note"></textarea>
            </div>
            <form>
                <div style="float: right" id="mainwp_notes_status"></div>
                <input type="button" class="button cont button-primary" id="mainwp_trusted_plugin_notes_save" value="<?php _e('Save Note','mainwp'); ?>"/>
                <input type="button" class="button cont" id="mainwp_notes_cancel" value="<?php _e('Close','mainwp'); ?>"/>
                <input type="hidden" id="mainwp_notes_slug" value=""/>
            </form>
        </div>
        <div class="pager" id="pager">
            <form>
                <img src="<?php echo plugins_url('images/first.png', dirname(__FILE__)); ?>" class="first">
                <img src="<?php echo plugins_url('images/prev.png', dirname(__FILE__)); ?>" class="prev">
                <input type="text" class="pagedisplay">
                <img src="<?php echo plugins_url('images/next.png', dirname(__FILE__)); ?>" class="next">
                <img src="<?php echo plugins_url('images/last.png', dirname(__FILE__)); ?>" class="last">
                <span>&nbsp;&nbsp;<?php _e('Show:','mainwp'); ?> </span><select class="pagesize">
                    <option selected="selected" value="10">10</option>
                    <option value="20">20</option>
                    <option value="30">30</option>
                    <option value="40">40</option>
                </select><span> <?php _e('Plugins per page','mainwp'); ?></span>
            </form>
        </div>

        <?php
    }

    public static function renderTable($keyword, $status, $groups, $sites)
    {
        MainWPCache::initCache('Plugins');

        $output = new stdClass();
        $output->errors = array();
        $output->plugins = array();

        if (get_option('mainwp_optimize') == 1)
        {

            if ($sites != '') {
                foreach ($sites as $k => $v) {
                    if (MainWPUtility::ctype_digit($v)) {
                        $website = MainWPDB::Instance()->getWebsiteById($v);

                        $allPlugins = json_decode($website->plugins, true);
                        for ($i = 0; $i < count($allPlugins); $i++) {
                            $plugin = $allPlugins[$i];
                            if ($plugin['active'] != (($status == 'active') ? 1 : 0)) continue;
                            if ($keyword != '' && !stristr($plugin['name'], $keyword)) continue;

                            $plugin['websiteid'] = $website->id;
                            $plugin['websiteurl'] = $website->url;
                            $output->plugins[] = $plugin;
                        }
                    }
                }
            }
            if ($groups != '') {
                //Search in local cache
                foreach ($groups as $k => $v) {
                    if (MainWPUtility::ctype_digit($v)) {
                        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupId($v));
                        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                        {
                            if ($website->sync_errors != '') continue;
                            $allPlugins = json_decode($website->plugins, true);
                            for ($i = 0; $i < count($allPlugins); $i++) {
                                $plugin = $allPlugins[$i];
                                if ($plugin['active'] != (($status == 'active') ? 1 : 0)) continue;
                                if ($keyword != '' && !stristr($plugin['name'], $keyword)) continue;

                                $plugin['websiteid'] = $website->id;
                                $plugin['websiteurl'] = $website->url;
                                $output->plugins[] = $plugin;
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

            $post_data = array(
                'keyword' => $keyword,
                'status' => $status
            );
            MainWPUtility::fetchUrlsAuthed($dbwebsites, 'get_all_plugins', $post_data, array(MainWPPlugins::getClassName(), 'PluginsSearch_handler'), $output);

            if (count($output->errors) > 0)
            {
                foreach ($output->errors as $siteid => $error)
                {
                    echo '<strong>Error on '.MainWPUtility::getNiceURL($dbwebsites[$siteid]->url).': '.$error.' <br /></strong>';
                }
                echo '<br />';
            }

            if (count($output->errors) == count($dbwebsites))
            {
                return;
            }
        }

        MainWPCache::addContext('Plugins', array('keyword' => $keyword, 'the_status' => $status));

        ob_start();
        ?>
    <div class="alignleft">
        <select name="bulk_action" id="mainwp_bulk_action">
            <option value="none"><?php _e('Choose Action','mainwp'); ?></option>
            <?php if (mainwp_current_user_can("dashboard", "activate_deactivate_plugins")) { ?>
            <?php if ($status == 'active') { ?>
            <option value="deactivate"><?php _e('Deactivate','mainwp'); ?></option>
            <?php } ?>
            <?php } // ?>
            <?php if ($status == 'inactive') { ?>
            <?php if (mainwp_current_user_can("dashboard", "activate_deactivate_plugins")) { ?>
            <option value="activate"><?php _e('Activate','mainwp'); ?></option>
            <?php } // ?>
            <?php if (mainwp_current_user_can("dashboard", "delete_plugins")) { ?>
            <option value="delete"><?php _e('Delete','mainwp'); ?></option>            
            <?php } // ?>
            <?php } ?>  
            <?php if (mainwp_current_user_can("dashboard", "ignore_unignore_updates")) { ?>   
            <option value="ignore_updates"><?php _e('Ignore Updates','mainwp'); ?></option>    
            <?php } ?>  
        </select> <input type="button" name="" id="mainwp_bulk_plugins_action_apply" class="button" value="<?php _e('Confirm','mainwp'); ?>"/> <span id="mainwp_bulk_action_loading"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/></span>&nbsp;<span><a href="http://docs.mainwp.com/why-does-the-mainwp-client-plugin-not-show-up-on-the-plugin-list-for-my-managed-site/" target="_blank"><?php _e('Why does the MainWP Child Plugin NOT show here?','mainwp'); ?></a></span>
    </div>
    <div class="clear"></div>


    <?php
        if (count($output->plugins) == 0) {
            ?>
        No plugins found
        <?php
            $newOutput = ob_get_clean();
            echo $newOutput;
            MainWPCache::addBody('Plugins', $newOutput);
            return;
        }

        //Map per siteId
        $sites = array(); //id -> url
        $sitePlugins = array(); //site_id -> plugin_version_name -> plugin obj
        $plugins = array(); //name_version -> slug
        $pluginsVersion = $pluginsName = array(); //name_version -> title_version
        foreach ($output->plugins as $plugin) {
            $sites[$plugin['websiteid']] = $plugin['websiteurl'];
            $plugins[$plugin['name'] . '_' . $plugin['version']] = $plugin['slug'];
            $pluginsName[$plugin['name'] . '_' . $plugin['version']] = $plugin['name'];
            $pluginsVersion[$plugin['name'] . '_' . $plugin['version']] = $plugin['name'] . ' ' . $plugin['version'];
            if (!isset($sitePlugins[$plugin['websiteid']]) || !is_array($sitePlugins[$plugin['websiteid']])) $sitePlugins[$plugin['websiteid']] = array();
            $sitePlugins[$plugin['websiteid']][$plugin['name'] . '_' . $plugin['version']] = $plugin;
        }
        ?>
<div id="mainwp-table-overflow" style="overflow: auto !important ;">
    <table class="ui-tinytable wp-list-table widefat fixed pages" style="width: auto; word-wrap: normal">
        <thead>
        <tr>
            <th class="headcol"></th>
            <?php
            foreach ($pluginsVersion as $plugin_name => $plugin_title) {
                echo '<th style="height: 100px; padding: 5px ;" class="long">
<p style="font-family: Arial, Sans-Serif; text-shadow: none ; width: 100px !important; height: 30px ; text-align: center; width: auto; height: auto; font-size: 13px; -webkit-transform: rotate(-90deg); -moz-transform: rotate(-90deg); -o-transform: rotate(-90deg); -ms-transform: rotate(-90deg); writing-mode: lr-tb; ">
<input type="checkbox" value="' . $plugins[$plugin_name] . '" id="' . $plugin_name . '" class="mainwp_plugin_check_all" style="margin: 3px 0px 0px 0px; display: none ; " />
<label for="' . $plugin_name . '">' . $plugin_title . '</label>
</p>
</th>';
            }
            ?>
        </tr>
        </thead>
        <tbody>
            <?php
            foreach ($sites as $site_id => $site_url) {
                ?>
            <tr>
                <td class="headcol">
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $site_id; ?>"/>
                    <label for="<?php echo $site_url; ?>"><strong><?php echo $site_url; ?></strong></label>
                    &nbsp;&nbsp;<input type="checkbox" value="" id="<?php echo $site_url; ?>"
                                       class="mainwp_site_check_all" style="display: none ;"/>
                </td>
                <?php
                foreach ($pluginsVersion as $plugin_name => $plugin_title) {
                    echo '<td class="long" style="text-align: center">';
                    if (isset($sitePlugins[$site_id]) && isset($sitePlugins[$site_id][$plugin_name])) {
                        echo '<input type="checkbox" value="' . $plugins[$plugin_name] . '" name="'. $pluginsName[$plugin_name].'" class="selected_plugin" />';
                    }
                    echo '</td>';
                }
                ?>
            </tr>
                <?php
            }
            ?>
        </tbody>
    </table>
</div>
        <?php
        $newOutput = ob_get_clean();
        echo $newOutput;
        MainWPCache::addBody('Plugins', $newOutput);
    }

    public static function PluginsSearch_handler($data, &$website, &$output)
    {
        if (preg_match('/<mainwp>(.*)<\/mainwp>/', $data, $results) > 0) {
            $plugins = unserialize(base64_decode($results[1]));
            unset($results);
            if (isset($plugins['error']))
            {
                $output->errors[$website->id] = MainWPErrorHelper::getErrorMessage(new MainWPException($plugins['error'], $website->url));
                return;
            }

            foreach ($plugins as $plugin) {
                if (!isset($plugin['name'])) continue;
                $plugin['websiteid'] = $website->id;
                $plugin['websiteurl'] = $website->url;

                $output->plugins[] = $plugin;
            }
            //$output->plugins = array_merge($output->plugins, $plugins);
            unset($plugins);
        } else {
            $output->errors[$website->id] = MainWPErrorHelper::getErrorMessage(new MainWPException('NOMAINWP', $website->url));
        }
    }

    public static function activatePlugins()
    {
        MainWPPlugins::action('activate');
    }

    public static function deactivatePlugins()
    {
        MainWPPlugins::action('deactivate');
    }

    public static function deletePlugins()
    {
        MainWPPlugins::action('delete');
    }
    
    public static function ignoreUpdates()
    {
        $websiteIdEnc = $_POST['websiteId'];

        $websiteId = $websiteIdEnc;
        if (!MainWPUtility::ctype_digit($websiteId)) die(json_encode(array('error' => 'Invalid request.')));

        $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        if (!MainWPUtility::can_edit_website($website)) die(json_encode(array('error' => 'You can not edit this website.')));

        $plugins = $_POST['plugins'];  
        $names = $_POST['names'];  
        
        $decodedIgnoredPlugins = json_decode($website->ignored_plugins, true);
        if (!is_array($decodedIgnoredPlugins)) $decodedIgnoredPlugins = array();            
        
                    
        if (is_array($plugins)) {
            for($i = 0; $i < count($plugins); $i++) {
                $slug = $plugins[$i];
                $name = $names[$i];
                if (!isset($decodedIgnoredPlugins[$slug]))
                {
                    $decodedIgnoredPlugins[$slug] = urldecode($name);
                }
            }
            MainWPDB::Instance()->updateWebsiteValues($website->id, array('ignored_plugins' => json_encode($decodedIgnoredPlugins)));
        }
        
        die(json_encode(array('result' => true)));
    }
    

    public static function action($pAction)
    {
        $websiteIdEnc = $_POST['websiteId'];

        $websiteId = $websiteIdEnc;
        if (!MainWPUtility::ctype_digit($websiteId)) die(json_encode(array('error' => 'Invalid request.')));

        $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        if (!MainWPUtility::can_edit_website($website)) die(json_encode(array('error' => 'You can not edit this website.')));

        try {
            $plugin = implode('||', $_POST['plugins']);
            $information = MainWPUtility::fetchUrlAuthed($website, 'plugin_action', array(
                'action' => $pAction,
                'plugin' => $plugin));
        } catch (MainWPException $e) {
            die(json_encode(array('error' => $e->getMessage())));
        }

        if (!isset($information['status']) || ($information['status'] != 'SUCCESS')) die(json_encode(array('error' => 'Unexpected error.')));

        die(json_encode(array('result' => true)));
    }

    //@see MainWPInstallBulk
    public static function renderInstall()
    {
        self::renderHeader('Install');
        MainWPInstallBulk::render('Plugins', 'plugin');
        self::renderFooter('Install');
    }

    //Performs a search
    public static function performSearch()
    {
        MainWPInstallBulk::performSearch(MainWPPlugins::getClassName(), 'Plugins');
    }

    public static function renderFound($api)
    {
        ?>
    <table class="wp-list-table widefat plugin-install" cellspacing="0">
        <thead>
        <tr>
            <th scope="col" id="name" class="manage-column column-name" style=""><?php  _e('Name','mainwp'); ?></th>
            <th scope="col" id="version" class="manage-column column-version" style=""><?php _e('Version','mainwp'); ?></th>
            <th scope="col" id="rating" class="manage-column column-rating" style=""><?php _e('Rating','mainwp'); ?></th>
            <th scope="col" id="description" class="manage-column column-description" style=""><?php _e('Description','mainwp'); ?></th>
        </tr>
        </thead>
        <tfoot>
        <tr>
            <th scope="col" class="manage-column column-name" style=""><?php _e('Name','mainwp'); ?></th>
            <th scope="col" class="manage-column column-version" style=""><?php _e('Version','mainwp'); ?></th>
            <th scope="col" class="manage-column column-rating" style=""><?php _e('Rating','mainwp'); ?></th>
            <th scope="col" class="manage-column column-description" style=""><?php _e('Description','mainwp'); ?></th>
        </tr>
        </tfoot>
        <tbody id="the-list">
            <?php
            if (!isset($api) || !isset($api->info['results']) || $api->info['results'] == 0) {
                ?>
            <tr class="no-items">
                <td class="colspanchange" colspan="4"><?php _e('No plugins match your request.','mainwp'); ?></td>
            </tr>
                <?php
            } else {
                foreach ($api->plugins as $plugin) {
                    $author = $plugin->author;
                    if (!empty($author))
                        $author = ' ' . sprintf(__('By %s'), $author) . '.';

                    //Limit description to 400char, and remove any HTML.
                    $description = strip_tags($plugin->description);

                    if (strlen($description) > 400)
                        $description = mb_substr($description, 0, 400) . '&#8230;';
                    //remove any trailing entities
                    $description = preg_replace('/&[^;\s]{0,6}$/', '', $description);
                    //strip leading/trailing & multiple consecutive lines
                    $description = trim($description);
                    $description = preg_replace("|(\r?\n)+|", "\n", $description);
                    //\n => <br>
                    $description = nl2br($description);
                    ?>
                <tr>
                    <td class="name column-name"><strong><?php echo $plugin->name; ?></strong>

                        <div class="action-links"><a
                                href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin='.$plugin->slug.'&TB_iframe=true&width=640&height=477'; ?>" target="_blank"
                                class="thickbox" title="More information about <?php echo $plugin->name; ?>"><?php _e('Details','mainwp'); ?></a> |
                            <a class="install-now" href="#" id="install-plugin-<?php echo $plugin->slug; ?>"
                               title="Install <?php echo $plugin->name; ?>  <?php echo $plugin->version; ?>"><?php _e('Install Now','mainwp'); ?></a>
                            <?php do_action('mainwp_installplugins_extra_links', $plugin); ?>
                        </div>
                    </td>
                    <td class="vers column-version"><?php echo $plugin->version; ?></td>
                    <td class="vers column-rating">
                        <?php
                        if (function_exists('wp_star_rating'))
                        {
                            wp_star_rating(array('rating' => esc_attr($plugin->rating), 'type' => 'percent', 'number' => $plugin->num_ratings));
                        }
                        else
                        {
                        ?>

                        <div class="legacy-star-holder star-holder" title="(based on <?php echo $plugin->num_ratings; ?> rating<?php
                            if ($plugin->num_ratings > 1) {
                                echo 's';
                            }
                            ?>)">
                            <div class="legacy-star legacy-star-rating star star-rating"
                                 style="width: <?php echo esc_attr($plugin->rating) ?>px"></div>
                        </div>
                        <?php
                        }
                        ?>
                    </td>
                    <td class="desc column-description"><?php echo $description, $author; ?></td>
                </tr>
                    <?php
                }
            }
            ?>
        </tbody>
    </table>
    <?php
        die();
    }

    public static function renderAutoUpdate()
    {
        $cachedAUSearch = null;
        if (isset($_SESSION['MainWPPluginsActiveStatus'])) {
            $cachedAUSearch = $_SESSION['MainWPPluginsActiveStatus'];          
        }            
        self::renderHeader('AutoUpdate');
        if (!mainwp_current_user_can("dashboard", "trust_untrust_updates")) {
            mainwp_do_not_have_permissions("Trust/Untrust updates");
        } else {
            $snAutomaticDailyUpdate = get_option('mainwp_automaticDailyUpdate');
            ?>
            <h2><?php _e('Plugin Automatic Update Trust List','mainwp'); ?></h2>
            <br />
            <div id="mainwp-au" class=""><strong><?php if ($snAutomaticDailyUpdate == 1) { ?>
                <div class="mainwp-au-on"><?php _e('Auto Updates are ON and Trusted Plugins will be Automatically Updated','mainwp'); ?> - <a href="<?php echo admin_url(); ?>admin.php?page=Settings"><?php _e('Change this in Settings','mainwp'); ?></a></div>
            <?php } elseif (($snAutomaticDailyUpdate === false) || ($snAutomaticDailyUpdate == 2)) { ?>
                <div class="mainwp-au-email"><?php _e('Auto Updates are OFF - Email Update Notification is ON','mainwp'); ?> - <a href="<?php echo admin_url(); ?>admin.php?page=Settings"><?php _e('Change this in Settings','mainwp'); ?></a></div>
            <?php } else { ?>
                <div class="mainwp-au-off"><?php _e('Auto Updates are OFF - Email Update Notification is OFF','mainwp'); ?> - <a href="<?php echo admin_url(); ?>admin.php?page=Settings"><?php _e('Change this in Settings','mainwp'); ?></a></div>
            <?php } ?></strong></div>
            <div class="mainwp_info-box"><?php _e('Only mark Plugins as Trusted if you are absolutely sure they can be updated without breaking your sites or your network.','mainwp'); ?> <strong><?php _e('Ignored Plugins can not be Automatically Updated.','mainwp'); ?></strong></div>
            <div class="postbox">
                <h3 class="mainwp_box_title"><?php _e('Search Plugins','mainwp'); ?></h3>
            <div class="inside">
                    <span><?php _e("Status:", "mainwp"); ?> </span>
                    <select id="mainwp_au_plugin_status">
                        <option value="all" <?php if ($cachedAUSearch != null && $cachedAUSearch['plugin_status'] == 'all') { echo 'selected'; } ?>><?php _e('All Plugins','mainwp'); ?></option>
                        <option value="active" <?php if ($cachedAUSearch != null && $cachedAUSearch['plugin_status'] == 'active') { echo 'selected'; } ?>><?php _e('Active Plugins','mainwp'); ?></option>
                        <option value="inactive" <?php if ($cachedAUSearch != null && $cachedAUSearch['plugin_status'] == 'inactive') { echo 'selected'; } ?>><?php _e('Inactive Plugins','mainwp'); ?></option>                        
                    </select>&nbsp;&nbsp;
                    <span><?php _e("Trust Status:", "mainwp"); ?> </span>
                        <select id="mainwp_au_plugin_trust_status">
                            <option value="all" <?php if ($cachedAUSearch != null && $cachedAUSearch['status'] == 'all') { echo 'selected'; } ?>><?php _e('All Plugins','mainwp'); ?></option>
                            <option value="trust" <?php if ($cachedAUSearch != null && $cachedAUSearch['status'] == 'trust') { echo 'selected'; } ?>><?php _e('Trusted Plugins','mainwp'); ?></option>
                            <option value="untrust" <?php if ($cachedAUSearch != null && $cachedAUSearch['status'] == 'untrust') { echo 'selected'; } ?>><?php _e('Not Trusted Plugins','mainwp'); ?></option>
                            <option value="ignored" <?php if ($cachedAUSearch != null && $cachedAUSearch['status'] == 'ignored') { echo 'selected'; } ?>><?php _e('Ignored Plugins','mainwp'); ?></option>
                        </select>&nbsp;&nbsp;
                    <span><?php _e("Containing Keywords:", "mainwp"); ?> </span>
                    <input type="text" class="mainwp-field mainwp-keyword" id="mainwp_au_plugin_keyword" style="width: 350px;" value="<?php echo ($cachedAUSearch !== null) ? $cachedAUSearch['keyword'] : "";?>">&nbsp;&nbsp;
                    <a href="#" class="button-primary" id="mainwp_show_all_active_plugins"><?php _e('Show Plugins','mainwp'); ?></a>
                    <span id="mainwp_plugins_loading"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/></span>
            </div>
            </div>



            <div id="mainwp_plugins_main" style="display: block; margin-top: 1.5em ;">
                <div id="mainwp_plugins_content">
                <?php
                    if (session_id() == '') session_start();
                    if (isset($_SESSION['MainWPPluginsActive'])) {
                        self::renderAllActiveTable($_SESSION['MainWPPluginsActive']);
                        echo '<script>mainwp_active_plugins_table_reinit();</script>';
                    }
                ?>
                </div>
            </div>
            <?php
        }
        self::renderFooter('AutoUpdate');
    }

    public static function ignorePluginThemeConflict($type, $name, $siteid)
    {
        if (MainWPUtility::ctype_digit($siteid))
        {
            $website = MainWPDB::Instance()->getWebsiteById($siteid);
            if (MainWPUtility::can_edit_website($website))
            {
                $name = urldecode($name);
                if ($type == 'plugin')
                {
                    $decodedIgnoredPlugins = json_decode($website->ignored_pluginConflicts, true);
                    if (!is_array($decodedIgnoredPlugins)) $decodedIgnoredPlugins = array();
                    if (!in_array($name, $decodedIgnoredPlugins))
                    {
                        $decodedIgnoredPlugins[] = $name;
                        MainWPDB::Instance()->updateWebsiteValues($website->id, array('ignored_pluginConflicts' => json_encode($decodedIgnoredPlugins)));
                    }
                }
                else if ($type == 'theme')
                {
                    $decodedIgnoredThemes = json_decode($website->ignored_themeConflicts, true);
                    if (!is_array($decodedIgnoredThemes)) $decodedIgnoredThemes = array();
                    if (!in_array($name, $decodedIgnoredThemes))
                    {
                        $decodedIgnoredThemes[] = $name;
                        MainWPDB::Instance()->updateWebsiteValues($website->id, array('ignored_themeConflicts' => json_encode($decodedIgnoredThemes)));
                    }
                }
            }
        }
        else
        {
            //Ignore globally
            $userExtension = MainWPDB::Instance()->getUserExtension();

            $name = urldecode($name);
            if ($type == 'plugin')
            {
                $globalIgnoredPluginConflicts = json_decode($userExtension->ignored_pluginConflicts, true);
                if (!is_array($globalIgnoredPluginConflicts)) $globalIgnoredPluginConflicts = array();
                if (!in_array($name, $globalIgnoredPluginConflicts))
                {
                    $globalIgnoredPluginConflicts[] = $name;
                    $userExtension->ignored_pluginConflicts = json_encode($globalIgnoredPluginConflicts);
                    MainWPDB::Instance()->updateUserExtension($userExtension);
                }
            }
            else if ($type == 'theme')
            {
                $globalIgnoredThemeConflicts = json_decode($userExtension->ignored_themeConflicts, true);
                if (!is_array($globalIgnoredThemeConflicts)) $globalIgnoredThemeConflicts = array();
                if (!in_array($name, $globalIgnoredThemeConflicts))
                {
                    $globalIgnoredThemeConflicts[] = $name;
                    $userExtension->ignored_themeConflicts = json_encode($globalIgnoredThemeConflicts);
                    MainWPDB::Instance()->updateUserExtension($userExtension);
                }
            }

        }
        return 'success';
    }

    public static function unIgnorePluginThemeConflict($type, $name, $siteid)
    {
        if ($siteid != '')
        {
            //For the sites..
            if ($siteid == '_ALL_')
            {
                $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
            }
            else
            {
                $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsiteById($siteid));
            }

            while ($websites && ($website = @MainWPDB::fetch_object($websites)))
            {
                if (MainWPUtility::can_edit_website($website))
                {
                    $name = urldecode($name);
                    if ($type == 'plugin')
                    {
                        $decodedIgnoredPlugins = json_decode($website->ignored_pluginConflicts, true);
                        if (!is_array($decodedIgnoredPlugins)) $decodedIgnoredPlugins = array();
                        if ($name == '')
                        {
                            MainWPDB::Instance()->updateWebsiteValues($website->id, array('ignored_pluginConflicts' => json_encode(array())));
                        }
                        else if (in_array($name, $decodedIgnoredPlugins))
                        {
                            $idx = array_search($name, $decodedIgnoredPlugins);
                            array_splice($decodedIgnoredPlugins, $idx, 1);
                            MainWPDB::Instance()->updateWebsiteValues($website->id, array('ignored_pluginConflicts' => json_encode($decodedIgnoredPlugins)));
                        }
                    }
                    else if ($type == 'theme')
                    {
                        $decodedIgnoredThemes = json_decode($website->ignored_themeConflicts, true);
                        if (!is_array($decodedIgnoredThemes)) $decodedIgnoredThemes = array();
                        if ($name == '')
                        {
                            MainWPDB::Instance()->updateWebsiteValues($website->id, array('ignored_themeConflicts' => json_encode(array())));
                        }
                        else if (in_array($name, $decodedIgnoredThemes))
                        {
                            $idx = array_search($name, $decodedIgnoredThemes);
                            array_splice($decodedIgnoredThemes, $idx, 1);
                            MainWPDB::Instance()->updateWebsiteValues($website->id, array('ignored_themeConflicts' => json_encode($decodedIgnoredThemes)));
                        }
                    }
                }
            }
            @MainWPDB::free_result($websites);
        }
        else
        {
            //unignore globally
            $userExtension = MainWPDB::Instance()->getUserExtension();

            $name = urldecode($name);
            if ($type == 'plugin')
            {
                $globalIgnoredPluginConflicts = json_decode($userExtension->ignored_pluginConflicts, true);
                if (!is_array($globalIgnoredPluginConflicts)) $globalIgnoredPluginConflicts = array();
                if ($name == '')
                {
                    //Unignore all
                    $globalIgnoredPluginConflicts = array();
                    $userExtension->ignored_pluginConflicts = json_encode($globalIgnoredPluginConflicts);
                    MainWPDB::Instance()->updateUserExtension($userExtension);
                }
                else if (in_array($name, $globalIgnoredPluginConflicts))
                {
                    $idx = array_search($name, $globalIgnoredPluginConflicts);
                    array_splice($globalIgnoredPluginConflicts, $idx, 1);
                    $userExtension->ignored_pluginConflicts = json_encode($globalIgnoredPluginConflicts);
                    MainWPDB::Instance()->updateUserExtension($userExtension);
                }
            }
            else if ($type == 'theme')
            {
                $globalIgnoredThemeConflicts = json_decode($userExtension->ignored_themeConflicts, true);
                if (!is_array($globalIgnoredThemeConflicts)) $globalIgnoredThemeConflicts = array();
                if ($name == '')
                {
                    $globalIgnoredThemeConflicts = array();
                    $userExtension->ignored_themeConflicts = json_encode($globalIgnoredThemeConflicts);
                    MainWPDB::Instance()->updateUserExtension($userExtension);
                }
                else if (in_array($name, $globalIgnoredThemeConflicts))
                {
                    $idx = array_search($name, $globalIgnoredThemeConflicts);
                    array_splice($globalIgnoredThemeConflicts, $idx, 1);
                    $userExtension->ignored_themeConflicts = json_encode($globalIgnoredThemeConflicts);
                    MainWPDB::Instance()->updateUserExtension($userExtension);
                }
            }

        }
        return 'success';
    }

    public static function renderIgnoredConflicts()
    {
        //todo: only fetch sites with ignored conflicts..
        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
        $userExtension = MainWPDB::Instance()->getUserExtension();
        $decodedIgnoredPluginConflicts = json_decode($userExtension->ignored_pluginConflicts, true);
        $ignoredPluginConflicts  = (is_array($decodedIgnoredPluginConflicts) && (count($decodedIgnoredPluginConflicts) > 0));

        $cnt = 0;
        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
        {
            $tmpDecodedIgnoredPluginConflicts = json_decode($website->ignored_pluginConflicts, true);
            if (!is_array($tmpDecodedIgnoredPluginConflicts) || count($tmpDecodedIgnoredPluginConflicts) == 0) continue;
            $cnt++;
        }

        self::renderHeader('IgnoredConflicts');
        ?>
        <h2><?php _e('Ignored Plugin Conflict List','mainwp'); ?></h2>
        <table id="mainwp-table" class="wp-list-table widefat" cellspacing="0" style="width: 780px">
            <caption><?php _e('Globally','mainwp'); ?></caption>
            <thead>
                <tr>
                    <th scope="col" class="manage-column" style="width: 650px"><?php _e('Plugins','mainwp'); ?></th>
                    <th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ($ignoredPluginConflicts) { ?><a href="#" class="button-primary mainwp-unignore-globally-all" onClick="return pluginthemeconflict_unignore('plugin', undefined, undefined);"><?php _e('Allow All','mainwp'); ?></a><?php } ?></th>
                </tr>
            </thead>
            <tbody id="globally-ignored-pluginconflict-list" class="list:sites">
            <?php
                if ($ignoredPluginConflicts)
                {
                    foreach ($decodedIgnoredPluginConflicts as $ignoredPluginName)
                    {
                    ?>
                        <tr plugin="<?php echo urlencode($ignoredPluginName); ?>">
                            <td>
                                <strong><?php echo $ignoredPluginName; ?></strong>
                            </td>
                            <td style="text-align: right; padding-right: 30px">
                                <a href="#" onClick="return pluginthemeconflict_unignore('plugin', '<?php echo urlencode($ignoredPluginName); ?>', undefined);"><?php _e('ALLOW','mainwp'); ?></a>
                            </td>
                        </tr>
                <?php
                    }
                ?>
            <?php
                }
                else
                {
                ?>
                        <tr><td colspan="2"><?php _e('No ignored plugin conflicts','mainwp'); ?></td></tr>
                <?php
                }
            ?>
            </tbody>
        </table>

        <table id="mainwp-table" class="wp-list-table widefat" cellspacing="0" style="width: 780px">
            <caption><?php _e('Per Site','mainwp'); ?></caption>
            <thead>
                <tr>
                    <th scope="col" class="manage-column" style="width: 250px"><?php _e('Site','mainwp'); ?></th>
                    <th scope="col" class="manage-column" style="width: 400px"><?php _e('Plugins','mainwp'); ?></th>
                    <th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ($cnt > 0) { ?><a href="#" class="button-primary mainwp-unignore-detail-all" onClick="return pluginthemeconflict_unignore('plugin', undefined, '_ALL_');"><?php _e('Allow All','mainwp'); ?></a><?php } ?></th>
                </tr>
            </thead>
            <tbody id="ignored-pluginconflict-list" class="list:sites">
            <?php
            if ($cnt > 0)
            {
                @MainWPDB::data_seek($websites, 0);
                while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                {                    
                    $decodedIgnoredPluginConflicts = json_decode($website->ignored_pluginConflicts, true);
                    if (!is_array($decodedIgnoredPluginConflicts) || count($decodedIgnoredPluginConflicts) == 0) continue;
                    $first = true;

                    foreach ($decodedIgnoredPluginConflicts as $ignoredPluginConflictName)
                    {
                        ?>
                    <tr site_id="<?php echo $website->id; ?>" plugin="<?php echo urlencode($ignoredPluginConflictName); ?>">
                        <td>
                            <span class="websitename" <?php if (!$first) { echo 'style="display: none;"'; } else { $first = false; }?>>
                                <a href="<?php echo admin_url('admin.php?page=managesites&dashboard=' . $website->id); ?>"><?php echo $website->name; ?></a>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo $ignoredPluginConflictName; ?></strong>
                        </td>
                        <td style="text-align: right; padding-right: 30px">
                            <a href="#" onClick="return pluginthemeconflict_unignore('plugin', '<?php echo urlencode($ignoredPluginConflictName); ?>', <?php echo $website->id; ?>)"><?php _e('ALLOW','mainwp'); ?></a>
                        </td>
                    </tr>
                        <?php
                    }
                }
                @MainWPDB::free_result($websites);
            }
            else
            {
            ?>
                <tr><td colspan="3"><?php _e('No ignored plugin conflicts','mainwp'); ?></td></tr>
            <?php
            }
            ?>
            </tbody>
        </table>
        <?php
        self::renderFooter('IgnoredConflicts');
    }

    public static function renderIgnore()
    {
        //todo: only fetch sites with ignored plugins
        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
        $userExtension = MainWPDB::Instance()->getUserExtension();
        $decodedIgnoredPlugins = json_decode($userExtension->ignored_plugins, true);
        $ignoredPlugins  = (is_array($decodedIgnoredPlugins) && (count($decodedIgnoredPlugins) > 0));

        $cnt = 0;
        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
        {
            if ($website->is_ignorePluginUpdates) continue;
            $tmpDecodedIgnoredPlugins = json_decode($website->ignored_plugins, true);
            if (!is_array($tmpDecodedIgnoredPlugins) || count($tmpDecodedIgnoredPlugins) == 0) continue;
            $cnt++;
        }

        self::renderHeader('Ignore');
?>
        <h2><?php _e('Plugin Ignore List','mainwp'); ?></h2>
        <table id="mainwp-table" class="wp-list-table widefat" cellspacing="0" style="width: 780px">
        	<caption><?php _e('Globally','mainwp'); ?></caption>
            <thead>
                <tr>
                    <th scope="col" class="manage-column" style="width: 650px"><?php _e('Plugins','mainwp'); ?></th>
                    <th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if (mainwp_current_user_can("dashboard", "ignore_unignore_updates")) { if ($ignoredPlugins) { ?><a href="#" class="button-primary mainwp-unignore-globally-all" onClick="return rightnow_plugins_unignore_globally_all();"><?php _e('Allow All','mainwp'); ?></a><?php } } ?></th>
                </tr>
            </thead>
            <tbody id="globally-ignored-plugins-list" class="list:sites">
            <?php
                if ($ignoredPlugins)
                {
                    foreach ($decodedIgnoredPlugins as $ignoredPlugin => $ignoredPluginName)
                    {
                    ?>
                        <tr plugin_slug="<?php echo urlencode($ignoredPlugin); ?>">
                            <td>
                                <strong><a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin='.urlencode(dirname($ignoredPlugin)).'&TB_iframe=true&width=640&height=477'; ?>" target="_blank" class="thickbox" title="More information about <?php echo $ignoredPluginName; ?>"><?php echo $ignoredPluginName; ?></a></strong> (<?php echo $ignoredPlugin; ?>)
                            </td>
                            <td style="text-align: right; padding-right: 30px">
                                <?php if (mainwp_current_user_can("dashboard", "ignore_unignore_updates")) { ?>
                                <a href="#" onClick="return rightnow_plugins_unignore_globally('<?php echo urlencode($ignoredPlugin); ?>')"><?php _e('ALLOW','mainwp'); ?></a>
                                <?php } ?>
                            </td>
                        </tr>
                <?php
                    }
                ?>
            <?php
                }
                else
                {
                ?>
                        <tr><td colspan="2"><?php _e('No ignored plugins','mainwp'); ?></td></tr>
                <?php
                }
            ?>
            </tbody>
        </table>

        <table id="mainwp-table" class="wp-list-table widefat" cellspacing="0" style="width: 780px">
        	<caption><?php _e('Per Site','mainwp'); ?></caption>
            <thead>
                <tr>
                    <th scope="col" class="manage-column" style="width: 250px"><?php _e('Site','mainwp'); ?></th>
                    <th scope="col" class="manage-column" style="width: 400px"><?php _e('Plugins','mainwp'); ?></th>
                    <th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ($cnt > 0) { ?><a href="#" class="button-primary mainwp-unignore-detail-all" onClick="return rightnow_plugins_unignore_detail_all();"><?php _e('Allow All','mainwp'); ?></a><?php } ?></th>
                </tr>
            </thead>
            <tbody id="ignored-plugins-list" class="list:sites">
            <?php
            if ($cnt > 0)
            {
                @MainWPDB::data_seek($websites, 0);
                while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                {
                    if ($website->is_ignorePluginUpdates) continue;
                    $decodedIgnoredPlugins = json_decode($website->ignored_plugins, true);
                    if (!is_array($decodedIgnoredPlugins) || count($decodedIgnoredPlugins) == 0) continue;
                    $first = true;

                    foreach ($decodedIgnoredPlugins as $ignoredPlugin => $ignoredPluginName)
                    {
                        ?>
                    <tr site_id="<?php echo $website->id; ?>" plugin_slug="<?php echo urlencode($ignoredPlugin); ?>">
                        <td>
                            <span class="websitename" <?php if (!$first) { echo 'style="display: none;"'; } else { $first = false; }?>>
                                <a href="<?php echo admin_url('admin.php?page=managesites&dashboard=' . $website->id); ?>"><?php echo $website->name; ?></a>
                            </span>
                        </td>
                        <td>
                            <strong><a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin='.urlencode(dirname($ignoredPlugin)).'&TB_iframe=true&width=640&height=477'; ?>" target="_blank" class="thickbox" title="More information about <?php echo $ignoredPluginName; ?>"><?php echo $ignoredPluginName; ?></a></strong> (<?php echo $ignoredPlugin; ?>)
                        </td>
                        <td style="text-align: right; padding-right: 30px">
                            <a href="#" onClick="return rightnow_plugins_unignore_detail('<?php echo urlencode($ignoredPlugin); ?>', <?php echo $website->id; ?>)"><?php _e('ALLOW','mainwp'); ?></a>
                        </td>
                    </tr>
                        <?php
                    }
                }
                @MainWPDB::free_result($websites);
            }
            else
            {
            ?>
                <tr><td colspan="3"><?php _e('No ignored plugins','mainwp'); ?></td></tr>
            <?php
            }
            ?>
            </tbody>
        </table>
            <?php
        self::renderFooter('Ignore');
    }

    public static function trustPost()
    {
        $userExtension = MainWPDB::Instance()->getUserExtension();
        $trustedPlugins = json_decode($userExtension->trusted_plugins, true);
        if (!is_array($trustedPlugins)) $trustedPlugins = array();

        $action = $_POST['do'];
        $slugs = $_POST['slugs'];

        if (!is_array($slugs)) return;
        if ($action != 'trust' && $action != 'untrust') return;

        if ($action == 'trust')
        {
           foreach ($slugs as $slug)
           {
               $idx = array_search(urldecode($slug), $trustedPlugins);
               if ($idx == false) $trustedPlugins[] = urldecode($slug);
           }
        }
        else if ($action == 'untrust')
        {
            foreach ($slugs as $slug)
            {
                if (in_array(urldecode($slug), $trustedPlugins))
                {
                    $trustedPlugins = array_diff($trustedPlugins, array(urldecode($slug)));
                }
            }
        }

        $userExtension->trusted_plugins = json_encode($trustedPlugins);
        MainWPDB::Instance()->updateUserExtension($userExtension);
    }

    public static function saveTrustedPluginNote()
    {
        $slug = urldecode($_POST['slug']);
        $note = $_POST['note'];

        $userExtension = MainWPDB::Instance()->getUserExtension();
        $trustedPluginsNotes = json_decode($userExtension->trusted_plugins_notes, true);
        if (!is_array($trustedPluginsNotes)) $trustedPluginsNotes = array();

        $trustedPluginsNotes[$slug] = $note;

        $userExtension->trusted_plugins_notes = json_encode($trustedPluginsNotes);
        MainWPDB::Instance()->updateUserExtension($userExtension);
    }

    public static function QSGManagePlugins() {
        self::renderHeader('PluginsHelp');
    ?><div style="text-align: center"><a href="#" class="button button-primary" id="mainwp-quick-start-guide"><?php _e('Show Quick Start Guide','mainwp'); ?></a></div>
                      <div  class="mainwp_info-box-yellow" id="mainwp-qsg-tips">
                          <span><a href="#" class="mainwp-show-qsg" number="1"><?php _e('Manage Plugins','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg"  number="2"><?php _e('How to install a Plugin','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg" number="3"><?php _e('How to update Plugins','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg" number="4"><?php _e('Ignore a plugin update','mainwp') ?></a></span><span><a href="#" id="mainwp-qsg-dismiss" style="float: right;"><?php _e('Dismiss','mainwp'); ?></a></span>
                      <div class="clear"></div>
                      <div id="mainwp-qsgs">
                        <div class="mainwp-qsg" number="1">
                            <h3>Manage Themes</h3>
                            <p>
                                <ol>
                                    <li>
                                        Select do you want to see your Active or Inactive plugins.<br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-plutins-active.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Optionaly, Enter the keyword for the search <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-plutins-keywords.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Select the sites from the Select Site Box <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-plutins-sites.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Hit the Show Plugins button <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-plutins-show.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li><h4>To Deactivate a Plugin: </h4><br/>
                                        <ol>
                                            <li>Select Active in Status dropdown list</li>
                                            <li>Select Site(s)</li>
                                            <li>Click Show Plugins button</li>
                                            <li>After list generates, select wanted plugin(s)</li>
                                            <li>Choose Deactivate from Bulk Action menu</li>
                                            <li>Click Confirm</li>
                                        </ol>
                                    </li>
                                    <li><h4>To delete a Plugin(s) from a site: </h4><br/>
                                        <ol>
                                            <li>Set the Inactive themes in status drop-down list</li>
                                            <li>Select Site(s)</li>
                                            <li>Click Show Plugins button</li>
                                            <li>After list generates, select wanted plugin(s)</li>
                                            <li>Choose Delete from Bulk Action menu</li>
                                            <li>Click Confirm</li>
                                        </ol>
                                    </li>
                                    <li><h4>To activate Plugin(s): </h4><br/>
                                        <ol>
                                            <li>Set the Inactive theme in status drop-down list</li>
                                            <li>Select Site(s)</li>
                                            <li>Click Show Plugins button</li>
                                            <li>After list generates, select wanted plugin(s)</li>
                                            <li>Choose Activate from Bulk Action menu</li>
                                            <li>Click Confirm</li>
                                        </ol>
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg" number="2">
                            <h3>How to install a Plugin</h3>
                            <p>You can install new plugin by searching WordPress theme repository or by uploading the plugin from your computer
                                <h4>Search Plugins</h4>
                                <ol>
                                    <li>
                                        Click the Install Tab
                                    </li>
                                    <li>
                                        Select if you want to make the search by Term, Author or Tag
                                    </li>
                                    <li>
                                        Enter a search keyword
                                    </li>
                                    <li>
                                        Click Search Plugins button <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-pulgins-search.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Select the site(s) you want to install the plugin, and click Install
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-pulgins-install.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                </ol>
                                <h4>Upload Plugins</h4>
                                <ol>
                                    <li>
                                        Click the Install Tab
                                    </li>
                                    <li>
                                        Click the Upload toggle Button
                                    </li>
                                    <li>
                                        Click â€œUpload Nowâ€ button<br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-plugins-upload.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Locate your Plugin
                                    </li>
                                    <li>
                                        Select sites you want to install the Plugin
                                    </li>
                                    <li>
                                        Click â€œInstall Nowâ€ button
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg" number="3">
                            <h3>How to update Plugins</h3>
                            <p>
                                <ol>
                                    <li>
                                        Go to main MainWP Dashboard
                                    </li>
                                    <li>
                                        Locate your â€œRight Nowâ€ Widget
                                    </li>
                                    <li>
                                        Click â€œShowâ€ on in Plugin Upgrades Availableâ€ area <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-available-plugins.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Click the middle upgrades link to show the drop down of the available upgrades for that site
                                    </li>
                                    <li>
                                        Select â€œUpgradeâ€ next to the name of the plugin or â€œUpgrade Allâ€ to upgrade all plugins on the site <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-available-plugins-upgrade.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg" number="4">
                            <h3>Ignore a Plugin update</h3>
                            <p>
                                <ol>
                                    <li>
                                        Go to main MainWP Dashboard
                                    </li>
                                    <li>
                                        Locate your â€œRight Nowâ€ Widget
                                    </li>
                                    <li>
                                        Click â€œShowâ€ on in â€œPlugin Upgrades Availableâ€ area
                                    </li>
                                    <li>
                                        Click the middle upgrades link to show the drop down of the available upgrades for that site
                                    </li>
                                    <li>
                                        Click â€œIgnoreâ€ next to the name of the plugin<br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-ignore-plugin.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                </ol>
                            </p>
                        </div>
                      </div>
                    </div>
    <?php
    self::renderFooter('PluginsHelp');
    }
}

?>