<?php

/**
 * Used in both InstallPlugins & InstallThemes
 */
class MainWP_Install_Bulk {
	public static function getClassName() {
		return __CLASS__;
	}

	//Has to be called in System constructor - adds handling for the main page
	public static function init() {
		add_action( 'admin_init', array( MainWP_Install_Bulk::getClassName(), 'admin_init' ) );
	}

	//Handles the uploading of a file
	public static function admin_init() {
		if ( isset( $_REQUEST['mainwp_do'] ) ) {
			if ( $_REQUEST['mainwp_do'] == 'MainWP_Install_Bulk-uploadfile' ) {
				// list of valid extensions, ex. array("jpeg", "xml", "bmp")
				$allowedExtensions = array( 'zip' ); //Only zip allowed
				// max file size in bytes
				$sizeLimit = 2 * 1024 * 1024; //2MB = max allowed

				$uploader = new qq2FileUploader( $allowedExtensions, $sizeLimit );
				$path     = MainWP_Utility::getMainWPSpecificDir( 'bulk' );

				$result = $uploader->handleUpload( $path, true );
				// to pass data through iframe you will need to encode all html tags
				die( htmlspecialchars( json_encode( $result ), ENT_NOQUOTES ) );
			}
		}
	}

	//Renders the upload sub part
	public static function renderUpload( $title ) {
		$type = ($title == 'Plugins') ? 'plugin' : 'theme';
		?>
		<div class="postbox">
			<h3 class="mainwp_box_title">
				<i class="fa fa-upload"></i> <?php echo ($type == 'plugin') ? __( 'Step 1: Upload plugins', 'mainwp' ) : __( 'Step 1: Upload themes', 'mainwp' ); ?></h3>
			<?php if ( $type == 'plugin' ) { ?>
				<div class="mainwp-postbox-actions-top">
					<?php _e( '<strong>Do not upload extensions here</strong>, they do not go on the child sites, upload and activate them via your dashboard sites', 'mainwp' ) ?> <a href="<?php echo get_admin_url(); ?>plugin-install.php"> <?php _e( 'plugin screen.', 'mainwp' ); ?></a>
				</div>
			<?php } ?>
			<div class="mainwp-padding-10">

				<div style="font-size: 20px; text-align: center; margin: 3em 0;"><i class="fa fa-file-archive-o fa-4x" aria-hidden="true"></i> <br/><br/> <?php _e( 'If you have', 'mainwp' ); ?> <?php echo strtolower( $title ); ?> <?php _e( 'in a .zip format, you may install it by uploading it here.', 'mainwp' ); ?></div>
				<div id="mainwp-file-uploader">
					<noscript>
						<p><?php _e( 'Please enable JavaScript to use file uploader.', 'mainwp' ); ?></p>
					</noscript>
				</div>
				<script>
					function createUploader() {
						var uploader = new qq.FileUploader( {
							element: document.getElementById( 'mainwp-file-uploader' ),
							action: location.href,
							<?php $extraOptions = apply_filters( 'mainwp_uploadbulk_uploader_options', '', $type );
							$extraOptions = trim( $extraOptions );
							$extraOptions = trim( trim( $extraOptions, ',' ) );
							if ( $extraOptions != '' ) {
								echo $extraOptions . ',';
							}
							?>
							params: {mainwp_do: 'MainWP_Install_Bulk-uploadfile'}
						} );
					}

					// in your app create uploader as soon as the DOM is ready
					// don't wait for the window to load
					createUploader();
				</script>
			</div>
		</div>
		<?php
	}

	public static function prepareInstall() {
		include_once( ABSPATH . '/wp-admin/includes/plugin-install.php' );

		if ( ! isset( $_POST['url'] ) ) {
			if ( $_POST['type'] == 'plugin' ) {
				$api = plugins_api( 'plugin_information', array(
					'slug'   => $_POST['slug'],
					'fields' => array( 'sections' => false ),
				) ); //Save on a bit of bandwidth.
			} else {
				$api = themes_api( 'theme_information', array(
					'slug'   => $_POST['slug'],
					'fields' => array( 'sections' => false ),
				) ); //Save on a bit of bandwidth.
			}
			$url = $api->download_link;
		} else {
			$url = $_POST['url'];

			$mwpDir = MainWP_Utility::getMainWPDir();
			$mwpUrl = $mwpDir[1];
			if ( stristr( $url, $mwpUrl ) ) {
				$fullFile = $mwpDir[0] . str_replace( $mwpUrl, '', $url );
				$url      = admin_url( '?sig=' . md5( filesize( $fullFile ) ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir[0], '', $fullFile ) ) );
			}
		}

