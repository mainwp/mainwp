<?php
/**
 * MainWP System.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Defines MainWP Twitter Max Seconds.
 *
 * @const ( string ) 60 * 5
 * @source https://github.com/mainwp/mainwp/blob/master/cron/bootstrap.php
 */
define( 'MAINWP_TWITTER_MAX_SECONDS', 60 * 5 );

const MAINWP_VIEW_PER_SITE         = 1;
const MAINWP_VIEW_PER_PLUGIN_THEME = 0;
const MAINWP_VIEW_PER_GROUP        = 2;

// phpcs:disable WordPress.WP.AlternativeFunctions -- for custom read/write file.

/**
 * Class MainWP_System
 *
 * @package MainWP\Dashboard
 */
class MainWP_System {

	/**
	 * Public static variable to hold the current plugin version.
	 *
	 * @var string Current plugin version.
	 */
	public static $version = '4.1.3.1';

	/**
	 * Private static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Public variable to hold the Metaboxes instance.
	 *
	 * @var object Metaboxes.
	 */
	public $metaboxes;

	/**
	 * Private variable to hold the current version.
	 *
	 * @var string The plugin current version.
	 */
	private $current_version = null;

	/**
	 * Private variable to hold the plugin slug (mainwp/mainwp.php)
	 *
	 * @var string Plugin slug.
	 */
	private $plugin_slug;

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @static
	 * @return MainWP_System
	 */
	public static function instance() {
		return self::$instance;
	}

	/**
	 * MainWP_System constructor.
	 *
	 * Runs any time class is called.
	 *
	 * @param string $mainwp_plugin_file Plugn slug.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Bulk_Post
	 * @uses \MainWP\Dashboard\MainWP_Hooks
	 * @uses \MainWP\Dashboard\MainWP_Menu::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Menu
	 * @uses \MainWP\Dashboard\MainWP_Meta_Boxes
	 * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::init_cron_jobs()
	 * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs
	 * @uses \MainWP\Dashboard\MainWP_System_Handler
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_System_View::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_UI::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_WP_CLI_Command::init()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Overview::init()
	 * @uses \MainWP\Dashboard\MainWP_Extensions::init()
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Install_Bulk::init()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Backups::init()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites::init()
	 * @uses \MainWP\Dashboard\MainWP_Overview::get()
	 * @uses \MainWP\Dashboard\MainWP_Page::init()
	 * @uses \MainWP\Dashboard\MainWP_Plugins::init()
	 * @uses \MainWP\Dashboard\MainWP_Post::init()
	 * @uses \MainWP\Dashboard\MainWP_Settings::init()
	 * @uses \MainWP\Dashboard\MainWP_Themes::init()
	 * @uses \MainWP\Dashboard\MainWP_Updates::init()
	 * @uses \MainWP\Dashboard\MainWP_User::init()
	 * @uses \MainWP\Dashboard\MainWP_Updates::init()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function __construct( $mainwp_plugin_file ) {
		self::$instance = $this;
		$this->load_all_options();
		$this->update();
		$this->plugin_slug = plugin_basename( $mainwp_plugin_file );

		// includes rest api work.
		require 'class-mainwp-rest-api.php';
		Rest_Api::instance()->init();

		if ( is_admin() ) {
			include_once ABSPATH . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'plugin.php';
			$pluginData            = get_plugin_data( $mainwp_plugin_file );
			$this->current_version = $pluginData['Version'];
			$currentVersion        = get_option( 'mainwp_plugin_version' );

			if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '4.0', '<' ) && version_compare( $this->current_version, '4.0', '>=' ) ) {
				add_action( 'mainwp_before_header', array( MainWP_System_View::get_class_name(), 'mainwp_4_update_notice' ) );
			}

			MainWP_Utility::update_option( 'mainwp_plugin_version', $this->current_version );
		}

		if ( ! defined( 'MAINWP_VERSION' ) ) {

			/**
			 * Defines MainWP Version.
			 *
			 * @const ( string )
			 * @source https://code-reference.mainwp.com/classes/MainWP.Dashboard.MainWP_System.html
			 */
			define( 'MAINWP_VERSION', $this->current_version );
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

		$systemHandler = MainWP_System_Handler::instance();

