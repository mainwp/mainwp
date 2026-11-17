<?php
/**
 * Plugin Name: MainWP remote MCP harness: client document
 * Description: E2E harness only. Serves a valid-looking client metadata document at
 * /harness/client.json and counts every fetch, so a check can prove the Dashboard never fetched
 * an unregistered client_id. /harness/client-hits reports the count (?reset=1 clears it).
 *
 * @package MainWP\Dashboard\Tests
 */

add_action(
    'muplugins_loaded',
    function () {
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared as a literal path only.
        $path = (string) wp_parse_url( $uri, PHP_URL_PATH );
        $base = (string) wp_parse_url( home_url(), PHP_URL_PATH );
        if ( $base . '/harness/client.json' === $path ) {
            update_option( 'mainwp_rmcp_harness_client_hits', (int) get_option( 'mainwp_rmcp_harness_client_hits', 0 ) + 1, false );
            $id = home_url( '/harness/client.json' );
            nocache_headers();
            header( 'Content-Type: application/json' );
            echo wp_json_encode(
                array(
                    'client_id'                  => $id,
                    'client_name'                => 'Harness unregistered client',
                    'redirect_uris'              => array( 'http://127.0.0.1/callback' ),
                    'grant_types'                => array( 'authorization_code', 'refresh_token' ),
                    'response_types'             => array( 'code' ),
                    'token_endpoint_auth_method' => 'none',
                )
            );
            exit;
        }
        if ( $base . '/harness/client-hits' === $path ) {
            if ( isset( $_GET['reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- harness counter on a sacrificial site.
                update_option( 'mainwp_rmcp_harness_client_hits', 0, false );
            }
            nocache_headers();
            header( 'Content-Type: application/json' );
            echo wp_json_encode( array( 'hits' => (int) get_option( 'mainwp_rmcp_harness_client_hits', 0 ) ) );
            exit;
        }
    }
);
