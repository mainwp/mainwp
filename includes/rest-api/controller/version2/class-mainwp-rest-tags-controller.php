<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;

/**
 * Class MainWP_Rest_Tags_Controller
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Tags_Controller extends MainWP_REST_Controller{ //phpcs:ignore -- NOSONAR - multi methods.

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
    protected $rest_base = 'tags';


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
            '/' . $this->rest_base . '/(?P<id>[\d]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
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
                ),
            )
        );

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

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/sites',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_sites_by_tag_id' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/clients',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_clients_by_tag_id' ),
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
        return $data;
    }

    /**
     * Get all Clients.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_items( $request ) {

        $args = $this->prepare_objects_query( $request );

        $s        = isset( $args['s'] ) ? trim( $args['s'] ) : '';
        $includ   = isset( $args['include'] ) ? $args['include'] : '';
        $exclud   = isset( $args['exclude'] ) ? $args['exclude'] : '';
        $page     = isset( $args['paged'] ) ? intval( $args['paged'] ) : false;
        $per_page = isset( $args['items_per_page'] ) ? intval( $args['items_per_page'] ) : false;

        $prepared_args = array(
            's'       => ! empty( $s ) ? $s : '',
            'include' => ! empty( $includ ) ? $includ : array(),
            'exclude' => ! empty( $exclud ) ? $exclud : array(),
        );

        if ( false !== $page ) {
            $prepared_args['page'] = $page;
        }
        if ( false !== $per_page ) {
            $prepared_args['per_page'] = $per_page;
        }

        $prepared_args['with_sites_ids'] = true;

        $groups = MainWP_DB_Common::instance()->get_tags( $prepared_args );

        $data = array();
        if ( $groups ) {
            foreach ( $groups as $group ) {
                $data[ $group->id ] = $this->filter_response_data_by_allowed_fields( $group, 'view' );
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
     * Get request tag.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|Object Item.
     */
    public function get_request_item( $request ) {
        $id   = intval( $request['id'] );
        $item = MainWP_DB_Common::instance()->get_group_by_id( $id );
        if ( empty( $item ) ) {
            return $this->get_rest_data_error( 'id', 'tag' );
        }
        return $item;
    }

    /**
     * Get tag.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_item( $request ) {
        $item = $this->get_request_item( $request );

        if ( is_wp_error( $item ) ) {
            return $item;
        }

        $prepared_args = array(
            'with_sites_ids' => true,
            'include'        => $item->id,
        );

        $data = array();

        $items = MainWP_DB_Common::instance()->get_tags( $prepared_args );
        if ( is_array( $items ) ) {
            $data = current( $items );
        }

        $resp_data = array(
            'success' => 1,
            'data'    => $this->filter_response_data_by_allowed_fields( $data, 'view' ),
        );

        return rest_ensure_response( $resp_data );
    }


    /**
     * Adds tag.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function create_item( $request ) {
        $resp_data            = array();
        $resp_data['success'] = 0;
        try {
            $item = array_filter( $request->get_params() );
            $tag  = MainWP_DB_Common::instance()->add_tag( $item );
            if ( ! empty( $tag ) ) {

                $prepared_args = array(
                    'with_sites_ids' => true,
                    'include'        => $tag->id,
                );

                $data  = array();
                $items = MainWP_DB_Common::instance()->get_tags( $prepared_args );
                if ( is_array( $items ) ) {
                    $data = current( $items );
                }

                $resp_data['success'] = 1;
                $resp_data['message'] = esc_html__( 'Tag created successfully.', 'mainwp' );
                $resp_data['data']    = $this->filter_response_data_by_allowed_fields( $data, 'view' );
            } else {
                $resp_data['error'] = esc_html__( 'Add tag failed. Please try again.', 'mainwp' );
            }
        } catch ( Exception $e ) {
            $resp_data['error'] = wp_strip_all_tags( $e->getMessage() );
        }
        return rest_ensure_response( $resp_data );
    }


    /**
     * Update tag.
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
            $item = array_filter( $request->get_params() );
            $tag  = MainWP_DB_Common::instance()->add_tag( $item );
            if ( false !== $tag ) {
                $tag                  = (array) $tag;
                $resp_data['success'] = 1;
                $resp_data['message'] = esc_html__( 'Tag updated successfully.', 'mainwp' );
                $resp_data['data']    = $this->filter_response_data_by_allowed_fields( $tag, 'view' );
            } else {
                $resp_data['error'] = esc_html__( 'Update tag failed. Please try again.', 'mainwp' );
            }
        } catch ( Exception $e ) {
            $resp_data['error'] = wp_strip_all_tags( $e->getMessage() );
        }
        return rest_ensure_response( $resp_data );
    }

    /**
     * Delete tag.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function delete_item( $request ) {

        $tag_id = ! empty( $request['id'] ) ? intval( $request['id'] ) : 0;

        $item = $this->get_request_item( $request );

        if ( is_wp_error( $item ) ) {
            return $item;
        }

        $success = MainWP_DB_Common::instance()->remove_group( $tag_id );

        $resp_data = array( 'success' => $success ? 1 : 0 );

        $resp_data['message'] = $success ? esc_html__( 'Tag deleted successfully.', 'mainwp' ) : esc_html__( 'Tag deleted failed.', 'mainwp' );
        $resp_data['data']    = $this->filter_response_data_by_allowed_fields( $item, 'view' );

        return rest_ensure_response( $resp_data );
    }

    /**
     * Get sites by tag id.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_sites_by_tag_id( $request ) {

        $item = $this->get_request_item( $request );

        if ( is_wp_error( $item ) ) {
            return $item;
        }

        $websites = MainWP_DB::instance()->get_websites_by_group_ids( array( $item->id ) );

        $data = array();

        if ( is_array( $websites ) ) {
            foreach ( $websites as $website ) {
                $data[] = apply_filters( 'mainwp_rest_routes_sites_controller_filter_allowed_fields_by_context', $website );
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
     * Get client by tag id.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_clients_by_tag_id( $request ) {

        $item = $this->get_request_item( $request );

        if ( is_wp_error( $item ) ) {
            return $item;
        }

        $clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all', null, OBJECT, array( 'by_tags' => array( $item->id ) ) );
        $data    = array();

        if ( is_array( $clients ) ) {
            foreach ( $clients as $client ) {
                $data[] = apply_filters( 'mainwp_rest_routes_clients_controller_filter_allowed_fields_by_context', $client );
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
     * Get the Tags schema, conforming to JSON Schema.
     *
     * @since  5.2
     * @return array
     */
    public function get_item_schema() {
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'tags',
            'type'       => 'object',
            'properties' => array(
                'id'          => array(
                    'type'              => 'integer',
                    'description'       => __( 'Tag ID.', 'mainwp' ),
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'wp_parse_id_list',
                    'context'           => array( 'view', 'edit' ),
                ),
                'name'        => array(
                    'type'              => 'string',
                    'description'       => __( 'Tag name.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validatze_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'color'       => array(
                    'type'              => 'string',
                    'description'       => __( 'Tag color.', 'mainwp' ),
                    'default'           => null,
                    'sanitize_callback' => 'sanitize_hex_color',
                    'validate_callback' => 'rest_validate_request_arg',
                    'context'           => array( 'view', 'edit' ),
                ),
                'count_sites' => array(
                    'type'              => 'absint',
                    'description'       => __( 'Tag Sites.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_hex_color',
                    'validate_callback' => 'rest_validate_request_arg',
                    'context'           => array( 'view' ),
                ),
                'sites_ids'   => array(
                    'type'              => 'array',
                    'default'           => array(),
                    'description'       => __( 'Tag Sites IDs', 'mainwp' ),
                    'sanitize_callback' => 'wp_parse_id_list',
                    'context'           => array( 'view' ),
                    'items'             => array(
                        'type' => 'integer',
                    ),
                ),
            ),
        );
    }
}