		$output          = array();
		$output['url']   = $url;
		$output['sites'] = array();

		if ( $_POST['selected_by'] == 'site' ) {
			//Get sites
			foreach ( $_POST['selected_sites'] as $enc_id ) {
				$websiteid = $enc_id;
				if ( MainWP_Utility::ctype_digit( $websiteid ) ) {
					$website                         = MainWP_DB::Instance()->getWebsiteById( $websiteid );
					$output['sites'][ $website->id ] = MainWP_Utility::mapSite( $website, array(
						'id',
						'url',
						'name',
					) );
				}
			}
		} else {
			//Get sites from group
			foreach ( $_POST['selected_groups'] as $enc_id ) {
				$groupid = $enc_id;
				if ( MainWP_Utility::ctype_digit( $groupid ) ) {
					$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $groupid ) );
					while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
						if ( $website->sync_errors != '' ) {
							continue;
						}
						$output['sites'][ $website->id ] = MainWP_Utility::mapSite( $website, array(
							'id',
							'url',
							'name',
						) );
					}
					@MainWP_DB::free_result( $websites );
				}
			}
		}

		die( json_encode( $output ) );
	}
        
        public static function addition_post_data( &$post_data = array()) {
            $clear_and_lock_opts = apply_filters( 'mainwp_clear_and_lock_options', array() );   
            if ( isset($post_data['url']) && false !== strpos( $post_data['url'], 'mwpdl') && false !== strpos( $post_data['url'], 'sig') ) {
                if ( is_array($clear_and_lock_opts) && isset($clear_and_lock_opts['wpadmin_user']) && !empty($clear_and_lock_opts['wpadmin_user']) && isset($clear_and_lock_opts['wpadmin_passwd']) && !empty($clear_and_lock_opts['wpadmin_passwd']) ) {
                    $post_data['wpadmin_user'] = $clear_and_lock_opts['wpadmin_user'];
                    $post_data['wpadmin_passwd'] = $clear_and_lock_opts['wpadmin_passwd'];
                }
            }           
            return $post_data;
        }
        
	public static function performInstall() {
		MainWP_Utility::endSession();

		//Fetch info..
		$post_data = array(
			'url'  => json_encode( $_POST['url'] ),
			'type' => $_POST['type'],
		);
		if ( $_POST['activatePlugin'] == 'true' ) {
			$post_data['activatePlugin'] = 'yes';
		}
		if ( $_POST['overwrite'] == 'true' ) {
			$post_data['overwrite'] = true;
		}            
                
                self::addition_post_data( $post_data );
                
		$output         = new stdClass();
		$output->ok     = array();
		$output->errors = array();
		$websites       = array( MainWP_DB::Instance()->getWebsiteById( $_POST['siteId'] ) );
		MainWP_Utility::fetchUrlsAuthed( $websites, 'installplugintheme', $post_data, array(
			MainWP_Install_Bulk::getClassName(),
			'InstallPluginTheme_handler',
		), $output );

		die( json_encode( $output ) );
	}

	public static function prepareUpload() {
		include_once( ABSPATH . '/wp-admin/includes/plugin-install.php' );

		$output          = array();
		$output['sites'] = array();
		if ( $_POST['selected_by'] == 'site' ) {
			//Get sites
			foreach ( $_POST['selected_sites'] as $enc_id ) {
				$websiteid = $enc_id;
				if ( MainWP_Utility::ctype_digit( $websiteid ) ) {
					$website                         = MainWP_DB::Instance()->getWebsiteById( $websiteid );
					$output['sites'][ $website->id ] = MainWP_Utility::mapSite( $website, array(
						'id',
						'url',
						'name',
					) );
				}
			}
		} else {
			//Get sites from group
			foreach ( $_POST['selected_groups'] as $enc_id ) {
				$groupid = $enc_id;
				if ( MainWP_Utility::ctype_digit( $groupid ) ) {
					$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $groupid ) );
					while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
						if ( $website->sync_errors != '' ) {
							continue;
						}
						$output['sites'][ $website->id ] = MainWP_Utility::mapSite( $website, array(
							'id',
							'url',
							'name',
						) );
					}
					@MainWP_DB::free_result( $websites );
				}
			}
		}

		$output['urls'] = array();

		foreach ( $_POST['files'] as $file ) {
			$output['urls'][] = MainWP_Utility::getDownloadUrl( 'bulk', $file );
		}
		$output['urls'] = implode( '||', $output['urls'] );
		$output['urls'] = apply_filters( 'mainwp_installbulk_prepareupload', $output['urls'] );

		die( json_encode( $output ) );
	}

	public static function performUpload() {
		MainWP_Utility::endSession();

		//Fetch info..
		$post_data = array(
			'url'  => json_encode( explode( '||', $_POST['urls'] ) ),
			'type' => $_POST['type'],
		);
		if ( $_POST['activatePlugin'] == 'true' ) {
			$post_data['activatePlugin'] = 'yes';
		}
		if ( $_POST['overwrite'] == 'true' ) {
			$post_data['overwrite'] = true;
		}
                self::addition_post_data( $post_data );
		$output         = new stdClass();
		$output->ok     = array();
		$output->errors = array();
		$websites       = array( MainWP_DB::Instance()->getWebsiteById( $_POST['siteId'] ) );
		MainWP_Utility::fetchUrlsAuthed( $websites, 'installplugintheme', $post_data, array(
			MainWP_Install_Bulk::getClassName(),
			'InstallPluginTheme_handler',
		), $output );
		die( json_encode( $output ) );
	}

	public static function cleanUpload() {
		$path = MainWP_Utility::getMainWPSpecificDir( 'bulk' );
		if ( file_exists( $path ) && ( $dh = opendir( $path ) ) ) {
			while ( ( $file = readdir( $dh ) ) !== false ) {
				if ( $file != '.' && $file != '..' ) {
					@unlink( $path . $file );
				}
			}
			closedir( $dh );
		}

		die( json_encode( array( 'ok' => true ) ) );
	}

	public static function InstallPluginTheme_handler( $data, $website, &$output ) {
		if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$result      = $results[1];
			$information = unserialize( base64_decode( $result ) );
			if ( isset( $information['installation'] ) && $information['installation'] == 'SUCCESS' ) {
				$output->ok[ $website->id ] = array( $website->name );
			} else if ( isset( $information['error'] ) ) {
				$error = $information['error'];
				if ( isset($information['error_code']) && 'folder_exists' ==  $information['error_code'] ) {
					$error = __( 'Already installed', 'mainwp' );
				}
				$output->errors[ $website->id ] = array( $website->name, $error );
			} else {
				$output->errors[ $website->id ] = array(
					$website->name,
					__( 'Undefined error! Please reinstall the MainWP Child plugin on the child site', 'mainwp' ),
				);
			}
		} else {
			$output->errors[ $website->id ] = array( $website->name, 'Error while installing' );
		}
	}
}

