<?php
/**
 * Tests for mainwp/list-monitor-incidents-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/list-monitor-incidents-v1.
 *
 * @group abilities
 * @group abilities-monitoring
 */
class Test_Monitor_Incidents_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Ability is registered and discoverable.
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();
        $abilities = wp_get_abilities();
        $this->assertArrayHasKey( 'mainwp/list-monitor-incidents-v1', $abilities );
    }

    /**
     * Happy path: an incident is an UP→DOWN transition, newest first. A DOWN
     * beat continuing an existing outage is the same incident, not a new one
     * (M17: the old code counted every DOWN heartbeat).
     */
    public function test_monitor_incidents_returns_expected_structure() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id    = $this->create_test_site( [ 'name' => 'Flaky', 'url' => 'https://flaky.example/' ] );
        $monitor_id = $this->seed_monitor( $site_id, [ 'last_status' => 0 ] );
        $this->seed_heartbeat( $monitor_id, [ 'status' => 1, 'time' => gmdate( 'Y-m-d H:i:s', time() - 4 * HOUR_IN_SECONDS ) ] );
        $this->seed_heartbeat( $monitor_id, [ 'status' => 0, 'time' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ), 'msg' => 'HTTP 500' ] );
        $this->seed_heartbeat( $monitor_id, [ 'status' => 0, 'time' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ), 'msg' => 'still down' ] );
        $this->seed_heartbeat( $monitor_id, [ 'status' => 1, 'time' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ] );
        $this->seed_heartbeat( $monitor_id, [ 'status' => 0, 'time' => gmdate( 'Y-m-d H:i:s', time() - 1800 ), 'msg' => 'HTTP 502' ] );

        $result = $this->execute_ability( 'mainwp/list-monitor-incidents-v1', [ 'site_id' => $site_id ] );

        $this->assertNotWPError( $result );
        $this->assertSame( 2, $result['total'], 'Two UP→DOWN transitions, not three DOWN beats.' );
        $this->assertCount( 2, $result['items'] );
        $this->assertSame( 'HTTP 502', $result['items'][0]['message'] );
        $this->assertSame( 'HTTP 500', $result['items'][1]['message'] );
        $this->assertSame( $site_id, $result['items'][0]['site_id'] );
        $this->assertFalse( $result['truncated'] );
        $this->assertSame( '', $result['note'] );
    }

    /**
     * Pagination is honored across incident transitions.
     */
    public function test_monitor_incidents_paginates() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id    = $this->create_test_site( [ 'name' => 'Flaky2', 'url' => 'https://flaky2.example/' ] );
        $monitor_id = $this->seed_monitor( $site_id, [ 'last_status' => 0 ] );
        $now        = time();
        for ( $i = 0; $i < 3; $i++ ) {
            $down = $now - ( 2 * $i + 1 ) * HOUR_IN_SECONDS;
            $up   = $now - ( 2 * $i + 2 ) * HOUR_IN_SECONDS;
            $this->seed_heartbeat( $monitor_id, [ 'status' => 1, 'time' => gmdate( 'Y-m-d H:i:s', $up ) ] );
            $this->seed_heartbeat( $monitor_id, [ 'status' => 0, 'time' => gmdate( 'Y-m-d H:i:s', $down ), 'msg' => 'incident-' . $i ] );
        }

        $result = $this->execute_ability( 'mainwp/list-monitor-incidents-v1', [ 'site_id' => $site_id, 'per_page' => 2, 'page' => 2 ] );

        $this->assertNotWPError( $result );
        $this->assertSame( 3, $result['total'] );
        $this->assertCount( 1, $result['items'] );
        $this->assertSame( 'incident-2', $result['items'][0]['message'], 'Page 2 holds the oldest transition.' );
    }

    /**
     * `page` carries no schema maximum, so a deep page must stay a cheap,
     * empty page rather than an unbounded per-monitor row fetch.
     */
    public function test_monitor_incidents_clamps_deep_page() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id    = $this->create_test_site( [ 'name' => 'Flaky3', 'url' => 'https://flaky3.example/' ] );
        $monitor_id = $this->seed_monitor( $site_id, [ 'last_status' => 0 ] );
        $now        = time();
        $this->seed_heartbeat( $monitor_id, [ 'status' => 1, 'time' => gmdate( 'Y-m-d H:i:s', $now - 2 * HOUR_IN_SECONDS ) ] );
        $this->seed_heartbeat( $monitor_id, [ 'status' => 0, 'time' => gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS ), 'msg' => 'HTTP 503' ] );

        $result = $this->execute_ability( 'mainwp/list-monitor-incidents-v1', [ 'site_id' => $site_id, 'per_page' => 100, 'page' => 100000 ] );

        $this->assertNotWPError( $result, 'A deep page must be accepted, not rejected.' );
        $this->assertSame( 1, $result['total'] );
        $this->assertSame( [], $result['items'], 'A page past the data returns no items.' );
    }

    /**
     * Seeding 10,000+ real transitions is too slow, so a stand-in DB layer
     * reports a monitor with more incidents than the fetch window and records
     * the LIMIT it was asked for.
     */
    public function test_monitor_incidents_fetch_window_and_total_are_capped() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [ 'name' => 'Flaky4', 'url' => 'https://flaky4.example/' ] );
        $this->seed_monitor( $site_id, [ 'last_status' => 0 ] );

        $stand_in = new class() extends \MainWP\Dashboard\MainWP_DB_Uptime_Monitoring {
            /**
             * LIMIT values passed to the row fetch.
             *
             * @var int[]
             */
            public $limits = [];

            public function count_site_incidents_between( $monitor_id, $start_dt, $end_dt ) {
                return 25000;
            }

            public function get_site_incidents_paginated( $monitor_id, $start_dt, $end_dt, $limit, $offset = 0 ) {
                $this->limits[] = (int) $limit;
                return [];
            }
        };

        $reflection = new \ReflectionProperty( \MainWP\Dashboard\MainWP_DB_Uptime_Monitoring::class, 'instance' );
        $reflection->setAccessible( true );
        $previous = $reflection->getValue();
        $reflection->setValue( null, $stand_in );

        try {
            $result = $this->execute_ability( 'mainwp/list-monitor-incidents-v1', [ 'site_id' => $site_id, 'per_page' => 100, 'page' => 100000 ] );
        } finally {
            $reflection->setValue( null, $previous );
        }

        $this->assertNotWPError( $result );
        $this->assertSame( [ 10000 ], $stand_in->limits, 'The per-monitor row fetch is bounded by the window, not by page * per_page.' );
        $this->assertSame( 10000, $result['total'], 'The reported total stops where the servable pages stop.' );
        $this->assertTrue( $result['truncated'], 'A monitor with more incidents than the window is flagged.' );
        $this->assertStringContainsString( 'Narrow the date range', $result['note'] );
    }

    /**
     * The window bounds each monitor, so a fleet whose incidents add up past
     * it without any one monitor overflowing is complete, not truncated.
     */
    public function test_monitor_incidents_total_across_monitors_is_not_truncated() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_a = $this->create_test_site( [ 'name' => 'Flaky5', 'url' => 'https://flaky5.example/' ] );
        $site_b = $this->create_test_site( [ 'name' => 'Flaky6', 'url' => 'https://flaky6.example/' ] );
        $this->seed_monitor( $site_a, [ 'last_status' => 0 ] );
        $this->seed_monitor( $site_b, [ 'last_status' => 0 ] );

        $stand_in = new class() extends \MainWP\Dashboard\MainWP_DB_Uptime_Monitoring {
            public function count_site_incidents_between( $monitor_id, $start_dt, $end_dt ) {
                return 6000;
            }

            public function get_site_incidents_paginated( $monitor_id, $start_dt, $end_dt, $limit, $offset = 0 ) {
                return [];
            }
        };

        $reflection = new \ReflectionProperty( \MainWP\Dashboard\MainWP_DB_Uptime_Monitoring::class, 'instance' );
        $reflection->setAccessible( true );
        $previous = $reflection->getValue();
        $reflection->setValue( null, $stand_in );

        try {
            $result = $this->execute_ability( 'mainwp/list-monitor-incidents-v1', [ 'per_page' => 100, 'page' => 120 ] );
        } finally {
            $reflection->setValue( null, $previous );
        }

        $this->assertNotWPError( $result );
        $this->assertSame( 12000, $result['total'] );
        $this->assertFalse( $result['truncated'] );
        $this->assertSame( '', $result['note'] );
    }

    /**
     * Unauthenticated users are denied.
     */
    public function test_monitor_incidents_requires_authentication() {
        $this->skip_if_no_abilities_api();
        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
        wp_set_current_user( 0 );

        $result = $this->execute_ability( 'mainwp/list-monitor-incidents-v1', [] );

        $this->assertWPError( $result );
        $this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
    }

    /**
     * Users without manage_options are denied.
     */
    public function test_monitor_incidents_requires_manage_options() {
        $this->skip_if_no_abilities_api();
        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
        $subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $subscriber_id );

        $result = $this->execute_ability( 'mainwp/list-monitor-incidents-v1', [] );

        $this->assertWPError( $result );
        $this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
    }

    /**
     * Schema rejects per_page above the maximum.
     */
    public function test_monitor_incidents_validates_input() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/list-monitor-incidents-v1', [ 'per_page' => 5000 ] );

        $this->assertWPError( $result, 'per_page above the maximum must be rejected.' );
    }
}
