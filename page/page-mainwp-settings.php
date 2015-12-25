<?php

class MainWP_Settings {
	public static function getClassName() {
		return __CLASS__;
	}

	public static $subPages;

	public static function init() {
		add_action( 'mainwp-pageheader-settings', array( MainWP_Settings::getClassName(), 'renderHeader' ) );
		add_action( 'mainwp-pagefooter-settings', array( MainWP_Settings::getClassName(), 'renderFooter' ) );
	}

	public static function initMenu() {
		add_submenu_page( 'mainwp_tab', __( 'Settings Global options', 'mainwp' ), ' <span id="mainwp-Settings">' . __( 'Settings', 'mainwp' ) . '</span>', 'read', 'Settings', array(
			MainWP_Settings::getClassName(),
			'render',
		) );
		add_submenu_page( 'mainwp_tab', __( 'Settings Help', 'mainwp' ), ' <div class="mainwp-hidden">' . __( 'Settings Help', 'mainwp' ) . '</div>', 'read', 'SettingsHelp', array(
			MainWP_Settings::getClassName(),
			'QSGManageSettings',
		) );
		add_submenu_page( 'mainwp_tab', __( 'Dashboard Options', 'mainwp' ), ' <div class="mainwp-hidden">' . __( 'Dashboard Options', 'mainwp' ) . '</div>', 'read', 'DashboardOptions', array(
			MainWP_Settings::getClassName(),
			'renderDashboardOptions',
		) );
		add_submenu_page( 'mainwp_tab', __( 'MainWP Tools', 'mainwp' ), ' <div class="mainwp-hidden">' . __( 'MainWP Tools', 'mainwp' ) . '</div>', 'read', 'MainWPTools', array(
			MainWP_Settings::getClassName(),
			'renderMainWPTools',
		) );

		self::$subPages = apply_filters( 'mainwp-getsubpages-settings', array(
			array(
				'title'    => __( 'Advanced Options', 'mainwp' ),
				'slug'     => 'Advanced',
				'callback' => array(
					MainWP_Settings::getClassName(),
					'renderAdvanced',
				),
			),
		) );
		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Settings' . $subPage['slug'], $subPage['callback'] );
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
						<a href="<?php echo admin_url( 'admin.php?page=OfflineChecks' ); ?>" class="mainwp-submenu"><?php _e( 'Offline Checks', 'mainwp' ); ?></a>
					</div>
				</div>
			</div>
			<?php
		}
	}

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
					<div class="mainwp-tips mainwp_info-box-blue">
						<span class="mainwp-tip" id="mainwp-settings-tips"><strong><?php _e( 'MainWP Tip', 'mainwp' ); ?>: </strong><?php _e( 'The majority of these default settings can also be tweaked on the Site level by visiting Manage Sites &rarr; Edit Site.', 'mainwp' ); ?></span><span><a href="#" class="mainwp-dismiss"><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss', 'mainwp' ); ?>
							</a></span></div>
				<?php } ?>
			<?php } ?>
			<?php if ( $shownPage == 'OfflineChecks' ) { ?>
				<?php if ( MainWP_Utility::showUserTip( 'mainwp-aumrecommend-tips' ) ) { ?>
					<div class="mainwp-tips mainwp_info-box-blue">
						<span class="mainwp-tip" id="mainwp-aumrecommend-tips"><strong><?php _e( 'MainWP Tip', 'mainwp' ); ?>: </strong><?php echo sprintf( __( 'We currently recommend the free %sAdvanced Uptime Monitor Extension%s to perform more frequent tests.', 'mainwp' ), '<a href="https://extensions.mainwp.com/product/mainwp-advanced-uptime-monitor/" target="_blank">', '</a>' ); ?></span><span><a href="#" class="mainwp-dismiss"><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss', 'mainwp' ); ?>
							</a></span></div>
				<?php } ?>
			<?php } ?>
		</div>
		<div class="mainwp-tabs" id="mainwp-tabs">
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage === '' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=Settings"><?php _e( 'Global Options', 'mainwp' ); ?></a>
			<a style="float: right" class="mainwp-help-tab nav-tab pos-nav-tab <?php if ( $shownPage === 'SettingsHelp' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=SettingsHelp"><?php _e( 'Help', 'mainwp' ); ?></a>
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
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'DashboardOptions' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=DashboardOptions"><?php _e( 'Dashboard Options', 'mainwp' ); ?></a>
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'MainWPTools' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=MainWPTools"><?php _e( 'MainWP Tools', 'mainwp' ); ?></a>
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'OfflineChecks' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=OfflineChecks"><?php _e( 'Offline Checks', 'mainwp' ); ?></a>
			<div class="clear"></div>
		</div>
		<div id="mainwp_wrap-inside">
		<?php
	}

	public static function renderFooter( $shownPage ) {
		?>
		</div>
		</div>
		<?php
	}

	public static function renderAdvanced() {
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
			mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );

			return;
		}

		if ( isset( $_POST['submit'] ) ) {
			MainWP_Utility::update_option( 'mainwp_maximumRequests', $_POST['mainwp_maximumRequests'] );
			MainWP_Utility::update_option( 'mainwp_minimumDelay', $_POST['mainwp_minimumDelay'] );
			MainWP_Utility::update_option( 'mainwp_maximumIPRequests', $_POST['mainwp_maximumIPRequests'] );
			MainWP_Utility::update_option( 'mainwp_minimumIPDelay', $_POST['mainwp_minimumIPDelay'] );
			MainWP_Utility::update_option( 'mainwp_sslVerifyCertificate', isset( $_POST['mainwp_sslVerifyCertificate'] ) ? 1 : 0 );
		}

		self::renderHeader( 'Advanced' );
		?>
		<form method="POST" action="" id="mainwp-settings-page-form">
			<div class="postbox" id="mainwp-advanced-options">
				<h3 class="mainwp_box_title">
					<span><i class="fa fa-cog"></i> <?php _e( 'Cross IP Settings', 'mainwp' ); ?></span></h3>

				<div class="inside">

					<table class="form-table">
						<tbody>
						<tr>
							<th scope="row"><?php _e( 'Maximum simultaneous requests', 'mainwp' ); ?><?php MainWP_Utility::renderToolTip( __( 'Maximum simultaneous requests. When too many requests are sent out, they will begin to time out. This will cause child sites to be shown as offline while they are online. With a typical shared host you should set this at 4, set to 0 for unlimited.', 'mainwp' ) ); ?></th>
							<td>
								<input type="text" name="mainwp_maximumRequests" class=""
									id="mainwp_maximumRequests" value="<?php echo( ( get_option( 'mainwp_maximumRequests' ) === false ) ? 4 : get_option( 'mainwp_maximumRequests' ) ); ?>"/>
								<i>Default: 4</i>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Minimum delay between requests (milliseconds)', 'mainwp' ); ?><?php MainWP_Utility::renderToolTip( __( 'Minimum delay between requests (milliseconds). With a typical shared host you should set this at 200.', 'mainwp' ) ); ?></th>
							<td>
								<input type="text" name="mainwp_minimumDelay" class=""
									id="mainwp_minimumDelay" value="<?php echo( ( get_option( 'mainwp_minimumDelay' ) === false ) ? 200 : get_option( 'mainwp_minimumDelay' ) ); ?>"/>
								<i>Default: 200</i>
							</td>
						</tr>
						</tbody>
					</table>
				</div>
			</div>
			<div class="postbox" id="mainwp-advanced-options">
				<h3 class="mainwp_box_title">
					<span><i class="fa fa-cog"></i> <?php _e( 'IP Settings', 'mainwp' ); ?></span></h3>

				<div class="inside">
					<table class="form-table">
						<tbody>
						<tr>
							<th scope="row"><?php _e( 'Maximum simultaneous requests per ip', 'mainwp' ); ?><?php MainWP_Utility::renderToolTip( __( 'Maximum simultaneous requests per IP. When too many requests are sent out, they will begin to time out. This will cause child sites to be shown as offline while they are online. With a typical shared host you should set this at 1, set to 0 for unlimited.', 'mainwp' ) ); ?></th>
							<td>
								<input type="text" name="mainwp_maximumIPRequests" class=""
									id="mainwp_maximumIPRequests" value="<?php echo( ( get_option( 'mainwp_maximumIPRequests' ) === false ) ? 1 : get_option( 'mainwp_maximumIPRequests' ) ); ?>"/>
								<i>Default: 1</i>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Minimum delay between requests to the same ip (milliseconds)', 'mainwp' ); ?><?php MainWP_Utility::renderToolTip( __( 'Minimum delay between requests (milliseconds) per IP. With a typical shared host you should set this at 1000.', 'mainwp' ) ); ?></th>
							<td>
								<input type="text" name="mainwp_minimumIPDelay" class=""
									id="mainwp_minimumIPDelay" value="<?php echo( ( get_option( 'mainwp_minimumIPDelay' ) === false ) ? 1000 : get_option( 'mainwp_minimumIPDelay' ) ); ?>"/>
								<i>Default: 1000</i>
							</td>
						</tr>
						</tbody>
					</table>
				</div>
			</div>
			<div class="postbox" id="mainwp-advanced-options">
				<h3 class="mainwp_box_title">
					<span><i class="fa fa-cog"></i> <?php _e( 'SSL Settings', 'mainwp' ); ?></span></h3>

				<div class="inside">
					<table class="form-table">
						</tbody>
						<tr>
							<th scope="row"><?php _e( 'Verify certificate', 'mainwp' ); ?><?php MainWP_Utility::renderToolTip( __( 'Verify the childs SSL certificate. This should be disabled if you are using out of date or self signed certificates.', 'mainwp' ) ); ?></th>
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
				</div>
			</div>
			<p class="submit">
				<input type="submit" name="submit" id="submit" class="button-primary button button-hero" value="<?php _e( 'Save Settings', 'mainwp' ); ?>"/>
			</p>
		</form>
		<?php
		self::renderFooter( 'Advanced' );
	}

	public static function render() {
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
			mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );

			return;
		}

		$updated = MainWP_Options::handleSettingsPost();
		$updated |= MainWP_Manage_Sites::handleSettingsPost();
		$updated |= MainWP_Offline_Checks::handleSettingsPost();
		$updated |= MainWP_Footprint::handleSettingsPost();

		self::renderHeader( '' ); ?>
		<?php if ( $updated ) {
			?>
			<div id="ajax-information-zone" class="updated">
				<p><?php _e( 'Your settings have been saved.', 'mainwp' ); ?></p></div>
			<?php
		}

		?>

		<form method="POST" action="admin.php?page=Settings" id="mainwp-settings-page-form">
			<?php

			MainWP_Options::renderSettings();

			MainWP_Manage_Sites::renderSettings();

			MainWP_Offline_Checks::renderSettings();

			MainWP_Footprint::renderSettings();

			?>
			<p class="submit">
				<input type="submit" name="submit" id="submit" class="button-primary button button-hero" value="<?php _e( 'Save Settings', 'mainwp' ); ?>"/>
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
			<div class="postbox" id="mainwp-dashboard-options">
				<h3 class="mainwp_box_title">
					<span><i class="fa fa-cog"></i> <?php _e( 'Dashboard Options', 'mainwp' ); ?></span></h3>

				<div class="inside">
					<table class="form-table">
						<tbody>
						<tr>
							<th scope="row"><?php _e( 'Hide MainWP Footer', 'mainwp' ); ?><?php MainWP_Utility::renderToolTip( __( 'If set to YES, fixed footer will be appended to the bottom of the page', 'mainwp' ) ); ?></th>
							<td>
								<div class="mainwp-checkbox">
									<input type="checkbox" name="mainwp_hide_footer"
										id="mainwp_hide_footer" <?php echo( ( get_option( 'mainwp_hide_footer', 0 ) == 1 ) ? 'checked="true"' : '' ); ?>/>
									<label for="mainwp_hide_footer"></label>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Use Child Site Favicon', 'mainwp' ); ?><?php MainWP_Utility::renderToolTip( __( 'Set to YES if you want to use Child Site Favicon.', 'mainwp' ) ); ?></th>
							<td>
								<div class="mainwp-checkbox">
									<input type="checkbox" name="mainwp_use_favicon"
										id="mainwp_use_favicon" <?php echo( ( get_option( 'mainwp_use_favicon', 1 ) == 1 ) ? 'checked="true"' : '' ); ?>/>
									<label for="mainwp_use_favicon"></label>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Hide MainWP Tips', 'mainwp' ); ?><?php MainWP_Utility::renderToolTip( __( 'If set to YES, MainWP Tips will be hidden', 'mainwp' ) ); ?></th>
							<td>
								<div class="mainwp-checkbox">
									<input type="checkbox" name="mainwp_hide_tips"
										id="mainwp_hide_tips" <?php echo( ( get_option( 'mainwp_hide_tips', 1 ) == 1 ) ? 'checked="true"' : '' ); ?>/>
									<label for="mainwp_hide_tips"></label>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Turn off Brag Button', 'mainwp' ); ?><?php MainWP_Utility::renderToolTip( __( 'If set to YES, Twitter messages will be turn off', 'mainwp' ) ); ?></th>
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
				</div>
			</div>
			<p class="submit">
				<input type="submit" name="submit" id="submit" class="button-primary button button-hero" value="<?php _e( 'Save Settings', 'mainwp' ); ?>"/>
			</p>
		</form>
		<?php

		self::renderFooter( 'DashboardOptions' );
	}


	public static function renderMainWPTools() {
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
			mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );

			return;
		}

		$wp_menu_items = array(
			'dashboard' => __( 'Dashboard', 'mainwp' ),
			'posts' => __( 'Posts', 'mainwp' ),
			'media' => __( 'Media', 'mainwp' ),
			'pages' => __( 'Pages' ),
			'appearance' => __( 'Appearance', 'mainwp' ),
			'comments' => __( 'Comments', 'mainwp' ),
			'users' => __( 'Users', 'mainwp' ),
			'tools' => __( 'Tools', 'mainwp' ),
		);

		$hide_menus =  get_option('mwp_setup_hide_wp_menus');

		if ( ! is_array( $hide_menus ) ) {
			$hide_menus = array();
		}

		self::renderHeader( 'MainWPTools' );
		?>
		<form method="POST" action="">
			<div class="postbox" id="mainwp-tools">
				<h3 class="mainwp_box_title">
					<span><i class="fa fa-wrench"></i> <?php _e( 'MainWP Tools', 'mainwp' ); ?></span></h3>

				<div class="inside">
					<table class="form-table">
						<tbody>
						<tr>
							<th scope="row"><?php _e( 'Force Dashboard to Establish New Connection', 'mainwp' ); ?><?php MainWP_Utility::renderToolTip( __( 'Use this option to establish new connection with child sites.', 'mainwp' ) ); ?></th>
							<td>
								<input type="submit" name="" id="force-destroy-sessions-button" class="button-primary button" value="<?php _e( 'Establish New Connection', 'mainwp' ); ?>"/><br/>
								<em>
									<?php _e( 'Forces your Dashboard to reconnect with your Child sites. This feature will log out any currently logged in users on the Child sites and require them to re-log in. Only needed if suggested by MainWP Support.', 'mainwp' ); ?>
								</em>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Scan child sites for known issues', 'mainwp' ); ?><?php MainWP_Utility::renderToolTip( __( 'Use this option to scan child sites for known issues.', 'mainwp' ) ); ?></th>
							<td>
								<a href="<?php echo admin_url( 'admin.php?page=MainWP_Child_Scan' ); ?>" class="button-primary button"><?php _e( 'Scan', 'mainwp' ); ?></a><br/>
								<em>
									<?php _e( 'Scans each site individually for known issues.', 'mainwp' ); ?>
								</em>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e('MainWP Quick Setup','mainwp'); ?></th>
							<td>
								<a href="admin.php?page=mainwp-setup" class="button-primary button"/><?php _e('Start Quick Setup','mainwp'); ?></a><br/>
								<em>
									<?php _e('MainWP Quick Setup allows you to quickly set your MainWP Dashboard preferences.','mainwp'); ?>
								</em>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Hide WP Menus', 'mainwp' ); ?></th>
							<td>
								<ul class="mainwp_checkboxes mainwp_hide_wpmenu_checkboxes">
									<?php
									foreach ( $wp_menu_items as $name => $item ) {
										$_selected = '';
										if ( in_array( $name, $hide_menus ) ) {
											$_selected = 'checked'; }
										?>
										<li>
											<input type="checkbox" id="mainwp_hide_wpmenu_<?php echo $name; ?>" name="mainwp_hide_wpmenu[]" <?php echo $_selected; ?> value="<?php echo $name; ?>" class="mainwp-checkbox2">
											<label for="mainwp_hide_wpmenu_<?php echo $name; ?>" class="mainwp-label2"><?php echo $item; ?></label>
										</li>
									<?php }
									?>
								</ul>
							</td>
						</tr>
						</tbody>
					</table>
				</div>
			</div>
			<p class="submit"><input type="submit" name="submit" id="submit" class="button-primary button button-hero" value="<?php _e('Save Settings','mainwp'); ?>"/></p>
		</form>
		<?php
		self::renderFooter( 'MainWPTools' );
	}

	public static function QSGManageSettings() {
		self::renderHeader( 'SettingsHelp' );
		?>
		<div style="text-align: center">
			<a href="#" class="button button-primary" id="mainwp-quick-start-guide"><?php _e( 'Show Quick Start Guide', 'mainwp' ); ?></a>
		</div>
		<div class="mainwp_info-box-yellow" id="mainwp-qsg-tips">
			<span><a href="#" class="mainwp-show-qsg" number="1"><?php _e( 'Settings Overview', 'mainwp' ) ?></a></span><span><a href="#" id="mainwp-qsg-dismiss" style="float: right;"><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss', 'mainwp' ); ?>
				</a></span>

			<div class="clear"></div>
			<div id="mainwp-qsgs">
				<div class="mainwp-qsg" number="1">
					<h3>Settings Overview</h3>

					<p>
					<ol>
						<li>
							<strong>Notification Email</strong> enables you to enter your email address where you want to receive Offline Monitoring alerts, Available Updates notifications and Backups notifications (when backup fails or starts if set in Backup Options section).
						<li>
							<strong>Allow us to count your sites</strong> - provides you ability to enable/disable MainWP from counting your managed sites. This info is used only to show a count of managed blogs. No other information is gathered but number of sites you have connected. Setting it to YES means that you allow us to get this info from your dashboard.
						</li>
						<li>
							<strong>Optimize for big networks</strong> uses a caching function. This option is recommended for networks with 50+ sites. If enabled (Set to YES) updates will be cached for quick loading. A manual refresh from the Dashboard is required to view new plugins, themes, posts, pages, comments and users.
						</li>
						<li>
							<strong>Maximum request / 30 seconds</strong> sets a limit of requests sent to child sites per 30 seconds. Too menu requests can lead to child sites timing out and showing as offline while they are online. On the other side, lower number of requests leads to slower MainWP performance.
						</li>
						<li>
							<strong>View Upgrades per site</strong> - option enables you to choose whether you like to see your updates Per Site or Per Theme/Plugin. If enabled (set to YES) updates in the Right Now widget will be displayed per site, if disabled Updates will be displayed per Theme/Plugin.
						</li>
						<li>
							<strong>Require backup before upgrade</strong> with this option enabled, when you try to upgrade a plugin, theme or WordPress core, MainWP will check if there is a full backup created for the site(s) you are trying to upgrade in last 7 days. If you have a fresh backup of the site(s) MainWP will proceed to the upgrade process, if not it will ask you to create a full backup.
						</li>
						<li>
							<strong>Automatic daily updates</strong> MainWP gives you ability to set automatic updates.
						</li>
						<li>
							<strong>Data Return Options</strong> - In case you have large number of posts/comments, fetching all of them from a child site at once can overload the dashboard and decrease the speed. In worst case scenario, it can crash communication. Here you can set the maximum the maximum number of posts/comments per search
						</li>
						<li>
							<strong>Backups on server</strong> enables you to limit the number of backups you want to store on your server. If set to 3, MainWP will keep only 3 full backups for each of your sites. MainWP always replaces the oldest backup file. This option doesn't affect external sources
						</li>
						<li>
							<strong>Backups on external sources</strong> enables you to limit the number of stored backups on external sources such as Dropbox, Amazon S3 or FTP. This option does not affect the backups on server options. If you don't want to limit the number of backups on external sources, set this option to 0
						</li>
						<li>
							<strong>Send email when backup starts</strong> when scheduled backup starts, MainWP will notify you via email notification if this option is enabled (Set to YES). Notification will be sent to email address set in the Notification Email field.
						</li>
						<li>
							<strong>Execute backups in chunks</strong> - when setting a backup tasks with 5+ scheduled sites, executing backups in chunks means that MainWP will backup 5 by 5 sites with 2 minutes pause between chunks. By enabling this option, you can avoid server timing out while executing scheduled backup tasks.
						</li>
						<li>
							<strong>Online Notification</strong> by default MainWP sends notifications only when your sites are offline. With this option enabled, MainWP will send an email even if your site is online notifying you that everything is okay. Frequency of this emails depends on your settings in the MainWP > Offline Check page.
						</li>
						<li>
							<strong>New Account</strong> enables you to add new Google Analytics account. Here you can add multiple accounts. You need to be logged in your account, once you are logged click the Add GA Account and allow MainWP to access it. To add additional accounts, log out of you current GA account, log into another one and lick the button again.
						</li>
						<li>
							<strong>Accounts</strong> this option shows only when a GA account(s) are added to MainWP. Here you can Disconnect selected account by clicking the Disconnect button.
						</li>
						<li>
							<strong>Time Interval</strong> select the time interval for your GA account. You can choose between Weekly and Monthly setup. This will determine the way how your MainWP GA widget displays statistics.
						</li>
						<li>
							<strong>Refresh Rate</strong> here you can set how often you want MainWP to check for new traffic data and new sites. Also use the Refresh Now button to refresh data on demand.
						</li>
						<li>
							<strong>Client Plugin folder options</strong> By default, files and folders on child sites are viewable. If you set to Hidden, MainWP will hide your files and folders. When hidden, if somebody tries to view your files it will return 404 file. However footprint does still exist.
						</li>
						<li>
							<strong>Turn Off Heatmap</strong> - By disabling Heatmaps (set to YES), you will remove the heatmap javascript footprint in the managed sites.
						</li>
					</ol>
					</p>
				</div>
			</div>
		</div>
		<?php
		self::renderFooter( 'SettingsHelp' );
	}
}
