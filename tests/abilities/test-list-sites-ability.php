<?php
/**
 * MainWP List Sites Ability Tests
 *
 * Tests for the mainwp/list-sites-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class MainWP_List_Sites_Ability_Test
 *
 * Tests for the mainwp/list-sites-v1 ability.
 */
class MainWP_List_Sites_Ability_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Test that the list-sites ability is registered.
	 *
	 * @return void
	 */
	public function test_list_sites_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$ability = wp_get_ability( 'mainwp/list-sites-v1' );
		$this->assertNotNull( $ability, 'Ability mainwp/list-sites-v1 should be registered.' );
	}

	/**
	 * Test that list-sites returns the expected shape.
	 *
	 * @return void
	 */
	public function test_list_sites_returns_expected_shape() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/list-sites-v1', [
			'page'     => 1,
			'per_page' => 10,
			'status'   => 'any',
			'search'   => '',
		] );

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
	 * Test that list-sites returns a created site.
	 *
	 * @return void
	 */
	public function test_list_sites_returns_created_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'My Test Site',
			'url'  => 'https://test-mytest.example.com/',
		] );

		$result = $this->execute_ability( 'mainwp/list-sites-v1', [
			'page'     => 1,
			'per_page' => 100,
		] );

		$this->assertNotWPError( $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );

		// Find the created site.
		$found = false;
		foreach ( $result['items'] as $item ) {
			if ( (int) $item['id'] === $site_id ) {
				$found = true;
				$this->assertEquals( 'My Test Site', $item['name'] );
				$this->assertEquals( 'https://test-mytest.example.com/', $item['url'] );
				$this->assertArrayHasKey( 'status', $item );
				$this->assertArrayHasKey( 'client_id', $item );
				break;
			}
		}

		$this->assertTrue( $found, 'Created site should be in the list.' );
	}

	/**
	 * Test that list-sites pagination works.
	 *
	 * @return void
	 */
	public function test_list_sites_pagination_works() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Create 25 test sites.
		for ( $i = 0; $i < 25; $i++ ) {
			$this->create_test_site( [
				'name' => "Pagination Test Site {$i}",
			] );
		}

		// Get first page.
		$page1 = $this->execute_ability( 'mainwp/list-sites-v1', [
			'page'     => 1,
			'per_page' => 10,
		] );

		$this->assertNotWPError( $page1 );
		$this->assertGreaterThanOrEqual( 25, $page1['total'] );
		$this->assertLessThanOrEqual( 10, count( $page1['items'] ) );
		$this->assertEquals( 1, $page1['page'] );

		// Get second page.
		$page2 = $this->execute_ability( 'mainwp/list-sites-v1', [
			'page'     => 2,
			'per_page' => 10,
		] );

		$this->assertNotWPError( $page2 );
		$this->assertEquals( 2, $page2['page'] );

		// Pages should return different sites.
		$page1_ids = array_column( $page1['items'], 'id' );
		$page2_ids = array_column( $page2['items'], 'id' );
		$this->assertEmpty(
			array_intersect( $page1_ids, $page2_ids ),
			'Page 1 and page 2 should not have overlapping sites.'
		);
	}

	/**
	 * Test that list-sites status filter works.
	 *
	 * @return void
	 */
	public function test_list_sites_status_filter_works() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Create a connected site (empty sync_errors = connected).
		$online_id = $this->create_test_site( [
			'name'        => 'Online Site',
			'sync_errors' => '', // Empty = connected.
		] );

		// Create a disconnected site (non-empty sync_errors = disconnected).
		$offline_id = $this->create_test_site( [
			'name'        => 'Offline Site',
			'sync_errors' => 'Connection failed', // Non-empty = disconnected.
		] );

		// Test connected filter.
		$connected = $this->execute_ability( 'mainwp/list-sites-v1', [
			'page'     => 1,
			'per_page' => 100,
			'status'   => 'connected',
		] );

		$this->assertNotWPError( $connected );
		$connected_ids = array_column( $connected['items'], 'id' );
		$this->assertContains( $online_id, $connected_ids, 'Online site should be in connected results.' );
		$this->assertNotContains( $offline_id, $connected_ids, 'Offline site should not be in connected results.' );

		// Verify all items have connected status.
		foreach ( $connected['items'] as $item ) {
			$this->assertEquals( 'connected', $item['status'], 'All items should have connected status.' );
		}

		// Test disconnected filter.
		$disconnected = $this->execute_ability( 'mainwp/list-sites-v1', [
			'page'     => 1,
			'per_page' => 100,
			'status'   => 'disconnected',
		] );

		$this->assertNotWPError( $disconnected );
		$disconnected_ids = array_column( $disconnected['items'], 'id' );
		$this->assertContains( $offline_id, $disconnected_ids, 'Offline site should be in disconnected results.' );
		$this->assertNotContains( $online_id, $disconnected_ids, 'Online site should not be in disconnected results.' );

		// Verify all items have disconnected status.
		foreach ( $disconnected['items'] as $item ) {
			$this->assertEquals( 'disconnected', $item['status'], 'All items should have disconnected status.' );
		}
	}

	/**
	 * Test that the available_update total counts only sites with updates (MWP-2169).
	 *
	 * The count helper has no available_update filter, so total used to count every
	 * site while items held only the sites with updates.
	 *
	 * @return void
	 */
	public function test_list_sites_available_update_total_matches_items() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$plugin_update = wp_json_encode( array( 'hello/hello.php' => array( 'Name' => 'Hello' ) ) );
		$theme_update  = wp_json_encode( array( 'twentyten' => array( 'Name' => 'Twenty Ten' ) ) );

		$plugin_id = $this->create_test_site( array( 'name' => 'MWP2169 Plugin Update', 'plugin_upgrades' => $plugin_update ) );
		$theme_id  = $this->create_test_site( array( 'name' => 'MWP2169 Theme Update', 'theme_upgrades' => $theme_update ) );
		$no_update = $this->create_test_site( array( 'name' => 'MWP2169 No Update' ) );

		$all = $this->execute_ability( 'mainwp/list-sites-v1', array(
			'page'     => 1,
			'per_page' => 100,
			'status'   => 'available_update',
			'search'   => 'MWP2169',
		) );

		$this->assertNotWPError( $all );
		$ids = array_map( 'intval', array_column( $all['items'], 'id' ) );
		sort( $ids );
		$this->assertSame( array( $plugin_id, $theme_id ), $ids, 'Only the sites with updates should be listed.' );
		$this->assertNotContains( $no_update, $ids );
		$this->assertSame( 2, $all['total'], 'total should count only the sites with updates.' );

		// A short page still reports the full filtered total.
		$page = $this->execute_ability( 'mainwp/list-sites-v1', array(
			'page'     => 1,
			'per_page' => 1,
			'status'   => 'available_update',
			'search'   => 'MWP2169',
		) );

		$this->assertNotWPError( $page );
		$this->assertCount( 1, $page['items'] );
		$this->assertSame( 2, $page['total'] );
	}

	/**
	 * Test that list-sites search filter works.
	 *
	 * @return void
	 */
	public function test_list_sites_search_filter_works() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Create a uniquely named site.
		$site_id = $this->create_test_site( [
			'name' => 'UniqueTestSite123',
		] );

		// Search for the unique name.
		$result = $this->execute_ability( 'mainwp/list-sites-v1', [
			'page'     => 1,
			'per_page' => 100,
			'search'   => 'UniqueTestSite123',
		] );

		$this->assertNotWPError( $result );
		$this->assertGreaterThanOrEqual( 1, count( $result['items'] ) );

		// Find the site in results.
		$found = false;
		foreach ( $result['items'] as $item ) {
			if ( (int) $item['id'] === $site_id ) {
				$found = true;
				$this->assertEquals( 'UniqueTestSite123', $item['name'] );
				break;
			}
		}

		$this->assertTrue( $found, 'Unique site should be found via search.' );
	}

	/**
	 * Test that list-sites validates input.
	 *
	 * Per WordPress REST API schema validation, values exceeding 'maximum' constraint
	 * return WP_Error (not clamped). The Abilities API wraps this as 'ability_invalid_input'.
	 *
	 * @return void
	 */
	public function test_list_sites_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Test with invalid per_page value (exceeds maximum of 100).
		$result = $this->execute_ability( 'mainwp/list-sites-v1', [
			'page'     => 1,
			'per_page' => 500,
		] );

		// WordPress schema validation rejects values exceeding maximum with WP_Error.
		// Abilities API wraps schema validation errors as 'ability_invalid_input'.
		$this->assertWPError( $result, 'per_page exceeding maximum should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Error code should be ability_invalid_input for schema validation failure.'
		);

		// Verify the error message mentions the constraint.
		$error_message = $result->get_error_message();
		$this->assertNotEmpty( $error_message, 'Error should have a message.' );
	}

	/**
	 * Test that list-sites returns proper item structure.
	 *
	 * @return void
	 */
	public function test_list_sites_item_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$this->create_test_site( [
			'name'      => 'Structure Test Site',
			'url'       => 'https://test-structure.example.com/',
			'client_id' => 5,
		] );

		$result = $this->execute_ability( 'mainwp/list-sites-v1', [
			'page'     => 1,
			'per_page' => 10,
		] );

		$this->assertNotWPError( $result );
		$this->assertGreaterThanOrEqual( 1, count( $result['items'] ) );

		$item = $result['items'][0];

		// Verify required fields exist.
		$this->assertArrayHasKey( 'id', $item, 'Item should have id field.' );
		$this->assertArrayHasKey( 'url', $item, 'Item should have url field.' );
		$this->assertArrayHasKey( 'name', $item, 'Item should have name field.' );
		$this->assertArrayHasKey( 'status', $item, 'Item should have status field.' );
		$this->assertArrayHasKey( 'client_id', $item, 'Item should have client_id field.' );

		// Verify field types.
		$this->assertIsInt( $item['id'] );
		$this->assertIsString( $item['url'] );
		$this->assertIsString( $item['name'] );
		$this->assertIsString( $item['status'] );
	}

	/**
	 * Test that list-sites works with empty database.
	 *
	 * @return void
	 */
	public function test_list_sites_with_empty_database() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Don't create any sites - just query empty DB.
		// Note: Other tests may have created sites that haven't been cleaned up yet.
		$result = $this->execute_ability( 'mainwp/list-sites-v1', [
			'page'     => 1,
			'per_page' => 10,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * Test that list-sites returns suspended sites with proper status.
	 *
	 * @return void
	 */
	public function test_list_sites_suspended_status() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'      => 'Suspended Site',
			'suspended' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/list-sites-v1', [
			'page'     => 1,
			'per_page' => 100,
		] );

		$this->assertNotWPError( $result );

		// Find the suspended site.
		foreach ( $result['items'] as $item ) {
			if ( (int) $item['id'] === $site_id ) {
				$this->assertEquals( 'suspended', $item['status'], 'Suspended site should have suspended status.' );
				return;
			}
		}

		$this->fail( 'Suspended site should be in the list.' );
	}

	/**
	 * Test that list-sites handles page out of bounds.
	 *
	 * @return void
	 */
	public function test_list_sites_page_out_of_bounds() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Create a few sites.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_test_site();
		}

		// Request a page beyond the total.
		$result = $this->execute_ability( 'mainwp/list-sites-v1', [
			'page'     => 9999,
			'per_page' => 10,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result['items'] );
		$this->assertEmpty( $result['items'], 'Out-of-bounds page should return empty items.' );
	}

	/**
	 * Test that list-sites default values work.
	 *
	 * @return void
	 */
	public function test_list_sites_default_values() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$this->create_test_site();

		// Call with minimal/no input to use defaults.
		$result = $this->execute_ability( 'mainwp/list-sites-v1', [] );

		$this->assertNotWPError( $result );
		$this->assertEquals( 1, $result['page'], 'Default page should be 1.' );
		$this->assertGreaterThan( 0, $result['per_page'], 'Default per_page should be positive.' );
	}
}
