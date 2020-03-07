<?php

class MainWP_Error_Helper {

	public static function getErrorMessage( $pException ) {
		$error = $pException->getMessage();

		if ( $pException->getMessage() == 'HTTPERROR' ) {
			$error = 'HTTP error' . ( $pException->getMessageExtra() != null ? ' - ' . $pException->getMessageExtra() : '' );
		} elseif ( $pException->getMessage() == 'NOMAINWP' ) {
			$error = sprintf( __( 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to your MainWP Dashboard afterward. If you continue experiencing this issue, check the child site for %1$sknown plugin conflicts%2$s, or check the %3$sMainWP Community%4$s for help.', 'mainwp' ), '<a href="https://meta.mainwp.com/t/known-plugin-conflicts/402">', '</a>', '<a href="https://meta.mainwp.com/c/community-support/5">', '</a>' );
		}

		return $error;
	}

	public static function getConsoleErrorMessage( $pException ) {
		$error = $pException->getMessage();

		if ( $pException->getMessage() == 'HTTPERROR' ) {
			$error = 'HTTP error' . ( $pException->getMessageExtra() != null ? ' - ' . $pException->getMessageExtra() : '' );
		} elseif ( $pException->getMessage() == 'NOMAINWP' ) {
			$error = sprintf( __( 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to your MainWP Dashboard afterward. If you continue experiencing this issue, check the child site for %1$sknown plugin conflicts%2$s, or check the %3$sMainWP Community%4$s for help.', 'mainwp' ), '<a href="https://meta.mainwp.com/t/known-plugin-conflicts/402">', '</a>', '<a href="https://meta.mainwp.com/c/community-support/5">', '</a>' );
		} elseif ( $pException->getMessage() != 'WPERROR' && ! empty( $pException->getMessageExtra() ) ) {
			$error .= ' - ' . $pException->getMessageExtra();
		}

		return $error;
	}

}
