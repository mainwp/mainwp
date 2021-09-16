<?php
/**
 * System Handler
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_System_Handler
 *
 * @package MainWP\Dashboard
 */
class MainWP_System_Handler {

	// phpcs:disable WordPress.WP.AlternativeFunctions -- use system functions

	/**
	 * Private static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Private variable to hold the upgrade version info.
	 *
	 * @var null Version info.
	 */
	private $upgradeVersionInfo = null;

	/**
	 * Method instance()
	 *
	 * Create public static instance.
	 *
	 * @static
	 * @return MainWP_System
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * MainWP_System_Handler constructor.
	 *
	 * Run each time the class is called.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_class_name()
	 */
	public function __construct() {
		add_filter( 'mainwp-extension-enabled-check', array( MainWP_Extensions_Handler::get_class_name(), 'is_extension_enabled' ) ); // @deprecated Use 'mainwp_extension_enabled_check' instead.
		add_filter( 'mainwp_extension_enabled_check', array( MainWP_Extensions_Handler::get_class_name(), 'is_extension_enabled' ) );

		/**
		 * This hook allows you to get a list of sites via the 'mainwp-getsites' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getsites
		 *
		 * @see \MainWP_Extensions::hook_get_sites
		 */
		add_filter( 'mainwp-getsites', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_sites' ), 10, 5 );     // @deprecated Use 'mainwp_getsites' instead.
		add_filter( 'mainwp-getdbsites', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_db_sites' ), 10, 5 ); // @deprecated Use 'mainwp_getdbsites' instead.

		add_filter( 'mainwp_getsites', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_sites' ), 10, 5 );
		add_filter( 'mainwp_getdbsites', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_db_sites' ), 10, 5 );

		/**
		 * This hook allows you to get a information about groups via the 'mainwp-getgroups' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getgroups
		 *
		 * @see \MainWP_Extensions::hook_get_groups
		 */
		add_filter( 'mainwp-getgroups', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_groups' ), 10, 4 ); // @deprecated Use 'mainwp_getgroups' instead.
		add_filter( 'mainwp_getgroups', array( MainWP_Extensions_Handler::get_class_name(), 'hook_get_groups' ), 10, 4 );
		add_action( 'mainwp_fetchurlsauthed', array( &$this, 'filter_fetch_urls_authed' ), 10, 7 );
		add_filter( 'mainwp_fetchurlauthed', array( &$this, 'filter_fetch_url_authed' ), 10, 6 );
		add_filter(
			'mainwp_getdashboardsites',
			array(
				MainWP_Extensions_Handler::get_class_name(),
				'hook_get_dashboard_sites',
			),
			10,
			7
		);

		// @deprecated Use 'mainwp_manager_getextensions' instead.
		add_filter(
			'mainwp-manager-getextensions',
			array(
				MainWP_Extensions_Handler::get_class_name(),
				'hook_get_all_extensions',
			)
		);

		add_filter(
			'mainwp_manager_getextensions',
			array(
				MainWP_Extensions_Handler::get_class_name(),
				'hook_get_all_extensions',
			)
		);

		if ( '' != get_option( 'mainwp_upgradeVersionInfo' ) ) {
			$this->upgradeVersionInfo = get_option( 'mainwp_upgradeVersionInfo' );
			if ( ! is_object( $this->upgradeVersionInfo ) ) {
				$this->upgradeVersionInfo = new \stdClass();
			}
		}
	}

	/**
	 * Method filter_fetch_urls_authed()
	 *
	 * Filter fetch authorized urls.
	 *
	 * @param mixed  $pluginFile MainWP extention.
	 * @param string $key MainWP Licence Key.
	 * @param object $dbwebsites Child Sites.
	 * @param string $what Function to perform.
	 * @param array  $params Function paramerters.
	 * @param mixed  $handle Function handle.
	 * @param mixed  $output Function output.
	 *
	 * @return mixed MainWP_Extensions_Handler::hook_fetch_urls_authed() Hook fetch authorized URLs.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::hook_fetch_urls_authed()
	 */
	public function filter_fetch_urls_authed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output ) {
		return MainWP_Extensions_Handler::hook_fetch_urls_authed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output, $is_external_hook = true );
	}

	/**
	 * Method filter_fetch_url_authed()
	 *
	 * Filter fetch Authorized URL.
	 *
	 * @param mixed  $pluginFile MainWP extention.
	 * @param string $key MainWP licence key.
	 * @param int    $websiteId Website ID.
	 * @param string $what Function to perform.
	 * @param array  $params Function paramerters.
	 * @param null   $raw_response Raw response.
	 *
	 * @return mixed MainWP_Extensions_Handler::hook_fetch_url_authed() Hook fetch authorized URL.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::hook_fetch_url_authed()
	 */
	public function filter_fetch_url_authed( $pluginFile, $key, $websiteId, $what, $params, $raw_response = null ) {
		return MainWP_Extensions_Handler::hook_fetch_url_authed( $pluginFile, $key, $websiteId, $what, $params, $raw_response );
	}

	/**
	 * Method apply_filter()
	 *
	 * Apply filter
	 *
	 * @param string $filter The filter.
	 * @param array  $value Input value.
	 *
	 * @return array $output Output array.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::hook_verify()
	 */
	public function apply_filters( $filter, $value = array() ) {

		if ( 'mainwp-getmetaboxes' === $filter ) {
			$output = apply_filters_deprecated( 'mainwp-getmetaboxes', array( $value ), '4.0.7.2', 'mainwp_getmetaboxes' );  // @deprecated Use 'mainwp_getmetaboxes' instead.
		} else {
			$output = apply_filters( $filter, $value );
		}

		if ( ! is_array( $output ) ) {
			return array();
		}
		$count = count( $output );
		for ( $i = 0; $i < $count; $i ++ ) {

			if ( 'mainwp_getmetaboxes' === $filter ) {
				// pass custom widget.
				if ( isset( $output[ $i ]['custom'] ) && $output[ $i ]['custom'] && isset( $output[ $i ]['plugin'] ) ) {
					continue;
				}
			}

			if ( ! isset( $output[ $i ]['plugin'] ) || ! isset( $output[ $i ]['key'] ) ) {
				unset( $output[ $i ] );
				continue;
			}

			if ( ! MainWP_Extensions_Handler::hook_verify( $output[ $i ]['plugin'], $output[ $i ]['key'] ) ) {
				unset( $output[ $i ] );
				continue;
			}
		}

		return $output;
	}

	/**
	 * Method handle_manage_sites_screen_settings()
	 *
	 * Handle manage sites screen settings
	 */
	public function handle_manage_sites_screen_settings() {
		if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'ManageSitesScrOptions' ) ) {
			$hide_cols = array();
			foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST ) ) as $key => $val ) {
				if ( false !== strpos( $key, 'mainwp_hide_column_' ) ) {
					$col         = str_replace( 'mainwp_hide_column_', '', $key );
					$hide_cols[] = $col;
				}
			}
			$user = wp_get_current_user();
			if ( $user ) {
				update_user_option( $user->ID, 'mainwp_settings_hide_manage_sites_columns', $hide_cols, true );
				update_option( 'mainwp_default_sites_per_page', ( isset( $_POST['mainwp_default_sites_per_page'] ) ? intval( $_POST['mainwp_default_sites_per_page'] ) : 25 ) );
			}
		}
	}

	/**
	 * Method handle_mainwp_tools_settings()
	 *
	 * Handle mainwp tools settings.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Twitter::clear_all_twitter_messages()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function handle_mainwp_tools_settings() {
		$update_screen_options = false;
		if ( isset( $_POST['submit'] ) && isset( $_GET['page'] ) && 'MainWPTools' === $_GET['page'] ) {
			if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'MainWPTools' ) ) {
				$update_screen_options = true;
				MainWP_Utility::update_option( 'mainwp_enable_managed_cr_for_wc', ( ! isset( $_POST['enable_managed_cr_for_wc'] ) ? 0 : 1 ) );
				MainWP_Utility::update_option( 'mainwp_use_favicon', ( ! isset( $_POST['mainwp_use_favicon'] ) ? 0 : 1 ) );
				MainWP_Utility::update_option( 'mainwp_enable_screenshots', ( ! isset( $_POST['enable_screenshots_feature'] ) ? 0 : 1 ) );

				$enabled_twit = ! isset( $_POST['mainwp_hide_twitters_message'] ) ? 0 : 1;
				MainWP_Utility::update_option( 'mainwp_hide_twitters_message', $enabled_twit );
				if ( ! $enabled_twit ) {
					MainWP_Twitter::clear_all_twitter_messages();
				}
			}
		} elseif ( ( isset( $_GET['page'] ) && 'mainwp_tab' === $_GET['page'] ) || isset( $_GET['dashboard'] ) ) {
			if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'MainWPScrOptions' ) ) {
				$update_screen_options = true;
			}
		}

		if ( $update_screen_options ) {
			$hide_wids = array();
			if ( isset( $_POST['mainwp_hide_widgets'] ) && is_array( $_POST['mainwp_hide_widgets'] ) ) {
				$hide_wids = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_hide_widgets'] ) );
			}
			$user = wp_get_current_user();
			if ( $user ) {
				update_user_option( $user->ID, 'mainwp_settings_hide_widgets', $hide_wids, true );
			}

			MainWP_Utility::update_option( 'mainwp_hide_update_everything', ( ! isset( $_POST['hide_update_everything'] ) ? 0 : 1 ) );
			MainWP_Utility::update_option( 'mainwp_number_overview_columns', ( isset( $_POST['number_overview_columns'] ) ? intval( $_POST['number_overview_columns'] ) : 2 ) );
		}
	}


	/**
	 * Method handle_rest_api_settings()
	 *
	 * Handle rest api settings
	 */
	public function handle_rest_api_settings() {
		$update_screen_options = false;
		if ( isset( $_POST['submit'] ) && isset( $_GET['page'] ) && 'RESTAPI' === $_GET['page'] ) {
			if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'RESTAPI' ) ) {
				MainWP_Utility::update_option( 'mainwp_enable_rest_api', ( ! isset( $_POST['mainwp_enable_rest_api'] ) ? 0 : 1 ) );

			}
		}
	}

	/**
	 * Method handle_settings_post()
	 *
	 * Handle saving settings page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Backup_Handler::handle_settings_post()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_Monitoring_Handler::handle_settings_post()
	 * @uses \MainWP\Dashboard\MainWP_Settings::handle_settings_post()
	 */
	public function handle_settings_post() {
		if ( isset( $_GET['page'] ) && isset( $_POST['wp_nonce'] ) ) {
			$this->include_pluggable();
			$this->handle_mainwp_tools_settings();
			$this->handle_rest_api_settings();
			$this->handle_manage_sites_screen_settings();
		}

		if ( isset( $_POST['select_mainwp_options_siteview'] ) ) {
			$this->include_pluggable();
			if ( check_admin_referer( 'mainwp-admin-nonce' ) ) {
				$userExtension            = MainWP_DB_Common::instance()->get_user_extension();
				$userExtension->site_view = ( empty( $_POST['select_mainwp_options_siteview'] ) ? MAINWP_VIEW_PER_PLUGIN_THEME : intval( $_POST['select_mainwp_options_siteview'] ) );
				MainWP_DB_Common::instance()->update_user_extension( $userExtension );
			}
		}

		if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) ) {
			$this->include_pluggable();
			if ( wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'Settings' ) ) {
				$updated  = MainWP_Settings::handle_settings_post();
				$updated |= MainWP_Backup_Handler::handle_settings_post();
				$updated |= MainWP_Monitoring_Handler::handle_settings_post();
				$msg      = '';
				if ( $updated ) {
					$msg = '&message=saved';
				}
				wp_safe_redirect( admin_url( 'admin.php?page=Settings' . $msg ) );
				exit();
			}
		}

		if ( isset( $_POST['mainwp_sidebar_position'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'onchange_sidebarposition' ) ) {
			$val  = isset( $_POST['mainwp_sidebar_position'] ) && $_POST['mainwp_sidebar_position'] ? 1 : 0;
			$user = wp_get_current_user();
			if ( $user ) {
				update_user_option( $user->ID, 'mainwp_sidebarPosition', $val, true );
			}
			return true;
		}
	}

	/**
	 * Method include_pluggable()
	 *
	 * Include pluggable functions.
	 */
	public function include_pluggable() {
		// may causing of conflict with Post S m t p plugin.
		if ( ! function_exists( 'wp_create_nonce' ) ) {
			include_once ABSPATH . WPINC . '/pluggable.php';
		}
	}

	/**
	 * Method plugins_api_info()
	 *
	 * Get MainWP Extension api information.
	 *
	 * @param mixed $false Return value.
	 * @param mixed $action Action being performed.
	 * @param mixed $arg Action arguments. Should be the plugin slug.
	 *
	 * @return mixed $info|$false
	 *
	 * @uses \MainWP\Dashboard\MainWP_API_Handler::get_plugin_information()
	 * @uses \MainWP\Dashboard\MainWP_Extensions_View::get_available_extensions()
	 * @uses \MainWP\Dashboard\MainWP_System::get_plugin_slug()
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_slugs()
	 */
	public function plugins_api_info( $false, $action, $arg ) {
		if ( 'plugin_information' !== $action ) {
			return $false;
		}

		if ( is_array( $arg ) ) {
			$arg = (object) $arg;
		}

		if ( ! isset( $arg->slug ) || ( '' === $arg->slug ) ) {
			return $false;
		}

		if ( dirname( MainWP_System::instance()->get_plugin_slug() ) === $arg->slug ) {
			return $false;
		}

		$result   = MainWP_Extensions_Handler::get_slugs();
		$am_slugs = $result['am_slugs'];

		if ( '' !== $am_slugs ) {
			$am_slugs = explode( ',', $am_slugs );
			$dir_slug = dirname( $arg->slug );
			if ( in_array( $dir_slug, $am_slugs ) ) {
				$info = MainWP_API_Handler::get_plugin_information( $dir_slug );
				if ( is_object( $info ) && property_exists( $info, 'sections' ) ) {
					if ( ! is_array( $info->sections ) || ! isset( $info->sections['changelog'] ) || empty( $info->sections['changelog'] ) ) {
						$exts_data = MainWP_Extensions_View::get_available_extensions();
						if ( isset( $exts_data[ $arg->slug ] ) ) {
							$ext_info                    = $exts_data[ $arg->slug ];
							$changelog_link              = rtrim( $ext_info['link'], '/' );
							$info->sections['changelog'] = '<a href="' . $changelog_link . '#tab-changelog" target="_blank">' . $changelog_link . '#tab-changelog</a>';
						}
					}
					return $info;
				}
				return $info;
			}
		}

		return $false;
	}

	/**
	 * Method check_update_custom()
	 *
	 * Check MainWP Extensions for updates.
	 *
	 * @param object $transient Transient information.
	 *
	 * @return object $transient Transient information.
	 *
	 * @uses \MainWP\Dashboard\MainWP_API_Handler::get_upgrade_information()
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_indexed_extensions_infor()
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_extension_slug()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function check_update_custom( $transient ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		if ( isset( $_POST['action'] ) && ( ( 'update-plugin' === $_POST['action'] ) || ( 'update-selected' === $_POST['action'] ) ) ) {
			$extensions = MainWP_Extensions_Handler::get_indexed_extensions_infor( array( 'activated' => true ) );
			if ( defined( 'DOING_AJAX' ) && isset( $_POST['plugin'] ) && 'update-plugin' == $_POST['action'] ) {
				$plugin_slug = sanitize_text_field( wp_unslash( $_POST['plugin'] ) );
				if ( isset( $extensions[ $plugin_slug ] ) ) {
					if ( isset( $transient->response[ $plugin_slug ] ) && version_compare( $transient->response[ $plugin_slug ]->new_version, $extensions[ $plugin_slug ]['version'], '=' ) ) {
						return $transient;
					}

					$api_slug = dirname( $plugin_slug );
					$rslt     = MainWP_API_Handler::get_upgrade_information( $api_slug );

					if ( ! empty( $rslt ) && isset( $rslt->latest_version ) && version_compare( $rslt->latest_version, $extensions[ $plugin_slug ]['version'], '>' ) ) {
						$transient->response[ $plugin_slug ] = self::map_rslt_obj( $rslt );
					}

					return $transient;
				}
			} elseif ( 'update-selected' === $_POST['action'] && isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ) {
				$updated = false;
				foreach ( wp_unslash( $_POST['checked'] ) as $plugin_slug ) {
					if ( isset( $extensions[ $plugin_slug ] ) ) {
						if ( isset( $transient->response[ $plugin_slug ] ) && version_compare( $transient->response[ $plugin_slug ]->new_version, $extensions[ $plugin_slug ]['version'], '=' ) ) {
							continue;
						}
						$api_slug = dirname( $plugin_slug );
						$rslt     = MainWP_API_Handler::get_upgrade_information( $api_slug );
						if ( ! empty( $rslt ) && isset( $rslt->latest_version ) && version_compare( $rslt->latest_version, $extensions[ $plugin_slug ]['version'], '>' ) ) {

							$this->upgradeVersionInfo->result[ $api_slug ] = $rslt;
							$transient->response[ $plugin_slug ]           = self::map_rslt_obj( $rslt );
							$updated                                       = true;
						}
					}
				}
				if ( $updated ) {
					MainWP_Utility::update_option( 'mainwp_upgradeVersionInfo', $this->upgradeVersionInfo );
				}

				return $transient;
			}
		}

		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		if ( isset( $_GET['do'] ) && 'checkUpgrade' === $_GET['do'] && ( ( time() - $this->upgradeVersionInfo->updated ) > 30 ) ) {
			$this->check_upgrade();
		}

		if ( null != $this->upgradeVersionInfo && property_exists( $this->upgradeVersionInfo, 'result' ) && is_array( $this->upgradeVersionInfo->result ) ) {
			foreach ( $this->upgradeVersionInfo->result as $rslt ) {
				if ( ! isset( $rslt->slug ) ) {
					continue;
				}

				$plugin_slug = MainWP_Extensions_Handler::get_extension_slug( $rslt->slug );
				if ( isset( $transient->checked[ $plugin_slug ] ) && version_compare( $rslt->latest_version, $transient->checked[ $plugin_slug ], '>' ) ) {
					$transient->response[ $plugin_slug ] = self::map_rslt_obj( $rslt );
				}
			}
		}

		return $transient;
	}


	/**
	 * Method map_rslt_obj()
	 *
	 * Map resulting object.
	 *
	 * @param object $result Resulting information.
	 *
	 * @return object $obj Mapped resulting object.
	 */
	public static function map_rslt_obj( $result ) {
		$obj              = new \stdClass();
		$obj->slug        = $result->slug;
		$obj->new_version = $result->latest_version;
		$obj->url         = 'https://mainwp.com/';
		$obj->package     = $result->download_url;

		return $obj;
	}

	/**
	 * Method check_upgrade()
	 *
	 * Check if Extension has an update.
	 *
	 * @uses \MainWP\Dashboard\MainWP_API_Handler::check_exts_upgrade()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	private function check_upgrade() {
		$result = MainWP_API_Handler::check_exts_upgrade();
		if ( null == $this->upgradeVersionInfo ) {
			$this->upgradeVersionInfo = new \stdClass();
		}
		$this->upgradeVersionInfo->updated = time();
		if ( ! empty( $result ) ) {
			$this->upgradeVersionInfo->result = $result;
		}
		MainWP_Utility::update_option( 'mainwp_upgradeVersionInfo', $this->upgradeVersionInfo );
	}

	/**
	 * Method pre_check_update_custom()
	 *
	 * Pre-check for extension updates.
	 *
	 * @param object $transient Transient information.
	 *
	 * @return object $transient Transient information.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_indexed_extensions_infor()
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_extension_slug()
	 */
	public function pre_check_update_custom( $transient ) {
		if ( ! isset( $transient->checked ) ) {
			return $transient;
		}

		if ( ( null == $this->upgradeVersionInfo ) || ! property_exists( $this->upgradeVersionInfo, 'updated' ) || ( ( time() - $this->upgradeVersionInfo->updated ) > 60 ) ) {
			$this->check_upgrade();
		}

		if ( null != $this->upgradeVersionInfo && property_exists( $this->upgradeVersionInfo, 'result' ) && is_array( $this->upgradeVersionInfo->result ) ) {
			$extensions = MainWP_Extensions_Handler::get_indexed_extensions_infor( array( 'activated' => true ) );
			foreach ( $this->upgradeVersionInfo->result as $rslt ) {
				$plugin_slug = MainWP_Extensions_Handler::get_extension_slug( $rslt->slug );
				if ( isset( $extensions[ $plugin_slug ] ) && version_compare( $rslt->latest_version, $extensions[ $plugin_slug ]['version'], '>' ) ) {
					$transient->response[ $plugin_slug ] = self::map_rslt_obj( $rslt );
				}
			}
		}

		return $transient;
	}

	/**
	 * Method upload_file()
	 *
	 * Upload a file.
	 *
	 * @param mixed $file File to upload.
	 *
	 * @return void
	 *
	 * @uses  \MainWP\Dashboard\MainWP_Utility::ends_with()
	 */
	public function upload_file( $file ) {
		header( 'Content-Description: File Transfer' );
		if ( MainWP_Utility::ends_with( $file, '.tar.gz' ) ) {
			header( 'Content-Type: application/x-gzip' );
			header( 'Content-Encoding: gzip' );
		} else {
			header( 'Content-Type: application/octet-stream' );
		}
		header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $file ) );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$this->readfile_chunked( $file );
		exit();
	}

	/**
	 * Method readfile_chunked()
	 *
	 * Read Chunked File.
	 *
	 * @param mixed $filename Name of file.
	 *
	 * @return mixed echo $buffer|false|$handle.
	 */
	public function readfile_chunked( $filename ) {
		$chunksize = 1024;
		$handle    = fopen( $filename, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		while ( ! feof( $handle ) ) {
			$buffer = fread( $handle, $chunksize );
			echo $buffer;
			if ( ob_get_length() ) {
				ob_flush();
				flush();
			}
			$buffer = null;
		}

		return fclose( $handle );
	}

	/**
	 * Method activate_redirection()
	 *
	 * Redirect after activating MainWP Extension.
	 *
	 * @param mixed $location Location to redirect to.
	 *
	 * @return $location Admin URL + the page to redirect to.
	 */
	public function activate_redirect( $location ) {
		$location = admin_url( 'admin.php?page=Extensions' );
		return $location;
	}

	/**
	 * Method activate_extension()
	 *
	 * Activate MainWP Extension.
	 *
	 * @param mixed $ext_key Extension API Key.
	 * @param array $info Extension Info.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::set_activation_info()
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager_Password_Management::generate_password()
	 */
	public function activate_extension( $ext_key, $info = array() ) {

		add_filter( 'wp_redirect', array( $this, 'activate_redirect' ) );

		if ( is_array( $info ) && isset( $info['product_id'] ) && isset( $info['software_version'] ) ) {
			$act_info = array(
				'product_id'       => $info['product_id'],
				'software_version' => $info['software_version'],
				'activated_key'    => 'Deactivated',
				'instance_id'      => MainWP_Api_Manager_Password_Management::generate_password( 12, false ),
			);
			MainWP_Api_Manager::instance()->set_activation_info( $ext_key, $act_info );
		}
	}

	/**
	 * Method deactivate_extension()
	 *
	 * Deactivate MaiNWP Extension.
	 *
	 * @param mixed $ext_key Exnension API Key.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::set_activation_info()
	 */
	public function deactivate_extension( $ext_key ) {
		MainWP_Api_Manager::instance()->set_activation_info( $ext_key, '' );
	}

}