		add_action( 'parse_request', array( &$this, 'parse_request' ) );
		add_action( 'init', array( &$this, 'localization' ) );
		add_filter( 'site_transient_update_plugins', array( $systemHandler, 'check_update_custom' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $systemHandler, 'pre_check_update_custom' ) );
		add_filter( 'plugins_api', array( $systemHandler, 'plugins_api_info' ), 10, 3 );

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

		new MainWP_Bulk_Post();

		add_action( 'admin_init', array( &$this, 'admin_init' ), 20 );
		add_action( 'after_setup_theme', array( &$this, 'after_setup_theme' ) );

		add_action( 'init', array( &$this, 'parse_init' ) );
		add_action( 'init', array( &$this, 'init' ), 9999 );
		add_action( 'admin_init', array( $this, 'admin_redirects' ) );
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		add_action( 'login_form', array( &$this, 'login_form_redirect' ) );
		add_action( 'admin_print_styles', array( MainWP_System_View::get_class_name(), 'admin_print_styles' ) );

		MainWP_Install_Bulk::init();

		MainWP_System_Cron_Jobs::instance()->init_cron_jobs();

		add_action( 'mainwp_before_header', array( MainWP_System_View::get_class_name(), 'admin_notices' ) );
		add_action( 'admin_notices', array( MainWP_System_View::get_class_name(), 'wp_admin_notices' ) );
		add_action( 'wp_mail_failed', array( &$this, 'wp_mail_failed' ) );

		add_action( 'after_plugin_row', array( MainWP_System_View::get_class_name(), 'after_extensions_plugin_row' ), 10, 3 );

		add_filter( 'mainwp-activated-check', array( &$this, 'activated_check' ) ); // @deprecated Use 'mainwp_activated_check' instead.
		add_filter( 'mainwp_activated_check', array( &$this, 'activated_check' ) );

		do_action_deprecated( 'mainwp-activated', array(), '4.0.7.2', 'mainwp_activated' ); // @deprecated Use 'mainwp_activated' instead.

