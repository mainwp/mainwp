<?php

class MainWP_Error_Helper {
	public static function getErrorMessage( $pException ) {
		$error = $pException->getMessage();

		if ( $pException->getMessage() == 'HTTPERROR' ) {
			$error = 'HTTP error' . ( $pException->getMessageExtra() != null ? ' - ' . $pException->getMessageExtra() : '' );
		} else if ( $pException->getMessage() == 'NOMAINWP' ) {
			$error = __( 'MainWP Child plugin not detected! First, install and activate the plugin and add your site to your MainWP Dashboard afterwards. If you continue experiencing this issue, please ', 'mainwp' );
			if ( $pException->getMessageExtra() != null ) {				
				$error .= sprintf( __( 'test the site connection %shere%s or ', 'mainwp' ), '<a href="' . admin_url( 'admin.php?page=managesites&do=test&site=' . urlencode( $pException->getMessageExtra() ) ) . '">', '</a>' );
			}
			$error .= sprintf( __( 'contact %sMainWP support%s.', 'mainwp' ), '<a href="https://mainwp.com/support/">', '</a>' );
		}

		return $error;
	}

	public static function getConsoleErrorMessage( $pException ) {
		$error = $pException->getMessage();

		if ( $pException->getMessage() == 'HTTPERROR' ) {
			$error = 'HTTP error' . ( $pException->getMessageExtra() != null ? ' - ' . $pException->getMessageExtra() : '' );
		} else if ( $pException->getMessage() == 'NOMAINWP' ) {
			$error = sprintf( __( 'MainWP Child plugin not detected! First, install and activate the plugin and add your site to your MainWP Dashboard afterwards. If you continue experiencing this issue, please test the site connection or contact MainWP support (%s).', 'mainwp' ), 'https://mainwp.com/support/' );
		} else if ( $pException->getMessage() != 'WPERROR' && !empty( $pException->getMessageExtra() ) ) {
			$error .= ' - ' . $pException->getMessageExtra();
		}

		return $error;
	}
}
