<?php
/**
 * Remote MCP target resolver interface.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns projected arguments into explicit targets: domains, clients, groups, tags and "empty means
 * all" become site ids. Implemented by `MCP_Target_Resolver`.
 *
 * Returns an array with `site_ids` (sorted ints, or null when the ability has no site target) and
 * `entity` (`{type, id}` for atomic entity actions, or null).
 */
interface Target_Resolver {

    /**
     * Resolve targets under the connection user's rights.
     *
     * @param Remote_MCP_Context $context   Connection.
     * @param string             $ability   Ability name.
     * @param array              $arguments Projected arguments.
     * @return array|null Targets, or null when they can't be resolved (refused).
     */
    public function resolve( Remote_MCP_Context $context, string $ability, array $arguments ): ?array;
}
