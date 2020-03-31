<?php
namespace MainWP\Dashboard;

/**
 * MainWP Extensions Page
 */
class MainWP_Extensions {

	public static function get_class_name() {
		return __CLASS__;
	}

	public static $extensionsLoaded = false;
	public static $extensions;

	public static $activation_info = null;

	public static function get_plugin_slug( $pSlug ) {
		$currentExtensions = ( self::$extensionsLoaded ? self::$extensions : get_option( 'mainwp_extensions' ) );

		if ( ! is_array( $currentExtensions ) || empty( $currentExtensions ) ) {
			return $pSlug;
		}

		foreach ( $currentExtensions as $extension ) {
			if ( isset( $extension['api'] ) && ( $extension['api'] == $pSlug ) ) {
				return $extension['slug'];
			}
		}

		return $pSlug;
	}

	public static function get_slugs() {
		$currentExtensions = ( self::$extensionsLoaded ? self::$extensions : get_option( 'mainwp_extensions' ) );

		if ( ! is_array( $currentExtensions ) || empty( $currentExtensions ) ) {
			return array(
				'slugs'    => '',
				'am_slugs' => '',
			);
		}

		$out    = '';
		$am_out = '';
		foreach ( $currentExtensions as $extension ) {
			if ( ! isset( $extension['api'] ) || '' === $extension['api'] ) {
				continue;
			}

			if ( isset( $extension['apiManager'] ) && ! empty( $extension['apiManager'] ) && 'Activated' === $extension['activated_key'] ) {
				if ( '' !== $am_out ) {
					$am_out .= ',';
				}
				$am_out .= $extension['api'];
			} else {
				if ( '' !== $out ) {
					$out .= ',';
				}
				$out .= $extension['api'];
			}
		}

		return array(
			'slugs'    => $out,
			'am_slugs' => $am_out,
		);
	}


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

