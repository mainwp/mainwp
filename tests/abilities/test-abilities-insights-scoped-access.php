<?php
/**
 * Tests for per-user scoping of the insights abilities.
 *
 * Covers the aggregate rewrites: list-upcoming-renewals intersects cost-item
 * sites with the caller's allowed set (company-wide items with no site
 * attachment stay unrestricted-only, totals sum post-filter), and
 * get-network-snapshot counts only accessible sites. The log-backed
 * abilities (query-activity, get-activity-summary) cannot be exercised here
 * because tests/bootstrap.php defines MAINWP_MODULE_LOG_ENABLED false —
 * their scoping is verified by the live restricted-account drill.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class Test_Abilities_Insights_Scoped_Access
 */
class Test_Abilities_Insights_Scoped_Access extends MainWP_Abilities_Test_Case {

	/**
	 * Clean up scope filters and seeded cost rows between tests.
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
	private function restrict_current_user_to_sites( array $site_ids = array( 1 ) ) {
		add_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $site_ids ) {
				return $site_ids;
			}
		);
	}

	/**
	 * Skip unless the Cost Tracker module and its table are present.
	 *
	 * @return string Cost tracker table name.
	 */
	private function require_cost_tracker(): string {
		if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
			$this->markTestSkipped( 'Cost Tracker module not active in this environment.' );
		}

		global $wpdb;
		$table  = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB::get_instance()->get_table_name( 'cost_tracker' );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB -- test fixture guard.
		if ( $exists !== $table ) {
			$this->markTestSkipped( "Cost Tracker table {$table} is not present in this environment." );
		}

