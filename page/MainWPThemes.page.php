<?php
/**
 * @see MainWPInstallBulk
 */
class MainWPThemes
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static $subPages;

    public static function init()
    {
        add_action('mainwp-pageheader-themes', array(MainWPThemes::getClassName(), 'renderHeader'));
        add_action('mainwp-pagefooter-themes', array(MainWPThemes::getClassName(), 'renderFooter'));
    }

    public static function initMenu()
    {

        add_submenu_page('mainwp_tab', __('Themes','mainwp'), '<span id="mainwp-Themes">' . __('Themes','mainwp') . '</span>', 'read', 'ThemesManage', array(MainWPThemes::getClassName(), 'render'));
        add_submenu_page('mainwp_tab', __('Themes','mainwp'), '<div class="mainwp-hidden">Install</div>', 'read', 'ThemesInstall', array(MainWPThemes::getClassName(), 'renderInstall'));
        add_submenu_page('mainwp_tab', __('Themes','mainwp'), '<div class="mainwp-hidden">Auto Update Trust</div>', 'read', 'ThemesAutoUpdate', array(MainWPThemes::getClassName(), 'renderAutoUpdate'));
        add_submenu_page('mainwp_tab', __('Themes','mainwp'), '<div class="mainwp-hidden">Ignored Updates</div>', 'read', 'ThemesIgnore', array(MainWPThemes::getClassName(), 'renderIgnore'));
        add_submenu_page('mainwp_tab', __('Themes','mainwp'), '<div class="mainwp-hidden">Ignored Conflicts</div>', 'read', 'ThemesIgnoredConflicts', array(MainWPThemes::getClassName(), 'renderIgnoredConflicts'));
        add_submenu_page('mainwp_tab', __('Themes Help','mainwp'), '<div class="mainwp-hidden">Themes Help</div>', 'read', 'ThemesHelp', array(MainWPThemes::getClassName(), 'QSGManageThemes'));

        self::$subPages = apply_filters('mainwp-getsubpages-themes', array());
        if (isset(self::$subPages) && is_array(self::$subPages))
        {
            foreach (self::$subPages as $subPage)
            {
                add_submenu_page('mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Themes' . $subPage['slug'], $subPage['callback']);
            }
        }
    }

    public static function initMenuSubPages()
    {
        ?>
        <div id="menu-mainwp-Themes" class="mainwp-submenu-wrapper">
            <div class="wp-submenu sub-open" style="">
                <div class="mainwp_boxout">
                    <div class="mainwp_boxoutin"></div>
                    <a href="<?php echo admin_url('admin.php?page=ThemesManage'); ?>" class="mainwp-submenu"><?php _e('Manage Themes','mainwp'); ?></a>
                    <a href="<?php echo admin_url('admin.php?page=ThemesInstall'); ?>" class="mainwp-submenu"><?php _e('Install','mainwp'); ?></a>
                    <a href="<?php echo admin_url('admin.php?page=ThemesAutoUpdate'); ?>" class="mainwp-submenu"><?php _e('Auto Update Trust','mainwp'); ?></a>
                    <a href="<?php echo admin_url('admin.php?page=ThemesIgnore'); ?>" class="mainwp-submenu"><?php _e('Ignored Updates','mainwp'); ?></a>
                    <a href="<?php echo admin_url('admin.php?page=ThemesIgnoredConflicts'); ?>" class="mainwp-submenu"><?php _e('Ignored Conflicts','mainwp'); ?></a>
                    <?php
                    if (isset(self::$subPages) && is_array(self::$subPages))
                    {
                        foreach (self::$subPages as $subPage)
                        {
                    ?>
                        <a href="<?php echo admin_url('admin.php?page=Themes'.$subPage['slug']); ?>" class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
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
        <img src="<?php echo plugins_url('images/icons/mainwp-themes.png', dirname(__FILE__)); ?>" style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Themes" height="32"/>
        <h2><?php _e('Themes','mainwp'); ?></h2><div style="clear: both;"></div><br/>
        <div class="mainwp-tabs" id="mainwp-tabs">
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'Manage') { echo "nav-tab-active"; } ?>" href="admin.php?page=ThemesManage"><?php _e('Manage','mainwp'); ?></a>
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'Install') { echo "nav-tab-active"; } ?>" href="admin.php?page=ThemesInstall"><?php _e('Install','mainwp'); ?></a>
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'AutoUpdate') { echo "nav-tab-active"; } ?>" href="admin.php?page=ThemesAutoUpdate"><?php _e('Auto Update Trust','mainwp'); ?></a>
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'Ignore') { echo "nav-tab-active"; } ?>" href="admin.php?page=ThemesIgnore"><?php _e('Ignored Updates','mainwp'); ?></a>
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'IgnoredConflicts') { echo "nav-tab-active"; } ?>" href="admin.php?page=ThemesIgnoredConflicts"><?php _e('Ignored Conflicts','mainwp'); ?></a>
            <a style="float: right" class="mainwp-help-tab nav-tab pos-nav-tab <?php if ($shownPage === 'ThemesHelp') { echo "nav-tab-active"; } ?>" href="admin.php?page=ThemesHelp"><?php _e('Help','mainwp'); ?></a>
            <?php
            if (isset(self::$subPages) && is_array(self::$subPages))
            {
                foreach (self::$subPages as $subPage)
                {
                ?>
                   <a class="nav-tab pos-nav-tab <?php if ($shownPage === $subPage['slug']) { echo "nav-tab-active"; } ?>" href="admin.php?page=Themes<?php echo $subPage['slug']; ?>"><?php echo $subPage['title']; ?></a>
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
        $cachedSearch = MainWPCache::getCachedContext('Themes');
        self::renderHeader('Manage'); ?>
            <div class="mainwp_info-box"><strong><?php _e('Use this to bulk (de)activate or delete themes. To add new themes click on the "Install" tab.','mainwp'); ?></strong></div>
        <br/>
        <div class="mainwp-search-form">
            <?php MainWPUI::select_sites_box(__("Select Sites", 'mainwp'), 'checkbox', true, true, 'mainwp_select_sites_box_right'); ?>

            <h3><?php _e('Search Themes','mainwp'); ?></h3>
            <p>
                <?php _e('Status:','mainwp'); ?><br />
                <select name="mainwp_theme_search_by_status" id="mainwp_theme_search_by_status">
                    <option value="active" <?php if ($cachedSearch != null && $cachedSearch['the_status'] == 'active') { echo 'selected'; } ?>><?php _e('Active','mainwp'); ?></option>
                    <option value="inactive" <?php if ($cachedSearch != null && $cachedSearch['the_status'] == 'inactive') { echo 'selected'; } ?>><?php _e('Inactive','mainwp'); ?></option>
                </select>
            </p>
            <p>
                <?php _e('Containing Keyword:','mainwp'); ?><br/>
                <input type="text" id="mainwp_theme_search_by_keyword" size="50" value="<?php if ($cachedSearch != null) { echo $cachedSearch['keyword']; } ?>"/>
            </p>
            <p>&nbsp;</p>
            <input type="button" name="mainwp_show_themes" id="mainwp_show_themes" class="button-primary" value="<?php _e('Show Themes','mainwp'); ?>"/>
            <span id="mainwp_themes_loading">&nbsp;<em><?php _e('Grabbing information from Child Sites','mainwp') ?></em>&nbsp;&nbsp;<img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/></span> <span id="mainwp_themes_loading_info"><?php _e('Automatically refreshing to get up to date information.','mainwp'); ?></span>
        </div>
        <div class="clear"></div>

        <div id="mainwp_themes_error"></div>
        <div id="mainwp_themes_main" <?php if ($cachedSearch != null) { echo 'style="display: block;"'; } ?>>
            <div id="mainwp_themes_content">
                <?php MainWPCache::echoBody('Themes'); ?>
            </div>
        </div>
    <?php
        if ($cachedSearch != null) { echo '<script>mainwp_themes_all_table_reinit();</script>'; }
        self::renderFooter('Manage');
    }

    public static function renderTable($keyword, $status, $groups, $sites)
    {
        MainWPCache::initCache('Themes');

        $output = new stdClass();
        $output->errors = array();
        $output->themes = array();

        if (get_option('mainwp_optimize') == 1)
        {
            //Search in local cache
            if ($sites != '') {
                foreach ($sites as $k => $v) {
                    if (MainWPUtility::ctype_digit($v)) {
                        $website = MainWPDB::Instance()->getWebsiteById($v);
                        $allThemes = json_decode($website->themes, true);
                        for ($i = 0; $i < count($allThemes); $i++) {
                            $theme = $allThemes[$i];
                            if ($theme['active'] != (($status == 'active') ? 1 : 0)) continue;
                            if ($keyword != '' && !stristr($theme['title'], $keyword)) continue;

                            $theme['websiteid'] = $website->id;
                            $theme['websiteurl'] = $website->url;
                            $output->themes[] = $theme;
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
                            $allThemes = json_decode($website->themes, true);
                            for ($i = 0; $i < count($allThemes); $i++) {
                                $theme = $allThemes[$i];
                                if ($theme['active'] != (($status == 'active') ? 1 : 0)) continue;
                                if ($keyword != '' && !stristr($theme['title'], $keyword)) continue;

                                $theme['websiteid'] = $website->id;
                                $theme['websiteurl'] = $website->url;
                                $output->themes[] = $theme;
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
            MainWPUtility::fetchUrlsAuthed($dbwebsites, 'get_all_themes', $post_data, array(MainWPThemes::getClassName(), 'ThemesSearch_handler'), $output);

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

        MainWPCache::addContext('Themes', array('keyword' => $keyword, 'the_status' => $status));

        ob_start();
        ?>
    <?php if ($status == 'inactive') { ?>
    <div class="alignleft">
        <select name="bulk_action" id="mainwp_bulk_action">
            <option value="none"><?php _e('Choose Action','mainwp'); ?></option>
            <option value="activate"><?php _e('Activate','mainwp'); ?></option>
            <option value="delete"><?php _e('Delete','mainwp'); ?></option>
        </select> <input type="button" name="" id="mainwp_bulk_theme_action_apply" class="button" value="<?php _e('Confirm','mainwp'); ?>"/> <span id="mainwp_bulk_action_loading"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/></span>
    </div>
    <div class="clear"></div>
    <?php } ?>


    <?php
        if (count($output->themes) == 0) {
            ?>
        No themes found
        <?php
            $newOutput = ob_get_clean();
            echo $newOutput;
            MainWPCache::addBody('Themes', $newOutput);
            return;
        }

        //Map per siteId
        $sites = array(); //id -> url
        $siteThemes = array(); //site_id -> theme_version_name -> theme obj
        $themes = array(); //name_version -> name
        $themesVersion = array(); //name_version -> title_version
        $themesRealVersion = array(); //name_version -> title_version
        foreach ($output->themes as $theme) {
            $sites[$theme['websiteid']] = $theme['websiteurl'];
            $themes[$theme['name'] . '_' . $theme['version']] = $theme['name'];
            $themesVersion[$theme['name'] . '_' . $theme['version']] = $theme['title'] . ' ' . $theme['version'];
            $themesRealVersion[$theme['name'] . '_' . $theme['version']] = $theme['version'];
            if (!isset($siteThemes[$theme['websiteid']]) || !is_array($siteThemes[$theme['websiteid']])) $siteThemes[$theme['websiteid']] = array();
            $siteThemes[$theme['websiteid']][$theme['name'] . '_' . $theme['version']] = $theme;
        }
        ?>
<div id="mainwp-table-overflow" style="overflow: auto !important ;">
    <table class="wp-list-table widefat fixed pages" style="width: auto; word-wrap: normal">
        <thead>
        <tr>
            <th></th>
            <?php
            foreach ($themesVersion as $theme_name => $theme_title) {
                echo '<th style="height: 100px; padding: 5px ;">
                    <p style="font-family: Arial, Sans-Serif; text-shadow: none ; width: 100px !important; height: 30px ; text-align: center; width: auto; height: auto; font-size: 13px; -webkit-transform: rotate(-90deg); -moz-transform: rotate(-90deg); -o-transform: rotate(-90deg); -ms-transform: rotate(-90deg); writing-mode: lr-tb; ">
                    <input type="checkbox" value="' . $themes[$theme_name] . '" id="' . $theme_name . '" version="'.$themesRealVersion[$theme_name].'" class="mainwp_theme_check_all" style="margin: 3px 0px 0px 0px; display: none ; " />
                    <label for="' . $theme_name . '">' . $theme_title . '</label>
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
                <td>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $site_id; ?>"/>
<!--                    <strong>--><?php //echo $site_url; ?><!--</strong>-->
                    <label for="<?php echo $site_url; ?>"><strong><?php echo $site_url; ?></strong></label>
                    &nbsp;&nbsp;<input type="checkbox" value="" id="<?php echo $site_url; ?>"
                                       class="mainwp_site_check_all" style="display: none ;"/>
                </td>
                <?php
                foreach ($themesVersion as $theme_name => $theme_title) {
                    echo '<td style="text-align: center">';
                    if (isset($siteThemes[$site_id]) && isset($siteThemes[$site_id][$theme_name])) {
                        echo '<input type="checkbox" value="' . $themes[$theme_name] . '" version="'.$themesRealVersion[$theme_name].'" class="selected_theme" />';
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
        MainWPCache::addBody('Themes', $newOutput);
    }
    public static function renderAllThemesTable($output = null)
    {
        if ($output == null)
        {
            $output = new stdClass();
            $output->errors = array();
            $output->themes = array();

            if (get_option('mainwp_optimize') == 1)
            {
                //Fetch all!
                //Build websites array
                //Search in local cache
                $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
                while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                {
                    $allThemes = json_decode($website->themes, true);
                    for ($i = 0; $i < count($allThemes); $i++) {
                        $theme = $allThemes[$i];
                        if ($theme['active'] != 1) continue;

                        $theme['websiteid'] = $website->id;
                        $theme['websiteurl'] = $website->url;
                        $output->themes[] = $theme;
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
                    'keyword' => '',
                    'status' => 'active'
                );
                MainWPUtility::fetchUrlsAuthed($dbwebsites, 'get_all_themes', $post_data, array(MainWPThemes::getClassName(), 'ThemesSearch_handler'), $output);

                if (count($output->errors) > 0)
                {
                    foreach ($output->errors as $siteid => $error)
                    {
                        echo '<strong>Error on ' . MainWPUtility::getNiceURL($dbwebsites[$siteid]->url) . ': ' . $error . ' <br /></strong>';
                    }
                    echo '<br />';
                }

                if (count($output->errors) == count($dbwebsites))
                {
                    session_start();
                    $_SESSION['SNThemesAll'] = $output;
                    return;
                }
            }

            if (session_id() == '') session_start();
            $_SESSION['SNThemesAll'] = $output;
        }


        if (count($output->themes) == 0) return;
        ?>
    <div class="alignleft">
        <select name="bulk_action" id="mainwp_bulk_action">
            <option value="none"><?php _e('Choose Action','mainwp'); ?></option>
            <option value="trust"><?php _e('Trust','mainwp'); ?></option>
            <option value="untrust"><?php _e('Untrust','mainwp'); ?></option>
        </select> <input type="button" name="" id="mainwp_bulk_trust_themes_action_apply" class="button" value="<?php _e('Confirm','mainwp'); ?>"/> <span id="mainwp_bulk_action_loading"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/></span>
    </div>
    <div class="clear"></div>


    <?php
        if (count($output->themes) == 0) {
            ?>
        No themes found
        <?php
            return;
        }

        //Map per siteId
        $themes = array(); //name_version -> slug
        foreach ($output->themes as $theme) {
            $themes[$theme['slug']] = $theme['name'];
        }
        asort($themes);

        $userExtension = MainWPDB::Instance()->getUserExtension();
        $decodedIgnoredThemes = json_decode($userExtension->ignored_themes, true);
        $trustedThemes = json_decode($userExtension->trusted_themes, true);
        if (!is_array($trustedThemes)) $trustedThemes = array();
        $trustedThemesNotes = json_decode($userExtension->trusted_themes_notes, true);
        if (!is_array($trustedThemesNotes)) $trustedThemesNotes = array();

        ?>
        <table id="mainwp_themes_all_table" class="wp-list-table widefat fixed posts tablesorter" cellspacing="0">
            <thead>
            <tr>
                <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input name="themes" type="checkbox"></th>
                <th scope="col" id="info" class="manage-column column-cb check-column" style=""></th>
                <th scope="col" id="theme" class="manage-column column-title sortable desc" style="">
                    <a href="#"><span><?php _e('Theme','mainwp'); ?></span><span class="sorting-indicator"></span></a>
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
                <th scope="col" class="manage-column column-cb check-column" style=""><input name="themes" type="checkbox"></th>
                <th scope="col" id="info_footer" class="manage-column column-cb check-column" style=""></th>
                <th scope="col" id="theme_footer" class="manage-column column-title sortable desc" style=""><span><?php _e('Theme','mainwp'); ?></span></th>
                <th scope="col" id="trustlvl_footer" class="manage-column column-posts" style=""><?php _e('Trust Level','mainwp'); ?></th>
                <th scope="col" id="ignoredstatus_footer" class="manage-column column-posts" style=""><?php _e('Ignored Status','mainwp'); ?></th>
                <th scope="col" id="notes_footer" class="manage-column column-posts" style=""><?php _e('Notes','mainwp'); ?></th>
            </tr>
            </tfoot>

            <tbody id="the-posts-list" class="list:posts">
                <?php
                    foreach ($themes as $slug => $name)
                    {
                     ?>
                    <tr id="post-1" class="post-1 post type-post status-publish format-standard hentry category-uncategorized alternate iedit author-self" valign="top" theme_slug="<?php echo urlencode($slug); ?>" theme_name="<?php echo rawurlencode($name); ?>">
                        <th scope="row" class="check-column"><input type="checkbox" name="theme[]" value="<?php echo urlencode($slug); ?>"></th>
                        <td scope="col" id="info_content" class="manage-column" style=""> <?php if (isset($decodedIgnoredThemes[$slug])) { MainWPUtility::renderToolTip('Ignored themes will NOT be auto-updated.', null, 'images/icons/mainwp-red-info-16.png'); } ?></td>
                        <td scope="col" id="theme_content" class="manage-column sorted" style="">
                            <?php echo $name; ?>
                        </td>
                        <td scope="col" id="trustlvl_content" class="manage-column" style="">
                            <?php
                                if (in_array($slug, $trustedThemes))
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
                            <?php if (isset($decodedIgnoredThemes[$slug])) { echo '<font color="#c00">Ignored</font>'; } ?>
                        </td>
                        <td scope="col" id="notes_content" class="manage-column" style="">
                            <img src="<?php echo plugins_url('images/notes.png', dirname(__FILE__)); ?>" class="mainwp_notes_img" <?php if (!isset($trustedThemesNotes[$slug]) || $trustedThemesNotes[$slug] == '') { echo 'style="display: none;"'; } ?> />
                            <a href="#" class="mainwp_trusted_theme_notes_show">Open</a>
                            <div style="display: none" class="note"><?php if (isset($trustedThemesNotes[$slug])) { echo $trustedThemesNotes[$slug]; } ?></div>
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
                <input type="button" class="button cont button-primary" id="mainwp_trusted_theme_notes_save" value="<?php _e('Save Note','mainwp'); ?>"/>
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

    public static function ThemesSearch_handler($data, $website, &$output)
    {
        if (preg_match('/<mainwp>(.*)<\/mainwp>/', $data, $results) > 0) {
            $themes = unserialize(base64_decode($results[1]));
            unset($results);
            if (isset($themes['error']))
            {
                $output->errors[$website->id] = MainWPErrorHelper::getErrorMessage(new MainWPException($themes['error'], $website->url));
                return;
            }

            foreach ($themes as $theme) {
                if (!isset($theme['name'])) continue;
                $theme['websiteid'] = $website->id;
                $theme['websiteurl'] = $website->url;

                $output->themes[] = $theme;
            }
            //$output->themes = array_merge($output->themes, $themes);
            unset($themes);
        } else {
            $output->errors[$website->id] = MainWPErrorHelper::getErrorMessage(new MainWPException('NOMAINWP', $website->url));
        }
    }

    public static function activateTheme()
    {
        MainWPThemes::action('activate', $_POST['theme']);
        die('SUCCESS');
    }

    public static function deleteThemes()
    {
        MainWPThemes::action('delete', implode('||', $_POST['themes']));
        die('SUCCESS');
    }

    public static function action($pAction, $theme)
    {
        $websiteIdEnc = $_POST['websiteId'];

        $websiteId = $websiteIdEnc;
        if (!MainWPUtility::ctype_digit($websiteId)) die('FAIL');

        $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        if (!MainWPUtility::can_edit_website($website)) die('FAIL');

        try {
            $information = MainWPUtility::fetchUrlAuthed($website, 'theme_action', array(
                'action' => $pAction,
                'theme' => $theme));
        } catch (MainWPException $e) {
            die('FAIL');
        }

        if (!isset($information['out']) || !isset($information['status']) || ($information['status'] != 'SUCCESS')) die('FAIL');

        die($information['out']);
    }

    //@see MainWPInstallBulk
    public static function renderInstall()
    {
        self::renderHeader('Install');
        MainWPInstallBulk::render('Themes');
        self::renderFooter('Install');
    }

    //Performs a search
    public static function performSearch()
    {
        MainWPInstallBulk::performSearch(MainWPThemes::getClassName(), 'Themes');
    }

    public static function renderFound($api)
    {
        global $themes_allowedtags;
        ?>
    <div id="mainwp_availablethemes">
        <?php
        $themes = $api->themes;
        $rows = ceil(count($themes) / 2);
        $table = array();
        $theme_keys = array_keys($themes);
        for ($row = 1; $row <= $rows; $row++)
            for ($col = 1; $col <= 3; $col++)
                $table[$row][$col] = array_shift($theme_keys);

        foreach ($table as $row => $cols) {
            foreach ($cols as $col => $theme_index) {
                $class = array('available-theme');
                if ($row == 1) {
                    $class[] = 'top';
                }
                if ($col == 1) {
                    $class[] = 'left';
                }
                if ($row == $rows) {
                    $class[] = 'bottom';
                }
                if ($col == 3) {
                    $class[] = 'right';
                }
                ?>
                <div class="<?php echo join(' ', $class); ?>">
                    <?php
                    if (isset($themes[$theme_index])) {
                        $theme = $themes[$theme_index];
                        $name = wp_kses($theme->name, $themes_allowedtags);
                        $desc = wp_kses($theme->description, $themes_allowedtags);
                        $preview_link = $theme->preview_url . '?TB_iframe=true&amp;width=600&amp;height=400';
                        ?>

                        <a class='thickbox thickbox-preview screenshot' href='<?php echo esc_url($preview_link); ?>'
                           target="_blank" title='Preview <?php echo $name; ?>'>
                            <img src='<?php echo esc_url($theme->screenshot_url); ?>'/>
                        </a>
                        <h3><?php echo $name; ?></h3>
                        <span class='action-links'>

                                    <a href="" class="thickbox thickbox-preview onclick"
                                       id="install-theme-<?php echo $theme->slug; ?>" title="Install â€œ<?php echo $name; ?>??">Install</a> |
                                    <a href="<?php echo $preview_link; ?>" target="_blank"
                                       class="thickbox thickbox-preview onclick previewlink"
                                       title="Preview '<?php echo $name; ?>'">Preview</a>
                                        <?php do_action("mainwp_installthemes_extra_links", $theme); ?>
                                </span>
                        <p><?php echo $desc; ?></p>

                        <div class="themedetaildiv hide-if-js" style="display: block;">
                            <p>
                                <strong><?php _e('Version:') ?></strong> <?php echo wp_kses($theme->version, $themes_allowedtags) ?>
                            </p>

                            <p>
                                <strong><?php _e('Author:') ?></strong> <?php echo wp_kses($theme->author, $themes_allowedtags) ?>
                            </p>
                            <?php if (!empty($theme->last_updated)) : ?>
                            <p><strong><?php _e('Last Updated:') ?></strong> <span
                                    title="<?php echo $theme->last_updated ?>"><?php printf(__('%s ago'), human_time_diff(strtotime($theme->last_updated))) ?></span>
                            </p>
                            <?php endif;
                            if (!empty($theme->requires)) : ?>
                                <p>
                                    <strong><?php _e('Requires WordPress Version:') ?></strong> <?php printf(__('%s or higher'), $theme->requires) ?>
                                </p>
                                <?php endif;
                            if (!empty($theme->tested)) : ?>
                                <p><strong><?php _e('Compatible up to:') ?></strong> <?php echo $theme->tested ?></p>
                                <?php endif;
                            if (!empty($theme->downloaded)) : ?>
                                <p>
                                    <strong><?php _e('Downloaded:') ?></strong> <?php printf(_n('%s time', '%s times', $theme->downloaded), number_format_i18n($theme->downloaded)) ?>
                                </p>
                                <?php endif; ?>
                            <div class="star-holder"
                                 title="<?php printf(_n('(based on %s rating)', '(based on %s ratings)', $theme->num_ratings), number_format_i18n($theme->num_ratings)) ?>">
                                <div class="star star-rating"
                                     style="width: <?php echo esc_attr($theme->rating) ?>px"></div>
                            </div>
                        </div>

                        <?php
                    }
                    ?>
                </div>
                <?php
            } // end foreach $cols
        }
        ?>
    </div>
    <?php
        die();
    }


    public static function renderAutoUpdate()
    {
        self::renderHeader('AutoUpdate');

        $snAutomaticDailyUpdate = get_option('mainwp_automaticDailyUpdate');
        ?>
        <h2><?php _e('Theme Automatic Update Trust List','mainwp'); ?></h2>
        <br />
        <div id="mainwp-au" class=""><strong><?php if ($snAutomaticDailyUpdate == 1) { ?>
            <span class="mainwp-au-on"><?php _e('Auto Updates are ON and Trusted Plugins will be Automatically Updated','mainwp'); ?></span>
        <?php } elseif (($snAutomaticDailyUpdate === false) || ($snAutomaticDailyUpdate == 2)) { ?>
            <span class="mainwp-au-email"><?php _e('Auto Updates are OFF - Email Update Notification is ON','mainwp'); ?></span>
        <?php } else { ?>
            <span class="mainwp-au-off"><?php _e('Auto Updates are OFF - Email Update Notification is OFF','mainwp'); ?></span>
        <?php } ?></strong> - <a href="<?php echo admin_url(); ?>admin.php?page=Settings"><?php _e('Change this in Settings','mainwp'); ?></a></div>
        <div class="mainwp_info-box"><?php _e('Only mark Themes as Trusted if you are absolutely sure they can be updated','mainwp'); ?></div>

        <a href="#" class="button-primary" id="mainwp_show_all_themes"><?php _e('Show Themes','mainwp'); ?></a>
        <span id="mainwp_themes_loading"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/></span>


        <div id="mainwp_themes_main" style="display: block; margin-top: 1.5em ;">
            <div id="mainwp_themes_content">
            <?php
                if (session_id() == '') session_start();
                if (isset($_SESSION['SNThemesAll'])) {
                    self::renderAllThemesTable($_SESSION['SNThemesAll']);
                    echo '<script>mainwp_themes_all_table_reinit();</script>';
                }
            ?>
            </div>
        </div>
        <?php
        self::renderFooter('AutoUpdate');
    }

    public static function renderIgnoredConflicts()
    {
        //todo: only fetch sites with ignored conflicts
        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
        $userExtension = MainWPDB::Instance()->getUserExtension();
        $decodedIgnoredThemeConflicts = json_decode($userExtension->ignored_themeConflicts, true);
        $ignoredThemeConflicts  = (is_array($decodedIgnoredThemeConflicts) && (count($decodedIgnoredThemeConflicts) > 0));

        $cnt = 0;
        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
        {
            $tmpDecodedIgnoredThemeConflicts = json_decode($website->ignored_themeConflicts, true);
            if (!is_array($tmpDecodedIgnoredThemeConflicts) || count($tmpDecodedIgnoredThemeConflicts) == 0) continue;
            $cnt++;
        }

        self::renderHeader('IgnoredConflicts');
        ?>
        <h2><?php _e('Ignored Theme Conflict List','mainwp'); ?></h2>
        <table id="mainwp-table" class="wp-list-table widefat" cellspacing="0" style="width: 780px">
            <caption><?php _e('Globally','mainwp'); ?></caption>
            <thead>
                <tr>
                    <th scope="col" class="manage-column" style="width: 650px"><?php _e('Themes','mainwp'); ?></th>
                    <th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ($ignoredThemeConflicts) { ?><a href="#" class="button-primary mainwp-unignore-globally-all" onClick="return pluginthemeconflict_unignore('theme', undefined, undefined);"><?php _e('Allow All','mainwp'); ?></a><?php } ?></th>
                </tr>
            </thead>
            <tbody id="globally-ignored-themeconflict-list" class="list:sites">
            <?php
                if ($ignoredThemeConflicts)
                {
                    foreach ($decodedIgnoredThemeConflicts as $ignoredThemeName)
                    {
                    ?>
                        <tr theme="<?php echo urlencode($ignoredThemeName); ?>">
                            <td>
                                <strong><?php echo $ignoredThemeName; ?></strong>
                            </td>
                            <td style="text-align: right; padding-right: 30px">
                                <a href="#" onClick="return pluginthemeconflict_unignore('theme', '<?php echo urlencode($ignoredThemeName); ?>', undefined);"><?php _e('ALLOW','mainwp'); ?></a>
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
                        <tr><td colspan="2"><?php _e('No ignored theme conflicts','mainwp'); ?></td></tr>
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
                    <th scope="col" class="manage-column" style="width: 400px"><?php _e('Themes','mainwp'); ?></th>
                    <th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ($cnt > 0) { ?><a href="#" class="button-primary mainwp-unignore-detail-all" onClick="return pluginthemeconflict_unignore('theme', undefined, '_ALL_');"><?php _e('Allow All','mainwp'); ?></a><?php } ?></th>
                </tr>
            </thead>
            <tbody id="ignored-themeconflict-list" class="list:sites">
            <?php
            if ($cnt > 0)
            {
                @MainWPDB::data_seek($websites, 0);
                while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                {
                    $decodedIgnoredThemeConflicts = json_decode($website->ignored_themeConflicts, true);
                    if (!is_array($decodedIgnoredThemeConflicts) || count($decodedIgnoredThemeConflicts) == 0) continue;
                    $first = true;

                    foreach ($decodedIgnoredThemeConflicts as $ignoredThemeConflictName)
                    {
                        ?>
                    <tr site_id="<?php echo $website->id; ?>" theme="<?php echo urlencode($ignoredThemeConflictName); ?>">
                        <td>
                            <span class="websitename" <?php if (!$first) { echo 'style="display: none;"'; } else { $first = false; }?>>
                                <?php echo $website->name; ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo $ignoredThemeConflictName; ?></strong>
                        </td>
                        <td style="text-align: right; padding-right: 30px">
                            <a href="#" onClick="return pluginthemeconflict_unignore('theme', '<?php echo urlencode($ignoredThemeConflictName); ?>', <?php echo $website->id; ?>)"><?php _e('ALLOW','mainwp'); ?></a>
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
                <tr><td colspan="3"><?php _e('No ignored theme conflicts','mainwp'); ?></td></tr>
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
        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
        $userExtension = MainWPDB::Instance()->getUserExtension();
        $decodedIgnoredThemes = json_decode($userExtension->ignored_themes, true);
        $ignoredThemes = (is_array($decodedIgnoredThemes) && (count($decodedIgnoredThemes) > 0));

        $cnt = 0;
        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
        {
            $tmpDecodedIgnoredThemes = json_decode($website->ignored_themes, true);
            if (!is_array($tmpDecodedIgnoredThemes) || count($tmpDecodedIgnoredThemes) == 0) continue;
            $cnt++;
        }

        self::renderHeader('Ignore');
        ?>
    <h2><?php _e('Theme Ignore List','mainwp'); ?></h2>
    <table id="mainwp-table" class="wp-list-table widefat" cellspacing="0" style="width: 780px">
    <caption><?php _e('Globally','mainwp'); ?></caption>
        <thead>
            <tr>
                <th scope="col" class="manage-column" style="width: 650px"><?php _e('Themes','mainwp'); ?></th>
                <th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ($ignoredThemes) { ?><a href="#" class="button-primary mainwp-unignore-globally-all" onClick="return rightnow_themes_unignore_globally_all();"><?php _e('Allow All','mainwp'); ?></a><?php } ?></th>
            </tr>
        </thead>
        <tbody id="globally-ignored-themes-list" class="list:sites">
        <?php
            if ($ignoredThemes)
            {
        ?>
                <?php
                foreach ($decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName)
                {
                ?>
                    <tr theme_slug="<?php echo urlencode($ignoredTheme); ?>">
                         <td>
                             <strong><?php echo $ignoredThemeName; ?></strong> (<?php echo $ignoredTheme; ?>)
                         </td>
                        <td style="text-align: right; padding-right: 30px">
                            <a href="#" onClick="return rightnow_themes_unignore_globally('<?php echo urlencode($ignoredTheme); ?>')"><?php _e('ALLOW','mainwp'); ?></a>
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
                    <tr><td colspan="2"><?php _e('No ignored themes','mainwp'); ?></td></tr>
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
                <th scope="col" class="manage-column" style="width: 400px"><?php _e('Themes','mainwp'); ?></th>
                <th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ($cnt > 0) { ?><a href="#" class="button-primary mainwp-unignore-detail-all" onClick="return rightnow_themes_unignore_detail_all();"><?php _e('Allow All','mainwp'); ?></a><?php } ?></th>
            </tr>
        </thead>
        <tbody id="ignored-themes-list" class="list:sites">
        <?php
        if ($cnt > 0)
        {
            @MainWPDB::data_seek($websites, 0);
            while ($websites && ($website = @MainWPDB::fetch_object($websites)))
            {
               $decodedIgnoredThemes = json_decode($website->ignored_themes, true);
               if (!is_array($decodedIgnoredThemes) || count($decodedIgnoredThemes) == 0) continue;
               $first = true;
               foreach ($decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName)
               {
                 ?>
               <tr site_id="<?php echo $website->id; ?>" theme_slug="<?php echo urlencode($ignoredTheme); ?>">
                   <td>
                       <span class="websitename" <?php if (!$first) { echo 'style="display: none;"'; } else { $first = false; }?>>
                           <?php echo $website->name; ?>
                       </span>
                   </td>
                   <td>
                       <strong><?php echo $ignoredThemeName; ?></strong> (<?php echo $ignoredTheme; ?>)
                   </td>
                   <td style="text-align: right; padding-right: 30px">
                       <a href="#" onClick="return rightnow_themes_unignore_detail('<?php echo urlencode($ignoredTheme); ?>', <?php echo $website->id; ?>)"><?php _e('ALLOW','mainwp'); ?></a>
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
            <tr><td colspan="3"><?php _e('No ignored themes','mainwp'); ?></td></tr>
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
        $trustedThemes = json_decode($userExtension->trusted_themes, true);
        if (!is_array($trustedThemes)) $trustedThemes = array();

        $action = $_POST['do'];
        $slugs = $_POST['slugs'];

        if (!is_array($slugs)) return;
        if ($action != 'trust' && $action != 'untrust') return;

        if ($action == 'trust')
        {
           foreach ($slugs as $slug)
           {
               $idx = array_search(urldecode($slug), $trustedThemes);
               if ($idx == false) $trustedThemes[] = urldecode($slug);
           }
        }
        else if ($action == 'untrust')
        {
            foreach ($slugs as $slug)
            {
                if (in_array(urldecode($slug), $trustedThemes))
                {
                    $trustedThemes = array_diff($trustedThemes, array(urldecode($slug)));
                }
            }
        }

        $userExtension->trusted_themes = json_encode($trustedThemes);
        MainWPDB::Instance()->updateUserExtension($userExtension);
    }

    public static function saveTrustedThemeNote()
    {
        $slug = urldecode($_POST['slug']);
        $note = $_POST['note'];

        $userExtension = MainWPDB::Instance()->getUserExtension();
        $trustedThemesNotes = json_decode($userExtension->trusted_themes_notes, true);
        if (!is_array($trustedThemesNotes)) $trustedThemesNotes = array();

        $trustedThemesNotes[$slug] = $note;

        $userExtension->trusted_themes_notes = json_encode($trustedThemesNotes);
        MainWPDB::Instance()->updateUserExtension($userExtension);
    }

    public static function QSGManageThemes() {
        self::renderHeader('ThemesHelp');
    ?><div style="text-align: center"><a href="#" class="button button-primary" id="mainwp-quick-start-guide"><?php _e('Show Quick Start Guide','mainwp'); ?></a></div>
                      <div  class="mainwp_info-box-yellow" id="mainwp-qsg-tips">
                          <span><a href="#" class="mainwp-show-qsg" number="1"><?php _e('Manage Themes','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg" number="4"><?php _e('Ignore a theme update','mainwp') ?></a></span><span><a href="#" id="mainwp-qsg-dismiss" style="float: right;"><?php _e('Dismiss','mainwp'); ?></a></span>
                      <div class="clear"></div>
                      <div id="mainwp-qsgs">
                        <div class="mainwp-qsg" number="1">
                            <h3>Manage Themes</h3>
                            <p>
                                <ol>
                                    <li>
                                        Select do you want to see your Active or Inactive themes.<br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-themes-active.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Optionaly, Enter the keyword for the search <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-themes-keyword.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Select the sites from the Select Site Box <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-themes-sites.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Hit the Show Themes button <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-themes-show.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li><h4>To Deactivate a Theme: </h4><br/>
                                        <ol>
                                            <li>Select Active in Status dropdown list</li>
                                            <li>Select Site(s)</li>
                                            <li>Click Show Themes button</li>
                                            <li>After list generates, select wanted theme(s)</li>
                                            <li>Choose Deactivate from Bulk Action menu</li>
                                            <li>Click Confirm</li>
                                        </ol>
                                    </li>
                                    <li><h4>To delete a Theme(s) from a site: </h4><br/>
                                        <ol>
                                            <li>Set the Inactive themes in status drop-down list</li>
                                            <li>Select Site(s)</li>
                                            <li>Click Show Themes button</li>
                                            <li>After list generates, select wanted theme(s)</li>
                                            <li>Choose Delete from Bulk Action menu</li>
                                            <li>Click Confirm</li>
                                        </ol>
                                    </li>
                                    <li><h4>To activate Theme(s): </h4><br/>
                                        <ol>
                                            <li>Set the Inactive theme in status drop-down list.</li>
                                            <li>Select Site(s)</li>
                                            <li>Click Show Theme button</li>
                                            <li>After list generates, select wanted theme(s)</li>
                                            <li>Choose Activate from Bulk Action menu</li>
                                            <li>Click Confirm</li>
                                        </ol>
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg" number="2">
                            <h3>How to install a Theme</h3>
                            <p>You can install new theme by searching WordPress theme repository or by uploading the theme from your computer
                                <h4>Search Themes</h4>
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
                                        Click Search Theme button <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-themes-install.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Select the site(s) you want to install the theme, and click Install
                                    </li>
                                </ol>
                                <h4>Upload Themes</h4>
                                <ol>
                                    <li>
                                        Click the Install Tab
                                    </li>
                                    <li>
                                        Click the Upload toggle Button
                                    </li>
                                    <li>
                                        Click â€œUpload Nowâ€ button<br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-theme-upload.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Locate your Theme
                                    </li>
                                    <li>
                                        Select sites you want to install the themes
                                    </li>
                                    <li>
                                        Click â€œInstall Nowâ€ button
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg" number="3">
                            <h3>How to update Themes</h3>
                            <p>
                                <ol>
                                    <li>
                                        Go to main MainWP Dashboard
                                    </li>
                                    <li>
                                        Locate your â€œRight Nowâ€ Widget
                                    </li>
                                    <li>
                                        Click â€œShowâ€ on in â€œTheme Upgrades Availableâ€ area <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-available-themes.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Click the middle upgrades link to show the drop down of the available upgrades for that site <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-available-themes-show.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Select â€œUpgradeâ€ next to the name of the theme or â€œUpgrade Allâ€ to upgrade all themes on the site <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-available-themes-upgrade.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg" number="4">
                            <h3>Ignore a Theme update</h3>
                            <p>
                                <ol>
                                    <li>
                                        Go to main MainWP Dashboard
                                    </li>
                                    <li>
                                        Locate your â€œRight Nowâ€ Widget
                                    </li>
                                    <li>
                                        Click â€œShowâ€ on in â€œTheme Upgrades Availableâ€ area <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-available-themes.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Click the middle upgrades link to show the drop down of the available upgrades for that site
                                    </li>
                                    <li>
                                        Click â€œIgnoreâ€ next to the name of the theme<br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-plugin-ignore.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                </ol>
                            </p>
                        </div>
                      </div>
                    </div>
    <?php
    self::renderFooter('ThemesHelp');
    }
}

?>