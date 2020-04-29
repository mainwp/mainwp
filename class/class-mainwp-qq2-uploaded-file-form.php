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

/**  Class MainWP_QQ2_Uploaded_File_Form.  */
class MainWP_QQ2_Uploaded_File_Form {
	/**
	 * Save the file to the specified path.
	 *
	 * @param string $path Path to save file to.
	 * @return boolean TRUE on success|false on failer.
	 */
	public function save( $path ) {
		$wpFileSystem = MainWP_Utility::get_wp_file_system();
		global $wp_filesystem;
		if ( false != $wpFileSystem ) {
			$path  = str_replace( MainWP_Utility::get_base_dir(), '', $path );
			$moved = $wpFileSystem->put_contents( $path, $wp_filesystem->get_contents( $_FILES['qqfile']['tmp_name'] ) );
		} else {
			$moved = move_uploaded_file( $_FILES['qqfile']['tmp_name'], $path );
		}

		if ( ! $moved ) {
			return false;
		}

		return true;
	}

	/** Get the File Name. */
	public function get_name() {
		return $_FILES['qqfile']['name'];
	}

	/** Get the File Size. */
	public function get_size() {
		return $_FILES['qqfile']['size'];
	}
}
