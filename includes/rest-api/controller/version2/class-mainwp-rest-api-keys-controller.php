<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_Rest_Api_Page;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Rest_API_Keys_Controller
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_API_Keys_Controller extends MainWP_REST_Controller { //phpcs:ignore -- NOSONAR - multi methods.

    // phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'rest-api';

    /**
     * Database instance.
     *
     * @var MainWP_DB
     */
    private $db = null;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->db = MainWP_DB::instance();
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
     * Method register_routes()
     *
     * Creates the necessary endpoints for the api.
     * Note, for a request to be successful the URL query parameters consumer_key and consumer_secret need to be set and correct.
     */
    public function register_routes() { // phpcs:ignore -- NOSONAR - complex.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/keys',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'list_keys' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->rest_api_list_keys_allowed_fields(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/add-key',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_new_key' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->rest_api_add_key_allowed_fields(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/edit-key/(?P<key_identifier>([1-9][0-9]*|ck_[a-f0-9]{40}))',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'edit_key' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->rest_api_edit_key_allowed_fields(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/delete-key/(?P<key_identifier>([1-9][0-9]*|ck_[a-f0-9]{40}))',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_key' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_api_allowed_id_field(),
                ),
            )
        );
    }

    /**
     * List all keys.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     *  @uses MainWP_DB::instance()->get_rest_api_keys()
     *  @uses MainWP_Rest_Api_Page::check_rest_api_updates()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function list_keys( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $data = array();
        // Get params.
        $args     = $this->prepare_objects_query( $request );
        $page     = ! empty( $args['paged'] ) ? (int) $args['paged'] : 1;
        $per_page = ! empty( $args['items_per_page'] ) ? (int) $args['items_per_page'] : 20;

        // get keys v1.
        $all_keys_v1 = MainWP_Rest_Api_Page::check_rest_api_updates();
        if ( ! empty( $all_keys_v1 ) && is_array( $all_keys_v1 ) ) {
            foreach ( $all_keys_v1 as $key => $item ) {
                $perms       = explode( ',', $item['perms'] );
                $permissions = ! empty( $perms ) && is_array( $perms ) ?
                    array_map(
                        function ( $per ) {
                            return current( $this->get_permissions_title( $per ) );
                        },
                        $perms
                    )
                    : array(); // Get permissions title.

                $record = array(
                    'id'            => $key,
                    'version'       => 'v1',
                    'description'   => ! empty( $item['desc'] ) ? $item['desc'] : '',
                    'permissions'   => array_unique( $permissions ),
                    'truncated_key' => substr( $key, -7 ),
                    'active'        => $item['enabled'] ? true : false,
                );

                // Filter response data by allowed fields.
                $data[] = $this->filter_response_data_by_allowed_fields( $record, 'view_v1' );
            }
        }

        // get all keys v2.
        $all_keys_v2 = $this->db->get_rest_api_keys();
        if ( ! empty( $all_keys_v2 ) && is_array( $all_keys_v2 ) ) {
            foreach ( $all_keys_v2 as $key ) {
                $record = array(
                    'id'            => $key->key_id ? $key->key_id : '',
                    'user_id'       => $key->user_id ? $key->user_id : '',
                    'version'       => 'v2',
                    'description'   => $key->description ? $key->description : '',
                    'permissions'   => ! empty( $key->permissions ) ? $this->get_permissions_title( $key->permissions ) : '',
                    'truncated_key' => $key->truncated_key ? $key->truncated_key : '',
                    'active'        => (bool) $key->enabled ? true : false,
                    'last_access'   => ! empty( $key->last_access ) ? gmdate( 'Y-m-d H:i:s', strtotime( $key->last_access ) ) : '',
                );

                // Filter response data by allowed fields.
                $data[] = $this->filter_response_data_by_allowed_fields( $record, 'view' );
            }
        }

        // Pagination.
        $total = count( $data );
        $pages = (int) max( 1, ceil( $total / max( 1, $per_page ) ) );
        if ( $page > $pages ) {
            $page = $pages;
        }

        $offset = ( $page - 1 ) * $per_page;
        $data   = array_slice( $data, $offset, $per_page );

        return rest_ensure_response(
            array(
                'success' => 1,
                'total'   => $total,
                'pages'   => $pages,
                'data'    => $data,
            )
        );
    }

    /**
     * Create new key.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_Rest_Api_Page::mainwp_generate_rand_hash()
     * @uses MainWP_DB::instance()->insert_rest_api_key()
     * @uses MainWP_Utility::update_option()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function create_new_key( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get request body.
        $body = $request->get_body_params();
        if ( empty( $body ) ) {
            return new WP_Error(
                'empty_body',
                __( 'Request body is empty.', 'mainwp' ),
            );
        }

        // Generate consumer key and secret.
        $_consumer_key    = MainWP_Rest_Api_Page::mainwp_generate_rand_hash();
        $_consumer_secret = MainWP_Rest_Api_Page::mainwp_generate_rand_hash();

        // Map data.
        $consumer_key    = 'ck_' . $_consumer_key;
        $consumer_secret = 'cs_' . $_consumer_secret;
        $active          = ! empty( $body['active'] ) ? 1 : 0;
        $permission      = ! empty( $body['permissions'] ) ? sanitize_text_field( wp_unslash( $body['permissions'] ) ) : '';
        $desc            = ! empty( $body['description'] ) ? sanitize_text_field( wp_unslash( $body['description'] ) ) : '';
        $scope           = $this->determine_scope( $permission );

        try {
            // Save api key v2.
            $api_key = $this->db->insert_rest_api_key( $consumer_key, $consumer_secret, $scope, $desc, $active );
            if ( empty( $api_key['key_id'] ) ) {
                return new WP_Error(
                    'create_key_failed',
                    __( 'Create API key failed.', 'mainwp' ),
                );
            }
        } catch ( Exception $e ) {
            return new WP_Error(
                'create_key_failed',
                __( 'Create API key failed.', 'mainwp' ),
            );
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => esc_html__( 'API Key created successfully.', 'mainwp' ),
                'token'   => $_consumer_secret . '==' . $_consumer_key,
            )
        );
    }

    /**
     * Edit key.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB::instance()->update_rest_api_key()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function edit_key( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get API key.
        $api_key = $this->get_request_item( $request );
        if ( empty( $api_key ) ) {
            return new WP_Error(
                'api_key_not_found',
                __( 'API key not found.', 'mainwp' ),
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

        // Get key identifier.
        $cons_key_id = $request->get_param( 'key_identifier' );

        // Determine defaults based on key version (v1 = array, v2 = object).
        if ( is_numeric( $cons_key_id ) ) {
            $current_enabled     = $api_key->enabled ?? 0;
            $current_description = $api_key->description ?? '';
            $current_permissions = $api_key->permissions ?? 'read';
        } else {
            $current_enabled     = $api_key['enabled'] ?? 0;
            $current_description = $api_key['desc'] ?? '';
            $current_permissions = $api_key['perms'] ?? 'r';
        }

        // Determine active.
        $active = $current_enabled;
        if ( isset( $body['active'] ) ) {
            $active = $body['active'] ? 1 : 0;
        }
        // Determine description.
        $desc = ! empty( $body['description'] ) ? sanitize_text_field( wp_unslash( $body['description'] ) ) : $current_description;

        // Edit api key v1.
        if ( ! is_numeric( $cons_key_id ) ) {
            $scope    = ! empty( $body['permissions'] ) ? $this->determine_scope( $body['permissions'], 'v1' ) : $current_permissions;
            $save     = false;
            $all_keys = get_option( 'mainwp_rest_api_keys', false );
            if ( is_array( $all_keys ) && isset( $all_keys[ $cons_key_id ] ) ) {
                $item = $all_keys[ $cons_key_id ];
                if ( is_array( $item ) && isset( $item['cs'] ) ) {
                    $item['desc']             = $desc;
                    $item['enabled']          = $active;
                    $item['perms']            = $scope;
                    $all_keys[ $cons_key_id ] = $item;
                    $save                     = true;
                }
            }

            if ( ! $save ) {
                return new WP_Error(
                    'update_key_failed',
                    __( 'Update API key failed.', 'mainwp' ),
                );
            }

            MainWP_Utility::update_option( 'mainwp_rest_api_keys', $all_keys );
        } else {
            $scope = ! empty( $body['permissions'] ) ? $this->determine_scope( $body['permissions'] ) : $current_permissions;
            // Update api key.
            $updated = MainWP_DB::instance()->update_rest_api_key( $api_key->key_id, $scope, $desc, $active );
            if ( false === $updated ) {
                return new WP_Error(
                    'update_key_failed',
                    __( 'Update API key failed.', 'mainwp' ),
                );
            }
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => esc_html__( 'API Key updated successfully.', 'mainwp' ),
            )
        );
    }

    /**
     * Delete key.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB::instance()->remove_rest_api_key()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function delete_key( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get API key.
        $api_key = $this->get_request_item( $request );
        if ( empty( $api_key ) ) {
            return new WP_Error(
                'api_key_not_found',
                __( 'API key not found.', 'mainwp' ),
            );
        }

        $cons_key_id = $request->get_param( 'key_identifier' );
        // Delete api key v2.
        if ( is_numeric( $cons_key_id ) ) {
            $deleted = $this->db->remove_rest_api_key( $api_key->key_id );
            if ( false === $deleted ) {
                return new WP_Error(
                    'delete_key_failed',
                    __( 'Delete API key failed.', 'mainwp' ),
                );
            }
        } else {
            // Delete api key v1.
            $save     = false;
            $all_keys = get_option( 'mainwp_rest_api_keys', false );
            if ( is_array( $all_keys ) && isset( $all_keys[ $cons_key_id ] ) ) {
                $item = $all_keys[ $cons_key_id ];
                if ( is_array( $item ) && isset( $item['cs'] ) ) {
                    unset( $all_keys[ $cons_key_id ] ); // delete key.
                    $save = true;
                }
            }

            if ( ! $save ) {
                return new WP_Error(
                    'delete_key_failed',
                    __( 'Delete API key failed.', 'mainwp' ),
                );
            }
            MainWP_Utility::update_option( 'mainwp_rest_api_keys', $all_keys );
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => esc_html__( 'API Key deleted successfully.', 'mainwp' ),
            )
        );
    }

    /**
     * Allowed fields for list API keys.
     *
     * @return array Allowed fields.
     */
    public function rest_api_list_keys_allowed_fields() {
        return array(
            'page'     => array(
                'required'          => false,
                'type'              => 'integer',
                'default'           => 1,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
                'description'       => __( 'Page number.', 'mainwp' ),
            ),
            'per_page' => array(
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'minimum'           => 1,
                'default'           => 20,
                'maximum'           => 200,
                'description'       => __( 'Number of keys per page.', 'mainwp' ),
            ),
        );
    }

    /**
     * Allowed fields for add API key.
     *
     * @return array Allowed fields.
     */
    public function rest_api_add_key_allowed_fields() {
        return array(
            'active'      => array(
                'required'          => true,
                'type'              => 'boolean',
                'description'       => __( 'Active or disable API key.', 'mainwp' ),
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'description' => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'API key description.', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'permissions' => array(
                'required'          => true,
                'type'              => 'string',
                'description'       => __( 'API key permissions.', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'rest_api_validate_permissions_param' ),
            ),
        );
    }

    /**
     * Allowed fields for edit API key.
     *
     * @return array Allowed fields.
     */
    public function rest_api_edit_key_allowed_fields() {
        return array_merge(
            $this->get_api_allowed_id_field(),
            array(
                'active'      => array(
                    'required'          => false,
                    'type'              => 'boolean',
                    'description'       => __( 'Active or disable API key.', 'mainwp' ),
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ),
                'description' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'description'       => __( 'API key description.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'permissions' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'description'       => __( 'API key permissions.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => array( $this, 'rest_api_validate_permissions_param' ),
                ),
            )
        );
    }

    /**
     * Validate method parameter.
     *
     * @param string          $value Method value.
     * @param WP_REST_Request $request Request object.
     * @param string          $param Parameter name.
     * @return bool|WP_Error
     */
    public function rest_api_validate_permissions_param( $value, $request, $param ) {
        if ( empty( $value ) ) {
            return false;
        }

        $key                 = explode( ',', $value );
        $allowed_permissions = array( 'read', 'write' );
        if ( ! empty( $key ) && is_array( $key ) ) {
            foreach ( $key as $per ) {
                if ( ! in_array( $per, $allowed_permissions, true ) ) {
                    return new WP_Error(
                        'invalid_method',
                        __( 'Invalid method value.', 'mainwp' ),
                    );
                }
            }
        }
        return true;
    }

    /**
     * Determine scope string based on permission list.
     *
     * @param string|array $permission  Permission string (comma-separated) or array.
     *  @param string       $version API version.
     * @return string  Returns one of: 'read', 'write', or 'read_write'.
     */
    private function determine_scope( $permission, $version = 'v2' ) { // phpcs:ignore -- NOSONAR - complex.
        $scope = 'read'; // Default scope.
        if ( empty( $permission ) ) {
            return ( 'v2' === $version ) ? $scope : 'r';
        }

        if ( is_string( $permission ) ) {
            $pers_list = explode( ',', strtolower( $permission ) );
        } elseif ( is_array( $permission ) ) {
            $pers_list = array_map( 'strtolower', $permission );
        } else {
            return ( 'v2' === $version ) ? $scope : 'r';
        }

        // Trim all values.
        $pers_list = array_map( 'trim', $pers_list );

        // If delete is set, but write is not, add write.
        if ( in_array( 'delete', $pers_list, true ) && ! in_array( 'write', $pers_list, true ) ) {
            $pers_list[] = 'write';
        }

        // v2: keep existing behavior.
        if ( 'v2' === $version ) {
            if ( in_array( 'write', $pers_list, true ) && in_array( 'read', $pers_list, true ) ) {
                $scope = 'read_write';
            } elseif ( in_array( 'write', $pers_list, true ) || in_array( 'delete', $pers_list, true ) ) {
                $scope = 'write';
            } elseif ( in_array( 'read', $pers_list, true ) ) {
                $scope = 'read';
            }
            return $scope;
        }

        // v1: map to r/w/d.
        $out = array();

        if ( in_array( 'read', $pers_list, true ) ) {
            $out[] = 'r';
        }
        if ( in_array( 'write', $pers_list, true ) || in_array( 'delete', $pers_list, true ) ) {
            $out[] = 'w';
            $out[] = 'd';
        }

        if ( empty( $out ) ) {
            $out[] = 'r';
        }

        return implode( ',', array_unique( $out ) );
    }
    /**
     * Get permissions title.
     *
     * @param string $per permissions.
     *
     * @return array
     */
    private function get_permissions_title( $per ) {
        $titles = array();
        switch ( $per ) {
            case 'read':
            case 'r':
                $titles[] = esc_html__( 'Read', 'mainwp' );
                break;
            case 'write':
            case 'delete':
            case 'w':
            case 'd':
                $titles[] = esc_html__( 'Write & Delete', 'mainwp' );
                break;
            case 'read_write':
                $titles[] = esc_html__( 'Read', 'mainwp' );
                $titles[] = esc_html__( 'Write & Delete', 'mainwp' );
                break;
            default:
                $titles[] = esc_html__( 'Unknown', 'mainwp' );
                break;
        }

        return $titles;
    }

    /**
     * Get monitor by.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by()
     *
     * @return WP_Error|Object Item.
     */
    private function get_request_item( $request ) {  // phpcs:ignore -- NOSONAR
        // Get id or domain raw value.
        $id = $request->get_param( 'key_identifier' );

        if ( empty( $id ) ) {
            return false;
        }

        // Get API by id.
        if ( is_numeric( $id ) ) {
            return $this->db->get_rest_api_key_by( $id );
        }

        $all_keys = get_option( 'mainwp_rest_api_keys', false );
        if ( empty( $all_keys ) || ! is_array( $all_keys ) ) {
            return false;
        }
        return isset( $all_keys[ $id ] ) ? $all_keys[ $id ] : false;
    }

    /**
     * Get allowed fields for monitors.
     *
     * @return array
     */
    public function get_api_allowed_id_field() {
        return array(
            'key_identifier' => array(
                'description'       => __( 'API KEY ID.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    /**
     * Get the Tags schema, conforming to JSON Schema.
     *
     * @since  5.2
     * @return array
     */
    public function get_item_schema() {  // phpcs:ignore -- NOSONAR - long schema.
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'api_keys',
            'type'       => 'object',
            'properties' => array(
                'id'            => array(
                    'type'        => 'string',
                    'description' => __( 'API Key ID.', 'mainwp' ),
                    'context'     => array( 'view', 'view_v1', 'simple_view' ),
                    'readonly'    => true,
                ),
                'user_id'       => array(
                    'type'        => 'integer',
                    'readonly'    => true,
                    'description' => __( 'User ID', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'version'       => array(
                    'type'        => 'string',
                    'readonly'    => true,
                    'description' => __( 'API Key version.', 'mainwp' ),
                    'context'     => array( 'view', 'view_v1' ),
                ),
                'description'   => array(
                    'type'        => 'string',
                    'readonly'    => true,
                    'description' => __( 'API Key description.', 'mainwp' ),
                    'context'     => array( 'view', 'simple_view', 'view_v1' ),
                ),
                'permissions'   => array(
                    'type'        => 'array',
                    'description' => __( 'API Key permissions.', 'mainwp' ),
                    'context'     => array( 'view', 'view_v1' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'name' => array(
                                'type'        => 'string',
                                'description' => __( 'Permission name.', 'mainwp' ),
                            ),
                        ),
                    ),
                ),
                'truncated_key' => array(
                    'type'        => 'string',
                    'description' => __( 'API Key truncated.', 'mainwp' ),
                    'context'     => array( 'view', 'view_v1' ),
                ),
                'active'        => array(
                    'type'        => 'boolean',
                    'description' => __( 'API Key active.', 'mainwp' ),
                    'context'     => array( 'view', 'view_v1' ),
                ),
                'last_access'   => array(
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'description' => __( 'API Key last access.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
            ),
        );
    }
}
