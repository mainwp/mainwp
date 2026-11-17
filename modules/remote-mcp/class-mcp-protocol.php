<?php
/**
 * Remote MCP protocol rules.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The two served protocol eras: message parsing, era selection and the 2026-07-28
 * validation table. Pure functions over the decoded message and the request headers, so the
 * endpoint keeps only dispatch.
 */
final class MCP_Protocol {

    /**
     * Legacy-only mode switch. Turning it off is the planned flip if ChatGPT fails
     * against a server that answers server/discover, so it stays a constant.
     *
     * @var bool
     */
    const MODERN_ENABLED = true;

    const MODERN = '2026-07-28';
    const LEGACY = '2025-11-25';

    /**
     * Every version served, modern first, as the -32022 and -32020 data lists them.
     *
     * @var string[]
     */
    const SUPPORTED = array( self::MODERN, self::LEGACY );

    /**
     * Initialization-based versions we don't serve. A follow-up naming one is a header mismatch
     * under the legacy contract; any other unknown version is -32022.
     *
     * @var string[]
     */
    const OLDER_LEGACY = array( '2024-11-05', '2025-03-26', '2025-06-18' );

    const META_VERSION      = 'io.modelcontextprotocol/protocolVersion';
    const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    const META_SERVER_INFO  = 'io.modelcontextprotocol/serverInfo';

    const PARSE_ERROR         = -32700;
    const INVALID_REQUEST     = -32600;
    const METHOD_NOT_FOUND    = -32601;
    const INVALID_PARAMS      = -32602;
    const INTERNAL_ERROR      = -32603;
    const HEADER_MISMATCH     = -32020;
    const UNSUPPORTED_VERSION = -32022;

    /**
     * Fixed error messages; nothing from the request is echoed.
     *
     * @var array
     */
    const MESSAGES = array(
        self::PARSE_ERROR         => 'Parse error',
        self::INVALID_REQUEST     => 'Invalid request',
        self::METHOD_NOT_FOUND    => 'Method not found',
        self::INVALID_PARAMS      => 'Invalid params',
        self::INTERNAL_ERROR      => 'Internal error',
        self::HEADER_MISMATCH     => 'Header mismatch',
        self::UNSUPPORTED_VERSION => 'Unsupported protocol version',
    );

    /**
     * Methods per era.
     *
     * @var array
     */
    const METHODS = array(
        'modern' => array( 'server/discover', 'ping', 'tools/list', 'tools/call', 'prompts/list', 'prompts/get' ),
        'legacy' => array( 'initialize', 'ping', 'tools/list', 'tools/call', 'prompts/list', 'prompts/get' ),
    );

    /**
     * Methods that need only a valid token; everything else needs `mainwp:read`.
     *
     * @var string[]
     */
    const TOKEN_ONLY_METHODS = array( 'initialize', 'server/discover', 'ping' );

    /**
     * How long a modern client may cache a list or discover result, in milliseconds.
     *
     * @var int
     */
    const TTL_MS = 300000;

