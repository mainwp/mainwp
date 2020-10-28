<?php
/**
 * MainWP REST API
 *
 * This class handles the REST API
 *
 * @package MainWP/REST API
 * @author Martin Gibson
 */

namespace MainWP\Dashboard;

/**
 * Class Rest_Api
 *
 * @package MainWP\Dashboard
 */
class Rest_Api {

	/**
	 * Protected static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Method instance()
	 *
	 * Create public static instance.
	 *
	 * @static
	 * @return self::$instance
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Method init()
	 *
	 * Adds an action to generate API credentials.
	 * Adds an action to create the rest API endpoints if activated in the plugin settings.
	 */
	public function init() {

		// add ajax action.
		add_action( 'wp_ajax_mainwp_generate_api_credentials', array( &$this, 'mainwp_generate_api_credentials' ) );

		// only activate the api if enabled in the plugin settings.
		if ( get_option( 'mainwp_enable_rest_api' ) ) {
			// check to see whether activated or not.
			$activated = get_option( 'mainwp_enable_rest_api' );

			if ( $activated ) {
				// run API.
				add_action( 'rest_api_init', array( &$this, 'mainwp_register_routes' ) );
			}
		}
	}

	/**
	 * Method mainwp_generate_rand_hash()
	 *
	 * Generates a random hash to be used when generating the consumer key and secret.
	 *
	 * @return string Returns random string.
	 */
	public function mainwp_generate_rand_hash() {
		if ( ! function_exists( 'openssl_random_pseudo_bytes' ) ) {
			return sha1( wp_rand() );
		}

		return bin2hex( openssl_random_pseudo_bytes( 20 ) ); // @codingStandardsIgnoreLine
	}

	/**
	 * Method mainwp_generate_api_credentials()
	 *
	 * Generates consumer key and secret and saves to the database encrypted.
	 *
	 * @return string $return_data JSON formatted consumer key and secret unhashed.
	 */
	public function mainwp_generate_api_credentials() {

		// we need to generate a consumer key and secret and return the result and save it into the database.
		$consumer_key    = 'ck_' . $this->mainwp_generate_rand_hash();
		$consumer_secret = 'cs_' . $this->mainwp_generate_rand_hash();

		$return_data = array(
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
		);

		// hash the password.
		$consumer_key_hashed    = wp_hash_password( $consumer_key );
		$consumer_secret_hashed = wp_hash_password( $consumer_secret );

		// store the data.
		update_option( 'mainwp_rest_api_consumer_key', $consumer_key_hashed );
		update_option( 'mainwp_rest_api_consumer_secret', $consumer_secret_hashed );

		wp_die( json_encode( $return_data ) );
	}

	/**
	 * Protected variable to hold the API version.
	 *
	 * @var string API version
	 */
	protected $api_version = '1';

