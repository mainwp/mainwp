<?php
/**
 * Remote MCP client metadata document checks.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The checks every bundled client document passes: in tests over every bundled file,
 * at load, and over a refreshed document before it can ship. One bad member fails the whole
 * document, because a partial accept would hide that the vendor's metadata changed.
 */
final class OAuth_Client_Document {

    const MAX_REDIRECTS  = 20;
    const MAX_URI_BYTES  = 2048;
    const MAX_NAME_BYTES = 200;

    /**
     * Auth methods that mean the client holds a shared secret (CIMD draft-02 section 4).
     *
     * @var array
     */
    const SYMMETRIC_METHODS = array( 'client_secret_basic', 'client_secret_post', 'client_secret_jwt' );

    /**
     * What a bundled client may use at the token endpoint.
     *
     * @var array
     */
    const SUPPORTED_METHODS = array( 'none' );

    /**
     * Validate a raw document against its registry key.
     *
     * @param string $raw       Document bytes.
     * @param string $client_id Registry key the document must name.
     * @return array `ok`, `reason` (null or a fixed code), `client_name`, `redirect_uris`,
     *               `auth_method` (`none`) and `auth_source` (`plural` or `singular`).
     */
    public static function validate( string $raw, string $client_id ): array {
        $doc = json_decode( $raw );
        if ( ! $doc instanceof \stdClass ) {
            return self::fail( 'not_object' );
        }
        // Associative decoding would turn {"0": ...} into a list and erase its JSON type.
        foreach ( array(
            'redirect_uris'                         => 'redirect_uris_invalid',
            'grant_types'                           => 'grant_types_invalid',
            'response_types'                        => 'response_types_invalid',
            'token_endpoint_auth_methods_supported' => 'auth_method_unsupported',
        ) as $member => $reason ) {
            if ( property_exists( $doc, $member ) && ! is_array( $doc->$member ) ) {
                return self::fail( $reason );
            }
        }
        $doc = (array) $doc;

        if ( ! isset( $doc['client_id'] ) || ! is_string( $doc['client_id'] ) || $doc['client_id'] !== $client_id ) {
            return self::fail( 'client_id_mismatch' );
        }

        $uris = $doc['redirect_uris'] ?? null;
        if ( ! is_array( $uris ) || empty( $uris ) || ! array_is_list( $uris ) || count( $uris ) > self::MAX_REDIRECTS ) {
            return self::fail( 'redirect_uris_invalid' );
        }
        foreach ( $uris as $uri ) {
            if ( ! is_string( $uri ) || ! self::redirect_syntax_ok( $uri ) ) {
                return self::fail( 'redirect_uris_invalid' );
            }
        }

        if ( array_key_exists( 'grant_types', $doc ) && ( ! is_array( $doc['grant_types'] ) || ! in_array( 'authorization_code', $doc['grant_types'], true ) ) ) {
            return self::fail( 'grant_types_invalid' );
        }
        if ( array_key_exists( 'response_types', $doc ) && ( ! is_array( $doc['response_types'] ) || ! in_array( 'code', $doc['response_types'], true ) ) ) {
            return self::fail( 'response_types_invalid' );
        }

        $singular = $doc['token_endpoint_auth_method'] ?? null;
        $plural   = $doc['token_endpoint_auth_methods_supported'] ?? null;
        $offered  = array_merge( is_string( $singular ) ? array( $singular ) : array(), is_array( $plural ) ? $plural : array() );
        if ( array_key_exists( 'client_secret', $doc ) || array_key_exists( 'jwks', $doc ) || array_intersect( self::SYMMETRIC_METHODS, array_filter( $offered, 'is_string' ) ) ) {
            return self::fail( 'secret_material' );
        }

        // ChatGPT names private_key_jwt in the singular field and lists none in the plural one, and
        // its token requests carry no assertion, so the plural list wins.
        if ( array_key_exists( 'token_endpoint_auth_methods_supported', $doc ) ) {
            if ( ! is_array( $plural ) || ! array_is_list( $plural ) || count( array_filter( $plural, 'is_string' ) ) !== count( $plural ) || ! array_intersect( self::SUPPORTED_METHODS, $plural ) ) {
                return self::fail( 'auth_method_unsupported' );
            }
            $source = 'plural';
        } elseif ( 'none' === $singular ) {
            $source = 'singular';
        } else {
            return self::fail( 'auth_method_unsupported' );
        }

        $name = $doc['client_name'] ?? null;
        if ( ! is_string( $name ) || '' === $name || strlen( $name ) > self::MAX_NAME_BYTES ) {
            return self::fail( 'client_name_invalid' );
        }

        return array(
            'ok'            => true,
            'reason'        => null,
            'client_name'   => $name,
            'redirect_uris' => $uris,
            'auth_method'   => 'none',
            'auth_source'   => $source,
        );
    }

    /**
     * The request-side syntax rule for a redirect URI, applied to the raw string:
     * absolute with a scheme and a host, no fragment, no whitespace or control character, and
     * `https` or loopback `http`.
     *
     * @param string $uri Redirect URI.
     * @return bool
     */
    public static function redirect_syntax_ok( string $uri ): bool {
        if ( '' === $uri || strlen( $uri ) > self::MAX_URI_BYTES || false !== strpos( $uri, '#' ) || preg_match( '/[\x00-\x20\x7f]/', $uri ) ) {
            return false;
        }
        if ( 0 === strpos( $uri, 'https://' ) ) {
            return self::https_redirect_syntax_ok( $uri );
        }
        return 1 === preg_match( '#^http://(?:127\.0\.0\.1|\[::1\]|localhost)(?::[0-9]{1,5})?(?:[/?].*)?\z#s', $uri );
    }

    /**
     * Keep HTTPS validation shared so a document and an admin-added app accept the same hosts.
     * The raw URI is retained for byte-exact redirect comparison.
     *
     * @param string $uri Redirect URI.
     * @return bool
     */
    public static function https_redirect_syntax_ok( string $uri ): bool {
        if ( '' === $uri || strlen( $uri ) > self::MAX_URI_BYTES || preg_match( '/[@\\\\%?#]|[^\x21-\x7e]/', $uri ) ) {
            return false;
        }
        if ( ! preg_match( '#^https://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::([0-9]+))?(?:/[^?\#]*)?\z#', $uri, $parts ) ) {
            return false;
        }
        if ( isset( $parts[2] ) && ( (int) $parts[2] < 1 || (int) $parts[2] > 65535 ) ) {
            return false;
        }
        $host = $parts[1];
        if ( '[' === $host[0] ) {
            return false !== filter_var( substr( $host, 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
        }
        return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 )
            || false !== filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );
    }

    /**
     * Failure result.
     *
     * @param string $reason Fixed reason code.
     * @return array
     */
    private static function fail( string $reason ): array {
        return array(
            'ok'            => false,
            'reason'        => $reason,
            'client_name'   => null,
            'redirect_uris' => array(),
            'auth_method'   => null,
            'auth_source'   => null,
        );
    }
}
