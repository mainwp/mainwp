<?php
/**
 * HTTP Error Handler
 *
 * Throw this error when MainWP is not detected
 * due to either an HTTP error or if MainWP Child Plugin is not found.
 */
namespace MainWP\Dashboard;

/**
 * Class MainWP Error Helper
 *
 * @return $error
 */
class MainWP_Error_Helper {

	/**
	 * Method get_error_message()
	 *
	 * Check for http error and or "nomainwp" error.
	 *
	 * @param mixed $pException
	 *
	 * @return @error Error message.
	 */
	public static function get_error_message( $pException ) {
		$error = $pException->getMessage();

		if ( $pException->getMessage() == 'HTTPERROR' ) {
			$error = 'HTTP error' . ( $pException->get_message_extra() != null ? ' - ' . $pException->get_message_extra() : '' );
		} elseif ( $pException->getMessage() == 'NOMAINWP' ) {
			$error = sprintf( __( 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to your MainWP Dashboard afterward. If you continue experiencing this issue, check the child site for %1$sknown plugin conflicts%2$s, or check the %3$sMainWP Community%4$s for help.', 'mainwp' ), '<a href="https://meta.mainwp.com/t/known-plugin-conflicts/402">', '</a>', '<a href="https://meta.mainwp.com/c/community-support/5">', '</a>' );
		}

		return $error;
	}

	/**
	 * Method get_console_error_message()
	 *
	 * Check for http error and or "nomainwp" and or "WPERROR".
	 *
	 * @param mixed $pException
	 *
	 * @return @error Error message.
	 */
	public static function get_console_error_message( $pException ) {
		$error = $pException->getMessage();

		if ( $pException->getMessage() == 'HTTPERROR' ) {
			$error = 'HTTP error' . ( $pException->get_message_extra() != null ? ' - ' . $pException->get_message_extra() : '' );
		} elseif ( $pException->getMessage() == 'NOMAINWP' ) {
			$error = sprintf( __( 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to your MainWP Dashboard afterward. If you continue experiencing this issue, check the child site for %1$sknown plugin conflicts%2$s, or check the %3$sMainWP Community%4$s for help.', 'mainwp' ), '<a href="https://meta.mainwp.com/t/known-plugin-conflicts/402">', '</a>', '<a href="https://meta.mainwp.com/c/community-support/5">', '</a>' );
		} elseif ( $pException->getMessage() != 'WPERROR' && ! empty( $pException->get_message_extra() ) ) {
			$error .= ' - ' . $pException->get_message_extra();
		}

		return $error;
	}

}
