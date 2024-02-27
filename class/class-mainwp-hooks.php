<?php
/**
 * MainWP Hooks
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.

/**
 * Class MainWP_Hooks
 *
 * @package MainWP\Dashboard
 */
class MainWP_Hooks {

	/**
	 * MainWP_Hooks constructor.
	 *
	 * Initialize MainWP_Hooks actions/filters upon creation of the object.
	 *
	 * @deprecated 4.0.7. Hook deprecation - Please use filter `mainwp_get_error_message` instead of `mainwp_getErrorMessage`.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_UI::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Install_Bulk::get_class_name()
	 */
	public function __construct() {
		add_filter( 'mainwp_getspecificdir', array( MainWP_System_Utility::get_class_name(), 'get_mainwp_specific_dir' ), 10, 1 );
		add_filter( 'mainwp_getmainwpdir', array( &$this, 'hook_get_mainwp_dir' ), 10, 3 );
		add_filter( 'mainwp_is_multi_user', array( &$this, 'is_multi_user' ) );
		add_filter( 'mainwp_qq2fileuploader', array( &$this, 'filter_qq2_file_uploader' ), 10, 2 );
		add_action( 'mainwp_select_sites_box', array( &$this, 'hook_select_sites_box' ), 10, 12 );
		add_action( 'mainwp_add_categories_box', array( &$this, 'hook_add_categories_box' ), 10, 1 );
		add_action( 'mainwp_prepareinstallplugintheme', array( MainWP_Install_Bulk::get_class_name(), 'prepare_install' ) );
		add_action( 'mainwp_performinstallplugintheme', array( MainWP_Install_Bulk::get_class_name(), 'perform_install' ) );
		add_filter( 'mainwp_getwpfilesystem', array( MainWP_System_Utility::get_class_name(), 'get_wp_file_system' ) );
		add_filter( 'mainwp_getspecificurl', array( MainWP_System_Utility::get_class_name(), 'get_mainwp_specific_url' ), 10, 1 );
		add_filter( 'mainwp_getdownloadurl', array( MainWP_System_Utility::get_class_name(), 'get_download_url' ), 10, 2 );
		add_action( 'mainwp_renderHeader', array( MainWP_UI::get_class_name(), 'render_header' ), 10, 2 );
		add_action( 'mainwp_renderFooter', array( MainWP_UI::get_class_name(), 'render_footer' ), 10, 0 );

		add_action( 'mainwp_notify_user', array( &$this, 'hook_notify_user' ), 10, 3 );
		add_filter( 'mainwp_is_mainwp_page', array( MainWP_System::class, 'is_mainwp_pages' ) );

		/**
		 * The actions has been deprecated.
		 *
		 * @deprecated 4.3 Please use actions: mainwp_action_activeplugin, mainwp_action_deactiveplugin ....
		 */
		add_action( 'mainwp_activePlugin', array( &$this, 'active_plugin' ), 10, 0 );
		add_action( 'mainwp_deactivePlugin', array( &$this, 'deactive_plugin' ), 10, 0 );
		add_action( 'mainwp_upgradePluginTheme', array( &$this, 'upgrade_plugin_theme' ), 10, 0 );
		add_action( 'mainwp_deletePlugin', array( &$this, 'delete_plugin' ), 10, 0 );
		add_action( 'mainwp_deleteTheme', array( &$this, 'delete_theme' ), 10, 0 );
		// End.

		/**
		 * Plugins/themes/core actions.
		 *
		 * @since 4.3
		 */
		add_action( 'mainwp_action_activeplugin', array( &$this, 'active_plugin' ), 10, 0 );
		add_action( 'mainwp_action_deactiveplugin', array( &$this, 'deactive_plugin' ), 10, 0 );
		add_action( 'mainwp_action_upgradeplugintheme', array( &$this, 'upgrade_plugin_theme' ), 10, 0 );
		add_action( 'mainwp_action_deleteplugin', array( &$this, 'delete_plugin' ), 10, 0 );
		add_action( 'mainwp_action_deletetheme', array( &$this, 'delete_theme' ), 10, 0 );
		add_action( 'mainwp_upgrade_wp', array( &$this, 'upgrade_wp' ), 10, 0 );
		// End.

		add_filter( 'mainwp_get_user_extension', array( &$this, 'hook_get_user_extension' ), 10, 2 );
		add_filter( 'mainwp_update_user_extension', array( &$this, 'update_user_extension' ), 10, 3 );
		add_filter( 'mainwp_getwebsitesbyurl', array( &$this, 'get_websites_by_url' ) );
		add_filter( 'mainwp_getWebsitesByUrl', array( &$this, 'get_websites_by_url' ) );
		add_action( 'mainwp_manage_posts_get_edit', array( &$this, 'hook_posts_get_edit' ), 10, 2 );
		add_action( 'mainwp_manage_posts_bulk_posting', array( &$this, 'hook_posts_bulk_posting' ), 10, 2 );

		/**
		 * The mainwp_getErrorMessage filter has been deprecated.
		 *
		 * @deprecated 4.0.7. Please use filter mainwp_get_error_message.
		 */
		add_filter( 'mainwp_getErrorMessage', array( &$this, 'get_error_message' ), 10, 2 );
		add_filter( 'mainwp_get_error_message', array( &$this, 'get_error_message' ), 10, 2 );

		add_filter( 'mainwp_getwebsitesbygroupids', array( &$this, 'hook_get_websites_by_group_ids' ), 10, 2 );

		add_filter( 'mainwp_cache_getcontext', array( &$this, 'cache_getcontext' ) );
		add_action( 'mainwp_cache_echo_body', array( &$this, 'cache_echo_body' ) );
		add_action( 'mainwp_cache_init', array( &$this, 'cache_init' ) );
		add_action( 'mainwp_cache_add_context', array( &$this, 'cache_add_context' ), 10, 2 );
		add_action( 'mainwp_cache_add_body', array( &$this, 'cache_add_body' ), 10, 2 );

		add_filter( 'mainwp_get_metaboxes_post', array( &$this, 'get_metaboxes_post' ), 10, 0 );
		add_filter( 'mainwp_getnotificationemail', array( &$this, 'hook_notification_email' ), 10, 1 );
		add_filter( 'mainwp_getformatemail', array( &$this, 'get_formated_email' ), 10, 3 );
		add_filter( 'mainwp-extension-available-check', array( MainWP_Extensions_Handler::get_class_name(), 'is_extension_available' ) );
		add_filter( 'mainwp_extension_is_activated', array( &$this, 'is_extension_activated' ), 10, 2 );
		add_filter( 'mainwp_extension_is_pro_member', array( &$this, 'is_pro_member' ), 10, 1 );

		/**
		 * Logging debug actions.
		 */
		add_action( 'mainwp_log_debug', array( &$this, 'mainwp_log_debug' ), 10, 1 );
		add_action( 'mainwp_log_info', array( &$this, 'mainwp_log_info' ), 10, 1 );
		add_action( 'mainwp_log_warning', array( &$this, 'mainwp_log_warning' ), 10, 1 );
		add_action( 'mainwp_log_action', array( &$this, 'mainwp_log_action' ), 10, 4 );
		add_action( 'mainwp_log_execution_time', array( &$this, 'mainwp_log_execution_time' ), 10, 1 );
		// END.

		add_filter( 'mainwp_getactivateextensionnotice', array( &$this, 'get_activate_extension_notice' ), 10, 1 );
		add_action( 'mainwp_enqueue_meta_boxes_scripts', array( &$this, 'enqueue_meta_boxes_scripts' ), 10, 1 );
		add_filter( 'mainwp_addsite', array( &$this, 'mainwp_add_site' ), 10, 1 );
		add_filter( 'mainwp_deletesite', array( &$this, 'hook_delete_site' ), 10, 1 );
		add_filter( 'mainwp_clonesite', array( &$this, 'filter_clone_site' ), 10, 6 );
		add_filter( 'mainwp_delete_clonesite', array( &$this, 'filter_delete_clone_site' ), 10, 4 );
		add_filter( 'mainwp_editsite', array( &$this, 'mainwp_edit_site' ), 10, 1 );
		add_action( 'mainwp_add_sub_leftmenu', array( &$this, 'hook_add_sub_left_menu' ), 10, 6 );
		add_filter( 'mainwp_getwebsiteoptions', array( &$this, 'hook_get_site_options' ), 10, 3 );
		add_filter( 'mainwp_updatewebsiteoptions', array( &$this, 'hook_update_site_options' ), 10, 4 );

		add_filter( 'mainwp_getwebsitesbyuserid', array( &$this, 'hook_get_websites_by_user_id' ), 10, 5 );
		add_filter( 'mainwp_getwebsite_by_id', array( &$this, 'hook_get_website_by_id' ), 10, 4 );

		add_filter( 'mainwp_addgroup', array( MainWP_Extensions_Handler::get_class_name(), 'hook_add_group' ), 10, 3 );
		add_filter( 'mainwp_getallposts', array( &$this, 'hook_get_all_posts' ), 10, 2 );
		add_filter( 'mainwp_check_current_user_can', array( &$this, 'hook_current_user_can' ), 10, 3 );
		add_filter( 'mainwp_escape_response_data', array( &$this, 'hook_escape_response' ), 10, 3 );
		add_filter( 'mainwp_escape_content', array( &$this, 'hook_escape_content' ), 10, 3 );

		add_filter( 'mainwp_db_query', array( &$this, 'hook_db_query' ), 10, 2 );
		add_filter( 'mainwp_db_fetch_object', array( &$this, 'db_fetch_object' ), 10, 2 );
		add_filter( 'mainwp_db_fetch_array', array( &$this, 'db_fetch_array' ), 10, 2 );
		add_action( 'mainwp_db_data_seek', array( &$this, 'hook_db_data_seek' ), 10, 2 );
		add_filter( 'mainwp_db_free_result', array( &$this, 'db_free_result' ), 10, 2 );
		add_filter( 'mainwp_db_num_rows', array( &$this, 'db_num_rows' ), 10, 2 );
		add_filter( 'mainwp_db_get_websites_for_current_user', array( &$this, 'db_get_websites_for_current_user' ), 10, 2 );

		// Sync website.
		add_filter( 'mainwp_sync_website', array( &$this, 'hook_sync_website' ), 10, 3 );

		add_action( 'mainwp_secure_request', array( &$this, 'hook_secure_request' ), 10, 2 );
		add_filter( 'mainwp_check_security_request', array( &$this, 'hook_check_security_request' ), 10, 3 );
		add_filter( 'mainwp_notification_get_settings', array( &$this, 'get_notification_settings' ), 10, 2 );

		add_filter( 'mainwp_send_wp_mail', array( &$this, 'hook_send_wp_mail' ), 10, 5 );
		add_filter( 'mainwp_notification_get_template_content', array( &$this, 'hook_get_template_html' ), 10, 3 );
		add_filter( 'mainwp_sitestable_getcolumns', array( $this, 'hook_atarim_default_sitestable_column' ), 10, 1 );
		add_filter( 'mainwp_sitestable_item', array( $this, 'hook_atarim_manage_sites_default_item' ), 10, 1 );
		add_filter( 'mainwp_monitoring_sitestable_getcolumns', array( $this, 'hook_atarim_default_sitestable_column' ), 10, 1 );
		add_filter( 'mainwp_monitoring_sitestable_item', array( $this, 'hook_atarim_manage_sites_default_item' ), 10, 1 );
		add_filter( 'mainwp_clients_get_website_client_tokens', array( &$this, 'hook_get_website_client_tokens' ), 10, 2 );
		add_filter( 'mainwp_secure_get_download_sig', array( &$this, 'hook_get_download_sig' ), 10, 2 );
		add_action( 'mainwp_secure_download', array( &$this, 'hook_secure_download' ), 10, 3 );
		add_action( 'mainwp_ajax_add_action', array( &$this, 'hook_ajax_add_action' ), 10, 2 );
		add_filter( 'mainwp_get_plugin_icon', array( &$this, 'hook_get_plugin_icon' ), 10, 2 );
		add_filter( 'mainwp_get_theme_icon', array( &$this, 'hook_get_theme_icon' ), 10, 2 );
		add_filter( 'mainwp_get_dir_slug', array( &$this, 'hook_get_dir_slug' ), 10, 2 );
		add_action( 'mainwp_do_widget_boxes', array( &$this, 'hook_do_widget_boxes' ), 10, 3 );
		add_action( 'mainwp_add_widget_box', array( &$this, 'hook_add_widget_box' ), 10, 6 );
		add_action( 'mainwp_render_modal_upload_icon', array( &$this, 'hook_render_modal_upload_icon' ), 10, 2 );
		add_action( 'mainwp_render_plugin_details_modal', array( &$this, 'hook_render_plugin_details_modal' ), 10, 2 );
		add_action( 'mainwp_render_updates', array( &$this, 'hook_render_updates' ), 10, 2 );
		add_filter( 'mainwp_get_wp_client_by', array( &$this, 'hook_get_wp_client_by' ), 10, 4 );

		/**
		 * Filter: mainwp_get_tokens_values
		 *
		 * Get tokens values for reports.
		 *
		 * @since 4.3.
		 */
		add_filter( 'mainwp_get_reports_group_values_website', array( MainWP_Reports_Helper::get_instance(), 'hook_get_reports_group_values' ), 10, 6 );

		/**
		 *  MainWP API hooks.
		 *
		 * @since 4.5.
		 */
		add_filter( 'mainwp_extension_get_activation_info', array( &$this, 'hook_get_activation_info' ), 10, 2 );
		add_filter( 'mainwp_get_api_url', array( &$this, 'hook_get_api_url' ), 10, 1 );
		add_filter( 'mainwp_hook_run_dashboard_action', array( &$this, 'hook_run_dashboard_action' ), 10, 2 );

		/**
		 *  Key encrypt hooks.
		 *
		 * @since 4.5.
		 */
		add_filter( 'mainwp_get_key_value', array( &$this, 'hook_get_key_value' ), 10, 3 );
		add_filter( 'mainwp_update_key_value', array( &$this, 'hook_update_key_value' ), 10, 4 );
		add_action( 'mainwp_delete_key_value', array( &$this, 'hook_delete_key_value' ), 10, 1 );

		add_filter( 'mainwp_encrypt_key_value', array( &$this, 'hook_encrypt_key_value' ), 10, 4 );
		add_filter( 'mainwp_decrypt_key_value', array( &$this, 'hook_decrypt_key_value' ), 10, 3 );
		add_action( 'mainwp_delete_key_file', array( &$this, 'hook_delete_key_file' ), 10, 1 );
		add_filter( 'mainwp_verify_ping_nonce', array( MainWP_Utility::class, 'hook_verify_ping_nonce' ), 10, 3 );
		add_action( 'mainwp_fetch_url_authed', array( MainWP_Actions_Handler::instance(), 'hook_mainwp_fetch_url_authed' ), 10, 5 );
	}

