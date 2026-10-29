<?php
/**
 * MainWP GetSiteSecurity Ability Tests
 *
 * Tests for the mainwp/get-site-security-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/get-site-security-v1 ability.
 *
 * @group abilities
 * @group abilities-sites
 */
class Test_GetSiteSecurity_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Test that the ability is registered.
     *
     * @return void
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();

        $ability = wp_get_ability( 'mainwp/get-site-security-v1' );
        $this->assertNotNull( $ability, 'Ability mainwp/get-site-security-v1 should be registered.' );
    }

    /**
     * Test successful execution with valid input.
     *
     * @return void
     */
    public function test_get_site_security_returns_expected_structure() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [
            'name' => 'Test Site',
            'url'  => 'https://test-get-site-security.example.com/',
        ] );

        $result = $this->execute_ability( 'mainwp/get-site-security-v1', [
            'site_id_or_domain' => $site_id,
        ] );

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey('site_id', $result);
    }

    /**
     * Test that unauthenticated users are denied.
     *
     * @return void
     */
    public function test_get_site_security_requires_authentication() {
        $this->skip_if_no_abilities_api();

        wp_set_current_user( 0 );

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/get-site-security-v1', [
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
    public function test_get_site_security_requires_manage_options() {
        $this->skip_if_no_abilities_api();

        $this->set_current_user_as_subscriber();

        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/get-site-security-v1', [
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
     * Test error for non-existent site.
     *
     * @return void
     */
    public function test_get_site_security_returns_error_for_not_found() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/get-site-security-v1', [
            'site_id_or_domain' => 999999,
        ] );

        $this->assertWPError( $result, 'Non-existent site should return WP_Error.' );
		$this->assertSame( 'mainwp_site_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
    }
}
