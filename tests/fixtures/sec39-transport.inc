<?php
/** Standalone fixture; the .inc suffix excludes it from PHPUnit discovery. */
/** Capture real cURL options in a subprocess before any remote transfer. */
namespace MainWP\Dashboard {
    function curl_init( ...$args ) { return \curl_init( ...$args ); }
    function curl_setopt( $handle, $option, $value ) {
        $GLOBALS['tls_options'][$option] = $value;
        \curl_setopt( $handle, $option, $value );
        if ( CURLOPT_SSLVERSION === $option ) { throw new \RuntimeException( 'captured' ); }
        return true;
    }
    class MainWP_Logger {
        public static function instance() { return new self(); }
        public function debug_for_website( ...$args ) {}
    }
    class MainWP_System_Utility {
        public static function get_mainwp_dir() { return array( sys_get_temp_dir() . '/' ); }
    }
    class MainWP_Execution_Helper {
        public static function execute_call_track( ...$args ) { return null; }
    }
    class MainWP_Connect_Failure_Ux {
        public static function get_user_agent() { return 'SEC39-local-test'; }
        public static function redact_url( $url ) { return $url; }
    }
}
namespace MainWP\Dashboard\Module\ApiBackups {
    function curl_init( ...$args ) { return \curl_init( ...$args ); }
    function curl_setopt( $handle, $option, $value ) {
        $GLOBALS['tls_options'][$option] = $value;
        return \curl_setopt( $handle, $option, $value );
    }
    function curl_exec( $handle ) { throw new \RuntimeException( 'captured' ); }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    $input = json_decode( stream_get_contents( STDIN ), true );
    function get_option( $key ) { return 'mainwp_sslVerifyCertificate' === $key ? $GLOBALS['input']['global'] : false; }
    function apply_filters( $name, $value, ...$args ) { return 'mainwp_api_backups_verify_ssl' === $name && isset( $GLOBALS['input']['filter'] ) ? $GLOBALS['input']['filter'] : $value; }
    class WP_HTTP_Proxy { public function is_enabled() { return false; } }
    require ( getenv( 'SEC39_PRODUCTION_ROOT' ) ?: dirname( __DIR__, 2 ) ) . '/class/class-mainwp-connect.php';
    require ( getenv( 'SEC39_PRODUCTION_ROOT' ) ?: dirname( __DIR__, 2 ) ) . '/modules/api-backups/classes/class-api-backups-3rd-party.php';
    class Sec39Transport extends \MainWP\Dashboard\MainWP_Connect { public static function init_cookiesdir( $dir ) {} }
    try {
        if ( ! empty( $input['provider'] ) ) {
            \MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party::call_vultr_api( 'GET', '/instances', 'fixture-only', null, false );
        } else {
            $website = null;
            Sec39Transport::fetch_url_site( $website, 'https://fixture.invalid/', array(), false, $input['policy'] );
        }
        throw new \LogicException( 'Transport was not intercepted' );
    } catch ( \RuntimeException $error ) {
        if ( 'captured' !== $error->getMessage() ) { throw $error; }
    }
    $options = $GLOBALS['tls_options'];
    $result = array( 'host' => $options[CURLOPT_SSL_VERIFYHOST], 'peer' => $options[CURLOPT_SSL_VERIFYPEER] );
    if ( ! empty( $input['local_url'] ) ) {
        $curl = \curl_init( $input['local_url'] );
        \curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYHOST => $result['host'], CURLOPT_SSL_VERIFYPEER => $result['peer'], CURLOPT_TIMEOUT => 3, CURLOPT_NOPROXY => '*' ) );
        $result['self_signed_ok'] = false !== \curl_exec( $curl );
        $result['errno'] = \curl_errno( $curl );
        \curl_close( $curl );
    }
    echo json_encode( $result );
}
