<?php
/**
 * MainWP Get Client Costs Ability Tests
 *
 * Tests for the mainwp/get-client-costs-v1 ability (feature-gated).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class Test_Get_Client_Costs_Ability
 *
 * Tests for the mainwp/get-client-costs-v1 ability (requires Cost Tracker module).
 */
class Test_Get_Client_Costs_Ability extends MainWP_Abilities_Test_Case {

	/**
	 * Scope filters added by a test, removed one by one in tearDown so the
	 * hook keeps any callback the rest of the suite relies on.
	 *
	 * @var array<string, callable>
	 */
	private $scope_filters = array();

	/**
	 * Cost tracker rows seeded by a test.
	 *
	 * @var int[]
	 */
	private $seeded_costs = array();


	/**
	 * Test that the ability is registered when Cost Tracker is available.
	 *
	 * @return void
	 */
	public function test_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
			$this->markTestSkipped( 'Cost Tracker module not available' );
		}

		$ability = wp_get_ability( 'mainwp/get-client-costs-v1' );
		$this->assertNotNull( $ability, 'Ability mainwp/get-client-costs-v1 should be registered when Cost Tracker is available.' );
	}

	/**
	 * Test that get-client-costs returns expected structure.
	 *
	 * @return void
	 */
	public function test_get_client_costs_returns_expected_structure() {
		$this->skip_if_no_abilities_api();

		if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
			$this->markTestSkipped( 'Cost Tracker module not available' );
		}

		$this->set_current_user_as_admin();

		$client_id = $this->create_test_client( [
			'name' => 'Test Client',
		] );

		$result = $this->execute_ability( 'mainwp/get-client-costs-v1', [
			'client_id_or_email' => $client_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * Test that get-client-costs requires authentication.
	 *
	 * @return void
	 */
	public function test_get_client_costs_requires_authentication() {
		$this->skip_if_no_abilities_api();

		if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
			$this->markTestSkipped( 'Cost Tracker module not available' );
		}

		wp_set_current_user( 0 );

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = $this->execute_ability( 'mainwp/get-client-costs-v1', [
			'client_id_or_email' => 1,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that get-client-costs requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_get_client_costs_requires_manage_options() {
		$this->skip_if_no_abilities_api();

		if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
			$this->markTestSkipped( 'Cost Tracker module not available' );
		}

		$this->set_current_user_as_subscriber();

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = $this->execute_ability( 'mainwp/get-client-costs-v1', [
			'client_id_or_email' => 1,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that get-client-costs validates input.
	 *
	 * @return void
	 */
	public function test_get_client_costs_validates_input() {
		$this->skip_if_no_abilities_api();

		if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
			$this->markTestSkipped( 'Cost Tracker module not available' );
		}

		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/get-client-costs-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Test that get-client-costs returns empty for no costs.
	 *
	 * @return void
	 */
	public function test_get_client_costs_returns_empty_for_no_costs() {
		$this->skip_if_no_abilities_api();

		if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
			$this->markTestSkipped( 'Cost Tracker module not available' );
		}

		$this->set_current_user_as_admin();

		$client_id = $this->create_test_client( [
			'name' => 'Client Without Costs',
		] );

		$result = $this->execute_ability( 'mainwp/get-client-costs-v1', [
			'client_id_or_email' => $client_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertEquals( 0, $result['total'] );
		$this->assertEmpty( $result['items'] );
	}

	/**
	 * Test that ability is not registered without Cost Tracker module.
	 *
	 * @return void
	 */
	public function test_get_client_costs_not_registered_without_module() {
		$this->skip_if_no_abilities_api();

		if ( class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
			$this->markTestSkipped( 'Cost Tracker module is available, cannot test absence' );
		}

		// Expect the "doing it wrong" notice from WP_Abilities_Registry::get_registered when ability not found.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$ability = wp_get_ability( 'mainwp/get-client-costs-v1' );
		$this->assertNull( $ability, 'Ability mainwp/get-client-costs-v1 should not be registered without Cost Tracker.' );
	}

	/**
	 * Drop the scope filters and cost rows a test added.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->scope_filters as $hook => $callback ) {
			remove_filter( $hook, $callback );
		}
		$this->scope_filters = array();

		if ( ! empty( $this->seeded_costs ) ) {
			global $wpdb;
			$table = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB::get_instance()->get_table_name( 'cost_tracker' );
			foreach ( $this->seeded_costs as $cost_id ) {
				$wpdb->delete( $table, array( 'id' => $cost_id ) ); // phpcs:ignore WordPress.DB -- test fixture cleanup.
			}
			$this->seeded_costs = array();
			wp_cache_delete( 'mainwp_cost_tracker_all', 'mainwp_cost_tracker' );
		}

		parent::tearDown();
	}

	/**
	 * Restrict the current user to the given site IDs.
	 *
	 * @param array $site_ids Allowed site IDs.
	 *
	 * @return void
	 */
	private function restrict_current_user_to_sites( array $site_ids ): void {
		$callback = static function () use ( $site_ids ) {
			return $site_ids;
		};

		$this->scope_filters['mainwp_currentuserallowedaccesssites'] = $callback;
		add_filter( 'mainwp_currentuserallowedaccesssites', $callback );
	}

	/**
	 * Skip unless the Cost Tracker module and its table are present.
	 *
	 * @return string Cost tracker table name.
	 */
	private function require_cost_tracker(): string {
		if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
			$this->markTestSkipped( 'Cost Tracker module not available' );
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
	 * Seed a cost tracker row and register it for cleanup.
	 *
	 * @param array $args Column overrides.
	 *
	 * @return int Cost row ID.
	 */
	private function seed_cost_tracker_entry( array $args = array() ): int {
		global $wpdb;
		$table = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB::get_instance()->get_table_name( 'cost_tracker' );

		$defaults = array(
			'name'               => 'Scoped Client Cost',
			'url'                => 'https://vendor.example/',
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
			'next_renewal_today'  => 0,
			'last_alert'         => 0,
			'cost_icon'          => '',
			'cost_color'         => '',
			'sites'              => '[]',
			'groups'             => '[]',
			'clients'            => '[]',
			'note'               => '',
		);

		$wpdb->insert( $table, array_merge( $defaults, $args ) ); // phpcs:ignore WordPress.DB -- test fixture.

		$cost_id             = (int) $wpdb->insert_id;
		$this->seeded_costs[] = $cost_id;
		wp_cache_delete( 'mainwp_cost_tracker_all', 'mainwp_cost_tracker' );

		return $cost_id;
	}

	/**
	 * A cost attached by client carries no sites column, so the scope filter
	 * has to read the producer's expanded site list. A restricted caller who
	 * can reach one of the client's sites must still see it, while a cost
	 * pinned to a site outside their scope stays hidden.
	 *
	 * @return void
	 */
	public function test_client_attached_cost_survives_scoped_caller() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$this->require_cost_tracker();

		$client_id = $this->create_test_client( array( 'name' => 'Scoped Costed Client' ) );
		$in_scope  = $this->create_test_site( array( 'name' => 'ScopedIn', 'client_id' => $client_id ) );
		$out_scope = $this->create_test_site( array( 'name' => 'ScopedOut', 'client_id' => $client_id ) );

		// get_db_sites() sums upgrade options; an unset wp_upgrades reaches
		// json_decode() as null and emits a deprecation.
		$this->set_site_option( $in_scope, 'wp_upgrades', '' );
		$this->set_site_option( $out_scope, 'wp_upgrades', '' );

		$by_client = $this->seed_cost_tracker_entry(
			array(
				'name'    => 'Client-Wide License',
				'price'   => 12.00,
				'clients' => wp_json_encode( array( $client_id ) ),
			)
		);
		$by_site   = $this->seed_cost_tracker_entry(
			array(
				'name'  => 'Out-Of-Scope Site License',
				'price' => 99.00,
				'sites' => wp_json_encode( array( $out_scope ) ),
			)
		);

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		$result = $this->execute_ability(
			'mainwp/get-client-costs-v1',
			array( 'client_id_or_email' => $client_id )
		);

		$this->assertNotWPError( $result );
		$ids = array_map( static fn( $item ) => (int) $item['id'], $result['items'] );

		$this->assertContains( $by_client, $ids, 'A cost attached through the client must reach a caller who can access one of that client\'s sites.' );
		$this->assertNotContains( $by_site, $ids, 'A cost pinned to an out-of-scope site must stay hidden.' );
		$this->assertSame( count( $result['items'] ), $result['total'] );
	}

	/**
	 * The same cost stays out of reach for a caller with no access to any of
	 * the client's sites: the client gate refuses before the cost list.
	 *
	 * @return void
	 */
	public function test_client_attached_cost_denied_for_caller_outside_the_client() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$this->require_cost_tracker();

		$client_id  = $this->create_test_client( array( 'name' => 'Unreachable Costed Client' ) );
		$other_site = $this->create_test_site( array( 'name' => 'UnrelatedSite' ) );
		$this->create_test_site( array( 'name' => 'UnreachableClientSite', 'client_id' => $client_id ) );

		$this->seed_cost_tracker_entry(
			array(
				'name'    => 'Client-Wide License',
				'price'   => 12.00,
				'clients' => wp_json_encode( array( $client_id ) ),
			)
		);

		$this->restrict_current_user_to_sites( array( $other_site ) );

		$result = $this->execute_ability(
			'mainwp/get-client-costs-v1',
			array( 'client_id_or_email' => $client_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_access_denied', $result->get_error_code() );
	}

	/**
	 * A cost attached to the client comes back as a cost object, not as the
	 * producer's id => cost[] map.
	 *
	 * @return void
	 */
	public function test_get_client_costs_returns_cost_objects_for_attached_costs() {
		$this->skip_if_no_abilities_api();

		if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
			$this->markTestSkipped( 'Cost Tracker module not available' );
		}

		$this->set_current_user_as_admin();

		global $wpdb;
		$table  = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB::get_instance()->get_table_name( 'cost_tracker' );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB -- test fixture guard.
		if ( $exists !== $table ) {
			$this->markTestSkipped( "Cost Tracker table {$table} is not present in this environment." );
		}

		$client_id = $this->create_test_client( [
			'name' => 'Costed Client',
		] );
		$site_id   = $this->create_test_site( [ 'name' => 'CostedClientSite', 'url' => 'https://costed-client.example.com/' ] );
		$this->set_site_option( $site_id, 'wp_upgrades', '' );
		$wpdb->update( \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' ), [ 'client_id' => $client_id ], [ 'id' => $site_id ] );

		$wpdb->insert( // phpcs:ignore WordPress.DB -- test fixture.
			$table,
			[
				'name'               => 'Client License',
				'url'                => 'https://vendor.example/',
				'type'               => 'subscription',
				'product_type'       => 'plugin',
				'slug'               => '',
				'license_type'       => 'single',
				'cost_status'        => 'active',
				'payment_method'     => 'manual',
				'price'              => 12.00,
				'renewal_type'       => 'yearly',
				'last_renewal'       => 0,
				'next_renewal'       => 0,
				'next_renewal_today'  => 0,
				'last_alert'         => 0,
				'cost_icon'          => '',
				'cost_color'         => '',
				'sites'              => wp_json_encode( [ $site_id ] ),
				'groups'             => '[]',
				'clients'            => wp_json_encode( [ $client_id ] ),
				'note'               => '',
			]
		);
		$cost_id = (int) $wpdb->insert_id;
		wp_cache_delete( 'mainwp_cost_tracker_all', 'mainwp_cost_tracker' );

		$result = $this->execute_ability( 'mainwp/get-client-costs-v1', [
			'client_id_or_email' => $client_id,
		] );

		$wpdb->delete( $table, [ 'id' => $cost_id ] ); // phpcs:ignore WordPress.DB -- test fixture cleanup.
		wp_cache_delete( 'mainwp_cost_tracker_all', 'mainwp_cost_tracker' );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $cost_id, (int) $result['items'][0]['id'], 'The item must be the cost object itself, not a map bucket.' );
		$this->assertSame( 'Client License', $result['items'][0]['name'] );
	}
}
