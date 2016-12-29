<?php

class MainWP_Settings {
	public static function getClassName() {
		return __CLASS__;
	}

	public static $subPages;

	public static function init() {
		/**
		 * This hook allows you to render the Settings page header via the 'mainwp-pageheader-settings' action.
		 * @link http://codex.mainwp.com/#mainwp-pageheader-settings
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-settings'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-settings
		 *
		 * @see \MainWP_Settings::renderHeader
		 */
		add_action( 'mainwp-pageheader-settings', array( MainWP_Settings::getClassName(), 'renderHeader' ) );

		/**
		 * This hook allows you to render the Settings page footer via the 'mainwp-pagefooter-settings' action.
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-settings
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-settings'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-settings
		 *
		 * @see \MainWP_Settings::renderFooter
		 */
		add_action( 'mainwp-pagefooter-settings', array( MainWP_Settings::getClassName(), 'renderFooter' ) );
	}

	public static function initMenu() {
		$_page = add_submenu_page( 'mainwp_tab', __( 'Settings Global options', 'mainwp' ), ' <span id="mainwp-Settings">' . __( 'Settings', 'mainwp' ) . '</span>', 'read', 'Settings', array(
			MainWP_Settings::getClassName(),
			'render',
		) );
		
		add_action( 'load-' . $_page, array(MainWP_Settings::getClassName(), 'on_load_page'));		
		
		$_page = add_submenu_page( 'mainwp_tab', __( 'Dashboard Options', 'mainwp' ), ' <div class="mainwp-hidden">' . __( 'Dashboard Options', 'mainwp' ) . '</div>', 'read', 'DashboardOptions', array(
			MainWP_Settings::getClassName(),
			'renderDashboardOptions',
		) );
		add_action( 'load-' . $_page, array(MainWP_Settings::getClassName(), 'on_load_page'));		
		
		$_page = add_submenu_page( 'mainwp_tab', __( 'MainWP Tools', 'mainwp' ), ' <div class="mainwp-hidden">' . __( 'MainWP Tools', 'mainwp' ) . '</div>', 'read', 'MainWPTools', array(
			MainWP_Settings::getClassName(),
			'renderMainWPTools',
		) );
		add_action( 'load-' . $_page, array(MainWP_Settings::getClassName(), 'on_load_page'));		
		
		$_page = add_submenu_page( 'mainwp_tab', __( 'Advanced Options', 'mainwp' ), ' <div class="mainwp-hidden">' . __( 'Advanced Options', 'mainwp' ) . '</div>', 'read', 'SettingsAdvanced', array(
			MainWP_Settings::getClassName(),
			'renderAdvanced',
		) );
		add_action( 'load-' . $_page, array(MainWP_Settings::getClassName(), 'on_load_page'));

		$_page = add_submenu_page('mainwp_tab', __('Managed Client Reports Responder', 'mainwp'), ' <div class="mainwp-hidden">' . __('Managed Client Reports Responder', 'mainwp') . '</div>', 'read', 'SettingsClientReportsResponder', array(
			MainWP_Settings::getClassName(),
			'renderReportResponder',
		));
		add_action('load-' . $_page, array(MainWP_Settings::getClassName(), 'on_load_page'));
		

		/**
		 * This hook allows you to add extra sub pages to the Settings page via the 'mainwp-getsubpages-settings' filter.
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-settings
		 */
		self::$subPages = apply_filters( 'mainwp-getsubpages-settings', array() );
		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Settings' . $subPage['slug'], $subPage['callback'] );
			}
		}
				
	}
	
	public static function on_load_page() {		
		MainWP_System::enqueue_postbox_scripts();		
		self::add_meta_boxes();
	}
	
	public static function add_meta_boxes() {		
		$i = 1;		
		if ( isset($_GET['page']) ) {
			if ( 'Settings' == $_GET['page'] ) {
				add_meta_box(
					'mwp-setting-contentbox-' . $i++,
					'<i class="fa fa-cog"></i> ' . __( 'Network Optimization', 'mainwp' ),
					array( 'MainWP_Options', 'renderNetworkOptimization' ),
					'mainwp_postboxes_global_settings',
					'normal',
					'core'
				);

				add_meta_box(
					'mwp-setting-contentbox-' . $i++,
					'<i class="fa fa-cog"></i> ' . __( 'Global Options', 'mainwp' ),
					array( 'MainWP_Options', 'renderGlobalOptions' ),
					'mainwp_postboxes_global_settings',
					'normal',
					'core'
				);	

				add_meta_box(
					'mwp-setting-contentbox-' . $i++,
					'<i class="fa fa-cog"></i> ' . __( 'Update Options', 'mainwp' ),
					array( 'MainWP_Options', 'renderUpdateOptions' ),
					'mainwp_postboxes_global_settings',
					'normal',
					'core'
				);
                                
                                if ( MainWP_Extensions::isExtensionAvailable('mainwp-comments-extension') ) { 
                                    add_meta_box(
                                            'mwp-setting-contentbox-' . $i++,
                                            '<i class="fa fa-cog"></i> ' . __( 'Data Return Options', 'mainwp' ),
                                            array( 'MainWP_Options', 'renderDataReturnOptions' ),
                                            'mainwp_postboxes_global_settings',
                                            'normal',
                                            'core'
                                    );
                                }

				add_meta_box(
					'mwp-setting-contentbox-' . $i++,
					'<i class="fa fa-cog"></i> ' . __( 'Backup Options', 'mainwp' ),
					array( 'MainWP_Manage_Sites_View', 'renderSettings' ),
					'mainwp_postboxes_global_settings',
					'normal',
					'core'
				);

				$filter = apply_filters( 'mainwp_has_settings_networkfootprint', false );		
				if ( $filter ) {
					add_meta_box(
						'mwp-setting-contentbox-' . $i++,
						'<i class="fa fa-cog"></i> ' . __( 'Network Footprint', 'mainwp' ),
						array( 'MainWP_Footprint', 'renderSettings' ),
						'mainwp_postboxes_global_settings',
						'normal',
						'core'
					);
				}
			} else if (  'DashboardOptions' == $_GET['page']  ) {				
				add_meta_box(
					'mwp-setting-contentbox-' . $i++,
					'<i class="fa fa-cog"></i> ' . __( 'Dashboard options', 'mainwp' ),
					array( 'MainWP_Settings', 'renderDashboardOptionsMetabox' ),
					'mainwp_postboxes_dashboard_options',
					'normal',
					'core'	
				);
			} else if (  'MainWPTools' == $_GET['page']  ) {
				add_meta_box(
					'mwp-setting-contentbox-' . $i++,
					'<i class="fa fa-wrench"></i> ' . __( 'MainWP tools', 'mainwp' ),
					array( 'MainWP_Settings', 'renderMainWPToolsMetabox' ),
					'mainwp_postboxes_mainwp_tools',
					'normal',
					'core'
				);
			} else if (  'SettingsAdvanced' == $_GET['page']  ) {
				add_meta_box(
					'mwp-setting-contentbox-' . $i++,
					'<i class="fa fa-cog"></i> ' . __( 'Cross IP settings', 'mainwp' ),
					array( 'MainWP_Settings', 'renderCrossIPSettings' ),
					'mainwp_postboxes_settings_advanced',
					'normal',
					'core'
				);
				add_meta_box(
					'mwp-setting-contentbox-' . $i++,
					'<i class="fa fa-cog"></i> ' . __( 'IP settings', 'mainwp' ),
					array( 'MainWP_Settings', 'renderIPSettings' ),
					'mainwp_postboxes_settings_advanced',
					'normal',
					'core'
				);
                                
                                add_meta_box(
					'mwp-setting-contentbox-' . $i++,
					'<i class="fa fa-cog"></i> ' . __( 'Frontend request settings', 'mainwp' ),
					array( 'MainWP_Settings', 'renderRequestSettings' ),
					'mainwp_postboxes_settings_advanced',
					'normal',
					'core'
				);
                                
				add_meta_box(
					'mwp-setting-contentbox-' . $i++,
					'<i class="fa fa-cog"></i> ' . __( 'SSL settings', 'mainwp' ),
					array( 'MainWP_Settings', 'renderSSLSettings' ),
					'mainwp_postboxes_settings_advanced',
					'normal',
					'core'
				);
			}
		}
	}

	public static function initMenuSubPages() {
		if ( isset( self::$subPages ) && is_array( self::$subPages ) && ( count( self::$subPages ) > 0 ) ) {
			?>
			<div id="menu-mainwp-Settings" class="mainwp-submenu-wrapper">
				<div class="wp-submenu sub-open" style="">
					<div class="mainwp_boxout">
						<div class="mainwp_boxoutin"></div>
						<a href="<?php echo admin_url( 'admin.php?page=Settings' ); ?>" class="mainwp-submenu"><?php _e( 'Global Options', 'mainwp' ); ?></a>
						<?php
						foreach ( self::$subPages as $subPage ) {
							?>
							<a href="<?php echo admin_url( 'admin.php?page=Settings' . $subPage['slug'] ); ?>"
							   class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
							<?php
						}
						?>
						<a href="<?php echo admin_url( 'admin.php?page=DashboardOptions' ); ?>" class="mainwp-submenu"><?php _e( 'Dashboard Options', 'mainwp' ); ?></a>
						<a href="<?php echo admin_url( 'admin.php?page=MainWPTools' ); ?>" class="mainwp-submenu"><?php _e( 'MainWP Tools', 'mainwp' ); ?></a>
						<a href="<?php echo admin_url('admin.php?page=SettingsClientReportsResponder'); ?>" class="mainwp-submenu"><?php _e('Managed Client Reports Responder', 'mainwp'); ?></a>
					</div>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderHeader( $shownPage ) {
		?>
		<div class="wrap">
		<a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
				src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50"
				alt="MainWP"/></a>
		<h2><i class="fa fa-cogs"></i> <?php _e( 'MainWP Settings', 'mainwp' ); ?></h2>
		<div style="clear: both;"></div><br/>
		<div id="mainwp-tip-zone">
			<?php if ( $shownPage == '' ) { ?>
				<?php if ( MainWP_Utility::showUserTip( 'mainwp-settings-tips' ) ) { ?>
					<div class="mainwp-tips mainwp-notice mainwp-notice-blue">
						<span class="mainwp-tip" id="mainwp-settings-tips"><strong><?php _e( 'MainWP Tip', 'mainwp' ); ?>: </strong><?php _e( 'The majority of these default settings can also be tweaked on the Site level by visiting Manage Sites &rarr; Edit Site.', 'mainwp' ); ?></span><span><a href="#" class="mainwp-dismiss"><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss', 'mainwp' ); ?>
							</a></span></div>
				<?php } ?>
			<?php } ?>
			<?php if ( $shownPage == 'OfflineChecks' ) { ?>
				<?php if ( MainWP_Utility::showUserTip( 'mainwp-aumrecommend-tips' ) ) { ?>
					<div class="mainwp-tips mainwp-notice mainwp-notice-blue">
						<span class="mainwp-tip" id="mainwp-aumrecommend-tips"><strong><?php _e( 'MainWP Tip', 'mainwp' ); ?>: </strong><?php echo sprintf( __( 'We currently recommend the free %sAdvanced Uptime Monitor Extension%s to perform more frequent tests.', 'mainwp' ), '<a href="https://mainwp.com/extension/advanced-uptime-monitor/" target="_blank">', '</a>' ); ?></span><span><a href="#" class="mainwp-dismiss"><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss', 'mainwp' ); ?>
							</a></span></div>
				<?php } ?>
			<?php } ?>
		</div>
		<div class="mainwp-tabs" id="mainwp-tabs">
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage === '' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=Settings"><?php _e( 'Global Options', 'mainwp' ); ?></a>						
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'DashboardOptions' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=DashboardOptions"><?php _e( 'Dashboard Options', 'mainwp' ); ?></a>
                        <a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'Advanced' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=SettingsAdvanced"><?php _e( 'Advanced Options', 'mainwp' ); ?></a>
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'MainWPTools' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=MainWPTools"><?php _e( 'MainWP Tools', 'mainwp' ); ?></a>
			<a class="nav-tab pos-nav-tab <?php
			if ($shownPage === 'SettingsClientReportsResponder') {
				echo 'nav-tab-active';
			}
			?>" href="admin.php?page=SettingsClientReportsResponder"><?php _e('Managed Client Reports Responder', 'mainwp'); ?></a>
			<?php
			if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
				foreach ( self::$subPages as $subPage ) {
					?>
					<a class="nav-tab pos-nav-tab <?php if ( $shownPage === $subPage['slug'] ) {
						echo 'nav-tab-active';
					} ?>" href="admin.php?page=Settings<?php echo $subPage['slug']; ?>"><?php echo $subPage['title']; ?></a>
					<?php
				}
			}
			?>			
			<div class="clear"></div>
		</div>
		<div id="mainwp_wrap-inside">
		<?php
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderFooter( $shownPage ) {
		?>
		</div>
		</div>
		<?php
	}

    public static function renderReportResponder() {
        self::renderHeader( 'SettingsClientReportsResponder' );
        if ('SettingsClientReportsResponder' == $_REQUEST['page']) {
            self::renderReportResponderDashboardPage();
        }
        self::renderFooter( 'SettingsClientReportsResponder' );
    }

    public static function renderReportResponderDashboardPage() {

        if ( isset( $_POST[ 'save_changes' ] ) ) {
            $nonce = $_REQUEST[ '_wpnonce' ];
            if ( !wp_verify_nonce( $nonce, 'general_settings' ) ) {
                echo "<div class='mainwp-notice-red'><p><strong>" . __( 'Unable to save settings, please refresh and try again.', 'mainwp' ) . "</strong></p></div>";
            } else {
                update_option( 'live-report-responder-siteurl', stripslashes( $_POST[ 'live_reponder_site_url' ] ) );
                update_option( 'live-report-responder-provideaccess', $_POST[ 'live_reponder_provideaccess' ] );
                $security_token = Live_Reports_Responder_Class::Live_Reports_Responder_generate_random_string();
                update_option( 'live-reports-responder-security-id', ( isset( $_POST[ 'requireUniqueSecurityId' ] ) ) ? $_POST[ 'requireUniqueSecurityId' ] : '' );
                update_option( 'live-reports-responder-security-code', stripslashes( $security_token ) );
                echo '<div  class="mainwp-notice mainwp-notice-green">' . __( 'Settings Saved Successfully', 'mainwp' ) . '</div>';
            }
        }
        ?>
        <form method="POST">
            <?php wp_nonce_field( 'general_settings' ); ?>
            <div class="postbox">
                <h3 class="mainwp_box_title"><span><i class="fa fa-cog"></i> <?php _e( 'General Options', 'mainwp' ); ?></span></h3>
                <div class="inside">
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row"><?php _e( 'Client Reports Site Url:', 'mainwp' ); ?></th>
                                <td>
                                    <input type="text"  name="live_reponder_site_url" placeholder="http://thisisexample.com/" value="<?php echo esc_attr( get_option( 'live-report-responder-siteurl' ) ); ?>"  size="50" autocomplete="off">
                                    <br><em><?php _e( 'With Trailing Slash', 'mainwp' ); ?></em>
                                </td>
                            </tr>
                            <tr class="form-field form-required">
                                <th scope="row"><?php _e( 'Allow Access:', 'mainwp' ); ?></th>
                                <td>
                                    <fieldset>
                                        <legend class="screen-reader-text"><span><?php _e( 'Allow Access: ', 'mainwp' ); ?></span></legend>
                                        <input type="checkbox" name="live_reponder_provideaccess" value="yes" <?php if ( get_option( 'live-report-responder-provideaccess') == 'yes' ) echo 'checked'; ?>>
                                        <span><?php _e( 'Tick to allow access to Managed Client Reports for WooCommerce Plugin', 'mainwp' ); ?></span>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr class="form-field form-required">
                                <th scope="row"><?php _e( 'Secure Connection:', 'mainwp' ); ?></th>
                                <td>

                                    <div style="margin: 1em 0px 8px 0;">
                                        <input name="requireUniqueSecurityId" type="checkbox" id="requireUniqueSecurityId" <?php if ( get_option( 'live-reports-responder-security-id' ) == 'on' ) echo 'checked'; ?>>
                                        <label for="requireUniqueSecurityId" style="font-size: 15px;"><?php _e('Require Unique Security ID', 'mainwp'); ?></label>
                                    </div>
                                    <div class="howto" style="margin-bottom: 35px;"><?php _e( 'The Unique Security ID adds additional protection between the Managed Client Reports for WooCommerce Responder and your Managed Client Reports for WooCommerce Plugin. The Unique Security ID will need to match when being added to the Managed Client Reports for WooCommerce plugin. This is additional security and should not be needed in most situations.', 'mainwp' ); ?></div>
                                    <?php if ( get_option( 'live-reports-responder-security-id' ) == 'on' ) { ?>
                                        <div>
                                            <span style="border: 1px dashed #e5e5e5; background: #fafafa; font-size: 24px; padding: 1em 2em;"><?php _e( 'Your Unique Security ID is:', 'mainwp' ); ?> <span style="font-weight: bold; color: #7fb100;">
                                                    <?php
                                                    echo get_option( 'live-reports-responder-security-code' );
                                                    ?>
                                                </span></span>
                                        </div>
                                    <?php } ?>
                                </td>
                            </tr>
                            <tr><th></th><td><input type="submit" name="save_changes" value="Save Changes" class="button-primary button button-hero">


                                </td></tr>
                        </tbody></table>
                </div>
            </div>
        </form>
        <?php
        if ( !empty( get_option( 'live-report-responder-siteurl' ) ) )
            self::renderReportResponderUserPage();
    }

    public static function renderReportResponderUserPage() {
        $arr['action'] = 'getusers';
        if ( get_option( 'live-reports-responder-security-id' ) == 'on') {
            $arr['security'] = base64_encode( get_option( 'live-reports-responder-security-code' ) );
        }
        $all_users_result = json_decode( Live_Reports_Responder_Class::CurlRequest( $arr ), true );
        if ( 'success' == $all_users_result[ 'result' ] ) {
            ?>
            <div class="postbox">
                <form method="post">

                    <h3 class="mainwp_box_title"><span><i class="fa fa-users"></i> All Users</span></h3>
                    <table class="wp-list-table widefat fixed striped users " style="border: none;">
                        <thead>
                            <tr>
                                <th><?php _e( 'Username', 'mainwp' ); ?></th>
                                <th><?php _e( 'Name', 'mainwp' ); ?></th>
                                <th><?php _e( 'Email', 'mainwp' ); ?></th>
                                <th><?php _e( 'Last Login', 'mainwp' ); ?></th>
                                <th><?php _e( 'Last Report Check', 'mainwp' ); ?></th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php
                            foreach ( $all_users_result[ 'data' ] as $user ) {
                                ?>
                                <tr>
                                    <td><?php echo $user[ 'user_login' ]; ?> </td>
                                    <td><?php echo $user[ 'display_name' ]; ?> </td>
                                    <td><?php echo $user[ 'user_email' ]; ?></td>
                                    <td><?php echo $user[ 'last_login' ] ? : '__'; ?>  </td>
                                    <td><?php echo $user[ 'last_reportcheck' ] ? : '__'; ?>  </td>
                                </tr>
                                <?php
                            }
                            ?>


                        </tbody>
                        <tfoot>
                            <tr>
                                <th><?php _e( 'Username', 'mainwp' ); ?></th>
                                <th><?php _e( 'Name', 'mainwp' ); ?></th>
                                <th><?php _e( 'Email', 'mainwp' ); ?></th>
                                <th><?php _e( 'Last Login', 'mainwp' ); ?></th>
                                <th><?php _e( 'Last Report Check', 'mainwp' ); ?></th>
                            </tr>
                        </tfoot>

                    </table>
                </form>
            </div>
            <?php
        } else {
            if ( !empty( $all_users_result[ 'message' ] ) ) {
                $message = $all_users_result['message'];
            } else {
                $message = 'Unknown Error';
            }
            echo '<div class="error" style="margin-top: 50px;"><p><strong>' . $message . '</strong></p></div>';
        }
    }

    public static function renderAdvanced() {
        if ( !mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );
			return;
		}

		if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'SettingsAdvanced' ) ) {
			MainWP_Utility::update_option( 'mainwp_maximumRequests', MainWP_Utility::ctype_digit( $_POST['mainwp_maximumRequests'] ) ? intval( $_POST['mainwp_maximumRequests'] ) : 4 );
			MainWP_Utility::update_option( 'mainwp_minimumDelay', MainWP_Utility::ctype_digit( $_POST['mainwp_minimumDelay'] ) ? intval( $_POST['mainwp_minimumDelay'] ) : 200 );
			MainWP_Utility::update_option( 'mainwp_maximumIPRequests', MainWP_Utility::ctype_digit( $_POST['mainwp_maximumIPRequests'] ) ? intval( $_POST['mainwp_maximumIPRequests'] ) : 1 );
			MainWP_Utility::update_option( 'mainwp_minimumIPDelay', MainWP_Utility::ctype_digit( $_POST['mainwp_minimumIPDelay'] ) ? intval( $_POST['mainwp_minimumIPDelay'] ) : 400 );
			MainWP_Utility::update_option( 'mainwp_maximumSyncRequests', MainWP_Utility::ctype_digit( $_POST['mainwp_maximumSyncRequests'] ) ? intval( $_POST['mainwp_maximumSyncRequests'] ) : 8 );
			MainWP_Utility::update_option( 'mainwp_maximumInstallUpdateRequests', MainWP_Utility::ctype_digit( $_POST['mainwp_maximumInstallUpdateRequests'] ) ? intval( $_POST['mainwp_maximumInstallUpdateRequests'] ) : 3 );
			MainWP_Utility::update_option( 'mainwp_sslVerifyCertificate', isset( $_POST['mainwp_sslVerifyCertificate'] ) ? 1 : 0 );
		}

		self::renderHeader( 'Advanced' );
		?>
		<form method="POST" action="" id="mainwp-settings-page-form">
			<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'SettingsAdvanced' ); ?>" />			
			<?php			
			MainWP_Tours::renderAdvancedOptionsTour();
			MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_settings_advanced');	
			?>			
			<p class="submit">
				<input type="submit" name="submit" id="submit" class="button-primary button button-hero" value="<?php esc_attr_e( 'Save settings', 'mainwp' ); ?>"/>
			</p>
		</form>
		<?php
		self::renderFooter( 'Advanced' );
	}
	
	public static function renderCrossIPSettings() {
		?>
		<div class="mainwp-postbox-actions-top">
			<?php _e( 'If you have sites on different servers timing out during updates or syncing, you may need to adjust the fields below by decreasing a number of requests and increasing delays.', 'mainwp' ); ?>
		</div>
		<div class="inside">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php _e( 'Maximum simultaneous requests', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Maximum simultaneous requests. When too many requests are sent out, they will begin to time out. This will cause child sites to be shown as offline while they are online. With a typical shared host you should set this at 4, set to 0 for unlimited.', 'mainwp' ) ); ?></th>
						<td>
							<input type="number" name="mainwp_maximumRequests" class=""
								   id="mainwp_maximumRequests" value="<?php echo( ( get_option( 'mainwp_maximumRequests' ) === false ) ? 4 : get_option( 'mainwp_maximumRequests' ) ); ?>"/>
							<em><?php _e( 'Default: 4','mainwp' ); ?></em>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e( 'Minimum delay between requests (milliseconds)', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Minimum delay between requests (milliseconds). With a typical shared host you should set this at 200.', 'mainwp' ) ); ?></th>
						<td>
							<input type="number" name="mainwp_minimumDelay" class=""
								   id="mainwp_minimumDelay" value="<?php echo( ( get_option( 'mainwp_minimumDelay' ) === false ) ? 200 : get_option( 'mainwp_minimumDelay' ) ); ?>"/>
							<em><?php _e( 'Default: 200','mainwp' ); ?></em>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	<?php
	}
	
	public static function renderIPSettings() {
		?>
		<div class="mainwp-postbox-actions-top">
			<?php _e( 'If you have sites on the same servers timing out during updates or syncing, you may need to adjust the fields below by decreasing a number of requests and increasing delays.', 'mainwp' ); ?>
		</div>
		<div class="inside">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php _e( 'Maximum simultaneous requests per ip', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Maximum simultaneous requests per IP. When too many requests are sent out, they will begin to time out. This will cause child sites to be shown as offline while they are online. With a typical shared host you should set this at 1, set to 0 for unlimited.', 'mainwp' ) ); ?></th>
						<td>
							<input type="number" name="mainwp_maximumIPRequests" class=""
								   id="mainwp_maximumIPRequests" value="<?php echo( ( get_option( 'mainwp_maximumIPRequests' ) === false ) ? 1 : get_option( 'mainwp_maximumIPRequests' ) ); ?>"/>
							<em><?php _e( 'Default: 1','mainwp' ); ?></em>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e( 'Minimum delay between requests to the same ip (milliseconds)', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Minimum delay between requests (milliseconds) per IP. With a typical shared host you should set this at 1000.', 'mainwp' ) ); ?></th>
						<td>
							<input type="number" name="mainwp_minimumIPDelay" class=""
								   id="mainwp_minimumIPDelay" value="<?php echo( ( get_option( 'mainwp_minimumIPDelay' ) === false ) ? 400 : get_option( 'mainwp_minimumIPDelay' ) ); ?>"/>
							<em><?php _e( 'Default: 1000','mainwp' ); ?></em>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
        
	public static function renderRequestSettings() {
        ?>    
        <div class="mainwp-postbox-actions-top">
			<?php _e( 'If your sites time out during syncing, installing or updating plugins/themes, you may need to adjust the fields below.', 'mainwp' ); ?>
		</div>
		<div class="inside">
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><?php _e( 'Maximum simultaneous sync requests', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Maximum simultaneous sync requests. When too many requests are sent to the backend some hosts will block the requests.', 'mainwp' ) ); ?></th>
                    <td>
                        <input type="number" name="mainwp_maximumSyncRequests" class=""
                               id="mainwp_maximumSyncRequests" value="<?php echo( ( get_option( 'mainwp_maximumSyncRequests' ) === false ) ? 8 : get_option( 'mainwp_maximumSyncRequests' ) ); ?>"/>
                        <em><?php _e( 'Default: 8','mainwp' ); ?></em>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( 'Minimum simultaneous install/update requests', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Minimum simultaneous install/update requests. When too many requests are sent to the backend some hosts will block the requests.', 'mainwp' ) ); ?></th>
                    <td>
                        <input type="number" name="mainwp_maximumInstallUpdateRequests" class=""
                               id="mainwp_maximumInstallUpdateRequests" value="<?php echo( ( get_option( 'mainwp_maximumInstallUpdateRequests' ) === false ) ? 3 : get_option( 'mainwp_maximumInstallUpdateRequests' ) ); ?>"/>
                        <em><?php _e( 'Default: 3','mainwp' ); ?></em>
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
        <?php
    }          
        
	public static function renderSSLSettings() {
	?>
		<table class="form-table">
			</tbody>
			<tr>
				<th scope="row"><?php _e( 'Verify certificate', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Verify the childs SSL certificate. This should be disabled if you are using out of date or self signed certificates.', 'mainwp' ) ); ?></th>
				<td style="width: 100px;">
					<div class="mainwp-checkbox">
						<input type="checkbox" name="mainwp_sslVerifyCertificate"
							   id="mainwp_sslVerifyCertificate" value="checked" <?php echo ( ( get_option( 'mainwp_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_sslVerifyCertificate' ) == 1 ) ) ? 'checked="checked"' : ''; ?>/><label for="mainwp_sslVerifyCertificate"></label>
					</div>
				</td>
				<td><em><?php _e( 'Default: YES', 'mainwp' ); ?></em></td>
			</tr>
			</tbody>
		</table>
	<?php
	}
	
	public static function render() {
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
			mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );

			return;
		}

		self::renderHeader( '' ); ?>
		<?php if ( isset($_GET['message']) && $_GET['message'] = 'saved') {
			?>
			<div id="ajax-information-zone" class="mainwp-notice mainwp-notice-green">
				<?php _e( 'Your settings have been saved.', 'mainwp' ); ?>
                        </div>
			<?php
		}

		?>

		<form method="POST" action="admin.php?page=Settings" id="mainwp-settings-page-form">
			<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'Settings' ); ?>" />
			<?php MainWP_Options::renderSettings(); ?>
			<p class="submit">
				<input type="submit" name="submit" id="submit" class="button-primary button button-hero" value="<?php esc_attr_e( 'Save settings', 'mainwp' ); ?>"/>
			</p>
		</form>
		<?php
		self::renderFooter( '' );
	}

	public static function renderDashboardOptions() {
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
			mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );

			return;
		}

		self::renderHeader( 'DashboardOptions' );
		?>
		<form method="POST" action="" id="mainwp-settings-page-form">
			<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'DashboardOptions' ); ?>" />
			<?php MainWP_Tours::renderDashboardOptionsTour(); ?>			
			<?php MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_dashboard_options'); ?>		
			<p class="submit">
				<input type="submit" name="submit" id="submit" class="button-primary button button-hero" value="<?php esc_attr_e( 'Save settings', 'mainwp' ); ?>"/>
			</p>
		</form>
		<?php

		self::renderFooter( 'DashboardOptions' );
	}

	public static function renderDashboardOptionsMetabox() {
		?>
			<table class="form-table">
			<tbody>
			<tr>
				<th scope="row"><?php _e( 'Hide MainWP footer', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'If set to YES, fixed footer will be appended to the bottom of the page', 'mainwp' ) ); ?></th>
				<td>
					<div class="mainwp-checkbox">
						<input type="checkbox" name="mainwp_hide_footer"
							   id="mainwp_hide_footer" <?php echo( ( get_option( 'mainwp_hide_footer', 0 ) == 1 ) ? 'checked="true"' : '' ); ?>/>
						<label for="mainwp_hide_footer"></label>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php _e( 'Use child site favicon', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Set to YES if you want to use Child Site Favicon.', 'mainwp' ) ); ?></th>
				<td>
					<div class="mainwp-checkbox">
						<input type="checkbox" name="mainwp_use_favicon"
							   id="mainwp_use_favicon" <?php echo( ( get_option( 'mainwp_use_favicon', 1 ) == 1 ) ? 'checked="true"' : '' ); ?>/>
						<label for="mainwp_use_favicon"></label>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php _e( 'Hide MainWP tips', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'If set to YES, MainWP Tips will be hidden', 'mainwp' ) ); ?></th>
				<td>
					<div class="mainwp-checkbox">
						<input type="checkbox" name="mainwp_hide_tips"
							   id="mainwp_hide_tips" <?php echo( ( get_option( 'mainwp_hide_tips', 1 ) == 1 ) ? 'checked="true"' : '' ); ?>/>
						<label for="mainwp_hide_tips"></label>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php _e( 'Turn off brag button', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'If set to YES, Twitter messages will be turn off', 'mainwp' ) ); ?></th>
				<td>
					<div class="mainwp-checkbox">
						<input type="checkbox" name="mainwp_hide_twitters_message"
							   id="mainwp_hide_twitters_message" <?php echo( ( get_option( 'mainwp_hide_twitters_message', 0 ) == 1 ) ? 'checked="true"' : '' ); ?>/>
						<label for="mainwp_hide_twitters_message"></label>
					</div>
				</td>
			</tr>
			</tbody>
		</table>
		<?php
	}

	public static function renderMainWPTools() {
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
			mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );

			return;
		}

		self::renderHeader( 'MainWPTools' );
		?>
		<form method="POST" action="">
			<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'MainWPTools' ); ?>" />
			<?php MainWP_Tours::renderMainWPToolsTour(); ?>
			<?php MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_mainwp_tools'); ?>		
			<p class="submit"><input type="submit" name="submit" id="submit" class="button-primary button button-hero" value="<?php esc_attr_e('Save Settings','mainwp'); ?>"/></p>
		</form>
		<?php
		self::renderFooter( 'MainWPTools' );
	}

	public static function renderMainWPToolsMetabox() {
		
		$wp_menu_items = array(
			'dashboard' => __( 'Dashboard', 'mainwp' ),
			'posts' => __( 'Posts', 'mainwp' ),
			'media' => __( 'Media', 'mainwp' ),
			'pages' => __( 'Pages', 'mainwp' ),
			'appearance' => __( 'Appearance', 'mainwp' ),
			'comments' => __( 'Comments', 'mainwp' ),
			'users' => __( 'Users', 'mainwp' ),
			'tools' => __( 'Tools', 'mainwp' ),
		);

		$hide_menus =  get_option('mwp_setup_hide_wp_menus');

		if ( ! is_array( $hide_menus ) ) {
			$hide_menus = array();
		}
		?>
		<table class="form-table">
			<tbody>
			<tr>
				<th scope="row"><?php _e( 'Force dashboard to establish new connection', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Use this option to establish new connection with child sites.', 'mainwp' ) ); ?></th>
				<td>
					<input type="submit" name="" id="force-destroy-sessions-button" class="button-primary button" value="<?php esc_attr_e( 'Establish New Connection', 'mainwp' ); ?>"/><br/>
					<em>
						<?php _e( 'Forces your dashboard to reconnect with your child sites. This feature will log out any currently logged in users on the Child sites and require them to re-log in. Only needed if suggested by MainWP Support.', 'mainwp' ); ?>
					</em>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php _e( 'Scan child sites for known issues', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Use this option to scan child sites for known issues.', 'mainwp' ) ); ?></th>
				<td>
					<a href="<?php echo admin_url( 'admin.php?page=MainWP_Child_Scan' ); ?>" class="button-primary button" id="mainwp_scan_child_sites_fki"><?php _e( 'Scan', 'mainwp' ); ?></a><br/>
					<em>
						<?php _e( 'Scans each site individually for known issues.', 'mainwp' ); ?>
					</em>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php _e('MainWP quick setup','mainwp'); ?></th>
				<td>
					<a href="admin.php?page=mainwp-setup" class="button-primary button" id="mainwp_start_qsw" /><?php _e('Start quick setup','mainwp'); ?></a><br/>
					<em>
						<?php _e('MainWP Quick Setup allows you to quickly set your MainWP Dashboard preferences.','mainwp'); ?>
					</em>
				</td>
			</tr>
			</tbody>
		</table>
		<div class="mainwp-notice mainwp-notice-yellow"><?php _e( 'Changing this settings will overwrite Clean & Lock Extension settings. Do not forget to migrate the settings you wish to keep.', 'mainwp' ); ?></div>
		<table class="form-table">
			<tbody>
			<tr>
				<th scope="row"><?php _e( 'Cleanup your dashboard', 'mainwp' ); ?></th>
				<td>
					<ul class="mainwp_checkboxes mainwp_hide_wpmenu_checkboxes">
						<?php
						foreach ( $wp_menu_items as $name => $item ) {
							$_selected = '';
							if ( in_array( $name, $hide_menus ) ) {
								$_selected = 'checked'; }
							?>
							<li>
								<input type="checkbox" id="mainwp_hide_wpmenu_<?php echo $name; ?>" name="mainwp_hide_wpmenu[]" <?php echo $_selected; ?> value="<?php echo $name; ?>">
								<label for="mainwp_hide_wpmenu_<?php echo $name; ?>" ><?php echo $item; ?></label>
							</li>
						<?php }
						?>
					</ul>
				</td>
			</tr>
			</tbody>
		</table>
		<?php
	}

}
