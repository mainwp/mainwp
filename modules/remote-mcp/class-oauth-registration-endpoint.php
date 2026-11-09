<?php
/**
 * Remote MCP registration endpoint.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registration for apps on the user's computer (RFC 7591). Only loopback redirects are
 * accepted, so a code can only reach the user's own machine, and a registration grants nothing
 * until the signed-in user approves it on the consent screen. Nothing it is given is fetched.
 */
class OAuth_Registration_Endpoint implements Route_Handler {

    const MAX_BODY_BYTES = 8192;
    const MAX_REDIRECTS  = 5;

    /**
     * Live registrations never consented, site-wide.
     *
     * @var int
     */
    const UNCONSENTED_CAP = 100;

    /**
     * Age an unconsented registration must reach before a full cap may evict it, so a flood can
     * push out only setups abandoned for an hour or more.
     *
     * @var int
     */
    const EVICT_MIN_AGE = HOUR_IN_SECONDS;

    const GRANT_TYPES    = array( 'authorization_code', 'refresh_token' );
    const RESPONSE_TYPES = array( 'code' );

    /**
     * Registry.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services Registry.
     */
    public function __construct( Remote_MCP_Services $services ) {
        $this->services = $services;
    }

    /**
     * Handle a registration request.
     *
     * @param Remote_MCP_Request $request Request.
     * @return Remote_MCP_Response
     */
    public function handle( Remote_MCP_Request $request ): Remote_MCP_Response {
        // The front controller already answers 404 under the kill switch; this keeps the handler
        // closed if it is ever reached another way.
        if ( ! Remote_MCP_Settings::is_active() || ! Remote_MCP_Settings::registration_enabled() ) {
            return new Remote_MCP_Response( 404, array( 'Content-Type' => 'text/plain; charset=utf-8' ), 'Not found' );
        }
        if ( 'POST' !== $request->method() ) {
            return new Remote_MCP_Response( 405, array( 'Allow' => 'POST' ) );
        }
        $store = $this->services->store();
        if ( null === $store ) {
            return $this->error( 500, 'server_error' );
        }

        $metadata = $this->parse( $request->body() );
        if ( is_string( $metadata ) ) {
            return $this->error( 400, $metadata );
        }

        $ip      = $this->client_ip( $request );
        $limiter = new OAuth_Registration_Rate_Limiter();
        $wait    = $limiter->retry_after( $ip );
        if ( $wait > 0 ) {
            return $this->limited( $limiter, 'per_ip', $wait );
        }

        $unconsented = $store->count_unconsented_clients();
        if ( null === $unconsented ) {
            return $this->error( 500, 'server_error' );
        }
        if ( $unconsented >= self::UNCONSENTED_CAP && ! $store->evict_oldest_unconsented_client( self::EVICT_MIN_AGE ) ) {
            return $this->limited( $limiter, 'cap', self::EVICT_MIN_AGE );
        }

        $created = $store->create_client(
            'registered',
            array(
                'redirect_uris' => $metadata['redirect_uris'],
                'client_name'   => $metadata['client_name'],
                'created_ip'    => $ip,
            )
        );
        $row     = OAuth_Store::OK === $created['outcome'] ? $store->get_client( $created['client_id'] ) : null;
        if ( null === $row ) {
            return $this->error( 500, 'server_error' );
        }
        $limiter->record( $ip );
        Remote_MCP_Events::audit(
            'registration_created',
            array(
                'outcome'     => 'ok',
                'client_ip'   => $ip,
                'client_kind' => 'registered',
            )
        );

        $response = array(
            'client_id'                  => $row['client_id'],
            'client_id_issued_at'        => (int) $row['created_at'],
            'redirect_uris'              => $row['redirect_uris'],
            'grant_types'                => self::GRANT_TYPES,
            'response_types'             => self::RESPONSE_TYPES,
            'token_endpoint_auth_method' => 'none',
        );
        if ( is_string( $row['client_name'] ) && '' !== $row['client_name'] ) {
            $response['client_name'] = $row['client_name'];
        }
        return Remote_MCP_Response::json( 201, $response );
    }

    /**
     * Parse and check the registration body.
     *
     * @param string $body Raw body.
     * @return array|string `redirect_uris` and `client_name`, or an RFC 7591 error code.
     */
    private function parse( string $body ) {
        if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
            return 'invalid_client_metadata';
        }
        $object = json_decode( $body );
        if ( ! $object instanceof \stdClass ) {
            return 'invalid_client_metadata';
        }
        // Preserve list types before converting the outer object for the existing parser.
        foreach ( array(
            'redirect_uris'  => 'invalid_redirect_uri',
            'grant_types'    => 'invalid_client_metadata',
            'response_types' => 'invalid_client_metadata',
        ) as $member => $error ) {
            if ( property_exists( $object, $member ) && ! is_array( $object->$member ) ) {
                return $error;
            }
        }
        $data = (array) $object;

