<?php
/**
 * Tests for mainwp/list-upcoming-renewals-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/list-upcoming-renewals-v1.
 *
 * @group abilities
 * @group abilities-insights
 */
class Test_Upcoming_Renewals_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Ability is registered and discoverable.
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();
        $abilities = wp_get_abilities();
        $this->assertArrayHasKey( 'mainwp/list-upcoming-renewals-v1', $abilities );
    }

    /**
     * With the Cost Tracker module unavailable, the ability returns the structured
     * module error, never a silent empty. Guarded like Test_Get_Client_Costs_Ability's
     * "not registered without module" test: the Cost Tracker module is enabled by
     * default under phpunit (unlike Logs, which tests/bootstrap.php explicitly
     * disables), so this test self-skips in that case rather than asserting a path
     * this environment can't reach.
     */
    public function test_upcoming_renewals_reports_module_not_available() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        if ( class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
            $this->markTestSkipped( 'Cost Tracker module loaded in this environment; data path is covered live.' );
        }

        $result = $this->execute_ability( 'mainwp/list-upcoming-renewals-v1', [] );

        $this->assertWPError( $result );
        $this->assertEquals( 'mainwp_module_not_available', $result->get_error_code() );
        $data = $result->get_error_data();
        $this->assertEquals( 501, $data['status'] );
    }

    /**
     * Unauthenticated users are denied.
     */
    public function test_upcoming_renewals_requires_authentication() {
        $this->skip_if_no_abilities_api();
        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
        wp_set_current_user( 0 );

        $result = $this->execute_ability( 'mainwp/list-upcoming-renewals-v1', [] );

        $this->assertWPError( $result );
        $this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
    }

    /**
     * Users without manage_options are denied.
     */
    public function test_upcoming_renewals_requires_manage_options() {
        $this->skip_if_no_abilities_api();
        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
        $subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $subscriber_id );

        $result = $this->execute_ability( 'mainwp/list-upcoming-renewals-v1', [] );

        $this->assertWPError( $result );
        $this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
    }

    /**
     * Schema rejects out-of-range input.
     */
    public function test_upcoming_renewals_validates_input() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/list-upcoming-renewals-v1', [ 'days_ahead' => 5000 ] );

        $this->assertWPError( $result, 'days_ahead above schema maximum must be rejected.' );
    }

    /**
     * Happy path: entries renewing inside the window are returned soonest-first
     * with a matching total_amount; entries outside the window (too far out, or
     * with no renewal date at all) are excluded.
     *
     * The Cost Tracker module is enabled by default under phpunit, and its
     * table is created by Cost_Tracker_DB::install() during module init, so this
     * environment can exercise the real query instead of just the module-gate
     * error path. Since the table may carry residue from other tests/suites
     * sharing this database, assertions are ID/delta based rather than assuming
     * the table is empty.
     */
    public function test_upcoming_renewals_happy_path() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
            $this->markTestSkipped( 'Cost Tracker module not active in this environment.' );
        }

        global $wpdb;
        $table = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB::get_instance()->get_table_name( 'cost_tracker' );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture guard.
        if ( $exists !== $table ) {
            $this->markTestSkipped( "Cost Tracker table {$table} is not present in this environment; cannot seed happy-path fixtures." );
        }

        // Baseline call before seeding, so assertions are deltas rather than
        // assuming the table is empty (it may carry residue from other tests).
        $baseline = $this->execute_ability( 'mainwp/list-upcoming-renewals-v1', [ 'days_ahead' => 30 ] );
        $this->assertNotWPError( $baseline, 'Baseline call should succeed.' );
        $baseline_total        = (int) $baseline['total'];
        $baseline_total_amount = round( (float) $baseline['total_amount'], 2 );

        $now = time();

        $id_in_window     = $this->seed_cost_tracker_entry( [
            'name'          => 'Renews Soon',
            'price'         => 19.99,
            'next_renewal'  => $now + 10 * DAY_IN_SECONDS,
        ] );
        $id_outside_window = $this->seed_cost_tracker_entry( [
            'name'          => 'Renews Far Out',
            'price'         => 5.00,
            'next_renewal'  => $now + 400 * DAY_IN_SECONDS,
        ] );
        $id_no_renewal    = $this->seed_cost_tracker_entry( [
            'name'          => 'No Renewal Date',
            'price'         => 7.00,
            'next_renewal'  => 0,
        ] );

        $result = $this->execute_ability( 'mainwp/list-upcoming-renewals-v1', [ 'days_ahead' => 30 ] );

        // Cleanup happens even if assertions below fail.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN (%d, %d, %d)", $id_in_window, $id_outside_window, $id_no_renewal ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture cleanup.

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertIsArray( $result );

        $ids = array_column( $result['items'], 'id' );
        $this->assertContains( $id_in_window, $ids, 'The entry renewing in 10 days should be present.' );
        $this->assertNotContains( $id_outside_window, $ids, 'The entry renewing in 400 days is outside the 30-day window and must be excluded.' );
        $this->assertNotContains( $id_no_renewal, $ids, 'An entry with next_renewal=0 has no upcoming renewal and must be excluded.' );

        $this->assertSame( $baseline_total + 1, (int) $result['total'], 'Exactly one new item should be added to the total.' );
        $this->assertSame(
            round( $baseline_total_amount + 19.99, 2 ),
            round( (float) $result['total_amount'], 2 ),
            'total_amount should increase by exactly the in-window item price.'
        );

        $in_window_item = null;
        foreach ( $result['items'] as $item ) {
            if ( (int) $item['id'] === $id_in_window ) {
                $in_window_item = $item;
                break;
            }
        }
        $this->assertNotNull( $in_window_item, 'The in-window item should be locatable by id.' );
        $this->assertSame( 19.99, (float) $in_window_item['price'] );
    }

    /**
     * Lifetime and non-active entries never renew, so they stay out of the
     * window even with an in-window next_renewal (M22).
     */
    public function test_upcoming_renewals_excludes_lifetime_and_inactive() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $table = $this->require_cost_tracker_table();

        global $wpdb;
        $now         = time();
        $id_lifetime = $this->seed_cost_tracker_entry( [
            'name'         => 'Lifetime License',
            'type'         => 'lifetime',
            'next_renewal' => $now + 5 * DAY_IN_SECONDS,
        ] );
        $id_archived = $this->seed_cost_tracker_entry( [
            'name'         => 'Archived Sub',
            'cost_status'  => 'archived',
            'next_renewal' => $now + 5 * DAY_IN_SECONDS,
        ] );

        $result = $this->execute_ability( 'mainwp/list-upcoming-renewals-v1', [ 'days_ahead' => 30 ] );

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN (%d, %d)", $id_lifetime, $id_archived ) ); // phpcs:ignore WordPress.DB -- test fixture cleanup.

        $this->assertNotWPError( $result );
        $ids = array_column( $result['items'], 'id' );
        $this->assertNotContains( $id_lifetime, $ids, 'Lifetime entries never renew.' );
        $this->assertNotContains( $id_archived, $ids, 'Non-active entries never renew.' );
    }

    /**
     * A recurring subscription whose stored next_renewal has already passed
     * rolls forward by its recurrence into the window (M22: the raw
     * next_renewal comparison dropped it).
     */
    public function test_upcoming_renewals_rolls_recurring_past_renewal_forward() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $table = $this->require_cost_tracker_table();

        global $wpdb;
        $now        = time();
        $id_monthly = $this->seed_cost_tracker_entry( [
            'name'         => 'Monthly Rolls Forward',
            'renewal_type' => 'monthly',
            'price'        => 9.99,
            'next_renewal' => $now - 2 * DAY_IN_SECONDS,
        ] );

        $result = $this->execute_ability( 'mainwp/list-upcoming-renewals-v1', [ 'days_ahead' => 30 ] );

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", $id_monthly ) ); // phpcs:ignore WordPress.DB -- test fixture cleanup.

        $this->assertNotWPError( $result );
        $ids = array_column( $result['items'], 'id' );
        $this->assertContains( $id_monthly, $ids, 'A monthly subscription renews within any 30-day window.' );
    }

    /**
     * Skip unless the Cost Tracker module and its table are present.
     *
     * @return string Cost tracker table name.
     */
    private function require_cost_tracker_table(): string {
        if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
            $this->markTestSkipped( 'Cost Tracker module not active in this environment.' );
        }
        global $wpdb;
        $table  = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB::get_instance()->get_table_name( 'cost_tracker' );
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB -- test fixture guard.
        if ( $exists !== $table ) {
            $this->markTestSkipped( "Cost Tracker table {$table} is not present in this environment." );
        }
        return $table;
    }

    /**
     * Seed a Cost Tracker entry with all NOT NULL columns populated, overridden
     * by $args.
     *
     * @param array $args Column overrides.
     * @return int Inserted row id.
     */
    private function seed_cost_tracker_entry( array $args = [] ): int {
        global $wpdb;
        $table = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB::get_instance()->get_table_name( 'cost_tracker' );

        $defaults = [
            'name'               => 'Test Cost Entry',
            'url'                => 'https://example.com/',
            'type'               => 'subscription',
            'product_type'       => 'plugin',
            'slug'               => '',
            'license_type'       => 'single',
            'cost_status'        => 'active',
            'payment_method'     => 'manual',
            'price'              => 0,
            'renewal_type'       => 'yearly',
            'last_renewal'       => 0,
            'next_renewal'       => 0,
            'next_renewal_today' => 0,
            'last_alert'         => 0,
            'cost_icon'          => '',
            'cost_color'         => '',
            'sites'              => '[]',
            'groups'             => '[]',
            'clients'            => '[]',
            'note'               => '',
        ];

        $wpdb->insert( $table, array_merge( $defaults, $args ) ); // phpcs:ignore WordPress.DB -- test fixture.

        return (int) $wpdb->insert_id;
    }
}
