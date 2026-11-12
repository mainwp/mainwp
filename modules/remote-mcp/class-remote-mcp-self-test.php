<?php
/**
 * Remote MCP self-test.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The self-test, the conditions that block enabling, and the opt-in check from the internet.
 *
 * The probes are loopback requests to the Dashboard's own public URLs, so they show what the
 * Dashboard sees of itself, not what an app on the internet sees. The connector is usually still
 * off when the self-test runs, so nothing of the module answers those URLs yet. Each run therefore
 * carries a one-time token, and answer_probe() replies for a request that carries it and that the
 * front controller would route to a module endpoint. A request that doesn't reach this WordPress
 * install with its path intact never gets that reply, which is what the probes measure.
 *
 * This file is the only place the module makes outbound requests.
 */
class Remote_MCP_Self_Test implements Enable_Check {

    /**
     * Query parameter carrying a run's probe token.
     *
     * @var string
     */
    const PROBE_VAR = 'mainwp_remote_mcp_probe';

    /**
     * Transient prefix of a live probe token.
     *
     * @var string
     */
    const PROBE_TRANSIENT = 'mainwp_remote_mcp_probe_';

    /**
     * Seconds a probe token stays valid.
     *
     * @var int
     */
    const PROBE_TTL = 120;

    /**
     * Seconds each loopback probe may take.
     *
     * @var int
     */
    const PROBE_TIMEOUT = 4;

    /**
     * Well-known forms: kind => [ document, `insert` or `append` ].
     *
     * @var array
     */
    const WELL_KNOWN = array(
        'rfc_insert'  => array( 'oauth-authorization-server', 'insert' ),
        'oidc_insert' => array( 'openid-configuration', 'insert' ),
        'rfc_append'  => array( 'oauth-authorization-server', 'append' ),
        'oidc_append' => array( 'openid-configuration', 'append' ),
    );

    /**
     * Observed discovery order of each supported client. Other bundled clients
     * have no observed order yet and aren't judged.
     *
     * @var array
     */
    const CLIENT_ORDERS = array(
        'claude_code' => array(
            'label' => 'Claude Code',
            'order' => array( 'rfc_insert', 'oidc_insert', 'oidc_append' ),
        ),
        'claude_ai'   => array(
            'label' => 'Claude',
            'order' => array( 'rfc_insert', 'oidc_insert', 'oidc_append' ),
        ),
        'vscode'      => array(
            'label' => 'VS Code',
            'order' => array( 'rfc_insert', 'oidc_insert', 'oidc_append' ),
        ),
        'chatgpt'     => array(
            'label' => 'ChatGPT',
            'order' => array( 'rfc_insert', 'rfc_append', 'oidc_append' ),
        ),
    );

    /**
     * The bundled client ids and their labels, looked up through Known_Clients.
     *
     * @var array
     */
    const BUNDLED_CLIENTS = array(
        'https://claude.ai/oauth/mcp-oauth-client-metadata' => 'Claude',
        'https://claude.ai/oauth/claude-code-client-metadata' => 'Claude Code',
        'https://chatgpt.com/oauth/client.json'            => 'ChatGPT',
        'https://vscode.dev/oauth/client-metadata.json'    => 'VS Code',
        'https://chatgpt.com/oauth/codex/client.json'      => 'Codex CLI',
        'https://zed.dev/oauth/client-metadata.json'       => 'Zed',
        'https://goose-docs.ai/oauth/client-metadata.json' => 'Goose',
    );

    /**
     * Checker used unless MAINWP_ASSISTANT_BRIDGE_URL names another.
     *
     * @var string
     */
    const BRIDGE_URL = 'https://ai-bridge.mainwp.com';

    /**
     * The external checks, in the contract's order.
     *
     * @var array
     */
    const EXTERNAL_CHECKS = array( 'dns', 'tls', 'discovery', 'metadata', 'mcp_401' );

