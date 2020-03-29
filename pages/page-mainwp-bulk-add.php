<?php
/**
 * MainWP Bulk Add Handler
 *
 * Handles Bulk addition of Pages, Posts, User Import, User Addition & Admin Users Password.
 */
namespace MainWP\Dashboard;

/**
 * MainWP Bulk Add
 *
 * @used-by MainWP_Page::BulkAddPage
 * @used-by MainWP_Post::BulkAddPost
 * @used-by MainWP_User::do_buk_add
 * @used-by MainWP_User::do_import
 * @used-by MainWP_Bulk_Update_Admin_Passwords::BulkAddUser
 */
class MainWP_Bulk_Add {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method posting_bulk_handler()
	 *
	 * @param mixed $data
	 * @param mixed $website
	 * @param mixed $output
	 *
	 * @return mixed $output
	 */
	public static function posting_bulk_handler( $data, $website, &$output ) {
		if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$result      = $results[1];
			$information = MainWP_Utility::get_child_response( base64_decode( $result ) );

			if ( isset( $information['added'] ) ) {
				$output->ok[ $website->id ] = '1';
				if ( isset( $information['link'] ) ) {
					$output->link[ $website->id ] = $information['link'];
				}
				if ( isset( $information['added_id'] ) ) {
					$output->added_id[ $website->id ] = $information['added_id'];
				}
			} elseif ( isset( $information['error'] ) ) {
				$output->errors[ $website->id ] = __( 'ERROR: ', 'mainwp' ) . $information['error'];
			} else {
				$output->errors[ $website->id ] = __( 'Undefined error! Please reinstall the MainWP Child plugin on the child site', 'mainwp' );
			}
		} else {
			MainWP_Logger::instance()->debug_for_website( $website, 'posting_bulk_handler', '[' . $website->url . '] Result was: [' . $data . ']' );
			$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( 'NOMAINWP', $website->url ) );
		}
	}
}
