<?php

// ini_set( 'display_errors', true );
// error_reporting( E_ALL | E_STRICT );

@ini_set( 'display_errors', false );
@error_reporting( 0 );

define( 'MAINWP_API_VALID', 'VALID' );
define( 'MAINWP_API_INVALID', 'INVALID' );

define( 'MAINWP_TWITTER_MAX_SECONDS', 60 * 5 ); // seconds

const MAINWP_VIEW_PER_SITE         = 1;
const MAINWP_VIEW_PER_PLUGIN_THEME = 0;
const MAINWP_VIEW_PER_GROUP        = 2;

class MainWP_System {

	public static $version = '4.0.7.2';
	// Singleton
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
		list ( $t1, $t2 )  = explode( '/', $this->plugin_slug );
		$this->slug        = str_replace( '.php', '', $t2 );

		if ( is_admin() ) {
			include_once ABSPATH . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'plugin.php'; // Version information from WordPress
			$pluginData            = get_plugin_data( $mainwp_plugin_file );
			$this->current_version = $pluginData['Version'];
			$currentVersion        = get_option( 'mainwp_plugin_version' );

			if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '4.0', '<' ) && version_compare( $this->current_version, '4.0', '>=' ) ) {
				add_action( 'mainwp_before_header', array( &$this, 'mainwp_4_update_notice' ) );
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
			add_filter( 'http_request_args', array(
				MainWP_Extensions::get_class_name(),
				'noSSLFilterExtensionUpgrade',
			), 99, 2 );
		}

		MainWP_Extensions::init();
		add_action( 'parse_request', array( &$this, 'parse_request' ) );
		add_action( 'init', array( &$this, 'localization' ) );

		// define the alternative API for updating checking
		add_filter( 'site_transient_update_plugins', array( &$this, 'check_update_custom' ) );

		// Define the alternative response for information checking
		add_filter( 'pre_set_site_transient_update_plugins', array( &$this, 'pre_check_update_custom' ) );
		add_filter( 'plugins_api', array( &$this, 'check_info' ), 10, 3 );

		$this->metaboxes = new MainWP_Meta_Boxes();

		MainWP_Overview::get(); // init main dashboard

		MainWP_Manage_Sites::init();
		new MainWP_Hooks(); // Init the hooks
		new MainWP_Menu(); // Init custom menu
		//
		// Change menu & widgets
		add_action( 'admin_menu', array( &$this, 'new_menus' ) );

		// Change footer
		add_filter( 'admin_footer', array( &$this, 'admin_footer' ), 15 );

		// Add js
		add_action( 'admin_head', array( &$this, 'admin_head' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue_scripts' ) );

		// Add body class
		add_action( 'admin_body_class', array( &$this, 'admin_body_class' ) );

		add_action( 'admin_post_mainwp_editpost', array( &$this, 'handle_edit_bulkpost' ) );

		// Handle the bulkpost
		add_action( 'save_post', array( &$this, 'save_bulkpost' ) );
		add_action( 'save_post', array( &$this, 'save_bulkpage' ) );

		// Add meta boxes for the bulkpost
		add_action( 'admin_init', array( &$this, 'admin_init' ), 20 ); // $priority = 20 probably to fix conflict with post smtp overwrite wp mail function

		// Create the post types for bulkpost/...
		add_action( 'init', array( &$this, 'create_post_type' ) );
		add_action( 'init', array( &$this, 'parse_init' ) );
		add_action( 'init', array( &$this, 'init' ), 9999 );

		add_action( 'admin_init', array( $this, 'admin_redirects' ) );

		// Remove the pages from the menu which I use in AJAX
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );

		// Add custom error messages
		add_filter( 'post_updated_messages', array( &$this, 'post_updated_messages' ) );

		add_action( 'login_form', array( &$this, 'login_form' ) );

		// add_filter( 'print_admin_styles', array( &$this, 'print_admin_styles' ) );
		add_action( 'admin_print_styles', array( &$this, 'admin_print_styles' ) );

		MainWP_Install_Bulk::init();

		do_action( 'mainwp_cronload_action' );

		// Cron every 5 minutes
		add_action( 'mainwp_cronstats_action', array( $this, 'mainwp_cronstats_action' ) );
		add_action( 'mainwp_cronbackups_action', array( $this, 'mainwp_cronbackups_action' ) );
		add_action( 'mainwp_cronbackups_continue_action', array( $this, 'mainwp_cronbackups_continue_action' ) );
		add_action( 'mainwp_cronupdatescheck_action', array( $this, 'mainwp_cronupdatescheck_action' ) );
		add_action( 'mainwp_cronpingchilds_action', array( $this, 'mainwp_cronpingchilds_action' ) );

		add_filter( 'cron_schedules', array( $this, 'get_cron_schedules' ) );

		$this->init_cron();

		add_action( 'mainwp_before_header', array( &$this, 'admin_notices' ) );
		add_action( 'admin_notices', array( &$this, 'wp_admin_notices' ) );
		add_action( 'wp_mail_failed', array( &$this, 'wp_mail_failed' ) );

		// to fix layout

		add_action( 'after_plugin_row', array( &$this, 'after_extensions_plugin_row' ), 10, 3 );

		add_filter( 'mainwp-activated-check', array( &$this, 'activated_check' ) );
		add_filter( 'mainwp-activated-sub-check', array( &$this, 'activated_sub_check' ) );
		add_filter( 'mainwp-extension-enabled-check', array( MainWP_Extensions::get_class_name(), 'isExtensionEnabled' ) );

		/**
		 * This hook allows you to get a list of sites via the 'mainwp-getsites' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getsites
		 *
		 * @see \MainWP_Extensions::hookGetSites
		 */
		add_filter( 'mainwp-getsites', array( MainWP_Extensions::get_class_name(), 'hookGetSites' ), 10, 5 );
		add_filter( 'mainwp-getdbsites', array( MainWP_Extensions::get_class_name(), 'hookGetDBSites' ), 10, 5 );

		/**
		 * This hook allows you to get a information about groups via the 'mainwp-getgroups' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getgroups
		 *
		 * @see \MainWP_Extensions::hookGetGroups
		 */
		add_filter( 'mainwp-getgroups', array( MainWP_Extensions::get_class_name(), 'hookGetGroups' ), 10, 4 );
		add_action( 'mainwp_fetchurlsauthed', array( &$this, 'filter_fetch_urls_authed' ), 10, 7 );
		add_filter( 'mainwp_fetchurlauthed', array( &$this, 'filter_fetch_url_authed' ), 10, 6 );
		add_filter( 'mainwp_getdashboardsites', array(
			MainWP_Extensions::get_class_name(),
			'hookGetDashboardSites',
		), 10, 7 );
		add_filter(
			'mainwp-manager-getextensions', array(
				MainWP_Extensions::get_class_name(),
				'hookManagerGetExtensions',
			)
		);

		do_action( 'mainwp-activated' );

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
		// WP-Cron
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			if ( isset( $_GET['mainwp_run'] ) && ! empty( $_GET['mainwp_run'] ) ) {
				add_action( 'init', array( $this, 'cron_active' ), PHP_INT_MAX );
			}
		}
		add_action( 'mainwp_admin_footer', array( 'MainWP_UI', 'usersnap_integration' ) );
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
			$query  = substr( $query, 0, strlen( $query ) - 2 );
			$query .= ')';

			$alloptions_db = $wpdb->get_results( $query );
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

	public function init_cron() {

		$useWPCron = ( get_option( 'mainwp_wp_cron' ) === false ) || ( get_option( 'mainwp_wp_cron' ) == 1 );
		$sched     = wp_next_scheduled( 'mainwp_cronstats_action' );
		if ( $sched == false ) {
			if ( $useWPCron ) {
				wp_schedule_event( time(), 'hourly', 'mainwp_cronstats_action' );
			}
		} else {
			if ( ! $useWPCron ) {
				wp_unschedule_event( $sched, 'mainwp_cronstats_action' );
			}
		}

		if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			$sched = wp_next_scheduled( 'mainwp_cronbackups_action' );
			if ( $sched == false ) {
				if ( $useWPCron ) {
					wp_schedule_event( time(), 'hourly', 'mainwp_cronbackups_action' );
				}
			} else {
				if ( ! $useWPCron ) {
					wp_unschedule_event( $sched, 'mainwp_cronbackups_action' );
				}
			}
			$sched = wp_next_scheduled( 'mainwp_cronbackups_continue_action' );
			if ( $sched == false ) {
				if ( $useWPCron ) {
					wp_schedule_event( time(), '5minutely', 'mainwp_cronbackups_continue_action' );
				}
			} else {
				if ( ! $useWPCron ) {
					wp_unschedule_event( $sched, 'mainwp_cronbackups_continue_action' );
				}
			}
		} else {
			$sched = wp_next_scheduled( 'mainwp_cronbackups_action' );
			if ( $sched ) {
				wp_unschedule_event( $sched, 'mainwp_cronbackups_action' );
			}
			$sched = wp_next_scheduled( 'mainwp_cronbackups_continue_action' );
			if ( $sched ) {
				wp_unschedule_event( $sched, 'mainwp_cronbackups_continue_action' );
			}
		}
		$sched = wp_next_scheduled( 'mainwp_cronremotedestinationcheck_action' );
		if ( $sched != false ) {
			wp_unschedule_event( $sched, 'mainwp_cronremotedestinationcheck_action' );
		}
		$sched = wp_next_scheduled( 'mainwp_cronpingchilds_action' );
		if ( $sched == false ) {
			if ( $useWPCron ) {
				wp_schedule_event( time(), 'daily', 'mainwp_cronpingchilds_action' );
			}
		} else {
			if ( ! $useWPCron ) {
				wp_unschedule_event( $sched, 'mainwp_cronpingchilds_action' );
			}
		}
		$sched = wp_next_scheduled( 'mainwp_cronupdatescheck_action' );
		if ( false == $sched ) {
			if ( $useWPCron ) {
				wp_schedule_event( time(), 'minutely', 'mainwp_cronupdatescheck_action' );
			}
		} else {
			if ( ! $useWPCron ) {
				wp_unschedule_event( $sched, 'mainwp_cronupdatescheck_action' );
			}
		}
	}

	public function cron_active() {
		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
			return;
		}
		if ( empty( $_GET['mainwp_run'] ) || 'test' !== $_GET['mainwp_run'] ) {
			return;
		}
		session_write_close();
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ), true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-MainWP-Version: ' . self::$version, true );
		nocache_headers();
		if ( 'test' == $_GET['mainwp_run'] ) {
			die( 'MainWP Test' );
		}
		die( '' );
	}

	public function filter_fetch_urls_authed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output ) {
		return MainWP_Extensions::hookFetchUrlsAuthed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output, $is_external_hook = true );
	}

	public function filter_fetch_url_authed( $pluginFile, $key, $websiteId, $what, $params, $raw_response = null ) {
		return MainWP_Extensions::hookFetchUrlAuthed( $pluginFile, $key, $websiteId, $what, $params, $raw_response );
	}


	public function after_extensions_plugin_row( $plugin_slug, $plugin_data, $status ) {
		$extensions = MainWP_Extensions::getExtensions();
		if ( ! isset( $extensions[ $plugin_slug ] ) ) {
			return;
		}

		if ( ! isset( $extensions[ $plugin_slug ]['apiManager'] ) || ! $extensions[ $plugin_slug ]['apiManager'] ) {
			return;
		}

		if ( isset( $extensions[ $plugin_slug ]['activated_key'] ) && 'Activated' == $extensions[ $plugin_slug ]['activated_key'] ) {
			return;
		}

		$slug = basename( $plugin_slug, '.php' );

		$activate_notices = get_user_option( 'mainwp_hide_activate_notices' );
		if ( is_array( $activate_notices ) ) {
			if ( isset( $activate_notices[ $slug ] ) ) {
				return;
			}
		}

		?>
		<style type="text/css">
			tr[data-plugin="<?php echo esc_attr($plugin_slug); ?>"] {
				box-shadow: none;
			}
		</style>
		<tr class="plugin-update-tr active" slug="<?php echo esc_attr($slug); ?>"><td colspan="3" class="plugin-update colspanchange"><div class="update-message api-deactivate">
					<?php printf( __( 'You have a MainWP Extension that does not have an active API entered.  This means you will not receive updates or support.  Please visit the %1$sExtensions%2$s page and enter your API.', 'mainwp' ), '<a href="admin.php?page=Extensions">', '</a>' ); ?>
					<span class="mainwp-right"><a href="#" class="mainwp-activate-notice-dismiss" ><i class="times circle icon"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
				</div></td></tr>
		<?php
	}

	public function parse_request() {
		if ( file_exists( MAINWP_PLUGIN_DIR . '/response/api.php' ) ) {
			include_once MAINWP_PLUGIN_DIR . '/response/api.php';
		}
	}

	public function localization() {
		load_plugin_textdomain( 'mainwp', false, dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/' );
	}

	public function activated_check() {
		return $this->get_version();
	}

	public function activated_sub_check() {
		return array( 'result' => MAINWP_API_VALID );
	}

	public function mainwp_4_update_notice() {
		if ( MainWP_Utility::show_mainwp_message( 'notice', 'upgrade_4' ) ) {
			?>
			<div class="ui icon message yellow" style="margin-bottom: 0; border-radius: 0;">
				<i class="exclamation circle icon"></i>
				<strong><?php echo esc_html__( 'Important Notice: ', 'mainwp' ); ?></strong>&nbsp;<?php printf( __( 'MainWP Version 4 is a major upgrade from MainWP Version 3. Please, read this&nbsp; %1$supdating FAQ%2$s.', 'mainwp' ), '<a href="https://mainwp.com/help/docs/faq-on-upgrading-from-mainwp-version-3-to-mainwp-version-4/" target="_blank">', '</a>' ); ?>
				<i class="close icon mainwp-notice-dismiss" notice-id="upgrade_4"></i>
			</div>
			<?php
		}
	}

	public function admin_notices() {
		if ( get_option( 'mainwp_refresh' ) ) {
			echo '<meta http-equiv="refresh" content="0">';
			delete_option( 'mainwp_refresh' );
		}

		$current_options = get_option( 'mainwp_showhide_events_notice' );
		if ( ! is_array( $current_options ) ) {
			$current_options = array();
		}

		$phpver = phpversion();
		if ( version_compare( $phpver, '5.5', '<' ) ) {
			if ( MainWP_Utility::show_mainwp_message( 'notice', 'phpver_5_5' ) ) {
				?>
				<div class="ui icon yellow message" style="margin-bottom: 0; border-radius: 0;">
					<i class="exclamation circle icon"></i>
					<?php printf( __( 'Your server is currently running PHP version %1$s. In the next few months your MainWP Dashboard will require PHP 5.6 as a minimum. Please upgrade your server to at least 5.6 but we recommend PHP 7 or newer. You can find a template email to send your host %2$shere%3$s.', 'mainwp' ), $phpver, '<a href="https://wordpress.org/about/requirements/" target="_blank">', '</a>' ); ?>
					<i class="close icon mainwp-notice-dismiss" notice-id="phpver_5_5"></i>
				</div>
				<?php
			}
		}

		if ( MainWP_Server_Information::is_openssl_config_warning() ) {
			if ( MainWP_Utility::show_mainwp_message( 'notice', 'ssl_warn' ) ) {
				if ( isset( $_GET['page'] ) && 'SettingsAdvanced' != $_GET['page'] ) {
					?>
					<div class="ui icon yellow message" style="margin-bottom: 0; border-radius: 0;">
						<i class="exclamation circle icon"></i>
						<?php printf( __( 'MainWP has detected that the <strong>OpenSSL.cnf</strong> file is not configured properly. It is required to configure this so you can start connecting your child sites. Please, %1$sclick here to configure it!%2$s', 'mainwp' ), '<a href="admin.php?page=SettingsAdvanced">', '</a>' ); ?>
						<i class="close icon mainwp-notice-dismiss" notice-id="ssl_warn"></i>
					</div>
					<?php
				}
			}
		}

		if ( is_multisite() && ( ! isset( $current_options['hide_multi_site_notice'] ) || empty( $current_options['hide_multi_site_notice'] ) ) ) {
			?>
			<div class="ui icon red message" style="margin-bottom: 0; border-radius: 0;">
				<i class="exclamation circle icon"></i>
				<?php esc_html_e( 'MainWP plugin is not designed nor fully tested on WordPress Multisite installations. Various features may not work properly. We highly recommend installing it on a single site installation!', 'mainwp' ); ?>
				<i class="close icon mainwp-notice-dismiss" notice-id="multi_site"></i>
			</div>
			<?php
		}

		if ( ! isset( $current_options['trust_child'] ) || empty( $current_options['trust_child'] ) ) {
			if ( self::is_mainwp_pages() ) {
				if ( ! MainWP_Plugins::check_auto_update_plugin( 'mainwp-child/mainwp-child.php' ) ) {
					?>
					<div class="ui icon yellow message" style="margin-bottom: 0; border-radius: 0;">
						<i class="info circle icon"></i>
						<div class="content">
							<?php esc_html_e( 'You have not set your MainWP Child plugins for auto updates, this is highly recommended!', 'mainwp' ); ?> <a id="mainwp_btn_autoupdate_and_trust" class="ui mini yellow button" href="#"><?php esc_html_e( 'Turn On', 'mainwp' ); ?></a>
						</div>
						<i class="close icon mainwp-events-notice-dismiss" notice="trust_child"></i>
					</div>
					<?php
				}
			}
		}

		$display_request1 = false;
		$display_request2 = false;

		if ( isset( $current_options['request_reviews1'] ) ) {
			if ( 'forever' == $current_options['request_reviews1'] ) {
				$display_request1 = false;
			} else {
				$days             = intval( $current_options['request_reviews1'] );   // 15 or 30
				$start_time       = $current_options['request_reviews1_starttime'];
				$display_request1 = ( ( time() - $start_time ) > $days * 24 * 3600 ) ? true : false;
			}
		} else {
			$current_options['request_reviews1']           = 30;
			$current_options['request_reviews1_starttime'] = time();
			update_option( 'mainwp_showhide_events_notice', $current_options );
		}

		if ( isset( $current_options['request_reviews2'] ) ) {
			if ( 'forever' == $current_options['request_reviews2'] ) {
				$display_request2 = false;
			} else {
				$days             = intval( $current_options['request_reviews2'] );   // 15
				$start_time       = $current_options['request_reviews2_starttime'];
				$display_request2 = ( ( time() - $start_time ) > $days * 24 * 3600 ) ? true : false;
			}
		} else {
			$currentExtensions = ( MainWP_Extensions::$extensionsLoaded ? MainWP_Extensions::$extensions : get_option( 'mainwp_extensions' ) );
			if ( is_array( $currentExtensions ) && count( $currentExtensions ) > 10 ) {
				$display_request2 = true;
			}
		}

		if ( $display_request1 ) {
			$this->render_rating_notice_1();
		} elseif ( $display_request2 ) {
			$this->render_rating_notice_2();
		}
	}

	public function render_rating_notice_1() {
		?>
			<div class="ui green icon message" style="margin-bottom: 0; border-radius: 0;">
				<i class="star icon"></i>
				<div class="content">
					<div class="header">
						<?php esc_html_e( 'Hi, I noticed you have been using MainWP for over 30 days and that\'s awesome!', 'mainwp' ); ?>
					</div>
						<?php esc_html_e( 'Could you please do me a BIG favor and give it a 5-star rating on WordPress? Reviews from users like you really help the MainWP community grow.', 'mainwp' ); ?>
					<br /><br />
					<a href="https://wordpress.org/support/view/plugin-reviews/mainwp#postform" target="_blank" class="ui green mini button"><?php esc_html_e( 'Ok, you deserve!', 'mainwp' ); ?></a>
					<a href="" class="ui mini green basic button mainwp-events-notice-dismiss" notice="request_reviews1"><?php esc_html_e( 'Nope, maybe later.', 'mainwp' ); ?></a>
					<a href="" class="ui mini green basic button mainwp-events-notice-dismiss" notice="request_reviews1_forever"><?php esc_html_e( 'I already did.', 'mainwp' ); ?></a>
				</div>
				<i class="close icon mainwp-notice-dismiss" notice="request_reviews1"></i>
			</div>
		<?php
	}

	public function render_rating_notice_2() {
		?>
			<div class="ui green icon message" style="margin-bottom: 0; border-radius: 0;">
							<i class="star icon"></i>
				<div class="content">
					<div class="header">
						<?php esc_html_e( 'Hi, I noticed you have a few MainWP Extensions installed and that\'s awesome!', 'mainwp' ); ?>
			</div>
					<?php esc_html_e( 'Could you please do me a BIG favor and give it a 5-star rating on WordPress? Reviews from users like you really help the MainWP community to grow.', 'mainwp' ); ?>
					<br /><br />
					<a href="https://wordpress.org/support/view/plugin-reviews/mainwp#postform" target="_blank" class="ui green mini button"><?php esc_html_e( 'Ok, you deserve!', 'mainwp' ); ?></a>
					<a href="" class="ui mini green basic button mainwp-events-notice-dismiss" notice="request_reviews2"><?php esc_html_e( 'Nope, maybe later.', 'mainwp' ); ?></a>
					<a href="" class="ui mini green basic button mainwp-events-notice-dismiss" notice="request_reviews2_forever"><?php esc_html_e( 'I already did.', 'mainwp' ); ?></a>
				</div>
				<i class="close icon mainwp-notice-dismiss" notice="request_reviews2"></i>
			</div>
			<?php
	}

	public function wp_admin_notices() {
		global $pagenow;

		$mail_failed = get_option( 'mainwp_notice_wp_mail_failed' );
		if ( 'yes' == $mail_failed ) {
			?>
			<div class="ui icon yellow message" style="margin-bottom: 0; border-radius: 0;">
				<i class="exclamation circle icon"></i>
				<?php echo esc_html__( 'Send mail function may failed.', 'mainwp' ); ?>
				<i class="close icon mainwp-notice-dismiss" notice-id="mail_failed"></i>
			</div>
			<?php
		}

		if ( 'plugins.php' !== $pagenow ) {
			return;
		}

		$deactivated_exts = get_transient( 'mainwp_transient_deactivated_incomtible_exts' );

		if ( $deactivated_exts && is_array( $deactivated_exts ) && count( $deactivated_exts ) > 0 ) {
			// delete_transient( 'mainwp_transient_deactivated_incomtible_exts' );
			?>
			<div class='notice notice-error my-dismiss-notice is-dismissible'>
			<p><?php echo esc_html__( 'MainWP Dashboard 4.0 or newer requires Extensions 4.0 or newer. MainWP will automatically deactivate older versions of MainWP Extensions in order to prevent compatibility problems.', 'mainwp' ); ?></p>
			</div>
			<?php
		}
	}

	public function get_cron_schedules( $schedules ) {
		$schedules['5minutely'] = array(
			'interval'   => 5 * 60, // 5minutes in seconds
			'display'    => __( 'Once every 5 minutes', 'mainwp' ),
		);
		$schedules['minutely']  = array(
			'interval'   => 1 * 60, // 1minute in seconds
			'display'    => __( 'Once every minute', 'mainwp' ),
		);

		return $schedules;
	}

	public function wp_mail_failed( $error ) {
		$mail_failed = get_option( 'mainwp_notice_wp_mail_failed' );
		if ( is_object( $error ) && empty( $mail_failed ) ) {
			MainWP_Utility::update_option( 'mainwp_notice_wp_mail_failed', 'yes' );
			$er = $error->get_error_message();
			if ( ! empty($er) ) {
				MainWP_Logger::instance()->debug( 'Error :: wp_mail :: [error=' . $er . ']' );
			}
		}
	}

	public function get_version() {
		return $this->current_version;
	}

	public function check_update_custom( $transient ) {
		if ( isset( $_POST['action'] ) && ( ( 'update-plugin' === $_POST['action'] ) || ( 'update-selected' === $_POST['action'] ) ) ) {
			$extensions = MainWP_Extensions::getExtensions( array( 'activated' => true ) );
			if ( defined( 'DOING_AJAX' ) && isset( $_POST['plugin'] ) && 'update-plugin' == $_POST['action'] ) {
				$plugin_slug = $_POST['plugin'];
				// get download pakage url to prevent expire
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
				} //Legacy, to support older versions.

				$plugin_slug = MainWP_Extensions::getPluginSlug( $rslt->slug );
				if ( isset( $transient->checked[ $plugin_slug ] ) && version_compare( $rslt->latest_version, $transient->checked[ $plugin_slug ], '>' ) ) {
					$transient->response[ $plugin_slug ] = self::map_rslt_obj( $rslt );
				}
			}
		}

		return $transient;
	}

	public static function map_rslt_obj( $pRslt ) {
		$obj              = new stdClass();
		$obj->slug        = $pRslt->slug;
		$obj->new_version = $pRslt->latest_version;
		$obj->url         = 'https://mainwp.com/';
		$obj->package     = $pRslt->download_url;

		return $obj;
	}

	private function check_upgrade() {
		$result = MainWP_API_Settings::check_upgrade();
		if ( null === $this->upgradeVersionInfo ) {
			$this->upgradeVersionInfo = new stdClass();
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

		if ( ( null == $this->upgradeVersionInfo ) || ( ( time() - $this->upgradeVersionInfo->updated ) > 60 ) ) {  // one minute before recheck to prevent check update information to many times
			$this->check_upgrade();
		}

		if ( null != $this->upgradeVersionInfo && property_exists( $this->upgradeVersionInfo, 'result' ) && is_array( $this->upgradeVersionInfo->result ) ) {
			$extensions = MainWP_Extensions::getExtensions( array( 'activated' => true ) );
			foreach ( $this->upgradeVersionInfo->result as $rslt ) {
				$plugin_slug = MainWP_Extensions::getPluginSlug( $rslt->slug );
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

		if ( $arg->slug === $this->slug ) {
			return $false;
		}

		$result   = MainWP_Extensions::getSlugs();
		$am_slugs = $result['am_slugs'];

		if ( '' !== $am_slugs ) {
			$am_slugs = explode( ',', $am_slugs );
			if ( in_array( $arg->slug, $am_slugs ) ) {
				$info = MainWP_API_Settings::get_plugin_information( $arg->slug );
				if ( is_object( $info ) && property_exists( $info, 'sections' ) ) {
					if ( ! is_array( $info->sections ) || ! isset( $info->sections['changelog'] ) || empty( $info->sections['changelog'] ) ) {
						$exts_data = MainWP_Extensions_View::getAvailableExtensions();
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

	public function print_digest_lines( $array, $backupChecks = null, $what = 'update' ) {

		$plain_text = apply_filters( 'mainwp_text_format_email', false );

		$output = '';

		if ( 'disc_sites' === $what ) {
			if ( $plain_text ) {
				foreach ( $array as $url ) {
					$output .= $url . "\r\n";
				}
			} else {
				foreach ( $array as $url ) {
					$output .= '<li>' . $url . '</li>' . "\n";
				}
			}
		} else {

			if ( $plain_text ) {
				foreach ( $array as $line ) {
					$siteId      = $line[0];
					$text        = $line[1];
					$trustedText = $line[2];

					$output .= $text . $trustedText . ( null == $backupChecks || ! isset( $backupChecks[ $siteId ] ) || ( true == $backupChecks[ $siteId ] ) ? '' : '(Requires manual backup)' ) . "\r\n";
				}
			} else {
				foreach ( $array as $line ) {
					$siteId      = $line[0];
					$text        = $line[1];
					$trustedText = $line[2];

					$output .= '<li>' . $text . $trustedText . ( null == $backupChecks || ! isset( $backupChecks[ $siteId ] ) || ( true == $backupChecks[ $siteId ] ) ? '' : '(Requires manual backup)' ) . '</li>' . "\n";
				}
			}
		}

		return $output;
	}

	public static function get_timestamp_from_hh_mm( $hh_mm ) {
			$hh_mm = explode( ':', $hh_mm );
			$_hour = isset( $hh_mm[0] ) ? intval( $hh_mm[0] ) : 0;
			$_mins = isset( $hh_mm[1] ) ? intval( $hh_mm[1] ) : 0;
		if ( $_hour < 0 || $_hour > 23 ) {
			$_hour = 0;
		}
		if ( $_mins < 0 || $_mins > 59 ) {
			$_mins = 0;
		}
			return strtotime( date( 'Y-m-d' ) . ' ' . $_hour . ':' . $_mins . ':59' );
	}

	public static function get_period_of_time_from_hh_mm( $hh_mm ) {
			$hh_mm = explode( ':', $hh_mm );
			$_hour = isset( $hh_mm[0] ) ? intval( $hh_mm[0] ) : 0;
			$_mins = isset( $hh_mm[1] ) ? intval( $hh_mm[1] ) : 0;

		if ( $_hour < 0 || $_hour > 23 ) {
			$_hour = 0;
		}

		if ( $_mins < 0 || $_mins > 59 ) {
			$_mins = 0;
		}
			return $_hour * 60 + $_mins; // mins
	}

	public function mainwp_cronupdatescheck_action() {
		MainWP_Logger::instance()->info( 'CRON :: updates check' );

		@ignore_user_abort( true );
		@set_time_limit( 0 );
		add_filter( 'admin_memory_limit', function() {
			return '512M';
		} );

		$timeDailyUpdate = get_option( 'mainwp_timeDailyUpdate' );

		// to check time to run daily
		$run_timestamp = 0; // 0 hour
		if ( ! empty($timeDailyUpdate) ) {
			$run_timestamp = self::get_timestamp_from_hh_mm( $timeDailyUpdate );
			if ( time() < $run_timestamp ) {
				return;
			}
		}

		$updatecheck_running = ( 'Y' == get_option( 'mainwp_updatescheck_is_running' ) ? true : false );

		$lasttimeAutomaticUpdate = get_option( 'mainwp_updatescheck_last_timestamp' );
		$frequencyDailyUpdate    = get_option( 'mainwp_frequencyDailyUpdate' );
		if ( $frequencyDailyUpdate <= 0 ) {
			$frequencyDailyUpdate = 1;
		}

		$period_of_time = $run_timestamp ? self::get_period_of_time_from_hh_mm( $timeDailyUpdate ) : 0; // mins
		// to valid period of time
		if ( $period_of_time > 24 * 60 ) {
			$period_of_time = 0;
		}

		$enableFrequencyAutomaticUpdate = false;
		// to check frequency to run daily
		// if the $period_of_time value is not valid then frequency run will avoid, automatic update will run one time per day as default
		if ( $period_of_time > 0 ) {
			$mins_between = ( 24 * 60 - $period_of_time ) / $frequencyDailyUpdate; // mins
			if ( time() < $lasttimeAutomaticUpdate + $mins_between * 60 ) {
				// if update checking is running then continue do that
				if ( ! $updatecheck_running ) {
					return;
				}
			} else {
				$enableFrequencyAutomaticUpdate = true;
			}
		}

		$mainwpAutomaticDailyUpdate = get_option( 'mainwp_automaticDailyUpdate' );

		$plugin_automaticDailyUpdate = get_option( 'mainwp_pluginAutomaticDailyUpdate' );
		$theme_automaticDailyUpdate  = get_option( 'mainwp_themeAutomaticDailyUpdate' );

		$mainwpLastAutomaticUpdate          = get_option( 'mainwp_updatescheck_last' );
		$mainwpHoursIntervalAutomaticUpdate = apply_filters( 'mainwp_updatescheck_hours_interval', false);

		if ( $mainwpHoursIntervalAutomaticUpdate > 0 ) {
			if ( $lasttimeAutomaticUpdate && ( $lasttimeAutomaticUpdate + $mainwpHoursIntervalAutomaticUpdate * 3600 > time() ) ) {
				// if update checking is running then continue do that
				if ( ! $updatecheck_running ) {
					MainWP_Logger::instance()->debug( 'CRON :: updates check :: already updated hours interval' );
					return;
				}
			}
		} elseif ( $enableFrequencyAutomaticUpdate ) {
			// ok go to frequency sync
			$websites = array();
		} elseif ( date( 'd/m/Y' ) === $mainwpLastAutomaticUpdate ) {
			MainWP_Logger::instance()->debug( 'CRON :: updates check :: already updated today' );

			return;
		}

		if ( 'Y' == get_option( 'mainwp_updatescheck_ready_sendmail' ) ) {
			$send_noti_at = apply_filters( 'mainwp_updatescheck_sendmail_at_time', false );
			if ( ! empty( $send_noti_at ) ) {
				$send_timestamp = self::get_timestamp_from_hh_mm( $send_noti_at );
				if ( time() < $send_timestamp ) {
					return; // send notification later
				}
			}
		}

		$disable_send_noti = apply_filters( 'mainwp_updatescheck_disable_sendmail', false );

		$websites             = array();
		$checkupdate_websites = MainWP_DB::instance()->get_websites_check_updates( 4 );

		foreach ( $checkupdate_websites as $website ) {
			if ( ! MainWP_DB::instance()->backup_full_task_running( $website->id ) ) {
				$websites[] = $website;
			}
		}

		MainWP_Logger::instance()->debug( 'CRON :: updates check :: found ' . count( $checkupdate_websites ) . ' websites' );
		MainWP_Logger::instance()->debug( 'CRON :: backup task running :: found ' . ( count( $checkupdate_websites ) - count( $websites ) ) . ' websites' );
		MainWP_Logger::instance()->info_update( 'CRON :: updates check :: found ' . count( $checkupdate_websites ) . ' websites' );

		$userid = null;
		foreach ( $websites as $website ) {
			$websiteValues = array(
				'dtsAutomaticSyncStart' => time(),
			);
			if ( null === $userid ) {
				$userid = $website->userid;
			}

			MainWP_DB::instance()->update_website_sync_values( $website->id, $websiteValues );
		}

		$text_format = get_option( 'mainwp_daily_digest_plain_text', false );

		if ( $text_format ) {
			$content_type = "Content-Type: text/plain; charset=\"utf-8\"\r\n";
		} else {
			$content_type = "Content-Type: text/html; charset=\"utf-8\"\r\n";
		}

		if ( count( $checkupdate_websites ) == 0 ) {
			$busyCounter = MainWP_DB::instance()->get_websites_count_where_dts_automatic_sync_smaller_then_start();
			MainWP_Logger::instance()->info_update( 'CRON :: busy counter :: found ' . $busyCounter . ' websites' );
			if ( 0 === $busyCounter ) {
				if ( 'Y' != get_option( 'mainwp_updatescheck_ready_sendmail' ) ) {
					MainWP_Utility::update_option( 'mainwp_updatescheck_ready_sendmail', 'Y' );
					return; // to check time before send notification
				}

				// set checking to done, so will check for other settings to run
				if ( $updatecheck_running ) {
					MainWP_Utility::update_option( 'mainwp_updatescheck_is_running', '' );
				}

				update_option( 'mainwp_last_synced_all_sites', time() );
				MainWP_Logger::instance()->debug( 'CRON :: updates check :: got to the mail part' );

				// Send the email & update all to this time!
				$mail     = '';
				$sendMail = false;

				$sitesCheckCompleted = null;
				if ( get_option( 'mainwp_backup_before_upgrade' ) == 1 ) {
					$sitesCheckCompleted = get_option( 'mainwp_automaticUpdate_backupChecks' );
					if ( ! is_array( $sitesCheckCompleted ) ) {
						$sitesCheckCompleted = null;
					}
				}

				$pluginsNewUpdate = get_option( 'mainwp_updatescheck_mail_update_plugins_new' );
				if ( ! is_array( $pluginsNewUpdate ) ) {
					$pluginsNewUpdate = array();
				}
				$pluginsToUpdate = get_option( 'mainwp_updatescheck_mail_update_plugins' );
				if ( ! is_array( $pluginsToUpdate ) ) {
					$pluginsToUpdate = array();
				}
				$notTrustedPluginsNewUpdate = get_option( 'mainwp_updatescheck_mail_ignore_plugins_new' );
				if ( ! is_array( $notTrustedPluginsNewUpdate ) ) {
					$notTrustedPluginsNewUpdate = array();
				}
				$notTrustedPluginsToUpdate = get_option( 'mainwp_updatescheck_mail_ignore_plugins' );
				if ( ! is_array( $notTrustedPluginsToUpdate ) ) {
					$notTrustedPluginsToUpdate = array();
				}

				if ( ! empty( $plugin_automaticDailyUpdate ) ) {
					if ( ( count( $pluginsNewUpdate ) != 0 ) || ( count( $pluginsToUpdate ) != 0 ) || ( count( $notTrustedPluginsNewUpdate ) != 0 ) || ( count( $notTrustedPluginsToUpdate ) != 0 )
					) {
						$sendMail = true;

						$mail_lines  = '';
						$mail_lines .= $this->print_digest_lines( $pluginsNewUpdate );
						$mail_lines .= $this->print_digest_lines( $pluginsToUpdate, $sitesCheckCompleted  );
						$mail_lines .= $this->print_digest_lines( $notTrustedPluginsNewUpdate  );
						$mail_lines .= $this->print_digest_lines( $notTrustedPluginsToUpdate  );

						if ( $text_format ) {
							$mail .= 'WordPress Plugin Updates' . "\r\n";
							$mail .= "\r\n";
							$mail .= $mail_lines;
							$mail .= "\r\n";
						} else {
							$mail .= '<div><strong>WordPress Plugin Updates</strong></div>';
							$mail .= '<ul>';
							$mail .= $mail_lines;
							$mail .= '</ul>';
						}
					}
				}

				$themesNewUpdate = get_option( 'mainwp_updatescheck_mail_update_themes_new' );
				if ( ! is_array( $themesNewUpdate ) ) {
					$themesNewUpdate = array();
				}
				$themesToUpdate = get_option( 'mainwp_updatescheck_mail_update_themes' );
				if ( ! is_array( $themesToUpdate ) ) {
					$themesToUpdate = array();
				}
				$notTrustedThemesNewUpdate = get_option( 'mainwp_updatescheck_mail_ignore_themes_new' );
				if ( ! is_array( $notTrustedThemesNewUpdate ) ) {
					$notTrustedThemesNewUpdate = array();
				}
				$notTrustedThemesToUpdate = get_option( 'mainwp_updatescheck_mail_ignore_themes' );
				if ( ! is_array( $notTrustedThemesToUpdate ) ) {
					$notTrustedThemesToUpdate = array();
				}

				if ( ! empty( $theme_automaticDailyUpdate ) ) {
					if ( ( count( $themesNewUpdate ) != 0 ) || ( count( $themesToUpdate ) != 0 ) || ( count( $notTrustedThemesNewUpdate ) != 0 ) || ( count( $notTrustedThemesToUpdate ) != 0 )
					) {
						$sendMail = true;

						$mail_lines  = '';
						$mail_lines .= $this->print_digest_lines( $themesNewUpdate );
						$mail_lines .= $this->print_digest_lines( $themesToUpdate, $sitesCheckCompleted  );
						$mail_lines .= $this->print_digest_lines( $notTrustedThemesNewUpdate  );
						$mail_lines .= $this->print_digest_lines( $notTrustedThemesToUpdate  );

						if ( $text_format ) {
							$mail .= 'WordPress Themes Updates' . "\r\n";
							$mail .= "\r\n";
							$mail .= $mail_lines;
							$mail .= "\r\n";
						} else {
							$mail .= '<div><strong>WordPress Themes Updates</strong></div>';
							$mail .= '<ul>';
							$mail .= $mail_lines;
							$mail .= '</ul>';
						}
					}
				}

				$coreNewUpdate = get_option( 'mainwp_updatescheck_mail_update_core_new' );
				if ( ! is_array( $coreNewUpdate ) ) {
					$coreNewUpdate = array();
				}
				$coreToUpdate = get_option( 'mainwp_updatescheck_mail_update_core' );
				if ( ! is_array( $coreToUpdate ) ) {
					$coreToUpdate = array();
				}
				$ignoredCoreNewUpdate = get_option( 'mainwp_updatescheck_mail_ignore_core_new' );
				if ( ! is_array( $ignoredCoreNewUpdate ) ) {
					$ignoredCoreNewUpdate = array();
				}
				$ignoredCoreToUpdate = get_option( 'mainwp_updatescheck_mail_ignore_core' );
				if ( ! is_array( $ignoredCoreToUpdate ) ) {
					$ignoredCoreToUpdate = array();
				}

				if ( ! empty( $mainwpAutomaticDailyUpdate ) ) {
					if ( ( count( $coreNewUpdate ) != 0 ) || ( count( $coreToUpdate ) != 0 ) || ( count( $ignoredCoreNewUpdate ) != 0 ) || ( count( $ignoredCoreToUpdate ) != 0 ) ) {
						$sendMail = true;

						$mail_lines  = '';
						$mail_lines .= $this->print_digest_lines( $coreNewUpdate );
						$mail_lines .= $this->print_digest_lines( $coreToUpdate, $sitesCheckCompleted  );
						$mail_lines .= $this->print_digest_lines( $ignoredCoreNewUpdate  );
						$mail_lines .= $this->print_digest_lines( $ignoredCoreToUpdate  );

						if ( $text_format ) {
							$mail .= 'WordPress Core Updates' . "\r\n";
							$mail .= "\r\n";
							$mail .= $mail_lines;
							$mail .= "\r\n";
						} else {
							$mail .= '<div><strong>WordPress Core Updates</strong></div>';
							$mail .= '<ul>';
							$mail .= $mail_lines;
							$mail .= '</ul>';
						}
					}
				}

				$sitesDisconnect = MainWP_DB::instance()->get_disconnected_websites();
				if ( count( $sitesDisconnect ) != 0 ) {
					$sendMail   = true;
					$mail_lines = $this->print_digest_lines( $sitesDisconnect, null, 'disc_sites' );
					if ( $text_format ) {
						$mail .= 'Connection Status' . "\r\n";
						$mail .= "\r\n";
						$mail .= $mail_lines;
						$mail .= "\r\n";
					} else {
						$mail .= '<b style="color: rgb(127, 177, 0); font-family: Helvetica, Sans; font-size: medium; line-height: normal;"> Connection Status </b><br>';
						$mail .= '<ul>';
						$mail .= $mail_lines;
						$mail .= '</ul>';
					}
				}

				$mail = apply_filters( 'mainwp_daily_digest_content', $mail, $text_format );

				MainWP_Utility::update_option( 'mainwp_automaticUpdate_backupChecks', '' );

				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_core_new', '' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_plugins_new', '' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_themes_new', '' );

				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_core', '' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_plugins', '' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_themes', '' );

				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_core', '' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_plugins', '' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_themes', '' );

				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_core_new', '' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_plugins_new', '' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_themes_new', '' );

				MainWP_Utility::update_option( 'mainwp_updatescheck_last', date( 'd/m/Y' ) );
				MainWP_Utility::update_option( 'mainwp_updatescheck_last_timestamp', time() );
				MainWP_Utility::update_option( 'mainwp_updatescheck_ready_sendmail', '' );

				$plain_text = apply_filters( 'mainwp_text_format_email', false );
				MainWP_Utility::update_option( 'mainwp_daily_digest_plain_text', $plain_text );

				MainWP_Utility::update_option( 'mainwp_updatescheck_sites_icon', '' );

				if ( 1 == get_option( 'mainwp_check_http_response', 0 ) ) {

					$sitesHttpCheckIds = get_option( 'mainwp_automaticUpdate_httpChecks' );
					if ( ! is_array( $sitesHttpCheckIds ) ) {
						$sitesHttpCheckIds = array();
					}

					$mail_offline = '';
					$sitesOffline = array();
					if ( count( $sitesHttpCheckIds ) > 0 ) {
						$sitesOffline = MainWP_DB::instance()->get_websites_by_ids( $sitesHttpCheckIds );
					}
					if ( is_array( $sitesOffline ) && count( $sitesOffline ) > 0 ) {
						foreach ( $sitesOffline as $site ) {
							if ( -1 == $site->offline_check_result ) {
								$mail_offline .= '<li>' . $site->name . ' - [' . $site->url . '] - [' . $site->http_response_code . ']</li>';
							}
						}
					}

					$email = get_option( 'mainwp_updatescheck_mail_email' );
					if ( ! $disable_send_noti && ! empty( $email ) && '' != $mail_offline ) {
						MainWP_Logger::instance()->debug( 'CRON :: http check :: send mail to ' . $email );
						$mail_offline           = '<div>After running auto updates, following sites are not returning expected HTTP request response:</div>
                                <div></div>
                                <ul>
                                ' . $mail_offline . '
                                </ul>
                                <div></div>
                                <div>Please visit your MainWP Dashboard as soon as possible and make sure that your sites are online. (<a href="' . site_url() . '">' . site_url() . '</a>)</div>';
						wp_mail(
							$email, $mail_title = 'MainWP - HTTP response check',
							MainWP_Utility::format_email(
								$email, $mail_offline, $mail_title
							),
							array(
								'From: "' . get_option( 'admin_email' ) . '" <' . get_option( 'admin_email' ) . '>',
								$content_type,
							)
						);
					}
					MainWP_Utility::update_option( 'mainwp_automaticUpdate_httpChecks', '' );
				}

				$disabled_notification = apply_filters( 'mainwp_updatescheck_disable_notification_mail', false );
				if ( $disabled_notification ) {
					return;
				}

				if ( ! $sendMail ) {
					MainWP_Logger::instance()->debug( 'CRON :: updates check :: sendMail is false' );

					return;
				}

				if ( ! $disable_send_noti ) {
					// Create a nice email to send
					$email = get_option( 'mainwp_updatescheck_mail_email' );
					MainWP_Logger::instance()->debug( 'CRON :: updates check :: send mail to ' . $email );
					if ( false !== $email && '' !== $email ) {
						if ( $text_format ) {
							$mail = 'We noticed the following updates are available on your MainWP Dashboard. (' . site_url() . ')' . "\r\n"
									. $mail . "\r\n" .
									'If your MainWP is configured to use Auto Updates these updates will be installed in the next 24 hours.' . "\r\n";
						} else {
							$mail = '<div>We noticed the following updates are available on your MainWP Dashboard. (<a href="' . site_url() . '">' . site_url() . '</a>)</div>
                                     <div></div>
                                     ' . $mail . '
                                     <div> </div>
                                     <div>If your MainWP is configured to use Auto Updates these updates will be installed in the next 24 hours.</div>';
						}
						wp_mail(
							$email, $mail_title = 'Available Updates',
							MainWP_Utility::format_email(
								$email, $mail, $mail_title, $text_format
							),
							array(
								'From: "' . get_option( 'admin_email' ) . '" <' . get_option( 'admin_email' ) . '>',
								$content_type,
							)
						);
					}
				}
			}
		} else {

			if ( ! $updatecheck_running ) {
				MainWP_Utility::update_option( 'mainwp_updatescheck_is_running', 'Y' );
			}

			$userExtension = MainWP_DB::instance()->get_user_extension_by_user_id( $userid );

			$decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
			if ( ! is_array( $decodedIgnoredPlugins ) ) {
				$decodedIgnoredPlugins = array();
			}

			$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
			if ( ! is_array( $trustedPlugins ) ) {
				$trustedPlugins = array();
			}

			$decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
			if ( ! is_array( $decodedIgnoredThemes ) ) {
				$decodedIgnoredThemes = array();
			}

			$trustedThemes = json_decode( $userExtension->trusted_themes, true );
			if ( ! is_array( $trustedThemes ) ) {
				$trustedThemes = array();
			}

			$coreToUpdateNow      = array();
			$coreToUpdate         = array();
			$coreNewUpdate        = array();
			$ignoredCoreToUpdate  = array();
			$ignoredCoreNewUpdate = array();

			$pluginsToUpdateNow         = array();
			$pluginsToUpdate            = array();
			$pluginsNewUpdate           = array();
			$notTrustedPluginsToUpdate  = array();
			$notTrustedPluginsNewUpdate = array();

			$themesToUpdateNow         = array();
			$themesToUpdate            = array();
			$themesNewUpdate           = array();
			$notTrustedThemesToUpdate  = array();
			$notTrustedThemesNewUpdate = array();

			$allWebsites = array();

			$infoTrustedText    = ' (<span style="color:#008000"><strong>Trusted</strong></span>)';
			$infoNotTrustedText = '';

			foreach ( $websites as $website ) {
				$websiteDecodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
				if ( ! is_array( $websiteDecodedIgnoredPlugins ) ) {
					$websiteDecodedIgnoredPlugins = array();
				}

				$websiteDecodedIgnoredThemes = json_decode( $website->ignored_themes, true );
				if ( ! is_array( $websiteDecodedIgnoredThemes ) ) {
					$websiteDecodedIgnoredThemes = array();
				}

				// Perform check & update
				if ( ! MainWP_Sync::syncSite( $website, false, true ) ) {
					$websiteValues = array(
						'dtsAutomaticSync' => time(),
					);

					MainWP_DB::instance()->update_website_sync_values( $website->id, $websiteValues );

					continue;
				}
				$website = MainWP_DB::instance()->get_website_by_id( $website->id );

				/** Check core updates * */
				$websiteLastCoreUpgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'last_wp_upgrades' ), true );
				$websiteCoreUpgrades     = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true );

				// Run over every update we had last time..
				if ( isset( $websiteCoreUpgrades['current'] ) ) {

					if ( $text_format ) {
						$infoTxt    = stripslashes( $website->name ) . ' - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'] . ' - ' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id );
						$infoNewTxt = '*NEW* ' . stripslashes( $website->name ) . ' - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'] . ' - ' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id );
					} else {
						$infoTxt    = '<a href="' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) . '">' . stripslashes( $website->name ) . '</a> - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'];
						$infoNewTxt = '*NEW* <a href="' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) . '">' . stripslashes( $website->name ) . '</a> - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'];
					}

					$newUpdate = ! ( isset( $websiteLastCoreUpgrades['current'] ) && ( $websiteLastCoreUpgrades['current'] == $websiteCoreUpgrades['current'] ) && ( $websiteLastCoreUpgrades['new'] == $websiteCoreUpgrades['new'] ) );
					// to fix
					if ( ! $website->is_ignoreCoreUpdates ) {
						if ( 1 === $website->automatic_update ) {
							if ( $newUpdate ) {
								$coreNewUpdate[] = array( $website->id, $infoNewTxt, $infoTrustedText );
							} else {
								// Check ignore ? $ignoredCoreToUpdate
								$coreToUpdateNow[]           = $website->id;
								$allWebsites[ $website->id ] = $website;
								$coreToUpdate[]              = array( $website->id, $infoTxt, $infoTrustedText );
							}
						} else {
							if ( $newUpdate ) {
								$ignoredCoreNewUpdate[] = array( $website->id, $infoNewTxt, $infoNotTrustedText );
							} else {
								$ignoredCoreToUpdate[] = array( $website->id, $infoTxt, $infoNotTrustedText );
							}
						}
					}
				}

				/** Check plugins * */
				$websiteLastPlugins = json_decode( MainWP_DB::instance()->get_website_option( $website, 'last_plugin_upgrades' ), true );
				$websitePlugins     = json_decode( $website->plugin_upgrades, true );

				/** Check themes * */
				$websiteLastThemes = json_decode( MainWP_DB::instance()->get_website_option( $website, 'last_theme_upgrades' ), true );
				$websiteThemes     = json_decode( $website->theme_upgrades, true );

				$decodedPremiumUpgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' ), true );
				if ( is_array( $decodedPremiumUpgrades ) ) {
					foreach ( $decodedPremiumUpgrades as $slug => $premiumUpgrade ) {
						if ( 'plugin' === $premiumUpgrade['type'] ) {
							if ( ! is_array( $websitePlugins ) ) {
								$websitePlugins = array();
							}
							$websitePlugins[ $slug ] = $premiumUpgrade;
						} elseif ( 'theme' === $premiumUpgrade['type'] ) {
							if ( ! is_array( $websiteThemes ) ) {
								$websiteThemes = array();
							}
							$websiteThemes[ $slug ] = $premiumUpgrade;
						}
					}
				}

				// Run over every update we had last time..
				foreach ( $websitePlugins as $pluginSlug => $pluginInfo ) {
					if ( isset( $decodedIgnoredPlugins[ $pluginSlug ] ) || isset( $websiteDecodedIgnoredPlugins[ $pluginSlug ] ) ) {
						continue;
					}
					if ( $website->is_ignorePluginUpdates ) {
						continue;
					}
					$infoTxt    = '';
					$infoNewTxt = '';
					if ( $text_format ) {
						$infoTxt    = stripslashes( $website->name ) . ' - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'] . ' - ' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id );
						$infoNewTxt = '*NEW* ' . stripslashes( $website->name ) . ' - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'] . ' - ' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id );
					} else {
						$infoTxt    = '<a href="' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) . '">' . stripslashes( $website->name ) . '</a> - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'];
						$infoNewTxt = '*NEW* <a href="' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) . '">' . stripslashes( $website->name ) . '</a> - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'];
					}
					if ( $pluginInfo['update']['url'] && ( false !== strpos( $pluginInfo['update']['url'], 'wordpress.org/plugins' ) ) ) {
						$change_log = $pluginInfo['update']['url'];
						if ( substr( $change_log, - 1 ) != '/' ) {
							$change_log .= '/';
						}
						$change_log .= '#developers';
						if ( ! $text_format ) {
							$infoTxt    .= ' - <a href="' . $change_log . '" target="_blank">Changelog</a>';
							$infoNewTxt .= ' - <a href="' . $change_log . '" target="_blank">Changelog</a>';
						}
					}
					$newUpdate = ! ( isset( $websiteLastPlugins[ $pluginSlug ] ) && ( $pluginInfo['Version'] == $websiteLastPlugins[ $pluginSlug ]['Version'] ) && ( $pluginInfo['update']['new_version'] == $websiteLastPlugins[ $pluginSlug ]['update']['new_version'] ) );
					// update this..
					if ( in_array( $pluginSlug, $trustedPlugins ) ) {
						// Trusted
						if ( $newUpdate ) {
							$pluginsNewUpdate[] = array( $website->id, $infoNewTxt, $infoTrustedText );
						} else {
							$pluginsToUpdateNow[ $website->id ][] = $pluginSlug;
							$allWebsites[ $website->id ]          = $website;
							$pluginsToUpdate[]                    = array( $website->id, $infoTxt, $infoTrustedText );
						}
					} else {
						// Not trusted
						if ( $newUpdate ) {
							$notTrustedPluginsNewUpdate[] = array( $website->id, $infoNewTxt, $infoNotTrustedText );
						} else {
							$notTrustedPluginsToUpdate[] = array( $website->id, $infoTxt, $infoNotTrustedText );
						}
					}
				}

				// Run over every update we had last time..
				foreach ( $websiteThemes as $themeSlug => $themeInfo ) {
					if ( isset( $decodedIgnoredThemes[ $themeSlug ] ) || isset( $websiteDecodedIgnoredThemes[ $themeSlug ] ) ) {
						continue;
					}

					if ( $website->is_ignoreThemeUpdates ) {
						continue;
					}
					if ( $text_format ) {
						$infoTxt    = stripslashes( $website->name ) . ' - ' . $themeInfo['Name'] . ' ' . $themeInfo['Version'] . ' to ' . $themeInfo['update']['new_version'] . ' - ' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id );
						$infoNewTxt = '*NEW* ' . stripslashes( $website->name ) . ' - ' . $themeInfo['Name'] . ' ' . $themeInfo['Version'] . ' to ' . $themeInfo['update']['new_version'] . ' - ' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id );
					} else {
						$infoTxt    = '<a href="' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) . '">' . stripslashes( $website->name ) . '</a> - ' . $themeInfo['Name'] . ' ' . $themeInfo['Version'] . ' to ' . $themeInfo['update']['new_version'];
						$infoNewTxt = '*NEW* <a href="' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) . '">' . stripslashes( $website->name ) . '</a> - ' . $themeInfo['Name'] . ' ' . $themeInfo['Version'] . ' to ' . $themeInfo['update']['new_version'];
					}

					$newUpdate = ! ( isset( $websiteLastThemes[ $themeSlug ] ) && ( $themeInfo['Version'] == $websiteLastThemes[ $themeSlug ]['Version'] ) && ( $themeInfo['update']['new_version'] == $websiteLastThemes[ $themeSlug ]['update']['new_version'] ) );
					// update this..
					if ( in_array( $themeSlug, $trustedThemes ) ) {
						// Trusted
						if ( $newUpdate ) {
							$themesNewUpdate[] = array( $website->id, $infoNewTxt, $infoTrustedText );
						} else {
							$themesToUpdateNow[ $website->id ][] = $themeSlug;
							$allWebsites[ $website->id ]         = $website;
							$themesToUpdate[]                    = array( $website->id, $infoTxt, $infoTrustedText );
						}
					} else {
						// Not trusted
						if ( $newUpdate ) {
							$notTrustedThemesNewUpdate[] = array( $website->id, $infoNewTxt, $infoNotTrustedText );
						} else {
							$notTrustedThemesToUpdate[] = array( $website->id, $infoTxt, $infoNotTrustedText );
						}
					}
				}

				do_action( 'mainwp_daily_digest_action', $website, $text_format );

				// Loop over last plugins & current plugins, check if we need to update them.
				$user  = get_userdata( $website->userid );
				$email = MainWP_Utility::get_notification_email( $user );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_email', $email );
				MainWP_DB::instance()->update_website_sync_values( $website->id, array( 'dtsAutomaticSync' => time() ) );
				MainWP_DB::instance()->update_website_option( $website, 'last_wp_upgrades', wp_json_encode( $websiteCoreUpgrades ) );
				MainWP_DB::instance()->update_website_option( $website, 'last_plugin_upgrades', $website->plugin_upgrades );
				MainWP_DB::instance()->update_website_option( $website, 'last_theme_upgrades', $website->theme_upgrades );

				// sync site favico one time per day
				$updatescheckSitesIcon = get_option( 'mainwp_updatescheck_sites_icon' );
				if ( ! is_array( $updatescheckSitesIcon ) ) {
					$updatescheckSitesIcon = array();
				}
				if ( ! in_array( $website->id, $updatescheckSitesIcon ) ) {
					self::sync_site_icon( $website->id );
					$updatescheckSitesIcon[] = $website->id;
					MainWP_Utility::update_option( 'mainwp_updatescheck_sites_icon', $updatescheckSitesIcon );
				}
			}

			if ( count( $coreNewUpdate ) != 0 ) {
				$coreNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_core_new' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_core_new', MainWP_Utility::array_merge( $coreNewUpdateSaved, $coreNewUpdate ) );
			}

			if ( count( $pluginsNewUpdate ) != 0 ) {
				$pluginsNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_plugins_new' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_plugins_new', MainWP_Utility::array_merge( $pluginsNewUpdateSaved, $pluginsNewUpdate ) );
			}

			if ( count( $themesNewUpdate ) != 0 ) {
				$themesNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_themes_new' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_themes_new', MainWP_Utility::array_merge( $themesNewUpdateSaved, $themesNewUpdate ) );
			}

			if ( count( $coreToUpdate ) != 0 ) {
				$coreToUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_core' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_core', MainWP_Utility::array_merge( $coreToUpdateSaved, $coreToUpdate ) );
			}

			if ( count( $pluginsToUpdate ) != 0 ) {
				$pluginsToUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_plugins' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_plugins', MainWP_Utility::array_merge( $pluginsToUpdateSaved, $pluginsToUpdate ) );
			}

			if ( count( $themesToUpdate ) != 0 ) {
				$themesToUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_themes' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_themes', MainWP_Utility::array_merge( $themesToUpdateSaved, $themesToUpdate ) );
			}

			if ( count( $ignoredCoreToUpdate ) != 0 ) {
				$ignoredCoreToUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_core' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_core', MainWP_Utility::array_merge( $ignoredCoreToUpdateSaved, $ignoredCoreToUpdate ) );
			}

			if ( count( $ignoredCoreNewUpdate ) != 0 ) {
				$ignoredCoreNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_core_new' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_core_new', MainWP_Utility::array_merge( $ignoredCoreNewUpdateSaved, $ignoredCoreNewUpdate ) );
			}

			if ( count( $notTrustedPluginsToUpdate ) != 0 ) {
				$notTrustedPluginsToUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_plugins' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_plugins', MainWP_Utility::array_merge( $notTrustedPluginsToUpdateSaved, $notTrustedPluginsToUpdate ) );
			}

			if ( count( $notTrustedPluginsNewUpdate ) != 0 ) {
				$notTrustedPluginsNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_plugins_new' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_plugins_new', MainWP_Utility::array_merge( $notTrustedPluginsNewUpdateSaved, $notTrustedPluginsNewUpdate ) );
			}

			if ( count( $notTrustedThemesToUpdate ) != 0 ) {
				$notTrustedThemesToUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_themes' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_themes', MainWP_Utility::array_merge( $notTrustedThemesToUpdateSaved, $notTrustedThemesToUpdate ) );
			}

			if ( count( $notTrustedThemesNewUpdate ) != 0 ) {
				$notTrustedThemesNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_themes_new' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_themes_new', MainWP_Utility::array_merge( $notTrustedThemesNewUpdateSaved, $notTrustedThemesNewUpdate ) );
			}

			if ( ( count( $coreToUpdate ) == 0 ) && ( count( $pluginsToUpdate ) == 0 ) && ( count( $themesToUpdate ) == 0 ) && ( count( $ignoredCoreToUpdate ) == 0 ) && ( count( $ignoredCoreNewUpdate ) == 0 ) && ( count( $notTrustedPluginsToUpdate ) == 0 ) && ( count( $notTrustedPluginsNewUpdate ) == 0 ) && ( count( $notTrustedThemesToUpdate ) == 0 ) && ( count( $notTrustedThemesNewUpdate ) == 0 )
			) {
				return;
			}

			if ( 1 !== $mainwpAutomaticDailyUpdate && 1 !== $plugin_automaticDailyUpdat && 1 !== $theme_automaticDailyUpdate ) {
				return;
			}

			// Check if backups are required!
			if ( get_option( 'mainwp_enableLegacyBackupFeature' ) && get_option( 'mainwp_backup_before_upgrade' ) == 1 ) {
				$sitesCheckCompleted = get_option( 'mainwp_automaticUpdate_backupChecks' );
				if ( ! is_array( $sitesCheckCompleted ) ) {
					$sitesCheckCompleted = array();
				}

				$websitesToCheck = array();

				if ( 1 === $plugin_automaticDailyUpdate ) {
					foreach ( $pluginsToUpdateNow as $websiteId => $slugs ) {
						$websitesToCheck[ $websiteId ] = true;
					}
				}

				if ( 1 === $theme_automaticDailyUpdate ) {
					foreach ( $themesToUpdateNow as $websiteId => $slugs ) {
						$websitesToCheck[ $websiteId ] = true;
					}
				}

				if ( 1 === $mainwpAutomaticDailyUpdate ) {
					foreach ( $coreToUpdateNow as $websiteId ) {
						$websitesToCheck[ $websiteId ] = true;
					}
				}

				foreach ( $websitesToCheck as $siteId => $bool ) {
					if ( 0 === $allWebsites[ $siteId ]->backup_before_upgrade ) {
						$sitesCheckCompleted[ $siteId ] = true;
					}
					if ( isset( $sitesCheckCompleted[ $siteId ] ) ) {
						continue;
					}

					$dir = MainWP_Utility::get_mainwp_specific_dir( $siteId );
					$dh  = opendir( $dir );
					// Check if backup ok
					$lastBackup = - 1;
					if ( file_exists( $dir ) && $dh ) {
						while ( ( $file = readdir( $dh ) ) !== false ) {
							if ( '.' !== $file && '..' !== $file ) {
								$theFile = $dir . $file;
								if ( MainWP_Utility::is_archive( $file ) && ! MainWP_Utility::is_sql_archive( $file ) && ( filemtime( $theFile ) > $lastBackup ) ) {
									$lastBackup = filemtime( $theFile );
								}
							}
						}
						closedir( $dh );
					}

					$mainwp_backup_before_upgrade_days = get_option( 'mainwp_backup_before_upgrade_days' );
					if ( empty( $mainwp_backup_before_upgrade_days ) || ! ctype_digit( $mainwp_backup_before_upgrade_days ) ) {
						$mainwp_backup_before_upgrade_days = 7;
					}

					$backupRequired = ( $lastBackup < ( time() - ( $mainwp_backup_before_upgrade_days * 24 * 60 * 60 ) ) ? true : false );

					if ( ! $backupRequired ) {
						$sitesCheckCompleted[ $siteId ] = true;
						MainWP_Utility::update_option( 'mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted );
						continue;
					}

					try {
						$result = MainWP_Manage_Sites::backup( $siteId, 'full', '', '', 0, 0, 0, 0 );
						MainWP_Manage_Sites::backup_download_file( $siteId, 'full', $result['url'], $result['local'] );
						$sitesCheckCompleted[ $siteId ] = true;
						MainWP_Utility::update_option( 'mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted );
					} catch ( Exception $e ) {
						$sitesCheckCompleted[ $siteId ] = false;
						MainWP_Utility::update_option( 'mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted );
					}
				}
			} else {
				$sitesCheckCompleted = null;
			}

			if ( 1 === $plugin_automaticDailyUpdate ) {
				// Update plugins
				foreach ( $pluginsToUpdateNow as $websiteId => $slugs ) {
					if ( ( null != $sitesCheckCompleted ) && ( false == $sitesCheckCompleted[ $websiteId ] ) ) {
						continue;
					}
					MainWP_Logger::instance()->info_update( 'CRON :: auto update :: websites id :: ' . $websiteId . ' :: plugins :: ' . implode( ',', $slugs ) );

					try {
						MainWP_Utility::fetch_url_authed(
							$allWebsites[ $websiteId ], 'upgradeplugintheme', array(
								'type'   => 'plugin',
								'list'   => urldecode( implode( ',', $slugs ) ),
							)
						);

						if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
							MainWP_Sync::syncInformationArray( $allWebsites[ $websiteId ], $information['sync'] );
						}
					} catch ( Exception $e ) {
						// ok
					}
				}
			} else {
				$pluginsToUpdateNow = array();
			}

			if ( 1 === $theme_automaticDailyUpdate ) {
				// Update themes
				foreach ( $themesToUpdateNow as $websiteId => $slugs ) {
					if ( ( null != $sitesCheckCompleted ) && ( false == $sitesCheckCompleted[ $websiteId ] ) ) {
						continue;
					}

					try {
						MainWP_Utility::fetch_url_authed(
							$allWebsites[ $websiteId ], 'upgradeplugintheme', array(
								'type'   => 'theme',
								'list'   => urldecode( implode( ',', $slugs ) ),
							)
						);

						if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
							MainWP_Sync::syncInformationArray( $allWebsites[ $websiteId ], $information['sync'] );
						}
					} catch ( Exception $e ) {
						// ok
					}
				}
			} else {
				$themesToUpdateNow = array();
			}

			if ( 1 === $mainwpAutomaticDailyUpdate ) {
				// Update core
				foreach ( $coreToUpdateNow as $websiteId ) {
					if ( ( null != $sitesCheckCompleted ) && ( false == $sitesCheckCompleted[ $websiteId ] ) ) {
						continue;
					}

					try {
						MainWP_Utility::fetch_url_authed( $allWebsites[ $websiteId ], 'upgrade' );
					} catch ( Exception $e ) {
						// ok
					}
				}
			} else {
				$coreToUpdateNow = array();
			}

			do_action( 'mainwp_cronupdatecheck_action', $pluginsNewUpdate, $pluginsToUpdate, $pluginsToUpdateNow, $themesNewUpdate, $themesToUpdate, $themesToUpdateNow, $coreNewUpdate, $coreToUpdate, $coreToUpdateNow );
		}
	}

	public static function sync_site_icon( $siteId = null ) {
		if ( MainWP_Utility::ctype_digit( $siteId ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $siteId );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$error = '';
				try {
					$information = MainWP_Utility::fetch_url_authed( $website, 'get_site_icon' );
				} catch ( MainWP_Exception $e ) {
					$error = $e->getMessage();
				}

				if ( '' != $error ) {
					return array( 'error' => $error );
				} elseif ( isset( $information['faviIconUrl'] ) && ! empty( $information['faviIconUrl'] ) ) {
					MainWP_Logger::instance()->debug( 'Downloading icon :: ' . $information['faviIconUrl'] );
					$content = MainWP_Utility::get_file_content( $information['faviIconUrl'] );
					if ( ! empty( $content ) ) {
						$dirs     = MainWP_Utility::get_mainwp_dir();
						$iconsDir = $dirs[0] . 'icons' . DIRECTORY_SEPARATOR;
						if ( ! @is_dir( $iconsDir ) ) {
							@mkdir( $iconsDir, 0777, true );
						}
						if ( ! file_exists( $iconsDir . 'index.php' ) ) {
							@touch( $iconsDir . 'index.php' );
						}
						$filename = basename( $information['faviIconUrl'] );
						$filename = strtok($filename, '?'); // to fix: remove params
						if ( $filename ) {
							$filename = 'favi-' . $siteId . '-' . $filename;
							$size     = file_put_contents( $iconsDir . $filename, $content );
							if ( $size ) {
								MainWP_Logger::instance()->debug( 'Icon size :: ' . $size );
								MainWP_DB::instance()->update_website_option( $website, 'favi_icon', $filename );
								return array( 'result' => 'success' );
							} else {
								return array( 'error' => 'Save icon file failed.' );
							}
						}
						return array( 'undefined_error' => true );
					} else {
						return array( 'error' => esc_html__( 'Download icon file failed', 'mainwp' ) );
					}
				} else {
					return array( 'undefined_error' => true );
				}
			}
		}
		return array( 'result' => 'NOSITE' );
	}

	public function mainwp_cronpingchilds_action() {
		MainWP_Logger::instance()->info( 'CRON :: ping childs' );

		$lastPing = get_option( 'mainwp_cron_last_ping' );
		if ( false !== $lastPing && ( time() - $lastPing ) < ( 60 * 60 * 23 ) ) {
			return;
		}
		MainWP_Utility::update_option( 'mainwp_cron_last_ping', time() );

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites() );
		while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
			try {
				$url = $website->siteurl;
				if ( ! MainWP_Utility::ends_with( $url, '/' ) ) {
					$url .= '/';
				}

				wp_remote_get( $url . 'wp-cron.php' );
			} catch ( Exception $e ) {
				// ok
			}
		}
		MainWP_DB::free_result( $websites );
	}

	public function mainwp_cronbackups_continue_action() {
		if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			return;
		}
		MainWP_Logger::instance()->info( 'CRON :: backups continue' );

		@ignore_user_abort( true );
		@set_time_limit( 0 );
		add_filter( 'admin_memory_limit', function() {
			return '512M';
		} );

		MainWP_Utility::update_option( 'mainwp_cron_last_backups_continue', time() );

		// Fetch all tasks where complete < last & last checkup is more then 1minute ago! & last is more then 1 minute ago!
		$tasks = MainWP_DB::instance()->get_backup_tasks_to_complete();

		MainWP_Logger::instance()->debug( 'CRON :: backups continue :: Found ' . count( $tasks ) . ' to continue.' );

		if ( empty( $tasks ) ) {
			return;
		}

		foreach ( $tasks as $task ) {
			MainWP_Logger::instance()->debug( 'CRON :: backups continue ::    Task: ' . $task->name );
		}

		foreach ( $tasks as $task ) {
			$task = MainWP_DB::instance()->get_backup_task_by_id( $task->id );
			if ( $task->completed < $task->last_run ) {
				MainWP_Manage_Backups::executeBackupTask( $task, 5, false );
				break;
			}
		}
	}

	public function mainwp_cronbackups_action() {
		if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			return;
		}

		MainWP_Logger::instance()->info( 'CRON :: backups' );

		@ignore_user_abort( true );
		@set_time_limit( 0 );
		add_filter( 'admin_memory_limit', function() {
			return '512M';
		} );

		MainWP_Utility::update_option( 'mainwp_cron_last_backups', time() );

		// Do cronjobs!
		// Config this in crontab: 0 0 * * * wget -q http://mainwp.com/wp-admin/?do=cron -O /dev/null 2>&1
		// this will execute once every day to check to do the scheduled backups
		$allTasks   = array();
		$dailyTasks = MainWP_DB::instance()->get_backup_tasks_todo_daily();
		if ( count( $dailyTasks ) > 0 ) {
			$allTasks = $dailyTasks;
		}
		$weeklyTasks = MainWP_DB::instance()->get_backup_tasks_todo_weekly();
		if ( count( $weeklyTasks ) > 0 ) {
			$allTasks = array_merge( $allTasks, $weeklyTasks );
		}
		$monthlyTasks = MainWP_DB::instance()->get_backup_tasks_todo_monthly();
		if ( count( $monthlyTasks ) > 0 ) {
			$allTasks = array_merge( $allTasks, $monthlyTasks );
		}

		MainWP_Logger::instance()->debug( 'CRON :: backups :: Found ' . count( $allTasks ) . ' to start.' );

		foreach ( $allTasks as $task ) {
			MainWP_Logger::instance()->debug( 'CRON :: backups ::    Task: ' . $task->name );
		}

		foreach ( $allTasks as $task ) {
			$threshold = 0;
			if ( 'daily' == $task->schedule ) {
				$threshold = ( 60 * 60 * 24 );
			} elseif ( 'weekly' == $task->schedule ) {
				$threshold = ( 60 * 60 * 24 * 7 );
			} elseif ( 'monthly' == $task->schedule ) {
				$threshold = ( 60 * 60 * 24 * 30 );
			}
			$task = MainWP_DB::instance()->get_backup_task_by_id( $task->id );
			if ( ( time() - $task->last_run ) < $threshold ) {
				continue;
			}

			if ( ! MainWP_Manage_Backups::validateBackupTasks( array( $task ) ) ) {
				$task = MainWP_DB::instance()->get_backup_task_by_id( $task->id );
			}

			$chunkedBackupTasks = get_option( 'mainwp_chunkedBackupTasks' );
			MainWP_Manage_Backups::executeBackupTask( $task, ( 0 !== $chunkedBackupTasks ? 5 : 0 ) );
		}
	}

	public function mainwp_cronstats_action() {
		MainWP_Logger::instance()->info( 'CRON :: stats' );

		MainWP_Utility::update_option( 'mainwp_cron_last_stats', time() );

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_websites_stats_update_sql() );

		$start = time();
		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			if ( ( $start - time() ) > ( 60 * 60 * 2 ) ) {
				// two hours passed, next cron will start!
				break;
			}

			MainWP_DB::instance()->update_website_stats( $website->id, time() );

			if ( property_exists( $website, 'sync_errors' ) && '' != $website->sync_errors ) {
				// Try reconnecting
				MainWP_Logger::instance()->infoForWebsite( $website, 'reconnect', 'Trying to reconnect' );
				try {
					if ( MainWP_Manage_Sites::_reconnect_site( $website ) ) {
						// Reconnected
						MainWP_Logger::instance()->infoForWebsite( $website, 'reconnect', 'Reconnected successfully' );
					}
				} catch ( Exception $e ) {
					// Still something wrong
					MainWP_Logger::instance()->warningForWebsite( $website, 'reconnect', $e->getMessage() );
				}
			}
			sleep( 3 );
		}
		MainWP_DB::free_result( $websites );
	}

	public function admin_footer() {

		$this->update_footer(true); // will hide #wpfooter

		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'PostBulkManage' ) ) {
			MainWP_Post::init_subpages_menu();
		}
		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'managesites' ) ) {
			MainWP_Manage_Sites::init_subpages_menu();
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'Settings' ) ) {
			MainWP_Settings::init_subpages_menu();
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'Extensions' ) ) {
			MainWP_Extensions::init_subpages_menu();
		}
		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'PageBulkManage' ) ) {
			MainWP_Page::init_subpages_menu();
		}
		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'ThemesManage' ) ) {
			MainWP_Themes::init_subpages_menu();
		}
		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'PluginsManage' ) ) {
			MainWP_Plugins::init_subpages_menu();
		}
		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'UserBulkManage' ) ) {
			MainWP_User::init_subpages_menu();
		}
		if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			if ( ! MainWP_Menu::is_disable_menu_item( 2, 'ManageBackups' ) ) {
				MainWP_Manage_Backups::init_subpages_menu();
			}
		}
		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'Settings' ) ) {
			MainWP_Settings::init_subpages_menu();
		}
		do_action( 'mainwp_admin_menu_sub' );
		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'ServerInformation' ) ) {
			MainWP_Server_Information::init_subpages_menu();
		}

		if ( self::is_mainwp_pages() ) {
			$disabled_confirm = get_option( 'mainwp_disable_update_confirmations', 0 );
			?>
			<input type="hidden" id="mainwp-disable-update-confirmations" value="<?php echo intval($disabled_confirm); ?>">

			<script type="text/javascript">
				jQuery( document ).ready( function ()
				{
					jQuery( '#adminmenu #collapse-menu' ).hide();
				} );
			</script>


			<?php
		}

		$hide_ref = apply_filters('mainwp_open_hide_referrer', false);
		if ( $hide_ref ) {
			?>
				<script type="text/javascript">
					jQuery(document).on('click', 'a.mainwp-may-hide-referrer', function(e) {
					  e.preventDefault();
					  mainwp_open_hide_referrer(e.target.href);
					});

					function mainwp_open_hide_referrer(url) {
					  var ran = Math.floor(Math.random() * 100) + 1;
					  var site = window.open("", "mainwp_hide_referrer_" + ran);
					  var meta = site.document.createElement('meta');
					  meta.name = "referrer";
					  meta.content = "no-referrer";
					  site.document.getElementsByTagName('head')[0].appendChild(meta);
					  site.document.open();
					  site.document.writeln('<script type="text/javascript">window.location = "' + url + '";<\/script>');
					  site.document.close();
					}
				</script>
			<?php
		}

		global $_mainwp_disable_menus_items;

		$_mainwp_disable_menus_items = apply_filters('mainwp_all_disablemenuitems', $_mainwp_disable_menus_items); // to support developer to debug
	}

	public function print_admin_styles( $value = true ) {
		if ( self::is_mainwp_pages() ) {
			return false;
		}
		return $value;
	}

	public function admin_print_styles() {
		?>
		<style>
			<?php
			if ( ! self::is_mainwp_pages() ) {
				?>
				html.wp-toolbar{
					padding-top: 32px !important; /* reset to default WP value */
				}
				<?php
			} else {
				?>
				#wpbody-content > div.update-nag,
				#wpbody-content > div.updated {
					margin-left: 190px;
				}
				<?php
			}
			?>
			.mainwp-checkbox:before {
				content: '<?php esc_html_e( 'YES', 'mainwp' ); ?>';
			}
			.mainwp-checkbox:after {
				content: '<?php esc_html_e( 'NO', 'mainwp' ); ?>';
			}
		</style>
		<?php
	}

	public static function is_mainwp_pages() {
		$screen = get_current_screen();
		if ( $screen && strpos( $screen->base, 'mainwp_' ) !== false && strpos( $screen->base, 'mainwp_child_tab' ) === false ) {
			return true;
		}

		return false;
	}

	public static function get_openssl_conf() {

		if ( defined( 'MAINWP_CRYPT_RSA_OPENSSL_CONFIG' ) ) {
			return MAINWP_CRYPT_RSA_OPENSSL_CONFIG;
		}

		$setup_conf_loc = '';
		if ( MainWP_Settings::isLocalWindowConfig() ) {
			$setup_conf_loc = get_option( 'mwp_setup_opensslLibLocation' );
		} elseif ( get_option( 'mainwp_opensslLibLocation' ) != '' ) {
			$setup_conf_loc = get_option( 'mainwp_opensslLibLocation' );
		}
		return $setup_conf_loc;
	}

	public function init() {

		global $_mainwp_disable_menus_items;

		// deprecate hook from 4.0
		$_mainwp_disable_menus_items = apply_filters( 'mainwp_disablemenuitems', $_mainwp_disable_menus_items );

		$_mainwp_disable_menus_items = apply_filters( 'mainwp_main_menu_disable_menu_items', $_mainwp_disable_menus_items );

		if ( ! function_exists( 'mainwp_current_user_can' ) ) {

			function mainwp_current_user_can( $cap_type = '', $cap ) {
				global $current_user;

				if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
					return true;
				}

				// To fix bug run from wp cli
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
		$chunksize = 1024; // how many bytes per chunk
		$handle    = @fopen( $filename, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		while ( ! @feof( $handle ) ) {
			$buffer = @fread( $handle, $chunksize );
			echo $buffer;
			ob_flush();
			flush();
			$buffer = null;
		}

		return @fclose( $handle );
	}

	public function parse_init() {
		if ( isset( $_GET['mwpdl'] ) && isset( $_GET['sig'] ) ) {
			$mwpDir = MainWP_Utility::get_mainwp_dir();
			$mwpDir = $mwpDir[0];
			$file   = trailingslashit( $mwpDir ) . rawurldecode( $_REQUEST['mwpdl'] );

			if ( stristr( rawurldecode( $_REQUEST['mwpdl'] ), '..' ) ) {
				return;
			}

			if ( file_exists( $file ) && md5( filesize( $file ) ) == $_GET['sig'] ) {
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

	public function login_form() {
		global $redirect_to;
		if ( ! isset( $_GET['redirect_to'] ) ) {
			$redirect_to = get_admin_url() . 'index.php';
		}
	}

	public function post_updated_messages( $messages ) {
		$messages['post'][98] = esc_html__( 'WordPress SEO values have been saved.', 'mainwp' );
		$messages['post'][99] = esc_html__( 'You have to select the sites you wish to publish to.', 'mainwp' );

		return $messages;
	}

	public function mainwp_warning_notice() {

		if ( get_option( 'mainwp_installation_warning_hide_the_notice' ) == 'yes' ) {
			return;
		}
		if ( MainWP_DB::instance()->get_websites_count() > 0 ) {
			return;
		} else {
			$plugins = get_plugins();
			if ( ! is_array($plugins) || count($plugins) <= 4 ) {
				return; // new install
			}
		}

		?>
		<div class="ui red icon message" style="margin-bottom: 0; border-radius: 0;">
			<i class="info circle icon"></i>
			<div class="content">
				<div class="header"><?php esc_html_e( 'This appears to be a production site', 'mainwp' ); ?></div>
				<?php esc_html_e( 'We HIGHLY recommend a NEW WordPress install for your MainWP Dashboard.', 'mainwp' ); ?> <?php printf( __( 'Using a new WordPress install will help to cut down on plugin conflicts and other issues that can be caused by trying to run your MainWP Dashboard off an active site. Most hosting companies provide free subdomains %s and we recommend creating one if you do not have a specific dedicated domain to run your MainWP Dashboard.', 'mainwp' ), '("<strong>demo.yourdomain.com</strong>")' ); ?>
				<br /><br />
				<a href="#" class="ui red mini button" id="remove-mainwp-installation-warning"><?php esc_html_e( 'I have read the warning and I want to proceed', 'mainwp' ); ?></a>
			</div>
		</div>
		<?php
	}


	public function activate_redirect( $location ) {
		$location = admin_url( 'admin.php?page=Extensions' );
		return $location;
	}

	public function activate_extention( $ext_key, $info = array() ) {

		add_filter( 'wp_redirect', array( $this, 'activate_redirect' ));

		if ( is_array( $info ) && isset( $info['product_id'] ) && isset( $info['software_version'] ) ) {
			$act_info = array(
				'product_id'       => $info['product_id'],
				'software_version' => $info['software_version'],
				'activated_key'    => 'Deactivated',
				'instance_id'      => MainWP_Api_Manager_Password_Management::generate_password( 12, false ),
			);
			MainWP_Api_Manager::instance()->set_activation_info($ext_key, $act_info);
		}
	}

	public function deactivate_extention( $ext_key ) {
		MainWP_Api_Manager::instance()->set_activation_info($ext_key, '');
	}

	public function admin_init() {
		if ( ! MainWP_Utility::is_admin() ) {
			return;
		}

		add_action('mainwp_activate_extention', array( $this, 'activate_extention' ), 10, 2 );
		add_action('mainwp_deactivate_extention', array( $this, 'deactivate_extention' ), 10, 1 );

		global $mainwpUseExternalPrimaryBackupsMethod;

		if ( null === $mainwpUseExternalPrimaryBackupsMethod ) {
			$mainwpUseExternalPrimaryBackupsMethod = apply_filters( 'mainwp-getprimarybackup-activated', '' );
		}

		add_action( 'mainwp_before_header', array( $this, 'mainwp_warning_notice' ) );
		MainWP_Post_Handler::instance()->init();
		$use_wp_datepicker = apply_filters( 'mainwp_ui_use_wp_calendar', false );
		// wp_enqueue_script( 'jquery-ui-tooltip' );
		// wp_enqueue_script( 'jquery-ui-autocomplete' );
		// wp_enqueue_script( 'jquery-ui-progressbar' );
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
			'installedBulkSettingsManager'       => MainWP_Extensions::isExtensionAvailable( 'mainwp-bulk-settings-manager' ) ? 1 : 0,
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
			// draggabilly grid layout library
			wp_enqueue_script( 'dragula', MAINWP_PLUGIN_URL . 'assets/js/dragula/dragula.min.js', array(), $this->current_version );
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

		// redirect on first install
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
		// if open /wp-admin/ path then check to redirect to mainwp overview
		if ( strpos( $_SERVER['REQUEST_URI'], '/wp-admin/' ) !== false && strpos( $_SERVER['REQUEST_URI'], '/wp-admin/' ) == $_pos ) {
			if ( mainwp_current_user_can( 'dashboard', 'access_global_dashboard' ) ) { // to fix
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

		if ( isset( $_POST['select_mainwp_options_siteview'] ) ) {
			$userExtension            = MainWP_DB::instance()->get_user_extension();
			$userExtension->site_view = ( empty( $_POST['select_mainwp_options_siteview'] ) ? MAINWP_VIEW_PER_PLUGIN_THEME : intval( $_POST['select_mainwp_options_siteview'] ) );
			MainWP_DB::instance()->update_user_extension( $userExtension );
		}

		if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) ) {
			if ( wp_verify_nonce( $_POST['wp_nonce'], 'Settings' ) ) {
				$updated  = MainWP_Settings::handle_settings_post();
				$updated |= MainWP_Manage_Sites::handle_settings_post();
				$msg      = '';
				if ( $updated ) {
					$msg = '&message=saved';
				}
				wp_safe_redirect( admin_url( 'admin.php?page=Settings' . $msg ) );
				exit();
			} elseif ( wp_verify_nonce( $_POST['wp_nonce'], 'PluginAutoUpdate' ) ) {
				$val = ( ! isset( $_POST['mainwp_pluginAutomaticDailyUpdate'] ) ? 0 : $_POST['mainwp_pluginAutomaticDailyUpdate'] );
				MainWP_Utility::update_option( 'mainwp_pluginAutomaticDailyUpdate', $val );
				wp_safe_redirect( admin_url( 'admin.php?page=PluginsAutoUpdate&message=saved' ) );
				exit();
			} elseif ( wp_verify_nonce( $_POST['wp_nonce'], 'ThemeAutoUpdate' ) ) {
				$val = ( ! isset( $_POST['mainwp_themeAutomaticDailyUpdate'] ) ? 0 : $_POST['mainwp_themeAutomaticDailyUpdate'] );
				MainWP_Utility::update_option( 'mainwp_themeAutomaticDailyUpdate', $val );
				wp_safe_redirect( admin_url( 'admin.php?page=ThemesAutoUpdate&message=saved' ) );
				exit();
			}
		}
	}

	public function handle_edit_bulkpost() {

		$post_id = 0;
		if ( isset( $_POST['post_ID'] ) ) {
			$post_id = (int) $_POST['post_ID'];
		}

		// verify this came from the our screen and with proper authorization.
		if ( $post_id && isset( $_POST['select_sites_nonce'] ) && wp_verify_nonce( $_POST['select_sites_nonce'], 'select_sites_' . $post_id ) ) {
			// see more 'editpost' in the file wp-admin/post.php
			check_admin_referer('update-post_' . $post_id);
			edit_post(); // WP core function

			$location = admin_url('admin.php?page=PostBulkEdit&post_id=' . $post_id . '&message=1');
			// to handle parameters
			// see more redirect_post() in the file wp-admin/includes/post.php
			$location = apply_filters( 'redirect_post_location', $location, $post_id );
			wp_safe_redirect($location);
			exit();
		}
	}

	public function redirect_edit_bulkpost( $location, $post_id ) {
		if ( $post_id ) {
			$location = admin_url('admin.php?page=PostBulkEdit&post_id=' . intval($post_id));
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

		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( isset( $_POST['mainwp_wpseo_metabox_save_values'] ) && ( ! empty( $_POST['mainwp_wpseo_metabox_save_values'] ) ) ) {
			return;
		}

		// Read extra metabox
		$pid = $this->metaboxes->select_sites_handle( $post_id, 'bulkpost' );
		$this->metaboxes->add_categories_handle( $post_id, 'bulkpost' );
		$this->metaboxes->add_tags_handle( $post_id, 'bulkpost' );
		$this->metaboxes->add_slug_handle( $post_id, 'bulkpost' );
		MainWP_Post::add_sticky_handle( $post_id );
		do_action( 'mainwp_save_bulkpost', $post_id );

		if ( $pid == $post_id ) {
			add_filter( 'redirect_post_location', array( $this, 'redirect_edit_bulkpost' ), 10, 2 );
		} else {
			// to support external redirect for example post dripper extension,
			// that will do not go to posting process
			do_action( 'mainwp_before_redirect_posting_bulkpost', $_post );
			// Redirect to handle page! (to actually post the messages)
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

		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( isset( $_POST['mainwp_wpseo_metabox_save_values'] ) && ( ! empty( $_POST['mainwp_wpseo_metabox_save_values'] ) ) ) {
			return;
		}

		// Read extra metabox
		$pid = $this->metaboxes->select_sites_handle( $post_id, 'bulkpage' );
		$this->metaboxes->add_slug_handle( $post_id, 'bulkpage' );
		MainWP_Page::add_status_handle( $post_id );

		do_action( 'mainwp_save_bulkpage', $post_id );

		if ( $pid == $post_id ) {
			// fixed by submitbox_misc_actions
			// $wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post_id ) );
			add_filter( 'redirect_post_location', array( $this, 'redirect_edit_bulkpage' ), 10, 2 );
		} else {
			do_action( 'mainwp_before_redirect_posting_bulkpage', $_post );
			// Redirect to handle page! (to actually post the messages)
			wp_safe_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPage&id=' . $post_id . '&hideall=1');
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
			// 'taxonomies' => array('category', 'post_tag', 'page-category'),
			'public'                 => true,
			'show_ui'                => true,
			// 'show_in_menu' => 'index.php',
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
			// 'taxonomies' => array('category', 'post_tag', 'page-category'),
			'public'                 => true,
			'show_ui'                => true,
			// 'show_in_menu' => 'index.php',
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
		// to fix issue start session for all requests
		if ( isset($_GET['page']) && in_array($_GET['page'], array(
			'PostBulkManage',
			'PageBulkManage',
			'PluginsManage',
			'PluginsAutoUpdate',
			'ThemesManage',
			'ThemesAutoUpdate',
			'UserBulkManage',
		)) ) {
			// start session
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
			wp_enqueue_script( 'semantic', MAINWP_PLUGIN_URL . 'assets/js/semantic-ui/semantic.min.js', array( 'jquery' ), $this->current_version, true );
			wp_enqueue_script( 'semantic-ui-datatables', MAINWP_PLUGIN_URL . 'assets/js/datatables/datatables.min.js', array( 'jquery' ), $this->current_version, true );
			wp_enqueue_script( 'semantic-ui-datatables-colreorder', MAINWP_PLUGIN_URL . 'assets/js/colreorder/dataTables.colReorder.js', array( 'jquery' ), $this->current_version, true );
			wp_enqueue_script( 'semantic-ui-datatables-scroller', MAINWP_PLUGIN_URL . 'assets/js/scroller/scroller.dataTables.js', array( 'jquery' ), $this->current_version, true );
			wp_enqueue_script( 'semantic-ui-datatables-fixedcolumns', MAINWP_PLUGIN_URL . 'assets/js/fixedcolumns/dataTables.fixedColumns.js', array( 'jquery' ), $this->current_version, true );
			wp_enqueue_script( 'semantic-ui-calendar', MAINWP_PLUGIN_URL . 'assets/js/calendar/calendar.min.js', array( 'jquery' ), $this->current_version, true );
			wp_enqueue_script( 'semantic-ui-hamburger', MAINWP_PLUGIN_URL . 'assets/js/hamburger/hamburger.js', array( 'jquery' ), $this->current_version, true );
		}

		if ( $load_cust_scripts ) {
			wp_enqueue_script( 'semantic', MAINWP_PLUGIN_URL . 'assets/js/semantic-ui/semantic.min.js', array( 'jquery' ), $this->current_version, true );
		}

		wp_enqueue_script( 'mainwp-ui', MAINWP_PLUGIN_URL . 'assets/js/mainwp-ui.js', array(), $this->current_version, true );
		wp_enqueue_script( 'mainwp-js-popup', MAINWP_PLUGIN_URL . 'assets/js/mainwp-popup.js', array(), $this->current_version, true );
		wp_enqueue_script( 'mainwp-fileuploader', MAINWP_PLUGIN_URL . 'assets/js/fileuploader.js', array(), $this->current_version ); // Load at header.
		wp_enqueue_script( 'mainwp-date', MAINWP_PLUGIN_URL . 'assets/js/date.js', array(), $this->current_version, true );
		wp_enqueue_script( 'mainwp-filesaver', MAINWP_PLUGIN_URL . 'assets/js/FileSaver.js', array(), $this->current_version, true );
		wp_enqueue_script( 'mainwp-jqueryfiletree', MAINWP_PLUGIN_URL . 'assets/js/jqueryFileTree.js', array(), $this->current_version, true );
	}

	public function admin_enqueue_styles( $hook ) {
		global $wp_version;
		wp_enqueue_style( 'mainwp', MAINWP_PLUGIN_URL . 'assets/css/mainwp.css', array(), $this->current_version );
		wp_enqueue_style( 'mainwp-responsive-layouts', MAINWP_PLUGIN_URL . 'assets/css/mainwp-responsive-layouts.css', array(), $this->current_version );

		// to faster a bit
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

	public function admin_head() {
		?>
		<script type="text/javascript">var mainwp_ajax_nonce = "<?php echo wp_create_nonce( 'mainwp_ajax' ); ?>"</script>		
		<?php
	}


	public function admin_body_class( $class_string ) {
		if ( self::is_mainwp_pages() ) {
			$class_string .= ' mainwp-ui mainwp-ui-page ';
			$class_string .= ' mainwp-ui-leftmenu ';     // to enable MainWP custom menu
		}
		return $class_string;
	}

	public function admin_menu() {
		global $menu;
		foreach ( $menu as $k => $item ) {
			if ( 'edit.php?post_type=bulkpost' === $item[2] ) { // Remove bulkpost
				unset( $menu[ $k ] );
			} elseif ( 'edit.php?post_type=bulkpage' === $item[2] ) { // Remove bulkpost
				unset( $menu[ $k ] );
			}
		}
	}

	public static function enqueue_postbox_scripts() {
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'wp-lists' );
		wp_enqueue_script( 'postbox' );
	}

	public function update_footer( $echo = false ) {
		if ( ! self::is_mainwp_pages() ) {
			return;
		}
		// avoid for better performance
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
				// for manage sites page
				if ( ( 'managesites' == $_GET['page'] ) && ! isset( $_GET['id'] ) && ! isset( $_GET['do'] ) && ! isset( $_GET['dashboard'] ) ) {
					$filter_group = get_option( 'mainwp_managesites_filter_group' );
					if ( $filter_group ) {
						$staging_group = get_option( 'mainwp_stagingsites_group_id' );
						if ( $staging_group == $filter_group ) {
							$is_staging = 'yes';
						}
					}
				} elseif ( 'UpdatesManage' == $_GET['page'] || 'mainwp_tab' == $_GET['page'] ) { // for Updates and Overview page
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

		ob_start();
		$this->render_footer_content( $websites );
		$newOutput = ob_get_clean();

		if ( true === $echo ) {
			echo $newOutput;
		} else {
			return $newOutput;
		}
	}

	public function render_footer_content( $websites ) {

		$cntr = 0;
		if ( is_array( $websites ) ) {
			$count = count( $websites );
			for ( $i = 0; $i < $count; $i ++ ) {
				$website = $websites[ $i ];
				if ( '' == $website->sync_errors ) {
					$cntr ++;
					echo '<input type="hidden" name="dashboard_wp_ids[]" class="dashboard_wp_id" value="' . intval($website->id) . '" />';
				}
			}
		} elseif ( false !== $websites ) {
			while ( $website = MainWP_DB::fetch_object( $websites ) ) {
				if ( '' == $website->sync_errors ) {
					$cntr ++;
					echo '<input type="hidden" name="dashboard_wp_ids[]" class="dashboard_wp_id" value="' . intval($website->id) . '" />';
				}
			}
		}

		// to support processes at mainwp footer
		do_action('mainwp_admin_footer');
		?>
		<div class="ui longer modal" id="mainwp-sync-sites-modal">
			<div class="header"><?php esc_html_e( 'Data Synchronization', 'mainwp' ); ?></div>
			<div class="ui green progress mainwp-modal-progress">
				<div class="bar"><div class="progress"></div></div>
				<div class="label"></div>
			</div>
			<div class="scrolling content mainwp-modal-content">
				<div class="ui middle aligned divided selection list" id="sync-sites-status">
					<?php
					if ( is_array( $websites ) ) {
						$count = count( $websites );
						for ( $i = 0; $i < $count; $i ++ ) {
							$nice_url = MainWP_Utility::get_nice_url( $website->url );
							$website  = $websites[ $i ];
							if ( '' == $website->sync_errors ) {
								?>
								<div class="item">
								<div class="right floated content">
								  <div class="sync-site-status" niceurl="<?php echo esc_html( $nice_url ); ?>" siteid="<?php echo intval( $website->id ); ?>"><i class="clock outline icon"></i></div>
								</div>
								<div class="content"><?php echo esc_html( $nice_url ); ?></div>
								</div>
								<?php
							} else {
								?>
								<div class="item disconnected-site">
								<div class="right floated content">
								  <div class="sync-site-status" niceurl="<?php echo esc_html( $nice_url ); ?>" siteid="<?php echo intval( $website->id ); ?>"><i class="exclamation red icon"></i></div>
								</div>
								<div class="content"><?php echo esc_html( $nice_url ); ?></div>
								</div>
								<?php
							}
						}
					} else {
						MainWP_DB::data_seek( $websites, 0 );
						while ( $website = MainWP_DB::fetch_object( $websites ) ) {
							$nice_url = MainWP_Utility::get_nice_url( $website->url );
							if ( '' === $website->sync_errors ) {
								?>
								<div class="item">
								<div class="right floated content">
								  <div class="sync-site-status" niceurl="<?php echo esc_html( $nice_url ); ?>" siteid="<?php echo intval( $website->id ); ?>"><i class="clock outline icon"></i></div>
								</div>
								<div class="content"><?php echo esc_html( $nice_url ); ?></div>
								</div>
								<?php
							} else {
								?>
								<div class="item disconnected-site">
								<div class="right floated content">
								  <div class="sync-site-status" niceurl="<?php echo esc_html( $nice_url ); ?>" siteid="<?php echo intval( $website->id ); ?>"><i class="exclamation red icon"></i></div>
								</div>
								<div class="content"><?php echo esc_html( $nice_url ); ?></div>
								</div>
								<?php
							}
						}
					}
					?>
				</div>
			</div>
			<div class="actions mainwp-modal-actions">
				<div class="mainwp-modal-close ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
			</div>
		</div>

		<div class="ui tiny modal" id="mainwp-modal-confirm">
			<div class="header"><?php esc_html_e( 'Confirmation', 'mainwp' ); ?></div>
			<div class="content">
				<div class="content-massage"></div>
				<div class="ui mini yellow message hidden update-confirm-notice" ><?php printf( __( 'To disable update confirmations, go to the %1$sSettings%2$s page and disable the "Disable update confirmations" option', 'mainwp' ), '<a href="admin.php?page=Settings">', '</a>' ); ?></div>
			</div>
			<div class="actions">
				<div class="ui cancel button"><?php esc_html_e('Cancel', 'mainwp'); ?></div>
				<div class="ui positive right labeled icon button"><?php esc_html_e('Yes', 'mainwp'); ?><i class="checkmark icon"></i></div>
			</div>
		</div>
		<?php
	}

	public function new_menus() {
		if ( MainWP_Utility::is_admin() ) {
			// Adding the page to manage your added sites/groups
			// The first page which will display the post area etc..
			if ( ! MainWP_Menu::is_disable_menu_item( 2, 'UpdatesManage' ) ) {
				MainWP_Updates::init_menu();
			}
			if ( ! MainWP_Menu::is_disable_menu_item( 2, 'managesites' ) ) {
				MainWP_Manage_Sites::init_menu();
			}
			if ( ! MainWP_Menu::is_disable_menu_item( 2, 'PostBulkManage' ) ) {
				MainWP_Post::init_menu();
			}
			if ( ! MainWP_Menu::is_disable_menu_item( 2, 'PageBulkManage' ) ) {
				MainWP_Page::init_menu();
			}
			if ( ! MainWP_Menu::is_disable_menu_item( 2, 'ThemesManage' ) ) {
				MainWP_Themes::init_menu();
			}
			if ( ! MainWP_Menu::is_disable_menu_item( 2, 'PluginsManage' ) ) {
				MainWP_Plugins::init_menu();
			}
			if ( ! MainWP_Menu::is_disable_menu_item( 2, 'UserBulkManage' ) ) {
				MainWP_User::init_menu();
			}
			if ( ! MainWP_Menu::is_disable_menu_item( 2, 'ManageBackups' ) ) {
				MainWP_Manage_Backups::init_menu();
			}
			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'UpdateAdminPasswords' ) ) {
				MainWP_Bulk_Update_Admin_Passwords::init_menu();
			}
			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageGroups' ) ) {
				MainWP_Manage_Groups::init_menu();
			}
			if ( ! MainWP_Menu::is_disable_menu_item( 2, 'Settings' ) ) {
				MainWP_Settings::init_menu();
			}
			MainWP_Extensions::init_menu(); // check disable menu item in the function
			do_action( 'mainwp_admin_menu' );

			if ( ! MainWP_Menu::is_disable_menu_item( 2, 'ServerInformation' ) ) {
				MainWP_Server_Information::init_menu();
			}

			MainWP_About::init_menu();
			MainWP_Child_Scan::init_menu();
		}
	}

	// On activation install the database
	public function activation() {
		// delete_option( 'mainwp_requests' );
		MainWP_DB::instance()->update();
		MainWP_DB::instance()->install();

		// Redirect to settings page
		MainWP_Utility::update_option( 'mainwp_activated', 'yes' );
	}

	public function deactivation() {
		update_option('mainwp_extensions_all_activation_cached', ''); // clear cached of all activations to reload for next loading
	}

	// On update update the database
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

			if ( ! MainWP_Extensions::hookVerify( $output[ $i ]['plugin'], $output[ $i ]['key'] ) ) {
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
