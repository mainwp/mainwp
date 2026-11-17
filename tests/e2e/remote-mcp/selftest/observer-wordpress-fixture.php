<?php
/**
 * Global option and JSON stand-ins for the offline observer fixture.
 *
 * @package MainWP\Dashboard
 */

function get_option( $name ) {
    if ( 'home' === $name ) {
        return 'clone' === getenv( 'RMCP_OBSERVER_CASE' ) ? 'https://clone.example' : 'https://dash.example';
    }
    if ( 'mainwp_remote_mcp_secret' === $name ) {
        return array( 'version' => 1, 'key' => 'a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=' );
    }
    return 'mainwp_remote_mcp_enabled' === $name;
}

function wp_json_encode( $value ) {
    return json_encode( $value );
}