	/**
	 * Method mainwp_rest_api_init()
	 *
	 * Creates the necessary endpoints for the api.
	 * Note, for a request to be successful the URL query parameters consumer_key and consumer_secret need to be set and correct.
	 */
	public function mainwp_register_routes() {

		// Create an array which holds all the endpoints. Method can be GET, POST, PUT, DELETE.
		$endpoints = array(
			array(
				'route'    => 'sites',
				'method'   => 'GET',
				'callback' => 'all-sites',
			),
			array(
				'route'    => 'sites',
				'method'   => 'GET',
				'callback' => 'all-sites-count',
			),
			array(
				'route'    => 'sites',
				'method'   => 'GET',
				'callback' => 'connected-sites',
			),
			array(
				'route'    => 'sites',
				'method'   => 'GET',
				'callback' => 'connected-sites-count',
			),
			array(
				'route'    => 'sites',
				'method'   => 'GET',
				'callback' => 'disconnected-sites',
			),
			array(
				'route'    => 'sites',
				'method'   => 'GET',
				'callback' => 'disconnected-sites-count',
			),
			array(
				'route'    => 'sites',
				'method'   => 'POST',
				'callback' => 'sync-sites',
			),
			array(
				'route'    => 'sites',
				'method'   => 'POST',
				'callback' => 'check-sites',
			),
			array(
				'route'    => 'sites',
				'method'   => 'POST',
				'callback' => 'disconnect-sites',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-info',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-installed-plugins',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-installed-plugins-count',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-active-plugins',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-active-plugins-count',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-inactive-plugins',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-inactive-plugins-count',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-installed-themes',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-installed-themes-count',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-active-themes',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-inactive-themes',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-inactive-themes-count',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-available-updates',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-available-updates-count',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-abandoned-plugins',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-abandoned-plugins-count',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-abandoned-themes',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-abandoned-themes-count',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-http-status',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-health-score',
			),
			array(
				'route'    => 'site',
				'method'   => 'GET',
				'callback' => 'site-security-issues',
			),
			array(
				'route'    => 'site',
				'method'   => 'POST',
				'callback' => 'add-site',
			),
			array(
				'route'    => 'site',
				'method'   => 'PUT',
				'callback' => 'edit-site',
			),
			array(
				'route'    => 'site',
				'method'   => 'POST',
				'callback' => 'sync-site',
			),
			array(
				'route'    => 'site',
				'method'   => 'POST',
				'callback' => 'reconnect-site',
			),
			array(
				'route'    => 'site',
				'method'   => 'POST',
				'callback' => 'disconnect-site',
			),
			array(
				'route'    => 'site',
				'method'   => 'DELETE',
				'callback' => 'remove-site',
			),
			array(
				'route'    => 'site',
				'method'   => 'PUT',
				'callback' => 'site-update-wordpress',
			),
			array(
				'route'    => 'site',
				'method'   => 'PUT',
				'callback' => 'site-update-plugins',
			),
			array(
				'route'    => 'site',
				'method'   => 'PUT',
				'callback' => 'site-update-themes',
			),
			array(
				'route'    => 'site',
				'method'   => 'PUT',
				'callback' => 'site-update-translations',
			),
			array(
				'route'    => 'site',
				'method'   => 'PUT',
				'callback' => 'site-update-item',
			),
			array(
				'route'    => 'site',
				'method'   => 'POST',
				'callback' => 'site-manage-plugin',
			),
			array(
				'route'    => 'site',
				'method'   => 'POST',
				'callback' => 'site-manage-theme',
			),
			array(
				'route'    => 'site',
				'method'   => 'POST',
				'callback' => 'check-site-http-status',
			),
			array(
				'route'    => 'updates',
				'method'   => 'GET',
				'callback' => 'available-updates',
			),
			array(
				'route'    => 'updates',
				'method'   => 'GET',
				'callback' => 'ignored-plugins-updates',
			),
			array(
				'route'    => 'updates',
				'method'   => 'GET',
				'callback' => 'site-ignored-plugins-updates',
			),
			array(
				'route'    => 'updates',
				'method'   => 'GET',
				'callback' => 'ignored-themes-updates',
			),
			array(
				'route'    => 'updates',
				'method'   => 'GET',
				'callback' => 'site-ignored-themes-updates',
			),
			array(
				'route'    => 'updates',
				'method'   => 'POST',
				'callback' => 'ignore-updates',
			),
			array(
				'route'    => 'updates',
				'method'   => 'POST',
				'callback' => 'ignore-update',
			),
			array(
				'route'    => 'updates',
				'method'   => 'POST',
				'callback' => 'unignore-updates',
			),
			array(
				'route'    => 'updates',
				'method'   => 'POST',
				'callback' => 'unignore-update',
			),
		);

		// loop through the endpoints.
		foreach ( $endpoints as $endpoint ) {

			$function_name = str_replace( '-', '_', $endpoint['callback'] );

			register_rest_route(
				'mainwp/v' . $this->api_version,
				'/' . $endpoint['route'] . '/' . $endpoint['callback'],
				array(
					'methods'             => $endpoint['method'],
					'callback'            => array( &$this, 'mainwp_rest_api_' . $function_name . '_callback' ),
					'permission_callback' => '__return_true',
				)
			);
		}

	}

	/**
	 * Method mainwp_rest_api_init()
	 *
	 * Makes sure the correct consumer key and secret are entered.
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return bool Whether the api credentials are valid.
	 */
	public function mainwp_validate_request( $request ) {

		// users entered consumer key and secret.
		$consumer_key    = $request['consumer_key'];
		$consumer_secret = $request['consumer_secret'];

		// data stored in database.
		$consumer_key_option    = get_option( 'mainwp_rest_api_consumer_key' );
		$consumer_secret_option = get_option( 'mainwp_rest_api_consumer_secret' );

		if ( wp_check_password( $consumer_key, $consumer_key_option ) && wp_check_password( $consumer_secret, $consumer_secret_option ) ) {
			if ( ! defined( 'MAINWP_REST_API' ) ) {
				define( 'MAINWP_REST_API', true );
			}
			return true;
		} else {
			return false;
		}

	}

