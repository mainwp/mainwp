<?php
/**
 * Remote MCP OAuth token endpoint.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The token endpoint (`route_token`): the `authorization_code` and `refresh_token` grants. Every
 * state transition is the store's; this class parses the raw form body, authenticates the client
 * by kind, applies the rate limits and maps store outcomes to RFC 6749 section 5.2 responses with
 * fixed text.
 */
final class OAuth_Token_Endpoint implements Route_Handler {

    /**
     * Single-valued parameters the endpoint defines. `resource` is counted separately.
     *
     * @var string[]
     */
    const PARAMS = array( 'grant_type', 'code', 'redirect_uri', 'code_verifier', 'client_id', 'refresh_token', 'scope', 'client_secret' );

    /**
     * Client kinds the OAuth endpoints accept. The `token` kind is refused.
     *
     * @var string[]
     */
    const OAUTH_KINDS = array( 'bundled', 'static', 'registered', 'webapp' );

    /**
     * Fixed error text per code. Nothing from the request is ever echoed.
     *
     * @var array
     */
    const DESCRIPTIONS = array(
        'invalid_request'         => 'The token request is malformed, repeats a parameter, or is missing one.',
        'invalid_client'          => 'Client authentication failed.',
        'invalid_grant'           => 'The authorization code or refresh token is invalid, expired, revoked, or belongs to another client.',
        'unsupported_grant_type'  => 'Only the authorization_code and refresh_token grants are supported.',
        'invalid_scope'           => 'The requested scope is not allowed for this connection.',
        'invalid_target'          => 'The resource does not match this server.',
        'rate_limited'            => 'Too many token requests. Try again later.',
        'server_error'            => 'The server could not complete the token request.',
        'temporarily_unavailable' => 'The server is busy. Try again shortly.',
    );

    /**
     * Services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Rate limiter.
     *
     * @var OAuth_Rate_Limiter
     */
    private $limiter;

    /**
     * Constructor. The registry passes only the services.
     *
     * @param Remote_MCP_Services     $services Services.
     * @param OAuth_Rate_Limiter|null $limiter  Rate limiter; a real-clock one when null.
     */
    public function __construct( Remote_MCP_Services $services, ?OAuth_Rate_Limiter $limiter = null ) {
        $this->services = $services;
        $this->limiter  = $limiter ? $limiter : new OAuth_Rate_Limiter();
    }

    /**
     * Handle one token request.
     *
     * @param Remote_MCP_Request $request Request.
     * @return Remote_MCP_Response
     */
    public function handle( Remote_MCP_Request $request ): Remote_MCP_Response {
        // Before the body is parsed or anything is looked up. The front controller already
        // answers 404 in this state; this covers a handler reached any other way.
        if ( ! Remote_MCP_Settings::is_active() || ! is_ssl() ) {
            return $this->error( 'invalid_request' );
        }
        if ( 'POST' !== $request->method() ) {
            return new Remote_MCP_Response( 405, array( 'Allow' => 'POST' ) );
        }
        $store = $this->services->store();
        if ( null === $store ) {
            return $this->error( 'server_error' );
        }

        $context = array(
            'endpoint'  => 'token',
            'client_ip' => $this->client_ip( $request ),
        );
        $limited = $this->limiter->endpoint_hit( $context['client_ip'] );
        if ( null !== $limited ) {
            return $this->rate_limited( $limited, $context );
        }

        // Duplicates and resource counts are refused before any lookup or state change.
        $params = $request->body_params( self::PARAMS, array( 'resource' ) );
        if ( null !== $params['duplicate'] ) {
            return $this->error( 'invalid_request' );
        }
        if ( count( $params['multi']['resource'] ) > 1 ) {
            return $this->error( 'invalid_target' );
        }
        $values   = $params['values'];
        $resource = empty( $params['multi']['resource'] ) ? null : self::normalize_resource( $params['multi']['resource'][0] );

        $grant_type = $values['grant_type'];
        if ( null === $grant_type ) {
            return $this->error( 'invalid_request' );
        }
        if ( 'authorization_code' !== $grant_type && 'refresh_token' !== $grant_type ) {
            return $this->error( 'unsupported_grant_type' );
        }

        $client = $this->authenticate_client( $request, $values, $store );
        if ( $client instanceof Remote_MCP_Response ) {
            return $client;
        }
        $context['client_kind'] = $client['kind'];

        if ( 'authorization_code' === $grant_type ) {
            return $this->exchange_code( $store, $client, $values, $resource, $context );
        }
        return $this->refresh( $store, $client, $values, $resource, $context );
    }

