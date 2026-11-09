<?php
/**
 * MainWP REST Jobs Controller
 *
 * Batch job status for REST v2 clients: a thin, owner-scoped wrapper around
 * the mainwp/get-batch-job-status-v1 ability.
 *
 * @package MainWP\Dashboard
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Rest_Jobs_Controller
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Jobs_Controller extends MainWP_REST_Controller {

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
    protected $rest_base = 'jobs';

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
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_\-]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => array(
                        'id' => array(
                            'description' => __( 'Batch job ID, as emitted in a queued response\'s job_id / status_url.', 'mainwp' ),
                            'type'        => 'string',
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Get one batch job's status.
     *
     * Delegates to the mainwp/get-batch-job-status-v1 ability, whose ownership
     * gate compares the job's recorded owner against the authenticated user.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Job status or error.
     */
    public function get_item( $request ) {
        $ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'mainwp/get-batch-job-status-v1' ) : null;

        if ( null === $ability ) {
            return new WP_Error(
                'mainwp_abilities_unavailable',
                __( 'The Abilities API is not available on this Dashboard.', 'mainwp' ),
                array( 'status' => 501 )
            );
        }

        $result = $ability->execute( array( 'job_id' => (string) $request['id'] ) );

        if ( is_wp_error( $result ) ) {
            $code       = $result->get_error_code();
            $error_data = $result->get_error_data( $code );
            $status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;
            // add_data() replaces the code's data rather than merging, so carry
            // the ability's own keys across instead of dropping them.
            $data           = is_array( $error_data ) ? $error_data : array();
            $data['status'] = $status;
            $result->add_data( $data, $code );
            return $result;
        }

        return rest_ensure_response( $result );
    }
}
