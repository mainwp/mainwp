<?php
/**
 * Remote MCP enable check interface.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The self-test conditions that must pass before the connector can be turned on: path routing,
 * discovery per client, the activity log, PHP and WordPress versions. Implemented by
 * `Remote_MCP_Self_Test`.
 */
interface Enable_Check {

    /**
     * Reasons enabling must be refused.
     *
     * @return string[] Short reason codes; empty when every check passes.
     */
    public function blocking_reasons(): array;
}
