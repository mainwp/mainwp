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

		update_option( MainWP_Connect::CHILD_REQUEST_TIMEOUT_OPTION, 500 );
		$this->assertSame( 560000, MainWP_Connect::get_site_removal_ajax_timeout_ms() );
	}
}
