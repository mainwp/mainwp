<?php
/**
 * Remote MCP hook provider interface.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A slot implementation's own WordPress hooks (revocation triggers, admin panel, approval page,
 * audit connector, job cron). The wiring calls register_hooks() once at boot, whether or not the
 * connector is on; anything that must stop when it is off checks Remote_MCP_Settings::is_active()
 * itself.
 */
interface Hook_Provider {

    /**
     * Add hooks.
     *
     * @param Remote_MCP_Services $services Module services.
     */
    public function register_hooks( Remote_MCP_Services $services ): void;
}
