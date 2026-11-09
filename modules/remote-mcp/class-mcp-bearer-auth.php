<?php
/**
 * Remote MCP bearer authentication.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one place a bearer token is checked: access tokens and connection tokens, on
 * the MCP route only. Nothing in the module reads a bearer token on determine_current_user,
 * rest_authentication_errors or any other hook, so a token never logs anyone in anywhere else.
 */
class MCP_Bearer_Auth {

    /**
     * Prefixes the MCP route accepts, each with the token type its row must have.
     *
     * @var array
     */
    const PREFIXES = array(
        'mwpat_' => OAuth_Token_Hasher::TYPE_ACCESS,
        'mwpct_' => OAuth_Token_Hasher::TYPE_CONNECT,
    );

    /**
     * Fixed text for an expired connection token.
     *
     * @var string
     */
    const CONNECT_EXPIRED = 'This MainWP connection token has expired. Create a new one in AI > MainWP MCP > Connect an app.';

    /**
     * Services.
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
     * Authenticate one MCP request.
     *
     * @param Remote_MCP_Request $request Request.
     * @return array `ok` true with `check` (the store's success result), or `ok` false with `response`.
     */
    public function authenticate( Remote_MCP_Request $request ): array {
        // Before header recovery, hashing or any lookup.
        if ( ! Remote_MCP_Settings::is_active() ) {
            return $this->refuse( $this->challenge( 'invalid_token' ) );
        }
        // Defense in depth: something defined it before us, so Team Control site scoping would be
        // off for every ability this request runs.
        if ( $this->rest_api_doing() ) {
            return $this->refuse( Remote_MCP_Response::jsonrpc_error( 500, MCP_Protocol::INTERNAL_ERROR, MCP_Protocol::MESSAGES[ MCP_Protocol::INTERNAL_ERROR ] ) );
        }
        // The MCP route never uses the cookie, Application Password or API-key identity
        // WordPress may have resolved for this request.
        wp_set_current_user( 0 );

        if ( $this->token_outside_header( $request ) ) {
            return $this->refuse( $this->bad_request() );
        }

        $recovered = $this->recover( $request->server() );
        if ( 'missing' === $recovered['state'] ) {
            Remote_MCP_Settings::bump_counter( 'auth_header_missing' );
            return $this->refuse( $this->challenge( null ) );
        }
        Remote_MCP_Settings::note_header_received();
        if ( 'ok' !== $recovered['state'] ) {
            Remote_MCP_Settings::bump_counter( 'auth_token_rejected' );
            return $this->refuse( $this->challenge( 'invalid_token' ) );
        }

        $token = $recovered['token'];
        $store = $this->services->store();
        if ( null === $store ) {
            Remote_MCP_Settings::bump_counter( 'auth_token_rejected' );
            return $this->refuse( $this->challenge( 'invalid_token' ) );
        }
        $type   = self::PREFIXES[ substr( $token, 0, 6 ) ];
        $result = OAuth_Token_Hasher::TYPE_CONNECT === $type ? $store->check_connect_token( $token ) : $store->check_access_token( $token );
        if ( OAuth_Store::RETRY === ( $result['outcome'] ?? null ) ) {
            return $this->refuse( Remote_MCP_Response::jsonrpc_error( 503, MCP_Protocol::INTERNAL_ERROR, 'Try again' ) );
        }
        if ( OAuth_Store::OK !== ( $result['outcome'] ?? null ) ) {
            Remote_MCP_Settings::bump_counter( 'auth_token_rejected' );
            $description = OAuth_Token_Hasher::TYPE_CONNECT === $type && $this->connect_token_expired( $store, $token ) ? self::CONNECT_EXPIRED : null;
            return $this->refuse( $this->challenge( 'invalid_token', $description ) );
        }

        $reason = $this->grant_refusal( $result );
        if ( null !== $reason ) {
            $store->record_grant_failure( (int) $result['grant_id'], $reason );
            Remote_MCP_Settings::bump_counter( 'auth_token_rejected' );
            return $this->refuse( $this->challenge( 'invalid_token' ) );
        }

        $store->record_grant_use( (int) $result['grant_id'], $this->client_ip( $request->server() ) );
        return array(
            'ok'    => true,
            'check' => $result,
        );
    }

