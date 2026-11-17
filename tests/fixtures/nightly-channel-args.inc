<?php
/**
 * Isolated license requests and package downloads without WordPress storage.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard {
    class MainWP_Logger {
        public static function instance() { return new self(); }
        public function log_events( ...$args ) {}
        public function debug( ...$args ) {}
    }
    class MainWP_System {
        public static function instance() { return new self(); }
        public function get_dashboard_version() { return '6.4.1'; }
    }
    class MainWP_Keys_Manager {
        public static function instance() { return new self(); }
        public function get_keys_value( $name ) { return 'fixture-master-key'; }
    }
    class MainWP_Utility {
        public static function value_to_string( $value ) { return ''; }
    }
}

namespace {
    define( 'ABSPATH', __DIR__ . '/' );
    $input = json_decode( $argv[1], true, 512, JSON_THROW_ON_ERROR );
    $requests = array();
    $downloads = array();
    $filter_calls = array();
    $package_filters = array();
    $filters = array();

    class WP_Error {
        public $code;
        private $message;
        private $data;
        public function __construct( $code, $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
    function __( $value, $domain ) { return $value; }
    function home_url() { return 'https://dashboard.example.test'; }
    function esc_url_raw( $value ) { return $value; }
    function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
    function wp_strip_all_tags( $value ) { return trim( strip_tags( $value ) ); }
    function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $value ) ) ); }
    function wp_json_encode( $value ) { return json_encode( $value, JSON_THROW_ON_ERROR ); }
    function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
    function add_query_arg( $name, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $name . '=' . rawurlencode( $value ); }
    function get_option( $name, $default = false ) {
        return 'fixture-addon_APIManAdder' === $name ? array( 'api_key' => array( 'encrypted_val' => 'fixture-ciphertext' ) ) : $default;
    }
    function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['filters'][ $hook ][] = $callback; }
    function apply_filters( $hook, $value, ...$args ) {
        foreach ( $GLOBALS['filters'][ $hook ] ?? array() as $callback ) {
            $value = $callback( $value, ...$args );
        }
        return $value;
    }
    function wp_remote_get( $url, $args ) {
        $GLOBALS['requests'][] = $url;
        parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
        $package = isset( $query['channel'] ) && 'nightly' === $query['channel']
            ? 'https://mainwp.com/wp-json/mainwp-nightly/v1/addon-download?product=fixture&sig=fresh'
            : 'https://mainwp.com/?download=stable&sig=fresh';
        $response = array( 'new_version' => '2.0-rc.2', 'package' => $GLOBALS['input']['response_package'] ?? $package );
        if ( array_key_exists( 'changes', $GLOBALS['input'] ) ) {
            $response['changes'] = $GLOBALS['input']['changes'];
        }
        switch ( $GLOBALS['input']['object_changes'] ?? '' ) {
            case 'root': $response['changes'] = (object) array( 'version' => '2.0-rc.2' ); break;
            case 'build': $response['changes'] = array( (object) array( 'version' => '2.0-rc.2', 'items' => array() ) ); break;
            case 'item': $response['changes'] = array( array( 'version' => '2.0-rc.2', 'items' => array( (object) array( 'type' => 'fixed', 'text' => 'Fix.' ) ) ) ); break;
        }
        if ( ! empty( $GLOBALS['input']['api_error'] ) ) { $response['errors'] = $GLOBALS['input']['api_error']; }
        return array(
            'code' => $GLOBALS['input']['http_code'] ?? 200,
            'body' => 'bulkupdatecheck' === $query['request'] ? json_encode( array( 'fixture-addon' => $response ), JSON_THROW_ON_ERROR ) : serialize( (object) $response ),
        );
    }
    function wp_remote_post( $url, $args ) {
        $GLOBALS['requests'][] = $url;
        return array( 'code' => 200, 'body' => '{"success":true}' );
    }
    function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
    function wp_remote_retrieve_body( $response ) { return $response['body']; }
    function download_url( $url ) {
        $GLOBALS['downloads'][] = $url;
        return empty( $GLOBALS['input']['download_error'] ) ? '/tmp/mainwp-nightly-fixture.zip' : new WP_Error( 'fixture_download_failed' );
    }

    $root = dirname( __DIR__, 2 );
    require $root . '/class/class-mainwp-api-manager.php';
    require $root . '/class/class-mainwp-api-manager-key.php';
    require $root . '/class/class-mainwp-api-manager-plugin-update.php';
    require $root . '/class/class-mainwp-api-handler.php';
    require $root . '/class/class-mainwp-system-handler.php';
    require $root . '/pages/page-mainwp-extensions-handler.php';

    add_filter( 'mainwp_decrypt_key_value', static function () { return 'fixture-license'; } );
    add_filter( 'mainwp_api_manager_update_check_args', static function ( $args, $type ) {
        $GLOBALS['filter_calls'][] = $type;
        if ( $GLOBALS['input']['nightly'] ?? true ) { $args['channel'] = 'nightly'; }
        return $args;
    }, 10, 2 );
    add_filter( 'mainwp_api_manager_upgrade_package_url', static function ( $url ) {
        $GLOBALS['package_filters'][] = $url;
        return '' === $url ? $url : add_query_arg( 'filtered', '1', $url );
    } );

    $plugin = array( 'plugin_name' => 'fixture-addon', 'software_version' => '1.0', 'product_id' => 'fixture', 'api_key' => 'fixture-license', 'instance' => 'fixture-instance' );
    \MainWP\Dashboard\MainWP_Extensions_Handler::$extensions = array(
        array( 'api' => $input['api'] ?? 'fixture-addon', 'slug' => 'fixture-addon/fixture.php', 'version' => '1.0', 'software_version' => '1.0', 'product_id' => 'fixture', 'instance_id' => 'fixture-instance', 'apiManager' => 1, 'activated_key' => 'Activated' ),
    );
    $client = \MainWP\Dashboard\MainWP_Api_Manager_Plugin_Update::instance();
    $result = null;
    switch ( $input['mode'] ) {
        case 'args':
            $client->update_check( $plugin );
            $client->bulk_update_check( array( 'fixture-addon' => $plugin ) );
            break;
        case 'licenses':
            $client->update_check( $plugin );
            $requests = array();
            foreach ( array( 'activate', 'deactivate', 'grab_api_key', 'verify_api_key', 'get_purchased_software', 'purchase_software' ) as $method ) {
                \MainWP\Dashboard\MainWP_Api_Manager_Key::instance()->$method( array() );
            }
            break;
        case 'changes':
            $result = array(
                'single' => \MainWP\Dashboard\MainWP_API_Handler::get_upgrade_information( 'fixture-addon' ),
                'bulk' => \MainWP\Dashboard\MainWP_API_Handler::check_exts_upgrade()['fixture-addon'],
            );
            break;
        case 'refresh':
            $handler = ( new \ReflectionClass( \MainWP\Dashboard\MainWP_System_Handler::class ) )->newInstanceWithoutConstructor();
            $upgrader = (object) array( 'skin' => (object) array( 'options' => array( 'package' => 'skin-original' ) ) );
            $reply = $input['reply'] ?? false;
            if ( ! empty( $input['reply_error'] ) ) { $reply = new WP_Error( 'earlier_filter' ); }
            $extra = array_key_exists( 'plugin', $input ) ? ( null === $input['plugin'] ? array() : array( 'plugin' => $input['plugin'] ) ) : array( 'plugin' => 'fixture-addon/fixture.php' );
            $extra = $input['hook_extra'] ?? $extra;
            $result = $handler->hook_refresh_trusted_extension_package_url( $reply, $input['package'] ?? 'https://mainwp.com/?sig=expired', $upgrader, $extra );
            $skin_package = $upgrader->skin->options['package'];
            break;
    }
    echo json_encode( array( 'result' => $result, 'requests' => $requests, 'downloads' => $downloads, 'filter_calls' => $filter_calls, 'package_filters' => $package_filters, 'skin_package' => $skin_package ?? null, 'error_message' => is_wp_error( $result ) ? $result->get_error_message() : null, 'error_data' => is_wp_error( $result ) ? $result->get_error_data() : null ), JSON_THROW_ON_ERROR );
}
