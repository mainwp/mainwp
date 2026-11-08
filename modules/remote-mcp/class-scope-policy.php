<?php
/**
 * Remote MCP scope and tier policy interface.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Effective scope and effective tier. Implemented by `MCP_Scope_Policy`, which owns the
 * CORE_TIERS and EXTENSION_TIERS tables; the approval gate's policy tests verify the Strict and
 * Hands-off mapping through it.
 *
 * Tiers are `read`, `write`, `confirm` and `dashboard`.
 */
interface Scope_Policy {

    /**
     * Effective scopes: the token's scope intersected with the grant's current scope.
     *
     * @param string $token_scope Token scope.
     * @param string $grant_scope Grant scope now.
     * @return string[]
     */
    public function effective_scopes( string $token_scope, string $grant_scope ): array;

    /**
     * The tier a call has right now, under the connection's policy and the call's arguments.
     *
     * @param Remote_MCP_Context $context   Connection.
     * @param string             $ability   Ability name.
     * @param array              $arguments Projected arguments.
     * @return string|null Tier, or null when the call isn't available remotely.
     */
    public function effective_tier( Remote_MCP_Context $context, string $ability, array $arguments ): ?string;
}
