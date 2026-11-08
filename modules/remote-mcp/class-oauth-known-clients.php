<?php
/**
 * Remote MCP known clients.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Client lookup across bundled and fetched CIMD documents, static clients, registered apps,
 * admin-added web apps and connection tokens. Only authorize may fetch an uncached document,
 * so token and MCP requests work even when the document server is unavailable.
 */
class OAuth_Known_Clients implements Known_Clients {

    /**
     * Bundled clients, keyed by exact client_id: the file under known-clients/, the consent
     * headline, the UTC fetch date and SHA-256 of the file as fetched, how a dashboard-tier
     * approval reaches the client and how a write call on a read grant is answered.
     *
     * An entry with no hash has no bundled file yet, so its client is refused like any document
     * that fails its checks. Extending or changing the list is a release, never a setting.
     *
     * @var array
     */
    const REGISTRY = array(
        'https://claude.ai/oauth/mcp-oauth-client-metadata' => array(
            'file'              => 'claude.json',
            'label'             => 'Claude',
            'fetched'           => '2026-10-20',
            'sha256'            => '049f8cf53fbaaa902e71402620e96269a8973917b90e69c98b0edccfb9b07f79',
            'approval_delivery' => 'result',
            'step_up'           => 'challenge',
        ),
        'https://claude.ai/oauth/claude-code-client-metadata' => array(
            'file'              => 'claude-code.json',
            'label'             => 'Claude Code',
            'fetched'           => '2026-10-20',
            'sha256'            => '844b69e632732c325e3bae21ab324fa3d5353ba2e15890a384e8885e9837e5a4',
            'approval_delivery' => 'elicitation',
            'step_up'           => 'challenge',
        ),
        'https://chatgpt.com/oauth/client.json'            => array(
            'file'              => 'chatgpt.json',
            'label'             => 'ChatGPT',
            'fetched'           => '2026-10-20',
            'sha256'            => '5b62ad00a6d2562dda7b8e373611c41e09967518f5fc0824b243a8e1a29f915e',
            'approval_delivery' => 'result',
            'step_up'           => 'chatgpt_meta',
        ),
        'https://vscode.dev/oauth/client-metadata.json'    => array(
            'file'              => 'vscode.json',
            'label'             => 'VS Code',
            'fetched'           => '2026-10-20',
            'sha256'            => 'ef2f099373c6417e4e7753e38d3832489ca2e3797a0a60cc4ae82a4095442ad2',
            'approval_delivery' => 'elicitation',
            'step_up'           => 'challenge',
        ),
        'https://chatgpt.com/oauth/codex/client.json'      => array(
            'file'              => 'codex-cli.json',
            'label'             => 'Codex CLI',
            'fetched'           => '2026-10-20',
            'sha256'            => '88c1677cc7dc907c07184571bba9ecaa342904424823d883270f9c8f62940004',
            'approval_delivery' => 'result',
            'step_up'           => 'result',
        ),
        'https://zed.dev/oauth/client-metadata.json'       => array(
            'file'              => 'zed.json',
            'label'             => 'Zed',
            'fetched'           => '2026-10-20',
            'sha256'            => 'f7f022a2c2a6be9d3dbadb6914a85bd3347ec0c438dce883f72d0d44d3a1c967',
            'approval_delivery' => 'result',
            'step_up'           => 'result',
        ),
        'https://goose-docs.ai/oauth/client-metadata.json' => array(
            'file'              => 'goose.json',
            'label'             => 'Goose',
            'fetched'           => '2026-10-20',
            'sha256'            => '7f6cb1cf0fd391c1e2c1e732da91dbacf23215b5ae29609fa185e1fa28d8925e',
            'approval_delivery' => 'result',
            'step_up'           => 'result',
        ),
    );

    /**
     * Pre-registered public clients with fixed redirects. The vendors' documented callbacks are
     * rechecked at each release refresh.
     *
     * @var array
     */
    const STATIC_CLIENTS = array(
        'mainwp-cursor'     => array(
            'label'         => "Cursor, or another program using Cursor's settings",
            'redirect_rule' => 'cursor_exact',
            'redirect_uris' => array( 'http://localhost:8787/callback', 'https://www.cursor.com/agents/mcp/oauth/callback' ),
        ),
        'mainwp-gemini-cli' => array(
            'label'         => "Gemini CLI, or another program on your computer using Gemini CLI's settings",
            'redirect_rule' => 'gemini_pattern',
            'redirect_uris' => array(),
        ),
    );

