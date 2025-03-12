<?php
/**
 * MainWP REST API
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 * @author Martin Gibson
 */

namespace MainWP\Dashboard;

use MainWP\Dashboard\MainWP_Rest_Api_Page;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_Monitoring_Handler;
use MainWP\Dashboard\MainWP_Sync;
use MainWP\Dashboard\MainWP_DB_Site_Actions;
use MainWP\Dashboard\MainWP_Client_Handler;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Updates_Handler;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_Error_Helper;
use MainWP\Dashboard\MainWP_Exception;
use Exception;

/**
 * Class Rest_Api_V1
 *
 * @package MainWP\Dashboard
 */
class Rest_Api_V1 { //phpcs:ignore -- NOSONAR - multi methods.

    // phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Protected variable to hold the API version.
     *
     * @var string API version
     */
    protected $api_version = '1';

    /**
     * Private variable enabled api.
     *
     * @var bool API enabled.
     */
    private static $enabled_api = null;

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
     * Adds an action to generate API credentials.
     * Adds an action to create the rest API endpoints if activated in the plugin settings.
     */
    public function init() {
        if ( $this->is_rest_api_enabled() ) {
            add_filter( 'mainwp_rest_api_validate', array( &$this, 'rest_api_validate' ), 10, 2 ); // to compatible version 1 rest api validate.
            // run API.
            if ( $this->enabled_rest_v1_api() ) {
                add_action( 'rest_api_init', array( &$this, 'mainwp_register_routes' ) );
            }
        }
        add_filter( 'mainwp_rest_api_enabled', array( &$this, 'hook_rest_api_enabled' ), 10, 1 );
        add_filter( 'mainwp_rest_api_v2_enabled', array( &$this, 'hook_rest_api_v2_enabled' ), 10, 1 );
    }


    /**
     * Method is_rest_api_enabled()
     *
     * Check if Enabled the REST API.
     */
    public function is_rest_api_enabled() {
        if ( null === static::$enabled_api ) {
            $disabled = apply_filters( 'mainwp_rest_api_disabled', false );
            if ( $disabled ) {
                static::$enabled_api = false;
            } else {
                static::$enabled_api = $this->enabled_rest_v2_api() || $this->enabled_rest_v1_api();
            }
        }
        return static::$enabled_api;
    }

    /**
     * Method enabled_rest_v1_api()
     *
     * Check if enable the REST API.
     */
    private function enabled_rest_v1_api() {

        $all_keys = get_option( 'mainwp_rest_api_keys', false );

        if ( ! is_array( $all_keys ) ) {
            return false;
        }

        foreach ( $all_keys as $item ) {
            if ( ! empty( $item['cs'] ) && ! empty( $item['enabled'] ) ) {
                return true; // one key enabled, enabled the REST API.
            }
        }

        return false; // all keys disabled.
    }

    /**
     * Method enabled_rest_v2_api()
     *
     * Enabled the REST API.
     */
    private function enabled_rest_v2_api() {
        return MainWP_DB::instance()->is_existed_enabled_rest_key();
    }

    /**
     * Method hook_rest_api_enabled()
     *
     * Hook to check if Enabled the REST API.
     */
    public function hook_rest_api_enabled() {
        return $this->is_rest_api_enabled();
    }

    /**
     * Method hook_rest_api_enabled()
     *
     * Hook to check if Enabled the REST API.
     */
    public function hook_rest_api_v2_enabled() {
        return $this->is_rest_api_enabled() && $this->enabled_rest_v2_api();
    }

