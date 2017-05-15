<?php
if ( session_id() == '' ) {
	session_start();
}
//ini_set('display_errors', true);
//error_reporting(E_ALL | E_STRICT);

@ini_set( 'display_errors', false );
@error_reporting( 0 );
define( 'MAINWP_API_VALID', 'VALID' );
define( 'MAINWP_API_INVALID', 'INVALID' );

define( 'MAINWP_TWITTER_MAX_SECONDS', 60 * 5 ); // seconds

class MainWP_System {
	public static $version = '3.4.1';
	//Singleton
	private static $instance = null;

	private $upgradeVersionInfo;

	/** @var $posthandler MainWP_DB */
	public $posthandler;
	public $metaboxes;

	/**
	 * The plugin current version
	 * @var string
	 */
	private $current_version = null;

	/**
	 * Plugin Slug (plugin_directory/plugin_file.php)
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Plugin name (plugin_file)
	 * @var string
	 */
	public $slug;

	/**
	 * @static
	 * @return MainWP_System
	 */
	static function Instance() {
		return self::$instance;
	}

	public function __construct( $mainwp_plugin_file ) {
		MainWP_System::$instance = $this;
		$this->load_all_options();
		$this->update();
		$this->plugin_slug = plugin_basename( $mainwp_plugin_file );
		list ( $t1, $t2 ) = explode( '/', $this->plugin_slug );
		$this->slug = str_replace( '.php', '', $t2 );

		if ( is_admin() ) {
			include_once( ABSPATH . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'plugin.php' ); //Version information from wordpress
			$pluginData            = get_plugin_data( $mainwp_plugin_file );
			$this->current_version = $pluginData['Version'];
			$currentVersion        = get_option( 'mainwp_plugin_version' );

			if ( empty( $currentVersion ) ) {
				MainWP_Utility::update_option( 'mainwp_getting_started', 'started' );
			} else if ( version_compare( $currentVersion, $this->current_version, '<' ) ) {
				update_option( 'mainwp_reset_user_tips', array() );
				MainWP_Utility::update_option( 'mainwp_reset_user_cookies', array() );
				MainWP_Utility::update_option( 'mainwp_getting_started', 'whatnew' );

			} else {
				delete_option('mainwp_getting_started');
			}

			MainWP_Utility::update_option( 'mainwp_plugin_version', $this->current_version );
		}

		if ( !defined( 'MAINWP_VERSION' ) ) {
			define( 'MAINWP_VERSION', $this->current_version );
		}

		if ( ( get_option( 'mainwp_upgradeVersionInfo' ) != '' ) && ( get_option( 'mainwp_upgradeVersionInfo' ) != null ) ) {
			$this->upgradeVersionInfo = unserialize( get_option( 'mainwp_upgradeVersionInfo' ) );
		} else {
			$this->upgradeVersionInfo = null;
		}


		$ssl_api_verifyhost = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) ) ? 1 : 0;
		if ( $ssl_api_verifyhost == 0 ) {
			add_filter( 'http_request_args', array(
				MainWP_Extensions::getClassName(),
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

		MainWP_Main::get(); //init main dashboard

		MainWP_Manage_Sites::init();
		new MainWP_Hooks(); //Init the hooks

		//Change menu & widgets
		add_action( 'admin_menu', array( &$this, 'new_menus' ) );

		//Change footer
		add_filter( 'update_footer', array( &$this, 'update_footer' ), 15 );

		//Add js
		add_action( 'admin_head', array( &$this, 'admin_head' ) );
		add_action( 'in_admin_header', array( &$this, 'in_admin_head' ));
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue_scripts' ) );

		//Add body class
		add_action( 'admin_body_class', array( &$this, 'admin_body_class' ) );

		//Handle the bulkpost
		add_action( 'publish_bulkpost', array( &$this, 'publish_bulkpost' ) );
		add_action( 'save_post', array( &$this, 'save_bulkpost' ) );
		add_action( 'save_post', array( &$this, 'save_bulkpage' ) );
		add_action( 'add_meta_boxes_bulkpost', array( 'MainWP_Post', 'addStickyOption' ) );

		//Handle the bulkpage
		add_action( 'publish_bulkpage', array( &$this, 'publish_bulkpage' ) );
        add_action( 'add_meta_boxes_bulkpage', array( 'MainWP_Page', 'modify_bulkpage_metabox' ) );

		//Add meta boxes for the bulkpost
		add_action( 'admin_init', array( &$this, 'admin_init' ) );

		//Create the post types for bulkpost/...
		add_action( 'init', array( &$this, 'create_post_type' ) );
		add_action( 'init', array( &$this, 'parse_init' ) );
		add_action( 'init', array( &$this, 'init' ), 9999 );

		add_action( 'admin_init', array( $this, 'admin_redirects' ) );

		//Remove the pages from the menu which I use in AJAX
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		add_action( 'admin_menu', array( &$this, 'remove_wp_menus' ) );

		//Add custom error messages
		add_filter( 'post_updated_messages', array( &$this, 'post_updated_messages' ) );

		add_action( 'login_form', array( &$this, 'login_form' ) );

		add_action( 'admin_print_styles', array( &$this, 'admin_print_styles' ) );

		add_filter( 'admin_footer', array( $this, 'admin_footer' ) );

		MainWP_Install_Bulk::init();

		do_action( 'mainwp_cronload_action' );

		//Cron every 5 minutes
		add_action( 'mainwp_cronstats_action', array( $this, 'mainwp_cronstats_action' ) );
		add_action( 'mainwp_cronbackups_action', array( $this, 'mainwp_cronbackups_action' ) );
		add_action( 'mainwp_cronbackups_continue_action', array( $this, 'mainwp_cronbackups_continue_action' ) );
		add_action( 'mainwp_cronupdatescheck_action', array( $this, 'mainwp_cronupdatescheck_action' ) );
		add_action( 'mainwp_cronpingchilds_action', array( $this, 'mainwp_cronpingchilds_action' ) );

		add_filter( 'cron_schedules', array( 'MainWP_Utility', 'getCronSchedules' ) );

		$useWPCron = ( get_option( 'mainwp_wp_cron' ) === false ) || ( get_option( 'mainwp_wp_cron' ) == 1 );

		//todo: remove in next version
		if ( ( $sched = wp_next_scheduled( 'mainwp_cronofflinecheck_action' ) ) != false ) {
			wp_unschedule_event( $sched, 'mainwp_cronofflinecheck_action' );
		}

		//todo: remove in next version
		if ( ( $sched = wp_next_scheduled( 'mainwp_cron_last_cronconflicts' ) ) != false ) {
			wp_unschedule_event( $sched, 'mainwp_cron_last_cronconflicts' );
		}

		if ( ( $sched = wp_next_scheduled( 'mainwp_cronstats_action' ) ) == false ) {
			if ( $useWPCron ) {
				wp_schedule_event( time(), 'hourly', 'mainwp_cronstats_action' );
			}
		} else {
			if ( ! $useWPCron ) {
				wp_unschedule_event( $sched, 'mainwp_cronstats_action' );
			}
		}

		if (get_option('mainwp_enableLegacyBackupFeature')) {
			if ( ( $sched = wp_next_scheduled( 'mainwp_cronbackups_action' ) ) == false ) {
				if ( $useWPCron ) {
					wp_schedule_event( time(), 'hourly', 'mainwp_cronbackups_action' );
				}
			} else {
				if ( ! $useWPCron ) {
					wp_unschedule_event( $sched, 'mainwp_cronbackups_action' );
				}
			}

			if ( ( $sched = wp_next_scheduled( 'mainwp_cronbackups_continue_action' ) ) == false ) {
				if ( $useWPCron ) {
					wp_schedule_event( time(), '5minutely', 'mainwp_cronbackups_continue_action' );
				}
			} else {
				if ( ! $useWPCron ) {
					wp_unschedule_event( $sched, 'mainwp_cronbackups_continue_action' );
				}
			}
		} else {
            if ( $sched = wp_next_scheduled( 'mainwp_cronbackups_action' ) ) {
                wp_unschedule_event( $sched, 'mainwp_cronbackups_action' );
			}
            if ( $sched = wp_next_scheduled( 'mainwp_cronbackups_continue_action' ) ) {
                wp_unschedule_event( $sched, 'mainwp_cronbackups_continue_action' );
			}
        }

		if ( ( $sched = wp_next_scheduled( 'mainwp_cronremotedestinationcheck_action' ) ) != false ) {
			wp_unschedule_event( $sched, 'mainwp_cronremotedestinationcheck_action' );
		}

		if ( ( $sched = wp_next_scheduled( 'mainwp_cronpingchilds_action' ) ) == false ) {
			if ( $useWPCron ) {
				wp_schedule_event( time(), 'daily', 'mainwp_cronpingchilds_action' );
			}
		} else {
			if ( ! $useWPCron ) {
				wp_unschedule_event( $sched, 'mainwp_cronpingchilds_action' );
			}
		}

		if ( ( $sched = wp_next_scheduled( 'mainwp_cronupdatescheck_action' ) ) == false ) {
			if ( $useWPCron ) {
				wp_schedule_event( time(), 'minutely', 'mainwp_cronupdatescheck_action' );
			}
		} else {
			if ( ! $useWPCron ) {
				wp_unschedule_event( $sched, 'mainwp_cronupdatescheck_action' );
			}
		}

		add_action( 'admin_notices', array( &$this, 'admin_notices' ) );
        // to fix layout
        if ( get_option( 'mainwp_disable_wp_main_menu', 1 ) ) {
            if (isset($_GET['page']) && $_GET['page'] != 'mainwp_about') {
                add_action( 'admin_notices', array( &$this, 'admin_notices_start' ), 1 );
                add_action( 'admin_notices', array( &$this, 'admin_notices_end' ), 9999 );
                add_action( 'user_admin_notices', array( &$this, 'admin_notices_start' ), 1 );
                add_action( 'user_admin_notices', array( &$this, 'admin_notices_end' ), 9999 );
                add_action( 'all_admin_notices', array( &$this, 'admin_notices_start' ), 1 );
                add_action( 'all_admin_notices', array( &$this, 'admin_notices_end' ), 9999 );
            }
        }

		add_action( 'after_plugin_row', array( &$this, 'after_extensions_plugin_row' ), 10, 3 );

		add_filter( 'mainwp-activated-check', array( &$this, 'activated_check' ) );
		add_filter( 'mainwp-activated-sub-check', array( &$this, 'activated_sub_check' ) );
		add_filter( 'mainwp-extension-enabled-check', array( MainWP_Extensions::getClassName(), 'isExtensionEnabled' ) );

		/**
		 * This hook allows you to get a list of sites via the 'mainwp-getsites' filter.
		 * @link http://codex.mainwp.com/#mainwp-getsites
		 *
		 * @see \MainWP_Extensions::hookGetSites
		 */
		add_filter( 'mainwp-getsites', array( MainWP_Extensions::getClassName(), 'hookGetSites' ), 10, 4 );
		add_filter( 'mainwp-getdbsites', array( MainWP_Extensions::getClassName(), 'hookGetDBSites' ), 10, 5 );

		/**
		 * This hook allows you to get a information about groups via the 'mainwp-getgroups' filter.
		 * @link http://codex.mainwp.com/#mainwp-getgroups
		 *
		 * @see \MainWP_Extensions::hookGetGroups
		 */
		add_filter( 'mainwp-getgroups', array( MainWP_Extensions::getClassName(), 'hookGetGroups' ), 10, 4 );
		add_action( 'mainwp_fetchurlsauthed', array( &$this, 'filter_fetchUrlsAuthed' ), 10, 7 );
		add_filter( 'mainwp_fetchurlauthed', array( &$this, 'filter_fetchUrlAuthed' ), 10, 5 );
		add_filter( 'mainwp_getdashboardsites', array(
			MainWP_Extensions::getClassName(),
			'hookGetDashboardSites',
		), 10, 7 );
		add_filter( 'mainwp-manager-getextensions', array(
			MainWP_Extensions::getClassName(),
			'hookManagerGetExtensions',
		) );
		add_action( 'mainwp_bulkpost_metabox_handle', array( $this, 'hookBulkPostMetaboxHandle' ) );
		add_action( 'mainwp_bulkpage_metabox_handle', array( $this, 'hookBulkPageMetaboxHandle' ) );

		$this->posthandler = new MainWP_Post_Handler();

		do_action( 'mainwp-activated' );


		MainWP_Updates::init();
		MainWP_Post::init();
		MainWP_Settings::init();
		if (get_option('mainwp_enableLegacyBackupFeature')) {
			MainWP_Manage_Backups::init();
		}
		MainWP_User::init();
		MainWP_Page::init();
		MainWP_Themes::init();
		MainWP_Plugins::init();
		MainWP_Right_Now::init();
		MainWP_Setup_Wizard::init();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			MainWP_WP_CLI_Command::init();
		}
		//WP-Cron
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			if ( isset($_GET[ 'mainwp_run' ]) && ! empty( $_GET[ 'mainwp_run' ] ) ) {
				add_action( 'init', array( $this, 'cron_active' ), PHP_INT_MAX );
			}
		}

	}

	function load_all_options() {
		global $wpdb;

		if ( !defined( 'WP_INSTALLING' ) || !is_multisite() )
			$alloptions = wp_cache_get( 'alloptions', 'options' );
		else
			$alloptions = false;

		if ( !defined( 'WP_INSTALLING' ) || !is_multisite() )
			$notoptions = wp_cache_get( 'notoptions', 'options' );
		else
			$notoptions = false;

		if ( !isset($alloptions['mainwp_db_version']) ) {
			$suppress = $wpdb->suppress_errors();
			$options = array('mainwp_db_version', 'mainwp_plugin_version', 'mainwp_upgradeVersionInfo', 'mainwp_extensions', 'mainwp_manager_extensions','mainwp_getting_started',
				'_transient__mainwp_activation_redirect',
				'_transient_timeout__mainwp_activation_redirect',
				'mainwp_activated',
				'mainwp_api_sslVerifyCertificate',
				'mainwp_automaticDailyUpdate',
				'mainwp_backup_before_upgrade',
				'mainwp_backupwordpress_ext_enabled',
				'mainwp_backwpup_ext_enabled',
				'mainwp_branding_button_contact_label',
				'mainwp_branding_child_hide',
				'mainwp_branding_ext_enabled',
				'mainwp_branding_extra_settings',
				'mainwp_branding_plugin_header',
				'mainwp_branding_remove_permalink',
				'mainwp_branding_remove_setting',
				'mainwp_branding_remove_wp_setting',
				'mainwp_branding_remove_wp_tools',
				'mainwp_creport_ext_branding_enabled',
				'mainwp_enableLegacyBackupFeature',
				'mainwp_ext_snippets_enabled',
				'mainwp_hide_footer',
				'mainwp_hide_twitters_message',
				'mainwp_ithemes_ext_enabled',
				'mainwp_keyword_links_htaccess_set',
				'mainwp_linkschecker_ext_enabled',
				'mainwp_maximumInstallUpdateRequests',
				'mainwp_maximumSyncRequests',
				'mainwp_pagespeed_ext_enabled',
				'mainwp_primaryBackup',
				'mainwp_refresh',
				'mainwp_security',
				'mainwp_updraftplus_ext_enabled',
				'mainwp_use_favicon',
				'mainwp_wordfence_ext_enabled',
				'mainwp_wp_cron',
				'mainwp_wpcreport_extension',
				'mainwp_wprocket_ext_enabled',
				'mainwp_hide_tips');
			$query = "SELECT option_name, option_value FROM $wpdb->options WHERE option_name in (";
			foreach ($options as $option) {
				$query .= "'" . $option . "', ";
			}
			$query = substr($query, 0, strlen($query) - 2);
			$query .= ")";

			$alloptions_db = $wpdb->get_results( $query );
			$wpdb->suppress_errors($suppress);
			if ( !is_array( $alloptions ) ) $alloptions = array();
			if ( is_array( $alloptions_db ) ) {
				foreach ( (array) $alloptions_db as $o ) {
					$alloptions[ $o->option_name ] = $o->option_value;
					unset($options[array_search($o->option_name, $options)]);
				}
				foreach ($options as $option ) {
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

	function cron_active() {
		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
			return;
		}
		if ( empty( $_GET[ 'mainwp_run' ] ) || 'test' !== $_GET[ 'mainwp_run' ] ) {
			return;
		}
		@session_write_close();
		@header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ), TRUE );
		@header( 'X-Robots-Tag: noindex, nofollow', TRUE );
		@header( 'X-MainWP-Version: ' . MainWP_System::$version, TRUE );
		nocache_headers();
		if ( $_GET[ 'mainwp_run' ] == 'test' ) {
			die( 'MainWP Test' );
		}
		die( '' );
	}

	function filter_fetchUrlsAuthed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output ) {
		return MainWP_Extensions::hookFetchUrlsAuthed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output );
	}

	function filter_fetchUrlAuthed( $pluginFile, $key, $websiteId, $what, $params ) {
		return MainWP_Extensions::hookFetchUrlAuthed( $pluginFile, $key, $websiteId, $what, $params );
	}

	function hookBulkPostMetaboxHandle( $post_id ) {
		$this->metaboxes->select_sites_handle( $post_id, 'bulkpost' );
		$this->metaboxes->add_categories_handle( $post_id, 'bulkpost' );
		$this->metaboxes->add_tags_handle( $post_id, 'bulkpost' );
		$this->metaboxes->add_slug_handle( $post_id, 'bulkpost' );
		MainWP_Post::add_sticky_handle( $post_id );
	}

	function hookBulkPageMetaboxHandle( $post_id ) {
		$this->metaboxes->select_sites_handle( $post_id, 'bulkpage' );
		$this->metaboxes->add_slug_handle( $post_id, 'bulkpage' );
        MainWP_Page::add_status_handle( $post_id );
	}

	public function after_extensions_plugin_row( $plugin_slug, $plugin_data, $status ) {
		$extensions = MainWP_Extensions::getExtensions();
		if ( !isset( $extensions[$plugin_slug] )) {
			return;
		}

		if ( isset($extensions[$plugin_slug]['activated_key']) && 'Activated' == $extensions[$plugin_slug]['activated_key'] ) {
			return;
		}

		$slug = basename($plugin_slug, ".php");
		$now = time();
		$register_time = get_option( 'mainwp_setup_register_later_time', 0 );
		if ($register_time > 0) {
			if ($now - $register_time > 24 * 60 * 60){
				delete_option('mainwp_setup_register_later_time');
			} else {
				return;
			}
		}

		$activate_notices = get_user_option( 'mainwp_hide_activate_notices' );
		if ( is_array( $activate_notices ) ) {
			if (isset($activate_notices[$slug])) {
				return;
			}
		}

		$notice = sprintf(__("You have a MainWP Extension that does not have an active API entered.  This means you will not receive updates or support.  Please visit the %sExtensions%s page and enter your API.", 'mainwp'), '<a href="admin.php?page=Extensions">', '</a>');
		?>
		<style type="text/css">
			tr[data-plugin="<?php echo $plugin_slug; ?>"] {
				box-shadow: none;
			}
		</style>
		<tr class="plugin-update-tr active" slug="<?php echo $slug; ?>"><td colspan="3" class="plugin-update colspanchange"><div class="update-message api-deactivate">
					<?php echo $notice; ?>
					<span class="mainwp-right"><a href="#" class="mainwp-activate-notice-dismiss" ><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss','mainwp' ); ?></a></span>
				</div></td></tr>
		<?php
	}

    public function parse_request(){
         if ( file_exists( MAINWP_PLUGIN_DIR."/response/api.php" ) ){
            include_once MAINWP_PLUGIN_DIR."/response/api.php";
         }
    }

	public function localization() {
		load_plugin_textdomain( 'mainwp', false, dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/' );
	}

	public function activated_check() {
		return $this->getVersion();
	}

	public function activated_sub_check()
	{
		return array( 'result' => MAINWP_API_VALID );
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
            if ( MainWP_Utility::showMainWPMessage( 'notice', 'phpver_5_5' ) ) {
                ?>
              <div class="mainwp-notice-wrap mainwp-notice mainwp-notice-red" style="margin-top: 2em">
                   <?php echo sprintf(__('<strong>MainWP Notice:</strong> Your server is currently running PHP version %s in the next few months your MainWP Dashboard will require PHP 5.5 as a minimum.<br/><br/>Please upgrade your server to at least 5.5 but we recommend PHP 7. You can find a template email to send your host %shere%s.', 'mainwp' ), $phpver, '<a href="https://wordpress.org/about/requirements/" target="_blank">','</a>'); ?>
                   <span class="mainwp-right"><a class="mainwp-notice-dismiss" notice-id="phpver_5_5"
                                                 style="text-decoration: none;" href="#"><i class="fa fa-times-circle"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
               </div>
               <?php
            }
        }

		if ( is_multisite() && ( !isset( $current_options['hide_multi_site_notice'] ) || empty( $current_options['hide_multi_site_notice'] ) ) ) {
			?>
			<div class="mainwp-events-notice mainwp-notice mainwp-notice-red">
				<span class="mainwp-right"><a class="mainwp-events-notice-dismiss" notice="multi_site"
				                              style="text-decoration: none;" href="#"><i class="fa fa-times-circle"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
				<span><i class="fa fa-exclamation-triangle fa-2x mainwp-red"></i> <strong><?php esc_html_e( 'Warning! WordPress Multisite detected.', 'mainwp' ); ?></strong></span>
				<p><?php esc_html_e( 'MainWP plugin is not designed nor fully tested on WordPress Multisite installations. Various features may not work properly. We highly recommend installing it on a single site installation!', 'mainwp' ); ?></p>
			</div>
			<?php
		}

		if ( MainWP_DB::Instance()->getWebsitesCount() == 0 ) {
			echo '<div class="mainwp-notice mainwp-notice-blue mainwp-api-message-valid fade"><strong>MainWP is almost ready. Please, <a href="' . admin_url() . 'admin.php?page=managesites&do=new">connect your first site</a>.</strong></div>';
			update_option( 'mainwp_first_site_events_notice', 'yes' );
		}

		if ( ! isset( $current_options['trust_child'] ) || empty( $current_options['trust_child'] ) ) {
			if ( self::isMainWP_Pages() ) {
				if ( ! MainWP_Plugins::checkAutoUpdatePlugin( 'mainwp-child/mainwp-child.php' ) ) {
					?>
					<div id="" class="mainwp-events-notice mainwp-notice mainwp-notice-red fade">
                                            <span class="mainwp-right"><a class="mainwp-events-notice-dismiss" notice="trust_child" style="text-decoration: none;" href="#"><i class="fa fa-times-circle"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
                                            <strong><?php esc_html_e( 'You have not set your MainWP Child plugins for auto updates, this is highly recommended!', 'mainwp' ); ?></strong>
                                            &nbsp;<a id="mainwp_btn_autoupdate_and_trust" class="button-primary" href="#"><?php esc_html_e( 'Turn on', 'mainwp' ); ?></a>
                                            &nbsp;<a class="button" href="//docs.mainwp.com/setting-mainwp-as-a-trusted-plugin/" target="_blank"><?php esc_html_e( 'Learn more', 'mainwp' ); ?></a>
					</div>
					<?php

				}
			}
		}

		$display_request1 = $display_request2 = false;

		if ( isset( $current_options['request_reviews1'] ) ) {
			if ( $current_options['request_reviews1'] == 'forever' ) {
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
			if ( $current_options['request_reviews2'] == 'forever' ) {
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
			?>
			<div class="mainwp-events-notice updated fade">
				<p>
					<span class="mainwp-right"><a class="mainwp-events-notice-dismiss" notice="request_reviews1" style="text-decoration: none;" href="#"><i class="fa fa-times-circle"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
					<span style="font-size: 14px;">
						<?php esc_html_e( 'Hi, I noticed you having been using MainWP for over 30 days and that\'s awesome!', 'mainwp' ); ?>
						<br/>
						<?php esc_html_e( 'Could you please do me a BIG favor and give it a 5-star rating on WordPress? Reviews from users like you really help the MainWP community to grow.', 'mainwp' ); ?>
						<br/><br/>
						<?php esc_html_e( 'Thank You!', 'mainwp' ); ?><br/>
						<?php esc_html_e( '~ Dennis', 'mainwp' ); ?><br/><br/>
						<a href="https://wordpress.org/support/view/plugin-reviews/mainwp#postform" target="_blank" class="button mainwp-upgrade-button"><?php esc_html_e( 'Ok, you deserve!', 'mainwp' ); ?>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
						</a>&nbsp;&nbsp;&nbsp;&nbsp; <a href="" class="button mainwp-events-notice-dismiss" notice="request_reviews1"><?php esc_html_e( 'Nope, maybe later.', 'mainwp' ); ?></a>
						<a href="" class="button mainwp-events-notice-dismiss" notice="request_reviews1_forever"><?php esc_html_e( 'I already did.', 'mainwp' ); ?></a>
					</span>
				</p>
			</div>
		<?php } else if ( $display_request2 ) { ?>
			<div class="mainwp-events-notice updated fade">
				<p>
					<span class="mainwp-right"><a class="mainwp-events-notice-dismiss" style="text-decoration: none;" notice="request_reviews2" href="#"><i class="fa fa-times-circle"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
					<span style="font-size: 14px;">
						<?php esc_html_e( 'Hi, I noticed you have a few MainWP extensions installed and that\'s awesome!', 'mainwp' ); ?>
						<br/>
						<?php esc_html_e( 'Could you please do me a BIG favor and give it a 5-star rating on WordPress? Reviews from users like you really help the MainWP community to grow.', 'mainwp' ); ?>
						<br/><br/>
						<?php esc_html_e( 'Thank You', 'mainwp' ); ?><br/>
						<?php esc_html_e( '~ Dennis', 'mainwp' ); ?><br/><br/>
						<a href="https://wordpress.org/support/view/plugin-reviews/mainwp#postform" target="_blank" class="button mainwp-upgrade-button">
							<?php esc_html_e( 'Ok, you deserve!', 'mainwp' ); ?>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
						</a>
						&nbsp;&nbsp;&nbsp;&nbsp;
						<a href="" class="button mainwp-events-notice-dismiss" notice="request_reviews2"><?php esc_html_e( 'Nope, maybe later.', 'mainwp' ); ?></a>
						<a href="" class="button mainwp-events-notice-dismiss" notice="request_reviews2_forever"><?php esc_html_e( 'I already did.', 'mainwp' ); ?></a>
					</span>
				</p>
			</div>
			<?php
		}
	}

    public function admin_notices_start() {
        echo '<div class="mainwp_admin_notices_wrap">';
    }

    public function admin_notices_end() {
        echo '</div>';
    }

	public function getVersion() {
		return $this->current_version;
	}


	public function check_update_custom( $transient ) {
		if ( isset( $_POST['action'] ) && ( ( $_POST['action'] == 'update-plugin' ) || ( $_POST['action'] == 'update-selected' ) ) ) {
			$extensions = MainWP_Extensions::getExtensions( array( 'activated' => true ) );
			if ( defined( 'DOING_AJAX' ) && isset( $_POST['plugin'] ) && $_POST['action'] == 'update-plugin' ) {
				$plugin_slug = $_POST['plugin'];
                // get download pakage url to prevent expire
				if ( isset( $extensions[ $plugin_slug ] ) ) {
					if ( isset( $transient->response[ $plugin_slug ] ) && version_compare( $transient->response[ $plugin_slug ]->new_version, $extensions[ $plugin_slug ]['version'], '=' ) ) {
						return $transient;
					}

					$api_slug = dirname( $plugin_slug );
					$rslt     = MainWP_API_Settings::getUpgradeInformation( $api_slug );

					if ( ! empty( $rslt ) && isset( $rslt->latest_version ) && version_compare( $rslt->latest_version, $extensions[ $plugin_slug ]['version'], '>' ) ) {
						$transient->response[ $plugin_slug ] = self::mapRsltObj( $rslt );
					}

					return $transient;
				}
			} else if ( $_POST['action'] == 'update-selected' && isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ) {
				$updated = false;
				foreach ( $_POST['checked'] as $plugin_slug ) {
					if ( isset( $extensions[ $plugin_slug ] ) ) {
						if ( isset( $transient->response[ $plugin_slug ] ) && version_compare( $transient->response[ $plugin_slug ]->new_version, $extensions[ $plugin_slug ]['version'], '=' ) ) {
							continue;
						}
						$api_slug = dirname( $plugin_slug );
						$rslt     = MainWP_API_Settings::getUpgradeInformation( $api_slug );
						if ( ! empty( $rslt ) && isset( $rslt->latest_version ) && version_compare( $rslt->latest_version, $extensions[ $plugin_slug ]['version'], '>' ) ) {
							$this->upgradeVersionInfo->result[ $api_slug ] = $rslt;
							$transient->response[ $plugin_slug ]           = self::mapRsltObj( $rslt );
							$updated                                       = true;
						}
					}
				}
				if ( $updated ) {
					MainWP_Utility::update_option( 'mainwp_upgradeVersionInfo', serialize( $this->upgradeVersionInfo ) );
				}

				return $transient;
			}
		}

		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		if ( isset( $_GET['do'] ) && $_GET['do'] == 'checkUpgrade' && ( ( time() - $this->upgradeVersionInfo->updated ) > 30 ) ) {
			$this->checkUpgrade();
		}

		if ( $this->upgradeVersionInfo != null && property_exists( $this->upgradeVersionInfo, 'result' ) && is_array( $this->upgradeVersionInfo->result ) ) {
			foreach ( $this->upgradeVersionInfo->result as $rslt ) {
				if ( ! isset( $rslt->slug ) ) {
					continue;
				} //Legacy, to support older versions.

				$plugin_slug = MainWP_Extensions::getPluginSlug( $rslt->slug );
				if ( isset( $transient->checked[ $plugin_slug ] ) && version_compare( $rslt->latest_version, $transient->checked[ $plugin_slug ], '>' ) ) {
					$transient->response[ $plugin_slug ] = self::mapRsltObj( $rslt );
				}
			}
		}

		return $transient;
	}

	public static function mapRsltObj( $pRslt ) {
		$obj              = new stdClass();
		$obj->slug        = $pRslt->slug;
		$obj->new_version = $pRslt->latest_version;
		$obj->url         = 'https://mainwp.com/';
		$obj->package     = $pRslt->download_url;
		$obj->key_status  = $pRslt->key_status;

		return $obj;
	}

	private function checkUpgrade() {
		$result                            = MainWP_API_Settings::checkUpgrade();
		$this->upgradeVersionInfo->updated = time();
		if ( ! empty( $result ) ) {
			$this->upgradeVersionInfo->result = $result;
		}
		MainWP_Utility::update_option( 'mainwp_upgradeVersionInfo', serialize( $this->upgradeVersionInfo ) );
	}

	public function pre_check_update_custom( $transient ) {                    
        if ( !isset( $transient->checked ) ) {                    
            return $transient;
        }

		if ( ( $this->upgradeVersionInfo == null ) || ( ( time() - $this->upgradeVersionInfo->updated ) > 60 ) ) {  // one minute before recheck to prevent check update information to many times                                     
			$this->checkUpgrade();
		}

		if ($this->upgradeVersionInfo != null && property_exists( $this->upgradeVersionInfo , 'result' ) && is_array( $this->upgradeVersionInfo->result ) ) {
			$extensions = MainWP_Extensions::getExtensions( array( 'activated' => true ) );
			foreach ( $this->upgradeVersionInfo->result as $rslt ) {
				$plugin_slug = MainWP_Extensions::getPluginSlug( $rslt->slug );
				if ( isset( $extensions[ $plugin_slug ] ) && version_compare( $rslt->latest_version, $extensions[ $plugin_slug ]['version'], '>' ) ) {
					$transient->response[ $plugin_slug ] = self::mapRsltObj( $rslt );
				}
			}
		}

		return $transient;
	}
        
	public function check_info( $false, $action, $arg ) {                
        if ( 'plugin_information' !== $action ) {
			return $false;
		}
                
		if ( ! isset( $arg->slug ) || ( $arg->slug == '' ) ) {
			return $false;
		}

		if ( $arg->slug === $this->slug ) {
			return $false;
		}

		$result   = MainWP_Extensions::getSlugs();
		$am_slugs = $result['am_slugs'];

		if ( $am_slugs != '' ) {
			$am_slugs = explode( ',', $am_slugs );
			if ( in_array( $arg->slug, $am_slugs ) ) {
				$info = MainWP_API_Settings::getPluginInformation( $arg->slug );
                if ( is_object( $info ) && property_exists( $info, 'sections' ) ) {
                    if ( !is_array( $info->sections ) || isset( $info->sections['changelog'] ) || empty( $info->sections['changelog'] ) ) {
                        $exts_data = MainWP_Extensions_View::getAvailableExtensions();
                        if (isset($exts_data[$arg->slug])) {
                            $ext_info = $exts_data[$arg->slug];
                            $changelog_link = rtrim($ext_info['link'],'/');
                            $info->sections['changelog'] = '<a href="' . $changelog_link . '#tab-changelog" target="_blank">' . $changelog_link . '#tab-changelog</a>';
                        }
                    }
                }
                return $info;
			}
		}

		return $false;
	}

	function print_updates_array_lines( $array, $backupChecks ) {
		$output = '';
		foreach ( $array as $line ) {
			$siteId      = $line[0];
			$text        = $line[1];
			$trustedText = $line[2];

			$output .= '<li>' . $text . $trustedText . ( $backupChecks == null || ! isset( $backupChecks[ $siteId ] ) || ( $backupChecks[ $siteId ] == true ) ? '' : '(Requires manual backup)' ) . '</li>' . "\n";
		}

		return $output;
	}

	function mainwp_cronupdatescheck_action() {
		MainWP_Logger::Instance()->info( 'CRON :: updates check' );

		@ignore_user_abort( true );
		@set_time_limit( 0 );
		$mem = '512M';
		@ini_set( 'memory_limit', $mem );
		@ini_set( 'max_execution_time', 0 );

		MainWP_Utility::update_option( 'mainwp_cron_last_updatescheck', time() );

		$mainwpAutomaticDailyUpdate = get_option( 'mainwp_automaticDailyUpdate' );

		$plugin_automaticDailyUpdate = get_option( 'mainwp_pluginAutomaticDailyUpdate' );
		$theme_automaticDailyUpdate = get_option( 'mainwp_themeAutomaticDailyUpdate' );

		$mainwpLastAutomaticUpdate = get_option( 'mainwp_updatescheck_last' );
		if ( $mainwpLastAutomaticUpdate == date( 'd/m/Y' ) ) {
			MainWP_Logger::Instance()->debug( 'CRON :: updates check :: already updated today' );

			return;
		}

	    if ( 'Y' == get_option('mainwp_updatescheck_ready_sendmail') ) {
	            $send_noti_at = apply_filters( 'mainwp_updatescheck_sendmail_at_time', false );
	            if ( !empty( $send_noti_at ) ) {
	                $send_noti_at = explode( ':', $send_noti_at );
	                $_hour = isset( $send_noti_at[0] ) ? intval( $send_noti_at[0] ) : 0;
	                $_mins = isset( $send_noti_at[1] ) ? intval( $send_noti_at[1] ) : 0;
	                if ( $_hour < 0 || $_hour > 23 ) {
	                    $_hour = 0;
	                }
	                if ( $_mins < 0 || $_mins > 59 ) {
	                    $_mins = 0;
	                }
	                $send_timestamp = strtotime( date( 'Y-m-d' ) . ' ' . $_hour . ':' . $_mins . ':59' );

	                if ( time() < $send_timestamp ) {
	                    return; // send notification later
	                }
	            }
	    }

		$websites = array();
		$checkupdate_websites = MainWP_DB::Instance()->getWebsitesCheckUpdates( 4 );

		foreach ($checkupdate_websites as $website) {
			if ( ! MainWP_DB::Instance()->backupFullTaskRunning( $website->id ) ) {
				$websites[] = $website;
			}
		}

		MainWP_Logger::Instance()->debug( 'CRON :: updates check :: found ' . count( $checkupdate_websites ) . ' websites' );
		MainWP_Logger::Instance()->debug( 'CRON :: backup task running :: found ' . (count( $checkupdate_websites )  - count( $websites )) . ' websites' );

		$userid = null;
		foreach ( $websites as $website ) {
			$websiteValues = array(
				'dtsAutomaticSyncStart' => time(),
			);
			if ( $userid == null ) {
				$userid = $website->userid;
			}

			MainWP_DB::Instance()->updateWebsiteSyncValues( $website->id, $websiteValues );
		}

		if ( count( $checkupdate_websites ) == 0 ) {
            if ( 'Y' != get_option('mainwp_updatescheck_ready_sendmail') ) {
                MainWP_Utility::update_option( 'mainwp_updatescheck_ready_sendmail', 'Y' );
                return; // to check time before send notification
            }

			$busyCounter = MainWP_DB::Instance()->getWebsitesCountWhereDtsAutomaticSyncSmallerThenStart();

			if ( $busyCounter == 0 ) {
                update_option( 'mainwp_last_synced_all_sites', time() );
				MainWP_Logger::Instance()->debug( 'CRON :: updates check :: got to the mail part' );

				//Send the email & update all to this time!
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

				if (!empty($plugin_automaticDailyUpdate)) {
					if ( ( count( $pluginsNewUpdate ) != 0 ) || ( count( $pluginsToUpdate ) != 0 )
					     || ( count( $notTrustedPluginsNewUpdate ) != 0 ) || ( count( $notTrustedPluginsToUpdate ) != 0 )
					) {
						$sendMail = true;

						$mail .= '<div><strong>WordPress Plugin Updates</strong></div>';
						$mail .= '<ul>';
						$mail .= $this->print_updates_array_lines( $pluginsNewUpdate, null );
						$mail .= $this->print_updates_array_lines( $pluginsToUpdate, $sitesCheckCompleted );
						$mail .= $this->print_updates_array_lines( $notTrustedPluginsNewUpdate, null );
						$mail .= $this->print_updates_array_lines( $notTrustedPluginsToUpdate, null );
						$mail .= '</ul>';
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

				if (!empty($theme_automaticDailyUpdate)) {
					if ( ( count( $themesNewUpdate ) != 0 ) || ( count( $themesToUpdate ) != 0 )
					     || ( count( $notTrustedThemesNewUpdate ) != 0 ) || ( count( $notTrustedThemesToUpdate ) != 0 )
					) {
						$sendMail = true;

						$mail .= '<div><strong>WordPress Themes Updates</strong></div>';
						$mail .= '<ul>';
						$mail .= $this->print_updates_array_lines( $themesNewUpdate, null );
						$mail .= $this->print_updates_array_lines( $themesToUpdate, $sitesCheckCompleted );
						$mail .= $this->print_updates_array_lines( $notTrustedThemesNewUpdate, null );
						$mail .= $this->print_updates_array_lines( $notTrustedThemesToUpdate, null );
						$mail .= '</ul>';
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

				if (!empty($mainwpAutomaticDailyUpdate)) {
					if ( ( count( $coreNewUpdate ) != 0 ) || ( count( $coreToUpdate ) != 0 ) || ( count( $ignoredCoreNewUpdate ) != 0 ) || ( count( $ignoredCoreToUpdate ) != 0 ) ) {
						$sendMail = true;

						$mail .= '<div><strong>WordPress Core Updates</strong></div>';
						$mail .= '<ul>';
						$mail .= $this->print_updates_array_lines( $coreNewUpdate, null );
						$mail .= $this->print_updates_array_lines( $coreToUpdate, $sitesCheckCompleted );
						$mail .= $this->print_updates_array_lines( $ignoredCoreNewUpdate, null );
						$mail .= $this->print_updates_array_lines( $ignoredCoreToUpdate, null );
						$mail .= '</ul>';
					}
				}

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
                MainWP_Utility::update_option( 'mainwp_updatescheck_ready_sendmail', '' );

                MainWP_Utility::update_option( 'mainwp_updatescheck_sites_icon', '' );

                if ( 1 == get_option( 'mainwp_check_http_response', 0 ) ) {

                    $sitesHttpCheckIds = get_option( 'mainwp_automaticUpdate_httpChecks' );
                    if ( ! is_array( $sitesHttpCheckIds ) ) {
                        $sitesHttpCheckIds = array();
                    }

                    $mail_offline = '';
                    $sitesOffline = array();
                    if (count($sitesHttpCheckIds) > 0) {
                        $sitesOffline = MainWP_DB::Instance()->getWebsitesByIds( $sitesHttpCheckIds );
                    }
                    if (is_array($sitesOffline) && count($sitesOffline) > 0) {
                        foreach($sitesOffline as $site) {
                            if ($site->offline_check_result == -1) {
                                $mail_offline .= '<li>' . $site->name . ' - [' . $site->url . '] - [' . $site->http_response_code . ']</li>';
                            }
                        }
                    }

                    $email = get_option( 'mainwp_updatescheck_mail_email' );
                    if ( !empty( $email ) && $mail_offline != '') {
                            MainWP_Logger::Instance()->debug( 'CRON :: http check :: send mail to ' . $email );
                            $mail_offline = '<div>After running auto updates, following sites are not returning expected HTTP request response:</div>
                                <div></div>
                                <ul>
                                ' . $mail_offline . '
                                </ul>
                                <div></div>
                                <div>Please visit your MainWP Dashboard as soon as possible and make sure that your sites are online. (<a href="' . site_url() . '">' . site_url() . '</a>)</div>';
                            wp_mail( $email, $mail_title = 'MainWP - HTTP response check', MainWP_Utility::formatEmail( $email, $mail_offline, $mail_title ), array(
                                    'From: "' . get_option( 'admin_email' ) . '" <' . get_option( 'admin_email' ) . '>',
                                    'content-type: text/html',
                            ) );
                    }
                    MainWP_Utility::update_option( 'mainwp_automaticUpdate_httpChecks', '' );
                }


				if ( ! $sendMail ) {
					MainWP_Logger::Instance()->debug( 'CRON :: updates check :: sendMail is false' );

					return;
				}

				if ( ($mainwpAutomaticDailyUpdate !== false && $mainwpAutomaticDailyUpdate != 0) || !empty($plugin_automaticDailyUpdate) || !empty($theme_automaticDailyUpdate) ) {
					//Create a nice email to send
					$email = get_option( 'mainwp_updatescheck_mail_email' );
					MainWP_Logger::Instance()->debug( 'CRON :: updates check :: send mail to ' . $email );
					if ( $email != false && $email != '' ) {
						$mail = '<div>We noticed the following updates are available on your MainWP Dashboard. (<a href="' . site_url() . '">' . site_url() . '</a>)</div>
                                 <div></div>
                                 ' . $mail . '
                                 <div><strong>Update Key</strong>:</div>
                                 <div><span style="color:#008000">Trusted</span> - will be updated within 24 hours.</div>
                                 <div><span style="color:#ff0000">Not Trusted</span> - you will need to log into your MainWP Dashboard and update.</div>
                                 <div> </div>
                                 <div>If your MainWP is configured to use Auto Updates these updates will be installed in the next 24 hours.</div>
                                 <div><strong>More about MainWP Automatic Updates</strong>:</div>
                                 <div><a href="http://mainwp.com/help/docs/managing-plugins-with-mainwp/plugins-auto-updates/" style="color:#446200" target="_blank">http://mainwp.com/help/docs/managing-plugins-with-mainwp/plugins-auto-updates/</a></div>
                                 <div><a href="http://mainwp.com/help/docs/managing-themes-with-mainwp/themes-auto-updates/" style="color:#446200" target="_blank">http://mainwp.com/help/docs/managing-themes-with-mainwp/themes-auto-updates/</a></div>
                                 <div><a href="http://mainwp.com/help/docs/auto-update-wordpress-core/" style="color:#446200" target="_blank">http://mainwp.com/help/docs/auto-update-wordpress-core/</a></div>';
						wp_mail( $email, $mail_title = 'MainWP - Trusted Updates', MainWP_Utility::formatEmail( $email, $mail, $mail_title ), array(
							'From: "' . get_option( 'admin_email' ) . '" <' . get_option( 'admin_email' ) . '>',
							'content-type: text/html',
						) );
					}
				}
			}
		} else {
			$userExtension = MainWP_DB::Instance()->getUserExtensionByUserId( $userid );

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

			$pluginsToUpdateNow      = array();
			$pluginsToUpdate         = array();
			$pluginsNewUpdate        = array();
			$notTrustedPluginsToUpdate  = array();
			$notTrustedPluginsNewUpdate = array();

			$themesToUpdateNow      = array();
			$themesToUpdate         = array();
			$themesNewUpdate        = array();
			$notTrustedThemesToUpdate  = array();
			$notTrustedThemesNewUpdate = array();

			$allWebsites = array();

			$infoTrustedText    = ' (<span style="color:#008000"><strong>Trusted</strong></span>)';
			$infoNotTrustedText = ' (<strong><span style="color:#ff0000">Not Trusted</span></strong>)';

			foreach ( $websites as $website ) {
				$websiteDecodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
				if ( ! is_array( $websiteDecodedIgnoredPlugins ) ) {
					$websiteDecodedIgnoredPlugins = array();
				}

				$websiteDecodedIgnoredThemes = json_decode( $website->ignored_themes, true );
				if ( ! is_array( $websiteDecodedIgnoredThemes ) ) {
					$websiteDecodedIgnoredThemes = array();
				}

				//Perform check & update
				if ( ! MainWP_Sync::syncSite( $website, false, true ) ) {
					$websiteValues = array(
						'dtsAutomaticSync' => time(),
					);

					MainWP_DB::Instance()->updateWebsiteSyncValues( $website->id, $websiteValues );

					continue;
				}
				$website = MainWP_DB::Instance()->getWebsiteById( $website->id );

				/** Check core updates **/
				$websiteLastCoreUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'last_wp_upgrades' ), true );
				$websiteCoreUpgrades     = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'wp_upgrades' ), true );

				//Run over every update we had last time..
				if ( isset( $websiteCoreUpgrades['current'] ) ) {
					$infoTxt    = '<a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . stripslashes( $website->name ) . '</a> - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'];
					$infoNewTxt = '*NEW* <a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . stripslashes( $website->name ) . '</a> - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'];
					$newUpdate  = ! ( isset( $websiteLastCoreUpgrades['current'] ) && ( $websiteLastCoreUpgrades['current'] == $websiteCoreUpgrades['current'] ) && ( $websiteLastCoreUpgrades['new'] == $websiteCoreUpgrades['new'] ) );
                    if ($website->is_ignoreCoreUpdates) {
                        continue;
                    }
					if ( $website->automatic_update == 1 ) {
						if ( $newUpdate ) {
							$coreNewUpdate[] = array( $website->id, $infoNewTxt, $infoTrustedText );
						} else {
							//Check ignore ? $ignoredCoreToUpdate
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

				/** Check plugins **/
				$websiteLastPlugins = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'last_plugin_upgrades' ), true );
				$websitePlugins     = json_decode( $website->plugin_upgrades, true );

				/** Check themes **/
				$websiteLastThemes = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'last_theme_upgrades' ), true );
				$websiteThemes     = json_decode( $website->theme_upgrades, true );

				$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
				if ( is_array( $decodedPremiumUpgrades ) ) {
					foreach ( $decodedPremiumUpgrades as $slug => $premiumUpgrade ) {
						if ( $premiumUpgrade['type'] == 'plugin' ) {
							if ( ! is_array( $websitePlugins ) ) {
								$websitePlugins = array();
							}
							$websitePlugins[ $slug ] = $premiumUpgrade;
						} else if ( $premiumUpgrade['type'] == 'theme' ) {
							if ( ! is_array( $websiteThemes ) ) {
								$websiteThemes = array();
							}
							$websiteThemes[ $slug ] = $premiumUpgrade;
						}
					}
				}

				//Run over every update we had last time..
				foreach ( $websitePlugins as $pluginSlug => $pluginInfo ) {
					if ( isset( $decodedIgnoredPlugins[ $pluginSlug ] ) || isset( $websiteDecodedIgnoredPlugins[ $pluginSlug ] ) ) {
						continue;
					}
                    if ($website->is_ignorePluginUpdates) {
                        continue;
                    }
					$infoTxt    = '<a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . stripslashes( $website->name ) . '</a> - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'];
					$infoNewTxt = '*NEW* <a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . stripslashes( $website->name ) . '</a> - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'];
                    if ( $pluginInfo['update']['url'] && ( false !== strpos( $pluginInfo['update']['url'], 'wordpress.org/plugins' ) ) ) {
                        $change_log = $pluginInfo['update']['url'];
                        if ( substr( $change_log, - 1 ) != '/' ) {
                                $change_log .= '/';
                        }
                        $change_log .= 'changelog/';
                        $infoTxt .= ' - ' . '<a href="' . $change_log .  '" target="_blank">Changelog</a>';
                        $infoNewTxt .= ' - ' . '<a href="' . $change_log .  '" target="_blank">Changelog</a>';
                    }
					$newUpdate = ! ( isset( $websiteLastPlugins[ $pluginSlug ] ) && ( $pluginInfo['Version'] == $websiteLastPlugins[ $pluginSlug ]['Version'] ) && ( $pluginInfo['update']['new_version'] == $websiteLastPlugins[ $pluginSlug ]['update']['new_version'] ) );
					//update this..
					if ( in_array( $pluginSlug, $trustedPlugins ) ) {
						//Trusted
						if ( $newUpdate ) {
							$pluginsNewUpdate[] = array( $website->id, $infoNewTxt, $infoTrustedText );
						} else {
							$pluginsToUpdateNow[ $website->id ][] = $pluginSlug;
							$allWebsites[ $website->id ]          = $website;
							$pluginsToUpdate[]                    = array( $website->id, $infoTxt, $infoTrustedText );
						}
					} else {
						//Not trusted
						if ( $newUpdate ) {
							$notTrustedPluginsNewUpdate[] = array( $website->id, $infoNewTxt, $infoNotTrustedText );
						} else {
							$notTrustedPluginsToUpdate[] = array( $website->id, $infoTxt, $infoNotTrustedText );
						}
					}
				}

				//Run over every update we had last time..
				foreach ( $websiteThemes as $themeSlug => $themeInfo ) {
					if ( isset( $decodedIgnoredThemes[ $themeSlug ] ) || isset( $websiteDecodedIgnoredThemes[ $themeSlug ] ) ) {
						continue;
					}

                    if ($website->is_ignoreThemeUpdates) {
                        continue;
                    }

					$infoTxt    = '<a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . stripslashes( $website->name )  . '</a> - ' . $themeInfo['Name'] . ' ' . $themeInfo['Version'] . ' to ' . $themeInfo['update']['new_version'];
					$infoNewTxt = '*NEW* <a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . stripslashes( $website->name ) . '</a> - ' . $themeInfo['Name'] . ' ' . $themeInfo['Version'] . ' to ' . $themeInfo['update']['new_version'];

					$newUpdate = ! ( isset( $websiteLastThemes[ $themeSlug ] ) && ( $themeInfo['Version'] == $websiteLastThemes[ $themeSlug ]['Version'] ) && ( $themeInfo['update']['new_version'] == $websiteLastThemes[ $themeSlug ]['update']['new_version'] ) );
					//update this..
					if ( in_array( $themeSlug, $trustedThemes ) ) {
						//Trusted
						if ( $newUpdate ) {
							$themesNewUpdate[] = array( $website->id, $infoNewTxt, $infoTrustedText );
						} else {
							$themesToUpdateNow[ $website->id ][] = $themeSlug;
							$allWebsites[ $website->id ]         = $website;
							$themesToUpdate[]                    = array( $website->id, $infoTxt, $infoTrustedText );
						}
					} else {
						//Not trusted
						if ( $newUpdate ) {
							$notTrustedThemesNewUpdate[] = array( $website->id, $infoNewTxt, $infoNotTrustedText );
						} else {
							$notTrustedThemesToUpdate[] = array( $website->id, $infoTxt, $infoNotTrustedText );
						}
					}
				}


				//Loop over last plugins & current plugins, check if we need to update them.
				$user  = get_userdata( $website->userid );
				$email = MainWP_Utility::getNotificationEmail( $user );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_email', $email );
				MainWP_DB::Instance()->updateWebsiteSyncValues( $website->id, array( 'dtsAutomaticSync' => time() ) );
				MainWP_DB::Instance()->updateWebsiteOption( $website, 'last_wp_upgrades', json_encode( $websiteCoreUpgrades ) );
				MainWP_DB::Instance()->updateWebsiteOption( $website, 'last_plugin_upgrades', $website->plugin_upgrades );
				MainWP_DB::Instance()->updateWebsiteOption( $website, 'last_theme_upgrades', $website->theme_upgrades );

                                // sync site favico one time per day
                $updatescheckSitesIcon = get_option( 'mainwp_updatescheck_sites_icon' );
                if ( !is_array( $updatescheckSitesIcon ) ) {
					$updatescheckSitesIcon = array();
				}
	            if ( !in_array( $website->id, $updatescheckSitesIcon ) ) {
	                MainWP_System::sync_site_icon( $website->id );
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


			if ( ( count( $coreToUpdate ) == 0 ) && ( count( $pluginsToUpdate ) == 0 ) && ( count( $themesToUpdate ) == 0 ) && ( count( $ignoredCoreToUpdate ) == 0 ) && ( count( $ignoredCoreNewUpdate ) == 0 )
			     && ( count( $notTrustedPluginsToUpdate ) == 0 ) && ( count( $notTrustedPluginsNewUpdate ) == 0 ) && ( count( $notTrustedThemesToUpdate ) == 0 ) && ( count( $notTrustedThemesNewUpdate ) == 0 )
			) {
				return;
			}

			if ( $mainwpAutomaticDailyUpdate != 1 && $plugin_automaticDailyUpdate != 1 && $theme_automaticDailyUpdate != 1 ) {
				return;
			}

			//Check if backups are required!
			if ( get_option( 'mainwp_enableLegacyBackupFeature' ) && get_option( 'mainwp_backup_before_upgrade' ) == 1 ) {
				$sitesCheckCompleted = get_option( 'mainwp_automaticUpdate_backupChecks' );
				if ( ! is_array( $sitesCheckCompleted ) ) {
					$sitesCheckCompleted = array();
				}


				$websitesToCheck = array();

				if ($plugin_automaticDailyUpdate == 1) {
					foreach ( $pluginsToUpdateNow as $websiteId => $slugs ) {
						$websitesToCheck[ $websiteId ] = true;
					}
				}

				if ($theme_automaticDailyUpdate == 1) {
					foreach ( $themesToUpdateNow as $websiteId => $slugs ) {
						$websitesToCheck[ $websiteId ] = true;
					}
				}

				if ($mainwpAutomaticDailyUpdate == 1) {
					foreach ( $coreToUpdateNow as $websiteId ) {
						$websitesToCheck[ $websiteId ] = true;
					}
				}

				foreach ( $websitesToCheck as $siteId => $bool ) {
					if ( $allWebsites[ $siteId ]->backup_before_upgrade == 0 ) {
						$sitesCheckCompleted[ $siteId ] = true;
					}
					if ( isset( $sitesCheckCompleted[ $siteId ] ) ) {
						continue;
					}

					$dir = MainWP_Utility::getMainWPSpecificDir( $siteId );
					//Check if backup ok
					$lastBackup = - 1;
					if ( file_exists( $dir ) && ( $dh = opendir( $dir ) ) ) {
						while ( ( $file = readdir( $dh ) ) !== false ) {
							if ( $file != '.' && $file != '..' ) {
								$theFile = $dir . $file;
								if ( MainWP_Utility::isArchive( $file ) && ! MainWP_Utility::isSQLArchive( $file ) && ( filemtime( $theFile ) > $lastBackup ) ) {
									$lastBackup = filemtime( $theFile );
								}
							}
						}
						closedir( $dh );
					}

					$mainwp_backup_before_upgrade_days  = get_option( 'mainwp_backup_before_upgrade_days' );
					if ( empty( $mainwp_backup_before_upgrade_days ) || !ctype_digit( $mainwp_backup_before_upgrade_days ) ) $mainwp_backup_before_upgrade_days = 7;

					$backupRequired = ( $lastBackup < ( time() - ( $mainwp_backup_before_upgrade_days * 24 * 60 * 60 ) ) ? true : false );

					if ( ! $backupRequired ) {
						$sitesCheckCompleted[ $siteId ] = true;
						MainWP_Utility::update_option( 'mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted );
						continue;
					}

					try {
						$result = MainWP_Manage_Sites::backup( $siteId, 'full', '', '', 0, 0, 0, 0 );
						MainWP_Manage_Sites::backupDownloadFile( $siteId, 'full', $result['url'], $result['local'] );
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

			if ($plugin_automaticDailyUpdate == 1) {
				//Update plugins
				foreach ( $pluginsToUpdateNow as $websiteId => $slugs ) {
					if ( ( $sitesCheckCompleted != null ) && ( $sitesCheckCompleted[ $websiteId ] == false ) ) {
						continue;
					}

					try {
						MainWP_Utility::fetchUrlAuthed( $allWebsites[ $websiteId ], 'upgradeplugintheme', array(
							'type' => 'plugin',
							'list' => urldecode( implode( ',', $slugs ) ),
						) );

						if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
							MainWP_Sync::syncInformationArray( $allWebsites[ $websiteId ], $information['sync'] );
						}
					} catch ( Exception $e ) {
					}
				}
			} else {
				$pluginsToUpdateNow = array();
			}

			if ($theme_automaticDailyUpdate == 1) {
				//Update themes
				foreach ( $themesToUpdateNow as $websiteId => $slugs ) {
					if ( ( $sitesCheckCompleted != null ) && ( $sitesCheckCompleted[ $websiteId ] == false ) ) {
						continue;
					}

					try {
						MainWP_Utility::fetchUrlAuthed( $allWebsites[ $websiteId ], 'upgradeplugintheme', array(
							'type' => 'theme',
							'list' => urldecode( implode( ',', $slugs ) ),
						) );

						if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
							MainWP_Sync::syncInformationArray( $allWebsites[ $websiteId ], $information['sync'] );
						}
					} catch ( Exception $e ) {
					}
				}
			} else {
				$themesToUpdateNow = array();
			}

			if ($mainwpAutomaticDailyUpdate == 1) {
				//Update core
				foreach ( $coreToUpdateNow as $websiteId ) {
					if ( ( $sitesCheckCompleted != null ) && ( $sitesCheckCompleted[ $websiteId ] == false ) ) {
						continue;
					}

					try {
						MainWP_Utility::fetchUrlAuthed( $allWebsites[ $websiteId ], 'upgrade' );
					} catch ( Exception $e ) {
					}
				}
			} else {
				$coreToUpdateNow = array();
			}

			do_action( 'mainwp_cronupdatecheck_action', $pluginsNewUpdate, $pluginsToUpdate, $pluginsToUpdateNow, $themesNewUpdate, $themesToUpdate, $themesToUpdateNow, $coreNewUpdate, $coreToUpdate, $coreToUpdateNow );
		}
	}

    public static function sync_site_icon($siteId = null) {
        if ( $siteId === null ) {
            if ( isset( $_POST['siteId'] ) )
               $siteId = $_POST['siteId'];
        }

        if ( MainWP_Utility::ctype_digit( $siteId ) ) {
			$website = MainWP_DB::Instance()->getWebsiteById( $siteId );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$error = '';
				try {
					$information = MainWP_Utility::fetchUrlAuthed( $website, 'get_site_icon' );
				} catch ( MainWP_Exception $e ) {
					$error = $e->getMessage();
				}

				if ( $error != '' ) {
					return array( 'error' => $error );
				} else if ( isset( $information['faviIconUrl'] ) && !empty($information['faviIconUrl']) ) {
                    MainWP_Logger::Instance()->debug( 'Downloading icon :: ' . $information['faviIconUrl'] );
                    $content =  MainWP_Utility::get_file_content( $information['faviIconUrl'] );
                    if ( !empty( $content ) ) {
                        $dirs      = MainWP_Utility::getMainWPDir();
                        $iconsDir = $dirs[0] . 'icons' . DIRECTORY_SEPARATOR;
                        if ( ! @is_dir( $iconsDir ) ) {
                            @mkdir( $iconsDir, 0777, true );
                        }
                        if ( ! file_exists( $iconsDir . 'index.php' ) ) {
                            @touch( $iconsDir . 'index.php' );
                        }
                        $filename = basename( $information['faviIconUrl'] );
                        if ( $filename ) {
                            $filename = 'favi-' . $siteId . '-' . $filename;
                            if ( file_put_contents( $iconsDir . $filename, $content ) ) {
                                MainWP_DB::Instance()->updateWebsiteOption( $website, 'favi_icon', $filename );
                                return array( 'result' => 'success' ) ;
                            } else {
                                return array( 'error' => 'Save icon file failed.' ) ;
                            }
                        }
                        return array( 'undefined_error' => true ) ;
                    } else {
	                    return array( 'error' => __( 'Download icon file failed', 'mainwp' ) );
                    }
				} else {
					return array( 'undefined_error' => true ) ;
				}
			}
		}
		return array( 'result' => 'NOSITE' );
	}

	function mainwp_cronpingchilds_action() {
		MainWP_Logger::Instance()->info( 'CRON :: ping childs' );

		$lastPing = get_option( 'mainwp_cron_last_ping' );
		if ( $lastPing !== false && ( time() - $lastPing ) < ( 60 * 60 * 23 ) ) {
			return;
		}
		MainWP_Utility::update_option( 'mainwp_cron_last_ping', time() );

		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsites() );
		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			try {
				$url = $website->siteurl;
				if ( ! MainWP_Utility::endsWith( $url, '/' ) ) {
					$url .= '/';
				}

				wp_remote_get( $url . 'wp-cron.php' );
			} catch ( Exception $e ) {

			}
		}
		@MainWP_DB::free_result( $websites );
	}

	function mainwp_cronbackups_continue_action() {
		if (!get_option('mainwp_enableLegacyBackupFeature')) {
			return;
		}
		MainWP_Logger::Instance()->info( 'CRON :: backups continue' );

		@ignore_user_abort( true );
		@set_time_limit( 0 );
		$mem = '512M';
		@ini_set( 'memory_limit', $mem );
		@ini_set( 'max_execution_time', 0 );

		MainWP_Utility::update_option( 'mainwp_cron_last_backups_continue', time() );

		//Fetch all tasks where complete < last & last checkup is more then 1minute ago! & last is more then 1 minute ago!
		$tasks = MainWP_DB::Instance()->getBackupTasksToComplete();

		MainWP_Logger::Instance()->debug( 'CRON :: backups continue :: Found ' . count( $tasks ) . ' to continue.' );

		if ( empty( $tasks ) ) {
			return;
		}

		foreach ( $tasks as $task ) {
			MainWP_Logger::Instance()->debug( 'CRON :: backups continue ::    Task: ' . $task->name );
		}

		foreach ( $tasks as $task ) {
			$task = MainWP_DB::Instance()->getBackupTaskById( $task->id );
			if ( $task->completed < $task->last_run ) {
				MainWP_Manage_Backups::executeBackupTask( $task, 5, false );
				break;
			}
		}
	}

	function mainwp_cronbackups_action() {
		if (!get_option('mainwp_enableLegacyBackupFeature')) {
			return;
		}

		MainWP_Logger::Instance()->info( 'CRON :: backups' );

		@ignore_user_abort( true );
		@set_time_limit( 0 );
		$mem = '512M';
		@ini_set( 'memory_limit', $mem );
		@ini_set( 'max_execution_time', 0 );

		MainWP_Utility::update_option( 'mainwp_cron_last_backups', time() );

		//Do cronjobs!
		//Config this in crontab: 0 0 * * * wget -q http://mainwp.com/wp-admin/?do=cron -O /dev/null 2>&1
		//this will execute once every day to check to do the scheduled backups
		$allTasks   = array();
		$dailyTasks = MainWP_DB::Instance()->getBackupTasksTodoDaily();
		if ( count( $dailyTasks ) > 0 ) {
			$allTasks = $dailyTasks;
		}
		$weeklyTasks = MainWP_DB::Instance()->getBackupTasksTodoWeekly();
		if ( count( $weeklyTasks ) > 0 ) {
			$allTasks = array_merge( $allTasks, $weeklyTasks );
		}
		$monthlyTasks = MainWP_DB::Instance()->getBackupTasksTodoMonthly();
		if ( count( $monthlyTasks ) > 0 ) {
			$allTasks = array_merge( $allTasks, $monthlyTasks );
		}

		MainWP_Logger::Instance()->debug( 'CRON :: backups :: Found ' . count( $allTasks ) . ' to start.' );

		foreach ( $allTasks as $task ) {
			MainWP_Logger::Instance()->debug( 'CRON :: backups ::    Task: ' . $task->name );
		}

		foreach ( $allTasks as $task ) {
			$threshold = 0;
			if ( $task->schedule == 'daily' ) {
				$threshold = ( 60 * 60 * 24 );
			} else if ( $task->schedule == 'weekly' ) {
				$threshold = ( 60 * 60 * 24 * 7 );
			} else if ( $task->schedule == 'monthly' ) {
				$threshold = ( 60 * 60 * 24 * 30 );
			}
			$task = MainWP_DB::Instance()->getBackupTaskById( $task->id );
			if ( ( time() - $task->last_run ) < $threshold ) {
				continue;
			}

			if ( ! MainWP_Manage_Backups::validateBackupTasks( array( $task ) ) ) {
				$task = MainWP_DB::Instance()->getBackupTaskById( $task->id );
			}

			$chunkedBackupTasks = get_option( 'mainwp_chunkedBackupTasks' );
			MainWP_Manage_Backups::executeBackupTask( $task, ( $chunkedBackupTasks != 0 ? 5 : 0 ) );
		}
	}

	function mainwp_cronstats_action() {
		MainWP_Logger::Instance()->info( 'CRON :: stats' );

		MainWP_Utility::update_option( 'mainwp_cron_last_stats', time() );

		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getWebsitesStatsUpdateSQL() );

		$start = time();
		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			if ( ( $start - time() ) > ( 60 * 60 * 2 ) ) {
				//two hours passed, next cron will start!
				break;
			}

			MainWP_DB::Instance()->updateWebsiteStats( $website->id, time() );

			if ( property_exists($website, 'sync_errors') && $website->sync_errors != '' ) {
				//Try reconnecting
				MainWP_Logger::Instance()->infoForWebsite( $website, 'reconnect', 'Trying to reconnect' );
				try {
					if ( MainWP_Manage_Sites::_reconnectSite( $website ) ) {
						//Reconnected
						MainWP_Logger::Instance()->infoForWebsite( $website, 'reconnect', 'Reconnected successfully' );
					}
				} catch ( Exception $e ) {
					//Still something wrong
					MainWP_Logger::Instance()->warningForWebsite( $website, 'reconnect', $e->getMessage() );
				}
			}
			else if ($website->nossl == 0) {
				//Try connecting to ssl!
			}
			sleep( 3 );
		}
		@MainWP_DB::free_result( $websites );
	}

    public static function add_left_menu($title, $key, $href, $desc = '' ) {
        global $mainwp_leftmenu;
        $mainwp_leftmenu[] = array($title, $key, $href, $desc);
    }

    public static function add_sub_left_menu($title, $parent_key, $slug, $href, $icon = '', $desc = '' ) {
        global $mainwp_sub_leftmenu, $mainwp_menu_active_slugs;
        $mainwp_sub_leftmenu[$parent_key][] = array($title, $slug, $href, $icon, $desc);
        if (!empty($slug))
            $mainwp_menu_active_slugs[$slug] = $slug; // to get active menu
    }

    public static function add_sub_sub_left_menu($title, $parent_key, $slug, $href, $right = '' ) {
        global $mainwp_sub_subleftmenu, $mainwp_menu_active_slugs;
        $mainwp_sub_subleftmenu[$parent_key][] = array($title, $href, $right);
        if (!empty($slug))
            $mainwp_menu_active_slugs[$slug] = $parent_key; // to get active menu
    }

    public static function init_subpages_left_menu($subPages, &$initSubpage, $parentKey, $slug ) {
        if ( !is_array( $subPages ) ) {
            return;
        }
        foreach ( $subPages as $subPage ) {
                if ( ! isset( $subPage['menu_hidden'] ) || (isset( $subPage['menu_hidden'] ) && $subPage['menu_hidden'] != true) ) {
                    $initSubpage[] = array(
                        'title' => $subPage['title'],
                        'parent_key' => $parentKey,
                        'href' => 'admin.php?page=' . $slug . $subPage['slug'],
                        'slug' => $slug . $subPage['slug'],
                        'right' => ''
                    );
                }
        }
    }

	function admin_footer() {
		MainWP_Post::initMenuSubPages();
		MainWP_Manage_Sites::initMenuSubPages();
		MainWP_Settings::initMenuSubPages();
		MainWP_Extensions::initMenuSubPages();
		MainWP_Page::initMenuSubPages();
		MainWP_Themes::initMenuSubPages();
		MainWP_Plugins::initMenuSubPages();
		MainWP_User::initMenuSubPages();
		if (get_option('mainwp_enableLegacyBackupFeature')) {
			MainWP_Manage_Backups::initMenuSubPages();
		}
        MainWP_Settings::initMenuSubPages();
		do_action( 'mainwp_admin_menu_sub' );
        MainWP_Server_Information::initMenuSubPages();
        if (get_option('mainwp_disable_wp_main_menu', 1)) {
            if ( self::isMainWP_Pages() ) {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function()
                {
                    jQuery('#adminmenu #collapse-menu').hide();
                });
            </script>
            <?php
            }
        }
	}

	function admin_print_styles() {
		$hide_footer = false;
		?>
		<style>
			.mainwp-checkbox:before {
				content: '<?php _e('YES', 'mainwp' ); ?>';
			}
			.mainwp-checkbox:after {
				content: '<?php _e('NO', 'mainwp' ); ?>';
			}

			<?php
			if ( isset( $_GET['hideall'] ) && $_GET['hideall'] == 1 ) {
				$post_plus = apply_filters( 'mainwp-ext-post-plus-enabled', false );

				if ( ! $post_plus ) { ?>
			#minor-publishing-actions {
				display: none;
			}

			<?php   }
		$hide_footer = true;
	?>
			#screen-options-link-wrap {
				display: none;
			}

			#wpcontent #wpadminbar {
				display: none;
			}

			.update-nag {
				display: none;
			}

			#wpfooter {
				display: none;
			}

			<?php
			}

			if ( ! $hide_footer && self::isMainWP_Pages() && ! self::isHideFooter() ) {
				?>
			#wpfooter {
				background: #333 !important;
				position: fixed !important;
				bottom: 0 !important;
			}

			<?php } ?>
		</style>
		<?php
	}

	public static function isMainWP_Pages() {
		$screen = get_current_screen();
		if ( $screen && strpos( $screen->base, 'mainwp_' ) !== false ) {
			return true;
		}

		return false;
	}

	public static function isHideFooter() {
		if ( get_option( 'mainwp_hide_footer', 0 ) ) {
			return true;
		}

		return false;
	}

	public static function get_openssl_conf() {
		$setup_hosting_type = get_option( 'mwp_setup_installationHostingType' );
		$setup_system_type = get_option( 'mwp_setup_installationSystemType' );
		$setup_conf_loc = '';
		if ( $setup_hosting_type == 2 && $setup_system_type == 3 ) {
			$setup_conf_loc = get_option( 'mwp_setup_opensslLibLocation' );
		}
		return $setup_conf_loc;
	}

	function init() {
		if ( ! function_exists( 'mainwp_current_user_can' ) ) {
			function mainwp_current_user_can( $cap_type = '', $cap ) {
				global $current_user;

				if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
					return true;
				}

				if ( empty( $current_user ) ) {
					if ( ! function_exists( 'wp_get_current_user' ) ) {
						require_once( ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'pluggable.php' );
					}
					$current_user = wp_get_current_user();
				}

				if ( empty( $current_user ) ) {
					return false;
				}

				return apply_filters( 'mainwp_currentusercan', true, $cap_type, $cap );
			}
		}

		$this->handleSettingsPost();

		remove_all_filters( 'admin_footer_text' );
		add_filter( 'admin_footer_text', array( &$this, 'admin_footer_text' ) );
	}

	function uploadFile( $file ) {
		header( 'Content-Description: File Transfer' );
		if ( MainWP_Utility::endsWith( $file, '.tar.gz' ) ) {
			header( 'Content-Type: application/x-gzip' );
			header( "Content-Encoding: gzip'" );
		} else {
			header( 'Content-Type: application/octet-stream' );
		}
		header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $file ) );
		while ( @ob_get_level() ) {
			@ob_end_clean();
		}
		$this->readfile_chunked( $file );
		exit();
	}

	function readfile_chunked( $filename ) {
		$chunksize = 1024; // how many bytes per chunk
		$handle    = @fopen( $filename, 'rb' );
		if ( $handle === false ) {
			return false;
		}

		while ( ! @feof( $handle ) ) {
			$buffer = @fread( $handle, $chunksize );
			echo $buffer;
			@ob_flush();
			@flush();
			$buffer = null;
		}

		return @fclose( $handle );
	}

	function parse_init() {
		if ( isset( $_GET['do'] ) && $_GET['do'] == 'testLog' ) {
			MainWP_Logger::Instance()->debug( 'ruben' );
		}
		if ( isset( $_GET['do'] ) && $_GET['do'] == 'cronBackups' ) {
			$this->mainwp_cronbackups_action();
		} else if ( isset( $_GET['do'] ) && $_GET['do'] == 'cronBackupsContinue' ) {
			$this->mainwp_cronbackups_continue_action();
		} else if ( isset( $_GET['do'] ) && $_GET['do'] == 'cronStats' ) {
			$this->mainwp_cronstats_action();
		} else if ( isset( $_GET['do'] ) && $_GET['do'] == 'cronUpdatesCheck' ) {
			$this->mainwp_cronupdatescheck_action();
		} else if ( isset( $_GET['mwpdl'] ) && isset( $_GET['sig'] ) ) {
			$mwpDir = MainWP_Utility::getMainWPDir();
			$mwpDir = $mwpDir[0];
			$file   = trailingslashit( $mwpDir ) . rawurldecode( $_REQUEST['mwpdl'] );

			if ( stristr( rawurldecode( $_REQUEST['mwpdl'] ), '..' ) ) {
				return;
			}

			if ( file_exists( $file ) && md5( filesize( $file ) ) == $_GET['sig'] ) {
				$this->uploadFile( $file );
				exit();
			}
		}
		else if ( isset( $_GET['page'] ) )
		{
			if ( MainWP_Utility::isAdmin() ) {
				switch ( $_GET['page'] ) {
					case 'mainwp-setup' :
						new MainWP_Setup_Wizard();
						break;
				}
			}
		}
	}

	function login_form() {
		global $redirect_to;
		if ( ! isset( $_GET['redirect_to'] ) ) {
			$redirect_to = get_admin_url() . 'index.php';
		}
	}

	function post_updated_messages( $messages ) {
		$messages['post'][98] = __( 'WordPress SEO values have been saved.', 'mainwp' );
		$messages['post'][99] = __( 'You have to select the sites you wish to publish to.', 'mainwp' );

		return $messages;
	}

	function mainwp_warning_notice() {
		if ( get_option( 'mainwp_installation_warning_hide_the_notice' ) == 'yes' ) {
			return;
		}
		?>
		<div id="mainwp-installation-warning" class="mainwp-notice mainwp-notice-red">
			<h3><?php esc_html_e( 'Stop! Before you continue,', 'mainwp' ); ?></h3>
			<strong><?php esc_html_e( 'We HIGHLY recommend a NEW WordPress install for your MainWP Dashboard.', 'mainwp' ); ?></strong><br/><br/>
			<?php echo sprintf( __( 'Using a new WordPress install will help to cut down on plugin conflicts and other issues that can be caused by trying to run your MainWP Dashboard off an active site. Most hosting companies provide free subdomains %s and we recommend creating one if you do not have a specific dedicated domain to run your MainWP Dashboard.', 'mainwp' ), '("<strong>demo.yourdomain.com</strong>")' ) ; ?><br/><br/>
			<?php echo sprintf( __( 'If you are not sure how to set up a subdomain here is a quick step by step with %s, %s or %s. If you are not sure what you have, contact your hosting companies support.', 'mainwp' ), '<a href="http://docs.mainwp.com/creating-a-subdomain-in-cpanel/">cPanel</a>', '<a href="http://docs.mainwp.com/creating-a-subdomain-in-plesk/">Plesk</a>', '<a href="http://docs.mainwp.com/creating-a-subdomain-in-directadmin-control-panel/">Direct Admin</a>' ) ; ?>
			<br/><br/>
			<div style="text-align: center">
				<a href="#" class="button button-primary" id="remove-mainwp-installation-warning"><?php esc_html_e('I have read the warning and I want to proceed', 'mainwp' ); ?></a>
			</div>
		</div>
		<?php
	}

	function admin_init() {
		if ( ! MainWP_Utility::isAdmin() ) {
			return;
		}
                                
		if ( get_option( 'mainwp_activated' ) == 'yes' ) {
			delete_option( 'mainwp_activated' );
			wp_cache_delete( 'mainwp_activated' , 'options' );
			wp_redirect( admin_url( 'admin.php?page=mainwp_tab' ) );

			return;
		}

		global $mainwpUseExternalPrimaryBackupsMethod;

		if ( $mainwpUseExternalPrimaryBackupsMethod === null ) {
			$mainwpUseExternalPrimaryBackupsMethod = apply_filters( 'mainwp-getprimarybackup-activated', '' );
		}

		add_action( 'admin_notices', array( $this, 'mainwp_warning_notice' ) );
		$this->posthandler->init();

		wp_enqueue_script( 'jquery-ui-tooltip' );
		wp_enqueue_script( 'jquery-ui-autocomplete' );
		wp_enqueue_script( 'jquery-ui-progressbar' );
		wp_enqueue_script( 'jquery-ui-datepicker' );
		wp_enqueue_script( 'jquery-ui-dialog' );

		global $wp_scripts;
		$ui      = $wp_scripts->query( 'jquery-ui-core' );
		$version = $ui->ver;
		if ( MainWP_Utility::startsWith( $version, '1.10' ) ) {
			wp_enqueue_style( 'jquery-ui-style', MAINWP_PLUGIN_URL . 'css/1.10.4/jquery-ui.min.css', array(), '1.10.4' );
		} else {
			wp_enqueue_style( 'jquery-ui-style', MAINWP_PLUGIN_URL . 'css/1.11.1/jquery-ui.min.css', array(), '1.11.1' );
		}

		wp_enqueue_script( 'mainwp', MAINWP_PLUGIN_URL . 'js/mainwp.js', array(
			'jquery-ui-tooltip',
			'jquery-ui-autocomplete',
			'jquery-ui-progressbar',
			'jquery-ui-dialog',
			'jquery-ui-datepicker',
		), $this->current_version );
		$mainwpParams = array(
			'image_url'             => MAINWP_PLUGIN_URL . 'images/',
			'backup_before_upgrade' => ( get_option( 'mainwp_backup_before_upgrade' ) == 1 ),
			'admin_url'             => admin_url(),
			'date_format'           => get_option( 'date_format' ),
			'time_format'           => get_option( 'time_format' ),
			'enabledTwit'           => MainWP_Twitter::enabledTwitterMessages(),
			'maxSecondsTwit'        => MAINWP_TWITTER_MAX_SECONDS,
			'installedBulkSettingsManager'	=> MainWP_Extensions::isExtensionAvailable( 'mainwp-bulk-settings-manager' ) ? 1 : 0,
			'maximumSyncRequests'   => ( get_option( 'mainwp_maximumSyncRequests' ) === false ) ? 8 : get_option( 'mainwp_maximumSyncRequests' ),
			'maximumInstallUpdateRequests'   => ( get_option( 'mainwp_maximumInstallUpdateRequests' ) === false ) ? 3 : get_option( 'mainwp_maximumInstallUpdateRequests' ),
		);
		wp_localize_script( 'mainwp', 'mainwpParams', $mainwpParams );
		wp_enqueue_script( 'mainwp-tristate', MAINWP_PLUGIN_URL . 'js/tristate.min.js', array( 'mainwp' ), $this->current_version );

		$mainwpTranslations = MainWP_System_View::getMainWPTranslations();

		wp_localize_script( 'mainwp', 'mainwpTranslations', $mainwpTranslations );

		$security_nonces = $this->posthandler->getSecurityNonces();
		wp_localize_script( 'mainwp', 'security_nonces', $security_nonces );

		MainWP_Meta_Boxes::initMetaBoxes();

		wp_enqueue_script( 'thickbox' );
		wp_enqueue_script( 'user-profile' );
		wp_enqueue_style( 'thickbox' );

		MainWP_Tours::enqueue_tours_scripts();

		if ( isset( $_GET['page'] ) && ( $_GET['page'] == 'PluginsManage' || $_GET['page'] == 'ThemesManage' ) ) {
			wp_enqueue_script( 'mainwp-fixedtable', MAINWP_PLUGIN_URL . 'js/tableHeadFixer.js', array( 'jquery', 'jquery-ui-core' ), $this->current_version );
		}

		if ( ! current_user_can( 'update_core' ) ) {
			remove_action( 'admin_notices', 'update_nag', 3 );
		}
	}

	public function admin_redirects() {
        if ( (defined( 'DOING_CRON' ) && DOING_CRON) ||  defined( 'DOING_AJAX' ) )  {
            return;
        }

        if ( ! empty( $_GET['page'] ) && in_array( $_GET['page'], array( 'mainwp-setup' ) ) ) {
			return;
		}

//       if ( ! get_transient( '_mainwp_activation_redirect' ) || is_network_admin() ) {
//			return;
//		}

		//delete_transient( '_mainwp_activation_redirect' );

		$quick_setup = get_site_option('mainwp_run_quick_setup', false);
		if ($quick_setup == 'yes') {
			wp_redirect( admin_url( 'admin.php?page=mainwp-setup' ) );
			exit;
		}

		$started = get_option('mainwp_getting_started');
		if (!empty($started)) {
			delete_option('mainwp_getting_started');
            if (! is_multisite()) {
                if ( 'started' == $started ) {
                    wp_redirect( admin_url( 'admin.php?page=mainwp_about&do=started' ) );
                    exit;
                } else if ( 'whatnew' == $started ) {
                    wp_redirect( admin_url( 'admin.php?page=mainwp_about&do=whatnew' ) );
                    exit;
                }
            }
		}

        $_pos = strlen( $_SERVER['REQUEST_URI'] ) - strlen( '/wp-admin/' );
		$hide_menus = get_option( 'mwp_setup_hide_wp_menus', array() );
		if ( ! is_array( $hide_menus ) ) {
			$hide_menus = array();
        }

		$hide_wp_dashboard = in_array( 'dashboard', $hide_menus );
		if ( ($hide_wp_dashboard && strpos( $_SERVER['REQUEST_URI'], 'index.php' ) ) || (strpos( $_SERVER['REQUEST_URI'], '/wp-admin/' ) !== false && strpos( $_SERVER['REQUEST_URI'], '/wp-admin/' ) == $_pos ) ) {
            if ( mainwp_current_user_can( 'dashboard', 'access_global_dashboard' ) ) { // to fix
                wp_redirect( admin_url( 'admin.php?page=mainwp_tab' ) );
                die();
            }
		}
	}

	function handleSettingsPost() {
		if ( ! function_exists( 'wp_create_nonce' ) ) {
			include_once( ABSPATH . WPINC . '/pluggable.php' );
		}

		if ( isset( $_GET['page'] ) ) {
			if ( $_GET['page'] == 'DashboardOptions' ) {
				if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'DashboardOptions' ) ) {
					MainWP_Utility::update_option( 'mainwp_use_favicon', ( ! isset( $_POST['mainwp_use_favicon'] ) ? 0 : 1 ) );
					MainWP_Utility::update_option( 'mainwp_hide_footer', ( ! isset( $_POST['mainwp_hide_footer'] ) ? 0 : 1 ) );
					MainWP_Utility::update_option( 'mainwp_hide_tips', ( ! isset( $_POST['mainwp_hide_tips'] ) ? 0 : 1 ) );
					$enabled_twit = ! isset( $_POST['mainwp_hide_twitters_message'] ) ? 0 : 1;
					MainWP_Utility::update_option( 'mainwp_hide_twitters_message', $enabled_twit );
					if ( ! $enabled_twit ) {
						MainWP_Twitter::clearAllTwitterMessages();
					}
                    MainWP_Utility::update_option( 'mainwp_disable_wp_main_menu', ( ! isset( $_POST['mainwp_disable_wp_main_menu'] ) ? 0 : 1 ) );
                    $redirect_url = admin_url( 'admin.php?page=DashboardOptions&message=saved');
                    wp_redirect( $redirect_url );
                    exit();
				}
			} else if ( $_GET['page'] == 'MainWPTools' ) {
				if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'MainWPTools' ) ) {
					$hide_menus = array();
					if ( isset( $_POST['mainwp_hide_wpmenu'] ) && is_array( $_POST['mainwp_hide_wpmenu'] ) && count( $_POST['mainwp_hide_wpmenu'] ) > 0 ) {
						foreach ( $_POST['mainwp_hide_wpmenu'] as $value ) {
							$hide_menus[] = $value;
						}
					}
					MainWP_Utility::update_option('mwp_setup_hide_wp_menus', $hide_menus);
				}
			}
		}

		if ( isset( $_POST['select_mainwp_options_siteview'] ) ) {
			$userExtension            = MainWP_DB::Instance()->getUserExtension();
			$userExtension->site_view = ( empty( $_POST['select_mainwp_options_siteview'] ) ? 0 : intval($_POST['select_mainwp_options_siteview']) );
			MainWP_DB::Instance()->updateUserExtension( $userExtension );
		}

		if ( isset( $_POST['submit'] )) {
			if (wp_verify_nonce( $_POST['wp_nonce'], 'Settings' ) ) {
				$updated = MainWP_Options::handleSettingsPost();
				$updated |= MainWP_Manage_Sites::handleSettingsPost();
				$updated |= MainWP_Footprint::handleSettingsPost();
				$msg = '';
				if ($updated) {
					$msg = '&message=saved';
				}
				wp_redirect( admin_url( 'admin.php?page=Settings' . $msg) );
				exit();
			} else if ( wp_verify_nonce( $_POST['wp_nonce'], 'PluginAutoUpdate' ) ) {
				$val = ( ! isset( $_POST['mainwp_pluginAutomaticDailyUpdate'] ) ? 2 : $_POST['mainwp_pluginAutomaticDailyUpdate'] );
				MainWP_Utility::update_option( 'mainwp_pluginAutomaticDailyUpdate', $val );
				wp_redirect( admin_url( 'admin.php?page=PluginsAutoUpdate&message=saved') );
				exit();
			} else if ( wp_verify_nonce( $_POST['wp_nonce'], 'ThemeAutoUpdate' ) ) {
				$val = ( ! isset( $_POST['mainwp_themeAutomaticDailyUpdate'] ) ? 2 : $_POST['mainwp_themeAutomaticDailyUpdate'] );
				MainWP_Utility::update_option( 'mainwp_themeAutomaticDailyUpdate', $val );
				wp_redirect( admin_url( 'admin.php?page=ThemesAutoUpdate&message=saved') );
				exit();
			}
		}
	}

	//This function will read the metaboxes & save them to the post
	function publish_bulkpost( $post_id ) {
		if ( isset( $_POST['mainwp_wpseo_metabox_save_values'] ) && ( ! empty( $_POST['mainwp_wpseo_metabox_save_values'] ) ) ) {
			return;
		}

		$message_id = 99;
		//Read extra metabox
		$pid = $this->metaboxes->select_sites_handle( $post_id, 'bulkpost' );
		do_action( 'mainwp_publish_bulkpost', $post_id );

		if ( $pid == $post_id ) {
			/** @var $wpdb wpdb */
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post_id ) );
			add_filter( 'redirect_post_location', create_function( '$location', 'return esc_url_raw(add_query_arg(array("message" => "' . $message_id . '", "hideall" => 1), $location));' ) );
		} else {
			$this->metaboxes->add_categories_handle( $post_id, 'bulkpost' );
			$this->metaboxes->add_tags_handle( $post_id, 'bulkpost' );
			$this->metaboxes->add_slug_handle( $post_id, 'bulkpost' );
			MainWP_Post::add_sticky_handle( $post_id );

			//Redirect to handle page! (to actually post the messages)
			wp_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPost&hideall=1&id=' . $post_id );
			die();
		}
	}

	function save_bulkpost( $post_id ) {
		$post = get_post( $post_id );

		if ( $post->post_type != 'bulkpage' && $post->post_type != 'bulkpost' ) {
			return;
		}

		if ( $post->post_type != 'bulkpost' && ( ! isset( $_POST['post_type'] ) || ( $_POST['post_type'] != 'bulkpost' ) ) ) {
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

		$message_id = 96;

		//Read extra metabox
		$pid = $this->metaboxes->select_sites_handle( $post_id, 'bulkpost' );
		$this->metaboxes->add_categories_handle( $post_id, 'bulkpost' );
		$this->metaboxes->add_tags_handle( $post_id, 'bulkpost' );
		$this->metaboxes->add_slug_handle( $post_id, 'bulkpost' );

		do_action( 'mainwp_save_bulkpost', $post_id );

		if ( isset( $_POST['save'] ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post_id ) );
			add_filter( 'redirect_post_location', create_function( '$location', 'return esc_url_raw(add_query_arg(array("message" => "' . $message_id . '", "hideall" => 1), $location));' ) );
		} else if ( $pid == $post_id ) {
			/** @var $wpdb wpdb */
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post_id ) );
			add_filter( 'redirect_post_location', create_function( '$location', 'return esc_url_raw(add_query_arg(array("message" => "' . $message_id . '", "hideall" => 1), $location));' ) );
		} else if ( isset( $_POST['publish'] ) ) {
			//Redirect to handle page! (to actually post the messages)
			wp_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPost&hideall=1&id=' . $post_id );
			die();
		}

	}

	//This function will read the metaboxes & save them to the post
	function publish_bulkpage( $post_id ) {
		if ( isset( $_POST['mainwp_wpseo_metabox_save_values'] ) && ( ! empty( $_POST['mainwp_wpseo_metabox_save_values'] ) ) ) {
			return;
		}

		$message_id = 99;

		//Read extra metabox
		$pid = $this->metaboxes->select_sites_handle( $post_id, 'bulkpage' );
		do_action( 'mainwp_publish_bulkpage', $post_id );

		if ( $pid == $post_id ) {
			/** @var $wpdb wpdb */
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post_id ) );
			add_filter( 'redirect_post_location', create_function( '$location', 'return esc_url_raw(add_query_arg(array("message" => "' . $message_id . '", "hideall" => 1), $location));' ) );
		} else {
			$this->metaboxes->add_slug_handle( $post_id, 'bulkpage' );
            MainWP_Page::add_status_handle( $post_id );
			//Redirect to handle page! (to actually post the messages)
			wp_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPage&hideall=1&id=' . $post_id );
			die();
		}
	}

	function save_bulkpage( $post_id ) {
		$post = get_post( $post_id );

		if ( $post->post_type != 'bulkpage' && $post->post_type != 'bulkpost' ) {
			return;
		}

		if ( $post->post_type != 'bulkpage' && ( ! isset( $_POST['post_type'] ) || ( $_POST['post_type'] != 'bulkpage' ) ) ) {
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

		$message_id = 96;

		//Read extra metabox
		$pid = $this->metaboxes->select_sites_handle( $post_id, 'bulkpage' );
		$this->metaboxes->add_slug_handle( $post_id, 'bulkpage' );
		do_action( 'mainwp_save_bulkpage', $post_id );

		if ( isset( $_POST['save'] ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post_id ) );
			add_filter( 'redirect_post_location', create_function( '$location', 'return esc_url_raw(add_query_arg(array("message" => "' . $message_id . '", "hideall" => 1), $location));' ) );
		} else if ( $pid == $post_id ) {
			/** @var $wpdb wpdb */
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post_id ) );
			add_filter( 'redirect_post_location', create_function( '$location', 'return esc_url_raw(add_query_arg(array("message" => "' . $message_id . '", "hideall" => 1), $location));' ) );
		} else {
			//Redirect to handle page! (to actually post the messages)
			wp_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPage&hideall=1&id=' . $post_id );
			die();
		}
	}

	function create_post_type() {
		$queryable = ( apply_filters( 'mainwp-ext-post-plus-enabled', false ) ) ? true : false;

		$labels = array(
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
			'labels'              => $labels,
			'hierarchical'        => false,
			'description'         => 'description...',
			'supports'            => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'custom-fields',
				'comments',
				'revisions',
			),
			//'taxonomies' => array('category', 'post_tag', 'page-category'),
			'public'              => true,
			'show_ui'             => true,
			//'show_in_menu' => 'index.php',
			'show_in_nav_menus'   => false,
			'publicly_queryable'  => $queryable,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'query_var'           => false,
			'can_export'          => false,
			'rewrite'             => false,
			'capabilities'        => array(
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
			'labels'              => $labels,
			'hierarchical'        => false,
			'description'         => 'description...',
			'supports'            => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'custom-fields',
				'comments',
				'revisions',
			),
			//'taxonomies' => array('category', 'post_tag', 'page-category'),
			'public'              => true,
			'show_ui'             => true,
			//'show_in_menu' => 'index.php',
			'show_in_nav_menus'   => false,
			'publicly_queryable'  => $queryable,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'query_var'           => false,
			'can_export'          => false,
			'rewrite'             => false,
			'capabilities'        => array(
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

	function admin_enqueue_scripts( $hook ) {
		wp_register_script( 'mainwp-admin', MAINWP_PLUGIN_URL . 'js/mainwp-admin.js', array( 'select2' ), $this->current_version );

		if ( MainWP_Utility::isAdmin() ) {
			wp_enqueue_script( 'mainwp-admin' );
		}

		if ( self::isMainWP_Pages() ) {
            wp_deregister_script( 'select2' );
			wp_enqueue_script( 'mainwp-rightnow', MAINWP_PLUGIN_URL . 'js/mainwp-rightnow.js', array(), $this->current_version );
			wp_enqueue_script( 'mainwp-managesites', MAINWP_PLUGIN_URL . 'js/mainwp-managesites.js', array(), $this->current_version );
			wp_enqueue_script( 'mainwp-extensions', MAINWP_PLUGIN_URL . 'js/mainwp-extensions.js', array(), $this->current_version );
            wp_enqueue_script( 'select2', MAINWP_PLUGIN_URL . 'js/select2/js/select2.min.js', array( 'jquery' ), '4.0.3', true );
		}

		wp_enqueue_script( 'mainwp-ui', MAINWP_PLUGIN_URL . 'js/mainwp-ui.js', array(), $this->current_version );
		wp_enqueue_script( 'mainwp-fileuploader', MAINWP_PLUGIN_URL . 'js/fileuploader.js', array(), $this->current_version );
		wp_enqueue_script( 'mainwp-date', MAINWP_PLUGIN_URL . 'js/date.js', array(), $this->current_version );
		wp_enqueue_script( 'mainwp-tablesorter', MAINWP_PLUGIN_URL . 'js/jquery.tablesorter.min.js', array(), $this->current_version );
		wp_enqueue_script( 'mainwp-tablesorter-pager', MAINWP_PLUGIN_URL . 'js/jquery.tablesorter.pager.js', array(), $this->current_version );
		wp_enqueue_script( 'mainwp-moment', MAINWP_PLUGIN_URL . 'js/moment.min.js', array(), $this->current_version );
		if (isset($_GET['page'])) {
			if ($_GET['page'] == 'managesites' || $_GET['page'] == 'PluginsManage' || $_GET['page'] == 'ThemesManage' ) {
				wp_enqueue_script( 'dragtable', MAINWP_PLUGIN_URL . 'js/dragtable/jquery.dragtable.js', array( 'jquery' ), '1.0', false );
			} else if ($_GET['page'] == 'PostBulkManage' || $_GET['page'] == 'PageBulkManage' || $_GET['page'] ==  'UserBulkManage') {
				wp_enqueue_script( 'dragtable', MAINWP_PLUGIN_URL . 'js/dragtable/jquery.dragtable.mod.js', array( 'jquery' ), '1.0', false );
			}
		}
	}

	function admin_enqueue_styles( $hook ) {
		global $wp_version;
		wp_register_style( 'mainwp-hidden', MAINWP_PLUGIN_URL . 'css/mainwp-hidden.css', array(), $this->current_version );
		wp_enqueue_style( 'mainwp', MAINWP_PLUGIN_URL . 'css/mainwp.css', array(), $this->current_version );
		wp_enqueue_style( 'mainwp-responsive-layouts', MAINWP_PLUGIN_URL . 'css/mainwp-responsive-layouts.css', array(), $this->current_version );
		wp_enqueue_style( 'mainwp-fileuploader', MAINWP_PLUGIN_URL . 'css/fileuploader.css', array(), $this->current_version );

		if ( isset( $_GET['hideall'] ) && $_GET['hideall'] == 1 ) {
			wp_enqueue_style( 'mainwp-hidden' );
			remove_action( 'admin_footer', 'wp_admin_bar_render', 1000 );
		}

		if ( version_compare( $wp_version, '4.3.1', '>' ) ) {
			wp_enqueue_style( 'mainwp-44', MAINWP_PLUGIN_URL . 'css/mainwp-44.css', array(), $this->current_version );
		}
        if ( self::isMainWP_Pages() ) {
            wp_deregister_style( 'select2' );
            wp_enqueue_style( 'mainwp-filetree', MAINWP_PLUGIN_URL . 'css/jqueryFileTree.css', array(), $this->current_version );
            wp_enqueue_style( 'mainwp-font-awesome', MAINWP_PLUGIN_URL . 'css/font-awesome/css/font-awesome.min.css', array(), $this->current_version );
            wp_enqueue_style( 'select2', MAINWP_PLUGIN_URL . 'js/select2/css/select2.css', array(), '4.0.4' );
        }
		if (isset($_GET['page'])) {
			if ($_GET['page'] == 'managesites'  || $_GET['page'] == 'PluginsManage' || $_GET['page'] == 'ThemesManage' ) {
				wp_enqueue_style( 'dragtable', MAINWP_PLUGIN_URL . 'css/dragtable/dragtable.css', array(), '1.0' );
			} else if ($_GET['page'] == 'PostBulkManage' || $_GET['page'] == 'PageBulkManage' || $_GET['page'] ==  'UserBulkManage') {
				wp_enqueue_style( 'dragtable', MAINWP_PLUGIN_URL . 'css/dragtable/dragtable.mod.css', array(), '1.0' );

			}
		}
	}

	function admin_head() {
		echo '<script type="text/javascript">var mainwp_ajax_nonce = "' . wp_create_nonce( 'mainwp_ajax' ) . '"</script>';
		echo '<script type="text/javascript" src="' . MAINWP_PLUGIN_URL . 'js/FileSaver.js' . '"></script>';
		echo '<script type="text/javascript" src="' . MAINWP_PLUGIN_URL . 'js/jqueryFileTree.js' . '"></script>';
	}

	function in_admin_head() {
		if (self::isMainWP_Pages() && isset($_GET['page'])) {
			$exts_pageslug = MainWP_Extensions::getExtensionsPageSlug();
			if (!in_array($_GET['page'], $exts_pageslug))
				self::addHelpTabs();
		}
	}

	public static function addHelpTabs () {
		$documentation = 'https://mainwp.com/help/';
		$screen = get_current_screen();
		$i = 1;

		$screen->add_help_tab( array(
			'id'	=> 'mainwp_helptabs_' . $i++,
			'title'	=> __('First Steps with MainWP', 'mainwp'),
			'content'	=> self::getHelpContent(1),
		) );
		$screen->add_help_tab( array(
			'id'	=> 'mainwp_helptabs_' . $i++,
			'title'	=> __('First Steps with Extensions', 'mainwp'),
			'content'	=> self::getHelpContent(2),
		) );
		$screen->add_help_tab( array(
			'id'	=> 'mainwp_helptabs_' . $i++,
			'title'	=> __('User Interface', 'mainwp'),
			'content'	=> self::getHelpContent(3),
		) );
		$screen->add_help_tab( array(
			'id'	=> 'mainwp_helptabs_' . $i++,
			'title'	=> __('Manage Updates', 'mainwp'),
			'content'	=> self::getHelpContent(4),
		) );
		$screen->add_help_tab( array(
			'id'	=> 'mainwp_helptabs_' . $i++,
			'title'	=> __('Manage Sites', 'mainwp'),
			'content'	=> self::getHelpContent(5),
		) );
		$screen->add_help_tab( array(
			'id'	=> 'mainwp_helptabs_' . $i++,
			'title'	=> __('Manage Posts', 'mainwp'),
			'content'	=> self::getHelpContent(6),
		) );
		$screen->add_help_tab( array(
			'id'	=> 'mainwp_helptabs_' . $i++,
			'title'	=> __('Manage Pages', 'mainwp'),
			'content'	=> self::getHelpContent(7),
		) );
		$screen->add_help_tab( array(
			'id'	=> 'mainwp_helptabs_' . $i++,
			'title'	=> __('Manage Plugins', 'mainwp'),
			'content'	=> self::getHelpContent(8),
		) );
		$screen->add_help_tab( array(
			'id'	=> 'mainwp_helptabs_' . $i++,
			'title'	=> __('Manage Themes', 'mainwp'),
			'content'	=> self::getHelpContent(9),
		) );
		$screen->add_help_tab( array(
			'id'	=> 'mainwp_helptabs_' . $i++,
			'title'	=> __('Manage Users', 'mainwp'),
			'content'	=> self::getHelpContent(10),
		) );
		$screen->add_help_tab( array(
			'id'	=> 'mainwp_helptabs_' . $i++,
			'title'	=> __('Troubleshooting', 'mainwp'),
			'content'	=> self::getHelpContent(11),
		) );
		$screen->set_help_sidebar(
			'<h3>' . __( 'Additional Help:', 'mainwp') . '</h3>' .
			'<p><a href="' . $documentation . '" target="_blank">' . __( 'MainWP Documentation', 'mainwp' ) . '</a></p>' .
			'<p><a href="https://mainwp.com/support/" target="_blank">' . __( 'MainWP Support', 'mainwp' ) . '</a></p>' .
			'<p><a href="https://www.facebook.com/groups/MainWPUsers/" target="_blank">' . __( 'MainWP Users Facebook Group', 'mainwp' ) . '</a></p>'
		);
	}

	public static function getHelpContent ($tabId) {
		$documentation = 'https://mainwp.com/help/';
		ob_start();
		if ( 1 == $tabId ) {
			?>
			<h3>First Steps with MainWP</h3>
			<p>If you are having issues with getting started with the MainWP plugin, please review following help documents</p>
			<a href="<?php echo $documentation; ?>docs/set-up-the-mainwp-plugin/" target="_blank"><i class="fa fa-book"></i> Set up the MainWP Plugin</a><br/>
			<a href="<?php echo $documentation; ?>docs/set-up-the-mainwp-plugin/install-mainwp-dashboard/" target="_blank"><i class="fa fa-book"></i> Install MainWP Dashboard</a><br/>
			<a href="<?php echo $documentation; ?>docs/set-up-the-mainwp-plugin/install-mainwp-child/" target="_blank"><i class="fa fa-book"></i> Install MainWP Child</a><br/>
			<a href="<?php echo $documentation; ?>docs/set-up-the-mainwp-plugin/quick-setup-wizard/" target="_blank"><i class="fa fa-book"></i> Quick Setup Wizard</a><br/>
			<a href="<?php echo $documentation; ?>docs/set-up-the-mainwp-plugin/set-unique-security-id/" target="_blank"><i class="fa fa-book"></i> Set Unique Security ID</a><br/>
			<a href="<?php echo $documentation; ?>docs/set-up-the-mainwp-plugin/add-site-to-your-dashboard/" target="_blank"><i class="fa fa-book"></i> Add a Site to your Dashboard</a><br/>
			<a href="<?php echo $documentation; ?>docs/set-up-the-mainwp-plugin/mainwp-dashboard-settings/" target="_blank"><i class="fa fa-book"></i> MainWP Dashboard Settings</a><br/>
			<a href="<?php echo $documentation; ?>docs/set-up-the-mainwp-plugin/add-site-to-your-dashboard/import-sites/" target="_blank"><i class="fa fa-book"></i> Import Sites</a><br/>
		<?php } else if ( 2 == $tabId ) { ?>
			<h3>First Steps with Extensions</h3>
			<p>If you are having issues with getting started with the MainWP extensions, please review following help documents</p>
			<a href="<?php echo $documentation; ?>docs/what-are-mainwp-extensions/" target="_blank"><i class="fa fa-book"></i> What are the MainWP Extensions</a><br/>
			<a href="<?php echo $documentation; ?>docs/what-are-mainwp-extensions/order-extensions/" target="_blank"><i class="fa fa-book"></i> Order Extension(s)</a><br/>
			<a href="<?php echo $documentation; ?>docs/what-are-mainwp-extensions/my-downloads-and-api-keys/" target="_blank"><i class="fa fa-book"></i> My Downloads and API Keys</a><br/>
			<a href="<?php echo $documentation; ?>docs/what-are-mainwp-extensions/install-extensions/" target="_blank"><i class="fa fa-book"></i> Install Extension(s)</a><br/>
			<a href="<?php echo $documentation; ?>docs/what-are-mainwp-extensions/activate-extensions-api/" target="_blank"><i class="fa fa-book"></i> Activate Extension(s) API</a><br/>
			<a href="<?php echo $documentation; ?>docs/what-are-mainwp-extensions/updating-extensions/" target="_blank"><i class="fa fa-book"></i> Updating Extension(s)</a><br/>
			<a href="<?php echo $documentation; ?>docs/what-are-mainwp-extensions/remove-extensions/" target="_blank"><i class="fa fa-book"></i> Remove Extension(s)</a><br/><br/>
			<a href="<?php echo $documentation; ?>/category/mainwp-extensions/" target="_blank"><i class="fa fa-book"></i> Help Documenation for all MainWP Extensions</a>
		<?php } else if ( 3 == $tabId ) { ?>
			<h3>User Interface</h3>
			<p>If you need help with understanding the MainWP User Interface, please review following help documents</p>
			<a href="<?php echo $documentation; ?>docs/understanding-mainwp-dashboard-user-interface/" target="_blank"><i class="fa fa-book"></i> Understanding MainWP Dashboard User Interface</a><br/>
			<a href="<?php echo $documentation; ?>docs/understanding-mainwp-dashboard-user-interface/mainwp-navigation/" target="_blank"><i class="fa fa-book"></i> MainWP Navigation</a><br/>
			<a href="<?php echo $documentation; ?>docs/understanding-mainwp-dashboard-user-interface/screen-options/" target="_blank"><i class="fa fa-book"></i> Screen Options</a><br/>
			<a href="<?php echo $documentation; ?>docs/understanding-mainwp-dashboard-user-interface/mainwp-dashboard/" target="_blank"><i class="fa fa-book"></i> MainWP Dashboard</a><br/>
			<a href="<?php echo $documentation; ?>docs/understanding-mainwp-dashboard-user-interface/mainwp-tables/" target="_blank"><i class="fa fa-book"></i> MainWP Tables</a><br/>
			<a href="<?php echo $documentation; ?>docs/understanding-mainwp-dashboard-user-interface/individual-child-site-mode/" target="_blank"><i class="fa fa-book"></i> Individual Child Site Mode</a><br/>
			<a href="<?php echo $documentation; ?>docs/understanding-mainwp-dashboard-user-interface/select-sites-metabox/" target="_blank"><i class="fa fa-book"></i> Select Sites Metabox</a><br/>
		<?php } else if ( 4 == $tabId ) { ?>
			<h3>Manage Updates</h3>
			<p>If you need help with managing updates, please review following help documents</p>
			<a href="<?php echo $documentation; ?>docs/managing-plugins-with-mainwp/update-plugins/" target="_blank"><i class="fa fa-book"></i> Update Plugins</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-plugins-with-mainwp/plugins-auto-updates/" target="_blank"><i class="fa fa-book"></i> Plugins Auto Updates</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-plugins-with-mainwp/ignore-plugin-updates/" target="_blank"><i class="fa fa-book"></i> Ignore Plugin Updates</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-themes-with-mainwp/update-themes/" target="_blank"><i class="fa fa-book"></i> Update Themes</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-themes-with-mainwp/themes-auto-updates/" target="_blank"><i class="fa fa-book"></i> Themes Auto Updates</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-themes-with-mainwp/ignore-theme-updates/" target="_blank"><i class="fa fa-book"></i> Ignore Theme Updates</a><br/>
			<a href="<?php echo $documentation; ?>docs/update-wordpress-core/" target="_blank"><i class="fa fa-book"></i> Update WordPress Core</a><br/>
			<a href="<?php echo $documentation; ?>docs/auto-update-wordpress-core/" target="_blank"><i class="fa fa-book"></i> Auto Update WordPress Core</a><br/>
			<a href="<?php echo $documentation; ?>docs/ignore-wordpress-core-update/" target="_blank"><i class="fa fa-book"></i> Ignore WordPress Core Update</a><br/>
		<?php } else if ( 5 == $tabId ) { ?>
			<h3>Manage Sites</h3>
			<p>If you need help with understanding sites management, please review following help documents</p>
			<a href="<?php echo $documentation; ?>docs/manage-child-sites/" target="_blank"><i class="fa fa-book"></i> Manage Child Sites</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-child-sites/access-child-site-wp-admin/" target="_blank"><i class="fa fa-book"></i> Access Child Site WP Admin</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-child-sites/synchronize-a-child-site/" target="_blank"><i class="fa fa-book"></i> Synchronize a Child Site</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-child-sites/edit-a-child-site/" target="_blank"><i class="fa fa-book"></i> Edit a Child Site</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-child-sites/reconnect-a-child-site/" target="_blank"><i class="fa fa-book"></i> Reconnect a Child Site</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-child-sites/delete-a-child-site/" target="_blank"><i class="fa fa-book"></i> Delete a Child Site</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-child-sites/security-issues/" target="_blank"><i class="fa fa-book"></i> Security Issues</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-child-sites/manage-child-site-groups/" target="_blank"><i class="fa fa-book"></i> Manage Child Site Groups</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-child-sites/manage-child-site-notes/" target="_blank"><i class="fa fa-book"></i> Manage Child Site Notes</a><br/>
		<?php } else if ( 6 == $tabId ) { ?>
			<h3>Manage Posts</h3>
			<p>If you need help with understanding posts management, please review following help documents</p>
			<a href="<?php echo $documentation; ?>docs/manage-posts/" target="_blank"><i class="fa fa-book"></i> Manage Posts</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-posts/create-a-new-post/" target="_blank"><i class="fa fa-book"></i> Create a New Post</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-posts/edit-an-existing-post/" target="_blank"><i class="fa fa-book"></i> Edit an Existing Post</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-posts/change-status-of-an-existing-post/" target="_blank"><i class="fa fa-book"></i> Change Status of an Existing Post</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-posts/view-an-existing-post/" target="_blank"><i class="fa fa-book"></i> View an Existing Post</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-posts/delete-posts/" target="_blank"><i class="fa fa-book"></i> Delete Post(s)</a><br/>
		<?php } else if ( 7 == $tabId ) { ?>
			<h3>Manage Pages</h3>
			<p>If you need help with understanding pages management, please review following help documents</p>
			<a href="<?php echo $documentation; ?>docs/manage-pages/" target="_blank"><i class="fa fa-book"></i> Manage Pages</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-pages/create-a-new-page/" target="_blank"><i class="fa fa-book"></i> Create a New Page</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-pages/edit-a-page/" target="_blank"><i class="fa fa-book"></i> Edit a Page</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-pages/view-an-existing-page/" target="_blank"><i class="fa fa-book"></i> View an Existing Page</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-pages/delete-pages/" target="_blank"><i class="fa fa-book"></i> Delete Page(s)</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-pages/restore-pages/" target="_blank"><i class="fa fa-book"></i> Restore Page(s)</a><br/>
		<?php } else if ( 8 == $tabId ) { ?>
			<h3>Manage Plugins</h3>
			<p>If you need help with understanding plugins management, please review following help documents</p>
			<a href="<?php echo $documentation; ?>docs/managing-plugins-with-mainwp/" target="_blank"><i class="fa fa-book"></i> Managing Plugins with MainWP</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-plugins-with-mainwp/install-plugins/" target="_blank"><i class="fa fa-book"></i> Install Plugin(s)</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-plugins-with-mainwp/activate-plugins/" target="_blank"><i class="fa fa-book"></i> Activate Plugins</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-plugins-with-mainwp/deactivate-plugins/" target="_blank"><i class="fa fa-book"></i> Deactivate Plugins</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-plugins-with-mainwp/delete-plugins/" target="_blank"><i class="fa fa-book"></i> Delete Plugins</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-plugins-with-mainwp/update-plugins/" target="_blank"><i class="fa fa-book"></i> Update Plugins</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-plugins-with-mainwp/plugins-auto-updates/" target="_blank"><i class="fa fa-book"></i> Plugins Auto Updates</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-plugins-with-mainwp/ignore-plugin-updates/" target="_blank"><i class="fa fa-book"></i> Ignore Plugin Updates</a><br/>
		<?php } else if ( 9 == $tabId ) { ?>
			<h3>Manage Themes</h3>
			<p>If you need help with understanding themes management, please review following help documents</p>
			<a href="<?php echo $documentation; ?>docs/managing-themes-with-mainwp/" target="_blank"><i class="fa fa-book"></i> Managing Themes with MainWP</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-themes-with-mainwp/install-themes/" target="_blank"><i class="fa fa-book"></i> Install Themes</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-themes-with-mainwp/activate-themes/" target="_blank"><i class="fa fa-book"></i> Activate Themes</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-themes-with-mainwp/delete-themes/" target="_blank"><i class="fa fa-book"></i> Delete Themes</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-themes-with-mainwp/abandoned-themes/" target="_blank"><i class="fa fa-book"></i> Abandoned Themes</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-themes-with-mainwp/update-themes/" target="_blank"><i class="fa fa-book"></i> Update Themes</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-themes-with-mainwp/themes-auto-updates/" target="_blank"><i class="fa fa-book"></i> Themes Auto Updates</a><br/>
			<a href="<?php echo $documentation; ?>docs/managing-themes-with-mainwp/ignore-theme-updates/" target="_blank"><i class="fa fa-book"></i> Ignore Theme Updates</a><br/>
		<?php } else if ( 10 == $tabId ) { ?>
			<h3>Manage Users</h3>
			<p>If you need help with understanding users management, please review following help documents</p>
			<a href="<?php echo $documentation; ?>docs/manage-users/" target="_blank"><i class="fa fa-book"></i> Manage Users</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-users/create-a-new-user/" target="_blank"><i class="fa fa-book"></i> Create a New User</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-users/edit-an-existing-user/" target="_blank"><i class="fa fa-book"></i> Edit an Existing User</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-users/change-a-role-for-child-site-users/" target="_blank"><i class="fa fa-book"></i> Change a Role for Child Site User(s)</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-users/bulk-update-users-passwords/" target="_blank"><i class="fa fa-book"></i> Bulk Update Users Passwords</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-users/bulk-update-administrator-passwords/" target="_blank"><i class="fa fa-book"></i> Bulk Update Administrator Passwords</a><br/>
			<a href="<?php echo $documentation; ?>docs/manage-users/delete-users/" target="_blank"><i class="fa fa-book"></i> Delete User(s)</a><br/>
		<?php } else if ( 11 == $tabId ) { ?>
			<h3>Troubleshooting</h3>
			<p>Troubleshooting help documentation</p>
			<a href="<?php echo $documentation; ?>category/troubleshooting/adding-a-child-site-issues/" target="_blank"><i class="fa fa-book"></i> Adding a Child Site Issues</a><br/>
			<a href="<?php echo $documentation; ?>category/troubleshooting/activating-and-updating-mainwp-extensions-issues/" target="_blank"><i class="fa fa-book"></i> Activating and Updating MainWP Extensions Issues</a><br/>
			<?php
		}
		$output = ob_get_clean();
		return $output;
	}

	function admin_body_class( $class_string ) {
		$screen = get_current_screen();

		if ( $screen && strpos( $screen->base, 'mainwp_' ) !== false ) {
			$class_string .= 'mainwp-ui';
		}
        if (get_option('mainwp_disable_wp_main_menu', 1)) {
            if ( self::isMainWP_Pages() ) {
                $class_string .= ' mainwp-ui-leftmenu folded';
            }
        }
		return $class_string;
	}

	function admin_menu() {
		global $menu;
		foreach ( $menu as $k => $item ) {
			if ( $item[2] == 'edit.php?post_type=bulkpost' ) { //Remove bulkpost
				unset( $menu[ $k ] );
			} else if ( $item[2] == 'edit.php?post_type=bulkpage' ) { //Remove bulkpost
				unset( $menu[ $k ] );
			}
		}
	}


	public  static function enqueue_postbox_scripts() {
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'wp-lists' );
		wp_enqueue_script( 'postbox' );
	}

	public static function do_mainwp_meta_boxes($_postpage, $screen = 'normal', $force_show = true) {
		wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false );
		wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
		if ($force_show) {
			add_filter('hidden_meta_boxes', array(self::$instance, 'force_show_meta_box'), 10, 3);
		}
		?>
		<div class="metabox-holder columns-1">
			<?php do_meta_boxes($_postpage, $screen, null ); ?>
		</div>
		<script type="text/javascript"> var mainwp_postbox_page = '<?php echo $_postpage; ?>';</script>
		<?php
		if ($force_show) {
			remove_filter('hidden_meta_boxes', array(self::$instance, 'force_show_meta_box'));
		}
	}

	public function force_show_meta_box($hidden, $screen) {
		return array();
	}

	public function remove_wp_menus() {
		$hide_menus = get_option( 'mwp_setup_hide_wp_menus', array() );
		if ( ! is_array( $hide_menus ) ) {
			$hide_menus = array(); }

		$menus_slug = array(
			'dashboard' => 'index.php',
			'posts' => 'edit.php',
			'media' => 'upload.php',
			'pages' => 'edit.php?post_type=page',
			'appearance' => 'themes.php',
			'comments' => 'edit-comments.php',
			'users' => 'users.php',
			'tools' => 'tools.php',
		);

		foreach ( $hide_menus as $menu ) {
			if ( isset( $menus_slug[ $menu ] ) ) {
				remove_menu_page( $menus_slug[ $menu ] );
			}
		}
	}

	function sites_fly_menu() {
		global $wpdb;
		$where    = MainWP_DB::Instance()->getWhereAllowAccessSites( 'wp' );

		$options_extra = MainWP_DB::Instance()->getSQLWebsitesOptionsExtra();
		$websites = $wpdb->get_results( 'SELECT wp.id,wp.name,wp.url' . $options_extra . ' FROM `' . $wpdb->prefix . 'mainwp_wp` wp WHERE 1 ' . $where );
		?>
		<div id="mainwp-sites-menu" style="direction: rtl;">
			<div style="direction: ltr;">
				<ul>
					<?php
					foreach ( $websites as $website ) {
						$imgfavi = '';
						if ( $website !== null ) {
							if ( get_option( 'mainwp_use_favicon', 1 ) == 1 ) {
								$favi_url = MainWP_Utility::get_favico_url( $website );
								$imgfavi  = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:bottom;"/>&nbsp;';
							}
						}
						echo wp_kses_post( '<li class="mwp-child-site-item" value="' . $website->id . '">' . $imgfavi . '<a href="admin.php?page=managesites&dashboard=' . $website->id . '" class="mainpw-fly-meny-lnk">' . MainWP_Utility::getNiceURL( $website->url ) . '</a></li>' );
					}
					?>
				</ul>
				<div id="mainwp-sites-menu-filter">
					<input id="mainwp-fly-manu-filter" style="margin-top: .5em; width: 100%;" type="text" value="" placeholder="<?php esc_attr_e( 'Type here to filter sites', 'mainwp' ); ?>" />
				</div>
			</div>
		</div>
		<?php
	}

	//Empty footer text
	function admin_footer_text() {
		if ( ! self::isMainWP_Pages() ) {
			return;
		}
		if ( self::isHideFooter() ) {
			return;
		}

		return wp_kses_post( '<a href="javascript:void(0)" id="mainwp-sites-menu-button" class="mainwp-white mainwp-margin-right-2"><i class="fa fa-globe fa-2x"></i></a>' . '<span style="font-size: 14px;"><i class="fa fa-info-circle"></i> ' . __( 'Currently managing ', 'mainwp' ) . MainWP_DB::Instance()->getWebsitesCount() . __( ' child sites with MainWP ', 'mainwp' ) . $this->current_version . __( ' version. ', 'mainwp' ) . '</span>' );
	}

	function add_new_links() {
		?>
		<div id="mainwp-add-new-links">
			<ul>
				<li>
					<a class="mainwp-add-new-link" href="<?php echo esc_attr( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>">
						<i class="fa fa-globe"></i> &nbsp;&nbsp;<?php esc_html_e( 'Site', 'mainwp' ); ?>
					</a>
				</li>
				<li>
					<a class="mainwp-add-new-link" href="<?php echo esc_attr( admin_url( 'admin.php?page=PostBulkAdd' ) ); ?>">
						<i class="fa fa-file-text"></i> &nbsp;&nbsp;<?php esc_html_e( 'Post', 'mainwp' ); ?>
					</a>
				</li>
				<li>
					<a class="mainwp-add-new-link" href="<?php echo esc_attr( admin_url( 'admin.php?page=PageBulkAdd' ) ); ?>">
						<i class="fa fa-file"></i> &nbsp;&nbsp;<?php esc_html_e( 'Page', 'mainwp' ); ?>
					</a>
				</li>
				<li>
					<a class="mainwp-add-new-link" href="<?php echo esc_attr( admin_url( 'admin.php?page=PluginsInstall' ) ); ?>">
						<i class="fa fa-plug"></i> &nbsp;&nbsp;<?php esc_html_e( 'Plugin', 'mainwp' ); ?>
					</a>
				</li>
				<li>
					<a class="mainwp-add-new-link" href="<?php echo esc_attr( admin_url( 'admin.php?page=ThemesInstall' ) ); ?>">
						<i class="fa fa-paint-brush"></i> &nbsp;&nbsp;<?php esc_html_e( 'Theme', 'mainwp' ); ?>
					</a>
				</li>
				<li>
					<a class="mainwp-add-new-link" href="<?php echo esc_attr( admin_url( 'admin.php?page=UserBulkAdd' ) ); ?>">
						<i class="fa fa-user"></i> &nbsp;&nbsp;<?php esc_html_e( 'User', 'mainwp' ); ?>
					</a>
				</li>
			</ul>
		</div>
		<?php
	}

	//Version
	function update_footer() {
		if ( ! self::isMainWP_Pages() ) {
			return;
		}

		$current_wpid = MainWP_Utility::get_current_wpid();
		if ( $current_wpid ) {
			$website  = MainWP_DB::Instance()->getWebsiteById( $current_wpid );
			$websites = array( $website );
		} else {
			$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( false, null, 'wp_sync.dtsSync DESC, wp.url ASC' ) );
		}
		ob_start();

		$cntr = 0;
		if ( is_array( $websites ) ) {
			for ( $i = 0; $i < count( $websites ); $i ++ ) {
				$website = $websites[ $i ];
				if ( $website->sync_errors == '' ) {
					$cntr ++;
					echo '<input type="hidden" name="dashboard_wp_ids[]" class="dashboard_wp_id" value="' . $website->id . '" />';
				}
			}
		} else if ( $websites !== false ) {
			while ( $website = @MainWP_DB::fetch_object( $websites ) ) {
				if ( $website->sync_errors == '' ) {
					$cntr ++;
					echo '<input type="hidden" name="dashboard_wp_ids[]" class="dashboard_wp_id" value="' . $website->id . '" />';
				}
			}
		}

		?>
		<div id="refresh-status-box" title="Syncing Websites" style="display: none; text-align: center">
			<div id="refresh-status-progress"></div>
			<span id="refresh-status-current">0</span> / <span id="refresh-status-total"><?php echo esc_html( $cntr ); ?></span>
			<span id="refresh-status-text"><?php esc_html_e( 'synced', 'mainwp' ); ?></span>

			<div style="height: 160px; overflow: auto; margin-top: 20px; margin-bottom: 10px; text-align: left" id="refresh-status-content">
				<table style="width: 100%">
					<?php
					if ( is_array( $websites ) ) {
						for ( $i = 0; $i < count( $websites ); $i ++ ) {
							$website = $websites[ $i ];
							if ( $website->sync_errors == '' ) {
								echo '<tr><td>' . MainWP_Utility::getNiceURL( $website->url ) . '</td><td style="width: 80px"><span class="refresh-status-wp" niceurl="' . MainWP_Utility::getNiceURL( $website->url ) . '" siteid="' . $website->id . '">PENDING</span></td></tr>';
							} else {
								echo '<tr class="mainwp_wp_offline"><td>' . MainWP_Utility::getNiceURL( $website->url ) . '</td><td style="width: 80px"><span class="refresh-status-wp" niceurl="' . MainWP_Utility::getNiceURL( $website->url ) . '" siteid="' . $website->id . '">DISCONNECTED</span></td></tr>';
							}
						}
					} else {
						@MainWP_DB::data_seek( $websites, 0 );
						while ( $website = @MainWP_DB::fetch_object( $websites ) ) {
							if ( $website->sync_errors == '' ) {
								echo '<tr><td>' . MainWP_Utility::getNiceURL( $website->url ) . '</td><td style="width: 80px"><span class="refresh-status-wp" niceurl="' . MainWP_Utility::getNiceURL( $website->url ) . '" siteid="' . $website->id . '">PENDING</span></td></tr>';
							} else {
								echo '<tr class="mainwp_wp_offline"><td>' . MainWP_Utility::getNiceURL( $website->url ) . '</td><td style="width: 80px"><span class="refresh-status-wp" niceurl="' . MainWP_Utility::getNiceURL( $website->url ) . '" siteid="' . $website->id . '">DISCONNECTED</span></td></tr>';
							}
						}
					}
					?>
				</table>
			</div>
			<input id="refresh-status-close" type="button" name="Close" value="Close" class="button"/>
		</div>
		<?php

		if ( ! self::isHideFooter() ) {
			self::sites_fly_menu();
			self::add_new_links();
		}

		$newOutput = ob_get_clean();

		$output = '';
		if ( ! self::isHideFooter() ) {
			$output .= '<a href="javascript:void(0)" id="dashboard_refresh" title="Sync Data" class="mainwp-margin-left-2 mainwp-green"><i class="fa fa-refresh fa-2x"></i></a> <a id="mainwp-add-new-button" class="mainwp-green mainwp-margin-left-2" title="Add New" href="javascript:void(0)"><i class="fa fa-plus fa-2x"></i></a> <a class="mainwp-green mainwp-margin-left-2" title="Get MainWP Extensions" href="https://mainwp.com/mainwp-extensions" target="_blank"><i class="fa fa-shopping-cart fa-2x"></i></a> <a class="mainwp-white mainwp-margin-left-2" title="Get Support" href="https://mainwp.com/support" target="_blank"><i class="fa fa-life-ring fa-2x"></i></a>' . '<a href="https://www.facebook.com/mainwp" class="mainwp-link-clean mainwp-margin-left-2" style="color: #3B5998;" target="_blank"><i class="fa fa-facebook-square fa-2x"></i></a> ' . ' <a href="https://twitter.com/mymainwp" class="mainwp-link-clean" target="_blank" style="color: #4099FF;"><i class="fa fa-twitter-square fa-2x"></i></a>.';
		}

		return $output . $newOutput;
	}

	function new_menus() {
		if ( MainWP_Utility::isAdmin() ) {
			//Adding the page to manage your added sites/groups
			//The first page which will display the post area etc..			
			MainWP_Updates::initMenu();
			MainWP_Manage_Sites::initMenu();
			MainWP_Post::initMenu();
			MainWP_Page::initMenu();
			MainWP_Themes::initMenu();
			MainWP_Plugins::initMenu();
			MainWP_User::initMenu();
			MainWP_Manage_Backups::initMenu();
			MainWP_Bulk_Update_Admin_Passwords::initMenu();
			MainWP_Manage_Groups::initMenu();
			MainWP_Settings::initMenu();
			MainWP_Extensions::initMenu();
			do_action( 'mainwp_admin_menu' );
			MainWP_Server_Information::initMenu();
			MainWP_About::initMenu();
			MainWP_Child_Scan::initMenu();

			MainWP_API_Settings::initMenu();
		}
	}

	//On activation install the database
	function activation() {
		delete_option( 'mainwp_requests' );
		MainWP_DB::Instance()->update();
		MainWP_DB::Instance()->install();

		//Redirect to settings page
		MainWP_Utility::update_option( 'mainwp_activated', 'yes' );
	}

	function deactivation() {
	}

	//On update update the database
	function update() {
		MainWP_DB::Instance()->update();
		MainWP_DB::Instance()->install();
	}

	function apply_filter( $filter, $value = array() ) {
		$output = apply_filters( $filter, $value );

		if ( ! is_array( $output ) ) {
			return array();
		}

		for ( $i = 0; $i < count( $output ); $i ++ ) {
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

	public function isSingleUser() {
		return true;
	}

	public function isMultiUser() {
		return ! $this->isSingleUser();
	}
}
