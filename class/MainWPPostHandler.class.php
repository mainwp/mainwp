<?php
class MainWPPostHandler
{
    protected $security_nonces;

    function __construct()
    {
    }

    function init()
    {
        //Page: ManageBackups
        $this->addAction('mainwp_addbackup', array(&$this, 'mainwp_addbackup'));
        $this->addAction('mainwp_updatebackup', array(&$this, 'mainwp_updatebackup'));
        $this->addAction('mainwp_removebackup', array(&$this, 'mainwp_removebackup'));
        $this->addAction('mainwp_pausebackup', array(&$this, 'mainwp_pausebackup'));
        $this->addAction('mainwp_resumebackup', array(&$this, 'mainwp_resumebackup'));
        add_action('wp_ajax_mainwp_site_dirs', array(&$this, 'mainwp_site_dirs')); //ok
        add_action('wp_ajax_mainwp_backuptask_get_sites', array(&$this, 'mainwp_backuptask_get_sites')); //ok
        $this->addAction('mainwp_backuptask_run_site', array(&$this, 'mainwp_backuptask_run_site'));
        $this->addAction('mainwp_backup_upload_file', array(&$this, 'mainwp_backup_upload_file'));

        //Page: ManageSites
        $this->addAction('mainwp_checkwp', array(&$this, 'mainwp_checkwp'));
        $this->addAction('mainwp_addwp', array(&$this, 'mainwp_addwp'));
        $this->addAction('mainwp_testwp', array(&$this, 'mainwp_testwp'));
        $this->addAction('mainwp_removesite', array(&$this, 'mainwp_removesite'));
        $this->addAction('mainwp_notes_save', array(&$this, 'mainwp_notes_save'));
        add_action('wp_ajax_mainwp_reconnectwp', array(&$this, 'mainwp_reconnectwp')); //ok

        //Page: ManageGroups
        $this->addAction('mainwp_group_rename', array(&$this, 'mainwp_group_rename'));
        add_action('wp_ajax_mainwp_group_delete', array(&$this, 'mainwp_group_delete')); //ok
        $this->addAction('mainwp_group_add', array(&$this, 'mainwp_group_add'));
        add_action('wp_ajax_mainwp_group_getsites', array(&$this, 'mainwp_group_getsites')); //ok
        $this->addAction('mainwp_group_updategroup', array(&$this, 'mainwp_group_updategroup'));

        //Page: InstallPlugins/Themes
        add_action('wp_ajax_mainwp_installbulksearch', array(&$this, 'mainwp_installbulksearch')); //ok
        add_action('wp_ajax_mainwp_installbulknavsearch', array(&$this, 'mainwp_installbulknavsearch')); //ok
        add_action('wp_ajax_mainwp_installbulknavupload', array(&$this, 'mainwp_installbulknavupload')); //ok
        add_action('wp_ajax_mainwp_preparebulkinstallplugintheme', array(&$this, 'mainwp_preparebulkinstallplugintheme')); //ok
        $this->addAction('mainwp_installbulkinstallplugintheme', array(&$this, 'mainwp_installbulkinstallplugintheme'));
        add_action('wp_ajax_mainwp_preparebulkuploadplugintheme', array(&$this, 'mainwp_preparebulkuploadplugintheme')); //ok
        $this->addAction('mainwp_installbulkuploadplugintheme', array(&$this, 'mainwp_installbulkuploadplugintheme'));
        $this->addAction('mainwp_cleanbulkuploadplugintheme', array(&$this, 'mainwp_cleanbulkuploadplugintheme'));

        //Page: BulkAddUser
        $this->addAction('mainwp_bulkadduser', array(&$this, 'mainwp_bulkadduser'));
        add_action('wp_ajax_mainwp_bulkuploadadduser', array(&$this, 'mainwp_bulkuploadadduser')); //ok - to check
        $this->addAction('mainwp_importuser', array(&$this, 'mainwp_importuser'));

        //Widget: RightNow
        $this->addAction('mainwp_syncsites', array(&$this, 'mainwp_syncsites'));
        $this->addAction('mainwp_upgradewp', array(&$this, 'mainwp_upgradewp'));
        $this->addAction('mainwp_upgradeplugintheme', array(&$this, 'mainwp_upgradeplugintheme'));
        add_action('wp_ajax_mainwp_ignoreplugintheme', array(&$this, 'mainwp_ignoreplugintheme')); //ok
        add_action('wp_ajax_mainwp_unignoreplugintheme', array(&$this, 'mainwp_unignoreplugintheme')); //ok
        add_action('wp_ajax_mainwp_ignorepluginsthemes', array(&$this, 'mainwp_ignorepluginsthemes')); //ok
        add_action('wp_ajax_mainwp_unignorepluginsthemes', array(&$this, 'mainwp_unignorepluginsthemes')); //ok
        $this->addAction('mainwp_trust_plugin', array(&$this, 'mainwp_trust_plugin'));
        $this->addAction('mainwp_trust_theme', array(&$this, 'mainwp_trust_theme'));
        $this->addAction('mainwp_checkbackups', array(&$this, 'mainwp_checkbackups'));
        $this->addAction('mainwp_syncerrors_dismiss', array(&$this, 'mainwp_syncerrors_dismiss'));

        //Page: backup
        $this->addAction('mainwp_backup_run_site', array(&$this, 'mainwp_backup_run_site'));
        $this->addAction('mainwp_backup', array(&$this, 'mainwp_backup'));
        $this->addAction('mainwp_createbackup_getfilesize', array(&$this, 'mainwp_createbackup_getfilesize'));
        $this->addAction('mainwp_backup_download_file', array(&$this, 'mainwp_backup_download_file'));
        $this->addAction('mainwp_backup_delete_file', array(&$this, 'mainwp_backup_delete_file'));
        $this->addAction('mainwp_backup_getfilesize', array(&$this, 'mainwp_backup_getfilesize'));
        $this->addAction('mainwp_backup_upload_getprogress', array(&$this, 'mainwp_backup_upload_getprogress'));

        //Page: CloneSite
//        add_action('wp_ajax_mainwp_clonesite_check_backups', array(&$this, 'mainwp_clonesite_check_backups'));
//        add_action('wp_ajax_mainwp_clone', array(&$this, 'mainwp_clone'));
//        add_action('wp_ajax_mainwp_clone_test_ftp', array(&$this, 'mainwp_clone_test_ftp'));

        //Page: SecurityIssues
        add_action('wp_ajax_mainwp_securityIssues_request', array(&$this, 'mainwp_securityIssues_request')); //ok
        add_action('wp_ajax_mainwp_securityIssues_fix', array(&$this, 'mainwp_securityIssues_fix')); //ok
        add_action('wp_ajax_mainwp_securityIssues_unfix', array(&$this, 'mainwp_securityIssues_unfix')); //ok

        //Page: ManageTips
        add_action('wp_ajax_mainwp_managetips_update', array(&$this, 'mainwp_managetips_update')); //ok

        //Page: OfflineChecks
        add_action('wp_ajax_mainwp_offline_check_save', array(&$this, 'mainwp_offline_check_save')); //ok
        add_action('wp_ajax_mainwp_offline_check_save_bulk', array(&$this, 'mainwp_offline_check_save_bulk')); //ok
        add_action('wp_ajax_mainwp_offline_check_check', array(&$this, 'mainwp_offline_check_check')); //ok

        //Page: Recent Posts
        $this->addAction('mainwp_post_unpublish', array(&$this, 'mainwp_post_unpublish'));
        $this->addAction('mainwp_post_publish', array(&$this, 'mainwp_post_publish'));
        $this->addAction('mainwp_post_trash', array(&$this, 'mainwp_post_trash'));
        $this->addAction('mainwp_post_delete', array(&$this, 'mainwp_post_delete'));
        $this->addAction('mainwp_post_restore', array(&$this, 'mainwp_post_restore'));
        $this->addAction('mainwp_post_approve', array(&$this, 'mainwp_post_approve'));

        //Page: Pages
        $this->addAction('mainwp_page_unpublish', array(&$this, 'mainwp_page_unpublish'));
        $this->addAction('mainwp_page_publish', array(&$this, 'mainwp_page_publish'));
        $this->addAction('mainwp_page_trash', array(&$this, 'mainwp_page_trash'));
        $this->addAction('mainwp_page_delete', array(&$this, 'mainwp_page_delete'));
        $this->addAction('mainwp_page_restore', array(&$this, 'mainwp_page_restore'));

        //Page: Users
        $this->addAction('mainwp_user_delete', array(&$this, 'mainwp_user_delete'));
        $this->addAction('mainwp_user_role_to_administrator', array(&$this, 'mainwp_user_role_to_administrator'));
        $this->addAction('mainwp_user_role_to_editor', array(&$this, 'mainwp_user_role_to_editor'));
        $this->addAction('mainwp_user_role_to_author', array(&$this, 'mainwp_user_role_to_author'));
        $this->addAction('mainwp_user_role_to_contributor', array(&$this, 'mainwp_user_role_to_contributor'));
        $this->addAction('mainwp_user_role_to_subscriber', array(&$this, 'mainwp_user_role_to_subscriber'));
        $this->addAction('mainwp_user_update_password', array(&$this, 'mainwp_user_update_password'));

        //Page: API
        add_action('wp_ajax_mainwp_api_test', array(&$this, 'mainwp_api_test')); //ok
        add_action('wp_ajax_mainwp_api_refresh', array(&$this, 'mainwp_api_refresh')); //ok

        //Page: Posts
        add_action('wp_ajax_mainwp_posts_search', array(&$this, 'mainwp_posts_search')); //ok
        add_action('wp_ajax_mainwp_get_categories', array(&$this, 'mainwp_get_categories')); //ok
        add_action('wp_ajax_mainwp_posts_get_terms', array(&$this, 'mainwp_posts_get_terms')); //ok
        add_action('wp_ajax_mainwp_posts_test_post', array(&$this, 'mainwp_posts_test_post')); //ok

        //Page: Pages
        add_action('wp_ajax_mainwp_pages_search', array(&$this, 'mainwp_pages_search')); //ok

        //Page: User
        add_action('wp_ajax_mainwp_users_search', array(&$this, 'mainwp_users_search')); //ok
        add_action('wp_ajax_mainwp_users_query', array(&$this, 'mainwp_users_query')); //ok

        //Page: Themes
        add_action('wp_ajax_mainwp_themes_search', array(&$this, 'mainwp_themes_search')); //ok
        add_action('wp_ajax_mainwp_themes_search_all', array(&$this, 'mainwp_themes_search_all')); //ok
        $this->addAction('mainwp_theme_activate', array(&$this, 'mainwp_theme_activate'));
        $this->addAction('mainwp_theme_delete', array(&$this, 'mainwp_theme_delete'));
        $this->addAction('mainwp_trusted_theme_notes_save', array(&$this, 'mainwp_trusted_theme_notes_save'));
        $this->addAction('mainwp_theme_ignore_updates', array(&$this, 'mainwp_theme_ignore_updates'));

        //Page: Plugins
        add_action('wp_ajax_mainwp_plugins_search', array(&$this, 'mainwp_plugins_search')); //ok
        add_action('wp_ajax_mainwp_plugins_search_all_active', array(&$this, 'mainwp_plugins_search_all_active')); //ok
        $this->addAction('mainwp_plugin_activate', array(&$this, 'mainwp_plugin_activate'));
        $this->addAction('mainwp_plugin_deactivate', array(&$this, 'mainwp_plugin_deactivate'));
        $this->addAction('mainwp_plugin_delete', array(&$this, 'mainwp_plugin_delete'));
        $this->addAction('mainwp_plugin_ignore_updates', array(&$this, 'mainwp_plugin_ignore_updates'));
        $this->addAction('mainwp_trusted_plugin_notes_save', array(&$this, 'mainwp_trusted_plugin_notes_save'));

        //Plugins
        $this->addAction('mainwp_ignorepluginthemeconflict', array(&$this, 'mainwp_ignorepluginthemeconflict'));
        $this->addAction('mainwp_unignorepluginthemeconflicts', array(&$this, 'mainwp_unignorepluginthemeconflicts'));

		//Widget: Plugins
        $this->addAction('mainwp_widget_plugin_activate', array(&$this, 'mainwp_widget_plugin_activate'));
        $this->addAction('mainwp_widget_plugin_deactivate', array(&$this, 'mainwp_widget_plugin_deactivate'));
        $this->addAction('mainwp_widget_plugin_delete', array(&$this, 'mainwp_widget_plugin_delete'));        
		
		//Widget: Themes
        $this->addAction('mainwp_widget_theme_activate', array(&$this, 'mainwp_widget_theme_activate'));        
        $this->addAction('mainwp_widget_theme_delete', array(&$this, 'mainwp_widget_theme_delete'));        
				
        //ServerInformation
        add_action('wp_ajax_mainwp_serverInformation', array(&$this, 'mainwp_serverInformation')); //ok

        $this->addAction('mainwp_extension_change_view', array(&$this, 'mainwp_extension_change_view'));
        $this->addAction('mainwp_events_notice_hide', array(&$this, 'mainwp_events_notice_hide'));
        MainWPExtensions::initAjaxHandlers();
    }

