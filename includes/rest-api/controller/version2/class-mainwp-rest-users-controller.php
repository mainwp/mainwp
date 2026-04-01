<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_Bulk_Add;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Error_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Class MainWP_Rest_Users_Controller
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Users_Controller extends MainWP_REST_Controller { //phpcs:ignore -- NOSONAR - multi methods.

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
    protected $rest_base = 'users';

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
        add_filter( 'mainwp_rest_users_fields_object_query', array( $this, 'users_fields_custom_query_args' ), 10, 2 );
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
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_users' ),
                    'args'                => $this->get_users_fields_allowed_fields(),
                    'validate_callback'   => array( $this, 'users_validate_filter_params' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/create',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_user' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'validate_callback'   => array( $this, 'users_validate_filter_params' ),
                    'args'                => $this->create_users_fields_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/(?P<user_id>[\d]+)/edit',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'edit_user' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'validate_callback'   => array( $this, 'edit_validate_filter_params' ),
                    'args'                => $this->edit_users_fields_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/(?P<user_id>[\d]+)/delete',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_user' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => array_merge(
                        $this->get_users_fields_allowed_fields(),
                        $this->edit_users_fields_allowed_fields()
                    ),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/update-admin-password',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'update_admin_password' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'validate_callback'   => array( $this, 'users_validate_filter_params' ),
                    'args'                => $this->update_admin_password_fields_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/import',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'import_users' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->import_users_fields_allowed_fields(),
                ),
            )
        );
    }

    /**
     * Add custom query args for pages.
     *
     * @param array           $args    Query args.
     * @param WP_REST_Request $request Request object.
     *
     * @return array
     */
    public function users_fields_custom_query_args( $args, $request ) {
        if ( ! empty( $request['clients'] ) ) {
            $args['clients'] = $request['clients'];
        }
        if ( ! empty( $request['groups'] ) ) {
            $args['groups'] = $request['groups'];
        }
        if ( ! empty( $request['websites'] ) ) {
            $args['websites'] = $request['websites'];
        }
        if ( ! empty( $request['roles'] ) ) {
            $args['roles'] = $request['roles'];
        }
        return $args;
    }

    /**
     * Get all general settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_users( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Prepare query parameters.
        $args = $this->prepare_objects_query( $request, 'users_fields' );

        // Get target websites.
        $websites_data = $this->get_websites_for_users_query( $args );
        if ( is_wp_error( $websites_data ) ) {
            return $websites_data;
        }

        // Prepare search parameters.
        $post_data = $this->prepare_users_search_data( $args );

        // Fetch users from child sites.
        $output = $this->fetch_users_from_sites( $websites_data['db_websites'], $post_data );

        // Handle fetch errors.
        $error_response = $this->handle_fetch_errors( $output );
        if ( is_wp_error( $error_response ) ) {
            return $error_response;
        }

        // Process and format results.
        $results = $this->process_users_results( $output->results, $websites_data['website_url'] );

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $results,
            )
        );
    }

    /**
     * Create user.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function create_user( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Validate request.
        $validation_error = $this->validate_user_request( $request );
        if ( is_wp_error( $validation_error ) ) {
            return $validation_error;
        }

        // Get request body.
        $body = $this->get_request_body( $request );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // Get target websites.
        $db_websites = $this->get_target_websites( $body );
        if ( is_wp_error( $db_websites ) ) {
            return $db_websites;
        }

        // Prepare user data.
        $user_to_add = $this->prepare_user_data( $body );
        $post_data   = $this->prepare_post_data( $user_to_add, $body );

        // Execute user creation on child sites.
        $output = $this->execute_user_creation( $db_websites, $post_data );

        // Process and return results.
        return $this->process_creation_results( $output, $db_websites, $user_to_add );
    }

    /**
     * Edit user.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function edit_user( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get website.
        $website = $this->get_request_website_by_id_domain_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        // Get user information.
        $user_info = $this->get_request_user_by_id( $website, $request );
        if ( is_wp_error( $user_info ) ) {
            return $user_info;
        }

        // Validate request.
        $validation_error = $this->validate_user_request( $request );
        if ( is_wp_error( $validation_error ) ) {
            return $validation_error;
        }

        // Get request body.
        $body = $this->get_request_body( $request );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // Prepare user update data.
        $user_data = $this->prepare_edit_user_data( $body, $user_info );

        // Execute user update.
        $result = $this->execute_user_update( $website, $user_data, $request );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => esc_html__( 'User updated successfully.', 'mainwp' ),
                'data'    => array(
                    'website_id'   => $website->id,
                    'website_url'  => $website->url,
                    'user_id'      => $request->get_param( 'user_id' ),
                    'updated_data' => $user_data,
                ),
            )
        );
    }

    /**
     * Delete user.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function delete_user( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get website.
        $website = $this->get_request_website_by_id_domain_item( $request );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        // Get user information.
        $user_info = $this->get_request_user_by_id( $website, $request );
        if ( is_wp_error( $user_info ) ) {
            return $user_info;
        }

        // Execute user deletion.
        $result = $this->execute_user_deletion( $website, $request );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => esc_html__( 'User deleted successfully.', 'mainwp' ),
            )
        );
    }

    /**
     * Update admin password.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_admin_password( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Validate request.
        $validation_error = $this->validate_user_request( $request );
        if ( is_wp_error( $validation_error ) ) {
            return $validation_error;
        }

        // Get request body.
        $body = $this->get_request_body( $request );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // Get target websites.
        $db_websites = $this->get_target_websites( $body );
        if ( is_wp_error( $db_websites ) ) {
            return $db_websites;
        }

        $pass_complexity = apply_filters( 'mainwp_new_user_password_complexity', '24' );
        $password        = ! empty( $body['password'] ) ? $body['password'] : wp_generate_password( $pass_complexity );

        $output = $this->execute_update_admin_password( $db_websites, $password, $request );
        if ( is_wp_error( $output ) ) {
            return $output;
        }

        return $this->process_update_admin_password_results( $output, $db_websites );
    }

    /**
     * Import users from CSV file.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function import_users( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $files = $request->get_file_params();

        // Validate request.
        $file = $this->validation_import_users_request( $files, $request );
        if ( is_wp_error( $file ) ) {
            return $file;
        }

        // Parse CSV file.
        $csv_data = $this->parse_csv_file( $file['tmp_name'], $request->get_param( 'has_header' ) );
        if ( is_wp_error( $csv_data ) ) {
            return $csv_data;
        }

        // Validate and process users.
        $results = $this->process_csv_users( $csv_data );

        return rest_ensure_response( $results );
    }

    /**
     * Validate import users request.
     *
     * @param array           $files   Array of uploaded files.
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return array|WP_Error Array of file data or WP_Error on failure.
     */
    private function validation_import_users_request( $files, $request ) { // phpcs:ignore -- NOSONAR - complex.
        $uploaded = isset( $files['csv_file'] ) ? $files['csv_file'] : null;
        if ( empty( $uploaded ) ) {
            $ct  = $request->get_header( 'content-type' );
            $msg = __( 'No CSV file uploaded.', 'mainwp' );
            if ( $ct && false !== stripos( $ct, 'multipart/form-data' ) ) {
                $msg .= ' ' . __( 'If you manually set Content-Type: multipart/form-data, remove it and let the client add the boundary automatically.', 'mainwp' );
            }
            return new WP_Error( 'no_file_uploaded', $msg, array( 'status' => 400 ) );
        }

        $file = $uploaded;

        // Validate file type.
        $file_type = wp_check_filetype( $file['name'] );
        if ( ! in_array( $file_type['ext'], array( 'csv', 'txt' ), true ) ) {
            return new WP_Error(
                'invalid_file_type',
                __( 'Invalid file type. Only CSV files are allowed.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        // Check for upload errors.
        if ( UPLOAD_ERR_OK !== $file['error'] ) {
            return new WP_Error(
                'upload_error',
                __( 'File upload error.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }
        return $file;
    }

    /**
     * Parse CSV file and extract user data.
     *
     * @param string $file_path Path to CSV file.
     * @param bool   $has_header Whether CSV has header row.
     * @return array|WP_Error Array of user data or WP_Error on failure.
     */
    private function parse_csv_file( $file_path, $has_header = true ) { // phpcs:ignore -- NOSONAR - complex.
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return new WP_Error(
                'file_not_readable',
                __( 'CSV file is not readable.', 'mainwp' )
            );
        }

        MainWP_System_Utility::get_wp_file_system();
        global $wp_filesystem;

        $content = $wp_filesystem->get_contents( $file_path );
        if ( empty( $content ) ) {
            return new WP_Error(
                'empty_file',
                __( 'CSV file is empty.', 'mainwp' )
            );
        }

        // Parse CSV content.
        $lines = explode( "\n", str_replace( "\r\n", "\n", $content ) );
        $lines = array_filter( array_map( 'trim', $lines ) );

        if ( empty( $lines ) ) {
            return new WP_Error(
                'no_data',
                __( 'No data found in CSV file.', 'mainwp' )
            );
        }

        // Remove header if present.
        if ( $has_header ) {
            array_shift( $lines );
        }

        $users_data  = array();
        $line_number = $has_header ? 2 : 1;

        foreach ( $lines as $line ) {
            if ( empty( $line ) ) {
                continue;
            }

            $items = str_getcsv( $line );
            if ( count( $items ) < 10 ) {
                return new WP_Error(
                    'invalid_csv_format',
                    sprintf(
                        /* translators: %d: line number */
                        __( 'Invalid CSV format at line %d. Expected 10 columns.', 'mainwp' ),
                        $line_number
                    )
                );
            }

            $users_data[] = array(
                'line_number'   => $line_number,
                'username'      => sanitize_text_field( $items[0] ),
                'email'         => sanitize_email( $items[1] ),
                'first_name'    => sanitize_text_field( $items[2] ),
                'last_name'     => sanitize_text_field( $items[3] ),
                'user_url'      => esc_url_raw( $items[4] ),
                'password'      => $items[5], // Don't sanitize password.
                'send_password' => filter_var( $items[6], FILTER_VALIDATE_BOOLEAN ),
                'role'          => sanitize_text_field( strtolower( $items[7] ) ),
                'select_sites'  => sanitize_text_field( $items[8] ),
                'select_groups' => sanitize_text_field( $items[9] ),
            );

            ++$line_number;
        }

        return $users_data;
    }

    /**
     * Process CSV users data and create users on child sites.
     *
     * @param array $users_data Array of user data from CSV.
     * @return array Results array with success and failed users.
     */
    private function process_csv_users( $users_data ) { // phpcs:ignore -- NOSONAR - complex.
        $results = array(
            'total'         => count( $users_data ),
            'success_count' => 0,
            'failed_count'  => 0,
            'success_users' => array(),
            'failed_users'  => array(),
        );

        foreach ( $users_data as $user_data ) {
            // Validate user data.
            $validation_error = $this->validate_csv_user_data( $user_data );
            if ( is_wp_error( $validation_error ) ) {
                ++$results['failed_count'];
                $results['failed_users'][] = array(
                    'line_number' => $user_data['line_number'],
                    'username'    => $user_data['username'],
                    'email'       => $user_data['email'],
                    'error'       => $validation_error->get_error_message(),
                );
                continue;
            }

            // Get target websites for this user.
            $db_websites = $this->get_websites_for_csv_user( $user_data );
            if ( is_wp_error( $db_websites ) ) {
                ++$results['failed_count'];
                $results['failed_users'][] = array(
                    'line_number' => $user_data['line_number'],
                    'username'    => $user_data['username'],
                    'email'       => $user_data['email'],
                    'error'       => $db_websites->get_error_message(),
                );
                continue;
            }

            if ( empty( $db_websites ) ) {
                ++$results['failed_count'];
                $results['failed_users'][] = array(
                    'line_number' => $user_data['line_number'],
                    'username'    => $user_data['username'],
                    'email'       => $user_data['email'],
                    'error'       => __( 'No valid websites found for this user.', 'mainwp' ),
                );
                continue;
            }

            // Prepare user data for creation.
            $user_to_add = $this->prepare_csv_user_for_creation( $user_data );
            $post_data   = array(
                'new_user'      => base64_encode( wp_json_encode( $user_to_add ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
                'send_password' => $user_data['send_password'] ? 1 : 0,
            );

            // Execute user creation.
            $output = $this->execute_user_creation( $db_websites, $post_data );

            // Process results for this user.
            $user_result = $this->process_single_csv_user_result( $output, $db_websites, $user_data );

            if ( $user_result['success'] ) {
                ++$results['success_count'];
                $results['success_users'][] = $user_result['data'];
            } else {
                ++$results['failed_count'];
                $results['failed_users'][] = $user_result['data'];
            }
        }

        return $results;
    }

    /**
     * Validate CSV user data.
     *
     * @param array $user_data User data from CSV.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    private function validate_csv_user_data( $user_data ) {  // phpcs:ignore -- NOSONAR - complex.
        // Validate required fields.
        if ( empty( $user_data['username'] ) ) {
            return new WP_Error(
                'missing_username',
                __( 'Username is required.', 'mainwp' )
            );
        }

        if ( empty( $user_data['email'] ) ) {
            return new WP_Error(
                'missing_email',
                __( 'Email is required.', 'mainwp' )
            );
        }

        // Validate email format.
        if ( ! is_email( $user_data['email'] ) ) {
            return new WP_Error(
                'invalid_email',
                __( 'Invalid email format.', 'mainwp' )
            );
        }

        // Validate role.
        $valid_roles = array( 'administrator', 'subscriber', 'editor', 'author', 'contributor' );
        if ( ! empty( $user_data['role'] ) && ! in_array( $user_data['role'], $valid_roles, true ) ) {
            return new WP_Error(
                'invalid_role',
                sprintf(
                    /* translators: %s: allowed roles */
                    __( 'Invalid role. Allowed roles: %s', 'mainwp' ),
                    implode( ', ', $valid_roles )
                )
            );
        }

        // Validate that at least one of select_sites or select_groups is provided.
        if ( empty( $user_data['select_sites'] ) && empty( $user_data['select_groups'] ) ) {
            return new WP_Error(
                'no_target_sites',
                __( 'Either select_sites or select_groups must be provided.', 'mainwp' )
            );
        }

        return true;
    }

    /**
     * Get websites for CSV user based on select_sites and select_groups.
     *
     * @param array $user_data User data from CSV.
     * @return array|WP_Error Array of websites or WP_Error on failure.
     */
    private function get_websites_for_csv_user( $user_data ) { // phpcs:ignore -- NOSONAR - complex.
        $utility        = MainWP_Utility::instance();
        $system_utility = new MainWP_System_Utility();
        $data_fields    = $system_utility->get_default_map_site_fields();
        $db_websites    = array();

        if ( ! empty( $user_data['select_sites'] ) ) {
            $site_urls = array_filter( array_map( 'trim', explode( ';', $user_data['select_sites'] ) ) );

            foreach ( $site_urls as $url ) {
                $websites = $this->db->get_websites_by_url( $url );
                if ( ! empty( $websites ) ) {
                    foreach ( $websites as $website ) {
                        if ( '' !== $website->sync_errors || $system_utility->is_suspended_site( $website ) ) {
                            continue;
                        }
                        $db_websites[ $website->id ] = $utility->map_site( $website, $data_fields );
                    }
                }
            }
        }

        if ( ! empty( $user_data['select_groups'] ) ) {
            $group_names = array_filter( array_map( 'trim', explode( ';', $user_data['select_groups'] ) ) );

            foreach ( $group_names as $group_name ) {
                $group = \MainWP\Dashboard\MainWP_DB_Common::instance()->get_group_by_name( $group_name );
                if ( ! $group ) {
                    continue;
                }

                $websites = $this->db->query( $this->db->get_sql_websites_by_group_id( $group->id ) );
                while ( $websites && ( $website = $this->db->fetch_object( $websites ) ) ) {
                    if ( '' !== $website->sync_errors || $system_utility->is_suspended_site( $website ) ) {
                        continue;
                    }
                    $db_websites[ $website->id ] = $utility->map_site( $website, $data_fields );
                }
                $this->db->free_result( $websites );
            }
        }

        return $db_websites;
    }

    /**
     * Prepare CSV user data for creation.
     *
     * @param array $user_data User data from CSV.
     * @return array Prepared user data.
     */
    private function prepare_csv_user_for_creation( $user_data ) {
        $user_to_add = array(
            'user_login' => $user_data['username'],
            'email'      => $user_data['email'],
            'first_name' => $user_data['first_name'],
            'last_name'  => $user_data['last_name'],
            'url'        => $user_data['user_url'],
            'role'       => ! empty( $user_data['role'] ) ? $user_data['role'] : 'subscriber',
        );

        // Add password if provided.
        if ( ! empty( $user_data['password'] ) ) {
            $user_to_add['user_pass'] = $user_data['password'];
        }

        return $user_to_add;
    }

    /**
     * Process result for a single CSV user.
     *
     * @param \stdClass $output Output from user creation.
     * @param array     $db_websites Array of websites.
     * @param array     $user_data Original user data from CSV.
     * @return array Result array with success flag and data.
     */
    private function process_single_csv_user_result( $output, $db_websites, $user_data ) { // phpcs:ignore -- NOSONAR - complex.
        $success_sites = array();
        $failed_sites  = array();

        foreach ( $db_websites as $site_id => $website ) {
            if ( isset( $output->ok[ $site_id ] ) && 1 === (int) $output->ok[ $site_id ] ) {
                $success_sites[] = array(
                    'id'   => $site_id,
                    'name' => isset( $website->name ) ? $website->name : '',
                    'url'  => isset( $website->url ) ? $website->url : '',
                );
            } else {
                $error_message  = isset( $output->errors[ $site_id ] ) ? $output->errors[ $site_id ] : __( 'Unknown error occurred.', 'mainwp' );
                $failed_sites[] = array(
                    'id'      => $site_id,
                    'name'    => isset( $website->name ) ? $website->name : '',
                    'url'     => isset( $website->url ) ? $website->url : '',
                    'message' => $error_message,
                );
            }
        }

        $success = ! empty( $success_sites );

        return array(
            'success' => $success,
            'data'    => array(
                'line_number'   => $user_data['line_number'],
                'username'      => $user_data['username'],
                'email'         => $user_data['email'],
                'success_sites' => $success_sites,
                'failed_sites'  => $failed_sites,
                'total_sites'   => count( $db_websites ),
                'success_count' => count( $success_sites ),
                'failed_count'  => count( $failed_sites ),
            ),
        );
    }

    /**
     * Process update admin password results.
     *
     * @param stdClass $output Output object with ok and errors arrays.
     * @param array    $db_websites Array of websites.
     * @return WP_REST_Response Response object with success and failed websites.
     */
    private function process_update_admin_password_results( $output, $db_websites ) { // phpcs:ignore -- NOSONAR - complex.
        $success_websites = array();
        $failed_websites  = array();

        foreach ( $db_websites as $site_id => $website ) {
            if ( isset( $output->ok[ $site_id ] ) && 1 === (int) $output->ok[ $site_id ] ) {
                $success_websites[] = array(
                    'id'   => $site_id,
                    'name' => isset( $website->name ) ? $website->name : '',
                    'url'  => isset( $website->url ) ? $website->url : '',
                );
            } else {
                $error_message     = isset( $output->errors[ $site_id ] ) ? $output->errors[ $site_id ] : __( 'Unknown error occurred.', 'mainwp' );
                $failed_websites[] = array(
                    'id'      => $site_id,
                    'name'    => isset( $website->name ) ? $website->name : '',
                    'url'     => isset( $website->url ) ? $website->url : '',
                    'message' => $error_message,
                );
            }
        }

        $total_websites = count( $db_websites );
        $success_count  = count( $success_websites );
        $failed_count   = count( $failed_websites );

        return rest_ensure_response(
            array(
                'success'          => true,
                'message'          => sprintf(
                    /* translators: 1: success count, 2: total count */
                    __( 'Admin password updated on %1$d of %2$d websites.', 'mainwp' ),
                    $success_count,
                    $total_websites
                ),
                'total_websites'   => $total_websites,
                'success_count'    => $success_count,
                'failed_count'     => $failed_count,
                'success_websites' => $success_websites,
                'failed_websites'  => $failed_websites,
            )
        );
    }

    /**
     * Summary of execute_update_admin_password
     *
     * @param mixed $db_websites Array of websites.
     * @param mixed $password New password.
     * @param mixed $request Request object.
     * @return stdClass|WP_Error
     */
    private function execute_update_admin_password( $db_websites, $password, $request ) { // phpcs:ignore -- NOSONAR - complex.
        $post_data      = array( 'new_password' => base64_encode( $password ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        $output         = new \stdClass();
        $output->ok     = array();
        $output->errors = array();
        try {
            MainWP_Connect::fetch_urls_authed(
                $db_websites,
                'newadminpassword',
                $post_data,
                array(
                    MainWP_Bulk_Add::get_class_name(),
                    'posting_bulk_handler',
                ),
                $output
            );

            return $output;
        } catch ( MainWP_Exception $e ) {
            return new WP_Error( 'update_admin_password_error', MainWP_Error_Helper::get_error_message( $e ) );
        }
    }

    /**
     * Execute user deletion on child site.
     *
     * @param object          $website Website object.
     * @param WP_REST_Request $request Full details about the request.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    private function execute_user_deletion( $website, $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Check if the user is the same user currently connecting to mainwp.
        $user_id = (int) $request->get_param( 'user_id' );
        if ( (int) $website->userid === $user_id ) {
            return new WP_Error( 'user_not_found', __( 'This user is used for our secure link, it can not be deleted.', 'mainwp' ) );
        }
        try {

            /**
             * Action: mainwp_before_user_action
             *
             * Fires before user edit/delete/update_user/update_password actions.
             *
             * @since 4.1
             */
            do_action( 'mainwp_before_user_action', 'delete', $user_id, '', '', 1, $website );

            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'user_action',
                array(
                    'action'    => 'delete',
                    'id'        => $user_id,
                    'extra'     => '',
                    'user_pass' => '',
                    'optimize'  => 1,
                )
            );

            if ( is_array( $information ) && isset( $information['status'] ) && ( 'SUCCESS' === $information['status'] ) ) {
                $data = isset( $information['other_data']['users_data'] ) ? $information['other_data']['users_data'] : array();  // user actions data.

                /**
                 * Fires immediately after user action.
                 *
                 * @since 4.5.1.1
                 */
                do_action( 'mainwp_user_action', $website, 'delete', $data, '', 1 );

                return true;
            }
            return new WP_Error( 'user_deletion_error', __( 'User deletion failed.', 'mainwp' ) );
        } catch ( MainWP_Exception $e ) {
            return new WP_Error( 'user_deletion_error', MainWP_Error_Helper::get_error_message( $e ) );
        }
    }
    /**
     * Prepare user data for update.
     *
     * @param array $body Request body data.
     * @param array $user_info Current user information.
     * @return array User data array.
     */
    private function prepare_edit_user_data( $body, $user_info ) { // phpcs:ignore -- NOSONAR - complex.
        $user_data = array();

        // Map editable fields.
        $field_mapping = array(
            'password'     => 'user_pass',
            'email'        => 'email',
            'role'         => 'role',
            'first_name'   => 'first_name',
            'last_name'    => 'last_name',
            'user_url'     => 'url',
            'nickname'     => 'nickname',
            'display_name' => 'display_name',
            'description'  => 'description',
        );

        foreach ( $field_mapping as $request_field => $user_field ) {
            if ( isset( $body[ $request_field ] ) && '' !== $body[ $request_field ] ) {
                $user_data[ $user_field ] = $body[ $request_field ];
            }
        }

        //
        // Set default role if not provided.
        if ( empty( $user_data['role'] ) ) {
            $user_data['role'] = 'donotupdate';
        }

        // Handle password update.
        if ( ! empty( $user_data['user_pass'] ) ) {
            $pass = '';
            if ( function_exists( '\mb_convert_encoding' ) ) {
                $pass = \mb_convert_encoding( $user_data['user_pass'], 'ISO-8859-1', 'UTF-8' );
            } else {
                // phpcs:disable Generic.PHP.DeprecatedFunctions.Deprecated
                $pass = utf8_decode( $user_data['user_pass'] ); // to compatible.
                // phpcs:enable Generic.PHP.DeprecatedFunctions.Deprecated
            }

            if ( ! empty( $pass ) ) {
                $user_data['pass1'] = $pass;
                $user_data['pass2'] = $pass;
            }
        }

        return $user_data;
    }

    /**
     * Execute user update on child site.
     *
     * @param object          $website Website object.
     * @param array           $user_data User data to update.
     * @param WP_REST_Request $request Full details about the request.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    private function execute_user_update( $website, $user_data, $request ) { // phpcs:ignore -- NOSONAR - complex.
        $user_id   = (int) $request->get_param( 'user_id' );
        $user_pass = isset( $user_data['user_pass'] ) ? $user_data['user_pass'] : '';

        // Check if the user is the same user currently connecting to mainwp.
        if ( ! empty( $user_data['role'] ) && (int) $user_id === (int) $website->userid ) {
            return new WP_Error( 'user_update_error', __( 'This user is used for our secure link, it can not be changed.', 'mainwp' ) );
        }
        /**
         * Action: mainwp_before_user_action
         *
         * Fires before user update.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_user_action', 'update_user', $user_id, $user_data, $user_pass, 1, $website );

        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'user_action',
                array(
                    'action'    => 'update_user',
                    'id'        => $user_id,
                    'extra'     => $user_data,
                    'user_pass' => $user_pass,
                    'optimize'  => 1,
                )
            );

            if ( is_array( $information ) && isset( $information['error'] ) ) {
                return new WP_Error(
                    'user_update_error',
                    esc_html( $information['error'] )
                );
            }

            if ( ! isset( $information['status'] ) || 'SUCCESS' !== $information['status'] ) {
                return new WP_Error(
                    'user_update_failed',
                    esc_html__( 'User update failed. Unexpected error.', 'mainwp' )
                );
            }

            // Update cached user data if optimize is enabled.
            if ( isset( $information['users'] ) ) {
                $data = isset( $information['other_data']['users_data'] ) ? $information['other_data']['users_data'] : array();  // user actions data.

                /**
                 * Fires immediately after user action.
                 *
                 * @since 4.5.1.1
                 */
                do_action( 'mainwp_user_action', $website, 'update_user', $data, $user_data, 1 );

                $website_values['users'] = wp_json_encode( $information['users'] );
                MainWP_DB::instance()->update_website_values( $website->id, $website_values );
            }

            return true;
        } catch ( MainWP_Exception $e ) {
            return new WP_Error(
                'user_update_exception',
                MainWP_Error_Helper::get_error_message( $e )
            );
        }
    }

    /**
     * Map user data from child site to standard format.
     *
     * @param array $user_data Raw user data from child site.
     * @param int   $website_id Website ID.
     * @return array Mapped user data.
     */
    private function map_user_data( $user_data, $website_id ) {
        return array(
            'id'         => $user_data['id'] ?? '',
            'website_id' => $website_id,
            'username'   => $user_data['login'] ?? '',
            'email'      => $user_data['email'] ?? '',
            'role'       => $user_data['role'] ?? '',
            'posts'      => $user_data['post_count'] ?? 0,
            'name'       => $user_data['nicename'] ?? '',
        );
    }


    /**
     * Get websites for users query.
     *
     * @param array $args Query arguments.
     * @return array|WP_Error Array with db_websites and website_url or WP_Error on failure.
     */
    private function get_websites_for_users_query( $args ) {
        $clients = $args['clients'] ?? '';
        $groups  = $args['groups'] ?? '';
        $sites   = $args['websites'] ?? '';

        $filter_db_websites = $this->get_db_websites_by_filter( $sites, $groups, $clients );

        if ( empty( $filter_db_websites ) || ! is_array( $filter_db_websites ) ) {
            return new WP_Error(
                'no_website_found',
                __( 'No website found.', 'mainwp' ) // NOSONAR.
            );
        }

        $db_websites = $filter_db_websites['db_websites'] ?? array();
        $website_url = $filter_db_websites['website_url'] ?? array();

        if ( empty( $db_websites ) ) {
            return new WP_Error(
                'no_website_found',
                __( 'No website found.', 'mainwp' ) // NOSONAR.
            );
        }

        return array(
            'db_websites' => $db_websites,
            'website_url' => $website_url,
        );
    }

    /**
     * Prepare users search data.
     *
     * @param array $args Query arguments.
     * @return array Search data array.
     */
    private function prepare_users_search_data( $args ) {
        $search = $args['s'] ?? '';
        $roles  = $args['roles'] ?? '';

        return array(
            'role'           => is_array( $roles ) ? implode( ',', $roles ) : $roles,
            'search'         => '*' . trim( $search ) . '*',
            'search_columns' => 'user_login,display_name,user_email',
        );
    }

    /**
     * Fetch users from child sites.
     *
     * @param array $db_websites Array of websites.
     * @param array $post_data Search parameters.
     * @return \stdClass|WP_Error Output object with results and errors.
     */
    private function fetch_users_from_sites( $db_websites, $post_data ) {
        try {
            $output          = new \stdClass();
            $output->results = array();
            $output->errors  = array();

            MainWP_Connect::fetch_urls_authed(
                $db_websites,
                'search_users',
                $post_data,
                array(
                    'MainWP_REST_Controller',
                    'posts_pages_search_handler',
                ),
                $output
            );

            return $output;
        } catch ( MainWP_Exception $e ) {
            return new WP_Error( 'fetch_users_error', MainWP_Error_Helper::get_error_message( $e ) );
        }
    }

    /**
     * Handle fetch errors.
     *
     * @param \stdClass $output Output from fetch operation.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    private function handle_fetch_errors( $output ) {
        if ( empty( $output->results ) ) {
            if ( ! empty( $output->errors ) ) {
                return new WP_Error(
                    'users_fetch_error',
                    __( 'Error fetching users from child sites.', 'mainwp' ),
                    array( 'errors' => $output->errors )
                );
            }
            return new WP_Error(
                'no_users_found',
                __( 'No users found.', 'mainwp' )
            );
        }
        return true;
    }

    /**
     * Process users results and format for response.
     *
     * @param array $results Raw results from child sites.
     * @param array $website_url Website URL mapping.
     * @return array Formatted results array.
     */
    private function process_users_results( $results, $website_url ) {
        $formatted_results = array();

        foreach ( $results as $website_id => $users ) {
            if ( ! isset( $website_url[ $website_id ] ) ) {
                continue;
            }

            foreach ( $users as $user_index => $user_data ) {
                $user = $this->map_user_data( $user_data, $website_id );
                $formatted_results[ $website_url[ $website_id ] ][ $user_index ] = $this->filter_response_data_by_allowed_fields( $user, 'view' );
            }
        }

        return $formatted_results;
    }

    /**
     * Validate create user request.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    private function validate_user_request( $request ) {
        $content_type = $this->validate_content_type( $request );
        if ( is_wp_error( $content_type ) ) {
            return $content_type;
        }
        return true;
    }

    /**
     * Get target websites from request body.
     *
     * @param array $body Request body data.
     * @return array|WP_Error Array of websites or WP_Error on failure.
     */
    private function get_target_websites( $body ) {
        $clients = $body['clients'] ?? '';
        $groups  = $body['groups'] ?? '';
        $sites   = $body['websites'] ?? '';

        $filter_db_websites = $this->get_db_websites_by_filter( $sites, $groups, $clients );

        if ( empty( $filter_db_websites ) || ! is_array( $filter_db_websites ) ) {
            return new WP_Error(
                'no_website_found',
                __( 'No website found.', 'mainwp' )
            );
        }

        $db_websites = $filter_db_websites['db_websites'] ?? array();

        if ( empty( $db_websites ) ) {
            return new WP_Error(
                'no_website_found',
                __( 'No website found.', 'mainwp' )
            );
        }

        return $db_websites;
    }

    /**
     * Prepare user data from request body.
     *
     * @param array $body Request body data.
     * @return array User data array.
     */
    private function prepare_user_data( $body ) {
        $pass_complexity = apply_filters( 'mainwp_new_user_password_complexity', '24' );
        $password        = ! empty( $body['password'] ) ? $body['password'] : wp_generate_password( $pass_complexity );

        return array(
            'user_pass'  => $password,
            'user_email' => $body['email'] ?? '',
            'user_login' => $body['username'] ?? '',
            'user_url'   => $body['user_url'] ?? '',
            'first_name' => $body['first_name'] ?? '',
            'last_name'  => $body['last_name'] ?? '',
            'role'       => $body['role'] ?? 'subscriber',
        );
    }

    /**
     * Prepare post data for API request.
     *
     * @param array $user_to_add User data.
     * @param array $body Request body data.
     * @return array Post data array.
     */
    private function prepare_post_data( $user_to_add, $body ) {
        return array(
            'new_user'      => base64_encode( wp_json_encode( $user_to_add ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
            'send_password' => isset( $body['send_password'] ) ? intval( $body['send_password'] ) : 0,
        );
    }

    /**
     * Execute user creation on child sites.
     *
     * @param array $db_websites Array of websites.
     * @param array $post_data Post data for API request.
     * @return \stdClass|WP_Error Output object with results.
     */
    private function execute_user_creation( $db_websites, $post_data ) {
        try {
            $output         = new \stdClass();
            $output->ok     = array();
            $output->errors = array();

            /**
             * Action: mainwp_before_user_create
             *
             * Fires before user create.
             *
             * @since 4.1
             */
            do_action( 'mainwp_before_user_create', $post_data, $db_websites );

            MainWP_Connect::fetch_urls_authed(
                $db_websites,
                'newuser',
                $post_data,
                array(
                    MainWP_Bulk_Add::get_class_name(),
                    'posting_bulk_handler',
                ),
                $output
            );

            /**
             * Action: mainwp_after_user_create
             *
             * Fires after user create.
             *
             * @since 4.1
             */
            do_action( 'mainwp_after_user_create', $output, $post_data, $db_websites );

            return $output;
        } catch ( MainWP_Exception $e ) {
            return new WP_Error( 'create_user_error', MainWP_Error_Helper::get_error_message( $e ) );
        }
    }

    /**
     * Process creation results and prepare response.
     *
     * @param \stdClass $output Output from user creation.
     * @param array     $db_websites Array of websites.
     * @param array     $user_to_add User data.
     * @return WP_REST_Response Response object.
     */
    private function process_creation_results( $output, $db_websites, $user_to_add ) {
        $created_sites = array();
        $failed_sites  = array();

        foreach ( $db_websites as $website ) {
            if ( isset( $output->ok[ $website->id ] ) && 1 === (int) $output->ok[ $website->id ] ) {
                $created_sites[ $website->id ] = array(
                    'url'     => $website->url,
                    'message' => esc_html__( 'User created successfully.', 'mainwp' ),
                );
            } elseif ( isset( $output->errors[ $website->id ] ) ) {
                $failed_sites[ $website->id ] = array(
                    'url'     => $website->url,
                    'message' => $output->errors[ $website->id ],
                );
            }
        }

        // Count results.
        $total_success = count( $created_sites );
        $total_failed  = count( $failed_sites );
        $total_sites   = count( $db_websites );

        $message = $this->build_creation_message( $total_success, $total_failed, $total_sites );
        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => $message,
                'data'    => array(
                    'user_info'     => $user_to_add,
                    'total_sites'   => $total_sites,
                    'total_success' => $total_success,
                    'total_failed'  => $total_failed,
                    'created_sites' => $created_sites,
                    'failed_sites'  => $failed_sites,
                ),
            )
        );
    }

    /**
     * Build creation message based on results.
     *
     * @param int $total_success Total successful creations.
     * @param int $total_failed Total failed creations.
     * @param int $total_sites Total sites processed.
     * @return string Message string.
     */
    private function build_creation_message( $total_success, $total_failed, $total_sites ) {
        if ( 0 === $total_failed ) {
            return sprintf(
                /* translators: %d: number of websites */
                esc_html__( 'User created successfully on all %d website(s).', 'mainwp' ),
                $total_success
            );
        } elseif ( 0 === $total_success ) {
            return sprintf(
                /* translators: %d: number of websites */
                esc_html__( 'User creation failed on all %d website(s).', 'mainwp' ),
                $total_failed
            );
        } else {
            return sprintf(
                /* translators: 1: number of successful websites, 2: number of failed websites, 3: total websites */
                esc_html__( 'User created on %1$d of %3$d website(s). %2$d failed.', 'mainwp' ),
                $total_success,
                $total_failed,
                $total_sites
            );
        }
    }

    /**
     * Users Validate filter params.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    /**
     * Validate filter params.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function users_validate_filter_params( $request ) {
        $clients      = $request->get_param( 'clients' );
        $groups       = $request->get_param( 'groups' );
        $websites     = $request->get_param( 'websites' );
        $filled_count = count( array_filter( array( $clients, $groups, $websites ) ) );
        if ( $filled_count > 1 ) {
            return new WP_Error(
                'invalid_filter_params',
                __( 'Only one of clients, groups, or websites can be specified.', 'mainwp' ),
            );
        }
        return true;
    }

    /**
     * Validate edit user request parameters.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function edit_validate_filter_params( $request ) {
        // Define editable fields.
        $editable_fields = array(
            'password',
            'role',
            'first_name',
            'last_name',
            'user_url',
            'nickname',
            'display_name',
            'email',
            'description',
        );

        // Check if at least one editable field is provided and not empty.
        $has_field = false;
        foreach ( $editable_fields as $field ) {
            $value = $request->get_param( $field );
            if ( ! empty( $value ) || ( isset( $value ) && '' !== $value ) ) {
                $has_field = true;
                break;
            }
        }

        if ( ! $has_field ) {
            return new WP_Error(
                'missing_required_fields',
                __( 'At least one field must be provided to update the user.', 'mainwp' ),
            );
        }

        return true;
    }

    /**
     * Get users fields allowed fields.
     *
     * @return array
     */
    public function get_users_fields_allowed_fields() {
        $roles = array( 'administrator', 'subscriber', 'editor', 'author', 'contributor' );
        return array(
            'clients'  => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Search by clients.', 'mainwp' ),
                'sanitize_callback' => array( $this, 'sanitize_text_field_to_array' ),
                'validate_callback' => array( $this, 'validate_clients' ),
            ),
            'groups'   => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Search by tags.', 'mainwp' ),
                'sanitize_callback' => array( $this, 'sanitize_groups_text_field' ),
                'validate_callback' => array( $this, 'validate_groups' ),
            ),
            'websites' => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Websites child site field IDs.', 'mainwp' ),
                'sanitize_callback' => array( $this, 'sanitize_text_field_to_array' ),
                'validate_callback' => array( $this, 'validate_site_ids' ),
            ),
            'search'   => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Search by user name.', 'mainwp' ),
            ),
            'roles'    => array(
                'required'          => false,
                'type'              => 'array',
                'sanitize_callback' => $this->make_enum_sanitizer( $roles, 'array' ),
                'validate_callback' => $this->make_enum_validator( $roles, 'array' ),
                'description'       => __( 'Search by roles.', 'mainwp' ),
            ),
        );
    }

    /**
     * Create users fields allowed fields.
     *
     * @return array
     */
    public function create_users_fields_allowed_fields() {

        return array_merge(
            $this->create_edit_users_fields_allowed_fields(),
            array(
                'username'      => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => __( 'Username.', 'mainwp' ),
                ),
                'email'         => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                    'description'       => __( 'Email.', 'mainwp' ),
                ),
                'send_password' => array(
                    'required'          => false,
                    'type'              => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                    'description'       => __( 'Send password.', 'mainwp' ),
                ),
                'clients'       => array(
                    'required'          => false,
                    'type'              => 'string',
                    'description'       => __( 'Search by clients.', 'mainwp' ),
                    'sanitize_callback' => array( $this, 'sanitize_text_field_to_array' ),
                    'validate_callback' => array( $this, 'validate_clients' ),
                ),
                'groups'        => array(
                    'required'          => false,
                    'type'              => 'string',
                    'description'       => __( 'Search by tags.', 'mainwp' ),
                    'sanitize_callback' => array( $this, 'sanitize_groups_text_field' ),
                    'validate_callback' => array( $this, 'validate_groups' ),
                ),
                'websites'      => array(
                    'required'          => false,
                    'type'              => 'string',
                    'description'       => __( 'Websites child site field IDs.', 'mainwp' ),
                    'sanitize_callback' => array( $this, 'sanitize_text_field_to_array' ),
                    'validate_callback' => array( $this, 'validate_site_ids' ),
                ),
            )
        );
    }

    /**
     * Edit users fields allowed fields.
     *
     * @return array
     */
    public function edit_users_fields_allowed_fields() {
        return array_merge(
            $this->allowed_user_id_field(),
            $this->allowed_id_domain_field(),
            $this->create_edit_users_fields_allowed_fields(),
            array(
                'nickname'     => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => __( 'Nickname.', 'mainwp' ),
                ),
                'display_name' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => __( 'Display name.', 'mainwp' ),
                ),
                'email'        => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                    'description'       => __( 'Email.', 'mainwp' ),
                ),
                'description'  => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => __( 'Description.', 'mainwp' ),
                ),
            )
        );
    }

    /**
     * Create and edit users fields allowed fields.
     *
     * @return array
     */
    private function create_edit_users_fields_allowed_fields() {
        $roles = array( 'administrator', 'subscriber', 'editor', 'author', 'contributor' );

        return array(
            'password'   => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => function ( $value ) {
                    return is_string( $value ) ? $value : '';
                },
                'description'       => __( 'Password.', 'mainwp' ),
            ),
            'role'       => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => $this->make_enum_sanitizer( $roles, 'string' ),
                'validate_callback' => $this->make_enum_validator( $roles, 'string' ),
                'description'       => __( 'Role.', 'mainwp' ),
            ),
            'first_name' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'First name.', 'mainwp' ),
            ),
            'last_name'  => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Last name.', 'mainwp' ),
            ),
            'user_url'   => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_url',
                'description'       => __( 'User website.', 'mainwp' ),
            ),
        );
    }

    /**
     * Update admin password fields allowed fields.
     *
     * @return array
     */
    public function update_admin_password_fields_allowed_fields() {
        return array(
            'password' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => function ( $value ) {
                    return is_string( $value ) ? $value : '';
                },
                'description'       => __( 'Password.', 'mainwp' ),
            ),
            'clients'  => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Search by clients.', 'mainwp' ),
                'sanitize_callback' => array( $this, 'sanitize_text_field_to_array' ),
                'validate_callback' => array( $this, 'validate_clients' ),
            ),
            'groups'   => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Search by tags.', 'mainwp' ),
                'sanitize_callback' => array( $this, 'sanitize_groups_text_field' ),
                'validate_callback' => array( $this, 'validate_groups' ),
            ),
            'websites' => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Websites child site field IDs.', 'mainwp' ),
                'sanitize_callback' => array( $this, 'sanitize_text_field_to_array' ),
                'validate_callback' => array( $this, 'validate_site_ids' ),
            ),
        );
    }

    /**
     * Import users fields allowed fields.
     *
     * @return array
     */
    public function import_users_fields_allowed_fields() {
        return array(
            'csv_file'   => array(
                'required'    => false,
                'type'        => 'file',
                'description' => __( 'CSV file containing user data.', 'mainwp' ),
            ),
            'has_header' => array(
                'required'          => false,
                'type'              => 'boolean',
                'default'           => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
                'description'       => __( 'Whether the CSV file has a header row.', 'mainwp' ),
            ),
        );
    }

    /**
     * Get allowed fields for users.
     *
     * @return array
     */
    private function allowed_user_id_field() {
        return array(
            'user_id' => array(
                'required'          => true,
                'description'       => __( 'User ID.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
        );
    }

    /**
     * Get allowed fields for posts.
     *
     * @return array
     */
    private function allowed_id_domain_field() {
        return array(
            'id_domain' => array(
                'required'          => true,
                'description'       => __( 'Site ID (number) or domain (string).', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    /**
     * Get request body.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return array|WP_Error
     */
    private function get_request_body( $request ) {  // phpcs:ignore -- NOSONAR - complex.
        // Get request body filed.
        $body = $request->get_body_params();
        if ( ! empty( $body ) && is_array( $body ) ) {
            return $body;
        }

        // Get request body from body.
        $body = $request->get_json_params();
        if ( ! empty( $body ) && is_array( $body ) ) {
            return $body;
        }

        // Get request body from raw.
        $body = $request->get_body();
        if ( ! empty( $body ) && is_string( $body ) ) {
            $body = json_decode( $body, true );
            if ( ! is_array( $body ) ) {
                return new WP_Error(
                    'invalid_json',
                    __( 'Request body contains invalid JSON.', 'mainwp' ),
                    array( 'status' => 400 )
                );
            }
            return $body;
        }
        // Return error.
        return new WP_Error(
            'empty_body',
            __( 'Request body is empty.', 'mainwp' ),
        );
    }

    /**
     * Validate content type.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    private function validate_content_type( $request ) {
        $content_type = (string) $request->get_header( 'content-type' );
        if ( false === stripos( $content_type, 'application/json' ) ) {
            return new WP_Error(
                'invalid_content_type',
                __( 'Invalid content type. Expected application/json.', 'mainwp' ),
            );
        }
        return true;
    }

    /**
     * Get monitor by.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by()
     *
     * @return WP_Error|mixed Item.
     */
    private function get_request_website_by_id_domain_item( $request ) { // phpcs:ignore -- NOSONAR
        // Get id or domain raw value.
        $raw = (string) $request->get_param( 'id_domain' );
        $raw = trim( $raw );

        if ( empty( $raw ) ) {
            return new WP_Error( 'id_domain_not_found', __( 'Site id or domain not found.', 'mainwp' ) );
        }

        // Get monitor by monitor id.
        if ( ctype_digit( $raw ) ) {
            $website_id = (int) $raw;
            return $this->get_site_by( 'id', $website_id );
        }

        // Get monitor by domain.
        $domain  = strtolower( rtrim( rawurldecode( $raw ), '/' ) );
        $website = $this->get_site_by( 'domain', $domain );
        if ( empty( $website ) ) {
            return new WP_Error( 'website_not_found', __( 'Website not found.', 'mainwp' ) );
        }

        return $website;
    }

    /**
     * Get user by id.
     *
     * @param object          $website Website.
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return array|WP_Error
     */
    private function get_request_user_by_id( $website, $request ) { // phpcs:ignore -- NOSONAR
        $user_id = (int) $request->get_param( 'user_id' );
        if ( empty( $user_id ) ) {
            return new WP_Error( 'user_id_not_found', __( 'User id not found.', 'mainwp' ) );
        }

        try {

            /**
            * Action: mainwp_before_user_action
            *
            * Fires before user edit/delete/update_user/update_password actions.
            *
            * @since 4.1
            */
            do_action( 'mainwp_before_user_action', 'edit', $user_id, '', '', 1, $website );

            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'user_action',
                array(
                    'action'    => 'edit',
                    'id'        => $user_id,
                    'extra'     => '',
                    'user_pass' => '',
                    'optimize'  => 1,
                )
            );
            if ( is_array( $information ) && isset( $information['status'] ) && ( 'SUCCESS' === $information['status'] ) ) {
                $data = isset( $information['other_data']['users_data'] ) ? $information['other_data']['users_data'] : array();  // user actions data.

                /**
                 * Fires immediately after user action.
                 *
                 * @since 4.5.1.1
                 */
                do_action( 'mainwp_user_action', $website, 'update_user', $data, '', 1 );

                return $information;
            }

            return new WP_Error( 'user_not_found', __( 'User not found.', 'mainwp' ) );
        } catch ( MainWP_Exception $e ) {
            return new WP_Error( 'get_user_error', MainWP_Error_Helper::get_error_message( $e ) );
        }
    }

    /**
     * Get the API keys schema, conforming to JSON Schema.
     *
     * @since  5.2
     * @return array
     */
    public function get_item_schema() {  // phpcs:ignore -- NOSONAR - long schema.
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'user',
            'type'       => 'object',
            'properties' => array(
                'id'         => array(
                    'type'        => 'integer',
                    'description' => __( 'User ID.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'website_id' => array(
                    'type'        => 'integer',
                    'description' => __( 'Website ID.', 'mainwp' ),
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'username'   => array(
                    'type'        => 'string',
                    'description' => __( 'Username.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'name'       => array(
                    'type'        => 'string',
                    'description' => __( 'Name.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'email'      => array(
                    'type'        => 'string',
                    'description' => __( 'Email.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'role'       => array(
                    'type'        => 'string',
                    'description' => __( 'Role.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'posts'      => array(
                    'type'        => 'integer',
                    'description' => __( 'Posts.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
            ),
        );
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity
}
