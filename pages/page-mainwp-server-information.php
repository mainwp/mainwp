<?php

/**
 * MainWP Server Information Page
 */
class MainWP_Server_Information {

	const WARNING = 1;
	const ERROR   = 2;

	public static $subPages;

	public static function getClassName() {
		return __CLASS__;
	}

	public static function initMenu() {
		add_submenu_page( 'mainwp_tab', __( 'Server Information', 'mainwp' ), ' <span id="mainwp-ServerInformation">' . __( 'Server Information', 'mainwp' ) . '</span>', 'read', 'ServerInformation', array(
			self::getClassName(),
			'render',
		) );
		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ServerInformationCron' ) ) {
			add_submenu_page( 'mainwp_tab', __( 'Cron Schedules', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Cron Schedules', 'mainwp' ) . '</div>', 'read', 'ServerInformationCron', array(
				self::getClassName(),
				'renderCron',
			) );
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ErrorLog' ) ) {
			add_submenu_page( 'mainwp_tab', __( 'Error Log', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Error Log', 'mainwp' ) . '</div>', 'read', 'ErrorLog', array(
				self::getClassName(),
				'renderErrorLogPage',
			) );
		}
		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'WPConfig' ) ) {
			add_submenu_page( 'mainwp_tab', __( 'WP-Config File', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'WP-Config File', 'mainwp' ) . '</div>', 'read', 'WPConfig', array(
				self::getClassName(),
				'renderWPConfig',
			) );
		}
		if ( ! MainWP_Menu::is_disable_menu_item( 3, '.htaccess' ) ) {
			if ( self::isApacheServerSoftware() ) {
				add_submenu_page( 'mainwp_tab', __( '.htaccess File', 'mainwp' ), '<div class="mainwp-hidden">' . __( '.htaccess File', 'mainwp' ) . '</div>', 'read', '.htaccess', array(
					self::getClassName(),
					'renderhtaccess',
				) );
			}
		}
		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ActionLogs' ) ) {
			add_submenu_page( 'mainwp_tab', __( 'Action logs', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Action logs', 'mainwp' ) . '</div>', 'read', 'ActionLogs', array(
				self::getClassName(),
				'renderActionLogs',
			) );
		}
		self::$subPages = apply_filters( 'mainwp-getsubpages-server', array() );
		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'Server' . $subPage['slug'] ) ) {
					continue;
				}
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Server' . $subPage['slug'], $subPage['callback'] );
			}
		}
		self::init_left_menu( self::$subPages );
	}

	public static function initMenuSubPages() {
		?>
		<div id="menu-mainwp-ServerInformation" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<a href="<?php echo admin_url( 'admin.php?page=ServerInformation' ); ?>" class="mainwp-submenu"><?php _e( 'Server', 'mainwp' ); ?></a>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ServerInformationCron' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=ServerInformationCron' ); ?>" class="mainwp-submenu"><?php _e( 'Cron Schedules', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ErrorLog' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=ErrorLog' ); ?>" class="mainwp-submenu"><?php _e( 'Error Log', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'WPConfig' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=WPConfig' ); ?>" class="mainwp-submenu"><?php _e( 'WP-Config File', 'mainwp' ); ?></a>
					<?php } ?>
					<?php
					if ( ! MainWP_Menu::is_disable_menu_item( 3, '.htaccess' ) ) {
						if ( self::isApacheServerSoftware() ) {
							?>
							<a href="<?php echo admin_url( 'admin.php?page=.htaccess' ); ?>" class="mainwp-submenu"><?php _e( '.htaccess File', 'mainwp' ); ?></a>
							<?php
						}
					}
					?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( ! isset( $subPage['menu_hidden'] ) || ( isset( $subPage['menu_hidden'] ) && $subPage['menu_hidden'] != true ) ) {
								if ( MainWP_Menu::is_disable_menu_item( 3, 'Server' . $subPage['slug'] ) ) {
									continue;
								}
								?>
								<a href="<?php echo admin_url( 'admin.php?page=Server' . $subPage['slug'] ); ?>" class="mainwp-submenu"><?php echo esc_html($subPage['title']); ?></a>
								<?php
							}
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function init_left_menu( $subPages = array() ) {
		MainWP_Menu::add_left_menu( array(
			'title'      => __( 'Status', 'mainwp' ),
			'parent_key' => 'mainwp_tab',
			'slug'       => 'ServerInformation',
			'href'       => 'admin.php?page=ServerInformation',
			'icon'       => '<i class="server icon"></i>',
		), 1 ); // level 1

		global $_mainwp_menu_active_slugs;
		$_mainwp_menu_active_slugs['ActionLogs'] = 'ServerInformation'; // hidden page

		$init_sub_subleftmenu = array(
			array(
				'title'      => __( 'Server', 'mainwp' ),
				'parent_key' => 'ServerInformation',
				'href'       => 'admin.php?page=ServerInformation',
				'slug'       => 'ServerInformation',
				'right'      => '',
			),
			array(
				'title'      => __( 'Cron Schedules', 'mainwp' ),
				'parent_key' => 'ServerInformation',
				'href'       => 'admin.php?page=ServerInformationCron',
				'slug'       => 'ServerInformationCron',
				'right'      => '',
			),
			array(
				'title'      => __( 'Error Log', 'mainwp' ),
				'parent_key' => 'ServerInformation',
				'href'       => 'admin.php?page=ErrorLog',
				'slug'       => 'ErrorLog',
				'right'      => '',
			),
			array(
				'title'      => __( 'WP-Config File', 'mainwp' ),
				'parent_key' => 'ServerInformation',
				'href'       => 'admin.php?page=WPConfig',
				'slug'       => 'WPConfig',
				'right'      => '',
			),
			array(
				'title'      => __( '.htaccess File', 'mainwp' ),
				'parent_key' => 'ServerInformation',
				'href'       => 'admin.php?page=.htaccess',
				'slug'       => '.htaccess',
				'right'      => '',
			),
		);

		if ( ! self::isApacheServerSoftware() ) {
			if ( $init_sub_subleftmenu[4]['slug'] == '.htaccess' ) {
				unset($init_sub_subleftmenu[4]);
			}
		}

		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ServerInformation', 'Settings' );
		foreach ( $init_sub_subleftmenu as $item ) {
			if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
				continue;
			}
			MainWP_Menu::add_left_menu( $item, 2);
		}
	}

	public static function renderHeader( $shownPage = '' ) {
			$params = array(
				'title' => __( 'Server Information', 'mainwp' ),
			);

			MainWP_UI::render_top_header( $params );

			$renderItems = array();

			$renderItems[] = array(
				'title'  => __( 'Server', 'mainwp' ),
				'href'   => 'admin.php?page=ServerInformation',
				'active' => ( $shownPage == '' ) ? true : false,
			);

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ServerInformationCron' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Cron Schedules', 'mainwp' ),
					'href'   => 'admin.php?page=ServerInformationCron',
					'active' => ( $shownPage == 'ServerInformationCron' ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ErrorLog' ) ) {
				$renderItems[] = array(
					'title'      => __( 'Error Log', 'mainwp' ),
					'href'       => 'admin.php?page=ErrorLog',
					'active'     => ( $shownPage == 'ErrorLog' ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'WPConfig' ) ) {
				$renderItems[] = array(
					'title'  => __( 'WP-Config File', 'mainwp' ),
					'href'   => 'admin.php?page=WPConfig',
					'active' => ( $shownPage == 'WPConfig' ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, '.htaccess' ) ) {
				if ( self::isApacheServerSoftware() ) {
					$renderItems[] = array(
						'title'  => __( '.htaccess File', 'mainwp' ),
						'href'   => 'admin.php?page=.htaccess',
						'active' => ( $shownPage == '.htaccess' ) ? true : false,
					);
				}
			}

			MainWP_UI::render_page_navigation( $renderItems );
			echo '<div class="ui segment">';
	}

	public static function renderFooter( $shownPage ) {
		echo '</div>';
	}

	public static function render() {
		if ( ! mainwp_current_user_can( 'dashboard', 'see_server_information' ) ) {
			mainwp_do_not_have_permissions( __( 'server information', 'mainwp' ) );

			return;
		}

		self::renderHeader( '' );

		do_action( 'mainwp-before-server-info-table' );

		?>
			<div class="ui two column grid">
			<div class="column"></div>
			<div class="right aligned column">
			<a href="#" style="margin-left:5px" class="ui small basic green button" id="mainwp-copy-meta-system-report" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Copy the system report to paste it to the MainWP Community.', 'mainwp' ); ?>"><?php _e( 'Copy System Report for the MainWP Community', 'mainwp' ); ?></a>
			<a href="#" class="ui small green button" id="mainwp-download-system-report"><?php _e( 'Download System Report', 'mainwp' ); ?></a>
			</div>
			</div>
			<table class="ui stackable celled table fixed mainwp-system-info-table">
				<thead>
					<tr>
						<th><?php _e( 'Server Info', 'mainwp' ); ?></th>
						<th><?php _e( 'Required', 'mainwp' ); ?></th>
						<th><?php _e( 'Detected', 'mainwp' ); ?></th>
						<th><?php _e( 'Status', 'mainwp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php _e( 'MainWP Dashboard', 'mainwp' ); ?></div></td></tr>
					<tr>
						<td><?php _e( 'MainWP Dashboard Version', 'mainwp' ); ?></td>
						<td><?php echo self::getMainWPVersion(); ?></td>
						<td><?php echo self::getCurrentVersion(); ?></td>
						<td><?php echo self::getMainWPVersionCheck(); ?></td>
					</tr>
				<?php self::checkDirectoryMainWPDirectory(); ?>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php _e( 'MainWP Extensions', 'mainwp' ); ?></div></td></tr>
				<?php
				$extensions       = MainWP_Extensions::loadExtensions();
				$extensions_slugs = array();
				if ( count( $extensions ) == 0 ) {
					echo '<tr><td colspan="4">' . __( 'No installed extensions', 'mainwp' ) . '</td></tr>';
				}
				foreach ( $extensions as $extension ) {
					$extensions_slugs[] = $extension['slug'];
					?>
						<tr>
							<td><?php echo $extension['name']; ?></td>
							<td><?php echo $extension['version']; ?></td>
							<td><?php echo isset( $extension['activated_key'] ) && $extension['activated_key'] == 'Activated' ? __( 'Active', 'mainwp' ) : __( 'Inactive', 'mainwp' ); ?></td>
							<td><?php echo isset( $extension['activated_key'] ) && $extension['activated_key'] == 'Activated' ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::getWarningHTML( self::WARNING ); ?></td>
						</tr>
						<?php
				}
				?>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php _e( 'WordPress', 'mainwp' ); ?></div></td></tr>
					<?php
					self::renderRow( 'WordPress Version', '>=', '3.6', 'getWordpressVersion', '', '', null, null, self::ERROR );
					self::renderRow( 'WordPress Memory Limit', '>=', '64M', 'getWordpressMemoryLimit', '', '', null );
					self::renderRow( 'MultiSite Disabled', '=', true, 'checkIfMultisite', '', '', null );
					?>
					<tr>
						<td><?php _e( 'FileSystem Method', 'mainwp' ); ?></td>
						<td><?php echo '= direct'; ?></td>
						<td><?php echo self::getFileSystemMethod(); ?></td>
						<td><?php echo self::getFileSystemMethodCheck(); ?></td>
					</tr>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php _e( 'PHP', 'mainwp' ); ?></div></td></tr>
					<?php
					self::renderRow( 'PHP Version', '>=', '5.6', 'getPHPVersion', '', '', null, null, self::ERROR );
					self::renderRow( 'PHP Safe Mode Disabled', '=', true, 'getPHPSafeMode', '', '', null );
					self::renderRow( 'PHP Max Execution Time', '>=', '30', 'getMaxExecutionTime', 'seconds', '=', '0' );
					self::renderRow( 'PHP Max Input Time', '>=', '30', 'getMaxInputTime', 'seconds', '=', '0' );
					self::renderRow( 'PHP Memory Limit', '>=', '128M', 'getPHPMemoryLimit', '', '', null, 'filesize' );
					self::renderRow( 'PCRE Backtracking Limit', '>=', '10000', 'getOutputBufferSize', '', '', null );
					self::renderRow( 'PHP Upload Max Filesize', '>=', '2M', 'getUploadMaxFilesize', '', '', null, 'filesize' );
					self::renderRow( 'PHP Post Max Size', '>=', '2M', 'getPostMaxSize', '', '', null, 'filesize' );
					self::renderRow( 'SSL Extension Enabled', '=', true, 'getSSLSupport', '', '', null );
					self::renderRow( 'SSL Warnings', '=', '', 'getSSLWarning', 'empty', '', null );
					self::renderRow( 'cURL Extension Enabled', '=', true, 'getCurlSupport', '', '', null, null, self::ERROR );
					self::renderRow( 'cURL Timeout', '>=', '300', 'getCurlTimeout', 'seconds', '=', '0' );
					if ( function_exists( 'curl_version' ) ) {
						self::renderRow( 'cURL Version', '>=', '7.18.1', 'getCurlVersion', '', '', null );
						self::renderRow( 'cURL SSL Version', '>=', array(
							'version_number' => 0x009080cf,
							'version'        => 'OpenSSL/0.9.8l',
						), 'getCurlSSLVersion', '', '', null, 'curlssl' );
					}
					?>
					<tr>
						<td colspan="2"><?php _e( 'PHP Allow URL fopen', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getPHPAllowUrlFopen(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'PHP Exif Support', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getPHPExif(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'PHP IPTC Support', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getPHPIPTC(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'PHP XML Support', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getPHPXML(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'PHP Disabled Functions', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::phpDisabledFunctions(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'PHP Loaded Extensions', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getLoadedPHPExtensions(); ?></td>
					</tr>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php _e( 'MySQL', 'mainwp' ); ?></div></td></tr>
					<?php self::renderRow( 'MySQL Version', '>=', '5.0', 'getMySQLVersion', '', '', null, null, self::ERROR ); ?>
					<tr>
						<td colspan="2"><?php _e( 'MySQL Mode', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getSQLMode(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'MySQL Client Encoding', 'mainwp' ); ?></td>
						<td colspan="2"><?php echo defined( 'DB_CHARSET' ) ? DB_CHARSET : ''; ?></td>
					</tr>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php _e( 'Server Info', 'mainwp' ); ?></div></td></tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php _e( 'WordPress Root Directory', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getWPRoot(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php _e( 'Server Name', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getServerName(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'Server Software', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getServerSoftware(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'Operating System', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getOS(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'Architecture', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getArchitecture(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php _e( 'Server IP', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getServerIP(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'Server Protocol', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getServerProtocol(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php _e( 'HTTP Host', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getHTTPHost(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'HTTPS', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getHTTPS(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'Server self connect', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::serverSelfConnect(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'User Agent', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getUserAgent(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php _e( 'Server Port', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getServerPort(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'Gateway Interface', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getServerGatewayInterface(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Memory Usage', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::memoryUsage(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Complete URL', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getCompleteURL(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Request Time', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getServerRequestTime(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php _e( 'Accept Content', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getServerHTTPAccept(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Accept-Charset Content', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getServerAcceptCharset(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Currently Executing Script Pathname', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getScriptFileName(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Current Page URI', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getCurrentPageURI(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Remote Address', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getRemoteAddress(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php _e( 'Remote Host', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getRemoteHost(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php _e( 'Remote Port', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::getRemotePort(); ?></td>
					</tr>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php _e( 'MainWP Settings', 'mainwp' ); ?></div></td></tr>
					<?php self::displayMainWPOptions(); ?>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php _e( 'Active Plugins', 'mainwp' ); ?></div></td></tr>
					<?php
					$all_extensions = MainWP_Extensions_View::getAvailableExtensions();
					$all_plugins    = get_plugins();
					foreach ( $all_plugins as $slug => $plugin ) {
						if ( isset( $all_extensions[ dirname( $slug ) ] ) ) {
							continue;
						}
						?>
						<tr>
							<td><?php echo esc_html($plugin['Name']); ?></td>
							<td><?php echo esc_html($plugin['Version']); ?></td>
							<td colspan="2"><?php echo is_plugin_active( $slug ) ? __( 'Active', 'mainwp' ) : __( 'Inactive', 'mainwp' ); ?></td>
						</tr>
						<?php
					}
					?>
				</tbody>
				<tfoot class="full-width">
				<tr>
				  <th colspan="4">
					<a href="#" class="ui right floated small green button" id="mainwp-download-system-report"><?php _e( 'Download System Report', 'mainwp' ); ?></a>
							<div><?php _e( 'Please include this information when requesting support.', 'mainwp' ); ?></div>
				  </th>
				</tr>
			  </tfoot>
			</table>
			<div id="download-server-information" style="display: none">
				<textarea readonly="readonly" wrap="off"></textarea>
			</div>
		<?php

		do_action( 'mainwp-after-server-info-table' );

		self::renderFooter( '' );
	}

	public static function renderQuickSetupSystemCheck() {
		?>
		<table id="mainwp-quick-system-requirements-check" class="ui tablet stackable single line table">
				<thead>
					<tr>
					<th><?php _e( 'Check', 'mainwp' ); ?></th>
					<th><?php _e( 'Required Value', 'mainwp' ); ?></th>
					<th><?php _e( 'Detected Value', 'mainwp' ); ?></th>
					<th class="collapsing center aligned"><?php _e( 'Status', 'mainwp' ); ?></th>
					</tr>
				</thead>
			<tbody>
					<?php
					self::render_row_with_description( __( 'PHP Version', 'mainwp' ), '>=', '5.6', 'getPHPVersion', '', '', null );
					self::render_row_with_description( __( 'SSL Extension Enabled', 'mainwp' ), '=', true, 'getSSLSupport', '', '', null );
					self::render_row_with_description( __( 'cURL Extension Enabled', 'mainwp' ), '=', true, 'getCurlSupport', '', '', null );
					self::render_row_with_description( __( 'MySQL Version', 'mainwp' ), '>=', '5.0', 'getMySQLVersion', '', '', null );
					?>
				</tbody>
			</table>
		<?php
	}

	public static function is_localhost() {
		$whitelist = array( '127.0.0.1', '::1' );
		if ( in_array( $_SERVER['REMOTE_ADDR'], $whitelist ) ) {
			return true;
		}
		return false;
	}

	public static function getCurrentVersion() {
		$currentVersion = get_option( 'mainwp_plugin_version' );
		return $currentVersion;
	}

	public static function getMainWPVersion() {
		if ( ( isset( $_SESSION['cachedVersion'] ) ) && ( null !== $_SESSION['cachedVersion'] ) && ( ( $_SESSION['cachedTime'] + ( 60 * 30 ) ) > time() ) ) {
			return $_SESSION['cachedVersion'];
		}
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';
		$api = plugins_api( 'plugin_information', array(
			'slug'       => 'mainwp',
			'fields'     => array( 'sections' => false ),
			'timeout'    => 60,
		) );
		if ( is_object( $api ) && isset( $api->version ) ) {
			$_SESSION['cachedTime']    = time();
			$_SESSION['cachedVersion'] = $api->version;
			return $_SESSION['cachedVersion'];
		}
		return false;
	}

	// Compare the detected MainWP Dashboard version agains the verion in WP.org
	public static function getMainWPVersionCheck() {
		$current = get_option( 'mainwp_plugin_version' );
		$latest  = self::getMainwpVersion();
		if ( $current == $latest ) {
			return '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>';
		} else {
			return self::getWarningHTML();
		}
	}

	// Render the Cron Schedule page
	public static function renderCron() {

		if ( ! mainwp_current_user_can( 'dashboard', 'see_server_information' ) ) {
			mainwp_do_not_have_permissions( __( 'cron schedules', 'mainwp' ) );

			return;
		}

		self::renderHeader( 'ServerInformationCron' );

		$cron_jobs = array(
			'Check for available updates'            => array( 'mainwp_cron_last_updatescheck', 'mainwp_cronupdatescheck_action', __( 'Once every minute', 'mainwp' ) ),
			'Check for new statistics'               => array( 'mainwp_cron_last_stats', 'mainwp_cronstats_action', __( 'Once hourly', 'mainwp' ) ),
			'Ping childs sites'                      => array( 'mainwp_cron_last_ping', 'mainwp_cronpingchilds_action', __( 'Once daily', 'mainwp' ) ),
		);

		if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			$cron_jobs['Start backups (Legacy)']    = array( 'mainwp_cron_last_backups', 'mainwp_cronbackups_action', __( 'Once hourly', 'mainwp' ) );
			$cron_jobs['Continue backups (Legacy)'] = array( 'mainwp_cron_last_backups_continue', 'mainwp_cronbackups_continue_action', __( 'Once every five minutes', 'mainwp' ) );
		}

		?>

		<?php do_action( 'mainwp-before-cron-jobs-table' ); ?>

		<table class="ui stackable celled table fixed" id="mainwp-cron-jobs-table">
			<thead>
				<tr>
					<th><?php _e( 'Cron Job', 'mainwp' ); ?></th>
					<th><?php _e( 'Hook', 'mainwp' ); ?></th>
					<th><?php _e( 'Schedule', 'mainwp' ); ?></th>
					<th><?php _e( 'Last Run', 'mainwp' ); ?></th>
					<th><?php _e( 'Next Run', 'mainwp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $cron_jobs as $cron_job => $hook ) {
					$next_run = wp_next_scheduled( $hook[1] );
					?>
				<tr>
					<td><?php echo $cron_job; ?></td>
					<td><?php echo $hook[1]; ?></td>
					<td><?php echo $hook[2]; ?></td>
					<td><?php echo ( get_option( $hook[0] ) === false || get_option( $hook[0] ) == 0 ) ? __( 'Never', 'mainwp' ) : MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( get_option( $hook[0] ) ) ); ?></td>
					<td><?php echo MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $next_run ) ); ?></td>
				</tr>
					<?php
				}
				do_action( 'mainwp-cron-jobs-list' );
				?>
			</tbody>
		</table>
		<script type="text/javascript">
		jQuery('#mainwp-cron-jobs-table').DataTable( {
				"paging": false,
		} );
		</script>
		<?php

		do_action( 'mainwp-after-cron-jobs-table' );

		self::renderFooter( 'ServerInformationCron' );
	}

	// Check if the ../wp-content/uploads/mainwp/ directory is writable
	public static function checkDirectoryMainWPDirectory() {
		$dirs = MainWP_Utility::getMainWPDir();
		$path = $dirs[0];

		if ( ! is_dir( dirname( $path ) ) ) {
			return self::renderDirectoryRow( 'MainWP Upload Directory', 'Writable', 'Not Found', false, self::ERROR );
		}

		$hasWPFileSystem = MainWP_Utility::getWPFilesystem();

		/** @global WP_Filesystem_Base $wp_filesystem */
		global $wp_filesystem;

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
			if ( ! $wp_filesystem->is_writable( $path ) ) {
				return self::renderDirectoryRow( 'MainWP Upload Directory', 'Writable', 'Not Writable', false, self::ERROR );
			}
		} else {
			if ( ! is_writable( $path ) ) {
				return self::renderDirectoryRow( 'MainWP Upload Directory', 'Writable', 'Not Writable', false, self::ERROR );
			}
		}
		return self::renderDirectoryRow( 'MainWP Upload Directory', 'Writable', 'Writable', true, self::ERROR );
	}

	// Print the directory check row
	public static function renderDirectoryRow( $pName, $pCheck, $pResult, $pPassed, $errorType = self::WARNING ) {
		?>
		<tr>
			<td><?php echo $pName; ?></td>
			<td><?php echo $pCheck; ?></td>
			<td><?php echo $pResult; ?></td>
			<td><?php echo( $pPassed ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::getWarningHTML( $errorType ) ); ?></td>
		</tr>
		<?php
		return true;
	}

	public static function renderRow( $pConfig, $pCompare, $pVersion, $pGetter, $pExtraText = '', $pExtraCompare = null, $pExtraVersion = null, $whatType = null, $errorType = self::WARNING ) {
		$currentVersion = call_user_func( array( self::getClassName(), $pGetter ) );
		?>
		<tr>
			<td><?php echo $pConfig; ?></td>
			<td><?php echo $pCompare; ?><?php echo ( $pVersion === true ? 'true' : ( is_array( $pVersion ) && isset( $pVersion['version'] ) ? $pVersion['version'] : $pVersion ) ) . ' ' . $pExtraText; ?></td>
			<td><?php echo( $currentVersion === true ? 'true' : $currentVersion ); ?></td>
			<?php if ( $whatType == 'filesize' ) { ?>
				<td><?php echo( self::filesize_compare( $currentVersion, $pVersion, $pCompare ) ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::getWarningHTML( $errorType ) ); ?></td>
			<?php } elseif ( $whatType == 'curlssl' ) { ?>
				<td><?php echo( self::curlssl_compare( $pVersion, $pCompare ) ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::getWarningHTML( $errorType ) ); ?></td>
			<?php } elseif ( ( $pGetter == 'getMaxInputTime' || $pGetter == 'getMaxExecutionTime' ) && $currentVersion == -1 ) { ?>
				<td><?php echo '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>'; ?></td>
			<?php } else { ?>
				<td><?php echo ( version_compare( $currentVersion, $pVersion, $pCompare ) || ( ( $pExtraCompare != null ) && version_compare( $currentVersion, $pExtraVersion, $pExtraCompare ) ) ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::getWarningHTML( $errorType ) ); ?></td>
		<?php } ?>
		</tr>
		<?php
	}

	public static function render_row_with_description( $pConfig, $pCompare, $pVersion, $pGetter, $pExtraText = '', $pExtraCompare = null, $pExtraVersion = null, $whatType = null, $errorType = self::WARNING ) {
		$currentVersion = call_user_func( array( self::getClassName(), $pGetter ) );
		?>
		<tr>
			<td><?php echo $pConfig; ?></td>
			<td><?php echo $pCompare; ?>  <?php echo ( $pVersion === true ? 'true' : ( is_array( $pVersion ) && isset( $pVersion['version'] ) ? $pVersion['version'] : $pVersion ) ) . ' ' . $pExtraText; ?></td>
			<td><?php echo ( $currentVersion === true ? 'true' : $currentVersion ); ?></td>
			<?php if ( $whatType == 'filesize' ) { ?>
				<td><?php echo ( self::filesize_compare( $currentVersion, $pVersion, $pCompare ) ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::getWarningHTML( $errorType ) ); ?></td>
			<?php } elseif ( $whatType == 'curlssl' ) { ?>
				<td><?php echo ( self::curlssl_compare( $pVersion, $pCompare ) ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::getWarningHTML( $errorType ) ); ?></td>
			<?php } elseif ( $pGetter == 'getMaxInputTime' && $currentVersion == -1 ) { ?>
				<td><?php echo '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>'; ?></td>
		<?php } else { ?>
				<td><?php echo( version_compare( $currentVersion, $pVersion, $pCompare ) || ( ( $pExtraCompare != null ) && version_compare( $currentVersion, $pExtraVersion, $pExtraCompare ) ) ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::getWarningHTML( $errorType ) ); ?></td>
		<?php } ?>
		</tr>
		<?php
	}

	// Check if the Dashboard site is Multisite setup
	public static function checkIfMultisite() {
		$isMultisite = ! is_multisite() ? true : false;

		return $isMultisite;
	}

	public static function checkCURLSSLInfo() {
		$isSupport = ( self::getCurlSupport() && self::getSSLSupport() ) ? true : false;
		$checkCURL = version_compare( self::getCurlVersion(), '7.18.1', '>=' );
		$checkSSL  = self::curlssl_compare( array(
			'version_number' => 0x009080cf,
			'version'        => 'OpenSSL/0.9.8l',
		), '>=' );

		return $isSupport && $checkSSL && $checkCURL;
	}

	public static function filesize_compare( $value1, $value2, $operator = null ) {
		if ( strpos( $value1, 'G' ) !== false ) {
			$value1 = preg_replace( '/[A-Za-z]/', '', $value1 );
			$value1 = intval( $value1 ) * 1024; // Megabyte number
		} else {
			$value1 = preg_replace( '/[A-Za-z]/', '', $value1 ); // Megabyte number
		}

		if ( strpos( $value2, 'G' ) !== false ) {
			$value2 = preg_replace( '/[A-Za-z]/', '', $value2 );
			$value2 = intval( $value2 ) * 1024; // Megabyte number
		} else {
			$value2 = preg_replace( '/[A-Za-z]/', '', $value2 ); // Megabyte number
		}

		return version_compare( $value1, $value2, $operator );
	}

	public static function curlssl_compare( $value, $operator = null ) {
		if ( isset( $value['version_number'] ) && defined( 'OPENSSL_VERSION_NUMBER' ) ) {
			return version_compare( OPENSSL_VERSION_NUMBER, $value['version_number'], $operator );
		}

		return false;
	}

	public static function getFileSystemMethod() {
		$fs = get_filesystem_method();

		return $fs;
	}

	public static function getFileSystemMethodCheck() {
		$fsmethod = self::getFileSystemMethod();
		if ( $fsmethod == 'direct' ) {
			return '<div class="ui green basic label"><i class="check circle icon"></i> Pass</div>';
		} else {
			return self::getWarningHTML();
		}
	}

	public static function getLoadedPHPExtensions() {
		$extensions = get_loaded_extensions();
		sort( $extensions );
		echo implode( ', ', $extensions );
	}

	public static function getWordpressMemoryLimit() {
		return WP_MEMORY_LIMIT;
	}

	public static function getCurlVersion() {
		$curlversion = curl_version();

		return $curlversion['version'];
	}

	public static function getCurlSSLVersion() {
		$curlversion = curl_version();

		return $curlversion['ssl_version'];
	}

	public static function getWordpressVersion() {
		global $wp_version;

		return $wp_version;
	}

	public static function getSSLSupport() {
		return extension_loaded( 'openssl' );
	}

	public static function getSSLWarning() {
		$conf     = array( 'private_key_bits' => 2048 );
		$conf_loc = MainWP_System::get_openssl_conf();
		if ( ! empty( $conf_loc ) ) {
			$conf['config'] = $conf_loc;
		}
		$res = openssl_pkey_new( $conf );
		openssl_pkey_export( $res, $privkey, null, $conf );

		$str = openssl_error_string();

		return ( stristr( $str, 'NCONF_get_string:no value' ) ? '' : $str );
	}

	public static function isOpensslConfigWarning() {
		$ssl_warning = self::getSSLWarning();
		if ( $ssl_warning != '' ) {
			if ( stristr( $ssl_warning, __( 'No such file or directory found', 'mainwp' ) ) !== false ) {
				return true;
			}
		}
		return false;
	}

	public static function getCurlSupport() {
		return function_exists( 'curl_version' );
	}

	public static function getCurlTimeout() {
		return ini_get( 'default_socket_timeout' );
	}

	public static function getPHPVersion() {
		return phpversion();
	}

	public static function getMaxExecutionTime() {
		return ini_get( 'max_execution_time' );
	}

	public static function getMaxInputTime() {
		return ini_get( 'max_input_time' );
	}

	public static function getUploadMaxFilesize() {
		return ini_get( 'upload_max_filesize' );
	}

	public static function getPostMaxSize() {
		return ini_get( 'post_max_size' );
	}

	public static function getMySQLVersion() {
		return MainWP_DB::Instance()->getMySQLVersion();
	}

	public static function getPHPMemoryLimit() {
		return ini_get( 'memory_limit' );
	}

	public static function getOS( $return = false ) {
		if ( $return ) {
			return PHP_OS;
		} else {
			echo PHP_OS;
		}
	}

	public static function getArchitecture() {
		echo( PHP_INT_SIZE * 8 )
		?>
		&nbsp;bit 
		<?php
	}

	public static function memoryUsage() {
		if ( function_exists( 'memory_get_usage' ) ) {
			$memory_usage = round( memory_get_usage() / 1024 / 1024, 2 ) . __( ' MB' );
		} else {
			$memory_usage = __( 'N/A' );
		}
		echo $memory_usage;
	}

	public static function getOutputBufferSize() {
		return ini_get( 'pcre.backtrack_limit' );
	}

	public static function getPHPSafeMode() {
		if ( version_compare( self::getPHPVersion(), '5.3.0' ) >= 0 ) {
			return true;
		}

		if ( ini_get( 'safe_mode' ) ) {
			return false;
		}

		return true;
	}

	public static function getSQLMode() {
		global $wpdb;
		$mysqlinfo = $wpdb->get_results( "SHOW VARIABLES LIKE 'sql_mode'" );
		if ( is_array( $mysqlinfo ) ) {
			$sql_mode = $mysqlinfo[0]->Value;
		}
		if ( empty( $sql_mode ) ) {
			$sql_mode = __( 'NOT SET' );
		}
		echo $sql_mode;
	}

	public static function getPHPAllowUrlFopen() {
		if ( ini_get( 'allow_url_fopen' ) ) {
			$allow_url_fopen = __( 'YES', 'mainwp' );
		} else {
			$allow_url_fopen = __( 'NO', 'mainwp' );
		}
		echo $allow_url_fopen;
	}

	public static function getPHPExif() {
		if ( is_callable( 'exif_read_data' ) ) {
			$exif = __( 'YES', 'mainwp' ) . ' ( V' . substr( phpversion( 'exif' ), 0, 4 ) . ')';
		} else {
			$exif = __( 'NO', 'mainwp' );
		}
		echo $exif;
	}

	public static function getPHPIPTC() {
		if ( is_callable( 'iptcparse' ) ) {
			$iptc = __( 'YES', 'mainwp' );
		} else {
			$iptc = __( 'NO', 'mainwp' );
		}
		echo $iptc;
	}

	public static function getPHPXML() {
		if ( is_callable( 'xml_parser_create' ) ) {
			$xml = __( 'YES', 'mainwp' );
		} else {
			$xml = __( 'NO', 'mainwp' );
		}
		echo $xml;
	}

	public static function getCurrentlyExecutingScript() {
		echo $_SERVER['PHP_SELF'];
	}

	public static function getServerGatewayInterface() {
		echo isset($_SERVER['GATEWAY_INTERFACE']) ? $_SERVER['GATEWAY_INTERFACE'] : '';
	}

	public static function getServerIP() {
		echo $_SERVER['SERVER_ADDR'];
	}

	public static function getServerName( $return = false ) {
		if ( $return ) {
			return $_SERVER['SERVER_NAME'];
		} else {
			echo $_SERVER['SERVER_NAME'];
		}
	}

	public static function getServerSoftware( $return = false ) {
		if ( $return ) {
			return $_SERVER['SERVER_SOFTWARE'];
		} else {
			echo $_SERVER['SERVER_SOFTWARE'];
		}
	}

	public static function isApacheServerSoftware( $return = false ) {
		$server = self::getServerSoftware(true);
		return ( stripos( $server, 'apache' ) !== false ) ? true : false;
	}

	public static function getServerProtocol() {
		echo $_SERVER['SERVER_PROTOCOL'];
	}

	public static function getServerRequestMethod() {
		echo $_SERVER['REQUEST_METHOD'];
	}

	public static function getServerRequestTime() {
		echo $_SERVER['REQUEST_TIME'];
	}

	public static function getServerQueryString() {
		echo $_SERVER['QUERY_STRING'];
	}

	public static function getServerHTTPAccept() {
		echo $_SERVER['HTTP_ACCEPT'];
	}

	public static function getServerAcceptCharset() {
		if ( ! isset( $_SERVER['HTTP_ACCEPT_CHARSET'] ) || ( $_SERVER['HTTP_ACCEPT_CHARSET'] == '' ) ) {
			echo __( 'N/A', 'mainwp' );
		} else {
			echo $_SERVER['HTTP_ACCEPT_CHARSET'];
		}
	}

	public static function getHTTPHost() {
		echo $_SERVER['HTTP_HOST'];
	}

	public static function getCompleteURL() {
		echo isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '';
	}

	public static function getUserAgent() {
		echo $_SERVER['HTTP_USER_AGENT'];
	}

	public static function getHTTPS() {
		if ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] != '' ) {
			echo __( 'ON', 'mainwp' ) . ' - ' . $_SERVER['HTTPS'];
		} else {
			echo __( 'OFF', 'mainwp' );
		}
	}

	public static function serverSelfConnect() {
		$url         = site_url( 'wp-cron.php' );
		$query_args  = array( 'mainwp_run' => 'test' );
		$url         = esc_url_raw( add_query_arg( $query_args, $url ) );
		$args        = array(
			'blocking'   => true,
			'sslverify'  => apply_filters( 'https_local_ssl_verify', true ),
			'timeout'    => 15,
		);
		$response    = wp_remote_post( $url, $args );
		$test_result = '';
		if ( is_wp_error( $response ) ) {
			$test_result .= sprintf( __( 'The HTTP response test get an error "%s"', 'mainwp' ), $response->get_error_message() );
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 && $response_code > 204 ) {
			$test_result .= sprintf( __( 'The HTTP response test get a false http status (%s)', 'mainwp' ), wp_remote_retrieve_response_code( $response ) );
		} else {
			$response_body = wp_remote_retrieve_body( $response );
			if ( false === strstr( $response_body, 'MainWP Test' ) ) {
				$test_result .= sprintf( __( 'Not expected HTTP response body: %s', 'mainwp' ), esc_attr( wp_strip_all_tags( $response_body ) ) );
			}
		}
		if ( empty( $test_result ) ) {
			_e( 'Response Test O.K.', 'mainwp' );
		} else {
			echo $test_result;
		}
	}

	public static function getRemoteAddress() {
		echo $_SERVER['REMOTE_ADDR'];
	}

	public static function getRemoteHost() {
		if ( ! isset( $_SERVER['REMOTE_HOST'] ) || ( $_SERVER['REMOTE_HOST'] == '' ) ) {
			echo __( 'N/A', 'mainwp' );
		} else {
			echo $_SERVER['REMOTE_HOST'];
		}
	}

	public static function getRemotePort() {
		echo $_SERVER['REMOTE_PORT'];
	}

	public static function getScriptFileName() {
		echo $_SERVER['SCRIPT_FILENAME'];
	}

	public static function getServerAdmin() {
		echo $_SERVER['SERVER_ADMIN'];
	}

	public static function getServerPort() {
		echo $_SERVER['SERVER_PORT'];
	}

	public static function getServerSignature() {
		echo $_SERVER['SERVER_SIGNATURE'];
	}

	public static function getServerPathTranslated() {
		if ( ! isset( $_SERVER['PATH_TRANSLATED'] ) || ( $_SERVER['PATH_TRANSLATED'] == '' ) ) {
			echo __( 'N/A', 'mainwp' );
		} else {
			echo $_SERVER['PATH_TRANSLATED'];
		}
	}

	public static function getScriptName() {
		echo $_SERVER['SCRIPT_NAME'];
	}

	public static function getCurrentPageURI() {
		echo $_SERVER['REQUEST_URI'];
	}

	public static function getWPRoot() {
		echo ABSPATH;
	}

	public function formatSizeUnits( $bytes ) {
		if ( $bytes >= 1073741824 ) {
			$bytes = number_format( $bytes / 1073741824, 2 ) . ' GB';
		} elseif ( $bytes >= 1048576 ) {
			$bytes = number_format( $bytes / 1048576, 2 ) . ' MB';
		} elseif ( $bytes >= 1024 ) {
			$bytes = number_format( $bytes / 1024, 2 ) . ' KB';
		} elseif ( $bytes > 1 ) {
			$bytes = $bytes . ' bytes';
		} elseif ( $bytes == 1 ) {
			$bytes = $bytes . ' byte';
		} else {
			$bytes = '0 bytes';
		}

		return $bytes;
	}

	/*
	 * Plugin Name: Error Log Dashboard Widget
	 * Plugin URI: http://wordpress.org/extend/plugins/error-log-dashboard-widget/
	 * Description: Robust zero-configuration and low-memory way to keep an eye on error log.
	 * Author: Andrey "Rarst" Savchenko
	 * Author URI: http://www.rarst.net/
	 * Version: 1.0.2
	 * License: GPLv2 or later

	 * Includes last_lines() function by phant0m, licensed under cc-wiki and GPLv2+
	 */

	public static function renderErrorLogPage() {

		if ( ! mainwp_current_user_can( 'dashboard', 'see_server_information' ) ) {
			mainwp_do_not_have_permissions( __( 'error log', 'mainwp' ) );

			return;
		}

		self::renderHeader( 'ErrorLog' );
		?>
		<table class="ui stackable celled table" id="mainwp-error-log-table">
			<thead>
				<tr>
					<th><?php _e( 'Time', 'mainwp' ); ?></th>
					<th><?php _e( 'Error', 'mainwp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php self::renderErrorLog(); ?>
			</tbody>
		</table>
		<?php
		self::renderFooter( 'ErrorLog' );
	}

	public static function renderErrorLog() {
		$log_errors = ini_get( 'log_errors' );
		if ( ! $log_errors ) {
			echo '<tr><td colspan="2">' . __( 'Error logging disabled.', 'mainwp' );
			echo '<br/>' . sprintf( __( 'To enable error logging, please check this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://codex.wordpress.org/Debugging_in_WordPress" target="_blank">', '</a>' );
			echo '</td></tr>';
		}

		$error_log = ini_get( 'error_log' );
		$logs      = apply_filters( 'error_log_mainwp_logs', array( $error_log ) );
		$count     = apply_filters( 'error_log_mainwp_lines', 50 );
		$lines     = array();

		foreach ( $logs as $log ) {

			if ( is_readable( $log ) ) {
				$lines = array_merge( $lines, self::last_lines( $log, $count ) );
			}
		}

		$lines = array_map( 'trim', $lines );
		$lines = array_filter( $lines );

		if ( empty( $lines ) ) {

			echo '<tr><td colspan="2">' . __( 'MainWP is unable to find your error logs, please contact your host for server error logs.', 'mainwp' ) . '</td></tr>';

			return;
		}

		foreach ( $lines as $key => $line ) {

			if ( false != strpos( $line, ']' ) ) {
				list( $time, $error ) = explode( ']', $line, 2 );
			} else {
				list( $time, $error ) = array( '', $line );
			}

			$time          = trim( $time, '[]' );
			$error         = trim( $error );
			$lines[ $key ] = compact( 'time', 'error' );
		}

		if ( count( $lines ) > 1 ) {

			uasort( $lines, array( __CLASS__, 'time_compare' ) );
			$lines = array_slice( $lines, 0, $count );
		}

		foreach ( $lines as $line ) {

			$error = esc_html( $line['error'] );
			$time  = esc_html( $line['time'] );
			if ( ! empty( $error ) ) {
				echo "<tr><td>{$time}</td><td>{$error}</td></tr>";
			}
		}
	}

	public static function time_compare( $a, $b ) {

		if ( $a == $b ) {
			return 0;
		}

		return ( strtotime( $a['time'] ) > strtotime( $b['time'] ) ) ? - 1 : 1;
	}

	public static function last_lines( $path, $line_count, $block_size = 512 ) {
		$lines = array();

		// we will always have a fragment of a non-complete line
		// keep this in here till we have our next entire line.
		$leftover = '';

		$fh = fopen( $path, 'r' );
		// go to the end of the file
		fseek( $fh, 0, SEEK_END );

		do {
			// need to know whether we can actually go back
			// $block_size bytes
			$can_read = $block_size;

			if ( ftell( $fh ) <= $block_size ) {
				$can_read = ftell( $fh );
			}

			if ( empty( $can_read ) ) {
				break;
			}

			// go back as many bytes as we can
			// read them to $data and then move the file pointer
			// back to where we were.
			fseek( $fh, - $can_read, SEEK_CUR );
			$data  = fread( $fh, $can_read );
			$data .= $leftover;
			fseek( $fh, - $can_read, SEEK_CUR );

			// split lines by \n. Then reverse them,
			// now the last line is most likely not a complete
			// line which is why we do not directly add it, but
			// append it to the data read the next time.
			$split_data = array_reverse( explode( "\n", $data ) );
			$new_lines  = array_slice( $split_data, 0, - 1 );
			$lines      = array_merge( $lines, $new_lines );
			$leftover   = $split_data[ count( $split_data ) - 1 ];
		} while ( count( $lines ) < $line_count && ftell( $fh ) != 0 );

		if ( ftell( $fh ) == 0 ) {
			$lines[] = $leftover;
		}

		fclose( $fh );

		// Usually, we will read too many lines, correct that here.
		return array_slice( $lines, 0, $line_count );
	}

	public static function renderWPConfig() {

		if ( ! mainwp_current_user_can( 'dashboard', 'see_server_information' ) ) {
			mainwp_do_not_have_permissions( __( 'WP-Config.php', 'mainwp' ) );

			return;
		}

		self::renderHeader( 'WPConfig' );
		?>
		<div id="mainwp-show-wp-config">
			<?php
			if ( false !== strpos( ini_get( 'disable_functions' ), 'show_source') ) {
				echo __( 'File content could not be displayed.', 'mainwp' );
				echo '<br />';
				echo __( 'It appears that the show_source() PHP function has been disabled on the servre.', 'mainwp' );
				echo '<br />';
				echo __( 'Please, contact your host support and have them enable the show_source() function for the proper functioning of this feature.', 'mainwp' );
			} else {
				if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
					@show_source( ABSPATH . 'wp-config.php' );
				} else {

					$files       = @get_included_files();
					$configFound = false;
					if ( is_array( $files ) ) {
						foreach ( $files as $file ) {
							if ( stristr( $file, 'wp-config.php' ) ) {
								$configFound = true;
								@show_source( $file );
								break;
							}
						}
					}

					if ( ! $configFound ) {
						_e( 'wp-config.php not found', 'mainwp' );
					}
				}
			}
			?>
		</div>
		<?php
		self::renderFooter( 'WPConfig' );
	}

	public static function renderActionLogs() {
		self::renderHeader( 'Action logs' );

		if ( isset( $_REQUEST['actionlogs_status'] ) ) {
			if ( $_REQUEST['actionlogs_status'] != MainWP_Logger::DISABLED ) {
				MainWP_Logger::Instance()->setLogPriority( $_REQUEST['actionlogs_status'] );
			}

			MainWP_Logger::Instance()->log( 'Action logs set to: ' . MainWP_Logger::Instance()->getLogText( $_REQUEST['actionlogs_status'] ), MainWP_Logger::LOG );

			if ( $_REQUEST['actionlogs_status'] == MainWP_Logger::DISABLED ) {
				MainWP_Logger::Instance()->setLogPriority( $_REQUEST['actionlogs_status'] );
			}

			MainWP_Utility::update_option( 'mainwp_actionlogs', $_REQUEST['actionlogs_status'] );
		}

		if ( isset( $_REQUEST['actionlogs_clear'] ) ) {
			MainWP_Logger::clearLog();
		}

		$enabled = get_option( 'mainwp_actionlogs' );
		if ( $enabled === false ) {
			$enabled = MainWP_Logger::DISABLED;
		}
		?>
		<div class="postbox">
			<h3 class="hndle" style="padding: 8px 12px; font-size: 14px;"><span>Action logs</span></h3>

			<div style="padding: 1em;">
				<form method="POST" action="">
					Status:
					<select name="actionlogs_status">
						<option value="<?php echo MainWP_Logger::DISABLED; ?>" 
												  <?php
													if ( MainWP_Logger::DISABLED == $enabled ) :
														echo 'selected';
		endif;
													?>
		>Disabled
						</option>
						<option value="<?php echo MainWP_Logger::WARNING; ?>" 
												  <?php
													if ( MainWP_Logger::WARNING == $enabled ) :
														echo 'selected';
						endif;
													?>
						>Warning
						</option>
						<option value="<?php echo MainWP_Logger::INFO; ?>" 
												  <?php
													if ( MainWP_Logger::INFO == $enabled ) :
														echo 'selected';
								endif;
													?>
								>Info
						</option>
						<option value="<?php echo MainWP_Logger::DEBUG; ?>" 
												  <?php
													if ( MainWP_Logger::DEBUG == $enabled ) :
														echo 'selected';
								endif;
													?>
								>Debug
						</option>
						 <option value="<?php echo MainWP_Logger::INFO_UPDATE; ?>" 
												   <?php
													if ( MainWP_Logger::INFO_UPDATE == $enabled ) :
														echo 'selected';
							endif;
													?>
							>Info Update
						</option>
					</select> <input type="submit" class="button button-primary" value="Save"/> <input type="submit" class="button button-primary" name="actionlogs_clear" value="Clear"/>
				</form>
			</div>
			<div style="padding: 1em;"><?php MainWP_Logger::showLog(); ?></div>
		</div>
		<?php
		self::renderFooter( 'Action logs' );
	}

	public static function renderhtaccess() {

		if ( ! mainwp_current_user_can( 'dashboard', 'see_server_information' ) ) {
			mainwp_do_not_have_permissions( __( '.htaccess', 'mainwp' ) );

			return;
		}

		self::renderHeader( '.htaccess' );
		?>
		<div id="mainwp-show-htaccess">
			<?php
			if ( false !== strpos( ini_get( 'disable_functions' ), 'show_source') ) {
				echo __( 'File content could not be displayed.', 'mainwp' );
				echo '<br />';
				echo __( 'It appears that the show_source() PHP function has been disabled on the servre.', 'mainwp' );
				echo '<br />';
				echo __( 'Please, contact your host support and have them enable the show_source() function for the proper functioning of this feature.', 'mainwp' );
			} else {
				show_source( ABSPATH . '.htaccess' );
			}
			?>
		</div>
		<?php
		self::renderFooter( '.htaccess' );
	}

	// Check for the disabled php functions.
	public static function phpDisabledFunctions() {
		$disabled_functions = ini_get( 'disable_functions' );
		if ( $disabled_functions != '' ) {
			$arr = explode( ',', $disabled_functions );
			sort( $arr );
			$_count = count( $arr );
			for ( $i = 0; $i < $_count; $i ++ ) {
				echo $arr[ $i ] . ', ';
			}
		} else {
			echo __( 'No functions disabled.', 'mainwp' );
		}
	}

	public static function mainwpOptions() {
		$mainwp_options = array(
			'mainwp_number_of_child_sites'           => __( 'Number Of Child Sites', 'mainwp' ),
			'mainwp_wp_cron'                         => __( 'Use WP-Cron', 'mainwp' ),
			'mainwp_optimize'                        => __( 'Optimize for Shared Hosting or Big Networks', 'mainwp' ),
			'mainwp_automaticDailyUpdate'            => __( 'Automatic Daily Update', 'mainwp' ),
			'mainwp_numberdays_Outdate_Plugin_Theme' => __( 'Abandoned Plugins/Themes Tolerance', 'mainwp' ),
			'mainwp_maximumPosts'                    => __( 'Maximum number of posts to return', 'mainwp' ),
			'mainwp_maximumPages'                    => __( 'Maximum number of pages to return', 'mainwp' ),
			'mainwp_maximumComments'                 => __( 'Maximum Number of Comments', 'mainwp' ),
			'mainwp_primaryBackup'                   => __( 'Primary Backup System', 'mainwp' ),
			'mainwp_maximumRequests'                 => __( 'Maximum simultaneous requests', 'mainwp' ),
			'mainwp_minimumDelay'                    => __( 'Minimum delay between requests', 'mainwp' ),
			'mainwp_maximumIPRequests'               => __( 'Maximum simultaneous requests per ip', 'mainwp' ),
			'mainwp_minimumIPDelay'                  => __( 'Minimum delay between requests to the same ip', 'mainwp' ),
			'mainwp_maximumSyncRequests'             => __( 'Maximum simultaneous sync requests', 'mainwp' ),
			'mainwp_maximumInstallUpdateRequests'    => __( 'Minimum simultaneous install/update requests', 'mainwp' ),
		);

		if ( ! MainWP_Extensions::isExtensionAvailable( 'mainwp-comments-extension' ) ) {
			unset( $mainwp_options['mainwp_maximumComments'] );
		}

		$options_value = array();
		$userExtension = MainWP_DB::Instance()->getUserExtension();
		foreach ( $mainwp_options as $opt => $label ) {
			$value = get_option( $opt, false );
			switch ( $opt ) {
				case 'mainwp_number_of_child_sites':
					$value = MainWP_DB::Instance()->getWebsitesCount();
					break;
				case 'mainwp_primaryBackup':
					$value = __( 'Default MainWP Backups', 'mainwp' );
					break;
				case 'mainwp_numberdays_Outdate_Plugin_Theme';
				case 'mainwp_maximumPosts';
				case 'mainwp_maximumPages';
				case 'mainwp_maximumComments';
				case 'mainwp_maximumSyncRequests';
				case 'mainwp_maximumInstallUpdateRequests';
					break;
				case 'mainwp_automaticDailyUpdate':
					if ( $value == 1 ) {
						$value = 'Install trusted updates';
					} else {
						$value = 'Disabled';
					}
					break;
				case 'mainwp_maximumRequests':
					$value = ( $value === false ) ? 4 : $value;
					break;
				case 'mainwp_maximumIPRequests':
					$value = ( $value === false ) ? 1 : $value;
					break;
				case 'mainwp_minimumIPDelay':
					$value = ( $value === false ) ? 1000 : $value;
					break;
				case 'mainwp_minimumDelay':
					$value = ( $value === false ) ? 200 : $value;
					break;
				default:
					$value = empty( $value ) ? 'No' : 'Yes';
					break;
			}
			$options_value[ $opt ] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		$primaryBackup        = get_option( 'mainwp_primaryBackup' );
		$primaryBackupMethods = apply_filters( 'mainwp-getprimarybackup-methods', array() );
		if ( ! is_array( $primaryBackupMethods ) ) {
			$primaryBackupMethods = array();
		}

		if ( count( $primaryBackupMethods ) > 0 ) {
			$chk = false;
			foreach ( $primaryBackupMethods as $method ) {
				if ( $primaryBackup == $method['value'] ) {
					$value = $method['title'];
					$chk   = true;
					break;
				}
			}
			if ( $chk ) {
				$options_value['mainwp_primaryBackup'] = array(
					'label' => __( 'Primary Backup System', 'mainwp' ),
					'value' => $value,
				);
			}
		}
		return $options_value;
	}

	public static function displayMainWPOptions() {
		$options = self::mainwpOptions();
		foreach ( $options as $option ) {
			echo '<tr><td colspan="2">' . $option['label'] . '</td><td colspan="2">' . $option['value'] . '</td></tr>';
		}
	}

	private static function getWarningHTML( $errorType = self::WARNING ) {
		if ( self::WARNING == $errorType ) {
			return '<div class="ui yellow basic label"><i class="exclamation circle icon"></i> ' . __( 'Warning', 'mainwp' ) . '</div>';
		}
		return '<span class="ui red basic label"><i class="times circle icon"></i> ' . __( 'Fail', 'mainwp' ) . '</div>';
	}

}