	/**
	 * Method mainwp_authentication_error()
	 *
	 * Common error message when consumer key and secret are wrong.
	 *
	 * @return array $response Array with an error message explaining that the credentials are wrong.
	 */
	public function mainwp_authentication_error() {

		$data = array( 'ERROR' => __( 'Incorrect consumer key and or secret. If the issue persists please reset your authentication details from the plugin settings.', 'mainwp' ) );

		$response = new \WP_REST_Response( $data );
		$response->set_status( 401 );

		return $response;

	}

	/**
	 * Method mainwp_missing_data_error()
	 *
	 * Common error message when data is missing from the request.
	 *
	 * @return array $response Array with an error message explaining details are missing.
	 */
	public function mainwp_missing_data_error() {

		$data = array( 'ERROR' => __( 'Required parameter is missing.', 'mainwp' ) );

		$response = new \WP_REST_Response( $data );
		$response->set_status( 400 );

		return $response;

	}

	/**
	 * Method mainwp_invalid_data_error()
	 *
	 * Common error message when data in request is ivalid.
	 *
	 * @return array $response Array with an error message explaining details are missing.
	 */
	public function mainwp_invalid_data_error() {

		$data = array( 'ERROR' => __( 'Required parameter data is is not valid.', 'mainwp' ) );

		$response = new \WP_REST_Response( $data );
		$response->set_status( 400 );

		return $response;

	}

	/**
	 * Method mainwp_run_process_success()
	 *
	 * Common error message when data is missing from the request.
	 *
	 * @return array $response Array with an error message explaining details are missing.
	 */
	public function mainwp_run_process_success() {

		$data = array( 'SUCCESS' => __( 'Process ran.', 'mainwp' ) );

		$response = new \WP_REST_Response( $data );
		$response->set_status( 200 );

		return $response;

	}

