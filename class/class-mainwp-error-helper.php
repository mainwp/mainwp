<?php
/**
 * HTTP Error Handler
 *
 * Throw this error when MainWP is not detected
 * due to either an HTTP error or if MainWP Child Plugin is not found.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP Error Helper
 *
 * @return $error
 */
class MainWP_Error_Helper { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.
    /**
     * Classifies cURL and WordPress HTTP transport failures as plain text.
     *
     * @param int|null $errno   cURL error number, when available.
     * @param string   $message Raw transport message.
     * @param string   $context site or mainwp_api destination.
     * @return array|null Reason and message, or null for an unknown failure.
     */
    public static function classify_transport_error( $errno, $message, $context = 'site' ) {
        $message = (string) $message;
        $reason  = '';
        $text    = '';

        // Resolver thread failures use different error numbers across libcurl versions.
        if ( false !== strpos( $message, 'getaddrinfo() thread failed to start' ) ) {
            $reason = 'server_resources';
            $text   = __( 'The server that hosts your MainWP Dashboard could not start a new connection. This usually means your hosting account has hit a process or memory limit. Ask your host to check the process (NPROC) and memory limits on the account, or move the Dashboard to a VPS.', 'mainwp' );
        } else {
            if ( empty( $errno ) && preg_match( '/cURL error (\d+):/i', $message, $matches ) ) {
                $errno = (int) $matches[1];
            }
            switch ( (int) $errno ) {
                case 6:
                    $reason = 'dns';
                    $text   = 'mainwp_api' === $context
                        ? __( 'The Dashboard server could not look up the address of the MainWP license server. DNS on the Dashboard server is broken, or a firewall is blocking lookups. Contact your host.', 'mainwp' )
                        : __( 'The Dashboard could not look up this site\'s address. Check that the domain is spelled correctly and has a working DNS record. If every site fails this way, DNS on the Dashboard server is broken and your host needs to fix it.', 'mainwp' );
                    break;
                case 7:
                    $reason = 'connect_failed';
                    $text   = 'mainwp_api' === $context
                        ? __( 'The Dashboard server could not connect to the MainWP license server. A firewall on the Dashboard server is probably blocking outbound connections. Contact your host.', 'mainwp' )
                        : __( 'The Dashboard could not connect to this site. The site may be down, or a firewall may be blocking connections from the Dashboard server. If the site opens in your browser, ask its host to allow your Dashboard server\'s IP address.', 'mainwp' );
                    break;
                case 28:
                    $reason = 'timeout';
                    $text   = 'mainwp_api' === $context
                        ? __( 'The MainWP license server did not respond in time. Try again in a few minutes. If it keeps failing, ask your host whether outbound connections from the Dashboard server are slow or filtered.', 'mainwp' )
                        : __( 'The site did not respond in time. Its server may be slow or overloaded, or a firewall may be dropping the connection. If this keeps happening, ask the site\'s host to look at server load and firewall rules.', 'mainwp' );
                    break;
                case 60:
                    $reason = 'ssl_peer';
                    $text   = 'mainwp_api' === $context
                        ? __( 'The MainWP license server\'s SSL certificate could not be verified. The CA certificates on the Dashboard server are probably out of date. Ask your host to update them.', 'mainwp' )
                        : __( 'The site\'s SSL certificate could not be verified. Check that the certificate is valid, not expired, and issued for this domain. If every site fails this way, the CA certificates on the Dashboard server are out of date and your host needs to update them.', 'mainwp' );
                    break;
                case 35:
                    $reason = 'ssl_handshake';
                    $text   = 'mainwp_api' === $context
                        ? __( 'The secure connection to the MainWP license server could not be set up. The Dashboard server\'s TLS support is probably too old, or a firewall is cutting the connection. Contact your host.', 'mainwp' )
                        : __( 'The secure connection to this site could not be set up. The two servers may not share a supported TLS version, or a firewall may be cutting the connection. If every site fails this way, ask your host to update the TLS libraries on the Dashboard server.', 'mainwp' );
                    break;
                case 58:
                case 77:
                    $reason = 'ssl_local';
                    $text   = __( 'The Dashboard server\'s own SSL setup is broken. It could not load its CA certificate bundle or client certificate. Ask your host to fix it.', 'mainwp' );
                    break;
                default:
                    return null;
            }
        }

        return array(
            'reason'  => $reason,
            /* translators: %s: raw transport error */
            'message' => $text . ' ' . sprintf( __( 'Details: %s', 'mainwp' ), $message ),
        );
    }

