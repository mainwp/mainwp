<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

use MainWP\Dashboard\MainWP_Sync;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Manage_Sites_View;
use MainWP\Dashboard\MainWP_Manage_Sites_Handler;
use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_Monitoring_Handler;
use MainWP\Dashboard\MainWP_Extra_Exception;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Rest_Api_Handle_V1;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_DB_Site_Actions;
use MainWP\Dashboard\MainWP_Logger;
/**
 * Class MainWP_Rest_Sites_Controller
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Sites_Controller extends MainWP_REST_Controller{ //phpcs:ignore -- NOSONAR - multi methods.

    // phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Endpoint namespace.
     *
     * @var string
     */
    protected $namespace = 'mainwp/v2';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'sites';


    /**
     * Constructor.
     */
    public function __construct() {
        add_filter( 'mainwp_rest_routes_sites_controller_filter_allowed_fields_by_context', array( $this, 'hook_filter_allowed_fields_by_context' ), 10, 2 );
        add_filter( 'mainwp_rest_routes_sites_controller_get_allowed_fields_by_context', array( $this, 'hook_get_allowed_fields_by_context' ), 10, 1 );
    }

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
     * Get filter sites.
     *
     * @param object $item item data.
     * @param string $context context.
     *
     * @return object item in context.
     */
    public function hook_filter_allowed_fields_by_context( $item, $context = 'view' ) {
        return $this->filter_response_data_by_allowed_fields( $item, $context );
    }

    /**
     * Get filter sites.
     *
     * @param string $context context.
     *
     * @return object item in context.
     */
    public function hook_get_allowed_fields_by_context( $context = 'view' ) {
        return $this->get_allowed_fields_by_context( $context );
    }

    /**
     * Method register_routes()
     *
     * Creates the necessary endpoints for the api.
     * Note, for a request to be successful the URL query parameters consumer_key and consumer_secret need to be set and correct.
     */
    public function register_routes() { // phpcs:ignore -- NOSONAR - complex.

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/basic',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_basic_items' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/count',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'count_items' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/sync',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'sync_items' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/reconnect',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'reconnect_items' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/disconnect',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'disconnect_sites' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/check',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'check_items' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[\d]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => array(
                        'id_domain' => array(
                            'description' => __( 'Site ID or domain.', 'mainwp' ),
                            'type'        => 'string',
                        ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}$)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => array(
                        'id_domain' => array(
                            'description' => __( 'Site ID or domain.', 'mainwp' ),
                            'type'        => 'string',
                        ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/add',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/edit',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/sync',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'sync_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/security',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'security_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/plugins',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_site_plugins' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/plugins/activate',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'activate_plugins_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/plugins/deactivate',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'deactivate_plugins_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/plugins/delete',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_plugins_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/plugins/abandoned',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_abandoned_plugins_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/themes',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_site_themes' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/themes/activate',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'activate_themes_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/themes/delete',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_themes_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/themes/abandoned',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_abandoned_themes_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/non-mainwp-changes',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_non_mainwp_changes_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/reconnect',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'reconnect_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/disconnect',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'disconnect_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/suspend',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'suspend_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/unsuspend',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'unsuspend_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/check',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'check_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/remove',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
            )
        );

        // Retrieves client Object for the site by site ID or Domain.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/client',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_client_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
            )
        );

        // Retrieves costs for the site by ID or Domain.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/costs',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_costs_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/batch',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'batch_items' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
                ),
                'schema' => array( $this, 'get_public_batch_schema' ),
            )
        );
    }


    /**
     * Get item.
     *
     * @param  WP_REST_Request $request Request object.
     *
     * @return array|mixed Response data, ready for insertion into collection data.
     */
    public function get_item( $request ) {

        $value = isset( $request['id_domain'] ) ? $request['id_domain'] : '';
        $by    = 'domain';

        if ( is_numeric( $value ) ) {
            $by = 'id';
        } else {
            $value = urldecode( $value );
        }

        $with_tags = isset( $request['with_tags'] ) ? mainwp_string_to_bool( $request['with_tags'] ) : false;

        $prepared_args = array(
            'with_tags' => $with_tags,
        );

        $item = $this->get_site_by( $by, $value, $prepared_args );

        if ( is_wp_error( $item ) ) {
            return $item;
        }

        $params   = array(
            'full_data'    => true,
            'selectgroups' => true,
            'include'      => array( $item->id ),
            'fields'       => $this->get_fields_for_response( $request ),
        );
        $websites = MainWP_DB::instance()->get_websites_for_current_user( $params );
        $data     = $websites ? current( $websites ) : array();

        $data = $this->filter_response_data_by_allowed_fields( $this->prepare_item_for_response( $data, $request ), 'view' );
        return rest_ensure_response( array( 'data' => $data ) );
    }



    /**
     * Get all sites.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_items( $request ) {

        $args = $this->prepare_objects_query( $request );
        $args = $this->validate_rest_args( $args, $this->get_validate_args_params( 'get_sites' ) );
        if ( is_wp_error( $args ) ) {
            return $args;
        }

        $args['selectgroups'] = isset( $request['with_tags'] ) ? mainwp_string_to_bool( $request['with_tags'] ) : true;
        $args['full_data']    = isset( $request['full_data'] ) ? mainwp_string_to_bool( $request['full_data'] ) : true;

        // get data.
        $websites = MainWP_DB::instance()->get_websites_for_current_user( $args );

        $data = array();

        if ( $websites ) {
            foreach ( $websites as $site ) {
                $data[] = $this->filter_response_data_by_allowed_fields( $this->prepare_item_for_response( $site, $request ), 'view' );
            }
        }
        return rest_ensure_response(
            array(
                'success' => 1,
                'total'   => count( $data ),
                'data'    => $data,
            )
        );
    }

    /**
     * Get all sites.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_basic_items( $request ) {

        $args = $this->prepare_objects_query( $request );
        $args = $this->validate_rest_args( $args, $this->get_validate_args_params( 'get_sites' ) );

        if ( is_wp_error( $args ) ) {
            return $args;
        }

        $args['selectgroups'] = isset( $request['with_tags'] ) ? mainwp_string_to_bool( $request['with_tags'] ) : false;
        $args['full_data']    = isset( $request['full_data'] ) ? mainwp_string_to_bool( $request['full_data'] ) : false;

        // get data.
        $websites = MainWP_DB::instance()->get_websites_for_current_user( $args );

        $data = array();

        if ( $websites ) {
            foreach ( $websites as $site ) {
                $data[] = $this->filter_response_data_by_allowed_fields( $this->prepare_item_for_response( $site, $request ), 'simple_view' );
            }
        }
        return rest_ensure_response(
            array(
                'success' => 1,
                'total'   => count( $data ),
                'data'    => $data,
            )
        );
    }


    /**
     * Get the query params for collections.
     *
     * @return array
     */
    public function get_collection_params() {
        $params                       = array();
        $params['context']['default'] = 'view';
        return $params;
    }

    /**
     * Count all sites.
     *
     * @param  WP_REST_Request $request Request object.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function count_items( $request ) {
        $args = $this->prepare_objects_query( $request );

        $args = $this->validate_rest_args( $args, $this->get_validate_args_params( 'get_sites' ) );
        if ( is_wp_error( $args ) ) {
            return $args;
        }

        $params = array(
            'exclude' => isset( $args['exclude'] ) ? $args['exclude'] : array(),
            'include' => isset( $args['include'] ) ? $args['include'] : array(),
            'status'  => isset( $args['status'] ) ? $args['status'] : array(),
        );

        // get data.
        $websites = MainWP_DB::instance()->get_websites_for_current_user( $params );
        $data     = array(
            'count' => count( $websites ),
        );
        return rest_ensure_response( $data );
    }

    /**
     * Sync sites.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function sync_item( $request ) {

        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        try {
            $success = MainWP_Sync::sync_site( $website );
        } catch ( \Exception $e ) {
            return new WP_Error( 'mainwp_rest_sync_site_error', $e->getMessage(), array( 'status' => 404 ) );
        }

        $data = array();

        if ( $success ) {
            $params   = array(
                'full_data'    => true,
                'selectgroups' => true,
                'include'      => array( $website->id ),
                'fields'       => $this->get_fields_for_response( $request ),
            );
            $websites = MainWP_DB::instance()->get_websites_for_current_user( $params );
            $data     = $websites ? current( $websites ) : array();
            $data     = $this->filter_response_data_by_allowed_fields( $this->prepare_item_for_response( $data, $request ), 'view' );
        }

        return rest_ensure_response(
            array(
                'success' => $success ? 1 : 0,
                'data'    => $data,
            )
        );
    }


    /**
     * Secure site info.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function security_item( $request ) {

        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        try {
            $result = MainWP_Connect::fetch_url_authed( $website, 'security' );
        } catch ( \Exception $e ) {
            return new WP_Error( 'mainwp_rest_get_secure_site_error', $e->getMessage(), array( 'status' => 404 ) );
        }

        $data = array(
            'data' => $result,
            'site' => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );

        return rest_ensure_response( $data );
    }

    /**
     * Get plugins of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_site_plugins( $request ) { //phpcs:ignore -- NOSONAR - complex.

        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $args = $this->prepare_objects_query( $request );
        $args = $this->validate_rest_args( $args, $this->get_validate_args_params( 'site_plugins' ) );

        if ( is_wp_error( $args ) ) {
            return $args;
        }

        $data = json_decode( $website->plugins, 1 );

        if ( is_array( $data ) ) {
            if ( ! empty( $args['must_use'] ) ) {
                $data = MainWP_Utility::get_sub_array_having( $data, 'mu', 1 );
            }
            if ( ! empty( $args['s'] ) ) {
                $s = trim( $args['s'] );
                if ( ! empty( $s ) ) {
                    $data = mainwp_search_in_array(
                        $data,
                        $s,
                        array(
                            'search_in_key'    => false,
                            'in_sub_fields'    => array( 'name', 'description', 'version', 'slug' ),
                            'use_index_result' => false,
                        )
                    );
                }
            }

            if ( ! empty( $args['status'] ) && is_array( $args['status'] ) && ! in_array( 'any', $args['status'] ) ) {

                $status_act   = in_array( 'active', $args['status'] ) ? 1 : 0;
                $status_inact = in_array( 'inactive', $args['status'] ) ? 1 : 0;

                $status_val = false;
                if ( $status_act && $status_inact ) {
                    $status_val = false;
                } elseif ( $status_act ) {
                    $status_val = 1;
                } elseif ( $status_inact ) {
                    $status_val = 0;
                }

                if ( false !== $status_val ) {
                    $data = MainWP_Utility::get_sub_array_having( $data, 'active', $status_val );
                }
            }
            if ( ! empty( $args['paged'] ) && ! empty( $args['items_per_page'] ) ) {
                $pag  = (int) $args['paged'];
                $per  = (int) $args['items_per_page'];
                $data = MainWP_Utility::get_sub_array_with_limit( $data, $per * ( $pag - 1 ), $per );
            }
        }

        $resp_data = array(
            'success' => 1,
            'total'   => is_array( $data ) ? count( $data ) : 0,
            'data'    => $data,
            'site'    => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );
        return rest_ensure_response( $resp_data );
    }


    /**
     * Handle plugins actions of site.
     *
     * @param object $website object.
     * @param string $action action.
     * @param string $slugs plugins slugs.
     *
     * @return WP_Error|array
     */
    public function handle_plugins_actions_of_site( $website, $action, $slugs ) {
        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'plugin_action',
                array(
                    'action' => $action,
                    'plugin' => implode( '||', $slugs ),
                )
            );

            $success = false;
            $data    = array();
            if ( is_array( $information ) && isset( $information['other_data']['plugin_action_data'] ) ) {
                $action_data = $information['other_data']['plugin_action_data'];
                foreach ( $action_data as $item ) {
                    $success = true;
                    $item    = MainWP_Utility::instance()->sanitize_data( $item );
                    if ( ! empty( $item ) && isset( $item['name'] ) ) {
                        $data[] = array(
                            'name'    => $item['name'],
                            'version' => $item['version'],
                            'slug'    => $item['slug'],
                        );
                    }
                }
            }

            return array(
                'success' => $success ? 1 : 0,
                'data'    => $data,
            );

        } catch ( \Exception $e ) {
            return new WP_Error( "mainwp_rest_{$action}_plugins_action", $e->getMessage() );
        }
    }


    /**
     * Activate plugins of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function activate_plugins_of_site( $request ) {
        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $slugs = isset( $request['slug'] ) ? array_map( 'trim', wp_parse_list( $request['slug'] ) ) : array();
        if ( empty( $slugs ) ) {
            return new WP_Error( 'mainwp_rest_invalid_plugins_slugs', __( 'Plugins slugs are empty or invalid.', 'mainwp' ), 400 );
        }
        $result = $this->handle_plugins_actions_of_site( $website, 'activate', $slugs );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $resp_data = array(
            'success' => ! empty( $result['success'] ) ? 1 : 0,
            'data'    => ! empty( $result['data'] ) ? $result['data'] : array(),
            'site'    => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );

        return rest_ensure_response( $resp_data );
    }

    /**
     * Deactivate plugins of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function deactivate_plugins_of_site( $request ) {
        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $slugs = isset( $request['slug'] ) ? array_map( 'trim', wp_parse_list( $request['slug'] ) ) : array();
        if ( empty( $slugs ) ) {
            return new WP_Error( 'mainwp_rest_invalid_plugins_slugs', __( 'Plugins slug is empty or invalid.', 'mainwp' ), 400 );
        }
        $result = $this->handle_plugins_actions_of_site( $website, 'deactivate', $slugs );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $resp_data = array(
            'success' => ! empty( $result['success'] ) ? 1 : 0,
            'data'    => ! empty( $result['data'] ) ? $result['data'] : array(),
            'site'    => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );

        return rest_ensure_response( $resp_data );
    }

    /**
     * Delete plugins of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function delete_plugins_of_site( $request ) {
        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }
        $slugs = isset( $request['slug'] ) ? array_map( 'trim', wp_parse_list( $request['slug'] ) ) : array();
        if ( empty( $slugs ) ) {
            return new WP_Error( 'mainwp_rest_invalid_plugins_slugs', __( 'Plugins slugs is empty or invalid.', 'mainwp' ), 400 );
        }
        $result = $this->handle_plugins_actions_of_site( $website, 'delete', $slugs );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $resp_data = array(
            'success' => ! empty( $result['success'] ) ? 1 : 0,
            'data'    => ! empty( $result['data'] ) ? $result['data'] : array(),
            'site'    => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );

        return rest_ensure_response( $resp_data );
    }

    /**
     * Get abandoned plugins of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_abandoned_plugins_of_site( $request ) {

        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        // get data.
        $data = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' );
        $data = ! empty( $data ) ? json_decode( $data, true ) : array();

        $resp_data = array(
            'success' => 1,
            'total'   => is_array( $data ) ? count( $data ) : 0,
            'data'    => $data,
            'site'    => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );

        return rest_ensure_response( $resp_data );
    }


    /**
     * Get themes of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_site_themes( $request ) { //phpcs:ignore - NOSONAR - complex.
        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $args = $this->prepare_objects_query( $request );

        $args = $this->validate_rest_args( $args, $this->get_validate_args_params( 'site_themes' ) );
        if ( is_wp_error( $args ) ) {
            return $args;
        }

        if ( isset( $args['status'] ) ) {
            $args['status'] = array_unique( wp_parse_list( $args['status'] ) );
        }

        $data = json_decode( $website->themes, 1 );
        if ( is_array( $data ) ) {
            if ( ! empty( $args['s'] ) ) {
                $s = trim( $args['s'] );
                if ( ! empty( $s ) ) {
                    $data = mainwp_search_in_array(
                        $data,
                        $s,
                        array(
                            'search_in_key'    => false,
                            'in_sub_fields'    => array( 'name', 'description', 'version', 'slug', 'title' ),
                            'use_index_result' => false,
                        )
                    );
                }
            }

            if ( ! empty( $args['status'] ) ) {

                $status_act   = in_array( 'active', $args['status'] ) ? 1 : 0;
                $status_inact = in_array( 'inactive', $args['status'] ) ? 1 : 0;

                $status_val = false;
                if ( $status_act && $status_inact ) {
                    $status_val = false;
                } elseif ( $status_act ) {
                    $status_val = 1;
                } elseif ( $status_inact ) {
                    $status_val = 0;
                }

                if ( false !== $status_val ) {
                    $data = MainWP_Utility::get_sub_array_having( $data, 'active', $status_val );
                }
            }

            if ( ! empty( $args['paged'] ) && ! empty( $args['items_per_page'] ) ) {
                $pag  = (int) $args['paged'];
                $per  = (int) $args['items_per_page'];
                $data = MainWP_Utility::get_sub_array_with_limit( $data, $per * ( $pag - 1 ), $per );
            }
        }

        $resp_data = array(
            'success' => 1,
            'total'   => is_array( $data ) ? count( $data ) : 0,
            'data'    => $data,
            'site'    => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );
        return rest_ensure_response( $resp_data );
    }


    /**
     * Handle plugins actions of site.
     *
     * @param object $website object.
     * @param string $action action.
     * @param string $slugs themes slugs.
     *
     * @return WP_Error|array
     */
    public function handle_theme_actions_of_site( $website, $action, $slugs ) { //phpcs:ignore -- NOSONAR - complex.
        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'theme_action',
                array(
                    'action' => $action,
                    'theme'  => $slugs,
                )
            );

            if ( ! is_array( $information ) ) {
                $information = array();
            }

            $success = 0;
            $data    = array();

            if ( 'delete' === $action && is_array( $information ) && ! empty( $information['error']['is_activated_theme'] ) ) {
                $data['error'] = esc_html__( sprintf( 'The theme %s is active.', $information['error']['is_activated_theme'] ), 'mainwp' );
            }

            if ( 'activate' === $action && isset( $information['other_data']['theme_deactivate_data'] ) ) {
                $success           = 1;
                $act_slug          = 'deactivate';
                $data[ $act_slug ] = array();
                $action_data       = $information['other_data']['theme_deactivate_data'];
                foreach ( $action_data as $item ) {
                    $success = true;
                    $item    = MainWP_Utility::instance()->sanitize_data( $item );
                    if ( ! empty( $item ) && isset( $item['name'] ) ) {
                        $data[ $act_slug ] = array(
                            'name'    => $item['name'],
                            'version' => $item['version'],
                            'slug'    => $item['slug'],
                        );
                    }
                }
            }

            if ( in_array( $action, array( 'activate', 'delete' ) ) && isset( $information['other_data']['theme_action_data'] ) ) {
                $success = 1;
                if ( 'activate' === $action ) {
                    $act_slug = 'activate';
                } else {
                    $act_slug = 'delete';
                }
                $data[ $act_slug ] = array();
                $action_data       = $information['other_data']['theme_action_data'];
                foreach ( $action_data as $item ) {
                    $success = true;
                    $item    = MainWP_Utility::instance()->sanitize_data( $item );
                    if ( ! empty( $item ) && isset( $item['name'] ) ) {
                        $data[ $act_slug ] = array(
                            'name'    => $item['name'],
                            'version' => $item['version'],
                            'slug'    => $item['slug'],
                        );
                    }
                }
            }

            return array(
                'success' => $success,
                'data'    => $data,
            );

        } catch ( \Exception $e ) {
            return new WP_Error( "mainwp_rest_{$action}_plugins_action", $e->getMessage() );
        }
    }


    /**
     * Activate themes of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function activate_themes_of_site( $request ) {
        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $slugs = isset( $request['slug'] ) && is_string( $request['slug'] ) ? trim( $request['slug'] ) : '';
        if ( empty( $slugs ) ) {
            return new WP_Error( 'mainwp_rest_invalid_themes_slugs', __( 'Theme slug is empty or invalid.', 'mainwp' ), 400 );
        }

        $result = $this->handle_theme_actions_of_site( $website, 'activate', $slugs );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $resp_data = array(
            'success' => ! empty( $result['success'] ) ? 1 : 0,
            'data'    => ! empty( $result['data'] ) ? $result['data'] : array(),
            'site'    => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );

        return rest_ensure_response( $resp_data );
    }


    /**
     * Delete themes of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function delete_themes_of_site( $request ) {

        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $slugs = isset( $request['slug'] ) ? array_map( 'trim', wp_parse_list( $request['slug'] ) ) : array();
        if ( empty( $slugs ) ) {
            return new WP_Error( 'mainwp_rest_invalid_themes_slugs', __( 'Theme slug is empty or invalid.', 'mainwp' ), 400 );
        }

        $result = $this->handle_theme_actions_of_site( $website, 'delete', implode( '||', $slugs ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $resp_data = array(
            'success' => ! empty( $result['success'] ) ? 1 : 0,
            'data'    => ! empty( $result['data'] ) ? $result['data'] : array(),
            'site'    => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );

        return rest_ensure_response( $resp_data );
    }


    /**
     * Get abandoned themes of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_abandoned_themes_of_site( $request ) {

        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        // get data.
        $data = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' );
        $data = ! empty( $data ) ? json_decode( $data, true ) : array();

        $resp_data = array(
            'success' => 1,
            'total'   => is_array( $data ) ? count( $data ) : 0,
            'data'    => $data,
            'site'    => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );

        return rest_ensure_response( $resp_data );
    }

    /**
     * Get non-mainwp changes of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_non_mainwp_changes_of_site( $request ) {

        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $params = array(
            'wpid'        => $website->id,
            'where_extra' => ' AND dismiss = 0 ',
            'limit'       => isset( $request['limit'] ) ? intval( $request['limit'] ) : 200,
        );

        $data = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );

        $resp_data = array(
            'success' => 1,
            'total'   => is_array( $data ) ? count( $data ) : 0,
            'data'    => $data,
            'site'    => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );
        return rest_ensure_response( $resp_data );
    }

    /**
     * Sync all sites.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function sync_items( $request ) {

        $args   = $this->prepare_objects_query( $request );
        $params = array(
            'exclude' => isset( $args['exclude'] ) && ! empty( $args['exclude'] ) ? $args['exclude'] : array(),
            'include' => isset( $args['include'] ) && ! empty( $args['include'] ) ? $args['include'] : array(),
        );

        $data     = array();
        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'favi_icon' ), 'no', $params ) );
        while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
            try {
                $ret = MainWP_Sync::sync_site( $website );
            } catch ( \Exception $e ) {
                $ret = false;
            }
            $item                 = $this->filter_response_data_by_allowed_fields( $this->prepare_item_for_response( $website, $request ), 'view' );
            $data[ $website->id ] = array_merge( array( 'result' => $ret ? 'success' : 'failed' ), $item );
        }
        MainWP_DB::free_result( $websites );
        update_option( 'mainwp_last_synced_all_sites', time() );
        return rest_ensure_response(
            array(
                'total' => count( $data ),
                'data'  => $data,
            )
        );
    }

    /**
     * Reconnect site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function reconnect_item( $request ) {
        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }
        $resp_data = array();
        try {
            $ret                  = MainWP_Manage_Sites_View::m_reconnect_site( $website );
            $resp_data['success'] = $ret ? 1 : 0;
        } catch ( \Exception $e ) {
            // failed.
            return new \WP_Error( 'mainwp_rest_reconnect_site_error', esc_html__( sprintf( 'Reconnect Site "%d" error:', $website->id ) ) . ': ' . $e->getMessage() );
        }
        $website           = $this->get_site_by( 'id', $website->id );
        $resp_data['data'] = $this->filter_response_data_by_allowed_fields( $website, 'simple_view' );
        return rest_ensure_response( $resp_data );
    }

    /**
     * Reconnect sites.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function reconnect_items( $request ) {

        $args   = $this->prepare_objects_query( $request );
        $params = array(
            'exclude' => isset( $args['exclude'] ) && ! empty( $args['exclude'] ) ? $args['exclude'] : array(),
            'include' => isset( $args['include'] ) && ! empty( $args['include'] ) ? $args['include'] : array(),
        );

        $data     = array();
        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'favi_icon' ), 'no', $params ) );
        while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) && '' !== $website->sync_errors ) {
            $ret = false;
            try {
                $ret = MainWP_Manage_Sites_View::m_reconnect_site( $website );
            } catch ( \Exception $e ) {
                // failed.
            }
            $data[ $website->id ] = $ret ? 'success' : 'failed';
        }
        MainWP_DB::free_result( $websites );
        return rest_ensure_response(
            array(
                'total' => count( $data ),
                'data'  => $data,
            )
        );
    }


    /**
     * Disconnect site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return array|mixed Response data, ready for insertion into collection data.
     */
    public function disconnect_site( $request ) {

        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $data = $this->filter_response_data_by_allowed_fields( $website, 'simple_view' );

        $success = 0;
        $error   = '';
        try {
            $info    = MainWP_Connect::fetch_url_authed( $website, 'disconnect' );
            $success = is_array( $info ) && isset( $info['result'] ) && 'success' === $info['result'] ? 1 : 0;
            $error   = is_array( $info ) && ! empty( $info['error'] ) ? $info['error'] : '';
        } catch ( \Exception $e ) {
            return new \WP_Error( 'mainwp_rest_disconnect_site_error', esc_html__( sprintf( 'Disconnect Site "%d" error:', $website->id ) ) . ': ' . $e->getMessage() );
        }
        $resp_data = array(
            'success' => $success,
            'data'    => $data,
        );
        if ( ! empty( $error ) ) {
            $resp_data['error'] = $error;
        }
        return rest_ensure_response( $resp_data );
    }


    /**
     * Suspend site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return array|mixed Response data, ready for insertion into collection data.
     */
    public function suspend_item( $request ) {
        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }
        $suspended = 1;
        if ( $website && ! $website->suspended ) { //phpcs:ignore -- to valid.
            $newValues = array(
                'suspended' => $suspended,
            );

            MainWP_DB::instance()->update_website_values( $website->id, $newValues );
            /**
             * Fires immediately after website suspended/unsuspend.
             *
             * @since 4.5.2
             *
             * @param object $website  website data.
             * @param int $suspended The new suspended value.
             */
            do_action( 'mainwp_site_suspended', $website, $suspended );
        }

        $resp_data = array(
            'success'   => 1,
            'suspended' => $suspended,
            'data'      => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );

        return rest_ensure_response( $resp_data );
    }


    /**
     * Unsuspend site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return array|mixed Response data, ready for insertion into collection data.
     */
    public function unsuspend_item( $request ) {
        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }
        $suspended = 0;
        if ( $website && $website->suspended ) { //phpcs:ignore -- to valid.
            $newValues = array(
                'suspended' => $suspended,
            );

            MainWP_DB::instance()->update_website_values( $website->id, $newValues );
            /**
             * Fires immediately after website suspended/unsuspend.
             *
             * @since 4.5.2
             *
             * @param object $website  website data.
             * @param int $suspended The new suspended value.
             */
            do_action( 'mainwp_site_suspended', $website, $suspended );
        }
        $resp_data = array(
            'success'   => 1,
            'suspended' => $suspended,
            'data'      => $this->filter_response_data_by_allowed_fields( $website, 'simple_view' ),
        );
        return rest_ensure_response( $resp_data );
    }

    /**
     * Disconnect sites.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function disconnect_sites( $request ) {

        $args     = $this->prepare_objects_query( $request );
        $params   = array(
            'view'    => 'base_view',
            'exclude' => isset( $args['exclude'] ) && ! empty( $args['exclude'] ) ? $args['exclude'] : array(),
            'include' => isset( $args['include'] ) && ! empty( $args['include'] ) ? $args['include'] : array(),
        );
        $data     = array();
        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user_by_params( $params ) );
        while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
            $info = false;
            try {
                $info = MainWP_Connect::fetch_url_authed( $website, 'disconnect' );
            } catch ( \Exception $e ) {
                // ok.
            }
            $success = 0;
            if ( is_array( $info ) ) {
                $success = isset( $info['result'] ) ? 1 : 0;
            }
            $data[ $website->id ] = $success ? 'success' : 'failed';
        }
        MainWP_DB::free_result( $websites );
        return rest_ensure_response(
            array(
                'total' => count( $data ),
                'data'  => $data,
            )
        );
    }

    /**
     * Check site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function check_item( $request ) {

        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $error = '';
        try {
            $ret = MainWP_Monitoring_Handler::handle_check_website( $website );
        } catch ( \Exception $e ) {
            $ret   = false;
            $error = $e->getMessage();
        }

        $resp_data = array( 'success' => $ret ? 1 : 0 );
        $new_code  = is_array( $ret ) && isset( $ret['httpCode'] ) ? $ret['httpCode'] : false;

        if ( ! empty( $error ) ) {
            $resp_data ['error'] = esc_html( $result );
        }

        $data = $this->filter_response_data_by_allowed_fields( $website, 'simple_view' );

        if ( false !== $new_code ) {
            if ( is_array( $result ) && isset( $result['new_uptime_status'] ) ) {
                $data['status'] = $result['new_uptime_status'];
            } else {
                $data['status'] = MainWP_Monitoring_Handler::get_site_checking_status( $new_code );
            }
            $data['http_code'] = $new_code;
        }

        $resp_data['data'] = $data;

        return rest_ensure_response( $resp_data );
    }

    /**
     * Check sites.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function check_items( $request ) {
        $args     = $this->prepare_objects_query( $request );
        $params   = array(
            'exclude' => isset( $args['exclude'] ) && ! empty( $args['exclude'] ) ? $args['exclude'] : array(),
            'include' => isset( $args['include'] ) && ! empty( $args['include'] ) ? $args['include'] : array(),
        );
        $data     = array();
        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'favi_icon' ), 'no', $params ) );
        while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
            try {
                $ret = MainWP_Monitoring_Handler::handle_check_website( $website );
            } catch ( \Exception $e ) {
                $ret = false;
            }
            $item                 = $this->filter_response_data_by_allowed_fields( $this->prepare_item_for_response( $website, $request ), 'view' );
            $data[ $website->id ] = array_merge( array( 'result' => $ret ? 'success' : 'failed' ), $item );
        }
        MainWP_DB::free_result( $websites );
        return rest_ensure_response(
            array(
                'total' => count( $data ),
                'data'  => $data,
            )
        );
    }

    /**
     * Remove site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function delete_item( $request ) {

        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $error  = '';
        $result = false;

        try {
            $result = MainWP_Manage_Sites_Handler::remove_wp_site( $website->id );
        } catch ( MainWP_Extra_Exception $e ) {
            $error = $e->getMessage();
        }

        $success = ( is_array( $result ) && isset( $result['result'] ) && ( 'success' === $result['result'] || 'removed' === $result['result'] ) ) ? 1 : 0;

        $resp_data = array(
            'success' => $success,
        );

        if ( is_array( $result ) && ! empty( $result['result'] ) ) {
            if ( 'success' === $result['result'] ) {
                $message = esc_html__( 'The site has been removed and the MainWP Child plugin has been disabled.', 'mainwp' );
            } else {
                $message = esc_html__( 'The site has been removed. Please make sure that the MainWP Child plugin has been deactivated properly.', 'mainwp' );
            }
            $resp_data['message'] = $message;
        }

        if ( ! empty( $error ) ) {
            $resp_data['error'] = $error;
        }

        $resp_data['data'] = $this->filter_response_data_by_allowed_fields( $website, 'simple_view' );

        return rest_ensure_response( $resp_data );
    }

    /**
     * Get client of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_client_site( $request ) {
        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $client = false;
        if ( $website->client_id ) {
            $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $website->client_id );
        }

        $resp_data = array(
            'success' => $client ? 1 : 0,
        );

        if ( empty( $client ) ) {
            $resp_data['error'] = esc_html__( 'Client is not set or not found.', 'mainwp' );
        } else {
            $resp_data['data'] = $client;
        }

        return rest_ensure_response( $resp_data );
    }

    /**
     * Get costs of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_costs_site( $request ) {
        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }
        $resp_data = array(
            'success' => 1,
        );
        $costs     = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'site_id', $website->id );
        if ( $costs ) {
            $data               = Cost_Tracker_Rest_Api_Handle_V1::instance()->prepare_api_costs_data( $costs );
            $resp_data['total'] = is_array( $data ) ? count( $data ) : 0;
            $resp_data['data']  = $data;
        } else {
            $resp_data['message'] = esc_html__( 'Cost not found.', 'mainwp' );
            $resp_data['data']    = array();
        }
        return rest_ensure_response( $resp_data );
    }

    /**
     * Get site by.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|Object Item.
     */
    public function get_request_item( $request ) {
        return $this->get_site_item( $request );
    }


    /**
     * Get the location schema, conforming to JSON Schema.
     *
     * @since  3.5.0
     * @return array
     */
    public function get_item_schema() { //phpcs:ignore -- NOSONAR - long function.
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'sites',
            'type'       => 'object',
            'properties' => array(
                'id'                     => array(
                    'type'        => 'integer',
                    'description' => __( 'Site ID.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'wp_parse_id_list',
                    ),
                ),
                'name'                   => array(
                    'type'        => 'string',
                    'description' => __( 'Site name.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
                'url'                    => array(
                    'type'        => 'string',
                    'description' => __( 'Site url.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
                'tags'                   => array( // response as tags.
                    'type'        => 'string',
                    'description' => __( 'Site tags.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'note'                   => array(
                    'type'        => 'string',
                    'description' => __( 'Site note.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'ip'                     => array(
                    'type'        => 'string',
                    'description' => __( 'Site IP.', 'mainwp' ),
                    'context'     => array( 'view' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
                'client_id'              => array(
                    'type'        => 'integer',
                    'default'     => 0,
                    'description' => __( 'Client id', 'mainwp' ),
                    'context'     => array( 'view', 'simple_view' ),
                ),
                'is_staging'             => array(
                    'type'        => 'integer',
                    'default'     => 0,
                    'description' => __( 'is staging', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'wp_version'             => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'wp version', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'php_version'            => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'php version', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'child_version'          => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'child version', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'mysql_version'          => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'mysql version', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'memory_limit'           => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'memory limit', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'database_size'          => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Database size', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'active_theme'           => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Active theme', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'status'                 => array(
                    'type'        => array( 'string', 'array' ),
                    'default'     => '',
                    'description' => __( 'Status', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'sync_errors'            => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Sync errors', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'http_status'            => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Http status', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'health_status'          => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Health status', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'health_score'           => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Health score', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'icon'                   => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Icon', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'last_sync'              => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Last sync', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'plugins'                => array(
                    'type'        => array( 'string', 'array' ),
                    'default'     => '',
                    'description' => __( 'Plugins', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'themes'                 => array(
                    'type'        => array( 'string', 'array' ),
                    'default'     => '',
                    'description' => __( 'Themes', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'last_post_time'         => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Last post time', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'recent_posts'           => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Recent posts', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'recent_pages'           => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Recent pages', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'recent_comments'        => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Recent comments', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'categories'             => array(
                    'type'        => 'string',
                    'default'     => '',
                    'description' => __( 'Categories', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'wp_upgrades'            => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'plugin_upgrades'        => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'premium_upgrades'       => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'theme_upgrades'         => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'translation_upgrades'   => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'ignored_plugins'        => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'ignored_themes'         => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'automatic_update'       => array(
                    'type'        => 'integer',
                    'default'     => 0,
                    'description' => __( 'Automatic update', 'mainwp' ),
                    'context'     => array( 'edit' ),
                    'enum'        => array( 0, 1 ),
                    'arg_options' => array(
                        'sanitize_callback' => 'absint',
                    ),
                ),
                'http_user'              => array(
                    'type'        => 'string',
                    'description' => __( 'HTTP user', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
                'http_pass'              => array(
                    'type'        => 'string',
                    'description' => __( 'HTTP password', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ), // no need to sanitize pass.
                ),
                'uniqueId'               => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'disable_health_check'   => array(
                    'type'        => 'integer',
                    'default'     => 0,
                    'description' => __( 'Disable health check', 'mainwp' ),
                    'context'     => array( 'edit' ),
                    'enum'        => array( 0, 1 ),
                    'arg_options' => array(
                        'sanitize_callback' => 'absint',
                    ),
                ),
                'health_threshold'       => array(
                    'type'        => 'integer',
                    'default'     => 0,
                    'description' => __( 'Health threshold', 'mainwp' ),
                    'context'     => array( 'edit' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'absint',
                    ),
                ),
                'is_ignoreCoreUpdates'   => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'is_ignorePluginUpdates' => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'is_ignoreThemeUpdates'  => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'verify_certificate'     => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'force_use_ipv4'         => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'ssl_version'            => array(
                    'type'    => 'string',
                    'default' => '',
                    'context' => array( 'view' ),
                ),
                'suspended'              => array(
                    'type'        => 'integer',
                    'default'     => 0,
                    'description' => __( 'Suspended', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                    'enum'        => array( 0, 1 ),
                    'arg_options' => array(
                        'sanitize_callback' => 'absint',
                    ),
                ),

            ),
        );
    }


    /**
     * Prepare a single order for create or update.
     *
     * @throws MainWP_Rest_Data_Exception When fails to set any item.
     * @param  WP_REST_Request $request Request object.
     * @return array
     */
    protected function prepare_object_for_database( $request ) {
        $item_fields                   = array();
        $item_fields['url']            = isset( $request['url'] ) ? sanitize_text_field( wp_unslash( $request['url'] ) ) : '';
        $item_fields['name']           = isset( $request['name'] ) ? sanitize_text_field( wp_unslash( $request['name'] ) ) : '';
        $item_fields['wpadmin']        = isset( $request['admin'] ) ? sanitize_text_field( wp_unslash( $request['admin'] ) ) : '';
        $item_fields['adminpwd']       = isset( $request['adminpassword'] ) ? wp_unslash( $request['adminpassword'] ) : '';
        $item_fields['unique_id']      = isset( $request['uniqueid'] ) ? sanitize_text_field( wp_unslash( $request['uniqueid'] ) ) : '';
        $item_fields['ssl_verify']     = empty( $request['ssl_verify'] ) ? false : intval( $request['ssl_verify'] );
        $item_fields['force_use_ipv4'] = isset( $request['force_use_ipv4'] ) && mainwp_string_to_bool( $request['force_use_ipv4'] ) ? 1 : 0;
        $item_fields['http_user']      = isset( $request['http_user'] ) ? sanitize_text_field( wp_unslash( $request['http_user'] ) ) : '';
        $item_fields['http_pass']      = isset( $request['http_pass'] ) ? wp_unslash( $request['http_pass'] ) : '';
        $item_fields['groupids']       = isset( $request['groupids'] ) && ! empty( $request['groupids'] ) ? explode( ',', sanitize_text_field( wp_unslash( $request['groupids'] ) ) ) : array();

        /**
         * Filters an object before it is inserted via the REST API.
         *
         * @since 5.2
         *
         * @param array         $item_fields    Site data.
         * @param WP_REST_Request $request  Request object.
         */
        return apply_filters( 'mainwp_rest_pre_insert_site_item', $item_fields, $request );
    }

    /**
     * Prepare a single order for create or update.
     *
     * @param  WP_REST_Request $request Request object.
     * @return array
     */
    protected function prepare_object_for_update( $request ) {

        $map_fields_update = array(
            'http_user'              => 'http_user',
            'http_pass'              => 'http_pass',
            'name'                   => 'name',
            'adminname'              => 'admin',
            'ssl_version'            => 'sslversion',
            'uniqueId'               => 'uniqueid',
            'protocol'               => 'protocol',
            'disable_health_check'   => 'disablehealthchecking',
            'health_threshold'       => 'healththreshold',
            'suspended'              => 'suspended',
            'groupids'               => 'groupids',
            'automatic_update'       => 'automatic_update',
            'backup_before_upgrade'  => 'backup_before_upgrade',
            'force_use_ipv4'         => 'force_use_ipv4',
            'is_ignoreCoreUpdates'   => 'ignore_core_updates',
            'is_ignorePluginUpdates' => 'ignore_plugin_updates',
            'is_ignoreThemeUpdates'  => 'ignore_theme_updates',
            'monitoring_emails'      => 'monitoring_emails',
        );

        $data = array();
        foreach ( $map_fields_update as $field ) {
            if ( isset( $request[ $field ] ) ) {
                $data[ $field ] = $request[ $field ];
            }
        }

        /**
         * Filters an object before it is inserted via the REST API.
         *
         * @since 5.2
         *
         * @param array         $item_fields    Site data.
         * @param WP_REST_Request $request  Request object.
         */
        return apply_filters( 'mainwp_rest_pre_update_site_item', $data, $request );
    }

    /**
     * Get formatted item object.
     *
     * @since  5.2
     * @param  object $site_object Site object.
     * @param  array  $args Request args.
     *
     * @return array
     */
    protected function get_pre_formatted_item_data( $site_object, $args = array() ) {

        $fields = false;
        // Determine if the response fields were specified.
        if ( ! empty( $this->request['_fields'] ) ) {
            $fields = wp_parse_list( $this->request['_fields'] );

            if ( 0 === count( $fields ) ) {
                $fields = false;
            } else {
                $fields = array_map( 'trim', $fields );
            }
        }

        $extra_fields = array();
        $extra_fields = false === $fields ? $extra_fields : array_intersect( $extra_fields, $fields );

        $formatted_data = array(
            'id'        => $site_object->id,
            'url'       => $site_object->url,
            'name'      => $site_object->name,
            'client_id' => $site_object->client_id,
        );

        if ( ! empty( $args['selectgroups'] ) ) {
            $formatted_data['wpgroups'] = $site_object->wpgroups;
        }

        if ( ! empty( $args['full_data'] ) ) {
            $formatted_extra = array(
                'id'                   => $site_object->id,
                'url'                  => $site_object->url,
                'name'                 => $site_object->name,
                'offline_checks_last'  => $site_object->offline_checks_last,
                'offline_check_result' => $site_object->offline_check_result, // 1 - online, -1 offline.
                'http_response_code'   => $site_object->http_response_code,
                'disable_health_check' => $site_object->disable_health_check,
                'health_threshold'     => $site_object->health_threshold,
                'note'                 => $site_object->note,
                'plugin_upgrades'      => $site_object->plugin_upgrades,
                'theme_upgrades'       => $site_object->theme_upgrades,
                'translation_upgrades' => $site_object->translation_upgrades,
                'securityIssues'       => $site_object->securityIssues,
                'themes'               => $site_object->themes,
                'plugins'              => $site_object->plugins,
                'automatic_update'     => ! empty( $site_object->automatic_update ) ? $site_object->automatic_update : 0,
                'sync_errors'          => $site_object->sync_errors,
                'last_post_gmt'        => $site_object->last_post_gmt,
                'health_value'         => $site_object->health_value,
                'phpversion'           => $site_object->phpversion,
                'wp_upgrades'          => $site_object->wp_upgrades,
                'security_stats'       => $site_object->security_stats,
                'client_id'            => $site_object->client_id,
                'adminname'            => $site_object->adminname,
                'http_user'            => $site_object->http_user,
                'http_pass'            => $site_object->http_pass,
                'ssl_version'          => $site_object->ssl_version,
                'signature_algo'       => $site_object->signature_algo,
                'verify_method'        => $site_object->verify_method,
                'verify_certificate'   => $site_object->verify_certificate,
            );

            $format_date = array( 'dtsAutomaticSync', 'dtsAutomaticSyncStart', 'dtsSync', 'dtsSyncStart' );
            // Format date values.
            foreach ( $format_date as $key ) {
                // Date created is stored UTC, date modified is stored WP local time.
                $datetime                = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $site_object->$key ) );
                $formatted_extra[ $key ] = mainwp_rest_prepare_date_response( $datetime, false );
            }

            $formatted_data = array_merge( $formatted_data, $formatted_extra );
        }

        return $formatted_data;
    }

    /**
     * Prepare a single product review output for response.
     *
     * @param object          $item site object.
     * @param WP_REST_Request $request Request object.
     * @param array           $args args.
     *
     * @return WP_REST_Response $response Response data.
     */
    public function prepare_item_for_response( $item, $request, $args = array() ) { //phpcs:ignore -- NOSONAR - complex.

        $fields = $this->get_fields_for_response( $request );

        if ( ! is_array( $fields ) ) {
            $fields = array();
        }

        $context = ! empty( $request['context'] ) ? $request['context'] : 'view';

        $data = $this->filter_response_by_context( $item, $context );

        if ( is_object( $data ) ) {
            $_data = array();
            foreach ( $data as $field => $value ) {
                $_data[ $field ] = $value;
            }
            $data = $_data;
        }

        if ( in_array( 'tags', $fields ) && property_exists( $item, 'wpgroups' ) && property_exists( $item, 'wpgroupids' ) ) {
            $wpgroupids = ! empty( $item->wpgroupids ) ? explode( ',', $item->wpgroupids ) : array();
            $wpgroups   = ! empty( $item->wpgroups ) ? explode( ',', $item->wpgroups ) : array();

            $tags = array();
            if ( is_array( $wpgroupids ) ) {
                foreach ( $wpgroupids as $gidx => $groupid ) {
                    if ( $groupid && ! isset( $tags[ $groupid ] ) ) {
                        $tags[ $groupid ] = isset( $wpgroups[ $gidx ] ) ? $wpgroups[ $gidx ] : '';
                    }
                }
            }
            $data['tags'] = $tags;
        }

        $map_fields = array(
            'database_size'  => 'dbsize',
            'http_status'    => 'http_response_code',
            'health_score'   => 'health_value',
            'icon'           => 'cust_site_icon_info',
            'last_sync'      => 'dtsSync',
            'last_post_time' => 'last_post_gmt',
        );

        if ( is_array( $data ) ) {
            foreach ( $map_fields as $field1 => $field2 ) {
                if ( in_array( $field1, $fields ) && property_exists( $item, $field2 ) ) {
                    $data[ $field1 ] = $item->{$field2};
                }
            }
        }

        $website_info = MainWP_DB::instance()->get_website_option( $item->id, 'site_info' );
        $website_info = ! empty( $website_info ) ? json_decode( $website_info, true ) : array();

        if ( is_array( $website_info ) && property_exists( $item, 'http_response_code' ) ) {
            $code        = $item->http_response_code;
            $code_string = MainWP_Utility::get_http_codes( $code );
            if ( ! empty( $code_string ) ) {
                $code .= ' - ' . $code_string;
            }
            $website_info['http_status'] = $code;
        }

        $map_meta_fields = array(
            'wp_version'    => 'wpversion',
            'php_version'   => 'phpversion',
            'child_version' => 'child_version',
            'mysql_version' => 'mysql_version',
            'memory_limit'  => 'memory_limit',
            'http_status'   => 'http_status',
        );

        foreach ( $map_meta_fields as $_field1 => $_field2 ) {
            if ( in_array( $_field1, $fields ) ) {
                $data[ $_field1 ] = isset( $website_info[ $_field2 ] ) ? $website_info[ $_field2 ] : '';
            }
        }

        if ( in_array( 'active_theme', $fields ) ) {
            $active_theme = array();
            if ( ! empty( $item->themes ) ) {
                $themes = json_decode( $item->themes, 1 );
                if ( is_array( $themes ) && ! empty( $themes ) ) {
                    foreach ( $themes as $theme ) {
                        if ( ! empty( $theme['active'] ) ) {
                            $active_theme = $theme;
                            break;
                        }
                    }
                }
            }
            $data['active_theme'] = is_array( $active_theme ) && ! empty( $active_theme['name'] ) ? esc_html( $active_theme['name'] . ' ' . $active_theme['version'] ) : '';
        }

        if ( in_array( 'status', $fields ) ) {
            $data['status'] = ! empty( $item->sync_errors ) ? 'disconnected' : 'connected';
            if ( $item->suspended ) {
                $data['status'] = 'suspended';
            }
        }

        if ( is_array( $fields ) && ! empty( $fields ) ) {
            $_data = array();
            foreach ( $data as $field => $value ) {
                if ( in_array( $field, $fields ) ) {
                    $_data[ $field ] = $value;
                }
            }
            $data = $_data;
        }

        if ( isset( $data['last_sync'] ) ) {
            $data['last_sync'] = mainwp_rest_prepare_date_response( $data['last_sync'] );
        }

        /**
         * Filterobject returned from the REST API.
         *
         * @param array $data The object.
         * @param mixed       $item   The object used to create response.
         * @param WP_REST_Request  $request  Request object.
         */
        return apply_filters( 'mainwp_rest_prepare_site', $data, $item, $request );
    }

    /**
     * Add Site.
     *
     * @since  5.2
     * @throws Exception But all errors are validated before returning any data.
     *
     * @param  WP_REST_Request $request  Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function create_item( $request ) {  //phpcs:ignore -- NOSONAR - complex.
        $resp_data            = array();
        $resp_data['success'] = 0;
        $site_id              = 0;
        $url                  = '';
        $data                 = array(); //phpcs:ignore -- NOSONAR - not used data.
        $found_id             = 0;
        try {
            $item = $this->prepare_object_for_database( $request );

            if ( empty( $item['name'] ) && ! empty( $item['url'] ) ) {
                $item['name'] = MainWP_Utility::remove_http_prefix( $item['url'], true );
                $item['name'] = rtrim( $item['name'], '/' );
            }

            $url     = $item['url'];
            $website = MainWP_DB::instance()->get_websites_by_url( $item['url'] );
            list( $message, $error, $site_id, $found_id ) = MainWP_Manage_Sites_View::add_wp_site( $website, $item );

            if ( ! empty( $site_id ) ) {
                $site                 = MainWP_DB::instance()->get_website_by_id( $site_id );
                $resp_data['success'] = 1;
                $resp_data['data']    = $this->filter_response_data_by_allowed_fields( $site, 'simple_view' );

                if ( ! empty( $message ) ) {
                    $resp_data['message'] = wp_strip_all_tags( $message );
                }
            } elseif ( ! empty( $error ) ) {
                $resp_data['error'] = wp_strip_all_tags( $error );
            } else {
                $resp_data['error'] = esc_html__( 'Add site failed. Please try again.', 'mainwp' );
            }
        } catch ( Exception $e ) {
            $resp_data['error'] = wp_strip_all_tags( $e->getMessage() );
        }

        $user                 = $this->get_rest_api_user();
        $is_dashboard_connect = ! empty( $user ) && 1 === (int) $user->key_type ? true : false;

        if ( $is_dashboard_connect ) {
            if ( ! empty( $resp_data['error'] ) ) {
                MainWP_Logger::instance()->log_action( 'Dashboard Connect add Site :: [url=' . $url . '] :: [result=Failed] :: [error=' . esc_html( $resp_data['error'] ) . ']', MainWP_Logger::CONNECT_LOG_PRIORITY );
            } elseif ( ! empty( $resp_data['success'] ) ) {
                MainWP_Logger::instance()->log_action( 'Dashboard Connect add Site :: [url=' . $url . '] :: [result=Success]', MainWP_Logger::CONNECT_LOG_PRIORITY );
            }
        }

        if ( ! empty( $found_id ) ) {
            $resp_data['found_id'] = $found_id;
            // if found connected and is dashboard connect request then try to reconnect to prevent incorrect connection.
            if ( $is_dashboard_connect ) {
                $reconnect = false;
                $site      = MainWP_DB::instance()->get_website_by_id( $found_id );
                try {
                    $reconnect                      = MainWP_Manage_Sites_View::m_reconnect_site( $site );
                    $resp_data['reconnect_success'] = $reconnect ? 1 : 0;
                } catch ( \Exception $e ) {
                    // failed.
                    $resp_data['reconnect_error'] = $e->getMessage();
                }
                MainWP_Logger::instance()->log_action( 'Dashboard Connect reconnect site :: [url=' . $url . '] :: [result=' . ( $reconnect ? 'success' : 'failed' ) . ']', MainWP_Logger::CONNECT_LOG_PRIORITY );
            }
        }

        return rest_ensure_response( $resp_data );
    }

    /**
     * Update site.
     *
     * @param  WP_REST_Request $request Request object.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_item( $request ) {
        $website = $this->get_request_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }
        $resp_data            = array();
        $resp_data['success'] = 0;
        try {
            $item   = $this->prepare_object_for_update( $request );
            $result = MainWP_DB_Common::instance()->rest_api_update_website( $website->id, $item );
            if ( is_array( $result ) && ! empty( $result['success'] ) ) {
                    $resp_data['success'] = 1;
                    $params               = array(
                        'full_data'    => true,
                        'selectgroups' => true,
                        'include'      => array( $website->id ),
                        'fields'       => $this->get_fields_for_response( $request ),
                    );
                    $websites             = MainWP_DB::instance()->get_websites_for_current_user( $params );
                    $data                 = $websites ? current( $websites ) : array();
                    $resp_data['data']    = $this->filter_response_data_by_allowed_fields( $this->prepare_item_for_response( $data, $request ), 'view' );
            } else {
                $resp_data['error'] = esc_html__( 'Update site failed. Please try again.', 'mainwp' );
            }
        } catch ( \Exception $e ) {
            $resp_data['error'] = $e->getMessage();
        }
        return rest_ensure_response( $resp_data );
    }
}