    /**
     * Resolve and authenticate the client. Web apps authenticate with exactly one of
     * `client_secret_basic` and `client_secret_post`; every other kind uses `none` and is refused
     * if it presents a secret. PKCE stays mandatory for all of them (the store checks the
     * verifier).
     *
     * @param Remote_MCP_Request $request Request.
     * @param array              $values  Body parameters.
     * @param OAuth_Store        $store   Store.
     * @return array|Remote_MCP_Response `client_id` and `kind`, or the refusal.
     */
    private function authenticate_client( Remote_MCP_Request $request, array $values, OAuth_Store $store ) {
        $basic = $this->basic_credentials( $request );
        // A header that isn't well-formed Basic is still an attempt to authenticate, and no kind
        // accepts it.
        if ( false === $basic ) {
            return $this->error( 'invalid_client', true );
        }
        $client_id = $values['client_id'];
        if ( null !== $basic ) {
            if ( null !== $client_id && ! hash_equals( $basic['client_id'], $client_id ) ) {
                return $this->error( 'invalid_client', true );
            }
            $client_id = $basic['client_id'];
        }
        if ( null === $client_id ) {
            return $this->error( 'invalid_request' );
        }

        $client = $this->services->get( 'known_clients' )->lookup( $client_id );
        if ( null === $client ) {
            // An MCP SDK client drops its cached id and registers again only after invalid_client;
            // the removed registration granted nothing and its grants are already revoked.
            if ( 0 === strpos( $client_id, 'mwplc_' ) ) {
                return $this->error( 'invalid_client', null !== $basic );
            }
            return $this->error( 'invalid_grant' );
        }
        $kind = isset( $client['kind'] ) && is_string( $client['kind'] ) ? $client['kind'] : '';
        if ( empty( $client['oauth_endpoints'] ) || ! in_array( $kind, self::OAUTH_KINDS, true ) ) {
            return $this->error( 'invalid_client', null !== $basic );
        }
        if ( empty( $client['enabled'] ) ) {
            return $this->error( 'invalid_grant' );
        }

        $methods = array();
        if ( null !== $basic ) {
            $methods[] = 'client_secret_basic';
        }
        if ( null !== $values['client_secret'] ) {
            $methods[] = 'client_secret_post';
        }
        if ( 'webapp' === $kind ) {
            $allowed = isset( $client['token_endpoint_auth_methods'] ) && is_array( $client['token_endpoint_auth_methods'] ) ? $client['token_endpoint_auth_methods'] : array();
            if ( 1 !== count( $methods ) || ! in_array( $methods[0], $allowed, true ) ) {
                return $this->error( 'invalid_client', null !== $basic );
            }
            $secret = null !== $basic ? $basic['client_secret'] : (string) $values['client_secret'];
            if ( ! $store->verify_client_secret( $client_id, $secret ) ) {
                return $this->error( 'invalid_client', null !== $basic );
            }
        } elseif ( ! empty( $methods ) ) {
            return $this->error( 'invalid_client', null !== $basic );
        }

        return array(
            'client_id' => $client_id,
            'kind'      => $kind,
        );
    }

