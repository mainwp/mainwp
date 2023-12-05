<?php
/**
 * MainWP_QQ2_Uploaded_File_Xhr
 *
 * DO NOT TOUCH - part of http://github.com/valums/file-uploader ! (@see js/fileuploader.js)
 * Handle file uploads via XMLHttpRequest
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_QQ2_Uploaded_File_Xhr
 *
 * @package MainWP\Dashboard
 */
class MainWP_QQ2_Uploaded_File_Xhr {

	// phpcs:disable WordPress.WP.AlternativeFunctions -- use system functions

	/**
	 * Save the file to the specified path
	 *
	 * @param string $path Path to File.
	 *
	 * @throws \Exception Error messages.
	 *
	 * @return boolean true|false.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
	 */
	public function save( $path ) {
		$input    = fopen( 'php://input', 'r' );
		$temp     = tmpfile();
		$realSize = stream_copy_to_stream( $input, $temp );
		fclose( $input );

		if ( $realSize != $this->get_size() ) { //phpcs:ignore -- to valid.
			return false;
		}

		$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
		global $wp_filesystem;

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
			if ( ! is_dir( dirname( dirname( dirname( $path ) ) ) ) ) {
				if ( ! $wp_filesystem->mkdir( dirname( dirname( dirname( $path ) ) ), 0777 ) ) {
					throw new \Exception( 'Unable to create the MainWP bulk upload directory, please check your system configuration.' );
				}
			}

			if ( ! is_dir( dirname( dirname( $path ) ) ) ) {
				if ( ! $wp_filesystem->mkdir( dirname( dirname( $path ) ), 0777 ) ) {
					throw new \Exception( 'Unable to create the MainWP bulk upload directory, please check your system configuration.' );
				}
			}

			if ( ! is_dir( dirname( $path ) ) ) {
				if ( ! $wp_filesystem->mkdir( dirname( $path ), 0777 ) ) {
					throw new \Exception( 'Unable to create the MainWP bulk upload directory, please check your system configuration.' );
				}
			}

			fseek( $temp, 0, SEEK_SET );
			$wp_filesystem->put_contents( $path, stream_get_contents( $temp ) );
		} else {
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}

			$target = fopen( $path, 'w' );
			fseek( $temp, 0, SEEK_SET );
			if ( stream_copy_to_stream( $temp, $target ) <= 0 ) {
				return false;
			}
			fclose( $target );
		}

		if ( ! file_exists( $path ) ) {
			throw new \Exception( 'Unable to save the file to the MainWP upload directory, please check your system configuration.' );
		}

		return true;
	}

	/** Get the File Name. */
	public function get_name() {
		return isset( $_GET['qqfile'] ) ? sanitize_text_field( wp_unslash( $_GET['qqfile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Method get_size()
	 *
	 * Get content length.
	 *
	 * @throws \Exception Error message.
	 * @return int Content lenght.
	 */
	public function get_size() {
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) ) {
			return (int) $_SERVER['CONTENT_LENGTH'];
		} else {
			throw new \Exception( 'Getting content length is not supported.' );
		}
	}
}
