<?php
/**
 * Connect/sync failure presentation (MWP-1788).
 *
 * Labels Cloudflare/proxy edge IPs separately from the Dashboard outbound IP,
 * always exposes the MainWP User-Agent, and formats a readable child/HTTP
 * response. Does not change connection auth, timeouts, or redirects.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Connect_Failure_Ux
 *
 * @package MainWP\Dashboard
 */
class MainWP_Connect_Failure_Ux { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Response headers that help identify WAF / proxy / auth blocks.
     *
     * @var array<int,string>
     */
    const NOTICE_HEADERS = array(
        'cf-ray',
        'cf-mitigated',
        'cf-cache-status',
        'server',
        'x-sucuri-id',
        'www-authenticate',
        'x-cache',
        'x-cache-status',
        'content-type',
        'location',
    );

    /**
     * Query/body parameter names whose values must not appear in failure diagnostics.
     *
     * Compared case-insensitively. Names ending in _token, _code, _key, _nonce,
     * _secret, _signature, _password, _passwd, _pwd, or _pass are also treated as sensitive.
     * password, passwd, and pwd match without an underscore (adminpassword).
     * key, code, token, nonce, and secret still require the underscore.
     *
     * @var array<int,string>
     */
    const SENSITIVE_STEMS = array( 'password', 'passwd', 'pwd', 'uniqueid', 'mainwpsignature', 'secret', 'token', 'nonce', 'apikey' );

    const SENSITIVE_PARAM_NAMES = array(
        'password',
        'passwd',
        'pwd',
        'pass',
        'pass1',
        'pass2',
        'adminpwd',
        'uniqueid',
        'unique_id',
        'privkey',
        'pubkey',
        'mainwpsignature',
        'mainwpsignature_adv',
        'authorization',
        'token',
        'code',
        'key',
        'nonce',
        'state',
        'signature',
        'secret',
        'sig',
        'hmac',
        'jwt',
        'bearer',
        'session',
        'rp_key',
        'rp_login',
        'reset_key',
        'resetpass',
        'reset_pass',
        'apikey',
        'api_key',
        'client_secret',
        'access_token',
        'id_token',
        'refresh_token',
        'oauth_token',
        'oauth_verifier',
        '_wpnonce',
    );

    /**
     * Parameter-name suffixes treated as sensitive (csrf_token, api_key, …).
     *
     * @var array<int,string>
     */
    const SENSITIVE_PARAM_SUFFIXES = array(
        'token',
        'code',
        'key',
        'nonce',
        'secret',
        'signature',
        'password',
        'passwd',
        'pwd',
        'pass',
    );

    /**
     * Suffixes that are sensitive even with no leading underscore.
     *
     * Password, passwd, and pwd are specific enough for adminpassword and
     * dbpassword. Key, code, token, nonce, and secret stay underscore-only
     * so ordinary words such as monkey and keycode stay visible.
     *
     * @var array<int,string>
     */
    const SENSITIVE_PARAM_BARE_SUFFIXES = array(
        'password',
        'passwd',
        'pwd',
    );

    /**
     * Markers that mean Cloudflare, another WAF, or a similar edge block.
     *
     * @var array<int,string>
     */
    const WAF_MARKERS = array(
        'cloudflare',
        'cf-ray',
        'cf-mitigated',
        'attention required',
        'sucuri',
        'wordfence',
        'imunify',
        'incapsula',
        'akamai',
        'mod_security',
        'modsecurity',
        'blocked by',
        'waf',
    );

    /**
     * User-Agent MainWP sends on connect/sync requests.
     *
     * @return string
     */
    public static function get_user_agent() {
        $version = isset( MainWP_System::$version ) ? MainWP_System::$version : '';
        return 'Mozilla/5.0 (compatible; MainWP/' . $version . '; +http://mainwp.com)';
    }

    /**
     * Dashboard outbound IP to allowlist (not a child/Cloudflare edge IP).
     *
     * @return string
     */
    public static function get_dashboard_outbound_ip() {
        if ( ! class_exists( __NAMESPACE__ . '\MainWP_Server_Information_Handler' ) ) {
            return '';
        }

        return MainWP_Server_Information_Handler::get_dashboard_outbound_ip();
    }

    /**
     * Normalize a resolved IP value to a display string.
     *
     * @param mixed $ip IP string or list from DNS helpers.
     *
     * @return string
     */
    public static function format_ip_list( $ip ) {
        if ( is_array( $ip ) ) {
            $parts = array();
            foreach ( $ip as $item ) {
                if ( self::is_ip_address( $item ) ) {
                    $parts[] = $item;
                }
            }
            return implode( ', ', $parts );
        }

        $value = is_scalar( $ip ) ? (string) $ip : '';

        return self::is_ip_address( $value ) ? $value : '';
    }

    /**
     * Whether a value is a usable IP (not a failed gethostbyname() hostname).
     *
     * @param mixed $value Candidate IP.
     *
     * @return bool
     */
    public static function is_ip_address( $value ) {
        return is_string( $value ) && '' !== $value && false !== filter_var( $value, FILTER_VALIDATE_IP );
    }

