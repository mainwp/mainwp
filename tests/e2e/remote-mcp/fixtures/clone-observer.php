<?php
/**
 * Read-only clone proof, run with WP-CLI and the Dashboard plugin skipped.
 *
 * @package MainWP\Dashboard
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    exit( 1 );
}

// Booting the connector before inspection could revoke copied rows after the home change.
$proof_input = json_decode( stream_get_contents( STDIN ), true );
if ( ! is_array( $proof_input ) || ! preg_match( '/^[a-f0-9]{64}$/D', $proof_input['comparison_key'] ?? '' ) ) {
    exit( 1 );
}
$proof_secret = get_option( 'mainwp_remote_mcp_secret' );
$proof_key    = is_array( $proof_secret ) ? base64_decode( $proof_secret['key'] ?? '', true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the module stores its 32-byte key as base64.
if ( false === $proof_key || 32 !== strlen( $proof_key ) || empty( $proof_secret['version'] ) ) {
    exit( 1 );
}
if ( ! is_string( $proof_input['resource'] ?? null ) || ! is_string( $proof_input['access'] ?? null ) || ! is_string( $proof_input['refresh'] ?? null ) ) {
    exit( 1 );
}
$proof_hash_key = hash_hmac( 'sha256', 'mainwp-remote-mcp-token-v1|' . $proof_input['resource'], $proof_key );
$proof_access   = hash_hmac( 'sha256', $proof_input['access'], $proof_hash_key );
$proof_refresh  = hash_hmac( 'sha256', $proof_input['refresh'], $proof_hash_key );
global $wpdb;
$proof_tokens = $wpdb->get_results(
    $wpdb->prepare(
        'SELECT * FROM %i WHERE token_hash IN (%s, %s) ORDER BY token_type',
        $wpdb->prefix . 'mainwp_oauth_tokens',
        $proof_access,
        $proof_refresh
    ),
    ARRAY_A
);
if ( 2 !== count( $proof_tokens ) || $proof_tokens[0]['grant_id'] !== $proof_tokens[1]['grant_id'] || array( 'access', 'refresh' ) !== array_column( $proof_tokens, 'token_type' ) ) {
    exit( 1 );
}
$proof_grant = $wpdb->get_row(
    $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'mainwp_oauth_grants', $proof_tokens[0]['grant_id'] ),
    ARRAY_A
);
if ( ! is_array( $proof_grant ) || 'live' !== $proof_grant['status'] ) {
    exit( 1 );
}
// Search-replace may rewrite resource columns. The original keyed token hashes must survive.
unset( $proof_grant['resource'] );
foreach ( $proof_tokens as &$proof_token ) {
    unset( $proof_token['resource'] );
}
unset( $proof_token );
$proof_result = array(
    'home'     => rtrim( get_option( 'home' ), '/' ),
    'identity' => hash_hmac( 'sha256', wp_json_encode( array( $proof_secret, get_option( 'mainwp_remote_mcp_enabled' ) ) ), $proof_input['comparison_key'] ),
    'state'    => hash_hmac( 'sha256', wp_json_encode( array( $proof_grant, $proof_tokens ) ), $proof_input['comparison_key'] ),
    'tokens'   => count( $proof_tokens ),
);
// Only fresh keyed fingerprints leave the process; never token hashes, credentials or keys.
echo wp_json_encode( $proof_result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- machine-readable local proof containing no credential material.
