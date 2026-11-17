<?php
/**
 * Remote MCP panel below the PHP floor.
 *
 * PHP 7.4 syntax only, and it may name no module class except Remote_MCP_Requirements: below the
 * floor the module autoloader isn't registered.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only thing that registers when PHP is too old: a panel saying which version is needed.
 */
final class Remote_MCP_Fallback_Panel {

    /**
     * Register the panel, the bootstrap's one hook below the floor.
     */
    public static function register_hooks() {
        add_filter( 'mainwp_mcp_direct_view', array( __CLASS__, 'direct_view' ) );
    }

    /**
     * Offer the panel as the MainWP MCP page's "Connect an app" view. The connector can't run
     * here, so the local server stays the default view.
     *
     * @param mixed $view View an earlier callback offered.
     * @return array
     */
    public static function direct_view( $view ) {
        unset( $view );
        return array(
            'render'             => array( __CLASS__, 'render_page' ),
            'render_with_header' => array( __CLASS__, 'render_page' ),
            'preferred'          => false,
            'apps'               => array(),
        );
    }

    /**
     * View callback.
     *
     * @param callable|null $render_header Page heading, rendered after page-level messages.
     */
    public static function render_page( $render_header = null ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo self::render( PHP_VERSION ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes.
        if ( is_callable( $render_header ) ) {
            call_user_func( $render_header );
        }
    }

    /**
     * The panel body.
     *
     * @param string $running Running PHP version.
     * @return string HTML.
     */
    public static function render( $running ) {
        $required = class_exists( __NAMESPACE__ . '\\Remote_MCP_Requirements', false ) ? Remote_MCP_Requirements::min_php() : '8.1';
        /* translators: 1: required PHP version, 2: running PHP version. */
        $message = sprintf( __( 'Remote AI connections need PHP %1$s or newer; this server runs %2$s.', 'mainwp' ), $required, $running );
        return '<div class="ui yellow message">' . esc_html( $message ) . '</div>';
    }
}
