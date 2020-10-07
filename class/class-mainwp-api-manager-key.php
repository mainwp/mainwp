<?php
/**
 * MainWP API Manager Key handler.
 *
 * This class handles user authentication with MainWP.com License Servers and provides the ability to grab license keys automatically.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MainWP_Api_Manager_Key
 *
 * MainWP Api Manager Key handler
 * This class handles user authentication with MainWP.com License Servers
 * and provides the ability to grab License Keys automatically.
 *
 * @package MainWP API Manager/Key Handler
 * @author Todd Lahman LLC
 * @copyright   Copyright (c) Todd Lahman LLC
 * @since 1.3.0
 */
class MainWP_Api_Manager_Key {

	/**
	 * Set initial $instance value.
	 *
	 * @var null
	 */
	protected static $instance = null;

	/**
	 * Set initial $apisslverify value.
	 *
	 * @var int
	 */
	protected static $apisslverify = 1;

	/**
	 * Create a new Self Instance.
	 *
	 * @return mixed self::$instance
	 */
	public static function instance() {

		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * MainWP_Api_Manager_Key constructor.
	 *
	 * Run any time class is called.
	 * Validate SSL certificate.
	 */
	public function __construct() {
		self::$apisslverify = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) ) ? 1 : 0;
	}

	/**
	 * Activate extension.
	 *
	 * This function checks the users login information & grabs the update URL for the specific extension & activates it.
	 *
	 * @param array $args Extension arguments.
	 *
	 * @return array Request response.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
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
			MainWP_Api_Manager::instance()->get_upgrade_url() . '?mainwp-api=am-software-api',
			array(
				'body'      => $args,
				'timeout'   => 50,
				'sslverify' => self::$apisslverify,
			)
		);

		if ( is_wp_error( $request ) || 200 != wp_remote_retrieve_response_code( $request ) ) {

			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	/**
	 * Deactivate extension .
	 *
	 * This function checks the users login information & grabs the update URL for the specific extension & deactivates it.
	 *
	 * @param array $args Extension arguments.
	 *
	 * @return array Request response.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
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
			MainWP_Api_Manager::instance()->get_upgrade_url() . '?wc-api=am-software-api',
			array(
				'body'      => $args,
				'timeout'   => 50,
				'sslverify' => self::$apisslverify,
			)
		);

		if ( is_wp_error( $request ) || 200 != wp_remote_retrieve_response_code( $request ) ) {
			// Request failed.
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	/**
	 * Grab extension API Key.
	 *
	 * This function checks the users login information & grabs the update URL for the specific extension & returns the API Key.
	 *
	 * @param array $args Extension arguments.
	 *
	 * @return array Request response.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
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
			MainWP_Api_Manager::instance()->get_upgrade_url() . '?mainwp-api=am-software-api',
			array(
				'body'      => $args,
				'timeout'   => 50,
				'sslverify' => self::$apisslverify,
			)
		);

		if ( is_wp_error( $request ) || 200 != wp_remote_retrieve_response_code( $request ) ) {
			// Request failed.
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	/**
	 * Test login API.
	 *
	 * This function checks the users login information & Tests it against the MainWP.com Login Credentials stored on the license server.
	 *
	 * @param aray $args Login arguments.
	 *
	 * @return array Request response.
	 *
	 * @throws \Exception Request error codes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
	 * @uses \MainWP\Dashboard\MainWP_Logger::debug()
	 */
	public function test_login_api( $args ) {

		$defaults = array(
			'request' => 'testloginapi',
		);

		$args             = wp_parse_args( $defaults, $args );
		$args['password'] = stripslashes( $args['password'] );

		$request = wp_remote_post(
			MainWP_Api_Manager::instance()->get_upgrade_url() . '?mainwp-api=am-software-api',
			array(
				'body'      => $args,
				'timeout'   => 50,
				'sslverify' => self::$apisslverify,
			)
		);

		$log = $request;
		if ( is_array( $log ) && isset( $log['http_response'] ) ) {
			unset( $log['http_response'] );
		}

		MainWP_Logger::instance()->debug( 'test_login_api:: RESULT :: ' . MainWP_Utility::value_to_string( $log, true ) );

		if ( is_wp_error( $request ) ) {
			if ( 1 == self::$apisslverify ) {
				MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );

				return array( 'retry_action' => 1 );
			}

			throw new \Exception( $request->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $request );
		if ( 200 != $code ) {
			$error = sprintf( __( 'Login verification could not be completed. Please contact %1$sMainWP Support%2$s so we can assist.', 'mainwp' ), '<a href="https://mainwp.com/my-account/get-support/" target="_blank">', '</a>' );
			throw new \Exception( $error );
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	/**
	 * Get purchased software.
	 *
	 * This function grabs a list of purchased MainWP Extensions that are available for download.
	 *
	 * @param array $args Software Arguments.
	 *
	 * @return array Request response.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
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
			MainWP_Api_Manager::instance()->get_upgrade_url() . '?mainwp-api=am-software-api',
			array(
				'body'      => $args,
				'timeout'   => 50,
				'sslverify' => self::$apisslverify,
			)
		);

		if ( is_wp_error( $request ) || 200 != wp_remote_retrieve_response_code( $request ) ) {
			// Request failed.
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	/**
	 * Purchase software.
	 *
	 * @param array $args Software arguments.
	 *
	 * @return array Request response.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
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
			MainWP_Api_Manager::instance()->get_upgrade_url() . '?mainwp-api=am-software-api',
			array(
				'body'      => $args,
				'timeout'   => 50,
				'sslverify' => self::$apisslverify,
			)
		);

		if ( is_wp_error( $request ) || 200 != wp_remote_retrieve_response_code( $request ) ) {
			// Request failed.
			return false;
		}

		$response = wp_remote_retrieve_body( $request );
		return $response;
	}
}

// Class is instantiated as an object by other classes on-demand.
