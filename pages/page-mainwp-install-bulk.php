<?php
namespace MainWP\Dashboard;

/**
 * MainWP Install Bulk
 *
 * @used-by MainWP_Plugins::InstallPlugins
 * @used-by MainWP_Themes::InstallThemes
 */
class MainWP_Install_Bulk {
	public static function get_class_name() {
		return __CLASS__;
	}

	// Has to be called in System constructor - adds handling for the main page
	public static function init() {
		add_action( 'admin_init', array( self::get_class_name(), 'admin_init' ) );
	}

	// Handles the uploading of a file
	public static function admin_init() {
		if ( isset( $_REQUEST['mainwp_do'] ) ) {
			if ( $_REQUEST['mainwp_do'] == 'MainWP_Install_Bulk-uploadfile' ) {
				// list of valid extensions, ex. array("jpeg", "xml", "bmp")
				$allowedExtensions = array( 'zip' ); // Only zip allowed
				// max file size in bytes
				$sizeLimit = 2 * 1024 * 1024; // 2MB = max allowed

				$uploader = new qq2FileUploader( $allowedExtensions, $sizeLimit );
				$path     = MainWP_Utility::get_mainwp_specific_dir( 'bulk' );

				$result = $uploader->handleUpload( $path, true );
				// to pass data through iframe you will need to encode all html tags
				die( htmlspecialchars( wp_json_encode( $result ), ENT_NOQUOTES ) );
			}
		}
	}

