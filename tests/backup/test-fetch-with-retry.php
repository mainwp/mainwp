<?php
/**
 * MainWP Fetch With Retry Tests
 *
 * Tests for the retry wrapper around child site requests, using
 * exponential backoff with a filterable delay so tests do not sleep.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Exception;

/**
 * Class MainWP_Fetch_With_Retry_Test
 */
class MainWP_Fetch_With_Retry_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Delays requested between attempts.
	 *
	 * @var array
	 */
	private $delays = array();

	/**
	 * Skip real sleeping and record requested delays.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->delays = array();
		add_filter(
			'mainwp_backup_retry_delay',
			function ( $delay ) {
				$this->delays[] = $delay;
				return 0; // Do not sleep in tests.
			}
		);
	}

	/**
	 * Mock the child to fail a number of times before succeeding.
	 *
	 * @param int   $failures Failures before success.
	 * @param array $success  Success payload.
	 *
	 * @return void
	 */
	private function mock_flaky_child( $failures, $success ) {
		$attempts = 0;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( &$attempts, $failures, $success ) {
				unset( $result, $website, $what );
				++$attempts;
				if ( $attempts <= $failures ) {
					throw new MainWP_Exception( 'attempt ' . $attempts . ' failed' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test mock.
				}
				return $success;
			},
			10,
			3
		);
	}

	/**
	 * A request that fails twice succeeds on the third attempt.
	 *
	 * @return void
	 */
	public function test_succeeds_on_third_attempt() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->mock_flaky_child( 2, array( 'result' => 'ok' ) );

		$result = MainWP_Connect::fetch_with_retry( $website, 'backup_preflight', array() );

		$this->assertSame( array( 'result' => 'ok' ), $result );
		$this->assertSame( array( 1, 2 ), $this->delays, 'Backoff must double between attempts.' );
	}

	/**
	 * Exhausted retries raise the retry-exhausted code with the last error.
	 *
	 * @return void
	 */
	public function test_exhausted_retries_throw_with_last_error() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->mock_flaky_child( 10, array( 'result' => 'never reached' ) );

		try {
			MainWP_Connect::fetch_with_retry( $website, 'backup_preflight', array() );
			$this->fail( 'Expected retry-exhausted exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_retry_exhausted', $e->get_message_error_code() );
			$this->assertStringContainsString( 'attempt 3 failed', $e->getMessage(), 'The last underlying error must be reported.' );
		}
	}

	/**
	 * A request that succeeds immediately never sleeps.
	 *
	 * @return void
	 */
	public function test_immediate_success_does_not_backoff() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->mock_flaky_child( 0, array( 'result' => 'ok' ) );

		$result = MainWP_Connect::fetch_with_retry( $website, 'backup_preflight', array() );

		$this->assertSame( array( 'result' => 'ok' ), $result );
		$this->assertSame( array(), $this->delays );
	}
}
