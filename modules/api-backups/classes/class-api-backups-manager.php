<?php
/**
 * MainWP Module API Backups Admin class.
 *
 * @package MainWP\Dashboard
 * @version 5.0
 */

namespace MainWP\Dashboard\Module\ApiBackups;

/**
 * Class Api_Backups_Manager
 */
class Api_Backups_Manager {


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
     * @return Api_Backups_Manager
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
        Api_Backups_Admin::get_instance();
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
     * @return string value.
     */
    public static function get_location_path( $path = 'dir' ) {
        $location = array(
            'dir' => MAINWP_MODULES_DIR . 'api-backups' . DIRECTORY_SEPARATOR,
            'url' => MAINWP_MODULES_URL . 'api-backups/',
        );
        return isset( $location[ $path ] ) ? $location[ $path ] : '';
    }
}