    /**
     * Find the bearer credential: HTTP_AUTHORIZATION, then any REDIRECT_ variant, then
     * getallheaders(), the first present wins. Exactly one Bearer credential, the scheme compared
     * case-insensitively, and the charset and prefix checked before anything is looked up.
     *
     * @param array $server Server variables.
     * @return array `state` (`ok`, `missing` or `invalid`) and `token` (string or null).
     */
    public function recover( array $server ): array {
        $value = null;
        if ( isset( $server['HTTP_AUTHORIZATION'] ) && is_string( $server['HTTP_AUTHORIZATION'] ) && '' !== trim( $server['HTTP_AUTHORIZATION'] ) ) {
            $value = $server['HTTP_AUTHORIZATION'];
        }
        if ( null === $value ) {
            foreach ( $server as $key => $candidate ) {
                if ( is_string( $key ) && 1 === preg_match( '/^(REDIRECT_)+HTTP_AUTHORIZATION$/', $key ) && is_string( $candidate ) && '' !== trim( $candidate ) ) {
                    $value = $candidate;
                    break;
                }
            }
        }
        if ( null === $value ) {
            foreach ( $this->all_headers() as $name => $candidate ) {
                if ( is_string( $name ) && 0 === strcasecmp( $name, 'Authorization' ) && is_string( $candidate ) && '' !== trim( $candidate ) ) {
                    $value = $candidate;
                    break;
                }
            }
        }
        if ( null === $value ) {
            return array(
                'state' => 'missing',
                'token' => null,
            );
        }
        if ( 1 !== preg_match( '/^(?i:Bearer)[ ]+((?:mwpat|mwpct)_[A-Za-z0-9_-]{43})$/', trim( $value ), $match ) ) {
            return array(
                'state' => 'invalid',
                'token' => null,
            );
        }
        return array(
            'state' => 'ok',
            'token' => $match[1],
        );
    }

    /**
     * The 401 challenge. It names both scopes so a client asks for both on first connect.
     *
     * @param string|null $error       `invalid_token` when a token was presented, else null.
     * @param string|null $description Fixed error_description, or null.
     * @return Remote_MCP_Response
     */
    public function challenge( ?string $error, ?string $description = null ): Remote_MCP_Response {
        $value = 'Bearer resource_metadata="' . $this->prm_url() . '", scope="' . OAuth_Store::SCOPE_READ . ' ' . OAuth_Store::SCOPE_WRITE . '"';
        if ( null !== $error ) {
            $value .= ', error="' . $error . '"';
        }
        if ( null !== $description ) {
            $value .= ', error_description="' . $description . '"';
        }
        return Remote_MCP_Response::json(
            401,
            array(
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => array(
                    'code'    => -32001,
                    'message' => 'Authentication required',
                ),
            ),
            array( 'WWW-Authenticate' => $value )
        );
    }

    /**
     * A 403 insufficient_scope challenge.
     *
     * @param string          $scope       Scope to ask for.
     * @param string          $description Fixed error_description.
     * @param int|string|null $id          Request id.
     * @return Remote_MCP_Response
     */
    public function insufficient_scope( string $scope, string $description, $id = null ): Remote_MCP_Response {
        return Remote_MCP_Response::json(
            403,
            array(
                'jsonrpc' => '2.0',
                'id'      => $id,
                'error'   => array(
                    'code'    => MCP_Protocol::INVALID_REQUEST,
                    'message' => 'Insufficient scope',
                ),
            ),
            array( 'WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="' . $scope . '", resource_metadata="' . $this->prm_url() . '", error_description="' . $description . '"' )
        );
    }

    /**
     * PRM URL for challenges, from the stored home.
     *
     * @return string
     */
    public function prm_url(): string {
        return (string) $this->services->urls()->prm_url();
    }

    /**
     * Client address: REMOTE_ADDR, mapped only through the one filter a site behind a
     * known proxy can use. Forwarded headers are never read here.
     *
     * @param array $server Server variables.
     * @return string
     */
    public function client_ip( array $server ): string {
        $ip = isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '';
        $ip = apply_filters( 'mainwp_remote_mcp_client_ip', $ip );
        return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }

