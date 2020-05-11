<?php
/**
 * MainWP Hooks
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Hooks
 */
class MainWP_Hooks {

	/**
	 * Method __contruct()
	 * 
	 * Initialize MainWP_Hooks actions/filters upon creation of the object.
	 * 
	 * @deprecated 4.0.7. Please use filter `mainwp_get_error_message` instead of `mainwp_getErrorMessage`.
	 */
	public function __construct() {
		add_filter( 'mainwp_getspecificdir', array( MainWP_System_Utility::get_class_name(), 'get_mainwp_specific_dir' ), 10, 1 );
		add_filter( 'mainwp_getmainwpdir', array( &$this, 'get_mainwp_dir' ), 10, 3 );
		add_filter( 'mainwp_is_multi_user', array( &$this, 'is_multi_user' ) );
		add_filter( 'mainwp_qq2fileuploader', array( &$this, 'filter_qq2_file_uploader' ), 10, 2 );
		add_action( 'mainwp_select_sites_box', array( &$this, 'select_sites_box' ), 10, 8 );
		add_action( 'mainwp_prepareinstallplugintheme', array( MainWP_Install_Bulk::get_class_name(), 'prepare_install' ) );
		add_action( 'mainwp_performinstallplugintheme', array( MainWP_Install_Bulk::get_class_name(), 'perform_install' ) );
		add_filter( 'mainwp_getwpfilesystem', array( MainWP_System_Utility::get_class_name(), 'get_wp_file_system' ) );
		add_filter( 'mainwp_getspecificurl', array( MainWP_System_Utility::get_class_name(), 'get_mainwp_specific_url' ), 10, 1 );
		add_filter( 'mainwp_getdownloadurl', array( MainWP_System_Utility::get_class_name(), 'get_download_url' ), 10, 2 );
		add_action( 'mainwp_renderHeader', array( MainWP_UI::get_class_name(), 'render_header' ), 10, 2 );
		add_action( 'mainwp_renderFooter', array( MainWP_UI::get_class_name(), 'render_footer' ), 10, 0 );

		add_action( 'mainwp_notify_user', array( &$this, 'notify_user' ), 10, 3 );
		add_action( 'mainwp_activePlugin', array( &$this, 'active_plugin' ), 10, 0 );
		add_action( 'mainwp_deactivePlugin', array( &$this, 'deactive_plugin' ), 10, 0 );
		add_action( 'mainwp_upgradePluginTheme', array( &$this, 'upgrade_plugin_theme' ), 10, 0 );
		add_action( 'mainwp_deletePlugin', array( &$this, 'delete_plugin' ), 10, 0 );
		add_action( 'mainwp_deleteTheme', array( &$this, 'delete_theme' ), 10, 0 );

		add_filter( 'mainwp_get_user_extension', array( &$this, 'get_user_extension' ) );
		add_filter( 'mainwp_getwebsitesbyurl', array( &$this, 'get_websites_by_url' ) );
		add_filter( 'mainwp_getWebsitesByUrl', array( &$this, 'get_websites_by_url' ) );

		/**
		 * @deprecated 4.0.7. Please use filter `mainwp_get_error_message` instead of `mainwp_getErrorMessage`.
		 */
		add_filter( 'mainwp_getErrorMessage', array( &$this, 'get_error_message' ), 10, 2 );
		add_filter( 'mainwp_get_error_message', array( &$this, 'get_error_message' ), 10, 2 );

		add_filter( 'mainwp_getwebsitesbygroupids', array( &$this, 'hook_get_websites_by_group_ids' ), 10, 2 );

		add_filter( 'mainwp_cache_getcontext', array( &$this, 'cache_getcontext' ) );
		add_action( 'mainwp_cache_echo_body', array( &$this, 'cache_echo_body' ) );
		add_action( 'mainwp_cache_init', array( &$this, 'cache_init' ) );
		add_action( 'mainwp_cache_add_context', array( &$this, 'cache_add_context' ), 10, 2 );
		add_action( 'mainwp_cache_add_body', array( &$this, 'cache_add_body' ), 10, 2 );

		add_filter( 'mainwp_getmetaboxes', array( &$this, 'get_meta_boxes' ), 10, 0 );
		add_filter( 'mainwp_getnotificationemail', array( MainWP_System_Utility::get_class_name(), 'get_notification_email' ), 10, 1 );
		add_filter( 'mainwp_getformatemail', array( &$this, 'get_format_email' ), 10, 3 );
		add_filter( 'mainwp-extension-available-check', array( MainWP_Extensions_Handler::get_class_name(), 'is_extension_available' ) );
		add_action( 'mainp_log_debug', array( &$this, 'mainwp_log_debug' ), 10, 1 );
		add_action( 'mainp_log_info', array( &$this, 'mainwp_log_info' ), 10, 1 );
		add_action( 'mainp_log_warning', array( &$this, 'mainwp_log_warning' ), 10, 1 );
		add_filter( 'mainwp_getactivateextensionnotice', array( &$this, 'get_activate_extension_notice' ), 10, 1 );
		add_action( 'mainwp_enqueue_meta_boxes_scripts', array( &$this, 'enqueue_meta_boxes_scripts' ), 10, 1 );
		add_filter( 'mainwp_addsite', array( &$this, 'mainwp_add_site' ), 10, 1 );
		add_filter( 'mainwp_deletesite', array( &$this, 'hook_delete_site' ), 10, 1 );
		add_filter( 'mainwp_clonesite', array( &$this, 'filter_clone_site' ), 10, 6 );
		add_filter( 'mainwp_delete_clonesite', array( &$this, 'filter_delete_clone_site' ), 10, 4 );
		add_filter( 'mainwp_editsite', array( &$this, 'mainwp_edit_site' ), 10, 1 );
		add_action( 'mainwp_add_sub_leftmenu', array( &$this, 'hook_add_sub_left_menu' ), 10, 6 );
		add_filter( 'mainwp_getwebsiteoptions', array( &$this, 'get_website_options' ), 10, 3 );
		add_filter( 'mainwp_addgroup', array( MainWP_Extensions_Handler::get_class_name(), 'hook_add_group' ), 10, 3 );
		add_filter( 'mainwp_getallposts', array( &$this, 'hook_get_all_posts' ), 10, 2 );
		add_filter( 'mainwp_check_current_user_can', array( &$this, 'hook_current_user_can' ), 10, 3 );
	}

