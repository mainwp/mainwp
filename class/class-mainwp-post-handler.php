<?php
/**
 * MainWP Post Handler
 */
class MainWP_Post_Handler {

	protected $security_nonces;
	private static $instance = null;

	function __construct() {
	}

	static function Instance() {
		if ( null == self::$instance ) {
			self::$instance = new MainWP_Post_Handler();
		}
		return self::$instance;
	}

	function init() {
		// Page: ManageBackups
		$this->addAction( 'mainwp_addbackup', array( &$this, 'mainwp_addbackup' ) );
		if ( mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
			$this->addAction( 'mainwp_updatebackup', array( &$this, 'mainwp_updatebackup' ) );
		}
		if ( mainwp_current_user_can( 'dashboard', 'delete_backup_tasks' ) ) {
			$this->addAction( 'mainwp_removebackup', array( &$this, 'mainwp_removebackup' ) );
		}
		$this->addAction( 'mainwp_pausebackup', array( &$this, 'mainwp_pausebackup' ) );
		$this->addAction( 'mainwp_resumebackup', array( &$this, 'mainwp_resumebackup' ) );

		add_action( 'wp_ajax_mainwp_backuptask_get_sites', array( &$this, 'mainwp_backuptask_get_sites' ) ); // ok

		if ( mainwp_current_user_can( 'dashboard', 'run_backup_tasks' ) ) {
			$this->addAction( 'mainwp_backuptask_run_site', array( &$this, 'mainwp_backuptask_run_site' ) );
		}
		$this->addAction( 'mainwp_backup_upload_file', array( &$this, 'mainwp_backup_upload_file' ) );

		// Page: ManageSites
		$this->addAction( 'mainwp_checkwp', array( &$this, 'mainwp_checkwp' ) );
		$this->addAction( 'mainwp_addwp', array( &$this, 'mainwp_addwp' ) );
		$this->addAction( 'mainwp_get_site_icon', array( &$this, 'get_site_icon' ) );
		$this->addAction( 'mainwp_ext_prepareinstallplugintheme', array( &$this, 'mainwp_ext_prepareinstallplugintheme' ) );
		$this->addAction( 'mainwp_ext_performinstallplugintheme', array( &$this, 'mainwp_ext_performinstallplugintheme' ) );
		$this->addAction( 'mainwp_ext_applypluginsettings', array( &$this, 'mainwp_ext_applypluginsettings' ) );

		if ( mainwp_current_user_can( 'dashboard', 'test_connection' ) ) {
			$this->addAction( 'mainwp_testwp', array( &$this, 'mainwp_testwp' ) );
		}

		$this->addAction( 'mainwp_removesite', array( &$this, 'mainwp_removesite' ) );
		$this->addAction( 'mainwp_notes_save', array( &$this, 'mainwp_notes_save' ) );
		add_action( 'wp_ajax_mainwp_reconnectwp', array( &$this, 'mainwp_reconnectwp' ) ); // ok
		$this->addAction( 'mainwp_updatechildsite_value', array( &$this, 'mainwp_updatechildsite_value' ) ); // ok
		// Page: ManageGroups
		$this->addAction( 'mainwp_group_rename', array( &$this, 'mainwp_group_rename' ) );
		$this->addAction( 'mainwp_group_delete', array( &$this, 'mainwp_group_delete' ) ); // ok
		$this->addAction( 'mainwp_group_add', array( &$this, 'mainwp_group_add' ) );
		add_action( 'wp_ajax_mainwp_group_getsites', array( &$this, 'mainwp_group_getsites' ) ); // ok
		$this->addAction( 'mainwp_group_updategroup', array( &$this, 'mainwp_group_updategroup' ) );

		// Page: InstallPlugins/Themes
		add_action( 'wp_ajax_mainwp_preparebulkinstallplugintheme', array(
			&$this,
			'mainwp_preparebulkinstallplugintheme',
		) ); // ok
		$this->addAction( 'mainwp_installbulkinstallplugintheme', array(
			&$this,
			'mainwp_installbulkinstallplugintheme',
		) );
		add_action( 'wp_ajax_mainwp_preparebulkuploadplugintheme', array(
			&$this,
			'mainwp_preparebulkuploadplugintheme',
		) ); // ok
		$this->addAction( 'mainwp_installbulkuploadplugintheme', array(
			&$this,
			'mainwp_installbulkuploadplugintheme',
		) );
		$this->addAction( 'mainwp_cleanbulkuploadplugintheme', array( &$this, 'mainwp_cleanbulkuploadplugintheme' ) );

		// Page: BulkAddUser
		$this->addAction( 'mainwp_bulkadduser', array( &$this, 'mainwp_bulkadduser' ) );
		add_action( 'wp_ajax_mainwp_bulkuploadadduser', array( &$this, 'mainwp_bulkuploadadduser' ) ); // ok - to check
		$this->addAction( 'mainwp_importuser', array( &$this, 'mainwp_importuser' ) );

		// Widget: RightNow
		$this->addAction( 'mainwp_syncsites', array( &$this, 'mainwp_syncsites' ) );
		$this->addAction( 'mainwp_upgradewp', array( &$this, 'mainwp_upgradewp' ) );
		$this->addAction( 'mainwp_upgradeplugintheme', array( &$this, 'mainwp_upgradeplugintheme' ) );
		$this->addAction( 'mainwp_ignoreplugintheme', array( &$this, 'mainwp_ignoreplugintheme' ) ); // ok
		$this->addAction( 'mainwp_unignoreplugintheme', array( &$this, 'mainwp_unignoreplugintheme' ) ); // ok
		$this->addAction( 'mainwp_ignorepluginsthemes', array( &$this, 'mainwp_ignorepluginsthemes' ) ); // ok
		$this->addAction( 'mainwp_unignorepluginsthemes', array(
			&$this,
			'mainwp_unignorepluginsthemes',
		) ); // ok
		$this->addAction( 'mainwp_unignoreabandonedplugintheme', array(
			&$this,
			'mainwp_unignoreabandonedplugintheme',
		) ); // ok
		$this->addAction( 'mainwp_unignoreabandonedpluginsthemes', array(
			&$this,
			'mainwp_unignoreabandonedpluginsthemes',
		) ); // ok
		$this->addAction( 'mainwp_dismissoutdateplugintheme', array(
			&$this,
			'mainwp_dismissoutdateplugintheme',
		) ); // ok
		$this->addAction( 'mainwp_dismissoutdatepluginsthemes', array(
			&$this,
			'mainwp_dismissoutdatepluginsthemes',
		) ); // ok
		$this->addAction( 'mainwp_trust_plugin', array( &$this, 'mainwp_trust_plugin' ) );
		$this->addAction( 'mainwp_trust_theme', array( &$this, 'mainwp_trust_theme' ) );
		$this->addAction( 'mainwp_checkbackups', array( &$this, 'mainwp_checkbackups' ) );
		$this->addAction( 'mainwp_syncerrors_dismiss', array( &$this, 'mainwp_syncerrors_dismiss' ) );
		// Page: backup
		if ( mainwp_current_user_can( 'dashboard', 'run_backup_tasks' ) ) {
			$this->addAction( 'mainwp_backup_run_site', array( &$this, 'mainwp_backup_run_site' ) );
		}
		if ( mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
			$this->addAction( 'mainwp_backup', array( &$this, 'mainwp_backup' ) );
		}
		$this->addAction( 'mainwp_backup_checkpid', array( &$this, 'mainwp_backup_checkpid' ) );
		$this->addAction( 'mainwp_createbackup_getfilesize', array( &$this, 'mainwp_createbackup_getfilesize' ) );
		$this->addAction( 'mainwp_backup_download_file', array( &$this, 'mainwp_backup_download_file' ) );
		$this->addAction( 'mainwp_backup_delete_file', array( &$this, 'mainwp_backup_delete_file' ) );
		$this->addAction( 'mainwp_backup_getfilesize', array( &$this, 'mainwp_backup_getfilesize' ) );
		$this->addAction( 'mainwp_backup_upload_getprogress', array( &$this, 'mainwp_backup_upload_getprogress' ) );
		$this->addAction( 'mainwp_backup_upload_checkstatus', array( &$this, 'mainwp_backup_upload_checkstatus' ) );

		// Page: CloneSite
		// add_action('wp_ajax_mainwp_clonesite_check_backups', array(&$this, 'mainwp_clonesite_check_backups'));
		// add_action('wp_ajax_mainwp_clone', array(&$this, 'mainwp_clone'));
		// add_action('wp_ajax_mainwp_clone_test_ftp', array(&$this, 'mainwp_clone_test_ftp'));

		if ( mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) {
			// Page: SecurityIssues
			$this->addAction( 'mainwp_securityIssues_request', array( &$this, 'mainwp_securityIssues_request' ) ); // ok
			$this->addAction( 'mainwp_securityIssues_fix', array( &$this, 'mainwp_securityIssues_fix' ) ); // ok
			$this->addAction( 'mainwp_securityIssues_unfix', array( &$this, 'mainwp_securityIssues_unfix' ) ); // ok
		}
		
		$this->addAction( 'mainwp_notice_status_update', array( &$this, 'mainwp_notice_status_update' ) );
		$this->addAction( 'mainwp_dismiss_twit', array( &$this, 'mainwp_dismiss_twit' ) );
		$this->addAction( 'mainwp_dismiss_activate_notice', array( &$this, 'dismiss_activate_notice' ) );
		$this->addAction( 'mainwp_status_saving', array( &$this, 'mainwp_status_saving' ) );
		$this->addAction( 'mainwp_leftmenu_filter_group', array( &$this, 'mainwp_leftmenu_filter_group' ) );
		$this->addAction( 'mainwp_widgets_order', array( &$this, 'ajax_widgets_order' ) );
		$this->addAction( 'mainwp_save_settings', array( &$this, 'ajax_mainwp_save_settings' ) );

		add_action( 'wp_ajax_mainwp_twitter_dashboard_action', array(
			&$this,
			'mainwp_twitter_dashboard_action',
		) ); // ok

		add_action( 'wp_ajax_mainwp_reset_usercookies', array( &$this, 'mainwp_reset_usercookies' ) ); // ok
		// Page: Recent Posts
		if ( mainwp_current_user_can( 'dashboard', 'manage_posts' ) ) {
			$this->addAction( 'mainwp_post_unpublish', array( &$this, 'mainwp_post_unpublish' ) );
			$this->addAction( 'mainwp_post_publish', array( &$this, 'mainwp_post_publish' ) );
			$this->addAction( 'mainwp_post_trash', array( &$this, 'mainwp_post_trash' ) );
			$this->addAction( 'mainwp_post_delete', array( &$this, 'mainwp_post_delete' ) );
			$this->addAction( 'mainwp_post_restore', array( &$this, 'mainwp_post_restore' ) );
			$this->addAction( 'mainwp_post_approve', array( &$this, 'mainwp_post_approve' ) );
		}
		$this->addAction( 'mainwp_post_addmeta', array( MainWP_Post::getClassName(), 'ajax_add_meta' ) );
		// Page: Pages
		if ( mainwp_current_user_can( 'dashboard', 'manage_pages' ) ) {
			$this->addAction( 'mainwp_page_unpublish', array( &$this, 'mainwp_page_unpublish' ) );
			$this->addAction( 'mainwp_page_publish', array( &$this, 'mainwp_page_publish' ) );
			$this->addAction( 'mainwp_page_trash', array( &$this, 'mainwp_page_trash' ) );
			$this->addAction( 'mainwp_page_delete', array( &$this, 'mainwp_page_delete' ) );
			$this->addAction( 'mainwp_page_restore', array( &$this, 'mainwp_page_restore' ) );
		}
		// Page: Users
		$this->addAction( 'mainwp_user_delete', array( &$this, 'mainwp_user_delete' ) );
		$this->addAction( 'mainwp_user_edit', array( &$this, 'mainwp_user_edit' ) );
		$this->addAction( 'mainwp_user_update_password', array( &$this, 'mainwp_user_update_password' ) );
		$this->addAction( 'mainwp_user_update_user', array( &$this, 'mainwp_user_update_user' ) );

		// Page: Posts
		add_action( 'wp_ajax_mainwp_posts_search', array( &$this, 'mainwp_posts_search' ) ); // ok
		add_action( 'wp_ajax_mainwp_get_categories', array( &$this, 'mainwp_get_categories' ) ); // ok
		add_action( 'wp_ajax_mainwp_posts_get_terms', array( &$this, 'mainwp_posts_get_terms' ) ); // ok
		add_action( 'wp_ajax_mainwp_posts_test_post', array( &$this, 'mainwp_posts_test_post' ) ); // ok
		$this->addAction( 'mainwp_post_get_edit', array( &$this, 'mainwp_post_get_edit' ) );

		// Page: Pages
		add_action( 'wp_ajax_mainwp_pages_search', array( &$this, 'mainwp_pages_search' ) ); // ok
		// Page: User
		$this->addAction( 'mainwp_users_search', array( &$this, 'mainwp_users_search' ) );

		// Page: Themes
		$this->addAction( 'mainwp_themes_search', array( &$this, 'mainwp_themes_search' ) );
		$this->addAction( 'mainwp_themes_search_all', array( &$this, 'mainwp_themes_search_all' ) );
		if ( mainwp_current_user_can( 'dashboard', 'activate_themes' ) ) {
			$this->addAction( 'mainwp_theme_activate', array( &$this, 'mainwp_theme_activate' ) );
		}
		if ( mainwp_current_user_can( 'dashboard', 'delete_themes' ) ) {
			$this->addAction( 'mainwp_theme_delete', array( &$this, 'mainwp_theme_delete' ) );
		}
		$this->addAction( 'mainwp_trusted_theme_notes_save', array( &$this, 'mainwp_trusted_theme_notes_save' ) );
		if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
			$this->addAction( 'mainwp_theme_ignore_updates', array( &$this, 'mainwp_theme_ignore_updates' ) );
		}