    /**
     * One line per contract code. Any other code, and every error, reads as EXTERNAL_GENERIC.
     *
     * @var array
     */
    const EXTERNAL_CODES = array(
        'dns_no_record'              => 'Your Dashboard\'s domain has no public DNS record.',
        'dns_private_address'        => 'Your Dashboard\'s domain points to a private address, so apps on the internet can\'t reach it.',
        'tls_handshake_failed'       => 'The secure connection to your Dashboard failed.',
        'tls_certificate_invalid'    => 'Your Dashboard\'s TLS certificate isn\'t valid for its domain.',
        'http_timeout'               => 'Your Dashboard didn\'t answer within 10 seconds.',
        'discovery_not_found'        => 'Apps can\'t find the sign-in information at the well-known address.',
        'discovery_blocked'          => 'Something in front of WordPress blocks the well-known address: an error page, a server error or a challenge page.',
        'discovery_path_append_only' => 'Only the subdirectory form of the well-known address answers. Some apps look elsewhere first.',
        'metadata_invalid'           => 'The sign-in information is missing required fields.',
        'metadata_issuer_mismatch'   => 'The sign-in information names a different site address.',
        'mcp_not_401'                => 'The MCP address didn\'t answer with a sign-in request (HTTP 401).',
        'mcp_challenge_invalid'      => 'The MCP address answered with a malformed sign-in request.',
        'skipped_after_failure'      => 'Skipped after an earlier failure.',
    );

    /**
     * Line for an unknown code and for every checker error, 429 included.
     *
     * @var string
     */
    const EXTERNAL_GENERIC = 'The check from the internet couldn\'t run. Try again in a few minutes.';

    /**
     * WordPress's guide for HTTPS behind a proxy.
     *
     * @var string
     */
    const PROXY_HELP = 'https://developer.wordpress.org/advanced-administration/security/https/';

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
     * Conditions that refuse enabling. The kill switch, an insecure home and a competing
     * authorization-server rule are also checked by the wiring itself.
     *
     * @return string[]
     */
    public function blocking_reasons(): array {
        return $this->run()['blocking'];
    }

