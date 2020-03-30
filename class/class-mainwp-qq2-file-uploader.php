<?php
namespace MainWP\Dashboard;

/**
 * MainWP_QQ2_File_Uploader
 *
 * DO NOT TOUCH - part of http://github.com/valums/file-uploader ! (@see js/fileuploader.js)
 */

class MainWP_QQ2_File_Uploader {
	private $allowedExtensions = array();
	private $sizeLimit         = 8388608;
	private $file;

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
	 * Returns array('success'=>true) or array('error'=>'error message')
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
		// $filename = md5(uniqid());
		$ext = $pathinfo['extension'];

		if ( $this->allowedExtensions && ! in_array( strtolower( $ext ), $this->allowedExtensions ) ) {
			$these = implode( ', ', $this->allowedExtensions );

			return array( 'error' => __( 'File has an invalid extension, it should be one of ', 'mainwp' ) . $these . '.' );
		}

		if ( ! $replaceOldFile ) {
			// don't overwrite previous files that were uploaded
			while ( file_exists( $uploadDirectory . $filename . '.' . $ext ) ) {
				$filename .= rand( 10, 99 );
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
		} catch ( Exception $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}
}
