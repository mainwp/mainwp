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
     * Method get_error_message()
     *
     * Check for http error and or "nomainwp" error.
     *
     * @param object $pException Exception object.
     * @param bool   $escape_msg escape message or not.
     *
     * @return string $error Error message.
     */
    public static function get_error_message( $pException, $escape_msg = false ) {
        $error = $pException->getMessage();

        if ( $pException->getMessage() === 'HTTPERROR' ) {
            $error = 'HTTP error' . ( ! empty( $pException->get_message_extra() ) ? ' - ' . $pException->get_message_extra() : '' );
        } elseif ( $pException->getMessage() === 'NOMAINWP' ) {
            $error = static::get_error_not_detected_connect();
        } elseif ( 'TIMEOUT' === $pException->getMessage() ) {
            $error = static::get_timeout_error_message( $pException->get_message_error_code() );
        }

        if ( $escape_msg ) {
            return esc_html( wp_strip_all_tags( $error ) );
        }

        return $error;
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
            $error = 'HTTP error' . ( ! empty( $pException->get_message_extra() ) ? ' - ' . $pException->get_message_extra() : '' );
        } elseif ( $pException->getMessage() === 'NOMAINWP' ) {
            $error = static::get_error_not_detected_connect();
        } elseif ( 'TIMEOUT' === $pException->getMessage() ) {
            $error = static::get_timeout_error_message( $pException->get_message_error_code() );
        } elseif ( $pException->getMessage() !== 'WPERROR' && ! empty( $pException->get_message_extra() ) ) {
            $error .= ' - ' . $pException->get_message_extra();
        }

        return $error;
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
            $base = static::get_error_message( $exception );
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
