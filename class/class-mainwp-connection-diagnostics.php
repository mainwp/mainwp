<?php
/**
 * MainWP connection diagnostics.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keeps raw transport observations inside a closed diagnosis boundary.
 */
class MainWP_Connection_Diagnostics { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /** Maximum response bytes retained for protocol and detector analysis. */
    const BODY_SAMPLE_LIMIT = 65536;

    /** Maximum response header bytes retained for detector analysis. */
    const HEADER_SAMPLE_LIMIT = 32768;

    /** Interactive connection diagnostics must complete inside this budget. */
    const DEFAULT_DEADLINE_SECONDS = 25;

    /** Consolidated troubleshooting documentation. */
    const DOCS_URL = 'https://docs.mainwp.com/troubleshooting/potential-issues';

    /**
     * Test a site that is not connected yet with a non-mutating MainWP request.
     *
     * @param string $url      Site URL.
     * @param string $admin    WordPress administrator username.
     * @param array  $settings Draft transport settings.
     *
     * @return array Safe exported diagnosis.
     */
    public static function test_unconnected( $url, $admin, $settings = array() ) {
        $nonce    = wp_rand( 0, 9999 );
        $postdata = http_build_query(
            array(
                'user'            => (string) $admin,
                'function'        => 'connection_check',
                'nonce'           => $nonce,
                'mainwpver'       => MainWP_System::$version,
                // Deliberately invalid: a framed PARSE_ERROR response proves Child handled the request without registration.
                'mainwpsignature' => base64_encode( 'mainwp-connection-diagnostic' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- HTTP protocol compatibility.
            ),
            '',
            '&'
        );

        $settings['mode']                    = 'probe';
        $settings['authentication_expected'] = false;

        return self::run_probe( $url, $postdata, $settings );
    }

    /**
     * Test a connected site using saved identity and keys with draft transport settings.
     *
     * @param object $website Saved website row.
     * @param array  $settings Draft transport settings.
     *
     * @return array Safe exported diagnosis.
     */
    public static function test_connected( &$website, $settings = array() ) {
        if ( ! is_object( $website ) ) {
            return self::configuration_result( 'missing_site' );
        }

        $postdata = MainWP_Connect::get_post_data_authed( $website, 'connection_check' );
        $parsed   = array();
        parse_str( (string) $postdata, $parsed );
        if ( empty( $postdata ) || empty( $parsed['mainwpsignature'] ) ) {
            return self::configuration_result( 'signing_failed' );
        }

        $settings['mode']                    = 'probe';
        $settings['authentication_expected'] = true;
        $settings['website']                 = $website;

        return self::run_probe( isset( $settings['url'] ) ? $settings['url'] : $website->url, $postdata, $settings );
    }

    /**
     * Diagnose a MainWP request while keeping every raw observation private.
     *
     * @param string $url      Site URL.
     * @param mixed  $postdata MainWP request body.
     * @param array  $settings Diagnostic settings.
     *
     * @return array Safe exported diagnosis.
     */
    public static function run_probe( $url, $postdata, $settings = array() ) {
        $deadline             = isset( $settings['deadline'] ) ? (float) $settings['deadline'] : microtime( true ) + self::DEFAULT_DEADLINE_SECONDS;
        $settings['deadline'] = $deadline;
        $settings['site_url'] = self::origin_url( $url );
        $settings['phase']    = 'child_endpoint';
        $settings['attempt']  = 1;

        $primary_url    = self::endpoint_url( $url );
        $primary_result = self::request( $primary_url, $postdata, $settings );
        $primary        = self::classify_request_result( $primary_result, $settings );
        $attempts       = array( self::attempt_summary( $primary ) );

        if ( ! empty( $settings['allow_fallback'] ) && self::is_ambiguous( $primary['diagnosis'] ) && microtime( true ) < $deadline ) {
            $settings['phase']   = 'compatibility_fallback';
            $settings['attempt'] = 2;
            $fallback_result     = self::request( self::origin_url( $url ), $postdata, $settings );
            $fallback            = self::classify_request_result( $fallback_result, $settings );
            $attempts[]          = self::attempt_summary( $fallback );

            if ( 'success' === $fallback['diagnosis']['verdict'] ) {
                $fallback                         = self::mark_fallback_success( $fallback );
                $fallback['primary_diagnosis_id'] = $primary['diagnosis']['diagnosis_id'];
                $fallback['attempts']             = $attempts;
                return $fallback;
            }
        }

        $primary['attempts'] = $attempts;
        return $primary;
    }

    /**
     * Build a diagnosis from an already validated MainWP Child response frame.
     *
     * @param array  $information Parsed Child response.
     * @param string $phase       Connection phase.
     * @param bool   $auth_expected Whether saved-key authentication was expected.
     *
     * @return array Safe exported diagnosis.
     */
    public static function from_child_response( $information, $phase = 'handshake', $auth_expected = false ) {
        $observation = array(
            'phase'                   => sanitize_key( $phase ),
            'mode'                    => 'connection',
            'child_frame'             => true,
            'child_error'             => ! empty( $information['error'] ),
            'child_error_code'        => isset( $information['error_code'] ) ? sanitize_key( $information['error_code'] ) : '',
            'authentication_expected' => (bool) $auth_expected,
            'authentication_confirmed' => $auth_expected && ( empty( $information['error'] ) || 'child_plugin_incompatible' === ( $information['error_code'] ?? '' ) ),
            'http_status'             => 200,
            'curl_errno'              => 0,
        );

        return self::export( self::diagnose( $observation ), $observation );
    }

    /** Build a safe not-tested result for rejected local input. */
    public static function not_tested( $reason = 'invalid_url' ) {
        return self::configuration_result( sanitize_key( $reason ) );
    }

    /** Build a safe local-configuration result without opening a connection. */
    private static function configuration_result( $reason ) {
        if ( 'signing_failed' === $reason ) {
            $diagnosis_id = 'dashboard_signing_failed';
        } elseif ( 'invalid_url' === $reason ) {
            $diagnosis_id = 'dashboard_invalid_url';
        } else {
            $diagnosis_id = 'dashboard_configuration_error';
        }
        $observation  = array(
            'phase'               => 'request_preparation',
            'configuration_error' => sanitize_key( $reason ),
            'http_status'         => 0,
            'curl_errno'          => 0,
        );
        $diagnosis = self::make_diagnosis(
            'not_tested',
            $diagnosis_id,
            'dashboard_configuration',
            'high',
            'request_preparation',
            array( array( 'id' => 'request_not_sent' ) )
        );
        $export               = self::export( $diagnosis, $observation );
        $export['attempts']   = array( self::attempt_summary( $export ) );
        return $export;
    }

    /** Parse a raw request result, classify it, then discard the raw observation. */
    private static function classify_request_result( $result, $settings ) {
        $observation = isset( $result['observation'] ) && is_array( $result['observation'] ) ? $result['observation'] : array();
        $body        = isset( $result['body'] ) && is_string( $result['body'] ) ? $result['body'] : '';
        $frame       = self::parse_child_frame( $body );

        $valid_probe_frame = is_array( $frame ) && ! empty( $frame );
        if ( $valid_probe_frame && 'probe' === ( $settings['mode'] ?? '' ) ) {
            $frame_code = isset( $frame['error_code'] ) ? sanitize_key( $frame['error_code'] ) : '';
            if ( empty( $settings['authentication_expected'] ) ) {
                $valid_probe_frame = 'parse_error1' === $frame_code;
            } else {
                $valid_probe_frame = in_array( $frame_code, array( 'parse_error1', 'parse_error2', 'parse_error3', 'parse_error4', 'child_plugin_incompatible' ), true );
            }
        }

        if ( $valid_probe_frame ) {
            $observation['child_frame']      = true;
            $observation['child_error']      = ! empty( $frame['error'] );
            $observation['child_error_code'] = isset( $frame['error_code'] ) ? sanitize_key( $frame['error_code'] ) : '';

            if ( ! empty( $settings['authentication_expected'] ) ) {
                $error_code = $observation['child_error_code'];
                $observation['authentication_confirmed'] = ! in_array( $error_code, array( 'parse_error1', 'parse_error2' ), true );
            }
        }

        $diagnosis = self::diagnose( $observation );
        $context   = array();
        if ( ! empty( $settings['website'] ) && is_object( $settings['website'] ) && isset( $settings['website']->version ) ) {
            $context['child_version'] = $settings['website']->version;
        }

        return self::export( $diagnosis, $observation, $context );
    }

    /** Strictly decode a framed MainWP Child JSON response. */
    private static function parse_child_frame( $body ) {
        if ( ! is_string( $body ) || '' === $body ) {
            return null;
        }
        if ( 1 !== preg_match( '/^\s*<mainwp>([A-Za-z0-9+\/=\r\n]+)<\/mainwp>\s*$/D', $body, $matches ) ) {
            return null;
        }
        $decoded = base64_decode( preg_replace( '/\s+/', '', $matches[1] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- MainWP wire format.
        if ( false === $decoded ) {
            return null;
        }
        $frame = json_decode( $decoded, true );
        return JSON_ERROR_NONE === json_last_error() && is_array( $frame ) ? $frame : null;
    }

    /** Build the canonical MainWP endpoint without carrying query or fragment data. */
    private static function endpoint_url( $url ) {
        return trailingslashit( self::origin_url( $url ) ) . 'wp-admin/admin-ajax.php';
    }

    /** Return the entered site base URL without query or fragment data. */
    private static function origin_url( $url ) {
        $parts = wp_parse_url( (string) $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return (string) $url;
        }
        $authority = $parts['host'];
        if ( false !== strpos( $authority, ':' ) && '[' !== substr( $authority, 0, 1 ) ) {
            $authority = '[' . $authority . ']';
        }
        if ( ! empty( $parts['port'] ) ) {
            $authority .= ':' . (int) $parts['port'];
        }
        $path = isset( $parts['path'] ) ? $parts['path'] : '';
        return strtolower( $parts['scheme'] ) . '://' . $authority . $path;
    }

    /** Reduce an exported attempt to machine-safe fields. */
    private static function attempt_summary( $export ) {
        $diagnosis = isset( $export['diagnosis'] ) ? $export['diagnosis'] : array();
        $support   = isset( $export['support'] ) ? $export['support'] : array();
        return array(
            'verdict'      => isset( $diagnosis['verdict'] ) ? $diagnosis['verdict'] : 'failure',
            'diagnosis_id' => isset( $diagnosis['diagnosis_id'] ) ? $diagnosis['diagnosis_id'] : 'unexpected_response',
            'phase'        => isset( $diagnosis['phase'] ) ? $diagnosis['phase'] : 'child_endpoint',
            'http_status'  => isset( $support['http_status'] ) ? (int) $support['http_status'] : 0,
            'curl_errno'   => isset( $support['curl_errno'] ) ? (int) $support['curl_errno'] : 0,
        );
    }

    /**
     * Execute one bounded diagnostic request.
     *
     * Raw headers, URLs, cURL text, and response bytes returned here are internal-only.
     * Callers must pass the observation to diagnose() and expose only export().
     *
     * @param string $url      Request URL.
     * @param mixed  $postdata POST data.
     * @param array  $settings Transport settings.
     *
     * @return array Internal request result.
     */
    private static function request( $url, $postdata, $settings = array() ) { // phpcs:ignore -- NOSONAR - cURL setup is intentionally kept in one closed boundary.
        $deadline = isset( $settings['deadline'] ) ? (float) $settings['deadline'] : microtime( true ) + self::DEFAULT_DEADLINE_SECONDS;
        $remaining = (int) ceil( $deadline - microtime( true ) );

        $observation = array(
            'requested_url'       => (string) $url,
            'effective_url'       => '',
            'peer_ip'             => '',
            'http_status'         => 0,
            'header_blocks'       => array(),
            'bounded_body_sample' => '',
            'body_truncated'      => false,
            'curl_errno'          => 0,
            'curl_error'          => '',
            'phase'               => isset( $settings['phase'] ) ? sanitize_key( $settings['phase'] ) : 'child_endpoint',
            'attempt'             => isset( $settings['attempt'] ) ? max( 1, (int) $settings['attempt'] ) : 1,
            'mode'                => isset( $settings['mode'] ) ? sanitize_key( $settings['mode'] ) : 'connection',
            'http_auth_supplied'  => ! empty( $settings['http_user'] ) && ! empty( $settings['http_pass'] ),
            'authentication_expected' => ! empty( $settings['authentication_expected'] ),
            'via_proxy'            => false,
        );

        if ( $remaining <= 0 ) {
            $observation['curl_errno'] = defined( 'CURLE_OPERATION_TIMEDOUT' ) ? CURLE_OPERATION_TIMEDOUT : 28;
            return array(
                'body'        => '',
                'observation' => $observation,
            );
        }

        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            $observation['configuration_error'] = 'invalid_url';
            return array(
                'body'        => '',
                'observation' => $observation,
            );
        }

        $body           = '';
        $header_blocks  = array();
        $header_bytes   = 0;
        $current_block  = -1;
        $body_truncated = false;
        $ch             = curl_init();

        $proxy = new \WP_HTTP_Proxy();
        if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
            $observation['via_proxy'] = true;
            curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
            curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
            curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );
            if ( $proxy->use_authentication() ) {
                curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
                curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
            }
        }

        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, min( 10, $remaining ) );
        curl_setopt( $ch, CURLOPT_TIMEOUT, $remaining );
        curl_setopt( $ch, CURLOPT_NOSIGNAL, true );
        curl_setopt( $ch, CURLOPT_ENCODING, '' );
        curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)' );

        curl_setopt(
            $ch,
            CURLOPT_HEADERFUNCTION,
            static function ( $handle, $line ) use ( &$header_blocks, &$header_bytes, &$current_block ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- cURL callback signature.
                $length  = strlen( $line );
                if ( $header_bytes >= self::HEADER_SAMPLE_LIMIT || $length > self::HEADER_SAMPLE_LIMIT - $header_bytes ) {
                    $header_bytes = self::HEADER_SAMPLE_LIMIT;
                    return $length;
                }
                $header_bytes += $length;
                $trimmed = trim( $line );
                if ( 0 === stripos( $trimmed, 'HTTP/' ) ) {
                    $header_blocks[] = array(
                        'status_line' => $trimmed,
                        'headers'     => array(),
                    );
                    $current_block = count( $header_blocks ) - 1;
                } elseif ( '' !== $trimmed && $current_block >= 0 && false !== strpos( $trimmed, ':' ) ) {
                    list( $name, $value ) = explode( ':', $trimmed, 2 );
                    $name                 = strtolower( trim( $name ) );
                    if ( ! isset( $header_blocks[ $current_block ]['headers'][ $name ] ) ) {
                        $header_blocks[ $current_block ]['headers'][ $name ] = array();
                    }
                    $header_blocks[ $current_block ]['headers'][ $name ][] = trim( $value );
                }
                return $length;
            }
        );

        curl_setopt(
            $ch,
            CURLOPT_WRITEFUNCTION,
            static function ( $handle, $chunk ) use ( &$body, &$body_truncated ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- cURL callback signature.
                $length    = strlen( $chunk );
                $remaining = self::BODY_SAMPLE_LIMIT - strlen( $body );
                if ( $remaining > 0 ) {
                    $body .= substr( $chunk, 0, $remaining );
                }
                if ( $length > max( 0, $remaining ) ) {
                    $body_truncated = true;
                }
                return $length;
            }
        );

        $headers = array(
            'X-Requested-With' => 'XMLHttpRequest',
            'Expect'           => MainWP_Connect::get_expect_header( $postdata ),
        );
        $website = isset( $settings['website'] ) && is_object( $settings['website'] ) ? $settings['website'] : null;
        $headers = apply_filters( 'mainwp_connect_http_request_headers', $headers, $website );
        if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
            $headers = \WpOrg\Requests\Requests::flatten( $headers );
        } else {
            $headers = \Requests::flatten( $headers );
        }
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
        curl_setopt( $ch, CURLOPT_REFERER, get_option( 'siteurl' ) );

        if ( ! empty( $settings['http_user'] ) && ! empty( $settings['http_pass'] ) ) {
            curl_setopt( $ch, CURLOPT_USERPWD, $settings['http_user'] . ':' . stripslashes( $settings['http_pass'] ) );
        }

        $verify_certificate = ! empty( $settings['verify_certificate'] );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, $verify_certificate ? 2 : 0 );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, $verify_certificate );
        curl_setopt( $ch, CURLOPT_SSLVERSION, isset( $settings['ssl_version'] ) ? (int) $settings['ssl_version'] : 0 );

        if ( ! empty( $settings['force_use_ipv4'] ) && defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
            curl_setopt( $ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
        }

        $website_id = $website && property_exists( $website, 'id' ) ? $website->id : false;
        $http_version = apply_filters( 'mainwp_curl_http_version', false, $website_id, $url );
        if ( false !== $http_version ) {
            curl_setopt( $ch, CURLOPT_HTTP_VERSION, $http_version );
        }

        if ( $website_id ) {
            $resolve_url     = isset( $settings['site_url'] ) ? $settings['site_url'] : $url;
            $curlopt_resolve = apply_filters( 'mainwp_curl_curlopt_resolve', false, $website_id, $resolve_url );
            if ( is_array( $curlopt_resolve ) && ! empty( $curlopt_resolve ) ) {
                curl_setopt( $ch, CURLOPT_RESOLVE, $curlopt_resolve );
                curl_setopt( $ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false );
            }
        }

        curl_exec( $ch );

        $observation['curl_errno']          = (int) curl_errno( $ch );
        $observation['curl_error']          = (string) curl_error( $ch );
        $observation['http_status']         = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $observation['effective_url']       = (string) curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
        $observation['peer_ip']             = defined( 'CURLINFO_PRIMARY_IP' ) ? (string) curl_getinfo( $ch, CURLINFO_PRIMARY_IP ) : '';
        $observation['header_blocks']       = $header_blocks;
        $observation['bounded_body_sample'] = $body;
        $observation['body_truncated']      = $body_truncated;

        if ( PHP_VERSION_ID < 80500 ) {
            curl_close( $ch );
        } else {
            unset( $ch );
        }

        return array(
            'body'        => $body,
            'observation' => $observation,
        );
    }

    /**
     * Classify an internal observation into the closed diagnosis model.
     *
     * @param array $observation Internal transport observation.
     *
     * @return array Closed diagnosis.
     */
    public static function diagnose( $observation ) { // phpcs:ignore -- NOSONAR - explicit precedence is easier to audit as a single classifier.
        $phase      = isset( $observation['phase'] ) ? sanitize_key( $observation['phase'] ) : 'child_endpoint';
        $http       = isset( $observation['http_status'] ) ? (int) $observation['http_status'] : 0;
        $curl_errno = isset( $observation['curl_errno'] ) ? (int) $observation['curl_errno'] : 0;
        $facts      = array();

        if ( ! empty( $observation['configuration_error'] ) ) {
            return self::make_diagnosis( 'not_tested', 'dashboard_configuration_error', 'dashboard_configuration', 'high', $phase, array( array( 'id' => 'request_not_sent' ) ) );
        }

        if ( ! empty( $observation['child_frame'] ) ) {
            $facts[] = array( 'id' => 'mainwp_frame_received' );
            if ( 'probe' === ( $observation['mode'] ?? '' ) ) {
                if ( ! empty( $observation['authentication_expected'] ) && empty( $observation['authentication_confirmed'] ) ) {
                    $facts[] = array( 'id' => 'authentication_not_confirmed' );
                    return self::make_diagnosis( 'warning', 'child_responded_authentication_unconfirmed', 'mainwp_child_responded', 'high', $phase, $facts );
                }
                if ( ! empty( $observation['authentication_confirmed'] ) ) {
                    $facts[] = array( 'id' => 'authentication_confirmed' );
                }
                if ( ! empty( $observation['child_error'] ) && 'child_plugin_incompatible' !== ( $observation['child_error_code'] ?? '' ) && ! empty( $observation['authentication_expected'] ) ) {
                    $facts[] = array( 'id' => 'mainwp_child_error_received' );
                    return self::make_diagnosis( 'failure', 'mainwp_child_error', 'mainwp_child_error', 'high', $phase, $facts );
                }
                return self::make_diagnosis( 'success', 'child_responded', 'mainwp_child_responded', 'high', $phase, $facts );
            }

            if ( ! empty( $observation['child_error'] ) ) {
                $facts[] = array( 'id' => 'mainwp_child_error_received' );
                return self::make_diagnosis( 'failure', 'mainwp_child_error', 'mainwp_child_error', 'high', $phase, $facts );
            }
            return self::make_diagnosis( 'success', 'child_responded', 'mainwp_child_responded', 'high', $phase, $facts );
        }

        if ( $curl_errno ) {
            $facts[] = array(
                'id'    => 'curl_errno',
                'value' => $curl_errno,
            );
            if ( in_array( $curl_errno, array( 5, 6 ), true ) ) {
                return self::make_diagnosis( 'failure', 'dns_resolution_failed', 'dns_failure', 'high', $phase, $facts );
            }
            if ( 28 === $curl_errno ) {
                return self::make_diagnosis( 'failure', 'connection_timed_out', 'connection_timeout', 'high', $phase, $facts );
            }
            if ( in_array( $curl_errno, self::tls_errno_values(), true ) ) {
                return self::make_diagnosis( 'failure', 'tls_connection_failed', 'tls_failure', 'high', $phase, $facts );
            }
            return self::make_diagnosis( 'failure', 'transport_request_failed', 'unexpected_response', 'medium', $phase, $facts );
        }

        if ( $http > 0 ) {
            $facts[] = array(
                'id'    => 'http_status',
                'value' => $http,
            );
        }

        // A provider is named only when distinctive structural evidence is present.
        $provider = self::detect_provider( $observation );
        if ( $provider ) {
            $facts[] = array( 'id' => $provider . '_marker_detected' );
            return self::make_diagnosis( 'failure', $provider . '_request_blocked', 'request_blocked', 'high', $phase, $facts, $provider, 'high' );
        }

        if ( $http >= 300 && $http < 400 ) {
            $facts[] = array( 'id' => 'redirect_not_followed' );
            return self::make_diagnosis( 'warning', 'redirect_not_followed', 'redirected', 'high', $phase, $facts );
        }

        if ( 401 === $http && self::has_basic_auth_challenge( $observation ) ) {
            $facts[] = array( 'id' => ! empty( $observation['http_auth_supplied'] ) ? 'http_auth_credentials_supplied' : 'http_auth_credentials_missing' );
            $diagnosis_id = ! empty( $observation['http_auth_supplied'] ) ? 'http_authentication_rejected' : 'http_authentication_required';
            return self::make_diagnosis( 'failure', $diagnosis_id, 'http_authentication', 'high', $phase, $facts );
        }

        if ( 429 === $http ) {
            return self::make_diagnosis( 'failure', 'request_rate_limited', 'rate_limited', 'high', $phase, $facts );
        }

        if ( in_array( $http, array( 403, 406 ), true ) ) {
            return self::make_diagnosis( 'failure', 'request_blocked', 'request_blocked', 'high', $phase, $facts );
        }

        if ( self::has_cache_hit_signal( $observation ) ) {
            $facts[] = array( 'id' => 'cache_hit_detected' );
            return self::make_diagnosis( 'failure', 'cached_response_intercepted', 'cached_intercepted_response', 'medium', $phase, $facts );
        }

        if ( $http >= 500 && $http <= 599 ) {
            return self::make_diagnosis( 'failure', 'server_error_response', 'server_error', 'high', $phase, $facts );
        }

        if ( $http > 0 ) {
            $facts[] = array( 'id' => 'mainwp_frame_not_received' );
            return self::make_diagnosis( 'failure', 'child_did_not_respond', 'mainwp_child_did_not_respond', 'medium', $phase, $facts );
        }

        return self::make_diagnosis( 'failure', 'unexpected_response', 'unexpected_response', 'low', $phase, array( array( 'id' => 'mainwp_frame_not_received' ) ) );
    }

    /**
     * Export a diagnosis with localized presentation and a safe support bundle.
     *
     * @param array $diagnosis   Closed diagnosis.
     * @param array $observation Internal observation.
     * @param array $context     Safe version context.
     *
     * @return array Safe public result.
     */
    public static function export( $diagnosis, $observation = array(), $context = array() ) {
        $presentation = self::presentation( $diagnosis, $observation );
        $support      = array(
            'diagnosis_id'        => $diagnosis['diagnosis_id'],
            'category'            => $diagnosis['category'],
            'provider'            => isset( $diagnosis['provider'] ) ? $diagnosis['provider'] : null,
            'category_confidence' => $diagnosis['category_confidence'],
            'provider_confidence' => $diagnosis['provider_confidence'],
            'phase'               => $diagnosis['phase'],
            'http_status'         => isset( $observation['http_status'] ) ? (int) $observation['http_status'] : 0,
            'curl_errno'          => isset( $observation['curl_errno'] ) ? (int) $observation['curl_errno'] : 0,
            'timestamp'           => gmdate( 'c' ),
            'dashboard_version'   => MainWP_System::$version,
            'child_version'       => isset( $context['child_version'] ) ? sanitize_text_field( $context['child_version'] ) : '',
            'facts'               => $diagnosis['facts'],
        );

        return array(
            'diagnosis'   => $diagnosis,
            'presentation' => $presentation,
            'support'     => $support,
        );
    }

    /**
     * Mark a successful compatibility fallback without losing the primary failure.
     *
     * @param array $export Safe exported diagnosis.
     *
     * @return array Updated export.
     */
    public static function mark_fallback_success( $export ) {
        if ( empty( $export['diagnosis'] ) || 'success' !== $export['diagnosis']['verdict'] ) {
            return $export;
        }
        $export['diagnosis']['verdict']      = 'warning';
        $export['diagnosis']['diagnosis_id'] = 'child_responded_via_fallback';
        $export['diagnosis']['facts'][]      = array( 'id' => 'primary_endpoint_failed' );
        $observation = array(
            'http_status' => isset( $export['support']['http_status'] ) ? (int) $export['support']['http_status'] : 0,
            'curl_errno'  => isset( $export['support']['curl_errno'] ) ? (int) $export['support']['curl_errno'] : 0,
        );
        $export['presentation']              = self::presentation( $export['diagnosis'], $observation );
        $export['support']['diagnosis_id']   = 'child_responded_via_fallback';
        $export['support']['facts']          = $export['diagnosis']['facts'];
        return $export;
    }

    /** Mark a failed connection action without turning a successful follow-up probe green. */
    public static function mark_connection_action_failed( $export ) {
        if ( empty( $export['diagnosis'] ) || ! in_array( $export['diagnosis']['verdict'], array( 'success', 'warning' ), true ) ) {
            return $export;
        }
        $export['diagnosis']['verdict']      = 'failure';
        $export['diagnosis']['diagnosis_id'] = 'connection_action_failed';
        $export['diagnosis']['category']     = 'mainwp_child_responded';
        $export['diagnosis']['facts'][]      = array( 'id' => 'connection_action_failed' );
        $observation = array(
            'http_status' => isset( $export['support']['http_status'] ) ? (int) $export['support']['http_status'] : 0,
            'curl_errno'  => isset( $export['support']['curl_errno'] ) ? (int) $export['support']['curl_errno'] : 0,
        );
        $export['presentation']            = self::presentation( $export['diagnosis'], $observation );
        $export['support']['diagnosis_id'] = 'connection_action_failed';
        $export['support']['category']     = 'mainwp_child_responded';
        $export['support']['facts']        = $export['diagnosis']['facts'];
        return $export;
    }

    /**
     * Determine whether a generic/root reachability fallback can add reliable information.
     *
     * @param array $diagnosis Closed diagnosis.
     *
     * @return bool
     */
    public static function is_ambiguous( $diagnosis ) {
        if ( ! is_array( $diagnosis ) || 'child_did_not_respond' !== ( $diagnosis['diagnosis_id'] ?? '' ) ) {
            return false;
        }
        foreach ( $diagnosis['facts'] ?? array() as $fact ) {
            if ( 'http_status' === ( $fact['id'] ?? '' ) ) {
                $status = (int) ( $fact['value'] ?? 0 );
                return ( $status >= 200 && $status < 300 ) || 404 === $status;
            }
        }
        return false;
    }

    /**
     * Return true only for globally routable addresses.
     *
     * @param string $ip IP address.
     *
     * @return bool
     */
    public static function is_global_ip( $ip ) {
        $packed = inet_pton( $ip );
        if ( false === $packed ) {
            return false;
        }

        // Treat IPv4-mapped IPv6 peers according to the embedded IPv4 address.
        if ( 16 === strlen( $packed ) && 0 === substr_compare( $packed, str_repeat( "\0", 10 ) . "\xff\xff", 0, 12 ) ) {
            $mapped = inet_ntop( substr( $packed, 12 ) );
            return false !== $mapped && self::is_global_ip( $mapped );
        }

        if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return false;
        }

        if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            foreach ( array( '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4' ) as $cidr ) {
                if ( self::ip_in_cidr( $ip, $cidr ) ) {
                    return false;
                }
            }
            return true;
        }

        foreach ( array( '64:ff9b:1::/48', '100::/64', '2001:db8::/32', '2001:10::/28', '2001:20::/28', 'fc00::/7', 'fe80::/10', 'ff00::/8' ) as $cidr ) {
            if ( self::ip_in_cidr( $ip, $cidr ) ) {
                return false;
            }
        }
        return true;
    }

    /** Check an IPv4 or IPv6 address against one CIDR. */
    private static function ip_in_cidr( $ip, $cidr ) {
        list( $network, $prefix ) = explode( '/', $cidr, 2 );
        $ip_bytes                 = inet_pton( $ip );
        $network_bytes            = inet_pton( $network );
        if ( false === $ip_bytes || false === $network_bytes || strlen( $ip_bytes ) !== strlen( $network_bytes ) ) {
            return false;
        }
        $bits = (int) $prefix;
        for ( $i = 0; $i < strlen( $ip_bytes ) && $bits > 0; $i++ ) {
            $used = min( 8, $bits );
            $mask = ( 0xff << ( 8 - $used ) ) & 0xff;
            if ( ( ord( $ip_bytes[ $i ] ) & $mask ) !== ( ord( $network_bytes[ $i ] ) & $mask ) ) {
                return false;
            }
            $bits -= $used;
        }
        return true;
    }

    /**
     * Build a closed diagnosis array.
     */
    private static function make_diagnosis( $verdict, $diagnosis_id, $category, $category_confidence, $phase, $facts, $provider = null, $provider_confidence = 'none' ) {
        $diagnosis = array(
            'verdict'             => $verdict,
            'diagnosis_id'        => $diagnosis_id,
            'category'            => $category,
            'category_confidence' => $category_confidence,
            'provider_confidence' => $provider_confidence,
            'phase'               => $phase,
            'facts'               => array_values( $facts ),
        );
        if ( null !== $provider ) {
            $diagnosis['provider'] = $provider;
        }
        return $diagnosis;
    }

    /**
     * Generate localized presentation only from stable IDs.
     */
    private static function presentation( $diagnosis, $observation ) { // phpcs:ignore -- NOSONAR - registry intentionally keeps copy in one auditable location.
        $id          = $diagnosis['diagnosis_id'];
        $title       = esc_html__( 'The connection test returned an unexpected response.', 'mainwp' );
        $explanation = esc_html__( 'MainWP could not verify communication with MainWP Child.', 'mainwp' );
        $steps       = array( esc_html__( 'Check the site URL and try the test again.', 'mainwp' ) );

        switch ( $id ) {
            case 'child_responded':
                $title       = esc_html__( 'MainWP Child responded to this test request.', 'mainwp' );
                $explanation = esc_html__( 'This confirms that MainWP Child handled the test request. It does not confirm registration or future connection state.', 'mainwp' );
                $steps       = array();
                break;
            case 'child_responded_authentication_unconfirmed':
                $title       = esc_html__( 'MainWP Child responded, but authentication was not confirmed.', 'mainwp' );
                $explanation = esc_html__( 'MainWP Child handled the request, but the saved connection keys were not accepted for the tested settings.', 'mainwp' );
                $steps       = array( esc_html__( 'Confirm that these settings point to the connected site, then reconnect it if needed.', 'mainwp' ) );
                break;
            case 'child_responded_via_fallback':
                $title       = esc_html__( 'MainWP Child responded through a compatibility fallback.', 'mainwp' );
                $explanation = esc_html__( 'The primary MainWP endpoint failed, but MainWP Child responded through the compatibility path.', 'mainwp' );
                $steps       = array( esc_html__( 'Check whether wp-admin or admin-ajax.php has been renamed, redirected, or blocked.', 'mainwp' ) );
                break;
            case 'dashboard_configuration_error':
                $title       = esc_html__( 'The Dashboard could not start the connection test.', 'mainwp' );
                $explanation = esc_html__( 'A local Dashboard configuration or signing problem prevented the request from being sent.', 'mainwp' );
                $steps       = array( esc_html__( 'Review the Dashboard server configuration and OpenSSL support, then try again.', 'mainwp' ) );
                break;
            case 'dashboard_signing_failed':
                $title       = esc_html__( 'The Dashboard could not sign the test request.', 'mainwp' );
                $explanation = esc_html__( 'The saved connection key could not be used, so no request was sent to the site.', 'mainwp' );
                $steps       = array( esc_html__( 'Check OpenSSL support on the Dashboard, then reconnect the site if the saved key is no longer valid.', 'mainwp' ) );
                break;
            case 'dashboard_invalid_url':
                $title       = esc_html__( 'The Dashboard could not test this site URL.', 'mainwp' );
                $explanation = esc_html__( 'The entered URL or port is not valid for a MainWP connection test.', 'mainwp' );
                $steps       = array( esc_html__( 'Enter the full HTTP or HTTPS site URL and try again.', 'mainwp' ) );
                break;
            case 'dns_resolution_failed':
                $title       = esc_html__( 'The site hostname could not be resolved.', 'mainwp' );
                $explanation = esc_html__( 'The Dashboard could not find an address for the entered site hostname.', 'mainwp' );
                $steps       = array( esc_html__( 'Check the site URL and its DNS records.', 'mainwp' ) );
                break;
            case 'connection_timed_out':
                $title       = esc_html__( 'The connection request timed out.', 'mainwp' );
                $explanation = esc_html__( 'The site did not complete the request within the connection test time limit.', 'mainwp' );
                $steps       = array( esc_html__( 'Try again, then ask the host to check slow or blocked requests from the Dashboard.', 'mainwp' ) );
                break;
            case 'tls_connection_failed':
                $title       = esc_html__( 'The secure connection could not be established.', 'mainwp' );
                $explanation = esc_html__( 'The Dashboard encountered a TLS or certificate error before MainWP communication could be verified.', 'mainwp' );
                $steps       = array( esc_html__( 'Correct the site certificate or TLS configuration, then try again.', 'mainwp' ) );
                break;
            case 'redirect_not_followed':
                $title       = esc_html__( 'The MainWP request was redirected.', 'mainwp' );
                $explanation = esc_html__( 'The test stopped at the redirect and did not forward credentials or request data.', 'mainwp' );
                $steps       = array( esc_html__( 'Update the site URL or server configuration so the MainWP endpoint does not redirect.', 'mainwp' ) );
                break;
            case 'http_authentication_required':
                $title       = esc_html__( 'HTTP authentication blocked the MainWP request.', 'mainwp' );
                $explanation = esc_html__( 'The server requested HTTP Basic Authentication before MainWP Child could respond.', 'mainwp' );
                $steps       = array( esc_html__( 'Enter the correct HTTP authentication credentials and test again.', 'mainwp' ) );
                break;
            case 'http_authentication_rejected':
                $title       = esc_html__( 'The HTTP authentication credentials were rejected.', 'mainwp' );
                $explanation = esc_html__( 'The server requested HTTP Basic Authentication after credentials were supplied.', 'mainwp' );
                $steps       = array( esc_html__( 'Check the HTTP authentication username and password, then test again.', 'mainwp' ) );
                break;
            case 'request_rate_limited':
                $title       = esc_html__( 'The server rate-limited the MainWP request.', 'mainwp' );
                $explanation = esc_html__( 'The server returned HTTP 429 before MainWP communication could be verified.', 'mainwp' );
                $steps       = array( esc_html__( 'Wait and try again, or ask the host to allow MainWP Dashboard requests.', 'mainwp' ) );
                break;
            case 'imunify360_request_blocked':
                $title       = esc_html__( 'Imunify360 appears to have blocked the MainWP request.', 'mainwp' );
                $explanation = esc_html__( 'The response contained a distinctive Imunify360 block marker.', 'mainwp' );
                $steps       = array( esc_html__( 'Ask the host to allow requests from your MainWP Dashboard.', 'mainwp' ) );
                break;
            case 'cloudflare_request_blocked':
                $title       = esc_html__( 'Cloudflare appears to have challenged the MainWP request.', 'mainwp' );
                $explanation = esc_html__( 'The response contained distinctive Cloudflare challenge markers.', 'mainwp' );
                $steps       = array( esc_html__( 'Exclude the MainWP endpoint from the applicable Cloudflare challenge or firewall rule.', 'mainwp' ) );
                break;
            case 'sucuri_request_blocked':
                $title       = esc_html__( 'Sucuri appears to have blocked the MainWP request.', 'mainwp' );
                $explanation = esc_html__( 'The response contained a distinctive Sucuri firewall block marker.', 'mainwp' );
                $steps       = array( esc_html__( 'Allow MainWP Dashboard requests in the Sucuri firewall configuration.', 'mainwp' ) );
                break;
            case 'request_blocked':
                $title       = esc_html__( 'A security layer appears to have blocked the MainWP request.', 'mainwp' );
                $explanation = esc_html__( 'The server rejected the request, but the response did not reliably identify a specific provider.', 'mainwp' );
                $steps       = array( esc_html__( 'Review the site firewall and ask the host to allow MainWP Dashboard requests.', 'mainwp' ) );
                break;
            case 'cached_response_intercepted':
                $title       = esc_html__( 'A cached response appears to have intercepted the MainWP request.', 'mainwp' );
                $explanation = esc_html__( 'The response showed a cache hit but did not contain a MainWP Child response frame.', 'mainwp' );
                $steps       = array( esc_html__( 'Exclude MainWP and admin-ajax.php requests from page caching.', 'mainwp' ) );
                break;
            case 'server_error_response':
                $title       = esc_html__( 'The server returned an error.', 'mainwp' );
                $explanation = esc_html__( 'The server returned a 5xx response before MainWP communication could be verified.', 'mainwp' );
                $steps       = array( esc_html__( 'Check the site server logs or ask the host to investigate the server error.', 'mainwp' ) );
                break;
            case 'mainwp_child_error':
                $title       = esc_html__( 'MainWP Child returned an error.', 'mainwp' );
                $explanation = esc_html__( 'MainWP Child handled the request but could not complete the requested connection action.', 'mainwp' );
                $steps       = array( esc_html__( 'Review the connection settings and try again.', 'mainwp' ) );
                break;
            case 'connection_action_failed':
                $title       = esc_html__( 'MainWP Child responded, but the connection action failed.', 'mainwp' );
                $explanation = esc_html__( 'A follow-up diagnostic reached MainWP Child after the requested add or reconnect action failed.', 'mainwp' );
                $steps       = array( esc_html__( 'Review the connection settings and retry the original action.', 'mainwp' ) );
                break;
            case 'child_did_not_respond':
                $title       = esc_html__( 'The server responded, but MainWP Child did not.', 'mainwp' );
                $explanation = esc_html__( 'The plugin may be missing or inactive, or the request may not have reached MainWP Child.', 'mainwp' );
                $steps       = array( esc_html__( 'Confirm that MainWP Child is active and that security or caching rules do not intercept MainWP requests.', 'mainwp' ) );
                break;
        }

        return array(
            'title'       => $title,
            'explanation' => $explanation,
            'steps'       => array_slice( $steps, 0, 3 ),
            'facts'       => self::fact_labels( $diagnosis['facts'] ),
            'server'      => self::server_row( $diagnosis, $observation ),
            'child'       => self::child_row( $diagnosis ),
            'docs_url'    => self::DOCS_URL,
        );
    }

    /** Build display-only labels from allowlisted facts. */
    private static function fact_labels( $facts ) {
        $labels = array();
        foreach ( $facts as $fact ) {
            $id = isset( $fact['id'] ) ? $fact['id'] : '';
            switch ( $id ) {
                case 'http_status':
                    $labels[] = sprintf( esc_html__( 'HTTP %d', 'mainwp' ), (int) ( $fact['value'] ?? 0 ) );
                    break;
                case 'curl_errno':
                    $labels[] = sprintf( esc_html__( 'cURL error %d', 'mainwp' ), (int) ( $fact['value'] ?? 0 ) );
                    break;
                case 'mainwp_frame_received':
                    $labels[] = esc_html__( 'MainWP response frame received', 'mainwp' );
                    break;
                case 'mainwp_frame_not_received':
                    $labels[] = esc_html__( 'MainWP response frame not received', 'mainwp' );
                    break;
                case 'authentication_confirmed':
                    $labels[] = esc_html__( 'Saved connection authentication confirmed', 'mainwp' );
                    break;
                case 'authentication_not_confirmed':
                    $labels[] = esc_html__( 'Saved connection authentication not confirmed', 'mainwp' );
                    break;
                case 'redirect_not_followed':
                    $labels[] = esc_html__( 'Redirect not followed', 'mainwp' );
                    break;
                case 'cache_hit_detected':
                    $labels[] = esc_html__( 'Cache-hit marker detected', 'mainwp' );
                    break;
                case 'request_not_sent':
                    $labels[] = esc_html__( 'Request not sent', 'mainwp' );
                    break;
                case 'primary_endpoint_failed':
                    $labels[] = esc_html__( 'Primary MainWP endpoint did not respond', 'mainwp' );
                    break;
                case 'connection_action_failed':
                    $labels[] = esc_html__( 'Connection action failed', 'mainwp' );
                    break;
                case 'http_auth_credentials_missing':
                    $labels[] = esc_html__( 'HTTP authentication credentials not supplied', 'mainwp' );
                    break;
                case 'http_auth_credentials_supplied':
                    $labels[] = esc_html__( 'HTTP authentication credentials supplied', 'mainwp' );
                    break;
                case 'imunify360_marker_detected':
                    $labels[] = esc_html__( 'Imunify360 block marker detected', 'mainwp' );
                    break;
                case 'cloudflare_marker_detected':
                    $labels[] = esc_html__( 'Cloudflare challenge marker detected', 'mainwp' );
                    break;
                case 'sucuri_marker_detected':
                    $labels[] = esc_html__( 'Sucuri block marker detected', 'mainwp' );
                    break;
            }
        }
        return array_slice( $labels, 0, 3 );
    }

    /** Build the server supporting row. */
    private static function server_row( $diagnosis, $observation ) {
        $http = isset( $observation['http_status'] ) ? (int) $observation['http_status'] : 0;
        if ( $http > 0 ) {
            return array(
                'state' => $http >= 200 && $http < 300 ? 'success' : 'warning',
                'label' => sprintf( esc_html__( 'Server response — HTTP %d', 'mainwp' ), $http ),
            );
        }
        if ( 'not_tested' === $diagnosis['verdict'] ) {
            return array(
                'state' => 'not_tested',
                'label' => esc_html__( 'Server request — Not sent', 'mainwp' ),
            );
        }
        return array(
            'state' => 'failure',
            'label' => esc_html__( 'Server response — Not received', 'mainwp' ),
        );
    }

    /** Build the MainWP Child supporting row. */
    private static function child_row( $diagnosis ) {
        if ( 'mainwp_child_responded' === $diagnosis['category'] ) {
            if ( 'failure' === $diagnosis['verdict'] ) {
                return array(
                    'state' => 'failure',
                    'label' => esc_html__( 'MainWP Child — Responded, but the action failed', 'mainwp' ),
                );
            }
            return array(
                'state' => 'warning' === $diagnosis['verdict'] ? 'warning' : 'success',
                'label' => esc_html__( 'MainWP Child — Responded', 'mainwp' ),
            );
        }
        if ( 'mainwp_child_error' === $diagnosis['category'] ) {
            return array(
                'state' => 'failure',
                'label' => esc_html__( 'MainWP Child — Responded with an error', 'mainwp' ),
            );
        }
        if ( in_array( $diagnosis['category'], array( 'dashboard_configuration', 'dns_failure', 'connection_timeout', 'tls_failure' ), true ) ) {
            return array(
                'state' => 'not_tested',
                'label' => esc_html__( 'MainWP Child — Not tested', 'mainwp' ),
            );
        }
        return array(
            'state' => 'failure',
            'label' => esc_html__( 'MainWP Child — Did not respond', 'mainwp' ),
        );
    }

    /** Detect distinctive provider markers without exposing response content. */
    private static function detect_provider( $observation ) {
        if ( ! empty( $observation['via_proxy'] ) ) {
            return null;
        }
        $peer = isset( $observation['peer_ip'] ) ? $observation['peer_ip'] : '';
        if ( '' !== $peer && ! self::is_global_ip( $peer ) ) {
            return null;
        }

        $body = isset( $observation['bounded_body_sample'] ) ? $observation['bounded_body_sample'] : '';
        if ( ! self::is_safe_text( $body ) ) {
            return null;
        }
        $body_lower = strtolower( $body );
        $headers    = self::final_headers( $observation );

        if ( false !== strpos( $body_lower, 'wsidchk' ) ) {
            return 'imunify360';
        }

        $cf_mitigated = self::header_contains( $headers, 'cf-mitigated', 'challenge' );
        $cf_ray       = isset( $headers['cf-ray'] );
        $cf_body      = false !== strpos( $body_lower, 'challenge-platform' ) || false !== strpos( $body_lower, 'cf-chl-' ) || false !== strpos( $body_lower, 'attention required! | cloudflare' );
        if ( $cf_mitigated || ( $cf_ray && $cf_body ) ) {
            return 'cloudflare';
        }

        $sucuri_body = false !== strpos( $body_lower, 'sucuri website firewall - access denied' ) || false !== strpos( $body_lower, 'access denied - sucuri website firewall' );
        if ( $sucuri_body && ( isset( $headers['x-sucuri-id'] ) || false !== strpos( $body_lower, 'sucuri' ) ) ) {
            return 'sucuri';
        }

        return null;
    }

    /** Return the final response header block. */
    private static function final_headers( $observation ) {
        $blocks = isset( $observation['header_blocks'] ) && is_array( $observation['header_blocks'] ) ? $observation['header_blocks'] : array();
        for ( $i = count( $blocks ) - 1; $i >= 0; $i-- ) {
            if ( isset( $blocks[ $i ]['headers'] ) && is_array( $blocks[ $i ]['headers'] ) ) {
                return $blocks[ $i ]['headers'];
            }
        }
        return array();
    }

    /** Check an allowlisted header value. */
    private static function header_contains( $headers, $name, $needle ) {
        if ( empty( $headers[ $name ] ) || ! is_array( $headers[ $name ] ) ) {
            return false;
        }
        foreach ( $headers[ $name ] as $value ) {
            if ( false !== stripos( $value, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    /** Detect an HTTP Basic challenge. */
    private static function has_basic_auth_challenge( $observation ) {
        return self::header_contains( self::final_headers( $observation ), 'www-authenticate', 'basic' );
    }

    /** Detect strong cache-hit signals. */
    private static function has_cache_hit_signal( $observation ) {
        $headers = self::final_headers( $observation );
        foreach ( array( 'x-cache', 'x-cache-status', 'x-litespeed-cache', 'cf-cache-status' ) as $name ) {
            if ( self::header_contains( $headers, $name, 'hit' ) ) {
                return true;
            }
        }
        if ( ! empty( $headers['age'][0] ) && ctype_digit( (string) $headers['age'][0] ) && (int) $headers['age'][0] > 0 ) {
            return true;
        }
        return false;
    }

    /** Skip body-derived detection for binary or malformed input. */
    private static function is_safe_text( $body ) {
        if ( ! is_string( $body ) || '' === $body || 1 !== preg_match( '//u', $body ) ) {
            return false;
        }
        return 0 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $body );
    }

    /** cURL TLS and certificate error numbers supported across shipped libcurl versions. */
    private static function tls_errno_values() {
        return array( 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 );
    }
}