    /**
     * Headline label of a registered app; its own name is only shown as unchecked.
     *
     * @var string
     */
    const REGISTERED_LABEL = 'An app on this computer';

    /**
     * Label of the internal connection-token kind.
     *
     * @var string
     */
    const TOKEN_LABEL = 'Connection token';

    /**
     * How long a failing bundled file stays quiet in the log after it was logged.
     * The lookup can run before login, so anyone could otherwise fill the log.
     *
     * @var int
     */
    const INVALID_LOG_INTERVAL = DAY_IN_SECONDS;

    /**
     * Registry.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Documents checked in this request, by client_id: the validation result, or a failure
     * reason string. Redirect rechecks must see the same document as the initial lookup.
     *
     * @var array
     */
    private $loaded = array();

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services Registry.
     */
    public function __construct( Remote_MCP_Services $services ) {
        $this->services = $services;
    }

    /**
     * Descriptor for an exact client_id, compared byte for byte.
     *
     * @param string $client_id Presented client_id.
     * @param bool   $fetch     Whether authorize may fetch an uncached document.
     * @return array|null Null for an unknown, invalid or switched-off client.
     */
    public function lookup( string $client_id, bool $fetch = false ): ?array {
        if ( OAuth_Store::CONNECTION_TOKEN_CLIENT_ID === $client_id ) {
            return $this->descriptor( $client_id, 'token', self::TOKEN_LABEL, null, 'exact', array(), array() );
        }
        $registry = $this->registry();
        if ( isset( $registry[ $client_id ] ) ) {
            return $this->bundled( $client_id, $registry[ $client_id ] );
        }
        if ( isset( static::STATIC_CLIENTS[ $client_id ] ) ) {
            if ( ! Remote_MCP_Settings::static_client_enabled( $client_id ) ) {
                return null;
            }
            $static = static::STATIC_CLIENTS[ $client_id ];
            return $this->descriptor( $client_id, 'static', $static['label'], null, $static['redirect_rule'], $static['redirect_uris'], array( 'none' ) );
        }
        if ( ! preg_match( '/^(mwplc|mwpwa)_[A-Za-z0-9_-]{43}\z/', $client_id, $prefix ) ) {
            return $this->fetched( $client_id, $fetch );
        }
        $store = $this->services->store();
        $row   = $store ? $store->get_client( $client_id ) : null;
        $kind  = 'mwplc' === $prefix[1] ? 'registered' : 'webapp';
        if ( null === $row || $kind !== $row['kind'] || 'active' !== $row['status'] ) {
            return null;
        }
        $uris = array_values( array_filter( $row['redirect_uris'], 'is_string' ) );
        $name = is_string( $row['client_name'] ) && '' !== $row['client_name'] ? $row['client_name'] : null;
        if ( 'registered' === $kind ) {
            return $this->descriptor( $client_id, 'registered', self::REGISTERED_LABEL, $name, 'registered_loopback', $uris, array( 'none' ) );
        }
        if ( null === $name ) {
            return null;
        }
        return $this->descriptor( $client_id, 'webapp', $name, $name, 'exact', $uris, array( 'client_secret_basic', 'client_secret_post' ) );
    }

    /**
     * Fetched clients retain the bundled kind because existing grants store HTTPS ids that way.
     *
     * @param string $client_id Client id.
     * @param bool   $fetch     Whether authorize may fetch.
     * @return array|null
     */
    private function fetched( string $client_id, bool $fetch ): ?array {
        if ( ! Remote_MCP_Settings::registration_enabled() || ! self::document_url_ok( $client_id ) ) {
            return null;
        }
        $key    = 'mainwp_remote_mcp_cimd_' . md5( $client_id );
        $loaded = $this->loaded[ $client_id ] ?? get_transient( $key );
        if ( false === $loaded && $fetch ) {
            $loaded = $this->fetch_document( $client_id );
            if ( is_array( $loaded ) ) {
                set_transient( $key, $loaded, HOUR_IN_SECONDS );
            }
        }
        if ( false !== $loaded ) {
            $this->loaded[ $client_id ] = $loaded;
            if ( ! is_array( $loaded ) ) {
                return null;
            }
        }
        $uris = is_array( $loaded ) ? $loaded['redirect_uris'] : array();
        $rule = 'exact';
        foreach ( $uris as $uri ) {
            if ( null !== self::loopback_parts( $uri ) ) {
                $rule = 'loopback_any_port';
            }
        }
        $client            = $this->descriptor( $client_id, 'bundled', (string) wp_parse_url( $client_id, PHP_URL_HOST ), is_array( $loaded ) ? $loaded['client_name'] : null, $rule, $uris, array( 'none' ) );
        $client['fetched'] = true;
        return $client;
    }

