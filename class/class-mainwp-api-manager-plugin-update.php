<?php
/**
 * MainWP API Manager Update Handler
 *
 * This class handles updates for MainWP Extension.
 *
 * @package MainWP API Manager/Update Handler
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MainWP_Api_Manager_Plugin_Update
 *
 * MainWP API Manager Update Handler.
 *
 * @package   MainWP API Manager/Update Handler
 * @author    Todd Lahman LLC
 * @copyright Copyright (c) Todd Lahman LLC
 * @since     1.0.0
 */
class MainWP_Api_Manager_Plugin_Update {

	/**
	 * Protected static variable to hold the instance.
	 *
	 * @var null Default value.
	 */
	protected static $instance = null;

	/**
	 * Method instance()
	 *
	 * @static
	 * @return class instance
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * MainWP_Api_Manager_Plugin_Update constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {

		// API data.
	}


	/**
	 * Create upgrade request API URL.
	 *
	 * @param array $args       Request arguments.
	 * @param bool  $bulk_check Bulk check request.
	 *
	 * @return string Build URL.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
	 */
	private function create_upgrade_api_url( $args, $bulk_check = true ) {
		if ( $bulk_check ) {
			$upgrade_url = esc_url_raw( add_query_arg( 'mainwp-api', 'am-software-api', MainWP_Api_Manager::instance()->get_upgrade_url() ) );
		} else {
			$upgrade_url = esc_url_raw( add_query_arg( 'mainwp-api', 'am-software-api', MainWP_Api_Manager::instance()->get_upgrade_url() ) ); // old: wc-api/upgrade-api.
		}

		$query_url = '';
		foreach ( $args as $key => $value ) {
			$query_url .= $key . '=' . rawurlencode( $value ) . '&';
		}
		$query_url = rtrim( $query_url, '&' );

		return $upgrade_url . '&' . $query_url;
	}

	/**
	 * Returns plugin information in an array.
	 *
	 * @param array $plugin Plugin to check.
	 *
	 * @return array Plugin information.
	 */
	public function update_check( $plugin ) {

		$args = array(
			'request'          => 'pluginupdatecheck',
			'plugin_name'      => $plugin['plugin_name'],
			'version'          => $plugin['software_version'],
			'product_id'       => $plugin['product_id'],
			'api_key'          => $plugin['api_key'],
			'instance'         => $plugin['instance'],
			'software_version' => $plugin['software_version'],
			'extra'            => isset( $plugin['extra'] ) ? $plugin['extra'] : '',
		);

		// Check for a plugin update.
		return $this->plugin_information( $args ); // pluginupdatecheck.
	}


	/**
	 * Check if bulkupdateapi is true|false & grab domain name adn extensions list.
	 *
	 * @param array $plugins List of plugins (extensions).
	 *
	 * @return array Plugin Information & bulkupdatecheck.
	 */
	public function bulk_update_check( $plugins ) {

		$args = array(
			'request'    => 'bulkupdatecheck',
			'extensions' => base64_encode( wp_json_encode( $plugins ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			'json'       => true,
		);

		$mainwp_api_key = MainWP_Api_Manager_Key::instance()->get_decrypt_master_api_key();

		if ( ! empty( $mainwp_api_key ) ) {
			$args['api_key'] = $mainwp_api_key;
		}

		return $this->plugin_information( $args, true ); // bulkupdatecheck.
	}


	/**
	 * Check $args, if there is a response, an object exists & response is not false.
	 *
	 * @param array $args Request arguments.
	 *
	 * @return array|false $response Plugin information.
	 */
	public function request( $args ) {
		$args['request'] = 'plugininformation';

		$response = $this->plugin_information( $args ); // plugininformation.

		// If everything is okay return the response.
		if ( isset( $response ) && is_object( $response ) && false !== $response ) {
			return $response;
		}
	}

	/**
	 * Sends and receives data to and from the server API.
	 *
	 * @access      public
	 *
	 * @param array $args       Request arguments.
	 * @param bool  $bulk_check Check if updating in bulk true|false.
	 *
	 * @return array|false Plugin information.
	 * @since       1.0.0
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::maybe_unserialyze()
	 */
	public function plugin_information( $args, $bulk_check = false ) {

		$args['object'] = MainWP_Api_Manager::instance()->get_domain();

		$target_url = $this->create_upgrade_api_url( $args, $bulk_check );
		$default    = array(
			'timeout'   => 150,
			'sslverify' => 1,
		);

		$params = apply_filters( 'mainwp_plugin_information_sslverify', $default, $args );

		$request = wp_remote_get(
			$target_url,
			$params
		);

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) !== 200 ) {
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		if ( isset( $args['json'] ) ) { // bulkupdatecheck: json.
			$response = json_decode( $response, true );
		} else { // pluginupdatecheck, plugininformation : serialize.
			$response = unserialize( $response ); // phpcs:ignore -- data from extensions, to compatible. 
		}

		/**
		 * For debugging errors from the API
		 * For errors like: unserialize(): Error at offset 0 of 170 bytes
		 * Comment out $response above first
		 */
		if ( ! $bulk_check ) {
			if ( is_object( $response ) ) {
				if ( isset( $response->package ) ) {
					$response->package = apply_filters( 'mainwp_api_manager_upgrade_package_url', $response->package, $response );
				}
				return $response;
			}
		} elseif ( is_array( $response ) ) {
			return $response;
		}

		return false;
	}
}

// End of class.
