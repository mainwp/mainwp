<?php
/**
 * MainWP Server Information Page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Server_Information
 */
class MainWP_Server_Information {

	const WARNING = 1;
	const ERROR   = 2;

	/**
	 * The Server information page sub-pages.
	 *
	 * @static
	 * @var array Sub pages.
	 */
	public static $subPages;

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method init_menu()
	 *
	 * Initiate Server Informaion subPage menu.
	 */
	public static function init_menu() {
		add_submenu_page(
			'mainwp_tab',
			__( 'Server Information', 'mainwp' ),
			' <span id="mainwp-ServerInformation">' . __( 'Server Information', 'mainwp' ) . '</span>',
			'read',
			'ServerInformation',
			array(
				self::get_class_name(),
				'render',
			)
		);
		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ServerInformationCron' ) ) {
			add_submenu_page(
				'mainwp_tab',
				__( 'Cron Schedules', 'mainwp' ),
				'<div class="mainwp-hidden">' . __( 'Cron Schedules', 'mainwp' ) . '</div>',
				'read',
				'ServerInformationCron',
				array(
					self::get_class_name(),
					'render_cron',
				)
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ErrorLog' ) ) {
			add_submenu_page(
				'mainwp_tab',
				__( 'Error Log', 'mainwp' ),
				'<div class="mainwp-hidden">' . __( 'Error Log', 'mainwp' ) . '</div>',
				'read',
				'ErrorLog',
				array(
					self::get_class_name(),
					'render_error_log_page',
				)
			);
		}
		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'WPConfig' ) ) {
			add_submenu_page(
				'mainwp_tab',
				__( 'WP-Config File', 'mainwp' ),
				'<div class="mainwp-hidden">' . __( 'WP-Config File', 'mainwp' ) . '</div>',
				'read',
				'WPConfig',
				array(
					self::get_class_name(),
					'render_wp_config',
				)
			);
		}
		if ( ! MainWP_Menu::is_disable_menu_item( 3, '.htaccess' ) ) {
			if ( MainWP_Server_Information_Handler::is_apache_server_software() ) {
				add_submenu_page(
					'mainwp_tab',
					__( '.htaccess File', 'mainwp' ),
					'<div class="mainwp-hidden">' . __( '.htaccess File', 'mainwp' ) . '</div>',
					'read',
					'.htaccess',
					array(
						self::get_class_name(),
						'render_htaccess',
					)
				);
			}
		}
		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ActionLogs' ) ) {
			add_submenu_page(
				'mainwp_tab',
				__( 'Action logs', 'mainwp' ),
				'<div class="mainwp-hidden">' . __( 'Action logs', 'mainwp' ) . '</div>',
				'read',
				'ActionLogs',
				array(
					self::get_class_name(),
					'render_action_logs',
				)
			);
		}

		/**
		 * Filter mainwp_getsubpages_server
		 *
		 * Filters subpages for the Status page.
		 *
		 * @since Unknown
		 */
		$sub_pages      = apply_filters_deprecated( 'mainwp-getsubpages-server', array( array() ), '4.0.7.2', 'mainwp_getsubpages_server' );
		self::$subPages = apply_filters( 'mainwp_getsubpages_server', $sub_pages );

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

	/**
	 * Renders Sub Pages Menu.
	 */
	public static function init_subpages_menu() {
		?>
		<div id="menu-mainwp-ServerInformation" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<a href="<?php echo admin_url( 'admin.php?page=ServerInformation' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Server', 'mainwp' ); ?></a>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ServerInformationCron' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=ServerInformationCron' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Cron Schedules', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ErrorLog' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=ErrorLog' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Error Log', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'WPConfig' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=WPConfig' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'WP-Config File', 'mainwp' ); ?></a>
					<?php } ?>
					<?php
					if ( ! MainWP_Menu::is_disable_menu_item( 3, '.htaccess' ) ) {
						if ( MainWP_Server_Information_Handler::is_apache_server_software() ) {
							?>
							<a href="<?php echo admin_url( 'admin.php?page=.htaccess' ); ?>" class="mainwp-submenu"><?php esc_html_e( '.htaccess File', 'mainwp' ); ?></a>
							<?php
						}
					}
					?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( ! isset( $subPage['menu_hidden'] ) || ( isset( $subPage['menu_hidden'] ) && true !== $subPage['menu_hidden'] ) ) {
								if ( MainWP_Menu::is_disable_menu_item( 3, 'Server' . $subPage['slug'] ) ) {
									continue;
								}
								?>
								<a href="<?php echo admin_url( 'admin.php?page=Server' . $subPage['slug'] ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
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

	/**
	 * Initiates Server Information left menu.
	 *
	 * @param array $subPages array of subpages.
	 */
	public static function init_left_menu( $subPages = array() ) {
		MainWP_Menu::add_left_menu(
			array(
				'title'      => __( 'Status', 'mainwp' ),
				'parent_key' => 'mainwp_tab',
				'slug'       => 'ServerInformation',
				'href'       => 'admin.php?page=ServerInformation',
				'icon'       => '<i class="server icon"></i>',
			),
			1
		);

		/**
		 * MainWP active menu slugs array.
		 *
		 * @global object
		 */
		global $_mainwp_menu_active_slugs;

		$_mainwp_menu_active_slugs['ActionLogs'] = 'ServerInformation';

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

		if ( ! MainWP_Server_Information_Handler::is_apache_server_software() ) {
			if ( '.htaccess' === $init_sub_subleftmenu[4]['slug'] ) {
				unset( $init_sub_subleftmenu[4] );
			}
		}

		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ServerInformation', 'Server' );
		foreach ( $init_sub_subleftmenu as $item ) {
			if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
				continue;
			}
			MainWP_Menu::add_left_menu( $item, 2 );
		}
	}

	/**
	 * Renders Server Information header.
	 *
	 * @param string $shownPage Current page.
	 */
	public static function render_header( $shownPage = '' ) {
			$params = array(
				'title' => __( 'Server Information', 'mainwp' ),
			);

			MainWP_UI::render_top_header( $params );

			$renderItems = array();

			$renderItems[] = array(
				'title'  => __( 'Server', 'mainwp' ),
				'href'   => 'admin.php?page=ServerInformation',
				'active' => ( '' === $shownPage ) ? true : false,
			);

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ServerInformationCron' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Cron Schedules', 'mainwp' ),
					'href'   => 'admin.php?page=ServerInformationCron',
					'active' => ( 'ServerInformationCron' === $shownPage ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ErrorLog' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Error Log', 'mainwp' ),
					'href'   => 'admin.php?page=ErrorLog',
					'active' => ( 'ErrorLog' === $shownPage ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'WPConfig' ) ) {
				$renderItems[] = array(
					'title'  => __( 'WP-Config File', 'mainwp' ),
					'href'   => 'admin.php?page=WPConfig',
					'active' => ( 'WPConfig' === $shownPage ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, '.htaccess' ) ) {
				if ( MainWP_Server_Information_Handler::is_apache_server_software() ) {
					$renderItems[] = array(
						'title'  => __( '.htaccess File', 'mainwp' ),
						'href'   => 'admin.php?page=.htaccess',
						'active' => ( '.htaccess' === $shownPage ) ? true : false,
					);
				}
			}

			MainWP_UI::render_page_navigation( $renderItems );
			echo '<div class="ui segment">';
	}

	/**
	 * Renders Server Information footer.
	 *
	 * @param string $shownPage Current page.
	 */
	public static function render_footer( $shownPage ) {
		echo '</div>';
	}

	/**
	 * Renders Server Information page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'see_server_information' ) ) {
			mainwp_do_not_have_permissions( 'server information', 'mainwp' );
			return;
		}
		self::render_header( '' );

		/**
		 * Action: mainwp_before_server_info_table
		 *
		 * Fires on the top of the Status page, before the Server Info table.
		 *
		 * @since 4.0
		 */
		do_action( 'mainwp_before_server_info_table' );
		?>
			<div class="ui two column grid">
			<div class="column"></div>
			<div class="right aligned column">
			<a href="#" style="margin-left:5px" class="ui small basic green button" id="mainwp-copy-meta-system-report" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Copy the system report to paste it to the MainWP Community.', 'mainwp' ); ?>"><?php esc_html_e( 'Copy System Report for the MainWP Community', 'mainwp' ); ?></a>
			<a href="#" class="ui small green button" id="mainwp-download-system-report"><?php esc_html_e( 'Download System Report', 'mainwp' ); ?></a>
			</div>
			</div>
			<table class="ui stackable celled table fixed mainwp-system-info-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Server Info', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Required', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Detected', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php esc_html_e( 'MainWP Dashboard', 'mainwp' ); ?></div></td></tr>
					<tr>
						<td><?php esc_html_e( 'MainWP Dashboard Version', 'mainwp' ); ?></td>
						<td><?php echo MainWP_Server_Information_Handler::get_mainwp_version(); ?></td>
						<td><?php echo MainWP_Server_Information_Handler::get_current_version(); ?></td>
						<td><?php echo self::get_mainwp_version_check(); ?></td>
					</tr>
				<?php self::check_directory_mainwp_directory(); ?>
					<tr>
						<td colspan="4"><div class="ui ribbon inverted grey label"><?php esc_html_e( 'MainWP Extensions', 'mainwp' ); ?></div></td>
					</tr>
				<?php
				$extensions       = MainWP_Extensions_Handler::get_extensions();
				$extensions_slugs = array();
				if ( 0 == count( $extensions ) ) {
					echo '<tr><td colspan="4">' . esc_html__( 'No installed extensions', 'mainwp' ) . '</td></tr>';
				}
				foreach ( $extensions as $extension ) {
					$extensions_slugs[] = $extension['slug'];
					?>
						<tr>
							<td><?php echo esc_html( $extension['name'] ); ?></td>
							<td><?php echo esc_html( $extension['version'] ); ?></td>
							<?php
							if ( isset( $extension['mainwp'] ) && $extension['mainwp'] ) {
								?>
								<td><?php echo isset( $extension['activated_key'] ) && 'Activated' === $extension['activated_key'] ? __( 'API License Active', 'mainwp' ) : __( 'API License Inactive', 'mainwp' ); ?></td>
								<td><?php echo isset( $extension['activated_key'] ) && 'Activated' === $extension['activated_key'] ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::get_warning_html( self::WARNING ); ?></td>
								<?php
							} else {
								?>
								<td></td>
								<td></td>
								<?php
							}
							?>
						</tr>
						<?php
				}
				?>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php esc_html_e( 'WordPress', 'mainwp' ); ?></div></td></tr>
					<?php
					self::render_row( 'WordPress Version', '>=', '3.6', 'get_wordpress_version', '', '', null, null, self::ERROR );
					self::render_row( 'WordPress Memory Limit', '>=', '64M', 'get_wordpress_memory_limit', '', '', null );
					self::render_row( 'MultiSite Disabled', '=', true, 'check_if_multisite', '', '', null );
					?>
					<tr>
						<td><?php esc_html_e( 'FileSystem Method', 'mainwp' ); ?></td>
						<td><?php echo esc_html( '= direct' ); ?></td>
						<td><?php echo MainWP_Server_Information_Handler::get_file_system_method(); ?></td>
						<td><?php echo self::get_file_system_method_check(); ?></td>
					</tr>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php esc_html_e( 'PHP', 'mainwp' ); ?></div></td></tr>
					<?php
					self::render_row( 'PHP Version', '>=', '5.6', 'get_php_version', '', '', null, null, self::ERROR );
					self::render_row( 'PHP Safe Mode Disabled', '=', true, 'get_php_safe_mode', '', '', null );
					self::render_row( 'PHP Max Execution Time', '>=', '30', 'get_max_execution_time', 'seconds', '=', '0' );
					self::render_row( 'PHP Max Input Time', '>=', '30', 'get_max_input_time', 'seconds', '=', '0' );
					self::render_row( 'PHP Memory Limit', '>=', '128M', 'get_php_memory_limit', '', '', null, 'filesize' );
					self::render_row( 'PCRE Backtracking Limit', '>=', '10000', 'get_output_buffer_size', '', '', null );
					self::render_row( 'PHP Upload Max Filesize', '>=', '2M', 'get_upload_max_filesize', '', '', null, 'filesize' );
					self::render_row( 'PHP Post Max Size', '>=', '2M', 'get_post_max_size', '', '', null, 'filesize' );
					self::render_row( 'SSL Extension Enabled', '=', true, 'get_ssl_support', '', '', null );
					self::render_row( 'SSL Warnings', '=', '', 'get_ssl_warning', 'empty', '', null );
					self::render_row( 'cURL Extension Enabled', '=', true, 'get_curl_support', '', '', null, null, self::ERROR );
					self::render_row( 'cURL Timeout', '>=', '300', 'get_curl_timeout', 'seconds', '=', '0' );
					if ( function_exists( 'curl_version' ) ) {
						self::render_row( 'cURL Version', '>=', '7.18.1', 'get_curl_version', '', '', null );
						self::render_row(
							'cURL SSL Version',
							'>=',
							array(
								'version_number' => 0x009080cf,
								'version'        => 'OpenSSL/0.9.8l',
							),
							'get_curl_ssl_version',
							'',
							'',
							null,
							'curlssl'
						);
					}
					?>
					<tr>
						<td colspan="2"><?php esc_html_e( 'PHP Allow URL fopen', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_php_allow_url_fopen(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'PHP Exif Support', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_php_exif(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'PHP IPTC Support', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_php_iptc(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'PHP XML Support', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_php_xml(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'PHP Disabled Functions', 'mainwp' ); ?></td>
						<td colspan="2"><?php self::php_disabled_functions(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'PHP Loaded Extensions', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_loaded_php_extensions(); ?></td>
					</tr>
					<tr>
						<td colspan="4"><div class="ui ribbon inverted grey label"><?php esc_html_e( 'MySQL', 'mainwp' ); ?></div></td>
					</tr>
					<?php self::render_row( 'MySQL Version', '>=', '5.0', 'get_mysql_version', '', '', null, null, self::ERROR ); ?>
					<tr>
						<td colspan="2"><?php esc_html_e( 'MySQL Mode', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_sql_mode(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'MySQL Client Encoding', 'mainwp' ); ?></td>
						<td colspan="2"><?php echo defined( 'DB_CHARSET' ) ? DB_CHARSET : ''; ?></td>
					</tr>
					<tr>
						<td colspan="4"><div class="ui ribbon inverted grey label"><?php esc_html_e( 'Server Info', 'mainwp' ); ?></div></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'WordPress Root Directory', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_wp_root(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Server Name', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_server_name(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Server Software', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_server_software(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Operating System', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_os(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Architecture', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_architecture(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Server IP', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_server_ip(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Server Protocol', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_server_protocol(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'HTTP Host', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_http_host(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'HTTPS', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_https(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Server self connect', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::server_self_connect(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'User Agent', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_user_agent(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Server Port', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_server_port(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Gateway Interface', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_server_gateway_interface(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Memory Usage', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::memory_usage(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Complete URL', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_complete_url(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Request Time', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_server_request_time(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Accept Content', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_server_http_accept(); ?></td>
					</tr>
					<tr>
						<td colspan="2"><?php esc_html_e( 'Accept-Charset Content', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_server_accept_charset(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Currently Executing Script Pathname', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_script_file_name(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Current Page URI', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_current_page_uri(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Remote Address', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_remote_address(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Remote Host', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_remote_host(); ?></td>
					</tr>
					<tr class="mwp-not-generate-row">
						<td colspan="2"><?php esc_html_e( 'Remote Port', 'mainwp' ); ?></td>
						<td colspan="2"><?php MainWP_Server_Information_Handler::get_remote_port(); ?></td>
					</tr>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php esc_html_e( 'MainWP Settings', 'mainwp' ); ?></div></td></tr>
					<?php self::display_mainwp_options(); ?>
					<tr><td colspan="4"><div class="ui ribbon inverted grey label"><?php esc_html_e( 'Active Plugins', 'mainwp' ); ?></div></td></tr>
					<?php
					$all_extensions = MainWP_Extensions_View::get_available_extensions();
					$all_plugins    = get_plugins();
					foreach ( $all_plugins as $slug => $plugin ) {
						if ( isset( $all_extensions[ dirname( $slug ) ] ) ) {
							continue;
						}
						?>
						<tr>
							<td><?php echo esc_html( $plugin['Name'] ); ?></td>
							<td><?php echo esc_html( $plugin['Version'] ); ?></td>
							<td colspan="2"><?php echo is_plugin_active( $slug ) ? __( 'Active', 'mainwp' ) : __( 'Inactive', 'mainwp' ); ?></td>
						</tr>
						<?php
					}
					?>
				</tbody>
				<tfoot class="full-width">
					<tr>
						<th colspan="4">
							<a href="#" class="ui right floated small green button" id="mainwp-download-system-report"><?php esc_html_e( 'Download System Report', 'mainwp' ); ?></a>
							<div><?php esc_html_e( 'Please include this information when requesting support.', 'mainwp' ); ?></div>
						</th>
					</tr>
				</tfoot>
			</table>
			<div id="download-server-information" style="display: none">
				<textarea readonly="readonly" wrap="off"></textarea>
			</div>
		<?php

		/**
		 * Action: mainwp_after_server_info_table
		 *
		 * Fires on the bottom of the Status page, after the Server Info table.
		 *
		 * @since 4.0
		 */
		do_action( 'mainwp_after_server_info_table' );

		self::render_footer( '' );
	}

	/**
	 * Renders MainWP system requirements check.
	 *
	 * @return void
	 */
	public static function render_quick_setup_system_check() {
		/**
		 * Action: mainwp_before_system_requirements_check
		 *
		 * Fires on the bottom of the System Requirements page, in Quick Setup Wizard.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_system_requirements_check' );
		?>
		<table id="mainwp-quick-system-requirements-check" class="ui tablet stackable single line table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Check', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Required Value', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Detected Value', 'mainwp' ); ?></th>
					<th class="collapsing center aligned"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				self::render_row_with_description( __( 'PHP Version', 'mainwp' ), '>=', '5.6', 'get_php_version', '', '', null );
				self::render_row_with_description( __( 'SSL Extension Enabled', 'mainwp' ), '=', true, 'get_ssl_support', '', '', null );
				self::render_row_with_description( __( 'cURL Extension Enabled', 'mainwp' ), '=', true, 'get_curl_support', '', '', null );
				self::render_row_with_description( __( 'MySQL Version', 'mainwp' ), '>=', '5.0', 'get_mysql_version', '', '', null );
				?>
			</tbody>
		</table>
		<?php
		/**
		 * Action: mainwp_after_system_requirements_check
		 *
		 * Fires on the bottom of the System Requirements page, in Quick Setup Wizard.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_system_requirements_check' );
	}

	/**
	 * Compares the detected MainWP Dashboard version agains the verion in WP.org.
	 *
	 * @return mixed Pass|self::get_warning_html().
	 */
	public static function get_mainwp_version_check() {
		$current = get_option( 'mainwp_plugin_version' );
		$latest  = MainWP_Server_Information_Handler::get_mainwp_version();
		if ( $current == $latest ) {
			return '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>';
		} else {
			return self::get_warning_html();
		}
	}

	/**
	 * Renders the Cron Schedule page.
	 *
	 * @return void
	 */
	public static function render_cron() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'see_server_information' ) ) {
			mainwp_do_not_have_permissions( 'cron schedules', 'mainwp' );
			return;
		}

		self::render_header( 'ServerInformationCron' );

		$cron_jobs = array(
			'Check for available updates' => array( 'mainwp_updatescheck_last_timestamp', 'mainwp_cronupdatescheck_action', __( 'Once every minute', 'mainwp' ) ),
			'Check for new statistics'    => array( 'mainwp_cron_last_stats', 'mainwp_cronstats_action', __( 'Once hourly', 'mainwp' ) ),
			'Ping childs sites'           => array( 'mainwp_cron_last_ping', 'mainwp_cronpingchilds_action', __( 'Once daily', 'mainwp' ) ),
		);

		$disableSitesMonitoring = get_option( 'mainwp_disableSitesChecking' );
		if ( ! $disableSitesMonitoring ) {
			$cron_jobs['Child site uptime monitoring'] = array( 'mainwp_cron_checksites_last_timestamp', 'mainwp_croncheckstatus_action', __( 'Once every minute', 'mainwp' ) );
		}

		$disableHealthChecking = get_option( 'mainwp_disableSitesHealthMonitoring' );
		if ( ! $disableHealthChecking ) {
			$cron_jobs['Site Health monitoring'] = array( 'mainwp_cron_checksiteshealth_last_timestamp', 'mainwp_cronsitehealthcheck_action', __( 'Once hourly', 'mainwp' ) );
		}

		if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			$cron_jobs['Start backups (Legacy)']    = array( 'mainwp_cron_last_backups', 'mainwp_cronbackups_action', __( 'Once hourly', 'mainwp' ) );
			$cron_jobs['Continue backups (Legacy)'] = array( 'mainwp_cron_last_backups_continue', 'mainwp_cronbackups_continue_action', __( 'Once every five minutes', 'mainwp' ) );
		}

		/**
		 * Action: mainwp_before_cron_jobs_table
		 *
		 * Renders on the top of the Cron Jobs page, before the Schedules table.
		 *
		 * @since 4.0
		 */
		do_action( 'mainwp_before_cron_jobs_table' );
		?>

		<table class="ui stackable celled table fixed" id="mainwp-cron-jobs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Cron Job', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Hook', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Schedule', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Last Run', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Next Run', 'mainwp' ); ?></th>
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
						<td><?php echo ( false == get_option( $hook[0] ) ) ? esc_html__( 'Never', 'mainwp' ) : MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( get_option( $hook[0] ) ) ); ?></td>
						<td><?php echo $next_run ? MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $next_run ) ) : ''; ?></td>
					</tr>
					<?php
				}
				/**
				 * Action: mainwp_cron_jobs_list
				 *
				 * Renders as the last row of the Schedules table.
				 *
				 * @since 4.0
				 */
				do_action( 'mainwp_cron_jobs_list' );
				?>
			</tbody>
		</table>
		<?php
		$table_features = array(
			'searching' => 'true',
			'paging'    => 'false',
			'info'      => 'false',
		);
		/**
		 * Filter: mainwp_cron_jobs_table_features
		 *
		 * Filters the Cron Schedules table features.
		 *
		 * @since 4.1
		 */
		$table_features = apply_filters( 'mainwp_cron_jobs_table_features', $table_features );
		?>
		<script type="text/javascript">
		jQuery( '#mainwp-cron-jobs-table' ).DataTable( {
			"searching": <?php echo $table_features['searching']; ?>,
			"paging": <?php echo $table_features['paging']; ?>,
			"info": <?php echo $table_features['info']; ?>,
		} );
		</script>
		<?php

		/**
		 * Action: mainwp_after_cron_jobs_table
		 *
		 * Renders on the bottom of the Cron Jobs page, after the Schedules table.
		 *
		 * @since 4.0
		 */
		do_action( 'mainwp_after_cron_jobs_table' );

		self::render_footer( 'ServerInformationCron' );
	}

	/**
	 * Checks if the ../wp-content/uploads/mainwp/ directory is writable.
	 *
	 * @return bool True if writable, false if not.
	 */
	public static function check_directory_mainwp_directory() {
		$dirs = MainWP_System_Utility::get_mainwp_dir();
		$path = $dirs[0];

		$passed = true;
		$mess   = 'Writable';

		if ( ! is_dir( dirname( $path ) ) ) {
			$mess   = 'Not Found';
			$passed = false;
		}

		$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
		global $wp_filesystem;

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
			if ( ! $wp_filesystem->is_writable( $path ) ) {
				$mess   = 'Not Writable';
				$passed = false;
			}
		} else {
			if ( ! is_writable( $path ) ) {
				$mess   = 'Not Writable';
				$passed = false;
			}
		}
		return self::render_directory_row( 'MainWP Upload Directory', 'Writable', $mess, $passed );
	}

	/**
	 * Renders the directory check row.
	 *
	 * @param string $name check name.
	 * @param string $check desired result.
	 * @param string $result detected result.
	 * @param bool   $passed true|false check result.
	 *
	 * @return bool true.
	 */
	public static function render_directory_row( $name, $check, $result, $passed ) {
		?>
		<tr>
			<td><?php echo esc_html( $name ); ?></td>
			<td><?php echo esc_html( $check ); ?></td>
			<td><?php echo esc_html( $result ); ?></td>
			<td><?php echo ( $passed ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::get_warning_html( self::ERROR ) ); ?></td>			
		</tr>
		<?php
		return true;
	}

	/**
	 * Renders server information table row.
	 *
	 * @param string $config configuraion check.
	 * @param string $compare comparison operator.
	 * @param mixed  $version reqiored minium version number.
	 * @param mixed  $getter detected version number.
	 * @param string $extraText additionl text.
	 * @param null   $extraCompare extra compare.
	 * @param null   $extraVersion extra version.
	 * @param null   $whatType comparison type.
	 * @param int    $errorType global variable self::WARNING = 1.
	 */
	public static function render_row( $config, $compare, $version, $getter, $extraText = '', $extraCompare = null, $extraVersion = null, $whatType = null, $errorType = self::WARNING ) {
		$currentVersion = call_user_func( array( MainWP_Server_Information_Handler::get_class_name(), $getter ) );
		?>
		<tr>
			<td><?php echo esc_html( $config ); ?></td>
			<td><?php echo esc_html( $compare ); ?><?php echo ( true === $version ? 'true' : ( is_array( $version ) && isset( $version['version'] ) ? $version['version'] : $version ) ) . ' ' . $extraText; ?></td>
			<td><?php echo( true === $currentVersion ? 'true' : $currentVersion ); ?></td>
			<?php if ( 'filesize' === $whatType ) { ?>
				<td><?php echo ( MainWP_Server_Information_Handler::filesize_compare( $currentVersion, $version, $compare ) ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::get_warning_html( $errorType ) ); ?></td>
			<?php } elseif ( 'curlssl' === $whatType ) { ?>
				<td><?php echo ( MainWP_Server_Information_Handler::curlssl_compare( $version, $compare ) ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::get_warning_html( $errorType ) ); ?></td>
			<?php } elseif ( ( 'get_max_input_time' === $getter || 'get_max_execution_time' === $getter ) && -1 == $currentVersion ) { ?>
				<td><?php echo '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>'; ?></td>
			<?php } else { ?>
				<td><?php echo ( version_compare( $currentVersion, $version, $compare ) || ( ( null != $extraCompare ) && version_compare( $currentVersion, $extraVersion, $extraCompare ) ) ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::get_warning_html( $errorType ) ); ?></td>
		<?php } ?>
		</tr>
		<?php
	}

	/**
	 * Renders server information table row with description.
	 *
	 * @param string $config configuraion check.
	 * @param string $compare comparison operator.
	 * @param mixed  $version reqiored minium version number.
	 * @param mixed  $getter detected version number.
	 * @param string $extraText additionl text.
	 * @param null   $extraCompare extra compare.
	 * @param null   $extraVersion extra version.
	 * @param null   $whatType comparison type.
	 * @param int    $errorType global variable self::WARNING = 1.
	 */
	public static function render_row_with_description( $config, $compare, $version, $getter, $extraText = '', $extraCompare = null, $extraVersion = null, $whatType = null, $errorType = self::WARNING ) {
		$currentVersion = call_user_func( array( MainWP_Server_Information_Handler::get_class_name(), $getter ) );
		?>
		<tr>
			<td><?php echo esc_html( $config ); ?></td>
			<td><?php echo esc_html( $compare ); ?>  <?php echo ( true === $version ? 'true' : ( is_array( $version ) && isset( $version['version'] ) ? $version['version'] : $version ) ) . ' ' . $extraText; ?></td>
			<td><?php echo ( true === $currentVersion ? 'true' : $currentVersion ); ?></td>
			<?php if ( 'filesize' === $whatType ) { ?>
			<td><?php echo ( MainWP_Server_Information_Handler::filesize_compare( $currentVersion, $version, $compare ) ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::get_warning_html( $errorType ) ); ?></td>
			<?php } elseif ( 'curlssl' === $whatType ) { ?>
			<td><?php echo ( MainWP_Server_Information_Handler::curlssl_compare( $version, $compare ) ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::get_warning_html( $errorType ) ); ?></td>
			<?php } elseif ( 'get_max_input_time' === $getter && -1 == $currentVersion ) { ?>
			<td><?php echo '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>'; ?></td>
			<?php } else { ?>
			<td><?php echo( version_compare( $currentVersion, $version, $compare ) || ( ( null != $extraCompare ) && version_compare( $currentVersion, $extraVersion, $extraCompare ) ) ? '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>' : self::get_warning_html( $errorType ) ); ?></td>
			<?php } ?>
		</tr>
		<?php
	}

	/**
	 * Checks if file system method is direct.
	 *
	 * @return mixed html|self::get_warning_html().
	 */
	public static function get_file_system_method_check() {
		$fsmethod = MainWP_Server_Information_Handler::get_file_system_method();
		if ( 'direct' === $fsmethod ) {
			return '<div class="ui green basic label"><i class="check circle icon"></i> ' . __( 'Pass', 'mainwp' ) . '</div>';
		} else {
			return self::get_warning_html();
		}
	}

	/**
	 * Renders Error Log page.
	 *
	 * Plugin-Name: Error Log Dashboard Widget
	 * Plugin URI: http://wordpress.org/extend/plugins/error-log-dashboard-widget/
	 * Description: Robust zero-configuration and low-memory way to keep an eye on error log.
	 * Author: Andrey "Rarst" Savchenko
	 * Author URI: http://www.rarst.net/
	 * Version: 1.0.2
	 * License: GPLv2 or later

	 * Includes last_lines() function by phant0m, licensed under cc-wiki and GPLv2+
	 */
	public static function render_error_log_page() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'see_server_information' ) ) {
			mainwp_do_not_have_permissions( 'error log', 'mainwp' );
			return;
		}
		self::render_header( 'ErrorLog' );

		/**
		 * Action: mainwp_before_error_log_table
		 *
		 * Fires before the Error Log table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_error_log_table' );
		?>
		<table class="ui stackable celled table" id="mainwp-error-log-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Error', 'mainwp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php self::render_error_log(); ?>
			</tbody>
		</table>
		<?php
		/**
		 * Action: mainwp_after_error_log_table
		 *
		 * Fires after the Error Log table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_error_log_table' );

		self::render_footer( 'ErrorLog' );
	}

	/**
	 * Renders error log page.
	 *
	 * @return void
	 */
	public static function render_error_log() {
		$log_errors = ini_get( 'log_errors' );
		if ( ! $log_errors ) {
			echo '<tr><td colspan="2">' . esc_html__( 'Error logging disabled.', 'mainwp' );
			echo '<br/>' . sprintf( esc_html__( 'To enable error logging, please check this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://codex.wordpress.org/Debugging_in_WordPress" target="_blank">', '</a>' );
			echo '</td></tr>';
		}

		$error_log = ini_get( 'error_log' );
		/**
		 * Filter: error_log_mainwp_logs
		 *
		 * Filters the error log files to show.
		 *
		 * @since Unknown
		 */
		$logs = apply_filters( 'error_log_mainwp_logs', array( $error_log ) );

		/**
		 * Filter: error_log_mainwp_lines
		 *
		 * Limits the number of error log records to be displayed. Default value, 50.
		 *
		 * @since Unknown
		 */
		$count = apply_filters( 'error_log_mainwp_lines', 50 );
		$lines = array();

		foreach ( $logs as $log ) {
			if ( is_readable( $log ) ) {
				$lines = array_merge( $lines, MainWP_Server_Information_Handler::last_lines( $log, $count ) );
			}
		}

		$lines = array_map( 'trim', $lines );
		$lines = array_filter( $lines );

		if ( empty( $lines ) ) {

			echo '<tr><td colspan="2">' . esc_html__( 'MainWP is unable to find your error logs, please contact your host for server error logs.', 'mainwp' ) . '</td></tr>';

			return;
		}

		foreach ( $lines as $key => $line ) {
			if ( false !== strpos( $line, ']' ) ) {
				list( $time, $error ) = explode( ']', $line, 2 );
			} else {
				list( $time, $error ) = array( '', $line );
			}
			$time          = trim( $time, '[]' );
			$error         = trim( $error );
			$lines[ $key ] = compact( 'time', 'error' );
		}

		if ( 1 < count( $lines ) ) {
			uasort( $lines, array( MainWP_Server_Information_Handler::get_class_name(), 'time_compare' ) );
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

	/**
	 * Renders the wp comfig page.
	 *
	 * @return void
	 */
	public static function render_wp_config() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'see_server_information' ) ) {
			mainwp_do_not_have_permissions( 'WP-Config.php', 'mainwp' );
			return;
		}

		self::render_header( 'WPConfig' );
		/**
		 * Action: mainwp_before_wp_config_section
		 *
		 * Fires before the WP Config section.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_wp_config_section' );
		?>
		<div id="mainwp-show-wp-config">
			<?php
			if ( false !== strpos( ini_get( 'disable_functions' ), 'show_source' ) ) {
				esc_html_e( 'File content could not be displayed.', 'mainwp' );
				echo '<br />';
				esc_html_e( 'It appears that the show_source() PHP function has been disabled on the servre.', 'mainwp' );
				echo '<br />';
				esc_html_e( 'Please, contact your host support and have them enable the show_source() function for the proper functioning of this feature.', 'mainwp' );
			} else {
				if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
					show_source( ABSPATH . 'wp-config.php' );
				} else {
					$files       = get_included_files();
					$configFound = false;
					if ( is_array( $files ) ) {
						foreach ( $files as $file ) {
							if ( stristr( $file, 'wp-config.php' ) ) {
								$configFound = true;
								show_source( $file );
								break;
							}
						}
					}
					if ( ! $configFound ) {
						esc_html_e( 'wp-config.php not found', 'mainwp' );
					}
				}
			}
			?>
		</div>
		<?php
		/**
		 * Action: mainwp_after_wp_config_section
		 *
		 * Fires after the WP Config section.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_wp_config_section' );

		self::render_footer( 'WPConfig' );
	}

	/**
	 * Renders action logs page.
	 */
	public static function render_action_logs() {
		self::render_header( 'Action logs' );

		if ( isset( $_REQUEST['actionlogs_status'] ) ) {
			$act_log = isset( $_REQUEST['actionlogs_status'] ) ? intval( $_REQUEST['actionlogs_status'] ) : MainWP_Logger::DISABLED;
			if ( MainWP_Logger::DISABLED != $act_log ) {
				MainWP_Logger::instance()->set_log_priority( $act_log );
			}

			MainWP_Logger::instance()->log( 'Action logs set to: ' . MainWP_Logger::instance()->get_log_text( $act_log ), MainWP_Logger::LOG );

			if ( MainWP_Logger::DISABLED == $act_log ) {
				MainWP_Logger::instance()->set_log_priority( $act_log );
			}

			MainWP_Utility::update_option( 'mainwp_actionlogs', $act_log );
		}

		if ( isset( $_REQUEST['actionlogs_clear'] ) ) {
			MainWP_Logger::clear_log();
		}

		$enabled = get_option( 'mainwp_actionlogs' );
		if ( false === $enabled ) {
			$enabled = MainWP_Logger::DISABLED;
		}
		?>
		<div class="postbox">
			<h3 class="hndle" style="padding: 8px 12px; font-size: 14px;"><span>Action logs</span></h3>

			<div style="padding: 1em;">
				<form method="POST" action="">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					Status:
					<select name="actionlogs_status">
						<option value="<?php echo MainWP_Logger::DISABLED; ?>" <?php echo ( MainWP_Logger::DISABLED == $enabled ? 'selected' : '' ); ?>>
							<?php esc_html_e( 'Disabled', 'mainwp' ); ?>
						</option>
						<option value="<?php echo MainWP_Logger::WARNING; ?>" <?php echo ( MainWP_Logger::WARNING == $enabled ? 'selected' : '' ); ?>>
							<?php esc_html_e( 'Warning', 'mainwp' ); ?>
						</option>
						<option value="<?php echo MainWP_Logger::INFO; ?>" <?php echo ( MainWP_Logger::INFO == $enabled ? 'selected' : '' ); ?>>
							<?php esc_html_e( 'Info', 'mainwp' ); ?>
						</option>
						<option value="<?php echo MainWP_Logger::DEBUG; ?>" <?php echo ( MainWP_Logger::DEBUG == $enabled ? 'selected' : '' ); ?>>
							<?php esc_html_e( 'Debug', 'mainwp' ); ?>
						</option>
						<option value="<?php echo MainWP_Logger::INFO_UPDATE; ?>" <?php echo ( MainWP_Logger::INFO_UPDATE == $enabled ? 'selected' : '' ); ?>>
							<?php esc_html_e( 'Info Update', 'mainwp' ); ?>
						</option>
					</select>
					<input type="submit" class="button button-primary" value="Save"/> <input type="submit" class="button button-primary" name="actionlogs_clear" value="Clear"/>
				</form>
			</div>
			<div style="padding: 1em;"><?php MainWP_Logger::show_log(); ?></div>
		</div>
		<?php
		self::render_footer( 'Action logs' );
	}

	/**
	 * Renders .htaccess File page.
	 *
	 * @return void
	 */
	public static function render_htaccess() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'see_server_information' ) ) {
			mainwp_do_not_have_permissions( '.htaccess', 'mainwp' );
			return;
		}
		self::render_header( '.htaccess' );
		/**
		 * Action: mainwp_before_htaccess_section
		 *
		 * Fires before the .htaccess file section.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_htaccess_section' );
		?>
		<div id="mainwp-show-htaccess">
			<?php
			if ( false !== strpos( ini_get( 'disable_functions' ), 'show_source' ) ) {
				esc_html_e( 'File content could not be displayed.', 'mainwp' );
				echo '<br />';
				esc_html_e( 'It appears that the show_source() PHP function has been disabled on the servre.', 'mainwp' );
				echo '<br />';
				esc_html_e( 'Please, contact your host support and have them enable the show_source() function for the proper functioning of this feature.', 'mainwp' );
			} else {
				show_source( ABSPATH . '.htaccess' );
			}
			?>
		</div>
		<?php
		/**
		 * Action: mainwp_after_htaccess_section
		 *
		 * Fires after the .htaccess file section.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_htaccess_section' );
		self::render_footer( '.htaccess' );
	}

	/**
	 * Checks for disable PHP Functions.
	 *
	 * @return void
	 */
	public static function php_disabled_functions() {
		$disabled_functions = ini_get( 'disable_functions' );
		if ( '' !== $disabled_functions ) {
			$arr = explode( ',', $disabled_functions );
			sort( $arr );
			$_count = count( $arr );
			for ( $i = 0; $i < $_count; $i ++ ) {
				echo $arr[ $i ] . ', ';
			}
		} else {
			esc_html_e( 'No functions disabled.', 'mainwp' );
		}
	}

	/**
	 * Renders MainWP Settings 'Options'.
	 *
	 * @return void
	 */
	public static function display_mainwp_options() {
		$options = MainWP_Server_Information_Handler::mainwp_options();
		foreach ( $options as $option ) {
			echo '<tr><td colspan="2">' . $option['label'] . '</td><td colspan="2">' . $option['value'] . '</td></tr>';
		}
	}

	/**
	 * Renders PHP Warning HTML.
	 *
	 * @param int $errorType Global variable self::WARNING = 1.
	 *
	 * @return string PHP Warning html.
	 */
	private static function get_warning_html( $errorType = self::WARNING ) {
		if ( self::WARNING == $errorType ) {
			return '<div class="ui yellow basic label"><i class="exclamation circle icon"></i> ' . __( 'Warning', 'mainwp' ) . '</div>';
		}
		return '<span class="ui red basic label"><i class="times circle icon"></i> ' . __( 'Fail', 'mainwp' ) . '</div>';
	}

}
