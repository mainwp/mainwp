<?php
/**
 * Remote MCP authorization request rules.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The client_id, redirect, PKCE, scope, resource and state rules of an authorization request.
 * Everything works on the raw strings the client sent: no WordPress URL sanitizer, no parsing
 * before a byte comparison. Client documents are fetched only after request syntax passes.
 */
final class OAuth_Request_Validator {

    /**
     * Single-valued authorization parameters. `resource` is counted separately.
     *
     * @var array
     */
    const PARAMS = array( 'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method' );

    /**
     * Longest redirect URI and state accepted, in bytes.
     *
     * @var int
     */
    const MAX_LENGTH = 2048;

    /**
     * Kinds whose OAuth endpoints are open. The `token` kind exists only for connection tokens.
     *
     * @var array
     */
    const OAUTH_KINDS = array( 'bundled', 'static', 'registered', 'webapp' );

    /**
     * Kinds whose redirects can contain no `@`, `\`, `%`, whitespace or control character: their
     * sets come from our own code or rows, so a redirect that needs one is a trick.
     *
     * @var array
     */
    const PLAIN_REDIRECT_KINDS = array( 'static', 'registered', 'webapp' );

    /**
     * Client lookup.
     *
     * @var Known_Clients
     */
    private $clients;

    /**
     * URLs.
     *
     * @var Remote_MCP_Urls
     */
    private $urls;

    /**
     * Constructor.
     *
     * @param Known_Clients   $clients Client lookup.
     * @param Remote_MCP_Urls $urls    URLs.
     */
    public function __construct( Known_Clients $clients, Remote_MCP_Urls $urls ) {
        $this->clients = $clients;
        $this->urls    = $urls;
    }

    /**
     * Syntax precedes client lookup so malformed requests cannot trigger a fetch. Failures appear
     * on our own page, because nothing about the client has been verified yet.
     *
     * @param Remote_MCP_Request $request Authorization request.
     * @return array `error` (a code) on failure, else `client` (descriptor) and `params`.
     */
    public function check_request( Remote_MCP_Request $request ): array {
        $parsed = $request->query_params( self::PARAMS, array( 'resource' ) );
        // Before any lookup, so a repeated client_id can't pick which value is looked up.
        if ( null !== $parsed['duplicate'] ) {
            return array( 'error' => 'duplicate_parameter' );
        }
        if ( 1 !== count( $parsed['multi']['resource'] ) ) {
            return array( 'error' => 'invalid_target' );
        }
        $values    = $parsed['values'];
        $client_id = $values['client_id'];
        if ( null === $client_id ) {
            return array( 'error' => 'unsupported_client' );
        }
        if ( ! self::redirect_syntax_ok( $values['redirect_uri'] ) ) {
            return array( 'error' => 'invalid_redirect_uri' );
        }
        if ( 'code' !== $values['response_type'] ) {
            return array( 'error' => 'unsupported_response_type' );
        }
        if ( 'S256' !== $values['code_challenge_method'] || 1 !== preg_match( '/^[A-Za-z0-9_-]{43}\z/', (string) $values['code_challenge'] ) ) {
            return array( 'error' => 'invalid_code_challenge' );
        }
        if ( null !== $values['state'] && strlen( $values['state'] ) > self::MAX_LENGTH ) {
            return array( 'error' => 'invalid_state' );
        }
        if ( ! $this->is_canonical_resource( $parsed['multi']['resource'][0] ) ) {
            return array( 'error' => 'invalid_target' );
        }
        $client = $this->clients->lookup( $client_id, true );
        if ( ! self::accepts( $client, $client_id ) ) {
            // Anyone can send this before logging in, so it is counted, not logged.
            Remote_MCP_Settings::bump_counter( 'unknown_client' );
            return array( 'error' => null === $client && 0 === strpos( $client_id, 'mwplc_' ) ? 'registration_ended' : 'unsupported_client' );
        }
        return array(
            'client' => $client,
            'params' => array(
                'client_id'      => $client_id,
                'redirect_uri'   => $values['redirect_uri'],
                'scope'          => $values['scope'],
                'state'          => $values['state'],
                'code_challenge' => $values['code_challenge'],
                'resource'       => (string) $this->urls->mcp_url(),
                'names'          => $parsed['names'],
            ),
        );
    }

