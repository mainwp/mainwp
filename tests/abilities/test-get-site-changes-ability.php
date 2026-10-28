<?php
/**
 * MainWP GetSiteChanges Ability Tests
 *
 * Tests for the mainwp/get-site-changes-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/get-site-changes-v1 ability.
 *
 * @group abilities
 * @group abilities-sites
 */
class Test_GetSiteChanges_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Test that the ability is registered.
     *
     * @return void
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();

        $ability = wp_get_ability( 'mainwp/get-site-changes-v1' );
        $this->assertNotNull( $ability, 'Ability mainwp/get-site-changes-v1 should be registered.' );
    }

    /**
     * Test successful execution with valid input.
     *
     * @return void
     */
    public function test_get_site_changes_returns_expected_structure() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        // Skip if Logs module is not available.
        if ( ! class_exists( 'MainWP\Dashboard\Module\Log\Log_Manager' ) ) {
            $this->markTestSkipped( 'Logs module is not available.' );
        }

        $site_id = $this->create_test_site( [
            'name' => 'Test Site',
            'url'  => 'https://test-get-site-changes.example.com/',
        ] );

        $result = $this->execute_ability( 'mainwp/get-site-changes-v1', [
            'site_id_or_domain' => $site_id,
        ] );

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'items', $result );
        $this->assertArrayHasKey( 'page', $result );
        $this->assertArrayHasKey( 'per_page', $result );
        $this->assertArrayHasKey( 'total', $result );
        $this->assertIsArray( $result['items'] );
        $this->assertEquals( 1, $result['page'] );
        $this->assertEquals( 20, $result['per_page'] );
    }

    /**
     * Test that unauthenticated users are denied.
     *
     * @return void
     */
    public function test_get_site_changes_requires_authentication() {
        $this->skip_if_no_abilities_api();

        wp_set_current_user( 0 );

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/get-site-changes-v1', [
            'site_id_or_domain' => 1,
        ] );

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
    public function test_get_site_changes_requires_manage_options() {
        $this->skip_if_no_abilities_api();

        $this->set_current_user_as_subscriber();

        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/get-site-changes-v1', [
            'site_id_or_domain' => 1,
        ] );

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
    public function test_get_site_changes_validates_input() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/get-site-changes-v1', [
            'site_id_or_domain' => '',
        ] );

        $this->assertWPError( $result, 'Invalid input should return WP_Error.' );
		$this->assertSame( 'mainwp_site_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
    }
}
