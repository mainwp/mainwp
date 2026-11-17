<?php
/**
 * Remote MCP front controller.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Serves every module path on `parse_request` priority 1, before core's rest_api_loaded at 10.
 * The WordPress REST server never sees these requests: it would answer a malformed body with
 * rest_invalid_json, wrap a 401 under `_envelope`, reflect any Origin into CORS headers and let
 * rest_authentication_errors answer before our challenge.
 *
 * Order per request: the active check before anything is read, then route matching, HTTPS, the
 * method, the body cap, and only then the route's handler. Handlers return a response object; this
 * class alone emits.
 */
class MCP_Front_Controller {

    /**
     * Request body cap, 1 MiB.
     *
     * @var int
     */
    const BODY_CAP = 1048576;

    /**
     * REST routes, lowercased, by slot.
     *
     * @var array
     */
    const REST_ROUTES = array(
        Remote_MCP_Urls::ROUTE_MCP      => 'route_mcp',
        Remote_MCP_Urls::ROUTE_PRM      => 'route_prm',
        Remote_MCP_Urls::ROUTE_TOKEN    => 'route_token',
        Remote_MCP_Urls::ROUTE_REGISTER => 'route_register',
    );

    /**
     * Methods each route accepts. Anything else, OPTIONS included, gets 405: v1 has no CORS and no
     * preflight.
     *
     * @var array
     */
    const METHODS = array(
        'route_mcp'         => array( 'POST' ),
        'route_prm'         => array( 'GET' ),
        'route_token'       => array( 'POST' ),
        'route_register'    => array( 'POST' ),
        'route_as_metadata' => array( 'GET' ),
        'route_openid'      => array( 'GET' ),
        'route_authorize'   => array( 'GET', 'POST' ),
        'route_approve'     => array( 'GET', 'POST' ),
    );

    /**
     * Browser pages: their path form with pretty permalinks, their selector value with plain ones.
     *
     * @var array
     */
    const BROWSER_ROUTES = array(
        'route_authorize' => array( Remote_MCP_Urls::AUTHORIZE_PATH, 'authorize' ),
        'route_approve'   => array( Remote_MCP_Urls::APPROVE_PATH, 'approve' ),
    );

    /**
     * Module services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * The active predicate, evaluated per request.
     *
     * @var callable
     */
    private $is_active;

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services  Services.
     * @param callable|null       $is_active Active predicate; Remote_MCP_Settings::is_active() when null.
     */
    public function __construct( Remote_MCP_Services $services, ?callable $is_active = null ) {
        $this->services  = $services;
        $this->is_active = $is_active ? $is_active : array( Remote_MCP_Settings::class, 'is_active' );
    }