	/**
	 * Method mainwp_log_debug()
	 *
	 * MainWP debug log.
	 *
	 * @param string $text Debug text.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger::debug()
	 */
	public function mainwp_log_debug( $text ) {
		MainWP_Logger::instance()->debug( $text );
	}

	/**
	 * Method mainwp_log_info()
	 *
	 * MainWP log info.
	 *
	 * @param string $text Info Text.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger::info()
	 */
	public function mainwp_log_info( $text ) {
		MainWP_Logger::instance()->info( $text );
	}

	/**
	 * Method mainwp_log_warning()
	 *
	 * MainWP log warning.
	 *
	 * @param string $text Warning text.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger::warning()
	 */
	public function mainwp_log_warning( $text ) {
		MainWP_Logger::instance()->warning( $text );
	}

	/**
	 * Method mainwp_log_action()
	 *
	 * MainWP log action.
	 *
	 * @param string $text Debug text.
	 * @param int    $priority priority: -1 disabled, 0 - log, 1 - warning, 2 - info, 3 - debug.
	 * @param int    $log_color Set color: 0 - LOG, 1 - WARNING, 2 - INFO, 3- DEBUG.
	 * @param bool   $forced forced logging.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger::debug()
	 */
	public function mainwp_log_action( $text, $priority = 0, $log_color = 0, $forced = false ) {
		if ( ! empty( $priority ) ) {
			MainWP_Logger::instance()->log_action( $text, $priority, $log_color, $forced );
		}
	}