	// Renders the upload sub part
	public static function render_upload( $type ) {
		$title             = ( $type == 'plugin' ) ? 'Plugins' : 'Themes';
		$favorites_enabled = is_plugin_active( 'mainwp-favorites-extension/mainwp-favorites-extension.php' );
		$cls               = $favorites_enabled ? 'favorites-extension-enabled ' : '';
		$cls              .= ( $type == 'plugin' ) ? 'qq-upload-plugins' : '';
		?>
		<div class="ui secondary center aligned padded segment">
			<h2 class="ui icon header">
				<i class="file archive outline icon"></i>
				<div class="content">
					<?php esc_html_e( 'Upload .zip File', 'mainwp' ); ?>
					<div class="sub header"><?php esc_html_e( 'If you have', 'mainwp' ); ?> <?php echo strtolower( $title ); ?> <?php esc_html_e( 'in a .zip format, you may install it by uploading it here.', 'mainwp' ); ?></div>
				</div>
			</h2>
			<div class="ui divider"></div>
				<div id="mainwp-file-uploader" class="<?php echo $cls; ?>" >
					<noscript>
					<div class="ui message red"><?php esc_html_e( 'Please enable JavaScript to use file uploader.', 'mainwp' ); ?></div>
					</noscript>
				</div>
				<script>
					function createUploader() {
						var uploader = new qq.FileUploader( {
							element: document.getElementById( 'mainwp-file-uploader' ),
							action: location.href,
							<?php
							$extraOptions = apply_filters( 'mainwp_uploadbulk_uploader_options', '', $type ); // support mainwp favorites extension
							$extraOptions = trim( $extraOptions );
							$extraOptions = trim( trim( $extraOptions, ',' ) );
							if ( $extraOptions != '' ) {
								echo wp_strip_all_tags( $extraOptions ) . ',';
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
		<?php
	}

	public static function prepare_install() {
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';

		if ( ! isset( $_POST['url'] ) ) {
			if ( $_POST['type'] == 'plugin' ) {
				$api = plugins_api(
					'plugin_information', array(
						'slug'   => $_POST['slug'],
						'fields' => array( 'sections' => false ),
					)
				); // Save on a bit of bandwidth.
			} else {
				$api = themes_api(
					'theme_information', array(
						'slug'   => $_POST['slug'],
						'fields' => array( 'sections' => false ),
					)
				); // Save on a bit of bandwidth.
			}
			$url = $api->download_link;
		} else {
			$url = $_POST['url'];

			$mwpDir = MainWP_Utility::get_mainwp_dir();
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
			// Get sites
			foreach ( $_POST['selected_sites'] as $enc_id ) {
				$websiteid = $enc_id;
				if ( MainWP_Utility::ctype_digit( $websiteid ) ) {
					$website                         = MainWP_DB::instance()->get_website_by_id( $websiteid );
					$output['sites'][ $website->id ] = MainWP_Utility::map_site(
						$website,
						array(
							'id',
							'url',
							'name',
						)
					);
				}
			}
		} else {
			// Get sites from group
			foreach ( $_POST['selected_groups'] as $enc_id ) {
				$groupid = $enc_id;
				if ( MainWP_Utility::ctype_digit( $groupid ) ) {
					$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $groupid ) );
					while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
						if ( $website->sync_errors != '' ) {
							continue;
						}
						$output['sites'][ $website->id ] = MainWP_Utility::map_site(
							$website,
							array(
								'id',
								'url',
								'name',
							)
						);
					}
					MainWP_DB::free_result( $websites );
				}
			}
		}

		wp_send_json( $output );
	}

	public static function addition_post_data( &$post_data = array() ) {
		$clear_and_lock_opts = apply_filters( 'mainwp_clear_and_lock_options', array() );
		if ( isset( $post_data['url'] ) && false !== strpos( $post_data['url'], 'mwpdl' ) && false !== strpos( $post_data['url'], 'sig' ) ) {
			if ( is_array( $clear_and_lock_opts ) && isset( $clear_and_lock_opts['wpadmin_user'] ) && ! empty( $clear_and_lock_opts['wpadmin_user'] ) && isset( $clear_and_lock_opts['wpadmin_passwd'] ) && ! empty( $clear_and_lock_opts['wpadmin_passwd'] ) ) {
				$post_data['wpadmin_user']   = $clear_and_lock_opts['wpadmin_user'];
				$post_data['wpadmin_passwd'] = $clear_and_lock_opts['wpadmin_passwd'];
			}
		}
		return $post_data;
	}

	public static function perform_install() {
		MainWP_Utility::end_session();

		// Fetch info..
		$post_data = array(
			'type' => $_POST['type'],
		);
		if ( $_POST['activatePlugin'] == 'true' ) {
			$post_data['activatePlugin'] = 'yes';
		}
		if ( $_POST['overwrite'] == 'true' ) {
			$post_data['overwrite'] = true;
		}

		// deprecated from 3.5.6
		self::addition_post_data( $post_data );

		// hook to support addition data: wpadmin_user, wpadmin_passwd
		$post_data = apply_filters( 'mainwp_perform_install_data', $post_data );

		$post_data['url'] = wp_json_encode( $_POST['url'] );

		$output         = new \stdClass();
		$output->ok     = array();
		$output->errors = array();
		$websites       = array( MainWP_DB::instance()->get_website_by_id( $_POST['siteId'] ) );
		MainWP_Utility::fetch_urls_authed( $websites, 'installplugintheme', $post_data, array(
			self::get_class_name(),
			'install_plugin_theme_handler',
		), $output, null, array( 'upgrade' => true ) );

		// die( wp_json_encode( $output ) );
		wp_send_json( $output );
	}

	public static function prepare_upload() {
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';

		$output          = array();
		$output['sites'] = array();
		if ( $_POST['selected_by'] == 'site' ) {
			// Get sites
			foreach ( $_POST['selected_sites'] as $enc_id ) {
				$websiteid = $enc_id;
				if ( MainWP_Utility::ctype_digit( $websiteid ) ) {
					$website                         = MainWP_DB::instance()->get_website_by_id( $websiteid );
					$output['sites'][ $website->id ] = MainWP_Utility::map_site(
						$website,
						array(
							'id',
							'url',
							'name',
						)
					);
				}
			}
		} else {
			// Get sites from group
			foreach ( $_POST['selected_groups'] as $enc_id ) {
				$groupid = $enc_id;
				if ( MainWP_Utility::ctype_digit( $groupid ) ) {
					$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $groupid ) );
					while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
						if ( $website->sync_errors != '' ) {
							continue;
						}
						$output['sites'][ $website->id ] = MainWP_Utility::map_site(
							$website,
							array(
								'id',
								'url',
								'name',
							)
						);
					}
					MainWP_DB::free_result( $websites );
				}
			}
		}

		$output['urls'] = array();

		foreach ( $_POST['files'] as $file ) {
			$output['urls'][] = MainWP_Utility::get_download_url( 'bulk', $file );
		}
		$output['urls'] = implode( '||', $output['urls'] );
		$output['urls'] = apply_filters( 'mainwp_installbulk_prepareupload', $output['urls'] );

		// die( wp_json_encode( $output ) );
		wp_send_json( $output );
	}

	public static function perform_upload() {
		MainWP_Utility::end_session();

		// Fetch info..
		$post_data = array(
			'type' => $_POST['type'],
		);
		if ( $_POST['activatePlugin'] == 'true' ) {
			$post_data['activatePlugin'] = 'yes';
		}
		if ( $_POST['overwrite'] == 'true' ) {
			$post_data['overwrite'] = true;
		}

		// deprecated from 3.5.6
		self::addition_post_data( $post_data );

		// hook to support addition data: wpadmin_user, wpadmin_passwd
		$post_data = apply_filters( 'mainwp_perform_install_data', $post_data );

		$post_data['url'] = wp_json_encode( explode( '||', $_POST['urls'] ) );

		$output         = new \stdClass();
		$output->ok     = array();
		$output->errors = array();
		$websites       = array( MainWP_DB::instance()->get_website_by_id( $_POST['siteId'] ) );
		MainWP_Utility::fetch_urls_authed( $websites, 'installplugintheme', $post_data, array(
			self::get_class_name(),
			'install_plugin_theme_handler',
		), $output, null, array( 'upgrade' => true ) );

		// die( wp_json_encode( $output ) );
		wp_send_json( $output );
	}

	public static function clean_upload() {
		$hasWPFileSystem = MainWP_Utility::get_wp_file_system();
		global $wp_filesystem;
		
		$path = MainWP_Utility::get_mainwp_specific_dir( 'bulk' );
		if ( $wp_filesystem->exists( $path ) ) {
			$dh = opendir( $path );
			if ( $dh ) {
				while ( ( $file = readdir( $dh ) ) !== false ) {
					if ( $file != '.' && $file != '..' ) {
						$wp_filesystem->delete( $path . $file );
					}
				}
				closedir( $dh );
			}
		}

		die( wp_json_encode( array( 'ok' => true ) ) );
	}

	public static function install_plugin_theme_handler( $data, $website, &$output ) {
		if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$result      = $results[1];
			$information = MainWP_Utility::get_child_response( base64_decode( $result ) );

			if ( isset( $information['installation'] ) && $information['installation'] == 'SUCCESS' ) {
				$output->ok[ $website->id ] = array( $website->name );
			} elseif ( isset( $information['error'] ) ) {
				$error = $information['error'];
				if ( isset( $information['error_code'] ) && 'folder_exists' == $information['error_code'] ) {
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
 */

/**
 * Handle file uploads via XMLHttpRequest
 */
class qq2UploadedFileXhr {
	/**
	 * Save the file to the specified path
	 *
	 * @return boolean TRUE on success
	 */
	public function save( $path ) {
		$input    = fopen( 'php://input', 'r' );
		$temp     = tmpfile();
		$realSize = stream_copy_to_stream( $input, $temp );
		fclose( $input );

		if ( $realSize != $this->get_size() ) {
			return false;
		}

		$hasWPFileSystem = MainWP_Utility::get_wp_file_system();
		/** @global WP_Filesystem_Base $wp_filesystem */
		global $wp_filesystem;

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
			if ( ! is_dir( dirname( dirname( dirname( $path ) ) ) ) ) {
				if ( ! $wp_filesystem->mkdir( dirname( dirname( dirname( $path ) ) ) ) ) {
					throw new \Exception( 'Unable to create the MainWP bulk upload directory, please check your system configuration.' );
				}
			}

			if ( ! is_dir( dirname( dirname( $path ) ) ) ) {
				if ( ! $wp_filesystem->mkdir( dirname( dirname( $path ) ) ) ) {
					throw new \Exception( 'Unable to create the MainWP bulk upload directory, please check your system configuration.' );
				}
			}

			if ( ! is_dir( dirname( $path ) ) ) {
				if ( ! $wp_filesystem->mkdir( dirname( $path ) ) ) {
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

	public function get_name() {
		return $_GET['qqfile'];
	}

	public function get_size() {
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) ) {
			return (int) $_SERVER['CONTENT_LENGTH'];
		} else {
			throw new \Exception( 'Getting content length is not supported.' );
		}
	}
}

/**
 * Handle file uploads via regular form post (uses the $_FILES array)
 */
class qq2UploadedFileForm {
	/**
	 * Save the file to the specified path
	 *
	 * @return boolean TRUE on success
	 */
	public function save( $path ) {
		$wpFileSystem = MainWP_Utility::get_wp_file_system();

		if ( $wpFileSystem != null ) {
			$path  = str_replace( MainWP_Utility::get_base_dir(), '', $path );
			$moved = $wpFileSystem->put_contents( $path, file_get_contents( $_FILES['qqfile']['tmp_name'] ) );
		} else {
			$moved = move_uploaded_file( $_FILES['qqfile']['tmp_name'], $path );
		}

		if ( ! $moved ) {
			return false;
		}

		return true;
	}

	public function get_name() {
		return $_FILES['qqfile']['name'];
	}

	public function get_size() {
		return $_FILES['qqfile']['size'];
	}
}


class qq2FileUploader {
	private $allowedExtensions = array();
	private $sizeLimit         = 8388608;
	private $file;

	public function __construct( array $allowedExtensions = array(), $sizeLimit = 8388608 ) {
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
	public function handleUpload( $uploadDirectory, $replaceOldFile = false ) {
		// if (!is_writable($uploadDirectory)){
		// return array('error' => "Server error. Upload directory isn't writable.");
		// }

		if ( ! $this->file ) {
			return array( 'error' => 'No files were uploaded!' );
		}

		$size = $this->file->get_size();

		if ( $size == 0 ) {
			return array( 'error' => 'File is empty!' );
		}

		$postSize   = $this->toBytes( ini_get( 'post_max_size' ) );
		$uploadSize = $this->toBytes( ini_get( 'upload_max_filesize' ) );
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
