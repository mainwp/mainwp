<?php
/**
 * MainWP Extensions Page Handler
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Extensions Page Handler
 */
class MainWP_Extensions_Handler {

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

	/**
	 * @var array Possible options.
	 */
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


	/**
	 * Get Plugin Slug.
	 *
	 * @param mixed $pSlug Extension Slug.
	 *
	 * @return string Extensions Slug.
	 */
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

	/**
	 * Get all extension slugs.
	 *
	 * @return array slugs.
	 * @return array am_slugs.
	 */
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

	/**
	 * Clean up MainWP Extention names.
	 *
	 * @param array $extension Array of MainWP Extentsions.
	 *
	 * @return string $menu_name Final Menu Name.
	 */
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

	/** Load MainWP Extensions. */
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

	/**
	 * Get MainWP Extensions array.
	 *
	 * @param array $args Empty Array.
	 *
	 * @return array Array of Extensions.
	 */
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

	/**
	 * Generate API Password.
	 *
	 * @param integer $length Lenght of password.
	 * @param boolean $special_chars true|false, allow special characters.
	 * @param boolean $extra_special_chars true|false, allow extra special characters.
	 *
	 * @return MainWP_Api_Manager_Password_Management::generate_password()
	 */
	public static function gen_api_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		MainWP_Deprecated_Hooks::maybe_handle_deprecated_filter();
		return MainWP_Api_Manager_Password_Management::generate_password( $length, $special_chars, $extra_special_chars );
	}


	/**
	 * Add Extension Menu.
	 *
	 * @param mixed $slug Extension slug.
	 *
	 * @return boolean true|false.
	 */
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

	/**
	 * HTTP Request Reject Unsafe Urls.
	 *
	 * @param boolean $r Results.
	 * @param mixed   $url Upgrade Extension URL.
	 *
	 * @return mixed false|$r.
	 */
	public static function http_request_reject_unsafe_urls( $r, $url ) {
		$r['reject_unsafe_urls'] = false;

		return $r;
	}

	/**
	 * No SSL Filter Function.
	 *
	 * @param boolean $r Results.
	 * @param mixed   $url Upgrade Extension URL.
	 *
	 * @return mixed false|$r.
	 */
	public static function no_ssl_filter_function( $r, $url ) {
		$r['sslverify'] = false;

		return $r;
	}

	/**
	 * No SSL Filter Extention Upgrade.
	 *
	 * @param boolean $r Results.
	 * @param mixed   $url Upgrade Extension URL.
	 *
	 * @return mixed false|$r.
	 */
	public static function no_ssl_filter_extension_upgrade( $r, $url ) {
		if ( ( false !== strpos( $url, 'am_download_file=' ) ) && ( false !== strpos( $url, 'am_email=' ) ) ) {
			$r['sslverify'] = false;
		}

		return $r;
	}

	/** Activate MainWP Extension License.  */
	public static function activate_license() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_extension_activatelicense' );
		$item_id  = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
		$response = MainWP_Api_Manager::instance()->grab_license_key_by_id( $item_id );
		die( wp_json_encode( $response ) );
	}

	/**
	 * Install MainWP Extension.
	 *
	 * @param mixed   $url MainWP Extension update URL.
	 * @param boolean $activatePlugin true|false Whether or not to activate extension.
	 *
	 * @return mixed $return
	 */
	public static function install_plugin( $url, $activatePlugin = false ) {

		$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

		global $wp_filesystem;

		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/screen.php';
		}

		include_once ABSPATH . '/wp-admin/includes/template.php';
		include_once ABSPATH . '/wp-admin/includes/misc.php';
		include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . '/wp-admin/includes/plugin.php';

		$installer          = new \WP_Upgrader();
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

	/**
	 * Check if MainWP Extension is available.
	 *
	 * @param mixed $pAPI MainWP Extension API Key.
	 *
	 * @return boolean true|false.
	 */
	public static function is_extension_available( $pAPI ) {

		MainWP_Deprecated_Hooks::maybe_handle_deprecated_filter();

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

	/**
	 * Check if MainWP Extension is enabled.
	 *
	 * @param mixed $pluginFile MainWP Extension to bo verified.
	 *
	 * @return array 'key' => md5( $pluginFile . '-SNNonceAdder').
	 */
	public static function is_extension_enabled( $pluginFile ) {
		return array( 'key' => md5( $pluginFile . '-SNNonceAdder' ) );
	}

	/**
	 * Create Menu Extension Array.
	 *
	 * @param mixed $slug menu slug.
	 *
	 * @return array Menu Array.
	 */
	public static function added_on_menu( $slug ) {
		$snMenuExtensions = get_option( 'mainwp_extmenu' );
		if ( ! is_array( $snMenuExtensions ) ) {
			$snMenuExtensions = array();
		}
		return in_array( $slug, $snMenuExtensions );
	}

	/**
	 * Check if MainWP Extension is activated or not.
	 *
	 * @param mixed $plugin_slug MainWP Extension slug.
	 *
	 * @return boolean true|false.
	 */
	public static function is_extension_activated( $plugin_slug ) {
		$extensions = self::get_extensions( array( 'activated' => true ) );
		return isset( $extensions[ $plugin_slug ] ) ? true : false;
	}

	/**
	 * Verify MainWP Extension.
	 *
	 * @param mixed $pluginFile MainWP Extensoin to verify.
	 * @param mixed $key Child Site Key.
	 *
	 * @return mixed md5( $pluginFile . '-SNNonceAdder' ) == $key
	 */
	public static function hook_verify( $pluginFile, $key ) {
		return ( md5( $pluginFile . '-SNNonceAdder' ) == $key );
	}

	/**
	 * Get sql websites for current user.
	 *
	 * @param mixed $pluginFile Extension plugin file to verify
	 * @param mixed $key PThe child-key.
	 *
	 * @return mixed null|sql query.
	 */
	public static function hook_get_dashboard_sites( $pluginFile, $key ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return null;
		}

		$current_wpid = MainWP_System_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid );
		} else {
			$sql = MainWP_DB::instance()->get_sql_websites_for_current_user();
		}

		return MainWP_DB::instance()->query( $sql );
	}

	/**
	 * Fetch Authorized URLS.
	 *
	 * @param mixed $pluginFile Extension plugin file to verify
	 * @param mixed $key The child-key.
	 * @param mixed $dbwebsites
	 * @param mixed $what
	 * @param mixed $params
	 * @param mixed $handle
	 * @param mixed $output
	 *
	 * @uses MainWP_Utility::fetch_urls_authed()
	 *
	 * @return mixed false|MainWP_Utility::fetch_urls_authed
	 */
	public static function hook_fetch_urls_authed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		return MainWP_Connect::fetch_urls_authed( $dbwebsites, $what, $params, $handle, $output );
	}

	/**
	 * Fetch Authorized URL.
	 *
	 * @throws MainWP_Exception On incorrect website
	 * @param mixed $pluginFile Extension plugin file to verify
	 * @param mixed $key The child-key.
	 * @param mixed $websiteId Child Site ID.
	 * @param mixed $what What.
	 * @param mixed $params Parameters.
	 * @param null  $rawResponse Raw responce.
	 *
	 * @return mixed false|throw|error
	 */
	public static function hook_fetch_url_authed( $pluginFile, $key, $websiteId, $what, $params, $rawResponse = null ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		try {
			$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
			if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
				throw new MainWP_Exception( 'You can not edit this website.' );
			}

			return MainWP_Connect::fetch_url_authed( $website, $what, $params, $checkConstraints = false, $pForceFetch = false, $pRetryFailed = true, $rawResponse );
		} catch ( MainWP_Exception $e ) {
			return array( 'error' => MainWP_Error_Helper::get_error_message( $e ) );
		}
	}

	/**
	 * Get DB Sites.
	 *
	 * @param mixed   $pluginFile Extension plugin file to verify
	 * @param mixed   $key The child-key.
	 * @param mixed   $sites Child Sites.
	 * @param string  $groups Groups.
	 * @param boolean $options Options.
	 *
	 * @return array $dbwebsites.
	 */
	public static function hook_get_db_sites( $pluginFile, $key, $sites, $groups = '', $options = false ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		MainWP_Deprecated_Hooks::maybe_handle_deprecated_filter();

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
	 * Get Sites.
	 *
	 * @param string  $pluginFile Extension plugin file to verify.
	 * @param string  $key The child-key.
	 * @param int     $websiteid The id of the child site you wish to retrieve.
	 * @param boolean $for_manager Check Team Control.
	 * @param array   $others Array of others.
	 *
	 * @return array $output Array of content to output.
	 */
	public static function hook_get_sites( $pluginFile, $key, $websiteid = null, $for_manager = false, $others = array() ) { // phpcs:ignore -- not quite complex function.
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		if ( $for_manager && ( ! defined( 'MWP_TEAMCONTROL_PLUGIN_SLUG' ) || ! mainwp_current_user_have_right( 'extension', dirname( MWP_TEAMCONTROL_PLUGIN_SLUG ) ) ) ) {
			return false;
		}

		MainWP_Deprecated_Hooks::maybe_handle_deprecated_filter();

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

			if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
				return false;
			}

			if ( ! mainwp_current_user_have_right( 'site', $websiteid ) ) {
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
	 * Method hook_get_groups()
	 *
	 * Get Child Sites within groups & store them in an array.
	 *
	 * @param string  $pluginFile Extension plugin file to verify.
	 * @param string  $key The child-key.
	 * @param int     $groupid The id of the group you wish to retrieve.
	 * @param boolean $for_manager Check Team Control.
	 *
	 * @return array|bool $output|false An array of arrays, the inner-array contains the id/name/array of site ids for the supplied groupid/all groups. False when something goes wrong.
	 */
	public static function hook_get_groups( $pluginFile, $key, $groupid, $for_manager = false ) {
		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		if ( $for_manager && ( ! defined( 'MWP_TEAMCONTROL_PLUGIN_SLUG' ) || ! mainwp_current_user_have_right( 'extension', dirname( MWP_TEAMCONTROL_PLUGIN_SLUG ) ) ) ) {
			return false;
		}

		MainWP_Deprecated_Hooks::maybe_handle_deprecated_filter();

		if ( isset( $groupid ) ) {
			$group = MainWP_DB_Common::instance()->get_group_by_id( $groupid );
			if ( empty( $group ) ) {
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

		$groups = MainWP_DB_Common::instance()->get_groups_and_count( null, $for_manager );
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

	/**
	 * Get Manager extensions.
	 *
	 * @return void
	 */
	public static function hook_manager_get_extensions() {
		MainWP_Deprecated_Hooks::maybe_handle_deprecated_filter();
		return get_option( 'mainwp_manager_extensions' );
	}

	/**
	 * Clone Site.
	 *
	 * @param mixed   $pluginFile Extension plugin file to verify
	 * @param mixed   $key The child-key.
	 * @param mixed   $websiteid Child Site ID.
	 * @param mixed   $cloneID Clone ID.
	 * @param mixed   $clone_url URL to CLone to.
	 * @param boolean $force_update true|false, force an update.
	 *
	 * @return mixed false|$ret
	 */
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
					if ( MainWP_System_Utility::can_edit_website( $website ) ) {
						MainWP_Sync::sync_site( $website, false, false );
						$group = MainWP_DB_Common::instance()->get_group_by_id( $group_id );
						if ( ! empty( $group ) ) {
							MainWP_DB_Common::instance()->update_group_site( $group->id, $id );
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

	/**
	 * Delete Clones Site.
	 *
	 * @param mixed   $pluginFile Extension plugin file to verify.
	 * @param mixed   $key The child-key.
	 * @param mixed   $clone_url URL to Clone to.
	 * @param boolean $clone_site_id Cloned Site ID.
	 *
	 * @return mixed false|array Array => "Success".
	 */
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

			$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

			global $wp_filesystem;

			$favi = MainWP_DB::instance()->get_website_option( $clone_site, 'favi_icon', '' );
			if ( ! empty( $favi ) && ( false !== strpos( $favi, 'favi-' . $clone_site->id . '-' ) ) ) {
				$dirs = MainWP_System_Utility::get_icons_dir();
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

	/**
	 * Add Groups.
	 *
	 * @param mixed $pluginFile  Extension plugin file to verify
	 * @param mixed $key The child-key.
	 * @param mixed $newName Name that you want to give the group.
	 *
	 * @return mixed false|$groupId
	 */
	public static function hook_add_group( $pluginFile, $key, $newName ) {

		if ( ! self::hook_verify( $pluginFile, $key ) ) {
			return false;
		}

		global $current_user;
		if ( ! empty( $newName ) ) {
			$groupId = MainWP_DB_Common::instance()->add_group( $current_user->ID, MainWP_Manage_Groups::check_group_name( $newName ) );
			do_action( 'mainwp_added_new_group', $groupId );
			return $groupId;
		}
		return false;
	}
}
