<?php
/**
 * Remote MCP scope and tier policy.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Effective scope and effective tier, the `scope_policy` slot. The tier comes
 * from the registry entry, then its conditional callback over the projected arguments, then the
 * connection's approval policy. The gate asks this at preview, at execution and before consume.
 */
class MCP_Scope_Policy implements Scope_Policy {

    /**
     * Services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services Services.
     */
    public function __construct( Remote_MCP_Services $services ) {
        $this->services = $services;
    }

    /**
     * Token scope intersected with the grant's current scope.
     *
     * @param string $token_scope Token scope.
     * @param string $grant_scope Grant scope.
     * @return string[]
     */
    public function effective_scopes( string $token_scope, string $grant_scope ): array {
        return OAuth_Store::scope_tokens( OAuth_Store::intersect_scopes( $token_scope, $grant_scope ) );
    }

    /**
     * Tier of this call now, or null when it isn't available remotely.
     *
     * @param Remote_MCP_Context $context   Connection.
     * @param string             $ability   Ability name.
     * @param array              $arguments Projected arguments.
     * @return string|null
     */
    public function effective_tier( Remote_MCP_Context $context, string $ability, array $arguments ): ?string {
        $entry = $this->services->get( 'tier_registry' )->entry( $ability );
        if ( ! is_array( $entry ) || 'refused' === ( $entry['source'] ?? null ) || ! empty( $entry['inert'] ) ) {
            return null;
        }
        $tier = $entry['tier'] ?? null;
        if ( is_string( $entry['conditional'] ?? null ) ) {
            $tier = MCP_Tool_Catalog::conditional_tier( $entry['conditional'], $arguments );
        }
        if ( ! in_array( $tier, array( 'read', 'write', OAuth_Store::TIER_CONFIRM, OAuth_Store::TIER_DASHBOARD ), true ) ) {
            return null;
        }
        if ( 'read' === $tier ) {
            return $tier;
        }

        $policy = $context->approval_policy;
        // Hands-off is rechecked on every call: a user who gained Team Control limits since
        // choosing it is treated as Standard until the policy is changed.
        if ( OAuth_Store::POLICY_HANDS_OFF === $policy && ! Remote_MCP_Team_Control::user_has_no_limits( $context->user_id ) ) {
            $policy = OAuth_Store::POLICY_STANDARD;
        }
        switch ( $policy ) {
            case OAuth_Store::POLICY_STANDARD:
                return $tier;
            case OAuth_Store::POLICY_HANDS_OFF:
                if ( OAuth_Store::TIER_DASHBOARD === $tier && ! MCP_Tool_Catalog::hands_off_floor( $ability, $arguments ) ) {
                    return OAuth_Store::TIER_CONFIRM;
                }
                return $tier;
        }
        // Strict, and any policy value this code doesn't know.
        return OAuth_Store::TIER_DASHBOARD;
    }
}
