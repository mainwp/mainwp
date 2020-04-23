<?php
/**
 * MainWP_QQ2_File_Uploader
 *
 * DO NOT TOUCH - part of http://github.com/valums/file-uploader ! (@see js/fileuploader.js)
 */
namespace MainWP\Dashboard;

/** Class MainWP_QQ2_File_Uploader. */
class MainWP_QQ2_File_Uploader {

	/** Array of allowed Extensions. */
	private $allowedExtensions = array();

	/** Maximum allowed file size.  */
	private $sizeLimit = 8388608;

	/** File to be uploaded.  */
	private $file;

	/**
	 * Method __construct
	 *
	 * @param array $allowedExtensions Array of allowed Extensions.
	 * @param int   $sizeLimit Maximum allowed file size.
	 */
	public function __construct( array $allowedExtensions = array(), $sizeLimit = 8388608 ) {
		$allowedExtensions = array_map( 'strtolower', $allowedExtensions );

		$this->allowedExtensions = $allowedExtensions;
		$this->sizeLimit         = $sizeLimit;

		if ( isset( $_GET['qqfile'] ) ) {
			$this->file = new MainWP_QQ2_Uploaded_File_Xhr();
		} elseif ( isset( $_FILES['qqfile'] ) ) {
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
	 * @param mixed   $uploadDirectory File Upload directory.
	 * @param boolean $replaceOldFile True|False Weather or not to replace the orignal file or not.
	 *
	 * @return array success'=>true|error'=>'error message'
	 */
	public function handle_upload( $uploadDirectory, $replaceOldFile = false ) {

		if ( ! $this->file ) {
			return array( 'error' => 'No files were uploaded!' );
		}

		$size = $this->file->get_size();

		if ( $size == 0 ) {
			return array( 'error' => 'File is empty!' );
		}

		$postSize   = $this->to_bytes( ini_get( 'post_max_size' ) );
		$uploadSize = $this->to_bytes( ini_get( 'upload_max_filesize' ) );
		if ( $postSize < $size || $uploadSize < $size ) {
			return array( 'error' => __( 'File is too large, increase post_max_size and/or upload_max_filesize', 'mainwp' ) );
		}

		$pathinfo = pathinfo( $this->file->get_name() );
		$filename = $pathinfo['filename'];
		$ext      = $pathinfo['extension'];

		if ( $this->allowedExtensions && ! in_array( strtolower( $ext ), $this->allowedExtensions ) ) {
			$these = implode( ', ', $this->allowedExtensions );

			return array( 'error' => __( 'File has an invalid extension, it should be one of ', 'mainwp' ) . $these . '.' );
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
					'error' => __( 'Could not save uploaded file!', 'mainwp' ) .
							__( 'The upload was cancelled, or server error encountered.', 'mainwp' ),
				);
			}
		} catch ( \Exception $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}
}
