<?php
/**
 * MainWP Server Information Page Handler
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP Server_Information_Handler
 */
class MainWP_Server_Information_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
        return get_option( 'mainwp_plugin_version' );
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
        include_once ABSPATH . '/wp-admin/includes/plugin-install.php'; // NOSONAR - WP compatible.
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
        return ! is_multisite() ? true : false;
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
            $ver = static::get_curl_ssl_version();
            return version_compare( static::extract_version( $ver ), static::extract_version( $version ), $operator );
        }
        return false;
    }

    /**
     * Method extract_version()
     *
     * Function to extract version from string for both OpenSSL and LibreSSL
     *
     * @param string $version_string Version OpenSSL and LibreSSL.
     *
     * @return mixed null|string
     */
    public static function extract_version( $version_string ) {
        if ( preg_match( '/(?:LibreSSL|OpenSSL)[\/ ]([\d.]+)/', $version_string, $matches ) ) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Gets file system method.
     *
     * @return string $fs File System Method.
     */
    public static function get_file_system_method() {
        return get_filesystem_method();
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

        if ( 2 !== $general_verify_con && function_exists( 'openssl_pkey_new' ) ) {
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
        $ssl_warning = static::get_ssl_warning();
        if ( '' !== $ssl_warning && ( false !== stristr( $ssl_warning, 'No such file or directory found', 'mainwp' ) || false !== stristr( $ssl_warning, 'No such process', 'mainwp' ) || false !== stristr( $ssl_warning, 'no such file', 'mainwp' ) ) ) {
            return true;
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
        echo esc_html( PHP_INT_SIZE * 8 );
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
        $cache_key = 'mainwp_sql_mode';
        $mysqlinfo = wp_cache_get( $cache_key );
        if ( false === $mysqlinfo ) {
            $mysqlinfo = $wpdb->get_results( "SHOW VARIABLES LIKE 'sql_mode'" ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- ok.
            wp_cache_set( $cache_key, $mysqlinfo, '', DAY_IN_SECONDS );
        }
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
        $server = static::get_server_software( true );
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
        echo isset( $_SERVER['HTTP_REFERER'] ) ? esc_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) ) : '';
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
            /* translators: %s: error message */
            $test_result .= sprintf( esc_html__( 'The HTTP response test get an error "%s"', 'mainwp' ), $response->get_error_message() );
        }
        $response_code = wp_remote_retrieve_response_code( $response );
        if ( 200 > $response_code && 204 < $response_code ) {
            /* translators: %s: HTTP status code */
            $test_result .= sprintf( esc_html__( 'The HTTP response test get a false http status (%s)', 'mainwp' ), wp_remote_retrieve_response_code( $response ) );
        } else {
            $response_body = wp_remote_retrieve_body( $response );
            if ( false === strstr( $response_body, 'MainWP Test' ) ) {
                /* translators: %s: response body */
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
    public static function mainwp_options() { // phpcs:ignore -- NOSONAR - current complexity required to achieve desired results. Pull request solutions appreaciated.
        $mainwp_options = array(
            'mainwp_number_of_child_sites'                => esc_html__( 'Number of connected sites', 'mainwp' ),
            'mainwp_wp_cron'                              => esc_html__( 'Use WP Cron', 'mainwp' ),
            'mainwp_optimize'                             => esc_html__( 'Optimize data loading', 'mainwp' ),
            'mainwp_automaticDailyUpdate'                 => esc_html__( 'WP Core advanced automatic updates enabled', 'mainwp' ),
            'mainwp_pluginAutomaticDailyUpdate'           => esc_html__( 'Plugin advanced automatic updates enabled', 'mainwp' ),
            'mainwp_themeAutomaticDailyUpdate'            => esc_html__( 'Theme advanced automatic updates enabled', 'mainwp' ),
            'mainwp_numberdays_Outdate_Plugin_Theme'      => esc_html__( 'Abandoned plugins/themes tolerance', 'mainwp' ),
            'mainwp_maximumPosts'                         => esc_html__( 'Maximum number of posts to return', 'mainwp' ),
            'mainwp_maximumPages'                         => esc_html__( 'Maximum number of pages to return', 'mainwp' ),
            'mainwp_maximumComments'                      => esc_html__( 'Maximum number of comments', 'mainwp' ),
            'mainwp_enableLegacyBackupFeature'            => esc_html__( 'MainWP legacy backups enabled', 'mainwp' ),
            'mainwp_primaryBackup'                        => esc_html__( 'Primary backup system', 'mainwp' ),
            'is_enable_automatic_check_uptime_monitoring' => esc_html__( 'Enable Uptime Monitoring', 'mainwp' ),
            'mainwp_disableSitesHealthMonitoring'         => esc_html__( 'Site health monitoring enabled', 'mainwp' ),
            'mainwp_maximumRequests'                      => esc_html__( 'Maximum simultaneous requests', 'mainwp' ),
            'mainwp_minimumDelay'                         => esc_html__( 'Minimum delay between requests', 'mainwp' ),
            'mainwp_maximumIPRequests'                    => esc_html__( 'Maximum simultaneous requests per ip', 'mainwp' ),
            'mainwp_minimumIPDelay'                       => esc_html__( 'Minimum delay between requests to the same ip', 'mainwp' ),
            'mainwp_maximumSyncRequests'                  => esc_html__( 'Maximum simultaneous sync requests', 'mainwp' ),
            'mainwp_maximumInstallUpdateRequests'         => esc_html__( 'Maximum simultaneous install and update requests', 'mainwp' ),
            'mainwp_maximum_uptime_monitoring_requests'   => esc_html__( 'Maximum simultaneous uptime monitoring requests (Default: 10)', 'mainwp' ),
            'mainwp_auto_purge_cache'                     => esc_html__( 'Cache control enabled', 'mainwp' ),
        );

        if ( ! is_plugin_active( 'mainwp-comments-extension/mainwp-comments-extension.php' ) ) {
            unset( $mainwp_options['mainwp_maximumComments'] );
        }

        $options_value = array();
        foreach ( $mainwp_options as $opt => $label ) {
            if ( 'is_enable_automatic_check_uptime_monitoring' === $opt ) {
                $global = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
                $value  = $global['active'];
            } else {
                $value = get_option( $opt, false );
            }
            $save_value = $value;
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
                case 'mainwp_maximum_uptime_monitoring_requests':
                    $value = ( false === $value ) ? 10 : $value;
                    break;
                case 'is_enable_automatic_check_uptime_monitoring':
                    $value = ! empty( $value ) ? esc_html__( 'Yes', 'mainwp' ) : esc_html__( 'No', 'mainwp' );
                    break;
                case 'mainwp_disableSitesHealthMonitoring':
                    $value = empty( $value ) ? esc_html__( 'Yes', 'mainwp' ) : esc_html__( 'No', 'mainwp' );
                    break;
                default:
                    $value = empty( $value ) ? esc_html__( 'No', 'mainwp' ) : esc_html__( 'Yes', 'mainwp' );
                    break;
            }
            $options_value[ $opt ] = array(
                'label'      => $label,
                'value'      => $value,
                'save_value' => $save_value,
                'name'       => $opt,
            );
        }

        $options_value['mainwp_rest_api_enabled'] = array(
            'label'      => esc_html__( 'REST API enabled', 'mainwp' ),
            'value'      => Rest_Api_V1::instance()->is_rest_api_enabled() ? esc_html__( 'Yes', 'mainwp' ) : esc_html__( 'No', 'mainwp' ),
            'save_value' => '',
            'name'       => 'mainwp_rest_api_enabled',
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
        $primary_methods      = apply_filters_deprecated( 'mainwp-getprimarybackup-methods', array( $primary_methods ), '4.0.7.2', 'mainwp_getprimarybackup_methods' );  // @deprecated Use 'mainwp_getprimarybackup_methods' instead. NOSONAR - not IP.
        $primaryBackupMethods = apply_filters( 'mainwp_getprimarybackup_methods', $primary_methods );

        if ( ! is_array( $primaryBackupMethods ) ) {
            $primaryBackupMethods = array();
        }

        if ( ! empty( $primaryBackupMethods ) ) {
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
                    'label'      => esc_html__( 'Primary Backup System', 'mainwp' ),
                    'value'      => $value,
                    'save_value' => $primaryBackup,
                    'name'       => 'mainwp_primaryBackup',
                );
            }
        }
        return $options_value;
    }

    /**
     * Get primary issues summary.
     *
     * @return array<int,array<string,string>>
     */
    public static function get_primary_issues() {
        $issues = array();

        if ( ! static::filesize_compare( static::get_wordpress_memory_limit(), '64M', '>=' ) ) {
            $issues[] = array(
                'severity' => 'warning',
                'title'    => esc_html__( 'Low WordPress memory limit', 'mainwp' ),
                'detail'   => sprintf(
                    /* translators: 1: detected memory limit, 2: recommended memory limit */
                    esc_html__( 'Detected %1$s. MainWP recommends at least %2$s.', 'mainwp' ),
                    static::get_wordpress_memory_limit(),
                    '64M'
                ),
                'anchor'   => 'mainwp-system-report-wordpress-table',
                'kb_url'   => 'https://docs.mainwp.com/troubleshooting/resolve-system-requirement-issues/',
            );
        }

        if ( ! version_compare( (string) static::get_curl_timeout(), '300', '>=' ) ) {
            $issues[] = array(
                'severity' => 'warning',
                'title'    => esc_html__( 'Low cURL timeout', 'mainwp' ),
                'detail'   => sprintf(
                    /* translators: 1: detected timeout, 2: recommended timeout */
                    esc_html__( 'Detected %1$s seconds. MainWP recommends at least %2$s seconds.', 'mainwp' ),
                    (string) static::get_curl_timeout(),
                    '300'
                ),
                'anchor'   => 'mainwp-system-report-php-table',
                'kb_url'   => 'https://docs.mainwp.com/troubleshooting/resolve-system-requirement-issues/',
            );
        }

        $rest_api = static::get_rest_api_reachability_data();
        if ( 'pass' !== $rest_api['status'] ) {
            $issues[] = array(
                'severity' => $rest_api['status'],
                'title'    => esc_html__( 'WordPress REST API is not fully reachable', 'mainwp' ),
                'detail'   => $rest_api['value'],
                'anchor'   => 'mainwp-system-report-connectivity-table',
                'kb_url'   => 'https://docs.mainwp.com/troubleshooting/wordpress-rest-api-does-not-respond/',
            );
        }

        $permalink = static::get_permalink_structure_data();
        if ( 'pass' !== $permalink['status'] ) {
            $issues[] = array(
                'severity' => $permalink['status'],
                'title'    => esc_html__( 'Plain permalinks detected', 'mainwp' ),
                'detail'   => esc_html__( 'MainWP features that depend on the WordPress REST API may not work correctly with plain permalinks.', 'mainwp' ),
                'anchor'   => 'mainwp-system-report-connectivity-table',
                'kb_url'   => 'https://docs.mainwp.com/troubleshooting/wordpress-rest-api-does-not-respond/',
            );
        }

        $self_connect = static::get_server_self_connect_data();
        if ( 'pass' !== $self_connect['status'] ) {
            $issue_title  = esc_html__( 'Dashboard self-connect check failed', 'mainwp' );
            $issue_detail = $self_connect['value'];
            $issue_html   = '';

            if ( isset( $self_connect['code'] ) && 'unexpected_body' === $self_connect['code'] ) {
                $issue_title  = esc_html__( 'Dashboard self-connect returned an unexpected body', 'mainwp' );
                $issue_detail = esc_html__( 'Unexpected response body. Note: This is common and usually not a problem unless WP-Cron or loopback requests are failing.', 'mainwp' );
                $issue_html   = sprintf(
                    '%1$s <em>%2$s</em>',
                    esc_html__( 'Unexpected response body.', 'mainwp' ),
                    esc_html__( 'Note: This is common and usually not a problem unless WP-Cron or loopback requests are failing.', 'mainwp' )
                );
            }

            $issues[] = array(
                'severity'    => $self_connect['status'],
                'title'       => $issue_title,
                'detail'      => $issue_detail,
                'detail_html' => $issue_html,
                'anchor'      => 'mainwp-system-report-connectivity-table',
                'kb_url'      => ( isset( $self_connect['code'] ) && 'unexpected_body' === $self_connect['code'] ) ? '' : 'https://docs.mainwp.com/troubleshooting/potential-issues/',
            );
        }

        return $issues;
    }

    /**
     * Add English export labels to localized report rows.
     *
     * @param array<int,array<string,mixed>> $rows           Report rows.
     * @param array<int,string>              $english_labels English labels to map.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function add_export_labels_to_rows( $rows, $english_labels ) {
        if ( empty( $rows ) || empty( $english_labels ) ) {
            return $rows;
        }

        $localized_map = array();
        foreach ( $english_labels as $english_label ) {
            $localized_map[ esc_html__( $english_label, 'mainwp' ) ] = $english_label;
        }

        foreach ( $rows as $index => $row ) {
            if ( ! is_array( $row ) || empty( $row['label'] ) || ! empty( $row['export_label'] ) ) {
                continue;
            }

            if ( isset( $localized_map[ $row['label'] ] ) ) {
                $rows[ $index ]['export_label'] = $localized_map[ $row['label'] ];
            }
        }

        return $rows;
    }

    /**
     * Get English export label for notification type.
     *
     * @param string $type           Notification type.
     * @param string $fallback_label Fallback localized label.
     *
     * @return string
     */
    private static function get_notification_type_export_label( $type, $fallback_label ) {
        $labels = array(
            'daily_digest'              => 'Daily Digest Email',
            'uptime'                    => 'Uptime Monitoring Email',
            'site_health'               => 'Site Health Monitoring Email',
            'deactivated_license_alert' => 'Extension License Deactivation Notification Email',
            'http_check'                => 'After Updates HTTP Check Email',
        );

        /**
         * Filter: mainwp_notification_type_export_labels
         *
         * Filters English export labels for notification types used in the Server Information report export.
         *
         * @since 6.1
         *
         * @param array  $labels         Notification type => English export label map.
         * @param string $type           Notification type currently being resolved.
         * @param string $fallback_label Localized fallback label.
         */
        $labels = apply_filters( 'mainwp_notification_type_export_labels', $labels, $type, $fallback_label );
        if ( ! is_array( $labels ) ) {
            $labels = array();
        }

        return isset( $labels[ $type ] ) ? $labels[ $type ] : $fallback_label;
    }

    /**
     * Get MainWP overview rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_mainwp_overview_report_rows() {
        $current_version = static::get_current_version();
        $latest_version  = static::get_mainwp_version();
        $version_value   = false !== $latest_version
            ? sprintf(
                /* translators: 1: latest version, 2: current version */
                esc_html__( 'Latest: %1$s | Detected: %2$s', 'mainwp' ),
                $latest_version,
                $current_version
            )
            : sprintf(
                /* translators: %s: current version */
                esc_html__( 'Detected: %s', 'mainwp' ),
                $current_version
            );

        $upload_directory = static::get_mainwp_upload_directory_data();

        return self::add_export_labels_to_rows(
            array(
            array(
                'label'  => esc_html__( 'MainWP Dashboard Version', 'mainwp' ),
                'value'  => $version_value,
                'status' => ( false !== $latest_version && $current_version === $latest_version ) ? 'pass' : 'warning',
            ),
            array(
                'label' => esc_html__( 'Number of connected sites', 'mainwp' ),
                'value' => (string) MainWP_DB::instance()->get_websites_count(),
            ),
            array(
                'label' => esc_html__( 'MainWP upload directory', 'mainwp' ),
                'value' => $upload_directory['value'],
                'status' => $upload_directory['status'],
            ),
            array(
                'label' => esc_html__( 'MainWP REST API enabled', 'mainwp' ),
                'value' => static::format_boolean_label( Rest_Api_V1::instance()->is_rest_api_enabled() ),
                'status' => Rest_Api_V1::instance()->is_rest_api_enabled() ? 'pass' : 'warning',
            ),
            ),
            array(
                'MainWP Dashboard Version',
                'Number of connected sites',
                'MainWP upload directory',
                'MainWP REST API enabled',
            )
        );
    }

    /**
     * Get connectivity rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_connectivity_report_rows() {
        $permalink    = static::get_permalink_structure_data();
        $rest_api     = static::get_rest_api_reachability_data();
        $self_connect = static::get_server_self_connect_data();
        $public_ip    = static::get_public_dashboard_ip_data();
        $ssl_verify   = ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || 1 === (int) get_option( 'mainwp_sslVerifyCertificate', 1 );
        $verify_con   = (int) get_option( 'mainwp_verify_connection_method', 1 );
        $signature    = static::get_signature_algorithm_label();

        return self::add_export_labels_to_rows(
            array(
            array(
                'label'      => esc_html__( 'Dashboard home URL', 'mainwp' ),
                'value'      => home_url( '/' ),
                'visibility' => 'full_only',
            ),
            array(
                'label'      => esc_html__( 'Dashboard site URL', 'mainwp' ),
                'value'      => site_url( '/' ),
                'visibility' => 'full_only',
            ),
            array(
                'label'           => esc_html__( 'Public Dashboard IP', 'mainwp' ),
                'value'           => $public_ip['value'],
                'status'          => $public_ip['status'],
                'visibility'      => 'community_masked',
                'community_value' => $public_ip['community_value'],
            ),
            array(
                'label'  => esc_html__( 'HTTPS', 'mainwp' ),
                'value'  => is_ssl() ? esc_html__( 'Enabled', 'mainwp' ) : esc_html__( 'Disabled', 'mainwp' ),
            ),
            array(
                'label'  => esc_html__( 'Permalink structure', 'mainwp' ),
                'value'  => $permalink['value'],
                'status' => $permalink['status'],
            ),
            array(
                'label'      => esc_html__( 'WordPress REST API endpoint', 'mainwp' ),
                'value'      => rest_url(),
                'visibility' => 'full_only',
            ),
            array(
                'label'  => esc_html__( 'WordPress REST API reachability', 'mainwp' ),
                'value'  => $rest_api['value'],
                'status' => $rest_api['status'],
            ),
            array(
                'label'  => esc_html__( 'Server self connect', 'mainwp' ),
                'value'  => $self_connect['value'],
                'status' => $self_connect['status'],
            ),
            array(
                'label' => esc_html__( 'Verify SSL certificate', 'mainwp' ),
                'value' => static::format_boolean_label( $ssl_verify ),
            ),
            array(
                'label' => esc_html__( 'Verify connection method', 'mainwp' ),
                'value' => 2 === $verify_con ? esc_html__( 'PHPSECLIB (fallback)', 'mainwp' ) : esc_html__( 'OpenSSL (default)', 'mainwp' ),
            ),
            array(
                'label' => esc_html__( 'OpenSSL signature algorithm', 'mainwp' ),
                'value' => $signature,
            ),
            array(
                'label' => esc_html__( 'Force IPv4', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_forceUseIPv4', 0 ) ),
            ),
            ),
            array(
                'Dashboard home URL',
                'Dashboard site URL',
                'Public Dashboard IP',
                'HTTPS',
                'Permalink structure',
                'WordPress REST API endpoint',
                'WordPress REST API reachability',
                'Server self connect',
                'Verify SSL certificate',
                'Verify connection method',
                'OpenSSL signature algorithm',
                'Force IPv4',
            )
        );
    }

    /**
     * Get scheduler rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_scheduler_report_rows() {
        $daily_frequency = (int) get_option( 'mainwp_frequencyDailyUpdate', 2 );
        if ( $daily_frequency <= 0 ) {
            $daily_frequency = 1;
        }

        $rows = array(
            array(
                'label'  => esc_html__( 'Use WP Cron', 'mainwp' ),
                'value'  => static::format_boolean_label( ( false === get_option( 'mainwp_wp_cron' ) ) || 1 === (int) get_option( 'mainwp_wp_cron', 1 ) ),
            ),
            array(
                'label' => esc_html__( 'Daily update frequency', 'mainwp' ),
                'value' => MainWP_Server_Information::get_schedule_auto_update_label( $daily_frequency ),
            ),
            array(
                'label' => esc_html__( 'Daily update time', 'mainwp' ),
                'value' => (string) get_option( 'mainwp_timeDailyUpdate', '00:00' ),
            ),
            array(
                'label' => esc_html__( 'Advanced automatic updates schedule', 'mainwp' ),
                'value' => static::get_automatic_updates_schedule_label(),
            ),
        );

        $updates_check = static::get_cron_job_summary( 'mainwp_updatescheck_start_last_timestamp', 'mainwp_cronupdatescheck_action', '' );
        $rows[]        = array(
            'label' => esc_html__( 'Check for available updates', 'mainwp' ),
            'value' => sprintf(
                /* translators: 1: last run, 2: next run */
                esc_html__( 'Last run: %1$s | Next run: %2$s', 'mainwp' ),
                $updates_check['last'],
                $updates_check['next']
            ),
        );

        $reconnect = static::get_cron_job_summary( 'mainwp_cron_last_stats', 'mainwp_cronreconnect_action', 'hourly' );
        $rows[]    = array(
            'label' => esc_html__( 'Reconnect sites', 'mainwp' ),
            'value' => sprintf(
                /* translators: 1: last run, 2: next run */
                esc_html__( 'Last run: %1$s | Next run: %2$s', 'mainwp' ),
                $reconnect['last'],
                $reconnect['next']
            ),
        );

        $monitoring = static::get_uptime_monitoring_summary();
        if ( ! empty( $monitoring ) ) {
            $rows[] = $monitoring;
        }

        return self::add_export_labels_to_rows(
            $rows,
            array(
                'Use WP Cron',
                'Daily update frequency',
                'Daily update time',
                'Advanced automatic updates schedule',
                'Check for available updates',
                'Reconnect sites',
                'Uptime monitoring schedule',
            )
        );
    }

    /**
     * Get general settings rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_general_settings_report_rows() {
        $default_setting = MainWP_Settings_Indicator::get_defaults_value();
        $sidebar         = get_user_option( 'mainwp_sidebarPosition' );
        if ( false === $sidebar ) {
            $sidebar = 1;
        }

        $time_auto_update      = get_option( 'mainwp_time_AutoUpdate', '00:00' );
        $frequency_auto_update = get_option( 'mainwp_frequency_AutoUpdate', 'daily' );
        if ( empty( $time_auto_update ) ) {
            $time_auto_update = '00:00';
        }
        if ( ! in_array( $frequency_auto_update, array( 'daily', 'weekly', 'monthly' ), true ) ) {
            $frequency_auto_update = 'daily';
        }

        $show_widgets = get_user_option( 'mainwp_settings_show_widgets', array() );
        if ( ! is_array( $show_widgets ) ) {
            $show_widgets = array();
        }

        return self::add_export_labels_to_rows(
            array(
            array(
                'label' => esc_html__( 'Timezone', 'mainwp' ),
                'value' => static::get_timezone_label(),
            ),
            array(
                'label' => esc_html__( 'Date format', 'mainwp' ),
                'value' => (string) get_option( 'date_format', $default_setting['date_format'] ),
            ),
            array(
                'label' => esc_html__( 'Time format', 'mainwp' ),
                'value' => (string) get_option( 'time_format', $default_setting['time_format'] ),
            ),
            array(
                'label' => esc_html__( 'Sidebar position', 'mainwp' ),
                'value' => 0 === (int) $sidebar ? esc_html__( 'Left', 'mainwp' ) : esc_html__( 'Right', 'mainwp' ),
            ),
            array(
                'label' => esc_html__( 'Hide Update Everything', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_hide_update_everything', $default_setting['mainwp_hide_update_everything'] ) ),
            ),
            array(
                'label' => esc_html__( 'Dashboard widgets shown', 'mainwp' ),
                'value' => sprintf(
                    /* translators: %d: visible widget count */
                    esc_html__( '%d visible', 'mainwp' ),
                    count( $show_widgets )
                ),
            ),
            array(
                'label' => esc_html__( 'WP Core advanced automatic updates', 'mainwp' ),
                'value' => static::get_trusted_update_mode_label( get_option( 'mainwp_automaticDailyUpdate', $default_setting['mainwp_automaticDailyUpdate'] ) ),
            ),
            array(
                'label' => esc_html__( 'Plugin advanced automatic updates', 'mainwp' ),
                'value' => static::get_trusted_update_mode_label( get_option( 'mainwp_pluginAutomaticDailyUpdate', $default_setting['mainwp_pluginAutomaticDailyUpdate'] ) ),
            ),
            array(
                'label' => esc_html__( 'Theme advanced automatic updates', 'mainwp' ),
                'value' => static::get_trusted_update_mode_label( get_option( 'mainwp_themeAutomaticDailyUpdate', $default_setting['mainwp_themeAutomaticDailyUpdate'] ) ),
            ),
            array(
                'label' => esc_html__( 'Translation advanced automatic updates', 'mainwp' ),
                'value' => static::get_trusted_update_mode_label( get_option( 'mainwp_transAutomaticDailyUpdate', 0 ) ),
            ),
            array(
                'label' => esc_html__( 'Automatic updates frequency', 'mainwp' ),
                'value' => ucfirst( (string) $frequency_auto_update ),
            ),
            array(
                'label' => esc_html__( 'Automatic updates time', 'mainwp' ),
                'value' => (string) $time_auto_update,
            ),
            array(
                'label' => esc_html__( 'Automatic updates day', 'mainwp' ),
                'value' => static::get_automatic_updates_day_label( $frequency_auto_update ),
            ),
            array(
                'label' => esc_html__( 'Advanced automatic updates delay', 'mainwp' ),
                'value' => static::format_delay_label( (int) get_option( 'mainwp_delay_autoupdate', 1 ) ),
            ),
            array(
                'label' => esc_html__( 'Show language updates', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_show_language_updates', $default_setting['mainwp_show_language_updates'] ) ),
            ),
            array(
                'label' => esc_html__( 'Disable update confirmations', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_disable_update_confirmations', $default_setting['mainwp_disable_update_confirmations'] ) ),
            ),
            array(
                'label' => esc_html__( 'After updates HTTP response check', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_check_http_response', $default_setting['mainwp_check_http_response'] ) ),
            ),
            array(
                'label' => esc_html__( 'After updates HTTP response method', 'mainwp' ),
                'value' => strtoupper( (string) get_option( 'mainwp_check_http_response_method', $default_setting['mainwp_check_http_response_method'] ) ),
            ),
            array(
                'label' => esc_html__( 'Backup before update', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_backup_before_upgrade', $default_setting['mainwp_backup_before_upgrade'] ) ),
            ),
            array(
                'label' => esc_html__( 'Full backup tolerance before update', 'mainwp' ),
                'value' => sprintf(
                    /* translators: %d: days */
                    esc_html__( '%d days', 'mainwp' ),
                    (int) get_option( 'mainwp_backup_before_upgrade_days', $default_setting['mainwp_backup_before_upgrade_days'] )
                ),
            ),
            array(
                'label' => esc_html__( 'Abandoned plugins/themes tolerance', 'mainwp' ),
                'value' => sprintf(
                    /* translators: %d: days */
                    esc_html__( '%d days', 'mainwp' ),
                    (int) get_option( 'mainwp_numberdays_Outdate_Plugin_Theme', $default_setting['mainwp_numberdays_Outdate_Plugin_Theme'] )
                ),
            ),
            array(
                'label' => esc_html__( 'Primary backup system', 'mainwp' ),
                'value' => static::get_primary_backup_method_label(),
            ),
            array(
                'label' => esc_html__( 'MainWP legacy backups enabled', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_enableLegacyBackupFeature', 0 ) ),
            ),
            array(
                'label' => esc_html__( 'Backups kept on server', 'mainwp' ),
                'value' => (string) ( false === get_option( 'mainwp_backupsOnServer' ) ? 1 : intval( get_option( 'mainwp_backupsOnServer' ) ) ),
            ),
            array(
                'label' => esc_html__( 'Backup to external sources', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_backupOnExternalSources', 0 ) ),
            ),
            array(
                'label' => esc_html__( 'Archive format', 'mainwp' ),
                'value' => static::format_archive_format_label( get_option( 'mainwp_archiveFormat', 'tar.gz' ) ),
            ),
            array(
                'label' => esc_html__( 'Auto detect maximum file descriptors on child sites', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_maximumFileDescriptorsAuto', 1 ) ),
            ),
            array(
                'label' => esc_html__( 'Maximum file descriptors fallback value', 'mainwp' ),
                'value' => (string) ( false === get_option( 'mainwp_maximumFileDescriptors' ) ? 150 : intval( get_option( 'mainwp_maximumFileDescriptors' ) ) ),
            ),
            array(
                'label' => esc_html__( 'Load files in memory before zipping', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_options_loadFilesBeforeZip', 1 ) ),
            ),
            array(
                'label' => esc_html__( 'Send email when backup fails', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_notificationOnBackupFail', 1 ) ),
            ),
            array(
                'label' => esc_html__( 'Send email when backup starts', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_notificationOnBackupStart', 1 ) ),
            ),
            array(
                'label' => esc_html__( 'Execute backup tasks in chunks', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_chunkedBackupTasks', 1 ) ),
            ),
            ),
            array(
                'Timezone',
                'Date format',
                'Time format',
                'Sidebar position',
                'Hide Update Everything',
                'Dashboard widgets shown',
                'WP Core advanced automatic updates',
                'Plugin advanced automatic updates',
                'Theme advanced automatic updates',
                'Translation advanced automatic updates',
                'Automatic updates frequency',
                'Automatic updates time',
                'Automatic updates day',
                'Advanced automatic updates delay',
                'Show language updates',
                'Disable update confirmations',
                'After updates HTTP response check',
                'After updates HTTP response method',
                'Backup before update',
                'Full backup tolerance before update',
                'Abandoned plugins/themes tolerance',
                'Primary backup system',
                'MainWP legacy backups enabled',
                'Backups kept on server',
                'Backup to external sources',
                'Archive format',
                'Auto detect maximum file descriptors on child sites',
                'Maximum file descriptors fallback value',
                'Load files in memory before zipping',
                'Send email when backup fails',
                'Send email when backup starts',
                'Execute backup tasks in chunks',
            )
        );
    }

    /**
     * Get advanced settings rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_advanced_settings_report_rows() {
        $default_setting = MainWP_Settings_Indicator::get_defaults_value();
        $sign_algs       = MainWP_System_Utility::get_open_ssl_sign_algos();
        $signature_algo  = (int) get_option( 'mainwp_connect_signature_algo', $default_setting['mainwp_connect_signature_algo'] );
        $sync_defaults   = MainWP_Settings::get_data_sync_default();
        $sync_data       = get_option( 'mainwp_settings_sync_data', array() );
        $sync_data       = ! empty( $sync_data ) ? json_decode( (string) $sync_data, true ) : array();
        if ( ! is_array( $sync_data ) ) {
            $sync_data = array();
        }

        $enabled_sync = 0;
        foreach ( array_keys( $sync_defaults ) as $sync_name ) {
            if ( ! isset( $sync_data[ $sync_name ] ) || ! empty( $sync_data[ $sync_name ] ) ) {
                ++$enabled_sync;
            }
        }

        return self::add_export_labels_to_rows(
            array(
            array(
                'label' => esc_html__( 'Maximum simultaneous requests', 'mainwp' ),
                'value' => (string) ( false === get_option( 'mainwp_maximumRequests' ) ? 4 : intval( get_option( 'mainwp_maximumRequests' ) ) ),
            ),
            array(
                'label' => esc_html__( 'Minimum delay between requests', 'mainwp' ),
                'value' => sprintf(
                    /* translators: %d: milliseconds */
                    esc_html__( '%d ms', 'mainwp' ),
                    (int) ( false === get_option( 'mainwp_minimumDelay' ) ? 200 : intval( get_option( 'mainwp_minimumDelay' ) ) )
                ),
            ),
            array(
                'label' => esc_html__( 'Maximum simultaneous requests per IP', 'mainwp' ),
                'value' => (string) ( false === get_option( 'mainwp_maximumIPRequests' ) ? 1 : intval( get_option( 'mainwp_maximumIPRequests' ) ) ),
            ),
            array(
                'label' => esc_html__( 'Minimum delay between requests to the same IP', 'mainwp' ),
                'value' => sprintf(
                    /* translators: %d: milliseconds */
                    esc_html__( '%d ms', 'mainwp' ),
                    (int) ( false === get_option( 'mainwp_minimumIPDelay' ) ? 1000 : intval( get_option( 'mainwp_minimumIPDelay' ) ) )
                ),
            ),
            array(
                'label' => esc_html__( 'Maximum simultaneous sync requests', 'mainwp' ),
                'value' => (string) ( false === get_option( 'mainwp_maximumSyncRequests' ) ? 8 : intval( get_option( 'mainwp_maximumSyncRequests' ) ) ),
            ),
            array(
                'label' => esc_html__( 'Maximum simultaneous install and update requests', 'mainwp' ),
                'value' => (string) ( false === get_option( 'mainwp_maximumInstallUpdateRequests' ) ? 3 : intval( get_option( 'mainwp_maximumInstallUpdateRequests' ) ) ),
            ),
            array(
                'label' => esc_html__( 'Maximum simultaneous uptime monitoring requests', 'mainwp' ),
                'value' => (string) ( false === get_option( 'mainwp_maximum_uptime_monitoring_requests' ) ? 10 : intval( get_option( 'mainwp_maximum_uptime_monitoring_requests' ) ) ),
            ),
            array(
                'label' => esc_html__( 'Sites processed per sync batch', 'mainwp' ),
                'value' => (string) ( false === get_option( 'mainwp_chunksitesnumber' ) ? 10 : intval( get_option( 'mainwp_chunksitesnumber' ) ) ),
            ),
            array(
                'label' => esc_html__( 'Sync batch sleep interval', 'mainwp' ),
                'value' => sprintf(
                    /* translators: %d: seconds */
                    esc_html__( '%d seconds', 'mainwp' ),
                    (int) ( false === get_option( 'mainwp_chunksleepinterval' ) ? 5 : intval( get_option( 'mainwp_chunksleepinterval' ) ) )
                ),
            ),
            array(
                'label' => esc_html__( 'Optimize data loading', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_optimize', $default_setting['mainwp_optimize'] ) ),
            ),
            array(
                'label' => esc_html__( 'Browser cache expiration time', 'mainwp' ),
                'value' => sprintf(
                    /* translators: %d: minutes */
                    esc_html__( '%d minutes', 'mainwp' ),
                    (int) get_option( 'mainwp_warm_cache_pages_ttl', 10 )
                ),
            ),
            array(
                'label' => esc_html__( 'Verify SSL certificate', 'mainwp' ),
                'value' => static::format_boolean_label( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || 1 === (int) get_option( 'mainwp_sslVerifyCertificate', 1 ) ),
            ),
            array(
                'label' => esc_html__( 'Verify connection method', 'mainwp' ),
                'value' => 2 === (int) get_option( 'mainwp_verify_connection_method', 1 ) ? esc_html__( 'PHPSECLIB (fallback)', 'mainwp' ) : esc_html__( 'OpenSSL (default)', 'mainwp' ),
            ),
            array(
                'label' => esc_html__( 'OpenSSL signature algorithm', 'mainwp' ),
                'value' => isset( $sign_algs[ $signature_algo ] ) ? $sign_algs[ $signature_algo ] : (string) $signature_algo,
            ),
            array(
                'label' => esc_html__( 'Force IPv4', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_forceUseIPv4', 0 ) ),
            ),
            array(
                'label' => esc_html__( 'Selected data to sync', 'mainwp' ),
                'value' => sprintf(
                    /* translators: 1: enabled count, 2: total count */
                    esc_html__( '%1$d of %2$d enabled', 'mainwp' ),
                    $enabled_sync,
                    count( $sync_defaults )
                ),
            ),
            ),
            array(
                'Maximum simultaneous requests',
                'Minimum delay between requests',
                'Maximum simultaneous requests per IP',
                'Minimum delay between requests to the same IP',
                'Maximum simultaneous sync requests',
                'Maximum simultaneous install and update requests',
                'Maximum simultaneous uptime monitoring requests',
                'Sites processed per sync batch',
                'Sync batch sleep interval',
                'Optimize data loading',
                'Browser cache expiration time',
                'Verify SSL certificate',
                'Verify connection method',
                'OpenSSL signature algorithm',
                'Force IPv4',
                'Selected data to sync',
            )
        );
    }

    /**
     * Get monitoring settings rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_monitoring_settings_report_rows() {
        $monitoring_settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
        $global_option       = get_option( 'mainwp_global_uptime_monitoring_settings', array() );
        if ( ! is_array( $global_option ) ) {
            $global_option = array();
        }

        $interval_values = MainWP_Uptime_Monitoring_Edit::get_interval_values( false );
        $timeout_values  = MainWP_Uptime_Monitoring_Edit::get_timeout_values( false );
        $interval_key    = $monitoring_settings['interval'] ?? 60;
        $timeout_key     = $monitoring_settings['timeout'] ?? 60;
        $interval_value  = isset( $interval_values[ $interval_key ] ) ? $interval_values[ $interval_key ] : $interval_key;
        $timeout_value   = isset( $timeout_values[ $timeout_key ] ) ? $timeout_values[ $timeout_key ] : $timeout_key;
        $retention_days  = isset( $global_option['retention_limits'] ) ? intval( $global_option['retention_limits'] ) : 180;
        $site_health     = empty( get_option( 'mainwp_disableSitesHealthMonitoring', 1 ) );
        $threshold       = (int) get_option( 'mainwp_sitehealthThreshold', 80 );

        return self::add_export_labels_to_rows(
            array(
            array(
                'label' => esc_html__( 'Enable uptime monitoring', 'mainwp' ),
                'value' => static::format_boolean_label( ! empty( $monitoring_settings['active'] ) ),
            ),
            array(
                'label' => esc_html__( 'Monitor type', 'mainwp' ),
                'value' => static::format_monitor_type_label( $monitoring_settings['type'] ?? 'http' ),
            ),
            array(
                'label' => esc_html__( 'Method', 'mainwp' ),
                'value' => strtoupper( (string) ( $monitoring_settings['method'] ?? 'get' ) ),
            ),
            array(
                'label' => esc_html__( 'Interval', 'mainwp' ),
                'value' => (string) $interval_value,
            ),
            array(
                'label' => esc_html__( 'Timeout', 'mainwp' ),
                'value' => (string) $timeout_value,
            ),
            array(
                'label' => esc_html__( 'Up HTTP codes', 'mainwp' ),
                'value' => ! empty( $monitoring_settings['up_status_codes'] ) ? (string) $monitoring_settings['up_status_codes'] : esc_html__( 'Default', 'mainwp' ),
            ),
            array(
                'label' => esc_html__( 'Down confirmation check', 'mainwp' ),
                'value' => ! empty( $monitoring_settings['maxretries'] ) ? esc_html__( 'Enabled', 'mainwp' ) : esc_html__( 'Disabled', 'mainwp' ),
            ),
            array(
                'label' => esc_html__( 'Keyword monitoring value', 'mainwp' ),
                'value' => ! empty( $monitoring_settings['keyword'] ) ? (string) $monitoring_settings['keyword'] : esc_html__( 'Not configured', 'mainwp' ),
            ),
            array(
                'label' => esc_html__( 'Monitoring data retention', 'mainwp' ),
                'value' => static::format_retention_days_label( $retention_days ),
            ),
            array(
                'label' => esc_html__( 'Enable site health monitoring', 'mainwp' ),
                'value' => static::format_boolean_label( $site_health ),
            ),
            array(
                'label' => esc_html__( 'Site Health threshold', 'mainwp' ),
                'value' => 100 === $threshold ? esc_html__( 'Good', 'mainwp' ) : esc_html__( 'Should be improved', 'mainwp' ),
            ),
            ),
            array(
                'Enable uptime monitoring',
                'Monitor type',
                'Method',
                'Interval',
                'Timeout',
                'Up HTTP codes',
                'Down confirmation check',
                'Keyword monitoring value',
                'Monitoring data retention',
                'Enable site health monitoring',
                'Site Health threshold',
            )
        );
    }

    /**
     * Get email settings rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_email_settings_report_rows() {
        $rows  = array();
        $types = MainWP_Notification_Settings::get_notification_types();

        foreach ( $types as $type => $label ) {
            $settings         = MainWP_Notification_Settings::get_general_email_settings( $type );
            $recipients       = isset( $settings['recipients'] ) ? trim( (string) $settings['recipients'] ) : '';
            $recipient_count  = static::count_recipients( $recipients );
            $subject_config   = ! empty( $settings['subject'] ) ? esc_html__( 'Subject set', 'mainwp' ) : esc_html__( 'Subject empty', 'mainwp' );
            $heading_config   = ! empty( $settings['heading'] ) ? esc_html__( 'Heading set', 'mainwp' ) : esc_html__( 'Heading empty', 'mainwp' );
            $enabled_disabled = empty( $settings['disable'] ) ? esc_html__( 'Enabled', 'mainwp' ) : esc_html__( 'Disabled', 'mainwp' );

            $value = sprintf(
                /* translators: 1: enabled state, 2: recipients count, 3: subject state, 4: heading state */
                esc_html__( '%1$s | %2$d recipient(s) | %3$s | %4$s', 'mainwp' ),
                $enabled_disabled,
                $recipient_count,
                $subject_config,
                $heading_config
            );

            $rows[] = array(
                'label'        => $label,
                'export_label' => self::get_notification_type_export_label( $type, $label ),
                'value'        => $value,
            );
        }

        return $rows;
    }

    /**
     * Get cost tracker rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_cost_tracker_report_rows() {
        if ( ! class_exists( '\MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Utility' ) || ! class_exists( '\MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Admin' ) ) {
            return array();
        }

        $selected_currency      = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Utility::get_instance()->get_option( 'currency', 'USD' );
        $currency_format        = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Utility::get_instance()->get_option( 'currency_format', array() );
        $currency_format        = array_merge( \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Utility::default_currency_settings(), is_array( $currency_format ) ? $currency_format : array() );
        $custom_payment_methods = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Utility::get_instance()->get_option( 'custom_payment_methods', array(), true );
        $product_types          = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Admin::get_product_type_icons();

        if ( ! is_array( $custom_payment_methods ) ) {
            $custom_payment_methods = array();
        }
        if ( ! is_array( $product_types ) ) {
            $product_types = array();
        }

        return self::add_export_labels_to_rows(
            array(
            array(
                'label' => esc_html__( 'Currency', 'mainwp' ),
                'value' => (string) $selected_currency,
            ),
            array(
                'label' => esc_html__( 'Currency position', 'mainwp' ),
                'value' => (string) $currency_format['currency_position'],
            ),
            array(
                'label' => esc_html__( 'Thousand separator', 'mainwp' ),
                'value' => '' === (string) $currency_format['thousand_separator'] ? esc_html__( 'None', 'mainwp' ) : (string) $currency_format['thousand_separator'],
            ),
            array(
                'label' => esc_html__( 'Decimal separator', 'mainwp' ),
                'value' => (string) $currency_format['decimal_separator'],
            ),
            array(
                'label' => esc_html__( 'Decimals', 'mainwp' ),
                'value' => (string) intval( $currency_format['decimals'] ),
            ),
            array(
                'label' => esc_html__( 'Product categories', 'mainwp' ),
                'value' => sprintf(
                    /* translators: %d: count */
                    esc_html__( '%d configured', 'mainwp' ),
                    count( $product_types )
                ),
            ),
            array(
                'label' => esc_html__( 'Payment methods', 'mainwp' ),
                'value' => sprintf(
                    /* translators: %d: count */
                    esc_html__( '%d configured', 'mainwp' ),
                    count( $custom_payment_methods )
                ),
            ),
            ),
            array(
                'Currency',
                'Currency position',
                'Thousand separator',
                'Decimal separator',
                'Decimals',
                'Product categories',
                'Payment methods',
            )
        );
    }

    /**
     * Get insights rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_insights_report_rows() {
        $options = get_option( 'mainwp_module_log_settings', array() );
        if ( ! is_array( $options ) ) {
            $options = array();
        }

        $dashboard_disabled = 0;
        $child_disabled     = 0;
        $changes_disabled   = 0;

        if ( class_exists( '\MainWP\Dashboard\Module\Log\Log_Settings' ) ) {
            $dashboard_disabled = count( \MainWP\Dashboard\Module\Log\Log_Settings::get_disabled_logs_type( 'dashboard' ) );
            $child_disabled     = count( \MainWP\Dashboard\Module\Log\Log_Settings::get_disabled_logs_type( 'nonmainwpchanges' ) );
            $changes_disabled   = count( \MainWP\Dashboard\Module\Log\Log_Settings::get_disabled_logs_type( 'changeslogs' ) );
        }

        return self::add_export_labels_to_rows(
            array(
            array(
                'label' => esc_html__( 'Enable Network Activity logging', 'mainwp' ),
                'value' => static::format_boolean_label( ! empty( $options['enabled'] ) ),
            ),
            array(
                'label' => esc_html__( 'Automatically archive logs', 'mainwp' ),
                'value' => static::format_boolean_label( ! empty( $options['auto_archive'] ) ),
            ),
            array(
                'label' => esc_html__( 'Data retention period', 'mainwp' ),
                'value' => static::format_log_retention_label( isset( $options['records_logs_ttl'] ) ? intval( $options['records_logs_ttl'] ) : 3 * YEAR_IN_SECONDS ),
            ),
            array(
                'label' => esc_html__( 'Child-site Network Activity retention', 'mainwp' ),
                'value' => static::format_retention_days_label( isset( $options['child_logs_ttl'] ) ? intval( $options['child_logs_ttl'] ) : 7 ),
            ),
            array(
                'label' => esc_html__( 'Disabled dashboard event types', 'mainwp' ),
                'value' => (string) $dashboard_disabled,
            ),
            array(
                'label' => esc_html__( 'Disabled child-site event types', 'mainwp' ),
                'value' => (string) $child_disabled,
            ),
            array(
                'label' => esc_html__( 'Disabled change-log event types', 'mainwp' ),
                'value' => (string) $changes_disabled,
            ),
            ),
            array(
                'Enable Network Activity logging',
                'Automatically archive logs',
                'Data retention period',
                'Child-site Network Activity retention',
                'Disabled dashboard event types',
                'Disabled child-site event types',
                'Disabled change-log event types',
            )
        );
    }

    /**
     * Get API backups rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_api_backups_report_rows() {
        if ( ! class_exists( '\MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party' ) ) {
            return array();
        }

        $definitions = array(
            'cloudways'    => array(
                'label'          => 'Cloudways',
                'enabled_option' => 'mainwp_enable_cloudways_api',
                'options'        => array( 'mainwp_cloudways_api_account_email' ),
                'secrets'        => array( array( '\MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party', 'get_cloudways_api_key' ) ),
            ),
            'gridpane'     => array(
                'label'          => 'GridPane',
                'enabled_option' => 'mainwp_enable_gridpane_api',
                'options'        => array(),
                'secrets'        => array( array( '\MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party', 'get_gridpane_api_key' ) ),
            ),
            'vultr'        => array(
                'label'          => 'Vultr',
                'enabled_option' => 'mainwp_enable_vultr_api',
                'options'        => array(),
                'secrets'        => array( array( '\MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party', 'get_vultr_api_key' ) ),
            ),
            'linode'       => array(
                'label'          => 'Akamai (Linode)',
                'enabled_option' => 'mainwp_enable_linode_api',
                'options'        => array(),
                'secrets'        => array( array( '\MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party', 'get_linode_api_key' ) ),
            ),
            'digitalocean' => array(
                'label'          => 'DigitalOcean',
                'enabled_option' => 'mainwp_enable_digitalocean_api',
                'options'        => array(),
                'secrets'        => array( array( '\MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party', 'get_digitalocean_api_key' ) ),
            ),
            'cpanel'       => array(
                'label'          => 'cPanel (WP Toolkit)',
                'enabled_option' => 'mainwp_enable_cpanel_api',
                'options'        => array( 'mainwp_cpanel_url', 'mainwp_cpanel_site_path', 'mainwp_cpanel_account_username' ),
                'secrets'        => array(),
            ),
            'plesk'        => array(
                'label'          => 'Plesk',
                'enabled_option' => 'mainwp_enable_plesk_api',
                'options'        => array( 'mainwp_plesk_api_url' ),
                'secrets'        => array( array( '\MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party', 'get_plesk_api_key' ) ),
            ),
            'kinsta'       => array(
                'label'          => 'Kinsta',
                'enabled_option' => 'mainwp_enable_kinsta_api',
                'options'        => array( 'mainwp_kinsta_api_account_email', 'mainwp_kinsta_company_id' ),
                'secrets'        => array( array( '\MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party', 'get_kinsta_api_key' ) ),
            ),
        );

        $rows = array();

        foreach ( $definitions as $provider ) {
            $enabled = ! empty( get_option( $provider['enabled_option'], 0 ) );
            $details = array();

            foreach ( $provider['options'] as $option_name ) {
                if ( '' !== trim( (string) get_option( $option_name, '' ) ) ) {
                    $details[] = esc_html__( 'profile configured', 'mainwp' );
                    break;
                }
            }

            foreach ( $provider['secrets'] as $secret_callback ) {
                $secret = call_user_func( $secret_callback );
                if ( ! empty( $secret ) ) {
                    $details[] = esc_html__( 'secret configured', 'mainwp' );
                    break;
                }
            }

            $summary = $enabled ? esc_html__( 'Enabled', 'mainwp' ) : esc_html__( 'Disabled', 'mainwp' );
            if ( ! empty( $details ) ) {
                $summary .= ' | ' . implode( ', ', array_unique( $details ) );
            }

            $rows[] = array(
                'label' => $provider['label'],
                'value' => $summary,
            );
        }

        return $rows;
    }

    /**
     * Get tools rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_tools_report_rows() {
        return self::add_export_labels_to_rows(
            array(
            array(
                'label' => esc_html__( 'Current MainWP theme', 'mainwp' ),
                'value' => MainWP_Settings::get_instance()->get_current_user_theme(),
            ),
            array(
                'label' => esc_html__( 'Guided tours', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_enable_guided_tours', 0 ) ),
            ),
            array(
                'label' => esc_html__( 'Chatbase', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_enable_guided_chatbase', 0 ) ),
            ),
            array(
                'label' => esc_html__( 'Guided video', 'mainwp' ),
                'value' => static::format_boolean_label( (int) get_option( 'mainwp_enable_guided_video', 0 ) ),
            ),
            ),
            array(
                'Current MainWP theme',
                'Guided tours',
                'Chatbase',
                'Guided video',
            )
        );
    }

    /**
     * Get debug rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_debug_report_rows() {
        $error_log_path = ini_get( 'error_log' );
        $log_readable   = ! empty( $error_log_path ) && file_exists( $error_log_path ) ? is_readable( $error_log_path ) : false;

        return self::add_export_labels_to_rows(
            array(
            array(
                'label' => esc_html__( 'WP_DEBUG', 'mainwp' ),
                'value' => static::format_boolean_label( defined( 'WP_DEBUG' ) && WP_DEBUG ),
            ),
            array(
                'label' => esc_html__( 'WP_DEBUG_LOG', 'mainwp' ),
                'value' => static::format_boolean_label( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ),
            ),
            array(
                'label' => esc_html__( 'WP_DEBUG_DISPLAY', 'mainwp' ),
                'value' => static::format_boolean_label( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ),
            ),
            array(
                'label' => esc_html__( 'PHP log_errors', 'mainwp' ),
                'value' => static::format_boolean_label( filter_var( ini_get( 'log_errors' ), FILTER_VALIDATE_BOOLEAN ) ),
            ),
            array(
                'label'           => esc_html__( 'PHP error_log path', 'mainwp' ),
                'value'           => ! empty( $error_log_path ) ? $error_log_path : esc_html__( 'Not set', 'mainwp' ),
                'visibility'      => 'community_masked',
                'community_value' => ! empty( $error_log_path ) ? esc_html__( 'Configured', 'mainwp' ) : esc_html__( 'Not set', 'mainwp' ),
            ),
            array(
                'label' => esc_html__( 'PHP error_log readable', 'mainwp' ),
                'value' => static::format_boolean_label( $log_readable ),
            ),
            ),
            array(
                'WP_DEBUG',
                'WP_DEBUG_LOG',
                'WP_DEBUG_DISPLAY',
                'PHP log_errors',
                'PHP error_log path',
                'PHP error_log readable',
            )
        );
    }

    /**
     * Get conflict signal rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_conflict_signal_report_rows() {
        $caching_plugins     = static::get_active_plugins_by_keyword_group(
            array(
                'cache',
                'rocket',
                'litespeed',
                'autoptimize',
                'wp-optimize',
                'perfmatters',
                'flyingpress',
                'sg optimizer',
                'breeze',
                'nitropack',
                'hummingbird',
            )
        );
        $security_plugins    = static::get_active_plugins_by_keyword_group(
            array(
                'wordfence',
                'sucuri',
                'solid security',
                'ithemes security',
                'all-in-one wp security',
                'shield security',
                'disable rest api',
                'rest api control',
                'wp hide',
                'cloudflare',
                'limit login',
            )
        );
        $maintenance_plugins = static::get_active_plugins_by_keyword_group(
            array(
                'maintenance',
                'coming soon',
                'seedprod',
                'lightstart',
            )
        );
        $maintenance_mode    = static::get_maintenance_mode_indicators();
        $dropins             = static::get_dropin_conflict_indicators();

        return self::add_export_labels_to_rows(
            array(
            array(
                'label' => esc_html__( 'Caching / performance plugins', 'mainwp' ),
                'value' => static::format_conflict_items_label( $caching_plugins ),
            ),
            array(
                'label' => esc_html__( 'Security / access-control plugins', 'mainwp' ),
                'value' => static::format_conflict_items_label( $security_plugins ),
            ),
            array(
                'label'  => esc_html__( 'Maintenance mode indicators', 'mainwp' ),
                'value'  => static::format_conflict_items_label( $maintenance_mode ),
                'status' => empty( $maintenance_mode ) ? '' : 'warning',
            ),
            array(
                'label' => esc_html__( 'Drop-ins / object cache', 'mainwp' ),
                'value' => static::format_conflict_items_label( $dropins ),
            ),
            ),
            array(
                'Caching / performance plugins',
                'Security / access-control plugins',
                'Maintenance mode indicators',
                'Drop-ins / object cache',
            )
        );
    }

    /**
     * Format boolean label.
     *
     * @param mixed $value True-ish value.
     *
     * @return string
     */
    private static function format_boolean_label( $value ) {
        return ! empty( $value ) ? esc_html__( 'Yes', 'mainwp' ) : esc_html__( 'No', 'mainwp' );
    }

    /**
     * Get timezone label.
     *
     * @return string
     */
    private static function get_timezone_label() {
        $timezone = wp_timezone_string();
        if ( ! empty( $timezone ) ) {
            return $timezone;
        }

        $gmt_offset = (float) get_option( 'gmt_offset', 0 );
        $tz_sign    = $gmt_offset >= 0 ? '+' : '-';
        $tz_value   = (string) abs( $gmt_offset );

        return sprintf( 'UTC%s%s', $tz_sign, $tz_value );
    }

    /**
     * Get trusted update mode label.
     *
     * @param mixed $value Setting value.
     *
     * @return string
     */
    private static function get_trusted_update_mode_label( $value ) {
        return 1 === (int) $value ? esc_html__( 'Install Trusted Updates', 'mainwp' ) : esc_html__( 'Disabled', 'mainwp' );
    }

    /**
     * Get archive format label.
     *
     * @param mixed $format Archive format.
     *
     * @return string
     */
    private static function format_archive_format_label( $format ) {
        $format = empty( $format ) ? 'tar.gz' : (string) $format;
        return $format;
    }

    /**
     * Get delay label.
     *
     * @param int $days Days.
     *
     * @return string
     */
    private static function format_delay_label( $days ) {
        if ( $days <= 0 ) {
            return esc_html__( 'Delay off', 'mainwp' );
        }

        return sprintf(
            /* translators: %d: day count */
            _n( '%d day', '%d days', $days, 'mainwp' ),
            $days
        );
    }

    /**
     * Get automatic update day label.
     *
     * @param string $frequency Frequency.
     *
     * @return string
     */
    private static function get_automatic_updates_day_label( $frequency ) {
        switch ( $frequency ) {
            case 'weekly':
                $days = array(
                    0 => esc_html__( 'Mon', 'mainwp' ),
                    1 => esc_html__( 'Tue', 'mainwp' ),
                    2 => esc_html__( 'Wed', 'mainwp' ),
                    3 => esc_html__( 'Thu', 'mainwp' ),
                    4 => esc_html__( 'Fri', 'mainwp' ),
                    5 => esc_html__( 'Sat', 'mainwp' ),
                    6 => esc_html__( 'Sun', 'mainwp' ),
                );
                $day  = (int) get_option( 'mainwp_dayinweek_AutoUpdate', 0 );
                return isset( $days[ $day ] ) ? $days[ $day ] : esc_html__( 'Mon', 'mainwp' );
            case 'monthly':
                return sprintf(
                    /* translators: %d: day of month */
                    esc_html__( 'Day %d of the month', 'mainwp' ),
                    (int) get_option( 'mainwp_dayinmonth_AutoUpdate', 1 )
                );
            default:
                return esc_html__( 'Every day', 'mainwp' );
        }
    }

    /**
     * Get automatic updates schedule label.
     *
     * @return string
     */
    private static function get_automatic_updates_schedule_label() {
        $frequency = (string) get_option( 'mainwp_frequency_AutoUpdate', 'daily' );
        $time      = (string) get_option( 'mainwp_time_AutoUpdate', '00:00' );
        if ( empty( $time ) ) {
            $time = '00:00';
        }

        if ( 'weekly' === $frequency ) {
            return sprintf(
                /* translators: 1: day label, 2: time */
                esc_html__( 'Weekly on %1$s at %2$s', 'mainwp' ),
                static::get_automatic_updates_day_label( 'weekly' ),
                $time
            );
        }

        if ( 'monthly' === $frequency ) {
            return sprintf(
                /* translators: 1: day label, 2: time */
                esc_html__( 'Monthly on %1$s at %2$s', 'mainwp' ),
                static::get_automatic_updates_day_label( 'monthly' ),
                $time
            );
        }

        return sprintf(
            /* translators: %s: time */
            esc_html__( 'Daily at %s', 'mainwp' ),
            $time
        );
    }

    /**
     * Count recipients.
     *
     * @param string $recipients Recipients string.
     *
     * @return int
     */
    private static function count_recipients( $recipients ) {
        if ( empty( $recipients ) ) {
            return 0;
        }

        $emails = preg_split( '/\s*,\s*/', trim( $recipients ) );
        $emails = array_filter( (array) $emails );

        return count( $emails );
    }

    /**
     * Get public dashboard IP data.
     *
     * @return array<string,string>
     */
    private static function get_public_dashboard_ip_data() {
        $server_ip = static::get_server_ip_value();

        if ( static::is_public_ip_address( $server_ip ) ) {
            return array(
                'value'           => $server_ip,
                'status'          => 'pass',
                'community_value' => esc_html__( 'Detected', 'mainwp' ),
            );
        }

        $cached_data = get_transient( 'mainwp_public_dashboard_ip_data' );
        if ( is_array( $cached_data ) && isset( $cached_data['value'], $cached_data['status'], $cached_data['community_value'] ) ) {
            return $cached_data;
        }

        $response = wp_safe_remote_get(
            'https://api.ipify.org?format=json',
            array(
                'timeout'   => 3,
                'sslverify' => true,
            )
        );

        if ( ! is_wp_error( $response ) ) {
            $response_code = (int) wp_remote_retrieve_response_code( $response );
            $body          = json_decode( (string) wp_remote_retrieve_body( $response ), true );
            $public_ip     = is_array( $body ) && isset( $body['ip'] ) ? trim( (string) $body['ip'] ) : '';

            if ( $response_code >= 200 && $response_code < 300 && static::is_public_ip_address( $public_ip ) ) {
                $data = array(
                    'value'           => $public_ip,
                    'status'          => 'pass',
                    'community_value' => esc_html__( 'Detected', 'mainwp' ),
                );
                set_transient( 'mainwp_public_dashboard_ip_data', $data, 12 * HOUR_IN_SECONDS );

                return $data;
            }
        }

        $data = array(
            'value'           => esc_html__( 'Unavailable. Contact your host if the Server IP is local or private.', 'mainwp' ),
            'status'          => 'warning',
            'community_value' => esc_html__( 'Unavailable', 'mainwp' ),
        );
        set_transient( 'mainwp_public_dashboard_ip_data', $data, HOUR_IN_SECONDS );

        return $data;
    }

    /**
     * Get server IP value.
     *
     * @return string
     */
    private static function get_server_ip_value() {
        return isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
    }

    /**
     * Check if IP address is public.
     *
     * @param string $ip_address IP address.
     *
     * @return bool
     */
    private static function is_public_ip_address( $ip_address ) {
        return ! empty( $ip_address ) && false !== filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    /**
     * Get active plugins matching a keyword group.
     *
     * @param array<int,string> $keywords Keywords.
     *
     * @return array<int,string>
     */
    private static function get_active_plugins_by_keyword_group( $keywords ) {
        $matches = array();
        $plugins = get_plugins();

        foreach ( $plugins as $slug => $plugin ) {
            if ( ! is_plugin_active( $slug ) ) {
                continue;
            }

            $plugin_name = isset( $plugin['Name'] ) ? (string) $plugin['Name'] : '';
            $haystack    = strtolower( $slug . ' ' . $plugin_name );

            if ( false !== strpos( $haystack, 'mainwp' ) ) {
                continue;
            }

            foreach ( $keywords as $keyword ) {
                if ( false !== strpos( $haystack, strtolower( $keyword ) ) ) {
                    $matches[] = ! empty( $plugin_name ) ? $plugin_name : $slug;
                    break;
                }
            }
        }

        return array_values( array_unique( $matches ) );
    }

    /**
     * Get maintenance mode indicators.
     *
     * @return array<int,string>
     */
    private static function get_maintenance_mode_indicators() {
        $indicators = static::get_active_plugins_by_keyword_group(
            array(
                'maintenance',
                'coming soon',
                'seedprod',
                'lightstart',
            )
        );

        if ( file_exists( ABSPATH . '.maintenance' ) ) {
            $indicators[] = esc_html__( '.maintenance file present', 'mainwp' );
        }

        return array_values( array_unique( $indicators ) );
    }

    /**
     * Get drop-in conflict indicators.
     *
     * @return array<int,string>
     */
    private static function get_dropin_conflict_indicators() {
        $dropins = array();

        if ( function_exists( 'get_dropins' ) ) {
            $dropin_files = get_dropins();
            if ( is_array( $dropin_files ) ) {
                $dropins = array_keys( $dropin_files );
            }
        }

        if ( wp_using_ext_object_cache() && ! in_array( 'object-cache.php', $dropins, true ) ) {
            $dropins[] = 'object-cache.php';
        }

        return array_values( array_unique( $dropins ) );
    }

    /**
     * Format conflict items label.
     *
     * @param array<int,string> $items Items.
     *
     * @return string
     */
    private static function format_conflict_items_label( $items ) {
        if ( empty( $items ) ) {
            return esc_html__( 'None detected', 'mainwp' );
        }

        return implode( ', ', $items );
    }

    /**
     * Get permalink structure data.
     *
     * @return array<string,string>
     */
    private static function get_permalink_structure_data() {
        $structure = (string) get_option( 'permalink_structure', '' );

        if ( empty( $structure ) ) {
            return array(
                'value'  => esc_html__( 'Plain', 'mainwp' ),
                'status' => 'warning',
            );
        }

        return array(
            'value'  => $structure,
            'status' => 'pass',
        );
    }

    /**
     * Get REST API reachability data.
     *
     * @return array<string,string>
     */
    private static function get_rest_api_reachability_data() {
        static $cached_data = null;
        if ( null !== $cached_data ) {
            return $cached_data;
        }

        $url      = rest_url();
        $response = wp_remote_get(
            esc_url_raw( $url ),
            array(
                'blocking'  => true,
                'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
                'timeout'   => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            $cached_data = array(
                'value'  => sprintf(
                    /* translators: %s: error message */
                    esc_html__( 'Request failed: %s', 'mainwp' ),
                    $response->get_error_message()
                ),
                'status' => 'error',
                'code'   => 'request_failed',
            );
            return $cached_data;
        }

        $response_code = (int) wp_remote_retrieve_response_code( $response );
        $body          = wp_remote_retrieve_body( $response );
        $data          = json_decode( $body, true );

        if ( $response_code >= 200 && $response_code < 300 && is_array( $data ) && isset( $data['namespaces'] ) ) {
            $cached_data = array(
                'value'  => sprintf(
                    /* translators: %d: HTTP status code */
                    esc_html__( 'Reachable (HTTP %d)', 'mainwp' ),
                    $response_code
                ),
                'status' => 'pass',
            );
            return $cached_data;
        }

        if ( $response_code >= 200 && $response_code < 300 ) {
            $cached_data = array(
                'value'  => sprintf(
                    /* translators: %d: HTTP status code */
                    esc_html__( 'Unexpected non-REST response (HTTP %d)', 'mainwp' ),
                    $response_code
                ),
                'status' => 'warning',
            );
            return $cached_data;
        }

        if ( 401 === $response_code ) {
            $cached_data = array(
                'value'  => esc_html__( 'Auth challenge detected (HTTP 401)', 'mainwp' ),
                'status' => 'error',
            );
            return $cached_data;
        }

        if ( 403 === $response_code ) {
            $cached_data = array(
                'value'  => esc_html__( 'Forbidden or blocked (HTTP 403)', 'mainwp' ),
                'status' => 'error',
            );
            return $cached_data;
        }

        if ( 404 === $response_code ) {
            $cached_data = array(
                'value'  => esc_html__( 'Not found (HTTP 404)', 'mainwp' ),
                'status' => 'error',
            );
            return $cached_data;
        }

        $cached_data = array(
            'value'  => sprintf(
                /* translators: %d: HTTP status code */
                esc_html__( 'Unexpected response (HTTP %d)', 'mainwp' ),
                $response_code
            ),
            'status' => 'warning',
        );

        return $cached_data;
    }

    /**
     * Get self-connect data.
     *
     * @return array<string,string>
     */
    private static function get_server_self_connect_data() {
        static $cached_data = null;
        if ( null !== $cached_data ) {
            return $cached_data;
        }

        $url        = site_url( 'wp-cron.php' );
        $query_args = array( 'mainwp_run' => 'test' );
        $url        = esc_url_raw( add_query_arg( $query_args, $url ) );
        $response   = wp_remote_post(
            $url,
            array(
                'blocking'  => true,
                'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
                'timeout'   => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            $cached_data = array(
                'value'  => sprintf(
                    /* translators: %s: error message */
                    esc_html__( 'Request failed: %s', 'mainwp' ),
                    $response->get_error_message()
                ),
                'status' => 'error',
            );
            return $cached_data;
        }

        $response_code = (int) wp_remote_retrieve_response_code( $response );
        if ( 401 === $response_code ) {
            $cached_data = array(
                'value'  => esc_html__( 'Auth challenge detected (HTTP 401)', 'mainwp' ),
                'status' => 'error',
                'code'   => 'auth_challenge',
            );
            return $cached_data;
        }
        if ( 403 === $response_code ) {
            $cached_data = array(
                'value'  => esc_html__( 'Forbidden or blocked (HTTP 403)', 'mainwp' ),
                'status' => 'error',
                'code'   => 'forbidden',
            );
            return $cached_data;
        }
        if ( $response_code < 200 || $response_code > 204 ) {
            $cached_data = array(
                'value'  => sprintf(
                    /* translators: %d: HTTP status code */
                    esc_html__( 'Unexpected response (HTTP %d)', 'mainwp' ),
                    $response_code
                ),
                'status' => 'warning',
                'code'   => 'unexpected_status',
            );
            return $cached_data;
        }

        $response_body = wp_remote_retrieve_body( $response );
        if ( false === strstr( $response_body, 'MainWP Test' ) ) {
            $cached_data = array(
                'value'  => esc_html__( 'Unexpected response body. Note: This is common and usually not a problem unless WP-Cron or loopback requests are failing.', 'mainwp' ),
                'status' => 'warning',
                'code'   => 'unexpected_body',
            );
            return $cached_data;
        }

        $cached_data = array(
            'value'  => sprintf(
                /* translators: %d: HTTP status code */
                esc_html__( 'Response test O.K. (HTTP %d)', 'mainwp' ),
                $response_code
            ),
            'status' => 'pass',
            'code'   => 'pass',
        );

        return $cached_data;
    }

    /**
     * Get upload directory data.
     *
     * @return array<string,string>
     */
    private static function get_mainwp_upload_directory_data() {
        $dirs = MainWP_System_Utility::get_mainwp_dir();
        $path = isset( $dirs[0] ) ? $dirs[0] : '';

        if ( empty( $path ) || ! is_dir( dirname( $path ) ) || ! is_dir( $path ) ) {
            return array(
                'value'  => esc_html__( 'Not found', 'mainwp' ),
                'status' => 'error',
            );
        }

        if ( is_writable( $path ) ) {
            return array(
                'value'  => esc_html__( 'Writable', 'mainwp' ),
                'status' => 'pass',
            );
        }

        return array(
            'value'  => esc_html__( 'Not writable', 'mainwp' ),
            'status' => 'error',
        );
    }

    /**
     * Get primary backup method label.
     *
     * @return string
     */
    private static function get_primary_backup_method_label() {
        $primary_backup = get_option( 'mainwp_primaryBackup' );
        $primary_methods = apply_filters_deprecated( 'mainwp-getprimarybackup-methods', array( array() ), '4.0.7.2', 'mainwp_getprimarybackup_methods' ); // NOSONAR - not IP.
        $primary_methods = apply_filters( 'mainwp_getprimarybackup_methods', $primary_methods );

        if ( is_array( $primary_methods ) ) {
            foreach ( $primary_methods as $method ) {
                if ( isset( $method['value'], $method['title'] ) && $primary_backup === $method['value'] ) {
                    return (string) $method['title'];
                }
            }
        }

        return esc_html__( 'Native backups', 'mainwp' );
    }

    /**
     * Get signature algorithm label.
     *
     * @return string
     */
    private static function get_signature_algorithm_label() {
        $default_setting = MainWP_Settings_Indicator::get_defaults_value();
        $sign_algs       = MainWP_System_Utility::get_open_ssl_sign_algos();
        $signature_algo  = (int) get_option( 'mainwp_connect_signature_algo', $default_setting['mainwp_connect_signature_algo'] );

        return isset( $sign_algs[ $signature_algo ] ) ? $sign_algs[ $signature_algo ] : (string) $signature_algo;
    }

    /**
     * Get cron job summary.
     *
     * @param string $last_run_option Last run option name.
     * @param string $hook            Cron hook.
     * @param string $fallback_freq   Fallback frequency.
     *
     * @return array<string,string>
     */
    private static function get_cron_job_summary( $last_run_option, $hook, $fallback_freq ) {
        $local_timestamp = MainWP_Utility::get_timestamp();
        $use_wp_cron     = ( false === get_option( 'mainwp_wp_cron' ) ) || ( 1 === (int) get_option( 'mainwp_wp_cron', 1 ) );

        if ( 'mainwp_updatescheck_start_last_timestamp' === $last_run_option ) {
            $update_time = MainWP_Settings::get_websites_automatic_update_time();
            return array(
                'last' => isset( $update_time['last'] ) ? $update_time['last'] : esc_html__( 'Never', 'mainwp' ),
                'next' => isset( $update_time['next'] ) ? $update_time['next'] : esc_html__( 'Unknown', 'mainwp' ),
            );
        }

        $lasttime_run = get_option( $last_run_option );
        if ( false === $lasttime_run || empty( $lasttime_run ) ) {
            $last_run = esc_html__( 'Never', 'mainwp' );
        } elseif ( 'mainwp_uptimecheck_auto_main_counter_lasttime_started' === $last_run_option ) {
            $last_run = MainWP_Utility::format_timestamp( $lasttime_run );
        } else {
            $last_run = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $lasttime_run ) );
        }

        $next_run = '';
        if ( $use_wp_cron ) {
            $scheduled = wp_next_scheduled( $hook );
            if ( ! empty( $scheduled ) ) {
                $next_run = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $scheduled ) );
            }
        }

        if ( empty( $next_run ) && ! empty( $fallback_freq ) ) {
            $nexttime_run = MainWP_Server_Information::get_schedule_next_time_to_show( $fallback_freq, is_numeric( $lasttime_run ) ? (int) $lasttime_run : 0, $local_timestamp );
            if ( $nexttime_run < $local_timestamp + 3 * MINUTE_IN_SECONDS ) {
                $next_run = esc_html__( 'Any minute', 'mainwp' );
            } else {
                $next_run = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $nexttime_run ) );
            }
        }

        if ( empty( $next_run ) ) {
            $next_run = esc_html__( 'Unknown', 'mainwp' );
        }

        return array(
            'last' => $last_run,
            'next' => $next_run,
        );
    }

    /**
     * Get uptime monitoring summary row.
     *
     * @return array<string,string>|array<mixed>
     */
    private static function get_uptime_monitoring_summary() {
        $global = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
        if ( empty( $global['active'] ) && ! get_option( 'mainwp_individual_uptime_monitoring_schedule_enabled' ) ) {
            return array();
        }

        $summary = static::get_cron_job_summary( 'mainwp_uptimecheck_auto_main_counter_lasttime_started', 'mainwp_cronuptimemonitoringcheck_action', 'minutely' );

        return array(
            'label' => esc_html__( 'Uptime monitoring schedule', 'mainwp' ),
            'value' => sprintf(
                /* translators: 1: last run, 2: next run */
                esc_html__( 'Last run: %1$s | Next run: %2$s', 'mainwp' ),
                $summary['last'],
                $summary['next']
            ),
        );
    }

    /**
     * Format retention label from days.
     *
     * @param int $days Days.
     *
     * @return string
     */
    private static function format_retention_days_label( $days ) {
        if ( $days <= 0 ) {
            return esc_html__( 'Keep forever', 'mainwp' );
        }

        return sprintf(
            /* translators: %d: days */
            _n( '%d day', '%d days', $days, 'mainwp' ),
            $days
        );
    }

    /**
     * Format monitoring type label.
     *
     * @param string $type Monitor type.
     *
     * @return string
     */
    private static function format_monitor_type_label( $type ) {
        switch ( $type ) {
            case 'ping':
                return esc_html__( 'Ping', 'mainwp' );
            case 'keyword':
                return esc_html__( 'Keyword Monitoring', 'mainwp' );
            default:
                return esc_html__( 'HTTP(s)', 'mainwp' );
        }
    }

    /**
     * Format log retention label.
     *
     * @param int $seconds Retention in seconds.
     *
     * @return string
     */
    private static function format_log_retention_label( $seconds ) {
        if ( $seconds <= 0 ) {
            return esc_html__( 'Forever', 'mainwp' );
        }
        if ( 3 * YEAR_IN_SECONDS === $seconds ) {
            return esc_html__( 'Three years', 'mainwp' );
        }
        if ( 2 * YEAR_IN_SECONDS === $seconds ) {
            return esc_html__( 'Two years', 'mainwp' );
        }
        if ( YEAR_IN_SECONDS === $seconds ) {
            return esc_html__( 'Year', 'mainwp' );
        }
        if ( 6 * MONTH_IN_SECONDS === $seconds ) {
            return esc_html__( 'Half a year', 'mainwp' );
        }
        if ( 3 * MONTH_IN_SECONDS === $seconds ) {
            return esc_html__( 'Three months', 'mainwp' );
        }
        if ( 2 * MONTH_IN_SECONDS === $seconds ) {
            return esc_html__( 'Two months', 'mainwp' );
        }
        if ( MONTH_IN_SECONDS === $seconds ) {
            return esc_html__( 'One month', 'mainwp' );
        }

        return sprintf(
            /* translators: %d: days */
            _n( '%d day', '%d days', max( 1, (int) floor( $seconds / DAY_IN_SECONDS ) ), 'mainwp' ),
            max( 1, (int) floor( $seconds / DAY_IN_SECONDS ) )
        );
    }
}
