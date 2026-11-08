<?php
/**
 * Remote MCP response object.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Status, headers and body of one module response. Handlers build it and the front
 * controller emits it, so PHPUnit can assert what would go on the wire.
 */
final class Remote_MCP_Response {

    /**
     * HTTP status.
     *
     * @var int
     */
    private $status;

    /**
     * Header name => value.
     *
     * @var array
     */
    private $headers;

    /**
     * Body.
     *
     * @var string
     */
    private $body;

    /**
     * Constructor. Every response starts `no-store`.
     *
     * @param int    $status  HTTP status.
     * @param array  $headers Header name => value.
     * @param string $body    Body.
     */
    public function __construct( int $status, array $headers = array(), string $body = '' ) {
        $this->status  = $status;
        $this->headers = array_merge( array( 'Cache-Control' => 'no-store' ), $headers );
        $this->body    = $body;
    }

    /**
     * JSON response.
     *
     * @param int   $status  HTTP status.
     * @param array $data    Body data.
     * @param array $headers Extra headers.
     * @return self
     */
    public static function json( int $status, array $data, array $headers = array() ): self {
        return new self( $status, array_merge( array( 'Content-Type' => 'application/json' ), $headers ), (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * JSON-RPC error response.
     *
     * @param int             $status  HTTP status.
     * @param int             $code    JSON-RPC error code.
     * @param string          $message Fixed message.
     * @param int|string|null $id      Request id, or null when it wasn't read.
     * @return self
     */
    public static function jsonrpc_error( int $status, int $code, string $message, $id = null ): self {
        return self::json(
            $status,
            array(
                'jsonrpc' => '2.0',
                'id'      => $id,
                'error'   => array(
                    'code'    => $code,
                    'message' => $message,
                ),
            )
        );
    }

    /**
     * HTML page that can't be framed.
     *
     * @param int    $status HTTP status.
     * @param string $html   Escaped HTML.
     * @return self
     */
    public static function html( int $status, string $html ): self {
        return new self(
            $status,
            array(
                'Content-Type'            => 'text/html; charset=utf-8',
                'X-Frame-Options'         => 'DENY',
                'Content-Security-Policy' => "frame-ancestors 'none'; default-src 'self'; style-src 'self'",
            ),
            $html
        );
    }

    /**
     * Status.
     *
     * @return int
     */
    public function status(): int {
        return $this->status;
    }

    /**
     * Headers.
     *
     * @return array
     */
    public function headers(): array {
        return $this->headers;
    }

    /**
     * One header, matched case-insensitively.
     *
     * @param string $name Header name.
     * @return string|null
     */
    public function header( string $name ): ?string {
        foreach ( $this->headers as $key => $value ) {
            if ( 0 === strcasecmp( $key, $name ) ) {
                return (string) $value;
            }
        }
        return null;
    }

    /**
     * Body.
     *
     * @return string
     */
    public function body(): string {
        return $this->body;
    }

    /**
     * Copy without any CORS header: v1 sends none, whatever a handler set.
     *
     * @return self
     */
    public function without_cors(): self {
        $headers = array();
        foreach ( $this->headers as $name => $value ) {
            if ( 0 !== stripos( (string) $name, 'access-control-' ) ) {
                $headers[ $name ] = $value;
            }
        }
        $copy          = clone $this;
        $copy->headers = $headers;
        return $copy;
    }
}
