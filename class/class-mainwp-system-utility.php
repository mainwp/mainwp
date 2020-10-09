<?php
/**
 * MainWP System Utility Helper
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- Using cURL functions.

/**
 * Class MainWP_System_Utility
 *
 * @package MainWP\Dashboard
 */
class MainWP_System_Utility {

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
		if ( 0 === $current_user->ID ) {
			return false;
		}

		if ( 10 == $current_user->wp_user_level || ( isset( $current_user->user_level ) && 10 == $current_user->user_level ) || current_user_can( 'level_10' ) ) {
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
	 * @param null $user User Email Address.
	 *
	 * @return mixed null|User Email Address.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 */
	public static function get_notification_email( $user = null ) {
		if ( null == $user ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$user = $current_user;
		}

		if ( null == $user ) {
			return null;
		}

		if ( ! ( $user instanceof \WP_User ) ) {
			return null;
		}

		$userExt = MainWP_DB_Common::instance()->get_user_extension();
		if ( '' != $userExt->user_email ) {
			return $userExt->user_email;
		}

		return $user->user_email;
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

		if ( null != $subdir && ! stristr( $subdir, '..' ) ) {
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
			} else {
				if ( ! $wp_filesystem->exists( trailingslashit( $newdir ) . '.htaccess' ) ) {
					$wp_filesystem->put_contents( trailingslashit( $newdir ) . '.htaccess', 'deny from all' );
				}
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

		return admin_url( '?sig=' . md5( filesize( $fullFile ) ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir, '', $fullFile ) ) );
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

		$dirs   = self::get_mainwp_dir();
		$newdir = $dirs[0] . $userid . ( null != $dir ? DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR : '' );

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {

			if ( ! $wp_filesystem->is_dir( $newdir ) ) {
				$wp_filesystem->mkdir( $newdir, 0777 );
			}

			if ( null != $dirs[0] . $userid && ! $wp_filesystem->exists( trailingslashit( $dirs[0] . $userid ) . '.htaccess' ) ) {
				$file_htaccess = trailingslashit( $dirs[0] . $userid ) . '.htaccess';
				$wp_filesystem->put_contents( $file_htaccess, 'deny from all' );
			}
		} else {

			if ( ! file_exists( $newdir ) ) {
				mkdir( $newdir, 0777, true );
			}

			if ( null != $dirs[0] . $userid && ! file_exists( trailingslashit( $dirs[0] . $userid ) . '.htaccess' ) ) {
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
		if ( null == $website ) {
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

		return ( $website->userid == $current_user->ID );
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
		if ( is_serialized( $data ) ) {
			$resp = unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore -- for compatability.
		} else {
			$resp = json_decode( $data, true );
		}

		if ( is_array( $resp ) ) {
			if ( isset( $resp['error'] ) ) {
				$resp['error'] = MainWP_Utility::esc_content( $resp['error'] );
			}

			if ( isset( $resp['message'] ) ) {
				$resp['message'] = MainWP_Utility::esc_content( $resp['message'] );
			}

			if ( isset( $resp['error_message'] ) ) {
				$resp['error_message'] = MainWP_Utility::esc_content( $resp['error_message'] );
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
		if ( '' == $data || is_array( $data ) ) {
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Settings::is_local_window_config()
	 */
	public static function get_openssl_conf() {

		if ( defined( 'MAINWP_CRYPT_RSA_OPENSSL_CONFIG' ) ) {
			return MAINWP_CRYPT_RSA_OPENSSL_CONFIG;
		}

		$setup_conf_loc = '';
		if ( MainWP_Settings::is_local_window_config() ) {
			$setup_conf_loc = get_option( 'mwp_setup_opensslLibLocation' );
		} elseif ( get_option( 'mainwp_opensslLibLocation' ) != '' ) {
			$setup_conf_loc = get_option( 'mainwp_opensslLibLocation' );
		}
		return $setup_conf_loc;
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

		$site_info = json_decode( MainWP_DB::instance()->get_website_option( $site, 'site_info' ), true );
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
	 * @param string $string String data.
	 * @param array  $replace_tokens array of tokens.
	 *
	 * @return string content with replaced tokens.
	 */
	public static function replace_tokens_values( $string, $replace_tokens ) {
		$tokens = array_keys( $replace_tokens );
		$values = array_values( $replace_tokens );
		return str_replace( $tokens, $values, $string );
	}
}
