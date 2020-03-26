<?php
/**
 * MainWP Api Manager Key Handsler.
 *
 * This class handles user authentication with MainWP.com License Servers
 * and provides the ability to grab license keys automatically.
 */
namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MainWP Api Manager Key Handler.
 *
 * This class handles user authentication with MainWP.com License Servers
 * and providfes the ability to grab License Keys automatically.
 *
 * @package MainWP API Manager/Key Handler
 * @author Todd Lahman LLC
 * @copyright   Copyright (c) Todd Lahman LLC
 * @since 1.3.0
 */
class MainWP_Api_Manager_Key {

	/**
	 * $_instance
	 *
	 * Set initial $_instance value.
	 *
	 * @var null
	 */
	protected static $_instance = null;

	/**
	 * $apisslverify
	 *
	 * Set initial $apisslverify value.
	 *
	 * @var integer
	 */
	protected static $apisslverify = 1;

	/**
	 * Instance
	 *
	 * Create a new Self Instance.
	 *
	 * @return mixed self::$_instance
	 */
	public static function instance() {

		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * __construct
	 *
	 * Validate SSL Certificate.
	 *
	 * @return bool 1|0
	 */
	public function __construct() {
		self::$apisslverify = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) ) ? 1 : 0;
	}

	/**
	 * Extension Activate
	 *
	 * This function checks the users login information & grabs the update URL
	 * for the specific extension & activates it.
	 *
	 * @param mixed $args Extension Arguments.
	 * @return mixed $response
	 */
	public function activate( $args ) {

		$defaults = array(
			'request' => 'softwareactivation',
		);

		$args = wp_parse_args( $defaults, $args );

		if ( isset( $args['password'] ) ) {
			$args['password'] = stripslashes( $args['password'] );
		}

		$request = wp_remote_post(
			MainWP_Api_Manager::instance()->getUpgradeUrl() . '?mainwp-api=am-software-api', array(
				'body'      => $args,
				'timeout'   => 50,
				'sslverify' => self::$apisslverify,
			)
		);

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {

			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	/**
	 * Extension Deactivate
	 *
	 * This function checks the users login information & grabs the update URL
	 * for the specific extension & deactivates it.
	 *
	 * @param mixed $args Extension Arguments.
	 * @return mixed $response
	 */
	public function deactivate( $args ) {

		$defaults = array(
			'request' => 'deactivation',
		);

		$args = wp_parse_args( $defaults, $args );

		if ( isset( $args['password'] ) ) {
			$args['password'] = stripslashes( $args['password'] );
		}
		$request = wp_remote_post(
			MainWP_Api_Manager::instance()->getUpgradeUrl() . '?wc-api=am-software-api', array(
				'body'      => $args,
				'timeout'   => 50,
				'sslverify' => self::$apisslverify,
			)
		);

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	/**
	 * Grab extention API Key
	 *
	 * This function checks the users login information & grabs the update URL
	 * for the specific extension & returns the API Key.
	 *
	 * @param mixed $args Extension Arguments.
	 * @return mixed $response
	 */
	public function grab_api_key( $args ) {

		$defaults = array(
			'request' => 'grabapikey',
		);

		$args = wp_parse_args( $defaults, $args );

		if ( isset( $args['password'] ) ) {
			$args['password'] = stripslashes( $args['password'] );
		}
		$request = wp_remote_post(
			MainWP_Api_Manager::instance()->getUpgradeUrl() . '?mainwp-api=am-software-api', array(
				'body'      => $args,
				'timeout'   => 50,
				'sslverify' => self::$apisslverify,
			)
		);

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	/**
	 * Test Login API
	 *
	 * This function checks the users login information & Tests
	 * it against the MainWP.com Login Credentials stored on the license server.
	 *
	 * @param mixed $args Login Arguments.
	 * @throws mixed Request error messages.
	 * @throws mixed Request error codes.
	 * @return mixed $response
	 */
	public function test_login_api( $args ) {

		$defaults = array(
			'request' => 'testloginapi',
		);

		$args             = wp_parse_args( $defaults, $args );
		$args['password'] = stripslashes( $args['password'] );

		$request = wp_remote_post(
			MainWP_Api_Manager::instance()->getUpgradeUrl() . '?mainwp-api=am-software-api', array(
				'body'      => $args,
				'timeout'   => 50,
				'sslverify' => self::$apisslverify,
			)
		);

		$log = $request;
		if ( is_array( $log ) && isset( $log['http_response'] ) ) {
			unset( $log['http_response'] );
		}

		MainWP_Logger::instance()->debug( 'test_login_api:: RESULT :: ' . print_r( $log, true ) );

		if ( is_wp_error( $request ) ) {
			if ( self::$apisslverify == 1 ) {
				MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );

				return array( 'retry_action' => 1 );
			}

			throw new \Exception( $request->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $request );
		if ( $code != 200 ) {
			throw new \Exception( 'Error: code ' . $code );
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	/**
	 * Get Purchased Software
	 *
	 * This function grabs a list of purchased MainWP Extensions
	 * that are available for download.
	 *
	 * @param mixed $args Software Arguments.
	 * @return mixed $response
	 */
	public function get_purchased_software( $args ) {

		$defaults = array(
			'request' => 'getpurchasedsoftware',
		);

		$args = wp_parse_args( $defaults, $args );

		if ( isset( $args['password'] ) ) {
			$args['password'] = stripslashes( $args['password'] );
		}
		$request = wp_remote_post(
			MainWP_Api_Manager::instance()->getUpgradeUrl() . '?mainwp-api=am-software-api', array(
				'body'      => $args,
				'timeout'   => 50,
				'sslverify' => self::$apisslverify,
			)
		);

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	/**
	 * Purchase Software
	 *
	 * @param mixed $args Software Arguments.
	 * @return mixed $response
	 */
	public function purchase_software( $args ) {
		$defaults = array(
			'request' => 'purchasesoftware',
		);
		$args     = wp_parse_args( $defaults, $args );

		if ( isset( $args['password'] ) ) {
			$args['password'] = stripslashes( $args['password'] );
		}
		$request = wp_remote_post(
			MainWP_Api_Manager::instance()->getUpgradeUrl() . '?mainwp-api=am-software-api', array(
				'body'      => $args,
				'timeout'   => 50,
				'sslverify' => self::$apisslverify,
			)
		);

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );
		return $response;
	}
}

// Class is instantiated as an object by other classes on-demand.
