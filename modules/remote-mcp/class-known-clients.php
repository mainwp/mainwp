<?php
/**
 * Remote MCP client lookup interface.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The single lookup over every client kind: bundled and fetched CIMD documents, static
 * pre-registered ids, registered loopback apps, admin-added web apps and the internal `token`
 * kind for connection tokens. Implemented by `OAuth_Known_Clients`.
 *
 * A client descriptor is an array:
 * - `client_id` (string), `kind` (`bundled`, `static`, `registered`, `webapp` or `token`)
 * - `fetched` (bool, true only for fetched CIMD clients, whose kind is `bundled`)
 * - `label` (string, the registry label or client_id host shown as the app's identity)
 *   and `client_name` (string or null, the app's own name)
 * - `redirect_rule` (`exact`, `loopback_any_port`, `cursor_exact`, `gemini_pattern` or
 *   `registered_loopback`) and `redirect_uris` (strings)
 * - `token_endpoint_auth_methods` (strings, from `none`, `client_secret_basic`, `client_secret_post`)
 * - `oauth_endpoints` (bool, false for `token`, so its id can't be used in an OAuth flow)
 * - `approval_delivery` (`elicitation` or `result`) and `enabled` (bool, the panel switch)
 * - `step_up` (`challenge`, `chatgpt_meta` or `result`): how a write call on a read grant is
 *   answered. Every non-bundled kind answers `result`, as the stub does.
 */
interface Known_Clients {

    /**
     * Descriptor for an exact client_id, compared byte for byte.
     *
     * @param string $client_id Presented client_id.
     * @param bool   $fetch     Whether authorize may fetch an uncached document.
     * @return array|null Descriptor, or null for an unknown or switched-off client.
     */
    public function lookup( string $client_id, bool $fetch = false ): ?array;

    /**
     * Whether a presented redirect URI matches the client under its redirect rule, compared on
     * the raw string.
     *
     * @param array  $client       Descriptor from lookup().
     * @param string $redirect_uri Presented redirect_uri.
     * @return bool
     */
    public function redirect_allowed( array $client, string $redirect_uri ): bool;

    /**
     * Whether a redirect URI may be stored for a client of this kind, checked when the client is
     * added (registration, the panel's web-app form), the add-time twin of redirect_allowed().
     *
     * @param string $kind         `registered` or `webapp`.
     * @param string $redirect_uri Redirect URI as submitted.
     * @return bool
     */
    public function validate_redirect( string $kind, string $redirect_uri ): bool;
}
