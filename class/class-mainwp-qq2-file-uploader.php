<?php
/**
 * MainWP_QQ2_File_Uploader
 *
 * DO NOT TOUCH - part of http://github.com/valums/file-uploader ! (@see js/fileuploader.js)
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_QQ2_File_Uploader
 *
 * @package MainWP\Dashboard
 */
class MainWP_QQ2_File_Uploader {

	/**
	 * Private variable to hold allowed file extensions.
	 *
	 * @var array Allowed extension.
	 */
	private $allowedExtensions = array();

	/**
	 * Private variable to hold allowed file size.
	 *
	 * @var int Size limit.
	 */
	private $sizeLimit = 8388608;

	/**
	 * Private variable to hold the file to upload.
	 *
	 * @var mixed The file.
	 */
	private $file;

	/**
	 * MainWP_QQ2_File_Uploader constructor.
	 *
	 * Run each time the class is called.
	 *
	 * @param array $allowedExtensions Array of allowed Extensions.
	 * @param int   $sizeLimit Maximum allowed file size.
	 *
	 * @uses \MainWP\Dashboard\MainWP_QQ2_Uploaded_File_Form
	 * @uses \MainWP\Dashboard\MainWP_QQ2_Uploaded_File_Xhr
	 */
	public function __construct( array $allowedExtensions = array(), $sizeLimit = 8388608 ) {
		$allowedExtensions = array_map( 'strtolower', $allowedExtensions );

		$this->allowedExtensions = $allowedExtensions;

		/**
		* Filter: 'mainwp_file_uploader_size_limit'
		*
		* Filters the maximum upload file size. Default: 8388608 Bytes (B) = 8 Megabytes (MB)
		*
		* @since 4.1
		*/
		$this->sizeLimit = apply_filters( 'mainwp_file_uploader_size_limit', $sizeLimit );

		if ( isset( $_GET['qqfile'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$this->file = new MainWP_QQ2_Uploaded_File_Xhr();
		} elseif ( isset( $_FILES['qqfile'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$this->file = new MainWP_QQ2_Uploaded_File_Form();
		} else {
			$this->file = false;
		}
	}

	/**
	 * Convert file size into bytes.
	 *
	 * @param int $str Original File.
	 *
	 * @return int $val File Size in Bytes.
	 */
	private function to_bytes( $str ) {
		$val  = trim( $str );
		$last = strtolower( $str[ strlen( $str ) - 1 ] );
		switch ( $last ) {
			case 'g':
				$val = substr( $str, 0, strlen( $str ) - 1 ) * 1024 * 1024 * 1024;
				break;
			case 'm':
				$val = substr( $str, 0, strlen( $str ) - 1 ) * 1024 * 1024;
				break;
			case 'k':
				$val = substr( $str, 0, strlen( $str ) - 1 ) * 1024;
				break;
		}

		return $val;
	}

	/**
	 * Handle the file upload.
	 *
	 * @param mixed $uploadDirectory File Upload directory.
	 * @param bool  $replaceOldFile True|False Weather or not to replace the orignal file or not.
	 *
	 * @return array success'=>true|error'=>'error message'
	 */
	public function handle_upload( $uploadDirectory, $replaceOldFile = false ) {

		if ( ! $this->file ) {
			return array( 'error' => 'No files were uploaded!' );
		}

		$size = $this->file->get_size();

		if ( empty( $size ) ) {
			return array( 'error' => 'File is empty!' );
		}

		$postSize   = $this->to_bytes( ini_get( 'post_max_size' ) );
		$uploadSize = $this->to_bytes( ini_get( 'upload_max_filesize' ) );
		if ( $postSize < $size || $uploadSize < $size ) {
			return array( 'error' => esc_html__( 'File is too large, increase post_max_size and/or upload_max_filesize', 'mainwp' ) );
		}

		$pathinfo = pathinfo( $this->file->get_name() );
		$filename = $pathinfo['filename'];
		$ext      = $pathinfo['extension'];

		if ( $this->allowedExtensions && ! in_array( strtolower( $ext ), $this->allowedExtensions ) ) {
			$these = implode( ', ', $this->allowedExtensions );

			return array( 'error' => esc_html__( 'File has an invalid extension, it should be one of ', 'mainwp' ) . $these . '.' );
		}

		if ( ! $replaceOldFile ) {
			// don't overwrite previous files that were uploaded.
			while ( file_exists( $uploadDirectory . $filename . '.' . $ext ) ) {
				$filename .= wp_rand( 10, 99 );
			}
		}

		try {
			if ( $this->file->save( $uploadDirectory . $filename . '.' . $ext ) ) {
				return array( 'success' => true );
			} else {
				return array(
					'error' => esc_html__( 'Could not save uploaded file!', 'mainwp' ) .
							esc_html__( 'The upload was cancelled, or server error encountered.', 'mainwp' ),
				);
			}
		} catch ( \Exception $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}

	/**
	 * Wrap of the method handle_upload() for compatible.
	 *
	 * @param mixed $uploadDirectory File Upload directory.
	 * @param bool  $replaceOldFile True|False Weather or not to replace the orignal file or not.
	 *
	 * @return array success'=>true|error'=>'error message'
	 */
	public function handleUpload( $uploadDirectory, $replaceOldFile = false ) { // phpcs:ignore -- for compatible
		return $this->handle_upload( $uploadDirectory, $replaceOldFile );
	}
}
