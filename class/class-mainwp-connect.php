<?php
/**
 * MainWP Connect
 *
 * MainWP Connect functions.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Connect
 *
 * @package MainWP\Dashboard
 */
class MainWP_Connect { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // phpcs:disable WordPress.DB.RestrictedFunctions, Generic.Metrics.CyclomaticComplexity, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- Using cURL functions.

    /**
     * Option key for an optional child-request total timeout override.
     *
     * Unset means the built-in default. Do not store the default itself.
     */
    const CHILD_REQUEST_TIMEOUT_OPTION = 'mainwp_child_request_timeout';

    /**
     * Total child request timeout, in seconds, when the option is unset.
     */
    const CHILD_REQUEST_TIMEOUT_DEFAULT = 72000;

    /**
     * Lowest total timeout, in seconds, that may be saved.
     */
    const CHILD_REQUEST_TIMEOUT_FLOOR = 300;

    /**
     * Site-removal (`deactivate`) cap, in seconds, when the option is unset.
     */
    const DEACTIVATE_TIMEOUT_DEFAULT = 300;

    /**
     * TCP/TLS connect wait, in seconds.
     */
    const CONNECT_TIMEOUT_SECONDS = 30;

    /**
     * Browser wait for site removal, in milliseconds, matching the default deactivate cap.
     */
    const SITE_REMOVAL_AJAX_TIMEOUT_MS = 360000;

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return object Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Saved child request timeout, or 0 when the option is unset or not usable.
     *
     * Values below the floor and the built-in default are treated as unset so a
     * stored 72000 cannot override the "do not write the default" rule.
     *
     * @return int Seconds, or 0.
     */
    public static function get_saved_child_request_timeout() {
        $saved = get_option( self::CHILD_REQUEST_TIMEOUT_OPTION, false );
        if ( false === $saved || null === $saved || '' === $saved ) {
            return 0;
        }
        if ( ! is_numeric( $saved ) ) {
            return 0;
        }
        $timeout = (int) $saved;
        if ( $timeout < self::CHILD_REQUEST_TIMEOUT_FLOOR || self::CHILD_REQUEST_TIMEOUT_DEFAULT === $timeout ) {
            return 0;
        }
        return $timeout;
    }

    /**
     * Base timeout passed into mainwp_fetch_url_site_timeout.
     *
     * Unset option: 72000, or 300 for deactivate. A saved value replaces 72000.
     * A saved value above 300 also raises the deactivate cap. The filtered
     * result is not raised back to the floor, so a one-argument callback can
     * still clamp a probe (including the URL corrector at 120).
     *
     * @param string $what Request action. `deactivate` uses the shorter base.
     *
     * @return int Timeout in seconds.
     */
    public static function resolve_child_request_timeout( $what = '' ) {
        $saved = static::get_saved_child_request_timeout();
        if ( 'deactivate' === $what ) {
            $timeout = self::DEACTIVATE_TIMEOUT_DEFAULT;
            if ( $saved > self::DEACTIVATE_TIMEOUT_DEFAULT ) {
                $timeout = $saved;
            }
        } else {
            $timeout = self::CHILD_REQUEST_TIMEOUT_DEFAULT;
            if ( $saved >= self::CHILD_REQUEST_TIMEOUT_FLOOR ) {
                $timeout = $saved;
            }
        }

        /**
         * Filter: mainwp_fetch_url_site_timeout
         *
         * Filters the request timeout ( CURLOPT_TIMEOUT + PHP time limit ) used
         * for child site requests. The default argument is 72000 seconds except
         * for site removal (`deactivate`), which starts at 300 unless a higher
         * saved Child request timeout applies. Values below 1 are ignored
         * ( 0 would disable the cURL timeout ) and fall back to 72000.
         * Callbacks may return a lower positive value; that value is kept.
         *
         * @param int    $timeout Timeout in seconds.
         * @param string $what    Request action, when the caller has one.
         *
         * @since 6.2
         */
        $timeout = (int) apply_filters( 'mainwp_fetch_url_site_timeout', $timeout, $what );
        if ( $timeout <= 0 ) {
            $timeout = self::CHILD_REQUEST_TIMEOUT_DEFAULT;
        }
        return $timeout;
    }

    /**
     * Browser wait for site removal.
     *
     * Default is 360000 ms. When the deactivate cap is 360 seconds or more, the
     * browser waits 60 seconds longer than that cap.
     *
     * @return int Milliseconds.
     */
    public static function get_site_removal_ajax_timeout_ms() {
        $php_seconds = static::resolve_child_request_timeout( 'deactivate' );
        if ( $php_seconds >= 360 ) {
            return ( $php_seconds + 60 ) * 1000;
        }
        return self::SITE_REMOVAL_AJAX_TIMEOUT_MS;
    }

    /**
     * Decide whether a submitted Child request timeout is stored.
     *
     * @param mixed $raw Submitted value.
     *
     * @return int|string|false Integer to store, `delete` to remove the option, false when the value is below the floor.
     */
    public static function normalize_child_request_timeout_setting( $raw ) {
        if ( null === $raw || false === $raw ) {
            return 'delete';
        }
        if ( is_string( $raw ) ) {
            $raw = trim( wp_unslash( $raw ) );
        }
        if ( '' === $raw ) {
            return 'delete';
        }
        if ( ! is_numeric( $raw ) ) {
            return false;
        }
        $timeout = (int) $raw;
        if ( self::CHILD_REQUEST_TIMEOUT_DEFAULT === $timeout ) {
            return 'delete';
        }
        if ( $timeout < self::CHILD_REQUEST_TIMEOUT_FLOOR ) {
            return false;
        }
        return $timeout;
    }

    /**
     * Persist a Child request timeout submission.
     *
     * @param mixed $raw Submitted value.
     *
     * @return int|string|false Stored seconds, `delete`, or false when rejected.
     */
    public static function save_child_request_timeout_setting( $raw ) {
        $normalized = static::normalize_child_request_timeout_setting( $raw );
        if ( 'delete' === $normalized ) {
            delete_option( self::CHILD_REQUEST_TIMEOUT_OPTION );
            return 'delete';
        }
        if ( is_int( $normalized ) ) {
            MainWP_Utility::update_option( self::CHILD_REQUEST_TIMEOUT_OPTION, $normalized );
            return $normalized;
        }
        return false;
    }

    /**
     * Classify a non-envelope child response that reached the NOMAINWP branch.
     *
     * Imunify `wsidchk` stays ahead of timeout. Gateway statuses are TIMEOUT
     * only when cURL reported no error and the body has no mainwp envelope.
     *
     * @param mixed $data        Response body.
     * @param mixed $err         cURL error string.
     * @param mixed $http_status HTTP status.
     * @param mixed $curl_errno  cURL error number captured before curl_close.
     *
     * @return string WSIDCHK, TIMEOUT, or NOMAINWP.
     */
    public static function child_fetch_else_code( $data, $err, $http_status, $curl_errno ) {
        $body = is_string( $data ) ? $data : '';
        if ( false !== strpos( $body, 'wsidchk' ) ) {
            return 'WSIDCHK';
        }
        $kind = static::child_request_timeout_kind( $curl_errno, $err, $http_status, $data );
        if ( '' !== $kind ) {
            return 'TIMEOUT';
        }
        return 'NOMAINWP';
    }

    /**
     * Tell a total timeout, a connect timeout, and a gateway timeout apart.
     *
     * @param mixed $curl_errno  cURL error number.
     * @param mixed $err         cURL error string.
     * @param mixed $http_status HTTP status.
     * @param mixed $data        Response body.
     *
     * @return string `operation`, `connect`, `gateway`, or empty when this is not a timeout.
     */
    public static function child_request_timeout_kind( $curl_errno, $err, $http_status, $data = '' ) {
        $body = is_string( $data ) ? $data : '';
        if ( false !== strpos( $body, 'wsidchk' ) ) {
            return '';
        }
        // A partial `<mainwp` fragment is not a Child envelope. Only a body the
        // success parser would accept suppresses TIMEOUT.
        if ( static::body_has_complete_mainwp_envelope( $data ) ) {
            return '';
        }

        $err_text = is_string( $err ) ? strtolower( $err ) : '';
        $errno    = (int) $curl_errno;
        $status   = (int) $http_status;

        $is_connect = false;
        if ( false !== strpos( $err_text, 'connection timed out' ) ) {
            $is_connect = true;
        } elseif ( false !== strpos( $err_text, 'connect timed out' ) ) {
            $is_connect = true;
        } elseif ( false !== strpos( $err_text, 'resolving timed out' ) ) {
            $is_connect = true;
        } elseif ( false !== strpos( $err_text, 'ssl connection timeout' ) ) {
            $is_connect = true;
        }

        if ( $is_connect ) {
            return 'connect';
        }
        if ( 28 === $errno ) {
            return 'operation';
        }
        if ( false !== strpos( $err_text, 'timed out' ) ) {
            return 'operation';
        }
        if ( '' !== trim( $err_text ) ) {
            return '';
        }
        if ( in_array( $status, array( 408, 504, 522, 524 ), true ) ) {
            return 'gateway';
        }
        return '';
    }