    /**
     * Whether MAINWP_REST_API_DOING is defined. A test seam: defining the constant would last for
     * the rest of the process.
     *
     * @return bool
     */
    protected function rest_api_doing(): bool {
        return defined( 'MAINWP_REST_API_DOING' );
    }

    /**
     * Request headers from the SAPI, for servers that keep Authorization out of $_SERVER.
     *
     * @return array
     */
    protected function all_headers(): array {
        if ( ! function_exists( 'getallheaders' ) ) {
            return array();
        }
        $headers = getallheaders();
        return is_array( $headers ) ? $headers : array();
    }

    /**
     * Why a checked token's grant can't be used now, or null.
     *
     * @param array $result Store success result.
     * @return string|null Reason code for record_grant_failure().
     */
    private function grant_refusal( array $result ): ?string {
        $user = get_userdata( (int) $result['user_id'] );
        if ( ! $user ) {
            return 'user_missing';
        }
        if ( ! user_can( $user, 'manage_options' ) ) {
            return 'cap_lost';
        }
        if ( (string) $result['resource'] !== (string) $this->services->urls()->mcp_url() ) {
            return 'wrong_resource';
        }
        // A deleted app or a switched-off client ends its grants here. An https client the plugin
        // stops bundling keeps working as a fetched client while registration is on, and ends
        // here once registration is off. The kind must match too, so a connection-token grant
        // can't borrow an OAuth client's entry or the reverse.
        $client = $this->services->get( 'known_clients' )->lookup( (string) $result['client_id'] );
        if ( ! is_array( $client ) || ( $client['kind'] ?? null ) !== $result['client_kind'] ) {
            return 'unknown_client';
        }
        return null;
    }

    /**
     * Whether a refused connection token is one that ran out, for the expiry text. Only the
     * refusal message depends on this; the token was already refused by check_connect_token().
     *
     * @param OAuth_Store $store Store.
     * @param string      $token Token.
     * @return bool
     */
    private function connect_token_expired( OAuth_Store $store, string $token ): bool {
        $row = $store->lookup_token( $token, OAuth_Token_Hasher::TYPE_CONNECT );
        return is_array( $row ) && 'live' === ( $row['status'] ?? null );
    }

    /**
     * Whether a token arrived in the query string or a form body (RFC 6750 2.2, 2.3). Such a
     * request is refused and nothing is looked up, even with a valid header beside it.
     *
     * @param Remote_MCP_Request $request Request.
     * @return bool
     */
    private function token_outside_header( Remote_MCP_Request $request ): bool {
        if ( in_array( 'access_token', Remote_MCP_Params::extract( $request->raw_query(), array( 'access_token' ) )['names'], true ) ) {
            return true;
        }
        $type = (string) $request->server_var( 'CONTENT_TYPE' );
        $body = ltrim( $request->body() );
        $form = false !== stripos( $type, 'application/x-www-form-urlencoded' ) || ( '' !== $body && '{' !== $body[0] && '[' !== $body[0] );
        return $form && in_array( 'access_token', Remote_MCP_Params::extract( $request->body(), array( 'access_token' ) )['names'], true );
    }

    /**
     * 400 for a token sent outside the header.
     *
     * @return Remote_MCP_Response
     */
    private function bad_request(): Remote_MCP_Response {
        return Remote_MCP_Response::json(
            400,
            array(
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => array(
                    'code'    => MCP_Protocol::INVALID_REQUEST,
                    'message' => MCP_Protocol::MESSAGES[ MCP_Protocol::INVALID_REQUEST ],
                ),
            ),
            array( 'WWW-Authenticate' => 'Bearer error="invalid_request", error_description="Send the token in the Authorization header only"' )
        );
    }

    /**
     * Refusal result.
     *
     * @param Remote_MCP_Response $response Response.
     * @return array
     */
    private function refuse( Remote_MCP_Response $response ): array {
        return array(
            'ok'       => false,
            'response' => $response,
        );
    }
}