    /**
     * Whether a lookup answer is a client the OAuth endpoints serve: the exact id asked for, a
     * kind other than `token`, and switched on.
     *
     * @param array|null $client    Descriptor from Known_Clients::lookup().
     * @param string     $client_id The id that was looked up.
     * @return bool
     */
    public static function accepts( ?array $client, string $client_id ): bool {
        return is_array( $client )
            && ( $client['client_id'] ?? null ) === $client_id
            && in_array( $client['kind'] ?? null, self::OAUTH_KINDS, true )
            && true === ( $client['oauth_endpoints'] ?? null )
            && true === ( $client['enabled'] ?? null );
    }

    /**
     * The presented redirect under the client kind's rule. The client lookup applies its
     * own rule too, and both must accept, so neither side's gap lets a redirect through.
     *
     * @param array  $client       Accepted descriptor.
     * @param string $redirect_uri Presented redirect URI.
     * @return bool
     */
    public function redirect_allowed( array $client, string $redirect_uri ): bool {
        if ( ! self::redirect_syntax_ok( $redirect_uri ) ) {
            return false;
        }
        $kind = $client['kind'] ?? '';
        if ( in_array( $kind, self::PLAIN_REDIRECT_KINDS, true ) && 1 === preg_match( '/[@\\\\%\s\x00-\x1f\x7f]/', $redirect_uri ) ) {
            return false;
        }
        if ( ! self::matches_kind_rule( $client, $redirect_uri ) ) {
            return false;
        }
        return $this->clients->redirect_allowed( $client, $redirect_uri );
    }

    /**
     * The approved scope for a request: every token known, write implying read, missing meaning
     * view only.
     *
     * @param string|null $scope Requested scope.
     * @return string|null Normalized scope, or null for `invalid_scope`.
     */
    public static function normalize_scope( ?string $scope ): ?string {
        if ( null === $scope ) {
            return OAuth_Store::SCOPE_READ;
        }
        $tokens = array_values( array_filter( explode( ' ', $scope ), 'strlen' ) );
        if ( empty( $tokens ) || array_diff( $tokens, OAuth_Store::SCOPES ) ) {
            return null;
        }
        // View-only consent must produce a grant with read access.
        if ( in_array( OAuth_Store::SCOPE_WRITE, $tokens, true ) || ! in_array( OAuth_Store::SCOPE_READ, $tokens, true ) ) {
            $tokens[] = OAuth_Store::SCOPE_READ;
        }
        return implode( ' ', array_values( array_intersect( OAuth_Store::SCOPES, $tokens ) ) );
    }