    /**
     * Page: Users
     */
    function mainwp_users_search()
    {
        MainWPUser::renderTable($_POST['role'], (isset($_POST['groups']) ? $_POST['groups'] : ''), (isset($_POST['sites']) ? $_POST['sites'] : ''));
        die();
    }

    function mainwp_users_query()
    {
        MainWPUser::renderTable(null, (isset($_POST['groups']) ? $_POST['groups'] : ''), (isset($_POST['sites']) ? $_POST['sites'] : ''), $_POST['search']);
        die();
    }


    /**
     * Page: Themes
     */
    function mainwp_themes_search()
    {
        MainWPThemes::renderTable($_POST['keyword'], $_POST['status'], (isset($_POST['groups']) ? $_POST['groups'] : ''), (isset($_POST['sites']) ? $_POST['sites'] : ''));
        die();
    }
    function mainwp_theme_activate()
    {
        $this->secure_request('mainwp_theme_activate');

        MainWPThemes::activateTheme();
        die();
    }
    function mainwp_theme_delete()
    {
        $this->secure_request('mainwp_theme_delete');

        MainWPThemes::deleteThemes();
        die();
    }
    function mainwp_theme_ignore_updates()
    {
        $this->secure_request('mainwp_theme_ignore_updates');

        MainWPThemes::ignoreUpdates();
        die();
    }
    function mainwp_themes_search_all()
    {
       MainWPThemes::renderAllThemesTable();
       die();
    }
    function mainwp_trusted_theme_notes_save()
    {
        $this->secure_request('mainwp_trusted_theme_notes_save');

        MainWPThemes::saveTrustedThemeNote();
        die(json_encode(array('result' => 'SUCCESS')));
    }
    /**
     * Page: Plugins
     */
    function mainwp_plugins_search()
    {
        MainWPPlugins::renderTable($_POST['keyword'], $_POST['status'], (isset($_POST['groups']) ? $_POST['groups'] : ''), (isset($_POST['sites']) ? $_POST['sites'] : ''));
        die();
    }
    function mainwp_plugins_search_all_active()
    {
        MainWPPlugins::renderAllActiveTable();
        die();
    }
    function mainwp_plugin_activate()
    {
        $this->secure_request('mainwp_plugin_activate');

        MainWPPlugins::activatePlugins();
        die();
    }
    function mainwp_plugin_deactivate()
    {
        $this->secure_request('mainwp_plugin_deactivate');

        MainWPPlugins::deactivatePlugins();
        die();
    }
    function mainwp_plugin_delete()
    {
        $this->secure_request('mainwp_plugin_delete');

        MainWPPlugins::deletePlugins();
        die();
    }
    
