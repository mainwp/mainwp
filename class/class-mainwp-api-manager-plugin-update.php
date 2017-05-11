<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * Todd Lahman LLC Updater - Single Updater Class
 *
 * @package Update API Manager/Update Handler
 * @author Todd Lahman LLC
 * @copyright   Copyright (c) Todd Lahman LLC
 * @since 1.0.0
 *
 */
class MainWP_Api_Manager_Plugin_Update {

	/**
	 * @var The single instance of the class
	 */
	protected static $_instance = null;

	/**
	 * @static
	 * @return class instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		// API data
	}

	// Update API URL
	private function create_upgrade_api_url( $args, $wc_api = true ) {
		if ($wc_api) {
			$upgrade_url = esc_url_raw( add_query_arg( 'wc-api', 'upgrade-api', MainWP_Api_Manager::instance()->getUpgradeUrl() ) );
		} else {
			$upgrade_url = esc_url_raw( add_query_arg( 'mainwp-api', 'am-software-api', MainWP_Api_Manager::instance()->getUpgradeUrl() ) );
		}

		$query_url   = '';
		foreach ( $args as $key => $value ) {
			$query_url .= $key . '=' . urlencode( $value ) . '&';
		}
		$query_url = rtrim( $query_url, '&' );

		return $upgrade_url . '&' . $query_url; //http_build_query( $args );
	}

	public function update_check( $plugin ) {

		$args = array(
			'request'          => 'pluginupdatecheck',
			'plugin_name'      => $plugin['plugin_name'],
			'version'          => $plugin['software_version'],
			'product_id'       => $plugin['product_id'],
			'api_key'          => $plugin['api_key'],
			'activation_email' => $plugin['activation_email'],
			'instance'         => $plugin['instance'],
			'domain'           => $plugin['domain'],
			'software_version' => $plugin['software_version'],
			'extra'            => isset( $plugin['extra'] ) ? $plugin['extra'] : '',
		);

		// Check for a plugin update
		return $this->plugin_information( $args );
	}

    public function bulk_update_check( $plugins ) {
        $args = array(
                'request'          => 'bulkupdatecheck',
                'domain'           => MainWP_Api_Manager::instance()->get_domain(),
                'extensions'       => base64_encode( serialize( $plugins ) ),
        );
        return $this->plugin_information( $args , true );
    }

	public function request( $args ) {
		$args['request'] = 'plugininformation';

		$response = $this->plugin_information( $args );

		// If everything is okay return the $response
		if ( isset( $response ) && is_object( $response ) && $response !== false ) {
			return $response;
		}
	}

	/**
	 * Sends and receives data to and from the server API
	 *
	 * @access public
	 * @since  1.0.0
	 * @return object $response
	 */
	public function plugin_information( $args, $bulk_check = false ) {
                $wc_api = $bulk_check ? false : true;
		$target_url = $this->create_upgrade_api_url( $args, $wc_api );
		$apisslverify = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) ) ? 1 : 0;
		$request = wp_remote_get( $target_url, array( 'timeout' => 50, 'sslverify' => $apisslverify ) );

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			return false;
		}

        if ($bulk_check) {
            $response = wp_remote_retrieve_body( $request );
            $response = unserialize( base64_decode( $response ) );
        } else {
			$response = unserialize( wp_remote_retrieve_body( $request ) );
        }

		/**
		 * For debugging errors from the API
		 * For errors like: unserialize(): Error at offset 0 of 170 bytes
		 * Comment out $response above first
		 */
        if (!$bulk_check) {
            if ( is_object( $response ) ) {
                    if ( isset( $response->package ) ) {
                            $response->package = apply_filters( 'mainwp_api_manager_upgrade_url', $response->package );
                    }
                    return $response;
            }
        } else if ( is_array( $response ) ) {
            return $response;
        }

        return false;
	}

} // End of class
