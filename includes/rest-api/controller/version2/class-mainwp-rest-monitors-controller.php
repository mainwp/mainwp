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
class MainWP_Rest_Monitors_Controller extends MainWP_REST_Controller { //phpcs:ignore -- NOSONAR - multi methods.

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
    protected $rest_base = 'monitors';

    /**
     * Constructor.
     */
    public function __construct() {
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
        // Retrieves all clients.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/count',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'count_monitors' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );
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
            'title'      => 'monitors',
            'type'       => 'object',
            'properties' => array(
                'total' => array(
                    'type'        => 'integer',
                    'description' => __( 'Total monitors.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
            ),
        );
    }

    /**
     * Count all Clients.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function count_monitors( $request ) {
        $args          = $this->prepare_objects_query( $request );
        $prepared_args = array(
            'count_only' => true,
        );
        // get data.

        return rest_ensure_response(
            array(
                'success' => 1,
                'total'   => 10,
            )
        );
    }
}