    function mainwp_plugin_ignore_updates()
    {
        $this->secure_request('mainwp_plugin_ignore_updates');
        MainWPPlugins::ignoreUpdates();
        die();
    }
    
    function mainwp_trusted_plugin_notes_save()
    {
        $this->secure_request('mainwp_trusted_plugin_notes_save');

        MainWPPlugins::saveTrustedPluginNote();
        die(json_encode(array('result' => 'SUCCESS')));
    }
	
	/**
	* Widget: Plugins
	*/
	function mainwp_widget_plugin_activate() {
		$this->secure_request('mainwp_widget_plugin_activate');
		MainWPWidgetPlugins::activatePlugin();
	}
	function mainwp_widget_plugin_deactivate() {
		$this->secure_request('mainwp_widget_plugin_deactivate');
		MainWPWidgetPlugins::deactivatePlugin();
	}
	function mainwp_widget_plugin_delete() {
		$this->secure_request('mainwp_widget_plugin_delete');
		MainWPWidgetPlugins::deletePlugin();		
	}
	
	/**
	* Widget: Themes
	*/
	function mainwp_widget_theme_activate() {
		$this->secure_request('mainwp_widget_theme_activate');
		MainWPWidgetThemes::activateTheme();
	}	
	function mainwp_widget_theme_delete() {
		$this->secure_request('mainwp_widget_theme_delete');
		MainWPWidgetThemes::deleteTheme();		
	}
	
	
    /**
     * Page: Posts
     */
    function mainwp_posts_search()
    {
        MainWPPost::renderTable($_POST['keyword'], $_POST['dtsstart'], $_POST['dtsstop'], $_POST['status'], (isset($_POST['groups']) ? $_POST['groups'] : ''), (isset($_POST['sites']) ? $_POST['sites'] : ''), $_POST['postId'], $_POST['userId']);
        die();
    }
    function mainwp_posts_get_terms()
    {
        MainWPPost::getTerms($_POST['selected_site'], $_POST['prefix'], $_POST['what'], $_POST['generate_type']);
        die();
    }
    function mainwp_posts_test_post()
    {
        MainWPPost::testPost();
        die();
    }
    function mainwp_get_categories()
    {
        MainWPPost::getCategories();
        die();
    }

