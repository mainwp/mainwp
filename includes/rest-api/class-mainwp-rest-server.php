<?php
/**
 * Initialize this version of the REST API.
 *
 * @package MainWP\Dashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class responsible for loading the REST API and all REST API namespaces.
 */
class MainWP_Rest_Server {


    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;


    /**
     * REST API namespaces and endpoints.
     *
     * @var array
     */
    protected $controllers = array();


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
     * Hook into WordPress ready to init the REST API as needed.
     */
    public function init() {
        if ( apply_filters( 'mainwp_rest_api_v2_enabled', false ) ) {
            add_action( 'rest_api_init', array( $this, 'register_rest_routes' ), 10 );
        }
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes() {
        foreach ( $this->get_rest_namespaces() as $namespace => $controllers ) {
            foreach ( $controllers as $controller_name => $controller_class ) {
                if ( ! isset( $this->controllers[ $namespace ][ $controller_name ] ) ) {
                    if ( class_exists( $controller_class ) ) {
                        $this->controllers[ $namespace ][ $controller_name ] = new $controller_class();
                        $this->controllers[ $namespace ][ $controller_name ]->register_routes();
                    } else {
                        $this->controllers[ $namespace ][ $controller_name ] = false;
                    }
                }
            }
        }
    }

    /**
     * Get registered routes controller.
     *
     * @param string $nspace namespace.
     * @param string $name name.
     *
     * @return mixed result.
     */
    public function get_rest_controller( $nspace, $name ) {
        if ( is_array( $this->controllers ) && isset( $this->controllers[ $nspace ][ $name ] ) && isset( $this->controllers[ $nspace ][ $name ] ) ) {
            return $this->controllers[ $nspace ][ $name ];
        }
        return false;
    }

    /**
     * Get API namespaces - new namespaces should be registered here.
     *
     * @return array List of Namespaces and Main controller classes.
     */
    protected function get_rest_namespaces() {
        return apply_filters(
            'mainwp_rest_api_get_rest_namespaces',
            array(
                'mainwp/v2' => $this->get_v2_controllers(),
            )
        );
    }

    /**
     * List of controllers in the wc/v2 namespace.
     *
     * @return array
     */
    protected function get_v2_controllers() {
        return array(
            'sites'   => 'MainWP_Rest_Sites_Controller',
            'clients' => 'MainWP_Rest_Clients_Controller',
            'tags'    => 'MainWP_Rest_Tags_Controller',
            'updates' => 'MainWP_Rest_Updates_Controller',
            'batch'   => 'MainWP_Rest_Global_Batch_Controller',
        );
    }

    /**
     * Return the path to the package.
     *
     * @return string
     */
    public static function get_path() {
        return dirname( __DIR__ );
    }
}
