<?php
/**
 * MainWP Extension Page
 */
namespace MainWP\Dashboard;

/**
 * MainWP Extensions Page
 */
class MainWP_Extensions {
	
	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}


	/**
	 * @var boolean true|false Extension is loaded.
	 */
	public static $extensionsLoaded = false;

	/**
	 * @var undefined Loaded extensions list. 
	 */
	public static $extensions;

	/** Instantiate action hooks. */
	public static function init() {
		/**
		 * This hook allows you to render the Extensions page header via the 'mainwp-pageheader-extensions' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pageheader-extensions
		 *
		 * @see \MainWP_Extensions::render_header
		 */
		add_action( 'mainwp-pageheader-extensions', array( self::get_class_name(), 'render_header' ) );

		/**
		 * This hook allows you to render the Extensions page footer via the 'mainwp-pagefooter-extensions' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-extensions
		 *
		 * @see \MainWP_Extensions::render_footer
		 */
		add_action( 'mainwp-pagefooter-extensions', array( self::get_class_name(), 'render_footer' ) );

		add_action( 'mainwp_help_sidebar_content', array( self::get_class_name(), 'mainwp_help_content' ) );

		add_filter( 'mainwp-extensions-apigeneratepassword', array( MainWP_Extensions_Handler::get_class_name(), 'gen_api_password' ), 10, 3 );
		add_filter( 'mainwp_extensions_apigeneratepassword', array( MainWP_Extensions_Handler::get_class_name(), 'gen_api_password' ), 10, 3 );
	}

	/**
	 * Instantiate Extensions Menu
	 * 
	 * @return void
	 * 
	 * phpcs:ignore -- not quite complex function
	 */
	public static function init_menu() {
		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'Extensions' ) ) {
			MainWP_Extensions_View::init_menu();
		}

		self::$extensions = array();
		$all_extensions   = array();

		/*
		* @deprecated Use 'mainwp_getextensions' instead.
		*
		*/
		$newExtensions = array();
		$newExtensions = apply_filters_deprecated( 'mainwp-getextensions', array( $newExtensions ), '4.0.1', 'mainwp_getextensions' );
		$newExtensions = apply_filters( 'mainwp_getextensions', $newExtensions );

		$activations_cached = get_option( 'mainwp_extensions_all_activation_cached', array() );

		if ( ! is_array( $activations_cached ) ) {
			$activations_cached = array();
		}

		$is_cached = ! empty( $activations_cached ) ? true : false;

		$extraHeaders = array(
			'IconURI'          => 'Icon URI',
			'SupportForumURI'  => 'Support Forum URI',
			'DocumentationURI' => 'Documentation URI',
		);

		$extsPages = array();

		$compatible_v4_checks = array(
			'advanced-uptime-monitor-extension/advanced-uptime-monitor-extension.php',
			'mainwp-article-uploader-extension/mainwp-article-uploader-extension.php',
			'mainwp-backupwordpress-extension/mainwp-backupwordpress-extension.php',
			'mainwp-backwpup-extension/mainwp-backwpup-extension.php',
			'mainwp-blogvault-backup-extension/mainwp-blogvault-backup-extension.php',
			'boilerplate-extension/boilerplate-extension.php',
			'mainwp-branding-extension/mainwp-branding-extension.php',
			'mainwp-broken-links-checker-extension/mainwp-broken-links-checker-extension.php',
			'mainwp-bulk-settings-manager/mainwp-bulk-settings-manager.php',
			'mainwp-clean-and-lock-extension/mainwp-clean-and-lock-extension.php',
			'mainwp-client-reports-extension/mainwp-client-reports-extension.php',
			'mainwp-clone-extension/mainwp-clone-extension.php',
			'mainwp-code-snippets-extension/mainwp-code-snippets-extension.php',
			'mainwp-comments-extension/mainwp-comments-extension.php',
			'mainwp-favorites-extension/mainwp-favorites-extension.php',
			'mainwp-file-uploader-extension/mainwp-file-uploader-extension.php',
			'mainwp-google-analytics-extension/mainwp-google-analytics-extension.php',
			'mainwp-links-manager-extension/mainwp-links-manager-extension.php',
			'mainwp-maintenance-extension/mainwp-maintenance-extension.php',
			'mainwp-piwik-extension/mainwp-piwik-extension.php',
			'mainwp-post-dripper-extension/mainwp-post-dripper-extension.php',
			'mainwp-rocket-extension/mainwp-rocket-extension.php',
			'mainwp-spinner/mainwp-spinner.php',
			'mainwp-sucuri-extension/mainwp-sucuri-extension.php',
			'mainwp-team-control/mainwp-team-control.php',
			'mainwp-updraftplus-extension/mainwp-updraftplus-extension.php',
			'mainwp-url-extractor-extension/mainwp-url-extractor-extension.php',
			'mainwp-woocommerce-shortcuts-extension/mainwp-woocommerce-shortcuts-extension.php',
			'mainwp-woocommerce-status-extension/mainwp-woocommerce-status-extension.php',
			'mainwp-wordfence-extension/mainwp-wordfence-extension.php',
			'wordpress-seo-extension/wordpress-seo-extension.php',
			'mainwp-page-speed-extension/mainwp-page-speed-extension.php',
			'mainwp-ithemes-security-extension/mainwp-ithemes-security-extension.php',
			'mainwp-post-plus-extension/mainwp-post-plus-extension.php',
			'mainwp-staging-extension/mainwp-staging-extension.php',
			'mainwp-custom-post-types/mainwp-custom-post-types.php',
			'mainwp-buddy-extension/mainwp-buddy-extension.php',
			'mainwp-vulnerability-checker-extension/mainwp-vulnerability-checker-extension.php',
			'mainwp-timecapsule-extension/mainwp-timecapsule-extension.php',
			'activity-log-mainwp/activity-log-mainwp.php',
		);
		include_once ABSPATH . '/wp-admin/includes/plugin.php';

		$deactivated_imcompatible = array();
		foreach ( $newExtensions as $extension ) {
			$slug        = plugin_basename( $extension['plugin'] );
			$plugin_data = get_plugin_data( $extension['plugin'] );
			$file_data   = get_file_data( $extension['plugin'], $extraHeaders );

			if ( ! isset( $plugin_data['Name'] ) || ( '' === $plugin_data['Name'] ) ) {
				continue;
			}

			if ( in_array( $slug, $compatible_v4_checks ) ) {
				$check_minver = '3.99999';
				if ( 'advanced-uptime-monitor-extension/advanced-uptime-monitor-extension.php' === $slug ) {
					$check_minver = '4.6.2';
				} elseif ( 'activity-log-mainwp/activity-log-mainwp.php' === $slug ) {
					$check_minver = '1.0.5';
				}

				if ( isset( $plugin_data['Version'] ) && version_compare( $plugin_data['Version'], $check_minver, '<' ) ) {
					$deactivated_imcompatible[] = $plugin_data['Name'];
					deactivate_plugins( $slug, true );
					continue;
				}
			}

			$extension['slug'] = $slug;

			if ( ! isset( $extension['name'] ) ) {
				$extension['name'] = $plugin_data['Name'];
			}
			$extension['version']          = $plugin_data['Version'];
			$extension['description']      = $plugin_data['Description'];
			$extension['author']           = $plugin_data['Author'];
			$extension['iconURI']          = isset( $extension['icon'] ) ? $extension['icon'] : $file_data['IconURI'];
			$extension['SupportForumURI']  = $file_data['SupportForumURI'];
			$extension['DocumentationURI'] = $file_data['DocumentationURI'];
			$extension['page']             = 'Extensions-' . str_replace( ' ', '-', ucwords( str_replace( '-', ' ', dirname( $slug ) ) ) );

			if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) {

				$api = dirname( $slug );

				if ( $is_cached ) {
					$options = isset( $activations_cached[ $api ] ) ? $activations_cached[ $api ] : array();
				} else {
					$options                    = MainWP_Api_Manager::instance()->get_activation_info( $api );
					$activations_cached[ $api ] = $options;
				}

				if ( ! is_array( $options ) ) {
					$options = array();
				}

				$extension['api_key']             = isset( $options['api_key'] ) ? $options['api_key'] : '';
				$extension['activation_email']    = isset( $options['activation_email'] ) ? $options['activation_email'] : '';
				$extension['activated_key']       = isset( $options['activated_key'] ) ? $options['activated_key'] : 'Deactivated';
				$extension['deactivate_checkbox'] = isset( $options['deactivate_checkbox'] ) ? $options['deactivate_checkbox'] : 'off';
				$extension['product_id']          = isset( $options['product_id'] ) ? $options['product_id'] : '';
				$extension['instance_id']         = isset( $options['instance_id'] ) ? $options['instance_id'] : '';
				$extension['software_version']    = isset( $options['software_version'] ) ? $options['software_version'] : '';
			}

			$all_extensions[] = $extension;
			if ( ( defined( 'MWP_TEAMCONTROL_PLUGIN_SLUG' ) && MWP_TEAMCONTROL_PLUGIN_SLUG == $slug ) ||
				mainwp_current_user_can( 'extension', dirname( $slug ) )
			) {
				self::$extensions[] = $extension;
				if ( mainwp_current_user_can( 'extension', dirname( $slug ) ) ) {
					if ( isset( $extension['callback'] ) ) {

						$menu_name = MainWP_Extensions_Handler::polish_ext_name( $extension );

						if ( MainWP_Extensions_Handler::added_on_menu( $slug ) ) {
							$_page = add_submenu_page( 'mainwp_tab', $extension['name'], $menu_name, 'read', $extension['page'], $extension['callback'] );
						} else {
							$_page = add_submenu_page( 'mainwp_tab', $extension['name'], '<div class="mainwp-hidden">' . $extension['name'] . '</div>', 'read', $extension['page'], $extension['callback'] );
						}

						if ( isset( $extension['on_load_callback'] ) && ! empty( $extension['on_load_callback'] ) ) {
							add_action( 'load-' . $_page, $extension['on_load_callback'] );
						}

						$extsPages[] = array(
							'title' => $menu_name,
							'slug'  => $extension['page'],
						);
					}
				}
			}
		}

		if ( ! empty( $deactivated_imcompatible ) ) {
			set_transient( 'mainwp_transient_deactivated_incomtible_exts', $deactivated_imcompatible );
		}

		MainWP_Utility::update_option( 'mainwp_extensions', self::$extensions );
		MainWP_Utility::update_option( 'mainwp_manager_extensions', $all_extensions );

		if ( ! $is_cached ) {
			update_option( 'mainwp_extensions_all_activation_cached', $activations_cached );
		}

		self::$extensionsLoaded = true;
		self::init_left_menu( $extsPages );
	}

	/**
	 * Initiate top level Extensions Menues.
	 * 
	 * @param mixed $extPages List of extention pages.
	 */
	public static function init_left_menu( $extPages ) {
		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'Extensions' ) ) {
			MainWP_Menu::add_left_menu(
				array(
					'title'             => __( 'Extensions', 'mainwp' ),
					'parent_key'        => 'mainwp_tab',
					'slug'              => 'Extensions',
					'href'              => 'admin.php?page=Extensions',
					'icon'              => '<i class="plug icon"></i>',
					'id'                => 'menu-item-extensions',
				),
				1
			);

			if ( 0 < count( $extPages ) ) {

				$init_sub_subleftmenu = array();
				$slug                 = '';
				MainWP_Menu::init_subpages_left_menu( $extPages, $init_sub_subleftmenu, 'Extensions', $slug );

				foreach ( $init_sub_subleftmenu as $item ) {
					if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
							continue;
					}
					MainWP_Menu::add_left_menu( $item, 2 );
				}
			}
		}
	}

	/** Initiate Extensions Suppage Menu.  */
	public static function init_subpages_menu() {
		if ( empty( self::$extensions ) ) {
			return;
		}
		$html = '';
		if ( isset( self::$extensions ) && is_array( self::$extensions ) ) {
			foreach ( self::$extensions as $extension ) {
				if ( defined( 'MWP_TEAMCONTROL_PLUGIN_SLUG' ) && ( MWP_TEAMCONTROL_PLUGIN_SLUG == $extension['slug'] ) && ! mainwp_current_user_can( 'extension', dirname( MWP_TEAMCONTROL_PLUGIN_SLUG ) ) ) {
					continue;
				}
				if ( MainWP_Extensions_Handler::added_on_menu( $extension['slug'] ) ) {
					continue;
				}

				$menu_name = MainWP_Extensions_Handler::polish_ext_name( $extension );

				if ( isset( $extension['direct_page'] ) ) {
					$html .= '<a href="' . admin_url( 'admin.php?page=' . $extension['direct_page'] ) . '" class="mainwp-submenu">' . $menu_name . '</a>';
				} else {
					$html .= '<a href="' . admin_url( 'admin.php?page=' . $extension['page'] ) . '" class="mainwp-submenu">' . $menu_name . '</a>';
				}
			}
		}
		if ( empty( $html ) ) {
			return;
		}
		?>
		<div id="menu-mainwp-Extensions" class="mainwp-submenu-wrapper" xmlns="http://www.w3.org/1999/html">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout mainwp-submenu-wide">
					<div class="mainwp_boxoutin"></div>
					<?php echo $html; ?>
				</div>
			</div>
		</div>
		<?php
	}

	
	/**
	 * Get purchased MainWP Extensions. 
	 * 
	 * @return html Extensions list. 
	 * 
	 * phpcs:ignore -- complex method
	 */
	public static function get_purchased_exts() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_getpurchased' );
		$username = trim( $_POST['username'] );
		$password = trim( $_POST['password'] );

		if ( ( '' === $username ) || ( '' === $password ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid login.', 'mainwp' ) ) ) );
		}

		$data   = MainWP_Api_Manager::instance()->get_purchased_software( $username, $password );
		$result = json_decode( $data, true );
		$return = array();

		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) && $result['success'] ) {
				$all_available_exts   = array();
				$map_extensions_group = array();
				$free_group           = array();

				foreach ( MainWP_Extensions_View::get_available_extensions() as $ext ) {
					$all_available_exts[ $ext['product_id'] ]   = $ext;
					$map_extensions_group[ $ext['product_id'] ] = current( $ext['group'] );
					if ( isset( $ext['free'] ) && ! empty( $ext['free'] ) ) {
						$free_group[] = $ext['product_id'];
					}
				}

				MainWP_Extensions_Handler::load_extensions();

				$installed_softwares = array();
				if ( is_array( self::$extensions ) ) {
					foreach ( self::$extensions as $extension ) {
						if ( isset( $extension['product_id'] ) && ! empty( $extension['product_id'] ) ) {
							$installed_softwares[ $extension['product_id'] ] = $extension['product_id'];
						}
					}
				}

				$purchased_data     = ( isset( $result['purchased_data'] ) && is_array( $result['purchased_data'] ) ) ? $result['purchased_data'] : array();
				$not_purchased_exts = array_diff_key( $all_available_exts, $purchased_data );
				$installing_exts    = array_diff_key( $purchased_data, $installed_softwares );

				$all_groups = MainWP_Extensions_View::get_extension_groups();

				$grouped_exts = array( 'others' => '' );

				foreach ( $installing_exts as $product_id => $product_info ) {
					$item_html      = '';
					$error          = '';
					$software_title = isset( $all_available_exts[ $product_id ] ) ? $all_available_exts[ $product_id ]['title'] : $product_id;

					if ( isset( $product_info['package'] ) && ! empty( $product_info['package'] ) ) {

						$package_url = apply_filters( 'mainwp_api_manager_upgrade_url', $product_info['package'] );

						$item_html = '
						<div class="item extension-to-install" download-link="' . $package_url . '" product-id="' . $product_id . '">
							<div class="ui grid">
								<div class="two column row">
									<div class="column"><span class="ui checkbox"><input type="checkbox" status="queue"><label>' . $software_title . '</label></span></div>
									<div class="column"><span class="installing-extension" status="queue"></span></div>
								</div>
							</div>
						</div>';

					} elseif ( isset( $product_info['error'] ) && ! empty( $product_info['error'] ) ) {
						$error = MainWP_Api_Manager::instance()->check_response_for_intall_errors( $product_info, $software_title );
					} else {
						$error = __( 'Undefined error occurred. Please try again.', 'mainwp' );
					}

					if ( ! empty( $error ) ) {
						$item_html = '
						<div class="item">
							<div class="ui grid">
								<div class="two column row">
									<div class="column"><span class="ui checkbox"><input type="checkbox" disabled="disabled"><label>' . $software_title . '</label></span></div>
									<div class="column"><i class="times circle red icon"></i> ' . $error . '</div>
								</div>
							</div>
						</div>';
					}

					$group_id = isset( $map_extensions_group[ $product_id ] ) ? $map_extensions_group[ $product_id ] : false;
					if ( ! empty( $group_id ) && isset( $all_groups[ $group_id ] ) ) {
						if ( isset( $grouped_exts[ $group_id ] ) ) {
							$grouped_exts[ $group_id ] .= $item_html;
						} else {
							$grouped_exts[ $group_id ] = $item_html;
						}
					} else {
						$grouped_exts['others'] .= $item_html;
					}
				}

				foreach ( $not_purchased_exts as $product_id => $ext ) {

					$item_html = '
					<div class="item" product-id="' . $product_id . '">
						<div class="ui grid">
							<div class="two column row">
								<div class="column"><span class="ui checkbox"><input type="checkbox" disabled="disabled"><label>' . $ext['title'] . '</label></span></div>
								<div class="column">' . __( 'Extension not purchased. ', 'mainwp' ) . '<a class="right floated" href="' . $ext['link'] . '" target="_blank">' . __( 'Get it here.', 'mainwp' ) . '</a></div>
							</div>
						</div>
					</div>';

					$group_id = isset( $map_extensions_group[ $product_id ] ) ? $map_extensions_group[ $product_id ] : false;
					if ( ! empty( $group_id ) && isset( $all_groups[ $group_id ] ) ) {
						if ( isset( $grouped_exts[ $group_id ] ) ) {
							$grouped_exts[ $group_id ] .= $item_html;
						} else {
							$grouped_exts[ $group_id ] = $item_html;
						}
					} elseif ( ! empty( $ext['title'] ) ) {
						$grouped_exts['others'] .= $item_html;
					}
				}

				$html = '';

				$html .= '<div class="mainwp-installing-extensions">';

				if ( empty( $installing_exts ) ) {
					$html .= '<div class="ui message yellow">' . __( 'All purchased extensions already installed.', 'mainwp' ) . '</div>';
				} else {
					$html .= '<div class="ui message yellow">' . __( 'You have access to all your purchased Extensions but you DO NOT need to install all off them. In order to avoid information overload, we highly recommend adding Extensions one at a time and as you need them. Skip any Extension you do not want to install at this time.', 'mainwp' ) . '</div>';
					$html .= '<div id="mainwp-bulk-activating-extensions-status" class="ui message" style="display:none;"></div>';

					foreach ( $all_groups as $gr_id => $gr_name ) {
						if ( isset( $grouped_exts[ $gr_id ] ) ) {
							$html .= '<h3>' . $gr_name . '</h3>';
							$html .= '<div class="ui relaxed divided list">';
							$html .= $grouped_exts[ $gr_id ];
							$html .= '</div>';
						}
					}

					if ( isset( $grouped_exts['others'] ) && ! empty( $grouped_exts['others'] ) ) {
						$html .= '<h3>' . __( 'Other', 'mainwp' ) . '</h3>';
						$html .= '<div class="ui relaxed divided list">';
						$html .= $grouped_exts['others'];
						$html .= '</div>';
					}
				}

				if ( ! empty( $installing_exts ) ) {
					$html .= '<p>
                                <span class="extension_api_loading">

                                    <i class="ui active inline loader tiny" style="display: none;"></i><span class="status hidden"></span>
                                </span>
                            </p> ';
				}

				$html  .= '</div>';
				$return = array(
					'result' => 'SUCCESS',
					'data'   => $html,
				);

			} elseif ( isset( $result['error'] ) ) {
				$return = array( 'error' => $result['error'] );
			}
		} else {
			$apisslverify = get_option( 'mainwp_api_sslVerifyCertificate' );
			if ( 1 == $apisslverify ) {
				MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );
				$return['retry_action'] = 1;
			}
		}
		wp_send_json( $return );
	}


	/**
	 * Render page header.
	 * 
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function render_header( $shownPage = '' ) {
		MainWP_Extensions_View::render_header( $shownPage, self::$extensions );
	}

	/**
	 * Render page footer. 
	 * 
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function render_footer( $shownPage ) {
		MainWP_Extensions_View::render_footer( $shownPage );
	}

	/** Render Page */
	public static function render() {

		$params = array(
			'title' => __( 'Extensions', 'mainwp' ),
		);
		MainWP_UI::render_top_header( $params );

		MainWP_Extensions_View::render( self::$extensions );
		echo '</div>';
	}

	/**
	 * MainWP Help Content.
	 * 
	 * Create the MainWP Help Document List for the help component in the sidebar.
	 * 
	 * @return html MainWP Help Document List.
	 */
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && 'Extensions' === $_GET['page'] ) {
			?>
			<p><?php esc_html_e( 'If you need help with your MainWP Extensions, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://mainwp.com/help/docs/what-are-mainwp-extensions/" target="_blank"><i class="fa fa-book"></i> What are the MainWP Extensions</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/what-are-mainwp-extensions/order-extensions/" target="_blank"><i class="fa fa-book"></i> Order Extension(s)</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/what-are-mainwp-extensions/my-downloads-and-api-keys/" target="_blank"><i class="fa fa-book"></i> My Downloads and API Keys</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/what-are-mainwp-extensions/install-extensions/" target="_blank"><i class="fa fa-book"></i> Install Extension(s)</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/what-are-mainwp-extensions/activate-extensions-api/" target="_blank"><i class="fa fa-book"></i> Activate Extension(s) API</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/what-are-mainwp-extensions/updating-extensions/" target="_blank"><i class="fa fa-book"></i> Updating Extension(s)</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/what-are-mainwp-extensions/remove-extensions/" target="_blank"><i class="fa fa-book"></i> Remove Extension(s)</a></div>
			</div>
			<?php
		}
	}

}
