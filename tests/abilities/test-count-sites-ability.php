<?php
/**
 * MainWP CountSites Ability Tests
 *
 * Tests for the mainwp/count-sites-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/count-sites-v1 ability.
 *
 * @group abilities
 * @group abilities-sites
 */
class Test_CountSites_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Test that the ability is registered.
     *
     * @return void
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();

        $ability = wp_get_ability( 'mainwp/count-sites-v1' );
        $this->assertNotNull( $ability, 'Ability mainwp/count-sites-v1 should be registered.' );
    }

    /**
     * Test successful execution with valid input.
     *
     * @return void
     */
    public function test_count_sites_returns_expected_structure() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $this->create_test_site( [
            'name' => 'Test Site',
            'url'  => 'https://test-count-sites.example.com/',
        ] );

        $result = $this->execute_ability( 'mainwp/count-sites-v1', [] );

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey('total', $result);
        $this->assertIsInt($result['total']);
    }

    /**
     * Test that unauthenticated users are denied.
     *
     * @return void
     */
    public function test_count_sites_requires_authentication() {
        $this->skip_if_no_abilities_api();

        wp_set_current_user( 0 );

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/count-sites-v1', [] );

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
    public function test_count_sites_requires_manage_options() {
        $this->skip_if_no_abilities_api();

        $this->set_current_user_as_subscriber();

        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/count-sites-v1', [] );

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
    public function test_count_sites_validates_input() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/count-sites-v1', [
            'status' => 'invalid_status', // Invalid enum value
        ] );

        $this->assertWPError( $result, 'Invalid input should return WP_Error.' );
        $this->assertEquals(
            'ability_invalid_input',
            $result->get_error_code(),
            'Should return ability_invalid_input error code for schema validation failure.'
        );
    }

    /**
     * Test that the status enum offers a legal "not filtering" value.
     *
     * Models that habitually fill every parameter need a neutral status;
     * without "any" in the enum they must pick a real status and silently
     * narrow the count.
     *
     * @return void
     */
    public function test_count_sites_status_enum_includes_any() {
        $this->skip_if_no_abilities_api();

        $ability = wp_get_ability( 'mainwp/count-sites-v1' );
        $this->assertNotNull( $ability );

        $schema = $ability->get_input_schema();
        $status = $schema['properties']['status'];

        $this->assertContains( 'any', $status['enum'], 'Status enum must offer a no-filter value.' );
        $this->assertSame( 'any', $status['default'], 'Status must default to the no-filter value.' );
    }

    /**
     * Test that status "any" counts all sites regardless of status.
     *
     * @return void
     */
    public function test_count_sites_any_status_returns_full_count() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $this->create_test_site( [
            'name' => 'Connected Count Site',
            'url'  => 'https://test-count-any-connected.example.com/',
        ] );
        $this->create_test_site( [
            'name'      => 'Suspended Count Site',
            'url'       => 'https://test-count-any-suspended.example.com/',
            'suspended' => 1,
        ] );

        $unfiltered = $this->execute_ability( 'mainwp/count-sites-v1', [] );
        $any        = $this->execute_ability( 'mainwp/count-sites-v1', [ 'status' => 'any' ] );

        $this->assertNotWPError( $unfiltered );
        $this->assertNotWPError( $any );
        $this->assertGreaterThanOrEqual( 2, $any['total'], 'Status "any" must not filter out sites.' );
        $this->assertSame( $unfiltered['total'], $any['total'], 'Status "any" must equal the unfiltered count.' );
    }

    /**
     * Test that a filtered zero count tells the model which filters applied.
     *
     * A status- or client-filtered zero is otherwise indistinguishable from
     * "no sites exist", so the model burns tool-loop iterations probing
     * filter variations.
     *
     * @return void
     */
    public function test_count_sites_filtered_zero_result_includes_note() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $this->create_test_site( [
            'name' => 'Note Count Site',
            'url'  => 'https://test-count-note.example.com/',
        ] );

        $result = $this->execute_ability( 'mainwp/count-sites-v1', [
            'status'    => 'suspended',
            'client_id' => 999999,
        ] );

        $this->assertNotWPError( $result );
        $this->assertSame( 0, $result['total'] );
        $this->assertArrayHasKey( 'note', $result, 'Filtered zero count must explain itself to the caller.' );
        $this->assertStringContainsString( 'status', $result['note'] );
        $this->assertStringContainsString( 'client_id', $result['note'] );

        // An unfiltered call never carries the note.
        $unfiltered = $this->execute_ability( 'mainwp/count-sites-v1', [] );
        $this->assertNotWPError( $unfiltered );
        $this->assertArrayNotHasKey( 'note', $unfiltered );
    }
}
