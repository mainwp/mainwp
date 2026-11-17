<?php
/**
 * Remote MCP request object.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * What the front controller hands a route handler: the matched route, the method, the raw query
 * string and body, and the server variables. Nothing here went through WordPress's REST request
 * parsing.
 */
final class Remote_MCP_Request {

    /**
     * Route slot.
     *
     * @var string
     */
    private $slot;

    /**
     * HTTP method, upper case.
     *
     * @var string
     */
    private $method;

    /**
     * Raw query string.
     *
     * @var string
     */
    private $raw_query;

    /**
     * Raw body, capped.
     *
     * @var string
     */
    private $body;

    /**
     * Server variables.
     *
     * @var array
     */
    private $server;

    /**
     * Constructor.
     *
     * @param string $slot      Route slot from Remote_MCP_Services::ROUTE_SLOTS.
     * @param string $method    HTTP method.
     * @param string $raw_query Raw query string.
     * @param string $body      Raw body.
     * @param array  $server    Server variables.
     */
    public function __construct( string $slot, string $method, string $raw_query, string $body, array $server ) {
        $this->slot      = $slot;
        $this->method    = strtoupper( $method );
        $this->raw_query = $raw_query;
        $this->body      = $body;
        $this->server    = $server;
    }

    /**
     * Route slot.
     *
     * @return string
     */
    public function slot(): string {
        return $this->slot;
    }

    /**
     * HTTP method.
     *
     * @return string
     */
    public function method(): string {
        return $this->method;
    }

    /**
     * Raw query string.
     *
     * @return string
     */
    public function raw_query(): string {
        return $this->raw_query;
    }

    /**
     * Raw body.
     *
     * @return string
     */
    public function body(): string {
        return $this->body;
    }

    /**
     * Server variables, for header recovery.
     *
     * @return array
     */
    public function server(): array {
        return $this->server;
    }

    /**
     * One server variable.
     *
     * @param string $name Variable name, for example `HTTP_AUTHORIZATION`.
     * @return string|null
     */
    public function server_var( string $name ): ?string {
        return isset( $this->server[ $name ] ) && is_string( $this->server[ $name ] ) ? $this->server[ $name ] : null;
    }

    /**
     * The query string's parameters under the multiplicity rules.
     *
     * @param array $defined Single-valued names.
     * @param array $multi   Multi-valued names.
     * @return array See Remote_MCP_Params::extract().
     */
    public function query_params( array $defined, array $multi = array() ): array {
        return Remote_MCP_Params::extract( $this->raw_query, $defined, $multi );
    }

    /**
     * The form body's parameters under the multiplicity rules.
     *
     * @param array $defined Single-valued names.
     * @param array $multi   Multi-valued names.
     * @return array See Remote_MCP_Params::extract().
     */
    public function body_params( array $defined, array $multi = array() ): array {
        return Remote_MCP_Params::extract( $this->body, $defined, $multi );
    }
}
