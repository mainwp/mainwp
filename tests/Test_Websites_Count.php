<?php
/**
 * Site counts retain ownership and access scope within a request.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;

/** Exercise the count query against the WordPress test database. */
class Test_Websites_Count extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Fixture owner.
	 *
	 * @var int
	 */
	private $owner;

	/**
	 * Other fixture owner.
	 *
	 * @var int
	 */
	private $other;

	/**
	 * Original signed-in user.
	 *
	 * @var int
	 */
	private $original_user;

	/**
	 * Fixture site IDs.
	 *
	 * @var array
	 */
	private $sites;

	/**
	 * Whether legacy per-user mode is enabled.
	 *
	 * @var bool
	 */
	private $multi_user = true;

	/**
	 * Allowed site IDs.
	 *
	 * @var array|string
	 */
	private $allowed_sites = 'all';

	/**
	 * Count cache, when available.
	 *
	 * @var \ReflectionProperty|null
	 */
	private $cache;

	/**
	 * Saved request cache.
	 *
	 * @var array
	 */
	private $saved_cache;

	/** Seed two owners, including one staging site. */
	public function setUp(): void {
		parent::setUp();
		global $wpdb;

		$this->original_user = get_current_user_id();
		$this->owner         = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other         = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->owner );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'mainwp_wp' );
		$this->sites = array(
			$this->create_test_site( array( 'userid' => $this->owner ) ),
			$this->create_test_site( array( 'userid' => $this->owner ) ),
			$this->create_test_site( array( 'userid' => $this->other ) ),
			$this->create_test_site( array( 'userid' => $this->other ) ),
			$this->create_test_site(
				array(
					'userid'     => $this->owner,
					'is_staging' => 1,
				)
			),
		);
		$this->assertSame( '', $wpdb->last_error );
		add_filter( 'mainwp_is_single_user', array( $this, 'single_user' ) );
		add_filter( 'mainwp_currentuserallowedaccesssites', array( $this, 'allowed_sites' ) );

		// Older code has an immutable function-local cache; its baseline failures remain observable.
		if ( property_exists( MainWP_DB::class, 'websites_count' ) ) {
			$this->cache = new \ReflectionProperty( MainWP_DB::class, 'websites_count' );
			$this->cache->setAccessible( true );
			$this->saved_cache = $this->cache->getValue();
			$this->cache->setValue( null, array() );
		}
	}

	/** Restore the cache and callbacks before the fixture transaction rolls back. */
	public function tearDown(): void {
		if ( $this->cache ) {
			$this->cache->setValue( null, $this->saved_cache );
		}
		remove_filter( 'mainwp_is_single_user', array( $this, 'single_user' ) );
		remove_filter( 'mainwp_currentuserallowedaccesssites', array( $this, 'allowed_sites' ) );
		wp_set_current_user( $this->original_user );
		parent::tearDown();
	}

	/**
	 * Single-user fixture mode.
	 *
	 * @return bool
	 */
	public function single_user() {
		return ! $this->multi_user;
	}

	/**
	 * Fixture access scope.
	 *
	 * @return array|string
	 */
	public function allowed_sites() {
		return $this->allowed_sites;
	}

	/** Legacy counts exclude other owners and retain staging/access behavior. */
	public function test_per_user_counts_run_without_sql_errors() {
		$this->assert_fresh_count( '2' );
		$this->allowed_sites = array( $this->sites[0], $this->sites[2] );
		$this->assert_fresh_count( '1' );
		$this->assert_fresh_count( '3', null, true );
	}

	/** An implicit owner follows the signed-in user, even after another owner's call. */
	public function test_current_owner_is_resolved_before_cache_lookup() {
		$this->assert_fresh_count( '2' );
		wp_set_current_user( $this->other );
		$this->assert_fresh_count( '2' );
		wp_set_current_user( $this->owner );
		$this->assert_cached_count( '2' );
	}

	/** Explicit owner IDs cannot reuse another owner's cached value. */
	public function test_explicit_owners_have_separate_cached_counts() {
		$this->multi_user = false;
		$this->assert_fresh_count( '3', $this->owner, true );
		$this->assert_fresh_count( '2', $this->other, true );
		$this->assert_cached_count( '3', (string) $this->owner, true );
	}

	/** User zero remains an owner constraint rather than an unrestricted count. */
	public function test_missing_and_zero_user_never_widen_counts() {
		$this->assert_fresh_count( '2' );
		wp_set_current_user( 0 );
		$this->assert_fresh_count( '0' );
		$this->assert_cached_count( '0', 0 );
		$this->assert_fresh_count( '0', 0, true );
		unset( $GLOBALS['current_user'] );
		$this->assert_cached_count( '0' );
	}

	/**
	 * Single-user mode retains its normal and staging-inclusive totals in both orders.
	 *
	 * @dataProvider access_orders
	 * @param bool $all_first Whether all-access is called first.
	 */
	public function test_single_user_counts_are_unchanged( $all_first ) {
		$this->multi_user = false;
		foreach ( array( $all_first, ! $all_first ) as $all_access ) {
			$expected = $all_access ? '5' : '4';
			$this->assert_fresh_count( $expected, null, $all_access );
			$this->assert_cached_count( $expected, null, $all_access );
		}
	}

	/**
	 * Filtered and all-access results remain distinct within the same request.
	 *
	 * @dataProvider access_orders
	 * @param bool $all_first Whether all-access is called first.
	 */
	public function test_access_filter_and_all_access_have_separate_counts( $all_first ) {
		$this->multi_user    = false;
		$this->allowed_sites = array( $this->sites[0] );
		foreach ( array( $all_first, ! $all_first ) as $all_access ) {
			$expected = $all_access ? '5' : '1';
			$this->assert_fresh_count( $expected, null, $all_access );
			$this->assert_cached_count( $expected, null, $all_access );
		}
	}

	/**
	 * Both supported argument orders.
	 *
	 * @return array
	 */
	public static function access_orders() {
		return array(
			'filtered first'   => array( false ),
			'all-access first' => array( true ),
		);
	}

	/**
	 * Assert a real count query and its successful value.
	 *
	 * @param string   $expected Expected numeric string.
	 * @param int|null $owner Owner argument.
	 * @param bool     $all_access All-access argument.
	 */
	private function assert_fresh_count( $expected, $owner = null, $all_access = false ) {
		global $wpdb;
		$db      = MainWP_DB::instance();
		$queries = $wpdb->num_queries;
		$actual  = $db->get_websites_count( $owner, $all_access );
		$this->assertSame( '', $wpdb->last_error, $wpdb->last_query );
		$this->assertSame( $expected, $actual, $wpdb->last_query );
		$this->assertSame( $queries + 1, $wpdb->num_queries, 'First call must execute its scoped count query.' );
	}

	/**
	 * Assert repeated identical scope uses its cached value.
	 *
	 * @param string     $expected Expected numeric string.
	 * @param int|string $owner Owner argument.
	 * @param bool       $all_access All-access argument.
	 */
	private function assert_cached_count( $expected, $owner = null, $all_access = false ) {
		global $wpdb;
		$db      = MainWP_DB::instance();
		$queries = $wpdb->num_queries;
		$this->assertSame( $expected, $db->get_websites_count( $owner, $all_access ) );
		$this->assertSame( $queries, $wpdb->num_queries, 'Repeated scope should use its cached count.' );
	}
}
