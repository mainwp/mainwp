<?php
/**
 * MainWP System Utility Helper
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors, Generic.Metrics.CyclomaticComplexity -- Using cURL functions.

/**
 * Class MainWP_System_Utility
 *
 * @package MainWP\Dashboard
 */
class MainWP_System_Utility {

	/**
	 * Private static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @static
	 * @return MainWP_Post_Handler
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method is_admin()
	 *
	 * Check if current user is an administrator.
	 *
	 * @return boolean True|False.
	 */
	public static function is_admin() {

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;
		if ( empty( $current_user->ID ) ) {
			return false;
		}

		if ( ( property_exists( $current_user, 'wp_user_level' ) && 10 === (int) $current_user->wp_user_level ) || ( isset( $current_user->user_level ) && 10 === (int) $current_user->user_level ) || self::current_user_has_role( 'administrator' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Method current_user_has_role()
	 *
	 * Check if the user has role.
	 *
	 * @param array|string $roles role or array of roles to check.
	 * @param object|null  $user user check.
	 *
	 * @return bool true|false If the user is administrator (Level 10), return true, if not, return false.
	 */
	public static function current_user_has_role( $roles, $user = null ) {

		if ( null === $user ) {
			$user = wp_get_current_user();
		}

		if ( empty( $user ) || empty( $user->ID ) ) {
			return false;
		}

		if ( is_string( $roles ) ) {
			$allowed_roles = array( $roles );
		} elseif ( is_array( $roles ) ) {
			$allowed_roles = $roles;
		} else {
			return false;
		}

		if ( array_intersect( $allowed_roles, $user->roles ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Method get_primary_backup()
	 *
	 * Check if using Legacy Backup Solution.
	 *
	 * @return mixed False|$enable_legacy_backup.
	 */
	public static function get_primary_backup() {
		$enable_legacy_backup = get_option( 'mainwp_enableLegacyBackupFeature' );
		if ( ! $enable_legacy_backup ) {
			return get_option( 'mainwp_primaryBackup', false );
		}
		return false;
	}

	/**
	 * Method get_notification_email()
	 *
	 * Check if user wants to recieve MainWP Notification Emails.
	 *
	 * @return mixed null|User Email Address.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 */
	public static function get_notification_email() {
		return get_option( 'admin_email' );
	}

	/**
	 * Method get_base_dir()
	 *
	 * Get the base upload directory.
	 *
	 * @return string basedir/
	 */
	public static function get_base_dir() {
		$upload_dir = wp_upload_dir();

		return $upload_dir['basedir'] . DIRECTORY_SEPARATOR;
	}

	/**
	 * Method get_icons_dir()
	 *
	 * Get MainWP icons directory,
	 * if it doesn't exist create it.
	 *
	 * @return array $dir, $url
	 */
	public static function get_icons_dir() {
		$hasWPFileSystem = self::get_wp_file_system();

		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
		global $wp_filesystem;

		$dirs = self::get_mainwp_dir();
		$dir  = $dirs[0] . 'icons' . DIRECTORY_SEPARATOR;
		$url  = $dirs[1] . 'icons/';
		if ( ! $wp_filesystem->exists( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0777 );
		}
		if ( ! $wp_filesystem->exists( $dir . 'index.php' ) ) {
			$wp_filesystem->touch( $dir . 'index.php' );
		}
		return array( $dir, $url );
	}

	/**
	 * Method touch().
	 *
	 * If the file does not exist, it will be created.
	 *
	 * @param string $filename File name.
	 */
	public static function touch( $filename ) {
		$hasWPFileSystem = self::get_wp_file_system();
		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
		global $wp_filesystem;
		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
			if ( ! $wp_filesystem->exists( $filename ) ) {
				$wp_filesystem->touch( $filename );
			}
		} elseif ( ! file_exists( $filename ) ) { //phpcs:ignore -- ok.
			touch( $filename ); //phpcs:ignore -- ok.
		}
	}

	/**
	 * Method is_writable().
	 *
	 * @param string $file The file.
	 */
	public static function is_writable( $file ) {
		$hasWPFileSystem = self::get_wp_file_system();
		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
		global $wp_filesystem;

		$is_writable = true;
		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
			if ( ! $wp_filesystem->is_writable( $file ) ) {
				$is_writable = false;
			}
		} elseif ( ! is_writable( $file ) ) { //phpcs:ignore -- ok.
			$is_writable = false;
		}
		return $is_writable;
	}

	/**
	 * Method get_mainwp_dir()
	 *
	 * Get the MainWP directory,
	 * if it doesn't exist create it.
	 *
	 * @param string|null $subdir mainwp sub diectories.
	 * @param bool        $direct_access Return true if Direct access file system. Default: false.
	 *
	 * @return array $dir, $url
	 */
	public static function get_mainwp_dir( $subdir = null, $direct_access = false ) {
		$hasWPFileSystem = self::get_wp_file_system();

		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
		global $wp_filesystem;

		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'mainwp' . DIRECTORY_SEPARATOR;
		$url        = $upload_dir['baseurl'] . '/mainwp/';
		if ( ! $wp_filesystem->exists( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0777 );
		}
		if ( ! $wp_filesystem->exists( $dir . 'index.php' ) ) {
			$wp_filesystem->touch( $dir . 'index.php' );
		}

		if ( ! empty( $subdir ) && ! stristr( $subdir, '..' ) ) {
			$newdir = $dir . $subdir . DIRECTORY_SEPARATOR;
			$url    = $url . $subdir . '/';

			if ( ! $wp_filesystem->exists( $newdir ) ) {
				$wp_filesystem->mkdir( $newdir, 0777 );
			}

			if ( $direct_access ) {
				if ( ! $wp_filesystem->exists( trailingslashit( $newdir ) . 'index.php' ) ) {
					$wp_filesystem->touch( trailingslashit( $newdir ) . 'index.php' );
				}
				if ( $wp_filesystem->exists( trailingslashit( $newdir ) . '.htaccess' ) ) {
					$wp_filesystem->delete( trailingslashit( $newdir ) . '.htaccess' );
				}
			} elseif ( ! $wp_filesystem->exists( trailingslashit( $newdir ) . '.htaccess' ) ) {
					$wp_filesystem->put_contents( trailingslashit( $newdir ) . '.htaccess', 'deny from all' );
			}
			return array( $newdir, $url );
		}

		return array( $dir, $url );
	}

	/**
	 * Method get_mainwp_sub_dir()
	 *
	 * Get the MainWP directory,
	 * if it doesn't exist create it.
	 *
	 * @param string|null $subdir mainwp sub diectories.
	 * @param bool        $direct_access Return true if Direct access file system. Default: false.
	 *
	 * @return string $dir mainwp sub-directory.
	 */
	public static function get_mainwp_sub_dir( $subdir = null, $direct_access = false ) {
		$dirs = self::get_mainwp_dir( $subdir, $direct_access );
		return $dirs[0];
	}

	/**
	 * Method get_download_dir()
	 *
	 * @param mixed $what What url.
	 * @param mixed $filename File Name.
	 *
	 * @return string Download URL.
	 */
	public static function get_download_url( $what, $filename ) {
		$specificDir = self::get_mainwp_specific_dir( $what );
		$mwpDir      = self::get_mainwp_dir();
		$mwpDir      = $mwpDir[0];
		$fullFile    = $specificDir . $filename;

		$download_url = admin_url( '?sig=' . self::get_download_sig( $fullFile ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir, '', $fullFile ) ) );

		return $download_url;
	}


	/**
	 * Method get_download_sig()
	 *
	 * @param string $fullFile File Name.
	 *
	 * @return string Sig Download URL.
	 */
	public static function get_download_sig( $fullFile ) {
		$key_value  = uniqid( 'sig_', true ) . filesize( $fullFile ) . time();
		$sig_values = array(
			'sig'       => md5( filesize( $fullFile ) ),
			'key_value' => $key_value,
			'hash_key'  => wp_hash( $key_value ),
		);
		$sig_values = wp_json_encode( $sig_values );
		$sig_values = rawurlencode( $sig_values );
		return $sig_values;
	}


	/**
	 * Method valid_download_sig()
	 *
	 * @param string $file File Name.
	 * @param string $sig download.
	 *
	 * @return bool true|false.
	 */
	public static function valid_download_sig( $file, $sig ) {

		$sig   = rawurldecode( $sig );
		$value = json_decode( $sig, true );

		if ( ! is_array( $value ) || empty( $value['key_value'] ) || empty( $value['sig'] ) ) {
			return false;
		}

		if ( md5( filesize( $file ) ) !== $value['sig'] ) {
			return false;
		}

		$hash_key = wp_hash( $value['key_value'] );
		if ( ! hash_equals( $hash_key, $value['hash_key'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Method get_mainwp_specific_dir()
	 *
	 * Get MainWP Specific directory,
	 * if it doesn't exist create it.
	 *
	 * Update .htaccess.
	 *
	 * @param null $dir Current MainWP directory.
	 *
	 * @return string $newdir
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_single_user()
	 */
	public static function get_mainwp_specific_dir( $dir = null ) {
		if ( MainWP_System::instance()->is_single_user() ) {
			$userid = 0;
		} else {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$userid = $current_user->ID;
		}

		$hasWPFileSystem = self::get_wp_file_system();

		global $wp_filesystem;

		$dirs = self::get_mainwp_dir();

		$newdir = $dirs[0] . $userid;
		if ( '/' === $dir || null === $dir ) {
			$newdir .= DIRECTORY_SEPARATOR;
		} else {
			$newdir .= DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR;
		}

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {

			if ( ! $wp_filesystem->is_dir( $newdir ) ) {
				$wp_filesystem->mkdir( $newdir, 0777 );
			}

			if ( ! empty( $dirs[0] ) . $userid && ! $wp_filesystem->exists( trailingslashit( $dirs[0] . $userid ) . '.htaccess' ) ) {
				$file_htaccess = trailingslashit( $dirs[0] . $userid ) . '.htaccess';
				$wp_filesystem->put_contents( $file_htaccess, 'deny from all' );
			}
		} else {

			if ( ! file_exists( $newdir ) ) {
				mkdir( $newdir, 0777, true );
			}

			if ( ! empty( $dirs[0] ) . $userid && ! file_exists( trailingslashit( $dirs[0] . $userid ) . '.htaccess' ) ) {
				$file = fopen( trailingslashit( $dirs[0] . $userid ) . '.htaccess', 'w+' );
				fwrite( $file, 'deny from all' );
				fclose( $file );
			}
		}

		return $newdir;
	}

	/**
	 * Method get_mainwp_specific_url()
	 *
	 * Get MainWP specific URL.
	 *
	 * @param mixed $dir MainWP Directory.
	 *
	 * @return string MainWP URL.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_single_user()
	 */
	public static function get_mainwp_specific_url( $dir ) {
		if ( MainWP_System::instance()->is_single_user() ) {
			$userid = 0;
		} else {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$userid = $current_user->ID;
		}
		$dirs = self::get_mainwp_dir();

		return $dirs[1] . $userid . '/' . $dir . '/';
	}

	/**
	 * Method get_mainwp_dir_allow_access()
	 *
	 * Get MainWP specific sub folder allow access.
	 *
	 * @param mixed $sub_dir MainWP Sub Directory.
	 */
	public static function get_mainwp_dir_allow_access( $sub_dir ) {
		$dirs = self::get_mainwp_dir( $sub_dir, false );
		if ( $dirs ) {
			$hasWPFileSystem = self::get_wp_file_system();
			global $wp_filesystem;
			if ( $wp_filesystem ) {
				// to fix issue of do not allow access.
				$newdir  = $dirs[0];
				$content = "Order allow,deny\r\nAllow from all";
				// check if the htaccess is deny access all.
				if ( $wp_filesystem->exists( trailingslashit( $newdir ) . '.htaccess' ) ) {
					if ( $wp_filesystem->size( trailingslashit( $newdir ) . '.htaccess' ) < 25 ) { // 25 bytes: deny from all.
						// update the htaccess file to allow direct access.
						$wp_filesystem->put_contents( trailingslashit( $newdir ) . '.htaccess', $content );
					}
				} else {
					// update the htaccess file to allow direct access.
					$wp_filesystem->put_contents( trailingslashit( $newdir ) . '.htaccess', $content );
				}
			}
		}
		return $dirs;
	}


	/**
	 * Method get_wp_file_system()
	 *
	 * Get WP file system & define Global Variable FS_METHOD.
	 *
	 * @return boolean $init True.
	 */
	public static function get_wp_file_system() {

		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			ob_start();
			if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
				include_once ABSPATH . '/wp-admin/includes/screen.php';
			}
			if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
				include_once ABSPATH . '/wp-admin/includes/template.php';
			}
			include_once ABSPATH . 'wp-admin/includes/file.php';

			if ( ! function_exists( 'wp_create_nonce' ) ) {
				include_once ABSPATH . WPINC . '/pluggable.php';
			}

			$creds = request_filesystem_credentials( 'test' );
			ob_end_clean();
			if ( empty( $creds ) ) {

				/**
				 * Define WordPress File system.
				 *
				 * @const ( bool ) Default: true
				 * @source https://code-reference.mainwp.com/classes/MainWP.Dashboard.MainWP_System_Utility.html
				 */
				define( 'FS_METHOD', 'direct' );
			}
			$init = \WP_Filesystem( $creds );
		} else {
			$init = true;
		}

		return $init;
	}

	/**
	 * Method can_edit_website()
	 *
	 * Check if current user can edit Child Site.
	 *
	 * @param mixed $website Child Site.
	 *
	 * @return mixed true|false|userid
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_single_user()
	 */
	public static function can_edit_website( &$website ) {
		if ( empty( $website ) ) {
			return false;
		}

		if ( MainWP_System::instance()->is_single_user() ) {
			return true;
		}

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		return ( $website->userid === $current_user->ID );
	}

	/**
	 * Gets site tags
	 *
	 * @param array $item Array containing child site data.
	 * @param bool  $client_tag It is client tags or not.
	 *
	 * @return mixed Single Row Classes Item.
	 */
	public static function get_site_tags( $item, $client_tag = false ) {

		if ( ! is_array( $item ) || ! isset( $item['wpgroups'] ) ) {
			return '';
		}

		$href = 'admin.php?page=managesites&g=';
		if ( $client_tag ) {
			$href = 'admin.php?page=ManageClients&tags=';
		}

		$tags        = '';
		$tags_labels = '';

		if ( isset( $item['wpgroups'] ) && ! empty( $item['wpgroups'] ) ) {

			if ( $client_tag ) {
				$tags_filter = self::client_tags_filter( $item );
				$tags        = $tags_filter['wpgroups'];
				$tags_ids    = $tags_filter['wpgroupids'];
			} else {
				$tags     = $item['wpgroups'];
				$tags     = explode( ',', $tags );
				$tags_ids = $item['wpgroupids'];
				$tags_ids = explode( ',', $tags_ids );
			}

			if ( is_array( $tags ) ) {
				foreach ( $tags as $idx => $tag ) {
					$tag  = trim( $tag );
					$tagx = MainWP_DB_Common::instance()->get_group_by_name( $tag );

					if ( is_object( $tagx ) && '' !== $tagx->color ) {
						$tag_a_style = 'style="color:#fff!important;opacity:1;"';
						$tag_style   = 'style="background-color:' . esc_html( $tagx->color ) . '"';
					} else {
						$tag_a_style = '';
						$tag_style   = '';
					}

					if ( isset( $tags_ids[ $idx ] ) && ! empty( $tags_ids[ $idx ] ) ) {
						$tag_id       = $tags_ids[ $idx ];
						$tags_labels .= '<span ' . $tag_style . ' class="ui tag mini label"><a ' . $tag_a_style . ' href="' . esc_url( $href . $tag_id ) . '">' . esc_html( $tag ) . '</a></span>';
					} else {
						$tags_labels .= '<span ' . $tag_style . ' class="ui tag mini label">' . esc_html( $tag ) . '</span>';
					}
				}
			}
		}
		return $tags_labels;
	}

	/**
	 * Filter client tags
	 *
	 * @param array $item Array containing tags.
	 *
	 * @return mixed Single Row Classes Item.
	 */
	public static function client_tags_filter( $item ) {
		$tags = $item['wpgroups'];
		$tags = explode( ',', $tags );
		$tags = array_values( array_unique( $tags ) );

		$tags_ids = $item['wpgroupids'];
		$tags_ids = explode( ',', $tags_ids );
		$tags_ids = array_values( array_unique( $tags_ids ) );

		$return = array();

		$return['wpgroups']   = $tags;
		$return['wpgroupids'] = $tags_ids;
		return $return;
	}

	/**
	 * Method is_suspended_site()
	 *
	 * Check if enable site.
	 *
	 * @param mixed $website The website.
	 */
	public static function is_suspended_site( $website = false ) {
		if ( empty( $website ) ) {
			return true; // empty so return as suspended.
		}
		if ( is_array( $website ) ) {
			return ( '1' === $website['suspended'] );
		} elseif ( is_object( $website ) ) {
			if ( ! property_exists( $website, 'suspended' ) && property_exists( $website, 'id' ) ) {
				$website = MainWP_DB::instance()->get_website_by_id( $website->id );
			}
			if ( property_exists( $website, 'suspended' ) ) {
				return ( '1' === $website->suspended );
			}
		} elseif ( is_numeric( $website ) ) {
			$siteId  = $website;
			$website = MainWP_DB::instance()->get_website_by_id( $siteId );
			if ( $website ) {
				return self::is_suspended_site( $website );
			}
		}
		return false;
	}

	/**
	 * Method get_current_wpid()
	 *
	 * Get current Child Site ID.
	 *
	 * @return string $current_user->current_site_id Current Child Site ID.
	 */
	public static function get_current_wpid() {

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		return $current_user->current_site_id;
	}

	/**
	 * Method set_current_wpid()
	 *
	 * Set the current Child Site ID.
	 *
	 * @param mixed $wpid Child Site ID.
	 */
	public static function set_current_wpid( $wpid ) {

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		$current_user->current_site_id = $wpid;
	}

	/**
	 * Method get_page_id()
	 *
	 * Get current Page ID.
	 *
	 * @param null $screen Current Screen ID.
	 *
	 * @return string $page Current page ID.
	 */
	public static function get_page_id( $screen = null ) {

		if ( empty( $screen ) ) {
			$screen = get_current_screen();
		} elseif ( is_string( $screen ) ) {
			$screen = convert_to_screen( $screen );
		}

		if ( ! isset( $screen->id ) ) {
			return;
		}

		$page = $screen->id;

		return $page;
	}

	/**
	 * Method get_child_response()
	 *
	 * Get response from Child Site.
	 *
	 * @param mixed $data Data to process.
	 *
	 * @return json $data|true.
	 */
	public static function get_child_response( $data ) {
			$resp = json_decode( $data, true );

		if ( is_array( $resp ) ) {
			if ( isset( $resp['error'] ) ) {
				$resp['error'] = MainWP_Utility::esc_content( $resp['error'] );
			}

			if ( isset( $resp['message'] ) ) {
				if ( is_string( $resp['message'] ) ) {
					$resp['message'] = MainWP_Utility::esc_content( $resp['message'] );
				}
			}

			if ( isset( $resp['error_message'] ) ) {
				$resp['error_message'] = MainWP_Utility::esc_content( $resp['error_message'] );
			}

			if ( isset( $resp['notices'] ) ) {
				if ( is_string( $resp['notices'] ) ) {
					$resp['notices'] = MainWP_Utility::esc_content( $resp['notices'] );
				} elseif ( is_array( $resp['notices'] ) ) {
					$notices = array();
					foreach ( $resp['notices'] as $noti ) {
						if ( ! empty( $noti ) && is_string( $noti ) ) {
							$notices[] = MainWP_Utility::esc_content( $noti );
						}
					}
					if ( ! empty( $notices ) ) {
						$resp['notices'] = implode( ' || ', $notices );
					}
				}
			}
		}

		return $resp;
	}

	/**
	 * Method maybe_unserialyze()
	 *
	 * Check if $data is serialized,
	 * if it isn't then base64_decode it.
	 *
	 * @param mixed $data Data to check.
	 *
	 * @return mixed $data.
	 */
	public static function maybe_unserialyze( $data ) {
		if ( empty( $data ) || is_array( $data ) ) {
			return $data;
		} elseif ( is_serialized( $data ) ) {
			// phpcs:ignore -- for compatability.
			return maybe_unserialize( $data );
		} else {
			// phpcs:ignore -- for compatability.
			return maybe_unserialize( base64_decode( $data ) );
		}
	}

	/**
	 * Method get_openssl_conf()
	 *
	 * Get dashboard openssl configuration.
	 */
	public static function get_openssl_conf() {

		if ( defined( 'MAINWP_CRYPT_RSA_OPENSSL_CONFIG' ) ) {
			return MAINWP_CRYPT_RSA_OPENSSL_CONFIG;
		}
		$lib_loc = get_option( 'mainwp_opensslLibLocation' );
		return ! empty( $lib_loc ) ? $lib_loc : '';
	}

	/**
	 * Get tokens of site.
	 *
	 * @param object $site The website.
	 *
	 * @return array Array of tokens.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 */
	public static function get_tokens_site_values( $site ) {

		$tokens_values = array(
			'[site.name]' => $site->name,
			'[site.url]'  => $site->url,
		);

		$site_info = MainWP_DB::instance()->get_website_option( $site, 'site_info' );
		$site_info = ! empty( $site_info ) ? json_decode( $site_info, true ) : array();

		if ( is_array( $site_info ) ) {
			$map_site_tokens = array(
				'client.site.version' => 'wpversion',   // Displays the WP version of the child site.
				'client.site.theme'   => 'themeactivated', // Displays the currently active theme for the child site.
				'client.site.php'     => 'phpversion', // Displays the PHP version of the child site.
				'client.site.mysql'   => 'mysql_version', // Displays the MySQL version of the child site.
			);
			foreach ( $map_site_tokens as $tok => $val ) {
				$tokens_value[ '[' . $tok . ']' ] = ( is_array( $site_info ) && isset( $site_info[ $val ] ) ) ? $site_info[ $val ] : '';
			}
		}

		return $tokens_values;
	}

	/**
	 *
	 * Replace site tokens.
	 *
	 * @param string $str String data.
	 * @param array  $replace_tokens array of tokens.
	 *
	 * @return string content with replaced tokens.
	 */
	public static function replace_tokens_values( $str, $replace_tokens ) {
		$tokens = array_keys( $replace_tokens );
		$values = array_values( $replace_tokens );
		return str_replace( $tokens, $values, $str );
	}

	/**
	 *
	 * Set timeout limit.
	 *
	 * @param int $timeout timeout value.
	 */
	public static function set_time_limit( $timeout = 0 ) {
		if ( false === strpos( ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( $timeout );
		}
	}

	/**
	 *
	 * Method get_plugin_theme_info().
	 *
	 * Get WordPress plugin/theme info.
	 *
	 * @param string $what 'plugin' or 'theme'.
	 * @param array  $params Plugin/Theme info params.
	 */
	public static function get_plugin_theme_info( $what, $params = array() ) {

		if ( 'plugin' === $what ) {
			include_once ABSPATH . '/wp-admin/includes/plugin-install.php';
			return plugins_api(
				'plugin_information',
				$params
			);
		} elseif ( 'theme' === $what ) {
			include_once ABSPATH . '/wp-admin/includes/theme-install.php';
			return themes_api(
				'theme_information',
				$params
			);

		}

		return false;
	}

	/**
	 * Method update_cached_icons().
	 *
	 * Update cached icons
	 *
	 * @param string $icon The icon.
	 * @param string $slug slug.
	 * @param string $type Type: plugin|theme.
	 * @param bool   $custom_icon Custom icon or not. Default: false.
	 */
	public static function update_cached_icons( $icon, $slug, $type, $custom_icon = false ) {

		if ( 'plugin' === $type ) {
			$option_name = 'plugins_icons';
		} elseif ( 'theme' === $type ) {
			$option_name = 'themes_icons';
		} else {
			return false;
		}

		$cached_icons = MainWP_DB::instance()->get_general_option( $option_name, 'array' );

		$icon = apply_filters( 'mainwp_update_cached_icons', $icon, $slug, $type );

		if ( isset( $cached_icons[ $slug ] ) ) {
			$value = $cached_icons[ $slug ];
		} else {
			$value = array(
				'lasttime_cached' => time(),
				'path_custom'     => '',
				'path'            => '',
			);
		}

		$value['lasttime_cached'] = time();

		if ( $custom_icon ) {
			$value['path_custom'] = $icon;
		} else {
			$value['path'] = $icon;
		}

		// update cache.
		$cached_icons[ $slug ] = $value;

		MainWP_DB::instance()->update_general_option( $option_name, $cached_icons, 'array' );
		return true;
	}

	/**
	 * Private function Fetch a plugin|theme icon via API from WordPress.org
	 *
	 * @param string $slug Plugin|Theme slug.
	 * @param string $type Plugin|Theme.
	 */
	private static function fetch_wp_org_icons( $slug, $type ) {
		if ( 'plugin' === $type ) {
			$fields = array(
				'tags'          => false,
				'icons'         => true,
				'sections'      => false,
				'description'   => false,
				'tested'        => false,
				'requires'      => false,
				'rating'        => false,
				'downloaded'    => false,
				'downloadlink'  => false,
				'last_updated'  => false,
				'homepage'      => false,
				'compatibility' => false,
				'ratings'       => false,
				'added'         => false,
				'donate_link'   => false,
			);
		} elseif ( 'theme' === $type ) {
			$fields = array(
				'screenshots'      => true,
				'screenshot_count' => 5,
				'sections'         => false,
				'rating'           => false,
				'downloaded'       => false,
				'download_link'    => false,
				'last_updated'     => false,
				'tags'             => false,
				'template'         => false,
				'parent'           => false,
				'screenshot_url'   => false,
				'homepage'         => false,
			);

		} else {
			return false;
		}

		$icon = '';
		if ( 'theme' === $type ) {
			// with $fields empty to get screenshot_url of theme.
			$info = self::get_plugin_theme_info(
				$type,
				array(
					'slug'    => $slug,
					'timeout' => 60,
				)
			);
			if ( is_object( $info ) && ! empty( $info->screenshot_url ) ) {
				$icon = $info->screenshot_url;
			}
		}

		// if get screenshot_url of theme success.
		if ( ! empty( $icon ) ) {
			$option_name = 'themes_icons';
		} else {
			$info        = self::get_plugin_theme_info(
				$type,
				array(
					'slug'    => $slug,
					'fields'  => $fields,
					'timeout' => 60,
				)
			);
			$option_name = 'plugins_icons';
			$icon        = '';
			if ( 'plugin' === $type ) {
				if ( is_object( $info ) && property_exists( $info, 'icons' ) && isset( $info->icons['1x'] ) ) {
					$icon = $info->icons['1x'];
				}
			} else {
				if ( is_object( $info ) && property_exists( $info, 'screenshots' ) && isset( $info->screenshots[0] ) ) {
					$icon = $info->screenshots[0];
				}
				$option_name = 'themes_icons';
			}
		}

		$fetched_icon = '';
		if ( '' !== $icon ) {
			$fetched_icon = rawurlencode( $icon );
		}

		$cached_icons = MainWP_DB::instance()->get_general_option( $option_name, 'array' );

		if ( isset( $cached_icons[ $slug ] ) ) {
			if ( '' === $fetched_icon && ! empty( $cached_icons[ $slug ]['path'] ) ) {
				// if fetch icon empty then used caching icon.
				$fetched_icon = $cached_icons[ $slug ]['path'];
				$icon         = rawurldecode( $fetched_icon );
			}
		}

		self::update_cached_icons( $fetched_icon, $slug, $type );

		if ( '' !== $icon ) {
			return $icon;
		}
		return false;
	}

	/**
	 * Method handle_get_icon()
	 *
	 * @param string $slug Plugin slug.
	 * @param string $type Type: theme|plugin.
	 */
	public static function handle_get_icon( $slug, $type ) {
		if ( empty( $slug ) ) {
			return false;
		}
		if ( 'plugin' === $type || 'theme' === $type ) {
			return self::fetch_wp_org_icons( $slug, $type );
		}
		return '';
	}


	/**
	 * Gets a plugin icon via API from WordPress.org
	 *
	 * @param string $slug Plugin slug.
	 * @param bool   $forced_get Forced get icon, default: false.
	 */
	public static function get_plugin_icon( $slug, $forced_get = false ) {

		$icon = apply_filters( 'mainwp_get_plugin_theme_icon', '', $slug, 'plugin' );

		if ( ! empty( $icon ) ) {
			return $icon;
		}

		$forced_get = apply_filters( 'mainwp_forced_get_plugin_theme_icon', $forced_get, $slug, 'plugin' );

		if ( $forced_get ) {
			$fet_icon = self::fetch_wp_org_icons( $slug, 'plugin' );
			if ( false !== $fet_icon ) {
				$scr  = MainWP_Utility::remove_http_prefix( $fet_icon );
				$icon = '<img style="display:inline-block" class="ui mini circular image" updated-icon="true" src="' . esc_attr( $scr ) . '" />';
				return $icon;
			}
			return $icon;
		}

		// checks expired.
		$cached_icons = MainWP_DB::instance()->get_general_option( 'plugins_icons', 'array' );

		if ( ! empty( $cached_icons ) ) {
			$lasttime_clear_cached = MainWP_DB::instance()->get_general_option( 'lasttime_clear_cached_plugins_icon' );
			if ( time() > ( intval( $lasttime_clear_cached ) + MONTH_IN_SECONDS ) ) {
				$updated    = false;
				$new_cached = array();
				foreach ( $cached_icons as $sl => $val ) {
					if ( empty( $val['path_custom'] ) && time() < ( intval( $val['lasttime_cached'] ) + 12 * MONTH_IN_SECONDS ) ) {
						$new_cached[ $sl ] = $val; // unset.
						$updated           = true;
					}
				}
				if ( $updated ) {
					MainWP_DB::instance()->update_general_option( 'plugins_icons', $new_cached, 'array' );
				}
				MainWP_DB::instance()->update_general_option( 'lasttime_clear_cached_plugins_icon', time() );
			}
		}

		return self::get_plugin_theme_icon( $slug, 'plugin' );
	}

	/**
	 * Gets a theme icon via API from WordPress.org
	 *
	 * @param string $slug Theme slug.
	 * @param bool   $forced_get Forced get icon, default: false.
	 */
	public static function get_theme_icon( $slug, $forced_get = false ) {

		$icon = apply_filters( 'mainwp_get_plugin_theme_icon', '', $slug, 'theme' );

		if ( ! empty( $icon ) ) {
			return $icon;
		}

		$forced_get = apply_filters( 'mainwp_forced_get_plugin_theme_icon', $forced_get, $slug, 'theme' );

		if ( $forced_get ) {
			$fet_icon = self::fetch_wp_org_icons( $slug, 'theme' );
			if ( false !== $fet_icon ) {
				$scr  = MainWP_Utility::remove_http_prefix( $fet_icon );
				$icon = '<img style="display:inline-block" class="ui mini circular image" updated-icon="true" src="' . esc_attr( $scr ) . '" />';
			}
			return $icon;
		}

		// checks expired.
		$cached_icons = MainWP_DB::instance()->get_general_option( 'themes_icons', 'array' );

		if ( ! empty( $cached_icons ) ) {
			$lasttime_clear_cached = MainWP_DB::instance()->get_general_option( 'lasttime_clear_cached_themes_icon' );
			if ( time() > ( intval( $lasttime_clear_cached ) + MONTH_IN_SECONDS ) ) {
				$updated    = false;
				$new_cached = array();
				foreach ( $cached_icons as $sl => $val ) {
					if ( empty( $val['path_custom'] ) && time() < ( intval( $val['lasttime_cached'] ) + 12 * MONTH_IN_SECONDS ) ) {
						$new_cached[ $sl ] = $val;
						$updated           = true;
					}
				}
				if ( $updated ) {
					MainWP_DB::instance()->update_general_option( 'themes_icons', $new_cached, 'array' );
				}
				MainWP_DB::instance()->update_general_option( 'lasttime_clear_cached_themes_icon', time() );
			}
		}

		return self::get_plugin_theme_icon( $slug, 'theme' );
	}


	/**
	 * Gets a plugin|theme icon to output.
	 *
	 * @param string $slug Plugin|Theme slug.
	 * @param string $type Type icon, plugin|theme.
	 */
	private static function get_plugin_theme_icon( $slug, $type ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		if ( 'plugin' === $type ) {
			$option_name = 'plugins_icons';
		} elseif ( 'theme' === $type ) {
			$option_name = 'themes_icons';
		} else {
			return '<i style="font-size: 17px"   class="plug circular inverted icon" not-cached-path="true"></i>';
		}

		$cached_icons = MainWP_DB::instance()->get_general_option( $option_name, 'array' );

		$cached_days = apply_filters( 'mainwp_plugin_theme_icon_cache_days', 15, $slug, $type ); // default 15 days.

		$attr_slug      = ' icon-type="' . esc_attr( $type ) . '" item-slug="' . esc_attr( $slug ) . '" ';
		$cls_expired    = ' cached-icon-expired ';
		$cls_uploadable = ' cached-icon-customable ';

		$icon = '';

		if ( isset( $cached_icons[ $slug ] ) ) {
			$scr            = '';
			$is_custom_icon = false;
			if ( ! empty( $cached_icons[ $slug ]['path_custom'] ) ) {
				if ( 'plugin' === $type ) {
					$dirs = self::get_mainwp_dir( 'plugin-icons', true );
				} elseif ( 'theme' === $type ) {
					$dirs = self::get_mainwp_dir( 'theme-icons', true );
				}
				$scr            = $dirs[1] . rawurldecode( $cached_icons[ $slug ]['path_custom'] );
				$is_custom_icon = true; // custom icons will not expired.
			} elseif ( ! empty( $cached_icons[ $slug ]['path'] ) ) {
				$scr = rawurldecode( $cached_icons[ $slug ]['path'] );
				$scr = MainWP_Utility::remove_http_prefix( $scr );
			}

			$set_cached_expired = apply_filters( 'mainwp_cache_icon_expired', false, $slug, 'theme' );
			$set_expired        = false;

			if ( $set_cached_expired ) {
				if ( time() > ( intval( $cached_icons[ $slug ]['lasttime_cached'] ) + 15 * MINUTE_IN_SECONDS ) ) {
					$set_expired = true;
				}
			}

			$forced_exprided = 1700238511;
			$lasttime_cached = isset( $cached_icons[ $slug ]['lasttime_cached'] ) ? intval( $cached_icons[ $slug ]['lasttime_cached'] ) : 0;

			if ( time() > ( $lasttime_cached + $cached_days * DAY_IN_SECONDS ) || $lasttime_cached < $forced_exprided ) { // expired.
				if ( ! empty( $scr ) ) {
					$icon = '<img style="display:inline-block" class="ui mini circular image ' . ( $is_custom_icon ? $cls_uploadable : $cls_expired ) . '" ' . $attr_slug . 'src="' . esc_attr( $scr ) . '"/>'; // to update expired icon.
				} else {
					$icon = '<i style="font-size: 17px" class="plug circular inverted icon ' . $cls_expired . $cls_uploadable . '" ' . $attr_slug . '></i>'; // to update expired icon.
				}
			} elseif ( ! empty( $scr ) ) {
				$icon = '<img style="display:inline-block" class="ui mini circular image ' . ( $is_custom_icon ? $cls_uploadable : ( $set_expired ? $cls_expired : '' ) ) . '" ' . $attr_slug . ' cached-path-icon="true" src="' . esc_attr( $scr ) . '"/>';
			} else {
				$icon = '<i style="font-size: 17px" class="plug circular inverted icon ' . ( $set_expired ? $cls_expired : '' ) . $cls_uploadable . '" ' . $attr_slug . ' cached-path-icon="true"></i>';
			}
		} elseif ( empty( $icon ) ) {
			$icon = '<i style="font-size: 17px" class="plug circular inverted icon ' . $cls_expired . '" ' . $attr_slug . ' not-cached-path="true"></i>'; // not upload when not existed in the cached.
		}
		return $icon;
	}


	/**
	 * Method handle_upload_image().
	 *
	 * Handle upload icons.
	 *
	 * @param string $sub_folder The sub folder.
	 * @param mixed  $file_uploader The file uploader.
	 * @param mixed  $file_index The index of file uploader.
	 * @param bool   $file_subindex Is file with sub index.
	 * @param int    $max_width max image width.
	 * @param int    $max_height max image height.
	 */
	public static function handle_upload_image( $sub_folder, $file_uploader, $file_index, $file_subindex = false, $max_width = 300, $max_height = 300 ) { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$dirs     = self::get_mainwp_dir( $sub_folder, true );
		$base_dir = $dirs[0];
		$base_url = $dirs[1];

		$output   = array();
		$filename = '';
		$filepath = '';

		$file_types = array(
			'image/jpeg',
			'image/jpg',
			'image/gif',
			'image/x-icon',
			'image/png',
		);

		$file_exts = array(
			'jpeg',
			'jpg',
			'gif',
			'ico',
			'png',
		);

		$upload_ok = ( false === $file_subindex ) ? ( UPLOAD_ERR_OK === $file_uploader['error'][ $file_index ] ) : ( UPLOAD_ERR_OK === $file_uploader['error'][ $file_index ][ $file_subindex ] );

		if ( $upload_ok ) {
			$tmp_file = ( false === $file_subindex ) ? ( $file_uploader['tmp_name'][ $file_index ] ) : ( $file_uploader['tmp_name'][ $file_index ][ $file_subindex ] );

			if ( is_uploaded_file( $tmp_file ) ) {
				if ( false === $file_subindex ) {
					$file_size = $file_uploader['size'][ $file_index ];
					$file_type = $file_uploader['type'][ $file_index ];
					$file_name = $file_uploader['name'][ $file_index ];
				} else {
					$file_size = $file_uploader['size'][ $file_index ][ $file_subindex ];
					$file_type = $file_uploader['type'][ $file_index ][ $file_subindex ];
					$file_name = $file_uploader['name'][ $file_index ][ $file_subindex ];
				}

				$file_extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

				if ( ( $file_size > 500 * 1025 ) ) {
					$output['error'][] = 3;
				} elseif ( ! in_array( $file_type, $file_types ) ) {
					$output['error'][] = 4;
				} elseif ( ! in_array( $file_extension, $file_exts ) ) {
					$output['error'][] = 5;
				} else {

					$dest_file = $base_dir . '/' . $file_name;
					$dest_file = dirname( $dest_file ) . '/' . wp_unique_filename( dirname( $dest_file ), basename( $dest_file ) );

					if ( move_uploaded_file( $tmp_file, $dest_file ) ) {
						if ( file_exists( $dest_file ) ) {
							list( $width, $height, $type, $attr ) = getimagesize( $dest_file );
						}

						$resize = false;
						if ( $width > $max_width ) {
							$dst_width = $max_width;
							if ( $height > $max_height ) {
								$dst_height = $max_height;
							} else {
								$dst_height = $height;
							}
							$resize = true;
						} elseif ( $height > $max_height ) {
							$dst_width  = $width;
							$dst_height = $max_height;
							$resize     = true;
						}

						if ( $resize ) {
							$src          = $dest_file;
							$cropped_file = wp_crop_image( $src, 0, 0, $width, $height, $dst_width, $dst_height, false );
							if ( ! $cropped_file || is_wp_error( $cropped_file ) ) {
								$output['error'][] = 9;
							} else {
								wp_delete_file( $dest_file );
								$filename = basename( $cropped_file );
								$filepath = $cropped_file;
							}
						} else {
							$filename = basename( $dest_file );
							$filepath = $dest_file;
						}
					} else {
						$output['error'][] = 6;
					}
				}
			}
		}
		$output['fileurl']  = ! empty( $filename ) ? $base_url . '/' . $filename : '';
		$output['filepath'] = ! empty( $filepath ) ? $filepath : '';
		$output['filename'] = ! empty( $filename ) ? $filename : '';

		return $output;
	}

	/**
	 * Method disabled_wpcore_update_by().
	 *
	 * Get disabled wpcore update by.
	 *
	 * @param string $website The website.
	 */
	public static function disabled_wpcore_update_by( $website ) {
		$by = self::get_disabled_wpcore_update_host( $website );
		if ( 'flywheel' === $by ) {
			return esc_html__( 'FlyWheel disables WP core updates. For more information contact FlyWheel support.', 'mainwp' );
		} elseif ( 'pressable' === $by ) {
			return esc_html__( 'Pressable disables WP core updates. For more information contact Pressable support.', 'mainwp' );
		}
		return '';
	}


	/**
	 * Method get_disabled_wpcore_update_host().
	 *
	 * Get wpcore update disabled for the websites on FlyWheel host or Pressable host.
	 *
	 * @param mixed $website data.
	 */
	public static function get_disabled_wpcore_update_host( $website ) {
		if ( empty( $website ) ) {
			return '';
		}
		$wphost = MainWP_DB::instance()->get_website_option( $website, 'wphost' );
		if ( ! empty( $wphost ) && ( 'flywheel' !== $wphost && 'pressable' !== $wphost ) ) {
			$wphost = '';
		}
		return empty( $wphost ) ? '' : $wphost;
	}


	/**
	 * Method get_connect_sign_algorithm().
	 *
	 * Get supported sign algorithms.
	 *
	 * @param mixed $website The Website object.
	 *
	 * @return mixed $alg Algorithm connect.
	 */
	public static function get_connect_sign_algorithm( $website ) {
		$alg = is_object( $website ) && property_exists( $website, 'signature_algo' ) && ! empty( $website->signature_algo ) ? $website->signature_algo : false;

		// to fix.
		if ( is_numeric( $alg ) ) {
			$alg = intval( $alg );
		}

		$default_alg = false;
		if ( defined( 'OPENSSL_ALGO_SHA256' ) ) {
			$default_alg = OPENSSL_ALGO_SHA256;
		}

		if ( ! empty( $alg ) ) {
			if ( 9999 === $alg ) {
				$alg = get_option( 'mainwp_connect_signature_algo', $default_alg );
				// to fix.
				if ( is_numeric( $alg ) ) {
					$alg = intval( $alg );
				}
			}
		}

		if ( empty( $alg ) ) {
			$site_info = MainWP_DB::instance()->get_website_option( $website, 'site_info' );
			$site_info = ! empty( $site_info ) ? json_decode( $site_info, true ) : array();
			if ( is_array( $site_info ) && ! empty( $site_info['child_version'] ) ) {
				if ( version_compare( $site_info['child_version'], '4.5', '>=' ) ) {
					$alg = $default_alg;
				}
			}
		}

		if ( ! self::is_valid_supported_sign_alg( $alg ) ) {
			$alg = false;
		}

		$alg = apply_filters( 'mainwp_connect_sign_algo', $alg, $website );

		return $alg;
	}

	/**
	 * Method is_valid_supported_sign_alg()
	 *
	 * Check if is supported sign algorithms.
	 *
	 * @param int $alg The Sign Algo value.
	 */
	public static function is_valid_supported_sign_alg( $alg ) {
		$valid = false;
		if ( defined( 'OPENSSL_ALGO_SHA1' ) && OPENSSL_ALGO_SHA1 === $alg ) {
			$valid = true;
		} elseif ( defined( 'OPENSSL_ALGO_SHA224' ) && OPENSSL_ALGO_SHA224 === $alg ) {
			$valid = true;
		} elseif ( defined( 'OPENSSL_ALGO_SHA256' ) && OPENSSL_ALGO_SHA256 === $alg ) {
			$valid = true;
		} elseif ( defined( 'OPENSSL_ALGO_SHA384' ) && OPENSSL_ALGO_SHA384 === $alg ) {
			$valid = true;
		} elseif ( defined( 'OPENSSL_ALGO_SHA512' ) && OPENSSL_ALGO_SHA512 === $alg ) {
			$valid = true;
		}
		return $valid;
	}

	/**
	 * Method get_signature_alg()
	 *
	 * Get custom signature algorithms.
	 */
	public static function get_open_ssl_sign_algos() {
		$values = array();

		if ( defined( 'OPENSSL_ALGO_SHA1' ) ) {
			$values[ OPENSSL_ALGO_SHA1 ] = 'OPENSSL_ALGO_SHA1';
		}
		if ( defined( 'OPENSSL_ALGO_SHA224' ) ) {
			$values[ OPENSSL_ALGO_SHA224 ] = 'OPENSSL_ALGO_SHA224';
		}

		if ( defined( 'OPENSSL_ALGO_SHA256' ) ) {
			$values[ OPENSSL_ALGO_SHA256 ] = 'OPENSSL_ALGO_SHA256 ' . esc_html__( '(Default)', 'mainwp' );
		}

		if ( defined( 'OPENSSL_ALGO_SHA384' ) ) {
			$values[ OPENSSL_ALGO_SHA384 ] = 'OPENSSL_ALGO_SHA384';
		}

		if ( defined( 'OPENSSL_ALGO_SHA512' ) ) {
			$values[ OPENSSL_ALGO_SHA512 ] = 'OPENSSL_ALGO_SHA512';
		}

		return $values;
	}

	/**
	 * Method get_default_map_site_fields()
	 *
	 * Get default map site fields.
	 */
	public static function get_default_map_site_fields() {
		$data_fields = array(
			'id',
			'url',
			'name',
			'adminname',
			'privkey',
			'http_user',
			'http_pass',
			'ssl_version',
			'sync_errors',
			'signature_algo',
			'verify_method',
		);
		return $data_fields;
	}

	/**
	 * Method get_staging_options_sites_view_for_current_users()
	 *
	 * Get staging options sites view for current users.
	 *
	 * @return string Site views.
	 */
	public static function get_staging_options_sites_view_for_current_users() {
		$view = apply_filters( 'mainwp_staging_current_user_sites_view', 'undefined' );
		if ( 'undefined' === $view ) { // to compatible.
			$view = get_user_option( 'mainwp_staging_options_updates_view' );
		}
		return $view;
	}
}
