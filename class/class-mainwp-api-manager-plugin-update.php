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
 * MainWP API Manager Update Handler
 *
 * @package   MainWP API Manager/Update Handler
 * @author    Todd Lahman LLC
 * @copyright Copyright (c) Todd Lahman LLC
 * @since     1.0.0
 */
class MainWP_Api_Manager_Plugin_Update {

	/**
	 * Protected static varibale to hold the instance.
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
	 */
	private function create_upgrade_api_url( $args, $bulk_check = true ) {
		if ( $bulk_check ) {
			$upgrade_url = esc_url_raw( add_query_arg( 'mainwp-api', 'am-software-api', MainWP_Api_Manager::instance()->get_upgrade_url() ) );
		} else {
			$upgrade_url = esc_url_raw( add_query_arg( 'wc-api', 'upgrade-api', MainWP_Api_Manager::instance()->get_upgrade_url() ) );
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
			'request'            => 'pluginupdatecheck',
			'plugin_name'        => $plugin['plugin_name'],
			'version'            => $plugin['software_version'],
			'product_id'         => $plugin['product_id'],
			'api_key'            => $plugin['api_key'],
			'activation_email'   => $plugin['activation_email'],
			'instance'           => $plugin['instance'],
			'domain'             => $plugin['domain'],
			'software_version'   => $plugin['software_version'],
			'extra'              => isset( $plugin['extra'] ) ? $plugin['extra'] : '',
		);

		// Check for a plugin update.
		return $this->plugin_information( $args );
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
			'domain'     => MainWP_Api_Manager::instance()->get_domain(),
			'extensions' => base64_encode( serialize( $plugins ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		);
		return $this->plugin_information( $args, true ); // bulk update check.
	}


	/**
	 * Check $args, if there is a response, an object eists & response is not false.
	 *
	 * @param array $args Request arguments.
	 *
	 * @return object $response Plugin information.
	 */
	public function request( $args ) {
		$args['request'] = 'plugininformation';

		$response = $this->plugin_information( $args );

		// If everything is okay return the response.
		if ( isset( $response ) && is_object( $response ) && false !== $response ) {
			return $response;
		}
	}

	/**
	 * Sends and receives data to and from the server API.
	 *
	 * @access public
	 *
	 * @since       1.0.0
	 *
	 * @param array $args       Request arguments.
	 * @param bool  $bulk_check Check if updating in bulk true|false.
	 *
	 * @return object Plugin information.
	 */
	public function plugin_information( $args, $bulk_check = false ) {
		$target_url   = $this->create_upgrade_api_url( $args, $bulk_check );
		$apisslverify = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) ) ? 1 : 0;
		$request      = wp_remote_get(
			$target_url,
			array(
				'timeout'   => 50,
				'sslverify' => $apisslverify,
			)
		);

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			return false;
		}

		if ( $bulk_check ) {
			$response = wp_remote_retrieve_body( $request );
			$response = MainWP_System_Utility::maybe_unserialyze( $response );
		} else {
			$response = MainWP_System_Utility::maybe_unserialyze( wp_remote_retrieve_body( $request ) );
		}

		/**
		 * For debugging errors from the API
		 * For errors like: unserialize(): Error at offset 0 of 170 bytes
		 * Comment out $response above first
		 */
		if ( ! $bulk_check ) {
			if ( is_object( $response ) ) {
				if ( isset( $response->package ) ) {
					$response->package = apply_filters( 'mainwp_api_manager_upgrade_url', $response->package );
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
