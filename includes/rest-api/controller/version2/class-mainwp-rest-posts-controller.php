<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Error_Helper;
use MainWP\Dashboard\MainWP_Actions_Handler;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Class MainWP_Rest_Posts_Controller
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Posts_Controller extends MainWP_REST_Controller { //phpcs:ignore -- NOSONAR - multi methods.

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
    protected $rest_base = 'posts';

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
        add_filter( 'mainwp_rest_posts_fields_object_query', array( $this, 'posts_fields_custom_query_args' ), 10, 2 );
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
                    'callback'            => array( $this, 'list_posts' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_posts_fields_allowed_fields(),
                    'validate_callback'   => array( $this, 'validate_filter_params' ),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/(?P<id_post>[\d]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_post' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_post_fields_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/(?P<id_post>[\d]+)/update-status',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'update_status_post' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->update_status_post_fields_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/(?P<id_post>[\d]+)/edit',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'edit_post' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->edit_post_fields_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/create',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_post' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->create_post_fields_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/(?P<id_post>[\d]+)/delete',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_post' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_post_fields_allowed_fields(),
                ),
            )
        );
    }

    /**
     * Add custom query args for posts.
     *
     * @param array           $args    Query args.
     * @param WP_REST_Request $request Request object.
     *
     * @return array
     */
    public function posts_fields_custom_query_args( $args, $request ) { // phpcs:ignore -- NOSONAR - complex.
        if ( ! empty( $request['clients'] ) ) {
            $args['clients'] = $request['clients'];
        }
        if ( ! empty( $request['groups'] ) ) {
            $args['groups'] = $request['groups'];
        }
        if ( ! empty( $request['websites'] ) ) {
            $args['websites'] = $request['websites'];
        }
        if ( ! empty( $request['dtsstart'] ) ) {
            $args['dtsstart'] = $request['dtsstart'];
        }
        if ( ! empty( $request['dtsstop'] ) ) {
            $args['dtsstop'] = $request['dtsstop'];
        }
        if ( ! empty( $request['search_on'] ) ) {
            $args['search_on'] = $request['search_on'];
        }
        if ( ! empty( $request['post_type'] ) ) {
            $args['post_type'] = isset( $request['post_type'] ) && 0 < strlen( sanitize_text_field( wp_unslash( $request['post_type'] ) ) ) ? sanitize_text_field( wp_unslash( $request['post_type'] ) ) : 'post';
        }
        if ( ! empty( $request['maximum'] ) ) {
            $args['maximum'] = $request['maximum'];
        }
        if ( ! empty( $request['post_title'] ) ) {
            $args['post_title'] = $request['post_title'];
        }
        if ( ! empty( $request['post_content'] ) ) {
            $args['post_content'] = $request['post_content'];
        }
        if ( ! empty( $request['post_status'] ) ) {
            $args['post_status'] = $request['post_status'];
        }
        if ( ! empty( $request['post_date'] ) ) {
            $args['post_date'] = $request['post_date'];
        }
        if ( ! empty( $request['post_date_gmt'] ) ) {
            $args['post_date_gmt'] = $request['post_date_gmt'];
        }
        if ( ! empty( $request['post_excerpt'] ) ) {
            $args['post_excerpt'] = $request['post_excerpt'];
        }
        if ( ! empty( $request['post_name'] ) ) {
            $args['post_name'] = $request['post_name'];
        }
        if ( ! empty( $request['comment_status'] ) ) {
            $args['comment_status'] = $request['comment_status'];
        }
        if ( ! empty( $request['ping_status'] ) ) {
            $args['ping_status'] = $request['ping_status'];
        }
        if ( ! empty( $request['post_password'] ) ) {
            $args['post_password'] = $request['post_password'];
        }
        if ( ! empty( $request['is_sticky'] ) ) {
            $args['is_sticky'] = $request['is_sticky'];
        }
        if ( ! empty( $request['post_category'] ) ) {
            $args['post_category'] = $request['post_category'];
        }
        if ( ! empty( $request['post_custom'] ) ) {
            $args['post_custom'] = $request['post_custom'];
        }
        if ( ! empty( $request['post_featured_image'] ) ) {
            $args['post_featured_image'] = $request['post_featured_image'];
        }
        if ( ! empty( $request['post_gallery_images'] ) ) {
            $args['post_gallery_images'] = $request['post_gallery_images'];
        }
        if ( ! empty( $request['featured_image_data'] ) ) {
            $args['featured_image_data'] = $request['featured_image_data'];
        }
        if ( ! empty( $request['post_tags'] ) ) {
            $args['post_tags'] = $request['post_tags'];
        }

        return $args;
    }


    /**
     * List all keys.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function list_posts( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $post_data = array();
        $utility   = MainWP_Utility::instance();
        // Get params.
        $args      = $this->prepare_objects_query( $request, 'posts_fields' );
        $post_type = ! empty( $args['post_type'] ) ? $args['post_type'] : 'post';
        $clients   = ! empty( $args['clients'] ) ? $args['clients'] : '';
        $groups    = ! empty( $args['groups'] ) ? $args['groups'] : '';
        $sites     = ! empty( $args['websites'] ) ? $args['websites'] : '';

        $post_data = array(
            'keyword'    => ! empty( $args['s'] ) ? $args['s'] : '',
            'search_on'  => ! empty( $args['search_on'] ) ? $args['search_on'] : '',
            'dtsstart'   => ! empty( $args['dtsstart'] ) ? $args['dtsstart'] : '',
            'dtsstop'    => ! empty( $args['dtsstop'] ) ? $args['dtsstop'] : '',
            'status'     => ! empty( $args['status'] ) ? $args['status'] : '',
            'maxRecords' => ! empty( $args['maximum'] ) ? $args['maximum'] : 50,
        );

        if ( is_plugin_active( 'mainwp-custom-post-types/mainwp-custom-post-types.php' ) && 'any' === $post_type ) {
            $post_data['exclude_page_type'] = 1;
        }
        $post_data['post_type'] = $post_type;

        if ( $utility->enabled_wp_seo() ) {
            $post_data['WPSEOEnabled'] = 1;
        }

        $filter_db_websites = $this->get_db_websites_by_filter( $sites, $groups, $clients );

        if ( empty( $filter_db_websites ) || ! is_array( $filter_db_websites ) ) {
            return new WP_Error(
                'website_not_found',
                $this->get_common_message( 'website_not_found' )
            );
        }

        $db_websites = $filter_db_websites['db_websites'] ?? array();
        $website_url = $filter_db_websites['website_url'] ?? array();

        if ( empty( $db_websites ) ) {
            return new WP_Error(
                'website_not_found',
                $this->get_common_message( 'website_not_found' )
            );
        }

        $posts = apply_filters( 'mainwp_getallposts', $db_websites, $post_data );
        if ( empty( $posts ) || empty( $posts->results ) ) {
            return new WP_Error(
                'no_posts_found',
                __( 'No posts found.', 'mainwp' ),
            );
        }

        // Map posts data with website url.
        $results = array();
        foreach ( $posts->results as $k => $v ) {
            foreach ( $v as $p => $post ) {
                $post['website_id']                  = $k; // add child website id.
                $post['dts']                         = ! empty( $post['dts'] ) ? gmdate( 'Y-m-d H:i:s', $post['dts'] ) : '';
                $results[ $website_url[ $k ] ][ $p ] = $this->filter_response_data_by_allowed_fields( $post, 'view' );
            }
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Get posts successfully.', 'mainwp' ),
                'data'    => $results,
            )
        );
    }

    /**
     * Get post.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_post( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $website = $this->get_request_item( $request );
        if ( empty( $website ) ) {
            return new WP_Error(
                'website_not_found',
                $this->get_common_message( 'website_not_found' )
            );
        }

        // Check post id exist.
        $post = $this->get_request_post_id( $website, $request );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        $post_id = $post['post_id'];
        if ( empty( $post['data']['my_post'] ) ) {
            return new WP_Error( 'post_not_exist', __( 'Post not exist.', 'mainwp' ) );
        }

        $data      = $post['data']['my_post'];
        $post_data = $this->decode_post_page_data( $data );

        $new_post  = $post_data['new_post'];
        $post_tags = isset( $new_post['post_tags'] ) ? rawurldecode( $new_post['post_tags'] ) : '';

        $result = array_merge(
            array(
                'post_id'             => $post_id,
                'post_category'       => $post_data['post_category'],
                'post_tags'           => $post_tags,
                'post_featured_image' => $post_data['post_featured_image'],
                'post_gallery_images' => $post_data['post_gallery_images'],
                'post_custom'         => $post_data['post_custom'],
                'upload_dir'          => $post_data['child_upload_dir'],
            ),
            $new_post,
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Get post successfully.', 'mainwp' ),
                'data'    => $this->filter_response_data_by_allowed_fields( $result, 'detail' ),
            )
        );
    }

    /**
     * Edit post status.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_status_post( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get website exist.
        $website = $this->get_request_item( $request );
        if ( empty( $website ) ) {
            return new WP_Error(
                'website_not_found',
                $this->get_common_message( 'website_not_found' )
            );
        }

        // Check post id exist.
        $post = $this->get_request_post_id( $website, $request );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        // Get status action.
        $post_id = $post['post_id'];
        $args    = $this->prepare_objects_query( $request );
        $status  = isset( $args['status'] ) ? $args['status'] : '';
        if ( empty( $status ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Status not found.', 'mainwp' ),
                )
            );
        }
        $status = 'approve' === $status ? 'publish' : $status;

        // Update post status.
        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'post_action',
                array(
                    'action' => $status,
                    'id'     => $post_id,
                )
            );

        } catch ( MainWP_Exception $e ) {
            return new WP_Error( 'update_post_status_error', MainWP_Error_Helper::get_error_message( $e ) );
        }

        if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) || empty( $information['my_post'] ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Update post status failed.', 'mainwp' ),
                )
            );
        }

        MainWP_Actions_Handler::instance()->do_action_mainwp_post_action( $website, $status, $information, $post_id, 'post' );
        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Update post status successfully.', 'mainwp' ),
            )
        );
    }

    /**
     * Edit post.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function edit_post( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get website exist.
        $website = $this->get_request_item( $request );
        if ( empty( $website ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => $this->get_common_message( 'website_not_found' ),
                )
            );
        }

        // Check post id exist.
        $post = $this->get_request_post_id( $website, $request );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        // Get post data from request.
        $args = $this->prepare_objects_query( $request, 'posts_fields' );

        $post_id       = $post['post_id'];
        $old_post      = $post['data']['my_post'];
        $old_post_data = $this->decode_post_page_data( $old_post );
        $old_new_post  = $old_post_data['new_post'];

        // Get post_category from request or use old value.
        $new_post_category = isset( $args['post_category'] ) && ! empty( $args['post_category'] ) ? $args['post_category'] : '';
        $post_category     = ! empty( $new_post_category ) ? $new_post_category : $old_post_data['post_category'];

        // Get post_custom from request or use old value.
        $new_post_custom = isset( $args['post_custom'] ) && ! empty( $args['post_custom'] ) ? $args['post_custom'] : '';
        $post_custom     = ! empty( $new_post_custom ) ? $new_post_custom : $old_post_data['post_custom'];

        // Get post_featured_image from request or use old value.
        $new_post_featured_image = isset( $args['post_featured_image'] ) && ! empty( $args['post_featured_image'] ) ? $args['post_featured_image'] : '';
        $post_featured_image     = ! empty( $new_post_featured_image ) ? $new_post_featured_image : $old_post_data['post_featured_image'];

        // Get featured_image_data from request or use old value.
        $featured_image_data = isset( $args['featured_image_data'] ) && ! empty( $args['featured_image_data'] ) ? $args['featured_image_data'] : null;
        $featured_image_data = ! empty( $featured_image_data ) ? $featured_image_data : $old_post_data['featured_image_data'];

        // Get post_gallery_images from request or use old value.
        $new_post_gallery_images = isset( $args['post_gallery_images'] ) && ! empty( $args['post_gallery_images'] ) ? $args['post_gallery_images'] : '';
        $post_gallery_images     = ! empty( $new_post_gallery_images ) ? $new_post_gallery_images : $old_post_data['post_gallery_images'];

        // Get post_tags from request or use old value.
        $new_post_tags = isset( $args['post_tags'] ) && ! empty( $args['post_tags'] ) ? $args['post_tags'] : '';
        $old_post_tags = isset( $old_new_post['post_tags'] ) ? rawurldecode( $old_new_post['post_tags'] ) : '';
        $post_tags     = ! empty( $new_post_tags ) ? $new_post_tags : $old_post_tags;

        // Build the post data to send to child site.
        $new_post = array(
            'ID'             => $post_id,
            'post_type'      => 'post',
            'post_title'     => isset( $args['post_title'] ) ? $args['post_title'] : '',
            'post_content'   => isset( $args['post_content'] ) ? $args['post_content'] : '',
            'post_status'    => isset( $args['post_status'] ) ? $args['post_status'] : 'draft',
            'post_excerpt'   => isset( $args['post_excerpt'] ) ? $args['post_excerpt'] : '',
            'post_name'      => isset( $args['post_name'] ) ? $args['post_name'] : '',
            'post_password'  => isset( $args['post_password'] ) ? $args['post_password'] : '',
            'comment_status' => isset( $args['comment_status'] ) ? $args['comment_status'] : 'open',
            'ping_status'    => isset( $args['ping_status'] ) ? $args['ping_status'] : 'closed',
            'post_date'      => isset( $args['post_date'] ) ? $args['post_date'] : '',
            'post_date_gmt'  => isset( $args['post_date_gmt'] ) ? $args['post_date_gmt'] : '',
            'is_sticky'      => isset( $args['is_sticky'] ) ? $args['is_sticky'] : '',
            'post_tags'      => $post_tags,
        );

        $post_data = $this->prepare_post_page_data( $new_post, $post_custom, $post_featured_image, $featured_image_data, $post_gallery_images, $post_category );

        // Send edit request to child site.
        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'newpost',
                $post_data
            );

        } catch ( MainWP_Exception $e ) {
            return new WP_Error( 'edit_post_error', MainWP_Error_Helper::get_error_message( $e ) );
        }

        if ( empty( $information['added'] ) ) {
            return new WP_Error(
                'edit_post_failed',
                __( 'Edit post failed.', 'mainwp' )
            );
        }

        MainWP_Actions_Handler::instance()->do_action_mainwp_post_action( $website, 'edit', $information, $post_id, 'post' );
        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Edit post successfully.', 'mainwp' ),
            )
        );
    }

    /**
     * Create post.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function create_post( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get website exist.
        $website = $this->get_request_item( $request );
        if ( empty( $website ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => $this->get_common_message( 'website_not_found' ),
                )
            );
        }

        $args = $this->prepare_objects_query( $request, 'posts_fields' );

        // Build the post data core fields.
        $new_post = array(
            'post_title'     => isset( $args['post_title'] ) ? $args['post_title'] : '',
            'post_content'   => isset( $args['post_content'] ) ? $args['post_content'] : '',
            'post_status'    => isset( $args['post_status'] ) ? $args['post_status'] : 'draft',
            'post_excerpt'   => isset( $args['post_excerpt'] ) ? $args['post_excerpt'] : '',
            'post_name'      => isset( $args['post_name'] ) ? $args['post_name'] : '',
            'post_password'  => isset( $args['post_password'] ) ? $args['post_password'] : '',
            'comment_status' => isset( $args['comment_status'] ) ? $args['comment_status'] : 'open',
            'ping_status'    => isset( $args['ping_status'] ) ? $args['ping_status'] : 'closed',
            'post_date'      => isset( $args['post_date'] ) ? $args['post_date'] : '',
            'post_date_gmt'  => isset( $args['post_date_gmt'] ) ? $args['post_date_gmt'] : '',
            'is_sticky'      => isset( $args['is_sticky'] ) ? $args['is_sticky'] : '',
        );

        // Optional fields: tags and custom meta.
        $req_post_tags = $request->get_param( 'post_tags' );
        if ( ! empty( $req_post_tags ) ) {
            $new_post['post_tags'] = $req_post_tags;
        }

        // Collect advanced data similar to bulk posting flow.
        $post_category       = isset( $args['post_category'] ) && ! empty( $args['post_category'] ) ? $args['post_category'] : '';
        $post_custom         = isset( $args['post_custom'] ) && ! empty( $args['post_custom'] ) && is_array( $args['post_custom'] ) ? $args['post_custom'] : array();
        $post_featured_image = isset( $args['post_featured_image'] ) && ! empty( $args['post_featured_image'] ) ? $args['post_featured_image'] : null;
        $post_gallery_images = isset( $args['post_gallery_images'] ) && ! empty( $args['post_gallery_images'] ) && is_array( $args['post_gallery_images'] ) ? $args['post_gallery_images'] : array();
        $featured_image_data = isset( $args['featured_image_data'] ) && ! empty( $args['featured_image_data'] ) ? $args['featured_image_data'] : null;

        $post_data = $this->prepare_post_page_data( $new_post, $post_custom, $post_featured_image, $featured_image_data, $post_gallery_images, $post_category );

        // Send create request to child site).
        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'newpost',
                $post_data
            );

        } catch ( MainWP_Exception $e ) {
            return new WP_Error( 'create_post_error', MainWP_Error_Helper::get_error_message( $e ) );
        }
        if ( empty( $information['added'] ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Create post failed.', 'mainwp' ),
                )
            );
        }

        MainWP_Actions_Handler::instance()->do_action_mainwp_post_action( $website, 'create', $information, false, 'post' );
        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Create post successfully.', 'mainwp' ),
                'data'    => array(
                    'post_id' => isset( $information['added_id'] ) ? $information['added_id'] : 0,
                    'link'    => isset( $information['link'] ) ? $information['link'] : '',
                ),
            )
        );
    }

    /**
     * Delete post.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function delete_post( $request ) {  // phpcs:ignore -- NOSONAR - complex.
        // Get website exist.
        $website = $this->get_request_item( $request );
        if ( empty( $website ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => $this->get_common_message( 'website_not_found' ),
                )
            );
        }

        // Check post id exist.
        $post = $this->get_request_post_id( $website, $request );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        $post_id = $post['post_id'];

        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'post_action',
                array(
                    'action' => 'delete',
                    'id'     => $post_id,
                )
            );

        } catch ( MainWP_Exception $e ) {
            return new WP_Error( 'delete_post_error', MainWP_Error_Helper::get_error_message( $e ) );
        }

        if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Delete post failed.', 'mainwp' ),
                )
            );
        }

        MainWP_Actions_Handler::instance()->do_action_mainwp_post_action( $website, 'delete', $information, $post_id, 'post' );
        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Delete post successfully.', 'mainwp' ),
            )
        );
    }

    /**
     * Get site by.
     *
     * @param  string $by Get by.
     * @param  mixed  $value Site id or domain.
     * @param  array  $args args.
     *
     * @return array|mixed Response data, ready for insertion into collection data.
     */
    public function get_site_by( $by, $value, $args = array() ) {
        $site          = false;
        $select_groups = ! empty( $args['with_tags'] );

        if ( 'id' === $by ) {
            $site_id = intval( $value );
        } elseif ( 'domain' === $by ) {
            $site = $this->db->get_websites_by_url( $value );
            if ( empty( $site ) ) {
                return $this->get_rest_data_error( 'domain', 'site' );
            }
            $site    = current( $site );
            $site_id = $site->id;
        }

        $site = $this->db->get_website_by_id( $site_id, $select_groups );
        if ( empty( $site ) ) {
            return $this->get_rest_data_error( 'id', 'site' );
        }
        return $site;
    }

    /**
     * Get allowed fields for posts.
     *
     * @return array
     */
    public function get_posts_fields_allowed_fields() {
        $search_on = array( 'title', 'body', 'Title and Body' );
        $status    = array( 'publish', 'pending', 'private', 'future', 'draft', 'trash' );
        return array(
            'search'    => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Search by keyword.', 'mainwp' ),
            ),
            'search_on' => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Search by title or content.', 'mainwp' ),
                'sanitize_callback' => $this->make_enum_sanitizer( $search_on, 'string' ),
                'validate_callback' => $this->make_enum_validator( $search_on, 'string' ),
            ),
            'status'    => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Search by status.', 'mainwp' ),
                'sanitize_callback' => $this->make_enum_sanitizer( $status, 'string' ),
                'validate_callback' => $this->make_enum_validator( $status, 'string' ),
            ),
            'dtsstart'  => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Search by start date.', 'mainwp' ),
                'validate_callback' => array( $this, 'validate_date_format' ),
            ),
            'dtsstop'   => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Search by stop date.', 'mainwp' ),
                'validate_callback' => array( $this, 'validate_date_format' ),
            ),
            'clients'   => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Search by clients.', 'mainwp' ),
                'sanitize_callback' => array( $this, 'sanitize_text_field_to_array' ),
                'validate_callback' => array( $this, 'validate_clients' ),
            ),
            'groups'    => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Search by tags.', 'mainwp' ),
                'sanitize_callback' => array( $this, 'sanitize_groups_text_field' ),
                'validate_callback' => array( $this, 'validate_groups' ),
            ),
            'websites'  => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Websites child site field IDs.', 'mainwp' ),
                'sanitize_callback' => array( $this, 'sanitize_text_field_to_array' ),
                'validate_callback' => array( $this, 'validate_site_ids' ),
            ),
            'maximum'   => array(
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'minimum'           => 1,
                'maximum'           => 200,
                'default'           => 50,
                'description'       => __( 'Maximum number of posts to return.', 'mainwp' ),
            ),
            'post_type' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Search by post type.', 'mainwp' ),
            ),
        );
    }

    /**
     * Get allowed fields for post.
     *
     * @return array
     */
    public function get_post_fields_allowed_fields() {
        return array_merge(
            $this->allowed_id_domain_field(),
            $this->allowed_id_post_field()
        );
    }

    /**
     * Get allowed fields for posts.
     *
     * @return array
     */
    public function update_status_post_fields_allowed_fields() {
        $status = array( 'unpublish', 'publish', 'approve', 'trash', 'delete', 'restore' );
        return array_merge(
            $this->allowed_id_domain_field(),
            $this->allowed_id_post_field(),
            array(
                'status' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'description'       => __( 'New status.', 'mainwp' ),
                    'sanitize_callback' => $this->make_enum_sanitizer( $status, 'string' ),
                    'validate_callback' => $this->make_enum_validator( $status, 'string' ),
                ),
            )
        );
    }

    /**
     * Get allowed fields for editing a post.
     *
     * @return array
     */
    public function edit_post_fields_allowed_fields() {
        $status = array( 'publish', 'pending', 'private', 'future', 'draft', 'trash' );
        return array_merge(
            $this->allowed_id_domain_field(),
            $this->allowed_id_post_field(),
            $this->allow_post_fields(),
            array(
                'post_title'   => array(
                    'required'          => false,
                    'type'              => 'string',
                    'description'       => $this->get_common_message( 'post_title' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'post_content' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'description'       => $this->get_common_message( 'post_content' ),
                    'sanitize_callback' => 'wp_kses_post',
                ),
                'post_status'  => array(
                    'required'          => false,
                    'type'              => 'string',
                    'description'       => $this->get_common_message( 'post_status' ),
                    'sanitize_callback' => $this->make_enum_sanitizer( $status, 'string' ),
                    'validate_callback' => $this->make_enum_validator( $status, 'string' ),
                ),
                'post_name'    => array(
                    'required'          => false,
                    'type'              => 'string',
                    'description'       => $this->get_common_message( 'post_slug' ),
                    'sanitize_callback' => 'sanitize_title',
                ),
            )
        );
    }

    /**
     * Get allowed fields for posts.
     *
     * @return array
     */
    public function create_post_fields_allowed_fields() {
        $status = array( 'publish', 'pending', 'private', 'future', 'draft', 'trash' );
        return array_merge(
            $this->allowed_id_domain_field(),
            $this->allow_post_fields(),
            array(
                'post_title'          => array(
                    'required'          => true,
                    'type'              => 'string',
                    'description'       => $this->get_common_message( 'post_title' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'post_content'        => array(
                    'required'          => true,
                    'type'              => 'string',
                    'description'       => $this->get_common_message( 'post_content' ),
                    'sanitize_callback' => 'wp_kses_post',
                ),
                'post_status'         => array(
                    'required'          => true,
                    'type'              => 'string',
                    'description'       => $this->get_common_message( 'post_status' ),
                    'sanitize_callback' => $this->make_enum_sanitizer( $status, 'string' ),
                    'validate_callback' => $this->make_enum_validator( $status, 'string' ),
                ),
                'post_name'           => array(
                    'required'          => true,
                    'type'              => 'string',
                    'description'       => $this->get_common_message( 'post_slug' ),
                    'sanitize_callback' => 'sanitize_title',
                ),
                'post_featured_image' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'description'       => __( 'Featured image URL or data.', 'mainwp' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'post_gallery_images' => array(
                    'required'          => false,
                    'type'              => 'array',
                    'description'       => __( 'Gallery images array.', 'mainwp' ),
                    'sanitize_callback' => function ( $value ) {
                        return is_array( $value ) ? $value : array();
                    },
                ),
            )
        );
    }

    /**
     * Get allowed fields for posts.
     *
     * @return array
     */
    public function allowed_id_post_field() {
        return array(
            'id_post' => array(
                'required'          => true,
                'description'       => __( 'Post ID.', 'mainwp' ),
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
    public function allowed_id_domain_field() {
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
     * Validate filter params.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function validate_filter_params( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $count_sites = $this->validate_count_sites_params( $request );
        if ( is_wp_error( $count_sites ) ) {
            return $count_sites;
        }

        $date_range = $this->validate_date_range( $request );
        if ( is_wp_error( $date_range ) ) {
            return $date_range;
        }

        $search = $this->validate_search_params( $request );
        if ( is_wp_error( $search ) ) {
            return $search;
        }

        return true;
    }

    /**
     * Validate date format.
     *
     * @param string          $value Date format.
     * @param WP_REST_Request $request Request object.
     * @param string          $param Parameter name.
     *
     * @return bool|WP_Error
     */
    public function validate_date_format( $value, $request, $param  ) { // phpcs:ignore -- NOSONAR - callback signature required by WordPress.
        if ( empty( $value ) ) {
            return true;
        }

        $value = $this->sanitize_field( $value );

        // Expect ISO date literal in YYYY-MM-DD format, e.g. YYYY-MM-DD.
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return new WP_Error(
                'invalid_date_format',
                __( 'Invalid date format. Use YYYY-MM-DD', 'mainwp' ),
            );
        }

        // Validate actual calendar date.
        list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );
        if ( ! checkdate( $month, $day, $year ) ) {
            return new WP_Error(
                'invalid_date_value',
                __( 'Invalid date value. Use a real date.', 'mainwp' ),
            );
        }

        // Validation not greater than current date.
        $fields = array( 'dtsstart', 'dtsstop' );
        if ( ! in_array( $param, $fields, true ) && strtotime( $value ) > time() ) {
            return new WP_Error(
                'invalid_date_value',
                __( 'Invalid date value. Date must be in the past.', 'mainwp' ),
            );
        }

        return true;
    }

    /**
     * Validate datetime format.
     *
     * @param string          $value Datetime value.
     * @param WP_REST_Request $request Request object.
     * @param string          $param Parameter name.
     *
     * @return bool|WP_Error
     */
    public function validate_datetime_format( $value, $request, $param = '' ) { // phpcs:ignore -- NOSONAR - callback signature required by WordPress.
        if ( empty( $value ) ) {
            return true; // Allow empty values.
        }

        $value = $this->sanitize_field( $value );

        // Expect datetime format: YYYY-MM-DD HH:MM:SS.
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
            return new WP_Error(
                'invalid_datetime_format',
                __( 'Invalid datetime format. Use YYYY-MM-DD HH:MM:SS (e.g., 2024-08-23 18:02:40)', 'mainwp' ),
            );
        }

        // Validate actual calendar date and time.
        list( $date_part, $time_part )  = explode( ' ', $value );
        list( $year, $month, $day )     = array_map( 'intval', explode( '-', $date_part ) );
        list( $hour, $minute, $second ) = array_map( 'intval', explode( ':', $time_part ) );

        // Validate date.
        if ( ! checkdate( $month, $day, $year ) ) {
            return new WP_Error(
                'invalid_datetime_date',
                __( 'Invalid date value. Use a real date.', 'mainwp' ),
            );
        }

        // Validate time.
        if ( $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59 ) {
            return new WP_Error(
                'invalid_datetime_time',
                __( 'Invalid time value. Hour must be 0-23, minute and second must be 0-59.', 'mainwp' ),
            );
        }

        return true;
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
                'id'                  => array(
                    'type'        => 'integer',
                    'description' => __( 'Post ID.', 'mainwp' ),
                    'context'     => array( 'view', 'simple_view' ),
                    'readonly'    => true,
                ),
                'website_id'          => array(
                    'type'        => 'integer',
                    'description' => __( 'Website ID.', 'mainwp' ),
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'post_type'           => array(
                    'type'        => 'string',
                    'description' => __( 'Post type.', 'mainwp' ),
                    'context'     => array( 'view', 'simple_view' ),
                    'readonly'    => true,
                ),
                'status'              => array(
                    'type'        => 'string',
                    'description' => $this->get_common_message( 'post_status' ),
                    'context'     => array( 'view', 'simple_view' ),
                    'readonly'    => true,
                ),
                'title'               => array(
                    'type'        => 'string',
                    'description' => $this->get_common_message( 'post_title' ),
                    'context'     => array( 'view', 'simple_view' ),
                    'readonly'    => true,
                ),
                'comment_count'       => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of comments.', 'mainwp' ),
                    'context'     => array( 'view', 'simple_view' ),
                    'readonly'    => true,
                ),
                'author'              => array(
                    'type'        => 'string',
                    'description' => __( 'Post author.', 'mainwp' ),
                    'context'     => array( 'view', 'simple_view' ),
                    'readonly'    => true,
                ),
                'post_category'       => array(
                    'type'        => 'string',
                    'description' => __( 'Post categories.', 'mainwp' ),
                    'context'     => array( 'view', 'detail' ),
                    'readonly'    => true,
                ),
                'post_tags'           => array(
                    'type'        => 'string',
                    'description' => __( 'Post tags.', 'mainwp' ),
                    'context'     => array( 'view', 'detail' ),
                    'readonly'    => true,
                ),
                'dts'                 => array(
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'description' => __( 'Post date.', 'mainwp' ),
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'authorEmail'         => array(
                    'type'        => 'string',
                    'description' => __( 'Post author email.', 'mainwp' ),
                    'context'     => array( 'simple_view' ),
                    'readonly'    => true,
                ),
                'post_password'       => array(
                    'type'        => 'string',
                    'description' => __( 'Post password.', 'mainwp' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'ping_status'         => array(
                    'type'        => 'string',
                    'description' => __( 'Ping status.', 'mainwp' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'comment_status'      => array(
                    'type'        => 'string',
                    'description' => __( 'Comment status.', 'mainwp' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'post_excerpt'        => array(
                    'type'        => 'string',
                    'description' => __( 'Post excerpt.', 'mainwp' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'post_name'           => array(
                    'type'        => 'string',
                    'description' => $this->get_common_message( 'post_slug' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'post_date_gmt'       => array(
                    'type'        => 'string',
                    'description' => __( 'Post date GMT.', 'mainwp' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'post_date'           => array(
                    'type'        => 'string',
                    'description' => __( 'Post date.', 'mainwp' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'post_status'         => array(
                    'type'        => 'string',
                    'description' => $this->get_common_message( 'post_status' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'post_content'        => array(
                    'type'        => 'string',
                    'description' => $this->get_common_message( 'post_content' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'post_title'          => array(
                    'type'        => 'string',
                    'description' => $this->get_common_message( 'post_title' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'is_sticky'           => array(
                    'type'        => 'boolean',
                    'description' => __( 'Sticky status.', 'mainwp' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'upload_dir'          => array(
                    'type'        => 'string',
                    'description' => __( 'Upload directory.', 'mainwp' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'post_custom'         => array(
                    'type'        => 'object',
                    'description' => __( 'Custom meta key/values.', 'mainwp' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'post_gallery_images' => array(
                    'type'        => 'array',
                    'description' => __( 'Gallery images.', 'mainwp' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
                'post_featured_image' => array(
                    'type'        => 'string',
                    'description' => __( 'Featured image URL.', 'mainwp' ),
                    'context'     => array( 'detail' ),
                    'readonly'    => true,
                ),
            ),
        );
    }

    /**
     * Get allowed post fields.
     *
     * @return array
     */
    private function allow_post_fields() {
        $ping_status    = array( 'open', 'closed' );
        $comment_status = array( 'open', 'closed' );
        $sticky         = array( 1, 0 );
        return array(
            'post_excerpt'   => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Post excerpt.', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'post_password'  => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Post password.', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'comment_status' => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Comment status.', 'mainwp' ),
                'sanitize_callback' => $this->make_enum_sanitizer( $comment_status, 'string' ),
                'validate_callback' => $this->make_enum_validator( $comment_status, 'string' ),
            ),
            'ping_status'    => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Ping status.', 'mainwp' ),
                'sanitize_callback' => $this->make_enum_sanitizer( $ping_status, 'string' ),
                'validate_callback' => $this->make_enum_validator( $ping_status, 'string' ),
            ),
            'post_date'      => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Post date (YYYY-MM-DD HH:MM:SS).', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'validate_datetime_format' ),
            ),
            'post_date_gmt'  => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Post date GMT (YYYY-MM-DD HH:MM:SS).', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'validate_datetime_format' ),
            ),
            'is_sticky'      => array(
                'required'          => false,
                'type'              => 'integer',
                'description'       => __( 'Sticky status.', 'mainwp' ),
                'sanitize_callback' => $this->make_enum_sanitizer( $sticky, 'integer' ),
                'validate_callback' => $this->make_enum_validator( $sticky, 'integer' ),
            ),
            'post_tags'      => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Post tags (string).', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'post_category'  => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Post categories (raw string).', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'post_custom'    => array(
                'required'          => false,
                'type'              => 'object',
                'description'       => __( 'Custom meta key/values.', 'mainwp' ),
                'sanitize_callback' => function ( $value ) {
                    return is_array( $value ) ? $value : array();
                },
            ),
        );
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
    private function get_request_item( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get id or domain raw value.
        $raw = (string) $request->get_param( 'id_domain' );
        $raw = trim( $raw );

        if ( empty( $raw ) ) {
            return false;
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
            return false;
        }

        return $website;
    }


    /**
     * Get post id.
     *
     * @param object          $website Website.
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return array|WP_Error
     */
    protected function get_request_post_id( $website, $request ) {  // phpcs:ignore -- NOSONAR
        $post_id = (int) $request->get_param( 'id_post' );
        if ( empty( $post_id ) ) {
            return new WP_Error( 'post_id_not_found', __( 'Post id not found.', 'mainwp' ) );
        }

        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'post_action',
                array(
                    'action'    => 'get_edit',
                    'id'        => $post_id,
                    'post_type' => 'post',
                )
            );

        } catch ( MainWP_Exception $e ) {
            return new WP_Error( 'get_post_error', MainWP_Error_Helper::get_error_message( $e ) );
        }

        if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) || empty( $information['my_post'] ) ) {
            return new WP_Error(
                'post_not_exist',
                __( 'Post not exist.', 'mainwp' )
            );
        }

        return array(
            'data'    => $information,
            'post_id' => $post_id,
        );
    }

    /**
     * Get common message.
     *
     * @param string $name Field name.
     *
     * @return string
     */
    protected function get_common_message( $name ) {
        $fields_map = array(
            'post_title'        => __( 'Post title.', 'mainwp' ),
            'post_content'      => __( 'Post content.', 'mainwp' ),
            'post_status'       => __( 'Post status.', 'mainwp' ),
            'post_slug'         => __( 'Post slug.', 'mainwp' ),
            'website_not_found' => __( 'Website not found.', 'mainwp' ),
        );
        return isset( $fields_map[ $name ] ) ? $fields_map[ $name ] : '';
    }

    /**
     * Validate search params.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    protected function validate_search_params( $request ) {
        $search        = $request->get_param( 'search' );
        $search_on     = $request->get_param( 'search_on' );
        $has_search    = ! empty( $search );
        $has_search_on = ! empty( $search_on );
        if ( $has_search xor $has_search_on ) {
            return new WP_Error(
                'incomplete_search',
                __( 'Both search and search_on must be provided together.', 'mainwp' ),
            );
        }
        return true;
    }

    /**
     * Validate count sites params.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    protected function validate_count_sites_params( $request ) {
        // Customer, group, site authentication only one of the 3 is processed once.
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
     * Validate date range.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    protected function validate_date_range( $request ) {
        // Validate date range.
        $date_start = $request->get_param( 'dtsstart' );
        $date_stop  = $request->get_param( 'dtsstop' );
        $has_start  = ! empty( $date_start );
        $has_stop   = ! empty( $date_stop );

        // XOR logic: if only one of the two is filled in.
        if ( $has_start xor $has_stop ) {
            return new WP_Error(
                'incomplete_date_range',
                __( 'Both start date and stop date must be provided together.', 'mainwp' ),
            );
        }

        if ( $has_start && $has_stop && strtotime( $date_start ) >= strtotime( $date_stop ) ) {
            return new WP_Error(
                'invalid_date_range',
                __( 'Start date must be before stop date.', 'mainwp' ),
            );
        }
        return true;
    }
}