		/**
		 * Action: mainwp_activated
		 *
		 * Fires upon MainWP plugin activation.
		 *
		 * @since Unknown
		 */
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
	}

	/**
	 * Method load_all_options()
	 *
	 * Load all wp_options data.
	 *
	 * @return array $alloptions Array of all options.
	 */
	public function load_all_options() {

		/**
		 * WordPress Database instance.
		 *
		 * @global object $wpdb
		 */
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
				'mainwp_activated',
				'mainwp_api_sslVerifyCertificate',
				'mainwp_automaticDailyUpdate',
				'mainwp_backup_before_upgrade',
				'mainwp_enableLegacyBackupFeature',
				'mainwp_hide_twitters_message',
				'mainwp_maximumInstallUpdateRequests',
				'mainwp_maximumSyncRequests',
				'mainwp_primaryBackup',
				'mainwp_security',
				'mainwp_use_favicon',
				'mainwp_wp_cron',
				'mainwp_timeDailyUpdate',
				'mainwp_frequencyDailyUpdate',
				'mainwp_wpcreport_extension',
				'mainwp_daily_digest_plain_text',
				'mainwp_enable_managed_cr_for_wc',
				'mainwp_hide_update_everything',
				'mainwp_number_overview_columns',
				'mainwp_disable_update_confirmations',
				'mainwp_settings_hide_widgets',
				'mainwp_settings_hide_manage_sites_columns',
				'mainwp_disableSitesChecking',
				'mainwp_disableSitesHealthMonitoring',
				'mainwp_frequencySitesChecking',
				'mainwp_sitehealthThreshold',
				'mainwp_updatescheck_frequency_today_count',
				'mainwp_settings_notification_emails',
				'mainwp_ignore_HTTP_response_status',
				'mainwp_check_http_response',
				'mainwp_setup_important_notification',
				'mainwp_extmenu',
				'mainwp_opensslLibLocation',
				'mainwp_notice_wp_mail_failed',
				'mainwp_show_language_updates',
				'mwp_setup_installationHostingType',
				'mwp_setup_installationSystemType',
				'mainwp_logger_check_daily',
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

	/**
	 * Method parse_request()
	 *
	 * Includes api.php.
	 */
	public function parse_request() {
		include_once MAINWP_PLUGIN_DIR . 'includes/api.php';
	}

	/**
	 * Method localization()
	 *
	 * Loads plugin language files.
	 */
	public function localization() {
		load_plugin_textdomain( 'mainwp', false, dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/' );
	}

	/**
	 * Method wp_mail_failed()
	 *
	 * Check if there has been a wp mail failer.
	 *
	 * @param string $error Array of error messages.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger::debug()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
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

	/**
	 * Method get_version()
	 *
	 * Get current plugin version.
	 *
	 * @return string Current plugin version.
	 */
	public function get_version() {
		return $this->current_version;
	}

	/**
	 * Method mainwp_cronpingchilds_action()
	 *
	 * Run cron ping child's action.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_ping_childs()
	 */
	public function mainwp_cronpingchilds_action() {
		MainWP_System_Cron_Jobs::instance()->cron_ping_childs();
	}

	/**
	 * Method mainwp_cronbackups_continue_action()
	 *
	 * Run cron backups continue action.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_backups_continue()
	 */
	public function mainwp_cronbackups_continue_action() {
		MainWP_System_Cron_Jobs::instance()->cron_backups_continue();
	}

	/**
	 * Method mainwp_cronbackups_action()
	 *
	 * Run cron backups action.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_backups()
	 */
	public function mainwp_cronbackups_action() {
		MainWP_System_Cron_Jobs::instance()->cron_backups();
	}

	/**
	 * Method mainwp_cronstats_action()
	 *
	 * Run cron stats action.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_stats()
	 */
	public function mainwp_cronstats_action() {
		MainWP_System_Cron_Jobs::instance()->cron_stats();
	}

	/**
	 * Method mainwp_cronupdatescheck_action()
	 *
	 * Run cron updates check action.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_updates_check()
	 */
	public function mainwp_cronupdatescheck_action() {
		MainWP_System_Cron_Jobs::instance()->cron_updates_check();
	}

	/**
	 * Method mainwp_croncheckstatus_action()
	 *
	 * Run cron check sites status action.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_check_websites_status()
	 */
	public function mainwp_croncheckstatus_action() {
		MainWP_System_Cron_Jobs::instance()->cron_check_websites_status();
	}

	/**
	 * Method mainwp_cronchecksitehealth_action()
	 *
	 * Run cron check sites health action.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_check_websites_health()
	 */
	public function mainwp_cronchecksitehealth_action() {
		MainWP_System_Cron_Jobs::instance()->cron_check_websites_health();
	}

	/**
	 * Method is_mainwp_pages()
	 *
	 * Get the current page and check it for "mainwp_".
	 *
	 * @return boolean ture|false.
	 */
	public static function is_mainwp_pages() {
		$screen = get_current_screen();
		if ( $screen && strpos( $screen->base, 'mainwp_' ) !== false && strpos( $screen->base, 'mainwp_child_tab' ) === false ) {
			return true;
		}

		return false;
	}

	/**
	 * Method init()
	 *
	 * Instantiate Plugin.
	 */
	public function init() {

		/**
		 * MainWP disabled menu items array.
		 *
		 * @global object $_mainwp_disable_menus_items
		 */
		global $_mainwp_disable_menus_items;

		$_mainwp_disable_menus_items = apply_filters( 'mainwp_disablemenuitems', $_mainwp_disable_menus_items );

		/**
		 * Filter: mainwp_main_menu_disable_menu_items
		 *
		 * Filters disabled MainWP navigation items.
		 *
		 * @since Unknown
		 */
		$_mainwp_disable_menus_items = apply_filters( 'mainwp_main_menu_disable_menu_items', $_mainwp_disable_menus_items );

		if ( ! function_exists( 'MainWP\Dashboard\mainwp_current_user_have_right' ) ) {

			/**
			 * Method mainwp_current_user_have_right()
			 *
			 * Check permission level by hook mainwp_currentusercan of Team Control extension.
			 *
			 * @param string $cap_type group or type of capabilities.
			 * @param string $cap capabilities for current user.
			 *
			 * @return bool true|false
			 *
			 * @uses \MainWP\Dashboard\MainWP_System_Handler::handle_settings_post()
			 */
			function mainwp_current_user_have_right( $cap_type = '', $cap ) {

				/**
				 * Current user global.
				 *
				 * @global string
				 */
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

		MainWP_System_Handler::instance()->handle_settings_post();
	}

	/**
	 * Method parse_init()
	 *
	 * Initiate plugin installation & then run the Quick Setup Wizard.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Handler::upload_file()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
	 * @uses \MainWP\Dashboard\MainWP_Setup_Wizard()
	 */
	public function parse_init() {
		if ( isset( $_GET['mwpdl'] ) && isset( $_GET['sig'] ) ) {
			$mwpDir = MainWP_System_Utility::get_mainwp_dir();
			$mwpDir = $mwpDir[0];
			$mwpdl  = isset( $_REQUEST['mwpdl'] ) ? wp_unslash( $_REQUEST['mwpdl'] ) : '';
			$file   = trailingslashit( $mwpDir ) . rawurldecode( $mwpdl );

			if ( stristr( rawurldecode( $mwpdl ), '..' ) ) {
				return;
			}

			$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

			/**
			 * WordPress files system object.
			 *
			 * @global object
			 */
			global $wp_filesystem;

			if ( $hasWPFileSystem && $wp_filesystem->exists( $file ) && md5( filesize( $file ) ) == $_GET['sig'] ) {
				MainWP_System_Handler::instance()->upload_file( $file );
				exit();
			}
		} elseif ( isset( $_GET['page'] ) ) {
			if ( MainWP_System_Utility::is_admin() ) {
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

		/**
		 * Redirect to global.
		 *
		 * @global string $redirect_to
		 */
		global $redirect_to;
		if ( ! isset( $_GET['redirect_to'] ) ) {
			$redirect_to = get_admin_url() . 'index.php';
		}
	}

	/**
	 * Method after_setup_theme()
	 *
	 * After setup theme hook, to support post thumbnails.
	 */
	public function after_setup_theme() {
		add_theme_support( 'post-thumbnails' );
	}

	/**
	 * Method admin_init()
	 *
	 * Do nothing if current user is not an Admin else display the page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Post_Backup_Handler::init()
	 * @uses \MainWP\Dashboard\MainWP_Post_Extension_Handler::init()
	 * @uses \MainWP\Dashboard\MainWP_Post_Handler::init()
	 * @uses \MainWP\Dashboard\MainWP_Post_Handler::get_security_nonces()
	 * @uses \MainWP\Dashboard\MainWP_Post_Plugin_Theme_Handler::init()
	 * @uses \MainWP\Dashboard\MainWP_Post_Site_Handler::init()
	 * @uses \MainWP\Dashboard\MainWP_System_Handler::activate_extension()
	 * @uses \MainWP\Dashboard\MainWP_System_Handler::deactivate_extension()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
	 * @uses \MainWP\Dashboard\MainWP_System_View::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_System_View::get_mainwp_translations()
	 * @uses \MainWP\Dashboard\MainWP_Twitter::enabled_twitter_messages()
	 */
	public function admin_init() {
		if ( ! MainWP_System_Utility::is_admin() ) {
			return;
		}

		add_action( 'mainwp_activate_extention', array( MainWP_System_Handler::instance(), 'activate_extension' ), 10, 2 ); // @deprecated Use 'mainwp_activate_extension' instead.
		add_action( 'mainwp_deactivate_extention', array( MainWP_System_Handler::instance(), 'deactivate_extension' ), 10, 1 ); // @deprecated Use 'mainwp_deactivate_extension' instead.

		add_action( 'mainwp_activate_extension', array( MainWP_System_Handler::instance(), 'activate_extension' ), 10, 2 );
		add_action( 'mainwp_deactivate_extension', array( MainWP_System_Handler::instance(), 'deactivate_extension' ), 10, 1 );

		/**
		 * MainWP use external primary backup method.
		 *
		 * @global string
		 */
		global $mainwpUseExternalPrimaryBackupsMethod;

		if ( null === $mainwpUseExternalPrimaryBackupsMethod ) {
			$return = '';

			/*
			 * @deprecated Use 'mainwp_getprimarybackup_activated' instead.
			 *
			 */
			$return                                = apply_filters_deprecated( 'mainwp-getprimarybackup-activated', array( $return ), '4.0.7.2', 'mainwp_getprimarybackup_activated' );
			$mainwpUseExternalPrimaryBackupsMethod = apply_filters( 'mainwp_getprimarybackup_activated', $return );
		}

		add_action( 'mainwp_before_header', array( MainWP_System_View::get_class_name(), 'mainwp_warning_notice' ) );

		MainWP_Post_Handler::instance()->init();
		MainWP_Post_Site_Handler::instance()->init();
		MainWP_Post_Plugin_Theme_Handler::instance()->init();
		MainWP_Post_Extension_Handler::instance()->init();
		MainWP_Post_Backup_Handler::instance()->init();

		/**
		 * Filter: mainwp_ui_use_wp_calendar
		 *
		 * Filters whether default jQuery datepicker should be used to avoid potential problems with Senatic UI Calendar library.
		 *
		 * @since 4.0.5
		 */
		$use_wp_datepicker = apply_filters( 'mainwp_ui_use_wp_calendar', false );
		if ( $use_wp_datepicker ) {
			wp_enqueue_script( 'jquery-ui-datepicker' );
		}
		wp_enqueue_script( 'jquery-ui-dialog' );
		$en_params = array( 'jquery-ui-dialog' );
		if ( $use_wp_datepicker ) {
			$en_params[] = 'jquery-ui-datepicker';
		}
		wp_enqueue_script( 'mainwp', MAINWP_PLUGIN_URL . 'assets/js/mainwp.js', $en_params, $this->current_version, true );

		$enableLegacyBackupFeature = get_option( 'mainwp_enableLegacyBackupFeature' );
		$primaryBackup             = get_option( 'mainwp_primaryBackup' );
		$disable_backup_checking   = ( empty( $enableLegacyBackupFeature ) && empty( $primaryBackup ) ) ? true : false;

		$mainwpParams = array(
			'image_url'                        => MAINWP_PLUGIN_URL . 'assets/images/',
			'backup_before_upgrade'            => ( get_option( 'mainwp_backup_before_upgrade' ) == 1 ),
			'disable_checkBackupBeforeUpgrade' => $disable_backup_checking,
			'admin_url'                        => admin_url(),
			'use_wp_datepicker'                => $use_wp_datepicker ? 1 : 0,
			'date_format'                      => get_option( 'date_format' ),
			'time_format'                      => get_option( 'time_format' ),
			'enabledTwit'                      => MainWP_Twitter::enabled_twitter_messages(),
			'maxSecondsTwit'                   => MAINWP_TWITTER_MAX_SECONDS,
			'installedBulkSettingsManager'     => is_plugin_active( 'mainwp-bulk-settings-manager/mainwp-bulk-settings-manager.php' ) ? 1 : 0,
			'maximumSyncRequests'              => ( get_option( 'mainwp_maximumSyncRequests' ) === false ) ? 8 : get_option( 'mainwp_maximumSyncRequests' ),
			'maximumInstallUpdateRequests'     => ( get_option( 'mainwp_maximumInstallUpdateRequests' ) === false ) ? 3 : get_option( 'mainwp_maximumInstallUpdateRequests' ),
		);
		wp_localize_script( 'mainwp', 'mainwpParams', $mainwpParams );

		$mainwpTranslations = MainWP_System_View::get_mainwp_translations();

		wp_localize_script( 'mainwp', 'mainwpTranslations', $mainwpTranslations );

		$security_nonces = MainWP_Post_Handler::instance()->get_security_nonces();

		$nonces_filter = apply_filters( 'mainwp_security_nonces', array() );

		if ( is_array( $nonces_filter ) && ! empty( $nonces_filter ) ) {
			$security_nonces = array_merge( $security_nonces, $nonces_filter );
		}

		wp_localize_script( 'mainwp', 'security_nonces', $security_nonces );

		wp_enqueue_script( 'thickbox' );
		wp_enqueue_script( 'user-profile' );
		wp_enqueue_style( 'thickbox' );

		if ( isset( $_GET['page'] ) && ( 'mainwp_tab' === $_GET['page'] || ( 'managesites' === $_GET['page'] ) && isset( $_GET['dashboard'] ) ) ) {
			wp_enqueue_script( 'dragula', MAINWP_PLUGIN_URL . 'assets/js/dragula/dragula.min.js', array(), $this->current_version, true );
			wp_enqueue_style( 'dragula', MAINWP_PLUGIN_URL . 'assets/js/dragula/dragula.min.css', array(), $this->current_version );
		}

		if ( isset( $_GET['page'] ) && ( 'managesites' === $_GET['page'] || 'MonitoringSites' === $_GET['page'] ) ) {
			wp_enqueue_script( 'dragula', MAINWP_PLUGIN_URL . 'assets/js/preview.js', array(), $this->current_version, true );
			wp_enqueue_style( 'dragula', MAINWP_PLUGIN_URL . 'assets/css/preview.css', array(), $this->current_version );
		}

		$this->init_session();

		if ( ! current_user_can( 'update_core' ) ) {
			remove_action( 'admin_notices', 'update_nag', 3 );
		}
	}

	/**
	 * Method admin_redirects()
	 *
	 * MainWP admin redirects.
	 */
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

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$_pos        = strlen( $request_uri ) - strlen( '/wp-admin/' );
		if ( ! empty( $request_uri ) && strpos( $request_uri, '/wp-admin/' ) !== false && strpos( $request_uri, '/wp-admin/' ) == $_pos ) {
			if ( mainwp_current_user_have_right( 'dashboard', 'access_global_dashboard' ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=mainwp_tab' ) );
				die();
			}
		}
		MainWP_Logger::instance()->check_log_daily();
	}

	/**
	 * Method init_session()
	 *
	 * Check current page & initiate a session.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::init_session()
	 */
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

	/**
	 * Method admin_enqueue_scripts()
	 *
	 * Enqueue all Mainwp Admin Scripts.
	 *
	 * @param mixed $hook Enqueue hook.
	 */
	public function admin_enqueue_scripts( $hook ) {

		$load_cust_scripts = false;

		/**
		 * Current pagenow.
		 *
		 * @global string
		 */
		global $pagenow;

		if ( is_plugin_active( 'mainwp-custom-post-types/mainwp-custom-post-types.php' ) && ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) ) {
			$load_cust_scripts = true;
		}

		if ( self::is_mainwp_pages() ) {
			wp_enqueue_script( 'jquery-migrate' ); // to compatible.
			wp_enqueue_script( 'mainwp-updates', MAINWP_PLUGIN_URL . 'assets/js/mainwp-updates.js', array(), $this->current_version, true );
			wp_enqueue_script( 'mainwp-managesites-action', MAINWP_PLUGIN_URL . 'assets/js/mainwp-managesites-action.js', array(), $this->current_version, true );
			wp_enqueue_script( 'mainwp-managesites-update', MAINWP_PLUGIN_URL . 'assets/js/mainwp-managesites-update.js', array(), $this->current_version, true );
			wp_enqueue_script( 'mainwp-managesites-import', MAINWP_PLUGIN_URL . 'assets/js/mainwp-managesites-import.js', array(), $this->current_version, true );
			wp_enqueue_script( 'mainwp-plugins-themes', MAINWP_PLUGIN_URL . 'assets/js/mainwp-plugins-themes.js', array(), $this->current_version, true );
			wp_enqueue_script( 'mainwp-backups', MAINWP_PLUGIN_URL . 'assets/js/mainwp-backups.js', array(), $this->current_version, true );
			wp_enqueue_script( 'mainwp-posts', MAINWP_PLUGIN_URL . 'assets/js/mainwp-posts.js', array(), $this->current_version, true );
			wp_enqueue_script( 'mainwp-users', MAINWP_PLUGIN_URL . 'assets/js/mainwp-users.js', array(), $this->current_version, true );
			wp_enqueue_script( 'mainwp-extensions', MAINWP_PLUGIN_URL . 'assets/js/mainwp-extensions.js', array(), $this->current_version, true );
			wp_enqueue_script( 'mainwp-moment', MAINWP_PLUGIN_URL . 'assets/js/moment/moment.min.js', array(), $this->current_version, true );
			wp_enqueue_script( 'semantic', MAINWP_PLUGIN_URL . 'assets/js/semantic-ui/semantic.min.js', array( 'jquery' ), $this->current_version, false );
			wp_enqueue_script( 'semantic-ui-datatables', MAINWP_PLUGIN_URL . 'assets/js/datatables/datatables.min.js', array( 'jquery' ), $this->current_version, false );
			wp_enqueue_script( 'semantic-ui-datatables-colreorder', MAINWP_PLUGIN_URL . 'assets/js/colreorder/dataTables.colReorder.js', array( 'jquery' ), $this->current_version, false );
			wp_enqueue_script( 'semantic-ui-datatables-scroller', MAINWP_PLUGIN_URL . 'assets/js/scroller/scroller.dataTables.js', array( 'jquery' ), $this->current_version, false );
			wp_enqueue_script( 'semantic-ui-datatables-fixedcolumns', MAINWP_PLUGIN_URL . 'assets/js/fixedcolumns/dataTables.fixedColumns.js', array( 'jquery' ), $this->current_version, false );
			wp_enqueue_script( 'semantic-ui-calendar', MAINWP_PLUGIN_URL . 'assets/js/calendar/calendar.min.js', array( 'jquery' ), $this->current_version, true );
			wp_enqueue_script( 'semantic-ui-hamburger', MAINWP_PLUGIN_URL . 'assets/js/hamburger/hamburger.js', array( 'jquery' ), $this->current_version, true );
			wp_enqueue_script( 'mainwp-clipboard', MAINWP_PLUGIN_URL . 'assets/js/clipboard/clipboard.min.js', array( 'jquery' ), $this->current_version, true );
			wp_enqueue_script( 'mainwp-rest-api', MAINWP_PLUGIN_URL . 'assets/js/mainwp-rest-api.js', array(), $this->current_version, true );
		}

		if ( $load_cust_scripts ) {
			wp_enqueue_script( 'semantic', MAINWP_PLUGIN_URL . 'assets/js/semantic-ui/semantic.min.js', array( 'jquery' ), $this->current_version, true );
		}

		wp_enqueue_script( 'mainwp-ui', MAINWP_PLUGIN_URL . 'assets/js/mainwp-ui.js', array(), $this->current_version, true );
		wp_enqueue_script( 'mainwp-js-popup', MAINWP_PLUGIN_URL . 'assets/js/mainwp-popup.js', array(), $this->current_version, true );
		wp_enqueue_script( 'mainwp-fileuploader', MAINWP_PLUGIN_URL . 'assets/js/fileuploader.js', array(), $this->current_version ); // phpcs:ignore -- fileuploader scripts need to load at header.
		wp_enqueue_script( 'mainwp-date', MAINWP_PLUGIN_URL . 'assets/js/date.js', array(), $this->current_version, true );
		wp_enqueue_script( 'mainwp-filesaver', MAINWP_PLUGIN_URL . 'assets/js/FileSaver.js', array(), $this->current_version, true );
		wp_enqueue_script( 'mainwp-jqueryfiletree', MAINWP_PLUGIN_URL . 'assets/js/jqueryFileTree.js', array(), $this->current_version, true );
	}

	/**
	 * Method admin_enqueue_styles()
	 *
	 * Enqueue all Mainwp Admin Styles.
	 *
	 * @param mixed $hook Enqueue hook.
	 */
	public function admin_enqueue_styles( $hook ) {

		wp_enqueue_style( 'mainwp', MAINWP_PLUGIN_URL . 'assets/css/mainwp.css', array(), $this->current_version );
		wp_enqueue_style( 'mainwp-responsive-layouts', MAINWP_PLUGIN_URL . 'assets/css/mainwp-responsive-layouts.css', array(), $this->current_version );

		if ( isset( $_GET['hideall'] ) && 1 === $_GET['hideall'] ) {
			remove_action( 'admin_footer', 'wp_admin_bar_render', 1000 );
		}

		/**
		 * Current pagenow.
		 *
		 * @global string
		 */
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
			// to fix conflict layout.
			wp_enqueue_style( 'jquery-ui-style', MAINWP_PLUGIN_URL . 'assets/css/1.11.1/jquery-ui.min.css', array(), '1.11.1' );
		}

		if ( $load_cust_scripts ) {
			wp_enqueue_style( 'semantic', MAINWP_PLUGIN_URL . 'assets/js/semantic-ui/semantic.min.css', array(), $this->current_version );
		}
	}

	/**
	 * Method admin_menu()
	 *
	 * Add Bulk Post/Pages menue.
	 */
	public function admin_menu() {

		/**
		 * Admin menu array.
		 *
		 * @global object
		 */
		global $menu;

		foreach ( $menu as $k => $item ) {
			if ( 'edit.php?post_type=bulkpost' === $item[2] ) {
				unset( $menu[ $k ] );
			} elseif ( 'edit.php?post_type=bulkpage' === $item[2] ) {
				unset( $menu[ $k ] );
			}
		}
	}

	/**
	 * Method enqueue_postbox_scripts()
	 *
	 * Enqueue postbox scripts.
	 */
	public static function enqueue_postbox_scripts() {
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'wp-lists' );
		wp_enqueue_script( 'postbox' );
	}

	/**
	 * Method admin_footer()
	 *
	 * Create MainWP admin footer.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_menu()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
	 * @uses \MainWP\Dashboard\MainWP_System_View::render_footer_content()
	 * @uses \MainWP\Dashboard\MainWP_System_View::admin_footer()
	 */
	public function admin_footer() {
		if ( ! self::is_mainwp_pages() ) {
			return;
		}

		?>
		<div class="ui large modal" id="mainwp-response-data-modal">
			<div class="header"><?php esc_html_e( 'Child Site Response', 'mainwp' ); ?></div>
			<div class="content">
				<div class="ui info message"><?php esc_html_e( 'To see the response in a more readable way, you can copy it and paste it into some HTML render tool, such as Codepen.io.', 'mainwp' ); ?>
				</div>
			</div>
			<div class="scrolling content content-response"></div>
			<div class="actions">
				<button class="ui green button mainwp-response-copy-button"><?php esc_html_e( 'Copy Response', 'mainwp' ); ?></button>
				<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
			</div>
		</div>
		<div id="mainwp-response-data-container" resp-data=""></div>
		<?php

		if ( isset( $_GET['hideall'] ) && 1 === $_GET['hideall'] ) {
			return;
		}
		$current_wpid = MainWP_System_Utility::get_current_wpid();
		if ( $current_wpid ) {
			$website  = MainWP_DB::instance()->get_website_by_id( $current_wpid );
			$websites = array( $website );
		} else {
			$is_staging = 'no';
			if ( isset( $_GET['page'] ) ) {
				if ( ( 'managesites' == $_GET['page'] ) && ! isset( $_GET['id'] ) && ! isset( $_GET['do'] ) && ! isset( $_GET['dashboard'] ) ) {
					$group_ids = get_option( 'mainwp_managesites_filter_group' );
					if ( ! empty( $group_ids ) ) {
						$group_ids = explode( ',', $group_ids ); // convert to array.
					}
					if ( $group_ids ) {
						$staging_group = get_option( 'mainwp_stagingsites_group_id' );
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

		MainWP_System_View::render_footer_content( $websites, $current_wpid );
		if ( empty( $current_wpid ) ) {
			MainWP_DB::free_result( $websites );
		}

		MainWP_System_View::admin_footer();
		MainWP_Menu::init_subpages_menu();

		/**
		 * MainWP disabled menu items array.
		 *
		 * @global object
		 */
		global $_mainwp_disable_menus_items;

		$_mainwp_disable_menus_items = apply_filters( 'mainwp_all_disablemenuitems', $_mainwp_disable_menus_items );
	}

	/**
	 * Method activated_check()
	 *
	 * Activated check.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook()
	 */
	public function activated_check() {
		MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook();
		return $this->get_version();
	}

	/**
	 * Method activation()
	 *
	 * Activate MainWP.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Install::install()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function activation() {
		MainWP_Install::instance()->install();
		MainWP_Utility::update_option( 'mainwp_activated', 'yes' );
	}

	/**
	 * Method deactivation()
	 *
	 * Deactivate MainWP.
	 */
	public function deactivation() {
		update_option( 'mainwp_extensions_all_activation_cached', '' );
	}

	/**
	 * Method update()
	 *
	 * Update MainWP.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Install::install()
	 */
	public function update() {
		MainWP_Install::instance()->install();
	}

	/**
	 * Method is_single_user()
	 *
	 * Check if single user environment.
	 *
	 * @return boolean true|false.
	 */
	public function is_single_user() {
		return true;
	}

	/**
	 * Method is_multi_user()
	 *
	 * Check if multi user environment.
	 *
	 * @return boolean true|false.
	 */
	public function is_multi_user() {
		return ! $this->is_single_user();
	}

	/**
	 * Method get_plugin_slug()
	 *
	 * Get MainWP Plugin Slug.
	 */
	public function get_plugin_slug() {
		return $this->plugin_slug;
	}

}
