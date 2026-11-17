<?php
/**
 * Remote MCP route handler interface.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One front-controller route. The controller has already checked is_active(), HTTPS, the method
 * and the body cap before calling it. Handlers return a response object and never echo. Each
 * route slot in Remote_MCP_Services::SLOTS names its implementing class.
 */
interface Route_Handler {

    /**
     * Handle a request.
     *
     * @param Remote_MCP_Request $request Request.
     * @return Remote_MCP_Response
     */
    public function handle( Remote_MCP_Request $request ): Remote_MCP_Response;
}