	/**
	 * Method mainwp_log_debug()
	 * 
	 * MainWP debug log.
	 * 
	 * @param mixed $pText Debug text.
	 * 
	 * @return string Debug text.
	 */
	public function mainwp_log_debug( $pText ) {
		MainWP_Logger::instance()->debug( $pText );
	}

	/**
	 * Method mainwp_log_info()
	 * 
	 * MainWP Log Info.
	 * 
	 * @param mixed $pText Info Text.
	 * 
	 * @return string Info Text.
	 */
	public function mainwp_log_info( $pText ) {
		MainWP_Logger::instance()->info( $pText );
	}

	/**
	 * Method mainwp_log_warning()
	 * 
	 * MainWP Log Warning.
	 * 
	 * @param mixed $pText Warning Text.
	 * 
	 * @return string Warning Text.
	 */
	public function mainwp_log_warning( $pText ) {
		MainWP_Logger::instance()->warning( $pText );
	}

	/**
	 * Method enqueue_meta_boxes_scripts()
	 * 
	 * Enqueue Scripts for all Meta boxes.
	 */
	public function enqueue_meta_boxes_scripts() {
		MainWP_System::enqueue_postbox_scripts();
	}

	/**
	 * Method mainwp_add_site()
	 * 
	 * Hook to add Child Site.
	 *
	 * @since 3.2.2
	 * @param array $params site data fields: url, name, wpadmin, unique_id, groupids, ssl_verify, ssl_version, http_user, http_pass, websiteid - if edit site
	 *
	 * @return array $ret data fields: response, siteid.
	 */
	public function mainwp_add_site( $params ) {
		$ret = array();

		if ( is_array( $params ) ) {
			if ( isset( $params['websiteid'] ) && MainWP_Utility::ctype_digit( $params['websiteid'] ) ) {
				$ret['siteid'] = self::update_wp_site( $params );
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
	 * Method hook_delete_site()
	 * 
	 * Hook to delete Child Site. 
	 * 
	 * @param boolean $site_id Child Site ID.
	 * 
	 * @return (boolean|array) Return false if empty and return array error - Site not found | result - SUCCESS.
	 */
	public function hook_delete_site( $site_id = false ) {

		if ( empty( $site_id ) ) {
			return false;
		}

		$sql      = MainWP_DB::instance()->get_sql_website_by_id( $site_id );
		$websites = MainWP_DB::instance()->query( $sql );
		$site     = MainWP_DB::fetch_object( $websites );

		if ( empty( $site ) ) {
			return array( 'error' => __( 'Not found the website', 'mainwp' ) );
		}
		$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

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
	 * @param mixed $websiteid Child Site ID.
	 * @param mixed $cloneid Cloan ID
	 * @param mixed $clone_url Cloan URL.
	 * @param boolean $force_update Force the update, true|false, Default: false.
	 * 
	 * @return array Site array to clone.
	 */
	public function filter_clone_site( $pluginFile, $key, $websiteid, $cloneid, $clone_url, $force_update = false ) {
		return MainWP_Extensions_Handler::hook_clone_site( $pluginFile, $key, $websiteid, $cloneid, $clone_url, $force_update );
	}

	/**
	 * Method filter_delete_clone_site()
	 * 
	 * Hook to delete cloaned Child Site.
	 * 
	 * @param mixed $pluginFile Plugin file.
	 * @param mixed $key Key.
	 * @param string $clone_url Cloan URL.
	 * @param boolean $clone_site_id Cloan Site ID.
	 * 
	 * @return array Site array to delete.
	 */
	public function filter_delete_clone_site( $pluginFile, $key, $clone_url = '', $clone_site_id = false ) {
		return MainWP_Extensions_Handler::hook_delete_clone_site( $pluginFile, $key, $clone_url, $clone_site_id );
	}

	/**
	 * Method mainwp_edit_site()
	 * 
	 * Hook to edit Child Site.
	 *
	 * @since 3.2.2
	 * @param array $params site data fields: websiteid, name, wpadmin, unique_id
	 *
	 * @return int $ret Child site ID.
	 */
	public function mainwp_edit_site( $params ) {
		$ret = array();
		if ( is_array( $params ) ) {
			if ( isset( $params['websiteid'] ) && MainWP_Utility::ctype_digit( $params['websiteid'] ) ) {
				$ret['siteid'] = self::update_wp_site( $params );
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
	 * @param mixed $title Menu title.
	 * @param mixed $slug Menu Slug.
	 * @param mixed $href Menu link.
	 * @param integer $level Menu Level.
	 * @param string $parent_key Parent menu.
	 * 
	 * @return array $mainwp_leftmenu[] | $mainwp_sub_leftmenu[].
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
	 * Method update_wp_site()
	 * 
	 * Update Child Site.
	 * 
	 * @param mixed $params Udate parameters. 
	 * 
	 * @return int Child Site ID on success and return 0 on failer.
	 */
	public static function update_wp_site( $params ) {
		if ( ! isset( $params['websiteid'] ) || ! MainWP_Utility::ctype_digit( $params['websiteid'] ) ) {
			return 0;
		}

		if ( isset( $params['is_staging'] ) ) {
			unset( $params['is_staging'] );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $params['websiteid'] );
		if ( null == $website ) {
			return 0;
		}

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			return 0;
		}

		$data     = array();
		$uniqueId = null;

		if ( isset( $params['name'] ) && ! empty( $params['name'] ) ) {
			$data['name'] = htmlentities( $params['name'] );
		}

		if ( isset( $params['wpadmin'] ) && ! empty( $params['wpadmin'] ) ) {
			$data['adminname'] = $params['wpadmin'];
		}

		if ( isset( $params['unique_id'] ) ) {
			$data['uniqueId'] = $params['unique_id'];
			$uniqueId         = $params['unique_id'];
		}

		if ( empty( $data ) ) {
			return 0;
		}

		MainWP_DB::instance()->update_website_values( $website->id, $data );
		if ( null !== $uniqueId ) {
			try {
				$information = MainWP_Connect::fetch_url_authed( $website, 'update_values', array( 'uniqueId' => $uniqueId ) );
			} catch ( MainWP_Exception $e ) {
				$error = $e->getMessage();
			}
		}
		do_action( 'mainwp_updated_site', $website->id, $data );
		return $website->id;
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

		return sprintf( __( 'You have a MainWP extension that does not have an active API entered. This means you will not receive updates or support. Please visit the %1$sExtensions%2$s page and enter your API key.', 'mainwp' ), '<a href="admin.php?page=Extensions">', '</a>' );
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
	 * 
	 *  @return string Cached Seach body html.
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
	 * 
	 * @return void
	 */
	public function cache_init( $page ) {
		MainWP_Cache::init_cache( $page );
	}

	/**
	 * Method cache_add_context()
	 * 
	 * Add time & Search Context session variable.
	 * 
	 * @param string $page Current MainWP Page.
	 * @param mixed $context Time of search.
	 * 
	 * @return void
	 */
	public function cache_add_context( $page, $context ) {
		MainWP_Cache::add_context( $page, $context );
	}

	/**
	 * Method cache_add_body()
	 * 
	 * Add Search Body Session variable.
	 * 
	 * @param string $page Current MainWP Page.
	 * @param mixed $body Search body.
	 * 
	 * @return void
	 */
	public function cache_add_body( $page, $body ) {
		MainWP_Cache::add_body( $page, $body );
	}

	/**
	 * Method select_sites_box()
	 * 
	 * Select sites box.
	 * 
	 * @param string $title Input title.
	 * @param string $type Input type, radio.
	 * @param boolean $show_group Whether or not to show group, Default: true.
	 * @param boolean $show_select_all Whether to show select all.
	 * @param string $class Default = ''.
	 * @param string $style Default = ''.
	 * @param array $selected_websites Selected Child Sites.
	 * @param array $selected_groups Selected Groups.
	 * 
	 * @return string MainWP Select Sites Box html.
	 */
	public function select_sites_box( $title = '', $type = 'checkbox', $show_group = true, $show_select_all = true, $class = '', $style = '', $selected_websites = array(), $selected_groups = array() ) {
		MainWP_UI::select_sites_box( $type, $show_group, $show_select_all, $class, $style, $selected_websites, $selected_groups );
	}

	public function notify_user( $userId, $subject, $content ) {
		wp_mail(
			MainWP_DB_Common::instance()->get_user_notification_email( $userId ),
			$subject,
			$content,
			array(
				'From: "' . get_option( 'admin_email' ) . '" <' . get_option( 'admin_email' ) . '>',
				'content-type: text/html',
			)
		);
	}

	public function get_error_message( $msg, $extra ) {
		return MainWP_Error_Helper::get_error_message( new MainWP_Exception( $msg, $extra ) );
	}

	public function get_user_extension() {
		return MainWP_DB_Common::instance()->get_user_extension();
	}

	public function get_website_options( $boolean, $website, $name = '' ) {

		if ( empty( $name ) ) {
			return $boolean;
		}

		if ( is_numeric( $website ) ) {
			$obj     = new \stdClass();
			$obj->id = $website;
			$website = $obj;
		}

		return MainWP_DB::instance()->get_website_option( $website, $name );
	}

	public function get_websites_by_url( $url ) {
		return MainWP_DB::instance()->get_websites_by_url( $url );
	}

	/**
	 * Hook to get posts from sites
	 *
	 * @since 3.4.4
	 * @param $pluginFile, $key, $sites, $post_data
	 * @param array       $post_data with values: keyword, dtsstart, dtsstop, status, maxRecords, post_type
	 * @return array
	 */
	public function hook_get_all_posts( $sites, $post_data = array() ) {

		$dbwebsites = array();
		$data       = array( 'id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey', 'verify_certificate', 'ssl_version', 'http_user', 'http_pass' );

		if ( '' !== $sites ) {
			foreach ( $sites as $k => $v ) {
				if ( MainWP_Utility::ctype_digit( $v ) ) {
					$website                    = MainWP_DB::instance()->get_website_by_id( $v );
					$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data );
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
				$output,
				$is_external_hook = true
			);
		}
		return $output;
	}

	public function hook_current_user_can( $input, $can_type, $which ) {

		if ( function_exists( 'mainwp_current_user_have_right' ) ) {
			return mainwp_current_user_have_right( $can_type, $which );
		}

		return $input;
	}

	public function get_mainwp_dir( $false = false, $dir = null, $direct_access = false ) {

		$dirs = MainWP_System_Utility::get_mainwp_dir();

		$newdir = $dirs[0] . ( null != $dir ? $dir . DIRECTORY_SEPARATOR : '' );
		$url    = $dirs[1] . '/' . $dir . '/';

		$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

		global $wp_filesystem;

		if ( null != $dir ) {

			if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {

				if ( ! $wp_filesystem->exists( $newdir ) ) {
					$wp_filesystem->mkdir( $newdir, 0777, true );
				}

				if ( $direct_access ) {
					if ( ! $wp_filesystem->exists( trailingslashit( $newdir ) . 'index.php' ) ) {
						$wp_filesystem->touch( trailingslashit( $newdir ) . 'index.php' );
					}
					if ( $wp_filesystem->exists( trailingslashit( $newdir ) . '.htaccess' ) ) {
						$wp_filesystem->delete( trailingslashit( $newdir ) . '.htaccess' );
					}
				} else {
					if ( ! $wp_filesystem->exists( trailingslashit( $newdir ) . '.htaccess' ) ) {
						$wp_filesystem->put_contents( trailingslashit( $newdir ) . '.htaccess', 'deny from all' );
					}
				}
			} else {
				// phpcs:disable -- to support when $wp_filesystem failed.
				if ( ! file_exists( $newdir ) ) {
					@mkdir( $newdir, 0777, true );
				}

				if ( $direct_access ) {
					if ( ! file_exists(trailingslashit( $newdir ) . 'index.php' ) ) {
						@touch( trailingslashit( $newdir ) . 'index.php' );
					}
					if ( file_exists( trailingslashit( $newdir ) . '.htaccess' ) ) {
						@unlink( trailingslashit( $newdir ) . '.htaccess' );
					}
				} else {
					if ( ! file_exists( trailingslashit( $newdir ) . '.htaccess' ) ) {
						$file = @fopen( trailingslashit( $newdir  ) . '.htaccess', 'w+' );
						@fwrite( $file, 'deny from all' );
						@fclose( $file );
					}
				}
				// phpcs:enable
			}
		}

		return array( $newdir, $url );
	}


	public function is_multi_user() {
		return MainWP_System::instance()->is_multi_user();
	}

	public function filter_qq2_file_uploader( $allowedExtensions, $sizeLimit ) {
		return new MainWP_QQ2_File_Uploader( $allowedExtensions, $sizeLimit );
	}

	public function get_meta_boxes() {
		return MainWP_System::instance()->metaboxes;
	}

	public function get_format_email( $body, $email, $title = '' ) {
		return MainWP_Format::format_email( $email, $body, $title );
	}

	public function active_plugin() {
		MainWP_Plugins_Handler::activate_plugins();
		die();
	}

	public function deactive_plugin() {
		MainWP_Plugins_Handler::deactivate_plugins();
		die();
	}

	public function delete_plugin() {
		MainWP_Plugins_Handler::delete_plugins();
		die();
	}

	public function delete_theme() {
		MainWP_Themes_Handler::delete_themes();
		die();
	}

	public function upgrade_plugin_theme() {
		try {
			$websiteId = null;
			$type      = null;
			$slugs     = array();
			if ( isset( $_POST['websiteId'] ) ) {
				$websiteId = $_POST['websiteId'];
			}
			if ( isset( $_POST['slugs'] ) ) {
				$slugs = $_POST['slugs'];
			}

			if ( isset( $_POST['type'] ) ) {
				$type = $_POST['type'];
			}

			$error = '';
			if ( 'plugin' === $type && ! mainwp_current_user_have_right( 'dashboard', 'update_plugins' ) ) {
				$error = mainwp_do_not_have_permissions( __( 'update plugins', 'mainwp' ), false );
			} elseif ( 'theme' === $type && ! mainwp_current_user_have_right( 'dashboard', 'update_themes' ) ) {
				$error = mainwp_do_not_have_permissions( __( 'update themes', 'mainwp' ), false );
			}

			if ( ! empty( $error ) ) {
				wp_send_json( array( 'error' => $error ) );
			}

			if ( MainWP_Utility::ctype_digit( $websiteId ) ) {
				$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
				if ( MainWP_System_Utility::can_edit_website( $website ) ) {
					$information = MainWP_Connect::fetch_url_authed(
						$website,
						'upgradeplugintheme',
						array(
							'type'   => $type,
							'list'   => urldecode( implode( ',', $slugs ) ),
						)
					);
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

	public function hook_get_websites_by_group_ids( $ids, $userId = null ) {
		return MainWP_DB::instance()->get_websites_by_group_ids( $ids, $userId );
	}

}
