<?php
/**
 * Remote MCP protected resource metadata route.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `route_prm`: the RFC 9728 document the 401 challenge points to. The resource
 * comes from the stored home, never from a request parameter or header.
 */
final class OAuth_Protected_Resource implements Route_Handler {

    /**
     * Module services.
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
     * Serve the document.
     *
     * @param Remote_MCP_Request $request Request.
     * @return Remote_MCP_Response
     */
    public function handle( Remote_MCP_Request $request ): Remote_MCP_Response {
        // Same refusal as the front controller gives MCP-route requests over http.
        if ( ! is_ssl() ) {
            return Remote_MCP_Response::jsonrpc_error( 400, -32600, 'HTTPS required' );
        }
        return OAuth_Metadata::response( OAuth_Metadata::protected_resource( $this->services->urls() ) );
    }
}
