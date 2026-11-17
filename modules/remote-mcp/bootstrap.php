<?php
/**
 * Remote MCP bootstrap.
 *
 * PHP 7.4 syntax only: this file runs on every supported PHP version and decides whether the
 * module's 8.1 classes may load at all.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'MAINWP_REMOTE_MCP_MIN_PHP' ) ) {
    define( 'MAINWP_REMOTE_MCP_MIN_PHP', '8.1' );
}

if ( ! function_exists( __NAMESPACE__ . '\\bootstrap_module' ) ) {

    /**
     * Load the module, the PHP fallback, or nothing.
     *
     * Version 1 is single-site only, so a multisite loads nothing. Below the PHP floor only the
     * requirements and fallback-panel files load, when present, and only the panel registers;
     * nothing that needs PHP 8.1 is required, so activation never fatals. At or above the floor
     * the module loads as before.
     *
     * @param string $php_version PHP version to decide for.
     * @param bool   $multisite   Whether the site is a multisite.
     * @param string $dir         Directory holding the module files.
     * @param string $panel_class Fallback panel class, which registers through register_hooks().
     * @return string `module`, `fallback` or `none`.
     */
    function bootstrap_module( $php_version, $multisite, $dir, $panel_class = 'MainWP\\Dashboard\\Remote_MCP\\Remote_MCP_Fallback_Panel' ) {
        if ( $multisite ) {
            return 'none';
        }
        if ( version_compare( $php_version, MAINWP_REMOTE_MCP_MIN_PHP, '<' ) ) {
            $panel_file = $dir . '/class-remote-mcp-fallback-panel.php';
            foreach ( array( $dir . '/class-remote-mcp-requirements.php', $panel_file ) as $file ) {
                if ( is_readable( $file ) ) {
                    require_once $file;
                }
            }
            // Only a panel from this directory registers; nothing else may hook in below the floor.
            if ( is_readable( $panel_file ) && class_exists( $panel_class, false ) && method_exists( $panel_class, 'register_hooks' ) ) {
                call_user_func( array( $panel_class, 'register_hooks' ) );
            }
            return 'fallback';
        }
        require_once $dir . '/class-remote-mcp-loader.php';
        Remote_MCP_Loader::init();
        Remote_MCP::boot();
        return 'module';
    }
}

bootstrap_module( PHP_VERSION, is_multisite(), __DIR__ );
