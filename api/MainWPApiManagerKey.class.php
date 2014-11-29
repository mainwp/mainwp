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

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class MainWPApiManagerKey {
    protected static $_instance = null;
    
        public static function instance() {

            if ( is_null( self::$_instance ) ) {
                    self::$_instance = new self();
            }

            return self::$_instance;
        }
        
	// API Key URL
	public function create_software_api_url( $args ) {

		$api_url = add_query_arg( 'wc-api', 'am-software-api', MainWPApiManager::instance()->upgrade_url);

		return $api_url . '&' . http_build_query( $args );
	}

	public function activate( $args ) {

		$defaults = array(
			'request' 			=> 'softwareactivation'									
                    );

		$args = wp_parse_args( $defaults, $args );

		$target_url = self::create_software_api_url( $args );
                
		$request = wp_remote_get( $target_url );

		if( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
		// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	public function deactivate( $args ) {

		$defaults = array(
			'request' 		=> 'deactivation'						
			);

		$args = wp_parse_args( $defaults, $args );

		$target_url = self::create_software_api_url( $args );

		$request = wp_remote_get( $target_url );


		if( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
		// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );                
		return $response;
	}

        public function grabapikey( $args ) {

		$defaults = array(
			'request' 			=> 'grabapikey'								
                    );

		$args = wp_parse_args( $defaults, $args );

		//$target_url = self::create_software_api_url( $args );                
		//$request = wp_remote_get( $target_url );
                
                $request = wp_remote_post( MainWPApiManager::instance()->upgrade_url . 'wc-api/am-software-api/', array( 'body' => $args ) );

		if( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
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

		//$target_url = self::create_software_api_url( $args );                
		//$request = wp_remote_get( $target_url );
                
                $request = wp_remote_post( MainWPApiManager::instance()->upgrade_url . 'wc-api/am-software-api/', array( 'body' => $args ) );

		if( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
		// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}
        
        
        public function getpurchasedsoftware( $args ) {

		$defaults = array(
			'request' 			=> 'getpurchasedsoftware',									
                    );

		$args = wp_parse_args( $defaults, $args );

		//$target_url = self::create_software_api_url( $args );                
		//$request = wp_remote_get( $target_url );
                
                $request = wp_remote_post( MainWPApiManager::instance()->upgrade_url . 'wc-api/am-software-api/', array( 'body' => $args ) );

		if( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
		// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}
      
}

// Class is instantiated as an object by other classes on-demand
