<?php
/**
 * MainWP Server Information Page Handler
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Server Information Handler
 */
class MainWP_Server_Information_Handler {

	// phpcs:disable WordPress.WP.AlternativeFunctions -- use system functions

	/**
	 * Get Class Name.
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/** Get current MainWP Plugin version. */
	public static function get_current_version() {
		$currentVersion = get_option( 'mainwp_plugin_version' );
		return $currentVersion;
	}

	/** Get current MainWP Dashboard version. */
	public static function get_mainwp_version() {
		if ( ( isset( $_SESSION['cachedVersion'] ) ) && ( null !== $_SESSION['cachedVersion'] ) && ( ( $_SESSION['cachedTime'] + ( 60 * 30 ) ) > time() ) ) {
			return $_SESSION['cachedVersion'];
		}
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';
		$api = plugins_api(
			'plugin_information',
			array(
				'slug'       => 'mainwp',
				'fields'     => array( 'sections' => false ),
				'timeout'    => 60,
			)
		);
		if ( is_object( $api ) && isset( $api->version ) ) {
			$_SESSION['cachedTime']    = time();
			$_SESSION['cachedVersion'] = $api->version;
			return $_SESSION['cachedVersion'];
		}
		return false;
	}

	/**
	 * Method check_if_multisite()
	 *
	 * Check if WP environment is Mutisilte or not.
	 *
	 * @return boolean true|false.
	 */
	public static function check_if_multisite() {
		$isMultisite = ! is_multisite() ? true : false;

		return $isMultisite;
	}

	/**
	 * Method filesize_compare()
	 *
	 * Compare Filesize.
	 *
	 * @param mixed $value1
	 * @param mixed $value2
	 * @param null  $operator
	 *
	 * @return void
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
	 * Method curlssl_compare()
	 *
	 * Compare cURL SSL Version.
	 *
	 * @param mixed $value
	 * @param null  $operator
	 *
	 * @return mixed false|version
	 */
	public static function curlssl_compare( $value, $operator = null ) {
		if ( isset( $value['version_number'] ) && defined( 'OPENSSL_VERSION_NUMBER' ) ) {
			return version_compare( OPENSSL_VERSION_NUMBER, $value['version_number'], $operator );
		}

		return false;
	}

	/**
	 * Method get_file_system_method()
	 *
	 * Get file system method.
	 *
	 * @return string $fs File System Method.
	 */
	public static function get_file_system_method() {
		$fs = get_filesystem_method();

		return $fs;
	}

	/**
	 * Method get_loaded_php_extensions()
	 *
	 * Get loaded PHP Extensions.
	 *
	 * @return string Comma separrated extention list.
	 */
	public static function get_loaded_php_extensions() {
		$extensions = get_loaded_extensions();
		sort( $extensions );
		echo implode( ', ', $extensions );
	}