    /**
     * Parse one request body. Objects stay stdClass, so `{}` and `[]` remain distinguishable.
     *
     * @param string $body Raw body.
     * @return array `message` (stdClass) on success, or `error` [ status, code, id ].
     */
    public static function parse( string $body ): array {
        $message = json_decode( $body, false, 512, JSON_BIGINT_AS_STRING );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return array( 'error' => array( 400, self::PARSE_ERROR, null ) );
        }
        // A batch is refused for every served version: 2025-06-18 removed batching.
        if ( ! $message instanceof \stdClass ) {
            return array( 'error' => array( 400, self::INVALID_REQUEST, null ) );
        }
        $id = property_exists( $message, 'id' ) ? $message->id : null;
        if ( ! is_string( $id ) && ! is_int( $id ) ) {
            $id = null;
        }
        if ( ! isset( $message->jsonrpc ) || '2.0' !== $message->jsonrpc || ! isset( $message->method ) || ! is_string( $message->method ) || '' === $message->method ) {
            return array( 'error' => array( 400, self::INVALID_REQUEST, $id ) );
        }
        if ( property_exists( $message, 'id' ) && null === $id ) {
            return array( 'error' => array( 400, self::INVALID_REQUEST, null ) );
        }
        if ( property_exists( $message, 'params' ) && ! $message->params instanceof \stdClass ) {
            return array( 'error' => array( 400, self::INVALID_REQUEST, $id ) );
        }
        return array( 'message' => $message );
    }

    /**
     * Request params as an object, an empty one when absent.
     *
     * @param \stdClass $message Message.
     * @return \stdClass
     */
    public static function params( \stdClass $message ): \stdClass {
        return isset( $message->params ) && $message->params instanceof \stdClass ? $message->params : new \stdClass();
    }

    /**
     * A request header from the server variables.
     *
     * @param array  $server Server variables.
     * @param string $name   Header name, for example `MCP-Protocol-Version`.
     * @return string|null
     */
    public static function header( array $server, string $name ): ?string {
        $key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
        return isset( $server[ $key ] ) && is_string( $server[ $key ] ) ? trim( $server[ $key ] ) : null;
    }

    /**
     * Which era serves a message.
     *
     * @param \stdClass $message Message.
     * @param array     $server  Server variables.
     * @return array `era` (`modern` or `legacy`), or `error` [ status, code, id, data ].
     */
    public static function select_era( \stdClass $message, array $server ): array {
        $params = self::params( $message );
        $meta   = isset( $params->_meta ) && $params->_meta instanceof \stdClass ? $params->_meta : null;
        if ( null !== $meta && property_exists( $meta, self::META_VERSION ) ) {
            return array( 'era' => 'modern' );
        }
        if ( 'initialize' === $message->method ) {
            return array( 'era' => 'legacy' );
        }
        $header = self::header( $server, 'MCP-Protocol-Version' );
        if ( null === $header || self::LEGACY === $header ) {
            // ChatGPT sends tools/call with no version header right after negotiating 2025-11-25,
            // so a headerless follow-up is served as that version on purpose.
            return array( 'era' => 'legacy' );
        }
        $id = isset( $message->id ) ? $message->id : null;
        if ( self::MODERN === $header ) {
            // A modern header with no _meta version fails the same _meta check as validate_modern().
            return array( 'error' => array( 400, self::INVALID_PARAMS, $id, null ) );
        }
        if ( in_array( $header, self::OLDER_LEGACY, true ) ) {
            return array( 'error' => array( 400, self::HEADER_MISMATCH, $id, array( 'supported' => self::SUPPORTED ) ) );
        }
        return array(
            'error' => array(
                400,
                self::UNSUPPORTED_VERSION,
                $id,
                array(
                    'supported' => self::SUPPORTED,
                    'requested' => self::printable( $header ),
                ),
            ),
        );
    }

    /**
     * The 2026-07-28 validation table, its rules in order. The `x-mcp-header` rule has nothing to
     * check: we declare no `x-mcp-header` parameters.
     *
     * @param \stdClass $message Message.
     * @param array     $server  Server variables.
     * @return array|null Null when valid, else `error` [ status, code, id, data ].
     */
    public static function validate_modern( \stdClass $message, array $server ): ?array {
        $id      = $message->id ?? null;
        $params  = self::params( $message );
        $version = self::header( $server, 'MCP-Protocol-Version' );
        $method  = self::header( $server, 'Mcp-Method' );
        $name    = self::header( $server, 'Mcp-Name' );
        $is_call = 'tools/call' === $message->method;

        if ( null === $version || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $version ) || null === $method || '' === $method || ( $is_call && ( null === $name || '' === $name ) ) ) {
            return array( 400, self::HEADER_MISMATCH, $id, null );
        }
        $meta = isset( $params->_meta ) && $params->_meta instanceof \stdClass ? $params->_meta : null;
        if ( null === $meta || ! isset( $meta->{self::META_VERSION} ) || ! is_string( $meta->{self::META_VERSION} ) || ! isset( $meta->{self::META_CAPABILITIES} ) || ! $meta->{self::META_CAPABILITIES} instanceof \stdClass ) {
            return array( 400, self::INVALID_PARAMS, $id, null );
        }
        $requested = $meta->{self::META_VERSION};
        if ( $version !== $requested || $method !== $message->method ) {
            return array( 400, self::HEADER_MISMATCH, $id, null );
        }
        if ( $is_call && ( ! isset( $params->name ) || ! is_string( $params->name ) || self::decode_name( $name ) !== $params->name ) ) {
            return array( 400, self::HEADER_MISMATCH, $id, null );
        }
        // Only 2026-07-28 is served statelessly; a _meta naming the legacy version is a client
        // mixing eras, refused rather than guessed at.
        if ( self::MODERN !== $requested ) {
            return array(
                400,
                self::UNSUPPORTED_VERSION,
                $id,
                array(
                    'supported' => self::SUPPORTED,
                    'requested' => self::printable( $requested ),
                ),
            );
        }
        if ( ! in_array( $message->method, self::METHODS['modern'], true ) ) {
            return array( 404, self::METHOD_NOT_FOUND, $id, null );
        }
        return null;
    }

    /**
     * Decode the `=?base64?...?=` sentinel form of Mcp-Name; any other value is used as sent.
     *
     * @param string $value Header value.
     * @return string|null Null when the sentinel form doesn't decode.
     */
    public static function decode_name( string $value ): ?string {
        if ( 1 !== preg_match( '/^=\?base64\?([A-Za-z0-9+\/]*={0,2})\?=$/', $value, $match ) ) {
            return $value;
        }
        $decoded = base64_decode( $match[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the MCP header encoding for non-ASCII tool names.
        return false === $decoded ? null : $decoded;
    }

    /**
     * Whether a modern request's client capabilities declare URL-mode elicitation.
     *
     * @param \stdClass $message Message.
     * @return bool
     */
    public static function supports_url_elicitation( \stdClass $message ): bool {
        $meta = self::params( $message )->_meta ?? null;
        $caps = $meta instanceof \stdClass ? ( $meta->{self::META_CAPABILITIES} ?? null ) : null;
        if ( ! $caps instanceof \stdClass || ! isset( $caps->elicitation ) || ! $caps->elicitation instanceof \stdClass ) {
            return false;
        }
        return isset( $caps->elicitation->url ) && ( $caps->elicitation->url instanceof \stdClass || true === $caps->elicitation->url );
    }

    /**
     * Capabilities both eras advertise. No resources, completions or logging.
     *
     * @return array
     */
    public static function capabilities(): array {
        return array(
            'tools'   => array( 'listChanged' => false ),
            'prompts' => array( 'listChanged' => false ),
        );
    }

    /**
     * A JSON value as plain PHP arrays, for ability arguments.
     *
     * @param mixed $value Decoded JSON.
     * @return mixed
     */
    public static function to_array( $value ) {
        if ( $value instanceof \stdClass ) {
            $value = get_object_vars( $value );
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value[ $key ] = self::to_array( $item );
            }
        }
        return $value;
    }

    /**
     * A client-sent version string reduced to what a version can contain, for the -32022 data.
     *
     * @param string $value Value.
     * @return string
     */
    private static function printable( string $value ): string {
        return substr( (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $value ), 0, 32 );
    }
}
