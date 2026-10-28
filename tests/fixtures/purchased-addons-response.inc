<?php
/**
 * Run the purchased add-ons page and license client without network or storage.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard {
	class MainWP_Logger {
		public static function instance() { return new self(); }
		public function debug( ...$args ) {}
	}
	class MainWP_Utility {
		public static function value_to_string( $value ) { return ''; }
		public static function get_dir_slug( $slug ) { return dirname( $slug ); }
	}
	class MainWP_Post_Handler {
		public static function instance() { return new self(); }
		public function secure_request( $action ) { $GLOBALS['probe_secured'] = $action; }
	}
	class MainWP_Extensions_Handler {
		public static function get_extensions() { return array(); }
		public static function get_extensions_disabled( $compatible = false ) { return array(); }
		public static function polish_string_name( $name ) { return $name; }
	}
	class MainWP_Extensions_View {
		public static function get_extension_groups() { return array( 'fixture' => 'Fixture group' ); }
		public static function get_available_extensions( $type ) {
			return array( array( 'product_id' => 'fixture-addon', 'slug' => 'fixture-addon/fixture.php', 'title' => 'Fixture add-on', 'type' => 'pro', 'model' => 'extension', 'group' => array( 'fixture' ), 'link' => 'https://example.test/addon' ) );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$input = json_decode( $argv[1], true, 512, JSON_THROW_ON_ERROR );
	$GLOBALS['probe_requests'] = array();
	$GLOBALS['probe_warnings'] = array();
	set_error_handler( static function ( $severity, $message ) {
		$GLOBALS['probe_warnings'][] = $message;
		return true;
	} );
	class WP_Error {
		public function __construct( private string $code, private string $message ) {}
		public function get_error_message() { return $this->message; }
	}
	class Purchased_Addons_Response_Stop extends \Error {}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function home_url() { return 'https://dashboard.example.test'; }
	function apply_filters( $hook, $value, ...$args ) { return $value; }
	function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
	function wp_unslash( $value ) { return $value; }
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function __( $value, $domain ) { return $value; }
	function esc_html__( $value, $domain ) { return esc_html( $value ); }
	function esc_attr( $value ) { return esc_html( $value ); }
	function esc_attr__( $value, $domain ) { return esc_attr( $value ); }
	function esc_url( $value ) { return esc_attr( $value ); }
	function get_option( $name ) { return 0; }
	function wp_remote_post( $url, $args ) {
		$GLOBALS['probe_requests'][] = $args;
		$input = $GLOBALS['input'];
		if ( isset( $input['error'] ) ) {
			return new WP_Error( 'http_request_failed', $input['error'] );
		}
		$purchased = empty( $input['owned'] ) ? array() : array( 'fixture-addon' => array( 'package' => 'https://updates.example.test/addon.zip' ) );
		return array( 'code' => $input['code'] ?? 200, 'body' => $input['body'] ?? json_encode( array( 'success' => true, 'purchased_data' => $purchased ) ) );
	}
	function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
	function wp_remote_retrieve_body( $response ) { return $response['body']; }
	function wp_send_json( $response ) {
		$GLOBALS['probe_response'] = $response;
		throw new Purchased_Addons_Response_Stop();
	}
	$root = dirname( __DIR__, 2 );
	require $root . '/class/class-mainwp-exception.php';
	require $root . '/class/class-mainwp-credential-render.php';
	require $root . '/class/class-mainwp-api-manager-key.php';
	require $root . '/class/class-mainwp-api-manager.php';
	require $root . '/pages/page-mainwp-extensions.php';
	$_POST = array( 'api_key' => 'fixture-license-key' );
	$verify = 'verify' === ( $input['operation'] ?? '' );
	try {
		if ( $verify ) {
			$GLOBALS['probe_response'] = \MainWP\Dashboard\MainWP_Api_Manager_Key::instance()->verify_api_key( array( 'api_key' => 'fixture-license-key' ) );
		} else {
			\MainWP\Dashboard\MainWP_Extensions::ajax_get_purchased_extensions();
		}
	} catch ( Purchased_Addons_Response_Stop $stop ) {
		// Capture the production AJAX response.
	} catch ( \MainWP\Dashboard\MainWP_Exception $exception ) {
		if ( ! $verify ) {
			throw $exception;
		}
		$GLOBALS['probe_response'] = array( 'error' => $exception->getMessage() );
	}
	echo json_encode( array( 'response' => $GLOBALS['probe_response'], 'sslverify' => $GLOBALS['probe_requests'][0]['sslverify'], 'requests' => count( $GLOBALS['probe_requests'] ), 'warnings' => $GLOBALS['probe_warnings'] ), JSON_THROW_ON_ERROR );
}
