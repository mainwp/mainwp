<?php

class MainWP_Error_Helper {
	public static function getErrorMessage( $pException ) {
		$error = $pException->getMessage();

		if ( $pException->getMessage() == 'HTTPERROR' ) {
			$error = 'HTTP error' . ( $pException->getMessageExtra() != null ? ' - ' . $pException->getMessageExtra() : '' );
		} else if ( $pException->getMessage() == 'NOMAINWP' ) {
			$error = __( 'No MainWP Child Plugin detected, first install and activate the plugin and add your site to MainWP Dashboard afterwards. If you continue experiencing this issue please ', 'mainwp' );
			if ( $pException->getMessageExtra() != null ) {				
				$error .= sprintf( __( 'test your connection %shere%s or ', 'mainwp' ), '<a href="' . admin_url( 'admin.php?page=managesites&do=test&site=' . urlencode( $pException->getMessageExtra() ) ) . '">', '</a>' );
			}
			$error .= sprintf( __( 'post as much information as possible on the error in the %ssupport forum%s.', 'mainwp' ), '<a href="https://mainwp.com/forum/">', '</a>' );
		}

		return $error;
	}
}