	/**
	 * Method mainwp_rest_api_all_sites_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: all-sites
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/all-sites
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_all_sites_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get data.
			$data = MainWP_DB::instance()->get_websites_for_current_user();

			$response = new \WP_REST_Response( $data );
			$response->set_status( 200 );

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_all_sites_count_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: all-sites-count
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/all-sites-count
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_all_sites_count_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get data.
			$websites = MainWP_DB::instance()->get_websites_for_current_user();

			$data = array(
				'count' => count( $websites ),
			);

			$response = new \WP_REST_Response( $data );
			$response->set_status( 200 );

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_connected_sites_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: connected-sites
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/connected-sites
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_connected_sites_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get data.
			$data = MainWP_DB::instance()->get_connected_websites();

			$response = new \WP_REST_Response( $data );
			$response->set_status( 200 );

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_connected_sites_count_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: connected-sites-count
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/connected-sites-count
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_connected_sites_count_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get data.
			$websites = MainWP_DB::instance()->get_connected_websites();

			$data = array(
				'count' => count( $websites ),
			);

			$response = new \WP_REST_Response( $data );
			$response->set_status( 200 );

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_disconnected_sites_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: disconnected-sites
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/disconnected-sites
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_disconnected_sites_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get data.
			$data = MainWP_DB::instance()->get_disconnected_websites();

			$response = new \WP_REST_Response( $data );
			$response->set_status( 200 );

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_disconnected_sites_count_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: disconnected-sites-count
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/disconnected-sites-count
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_disconnected_sites_count_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get data.
			$websites = MainWP_DB::instance()->get_disconnected_websites();

			$data = array(
				'count' => count( $websites ),
			);

			$response = new \WP_REST_Response( $data );
			$response->set_status( 200 );

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_sync_sites_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: sync-sites
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/sync-sites
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_sync_sites_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
			while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
				try {
					MainWP_Sync::sync_site( $website );
				} catch ( \Exception $e ) {

				}
			}
			MainWP_DB::free_result( $websites );

			// do common process response.
			$response = $this->mainwp_run_process_success();

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_check_sites_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: check-sites
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/check-sites
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_check_sites_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
			while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
				try {
					MainWP_Monitoring_Handler::handle_check_website( $website );
				} catch ( \Exception $e ) {

				}
			}
			MainWP_DB::free_result( $websites );

			// do common process response.
			$response = $this->mainwp_run_process_success();

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_disconnect_sites_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: disconnect-sites
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/disconnect-sites
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_disconnect_sites_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
			while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
				try {
					MainWP_Connect::fetch_url_authed( $website, 'disconnect' );
				} catch ( \Exception $e ) {

				}
			}
			MainWP_DB::free_result( $websites );

			// do common process response.
			$response = $this->mainwp_run_process_success();

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$data = MainWP_DB::instance()->get_website_by_id( $site_id );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_info_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-info
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-info
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_info_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$data    = json_decode( MainWP_DB::instance()->get_website_option( $website, 'site_info' ), true );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_installed_plugins_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-installed-plugins
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-installed-plugins
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_installed_plugins_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$data    = json_decode( $website->plugins, 1 );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_installed_plugins_count_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-installed-plugins-count
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-installed-plugins-count
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_installed_plugins_count_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$plugins = json_decode( $website->plugins, 1 );
					$data    = array(
						'count' => count( $plugins ),
					);

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_active_plugins_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-active-plugins
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-active-plugins
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_active_plugins_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$plugins = json_decode( $website->plugins, 1 );
					$data    = MainWP_Utility::get_sub_array_having( $plugins, 'active', 1 );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_active_plugins_count_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-active-plugins-count
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-active-plugins-count
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_active_plugins_count_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website        = MainWP_DB::instance()->get_website_by_id( $site_id );
					$plugins        = json_decode( $website->plugins, 1 );
					$active_plugins = MainWP_Utility::get_sub_array_having( $plugins, 'active', 1 );
					$data           = array(
						'count' => count( $active_plugins ),
					);

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_inactive_plugins_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-inactive-plugins
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-inactive-plugins
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_inactive_plugins_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$plugins = json_decode( $website->plugins, 1 );
					$data    = MainWP_Utility::get_sub_array_having( $plugins, 'active', 0 );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_inactive_plugins_count_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-inactive-plugins-count
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-inactive-plugins-count
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_inactive_plugins_count_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website          = MainWP_DB::instance()->get_website_by_id( $site_id );
					$plugins          = json_decode( $website->plugins, 1 );
					$inactive_plugins = MainWP_Utility::get_sub_array_having( $plugins, 'active', 0 );
					$data             = array(
						'count' => count( $inactive_plugins ),
					);

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_installed_themes_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-installed-themes
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-installed-themes
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_installed_themes_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$data    = json_decode( $website->themes, 1 );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_installed_themes_count_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-installed-themes-count
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-installed-themes-count
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_installed_themes_count_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$themes  = json_decode( $website->themes, 1 );
					$data    = array(
						'count' => count( $themes ),
					);

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_active_themes_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-active-themes
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-active-themes
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_active_themes_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$themes  = json_decode( $website->themes, 1 );
					$data    = MainWP_Utility::get_sub_array_having( $themes, 'active', 1 );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_inactive_themes_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-inactive-themes
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-inactive-themes
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_inactive_themes_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$themes  = json_decode( $website->themes, 1 );
					$data    = MainWP_Utility::get_sub_array_having( $themes, 'active', 0 );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_inactive_themes_count_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-inactive-themes-count
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-inactive-themes-count
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_inactive_themes_count_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website         = MainWP_DB::instance()->get_website_by_id( $site_id );
					$themes          = json_decode( $website->themes, 1 );
					$inactive_themes = MainWP_Utility::get_sub_array_having( $themes, 'active', 0 );
					$data            = array(
						'count' => count( $inactive_themes ),
					);

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_available_updates_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-available-updates
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-available-updates
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_available_updates_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website              = MainWP_DB::instance()->get_website_by_id( $site_id );
					$wp_upgrades          = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true );
					$plugin_upgrades      = json_decode( $website->plugin_upgrades, true );
					$theme_upgrades       = json_decode( $website->theme_upgrades, true );
					$translation_upgrades = json_decode( $website->translation_upgrades, true );
					$data                 = array(
						'wp_core'     => $wp_upgrades,
						'plugins'     => $plugin_upgrades,
						'themes'      => $theme_upgrades,
						'translation' => $translation_upgrades,
					);

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_available_updates_count_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-available-updates-count
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-available-updates-count
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_available_updates_count_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website      = MainWP_DB::instance()->get_website_by_id( $site_id );
					$plugins      = json_decode( $website->plugin_upgrades, true );
					$themes       = json_decode( $website->theme_upgrades, true );
					$translations = json_decode( $website->translation_upgrades, true );
					$wp           = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true );
					if ( count( $wp ) > 0 ) {
						$wp = 1;
					} else {
						$wp = 0;
					}
					$total = array_merge( $plugins, $themes, $translations );
					$data  = array(
						'total'        => count( $total ) + $wp,
						'wp'           => $wp,
						'plugins'      => count( $plugins ),
						'themes'       => count( $themes ),
						'translations' => count( $translations ),
					);

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_abandoned_plugins_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-abandoned-plugins
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-abandoned-plugins
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_abandoned_plugins_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$data    = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' ), true );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_abandoned_plugins_count_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-abandoned-plugins-count
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-abandoned-plugins-count
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_abandoned_plugins_count_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$plugins = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' ), true );
					$data    = array(
						'count' => count( $plugins ),
					);

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_abandoned_themes_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-abandoned-themes
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-abandoned-themes
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_abandoned_themes_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$data    = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' ), true );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_abandoned_themes_count_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-abandoned-themes-count
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-abandoned-themes-count
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_abandoned_themes_count_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$themes  = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' ), true );
					$data    = array(
						'count' => count( $themes ),
					);

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_http_status_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-http-status
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-http-status
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_http_status_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$data    = $website->http_response_code;

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_health_score_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-health-score
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-health-score
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_health_score_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website       = MainWP_DB::instance()->get_website_by_id( $site_id, false, array( 'health_site_status' ) );
					$health_status = isset( $website->health_site_status ) ? json_decode( $website->health_site_status, true ) : array();
					$hstatus       = MainWP_Utility::get_site_health( $health_status );
					$hval          = $hstatus['val'];
					$critical      = $hstatus['critical'];
					if ( 80 <= $hval && 0 == $critical ) {
						$health_score = 'Good';
					} else {
						$health_score = 'Should be improved';
					}

					$data = $health_score;

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_security_issues_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-security-issues
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-security-issues
	 * API Method: get
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_security_issues_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$data    = MainWP_Connect::fetch_url_authed( $website, 'security' );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_add_site_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-add
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-add
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_add_site_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get data.
			$fields = $request->get_json_params();

			$data = MainWP_Manage_Sites_Handler::rest_api_add_site( $fields );

			$response = new \WP_REST_Response( $data );
			$response->set_status( 200 );

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_edit_site_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-edit
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-edit
	 * API Method: PUT
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_edit_site_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];
					$fields  = $request->get_json_params(); // this will get json body and parse it as an associative array which is perfect for what we want.

					// get data.
					$data = MainWP_DB_Common::instance()->rest_api_update_website( $site_id, $fields );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_sync_site_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: sync-site
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/sync-site
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_sync_site_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {
			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					try {
						MainWP_Sync::sync_site( $website );
					} catch ( \Exception $e ) {

					}
					// do common process response.
					$response = $this->mainwp_run_process_success();
				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_reconnect_site_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: reconnect-site
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/reconnect-site
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_reconnect_site_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					try {
						MainWP_Manage_Sites_View::m_reconnect_site( $website );
					} catch ( \Exception $e ) {

					}
					// do common process response.
					$response = $this->mainwp_run_process_success();

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_disconnect_site_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: disconnect-site
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/disconnect-site
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_disconnect_site_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					try {
							MainWP_Connect::fetch_url_authed( $website, 'disconnect' );
					} catch ( \Exception $e ) {

					}
					// do common process response.
					$response = $this->mainwp_run_process_success();

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_remove_site_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: remove-site
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/remove-site
	 * API Method: DELETE
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_remove_site_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					MainWP_Manage_Sites_Handler::remove_website( $site_id );

					// do common process response.
					$response = $this->mainwp_run_process_success();

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_update_wordpress_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-update-wordpress
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-update-wordpress
	 * API Method: PUT
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_update_wordpress_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$data    = MainWP_Updates_Handler::upgrade_website( $website );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_update_plugins_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-update-plugins
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-update-plugins
	 * API Method: PUT
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_update_plugins_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website         = MainWP_DB::instance()->get_website_by_id( $site_id );
					$plugin_upgrades = json_decode( $website->plugin_upgrades, true );
					$slugs           = array();
					foreach ( $plugin_upgrades as $slug => $plugin ) {
						$slugs[] = $slug;
					}
					MainWP_Connect::fetch_url_authed(
						$website,
						'upgradeplugintheme',
						array(
							'type' => 'plugin',
							'list' => urldecode( implode( ',', $slugs ) ),
						)
					);

					// do common process response.
					$response = $this->mainwp_run_process_success();

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_update_themes_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-update-themes
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-update-themes
	 * API Method: PUT
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_update_themes_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website        = MainWP_DB::instance()->get_website_by_id( $site_id );
					$theme_upgrades = json_decode( $website->theme_upgrades, true );
					$slugs          = array();
					foreach ( $theme_upgrades as $slug => $theme ) {
						$slugs[] = $slug;
					}
					MainWP_Connect::fetch_url_authed(
						$website,
						'upgradeplugintheme',
						array(
							'type' => 'theme',
							'list' => urldecode( implode( ',', $slugs ) ),
						)
					);

					// do common process response.
					$response = $this->mainwp_run_process_success();

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_update_translations_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-update-translations
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-update-translations
	 * API Method: PUT
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_update_translations_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website              = MainWP_DB::instance()->get_website_by_id( $site_id );
					$translation_upgrades = json_decode( $website->translation_upgrades, true );
					$slugs                = array();
					foreach ( $translation_upgrades as $translation_upgrade ) {
						$slugs[] = $translation_upgrade['slug'];
					}
					MainWP_Connect::fetch_url_authed(
						$website,
						'upgradetranslation',
						array(
							'type' => 'translation',
							'list' => urldecode( implode( ',', $slugs ) ),
						)
					);

					// do common process response.
					$response = $this->mainwp_run_process_success();

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_update_item_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-update-item
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-update-item
	 * API Method: PUT
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_update_item_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.

			if ( null != $request['site_id'] && null != $request['type'] && null != $request['slug'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];
					$type    = $request['type'];
					$slug    = $request['slug'];

					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					MainWP_Connect::fetch_url_authed(
						$website,
						'upgradeplugintheme',
						array(
							'type' => $type,
							'list' => urldecode( $slug ),
						)
					);

					// do common process response.
					$response = $this->mainwp_run_process_success();

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_manage_plugin_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-manage-plugin
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-manage-plugin
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_manage_plugin_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.

			if ( null != $request['site_id'] && null != $request['plugin'] && null != $request['action'] ) {

				if ( 'activate' == $request['action'] || 'deactivate' == $request['action'] || 'delete' == $request['action'] ) {

					if ( is_numeric( $request['site_id'] ) ) {

						$site_id = $request['site_id'];
						$plugin  = $request['plugin'];
						$action  = $request['action'];

						$website = MainWP_DB::instance()->get_website_by_id( $site_id );

						try {
							MainWP_Connect::fetch_url_authed(
								$website,
								'plugin_action',
								array(
									'action' => $action,
									'plugin' => $plugin,
								)
							);
						} catch ( \Exception $e ) {

						}

						// do common process response.
						$response = $this->mainwp_run_process_success();

					} else {
						// throw invalid data error.
						$response = $this->mainwp_invalid_data_error();
					}
				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_manage_theme_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-manage-theme
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/site-manage-theme
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_manage_theme_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.

			if ( null != $request['site_id'] && null != $request['theme'] && null != $request['action'] ) {

				if ( 'activate' == $request['action'] || 'deactivate' == $request['action'] || 'delete' == $request['action'] ) {

					if ( is_numeric( $request['site_id'] ) ) {

						$site_id = $request['site_id'];
						$theme   = $request['theme'];
						$action  = $request['action'];

						$website = MainWP_DB::instance()->get_website_by_id( $site_id );

						try {
							MainWP_Connect::fetch_url_authed(
								$website,
								'theme_action',
								array(
									'action' => $action,
									'theme'  => $theme,
								)
							);
						} catch ( \Exception $e ) {

						}

						// do common process response.
						$response = $this->mainwp_run_process_success();

					} else {
						// throw invalid data error.
						$response = $this->mainwp_invalid_data_error();
					}
				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_check_site_http_status_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: check-site-http-status
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/check-site-http-status
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_check_site_http_status_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					MainWP_Monitoring_Handler::handle_check_website( $website );

					// do common process response.
					$response = $this->mainwp_run_process_success();

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_available_updates_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: available-updates
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/updates/available-updates
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_available_updates_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			$all_updates = array();

			$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );

			while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
				$wp_upgrades                 = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true );
				$plugin_upgrades             = json_decode( $website->plugin_upgrades, true );
				$theme_upgrades              = json_decode( $website->theme_upgrades, true );
				$translation_upgrades        = json_decode( $website->translation_upgrades, true );
				$all_updates[ $website->id ] = array(
					'wp_core'     => $wp_upgrades,
					'plugins'     => $plugin_upgrades,
					'themes'      => $theme_upgrades,
					'translation' => $translation_upgrades,
				);
			}
			MainWP_DB::free_result( $websites );

			// get data.
			$data = $all_updates;

			$response = new \WP_REST_Response( $data );
			$response->set_status( 200 );

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_ignored_plugins_updates_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: ignored-plugins-updates
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/updates/ignored-plugins-updates
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_ignored_plugins_updates_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			$userExtension = MainWP_DB_Common::instance()->get_user_extension();

			// get data.
			$data = json_decode( $userExtension->ignored_plugins, true );

			$response = new \WP_REST_Response( $data );
			$response->set_status( 200 );

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_ignored_plugins_updates_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-ignored-plugins-updates
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/updates/site-ignored-plugins-updates
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_ignored_plugins_updates_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$data    = json_decode( $website->ignored_plugins, true );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_ignored_themes_updates_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: ignored-themes-updates
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/updates/ignored-themes-updates
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_ignored_themes_updates_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			$userExtension = MainWP_DB_Common::instance()->get_user_extension();

			// get data.
			$data = json_decode( $userExtension->ignored_themes, true );

			$response = new \WP_REST_Response( $data );
			$response->set_status( 200 );

		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_site_ignored_themes_updates_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: site-ignored-themes-updates
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/updates/site-ignored-themes-updates
	 * API Method: GET
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_site_ignored_themes_updates_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['site_id'] ) {

				if ( is_numeric( $request['site_id'] ) ) {

					$site_id = $request['site_id'];

					// get data.
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					$data    = json_decode( $website->ignored_themes, true );

					$response = new \WP_REST_Response( $data );
					$response->set_status( 200 );

				} else {
					// throw invalid data error.
					$response = $this->mainwp_invalid_data_error();
				}
			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_ignore_updates_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: ignore-updates
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/updates/ignore-updates
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_ignore_updates_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['type'] && null != $request['slug'] && null != $request['name'] ) {

				$type = $request['type'];
				$slug = $request['slug'];
				$name = $request['name'];

				MainWP_Updates_Handler::ignore_plugins_themes( $type, $slug, $name );

				// do common process response.
				$response = $this->mainwp_run_process_success();

			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_ignore_update_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: ignore-update
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/updates/ignore-update
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_ignore_update_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['type'] && null != $request['slug'] && null != $request['name'] && null != $request['site_id'] ) {

				$site_id = $request['site_id'];
				$type    = $request['type'];
				$slug    = $request['slug'];
				$name    = $request['name'];

				MainWP_Updates_Handler::ignore_plugin_theme( $type, $slug, $name, $site_id );

				// do common process response.
				$response = $this->mainwp_run_process_success();

			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_unignore_updates_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: unignore-updates
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/updates/unignore-updates
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_unignore_updates_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['type'] && null != $request['slug'] ) {

				$type = $request['type'];
				$slug = $request['slug'];

				MainWP_Updates_Handler::unignore_plugins_themes( $type, $slug );

				// do common process response.
				$response = $this->mainwp_run_process_success();

			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

	/**
	 * Method mainwp_rest_api_unignore_update_callback()
	 *
	 * Callback function for managing the response to API requests made for the endpoint: unignore-update
	 * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/updates/unignore-update
	 * API Method: POST
	 *
	 * @param array $request The request made in the API call which includes all parameters.
	 *
	 * @return object $response An object that contains the return data and status of the API request.
	 */
	public function mainwp_rest_api_unignore_update_callback( $request ) {

		// first validate the request.
		if ( $this->mainwp_validate_request( $request ) ) {

			// get parameters.
			if ( null != $request['type'] && null != $request['slug'] && null != $request['site_id'] ) {

				$site_id = $request['site_id'];
				$type    = $request['type'];
				$slug    = $request['slug'];

				MainWP_Updates_Handler::unignore_plugin_theme( $type, $slug, $site_id );

				// do common process response.
				$response = $this->mainwp_run_process_success();

			} else {
				// throw missing data error.
				$response = $this->mainwp_missing_data_error();
			}
		} else {
			// throw common error.
			$response = $this->mainwp_authentication_error();
		}

		return $response;

	}

}

// End of class.