    /**
     * Whether every redirect the client can use goes to the user's own machine.
     *
     * @param array $client Descriptor.
     * @return bool
     */
    public static function loopback_only( array $client ): bool {
        $rule = $client['redirect_rule'] ?? '';
        if ( 'registered' === ( $client['kind'] ?? '' ) || 'gemini_pattern' === $rule ) {
            return true;
        }
        $uris = (array) ( $client['redirect_uris'] ?? array() );
        if ( empty( $uris ) ) {
            return false;
        }
        foreach ( $uris as $uri ) {
            if ( ! is_string( $uri ) || null === self::loopback_parts( $uri ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Absolute URI with a scheme and a host, at most 2048 bytes, no whitespace, control character
     * or fragment (OAuth 2.1 2.3). Checked on the raw string; the comparison stays raw too.
     *
     * @param string|null $uri Redirect URI.
     * @return bool
     */
    private static function redirect_syntax_ok( ?string $uri ): bool {
        if ( null === $uri || strlen( $uri ) > self::MAX_LENGTH || false !== strpos( $uri, '#' ) ) {
            return false;
        }
        if ( 1 === preg_match( '/[\x00-\x20\x7f]/', $uri ) ) {
            return false;
        }
        return 1 === preg_match( '#^[A-Za-z][A-Za-z0-9+.-]*://[^/?\#]+#', $uri );
    }

    /**
     * The presented resource equals the canonical MCP URL after lowercasing scheme and host and
     * dropping the default port; path and query compare exactly. The MCP spec says servers
     * should accept an uppercase scheme and host. The host may be a bracketed IPv6 literal,
     * because the canonical URL keeps the brackets of an IPv6 home.
     *
     * @param string $presented Presented resource.
     * @return bool
     */
    private function is_canonical_resource( string $presented ): bool {
        $canonical = $this->urls->mcp_url();
        if ( null === $canonical || 1 !== preg_match( '#^([A-Za-z][A-Za-z0-9+.-]*)://(\[[0-9A-Fa-f:.]+\]|[^/?\#:@\[\]]+)(?::([0-9]{1,5}))?([/?][^\#]*)?\z#', $presented, $match ) ) {
            return false;
        }
        $scheme = strtolower( $match[1] );
        $port   = $match[3] ?? '';
        if ( ( 'https' === $scheme && '443' === $port ) || ( 'http' === $scheme && '80' === $port ) ) {
            $port = '';
        }
        $normalized = $scheme . '://' . strtolower( $match[2] ) . ( '' === $port ? '' : ':' . $port ) . ( $match[4] ?? '' );
        return $normalized === $canonical;
    }

    /**
     * The redirect rule of the client's kind.
     *
     * @param array  $client Descriptor.
     * @param string $uri    Presented redirect URI.
     * @return bool
     */
    private static function matches_kind_rule( array $client, string $uri ): bool {
        $registered = array_values( array_filter( (array) ( $client['redirect_uris'] ?? array() ), 'is_string' ) );
        switch ( $client['kind'] ?? '' ) {
            case 'bundled':
                return self::bundled_match( $uri, $registered );
            case 'static':
                if ( 'cursor_exact' === ( $client['redirect_rule'] ?? '' ) ) {
                    return in_array( $uri, $registered, true );
                }
                if ( 'gemini_pattern' === ( $client['redirect_rule'] ?? '' ) ) {
                    return 1 === preg_match( '#^http://localhost:([0-9]{1,5})/oauth/callback\z#', $uri, $match ) && self::valid_port( $match[1] );
                }
                return false;
            case 'registered':
                $presented = self::registered_form( $uri );
                if ( null === $presented ) {
                    return false;
                }
                foreach ( $registered as $entry ) {
                    if ( self::registered_form( $entry ) === $presented ) {
                        return true;
                    }
                }
                return false;
            case 'webapp':
                return 0 === strpos( $uri, 'https://' ) && in_array( $uri, $registered, true );
        }
        return false;
    }

    /**
     * Bundled clients: https byte for byte; http only to a loopback host, ignoring the port of
     * both sides as RFC 8252 7.3 asks, with scheme, host, path and query exact. Claude Code's
     * document lists port-less loopback callbacks and calls back on a random port.
     *
     * @param string $uri        Presented redirect URI.
     * @param array  $registered The document's redirect URIs.
     * @return bool
     */
    private static function bundled_match( string $uri, array $registered ): bool {
        if ( 0 === strpos( $uri, 'https://' ) ) {
            return in_array( $uri, $registered, true );
        }
        $presented = self::loopback_parts( $uri );
        if ( null === $presented ) {
            return false;
        }
        foreach ( $registered as $entry ) {
            if ( self::loopback_parts( $entry ) === $presented ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Host and path-and-query of an http loopback URI, the port dropped. `localhost` and
     * `127.0.0.1` stay distinct: they can reach different listeners.
     *
     * @param string $uri URI.
     * @return array|null [ host, rest ], or null when it isn't an http loopback URI.
     */
    private static function loopback_parts( string $uri ): ?array {
        if ( 1 !== preg_match( '#^http://(127\.0\.0\.1|\[::1\]|localhost)(?::([0-9]{1,5}))?([/?][^\#]*)?\z#', $uri, $match ) ) {
            return null;
        }
        if ( isset( $match[2] ) && '' !== $match[2] && ! self::valid_port( $match[2] ) ) {
            return null;
        }
        return array( $match[1], $match[3] ?? '' );
    }

    /**
     * A registered app's redirect without its port, when it passes the registration loopback
     * grammar (no query, no fragment). Only the port is ignored (RFC 8252 7.3, 8.4).
     *
     * @param string $uri URI.
     * @return string|null
     */
    private static function registered_form( string $uri ): ?string {
        if ( 1 === preg_match( '/[@\\\\%\s\x00-\x1f\x7f]/', $uri ) ) {
            return null;
        }
        if ( 1 !== preg_match( '#^http://(127\.0\.0\.1|\[::1\]|localhost)(?::([0-9]{1,5}))?(/[A-Za-z0-9._~/-]*)?\z#', $uri, $match ) ) {
            return null;
        }
        if ( isset( $match[2] ) && '' !== $match[2] && ! self::valid_port( $match[2] ) ) {
            return null;
        }
        return 'http://' . $match[1] . ( $match[3] ?? '' );
    }

    /**
     * Port 1-65535.
     *
     * @param string $port Digits.
     * @return bool
     */
    private static function valid_port( string $port ): bool {
        return (int) $port >= 1 && (int) $port <= 65535;
    }
}
