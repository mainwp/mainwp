<?php

/**
 * WooCommerce API Manager API Key Class
 *
 * @package Update API Manager/Key Handler
 * @author Todd Lahman LLC
 * @copyright   Copyright (c) Todd Lahman LLC
 * @since 1.3
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class MainWP_Api_Manager_Key {
	protected static $_instance = null;

	protected static $apisslverify = 1;

	public static function instance() {

		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		self::$apisslverify = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) ) ? 1 : 0;
	}

	// API Key URL
	public function create_software_api_url( $args, $wc_api = false ) {
		if ($wc_api)
			$api_url = esc_url_raw( add_query_arg( 'wc-api', 'am-software-api', MainWP_Api_Manager::instance()->getUpgradeUrl() ) );
		else
			$api_url = esc_url_raw( add_query_arg( 'mainwp-api', 'am-software-api', MainWP_Api_Manager::instance()->getUpgradeUrl() ) );

		$query_url = '';
		foreach ( $args as $key => $value ) {
			$query_url .= $key . '=' . urlencode( $value ) . '&';
		}
		$query_url = rtrim( $query_url, '&' );

		return $api_url . '&' . $query_url;
	}

	public function activate( $args ) {

		$defaults = array(
			'request' => 'softwareactivation',
		);

		$args = wp_parse_args( $defaults, $args );

		$target_url = self::create_software_api_url( $args );

		$request = wp_remote_get( $target_url, array( 'timeout' => 50, 'sslverify' => self::$apisslverify ) );

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	public function deactivate( $args ) {

		$defaults = array(
			'request' => 'deactivation',
		);

		$args = wp_parse_args( $defaults, $args );

		$target_url = self::create_software_api_url( $args, true );
		$request    = wp_remote_get( $target_url, array( 'timeout' => 50, 'sslverify' => self::$apisslverify ) );

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	public function grabapikey( $args ) {

		$defaults = array(
			'request' => 'grabapikey',
		);

		$args = wp_parse_args( $defaults, $args );

		$target_url = self::create_software_api_url( $args );
		$request    = wp_remote_get( $target_url, array( 'timeout' => 50, 'sslverify' => self::$apisslverify ) );

		//                $request = wp_remote_post( MainWP_Api_Manager::instance()->getUpgradeUrl() . 'wc-api/am-software-api/', array('body' => $args) );

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	public function testloginapi( $args ) {

		$defaults = array(
			'request' => 'testloginapi',
		);

		$args = wp_parse_args( $defaults, $args );

		$target_url = self::create_software_api_url( $args );
		$request    = wp_remote_get( $target_url, array( 'timeout' => 50, 'sslverify' => self::$apisslverify ) );

        $log = $request;
        if ( is_array( $log ) && isset( $log['http_response'] ) ) {
            unset( $log['http_response'] );
        }

        MainWP_Logger::Instance()->debug( 'testloginapi:: RESULT :: ' . print_r( $log, true ) );

		//                $request = wp_remote_post( MainWP_Api_Manager::instance()->getUpgradeUrl() . 'wc-api/am-software-api/', array('body' => $args) );

		if ( is_wp_error( $request ) ) {
			if ( self::$apisslverify == 1 ) {
				MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );

				return array( 'retry_action' => 1 );
			}

			throw new Exception( $request->get_error_message() );

			return false;
		}

		$code = wp_remote_retrieve_response_code( $request );
		if ( $code != 200 ) {
			throw new Exception( 'Error: code ' . $code );

			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}


	public function getpurchasedsoftware( $args ) {

		$defaults = array(
			'request' => 'getpurchasedsoftware',
		);

		$args = wp_parse_args( $defaults, $args );

		$target_url = self::create_software_api_url( $args );
		$request    = wp_remote_get( $target_url, array( 'timeout' => 50, 'sslverify' => self::$apisslverify ) );

		//                $request = wp_remote_post( MainWP_Api_Manager::instance()->getUpgradeUrl() . 'wc-api/am-software-api/', array('body' => $args) );

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	public function purchasesoftware( $args ) {
		$defaults = array(
			'request' => 'purchasesoftware',
		);
		$args = wp_parse_args( $defaults, $args );
		$target_url = self::create_software_api_url( $args );
		$request = wp_remote_get( $target_url, array('timeout' => 50, 'sslverify' => self::$apisslverify));

		if( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );
		return $response;
	}
}

// Class is instantiated as an object by other classes on-demand
