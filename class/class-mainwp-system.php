<?php

namespace MainWP\Dashboard;

define( 'MAINWP_TWITTER_MAX_SECONDS', 60 * 5 );

const MAINWP_VIEW_PER_SITE         = 1;
const MAINWP_VIEW_PER_PLUGIN_THEME = 0;
const MAINWP_VIEW_PER_GROUP        = 2;

// phpcs:disable WordPress.WP.AlternativeFunctions -- for custom read/write file.

class MainWP_System {

	public static $version = '4.0.7.2';

	/**
	 * Singleton.
	 */
	private static $instance    = null;
	private $upgradeVersionInfo = null;
	public $metaboxes;

	/**
	 * The plugin current version
	 *
	 * @var string
	 */
	private $current_version = null;

	/**
	 * Plugin Slug (plugin_directory/plugin_file.php)
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Plugin name (plugin_file)
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * @static
	 * @return MainWP_System
	 */
	public static function instance() {
		return self::$instance;
	}

	public function __construct( $mainwp_plugin_file ) {
		self::$instance = $this;
		$this->load_all_options();
		$this->update();
		$this->plugin_slug = plugin_basename( $mainwp_plugin_file );

		if ( is_admin() ) {
			include_once ABSPATH . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'plugin.php';
			$pluginData            = get_plugin_data( $mainwp_plugin_file );
			$this->current_version = $pluginData['Version'];
			$currentVersion        = get_option( 'mainwp_plugin_version' );

			if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '4.0', '<' ) && version_compare( $this->current_version, '4.0', '>=' ) ) {
				add_action( 'mainwp_before_header', array( MainWP_System_View::get_class_name(), 'mainwp_4_update_notice' ) );
			}

			if ( empty( $currentVersion ) ) {
				MainWP_Utility::update_option( 'mainwp_getting_started', 'started' );
			} elseif ( version_compare( $currentVersion, $this->current_version, '<' ) ) {
				update_option( 'mainwp_reset_user_tips', array() );
				MainWP_Utility::update_option( 'mainwp_reset_user_cookies', array() );
			} else {
				delete_option( 'mainwp_getting_started' );
			}

