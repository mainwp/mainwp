<?php
/**
 * MainWP GetSitesBasic Ability Tests
 *
 * Tests for the mainwp/get-sites-basic-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/get-sites-basic-v1 ability.
 *
 * @group abilities
 * @group abilities-sites
 */
class Test_GetSitesBasic_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Test that the ability is registered.
     *
     * @return void
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();

        $ability = wp_get_ability( 'mainwp/get-sites-basic-v1' );
        $this->assertNotNull( $ability, 'Ability mainwp/get-sites-basic-v1 should be registered.' );
    }

    /**
     * Test successful execution with valid input.
     *
     * Validates output structure matches get_sites_basic_output_schema():
     * - items: array of site objects with id, url, name
     * - page: current page number (integer)
     * - per_page: items per page (integer)
     * - total: total count of matching sites (integer)
     *
     * @return void
     */
    public function test_get_sites_basic_returns_expected_structure() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [
            'name' => 'Test Site',
            'url'  => 'https://test-get-sites-basic.example.com/',
        ] );

        $result = $this->execute_ability( 'mainwp/get-sites-basic-v1', [] );

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertIsArray( $result, 'Result should be an array.' );

        // Validate pagination keys exist and are integers.
        $this->assertArrayHasKey( 'items', $result, 'Result should have items key.' );
        $this->assertArrayHasKey( 'page', $result, 'Result should have page key.' );
        $this->assertArrayHasKey( 'per_page', $result, 'Result should have per_page key.' );
        $this->assertArrayHasKey( 'total', $result, 'Result should have total key.' );

        $this->assertIsArray( $result['items'], 'items should be an array.' );
        $this->assertIsInt( $result['page'], 'page should be an integer.' );
        $this->assertIsInt( $result['per_page'], 'per_page should be an integer.' );
        $this->assertIsInt( $result['total'], 'total should be an integer.' );

        // Validate default pagination values.
        $this->assertEquals( 1, $result['page'], 'Default page should be 1.' );
        $this->assertEquals( 20, $result['per_page'], 'Default per_page should be 20.' );
        $this->assertGreaterThanOrEqual( 1, $result['total'], 'total should include at least the created test site.' );

        // Validate site structure - items should not be empty since we created a test site.
        $this->assertNotEmpty( $result['items'], 'Items should not be empty after creating test site.' );
        $first_site = $result['items'][0];
        $this->assertArrayHasKey( 'id', $first_site, 'Site item should have id.' );
        $this->assertArrayHasKey( 'url', $first_site, 'Site item should have url.' );
        $this->assertArrayHasKey( 'name', $first_site, 'Site item should have name.' );
    }

    /**
     * Test that unauthenticated users are denied.
     *
     * @return void
     */
    public function test_get_sites_basic_requires_authentication() {
        $this->skip_if_no_abilities_api();

        wp_set_current_user( 0 );

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/get-sites-basic-v1', [] );

        $this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
        $this->assertEquals(
            'ability_invalid_permissions',
            $result->get_error_code(),
            'Should return ability_invalid_permissions error code.'
        );
    }

    /**
     * Test that users without manage_options capability are denied.
     *
     * @return void
     */
    public function test_get_sites_basic_requires_manage_options() {
        $this->skip_if_no_abilities_api();

        $this->set_current_user_as_subscriber();

        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/get-sites-basic-v1', [] );

        $this->assertWPError( $result, 'Subscriber should be denied.' );
        $this->assertEquals(
            'ability_invalid_permissions',
            $result->get_error_code(),
            'Should return ability_invalid_permissions error code.'
        );
    }

    /**
     * Test input validation rejects invalid values.
     *
     * @return void
     */
    public function test_get_sites_basic_validates_input() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/get-sites-basic-v1', [
            'per_page' => 9999, // Exceeds maximum
        ] );

        $this->assertWPError( $result, 'Invalid input should return WP_Error.' );
    }

    /**
     * Test that the status enum offers a legal "not filtering" value.
     *
     * Models that habitually fill every parameter need a neutral status;
     * without "any" in the enum they must pick a real status and silently
     * narrow the results.
     *
     * @return void
     */
    public function test_get_sites_basic_status_enum_includes_any() {
        $this->skip_if_no_abilities_api();

        $ability = wp_get_ability( 'mainwp/get-sites-basic-v1' );
        $this->assertNotNull( $ability );

        $schema = $ability->get_input_schema();
        $status = $schema['properties']['status'];

        $this->assertContains( 'any', $status['enum'], 'Status enum must offer a no-filter value.' );
        $this->assertSame( 'any', $status['default'], 'Status must default to the no-filter value.' );
    }

    /**
     * Test that status "any" returns all sites regardless of status.
     *
     * @return void
     */
    public function test_get_sites_basic_any_status_returns_all_sites() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $connected_id = $this->create_test_site( [
            'name' => 'Connected Basic Site',
            'url'  => 'https://test-basic-any-connected.example.com/',
        ] );
        $suspended_id = $this->create_test_site( [
            'name'      => 'Suspended Basic Site',
            'url'       => 'https://test-basic-any-suspended.example.com/',
            'suspended' => 1,
        ] );

        $result = $this->execute_ability( 'mainwp/get-sites-basic-v1', [
            'page'     => 1,
            'per_page' => 100,
            'status'   => 'any',
        ] );

        $this->assertNotWPError( $result );
        $ids = wp_list_pluck( $result['items'], 'id' );
        $this->assertContains( $connected_id, $ids, 'Status "any" must include connected sites.' );
        $this->assertContains( $suspended_id, $ids, 'Status "any" must include suspended sites.' );
    }

    /**
     * Test that a filtered empty result tells the model which filters applied.
     *
     * A client- or status-filtered empty list is otherwise indistinguishable
     * from "no sites exist", so the model burns tool-loop iterations probing
     * filter variations.
     *
     * @return void
     */
    public function test_get_sites_basic_filtered_empty_result_includes_note() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $this->create_test_site( [
            'name' => 'Note Basic Site',
            'url'  => 'https://test-basic-note.example.com/',
        ] );

        $result = $this->execute_ability( 'mainwp/get-sites-basic-v1', [
            'client_id' => 999999,
        ] );

        $this->assertNotWPError( $result );
        $this->assertSame( [], $result['items'] );
        $this->assertArrayHasKey( 'note', $result, 'Filtered empty result must explain itself to the caller.' );
        $this->assertStringContainsString( 'client_id', $result['note'] );

        // An unfiltered call never carries the note.
        $unfiltered = $this->execute_ability( 'mainwp/get-sites-basic-v1', [] );
        $this->assertNotWPError( $unfiltered );
        $this->assertArrayNotHasKey( 'note', $unfiltered );
    }

    /**
     * Test that tag and client filters narrow the returned items.
     *
     * The fetch query previously ignored both filters (it takes different
     * param names than the count query), so items came back unfiltered
     * while total was filtered.
     *
     * @return void
     */
    public function test_get_sites_basic_tag_and_client_filters_narrow_items() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tagged_id   = $this->create_test_site( [
            'name' => 'Tagged Basic Site',
            'url'  => 'https://test-basic-tagged.example.com/',
        ] );
        $untagged_id = $this->create_test_site( [
            'name' => 'Untagged Basic Site',
            'url'  => 'https://test-basic-untagged.example.com/',
        ] );

        $tag_id = $this->create_test_tag( [ 'name' => 'Basic Filter Tag' ] );
        $this->assign_site_to_tag( $tagged_id, $tag_id );

        $result = $this->execute_ability( 'mainwp/get-sites-basic-v1', [
            'page'     => 1,
            'per_page' => 100,
            'tag_ids'  => [ $tag_id ],
        ] );

        $this->assertNotWPError( $result );
        $ids = wp_list_pluck( $result['items'], 'id' );
        $this->assertContains( $tagged_id, $ids, 'Tag filter must include the tagged site.' );
        $this->assertNotContains( $untagged_id, $ids, 'Tag filter must exclude untagged sites.' );
        $this->assertSame( count( $ids ), $result['total'], 'Items and total must agree under a tag filter.' );

        $client_id = $this->create_test_client( [ 'name' => 'Basic Filter Client' ] );
        \MainWP\Dashboard\MainWP_DB::instance()->update_website_values( $tagged_id, [ 'client_id' => $client_id ] );

        $result = $this->execute_ability( 'mainwp/get-sites-basic-v1', [
            'page'      => 1,
            'per_page'  => 100,
            'client_id' => $client_id,
        ] );

        $this->assertNotWPError( $result );
        $ids = wp_list_pluck( $result['items'], 'id' );
        $this->assertContains( $tagged_id, $ids, 'Client filter must include the assigned site.' );
        $this->assertNotContains( $untagged_id, $ids, 'Client filter must exclude unassigned sites.' );
    }
}