    /**
     * Method get_error_message()
     *
     * Check for http error and or "nomainwp" error.
     *
     * @param object $pException Exception object.
     * @param bool   $escape_msg escape message or not.
     * @param bool   $plain_text Return transport details without HTML escaping for APIs.
     *
     * @return string $error Error message.
     */
    public static function get_error_message( $pException, $escape_msg = false, $plain_text = false ) {
        $error = $pException->getMessage();

        if ( $pException->getMessage() === 'HTTPERROR' ) {
            $extra = $pException->get_message_extra( false );
            $error = 'HTTP error' . ( ! empty( $extra ) ? ' - ' . $extra : '' );
            return $plain_text && ! $escape_msg ? $error : esc_html( $error );
        } elseif ( $pException->getMessage() === 'NOMAINWP' ) {
            $error = static::get_error_not_detected_connect();
        } elseif ( 'TIMEOUT' === $pException->getMessage() ) {
            $error = static::get_timeout_error_message( $pException->get_message_error_code() );
        }

        if ( $escape_msg ) {
            return esc_html( wp_strip_all_tags( $error ) );
        }

        return $plain_text ? html_entity_decode( $error, ENT_QUOTES, 'UTF-8' ) : $error;
    }

    /**
     * Method get_console_error_message()
     *
     * Check for http error and or "nomainwp" and or "WPERROR".
     *
     * @param mixed $pException The exception.
     *
     * @return string @error Error message.
     */
    public static function get_console_error_message( $pException ) {
        $error = $pException->getMessage();

        if ( $pException->getMessage() === 'HTTPERROR' ) {
            return static::get_error_message( $pException, false, true );
        } elseif ( $pException->getMessage() === 'NOMAINWP' ) {
            $error = static::get_error_not_detected_connect();
        } elseif ( 'TIMEOUT' === $pException->getMessage() ) {
            $error = static::get_timeout_error_message( $pException->get_message_error_code() );
        } elseif ( $pException->getMessage() !== 'WPERROR' && ! empty( $pException->get_message_extra() ) ) {
            $error .= ' - ' . $pException->get_message_extra();
        }

        return html_entity_decode( $error, ENT_QUOTES, 'UTF-8' );
    }

    /**
     * Method get_error_not_detected_connect()
     *
     * @return string @error Error message.
     */
    public static function get_error_not_detected_connect() {
        /* translators: 1: Opening anchor tag, 2: Closing anchor tag */
        return sprintf( esc_html__( 'MainWP Child plugin not detected or could not be reached! Ensure the MainWP Child plugin is installed and activated on the child site, and there are no security rules blocking requests.  If you continue experiencing this issue, check the %1$sMainWP Community%2$s for help.', 'mainwp' ), '<a href="https://community.mainwp.com/c/community-support/5" target="_blank">', '</a>' );
    }

    /**
     * Connect/sync failure message with IP labels, User-Agent, and readable response.
     *
     * On 403 / Cloudflare / WAF, Cloudflare and User-Agent allow steps come first.
     *
     * @param MainWP_Exception $exception Exception.
     * @param array            $output    Fetch output.
     *
     * @return string
     */
    public static function get_connect_failure_message( $exception, $output = array() ) {
        $details = MainWP_Connect_Failure_Ux::from_exception( $exception, $output );
        $ux      = MainWP_Connect_Failure_Ux::render_html( $details );

        if ( ! empty( $details['waf_likely'] ) ) {
            return $ux;
        }

        $base = '';
        if ( $exception instanceof MainWP_Exception ) {
            $base = 'HTTPERROR' === $exception->getMessage() ? static::get_error_message( $exception ) : MainWP_Connect_Failure_Ux::sanitize_diagnostic_text( static::get_error_message( $exception ) );
        }

        if ( '' === $base ) {
            return $ux;
        }

        return $base . $ux;
    }

    /**
     * Fixed copy for a child request timeout.
     *
     * Gateway and connect timeouts do not mention the Advanced Settings total.
     * The extra on the exception stays empty; this method does not append it.
     *
     * @param string $kind operation, connect, or gateway.
     *
     * @return string
     */
    public static function get_timeout_error_message( $kind = 'operation' ) {
        if ( 'gateway' === $kind ) {
            return esc_html__( 'The child site request timed out at a gateway or proxy. This is not a missing MainWP Child plugin. Changing the Child request timeout does not fix this response.', 'mainwp' );
        }
        if ( 'connect' === $kind ) {
            return esc_html__( 'The connection to the child site timed out before it opened. Use Test Connection to check whether the site is reachable. This is not a missing MainWP Child plugin.', 'mainwp' );
        }
        return esc_html__( 'The child site request timed out. You can raise the Child request timeout in Settings → Advanced Settings. This is not a missing MainWP Child plugin.', 'mainwp' );
    }
}