			MainWP_Utility::update_option( 'mainwp_plugin_version', $this->current_version );
		}

		if ( ! defined( 'MAINWP_VERSION' ) ) {
			define( 'MAINWP_VERSION', $this->current_version );
		}

		if ( '' != get_option( 'mainwp_upgradeVersionInfo' ) ) {
			$this->upgradeVersionInfo = get_option( 'mainwp_upgradeVersionInfo' );
		} else {
			$this->upgradeVersionInfo = null;
		}

		$ssl_api_verifyhost = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) ) ? 1 : 0;
		if ( 0 == $ssl_api_verifyhost ) {
			add_filter(
				'http_request_args',
				array(
					MainWP_Extensions_Handler::get_class_name(),
					'no_ssl_filter_extension_upgrade',
				),
				99,
				2
			);
		}

		MainWP_Extensions::init();
		add_action( 'parse_request', array( &$this, 'parse_request' ) );
		add_action( 'init', array( &$this, 'localization' ) );
		add_filter( 'site_transient_update_plugins', array( &$this, 'check_update_custom' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( &$this, 'pre_check_update_custom' ) );
		add_filter( 'plugins_api', array( &$this, 'check_info' ), 10, 3 );

		$this->metaboxes = new MainWP_Meta_Boxes();

		MainWP_Overview::get();

		MainWP_Manage_Sites::init();
		new MainWP_Hooks();
		new MainWP_Menu();

		add_action( 'admin_menu', array( MainWP_Menu::get_class_name(), 'init_mainwp_menus' ) );
		add_filter( 'admin_footer', array( &$this, 'admin_footer' ), 15 );
		add_action( 'admin_head', array( MainWP_System_View::get_class_name(), 'admin_head' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue_scripts' ) );
		add_action( 'admin_body_class', array( MainWP_System_View::get_class_name(), 'admin_body_class' ) );
		add_action( 'admin_post_mainwp_editpost', array( &$this, 'handle_edit_bulkpost' ) );
		add_action( 'save_post', array( &$this, 'save_bulkpost' ) );
		add_action( 'save_post', array( &$this, 'save_bulkpage' ) );
		add_action( 'admin_init', array( &$this, 'admin_init' ), 20 );
		add_action( 'init', array( &$this, 'create_post_type' ) );
		add_action( 'init', array( &$this, 'parse_init' ) );
		add_action( 'init', array( &$this, 'init' ), 9999 );
		add_action( 'admin_init', array( $this, 'admin_redirects' ) );
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		add_filter( 'post_updated_messages', array( MainWP_System_View::get_class_name(), 'post_updated_messages' ) );
		add_action( 'login_form', array( &$this, 'login_form_redirect' ) );
		add_action( 'admin_print_styles', array( MainWP_System_View::get_class_name(), 'admin_print_styles' ) );

		MainWP_Install_Bulk::init();

		MainWP_System_Cron_Jobs::instance()->init_cron_jobs();

		add_action( 'mainwp_before_header', array( MainWP_System_View::get_class_name(), 'admin_notices' ) );
		add_action( 'admin_notices', array( MainWP_System_View::get_class_name(), 'wp_admin_notices' ) );
		add_action( 'wp_mail_failed', array( &$this, 'wp_mail_failed' ) );

		add_action( 'after_plugin_row', array( MainWP_System_View::get_class_name(), 'after_extensions_plugin_row' ), 10, 3 );

		add_filter( 'mainwp-activated-check', array( &$this, 'activated_check' ) ); // @deprecated Use 'mainwp_activated_check' instead.
		add_filter( 'mainwp-extension-enabled-check', array( MainWP_Extensions_Handler::get_class_name(), 'is_extension_enabled' ) ); // @deprecated Use 'mainwp_extension_enabled_check' instead.

		add_filter( 'mainwp_activated_check', array( &$this, 'activated_check' ) );
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
		add_filter(
			'mainwp-manager-getextensions',
			array(
				MainWP_Extensions_Handler::get_class_name(),
				'hook_manager_get_extensions',
			)
		);

		/*
		* @deprecated Use 'mainwp_activated' instead.
		*
		*/

		do_action_deprecated( 'mainwp-activated', array(), '4.0.1', 'mainwp_activated'  ); // @deprecated Use 'mainwp_activated' instead.

		do_action( 'mainwp_activated' );

		MainWP_Updates::init();
		MainWP_Post::init();
		MainWP_Settings::init();
		if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			MainWP_Manage_Backups::init();
		}
		MainWP_User::init();
		MainWP_Page::init();
		MainWP_Themes::init();
		MainWP_Plugins::init();
		MainWP_Updates_Overview::init();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			MainWP_WP_CLI_Command::init();
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			if ( isset( $_GET['mainwp_run'] ) && ! empty( $_GET['mainwp_run'] ) ) {
				add_action( 'init', array( MainWP_System_Cron_Jobs::instance(), 'cron_active' ), PHP_INT_MAX );
			}
		}
		add_action( 'mainwp_admin_footer', array( MainWP_UI::get_class_name(), 'usersnap_integration' ) );
	}

	public function load_all_options() {
		global $wpdb;

		if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
			$alloptions = wp_cache_get( 'alloptions', 'options' );
		} else {
			$alloptions = false;
		}

		if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
			$notoptions = wp_cache_get( 'notoptions', 'options' );
		} else {
			$notoptions = false;
		}

		if ( ! isset( $alloptions['mainwp_db_version'] ) ) {
			$suppress = $wpdb->suppress_errors();
			$options  = array(
				'mainwp_db_version',
				'mainwp_plugin_version',
				'mainwp_upgradeVersionInfo',
				'mainwp_extensions',
				'mainwp_manager_extensions',
				'mainwp_getting_started',
				'mainwp_activated',
				'mainwp_api_sslVerifyCertificate',
				'mainwp_automaticDailyUpdate',
				'mainwp_backup_before_upgrade',
				'mainwp_enableLegacyBackupFeature',
				'mainwp_hide_twitters_message',
				'mainwp_maximumInstallUpdateRequests',
				'mainwp_maximumSyncRequests',
				'mainwp_primaryBackup',
				'mainwp_refresh',
				'mainwp_security',
				'mainwp_use_favicon',
				'mainwp_wp_cron',
				'mainwp_timeDailyUpdate',
				'mainwp_frequencyDailyUpdate',
				'mainwp_wpcreport_extension',
				'mainwp_daily_digest_plain_text',
				'mainwp_enable_managed_cr_for_wc',
				'mainwp_hide_update_everything',
				'mainwp_show_usersnap',
				'mainwp_number_overview_columns',
				'mainwp_disable_update_confirmations',
				'mainwp_settings_hide_widgets',
				'mainwp_settings_hide_manage_sites_columns',
			);

			$query = "SELECT option_name, option_value FROM $wpdb->options WHERE option_name in (";
			foreach ( $options as $option ) {
				$query .= "'" . $option . "', ";
			}
			$query         = substr( $query, 0, strlen( $query ) - 2 );
			$query .= ")"; // phpcs:ignore -- ignore double quotes auto-correction.
			$alloptions_db	 = $wpdb->get_results( $query ); // phpcs:ignore -- unprepared SQL ok.
			$wpdb->suppress_errors( $suppress );
			if ( ! is_array( $alloptions ) ) {
				$alloptions = array();
			}
			if ( is_array( $alloptions_db ) ) {
				foreach ( (array) $alloptions_db as $o ) {
					$alloptions[ $o->option_name ] = $o->option_value;
					unset( $options[ array_search( $o->option_name, $options ) ] );
				}
				foreach ( $options as $option ) {
					$notoptions[ $option ] = true;
				}
				if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
					wp_cache_set( 'alloptions', $alloptions, 'options' );
					wp_cache_set( 'notoptions', $notoptions, 'options' );
				}
			}
		}

		return $alloptions;
	}

	public function filter_fetch_urls_authed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output ) {
		return MainWP_Extensions_Handler::hook_fetch_urls_authed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output, $is_external_hook = true );
	}

	public function filter_fetch_url_authed( $pluginFile, $key, $websiteId, $what, $params, $raw_response = null ) {
		return MainWP_Extensions_Handler::hook_fetch_url_authed( $pluginFile, $key, $websiteId, $what, $params, $raw_response );
	}

	public function parse_request() {
		include_once MAINWP_PLUGIN_DIR . '/include/api.php';
	}

	public function localization() {
		load_plugin_textdomain( 'mainwp', false, dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/' );
	}

	public function activated_check() {
		MainWP_Deprecated_Hooks::maybe_handle_deprecated_filter();
		return $this->get_version();
	}


	public function wp_mail_failed( $error ) {
		$mail_failed = get_option( 'mainwp_notice_wp_mail_failed' );
		if ( is_object( $error ) && empty( $mail_failed ) ) {
			MainWP_Utility::update_option( 'mainwp_notice_wp_mail_failed', 'yes' );
			$er = $error->get_error_message();
			if ( ! empty( $er ) ) {
				MainWP_Logger::instance()->debug( 'Error :: wp_mail :: [error=' . $er . ']' );
			}
		}
	}

	public function get_version() {
		return $this->current_version;
	}

	// phpcs:ignore -- complex method
	public function check_update_custom( $transient ) {
		if ( isset( $_POST['action'] ) && ( ( 'update-plugin' === $_POST['action'] ) || ( 'update-selected' === $_POST['action'] ) ) ) {
			$extensions = MainWP_Extensions_Handler::get_extensions( array( 'activated' => true ) );
			if ( defined( 'DOING_AJAX' ) && isset( $_POST['plugin'] ) && 'update-plugin' == $_POST['action'] ) {
				$plugin_slug = $_POST['plugin'];
				if ( isset( $extensions[ $plugin_slug ] ) ) {
					if ( isset( $transient->response[ $plugin_slug ] ) && version_compare( $transient->response[ $plugin_slug ]->new_version, $extensions[ $plugin_slug ]['version'], '=' ) ) {
						return $transient;
					}

					$api_slug = dirname( $plugin_slug );
					$rslt     = MainWP_API_Settings::get_upgrade_information( $api_slug );

					if ( ! empty( $rslt ) && isset( $rslt->latest_version ) && version_compare( $rslt->latest_version, $extensions[ $plugin_slug ]['version'], '>' ) ) {
						$transient->response[ $plugin_slug ] = self::map_rslt_obj( $rslt );
					}

					return $transient;
				}
			} elseif ( 'update-selected' === $_POST['action'] && isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ) {
				$updated = false;
				foreach ( $_POST['checked'] as $plugin_slug ) {
					if ( isset( $extensions[ $plugin_slug ] ) ) {
						if ( isset( $transient->response[ $plugin_slug ] ) && version_compare( $transient->response[ $plugin_slug ]->new_version, $extensions[ $plugin_slug ]['version'], '=' ) ) {
							continue;
						}
						$api_slug = dirname( $plugin_slug );
						$rslt     = MainWP_API_Settings::get_upgrade_information( $api_slug );
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

				$plugin_slug = MainWP_Extensions_Handler::get_plugin_slug( $rslt->slug );
				if ( isset( $transient->checked[ $plugin_slug ] ) && version_compare( $rslt->latest_version, $transient->checked[ $plugin_slug ], '>' ) ) {
					$transient->response[ $plugin_slug ] = self::map_rslt_obj( $rslt );
				}
			}
		}

		return $transient;
	}

	public static function map_rslt_obj( $pRslt ) {
		$obj              = new \stdClass();
		$obj->slug        = $pRslt->slug;
		$obj->new_version = $pRslt->latest_version;
		$obj->url         = 'https://mainwp.com/';
		$obj->package     = $pRslt->download_url;

		return $obj;
	}

	private function check_upgrade() {
		$result = MainWP_API_Settings::check_upgrade();
		if ( null === $this->upgradeVersionInfo ) {
			$this->upgradeVersionInfo = new \stdClass();
		}
		$this->upgradeVersionInfo->updated = time();
		if ( ! empty( $result ) ) {
			$this->upgradeVersionInfo->result = $result;
		}
		MainWP_Utility::update_option( 'mainwp_upgradeVersionInfo', $this->upgradeVersionInfo );
	}

	public function pre_check_update_custom( $transient ) {
		if ( ! isset( $transient->checked ) ) {
			return $transient;
		}

		if ( ( null == $this->upgradeVersionInfo ) || ( ( time() - $this->upgradeVersionInfo->updated ) > 60 ) ) {
			$this->check_upgrade();
		}

		if ( null != $this->upgradeVersionInfo && property_exists( $this->upgradeVersionInfo, 'result' ) && is_array( $this->upgradeVersionInfo->result ) ) {
			$extensions = MainWP_Extensions_Handler::get_extensions( array( 'activated' => true ) );
			foreach ( $this->upgradeVersionInfo->result as $rslt ) {
				$plugin_slug = MainWP_Extensions_Handler::get_plugin_slug( $rslt->slug );
				if ( isset( $extensions[ $plugin_slug ] ) && version_compare( $rslt->latest_version, $extensions[ $plugin_slug ]['version'], '>' ) ) {
					$transient->response[ $plugin_slug ] = self::map_rslt_obj( $rslt );
				}
			}
		}

		return $transient;
	}

	public function check_info( $false, $action, $arg ) {
		if ( 'plugin_information' !== $action ) {
			return $false;
		}

		if ( ! isset( $arg->slug ) || ( '' === $arg->slug ) ) {
			return $false;
		}

		if ( dirname( $this->plugin_slug ) === $arg->slug ) {
			return $false;
		}

		$result   = MainWP_Extensions_Handler::get_slugs();
		$am_slugs = $result['am_slugs'];

		if ( '' !== $am_slugs ) {
			$am_slugs = explode( ',', $am_slugs );
			if ( in_array( $arg->slug, $am_slugs ) ) {
				$info = MainWP_API_Settings::get_plugin_information( $arg->slug );
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
	 * Method mainwp_cronpingchilds_action()
	 *
	 * Run cron ping childs action.
	 */
	public function mainwp_cronpingchilds_action() {
		MainWP_System_Cron_Jobs::instance()->cron_ping_childs();
	}

	/**
	 * Method mainwp_cronbackups_continue_action()
	 *
	 * Run cron backups continue action.
	 */
	public function mainwp_cronbackups_continue_action() {
		MainWP_System_Cron_Jobs::instance()->cron_backups_continue();
	}

	/**
	 * Method mainwp_cronbackups_action()
	 *
	 * Run cron backups action.
	 */
	public function mainwp_cronbackups_action() {
		MainWP_System_Cron_Jobs::instance()->cron_backups();
	}

	/**
	 * Method mainwp_cronstats_action()
	 *
	 * Run cron stats action.
	 */
	public function mainwp_cronstats_action() {
		MainWP_System_Cron_Jobs::instance()->cron_stats();
	}

	/**
	 * Method mainwp_cronupdatescheck_action()
	 *
	 * Run cron updates check action.
	 */
	public function mainwp_cronupdatescheck_action() {
		MainWP_System_Cron_Jobs::instance()->cron_updates_check_action();
	}

	public function print_admin_styles( $value = true ) {
		if ( self::is_mainwp_pages() ) {
			return false;
		}
		return $value;
	}

	public static function is_mainwp_pages() {
		$screen = get_current_screen();
		if ( $screen && strpos( $screen->base, 'mainwp_' ) !== false && strpos( $screen->base, 'mainwp_child_tab' ) === false ) {
			return true;
		}

		return false;
	}

	public function init() {

		global $_mainwp_disable_menus_items;

		$_mainwp_disable_menus_items = apply_filters( 'mainwp_disablemenuitems', $_mainwp_disable_menus_items );

		$_mainwp_disable_menus_items = apply_filters( 'mainwp_main_menu_disable_menu_items', $_mainwp_disable_menus_items );

		if ( ! function_exists( 'MainWP\Dashboard\mainwp_current_user_can' ) ) {

			/**
			 * Check permission level by hook mainwp_currentusercan of Team Control extension
			 *
			 * @param string $cap_type group or type of capabilities
			 * @param string $cap capabilities for current user
			 * @return bool true|false
			 */
			function mainwp_current_user_can( $cap_type = '', $cap ) {
				global $current_user;

				if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
					return true;
				}

				if ( defined( 'WP_CLI' ) && WP_CLI ) {
					return true;
				}

				if ( empty( $current_user ) ) {
					if ( ! function_exists( 'wp_get_current_user' ) ) {
						require_once ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'pluggable.php';
					}
				}

				return apply_filters( 'mainwp_currentusercan', true, $cap_type, $cap );
			}
		}

		$this->handle_settings_post();
	}

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

	public function readfile_chunked( $filename ) {
		$chunksize = 1024;
		$handle    = fopen( $filename, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		while ( ! feof( $handle ) ) {
			$buffer = fread( $handle, $chunksize );
			echo $buffer;
			ob_flush();
			flush();
			$buffer = null;
		}

		return fclose( $handle );
	}

	public function parse_init() {
		if ( isset( $_GET['mwpdl'] ) && isset( $_GET['sig'] ) ) {
			$mwpDir = MainWP_Utility::get_mainwp_dir();
			$mwpDir = $mwpDir[0];
			$file   = trailingslashit( $mwpDir ) . rawurldecode( $_REQUEST['mwpdl'] );

			if ( stristr( rawurldecode( $_REQUEST['mwpdl'] ), '..' ) ) {
				return;
			}

			$hasWPFileSystem = MainWP_Utility::get_wp_file_system();

			global $wp_filesystem;

			if ( $hasWPFileSystem && $wp_filesystem->exists( $file ) && md5( filesize( $file ) ) == $_GET['sig'] ) {
				$this->upload_file( $file );
				exit();
			}
		} elseif ( isset( $_GET['page'] ) ) {
			if ( MainWP_Utility::is_admin() ) {
				switch ( $_GET['page'] ) {
					case 'mainwp-setup':
						new MainWP_Setup_Wizard();
						break;
				}
			}
		}
	}

	/**
	 * Method login_form_redirect()
	 *
	 * Login redirect.
	 */
	public function login_form_redirect() {
		global $redirect_to;
		if ( ! isset( $_GET['redirect_to'] ) ) {
			$redirect_to = get_admin_url() . 'index.php';
		}
	}

	public function activate_redirect( $location ) {
		$location = admin_url( 'admin.php?page=Extensions' );
		return $location;
	}

	public function activate_extention( $ext_key, $info = array() ) {

		add_filter( 'wp_redirect', array( $this, 'activate_redirect' ) );

		if ( is_array( $info ) && isset( $info['product_id'] ) && isset( $info['software_version'] ) ) {
			$act_info = array(
				'product_id'         => $info['product_id'],
				'software_version'   => $info['software_version'],
				'activated_key'      => 'Deactivated',
				'instance_id'        => MainWP_Api_Manager_Password_Management::generate_password( 12, false ),
			);
			MainWP_Api_Manager::instance()->set_activation_info( $ext_key, $act_info );
		}
	}

	public function deactivate_extention( $ext_key ) {
		MainWP_Api_Manager::instance()->set_activation_info( $ext_key, '' );
	}

	public function admin_init() {
		if ( ! MainWP_Utility::is_admin() ) {
			return;
		}

		add_action( 'mainwp_activate_extention', array( $this, 'activate_extention' ), 10, 2 );
		add_action( 'mainwp_deactivate_extention', array( $this, 'deactivate_extention' ), 10, 1 );

		global $mainwpUseExternalPrimaryBackupsMethod;

		if ( null === $mainwpUseExternalPrimaryBackupsMethod ) {
			$return = '';

			/*
			 * @deprecated Use 'mainwp_getprimarybackup_activated' instead.
			 *
			 */
			$return                                = apply_filters_deprecated( 'mainwp-getprimarybackup-activated', array( $return ), '4.0.1', 'mainwp_getprimarybackup_activated' );
			$mainwpUseExternalPrimaryBackupsMethod = apply_filters( 'mainwp_getprimarybackup_activated', $return );
		}

		add_action( 'mainwp_before_header', array( MainWP_System_View::get_class_name(), 'mainwp_warning_notice' ) );
		MainWP_Post_Handler::instance()->init();
		$use_wp_datepicker = apply_filters( 'mainwp_ui_use_wp_calendar', false );
		if ( $use_wp_datepicker ) {
			wp_enqueue_script( 'jquery-ui-datepicker' );
		}
		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'jquery-ui-style', MAINWP_PLUGIN_URL . 'assets/css/1.11.1/jquery-ui.min.css', array(), '1.11.1' );

		$en_params = array( 'jquery-ui-dialog' );
		if ( $use_wp_datepicker ) {
			$en_params[] = 'jquery-ui-datepicker';
		}
		wp_enqueue_script( 'mainwp', MAINWP_PLUGIN_URL . 'assets/js/mainwp.js', $en_params, $this->current_version, true );

		$enableLegacyBackupFeature = get_option( 'mainwp_enableLegacyBackupFeature' );
		$primaryBackup             = get_option( 'mainwp_primaryBackup' );
		$disable_backup_checking   = ( empty( $enableLegacyBackupFeature ) && empty( $primaryBackup ) ) ? true : false;

		$mainwpParams = array(
			'image_url'                          => MAINWP_PLUGIN_URL . 'assets/images/',
			'backup_before_upgrade'              => ( get_option( 'mainwp_backup_before_upgrade' ) == 1 ),
			'disable_checkBackupBeforeUpgrade'   => $disable_backup_checking,
			'admin_url'                          => admin_url(),
			'use_wp_datepicker'                  => $use_wp_datepicker ? 1 : 0,
			'date_format'                        => get_option( 'date_format' ),
			'time_format'                        => get_option( 'time_format' ),
			'enabledTwit'                        => MainWP_Twitter::enabled_twitter_messages(),
			'maxSecondsTwit'                     => MAINWP_TWITTER_MAX_SECONDS,
			'installedBulkSettingsManager'       => is_plugin_active( 'mainwp-bulk-settings-manager/mainwp-bulk-settings-manager.php' ) ? 1 : 0,
			'maximumSyncRequests'                => ( get_option( 'mainwp_maximumSyncRequests' ) === false ) ? 8 : get_option( 'mainwp_maximumSyncRequests' ),
			'maximumInstallUpdateRequests'       => ( get_option( 'mainwp_maximumInstallUpdateRequests' ) === false ) ? 3 : get_option( 'mainwp_maximumInstallUpdateRequests' ),
		);
		wp_localize_script( 'mainwp', 'mainwpParams', $mainwpParams );

		$mainwpTranslations = MainWP_System_View::get_mainwp_translations();

		wp_localize_script( 'mainwp', 'mainwpTranslations', $mainwpTranslations );

		$security_nonces = MainWP_Post_Handler::instance()->get_security_nonces();
		wp_localize_script( 'mainwp', 'security_nonces', $security_nonces );

		wp_enqueue_script( 'thickbox' );
		wp_enqueue_script( 'user-profile' );
		wp_enqueue_style( 'thickbox' );

		if ( isset( $_GET['page'] ) && ( 'mainwp_tab' === $_GET['page'] || ( 'managesites' === $_GET['page'] ) && isset( $_GET['dashboard'] ) ) ) {
			wp_enqueue_script( 'dragula', MAINWP_PLUGIN_URL . 'assets/js/dragula/dragula.min.js', array(), $this->current_version, true );
			wp_enqueue_style( 'dragula', MAINWP_PLUGIN_URL . 'assets/js/dragula/dragula.min.css', array(), $this->current_version );
		}

		$this->init_session();

		if ( ! current_user_can( 'update_core' ) ) {
			remove_action( 'admin_notices', 'update_nag', 3 );
		}
	}

	public function admin_redirects() {
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! empty( $_GET['page'] ) && in_array( $_GET['page'], array( 'mainwp-setup' ) ) ) {
			return;
		}

		$quick_setup = get_option( 'mainwp_run_quick_setup', false );
		if ( 'yes' === $quick_setup ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mainwp-setup' ) );
			exit;
		}

		if ( 'yes' === get_option( 'mainwp_activated' ) ) {
			delete_option( 'mainwp_activated' );
			wp_cache_delete( 'mainwp_activated', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_safe_redirect( admin_url( 'admin.php?page=mainwp_tab' ) );

			return;
		}

		$started = get_option( 'mainwp_getting_started' );
		if ( ! empty( $started ) ) {
			delete_option( 'mainwp_getting_started' );
			wp_cache_delete( 'mainwp_getting_started', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			if ( ! is_multisite() ) {
				if ( 'started' === $started ) {
					wp_safe_redirect( admin_url( 'admin.php?page=mainwp_about&do=started' ) );
					exit;
				}
			}
		}

		$_pos = strlen( $_SERVER['REQUEST_URI'] ) - strlen( '/wp-admin/' );
		if ( strpos( $_SERVER['REQUEST_URI'], '/wp-admin/' ) !== false && strpos( $_SERVER['REQUEST_URI'], '/wp-admin/' ) == $_pos ) {
			if ( mainwp_current_user_can( 'dashboard', 'access_global_dashboard' ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=mainwp_tab' ) );
				die();
			}
		}
	}

	public function handle_settings_post() {
		if ( ! function_exists( 'wp_create_nonce' ) ) {
			include_once ABSPATH . WPINC . '/pluggable.php';
		}

		if ( isset( $_GET['page'] ) && isset( $_POST['wp_nonce'] ) ) {
			$this->handle_mainwp_tools_settings();
			$this->handle_manage_sites_screen_settings();
		}

		if ( isset( $_POST['select_mainwp_options_siteview'] ) && check_admin_referer( 'mainwp-admin-nonce' ) ) {
			$userExtension            = MainWP_DB::instance()->get_user_extension();
			$userExtension->site_view = ( empty( $_POST['select_mainwp_options_siteview'] ) ? MAINWP_VIEW_PER_PLUGIN_THEME : intval( $_POST['select_mainwp_options_siteview'] ) );
			MainWP_DB::instance()->update_user_extension( $userExtension );
		}

		if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) ) {
			if ( wp_verify_nonce( $_POST['wp_nonce'], 'Settings' ) ) {
				$updated  = MainWP_Settings::handle_settings_post();
				$updated |= MainWP_Manage_Sites_Handler::handle_settings_post();
				$msg      = '';
				if ( $updated ) {
					$msg = '&message=saved';
				}
				wp_safe_redirect( admin_url( 'admin.php?page=Settings' . $msg ) );
				exit();
			}
		}
	}

	public function handle_mainwp_tools_settings() {
		$update_screen_options = false;
		if ( 'MainWPTools' === $_GET['page'] ) {
			if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'MainWPTools' ) ) {
				$update_screen_options = true;
				MainWP_Utility::update_option( 'mainwp_enable_managed_cr_for_wc', ( ! isset( $_POST['enable_managed_cr_for_wc'] ) ? 0 : 1 ) );
				MainWP_Utility::update_option( 'mainwp_use_favicon', ( ! isset( $_POST['mainwp_use_favicon'] ) ? 0 : 1 ) );

				$enabled_twit = ! isset( $_POST['mainwp_hide_twitters_message'] ) ? 0 : 1;
				MainWP_Utility::update_option( 'mainwp_hide_twitters_message', $enabled_twit );
				if ( ! $enabled_twit ) {
					MainWP_Twitter::clear_all_twitter_messages();
				}
			}
		} elseif ( 'mainwp_tab' === $_GET['page'] || isset( $_GET['dashboard'] ) ) {
			if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'MainWPScrOptions' ) ) {
				$update_screen_options = true;
			}
		}

		if ( $update_screen_options ) {
			$hide_wids = array();
			if ( isset( $_POST['mainwp_hide_widgets'] ) && is_array( $_POST['mainwp_hide_widgets'] ) ) {
				foreach ( $_POST['mainwp_hide_widgets'] as $value ) {
					$hide_wids[] = $value;
				}
			}
			$user = wp_get_current_user();
			if ( $user ) {
				update_user_option( $user->ID, 'mainwp_settings_hide_widgets', $hide_wids, true );
			}

			MainWP_Utility::update_option( 'mainwp_hide_update_everything', ( ! isset( $_POST['hide_update_everything'] ) ? 0 : 1 ) );
			MainWP_Utility::update_option( 'mainwp_show_usersnap', ( ! isset( $_POST['mainwp_show_usersnap'] ) ? 0 : time() ) );
			MainWP_Utility::update_option( 'mainwp_number_overview_columns', intval( $_POST['number_overview_columns'] ) );
		}
	}

	public function handle_manage_sites_screen_settings() {
		if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'ManageSitesScrOptions' ) ) {
			$hide_cols = array();
			foreach ( $_POST as $key => $val ) {
				if ( false !== strpos( $key, 'mainwp_hide_column_' ) ) {
					$col         = str_replace( 'mainwp_hide_column_', '', $key );
					$hide_cols[] = $col;
				}
			}
			$user = wp_get_current_user();
			if ( $user ) {
				update_user_option( $user->ID, 'mainwp_settings_hide_manage_sites_columns', $hide_cols, true );
				update_option( 'mainwp_default_sites_per_page', intval( $_POST['mainwp_default_sites_per_page'] ) );
			}
		}
	}

	public function handle_edit_bulkpost() {

		$post_id = 0;
		if ( isset( $_POST['post_ID'] ) ) {
			$post_id = (int) $_POST['post_ID'];
		}

		if ( $post_id && isset( $_POST['select_sites_nonce'] ) && wp_verify_nonce( $_POST['select_sites_nonce'], 'select_sites_' . $post_id ) ) {
			check_admin_referer( 'update-post_' . $post_id );
			edit_post();

			$location = admin_url( 'admin.php?page=PostBulkEdit&post_id=' . $post_id . '&message=1' );
			$location = apply_filters( 'redirect_post_location', $location, $post_id );
			wp_safe_redirect( $location );
			exit();
		}
	}

	public function redirect_edit_bulkpost( $location, $post_id ) {
		if ( $post_id ) {
			$location = admin_url( 'admin.php?page=PostBulkEdit&post_id=' . intval( $post_id ) );
		} else {
			$location = admin_url( 'admin.php?page=PostBulkAdd' );
		}

		return $location;
	}

	public function redirect_edit_bulkpage( $location, $post_id ) {

		if ( $post_id ) {
			$location = admin_url( 'admin.php?page=PageBulkEdit&post_id=' . intval( $post_id ) );
		} else {
			$location = admin_url( 'admin.php?page=PageBulkAdd' );
		}

		return $location;
	}

	public function save_bulkpost( $post_id ) {
		$_post = get_post( $post_id );

		if ( 'bulkpost' !== $_post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'update-post_' . $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['post_type'] ) || ( 'bulkpost' !== $_POST['post_type'] ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( isset( $_POST['mainwp_wpseo_metabox_save_values'] ) && ( ! empty( $_POST['mainwp_wpseo_metabox_save_values'] ) ) ) {
			return;
		}

		$pid = $this->metaboxes->select_sites_handle( $post_id, 'bulkpost' );
		$this->metaboxes->add_categories_handle( $post_id, 'bulkpost' );
		$this->metaboxes->add_tags_handle( $post_id, 'bulkpost' );
		$this->metaboxes->add_slug_handle( $post_id, 'bulkpost' );
		MainWP_Post_Page_Handler::add_sticky_handle( $post_id );
		do_action( 'mainwp_save_bulkpost', $post_id );

		if ( $pid == $post_id ) {
			add_filter( 'redirect_post_location', array( $this, 'redirect_edit_bulkpost' ), 10, 2 );
		} else {
			do_action( 'mainwp_before_redirect_posting_bulkpost', $_post );
			wp_safe_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPost&id=' . $post_id . '&hideall=1' );
			die();
		}
	}

	public function save_bulkpage( $post_id ) {

		$_post = get_post( $post_id );

		if ( 'bulkpage' !== $_post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'update-post_' . $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['post_type'] ) || ( 'bulkpage' !== $_POST['post_type'] ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( isset( $_POST['mainwp_wpseo_metabox_save_values'] ) && ( ! empty( $_POST['mainwp_wpseo_metabox_save_values'] ) ) ) {
			return;
		}

		$pid = $this->metaboxes->select_sites_handle( $post_id, 'bulkpage' );
		$this->metaboxes->add_slug_handle( $post_id, 'bulkpage' );
		MainWP_Page::add_status_handle( $post_id );

		do_action( 'mainwp_save_bulkpage', $post_id );

		if ( $pid == $post_id ) {
			add_filter( 'redirect_post_location', array( $this, 'redirect_edit_bulkpage' ), 10, 2 );
		} else {
			do_action( 'mainwp_before_redirect_posting_bulkpage', $_post );
			wp_safe_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPage&id=' . $post_id . '&hideall=1' );
			die();
		}
	}

	public function create_post_type() {
		$queryable = is_plugin_active( 'mainwp-post-plus-extension/mainwp-post-plus-extension.php' ) ? true : false;
		$labels    = array(
			'name'               => _x( 'Bulkpost', 'bulkpost' ),
			'singular_name'      => _x( 'Bulkpost', 'bulkpost' ),
			'add_new'            => _x( 'Add New', 'bulkpost' ),
			'add_new_item'       => _x( 'Add New Bulkpost', 'bulkpost' ),
			'edit_item'          => _x( 'Edit Bulkpost', 'bulkpost' ),
			'new_item'           => _x( 'New Bulkpost', 'bulkpost' ),
			'view_item'          => _x( 'View Bulkpost', 'bulkpost' ),
			'search_items'       => _x( 'Search Bulkpost', 'bulkpost' ),
			'not_found'          => _x( 'No bulkpost found', 'bulkpost' ),
			'not_found_in_trash' => _x( 'No bulkpost found in Trash', 'bulkpost' ),
			'parent_item_colon'  => _x( 'Parent Bulkpost:', 'bulkpost' ),
			'menu_name'          => _x( 'Bulkpost', 'bulkpost' ),
		);

		$args = array(
			'labels'                 => $labels,
			'hierarchical'           => false,
			'description'            => 'description...',
			'supports'               => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'custom-fields',
				'comments',
				'revisions',
			),
			'public'                 => true,
			'show_ui'                => true,
			'show_in_nav_menus'      => false,
			'publicly_queryable'     => $queryable,
			'exclude_from_search'    => true,
			'has_archive'            => false,
			'query_var'              => false,
			'can_export'             => false,
			'rewrite'                => false,
			'capabilities'           => array(
				'edit_post'          => 'read',
				'edit_posts'         => 'read',
				'edit_others_posts'  => 'read',
				'publish_posts'      => 'read',
				'read_post'          => 'read',
				'read_private_posts' => 'read',
				'delete_post'        => 'read',
			),
		);

		register_post_type( 'bulkpost', $args );

		$labels = array(
			'name'               => _x( 'Bulkpage', 'bulkpage' ),
			'singular_name'      => _x( 'Bulkpage', 'bulkpage' ),
			'add_new'            => _x( 'Add New', 'bulkpage' ),
			'add_new_item'       => _x( 'Add New Bulkpage', 'bulkpage' ),
			'edit_item'          => _x( 'Edit Bulkpage', 'bulkpage' ),
			'new_item'           => _x( 'New Bulkpage', 'bulkpage' ),
			'view_item'          => _x( 'View Bulkpage', 'bulkpage' ),
			'search_items'       => _x( 'Search Bulkpage', 'bulkpage' ),
			'not_found'          => _x( 'No bulkpage found', 'bulkpage' ),
			'not_found_in_trash' => _x( 'No bulkpage found in Trash', 'bulkpage' ),
			'parent_item_colon'  => _x( 'Parent Bulkpage:', 'bulkpage' ),
			'menu_name'          => _x( 'Bulkpage', 'bulkpage' ),
		);

		$args = array(
			'labels'                 => $labels,
			'hierarchical'           => false,
			'description'            => 'description...',
			'supports'               => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'custom-fields',
				'comments',
				'revisions',
			),
			'public'                 => true,
			'show_ui'                => true,
			'show_in_nav_menus'      => false,
			'publicly_queryable'     => $queryable,
			'exclude_from_search'    => true,
			'has_archive'            => false,
			'query_var'              => false,
			'can_export'             => false,
			'rewrite'                => false,
			'capabilities'           => array(
				'edit_post'          => 'read',
				'edit_posts'         => 'read',
				'edit_others_posts'  => 'read',
				'publish_posts'      => 'read',
				'read_post'          => 'read',
				'read_private_posts' => 'read',
				'delete_post'        => 'read',
			),
		);

		register_post_type( 'bulkpage', $args );
	}

	public function init_session() {
		if ( isset( $_GET['page'] ) && in_array(
			$_GET['page'],
			array(
				'PostBulkManage',
				'PageBulkManage',
				'PluginsManage',
				'PluginsAutoUpdate',
				'ThemesManage',
				'ThemesAutoUpdate',
				'UserBulkManage',
			)
		)
		) {
			MainWP_Cache::init_session();
		}
	}

	public function admin_enqueue_scripts( $hook ) {

		$load_cust_scripts = false;

		global $pagenow;

		if ( is_plugin_active( 'mainwp-custom-post-types/mainwp-custom-post-types.php' ) && ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) ) {
			$load_cust_scripts = true;
		}

		if ( self::is_mainwp_pages() ) {
			wp_enqueue_script( 'mainwp-updates', MAINWP_PLUGIN_URL . 'assets/js/mainwp-updates.js', array(), $this->current_version, true );
			wp_enqueue_script( 'mainwp-managesites', MAINWP_PLUGIN_URL . 'assets/js/mainwp-managesites.js', array(), $this->current_version, true );
			wp_enqueue_script( 'mainwp-extensions', MAINWP_PLUGIN_URL . 'assets/js/mainwp-extensions.js', array(), $this->current_version, true );
			wp_enqueue_script( 'mainwp-moment', MAINWP_PLUGIN_URL . 'assets/js/moment/moment.min.js', array(), $this->current_version, true );
			wp_enqueue_script( 'semantic', MAINWP_PLUGIN_URL . 'assets/js/semantic-ui/semantic.min.js', array( 'jquery' ), $this->current_version, false );
			wp_enqueue_script( 'semantic-ui-datatables', MAINWP_PLUGIN_URL . 'assets/js/datatables/datatables.min.js', array( 'jquery' ), $this->current_version, false );
			wp_enqueue_script( 'semantic-ui-datatables-colreorder', MAINWP_PLUGIN_URL . 'assets/js/colreorder/dataTables.colReorder.js', array( 'jquery' ), $this->current_version, false );
			wp_enqueue_script( 'semantic-ui-datatables-scroller', MAINWP_PLUGIN_URL . 'assets/js/scroller/scroller.dataTables.js', array( 'jquery' ), $this->current_version, false );
			wp_enqueue_script( 'semantic-ui-datatables-fixedcolumns', MAINWP_PLUGIN_URL . 'assets/js/fixedcolumns/dataTables.fixedColumns.js', array( 'jquery' ), $this->current_version, false );
			wp_enqueue_script( 'semantic-ui-calendar', MAINWP_PLUGIN_URL . 'assets/js/calendar/calendar.min.js', array( 'jquery' ), $this->current_version, true );
			wp_enqueue_script( 'semantic-ui-hamburger', MAINWP_PLUGIN_URL . 'assets/js/hamburger/hamburger.js', array( 'jquery' ), $this->current_version, true );
		}

		if ( $load_cust_scripts ) {
			wp_enqueue_script( 'semantic', MAINWP_PLUGIN_URL . 'assets/js/semantic-ui/semantic.min.js', array( 'jquery' ), $this->current_version, true );
		}

		wp_enqueue_script( 'mainwp-ui', MAINWP_PLUGIN_URL . 'assets/js/mainwp-ui.js', array(), $this->current_version, true );
		wp_enqueue_script( 'mainwp-js-popup', MAINWP_PLUGIN_URL . 'assets/js/mainwp-popup.js', array(), $this->current_version, true );
		// phpcs:ignore -- fileuploader scripts need to load at header.
		wp_enqueue_script( 'mainwp-fileuploader', MAINWP_PLUGIN_URL . 'assets/js/fileuploader.js', array(), $this->current_version );
		wp_enqueue_script( 'mainwp-date', MAINWP_PLUGIN_URL . 'assets/js/date.js', array(), $this->current_version, true );
		wp_enqueue_script( 'mainwp-filesaver', MAINWP_PLUGIN_URL . 'assets/js/FileSaver.js', array(), $this->current_version, true );
		wp_enqueue_script( 'mainwp-jqueryfiletree', MAINWP_PLUGIN_URL . 'assets/js/jqueryFileTree.js', array(), $this->current_version, true );
	}

	public function admin_enqueue_styles( $hook ) {
		global $wp_version;
		wp_enqueue_style( 'mainwp', MAINWP_PLUGIN_URL . 'assets/css/mainwp.css', array(), $this->current_version );
		wp_enqueue_style( 'mainwp-responsive-layouts', MAINWP_PLUGIN_URL . 'assets/css/mainwp-responsive-layouts.css', array(), $this->current_version );

		if ( isset( $_GET['hideall'] ) && 1 === $_GET['hideall'] ) {
			remove_action( 'admin_footer', 'wp_admin_bar_render', 1000 );
		}

		global $pagenow;

		$load_cust_scripts = false;
		if ( is_plugin_active( 'mainwp-custom-post-types/mainwp-custom-post-types.php' ) && ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) ) {
			$load_cust_scripts = true;
		}

		if ( self::is_mainwp_pages() ) {
			wp_enqueue_style( 'mainwp-filetree', MAINWP_PLUGIN_URL . 'assets/css/jqueryFileTree.css', array(), $this->current_version );
			wp_enqueue_style( 'semantic', MAINWP_PLUGIN_URL . 'assets/js/semantic-ui/semantic.min.css', array(), $this->current_version );
			wp_enqueue_style( 'semantic-mainwp', MAINWP_PLUGIN_URL . 'assets/css/mainwp-semantic.css', array(), $this->current_version );
			wp_enqueue_style( 'semantic-ui-datatables', MAINWP_PLUGIN_URL . 'assets/js/datatables/datatables.min.css', array(), $this->current_version );
			wp_enqueue_style( 'semantic-ui-datatables-colreorder', MAINWP_PLUGIN_URL . 'assets/js/colreorder/colReorder.semanticui.css', array(), $this->current_version );
			wp_enqueue_style( 'semantic-ui-datatables-scroller', MAINWP_PLUGIN_URL . 'assets/js/scroller/scroller.dataTables.css', array(), $this->current_version );
			wp_enqueue_style( 'semantic-ui-calendar', MAINWP_PLUGIN_URL . 'assets/js/calendar/calendar.min.css', array(), $this->current_version );
			wp_enqueue_style( 'semantic-ui-hamburger', MAINWP_PLUGIN_URL . 'assets/js/hamburger/hamburger.css', array(), $this->current_version );
		}

		if ( $load_cust_scripts ) {
			wp_enqueue_style( 'semantic', MAINWP_PLUGIN_URL . 'assets/js/semantic-ui/semantic.min.css', array(), $this->current_version );
		}
	}

	public function admin_menu() {
		global $menu;
		foreach ( $menu as $k => $item ) {
			if ( 'edit.php?post_type=bulkpost' === $item[2] ) {
				unset( $menu[ $k ] );
			} elseif ( 'edit.php?post_type=bulkpage' === $item[2] ) {
				unset( $menu[ $k ] );
			}
		}
	}

	public static function enqueue_postbox_scripts() {
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'wp-lists' );
		wp_enqueue_script( 'postbox' );
	}

	public function admin_footer( $echo = false ) {
		if ( ! self::is_mainwp_pages() ) {
			return;
		}
		if ( isset( $_GET['hideall'] ) && 1 === $_GET['hideall'] ) {
			return;
		}
		$current_wpid = MainWP_Utility::get_current_wpid();
		if ( $current_wpid ) {
			$website  = MainWP_DB::instance()->get_website_by_id( $current_wpid );
			$websites = array( $website );
		} else {
			$is_staging = 'no';
			if ( isset( $_GET['page'] ) ) {
				if ( ( 'managesites' == $_GET['page'] ) && ! isset( $_GET['id'] ) && ! isset( $_GET['do'] ) && ! isset( $_GET['dashboard'] ) ) {
					$filter_group = get_option( 'mainwp_managesites_filter_group' );
					if ( $filter_group ) {
						$staging_group = get_option( 'mainwp_stagingsites_group_id' );
						if ( $staging_group == $filter_group ) {
							$is_staging = 'yes';
						}
					}
				} elseif ( 'UpdatesManage' == $_GET['page'] || 'mainwp_tab' == $_GET['page'] ) {
					$staging_enabled = is_plugin_active( 'mainwp-staging-extension/mainwp-staging-extension.php' ) ? true : false;
					if ( $staging_enabled ) {
						$staging_view = get_user_option( 'mainwp_staging_options_updates_view' ) == 'staging' ? true : false;
						if ( $staging_view ) {
							$is_staging = 'yes';
						}
					}
				}
			}
			$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp_sync.dtsSync DESC, wp.url ASC', false, false, null, false, array(), $is_staging ) );
		}

		MainWP_System_View::render_footer_content( $websites );

		MainWP_System_View::admin_footer();

		MainWP_Menu::init_subpages_menu();

		global $_mainwp_disable_menus_items;

		$_mainwp_disable_menus_items = apply_filters( 'mainwp_all_disablemenuitems', $_mainwp_disable_menus_items );
	}

	public function activation() {
		MainWP_DB::instance()->update();
		MainWP_DB::instance()->install();

		MainWP_Utility::update_option( 'mainwp_activated', 'yes' );
	}

	public function deactivation() {
		update_option( 'mainwp_extensions_all_activation_cached', '' );
	}

	public function update() {
		MainWP_DB::instance()->update();
		MainWP_DB::instance()->install();
	}

	public function apply_filter( $filter, $value = array() ) {
		$output = apply_filters( $filter, $value );

		if ( ! is_array( $output ) ) {
			return array();
		}
		$count = count( $output );
		for ( $i = 0; $i < $count; $i ++ ) {
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

	public function is_single_user() {
		return true;
	}

	public function is_multi_user() {
		return ! $this->is_single_user();
	}

}
