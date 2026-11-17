<?php
/**
 * Tests for mainwp/get-network-snapshot-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/get-network-snapshot-v1.
 *
 * @group abilities
 * @group abilities-insights
 */
class Test_Network_Snapshot_Ability extends MainWP_Abilities_Test_Case {

	/**
	 * Ability is registered and discoverable.
	 */
	public function test_ability_is_registered() {
		$this->skip_if_no_abilities_api();
		$abilities = wp_get_abilities();
		$this->assertArrayHasKey( 'mainwp/get-network-snapshot-v1', $abilities );
	}

	/**
	 * Happy path: counts reflect seeded site states and monitors.
	 *
	 * Asserts deltas rather than absolute totals: this ability queries the whole
	 * network unscoped, so a shared test DB that already has rows (leftover from
	 * another test's DDL forcing an implicit commit past transaction rollback)
	 * would make absolute-count assertions flaky. Capturing a baseline before
	 * seeding fixtures keeps the assertions correct regardless of what else is
	 * in the DB.
	 */
	public function test_network_snapshot_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$baseline = $this->execute_ability( 'mainwp/get-network-snapshot-v1', [] );
		$this->assertNotWPError( $baseline );

		$connected_id    = $this->create_test_site( [ 'name' => 'Connected', 'url' => 'https://connected.example/' ] );
		$disconnected_id = $this->create_test_site( [ 'name' => 'Broken', 'url' => 'https://broken.example/', 'sync_errors' => 'ERROR' ] );
		$this->update_site_sync( $connected_id, [ 'dtsSync' => time() - 100 ] );
		$this->update_site_sync( $disconnected_id, [ 'dtsSync' => time() - 3 * DAY_IN_SECONDS ] );
		$this->seed_monitor( $connected_id, [ 'last_status' => 1 ] );
		$this->seed_monitor( $disconnected_id, [ 'last_status' => 0 ] );

		$result = $this->execute_ability( 'mainwp/get-network-snapshot-v1', [] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['sites']['total'] - $baseline['sites']['total'] );
		$this->assertSame( 1, $result['sites']['connected'] - $baseline['sites']['connected'] );
		$this->assertSame( 1, $result['sites']['disconnected'] - $baseline['sites']['disconnected'] );
		$this->assertSame( 1, $result['sites']['stale_sync_24h'] - $baseline['sites']['stale_sync_24h'] );
		$this->assertSame( 1, $result['monitoring']['monitors_down'] - $baseline['monitoring']['monitors_down'] );
		$this->assertArrayHasKey( 'updates', $result );
		$this->assertArrayHasKey( 'activity_last_24h', $result );
		$this->assertArrayHasKey( 'generated_at', $result );
	}

	/**
	 * Unauthenticated users are denied.
	 */
	public function test_network_snapshot_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/get-network-snapshot-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Users without manage_options are denied.
	 */
	public function test_network_snapshot_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/get-network-snapshot-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Schema rejects unknown properties.
	 */
	public function test_network_snapshot_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/get-network-snapshot-v1', [ 'bogus' => true ] );

		$this->assertWPError( $result, 'additionalProperties must be rejected.' );
	}
}
