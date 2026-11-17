<?php
/**
 * Tests for per-user site scoping of the fleet, backup-status, and monitoring abilities.
 *
 * These aggregate abilities enumerate every site (or every monitor) through
 * get_sql_websites_for_current_user() / get_monitors(), whose own scoping is
 * skipped under MAINWP_REST_API_DOING / cron / CLI. Each ability therefore
 * intersects the caller's mainwp_currentuserallowedaccesssites set itself; a
 * restricted API-key user must not see out-of-scope sites in the totals or rows.
 *
 * These assertions run in the normal test process, where MAINWP_REST_API_DOING
 * is not defined, so the DB layer's own WHERE also scopes and covers the same
 * outcome — they are behavioral guards, matching the pattern in
 * test-abilities-insights-scoped-access.php. The in-ability intersect was
 * separately confirmed load-bearing on the actual REST/MCP path: with
 * MAINWP_REST_API_DOING defined (DB WHERE bypassed) the ability still scoped to
 * the allowed site, and removing the intersect leaked every site. The harness
 * cannot hold that constant per-test (it is process-global and would bypass
 * capability checks for every sibling test; @runInSeparateProcess does not
 * survive the WP test bootstrap here), so that proof stays a manual check.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class Test_Abilities_Fleet_Monitoring_Scoped_Access
 */
class Test_Abilities_Fleet_Monitoring_Scoped_Access extends MainWP_Abilities_Test_Case {

	/**
	 * Clean up scope filters between tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'mainwp_currentuserallowedaccesssites' );
		remove_all_filters( 'mainwp_currentuserallowedaccessgroups' );
		parent::tearDown();
	}

	/**
	 * Restrict the current user to the given site IDs via the sites filter.
	 *
	 * @param array $site_ids Allowed site IDs.
	 *
	 * @return void
	 */
	private function restrict_current_user_to_sites( array $site_ids ) {
		add_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $site_ids ) {
				return $site_ids;
			}
		);
	}

	// =========================================================================
	// get-fleet-versions-v1
	// =========================================================================

	/**
	 * The fleet version distribution counts only the caller's allowed sites.
	 *
	 * @return void
	 */
	public function test_fleet_versions_counts_only_allowed_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$in_scope = $this->create_test_site();
		$this->create_test_site();

		$baseline = $this->execute_ability( 'mainwp/get-fleet-versions-v1', array() );
		$this->assertNotWPError( $baseline );
		$this->assertGreaterThanOrEqual( 2, $baseline['total_sites'] );

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		$result = $this->execute_ability( 'mainwp/get-fleet-versions-v1', array() );
		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['total_sites'], 'A restricted caller must only count their allowed sites.' );
	}

	/**
	 * An empty allowed set yields a zero-site fleet distribution, not a leak.
	 *
	 * @return void
	 */
	public function test_fleet_versions_empty_scope_counts_nothing() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$this->create_test_site();

		$this->restrict_current_user_to_sites( array() );

		$result = $this->execute_ability( 'mainwp/get-fleet-versions-v1', array() );
		$this->assertNotWPError( $result );
		$this->assertSame( 0, $result['total_sites'] );
	}

	/**
	 * The sites-segment query lists only sites the caller may see.
	 *
	 * @return void
	 */
	public function test_sites_segment_excludes_out_of_scope_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$in_scope  = $this->create_test_site();
		$out_scope = $this->create_test_site();

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		// No threshold filters: every allowed site matches.
		$result = $this->execute_ability( 'mainwp/query-sites-segment-v1', array() );
		$this->assertNotWPError( $result );

		$ids = array_map( static fn( $item ) => (int) $item['id'], $result['items'] );
		$this->assertContains( $in_scope, $ids, 'An allowed site must be listed.' );
		$this->assertNotContains( $out_scope, $ids, 'An out-of-scope site must not be listed.' );
	}

	// =========================================================================
	// get-backup-status-v1
	// =========================================================================

	/**
	 * The backup status totals and rows cover only the caller's allowed sites.
	 *
	 * @return void
	 */
	public function test_backup_status_scopes_totals_and_rows() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$in_scope  = $this->create_test_site();
		$out_scope = $this->create_test_site();

		$baseline = $this->execute_ability( 'mainwp/get-backup-status-v1', array() );
		$this->assertNotWPError( $baseline );
		$this->assertGreaterThanOrEqual( 2, $baseline['totals']['sites'] );

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		$result = $this->execute_ability( 'mainwp/get-backup-status-v1', array() );
		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['totals']['sites'], 'Totals must count only allowed sites.' );

		$row_ids = array_map( static fn( $item ) => (int) $item['site_id'], $result['items'] );
		$this->assertNotContains( $out_scope, $row_ids, 'No row may reference an out-of-scope site.' );
	}

	// =========================================================================
	// get-uptime-stats-v1 (whole-fleet path)
	// =========================================================================

	/**
	 * A whole-fleet uptime request returns only monitors on allowed sites.
	 *
	 * @return void
	 */
	public function test_uptime_stats_fleet_scopes_to_allowed_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		if ( \MainWP\Dashboard\MainWP_Uptime_Monitoring_Edit::is_enable_global_monitoring() ) {
			$this->markTestSkipped( 'Global monitoring enabled in this environment.' );
		}

		$in_scope  = $this->create_test_site( array( 'name' => 'InScope', 'url' => 'https://in.example/' ) );
		$out_scope = $this->create_test_site( array( 'name' => 'OutScope', 'url' => 'https://out.example/' ) );
		$mon_in    = $this->seed_monitor( $in_scope, array( 'last_status' => 1 ) );
		$mon_out   = $this->seed_monitor( $out_scope, array( 'last_status' => 1 ) );

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		// site_id 0 (default) is the whole-fleet path.
		$result = $this->execute_ability( 'mainwp/get-uptime-stats-v1', array() );
		$this->assertNotWPError( $result );

		$monitor_ids = array_map( static fn( $item ) => (int) $item['monitor_id'], $result['items'] );
		$this->assertContains( $mon_in, $monitor_ids, 'A monitor on an allowed site must be listed.' );
		$this->assertNotContains( $mon_out, $monitor_ids, 'A monitor on an out-of-scope site must be excluded.' );

		$site_ids = array_map( static fn( $item ) => (int) $item['site_id'], $result['items'] );
		$this->assertNotContains( $out_scope, $site_ids );
	}
}