	/**
	 * Method get_wordpress_memory_limit()
	 *
	 * Get the WP_MEMORY_LIMIT value.
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
	 */
	public static function get_ssl_warning() {
		$conf     = array( 'private_key_bits' => 2048 );
		$conf_loc = MainWP_System_Utility::get_openssl_conf();
		if ( ! empty( $conf_loc ) ) {
			$conf['config'] = $conf_loc;
		}
		$res = openssl_pkey_new( $conf );
		openssl_pkey_export( $res, $privkey, null, $conf );

		$str = openssl_error_string();

		return ( stristr( $str, 'NCONF_get_string:no value' ) ? '' : $str );
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
			if ( false !== stristr( $ssl_warning, __( 'No such file or directory found', 'mainwp' ) ) ) {
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
	 * @param boolean $return = false.
	 *
	 * @return mixed PHP_OS.
	 */
	public static function get_os( $return = false ) {
		if ( $return ) {
			return PHP_OS;
		} else {
			echo PHP_OS;
		}
	}

	/**
	 * Method get_architecture()
	 *
	 * Get PHP_INT_SIZE.
	 *
	 * @return string PHP_INT_SIZE * 8bit.
	 */
	public static function get_architecture() {
		echo( PHP_INT_SIZE * 8 )
		?>
		&nbsp;bit
		<?php
	}

	/**
	 * Method memory_usage()
	 *
	 * Get currently used memory.
	 *
	 * @return string $memory_usage Currently used memory.
	 */
	public static function memory_usage() {
		if ( function_exists( 'memory_get_usage' ) ) {
			$memory_usage = round( memory_get_usage() / 1024 / 1024, 2 ) . ' MB';
		} else {
			$memory_usage = 'N/A';
		}
		echo $memory_usage;
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
	 * @return boolean true|false.
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
	 *
	 * @return string $sql_mode Curret SQL Mode.
	 */
	public static function get_sql_mode() {
		global $wpdb;
		$mysqlinfo = $wpdb->get_results( "SHOW VARIABLES LIKE 'sql_mode'" );
		if ( is_array( $mysqlinfo ) ) {
			$sql_mode = $mysqlinfo[0]->Value;
		}
		if ( empty( $sql_mode ) ) {
			$sql_mode = __( 'NOT SET', 'mainwp' );
		}
		echo $sql_mode;
	}

	/**
	 * Method get_php_allow_url_fopen()
	 *
	 * Check if PHP fopen is allowed.
	 *
	 * @return string $allow_url_fopen YES|NO.
	 */
	public static function get_php_allow_url_fopen() {
		if ( ini_get( 'allow_url_fopen' ) ) {
			$allow_url_fopen = __( 'YES', 'mainwp' );
		} else {
			$allow_url_fopen = __( 'NO', 'mainwp' );
		}
		echo $allow_url_fopen;
	}

	/**
	 * Method get_php_exif()
	 *
	 * Check if PHP Exif is enabled.
	 *
	 * @return string $exif YES|NO.
	 */
	public static function get_php_exif() {
		if ( is_callable( 'exif_read_data' ) ) {
			$exif = __( 'YES', 'mainwp' ) . ' ( V' . substr( phpversion( 'exif' ), 0, 4 ) . ')';
		} else {
			$exif = __( 'NO', 'mainwp' );
		}
		echo $exif;
	}

	/**
	 * Method get_php_iptc()
	 *
	 * Check if iptcparse is enabled.
	 *
	 * @return string $iptc YES|NO.
	 */
	public static function get_php_iptc() {
		if ( is_callable( 'iptcparse' ) ) {
			$iptc = __( 'YES', 'mainwp' );
		} else {
			$iptc = __( 'NO', 'mainwp' );
		}
		echo $iptc;
	}

	/**
	 * Method get_php_xml()
	 *
	 * Check if PHP XML Parser is enabled.
	 *
	 * @return string $xml YES|NO.
	 */
	public static function get_php_xml() {
		if ( is_callable( 'xml_parser_create' ) ) {
			$xml = __( 'YES', 'mainwp' );
		} else {
			$xml = __( 'NO', 'mainwp' );
		}
		echo $xml;
	}

	/**
	 * Method get_server_gateway_interface()
	 *
	 * Get server gateway interface.
	 *
	 * @return string GATEWAY_INTERFACE|EMPTYSTRING.
	 */
	public static function get_server_gateway_interface() {
		echo isset( $_SERVER['GATEWAY_INTERFACE'] ) ? $_SERVER['GATEWAY_INTERFACE'] : '';
	}

	/**
	 * Method  get_server_ip()
	 *
	 * Get server IP address.
	 *
	 * @return string $_SERVER['SERVER_ADDR'].
	 */
	public static function get_server_ip() {
		echo $_SERVER['SERVER_ADDR'];
	}

	/**
	 * Method get_server_name()
	 *
	 * Get server name.
	 *
	 * @param boolean $return = false.
	 *
	 * @return string $_SERVER['SERVER_NAME'].
	 */
	public static function get_server_name( $return = false ) {
		if ( $return ) {
			return $_SERVER['SERVER_NAME'];
		} else {
			echo $_SERVER['SERVER_NAME'];
		}
	}

	/**
	 * Method get_server_software()
	 *
	 * Get server software.
	 *
	 * @param boolean $return = false.
	 *
	 * @return string $_SERVER['SERVER_SOFTWARE'].
	 */
	public static function get_server_software( $return = false ) {
		if ( $return ) {
			return $_SERVER['SERVER_SOFTWARE'];
		} else {
			echo $_SERVER['SERVER_SOFTWARE'];
		}
	}

	/**
	 * Method is_apache_server_software()
	 *
	 * Check if server software is apache.
	 *
	 * @param boolean $return = false.
	 *
	 * @return boolean True|false.
	 */
	public static function is_apache_server_software( $return = false ) {
		$server = self::get_server_software( true );
		return ( false !== stripos( $server, 'apache' ) ) ? true : false;
	}

	/**
	 * Method get_server_protocol()
	 *
	 * Get server protocol.
	 *
	 * @return string $_SERVER['SERVER_PROTOCOL'].
	 */
	public static function get_server_protocol() {
		echo $_SERVER['SERVER_PROTOCOL'];
	}

	/**
	 * Method get_server_request_time()
	 *
	 * Get server request time.
	 *
	 * @return string $_SERVER['REQUEST_TIME'].
	 */
	public static function get_server_request_time() {
		echo $_SERVER['REQUEST_TIME'];
	}


	/**
	 * Method get_server_http_accept()
	 *
	 * Get server http accept.
	 *
	 * @return string $_SERVER['HTTP_ACCEPT'].
	 */
	public static function get_server_http_accept() {
		echo $_SERVER['HTTP_ACCEPT'];
	}

	/**
	 * Method get_server_accept_charset()
	 *
	 * Get server accepted character set.
	 *
	 * @return string $_SERVER['HTTP_ACCEPT_CHARSET']|N/A.
	 */
	public static function get_server_accept_charset() {
		if ( ! isset( $_SERVER['HTTP_ACCEPT_CHARSET'] ) || ( '' === $_SERVER['HTTP_ACCEPT_CHARSET'] ) ) {
			esc_html_e( 'N/A', 'mainwp' );
		} else {
			echo $_SERVER['HTTP_ACCEPT_CHARSET'];
		}
	}

	/**
	 * Method get_http_host()
	 *
	 * Get http host.
	 *
	 * @return string $_SERVER['HTTP_HOST'].
	 */
	public static function get_http_host() {
		echo $_SERVER['HTTP_HOST'];
	}

	/**
	 * Method get_complete_url()
	 *
	 * Get complete url.
	 *
	 * @return string $_SERVER['HTTP_REFERER'].
	 */
	public static function get_complete_url() {
		echo isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '';
	}

	/**
	 * Method get_user_agent()
	 *
	 * Get user agent.
	 *
	 * @return string $_SERVER['HTTP_USER_AGENT'].
	 */
	public static function get_user_agent() {
		echo $_SERVER['HTTP_USER_AGENT'];
	}

	/**
	 * Method get_https()
	 *
	 * Check if https is enabled.
	 *
	 * @return string ON|OFF.
	 */
	public static function get_https() {
		if ( isset( $_SERVER['HTTPS'] ) && '' !== $_SERVER['HTTPS'] ) {
			esc_html_e( 'ON', 'mainwp' ) . ' - ' . $_SERVER['HTTPS'];
		} else {
			esc_html_e( 'OFF', 'mainwp' );
		}
	}

	/**
	 * Method server_self_connect()
	 *
	 * Check server self connect.
	 *
	 * @return string $test_result.
	 */
	public static function server_self_connect() {
		$url         = site_url( 'wp-cron.php' );
		$query_args  = array( 'mainwp_run' => 'test' );
		$url         = esc_url_raw( add_query_arg( $query_args, $url ) );
		$args        = array(
			'blocking'   => true,
			'sslverify'  => apply_filters( 'https_local_ssl_verify', true ),
			'timeout'    => 15,
		);
		$response    = wp_remote_post( $url, $args );
		$test_result = '';
		if ( is_wp_error( $response ) ) {
			$test_result .= sprintf( __( 'The HTTP response test get an error "%s"', 'mainwp' ), $response->get_error_message() );
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 > $response_code && 204 < $response_code ) {
			$test_result .= sprintf( __( 'The HTTP response test get a false http status (%s)', 'mainwp' ), wp_remote_retrieve_response_code( $response ) );
		} else {
			$response_body = wp_remote_retrieve_body( $response );
			if ( false === strstr( $response_body, 'MainWP Test' ) ) {
				$test_result .= sprintf( __( 'Not expected HTTP response body: %s', 'mainwp' ), esc_attr( wp_strip_all_tags( $response_body ) ) );
			}
		}
		if ( empty( $test_result ) ) {
			esc_html_e( 'Response Test O.K.', 'mainwp' );
		} else {
			echo $test_result;
		}
	}

	/**
	 * Method get_remote_address()
	 *
	 * Get server remote address.
	 *
	 * @return string $_SERVER['REMOTE_ADDR'].
	 */
	public static function get_remote_address() {
		echo $_SERVER['REMOTE_ADDR'];
	}

	/**
	 * Method get_remote_host()
	 *
	 * Get server remote host.
	 *
	 * @return string $_SERVER['REMOTE_HOST']|N/A.
	 */
	public static function get_remote_host() {
		if ( ! isset( $_SERVER['REMOTE_HOST'] ) || ( '' === $_SERVER['REMOTE_HOST'] ) ) {
			esc_html_e( 'N/A', 'mainwp' );
		} else {
			echo $_SERVER['REMOTE_HOST'];
		}
	}

	/**
	 * Method get_remote_port()
	 *
	 * Get server remote port.
	 *
	 * @return string $_SERVER['REMOTE_PORT'].
	 */
	public static function get_remote_port() {
		echo $_SERVER['REMOTE_PORT'];
	}

	/**
	 * Method get_script_file_name()
	 *
	 * Get server script filename.
	 *
	 * @return string $_SERVER['SCRIPT_FILENAME'].
	 */
	public static function get_script_file_name() {
		echo $_SERVER['SCRIPT_FILENAME'];
	}

	/**
	 * Method get_server_port()
	 *
	 * Get server port.
	 *
	 * @return string $_SERVER['SERVER_PORT'].
	 */
	public static function get_server_port() {
		echo $_SERVER['SERVER_PORT'];
	}

	/**
	 * Method get_current_page_uri()
	 *
	 * Get current page URI.
	 *
	 * @return string $_SERVER['REQUEST_URI'].
	 */
	public static function get_current_page_uri() {
		echo $_SERVER['REQUEST_URI'];
	}

	/**
	 * Method get_wp_root()
	 *
	 * Get WP Root Path.
	 *
	 * @return string ABSPATH.
	 */
	public static function get_wp_root() {
		echo ABSPATH;
	}

	/**
	 * Method time_compare()
	 *
	 * Compair time.
	 *
	 * @param mixed $a
	 * @param mixed $b
	 */
	public static function time_compare( $a, $b ) {

		if ( $a == $b ) {
			return 0;
		}

		return ( strtotime( $a['time'] ) > strtotime( $b['time'] ) ) ? - 1 : 1;
	}

	/**
	 * Method last_lines()
	 *
	 * Get line count.
	 *
	 * @param mixed   $path
	 * @param mixed   $line_count
	 * @param integer $block_size
	 *
	 * @return string Line Count.
	 */
	public static function last_lines( $path, $line_count, $block_size = 512 ) {
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
		} while ( $count < $line_count && ftell( $fh ) != 0 );

		if ( 0 == ftell( $fh ) ) {
			$lines[] = $leftover;
		}

		fclose( $fh );

		return array_slice( $lines, 0, $line_count );
	}

	/**
	 * Method mainwp_options()
	 *
	 * Get MainWP Set Options.
	 *
	 * @return array $options_value MainWP Options array.
	 */
	public static function mainwp_options() { // phpcs:ignore -- not quite complex function.
		$mainwp_options = array(
			'mainwp_number_of_child_sites'           => __( 'Number Of Child Sites', 'mainwp' ),
			'mainwp_wp_cron'                         => __( 'Use WP-Cron', 'mainwp' ),
			'mainwp_optimize'                        => __( 'Optimize for Shared Hosting or Big Networks', 'mainwp' ),
			'mainwp_automaticDailyUpdate'            => __( 'Automatic Daily Update', 'mainwp' ),
			'mainwp_numberdays_Outdate_Plugin_Theme' => __( 'Abandoned Plugins/Themes Tolerance', 'mainwp' ),
			'mainwp_maximumPosts'                    => __( 'Maximum number of posts to return', 'mainwp' ),
			'mainwp_maximumPages'                    => __( 'Maximum number of pages to return', 'mainwp' ),
			'mainwp_maximumComments'                 => __( 'Maximum Number of Comments', 'mainwp' ),
			'mainwp_primaryBackup'                   => __( 'Primary Backup System', 'mainwp' ),
			'mainwp_maximumRequests'                 => __( 'Maximum simultaneous requests', 'mainwp' ),
			'mainwp_minimumDelay'                    => __( 'Minimum delay between requests', 'mainwp' ),
			'mainwp_maximumIPRequests'               => __( 'Maximum simultaneous requests per ip', 'mainwp' ),
			'mainwp_minimumIPDelay'                  => __( 'Minimum delay between requests to the same ip', 'mainwp' ),
			'mainwp_maximumSyncRequests'             => __( 'Maximum simultaneous sync requests', 'mainwp' ),
			'mainwp_maximumInstallUpdateRequests'    => __( 'Minimum simultaneous install/update requests', 'mainwp' ),
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
					$value = __( 'Default MainWP Backups', 'mainwp' );
					break;
				case 'mainwp_numberdays_Outdate_Plugin_Theme':
				case 'mainwp_maximumPosts':
				case 'mainwp_maximumPages':
				case 'mainwp_maximumComments':
				case 'mainwp_maximumSyncRequests':
				case 'mainwp_maximumInstallUpdateRequests':
					break;
				case 'mainwp_automaticDailyUpdate':
					if ( 1 == $value ) {
						$value = 'Install trusted updates';
					} else {
						$value = 'Disabled';
					}
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
				default:
					$value = empty( $value ) ? __( 'No', 'mainwp' ) : __( 'Yes', 'mainwp' );
					break;
			}
			$options_value[ $opt ] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		$primaryBackup        = get_option( 'mainwp_primaryBackup' );
		$primary_methods      = array();
		$primary_methods      = apply_filters_deprecated( 'mainwp-getprimarybackup-methods', array( $primary_methods ), '4.0.1', 'mainwp_getprimarybackup_methods' );  // @deprecated Use 'mainwp_getprimarybackup_methods' instead.
		$primaryBackupMethods = apply_filters( 'mainwp_getprimarybackup_methods', $primary_methods );

		if ( ! is_array( $primaryBackupMethods ) ) {
			$primaryBackupMethods = array();
		}

		if ( 0 < count( $primaryBackupMethods ) ) {
			$chk = false;
			foreach ( $primaryBackupMethods as $method ) {
				if ( $primaryBackup == $method['value'] ) {
					$value = $method['title'];
					$chk   = true;
					break;
				}
			}
			if ( $chk ) {
				$options_value['mainwp_primaryBackup'] = array(
					'label' => __( 'Primary Backup System', 'mainwp' ),
					'value' => $value,
				);
			}
		}
		return $options_value;
	}

}
