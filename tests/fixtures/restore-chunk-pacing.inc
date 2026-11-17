<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Standalone subprocess fixture.
/**
 * Standalone transport fixture; no network requests are executed.
 *
 * @package MainWP\Dashboard\Tests
 */

// phpcs:disable Squiz.Commenting.ClassComment, Squiz.Commenting.FunctionComment -- Minimal dependency stubs for the isolated transport.
// phpcs:disable Generic.Files.OneObjectStructurePerFile, Generic.CodeAnalysis.UnusedFunctionParameter, Universal.Files.SeparateFunctionsFromOO -- Transport stubs share one subprocess.
// phpcs:disable Universal.Namespaces, WordPress.Files.FileName.InvalidClassFileName -- Namespaced overrides precede the isolated entry point.
// phpcs:disable WordPress.WP.AlternativeFunctions -- WordPress is not loaded in this fixture.
namespace MainWP\Dashboard {
	function curl_multi_exec( $handle, &$running ) {
		$running = 0;
		return CURLM_OK; }
	function curl_multi_select( $handle, $timeout ) {
		return $GLOBALS['input']['ready']; }
	function curl_multi_info_read( $handle ) {
		return false; }
	function usleep( $microseconds ) {
		$GLOBALS['waits'][] = $microseconds; }
	function curl_multi_close( $handle ) {
		throw new \RuntimeException( 'pacing captured' ); }
	class MainWP_Logger {
		public static function instance() {
			return new self(); }
		public function debug_for_website( ...$args ) {}
	}
	class MainWP_System_Utility {
		public static function get_mainwp_dir() {
			return array( sys_get_temp_dir() . '/' ); }
		public static function set_time_limit( $timeout ) {}
	}
	class MainWP_Execution_Helper {
		public static function execute_call_track( ...$args ) {
			return null; }
	}
	class MainWP_Connect_Failure_Ux {
		public static function get_user_agent() {
			return 'restore-pacing-test'; }
		public static function redact_url( $url ) {
			return $url; }
	}
	class MainWP_Utility {
		public static function end_session() {}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'GB_IN_BYTES', 1073741824 );
	$input = json_decode( stream_get_contents( STDIN ), true );
	$waits = array();
	function get_option( $key, $fallback = false ) {
		return $fallback; }
	function apply_filters( $name, $value, ...$args ) {
		return $value; }
	class WP_HTTP_Proxy {
		public function is_enabled() {
			return false; }
	}
	class Requests {
		public static function flatten( $headers ) {
			return array(); }
	}
	require dirname( __DIR__, 2 ) . '/class/class-mainwp-connect.php';
	class RestorePacingTransport extends \MainWP\Dashboard\MainWP_Connect {
		public static function init_cookiesdir( $dir ) {}
		public static function is_valid_curl_handle( $handle ) {
			return true; }
	}
	try {
		$website = null;
		$others  = isset( $input['action'] ) ? array( 'function' => $input['action'] ) : array();
		RestorePacingTransport::fetch_url_site( $website, 'https://fixture.invalid/', array(), false, null, null, null, 0, $others );
		throw new \LogicException( 'Transport loop was not reached.' );
	} catch ( \RuntimeException $error ) {
		if ( 'pacing captured' !== $error->getMessage() ) {
			throw $error; }
	}
	echo json_encode( $waits );
}
