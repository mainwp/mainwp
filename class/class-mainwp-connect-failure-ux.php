<?php
/**
 * Connect/sync failure presentation (MWP-1788).
 *
 * Labels Cloudflare/proxy edge IPs separately from the Dashboard outbound IP,
 * always exposes the MainWP User-Agent, and formats HTTP status and headers.
 * Does not change connection auth, timeouts, or redirects.
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
     * Parameter names whose values must not appear in header diagnostics.
     *
     * Compared case-insensitively, including names ending in these names.
     *
     * @var array<int,string>
     */
    const SENSITIVE_PARAM_NAMES = array(
        'password',
        'passwd',
        'pwd',
        'adminpwd',
        'uniqueid',
        'unique_id',
        'privkey',
        'pubkey',
        'mainwpsignature',
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
                $status = $line;
                continue;
            }
            $parts = explode( ':', $line, 2 );
            if ( 2 !== count( $parts ) ) {
                continue;
            }
            $name = strtolower( trim( $parts[0] ) );
            if ( in_array( $name, $wanted, true ) ) {
                $value = trim( $parts[1] );
                if ( 'location' === $name ) {
                    $value = self::redact_url( $value );
                }
                $value  = self::redact_secrets( $value );
                $kept[] = $name . ': ' . $value;
            }
        }

        $snippet = $status;
        if ( ! empty( $kept ) ) {
            $snippet .= ( '' !== $snippet ? "\n" : '' ) . implode( "\n", $kept );
        }

        return trim( $snippet );
    }

    /**
     * Build a response block containing HTTP status and safe headers.
     *
     * @param int    $http_status HTTP status.
     * @param string $headers     Raw response headers.
     * @param string $body        Raw response body.
     *
     * @return array<string,string>
     */
    public static function summarize_response( $http_status, $headers = '', $body = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Retained for existing callers; body text is never displayed.
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

        $lines[] = esc_html__( 'The response body is not shown because it can contain private data from the child site.', 'mainwp' );

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
        $response    = self::summarize_response( $http_status, $headers, $body );
        $waf_likely  = self::is_waf_likely( $http_status, $headers, $body );
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

        $body        = '';
        $http_status = 0;
        $headers     = '';
        $resolved_ip = '';

        if ( $exception instanceof MainWP_Exception ) {
            $data = $exception->get_data();
            if ( is_array( $data ) ) {
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
            } elseif ( is_string( $data ) ) {
                $body = $data;
            }
        }

        if ( isset( $output['http_status'] ) && (int) $output['http_status'] > 0 ) {
            $http_status = (int) $output['http_status'];
        } elseif ( isset( $output['httpCode'] ) && (int) $output['httpCode'] > 0 ) {
            $http_status = (int) $output['httpCode'];
        }

        if ( isset( $output['response_headers'] ) && '' !== $output['response_headers'] ) {
            $headers = (string) $output['response_headers'];
        }

        if ( isset( $output['response_body'] ) && '' !== (string) $output['response_body'] ) {
            $body = (string) $output['response_body'];
        } elseif ( isset( $output['fetch_data'] ) && is_string( $output['fetch_data'] ) && '[hidden response data]' !== $output['fetch_data'] && '' === $body ) {
            $body = $output['fetch_data'];
        }

        if ( isset( $output['resolved_ip'] ) ) {
            $resolved_ip = $output['resolved_ip'];
        } elseif ( isset( $output['ip'] ) && '' === self::format_ip_list( $resolved_ip ) ) {
            $resolved_ip = $output['ip'];
        }

        return self::build_details(
            array(
                'http_status' => $http_status,
                'headers'     => $headers,
                'body'        => $body,
                'resolved_ip' => $resolved_ip,
                'error'       => ( $exception instanceof MainWP_Exception ) ? $exception->getMessage() : '',
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
        $output['response_headers']      = $details['response']['headers'];
        $output['response_body']         = $details['response']['body'];
        $output['response_readable']     = $details['response']['text'];
        $output['failure_ux']            = self::render_html( $details );
        $output['failure_ux_text']       = self::format_text( $details );

        return $output;
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

        $parts = wp_parse_url( $url );
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
     * Withhold text containing a sensitive name before ':' or '=' (raw or URL-encoded), or any regex failure.
     *
     * Names ending in a sensitive name also count. Otherwise, strip URL credentials,
     * queries and fragments while keeping the diagnostic text readable.
     *
     * @param string $text Text.
     *
     * @return string Safe text, or an empty string when it must be withheld.
     */
    public static function redact_secrets( $text ) {
        if ( ! is_string( $text ) || '' === $text ) {
            return '';
        }

        // A zero-width scan checks every position without consuming a prior name or value.
        // The pattern depends only on the fixed names, never on response values.
        $pattern = '/(?=(?:' . implode( '|', self::SENSITIVE_PARAM_NAMES ) . ')[\'"\]]*\s*(?:[:=]|%3[ad]))/iu';
        // Malformed tags leave angle brackets and spaces inside a sensitive name, and
        // invisible format or combining characters split it without showing a gap.
        $detection = preg_replace( '/[\s<>\p{Cf}\p{Mn}]+/u', '', $text );
        if ( ! is_string( $detection ) || PREG_NO_ERROR !== preg_last_error() ) {
            return '';
        }
        foreach ( array( $text, $detection ) as $candidate ) {
            $matched = preg_match( $pattern, $candidate );
            if ( false === $matched || PREG_NO_ERROR !== preg_last_error() || 1 === $matched ) {
                return '';
            }
        }

        $with_urls = preg_replace_callback(
            '#(?:https?://[^\s\'"<>]+|/[^\s\'"<>]*\?[^\s\'"<>]+)#i',
            static function ( $matches ) {
                return MainWP_Connect_Failure_Ux::redact_url( $matches[0] );
            },
            $text
        );
        if ( ! is_string( $with_urls ) || PREG_NO_ERROR !== preg_last_error() ) {
            return '';
        }

        return $with_urls;
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
