<?php
/**
 * Tests for mainwp/query-sites-segment-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/query-sites-segment-v1.
 *
 * @group abilities
 * @group abilities-fleet
 */
class Test_Sites_Segment_Ability extends MainWP_Abilities_Test_Case {

	/**
	 * Ability is registered and discoverable.
	 */
	public function test_ability_is_registered() {
		$this->skip_if_no_abilities_api();
		$abilities = wp_get_abilities();
		$this->assertArrayHasKey( 'mainwp/query-sites-segment-v1', $abilities );
	}

	/**
	 * Happy path: php_below matches only the old-PHP site, at the items level.
	 *
	 * Asserts a delta rather than an absolute total: this ability queries the
	 * whole network unscoped, so a shared test DB that already has rows
	 * (leftover from another test's DDL forcing an implicit commit past
	 * transaction rollback) would make absolute-count assertions flaky.
	 * Capturing a baseline with the same filter before seeding fixtures keeps
	 * the assertions correct regardless of what else is in the DB. The seeded
	 * site is located in the items list by id rather than assumed to be at
	 * index 0, since pre-existing rows may also match the filter.
	 */
	public function test_sites_segment_filters_by_php_version() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$baseline = $this->execute_ability( 'mainwp/query-sites-segment-v1', [ 'php_below' => '8.1', 'per_page' => 100 ] );
		$this->assertNotWPError( $baseline );

		$old_id = $this->create_test_site( [ 'name' => 'Old PHP', 'url' => 'https://old.example/' ] );
		$new_id = $this->create_test_site( [ 'name' => 'New PHP', 'url' => 'https://new.example/' ] );
		$this->set_site_option( $old_id, 'phpversion', '7.4.33' );
		$this->set_site_option( $new_id, 'phpversion', '8.3.2' );

		$result = $this->execute_ability( 'mainwp/query-sites-segment-v1', [ 'php_below' => '8.1', 'per_page' => 100 ] );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['total'] - $baseline['total'] );
		$items_by_id = array_column( $result['items'], null, 'id' );
		$this->assertArrayHasKey( $old_id, $items_by_id );
		$this->assertArrayNotHasKey( $new_id, $items_by_id );
		$this->assertSame( '8.1', $result['filters']['php_below'] );
	}

	/**
	 * No filters returns every site with a self-describing filters echo.
	 *
	 * Delta pattern for the same reason as above: baseline captured with no
	 * filters before seeding, then asserts the seeded pair increased the
	 * total by exactly 2.
	 */
	public function test_sites_segment_no_filters_returns_all() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$baseline = $this->execute_ability( 'mainwp/query-sites-segment-v1', [ 'per_page' => 100 ] );
		$this->assertNotWPError( $baseline );

		$this->create_test_site( [ 'name' => 'A', 'url' => 'https://a.example/' ] );
		$this->create_test_site( [ 'name' => 'B', 'url' => 'https://b.example/' ] );

		$result = $this->execute_ability( 'mainwp/query-sites-segment-v1', [ 'per_page' => 100 ] );

		$this->assertNotWPError( $result );
		$this->assertSame( 2, $result['total'] - $baseline['total'] );
		$this->assertSame( '', $result['filters']['php_below'] );
	}

	/**
	 * Unauthenticated users are denied.
	 */
	public function test_sites_segment_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/query-sites-segment-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Users without manage_options are denied.
	 */
	public function test_sites_segment_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/query-sites-segment-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Schema rejects invalid enum values.
	 */
	public function test_sites_segment_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/query-sites-segment-v1', [ 'has_sync_error' => 'maybe' ] );

		$this->assertWPError( $result, 'has_sync_error outside enum must be rejected.' );
	}
}