    /**
     * The parse_request callback: dispatch, then emit and exit when the path was ours.
     *
     * @param \WP $wp Current WordPress environment.
     */
    public function on_parse_request( $wp ): void {
        if ( ! $wp instanceof \WP ) {
            return;
        }
        $response = $this->dispatch(
            $wp,
            function ( int $limit ) {
                $body = file_get_contents( 'php://input', false, null, 0, $limit ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- raw request body, read once with a cap.
                return false === $body ? '' : $body;
            }
        );
        if ( null !== $response ) {
            $this->emit( $response );
        }
    }

    /**
     * The parse_request callback while the stored toggle is off: module paths get a final 404 and
     * nothing else happens. Without it, the plain-permalink selectors are ordinary home page
     * requests, which WordPress answers with 200.
     *
     * @param \WP $wp Current WordPress environment.
     */
    public function on_parse_request_disabled( $wp ): void {
        if ( ! $wp instanceof \WP ) {
            return;
        }
        $response = $this->not_found( $wp );
        if ( null !== $response ) {
            $this->emit( $response );
        }
    }

    /**
     * A final 404 for a module path, or null when the request isn't for one. No handler runs and
     * no header, body or parameter beyond the path and the route selector is read.
     *
     * Only the module's own paths are claimed: its REST routes and the `mainwp-oauth` paths and
     * selectors. The well-known paths are shared with every other authorization server, so while
     * the connector is off another plugin's metadata there must keep working.
     *
     * @param \WP $wp Current WordPress environment.
     * @return Remote_MCP_Response|null
     */
    public function not_found( \WP $wp ): ?Remote_MCP_Response {
        $slot = $this->match( $wp );
        if ( null === $slot || 'route_as_metadata' === $slot || 'route_openid' === $slot ) {
            return null;
        }
        return new Remote_MCP_Response( 404, array( 'Content-Type' => 'text/plain; charset=utf-8' ), 'Not found' );
    }

    /**
     * Handle one request, or return null to leave a request that isn't for the module to WordPress.
     * While the connector is off or forced off, a module path gets a final 404.
     *
     * @param \WP      $wp        Current WordPress environment.
     * @param callable $read_body Reads at most N bytes of the raw body.
     * @return Remote_MCP_Response|null
     */
    public function dispatch( \WP $wp, callable $read_body ): ?Remote_MCP_Response {
        // mainwp.php skips MainWP_System on a SecuPress scan request, which anyone can fake with a query
        // string. The activity log never loads then, so nothing served here would be recorded.
        if ( null === \MainWP\Dashboard\MainWP_System::instance() ) {
            return $this->not_found( $wp );
        }
        // First, before any header or body is read; only the path and route selector are matched.
        if ( ! call_user_func( $this->is_active ) ) {
            return $this->not_found( $wp );
        }
        $slot = $this->match( $wp );
        if ( null === $slot ) {
            return null;
        }
        // No redirect to https: a client that sent credentials in clear has already leaked them,
        // and the body is never read, so a code or refresh token sent over http isn't burned.
        if ( ! is_ssl() ) {
            $endpoint = substr( $slot, strlen( 'route_' ) );
            $logged   = OAuth_Rate_Limiter::TRANSIENT_PREFIX . 'http_refused_' . $endpoint;
            // Anyone can repeat this request, and one row an hour is enough to show the setup problem.
            if ( false === get_transient( $logged ) ) {
                set_transient( $logged, 1, HOUR_IN_SECONDS );
                Remote_MCP_Events::audit( 'http_refused', array( 'endpoint' => $endpoint ) );
            }
            return $this->insecure_response( $slot );
        }
        if ( 'route_unknown' === $slot ) {
            return Remote_MCP_Response::json( 404, array( 'error' => 'not_found' ) );
        }
        $method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) : 'GET' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a fixed list only.
        if ( ! in_array( $method, self::METHODS[ $slot ], true ) ) {
            return new Remote_MCP_Response( 405, array( 'Allow' => implode( ', ', self::METHODS[ $slot ] ) ) );
        }
        $body = '';
        if ( 'POST' === $method ) {
            $body = (string) call_user_func( $read_body, self::BODY_CAP + 1 );
            if ( strlen( $body ) > self::BODY_CAP ) {
                return Remote_MCP_Response::json( 413, array( 'error' => 'request_too_large' ) );
            }
        }
        // WordPress adds magic quotes to $_SERVER; handlers get the bytes the client sent.
        $query   = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handlers parse the raw string themselves.
        $request = new Remote_MCP_Request( $slot, $method, $query, $body, wp_unslash( $_SERVER ) );
        return $this->services->get( $slot )->handle( $request )->without_cors();
    }

