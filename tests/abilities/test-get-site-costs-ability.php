<?php
/**
 * MainWP GetSiteCosts Ability Tests
 *
 * Tests for the mainwp/get-site-costs-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/get-site-costs-v1 ability.
 *
 * @group abilities
 * @group abilities-sites
 */
class Test_GetSiteCosts_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Skip if Cost Tracker module is not active.
     *
     * The get-site-costs-v1 ability is only registered when the Cost Tracker
     * module is active. This helper skips tests when the module is unavailable.
     *
     * @return void
     */
    private function skip_if_no_cost_tracker() {
        if ( ! class_exists( 'MainWP\\Dashboard\\Module\\CostTracker\\Cost_Tracker_Manager' ) ) {
            $this->markTestSkipped( 'Cost Tracker module not active; get-site-costs ability not registered.' );
        }
    }

    /**
     * Test that the ability is registered.
     *
     * @return void
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();
        $this->skip_if_no_cost_tracker();

        $ability = wp_get_ability( 'mainwp/get-site-costs-v1' );
        $this->assertNotNull( $ability, 'Ability mainwp/get-site-costs-v1 should be registered.' );
    }

    /**
     * Test successful execution with valid input.
     *
     * @return void
     */
    public function test_get_site_costs_returns_expected_structure() {
        $this->skip_if_no_abilities_api();
        $this->skip_if_no_cost_tracker();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [
            'name' => 'Test Site',
            'url'  => 'https://test-get-site-costs.example.com/',
        ] );

        $result = $this->execute_ability( 'mainwp/get-site-costs-v1', [
            'site_id_or_domain' => $site_id,
        ] );

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'site_id', $result );
    }

    /**
     * Test that unauthenticated users are denied.
     *
     * @return void
     */
    public function test_get_site_costs_requires_authentication() {
        $this->skip_if_no_abilities_api();
        $this->skip_if_no_cost_tracker();

        wp_set_current_user( 0 );

        // Expect the "doing it wrong" notice from WP_Ability::execute.
        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        // Pass valid input structure to test permissions (not input validation).
        $result = $this->execute_ability( 'mainwp/get-site-costs-v1', [
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
    public function test_get_site_costs_requires_manage_options() {
        $this->skip_if_no_abilities_api();
        $this->skip_if_no_cost_tracker();

        $this->set_current_user_as_subscriber();

        // Expect the "doing it wrong" notice from WP_Ability::execute.
        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        // Pass valid input structure to test permissions (not input validation).
        $result = $this->execute_ability( 'mainwp/get-site-costs-v1', [
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
    public function test_get_site_costs_validates_input() {
        $this->skip_if_no_abilities_api();
        $this->skip_if_no_cost_tracker();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/get-site-costs-v1', [
            'site_id_or_domain' => '',
        ] );

        $this->assertWPError( $result, 'Invalid input should return WP_Error.' );
		$this->assertSame( 'mainwp_site_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
    }

    /**
     * The producer returns a site_id => costs map. Each cost assigned to the
     * site must come back as its own item, and costs of other sites must not.
     *
     * @return void
     */
    public function test_get_site_costs_returns_each_cost_assigned_to_the_site() {
        $this->skip_if_no_abilities_api();
        $this->skip_if_no_cost_tracker();
        $this->set_current_user_as_admin();

        global $wpdb;
        $table  = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB::get_instance()->get_table_name( 'cost_tracker' );
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB -- test fixture guard.
        if ( $exists !== $table ) {
            $this->markTestSkipped( "Cost Tracker table {$table} is not present in this environment." );
        }

        $site_id  = $this->create_test_site( [ 'name' => 'Costed Site', 'url' => 'https://costed-site.example.com/' ] );
        $other_id = $this->create_test_site( [ 'name' => 'Other Site', 'url' => 'https://other-costed-site.example.com/' ] );

        $cost_ids = array();
        foreach ( array( 'Hosting' => $site_id, 'Backup License' => $site_id, 'Other Site Cost' => $other_id ) as $name => $cost_site ) {
            $wpdb->insert( // phpcs:ignore WordPress.DB -- test fixture.
                $table,
                [
                    'name'               => $name,
                    'url'                => 'https://vendor.example/',
                    'type'               => 'subscription',
                    'product_type'       => 'plugin',
                    'slug'               => '',
                    'license_type'       => 'single',
                    'cost_status'        => 'active',
                    'payment_method'     => 'manual',
                    'price'              => 10.00,
                    'renewal_type'       => 'yearly',
                    'last_renewal'       => 0,
                    'next_renewal'       => 0,
                    'next_renewal_today' => 0,
                    'last_alert'         => 0,
                    'cost_icon'          => '',
                    'cost_color'         => '',
                    'sites'              => wp_json_encode( [ $cost_site ] ),
                    'groups'             => '[]',
                    'clients'            => '[]',
                    'note'               => '',
                ]
            );
            $cost_ids[ $name ] = (int) $wpdb->insert_id;
        }
        wp_cache_delete( 'mainwp_cost_tracker_all', 'mainwp_cost_tracker' );

        $result = $this->execute_ability( 'mainwp/get-site-costs-v1', [
            'site_id_or_domain' => $site_id,
        ] );

        foreach ( $cost_ids as $cost_id ) {
            $wpdb->delete( $table, [ 'id' => $cost_id ] ); // phpcs:ignore WordPress.DB -- test fixture cleanup.
        }
        wp_cache_delete( 'mainwp_cost_tracker_all', 'mainwp_cost_tracker' );

        $this->assertNotWPError( $result );
        $this->assertSame( $site_id, $result['site_id'] );
        $this->assertSame( 2, $result['total'] );
        $this->assertCount( 2, $result['costs'] );

        $returned = array_column( $result['costs'], 'name', 'id' );
        ksort( $returned );
        $this->assertSame(
            array(
                $cost_ids['Hosting']        => 'Hosting',
                $cost_ids['Backup License'] => 'Backup License',
            ),
            $returned,
            'Each item must be a cost object assigned to the site, not the map bucket.'
        );
    }
}
