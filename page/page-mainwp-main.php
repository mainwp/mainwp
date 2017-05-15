<?php

class MainWP_Main {
	public static function getClassName() {
		return __CLASS__;
	}

	protected static $singleton = null;

	public static function get() {
		if ( self::$singleton == null ) {
			self::$singleton = new MainWP_Main();
		}

		return self::$singleton;
	}

	public function __construct() {
		//Prevent conflicts
		add_filter( 'screen_layout_columns', array( &$this, 'on_screen_layout_columns' ), 10, 2 );
		add_action( 'admin_menu', array( &$this, 'on_admin_menu' ) );
		add_action( 'admin_post_save_howto_testPages_general', array( &$this, 'on_save_changes' ) );
	}

	function on_screen_layout_columns( $columns, $screen ) {
		if ( $screen == $this->dashBoard ) {
			$columns[ $this->dashBoard ] = 3; //Number of supported columns
		}

		return $columns;
	}

	function on_admin_menu() {
		if ( MainWP_Utility::isAdmin() ) {
			global $current_user;
			delete_user_option( $current_user->ID, 'screen_layout_toplevel_page_mainwp_tab' );
			$this->dashBoard = add_menu_page( 'MainWP', 'MainWP', 'read', 'mainwp_tab', array(
				$this,
				'on_show_page',
			), plugins_url( 'images/mainwpicon.png', dirname( __FILE__ ) ), '2.00001' );

			if ( mainwp_current_user_can( 'dashboard', 'access_global_dashboard' ) ) {
				add_submenu_page( 'mainwp_tab', 'MainWP', __( 'Overview', 'mainwp' ), 'read', 'mainwp_tab', array(
					$this,
					'on_show_page',
				) );
			}

			$val = get_user_option( 'screen_layout_' . $this->dashBoard );
			if ( ! MainWP_Utility::ctype_digit( $val ) ) {
				update_user_option( $current_user->ID, 'screen_layout_' . $this->dashBoard, 2, true );
			}
			add_action( 'load-' . $this->dashBoard, array( &$this, 'on_load_page' ) );
            MainWP_Main::init_left_menu();
		}
		//        else
		//        {
		//            $this->dashBoard = add_menu_page('MainWP', 'MainWP', 'read', 'mainwp_tab', array($this, 'require_registration'), plugins_url('images/mainwpicon.png', dirname(__FILE__)), '2.0001');
		//        }
	}

    static function init_left_menu( $subPages = array() ) {
        $init_leftmenu = array(
            array(  'title' => __('MainWP Dashboard', 'mainwp'),
                    'key' => 'mainwp_tab',
                    'href' => 'admin.php?page=mainwp_tab'
                ),
            array(  'title' => __('MainWP Extensions', 'mainwp'),
                    'key' => 'Extensions',
                    'href' => 'admin.php?page=Extensions'
                ),
            array(  'title' => __('Child Sites', 'mainwp'),
                    'key' => 'childsites_menu',
                    'href' => 'admin.php?page=managesites'
                )
        );

        foreach($init_leftmenu as $item) {
            MainWP_System::add_left_menu($item['title'], $item['key'], $item['href']);
        }
        MainWP_System::add_sub_left_menu(__('Overview', 'mainwp'), 'mainwp_tab', 'mainwp_tab', 'admin.php?page=mainwp_tab', '<i class="fa fa-tachometer"></i>', '' );
    }

	function on_load_page() {
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'wp-lists' );
		wp_enqueue_script( 'postbox' );
		wp_enqueue_script( 'dashboard' );
		wp_enqueue_script( 'widgets' );		