        $uris = $data['redirect_uris'] ?? null;
        if ( ! is_array( $uris ) || empty( $uris ) || ! array_is_list( $uris ) || count( $uris ) > self::MAX_REDIRECTS ) {
            return 'invalid_redirect_uri';
        }
        $clients = $this->services->get( 'known_clients' );
        foreach ( $uris as $uri ) {
            if ( ! is_string( $uri ) || ! $clients->validate_redirect( 'registered', $uri ) ) {
                return 'invalid_redirect_uri';
            }
        }

        if ( array_key_exists( 'token_endpoint_auth_method', $data ) && 'none' !== $data['token_endpoint_auth_method'] ) {
            return 'invalid_client_metadata';
        }
        foreach ( array(
            'grant_types'    => self::GRANT_TYPES,
            'response_types' => self::RESPONSE_TYPES,
        ) as $member => $allowed ) {
            if ( ! array_key_exists( $member, $data ) ) {
                continue;
            }
            $values = $data[ $member ];
            if ( ! is_array( $values ) || empty( $values ) || ! array_is_list( $values ) || array_diff( array_map( 'strval', array_filter( $values, 'is_string' ) ), $allowed ) || count( array_filter( $values, 'is_string' ) ) !== count( $values ) ) {
                return 'invalid_client_metadata';
            }
        }

        $name = $data['client_name'] ?? '';
        if ( ! is_string( $name ) ) {
            return 'invalid_client_metadata';
        }
        // Anyone can register, and these controls can reorder the "name not checked" warning for the
        // administrator reading it. esc_html() keeps valid entities, so they go too, repeatedly
        // because removing one can complete another.
        $name = preg_replace( '/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name );
        do {
            $before = $name;
            $name   = null === $name ? null : preg_replace( '/&#?[a-z0-9]+;/i', '', $name );
        } while ( $before !== $name );
        if ( null === $name ) {
            return 'invalid_client_metadata';
        }
        // logo_uri, client_uri, policy_uri, tos_uri, jwks_uri, software_statement and every other
        // member are dropped here: never fetched, stored or shown.
        return array(
            'redirect_uris' => $uris,
            'client_name'   => $name,
        );
    }

    /**
     * Client address for the limit and logs: REMOTE_ADDR, unless a site behind a known proxy maps it.
     * Proxy headers are never read here, since anyone can send them.
     *
     * @param Remote_MCP_Request $request Request.
     * @return string
     */
    private function client_ip( Remote_MCP_Request $request ): string {
        $remote = (string) $request->server_var( 'REMOTE_ADDR' );
        $ip     = apply_filters( 'mainwp_remote_mcp_client_ip', $remote );
        return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : $remote;
    }

    /**
     * A 429 with Retry-After; the hit is logged at most once an hour.
     *
     * @param OAuth_Registration_Rate_Limiter $limiter Limiter.
     * @param string                          $reason  `per_ip` or `cap`.
     * @param int                             $wait    Seconds.
     * @return Remote_MCP_Response
     */
    private function limited( OAuth_Registration_Rate_Limiter $limiter, string $reason, int $wait ): Remote_MCP_Response {
        if ( $limiter->claim_log_slot() ) {
            Remote_MCP_Events::audit( 'registration_limited', array( 'reason' => $reason ) );
        }
        return $this->error( 429, 'too_many_requests', array( 'Retry-After' => (string) $wait ) );
    }

    /**
     * RFC 7591 section 3.2.2 error with fixed text; nothing from the request is echoed.
     *
     * @param int    $status  HTTP status.
     * @param string $error   Error code.
     * @param array  $headers Extra headers.
     * @return Remote_MCP_Response
     */
    private function error( int $status, string $error, array $headers = array() ): Remote_MCP_Response {
        $descriptions = array(
            'invalid_redirect_uri'    => 'Only apps on this computer can register here, with a loopback redirect such as http://127.0.0.1/callback. A web app needs a Dashboard admin to add it in AI > MainWP MCP > Connect an app.',
            'invalid_client_metadata' => 'The registration request is not valid.',
            'too_many_requests'       => 'Too many registrations right now. Try again later.',
            'server_error'            => 'The registration could not be saved.',
        );
        return Remote_MCP_Response::json(
            $status,
            array(
                'error'             => $error,
                'error_description' => $descriptions[ $error ],
            ),
            $headers
        );
    }
}
