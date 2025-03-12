<?php
/**
 * MainWP Module Cost Tracker Admin class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Cost_Tracker_Manager
 */
class Cost_Tracker_Manager {

    /**
     * Static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @static
     *
     * @return Cost_Tracker_Manager
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor
     *
     * Runs each time the class is called.
     */
    public function __construct() {
        spl_autoload_register( array( $this, 'autoload' ) );
        Cost_Tracker_Admin::get_instance();
        $base_dir = static::get_location_path();
        // includes rest api work.
        require_once $base_dir . 'rest-api/version1/class-cost-tracker-rest-api-v1.php'; // NOSONAR - WP compatible.
        require_once $base_dir . 'rest-api/version1/class-cost-tracker-rest-api-handle-v1.php'; // NOSONAR - WP compatible.

        require_once $base_dir . 'rest-api/version2/class-mainwp-rest-costs-controller.php'; // NOSONAR - WP compatible.

        Cost_Tracker_Rest_Api_V1::instance()->init();
        add_filter( 'mainwp_rest_api_get_rest_namespaces', array( $this, 'hook_rest_api_get_rest_namespaces' ), 10, 1 );
    }

    /**
     * Method hook_rest_api_get_rest_namespaces().
     *
     * @param string $namespaces namespaces.
     *
     * @return string namespaces.
     */
    public function hook_rest_api_get_rest_namespaces( $namespaces ) {
        $namespaces['mainwp/v2/costs'] = array( 'costs' => \MainWP_Rest_Costs_Controller::class );
        return $namespaces;
    }

    /**
     * Autoloader for classes.
     *
     * @param string $class_name class name.
     */
    public function autoload( $class_name ) {

        if ( ! preg_match( '/^(?P<namespace>.+)\\\\(?P<autoload>[^\\\\]+)$/', $class_name, $matches ) ) {
            return;
        }

        static $reflection;

        if ( empty( $reflection ) ) {
            $reflection = new \ReflectionObject( $this );
        }

        if ( $reflection->getNamespaceName() !== $matches['namespace'] ) {
            return;
        }

        $autoload_name = $matches['autoload'];
        $autoload_dir  = static::get_location_path();

        $load_dirs = array(
            'classes' => 'class',
            'pages'   => 'page',
            'widgets' => 'widget',
        );
        foreach ( $load_dirs as $dir => $prefix ) {
            $dir           = $dir . DIRECTORY_SEPARATOR;
            $autoload_path = sprintf( '%s%s%s-%s.php', $autoload_dir, $dir, $prefix, strtolower( str_replace( '_', '-', $autoload_name ) ) );
            if ( is_readable( $autoload_path ) ) {
                require_once $autoload_path; // NOSONAR - WP compatible.
                return;
            }
        }
    }

    /**
     * Method get_location().
     *
     * @param string $path what to get path/url.
     *
     * @return string value
     */
    public static function get_location_path( $path = 'dir' ) {
        $location = array(
            'dir' => MAINWP_MODULES_DIR . 'cost-tracker' . DIRECTORY_SEPARATOR,
            'url' => MAINWP_MODULES_URL . 'cost-tracker/',
        );
        return isset( $location[ $path ] ) ? $location[ $path ] : '';
    }
}
