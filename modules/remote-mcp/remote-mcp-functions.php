<?php
/**
 * Public Remote MCP functions for extensions.
 *
 * @package MainWP\Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'mainwp_remote_mcp_authorization_active' ) ) {
    /**
     * Whether deferred work an extension queued under a remote AI connection may still run. Call
     * it before every dispatch with the two values `mainwp_remote_mcp_deferred_context` returned
     * when the work was queued. It is false once the connection is revoked, re-policied, narrowed
     * to View only or turned off.
     *
     * @param int $grant_id       Grant id from the deferred context.
     * @param int $policy_version Policy version from the deferred context.
     * @return bool
     */
    function mainwp_remote_mcp_authorization_active( $grant_id, $policy_version ) {
        if ( ! is_numeric( $grant_id ) || ! is_numeric( $policy_version ) ) {
            return false;
        }
        return \MainWP\Dashboard\Remote_MCP\MCP_Extension_Contracts::authorization_active( (int) $grant_id, (int) $policy_version );
    }
}