/**
 *
 * DO NOT TOUCH - part of http://github.com/valums/file-uploader ! (@see js/fileuploader.js)
 *
 */

/**
 * Handle file uploads via XMLHttpRequest
 */
class qq2UploadedFileXhr {
	/**
	 * Save the file to the specified path
	 * @return boolean TRUE on success
	 */
	function save( $path ) {
		$input    = fopen( 'php://input', 'r' );
		$temp     = tmpfile();
		$realSize = stream_copy_to_stream( $input, $temp );
		fclose( $input );

		if ( $realSize != $this->getSize() ) {
			return false;
		}

		$hasWPFileSystem = MainWP_Utility::getWPFilesystem();
		/** @global WP_Filesystem_Base $wp_filesystem */
		global $wp_filesystem;

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
			if ( ! is_dir( dirname( dirname( dirname( $path ) ) ) ) ) {
				if ( ! $wp_filesystem->mkdir( dirname( dirname( dirname( $path ) ) ) ) ) {
					throw new Exception( 'Unable to create the MainWP bulk upload directory, please check your system configuration.' );
				}
			}

			if ( ! is_dir( dirname( dirname( $path ) ) ) ) {
				if ( ! $wp_filesystem->mkdir( dirname( dirname( $path ) ) ) ) {
					throw new Exception( 'Unable to create the MainWP bulk upload directory, please check your system configuration.' );
				}
			}

			if ( ! is_dir( dirname( $path ) ) ) {
				if ( ! $wp_filesystem->mkdir( dirname( $path ) ) ) {
					throw new Exception( 'Unable to create the MainWP bulk upload directory, please check your system configuration.' );
				}
			}

			fseek( $temp, 0, SEEK_SET );
			$wp_filesystem->put_contents( $path, stream_get_contents( $temp ) );
		} else {
			if ( ! is_dir( dirname( $path ) ) ) {
				@mkdir( dirname( $path ), 0777, true );
			}

			$target = fopen( $path, 'w' );
			fseek( $temp, 0, SEEK_SET );
			if ( stream_copy_to_stream( $temp, $target ) <= 0 ) {
				return false;
			}
			fclose( $target );
		}

