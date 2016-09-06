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
	public static $version = '3.0';
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
			if ( version_compare( $currentVersion, $this->current_version, '<' ) ) {
				update_option( 'mainwp_reset_user_tips', array() );
				MainWP_Utility::update_option( 'mainwp_reset_user_cookies', array() );
				//delete_option('mainwp_api_sslVerifyCertificate');
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

		add_action( 'in_plugin_update_message-' . $this->plugin_slug, array(
			$this,
			'in_plugin_update_message',
		), 10, 2 );
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
		MainWP_Manage_Backups::init();
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

		if ( !isset($alloptions['mainwp_db_version']) ) {
			$suppress = $wpdb->suppress_errors();
			$alloptions_db = $wpdb->get_results( "SELECT option_name, option_value FROM $wpdb->options WHERE option_name in ('mainwp_db_version', 'mainwp_plugin_version', 'mainwp_upgradeVersionInfo', 'mainwp_extensions', 'mainwp_manager_extensions')" );
			$wpdb->suppress_errors($suppress);
			if ( !is_array( $alloptions ) ) $alloptions = array();
			if ( is_array( $alloptions_db ) ) {
				foreach ( (array) $alloptions_db as $o ) {
					$alloptions[ $o->option_name ] = $o->option_value;
				}
				if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
					wp_cache_set( 'alloptions', $alloptions, 'options' );
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
	}

	public function in_plugin_update_message( $plugin_data, $r ) {
		if ( empty( $r ) || ! is_object( $r ) ) {
			return;
		}

		if ( property_exists( $r, 'key_status' ) && $r->key_status == 'NOK' ) {
			echo '<br />Your Log-in and Password are invalid, please update your login settings <a href="' . admin_url( 'admin.php?page=Settings' ) . '">here</a>.';
		} else if ( property_exists( $r, 'package' ) && empty( $r->package ) ) {
			echo '<br />Your update license has expired, please log into <a href="https://mainwp.com/member">the members area</a> and upgrade your support and update license.';
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

		if ( is_multisite() && ( !isset( $current_options['hide_multi_site_notice'] ) || empty( $current_options['hide_multi_site_notice'] ) ) ) {
			?>
			<div class="mainwp-events-notice mainwp_info-box-red">
				<span style="float: right;"><a class="mainwp-events-notice-dismiss" notice="multi_site"
				                               style="text-decoration: none;" href="#"><i class="fa fa-times-circle"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
				<span><i class="fa fa-exclamation-triangle fa-2x mwp-red"></i> <strong><?php esc_html_e( 'Warning! WordPress Multisite detected.', 'mainwp' ); ?></strong></span>
				<p><?php esc_html_e( 'MainWP Plugin is not designed nor fully tested on WordPress Multi Site installations. Varisous features may not work propely. We highly recommend insallting it on a Single Site installation!', 'mainwp' ); ?></p>
			</div>
			<?php
		}

		if ( MainWP_DB::Instance()->getWebsitesCount() == 0 ) {
			echo '<div id="message" class="mainwp-api-message-valid updated fade"><p><strong>MainWP is almost ready. Please <a href="' . admin_url() . 'admin.php?page=managesites&do=new">enter your first site</a>.</strong></p></div>';
			update_option( 'mainwp_first_site_events_notice', 'yes' );
		} else {
			if ( get_option( 'mainwp_first_site_events_notice' ) == 'yes' ) {
				?>
				<div class="mainwp-events-notice updated fade">
					<p>
						<span style="float: right;"><a class="mainwp-events-notice-dismiss" notice="first_site"
						                               style="text-decoration: none;" href="#"><i
									class="fa fa-times-circle"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span><span><strong><?php echo sprintf( __( 'Warning: Your setup is almost complete we recommend following the directions in the following help doc to be sure your scheduled events occur as expected %sScheduled Events%s', 'mainwp' ), '<a href="http://docs.mainwp.com/backups-scheduled-events-occurring/">', '</a>' ) ; ?></strong></span>
					</p>
				</div>
				<?php
			}
		}

		if ( $this->current_version == '2.0.22' || $this->current_version == '2.0.23' ) {
			if ( get_option( 'mainwp_installation_warning_hide_the_notice' ) == 'yes' ) {
				if ( get_option( 'mainwp_fixed_security_2022' ) != 1 ) {
					?>
					<div class="mainwp_info-box-red">
						<span><strong><?php esc_html_e( 'This update includes additional security hardening.', 'mainwp' ); ?></strong>
							<p><?php esc_html_e( 'In order to complete the security hardening process follow these steps:', 'mainwp' ); ?></p>
							<ol>
								<li><?php esc_html_e( 'Update all your Child Sites to the latest version MainWP Child.', 'mainwp' ); ?></li>
								<li><?php echo sprintf( __( 'Then Go to the MainWP Tools Page by clicking %sHere%s.', 'mainwp' ), '<a style="text-decoration: none;" href="admin.php?page=MainWPTools" "MainWP Tools">', '</a>' ) ; ?></li>
								<li><?php esc_html_e( 'Press the Establish New Connection Button and Let it Run.', 'mainwp' ); ?></li>
								<li><?php esc_html_e( 'Once completed the security hardening is done.', 'mainwp' ); ?></li>
							</ol>
							<em><?php esc_html_e( 'This message will automatically go away once you have completed the hardening process.', 'mainwp' ); ?></em>
						</span>
					</div>
					<?php
				}
			}
		}

		if ( ! isset( $current_options['trust_child'] ) || empty( $current_options['trust_child'] ) ) {
			if ( self::isMainWP_Pages() ) {
				if ( ! MainWP_Plugins::checkAutoUpdatePlugin( 'mainwp-child/mainwp-child.php' ) ) {
					?>
					<div id="" class="mainwp-events-notice error fade">
						<p>
							<span style="float: right;"><a class="mainwp-events-notice-dismiss" notice="trust_child" style="text-decoration: none;" href="#"><i class="fa fa-times-circle"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
							<strong><?php esc_html_e( 'You have not set your MainWP Child Plugins for auto updates, this is highly recommended', 'mainwp' ); ?></strong>
							&nbsp;<a id="mainwp_btn_autoupdate_and_trust" class="button-primary" href="#"><?php esc_html_e( 'Turn On', 'mainwp' ); ?></a>
							&nbsp;<a class="button" href="//docs.mainwp.com/setting-mainwp-as-a-trusted-plugin/" target="_blank"><?php esc_html_e( 'Learn More', 'mainwp' ); ?></a>
						</p>
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
					<span style="float: right;"><a class="mainwp-events-notice-dismiss" notice="request_reviews1" style="text-decoration: none;" href="#"><i class="fa fa-times-circle"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
					<span style="font-size: 14px;">
						<?php esc_html_e( 'Hi, I noticed you having been using MainWP for over 30 days and that\'s awesome!', 'mainwp' ); ?>
						<br/>
						<?php esc_html_e( 'Could you please do me a BIG favor and give it a 5-star rating on WordPress?  Reviews from users like you really help the MainWP community to grow.', 'mainwp' ); ?>
						<br/><br/>
						<?php esc_html_e( 'Thank You', 'mainwp' ); ?><br/>
						<?php esc_html_e( '~ Dennis', 'mainwp' ); ?><br/><br/>
						<a href="https://wordpress.org/support/view/plugin-reviews/mainwp#postform" target="_blank" class="button mainwp-upgrade-button"><?php esc_html_e( 'Ok, you deserve', 'mainwp' ); ?>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
						</a>&nbsp;&nbsp;&nbsp;&nbsp; <a href="" class="button mainwp-events-notice-dismiss" notice="request_reviews1"><?php esc_html_e( 'Nope, maybe later', 'mainwp' ); ?></a>
						<a href="" class="button mainwp-events-notice-dismiss" notice="request_reviews1_forever"><?php esc_html_e( 'I already did', 'mainwp' ); ?></a>
					</span>
				</p>
			</div>
		<?php } else if ( $display_request2 ) { ?>
			<div class="mainwp-events-notice updated fade">
				<p>
					<span style="float: right;"><a class="mainwp-events-notice-dismiss" notice="request_reviews2" style="text-decoration: none;" href="#"><i class="fa fa-times-circle"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
					<span style="font-size: 14px;">
						<?php esc_html_e( 'Hi, I noticed you have a few MainWP Extensions installed and that\'s awesome!', 'mainwp' ); ?>
						<br/>
						<?php esc_html_e( 'Could you please do me a BIG favor and give it a 5-star rating on WordPress?  Reviews from users like you really help the MainWP community to grow.', 'mainwp' ); ?>
						<br/><br/>
						<?php esc_html_e( 'Thank You', 'mainwp' ); ?><br/>
						<?php esc_html_e( '~ Dennis', 'mainwp' ); ?><br/><br/>
						<a href="https://wordpress.org/support/view/plugin-reviews/mainwp#postform" target="_blank" class="button mainwp-upgrade-button">
							<?php esc_html_e( 'Ok, you deserve', 'mainwp' ); ?>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
							<i class="fa fa-star"></i>
						</a>
						&nbsp;&nbsp;&nbsp;&nbsp;
						<a href="" class="button mainwp-events-notice-dismiss" notice="request_reviews2"><?php esc_html_e( 'Nope, maybe later', 'mainwp' ); ?></a>
						<a href="" class="button mainwp-events-notice-dismiss" notice="request_reviews2_forever"><?php esc_html_e( 'I already did', 'mainwp' ); ?></a>
					</span>
				</p>
			</div>
			<?php
		}
	}

	public function getVersion() {
		return $this->current_version;
	}


	public function check_update_custom( $transient ) {
		if ( isset( $_POST['action'] ) && ( ( $_POST['action'] == 'update-plugin' ) || ( $_POST['action'] == 'update-selected' ) ) ) {
			$extensions = MainWP_Extensions::getExtensions( array( 'activated' => true ) );
			if ( defined( 'DOING_AJAX' ) && isset( $_POST['plugin'] ) && $_POST['action'] == 'update-plugin' ) {
				$plugin_slug = $_POST['plugin'];
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
		$obj->url         = 'http://mainwp.com/';
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
		//        if (empty($transient->checked)) {
		//            return $transient;
		//        }

		if ( ( $this->upgradeVersionInfo == null ) || ( ( time() - $this->upgradeVersionInfo->updated ) > 60 * 60 * 12 ) ) {
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
		if ( ! isset( $arg->slug ) || ( $arg->slug == '' ) ) {
			return false;
		}

		if ( $arg->slug === $this->slug ) {
			return false;
		}

		$result   = MainWP_Extensions::getSlugs();
		$slugs    = $result['slugs'];
		$am_slugs = $result['am_slugs'];

		if ( $am_slugs != '' ) {
			$am_slugs = explode( ',', $am_slugs );
			if ( in_array( $arg->slug, $am_slugs ) ) {
				return MainWP_API_Settings::getPluginInformation( $arg->slug );
			}
		}

		return false;
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

		$mainwpLastAutomaticUpdate = get_option( 'mainwp_updatescheck_last' );
		if ( $mainwpLastAutomaticUpdate == date( 'd/m/Y' ) ) {
			MainWP_Logger::Instance()->debug( 'CRON :: updates check :: already updated today' );

			return;
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
			$busyCounter = MainWP_DB::Instance()->getWebsitesCountWhereDtsAutomaticSyncSmallerThenStart();

			if ( $busyCounter == 0 ) {
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
				if ( ! $sendMail ) {
					MainWP_Logger::Instance()->debug( 'CRON :: updates check :: sendMail is false' );

					return;
				}

				if ( $mainwpAutomaticDailyUpdate !== false && $mainwpAutomaticDailyUpdate != 0 ) {
					//Create a nice email to send
					$email = get_option( 'mainwp_updatescheck_mail_email' );
					MainWP_Logger::Instance()->debug( 'CRON :: updates check :: send mail to ' . $email );
					if ( $email != false && $email != '' ) {
						$mail = '<div>We noticed the following updates are available on your MainWP Dashboard. (<a href="' . site_url() . '">' . site_url() . '</a>)</div>
                                 <div></div>
                                 ' . $mail . '
                                 Update Key: (<strong><span style="color:#008000">Trusted</span></strong>) will be auto updated within 24 hours. (<strong><span style="color:#ff0000">Not Trusted</span></strong>) you will need to log into your Main Dashboard and update
                                 <div> </div>
                                 <div>If your MainWP is configured to use Auto Updates these upgrades will be installed in the next 24 hours. To find out how to enable automatic updates please see the FAQs below.</div>
                                 <div><a href="http://docs.mainwp.com/marking-a-plugin-as-trusted/" style="color:#446200" target="_blank">http://docs.mainwp.com/marking-a-plugin-as-trusted/</a></div>
                                 <div><a href="http://docs.mainwp.com/marking-a-theme-as-trusted/" style="color:#446200" target="_blank">http://docs.mainwp.com/marking-a-theme-as-trusted/</a></div>
                                 <div><a href="http://docs.mainwp.com/marking-a-sites-wp-core-updates-as-trusted/" style="color:#446200" target="_blank">http://docs.mainwp.com/marking-a-sites-wp-core-updates-as-trusted/</a></div>';
						wp_mail( $email, 'MainWP - Trusted Updates', MainWP_Utility::formatEmail( $email, $mail ), array(
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

				/** Check core upgrades **/
				$websiteLastCoreUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'last_wp_upgrades' ), true );
				$websiteCoreUpgrades     = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'wp_upgrades' ), true );

				//Run over every update we had last time..
				if ( isset( $websiteCoreUpgrades['current'] ) ) {
					$infoTxt    = '<a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . stripslashes( $website->name ) . '</a> - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'];
					$infoNewTxt = '*NEW* <a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . stripslashes( $website->name ) . '</a> - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'];
					$newUpdate  = ! ( isset( $websiteLastCoreUpgrades['current'] ) && ( $websiteLastCoreUpgrades['current'] == $websiteCoreUpgrades['current'] ) && ( $websiteLastCoreUpgrades['new'] == $websiteCoreUpgrades['new'] ) );
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

					$infoTxt    = '<a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . stripslashes( $website->name ) . '</a> - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'];
					$infoNewTxt = '*NEW* <a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . stripslashes( $website->name ) . '</a> - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'];

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


				//Loop over last plugins & current plugins, check if we need to upgrade them..
				$user  = get_userdata( $website->userid );
				$email = MainWP_Utility::getNotificationEmail( $user );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_email', $email );
				MainWP_DB::Instance()->updateWebsiteSyncValues( $website->id, array( 'dtsAutomaticSync' => time() ) );
				MainWP_DB::Instance()->updateWebsiteOption( $website, 'last_wp_upgrades', json_encode( $websiteCoreUpgrades ) );
				MainWP_DB::Instance()->updateWebsiteOption( $website, 'last_plugin_upgrades', $website->plugin_upgrades );
				MainWP_DB::Instance()->updateWebsiteOption( $website, 'last_theme_upgrades', $website->theme_upgrades );
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

			if ( get_option( 'mainwp_automaticDailyUpdate' ) != 1 ) {
				return;
			}

			//Check if backups are required!
			if ( get_option( 'mainwp_backup_before_upgrade' ) == 1 ) {
				$sitesCheckCompleted = get_option( 'mainwp_automaticUpdate_backupChecks' );
				if ( ! is_array( $sitesCheckCompleted ) ) {
					$sitesCheckCompleted = array();
				}

				$websitesToCheck = array();
				foreach ( $pluginsToUpdateNow as $websiteId => $slugs ) {
					$websitesToCheck[ $websiteId ] = true;
				}

				foreach ( $themesToUpdateNow as $websiteId => $slugs ) {
					$websitesToCheck[ $websiteId ] = true;
				}

				foreach ( $coreToUpdateNow as $websiteId ) {
					$websitesToCheck[ $websiteId ] = true;
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

					$backupRequired = ( $lastBackup < ( time() - ( 7 * 24 * 60 * 60 ) ) ? true : false );

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
			do_action( 'mainwp_cronupdatecheck_action', $pluginsNewUpdate, $pluginsToUpdate, $pluginsToUpdateNow, $themesNewUpdate, $themesToUpdate, $themesToUpdateNow, $coreNewUpdate, $coreToUpdate, $coreToUpdateNow );
		}
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
		if ( get_option( 'mainwp_seo' ) != 1 ) {
			return;
		}

		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getWebsitesStatsUpdateSQL() );

		$start = time();
		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			if ( ( $start - time() ) > ( 60 * 60 * 2 ) ) {
				//two hours passed, next cron will start!
				break;
			}

			$errors = false;
			if ( !$errors ) {
				$indexed = MainWP_Utility::getGoogleCount( $website->url );

				if ($indexed === NULL) {
					$errors = true;
				}
			}

			if ( !$errors ) {
				$alexia = MainWP_Utility::getAlexaRank( $website->url );

				if ($alexia === NULL) {
					$errors = true;
				}
			}

			$pageRank = 0;//MainWP_Utility::getPagerank($website->url);


			$newIndexed = ($errors ? $website->indexed : $indexed);
			$oldIndexed = ($errors ? $website->indexed_old : $website->indexed);
			$newAlexia = ($errors ? $website->alexia : $alexia);
			$oldAlexia = ($errors ? $website->alexia_old : $website->alexia);
			$statsUpdated = ($errors ? $website->statsUpdate : time());

			MainWP_DB::Instance()->updateWebsiteStats( $website->id, $pageRank, $newIndexed, $newAlexia, $website->pagerank, $oldIndexed, $oldAlexia, $statsUpdated );

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
			sleep( 3 );
		}
		@MainWP_DB::free_result( $websites );
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
		MainWP_Manage_Backups::initMenuSubPages();

		do_action( 'mainwp_admin_menu_sub' );
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
				background: #333 !important;;
				position: fixed !important;
				bottom: 0 !important;;
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
		$messages['post'][98] = 'Wordpress Seo values saved.';
		$messages['post'][99] = 'You have to select the sites you wish to publish to.';

		return $messages;
	}

	function mainwp_warning_notice() {
		if ( get_option( 'mainwp_installation_warning_hide_the_notice' ) == 'yes' ) {
			return;
		}
		?>
		<div id="mainwp-installation-warning" class="mainwp_info-box-red">
			<h3><?php esc_html_e( 'Stop! Before you continue,', 'mainwp' ); ?></h3>
			<strong><?php esc_html_e( 'We HIGHLY recommend a NEW WordPress install for your Main Dashboard.', 'mainwp' ); ?></strong><br/><br/>
			<?php echo sprintf( __( 'Using a new WordPress install will help to cut down on Plugin Conflicts and other issues that can be caused by trying to run your MainWP Main Dashboard off an active site. Most hosting companies provide free subdomains %s and we recommend creating one if you do not have a specific dedicated domain to run your Network Main Dashboard.', 'mainwp' ), '("<strong>demo.yourdomain.com</strong>")' ) ; ?><br/><br/>
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
			wp_redirect( admin_url( 'admin.php?page=managesites&do=new' ) );

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

		if ( isset( $_GET['page'] ) && ( $_GET['page'] == 'PluginsManage' || $_GET['page'] == 'ThemesManage' ) ) {
			wp_enqueue_script( 'mainwp-fixedtable', MAINWP_PLUGIN_URL . 'js/tableHeadFixer.js', array( 'jquery', 'jquery-ui-core' ), $this->current_version );
		}

		if ( ! current_user_can( 'update_core' ) ) {
			remove_action( 'admin_notices', 'update_nag', 3 );
		}
	}

	public function admin_redirects() {
		$_pos = strlen( $_SERVER['REQUEST_URI'] ) - strlen( '/wp-admin/' );
		$hide_menus = get_option( 'mwp_setup_hide_wp_menus', array() );
		if ( ! is_array( $hide_menus ) ) {
			$hide_menus = array(); }
		$hide_wp_dashboard = in_array( 'dashboard', $hide_menus );
		if ( ($hide_wp_dashboard && strpos( $_SERVER['REQUEST_URI'], 'index.php' ) ) || (strpos( $_SERVER['REQUEST_URI'], '/wp-admin/' ) !== false && strpos( $_SERVER['REQUEST_URI'], '/wp-admin/' ) == $_pos ) ) {
			wp_redirect( admin_url( 'admin.php?page=mainwp_tab' ) );
			die();
		}

		if ( ! get_transient( '_mainwp_activation_redirect' ) || is_network_admin() ) {
			return;
		}

		delete_transient( '_mainwp_activation_redirect' );

		if ( ! empty( $_GET['page'] ) && in_array( $_GET['page'], array( 'mainwp-setup' ) ) ) {
			return;
		}

		$quick_setup = get_site_option('mainwp_run_quick_setup', false);
		if ($quick_setup == 'yes') {
			wp_redirect( admin_url( 'admin.php?page=mainwp-setup' ) );
			exit;
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
				}
			} else if ( $_GET['page'] == 'Settings' ) {
				if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'Settings' ) ) {
					if ( isset( $_POST['mainwp_primaryBackup'] ) ) {
						MainWP_Utility::update_option( 'mainwp_primaryBackup', $_POST['mainwp_primaryBackup'] );
					}
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
			$userExtension->site_view = ( empty( $_POST['select_mainwp_options_siteview'] ) ? 0 : 1 );
			MainWP_DB::Instance()->updateUserExtension( $userExtension );
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
		wp_register_script( 'mainwp-admin', MAINWP_PLUGIN_URL . 'js/mainwp-admin.js', array(), $this->current_version );

		if ( MainWP_Utility::isAdmin() ) {
			wp_enqueue_script( 'mainwp-admin' );
		}

		if ( self::isMainWP_Pages() ) {
			wp_enqueue_script( 'mainwp-rightnow', MAINWP_PLUGIN_URL . 'js/mainwp-rightnow.js', array(), $this->current_version );
			wp_enqueue_script( 'mainwp-managesites', MAINWP_PLUGIN_URL . 'js/mainwp-managesites.js', array(), $this->current_version );
			wp_enqueue_script( 'mainwp-extensions', MAINWP_PLUGIN_URL . 'js/mainwp-extensions.js', array(), $this->current_version );
		}

		wp_enqueue_script( 'mainwp-ui', MAINWP_PLUGIN_URL . 'js/mainwp-ui.js', array(), $this->current_version );
		wp_enqueue_script( 'mainwp-fileuploader', MAINWP_PLUGIN_URL . 'js/fileuploader.js', array(), $this->current_version );
		wp_enqueue_script( 'mainwp-date', MAINWP_PLUGIN_URL . 'js/date.js', array(), $this->current_version );
		wp_enqueue_script( 'mainwp-tablesorter', MAINWP_PLUGIN_URL . 'js/jquery.tablesorter.min.js', array(), $this->current_version );
		wp_enqueue_script( 'mainwp-tablesorter-pager', MAINWP_PLUGIN_URL . 'js/jquery.tablesorter.pager.js', array(), $this->current_version );
		wp_enqueue_script( 'mainwp-moment', MAINWP_PLUGIN_URL . 'js/moment.min.js', array(), $this->current_version );
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

		wp_enqueue_style( 'mainwp-filetree', MAINWP_PLUGIN_URL . 'css/jqueryFileTree.css', array(), $this->current_version );
		wp_enqueue_style( 'mainwp-font-awesome', MAINWP_PLUGIN_URL . 'css/font-awesome/css/font-awesome.min.css', array(), $this->current_version );
	}

	function admin_head() {
		echo '<script type="text/javascript" src="' . MAINWP_PLUGIN_URL . 'js/jsapi.js' . '"></script>';
		echo '<script type="text/javascript">
  				google.load("visualization", "1", {packages:["corechart"]});
			</script>';
		echo '<script type="text/javascript">var mainwp_ajax_nonce = "' . wp_create_nonce( 'mainwp_ajax' ) . '"</script>';
		echo '<script type="text/javascript" src="' . MAINWP_PLUGIN_URL . 'js/FileSaver.js' . '"></script>';
		echo '<script type="text/javascript" src="' . MAINWP_PLUGIN_URL . 'js/jqueryFileTree.js' . '"></script>';
	}

	function admin_body_class( $class_string ) {
		$screen = get_current_screen();

		if ( $screen && strpos( $screen->base, 'mainwp_' ) !== false ) {
			$class_string .= 'mainwp-ui';
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
								$favi     = MainWP_DB::Instance()->getWebsiteOption( $website, 'favi_icon', '' );
								$favi_url = MainWP_Utility::get_favico_url( $favi, $website );
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

		return wp_kses_post( '<a href="javascript:void(0)" id="mainwp-sites-menu-button" class="mainwp-white mainwp-right-margin-2"><i class="fa fa-globe fa-2x"></i></a>' . '<span style="font-size: 14px;"><i class="fa fa-info-circle"></i> ' . __( 'Currently managing ', 'mainwp' ) . MainWP_DB::Instance()->getWebsitesCount() . __( ' child sites with MainWP ', 'mainwp' ) . $this->current_version . __( ' version. ', 'mainwp' ) . '</span>' );
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
			$output .= '<a href="javascript:void(0)" id="dashboard_refresh" title="Sync Data" class="mainwp-left-margin-2 mainwp-green"><i class="fa fa-refresh fa-2x"></i></a> <a id="mainwp-add-new-button" class="mainwp-blue mainwp-left-margin-2" title="Add New" href="javascript:void(0)"><i class="fa fa-plus fa-2x"></i></a> <a class="mainwp-red mainwp-left-margin-2" title="Get MainWP Extensions" href="https://mainwp.com/extensions/" target="_blank"><i class="fa fa-shopping-cart fa-2x"></i></a> <a class="mainwp-white mainwp-left-margin-2" title="Get Support" href="http://support.mainwp.com" target="_blank"><i class="fa fa-life-ring fa-2x"></i></a>' . '<a href="https://www.facebook.com/mainwp" class="mainwp-link-clean mainwp-left-margin-2" style="color: #3B5998;" target="_blank"><i class="fa fa-facebook-square fa-2x"></i></a> ' . ' <a href="https://twitter.com/mymainwp" class="mainwp-link-clean" target="_blank" style="color: #4099FF;"><i class="fa fa-twitter-square fa-2x"></i></a>.';
		}

		return $output . $newOutput;
	}

	function new_menus() {
		if ( MainWP_Utility::isAdmin() ) {
			//Adding the page to manage your added sites/groups
			//The first page which will display the post area etc..
			MainWP_Security_Issues::initMenu();
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
			MainWP_Documentation::initMenu();
			MainWP_Server_Information::initMenu();
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