		return $table;
	}

	/**
	 * Seed a cost tracker row (mirrors test-upcoming-renewals-ability.php).
	 *
	 * @param array $args Column overrides.
	 *
	 * @return int Cost row ID.
	 */
	private function seed_cost_tracker_entry( array $args = array() ): int {
		global $wpdb;
		$table = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB::get_instance()->get_table_name( 'cost_tracker' );

		$defaults = array(
			'name'               => 'Scoped Cost Entry',
			'url'                => 'https://example.com/',
			'type'               => 'subscription',
			'product_type'       => 'plugin',
			'slug'               => '',
			'license_type'       => 'single',
			'cost_status'        => 'active',
			'payment_method'     => 'manual',
			'price'              => 0,
			'renewal_type'       => 'yearly',
			'last_renewal'       => 0,
			'next_renewal'       => 0,
			'next_renewal_today' => 0,
			'last_alert'         => 0,
			'cost_icon'          => '',
			'cost_color'         => '',
			'sites'              => '[]',
			'groups'             => '[]',
			'clients'            => '[]',
			'note'               => '',
		);

		$wpdb->insert( $table, array_merge( $defaults, $args ) ); // phpcs:ignore WordPress.DB -- test fixture.

		return (int) $wpdb->insert_id;
	}

	// =========================================================================
	// list-upcoming-renewals-v1
	// =========================================================================

	/**
	 * Restricted callers only see renewals backed by an accessible site;
	 * site_ids are trimmed to the caller's slice and the total amount sums
	 * only surfaced items.
	 *
	 * @return void
	 */
	public function test_upcoming_renewals_scopes_items_to_allowed_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$this->require_cost_tracker();

		$in_scope  = $this->create_test_site();
		$out_scope = $this->create_test_site();
		$now       = time();

		$id_visible = $this->seed_cost_tracker_entry(
			array(
				'name'         => 'Backed By Allowed Site',
				'price'        => 10.00,
				'next_renewal' => $now + 5 * DAY_IN_SECONDS,
				'sites'        => wp_json_encode( array( $in_scope, $out_scope ) ),
			)
		);
		$id_hidden  = $this->seed_cost_tracker_entry(
			array(
				'name'         => 'Backed By Out-Of-Scope Site Only',
				'price'        => 99.00,
				'next_renewal' => $now + 5 * DAY_IN_SECONDS,
				'sites'        => wp_json_encode( array( $out_scope ) ),
			)
		);
		$id_global  = $this->seed_cost_tracker_entry(
			array(
				'name'         => 'Company-Wide License',
				'price'        => 50.00,
				'next_renewal' => $now + 5 * DAY_IN_SECONDS,
				'sites'        => '[]',
			)
		);

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		$result = $this->execute_ability( 'mainwp/list-upcoming-renewals-v1', array( 'days_ahead' => 30 ) );

		$this->assertNotWPError( $result );
		$ids = array_map( static fn( $item ) => (int) $item['id'], $result['items'] );

		$this->assertContains( $id_visible, $ids, 'An item backed by an accessible site must be listed.' );
		$this->assertNotContains( $id_hidden, $ids, 'An item backed only by out-of-scope sites must be excluded.' );
		$this->assertNotContains( $id_global, $ids, 'A company-wide item with no site attachment stays unrestricted-only.' );

		foreach ( $result['items'] as $item ) {
			if ( (int) $item['id'] === $id_visible ) {
				$this->assertSame( array( $in_scope ), $item['site_ids'], 'site_ids must be trimmed to the allowed slice.' );
			}
			$this->assertNotContains( $out_scope, $item['site_ids'], 'No surfaced item may reference an out-of-scope site.' );
		}
	}

	/**
	 * Unrestricted control: all three seeded items surface, untrimmed.
	 *
	 * @return void
	 */
	public function test_upcoming_renewals_unrestricted_admin_sees_everything() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$this->require_cost_tracker();

		$site = $this->create_test_site();
		$now  = time();

		$id_sited  = $this->seed_cost_tracker_entry(
			array(
				'name'         => 'Sited Entry',
				'price'        => 10.00,
				'next_renewal' => $now + 5 * DAY_IN_SECONDS,
				'sites'        => wp_json_encode( array( $site ) ),
			)
		);
		$id_global = $this->seed_cost_tracker_entry(
			array(
				'name'         => 'Global Entry',
				'price'        => 50.00,
				'next_renewal' => $now + 5 * DAY_IN_SECONDS,
				'sites'        => '[]',
			)
		);

		$result = $this->execute_ability( 'mainwp/list-upcoming-renewals-v1', array( 'days_ahead' => 30 ) );

		$this->assertNotWPError( $result );
		$ids = array_map( static fn( $item ) => (int) $item['id'], $result['items'] );
		$this->assertContains( $id_sited, $ids );
		$this->assertContains( $id_global, $ids );
	}

	// =========================================================================
	// get-network-snapshot-v1
	// =========================================================================

	/**
	 * A restricted caller's snapshot counts only their allowed sites.
	 *
	 * @return void
	 */
	public function test_network_snapshot_counts_only_accessible_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$in_scope = $this->create_test_site();
		$this->create_test_site();

		// Unrestricted baseline includes both new sites.
		$baseline = $this->execute_ability( 'mainwp/get-network-snapshot-v1', array() );
		$this->assertNotWPError( $baseline );
		$this->assertGreaterThanOrEqual( 2, $baseline['sites']['total'] );

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		$result = $this->execute_ability( 'mainwp/get-network-snapshot-v1', array() );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['sites']['total'], 'A restricted caller must only see their allowed sites counted.' );
	}

	/**
	 * An empty allowed set yields an all-zero snapshot rather than a leak.
	 *
	 * @return void
	 */
	public function test_network_snapshot_empty_scope_counts_nothing() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$this->create_test_site();

		$this->restrict_current_user_to_sites( array() );

		$result = $this->execute_ability( 'mainwp/get-network-snapshot-v1', array() );

		$this->assertNotWPError( $result );
		$this->assertSame( 0, $result['sites']['total'] );
		$this->assertSame( 0, $result['updates']['plugin_updates'] );
		$this->assertSame( 0, $result['monitoring']['monitors_up'] + $result['monitoring']['monitors_down'] );
	}
}
