<?php
/**
 * MainWP Info Page.
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
	 * The Info page sub-pages.
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
	 * Initiate Info subPage menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::is_apache_server_software()
	 */
	public static function init_menu() {
		add_submenu_page(
			'mainwp_tab',
			__( 'Info', 'mainwp' ),
			' <span id="mainwp-ServerInformation">' . __( 'Info', 'mainwp' ) . '</span>',
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
		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginPrivacy' ) ) {
			add_submenu_page(
				'mainwp_tab',
				__( 'Plugin Privacy', 'mainwp' ),
				'<div class="mainwp-hidden">' . __( 'Plugin Privacy', 'mainwp' ) . '</div>',
				'read',
				'PluginPrivacy',
				array(
					self::get_class_name(),
					'render_plugin_privacy_page',
				)
			);
		}

		/**
		 * Filter mainwp_getsubpages_server
		 *
		 * Filters subpages for the Info page.
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::is_apache_server_software()
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::is_apache_server_software()
	 */
	public static function init_left_menu( $subPages = array() ) {
		MainWP_Menu::add_left_menu(
			array(
				'title'      => __( 'Info', 'mainwp' ),
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
				'title'      => __( 'Action Logs', 'mainwp' ),
				'parent_key' => 'ServerInformation',
				'href'       => 'admin.php?page=ActionLogs',
				'slug'       => 'ActionLogs',
				'right'      => '',
			),
			array(
				'title'      => __( 'Plugin Privacy', 'mainwp' ),
				'parent_key' => 'ServerInformation',
				'href'       => 'admin.php?page=PluginPrivacy',
				'slug'       => 'PluginPrivacy',
				'right'      => '',
			),
		);

		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ServerInformation', 'Server' );
		foreach ( $init_sub_subleftmenu as $item ) {
			if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
				continue;
			}
			MainWP_Menu::add_left_menu( $item, 2 );
		}
	}

	/**
	 * Renders Info header.
	 *
	 * @param string $shownPage Current page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::is_apache_server_software()
	 */
	public static function render_header( $shownPage = '' ) {
			$params = array(
				'title' => __( 'Info', 'mainwp' ),
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

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ActionLogs' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Action Logs', 'mainwp' ),
					'href'   => 'admin.php?page=ActionLogs',
					'active' => ( 'ActionLogs' === $shownPage ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginPrivacy' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Plugin Privacy', 'mainwp' ),
					'href'   => 'admin.php?page=PluginPrivacy',
					'active' => ( 'PluginPrivacy' === $shownPage ) ? true : false,
				);
			}

			MainWP_UI::render_page_navigation( $renderItems );

			self::render_actions_bar();

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
	 * Renders Server Information action bar element.
	 */
	public static function render_actions_bar() {
		if ( isset( $_GET['page'] ) && 'ServerInformation' === $_GET['page'] ) :
			?>
		<div class="mainwp-actions-bar">
			<div class="ui two column grid">
				<div class="column"></div>
				<div class="right aligned column">
					<a href="#" style="margin-left:5px" class="ui mini basic green button" id="mainwp-copy-meta-system-report" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Copy the system report to paste it to the MainWP Community.', 'mainwp' ); ?>"><?php esc_html_e( 'Copy System Report for the MainWP Community', 'mainwp' ); ?></a>
					<a href="#" class="ui mini green button" id="mainwp-download-system-report"><?php esc_html_e( 'Download System Report', 'mainwp' ); ?></a>
				</div>
			</div>
		</div>
			<?php
		endif;
	}

	/**
	 * Renders Server Information page.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_View::get_available_extensions()
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_extensions()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_mainwp_version()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_current_version()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_file_system_method()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_php_allow_url_fopen()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_php_exif()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_php_iptc()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_php_xml()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_loaded_php_extensions()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_sql_mode()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_wp_root()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_name()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_software()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_os()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_architecture()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_ip()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_protocol()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_http_host()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_https()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::server_self_connect()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_user_agent()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_port()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_gateway_interface()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::memory_usage()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_complete_url()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_request_time()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_http_accept()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_server_accept_charset()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_script_file_name()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_current_page_uri()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_remote_address()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_remote_host()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_remote_port()
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
		 * Fires on the top of the Info page, before the Server Info table.
		 *
		 * @since 4.0
		 */
		do_action( 'mainwp_before_server_info_table' );
		?>
		<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-server-info-info-message' ) ) : ?>
			<div class="ui info message">
				<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-server-info-info-message"></i>
				<?php echo sprintf( __( 'Check your system configuration and make sure your MainWP Dashboard passes all system requirements.  If you need help with resolving specific errors, please review this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/resolving-system-requirement-issues/" target="_blank">', '</a>' ); ?>
			</div>
		<?php endif; ?>
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
					self::render_row( 'PHP Version', '>=', '7.0', 'get_php_version', '', '', null, null, self::ERROR );
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
						$reuire_curl = '7.18.1';
						if ( version_compare( MainWP_Server_Information_Handler::get_php_version(), '8.0.0' ) >= 0 ) {
							$reuire_curl = '7.29.0';
						}
						self::render_row( 'cURL Version', '>=', $reuire_curl, 'get_curl_version', '', '', null );
						$openssl_version = 'OpenSSL/1.1.0';
						self::render_row(
							'cURL SSL Version',
							'>=',
							$openssl_version,
							'get_curl_ssl_version',
							'',
							'',
							null,
							'curlssl'
						);
						if ( ! MainWP_Server_Information_Handler::curlssl_compare( $openssl_version, '>=' ) ) {
							echo "<tr class='warning'><td colspan='4'><i class='attention icon'></i>" . sprintf( __( 'Your host needs to update OpenSSL to at least version 1.1.0 which is already over 4 years old and contains patches for over 60 vulnerabilities.%1$sThese range from Denial of Service to Remote Code Execution. %2$sClick here for more information.%3$s', 'mainwp' ), '<br/>', '<a href="https://community.letsencrypt.org/t/openssl-client-compatibility-changes-for-let-s-encrypt-certificates/143816" target="_blank">', '</a>' ) . '</td></tr>';
						}
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
			<div id="download-server-information" style="opacity:0">
				<textarea readonly="readonly" wrap="off"></textarea>
			</div>
		<?php

		/**
		 * Action: mainwp_after_server_info_table
		 *
		 * Fires on the bottom of the Info page, after the Server Info table.
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
		<table id="mainwp-quick-system-requirements-check" class="ui single line table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Check', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Required Value', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Detected Value', 'mainwp' ); ?></th>
					<th class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				self::render_row_with_description( __( 'PHP Version', 'mainwp' ), '>=', '7.0', 'get_php_version', '', '', null );
				self::render_row_with_description( __( 'SSL Extension Enabled', 'mainwp' ), '=', true, 'get_ssl_support', '', '', null );
				self::render_row_with_description( __( 'cURL Extension Enabled', 'mainwp' ), '=', true, 'get_curl_support', '', '', null );
				$openssl_version = 'OpenSSL/1.1.0';
				self::render_row_with_description(
					'cURL SSL Version',
					'>=',
					$openssl_version,
					'get_curl_ssl_version',
					'',
					'',
					null,
					'curlssl'
				);

				if ( ! MainWP_Server_Information_Handler::curlssl_compare( $openssl_version, '>=' ) ) {
					echo "<tr class='warning'><td colspan='4'><i class='attention icon'></i>" . sprintf( __( 'Your host needs to update OpenSSL to at least version 1.1.0 which is already over 4 years old and contains patches for over 60 vulnerabilities.%1$sThese range from Denial of Service to Remote Code Execution. %2$sClick here for more information.%3$s', 'mainwp' ), '<br/>', '<a href="https://community.letsencrypt.org/t/openssl-client-compatibility-changes-for-let-s-encrypt-certificates/143816" target="_blank">', '</a>' ) . '</td></tr>';
				}
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_mainwp_version()
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::format_timestamp()
	 * @uses \MainWP\Dashboard\MainWP_Utility::get_timestamp()
	 */
	public static function render_cron() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'see_server_information' ) ) {
			mainwp_do_not_have_permissions( 'cron schedules', 'mainwp' );
			return;
		}

		self::render_header( 'ServerInformationCron' );

		$cron_jobs = array(
			'Check for available updates' => array( 'mainwp_updatescheck_start_last_timestamp', 'mainwp_cronupdatescheck_action', __( 'Once every minute', 'mainwp' ) ),
			'Check for new statistics'    => array( 'mainwp_cron_last_stats', 'mainwp_cronstats_action', __( 'Once hourly', 'mainwp' ) ),
			'Ping childs sites'           => array( 'mainwp_cron_last_ping', 'mainwp_cronpingchilds_action', __( 'Once daily', 'mainwp' ) ),
		);

		$disableSitesMonitoring = get_option( 'mainwp_disableSitesChecking', 1 );
		if ( ! $disableSitesMonitoring ) {
			$cron_jobs['Child site uptime monitoring'] = array( 'mainwp_cron_checksites_last_timestamp', 'mainwp_croncheckstatus_action', __( 'Once every minute', 'mainwp' ) );
		}

		$disableHealthChecking = get_option( 'mainwp_disableSitesHealthMonitoring', 1 );  // disabled by default.
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
		<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-cron-info-message' ) ) : ?>
			<div class="ui info message">
				<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-cron-info-message"></i>
				<?php echo sprintf( __( 'Make sure scheduled actions are working correctly.  If scheduled actions do not run normally, please review this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/scheduled-events-not-occurring/" target="_blank">', '</a>' ); ?>
			</div>
		<?php endif; ?>
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
					if ( ! empty( $next_run ) ) {
						$next_run = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $next_run ) );
					}

					if ( 'mainwp_updatescheck_start_last_timestamp' == $hook[0] ) {
						$update_time = MainWP_Settings::get_websites_automatic_update_time();
						$last_run    = $update_time['last'];
						$next_run    = $update_time['next'];
					} elseif ( false == get_option( $hook[0] ) ) {
						$last_run = esc_html__( 'Never', 'mainwp' );
					} else {
						$last_run = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( get_option( $hook[0] ) ) );
					}

					?>
					<tr>
						<td><?php echo $cron_job; ?></td>
						<td><?php echo $hook[1]; ?></td>
						<td><?php echo $hook[2]; ?></td>
						<td><?php echo esc_html( $last_run ); ?></td>
						<td><?php echo ! empty( $next_run ) ? esc_html( $next_run ) : ''; ?></td>
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::filesize_compare()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::curlssl_compare()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_class_name()
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
			<?php } elseif ( 'get_curl_ssl_version' === $getter ) { ?>
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::filesize_compare()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::curlssl_compare()
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
			<?php } elseif ( 'get_curl_ssl_version' === $getter ) { ?>
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_file_system_method()
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
		<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-error-log-info-message' ) ) : ?>
			<div class="ui info message">
				<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-error-log-info-message"></i>
				<?php echo __( 'See the WordPress error log to fix problems that arise on your MainWP Dashboard site.', 'mainwp' ); ?>
			</div>
		<?php endif; ?>
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::last_lines()
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::get_class_name()
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
	 * Renders the WP Config page.
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger
	 * @uses \MainWP\Dashboard\MainWP_Logger::clear_log()
	 * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public static function render_action_logs() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		self::render_header( 'ActionLogs' );

		if ( isset( $_REQUEST['actionlogs_status'] ) ) {
			$act_log  = isset( $_REQUEST['actionlogs_status'] ) ? $_REQUEST['actionlogs_status'] : MainWP_Logger::DISABLED;
			$spec_log = 0;
			if ( is_string( $act_log ) && false !== strpos( $act_log, 'specific_' ) ) {
				$act_log  = str_replace( 'specific_', '', $act_log );
				$spec_log = 1;
			}

			$act_log = intval( $act_log );

			MainWP_Utility::update_option( 'mainwp_specific_logs', $spec_log );

			if ( MainWP_Logger::DISABLED != $act_log ) {
				MainWP_Logger::instance()->set_log_priority( $act_log, $spec_log );
			}

			MainWP_Logger::instance()->log( 'Action logs set to: ' . MainWP_Logger::instance()->get_log_text( $act_log ), MainWP_Logger::LOG );

			if ( MainWP_Logger::DISABLED == $act_log ) {
				MainWP_Logger::instance()->set_log_priority( $act_log, $spec_log );
			}

			MainWP_Utility::update_option( 'mainwp_actionlogs', $act_log );
		}

		if ( isset( $_REQUEST['actionlogs_clear'] ) ) {
			$log_to_db = apply_filters( 'mainwp_logger_to_db', true );
			if ( $log_to_db ) {
				MainWP_Logger::instance()->clear_log_db();
			} else {
				MainWP_Logger::instance()->clear_log();
			}
		}

		$enabled          = MainWP_Logger::instance()->get_log_status();
		$specific_default = array(
			MAINWP_UPDATE_CHECK_LOG_PRIORITY_NUMBER => __( 'Update Checking', 'mainwp' ),
		);
		$specific_logs    = apply_filters( 'mainwp_specific_action_logs', $specific_default );

		?>
		<div class="mainwp-sub-header" style="margin: -14px -14px 0 -14px;">
			<div class="ui mini form two column grid">
				<div class="column">
				<form method="POST" action="">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<select name="actionlogs_status" class="ui dropdown">
						<option value="<?php echo MainWP_Logger::DISABLED; ?>" <?php echo ( MainWP_Logger::DISABLED == $enabled ? 'selected' : '' ); ?>>
							<?php esc_html_e( 'Disabled', 'mainwp' ); ?>
						</option>
							<option value="<?php echo MainWP_Logger::INFO; ?>" <?php echo ( MainWP_Logger::INFO == $enabled ? 'selected' : '' ); ?>>
								<?php esc_html_e( 'Info', 'mainwp' ); ?>
							</option>
						<option value="<?php echo MainWP_Logger::WARNING; ?>" <?php echo ( MainWP_Logger::WARNING == $enabled ? 'selected' : '' ); ?>>
							<?php esc_html_e( 'Warning', 'mainwp' ); ?>
						</option>
						<option value="<?php echo MainWP_Logger::DEBUG; ?>" <?php echo ( MainWP_Logger::DEBUG == $enabled ? 'selected' : '' ); ?>>
							<?php esc_html_e( 'Debug', 'mainwp' ); ?>
						</option>
						<?php
						if ( is_array( $specific_logs ) && ! empty( $specific_logs ) ) {
							foreach ( $specific_logs as $spec_log => $spec_title ) {
								?>
							<option value="specific_<?php echo intval( $spec_log ); ?>" <?php echo ( $spec_log == $enabled ? 'selected' : '' ); ?>>
								<?php echo esc_html( $spec_title ); ?>
							</option>
								<?php
							}
						}
						?>
					</select>
						<input type="submit" class="ui green mini button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
						<input type="submit" class="ui mini button" name="actionlogs_clear" value="<?php esc_attr_e( 'Delete Log', 'mainwp' ); ?>" />
				</form>
			</div>
				<div class="column">

				</div>
			</div>
		</div>
		<div>
			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-action-logs-info-message' ) ) : ?>
				<div class="ui hidden divider"></div>
				<div class="ui info message">
					<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-action-logs-info-message"></i>
					<?php echo sprintf( __( 'Enable specific logging system.  For additional help, please review this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/action-logs/" target="_blank">', '</a>' ); ?>
				</div>
			<?php endif; ?>
		<?php
		$log_to_db = apply_filters( 'mainwp_logger_to_db', true );
		if ( $log_to_db ) {
			return MainWP_Logger::instance()->show_log_db();
		} else {
			MainWP_Logger::instance()->show_log();
		}
		?>
		</div>
		<?php
		self::render_footer( 'ActionLogs' );
	}

	/**
	 * Renders the Plugin Privacy page.
	 *
	 * @return void
	 */
	public static function render_plugin_privacy_page() {

		self::render_header( 'PluginPrivacy' );
		/**
		 * Action: mainwp_before_plugin_privacy_section
		 *
		 * Fires before the Plugin Privacy section.
		 *
		 * @since 4.2
		 */
		do_action( 'mainwp_before_plugin_privacy_section' );
		?>
		<div class="ui padded segment">
			<div class="ui two columns grid">
				<div class="column">
					<div id="mainwp-plugin-privacy" class="ui piled segment">
						<h2 class="ui header">
							<?php echo __( 'Plugin Dashboard Privacy Policy', 'mainwp' ); ?>
							<div class="sub header"><em><?php echo __( 'Last updated: March 21, 2022', 'mainwp' ); ?></em></div>
						</h2>
						<p><?php echo __( 'We value your privacy very highly. Please read this Privacy Policy carefully before using the MainWP Dashboard Plugin ("Plugin") operated by Sick Marketing, LLC d/b/a MainWP, a Limited Liability Company formed in Nevada, United States ("us","we","our") as this Privacy Policy contains important information regarding your privacy.', 'mainwp' ); ?></p>
						<p><?php echo __( 'Your access to and use of the Plugin is conditional upon your acceptance of and compliance with this Privacy Policy. This Privacy Policy applies to everyone accessing or using the Plugin.', 'mainwp' ); ?></p>
						<p><?php echo __( 'By accessing or using the Plugin, you agree to be bound by this Privacy Policy. If you disagree with any part of this Privacy Policy, then you do not have our permission to access or use the Plugin.', 'mainwp' ); ?></p>
						<h3 class="ui header"><?php echo __( 'What personal data we collect', 'mainwp' ); ?></h3>
						<p><?php echo __( 'We do not collect, store, nor process any personal data through this Plugin.', 'mainwp' ); ?></p>
						<h3 class="ui header"><?php echo __( 'Third-party extensions and integrations', 'mainwp' ); ?></h3>
						<p><?php echo __( 'This Plugin may be used with extensions that are operated by parties other than us. We may also provide extensions that have integrations with third party services. We do not control such extensions and integrations and are not responsible for their contents or the privacy or other practices of such extensions or integrations. Further, it is up to you to take precautions to ensure that whatever extensions or integrations you use adequately protect your privacy. Please review the Privacy Policies of such extensions or integrations before using them.', 'mainwp' ); ?></p>
						<div class="ui hidden divider"></div>
						<div class="ui two columns grid">
							<div class="column">
								<h3 class="ui header"><?php echo __( 'Our contact information', 'mainwp' ); ?></h3>
								<p><?php echo __( 'If you have any questions regarding our privacy practices, please do not hesitate to contact us at the following:', 'mainwp' ); ?></p>
								<div class="ui list">
									<div class="item"><?php echo __( 'Sick Marketing, LLC d/b/a MainWP', 'mainwp' ); ?></div>
									<div class="item"><?php echo __( 'support@mainwp.com', 'mainwp' ); ?></div>
									<div class="item"><?php echo __( '4730 S. Fort Apache Road', 'mainwp' ); ?></div>
									<div class="item"><?php echo __( 'Suite 300', 'mainwp' ); ?></div>
									<div class="item"><?php echo __( 'PO Box 27740', 'mainwp' ); ?></div>
									<div class="item"><?php echo __( 'Last Vegas, NV 89126', 'mainwp' ); ?></div>
									<div class="item"><?php echo __( 'United States', 'mainwp' ); ?></div>
								</div>
							</div>
							<div class="column">
								<h3 class="ui header"><?php echo __( 'Our representative�s contact information', 'mainwp' ); ?></h3>
								<p><?php echo __( 'If you are a resident of the European Union or the European Economic Area, you may also contact our representative at the following:', 'mainwp' ); ?></p>
								<div class="item"><?php echo __( 'Osano International Compliance Services Limited', 'mainwp' ); ?></div>
								<div class="item"><?php echo __( 'ATTN: NT2D', 'mainwp' ); ?></div>
								<div class="item"><?php echo __( '25/28 North Wall Quay', 'mainwp' ); ?></div>
								<div class="item"><?php echo __( 'Dublin 1, D01 H104', 'mainwp' ); ?></div>
								<div class="item"><?php echo __( 'Ireland', 'mainwp' ); ?></div>
							</div>
						</div>
					</div>
					<div class="ui divider"></div>
					<a href="<?php echo get_site_url() . '/wp-content/plugins/mainwp/privacy-policy.txt'; ?>" class="ui green basic button" target="_blank"><?php echo __( 'Download MainWP Dashboard Privacy Policy', 'mainwp' ); ?></a> <a href="<?php echo get_site_url() . '/wp-content/plugins/mainwp/mainwp-child-privacy-policy.txt'; ?>" class="ui green basic button" target="_blank"><?php echo __( 'Download MainWP Child Privacy Policy', 'mainwp' ); ?></a>
				</div>
				<div class="middle aligned center aligned column">
					<i class="massive icons">
					  <i style="font-size:350px;color:#eeeeee;" class="file outline icon"></i>
					  <i style="font-size:80px;color:#eeeeee;" class=" lock icon"></i>
					</i>
				</div>
			</div>
		</div>

		<?php
		/**
		 * Action: mainwp_after_plugin_privacy_section
		 *
		 * Fires after the Plugin Privacy section.
		 *
		 * @since 4.2
		 */
		do_action( 'mainwp_after_plugin_privacy_section' );

		self::render_footer( 'PluginPrivacy' );
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::mainwp_options()
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
