<?php
/**
 * MainWP Bulk Add Handler
 *
 * Handles Bulk addition of Pages, Posts, User Import, User Addition & Admin Users Password.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Bulk_Add
 *
 * @used-by MainWP_Page::BulkAddPage
 * @used-by MainWP_Post::BulkAddPost
 * @used-by MainWP_User::do_bulk_add
 * @used-by MainWP_User::do_import
 * @used-by MainWP_Bulk_Update_Admin_Passwords::BulkAddUser
 */
class MainWP_Bulk_Add {

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method posting_bulk_handler()
	 *
	 * @param mixed  $data Data to process.
	 * @param object $website The website object.
	 * @param object $output Function output object.
	 *
	 * @return mixed $output
	 *
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_Logger::warning_for_website()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_child_response()
	 */
	public static function posting_bulk_handler( $data, $website, &$output ) {
		if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
			return;
		}
		if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$result      = $results[1];
			$information = MainWP_System_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

			if ( is_array( $information ) && isset( $information['other_data']['new_post_data'] ) ) { // created/updated post/page.
				do_action( 'mainwp_post_created', $website, 'newpost', $information['other_data']['new_post_data'] );
			}

			if ( is_array( $information ) && isset( $information['other_data']['new_user_data'] ) ) { // created user.
				$data = $information['other_data']['new_user_data'];
				/**
				 * Fires immediately after new user action.
				 *
				 * @since 4.5.1.1
				 */
				do_action( 'mainwp_user_action', $website, 'created', $data );
			}

			if ( is_array( $information ) && isset( $information['other_data']['update_admin_password'] ) ) { // created user.
				$data = $information['other_data']['update_admin_password'];
				/**
				 * Fires immediately after update admin password action.
				 *
				 * @since 4.5.1.1
				 */
				do_action( 'mainwp_user_action', $website, 'newadminpassword', $data );
			}

			if ( isset( $information['added'] ) ) {
				$output->ok[ $website->id ] = '1';
				if ( isset( $information['link'] ) ) {
					$output->link[ $website->id ] = esc_html( $information['link'] );
				}
				if ( isset( $information['added_id'] ) ) {
					$output->added_id[ $website->id ] = esc_html( $information['added_id'] );
				}
			} elseif ( isset( $information['error'] ) ) {
				$output->errors[ $website->id ] = esc_html__( 'ERROR: ', 'mainwp' ) . esc_html( $information['error'] );
			} else {
				$output->errors[ $website->id ] = esc_html__( 'Undefined error! Please reinstall the MainWP Child plugin on the child site', 'mainwp' );
			}
		} else {
			MainWP_Logger::instance()->debug_for_website( $website, 'posting_bulk_handler', '[' . $website->url . '] Result was: [data-start]' . $data . '[data-end]' );
			$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( 'NOMAINWP', $website->url ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}
}
