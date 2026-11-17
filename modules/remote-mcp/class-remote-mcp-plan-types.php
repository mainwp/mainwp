<?php
/**
 * Remote MCP target resolver and execution adapter type names.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The type names a Tier_Registry entry's `resolver` and `adapter` may hold. Several classes build
 * and read entries, so the names live here once; an entry naming anything else is not available.
 *
 * The audited tier table uses its own labels, mapped once:
 *
 * - Resolver: "global", "none" or an operation or page reference is `none`; one `site_id` or
 *   `site_id_or_domain` is `site`; `site_ids_or_domains` is `sites`; "one client" is `client`;
 *   "one tag" is `tag`.
 * - Adapter: `direct` and `direct-read` are `direct`; `atomic`, `atomic entity action`,
 *   `atomic-site` and `atomic-global` are `atomic`; `chunk` is `chunked_sites`; `updates` is
 *   `updates`.
 */
final class Remote_MCP_Plan_Types {

    /**
     * Target resolver types.
     *
     * @var array
     */
    const RESOLVERS = array( 'none', 'site', 'sites', 'client', 'tag' );

    /**
     * Execution adapter types.
     *
     * @var array
     */
    const ADAPTERS = array( 'direct', 'atomic', 'chunked_sites', 'updates' );
}
