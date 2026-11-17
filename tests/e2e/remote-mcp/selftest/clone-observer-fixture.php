<?php
/**
 * WordPress stand-ins for an offline clone-observer process.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Tests\Remote_MCP_Observer;

define( 'ABSPATH', __DIR__ );
define( 'WP_CLI', true );
define( 'ARRAY_A', 'ARRAY_A' );

/** Local database fixture; never opens a connection. */
class Observer_DB {
    public $prefix = 'wp_';

    public function prepare( $query, ...$args ) {
        return array( $query, $args );
    }

    public function get_results( $query, $format ) {
        if ( ARRAY_A !== $format || array( 'wp_mainwp_oauth_tokens', 'e275631b6027b8d361ff4294ffab07b5d1b76458cb754771b93c36ba03b7da05', 'a11dcdab2806bb436bfeb759d941df61d45b943cec505189c9d8889b73804d8b' ) !== $query[1] ) { // gitleaks:allow -- synthetic HMACs for the fixed fixture credentials.
            return array();
        }
        if ( 'empty' === getenv( 'RMCP_OBSERVER_CASE' ) ) {
            return array();
        }
        return array(
            array( 'grant_id' => '7', 'token_type' => 'access', 'token_hash' => $query[1][1], 'resource' => get_option( 'home' ) . '/wp-json/mainwp-remote/v1/mcp' ),
            array( 'grant_id' => '7', 'token_type' => 'refresh', 'token_hash' => $query[1][2], 'resource' => get_option( 'home' ) . '/wp-json/mainwp-remote/v1/mcp' ),
        );
    }

    public function get_row( $query, $format ) {
        if ( ARRAY_A !== $format || array( 'wp_mainwp_oauth_grants', '7' ) !== $query[1] ) {
            return null;
        }
        return array( 'id' => '7', 'status' => 'live', 'resource' => get_option( 'home' ) . '/wp-json/mainwp-remote/v1/mcp' );
    }
}

// The observer uses WordPress's global functions; keep the stand-ins outside its namespace.
require __DIR__ . '/observer-wordpress-fixture.php';
$GLOBALS['wpdb'] = new Observer_DB();
require dirname( __DIR__ ) . '/fixtures/clone-observer.php';