    /**
     * Run every check.
     *
     * @return array `lines` (each `id`, `status` pass|warn|fail|info, `message`, `fix`, `help`, and
     *               check-specific fields) and `blocking` (reason codes).
     */
    public function run(): array {
        $lines    = array();
        $blocking = array();
        $urls     = $this->services->urls();

        $lines[] = self::line( 'scope', 'info', __( 'These checks run from the Dashboard itself, so they can\'t see what Claude, ChatGPT or another app sees from the internet. Use "Check from the internet" for that.', 'mainwp' ) );

        $requirements = $this->requirements();
        if ( $requirements['ok'] ) {
            /* translators: 1: PHP version, 2: WordPress version. */
            $lines[] = self::line( 'requirements', 'pass', sprintf( __( 'PHP %1$s and WordPress %2$s are supported.', 'mainwp' ), PHP_VERSION, (string) get_bloginfo( 'version' ) ) );
        } else {
            $lines[]    = self::line( 'requirements', 'fail', Remote_MCP_Requirements::message( $requirements ) );
            $blocking[] = 'php' === $requirements['reason'] ? 'php_version' : ( 'multisite' === $requirements['reason'] ? 'multisite' : 'wordpress_version' );
        }

        if ( $urls->is_secure() ) {
            $lines[] = self::line( 'https_home', 'pass', __( 'Your site address uses https://.', 'mainwp' ) );
        } else {
            $lines[]    = self::line( 'https_home', 'fail', __( 'Your site address starts with http://. Remote AI connections need https://.', 'mainwp' ) );
            $blocking[] = 'insecure_home';
        }

        if ( $this->activity_log_available() ) {
            $lines[] = self::line( 'activity_log', 'pass', __( 'The activity log is on, so every AI app call is recorded.', 'mainwp' ) );
        } else {
            $lines[]    = self::line( 'activity_log', 'fail', __( 'Turn on the activity log first. AI apps can\'t make changes or use tools MainWP hasn\'t reviewed unless each call is recorded.', 'mainwp' ) );
            $blocking[] = 'activity_log_off';
        }

        $forced  = Remote_MCP_Settings::forced_off();
        $lines[] = null === $forced
            ? self::line( 'kill_switch', 'pass', __( 'Nothing forces remote AI connections off.', 'mainwp' ) )
            : self::line( 'kill_switch', 'fail', Remote_MCP_Admin::forced_off_message( $forced ) );

        $competing = ( new Remote_MCP( $this->services ) )->refresh_competing_rules();
        if ( $competing['authorization_server'] ) {
            $lines[] = self::line( 'competing_rules', 'fail', __( 'Another plugin serves /.well-known/oauth-authorization-server through a rewrite rule. Two sign-in servers can\'t share that address, so remote AI connections can\'t be turned on.', 'mainwp' ) );
        } elseif ( $competing['openid'] ) {
            $lines[] = self::line( 'competing_rules', 'warn', __( 'Another plugin serves /.well-known/openid-configuration. Apps that look there first may find that plugin instead of this Dashboard.', 'mainwp' ) );
        }

        $port = $urls->port();
        if ( null !== $port ) {
            if ( 'refuse' === $this->non_443_policy() ) {
                /* translators: %d: port number. */
                $lines[]    = self::line( 'non_443', 'fail', sprintf( __( 'Claude and ChatGPT can\'t reach port %d; serve the Dashboard on 443.', 'mainwp' ), $port ) );
                $blocking[] = 'non_443_port';
            } else {
                /* translators: %d: port number. */
                $lines[] = self::line( 'non_443', 'warn', sprintf( __( 'Remote AI apps may not reach port %d.', 'mainwp' ), $port ) );
            }
        }

        if ( $requirements['ok'] && $urls->is_secure() ) {
            $token = OAuth_Token_Hasher::base64url( random_bytes( 32 ) );
            set_transient( self::probe_key( $token ), 1, self::PROBE_TTL );
            try {
                $this->probe_lines( $token, $lines, $blocking );
            } finally {
                delete_transient( self::probe_key( $token ) );
            }
        }

        $counts   = Remote_MCP_Settings::counters();
        $stripped = $counts['auth_header_missing'] > 0 && 0 === $counts['auth_header_received'];
        $lines[]  = self::line(
            'auth_counters',
            $stripped ? 'warn' : 'info',
            /* translators: 1: requests without an Authorization header, 2: rejected tokens. */
            sprintf( __( 'Last 24 hours: requests without an Authorization header: %1$d; tokens rejected: %2$d.', 'mainwp' ), $counts['auth_header_missing'], $counts['auth_token_rejected'] ),
            $stripped ? __( 'No request with an Authorization header has reached WordPress in the last 24 hours. If an app can\'t connect, the web server or a proxy may be stripping it.', 'mainwp' ) : '',
            array(
                'counts' => array(
                    'auth_header_missing' => $counts['auth_header_missing'],
                    'auth_token_rejected' => $counts['auth_token_rejected'],
                ),
            )
        );

        $lines[] = $this->known_clients_line();
        /* translators: %d: refused requests. */
        $lines[] = self::line( 'unknown_clients', 'info', sprintf( __( 'Unknown apps refused in the last 24 hours: %d.', 'mainwp' ), $counts['unknown_client'] ), '', array( 'count' => $counts['unknown_client'] ) );

        return array(
            'lines'    => $lines,
            'blocking' => array_values( array_unique( $blocking ) ),
        );
    }

