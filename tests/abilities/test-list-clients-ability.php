<?php
/**
 * MainWP List Clients Ability Tests
 *
 * Tests for the mainwp/list-clients-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class Test_List_Clients_Ability
 *
 * Tests for the mainwp/list-clients-v1 ability.
 */
class Test_List_Clients_Ability extends MainWP_Abilities_Test_Case {

	/**
	 * Test that the ability is registered.
	 *
	 * @return void
	 */
	public function test_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$ability = wp_get_ability( 'mainwp/list-clients-v1' );
		$this->assertNotNull( $ability, 'Ability mainwp/list-clients-v1 should be registered.' );
	}

	/**
	 * Test that list-clients returns expected structure.
	 *
	 * @return void
	 */
	public function test_list_clients_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/list-clients-v1', [] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'page', $result );
		$this->assertArrayHasKey( 'per_page', $result );
		$this->assertArrayHasKey( 'total', $result );

		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['page'] );
		$this->assertIsInt( $result['per_page'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * Test that list-clients requires authentication.
	 *
	 * @return void
	 */
	public function test_list_clients_requires_authentication() {
		$this->skip_if_no_abilities_api();
		wp_set_current_user( 0 );

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = $this->execute_ability( 'mainwp/list-clients-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that list-clients requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_list_clients_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_subscriber();

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = $this->execute_ability( 'mainwp/list-clients-v1', [] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that list-clients validates input.
	 *
	 * @return void
	 */
	public function test_list_clients_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/list-clients-v1', [
			'per_page' => 101,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Test that list-clients returns created client.
	 *
	 * @return void
	 */
	public function test_list_clients_returns_created_client() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$client_id = $this->create_test_client( [
			'name'         => 'Test Client Alpha',
			'client_email' => 'test-alpha@example.com',
		] );

		$result = $this->execute_ability( 'mainwp/list-clients-v1', [] );

		$this->assertNotWPError( $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );

		$found = false;
		foreach ( $result['items'] as $item ) {
			if ( (int) $item['id'] === $client_id ) {
				$found = true;
				$this->assertEquals( 'Test Client Alpha', $item['name'] );
				$this->assertEquals( 'test-alpha@example.com', $item['email'] );
				$this->assertArrayHasKey( 'suspended', $item );
				break;
			}
		}

		$this->assertTrue( $found, 'Created client should be in the list.' );
	}

	/**
	 * Test that list-clients pagination works.
	 *
	 * @return void
	 */
	public function test_list_clients_pagination_works() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		for ( $i = 1; $i <= 25; $i++ ) {
			$this->create_test_client( [
				'name' => 'Test Client ' . $i,
			] );
		}

		$page1 = $this->execute_ability( 'mainwp/list-clients-v1', [
			'page'     => 1,
			'per_page' => 20,
		] );

		$this->assertNotWPError( $page1 );
		$this->assertEquals( 20, count( $page1['items'] ) );
		$this->assertGreaterThanOrEqual( 25, $page1['total'] );

		$page2 = $this->execute_ability( 'mainwp/list-clients-v1', [
			'page'     => 2,
			'per_page' => 20,
		] );

		$this->assertNotWPError( $page2 );
		$this->assertGreaterThanOrEqual( 5, count( $page2['items'] ) );
	}

	/**
	 * Test that list-clients status filter works.
	 *
	 * @return void
	 */
	public function test_list_clients_status_filter_works() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$active_id    = $this->create_test_client( [
			'name'      => 'Active Client',
			'suspended' => 0,
		] );
		$suspended_id = $this->create_test_client( [
			'name'      => 'Suspended Client',
			'suspended' => 1,
		] );

		$active_result = $this->execute_ability( 'mainwp/list-clients-v1', [
			'status' => 'active',
		] );

		$this->assertNotWPError( $active_result );

		// Exclusion matters as much as inclusion: a silently ignored filter
		// (S2: 'suspended' arg vs the 'status' key get_wp_clients reads)
		// returns both clients in both calls.
		$active_ids = array_map( 'intval', array_column( $active_result['items'], 'id' ) );
		$this->assertContains( $active_id, $active_ids, 'Active client should be in active filter.' );
		$this->assertNotContains( $suspended_id, $active_ids, 'Suspended client must not pass the active filter.' );

		$suspended_result = $this->execute_ability( 'mainwp/list-clients-v1', [
			'status' => 'suspended',
		] );

		$this->assertNotWPError( $suspended_result );

		$suspended_ids = array_map( 'intval', array_column( $suspended_result['items'], 'id' ) );
		$this->assertContains( $suspended_id, $suspended_ids, 'Suspended client should be in suspended filter.' );
		$this->assertNotContains( $active_id, $suspended_ids, 'Active client must not pass the suspended filter.' );
	}

	/**
	 * Test that list-clients search filter works.
	 *
	 * @return void
	 */
	public function test_list_clients_search_filter_works() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$this->create_test_client( [
			'name' => 'Unique Searchable Name',
		] );

		$result = $this->execute_ability( 'mainwp/list-clients-v1', [
			'search' => 'Unique Searchable',
		] );

		$this->assertNotWPError( $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );

		$found = false;
		foreach ( $result['items'] as $item ) {
			if ( strpos( $item['name'], 'Unique Searchable' ) !== false ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Search should find client with matching name.' );
	}

	/**
	 * Test that a filtered empty result tells the model which filters applied.
	 *
	 * A status- or search-filtered empty list is otherwise indistinguishable
	 * from "no clients exist".
	 *
	 * @return void
	 */
	public function test_list_clients_filtered_empty_result_includes_note() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$this->create_test_client( [ 'name' => 'Active Note Client' ] );

		$result = $this->execute_ability( 'mainwp/list-clients-v1', [
			'status' => 'suspended',
			'search' => 'zzz-no-such-client',
		] );

		$this->assertNotWPError( $result );
		$this->assertSame( [], $result['items'] );
		$this->assertArrayHasKey( 'note', $result, 'Filtered empty result must explain itself to the caller.' );
		$this->assertStringContainsString( 'status', $result['note'] );
		$this->assertStringContainsString( 'search', $result['note'] );

		// An unfiltered call never carries the note.
		$unfiltered = $this->execute_ability( 'mainwp/list-clients-v1', [] );
		$this->assertNotWPError( $unfiltered );
		$this->assertArrayNotHasKey( 'note', $unfiltered );
	}

	/**
	 * Test that list-clients handles empty database.
	 *
	 * @return void
	 */
	public function test_list_clients_with_empty_database() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_clients" );

		$result = $this->execute_ability( 'mainwp/list-clients-v1', [] );

		$this->assertNotWPError( $result );
		$this->assertEquals( 0, $result['total'] );
		$this->assertEmpty( $result['items'] );
	}
}
