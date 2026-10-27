<?php
/**
 * Isolated add-on update security probe; no database or network is accessed.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard {
	class MainWP_Api_Manager {
		public static function instance() { return new self(); }
		public function get_domain() { return 'dashboard.example.test'; }
		public function get_upgrade_url() { return 'https://updates.example.test/'; }
	}
	class MainWP_Logger {
		public static function instance() { return new self(); }
		public function log_events( ...$args ) {}
		public function debug( ...$args ) {}
	}
	class MainWP_System {
		public static function instance() { return new self(); }
		public function get_dashboard_version() { return '6.4-rc.3'; }
	}
	class MainWP_Utility {
		public static function value_to_string( $value ) { return ''; }
	}
	class MainWP_Extensions {
		public static function get_class_name() { return self::class; }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$input = json_decode( $argv[1], true, 512, JSON_THROW_ON_ERROR );
	$GLOBALS['probe_hooks'] = array();
	$GLOBALS['probe_wakeup_calls'] = 0;
	$GLOBALS['probe_warnings'] = array();
	set_error_handler(
		static function ( $severity, $message ) {
			if ( error_reporting() & $severity ) {
				$GLOBALS['probe_warnings'][] = $message;
			}
			return true;
		}
	);

	class Addon_Update_Wakeup_Probe {
		public function __wakeup() { ++$GLOBALS['probe_wakeup_calls']; }
	}
	function esc_url_raw( $url ) { return $url; }
	function add_query_arg( $name, $value, $url ) { return $url . '?' . $name . '=' . $value; }
	function get_option( $name ) { return $GLOBALS['input']['option'] ?? false; }
	function mainwp_current_user_can( ...$args ) { return true; }
	function add_action( $hook, ...$args ) { $GLOBALS['probe_hooks'][] = $hook; }
	function apply_filters( $hook, $value, ...$args ) {
		return 'mainwp_api_manager_upgrade_package_url' === $hook ? $value . '?filtered=1' : $value;
	}
	function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
	function is_wp_error( $value ) { return false; }
	function wp_remote_get( $url, $args ) { return array( 'body' => $GLOBALS['probe_body'] ); }
	function wp_remote_post( $url, $args ) {
		$GLOBALS['probe_http_args'] = $args;
		return array( 'body' => '{"success":true}' );
	}
	function wp_remote_retrieve_response_code( $response ) { return 200; }
	function wp_remote_retrieve_body( $response ) { return $response['body']; }

	$root = dirname( __DIR__, 2 );
	require $root . '/class/class-mainwp-api-manager-plugin-update.php';
	require $root . '/class/class-mainwp-api-manager-key.php';
	require $root . '/pages/page-mainwp-extensions-handler.php';

	if ( 'licence' === $input['mode'] ) {
		$client = new \MainWP\Dashboard\MainWP_Api_Manager_Key();
		$response = $client->{$input['method']}( array() );
		echo json_encode( array( 'response' => $response, 'sslverify' => $GLOBALS['probe_http_args']['sslverify'] ), JSON_THROW_ON_ERROR );
		exit;
	}
	if ( 'download_filters' === $input['mode'] ) {
		$args = array( 'sslverify' => true, 'timeout' => 50 );
		echo json_encode(
			array(
				'expected' => $args,
				'install' => \MainWP\Dashboard\MainWP_Extensions_Handler::no_ssl_filter_function( $args ),
				'upgrade' => \MainWP\Dashboard\MainWP_Extensions_Handler::no_ssl_filter_extension_upgrade( $args, 'https://updates.example.test/?am_download_file=fixture&am_email=fixture' ),
				'unrelated' => \MainWP\Dashboard\MainWP_Extensions_Handler::no_ssl_filter_extension_upgrade( $args, 'https://example.test/' ),
			),
			JSON_THROW_ON_ERROR
		);
		exit;
	}
	if ( 'ajax_hooks' === $input['mode'] ) {
		require $root . '/class/class-mainwp-post-base-handler.php';
		require $root . '/class/class-mainwp-post-extension-handler.php';
		( new \MainWP\Dashboard\MainWP_Post_Extension_Handler() )->init();
		echo json_encode( array( 'hooks' => $GLOBALS['probe_hooks'] ), JSON_THROW_ON_ERROR );
		exit;
	}

	$expected = (object) array( 'slug' => 'fixture-addon', 'new_version' => '2.0', 'package' => 'https://updates.example.test/addon.zip' );
	if ( 'information' === $input['body'] ) {
		$expected->version = '2.0';
		$expected->sections = array( 'description' => 'Fixture description', 'changelog' => 'Fixture changelog' );
	} elseif ( 'api_error' === $input['body'] ) {
		$expected->package = '';
		$expected->errors = array( 'download_revoked' => 'download_revoked' );
	}
	$body = serialize( $expected );
	switch ( $input['body'] ) {
		case 'foreign_object': $body = serialize( new Addon_Update_Wakeup_Probe() ); break;
		case 'unknown_object': $body = 'O:24:"Unknown_Addon_Response_X":0:{}'; break;
		case 'nested_object':
			$expected->extra = new Addon_Update_Wakeup_Probe();
			$body = serialize( $expected );
			break;
		case 'malformed': $body = 'not serialized data'; break;
		case 'empty': $body = ''; break;
		case 'array': $body = serialize( array( 'package' => 'fixture.zip' ) ); break;
		case 'scalar': $body = serialize( 'fixture' ); break;
		case 'false': $body = serialize( false ); break;
		case 'too_deep':
			$deep = array();
			for ( $i = 0; $i < 70; ++$i ) { $deep = array( $deep ); }
			$expected->extra = $deep;
			$body = serialize( $expected );
			break;
		case 'bulk':
			$expected = array( 'fixture-addon' => array( 'new_version' => '2.0', 'package' => 'fixture.zip' ) );
			$body = json_encode( $expected );
			break;
	}
	$GLOBALS['probe_body'] = $body;
	$bulk = 'bulk' === $input['body'];
	$args = $bulk ? array( 'request' => 'bulkupdatecheck', 'json' => true ) : array( 'request' => 'pluginupdatecheck' );
	$client = new \MainWP\Dashboard\MainWP_Api_Manager_Plugin_Update();
	$response = 'information' === $input['body'] ? $client->request( $args ) : $client->plugin_information( $args, $bulk );
	if ( ! $bulk ) { $expected->package .= '?filtered=1'; }
	echo json_encode(
		array(
			'response' => $response,
			'expected' => $expected,
			'wakeup_calls' => $GLOBALS['probe_wakeup_calls'],
			'nested_class' => isset( $response->extra ) && is_object( $response->extra ) ? get_class( $response->extra ) : null,
			'warnings' => $GLOBALS['probe_warnings'],
		),
		JSON_THROW_ON_ERROR
	);
}
