<?php
/**
 * Error codes returned by MainWP_Extensions_Handler::fetch_url_authed().
 *
 * A missing site is refused before any child request. That result uses
 * excep_fetch_url_authed_refused. An exception raised by the child request
 * keeps excep_fetch_url_authed. The child request is simulated with the
 * PHPUnit-only mainwp_fetch_url_authed_pre filter so the test does not open
 * a socket. The filter throws the same HTTPERROR the connect layer throws
 * when cURL reports a dropped connection.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Extensions_Handler;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Tests the two fetch_url_authed error codes.
 */
class Test_MWP_1837_Fetch_Url_Authed_Refusal extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Remove the test filter if a test leaves it in place.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'mainwp_fetch_url_authed_pre' );
		parent::tearDown();
	}

	/**
	 * A site that cannot be edited is refused before the child request.
	 *
	 * is_single_user() is always true, so can_edit_website() is false only
	 * when the site row is missing. That is the pre-dispatch refusal.
	 *
	 * @return void
	 */
	public function test_missing_site_returns_the_refusal_code_and_sends_nothing() {
		$dispatched = false;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function () use ( &$dispatched ) {
				$dispatched = true;
				return array( 'should-not-run' => true );
			},
			10,
			4
		);

		$result = MainWP_Extensions_Handler::fetch_url_authed( 999999999, 'stats', array() );

		$this->assertFalse( $dispatched, 'A refusal must return before the child request filter runs.' );
		$this->assertSame(
			array(
				'error'     => 'You can not edit this website.',
				'errorCode' => 'excep_fetch_url_authed_refused',
			),
			$result
		);
	}

	/**
	 * An exception from the child request keeps the original code.
	 *
	 * @return void
	 */
	public function test_dropped_connection_keeps_the_original_code() {
		$site_id = $this->create_test_site(
			array(
				'name' => 'Refusal code site',
				'url'  => 'https://fetch-url-authed-refusal.example/',
			)
		);

		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function () {
				throw new MainWP_Exception( 'HTTPERROR', 'Connection reset by peer' );
			},
			10,
			4
		);

		$result = MainWP_Extensions_Handler::fetch_url_authed( $site_id, 'stats', array() );

		$this->assertSame(
			array(
				'error'     => 'HTTP error - Connection reset by peer',
				'errorCode' => 'excep_fetch_url_authed',
			),
			$result
		);
	}

	/**
	 * A normal child response is returned as the connect layer produced it.
	 *
	 * @return void
	 */
	public function test_child_response_is_returned_unchanged() {
		$site_id = $this->create_test_site(
			array(
				'name' => 'Refusal code success site',
				'url'  => 'https://fetch-url-authed-success.example/',
			)
		);

		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function () {
				return array( 'success' => 1 );
			},
			10,
			4
		);

		$result = MainWP_Extensions_Handler::fetch_url_authed( $site_id, 'stats', array( 'list' => 'one' ) );

		$this->assertSame( array( 'success' => 1 ), $result );
	}
}
