<?php

class MainWP_Extensions {
	public static function getClassName() {
		return __CLASS__;
	}

	public static $extensionsLoaded = false;
	public static $extensions;

	public static function getPluginSlug( $pSlug ) {
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

	public static function getSlugs() {
		$currentExtensions = ( self::$extensionsLoaded ? self::$extensions : get_option( 'mainwp_extensions' ) );

		if ( ! is_array( $currentExtensions ) || empty( $currentExtensions ) ) {
			return array( 'slugs' => '', 'am_slugs' => '' );
		}

		$out    = '';
		$am_out = '';
		foreach ( $currentExtensions as $extension ) {
			if ( ! isset( $extension['api'] ) || $extension['api'] == '' ) {
				continue;
			}

			if ( isset( $extension['apiManager'] ) && ! empty( $extension['apiManager'] ) && $extension['activated_key'] == 'Activated' ) {
				if ( $am_out != '' ) {
					$am_out .= ',';
				}
				$am_out .= $extension['api'];
			} else {
				if ( $out != '' ) {
					$out .= ',';
				}
				$out .= $extension['api'];
			}
		}

		return array( 'slugs' => $out, 'am_slugs' => $am_out );
	}


	public static function init() {
		/**
		 * This hook allows you to render the Extensions page header via the 'mainwp-pageheader-extensions' action.
		 * @link http://codex.mainwp.com/#mainwp-pageheader-extensions
		 *
		 * @see \MainWP_Extensions::renderHeader
		 */
		add_action( 'mainwp-pageheader-extensions', array( MainWP_Extensions::getClassName(), 'renderHeader' ) );

		/**
		 * This hook allows you to render the Extensions page footer via the 'mainwp-pagefooter-extensions' action.
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-extensions
		 *
		 * @see \MainWP_Extensions::renderFooter
		 */
		add_action( 'mainwp-pagefooter-extensions', array( MainWP_Extensions::getClassName(), 'renderFooter' ) );
		add_filter( 'mainwp-extensions-apigeneratepassword', array(
			MainWP_Extensions::getClassName(),
			'genApiPassword',
		), 10, 3 );
	}

	public static function initMenu() {
		$page = MainWP_Extensions_View::initMenu();
		add_action( 'load-' . $page, array(MainWP_Extensions::getClassName(), 'on_load_page'));

		self::$extensions = array();
		$all_extensions   = array();

		$newExtensions = apply_filters( 'mainwp-getextensions', array() );
		$extraHeaders  = array(
			'IconURI'          => 'Icon URI',
			'SupportForumURI'  => 'Support Forum URI',
			'DocumentationURI' => 'Documentation URI',
		);
        $extsPages = array();
		foreach ( $newExtensions as $extension ) {
			$slug        = plugin_basename( $extension['plugin'] );
			$plugin_data = get_plugin_data( $extension['plugin'] );
			$file_data   = get_file_data( $extension['plugin'], $extraHeaders );
			if ( ! isset( $plugin_data['Name'] ) || ( $plugin_data['Name'] == '' ) ) {
				continue;
			}

			$extension['slug']             = $slug;
			$extension['name']             = $plugin_data['Name'];
			$extension['version']          = $plugin_data['Version'];
			$extension['description']      = $plugin_data['Description'];
			$extension['author']           = $plugin_data['Author'];
			$extension['iconURI']          = $file_data['IconURI'];
			$extension['SupportForumURI']  = $file_data['SupportForumURI'];
			$extension['DocumentationURI'] = $file_data['DocumentationURI'];
			$extension['page']             = 'Extensions-' . str_replace( ' ', '-', ucwords( str_replace( '-', ' ', dirname( $slug ) ) ) );

			if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) {
				$api     = dirname( $slug );
				$options = get_option( $api . '_APIManAdder' );
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
                        $menu_name = str_replace( array(
							'Extension',
							'MainWP',
						), '', $extension['name'] );
						if (MainWP_Extensions::addedOnMenu( $slug )) {
							$_page = add_submenu_page( 'mainwp_tab', $extension['name'], $menu_name, 'read', $extension['page'], $extension['callback'] );
						} else {
							$_page = add_submenu_page( 'mainwp_tab', $extension['name'], '<div class="mainwp-hidden">' . $extension['name'] . '</div>', 'read', $extension['page'], $extension['callback'] );
						}

						if ( isset( $extension['on_load_callback'] ) && !empty($extension['on_load_callback'])) {
							add_action( 'load-' . $_page, $extension['on_load_callback']);
						}
                        $extsPages[] = array('title' => $menu_name, 'page' => $extension['page']);
					}
				}
			}
		}
		MainWP_Utility::update_option( 'mainwp_extensions', self::$extensions );
		MainWP_Utility::update_option( 'mainwp_manager_extensions', $all_extensions );
		self::$extensionsLoaded = true;
        MainWP_Extensions::init_sub_sub_left_menu($extsPages);
	}

    static function init_sub_sub_left_menu($extPages) {
        global $mainwp_menu_active_slugs;
        // to get parent menu items
        $mainwp_menu_active_slugs['Extensions'] = 'Extensions';
        if (is_array($extPages)) {
            foreach($extPages as $extension) {
                MainWP_System::add_sub_left_menu($extension['title'], 'Extensions', $extension['page'], 'admin.php?page=' . $extension['page'], '', '' );
            }
        }
    }

	public static function on_load_page() {
		MainWP_System::enqueue_postbox_scripts();
		self::add_meta_boxes();
	}

	public static function add_meta_boxes() {
		$i = 1;
		if ( mainwp_current_user_can( 'dashboard', 'bulk_install_and_activate_extensions' ) ) {
			add_meta_box(
				'mwp-extension-contentbox-' . $i++,
				'<i class="fa fa-cog"></i> ' . __( 'Bulk install and activate extensions', 'mainwp' ),
				array( 'MainWP_Extensions_View', 'renderInstallAndActive' ),
				'mainwp_postboxes_manage_extensions',
				'normal',
				'core'
			);
		}

		add_meta_box(
			'mwp-extension-contentbox-' . $i++,
			'<i class="fa fa-cog"></i> ' . sprintf( _n( '%d Installed MainWP Extension', '%d Installed MainWP Extensions', ( count( self::$extensions ) == 1 ? 1 : 2 ), 'mainwp' ), count( self::$extensions ) ),
			array( 'MainWP_Extensions_View', 'renderInstalledExtensions' ),
			'mainwp_postboxes_manage_extensions',
			'normal',
			'core',
			array( 'extensions' => self::$extensions )
		);

		add_meta_box(
			'mwp-extension-contentbox-' . $i++,
			'<i class="fa fa-cog"></i> ' . sprintf( __('Available %sMainWP Extensions%s', 'mainwp'), '<a href="https://mainwp.com/mainwp-extensions">', '</a>' ),
			array( 'MainWP_Extensions_View', 'renderAvailableExtensions' ),
			'mainwp_postboxes_manage_extensions',
			'normal',
			'core',
			array( 'extensions' => self::$extensions )
		);
	}

	public static function loadExtensions() {
		if ( ! isset( self::$extensions ) ) {
			self::$extensions = get_option( 'mainwp_extensions' );
			if ( ! is_array( self::$extensions ) ) {
				self::$extensions = array();
			}
			self::$extensionsLoaded = true;
		}

		return self::$extensions;
	}

	public static function getExtensions($args = array()) {
		if (!is_array($args))
			$args = array();

		$extensions = MainWP_Extensions::loadExtensions();

		$return      = array();
		foreach ( $extensions as $extension ) {
			if ( isset( $args['activated'] ) && !empty( $args['activated'] ) ) {
				if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) {
					if ( !isset( $extension['activated_key'] ) || 'Activated' != $extension['activated_key'] )
						continue;
				}
			}
			$ext                         = array();
			$ext['version']              = $extension['version'];
			$ext['name']				 = $extension['name'];
			$ext['page']				 = $extension['page'];
			$ext['page']				 = $extension['page'];
			if ( isset( $extension['activated_key'] ) && 'Activated' == $extension['activated_key'] ) {
				$ext['activated_key']              = 'Activated';
			}
			$return[ $extension['slug'] ] = $ext;
		}
		return $return;
	}

	public static function getExtensionsPageSlug() {
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

	public static function genApiPassword( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$api_manager_password_management = new MainWP_Api_Manager_Password_Management();

		return $api_manager_password_management->generate_password( $length, $special_chars, $extra_special_chars );
	}

	public static function initMenuSubPages() {
		//if (true) return;
		if ( empty( self::$extensions ) ) {
			return;
		}
		$html = '';
		if ( isset( self::$extensions ) && is_array( self::$extensions ) ) {
			foreach ( self::$extensions as $extension ) {
				if ( defined( 'MWP_TEAMCONTROL_PLUGIN_SLUG' ) && ( MWP_TEAMCONTROL_PLUGIN_SLUG == $extension['slug'] ) && ! mainwp_current_user_can( 'extension', dirname( MWP_TEAMCONTROL_PLUGIN_SLUG ) ) ) {
					continue;
				}
				if (MainWP_Extensions::addedOnMenu( $extension['slug'] )) {
					continue;
				}
				if ( isset( $extension['direct_page'] ) ) {
					$html .= '<a href="' . admin_url( 'admin.php?page=' . $extension['direct_page'] ) . '"
							   class="mainwp-submenu">' . str_replace( array(
							'Extension',
							'MainWP',
						), '', $extension['name'] ) . '</a>';
				} else {
					$html .= '<a href="' . admin_url( 'admin.php?page=' . $extension['page'] ) . '"
							   class="mainwp-submenu">' . str_replace( array(
							'Extension',
							'MainWP',
						), '', $extension['name'] ) . '</a>';
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

	public static function initAjaxHandlers() {
//		add_action( 'wp_ajax_mainwp_extension_enable_all', array(
//			MainWP_Extensions::getClassName(),
//			'enableAllExtensions',
//		) );
//		add_action( 'wp_ajax_mainwp_extension_disable_all', array(
//			MainWP_Extensions::getClassName(),
//			'disableAllExtensions',
//		) );
		add_action( 'wp_ajax_mainwp_extension_add_menu', array( MainWP_Extensions::getClassName(), 'ajaxAddExtensionMenu' ) );
		add_action( 'wp_ajax_mainwp_extension_remove_menu', array(
			MainWP_Extensions::getClassName(),
			'removeExtensionMenuFromMainWPMenu',
		) );
		MainWP_System::Instance()->posthandler->addAction( 'mainwp_extension_activate', array(
			MainWP_Extensions::getClassName(),
			'activateExtension',
		) );
		MainWP_System::Instance()->posthandler->addAction( 'mainwp_extension_deactivate', array(
			MainWP_Extensions::getClassName(),
			'deactivateExtension',
		) );
		add_action( 'wp_ajax_mainwp_extension_testextensionapilogin', array(
			MainWP_Extensions::getClassName(),
			'testExtensionsApiLogin',
		) );

		if ( mainwp_current_user_can( 'dashboard', 'bulk_install_and_activate_extensions' ) ) {
			add_action( 'wp_ajax_mainwp_extension_grabapikey', array(
				MainWP_Extensions::getClassName(),
				'grabapikeyExtension',
			) );
			MainWP_System::Instance()->posthandler->addAction( 'mainwp_extension_saveextensionapilogin', array(
				MainWP_Extensions::getClassName(),
				'saveExtensionsApiLogin',
			) );
			add_action( 'wp_ajax_mainwp_extension_getpurchased', array(
				MainWP_Extensions::getClassName(),
				'getPurchasedExts',
			) );
			MainWP_System::Instance()->posthandler->addAction( 'mainwp_extension_downloadandinstall', array(
				MainWP_Extensions::getClassName(),
				'downloadAndInstall',
			) );
			MainWP_System::Instance()->posthandler->addAction( 'mainwp_extension_bulk_activate', array(
				MainWP_Extensions::getClassName(),
				'bulkActivate',
			) );
			add_action( 'wp_ajax_mainwp_extension_apisslverifycertificate', array(
				MainWP_Extensions::getClassName(),
				'saveApiSSLVerify',
			) );
		}
	}

//	public static function enableAllExtensions() {
//		$snEnabledExtensions = array();
//
//		if ( isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] ) ) {
//			foreach ( $_POST['slugs'] as $slug ) {
//				$snEnabledExtensions[] = $slug;
//			}
//		}
//
//		MainWP_Utility::update_option( 'mainwp_extloaded', $snEnabledExtensions );
//
//		die( json_encode( array( 'result' => 'SUCCESS' ) ) );
//	}

//	public static function disableAllExtensions() {
//		MainWP_Utility::update_option( 'mainwp_extloaded', array() );
//
//		die( json_encode( array( 'result' => 'SUCCESS' ) ) );
//	}

	public static function ajaxAddExtensionMenu()
	{
		self::addExtensionMenu($_POST['slug']);
		die(json_encode(array('result' => 'SUCCESS')));
	}

	public static function addExtensionMenu($slug) {
		$snMenuExtensions = get_option( 'mainwp_extmenu' );
		if ( ! is_array( $snMenuExtensions ) ) {
			$snMenuExtensions = array();
		}

		$snMenuExtensions[] = $slug;

		return MainWP_Utility::update_option( 'mainwp_extmenu', $snMenuExtensions );
	}

	public static function activateExtension() {
		MainWP_System::Instance()->posthandler->secure_request( 'mainwp_extension_activate' );
		$api       = dirname( $_POST['slug'] );
		$api_key   = trim( $_POST['key'] );
		$api_email = trim( $_POST['email'] );
		$result    = MainWP_Api_Manager::instance()->license_key_activation( $api, $api_key, $api_email );
		die( json_encode( $result ) );
	}

	public static function deactivateExtension() {
		MainWP_System::Instance()->posthandler->secure_request( 'mainwp_extension_deactivate' );
		$api    = dirname( $_POST['slug'] );
		$result = MainWP_Api_Manager::instance()->license_key_deactivation( $api );
		die( json_encode( $result ) );
	}


	public static function grabapikeyExtension() {
		$username = trim( $_POST['username'] );
		$password = trim( $_POST['password'] );
		$api      = dirname( $_POST['slug'] );
		$result   = MainWP_Api_Manager::instance()->grab_license_key( $api, $username, $password );
		die( json_encode( $result ) );
	}

	public static function saveExtensionsApiLogin() {
		MainWP_System::Instance()->posthandler->secure_request('mainwp_extension_saveextensionapilogin');

		$api_login_history = isset( $_SESSION['api_login_history'] ) ? $_SESSION['api_login_history'] : array();

		$new_api_login_history = array();
		$requests = 0;
		foreach ( $api_login_history as $api_login ) {
			if ( $api_login['time'] > ( time() - 1 * 60 ) ) {
				$new_api_login_history[] = $api_login;
				$requests++;
			}
		}

		if ( $requests > 4 ) {
			$_SESSION['api_login_history'] = $new_api_login_history;
			die( json_encode( array( 'error' => __( 'Too many requests', 'mainwp' ) ) ) );
		} else {
			$new_api_login_history[] = array( 'time' => time() );
			$_SESSION['api_login_history'] = $new_api_login_history;
		}

		$username = trim( $_POST['username'] );
		$password = trim( $_POST['password'] );
		if ( ( $username == '' ) && ( $password == '' ) ) {
			MainWP_Utility::update_option( 'mainwp_extensions_api_username', $username );
			MainWP_Utility::update_option( 'mainwp_extensions_api_password', $password );
			die( json_encode( array( 'saved' => 1 ) ) );
		}
		$result = array();
		try {
			$test = MainWP_Api_Manager::instance()->test_login_api( $username, $password );
		} catch ( Exception $e ) {
			$return['error'] = $e->getMessage();
			die( json_encode( $return ) );
		}

		if ( is_array( $test ) && isset( $test['retry_action'] ) ) {
			die( json_encode( $test ) );
		}

		$result     = json_decode( $test, true );
		$save_login = ( isset( $_POST['saveLogin'] ) && ( $_POST['saveLogin'] == '1' ) ) ? true : false;
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
			} else if ( isset( $result['error'] ) ) {
				$return['error'] = $result['error'];
			}
		}

		if ( ! $save_login ) {
			MainWP_Utility::update_option( 'mainwp_extensions_api_username', '' );
			MainWP_Utility::update_option( 'mainwp_extensions_api_password', '' );
			MainWP_Utility::update_option( 'mainwp_extensions_api_save_login', '' );
		}

		die( json_encode( $return ) );
	}

	public static function saveApiSSLVerify() {
		MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', intval( $_POST['api_sslverify'] ) );
		die( json_encode( array( 'saved' => 1 ) ) );
	}


	public static function testExtensionsApiLogin() {
		$enscrypt_u = get_option( 'mainwp_extensions_api_username' );
		$enscrypt_p = get_option( 'mainwp_extensions_api_password' );
		$username   = ! empty( $enscrypt_u ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_u ) : '';
		$password   = ! empty( $enscrypt_p ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_p ) : '';

		if ( ( $username == '' ) || ( $password == '' ) ) {
			die( json_encode( array( 'error' => __( 'Login Invalid.', 'mainwp' ) ) ) );
		}

		$result = array();
		try {
			$test = MainWP_Api_Manager::instance()->test_login_api( $username, $password );
		} catch ( Exception $e ) {
			$return['error'] = $e->getMessage();
			die( json_encode( $return ) );
		}

		if ( is_array( $test ) && isset( $test['retry_action'] ) ) {
			die( json_encode( $test ) );
		}

		$result = json_decode( $test, true );
		$return = array();
		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) && $result['success'] ) {
				$return['result'] = 'SUCCESS';
			} else if ( isset( $result['error'] ) ) {
				$return['error'] = $result['error'];
			}
		} else {
			$apisslverify = get_option( 'mainwp_api_sslVerifyCertificate' );
			if ( $apisslverify == 1 ) {
				MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );
				$return['retry_action'] = 1;
			}
		}
		die( json_encode( $return ) );
	}


	public static function getPurchasedExts() {
		$username = trim( $_POST['username'] );
		$password = trim( $_POST['password'] );
		if ( ( $username == '' ) || ( $password == '' ) ) {
			die( json_encode( array( 'error' => __( 'Login Invalid.', 'mainwp' ) ) ) );
		}

		$data   = MainWP_Api_Manager::instance()->get_purchased_software( $username, $password );
		$result = json_decode( $data, true );
		$return = array();
		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) && $result['success'] ) {
				$all_available_exts = array();
				$map_extensions_group = array();
				$free_group = array();
				foreach ( MainWP_Extensions_View::getAvailableExtensions() as $ext ) {
					$all_available_exts[ $ext['product_id'] ] = $ext;
					$map_extensions_group[ $ext['product_id'] ] = current( $ext['group'] ); // first group
					if ( isset( $ext['free'] ) && !empty( $ext['free'] ) ) {
						$free_group[] = $ext['product_id'];
					}
				}
				self::loadExtensions();
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

				//todo update to coding standards
				$all_groups = MainWP_Extensions_View::getExtensionGroups();
				$grouped_exts = array( 'others' => '');
				foreach($installing_exts as $product_id => $product_info) {
					$item_html = '';
					$error = '';
					$software_title = isset($all_available_exts[$product_id]) ? $all_available_exts[$product_id]['title'] : $product_id;
					if (isset($product_info['package']) && !empty($product_info['package'])){
						$package_url = apply_filters('mainwp_api_manager_upgrade_url', $product_info['package']);
						$item_html = '<div class="extension_to_install" download-link="' . $package_url . '" product-id="' . $product_id . '"><input type="checkbox" status="queue" checked="true"> <span class="name"><strong>' . $software_title . "</strong></span> " . '<span class="ext_installing" status="queue"><i class="fa fa-spinner fa-pulse hidden" style="display: none;"></i> <span class="status hidden"><i class="fa fa-clock-o"></i> ' . __('Queued', 'mainwp') . '</span></span></div>';
					} else if (isset($product_info['error']) && !empty($product_info['error'])) {
						$error = MainWP_Api_Manager::instance()->check_response_for_intall_errors($product_info, $software_title);
					} else {
						$error = __('Undefined error.', 'mainwp');
					}

					if (!empty($error)) {
						$item_html = '<div><input type="checkbox" disabled="disabled"> <span class="name"><strong>' . $software_title . "</strong></span> <span style=\"color: red;\"><strong>Error</strong> " . $error . '</span></div>';
					}

					$group_id = isset($map_extensions_group[$product_id]) ? $map_extensions_group[$product_id] : false;
					if (!empty($group_id) && isset($all_groups[$group_id])) {
						if (isset($grouped_exts[$group_id]))
							$grouped_exts[$group_id] .= $item_html;
						else
							$grouped_exts[$group_id] = $item_html;
					} else {
						$grouped_exts['others'] .= $item_html;
					}
				}

				foreach($not_purchased_exts as $product_id => $ext) {
					$item_html = '<div class="extension_not_purchased" product-id="' . $product_id . '"><input type="checkbox" disabled="disabled"> <span class="name"><strong>' . $ext['title'] . '</strong></span> ' . __( 'Extension not purchased.', 'mainwp' ) . ' <a href="' . $ext['link'] . '" target="_blank">' .  __( 'Get it here!', 'mainwp') . '</a>' . (in_array($product_id, $free_group) ? " <em>" . __( 'It\'s free.', 'mainwp' ) ."</em>" : '') .'</div>';
					$group_id = isset($map_extensions_group[$product_id]) ? $map_extensions_group[$product_id] : false;
					if (!empty($group_id) && isset($all_groups[$group_id])) {
						if (isset($grouped_exts[$group_id]))
							$grouped_exts[$group_id] .= $item_html;
						else
							$grouped_exts[$group_id] = $item_html;
					} else {
						$grouped_exts['others'] .= $item_html;
					}
				}

				//todo update coding standards
				$html = '<div class="inside">';
				$html .= '<h2>' . __( 'Install Purchased Extensions', 'mainwp' ) . '</h2>';
				$html .= '<div class="mainwp_extension_installing">';
				if ( empty( $installing_exts ) ) {
					$html .= '<p>' . __( 'All purchased extensions are Installed', 'mainwp' ) . '</p>';
				} else {
					$html .= '<p><span class="description">' . __('You have access to all your purchased Extensions but you DO NOT need to install all off them. In order to avoid information overload, we highly recommend adding Extensions one at a time and as you need them. Uncheck any Extension you do not want to install.', 'mainwp') . '</span></p>';
					$html .= '<div><a id="mainwp-check-all-ext" href="javascript:void(0);"><i class="fa fa-check-square-o"></i> ' . __('Select All', 'mainwp') . '</a> | <a id="mainwp-uncheck-all-ext" href="javascript:void(0);"><i class="fa fa-square-o"></i> ' . __('Select None', 'mainwp') . '</a></div>';
				}

				foreach($all_groups as $gr_id => $gr_name) {
					if (isset($grouped_exts[$gr_id])) {
						$html .= '<h3>' . $gr_name . '</h3>';
						$html .= $grouped_exts[$gr_id];
					}
				}

				if (isset($grouped_exts['others']) && ! empty($grouped_exts['others'])) {
					$html .= '<h3>Others</h3>';
					$html .= $grouped_exts['others'];
				}

				$html .= '</div>';
				$html .= '</div>';

				if ( ! empty( $installing_exts ) ) {
					$html .= '<p>
                                <span class="extension_api_loading">
                                    <input type="button" class="mainwp-upgrade-button button-primary" id="mainwp-extensions-installnow" value="' . __( 'Install Selected Extensions', 'mainwp' ) . '">
                                    <i class="fa fa-spinner fa-pulse" style="display: none;"></i><span class="status hidden"></span>
                                </span>
                            </p> ';
				}

				$html .= '<p><div id="extBulkActivate"><i class="fa fa-spinner fa-pulse hidden" style="display: none"></i> <span class="status hidden"></span></div></p>';
				$return = array( 'result' => 'SUCCESS', 'data' => $html );
			} else if ( isset( $result['error'] ) ) {
				$return = array( 'error' => $result['error'] );
			}
		} else {
			$apisslverify = get_option( 'mainwp_api_sslVerifyCertificate' );
			if ( $apisslverify == 1 ) {
				MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );
				$return['retry_action'] = 1;
			}
		}
		die( json_encode( $return ) );
	}

	public static function http_request_reject_unsafe_urls( $r, $url ) {
		$r['reject_unsafe_urls'] = false;

		return $r;
	}

	public static function noSSLFilterFunction( $r, $url ) {
		$r['sslverify'] = false;

		return $r;
	}

	public static function noSSLFilterExtensionUpgrade( $r, $url ) {
		if ( ( strpos( $url, 'am_download_file=' ) !== false ) && ( strpos( $url, 'am_email=' ) ) !== false ) {
			$r['sslverify'] = false;
		}

		return $r;
	}

	public static function downloadAndInstall() {
		MainWP_System::Instance()->posthandler->secure_request( 'mainwp_extension_downloadandinstall' );
		$return = self::installPlugin( $_POST['download_link'] );
		die( '<mainwp>' . json_encode( $return ) . '</mainwp>' );
	}

	public static function installPlugin( $url, $activatePlugin = false ) {
		$hasWPFileSystem = MainWP_Utility::getWPFilesystem();
		/** @global WP_Filesystem_Base $wp_filesystem */
		global $wp_filesystem;

		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once( ABSPATH . '/wp-admin/includes/screen.php' );
		}
		include_once( ABSPATH . '/wp-admin/includes/template.php' );
		include_once( ABSPATH . '/wp-admin/includes/misc.php' );
		include_once( ABSPATH . '/wp-admin/includes/class-wp-upgrader.php' );
		include_once( ABSPATH . '/wp-admin/includes/plugin.php' );

		$installer          = new WP_Upgrader();
		$ssl_verifyhost     = get_option( 'mainwp_sslVerifyCertificate' );
		$ssl_api_verifyhost = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) ) ? 1 : 0;

		if ( $ssl_verifyhost === '0' || $ssl_api_verifyhost == 0 ) {
			add_filter( 'http_request_args', array( MainWP_Extensions::getClassName(), 'noSSLFilterFunction' ), 99, 2 );
		}

		add_filter( 'http_request_args', array(
			MainWP_Extensions::getClassName(),
			'http_request_reject_unsafe_urls',
		), 99, 2 );

		$result = $installer->run( array(
			'package'           => $url,
			'destination'       => WP_PLUGIN_DIR,
			'clear_destination' => false, //overwrite files
			'clear_working'     => true,
			'hook_extra'        => array(),
		) );
		remove_filter( 'http_request_args', array(
			MainWP_Extensions::getClassName(),
			'http_request_reject_unsafe_urls',
		), 99, 2 );
		if ( $ssl_verifyhost === '0' ) {
			remove_filter( 'http_request_args', array( MainWP_Extensions::getClassName(), 'noSSLFilterFunction' ), 99 );
		}

		$error = $output = $plugin_slug = null;
		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			if ( $result->get_error_data() && is_string( $result->get_error_data() ) ) {
				$error = $error_code . " - " .$result->get_error_data();
			} else {
				$error = $error_code;
			}
		} else {
			$path = $result['destination'];
			foreach ( $result['source_files'] as $srcFile ) {
				// to fix bug
				if ( $srcFile == 'readme.txt' ) {
					continue;
				}
				$thePlugin = get_plugin_data( $path . $srcFile );
				if ( $thePlugin != null && $thePlugin != '' && $thePlugin['Name'] != '' ) {
					$output .= __( 'Successfully installed the plugin', 'mainwp' ) . ' ' . $thePlugin['Name'] . ' ' . $thePlugin['Version'];
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
			$return['slug']   = $plugin_slug;
		}

		return $return;
	}

	public static function bulkActivate() {
		MainWP_System::Instance()->posthandler->secure_request( 'mainwp_extension_bulk_activate' );
		$plugins = $_POST['plugins'];
		if ( is_array( $plugins ) && count( $plugins ) > 0 ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				activate_plugins( $plugins );
				die( 'SUCCESS' );
			}
		}
		die( 'FAILED' );
	}

	public static function removeExtensionMenuFromMainWPMenu() {
		$snMenuExtensions = get_option( 'mainwp_extmenu' );
		if ( ! is_array( $snMenuExtensions ) ) {
			$snMenuExtensions = array();
		}

		$key = array_search( $_POST['slug'], $snMenuExtensions );

		if ( $key !== false ) {
			unset( $snMenuExtensions[ $key ] );
		}

		MainWP_Utility::update_option( 'mainwp_extmenu', $snMenuExtensions );

		die( json_encode( array( 'result' => 'SUCCESS' ) ) );
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderHeader( $shownPage ) {
		MainWP_Extensions_View::renderHeader( $shownPage, self::$extensions );
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderFooter( $shownPage ) {
		MainWP_Extensions_View::renderFooter( $shownPage );
	}

	public static function render() {
        MainWP_UI::render_left_menu();
		?>
		<div class="mainwp-wrap">
		<h1 class="mainwp-margin-top-0"><i class="fa fa-plug"></i> <?php _e( 'Extensions', 'mainwp' ); ?></h1>

		<?php
		MainWP_Extensions_View::render( self::$extensions );
		echo '</div>';
	}

	public static function isExtensionAvailable( $pAPI ) {
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

	public static function isExtensionEnabled( $pluginFile ) {
		return array( 'key' => md5( $pluginFile . '-SNNonceAdder' ) );
	}

	public static function addedOnMenu( $slug ) {
		$snMenuExtensions = get_option( 'mainwp_extmenu' );
		if ( ! is_array( $snMenuExtensions ) ) {
			$snMenuExtensions = array();
		}
		return in_array( $slug, $snMenuExtensions );
	}

	public static function isExtensionActivated( $plugin_slug )
	{
		$extensions = MainWP_Extensions::getExtensions( array( 'activated' => true ) );
		return isset($extensions[$plugin_slug]) ? true : false;
	}

	public static function create_nonce_function() {
	}

	public static function hookVerify( $pluginFile, $key ) {
		if ( ! function_exists( 'wp_create_nonce' ) ) {
			include_once( ABSPATH . WPINC . '/pluggable.php' );
		}

		return ( ( wp_verify_nonce( $key, $pluginFile . '-SNNonceAdder' ) == 1 ) || ( md5( $pluginFile . '-SNNonceAdder' ) == $key ) );
	}

	public static function hookGetDashboardSites( $pluginFile, $key ) {
		if ( ! self::hookVerify( $pluginFile, $key ) ) {
			return null;
		}

		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::Instance()->getSQLWebsiteById( $current_wpid );
		} else {
			$sql = MainWP_DB::Instance()->getSQLWebsitesForCurrentUser();
		}

		return MainWP_DB::Instance()->query( $sql );
	}

	public static function hookFetchUrlsAuthed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output ) {
		if ( ! self::hookVerify( $pluginFile, $key ) ) {
			return false;
		}

		return MainWP_Utility::fetchUrlsAuthed( $dbwebsites, $what, $params, $handle, $output );
	}

	public static function hookFetchUrlAuthed( $pluginFile, $key, $websiteId, $what, $params ) {
		if ( ! self::hookVerify( $pluginFile, $key ) ) {
			return false;
		}

		try {
			$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );
			if ( ! MainWP_Utility::can_edit_website( $website ) ) {
				throw new MainWP_Exception( 'You can not edit this website.' );
			}

			return MainWP_Utility::fetchUrlAuthed( $website, $what, $params );
		} catch ( MainWP_Exception $e ) {
			return array( 'error' => MainWP_Error_Helper::getErrorMessage($e) );
		}
	}

	//todo: implement correclty: MainWP_DB::Instance()->getWebsiteOption($website, 'premium_upgrades')..
	private static $possible_options = array(
		'plugin_upgrades'  => 'plugin_upgrades',
		'theme_upgrades'   => 'theme_upgrades',
		'premium_upgrades' => 'premium_upgrades',
		'plugins'          => 'plugins',
		'dtsSync'          => 'dtsSync',
		'version'          => 'version',
        'sync_errors'      => 'sync_errors'
	);

	public static function hookGetDBSites( $pluginFile, $key, $sites, $groups, $options = false ) {
		if ( ! self::hookVerify( $pluginFile, $key ) ) {
			return false;
		}

		$dbwebsites = array();
		$data       = array( 'id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey', 'verify_certificate', 'ssl_version' );

		if ( is_array( $options ) ) {
			foreach ( $options as $option_name => $value ) {
				if ( ( $value === true ) && isset( self::$possible_options[ $option_name ] ) ) {
					$data[] = self::$possible_options[ $option_name ];
				}
			}
		}

		if ( $sites != '' ) {
			foreach ( $sites as $k => $v ) {
				if ( MainWP_Utility::ctype_digit( $v ) ) {
					$website                    = MainWP_DB::Instance()->getWebsiteById( $v );
					$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, $data );
				}
			}
		}

		if ( $groups != '' ) {
			foreach ( $groups as $k => $v ) {
				if ( MainWP_Utility::ctype_digit( $v ) ) {
					$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $v ) );
					while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
						$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, $data );
					}
					@MainWP_DB::free_result( $websites );
				}
			}
		}

		return $dbwebsites;
	}

	/**
	 * @param string $pluginFile Extension plugin file to verify
	 * @param string $key The child-key
	 * @param int $websiteid The id of the child-site you wish to retrieve
	 * @param bool $for_manager
	 *
	 * @return array|bool An array of arrays, the inner-array contains the id/url/name/totalsize of the website. False when something goes wrong.
	 */
	public static function hookGetSites( $pluginFile, $key, $websiteid = null, $for_manager = false ) {
		if ( ! self::hookVerify( $pluginFile, $key ) ) {
			return false;
		}

		if ( $for_manager && ( ! defined( 'MWP_TEAMCONTROL_PLUGIN_SLUG' ) || ! mainwp_current_user_can( 'extension', dirname( MWP_TEAMCONTROL_PLUGIN_SLUG ) ) ) ) {
			return false;
		}

		if ( isset( $websiteid ) && ( $websiteid != null ) ) {
			$website = MainWP_DB::Instance()->getWebsiteById( $websiteid );

			if ( ! MainWP_Utility::can_edit_website( $website ) ) {
				return false;
			}

			if ( ! mainwp_current_user_can( 'site', $websiteid ) ) {
				return false;
			}

			return array(
				array(
					'id'        => $websiteid,
					'url'       => MainWP_Utility::getNiceURL( $website->url, true ),
					'name'      => $website->name,
					'totalsize' => $website->totalsize,
				),
			);
		}

		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( false, null, 'wp.url', false, false, null, $for_manager ) );
		$output   = array();
		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			$output[] = array(
				'id'        => $website->id,
				'url'       => MainWP_Utility::getNiceURL( $website->url, true ),
				'name'      => $website->name,
				'totalsize' => $website->totalsize,
			);
		}
		@MainWP_DB::free_result( $websites );

		return $output;
	}

	/**
	 * @param string $pluginFile Extension plugin file to verify
	 * @param string $key The child-key
	 * @param int $groupid The id of the group you wish to retrieve
	 * @param bool $for_manager
	 *
	 * @return array|bool An array of arrays, the inner-array contains the id/name/array of site ids for the supplied groupid/all groups. False when something goes wrong.
	 */
	public static function hookGetGroups( $pluginFile, $key, $groupid, $for_manager = false ) {
		if ( ! self::hookVerify( $pluginFile, $key ) ) {
			return false;
		}

		if ( $for_manager && ( ! defined( 'MWP_TEAMCONTROL_PLUGIN_SLUG' ) || ! mainwp_current_user_can( 'extension', dirname( MWP_TEAMCONTROL_PLUGIN_SLUG ) ) ) ) {
			return false;
		}

		if ( isset( $groupid ) ) {
			$group = MainWP_DB::Instance()->getGroupById( $groupid );
			if ( ! MainWP_Utility::can_edit_group( $group ) ) {
				return false;
			}

			$websites    = MainWP_DB::Instance()->getWebsitesByGroupId( $group->id );
			$websitesOut = array();
			foreach ( $websites as $website ) {
				$websitesOut[] = $website->id;
			}

			return array( array( 'id' => $groupid, 'name' => $group->name, 'websites' => $websitesOut ) );
		}

		$groups = MainWP_DB::Instance()->getGroupsAndCount( null, $for_manager );
		$output = array();
		foreach ( $groups as $group ) {
			$websites    = MainWP_DB::Instance()->getWebsitesByGroupId( $group->id );
			$websitesOut = array();
			foreach ( $websites as $website ) {
				if ( in_array( $website->id, $websitesOut ) ) {
					continue;
				}
				$websitesOut[] = $website->id;
			}
			$output[] = array( 'id' => $group->id, 'name' => $group->name, 'websites' => $websitesOut );
		}

		return $output;
	}

	public static function hookManagerGetExtensions() {
		return get_option( 'mainwp_manager_extensions' );
	}
}
