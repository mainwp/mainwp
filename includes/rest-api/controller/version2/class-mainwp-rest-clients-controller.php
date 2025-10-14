<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Client_Handler;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Rest_Api_Handle_V1;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class MainWP_Rest_Clients_Controller
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Clients_Controller extends MainWP_REST_Controller { //phpcs:ignore -- NOSONAR - multi methods.

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
    protected $rest_base = 'clients';

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
     * Constructor.
     */
    public function __construct() {
        add_filter( 'mainwp_rest_routes_clients_controller_filter_allowed_fields_by_context', array( $this, 'hook_filter_allowed_fields_by_context' ), 10, 2 );
        add_filter( 'mainwp_rest_client_fields_object_query', array( $this, 'client_fields_custom_query_args' ), 10, 2 );
    }

    /**
     * Get filter client.
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
     * Add custom query args.
     *
     * @param array           $args    Query args.
     * @param WP_REST_Request $request Request object.
     *
     * @return array
     */
    public function client_fields_custom_query_args( $args, $request ) {
        if ( ! empty( $request['client_id'] ) ) {
            $args['client_id'] = $request['client_id'];
        }

        return $args;
    }

    /**
     * Method register_routes()
     *
     * Creates the necessary endpoints for the api.
     * Note, for a request to be successful the URL query parameters consumer_key and consumer_secret need to be set and correct.
     */
	public function register_routes() { // phpcs:ignore -- NOSONAR - complex.

        // Retrieves all clients.
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

        // Retrieves the number of clients.
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

        // Adds new client.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/add',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
            )
        );

        // Retrieves client by ID or Email.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_email>(?!fields$)[\d]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Retrieves client by ID or Email.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_email>(?!fields$)[a-zA-Z0-9\.\_\%\+\-\@]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Edit client by ID or Email.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_email>(?!fields$)[a-zA-Z0-9\_\-\.\@]+)/edit',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Removes client by ID or Email from the Dashboard.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_email>(?!fields$)[a-zA-Z0-9\_\-\.\@]+)/remove',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Retrieves all sites for a client by client ID or Email.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_email>(?!fields$)[a-zA-Z0-9\_\-\.\@]+)/sites',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_sites_client' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Retrieves all costs for a client by client ID or Email.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_email>(?!fields$)[a-zA-Z0-9\_\-\.\@]+)/costs',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_costs_client' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Retrieves the number of sites for a client by client ID or Email.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_email>(?!fields$)[a-zA-Z0-9\_\-\.\@]+)/sites/count',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'count_sites_client' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Suspends client by ID or Domain.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_email>(?!fields$)[a-zA-Z0-9\_\-\.\@]+)/suspend',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'suspend_client' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Unsuspends client by ID or Domain.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_email>(?!fields$)[a-zA-Z0-9\_\-\.\@]+)/unsuspend',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'unsuspend_client' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
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

        // Client Fields.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/fields',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'client_fields' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_client_fields_allowed_fields(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/fields/add',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_client_fields' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->create_client_fields_allowed_fields(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/fields/(?P<id_name>[^/]+)/edit',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'edit_client_fields' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->edit_client_fields_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/fields/(?P<id_name>[^/]+)/delete',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_client_fields' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );
    }

    /**
     * Get site by.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|bool|Object Item.
     */
    public function get_request_item( $request ) {
        $route = $request->get_route();

        if ( MainWP_Utility::string_ends_by( $route, '/batch' ) ) {
            $by    = 'id';
            $value = intval( $request['id'] );
        } else {
            $value = $request['id_email'];
            $by    = 'email';
            if ( is_numeric( $value ) ) {
                $by = 'id';
            } else {
                $value = wp_unslash( $value );
            }
        }
        return $this->get_client_by( $by, $value );
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
     * Get formatted item object.
     *
     * @since  5.2
     * @param  array $data data object.
     *
     * @return array
     */
    protected function get_formatted_item_data( $data ) {
        if ( ! empty( $data['created'] ) ) {
            $data['created'] = mainwp_rest_prepare_date_response( $data['created'] );
        }
        if ( isset( $data['client_id'] ) && ! isset( $data['field_id'] ) ) {
            $data['id'] = $data['client_id'];
            unset( $data['client_id'] );
        }
        return $data;
    }


    /**
     * Get Client by.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_item( $request ) {

        $item = $this->get_request_item( $request );

        if ( is_wp_error( $item ) ) {
            return $item;
        }

        $resp_data = array(
            'success' => $item ? 1 : 0,
            'data'    => $this->filter_response_data_by_allowed_fields( $item, 'simple_view' ),
        );

        return rest_ensure_response( $resp_data );
    }


    /**
     * Get all Clients.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
	public function get_items( $request ) { //phpcs:ignore -- NOSONAR - complex.

        $args = $this->prepare_objects_query( $request );

        $status = isset( $args['status'] ) ? wp_parse_list( $args['status'] ) : array();
        $status = array_filter( array_map( 'trim', $status ) );

        if ( empty( $status ) ) {
            $status[] = 'all';
        }

        $all = is_array( $status ) && in_array( 'all', $status ) ? true : false;

        $s        = isset( $args['s'] ) ? trim( $args['s'] ) : '';
        $includ   = isset( $args['include'] ) ? $args['include'] : '';
        $exclud   = isset( $args['exclude'] ) ? $args['exclude'] : '';
        $page     = isset( $args['paged'] ) ? intval( $args['paged'] ) : false;
        $per_page = isset( $args['items_per_page'] ) ? intval( $args['items_per_page'] ) : false;

        $prepared_args = array(
            's'             => ! empty( $s ) ? $s : '',
            'include'       => ! empty( $includ ) ? $includ : array(),
            'exclude'       => ! empty( $exclud ) ? $exclud : array(),
            'status'        => $all ? array() : $status,
            'custom_fields' => isset( $request['custom_fields'] ) && $request['custom_fields'] ? true : false,
            'with_tags'     => isset( $request['with_tags'] ) && $request['with_tags'] ? true : false,
            'with_contacts' => isset( $request['with_contacts'] ) && $request['with_contacts'] ? true : false,
        );

        if ( false !== $page ) {
            $prepared_args['page'] = $page;
        }
        if ( false !== $per_page ) {
            $prepared_args['per_page'] = $per_page;
        }

        // get data.
        $clients = MainWP_DB_Client::instance()->get_wp_clients( $prepared_args );
        $data    = array();
        if ( is_array( $clients ) ) {
            foreach ( $clients as $client ) {
                $data[] = $this->filter_response_data_by_allowed_fields( $client );
            }
        }
        return rest_ensure_response(
            array(
                'success' => 1,
                'total'   => is_array( $data ) ? count( $data ) : 0,
                'data'    => $data,
            )
        );
    }

    /**
     * Count all Clients.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function count_items( $request ) {
        $prepared_args = array(
            'count_only' => true,
        );
        // get data.
        $value = MainWP_DB_Client::instance()->get_wp_clients( $prepared_args );
        return rest_ensure_response(
            array(
                'success' => 1,
                'total'   => $value,
            )
        );
    }

    /**
     * Adds new client.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function create_item( $request ) {
        $resp_data            = array();
        $resp_data['success'] = 0;
        try {
            $item = array_filter( $request->get_params() );
            if ( isset( $item['selected_sites'] ) ) {
                $item['selected_sites'] = array_unique( wp_parse_list( $item['selected_sites'] ) );
            }
            $result = MainWP_Client_Handler::rest_api_add_client( $item );
            if ( is_array( $result ) && isset( $result['clientid'] ) ) {
                $client               = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $result['clientid'], OBJECT, array( 'with_selected_sites' => true ) );
                $resp_data['success'] = 1;
                $resp_data['message'] = esc_html__( 'Client created Successfully.', 'mainwp' );
                $resp_data['data']    = $this->filter_response_data_by_allowed_fields( $client, 'simple_view', array( 'selected_sites' ) );
            } elseif ( is_array( $result ) && ! empty( $result['error'] ) ) {
                $resp_data['error'] = wp_strip_all_tags( $result['error'] );
            } else {
                $resp_data['error'] = esc_html__( 'Add client failed. Please try again.', 'mainwp' );
            }
        } catch ( Exception $e ) {
            $resp_data['error'] = wp_strip_all_tags( $e->getMessage() );
        }
        return rest_ensure_response( $resp_data );
    }


    /**
     * Update new client.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function update_item( $request ) {

        $item = $this->get_request_item( $request );

        if ( is_wp_error( $item ) ) {
            return $item;
        }

        $resp_data            = array();
        $resp_data['success'] = 0;
        try {
            $data = array_filter( $request->get_params() );
            if ( is_array( $data ) ) {
                $data['client_id'] = $item->client_id;
            }
            if ( isset( $data['selected_sites'] ) ) {
                $data['selected_sites'] = array_unique( wp_parse_list( $data['selected_sites'] ) );
            }
            $result = MainWP_Client_Handler::rest_api_add_client( $data, true );
            if ( is_array( $result ) && isset( $result['clientid'] ) ) {
                $client               = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $result['clientid'], OBJECT, array( 'with_selected_sites' => true ) );
                $resp_data['success'] = 1;
                $resp_data['message'] = esc_html__( 'Client updated Successfully.', 'mainwp' );
                $resp_data['data']    = $this->filter_response_data_by_allowed_fields( $client, 'simple_view', array( 'selected_sites' ) );
            } elseif ( is_array( $result ) && ! empty( $result['error'] ) ) {
                $resp_data['error'] = wp_strip_all_tags( $result['error'] );
            } else {
                $resp_data['error'] = esc_html__( 'Update client failed. Please try again.', 'mainwp' );
            }
        } catch ( Exception $e ) {
            $resp_data['error'] = wp_strip_all_tags( $e->getMessage() );
        }
        return rest_ensure_response( $resp_data );
    }


    /**
     * Delete client.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function delete_item( $request ) {
        $item = $this->get_request_item( $request );
        if ( is_wp_error( $item ) ) {
            return $item;
        }

        $ret       = MainWP_DB_Client::instance()->delete_client( $item->client_id );
        $resp_data = array(
            'success' => $ret ? 1 : 0,
            'message' => $ret ? esc_html__( 'Client deleted successfully', 'mainwp' ) : esc_html__( 'Client deleted failed.', 'mainwp' ),
            'data'    => $this->filter_response_data_by_allowed_fields( $item, 'simple_view' ),
        );
        return rest_ensure_response( $resp_data );
    }

    /**
     * Get sites of client.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_sites_client( $request ) {
        $item = $this->get_request_item( $request );

        if ( is_wp_error( $item ) ) {
            return $item;
        }

        $data  = array();
        $sites = MainWP_DB_Client::instance()->get_websites_by_client_ids(
            $item->client_id,
            array(
                'selectgroups' => true,
                'full_data'    => true,
            )
        );
        if ( $sites ) {
            foreach ( $sites as $site ) {
                $data[] = apply_filters( 'mainwp_rest_routes_sites_controller_filter_allowed_fields_by_context', $site );
            }
        }

        $resp_data = array(
            'success' => 1,
            'total'   => count( $data ),
            'data'    => $data,
        );

        return rest_ensure_response( $resp_data );
    }

    /**
     * Get costs of client.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_costs_client( $request ) {
        $item = $this->get_request_item( $request );
        if ( is_wp_error( $item ) ) {
            return $item;
        }
        $resp_data = array(
            'success' => 1,
        );

        $client_costs = Cost_Tracker_DB::get_instance()->get_all_cost_trackers_by_clients( array( $item->client_id ) );

        if ( is_array( $client_costs ) ) {
            $client_costs = current( $client_costs ); // for current client.
        }

        if ( $client_costs ) {
            $data               = Cost_Tracker_Rest_Api_Handle_V1::instance()->prepare_api_costs_data( $client_costs );
            $resp_data['total'] = is_array( $data ) ? count( $data ) : 0;
            $resp_data['data']  = $data;
        } else {
            $resp_data['message'] = esc_html__( 'Costs not found.', 'mainwp' );
        }
        return rest_ensure_response( $resp_data );
    }

    /**
     * Count sites of client.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function count_sites_client( $request ) {
        $item = $this->get_request_item( $request );
        if ( is_wp_error( $item ) ) {
            return $item;
        }
        $resp_data          = array(
            'success' => 1,
        );
        $sites              = MainWP_DB_Client::instance()->get_websites_by_client_ids( $item->client_id );
        $resp_data['total'] = is_array( $sites ) ? count( $sites ) : 0;
        return rest_ensure_response( $resp_data );
    }

    /**
     * Suspend client.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function suspend_client( $request ) {
        $item = $this->get_request_item( $request );
        if ( is_wp_error( $item ) ) {
            return $item;
        }

        $params = array(
            'client_id' => $item->client_id,
            'suspended' => 1,
        );

        MainWP_DB_Client::instance()->update_client( $params );

        $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $item->client_id );

        $resp_data = array(
            'success' => 1,
            'message' => esc_html__( 'Client suspended successfully.', 'mainwp' ),
            'data'    => $this->filter_response_data_by_allowed_fields( $client, 'simple_view' ),
        );
        return rest_ensure_response( $resp_data );
    }

    /**
     * Unsuspend client.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function unsuspend_client( $request ) {
        $item = $this->get_request_item( $request );
        if ( is_wp_error( $item ) ) {
            return $item;
        }

        $params = array(
            'client_id' => $item->client_id,
            'suspended' => 0,
        );

        MainWP_DB_Client::instance()->update_client( $params );

        $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $item->client_id );

        $resp_data = array(
            'success' => 1,
            'message' => esc_html__( 'Client unsuspended successfully.', 'mainwp' ),
            'data'    => $this->filter_response_data_by_allowed_fields( $client, 'simple_view' ),
        );
        return rest_ensure_response( $resp_data );
    }

    /**
     * Get client fields.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Client::instance()->get_client_fields_by_params()
     *
     * @return WP_Error|WP_REST_Response
     */
	public function client_fields( $request ) {  // phpcs:ignore -- NOSONAR - complex.
        // Prepare query args.
        $args   = $this->prepare_objects_query( $request, 'client_fields' );
        $params = array(
            'exclude'   => ! empty( $args['exclude'] ) ? $args['exclude'] : '',
            'include'   => ! empty( $args['include'] ) ? $args['include'] : '',
            'client_id' => ! empty( $args['client_id'] ) ? $args['client_id'] : '',
            'search'    => ! empty( $args['s'] ) ? $args['s'] : '',
            'page'      => ! empty( $args['paged'] ) ? (int) $args['paged'] : 1,
            'per_page'  => ! empty( $args['items_per_page'] ) ? (int) $args['items_per_page'] : 20,
        );

        // Get client fields.
        $fields = MainWP_DB_Client::instance()->get_client_fields_by_params( $params );
        if ( empty( $fields ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'No client fields found.', 'mainwp' ),
                )
            );
        }

        $data = array();
        foreach ( $fields as $field ) {
            $record = array(
                'field_id'    => (int) $field->field_id ? $field->field_id : 0,
                'name'        => $field->field_name ? $field->field_name : '',
                'description' => $field->field_desc ? $field->field_desc : '',
                'client_id'   => (int) $field->client_id ? $field->client_id : 0,
            );
            $data[] = $this->filter_response_data_by_allowed_fields( $record, 'field_view' );
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
     * Create client fields.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Client::instance()->add_client_field()
     * @uses MainWP_DB_Client::instance()->get_wp_client_by()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function create_client_fields( $request ) {
        // Get request body.
        $body = $request->get_body_params();
        if ( empty( $body ) ) {
            return new WP_Error(
                'empty_body',
                __( 'Request body is empty.', 'mainwp' ),
            );
        }

        // Validate request body.
        if ( empty( $body['name'] ) || empty( $body['description'] ) ) {
            return new WP_Error(
                'empty_name',
                __( 'Name and description are required.', 'mainwp' ),
            );
        }

        // Check client id.
        $client_id = 0;
        if ( ! empty( $body['client_id'] ) ) {
            $client_id = absint( $body['client_id'] );
            if ( ! MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id ) ) {
                return new WP_Error(
                    'invalid_client_id',
                    __( 'Invalid client ID.', 'mainwp' ),
                );
            }
        }

        $name = sanitize_text_field( wp_unslash( $body['name'] ) );
        $desc = sanitize_text_field( wp_unslash( $body['description'] ) );

        $field = MainWP_DB_Client::instance()->add_client_field(
            array(
                'field_name' => $name,
                'field_desc' => $desc,
                'client_id'  => $client_id,
            )
        );

        if ( ! $field ) {
            return new WP_Error(
                'create_field_failed',
                __( 'Create client field failed.', 'mainwp' ),
            );
        }

        // Prepare response data.
        $data = array(
            'field_id'    => (int) $field->field_id ? $field->field_id : 0,
            'name'        => $field->field_name ? $field->field_name : '',
            'description' => $field->field_desc ? $field->field_desc : '',
            'client_id'   => (int) $field->client_id ? $field->client_id : 0,
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Client field created successfully.', 'mainwp' ),
                'data'    => $this->filter_response_data_by_allowed_fields( $data, 'field_edit' ),
            )
        );
    }

    /**
     * Edit client fields.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @users MainWP_DB_Client::instance()->update_client_field()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function edit_client_fields( $request ) {
        // Get client field.
        $field = $this->get_request_client_fields_item( $request );
        if ( empty( $field ) ) {
            return new WP_Error(
                'invalid_field_id',
                __( 'Invalid client field.', 'mainwp' ),
            );
        }

        // Get request body.
        $body = $request->get_json_params();
        if ( empty( $body ) ) {
            return new WP_Error(
                'empty_body',
                __( 'Request body is empty.', 'mainwp' ),
            );
        }

        $name = ! empty( $body['name'] ) ? sanitize_text_field( wp_unslash( $body['name'] ) ) : $field->field_name;
        $desc = ! empty( $body['description'] ) ? sanitize_text_field( wp_unslash( $body['description'] ) ) : $field->field_desc;

        $updated = MainWP_DB_Client::instance()->update_client_field(
            $field->field_id,
            array(
                'field_name' => $name,
                'field_desc' => $desc,
            )
        );
        if ( ! $updated ) {
            return new WP_Error(
                'update_field_failed',
                __( 'Update client field failed.', 'mainwp' ),
            );
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Client field updated successfully.', 'mainwp' ),
            )
        );
    }

    /**
     * Delete client fields.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @users MainWP_DB_Client::instance()->delete_client_field_by()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function delete_client_fields( $request ) {
        // Get client field.
        $field = $this->get_request_client_fields_item( $request );
        if ( empty( $field ) ) {
            return new WP_Error(
                'invalid_field_id',
                __( 'Invalid client field.', 'mainwp' ),
            );
        }

        // Delete client field.
        $field_id  = ! empty( $field->field_id ) ? $field->field_id : 0;
        $client_id = ! empty( $field->client_id ) ? $field->client_id : 0;
        $deleted   = MainWP_DB_Client::instance()->delete_client_field_by( 'field_id', $field_id, $client_id );

        if ( ! $deleted ) {
            return new WP_Error(
                'delete_field_failed',
                __( 'Delete client field failed.', 'mainwp' ),
            );
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => esc_html__( 'Client field deleted successfully.', 'mainwp' ),
            )
        );
    }

    /**
     * Edit client fields allowed fields.
     *
     * @return array
     */
    public function edit_client_fields_allowed_fields() {
        return array(
            'name'        => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Field name.', 'mainwp' ),
            ),
            'description' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Field description.', 'mainwp' ),
            ),
        );
    }

    /**
     * Create client fields allowed fields.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function create_client_fields_allowed_fields() {
        return array(
            'name'        => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Field name.', 'mainwp' ),
            ),
            'description' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Field description.', 'mainwp' ),
            ),
            'client_id'   => array(
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'description'       => __( 'Client ID.', 'mainwp' ),
            ),
        );
    }

    /**
     * Get client fields allowed fields.
     *
     * @return array
     */
    public function get_client_fields_allowed_fields() {
        return array(
            'search'    => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Search client field name.', 'mainwp' ),
            ),
            'include'   => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Include client field IDs.', 'mainwp' ),
            ),
            'exclude'   => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Exclude client field IDs.', 'mainwp' ),
            ),
            'client_id' => array(
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'description'       => __( 'Client ID.', 'mainwp' ),
            ),
            'page'      => array(
                'required'          => false,
                'type'              => 'integer',
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
                'description'       => __( 'Page number.', 'mainwp' ),
            ),
            'pre_page'  => array(
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'minimum'           => 1,
                'maximum'           => 200,
                'description'       => __( 'Number of client fields per page.', 'mainwp' ),
            ),
        );
    }

    /**
     * Get client field request id or name
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Client::instance()->get_client_fields_by()
     *
     * @return Object|bool Item.
     */
    private function get_request_client_fields_item( $request ) {
        $raw         = $request->get_param( 'id_name' );
        $decoded     = rawurldecode( (string) $raw );
        $field_value = trim( sanitize_text_field( wp_unslash( $decoded ) ) );

        if ( empty( $field_value ) ) {
            return false;
        }

        // Get client field by id.
        if ( ctype_digit( $field_value ) ) {
            return MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', (int) $field_value );
        }

        return MainWP_DB_Client::instance()->get_client_fields_by( 'field_name', $field_value );
    }

    /**
     * Get the Tags schema, conforming to JSON Schema.
     *
     * @since  5.2
     * @return array
     */
    public function get_item_schema() {
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'clients',
            'type'       => 'object',
            'properties' => array(
                'id'                 => array(
                    'type'        => 'integer',
                    'description' => __( 'Client ID.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                ),
                'client_id'          => array(
                    'type'        => 'integer',
                    'description' => __( 'Client ID.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view', 'field_view', 'field_edit' ),
                ),
                'name'               => array(
                    'type'        => 'string',
                    'description' => __( 'Client name.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view', 'field_view', 'field_edit' ),
                ),
                'address_1'          => array(
                    'type'        => 'string',
                    'description' => __( 'Address 1.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                ),
                'address_2'          => array(
                    'type'        => 'string',
                    'description' => __( 'Address 2.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                ),
                'city'               => array(
                    'type'        => 'string',
                    'description' => __( 'City.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                ),
                'zip'                => array(
                    'type'        => 'string',
                    'description' => __( 'Zip.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                ),
                'state'              => array(
                    'type'        => 'string',
                    'description' => __( 'State.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                ),
                'country'            => array(
                    'type'        => 'string',
                    'description' => __( 'Country.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                ),
                'note'               => array(
                    'type'        => 'string',
                    'description' => __( 'Note.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'client_email'       => array(
                    'type'        => 'string',
                    'description' => __( 'Email.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                ),
                'client_phone'       => array(
                    'type'        => 'string',
                    'description' => __( 'Phone.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                ),
                'client_facebook'    => array(
                    'type'        => 'string',
                    'description' => __( 'Facebook.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'client_twitter'     => array(
                    'type'        => 'string',
                    'description' => __( 'X.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'client_instagram'   => array(
                    'type'        => 'string',
                    'description' => __( 'Instagram.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'client_linkedin'    => array(
                    'type'        => 'string',
                    'description' => __( 'Linkedin.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'created'            => array(
                    'type'        => 'integer',
                    'description' => __( 'Created.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                ),
                'suspended'          => array(
                    'type'        => 'integer',
                    'description' => __( 'Suspended.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'simple_view' ),
                ),
                'primary_contact_id' => array(
                    'type'        => 'integer',
                    'description' => __( 'Primary contact id.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'field_id'           => array(
                    'type'        => 'integer',
                    'description' => __( 'Field ID.', 'mainwp' ),
                    'context'     => array( 'field_view', 'field_edit' ),
                ),
                'description'        => array(
                    'type'        => 'string',
                    'description' => __( 'Field description.', 'mainwp' ),
                    'context'     => array( 'field_view', 'field_edit' ),
                ),
            ),
        );
    }
}
