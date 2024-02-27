<?php
/**
 * MainWP Server Information Page Handler
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP Server_Information_Handler
 */
class MainWP_Server_Information_Handler {

	// phpcs:disable WordPress.WP.AlternativeFunctions -- use system functions.

	/**
	 * Gets Class Name.
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Gets current MainWP Plugin version.
	 *
	 * @return mixed $currentVersion verion number/
	 */
	public static function get_current_version() {
		$currentVersion = get_option( 'mainwp_plugin_version' );
		return $currentVersion;
	}

	/**
	 * Gets current MainWP Dashboard version.
	 *
	 * @return mixed false|version
	 */
	public static function get_mainwp_version() {

		if ( isset( $_SESSION['cachedVersion'] ) && isset( $_SESSION['cachedTime'] ) && ( null !== $_SESSION['cachedVersion'] ) && ( ( $_SESSION['cachedTime'] + ( 60 * 30 ) ) > time() ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
			return $_SESSION['cachedVersion']; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
		}
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';
		$api = MainWP_System_Utility::get_plugin_theme_info(
			'plugin',
			array(
				'slug'    => 'mainwp',
				'fields'  => array( 'sections' => false ),
				'timeout' => 60,
			)
		);
		if ( is_object( $api ) && isset( $api->version ) ) {
			$_SESSION['cachedTime']    = time();
			$_SESSION['cachedVersion'] = $api->version; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
			return $_SESSION['cachedVersion']; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
		}
		return false;
	}

	/**
	 * Checks if WP environment is Mutisilte or not.
	 *
	 * @return bool true|false.
	 */
	public static function check_if_multisite() {
		$isMultisite = ! is_multisite() ? true : false;
		return $isMultisite;
	}

	/**
	 * Compares filesize.
	 *
	 * @param mixed $value1   First file size.
	 * @param mixed $value2   Second file size.
	 * @param null  $operator Comparison operator.
	 *
	 * @return mixed version_compare() value.
	 */
	public static function filesize_compare( $value1, $value2, $operator = null ) {
		if ( false !== strpos( $value1, 'G' ) ) {
			$value1 = preg_replace( '/[A-Za-z]/', '', $value1 );
			$value1 = intval( $value1 ) * 1024;
		} else {
			$value1 = preg_replace( '/[A-Za-z]/', '', $value1 );
		}

		if ( false !== strpos( $value2, 'G' ) ) {
			$value2 = preg_replace( '/[A-Za-z]/', '', $value2 );
			$value2 = intval( $value2 ) * 1024;
		} else {
			$value2 = preg_replace( '/[A-Za-z]/', '', $value2 );
		}

		return version_compare( $value1, $value2, $operator );
	}

	/**
	 * Compares cURL SSL Version.
	 *
	 * @param mixed $version CURL SSL version number.
	 * @param null  $operator Comparison operator.
	 *
	 * @return mixed false|version
	 */
	public static function curlssl_compare( $version, $operator ) {
		if ( function_exists( 'curl_version' ) ) {
			$ver = self::get_curl_ssl_version();
			return version_compare( $ver, $version, $operator );
		}
		return false;
	}

	/**
	 * Gets file system method.
	 *
	 * @return string $fs File System Method.
	 */
	public static function get_file_system_method() {
		$fs = get_filesystem_method();

		return $fs;
	}

	/**
	 * Gets loaded PHP Extensions.
	 */
	public static function get_loaded_php_extensions() {
		$extensions = get_loaded_extensions();
		sort( $extensions );
		echo esc_html( implode( ', ', $extensions ) );
	}

	/**
	 * Gets the WP_MEMORY_LIMIT value.
	 *
	 * @return mixed WP_MEMORY_LIMIT WordPress Memmory Limit.
	 */
	public static function get_wordpress_memory_limit() {
		return WP_MEMORY_LIMIT;
	}

	/**
	 * Method get_curl_version()
	 *
	 * Get current cURL Version.
	 *
	 * @return mixed $curlversion['version'] Currently installed cURL Version.
	 */
	public static function get_curl_version() {
		$curlversion = curl_version();

		return $curlversion['version'];
	}

	/**
	 * Method get_curl_ssl_version()
	 *
	 * Get current SSL Version installed.
	 *
	 * @return mixed $curlversion['ssl_version'] Currently installed SSL version.
	 */
	public static function get_curl_ssl_version() {
		$curlversion = curl_version();

		return $curlversion['ssl_version'];
	}

	/**
	 * Method get_wordpress_version()
	 *
	 * Get current WordPress Version
	 *
	 * @return mixed $wp_version Current installed WordPress version
	 */
	public static function get_wordpress_version() {

		/**
		 * WordPress version.
		 *
		 * @global string
		 */
		global $wp_version;

		return $wp_version;
	}

	/**
	 * Method get_ssl_support()
	 *
	 * Get SSL Support.
	 *
	 * @return mixed Open SSL Extentions loaded.
	 */
	public static function get_ssl_support() {
		return extension_loaded( 'openssl' );
	}

	/**
	 * Method get_ssl_warning()
	 *
	 * Get any SSL Warning Messages.
	 *
	 * @return string SSL Error message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_openssl_conf()
	 */
	public static function get_ssl_warning() {
		$conf     = array( 'private_key_bits' => 2048 );
		$conf_loc = MainWP_System_Utility::get_openssl_conf();
		if ( ! empty( $conf_loc ) ) {
			$conf['config'] = $conf_loc;
		}
		$errors = array();

		$general_verify_con = (int) get_option( 'mainwp_verify_connection_method', 0 );

		if ( 2 !== $general_verify_con ) {
			if ( function_exists( 'openssl_pkey_new' ) ) {
				$res = openssl_pkey_new( $conf );
				openssl_pkey_export( $res, $privkey, null, $conf );

				$error = '';
				while ( ( $errorRow = openssl_error_string() ) !== false ) {
					$error = $errorRow . "\n" . $error;
				}
				if ( ! empty( $error ) ) {
					$errors[] = $error;
				}
			}
		}

		return empty( $errors ) ? '' : implode( ' - ', $errors );
	}

	/**
	 * To verify openssl working.
	 *
	 * @return bool Working status.
	 */
	public static function get_openssl_working_status() {

		$ok                 = false;
		$general_verify_con = (int) get_option( 'mainwp_verify_connection_method', 0 );
		if ( 2 === $general_verify_con ) {
			$ok = 1;
		} elseif ( function_exists( 'openssl_verify' ) && function_exists( 'openssl_pkey_new' ) ) {

			$conf = array(
				'private_key_bits' => 2048,
			);

			$conf_loc = MainWP_System_Utility::get_openssl_conf();
			if ( ! empty( $conf_loc ) ) {
				$conf['config'] = $conf_loc;
			}

			$res = openssl_pkey_new( $conf );

			@openssl_pkey_export( $res, $privkey, null, $conf ); // phpcs:ignore -- prevent warning.
			$details = openssl_pkey_get_details( $res );

			if ( is_array( $details ) && isset( $details['key'] ) ) {
				$publicKey = $details['key'];
				$data      = 'working status';
				openssl_sign( $data, $signature, $privkey ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
				if ( ! empty( $signature ) ) {
					$ok = openssl_verify( $data, $signature, $publicKey );
				}
			}
		}

		return 1 === $ok;
	}

	/**
	 * Method is_openssl_config_warning()
	 *
	 * Check if open ssl is configured correctly.
	 *
	 * @return boolean true|false.
	 */
	public static function is_openssl_config_warning() {
		$ssl_warning = self::get_ssl_warning();
		if ( '' !== $ssl_warning ) {
			if ( false !== stristr( $ssl_warning, 'No such file or directory found', 'mainwp' ) || false !== stristr( $ssl_warning, 'No such process', 'mainwp' ) || false !== stristr( $ssl_warning, 'no such file', 'mainwp' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Method get_curl_support()
	 *
	 * Get cURL Version.
	 *
	 * @return string cURL Version.
	 */
	public static function get_curl_support() {
		return function_exists( 'curl_version' );
	}

	/**
	 *  Method get_curl_timeout()
	 *
	 * Get cURL_Timeout value.
	 *
	 * @return string cURL_Timeout value.
	 */
	public static function get_curl_timeout() {
		return ini_get( 'default_socket_timeout' );
	}

	/**
	 *  Method get_php_version()
	 *
	 * Get PHP Version.
	 *
	 * @return string phpversion().
	 */
	public static function get_php_version() {
		return phpversion();
	}

	/**
	 * Method get_max_execution_time()
	 *
	 * Get MAX_EXECUTION_TIME.
	 *
	 * @return string MAX_EXECUTION_TIME.
	 */
	public static function get_max_execution_time() {
		return ini_get( 'max_execution_time' );
	}

	/**
	 * Method get_max_execution_time()
	 *
	 * Get MAX_INPUT_TIME.
	 *
	 * @return string MAX_EXECUTION_TIME.
	 */
	public static function get_max_input_time() {
		return ini_get( 'max_input_time' );
	}

	/**
	 * Method get_max_execution_time()
	 *
	 * Get MAX_UPLOAD_FILESIZE.
	 *
	 * @return string MAX_UPLOAD_FILESIZE.
	 */
	public static function get_upload_max_filesize() {
		return ini_get( 'upload_max_filesize' );
	}

	/**
	 * Method get_max_execution_time()
	 *
	 * Get MAX_POST_SIZE.
	 *
	 * @return string MAX_POST_SIZE.
	 */
	public static function get_post_max_size() {
		return ini_get( 'post_max_size' );
	}

	/**
	 * Method get_mysql_version()
	 *
	 * Get MySql Version.
	 *
	 * @return string MySQL Version.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_my_sql_version()
	 */
	public static function get_mysql_version() {
		return MainWP_DB::instance()->get_my_sql_version();
	}

	/**
	 * Method get_php_memory_limit()
	 *
	 * Get PHP_MEMORY_LIMIT.
	 *
	 * @return string PHP_MEMORY_LIMIT.
	 */
	public static function get_php_memory_limit() {
		return ini_get( 'memory_limit' );
	}

	/**
	 * Method get_os()
	 *
	 * Get Host OS.
	 *
	 * @param bool $return_value = false.
	 *
	 * @return mixed PHP_OS.
	 */
	public static function get_os( $return_value = false ) {
		if ( $return_value ) {
			return PHP_OS;
		} else {
			echo esc_html( PHP_OS );
		}
	}

	/**
	 * Method get_architecture()
	 *
	 * Get PHP_INT_SIZE * 8bit.
	 */
	public static function get_architecture() {
		echo( esc_html( PHP_INT_SIZE * 8 ) )
		?>
		&nbsp;bit
		<?php
	}

	/**
	 * Method memory_usage()
	 *
	 * Get currently used memory.
	 */
	public static function memory_usage() {
		if ( function_exists( 'memory_get_usage' ) ) {
			$memory_usage = round( memory_get_usage() / 1024 / 1024, 2 ) . ' MB';
		} else {
			$memory_usage = 'N/A';
		}
		echo esc_html( $memory_usage );
	}

	/**
	 * Method get_output_buffer_size()
	 *
	 * Get putput buffer size.
	 *
	 * @return string Current output buffer Size.
	 */
	public static function get_output_buffer_size() {
		return ini_get( 'pcre.backtrack_limit' );
	}

	/**
	 * Method get_php_safe_mode()
	 *
	 * Get PHP Safe Mode.
	 *
	 * @return bool true|false.
	 */
	public static function get_php_safe_mode() {
		if ( version_compare( self::get_php_version(), '5.3.0' ) >= 0 ) {
			return true;
		}

		if ( ini_get( 'safe_mode' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Method get_sql_mode()
	 *
	 * Get SQL Mode.
	 */
	public static function get_sql_mode() {

		/**
		 * WordPress database instance.
		 *
		 * @global object
		 */
		global $wpdb;

		$sql_mode  = '';
		$mysqlinfo = $wpdb->get_results( "SHOW VARIABLES LIKE 'sql_mode'" ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- ok.
		if ( is_array( $mysqlinfo ) ) {
			$sql_mode = $mysqlinfo[0]->Value;
		}
		if ( empty( $sql_mode ) ) {
			$sql_mode = esc_html__( 'NOT SET', 'mainwp' );
		}
		echo esc_html( $sql_mode );
	}

	/**
	 * Checks if PHP url fopen is allowed.
	 */
	public static function get_php_allow_url_fopen() {
		$allow = ini_get( 'allow_url_fopen' );
		if ( is_numeric( $allow ) ) {
			$allow = intval( $allow );
			if ( $allow ) {
				esc_html_e( 'YES', 'mainwp' );
			} else {
				esc_html_e( 'NO', 'mainwp' );
			}
		} elseif ( is_string( $allow ) ) {
			echo esc_html( $allow );
		}
	}

	/**
	 * Method get_php_exif()
	 *
	 * Check if PHP Exif is enabled.
	 */
	public static function get_php_exif() {
		if ( is_callable( 'exif_read_data' ) ) {
			esc_html_e( 'YES', 'mainwp' ) . ' ( V' . esc_html( substr( phpversion( 'exif' ), 0, 4 ) ) . ')';
		} else {
			esc_html_e( 'NO', 'mainwp' );
		}
	}

	/**
	 * Method get_php_iptc()
	 *
	 * Check if iptcparse is enabled.
	 */
	public static function get_php_iptc() {
		if ( is_callable( 'iptcparse' ) ) {
			esc_html_e( 'YES', 'mainwp' );
		} else {
			esc_html_e( 'NO', 'mainwp' );
		}
	}

	/**
	 * Method get_php_xml()
	 *
	 * Check if PHP XML Parser is enabled.
	 */
	public static function get_php_xml() {
		if ( is_callable( 'xml_parser_create' ) ) {
			esc_html_e( 'YES', 'mainwp' );
		} else {
			esc_html_e( 'NO', 'mainwp' );
		}
	}

	/**
	 * Method get_server_gateway_interface()
	 *
	 * Get server gateway interface.
	 */
	public static function get_server_gateway_interface() {
		echo isset( $_SERVER['GATEWAY_INTERFACE'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['GATEWAY_INTERFACE'] ) ) ) : '';
	}

	/**
	 * Method  get_server_ip()
	 *
	 * Get server IP address.
	 */
	public static function get_server_ip() {
		echo isset( $_SERVER['SERVER_ADDR'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) ) : '';
	}

	/**
	 * Method get_server_name()
	 *
	 * Get server name.
	 *
	 * @param bool $return_value Return or not.
	 *
	 * @return string $_SERVER['SERVER_NAME'].
	 */
	public static function get_server_name( $return_value = false ) {
		if ( $return_value ) {
			return isset( $_SERVER['SERVER_NAME'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) ) : '';
		} else {
			echo isset( $_SERVER['SERVER_NAME'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) ) : '';
		}
	}

	/**
	 * Method get_server_software()
	 *
	 * Get server software.
	 *
	 * @param bool $return_value Return or not.
	 *
	 * @return string $_SERVER['SERVER_SOFTWARE'].
	 */
	public static function get_server_software( $return_value = false ) {
		if ( $return_value ) {
			return isset( $_SERVER['SERVER_SOFTWARE'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		} else {
			echo isset( $_SERVER['SERVER_SOFTWARE'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		}
	}

	/**
	 * Method is_apache_server_software()
	 *
	 * Check if server software is apache.
	 *
	 * @return bool True|false.
	 */
	public static function is_apache_server_software() {
		$server = self::get_server_software( true );
		return ( false !== stripos( $server, 'apache' ) ) ? true : false;
	}

	/**
	 * Gets server protocol.
	 */
	public static function get_server_protocol() {
		echo isset( $_SERVER['SERVER_PROTOCOL'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ) ) : '';
	}

	/**
	 * Gets server request time.
	 */
	public static function get_server_request_time() {
		echo isset( $_SERVER['REQUEST_TIME'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_TIME'] ) ) ) : '';
	}


	/**
	 * Gets server HTTP accept.
	 */
	public static function get_server_http_accept() {
		echo isset( $_SERVER['HTTP_ACCEPT'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) ) : '';
	}

	/**
	 * Gets server accepted character set.
	 */
	public static function get_server_accept_charset() {
		// phpcs:disable WordPress.Security.EscapeOutput
		echo ( ! isset( $_SERVER['HTTP_ACCEPT_CHARSET'] ) || empty( $_SERVER['HTTP_ACCEPT_CHARSET'] ) ) ? esc_html__( 'N/A', 'mainwp' ) : esc_html( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_CHARSET'] ) ) );
		// phpcs:enable
	}

	/**
	 * Gets HTTP host.
	 */
	public static function get_http_host() {
		echo isset( $_SERVER['HTTP_HOST'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
	}

	/**
	 * Gets complete URL.
	 */
	public static function get_complete_url() {
		echo isset( $_SERVER['HTTP_REFERER'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) ) : '';
	}

	/**
	 * Gets user agent.
	 */
	public static function get_user_agent() {
		echo isset( $_SERVER['HTTP_USER_AGENT'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
	}

	/**
	 * Checks if HTTPS is enabled.
	 */
	public static function get_https() {
		if ( isset( $_SERVER['HTTPS'] ) && '' !== $_SERVER['HTTPS'] ) {
			esc_html_e( 'ON', 'mainwp' ) . ' - ' . esc_html( sanitize_text_field( wp_unslash( $_SERVER['HTTPS'] ) ) );
		} else {
			esc_html_e( 'OFF', 'mainwp' );
		}
	}

	/**
	 * Method server_self_connect()
	 *
	 * Check if server self-connect is possible.
	 */
	public static function server_self_connect() {
		$url        = site_url( 'wp-cron.php' );
		$query_args = array( 'mainwp_run' => 'test' );
		$url        = esc_url_raw( add_query_arg( $query_args, $url ) );

		/**
		 * Filter: https_local_ssl_verify
		 *
		 * Filters whether the server-self check shoul verify SSL Cert.
		 *
		 * @since Unknown
		 */
		$args        = array(
			'blocking'  => true,
			'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
			'timeout'   => 15,
		);
		$response    = wp_remote_post( $url, $args );
		$test_result = '';
		if ( is_wp_error( $response ) ) {
			$test_result .= sprintf( esc_html__( 'The HTTP response test get an error "%s"', 'mainwp' ), $response->get_error_message() );
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 > $response_code && 204 < $response_code ) {
			$test_result .= sprintf( esc_html__( 'The HTTP response test get a false http status (%s)', 'mainwp' ), wp_remote_retrieve_response_code( $response ) );
		} else {
			$response_body = wp_remote_retrieve_body( $response );
			if ( false === strstr( $response_body, 'MainWP Test' ) ) {
				$test_result .= sprintf( esc_html__( 'Not expected HTTP response body: %s', 'mainwp' ), esc_attr( wp_strip_all_tags( $response_body ) ) );
			}
		}
		if ( empty( $test_result ) ) {
			esc_html_e( 'Response Test O.K.', 'mainwp' );
		} else {
			echo esc_html( $test_result );
		}
	}

	/**
	 * Gets server remote address.
	 */
	public static function get_remote_address() {
		echo isset( $_SERVER['REMOTE_ADDR'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
	}

	/**
	 * Gets server remote host.
	 */
	public static function get_remote_host() {
		if ( ! isset( $_SERVER['REMOTE_HOST'] ) || ( '' === $_SERVER['REMOTE_HOST'] ) ) {
			esc_html_e( 'N/A', 'mainwp' );
		} else {
			echo isset( $_SERVER['REMOTE_HOST'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_HOST'] ) ) ) : '';
		}
	}

	/**
	 * Gets server remote port.
	 */
	public static function get_remote_port() {
		echo isset( $_SERVER['REMOTE_PORT'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_PORT'] ) ) ) : '';
	}

	/**
	 * Gets server script filename.
	 */
	public static function get_script_file_name() {
		echo isset( $_SERVER['SCRIPT_FILENAME'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) ) : '';
	}

	/**
	 * Method get_server_port()
	 *
	 * Get server port.
	 */
	public static function get_server_port() {
		echo isset( $_SERVER['SERVER_PORT'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_PORT'] ) ) ) : '';
	}

	/**
	 * Method get_current_page_uri()
	 *
	 * Get current page URI.
	 */
	public static function get_current_page_uri() {
		echo isset( $_SERVER['REQUEST_URI'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '';
	}

	/**
	 * Method get_wp_root()
	 *
	 * Get WP Root Path.
	 */
	public static function get_wp_root() {
		echo esc_html( ABSPATH );
	}

	/**
	 * Compares time.
	 *
	 * @param mixed $a first time to compare.
	 * @param mixed $b second time to compare.
	 */
	public static function time_compare( $a, $b ) {

		if ( $a === $b ) {
			return 0;
		}

		return ( strtotime( $a['time'] ) > strtotime( $b['time'] ) ) ? - 1 : 1;
	}

	/**
	 * Gets line count.
	 *
	 * @param mixed $path the error log file location.
	 * @param int   $line_count number of lines in the error log.
	 * @param int   $block_size block size.
	 *
	 * @return string Line Count.
	 */
	public static function last_lines( $path, $line_count, $block_size = 512 ) {
		//phpcs:disable WordPress.WP.AlternativeFunctions
		$lines    = array();
		$leftover = '';
		$fh       = fopen( $path, 'r' );

		fseek( $fh, 0, SEEK_END );

		do {
			$can_read = $block_size;

			if ( ftell( $fh ) <= $block_size ) {
				$can_read = ftell( $fh );
			}

			if ( empty( $can_read ) ) {
				break;
			}

			fseek( $fh, - $can_read, SEEK_CUR );
			$data  = fread( $fh, $can_read );
			$data .= $leftover;
			fseek( $fh, - $can_read, SEEK_CUR );

			$split_data = array_reverse( explode( "\n", $data ) );
			$new_lines  = array_slice( $split_data, 0, - 1 );
			$lines      = array_merge( $lines, $new_lines );
			$leftover   = $split_data[ count( $split_data ) - 1 ];
			$count      = count( $lines );
		} while ( $count < $line_count && ! empty( ftell( $fh ) ) );

		if ( empty( ftell( $fh ) ) ) {
			$lines[] = $leftover;
		}

		fclose( $fh );
		//phpcs:enable

		return array_slice( $lines, 0, $line_count );
	}

	/**
	 * Gets MainWP Set Options.
	 *
	 * @return array $options_value MainWP Options array.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_websites_count()
	 */
	public static function mainwp_options() { // phpcs:ignore -- current complexity required to achieve desired results. Pull request solutions appreaciated.
		$mainwp_options = array(
			'mainwp_number_of_child_sites'           => esc_html__( 'Number of connected sites', 'mainwp' ),
			'mainwp_wp_cron'                         => esc_html__( 'Use WP Cron', 'mainwp' ),
			'mainwp_optimize'                        => esc_html__( 'Optimize data loading', 'mainwp' ),
			'mainwp_automaticDailyUpdate'            => esc_html__( 'WP Core advanced automatic updates enabled', 'mainwp' ),
			'mainwp_pluginAutomaticDailyUpdate'      => esc_html__( 'Plugin advanced automatic updates enabled', 'mainwp' ),
			'mainwp_themeAutomaticDailyUpdate'       => esc_html__( 'Theme advanced automatic updates enabled', 'mainwp' ),
			'mainwp_numberdays_Outdate_Plugin_Theme' => esc_html__( 'Abandoned plugins/themes tolerance', 'mainwp' ),
			'mainwp_maximumPosts'                    => esc_html__( 'Maximum number of posts to return', 'mainwp' ),
			'mainwp_maximumPages'                    => esc_html__( 'Maximum number of pages to return', 'mainwp' ),
			'mainwp_maximumComments'                 => esc_html__( 'Maximum number of comments', 'mainwp' ),
			'mainwp_enableLegacyBackupFeature'       => esc_html__( 'MainWP legacy backups enabled', 'mainwp' ),
			'mainwp_primaryBackup'                   => esc_html__( 'Primary backup system', 'mainwp' ),
			'mainwp_disableSitesChecking'            => esc_html__( 'Basic uptime monitoring enabled', 'mainwp' ),
			'mainwp_disableSitesHealthMonitoring'    => esc_html__( 'Site health monitoring enabled', 'mainwp' ),
			'mainwp_maximumRequests'                 => esc_html__( 'Maximum simultaneous requests', 'mainwp' ),
			'mainwp_minimumDelay'                    => esc_html__( 'Minimum delay between requests', 'mainwp' ),
			'mainwp_maximumIPRequests'               => esc_html__( 'Maximum simultaneous requests per ip', 'mainwp' ),
			'mainwp_minimumIPDelay'                  => esc_html__( 'Minimum delay between requests to the same ip', 'mainwp' ),
			'mainwp_maximumSyncRequests'             => esc_html__( 'Maximum simultaneous sync requests', 'mainwp' ),
			'mainwp_maximumInstallUpdateRequests'    => esc_html__( 'Maximum simultaneous install and update requests', 'mainwp' ),
			'mainwp_auto_purge_cache'                => esc_html__( 'Cache control enabled', 'mainwp' ),
		);

		if ( ! is_plugin_active( 'mainwp-comments-extension/mainwp-comments-extension.php' ) ) {
			unset( $mainwp_options['mainwp_maximumComments'] );
		}

		$options_value = array();
		$userExtension = MainWP_DB_Common::instance()->get_user_extension();
		foreach ( $mainwp_options as $opt => $label ) {
			$value = get_option( $opt, false );
			switch ( $opt ) {
				case 'mainwp_number_of_child_sites':
					$value = MainWP_DB::instance()->get_websites_count();
					break;
				case 'mainwp_primaryBackup':
					$value = esc_html__( 'MainWP Legacy Backups', 'mainwp' );
					break;
				case 'mainwp_numberdays_Outdate_Plugin_Theme':
				case 'mainwp_maximumPosts':
				case 'mainwp_maximumPages':
				case 'mainwp_maximumComments':
				case 'mainwp_maximumSyncRequests':
				case 'mainwp_maximumInstallUpdateRequests':
					break;
				case 'mainwp_maximumRequests':
					$value = ( false === $value ) ? 4 : $value;
					break;
				case 'mainwp_maximumIPRequests':
					$value = ( false === $value ) ? 1 : $value;
					break;
				case 'mainwp_minimumIPDelay':
					$value = ( false === $value ) ? 1000 : $value;
					break;
				case 'mainwp_minimumDelay':
					$value = ( false === $value ) ? 200 : $value;
					break;
				case 'mainwp_maximumSyncRequests':
					$value = ( false === $value ) ? 8 : $value;
					break;
				case 'mainwp_maximumInstallUpdateRequests':
					$value = ( false === $value ) ? 3 : $value;
					break;
				case 'mainwp_disableSitesChecking':
					$value = empty( $value ) ? esc_html__( 'Yes', 'mainwp' ) : esc_html__( 'No', 'mainwp' );
					break;
				case 'mainwp_disableSitesHealthMonitoring':
					$value = empty( $value ) ? esc_html__( 'Yes', 'mainwp' ) : esc_html__( 'No', 'mainwp' );
					break;
				default:
					$value = empty( $value ) ? esc_html__( 'No', 'mainwp' ) : esc_html__( 'Yes', 'mainwp' );
					break;
			}
			$options_value[ $opt ] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		$options_value[ $opt ] = array(
			'label' => esc_html__( 'REST API enabled', 'mainwp' ),
			'value' => Rest_Api::instance()->is_rest_api_enabled() ? esc_html__( 'Yes', 'mainwp' ) : esc_html__( 'No', 'mainwp' ),
		);

		/**
		 * Filter: mainwp_getprimarybackup_methods
		 *
		 * Filters the primary backup options for the select menu in Settings.
		 *
		 * @since 4.0
		 */
		$primaryBackup        = get_option( 'mainwp_primaryBackup' );
		$primary_methods      = array();
		$primary_methods      = apply_filters_deprecated( 'mainwp-getprimarybackup-methods', array( $primary_methods ), '4.0.7.2', 'mainwp_getprimarybackup_methods' );  // @deprecated Use 'mainwp_getprimarybackup_methods' instead.
		$primaryBackupMethods = apply_filters( 'mainwp_getprimarybackup_methods', $primary_methods );

		if ( ! is_array( $primaryBackupMethods ) ) {
			$primaryBackupMethods = array();
		}

		if ( 0 < count( $primaryBackupMethods ) ) {
			$chk = false;
			foreach ( $primaryBackupMethods as $method ) {
				if ( $primaryBackup === $method['value'] ) {
					$value = $method['title'];
					$chk   = true;
					break;
				}
			}
			if ( $chk ) {
				$options_value['mainwp_primaryBackup'] = array(
					'label' => esc_html__( 'Primary Backup System', 'mainwp' ),
					'value' => $value,
				);
			}
		}
		return $options_value;
	}
}