	/**
	 * Method mainwp_log_execution_time()
	 *
	 * MainWP log execution time.
	 *
	 * @param string $text Debug text.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger::debug()
	 */
	public function mainwp_log_execution_time( $text = '' ) {
		MainWP_Logger::instance()->log_execution_time( $text );
	}

	/**
	 * Method enqueue_meta_boxes_scripts()
	 *
	 * Enqueue Scripts for all Meta boxes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::enqueue_postbox_scripts()
	 */
	public function enqueue_meta_boxes_scripts() {
		MainWP_System::enqueue_postbox_scripts();
	}

	/**
	 * Method mainwp_add_site()
	 *
	 * Hook to add Child Site.
	 *
	 * @param array $params site data fields: url, name, wpadmin, unique_id, groupids, ssl_verify, ssl_version,
	 *                      http_user, http_pass, websiteid - if edit site.
	 *
	 * @return array $ret data fields: response, siteid.
	 *
	 * @throws \Exception Exception message.
	 *
	 * @since 3.2.2
	 *
	 * @uses  \MainWP\Dashboard\MainWP_Manage_Sites_View::update_wp_site()
	 * @uses  \MainWP\Dashboard\MainWP_Manage_Sites_View::add_wp_site()
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function mainwp_add_site( $params ) {
		$ret = array();

		if ( is_array( $params ) ) {
			if ( isset( $params['websiteid'] ) && MainWP_Utility::ctype_digit( $params['websiteid'] ) ) {
				$ret['siteid'] = MainWP_Manage_Sites_View::update_wp_site( $params );
				return $ret;
			} elseif ( isset( $params['url'] ) && isset( $params['wpadmin'] ) ) {
				$website                           = MainWP_DB::instance()->get_websites_by_url( $params['url'] );
				list( $message, $error, $site_id ) = MainWP_Manage_Sites_View::add_wp_site( $website, $params );

				if ( '' !== $error ) {
					return array( 'error' => $error );
				}
				$ret['response'] = $message;
				$ret['siteid']   = $site_id;
			}
		}

		return $ret;
	}

	/**
	 * Method is_pro_member()
	 *
	 * Check for inactive MainWP Extensions.
	 *
	 * @param mixed $input Input value.
	 *
	 * @return bool Activation notice.
	 */
	public static function is_pro_member( $input = false ) {
		$info = get_option( 'mainwp_extensions_plan_info' );
		if ( ! empty( $info ) ) {
			$info = json_decode( $info, true );
			if ( is_array( $info ) && isset( $info['plan_purchased'] ) && isset( $info['plan_status'] ) ) {
				if ( 'active' === $info['plan_status'] && in_array( $info['plan_purchased'], array( 'monthly', 'yearly', 'lifetime' ) ) ) {
					return true;
				}
			}
		}
		return $input;
	}


	/**
	 * Method hook_delete_site()
	 *
	 * Hook to delete Child Site.
	 *
	 * @param bool $site_id Child site ID.
	 *
	 * @return bool|array Return false if empty and return array error - Site not found | result - SUCCESS.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_icons_dir()
	 */
	public function hook_delete_site( $site_id = false ) {

		if ( empty( $site_id ) ) {
			return false;
		}

		$sql      = MainWP_DB::instance()->get_sql_website_by_id( $site_id );
		$websites = MainWP_DB::instance()->query( $sql );
		$site     = MainWP_DB::fetch_object( $websites );

		if ( empty( $site ) ) {
			return array( 'error' => esc_html__( 'Not found the website', 'mainwp' ) );
		}
		$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
		global $wp_filesystem;

		if ( $hasWPFileSystem ) {
			$favi = MainWP_DB::instance()->get_website_option( $site, 'favi_icon', '' );
			if ( ! empty( $favi ) && ( false !== strpos( $favi, 'favi-' . $site->id . '-' ) ) ) {
				$dirs = MainWP_System_Utility::get_icons_dir();
				if ( $wp_filesystem->exists( $dirs[0] . $favi ) ) {
					$wp_filesystem->delete( $dirs[0] . $favi );
				}
			}
		}

		MainWP_DB::instance()->remove_website( $site->id );

		/** This action is documented in pages\page-mainwp-manage-sites-handler.php */
		do_action( 'mainwp_delete_site', $site );
		return array( 'result' => 'SUCCESS' );
	}

	/**
	 * Method filter_clone_site()
	 *
	 * Hook to clone site.
	 *
	 * @since 3.4.4
	 * @param mixed $pluginFile Plugin file.
	 * @param mixed $key Key.
	 * @param mixed $websiteid Child site ID.
	 * @param mixed $cloneid Clone site ID.
	 * @param mixed $clone_url Clone site URL.
	 * @param bool  $force_update Force the update, true|false, Default: false.
	 *
	 * @return array Site array to clone.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::hook_clone_site()
	 */
	public function filter_clone_site( $pluginFile, $key, $websiteid, $cloneid, $clone_url, $force_update = false ) {
		return MainWP_Extensions_Handler::hook_clone_site( $pluginFile, $key, $websiteid, $cloneid, $clone_url, $force_update );
	}

	/**
	 * Method filter_delete_clone_site()
	 *
	 * Hook to delete cloaned Child Site.
	 *
	 * @param mixed  $pluginFile Plugin file.
	 * @param mixed  $key Key.
	 * @param string $clone_url Clone site URL.
	 * @param bool   $clone_site_id Clone site ID.
	 *
	 * @return array Site array to delete.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::hook_delete_clone_site()
	 */
	public function filter_delete_clone_site( $pluginFile, $key, $clone_url = '', $clone_site_id = false ) {
		return MainWP_Extensions_Handler::hook_delete_clone_site( $pluginFile, $key, $clone_url, $clone_site_id );
	}

	/**
	 * Method mainwp_edit_site()
	 *
	 * Hook to edit Child Site.
	 *
	 * @param array $params site data fields: websiteid, name, wpadmin, unique_id.
	 *
	 * @return array $ret Child site ID.
	 *
	 * @throws \Exception Exception message.
	 *
	 * @since 3.2.2
	 *
	 * @uses  \MainWP\Dashboard\MainWP_Manage_Sites_View::update_wp_site()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function mainwp_edit_site( $params ) {
		$ret = array();
		if ( is_array( $params ) ) {
			if ( isset( $params['websiteid'] ) && MainWP_Utility::ctype_digit( $params['websiteid'] ) ) {
				$ret['siteid'] = MainWP_Manage_Sites_View::update_wp_site( $params );
				return $ret;
			}
		}
		return $ret;
	}

	/**
	 * Method hook_add_sub_left_menu()
	 *
	 * Hook to add MainWP Left Menu item.
	 *
	 * @param string  $title Menu title.
	 * @param string  $slug Menu slug.
	 * @param string  $href Menu link.
	 * @param integer $level Menu level.
	 * @param string  $parent_key Parent menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
	 */
	public function hook_add_sub_left_menu( $title, $slug, $href, $level = 1, $parent_key = 'mainwp_tab' ) {
		$item = array(
			'title'      => $title,
			'parent_key' => $parent_key,
			'slug'       => $slug,
			'href'       => $href,
		);
		MainWP_Menu::add_left_menu( $item, $level );
	}

