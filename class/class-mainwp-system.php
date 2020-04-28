<?php

namespace MainWP\Dashboard;

define( 'MAINWP_TWITTER_MAX_SECONDS', 60 * 5 );

const MAINWP_VIEW_PER_SITE         = 1;
const MAINWP_VIEW_PER_PLUGIN_THEME = 0;
const MAINWP_VIEW_PER_GROUP        = 2;

// phpcs:disable WordPress.WP.AlternativeFunctions -- for custom read/write file.

/**
 * MainWP System class
 */
class MainWP_System {

	public static $version = '4.0.7.2';

	/**
	 * @var mixed Singleton
	 */
	private static $instance = null;
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

		do_action_deprecated( 'mainwp-activated', array(), '4.0.1', 'mainwp_activated' ); // @deprecated Use 'mainwp_activated' instead.

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

	public function parse_request() {
		include_once MAINWP_PLUGIN_DIR . '/include/api.php';
	}

	public function localization() {
		load_plugin_textdomain( 'mainwp', false, dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/' );
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
		MainWP_System_Cron_Jobs::instance()->cron_updates_check();
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

		if ( ! function_exists( 'MainWP\Dashboard\mainwp_current_user_have_right' ) ) {

			/**
			 * Check permission level by hook mainwp_currentusercan of Team Control extension
			 *
			 * @param string $cap_type group or type of capabilities
			 * @param string $cap capabilities for current user
			 * @return bool true|false
			 */
			function mainwp_current_user_have_right( $cap_type = '', $cap ) {
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
				MainWP_System_Handler::instance()->upload_file( $file );
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


	public function admin_init() {
		if ( ! MainWP_Utility::is_admin() ) {
			return;
		}

		add_action( 'mainwp_activate_extention', array( MainWP_System_Handler::instance(), 'activate_extention' ), 10, 2 );
		add_action( 'mainwp_deactivate_extention', array( MainWP_System_Handler::instance(), 'deactivate_extention' ), 10, 1 );

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
		MainWP_Post_Site_Handler::instance()->init();
		MainWP_Post_Plugin_Theme_Handler::instance()->init();
		MainWP_Post_Extension_Handler::instance()->init();
		MainWP_Post_Backup_Handler::instance()->init();

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
			if ( mainwp_current_user_have_right( 'dashboard', 'access_global_dashboard' ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=mainwp_tab' ) );
				die();
			}
		}
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
			wp_enqueue_script( 'mainwp-sites-selector', MAINWP_PLUGIN_URL . 'assets/js/mainwp-sites-selector.js', array(), $this->current_version, true );
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

	/**
	 * Method activated_check()
	 *
	 * Activated check
	 */
	public function activated_check() {
		MainWP_Deprecated_Hooks::maybe_handle_deprecated_filter();
		return $this->get_version();
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
		MainWP_DB::instance()->install();
	}

	public function is_single_user() {
		return true;
	}

	public function is_multi_user() {
		return ! $this->is_single_user();
	}

	public function get_plugin_slug() {
		return $this->plugin_slug;
	}

}
