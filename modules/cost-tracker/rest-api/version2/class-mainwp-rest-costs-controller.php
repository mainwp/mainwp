<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Extra_Exception;
use MainWP\Dashboard\MainWP_DB_Client;

use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Rest_Api_Handle_V1;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Admin;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Add_Edit;

/**
 * Class MainWP_Rest_Costs_Controller
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Costs_Controller extends MainWP_REST_Controller { //phpcs:ignore -- NOSONAR - multi methods.

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
    protected $rest_base = 'costs';


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

        // Adds new cost.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/add',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Retrieves cost Object by cost ID.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Edits cost by cost ID.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/edit',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Removes cost by cost ID.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/remove',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Retrieves all sites for a cost by cost ID.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/sites',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_sites_by_cost' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Retrieves all costs for a cost by cost ID.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/clients',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_clients_by_cost' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Handle batch requests.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/batch',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'batch_items' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );
    }

    /**
     * Prepare objects query.
     *
     * @since  5.2
     * @param  WP_REST_Request $request Full details about the request.
     * @param  string          $type 'object'.
     *
     * @return array
     */
    protected function prepare_objects_query( $request, $type = 'object' ) {

        // This is needed to get around an array to string notice in WC_REST_Orders_V2_Controller::prepare_objects_query.
        $statuses = array();
        if ( isset( $request['status'] ) ) {
            $statuses = wp_parse_list( $request['status'] );
            unset( $request['status'] );
        }

        $category = array();
        if ( isset( $request['category'] ) ) {
            $category = wp_parse_list( $request['category'] );
            unset( $request['category'] );
        }

        $types = array();
        if ( isset( $request['type'] ) ) {
            $types = wp_parse_list( $request['type'] );
            unset( $request['type'] );
        }

        $args = parent::prepare_objects_query( $request, $type );

        $product_types = Cost_Tracker_Admin::get_product_types();

        $payment_types = array(
            'subscription' => esc_html__( 'Subscription', 'mainwp' ),
            'lifetime'     => esc_html__( 'Lifetime', 'mainwp' ),
        );

        $cost_status = Cost_Tracker_Admin::get_cost_status();

        $args['status'] = array();
        foreach ( $statuses as $status ) {
            if ( isset( $cost_status[ $status ] ) ) {
                $args['status'][] = $status;
            }
        }

        // Put the statuses back for further processing (next/prev links, etc).
        $request['status'] = $statuses;

        $args['category'] = array();
        foreach ( $category as $cat ) {
            if ( isset( $product_types[ $cat ] ) || 'any' === $cat ) {
                $args['category'][] = $cat;
            }
        }

        // Put the statuses back for further processing (next/prev links, etc).
        $request['category'] = $category;

        $args['type'] = array();
        foreach ( $types as $type ) {
            if ( isset( $payment_types[ $type ] ) || 'any' === $type ) {
                $args['type'][] = $type;
            }
        }

        // Put the statuses back for further processing (next/prev links, etc).
        $request['type'] = $types;

        return $args;
    }

    /**
     * Get all Clients.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_items( $request ) {

        $valid_args = parent::prepare_objects_query( $request );
        $valid_args = $this->validate_rest_args( $valid_args, $this->get_validate_args_params( 'get_costs' ) );

        if ( is_wp_error( $valid_args ) ) {
            return $valid_args;
        }

        $args = $this->prepare_objects_query( $request );

        $costs = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'all', null, $args );
        $data  = Cost_Tracker_Rest_Api_Handle_V1::instance()->prepare_api_costs_data( $costs );

        $resp_data = array(
            'success' => 1,
            'total'   => is_array( $data ) ? count( $data ) : 0,
            'data'    => $data,
        );
        return rest_ensure_response( $resp_data );
    }


    /**
     * Create item.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function create_item( $request ) {
        try {
            $resp_data = $this->handle_rest_update_insert_item( $request );
        } catch ( MainWP_Extra_Exception $e ) {
            return new \WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => 400 ) );
        }
        return rest_ensure_response( $resp_data );
    }

    /**
     *
     * Handles the saving item.
     *
     * @throws MainWP_Extra_Exception Exception happen.
     * @param  WP_REST_Request $request Full details about the request.
     *
     * @return mixed Save output.
     */
    public static function handle_rest_update_insert_item( $request ) { //phpcs:ignore -- NOSONAR - complex method.

        //phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $update                   = array();
        $last_renewal             = isset( $request['last_renewal'] ) ? strtotime( wp_unslash( $request['last_renewal'] ) ) : 0;
        $update['name']           = sanitize_text_field( wp_unslash( $request['name'] ) );
        $update['type']           = sanitize_text_field( wp_unslash( $request['payment_type'] ) );
        $update['product_type']   = sanitize_text_field( wp_unslash( $request['product_type'] ) );
        $update['slug']           = isset( $request['product_slug'] ) ? sanitize_text_field( wp_unslash( $request['product_slug'] ) ) : '';
        $update['license_type']   = sanitize_text_field( wp_unslash( $request['license_type'] ) );
        $update['cost_status']    = sanitize_text_field( wp_unslash( $request['cost_tracker_status'] ) );
        $update['url']            = ! empty( $request['url'] ) ? esc_url_raw( wp_unslash( $request['url'] ) ) : '';
        $update['cost_icon']      = ! empty( $request['icon_hidden'] ) ? sanitize_text_field( wp_unslash( $request['icon_hidden'] ) ) : '';
        $update['cost_color']     = sanitize_hex_color( wp_unslash( $request['product_color'] ) );
        $update['price']          = floatval( $request['price'] );
        $update['payment_method'] = sanitize_text_field( wp_unslash( $request['payment_method'] ) );

        $renewal_fequency       = sanitize_text_field( wp_unslash( $request['renewal_type'] ) );
        $update['renewal_type'] = $renewal_fequency;
        $update['last_renewal'] = $last_renewal; // labeled Purchase date.

        $next_renewal           = Cost_Tracker_Admin::get_next_renewal( $last_renewal, $renewal_fequency );
        $update['next_renewal'] = $next_renewal;

        $note           = isset( $request['note'] ) ? wp_unslash( $request['note'] ) : '';
        $esc_note       = apply_filters( 'mainwp_escape_content', $note );
        $update['note'] = $esc_note;

        $selected_sites   = array();
        $selected_groups  = array();
        $selected_clients = array();

        if ( isset( $request['sites'] ) && is_array( $request['sites'] ) ) {
            foreach ( wp_unslash( $request['sites'] ) as $selected ) {
                $selected_sites[] = intval( $selected );
            }
        } elseif ( isset( $request['groups'] ) && is_array( $request['groups'] ) ) {
            foreach ( wp_unslash( $request['groups'] ) as $selected ) {
                $selected_groups[] = intval( $selected );
            }
        } elseif ( isset( $request['clients'] ) && is_array( $request['clients'] ) ) {
            foreach ( wp_unslash( $request['clients'] ) as $selected ) {
                $selected_clients[] = intval( $selected );
            }
        }

        $update['sites']   = ! empty( $selected_sites ) ? wp_json_encode( $selected_sites ) : '';
        $update['groups']  = ! empty( $selected_groups ) ? wp_json_encode( $selected_groups ) : '';
        $update['clients'] = ! empty( $selected_clients ) ? wp_json_encode( $selected_clients ) : '';

        if ( empty( $update['sites'] ) && empty( $update['groups'] ) && empty( $update['clients'] ) ) {
            throw new MainWP_Extra_Exception( 'mainwp_rest_create_costs_invalid_data', esc_html__( 'Please enter websites or groups or clients.', 'mainwp' ) );
        }

        $current = false;
        if ( ! empty( $request['id'] ) ) {
            $update['id'] = intval( $request['id'] );
            $current      = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'id', $update['id'] );
        }

        //phpcs:enable
        $err_msg = '';
        $output  = false;

        if ( ! isset( $update['cost_color'] ) ) {
            $update['cost_color'] = ''; // can not be null.
        }

        try {
            $output = Cost_Tracker_DB::get_instance()->update_cost_tracker( $update );
            if ( $output && ! empty( $output->id ) ) {
                Cost_Tracker_DB::get_instance()->update_selected_lookup_cost( $output->id, $selected_sites, $selected_groups, $selected_clients );
                if ( $current && ! empty( $current->cost_icon ) && false === strpos( $current->cost_icon, 'deficon:' ) && $current->cost_icon !== $update['cost_icon'] ) {
                    Cost_Tracker_Add_Edit::get_instance()->delete_product_icon_file( $current->cost_icon );
                }
            }
        } catch ( MainWP_Exception $ex ) {
            $err_msg = $ex->getMessage();
        }

        if ( empty( $err_msg ) && ! empty( $output ) ) { // success.
            return array(
                'success' => 1,
                'data'    => $output,
            );
        } elseif ( ! empty( $err_msg ) ) { // error.
            return array(
                'success' => 0,
                'error'   => $err_msg,
            );
        }
        return array(
            'success' => 0,
            'error'   => esc_html__( 'Add cost failed. Please try again.', 'mainwp' ),
        );
    }


    /**
     * Get site by.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|Object Item.
     */
    public function get_request_item( $request ) {
        $id   = $request['id'];
        $item = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'id', $id );
        if ( empty( $item ) ) {
            return $this->get_rest_data_error( 'id', 'costs' );
        }
        return $item;
    }


    /**
     * Get cost.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_item( $request ) {
        $cost = $this->get_request_item( $request );
        if ( is_wp_error( $cost ) ) {
            return $cost;
        }
        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $cost,
            )
        );
    }

    /**
     * Adds new client.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function add_item( $request ) {
        // get data.
        $fields = $request->get_json_params();
        try {
            $data     = MainWP_Client_Handler::rest_api_add_client( $fields );
            $response = new \WP_REST_Response( $data );
            $response->set_status( 200 );
        } catch ( \Exception $e ) {
            return new WP_Error( $e->getCode(), $e->getMessage(), array( 'status' => 400 ) );
        }

        // get data.
        $value = MainWP_DB_Client::instance()->get_wp_clients( $prepared_args );
        return rest_ensure_response( $value );
    }



    /**
     * Update item.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function update_item( $request ) {

        $cost = $this->get_request_item( $request );
        if ( is_wp_error( $cost ) ) {
            return $cost;
        }

        try {
            $resp_data = $this->handle_rest_update_insert_item( $request );
        } catch ( MainWP_Extra_Exception $e ) {
            return new \WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => 400 ) );
        }
        return rest_ensure_response( $resp_data );
    }


    /**
     * Delete item.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function delete_item( $request ) {

        $cost = $this->get_request_item( $request );
        if ( is_wp_error( $cost ) ) {
            return $cost;
        }

        $deleted = Cost_Tracker_DB::get_instance()->delete_cost_tracker( 'id', $cost->id );

        return rest_ensure_response(
            array(
                'success' => $deleted ? 1 : 0,
                'message' => $deleted ? esc_html__( 'Cost deteled successfully.' ) : esc_html__( 'Cost deteled failed.' ),
                'data'    => $cost,
            )
        );
    }


    /**
     * Get sites by item.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_sites_by_cost( $request ) {

        $cost = $this->get_request_item( $request );

        if ( is_wp_error( $cost ) ) {
            return $cost;
        }

        $sites   = ! empty( $cost->sites ) ? json_decode( $cost->sites, true ) : array();
        $groups  = ! empty( $cost->groups ) ? json_decode( $cost->groups, true ) : array();
        $clients = ! empty( $cost->clients ) ? json_decode( $cost->clients, true ) : array();

        if ( ! is_array( $sites ) ) {
            $sites = array();
        }
        if ( ! is_array( $groups ) ) {
            $groups = array();
        }
        if ( ! is_array( $clients ) ) {
            $clients = array();
        }

        $data = array();

        if ( ! empty( $sites ) || ! empty( $groups ) || ! empty( $clients ) ) {
            $params = array(
                'sites'         => $sites,
                'groups'        => $groups,
                'clients'       => $clients,
                'selectgroups'  => true,
                'schema_fields' => apply_filters( 'mainwp_rest_routes_sites_controller_get_allowed_fields_by_context', 'view' ),
            );
                // to do: should create relation of: costs, sites, groups, clients in other way.
            // to make it more easier in queries.
            $cost_sites = MainWP_DB::instance()->get_db_sites( $params );
            if ( is_array( $cost_sites ) ) {
                foreach ( $cost_sites as $site ) {
                    $data[] = apply_filters( 'mainwp_rest_routes_sites_controller_filter_allowed_fields_by_context', $site );
                }
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
     * Get clients by item.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_clients_by_cost( $request ) { //phpcs:ignore -- NOSONAR - complex.

        $cost = $this->get_request_item( $request );

        if ( is_wp_error( $cost ) ) {
            return $cost;
        }

        $sites   = ! empty( $cost->sites ) ? json_decode( $cost->sites, true ) : array();
        $groups  = ! empty( $cost->groups ) ? json_decode( $cost->groups, true ) : array();
        $clients = ! empty( $cost->clients ) ? json_decode( $cost->clients, true ) : array();

        if ( ! is_array( $sites ) ) {
            $sites = array();
        }
        if ( ! is_array( $groups ) ) {
            $groups = array();
        }
        if ( ! is_array( $clients ) ) {
            $clients = array();
        }

        $data = array();

        if ( ! empty( $sites ) || ! empty( $groups ) || ! empty( $clients ) ) {
            $params = array(
                'sites'   => $sites,
                'groups'  => $groups,
                'clients' => $clients,
            );

            // to do: should create relation of: costs, sites, groups, clients in other way.
            // to make it more easier in queries.
            $cost_sites = MainWP_DB::instance()->get_db_sites( $params );

            $client_ids = array();
            if ( is_array( $cost_sites ) ) {
                foreach ( $cost_sites as $site ) {
                    if ( ! in_array( $site->client_id, $client_ids ) ) {
                        $client_ids[] = $site->client_id;
                    }
                }
            }

            if ( ! empty( $client_ids ) ) {
                $prepared_args = array(
                    'include' => $client_ids,
                );
                // get data.
                $clients = MainWP_DB_Client::instance()->get_wp_clients( $prepared_args );
                if ( ! empty( $clients ) ) {
                    foreach ( $clients as $client ) {
                        $data[] = apply_filters( 'mainwp_rest_routes_clients_controller_filter_allowed_fields_by_context', $client );
                    }
                }
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
     * Only return writable props from schema.
     *
     * @param  array $schema schema.
     * @return bool
     */
    protected function filter_writable_props( $schema ) {
        return empty( $schema['readonly'] );
    }

    /**
     * Get the query params for collections.
     *
     * @return array
     */
    public function get_collection_params() {

        $params = parent::get_collection_params();

        $params['context']['default'] = 'view';

        $params['after']            = array(
            'description' => __( 'Limit response to resources published after a given ISO8601 compliant date.', 'woocommerce' ),
            'type'        => 'string',
            'format'      => 'date-time',
        );
        $params['before']           = array(
            'description' => __( 'Limit response to reviews published before a given ISO8601 compliant date.', 'woocommerce' ),
            'type'        => 'string',
            'format'      => 'date-time',
        );
        $params['exclude']          = array(
            'description' => __( 'Ensure result set excludes specific IDs.', 'woocommerce' ),
            'type'        => 'array',
            'items'       => array(
                'type' => 'integer',
            ),
            'default'     => array(),
        );
        $params['include']          = array(
            'description' => __( 'Limit result set to specific IDs.', 'woocommerce' ),
            'type'        => 'array',
            'items'       => array(
                'type' => 'integer',
            ),
            'default'     => array(),
        );
        $params['offset']           = array(
            'description' => __( 'Offset the result set by a specific number of items.', 'woocommerce' ),
            'type'        => 'integer',
        );
        $params['order']            = array(
            'description' => __( 'Order sort attribute ascending or descending.', 'woocommerce' ),
            'type'        => 'string',
            'default'     => 'desc',
            'enum'        => array(
                'asc',
                'desc',
            ),
        );
        $params['orderby']          = array(
            'description' => __( 'Sort collection by object attribute.', 'woocommerce' ),
            'type'        => 'string',
            'default'     => 'date_gmt',
            'enum'        => array(
                'date',
                'date_gmt',
                'id',
                'include',
                'product',
            ),
        );
        $params['reviewer']         = array(
            'description' => __( 'Limit result set to reviews assigned to specific user IDs.', 'woocommerce' ),
            'type'        => 'array',
            'items'       => array(
                'type' => 'integer',
            ),
        );
        $params['reviewer_exclude'] = array(
            'description' => __( 'Ensure result set excludes reviews assigned to specific user IDs.', 'woocommerce' ),
            'type'        => 'array',
            'items'       => array(
                'type' => 'integer',
            ),
        );
        $params['reviewer_email']   = array(
            'default'     => null,
            'description' => __( 'Limit result set to that from a specific author email.', 'woocommerce' ),
            'format'      => 'email',
            'type'        => 'string',
        );
        $params['product']          = array(
            'default'     => array(),
            'description' => __( 'Limit result set to reviews assigned to specific product IDs.', 'woocommerce' ),
            'type'        => 'array',
            'items'       => array(
                'type' => 'integer',
            ),
        );
        $params['status']           = array(
            'default'           => 'approved',
            'description'       => __( 'Limit result set to reviews assigned a specific status.', 'woocommerce' ),
            'sanitize_callback' => 'sanitize_key',
            'type'              => 'string',
            'enum'              => array(
                'all',
                'hold',
                'approved',
                'spam',
                'trash',
            ),
        );

        /**
         * Filter collection parameters.
         *
         * This filter registers the collection parameter, but does not map the
         * collection parameter to an internal WP_Comment_Query parameter. Use the
         * `wc_rest_review_query` filter to set WP_Comment_Query parameters.
         *
         * @since 5.2
         * @param array $params JSON Schema-formatted collection parameters.
         */
        return apply_filters( 'mainwp_rest_cost_collection_params', $params );
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
            'title'      => 'costs',
            'type'       => 'object',
            'properties' => array(
                'id'             => array(
                    'type'              => 'integer',
                    'description'       => __( 'Cost ID.', 'mainwp' ),
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'rest_validate_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'name'           => array(
                    'type'              => 'string',
                    'description'       => __( 'Cost name.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'url'            => array(
                    'type'              => 'string',
                    'description'       => __( 'Cost url.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_url',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'type'           => array(
                    'type'              => 'string',
                    'description'       => __( 'Cost type.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'product_type'   => array(
                    'type'              => 'string',
                    'description'       => __( 'Product type.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'slug'           => array(
                    'type'              => 'string',
                    'description'       => __( 'Product slug.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'license_type'   => array(
                    'type'              => 'string',
                    'description'       => __( 'License type.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'cost_status'    => array(
                    'type'              => 'string',
                    'description'       => __( 'Cost status.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'payment_method' => array(
                    'type'              => 'string',
                    'description'       => __( 'Payment method.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'price'          => array(
                    'type'              => 'number',
                    'description'       => __( 'Price.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'renewal_type'   => array(
                    'type'              => 'string',
                    'description'       => __( 'Renewal type.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'last_renewal'   => array(
                    'type'              => 'integer',
                    'description'       => __( 'Last renewal.', 'mainwp' ),
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'next_renewal'   => array(
                    'type'              => 'integer',
                    'description'       => __( 'Next renewal.', 'mainwp' ),
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'last_alert'     => array(
                    'type'              => 'integer',
                    'description'       => __( 'Last alert.', 'mainwp' ),
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'cost_icon'      => array(
                    'type'              => 'string',
                    'description'       => __( 'Cost icon.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'cost_color'     => array(
                    'type'              => 'string',
                    'description'       => __( 'Cost color.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'sites'          => array(
                    'type'              => 'string',
                    'description'       => __( 'Sites IDs.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'groups'         => array(
                    'type'              => 'string',
                    'description'       => __( 'Groups IDs.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'clients'        => array(
                    'type'              => 'string',
                    'description'       => __( 'Clients IDs.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'note'           => array(
                    'type'              => 'string',
                    'description'       => __( 'Note.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
            ),
        );
    }

    /**
     * Prepare a single product review output for response.
     *
     * @param WP_Comment      $review Product review object.
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response $response Response data.
     */
    public function prepare_item_for_response( $review, $request ) {
        $context = ! empty( $request['context'] ) ? $request['context'] : 'view';
        $fields  = $this->get_fields_for_response( $request );
        $data    = array();

        if ( in_array( 'id', $fields, true ) ) {
            $data['id'] = (int) $review->comment_ID;
        }
        if ( in_array( 'date_created', $fields, true ) ) {
            $data['date_created'] = mainwp_rest_prepare_date_response( $review->comment_date );
        }
        if ( in_array( 'date_created_gmt', $fields, true ) ) {
            $data['date_created_gmt'] = mainwp_rest_prepare_date_response( $review->comment_date_gmt );
        }

        $data = $this->filter_response_by_context( $data, $context );

        /**
         * Filter product reviews object returned from the REST API.
         *
         * @param array $data The  object.
         * @param WP_REST_Request  $request  Request object.
         */
        return apply_filters( 'mainwp_rest_prepare_cost', $data, $review, $request );
    }
}