    /**
     * Credentials from an `Authorization` header (RFC 6749 2.3.1), taken from HTTP_AUTHORIZATION
     * or a REDIRECT_ copy of it, or from PHP_AUTH_USER where mod_php consumed the header.
     *
     * @param Remote_MCP_Request $request Request.
     * @return array|null|false `client_id` and `client_secret`; null when no credentials were sent;
     *                          false for a header that isn't well-formed Basic.
     */
    private function basic_credentials( Remote_MCP_Request $request ) {
        $header = null;
        foreach ( $request->server() as $name => $value ) {
            if ( is_string( $name ) && is_string( $value ) && '' !== $value && 1 === preg_match( '/^(REDIRECT_)*HTTP_AUTHORIZATION$/D', $name ) ) {
                $header = $value;
                if ( 'HTTP_AUTHORIZATION' === $name ) {
                    break;
                }
            }
        }
        if ( null === $header ) {
            $user = $request->server_var( 'PHP_AUTH_USER' );
            if ( null === $user ) {
                return null;
            }
            return array(
                'client_id'     => urldecode( $user ),
                'client_secret' => urldecode( (string) $request->server_var( 'PHP_AUTH_PW' ) ),
            );
        }
        if ( 1 !== preg_match( '/^Basic[ ]+([A-Za-z0-9+\/]+={0,2})[ ]*$/iD', $header, $match ) ) {
            return false;
        }
        $decoded = base64_decode( $match[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- HTTP Basic credentials (RFC 7617).
        if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
            return false;
        }
        list( $id, $secret ) = explode( ':', $decoded, 2 );
        $id                  = urldecode( $id );
        if ( '' === $id ) {
            return false;
        }
        return array(
            'client_id'     => $id,
            'client_secret' => urldecode( $secret ),
        );
    }

    /**
     * The `authorization_code` grant. The store checks the code against client_id, verifier,
     * redirect_uri and resource, rechecks the user and publishes the grant.
     *
     * @param OAuth_Store $store    Store.
     * @param array       $client   Authenticated client.
     * @param array       $values   Body parameters.
     * @param string|null $requested_resource Normalized resource, or null when omitted.
     * @param array       $context  Audit fields.
     * @return Remote_MCP_Response
     */
    private function exchange_code( OAuth_Store $store, array $client, array $values, ?string $requested_resource, array $context ): Remote_MCP_Response {
        if ( null === $values['code'] || null === $values['code_verifier'] ) {
            return $this->error( 'invalid_request' );
        }
        $result = $store->redeem_code( $values['code'], $client['client_id'], $values['code_verifier'], $values['redirect_uri'], $requested_resource );
        if ( OAuth_Store::OK !== $result['outcome'] ) {
            if ( 'code_replay' === $result['event'] ) {
                $grant_id = (int) $result['grant_id'];
                $host     = wp_parse_url( (string) $result['client_id'], PHP_URL_HOST );
                $store->record_grant_failure( $grant_id, 'code_replay' );
                Remote_MCP_Events::audit(
                    'code_replay',
                    $context + array(
                        'grant_id'    => $grant_id,
                        'user_id'     => (int) $result['user_id'],
                        'client_host' => is_string( $host ) ? $host : '',
                        'outcome'     => (string) $result['outcome'],
                    )
                );
            }
            return $this->store_error( (string) $result['outcome'] );
        }

        $grant    = $store->get_grant( (int) $result['grant_id'] );
        $user_id  = null === $grant ? 0 : (int) $grant['user_id'];
        $kind     = null === $grant ? $client['kind'] : (string) $grant['client_kind'];
        $grant_id = (int) $result['grant_id'];
        do_action( Remote_MCP_Events::GRANT_ISSUED, $grant_id, $user_id, $client['client_id'], $kind, 'code_exchange' );
        Remote_MCP_Events::audit( 'token_issued', $context + $this->grant_fields( $grant, $grant_id ) + array( 'outcome' => OAuth_Store::OK ) );
        $this->touch( $store, $client );
        return $this->issued( $result );
    }

    /**
     * The `refresh_token` grant. Rotation, the grace window and replay handling are the store's.
     *
     * @param OAuth_Store $store    Store.
     * @param array       $client   Authenticated client.
     * @param array       $values   Body parameters.
     * @param string|null $requested_resource Normalized resource, or null when omitted.
     * @param array       $context  Audit fields.
     * @return Remote_MCP_Response
     */
    private function refresh( OAuth_Store $store, array $client, array $values, ?string $requested_resource, array $context ): Remote_MCP_Response {
        $token = $values['refresh_token'];
        if ( null === $token ) {
            return $this->error( 'invalid_request' );
        }
        // A read only, to name the grant for the per-grant limit and the panel's failure column.
        // refresh() makes every decision again from its own lookup.
        $known = $store->lookup_token( $token, OAuth_Token_Hasher::TYPE_REFRESH );
        // Checked before the per-grant limiter and the failure write, so another client holding
        // a stolen refresh token can't spend the victim grant's allowance or mark it failed.
        if ( null !== $known && ! hash_equals( (string) $known['client_id'], $client['client_id'] ) ) {
            return $this->error( 'invalid_grant' );
        }
        $grant_id = null === $known ? 0 : (int) $known['id'];
        if ( null !== $known ) {
            $context += $this->grant_fields( $known, $grant_id );
            $limited  = $this->limiter->refresh_hit( $grant_id );
            if ( null !== $limited ) {
                return $this->rate_limited( $limited, $context );
            }
            // Zero resources means the grant's own; one must equal it (RFC 8707 2.2).
            if ( null !== $requested_resource && $requested_resource !== (string) $known['resource'] ) {
                $store->record_grant_failure( $grant_id, 'invalid_target' );
                return $this->error( 'invalid_target' );
            }
        }

        $result = $store->refresh( $token, $client['client_id'], $values['scope'] );
        if ( OAuth_Store::OK !== $result['outcome'] ) {
            if ( null !== $known ) {
                $store->record_grant_failure( $grant_id, is_string( $result['event'] ) ? $result['event'] : (string) $result['outcome'] );
            }
            if ( 'refresh_replay' === $result['event'] || 'refresh_duplicate_late' === $result['event'] ) {
                Remote_MCP_Events::audit( $result['event'], $context + array( 'outcome' => (string) $result['outcome'] ) );
            }
            return $this->store_error( (string) $result['outcome'] );
        }

        Remote_MCP_Events::audit( 'token_refreshed', $context + array( 'outcome' => OAuth_Store::OK ) );
        $this->touch( $store, $client );
        return $this->issued( $result );
    }

    /**
     * Logged grant fields: ids and the display host, never a credential.
     *
     * @param array|null $grant    Grant or lookup row.
     * @param int        $grant_id Grant id.
     * @return array
     */
    private function grant_fields( ?array $grant, int $grant_id ): array {
        $fields = array( 'grant_id' => $grant_id );
        if ( null !== $grant ) {
            $fields['user_id']     = (int) $grant['user_id'];
            $fields['client_host'] = (string) $grant['client_host'];
        }
        return $fields;
    }

    /**
     * Record use on a stored client row, for the panel.
     *
     * @param OAuth_Store $store  Store.
     * @param array       $client Authenticated client.
     */
    private function touch( OAuth_Store $store, array $client ): void {
        if ( 'registered' === $client['kind'] || 'webapp' === $client['kind'] ) {
            $store->touch_client( $client['client_id'] );
        }
    }

    /**
     * Successful token response.
     *
     * @param array $result Store result.
     * @return Remote_MCP_Response
     */
    private function issued( array $result ): Remote_MCP_Response {
        $body = array(
            'access_token' => (string) $result['access_token'],
            'token_type'   => 'Bearer',
            'expires_in'   => (int) $result['expires_in'],
            'scope'        => (string) $result['scope'],
        );
        if ( is_string( $result['refresh_token'] ) ) {
            $body['refresh_token'] = $result['refresh_token'];
        }
        return Remote_MCP_Response::json( 200, $body, array( 'Pragma' => 'no-cache' ) );
    }

    /**
     * Response for a store outcome other than success.
     *
     * @param string $outcome Store outcome.
     * @return Remote_MCP_Response
     */
    private function store_error( string $outcome ): Remote_MCP_Response {
        switch ( $outcome ) {
            case OAuth_Store::INVALID_GRANT:
            case OAuth_Store::INVALID_TARGET:
            case OAuth_Store::INVALID_SCOPE:
            case OAuth_Store::INVALID_REQUEST:
                return $this->error( $outcome );
            case OAuth_Store::RETRY:
                // Lost the version race three times.
                return $this->error( 'temporarily_unavailable', false, array( 'Retry-After' => '1' ) );
            default:
                return $this->error( 'server_error' );
        }
    }

    /**
     * 429 with Retry-After. Logged once per window, so a flood can't fill the log.
     *
     * @param array $limited Limiter refusal.
     * @param array $context Audit fields.
     * @return Remote_MCP_Response
     */
    private function rate_limited( array $limited, array $context ): Remote_MCP_Response {
        if ( $limited['first'] ) {
            Remote_MCP_Events::audit( 'rate_limited', $context + array( 'reason' => $limited['limit'] ) );
        }
        return $this->error( 'rate_limited', false, array( 'Retry-After' => (string) $limited['retry_after'] ) );
    }

    /**
     * Error response: RFC 6749 5.2 JSON with fixed text.
     *
     * @param string $error   Error code from DESCRIPTIONS.
     * @param bool   $basic   Whether the client tried the Authorization header, which gets a Basic challenge.
     * @param array  $headers Extra headers.
     * @return Remote_MCP_Response
     */
    private function error( string $error, bool $basic = false, array $headers = array() ): Remote_MCP_Response {
        $statuses          = array(
            'invalid_client'          => 401,
            'rate_limited'            => 429,
            'server_error'            => 500,
            'temporarily_unavailable' => 503,
        );
        $headers['Pragma'] = 'no-cache';
        if ( $basic ) {
            // RFC 6749 5.2: a failed Authorization-header attempt gets a challenge for that scheme.
            $headers['WWW-Authenticate'] = 'Basic realm="mainwp-oauth"';
        }
        // RFC 6749 lists no code for a rate limit; invalid_request is one no client treats as a dead connection.
        $code = 'rate_limited' === $error ? 'invalid_request' : $error;
        return Remote_MCP_Response::json(
            $statuses[ $error ] ?? 400,
            array(
                'error'             => $code,
                'error_description' => self::DESCRIPTIONS[ $error ],
            ),
            $headers
        );
    }

    /**
     * Client IP for rate limits and logs: REMOTE_ADDR only, unless a site behind a known proxy maps
     * it. Proxy headers are never read here, since anyone can send them.
     *
     * @param Remote_MCP_Request $request Request.
     * @return string
     */
    private function client_ip( Remote_MCP_Request $request ): string {
        $remote = (string) $request->server_var( 'REMOTE_ADDR' );
        /**
         * Map the client address for a site behind a trusted reverse proxy.
         *
         * @param string $remote REMOTE_ADDR.
         */
        $ip = apply_filters( 'mainwp_remote_mcp_client_ip', $remote );
        return substr( is_string( $ip ) ? $ip : $remote, 0, 45 );
    }

    /**
     * The resource comparison form: scheme and host lowercased and the default port dropped, the
     * path and query left exactly as sent.
     *
     * @param string $uri Presented resource.
     * @return string
     */
    private static function normalize_resource( string $uri ): string {
        if ( 1 !== preg_match( '#^([A-Za-z][A-Za-z0-9+.-]*)://([^/?\#]*)(.*)$#sD', $uri, $parts ) ) {
            return $uri;
        }
        $scheme    = strtolower( $parts[1] );
        $authority = strtolower( $parts[2] );
        $default   = array(
            'https' => ':443',
            'http'  => ':80',
        );
        if ( isset( $default[ $scheme ] ) && substr( $authority, -strlen( $default[ $scheme ] ) ) === $default[ $scheme ] ) {
            $authority = substr( $authority, 0, -strlen( $default[ $scheme ] ) );
        }
        return $scheme . '://' . $authority . $parts[3];
    }
}