	/**
	 * Method is_extension_activated()
	 *
	 * Check for inactive MainWP Extensions.
	 *
	 * @param mixed $input Input value.
	 * @param mixed $slug MainWP Extension to check.
	 *
	 * @return bool Activation notice.
	 */
	public function is_extension_activated( $input, $slug ) {
		return MainWP_Extensions_Handler::is_extension_activated( $slug );
	}


	/**
	 * Method get_activate_extension_notice()
	 *
	 * Check for inactive MainWP Extensions &
	 * return an activation warning message.
	 *
	 * @param mixed $pluginFile MainWP Extension to check.
	 *
	 * @return string Activation notice.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::is_extension_activated()
	 */
	public function get_activate_extension_notice( $pluginFile ) {
		$active = MainWP_Extensions_Handler::is_extension_activated( $pluginFile );
		if ( $active ) {
			return false;
		}

		$activate_notices = get_user_option( 'mainwp_hide_activate_notices' );
		if ( is_array( $activate_notices ) ) {
			$slug = basename( $pluginFile, '.php' );
			if ( isset( $activate_notices[ $slug ] ) ) {
				return false;
			}
		}

		return sprintf( esc_html__( 'You have a MainWP extension that does not have an active API entered. This means you will not receive updates or support. Please visit the %1$sExtensions%2$s page and enter your API key.', 'mainwp' ), '<a href="admin.php?page=Extensions">', '</a>' );
	}

	/**
	 * Method cache_getcontext()
	 *
	 * Get cached search context for given page.
	 *
	 * @param string $page Current MainWP Page.
	 *
	 * @return array Cached Search Array.
	 */
	public function cache_getcontext( $page ) {
		return MainWP_Cache::get_cached_context( $page );
	}

	/**
	 * Method cache_echo_body()
	 *
	 * Echo Cached Search Body.
	 *
	 * @param string $page Current MainWP Page.
	 */
	public function cache_echo_body( $page ) {
		MainWP_Cache::echo_body( $page );
	}

	/**
	 * Method cache_init()
	 *
	 * Initiate search session variables for the current page.
	 *
	 * @param string $page Current MainWP Page.
	 */
	public function cache_init( $page ) {
		MainWP_Cache::init_cache( $page );
	}

	/**
	 * Method cache_add_context()
	 *
	 * Hook to add time & Search Context session variable.
	 *
	 * @param string $page Current MainWP Page.
	 * @param mixed  $context Time of search.
	 */
	public function cache_add_context( $page, $context ) {
		MainWP_Cache::add_context( $page, $context );
	}

	/**
	 * Method cache_add_body()
	 *
	 * Hook to add Search Body Session variable.
	 *
	 * @param string $page Current MainWP Page.
	 * @param mixed  $body Search body.
	 */
	public function cache_add_body( $page, $body ) {
		MainWP_Cache::add_body( $page, $body );
	}

	/**
	 * Method hook_select_sites_box()
	 *
	 * Hook to select sites box.
	 *
	 * @param string $title Input title.
	 * @param string $type Input type, radio.
	 * @param bool   $show_group Whether or not to show group, Default: true.
	 * @param bool   $show_select_all Whether to show select all.
	 * @param string $class_style Default = ''.
	 * @param string $style Default = ''.
	 * @param array  $selected_websites Selected Child Sites.
	 * @param array  $selected_groups Selected Groups.
	 * @param bool   $show_client Show Clients.
	 * @param array  $selected_clients Selected Clients.
	 * @param mixed  $post_id post ID.
	 * @param bool   $show_create_tag Show create tag button.
	 */
	public function hook_select_sites_box( $title = '', $type = 'checkbox', $show_group = true, $show_select_all = true, $class_style = '', $style = '', $selected_websites = array(), $selected_groups = array(), $show_client = false, $selected_clients = array(), $post_id = false, $show_create_tag = true ) {
		$sel_params = array(
			'type'             => $type,
			'show_group'       => $show_group,
			'show_select_all'  => $show_select_all,
			'class'            => $class_style,
			'style'            => $style,
			'selected_sites'   => $selected_websites,
			'selected_groups'  => $selected_groups,
			'show_client'      => $show_client,
			'selected_clients' => $selected_clients,
			'post_id'          => $post_id,
			'show_create_tag'  => $show_create_tag,
		);
		MainWP_UI_Select_Sites::select_sites_box( $sel_params );
	}

	/**
	 * Method hook_add_categories_box()
	 *
	 * General categories box.
	 *
	 * @param int $post_id Post ID.
	 */
	public function hook_add_categories_box( $post_id = false ) {
		MainWP_System::instance()->metaboxes->add_categories( $post_id );
	}

	/**
	 * Method hook_notify_user()
	 *
	 * Hook to send user a notification.
	 *
	 * @param int    $userId User ID.
	 * @param string $subject Email Subject.
	 * @param string $content Email Content.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Notification::send_notify_user()
	 */
	public function hook_notify_user( $userId, $subject, $content ) {
		MainWP_Notification::send_notify_user( $userId, $subject, $content );
	}

	/**
	 * Method get_error_message()
	 *
	 * Hook to get error message.
	 *
	 * @param object $msg Error message.
	 * @param object $extra HTTP error message.
	 *
	 * @return string Error message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
	 */
	public function get_error_message( $msg, $extra ) {
		return MainWP_Error_Helper::get_error_message( new MainWP_Exception( $msg, $extra ) );
	}

	/**
	 * Method hook_get_user_extension()
	 *
	 * Hook to get user extension.
	 *
	 * @return object $row User extension.
	 */
	public function hook_get_user_extension() {
		return MainWP_DB_Common::instance()->get_user_extension();
	}

	/**
	 * Method update_user_extension()
	 *
	 * Hook to update user extension.
	 *
	 * @param bool   $input_value input filter value.
	 * @param string $option_name option name.
	 * @param mixed  $option_value option value.
	 *
	 * @return bool true.
	 */
	public function update_user_extension( $input_value, $option_name, $option_value ) {
		$userExtension                 = MainWP_DB_Common::instance()->get_user_extension();
		$userExtension->{$option_name} = wp_json_encode( $option_value );
		MainWP_DB_Common::instance()->update_user_extension( $userExtension );
		return true;
	}

	/**
	 * Method hook_get_site_options()
	 *
	 * Hook to get Child site options.
	 *
	 * @param mixed        $boolean Boolean check.
	 * @param object       $website Child site object.
	 * @param string|array $options Option table name.
	 *
	 * @return string|null Database query result (as string), or null on failure
	 */
	public function hook_get_site_options( $boolean, $website, $options = '' ) {

		if ( empty( $options ) ) {
			return $boolean;
		}

		if ( is_string( $options ) ) {
			return MainWP_DB::instance()->get_website_option( $website, $options );
		} elseif ( is_array( $options ) ) {
			return MainWP_DB::instance()->get_website_options_array( $website, $options );
		}
		return $boolean;
	}

	/**
	 * Method hook_update_site_options()
	 *
	 * Hook to get Child site options.
	 *
	 * @param mixed  $boolean Boolean check.
	 * @param object $website Child site object.
	 * @param string $option Option name.
	 * @param string $value Option value.
	 *
	 * @return string|null Database query result (as string), or null on failure
	 */
	public function hook_update_site_options( $boolean, $website, $option, $value ) {
		if ( is_numeric( $website ) ) {
			$obj     = new \stdClass();
			$obj->id = intval( $website );
			$website = $obj;
		} elseif ( ! is_object( $website ) || ! property_exists( $website, 'id' ) ) {
			return false;
		}
		return MainWP_DB::instance()->update_website_option( $website, $option, $value );
	}

