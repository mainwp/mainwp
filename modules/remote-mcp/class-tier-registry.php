<?php
/**
 * Remote MCP tier registry interface.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one accessor over the core and extension tier tables and declared contracts. Implemented by
 * `MCP_Tier_Registry`; the approval gate and the job runner read tiers, contracts, resolvers and
 * adapters only through it and keep no copy.
 *
 * Precedence: a core table entry, then an audited extension entry, then a
 * declared contract, then for a read ability with valid annotations (`readonly: true`,
 * `destructive: false`) `source: annotations` with tier `read`, run directly with no contract.
 * The uncontracted fallback (tier `dashboard`) is for writes with valid annotations only;
 * anything else gets null.
 *
 * An entry is an array: `source` (`core`, `audit`, `declared`, `annotations`, `uncontracted` or `refused`),
 * `tier`, `conditional` (a callback name or null), `contract` (the Control_Contract shape),
 * `resolver` and `adapter` (type names), `sensitive`, `deferred`, `credential_bearing`,
 * `gate_supplied`, `compare_fields`, `min_version`, `inert` and the audit `reason`.
 */
interface Tier_Registry {

    /**
     * Entry for an ability.
     *
     * @param string $ability Ability name.
     * @return array|null Entry, or null when the ability isn't available remotely.
     */
    public function entry( string $ability ): ?array;
}
