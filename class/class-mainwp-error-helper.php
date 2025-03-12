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
        return sprintf( esc_html__( 'MainWP Child plugin not detected or could not be reached! Ensure the MainWP Child plugin is installed and activated on the child site, and there are no security rules blocking requests.  If you continue experiencing this issue, check the %1$sMainWP Community%2$s for help.', 'mainwp' ), '<a href="https://community.mainwp.com/c/community-support/5" target="_blank">', '</a>' );
    }
}