		add_filter( 'mainwp-extensions-apigeneratepassword', array( self::get_class_name(), 'gen_api_password' ), 10, 3 );
	}

	public static function init_menu() {
		if ( ! MainWP_Menu::is_disable_menu_item( 2, 'Extensions' ) ) {
			MainWP_Extensions_View::init_menu();
		}

		self::$extensions = array();
		$all_extensions   = array();

		$newExtensions      = apply_filters( 'mainwp-getextensions', array() );
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

						$menu_name = self::polish_ext_name( $extension );

						if ( self::added_on_menu( $slug ) ) {
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

	public static function polish_ext_name( $extension ) {
		if ( isset( $extension['mainwp'] ) && $extension['mainwp'] ) {
			$menu_name = str_replace(
				array(
					'Extension',
					'MainWP',
				),
				'',
				$extension['name']
			);
			$menu_name = trim( $menu_name );
		} else {
			$menu_name = $extension['name'];
		}
		return $menu_name;
	}


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

	public static function load_extensions() {
		if ( ! isset( self::$extensions ) ) {
			self::$extensions = get_option( 'mainwp_extensions' );
			if ( ! is_array( self::$extensions ) ) {
				self::$extensions = array();
			}
			self::$extensionsLoaded = true;
		}

		return self::$extensions;
	}

	public static function get_extensions( $args = array() ) {
		if ( ! is_array( $args ) ) {
			$args = array();
		}

		$extensions = self::load_extensions();

		$return = array();
		foreach ( $extensions as $extension ) {
			if ( isset( $args['activated'] ) && ! empty( $args['activated'] ) ) {
				if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) {
					if ( ! isset( $extension['activated_key'] ) || 'Activated' !== $extension['activated_key'] ) {
						continue;
					}
				}
			}
			$ext            = array();
			$ext['version'] = $extension['version'];
			$ext['name']    = $extension['name'];
			$ext['page']    = $extension['page'];
			$ext['page']    = $extension['page'];
			if ( isset( $extension['activated_key'] ) && 'Activated' === $extension['activated_key'] ) {
				$ext['activated_key'] = 'Activated';
			}
			$return[ $extension['slug'] ] = $ext;
		}
		return $return;
	}

	public static function get_extensions_page_slug() {
		$currentExtensions = ( self::$extensionsLoaded ? self::$extensions : get_option( 'mainwp_extensions' ) );

		if ( ! is_array( $currentExtensions ) || empty( $currentExtensions ) ) {
			return array();
		}
		$pageSlugs = array();
		foreach ( $currentExtensions as $extension ) {
			$pageSlugs[] = $extension['page'];
		}

		return $pageSlugs;
	}

	public static function gen_api_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		return MainWP_Api_Manager_Password_Management::generate_password( $length, $special_chars, $extra_special_chars );
	}

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
				if ( self::added_on_menu( $extension['slug'] ) ) {
					continue;
				}

				$menu_name = self::polish_ext_name( $extension );

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

	public static function init_ajax_handlers() {
		MainWP_Post_Handler::instance()->add_action(
			'mainwp_extension_add_menu',
			array(
				self::get_class_name(),
				'ajax_add_extension_menu',
			)
		);
		MainWP_Post_Handler::instance()->add_action(
			'mainwp_extension_remove_menu',
			array(
				self::get_class_name(),
				'remove_extension_menu_from_mainwp_menu',
			)
		);
		MainWP_Post_Handler::instance()->add_action(
			'mainwp_extension_activate',
			array(
				self::get_class_name(),
				'activate_extension',
			)
		);
		MainWP_Post_Handler::instance()->add_action(
			'mainwp_extension_deactivate',
			array(
				self::get_class_name(),
				'deactivate_extension',
			)
		);
		MainWP_Post_Handler::instance()->add_action(
			'mainwp_extension_testextensionapilogin',
			array(
				self::get_class_name(),
				'test_extensions_api_login',
			)
		);

		if ( mainwp_current_user_can( 'dashboard', 'bulk_install_and_activate_extensions' ) ) {
			MainWP_Post_Handler::instance()->add_action(
				'mainwp_extension_grabapikey',
				array(
					self::get_class_name(),
					'grab_extension_api_key',
				)
			);
			MainWP_Post_Handler::instance()->add_action(
				'mainwp_extension_saveextensionapilogin',
				array(
					self::get_class_name(),
					'save_extensions_api_login',
				)
			);
			MainWP_Post_Handler::instance()->add_action(
				'mainwp_extension_getpurchased',
				array(
					self::get_class_name(),
					'get_purchased_exts',
				)
			);
			MainWP_Post_Handler::instance()->add_action(
				'mainwp_extension_downloadandinstall',
				array(
					self::get_class_name(),
					'download_and_install',
				)
			);
			MainWP_Post_Handler::instance()->add_action(
				'mainwp_extension_bulk_activate',
				array(
					self::get_class_name(),
					'bulk_activate',
				)
			);
			MainWP_Post_Handler::instance()->add_action(
				'mainwp_extension_apisslverifycertificate',
				array(
					self::get_class_name(),
					'save_api_ssl_verify',
				)
			);
		}
	}


	public static function ajax_add_extension_menu() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_add_menu' );
		self::add_extension_menu( $_POST['slug'] );
		die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
	}

	public static function add_extension_menu( $slug ) {
		$snMenuExtensions = get_option( 'mainwp_extmenu' );
		if ( ! is_array( $snMenuExtensions ) ) {
			$snMenuExtensions = array();
		}

		$snMenuExtensions[] = $slug;

		MainWP_Utility::update_option( 'mainwp_extmenu', $snMenuExtensions );
		do_action( 'mainwp_added_extension_menu', $slug );

		return true;
	}

	public static function activate_extension() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_activate' );
		$api       = dirname( $_POST['slug'] );
		$api_key   = trim( $_POST['key'] );
		$api_email = trim( $_POST['email'] );
		$result    = MainWP_Api_Manager::instance()->license_key_activation( $api, $api_key, $api_email );
		wp_send_json( $result );
	}

	public static function deactivate_extension() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_deactivate' );
		$api    = dirname( $_POST['slug'] );
		$result = MainWP_Api_Manager::instance()->license_key_deactivation( $api );
		wp_send_json( $result );
	}


	public static function grab_extension_api_key() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_grabapikey' );
		$username = trim( $_POST['username'] );
		$password = trim( $_POST['password'] );
		$api      = dirname( $_POST['slug'] );
		$result   = MainWP_Api_Manager::instance()->grab_license_key( $api, $username, $password );
		wp_send_json( $result );
	}

	public static function save_extensions_api_login() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_saveextensionapilogin' );
		$api_login_history = isset( $_SESSION['api_login_history'] ) ? $_SESSION['api_login_history'] : array();

		$new_api_login_history = array();
		$requests              = 0;

		foreach ( $api_login_history as $api_login ) {
			if ( $api_login['time'] > ( time() - 1 * 60 ) ) {
				$new_api_login_history[] = $api_login;
				$requests++;
			}
		}

		if ( 4 < $requests ) {
			$_SESSION['api_login_history'] = $new_api_login_history;
			die( wp_json_encode( array( 'error' => __( 'Too many requests', 'mainwp' ) ) ) );
		} else {
			$new_api_login_history[]       = array( 'time' => time() );
			$_SESSION['api_login_history'] = $new_api_login_history;
		}

		$username = trim( $_POST['username'] );
		$password = trim( $_POST['password'] );
		if ( ( '' === $username ) && ( '' === $password ) ) {
			MainWP_Utility::update_option( 'mainwp_extensions_api_username', $username );
			MainWP_Utility::update_option( 'mainwp_extensions_api_password', $password );
			die( wp_json_encode( array( 'saved' => 1 ) ) );
		}
		$result = array();
		try {
			$test = MainWP_Api_Manager::instance()->test_login_api( $username, $password );
		} catch ( Exception $e ) {
			$return['error'] = $e->getMessage();
			die( wp_json_encode( $return ) );
		}

		if ( is_array( $test ) && isset( $test['retry_action'] ) ) {
			wp_send_json( $test );
		}

		$result     = json_decode( $test, true );
		$save_login = ( isset( $_POST['saveLogin'] ) && ( '1' === $_POST['saveLogin'] ) ) ? true : false;
		$return     = array();
		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) && $result['success'] ) {
				if ( $save_login ) {
					$enscrypt_u = MainWP_Api_Manager_Password_Management::encrypt_string( $username );
					$enscrypt_p = MainWP_Api_Manager_Password_Management::encrypt_string( $password );
					MainWP_Utility::update_option( 'mainwp_extensions_api_username', $enscrypt_u );
					MainWP_Utility::update_option( 'mainwp_extensions_api_password', $enscrypt_p );
					MainWP_Utility::update_option( 'mainwp_extensions_api_save_login', true );
				}
				$return['result'] = 'SUCCESS';
			} elseif ( isset( $result['error'] ) ) {
				$return['error'] = $result['error'];
			}
		}

		if ( ! $save_login ) {
			MainWP_Utility::update_option( 'mainwp_extensions_api_username', '' );
			MainWP_Utility::update_option( 'mainwp_extensions_api_password', '' );
			MainWP_Utility::update_option( 'mainwp_extensions_api_save_login', '' );
		}

		die( wp_json_encode( $return ) );
	}

	public static function save_api_ssl_verify() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_apisslverifycertificate' );
		MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', intval( $_POST['api_sslverify'] ) );
		die( wp_json_encode( array( 'saved' => 1 ) ) );
	}


	public static function test_extensions_api_login() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_testextensionapilogin' );
		$enscrypt_u = get_option( 'mainwp_extensions_api_username' );
		$enscrypt_p = get_option( 'mainwp_extensions_api_password' );
		$username   = ! empty( $enscrypt_u ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_u ) : '';
		$password   = ! empty( $enscrypt_p ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_p ) : '';

		if ( ( '' === $username ) || ( '' === $password ) ) {
			die( wp_json_encode( array( 'error' => __( 'Login Invalid.', 'mainwp' ) ) ) );
		}

		$result = array();
		try {
			$test = MainWP_Api_Manager::instance()->test_login_api( $username, $password );
		} catch ( Exception $e ) {
			$return['error'] = $e->getMessage();
			die( wp_json_encode( $return ) );
		}

		if ( is_array( $test ) && isset( $test['retry_action'] ) ) {
			wp_send_json( $test );
		}

		$result = json_decode( $test, true );
		$return = array();
		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) && $result['success'] ) {
				$return['result'] = 'SUCCESS';
			} elseif ( isset( $result['error'] ) ) {
				$return['error'] = $result['error'];
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

				self::load_extensions();

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

	public static function http_request_reject_unsafe_urls( $r, $url ) {
		$r['reject_unsafe_urls'] = false;

		return $r;
	}

	public static function no_ssl_filter_function( $r, $url ) {
		$r['sslverify'] = false;

		return $r;
	}

	public static function no_ssl_filter_extension_upgrade( $r, $url ) {
		if ( ( false !== strpos( $url, 'am_download_file=' ) ) && ( false !== strpos( $url, 'am_email=' ) ) ) {
			$r['sslverify'] = false;
		}

		return $r;
	}

	public static function activate_license() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_activatelicense' );
		$item_id  = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
		$response = MainWP_Api_Manager::instance()->grab_license_key_by_id( $item_id );
		die( wp_json_encode( $response ) );
	}

	public static function download_and_install() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_downloadandinstall' );

		ini_set( 'zlib.output_compression', 'Off' );

		$return = self::install_plugin( $_POST['download_link'] );

		die( '<mainwp>' . wp_json_encode( $return ) . '</mainwp>' );
	}

	public static function install_plugin( $url, $activatePlugin = false ) {

		$hasWPFileSystem = MainWP_Utility::get_wp_file_system();

		global $wp_filesystem;

		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/screen.php';
		}

		include_once ABSPATH . '/wp-admin/includes/template.php';
		include_once ABSPATH . '/wp-admin/includes/misc.php';
		include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . '/wp-admin/includes/plugin.php';

		$installer          = new WP_Upgrader();
		$ssl_verifyhost     = get_option( 'mainwp_sslVerifyCertificate' );
		$ssl_api_verifyhost = ( ( false === get_option( 'mainwp_api_sslVerifyCertificate' ) ) || ( 1 == get_option( 'mainwp_api_sslVerifyCertificate' ) ) ) ? 1 : 0;

		if ( '0' === $ssl_verifyhost || 0 == $ssl_api_verifyhost ) {
			add_filter( 'http_request_args', array( self::get_class_name(), 'no_ssl_filter_function' ), 99, 2 );
		}

		add_filter( 'http_request_args', array( self::get_class_name(), 'http_request_reject_unsafe_urls' ), 99, 2 );

		$result = $installer->run(
			array(
				'package'           => $url,
				'destination'       => WP_PLUGIN_DIR,
				'clear_destination' => false,
				'clear_working'     => true,
				'hook_extra'        => array(),
			)
		);

		remove_filter( 'http_request_args', array( self::get_class_name(), 'http_request_reject_unsafe_urls' ), 99, 2 );

		if ( '0' === $ssl_verifyhost ) {
			remove_filter( 'http_request_args', array( self::get_class_name(), 'no_ssl_filter_function' ), 99 );
		}

		$error       = null;
		$output      = null;
		$plugin_slug = null;

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			if ( $result->get_error_data() && is_string( $result->get_error_data() ) ) {
				$error = $error_code . ' - ' . $result->get_error_data();
			} else {
				$error = $error_code;
			}
		} else {
			$path = $result['destination'];

			foreach ( $result['source_files'] as $srcFile ) {

				if ( 'readme.txt' === $srcFile ) {
					continue;
				}

				$thePlugin = get_plugin_data( $path . $srcFile );

				if ( null != $thePlugin && '' !== $thePlugin && '' !== $thePlugin['Name'] ) {
					$output     .= esc_html( $thePlugin['Name'] ) . ' (' . esc_html( $thePlugin['Version'] ) . ')' . __( ' installed successfully.', 'mainwp' );
					$plugin_slug = $result['destination_name'] . '/' . $srcFile;

					if ( $activatePlugin ) {
						activate_plugin( $path . $srcFile, '', false, true );
						do_action( 'mainwp_api_extension_activated', $path . $srcFile );
					}

					break;
				}
			}
		}

		if ( ! empty( $error ) ) {
			$return['error'] = $error;
		} else {
			$return['result'] = 'SUCCESS';
			$return['output'] = $output;
			$return['slug']   = esc_html( $plugin_slug );
		}

		return $return;
	}

	public static function bulk_activate() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_bulk_activate' );
		$plugins = $_POST['plugins'];
		if ( is_array( $plugins ) && 0 < count( $plugins ) ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				activate_plugins( $plugins );
				die( 'SUCCESS' );
			}
		}
		die( 'FAILED' );
	}

	public static function remove_extension_menu_from_mainwp_menu() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_remove_menu' );
		$snMenuExtensions = get_option( 'mainwp_extmenu' );
		if ( ! is_array( $snMenuExtensions ) ) {
			$snMenuExtensions = array();
		}

		$key = array_search( $_POST['slug'], $snMenuExtensions );

		if ( false !== $key ) {
			unset( $snMenuExtensions[ $key ] );
		}

		MainWP_Utility::update_option( 'mainwp_extmenu', $snMenuExtensions );
		do_action( 'mainwp_removed_extension_menu', $_POST['slug'] );
		die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function render_header( $shownPage = '' ) {
		MainWP_Extensions_View::render_header( $shownPage, self::$extensions );
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function render_footer( $shownPage ) {
		MainWP_Extensions_View::render_footer( $shownPage );
	}

	public static function render() {

		$params = array(
			'title' => __( 'Extensions', 'mainwp' ),
		);
		MainWP_UI::render_top_header( $params );

		MainWP_Extensions_View::render( self::$extensions );
		echo '</div>';
	}

	public static function is_extension_available( $pAPI ) {
		$extensions = ( self::$extensionsLoaded ? self::$extensions : get_option( 'mainwp_extensions' ) );
		if ( isset( $extensions ) && is_array( $extensions ) ) {
			foreach ( $extensions as $extension ) {
				$slug = dirname( $extension['slug'] );
				if ( $slug == $pAPI ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function is_extension_enabled( $pluginFile ) {
		return array( 'key' => md5( $pluginFile . '-SNNonceAdder' ) );
	}

	public static function added_on_menu( $slug ) {
		$snMenuExtensions = get_option( 'mainwp_extmenu' );
		if ( ! is_array( $snMenuExtensions ) ) {
			$snMenuExtensions = array();
		}
		return in_array( $slug, $snMenuExtensions );
	}

	public static function is_extension_activated( $plugin_slug ) {
		$extensions = self::get_extensions( array( 'activated' => true ) );
		return isset( $extensions[ $plugin_slug ] ) ? true : false;
	}

	public static function hook_verify( $pluginFile, $key ) {
		return ( md5( $pluginFile . '-SNNonceAdder' ) == $key );
	}

	public static function hook_get_dashboard_sites( $pluginFile, $key ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return null;
		}

		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid );
		} else {
			$sql = MainWP_DB::instance()->get_sql_websites_for_current_user();
		}

		return MainWP_DB::instance()->query( $sql );
	}

	public static function hook_fetch_urls_authed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		return MainWP_Utility::fetch_urls_authed( $dbwebsites, $what, $params, $handle, $output );
	}

	public static function hook_fetch_url_authed( $pluginFile, $key, $websiteId, $what, $params, $rawResponse = null ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		try {
			$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
			if ( ! MainWP_Utility::can_edit_website( $website ) ) {
				throw new MainWP_Exception( 'You can not edit this website.' );
			}

			return MainWP_Utility::fetch_url_authed( $website, $what, $params, $checkConstraints = false, $pForceFetch = false, $pRetryFailed = true, $rawResponse );
		} catch ( MainWP_Exception $e ) {
			return array( 'error' => MainWP_Error_Helper::get_error_message( $e ) );
		}
	}

	private static $possible_options = array(
		'plugin_upgrades'  => 'plugin_upgrades',
		'theme_upgrades'   => 'theme_upgrades',
		'premium_upgrades' => 'premium_upgrades',
		'plugins'          => 'plugins',
		'dtsSync'          => 'dtsSync',
		'version'          => 'version',
		'sync_errors'      => 'sync_errors',
		'ignored_plugins'  => 'ignored_plugins',
	);

	public static function hook_get_db_sites( $pluginFile, $key, $sites, $groups = '', $options = false ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		$dbwebsites = array();
		$data       = array( 'id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey', 'verify_certificate', 'ssl_version', 'http_user', 'http_pass' );

		if ( is_array( $options ) ) {
			foreach ( $options as $option_name => $value ) {
				if ( ( true == $value ) && isset( self::$possible_options[ $option_name ] ) ) {
					$data[] = self::$possible_options[ $option_name ];
				}
			}
		}

		if ( '' !== $sites ) {
			foreach ( $sites as $k => $v ) {
				if ( MainWP_Utility::ctype_digit( $v ) ) {
					$website                    = MainWP_DB::instance()->get_website_by_id( $v );
					$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data );
				}
			}
		}

		if ( '' !== $groups ) {
			foreach ( $groups as $k => $v ) {
				if ( MainWP_Utility::ctype_digit( $v ) ) {
					$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $v ) );
					while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
						$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data );
					}
					MainWP_DB::free_result( $websites );
				}
			}
		}

		return $dbwebsites;
	}

	/**
	 * @param string $pluginFile Extension plugin file to verify
	 * @param string $key The child-key
	 * @param int    $websiteid The id of the child-site you wish to retrieve
	 * @param bool   $for_manager
	 *
	 * @return array|bool An array of arrays, the inner-array contains the id/url/name/totalsize of the website. False when something goes wrong.
	 */
	public static function hook_get_sites( $pluginFile, $key, $websiteid = null, $for_manager = false, $others = array() ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		if ( $for_manager && ( ! defined( 'MWP_TEAMCONTROL_PLUGIN_SLUG' ) || ! mainwp_current_user_can( 'extension', dirname( MWP_TEAMCONTROL_PLUGIN_SLUG ) ) ) ) {
			return false;
		}

		if ( ! is_array( $others ) ) {
			$others = array();
		}

		$search_site = null;
		$orderBy     = 'wp.url';
		$offset      = false;
		$rowcount    = false;
		$extraWhere  = null;

		if ( isset( $websiteid ) && ( null != $websiteid ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $websiteid );

			if ( ! MainWP_Utility::can_edit_website( $website ) ) {
				return false;
			}

			if ( ! mainwp_current_user_can( 'site', $websiteid ) ) {
				return false;
			}

			return array(
				array(
					'id'        => $websiteid,
					'url'       => MainWP_Utility::get_nice_url( $website->url, true ),
					'name'      => $website->name,
					'totalsize' => $website->totalsize,
				),
			);
		} else {
			if ( isset( $others['orderby'] ) ) {
				if ( ( 'site' === $others['orderby'] ) ) {
					$orderBy = 'wp.name ' . ( 'asc' === $others['order'] ? 'asc' : 'desc' );
				} elseif ( ( 'url' === $others['orderby'] ) ) {
					$orderBy = 'wp.url ' . ( 'asc' === $others['order'] ? 'asc' : 'desc' );
				}
			}
			if ( isset( $others['search'] ) ) {
				$search_site = trim( $others['search'] );
			}

			if ( is_array( $others ) ) {
				if ( isset( $others['plugins_slug'] ) ) {

					$slugs      = explode( ',', $others['plugins_slug'] );
					$extraWhere = '';
					foreach ( $slugs as $slug ) {
						$slug        = wp_json_encode( $slug );
						$slug        = trim( $slug, '"' );
						$slug        = str_replace( '\\', '.', $slug );
						$extraWhere .= ' wp.plugins REGEXP "' . $slug . '" OR';
					}
					$extraWhere = trim( rtrim( $extraWhere, 'OR' ) );

					if ( '' === $extraWhere ) {
						$extraWhere = null;
					} else {
						$extraWhere = '(' . $extraWhere . ')';
					}
				}
			}
		}

				$totalRecords = '';

		if ( isset( $others['per_page'] ) && ! empty( $others['per_page'] ) ) {
			$sql            = MainWP_DB::instance()->get_sql_websites_for_current_user( false, $search_site, $orderBy, false, false, $extraWhere, $for_manager );
			$websites_total = MainWP_DB::instance()->query( $sql );
			$totalRecords   = ( $websites_total ? MainWP_DB::num_rows( $websites_total ) : 0 );

			if ( $websites_total ) {
				MainWP_DB::free_result( $websites_total );
			}

			$rowcount = absint( $others['per_page'] );
			$pagenum  = isset( $others['paged'] ) ? absint( $others['paged'] ) : 0;
			if ( $pagenum > $totalRecords ) {
				$pagenum = $totalRecords;
			}
			$pagenum = max( 1, $pagenum );
			$offset  = ( $pagenum - 1 ) * $rowcount;

		}

		$sql      = MainWP_DB::instance()->get_sql_websites_for_current_user( false, $search_site, $orderBy, $offset, $rowcount, $extraWhere, $for_manager );
		$websites = MainWP_DB::instance()->query( $sql );

		$output = array();
		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			$re = array(
				'id'        => $website->id,
				'url'       => MainWP_Utility::get_nice_url( $website->url, true ),
				'name'      => $website->name,
				'totalsize' => $website->totalsize,
			);

			if ( 0 < $totalRecords ) {
				$re['totalRecords'] = $totalRecords;
				$totalRecords       = 0;
			}

			$output[] = $re;
		}
		MainWP_DB::free_result( $websites );

		return $output;
	}

	/**
	 * @param string $pluginFile Extension plugin file to verify
	 * @param string $key The child-key
	 * @param int    $groupid The id of the group you wish to retrieve
	 * @param bool   $for_manager
	 *
	 * @return array|bool An array of arrays, the inner-array contains the id/name/array of site ids for the supplied groupid/all groups. False when something goes wrong.
	 */
	public static function hook_get_groups( $pluginFile, $key, $groupid, $for_manager = false ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		if ( $for_manager && ( ! defined( 'MWP_TEAMCONTROL_PLUGIN_SLUG' ) || ! mainwp_current_user_can( 'extension', dirname( MWP_TEAMCONTROL_PLUGIN_SLUG ) ) ) ) {
			return false;
		}

		if ( isset( $groupid ) ) {
			$group = MainWP_DB::instance()->get_group_by_id( $groupid );
			if ( ! MainWP_Utility::can_edit_group( $group ) ) {
				return false;
			}

			$websites    = MainWP_DB::instance()->get_websites_by_group_id( $group->id );
			$websitesOut = array();
			foreach ( $websites as $website ) {
				$websitesOut[] = $website->id;
			}

			return array(
				array(
					'id'       => $groupid,
					'name'     => $group->name,
					'websites' => $websitesOut,
				),
			);
		}

		$groups = MainWP_DB::instance()->get_groups_and_count( null, $for_manager );
		$output = array();
		foreach ( $groups as $group ) {
			$websites    = MainWP_DB::instance()->get_websites_by_group_id( $group->id );
			$websitesOut = array();
			foreach ( $websites as $website ) {
				if ( in_array( $website->id, $websitesOut ) ) {
					continue;
				}
				$websitesOut[] = $website->id;
			}
			$output[] = array(
				'id'       => $group->id,
				'name'     => $group->name,
				'websites' => $websitesOut,
			);
		}

		return $output;
	}

	public static function hook_manager_get_extensions() {
		return get_option( 'mainwp_manager_extensions' );
	}

	public static function hook_clone_site( $pluginFile, $key, $websiteid, $cloneID, $clone_url, $force_update = false ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		if ( ! empty( $websiteid ) && ! empty( $cloneID ) ) {

			$sql      = MainWP_DB::instance()->get_sql_website_by_id( $websiteid );
			$websites = MainWP_DB::instance()->query( $sql );
			$website  = MainWP_DB::fetch_object( $websites );

			if ( empty( $website ) ) {
				return array( 'error' => __( 'Website not found.', 'mainwp' ) );
			}

			$ret = array();

			if ( '/' !== substr( $clone_url, - 1 ) ) {
				$clone_url .= '/';
			}

			$tmp1 = MainWP_Utility::remove_http_www_prefix( $website->url );
			$tmp2 = MainWP_Utility::remove_http_www_prefix( $clone_url );

			if ( false === strpos( $tmp2, $tmp1 ) ) {
					return false;
			}

			$clone_sites = MainWP_DB::instance()->get_websites_by_url( $clone_url );
			if ( $clone_sites ) {
				$clone_site = current( $clone_sites );
				if ( $clone_site && $clone_site->is_staging ) {
					if ( $force_update ) {
						MainWP_DB::instance()->update_website_values(
							$clone_site->id,
							array(
								'adminname'          => $website->adminname,
								'pubkey'             => $website->pubkey,
								'privkey'            => $website->privkey,
								'nossl'              => $website->nossl,
								'nosslkey'           => $website->nosslkey,
								'verify_certificate' => $website->verify_certificate,
								'uniqueId'           => ( null !== $website->uniqueId ? $website->uniqueId : '' ),
								'http_user'          => $website->http_user,
								'http_pass'          => $website->http_pass,
								'ssl_version'        => $website->ssl_version,
							)
						);
					}
					$ret['siteid']   = $clone_site->id;
					$ret['response'] = __( 'Site updated.', 'mainwp' );
				}
				return $ret;
			}
			$clone_name = $website->name . ' - ' . $cloneID;
			global $current_user;

			$id = MainWP_DB::instance()->add_website( $current_user->ID, $clone_name, $clone_url, $website->adminname, $website->pubkey, $website->privkey, $website->nossl, $website->nosslkey, array(), array(), $website->verify_certificate, ( null !== $website->uniqueId ? $website->uniqueId : '' ), $website->http_user, $website->http_pass, $website->ssl_version, $website->wpe, $isStaging = 1 );

			do_action( 'mainwp_added_new_site', $id );

			if ( $id ) {
				$group_id = get_option( 'mainwp_stagingsites_group_id' );
				if ( $group_id ) {
					$website = MainWP_DB::instance()->get_website_by_id( $id );
					if ( MainWP_Utility::can_edit_website( $website ) ) {
						MainWP_Sync::sync_site( $website, false, false );
						$group = MainWP_DB::instance()->get_group_by_id( $group_id );
						if ( MainWP_Utility::can_edit_group( $group ) ) {
							MainWP_DB::instance()->update_group_site( $group->id, $id );
						}
					}
				}
				$ret['response'] = __( 'Site successfully added.', 'mainwp' );
				$ret['siteid']   = $id;
			}
			return $ret;
		}

		return false;
	}

	public static function hook_delete_clone_site( $pluginFile, $key, $clone_url = '', $clone_site_id = false ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		if ( ( empty( $clone_url ) && empty( $clone_site_id ) ) ) {
			return false;
		}

		$clone_site = null;
		if ( ! empty( $clone_url ) ) {
			if ( '/' !== substr( $clone_url, - 1 ) ) {
				$clone_url .= '/';
			}
			$clone_sites = MainWP_DB::instance()->get_websites_by_url( $clone_url );
			if ( ! empty( $clone_sites ) ) {
				$clone_site = current( $clone_sites );

			}
		} elseif ( ! empty( $clone_site_id ) ) {
			$sql        = MainWP_DB::instance()->get_sql_website_by_id( $clone_site_id );
			$websites   = MainWP_DB::instance()->query( $sql );
			$clone_site = MainWP_DB::fetch_object( $websites );
		}

		if ( empty( $clone_site ) ) {
			return array( 'error' => __( 'Not found the clone website', 'mainwp' ) );
		}

		if ( $clone_site ) {
			if ( 0 == $clone_site->is_staging ) {
				return false;
			}

			$hasWPFileSystem = MainWP_Utility::get_wp_file_system();

			global $wp_filesystem;

			$favi = MainWP_DB::instance()->get_website_option( $clone_site, 'favi_icon', '' );
			if ( ! empty( $favi ) && ( false !== strpos( $favi, 'favi-' . $clone_site->id . '-' ) ) ) {
				$dirs = MainWP_Utility::get_icons_dir();
				if ( $wp_filesystem->exists( $dirs[0] . $favi ) ) {
					$wp_filesystem->delete( $dirs[0] . $favi );
				}
			}

			MainWP_DB::instance()->remove_website( $clone_site->id );
			do_action( 'mainwp_delete_site', $clone_site );
			return array( 'result' => 'SUCCESS' );
		}

		return false;
	}


	public static function hook_add_group( $pluginFile, $key, $newName ) {

		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		global $current_user;
		if ( ! empty( $newName ) ) {
			$groupId = MainWP_DB::instance()->add_group( $current_user->ID, MainWP_Manage_Groups::check_group_name( $newName ) );
			do_action( 'mainwp_added_new_group', $groupId );
			return $groupId;
		}
		return false;
	}

	/*
	 * Hook the section help content to the Help Sidebar element
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