    /**
     * Page: Pages
     */
    function mainwp_pages_search()
    {
        MainWPPage::renderTable($_POST['keyword'], $_POST['dtsstart'], $_POST['dtsstop'], $_POST['status'], (isset($_POST['groups']) ? $_POST['groups'] : ''), (isset($_POST['sites']) ? $_POST['sites'] : ''));
        die();
    }

    /**
     * Page: API
     */
    function mainwp_api_test()
    {
        die(json_encode(MainWPAPISettings::testAndSaveLogin($_POST['username'], $_POST['password'])));
    }

    function mainwp_api_refresh()
    {
        die(json_encode(MainWPAPISettings::refresh()));
    }

    /**
     * Page: Users
     */
    function mainwp_user_role_to_administrator()
    {
        $this->secure_request('mainwp_user_role_to_administrator');

        MainWPUser::changeRole('administrator');
    }
    function mainwp_user_role_to_editor()
    {
        $this->secure_request('mainwp_user_role_to_editor');

        MainWPUser::changeRole('editor');
    }
    function mainwp_user_role_to_author()
    {
        $this->secure_request('mainwp_user_role_to_author');

        MainWPUser::changeRole('author');
    }
    function mainwp_user_role_to_contributor()
    {
        $this->secure_request('mainwp_user_role_to_contributor');

        MainWPUser::changeRole('contributor');
    }
    function mainwp_user_role_to_subscriber()
    {
        $this->secure_request('mainwp_user_role_to_subscriber');

        MainWPUser::changeRole('subscriber');
    }
    function mainwp_user_delete()
    {
        $this->secure_request('mainwp_user_delete');

        MainWPUser::delete();
    }
    function mainwp_user_update_password()
    {
        $this->secure_request('mainwp_user_update_password');

        MainWPUser::updatePassword();
    }
    /**
     * Page: Recent Posts
     */
    function mainwp_post_unpublish()
    {
        $this->secure_request('mainwp_post_unpublish');
        
        MainWPRecentPosts::unpublish();
    }
    function mainwp_post_publish()
    {
        $this->secure_request('mainwp_post_publish');
    
        MainWPRecentPosts::publish();
    }
    function mainwp_post_approve()
    {
        $this->secure_request('mainwp_post_approve');
    
        MainWPRecentPosts::approve();
    }
    function mainwp_post_trash()
    {
        $this->secure_request('mainwp_post_trash');
    
        MainWPRecentPosts::trash();
    }
    function mainwp_post_delete()
    {
        $this->secure_request('mainwp_post_delete');
    
        MainWPRecentPosts::delete();
    }
    function mainwp_post_restore()
    {
        $this->secure_request('mainwp_post_restore');
    
        MainWPRecentPosts::restore();
    }