    /**
     * Which module route a request is for: a slot, `route_unknown` for an unknown path under the
     * module's REST namespace, or null when it isn't ours.
     *
     * Well-known and authorize paths are matched against REQUEST_URI's path, never through rewrite
     * rules or $wp->matched_rule: core's own attachment and page rules match these paths before
     * WordPress knows nothing is there. Only the exact forms are claimed, never
     * /.well-known/ as a prefix, so ACME and every other well-known path stay untouched.
     *
     * @param \WP $wp Current WordPress environment.
     * @return string|null
     */
    public function match( \WP $wp ): ?string {
        $urls = $this->services->urls();
        if ( ! $urls->is_secure() ) {
            return null;
        }

        // WordPress fills rest_route for both /wp-json/... and ?rest_route=... before parse_request.
        $route = $wp->query_vars['rest_route'] ?? null;
        if ( is_string( $route ) && '' !== $route ) {
            $normalized = '/' . strtolower( trim( $route, '/' ) );
            if ( 0 !== strpos( $normalized . '/', Remote_MCP_Urls::NAMESPACE_ROUTE . '/' ) ) {
                return null;
            }
            return self::REST_ROUTES[ $normalized ] ?? 'route_unknown';
        }

        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against fixed paths only.
        $path = (string) strstr( $uri . '?', '?', true );
        $home = $urls->path();

        // The nginx dot-path fallback appends q=, so only the path counts.
        $competing = Remote_MCP_Settings::competing_rules();
        $documents = array(
            'route_as_metadata' => array( 'oauth-authorization-server', $competing['authorization_server'] ),
            'route_openid'      => array( 'openid-configuration', $competing['openid'] ),
        );
        foreach ( $documents as $slot => list( $name, $taken ) ) {
            // A path another plugin's rewrite rule serves stays that plugin's.
            if ( ! $taken && ( '/.well-known/' . $name . $home === $path || $home . '/.well-known/' . $name === $path ) ) {
                return $slot;
            }
        }

        if ( $urls->plain_permalinks() ) {
            if ( $home . '/' === $path || ( '' !== $home && $home === $path ) ) {
                $selector = Remote_MCP_Params::extract( (string) wp_parse_url( $uri, PHP_URL_QUERY ), array( Remote_MCP_Urls::AUTHORIZE_QUERY_VAR ) );
                foreach ( self::BROWSER_ROUTES as $slot => list( , $value ) ) {
                    if ( null === $selector['duplicate'] && $value === $selector['values'][ Remote_MCP_Urls::AUTHORIZE_QUERY_VAR ] ) {
                        return $slot;
                    }
                }
            }
            return null;
        }
        foreach ( self::BROWSER_ROUTES as $slot => list( $route_path ) ) {
            if ( $home . $route_path === $path ) {
                return $slot;
            }
        }
        return null;
    }

    /**
     * Refusal for a request that didn't arrive over HTTPS. Nothing is read, looked
     * up or changed, and nothing redirects to a client.
     *
     * @param string $slot Matched slot.
     * @return Remote_MCP_Response|null Null for well-known metadata, which gets WordPress's 404.
     */
    private function insecure_response( string $slot ): ?Remote_MCP_Response {
        switch ( $slot ) {
            case 'route_as_metadata':
            case 'route_openid':
                return null;
            case 'route_token':
            case 'route_register':
                return Remote_MCP_Response::json(
                    400,
                    array(
                        'error'             => 'invalid_request',
                        'error_description' => 'HTTPS required.',
                    )
                );
            case 'route_authorize':
            case 'route_approve':
                return Remote_MCP_Response::html( 400, '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html__( 'HTTPS required', 'mainwp' ) . '</title></head><body><p>' . esc_html__( 'This page only works over HTTPS.', 'mainwp' ) . '</p></body></html>' );
            default:
                return Remote_MCP_Response::jsonrpc_error( 400, -32600, 'HTTPS required' );
        }
    }

    /**
     * Send a response and end the request.
     *
     * @param Remote_MCP_Response $response Response.
     */
    protected function emit( Remote_MCP_Response $response ): void {
        // Page caches read the constant, not headers.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        if ( ! headers_sent() ) {
            // Drops anything queued earlier, CORS headers from another plugin included.
            header_remove();
            status_header( $response->status() );
            nocache_headers();
            foreach ( $response->headers() as $name => $value ) {
                header( $name . ': ' . $value );
            }
        }
        echo $response->body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON or HTML body the handler built and escaped.
        exit;
    }
}
