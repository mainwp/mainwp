<?php
/**
 * Remote MCP discovery documents.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The authorization server metadata and protected resource metadata, built only from the
 * stored home. Shared by the well-known, OpenID and PRM routes.
 */
final class OAuth_Metadata {

    /**
     * Set by no_page_cache() for the current request.
     *
     * @var bool
     */
    private static $page_cache_disabled = false;

    /**
     * Token endpoint client authentication methods. Web apps authenticate with a secret; which
     * method a client may use is enforced by its kind at the token endpoint.
     *
     * @var array
     */
    const TOKEN_AUTH_METHODS = array( 'none', 'client_secret_basic', 'client_secret_post' );

    /**
     * Scopes the protected resource names. `offline_access` is an authorization server scope only,
     * so it never appears in PRM or in a challenge.
     *
     * @var array
     */
    const RESOURCE_SCOPES = array( OAuth_Store::SCOPE_READ, OAuth_Store::SCOPE_WRITE );

    /**
     * RFC 8414 document, also served at the OpenID path without ID-token fields, since no ID
     * tokens are issued.
     *
     * @param Remote_MCP_Urls $urls URLs.
     * @return array|null Null on a home the module can't serve.
     */
    public static function authorization_server( Remote_MCP_Urls $urls ): ?array {
        if ( ! $urls->is_secure() ) {
            return null;
        }
        $document = array(
            'issuer'                 => $urls->issuer(),
            'authorization_endpoint' => $urls->authorization_endpoint(),
            'token_endpoint'         => $urls->token_endpoint(),
        );
        if ( Remote_MCP_Settings::registration_enabled() ) {
            $document['registration_endpoint'] = $urls->registration_endpoint();
        }
        return $document + array(
            'response_types_supported'              => array( 'code' ),
            'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
            'code_challenge_methods_supported'      => array( 'S256' ),
            'token_endpoint_auth_methods_supported' => self::TOKEN_AUTH_METHODS,
            // Clients present their document URL as client_id. Bundled copies stay local;
            // other documents may be fetched at authorize when registration is enabled.
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
            'scopes_supported'                      => OAuth_Store::SCOPES,
        );
    }

    /**
     * RFC 9728 document. One authorization server only: hosted Claude reads the first entry.
     *
     * @param Remote_MCP_Urls $urls URLs.
     * @return array|null Null on a home the module can't serve.
     */
    public static function protected_resource( Remote_MCP_Urls $urls ): ?array {
        if ( ! $urls->is_secure() ) {
            return null;
        }
        return array(
            'resource'                 => $urls->mcp_url(),
            'authorization_servers'    => array( $urls->issuer() ),
            'scopes_supported'         => self::RESOURCE_SCOPES,
            'bearer_methods_supported' => array( 'header' ),
        );
    }

    /**
     * Response for a document, or a 404 when there is none.
     *
     * @param array|null $document Document.
     * @return Remote_MCP_Response
     */
    public static function response( ?array $document ): Remote_MCP_Response {
        self::no_page_cache();
        if ( null === $document ) {
            return new Remote_MCP_Response( 404, array( 'Content-Type' => 'text/plain; charset=utf-8' ), 'Not found' );
        }
        return Remote_MCP_Response::json( 200, $document );
    }

    /**
     * Page caches read the constant, not headers, and a cached consent page or metadata
     * document for another home would be served to the wrong client.
     */
    public static function no_page_cache(): void {
        self::$page_cache_disabled = true;
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
    }

    /**
     * Whether this request asked page caches to skip it.
     *
     * The constant can't be undefined once set, so this flag is what shows that a given handler
     * still makes the call.
     *
     * @return bool
     */
    public static function page_cache_disabled(): bool {
        return self::$page_cache_disabled;
    }
}
