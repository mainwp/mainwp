<?php
/**
 * MainWP System Monitor Loader.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MainWP_System_Monitor_Loader {


    /**
     * Private static variable.
     *
     * @var string registered Registered loader.
     */

    private static $registered = false;


    /**
     * Method init loader.
     */
    public static function init() {

        if ( self::$registered ) {
            return;
        }

        spl_autoload_register( array( self::class, 'autoload' ) );

        self::$registered = true;
    }

    /**
     * Method autoload.
     *
     * @param string $cls Loading class.
     */
    public static function autoload( $cls ) {

        $class = $cls;

        // Only handle our namespace.
        if ( strpos( $class, 'MainWP\\Dashboard\\SystemMonitor' ) !== 0 ) {
            return;
        }

        // Get only class name.
        $class = substr( $class, strrpos( $class, '\\' ) + 1 );

        // convert class name to file format..
        $file = MAINWP_PLUGIN_DIR .
            '/modules/system-monitor/class-' .
            strtolower( str_replace( '_', '-', $class ) ) .
            '.php';

        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
}
