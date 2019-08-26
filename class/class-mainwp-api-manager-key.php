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
if ( !defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class MainWP_Api_Manager_Key {

	protected static $_instance		 = null;
	protected static $apisslverify	 = 1;

	public static function instance() {

		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		self::$apisslverify = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) ) ? 1 : 0;
	}

	public function activate( $api_params ) {
		$defaults = array(
			'edd_action' => 'activate_license',
		);
		$api_params = wp_parse_args( $defaults, $api_params );
		return wp_remote_post( MainWP_Api_Manager::instance()->get_license_url(), array( 'timeout' => 50, 'sslverify' => self::$apisslverify, 'body' => $api_params ) );
	}

	public function deactivate( $api_params ) {

		$defaults = array(
			'edd_action' => 'deactivate_license',
		);

		$api_params = wp_parse_args( $defaults, $api_params );
        return wp_remote_post( MainWP_Api_Manager::instance()->get_license_url() , array('body' => $api_params, 'timeout' => 50, 'sslverify' => self::$apisslverify ) );
	}

	public function grabapikey( $item_id, $public_key, $token ) {

        $url = MainWP_Api_Manager::instance()->get_api_url();

        $target_url = $url . 'grablicense/';

        $args = array(
            'key' => $public_key,
            'token' => $token,
            'item_id' => $item_id,
            'url' => urlencode( MainWP_Api_Manager::instance()->get_domain() )
        );

        $request = wp_remote_post( $target_url , array('body' => $args, 'timeout' => 50, 'sslverify' => self::$apisslverify ) );

        if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}


    public function testverifyapi( $key, $token ) {

        $url = MainWP_Api_Manager::instance()->get_api_url();

        $target_url = $url . 'testverifyapi/';

        $args = array(
            'key' => $key,
            'token' => $token,
        );

        $request = wp_remote_post( $target_url , array('body' => $args, 'timeout' => 50, 'sslverify' => self::$apisslverify ) );

        $log = $request;

        if ( is_array( $log ) && isset( $log['http_response'] ) ) {
            unset( $log['http_response'] );
		}

		MainWP_Logger::Instance()->debug( 'Test verify api:: RESULT :: ' . print_r( $log, true ) );

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


	public function get_purchasedsoftware( $args ) {

        $url = MainWP_Api_Manager::instance()->get_api_url();

        $target_url = $url . 'getpurchaseddownload/';

        $params = array(
            'key' => $args['public_key'],
            'token' => $args['token'],
            'url'  => urlencode( MainWP_Api_Manager::instance()->get_domain() )
        );

        if ( isset( $args['product_id'] ) ) {
            $params['item_id'] = $args['product_id'];
        }

        $request = wp_remote_post( $target_url , array('body' => $params, 'timeout' => 50, 'sslverify' => self::$apisslverify ) );

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );
		return $response;
	}

	public function purchasesoftware( $item_id, $public_key,  $token) {

		$url = MainWP_Api_Manager::instance()->get_api_url();

        $target_url = $url . 'purchasedownload/';

        $params = array(
            'key' => $public_key,
            'token' => $token,
            'item_id' => $item_id,
            'url'  => urlencode( MainWP_Api_Manager::instance()->get_domain() )
        );

        $request = wp_remote_post( $target_url , array('body' => $params, 'timeout' => 50, 'sslverify' => self::$apisslverify ) );

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );
		return $response;
	}
}

// Class is instantiated as an object by other classes on-demand
