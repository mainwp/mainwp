<?php
namespace MainWP\Dashboard;

/**
 * MainWP Post Handler
 */
class MainWP_Post_Handler {

	protected $security_nonces;
	private static $instance = null;

	public function __construct() {
	}

	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new MainWP_Post_Handler();
		}
		return self::$instance;
	}

	public function init() {
		// Page: ManageBackups
		$this->add_action( 'mainwp_addbackup', array( &$this, 'mainwp_addbackup' ) );
		if ( mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
			$this->add_action( 'mainwp_updatebackup', array( &$this, 'mainwp_updatebackup' ) );
		}
		if ( mainwp_current_user_can( 'dashboard', 'delete_backup_tasks' ) ) {
			$this->add_action( 'mainwp_removebackup', array( &$this, 'mainwp_removebackup' ) );
		}
		$this->add_action( 'mainwp_pausebackup', array( &$this, 'mainwp_pausebackup' ) );
		$this->add_action( 'mainwp_resumebackup', array( &$this, 'mainwp_resumebackup' ) );

		$this->add_action( 'mainwp_backuptask_get_sites', array( &$this, 'mainwp_backuptask_get_sites' ) ); // ok

		if ( mainwp_current_user_can( 'dashboard', 'run_backup_tasks' ) ) {
			$this->add_action( 'mainwp_backuptask_run_site', array( &$this, 'mainwp_backuptask_run_site' ) );
		}
		$this->add_action( 'mainwp_backup_upload_file', array( &$this, 'mainwp_backup_upload_file' ) );

		// Page: ManageSites
		$this->add_action( 'mainwp_checkwp', array( &$this, 'mainwp_checkwp' ) );
		$this->add_action( 'mainwp_addwp', array( &$this, 'mainwp_addwp' ) );
		$this->add_action( 'mainwp_get_site_icon', array( &$this, 'get_site_icon' ) );
		$this->add_action( 'mainwp_ext_prepareinstallplugintheme', array( &$this, 'mainwp_ext_prepareinstallplugintheme' ) );
		$this->add_action( 'mainwp_ext_performinstallplugintheme', array( &$this, 'mainwp_ext_performinstallplugintheme' ) );
		$this->add_action( 'mainwp_ext_applypluginsettings', array( &$this, 'mainwp_ext_applypluginsettings' ) );

		if ( mainwp_current_user_can( 'dashboard', 'test_connection' ) ) {
			$this->add_action( 'mainwp_testwp', array( &$this, 'mainwp_testwp' ) );
		}

		$this->add_action( 'mainwp_removesite', array( &$this, 'mainwp_removesite' ) );
		$this->add_action( 'mainwp_notes_save', array( &$this, 'mainwp_notes_save' ) );
		$this->add_action( 'mainwp_reconnectwp', array( &$this, 'mainwp_reconnectwp' ) ); // ok
		$this->add_action( 'mainwp_updatechildsite_value', array( &$this, 'mainwp_updatechildsite_value' ) ); // ok
		// Page: ManageGroups
		$this->add_action( 'mainwp_group_rename', array( &$this, 'mainwp_group_rename' ) );
		$this->add_action( 'mainwp_group_delete', array( &$this, 'mainwp_group_delete' ) ); // ok
		$this->add_action( 'mainwp_group_add', array( &$this, 'mainwp_group_add' ) );
		$this->add_action( 'mainwp_group_getsites', array( &$this, 'mainwp_group_getsites' ) ); // ok
		$this->add_action( 'mainwp_group_updategroup', array( &$this, 'mainwp_group_updategroup' ) );

		// Page: InstallPlugins/Themes
		$this->add_action(
			'mainwp_preparebulkinstallplugintheme', array(
				&$this,
				'mainwp_preparebulkinstallplugintheme',
			)
		); // ok
		$this->add_action(
			'mainwp_installbulkinstallplugintheme', array(
				&$this,
				'mainwp_installbulkinstallplugintheme',
			)
		);
		$this->add_action(
			'mainwp_preparebulkuploadplugintheme', array(
				&$this,
				'mainwp_preparebulkuploadplugintheme',
			)
		); // ok
		$this->add_action(
			'mainwp_installbulkuploadplugintheme', array(
				&$this,
				'mainwp_installbulkuploadplugintheme',
			)
		);
		$this->add_action( 'mainwp_cleanbulkuploadplugintheme', array( &$this, 'mainwp_cleanbulkuploadplugintheme' ) );

		// Page: BulkAddUser
		$this->add_action( 'mainwp_bulkadduser', array( &$this, 'mainwp_bulkadduser' ) );
		$this->add_action( 'mainwp_importuser', array( &$this, 'mainwp_importuser' ) );

		// Widget: RightNow
		$this->add_action( 'mainwp_syncsites', array( &$this, 'mainwp_syncsites' ) );
		$this->add_action( 'mainwp_upgradewp', array( &$this, 'mainwp_upgradewp' ) );
		$this->add_action( 'mainwp_upgradeplugintheme', array( &$this, 'mainwp_upgrade_plugintheme' ) );
		$this->add_action( 'mainwp_ignoreplugintheme', array( &$this, 'mainwp_ignoreplugintheme' ) ); // ok
		$this->add_action( 'mainwp_unignoreplugintheme', array( &$this, 'mainwp_unignoreplugintheme' ) ); // ok
		$this->add_action( 'mainwp_ignorepluginsthemes', array( &$this, 'mainwp_ignorepluginsthemes' ) ); // ok
		$this->add_action(
			'mainwp_unignorepluginsthemes', array(
				&$this,
				'mainwp_unignorepluginsthemes',
			)
		); // ok
		$this->add_action(
			'mainwp_unignoreabandonedplugintheme', array(
				&$this,
				'mainwp_unignoreabandonedplugintheme',
			)
		); // ok
		$this->add_action(
			'mainwp_unignoreabandonedpluginsthemes', array(
				&$this,
				'mainwp_unignoreabandonedpluginsthemes',
			)
		); // ok
		$this->add_action(
			'mainwp_dismissoutdateplugintheme', array(
				&$this,
				'mainwp_dismissoutdateplugintheme',
			)
		); // ok
		$this->add_action(
			'mainwp_dismissoutdatepluginsthemes', array(
				&$this,
				'mainwp_dismissoutdatepluginsthemes',
			)
		); // ok
		$this->add_action( 'mainwp_trust_plugin', array( &$this, 'mainwp_trust_plugin' ) );
		$this->add_action( 'mainwp_trust_theme', array( &$this, 'mainwp_trust_theme' ) );
		$this->add_action( 'mainwp_checkbackups', array( &$this, 'mainwp_checkbackups' ) );
		$this->add_action( 'mainwp_syncerrors_dismiss', array( &$this, 'mainwp_syncerrors_dismiss' ) );
		// Page: backup
		if ( mainwp_current_user_can( 'dashboard', 'run_backup_tasks' ) ) {
			$this->add_action( 'mainwp_backup_run_site', array( &$this, 'mainwp_backup_run_site' ) );
		}
		if ( mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
			$this->add_action( 'mainwp_backup', array( &$this, 'mainwp_backup' ) );
		}
		$this->add_action( 'mainwp_backup_checkpid', array( &$this, 'mainwp_backup_checkpid' ) );
		$this->add_action( 'mainwp_createbackup_getfilesize', array( &$this, 'mainwp_createbackup_getfilesize' ) );
		$this->add_action( 'mainwp_backup_download_file', array( &$this, 'mainwp_backup_download_file' ) );
		$this->add_action( 'mainwp_backup_delete_file', array( &$this, 'mainwp_backup_delete_file' ) );
		$this->add_action( 'mainwp_backup_getfilesize', array( &$this, 'mainwp_backup_getfilesize' ) );
		$this->add_action( 'mainwp_backup_upload_getprogress', array( &$this, 'mainwp_backup_upload_getprogress' ) );
		$this->add_action( 'mainwp_backup_upload_checkstatus', array( &$this, 'mainwp_backup_upload_checkstatus' ) );

		if ( mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) {
			// Page: SecurityIssues
			$this->add_action( 'mainwp_security_issues_request', array( &$this, 'mainwp_security_issues_request' ) ); // ok
			$this->add_action( 'mainwp_security_issues_fix', array( &$this, 'mainwp_security_issues_fix' ) ); // ok
			$this->add_action( 'mainwp_security_issues_unfix', array( &$this, 'mainwp_security_issues_unfix' ) ); // ok
		}

		$this->add_action( 'mainwp_notice_status_update', array( &$this, 'mainwp_notice_status_update' ) );
		$this->add_action( 'mainwp_dismiss_twit', array( &$this, 'mainwp_dismiss_twit' ) );
		$this->add_action( 'mainwp_dismiss_activate_notice', array( &$this, 'dismiss_activate_notice' ) );
		$this->add_action( 'mainwp_status_saving', array( &$this, 'mainwp_status_saving' ) );
		$this->add_action( 'mainwp_leftmenu_filter_group', array( &$this, 'mainwp_leftmenu_filter_group' ) );
		$this->add_action( 'mainwp_widgets_order', array( &$this, 'ajax_widgets_order' ) );
		$this->add_action( 'mainwp_save_settings', array( &$this, 'ajax_mainwp_save_settings' ) );

		$this->add_action(
			'mainwp_twitter_dashboard_action', array(
				&$this,
				'mainwp_twitter_dashboard_action',
			)
		); // ok

		// Page: Recent Posts
		if ( mainwp_current_user_can( 'dashboard', 'manage_posts' ) ) {
			$this->add_action( 'mainwp_post_unpublish', array( &$this, 'mainwp_post_unpublish' ) );
			$this->add_action( 'mainwp_post_publish', array( &$this, 'mainwp_post_publish' ) );
			$this->add_action( 'mainwp_post_trash', array( &$this, 'mainwp_post_trash' ) );
			$this->add_action( 'mainwp_post_delete', array( &$this, 'mainwp_post_delete' ) );
			$this->add_action( 'mainwp_post_restore', array( &$this, 'mainwp_post_restore' ) );
			$this->add_action( 'mainwp_post_approve', array( &$this, 'mainwp_post_approve' ) );
		}
		$this->add_action( 'mainwp_post_addmeta', array( MainWP_Post::get_class_name(), 'ajax_add_meta' ) );
		// Page: Pages
		if ( mainwp_current_user_can( 'dashboard', 'manage_pages' ) ) {
			$this->add_action( 'mainwp_page_unpublish', array( &$this, 'mainwp_page_unpublish' ) );
			$this->add_action( 'mainwp_page_publish', array( &$this, 'mainwp_page_publish' ) );
			$this->add_action( 'mainwp_page_trash', array( &$this, 'mainwp_page_trash' ) );
			$this->add_action( 'mainwp_page_delete', array( &$this, 'mainwp_page_delete' ) );
			$this->add_action( 'mainwp_page_restore', array( &$this, 'mainwp_page_restore' ) );
		}
		// Page: Users
		$this->add_action( 'mainwp_user_delete', array( &$this, 'mainwp_user_delete' ) );
		$this->add_action( 'mainwp_user_edit', array( &$this, 'mainwp_user_edit' ) );
		$this->add_action( 'mainwp_user_update_password', array( &$this, 'mainwp_user_update_password' ) );
		$this->add_action( 'mainwp_user_update_user', array( &$this, 'mainwp_user_update_user' ) );

		// Page: Posts
		$this->add_action( 'mainwp_posts_search', array( &$this, 'mainwp_posts_search' ) ); // ok
		$this->add_action( 'mainwp_get_categories', array( &$this, 'mainwp_get_categories' ) ); // ok
		$this->add_action( 'mainwp_post_get_edit', array( &$this, 'mainwp_post_get_edit' ) );

		// Page: Pages
		$this->add_action( 'mainwp_pages_search', array( &$this, 'mainwp_pages_search' ) ); // ok
		// Page: User
		$this->add_action( 'mainwp_users_search', array( &$this, 'mainwp_users_search' ) );

		// Page: Themes
		$this->add_action( 'mainwp_themes_search', array( &$this, 'mainwp_themes_search' ) );
		$this->add_action( 'mainwp_themes_search_all', array( &$this, 'mainwp_themes_search_all' ) );
		if ( mainwp_current_user_can( 'dashboard', 'activate_themes' ) ) {
			$this->add_action( 'mainwp_theme_activate', array( &$this, 'mainwp_theme_activate' ) );
		}
		if ( mainwp_current_user_can( 'dashboard', 'delete_themes' ) ) {
			$this->add_action( 'mainwp_theme_delete', array( &$this, 'mainwp_theme_delete' ) );
		}
		$this->add_action( 'mainwp_trusted_theme_notes_save', array( &$this, 'mainwp_trusted_theme_notes_save' ) );
		if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
			$this->add_action( 'mainwp_theme_ignore_updates', array( &$this, 'mainwp_theme_ignore_updates' ) );
		}

		// Page: Plugins
		$this->add_action( 'mainwp_plugins_search', array( &$this, 'mainwp_plugins_search' ) );
		$this->add_action( 'mainwp_plugins_search_all_active', array( &$this, 'mainwp_plugins_search_all_active' ) );

		if ( mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) {
			$this->add_action( 'mainwp_plugin_activate', array( &$this, 'mainwp_plugin_activate' ) );
			$this->add_action( 'mainwp_plugin_deactivate', array( &$this, 'mainwp_plugin_deactivate' ) );
		}
		if ( mainwp_current_user_can( 'dashboard', 'delete_plugins' ) ) {
			$this->add_action( 'mainwp_plugin_delete', array( &$this, 'mainwp_plugin_delete' ) );
		}

		if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
			$this->add_action( 'mainwp_plugin_ignore_updates', array( &$this, 'mainwp_plugin_ignore_updates' ) );
		}
		$this->add_action( 'mainwp_trusted_plugin_notes_save', array( &$this, 'mainwp_trusted_plugin_notes_save' ) );

		// Widget: Plugins
		$this->add_action( 'mainwp_widget_plugin_activate', array( &$this, 'mainwp_widget_plugin_activate' ) );
		$this->add_action( 'mainwp_widget_plugin_deactivate', array( &$this, 'mainwp_widget_plugin_deactivate' ) );
		$this->add_action( 'mainwp_widget_plugin_delete', array( &$this, 'mainwp_widget_plugin_delete' ) );

		// Widget: Themes
		$this->add_action( 'mainwp_widget_theme_activate', array( &$this, 'mainwp_widget_theme_activate' ) );
		$this->add_action( 'mainwp_widget_theme_delete', array( &$this, 'mainwp_widget_theme_delete' ) );

		$this->add_action( 'mainwp_events_notice_hide', array( &$this, 'mainwp_events_notice_hide' ) );
		$this->add_action( 'mainwp_showhide_sections', array( &$this, 'mainwp_showhide_sections' ) );
		$this->add_action( 'mainwp_saving_status', array( &$this, 'mainwp_saving_status' ) );
		$this->add_action( 'mainwp_autoupdate_and_trust_child', array( &$this, 'mainwp_autoupdate_and_trust_child' ) );
		$this->add_action( 'mainwp_installation_warning_hide', array( &$this, 'mainwp_installation_warning_hide' ) );
		$this->add_action( 'mainwp_force_destroy_sessions', array( &$this, 'mainwp_force_destroy_sessions' ) );
		$this->add_action( 'mainwp_recheck_http', array( &$this, 'mainwp_recheck_http' ) );
		$this->add_action( 'mainwp_ignore_http_response', array( &$this, 'mainwp_ignore_http_response' ) );
		$this->add_action( 'mainwp_disconnect_site', array( &$this, 'ajax_disconnect_site' ) );
		$this->add_action( 'mainwp_manage_display_rows', array( &$this, 'ajax_display_rows' ) );

		$this->add_security_nonce( 'mainwp-common-nonce' );

		MainWP_Extensions::init_ajax_handlers();

		$this->add_action( 'mainwp_childscan', array( &$this, 'mainwp_childscan' ) ); // ok
	}

	public function mainwp_childscan() {
		if ( $this->check_security( 'mainwp_childscan', 'security' ) ) {
			MainWP_Child_Scan::scan();
		} else {
			die( wp_json_encode( array( 'error' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	// Hide the installation warning
	public function mainwp_installation_warning_hide() {
		$this->secure_request( 'mainwp_installation_warning_hide' );

		update_option( 'mainwp_installation_warning_hide_the_notice', 'yes' );
		die( 'ok' );
	}

	/**
	 * Page: Users
	 */
	public function mainwp_users_search() {
		$this->secure_request( 'mainwp_users_search' );
		MainWP_Cache::init_session();
		MainWP_User::render_table( false, $_POST['role'], ( isset( $_POST['groups'] ) ? $_POST['groups'] : '' ), ( isset( $_POST['sites'] ) ? $_POST['sites'] : '' ), $_POST['search'] );
		die();
	}

	/**
	 * Page: Themes
	 */
	public function mainwp_themes_search() {
		$this->secure_request( 'mainwp_themes_search' );
		MainWP_Cache::init_session();
		$result = MainWP_Themes::render_table( $_POST['keyword'], $_POST['status'], ( isset( $_POST['groups'] ) ? $_POST['groups'] : '' ), ( isset( $_POST['sites'] ) ? $_POST['sites'] : '' ) );
		wp_send_json( $result );
	}

	public function mainwp_theme_activate() {
		$this->secure_request( 'mainwp_theme_activate' );

		MainWP_Themes::activate_theme();
		die();
	}

	public function mainwp_theme_delete() {
		$this->secure_request( 'mainwp_theme_delete' );

		MainWP_Themes::delete_themes();
		die();
	}

	public function mainwp_theme_ignore_updates() {
		$this->secure_request( 'mainwp_theme_ignore_updates' );

		MainWP_Themes::ignore_updates();
		die();
	}

	public function mainwp_themes_search_all() {
		$this->secure_request( 'mainwp_themes_search_all' );
		MainWP_Cache::init_session();
		MainWP_Themes::render_all_themes_table();
		die();
	}

	public function mainwp_trusted_theme_notes_save() {
		$this->secure_request( 'mainwp_trusted_theme_notes_save' );

		MainWP_Themes::save_trusted_theme_note();
		die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
	}

	/**
	 * Page: Plugins
	 */
	public function mainwp_plugins_search() {
		$this->secure_request( 'mainwp_plugins_search' );
		MainWP_Cache::init_session();
		$result = MainWP_Plugins::render_table( $_POST['keyword'], $_POST['status'], ( isset( $_POST['groups'] ) ? $_POST['groups'] : '' ), ( isset( $_POST['sites'] ) ? $_POST['sites'] : '' ) );
		wp_send_json( $result );
	}

	public function mainwp_plugins_search_all_active() {
		$this->secure_request( 'mainwp_plugins_search_all_active' );
		MainWP_Cache::init_session();
		MainWP_Plugins::render_all_active_table();
		die();
	}

	public function mainwp_plugin_activate() {
		$this->secure_request( 'mainwp_plugin_activate' );

		MainWP_Plugins::activate_plugins();
		die();
	}

	public function mainwp_plugin_deactivate() {
		$this->secure_request( 'mainwp_plugin_deactivate' );

		MainWP_Plugins::deactivate_plugins();
		die();
	}

	public function mainwp_plugin_delete() {
		$this->secure_request( 'mainwp_plugin_delete' );

		MainWP_Plugins::delete_plugins();
		die();
	}

	public function mainwp_plugin_ignore_updates() {
		$this->secure_request( 'mainwp_plugin_ignore_updates' );

		MainWP_Plugins::ignore_updates();
		die();
	}

	public function mainwp_trusted_plugin_notes_save() {
		$this->secure_request( 'mainwp_trusted_plugin_notes_save' );

		MainWP_Plugins::save_trusted_plugin_note();
		die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
	}

	/**
	 * Widget: Plugins
	 */
	public function mainwp_widget_plugin_activate() {
		$this->secure_request( 'mainwp_widget_plugin_activate' );
		MainWP_Widget_Plugins::activate_plugin();
	}

	public function mainwp_widget_plugin_deactivate() {
		$this->secure_request( 'mainwp_widget_plugin_deactivate' );
		MainWP_Widget_Plugins::deactivate_plugin();
	}

	public function mainwp_widget_plugin_delete() {
		$this->secure_request( 'mainwp_widget_plugin_delete' );
		MainWP_Widget_Plugins::delete_plugin();
	}

	/**
	 * Widget: Themes
	 */
	public function mainwp_widget_theme_activate() {
		$this->secure_request( 'mainwp_widget_theme_activate' );
		MainWP_Widget_Themes::activate_theme();
	}

	public function mainwp_widget_theme_delete() {
		$this->secure_request( 'mainwp_widget_theme_delete' );
		MainWP_Widget_Themes::delete_theme();
	}

	/**
	 * Page: Posts
	 */
	public function mainwp_posts_search() {
		$this->secure_request( 'mainwp_posts_search' );

		$post_type = ( isset( $_POST['post_type'] ) && 0 < strlen( trim( $_POST['post_type'] ) ) ? $_POST['post_type'] : 'post' );

		if ( isset( $_POST['maximum'] ) ) {
			MainWP_Utility::update_option( 'mainwp_maximumPosts', MainWP_Utility::ctype_digit( $_POST['maximum'] ) ? intval( $_POST['maximum'] ) : 50  );
		}

		MainWP_Cache::init_session();

		MainWP_Post::render_table( false, $_POST['keyword'], $_POST['dtsstart'], $_POST['dtsstop'], $_POST['status'], ( isset( $_POST['groups'] ) ? $_POST['groups'] : '' ), ( isset( $_POST['sites'] ) ? $_POST['sites'] : '' ), $_POST['postId'], $_POST['userId'], $post_type, $_POST['search_on'] );

		die();
	}

	public function mainwp_get_categories() {
		$this->secure_request( 'mainwp_get_categories' );
		MainWP_Post::get_categories();
		die();
	}

	public function mainwp_post_get_edit() {
		$this->secure_request( 'mainwp_post_get_edit' );
		MainWP_Post::get_post(); // to edit
		die();
	}

	/**
	 * Page: Pages
	 */
	public function mainwp_pages_search() {
		$this->secure_request( 'mainwp_pages_search' );
		if ( isset( $_POST['maximum'] ) ) {
			MainWP_Utility::update_option( 'mainwp_maximumPages', MainWP_Utility::ctype_digit( $_POST['maximum'] ) ? intval( $_POST['maximum'] ) : 50  );
		}

		MainWP_Cache::init_session();

		MainWP_Page::render_table( false, $_POST['keyword'], $_POST['dtsstart'], $_POST['dtsstop'], $_POST['status'], ( isset( $_POST['groups'] ) ? $_POST['groups'] : '' ), ( isset( $_POST['sites'] ) ? $_POST['sites'] : '' ), $_POST['search_on'] );
		die();
	}

	/**
	 * Page: Users
	 */
	public function mainwp_user_delete() {
		$this->secure_request( 'mainwp_user_delete' );

		MainWP_User::delete();
	}

	public function mainwp_user_edit() {
		$this->secure_request( 'mainwp_user_edit' );

		MainWP_User::edit();
	}

	public function mainwp_user_update_password() {
		$this->secure_request( 'mainwp_user_update_password' );

		MainWP_User::update_password();
	}

	public function mainwp_user_update_user() {
		$this->secure_request( 'mainwp_user_update_user' );

		MainWP_User::update_user();
	}

	/**
	 * Page: Recent Posts
	 */
	public function mainwp_post_unpublish() {
		$this->secure_request( 'mainwp_post_unpublish' );

		MainWP_Recent_Posts::unpublish();
	}

	public function mainwp_post_publish() {
		$this->secure_request( 'mainwp_post_publish' );

		MainWP_Recent_Posts::publish();
	}

	public function mainwp_post_approve() {
		$this->secure_request( 'mainwp_post_approve' );

		MainWP_Recent_Posts::approve();
	}

	public function mainwp_post_trash() {
		$this->secure_request( 'mainwp_post_trash' );

		MainWP_Recent_Posts::trash();
	}

	public function mainwp_post_delete() {
		$this->secure_request( 'mainwp_post_delete' );

		MainWP_Recent_Posts::delete();
	}

	public function mainwp_post_restore() {
		$this->secure_request( 'mainwp_post_restore' );

		MainWP_Recent_Posts::restore();
	}

	/**
	 * Page: Recent Pages
	 */
	public function mainwp_page_unpublish() {
		$this->secure_request( 'mainwp_page_unpublish' );

		MainWP_Page::unpublish();
	}

	public function mainwp_page_publish() {
		$this->secure_request( 'mainwp_page_publish' );

		MainWP_Page::publish();
	}

	public function mainwp_page_trash() {
		$this->secure_request( 'mainwp_page_trash' );

		MainWP_Page::trash();
	}

	public function mainwp_page_delete() {
		$this->secure_request( 'mainwp_page_delete' );

		MainWP_Page::delete();
	}

	public function mainwp_page_restore() {
		$this->secure_request( 'mainwp_page_restore' );

		MainWP_Page::restore();
	}

	// Hide after installtion notices (PHP version, Trust MainWP Child, Multisite Warning and OpenSSL warning)
	public function mainwp_notice_status_update() {
		$this->secure_request( 'mainwp_notice_status_update' );

		if ( 'mail_failed' === $_POST['notice_id'] ) {
			MainWP_Utility::update_option( 'mainwp_notice_wp_mail_failed', 'hide' );
			die( 'ok' );
		}

		global $current_user;
		$user_id = $current_user->ID;
		if ( $user_id ) {
			$status = get_user_option( 'mainwp_notice_saved_status' );
			if ( ! is_array( $status ) ) {
				$status = array();
			}
			$status[ $_POST['notice_id'] ] = 1;
			update_user_option( $user_id, 'mainwp_notice_saved_status', $status );
		}
		die( 1 );
	}

	public function mainwp_status_saving() {
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

	public function ajax_widgets_order() {

		$this->secure_request( 'mainwp_widgets_order' );
		$user = wp_get_current_user();
		if ( $user ) {
			update_user_option($user->ID, 'mainwp_widgets_sorted_' . $_POST['page'], ( isset($_POST['order']) ? $_POST['order'] : '' ), true);
			die( 'ok' );
		}
		die( -1 );
	}

	public function ajax_mainwp_save_settings() {
		$this->secure_request( 'mainwp_save_settings' );
		$option_name = 'mainwp_' . $_POST['name'];
		$val         = $_POST['value'];

		MainWP_Utility::update_option( $option_name, $val );

		die( 'ok' );
	}

	public function mainwp_leftmenu_filter_group() {
		$this->secure_request( 'mainwp_leftmenu_filter_group' );
		if ( isset( $_POST['group_id'] ) && ! empty( $_POST['group_id'] ) ) {
			$ids      = '';
			$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $_POST['group_id'], true ) );
			while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
				$ids .= $website->id . ',';
			}
			MainWP_DB::free_result( $websites );
			$ids = rtrim( $ids, ',' );
			die( $ids );
		}
		die( '' );
	}

	public function mainwp_dismiss_twit() {
		$this->secure_request( 'mainwp_dismiss_twit' );

		global $current_user;
		$user_id = $current_user->ID;
		if ( $user_id && isset( $_POST['twitId'] ) && ! empty( $_POST['twitId'] ) && isset( $_POST['what'] ) && ! empty( $_POST['what'] ) ) {
			MainWP_Twitter::clear_twitter_info( $_POST['what'], $_POST['twitId'] );
		}
		die( 1 );
	}

	public function dismiss_activate_notice() {
		$this->secure_request( 'mainwp_dismiss_activate_notice' );

		global $current_user;
		$user_id = $current_user->ID;
		if ( $user_id && isset( $_POST['slug'] ) && ! empty( $_POST['slug'] ) ) {
			$activate_notices = get_user_option( 'mainwp_hide_activate_notices' );
			if ( ! is_array( $activate_notices ) ) {
				$activate_notices = array();
			}
			$activate_notices[ $_POST['slug'] ] = time();
			update_user_option( $user_id, 'mainwp_hide_activate_notices', $activate_notices );
		}
		die( 1 );
	}

	public function mainwp_twitter_dashboard_action() {
		$this->secure_request( 'mainwp_twitter_dashboard_action' );

		$success = false;
		if ( isset( $_POST['actionName'] ) && isset( $_POST['countSites'] ) && ! empty( $_POST['countSites'] ) ) {
			$success = MainWP_Twitter::update_twitter_info( $_POST['actionName'], $_POST['countSites'], (int) $_POST['countSeconds'], ( isset( $_POST['countRealItems'] ) ? $_POST['countRealItems'] : 0 ), time(), ( isset( $_POST['countItems'] ) ? $_POST['countItems'] : 0 ) );
		}

		if ( isset( $_POST['showNotice'] ) && ! empty( $_POST['showNotice'] ) ) {
			if ( MainWP_Twitter::enabled_twitter_messages() ) {
				$twitters = MainWP_Twitter::get_twitter_notice( $_POST['actionName'] );
				$html     = '';
				if ( is_array( $twitters ) ) {
					foreach ( $twitters as $timeid => $twit_mess ) {
						if ( ! empty( $twit_mess ) ) {
							$sendText = MainWP_Twitter::get_twit_to_send( $_POST['actionName'], $timeid );
							$html    .= '<div class="mainwp-tips mainwp-notice mainwp-notice-blue twitter"><span class="mainwp-tip" twit-what="' . esc_attr($_POST['actionName']) . '" twit-id="' . $timeid . '">' . $twit_mess . '</span>&nbsp;' . MainWP_Twitter::gen_twitter_button( $sendText, false ) . '<span><a href="#" class="mainwp-dismiss-twit mainwp-right" ><i class="fa fa-times-circle"></i> ' . __( 'Dismiss', 'mainwp' ) . '</a></span></div>';
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

	/**
	 * not used
	 */
	public function mainwp_reset_usercookies() {
		$this->secure_request();

		global $current_user;
		$user_id = $current_user->ID;
		if ( $user_id && isset( $_POST['what'] ) && ! empty( $_POST['what'] ) ) {
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
	public function mainwp_security_issues_request() {
		$this->secure_request( 'mainwp_security_issues_request' );

		try {
			wp_send_json( array( 'result' => MainWP_Security_Issues::fetch_security_issues() ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_security_issues_fix() {
		$this->secure_request( 'mainwp_security_issues_fix' );

		try {
			wp_send_json( array( 'result' => MainWP_Security_Issues::fix_security_issue() ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_security_issues_unfix() {
		$this->secure_request( 'mainwp_security_issues_unfix' );

		try {
			wp_send_json( array( 'result' => MainWP_Security_Issues::unfix_security_issue() ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}


	public function ajax_disconnect_site() {
		$this->secure_request( 'mainwp_disconnect_site' );

		$siteid = $_POST['wp_id'];

		if ( empty( $siteid ) ) {
			die( wp_json_encode( array( 'error' => 'Error: site id empty' ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $siteid );

		if ( ! $website ) {
			die( wp_json_encode( array( 'error' => 'Not found site' ) ) );
		}

		try {
			$information = MainWP_Utility::fetch_url_authed( $website, 'disconnect');
		} catch ( Exception $e ) {
			$information = array( 'error' => __( 'fetch_url_authed exception', 'mainwp' ) );
		}

		wp_send_json( $information );
	}

	public function ajax_display_rows() {
		$this->secure_request( 'mainwp_manage_display_rows' );
		MainWP_Manage_Sites::display_rows();
	}

	/*
	 * Page: Backup
	 */
	public function mainwp_backup_run_site() {
		$this->secure_request( 'mainwp_backup_run_site' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			$ret = array( 'result' => MainWP_Manage_Sites::backup( $_POST['site_id'], 'full', '', '', 1, 1, 1, 1 ) );
			wp_send_json($ret);
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_backup() {
		$this->secure_request( 'mainwp_backup' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			$excludedFolder = trim( $_POST['exclude'], "\n" );
			$excludedFolder = explode( "\n", $excludedFolder );
			$excludedFolder = array_map( array( 'MainWP_Utility', 'trim_slashes' ), $excludedFolder );
			$excludedFolder = array_map( 'htmlentities', $excludedFolder );
			$excludedFolder = implode( ',', $excludedFolder );

			$result = MainWP_Manage_Sites::backup( $_POST['site_id'], $_POST['type'], ( isset( $_POST['subfolder'] ) ? $_POST['subfolder'] : '' ), $excludedFolder, $_POST['excludebackup'], $_POST['excludecache'], $_POST['excludenonwp'], $_POST['excludezip'], $_POST['filename'], isset( $_POST['fileNameUID'] ) ? $_POST['fileNameUID'] : '', $_POST['archiveFormat'], ( isset($_POST['maximumFileDescriptorsOverride']) && 1 === $_POST['maximumFileDescriptorsOverride'] ), ( 1 === $_POST['maximumFileDescriptorsAuto'] ), ( isset($_POST['maximumFileDescriptors']) ? $_POST['maximumFileDescriptors'] : '' ), ( isset( $_POST['loadFilesBeforeZip'] ) ? $_POST['loadFilesBeforeZip'] : '' ), $_POST['pid'], ( isset( $_POST['append'] ) && ( 1 === $_POST['append'] ) ) );
			wp_send_json( array( 'result' => $result ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_backup_checkpid() {
		$this->secure_request( 'mainwp_backup_checkpid' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			wp_send_json( MainWP_Manage_Sites::backup_check_pid( $_POST['site_id'], $_POST['pid'], $_POST['type'], ( isset($_POST['subfolder']) ? $_POST['subfolder'] : '' ), $_POST['filename'] )  );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_backup_download_file() {
		$this->secure_request( 'mainwp_backup_download_file' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			die( wp_json_encode( array( 'result' => MainWP_Manage_Sites::backup_download_file( $_POST['site_id'], $_POST['type'], $_POST['url'], $_POST['local'] ) ) ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_backup_delete_file() {
		$this->secure_request( 'mainwp_backup_delete_file' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( __( 'Invalid request!', 'mainwp' ) );
			}

			die( wp_json_encode( array( 'result' => MainWP_Manage_Sites::backup_delete_file( $_POST['site_id'], $_POST['file'] ) ) ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_createbackup_getfilesize() {
		$this->secure_request( 'mainwp_createbackup_getfilesize' );

		try {
			if ( ! isset( $_POST['siteId'] ) ) {
				throw new Exception( __( 'No site selected!', 'mainwp' ) );
			}
			$siteId      = $_POST['siteId'];
			$fileName    = $_POST['fileName'];
			$fileNameUID = $_POST['fileNameUID'];
			$type        = $_POST['type'];

			$website = MainWP_DB::instance()->get_website_by_id( $siteId );
			if ( ! $website ) {
				throw new Exception( __( 'No site selected!', 'mainwp' ) );
			}

			MainWP_Utility::end_session();
			// Send request to the childsite!
			$result = MainWP_Utility::fetch_url_authed(
				$website, 'createBackupPoll', array(
					'fileName'       => $fileName,
					'fileNameUID'    => $fileNameUID,
					'type'           => $type,
				)
			);

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

		die( wp_json_encode( $output ) );
	}

	public function mainwp_backup_getfilesize() {
		$this->secure_request( 'mainwp_backup_getfilesize' );

		try {
			die( wp_json_encode( array( 'result' => MainWP_Manage_Sites::backupGetFilesize( $_POST['local'] ) ) ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_backup_upload_checkstatus() {
		$this->secure_request( 'mainwp_backup_upload_checkstatus' );

		try {
			$array = get_option( 'mainwp_upload_progress' );
			$info  = apply_filters( 'mainwp_remote_destination_info', array(), $_POST['remote_destination'] );

			if ( ! is_array( $array ) || ! isset( $array[ $_POST['unique'] ] ) || ! isset( $array[ $_POST['unique'] ]['dts'] ) ) {
				die(
					wp_json_encode(
						array(
							'status' => 'stalled',
							'info'   => $info,
						)
					)
				);
			} elseif ( isset( $array[ $_POST['unique'] ]['finished'] ) ) {
				die(
					wp_json_encode(
						array(
							'status' => 'done',
							'info'   => $info,
						)
					)
				);
			} else {
				if ( $array[ $_POST['unique'] ]['dts'] < ( time() - ( 2 * 60 ) ) ) { // 2minutes
					die(
						wp_json_encode(
							array(
								'status' => 'stalled',
								'info'   => $info,
							)
						)
					);
				} else {
					die(
						wp_json_encode(
							array(
								'status' => 'busy',
								'info'   => $info,
							)
						)
					);
				}
			}
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_backup_upload_getprogress() {
		$this->secure_request( 'mainwp_backup_upload_getprogress' );

		try {
			$array = get_option( 'mainwp_upload_progress' );

			if ( ! is_array( $array ) || ! isset( $array[ $_POST['unique'] ] ) ) {
				die( wp_json_encode( array( 'result' => 0 ) ) );
			} elseif ( isset( $array[ $_POST['unique'] ]['finished'] ) ) {
				throw new MainWP_Exception( __( 'finished...', 'maiwnp' ) );
			} else {
				wp_send_json( array( 'result' => ( isset( $array[ $_POST['unique'] ]['offset'] ) ? $array[ $_POST['unique'] ]['offset'] : $array[ $_POST['unique'] ] ) )  );
			}
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	/*
	 * Page: BulkAddUser
	 */

	public function mainwp_bulkadduser() {
		if ( ! $this->check_security( 'mainwp_bulkadduser' ) ) {
			die( 'ERROR ' . wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		MainWP_User::do_buk_add();
		die();
	}

	public function mainwp_importuser() {
		$this->secure_request( 'mainwp_importuser' );

		MainWP_User::do_import();
	}

	/*
	 * Page: InstallPlugins/Themes
	 */

	public function mainwp_preparebulkinstallplugintheme() {
		$this->secure_request( 'mainwp_preparebulkinstallplugintheme' );

		MainWP_Install_Bulk::prepare_install();
	}

	public function mainwp_installbulkinstallplugintheme() {
		$this->secure_request( 'mainwp_installbulkinstallplugintheme' );

		MainWP_Install_Bulk::perform_install();
	}

	public function mainwp_preparebulkuploadplugintheme() {
		$this->secure_request( 'mainwp_preparebulkuploadplugintheme' );

		MainWP_Install_Bulk::prepare_upload();
	}

	public function mainwp_installbulkuploadplugintheme() {
		$this->secure_request( 'mainwp_installbulkuploadplugintheme' );

		MainWP_Install_Bulk::perform_upload();
	}

	public function mainwp_cleanbulkuploadplugintheme() {
		$this->secure_request( 'mainwp_cleanbulkuploadplugintheme' );

		MainWP_Install_Bulk::clean_upload();
	}

	/*
	 * Page: ManageGroups
	 */

	public function mainwp_group_rename() {
		$this->secure_request( 'mainwp_group_rename' );

		MainWP_Manage_Groups::renameGroup();
	}

	public function mainwp_group_delete() {
		$this->secure_request( 'mainwp_group_delete' );

		MainWP_Manage_Groups::deleteGroup();
	}

	public function mainwp_group_add() {
		$this->secure_request( 'mainwp_group_add' );

		MainWP_Manage_Groups::add_group();
	}

	public function mainwp_group_getsites() {
		$this->secure_request( 'mainwp_group_getsites' );

		die( MainWP_Manage_Groups::getSites() );
	}

	public function mainwp_group_updategroup() {
		$this->secure_request( 'mainwp_group_updategroup' );

		MainWP_Manage_Groups::update_group();
	}

	/*
	 * Page: ManageBackups
	 */

	// Add task to the database
	public function mainwp_addbackup() {
		$this->secure_request( 'mainwp_addbackup' );

		MainWP_Manage_Backups::addBackup();
	}

	// Update task
	public function mainwp_updatebackup() {
		$this->secure_request( 'mainwp_updatebackup' );

		MainWP_Manage_Backups::updateBackup();
	}

	// Remove a task from MainWP
	public function mainwp_removebackup() {
		$this->secure_request( 'mainwp_removebackup' );

		MainWP_Manage_Backups::removeBackup();
	}

	public function mainwp_resumebackup() {
		$this->secure_request( 'mainwp_resumebackup' );

		MainWP_Manage_Backups::resumeBackup();
	}

	public function mainwp_pausebackup() {
		$this->secure_request( 'mainwp_pausebackup' );

		MainWP_Manage_Backups::pauseBackup();
	}

	public function mainwp_backuptask_get_sites() {
		$this->secure_request( 'mainwp_backuptask_get_sites' );

		$taskID = $_POST['task_id'];

		wp_send_json( array( 'result' => MainWP_Manage_Backups::getBackupTaskSites( $taskID ) ) );
	}

	public function mainwp_backuptask_run_site() {
		try {
			$this->secure_request( 'mainwp_backuptask_run_site' );

			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) || ! isset( $_POST['task_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['task_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			wp_send_json( array( 'result' => MainWP_Manage_Backups::backup( $_POST['task_id'], $_POST['site_id'], $_POST['fileNameUID'] ) ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_backup_upload_file() {
		try {
			$this->secure_request( 'mainwp_backup_upload_file' );

			do_action( 'mainwp_remote_backup_extension_backup_upload_file' );

			throw new MainWP_Exception( __( 'Remote Backup extension has not been installed or activated.', 'mainwp' ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	/*
	 * Page: ManageSites
	 */

	// Check if WP can be added
	public function mainwp_checkwp() {
		if ( $this->check_security( 'mainwp_checkwp', 'security' ) ) {
			MainWP_Manage_Sites::check_site();
		} else {
			die( wp_json_encode( array( 'response' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	// Add WP to the database
	public function mainwp_addwp() {
		if ( $this->check_security( 'mainwp_addwp', 'security' ) ) {
			MainWP_Manage_Sites::add_site();
		} else {
			die( wp_json_encode( array( 'response' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	public function get_site_icon() {
		if ( $this->check_security( 'mainwp_get_site_icon', 'security' ) ) {
			$siteId = null;
			if ( isset( $_POST['siteId'] ) ) {
				$siteId = intval( $_POST['siteId'] );
			}
			$result = MainWP_System::sync_site_icon( $siteId );
			wp_send_json( $result );
		} else {
			die( wp_json_encode( array( 'error' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	public function mainwp_ext_prepareinstallplugintheme() {

		do_action( 'mainwp_prepareinstallplugintheme' );
	}

	public function mainwp_ext_performinstallplugintheme() {

		do_action( 'mainwp_performinstallplugintheme' );
	}

	public function mainwp_ext_applypluginsettings() {
		if ( $this->check_security( 'mainwp_ext_applypluginsettings', 'security' ) ) {
			MainWP_Manage_Sites::apply_plugin_settings();
		} else {
			die( wp_json_encode( array( 'error' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	public function mainwp_testwp() {
		$this->secure_request( 'mainwp_testwp' );

		$url               = null;
		$name              = null;
		$http_user         = null;
		$http_pass         = null;
		$verifyCertificate = 1;
		$sslVersion        = 0;

		if ( isset( $_POST['url'] ) ) {
			$url = $_POST['url'];

			$temp_url = MainWP_Utility::remove_http_prefix( $url, true );

			if ( strpos( $temp_url, ':' ) ) {
				die( wp_json_encode( array( 'error' => __( 'Invalid URL.', 'mainwp' ) ) ) );
			}

			$verifyCertificate = $_POST['test_verify_cert'];
			$forceUseIPv4      = $_POST['test_force_use_ipv4'];
			$sslVersion        = $_POST['test_ssl_version'];
			$http_user         = $_POST['http_user'];
			$http_pass         = $_POST['http_pass'];

		} elseif ( isset( $_POST['siteid'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $_POST['siteid'] );
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

		$rslt = MainWP_Utility::try_visit( $url, $verifyCertificate, $http_user, $http_pass, $sslVersion, $forceUseIPv4 );

		if ( isset( $rslt['error'] ) && ( '' !== $rslt['error'] ) && ( 'wp-admin/' !== substr( $url, - 9 ) ) ) {
			if ( substr( $url, - 1 ) != '/' ) {
				$url .= '/';
			}
			$url    .= 'wp-admin/';
			$newrslt = MainWP_Utility::try_visit( $url, $verifyCertificate, $http_user, $http_pass, $sslVersion, $forceUseIPv4 );
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
	public function mainwp_removesite() {
		if ( ! mainwp_current_user_can( 'dashboard', 'delete_sites' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'delete sites', 'mainwp' ), false ) ) ) );
		}

		$this->secure_request( 'mainwp_removesite' );

		MainWP_Manage_Sites::remove_site();
	}

	// Save note
	public function mainwp_notes_save() {
		$this->secure_request( 'mainwp_notes_save' );

		MainWP_Manage_Sites::save_note();
	}

	public function mainwp_reconnectwp() {
		$this->secure_request( 'mainwp_reconnectwp' );

		MainWP_Manage_Sites::reconnect_site();
	}

	public function mainwp_updatechildsite_value() {
		$this->secure_request( 'mainwp_updatechildsite_value' );

		MainWP_Manage_Sites::update_child_site_value();
	}

	/*
	 * Widget: RightNow
	 */

	public function mainwp_syncsites() {
		$this->secure_request( 'mainwp_syncsites' );
		MainWP_Updates_Overview::dismiss_sync_errors( false );
		MainWP_Updates_Overview::sync_site();
	}

	// Update a specific WP
	public function mainwp_upgradewp() {
		if ( ! mainwp_current_user_can( 'dashboard', 'update_wordpress' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'update WordPress', 'mainwp' ), $echo = false ) ) ) );
		}

		$this->secure_request( 'mainwp_upgradewp' );

		try {
			$id = null;
			if ( isset( $_POST['id'] ) ) {
				$id = $_POST['id'];
			}
			die( wp_json_encode( array( 'result' => MainWP_Updates::upgrade_site( $id ) ) ) ); // ok
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_upgrade_plugintheme() {

		if ( ! isset($_POST['type'] ) ) {
			die( wp_json_encode( array( 'error' => '<i class="red times icon"></i> ' . __( 'Invalid request', 'mainwp' ) ) ) );
		}

		if ( 'plugin' === $_POST['type'] && ! mainwp_current_user_can( 'dashboard', 'update_plugins' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'update plugins', 'mainwp' ), $echo = false ) ) ) );
		}

		if ( 'theme' === $_POST['type'] && ! mainwp_current_user_can( 'dashboard', 'update_themes' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'update themes', 'mainwp' ), $echo = false ) ) ) );
		}

		if ( 'translation' === $_POST['type'] && ! mainwp_current_user_can( 'dashboard', 'update_translations' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'update translations', 'mainwp' ), $echo = false ) ) ) );
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
					$slugs = MainWP_Updates::get_plugin_theme_slugs( $websiteId, $_POST['type'] );
				}
			} elseif ( isset( $_POST['slug'] ) ) {
				$slugs = $_POST['slug'];
			} else {
				$slugs = MainWP_Updates::get_plugin_theme_slugs( $websiteId, $_POST['type'] );
			}
		}

		if ( MainWP_DB::instance()->backup_full_task_running( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => __( 'Backup process in progress on the child site. Please, try again later.', 'mainwp' ) ) ) );
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
			die( wp_json_encode( array( 'message' => __( 'Item slug could not be found. Update process could not be executed.', 'mainwp' ) ) ) );
		}
		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		try {
			$info = array( 'result' => MainWP_Updates::upgrade_plugin_theme_translation( $websiteId, $_POST['type'], $slugs ) );

			if ( $chunk_support && ( 0 < count( $chunk_slugs ) ) ) {
				$info['chunk_slugs'] = implode( ',', $chunk_slugs );
			}

			if ( ! empty( $website ) ) {
				$info['site_url'] = esc_url( $website->url );
			}
			wp_send_json( $info );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message' => $e->getMessage(),
							'extra'   => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_ignoreplugintheme() {
		$this->secure_request( 'mainwp_ignoreplugintheme' );

		if ( ! isset( $_POST['id'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		wp_send_json( array( 'result' => MainWP_Updates::ignore_plugin_theme( $_POST['type'], $_POST['slug'], $_POST['name'], $_POST['id'] ) ) );
	}

	public function mainwp_unignoreabandonedplugintheme() {
		$this->secure_request( 'mainwp_unignoreabandonedplugintheme' );

		if ( ! isset( $_POST['id'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates::unignore_abandoned_plugin_theme( $_POST['type'], $_POST['slug'], $_POST['id'] ) ) ) ); // ok
	}

	public function mainwp_unignoreabandonedpluginsthemes() {
		$this->secure_request( 'mainwp_unignoreabandonedpluginsthemes' );

		if ( ! isset( $_POST['slug'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates::unignore_abandoned_plugins_themes( $_POST['type'], $_POST['slug'] ) ) ) );
	}

	public function mainwp_dismissoutdateplugintheme() {
		$this->secure_request( 'mainwp_dismissoutdateplugintheme' );

		if ( ! isset( $_POST['id'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates::dismiss_plugin_theme( $_POST['type'], $_POST['slug'], $_POST['name'], $_POST['id'] ) ) ) );
	}

	public function mainwp_dismissoutdatepluginsthemes() {
		$this->secure_request( 'mainwp_dismissoutdatepluginsthemes' );

		if ( ! mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'ignore/unignore updates', 'mainwp' ) ) ) ) );
		}

		if ( ! isset( $_POST['slug'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates::dismiss_plugins_themes( $_POST['type'], $_POST['slug'], $_POST['name'] ) ) ) );
	}

	public function mainwp_unignoreplugintheme() {
		$this->secure_request( 'mainwp_unignoreplugintheme' );

		if ( ! isset( $_POST['id'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates::unignore_plugin_theme( $_POST['type'], $_POST['slug'], $_POST['id'] ) ) ) );
	}

	public function mainwp_ignorepluginsthemes() {
		$this->secure_request( 'mainwp_ignorepluginsthemes' );

		if ( ! mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'ignore/unignore updates', 'mainwp' ) ) ) ) );
		}

		if ( ! isset( $_POST['slug'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates::ignore_plugins_themes( $_POST['type'], $_POST['slug'], $_POST['name'] ) ) ) );
	}

	public function mainwp_unignorepluginsthemes() {
		$this->secure_request( 'mainwp_unignorepluginsthemes' );

		if ( ! isset( $_POST['slug'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates::unignore_plugins_themes( $_POST['type'], $_POST['slug'] ) ) ) );
	}

	public function mainwp_trust_plugin() {
		$this->secure_request( 'mainwp_trust_plugin' );

		MainWP_Plugins::trust_post();
		die( wp_json_encode( array( 'result' => true ) ) );
	}

	public function mainwp_trust_theme() {
		$this->secure_request( 'mainwp_trust_theme' );

		MainWP_Themes::trust_post();
		die( wp_json_encode( array( 'result' => true ) ) );
	}

	public function mainwp_checkbackups() {
		$this->secure_request( 'mainwp_checkbackups' );

		try {
			wp_send_json( array( 'result' => MainWP_Updates_Overview::check_backups() ) );
		} catch ( Exception $e ) {
			die( wp_json_encode( array( 'error' => $e->getMessage() ) ) );
		}
	}

	public function mainwp_syncerrors_dismiss() {
		$this->secure_request( 'mainwp_syncerrors_dismiss' );

		try {
			die( wp_json_encode( array( 'result' => MainWP_Updates_Overview::dismiss_sync_errors() ) ) );
		} catch ( Exception $e ) {
			die( wp_json_encode( array( 'error' => $e->getMessage() ) ) );
		}
	}


	public function mainwp_events_notice_hide() {
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

	public function mainwp_showhide_sections() {
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

	public function mainwp_saving_status() {
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

	public function mainwp_recheck_http() {
		if ( ! $this->check_security( 'mainwp_recheck_http' ) ) {
			die( wp_json_encode( array( 'error' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}

		if ( ! isset( $_POST['websiteid'] ) || empty( $_POST['websiteid'] ) ) {
			die( -1 );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $_POST['websiteid'] );
		if ( empty( $website ) ) {
			die( -1 );
		}

		$result       = MainWP_Utility::is_website_available( $website );
		$http_code    = ( is_array( $result ) && isset( $result['httpCode'] ) ) ? $result['httpCode'] : 0;
		$check_result = MainWP_Utility::check_ignored_http_code( $http_code );
		MainWP_DB::instance()->update_website_values(
			$website->id, array(
				'offline_check_result'   => $check_result ? '1' : '-1',
				'offline_checks_last'    => time(),
				'http_response_code'     => $http_code,
			)
		);
		die(
			wp_json_encode(
				array(
					'httpcode' => esc_html($http_code),
					'status'   => $check_result ? 1 : 0,
				)
			)
		);
	}

	public function mainwp_ignore_http_response() {
		if ( ! $this->check_security( 'mainwp_ignore_http_response' ) ) {
			die( wp_json_encode( array( 'error' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}

		if ( ! isset( $_POST['websiteid'] ) || empty( $_POST['websiteid'] ) ) {
			die( -1 );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $_POST['websiteid'] );
		if ( empty( $website ) ) {
			die( -1 );
		}

		MainWP_DB::instance()->update_website_values( $website->id, array( 'http_response_code' => '-1' ) );
		die( wp_json_encode( array( 'ok' => 1 ) ) );
	}

	public function mainwp_autoupdate_and_trust_child() {
		$this->secure_request( 'mainwp_autoupdate_and_trust_child' );
		if ( get_option( 'mainwp_automaticDailyUpdate' ) != 1 ) {
			update_option( 'mainwp_automaticDailyUpdate', 1 );
		}
		MainWP_Plugins::trust_plugin( 'mainwp-child/mainwp-child.php' );
		die( 'ok' );
	}

	public function secure_request( $action = '', $query_arg = 'security' ) {
		if ( ! MainWP_Utility::is_admin() ) {
			die( 0 );
		}
		if ( '' === $action ) {
			return;
		}

		if ( ! $this->check_security( $action, $query_arg ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}

		if ( isset( $_POST['dts'] ) ) {
			$ajaxPosts = get_option( 'mainwp_ajaxposts' );
			if ( ! is_array( $ajaxPosts ) ) {
				$ajaxPosts = array();
			}

			// If already processed, just quit!
			if ( isset( $ajaxPosts[ $action ] ) && ( $ajaxPosts[ $action ] == $_POST['dts'] ) ) {
				die( wp_json_encode( array( 'error' => __( 'Double request!', 'mainwp' ) ) ) );
			}

			$ajaxPosts[ $action ] = $_POST['dts'];
			MainWP_Utility::update_option( 'mainwp_ajaxposts', $ajaxPosts );
		}
	}

	public function check_security( $action = - 1, $query_arg = 'security' ) {
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

	public function add_action( $action, $callback ) {
		add_action( 'wp_ajax_' . $action, $callback );
		$this->add_security_nonce( $action );
	}

	public function add_security_nonce( $action ) {
		if ( ! is_array( $this->security_nonces ) ) {
			$this->security_nonces = array();
		}

		if ( ! function_exists( 'wp_create_nonce' ) ) {
			include_once ABSPATH . WPINC . '/pluggable.php';
		}
		$this->security_nonces[ $action ] = wp_create_nonce( $action );
	}

	public function get_security_nonces() {
		return $this->security_nonces;
	}

	public function mainwp_force_destroy_sessions() {
		$this->secure_request( 'mainwp_force_destroy_sessions' );

		$website_id = ( isset( $_POST['website_id'] ) ? (int) $_POST['website_id'] : 0 );

		if ( ! MainWP_DB::instance()->get_website_by_id( $website_id ) ) {
			die( wp_json_encode( array( 'error' => array( 'message' => __( 'This website does not exist.', 'mainwp' ) ) ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $website_id );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => array( 'message' => __( 'You cannot edit this website.', 'mainwp' ) ) ) ) );
		}

		try {
			$information = MainWP_Utility::fetch_url_authed(
				$website, 'settings_tools', array(
					'action' => 'force_destroy_sessions',
				)
			);
			global $mainWP;
			if ( ( '2.0.22' === $mainWP->get_version() ) || ( '2.0.23' === $mainWP->get_version() ) ) {
				if ( get_option( 'mainwp_fixed_security_2022' ) != 1 ) {
					update_option( 'mainwp_fixed_security_2022', 1 );
				}
			}
		} catch ( Exception $e ) {
			$information = array( 'error' => __( 'fetch_url_authed exception', 'mainwp' ) );
		}

		// die( wp_json_encode( $information ) );
		wp_send_json( $information );
	}

}
