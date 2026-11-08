<?php
/**
 * Remote MCP canonical URLs.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Issuer, resource and endpoint URLs from the stored `home`. Never from `Host`, `X-Forwarded-*`,
 * `HTTPS`, `SERVER_PORT`, is_ssl() or home_url()'s request-time filters: ChatGPT compares these
 * byte for byte, and the token hash is keyed to the resource.
 */
final class Remote_MCP_Urls {

    /**
     * REST namespace of every module route.
     *
     * @var string
     */
    const NAMESPACE_ROUTE = '/mainwp-remote/v1';

    const ROUTE_MCP      = '/mainwp-remote/v1/mcp';
    const ROUTE_PRM      = '/mainwp-remote/v1/oauth-protected-resource';
    const ROUTE_TOKEN    = '/mainwp-remote/v1/oauth/token';
    const ROUTE_REGISTER = '/mainwp-remote/v1/register';

    /**
     * Browser path of the authorization endpoint, under the home path.
     *
     * @var string
     */
    const AUTHORIZE_PATH = '/mainwp-oauth/authorize';

    /**
     * Browser path of the approval page, under the home path.
     *
     * @var string
     */
    const APPROVE_PATH = '/mainwp-oauth/approve';

    /**
     * Query selector of the authorization endpoint with plain permalinks.
     *
     * @var string
     */
    const AUTHORIZE_QUERY_VAR = 'mainwp_oauth';

    /**
     * What a home on a port other than 443 means: `warn` until the port check runs, `refuse` if a
     * hosted client can't connect on another port.
     *
     * @var string
     */
    const NON_443_POLICY = 'warn';

    /**
     * Canonical base, or null when home is not a usable https URL.
     *
     * @var string|null
     */
    private $base;

    /**
     * Constructor. The stored home is read once, here.
     *
     * @param string|null $home Home URL; get_option( 'home' ) when null, which honors WP_HOME.
     */
    public function __construct( ?string $home = null ) {
        $this->base = self::canonical_base( null === $home ? (string) get_option( 'home' ) : $home );
    }

    /**
     * Canonical form of a home URL: https only, scheme and host lowercased, port 443 dropped and
     * other ports kept, the path as stored without a trailing slash. A home with userinfo, a query
     * or a fragment is refused rather than cleaned up.
     *
     * @param string $home Home URL.
     * @return string|null
     */
    public static function canonical_base( string $home ): ?string {
        if ( 1 !== preg_match( '#^https://#i', $home ) ) {
            return null;
        }
        $parts = wp_parse_url( $home );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || false !== strpos( $home, '#' ) || false !== strpos( $home, '?' ) ) {
            return null;
        }
        $port = isset( $parts['port'] ) ? (int) $parts['port'] : 443;
        if ( $port < 1 || $port > 65535 ) {
            return null;
        }
        $base = 'https://' . strtolower( $parts['host'] ) . ( 443 === $port ? '' : ':' . $port );
        return $base . rtrim( isset( $parts['path'] ) ? (string) $parts['path'] : '', '/' );
    }

    /**
     * Whether home is an https URL the module can serve.
     *
     * @return bool
     */
    public function is_secure(): bool {
        return null !== $this->base;
    }

    /**
     * Canonical base.
     *
     * @return string|null
     */
    public function base(): ?string {
        return $this->base;
    }

    /**
     * Issuer: the canonical base, never under the REST prefix.
     *
     * @return string|null
     */
    public function issuer(): ?string {
        return $this->base;
    }

    /**
     * Path of home: '' for a root install, '/sub' for a subdirectory install.
     *
     * @return string
     */
    public function path(): string {
        if ( null === $this->base ) {
            return '';
        }
        $path = wp_parse_url( $this->base, PHP_URL_PATH );
        return is_string( $path ) ? $path : '';
    }

    /**
     * Port of home when it isn't 443.
     *
     * @return int|null
     */
    public function port(): ?int {
        if ( null === $this->base ) {
            return null;
        }
        $port = wp_parse_url( $this->base, PHP_URL_PORT );
        return is_int( $port ) ? $port : null;
    }

    /**
     * Whether the site uses plain permalinks, where routes use the `?rest_route=` form.
     *
     * @return bool
     */
    public function plain_permalinks(): bool {
        return '' === (string) get_option( 'permalink_structure' );
    }

    /**
     * The MCP URL, which is also the canonical resource (RFC 8707) the token hash is bound to.
     *
     * @return string|null
     */
    public function mcp_url(): ?string {
        return $this->rest_url( self::ROUTE_MCP );
    }

    /**
     * Protected resource metadata URL.
     *
     * @return string|null
     */
    public function prm_url(): ?string {
        return $this->rest_url( self::ROUTE_PRM );
    }

    /**
     * Token endpoint.
     *
     * @return string|null
     */
    public function token_endpoint(): ?string {
        return $this->rest_url( self::ROUTE_TOKEN );
    }

    /**
     * Registration endpoint.
     *
     * @return string|null
     */
    public function registration_endpoint(): ?string {
        return $this->rest_url( self::ROUTE_REGISTER );
    }

    /**
     * Authorization endpoint, also the consent form's action.
     *
     * @return string|null
     */
    public function authorization_endpoint(): ?string {
        if ( null === $this->base ) {
            return null;
        }
        if ( $this->plain_permalinks() ) {
            return $this->base . '/?' . self::AUTHORIZE_QUERY_VAR . '=authorize';
        }
        return $this->base . self::AUTHORIZE_PATH;
    }

    /**
     * Approval page URL for one approval row. The row id is not a secret: the page
     * checks the signed-in user.
     *
     * @param int $approval_id Approval id.
     * @return string The URL, or '' when home is not a usable https URL.
     */
    public function approval_url( int $approval_id ): string {
        if ( null === $this->base ) {
            return '';
        }
        if ( $this->plain_permalinks() ) {
            return $this->base . '/?' . self::AUTHORIZE_QUERY_VAR . '=approve&approval=' . $approval_id;
        }
        return $this->base . self::APPROVE_PATH . '?approval=' . $approval_id;
    }

    /**
     * URL of a module REST route in the site's permalink form.
     *
     * @param string $route Route, starting with NAMESPACE_ROUTE.
     * @return string|null
     */
    public function rest_url( string $route ): ?string {
        if ( null === $this->base ) {
            return null;
        }
        if ( $this->plain_permalinks() ) {
            return $this->base . '/?rest_route=' . $route;
        }
        return $this->base . '/' . trim( rest_get_url_prefix(), '/' ) . $route;
    }
}