    /**
     * Method mainwp_rest_api_init()
     *
     * Creates the necessary endpoints for the api.
     * Note, for a request to be successful the URL query parameters consumer_key and consumer_secret need to be set and correct.
     */
    public function mainwp_register_routes() { // phpcs:ignore -- NOSONAR - complex.
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
                'route'    => 'sites',
                'method'   => 'GET',
                'callback' => 'http-status',
            ),
            array(
                'route'    => 'sites',
                'method'   => 'GET',
                'callback' => 'health-score',
            ),
            array(
                'route'    => 'sites',
                'method'   => 'GET',
                'callback' => 'security-issues',
            ),
            array(
                'route'    => 'sites',
                'method'   => 'GET',
                'callback' => 'sites-available-updates-count',
            ),
            array(
                'route'    => 'sites',
                'method'   => 'GET',
                'callback' => 'get-sites-by-url',
            ),
            array(
                'route'    => 'sites',
                'method'   => 'GET',
                'callback' => 'get-sites-by-client',
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
                'route'    => 'site',
                'method'   => 'GET',
                'callback' => 'non-mainwp-changes',
            ),
            array(
                'route'    => 'site',
                'method'   => 'GET',
                'callback' => 'non-mainwp-changes-count',
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
            array(
                'route'    => 'client',
                'method'   => 'POST',
                'callback' => 'add-client',
            ),
            array(
                'route'    => 'client',
                'method'   => 'PUT',
                'callback' => 'edit-client',
            ),
            array(
                'route'    => 'client',
                'method'   => 'DELETE',
                'callback' => 'remove-client',
            ),
            array(
                'route'    => 'clients',
                'method'   => 'GET',
                'callback' => 'all-clients',
            ),
            array(
                'route'    => 'tags',
                'method'   => 'GET',
                'callback' => 'all-tags',
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
    public function mainwp_validate_request( $request ) { // phpcs:ignore -- NOSONAR - complex.

        $consumer_key    = null;
        $consumer_secret = null;

        if ( ! empty( $request['consumer_key'] ) && ! empty( $request['consumer_secret'] ) ) {
            // users entered consumer key and secret.
            $consumer_key    = $request['consumer_key'];
            $consumer_secret = $request['consumer_secret'];
        } else {
            $headers = apache_request_headers();

            $header_keys = '';

            if ( isset( $headers['x-api-key'] ) ) {
                $header_keys = $headers['x-api-key'];
            } elseif ( isset( $headers['X-Api-Key'] ) ) {
                $header_keys = $headers['X-Api-Key'];
            }

            $api_keys = array();
            if ( is_string( $header_keys ) && ! empty( $header_keys ) ) {
                $api_keys = json_decode( $header_keys, true );
            }

            if ( is_array( $api_keys ) && isset( $api_keys['consumer_key'] ) ) {
                // users entered consumer key and secret.
                $consumer_key    = $api_keys['consumer_key'];
                $consumer_secret = $api_keys['consumer_secret'];
            }
        }

        // data stored in database.
        $all_keys = MainWP_Rest_Api_Page::check_rest_api_updates();
        if ( ! is_array( $all_keys ) ) {
            $all_keys = array();
        }

        if ( isset( $all_keys[ $consumer_key ] ) ) {
            $existed_key = $all_keys[ $consumer_key ];
            if ( is_array( $existed_key ) && isset( $existed_key['cs'] ) ) {
                $cs_hashed = $existed_key['cs'];
                $enabled   = isset( $existed_key['enabled'] ) && ! empty( $existed_key['enabled'] ) ? true : false;
                if ( $enabled && wp_check_password( $consumer_secret, $cs_hashed ) ) {

                    $valid_auth = true;

                    if ( isset( $existed_key['ck_hashed'] ) && ! wp_check_password( $consumer_key, $existed_key['ck_hashed'] ) ) {
                        $valid_auth = false;
                    }

                    if ( $valid_auth ) {
                        try {
                            if ( $this->mainwp_permission_check_request( $request, $existed_key ) ) {
                                if ( ! defined( 'MAINWP_REST_API' ) ) { // compatible.
                                    define( 'MAINWP_REST_API', true );
                                }
                                if ( ! defined( 'MAINWP_REST_API_DOING' ) ) {
                                    define( 'MAINWP_REST_API_DOING', true );
                                }
                                return true;
                            }
                        } catch ( \Exception $ex ) {
                            $err = $ex->getMessage();
                            return new \WP_Error(
                                'rest_method_not_allowed',
                                is_string( $err ) ? $err : esc_html__( 'Sorry, you are not allowed to do the method.', 'mainwp' ),
                                array( 'status' => 401 )
                            );
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * Method mainwp_permission_check_request()
     *
     * Check request permissions.
     *
     * @param array $request The request made in the API call which includes all parameters.
     * @param array $item API Keys using.
     *
     * @return bool Whether the api permissions are valid.
     * @throws \MainWP_Exception Request permissions check.
     */
    public function mainwp_permission_check_request( $request, $item ) {

        $init_pers = '';
        if ( isset( $item['perms'] ) ) {
            $init_pers = $item['perms'];
        } else {
            $init_pers = 'r,w,d'; // to compatible.
        }

        $item_pers = is_string( $init_pers ) ? explode( ',', $init_pers ) : array();

        $perms_map = array(
            'r' => array(
                'GET',
            ),
            'w' => array(
                'POST',
                'PUT',
                'PATCH',
            ),
            'd' => array(
                'DELETE',
            ),
        );

        $allow_methods = array();

        if ( in_array( 'r', $item_pers ) ) {
            $allow_methods = $perms_map['r'];
        }
        if ( in_array( 'w', $item_pers ) ) {
            $allow_methods = array_merge( $allow_methods, $perms_map['w'] );
        }
        if ( in_array( 'd', $item_pers ) ) {
            $allow_methods = array_merge( $allow_methods, $perms_map['d'] );
        }

        $methods_map = array(
            'GET'    => esc_html__( 'Read', 'mainwp' ),
            'DELETE' => esc_html__( 'Delete', 'mainwp' ),
            'POST'   => esc_html__( 'Write', 'mainwp' ),
            'PUT'    => esc_html__( 'Write', 'mainwp' ),
            'PATCH'  => esc_html__( 'Write', 'mainwp' ),
        );

        $method = $request->get_method();

        if ( empty( $allow_methods ) || ! in_array( $method, $allow_methods ) ) {
            throw new MainWP_Exception( sprintf( esc_html__( 'Sorry, you are not allowed to do the %s method.', 'mainwp' ), ( isset( $methods_map[ $method ] ) ? $methods_map[ $method ] : '' ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return true;
    }

    /**
     * Method rest_api_validate()
     *
     * Hook validate the request.
     *
     * @param bool  $input_value input filter value, it should always be FALSE.
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return bool Whether the api credentials are valid.
     */
    public function rest_api_validate( $input_value, $request ) {
        unset( $input_value );
        $valid = $this->mainwp_validate_request( $request );
        if ( true === $valid ) {
            return true;
        }
        return false;
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
     * @param string $error Error message.
     *
     * @return array $response Array with an error message explaining details are missing.
     */
    public function mainwp_invalid_data_error( $error = '' ) {

        if ( ! empty( $error ) ) {
            $data = array( 'ERROR' => $error );
        } else {
            $data = array( 'ERROR' => esc_html__( 'Required parameter data is is not valid.', 'mainwp' ) );
        }

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

        $data = array( 'SUCCESS' => esc_html__( 'Process ran.', 'mainwp' ) );

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {
            $params = array(
                'selectgroups' => ( isset( $request['selectgroups'] ) && ! empty( $request['selectgroups'] ) ) ? true : false,
            );

            $format              = isset( $request['format'] ) ? $request['format'] : '';
            $params['format']    = $format;
            $params['full_data'] = isset( $request['full_data'] ) ? $request['full_data'] : false;

            // get data.
            $data = MainWP_DB::instance()->get_websites_for_current_user( $params );

            $result = $data;

            if ( 'array' === $format && is_array( $data ) ) {
                $result = array(
                    'data' => $data,
                );
            }

            $response = new \WP_REST_Response( $result );
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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            $data = array();

            $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
            while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                try {
                    $ret = MainWP_Sync::sync_site( $website );
                } catch ( \Exception $e ) {
                    $ret = false;
                }
                $data[ $website->id ] = $ret ? 'success' : 'failed';
            }
            MainWP_DB::free_result( $websites );

            update_option( 'mainwp_last_synced_all_sites', time() );

            $response = new \WP_REST_Response( $data );
            $response->set_status( 200 );

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            $data = array();

            $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
            while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                try {
                    $ret = MainWP_Monitoring_Handler::handle_check_website( $website );
                } catch ( \Exception $e ) {
                    $ret = false;
                }
                $data[ $website->id ] = $ret ? 'success' : 'failed';
            }
            MainWP_DB::free_result( $websites );

            $response = new \WP_REST_Response( $data );
            $response->set_status( 200 );

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
            while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                try {
                    MainWP_Connect::fetch_url_authed( $website, 'disconnect' );
                } catch ( \Exception $e ) {
                    // ok.
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
     * Method mainwp_rest_api_http_status_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: http-status
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/http-status
     * API Method: POST
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_http_status_callback( $request ) {
        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {
            $data     = array();
            $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
            while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                $data[ $website->id ] = $website->http_response_code;
            }
            MainWP_DB::free_result( $websites );
            $response = new \WP_REST_Response( $data );
            $response->set_status( 200 );
        } else {
            // throw common error.
            $response = $this->mainwp_authentication_error();
        }
        return $response;
    }


    /**
     * Method mainwp_rest_api_health_score_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: health-score
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/health-score
     * API Method: POST
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_health_score_callback( $request ) {

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {
            $data                 = array();
            $params['extra_view'] = array( 'health_site_status' );
            $websites             = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
            while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                $health_status = isset( $website->health_site_status ) ? json_decode( $website->health_site_status, true ) : array();
                $hstatus       = MainWP_Utility::get_site_health( $health_status );
                $hval          = $hstatus['val'];
                $critical      = $hstatus['critical'];
                if ( 80 <= $hval && empty( $critical ) ) {
                    $health_score = 'Good';
                } else {
                    $health_score = 'Should be improved';
                }
                $data[ $website->id ] = $health_score;
            }
            MainWP_DB::free_result( $websites );
            $response = new \WP_REST_Response( $data );
            $response->set_status( 200 );
        } else {
            // throw common error.
            $response = $this->mainwp_authentication_error();
        }

        return $response;
    }

    /**
     * Method mainwp_rest_api_security_issues_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: security-issues
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/security-issues
     * API Method: POST
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_security_issues_callback( $request ) {

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {
            $data                 = array();
            $params['extra_view'] = array( 'health_site_status' );
            $websites             = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
            while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                $ret                  = MainWP_Connect::fetch_url_authed( $website, 'security' );
                $data[ $website->id ] = $ret;
            }
            MainWP_DB::free_result( $websites );
            $response = new \WP_REST_Response( $data );
            $response->set_status( 200 );
        } else {
            // throw common error.
            $response = $this->mainwp_authentication_error();
        }

        return $response;
    }

    /**
     * Method mainwp_rest_api_sites_available_updates_count_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: sites-available-updates-count
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/sites-available-updates-count
     * API Method: GET
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_sites_available_updates_count_callback( $request ) {

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {
            $data     = MainWP_Common_Handler::instance()->sites_available_updates_count();
            $response = new \WP_REST_Response( $data );
        } else {
            // throw common error.
            $response = $this->mainwp_authentication_error();
        }

        return $response;
    }

    /**
     * Method mainwp_rest_api_get_sites_by_url_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: get-sites-by-url
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/get-sites-by-url
     * API Method: GET
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_get_sites_by_url_callback( $request ) { // phpcs:ignore -- NOSONAR - complex.

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            $params = array(
                'full_data'    => true,
                'selectgroups' => ( isset( $request['selectgroups'] ) && ! empty( $request['selectgroups'] ) ) ? true : false,
            );

            $format           = isset( $request['format'] ) ? $request['format'] : '';
            $params['format'] = $format;

            if ( isset( $request['urls'] ) && ! empty( $request['urls'] ) ) {
                $params['urls'] = rawurldecode( $request['urls'] );
            }

            // get data.
            $data = MainWP_DB::instance()->get_websites_for_current_user( $params );

            $result = $data;

            if ( 'array' === $format && is_array( $data ) ) {
                $result = array(
                    'data' => $data,
                );
            }

            $response = new \WP_REST_Response( $result );
            $response->set_status( 200 );

        } else {
            // throw common error.
            $response = $this->mainwp_authentication_error();
        }

        return $response;
    }

    /**
     * Method mainwp_rest_api_get_sites_by_client_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: get-sites-by-client
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/sites/get-sites-by-client
     * API Method: GET
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_get_sites_by_client_callback( $request ) { // phpcs:ignore -- NOSONAR - complex.

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            $params = array(
                'full_data'    => true,
                'selectgroups' => ( isset( $request['selectgroups'] ) && 'yes' === $request['selectgroups'] ) ? true : false,
            );

            $format           = isset( $request['format'] ) ? $request['format'] : 'array';
            $params['format'] = $format;

            if ( isset( $request['client_id'] ) && ! empty( $request['client_id'] ) ) {
                $params['client'] = $request['client_id'];
            } elseif ( isset( $request['client'] ) && ! empty( $request['client'] ) ) {
                $params['client'] = rawurldecode( $request['client'] );
            }

            // get data.
            $data = MainWP_DB::instance()->get_websites_for_current_user( $params );

            $result = $data;

            if ( 'array' === $format && is_array( $data ) ) {
                $result = array(
                    'data' => $data,
                );
            }

            $response = new \WP_REST_Response( $result );
            $response->set_status( 200 );

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
    public function mainwp_rest_api_site_callback( $request ) { // phpcs:ignore -- NOSONAR - complex.

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id   = $request['site_id'];
                    $with_tags = isset( $request['with_tags'] ) && $request['with_tags'] ? true : false;

                    $selectGroups = $with_tags ? true : false;

                    // get data.
                    $data = MainWP_DB::instance()->get_website_by_id( $site_id, $selectGroups );

                    if ( ! empty( $data ) && property_exists( $data, 'privkey' ) ) {
                        unset( $data->privkey );
                    }

                    if ( ! empty( $data ) && property_exists( $data, 'adminname' ) ) {
                        unset( $data->adminname );
                    }

                    if ( $with_tags && ! empty( $data ) && property_exists( $data, 'wpgroups' ) && property_exists( $data, 'wpgroupids' ) ) {
                        $wpgroupids = explode( ',', $data->wpgroupids );
                        $wpgroups   = explode( ',', $data->wpgroups );

                        $tags = array();
                        if ( is_array( $wpgroupids ) ) {
                            foreach ( $wpgroupids as $gidx => $groupid ) {
                                if ( $groupid && ! isset( $tags[ $groupid ] ) ) {
                                    $tags[ $groupid ] = isset( $wpgroups[ $gidx ] ) ? $wpgroups[ $gidx ] : '';
                                }
                            }
                        }
                        $data->tags = $tags;
                    }

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    // get data.
                    $website = MainWP_DB::instance()->get_website_by_id( $site_id );
                    $data    = MainWP_DB::instance()->get_website_option( $website, 'site_info' );
                    $data    = ! empty( $data ) ? json_decode( $data, true ) : array();

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
    public function mainwp_rest_api_site_available_updates_callback( $request ) { //phpcs:ignore -- NOSONAR - complex ok.

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    // get data.
                    $website     = MainWP_DB::instance()->get_website_by_id( $site_id, false, array( 'rollback_updates_data' ) );
                    $wp_upgrades = MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' );
                    $wp_upgrades = ! empty( $wp_upgrades ) ? json_decode( $wp_upgrades, true ) : array();

                    $plugin_upgrades      = json_decode( $website->plugin_upgrades, true );
                    $theme_upgrades       = json_decode( $website->theme_upgrades, true );
                    $translation_upgrades = json_decode( $website->translation_upgrades, true );
                    $data                 = array(
                        'wp_core'     => $wp_upgrades,
                        'plugins'     => $plugin_upgrades,
                        'themes'      => $theme_upgrades,
                        'translation' => $translation_upgrades,
                    );

                    $roll_data = MainWP_Updates_Helper::instance()->get_roll_items_updates_of_site( $website );

                    if ( ! empty( $roll_data['plugins'] ) ) {
                        $data['rollback_plugins'] = $roll_data['plugins'];
                    }
                    if ( ! empty( $roll_data['themes'] ) ) {
                        $data['rollback_themes'] = $roll_data['themes'];
                    }

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
    public function mainwp_rest_api_site_available_updates_count_callback( $request ) { // phpcs:ignore -- NOSONAR - complex.

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    // get data.
                    $website      = MainWP_DB::instance()->get_website_by_id( $site_id, false, array( 'rollback_updates_data' ) );
                    $plugins      = json_decode( $website->plugin_upgrades, true );
                    $themes       = json_decode( $website->theme_upgrades, true );
                    $translations = json_decode( $website->translation_upgrades, true );
                    $wp           = MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' );
                    $wp           = ! empty( $wp ) ? json_decode( $wp, true ) : array();

                    if ( ! empty( $wp ) ) {
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

                    $roll_data = MainWP_Updates_Helper::instance()->get_roll_items_updates_of_site( $website );

                    if ( ! empty( $roll_data['plugins'] ) ) {
                        $data['rollback_plugins'] = $roll_data['plugins'];
                    }
                    if ( ! empty( $roll_data['themes'] ) ) {
                        $data['rollback_themes'] = $roll_data['themes'];
                    }

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    // get data.
                    $website = MainWP_DB::instance()->get_website_by_id( $site_id );
                    $data    = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' );
                    $data    = ! empty( $data ) ? json_decode( $data, true ) : array();

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    // get data.
                    $website = MainWP_DB::instance()->get_website_by_id( $site_id );
                    $plugins = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' );
                    $plugins = ! empty( $plugins ) ? json_decode( $plugins, true ) : array();

                    $data = array(
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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    // get data.
                    $website = MainWP_DB::instance()->get_website_by_id( $site_id );
                    $data    = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' );
                    $data    = ! empty( $data ) ? json_decode( $data, true ) : array();

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    // get data.
                    $website = MainWP_DB::instance()->get_website_by_id( $site_id );
                    $themes  = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' );
                    $themes  = ! empty( $themes ) ? json_decode( $themes, true ) : array();

                    $data = array(
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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
    public function mainwp_rest_api_site_health_score_callback( $request ) { // phpcs:ignore -- NOSONAR - complex.

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    // get data.
                    $website       = MainWP_DB::instance()->get_website_by_id( $site_id, false, array( 'health_site_status' ) );
                    $health_status = isset( $website->health_site_status ) ? json_decode( $website->health_site_status, true ) : array();
                    $hstatus       = MainWP_Utility::get_site_health( $health_status );
                    $hval          = $hstatus['val'];
                    $critical      = $hstatus['critical'];
                    if ( 80 <= $hval && empty( $critical ) ) {
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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
     * Callback function for managing the response to API requests made for the endpoint: add-site
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/add-site
     * API Method: POST
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_add_site_callback( $request ) {

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

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
     * Callback function for managing the response to API requests made for the endpoint: edit-site
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/edit-site
     * API Method: PUT
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_edit_site_callback( $request ) {

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {
            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];
                    $website = MainWP_DB::instance()->get_website_by_id( $site_id );
                    try {
                        MainWP_Sync::sync_site( $website );
                    } catch ( \Exception $e ) {
                        // ok.
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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];
                    $website = MainWP_DB::instance()->get_website_by_id( $site_id );
                    try {
                        MainWP_Manage_Sites_View::m_reconnect_site( $website );
                    } catch ( \Exception $e ) {
                        // ok.
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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];
                    $website = MainWP_DB::instance()->get_website_by_id( $site_id );
                    try {
                            MainWP_Connect::fetch_url_authed( $website, 'disconnect' );
                    } catch ( \Exception $e ) {
                        // ok.
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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    // get data.
                    $website = MainWP_DB::instance()->get_website_by_id( $site_id );
                    try {
                        $data = MainWP_Updates_Handler::upgrade_website( $website );
                    } catch ( \Exception $e ) {
                        $data = array( 'error' => MainWP_Error_Helper::get_console_error_message( $e ) );
                    }
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
    public function mainwp_rest_api_site_update_plugins_callback( $request ) { //phpcs:ignore -- NOSONAR - complex.

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    // get data.
                    $website         = MainWP_DB::instance()->get_website_by_id( $site_id );
                    $plugin_upgrades = json_decode( $website->plugin_upgrades, true );
                    $slugs           = array();
                    foreach ( $plugin_upgrades as $slug => $plugin ) {
                        $slugs[] = $slug;
                    }

                    try {
                        $information = MainWP_Connect::fetch_url_authed(
                            $website,
                            'upgradeplugintheme',
                            array(
                                'type' => 'plugin',
                                'list' => urldecode( implode( ',', $slugs ) ),
                            )
                        );

                        $result = Rest_Api_V1_Helper::instance()->handle_site_update_item( $site_id, 'plugin', $information );

                        $data = array(
                            'SUCCESS' => 'Process ran.', // to compatible.
                            'data'    => $result,
                        );

                        $response = new \WP_REST_Response( $data );
                        $response->set_status( 200 );

                    } catch ( \Exception $e ) {
                        return new \WP_Error( 'mainwp_rest_upgrade_plugintheme_error', $e->getMessage() );
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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    // get data.
                    $website        = MainWP_DB::instance()->get_website_by_id( $site_id );
                    $theme_upgrades = json_decode( $website->theme_upgrades, true );
                    $slugs          = array();
                    foreach ( $theme_upgrades as $slug => $theme ) {
                        $slugs[] = $slug;
                    }

                    $information = MainWP_Connect::fetch_url_authed(
                        $website,
                        'upgradeplugintheme',
                        array(
                            'type' => 'theme',
                            'list' => urldecode( implode( ',', $slugs ) ),
                        )
                    );

                    $result = Rest_Api_V1_Helper::instance()->handle_site_update_item( $site_id, 'theme', $information );

                    $data = array(
                        'SUCCESS' => 'Process ran.', // to compatible.
                        'data'    => $result,
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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.

            if ( isset( $request['site_id'] ) && isset( $request['type'] ) && isset( $request['slug'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];
                    $type    = $request['type'];
                    $slug    = $request['slug'];

                    $website     = MainWP_DB::instance()->get_website_by_id( $site_id );
                    $information = MainWP_Connect::fetch_url_authed(
                        $website,
                        'upgradeplugintheme',
                        array(
                            'type' => $type,
                            'list' => urldecode( $slug ),
                        )
                    );

                    $result = Rest_Api_V1_Helper::instance()->handle_site_update_item( $site_id, $type, $information );

                    $data = array(
                        'SUCCESS' => 'Process ran.', // to compatible.
                        'data'    => $result,
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
    public function mainwp_rest_api_site_manage_plugin_callback( $request ) { // phpcs:ignore -- NOSONAR - complex.

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.

            if ( isset( $request['site_id'] ) && isset( $request['plugin'] ) && isset( $request['action'] ) ) {

                if ( 'activate' === $request['action'] || 'deactivate' === $request['action'] || 'delete' === $request['action'] ) {

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
                            // ok.
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
    public function mainwp_rest_api_site_manage_theme_callback( $request ) { // phpcs:ignore -- NOSONAR - complex.

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.

            if ( ! empty( $request['site_id'] ) && ! empty( $request['theme'] ) && ! empty( $request['action'] ) ) {

                if ( 'activate' === $request['action'] || 'deactivate' === $request['action'] || 'delete' === $request['action'] ) {

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
                            // ok.
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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
     * Method mainwp_rest_api_non_mainwp_changes_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: non-mainwp-changes
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/non-mainwp-changes
     * API Method: GET
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_non_mainwp_changes_callback( $request ) {

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    $params = array(
                        'wpid'        => $site_id,
                        'where_extra' => ' AND dismiss = 0 ',
                    );

                    $data = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );

                    $response = new \WP_REST_Response( $data );
                    $response->set_status( 200 );

                } elseif ( 'all' === $request['site_id'] ) {
                    $limit  = apply_filters( 'mainwp_widget_site_actions_limit_number', 10000 );
                    $params = array(
                        'limit'       => $limit,
                        'where_extra' => ' AND dismiss = 0 ',
                    );

                    $data = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );

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
     * Method mainwp_rest_api_non_mainwp_changes_count_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: non-mainwp-changes-count
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/site/non-mainwp-changes-count
     * API Method: GET
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_non_mainwp_changes_count_callback( $request ) {

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

                if ( is_numeric( $request['site_id'] ) ) {

                    $site_id = $request['site_id'];

                    $params = array(
                        'wpid'        => $site_id,
                        'where_extra' => ' AND dismiss = 0 ',
                    );

                    $data = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );

                    $data_count = array(
                        'count' => count( $data ),
                    );

                    $response = new \WP_REST_Response( $data_count );
                    $response->set_status( 200 );

                } elseif ( 'all' === $request['site_id'] ) {
                    $limit  = apply_filters( 'mainwp_widget_site_actions_limit_number', 10000 );
                    $params = array(
                        'limit'       => $limit,
                        'where_extra' => ' AND dismiss = 0 ',
                    );

                    $data = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );

                    $data_count = array(
                        'count' => count( $data ),
                    );

                    $response = new \WP_REST_Response( $data_count );
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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            $all_updates = array();

            $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( true, null, 'wp.url', false, false, null, false, array( 'rollback_updates_data' ) ) );

            while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                $wp_upgrades = MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' );
                $wp_upgrades = ! empty( $wp_upgrades ) ? json_decode( $wp_upgrades, true ) : array();

                $plugin_upgrades             = json_decode( $website->plugin_upgrades, true );
                $theme_upgrades              = json_decode( $website->theme_upgrades, true );
                $translation_upgrades        = json_decode( $website->translation_upgrades, true );
                $all_updates[ $website->id ] = array(
                    'wp_core'     => $wp_upgrades,
                    'plugins'     => $plugin_upgrades,
                    'themes'      => $theme_upgrades,
                    'translation' => $translation_upgrades,
                    'groups'      => $website->wpgroups,
                );
                $roll_data                   = MainWP_Updates_Helper::instance()->get_roll_items_updates_of_site( $website );
                if ( ! empty( $roll_data['plugins'] ) ) {
                    $all_updates[ $website->id ]['rollback_plugins'] = $roll_data['plugins'];
                }
                if ( ! empty( $roll_data['themes'] ) ) {
                    $all_updates[ $website->id ]['rollback_themes'] = $roll_data['themes'];
                }
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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['type'] ) && isset( $request['slug'] ) && isset( $request['name'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {
            // get parameters.
            if ( isset( $request['type'] ) && isset( $request['slug'] ) && isset( $request['name'] ) && isset( $request['site_id'] ) ) {

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['type'] ) && isset( $request['slug'] ) ) {

                $type = $request['type'];
                $slug = $request['slug'];

                MainWP_Updates_Handler::unignore_global_plugins_themes( $type, $slug );

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
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get parameters.
            if ( isset( $request['type'] ) && isset( $request['slug'] ) && isset( $request['site_id'] ) ) {

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

    /**
     * Method mainwp_rest_api_add_client_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: add-client
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/client/add-client
     * API Method: POST
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_add_client_callback( $request ) {

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get data.
            $fields = $request->get_json_params();

            try {
                $data     = MainWP_Client_Handler::rest_api_add_client( $fields );
                $response = new \WP_REST_Response( $data );
                $response->set_status( 200 );
            } catch ( \Exception $e ) {
                $response = $this->mainwp_invalid_data_error( $e->getMessage() );
            }
        } else {
            // throw common error.
            $response = $this->mainwp_authentication_error();
        }

        return $response;
    }


    /**
     * Method mainwp_rest_api_edit_client_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: edit-client
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/client/edit-client
     * API Method: PUT
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_edit_client_callback( $request ) {

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            // get data.
            $fields = $request->get_json_params();

            try {
                $data     = MainWP_Client_Handler::rest_api_add_client( $fields, true );
                $response = new \WP_REST_Response( $data );
                $response->set_status( 200 );
            } catch ( \Exception $e ) {
                $response = $this->mainwp_invalid_data_error( $e->getMessage() );
            }
        } else {
            // throw common error.
            $response = $this->mainwp_authentication_error();
        }

        return $response;
    }

    /**
     * Method mainwp_rest_api_remove_client_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: remove-client
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/client/remove-client
     * API Method: GET
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_remove_client_callback( $request ) {

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {
            $client_id = isset( $request['client_id'] ) ? intval( $request['client_id'] ) : 0;
            if ( ! empty( $client_id ) ) {
                MainWP_DB_Client::instance()->delete_client( $client_id );
                // do common process response.
                $response = $this->mainwp_run_process_success();
            } else {
                $response = $this->mainwp_invalid_data_error();
            }
        } else {
            // throw common error.
            $response = $this->mainwp_authentication_error();
        }

        return $response;
    }

    /**
     * Method mainwp_rest_api_all_clients_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: all-clients
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/clients/all-clients
     * API Method: GET
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_all_clients_callback( $request ) {

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            $params = array(
                'client'        => isset( $request['client'] ) ? $request['client'] : '',
                'custom_fields' => isset( $request['custom_fields'] ) && $request['custom_fields'] ? true : false,
                'with_tags'     => isset( $request['with_tags'] ) && $request['with_tags'] ? true : false,
                'with_contacts' => isset( $request['with_contacts'] ) && $request['with_contacts'] ? true : false,
            );

            // get data.
            $data = MainWP_DB_Client::instance()->get_wp_clients( $params );

            $result = array(
                'data' => $data,
            );

            $response = new \WP_REST_Response( $result );
            $response->set_status( 200 );

        } else {
            // throw common error.
            $response = $this->mainwp_authentication_error();
        }

        return $response;
    }

    /**
     * Method mainwp_rest_api_all_tags_callback()
     *
     * Callback function for managing the response to API requests made for the endpoint: all-tags
     * Can be accessed via a request like: https://yourdomain.com/wp-json/mainwp/v1/tags/all-tags
     * API Method: GET
     *
     * @param array $request The request made in the API call which includes all parameters.
     *
     * @return object $response An object that contains the return data and status of the API request.
     */
    public function mainwp_rest_api_all_tags_callback( $request ) { // phpcs:ignore -- NOSONAR - complex.

        // first validate the request.
        $valid = $this->mainwp_validate_request( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( true === $valid ) {

            $data = array();

            $tag_id   = isset( $request['tag_id'] ) ? intval( $request['tag_id'] ) : 0;
            $tag_name = isset( $request['tag_name'] ) ? (string) $request['tag_name'] : '';

            $groups = MainWP_DB_Common::instance()->get_groups_and_count();

            if ( $groups ) {
                foreach ( $groups as $group ) {
                    if ( ! empty( $tag_id ) ) {
                        if ( (int) $group->id === $tag_id ) {
                            $data[ $group->id ] = $group->name;
                        }
                    } elseif ( ! empty( $tag_name ) ) {
                        if ( $group->name === $tag_name ) {
                            $data[ $group->id ] = $group->name;
                        }
                    } else {
                        $data[ $group->id ] = $group->name;
                    }
                }
            }

            $result = array(
                'data' => $data,
            );

            $response = new \WP_REST_Response( $result );
            $response->set_status( 200 );

        } else {
            // throw common error.
            $response = $this->mainwp_authentication_error();
        }

        return $response;
    }
}
// End of class.
Rest_Api_V1::instance()->init();
