<?php
class MainWPManageSites
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static $subPages;
    public static $page;
    /** @var $sitesTable MainWPManageSites_List_Table */
    public static $sitesTable;

    public static function init()
    {
        add_action('mainwp-pageheader-sites', array(MainWPManageSites::getClassName(), 'renderHeader'));
        add_action('mainwp-pagefooter-sites', array(MainWPManageSites::getClassName(), 'renderFooter'));

        add_filter('set-screen-option', array(MainWPManageSites::getClassName(), 'setScreenOption'), 10, 3);
    }

    static function on_screen_layout_columns($columns, $screen)
    {
        if ($screen == self::$page) {
            $columns[self::$page] = 3; //Number of supported columns
        }
        return $columns;
    }

    public static function initMenu()
    {
        self::$page = MainWPManageSitesView::initMenu();
        add_submenu_page('mainwp_tab', __('Sites Help','mainwp'), '<div class="mainwp-hidden">' . __('Sites Help','mainwp') . '</div>', 'read', 'SitesHelp', array(MainWPManageSites::getClassName(), 'QSGManageSites'));

        if (isset($_REQUEST['dashboard']))
        {
            global $current_user;
            delete_user_option($current_user->ID, 'screen_layout_toplevel_page_managesites');
            add_filter('screen_layout_columns', array(self::getClassName(), 'on_screen_layout_columns'), 10, 2);

            $val = get_user_option('screen_layout_' . self::$page);
            if (!MainWPUtility::ctype_digit($val))
            {
                global $current_user;
                update_user_option($current_user->ID, 'screen_layout_' . self::$page, 2, true);
            }

            add_action('load-'.MainWPManageSites::$page, array(MainWPManageSites::getClassName(), 'on_load_page_dashboard'));
        }
        else if (isset($_REQUEST['id']))
        {

        }
        else
        {
//            add_action('load-'.MainWPManageSites::$page, array(MainWPManageSites::getClassName(), 'on_load_page_manage'));
            add_action('load-'.MainWPManageSites::$page, array(MainWPManageSites::getClassName(), 'add_options')); //todo: RS: generify
        }
        add_submenu_page('mainwp_tab', 'Sites', '<div class="mainwp-hidden">Sites</div>', 'read', 'SiteOpen', array(MainWPSiteOpen::getClassName(), 'render'));
        add_submenu_page('mainwp_tab', 'Sites', '<div class="mainwp-hidden">Sites</div>', 'read', 'SiteRestore', array(MainWPSiteOpen::getClassName(), 'renderRestore'));

        self::$subPages = apply_filters('mainwp-getsubpages-sites', array());
        if (isset(self::$subPages) && is_array(self::$subPages))
        {
            foreach (self::$subPages as $subPage)
            {
                add_submenu_page('mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'ManageSites' . $subPage['slug'], $subPage['callback']);
            }
        }
    }

    public static function initMenuSubPages()
    {
        MainWPManageSitesView::initMenuSubPages(self::$subPages);
    }

    public static function renderHeader($shownPage)
    {
        MainWPManageSitesView::renderHeader($shownPage, self::$subPages);
    }

    public static function renderFooter($shownPage)
    {
        MainWPManageSitesView::renderFooter($shownPage, self::$subPages);
    }

    public static function renderNewSite()
    {
        if (isset($_REQUEST['mainwp_managesites_chk_bulkupload']) && $_REQUEST['mainwp_managesites_chk_bulkupload'])
        {
            self::renderBulkUpload();
        }
        else
        {
            $groups = MainWPDB::Instance()->getGroupsForCurrentUser();
            self::renderHeader('AddNew');
            MainWPManageSitesView::_renderNewSite($groups);
            self::renderFooter('AddNew');
        }
    }

    public static function renderTest()
    {
        self::renderHeader('Test');

        MainWPManageSitesView::renderTest();

        self::renderFooter('Test');
    }

    public static function renderBulkUpload()
    {
        self::renderHeader('ManageSitesBulkUpload');
        MainWPManageSitesView::renderBulkUpload();
        self::renderFooter('ManageSitesBulkUpload');
    }

    /**
     * @throws MainWPException
     */
    public static function backupSite($siteid, $userid, $type, $exclude, $taskId, $subfolder, $pFilename = null)
    {
        if (trim($pFilename) == '') $pFilename = null;

        $backup_result = array();

        //Creating a backup
        $website = MainWPDB::Instance()->getWebsiteById($siteid);
        $subfolder = str_replace('%sitename%', MainWPUtility::sanitize($website->name), $subfolder);
        $subfolder = str_replace('%url%', MainWPUtility::sanitize(MainWPUtility::getNiceURL($website->url)), $subfolder);
        $subfolder = str_replace('%type%', $type, $subfolder);
        $subfolder = str_replace('%date%', date('Ymd'), $subfolder);
        $subfolder = str_replace('%task%', '', $subfolder);

        if (!MainWPSystem::Instance()->isSingleUser() && $userid != $website->userid)
        {
            throw new MainWPException('Undefined error');
        }

        $websiteCleanUrl = $website->url;
        if (substr($websiteCleanUrl, -1) == '/')
        {
            $websiteCleanUrl = substr($websiteCleanUrl, 0, -1);
        }
        $websiteCleanUrl = str_replace(array('http://', 'https://', '/'), array('', '', '-'), $websiteCleanUrl);

        $maximumFileDescriptors = get_option('mainwp_maximumFileDescriptors');
        $maximumFileDescriptors = ($maximumFileDescriptors === false ? 0 : $maximumFileDescriptors);
        $file = str_replace(array('%sitename%', '%url%', '%date%', '%time%', '%type%'), array(MainWPUtility::sanitize($website->name), $websiteCleanUrl, date('m-d-Y'), date('G\hi\ms\s'), $type), $pFilename) . '.zip';

        $information = MainWPUtility::fetchUrlAuthed($website, 'backup', array('type' => $type, 'exclude' => $exclude, 'file_descriptors' => $maximumFileDescriptors, 'file' => $file));
        if (isset($information['error']))
        {
            throw new MainWPException($information['error']);
        }
        else if ($type == 'db' && !$information['db'])
        {
            throw new MainWPException('Database backup failed.');
        }
        else if ($type == 'full' && !$information['full'])
        {
            throw new MainWPException('Full backup failed.');
        }
        else if (isset($information['db']))
        {
            $http = new WP_Http();

            $dir = MainWPUtility::getMainWPSpecificDir($website->id);

            @mkdir($dir, 0777, true);

            if (!file_exists($dir . 'index.php'))
            {
                @touch($dir . 'index.php');
            }


            //Clean old backups from our system
            $maxBackups = get_option('mainwp_backupsOnServer');
            if ($maxBackups === false) $maxBackups = 1;

            $dbBackups = array();
            $fullBackups = array();
            if (file_exists($dir) && ($dh = opendir($dir)))
            {
                while (($file = readdir($dh)) !== false)
                {
                    if ($file != '.' && $file != '..')
                    {
                        $theFile = $dir . $file;
                        if ($information['db'] && (preg_match('/(.*).sql$/', $file) || preg_match('/(.*).sql.zip$/', $file)))
                        {
                            $dbBackups[filemtime($theFile) . $file] = $theFile;
                        }

                        if ($information['full'] && preg_match('/(.*).zip/', $file))
                        {
                            $fullBackups[filemtime($theFile) . $file] = $theFile;
                        }
                    }
                }
                closedir($dh);
            }
            krsort($dbBackups);
            krsort($fullBackups);

            $cnt = 0;
            foreach ($dbBackups as $key => $dbBackup)
            {
                $cnt++;
                if ($cnt >= $maxBackups)
                {
                    @unlink($dbBackup);
                }
            }

            $cnt = 0;
            foreach ($fullBackups as $key => $fullBackup)
            {
                $cnt++;
                if ($cnt >= $maxBackups)
                {
                    @unlink($fullBackup);
                }
            }

            $localBackupFile = null;

            $what = null;
            $regexBackupFile = null;
            if ($information['db'])
            {
                $what = 'db';
                $localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . date('m-d-Y') . '-' . time() . '.sql';
                $regexBackupFile = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip)?';

                if ($pFilename != null)
                {
                    $localBackupFile = $dir . str_replace(array('%sitename%', '%url%', '%date%', '%time%', '%type%'), array(MainWPUtility::sanitize($website->name), $websiteCleanUrl, date('m-d-Y'), date('G\hi\ms\s'), $what), $pFilename) . '.sql';
                }

                if (MainWPUtility::endsWith($information['db'], 'zip')) $localBackupFile .= '.zip';

                MainWPUtility::downloadToFile($information['db'], $localBackupFile);
            }

            if ($information['full'])
            {
                $what = 'full';
                $localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . date('m-d-Y') . '-' . time() . '.zip';
                $regexBackupFile = 'full-' . $websiteCleanUrl . '-(.*)-(.*).zip';

                if ($pFilename != null)
                {
                    $localBackupFile = $dir . str_replace(array('%sitename%', '%url%', '%date%', '%time%', '%type%'), array(MainWPUtility::sanitize($website->name), $websiteCleanUrl, date('m-d-Y'), date('G\hi\ms\s'), $what), $pFilename) . '.zip';
                }

                MainWPUtility::downloadToFile($information['full'], $localBackupFile);
            }

            $unique = time();
            do_action('mainwp_postprocess_backup_site', $localBackupFile, $what, $subfolder, $regexBackupFile, $website, $taskId, $unique);
            $extra_result = apply_filters('mainwp_postprocess_backup_sites_feedback', array(), $unique);
            if (is_array($extra_result))
            {
                foreach ($extra_result as $key => $value)
                {
                    $backup_result[$key] = $value;
                }
            }
        }
        else
        {
            throw new MainWPException('Database backup failed due to an undefined error');
        }

        return $backup_result;
    }

    public static function backupGetfilesize($pFile)
    {
        $dir = MainWPUtility::getMainWPSpecificDir();

        if (stristr($pFile, $dir))
        {
            return filesize($pFile);
        }
        return 0;
    }

    public static function backupDownloadFile($pSiteId, $pType, $pUrl, $pFile)
    {
        $dir = dirname($pFile) . '/';
        @mkdir($dir, 0777, true);
        if (!file_exists($dir . 'index.php'))
        {
            @touch($dir . 'index.php');
        }
        //Clean old backups from our system
        $maxBackups = get_option('mainwp_backupsOnServer');
        if ($maxBackups === false) $maxBackups = 1;

        $dbBackups = array();
        $fullBackups = array();

        if (file_exists($dir) && ($dh = opendir($dir)))
        {
            while (($file = readdir($dh)) !== false)
            {
                if ($file != '.' && $file != '..')
                {
                    $theFile = $dir . $file;
                    if ($pType == 'db' && preg_match('/(.*).sql(\.zip)?$/', $file))
                    {
                        $dbBackups[filemtime($theFile) . $file] = $theFile;
                    }

                    if ($pType == 'full' && preg_match('/(.*).zip/', $file))
                    {
                        $fullBackups[filemtime($theFile) . $file] = $theFile;
                    }
                }
            }
            closedir($dh);
        }
        krsort($dbBackups);
        krsort($fullBackups);

        $cnt = 0;
        foreach ($dbBackups as $key => $dbBackup)
        {
            $cnt++;
            if ($cnt >= $maxBackups)
            {
                @unlink($dbBackup);
            }
        }

        $cnt = 0;
        foreach ($fullBackups as $key => $fullBackup)
        {
            $cnt++;
            if ($cnt >= $maxBackups)
            {
                @unlink($fullBackup);
            }
        }

        session_write_close();

        $what = null;
        if ($pType == 'db')
        {
            MainWPUtility::downloadToFile($pUrl, $pFile);
        }

        if ($pType == 'full')
        {
            MainWPUtility::downloadToFile($pUrl, $pFile);
        }

        return true;
    }

    public static function backup($pSiteId, $pType, $pSubfolder, $pExclude, $pFilename = null, $pFileNameUID = '')
    {
        if (trim($pFilename) == '') $pFilename = null;

        $backup_result = array();

        //Creating a backup
        $website = MainWPDB::Instance()->getWebsiteById($pSiteId);
        $subfolder = str_replace('%sitename%', MainWPUtility::sanitize($website->name), $pSubfolder);
        $subfolder = str_replace('%url%', MainWPUtility::sanitize(MainWPUtility::getNiceURL($website->url)), $subfolder);
        $subfolder = str_replace('%type%', $pType, $subfolder);
        $subfolder = str_replace('%date%', date('Ymd'), $subfolder);
        $subfolder = str_replace('%task%', '', $subfolder);

        if (!MainWPUtility::can_edit_website($website))
        {
            throw new MainWPException('You are not allowed to backup this site');
        }

        $websiteCleanUrl = $website->url;
        if (substr($websiteCleanUrl, -1) == '/')
        {
            $websiteCleanUrl = substr($websiteCleanUrl, 0, -1);
        }
        $websiteCleanUrl = str_replace(array('http://', 'https://', '/'), array('', '', '-'), $websiteCleanUrl);

        MainWPUtility::endSession();
        $maximumFileDescriptors = get_option('mainwp_maximumFileDescriptors');
        $maximumFileDescriptors = ($maximumFileDescriptors === false ? 0 : $maximumFileDescriptors);
        $file = str_replace(array('%sitename%', '%url%', '%date%', '%time%', '%type%'), array(MainWPUtility::sanitize($website->name), $websiteCleanUrl, date('m-d-Y'), date('G\hi\ms\s'), $pType), $pFilename);

        $information = MainWPUtility::fetchUrlAuthed($website, 'backup', array('type' => $pType, 'exclude' => $pExclude, 'file_descriptors' => $maximumFileDescriptors, 'file' => $file, 'fileUID' => $pFileNameUID));

        if (isset($information['error']))
        {
            throw new MainWPException($information['error']);
        }
        else if ($pType == 'db' && !$information['db'])
        {
            throw new MainWPException('Database backup failed.');
        }
        else if ($pType == 'full' && !$information['full'])
        {
            throw new MainWPException('Full backup failed.');
        }
        else if (isset($information['db']))
        {
            if ($information['db'] != false)
            {
                $backup_result['url'] = $information['db'];
                $backup_result['type'] = 'db';
            }
            else if ($information['full'] != false)
            {
                $backup_result['url'] = $information['full'];
                $backup_result['type'] = 'full';
            }

            if (isset($information['size']))
            {
                $backup_result['size'] = $information['size'];
            }
            $backup_result['subfolder'] = $subfolder;

            $dir = MainWPUtility::getMainWPSpecificDir($pSiteId);

            if ($pType == 'db')
            {
                $localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . date('m-d-Y') . '-' . time() . '.sql';
                $localRegexFile = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip)?';
            }
            else
            {
                $localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . date('m-d-Y') . '-' . time() . '.zip';
                $localRegexFile = 'full-' . $websiteCleanUrl . '-(.*)-(.*).zip';
            }

            if ($pFilename != null)
            {
                $localBackupFile = $dir . str_replace(array('%sitename%', '%url%', '%date%', '%time%', '%type%'), array(MainWPUtility::sanitize($website->name), $websiteCleanUrl, date('m-d-Y'), date('G\hi\ms\s'), $pType), $pFilename);

                if ($pType == 'db')
                {
                    $localBackupFile .= '.sql';
                }
                else
                {
                    $localBackupFile .= '.zip';
                }
            }

            if (($pType == 'db') && MainWPUtility::endsWith($information['db'], 'zip')) $localBackupFile .= '.zip';

            $backup_result['local'] = $localBackupFile;
            $backup_result['regexfile'] = $localRegexFile;

            return $backup_result;
        }
        else
        {
            throw new MainWPException('Database backup failed due to an undefined error');
        }
    }

    public static function renderSeoPage($website)
    {
        MainWPManageSitesView::renderSeoPage($website);
    }

    public static function on_load_page_dashboard()
    {
        wp_enqueue_script('common');
        wp_enqueue_script('wp-lists');
        wp_enqueue_script('postbox');
        wp_enqueue_script('dashboard');
        wp_enqueue_script('widgets');

        $i = 1;
        add_meta_box(self::$page . '-metaboxes-contentbox-' . $i++, MainWPRightNow::getName(), array(MainWPRightNow::getClassName(), 'render'), self::$page, 'normal', 'core');
        add_meta_box(self::$page . '-metaboxes-contentbox-' . $i++, MainWPRecentPosts::getName(), array(MainWPRecentPosts::getClassName(), 'render'), self::$page, 'normal', 'core');
        add_meta_box(self::$page . '-metaboxes-contentbox-' . $i++, MainWPRecentPages::getName(), array(MainWPRecentPages::getClassName(), 'render'), self::$page, 'normal', 'core');
        add_meta_box(self::$page . '-metaboxes-contentbox-' . $i++, MainWPShortcuts::getName(), array(MainWPShortcuts::getClassName(), 'render'), self::$page, 'normal', 'core');
        add_meta_box(self::$page . '-metaboxes-contentbox-' . $i++, MainWPSecurityIssues::getMetaboxName(), array(MainWPSecurityIssues::getClassName(), 'renderMetabox'), self::$page, 'normal', 'core');
        if (get_option('mainwp_seo') == 1) add_meta_box(self::$page . '-metaboxes-contentbox-' . $i++, MainWPManageSites::getMetaboxName(), array(MainWPManageSites::getClassName(), 'renderMetabox'), self::$page, 'normal', 'core');
        add_meta_box(self::$page . '-metaboxes-contentbox-' . $i++, MainWPManageBackups::getMetaboxName(), array(MainWPManageBackups::getClassName(), 'renderMetabox'), self::$page, 'normal', 'core');
		add_meta_box(self::$page . '-metaboxes-contentbox-' . $i++, MainWPWidgetPlugins::getName(), array(MainWPWidgetPlugins::getClassName(), 'render'), self::$page, 'normal', 'core');
		add_meta_box(self::$page . '-metaboxes-contentbox-' . $i++, MainWPWidgetThemes::getName(), array(MainWPWidgetThemes::getClassName(), 'render'), self::$page, 'normal', 'core');
        add_meta_box(self::$page . '-metaboxes-contentbox-' . $i++, MainWPNotes::getName(), array(MainWPNotes::getClassName(), 'render'), self::$page, 'normal', 'core');
		
        $extMetaBoxs = MainWPSystem::Instance()->apply_filter('mainwp-getmetaboxes', array());
        $extMetaBoxs = apply_filters('mainwp-getmetaboxs', $extMetaBoxs);
        foreach ($extMetaBoxs as $metaBox)
        {
            add_meta_box(self::$page.'-contentbox-' . $i++, $metaBox['metabox_title'], $metaBox['callback'], self::$page, 'normal', 'core');
        }
    }

    public static function renderDashboard($website)
    {
        MainWPUtility::set_current_wpid($website->id);
        self::renderHeader('ManageSitesDashboard');
        MainWPManageSitesView::renderDashboard($website, self::$page);
        self::renderFooter('ManageSitesDashboard');
    }

    public static function renderBackupSite($website)
    {
        MainWPManageSitesView::renderBackupSite($website);
    }

    public static function showBackups(&$website)
    {
        $dir = MainWPUtility::getMainWPSpecificDir($website->id);

        if (!file_exists($dir . 'index.php'))
        {
            @touch($dir . 'index.php');
        }
        $dbBackups = array();
        $fullBackups = array();
        if (file_exists($dir) && ($dh = opendir($dir)))
        {
            while (($file = readdir($dh)) !== false)
            {
                if ($file != '.' && $file != '..')
                {
                    $theFile = $dir . $file;
                    if (preg_match('/(.*)\.sql(\.zip)?$/', $file))
                    {
                        $dbBackups[filemtime($theFile) . $file] = $theFile;
                    }
                    else if (preg_match('/(.*)\.zip/', $file))
                    {
                        $fullBackups[filemtime($theFile) . $file] = $theFile;
                    }
                }
            }
            closedir($dh);
        }
        krsort($dbBackups);
        krsort($fullBackups);

        MainWPManageSitesView::showBackups($website, $fullBackups, $dbBackups);
    }

    protected static function getOppositeOrderBy($pOrderBy)
    {
        return ($pOrderBy == 'asc' ? 'desc' : 'asc');
    }

    public static function _renderAllSites($showDelete = true, $showAddNew = true)
    {
        self::renderHeader('');

        $userExtension = MainWPDB::Instance()->getUserExtension();

        $globalIgnoredPluginConflicts = json_decode($userExtension->ignored_pluginConflicts, true);
        if (!is_array($globalIgnoredPluginConflicts)) $globalIgnoredPluginConflicts = array();

        $globalIgnoredThemeConflicts = json_decode($userExtension->ignored_themeConflicts, true);
        if (!is_array($globalIgnoredThemeConflicts)) $globalIgnoredThemeConflicts = array();

        self::$sitesTable->prepare_items($globalIgnoredPluginConflicts, $globalIgnoredThemeConflicts);
      ?>
        <div id="mainwp_managesites_content">
            <div id="mainwp_managesites_add_errors" class="mainwp_error"></div>
            <div id="mainwp_managesites_add_message" class="mainwp_updated updated"></div>
            <?php
            MainWPManageSitesView::_renderInfo();

//            self::$sitesTable->display_search();
            ?>
        <form method="post" class="mainwp-table-container">
          <input type="hidden" name="page" value="sites_list_table">
          <?php
        MainWPManageSitesView::_renderNotes();
        self::$sitesTable->display();
        self::$sitesTable->clear_items();
       ?>
        </form>
    </div><?php

        self::renderFooter('');
    }

    public static function renderAllSites()
    {
        global $current_user;

        if (isset($_REQUEST['do']))
        {
            if ($_REQUEST['do'] == 'new')
            {
                self::renderNewSite();
            }
            else if ($_REQUEST['do'] == 'test')
            {
                self::renderTest();
            }
            return;
        }
        $website = null;
        if (isset($_GET['backupid']) && MainWPUtility::ctype_digit($_GET['backupid']))
        {
            $websiteid = $_GET['backupid'];

            $backupwebsite = MainWPDB::Instance()->getWebsiteById($websiteid);
            if (MainWPUtility::can_edit_website($backupwebsite))
            {
                MainWPManageSites::renderBackupSite($backupwebsite);
                return;
            }
        }

        if (isset($_GET['seowebsiteid']) && MainWPUtility::ctype_digit($_GET['seowebsiteid']))
        {
            $websiteid = $_GET['seowebsiteid'];

            $seoWebsite = MainWPDB::Instance()->getWebsiteById($websiteid);
            if (MainWPUtility::can_edit_website($seoWebsite))
            {
                MainWPManageSites::renderSeoPage($seoWebsite);
                return;
            }
        }

        if (isset($_GET['dashboard']) && MainWPUtility::ctype_digit($_GET['dashboard']))
        {
            $websiteid = $_GET['dashboard'];

            $dashboardWebsite = MainWPDB::Instance()->getWebsiteById($websiteid);
            if (MainWPUtility::can_edit_website($dashboardWebsite))
            {
                MainWPManageSites::renderDashboard($dashboardWebsite);
                return;
            }
        }

        if (isset($_GET['id']) && MainWPUtility::ctype_digit($_GET['id']))
        {
            $websiteid = $_GET['id'];

            $website = MainWPDB::Instance()->getWebsiteById($websiteid);
            if (!MainWPUtility::can_edit_website($website))
            {
                $website = null;
            }
        }

        if ($website == null)
        {
            self::_renderAllSites();
        }
        else
        {
            $updated = false;
            //Edit website!
            if (isset($_POST['submit']) && isset($_POST['mainwp_managesites_edit_siteadmin']) && $_POST['mainwp_managesites_edit_siteadmin'] != '')
            {
                //update site
                $groupids = array();
                $groupnames = array();
                if (isset($_POST['selected_groups']))
                {
                    foreach ($_POST['selected_groups'] as $group)
                    {
                        $groupids[] = $group;
                    }
                }
                if (isset($_POST['mainwp_managesites_edit_addgroups']) && $_POST['mainwp_managesites_edit_addgroups'] != '')
                {
                    $tmpArr = explode(',', $_POST['mainwp_managesites_edit_addgroups']);
                    foreach ($tmpArr as $tmp)
                    {
                        $group = MainWPDB::Instance()->getGroupByNameForUser(trim($tmp));
                        if ($group)
                        {
                            if (!in_array($group->id, $groupids))
                            {
                                $groupids[] = $group->id;
                            }
                        }
                        else
                        {
                            $groupnames[] = trim($tmp);
                        }
                    }
                }
                $newPluginDir = (isset($_POST['mainwp_options_footprint_plugin_folder']) ? $_POST['mainwp_options_footprint_plugin_folder'] : '');

                MainWPDB::Instance()->updateWebsite($website->id, $current_user->ID, $_POST['mainwp_managesites_edit_sitename'], $_POST['mainwp_managesites_edit_siteadmin'], $groupids, $groupnames, $_POST['offline_checks'], $newPluginDir);

                do_action('mainwp_update_site', $website->id);

                $newValues = array('automatic_update' => (!isset($_POST['mainwp_automaticDailyUpdate']) ? 0 : 1),
                    'backup_before_upgrade' => (!isset($_POST['mainwp_backup_before_upgrade']) ? 0 : 1));
                MainWPDB::Instance()->updateWebsiteValues($website->id, $newValues);
                $updated = true;
                //Reload the site
                $website = MainWPDB::Instance()->getWebsiteById($website->id);
            }

            $groups = MainWPDB::Instance()->getGroupsForCurrentUser();
            $statusses = array('hourly', '2xday', 'daily', 'weekly');

            $pluginDir = $website->pluginDir;
            self::renderHeader('ManageSitesEdit');
            MainWPManageSitesView::renderAllSites($website, $updated, $groups, $statusses, $pluginDir);
            self::renderFooter('ManageSitesEdit');
        }
    }

    public static function checkSite()
    {
        $website = MainWPDB::Instance()->getWebsitesByUrl($_POST['url']);
        $ret = array();
        if (MainWPUtility::can_edit_website($website))
        { //Already added to the database - so exists.
            $ret['response'] = 'ERROR You already added your site to MainWP';
        }
        else
        {
            try
            {
                $information = MainWPUtility::fetchUrlNotAuthed($_POST['url'], $_POST['admin'], 'stats'); //Fetch the stats with the given admin name

                if (isset($information['wpversion']))
                { //Version found - able to add
                    $ret['response'] = 'OK';
                }
                else if (isset($information['error']))
                { //Error
                    $ret['response'] = 'ERROR ' . $information['error'];
                }
                else
                { //Should not occur?
                    $ret['response'] = 'ERROR';
                }
            }
            catch (MainWPException $e)
            {
                //Exception - error
                $ret['response'] = $e->getMessage();
            }
        }
        $ret['check_me'] = (isset($_POST['check_me']) ? $_POST['check_me'] : null);
        die(json_encode($ret));
    }

    public static function reconnectSite()
    {
        $siteId = $_POST['siteid'];

        try
        {
            if (MainWPUtility::ctype_digit($siteId))
            {
                $website = MainWPDB::Instance()->getWebsiteById($siteId);
                self::_reconnectSite($website);
            }
            else
            {
                throw new Exception('Invalid request');
            }
        }
        catch (Exception $e)
        {
            die('ERROR ' . $e->getMessage());
        }

        die('Site successfully reconnected');
    }

    public static function _reconnectSite($website)
    {
        return MainWPManageSitesView::_reconnectSite($website);
    }

    public static function addSite()
    {
        $ret = array();
        $error = '';
        $message = '';

        if (isset($_POST['managesites_add_wpurl']) && isset($_POST['managesites_add_wpadmin']))
        {
            //Check if already in DB
            $website = MainWPDB::Instance()->getWebsitesByUrl($_POST['managesites_add_wpurl']);
            list($message, $error) = MainWPManageSitesView::addSite($website);
        }

        $ret['add_me'] = (isset($_POST['add_me']) ? $_POST['add_me'] : null);
        if ($error != '')
        {
            $ret['response'] = 'ERROR ' . $error;
            die(json_encode($ret));
        }
        $ret['response'] = $message;
        if (MainWPDB::Instance()->getWebsitesCount() == 1) $ret['redirectUrl'] = admin_url('admin.php?page=managesites');

        die(json_encode($ret));
    }

    public static function saveNote()
    {
        if (isset($_POST['websiteid']) && MainWPUtility::ctype_digit($_POST['websiteid']))
        {
            $website = MainWPDB::Instance()->getWebsiteById($_POST['websiteid']);
            if (MainWPUtility::can_edit_website($website))
            {
                MainWPDB::Instance()->updateNote($website->id, $_POST['note']);
                die(json_encode(array('result' => 'SUCCESS')));
            }
            else
            {
                die(json_encode(array('error' => 'Not your website')));
            }
        }
        die(json_encode(array('undefined_error' => true)));
    }


    public static function removeSite()
    {
        if (isset($_POST['id']) && MainWPUtility::ctype_digit($_POST['id']))
        {
            $website = MainWPDB::Instance()->getWebsiteById($_POST['id']);
            if (MainWPUtility::can_edit_website($website))
            {
                $error = '';

                try
                {
                    $information = MainWPUtility::fetchUrlAuthed($website, 'deactivate');
                }
                catch (MainWPException $e)
                {
                    $error = $e->getMessage();
                }

                //Remove from DB
                MainWPDB::Instance()->removeWebsite($website->id);
                do_action('mainwp_delete_site', $website);
                if ($error != '')
                {
                    die(json_encode(array('error' => $error)));
                }
                else if (isset($information['deactivated']))
                {
                    die(json_encode(array('result' => 'SUCCESS')));
                }
                else
                {
                    die(json_encode(array('undefined_error' => true)));
                }
            }
        }
        die(json_encode(array('result' => 'NOSITE')));
    }

    public static function handleSettingsPost()
    {
        if (MainWPUtility::isAdmin())
        {
            if (isset($_POST['submit']))
            {
                if (MainWPUtility::ctype_digit($_POST['mainwp_options_backupOnServer']) && $_POST['mainwp_options_backupOnServer'] > 0)
                {
                    MainWPUtility::update_option('mainwp_backupsOnServer', $_POST['mainwp_options_backupOnServer']);
                }
                if (MainWPUtility::ctype_digit($_POST['mainwp_options_maximumFileDescriptors']) && $_POST['mainwp_options_maximumFileDescriptors'] > -1)
                {
                    MainWPUtility::update_option('mainwp_maximumFileDescriptors', $_POST['mainwp_options_maximumFileDescriptors']);
                }
                if (MainWPUtility::ctype_digit($_POST['mainwp_options_backupOnExternalSources']) && $_POST['mainwp_options_backupOnExternalSources'] >= 0)
                {
                    MainWPUtility::update_option('mainwp_backupOnExternalSources', $_POST['mainwp_options_backupOnExternalSources']);
                }
                MainWPUtility::update_option('mainwp_notificationOnBackupFail', (!isset($_POST['mainwp_options_notificationOnBackupFail']) ? 0 : 1));
                MainWPUtility::update_option('mainwp_notificationOnBackupStart', (!isset($_POST['mainwp_options_notificationOnBackupStart']) ? 0 : 1));
                MainWPUtility::update_option('mainwp_chunkedBackupTasks', (!isset($_POST['mainwp_options_chunkedBackupTasks']) ? 0 : 1));

                return true;
            }
        }
        return false;
    }

    public static function renderSettings()
    {
        if (MainWPUtility::isAdmin())
        {
            MainWPManageSitesView::renderSettings();
        }
    }

    //todo: RS: Generify
    public static function add_options()
    {
        $option = 'per_page';
        $args = array(
            'label' => MainWPManageSitesView::sitesPerPage(),
            'default' => 10,
            'option' => 'mainwp_managesites_per_page'
        );
        add_screen_option($option, $args);

        self::$sitesTable = new MainWPManageSites_List_Table();
    }

    public static function on_load_page_manage()
    {
        $screen = get_current_screen();
        // get out of here if we are not on our settings page
        if (!is_object($screen) || $screen->id != MainWPManageSites::$page)
            return;

        $args = array(
            'label' => MainWPManageSitesView::sitesPerPage(),
            'default' => 20,
            'option' => 'mainwp_managesites_per_page'
        );
        add_screen_option('per_page', $args);
    }

    public static function setScreenOption($status, $option, $value)
    {
        if ('mainwp_managesites_per_page' == $option) return $value;

        return null;
    }

    protected static function getPerPage()
    {
        // get the current user ID
        $user = get_current_user_id();
        // get the current admin screen
        $screen = get_current_screen();
        // retrieve the "per_page" option
        $screen_option = $screen->get_option('per_page', 'option');
        // retrieve the value of the option stored for the current user
        $per_page = get_user_meta($user, $screen_option, true);
        if (empty ($per_page) || $per_page < 1)
        {
            // get the default value if none is set
            $per_page = $screen->get_option('per_page', 'default');
        }

        return $per_page;
    }

    public static function getMetaboxName()
    {
        return 'SEO Details';
    }

    public static function renderMetabox()
    {
        $website = MainWPUtility::get_current_wpid();
        if (!$website) return;

        $website = MainWPDB::Instance()->getWebsiteById($website);

        MainWPManageSitesView::showSEOWidget($website);
    }

    public static function QSGManageSites()
    {
      self::renderHeader('SitesHelp');
    ?><div style="text-align: center"><a href="#" class="button button-primary" id="mainwp-quick-start-guide"><?php _e('Show Quick Start Guide','mainwp'); ?></a></div>
                      <div  class="mainwp_info-box-yellow" id="mainwp-qsg-tips">
                          <span><a href="#" class="mainwp-show-qsg" number="1"><?php _e('Adding a Site To MainWP Dashboard','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg"  number="2"><?php _e('How to use the Child Unique Security ID','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg"  number="3"><?php _e('Individual Site Dashboard','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg"  number="4"><?php _e('How to test a Site Connection','mainwp') ?></a></span><span><a href="#" id="mainwp-qsg-dismiss" style="float: right;"><?php _e('Dismiss','mainwp'); ?></a></span>
                      <div class="clear"></div>
                      <div id="mainwp-qsgs">
                        <div class="mainwp-qsg" number="1">
                            <h3>Adding a Site To MainWP Dashboard</h3>
                            <p>
                                <ol>
                                    <li>
                                        Click the â€œAdd New Siteâ€ button in or go to MainWP > Sites > Add New<br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-add-new-site-1024x64.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Enter Site Name, Site URL and Administrator Username <br/><br/>
                                    </li>
                                    <li>
                                        Enter or Select a Group Name (optional) <br/><br/>
                                    </li>
                                    <li>
                                        Click the Add New Site button.
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg"  number="2">
                            <h3>How to use the Child Unique Security ID</h3>
                            <p>
                                <ol>
                                    <li>In the Child Site locate MainWP Settings in the Main WordPress Settings tab</li>
                                    <li>Click â€œRequire Unique Security IDâ€</li>
                                    <li>
                                        Click Save Changes <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/04/new-sec-id.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>In Your Main Network site Click the Add New Site button</li>
                                    <li>In the Child Unique Security ID field enter the Unique ID you received from your child</li>
                                    <li>Fill in all other fields as normal</li>
                                    <li>Click Add New Site</li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg"  number="3">
                            <h3>Individual Site Dashboard</h3>
                            <p>
                                <ol>
                                    <li>
                                        Locate the wanted site in the list and click the â€œDashboardâ€ link under the site name or just click on the site name  <br /><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/12/new-dashboard-link-1024x77.png" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg"  number="4">
                            <h3>How to test a Site Connection</h3>
                            <p>
                                <ol>
                                    <li>
                                        Click the Test Connection Tab <br /><br />
                                    </li>
                                    <li>Enter your sites URL</li>
                                    <li>
                                        Click Test Connection <br /><br />
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-test-connection.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                </ol>
                            </p>
                        </div>
                      </div>
                    </div>
        <?php
        self::renderFooter('SitesHelp');
    }
}

?>