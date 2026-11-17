<?php
/**
 * Tests for mainwp/get-uptime-stats-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/get-uptime-stats-v1.
 *
 * @group abilities
 * @group abilities-monitoring
 */
class Test_Uptime_Stats_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Ability is registered and discoverable.
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();
        $abilities = wp_get_abilities();
        $this->assertArrayHasKey( 'mainwp/get-uptime-stats-v1', $abilities );
    }

    /**
     * Happy path: uptime percent computed from seeded hourly stats.
     */
    public function test_uptime_stats_returns_expected_structure() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id    = $this->create_test_site( [ 'name' => 'Monitored', 'url' => 'https://mon.example/' ] );
        $monitor_id = $this->seed_monitor( $site_id, [ 'last_status' => 1 ] );
        $hour       = (int) ( floor( time() / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );
        $this->seed_stat_hourly( $monitor_id, $hour - HOUR_IN_SECONDS, 55, 5, 120 );
        $this->seed_stat_hourly( $monitor_id, $hour - 2 * HOUR_IN_SECONDS, 60, 0, 80 );

        $result = $this->execute_ability( 'mainwp/get-uptime-stats-v1', [ 'site_id' => $site_id ] );

        $this->assertNotWPError( $result );
        $this->assertCount( 1, $result['items'] );
        $item = $result['items'][0];
        $this->assertSame( $monitor_id, $item['monitor_id'] );
        $this->assertSame( 115, $item['checks_up'] );
        $this->assertSame( 5, $item['checks_down'] );
        $this->assertEqualsWithDelta( 95.83, $item['uptime_percent'], 0.01 );
        $this->assertSame( 'up', $item['last_status'] );
        $this->assertEqualsWithDelta( 95.83, $result['summary']['uptime_percent'], 0.01 );
    }

    /**
     * The average ping weights hours by their up-check counts instead of
     * averaging the hourly averages (M18).
     */
    public function test_uptime_stats_weights_avg_ping_by_up_checks() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id    = $this->create_test_site( [ 'name' => 'Weighted', 'url' => 'https://weighted.example/' ] );
        $monitor_id = $this->seed_monitor( $site_id, [ 'last_status' => 1 ] );
        $hour       = (int) ( floor( time() / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );
        $this->seed_stat_hourly( $monitor_id, $hour - HOUR_IN_SECONDS, 10, 0, 100 );
        $this->seed_stat_hourly( $monitor_id, $hour - 2 * HOUR_IN_SECONDS, 90, 0, 200 );

        $result = $this->execute_ability( 'mainwp/get-uptime-stats-v1', [ 'site_id' => $site_id ] );

        $this->assertNotWPError( $result );
        $this->assertEqualsWithDelta( 190.0, $result['items'][0]['avg_ping_ms'], 0.1, 'Weighted by up count: (100*10 + 200*90) / 100.' );
    }

    /**
     * Hours whose ping_min was pinned to 0 by the old writer do not poison
     * the minimum (M18).
     */
    public function test_uptime_stats_min_ping_ignores_zero_rows() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id    = $this->create_test_site( [ 'name' => 'MinPing', 'url' => 'https://minping.example/' ] );
        $monitor_id = $this->seed_monitor( $site_id, [ 'last_status' => 1 ] );
        $hour       = (int) ( floor( time() / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );
        $this->seed_stat_hourly( $monitor_id, $hour - HOUR_IN_SECONDS, 30, 0, 120, 0 );
        $this->seed_stat_hourly( $monitor_id, $hour - 2 * HOUR_IN_SECONDS, 30, 0, 90, 50 );

        $result = $this->execute_ability( 'mainwp/get-uptime-stats-v1', [ 'site_id' => $site_id ] );

        $this->assertNotWPError( $result );
        $this->assertEqualsWithDelta( 50.0, $result['items'][0]['min_ping_ms'], 0.1, 'Zero-pinned rows must not report min 0.' );
    }

    /**
     * The date range is clamped to the 30-day stat_hourly retention (M18).
     */
    public function test_uptime_stats_clamps_range_to_retention() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [ 'name' => 'Clamped', 'url' => 'https://clamped.example/' ] );
        $this->seed_monitor( $site_id, [ 'last_status' => 1 ] );

        $result = $this->execute_ability(
            'mainwp/get-uptime-stats-v1',
            [
                'date_from' => time() - 90 * DAY_IN_SECONDS,
                'date_to'   => time(),
            ]
        );

        $this->assertNotWPError( $result );
        $this->assertLessThanOrEqual( 30 * DAY_IN_SECONDS, $result['date_to'] - $result['date_from'], 'Span must clamp to the 30-day retention.' );
    }

    /**
     * The hourly-stat writer seeds ping_min from the first UP ping instead of
     * pinning it to 0 (M18 writer half).
     */
    public function test_stat_hourly_writer_inits_ping_min_from_first_ping() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id    = $this->create_test_site( [ 'name' => 'Writer', 'url' => 'https://writer.example/' ] );
        $monitor_id = $this->seed_monitor( $site_id, [ 'last_status' => 1 ] );
        $handle     = new \MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle();
        $time       = gmdate( 'Y-m-d H:i:s' );

        $handle->calc_and_save_site_uptime_stat_hourly_data( $monitor_id, [ 'time' => $time, 'status' => 1, 'ping_ms' => 120 ] );
        $handle->calc_and_save_site_uptime_stat_hourly_data( $monitor_id, [ 'time' => $time, 'status' => 1, 'ping_ms' => 80 ] );

        $hourly_key = \MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp( strtotime( $time ) );
        $stat       = \MainWP\Dashboard\MainWP_DB_Uptime_Monitoring::instance()->get_uptime_monitor_stat_hourly_by( $monitor_id, 'timestamp', $hourly_key );

        $this->assertIsArray( $stat );
        $this->assertEqualsWithDelta( 80.0, (float) $stat['ping_min'], 0.1, 'First ping seeds the minimum; second lowers it.' );
    }

    /**
     * No monitors + global monitoring off returns the structured disabled error.
     */
    public function test_uptime_stats_reports_monitoring_disabled() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        // No monitors seeded, and the global monitoring setting is off by default in tests.
        $result = $this->execute_ability( 'mainwp/get-uptime-stats-v1', [] );

        if ( \MainWP\Dashboard\MainWP_Uptime_Monitoring_Edit::is_enable_global_monitoring() ) {
            $this->markTestSkipped( 'Global monitoring enabled in this environment.' );
        }
        $this->assertWPError( $result );
        $this->assertEquals( 'mainwp_monitoring_disabled', $result->get_error_code() );
    }

    /**
     * Unauthenticated users are denied.
     */
    public function test_uptime_stats_requires_authentication() {
        $this->skip_if_no_abilities_api();
        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
        wp_set_current_user( 0 );

        $result = $this->execute_ability( 'mainwp/get-uptime-stats-v1', [] );

        $this->assertWPError( $result );
        $this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
    }

    /**
     * Users without manage_options are denied.
     */
    public function test_uptime_stats_requires_manage_options() {
        $this->skip_if_no_abilities_api();
        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
        $subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $subscriber_id );

        $result = $this->execute_ability( 'mainwp/get-uptime-stats-v1', [] );

        $this->assertWPError( $result );
        $this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
    }

    /**
     * Schema rejects negative site_id.
     */
    public function test_uptime_stats_validates_input() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/get-uptime-stats-v1', [ 'site_id' => -5 ] );

        $this->assertWPError( $result, 'Negative site_id must be rejected.' );
    }
}