		if ( ! file_exists( $path ) ) {
			throw new Exception( 'Unable to save the file to the MainWP upload directory, please check your system configuration.' );
		}

		return true;
	}

	function getName() {
		return $_GET['qqfile'];
	}

	function getSize() {
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) ) {
			return (int) $_SERVER['CONTENT_LENGTH'];
		} else {
			throw new Exception( 'Getting content length is not supported.' );
		}
	}
}

/**
 * Handle file uploads via regular form post (uses the $_FILES array)
 */
class qq2UploadedFileForm {
	/**
	 * Save the file to the specified path
	 * @return boolean TRUE on success
	 */
	function save( $path ) {
		$wpFileSystem = MainWP_Utility::getWPFilesystem();

		if ( $wpFileSystem != null ) {
			$path  = str_replace( MainWP_Utility::getBaseDir(), '', $path );
			$moved = $wpFileSystem->put_contents( $path, file_get_contents( $_FILES['qqfile']['tmp_name'] ) );
		} else {
			$moved = move_uploaded_file( $_FILES['qqfile']['tmp_name'], $path );
		}

		if ( ! $moved ) {
			return false;
		}

		return true;
	}

	function getName() {
		return $_FILES['qqfile']['name'];
	}

	function getSize() {
		return $_FILES['qqfile']['size'];
	}
}


class qq2FileUploader {
	private $allowedExtensions = array();
	private $sizeLimit = 8388608;
	private $file;

	function __construct( array $allowedExtensions = array(), $sizeLimit = 8388608 ) {
		$allowedExtensions = array_map( 'strtolower', $allowedExtensions );

		$this->allowedExtensions = $allowedExtensions;
		$this->sizeLimit         = $sizeLimit;

		if ( isset( $_GET['qqfile'] ) ) {
			$this->file = new qq2UploadedFileXhr();
		} elseif ( isset( $_FILES['qqfile'] ) ) {
			$this->file = new qq2UploadedFileForm();
		} else {
			$this->file = false;
		}
	}

	private function toBytes( $str ) {
		$val  = trim( $str );
		$last = strtolower( $str[ strlen( $str ) - 1 ] );
		switch ( $last ) {
			case 'g':
				$val *= 1024;
			case 'm':
				$val *= 1024;
			case 'k':
				$val *= 1024;
		}

		return $val;
	}

	/**
	 * Returns array('success'=>true) or array('error'=>'error message')
	 */
	function handleUpload( $uploadDirectory, $replaceOldFile = false ) {
		//        if (!is_writable($uploadDirectory)){
		//            return array('error' => "Server error. Upload directory isn't writable.");
		//        }

		if ( ! $this->file ) {
			return array( 'error' => 'No files were uploaded!' );
		}

		$size = $this->file->getSize();

		if ( $size == 0 ) {
			return array( 'error' => 'File is empty!' );
		}

		$postSize   = $this->toBytes( ini_get( 'post_max_size' ) );
		$uploadSize = $this->toBytes( ini_get( 'upload_max_filesize' ) );
		if ( $postSize < $size || $uploadSize < $size ) {
			return array( 'error' => __( 'File is too large, increase post_max_size and/or upload_max_filesize', 'mainwp' ) );
		}

		$pathinfo = pathinfo( $this->file->getName() );
		$filename = $pathinfo['filename'];
		//$filename = md5(uniqid());
		$ext = $pathinfo['extension'];

		if ( $this->allowedExtensions && ! in_array( strtolower( $ext ), $this->allowedExtensions ) ) {
			$these = implode( ', ', $this->allowedExtensions );

			return array( 'error' => __( 'File has an invalid extension, it should be one of ', 'mainwp' ) . $these . '.' );
		}

		if ( ! $replaceOldFile ) {
			/// don't overwrite previous files that were uploaded
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
					           _( 'The upload was cancelled, or server error encountered.', 'mainwp' ),
				);
			}
		} catch ( Exception $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}
}
