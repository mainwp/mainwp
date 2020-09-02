<?php
/**
 * This file handles all of the Bulk Installation Methods
 * for Plugins & Themes.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Install_Bulk
 *
 * @used-by MainWP_Plugins::InstallPlugins
 * @used-by MainWP_Themes::InstallThemes
 */
class MainWP_Install_Bulk {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 *
	 * @uses self::init()
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method init()
	 *
	 * Instantiate the main page
	 *
	 * Has to be called in System constructor,
	 * adds handling for the main page.
	 */
	public static function init() {
		add_action( 'admin_init', array( self::get_class_name(), 'admin_init' ) );
	}

	/**
	 * Method admin_init()
	 *
	 * Handles the uploading of a file.
	 */
	public static function admin_init() {
		if ( isset( $_REQUEST['mainwp_do'] ) ) {
			if ( 'MainWP_Install_Bulk-uploadfile' == $_REQUEST['mainwp_do'] ) {
				$allowedExtensions = array( 'zip' ); // Only zip allowed.
				// max file size in bytes.
				$sizeLimit = 2 * 1024 * 1024; // 2MB = max allowed.

				$uploader = new MainWP_QQ2_File_Uploader( $allowedExtensions, $sizeLimit );
				$path     = MainWP_System_Utility::get_mainwp_specific_dir( 'bulk' );

				$result = $uploader->handle_upload( $path, true );
				// to pass data through iframe you will need to encode all html tags.
				die( htmlspecialchars( wp_json_encode( $result ), ENT_NOQUOTES ) );
			}
		}
	}


