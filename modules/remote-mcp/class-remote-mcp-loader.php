<?php
/**
 * Remote MCP class loader.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Autoloader for the module's own namespace.
 */
class Remote_MCP_Loader {

    /**
     * Whether the autoloader is registered.
     *
     * @var bool
     */
    private static $registered = false;

    /**
     * Register the autoloader once.
     */
    public static function init(): void {
        if ( self::$registered ) {
            return;
        }
        spl_autoload_register( array( self::class, 'autoload' ) );
        self::$registered = true;
    }

    /**
     * Load `MainWP\Dashboard\Remote_MCP\Some_Class` from `class-some-class.php` in this directory.
     *
     * @param string $class_name Fully qualified class name.
     */
    public static function autoload( string $class_name ): void {
        $prefix = __NAMESPACE__ . '\\';
        if ( 0 !== strpos( $class_name, $prefix ) ) {
            return;
        }
        $short = substr( $class_name, strlen( $prefix ) );
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $short ) ) {
            return;
        }
        $file = __DIR__ . '/class-' . strtolower( str_replace( '_', '-', $short ) ) . '.php';
        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
}
