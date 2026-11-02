<?php
/**
 * Error codes returned by MainWP_Extensions_Handler::fetch_url_authed().
 *
 * A missing site, or an existing site excluded by
 * mainwp_currentuserallowedaccesssites, is refused before any child
 * request. That result uses excep_fetch_url_authed_refused. An exception
 * from the site lookup or from the child request keeps
 * excep_fetch_url_authed. The child request is simulated with the
 * PHPUnit-only mainwp_fetch_url_authed_pre filter so the test does not open
 * a socket. The filter throws the same HTTPERROR the connect layer throws
 * when cURL reports a dropped connection.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Extensions_Handler;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Tests the two fetch_url_authed error codes.
 */
class Test_MWP_1837_Fetch_Url_Authed_Refusal extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Access-list callback registered by a test, removed in tearDown.
	 *
	 * @var callable|null
	 */
	private $allowed_sites_filter = null;

	/**
	 * Remove the test filters if a test leaves them in place.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'mainwp_fetch_url_authed_pre' );
		if ( null !== $this->allowed_sites_filter ) {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $this->allowed_sites_filter, 999 );
			$this->allowed_sites_filter = null;
		}
		parent::tearDown();
	}

	/**
	 * Register a late access-list filter and remember it for tearDown.
	 *
	 * @param callable $callback Filter callback.
	 *
	 * @return void
	 */
	private function filter_allowed_sites( $callback ) {
		$this->allowed_sites_filter = $callback;
		add_filter( 'mainwp_currentuserallowedaccesssites', $callback, 999 );
	}

	/**
	 * A missing site is refused before the child request.
	 *
	 * is_single_user() is always true, so can_edit_website() is false when
	 * get_website_by_id() returns nothing. A missing row is one such case.
	 * An existing row hidden by mainwp_currentuserallowedaccesssites is the
	 * other.
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
	 * An existing site excluded by the access filter is refused before the child request.
	 *
	 * get_website_by_id() applies mainwp_currentuserallowedaccesssites. A
	 * numeric allow-list that omits the site hides the row, so
	 * can_edit_website() is false even though the row is still stored.
	 *
	 * @return void
	 */
	public function test_excluded_existing_site_returns_the_refusal_code_and_sends_nothing() {
		$site_id = $this->create_test_site(
			array(
				'name' => 'Access excluded site',
				'url'  => 'https://fetch-url-authed-excluded.example/',
			)
		);

		$this->filter_allowed_sites(
			static function () use ( $site_id ) {
				return array( $site_id + 1 );
			}
		);

		$this->assertNotEmpty(
			MainWP_DB::instance()->get_website_by_id_unscoped( $site_id ),
			'The site row must exist so this is an access refusal, not a missing row.'
		);
		$this->assertEmpty( MainWP_DB::instance()->get_website_by_id( $site_id ) );

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

		$result = MainWP_Extensions_Handler::fetch_url_authed( $site_id, 'stats', array() );

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

	/**
	 * A MainWP_Exception from the site lookup keeps the original code.
	 *
	 * The lookup runs inside the same try as the child request, so a thrown
	 * exception is returned as excep_fetch_url_authed and no request is sent.
	 *
	 * @return void
	 */
	public function test_lookup_exception_keeps_the_original_code_and_sends_nothing() {
		$site_id = $this->create_test_site(
			array(
				'name' => 'Lookup exception site',
				'url'  => 'https://fetch-url-authed-lookup-exception.example/',
			)
		);

		$this->filter_allowed_sites(
			static function () {
				throw new MainWP_Exception( 'lookup failed' );
			}
		);

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

		$result = MainWP_Extensions_Handler::fetch_url_authed( $site_id, 'stats', array() );

		$this->assertFalse( $dispatched, 'A lookup exception must return before the child request filter runs.' );
		$this->assertSame(
			array(
				'error'     => 'lookup failed',
				'errorCode' => 'excep_fetch_url_authed',
			),
			$result
		);
	}
}