    /**
     * Whether the body is a complete Child envelope.
     *
     * Matches the `<mainwp>...</mainwp>` parser used by fetch_url_site(). An
     * opening tag without the closing tag is not complete.
     *
     * @param mixed $data Response body.
     *
     * @return bool
     */
    public static function body_has_complete_mainwp_envelope( $data ) {
        if ( ! is_string( $data ) || '' === $data ) {
            return false;
        }
        return 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data );
    }

    /**
     * Child-envelope body whose decoded error is the timeout message.
     *
     * Batch handlers only special-case a mainwp envelope or a missing plugin.
     * This lets them show the timeout copy without a new handler argument.
     * The message has no URL, IP, or Location.
     *
     * @param string $kind operation, connect, or gateway.
     *
     * @return string Envelope, or empty when encoding fails.
     */
    public static function timeout_batch_handler_body( $kind ) {
        return static::handler_error_envelope( MainWP_Error_Helper::get_timeout_error_message( $kind ) );
    }

    /**
     * Build a mainwp envelope containing a single error string.
     *
     * @param string $message Error text. Must not include a URL, IP, or Location.
     *
     * @return string
     */
    public static function handler_error_envelope( $message ) {
        $payload = wp_json_encode(
            array(
                'error' => $message,
            )
        );
        if ( ! is_string( $payload ) || '' === $payload ) {
            return '';
        }
        return '<mainwp>' . base64_encode( $payload ) . '</mainwp>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- same encoding Child responses already use.
    }

    /**
     * Data passed to a fetch_urls_authed handler.
     *
     * A complete envelope is left unchanged. A timeout-shaped result with no
     * complete envelope becomes a timeout error envelope. Anything else is
     * returned as received.
     *
     * @param mixed $data        Response body.
     * @param mixed $curl_errno  cURL error number captured before curl_close.
     * @param mixed $err         cURL error string.
     * @param mixed $http_status HTTP status.
     *
     * @return mixed
     */
    public static function batch_handler_response_data( $data, $curl_errno, $err, $http_status ) {
        if ( static::body_has_complete_mainwp_envelope( $data ) ) {
            return $data;
        }
        $kind = static::child_request_timeout_kind( $curl_errno, $err, $http_status, $data );
        if ( '' === $kind ) {
            return $data;
        }
        $envelope = static::timeout_batch_handler_body( $kind );
        if ( '' === $envelope ) {
            return $data;
        }
        return $envelope;
    }

    /**
     * Result returned by try_visit() when curl_init() does not return a handle.
     *
     * No IP is included. Callers treat a non-empty error as a failed visit.
     *
     * @return array
     */
    public static function try_visit_curl_init_failure() {
        return array(
            'host'           => '',
            'httpCode'       => 0,
            'httpCodeString' => '',
            'httpVersion'    => false,
            'error'          => 'cURL could not be initialized.',
        );
    }

    /**
     * Throw the fetch_url_site() failure for an unusable curl handle.
     *
     * @param array $output Fetch output.
     *
     * @throws MainWP_Exception HTTPERROR with an empty body. The extra is the fixed init message, not a URL or IP.
     */
    public static function throw_fetch_url_site_curl_init_failure( &$output ) {
        if ( ! is_array( $output ) ) {
            $output = array();
        }
        $output['error_category'] = 'http_error';
        $output['error_code']     = 'curl_init_failed';
        $output['error_message']  = 'cURL could not be initialized.';
        $thr_error                = new MainWP_Exception( 'HTTPERROR', 'cURL could not be initialized.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        $thr_error->set_data( '' );
        throw $thr_error;
    }

    /**
     * Build a TIMEOUT exception with an empty extra.
     *
     * @param string $kind   operation, connect, or gateway.
     * @param array  $output Fetch output, updated with the timeout category.
     *
     * @return MainWP_Exception
     */
    protected static function new_timeout_exception( $kind, &$output ) {
        if ( 'gateway' !== $kind && 'connect' !== $kind ) {
            $kind = 'operation';
        }
        if ( ! is_array( $output ) ) {
            $output = array();
        }
        $output['error_category'] = 'request_timeout';
        $output['error_code']     = 'request_timeout';
        $output['error_message']  = MainWP_Error_Helper::get_timeout_error_message( $kind );
        return new MainWP_Exception( 'TIMEOUT', '', $kind );
    }

    /**
     * Method try visit.
     *
     * Try connecting to Child Site via cURL.
     *
     * @param string $url Child Site URL.
     * @param bool   $ssl_verifyhost Option to check SSL Certificate. Default = null.
     * @param string $http_user HTTPAuth Username. Default = null.
     * @param string $http_pass HTTPAuth Password. Default = null.
     * @param int    $sslVersion        Child Site SSL Version.
     * @param bool   $forceUseIPv4      Option to force IP4. Default = null.
     * @param bool   $no_body           Option to set CURLOPT_NOBODY option. Default = false.
     *
     * @return array $out. 'host IP, Returned HTTP Code, Error Message, http Status error message.
     *
     * @uses \MainWP\Dashboard\MainWP_Logger::debug()
     * @uses \MainWP\Dashboard\MainWP_System::$version
     * @uses \MainWP\Dashboard\MainWP_Utility::value_to_string()
     * @uses \MainWP\Dashboard\MainWP_Utility::get_http_codes()
     */
    public static function try_visit( $url, $ssl_verifyhost = null, $http_user = null, $http_pass = null, $sslVersion = 0, $forceUseIPv4 = null, $no_body = false ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $agent    = MainWP_Connect_Failure_Ux::get_user_agent();
        $postdata = array( 'test' => 'yes' );

        $ch = curl_init();
        if ( ! static::is_valid_curl_handle( $ch ) ) {
            return static::try_visit_curl_init_failure();
        }

        $proxy = new \WP_HTTP_Proxy();
        if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
            curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
            curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
            curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );

            if ( $proxy->use_authentication() ) {
                curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
                curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
            }
        }

        curl_setopt( $ch, CURLOPT_URL, $url );
        if ( $no_body ) {
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'HEAD' ); // HTTP request is 'HEAD', but sometime return 4xx - error code.
        }

        $follow_loc = apply_filters( 'mainwp_try_visit_follow_location', false ); // to support for case compatible.

        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, $follow_loc ? true : false );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS );
        curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
        curl_setopt( $ch, CURLOPT_ENCODING, 'none' );

        if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
            $http_pass = stripslashes( $http_pass );
            curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
        }

        if ( $ssl_verifyhost ) {
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
        } else {
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false ); // NOSONAR.
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false ); // NOSONAR.
        }

        curl_setopt( $ch, CURLOPT_SSLVERSION, $sslVersion );

        $http_version = apply_filters( 'mainwp_curl_http_version', false, false, $url );
        if ( false !== $http_version ) {
            curl_setopt( $ch, CURLOPT_HTTP_VERSION, $http_version );
        }

        $curlopt_resolve = apply_filters( 'mainwp_curl_curlopt_resolve', false, false, $url );
        if ( is_array( $curlopt_resolve ) && ! empty( $curlopt_resolve ) ) {
            curl_setopt( $ch, CURLOPT_RESOLVE, $curlopt_resolve );
            curl_setopt( $ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false );
        }

        $headers           = array( 'X-Requested-With' => 'XMLHttpRequest' );
        $headers['Expect'] = static::get_expect_header( $postdata );
        $headers           = apply_filters( 'mainwp_connect_http_request_headers', $headers, false );

        if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
            $headers = \WpOrg\Requests\Requests::flatten( $headers );
        } else {
            $headers = \Requests::flatten( $headers );
        }

        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
        curl_setopt( $ch, CURLOPT_REFERER, get_option( 'siteurl' ) );

        $resp_headers = '';
        static::capture_response_headers( $ch, $resp_headers );

        $force_use_ipv4 = false;
        if ( null !== $forceUseIPv4 ) {
            if ( 1 === $forceUseIPv4 ) {
                $force_use_ipv4 = true;
            } elseif ( 2 === $forceUseIPv4 ) {
                if ( 1 === (int) get_option( 'mainwp_forceUseIPv4' ) ) {
                    $force_use_ipv4 = true;
                }
            }
        } elseif ( 1 === (int) get_option( 'mainwp_forceUseIPv4' ) ) {
                $force_use_ipv4 = true;
        }

        if ( $force_use_ipv4 && defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
            curl_setopt( $ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
        }

        MainWP_Logger::instance()->debug( ' :: trying Visit :: [url=' . MainWP_Connect_Failure_Ux::redact_url( $url ) . ']' );

        $http_version = false;

        $disabled_functions = ini_get( 'disable_functions' );
        if ( empty( $disabled_functions ) || ( stristr( $disabled_functions, 'curl_multi_exec' ) === false ) ) {
            MainWP_Logger::instance()->debug( ' :: trying Visit :: curl_multi_exec => enabled.' );
            $mh = curl_multi_init();
            @curl_multi_add_handle( $mh, $ch );

            do {
                do {
                    $mrc = curl_multi_exec( $mh, $running );
                } while ( CURLM_CALL_MULTI_PERFORM === $mrc );

                if ( $running ) {
                    $rc = curl_multi_select( $mh, 1.0 );
                    if ( -1 === $rc ) {
                        usleep( 100000 );
                    }
                }

                while ( $info = curl_multi_info_read( $mh ) ) {
                    $data        = curl_multi_getcontent( $info['handle'] );
                    $err         = curl_error( $info['handle'] );
                    $http_status = curl_getinfo( $info['handle'], CURLINFO_HTTP_CODE );
                    $realurl     = curl_getinfo( $info['handle'], CURLINFO_EFFECTIVE_URL );
                    if ( defined( 'CURLINFO_HTTP_VERSION' ) ) {
                        $http_version = curl_getinfo( $info['handle'], CURLINFO_HTTP_VERSION );
                    }

                    curl_multi_remove_handle( $mh, $info['handle'] );
                    curl_close( $info['handle'] );
                }
                usleep( 10000 );

            } while ( $running > 0 );

            if ( static::is_valid_curl_handle( $mh ) ) {
                curl_multi_close( $mh );
            }
        } else {
            $data        = curl_exec( $ch );
            $err         = curl_error( $ch );
            $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $realurl     = curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );

            if ( defined( 'CURLINFO_HTTP_VERSION' ) ) {
                $http_version = curl_getinfo( $ch, CURLINFO_HTTP_VERSION );
            }

            if ( static::is_valid_curl_handle( $ch ) ) {
                curl_close( $ch );
            }
        }

        MainWP_Logger::instance()->log_execution_time( 'tryVisit :: [url=' . MainWP_Connect_Failure_Ux::redact_url( $url ) . '] [http_status=' . $http_status . ']' );

        $host   = wp_parse_url( ( empty( $realurl ) ? $url : $realurl ), PHP_URL_HOST );
        $ip     = false;
        $target = false;

        // Ask only for the record types read below. dns_get_record() defaults to DNS_ANY, which
        // most resolvers now refuse or answer with a stub (RFC 8482), so it buys retries and
        // timeouts instead of answers. Names that exist only in the hosts file are not resolved
        // here at all -- dns_get_record() never reads the hosts file -- they fall through to the
        // gethostbynamel() call below.
        $found     = false;
        $dnsRecord = @dns_get_record( $host, DNS_A | DNS_AAAA | DNS_CNAME );
        MainWP_Logger::instance()->debug( ' :: tryVisit :: [dnsRecord=' . MainWP_Utility::value_to_string( $dnsRecord, 1 ) . ']' );

        if ( false !== $dnsRecord && is_array( $dnsRecord ) ) {
            if ( ! isset( $dnsRecord['ip'] ) ) {
                foreach ( $dnsRecord as $dnsRec ) {
                    if ( isset( $dnsRec['ip'] ) ) {
                        $ip = $dnsRec['ip'];
                        break;
                    }
                }
            } else {
                $ip = $dnsRecord['ip'];
            }

            if ( ! isset( $dnsRecord['host'] ) ) {
                foreach ( $dnsRecord as $dnsRec ) {
                    if ( $dnsRec['host'] === $host ) {
                        if ( 'CNAME' === $dnsRec['type'] ) {
                            $target = $dnsRec['target'];
                        }
                        $found = true;
                        break;
                    }
                }
            } else {
                $found = ( $dnsRecord['host'] === $host );
                if ( 'CNAME' === $dnsRecord['type'] ) {
                    $target = $dnsRecord['target'];
                }
            }
        }

        if ( false === $ip ) {
            $ip = gethostbynamel( $host );
        }
        if ( ( false !== $target ) && ( $target !== $host ) ) {
            $host .= ' (CNAME: ' . $target . ')';
        }

        $out = array(
            'host'           => $host,
            'httpCode'       => $http_status,
            'httpCodeString' => MainWP_Utility::get_http_codes( $http_status ),
            'httpVersion'    => $http_version,
        );

        $hidden_data = '[hidden response data]';
        $hide_raw    = (bool) apply_filters( 'mainwp_hide_raw_connection_response_data', true );

        if ( ( false === $ip || $ip === $host || ! static::validate_ip( $ip ) ) && $hide_raw ) { // Failed to resolve hostname.
            $data = $hidden_data;
        }

        $response_bytes = is_string( $data ) ? strlen( $data ) : 0;
        MainWP_Logger::instance()->debug( ' :: tryVisit :: [url=' . MainWP_Connect_Failure_Ux::redact_url( $url ) . '] [http_status=' . $http_status . '] [http_version=' . ( false === $http_version ? 'N/A' : MainWP_System_Utility::get_http_version_const_str( $http_version ) ) . '] [error=' . MainWP_Connect_Failure_Ux::sanitize_diagnostic_text( (string) $err ) . '] [response_bytes=' . $response_bytes . ']' );

        if ( false !== $ip ) {
            $out['ip'] = $ip;
            $found     = true;
        }

        $out['error'] = ( '' === $err && false === $found ? 'Invalid host.' : $err );

        $response_body = '';
        if ( ! $hide_raw && is_string( $data ) ) {
            $response_body = $data;
        }

        $out = MainWP_Connect_Failure_Ux::enrich_output(
            $out,
            array(
                'http_status'      => $http_status,
                'headers'          => $resp_headers,
                'body'             => $response_body,
                'resolved_ip'      => $ip,
                'error'            => $out['error'],
                'skip_outbound_ip' => ( '' === $out['error'] && 200 === (int) $http_status ),
            )
        );

        return $out;
    }

    /**
     * Capture response headers on a cURL handle without changing the request.
     *
     * @param resource|\CurlHandle $ch      cURL handle.
     * @param string               $headers Captured header text.
     *
     * @return void
     */
    public static function capture_response_headers( $ch, &$headers ) {
        $headers = '';
        if ( ! static::is_valid_curl_handle( $ch ) ) {
            return;
        }
        curl_setopt(
            $ch,
            CURLOPT_HEADERFUNCTION,
            static function ( $curl, $header_line ) use ( &$headers ) {
                unset( $curl );
                $headers .= $header_line;
                return strlen( $header_line );
            }
        );
    }


    /**
     * Method validate_ip().
     *
     * @param  string $ip IP check.
     * @return bool Check IP result.
     */
    public static function validate_ip( $ip ) {
        // Validate the IP and check for private and reserved ranges.
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return true;
        }
        return false;
    }

    /**
     * Method check_ignored_http_code()
     *
     * Check if http error code is being ignored.
     *
     * @param mixed        $value http error code.
     * @param object|false $website website.
     *
     * @return bolean True|False.
     */
    public static function check_ignored_http_code( $value, $website = false ) { // phpcs:ignore -- NOSONAR -complex method.
        $value           = (int) $value;
        $site_id         = is_object( $website ) && ! empty( $website->id ) ? $website->id : 0;
        $global_settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();

        $ignored_code = '';

        if ( $site_id ) {

            $primary_monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site_id, 'issub', 0 );
            $global_settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();

            $mo_active = 0;
            if ( $primary_monitor ) {
                $mo_active = MainWP_Uptime_Monitoring_Connect::get_apply_setting( 'active', (int) $primary_monitor->active, $global_settings, -1, 0 );
            }

            if ( $mo_active ) {
                $ignored_code = MainWP_Uptime_Monitoring_Connect::instance()->get_up_codes( $primary_monitor, $global_settings );
            } else {
                $ignored_code = is_array( $global_settings ) && isset( $global_settings['up_status_codes'] ) ? $global_settings['up_status_codes'] : '';
            }
        } else {
            $ignored_code = ! empty( $global_settings['up_status_codes'] ) ? $global_settings['up_status_codes'] : '';
        }

        if ( ! empty( $ignored_code ) ) {
            $ignored_code = explode( ',', $ignored_code );
            foreach ( $ignored_code as $code ) {
                $code = trim( $code );
                if ( (int) $value === (int) $code ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Method check website status.
     *
     * Check if the Website returns and http errors.
     *
     * @param object $website Child Site information.
     * @param bool   $chk_http_site Check site http response.
     *
     * @return mixed False|try visit result.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::is_domain_valid()
     */
    public static function check_website_status( $website, $chk_http_site = false ) { //phpcs:ignore -- NOSONAR - complexity.

        if ( is_object( $website ) && isset( $website->id ) ) {
            $primary_monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $website->id, 'issub', 0 );
            if ( $primary_monitor ) {
                // return compatible uptime status here.
                return MainWP_Uptime_Monitoring_Handle::check_website_uptime_monitoring_status(
                    $primary_monitor,
                    array(
                        'ignore_compatible_save' => 1,
                        'check_http_site'        => $chk_http_site,
                    )
                ); // to ignore save compatible uptime status.
            }
        }

        $http_user         = null;
        $http_pass         = null;
        $sslVersion        = null;
        $verifyCertificate = null;
        $forceUseIPv4      = null;
        if ( is_object( $website ) && isset( $website->url ) ) {
            $url               = $website->url;
            $verifyCertificate = isset( $website->verify_certificate ) ? (int) $website->verify_certificate : null;
            $forceUseIPv4      = $website->force_use_ipv4;
            // MWP-1548: decrypt at the boundary so HTTP Basic Auth gets
            // the plaintext credentials. Legacy plaintext rows pass
            // through unchanged via the helper's fallback.
            $http_user  = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
            $http_pass  = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );
            $sslVersion = $website->ssl_version;
        } else {
            $url = $website;
        }

        if ( ! MainWP_Utility::is_domain_valid( $url ) ) {
            return false;
        }

        $ssl_verifyhost = false;

        if ( 1 === $verifyCertificate ) {
            $ssl_verifyhost = true;
        } elseif ( 2 === $verifyCertificate || null === $verifyCertificate ) {
            if ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === get_option( 'mainwp_sslVerifyCertificate' ) ) ) {
                $ssl_verifyhost = true;
            }
        }

        $noBody = false;
        return static::try_visit( $url, $ssl_verifyhost, $http_user, $http_pass, $sslVersion, $forceUseIPv4, $noBody );
    }

    /**
     * Method get_post_data_authed()
     *
     * Get authorized $_POST data & build query.
     *
     * @param mixed $website Array of Child Site Info.
     * @param mixed $what What we are posting.
     * @param null  $params Post parameters.
     * @param array $others Other data.
     *
     * @return mixed null|http_build_query()
     */
    public static function get_post_data_authed( &$website, $what, $params = null, $others = array() ) {  //phpcs:ignore -- NOSONAR - complex method.

        if ( ! is_array( $others ) ) {
            $others = array();
        }

        $verify_signature = ! empty( $others['verify_signature'] ) ? true : false;
        $http_user_plain  = ! empty( $others['http_user_plain'] ) ? $others['http_user_plain'] : '';
        $http_pass_plain  = ! empty( $others['http_pass_plain'] ) ? $others['http_pass_plain'] : '';

        if ( $website && '' !== $what ) {
            // The nonce must never repeat. The Child stores every legacy signature it accepts, forever,
            // and refuses a repeat as "This request has already been used"; signing is deterministic,
            // so the nonce is the only thing that makes two requests for the same action differ.
            $data              = array();
            $data['user']      = $website->adminname;
            $data['function']  = $what;
            $data['nonce']     = bin2hex( random_bytes( 16 ) );
            $data['mainwpver'] = MainWP_System::$version;

            $params_filter = apply_filters( 'mainwp_pre_fetch_authed_data', false, $params, $what, $website, $verify_signature );
            if ( is_array( $params_filter ) && ! empty( $params_filter ) ) {
                $data = array_merge( $data, $params_filter );
            }

            if ( null !== $params ) {
                $data = array_merge( $data, $params );
            }

            $alg          = false;
            $sign_success = null;
            $use_seclib   = false;

            $data = apply_filters( 'mainwp_get_post_data_authed', $data, $website, $what, $params, $verify_signature );

            $alt_user = '';

            /**
             * Current user global.
             *
             * @global string
             */
            global $current_user;

            if ( ( ! defined( 'DOING_CRON' ) || false === DOING_CRON ) && ( ! defined( 'WP_CLI' ) || false === WP_CLI ) && is_object( $current_user ) && property_exists( $current_user, 'ID' ) && $current_user->ID ) {
                /**
                 * Filter: mainwp_alter_login_user
                 *
                 * Filters users accounts so it allows you user to jump to child site under alternative administrator account.
                 *
                 * @param int $website->id Child site ID.
                 * @param int $current_user->ID User ID.
                 *
                 * @since Unknown
                 */
                $alt_user = apply_filters( 'mainwp_alter_login_user', false, $website->id, $current_user->ID );

            }

            $child_support_adv_sign = 1 === (int) MainWP_DB::instance()->get_website_option( $website, 'support_advanced_sign' );

            $alg        = false;
            $sign_value = $what . $data['nonce']; // Legacy signature data.

            if ( ! $verify_signature || ! $child_support_adv_sign ) {
                if ( MainWP_Connect_Lib::is_use_fallback_sec_lib( $website ) ) {
                    $sign_success = MainWP_Connect_Lib::connect_sign( $sign_value, $signature, base64_decode( $website->privkey ), $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                    $use_seclib   = true;
                } elseif ( function_exists( 'openssl_verify' ) ) {
                    $alg          = MainWP_System_Utility::get_connect_sign_algorithm( $website );
                    $sign_success = static::connect_sign( $sign_value, $signature, base64_decode( $website->privkey ), $alg, $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                    if ( false !== $alg ) {
                        $data['sign_algo'] = $alg;
                    }
                }

                if ( null !== $sign_success && empty( $sign_success ) ) {
                    $sign_error = '';
                    while ( $msg = openssl_error_string() ) {
                        if ( is_string( $msg ) ) {
                            $sign_error .= $msg;
                        }
                    }
                    MainWP_Logger::instance()->warning_for_website( $website, 'CONNECT SIGN', 'FAILED :: [what=' . ( is_string( $what ) ? $what : '' ) . '] :: [seclib=' . intval( $use_seclib ) . '] :: [algorithm=' . $alg . '] :: [openssl_sign error =' . $sign_error . ']', false );
                }
            } else {
                $signature = 'useadvancedmainwpsignature';
            }

            $data['mainwpsignature'] = ! empty( $signature ) ? base64_encode( $signature ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

            if ( $verify_signature ) {
                $ts           = time();
                $data_sign_v2 = array(
                    'base_function' => $what,
                    'nonce'         => $data['nonce'],
                    'expires'       => $ts + 60,
                    'user'          => $website->adminname,
                    'req_id'        => wp_generate_uuid4(),
                );

                if ( ! empty( $alt_user ) ) {
                    $data_sign_v2['alt_user'] = rawurlencode( $alt_user );
                }

                if ( 'process_premium_updates' === $what ) {
                    $add_sign_params = array( 'premium_perform', 'premium_type', 'list' );
                    foreach ( $add_sign_params as $_name ) {
                        if ( isset( $params[ $_name ] ) ) {
                            $data_sign_v2[ $_name ] = $params[ $_name ];
                        }
                    }
                }

                $sign_success_v2 = null;

                $sign_value_v2 = wp_json_encode( $data_sign_v2 );

                if ( MainWP_Connect_Lib::is_use_fallback_sec_lib( $website ) ) {
                    $use_seclib      = true;
                    $sign_success_v2 = MainWP_Connect_Lib::connect_sign( $sign_value_v2, $signature_v2, base64_decode( $website->privkey ), $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                } elseif ( function_exists( 'openssl_verify' ) ) {
                    $alg             = MainWP_System_Utility::get_connect_sign_algorithm( $website );
                    $sign_success_v2 = static::connect_sign( $sign_value_v2, $signature_v2, base64_decode( $website->privkey ), $alg, $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                    if ( false !== $alg ) {
                        $data['sign_algo'] = $alg;
                    }
                }

                $data['data_signature']      = $sign_value_v2;
                $data['mainwpsignature_adv'] = ! empty( $signature_v2 ) ? base64_encode( $signature_v2 ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

                if ( null !== $sign_success_v2 && empty( $sign_success_v2 ) ) {
                    $sign_error = '';
                    while ( $msg = openssl_error_string() ) {
                        if ( is_string( $msg ) ) {
                            $sign_error .= $msg;
                        }
                    }
                    MainWP_Logger::instance()->warning_for_website( $website, 'CONNECT ADV SIGN', 'FAILED :: [what=' . ( is_string( $what ) ? $what : '' ) . '] :: [seclib=' . intval( $use_seclib ) . '] :: [algorithm=' . $alg . '] :: [openssl_sign error =' . $sign_error . ']', false );
                }
            }

            if ( $use_seclib ) {
                $data['verifylib'] = 1;
            }

            if ( ! empty( $alt_user ) ) {
                $data['alt_user'] = rawurlencode( $alt_user );
            }

            /** This filter is documented in ../widgets/widget-mainwp-recent-posts.php */
            $recent_number = apply_filters( 'mainwp_recent_posts_pages_number', 5 );
            if ( 5 !== $recent_number ) {
                $data['recent_number'] = $recent_number;
            }

            $scan_dir = apply_filters( 'mainwp_stats_scan_dir', false, $website );
            if ( ! empty( $scan_dir ) ) {
                $data['scan_dir'] = 1;
            }

            if ( 'process_premium_updates' === $what ) {
                if ( ! empty( $http_user_plain ) && ! empty( $http_pass_plain ) ) {
                    // For post data.
                    $data['wp_http_user'] = $http_user_plain;
                    $data['wp_http_pass'] = $http_pass_plain;
                }
                $data['sslVerify'] = $website->verify_certificate ? 1 : 0;
            }
            return http_build_query( $data, '', '&' );
        }

        return null;
    }

    /**
     * Method get_renew_post_data_authed()
     *
     * Get authorized $_POST data & build query for renew connection action only.
     *
     * @param mixed $website Array of Child Site Info.
     * @param mixed $what What we are posting.
     * @param array $others Other data.
     *
     * @return mixed null|http_build_query()
     */
    private static function get_renew_post_data_authed( &$website, $what, $others = array() ) { // phpcs:ignore -- NOSONAR - complex.

        if ( ! is_array( $others ) ) {
            $others = array();
        }

        $verify_signature = ! empty( $others['verify_signature'] ) ? true : false;

        if ( $website && '' !== $what ) {
            $compat_what      = 'disconnect'; // to compatible, renew will call disconnect.
            $data             = array();
            $data['user']     = $website->adminname;
            $data['function'] = $compat_what;
            $data['nonce']    = bin2hex( random_bytes( 16 ) );

            $sign_value = $compat_what . $data['nonce']; // compatible format.

            $alg          = false;
            $sign_success = null;
            $use_seclib   = false;

            if ( MainWP_Connect_Lib::is_use_fallback_sec_lib( $website ) ) {
                // to disconnect.
                $sign_success = MainWP_Connect_Lib::connect_sign( $sign_value, $signature, base64_decode( $website->privkey ), $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                $use_seclib   = true;
            } elseif ( function_exists( 'openssl_verify' ) ) {
                $alg          = MainWP_System_Utility::get_connect_sign_algorithm( $website );
                $sign_success = static::connect_sign( $sign_value, $signature, base64_decode( $website->privkey ), $alg, $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for keys encoding.
                if ( empty( $sign_success ) ) { // error from openssl, openssl_sign().
                    $alg = defined( 'OPENSSL_ALGO_SHA1' ) ? OPENSSL_ALGO_SHA1 : false; // to set default SHA1, to disconnect.
                    MainWP_Logger::instance()->debug_for_website( $website, 'get_renew_post_data_authed', '[' . MainWP_Connect_Failure_Ux::redact_url( $website->url ) . '] :: [openssl_sign:failed] :: Set sign_algo=SHA1' );
                    $sign_success = static::connect_sign( $sign_value, $signature, base64_decode( $website->privkey ), $alg, $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for keys encoding.
                }

                if ( false !== $alg ) {
                    $data['sign_algo'] = $alg;
                }
            }

            $data['mainwpsignature'] = ! empty( $signature ) ? base64_encode( $signature ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

            if ( null !== $sign_success && empty( $sign_success ) ) {
                $sign_error = '';
                while ( $msg = openssl_error_string() ) {
                    if ( is_string( $msg ) ) {
                        $sign_error .= $msg;
                    }
                }
                MainWP_Logger::instance()->warning_for_website( $website, 'CONNECT SIGN', 'FAILED :: [what=' . ( is_string( $what ) ? $what : '' ) . '] :: [seclib=' . intval( $use_seclib ) . '] :: [algorithm=' . $alg . '] :: [openssl_sign error =' . $sign_error . ']', false );
            }

            if ( $verify_signature ) {
                $ts            = time();
                $data_sign_v2  = array(
                    'base_function' => $compat_what,
                    'nonce'         => $data['nonce'],
                    'expires'       => $ts + 60,
                    'user'          => $website->adminname,
                    'req_id'        => wp_generate_uuid4(),
                );
                $sign_value_v2 = wp_json_encode( $data_sign_v2 );

                $sign_success_v2 = null;

                if ( MainWP_Connect_Lib::is_use_fallback_sec_lib( $website ) ) {
                    // to disconnect.
                    $sign_success_v2 = MainWP_Connect_Lib::connect_sign( $sign_value_v2, $signature_v2, base64_decode( $website->privkey ), $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                } elseif ( function_exists( 'openssl_verify' ) ) {
                    $alg             = MainWP_System_Utility::get_connect_sign_algorithm( $website );
                    $sign_success_v2 = static::connect_sign( $sign_value_v2, $signature_v2, base64_decode( $website->privkey ), $alg, $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for keys encoding.
                    if ( empty( $sign_success_v2 ) ) { // error from openssl, openssl_sign().
                        $alg = defined( 'OPENSSL_ALGO_SHA1' ) ? OPENSSL_ALGO_SHA1 : false; // to set default SHA1, to disconnect.
                        MainWP_Logger::instance()->debug_for_website( $website, 'get_renew_post_data_authed', '[' . MainWP_Connect_Failure_Ux::redact_url( $website->url ) . '] :: [openssl_sign:failed] :: Set sign_algo=SHA1' );
                        $sign_success_v2 = static::connect_sign( $sign_value_v2, $signature_v2, base64_decode( $website->privkey ), $alg, $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for keys encoding.
                    }
                    if ( false !== $alg ) {
                        $data['sign_algo'] = $alg;
                    }
                }

                $data['data_signature']      = $sign_value_v2;
                $data['mainwpsignature_adv'] = ! empty( $signature_v2 ) ? base64_encode( $signature_v2 ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

                if ( null !== $sign_success_v2 && empty( $sign_success_v2 ) ) {
                    $sign_error = '';
                    while ( $msg = openssl_error_string() ) {
                        if ( is_string( $msg ) ) {
                            $sign_error .= $msg;
                        }
                    }
                    MainWP_Logger::instance()->warning_for_website( $website, 'CONNECT ADV SIGN', 'FAILED :: [what=' . ( is_string( $what ) ? $what : '' ) . '] :: [seclib=' . intval( $use_seclib ) . '] :: [algorithm=' . $alg . '] :: [openssl_sign error =' . $sign_error . ']', false );
                }
            }

            if ( $use_seclib ) {
                $data['verifylib'] = 1;
            }

            return http_build_query( $data, '', '&' );
        }
        return null;
    }


    /**
     * Method get_get_data_authed()
     *
     * Get authorized $_GET data & build query.
     *
     * @param mixed  $website Child Site data.
     * @param mixed  $paramValue OpenSSL parameter.
     * @param string $paramName Parameter name.
     * @param bool   $asArray true|false Default is false.
     * @param array  $other_params other params.
     * @param string $custom_url Optional. Override the target base URL for browser-bound
     *                           requests ( see MainWP_Site_Url_Corrector::browser_target_url() ).
     *                           Default null keeps the stored URL � server-side callers
     *                           ( backups, premium updates ) must not pass this.
     *
     * @return string $url
     */
    public static function get_get_data_authed( $website, $paramValue, $paramName = 'where', $asArray = false, $other_params = array(), $custom_url = null ) { //phpcs:ignore -- NOSONAR - complex method.
        $params = array();
        if ( $website && '' !== $paramValue ) {

            $sign_success = null;
            $alg          = false;
            $use_seclib   = false;
            $nonce        = bin2hex( random_bytes( 16 ) );

            /**
             * Current user global.
             *
             * @global string
             */
            global $current_user;

            $alt_user = '';
            if ( ( ( ! defined( 'DOING_CRON' ) || false === DOING_CRON ) && ( ! defined( 'WP_CLI' ) || false === WP_CLI ) ) && $current_user && $current_user->ID ) {
                /** This filter is documented in ../class/class-mainwp-connect.php */
                $alt_user = apply_filters( 'mainwp_alter_login_user', false, $website->id, $current_user->ID );
            }

            $verify_signature = is_array( $other_params ) && ! empty( $other_params['verify_signature'] );

            $child_support_adv_sign = 1 === (int) MainWP_DB::instance()->get_website_option( $website, 'support_advanced_sign' );

            if ( ! $verify_signature || ! $child_support_adv_sign ) {
                $sign_value = $paramValue . $nonce; // compatible format.

                if ( MainWP_Connect_Lib::is_use_fallback_sec_lib( $website ) ) {
                    $sign_success = MainWP_Connect_Lib::connect_sign( $sign_value, $signature, base64_decode( $website->privkey ), $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                    $use_seclib   = true;
                } elseif ( function_exists( 'openssl_verify' ) ) {
                    $alg          = MainWP_System_Utility::get_connect_sign_algorithm( $website );
                    $sign_success = static::connect_sign( $sign_value, $signature, base64_decode( $website->privkey ), $alg, $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                }

                $signature = ! empty( $signature ) ? base64_encode( $signature ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

                if ( null !== $sign_success && empty( $sign_success ) ) {
                    $sign_error = '';
                    while ( $msg = openssl_error_string() ) {
                        if ( is_string( $msg ) ) {
                            $sign_error .= $msg;
                        }
                    }
                    MainWP_Logger::instance()->warning_for_website( $website, 'CONNECT SIGN', 'FAILED :: [login_required=1] :: [seclib=' . intval( $use_seclib ) . '] :: [algorithm=' . $alg . '] :: [openssl_sign error =' . $sign_error . ']', false );
                }
            } else {
                $signature = base64_encode( 'useadvancedmainwpsignature' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
            }

            $params = array(
                'login_required'  => 1,
                'user'            => rawurlencode( $website->adminname ),
                'mainwpsignature' => rawurlencode( $signature ),
                'nonce'           => $nonce,
                $paramName        => rawurlencode( $paramValue ),
            );

            if ( false !== $alg ) {
                $params['sign_algo'] = $alg;
            }

            if ( $verify_signature ) {
                $ts           = time();
                $data_sign_v2 = array(
                    'base_function' => $paramValue,
                    'where'         => rawurlencode( $paramName ),
                    'nonce'         => $nonce,
                    'expires'       => $ts + 60,
                    'user'          => $website->adminname,
                    'req_id'        => wp_generate_uuid4(),
                );

                if ( ! empty( $alt_user ) ) {
                    $data_sign_v2['alt_user'] = rawurlencode( $alt_user );
                }

                $sign_success_v2 = null;

                $sign_value_v2 = wp_json_encode( $data_sign_v2 );

                if ( MainWP_Connect_Lib::is_use_fallback_sec_lib( $website ) ) {
                    $sign_success_v2 = MainWP_Connect_Lib::connect_sign( $sign_value_v2, $signature_v2, base64_decode( $website->privkey ), $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                    $use_seclib      = true;
                } elseif ( function_exists( 'openssl_verify' ) ) {
                    $alg             = MainWP_System_Utility::get_connect_sign_algorithm( $website );
                    $sign_success_v2 = static::connect_sign( $sign_value_v2, $signature_v2, base64_decode( $website->privkey ), $alg, $website->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                    if ( false !== $alg ) {
                        $params['sign_algo'] = $alg;
                    }
                }

                $signature_v2 = ! empty( $signature_v2 ) ? base64_encode( $signature_v2 ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

                $params['data_signature']      = rawurlencode( $sign_value_v2 );
                $params['mainwpsignature_adv'] = rawurlencode( $signature_v2 );

                if ( null !== $sign_success_v2 && empty( $sign_success_v2 ) ) {
                    $sign_error = '';
                    while ( $msg = openssl_error_string() ) {
                        if ( is_string( $msg ) ) {
                            $sign_error .= $msg;
                        }
                    }
                    MainWP_Logger::instance()->warning_for_website( $website, 'CONNECT ADV SIGN', 'FAILED :: [login_required=1] :: [seclib=' . intval( $use_seclib ) . '] :: [algorithm=' . $alg . '] :: [openssl_sign error =' . $sign_error . ']', false );
                }
            }

            if ( ! empty( $alt_user ) ) {
                $params['alt_user'] = rawurlencode( $alt_user );
            }

            if ( is_array( $other_params ) ) {
                foreach ( $other_params as $name => $value ) {
                    if ( is_string( $name ) && ! empty( $name ) && is_scalar( $value ) ) {
                        $params[ sanitize_text_field( wp_unslash( $name ) ) ] = rawurlencode( sanitize_text_field( wp_unslash( $value ) ) );
                    }
                }
            }

            if ( ! empty( $params['login_required'] ) && ! empty( $params['where'] ) ) {
                $open_params = apply_filters( 'mainwp_open_site_login_required_params', false, $params, $website );
                if ( is_array( $open_params ) && ! empty( $open_params ) ) {
                    $where_params = '';
                    foreach ( $open_params as $key => $value ) {
                        $where_params .= rawurlencode( sanitize_text_field( wp_unslash( $key ) ) ) . '=' . rawurlencode( sanitize_text_field( wp_unslash( $value ) ) ) . '&';
                    }
                    if ( ! empty( $where_params ) ) {
                        $params['where_params'] = rawurlencode( rtrim( $where_params, '&' ) );
                    }
                }
            }

            if ( ! empty( $use_seclib ) ) {
                $params['verifylib'] = 1;
            }
        }

        if ( $asArray ) {
            return $params;
        }

        if ( null !== $custom_url && '' !== $custom_url ) {
            $url = $custom_url;
        } else {
            $url = ( isset( $website->url ) && '' !== $website->url ? $website->url : $website->siteurl );
        }
        $url .= ( substr( $url, - 1 ) !== '/' ? '/' : '' );
        $url .= '?';

        foreach ( $params as $key => $value ) {
            $url .= $key . '=' . $value . '&';
        }
        return rtrim( $url, '&' );
    }

    /**
     * Method connect_sign()
     *
     * Sign connect.
     *
     * @param string $data Data sign.
     * @param string $signature signature.
     * @param string $privkey Private key.
     * @param mixed  $algorithm signature algorithm.
     * @param int    $site_id site id.
     *
     * @return bool Success or not.
     */
    public static function connect_sign( $data, &$signature, $privkey, $algorithm, $site_id ) {
        $de_privkey = MainWP_Encrypt_Data_Lib::instance()->decrypt_privkey( $privkey, $site_id );

        if ( empty( $de_privkey ) ) {
            MainWP_Logger::instance()->debug( 'Error: Failed to decrypt the priv key.' );
        }

        if ( empty( $de_privkey ) ) {
            $de_privkey = $privkey; // compatible.
        }
        if ( false === $algorithm ) {
            return openssl_sign( $data, $signature, $de_privkey ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        } else {
            return openssl_sign( $data, $signature, $de_privkey, $algorithm ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        }
    }

    /**
     * Method get_post_data_not_authed()
     *
     * Get not authorized $_POST data.
     *
     * @param mixed $url Child site URL.
     * @param mixed $admin Admin Username.
     * @param mixed $what What function to perform.
     * @param null  $params Function parameters.
     *
     * @return mixed null|http_build_query()
     */
    public static function get_post_data_not_authed( $url, $admin, $what, $params = null ) {
        if ( '' !== $url && '' !== $admin && '' !== $what ) {
            $data              = array();
            $data['user']      = $admin;
            $data['function']  = $what;
            $data['mainwpver'] = MainWP_System::$version;

            if ( null !== $params ) {
                $data = array_merge( $data, $params );
            }

            return http_build_query( $data, '', '&' );
        }

        return null;
    }

    /**
     * Format a privacy-safe diagnostic for an unexpected Child response.
     *
     * @param mixed $website Website information.
     * @param mixed $data    Raw response data.
     *
     * @return string Redacted diagnostic message.
     */
    private static function format_unexpected_response_log( $website, $data ) {
        $site_id = 0;
        if ( is_object( $website ) && property_exists( $website, 'id' ) && 0 < (int) $website->id ) {
            $site_id = (int) $website->id;
        }

        $response_bytes = is_string( $data ) ? strlen( $data ) : 0;

        return 'curl_multi_getcontent :: unexpected response :: [siteid=' . $site_id . '] :: [response_bytes=' . $response_bytes . ']';
    }

    /**
     * Method fetch_urls_authed()
     *
     * Fetches data from child sites if authenticated.
     *
     * @param object $websites Websites information.
     * @param string $what Action to perform.
     * @param array  $params Request parameters.
     * @param mixed  $handler Request handler.
     * @param mixed  $output Request output.
     * @param mixed  $whatPage Request URL. Default /admin-ajax.php.
     * @param array  $others Request additional information.
     *
     * @return bool true|false
     *
     * @uses \MainWP\Dashboard\MainWP_System::$version
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
     */
    public static function fetch_urls_authed( &$websites, $what, $params, $handler, &$output, $whatPage = null, $others = array() ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        if ( ! is_array( $websites ) || empty( $websites ) ) {
            return false;
        }

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $sleep_int = (int) get_option( 'mainwp_chunksleepinterval', 5 );
        $chunkSize = (int) get_option( 'mainwp_chunksitesnumber', 10 );

        $chunkSize = apply_filters( 'mainwp_fetch_urls_chunk_size', $chunkSize );
        if ( count( $websites ) > $chunkSize ) {
            $total = count( $websites );
            $loops = ceil( $total / $chunkSize );
            for ( $i = 0; $i < $loops; $i++ ) {
                $newSites = array_slice( $websites, $i * $chunkSize, $chunkSize, true );
                static::fetch_urls_authed( $newSites, $what, $params, $handler, $output, $whatPage, $others );
                sleep( $sleep_int );
            }

            return false;
        }

        $agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';
        $mh    = curl_multi_init();

        /** This filter is documented in class/class-mainwp-connect.php */
        $timeout = static::resolve_child_request_timeout( $what );

        $disabled_functions = ini_get( 'disable_functions' );
        $handleToWebsite    = array();
        $requestUrls        = array();
        $requestHandles     = array();

        $dirs      = MainWP_System_Utility::get_mainwp_dir();
        $cookieDir = $dirs[0] . 'cookies';

        static::init_cookiesdir( $cookieDir );

        $_org_params = null;

        foreach ( $websites as $website ) {

            if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
                MainWP_Demo_Handle::get_instance()->handle_fetch_urls_demo( $data, $website, $output, $what, $params );
                continue;
            }

            $url = $website->url;
            if ( '/' !== substr( $url, - 1 ) ) {
                $url .= '/';
            }

            if ( false === strpos( $url, 'wp-admin' ) ) {
                $url .= 'wp-admin/';
            }

            if ( null !== $whatPage ) {
                $url .= $whatPage;
            } else {
                $url .= 'admin-ajax.php';
            }

            $http_user = null;
            $http_pass = null;

            if ( property_exists( $website, 'http_user' ) ) {
                $http_user = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
            }
            if ( property_exists( $website, 'http_pass' ) ) {
                $http_pass = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );
            }

            if ( isset( $params ) && isset( $params['new_post'] ) ) {

                if ( null === $_org_params ) {
                    $_org_params = $params;
                }

                /**
                 * Filter is being replaced with mainwp_pre_posting_posts.
                 *
                 * @deprecated
                 */
                $params = apply_filters_deprecated(
                    'mainwp-pre-posting-posts',
                    array(
                        ( is_array( $params ) ? $params : array() ),
                        (object) array(
                            'id'   => $website->id,
                            'url'  => $website->url,
                            'name' => $website->name,
                        ),
                    ),
                    '4.0.7.2', // NOSONAR - not IP.
                    'mainwp_pre_posting_posts'
                );

                /**
                 * Filter: mainwp_pre_posting_posts
                 *
                 * Prepares parameters for the authenticated cURL post.
                 *
                 * @since 4.1
                 */
                $params = apply_filters(
                    'mainwp_pre_posting_posts',
                    ( is_array( $params ) ? $params : array() ),
                    (object) array(
                        'id'   => $website->id,
                        'url'  => $website->url,
                        'name' => $website->name,
                    )
                );
            }

            $ch = curl_init();
            if ( ! static::is_valid_curl_handle( $ch ) ) {
                MainWP_Logger::instance()->debug( 'fetch_urls_authed :: curl_init failed' );
                if ( null !== $handler ) {
                    $init_body = static::handler_error_envelope( 'cURL could not be initialized.' );
                    if ( '' !== $init_body ) {
                        call_user_func_array( $handler, array( $init_body, $website, &$output, $params ) );
                    }
                }
                if ( null !== $_org_params ) {
                    $params = $_org_params;
                }
                continue;
            }

            $proxy = new \WP_HTTP_Proxy();
            if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
                curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
                curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
                curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );

                if ( $proxy->use_authentication() ) {
                    curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
                    curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
                }
            }

            if ( ( null !== $website ) && ( ( property_exists( $website, 'wpe' ) && 1 !== $website->wpe ) || ( isset( $others['upgrade'] ) && ( true === $others['upgrade'] ) ) ) ) {
                // to fix.
                if ( defined( 'LOGGED_IN_SALT' ) && defined( 'NONCE_SALT' ) ) {
                    $cookie_salt = sha1( sha1( 'mainwp' . LOGGED_IN_SALT . $website->id ) . NONCE_SALT . 'WP_Cookie' ); // NOSONAR - safe for salt file name.
                } else {
                    // MWP-1558: misconfigured WP installs (no salts) previously used unsalted SHA1, which is enumerable. Fall back to the per-install MainWP filename secret instead.
                    $cookie_salt = MainWP_System_Utility::get_private_filename( 'cookies', $website->id, 'WP_Cookie' );
                }
                $cookieFile = $cookieDir . '/' . $cookie_salt;
                if ( ! file_exists( $cookieFile ) ) {
                    @file_put_contents( $cookieFile, '' );
                }

                if ( file_exists( $cookieFile ) ) {
                    @chmod( $cookieFile, 0644 ); // NOSONAR - correct file permissions, owner: rwe, group & others: r.
                    curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookieFile );
                    curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookieFile );
                }
            }

            curl_setopt( $ch, CURLOPT_URL, $url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
            curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
            curl_setopt( $ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
            curl_setopt( $ch, CURLOPT_MAXREDIRS, 3 );
            curl_setopt( $ch, CURLOPT_POST, true );

            $postdata = static::get_post_data_authed( $website, $what, $params );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
            curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS );
            curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
            curl_setopt( $ch, CURLOPT_REFERER, get_option( 'siteurl' ) );
            curl_setopt( $ch, CURLOPT_ENCODING, 'none' );
            if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
                $http_pass = stripslashes( $http_pass );
                curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
            }

            $ssl_verifyhost    = false;
            $verifyCertificate = isset( $website->verify_certificate ) ? (int) $website->verify_certificate : null;
            if ( null !== $verifyCertificate ) {
                if ( 1 === $verifyCertificate ) {
                    $ssl_verifyhost = true;
                } elseif ( 2 === $verifyCertificate ) {
                    if ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === (int) get_option( 'mainwp_sslVerifyCertificate' ) ) ) {
                        $ssl_verifyhost = true;
                    }
                }
            } elseif ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === (int) get_option( 'mainwp_sslVerifyCertificate' ) ) ) {
                $ssl_verifyhost = true;
            }

            if ( $ssl_verifyhost ) {
                curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
                curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
            } else {
                curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false ); // NOSONAR.
                curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false ); // NOSONAR.
            }

            curl_setopt( $ch, CURLOPT_SSLVERSION, $website->ssl_version );

            if ( is_object( $website ) && property_exists( $website, 'id' ) ) {
                $http_version = apply_filters( 'mainwp_curl_http_version', false, $website->id );
                if ( false !== $http_version ) {
                    curl_setopt( $ch, CURLOPT_HTTP_VERSION, $http_version );
                }

                $curlopt_resolve = apply_filters( 'mainwp_curl_curlopt_resolve', false, $website->id, $website->url );
                if ( is_array( $curlopt_resolve ) && ! empty( $curlopt_resolve ) ) {
                    curl_setopt( $ch, CURLOPT_RESOLVE, $curlopt_resolve );
                    curl_setopt( $ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false );
                }
            }

            curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
            MainWP_System_Utility::set_time_limit( $timeout );

            if ( empty( $disabled_functions ) || ( false === stristr( $disabled_functions, 'curl_multi_exec' ) ) ) {
                @curl_multi_add_handle( $mh, $ch );
            }

            $handleToWebsite[ static::get_resource_id( $ch ) ] = $website;
            $requestUrls[ static::get_resource_id( $ch ) ]     = $website->url;
            $requestHandles[ static::get_resource_id( $ch ) ]  = $ch;

            if ( null !== $_org_params ) {
                $params = $_org_params;
            }
        }

        if ( empty( $disabled_functions ) || ( false === stristr( $disabled_functions, 'curl_multi_exec' ) ) ) {
            $lastRun     = 0;
            $retry_added = false;
            do {
                $retry_added = false;
                if ( 20 < time() - $lastRun ) {
                    MainWP_System_Utility::set_time_limit( $timeout );
                    $lastRun = time();
                }

                curl_multi_exec( $mh, $running );
                curl_multi_select( $mh );
                while ( $info = curl_multi_info_read( $mh ) ) {
                    $data         = curl_multi_getcontent( $info['handle'] );
                    $batch_err    = curl_error( $info['handle'] );
                    $batch_errno  = (int) curl_errno( $info['handle'] );
                    $batch_status = (int) curl_getinfo( $info['handle'], CURLINFO_HTTP_CODE );
                    $contains     = static::body_has_complete_mainwp_envelope( $data );
                    curl_multi_remove_handle( $mh, $info['handle'] );

                    $rid = static::get_resource_id( $info['handle'] );
                    if ( ! $contains && isset( $requestUrls[ $rid ] ) ) {
                        curl_setopt( $info['handle'], CURLOPT_URL, $requestUrls[ $rid ] );
                        curl_setopt( $info['handle'], CURLOPT_FRESH_CONNECT, true );
                        curl_setopt( $info['handle'], CURLOPT_FORBID_REUSE, true );
                        $add_retry = curl_multi_add_handle( $mh, $info['handle'] );
                        if ( CURLM_OK === $add_retry ) {
                            $mrc = curl_multi_exec( $mh, $running );

                            if ( CURLM_OK === $mrc ) {
                                $retry_added = true;
                                unset( $requestUrls[ $rid ] );
                                continue; // libcurl updates $running automatically.
                            }

                            curl_multi_remove_handle( $mh, $info['handle'] );
                        }
                    }

                    if ( ! $contains ) {
                        $log_website = isset( $handleToWebsite[ $rid ] ) ? $handleToWebsite[ $rid ] : null;
                        MainWP_Logger::instance()->debug( static::format_unexpected_response_log( $log_website, $data ) );
                    }

                    if ( null !== $handler ) {
                        $site         = &$handleToWebsite[ static::get_resource_id( $info['handle'] ) ];
                        $handler_data = static::batch_handler_response_data( $data, $batch_errno, $batch_err, $batch_status );
                        call_user_func_array( $handler, array( $handler_data, $site, &$output, $params ) );
                    }

                    unset( $handleToWebsite[ static::get_resource_id( $info['handle'] ) ] );
                    if ( static::is_valid_curl_handle( $info['handle'] ) ) {
                        curl_close( $info['handle'] );
                    }
                    unset( $info['handle'] );
                }
                usleep( 10000 );
            } while ( $running > 0 || $retry_added );

            if ( static::is_valid_curl_handle( $mh ) ) {
                curl_multi_close( $mh );
            }
        } else {
            foreach ( $requestHandles as $ch ) {
                $data         = curl_exec( $ch );
                $batch_err    = curl_error( $ch );
                $batch_errno  = (int) curl_errno( $ch );
                $batch_status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );

                if ( null !== $handler ) {
                    $site         = &$handleToWebsite[ static::get_resource_id( $ch ) ];
                    $handler_data = static::batch_handler_response_data( $data, $batch_errno, $batch_err, $batch_status );
                    call_user_func_array( $handler, array( $handler_data, $site, &$output, $params ) );
                }
            }
        }

        return true;
    }

    /**
     * Credits WordPress org.
     *
     * Get the correct "Expect" header for the given request data.
     *
     * @param string|array $data Data to send either as the POST body, or as parameters in the URL for a GET/HEAD.
     * @return string The "Expect" header.
     */
    public static function get_expect_header( $data ) {
        if ( ! is_array( $data ) ) {
            return strlen( (string) $data ) >= 1048576 ? '100-Continue' : '';
        }

        $bytesize = 0;
        $iterator = new \RecursiveIteratorIterator( new \RecursiveArrayIterator( $data ) );

        foreach ( $iterator as $datum ) {
            $bytesize += strlen( (string) $datum );

            if ( $bytesize >= 1048576 ) {
                return '100-Continue';
            }
        }

        return '';
    }

    /**
     * Method get_resource_id()
     *
     * Get resource id.
     *
     * @param mixed $res The given resource.
     *
     * @return $result Resource ID only.
     */
    public static function get_resource_id( $res ) {
        $result = false;
        if ( is_a( $res, 'CurlHandle' ) ) {
            $result = spl_object_hash( $res );
        } elseif ( is_resource( $res ) ) {
            $resourceString = (string) $res;
            $exploded       = explode( '#', $resourceString );
            $result         = array_pop( $exploded );
        }
        return $result;
    }

    /**
     * Method get_lock_identifier().
     *
     * Get lock identifier.
     *
     * @param mixed $pLockName Provided Lock Name.
     *
     * @return mixed false|sem_get()|@fopen
     */
    public static function get_lock_identifier( $pLockName ) {
        if ( ( null === $pLockName ) || ( false === $pLockName ) ) {
            return false;
        }

        if ( function_exists( 'sem_get' ) ) {
            return sem_get( $pLockName );
        } else {
            $fh = @fopen( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lock' . $pLockName . '.txt', 'w+' );
            if ( ! $fh ) {
                return false;
            }

            return $fh;
        }
    }

    /**
     * Method lock()
     *
     * Use sem_acquire or @flock to lock the $identifier.
     *
     * @param mixed $identifier Identifier.
     *
     * @return mixed false|sem_acquire()|@flock
     */
    public static function lock( $identifier ) {
        if ( ( null === $identifier ) || ( false === $identifier ) ) {
            return false;
        }

        if ( function_exists( 'sem_acquire' ) ) {
            return sem_acquire( $identifier );
        } else {
            if ( ! is_resource( $identifier ) ) {
                return false; // to fix.
            }
            for ( $i = 0; $i < 3; $i++ ) {
                if ( @flock( $identifier, LOCK_EX ) ) {
                    return $identifier;
                } else {
                    sleep( 1 );
                }
            }
            return false;
        }
    }

    /**
     * Method release()
     *
     * Use sem_release or @flock, @fclose to unlock $identifier.
     *
     * @param mixed $identifier Identifier.
     *
     * @return mixed false|sem_release()|@flock
     */
    public static function release( $identifier ) {
        if ( ( null === $identifier ) || ( false === $identifier ) ) {
            return false;
        }

        if ( function_exists( 'sem_release' ) ) {
            return sem_release( $identifier );
        } else {
            if ( ! is_resource( $identifier ) ) {
                return false; // to fix.
            }
            @flock( $identifier, LOCK_UN );
            @fclose( $identifier );
        }

        return false;
    }

    /**
     * Method fetch_url_authed()
     *
     * Updates the child site via authenticated request.
     *
     * @param object $website          Website information.
     * @param string $what             Function to perform.
     * @param null   $params           Function parameters.
     * @param bool   $checkConstraints Whether or not to check constraints.
     * @param bool   $pForceFetch      Whether or not to force the fetch.
     * @param bool   $pRetryFailed     Whether or not to retry the fetch process.
     * @param null   $rawResponse      Raw response.
     * @param bool   $verify_signature Wether verify signature data.
     * @param array  $request_options Optional transport controls; root_only bypasses admin-ajax.
     *
     * @return mixed $information
     *
     * @uses \MainWP\Dashboard\MainWP_Monitoring_Handler::handle_check_website()
     * @uses \MainWP\Dashboard\MainWP_Premium_Update::maybe_request_premium_updates()
     * @uses \MainWP\Dashboard\MainWP_Sync::sync_information_array()
     */
    public static function fetch_url_authed( // phpcs:ignore -- NOSONAR - complex.
        &$website,
        $what,
        $params = null,
        $checkConstraints = false,
        $pForceFetch = false,
        $pRetryFailed = true,
        $rawResponse = null,
        $verify_signature = false,
        $request_options = array()
    ) {
        unset( $pForceFetch );

        // to support demo data.
        if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
            return MainWP_Demo_Handle::get_instance()->handle_action_demo( $website, $what );
        }

        /**
         * Filter to mock fetch_url_authed response before any HTTP/signing occurs.
         *
         * This filter fires early, before OpenSSL signing or HTTP requests, allowing
         * tests to bypass child site communication entirely.
         *
         * SECURITY WARNING - TEST ONLY:
         * This filter ONLY fires when ALL of the following conditions are met:
         * 1. MAINWP_TESTING_MODE constant is defined and true
         * 2. A PHPUnit test harness constant is present (WP_TESTS_DOMAIN, PHPUNIT_COMPOSER_INSTALL, or WP_TESTS_DIR)
         *
         * This triple-check prevents malicious code from defining MAINWP_TESTING_MODE
         * in production to spoof child site responses.
         *
         * IMPORTANT: MAINWP_TESTING_MODE must ONLY be defined in the PHPUnit bootstrap
         * file (tests/bootstrap.php). Defining it in production code, wp-config.php, or
         * plugin files would create a security vulnerability allowing response spoofing.
         *
         * @since 5.4
         *
         * @param mixed  $pre_result Return non-false to short-circuit and return this value.
         * @param object $website    Website object being communicated with.
         * @param string $what       Action being performed (e.g., 'plugin_action').
         * @param array  $params     Request parameters.
         * @param bool   $verify_signature Whether to verify the signature.
         * @param bool   $pRetryFailed Whether to retry failed requests.
         * @param array  $request_options Optional transport controls.
         * @return mixed Array to return early, false to proceed normally.
         */
        $is_phpunit_env = defined( 'WP_TESTS_DOMAIN' ) || defined( 'PHPUNIT_COMPOSER_INSTALL' ) || ( defined( 'WP_TESTS_DIR' ) && WP_TESTS_DIR );
        if ( defined( 'MAINWP_TESTING_MODE' ) && MAINWP_TESTING_MODE && $is_phpunit_env ) {
            $pre_result = apply_filters( 'mainwp_fetch_url_authed_pre', false, $website, $what, $params, $verify_signature, $pRetryFailed, $request_options );
            if ( false !== $pre_result ) {
                return $pre_result;
            }
        }

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $others = array(
            'force_use_ipv4' => $website->force_use_ipv4,
            'upgrade'        => ( 'upgradeplugintheme' === $what || 'upgrade' === $what || 'upgradetranslation' === $what ),
        );

        if ( isset( $rawResponse ) && $rawResponse ) {
            $others['raw_response'] = 'yes';
        }

        $params['optimize'] = ( ( 1 === (int) get_option( 'mainwp_optimize', 1 ) ) ? 1 : 0 );

        $updating_website = false;
        $type             = '';
        $list             = '';

        $premium_update = 'process_premium_updates' === $what && ! empty( $params['premium_perform'] ) && 'premium_update' === $params['premium_perform'] ? true : false;

        if ( 'upgradeplugintheme' === $what || 'upgrade' === $what || 'upgradetranslation' === $what || $premium_update ) {
            $updating_website = true;
            if ( $premium_update ) {
                $type = $params['premium_type'];
                $list = $params['list'];
            } elseif ( 'upgradeplugintheme' === $what || 'upgradetranslation' === $what ) {
                $type = $params['type'];
                $list = $params['list'];
            } else {
                $type = 'wp';
                $list = '';
            }
        }

        if ( $updating_website ) {
            /**
             * Action: mainwp_website_before_updated
             *
             * Fires before the child site update process.
             *
             * @param object $website Object containing child site info.
             * @param string $type    Type parameter.
             * @param string $list    List parameter.
             *
             * @since Unknown
             */
            do_action( 'mainwp_website_before_updated', $website, $type, $list );
        }

        $information = array();
        $output      = array();

        if ( 'stats' === $what || ( 'upgradeplugintheme' === $what && isset( $params['type'] ) ) ) {
            $request_update = MainWP_Premium_Update::maybe_request_premium_updates( $website, $what, $params, $output_result );
            if ( $request_update ) {
                // Return the information here.
                return $output_result;
            }
        }

        // MWP-1548: decrypt http_user / http_pass before they hit the
        // outbound HTTP Basic Auth header. Legacy plaintext rows pass
        // through unchanged.
        $http_user_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
        $http_pass_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );

        if ( 'renew' === $what ) {
            $postdata = static::get_renew_post_data_authed(
                $website,
                $what,
                array(
                    'verify_signature' => $verify_signature,
                )
            );
        } else {
            $postdata = static::get_post_data_authed(
                $website,
                $what,
                $params,
                array(
                    'verify_signature' => $verify_signature,
                    'http_user_plain'  => $http_user_plain,
                    'http_pass_plain'  => $http_pass_plain,
                )
            );

        }

        $others['function'] = $what;
        if ( ! empty( $request_options['root_only'] ) ) {
            $others['root_only'] = true;
        }

        $information = static::fetch_url( $website, $website->url, $postdata, $checkConstraints, $website->verify_certificate, $pRetryFailed, $http_user_plain, $http_pass_plain, $website->ssl_version, $others, $output );

        if ( ! empty( $output ) ) {
            if ( ! is_array( $information ) ) {
                $information = array();
            }
            $information['fetch_url_output'] = $output;
        }
        /**
         * Fires immediately after fetch url action.
         *
         * @param object $website  website.
         * @param array $information information result data.
         * @param string $what action.
         * @param array $params params input array.
         * @param array $others others input array.
         *
         * @since 4.5.1.1
         */
        do_action( 'mainwp_fetch_url_authed', $website, $information, $what, $params, $others );

        if ( is_array( $information ) ) {
            if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
                MainWP_Sync::sync_information_array( $website, $information['sync'] );
                unset( $information['sync'] );
            }

            if ( isset( $information['sync_partial'] ) && is_array( $information['sync_partial'] ) && ! empty( $information['sync_partial'] ) ) {
                MainWP_Sync::sync_partial_information( $website, $information['sync_partial'] );
                unset( $information['sync_partial'] );
            }
        }

        if ( $updating_website ) {
            /**
             * Action: mainwp_website_updated
             *
             * Fires after the child site update process.
             *
             * @param object $website     Object containing child site info.
             * @param string $type        Type parameter.
             * @param string $list        List parameter.
             * @param array  $information Array containing the information fetched from the child site.
             *
             * @since Unknown
             */
            do_action( 'mainwp_website_updated', $website, $type, $list, $information );
            if ( 1 === (int) get_option( 'mainwp_check_http_response', 0 ) ) {
                MainWP_Monitoring_Handler::handle_check_website( $website, true );
            }
        }

        return $information;
    }

    /**
     * Method fetch_url_not_authed()
     *
     * Fetch not authorized URL.
     *
     * @param string  $url URL to fetch from.
     * @param string  $admin Admin name.
     * @param string  $what Function to perform.
     * @param null    $params Function parameters.
     * @param bool    $pForceFetch true|false Whether or not to force the fetch.
     * @param null    $verifyCertificate Verify the SSL Certificate.
     * @param null    $http_user htaccess username.
     * @param null    $http_pass htaccess password.
     * @param integer $sslVersion SSL version to check for.
     * @param array   $others Other functions to perform.
     * @param array   $output Output values.
     *
     * @return mixed static::fetch_url() Fetch URL.
     */
    public static function fetch_url_not_authed(  // phpcs:ignore -- NOSONAR - compatible.
        $url,
        $admin,
        $what,
        $params = null,
        $pForceFetch = false,
        $verifyCertificate = null,
        $http_user = null,
        $http_pass = null,
        $sslVersion = 0,
        $others = array(),
        &$output = array()
    ) {

        /**
         * Filter to mock the fetch_url_not_authed response before any HTTP request occurs.
         *
         * This filter fires early, before the HTTP request is performed, allowing
         * tests to bypass child site communication entirely.
         *
         * SECURITY WARNING - TEST ONLY:
         * This filter ONLY fires when ALL of the following conditions are met:
         * 1. MAINWP_TESTING_MODE constant is defined and true.
         * 2. A PHPUnit test harness constant is present (WP_TESTS_DOMAIN,
         *    PHPUNIT_COMPOSER_INSTALL, or WP_TESTS_DIR).
         *
         * These checks prevent malicious code from defining MAINWP_TESTING_MODE
         * in production to spoof child site responses.
         *
         * IMPORTANT: MAINWP_TESTING_MODE must ONLY be defined in the PHPUnit bootstrap
         * file (tests/bootstrap.php). Defining it in production code, wp-config.php,
         * or plugin files would create a security vulnerability allowing response
         * spoofing.
         *
         * @since 6.2.1
         *
         * @param mixed       $pre_result          Return non-false to short-circuit the request and return this value.
         * @param string      $url                 URL to fetch.
         * @param string      $admin               Admin username or identifier.
         * @param string      $what                Action being performed.
         * @param array|null  $params              Request parameters.
         * @param bool        $pForceFetch         Whether to force the fetch.
         * @param bool|null   $verifyCertificate   Whether to verify the SSL certificate.
         * @param string|null $http_user           HTTP authentication username.
         * @param string|null $http_pass           HTTP authentication password.
         * @param int         $sslVersion          SSL version.
         * @param array       $others              Additional request options.
         * @param array       $output              Output data passed by reference.
         *
         * @return mixed Array or other value to return early, false to proceed normally.
         */
        $is_phpunit_env = defined( 'WP_TESTS_DOMAIN' ) || defined( 'PHPUNIT_COMPOSER_INSTALL' ) || ( defined( 'WP_TESTS_DIR' ) && WP_TESTS_DIR );

        if ( defined( 'MAINWP_TESTING_MODE' ) && MAINWP_TESTING_MODE && $is_phpunit_env ) {
            $pre_result = apply_filters_ref_array(
                'mainwp_fetch_url_not_authed_pre',
                array(
                    false,
                    $url,
                    $admin,
                    $what,
                    $params,
                    $pForceFetch,
                    $verifyCertificate,
                    $http_user,
                    $http_pass,
                    $sslVersion,
                    $others,
                    &$output,
                )
            );

            if ( false !== $pre_result ) {
                return $pre_result;
            }
        }

        unset( $pForceFetch );

        if ( empty( $params ) ) {
            $params = array();
        }

        $postdata = static::get_post_data_not_authed( $url, $admin, $what, $params );
        $website  = null;

        $others['function'] = $what;
        return static::fetch_url( $website, $url, $postdata, false, $verifyCertificate, true, $http_user, $http_pass, $sslVersion, $others, $output );
    }

    /**
     * Method fetch_url()
     *
     * Fetch URL.
     *
     * @param object  $website Child Site info.
     * @param string  $url URL to fetch from.
     * @param mixed   $postdata Post data to fetch.
     * @param bool    $checkConstraints true|false Whether or not to check constraints.
     * @param null    $verifyCertificate Verify SSL Certificate.
     * @param bool    $pRetryFailed ture|false Whether or not the Retry has failed.
     * @param null    $http_user htaccess username.
     * @param null    $http_pass htaccess password.
     * @param integer $sslVersion SSL version.
     * @param array   $others Other functions to perform.
     * @param array   $output Output values.
     *
     * @throws \Exception Exception message.
     *
     * @return mixed static::fetch_url_site()
     */
    public static function fetch_url( // phpcs:ignore -- NOSONAR - complex.
        &$website,
        $url,
        $postdata,
        $checkConstraints = false,
        $verifyCertificate = null,
        $pRetryFailed = true,
        $http_user = null,
        $http_pass = null,
        $sslVersion = 0,
        $others = array(),
        &$output = array()
    ) {

        if ( ! empty( $others['root_only'] ) ) {
            return static::fetch_url_site( $website, $url, $postdata, $checkConstraints, $verifyCertificate, $http_user, $http_pass, $sslVersion, $others, $output );
        }

        $start = time();

        try {
            $tmpUrl = $url;
            if ( '/' !== substr( $tmpUrl, - 1 ) ) {
                $tmpUrl .= '/';
            }

            if ( false === strpos( $url, 'wp-admin' ) ) {
                $tmpUrl .= 'wp-admin/admin-ajax.php';
            }

            return static::fetch_url_site( $website, $tmpUrl, $postdata, $checkConstraints, $verifyCertificate, $http_user, $http_pass, $sslVersion, $others, $output );
        } catch ( \Exception $e ) {
            if ( ! $pRetryFailed || ( 30 < ( time() - $start ) ) ) {
                throw $e;
            }

            try {
                return static::fetch_url_site( $website, $url, $postdata, $checkConstraints, $verifyCertificate, $http_user, $http_pass, $sslVersion, $others, $output );
            } catch ( \Exception $ex ) {
                throw $e;
            }
        }
    }

    /**
     * Method fetch_url_site()
     *
     * M Fetch URL.
     *
     * @param object  $website Child Site info.
     * @param string  $url URL to fetch from.
     * @param mixed   $postdata Post data to fetch.
     * @param bool    $checkConstraints true|false Whether or not to check constraints.
     * @param null    $verifyCertificate Verify SSL Certificate.
     * @param null    $http_user htaccess username.
     * @param null    $http_pass htaccess password.
     * @param integer $sslVersion SSL version.
     * @param array   $others Other functions to perform.
     * @param array   $output Output values.
     *
     * @return mixed $data, $information.
     * @throws MainWP_Exception Exception message.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::insert_or_update_request_log()
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_Logger::debug_for_website()
     * @uses \MainWP\Dashboard\MainWP_System::$version
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_child_response()
     * @uses \MainWP\Dashboard\MainWP_Utility::value_to_string()
     * @uses \MainWP\Dashboard\MainWP_Utility::end_session()
     */
    public static function fetch_url_site( // phpcs:ignore -- NOSONAR - complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        &$website,
        $url,
        $postdata,
        $checkConstraints = false,
        $verifyCertificate = null,
        $http_user = null,
        $http_pass = null,
        $sslVersion = 0,
        $others = array(),
        &$output = array()
    ) {

        /**
         * Enables data to be returned prior to connecting to the site.
         *
         * Dev/test override only. Gated behind the MAINWP_DEV_FILTERS_ENABLED
         * constant so the filter does not dispatch in production. The filter
         * receives plaintext HTTP Basic Auth credentials and the full $website
         * DB row (including privkey); enabling it in production would expose
         * those values to any 3rd-party plugin hooking the filter.
         *
         * To enable in a dev/test environment, add to wp-config.php:
         *     define( 'MAINWP_DEV_FILTERS_ENABLED', true );
         *
         * @since 5.5
         *
         * @param  mixed false
         * @param  mixed $website
         * @param  mixed $url
         * @param  mixed $postdata
         * @param  mixed $checkConstraints
         * @param  mixed $verifyCertificate
         * @param  mixed $http_user
         * @param  mixed $http_pass
         * @param  mixed $sslVersion
         * @param  mixed $others
         * @param  mixed $output
         */
        if ( defined( 'MAINWP_DEV_FILTERS_ENABLED' ) && MAINWP_DEV_FILTERS_ENABLED ) {
            $dev_data = apply_filters( 'mainwp_dev_return_data_before_connect_site', false, $website, $url, $postdata, $checkConstraints, $verifyCertificate, $http_user, $http_pass, $sslVersion, $others, $output );
            if ( false !== $dev_data ) {
                return $dev_data;
            }
        }

        $agent    = MainWP_Connect_Failure_Ux::get_user_agent();
        $safe_url = MainWP_Connect_Failure_Ux::redact_url( $url );

        if ( ! empty( $website ) ) {
            MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', 'Request to [' . $safe_url . ']' );
        }

        $identifier = null;
        if ( $checkConstraints ) {
            static::check_constraints( $identifier, $website );
        }

        if ( null !== $website ) {
            MainWP_DB_Common::instance()->insert_or_update_request_log( $website->id, null, microtime( true ), null );
        }

        if ( null !== $identifier ) {
            static::release( $identifier );
        }

        $dirs      = MainWP_System_Utility::get_mainwp_dir();
        $cookieDir = $dirs[0] . 'cookies';

        static::init_cookiesdir( $cookieDir );

        $fetch_track_id = MainWP_Execution_Helper::execute_call_track( 'start_point', $website );

        $ch = curl_init();
        if ( ! static::is_valid_curl_handle( $ch ) ) {
            static::throw_fetch_url_site_curl_init_failure( $output );
        }

        $proxy = new \WP_HTTP_Proxy();
        if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
            curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
            curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
            curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );

            if ( $proxy->use_authentication() ) {
                curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
                curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
            }
        }

        if ( ( null !== $website ) && ( ( property_exists( $website, 'wpe' ) && 1 !== $website->wpe ) || ( isset( $others['upgrade'] ) && ( true === $others['upgrade'] ) ) ) ) {
            // to fix.
            if ( defined( 'LOGGED_IN_SALT' ) && defined( 'NONCE_SALT' ) ) {
                $cookie_salt = sha1( sha1( 'mainwp' . LOGGED_IN_SALT . $website->id ) . NONCE_SALT . 'WP_Cookie' ); // NOSONAR - safe for salt file name.
            } else {
                // MWP-1558: misconfigured WP installs (no salts) previously used unsalted SHA1, which is enumerable. Fall back to the per-install MainWP filename secret instead.
                $cookie_salt = MainWP_System_Utility::get_private_filename( 'cookies', $website->id, 'WP_Cookie' );
            }
            $cookieFile = $cookieDir . '/' . $cookie_salt;
            if ( ! file_exists( $cookieFile ) ) {
                @file_put_contents( $cookieFile, '' );
            }

            if ( file_exists( $cookieFile ) ) {
                @chmod( $cookieFile, 0644 ); // NOSONAR - correct file permissions, owner: rwe, group & others: r.
                curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookieFile );
                curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookieFile );
            }
        }

        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
        curl_setopt( $ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
        curl_setopt( $ch, CURLOPT_MAXREDIRS, 3 );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS );
        curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
        curl_setopt( $ch, CURLOPT_ENCODING, 'none' );

        $resp_headers = '';
        static::capture_response_headers( $ch, $resp_headers );

        if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
            $http_pass = stripslashes( $http_pass );
            curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
        }

        $ssl_verifyhost = false;
        if ( null !== $verifyCertificate ) {
            if ( 1 === (int) $verifyCertificate ) {
                $ssl_verifyhost = true;
            } elseif ( 2 === (int) $verifyCertificate ) {
                if ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === (int) get_option( 'mainwp_sslVerifyCertificate' ) ) ) {
                    $ssl_verifyhost = true;
                }
            }
        } elseif ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === (int) get_option( 'mainwp_sslVerifyCertificate' ) ) ) {
                $ssl_verifyhost = true;
        }

        if ( $ssl_verifyhost ) {
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
        } else {
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false ); // NOSONAR.
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false ); // NOSONAR.
        }

        curl_setopt( $ch, CURLOPT_SSLVERSION, $sslVersion );

        $http_version = apply_filters( 'mainwp_curl_http_version', false, $website ? $website->id : false, $url );
        if ( false !== $http_version ) {
            curl_setopt( $ch, CURLOPT_HTTP_VERSION, $http_version );
        }

        $curlopt_resolve = false;

        if ( is_object( $website ) && property_exists( $website, 'id' ) ) {
            $curlopt_resolve = apply_filters( 'mainwp_curl_curlopt_resolve', false, $website->id, $website->url );
        }

        if ( is_array( $curlopt_resolve ) && ! empty( $curlopt_resolve ) ) {
            curl_setopt( $ch, CURLOPT_RESOLVE, $curlopt_resolve );
            curl_setopt( $ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false );
        }

        $headers           = array( 'X-Requested-With' => 'XMLHttpRequest' );
        $headers['Expect'] = static::get_expect_header( $postdata );

        $headers = apply_filters( 'mainwp_connect_http_request_headers', $headers, $website );

        if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
            $headers = \WpOrg\Requests\Requests::flatten( $headers );
        } else {
            $headers = \Requests::flatten( $headers );
        }

        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
        curl_setopt( $ch, CURLOPT_REFERER, get_option( 'siteurl' ) );

        $force_use_ipv4 = false;
        $forceUseIPv4   = isset( $others['force_use_ipv4'] ) ? (int) $others['force_use_ipv4'] : null;
        if ( null !== $forceUseIPv4 ) {
            if ( 1 === $forceUseIPv4 ) {
                $force_use_ipv4 = true;
            } elseif ( 2 === $forceUseIPv4 ) {
                if ( 1 === (int) get_option( 'mainwp_forceUseIPv4' ) ) {
                    $force_use_ipv4 = true;
                }
            }
        } elseif ( 1 === (int) get_option( 'mainwp_forceUseIPv4' ) ) {
                $force_use_ipv4 = true;
        }

        if ( $force_use_ipv4 && defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
            curl_setopt( $ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
        }

        $what = '';
        if ( is_array( $others ) && isset( $others['function'] ) ) {
            $what = $others['function'];
        }

        /** This filter is documented in class/class-mainwp-connect.php */
        $timeout = static::resolve_child_request_timeout( $what );

        curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
        MainWP_System_Utility::set_time_limit( $timeout );

        MainWP_Utility::end_session();

        MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', 'Executing handlers' );

        $disabled_functions = ini_get( 'disable_functions' );
        $curl_errno         = 0;
        if ( empty( $disabled_functions ) || ( false === stristr( $disabled_functions, 'curl_multi_exec' ) ) ) {
            $mh = @curl_multi_init();
            @curl_multi_add_handle( $mh, $ch );

            $lastRun = 0;
            $running = null;

            do {
                if ( 20 < time() - $lastRun ) {
                    MainWP_System_Utility::set_time_limit( $timeout );
                    $lastRun = time();
                }

                do {
                    $mrc = curl_multi_exec( $mh, $running );
                } while ( CURLM_CALL_MULTI_PERFORM === $mrc );

                $rc = curl_multi_select( $mh, 1.0 );
                if ( -1 === $rc ) {
                    usleep( 100000 );
                }

                while ( $info = @curl_multi_info_read( $mh ) ) {
                    $data        = @curl_multi_getcontent( $info['handle'] );
                    $http_status = @curl_getinfo( $info['handle'], CURLINFO_HTTP_CODE );
                    $err         = @curl_error( $info['handle'] );
                    $real_url    = @curl_getinfo( $info['handle'], CURLINFO_EFFECTIVE_URL );
                    $curl_errno  = (int) @curl_errno( $info['handle'] );

                    @curl_multi_remove_handle( $mh, $info['handle'] );
                    curl_close( $info['handle'] );
                }
                usleep( 10000 );
            } while ( $running > 0 );

            if ( static::is_valid_curl_handle( $mh ) ) {
                @curl_multi_close( $mh );
            }
        } else {
            $data        = @curl_exec( $ch );
            $http_status = @curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $err         = @curl_error( $ch );
            $real_url    = @curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
            $curl_errno  = (int) @curl_errno( $ch );
            curl_close( $ch );
        }

        $host        = wp_parse_url( $real_url, PHP_URL_HOST );
        $ip          = gethostbyname( $host );
        $resolved_ip = ( is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '';

        MainWP_Execution_Helper::execute_call_track( 'end_point', $website, array(), $fetch_track_id, 'fetch site' );

        if ( null !== $website ) {
            MainWP_DB_Common::instance()->insert_or_update_request_log( $website->id, $ip, null, microtime( true ) );
        }

        $raw_response = isset( $others['raw_response'] ) && 'yes' === $others['raw_response'] ? true : false;

        $hide_raw    = (bool) apply_filters( 'mainwp_hide_raw_connection_response_data', true );
        $hidden_data = $hide_raw ? '[hidden response data]' : $data;

        if ( ! is_array( $output ) ) {
            $output = array();
        }

        $output['fetch_data'] = $hidden_data;

        $output['http_status'] = (int) $http_status;

        $response_body = '';
        if ( ! $hide_raw && is_string( $data ) ) {
            $response_body = $data;
        }

        $failure_context = array(
            'http_status' => $http_status,
            'headers'     => $resp_headers,
            'body'        => $response_body,
            'resolved_ip' => $resolved_ip,
            'error'       => $err,
        );

        MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', 'http status: [' . $http_status . '] err: [' . MainWP_Connect_Failure_Ux::sanitize_diagnostic_text( (string) $err ) . ']' );

        MainWP_Logger::instance()->log_execution_time( 'fetch_url_site :: [url=' . $safe_url . ']' );

        $thr_error = null;

        if ( in_array( $what, array( 'installplugintheme', 'upgradeplugintheme', 'upgradetranslation', 'process_premium_updates', 'upgrade', 'stats', 'renew', 'reconnect' ), true ) ) {
            MainWP_Cache_Helper::invalidate_cache_group( MainWP_Cache_Helper::CGR_UPDATES );
            MainWP_Cache_Warm_Helper::invalidate_pages_by_site_actions( $what );
        }

        if ( 'process_premium_updates' === $what ) {
            $response_bytes = is_string( $data ) ? strlen( $data ) : 0;
            MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', 'function: [process_premium_updates] response received: [bytes=' . $response_bytes . ']' );
        }

        $timeout_kind = static::child_request_timeout_kind( $curl_errno, $err, $http_status, $data );

        if ( ( false === $data ) && empty( $http_status ) ) {
            if ( '' !== $timeout_kind ) {
                MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', 'Error: TIMEOUT' );
                $thr_error = static::new_timeout_exception( $timeout_kind, $output );
            } else {
                MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', '[' . $safe_url . '] HTTP Error: [status=0][' . MainWP_Connect_Failure_Ux::sanitize_diagnostic_text( (string) $err ) . ']' );
                $thr_error                = new MainWP_Exception( 'HTTPERROR', $err ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                $output['error_category'] = 'http_error';
            }
        } elseif ( empty( $data ) && ! empty( $err ) ) {
            if ( '' !== $timeout_kind ) {
                MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', 'Error: TIMEOUT' );
                $thr_error = static::new_timeout_exception( $timeout_kind, $output );
            } else {
                MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', '[' . $safe_url . '] HTTP Error: [status=' . $http_status . '][' . MainWP_Connect_Failure_Ux::sanitize_diagnostic_text( (string) $err ) . ']' );
                $thr_error                = new MainWP_Exception( 'HTTPERROR', $err ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                $output['error_category'] = 'http_error';
                $output['error_code']     = 'http_request_failed';
                $output['error_message']  = $err;
            }
        } elseif ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
            $output['connection_step'] = 'verify_credentials';
            $result                    = $results[1];
            $information               = MainWP_System_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
            unset( $output['fetch_data'] ); // hide the data.
            MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', '[' . $safe_url . '] information: [OK]' );

            $error_code = is_array( $information ) && isset( $information['error_code'] ) ? sanitize_text_field( wp_unslash( $information['error_code'] ) ) : '';
            if ( ! empty( $error_code ) ) {
                $output['child_error_code'] = $error_code;
            }

            if ( is_array( $information ) && $website ) {
                // Process to ensure compatibility with old and new Child versions.
                $new_sync = isset( $information['support_advanced_sign'] );
                $old_sync = ! $new_sync && ( isset( $information['mainwpdir'], $information['uniqueId'] ) || isset( $information['version'], $information['wpversion'], $information['wpe'] ) );
                if ( $new_sync || $old_sync ) {
                    $adv_sign_support = MainWP_DB::instance()->get_website_option( $website, 'support_advanced_sign' );
                    if ( ! empty( $information['support_advanced_sign'] ) && 0 === (int) $adv_sign_support ) {
                        MainWP_DB::instance()->update_website_option( $website, 'support_advanced_sign', 1 );
                    } elseif ( empty( $information['support_advanced_sign'] ) && 1 === (int) $adv_sign_support ) {
                        MainWP_DB::instance()->update_website_option( $website, 'support_advanced_sign', 0 );
                    }
                }
            }

            if ( 'process_premium_updates' === $what ) {
                MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', 'function: [process_premium_updates] decoded response: [OK]' );
            }

            return $information;
        } elseif ( 200 === (int) $http_status && ! empty( $err ) ) {
            if ( '' !== $timeout_kind ) {
                MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', 'Error: TIMEOUT' );
                $thr_error = static::new_timeout_exception( $timeout_kind, $output );
            } else {
                $thr_error                = new MainWP_Exception( 'HTTPERROR', $err ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                $output['error_category'] = 'http_error';
                $output['error_code']     = 'http_request_failed';
                $output['error_message']  = $err;
                MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', '[' . $safe_url . '] error [' . MainWP_Connect_Failure_Ux::sanitize_diagnostic_text( (string) $err ) . ']' );
            }
        } elseif ( $raw_response ) {
            MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', 'Response: [RAW]' );
            return $data;
        } else {
            $else_code = static::child_fetch_else_code( $data, $err, $http_status, $curl_errno );
            if ( 'WSIDCHK' === $else_code ) {
                MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', 'Error: wsidchk' );
                $err_msg                  = 'Connection Failed. We suspect that Imunify360, a security layer added by your host, is causing this problem. Please contact your host to whitelist your Dashboard IP in their system. If you need help determining your MainWP Dashboard site IP address, check with your hosting provider.';
                $thr_error                = new MainWP_Exception( 'ERROR:' . $err_msg, $url );
                $output['error_category'] = 'child_plugin_missing';
                $output['error_message']  = $err_msg;
            } elseif ( 'TIMEOUT' === $else_code ) {
                MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', 'Error: TIMEOUT' );
                $thr_error = static::new_timeout_exception( $timeout_kind, $output );
            } else {
                $response_bytes = is_string( $data ) ? strlen( $data ) : 0;
                MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', '[' . $safe_url . '] Error: NOMAINWP [response_bytes=' . $response_bytes . ']' );
                $thr_error                = new MainWP_Exception( 'NOMAINWP', $url ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                $err_msg                  = 'Connection Failed. Please ensure that the MainWP Child plugin is installed and activated on the child site.';
                $output['error_category'] = 'child_plugin_missing';
                $output['error_message']  = $err_msg;
            }
        }

        if ( null !== $thr_error ) {
            $output = MainWP_Connect_Failure_Ux::enrich_output( $output, $failure_context );
            if ( ! $hide_raw && ! empty( $output['response_readable'] ) ) {
                $output['fetch_data'] = $output['response_readable'];
            }
            $readable = ! empty( $output['response_readable'] ) ? $output['response_readable'] : $hidden_data;
            $thr_error->set_data(
                array(
                    'text'        => $readable,
                    'http_status' => (int) $http_status,
                    'headers'     => isset( $output['response_headers'] ) ? $output['response_headers'] : '',
                    'body'        => isset( $output['response_body'] ) ? $output['response_body'] : '',
                    'resolved_ip' => $resolved_ip,
                )
            );
            throw $thr_error;
        }
    }

    /**
     * Method check_constraints()
     *
     * Check connection delay constraints.
     *
     * @param mixed $identifier Lock identifier.
     * @param mixed $website Object child site.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::close_open_requests()
     * @uses \MainWP\Dashboard\MainWP_DB::get_wp_ip()
     * @uses \MainWP\Dashboard\MainWP_Utility::end_session()
     */
    private static function check_constraints( &$identifier, $website ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $semLock      = '103218';
        $identifier   = static::get_lock_identifier( $semLock );
        $minimumDelay = ( ( false === get_option( 'mainwp_minimumDelay' ) ) ? 200 : get_option( 'mainwp_minimumDelay' ) );
        if ( 0 < $minimumDelay ) {
            $minimumDelay = $minimumDelay / 1000;
        }
        $minimumIPDelay = ( ( false === get_option( 'mainwp_minimumIPDelay' ) ) ? 1000 : get_option( 'mainwp_minimumIPDelay' ) );
        if ( 0 < $minimumIPDelay ) {
            $minimumIPDelay = $minimumIPDelay / 1000;
        }

        MainWP_Utility::end_session();
        $delay = true;
        while ( $delay ) {
            static::lock( $identifier );
            if ( 0 < $minimumDelay && static::check_constraints_last_request( $identifier, $minimumDelay ) ) {
                continue;
            }

            if ( 0 < $minimumIPDelay && null !== $website ) {
                $ip = MainWP_DB::instance()->get_wp_ip( $website->id );
                if ( null !== $ip && '' !== $ip && static::check_constraints_last_request( $identifier, $minimumIPDelay, $ip ) ) {
                    continue;
                }
            }
            $delay = false;
        }

        $maximumRequests   = ( ( false === get_option( 'mainwp_maximumRequests' ) ) ? 4 : get_option( 'mainwp_maximumRequests' ) );
        $maximumIPRequests = ( ( false === get_option( 'mainwp_maximumIPRequests' ) ) ? 1 : get_option( 'mainwp_maximumIPRequests' ) );

        $first = true;
        $delay = true;
        while ( $delay ) {
            if ( ! $first ) {
                static::lock( $identifier );
            } else {
                $first = false;
            }

            MainWP_DB_Common::instance()->close_open_requests();

            if ( 0 < $maximumRequests && static::check_constraints_open_requests( $identifier, $maximumRequests ) ) {
                continue;
            }

            if ( 0 < $maximumIPRequests && null !== $website ) {
                $ip = MainWP_DB::instance()->get_wp_ip( $website->id );
                if ( null !== $ip && '' !== $ip && static::check_constraints_open_requests( $identifier, $maximumIPRequests, $ip ) ) {
                    continue;
                }
            }
            $delay = false;
        }
    }

    /**
     * Method check_constraints_last_request().
     *
     * Check constraints for last requests.
     *
     * @param mixed       $identifier connect identifier.
     * @param int         $minimumDelay minimum delay.
     * @param string|null $ip ip address.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_last_request_timestamp()
     */
    private static function check_constraints_last_request( $identifier, $minimumDelay, $ip = null ) {
        $lastRequest = MainWP_DB_Common::instance()->get_last_request_timestamp( $ip );
        if ( $lastRequest > ( ( microtime( true ) ) - $minimumDelay ) ) {
            static::release( $identifier );
            $sleep = ( $minimumDelay - ( ( microtime( true ) ) - $lastRequest ) ) * 1000 * 1000;
            $sleep = max( 0, intval( $sleep ) );
            usleep( $sleep );
            return true;
        }
        return false;
    }

    /**
     * Method check_constraints_open_requests().
     *
     * Check constraints for open requests.
     *
     * @param mixed       $identifier connect identifier.
     * @param int         $maximumRequests maximum requests.
     * @param string|null $ip ip address.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_nrof_open_requests()
     */
    private static function check_constraints_open_requests( $identifier, $maximumRequests, $ip = null ) {
        $nrOfOpenRequests = MainWP_DB_Common::instance()->get_nrof_open_requests( $ip );
        if ( $nrOfOpenRequests >= $maximumRequests ) {
            static::release( $identifier );
            usleep( 200000 );
            return true;
        }
        return false;
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- all throws are MainWP_Exception.
    /**
     * Method fetch_with_retry()
     *
     * Fetch from a child site with retries and exponential backoff
     * (1s, 2s between attempts by default). Used for short idempotent
     * requests like the backup preflight where a transient network blip
     * should not fail the whole operation.
     *
     * @param object $website     Website object.
     * @param string $what        Child site action.
     * @param array  $params      Request parameters.
     * @param int    $max_retries Maximum attempts (default 3).
     *
     * @return mixed Child site response.
     * @throws MainWP_Exception ERR_RETRY_EXHAUSTED when all attempts fail, or ERR_SLOT_LOST when the concurrency slot is lost mid-retry.
     */
    public static function fetch_with_retry( &$website, $what, $params = array(), $max_retries = 3 ) {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        $last_error = null;

        for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
            if ( 1 < $attempt && MainWP_Backup_Monitor::native_lease_lost() ) {
                // The slot went to another process while an earlier attempt
                // was in flight. Spending the remaining attempts would make
                // them all outside it, so report the loss as itself rather
                // than as an exhausted retry the caller would misread.
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SLOT_LOST ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }
            try {
                return static::fetch_url_authed( $website, $what, $params );
            } catch ( \Exception $e ) {
                $last_error = $e;
                // Checked before the backoff, not only at the top of the
                // next attempt: sleeping first would spend the delay on a
                // slot that is already gone, and a single-attempt call
                // never reaches the top of a next attempt at all - it would
                // report an exhausted retry for a loss.
                if ( MainWP_Backup_Monitor::native_lease_lost() ) {
                    throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SLOT_LOST ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                }
                if ( $attempt < $max_retries ) {
                    /**
                     * Filter the backoff delay in seconds between retried child requests.
                     *
                     * @param int    $delay   Delay in seconds (1, 2, 4, ...).
                     * @param int    $attempt Attempt number that just failed.
                     * @param string $what    Child site action.
                     */
                    $delay = (int) apply_filters( 'mainwp_backup_retry_delay', 2 ** ( $attempt - 1 ), $attempt, $what );
                    if ( $delay > 0 ) {
                        sleep( $delay );
                    }
                }
            }
        }

        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic context.
        throw new MainWP_Exception(
            sprintf(
                /* translators: 1: number of attempts, 2: last error message */
                esc_html__( 'The request to the child site failed after %1$d attempts. Last error: %2$s', 'mainwp' ),
                (int) $max_retries,
                esc_html( null !== $last_error ? $last_error->getMessage() : '' )
            ),
            null,
            MainWP_Backup_Error_Codes::ERR_RETRY_EXHAUSTED
        );
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    /**
     * Method parse_content_range_header()
     *
     * Parse a Content-Range response header value.
     *
     * Accepts the satisfied form "bytes START-END/TOTAL" and the
     * unsatisfied form "bytes &#42;/TOTAL" used with 416 responses. The
     * "Content-Range:" name prefix is tolerated.
     *
     * @param string $value Header value or full header line.
     *
     * @return array|false Array with start/end/total keys for the satisfied
     *                     form, array with only a total key for the
     *                     unsatisfied form, false when not parsable.
     */
    public static function parse_content_range_header( $value ) {
        if ( ! is_string( $value ) || '' === $value ) {
            return false;
        }

        $value = preg_replace( '/^Content-Range:\s*/i', '', trim( $value ) );

        if ( preg_match( '/^bytes\s+(\d+)-(\d+)\/(\d+)$/i', $value, $matches ) ) {
            return array(
                'start' => (int) $matches[1],
                'end'   => (int) $matches[2],
                'total' => (int) $matches[3],
            );
        }

        if ( preg_match( '/^bytes\s+\*\/(\d+)$/i', $value, $matches ) ) {
            return array( 'total' => (int) $matches[1] );
        }

        return false;
    }

    /**
     * Method decide_download_mode()
     *
     * Decide how to handle a download response when resuming is in play.
     *
     * The rules protect against the historical corruption bugs: data is
     * appended only when the server confirmed the exact requested offset
     * with 206 + Content-Range, a 200 answer to a Range request means the
     * server ignored the header (old child site) and the download restarts
     * from byte zero, and 416 is treated as already-complete only when the
     * local size matches the server-reported total.
     *
     * @param int         $http_status      HTTP response status code.
     * @param int         $requested_offset Byte offset requested via Range (0 = fresh download).
     * @param array|false $content_range    Parsed Content-Range header or false when absent.
     * @param int         $local_size       Current local file size in bytes.
     * @param int|false   $expected_size    Expected remote total size, false when unknown.
     *
     * @return string 'w' to write from scratch, 'a' to append, 'already_complete' when nothing is left to download.
     * @throws MainWP_Exception When the response cannot be used safely.
     */
    public static function decide_download_mode( $http_status, $requested_offset, $content_range, $local_size, $expected_size ) {
        $sent_range = $requested_offset > 0;

        if ( 416 === $http_status ) {
            // A range is only requested while the local file is shorter than the
            // advertised size, so a 416 total that matches the local file but not
            // the advertised size is a smaller artifact than promised, not a finished
            // download. Restarting lets the final size check report it.
            if ( is_array( $content_range ) && isset( $content_range['total'] ) && $local_size === $content_range['total'] && ( false === $expected_size || $local_size === $expected_size ) ) {
                return 'already_complete';
            }
            return 'w';
        }

        if ( 400 <= $http_status || ( 200 !== $http_status && 206 !== $http_status ) ) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic context.
            throw new MainWP_Exception(
                esc_html__( 'Backup download failed: the child site returned an unexpected response.', 'mainwp' ),
                'HTTP ' . (int) $http_status,
                MainWP_Backup_Error_Codes::ERR_HTTP_ERROR
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if ( ! $sent_range ) {
            return 'w';
        }

        if ( 200 === $http_status ) {
            // Server ignored the Range header (old child site). Never append.
            if ( false !== $expected_size && $local_size === $expected_size ) {
                return 'already_complete';
            }
            return 'w';
        }

        // 206 on a resume: the Content-Range start must match the requested offset.
        if ( ! is_array( $content_range ) || ! isset( $content_range['start'] ) || $content_range['start'] !== $requested_offset ) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic context.
            throw new MainWP_Exception(
                esc_html__( 'Backup resume failed: the child site confirmed a different byte range than requested.', 'mainwp' ),
                is_array( $content_range ) && isset( $content_range['start'] ) ? 'expected offset ' . $requested_offset . ', got ' . $content_range['start'] : 'Content-Range header missing',
                MainWP_Backup_Error_Codes::ERR_HTTP_ERROR
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return 'a';
    }

    /**
     * Method download_to_file()
     *
     * Download a backup file from a child site, resuming with HTTP Range
     * requests when a partial file exists. Appends only after the child
     * confirms the partial response; error response bodies never reach the
     * backup file, and the transfer aborts on stalls and timeouts.
     *
     * An existing file exactly matching the expected size needs no request.
     * A 416 with a total that differs from the expected size restarts the
     * transfer; an undersized result then fails the final size check.
     * A partial file is discarded when the expected size is unknown or the
     * file exceeds it.
     *
     * @param string      $url       Download URL.
     * @param string      $file      Local destination path.
     * @param int|false   $size      Expected total size in bytes; false or
     *                               non-positive values disable size checks and resuming.
     * @param string|null $http_user HTTP Basic Auth username.
     * @param string|null $http_pass HTTP Basic Auth password.
     * @param object|null $website   Child site transport settings; null uses cURL defaults.
     *
     * @throws MainWP_Exception If the destination cannot be written, the HTTP transfer fails,
     *                           a resumed 206 confirms the wrong range, or the
     *                           downloaded size is outside the accepted range.
     *
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     */
    public static function download_to_file( $url, $file, $size = false, $http_user = null, $http_pass = null, $website = null ) { // phpcs:ignore -- NOSONAR - complex.

        $hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

        /**
         * WordPress files system object.
         *
         * @global object
         */
        global $wp_filesystem;

        /**
         * Filter to mock download_to_file before any HTTP transfer occurs.
         *
         * SECURITY WARNING - TEST ONLY: fires only when MAINWP_TESTING_MODE is
         * defined together with a PHPUnit harness constant, mirroring the
         * mainwp_fetch_url_authed_pre guard. MAINWP_TESTING_MODE must only be
         * defined in the PHPUnit bootstrap.
         *
         * @param mixed  $pre Return non-null to short-circuit the download.
         * @param string $url  Download URL.
         * @param string $file Local target file.
         * @param mixed  $size Expected size or false.
         */
        $is_phpunit_env = defined( 'WP_TESTS_DOMAIN' ) || defined( 'PHPUNIT_COMPOSER_INSTALL' ) || ( defined( 'WP_TESTS_DIR' ) && WP_TESTS_DIR );
        if ( defined( 'MAINWP_TESTING_MODE' ) && MAINWP_TESTING_MODE && $is_phpunit_env ) {
            $pre_result = apply_filters( 'mainwp_download_to_file_pre', null, $url, $file, $size );
            if ( null !== $pre_result ) {
                if ( $pre_result instanceof MainWP_Exception ) {
                    throw $pre_result;
                }
                return;
            }
        }

        $expected_size = ( false !== $size && is_numeric( $size ) && (int) $size > 0 ) ? (int) $size : false;

        // A partial file larger than the expected total is corrupt - start over.
        if ( $wp_filesystem->exists( $file ) && ( ( false === $expected_size ) || ( $wp_filesystem->size( $file ) > $expected_size ) ) ) {
            $wp_filesystem->delete( $file );
        }

        if ( ! $wp_filesystem->exists( dirname( $file ) ) ) {
            $wp_filesystem->mkdir( dirname( $file ), 0750 ); // MWP-1558: tightened from 0777; downloaded files may contain backup data.
        }

        if ( ! $wp_filesystem->exists( dirname( $file ) ) ) {
            throw new MainWP_Exception( esc_html__( 'MainWP plugin could not create directory in order to download the file.', 'mainwp' ) );
        }

        if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
            if ( ! $wp_filesystem->is_writable( @dirname( $file ) ) ) {
                throw new MainWP_Exception( esc_html__( 'MainWP upload directory is not writable.', 'mainwp' ) );
            }
        } elseif ( ! is_writable( @dirname( $file ) ) ) { //phpcs:ignore -- ok.
            throw new MainWP_Exception( esc_html__( 'MainWP upload directory is not writable.', 'mainwp' ) );
        }

        $local_size = ( false !== $expected_size && $wp_filesystem->exists( $file ) ) ? (int) $wp_filesystem->size( $file ) : 0;

        if ( false !== $expected_size && $local_size === $expected_size ) {
            return; // Already fully downloaded.
        }

        for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
            $result = static::perform_download_request( $url, $file, $expected_size, $local_size, $http_user, $http_pass, $website );

            if ( 'restart' !== $result ) {
                break;
            }

            // Unusable resume state (416 size mismatch) - delete and download fresh.
            if ( $wp_filesystem->exists( $file ) ) {
                $wp_filesystem->delete( $file );
            }
            $local_size = 0;
        }

        if ( false !== $expected_size && 'already_complete' !== $result ) {
            $final_size = $wp_filesystem->exists( $file ) ? (int) $wp_filesystem->size( $file ) : 0;
            $tolerance  = (int) max( 1, floor( $expected_size * 0.01 ) );
            if ( $final_size < $expected_size || $final_size - $expected_size > $tolerance ) {
                $too_small = $final_size < $expected_size;
                if ( ! $too_small && $wp_filesystem->exists( $file ) ) {
                    $wp_filesystem->delete( $file ); // Oversized files are unusable for resume.
                }
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic context.
                throw new MainWP_Exception(
                    esc_html__( 'The downloaded backup file size does not match the size reported by the child site. The transfer may have been interrupted. Try again to resume the download.', 'mainwp' ),
                    'expected ' . $expected_size . ' bytes, got ' . $final_size,
                    $too_small ? MainWP_Backup_Error_Codes::ERR_FILE_TOO_SMALL : MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT
                );
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }
    }

    /**
     * The www alias and scheme changes keep downloads on the same Child host.
     *
     * @param string $site_url Site URL.
     * @param string $effective_url Download URL.
     * @return bool Whether both URLs name the same host.
     */
    public static function download_hosts_match( string $site_url, string $effective_url ): bool {
        $site_host      = wp_parse_url( $site_url, PHP_URL_HOST );
        $effective_host = wp_parse_url( $effective_url, PHP_URL_HOST );
        if ( ! is_string( $site_host ) || '' === $site_host || ! is_string( $effective_host ) || '' === $effective_host ) {
            return false;
        }
        return preg_replace( '/^www\./i', '', strtolower( $site_host ) ) === preg_replace( '/^www\./i', '', strtolower( $effective_host ) );
    }

    /**
     * Method perform_download_request()
     *
     * Execute a single download HTTP request, streaming the body to the
     * target file once the response status and Content-Range are validated.
     * Partial bytes from an interrupted valid transfer are kept on disk so
     * the next attempt can resume; error bodies are discarded.
     *
     * @param string      $url           Download URL.
     * @param string      $file          Local target file.
     * @param int|false   $expected_size Expected total size, false when unknown.
     * @param int         $local_size    Local size used as the resume offset.
     * @param mixed       $http_user     htaccess username.
     * @param mixed       $http_pass     htaccess password.
     * @param object|null $website     Child site TLS settings; null uses cURL defaults.
     *
     * @return string 'done' when handled, 'already_complete' when a 416 confirms the local total, or 'restart' to retry from scratch.
     * @throws MainWP_Exception When the transfer fails.
     */
    private static function perform_download_request( $url, $file, $expected_size, $local_size, $http_user, $http_pass, $website ) { // phpcs:ignore -- NOSONAR - complex.
        // get_get_data_authed() builds the URL from siteurl when url is empty, so the request URL is the host to hold redirects to.
        $site_url = null !== $website && ! empty( $website->url ) ? (string) $website->url : $url;
        $agent    = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';

        $requested_offset = ( false !== $expected_size && $local_size > 0 && $local_size < $expected_size ) ? $local_size : 0;

        $ch = curl_init( str_replace( ' ', '%20', $url ) );

        $proxy = new \WP_HTTP_Proxy();
        if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
            curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
            curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
            curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );

            if ( $proxy->use_authentication() ) {
                curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
                curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
            }
        }

        $response_headers     = array();
        $state                = new \stdClass();
        $state->fp            = null;
        $state->mode          = null;
        $state->discard       = false;
        $state->exception     = null;
        $state->bytes         = 0;
        $state->last_progress = 0;

        /**
         * Filter the transfer limits for backup file downloads.
         *
         * The stall detection (low_speed_limit/low_speed_time) aborts
         * transfers that drop below the byte rate for the given number of
         * seconds, which catches connections killed by hosting proxies.
         *
         * @param array $timeouts {
         *     Transfer limits in seconds and bytes per second.
         *
         *     @type int $connect_timeout Connection timeout (default 30s).
         *     @type int $timeout         Total transfer timeout (default 1800s).
         *     @type int $low_speed_limit Minimum transfer rate in bytes/s (default 1024).
         *     @type int $low_speed_time  Seconds below the rate before aborting (default 60).
         * }
         */
        $timeouts = apply_filters(
            'mainwp_download_to_file_timeouts',
            array(
                'connect_timeout' => 30,
                'timeout'         => 1800,
                'low_speed_limit' => 1024,
                'low_speed_time'  => 60,
            )
        );

        $options = array(
            CURLOPT_HEADER          => false,
            CURLOPT_USERAGENT       => $agent,
            CURLOPT_ENCODING        => 'none',
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_CONNECTTIMEOUT  => (int) $timeouts['connect_timeout'],
            CURLOPT_TIMEOUT         => (int) $timeouts['timeout'],
            CURLOPT_LOW_SPEED_LIMIT => (int) $timeouts['low_speed_limit'],
            CURLOPT_LOW_SPEED_TIME  => (int) $timeouts['low_speed_time'],
        );
        if ( null !== $website ) {
            $verify_certificate = isset( $website->verify_certificate ) ? (int) $website->verify_certificate : null;
            $verify             = 1 === $verify_certificate;
            if ( null === $verify_certificate || 2 === $verify_certificate ) {
                $verify = false === get_option( 'mainwp_sslVerifyCertificate' ) || 1 === (int) get_option( 'mainwp_sslVerifyCertificate' );
            }
            $options[ CURLOPT_SSL_VERIFYHOST ] = $verify ? 2 : false;
            $options[ CURLOPT_SSL_VERIFYPEER ] = $verify;
            $options[ CURLOPT_SSLVERSION ]     = isset( $website->ssl_version ) ? (int) $website->ssl_version : CURL_SSLVERSION_DEFAULT;

            $http_version = apply_filters( 'mainwp_curl_http_version', false, $website->id, $url );
            if ( false !== $http_version ) {
                $options[ CURLOPT_HTTP_VERSION ] = $http_version;
            }
            $curlopt_resolve = false;
            if ( is_object( $website ) && property_exists( $website, 'id' ) ) {
                $curlopt_resolve = apply_filters( 'mainwp_curl_curlopt_resolve', false, $website->id, $website->url );
            }
            if ( is_array( $curlopt_resolve ) && ! empty( $curlopt_resolve ) ) {
                $options[ CURLOPT_RESOLVE ]              = $curlopt_resolve;
                $options[ CURLOPT_DNS_USE_GLOBAL_CACHE ] = false;
            }
            $force_ipv4 = isset( $website->force_use_ipv4 ) ? (int) $website->force_use_ipv4 : null;
            $use_ipv4   = 1 === $force_ipv4;
            if ( null === $force_ipv4 || 2 === $force_ipv4 ) {
                $use_ipv4 = 1 === (int) get_option( 'mainwp_forceUseIPv4' );
            }
            if ( $use_ipv4 && defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
                $options[ CURLOPT_IPRESOLVE ] = CURL_IPRESOLVE_V4;
            }
        }
        /**
         * Allow download transport options to follow site-specific policies.
         *
         * @param array       $options cURL transport options.
         * @param object|null $website Child site, when supplied.
         */
        $options = apply_filters( 'mainwp_download_to_file_curl_options', $options, $website );
        foreach ( $options as $option => $value ) {
            curl_setopt( $ch, $option, $value );
        }

        if ( $requested_offset > 0 ) {
            curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Range: bytes=' . $requested_offset . '-' ) );
        }

        curl_setopt(
            $ch,
            CURLOPT_HEADERFUNCTION,
            function ( $handle, $header ) use ( &$response_headers, $site_url, $state ) {
                $trimmed = trim( $header );
                if ( 0 === stripos( $trimmed, 'HTTP/' ) ) {
                    $response_headers = array(); // New status line (redirect) - discard previous headers.
                }
                if ( 0 === stripos( $trimmed, 'Location:' ) && (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ) >= 300 && (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ) < 400 ) {
                    $redirect = \WP_Http::make_absolute_url( trim( substr( $trimmed, 9 ) ), (string) curl_getinfo( $handle, CURLINFO_EFFECTIVE_URL ) );
                    // Refusing after the transfer would still let the Child contact another host.
                    if ( ! static::download_hosts_match( $site_url, $redirect ) ) {
                        $state->exception = new MainWP_Exception( esc_html__( 'The backup download redirected to a different host.', 'mainwp' ), '', MainWP_Backup_Error_Codes::ERR_HTTP_ERROR );
                        return -1;
                    }
                }
                if ( '' !== $trimmed ) {
                    $response_headers[] = $trimmed;
                }
                return strlen( $header );
            }
        );

        curl_setopt(
            $ch,
            CURLOPT_WRITEFUNCTION,
            function ( $handle, $data ) use ( $state, &$response_headers, $file, $requested_offset, $local_size, $expected_size, $site_url ) {
                if ( $state->discard ) {
                    return strlen( $data );
                }

                if ( null === $state->fp ) {
                    $status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );

                    if ( 200 !== $status && 206 !== $status ) {
                        // Error or unsatisfiable-range body - never write it to the backup file.
                        $state->discard = true;
                        return strlen( $data );
                    }

                    if ( ! static::download_hosts_match( $site_url, (string) curl_getinfo( $handle, CURLINFO_EFFECTIVE_URL ) ) ) {
                        $state->exception = new MainWP_Exception( esc_html__( 'The backup download redirected to a different host.', 'mainwp' ), '', MainWP_Backup_Error_Codes::ERR_HTTP_ERROR );
                        return -1;
                    }

                    $content_range = false;
                    foreach ( $response_headers as $header_line ) {
                        if ( 0 === stripos( $header_line, 'Content-Range:' ) ) {
                            $content_range = static::parse_content_range_header( $header_line );
                            break;
                        }
                    }

                    try {
                        $mode = static::decide_download_mode( $status, $requested_offset, $content_range, $local_size, $expected_size );
                    } catch ( MainWP_Exception $e ) {
                        $state->exception = $e;
                        return -1; // Abort the transfer.
                    }

                    if ( 'already_complete' === $mode ) {
                        $state->discard = true;
                        return strlen( $data );
                    }

                    $state->bytes = 'a' === $mode ? $local_size : 0;
                    $state->mode  = $mode;
                    $state->fp    = fopen( $file, $mode . 'b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming large downloads.
                    if ( false === $state->fp ) {
                        $state->exception = new MainWP_Exception(
                            esc_html__( 'Could not open the local backup file for writing. Check disk space and permissions on the dashboard backup directory.', 'mainwp' ),
                            $file,
                            MainWP_Backup_Error_Codes::ERR_PERMISSIONS
                        );
                        return -1;
                    }
                }

                $written = fwrite( $state->fp, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming large downloads.
                if ( false !== $written ) {
                    $state->bytes += $written;
                    if ( time() - $state->last_progress >= 60 ) {
                        $state->last_progress = time();
                        /**
                         * Keep download ownership live while bytes are arriving.
                         *
                         * @param string $file Local download path.
                         * @param int $bytes_so_far Bytes stored, including a resumed prefix.
                         */
                        try {
                            do_action( 'mainwp_download_to_file_progress', $file, $state->bytes );
                        } catch ( \Throwable $e ) {
                            // A listener failure must not interrupt the transfer or bypass handle cleanup.
                        }
                    }
                }
                return ( false === $written ) ? -1 : $written;
            }
        );

        if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
            $http_pass = stripslashes( $http_pass );
            curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
        }

        curl_exec( $ch );
        $curl_errno    = curl_errno( $ch );
        $curl_error    = curl_error( $ch );
        $status        = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        $effective_url = (string) curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
        if ( static::is_valid_curl_handle( $ch ) ) {
            curl_close( $ch );
        }
        if ( null !== $state->fp && false !== $state->fp ) {
            fclose( $state->fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming large downloads.
        }

        if ( null !== $state->exception ) {
            throw $state->exception;
        }

        if ( CURLE_OK !== $curl_errno ) {
            clearstatcache( true, $file );
            $partial_size = is_file( $file ) ? (int) filesize( $file ) : 0;
            if ( 0 === $partial_size ) {
                $message = esc_html__( 'The backup download was interrupted before completing. No partial data was saved. Try the download again.', 'mainwp' );
            } elseif ( false !== $expected_size && $partial_size < $expected_size && ( 0 === $requested_offset || 'w' !== $state->mode ) ) {
                $message = esc_html__( 'The backup download was interrupted before completing. Partial data was kept so the next attempt can resume.', 'mainwp' );
            } else {
                $message = esc_html__( 'The backup download was interrupted before completing. Partial data was kept. Try the download again.', 'mainwp' );
            }
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic context.
            throw new MainWP_Exception(
                $message,
                'cURL error ' . $curl_errno . ': ' . $curl_error,
                static::map_curl_errno_to_backup_error( $curl_errno, $curl_error )
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if ( ! static::download_hosts_match( $site_url, $effective_url ) ) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed internal error code.
            throw new MainWP_Exception(
                esc_html__( 'The backup download redirected to a different host.', 'mainwp' ),
                '',
                MainWP_Backup_Error_Codes::ERR_HTTP_ERROR
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if ( 416 === $status ) {
            $content_range = false;
            foreach ( $response_headers as $header_line ) {
                if ( 0 === stripos( $header_line, 'Content-Range:' ) ) {
                    $content_range = static::parse_content_range_header( $header_line );
                    break;
                }
            }
            $mode = static::decide_download_mode( 416, $requested_offset, $content_range, $local_size, $expected_size );
            return ( 'already_complete' === $mode ) ? 'already_complete' : 'restart';
        }

        if ( 200 !== $status && 206 !== $status ) {
            // Throws for 4xx/5xx and other unexpected statuses.
            static::decide_download_mode( $status, $requested_offset, false, $local_size, $expected_size );
        }

        if ( null === $state->fp && ! $state->discard && 200 === $status ) {
            // Zero-length 200 body: create/truncate the file so the size check reports honestly.
            $empty_fp = fopen( $file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming large downloads.
            if ( false !== $empty_fp ) {
                fclose( $empty_fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming large downloads.
            }
        }

        return 'done';
    }

    /**
     * Method map_curl_errno_to_backup_error()
     *
     * Map a cURL error number to a backup error code.
     *
     * @param int    $errno cURL error number.
     * @param string $error cURL error message.
     *
     * @return string Backup error code.
     */
    private static function map_curl_errno_to_backup_error( $errno, $error ) {
        if ( CURLE_OPERATION_TIMEDOUT === $errno ) {
            // cURL reports stalled transfers (low speed limit) with the same
            // error number as flat timeouts; the message tells them apart.
            return ( false !== stripos( (string) $error, 'too slow' ) ) ? MainWP_Backup_Error_Codes::ERR_STALLED : MainWP_Backup_Error_Codes::ERR_TIMEOUT;
        }
        if ( in_array( $errno, array( CURLE_SSL_CONNECT_ERROR, CURLE_SSL_PEER_CERTIFICATE, CURLE_SSL_ENGINE_NOTFOUND, CURLE_SSL_ENGINE_SETFAILED, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER, CURLE_SSL_CACERT ), true ) ) {
            return MainWP_Backup_Error_Codes::ERR_SSL_FAILED;
        }
        if ( in_array( $errno, array( CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT ), true ) ) {
            return MainWP_Backup_Error_Codes::ERR_CONNECT_FAILED;
        }
        return MainWP_Backup_Error_Codes::ERR_HTTP_ERROR;
    }

    /**
     * Method init_coockiesdir()
     *
     * Check for cookies directory and create it if it doesn't already exist,
     * set the file permissions and update htaccess.
     *
     * @param mixed $cookieDir Cookies directory.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     */
    public static function init_cookiesdir( $cookieDir ) {

            $hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

            /**
             * WordPress files system object.
             *
             * @global object
             */
            global $wp_filesystem;

        if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {

            if ( ! $wp_filesystem->is_dir( $cookieDir ) ) {
                $wp_filesystem->mkdir( $cookieDir, 0750 ); // MWP-1558: tightened from 0777; cookies/ holds child wp-admin session cookies.
            }

            if ( ! file_exists( $cookieDir . '/.htaccess' ) ) {
                $file_htaccess = $cookieDir . '/.htaccess';
                $wp_filesystem->put_contents( $file_htaccess, 'deny from all' );
            }

            if ( ! file_exists( $cookieDir . '/index.php' ) ) {
                $file_index = $cookieDir . '/index.php';
                $wp_filesystem->touch( $file_index );
            }
        } else {

            if ( ! file_exists( $cookieDir ) ) {
                @mkdir( $cookieDir, 0750, true ); // MWP-1558: tightened from 0777; cookies/ holds child wp-admin session cookies.
            }

            if ( ! file_exists( $cookieDir . '/.htaccess' ) ) {
                $file_htaccess = @fopen( $cookieDir . '/.htaccess', 'w+' );
                @fwrite( $file_htaccess, 'deny from all' );
                @fclose( $file_htaccess );
            }

            if ( ! file_exists( $cookieDir . '/index.php' ) ) {
                $file_index = @fopen( $cookieDir . '/index.php', 'w+' );
                @fclose( $file_index );
            }
        }
    }

    /**
     * Method get_file_content()
     *
     * Get contents of file.
     *
     * @param mixed $url File Location.
     * @param array $resolve Checked host and address pins for Child icons.
     *
     * @return mixed false|$data
     *
     * @uses \MainWP\Dashboard\MainWP_System::$version
     */
    public static function get_file_content( $url, $resolve = array() ) {
        $agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';
        $ch    = curl_init();

        $proxy = new \WP_HTTP_Proxy();
        if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
            curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
            curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
            curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );

            if ( $proxy->use_authentication() ) {
                curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
                curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
            }
        }

        curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
        if ( ! empty( $resolve ) ) {
            // A proxy would resolve the hostname independently of the checked address.
            curl_setopt( $ch, CURLOPT_PROXY, '' );
            curl_setopt( $ch, CURLOPT_RESOLVE, $resolve );
        }
        curl_setopt( $ch, CURLOPT_HEADER, 0 );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
        curl_setopt( $ch, CURLOPT_ENCODING, 'none' );

        $data     = @curl_exec( $ch );
        $httpCode = @curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        if ( static::is_valid_curl_handle( $ch ) ) {
            curl_close( $ch );
        }
        if ( 200 === (int) $httpCode ) {
            return $data;
        } else {
            return false;
        }
    }

    /**
     * Method is_valid_curl_handle
     *
     * @param  mixed $ch cURL handle to validate.
     * @return bool Valid curl handle.
     */
    public static function is_valid_curl_handle( $ch ) {
        return is_resource( $ch )
            || ( is_object( $ch )
                && class_exists( 'CurlHandle', false )
                && $ch instanceof \CurlHandle
            );
    }

    /**
     * Method get_favico_url()
     *
     * Get Child Site favicon URL.
     *
     * @param mixed $website Child Site info.
     *
     * @return mixed $faviurl Favicon URL.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_icons_dir()
     * @uses \MainWP\Dashboard\MainWP_Utility::remove_http_prefix()
     */
    public static function get_favico_url( $website ) {
        $favi    = MainWP_DB::instance()->get_website_option( $website, 'favi_icon', '' );
        $faviurl = '';

        if ( ! empty( $favi ) ) {
            if ( false !== strpos( $favi, 'favi-' . intval( $website->id ) . '-' ) ) {
                $dirs = MainWP_System_Utility::get_icons_dir();
                if ( file_exists( $dirs[0] . $favi ) ) {
                    $faviurl = $dirs[1] . $favi;
                } else {
                    $faviurl = '';
                }
            } elseif ( ( 0 === strpos( $favi, '//' ) ) || ( 0 === strpos( $favi, 'http' ) ) ) {
                $faviurl = $favi;
            } else {
                $faviurl = $website->url . $favi;
                $faviurl = MainWP_Utility::remove_http_prefix( $faviurl );
            }
        }

        if ( empty( $faviurl ) ) {
            $faviurl = false;
        }

        return $faviurl;
    }
}
