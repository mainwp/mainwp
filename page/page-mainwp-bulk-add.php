<?php

/**
 * Used in both BulkAddPage & BulkAddPost & BulkAddUser
 */
class MainWP_Bulk_Add {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function PostingBulk_handler( $data, $website, &$output ) {
		if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$result      = $results[1];
			$information = unserialize( base64_decode( $result ) );
			if ( isset( $information['added'] ) ) {
				$output->ok[ $website->id ] = '1';
				if ( isset( $information['link'] ) ) {
					$output->link[ $website->id ] = $information['link'];
				}
				if ( isset( $information['added_id'] ) ) {
					$output->added_id[ $website->id ] = $information['added_id'];
				}
			} else if ( isset( $information['error'] ) ) {
				$output->errors[ $website->id ] = __( 'ERROR: ', 'mainwp' ) . $information['error'];
			} else {
				$output->errors[ $website->id ] = __( 'Undefined error! Please reinstall the MainWP Child plugin on the child site', 'mainwp' );
			}
		} else {
			$output->errors[ $website->id ] = MainWP_Error_Helper::getErrorMessage( new MainWP_Exception( 'NOMAINWP', $website->url ) );
		}
	}
}
