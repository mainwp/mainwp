<?php
/**
 * Isolated cURL responses keep these branch tests independent of DNS and servers.
 *
 * @package MainWP\Dashboard\Tests
 */
namespace MainWP\Dashboard {
    function ini_get( $name ) {
        if ( 'disable_functions' === $name ) {
            return $GLOBALS['input']['multi'] ? '' : 'curl_multi_exec';
        }
        return \ini_get( $name );
    }
    function curl_init() {
        $GLOBALS['handle'] = \curl_init();
        if ( 'bulk' === $GLOBALS['input']['mode'] ) {
            $index = count( $GLOBALS['handle_sites'] );
            $GLOBALS['handle_sites'][ spl_object_id( $GLOBALS['handle'] ) ] = $index;
        }
        return $GLOBALS['handle'];
    }
    function transport_fixture_response( $handle ) {
        if ( 'bulk' === $GLOBALS['input']['mode'] ) {
            return $GLOBALS['input']['sites'][ $GLOBALS['handle_sites'][ spl_object_id( $handle ) ] ];
        }
        return $GLOBALS['input'];
    }
    function curl_setopt( $handle, $option, $value ) {
        if ( 'bulk' === $GLOBALS['input']['mode'] && CURLOPT_URL === $option ) {
            $GLOBALS['request_urls'][ spl_object_id( $handle ) ][] = $value;
        }
        return \curl_setopt( $handle, $option, $value );
    }
    function curl_multi_add_handle( $multi, $handle ) {
        if ( 'bulk' === $GLOBALS['input']['mode'] ) {
            $GLOBALS['pending_handles'][] = $handle;
            return CURLM_OK;
        }
        return \curl_multi_add_handle( $multi, $handle );
    }
    function curl_multi_remove_handle( $multi, $handle ) {
        return 'bulk' === $GLOBALS['input']['mode'] ? CURLM_OK : \curl_multi_remove_handle( $multi, $handle );
    }
    function curl_exec( $handle ) { return transport_fixture_response( $handle )['body']; }
    function curl_error( $handle ) { return transport_fixture_response( $handle )['message']; }
    function curl_errno( $handle ) { return transport_fixture_response( $handle )['errno']; }
    function curl_getinfo( $handle, $option ) {
        if ( CURLINFO_HTTP_CODE === $option ) { return transport_fixture_response( $handle )['status']; }
        if ( CURLINFO_EFFECTIVE_URL === $option ) { return 'http://127.0.0.1/'; }
        return 0;
    }
    function curl_multi_exec( $handle, &$running ) { $running = 0; return CURLM_OK; }
    function curl_multi_select( $handle, $timeout = 1 ) { return 0; }
    function curl_multi_info_read( $handle ) {
        if ( 'bulk' === $GLOBALS['input']['mode'] ) {
            $handle = array_shift( $GLOBALS['pending_handles'] );
            return $handle ? array( 'handle' => $handle ) : false;
        }
        static $read = false;
        if ( $read ) { return false; }
        $read = true;
        return array( 'handle' => $GLOBALS['handle'] );
    }
    function curl_multi_getcontent( $handle ) { return transport_fixture_response( $handle )['body']; }
    function dns_get_record( $host, $type ) { return array(); }
    function gethostbynamel( $host ) { return array( '127.0.0.1' ); }
    class MainWP_System { public static $version = 'test'; }
    class MainWP_Demo_Handle {
        public static function get_instance() { return new self(); }
        public function is_demo_website( $site ) { return false; }
    }
    class MainWP_Logger {
        public static function instance() { return new self(); }
        public function __call( $name, $args ) {}
    }
    class MainWP_System_Utility {
        public static function get_mainwp_dir() { return array( sys_get_temp_dir() . '/' ); }
        public static function set_time_limit( $timeout ) {}
        public static function get_child_response( $data ) { return json_decode( $data, true ); }
        public static function get_http_version_const_str( $version ) { return ''; }
    }
    class MainWP_Execution_Helper {
        public static function execute_call_track( ...$args ) { return null; }
    }
    class MainWP_Utility {
        public static function end_session() {}
        public static function get_http_codes( $code ) { return ''; }
        public static function value_to_string( $value, $mode = 0 ) { return ''; }
    }
    class MainWP_Connect_Failure_Ux {
        public static function get_user_agent() { return 'Transport-test'; }
        public static function redact_url( $url ) { return $url; }
        public static function sanitize_diagnostic_text( $text ) { return $text; }
        public static function describe_received_response( $body ) { return ''; }
        public static function enrich_output( $output, $context ) { return $output; }
    }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    $input = json_decode( stream_get_contents( STDIN ), true );
    function __( $text, $domain ) { return $text; }
    function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ); }
    function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
    function get_option( $key, $default = false ) { return $default; }
    function wp_json_encode( $data ) { return json_encode( $data ); }
    function esc_html__( $text, $domain ) { return esc_html( $text ); }
    function apply_filters( $hook, $value, ...$args ) { return $value; }
    function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
    class WP_HTTP_Proxy { public function is_enabled() { return false; } }
    class Requests { public static function flatten( $headers ) { return array(); } }
    $root = dirname( __DIR__, 2 );
    require $root . '/class/class-mainwp-exception.php';
    require $root . '/class/class-mainwp-error-helper.php';
    require $root . '/class/class-mainwp-connect.php';
    require $root . '/class/class-mainwp-uptime-monitoring-connect.php';
    require $root . '/pages/page-mainwp-plugins-handler.php';
    class Readable_Transport_Connect extends \MainWP\Dashboard\MainWP_Connect {
        public static function init_cookiesdir( $dir ) {}
        public static function get_post_data_authed( &$website, $what, $params = null, $others = array() ) { return array(); }
    }
    if ( 'classifier' === $input['mode'] ) {
        echo json_encode( \MainWP\Dashboard\MainWP_Error_Helper::classify_transport_error( $input['errno'], $input['message'], $input['context'] ) );
    } elseif ( 'bulk' === $input['mode'] ) {
        $GLOBALS['handle_sites'] = array();
        $GLOBALS['pending_handles'] = array();
        $GLOBALS['request_urls'] = array();
        $websites = array();
        foreach ( $input['sites'] as $index => $response ) {
            $websites[] = (object) array( 'id' => $index + 1, 'url' => 'https://fixture-' . ( $index + 1 ) . '.test/', 'name' => 'Fixture', 'ssl_version' => 0 );
        }
        $output = empty( $input['array_output'] ) ? (object) array( 'errors' => array(), 'status' => 'all' ) : array( 'existing' => 'kept' );
        $received = array();
        $callback = static function ( $data, $site, &$result, $params ) use ( &$received, $input ) {
            $received[ $site->id ] = $data;
            if ( ! empty( $input['dashboard_handler'] ) ) {
                \MainWP\Dashboard\MainWP_Plugins_Handler::plugins_search_handler( $data, $site, $result );
            }
        };
        if ( ! empty( $input['body_only_callback'] ) ) {
            $callback = static function ( $data ) use ( &$received ) { $received[] = $data; };
        }
        Readable_Transport_Connect::fetch_urls_authed( $websites, 'fixture', array( 'unchanged' => true ), $callback, $output );
        $expected = array();
        foreach ( $input['sites'] as $response ) {
            $expected[] = Readable_Transport_Connect::batch_handler_response_data( $response['body'], $response['errno'], $response['message'], $response['status'] );
        }
        echo json_encode( array( 'received' => $received, 'expected' => $expected, 'output' => $output, 'request_urls' => array_values( $GLOBALS['request_urls'] ) ) );
    } elseif ( 'visit' === $input['mode'] ) {
        echo json_encode( Readable_Transport_Connect::try_visit( 'http://127.0.0.1/' ) );
    } elseif ( 'uptime' === $input['mode'] ) {
        $monitor = (object) array( 'monitor_id' => 1, 'type' => 'http', 'method' => 'get', 'timeout' => 60 );
        $output = (object) array( 'global_settings' => array(), 'requests_info' => array() );
        $callback = static function ( $data, $site, &$result, $params ) {
            $info   = $result->requests_info[1];
            $parser = new \MainWP\Dashboard\MainWP_Uptime_Monitoring_Connect();
            echo json_encode( array( 'info' => $info, 'parsed' => $parser->parse_response_status( $info['http_code'], $info['http_error'], $info['http_errno'] ) ) );
        };
        \MainWP\Dashboard\MainWP_Uptime_Monitoring_Connect::fetch_single_uptime_url( \curl_init(), $callback, $monitor, $output, array(), true );
    } else {
        $website = null;
        $output  = array();
        try {
            Readable_Transport_Connect::fetch_url_site( $website, 'http://127.0.0.1/', array(), false, null, null, null, 0, array(), $output );
        } catch ( \MainWP\Dashboard\MainWP_Exception $error ) {
            echo json_encode( array( 'code' => $error->getMessage(), 'extra' => $error->get_message_extra( false ), 'output' => $output ) );
        }
    }
}