		self::add_meta_boxes( $this->dashBoard );
	}

	static function add_meta_boxes( $page ) {
		$i = 1;
		add_meta_box( $page . '-contentbox-' . $i ++, MainWP_Right_Now::getName(), array(
			MainWP_Right_Now::getClassName(),
			'render',
		), $page, 'normal', 'core' );
		if ( !MainWP_Utility::get_current_wpid() ) {
			add_meta_box( $page . '-contentbox-' . $i ++, MainWP_Connection_Status::getName(), array(
				MainWP_Connection_Status::getClassName(),
				'render',
			), $page, 'normal', 'core' );
		}
		if ( mainwp_current_user_can( 'dashboard', 'manage_posts' ) ) {
			add_meta_box( $page . '-contentbox-' . $i ++, MainWP_Recent_Posts::getName(), array(
				MainWP_Recent_Posts::getClassName(),
				'render',
			), $page, 'normal', 'core' );
		}
		if ( mainwp_current_user_can( 'dashboard', 'manage_pages' ) ) {
			add_meta_box( $page . '-contentbox-' . $i ++, MainWP_Recent_Pages::getName(), array(
				MainWP_Recent_Pages::getClassName(),
				'render',
			), $page, 'normal', 'core' );
		}
		if ( mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) {
			add_meta_box( $page . '-contentbox-' . $i ++, MainWP_Security_Issues::getMetaboxName(), array(
				MainWP_Security_Issues::getClassName(),
				'renderMetabox',
			), $page, 'normal', 'core' );
		}
		global $mainwpUseExternalPrimaryBackupsMethod;
		if ( empty( $mainwpUseExternalPrimaryBackupsMethod ) ) {
            if (get_option('mainwp_enableLegacyBackupFeature')) {
                add_meta_box( $page . '-contentbox-' . $i ++, MainWP_Backup_Tasks::getName(), array(
                        MainWP_Backup_Tasks::getClassName(),
                        'render',
                ), $page, 'normal', 'core' );
            }
		}
		add_meta_box( $page . '-contentbox-' . $i ++, MainWP_Extensions_Widget::getName(), array(
			MainWP_Extensions_Widget::getClassName(),
			'render',
		), $page, 'normal', 'core' );			

		add_meta_box( $page . '-contentbox-' . $i ++, MainWP_Helpful_Links_Widget::getName(), array(
			MainWP_Helpful_Links_Widget::getClassName(),
			'render',
		), $page, 'normal', 'core' );

		add_meta_box( $page . '-contentbox-' . $i ++, MainWP_Blogroll_Widget::getName(), array(
			MainWP_Blogroll_Widget::getClassName(),
			'render',
		), $page, 'normal', 'core' );	

		/**
		 * This hook allows you to add extra metaboxes to the dashboard via the 'mainwp-getmetaboxes' filter.
		 * @link http://codex.mainwp.com/#mainwp-getmetaboxes
		 */
		$extMetaBoxs = MainWP_System::Instance()->apply_filter( 'mainwp-getmetaboxes', array() );
		$extMetaBoxs = apply_filters( 'mainwp-getmetaboxs', $extMetaBoxs );
		foreach ( $extMetaBoxs as $metaBox ) {
			add_meta_box( $page . '-contentbox-' . $i ++, $metaBox['metabox_title'], $metaBox['callback'], $page, 'normal', 'core' );
		}
	}

	function require_registration() {
		?>
		<h2><?php _e( 'Overview', 'mainwp' ); ?></h2>
		<?php _e( 'MainWP needs to be activated before using', 'mainwp' ); ?> -
		<a href="<?php echo admin_url(); ?>admin.php?page=Settings"><?php _e( 'Activate here', 'mainwp' ); ?></a>.
		<?php
	}

	function on_show_page() {
		if ( ! mainwp_current_user_can( 'dashboard', 'access_global_dashboard' ) ) {
			mainwp_do_not_have_permissions( __( 'global dashboard', 'mainwp' ) );

			return;
		}

		global $screen_layout_columns;
        MainWP_UI::render_left_menu();
		?>
		<div id="mainwp_tab-general" class="mainwp-wrap">

			<h1 class="mainwp-margin-top-0"><i class="fa fa-tachometer"></i> <?php _e( 'Overview', 'mainwp' ); ?></h1>
			<br/>

			<?php if ( MainWP_Utility::showUserTip( 'mainwp-dashboard-tips' ) ) { ?>
				<div id="mainwp-tip-zone">
					<div class="mainwp-tips mainwp-notice mainwp-notice-blue">
						<span class="mainwp-tip" id="mainwp-dashboard-tips"><strong><?php _e( 'MainWP Tip', 'mainwp' ); ?>: </strong><?php _e( 'You can move the Widgets around to fit your needs and even adjust the number of columns by selecting "Screen Options" on the top right.', 'mainwp' ); ?></span><span><a href="#" class="mainwp-dismiss"><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss', 'mainwp' ); ?>
							</a></span></div>
				</div>
			<?php } ?>

			<?php
			$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( false, null, 'wp_sync.dtsSync DESC, wp.url ASC' ) );
			self::renderDashboardBody( $websites, $this->dashBoard, $screen_layout_columns );
			@MainWP_DB::free_result( $websites );
			?>
		</div>
		<?php
	}

	public static function renderDashboardBody( $websites, $pDashboard, $pScreenLayout , $hideShortcuts = false) {
		$opts           = get_option( 'mainwp_opts_showhide_sections', false );
		$hide_shortcuts = ( is_array( $opts ) && isset( $opts['welcome_shortcuts'] ) && $opts['welcome_shortcuts'] == 'hide' ) ? true : false;
		$current_screen = get_current_screen();
		?>
		<form action="admin-post.php" method="post">
			<?php wp_nonce_field( 'mainwp_tab-general' ); ?>
			<?php wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false ); ?>
			<?php wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false ); ?>
			<input type="hidden" name="action" value="save_howto_testPages_general"/>

			<!-- Welcome Widget -->
			<?php
			if ( $current_screen->id === 'renderUpdatesTour' ) {
				MainWP_Tours::renderOverviewTour();
			}
			else {
				MainWP_Tours::renderOverviewTour();
			}
			?>
			<div id="mainwp-welocme-bar" class="welcome-panel">
				<div id="mainwp-welocme-bar-top" class="mainwp-padding-10">
					<div class="mainwp-cols-2 mainwp-left mainwp-padding-top-10">
						<?php
						$current_wp_id = MainWP_Utility::get_current_wpid();
						$website       = null;
						if ( ! empty( $current_wp_id ) ) {
							$website = $websites[0];
						}

						$imgfavi = '';
						if ( $website !== null ) {
							if ( get_option( 'mainwp_use_favicon', 1 ) == 1 ) {
								$favi_url = MainWP_Utility::get_favico_url( $website );
								$imgfavi  = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
							}
						}
						if ($website !== null) {
							if ( ( time() - $website->dtsSync ) > ( 60 * 60 * 24 ) ) {
								?>
								<h3 class="mainwp-margin-top-0"><i class="fa fa-flag"></i> <?php _e( 'Your MainWP Dashboard has not been synced for 24 hours!', 'mainwp' ); ?></h3>
								<p class="about-description"><?php _e( 'Click the Sync Data button to get the latest data from child sites.', 'mainwp' ); ?></p>
								<?php
							} else {
								?>
								<h3 class="mainwp-margin-top-0"><?php echo sprintf( __( 'Welcome to %s dashboard!', 'mainwp' ), stripslashes( $website->name ) ); ?></h3>
								<p class="about-description"><?php echo sprintf( __( 'This information is only for %s%s', 'mainwp' ), $imgfavi, MainWP_Utility::getNiceURL( $website->url, true ) ); ?></p>
								<?php
							}
						} else {
							$result = MainWP_DB::Instance()->getLastSyncStatus();
                            $sync_status = $result['sync_status'];
                            $last_sync = $result['last_sync'];

							if ( $sync_status === 'not_synced' ) {
								?>
								<h3 class="mainwp-margin-top-0"><i class="fa fa-flag"></i> <?php _e( 'Your MainWP Dashboard has not been synced for 24 hours!', 'mainwp' ); ?></h3>
								<p class="about-description"><?php _e( 'Click the Sync Data button to get the latest data from child sites.', 'mainwp' ); ?></p>
								<?php
							} else if ( $sync_status === 'all_synced' ) {
                                $now = time();
                                $last_sync_all = get_option('mainwp_last_synced_all_sites', 0);
                                if ($last_sync_all == 0)
                                    $last_sync_all = $last_sync;
								?>
                                <h3 class="mainwp-margin-top-0"><?php echo empty($last_sync) ? __( 'All sites have been synced within the last 24 hours', 'mainwp' ) . '!' : sprintf(__('Sites last synced at %s (%s ago)', 'mainwp'), MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $last_sync_all )), human_time_diff( MainWP_Utility::getTimestamp( $last_sync_all ), MainWP_Utility::getTimestamp( $now ) )) ; ?></h3>
								<p class="about-description"><?php echo __( 'Management is more than just updates!', 'mainwp' ); ?></p>
								<?php
							} else {
								?>
								<h3 class="mainwp-margin-top-0"><i class="fa fa-flag"></i> <?php echo __( "Some child sites didn't sync correctly!", 'mainwp' ); ?></h3>
								<p class="about-description"><?php echo __( 'Check the Connection status widget to review sites that are not synchronized.', 'mainwp' ); ?></p>
								<?php
							}
						}
						?>
					</div>
					<div class="mainwp-cols-2 mainwp-right mainwp-t-align-right">
						<a class="button-hero button mainwp-upgrade-button mainwp-large" id="dashboard_refresh" title="<?php echo MainWP_Right_Now::renderLastUpdate(); ?>"><i class="fa fa-refresh"></i> <?php _e( 'Sync Data with child sites', 'mainwp' ); ?></a>
						<a class="button-hero button-primary button mainwp-large" target="_blank" href="https://mainwp.com/mainwp-extensions"><i class="fa fa-cart-plus"></i> <?php _e( 'Get new extensions', 'mainwp' ); ?></a>
					</div>
					<div class="mainwp-clear"></div>
				</div>
				<?php if (!$hideShortcuts) { ?>
					<div class="mainwp-postbox-actions-bottom">
						<div>
							<a id="mainwp-link-showhide-welcome-shortcuts" status="<?php echo( $hide_shortcuts ? 'hide' : 'show' ); ?>" href="#">
								<i class="fa fa-eye-slash" aria-hidden="true"></i> <?php echo( $hide_shortcuts ? __( 'Show Quick Start shortcuts', 'mainwp' ) : __( 'Hide Quick Start shortcuts', 'mainwp' ) ); ?>
							</a>
						</div>
						<div id="mainwp-welcome-bar-shotcuts" style="<?php echo( $hide_shortcuts ? 'display: none;' : '' ); ?>">
							<div class="welcome-panel-column-container">
								<div class="welcome-panel-column">
									<h4><?php _e( 'Get started', 'mainwp' ); ?></h4>
									<ul>
										<li>
											<a href="<?php echo get_admin_url(); ?>admin.php?page=Settings"><i class="fa fa-cogs"></i> <?php _e( 'Check MainWP Dashboard settings', 'mainwp' ); ?></a>
										</li>
										<li>
											<a href="<?php echo get_admin_url(); ?>admin.php?page=managesites&do=new"><i class="fa fa-globe"></i> <?php _e( 'Add new site', 'mainwp' ); ?></a>
										</li>
										<li>
											<a href="<?php echo get_admin_url(); ?>admin.php?page=ManageGroups"><i class="fa fa-globe"></i> <?php _e( 'Create child site groups', 'mainwp' ); ?></a>
										</li>
										<li>
											<a href="<?php echo get_admin_url(); ?>admin.php?page=Extensions"><i class="fa fa-plug"></i> <?php _e( 'Browse MainWP Extensions', 'mainwp' ); ?></a>
										</li>
										

									</ul>
								</div>
								<div class="welcome-panel-column">
									<h4><?php _e( 'Next steps', 'mainwp' ); ?></h4>
									<ul>
										<li>
											<a href="<?php echo get_admin_url(); ?>admin.php?page=PostBulkAdd"><i class="fa fa-file-text"></i> <?php _e( 'Add post to child site(s)', 'mainwp' ); ?></a>
										</li>
										<li>
											<a href="<?php echo get_admin_url(); ?>admin.php?page=PageBulkAdd"><i class="fa fa-file"></i> <?php _e( 'Add page to child site(s)', 'mainwp' ); ?></a>
										</li>
										<li>
											<a href="<?php echo get_admin_url(); ?>admin.php?page=PluginsInstall"><i class="fa fa-plug"></i> <?php _e( 'Add plugin to child site(s)', 'mainwp' ); ?></a>
										</li>
										<li>
											<a href="<?php echo get_admin_url(); ?>admin.php?page=ThemesInstall"><i class="fa fa-paint-brush"></i> <?php _e( 'Add theme to child site(s)', 'mainwp' ); ?></a>
										</li>
									</ul>
								</div>
								<div class="welcome-panel-column welcome-panel-last">
									<h4><?php _e( 'More actions', 'mainwp' ); ?></h4>
									<ul>
										<li>
											<a href="<?php echo get_admin_url(); ?>admin.php?page=managesites&do=test"><i class="fa fa-globe"></i> <?php _e( 'Test connection', 'mainwp' ); ?></a>
										</li>
										<li>
											<a href="<?php echo get_admin_url(); ?>admin.php?page=Extensions"><i class="fa fa-plug"></i> <?php _e( 'Manage extensions', 'mainwp' ); ?></a>
										</li>
										<li>
											<a href="<?php echo get_admin_url(); ?>admin.php?page=ServerInformation"><i class="fa fa-server"></i> <?php _e( 'Check MainWP requirements', 'mainwp' ); ?></a>
										</li>
										<li>
											<a href="<?php echo get_admin_url(); ?>admin.php?page=DashboardOptions"><i class="fa fa-cogs"></i> <?php _e( 'Set your preferences', 'mainwp' ); ?></a>
										</li>
									</ul>
								</div>
								<div class="mainwp-clear"></div>
							</div>
						</div>
					</div>
				<?php } ?>
			</div>
                        <div id="mainwp_main_errors" class="mainwp_error error"></div>
		</form>
                
		<?php if ($hideShortcuts) return; ?>

		<div id="mainwp-dashboard-info-box">
			<?php if ( empty( $current_wp_id ) && MainWP_Twitter::enabledTwitterMessages() ) {
				$filter = array(
					'upgrade_everything',
					'upgrade_all_wp_core',
					'upgrade_all_plugins',
					'upgrade_all_themes',
				);
				foreach ( $filter as $what ) {
					$twitters = MainWP_Twitter::getTwitterNotice( $what );
					if ( is_array( $twitters ) ) {
						foreach ( $twitters as $timeid => $twit_mess ) {
							if ( ! empty( $twit_mess ) ) {
								$sendText = MainWP_Twitter::getTwitToSend($what, $timeid);
								if (!empty($sendText)) {
									?>
									<div class="mainwp-tips mainwp-notice mainwp-notice-blue twitter">
									<span class="mainwp-tip" twit-what="<?php echo $what; ?>"
									      twit-id="<?php echo $timeid; ?>"><?php echo $twit_mess; ?></span>&nbsp;<?php MainWP_Twitter::genTwitterButton($sendText); ?>
										<span><a href="#" class="mainwp-dismiss-twit mainwp-right"><i
													class="fa fa-times-circle"></i> <?php _e('Dismiss', 'mainwp'); ?>
											</a></span></div>
									<?php
								}
							}
						}
					}
				}
				?>
			<?php } ?>
		</div>


		<div id="dashboard-widgets-wrap">

			<?php require_once( ABSPATH . 'wp-admin/includes/dashboard.php' );

			wp_dashboard(); ?>

			<div class="clear"></div>
		</div><!-- dashboard-widgets-wrap -->
		<?php
	}

	//executed if the post arrives initiated by pressing the submit button of form
	function on_save_changes() {
		//user permission check
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Cheatin&#8217; uh?' ) );
		}
		//cross check the given referer
		check_admin_referer( 'mainwp_tab-general' );

		//process here your on $_POST validation and / or option saving

		//lets redirect the post request into get request (you may add additional params at the url, if you need to show save results
		wp_redirect( $_POST['_wp_http_referer'] );
	}
}
