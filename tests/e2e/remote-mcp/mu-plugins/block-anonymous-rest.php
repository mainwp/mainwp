<?php
/**
 * Plugin Name: MainWP remote MCP harness: block anonymous REST
 * Description: E2E harness only. Behaves like a security plugin that refuses every anonymous
 * REST request through rest_authentication_errors.
 *
 * @package MainWP\Dashboard\Tests
 */

add_filter(
    'rest_authentication_errors',
    function ( $result ) {
        if ( ! empty( $result ) || is_user_logged_in() ) {
            return $result;
        }
        return new WP_Error( 'harness_rest_blocked', 'Anonymous REST requests are blocked.', array( 'status' => 401 ) );
    },
    99
);
