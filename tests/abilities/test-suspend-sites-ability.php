<?php
/**
 * MainWP SuspendSites Ability Tests
 *
 * Tests for the mainwp/suspend-sites-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/suspend-sites-v1 ability.
 *
 * @group abilities
 * @group abilities-sites
 */
class Test_SuspendSites_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Test that the ability is registered.
     *
     * @return void
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();

        $ability = wp_get_ability( 'mainwp/suspend-sites-v1' );
        $this->assertNotNull( $ability, 'Ability mainwp/suspend-sites-v1 should be registered.' );
    }

    /**
     * Test successful execution with valid input.
     *
     * @return void
     */
    public function test_suspend_sites_returns_expected_structure() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [
            'name' => 'Test Site',
            'url'  => 'https://test-suspend-sites.example.com/',
        ] );

        $result = $this->execute_ability( 'mainwp/suspend-sites-v1', [
            'site_ids_or_domains' => [ $site_id ],
        ] );

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'suspended', $result );
        $this->assertArrayHasKey( 'errors', $result );
        $this->assertIsArray( $result['suspended'] );
        $this->assertIsArray( $result['errors'] );
    }

    /**
     * Test that unauthenticated users are denied.
     *
     * @return void
     */
    public function test_suspend_sites_requires_authentication() {
        $this->skip_if_no_abilities_api();

        wp_set_current_user( 0 );

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/suspend-sites-v1', [
            'site_ids_or_domains' => [ 1 ],
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
    public function test_suspend_sites_requires_manage_options() {
        $this->skip_if_no_abilities_api();

        $this->set_current_user_as_subscriber();

        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/suspend-sites-v1', [
            'site_ids_or_domains' => [ 1 ],
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
    public function test_suspend_sites_validates_input() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/suspend-sites-v1', [
            'site_ids_or_domains' => 'not-an-array',
        ] );

        $this->assertWPError( $result, 'Invalid input should return WP_Error.' );
        $this->assertEquals(
            'mainwp_invalid_input',
            $result->get_error_code(),
            'Non-array input should return mainwp_invalid_input error code.'
        );
    }

    /**
     * Test that batch operations with more than threshold sites are queued.
     *
     * @return void
     */
    public function test_suspend_sites_queues_large_batch() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        // Lower threshold to 2 for testing.
        $threshold_callback = function() {
            return 2;
        };
        add_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );

        try {
            // Create 3 sites to exceed threshold.
            $site_ids = array();
            for ( $i = 1; $i <= 3; $i++ ) {
                $site_ids[] = $this->create_test_site( [
                    'name' => "Batch Test Site {$i}",
                    'url'  => "https://batch-suspend-{$i}.example.com/",
                ] );
            }

            $result = $this->execute_ability( 'mainwp/suspend-sites-v1', [
                'site_ids_or_domains' => $site_ids,
            ] );

            $this->assertNotWPError( $result, 'Batch operation should return successful result.' );
            $this->assertIsArray( $result );
            $this->assertArrayHasKey( 'queued', $result, 'Result should have queued key.' );
            $this->assertTrue( $result['queued'], 'Large batch should be queued.' );
            $this->assertArrayHasKey( 'job_id', $result, 'Result should have job_id.' );
            $this->assertNotEmpty( $result['job_id'], 'Job ID should not be empty.' );
            $this->assertArrayHasKey( 'total', $result, 'Result should have total.' );
            $this->assertEquals( 3, $result['total'], 'Total should match site count.' );

            // Verify transient was created.
            $job_data = get_transient( 'mainwp_batch_job_' . $result['job_id'] );
            $this->assertIsArray( $job_data, 'Job transient should exist.' );
            $this->assertEquals( 'suspend', $job_data['job_type'], 'Job type should be suspend.' );
            $this->assertEquals( 'queued', $job_data['status'], 'Job status should be queued.' );
        } finally {
            remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
        }
    }
}