	/**
	 * Method render_upload()
	 *
	 * Renders the upload sub part.
	 *
	 * @param string $type Plugin|Theme Type of upload.
	 */
	public static function render_upload( $type ) {
		$title             = ( 'plugin' == $type ) ? 'Plugins' : 'Themes';
		$favorites_enabled = is_plugin_active( 'mainwp-favorites-extension/mainwp-favorites-extension.php' );
		$cls               = $favorites_enabled ? 'favorites-extension-enabled ' : '';
		$cls              .= ( 'plugin' == $type ) ? 'qq-upload-plugins' : '';
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
							/**
							 * Uploader options
							 *
							 * Adds extra options to the bulk upload process as a support for the Favorites extension.
							 *
							 * @param string $type Determines if plugins or themes are being installed.
							 *
							 * @since Unknown
							 */
							$extraOptions = apply_filters( 'mainwp_uploadbulk_uploader_options', '', $type );
							$extraOptions = trim( $extraOptions );
							$extraOptions = trim( trim( $extraOptions, ',' ) );
							if ( '' != $extraOptions ) {
								echo wp_strip_all_tags( $extraOptions ) . ',';
							}
							?>
							params: {mainwp_do: 'MainWP_Install_Bulk-uploadfile'}
						} );
					}

					// in your app create uploader as soon as the DOM is ready.
					// don't wait for the window to load.
					createUploader();
				</script>
			</div>
		<?php
	}

	/**
	 * Method prepare_install()
	 *
	 * Prepair for the installation.
	 *
	 * Grab all the nesesary data to make the upload and prepair json response.
	 */
	public static function prepare_install() {
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';

		if ( ! isset( $_POST['url'] ) ) {
			if ( isset( $_POST['type'] ) && 'plugin' == $_POST['type'] ) {
				$api = plugins_api(
					'plugin_information',
					array(
						'slug'   => isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '',
						'fields' => array( 'sections' => false ),
					)
				); // Save on a bit of bandwidth.
			} else {
				$api = themes_api(
					'theme_information',
					array(
						'slug'   => isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '',
						'fields' => array( 'sections' => false ),
					)
				); // Save on a bit of bandwidth.
			}
			$url = $api->download_link;
		} else {
			$url = isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '';

			$mwpDir = MainWP_System_Utility::get_mainwp_dir();
			$mwpUrl = $mwpDir[1];
			if ( stristr( $url, $mwpUrl ) ) {
				$fullFile = $mwpDir[0] . str_replace( $mwpUrl, '', $url );
				$url      = admin_url( '?sig=' . md5( filesize( $fullFile ) ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir[0], '', $fullFile ) ) );
			}
		}

		$output          = array();
		$output['url']   = $url;
		$output['sites'] = array();

		if ( isset( $_POST['selected_by'] ) && 'site' == $_POST['selected_by'] ) {
			$selected_sites = isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_sites'] ) ) : array();
			// Get sites.
			foreach ( $selected_sites as $enc_id ) {
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
			$selected_groups = ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_groups'] ) ) : array();
			// Get sites from group.
			foreach ( $selected_groups as $enc_id ) {
				$groupid = $enc_id;
				if ( MainWP_Utility::ctype_digit( $groupid ) ) {
					$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $groupid ) );
					while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
						if ( '' != $website->sync_errors ) {
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


	/**
	 * Method addition_post_data()
	 *
	 * Grab Post addition data.
	 *
	 * @param array $post_data Data for post.
	 *
	 * @return mixed $post_data Bulk post addition data.
	 */
	public static function addition_post_data( &$post_data = array() ) {

		/**
		 * Clean and Lock extension options
		 *
		 * Adds additional options related to Clean and Lock options in order to avoid conflicts when HTTP Basic auth is set.
		 *
		 * @since Unknown
		 */
		$clear_and_lock_opts = apply_filters( 'mainwp_clear_and_lock_options', array() );
		if ( isset( $post_data['url'] ) && false !== strpos( $post_data['url'], 'mwpdl' ) && false !== strpos( $post_data['url'], 'sig' ) ) {
			if ( is_array( $clear_and_lock_opts ) && isset( $clear_and_lock_opts['wpadmin_user'] ) && ! empty( $clear_and_lock_opts['wpadmin_user'] ) && isset( $clear_and_lock_opts['wpadmin_passwd'] ) && ! empty( $clear_and_lock_opts['wpadmin_passwd'] ) ) {
				$post_data['wpadmin_user']   = $clear_and_lock_opts['wpadmin_user'];
				$post_data['wpadmin_passwd'] = $clear_and_lock_opts['wpadmin_passwd'];
			}
		}
		return $post_data;
	}

	/** Perform Install */
	public static function perform_install() {
		MainWP_Utility::end_session();

		// Fetch info.
		$post_data = array(
			'type' => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '',
		);
		if ( isset( $_POST['activatePlugin'] ) && 'true' == $_POST['activatePlugin'] ) {
			$post_data['activatePlugin'] = 'yes';
		}
		if ( isset( $_POST['overwrite'] ) && 'true' == $_POST['overwrite'] ) {
			$post_data['overwrite'] = true;
		}

		/**
		 * Addition Post Data.
		 *
		 * @param $post_data The post data.
		 * @deprecated From.
		 * @since 3.5.6.
		 */
		self::addition_post_data( $post_data );

		/**
		 * Perform insatallation additional data
		 *
		 * Adds support for additional data such as HTTP User and HTTP Password.
		 *
		 * @param array $post_data Array containg the post data.
		 *
		 * @since Unknown
		 */
		$post_data = apply_filters( 'mainwp_perform_install_data', $post_data );

		$post_data['url'] = isset( $_POST['url'] ) ? wp_json_encode( wp_unslash( $_POST['url'] ) ) : '';
		$site_id          = isset( $_POST['siteId'] ) ? intval( $_POST['siteId'] ) : 0;
		$output           = new \stdClass();
		$output->ok       = array();
		$output->errors   = array();
		$output->results  = array();
		$websites         = array( MainWP_DB::instance()->get_website_by_id( $site_id ) );

		/**
		* Action: mainwp_before_plugin_theme_install
		*
		* Fires before plugin/theme install.
		*
		* @since 4.1
		*/
		do_action( 'mainwp_before_plugin_theme_install', $post_data, $websites );

		MainWP_Connect::fetch_urls_authed(
			$websites,
			'installplugintheme',
			$post_data,
			array(
				self::get_class_name(),
				'install_plugin_theme_handler',
			),
			$output,
			null,
			array( 'upgrade' => true )
		);

		/**
		* Action: mainwp_after_plugin_theme_install
		*
		* Fires after plugin/theme install.
		*
		* @since 4.1
		*/
		do_action( 'mainwp_after_plugin_theme_install', $output, $post_data, $websites );

		wp_send_json( $output );
	}

	/**
	 * Method prepare_upload()
	 *
	 * Prepair the upload.
	 */
	public static function prepare_upload() {
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';

		$output          = array();
		$output['sites'] = array();
		if ( isset( $_POST['selected_by'] ) && 'site' == $_POST['selected_by'] ) {
			$selected_sites = isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_sites'] ) ) : array();
			// Get sites.
			foreach ( $selected_sites as $enc_id ) {
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
			$selected_groups = ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_groups'] ) ) : array();
			// Get sites from group.
			foreach ( $selected_groups as $enc_id ) {
				$groupid = $enc_id;
				if ( MainWP_Utility::ctype_digit( $groupid ) ) {
					$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $groupid ) );
					while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
						if ( '' != $website->sync_errors ) {
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
		$files          = isset( $_POST['files'] ) && is_array( $_POST['files'] ) ? wp_unslash( $_POST['files'] ) : array();
		foreach ( $files as $file ) {
			$output['urls'][] = MainWP_System_Utility::get_download_url( 'bulk', $file );
		}
		$output['urls'] = implode( '||', $output['urls'] );

		/**
		 * Prepare upload
		 *
		 * Prepares upload URLs for the bulk install process.
		 *
		 * @since Unknown
		 */
		$output['urls'] = apply_filters( 'mainwp_installbulk_prepareupload', $output['urls'] );

		wp_send_json( $output );
	}

	/**
	 * Method perform_upload()
	 *
	 * Perform the upload.
	 */
	public static function perform_upload() {
		MainWP_Utility::end_session();
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		// Fetch info.
		$post_data = array(
			'type' => $type,
		);
		if ( isset( $_POST['activatePlugin'] ) && 'true' == $_POST['activatePlugin'] ) {
			$post_data['activatePlugin'] = 'yes';
		}
		if ( isset( $_POST['overwrite'] ) && 'true' == $_POST['overwrite'] ) {
			$post_data['overwrite'] = true;
		}

		// deprecated from 3.5.6.
		self::addition_post_data( $post_data );

		/** This filter is documented in pages/page-mainwp-install-bulk.php */
		$post_data = apply_filters( 'mainwp_perform_install_data', $post_data );

		$urls             = isset( $_POST['urls'] ) ? esc_url_raw( wp_unslash( $_POST['urls'] ) ) : '';
		$post_data['url'] = wp_json_encode( explode( '||', $urls ) );
		$site_id          = isset( $_POST['siteId'] ) ? intval( $_POST['siteId'] ) : 0;

		$output          = new \stdClass();
		$output->ok      = array();
		$output->errors  = array();
		$output->results = array();
		$websites        = array( MainWP_DB::instance()->get_website_by_id( $site_id ) );

		/**
		* Action: mainwp_before_plugin_theme_install
		*
		* Fires before plugin/theme install.
		*
		* @since 4.1
		*/
		do_action( 'mainwp_before_plugin_theme_install', $post_data, $websites );

		MainWP_Connect::fetch_urls_authed(
			$websites,
			'installplugintheme',
			$post_data,
			array(
				self::get_class_name(),
				'install_plugin_theme_handler',
			),
			$output,
			null,
			array( 'upgrade' => true )
		);

		/**
		* Action: mainwp_after_plugin_theme_install
		*
		* Fires after plugin/theme install.
		*
		* @since 4.1
		*/
		do_action( 'mainwp_after_plugin_theme_install', $output, $post_data, $websites );

		wp_send_json( $output );
	}

	/**
	 * Clean the upload
	 *
	 * Do file structure mainenance and tmp file removals.
	 */
	public static function clean_upload() {
		$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
		global $wp_filesystem;

		$path = MainWP_System_Utility::get_mainwp_specific_dir( 'bulk' );
		if ( $wp_filesystem->exists( $path ) ) {
			$dh = opendir( $path );
			if ( $dh ) {
				while ( false !== ( $file = readdir( $dh ) ) ) {
					if ( '.' != $file && '..' != $file ) {
						$wp_filesystem->delete( $path . $file );
					}
				}
				closedir( $dh );
			}
		}

		die( wp_json_encode( array( 'ok' => true ) ) );
	}

	/**
	 * Plugin & Theme upload handler.
	 *
	 * @param mixed  $data Processing data.
	 * @param object $website The website object.
	 * @param mixed  $output Function output.
	 *
	 * @return mixed $output->ok[ $website->id ] = array( $website->name )|Error,
	 *  Already installed,
	 *  Undefined error! Please reinstall the MainWP Child plugin on the child site,
	 *  Error while installing.
	 */
	public static function install_plugin_theme_handler( $data, $website, &$output ) {
		if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {

			$result = $results[1];

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			$information = MainWP_System_Utility::get_child_response( base64_decode( $result ) );

			if ( isset( $information['installation'] ) && 'SUCCESS' == $information['installation'] ) {
				$output->ok[ $website->id ]      = array( $website->name );
				$output->results[ $website->id ] = isset( $information['install_results'] ) ? $information['install_results'] : array();
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