	/**
	 * Get sites by user ID.
	 *
	 * @param mixed  $boolean Boolean check.
	 * @param int    $userid       User ID.
	 * @param bool   $selectgroups Selected groups.
	 * @param null   $search_site  Site search field value.
	 * @param string $orderBy      Order list by. Default: URL.
	 *
	 * @return object|null Database query results or null on failure.
	 */
	public function hook_get_websites_by_user_id( $boolean, $userid, $selectgroups = false, $search_site = null, $orderBy = 'wp.url' ) {
		return MainWP_DB::instance()->get_websites_by_user_id( $userid, $selectgroups, $search_site, $orderBy );
	}

	/**
	 * Get sites by website ID.
	 *
	 * @param mixed $boolean Boolean check.
	 * @param int   $website_id       User ID.
	 * @param bool  $selectGroups     Select groups.
	 * @param array $extra_view   get extra option fields.
	 *
	 * @return object|null Database query results or null on failure.
	 */
	public function hook_get_website_by_id( $boolean, $website_id, $selectGroups = false, $extra_view = array() ) {

		if ( empty( $website_id ) ) {
			return false;
		}

		$website = MainWP_DB::instance()->get_website_by_id( $website_id, $selectGroups, $extra_view );

		if ( ! empty( $website ) && property_exists( $website, 'privkey' ) ) {
			unset( $website->privkey );
		}

		return $website;
	}


	/**
	 * Method get_websites_by_url()
	 *
	 * Hook to get Child Site by URL.
	 *
	 * @param string $url Child Site URL.
	 *
	 * @return array|object|null Database query results.
	 */
	public function get_websites_by_url( $url ) {
		return MainWP_DB::instance()->get_websites_by_url( $url );
	}

	/**
	 * Method hook_get_all_posts()
	 * Hook to get posts from sites.
	 *
	 * @param object $sites     Child Sites object.
	 * @param array  $post_data with values: keyword, dtsstart, dtsstop, status, maxRecords, post_type.
	 *
	 * @return \stdClass $output All posts data array.
	 *
	 * @since 3.4.4
	 *
	 * @uses \MainWP\Dashboard\MainWP_Post::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
	 */
	public function hook_get_all_posts( $sites, $post_data = array() ) {

		$dbwebsites = array();

		$data   = MainWP_System_Utility::get_default_map_site_fields();
		$data[] = 'verify_certificate';

		if ( '' !== $sites ) {
			foreach ( $sites as $k => $v ) {
				if ( MainWP_Utility::ctype_digit( $v ) ) {
					$website = MainWP_DB::instance()->get_website_by_id( $v );
					if ( empty( $website->sync_errors ) && ! MainWP_System_Utility::is_suspended_site( $website ) ) {
						$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data );
					}
				}
			}
		}

		$default_data = array(
			'post_type'  => 'post',
			'status'     => 'publish',
			'maxRecords' => 10,
		);

		$post_data = array_merge( $default_data, $post_data );

