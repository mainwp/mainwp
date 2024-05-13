<?php
/**
 * MainWP_QQ2_Uploaded_File_Form
 *
 * DO NOT TOUCH - part of http://github.com/valums/file-uploader ! (@see js/fileuploader.js)
 * Handle file uploads via regular form post (uses the $_FILES array)
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_QQ2_Uploaded_File_Form
 *
 * @package MainWP\Dashboard
 */
class MainWP_QQ2_Uploaded_File_Form {
	/**
	 * Save the file to the specified path.
	 *
	 * @param string $path Path to save file to.
	 * @return boolean TRUE on success|false on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_base_dir()
	 */
	public function save( $path ) {
		$wpFileSystem = MainWP_System_Utility::get_wp_file_system();

		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
		global $wp_filesystem;

		$moved = false;

		$tmp_name = isset( $_FILES['qqfile']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['qqfile']['tmp_name'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify in caller.

		if ( ! empty( $tmp_name ) ) {
			if ( false != $wpFileSystem ) { //phpcs:ignore -- to valid.
				$path  = str_replace( MainWP_System_Utility::get_base_dir(), '', $path );
				$moved = $wpFileSystem->put_contents( $path, $wp_filesystem->get_contents( $tmp_name ) );
			} else {
				$moved = move_uploaded_file( $tmp_name, $path );
			}
		}

		if ( ! $moved ) {
			return false;
		}

		return true;
	}

	/** Get the File Name. */
	public function get_name() {
		return isset( $_FILES['qqfile']['name'] ) ? sanitize_text_field( wp_unslash( $_FILES['qqfile']['name'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify in caller.
	}

	/** Get the File Size. */
	public function get_size() {
		return isset( $_FILES['qqfile']['size'] ) ? sanitize_text_field( wp_unslash( $_FILES['qqfile']['size'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify in caller.
	}
}
