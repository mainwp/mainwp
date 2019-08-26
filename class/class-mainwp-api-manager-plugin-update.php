<?php

if ( !defined( 'ABSPATH' ) ) {
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


	public function update_check( $data ) {

        $api_params = array(
			'edd_action' => 'mainwp_get_version',
			'license'    => ! empty( $data['license'] ) ? $data['license'] : '',
			'item_id'  => isset( $data['item_id'] ) ? $data['item_id'] : false,
			'version'    => isset( $data['version'] ) ? $data['version'] : false,
			'slug'       => $data['slug'],
			'author'     => 'MainWP',
			'url'        => MainWP_Api_Manager::instance()->get_domain(),
			'beta'       => ! empty( $data['beta'] ),
		);

        $apisslverify	 = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) ) ? 1 : 0;

		$request    = wp_remote_post( MainWP_Api_Manager::instance()->get_license_url(), array( 'timeout' => 50, 'sslverify' => $apisslverify, 'body' => $api_params ) );

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			return false;
		}

        $response = json_decode( wp_remote_retrieve_body( $request ) );

        if ( $response && isset( $response->sections ) ) {
            $response->sections = maybe_unserialize( $response->sections );
        } else {
            $response = false;
        }

        if ( $response && isset( $response->banners ) ) {
            $response->banners = maybe_unserialize( $response->banners );
        }

        if ( $response && isset( $response->icons ) ) {
            $response->icons = maybe_unserialize( $response->icons );
        }

        if( ! empty( $response->sections ) ) {
            foreach( $response->sections as $key => $section ) {
                $response->$key = (array) $section;
            }
        }

        if ( is_object( $response ) ) {
            if ( isset( $response->package ) ) {
                $response->package = apply_filters( 'mainwp_api_manager_upgrade_url', $response->package );
            }
            return $response;
        }

		return false;

	}

	public function bulk_update_check( $plugins, $public_key, $token ) {

        $url = MainWP_Api_Manager::instance()->get_api_url();

        $target_url = $url . 'bulkgetversions/';

        $args = array(
            'key' => $public_key,
            'token' => $token,
            'items' => base64_encode( serialize( $plugins ) ),
        );

        $apisslverify	 = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) ) ? 1 : 0;

        $request = wp_remote_post( $target_url , array('body' => $args, 'timeout' => 50, 'sslverify' => $apisslverify ) );

        $log = $request;

        if ( is_array( $log ) && isset( $log['http_response'] ) ) {
            unset( $log['http_response'] );
		}

		MainWP_Logger::Instance()->debug( 'Test verify api:: RESULT :: ' . print_r( $log, true ) );

		if ( is_wp_error( $request ) ) {
			if ( $apisslverify == 1 ) {
				MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );
			}
			return false;
		}

		$code = wp_remote_retrieve_response_code( $request );

		if ( $code != 200 ) {
			return false;
		}

		$response = wp_remote_retrieve_body( $request );
        $response	 = unserialize( base64_decode( $response ) );

        if ( is_array( $response ) ) {
            return $response;
        }

		return false;

	}

}

// End of class
