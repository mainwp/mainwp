<?php
/**
 * Updates pagination matches the visible site query.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Updates;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Exercise the pager and listing against real WordPress tables.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_Updates_Pagination_Count extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Original request values.
	 *
	 * @var array
	 */
	private $original_get;

	/**
	 * Sites per page.
	 *
	 * @var int
	 */
	private $limit = 1;

	/** Set up one independent request. */
	public function set_up() {
		parent::set_up();
		$this->original_get = $_GET;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_System_Utility::set_current_wpid( 0 );
		add_filter( 'mainwp_manage_updates_limit_loading', array( $this, 'one_site_per_page' ) );
	}

	/**
	 * Load one site per page.
	 *
	 * @return int Per-page limit.
	 */
	public function one_site_per_page() {
		return $this->limit;
	}

	/** Restore request values. */
	public function tear_down() {
		$_GET = $this->original_get;
		parent::tear_down();
	}

	/** A disconnected site cannot create an empty final page. */
	public function test_disconnected_site_is_excluded_from_pager() {
		$visible = $this->production_sites();
		$this->create_test_site( array( 'sync_errors' => 'Fixture connection error' ) );
		$this->assert_pagination( $visible );
	}

	/** Production pages exclude staging sites. */
	public function test_production_pager_excludes_staging_site() {
		$visible = $this->production_sites();
		$this->create_test_site( array( 'is_staging' => 1 ) );
		$this->assert_pagination( $visible );
	}

	/** Staging pages exclude production and disconnected staging sites. */
	public function test_staging_pager_uses_staging_view() {
		$this->production_sites();
		$visible = array( $this->create_test_site( array( 'is_staging' => 1 ) ) );
		$this->create_test_site(
			array(
				'is_staging'  => 1,
				'sync_errors' => 'Fixture connection error',
			)
		);
		update_option( 'active_plugins', array( 'mainwp-staging-extension/mainwp-staging-extension.php' ) );
		update_user_option( get_current_user_id(), 'mainwp_staging_options_updates_view', 'staging' );
		$this->assert_pagination( $visible );
	}

	/** Team Control's site-access filter must scope the pager too. */
	public function test_restricted_user_pager_excludes_hidden_sites() {
		$sites = $this->production_sites();
		$staff = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $staff )->add_cap( 'level_10' );
		wp_set_current_user( $staff );
		MainWP_System_Utility::set_current_wpid( 0 );
		$visible = array_slice( $sites, 0, 2 );
		add_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $visible ) {
				return $visible;
			}
		);
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assert_pagination( $visible );
	}

	/** Ownership and connected status are applied together. */
	public function test_multiuser_owner_pager_excludes_other_owners() {
		$visible = $this->production_sites();
		$this->create_test_site( array( 'sync_errors' => 'Fixture connection error' ) );
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->create_test_site( array( 'userid' => $owner ) );
		add_filter( 'mainwp_is_single_user', '__return_false' );
		$this->assert_pagination( $visible );
	}

	/** An empty allowed set keeps the stored page positive. */
	public function test_empty_scope_keeps_page_one_without_pager_links() {
		$this->production_sites();
		add_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
		$this->assert_pagination( array() );
	}

	/** A site outside the listing cannot add a partially empty page. */
	public function test_two_sites_per_page_uses_ceiling_of_visible_count() {
		$this->limit = 2;
		$visible     = $this->production_sites();
		$this->create_test_site( array( 'sync_errors' => 'Fixture connection error' ) );
		$this->assert_pagination( $visible );
	}

	/** A Dashboard without sites keeps page one as its initial state. */
	public function test_no_sites_keeps_page_one_without_pager_links() {
		$this->assert_pagination( array() );
	}

	/** Loading all sites retains the existing listing and option behavior. */
	public function test_loading_toggle_preserves_unbounded_listing_when_off() {
		$visible = $this->production_sites();
		$this->create_test_site( array( 'sync_errors' => 'Fixture connection error' ) );
		$this->assert_pagination( $visible );
		remove_filter( 'mainwp_manage_updates_limit_loading', array( $this, 'one_site_per_page' ) );
		update_option( 'mainwp_manage_updates_limit_sites', 1 );
		update_option( 'mainwp_manage_updates_limit_current_page', 9 );
		MainWP_Updates::handle_limit_sites();
		$this->assertSame( 0, (int) get_option( 'mainwp_manage_updates_limit_sites' ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_manage_updates_limit_current_page' ) );
		$this->assertSame( $visible, $this->listed_site_ids() );
	}

	/** Counts retain listing scope without returning full site records. */
	public function test_count_projection_returns_scalar_for_scoped_listing() {
		$sites   = $this->production_sites();
		$visible = array_slice( $sites, 0, 2 );
		$this->create_test_site( array( 'sync_errors' => 'Fixture connection error' ) );
		$this->create_test_site( array( 'is_staging' => 1 ) );
		$this->set_site_option( $visible[0], 'premium_upgrades', 'fixture-premium-update' );
		add_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $visible ) {
				return $visible;
			}
		);
		$db = MainWP_DB::instance();
		add_filter(
			'mainwp_get_sql_websites',
			static function () {
				return array( 'connected' => 'yes' );
			}
		);
		foreach ( array( false, true ) as $select_groups ) {
			$params = array( 'connected' => 'yes' );
			$sql    = $db->get_sql_websites_for_current_user( $select_groups, null, 'wp.url', false, false, null, false, array( 'premium_upgrades' ), 'no', $params );
			$result = $db->query( $sql );
			$this->assertSame( 2, MainWP_DB::num_rows( $result ) );
			$this->assertGreaterThan( 2, $result->field_count );
			$site = MainWP_DB::fetch_object( $result );
			$this->assertSame( $visible[0], (int) $site->id );
			$this->assertSame( 'fixture-premium-update', $site->premium_upgrades );
			MainWP_DB::free_result( $result );

			$params['count_only'] = true;
			$sql                  = $db->get_sql_websites_for_current_user( $select_groups, null, 'wp.url', false, false, null, false, array( 'premium_upgrades' ), 'no', $params );
			$result               = $db->query( $sql );
			$this->assertSame( 1, MainWP_DB::num_rows( $result ), 'A count query must return one row.' );
			$this->assertSame( 1, $result->field_count, 'A count query must return one scalar field.' );
			MainWP_DB::free_result( $result );
			$this->assertSame( 2, (int) $db->get_var_field( $sql ) );
		}
	}

	/**
	 * Create four connected production sites.
	 *
	 * @return int[] Site IDs.
	 */
	private function production_sites() {
		$ids = array();
		for ( $i = 1; $i <= 4; ++$i ) {
			$ids[] = $this->create_test_site( array( 'url' => 'https://site-' . $i . '.example.org/' ) );
		}
		return $ids;
	}

	/**
	 * Check unbounded rows, rendered links, clamp, and final-page contents.
	 *
	 * @param int[] $visible Visible site IDs.
	 */
	private function assert_pagination( $visible ) {
		delete_option( 'mainwp_manage_updates_limit_sites' );
		$this->assertSame( $visible, $this->listed_site_ids(), 'Unbounded listing fixture must have the expected scope.' );
		$_GET['current_page'] = '999';
		$_GET['_opennonce']   = wp_create_nonce( 'mainwp-admin-nonce' );
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		MainWP_Updates::handle_limit_sites();
		ob_start();
		MainWP_Updates::render_limit_load_sites( $this->limit );
		$html      = ob_get_clean();
		$sql_error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		preg_match_all( '/aria-current="page"[^>]*>(\d+)<\/a>/', $html, $matches );
		$actual   = array(
			'page_total'     => count( $matches[1] ),
			'unbounded_rows' => count( $visible ),
			'current_page'   => (int) get_option( 'mainwp_manage_updates_limit_current_page' ),
			'last_page_rows' => count( $this->listed_site_ids() ),
			'sql_error'      => $sql_error,
		);
		$pages    = (int) ceil( count( $visible ) / $this->limit );
		$expected = array(
			'page_total'     => $pages,
			'unbounded_rows' => count( $visible ),
			'current_page'   => max( 1, $pages ),
			'last_page_rows' => empty( $visible ) ? 0 : count( $visible ) - ( $pages - 1 ) * $this->limit,
			'sql_error'      => '',
		);
		$this->assertSame( $expected, $actual, wp_json_encode( $actual ) );
	}

	/**
	 * Read the real Updates listing.
	 *
	 * @return int[] Site IDs in listing order.
	 */
	private function listed_site_ids() {
		$result = MainWP_Updates::get_sites_for_current_user();
		global $wpdb;
		$this->assertSame( 0, mysqli_errno( $wpdb->dbh ), 'Updates query must succeed.' ); // phpcs:ignore WordPress.DB.RestrictedFunctions -- The listing executes through MainWP's mysqli query path.
		if ( false === $result ) {
			return array();
		}
		$ids = array();
		while ( $site = MainWP_DB::fetch_object( $result ) ) {
			$ids[] = (int) $site->id;
		}
		MainWP_DB::free_result( $result );
		return $ids;
	}
}
