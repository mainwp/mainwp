<?php
/**
 * MainWP Module Cost Tracker Rest Api class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_DB_Client;

/**
 * Class Cost_Tracker_Rest_Api_V1
 */
class Cost_Tracker_Rest_Api_V1 {

    /**
     * Protected variable to hold the API version.
     *
     * @var string API version
     */
    protected $api_version = '1';

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
     * @return static::$instance
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method init()
     *
     * Adds an action to create the rest API endpoints if activated in the plugin settings.
     */
    public function init() {
        add_action( 'init', array( &$this, 'init_rest_api' ) );
    }

    /**
     * Method init_rest_api()
     *
     * Adds an action to create the rest API endpoints if activated in the plugin settings.
     */
    public function init_rest_api() {
        $activated = apply_filters( 'mainwp_rest_api_enabled', false );
        // only activate the api if enabled in the dashboard plugin.
        if ( $activated ) {
            // init APIs.
            add_action( 'rest_api_init', array( &$this, 'mainwp_register_routes' ) );
        }
    }

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
                'route'    => 'cost-tracker',
                'method'   => 'GET',
                'callback' => 'get-all-costs',
            ),
            array(
                'route'    => 'cost-tracker',
                'method'   => 'GET',
                'callback' => 'get-client-costs',
            ),
            array(
                'route'    => 'cost-tracker',
                'method'   => 'GET',
                'callback' => 'get-site-costs',
            ),
            array(
                'route'    => 'cost-tracker',
                'method'   => 'GET',
                'callback' => 'get-costs',
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
                    'callback'            => array( &$this, 'cost_tracker_rest_api_' . $function_name . '_callback' ),
                    'permission_callback' => '__return_true',
                )
            );
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

        $data = array( 'ERROR' => esc_html__( 'Incorrect or missing consumer key and/or secret. If the issue persists please reset your authentication details from the MainWP > Settings > REST API page, on your MainWP Dashboard site.', 'mainwp' ) );

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

        $data = array( 'ERROR' => esc_html__( 'Required parameter is missing.', 'mainwp' ) );

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

        $data = array( 'ERROR' => esc_html__( 'Required parameter data is is not valid.', 'mainwp' ) );

        $response = new \WP_REST_Response( $data );
        $response->set_status( 400 );

        return $response;
    }

    /**
     * Method cost_tracker_rest_api_get_all_costs_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: cost-tracker
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/cost-tracker/get-all-costs
     * API Method: GET
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function cost_tracker_rest_api_get_all_costs_callback( $request ) {
        // first validate the request.
        if ( apply_filters( 'mainwp_rest_api_validate', false, $request ) ) {
            $costs          = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'all' );
            $result         = array();
            $data           = Cost_Tracker_Rest_Api_Handle_V1::instance()->prepare_api_costs_data( $costs );
            $result['data'] = $data;
            $response       = new \WP_REST_Response( $result );
            $response->set_status( 200 );
        } else {
            // throw common error.
            $response = $this->mainwp_authentication_error();
        }
        return $response;
    }


    /**
     * Method cost_tracker_rest_api_get_client_costs_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: cost-tracker
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/cost-tracker/get-client-costs
     * API Method: GET
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function cost_tracker_rest_api_get_client_costs_callback( $request ) { //phpcs:ignore -- NOSONAR - complex.
        // first validate the request.
        if ( apply_filters( 'mainwp_rest_api_validate', false, $request ) ) {
            if ( isset( $request['client_id'] ) && null !== $request['client_id'] ) {
                $client_id = intval( $request['client_id'] );
                $costs     = array();
                $error     = '';
                $client    = false;
                if ( ! empty( $client_id ) ) {
                    $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
                }

                if ( empty( $client ) ) {
                    $error = esc_html__( 'Invalid Client ID or Client not found. Please try again.', 'mainwp' );
                } else {
                    $costs = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'client_id', $client_id );
                    $data  = Cost_Tracker_Rest_Api_Handle_V1::instance()->prepare_api_costs_data( $costs );

                    $result         = array();
                    $result['data'] = $data;
                }
                if ( ! empty( $error ) ) {
                    $result['ERROR'] = $error;
                }

                $response = new \WP_REST_Response( $result );
                $response->set_status( 200 );
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
     * Method cost_tracker_rest_api_get_site_costs_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: cost-tracker
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/cost-tracker/get-site-costs
     * API Method: GET
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function cost_tracker_rest_api_get_site_costs_callback( $request ) {
        // first validate the request.
        if ( apply_filters( 'mainwp_rest_api_validate', false, $request ) ) {
            // get parameters.
            if ( isset( $request['site_id'] ) && null !== $request['site_id'] ) {
                $site_id = intval( $request['site_id'] );
                $result  = array();

                $valid_error = Cost_Tracker_Rest_Api_Handle_V1::instance()->valid_api_request_data_by( 'site_id', $site_id );
                if ( ! empty( $valid_error ) ) {
                    $result = $valid_error;
                } else {
                    $costs          = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'site_id', $site_id );
                    $data           = Cost_Tracker_Rest_Api_Handle_V1::instance()->prepare_api_costs_data( $costs );
                    $result['data'] = $data;
                }
                $response = new \WP_REST_Response( $result );
                $response->set_status( 200 );
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
     * Method cost_tracker_rest_api_get_costs_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: cost-tracker
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/cost-tracker/get-costs
     * API Method: GET
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function cost_tracker_rest_api_get_costs_callback( $request ) { //phpcs:ignore -- NOSONAR - complex.
        // first validate the request.
        if ( apply_filters( 'mainwp_rest_api_validate', false, $request ) ) {
            // get parameters.
            if ( isset( $request['id'] ) && null !== $request['id'] ) {
                $id         = sanitize_text_field( $request['id'] ); // int or string of int.
                $costs_data = array();
                if ( ! empty( $id ) ) {
                    $cost = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'id', $request['id'] );
                    if ( is_numeric( $id ) ) {
                        $costs_data[] = $cost;
                    } else {
                        $costs_data = $cost;
                    }
                }

                $result = array();
                $error  = '';
                if ( empty( $cost ) ) {
                    $error = esc_html__( 'Invaid subscription id or subscription not found. Plase try again.', 'mainwp' );
                } else {
                    $data           = Cost_Tracker_Rest_Api_Handle_V1::instance()->prepare_api_costs_data( $costs_data );
                    $result['data'] = $data;
                }

                if ( ! empty( $error ) ) {
                    $result['ERROR'] = $error;
                }

                $response = new \WP_REST_Response( $result );
                $response->set_status( 200 );
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
