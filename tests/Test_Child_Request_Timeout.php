<?php
/**
 * Child request timeout tests (MWP-1787).
 *
 * Covers the unset 72000 default, the deactivate cap, filter values below the
 * save floor, Advanced save (blank and 72000 delete the option), and the
 * fetch classifier (gateway statuses and cURL errno 28 versus a real missing
 * Child response and Imunify wsidchk).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_Error_Helper;
use MainWP\Dashboard\MainWP_Exception;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Child_Request_Timeout extends \WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION );
		parent::tearDown();
	}

	public function test_unset_option_keeps_total_timeout_at_72000(): void {
		delete_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION );
		$this->assertSame( 72000, MainWP_Connect::resolve_child_request_timeout( '' ) );
		$this->assertSame( 72000, MainWP_Connect::resolve_child_request_timeout( 'stats' ) );
	}

	public function test_filtered_timeout_is_not_raised_to_the_save_floor(): void {
		delete_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION );
		$clamp = static function () {
			return 120;
		};
		add_filter( 'mainwp_fetch_url_site_timeout', $clamp, PHP_INT_MAX );
		try {
			$this->assertSame( 120, MainWP_Connect::resolve_child_request_timeout( 'stats' ) );
			$this->assertSame( 120, MainWP_Connect::resolve_child_request_timeout( 'deactivate' ) );
		} finally {
			remove_filter( 'mainwp_fetch_url_site_timeout', $clamp, PHP_INT_MAX );
		}
	}

	public function test_deactivate_unset_base_is_300_and_saved_value_can_raise_it(): void {
		delete_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION );
		$this->assertSame( 300, MainWP_Connect::resolve_child_request_timeout( 'deactivate' ) );

		update_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION, 600 );
		$this->assertSame( 600, MainWP_Connect::resolve_child_request_timeout( 'stats' ) );
		$this->assertSame( 600, MainWP_Connect::resolve_child_request_timeout( 'deactivate' ) );
	}

	public function test_stored_default_is_ignored(): void {
		update_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION, 72000 );
		$this->assertSame( 0, MainWP_Connect::get_saved_child_request_timeout() );
		$this->assertSame( 72000, MainWP_Connect::resolve_child_request_timeout( 'stats' ) );
		$this->assertSame( 300, MainWP_Connect::resolve_child_request_timeout( 'deactivate' ) );
	}

	public function test_save_deletes_blank_and_72000_and_rejects_below_floor(): void {
		update_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION, 600 );

		$this->assertSame( 'delete', MainWP_Connect::save_child_request_timeout_setting( '' ) );
		$this->assertFalse( get_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION, false ) );

		$this->assertSame( 400, MainWP_Connect::save_child_request_timeout_setting( '400' ) );
		$this->assertSame( 400, (int) get_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION ) );

		$this->assertSame( 'delete', MainWP_Connect::save_child_request_timeout_setting( '72000' ) );
		$this->assertFalse( get_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION, false ) );

		update_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION, 500 );
		$this->assertFalse( MainWP_Connect::save_child_request_timeout_setting( '299' ) );
		$this->assertSame( 500, (int) get_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION ) );

		$this->assertSame( 300, MainWP_Connect::save_child_request_timeout_setting( 300 ) );
	}

	/** @dataProvider advanced_timeout_inputs */
	public function test_advanced_save_reports_timeout_validation_result( $input, $saved, $error ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION, 600 );
		$previous_post = $_POST;
		$_POST = array( 'submit' => '1', 'wp_nonce' => wp_create_nonce( 'SettingsAdvanced' ), 'mainwp_child_request_timeout' => $input );
		ob_start();
		try {
			\MainWP\Dashboard\MainWP_Settings::render_advanced();
		} finally {
			$html = ob_get_clean();
			$_POST = $previous_post;
		}
		$actual = get_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION, false );
		$this->assertSame( $saved, false === $actual ? false : (int) $actual );
		if ( $error ) {
			$this->assertMatchesRegularExpression( '/class="ui (?:red|error) message"/', $html );
			$this->assertStringContainsString( 'Child request timeout must be a whole number of seconds, at least 300.', $html );
			$this->assertStringNotContainsString( 'Settings have been saved successfully!', $html );
		} else {
			$this->assertStringContainsString( 'Settings have been saved successfully!', $html );
		}
	}

	public static function advanced_timeout_inputs(): array {
		return array(
			'below floor' => array( '299', 600, true ),
			'not numeric' => array( 'invalid', 600, true ),
			'valid' => array( '450', 450, false ),
			'blank default' => array( '', false, false ),
			'explicit default' => array( '72000', false, false ),
		);
	}

	public function test_gateway_and_curl_timeouts_are_timeout_and_missing_plugin_is_not(): void {
		$missing = '<html><body>MainWP Child plugin is not installed.</body></html>';
		$this->assertSame( 'NOMAINWP', MainWP_Connect::child_fetch_else_code( $missing, '', 200, 0 ) );
		$this->assertSame( '', MainWP_Connect::child_request_timeout_kind( 0, '', 200, $missing ) );

		foreach ( array( 408, 504, 522, 524 ) as $status ) {
			$this->assertSame( 'TIMEOUT', MainWP_Connect::child_fetch_else_code( '<html>timeout page</html>', '', $status, 0 ) );
			$this->assertSame( 'gateway', MainWP_Connect::child_request_timeout_kind( 0, '', $status, '<html>timeout page</html>' ) );
		}

		$this->assertSame( 'operation', MainWP_Connect::child_request_timeout_kind( 28, 'Operation timed out after 300000 milliseconds with 0 bytes received', 0, false ) );
		$this->assertSame( 'connect', MainWP_Connect::child_request_timeout_kind( 28, 'Connection timed out after 10012 milliseconds', 0, false ) );

		$wsidchk = '<html>wsidchk security check</html>';
		$this->assertSame( 'WSIDCHK', MainWP_Connect::child_fetch_else_code( $wsidchk, '', 524, 0 ) );
		$this->assertSame( '', MainWP_Connect::child_request_timeout_kind( 0, '', 524, $wsidchk ) );
	}

	public function test_timeout_copy_has_no_url_ip_or_location_and_extra_is_empty(): void {
		$operation = new MainWP_Exception( 'TIMEOUT', '', 'operation' );
		$gateway   = new MainWP_Exception( 'TIMEOUT', '', 'gateway' );
		$connect   = new MainWP_Exception( 'TIMEOUT', '', 'connect' );

		$this->assertSame( '', $operation->get_message_extra( false ) );
		$this->assertSame( '', $gateway->get_message_extra( false ) );
		$this->assertSame( '', $connect->get_message_extra( false ) );

		$messages = array(
			MainWP_Error_Helper::get_error_message( $operation ),
			MainWP_Error_Helper::get_error_message( $gateway ),
			MainWP_Error_Helper::get_error_message( $connect ),
		);

		$this->assertStringContainsString( 'Settings → Advanced Settings', $messages[0] );
		$this->assertStringNotContainsString( 'Settings → Advanced Settings', $messages[1] );
		$this->assertStringNotContainsString( 'Settings → Advanced Settings', $messages[2] );

		foreach ( $messages as $message ) {
			$this->assertStringNotContainsString( 'http', strtolower( $message ) );
			$this->assertStringNotContainsString( 'Location', $message );
			$this->assertStringNotContainsString( 'Child request timeout does not', $messages[0] );
		}

		$this->assertStringContainsString( 'does not fix this response', $messages[1] );
		$this->assertStringContainsString( 'Test Connection', $messages[2] );
	}

	public function test_site_removal_ajax_timeout_grows_only_above_360_seconds(): void {
		delete_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION );
		$this->assertSame( 360000, MainWP_Connect::get_site_removal_ajax_timeout_ms() );

		update_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION, 350 );
		$this->assertSame( 360000, MainWP_Connect::get_site_removal_ajax_timeout_ms() );

		update_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION, 360 );
		$this->assertSame( 420000, MainWP_Connect::get_site_removal_ajax_timeout_ms() );

		update_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION, 500 );
		$this->assertSame( 560000, MainWP_Connect::get_site_removal_ajax_timeout_ms() );
	}

	public function test_partial_mainwp_body_is_timeout_and_complete_envelope_is_not(): void {
		$partial = '<mainwp>truncated-without-close';
		$this->assertFalse( MainWP_Connect::body_has_complete_mainwp_envelope( $partial ) );
		$this->assertSame( 'gateway', MainWP_Connect::child_request_timeout_kind( 0, '', 524, $partial ) );
		$this->assertSame( 'TIMEOUT', MainWP_Connect::child_fetch_else_code( $partial, '', 524, 0 ) );
		$this->assertSame( 'operation', MainWP_Connect::child_request_timeout_kind( 28, 'Operation timed out after 300000 milliseconds with 0 bytes received', 0, $partial ) );

		$complete = '<mainwp>abc</mainwp>';
		$this->assertTrue( MainWP_Connect::body_has_complete_mainwp_envelope( $complete ) );
		$this->assertSame( '', MainWP_Connect::child_request_timeout_kind( 0, '', 524, $complete ) );
		$this->assertSame( '', MainWP_Connect::child_request_timeout_kind( 28, 'Operation timed out after 300000 milliseconds with 0 bytes received', 200, $complete ) );
	}

	public function test_curl_init_failure_paths_omit_ip_and_url(): void {
		$this->assertFalse( MainWP_Connect::is_valid_curl_handle( false ) );

		$visit = MainWP_Connect::try_visit_curl_init_failure();
		$this->assertSame( 'cURL could not be initialized.', $visit['error'] );
		$this->assertSame( '', $visit['host'] );
		$this->assertArrayNotHasKey( 'ip', $visit );
		$this->assertStringNotContainsString( 'http', strtolower( $visit['error'] ) );

		$init_body = MainWP_Connect::handler_error_envelope( 'cURL could not be initialized.' );
		$this->assertTrue( MainWP_Connect::body_has_complete_mainwp_envelope( $init_body ) );
		$this->assertStringNotContainsString( 'http', strtolower( $init_body ) );

		$output = null;
		$thrown = false;
		try {
			MainWP_Connect::throw_fetch_url_site_curl_init_failure( $output );
		} catch ( MainWP_Exception $e ) {
			$thrown = true;
			$this->assertSame( 'HTTPERROR', $e->getMessage() );
			$this->assertSame( '', $e->get_data() );
			$this->assertStringNotContainsString( 'http', strtolower( (string) $e->get_message_extra( false ) ) );
		}
		$this->assertTrue( $thrown );
		$this->assertSame( 'curl_init_failed', $output['error_code'] );
	}

	public function test_batch_timeout_becomes_timeout_envelope_and_complete_body_is_kept(): void {
		$gateway = MainWP_Connect::batch_handler_response_data( '<html>timeout page</html>', 0, '', 522 );
		$this->assertTrue( MainWP_Connect::body_has_complete_mainwp_envelope( $gateway ) );
		$this->assertSame( 1, preg_match( '/<mainwp>(.*)<\/mainwp>/', $gateway, $parts ) );
		$decoded = json_decode( base64_decode( $parts[1] ), true );
		$this->assertIsArray( $decoded );
		$this->assertStringContainsString( 'does not fix this response', $decoded['error'] );
		$this->assertStringNotContainsString( 'http', strtolower( $decoded['error'] ) );
		$this->assertStringNotContainsString( 'NOMAINWP', $decoded['error'] );

		$operation = MainWP_Connect::batch_handler_response_data( false, 28, 'Operation timed out after 300000 milliseconds with 0 bytes received', 0 );
		$this->assertSame( 1, preg_match( '/<mainwp>(.*)<\/mainwp>/', $operation, $op_parts ) );
		$op_decoded = json_decode( base64_decode( $op_parts[1] ), true );
		$this->assertStringContainsString( 'Settings → Advanced Settings', $op_decoded['error'] );

		$complete = '<mainwp>abc</mainwp>';
		$this->assertSame( $complete, MainWP_Connect::batch_handler_response_data( $complete, 28, 'Operation timed out after 300000 milliseconds with 0 bytes received', 504 ) );

		$missing = '<html><body>MainWP Child plugin is not installed.</body></html>';
		$this->assertSame( $missing, MainWP_Connect::batch_handler_response_data( $missing, 0, '', 200 ) );
	}
}