    /**
     * Discovery, routing, header and HTTPS probes.
     *
     * @param string $token    Probe token.
     * @param array  $lines    Lines, appended to.
     * @param array  $blocking Blocking reasons, appended to.
     */
    private function probe_lines( string $token, array &$lines, array &$blocking ): void {
        $urls    = $this->services->urls();
        $path    = $urls->path();
        $root    = '' === $path;
        $issuer  = (string) $urls->issuer();
        $fetched = array();
        $results = array();
        foreach ( self::WELL_KNOWN as $kind => list( $document, $form ) ) {
            $url = $this->well_known_url( $document, $form );
            if ( ! isset( $fetched[ $url ] ) ) {
                $fetched[ $url ] = $this->classify( $this->get( add_query_arg( self::PROBE_VAR, $token, $url ) ), $issuer );
            }
            $results[ $kind ] = $fetched[ $url ] + array( 'url' => $url );
        }
        $classes = array_map(
            function ( $result ) {
                return $result['class'];
            },
            $results
        );

        if ( in_array( 'ours', $classes, true ) ) {
            $lines[] = self::line( 'path_routing', 'pass', __( 'Requests for the well-known addresses reach WordPress.', 'mainwp' ) );
        } else {
            $rule = 'RewriteRule ^\.well-known/(oauth-authorization-server|openid-configuration)$ ' . $path . '/index.php [L]';
            $fix  = $urls->plain_permalinks()
                /* translators: %s: Apache rewrite rule. */
                ? sprintf( __( 'Turn on pretty permalinks in Settings > Permalinks, or add this rule to the .htaccess file of this WordPress install: %s', 'mainwp' ), $rule )
                /* translators: %s: Apache rewrite rule. */
                : sprintf( __( 'Ask your host to send these addresses to WordPress, or add this rule to the .htaccess file of this WordPress install: %s', 'mainwp' ), $rule );
            $lines[]    = self::line( 'path_routing', 'fail', __( 'Requests for the well-known addresses don\'t reach WordPress, so no app can find how to sign in.', 'mainwp' ), $fix );
            $blocking[] = 'path_routing';
        }

        $discovery = self::evaluate_discovery( $classes, $root );
        $lines[]   = self::line( 'discovery', $discovery['status'], self::discovery_message( $discovery, $root ), '', array( 'clients' => $discovery['clients'] ) );
        if ( 'fail' === $discovery['status'] ) {
            $blocking[] = 'discovery_failed';
        }

        if ( ! $root && 'ours' !== $classes['rfc_append'] && 'ours' !== $classes['oidc_append'] && 'pass' !== $discovery['status'] ) {
            $foreign = 'foreign' === $classes['rfc_append'] || 'foreign' === $classes['oidc_append'];
            $lines[] = self::line(
                'path_append',
                'fail',
                $foreign
                    /* translators: %s: URL. */
                    ? sprintf( __( '%s is answered by another WordPress install, so the request never reaches this Dashboard. A server rule that blocks addresses starting with a dot usually causes this.', 'mainwp' ), $results['rfc_append']['url'] )
                    /* translators: %s: URL. */
                    : sprintf( __( 'The web server blocks %s before it reaches WordPress. A server rule that blocks addresses starting with a dot usually causes this.', 'mainwp' ), $results['rfc_append']['url'] )
            );
            $lines[] = self::line( 'subdirectory_rule', 'info', __( 'Add this rule to the web server in front of your site so apps find this Dashboard at the root well-known addresses.', 'mainwp' ), self::subdirectory_rule( $path ) );
        }

        $static = array();
        foreach ( $fetched as $url => $result ) {
            if ( $result['static'] ) {
                $static[] = $url;
            }
        }
        if ( $static ) {
            /* translators: %s: comma-separated URLs. */
            $lines[] = self::line( 'well_known_conflict', 'warn', sprintf( __( 'Something other than WordPress answers %s, for example a static file or a server rule. Remove it so these addresses reach WordPress.', 'mainwp' ), implode( ', ', $static ) ) );
        }

        $this->endpoint_lines( $token, $lines );
    }