    /**
     * Whether the failure looks like HTTP 403 or a Cloudflare/WAF block.
     *
     * @param int    $http_status HTTP status.
     * @param string $headers     Raw or summarized response headers.
     * @param string $body        Raw or summarized response body.
     *
     * @return bool
     */
    public static function is_waf_likely( $http_status, $headers = '', $body = '' ) {
        if ( 403 === (int) $http_status ) {
            return true;
        }

        $haystack = strtolower( (string) $headers . "\n" . (string) $body );
        foreach ( self::WAF_MARKERS as $marker ) {
            if ( false !== strpos( $haystack, $marker ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract a short, safe headers snippet.
     *
     * @param string $raw_headers Raw response headers.
     *
     * @return string
     */
    public static function summarize_headers( $raw_headers ) {
        if ( ! is_string( $raw_headers ) || '' === $raw_headers ) {
            return '';
        }

        $lines  = preg_split( '/\r\n|\n|\r/', $raw_headers );
        $kept   = array();
        $wanted = array_map( 'strtolower', self::NOTICE_HEADERS );
        $status = '';

        foreach ( (array) $lines as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line ) {
                continue;
            }
            if ( 0 === stripos( $line, 'HTTP/' ) ) {
                $status = self::redact_urls_in_text( $line );
                if ( ! is_string( $status ) ) {
                    $status = self::unredactable_text();
                } elseif ( self::mentions_sensitive_name( $line ) || self::mentions_sensitive_name( $status ) ) {
                    $status = ( preg_match( '/^HTTP\/\S+\s+\d{3}/', $line, $code ) ? $code[0] . ' ' : '' ) . '[redacted]';
                }
                continue;
            }
            $parts = explode( ':', $line, 2 );
            if ( 2 !== count( $parts ) ) {
                continue;
            }
            $name = strtolower( trim( $parts[0] ) );
            if ( in_array( $name, $wanted, true ) ) {
                $kept[] = $name . ': ' . self::header_value_for_display( $parts[1], $name );
            }
        }

        $snippet = $status;
        if ( ! empty( $kept ) ) {
            $snippet .= ( '' !== $snippet ? "\n" : '' ) . implode( "\n", $kept );
        }

        return trim( $snippet );
    }

    /**
     * Preserve fixed Child credential guidance while hiding reflected secrets.
     *
     * @param string $message Decoded registration error.
     * @return string Escaped fixed guidance or conservatively redacted text.
     */
    public static function sanitize_registration_error( $message ) {
        if ( 'Unable to connect to the site. Please verify that your Admin Username and Password are correct and try again.' === $message ) {
            return esc_html__( 'Unable to connect to the site. Please verify that your Admin Username and Password are correct and try again.', 'mainwp' );
        }
        if ( 'Unable to connect to the site. Password Authentication is disabled, so please verify that the Unique Security ID is correct and try again.' === $message ) {
            return esc_html__( 'Unable to connect to the site. Password Authentication is disabled, so please verify that the Unique Security ID is correct and try again.', 'mainwp' );
        }

        // URL redaction can decode encoded markup after the caller's HTML filtering.
        return esc_html( self::sanitize_diagnostic_text( $message ) );
    }

    /**
     * Describe a received body without retaining its raw contents.
     *
     * @param mixed $body Received response.
     * @return string Safe description and, where possible, a short excerpt.
     */
    public static function describe_received_response( $body ) {
        if ( ! is_string( $body ) ) {
            return '';
        }
        $sample = substr( $body, 0, 4096 );
        if ( strlen( $body ) > 4096 && 1 !== preg_match( '//u', $sample ) ) {
            // Validate at most three more bytes, then keep only complete
            // characters inside the original cap. Invalid data stays binary.
            for ( $extra = 1; $extra <= 3; $extra++ ) {
                if ( 1 !== preg_match( '//u', substr( $body, 0, 4096 + $extra ) ) ) {
                    continue;
                }
                for ( $trim = 1; $trim <= 3; $trim++ ) {
                    $prefix = substr( $sample, 0, -$trim );
                    if ( 1 === preg_match( '//u', $prefix ) ) {
                        $sample = $prefix;
                        break;
                    }
                }
                break;
            }
        }
        if ( '' === trim( $sample ) ) {
            return __( 'Body: the site returned an empty response. Ask the host to check the registration request to wp-admin/admin-ajax.php.', 'mainwp' );
        }
        if ( false !== stripos( self::decode_diagnostic_text( $sample ), '<mainwp' ) ) {
            return __( 'Body: a MainWP response was found but could not be read. Ask the host to check whether a plugin or proxy altered the response.', 'mainwp' );
        }
        if ( false !== strpos( $sample, "\0" ) || "\x1f\x8b" === substr( $sample, 0, 2 ) || 1 !== preg_match( '//u', $sample ) ) {
            return __( 'Body: the site returned binary data, possibly encoded or compressed by the host. Ask the host to check response encoding for wp-admin/admin-ajax.php.', 'mainwp' );
        }

        // Check the capped raw markup too: stripping tags first would discard
        // sensitive field names while leaving their values in visible text.
        $safe = self::sanitize_diagnostic_text( $sample );
        if ( false !== stripos( self::decode_diagnostic_text( $sample ), 'cookie' ) ) {
            $safe = self::sensitive_text();
        }
        $text      = html_entity_decode( $safe, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text      = wp_strip_all_tags( $text, true );
        $text      = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
        $html      = false !== strpos( $sample, '<' ) && false !== strpos( $sample, '>' );
        $challenge = false !== stripos( $sample, 'wp-login' ) || false !== stripos( $sample, 'captcha' ) || false !== stripos( $sample, 'checking your browser' ) || false !== stripos( $sample, 'just a moment' );
        if ( $challenge ) {
            $description = __( 'Body: the site returned a login or challenge page instead of the MainWP response. Check the site firewall, security and caching plugins.', 'mainwp' );
        } elseif ( $html ) {
            $description = __( 'Body: the site returned an HTML page instead of the MainWP response. A firewall, security or caching plugin may have replaced it. Check those rules for wp-admin/admin-ajax.php.', 'mainwp' );
        } else {
            $description = __( 'Body: the site returned text instead of the MainWP response. Ask the host to check the registration request to wp-admin/admin-ajax.php.', 'mainwp' );
        }
        if ( $safe === $sample && preg_match( '/<title\b[^>]*>(.*?)<\/title\s*>/is', $sample, $title ) ) {
            $text = trim( wp_strip_all_tags( html_entity_decode( $title[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true ) );
        }
        $excerpt = wp_html_excerpt( $text, 200, '…' );
        if ( '' !== $excerpt ) {
            /* translators: %s: short redacted response excerpt. */
            $description .= "\n" . sprintf( __( 'Excerpt: %s', 'mainwp' ), $excerpt );
        }
        return $description;
    }

    /**
     * Build a readable response block with status and headers.
     *
     * @param int    $http_status HTTP status.
     * @param string $headers     Raw response headers.
     * @param string $body        Raw body; used only for the legacy presence check.
     * @param string $diagnostic  Capped, sanitized description from the transport.
     *
     * @return array<string,string>
     */
    public static function summarize_response( $http_status, $headers = '', $body = '', $diagnostic = '' ) {
        $status      = (int) $http_status;
        $status_text = $status > 0 ? (string) $status : '';
        $code_label  = $status > 0 ? MainWP_Utility::get_http_codes( $status ) : '';
        if ( '' !== $code_label ) {
            $status_text .= ' (' . $code_label . ')';
        }
        if ( '' === $status_text ) {
            $status_text = esc_html__( 'Unavailable', 'mainwp' );
        }

        $header_snip = self::summarize_headers( $headers );

        $lines = array(
            sprintf(
                /* translators: %s: HTTP status */
                esc_html__( 'Status: %s', 'mainwp' ),
                $status_text
            ),
        );

        if ( '' !== $header_snip ) {
            $lines[] = esc_html__( 'Headers:', 'mainwp' ) . "\n" . $header_snip;
        } else {
            $lines[] = esc_html__( 'Headers: none captured.', 'mainwp' );
        }

        // The full body is never displayed, but saying nothing came back would send
        // someone debugging a WAF or error page in the wrong direction. The
        // hidden-data placeholder does not prove a body arrived.
        if ( is_string( $diagnostic ) && '' !== $diagnostic ) {
            $lines[] = $diagnostic;
        } elseif ( is_string( $body ) && '' !== $body && '[hidden response data]' !== $body ) {
            $lines[] = esc_html__( 'Body: received but not shown, because it may contain sensitive data.', 'mainwp' );
        } else {
            $lines[] = esc_html__( 'Body: no readable child response was returned.', 'mainwp' );
        }

        return array(
            'status'      => $status_text,
            'http_status' => $status,
            'headers'     => $header_snip,
            'body'        => '',
            'text'        => implode( "\n\n", $lines ),
        );
    }

    /**
     * Build structured failure details for connect/sync UI.
     *
     * @param array $context {
     *     Optional context.
     *
     *     @type int    $http_status  HTTP status.
     *     @type string $headers      Raw headers.
     *     @type string $body         Raw body.
     *     @type mixed  $resolved_ip  Child/proxy resolved IP.
     *     @type string $error        Transport error.
     * }
     *
     * @return array<string,mixed>
     */
    public static function build_details( $context = array() ) {
        if ( ! is_array( $context ) ) {
            $context = array();
        }

        $http_status = isset( $context['http_status'] ) ? (int) $context['http_status'] : 0;
        $headers     = isset( $context['headers'] ) ? (string) $context['headers'] : '';
        $body        = isset( $context['body'] ) ? (string) $context['body'] : '';
        $resolved_ip = self::format_ip_list( isset( $context['resolved_ip'] ) ? $context['resolved_ip'] : '' );
        $diagnostic  = isset( $context['response_diagnostic'] ) && is_string( $context['response_diagnostic'] ) ? $context['response_diagnostic'] : '';
        $response    = self::summarize_response( $http_status, $headers, $body, $diagnostic );
        $waf_likely  = self::is_waf_likely( $http_status, $headers . "\n" . $response['headers'], $body . "\n" . $response['body'] );
        $lookup_ip   = empty( $context['skip_outbound_ip'] );

        return array(
            'http_status'           => $http_status,
            'waf_likely'            => $waf_likely,
            'dashboard_outbound_ip' => $lookup_ip ? self::get_dashboard_outbound_ip() : '',
            'user_agent'            => self::get_user_agent(),
            'resolved_ip'           => $resolved_ip,
            'resolved_ip_label'     => esc_html__( 'Cloudflare/proxy edge IP', 'mainwp' ),
            'error'                 => isset( $context['error'] ) ? (string) $context['error'] : '',
            'response'              => $response,
        );
    }

    /**
     * Build details from a MainWP exception plus fetch output.
     *
     * @param MainWP_Exception|null $exception Exception.
     * @param array                 $output    Fetch output.
     *
     * @return array<string,mixed>
     */
    public static function from_exception( $exception, $output = array() ) {
        if ( ! is_array( $output ) ) {
            $output = array();
        }

        $body                = '';
        $http_status         = 0;
        $headers             = '';
        $resolved_ip         = '';
        $legacy_text         = '';
        $has_response_fields = false;
        $diagnostic          = '';

        if ( $exception instanceof MainWP_Exception ) {
            $data = $exception->get_data();
            if ( is_array( $data ) ) {
                $has_response_fields = array_key_exists( 'headers', $data ) || array_key_exists( 'body', $data );
                if ( isset( $data['response_diagnostic'] ) && is_string( $data['response_diagnostic'] ) ) {
                    $diagnostic = $data['response_diagnostic'];
                }
                if ( isset( $data['http_status'] ) ) {
                    $http_status = (int) $data['http_status'];
                }
                if ( isset( $data['headers'] ) ) {
                    $headers = (string) $data['headers'];
                }
                if ( isset( $data['body'] ) ) {
                    $body = (string) $data['body'];
                }
                if ( isset( $data['resolved_ip'] ) ) {
                    $resolved_ip = $data['resolved_ip'];
                }
                if ( ! empty( $data['text'] ) ) {
                    $legacy_text = (string) $data['text'];
                }
            } elseif ( is_string( $data ) ) {
                $legacy_text = $data;
            }
        }

        if ( isset( $output['response_diagnostic'] ) && is_string( $output['response_diagnostic'] ) ) {
            $diagnostic = $output['response_diagnostic'];
        }

        if ( isset( $output['http_status'] ) && (int) $output['http_status'] > 0 ) {
            $http_status = (int) $output['http_status'];
        } elseif ( isset( $output['httpCode'] ) && (int) $output['httpCode'] > 0 ) {
            $http_status = (int) $output['httpCode'];
        }

        if ( array_key_exists( 'response_headers', $output ) ) {
            $has_response_fields = true;
            if ( '' !== (string) $output['response_headers'] ) {
                $headers = (string) $output['response_headers'];
            }
        }

        if ( array_key_exists( 'response_body', $output ) ) {
            $has_response_fields = true;
            if ( '' !== (string) $output['response_body'] ) {
                $body = (string) $output['response_body'];
            }
        } elseif ( array_key_exists( 'fetch_data', $output ) ) {
            $has_response_fields = true;
            if ( is_string( $output['fetch_data'] ) && '[hidden response data]' !== $output['fetch_data'] && '' === $body ) {
                $body = $output['fetch_data'];
            }
        }

        if ( ! $has_response_fields && isset( $output['response_readable'] ) && is_string( $output['response_readable'] ) ) {
            $legacy_text = $output['response_readable'];
        }

        if ( ! $has_response_fields && '' === $body && '' !== $legacy_text ) {
            $body = $legacy_text;
        }

        if ( isset( $output['resolved_ip'] ) ) {
            $resolved_ip = $output['resolved_ip'];
        } elseif ( isset( $output['ip'] ) && '' === self::format_ip_list( $resolved_ip ) ) {
            $resolved_ip = $output['ip'];
        }

        if ( $http_status <= 0 && is_string( $body ) && preg_match( '/Status:\s*(\d{3})/', $body, $status_match ) ) {
            $http_status = (int) $status_match[1];
        }

        return self::build_details(
            array(
                'http_status'         => $http_status,
                'response_diagnostic' => $diagnostic,
                'headers'             => $headers,
                'body'                => $body,
                'resolved_ip'         => $resolved_ip,
                'error'               => ( $exception instanceof MainWP_Exception ) ? $exception->getMessage() : '',
            )
        );
    }

    /**
     * Readable response text for View Response / resp_data.
     *
     * @param array                 $output    Fetch output.
     * @param MainWP_Exception|null $exception Exception.
     *
     * @return string
     */
    public static function readable_response_from_output( $output = array(), $exception = null ) {
        $details = self::from_exception( $exception, $output );
        return $details['response']['text'];
    }

    /**
     * HTML for connect/sync failure screens.
     *
     * @param array $details Details from build_details().
     *
     * @return string
     */
    public static function render_html( $details ) {
        if ( ! is_array( $details ) ) {
            $details = self::build_details();
        }

        $outbound = isset( $details['dashboard_outbound_ip'] ) ? (string) $details['dashboard_outbound_ip'] : '';
        $agent    = isset( $details['user_agent'] ) ? (string) $details['user_agent'] : self::get_user_agent();
        $edge_ip  = isset( $details['resolved_ip'] ) ? (string) $details['resolved_ip'] : '';
        $waf      = ! empty( $details['waf_likely'] );
        $response = isset( $details['response']['text'] ) ? (string) $details['response']['text'] : '';

        if ( '' === $outbound ) {
            $outbound = esc_html__( 'Unavailable. Check Info → Server for Public Dashboard IP, or ask your host.', 'mainwp' );
        }

        ob_start();
        ?>
        <div class="mainwp-connect-failure-ux">
            <?php if ( $waf ) : ?>
                <p><strong><?php esc_html_e( 'Cloudflare / WAF / User-Agent allow', 'mainwp' ); ?></strong></p>
                <ol>
                    <li><?php esc_html_e( 'In Cloudflare (Security → WAF) or your host WAF, allow the MainWP User-Agent shown below. IP allowlists alone are often not enough.', 'mainwp' ); ?></li>
                    <li><?php esc_html_e( 'If you allowlist IPs, allow the Dashboard outbound IP — not the Cloudflare/proxy edge IP.', 'mainwp' ); ?></li>
                    <li><?php esc_html_e( 'After the WAF allows this User-Agent, retry connect or sync. Reinstall MainWP Child only if those steps do not resolve it.', 'mainwp' ); ?></li>
                </ol>
            <?php endif; ?>
            <p><strong><?php esc_html_e( 'Dashboard outbound IP (allowlist this)', 'mainwp' ); ?>:</strong> <code><?php echo esc_html( $outbound ); ?></code></p>
            <p><strong><?php esc_html_e( 'User-Agent MainWP sends', 'mainwp' ); ?>:</strong> <code><?php echo esc_html( $agent ); ?></code></p>
            <?php if ( '' !== $edge_ip ) : ?>
                <p><strong><?php esc_html_e( 'Cloudflare/proxy edge IP (do not allowlist this as the Dashboard IP)', 'mainwp' ); ?>:</strong> <code><?php echo esc_html( $edge_ip ); ?></code></p>
            <?php endif; ?>
            <p><strong><?php esc_html_e( 'Child / HTTP response', 'mainwp' ); ?></strong></p>
            <pre><?php echo esc_html( $response ); ?></pre>
            <?php if ( ! $waf ) : ?>
                <p><?php esc_html_e( 'If the child site is behind Cloudflare or another WAF, allow this User-Agent there. Then reinstall or reactivate MainWP Child only if that does not resolve it.', 'mainwp' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Plain-text form for stored sync errors and tooltips.
     *
     * @param array $details Details from build_details().
     *
     * @return string
     */
    public static function format_text( $details ) {
        if ( ! is_array( $details ) ) {
            $details = self::build_details();
        }

        $outbound = isset( $details['dashboard_outbound_ip'] ) ? (string) $details['dashboard_outbound_ip'] : '';
        $agent    = isset( $details['user_agent'] ) ? (string) $details['user_agent'] : self::get_user_agent();
        $edge_ip  = isset( $details['resolved_ip'] ) ? (string) $details['resolved_ip'] : '';
        $waf      = ! empty( $details['waf_likely'] );
        $response = isset( $details['response']['text'] ) ? (string) $details['response']['text'] : '';

        if ( '' === $outbound ) {
            $outbound = __( 'Unavailable. Check Info → Server for Public Dashboard IP, or ask your host.', 'mainwp' );
        }

        $lines = array();
        if ( $waf ) {
            $lines[] = __( 'Cloudflare / WAF / User-Agent allow', 'mainwp' );
            $lines[] = __( '1. In Cloudflare (Security → WAF) or your host WAF, allow the MainWP User-Agent shown below. IP allowlists alone are often not enough.', 'mainwp' );
            $lines[] = __( '2. If you allowlist IPs, allow the Dashboard outbound IP — not the Cloudflare/proxy edge IP.', 'mainwp' );
            $lines[] = __( '3. After the WAF allows this User-Agent, retry connect or sync. Reinstall MainWP Child only if those steps do not resolve it.', 'mainwp' );
        }

        $lines[] = __( 'Dashboard outbound IP (allowlist this):', 'mainwp' ) . ' ' . $outbound;
        $lines[] = __( 'User-Agent MainWP sends:', 'mainwp' ) . ' ' . $agent;
        if ( '' !== $edge_ip ) {
            $lines[] = __( 'Cloudflare/proxy edge IP (do not allowlist this as the Dashboard IP):', 'mainwp' ) . ' ' . $edge_ip;
        }
        $lines[] = __( 'Child / HTTP response', 'mainwp' );
        $lines[] = $response;
        if ( ! $waf ) {
            $lines[] = __( 'If the child site is behind Cloudflare or another WAF, allow this User-Agent there. Then reinstall or reactivate MainWP Child only if that does not resolve it.', 'mainwp' );
        }

        return implode( "\n", $lines );
    }

    /**
     * Attach failure fields onto a try_visit / fetch output array.
     *
     * @param array $output Existing output.
     * @param array $context Raw context (http_status, headers, body, resolved_ip, error).
     *
     * @return array
     */
    public static function enrich_output( $output, $context = array() ) {
        if ( ! is_array( $output ) ) {
            $output = array();
        }

        $details = self::build_details( $context );

        $output['dashboard_outbound_ip'] = $details['dashboard_outbound_ip'];
        $output['user_agent']            = $details['user_agent'];
        $output['resolved_ip']           = $details['resolved_ip'];
        $output['resolved_ip_label']     = $details['resolved_ip_label'];
        $output['waf_likely']            = $details['waf_likely'];
        if ( isset( $context['response_diagnostic'] ) && is_string( $context['response_diagnostic'] ) ) {
            $output['response_diagnostic'] = $context['response_diagnostic'];
        }
        $output['response_headers']  = $details['response']['headers'];
        $output['response_body']     = $details['response']['body'];
        $output['response_readable'] = $details['response']['text'];
        $output['failure_ux']        = self::render_html( $details );
        $output['failure_ux_text']   = self::format_text( $details );

        return $output;
    }

    /**
     * Whether a query or form parameter name is sensitive.
     *
     * @param string $name Parameter name (already decoded).
     *
     * @return bool
     */
    public static function is_sensitive_param( $name ) {
        $name = strtolower( (string) $name );
        $name = preg_replace( '/\[.*$/', '', $name );
        if ( ! is_string( $name ) || '' === $name ) {
            return false;
        }

        if ( in_array( $name, self::SENSITIVE_PARAM_NAMES, true ) ) {
            return true;
        }

        foreach ( self::SENSITIVE_PARAM_SUFFIXES as $suffix ) {
            $needle = '_' . $suffix;
            $len    = strlen( $needle );
            if ( strlen( $name ) >= $len && substr( $name, -$len ) === $needle ) {
                return true;
            }
        }

        foreach ( self::SENSITIVE_PARAM_BARE_SUFFIXES as $suffix ) {
            $len = strlen( $suffix );
            if ( strlen( $name ) > $len && substr( $name, -$len ) === $suffix ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redact sensitive names in a query or fragment string.
     *
     * Handles mixed-case and URL-encoded names/values. Non-sensitive pairs stay intact.
     *
     * @param string $query Raw query or fragment (without leading ? or #).
     *
     * @return string
     */
    public static function redact_query_string( $query ) {
        if ( ! is_string( $query ) || '' === $query ) {
            return '';
        }

        $pairs = array();
        foreach ( explode( '&', $query ) as $pair ) {
            if ( '' === $pair ) {
                continue;
            }
            $bits     = explode( '=', $pair, 2 );
            $raw_name = $bits[0];
            $decoded  = rawurldecode( str_replace( '+', ' ', $raw_name ) );
            if ( self::is_sensitive_param( $decoded ) ) {
                $pairs[] = $raw_name . '=[redacted]';
            } else {
                $pairs[] = $pair;
            }
        }

        return implode( '&', $pairs );
    }

    /**
     * Strip a Location / redirect URL to scheme, host, port, and path.
     *
     * Query, fragment, and userinfo are dropped so unknown secrets cannot
     * leak through a denylist.
     *
     * @param string $url URL or relative Location value.
     *
     * @return string
     */
    public static function redact_url( $url ) {
        if ( ! is_string( $url ) || '' === $url ) {
            return '';
        }

        $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback for when wp_parse_url() is not loaded yet.
        if ( ! is_array( $parts ) ) {
            return '';
        }

        return self::rebuild_url( $parts );
    }

    /**
     * Rebuild a Location URL from parse_url / wp_parse_url parts.
     *
     * Only scheme, hostname, port, and path are emitted.
     *
     * @param array $parts URL parts.
     *
     * @return string
     */
    public static function rebuild_url( $parts ) {
        if ( ! is_array( $parts ) ) {
            return '';
        }

        $url = '';
        if ( ! empty( $parts['scheme'] ) ) {
            $url .= $parts['scheme'] . ':';
        }
        if ( ! empty( $parts['host'] ) ) {
            $url .= '//' . $parts['host'];
            if ( ! empty( $parts['port'] ) ) {
                $url .= ':' . $parts['port'];
            }
        }
        if ( isset( $parts['path'] ) ) {
            $url .= $parts['path'];
        }

        return $url;
    }

    /**
     * Make arbitrary diagnostic text safe without changing benign text.
     *
     * URLs lose userinfo, query strings, and fragments. Text that names a
     * sensitive field is hidden as a unit because its value boundary cannot
     * be trusted in an exception or other free-form diagnostic string.
     *
     * @param string $text Diagnostic text.
     *
     * @return string
     */
    public static function sanitize_diagnostic_text( $text ) {
        if ( ! is_string( $text ) || '' === $text ) {
            return '';
        }

        $with_urls = self::redact_urls_in_text( $text );
        if ( ! is_string( $with_urls ) ) {
            return self::unredactable_text();
        }
        if ( self::mentions_sensitive_name( $text ) || self::mentions_sensitive_name( $with_urls ) ) {
            return self::sensitive_text();
        }

        return $with_urls;
    }

    /**
     * Strip unknown secrets from URLs embedded in text.
     *
     * @param string $text Text that may contain absolute or relative URLs.
     *
     * @return string|null
     */
    private static function redact_urls_in_text( $text ) {
        $with_urls = preg_replace_callback(
            '#(?:(?<![A-Za-z0-9+.-])[A-Za-z][A-Za-z0-9+.-]{0,31}+://[^\s\'"<>]++|//[^\s\'"<>]++|/[^\s\'"<>]*+\?[^\s\'"<>]++)#',
            static function ( $found ) {
                return MainWP_Connect_Failure_Ux::redact_url( MainWP_Connect_Failure_Ux::decode_diagnostic_text( $found[0] ) );
            },
            $text
        );
        if ( ! is_string( $with_urls ) ) {
            return null;
        }

        // A fully encoded scheme does not match the URL pattern above. Decode
        // only the whitespace-delimited token, then replace its URL portion.
        return preg_replace_callback(
            '#[^\s\'"<>]+#u',
            static function ( $found ) {
                $decoded = MainWP_Connect_Failure_Ux::decode_diagnostic_text( $found[0] );
                if ( $decoded === $found[0] ) {
                    return $found[0];
                }
                if ( preg_match( '/(?<![A-Za-z0-9+.-])[A-Za-z][A-Za-z0-9+.-]{0,31}:\/\//', $decoded, $scheme, PREG_OFFSET_CAPTURE ) ) {
                    $offset = $scheme[0][1];
                    return substr( $decoded, 0, $offset ) . MainWP_Connect_Failure_Ux::redact_url( substr( $decoded, $offset ) );
                }
                if ( 0 === strpos( $decoded, '//' ) ) {
                    return MainWP_Connect_Failure_Ux::redact_url( $decoded );
                }
                if ( 0 === strpos( $decoded, '/' ) && false !== strpos( $decoded, '?' ) ) {
                    return MainWP_Connect_Failure_Ux::redact_url( $decoded );
                }
                return $found[0];
            },
            $with_urls
        );
    }

    /**
     * Repeatedly decode entities and percent encoding in diagnostic text.
     *
     * @param string $text Text to decode.
     *
     * @return string
     */
    private static function decode_diagnostic_text( $text ) {
        $decoded = (string) $text;
        for ( $round = 0; $round < 3; ++$round ) {
            $next = rawurldecode( html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            if ( $next === $decoded ) {
                break;
            }
            $decoded = $next;
        }

        return $decoded;
    }

    /**
     * Placeholder shown when a value could not be redacted.
     *
     * @return string
     */
    private static function unredactable_text() {
        return __( '[response content hidden: it could not be safely redacted]', 'mainwp' );
    }

    /**
     * Placeholder shown when a response names a sensitive field.
     *
     * @return string
     */
    private static function sensitive_text() {
        return __( '[response content hidden: it named a sensitive field]', 'mainwp' );
    }

    /**
     * Prepare one kept header value for display.
     *
     * A header value that names a secret anywhere is hidden whole instead of
     * redacted piece by piece: entity- or percent-encoded quotes can move a
     * value's boundary, and every redaction order loses to one of them.
     *
     * @param string $value Header value.
     * @param string $name  Lowercased header name.
     *
     * @return string
     */
    private static function header_value_for_display( $value, $name ) {
        $value = preg_replace( '/[\s\p{Zs}]+/u', ' ', html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( ! is_string( $value ) ) {
            return self::unredactable_text();
        }
        $value = trim( $value );
        if ( 'location' === $name ) {
            $value = self::redact_url( self::decode_diagnostic_text( $value ) );
        } else {
            $value = self::redact_urls_in_text( $value );
            if ( ! is_string( $value ) ) {
                return self::unredactable_text();
            }
        }
        if ( ! self::mentions_sensitive_name( $value ) ) {
            return $value;
        }
        // Keep a known auth scheme so the challenge type stays recognizable. A fixed
        // list, because the classifier treats "bearer" as a sensitive name.
        if ( 'www-authenticate' === $name && preg_match( '/^(Basic|Bearer|Digest|Negotiate|NTLM)(?=\s|$)/i', $value, $scheme ) ) {
            return $scheme[1] . ' [redacted]';
        }
        return '[redacted]';
    }

    /**
     * Whether the text names a secret, as written or after entity and percent decoding.
     *
     * Checks each name-like token, then core stems in a copy with every separator
     * removed, because a space or invisible character inside a name splits it into
     * harmless-looking tokens. Fails closed: a normalization error counts as a match.
     *
     * @param string $text Text to check.
     *
     * @return bool
     */
    private static function mentions_sensitive_name( $text ) {
        $text       = (string) $text;
        $candidates = array( $text );
        $decoded    = $text;
        for ( $round = 0; $round < 3; ++$round ) {
            $next = rawurldecode( html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            if ( $next === $decoded ) {
                break;
            }
            $candidates[] = $next;
            $decoded      = $next;
        }

        $stripped_candidates = array();
        foreach ( $candidates as $candidate ) {
            $stripped = wp_strip_all_tags( str_replace( '<', ' <', $candidate ) );
            if ( $stripped !== $candidate ) {
                $stripped_candidates[] = $stripped;
            }
        }
        $candidates = array_merge( $candidates, $stripped_candidates );

        foreach ( $candidates as $candidate ) {
            $candidate = preg_replace( '/\p{Cf}+/u', '', $candidate );
            if ( ! is_string( $candidate ) ) {
                return true;
            }
            $tokens = preg_split( '/[^A-Za-z0-9_\-]+/', $candidate, -1, PREG_SPLIT_NO_EMPTY );
            if ( false === $tokens ) {
                return true;
            }
            foreach ( $tokens as $token ) {
                if ( self::is_sensitive_param( $token ) ) {
                    return true;
                }
            }
            if ( self::mentions_sensitive_fragment_sequence( $candidate ) ) {
                return true;
            }
            $squeezed = preg_replace( '/[^a-z0-9]+/u', '', strtolower( $candidate ) );
            if ( ! is_string( $squeezed ) ) {
                return true;
            }
            // The stems also catch a name glued to its value once separators are gone.
            foreach ( self::SENSITIVE_STEMS as $stem ) {
                if ( false !== strpos( $squeezed, $stem ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Detect a sensitive name split across punctuation, markup, or whitespace.
     *
     * Only bounded windows are joined, which covers every configured name
     * without building an unbounded compact copy of arbitrary response text.
     *
     * @param string $text Candidate text.
     *
     * @return bool
     */
    private static function mentions_sensitive_fragment_sequence( $text ) {
        $parts = preg_split( '/[^A-Za-z0-9_]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( false === $parts ) {
            return true;
        }

        $count = count( $parts );
        for ( $start = 0; $start < $count; ++$start ) {
            $name = '';
            $end  = min( $count, $start + 19 );
            for ( $index = $start; $index < $end; ++$index ) {
                $name .= $parts[ $index ];
                if ( strlen( $name ) > 32 ) {
                    break;
                }
                if ( $index > $start && ( self::is_sensitive_fragment_param( $name ) || self::is_sensitive_compact_param( $name ) ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Classify a joined fragment window without repeatedly scanning the name list.
     *
     * @param string $name Candidate parameter name.
     *
     * @return bool
     */
    private static function is_sensitive_fragment_param( $name ) {
        static $exact_names = null;

        $name = strtolower( (string) $name );
        if ( null === $exact_names ) {
            $exact_names = array_fill_keys( self::SENSITIVE_PARAM_NAMES, true );
        }
        if ( isset( $exact_names[ $name ] ) ) {
            return true;
        }
        foreach ( self::SENSITIVE_PARAM_SUFFIXES as $suffix ) {
            $needle = '_' . $suffix;
            $length = strlen( $needle );
            if ( substr( $name, -$length ) === $needle ) {
                return true;
            }
        }
        foreach ( self::SENSITIVE_PARAM_BARE_SUFFIXES as $suffix ) {
            $length = strlen( $suffix );
            if ( substr( $name, -$length ) === $suffix ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify a name after separators, including underscores, have been removed.
     *
     * @param string $name Compact candidate name.
     *
     * @return bool
     */
    private static function is_sensitive_compact_param( $name ) {
        static $compact_names = null;

        if ( null === $compact_names ) {
            $compact_names = array();
            foreach ( self::SENSITIVE_PARAM_NAMES as $sensitive ) {
                $compact_names[ str_replace( array( '_', '-' ), '', $sensitive ) ] = true;
            }
        }

        return isset( $compact_names[ strtolower( (string) $name ) ] );
    }

    /**
     * Allowed HTML for rendering stored failure UX.
     *
     * @return array<string,array<string,bool>>
     */
    public static function kses_allowed() {
        return array(
            'div'    => array( 'class' => true ),
            'p'      => array(),
            'strong' => array(),
            'em'     => array(),
            'ol'     => array(),
            'ul'     => array(),
            'li'     => array(),
            'code'   => array(),
            'pre'    => array(),
            'br'     => array(),
            'a'      => array(
                'href'   => true,
                'target' => true,
            ),
        );
    }
}