		// Page: Plugins
		$this->addAction( 'mainwp_plugins_search', array( &$this, 'mainwp_plugins_search' ) );
		$this->addAction( 'mainwp_plugins_search_all_active', array( &$this, 'mainwp_plugins_search_all_active' ) );

		if ( mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) {
			$this->addAction( 'mainwp_plugin_activate', array( &$this, 'mainwp_plugin_activate' ) );
			$this->addAction( 'mainwp_plugin_deactivate', array( &$this, 'mainwp_plugin_deactivate' ) );
		}
		if ( mainwp_current_user_can( 'dashboard', 'delete_plugins' ) ) {
			$this->addAction( 'mainwp_plugin_delete', array( &$this, 'mainwp_plugin_delete' ) );
		}

		if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
			$this->addAction( 'mainwp_plugin_ignore_updates', array( &$this, 'mainwp_plugin_ignore_updates' ) );
		}
		$this->addAction( 'mainwp_trusted_plugin_notes_save', array( &$this, 'mainwp_trusted_plugin_notes_save' ) );

		// Widget: Plugins
		$this->addAction( 'mainwp_widget_plugin_activate', array( &$this, 'mainwp_widget_plugin_activate' ) );
		$this->addAction( 'mainwp_widget_plugin_deactivate', array( &$this, 'mainwp_widget_plugin_deactivate' ) );
		$this->addAction( 'mainwp_widget_plugin_delete', array( &$this, 'mainwp_widget_plugin_delete' ) );

		// Widget: Themes
		$this->addAction( 'mainwp_widget_theme_activate', array( &$this, 'mainwp_widget_theme_activate' ) );
		$this->addAction( 'mainwp_widget_theme_delete', array( &$this, 'mainwp_widget_theme_delete' ) );

		$this->addAction( 'mainwp_events_notice_hide', array( &$this, 'mainwp_events_notice_hide' ) );
		$this->addAction( 'mainwp_showhide_sections', array( &$this, 'mainwp_showhide_sections' ) );
		$this->addAction( 'mainwp_saving_status', array( &$this, 'mainwp_saving_status' ) );
		$this->addAction( 'mainwp_autoupdate_and_trust_child', array( &$this, 'mainwp_autoupdate_and_trust_child' ) );
		$this->addAction( 'mainwp_installation_warning_hide', array( &$this, 'mainwp_installation_warning_hide' ) );
		$this->addAction( 'mainwp_force_destroy_sessions', array( &$this, 'mainwp_force_destroy_sessions' ) );
		$this->addAction( 'mainwp_recheck_http', array( &$this, 'mainwp_recheck_http' ) );
		$this->addAction( 'mainwp_ignore_http_response', array( &$this, 'mainwp_ignore_http_response' ) );
		$this->addAction( 'mainwp_disconnect_site', array( &$this, 'ajax_disconnect_site' ) );
		$this->addAction( 'mainwp_manage_display_rows', array( &$this, 'ajax_display_rows' ) );

		$this->addSecurityNonce( 'mainwp-common-nonce' );

		MainWP_Extensions::initAjaxHandlers();

		add_action( 'wp_ajax_mainwp_childscan', array( &$this, 'mainwp_childscan' ) ); // ok
	}

	function mainwp_childscan() {
		// todo: RS: secure action
		MainWP_Child_Scan::scan();
	}

	// Hide the installation warning
	function mainwp_installation_warning_hide() {
		$this->secure_request( 'mainwp_installation_warning_hide' );

		update_option( 'mainwp_installation_warning_hide_the_notice', 'yes' );
		die( 'ok' );
	}

	/**
	 * Page: Users
	 */
	function mainwp_users_search() {
		$this->secure_request( 'mainwp_users_search' );
		MainWP_Cache::initSession();
		MainWP_User::renderTable( false, $_POST['role'], ( isset( $_POST['groups'] ) ? $_POST['groups'] : '' ), ( isset( $_POST['sites'] ) ? $_POST['sites'] : '' ), $_POST['search'] );
		die();
	}

	/**
	 * Page: Themes
	 */
	function mainwp_themes_search() {
		$this->secure_request( 'mainwp_themes_search' );
		MainWP_Cache::initSession();
		$result = MainWP_Themes::renderTable( $_POST['keyword'], $_POST['status'], ( isset( $_POST['groups'] ) ? $_POST['groups'] : '' ), ( isset( $_POST['sites'] ) ? $_POST['sites'] : '' ) );
		wp_send_json( $result );
	}

	function mainwp_theme_activate() {
		$this->secure_request( 'mainwp_theme_activate' );

		MainWP_Themes::activateTheme();
		die();
	}

	function mainwp_theme_delete() {
		$this->secure_request( 'mainwp_theme_delete' );

		MainWP_Themes::deleteThemes();
		die();
	}

	function mainwp_theme_ignore_updates() {
		$this->secure_request( 'mainwp_theme_ignore_updates' );

		MainWP_Themes::ignoreUpdates();
		die();
	}

	function mainwp_themes_search_all() {
		$this->secure_request( 'mainwp_themes_search_all' );
		MainWP_Cache::initSession();
		MainWP_Themes::renderAllThemesTable();
		die();
	}

	function mainwp_trusted_theme_notes_save() {
		$this->secure_request( 'mainwp_trusted_theme_notes_save' );

		MainWP_Themes::saveTrustedThemeNote();
		die( json_encode( array( 'result' => 'SUCCESS' ) ) );
	}

	/**
	 * Page: Plugins
	 */
	function mainwp_plugins_search() {
		$this->secure_request( 'mainwp_plugins_search' );
		MainWP_Cache::initSession();
		$result = MainWP_Plugins::renderTable( $_POST['keyword'], $_POST['status'], ( isset( $_POST['groups'] ) ? $_POST['groups'] : '' ), ( isset( $_POST['sites'] ) ? $_POST['sites'] : '' ) );
		wp_send_json( $result );
	}

	function mainwp_plugins_search_all_active() {
		$this->secure_request( 'mainwp_plugins_search_all_active' );
		MainWP_Cache::initSession();
		MainWP_Plugins::renderAllActiveTable();
		die();
	}

	function mainwp_plugin_activate() {
		$this->secure_request( 'mainwp_plugin_activate' );

		MainWP_Plugins::activatePlugins();
		die();
	}

	function mainwp_plugin_deactivate() {
		$this->secure_request( 'mainwp_plugin_deactivate' );

		MainWP_Plugins::deactivatePlugins();
		die();
	}

	function mainwp_plugin_delete() {
		$this->secure_request( 'mainwp_plugin_delete' );

		MainWP_Plugins::deletePlugins();
		die();
	}

	function mainwp_plugin_ignore_updates() {
		$this->secure_request( 'mainwp_plugin_ignore_updates' );

		MainWP_Plugins::ignoreUpdates();
		die();
	}

	function mainwp_trusted_plugin_notes_save() {
		$this->secure_request( 'mainwp_trusted_plugin_notes_save' );

		MainWP_Plugins::saveTrustedPluginNote();
		die( json_encode( array( 'result' => 'SUCCESS' ) ) );
	}

	/**
	 * Widget: Plugins
	 */
	function mainwp_widget_plugin_activate() {
		$this->secure_request( 'mainwp_widget_plugin_activate' );
		MainWP_Widget_Plugins::activatePlugin();
	}

	function mainwp_widget_plugin_deactivate() {
		$this->secure_request( 'mainwp_widget_plugin_deactivate' );
		MainWP_Widget_Plugins::deactivatePlugin();
	}

	function mainwp_widget_plugin_delete() {
		$this->secure_request( 'mainwp_widget_plugin_delete' );
		MainWP_Widget_Plugins::deletePlugin();
	}

	/**
	 * Widget: Themes
	 */
	function mainwp_widget_theme_activate() {
		$this->secure_request( 'mainwp_widget_theme_activate' );
		MainWP_Widget_Themes::activateTheme();
	}

	function mainwp_widget_theme_delete() {
		$this->secure_request( 'mainwp_widget_theme_delete' );
		MainWP_Widget_Themes::deleteTheme();
	}

	/**
	 * Page: Posts
	 */
	function mainwp_posts_search() {
		$this->secure_request();

		$post_type = ( isset( $_POST['post_type'] ) && 0 < strlen( trim( $_POST['post_type'] ) ) ? $_POST['post_type'] : 'post' );

		if ( isset( $_POST['maximum'] ) ) {
			MainWP_Utility::update_option( 'mainwp_maximumPosts', MainWP_Utility::ctype_digit( $_POST['maximum'] ) ? intval( $_POST['maximum'] ) : 50  );
		}

		MainWP_Cache::initSession();

		MainWP_Post::renderTable( false, $_POST['keyword'], $_POST['dtsstart'], $_POST['dtsstop'], $_POST['status'], ( isset( $_POST['groups'] ) ? $_POST['groups'] : '' ), ( isset( $_POST['sites'] ) ? $_POST['sites'] : '' ), $_POST['postId'], $_POST['userId'], $post_type, $_POST['search_on'] );

		die();
	}

	function mainwp_posts_get_terms() {
		$this->secure_request();
		MainWP_Post::getTerms( $_POST['selected_site'], $_POST['prefix'], $_POST['what'], $_POST['generate_type'] );
		die();
	}

	function mainwp_posts_test_post() {
		$this->secure_request();
		MainWP_Post::testPost();
		die();
	}

	function mainwp_get_categories() {
		$this->secure_request();
		MainWP_Post::getCategories();
		die();
	}

	function mainwp_post_get_edit() {
		$this->secure_request( 'mainwp_post_get_edit' );
		MainWP_Post::getPost(); // to edit
		die();
	}

	/**
	 * Page: Pages
	 */
	function mainwp_pages_search() {
		$this->secure_request();
		if ( isset( $_POST['maximum'] ) ) {
			MainWP_Utility::update_option( 'mainwp_maximumPages', MainWP_Utility::ctype_digit( $_POST['maximum'] ) ? intval( $_POST['maximum'] ) : 50  );
		}

		MainWP_Cache::initSession();

		MainWP_Page::renderTable( false, $_POST['keyword'], $_POST['dtsstart'], $_POST['dtsstop'], $_POST['status'], ( isset( $_POST['groups'] ) ? $_POST['groups'] : '' ), ( isset( $_POST['sites'] ) ? $_POST['sites'] : '' ), $_POST['search_on'] );
		die();
	}

	/**
	 * Page: Users
	 */
	function mainwp_user_delete() {
		$this->secure_request( 'mainwp_user_delete' );

		MainWP_User::delete();
	}

	function mainwp_user_edit() {
		$this->secure_request( 'mainwp_user_edit' );

		MainWP_User::edit();
	}

	function mainwp_user_update_password() {
		$this->secure_request( 'mainwp_user_update_password' );

		MainWP_User::updatePassword();
	}

	function mainwp_user_update_user() {
		$this->secure_request( 'mainwp_user_update_user' );

		MainWP_User::updateUser();
	}

	/**
	 * Page: Recent Posts
	 */
	function mainwp_post_unpublish() {
		$this->secure_request( 'mainwp_post_unpublish' );

		MainWP_Recent_Posts::unpublish();
	}

	function mainwp_post_publish() {
		$this->secure_request( 'mainwp_post_publish' );

		MainWP_Recent_Posts::publish();
	}

	function mainwp_post_approve() {
		$this->secure_request( 'mainwp_post_approve' );

		MainWP_Recent_Posts::approve();
	}

	function mainwp_post_trash() {
		$this->secure_request( 'mainwp_post_trash' );

		MainWP_Recent_Posts::trash();
	}

	function mainwp_post_delete() {
		$this->secure_request( 'mainwp_post_delete' );

		MainWP_Recent_Posts::delete();
	}

	function mainwp_post_restore() {
		$this->secure_request( 'mainwp_post_restore' );

		MainWP_Recent_Posts::restore();
	}

	/**
	 * Page: Recent Pages
	 */
	function mainwp_page_unpublish() {
		$this->secure_request( 'mainwp_page_unpublish' );

		MainWP_Page::unpublish();
	}

	function mainwp_page_publish() {
		$this->secure_request( 'mainwp_page_publish' );

		MainWP_Page::publish();
	}

	function mainwp_page_trash() {
		$this->secure_request( 'mainwp_page_trash' );

		MainWP_Page::trash();
	}

	function mainwp_page_delete() {
		$this->secure_request( 'mainwp_page_delete' );

		MainWP_Page::delete();
	}

	function mainwp_page_restore() {
		$this->secure_request( 'mainwp_page_restore' );

		MainWP_Page::restore();
	}

	// Hide after installtion notices (PHP version, Trust MainWP Child, Multisite Warning and OpenSSL warning)
	function mainwp_notice_status_update() {
		$this->secure_request( 'mainwp_notice_status_update' );

		if ( 'mail_failed' === $_POST['notice_id'] ) {
			MainWP_Utility::update_option( 'mainwp_notice_wp_mail_failed', 'hide' );
			die( 'ok' );
		}

		global $current_user;
		if ( ( $user_id = $current_user->ID ) ) {
			$status = get_user_option( 'mainwp_notice_saved_status' );
			if ( ! is_array( $status ) ) {
				$status = array();
			}
			$status[ $_POST['notice_id'] ] = 1;
			update_user_option( $user_id, 'mainwp_notice_saved_status', $status );
		}
		die( 1 );
	}

	function mainwp_status_saving() {
		$this->secure_request( 'mainwp_status_saving' );
		$values = get_option( 'mainwp_status_saved_values' );

		if ( ! isset( $_POST['status'] ) ) {
			die( -1 );
		}

		if ( 'last_sync_sites' === $_POST['status'] ) {
			update_option( 'mainwp_last_synced_all_sites', time() );
			do_action( 'mainwp_synced_all_sites' );
			die( 'ok' );
		}

		if ( ! isset( $_POST['value'] ) || empty( $_POST['value'] ) ) {
			if ( isset( $values[ $_POST['status'] ] ) ) {
				unset( $values[ $_POST['status'] ] );
			}
		} else {
			$values[ $_POST['status'] ] = $_POST['value'];
		}

		update_option( 'mainwp_status_saved_values', $values );
		die( 'ok' );
	}

	function ajax_widgets_order() {

		$this->secure_request( 'mainwp_widgets_order' );
		if ( $user = wp_get_current_user() ) {
			update_user_option($user->ID, 'mainwp_widgets_sorted_' . $_POST['page'], ( isset($_POST['order']) ? $_POST['order'] : '' ), true);
			die( 'ok' );
		}
		die( -1 );
	}

	function ajax_mainwp_save_settings() {
		$this->secure_request( 'mainwp_save_settings' );
		$option_name = 'mainwp_' . $_POST['name'];
		$val         = $_POST['value'];

		MainWP_Utility::update_option( $option_name, $val );

		die( 'ok' );
	}

	function mainwp_leftmenu_filter_group() {
		$this->secure_request( 'mainwp_leftmenu_filter_group' );
		if ( isset( $_POST['group_id'] ) && ! empty( $_POST['group_id'] ) ) {
			$ids      = '';
			$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $_POST['group_id'], true ) );
			while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
				$ids .= $website->id . ',';
			}
			MainWP_DB::free_result( $websites );
			$ids = rtrim( $ids, ',' );
			die( $ids );
		}
		die( '' );
	}

	function mainwp_dismiss_twit() {
		$this->secure_request( 'mainwp_dismiss_twit' );

		global $current_user;
		if ( ( $user_id = $current_user->ID ) && isset( $_POST['twitId'] ) && ! empty( $_POST['twitId'] ) && isset( $_POST['what'] ) && ! empty( $_POST['what'] ) ) {
			MainWP_Twitter::clearTwitterInfo( $_POST['what'], $_POST['twitId'] );
		}
		die( 1 );
	}

	function dismiss_activate_notice() {
		$this->secure_request( 'mainwp_dismiss_activate_notice' );

		global $current_user;
		if ( ( $user_id = $current_user->ID ) && isset( $_POST['slug'] ) && ! empty( $_POST['slug'] ) ) {
			$activate_notices = get_user_option( 'mainwp_hide_activate_notices' );
			if ( ! is_array( $activate_notices ) ) {
				$activate_notices = array();
			}
			$activate_notices[ $_POST['slug'] ] = time();
			update_user_option( $user_id, 'mainwp_hide_activate_notices', $activate_notices );
		}
		die( 1 );
	}

	function mainwp_twitter_dashboard_action() {

		$success = false;
		if ( isset( $_POST['actionName'] ) && isset( $_POST['countSites'] ) && ! empty( $_POST['countSites'] ) ) {
			$success = MainWP_Twitter::updateTwitterInfo( $_POST['actionName'], $_POST['countSites'], (int) $_POST['countSeconds'], ( isset( $_POST['countRealItems'] ) ? $_POST['countRealItems'] : 0 ), time(), ( isset( $_POST['countItems'] ) ? $_POST['countItems'] : 0 ) );
		}

		if ( isset( $_POST['showNotice'] ) && ! empty( $_POST['showNotice'] ) ) {
			if ( MainWP_Twitter::enabledTwitterMessages() ) {
				$twitters = MainWP_Twitter::getTwitterNotice( $_POST['actionName'] );
				$html     = '';
				if ( is_array( $twitters ) ) {
					foreach ( $twitters as $timeid => $twit_mess ) {
						if ( ! empty( $twit_mess ) ) {
							$sendText = MainWP_Twitter::getTwitToSend( $_POST['actionName'], $timeid );
							$html    .= '<div class="mainwp-tips mainwp-notice mainwp-notice-blue twitter"><span class="mainwp-tip" twit-what="' . esc_attr($_POST['actionName']) . '" twit-id="' . $timeid . '">' . $twit_mess . '</span>&nbsp;' . MainWP_Twitter::genTwitterButton( $sendText, false ) . '<span><a href="#" class="mainwp-dismiss-twit mainwp-right" ><i class="fa fa-times-circle"></i> ' . __( 'Dismiss', 'mainwp' ) . '</a></span></div>';
						}
					}
				}
				die( $html );
			}
		} elseif ( $success ) {
			die( 'ok' );
		}

		die( '' );
	}

	function mainwp_reset_usercookies() {
		$this->secure_request();

		global $current_user;
		if ( ( $user_id = $current_user->ID ) && isset( $_POST['what'] ) && ! empty( $_POST['what'] ) ) {
			$user_cookies = get_user_option( 'mainwp_saved_user_cookies' );
			if ( ! is_array( $user_cookies ) ) {
				$user_cookies = array();
			}
			if ( ! isset( $user_cookies[ $_POST['what'] ] ) ) {
				$user_cookies[ $_POST['what'] ] = 1;
				update_user_option( $user_id, 'mainwp_saved_user_cookies', $user_cookies );
			}
		}
		die( 1 );
	}

	/**
	 * Page: SecurityIssues
	 */
	function mainwp_securityIssues_request() {
		$this->secure_request( 'mainwp_securityIssues_request' );

		try {
			wp_send_json( array( 'result' => MainWP_Security_Issues::fetchSecurityIssues() ) );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	function mainwp_securityIssues_fix() {
		$this->secure_request( 'mainwp_securityIssues_fix' );

		try {
			wp_send_json( array( 'result' => MainWP_Security_Issues::fixSecurityIssue() ) );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	function mainwp_securityIssues_unfix() {
		$this->secure_request( 'mainwp_securityIssues_unfix' );

		try {
			wp_send_json( array( 'result' => MainWP_Security_Issues::unfixSecurityIssue() ) );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}


	public function ajax_disconnect_site() {
		$this->secure_request( 'mainwp_disconnect_site' );

		$siteid = $_POST['wp_id'];

		if ( empty( $siteid ) ) {
			die( json_encode( array( 'error' => 'Error: site id empty' ) ) );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $siteid );

		if ( ! $website ) {
			die( json_encode( array( 'error' => 'Not found site' ) ) );
		}

		try {
			$information = MainWP_Utility::fetchUrlAuthed( $website, 'disconnect');
		} catch ( Exception $e ) {
			$information = array( 'error' => __( 'fetchUrlAuthed exception', 'mainwp' ) );
		}

		// die( json_encode( $information ) );
		wp_send_json( $information );
	}

	public function ajax_display_rows() {
		$this->secure_request( 'mainwp_manage_display_rows' );
		MainWP_Manage_Sites::display_rows();
	}

	/*
	 * Page: Backup
	 */
	function mainwp_backup_run_site() {
		$this->secure_request( 'mainwp_backup_run_site' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			$ret = array( 'result' => MainWP_Manage_Sites::backup( $_POST['site_id'], 'full', '', '', 1, 1, 1, 1 ) );
			wp_send_json($ret);
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	function mainwp_backup() {
		$this->secure_request( 'mainwp_backup' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			$excludedFolder = trim( $_POST['exclude'], "\n" );
			$excludedFolder = explode( "\n", $excludedFolder );
			$excludedFolder = array_map( array( 'MainWP_Utility', 'trimSlashes' ), $excludedFolder );
			$excludedFolder = array_map( 'htmlentities', $excludedFolder );
			$excludedFolder = implode( ',', $excludedFolder );

			$result = MainWP_Manage_Sites::backup( $_POST['site_id'], $_POST['type'], ( isset( $_POST['subfolder'] ) ? $_POST['subfolder'] : '' ), $excludedFolder, $_POST['excludebackup'], $_POST['excludecache'], $_POST['excludenonwp'], $_POST['excludezip'], $_POST['filename'], isset( $_POST['fileNameUID'] ) ? $_POST['fileNameUID'] : '', $_POST['archiveFormat'], ( isset($_POST['maximumFileDescriptorsOverride']) && 1 === $_POST['maximumFileDescriptorsOverride'] ), ( 1 === $_POST['maximumFileDescriptorsAuto'] ), ( isset($_POST['maximumFileDescriptors']) ? $_POST['maximumFileDescriptors'] : '' ), ( isset( $_POST['loadFilesBeforeZip'] ) ? $_POST['loadFilesBeforeZip'] : '' ), $_POST['pid'], ( isset( $_POST['append'] ) && ( 1 === $_POST['append'] ) ) );
			wp_send_json( array( 'result' => $result ) );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	function mainwp_backup_checkpid() {
		$this->secure_request( 'mainwp_backup_checkpid' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			wp_send_json( MainWP_Manage_Sites::backupCheckpid( $_POST['site_id'], $_POST['pid'], $_POST['type'], ( isset($_POST['subfolder']) ? $_POST['subfolder'] : '' ), $_POST['filename'] )  );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	function mainwp_backup_download_file() {
		$this->secure_request( 'mainwp_backup_download_file' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			die( json_encode( array( 'result' => MainWP_Manage_Sites::backupDownloadFile( $_POST['site_id'], $_POST['type'], $_POST['url'], $_POST['local'] ) ) ) );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	function mainwp_backup_delete_file() {
		$this->secure_request( 'mainwp_backup_delete_file' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( __( 'Invalid request!', 'mainwp' ) );
			}

			die( json_encode( array( 'result' => MainWP_Manage_Sites::backupDeleteFile( $_POST['site_id'], $_POST['file'] ) ) ) );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	function mainwp_createbackup_getfilesize() {
		$this->secure_request( 'mainwp_createbackup_getfilesize' );

		try {
			if ( ! isset( $_POST['siteId'] ) ) {
				throw new Exception( __( 'No site selected!', 'mainwp' ) );
			}
			$siteId      = $_POST['siteId'];
			$fileName    = $_POST['fileName'];
			$fileNameUID = $_POST['fileNameUID'];
			$type        = $_POST['type'];

			$website = MainWP_DB::Instance()->getWebsiteById( $siteId );
			if ( ! $website ) {
				throw new Exception( __( 'No site selected!', 'mainwp' ) );
			}

			MainWP_Utility::endSession();
			// Send request to the childsite!
			$result = MainWP_Utility::fetchUrlAuthed( $website, 'createBackupPoll', array(
				'fileName'       => $fileName,
				'fileNameUID'    => $fileNameUID,
				'type'           => $type,
			) );

			if ( ! isset( $result['size'] ) ) {
				throw new Exception( __( 'Invalid response!', 'mainwp' ) );
			}

			if ( MainWP_Utility::ctype_digit( $result['size'] ) ) {
				$output = array( 'size' => $result['size'] );
			} else {
				$output = array();
			}
		} catch ( Exception $e ) {
			$output = array( 'error' => $e->getMessage() );
		}

		die( json_encode( $output ) );
	}

	function mainwp_backup_getfilesize() {
		$this->secure_request( 'mainwp_backup_getfilesize' );

		try {
			die( json_encode( array( 'result' => MainWP_Manage_Sites::backupGetFilesize( $_POST['local'] ) ) ) );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	function mainwp_backup_upload_checkstatus() {
		$this->secure_request( 'mainwp_backup_upload_checkstatus' );

		try {
			$array = get_option( 'mainwp_upload_progress' );
			$info  = apply_filters( 'mainwp_remote_destination_info', array(), $_POST['remote_destination'] );

			if ( ! is_array( $array ) || ! isset( $array[ $_POST['unique'] ] ) || ! isset( $array[ $_POST['unique'] ]['dts'] ) ) {
				die( json_encode( array(
					'status' => 'stalled',
					'info'   => $info,
				) ) );
			} elseif ( isset( $array[ $_POST['unique'] ]['finished'] ) ) {
				die( json_encode( array(
					'status' => 'done',
					'info'   => $info,
				) ) );
			} else {
				if ( $array[ $_POST['unique'] ]['dts'] < ( time() - ( 2 * 60 ) ) ) { // 2minutes
					die( json_encode( array(
						'status' => 'stalled',
						'info'   => $info,
					) ) );
				} else {
					die( json_encode( array(
						'status' => 'busy',
						'info'   => $info,
					) ) );
				}
			}
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	function mainwp_backup_upload_getprogress() {
		$this->secure_request( 'mainwp_backup_upload_getprogress' );

		try {
			$array = get_option( 'mainwp_upload_progress' );

			if ( ! is_array( $array ) || ! isset( $array[ $_POST['unique'] ] ) ) {
				die( json_encode( array( 'result' => 0 ) ) );
			} elseif ( isset( $array[ $_POST['unique'] ]['finished'] ) ) {
				throw new MainWP_Exception( __( 'finished...', 'maiwnp' ) );
			} else {
				wp_send_json( array( 'result' => ( isset( $array[ $_POST['unique'] ]['offset'] ) ? $array[ $_POST['unique'] ]['offset'] : $array[ $_POST['unique'] ] ) )  );
			}
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	/*
	 * Page: BulkAddUser
	 */

	function mainwp_bulkadduser() {
		if ( ! $this->check_security( 'mainwp_bulkadduser' ) ) {
			die( 'ERROR ' . json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		MainWP_User::doBukAdd();
		die();
	}

	function mainwp_bulkuploadadduser() {
		$this->secure_request();

		MainWP_User::renderBulkUpload();
		die();
	}

	function mainwp_importuser() {
		$this->secure_request( 'mainwp_importuser' );

		MainWP_User::doImport();
	}

	/*
	 * Page: InstallPlugins/Themes
	 */

	function mainwp_preparebulkinstallplugintheme() {
		$this->secure_request();

		MainWP_Install_Bulk::prepareInstall();
	}

	function mainwp_installbulkinstallplugintheme() {
		$this->secure_request( 'mainwp_installbulkinstallplugintheme' );

		MainWP_Install_Bulk::performInstall();
	}

	function mainwp_preparebulkuploadplugintheme() {
		$this->secure_request();

		MainWP_Install_Bulk::prepareUpload();
	}

	function mainwp_installbulkuploadplugintheme() {
		$this->secure_request( 'mainwp_installbulkuploadplugintheme' );

		MainWP_Install_Bulk::performUpload();
	}

	function mainwp_cleanbulkuploadplugintheme() {
		$this->secure_request( 'mainwp_cleanbulkuploadplugintheme' );

		MainWP_Install_Bulk::cleanUpload();
	}

	/*
	 * Page: ManageGroups
	 */

	function mainwp_group_rename() {
		$this->secure_request( 'mainwp_group_rename' );

		MainWP_Manage_Groups::renameGroup();
	}

	function mainwp_group_delete() {
		$this->secure_request( 'mainwp_group_delete' );

		MainWP_Manage_Groups::deleteGroup();
	}

	function mainwp_group_add() {
		$this->secure_request( 'mainwp_group_add' );

		MainWP_Manage_Groups::addGroup();
	}

	function mainwp_group_getsites() {
		$this->secure_request();

		die( MainWP_Manage_Groups::getSites() );
	}

	function mainwp_group_updategroup() {
		$this->secure_request( 'mainwp_group_updategroup' );

		MainWP_Manage_Groups::updateGroup();
	}

	/*
	 * Page: ManageBackups
	 */

	// Add task to the database
	function mainwp_addbackup() {
		$this->secure_request( 'mainwp_addbackup' );

		MainWP_Manage_Backups::addBackup();
	}

	// Update task
	function mainwp_updatebackup() {
		$this->secure_request( 'mainwp_updatebackup' );

		MainWP_Manage_Backups::updateBackup();
	}

	// Remove a task from MainWP
	function mainwp_removebackup() {
		$this->secure_request( 'mainwp_removebackup' );

		MainWP_Manage_Backups::removeBackup();
	}

	function mainwp_resumebackup() {
		$this->secure_request( 'mainwp_resumebackup' );

		MainWP_Manage_Backups::resumeBackup();
	}

	function mainwp_pausebackup() {
		$this->secure_request( 'mainwp_pausebackup' );

		MainWP_Manage_Backups::pauseBackup();
	}

	function mainwp_backuptask_get_sites() {
		$this->secure_request();

		$taskID = $_POST['task_id'];

		wp_send_json( array( 'result' => MainWP_Manage_Backups::getBackupTaskSites( $taskID ) ) );
	}

	function mainwp_backuptask_run_site() {
		try {
			$this->secure_request( 'mainwp_backuptask_run_site' );

			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) || ! isset( $_POST['task_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['task_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			wp_send_json( array( 'result' => MainWP_Manage_Backups::backup( $_POST['task_id'], $_POST['site_id'], $_POST['fileNameUID'] ) ) );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	function mainwp_backup_upload_file() {
		try {
			$this->secure_request( 'mainwp_backup_upload_file' );

			do_action( 'mainwp_remote_backup_extension_backup_upload_file' );

			throw new MainWP_Exception( __( 'Remote Backup extension has not been installed or activated.', 'mainwp' ) );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	/*
	 * Page: ManageSites
	 */

	// Check if WP can be added
	function mainwp_checkwp() {
		if ( $this->check_security( 'mainwp_checkwp', 'security' ) ) {
			MainWP_Manage_Sites::checkSite();
		} else {
			die( json_encode( array( 'response' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	// Add WP to the database
	function mainwp_addwp() {
		if ( $this->check_security( 'mainwp_addwp', 'security' ) ) {
			MainWP_Manage_Sites::addSite();
		} else {
			die( json_encode( array( 'response' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	function get_site_icon() {
		if ( $this->check_security( 'mainwp_get_site_icon', 'security' ) ) {
			$result = MainWP_System::sync_site_icon();
			wp_send_json( $result );
		} else {
			die( json_encode( array( 'error' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	function mainwp_ext_prepareinstallplugintheme() {

		do_action( 'mainwp_prepareinstallplugintheme' );
	}

	function mainwp_ext_performinstallplugintheme() {

		do_action( 'mainwp_performinstallplugintheme' );
	}

	function mainwp_ext_applypluginsettings() {
		if ( $this->check_security( 'mainwp_ext_applypluginsettings', 'security' ) ) {
			MainWP_Manage_Sites::apply_plugin_settings();
		} else {
			die( json_encode( array( 'error' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	function mainwp_testwp() {
		$this->secure_request( 'mainwp_testwp' );

		$url               = null;
		$name              = null;
		$http_user         = null;
		$http_pass         = null;
		$verifyCertificate = 1;
		$sslVersion        = 0;

		if ( isset( $_POST['url'] ) ) {
			$url = $_POST['url'];

			$temp_url = MainWP_Utility::removeHttpPrefix( $url, true );

			if ( strpos( $temp_url, ':' ) ) {
				die( json_encode( array( 'error' => __( 'Invalid URL.', 'mainwp' ) ) ) );
			}

			$verifyCertificate = $_POST['test_verify_cert'];
			$forceUseIPv4      = $_POST['test_force_use_ipv4'];
			$sslVersion        = $_POST['test_ssl_version'];
			$http_user         = $_POST['http_user'];
			$http_pass         = $_POST['http_pass'];

		} elseif ( isset( $_POST['siteid'] ) ) {
			$website = MainWP_DB::Instance()->getWebsiteById( $_POST['siteid'] );
			if ( $website ) {
				$url               = $website->url;
				$name              = $website->name;
				$verifyCertificate = $website->verify_certificate;
				$forceUseIPv4      = $website->force_use_ipv4;
				$sslVersion        = $website->ssl_version;
				$http_user         = $website->http_user;
				$http_pass         = $website->http_pass;
			}
		}

		$rslt = MainWP_Utility::tryVisit( $url, $verifyCertificate, $http_user, $http_pass, $sslVersion, $forceUseIPv4 );

		if ( isset( $rslt['error'] ) && ( '' !== $rslt['error'] ) && ( 'wp-admin/' !== substr( $url, - 9 ) ) ) {
			if ( substr( $url, - 1 ) != '/' ) {
				$url .= '/';
			}
			$url    .= 'wp-admin/';
			$newrslt = MainWP_Utility::tryVisit( $url, $verifyCertificate, $http_user, $http_pass, $sslVersion, $forceUseIPv4 );
			if ( isset( $newrslt['error'] ) && ( '' !== $rslt['error'] ) ) {
				$rslt = $newrslt;
			}
		}

		if ( null != $name ) {
			$rslt['sitename'] = esc_html($name);
		}

		wp_send_json( $rslt );
	}

	// Remove a website from MainWP
	function mainwp_removesite() {
		if ( ! mainwp_current_user_can( 'dashboard', 'delete_sites' ) ) {
			die( json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'delete sites', 'mainwp' ), false ) ) ) );
		}

		$this->secure_request( 'mainwp_removesite' );

		MainWP_Manage_Sites::removeSite();
	}

	// Save note
	function mainwp_notes_save() {
		$this->secure_request( 'mainwp_notes_save' );

		MainWP_Manage_Sites::saveNote();
	}

	function mainwp_reconnectwp() {
		$this->secure_request();

		MainWP_Manage_Sites::reconnectSite();
	}

	function mainwp_updatechildsite_value() {
		$this->secure_request( 'mainwp_updatechildsite_value' );

		MainWP_Manage_Sites::updateChildsiteValue();
	}

	/*
	 * Widget: RightNow
	 */

	function mainwp_syncsites() {
		$this->secure_request( 'mainwp_syncsites' );
		MainWP_Updates_Overview::dismissSyncErrors( false );
		MainWP_Updates_Overview::syncSite();
	}

	// Update a specific WP
	function mainwp_upgradewp() {
		if ( ! mainwp_current_user_can( 'dashboard', 'update_wordpress' ) ) {
			die( json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'update WordPress', 'mainwp' ), $echo = false ) ) ) );
		}

		$this->secure_request( 'mainwp_upgradewp' );

		try {
			$id = null;
			if ( isset( $_POST['id'] ) ) {
				$id = $_POST['id'];
			}
			die( json_encode( array( 'result' => MainWP_Updates::upgradeSite( $id ) ) ) ); // ok
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message'    => $e->getMessage(),
					'extra'      => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	// todo: rename
	function mainwp_upgradeplugintheme() {

		if ( ! isset($_POST['type'] ) ) {
			die( json_encode( array( 'error' => '<i class="red times icon"></i> ' . __( 'Invalid request', 'mainwp' ) ) ) );
		}

		if ( 'plugin' === $_POST['type'] && ! mainwp_current_user_can( 'dashboard', 'update_plugins' ) ) {
			die( json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'update plugins', 'mainwp' ), $echo = false ) ) ) );
		}

		if ( 'theme' === $_POST['type'] && ! mainwp_current_user_can( 'dashboard', 'update_themes' ) ) {
			die( json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'update themes', 'mainwp' ), $echo = false ) ) ) );
		}

		if ( 'translation' === $_POST['type'] && ! mainwp_current_user_can( 'dashboard', 'update_translations' ) ) {
			die( json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'update translations', 'mainwp' ), $echo = false ) ) ) );
		}

		$this->secure_request( 'mainwp_upgradeplugintheme' );

		// at the moment: support chunk update for manage sites page only
		$chunk_support = isset( $_POST['chunk_support'] ) && $_POST['chunk_support'] ? true : false;
		$max_update    = 0;
		$websiteId     = null;
		$slugs         = '';

		if ( isset( $_POST['websiteId'] ) ) {
			$websiteId = $_POST['websiteId'];

			if ( $chunk_support ) {
				$max_update = apply_filters('mainwp_update_plugintheme_max', false, $websiteId );
				if ( empty($max_update) ) {
					$chunk_support = false; // there is not hook so disable chunk update support
				}
			}
			if ( $chunk_support ) {
				if ( isset($_POST['chunk_slugs']) ) {
					$slugs = $_POST['chunk_slugs'];  // chunk slugs send so use this
				} else {
					$slugs = MainWP_Updates::getPluginThemeSlugs( $websiteId, $_POST['type'] );
				}
			} elseif ( isset( $_POST['slug'] ) ) {
				$slugs = $_POST['slug'];
			} else {
				$slugs = MainWP_Updates::getPluginThemeSlugs( $websiteId, $_POST['type'] );
			}
		}

		if ( MainWP_DB::Instance()->backupFullTaskRunning( $websiteId ) ) {
			die( json_encode( array( 'error' => __( 'Backup process in progress on the child site. Please, try again later.', 'mainwp' ) ) ) );
		}

		$chunk_slugs = array();

		if ( $chunk_support ) {
			// calculate update slugs here
			if ( $max_update ) {
				$slugs        = explode(',', $slugs);
				$chunk_slugs  = array_slice($slugs, $max_update);
				$update_slugs = array_diff($slugs, $chunk_slugs);
				$slugs        = implode(',', $update_slugs);
			}
		}

		if ( empty( $slugs ) && ! $chunk_support ) {
			die( json_encode( array( 'message' => __( 'Item slug could not be found. Update process could not be executed.', 'mainwp' ) ) ) );
		}
		$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );
		try {
			$info = array( 'result' => MainWP_Updates::upgradePluginThemeTranslation( $websiteId, $_POST['type'], $slugs ) );

			if ( $chunk_support && ( 0 < count( $chunk_slugs ) ) ) {
				$info['chunk_slugs'] = implode( ',', $chunk_slugs );
			}

			if ( ! empty( $website ) ) {
				$info['site_url'] = esc_url( $website->url );
			}
			wp_send_json( $info );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array(
				'error' => array(
					'message' => $e->getMessage(),
					'extra'   => $e->getMessageExtra(),
				),
			) ) );
		}
	}

	function mainwp_ignoreplugintheme() {
		$this->secure_request( 'mainwp_ignoreplugintheme' );

		if ( ! isset( $_POST['id'] ) ) {
			die( json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		wp_send_json( array( 'result' => MainWP_Updates::ignorePluginTheme( $_POST['type'], $_POST['slug'], $_POST['name'], $_POST['id'] ) ) );
	}

	function mainwp_unignoreabandonedplugintheme() {
		$this->secure_request( 'mainwp_unignoreabandonedplugintheme' );

		if ( ! isset( $_POST['id'] ) ) {
			die( json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( json_encode( array( 'result' => MainWP_Updates::unIgnoreAbandonedPluginTheme( $_POST['type'], $_POST['slug'], $_POST['id'] ) ) ) ); // ok
	}

	function mainwp_unignoreabandonedpluginsthemes() {
		$this->secure_request( 'mainwp_unignoreabandonedpluginsthemes' );

		if ( ! isset( $_POST['slug'] ) ) {
			die( json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( json_encode( array( 'result' => MainWP_Updates::unIgnoreAbandonedPluginsThemes( $_POST['type'], $_POST['slug'] ) ) ) );
	}

	function mainwp_dismissoutdateplugintheme() {
		$this->secure_request( 'mainwp_dismissoutdateplugintheme' );

		if ( ! isset( $_POST['id'] ) ) {
			die( json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( json_encode( array( 'result' => MainWP_Updates::dismissPluginTheme( $_POST['type'], $_POST['slug'], $_POST['name'], $_POST['id'] ) ) ) );
	}

	function mainwp_dismissoutdatepluginsthemes() {
		$this->secure_request( 'mainwp_dismissoutdatepluginsthemes' );

		if ( ! mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
			die( json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'ignore/unignore updates', 'mainwp' ) ) ) ) );
		}

		if ( ! isset( $_POST['slug'] ) ) {
			die( json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( json_encode( array( 'result' => MainWP_Updates::dismissPluginsThemes( $_POST['type'], $_POST['slug'], $_POST['name'] ) ) ) );
	}

	function mainwp_unignoreplugintheme() {
		$this->secure_request( 'mainwp_unignoreplugintheme' );

		if ( ! isset( $_POST['id'] ) ) {
			die( json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( json_encode( array( 'result' => MainWP_Updates::unIgnorePluginTheme( $_POST['type'], $_POST['slug'], $_POST['id'] ) ) ) );
	}

	function mainwp_ignorepluginsthemes() {
		$this->secure_request( 'mainwp_ignorepluginsthemes' );

		if ( ! mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
			die( json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'ignore/unignore updates', 'mainwp' ) ) ) ) );
		}

		if ( ! isset( $_POST['slug'] ) ) {
			die( json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( json_encode( array( 'result' => MainWP_Updates::ignorePluginsThemes( $_POST['type'], $_POST['slug'], $_POST['name'] ) ) ) );
	}

	function mainwp_unignorepluginsthemes() {
		$this->secure_request( 'mainwp_unignorepluginsthemes' );

		if ( ! isset( $_POST['slug'] ) ) {
			die( json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( json_encode( array( 'result' => MainWP_Updates::unIgnorePluginsThemes( $_POST['type'], $_POST['slug'] ) ) ) );
	}

	function mainwp_trust_plugin() {
		$this->secure_request( 'mainwp_trust_plugin' );

		MainWP_Plugins::trustPost();
		die( json_encode( array( 'result' => true ) ) );
	}

	function mainwp_trust_theme() {
		$this->secure_request( 'mainwp_trust_theme' );

		MainWP_Themes::trustPost();
		die( json_encode( array( 'result' => true ) ) );
	}

	function mainwp_checkbackups() {
		$this->secure_request( 'mainwp_checkbackups' );

		try {
			wp_send_json( array( 'result' => MainWP_Updates_Overview::checkBackups() ) );
		} catch ( Exception $e ) {
			die( json_encode( array( 'error' => $e->getMessage() ) ) );
		}
	}

	function mainwp_syncerrors_dismiss() {
		$this->secure_request( 'mainwp_syncerrors_dismiss' );

		try {
			die( json_encode( array( 'result' => MainWP_Updates_Overview::dismissSyncErrors() ) ) );
		} catch ( Exception $e ) {
			die( json_encode( array( 'error' => $e->getMessage() ) ) );
		}
	}


	function mainwp_events_notice_hide() {
		$this->secure_request( 'mainwp_events_notice_hide' );

		if ( isset( $_POST['notice'] ) ) {
			$current_options = get_option( 'mainwp_showhide_events_notice' );
			if ( ! is_array( $current_options ) ) {
				$current_options = array();
			}
			if ( 'first_site' === $_POST['notice'] ) {
				update_option( 'mainwp_first_site_events_notice', '' );
			} elseif ( 'request_reviews1' === $_POST['notice'] ) {
				$current_options['request_reviews1']           = 15;
				$current_options['request_reviews1_starttime'] = time();
			} elseif ( 'request_reviews1_forever' === $_POST['notice'] || 'request_reviews2_forever' === $_POST['notice'] ) {
				$current_options['request_reviews1'] = 'forever';
				$current_options['request_reviews2'] = 'forever';
			} elseif ( 'request_reviews2' === $_POST['notice'] ) {
				$current_options['request_reviews2']           = 15;
				$current_options['request_reviews2_starttime'] = time();
			} elseif ( 'trust_child' === $_POST['notice'] ) {
				$current_options['trust_child'] = 1;
			} elseif ( 'multi_site' === $_POST['notice'] ) {
				$current_options['hide_multi_site_notice'] = 1;
			}
			update_option( 'mainwp_showhide_events_notice', $current_options );
		}
		die( 'ok' );
	}

	function mainwp_showhide_sections() {
		if ( isset( $_POST['sec'] ) && isset( $_POST['status'] ) ) {
			$opts = get_option( 'mainwp_opts_showhide_sections' );
			if ( ! is_array( $opts ) ) {
				$opts = array();
			}
			$opts[ $_POST['sec'] ] = $_POST['status'];
			update_option( 'mainwp_opts_showhide_sections', $opts );
			die( 'ok' );
		}
		die( 'failed' );
	}

	function mainwp_saving_status() {
		if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'mainwp_ajax' ) ) {
			die( 'Invalid request.' );
		}
		if ( isset( $_POST['saving_status'] ) ) {
			$current_options = get_option( 'mainwp_opts_saving_status' );
			if ( ! is_array( $current_options ) ) {
				$current_options = array();
			}

			if ( ! empty( $_POST['saving_status'] ) ) {
				$current_options[ $_POST['saving_status'] ] = $_POST['value'];
			}

			update_option( 'mainwp_opts_saving_status', $current_options );
		}
		die( 'ok' );
	}

	function mainwp_recheck_http() {
		if ( ! $this->check_security( 'mainwp_recheck_http' ) ) {
			die( json_encode( array( 'error' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}

		if ( ! isset( $_POST['websiteid'] ) || empty( $_POST['websiteid'] ) ) {
			die( -1 );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $_POST['websiteid'] );
		if ( empty( $website ) ) {
			die( -1 );
		}

		$result       = MainWP_Utility::isWebsiteAvailable( $website );
		$http_code    = ( is_array( $result ) && isset( $result['httpCode'] ) ) ? $result['httpCode'] : 0;
		$check_result = MainWP_Utility::check_ignored_http_code( $http_code );
		MainWP_DB::Instance()->updateWebsiteValues( $website->id, array(
			'offline_check_result'   => $check_result ? '1' : '-1',
			'offline_checks_last'    => time(),
			'http_response_code'     => $http_code,
		) );
		die( json_encode( array(
			'httpcode' => esc_html($http_code),
			'status'   => $check_result ? 1 : 0,
		) ) );
	}

	function mainwp_ignore_http_response() {
		if ( ! $this->check_security( 'mainwp_ignore_http_response' ) ) {
			die( json_encode( array( 'error' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}

		if ( ! isset( $_POST['websiteid'] ) || empty( $_POST['websiteid'] ) ) {
			die( -1 );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $_POST['websiteid'] );
		if ( empty( $website ) ) {
			die( -1 );
		}

		MainWP_DB::Instance()->updateWebsiteValues( $website->id, array( 'http_response_code' => '-1' ) );
		die( json_encode( array( 'ok' => 1 ) ) );
	}

	function mainwp_autoupdate_and_trust_child() {
		$this->secure_request( 'mainwp_autoupdate_and_trust_child' );
		if ( get_option( 'mainwp_automaticDailyUpdate' ) != 1 ) {
			update_option( 'mainwp_automaticDailyUpdate', 1 );
		}
		MainWP_Plugins::trustPlugin( 'mainwp-child/mainwp-child.php' );
		die( 'ok' );
	}

	function secure_request( $action = '', $query_arg = 'security' ) {
		if ( ! MainWP_Utility::isAdmin() ) {
			die( 0 );
		}
		if ( '' === $action ) {
			return;
		}

		if ( ! $this->check_security( $action, $query_arg ) ) {
			die( json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}

		if ( isset( $_POST['dts'] ) ) {
			$ajaxPosts = get_option( 'mainwp_ajaxposts' );
			if ( ! is_array( $ajaxPosts ) ) {
				$ajaxPosts = array();
			}

			// If already processed, just quit!
			if ( isset( $ajaxPosts[ $action ] ) && ( $ajaxPosts[ $action ] == $_POST['dts'] ) ) {
				die( json_encode( array( 'error' => __( 'Double request!', 'mainwp' ) ) ) );
			}

			$ajaxPosts[ $action ] = $_POST['dts'];
			MainWP_Utility::update_option( 'mainwp_ajaxposts', $ajaxPosts );
		}
	}

	function check_security( $action = - 1, $query_arg = 'security' ) {
		if ( - 1 === $action ) {
			return false;
		}

		$adminurl = strtolower( admin_url() );
		$referer  = strtolower( wp_get_referer() );
		$result   = isset( $_REQUEST[ $query_arg ] ) ? wp_verify_nonce( $_REQUEST[ $query_arg ], $action ) : false;
		if ( ! $result && ! ( - 1 === $action && 0 === strpos( $referer, $adminurl ) ) ) {
			return false;
		}

		return true;
	}

	function addAction( $action, $callback ) {
		add_action( 'wp_ajax_' . $action, $callback );
		$this->addSecurityNonce( $action );
	}

	function addSecurityNonce( $action ) {
		if ( ! is_array( $this->security_nonces ) ) {
			$this->security_nonces = array();
		}

		if ( ! function_exists( 'wp_create_nonce' ) ) {
			include_once ABSPATH . WPINC . '/pluggable.php';
		}
		$this->security_nonces[ $action ] = wp_create_nonce( $action );
	}

	function getSecurityNonces() {
		return $this->security_nonces;
	}

	function mainwp_force_destroy_sessions() {
		$this->secure_request( 'mainwp_force_destroy_sessions' );

		$website_id = ( isset( $_POST['website_id'] ) ? (int) $_POST['website_id'] : 0 );

		if ( ! MainWP_DB::Instance()->getWebsiteById( $website_id ) ) {
			die( json_encode( array( 'error' => array( 'message' => __( 'This website does not exist.', 'mainwp' ) ) ) ) );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $website_id );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( json_encode( array( 'error' => array( 'message' => __( 'You cannot edit this website.', 'mainwp' ) ) ) ) );
		}

		try {
			$information = MainWP_Utility::fetchUrlAuthed( $website, 'settings_tools', array(
				'action' => 'force_destroy_sessions',
			) );
			global $mainWP;
			if ( ( '2.0.22' === $mainWP->getVersion() ) || ( '2.0.23' === $mainWP->getVersion() ) ) {
				if ( get_option( 'mainwp_fixed_security_2022' ) != 1 ) {
					update_option( 'mainwp_fixed_security_2022', 1 );
				}
			}
		} catch ( Exception $e ) {
			$information = array( 'error' => __( 'fetchUrlAuthed exception', 'mainwp' ) );
		}

		// die( json_encode( $information ) );
		wp_send_json( $information );
	}

}
