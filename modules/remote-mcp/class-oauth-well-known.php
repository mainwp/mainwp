<?php
/**
 * Remote MCP authorization server metadata route.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `route_as_metadata`: the RFC 8414 document at every well-known form the front controller
 * matches for this install.
 */
final class OAuth_Well_Known implements Route_Handler {

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
        // Over http, well-known metadata is a plain 404, also when reached without the front controller.
        if ( ! is_ssl() ) {
            return OAuth_Metadata::response( null );
        }
        return OAuth_Metadata::response( OAuth_Metadata::authorization_server( $this->services->urls() ) );
    }
}