    /**
     * The storage limit applies before HTTP because every OAuth row must hold the same id.
     *
     * @param string $client_id Client id.
     * @return bool
     */
    private static function document_url_ok( string $client_id ): bool {
        if ( strlen( $client_id ) > 512 || 0 !== strpos( $client_id, 'https://' ) || preg_match( '/[\\\\\x00-\x20\x7f]/', $client_id ) ) {
            return false;
        }
        $parts = wp_parse_url( $client_id );
        return is_array( $parts )
            && ! empty( $parts['host'] )
            && ! empty( $parts['path'] )
            && ! isset( $parts['user'] )
            && ! isset( $parts['pass'] )
            && ! isset( $parts['fragment'] )
            && ( ! isset( $parts['port'] ) || 443 === $parts['port'] )
            && ! preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', rawurldecode( $parts['path'] ) );
    }

    /**
     * Core's safe HTTP client rejects private destinations. Redirects stay disabled because a
     * document must come from its client_id URL, without a second destination to trust.
     *
     * @param string $client_id Client id.
     * @return array|string Validated document or a failure marker for this request only.
     */
    private function fetch_document( string $client_id ) {
        $remote  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hashed for the limiter, never output or used as a URL.
        $mapped  = apply_filters( 'mainwp_remote_mcp_client_ip', $remote );
        $ip      = is_string( $mapped ) && false !== filter_var( $mapped, FILTER_VALIDATE_IP ) ? $mapped : $remote;
        $limiter = new OAuth_Registration_Rate_Limiter( 'mainwp_remote_mcp_cimdrl_' );
        if ( 0 !== $limiter->retry_after( $ip ) ) {
            return 'fetch_failed';
        }
        $limiter->record( $ip );
        $response = wp_safe_remote_get(
            $client_id,
            array(
                'redirection'         => 0,
                'timeout'             => 5,
                'limit_response_size' => 16385,
                'headers'             => array( 'Accept' => 'application/json' ),
            )
        );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return 'fetch_failed';
        }
        // A repeated Content-Type header comes back as an array; a document has one type.
        $header = wp_remote_retrieve_header( $response, 'content-type' );
        $type   = is_string( $header ) ? trim( explode( ';', $header, 2 )[0] ) : '';
        $body   = wp_remote_retrieve_body( $response );
        // WordPress truncates at the response limit without an error, so request one extra byte.
        if ( ! preg_match( '/^application\/(?:json|[!#$%&\'*+.^_`|~0-9a-z-]+\+json)$/i', $type ) || strlen( $body ) > 16384 ) {
            return 'fetch_failed';
        }
        $checked = OAuth_Client_Document::validate( $body, $client_id );
        return $checked['ok'] ? $checked : 'fetch_failed';
    }

    /**
     * Whether a presented redirect URI matches the client under its kind's rule,
     * compared on the raw string. The client is looked up again by its id, so a descriptor the
     * caller built or kept from before a switch change can't widen the rule.
     *
     * @param array  $client       Descriptor from lookup().
     * @param string $redirect_uri Presented redirect_uri.
     * @return bool
     */
    public function redirect_allowed( array $client, string $redirect_uri ): bool {
        $client = isset( $client['client_id'] ) && is_string( $client['client_id'] ) ? $this->lookup( $client['client_id'] ) : null;
        if ( null === $client || ! $client['oauth_endpoints'] || ! self::raw_syntax_ok( $redirect_uri ) ) {
            return false;
        }
        switch ( $client['kind'] ) {
            case 'bundled':
                if ( 0 === strpos( $redirect_uri, 'https://' ) ) {
                    return in_array( $redirect_uri, $client['redirect_uris'], true );
                }
                $presented = self::loopback_parts( $redirect_uri );
                if ( null === $presented ) {
                    return false;
                }
                // RFC 8252 section 7.3: the port is ignored, scheme, host, path and query are not.
                foreach ( $client['redirect_uris'] as $entry ) {
                    $registered = self::loopback_parts( $entry );
                    if ( null !== $registered && $registered['host'] === $presented['host'] && $registered['rest'] === $presented['rest'] ) {
                        return true;
                    }
                }
                return false;
            case 'static':
                if ( ! self::strict_chars_ok( $redirect_uri ) ) {
                    return false;
                }
                if ( 'gemini_pattern' === $client['redirect_rule'] ) {
                    return 1 === preg_match( '#^http://localhost:([0-9]{1,5})/oauth/callback\z#', $redirect_uri, $port ) && self::port_ok( $port[1] );
                }
                return in_array( $redirect_uri, $client['redirect_uris'], true );
            case 'registered':
                if ( ! $this->validate_redirect( 'registered', $redirect_uri ) ) {
                    return false;
                }
                $presented = self::without_port( $redirect_uri );
                foreach ( $client['redirect_uris'] as $entry ) {
                    if ( null !== $presented && self::without_port( $entry ) === $presented ) {
                        return true;
                    }
                }
                return false;
            case 'webapp':
                return $this->validate_redirect( 'webapp', $redirect_uri ) && in_array( $redirect_uri, $client['redirect_uris'], true );
        }
        return false;
    }

    /**
     * Whether a redirect URI may be stored for a client of this kind: the registration grammar
     * or the web-app rule. redirect_allowed() applies the same check at authorize,
     * so a URI accepted here is one authorize accepts.
     *
     * @param string $kind         `registered` or `webapp`.
     * @param string $redirect_uri Redirect URI as submitted.
     * @return bool
     */
    public function validate_redirect( string $kind, string $redirect_uri ): bool {
        if ( ! self::raw_syntax_ok( $redirect_uri ) || ! self::strict_chars_ok( $redirect_uri ) ) {
            return false;
        }
        if ( 'registered' === $kind ) {
            return 1 === preg_match( '#^http://(?:127\.0\.0\.1|\[::1\]|localhost)(?::([0-9]{1,5}))?(?:/[A-Za-z0-9._~/-]*)?\z#', $redirect_uri, $port )
                && ( ! isset( $port[1] ) || self::port_ok( $port[1] ) );
        }
        if ( 'webapp' === $kind ) {
            return false === strpos( $redirect_uri, '*' ) && OAuth_Client_Document::https_redirect_syntax_ok( $redirect_uri );
        }
        return false;
    }

    /**
     * Bundled clients whose file fails to load, by client_id, with the reason. The self-test
     * names them.
     *
     * @return array
     */
    public function bundled_errors(): array {
        $errors = array();
        foreach ( $this->registry() as $client_id => $entry ) {
            $loaded = $this->load( $client_id, $entry );
            if ( is_string( $loaded ) ) {
                $errors[ $client_id ] = $loaded;
            }
        }
        return $errors;
    }

    /**
     * Bundled registry. A seam for tests, which point it at fixture documents.
     *
     * @return array
     */
    protected function registry(): array {
        return static::REGISTRY;
    }

    /**
     * Raw bytes of a bundled document.
     *
     * @param string $file File name under known-clients/.
     * @return string|null
     */
    protected function read_document( string $file ): ?string {
        $path = __DIR__ . '/known-clients/' . $file;
        if ( ! is_readable( $path ) ) {
            return null;
        }
        $raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file shipped with the plugin.
        return false === $raw ? null : $raw;
    }

    /**
     * Descriptor of a bundled client, or null when its file fails.
     *
     * @param string $client_id Client id.
     * @param array  $entry     Registry entry.
     * @return array|null
     */
    private function bundled( string $client_id, array $entry ): ?array {
        $loaded = $this->load( $client_id, $entry );
        if ( ! is_array( $loaded ) ) {
            return null;
        }
        $rule = 'exact';
        foreach ( $loaded['redirect_uris'] as $uri ) {
            if ( null !== self::loopback_parts( $uri ) ) {
                $rule = 'loopback_any_port';
            }
        }
        return $this->descriptor( $client_id, 'bundled', $entry['label'], $loaded['client_name'], $rule, $loaded['redirect_uris'], array( 'none' ), $entry['approval_delivery'], $entry['step_up'] );
    }

    /**
     * Read, hash-check and validate a bundled document once per request.
     *
     * @param string $client_id Client id.
     * @param array  $entry     Registry entry.
     * @return array|string Validation result, or a failure reason.
     */
    private function load( string $client_id, array $entry ) {
        if ( ! isset( $this->loaded[ $client_id ] ) ) {
            $raw = is_string( $entry['sha256'] ?? null ) ? $this->read_document( (string) $entry['file'] ) : null;
            if ( null === $raw ) {
                $result = 'unreadable';
            } elseif ( ! hash_equals( $entry['sha256'], hash( 'sha256', $raw ) ) ) {
                // A partial upgrade or a hand-edited file. Not a security boundary: anyone who can
                // write plugin files already owns the site.
                $result = 'hash_mismatch';
            } else {
                $checked = OAuth_Client_Document::validate( $raw, $client_id );
                $result  = $checked['ok'] ? $checked : (string) $checked['reason'];
            }
            if ( is_string( $result ) ) {
                $this->log_invalid( $client_id, (string) $entry['file'], $result );
            }
            $this->loaded[ $client_id ] = $result;
        }
        return $this->loaded[ $client_id ];
    }

    /**
     * Log a failing bundled file at most once per interval per file.
     *
     * @param string $client_id Client id.
     * @param string $file      File name.
     * @param string $reason    Reason code.
     */
    private function log_invalid( string $client_id, string $file, string $reason ): void {
        $key = 'mainwp_remote_mcp_kc_' . md5( $file );
        if ( false !== get_transient( $key ) ) {
            return;
        }
        set_transient( $key, 1, self::INVALID_LOG_INTERVAL );
        Remote_MCP_Events::audit(
            'known_client_invalid',
            array(
                'client_host' => (string) wp_parse_url( $client_id, PHP_URL_HOST ),
                'reason'      => $reason,
            )
        );
    }

    /**
     * Build a descriptor in the interface's shape.
     *
     * @param string      $client_id    Client id.
     * @param string      $kind         Kind.
     * @param string      $label        Headline label.
     * @param string|null $client_name  The app's own name.
     * @param string      $rule         Redirect rule.
     * @param array       $uris         Redirect URIs.
     * @param array       $auth_methods Token endpoint auth methods.
     * @param string      $delivery     Approval delivery.
     * @param string      $step_up      Step-up form.
     * @return array
     */
    private function descriptor( string $client_id, string $kind, string $label, ?string $client_name, string $rule, array $uris, array $auth_methods, string $delivery = 'result', string $step_up = 'result' ): array {
        return array(
            'client_id'                   => $client_id,
            'kind'                        => $kind,
            'label'                       => $label,
            'client_name'                 => $client_name,
            'redirect_rule'               => $rule,
            'redirect_uris'               => $uris,
            'token_endpoint_auth_methods' => $auth_methods,
            'oauth_endpoints'             => 'token' !== $kind,
            'approval_delivery'           => $delivery,
            'enabled'                     => true,
            'step_up'                     => $step_up,
        );
    }

    /**
     * Checks every kind shares: at most 2048 bytes, no fragment, no whitespace or control
     * character.
     *
     * @param string $uri Redirect URI.
     * @return bool
     */
    private static function raw_syntax_ok( string $uri ): bool {
        return '' !== $uri && strlen( $uri ) <= OAuth_Client_Document::MAX_URI_BYTES && false === strpos( $uri, '#' ) && ! preg_match( '/[\x00-\x20\x7f]/', $uri );
    }

    /**
     * The refusal run before any comparison for static, registered and web-app clients: no
     * userinfo, backslash or percent-encoding, which parsers disagree about.
     *
     * @param string $uri Redirect URI.
     * @return bool
     */
    private static function strict_chars_ok( string $uri ): bool {
        return ! preg_match( '/[@\\\\%]|[^\x21-\x7e]/', $uri );
    }

    /**
     * Host and everything after the port of a loopback `http` URI, from the raw string.
     *
     * @param string $uri URI.
     * @return array|null `host`, `rest` (path and query); null when not loopback http.
     */
    private static function loopback_parts( string $uri ): ?array {
        if ( ! preg_match( '#^http://(127\.0\.0\.1|\[::1\]|localhost)(?::([0-9]{1,5}))?([/?].*)?\z#s', $uri, $parts ) ) {
            return null;
        }
        if ( isset( $parts[2] ) && '' !== $parts[2] && ! self::port_ok( $parts[2] ) ) {
            return null;
        }
        return array(
            'host' => $parts[1],
            'rest' => $parts[3] ?? '',
        );
    }

    /**
     * A loopback URI with its port removed, for the registered-app comparison (RFC 8252 7.3, 8.4).
     *
     * @param string $uri URI.
     * @return string|null
     */
    private static function without_port( string $uri ): ?string {
        $parts = self::loopback_parts( $uri );
        return null === $parts ? null : 'http://' . $parts['host'] . $parts['rest'];
    }

    /**
     * Whether a port string is 1-65535.
     *
     * @param string $port Digits.
     * @return bool
     */
    private static function port_ok( string $port ): bool {
        return (int) $port >= 1 && (int) $port <= 65535;
    }
}