    /**
     * MCP endpoint probes: the Authorization header, HTTPS as WordPress sees it, and plain HTTP.
     *
     * @param string $token Probe token.
     * @param array  $lines Lines, appended to.
     */
    private function endpoint_lines( string $token, array &$lines ): void {
        $mcp      = (string) $this->services->urls()->mcp_url();
        $response = wp_remote_post(
            add_query_arg( self::PROBE_VAR, $token, $mcp ),
            array(
                'timeout'     => self::PROBE_TIMEOUT,
                'redirection' => 0,
                'sslverify'   => $this->sslverify(),
                'headers'     => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'        => '{}',
            )
        );
        $probe    = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $probe ) || ! isset( $probe['https'], $probe['authorization'] ) ) {
            $lines[] = self::line( 'authorization_header', 'fail', __( 'The probe of the MCP address didn\'t reach WordPress.', 'mainwp' ) );
            $lines[] = self::line( 'https_requests', 'fail', __( 'The probe of the MCP address didn\'t reach WordPress.', 'mainwp' ) );
        } else {
            $lines[] = true === $probe['authorization']
                ? self::line( 'authorization_header', 'pass', __( 'The Authorization header reaches WordPress.', 'mainwp' ) )
                : self::line( 'authorization_header', 'fail', __( 'The Authorization header doesn\'t reach WordPress: the web server or a proxy strips it, so every app request looks signed out.', 'mainwp' ), __( 'On Apache, add "CGIPassAuth On" to the .htaccess file, or ask your host to pass the Authorization header to PHP.', 'mainwp' ) );
            $lines[] = true === $probe['https']
                ? self::line( 'https_requests', 'pass', __( 'Requests reach WordPress over https://.', 'mainwp' ) )
                : self::line( 'https_requests', 'fail', __( 'Requests reach WordPress as http://; see the reverse proxy guide.', 'mainwp' ), '', array( 'help' => self::PROXY_HELP ) );
        }

        $plain   = wp_remote_post(
            'http://' . substr( $mcp, strlen( 'https://' ) ),
            array(
                'timeout'     => self::PROBE_TIMEOUT,
                'redirection' => 0,
                'headers'     => array( 'Content-Type' => 'application/json' ),
                'body'        => '{}',
            )
        );
        $code    = is_wp_error( $plain ) ? 0 : (int) wp_remote_retrieve_response_code( $plain );
        $lines[] = $code >= 200 && $code < 300
            ? self::line( 'plain_http', 'fail', __( 'A plain http:// request to the MCP address was answered. It must be refused.', 'mainwp' ) )
            : self::line( 'plain_http', 'pass', __( 'Plain http:// requests to the MCP address are refused.', 'mainwp' ) );
    }

    /**
     * Whether every bundled client document loads.
     *
     * @return array Line.
     */
    private function known_clients_line(): array {
        $clients = $this->services->get( 'known_clients' );
        $broken  = array();
        foreach ( self::BUNDLED_CLIENTS as $client_id => $label ) {
            if ( null === $clients->lookup( $client_id ) ) {
                $broken[] = $label;
            }
        }
        if ( ! $broken ) {
            return self::line( 'known_clients', 'pass', __( 'Every bundled app file passed its check.', 'mainwp' ) );
        }
        /* translators: %s: comma-separated app names. */
        return self::line( 'known_clients', 'fail', sprintf( __( 'These apps can\'t connect because their bundled file failed its check: %s. Reinstall or update MainWP.', 'mainwp' ), implode( ', ', $broken ) ) );
    }

    /**
     * Judge each client's observed discovery order over the fetched results.
     *
     * @param array $classes Kind => `ours`, `foreign` or `none`.
     * @param bool  $root    Whether this is a root install.
     * @return array `status` and `clients` (client key => pass|warn|fail).
     */
    public static function evaluate_discovery( array $classes, bool $root ): array {
        $clients = array();
        foreach ( self::CLIENT_ORDERS as $key => $client ) {
            // Every observed client asks for the root RFC 8414 document first; a root install that
            // can't serve it has a routing or conflict problem the walk would hide.
            if ( $root && 'ours' !== ( $classes['rfc_insert'] ?? 'none' ) ) {
                $clients[ $key ] = 'fail';
                continue;
            }
            $result  = 'fail';
            $foreign = false;
            foreach ( $client['order'] as $kind ) {
                $class = $classes[ $kind ] ?? 'none';
                if ( 'ours' === $class ) {
                    $result = $foreign ? 'warn' : 'pass';
                    break;
                }
                if ( 'foreign' === $class ) {
                    $foreign = true;
                }
            }
            // ChatGPT was never observed completing without an RFC 8414 document.
            if ( 'chatgpt' === $key && 'fail' !== $result && 'ours' !== ( $classes['rfc_insert'] ?? 'none' ) && 'ours' !== ( $classes['rfc_append'] ?? 'none' ) ) {
                $result = 'warn';
            }
            $clients[ $key ] = $result;
        }
        $status = in_array( 'fail', $clients, true ) ? 'fail' : ( in_array( 'warn', $clients, true ) ? 'warn' : 'pass' );
        return array(
            'status'  => $status,
            'clients' => $clients,
        );
    }

    /**
     * The discovery line's text.
     *
     * @param array $discovery evaluate_discovery() result.
     * @param bool  $root      Whether this is a root install.
     * @return string
     */
    private static function discovery_message( array $discovery, bool $root ): string {
        $named = array(
            'warn' => array(),
            'fail' => array(),
        );
        foreach ( $discovery['clients'] as $key => $result ) {
            if ( isset( $named[ $result ] ) ) {
                $named[ $result ][] = self::CLIENT_ORDERS[ $key ]['label'];
            }
        }
        if ( 'pass' === $discovery['status'] ) {
            return __( 'Claude Code, Claude, VS Code and ChatGPT can all find this Dashboard\'s sign-in information.', 'mainwp' );
        }
        $parts = array();
        if ( $named['fail'] ) {
            $parts[] = $root
                /* translators: %s: comma-separated app names. */
                ? sprintf( __( '%s can\'t connect: on a root install, /.well-known/oauth-authorization-server must return this Dashboard\'s sign-in information.', 'mainwp' ), implode( ', ', $named['fail'] ) )
                /* translators: %s: comma-separated app names. */
                : sprintf( __( '%s can\'t find this Dashboard\'s sign-in information.', 'mainwp' ), implode( ', ', $named['fail'] ) );
        }
        if ( $named['warn'] ) {
            /* translators: %s: comma-separated app names. */
            $parts[] = sprintf( __( '%s may not connect: they reach this Dashboard only after another sign-in document, or without the document they ask for first.', 'mainwp' ), implode( ', ', $named['warn'] ) );
        }
        return implode( ' ', $parts );
    }

    /**
     * The server rule that sends the root well-known forms for a subdirectory install to it.
     * nginx was tested; Apache wasn't.
     *
     * @param string $path Home path, such as `/sub`.
     * @return string
     */
    private static function subdirectory_rule( string $path ): string {
        $pattern = '^/\.well-known/(oauth-authorization-server|openid-configuration)' . $path . '(/.*)?$';
        return sprintf(
            /* translators: 1: nginx directive, 2: Apache rule. */
            __( 'nginx (tested), in the server block before the locations: %1$s Apache (untested), in the root .htaccess file: %2$s', 'mainwp' ),
            'rewrite ' . $pattern . ' ' . $path . '/index.php last;',
            'RewriteRule ' . substr( $pattern, 0, 1 ) . substr( $pattern, 2 ) . ' ' . $path . '/index.php [L]'
        );
    }

    /**
     * The check from the internet: one request to the checker carrying only the canonical
     * home, sent only when a user clicks. Nothing is stored.
     *
     * @return array Lines, each `id`, `status`, `message`, `fix` and `help`.
     */
    public function external_check(): array {
        $home = $this->services->urls()->base();
        if ( null === $home || strlen( $home ) > 512 ) {
            return array( self::line( 'external', 'fail', self::EXTERNAL_GENERIC ) );
        }
        $response = wp_safe_remote_post(
            $this->bridge_url() . '/reachability/check',
            array(
                'user-agent'  => 'MainWP Remote MCP',
                'timeout'     => 30,
                'redirection' => 0,
                'headers'     => array( 'Content-Type' => 'application/json' ),
                'cookies'     => array(),
                'body'        => (string) wp_json_encode(
                    array(
                        'schema'        => 1,
                        'dashboard_url' => $home,
                    )
                ),
            )
        );
        $body     = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $checks   = is_array( $body ) && 1 === ( $body['schema'] ?? null ) && isset( $body['checks'] ) && is_array( $body['checks'] ) ? $body['checks'] : null;
        if ( null === $checks || array_column( $checks, 'id' ) !== self::EXTERNAL_CHECKS || count( $checks ) !== count( self::EXTERNAL_CHECKS ) ) {
            return array( self::line( 'external', 'fail', self::EXTERNAL_GENERIC ) );
        }
        $pass_messages = array(
            'dns'       => __( 'DNS: Your Dashboard\'s domain resolves to a public IP address.', 'mainwp' ),
            'tls'       => __( 'TLS: Your Dashboard accepts a secure connection with a valid certificate.', 'mainwp' ),
            'discovery' => __( 'Discovery: Apps can reach the well-known sign-in discovery address.', 'mainwp' ),
            'metadata'  => __( 'Metadata: The sign-in information contains the required fields and matches your Dashboard\'s address.', 'mainwp' ),
            'mcp_401'   => __( 'MCP sign-in: The MCP address returns HTTP 401 with a valid sign-in request.', 'mainwp' ),
        );
        $check_labels  = array(
            'dns'       => __( 'DNS', 'mainwp' ),
            'tls'       => __( 'TLS', 'mainwp' ),
            'discovery' => __( 'Discovery', 'mainwp' ),
            'metadata'  => __( 'Metadata', 'mainwp' ),
            'mcp_401'   => __( 'MCP sign-in', 'mainwp' ),
        );
        $lines         = array();
        foreach ( $checks as $check ) {
            $status = $check['status'] ?? null;
            $code   = $check['code'] ?? null;
            if ( ! in_array( $status, array( 'pass', 'warn', 'fail', 'skipped' ), true ) || ( null !== $code && ! is_string( $code ) ) ) {
                return array( self::line( 'external', 'fail', self::EXTERNAL_GENERIC ) );
            }
            if ( null === $code ) {
                if ( 'pass' === $status ) {
                    $message = $pass_messages[ $check['id'] ];
                } else {
                    $descriptions = array(
                        'warn'    => __( 'The check returned a warning without further details.', 'mainwp' ),
                        'fail'    => __( 'The check failed without further details.', 'mainwp' ),
                        'skipped' => __( 'The check was skipped.', 'mainwp' ),
                    );
                    /* translators: 1: check name, 2: result description. */
                    $message = sprintf( __( '%1$s: %2$s', 'mainwp' ), $check_labels[ $check['id'] ], $descriptions[ $status ] );
                }
            } else {
                $message = self::EXTERNAL_CODES[ $code ] ?? self::EXTERNAL_GENERIC;
            }
            $lines[] = self::line( (string) $check['id'], $status, $message, '', array( 'help' => in_array( $status, array( 'warn', 'fail' ), true ) ? Remote_MCP_Host_Detection::help_url() : null ) );
        }
        return $lines;
    }

    /**
     * Reply to a probe of this run, before the front controller sees it (parse_request priority 0).
     *
     * @param mixed $wp WordPress request.
     */
    public function answer_probe( $wp ): void {
        if ( ! $wp instanceof \WP ) {
            return;
        }
        $response = $this->probe_response( $wp, wp_unslash( $_GET ), wp_unslash( $_SERVER ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the probe token is the check; nothing changes state.
        if ( null !== $response ) {
            $this->emit( $response );
        }
    }

    /**
     * The probe reply for a request, or null when it isn't a live probe of a module endpoint.
     *
     * @param \WP   $wp     WordPress request.
     * @param array $get    Query parameters, unslashed.
     * @param array $server Server variables, unslashed.
     * @return Remote_MCP_Response|null
     */
    public function probe_response( \WP $wp, array $get, array $server ): ?Remote_MCP_Response {
        $token = isset( $get[ self::PROBE_VAR ] ) && is_string( $get[ self::PROBE_VAR ] ) ? $get[ self::PROBE_VAR ] : '';
        if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) || ! get_transient( self::probe_key( $token ) ) ) {
            return null;
        }
        $slot = ( new MCP_Front_Controller( $this->services ) )->match( $wp );
        if ( 'route_as_metadata' === $slot || 'route_openid' === $slot ) {
            return Remote_MCP_Response::json( 200, array( 'issuer' => $this->services->urls()->issuer() ) );
        }
        if ( 'route_mcp' === $slot ) {
            return Remote_MCP_Response::json(
                200,
                array(
                    'https'         => is_ssl(),
                    'authorization' => hash_equals( 'Bearer ' . $token, self::authorization_header( $server ) ),
                )
            );
        }
        return null;
    }

    /**
     * The Authorization header: HTTP_AUTHORIZATION, then a REDIRECT_ copy, then getallheaders().
     *
     * @param array $server Server variables.
     * @return string
     */
    private static function authorization_header( array $server ): string {
        if ( isset( $server['HTTP_AUTHORIZATION'] ) && is_string( $server['HTTP_AUTHORIZATION'] ) ) {
            return $server['HTTP_AUTHORIZATION'];
        }
        foreach ( $server as $key => $value ) {
            if ( is_string( $value ) && 1 === preg_match( '/^(REDIRECT_)+HTTP_AUTHORIZATION$/', (string) $key ) ) {
                return $value;
            }
        }
        if ( function_exists( 'getallheaders' ) ) {
            foreach ( (array) getallheaders() as $name => $value ) {
                if ( 0 === strcasecmp( (string) $name, 'Authorization' ) && is_string( $value ) ) {
                    return $value;
                }
            }
        }
        return '';
    }

    /**
     * Send a probe reply and stop.
     *
     * @param Remote_MCP_Response $response Response.
     */
    protected function emit( Remote_MCP_Response $response ): void {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        if ( ! headers_sent() ) {
            status_header( $response->status() );
            nocache_headers();
            foreach ( $response->headers() as $name => $value ) {
                header( $name . ': ' . $value );
            }
        }
        echo $response->body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON body built here.
        exit;
    }

    /**
     * Platform requirements; a test subclass replaces them.
     *
     * @return array As Remote_MCP_Requirements::evaluate().
     */
    protected function requirements(): array {
        return Remote_MCP_Requirements::current();
    }

    /**
     * Whether the activity log records calls; a test subclass replaces it, since the suite
     * runs with the logs module off.
     *
     * @return bool
     */
    protected function activity_log_available(): bool {
        return Remote_MCP_Settings::activity_log_available();
    }

    /**
     * What a non-443 home means; a test subclass replaces it.
     *
     * @return string `warn` or `refuse`.
     */
    protected function non_443_policy(): string {
        return Remote_MCP_Urls::NON_443_POLICY;
    }

    /**
     * Fetch one well-known URL.
     *
     * @param string $url URL.
     * @return array|\WP_Error
     */
    private function get( string $url ) {
        return wp_remote_get(
            $url,
            array(
                'timeout'     => self::PROBE_TIMEOUT,
                'redirection' => 0,
                'sslverify'   => $this->sslverify(),
                'headers'     => array( 'Accept' => 'application/json' ),
            )
        );
    }

    /**
     * Whether a fetch is ours: a 200 JSON object whose issuer is ours. Any other 200 JSON object is
     * foreign, and a 200 that isn't JSON is something other than WordPress answering.
     *
     * @param array|\WP_Error $response Response.
     * @param string          $issuer   Our issuer.
     * @return array `class` (ours|foreign|none) and `static`.
     */
    private function classify( $response, string $issuer ): array {
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return array(
                'class'  => 'none',
                'static' => false,
            );
        }
        $json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $object = is_array( $json ) && ( array() === $json || ! array_is_list( $json ) );
        if ( ! $object ) {
            return array(
                'class'  => 'none',
                'static' => true,
            );
        }
        return array(
            'class'  => isset( $json['issuer'] ) && is_string( $json['issuer'] ) && $json['issuer'] === $issuer ? 'ours' : 'foreign',
            'static' => false,
        );
    }

    /**
     * A well-known URL: path-insert puts the document before the home path, path-append after.
     *
     * @param string $document Document name.
     * @param string $form     `insert` or `append`.
     * @return string
     */
    private function well_known_url( string $document, string $form ): string {
        $urls   = $this->services->urls();
        $base   = (string) $urls->base();
        $path   = $urls->path();
        $origin = substr( $base, 0, strlen( $base ) - strlen( $path ) );
        return 'insert' === $form ? $origin . '/.well-known/' . $document . $path : $base . '/.well-known/' . $document;
    }

    /**
     * TLS verification for loopback probes. Core's own loopback checks use this filter, because a
     * site often can't verify its own certificate from inside (a local CA, a proxy's certificate);
     * the probes carry no credential.
     *
     * @return bool
     */
    private function sslverify(): bool {
        return (bool) apply_filters( 'https_local_ssl_verify', false );
    }

    /**
     * The checker base URL.
     *
     * @return string
     */
    private function bridge_url(): string {
        if ( defined( 'MAINWP_ASSISTANT_BRIDGE_URL' ) && is_string( MAINWP_ASSISTANT_BRIDGE_URL ) && 0 === strpos( MAINWP_ASSISTANT_BRIDGE_URL, 'https://' ) ) {
            return rtrim( MAINWP_ASSISTANT_BRIDGE_URL, '/' );
        }
        return self::BRIDGE_URL;
    }

    /**
     * Transient name of a probe token, from its hash so the token itself isn't stored.
     *
     * @param string $token Probe token.
     * @return string
     */
    private static function probe_key( string $token ): string {
        return self::PROBE_TRANSIENT . substr( hash( 'sha256', $token ), 0, 32 );
    }

    /**
     * One line.
     *
     * @param string $id      Check id.
     * @param string $status  pass, warn, fail or info.
     * @param string $message Text, unescaped.
     * @param string $fix     Fix text, unescaped.
     * @param array  $extra   Extra fields.
     * @return array
     */
    private static function line( string $id, string $status, string $message, string $fix = '', array $extra = array() ): array {
        return array_merge(
            array(
                'id'      => $id,
                'status'  => $status,
                'message' => $message,
                'fix'     => $fix,
                'help'    => null,
            ),
            $extra
        );
    }
}
