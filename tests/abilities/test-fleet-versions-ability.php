<?php
/**
 * Tests for mainwp/get-fleet-versions-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/get-fleet-versions-v1.
 *
 * @group abilities
 * @group abilities-fleet
 */
class Test_Fleet_Versions_Ability extends MainWP_Abilities_Test_Case {

	/**
	 * Ability is registered and discoverable.
	 */
	public function test_ability_is_registered() {
		$this->skip_if_no_abilities_api();
		$abilities = wp_get_abilities();
		$this->assertArrayHasKey( 'mainwp/get-fleet-versions-v1', $abilities );
	}

	/**
	 * Happy path: versions bucket to major.minor with counts.
	 *
	 * Asserts deltas rather than absolute totals: this ability queries the
	 * whole network unscoped, so a shared test DB that already has rows
	 * (leftover from another test's DDL forcing an implicit commit past
	 * transaction rollback) would make absolute-count assertions flaky.
	 * Capturing a baseline before seeding fixtures keeps the assertions
	 * correct regardless of what else is in the DB.
	 */
	public function test_fleet_versions_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$baseline = $this->execute_ability( 'mainwp/get-fleet-versions-v1', [] );
		$this->assertNotWPError( $baseline );
		$baseline_php = array_column( $baseline['php'], 'count', 'version' );
		$baseline_82  = $baseline_php['8.2'] ?? 0;
		$baseline_74  = $baseline_php['7.4'] ?? 0;

		$a = $this->create_test_site( [ 'name' => 'A', 'url' => 'https://a.example/' ] );
		$b = $this->create_test_site( [ 'name' => 'B', 'url' => 'https://b.example/' ] );
		$c = $this->create_test_site( [ 'name' => 'C', 'url' => 'https://c.example/' ] );
		$this->set_site_option( $a, 'phpversion', '8.2.15' );
		$this->set_site_option( $b, 'phpversion', '8.2.7' );
		$this->set_site_option( $c, 'phpversion', '7.4.33' );

		$result = $this->execute_ability( 'mainwp/get-fleet-versions-v1', [] );

		$this->assertNotWPError( $result );
		$this->assertSame( 3, $result['total_sites'] - $baseline['total_sites'] );
		$php = array_column( $result['php'], 'count', 'version' );
		$this->assertSame( 2, ( $php['8.2'] ?? 0 ) - $baseline_82 );
		$this->assertSame( 1, ( $php['7.4'] ?? 0 ) - $baseline_74 );
		$this->assertArrayHasKey( 'wp', $result );
		$this->assertArrayHasKey( 'mysql', $result );
	}

	/**
	 * Unauthenticated users are denied.
	 */
	public function test_fleet_versions_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/get-fleet-versions-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Users without manage_options are denied.
	 */
	public function test_fleet_versions_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/get-fleet-versions-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Schema rejects unknown properties.
	 */
	public function test_fleet_versions_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/get-fleet-versions-v1', [ 'bogus' => 1 ] );

		$this->assertWPError( $result, 'additionalProperties must be rejected.' );
	}
}