		$output          = new \stdClass();
		$output->results = array();
		if ( $dbwebsites ) {
			MainWP_Connect::fetch_urls_authed(
				$dbwebsites,
				'get_all_posts',
				$post_data,
				array(
					MainWP_Post::get_class_name(),
					'hook_posts_search_handler',
				),
				$output
			);
		}
		return $output;
	}

	/**
	 * Method mainwp_current_user_have_right()
	 *
	 * Check permission level by hook mainwp_currentusercan of Team Control extension
	 *
	 * @param mixed  $input Return value holder.
	 * @param string $can_type group or type of capabilities.
	 * @param string $which Which function to perform.
	 *
	 * @return bool $input Return true if the user can and false if they can not.
	 */
	public function hook_current_user_can( $input, $can_type, $which ) {

		if ( function_exists( 'mainwp_current_user_have_right' ) ) {
			return mainwp_current_user_have_right( $can_type, $which );
		}

		return $input;
	}

	/**
	 * Method hook_escape_response()
	 *
	 * To escape response data.
	 *
	 * @param mixed $response     response data.
	 * @param bool  $fields       fields of response data - option.
	 * @param array $more_allowed input allowed tags - option.
	 *
	 * @return mixed $response valid response data.
	 * @throws \Exception Exception message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::esc_content()
	 * @uses \MainWP\Dashboard\MainWP_Utility::esc_mixed_content()
	 */
	public function hook_escape_response( $response, $fields = false, $more_allowed = array() ) {

		if ( false === $fields || is_string( $response ) ) {
			return MainWP_Utility::esc_content( $response );
		}

		if ( ! is_array( $fields ) ) {
			return $response;
		}

		if ( ! in_array( 'error', $fields ) ) {
			$fields[] = 'error'; // to sure to valid 'error' field, if that existed.
		}

		if ( ! in_array( 'message', $fields ) ) {
			$fields[] = 'message'; // to sure to valid 'message' field, if that existed.
		}

		$depth = 10;

		foreach ( $fields as $field ) {
			if ( isset( $response[ $field ] ) ) {
				$response[ $field ] = MainWP_Utility::esc_mixed_content( $response[ $field ], $depth, $more_allowed );
			}
		}
		return $response;
	}

	/**
	 * Method hook_escape_content()
	 *
	 * To escape content.
	 *
	 * @param mixed  $content     response data.
	 * @param string $type content type for escape.
	 * @param array  $more_allowed input allowed tags - option.
	 *
	 * @return mixed $response valid response data.
	 * @throws \Exception Exception message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::esc_content()
	 */
	public function hook_escape_content( $content, $type = 'note', $more_allowed = array() ) {
		return MainWP_Utility::esc_content( $content, $type, $more_allowed );
	}


	/**
	 * Method db_free_result()
	 *
	 * To escape response data.
	 *
	 * @param mixed $input_value     input value.
	 * @param mixed $result     result data.
	 *
	 * @return bool true.
	 */
	public function db_free_result( $input_value, $result ) {
		MainWP_DB::free_result( $result );
		return true;
	}

	/**
	 * Method db_num_rows()
	 *
	 * To escape response data.
	 *
	 * @param mixed $input_value     input value.
	 * @param mixed $result     result data.
	 *
	 * @return bool true.
	 */
	public function db_num_rows( $input_value, $result ) {
		return MainWP_DB::num_rows( $result );
	}


	/**
	 * Method db_get_websites_for_current_user()
	 *
	 * To escape response data.
	 *
	 * @param mixed $input_value     input value.
	 * @param array $params     params data.
	 *
	 * @return mixed websites.
	 */
	public function db_get_websites_for_current_user( $input_value = false, $params = array() ) {
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$orderBy    = isset( $params['orderby'] ) ? MainWP_DB::instance()->escape( $params['orderby'] ) : 'wp.url';
		$extra_view = isset( $params['extra_view'] ) ? $params['extra_view'] : array( 'favi_icon' );

		if ( ! is_array( $extra_view ) ) {
			$extra_view = array( 'favi_icon' );
		}
		return MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, $orderBy, false, false, null, false, $extra_view ) );
	}


	/**
	 * Method hook_sync_website()
	 *
	 * To escape response data.
	 *
	 * @param mixed $input_value     input value.
	 * @param int   $website_id  Website ID.
	 * @param bool  $fire_end_session to run the ending session or not.
	 *
	 * @return mixed websites.
	 */
	public function hook_sync_website( $input_value, $website_id, $fire_end_session = true ) {
		if ( empty( $website_id ) ) {
			return $input_value;
		}
		$website = MainWP_DB::instance()->get_website_by_id( $website_id );
		if ( empty( $website ) ) {
			return $input_value;
		}
		return MainWP_Sync::sync_website( $website, $fire_end_session );
	}


	/**
	 * Method hook_db_data_seek()
	 *
	 * To escape response data.
	 *
	 * @param mixed $result     result data.
	 * @param int   $offset     offset of data.
	 */
	public function hook_db_data_seek( $result, $offset = 0 ) {
		MainWP_DB::data_seek( $result, $offset );
	}


	/**
	 * Method hook_db_query()
	 *
	 * To query db.
	 *
	 * @param mixed  $input_value    filter input value.
	 * @param string $sql     result data.
	 *
	 * @return bool true.
	 */
	public function hook_db_query( $input_value, $sql ) {
		return MainWP_DB::instance()->query( $sql );
	}

	/**
	 * Method db_fetch_object()
	 *
	 * To escape response data.
	 *
	 * @param mixed $input_value     input value.
	 * @param mixed $result     result data.
	 *
	 * @return bool true.
	 */
	public function db_fetch_object( $input_value, $result ) {
		return MainWP_DB::fetch_object( $result );
	}


	/**
	 * Method db_fetch_array()
	 *
	 * To escape response data.
	 *
	 * @param mixed $input_value     input value.
	 * @param mixed $result     result data.
	 *
	 * @return bool true.
	 */
	public function db_fetch_array( $input_value, $result ) {
		return MainWP_DB::fetch_array( $result );
	}


	/**
	 * Get notification email settings.
	 *
	 * @param string $settings hook settings input value.
	 * @param string $type Notification type.
	 *
	 * @return array $gen_email_settings email settings.
	 */
	public function get_notification_settings( $settings, $type ) {
		$gen_email_settings = MainWP_Notification_Settings::get_general_email_settings( $type );
		return $gen_email_settings;
	}

	/**
	 * Hook send_wp_mail().
	 *
	 * Send email via wp_mail().
	 *
	 * @param string $input hook input value.
	 * @param string $email send to email.
	 * @param string $subject email content.
	 * @param bool   $formated_content email content.
	 * @param string $content_type Text format.
	 */
	public function hook_send_wp_mail( $input, $email, $subject, $formated_content, $content_type = '' ) {
		return MainWP_Notification::send_wp_mail(
			$email,
			$subject,
			$formated_content,
			$content_type
		);
	}

	/**
	 * Hook get template HTML content.
	 *
	 * @param string $input hooks input value.
	 * @param string $template_name Template name.
	 * @param array  $args          Arguments. (default: array).
	 *
	 * @return string
	 */
	public function hook_get_template_html( $input, $template_name, $args = array() ) {
		$mail_content = MainWP_Notification_Template::instance()->get_template_html(
			$template_name,
			$args
		);
		return $mail_content;
	}

	/**
	 * Method hook_secure_request()
	 *
	 * Security check to request parameter
	 *
	 * @param string $action Action to perform.
	 * @param string $query_arg Query argument.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Post_Handler::secure_request()
	 */
	public function hook_secure_request( $action = '', $query_arg = 'security' ) {
		MainWP_Post_Handler::instance()->secure_request( $action, $query_arg );
	}

	/**
	 * Method hook_check_security_request()
	 *
	 * Security check to request parameter
	 *
	 * @param bool   $input_value Input value.
	 * @param string $action Action to perform.
	 * @param string $query_arg Query argument.
	 */
	public function hook_check_security_request( $input_value, $action = '', $query_arg = '' ) {
		if ( empty( $query_arg ) ) {
			//phpcs:ignore Squiz.PHP.CommentedOutCode.Found
			// to do, $query_arg = 'security'.
			$query_arg = $action; // to check wp_verify_nonce - sanitize_key( $_REQUEST[ $query_arg ] ) - $action.
		}
		return MainWP_Post_Handler::instance()->check_security( $action, $query_arg, false );
	}

	/**
	 * Method hook_get_mainwp_dir()
	 *
	 * Hook to get MainWP Directory.
	 *
	 * @param bool $input_value False.
	 * @param null $dir WP files system diectories.
	 * @param bool $direct_access Return true if Direct access file system. Default: false.
	 *
	 * @return array $newdir, $url.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
	 */
	public function hook_get_mainwp_dir( $input_value = false, $dir = null, $direct_access = false ) {
		return MainWP_System_Utility::get_mainwp_dir( $dir, $direct_access );
	}


	/**
	 * Method is_multi_user()
	 *
	 * Hook to check if multi user.
	 *
	 * @return bool true|false.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
	 */
	public function is_multi_user() {
		return MainWP_System::instance()->is_multi_user();
	}

	/**
	 * Method filter_qq2_file_uploader()
	 *
	 * Hook to create new MainWP_QQ2_File_Uploader() class.
	 *
	 * @param mixed $allowedExtensions Allowed files extentions.
	 * @param mixed $sizeLimit         Maximum file size allowed to be uploaded.
	 *
	 * @return MainWP_QQ2_File_Uploader Return instance of MainWP_QQ2_File_Uploader
	 *
	 * @uses \MainWP\Dashboard\MainWP_QQ2_File_Uploader
	 */
	public function filter_qq2_file_uploader( $allowedExtensions, $sizeLimit ) {
		return new MainWP_QQ2_File_Uploader( $allowedExtensions, $sizeLimit );
	}

	/**
	 * Method get_metaboxes_post()
	 *
	 * Hook to get meta boxes.
	 *
	 * @return string|bool Return error or true.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::$metaboxes
	 */
	public function get_metaboxes_post() {
		return MainWP_System::instance()->metaboxes;
	}

	/**
	 * Method hook_notification_email()
	 *
	 * Hook to get notification email.
	 *
	 * @return string Return email.
	 */
	public function hook_notification_email() {
		return MainWP_Notification_Settings::get_general_email();
	}

	/**
	 * Method get_formated_email()
	 *
	 * Hook to format email.
	 *
	 * @param mixed  $body Email body.
	 * @param mixed  $email Email address.
	 * @param string $title Email title.
	 *
	 * @return string|bool Return error or true.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Format::format_email()
	 */
	public function get_formated_email( $body, $email, $title = '' ) {
		return MainWP_Format::format_email( $email, $body, $title );
	}

	/**
	 * Method active_plugin()
	 *
	 * Hook to activate plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::activate_plugins()
	 */
	public function active_plugin() {
		MainWP_Plugins_Handler::activate_plugins();
		die();
	}

	/**
	 * Method deactive_plugin()
	 *
	 * Hook to deactivate plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::deactivate_plugins()
	 */
	public function deactive_plugin() {
		MainWP_Plugins_Handler::deactivate_plugins();
		die();
	}

	/**
	 * Method delete_plugin()
	 *
	 * Hook to delete plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::delete_plugins()
	 */
	public function delete_plugin() {
		MainWP_Plugins_Handler::delete_plugins();
		die();
	}

	/**
	 * Method delete_theme()
	 *
	 * Hook to delete theme()
	 *
	 * @uses \MainWP\Dashboard\MainWP_Themes_Handler::delete_themes()
	 */
	public function delete_theme() {
		MainWP_Themes_Handler::delete_themes();
		die();
	}

	/**
	 * Method upgrade_wp()
	 *
	 * Hook to upgrade WP.
	 */
	public function upgrade_wp() {

		if ( ! mainwp_current_user_have_right( 'dashboard', 'update_wordpress' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( esc_html__( 'update WordPress', 'mainwp' ), $echo = false ) ) ) );
		}

		try {
			$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::upgrade_site( $id ) ) ) ); // ok.
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

		die();
	}

	/**
	 * Method upgrade_plugin_theme()
	 *
	 * Hook to update theme.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function upgrade_plugin_theme() {
		try {
			// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$websiteId = isset( $_POST['websiteId'] ) ? intval( $_POST['websiteId'] ) : null;
			$type      = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : null;
			$slugs     = isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] ) ? wp_unslash( $_POST['slugs'] ) : array();
			$error     = '';
			$erCode    = '';
			// phpcs:enable

			if ( 'plugin' === $type && ! mainwp_current_user_have_right( 'dashboard', 'update_plugins' ) ) {
				$error = mainwp_do_not_have_permissions( esc_html__( 'update plugins', 'mainwp' ), false );
			} elseif ( 'theme' === $type && ! mainwp_current_user_have_right( 'dashboard', 'update_themes' ) ) {
				$error = mainwp_do_not_have_permissions( esc_html__( 'update themes', 'mainwp' ), false );
			}

			$website = MainWP_DB::instance()->get_website_by_id( $websiteId );

			if ( MainWP_System_Utility::is_suspended_site( $website ) ) {
				$error  = esc_html__( 'Suspended site.', 'mainwp' );
				$erCode = 'SUSPENDED_SITE';
			}

			if ( ! empty( $error ) ) {
				wp_send_json(
					array(
						'error'     => $error,
						'errorCode' => $erCode,
					)
				);
			}

			if ( MainWP_Utility::ctype_digit( $websiteId ) ) {
				if ( MainWP_System_Utility::can_edit_website( $website ) ) {
					/**
					* Action: mainwp_before_plugin_theme_translation_update
					*
					* Fires before plugin/theme/translation update actions.
					*
					* @since 4.1
					*/
					do_action( 'mainwp_before_plugin_theme_translation_update', $type, implode( ',', $slugs ), $website );

					$information = MainWP_Connect::fetch_url_authed(
						$website,
						'upgradeplugintheme',
						array(
							'type' => $type,
							'list' => urldecode( implode( ',', $slugs ) ),
						)
					);

					/**
					* Action: mainwp_after_plugin_theme_translation_update
					*
					* Fires before plugin/theme/translation update actions.
					*
					* @since 4.1
					*/
					do_action( 'mainwp_after_plugin_theme_translation_update', $information, $type, implode( ',', $slugs ), $website );

					if ( isset( $information['sync'] ) ) {
						unset( $information['sync'] );
					}

					wp_send_json( $information );
				}
			}
		} catch ( MainWP_Exception $e ) {
			die( wp_json_encode( array( 'error' => MainWP_Error_Helper::get_error_message( $e ) ) ) );
		}

		die();
	}

	/**
	 * Method hook_get_websites_by_group_ids()
	 *
	 * Hook to get Child Sites by group ID.
	 *
	 * @param mixed $ids Group IDs.
	 * @param null  $userId Current user ID.
	 *
	 * @return (object|null) Database query result for get Child Sites by group ID or null on failure.
	 */
	public function hook_get_websites_by_group_ids( $ids, $userId = null ) {
		return MainWP_DB::instance()->get_websites_by_group_ids( $ids, $userId );
	}

	/**
	 * Method hook_posts_get_edit()
	 *
	 * Get and create post to edit.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Post_Page_Handler::get_post()
	 */
	public function hook_posts_get_edit() {
		MainWP_Post_Page_Handler::get_post();
		die();
	}

	/**
	 * Method hook_posts_bulk_posting()
	 *
	 * Post Bulk posting.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Post_Page_Handler::get_post()
	 */
	public function hook_posts_bulk_posting() {
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$post_id = isset( $_POST['post_id'] ) && ! empty( $_POST['post_id'] ) ? intval( wp_unslash( $_POST['post_id'] ) ) : false;
		if ( $post_id ) {
			MainWP_Post_Page_Handler::posting_posts( $post_id, 'ajax_posting' );
		}
		// phpcs:enable
		die();
	}

	/**
	 * Method hook_atarim_default_sitestable_column()
	 *
	 * @param mixed $columns columns data.
	 *
	 * Hook Atarim default column.
	 */
	public function hook_atarim_default_sitestable_column( $columns ) {
		if ( ! is_plugin_active( 'mainwp-atarim-extension/mainwp-atarim-extension.php' ) ) {
			$columns['atarim_tasks'] = esc_html__( 'Atarim', 'mainwp-atarim-extension' );
		}
		return $columns;
	}

	/**
	 * Method hook_atarim_manage_sites_default_item()
	 *
	 * @param mixed $item item row data.
	 *
	 * Hook Atarim manage sites default item.
	 */
	public function hook_atarim_manage_sites_default_item( $item ) {
		if ( ! is_plugin_active( 'mainwp-atarim-extension/mainwp-atarim-extension.php' ) ) {
			if ( is_array( $item ) && isset( $item['url'] ) ) {
				$collaborate_link     = 'https://app.atarim.io/fetching/?_from=mainwp&url=' . $item['url'];
				$item['atarim_tasks'] = '<a href="' . $collaborate_link . '" target="_balnk" data-tooltip="Collaborate on this website." data-inverted="" data-position="left center"><span class="ui blue icon label"><i class="comments icon"></i></span></a>';
			}
		}
		return $item;
	}

	/**
	 * Method hook_get_website_client_tokens()
	 *
	 * Hook get client tokens.
	 *
	 * @param mixed $input_value input filter value.
	 * @param int   $websiteid Website ID.
	 *
	 * @return mixed $result Result of tokens.
	 */
	public function hook_get_website_client_tokens( $input_value, $websiteid = false ) {
		$client_tokens = MainWP_Client_Handler::instance()->get_website_client_tokens_data( $websiteid );

		if ( false === $client_tokens ) {
			return $input_value;
		}

		return $client_tokens;
	}

	/**
	 * Method hook_get_download_sig()
	 *
	 * Hook get download sig.
	 *
	 * @param string $input_value Input value.
	 * @param string $fullfile Full file path.
	 *
	 * @return string
	 */
	public function hook_get_download_sig( $input_value, $fullfile ) {
		return MainWP_System_Utility::get_download_sig( $fullfile );
	}

	/**
	 * Method hook_download_valid_sig().
	 *
	 * Hook get download sig.
	 *
	 * @param string $false_value false value.
	 * @param string $fullfile Full file path.
	 * @param string $sig sig value.
	 */
	public function hook_secure_download( $false_value, $fullfile, $sig ) {
		unset( $false_value );
		return MainWP_System_Utility::valid_download_sig( $fullfile, $sig );
	}

	/**
	 * Method hook_ajax_add_action().
	 *
	 * Hook add ajax action.
	 *
	 * @param string $action action name.
	 * @param string $callback callback function.
	 * @param int    $priority priority action.
	 * @param int    $accepted number args.
	 */
	public function hook_ajax_add_action( $action, $callback, $priority = 10, $accepted = 2 ) {
		MainWP_Post_Handler::instance()->add_action( $action, $callback, $priority, $accepted );
	}

	/**
	 * Method hook_get_plugin_icon().
	 *
	 * Hook get plugin icon.
	 *
	 * @param mixed  $icon input icon.
	 * @param string $slug Plugin slug.
	 */
	public function hook_get_plugin_icon( $icon, $slug ) {
		return MainWP_System_Utility::get_plugin_icon( $slug );
	}

	/**
	 * Method hook_get_theme_icon().
	 *
	 * Hook get theme icon.
	 *
	 * @param mixed  $icon input icon.
	 * @param string $slug Theme slug.
	 */
	public function hook_get_theme_icon( $icon, $slug ) {
		return MainWP_System_Utility::get_theme_icon( $slug );
	}
	/**
	 * Method hook_get_dir_slug().
	 *
	 * Hook get dir slug of plugin/theme.
	 *
	 * @param mixed  $input input value.
	 * @param string $slug Plugin/Theme slug.
	 */
	public function hook_get_dir_slug( $input, $slug ) {
		return MainWP_Utility::get_dir_slug( $slug );
	}

	/**
	 * Method hook_do_widget_boxes()
	 *
	 * Customize WordPress do_meta_boxes() function.
	 *
	 * @param mixed       $screen_id Current page ID.
	 * @param string|null $context right|null. If 3 columns then = 'middle'.
	 * @param string      $input_obj Empty string.
	 *
	 * @return void Renders widget container box.
	 */
	public function hook_do_widget_boxes( $screen_id, $context = null, $input_obj = '' ) {
		MainWP_UI::do_widget_boxes( $screen_id, $context, $input_obj );
	}


	/**
	 * Method hook_add_widget_box()
	 *
	 * Hook add MainWP meta box.
	 *
	 * @param mixed       $id Widget ID parameter.
	 * @param mixed       $callback Callback function.
	 * @param null        $screen Current page.
	 * @param string|null $layout widget's layout .
	 *
	 * @return void Sets Global $mainwp_widget_boxes[ $page ][ $context ][ $priority ][ $id ].
	 */
	public function hook_add_widget_box( $id, $callback, $screen = null, $layout = null ) {
		if ( ! is_array( $layout ) ) {
			$layout = array();
		}
		MainWP_UI::add_widget_box( $id, $callback, $screen, $layout );
	}

	/**
	 * Method hook_render_modal_upload_icon()
	 *
	 * Render modal window for upload plugins & themes icon.
	 */
	public function hook_render_modal_upload_icon() {
		MainWP_UI::render_modal_upload_icon();
	}

	/**
	 * Method hook_render_plugin_details_modal()
	 *
	 * Render modal window plugin details.
	 */
	public function hook_render_plugin_details_modal() {
		MainWP_Updates::render_plugin_details_modal();
	}

	/**
	 * Method hook_render_updates()
	 *
	 * Render modal window updates details.
	 */
	public function hook_render_updates() {
		MainWP_Updates::render();
	}

	/**
	 * Method hook_get_wp_client_by()
	 *
	 * Handle get wp client by.
	 *
	 * @param string $by by.
	 * @param mixed  $value by value.
	 * @param mixed  $obj Format data.
	 * @param bool   $params Others params.
	 *
	 * @return mixed $result results.
	 */
	public function hook_get_wp_client_by( $by = 'client_id', $value = null, $obj = OBJECT, $params = array() ) {
		return MainWP_DB_Client::instance()->get_wp_client_by( $by, $value, $obj, $params );
	}

	/**
	 * Method hook_get_activation_info()
	 *
	 * Get extension activation info.
	 *
	 * @param bool   $boolean Input bool value.
	 * @param string $ext_slug extension api slug.
	 */
	public function hook_get_activation_info( $boolean, $ext_slug ) {
		$data = MainWP_Api_Manager::instance()->get_activation_info( $ext_slug );

		$info = array();

		if ( is_array( $data ) && ! empty( $data['api_key'] ) ) {
			$info['api_key']          = $data['api_key'];
			$info['product_id']       = isset( $data['product_id'] ) ? $data['product_id'] : '';
			$info['instance']         = isset( $data['instance_id'] ) ? $data['instance_id'] : '';
			$info['software_version'] = isset( $data['software_version'] ) ? $data['software_version'] : '';
			$info['object']           = MainWP_Api_Manager::instance()->get_domain();
			if ( isset( $data['product_item_id'] ) ) {
				$info['product_item_id'] = $data['product_item_id'];
			}
		} else {
			$info['activated_key'] = 'Deactivated';
		}

		return $info;
	}

	/**
	 * Method hook_get_api_url()
	 *
	 * Get MainWP API Url.
	 */
	public function hook_get_api_url() {
		return MainWP_Api_Manager::instance()->get_upgrade_url();
	}

	/**
	 * Method hook_run_dashboard_action().
	 *
	 * Handle run dashboard action.
	 *
	 * @param bool   $boolean Input bool value.
	 * @param string $action The action to run.
	 * @param bool   $out_die The function die or return.
	 *
	 * @return mixed $return result.
	 */
	public function hook_run_dashboard_action( $boolean, $action, $out_die = false ) {

		if ( ! isset( $action ) ) {
			return false;
		}

		if ( 'master_api_key_check' === $action ) {
			$return = $this->hook_master_api_key_check();
		} else {
			return false;
		}

		if ( $out_die ) {
			wp_send_json( $return );
		}

		return $return;
	}



	/**
	 * Method hook_master_api_key_check().
	 *
	 * Handle to valid MainWP API key.
	 *
	 * @return array $return Data.
	 */
	public function hook_master_api_key_check() {
		$api_key = MainWP_Api_Manager_Key::instance()->get_decrypt_master_api_key();
		$return  = array();
		$error   = '';
		if ( ! empty( $api_key ) ) {
			$result = array();
			try {
				$test   = MainWP_Api_Manager::instance()->verify_mainwp_api( $api_key );
				$result = json_decode( $test, true );
				if ( is_array( $result ) ) {
					if ( isset( $result['success'] ) && $result['success'] ) {
						$return['success'] = true;
					} elseif ( isset( $result['error'] ) ) {
						$error = $result['error'];
					}
				}
			} catch ( \Exception $e ) {
				$error = $e->getMessage();
			}
		} else {
			$error = esc_html__( 'MainWP API key are required.', 'mainwp' );
		}

		if ( ! empty( $error ) ) {
			$return['error'] = $error;
		}

		if ( ! isset( $return['success'] ) ) {
			$return['success'] = false;
		}

		return $return;
	}

	/**
	 * Method hook_get_key_value().
	 *
	 * Handle get key value.
	 *
	 * @param bool   $input_value Boolean value, it should always be FALSE.
	 * @param string $name Key option name.
	 * @param mixed  $default_value default value.
	 *
	 * @return array $return Decrypted Key value.
	 */
	public function hook_get_key_value( $input_value, $name, $default_value = false ) {
		return MainWP_Keys_Manager::instance()->get_keys_value( $name, $default_value );
	}

	/**
	 * Method hook_update_key_value().
	 *
	 * Handle update key value.
	 *
	 * @param bool   $input_value Boolean value, it should always be FALSE.
	 * @param string $name Key option name.
	 * @param mixed  $value Key value.
	 * @param string $prefix prefix of key file name.
	 *
	 * @return array $return Decrypted Key value.
	 */
	public function hook_update_key_value( $input_value, $name, $value = false, $prefix = 'ext_' ) {
		return MainWP_Keys_Manager::instance()->update_key_value( $name, $value, $prefix );
	}


	/**
	 * Method hook_delete_key_value().
	 *
	 * Handle delete key value.
	 *
	 * @param string $name Key option name.
	 *
	 * @return mixed Return Result.
	 */
	public function hook_delete_key_value( $name ) {
		return MainWP_Keys_Manager::instance()->update_key_value( $name, false ); // false: to delete.
	}

	/**
	 * Method hook_encrypt_key_value().
	 *
	 * Handle get key value.
	 *
	 * @param bool  $input_value Boolean value, it should always be FALSE.
	 * @param array $data Encrypted data.
	 * @param mixed $prefix prefix value.
	 * @param mixed $key_file Key file value.
	 *
	 * @return array $return Decrypted Key value.
	 */
	public function hook_encrypt_key_value( $input_value, $data, $prefix = 'ext_', $key_file = false ) {
		return MainWP_Keys_Manager::instance()->encrypt_keys_data( $data, $prefix, $key_file );
	}

	/**
	 * Method hook_decrypt_key_value().
	 *
	 * Handle get key value.
	 *
	 * @param bool  $input_value Boolean value, it should always be FALSE.
	 * @param array $encrypted_data Encrypted data.
	 * @param mixed $default_value default value.
	 *
	 * @return array $return Decrypted Key value.
	 */
	public function hook_decrypt_key_value( $input_value, $encrypted_data, $default_value = false ) {
		return MainWP_Keys_Manager::instance()->decrypt_keys_data( $encrypted_data, $default_value );
	}


	/**
	 * Method hook_delete_key_file().
	 *
	 * Handle delete key value.
	 *
	 * @param string $key_file Key file name.
	 *
	 * @return array $return Decrypted Key value.
	 */
	public function hook_delete_key_file( $key_file ) {
		return MainWP_Keys_Manager::instance()->delete_key_file( $key_file );
	}
}