    /**
     * Page: Recent Pages
     */
    function mainwp_page_unpublish()
    {
        $this->secure_request('mainwp_page_unpublish');

        MainWPPage::unpublish();
    }
    function mainwp_page_publish()
    {
        $this->secure_request('mainwp_page_publish');

        MainWPPage::publish();
    }
    function mainwp_page_trash()
    {
        $this->secure_request('mainwp_page_trash');

        MainWPPage::trash();
    }
    function mainwp_page_delete()
    {
        $this->secure_request('mainwp_page_delete');

        MainWPPage::delete();
    }
    function mainwp_page_restore()
    {
        $this->secure_request('mainwp_page_restore');

        MainWPPage::restore();
    }

    /**
     * Page: OfflineChecks
     */
    function mainwp_offline_check_save()
    {
        die(MainWPOfflineChecks::updateWebsite());
    }

    function mainwp_offline_check_save_bulk()
    {
        die(MainWPOfflineChecks::updateWebsites());
    }

    function mainwp_offline_check_check()
    {
        die(json_encode(MainWPOfflineChecks::checkWebsite()));
    }

    /**
     * Page: ManageTips
     */
    function mainwp_managetips_update()
    {
        MainWPManageTips::updateTipSettings();
        die();
    }

    /**
     * Page: SecurityIssues
     */
    function mainwp_securityIssues_request()
    {
        try
        {
            die(json_encode(array('result' => MainWPSecurityIssues::fetchSecurityIssues())));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }

    function mainwp_securityIssues_fix()
    {
        try
        {
            die(json_encode(array('result' => MainWPSecurityIssues::fixSecurityIssue())));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }
    function mainwp_securityIssues_unfix()
    {
        try
        {
            die(json_encode(array('result' => MainWPSecurityIssues::unfixSecurityIssue())));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }

    /**
     * Page: CloneSite
     */
//    function mainwp_clonesite_check_backups()
//    {
//        die(MainWPCloneSite::render_check_backups());
//    }

//    function mainwp_clone()
//    {
//        die(MainWPCloneSite::render_clone());
//    }

//    function mainwp_clone_test_ftp()
//    {
//        die(MainWPCloneSite::testFTP());
//    }

    /*
    * Page: Backup
    */
    function mainwp_backup_run_site()
    {
        $this->secure_request('mainwp_backup_run_site');

        try
        {
            if (!isset($_POST['site_id']) || !MainWPUtility::ctype_digit($_POST['site_id']))
            {
                throw new MainWPException('Invalid request');
            }

            die(json_encode(array('result' => MainWPManageSites::backup($_POST['site_id'], 'full', '', '', 0, 0, 0, 0))));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }

    function mainwp_backup()
    {
        $this->secure_request('mainwp_backup');

        try
        {
            if (!isset($_POST['site_id']) || !MainWPUtility::ctype_digit($_POST['site_id']))
            {
                throw new MainWPException('Invalid request');
            }

            $excludedFolder = trim($_POST['exclude'], "\n");
            $excludedFolder = explode("\n", $excludedFolder);
            $excludedFolder = array_map(array('MainWPUtility', 'trimSlashes'), $excludedFolder);
            $excludedFolder = implode(",", $excludedFolder);

            die(json_encode(array('result' => MainWPManageSites::backup($_POST['site_id'], $_POST['type'], (isset($_POST['subfolder']) ? $_POST['subfolder'] : ''), $excludedFolder, $_POST['excludebackup'], $_POST['excludecache'], $_POST['excludenonwp'], $_POST['excludezip'], $_POST['filename'], isset($_POST['fileNameUID']) ? $_POST['fileNameUID'] : '', $_POST['archiveFormat'], ($_POST['maximumFileDescriptorsOverride'] == 1), ($_POST['maximumFileDescriptorsAuto'] == 1), $_POST['maximumFileDescriptors'], $_POST['loadFilesBeforeZip']))));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }

    function mainwp_backup_download_file()
    {
        $this->secure_request('mainwp_backup_download_file');

        try
        {
            if (!isset($_POST['site_id']) || !MainWPUtility::ctype_digit($_POST['site_id']))
            {
                throw new MainWPException('Invalid request');
            }

            die(json_encode(array('result' => MainWPManageSites::backupDownloadFile($_POST['site_id'], $_POST['type'], $_POST['url'], $_POST['local']))));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }

    function mainwp_backup_delete_file()
    {
        $this->secure_request('mainwp_backup_delete_file');

        try
        {
            if (!isset($_POST['site_id']) || !MainWPUtility::ctype_digit($_POST['site_id']))
            {
                throw new MainWPException('Invalid request');
            }

            die(json_encode(array('result' => MainWPManageSites::backupDeleteFile($_POST['site_id'], $_POST['file']))));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }

    function mainwp_createbackup_getfilesize()
    {
        $this->secure_request('mainwp_createbackup_getfilesize');

        try
        {
            if (!isset($_POST['siteId'])) throw new Exception(__('No site given','mainwp-child'));
            $siteId = $_POST['siteId'];
            $fileName = $_POST['fileName'];
            $fileNameUID = $_POST['fileNameUID'];
            $type = $_POST['type'];

            $website = MainWPDB::Instance()->getWebsiteById($siteId);
            if (!$website)  throw new Exception(__('No site given','mainwp-child'));

            MainWPUtility::endSession();
            //Send request to the childsite!
            $result = MainWPUtility::fetchUrlAuthed($website, 'createBackupPoll', array('fileName' => $fileName, 'fileNameUID' => $fileNameUID, 'type' => $type));

            if (!isset($result['size'])) throw new Exception(__('Invalid response','mainwp-child'));

            if (MainWPUtility::ctype_digit($result['size'])) $output = array('size' => $result['size']);
            else $output = array();
        }
        catch (Exception $e)
        {
            $output = array('error' => $e->getMessage());
        }

        die(json_encode($output));
    }

    function mainwp_backup_getfilesize()
    {
        $this->secure_request('mainwp_backup_getfilesize');

        try
        {
            die(json_encode(array('result' => MainWPManageSites::backupGetFilesize($_POST['local']))));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }


    function mainwp_backup_upload_getprogress()
    {
        $this->secure_request('mainwp_backup_upload_getprogress');

        try
        {
            $array = get_option('mainwp_upload_progress');

            die(json_encode(array('result' => (!is_array($array) || !isset($array[$_POST['unique']]) ? 0 : $array[$_POST['unique']]))));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }

    /*
    * Page: BulkAddUser
    */
    function mainwp_bulkadduser()
    {
        if (!$this->check_security('mainwp_bulkadduser')) die('ERROR ' . json_encode(array('error' => 'Invalid request')));
        MainWPUser::doPost();
        die();
    }

    function mainwp_bulkuploadadduser()
    {
        MainWPUser::renderBulkUpload();
       die();
    }

    function mainwp_importuser()
    {
        $this->secure_request('mainwp_importuser');

        MainWPUser::doImport();
    }

    /*
    * Page: InstallPlugins/Themes
    */

    function mainwp_installbulksearch()
    {
        if ($_REQUEST['page'] == 'PluginsInstall')
        {
            MainWPPlugins::performSearch();
        }
        else
        {
            MainWPThemes::performSearch();
        }
    }

    function mainwp_installbulknavsearch()
    {
        if ($_REQUEST['page'] == 'PluginsInstall')
        {
            MainWPInstallBulk::renderSearch('Plugins');
        }
        else
        {
            MainWPInstallBulk::renderSearch('Themes');
        }
        die();
    }

    function mainwp_installbulknavupload()
    {
        if ($_REQUEST['page'] == 'PluginsInstall')
        {
            MainWPInstallBulk::renderUpload('Plugins');
        }
        else
        {
            MainWPInstallBulk::renderUpload('Themes');
        }
        die();
    }

    function mainwp_preparebulkinstallplugintheme()
    {
        MainWPInstallBulk::prepareInstall();
    }

    function mainwp_installbulkinstallplugintheme()
    {
        $this->secure_request('mainwp_installbulkinstallplugintheme');

        MainWPInstallBulk::performInstall();
    }

    function mainwp_preparebulkuploadplugintheme()
    {
        MainWPInstallBulk::prepareUpload();
    }

    function mainwp_installbulkuploadplugintheme()
    {
        $this->secure_request('mainwp_installbulkuploadplugintheme');

        MainWPInstallBulk::performUpload();
    }

    function mainwp_cleanbulkuploadplugintheme()
    {
        $this->secure_request('mainwp_cleanbulkuploadplugintheme');

        MainWPInstallBulk::cleanUpload();
    }

    /*
     * Page: ManageGroups
     */
    function mainwp_group_rename()
    {
        $this->secure_request('mainwp_group_rename');

        MainWPManageGroups::renameGroup();
    }

    function mainwp_group_delete()
    {
        MainWPManageGroups::deleteGroup();
    }

    function mainwp_group_add()
    {
        $this->secure_request('mainwp_group_add');

        MainWPManageGroups::addGroup();
    }

    function mainwp_group_getsites()
    {
        die(MainWPManageGroups::getSites());
    }

    function mainwp_group_updategroup()
    {
        $this->secure_request('mainwp_group_updategroup');

        MainWPManageGroups::updateGroup();
    }

    /*
     * Page: ManageBackups
     */
    //Add task to the database
    function mainwp_addbackup()
    {
        $this->secure_request('mainwp_addbackup');

        MainWPManageBackups::addBackup();
    }

    //Update task
    function mainwp_updatebackup()
    {
        $this->secure_request('mainwp_updatebackup');

        MainWPManageBackups::updateBackup();
    }

    //Remove a task from MainWP
    function mainwp_removebackup()
    {
        $this->secure_request('mainwp_removebackup');

        MainWPManageBackups::removeBackup();
    }

    function mainwp_resumebackup()
    {
        $this->secure_request('mainwp_resumebackup');

        MainWPManageBackups::resumeBackup();
    }

    function mainwp_pausebackup()
    {
        $this->secure_request('mainwp_pausebackup');

        MainWPManageBackups::pauseBackup();
    }

    function mainwp_site_dirs()
    {
        MainWPManageBackups::getSiteDirectories();
        exit();
    }

    function mainwp_backuptask_get_sites()
    {
        $taskID = $_POST['task_id'];

        die(json_encode(array('result' => MainWPManageBackups::getBackupTaskSites($taskID))));
    }

    function mainwp_backuptask_run_site()
    {
        try
        {
            $this->secure_request('mainwp_backuptask_run_site');

            if (!isset($_POST['site_id']) || !MainWPUtility::ctype_digit($_POST['site_id']) || !isset($_POST['task_id']) || !MainWPUtility::ctype_digit($_POST['task_id']))
            {
                throw new MainWPException('Invalid request');
            }

            die(json_encode(array('result' => MainWPManageBackups::backup($_POST['task_id'], $_POST['site_id'], $_POST['fileNameUID']))));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }

    function mainwp_backup_upload_file()
    {
        try
        {
            $this->secure_request('mainwp_backup_upload_file');

            do_action('mainwp_remote_backup_extension_backup_upload_file');

            throw new MainWPException('Remote Backup Extension not loaded');
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }

    /*
    * Page: ManageSites
    */

    //Check if WP can be added
    function mainwp_checkwp()
    {
        if ($this->check_security('mainwp_checkwp', 'security'))
        {
            MainWPManageSites::checkSite();
        }
        else
        {
            die(json_encode(array('response' => 'ERROR Invalid request')));
        }
    }

    //Add WP to the database
    function mainwp_addwp()
    {
        if ($this->check_security('mainwp_addwp', 'security'))
        {
            MainWPManageSites::addSite();
        }
        else
        {
            die(json_encode(array('response' => 'ERROR Invalid request')));
        }
    }

    function mainwp_testwp()
    {
        $this->secure_request('mainwp_testwp');

        $url = null;
        $name = null;
        $verifyCertificate = 1;
        if (isset($_POST['url']))
        {
            $url = $_POST['url'];
            $verifyCertificate = $_POST['test_verify_cert'];
        }
        else if (isset($_POST['siteid']))
        {
            $website = MainWPDB::Instance()->getWebsiteById($_POST['siteid']);
            if ($website)
            {
                $url = $website->url;
                $name = $website->name;
                $verifyCertificate = $website->verify_certificate;
            }
        }

        $rslt = MainWPUtility::tryVisit($url, $verifyCertificate);

        if (isset($rslt['error']) && ($rslt['error'] != '') && (substr($url, -9) != 'wp-admin/'))
        {
            if (substr($url, -1) != '/') { $url .= '/'; }
            $url .= 'wp-admin/';
            $newrslt = MainWPUtility::tryVisit($url, $verifyCertificate);
            if (isset($newrslt['error']) && ($rslt['error'] != '')) $rslt = $newrslt;
        }

        if ($name != null) $rslt['sitename'] = $name;

        die(json_encode($rslt));
    }

    //Remove a website from MainWP
    function mainwp_removesite()
    {
        $this->secure_request('mainwp_removesite');

        MainWPManageSites::removeSite();
    }

    //Save note
    function mainwp_notes_save()
    {
        $this->secure_request('mainwp_notes_save');

        MainWPManageSites::saveNote();
    }

    function mainwp_reconnectwp()
    {
        MainWPManageSites::reconnectSite();
    }

    /*
     * Widget: RightNow
     */

    function mainwp_syncsites()
    {
        $this->secure_request('mainwp_syncsites');

        MainWPRightNow::dismissSyncErrors(false);
        MainWPRightNow::syncSite();
    }

    //Upgrade a specific WP
    function mainwp_upgradewp()
    {
        $this->secure_request('mainwp_upgradewp');

        try
        {
            $id = null;
            if (isset($_POST['id']))
            {
                $id = $_POST['id'];
            }
            die(json_encode(array('result' => MainWPRightNow::upgradeSite($id))));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }

    function mainwp_upgradeplugintheme()
    {
        $this->secure_request('mainwp_upgradeplugintheme');

        try
        {
            $websiteId = null;
            if (isset($_POST['websiteId']))
            {
                $websiteId = $_POST['websiteId'];
            }
            die(json_encode(array('result' => MainWPRightNow::upgradePluginTheme($websiteId, $_POST['type'], $_POST['slug']))));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
    }

    function mainwp_ignoreplugintheme()
    {
        if (!isset($_POST['id']))
        {
            die(json_encode(array('error' => 'Invalid request')));
        }
        die(json_encode(array('result' => MainWPRightNow::ignorePluginTheme($_POST['type'], $_POST['slug'], $_POST['name'], $_POST['id']))));
    }

    function mainwp_ignorepluginthemeconflict()
    {
        $this->secure_request('mainwp_ignorepluginthemeconflict');

        if (!isset($_POST['siteid']))
        {
            die(json_encode(array('error' => 'Invalid request')));
        }
        die(json_encode(array('result' => MainWPPlugins::ignorePluginThemeConflict($_POST['type'], $_POST['name'], $_POST['siteid']))));
    }
    function mainwp_unignorepluginthemeconflicts()
    {
        $this->secure_request('mainwp_unignorepluginthemeconflicts');

        if (!isset($_POST['siteid']))
        {
            die(json_encode(array('error' => 'Invalid request')));
        }
        die(json_encode(array('result' => MainWPPlugins::unIgnorePluginThemeConflict($_POST['type'], $_POST['name'], $_POST['siteid']))));
    }

    function mainwp_unignoreplugintheme()
    {
        if (!isset($_POST['id']))
        {
            die(json_encode(array('error' => 'Invalid request')));
        }
        die(json_encode(array('result' => MainWPRightNow::unIgnorePluginTheme($_POST['type'], $_POST['slug'], $_POST['id']))));
    }

    function mainwp_ignorepluginsthemes()
    {
        if (!isset($_POST['slug']))
        {
            die(json_encode(array('error' => 'Invalid request')));
        }
        die(json_encode(array('result' => MainWPRightNow::ignorePluginsThemes($_POST['type'], $_POST['slug'], $_POST['name']))));
    }

    function mainwp_unignorepluginsthemes()
    {
        if (!isset($_POST['slug']))
        {
            die(json_encode(array('error' => 'Invalid request')));
        }
        die(json_encode(array('result' => MainWPRightNow::unIgnorePluginsThemes($_POST['type'], $_POST['slug']))));
    }

    function mainwp_trust_plugin()
    {
        $this->secure_request('mainwp_trust_plugin');

        MainWPPlugins::trustPost();
        die(json_encode(array('result' => true)));
    }

    function mainwp_trust_theme()
    {
        $this->secure_request('mainwp_trust_theme');

        MainWPThemes::trustPost();
        die(json_encode(array('result' => true)));
    }

    function mainwp_checkbackups()
    {
        $this->secure_request('mainwp_checkbackups');

        try
        {
            die(json_encode(array('result' => MainWPRightNow::checkBackups())));
        }
        catch (Exception $e)
        {
            die(json_encode(array('error' => $e->getMessage())));
        }
    }

    function mainwp_syncerrors_dismiss()
    {
        $this->secure_request('mainwp_syncerrors_dismiss');

        try
        {
            die(json_encode(array('result' => MainWPRightNow::dismissSyncErrors())));
        }
        catch (Exception $e)
        {
            die(json_encode(array('error' => $e->getMessage())));
        }
    }

    function mainwp_serverInformation()
    {
        MainWPServerInformation::fetchChildServerInformation($_POST['siteId']);
        die();
    }

    function mainwp_extension_change_view()
    {
        $this->secure_request('mainwp_extension_change_view');

        try
        {
            die(json_encode(MainWPExtensionsWidget::changeDefaultView()));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage(), 'extra' => $e->getMessageExtra()))));
        }
        catch (Exception $e)
        {
            die(json_encode(array('error' => array('message' => $e->getMessage()))));
        }
    }
    
    function mainwp_events_notice_hide() {
        update_option("mainwp_first_site_events_notice", "");
        die('ok');
    }
    
    function secure_request($action, $query_arg = 'security')
    {
        if (!$this->check_security($action, $query_arg)) die(json_encode(array('error' => 'Invalid request')));
    }

    function check_security($action = -1, $query_arg = 'security')
    {
        if ($action == -1) return false;

        $adminurl = strtolower(admin_url());
        $referer = strtolower(wp_get_referer());
        $result = isset($_REQUEST[$query_arg]) ? wp_verify_nonce($_REQUEST[$query_arg], $action) : false;
        if (!$result && !(-1 == $action && strpos($referer, $adminurl) === 0))
        {
            return false;
        }

        return true;
    }

    function addAction($action, $callback)
    {
        add_action('wp_ajax_' . $action, $callback);
        $this->addSecurityNonce($action);
    }

    function addSecurityNonce($action)
    {
        if (!is_array($this->security_nonces)) $this->security_nonces = array();

        if (!function_exists('wp_create_nonce')) include_once(ABSPATH . WPINC . '/pluggable.php');
        $this->security_nonces[$action] = wp_create_nonce($action);
    }

    function getSecurityNonces()
    {
        return $this->security_nonces;
    }
}

?>
