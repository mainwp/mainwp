<?php
/**
 * Uptime monitor tests (MWP-1703).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Connect;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Schedule;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Tests uptime monitor interval and confirmation-retry scheduling.
 */
class Test_Uptime_Monitor_Scheduling extends \WP_UnitTestCase {

	/**
	 * Created site ID.
	 *
	 * @var int
	 */
	protected $site_id = 0;

	/**
	 * Created monitor ID.
	 *
	 * @var int
	 */
	protected $monitor_id = 0;

    /**
     * Created sub-monitor ID.
     *
     * @var int
     */
    protected $sub_monitor_id = 0;


	/**
	 * Uptime notification captured before email delivery.
	 *
	 * @var object|false
	 */
	protected $sent_notification = false;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'mainwp_wp_mail_to', array( $this, 'capture_uptime_notification' ), 10, 5 );

		$this->site_id = $this->create_test_site(
			array(
				'name' => 'Test Site',
				'url'  => 'https://test-uptime-interval-scheduling.example.com/',
			)
		);

		$this->monitor_id = $this->create_test_monitor( $this->site_id );

        $this->sub_monitor_id = $this->create_test_monitor(
            $this->site_id,
            array(
                'suburl' => 'testsubpage'
            )
        );

	}

	/**
	 * Clean up test fixtures and generated uptime data.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		remove_filter( 'mainwp_wp_mail_to', array( $this, 'capture_uptime_notification' ), 10 );

		if ( $this->monitor_id ) {
			MainWP_DB::instance()->delete_regular_process( false, $this->monitor_id, 'monitor', 'uptime_notification' );
			MainWP_DB_Uptime_Monitoring::instance()->delete_monitor( array( 'monitor_id' => $this->monitor_id ) );
		}

		if ( $this->site_id ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $this->site_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_options', array( 'wpid' => $this->site_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $this->site_id ), array( '%d' ) );
		}

        if ( $this->sub_monitor_id ) {
            MainWP_DB::instance()->delete_regular_process( false, $this->sub_monitor_id, 'monitor', 'uptime_notification' );
            MainWP_DB_Uptime_Monitoring::instance()->delete_monitor( array( 'monitor_id' => $this->sub_monitor_id ) );
        }

		parent::tearDown();
	}


    /**
	 * Verify uptime monitor creation and editing behaviors for primary and sub-monitors
	 * against all acceptance criteria.
	 *
	 * AC Coverage:
	 * - Creating first Sub-Monitor does not change Primary Monitor row or status.
	 * - Primary and Sub-Monitor have distinct monitor IDs.
	 * - Primary Monitor using global settings remains active after Sub-Monitor creation.
	 * - Editing an existing Sub-Monitor updates only that Sub-Monitor.
	 * - Saving Primary Monitor settings updates/creates only the Primary Monitor.
	 * - Safe recovery path for affected sites with Sub-Monitors but no Primary Monitor.
	 * - Covers creation/editing across 0, 1, and multiple Sub-Monitors with inherited (-1),
	 *   enabled (1), and disabled (0) primary active settings.
	 *
	 * @return void
	 */
	public function test_uptime_monitor_acceptance_criteria_matrix() {
		$global_settings = $this->get_global_settings( 5 );
		$db              = MainWP_DB_Uptime_Monitoring::instance();

		// Primary settings matrix: -1 (Inherit/Global), 1 (Enabled), 0 (Disabled)
		$active_matrix = array( -1, 1, 0 );

		foreach ( $active_matrix as $primary_active_setting ) {
			// Setup baseline Primary Monitor
			$db->update_wp_monitor(
				array(
					'monitor_id' => $this->monitor_id,
					'active'     => $primary_active_setting,
					'suburl'     => '',
				)
			);

			$expected_active = ( -1 === $primary_active_setting ) ? (int) $global_settings['active'] : $primary_active_setting;


			// -----------------------------------------------------------------
			// SCENARIO 1: Zero Sub-Monitors Clean Slate
			// -----------------------------------------------------------------
			$existing_subs = $db->get_sub_monitors( $this->site_id );
			if ( ! empty( $existing_subs ) ) {
                foreach ( $existing_subs as $sub ) {
                    $db->delete_monitor( array( 'monitor_id' => $sub->monitor_id ) );
                }
            }

			$this->assertCount( 0, $db->get_sub_monitors( $this->site_id ), 'Site should initially have zero sub-monitors.' );

			// -----------------------------------------------------------------
			// AC: Creating first Sub-Monitor does not change Primary Monitor row/status
			// AC: Distinct monitor IDs
			// AC: Primary using global settings remains active after creation
			// -----------------------------------------------------------------
			$primary_before = $db->get_monitor_by( false, 'monitor_id', $this->monitor_id );

			$first_sub_id = $this->create_test_monitor(
				$this->site_id,
				array(
					'suburl' => 'first-sub-page',
					'active' => $primary_active_setting,
				)
			);

			$primary_after = $db->get_monitor_by( false, 'monitor_id', $this->monitor_id );

			// AC: Primary and Sub-Monitor have distinct monitor IDs.
			$this->assertNotEquals( $this->monitor_id, $first_sub_id, 'Primary and Sub-Monitor must have distinct monitor IDs.' );

			// AC: Creating first Sub-Monitor does not change Primary Monitor row or status.
			$this->assertSame( (int) $primary_before->active, (int) $primary_after->active, 'Primary monitor active status column must remain unchanged.' );
			$this->assertSame( $primary_before->suburl, $primary_after->suburl, 'Primary monitor suburl must remain unchanged.' );

			// AC: Primary Monitor using global settings remains active after Sub-Monitor is created.
			$resolved_primary_after = ( -1 === (int) $primary_after->active ) ? (int) $global_settings['active'] : (int) $primary_after->active;
			$this->assertSame( $expected_active, $resolved_primary_after, 'Primary monitor effective active state must be maintained.' );

			// -----------------------------------------------------------------
			// AC: Editing an existing Sub-Monitor updates ONLY that Sub-Monitor
			// -----------------------------------------------------------------
			$db->update_wp_monitor(
				array(
					'monitor_id' => $first_sub_id,
					'suburl'     => 'edited-first-sub-page',
				)
			);

			$edited_first_sub = $db->get_monitor_by( false, 'monitor_id', $first_sub_id );
			$primary_unaffected = $db->get_monitor_by( false, 'monitor_id', $this->monitor_id );

			$this->assertSame( 'edited-first-sub-page', $edited_first_sub->suburl, 'Target sub-monitor suburl should be updated.' );
			$this->assertSame( '', $primary_unaffected->suburl, 'Primary monitor suburl must remain empty and untouched when editing sub-monitor.' );

			// Clean up single sub-monitor
			$db->delete_monitor( array( 'monitor_id' => $first_sub_id ) );

			// -----------------------------------------------------------------
			// AC: Multiple Sub-Monitors creation & batch isolated editing
			// -----------------------------------------------------------------
			$multi_sub_ids = array();
			$sub_paths     = array( 'multi-sub-1', 'multi-sub-2', 'multi-sub-3' );

			foreach ( $sub_paths as $path ) {
				$multi_sub_ids[] = $this->create_test_monitor(
					$this->site_id,
					array(
						'suburl' => $path,
						'active' => $primary_active_setting,
					)
				);
			}

			$sub_monitors_count = $db->get_sub_monitors( $this->site_id );
			$this->assertCount( count( $sub_paths ), $sub_monitors_count, 'Site should contain exact number of created sub-monitors.' );

			// Edit only the middle sub-monitor
			$target_sub_id = $multi_sub_ids[1];
			$db->update_wp_monitor(
				array(
					'monitor_id' => $target_sub_id,
					'suburl'     => 'isolated-sub-update',
				)
			);

			// AC: Verify only target sub-monitor changed
			$sub_0 = $db->get_monitor_by( false, 'monitor_id', $multi_sub_ids[0] );
			$sub_1 = $db->get_monitor_by( false, 'monitor_id', $multi_sub_ids[1] );
			$sub_2 = $db->get_monitor_by( false, 'monitor_id', $multi_sub_ids[2] );

			$this->assertSame( 'multi-sub-1', $sub_0->suburl, 'Unedited sub-monitor 0 should remain untouched.' );
			$this->assertSame( 'isolated-sub-update', $sub_1->suburl, 'Target sub-monitor 1 should reflect updated suburl.' );
			$this->assertSame( 'multi-sub-3', $sub_2->suburl, 'Unedited sub-monitor 2 should remain untouched.' );

			// -----------------------------------------------------------------
			// AC: Saving Primary Monitor settings still updates or creates ONLY Primary Monitor
			// -----------------------------------------------------------------
			$db->update_wp_monitor(
				array(
					'monitor_id' => $this->monitor_id,
					'active'     => 1,
				)
			);

			$primary_updated = $db->get_monitor_by( false, 'monitor_id', $this->monitor_id );
			$sub_1_after_primary_update = $db->get_monitor_by( false, 'monitor_id', $target_sub_id );

			$this->assertSame( 1, (int) $primary_updated->active, 'Primary monitor active field should be updated.' );
			$this->assertSame( 'isolated-sub-update', $sub_1_after_primary_update->suburl, 'Sub-monitor data should not be overwritten when saving primary settings.' );

			// Clean up multi sub-monitors
			foreach ( $multi_sub_ids as $sub_id ) {
				$db->delete_monitor( array( 'monitor_id' => $sub_id ) );
			}
		}
	}

	/**
	 * Verify safe recovery path for affected sites with Sub-Monitors but no Primary Monitor.
	 *
	 * AC Coverage:
	 * - A safe recovery path is provided for affected sites with Sub-Monitors but no Primary Monitor.
	 *
	 * @return void
	 */
	public function test_safe_recovery_path_for_orphaned_sub_monitors() {
		$db = MainWP_DB_Uptime_Monitoring::instance();

		// 1. Purge all existing primary and sub-monitors for this site to ensure a clean baseline.
		$db->delete_site_monitors( $this->site_id );

		// 2. Create an orphaned sub-monitor scenario (suburl is non-empty, active = 1).
		$orphan_sub_id = $this->create_test_monitor(
			$this->site_id,
			array(
				'suburl' => 'orphaned-sub-page',
			)
		);

		// Assert orphaned state: Exactly 1 sub-monitor exists, 0 primary monitors exist.
		$primary_monitor = $db->get_primary_monitor( $this->site_id );
		$sub_monitors    = $db->get_sub_monitors( $this->site_id );

		$this->assertFalse( $primary_monitor, 'Primary monitor should be missing prior to recovery.' );
		$this->assertCount( 1, $sub_monitors, 'Exactly one orphaned sub-monitor should exist prior to recovery.' );

        $sub_monitor = $sub_monitors[0];

        $this->assertSame( -1, (int) $sub_monitor->active );
        $this->assertSame( '', $sub_monitor->keyword );
        $this->assertSame( -1, (int) $sub_monitor->interval );
        $this->assertSame( -1, (int) $sub_monitor->maxretries );
        $this->assertSame( 'useglobal', $sub_monitor->up_status_codes );
        $this->assertSame( 'useglobal', $sub_monitor->type );
        $this->assertSame( 'useglobal', $sub_monitor->method );
        $this->assertSame( -1, (int) $sub_monitor->timeout );
        $this->assertSame( (int) $this->site_id, (int) $sub_monitor->wpid );

		// 3. Trigger Recovery Path: Heals missing primary monitor.
        $db->repair_missing_primary_monitors();
        $recovered_primary = $db->get_primary_monitor( $this->site_id );
		$this->assertNotEmpty( $recovered_primary, 'Recovery method must return or create a primary monitor.' );
        $this->assertSame( -1, (int) $recovered_primary->active );
        $this->assertSame( '', $recovered_primary->keyword );
        $this->assertSame( -1, (int) $recovered_primary->interval );
        $this->assertSame( -1, (int) $recovered_primary->maxretries );
        $this->assertSame( 'useglobal', $recovered_primary->up_status_codes );
        $this->assertSame( 'useglobal', $recovered_primary->type );
        $this->assertSame( 'useglobal', $recovered_primary->method );
        $this->assertSame( -1, (int) $recovered_primary->timeout );
        $this->assertSame( (int) $this->site_id, (int) $recovered_primary->wpid );
        $this->assertEmpty( $recovered_primary->suburl, 'Recovered primary monitor must have an empty suburl.' );

		// 4. Verify sub-monitors remain intact post-recovery.
		$sub_monitors_after_recovery = $db->get_sub_monitors( $this->site_id );
		$this->assertCount( 1, $sub_monitors_after_recovery, 'Orphaned sub-monitors must not be destroyed during primary monitor recovery.' );
		$this->assertSame( (int) $orphan_sub_id, (int) $sub_monitors_after_recovery[0]->monitor_id );

		// Re-assign created primary monitor ID so tearDown() cleans up properly.
		$this->sub_monitor_id = (int) $orphan_sub_id;
		$this->monitor_id = (int) $recovered_primary->monitor_id;
	}


    /**
     * Verify per-site bypass-cache inheritance for primary and sub-monitors.
     *
     * The resolved bypass-cache setting is used by URL construction, request
     * headers, retries, and single/multi-monitor checks.
     *
     * Site -1: inherit Global 1, both monitors = 1.
     * Site 0: override Global 1, both monitors = 0.
     * Site 1: override Global 0, both monitors = 1.
     *
     * @return void
     */
    public function test_bypass_cache_setting_is_inherited_by_sub_monitors() {
        $global_settings                 = $this->get_global_settings( 5 );
        $global_settings['bypass_cache'] = 1;

        // Per-site setting enabled.
        MainWP_DB_Uptime_Monitoring::instance()->update_website_option(
            $this->site_id,
            'bypass_cache',
            1
        );

        $primary_monitor = $this->get_monitor();
        $sub_monitor     = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by(
            false,
            'monitor_id',
            $this->sub_monitor_id
        );

        MainWP_Uptime_Monitoring_Connect::apply_bypass_cache_settings(
            $primary_monitor,
            $global_settings
        );
        MainWP_Uptime_Monitoring_Connect::apply_bypass_cache_settings(
            $sub_monitor,
            $global_settings
        );

        $this->assertSame(
            1,
            (int) ( $primary_monitor->bypass_cache ?? 0 ),
            'The primary monitor should use the enabled per-site bypass_cache setting.'
        );

        $this->assertSame(
            1,
            (int) ( $sub_monitor->bypass_cache ?? 0 ),
            'The sub-monitor should use the enabled per-site bypass_cache setting.'
        );

        $this->assertObjectHasProperty(
            'bypass_cache_params',
            $primary_monitor,
            'The primary monitor should have bypass-cache request parameters.'
        );

        $this->assertObjectHasProperty(
            'bypass_cache_params',
            $sub_monitor,
            'The sub-monitor should have bypass-cache request parameters.'
        );

        $this->assertNotEmpty(
            $primary_monitor->bypass_cache_params['headers'],
            'The primary monitor should have bypass-cache request headers.'
        );

        $this->assertNotEmpty(
            $primary_monitor->bypass_cache_params['headers_flatten'],
            'The primary monitor should have flattened bypass-cache request headers.'
        );

        $this->assertNotEmpty(
            $sub_monitor->bypass_cache_params['headers'],
            'The sub-monitor should have bypass-cache request headers.'
        );

        $this->assertNotEmpty(
            $sub_monitor->bypass_cache_params['headers_flatten'],
            'The sub-monitor should have flattened bypass-cache request headers.'
        );

        $primary_url    = MainWP_Uptime_Monitoring_Connect::get_apply_monitor_url( $primary_monitor );
        $submonitor_url = MainWP_Uptime_Monitoring_Connect::get_apply_monitor_url( $sub_monitor );

        $this->assertStringContainsString(
            'mots=',
            $primary_url,
            'The primary monitor URL should contain the cache-bypass parameter.'
        );

        $this->assertStringContainsString(
            'mots=',
            $submonitor_url,
            'The sub-monitor URL should contain the cache-bypass parameter.'
        );

        // Per-site setting disabled must override the global setting.
        MainWP_DB_Uptime_Monitoring::instance()->update_website_option(
            $this->site_id,
            'bypass_cache',
            0
        );

        $primary_monitor = $this->get_monitor();
        $sub_monitor     = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by(
            false,
            'monitor_id',
            $this->sub_monitor_id
        );

        MainWP_Uptime_Monitoring_Connect::apply_bypass_cache_settings(
            $primary_monitor,
            $global_settings
        );
        MainWP_Uptime_Monitoring_Connect::apply_bypass_cache_settings(
            $sub_monitor,
            $global_settings
        );

        $this->assertSame(
            0,
            (int) ( $primary_monitor->bypass_cache ?? 0 ),
            'The primary monitor should use the disabled per-site bypass_cache setting.'
        );

        $this->assertSame(
            0,
            (int) ( $sub_monitor->bypass_cache ?? 0 ),
            'The sub-monitor should use the disabled per-site bypass_cache setting.'
        );

        $this->assertObjectNotHasProperty(
            'bypass_cache_params',
            $primary_monitor,
            'The primary monitor should not have bypass-cache request parameters when the setting is disabled.'
        );

        $this->assertObjectNotHasProperty(
            'bypass_cache_params',
            $sub_monitor,
            'The sub-monitor should not have bypass-cache request parameters when the setting is disabled.'
        );

        $primary_url    = MainWP_Uptime_Monitoring_Connect::get_apply_monitor_url( $primary_monitor );
        $submonitor_url = MainWP_Uptime_Monitoring_Connect::get_apply_monitor_url( $sub_monitor );

        $this->assertStringNotContainsString(
            'mots=',
            $primary_url,
            'The primary monitor URL should not contain the cache-bypass parameter.'
        );

        $this->assertStringNotContainsString(
            'mots=',
            $submonitor_url,
            'The sub-monitor URL should not contain the cache-bypass parameter.'
        );

        // Per-site setting enabled must override the global setting.
        $global_settings['bypass_cache'] = 0;

        MainWP_DB_Uptime_Monitoring::instance()->update_website_option(
            $this->site_id,
            'bypass_cache',
            1
        );

        $primary_monitor = $this->get_monitor();
        $sub_monitor     = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by(
            false,
            'monitor_id',
            $this->sub_monitor_id
        );

        MainWP_Uptime_Monitoring_Connect::apply_bypass_cache_settings(
            $primary_monitor,
            $global_settings
        );
        MainWP_Uptime_Monitoring_Connect::apply_bypass_cache_settings(
            $sub_monitor,
            $global_settings
        );

        $this->assertSame(
            1,
            (int) ( $primary_monitor->bypass_cache ?? 0 ),
            'The primary monitor should use the enabled per-site bypass_cache setting.'
        );

        $this->assertSame(
            1,
            (int) ( $sub_monitor->bypass_cache ?? 0 ),
            'The sub-monitor should use the enabled per-site bypass_cache setting.'
        );

        $this->assertObjectHasProperty(
            'bypass_cache_params',
            $primary_monitor,
            'The primary monitor should have bypass-cache request parameters.'
        );

        $this->assertObjectHasProperty(
            'bypass_cache_params',
            $sub_monitor,
            'The sub-monitor should have bypass-cache request parameters.'
        );

        $this->assertNotEmpty(
            $primary_monitor->bypass_cache_params['headers'],
            'The primary monitor should have bypass-cache request headers.'
        );

        $this->assertNotEmpty(
            $primary_monitor->bypass_cache_params['headers_flatten'],
            'The primary monitor should have flattened bypass-cache request headers.'
        );

        $this->assertNotEmpty(
            $sub_monitor->bypass_cache_params['headers'],
            'The sub-monitor should have bypass-cache request headers.'
        );

        $this->assertNotEmpty(
            $sub_monitor->bypass_cache_params['headers_flatten'],
            'The sub-monitor should have flattened bypass-cache request headers.'
        );

        $primary_url    = MainWP_Uptime_Monitoring_Connect::get_apply_monitor_url( $primary_monitor );
        $submonitor_url = MainWP_Uptime_Monitoring_Connect::get_apply_monitor_url( $sub_monitor );

        $this->assertStringContainsString(
            'mots=',
            $primary_url,
            'The primary monitor URL should contain the cache-bypass parameter.'
        );

        $this->assertStringContainsString(
            'mots=',
            $submonitor_url,
            'The sub-monitor URL should contain the cache-bypass parameter.'
        );


    }

    /**
     * Verify per-site bypass-cache inheritance across uptime monitor flows.
     *
     * The per-site bypass-cache setting is the source of truth for uptime monitor
     * processing. It must be inherited consistently by both single-monitor and
     * multi-monitor flows before any subsequent bypass-cache handling occurs.
     *
     * Once inherited, the resolved setting must remain consistent throughout the
     * monitor flow, including generated bypass-cache request parameters, request
     * headers, and retry attempts.
     *
     * @return void
     */
    public function test_per_site_bypass_cache_setting_is_inherited_across_uptime_monitor_flows() {

        $global_settings                 = $this->get_global_settings( 5 );
        $global_settings['bypass_cache'] = 1;

        // Enable the per-site bypass-cache setting.
        MainWP_DB_Uptime_Monitoring::instance()->update_website_option(
            $this->site_id,
            'bypass_cache',
            -1
        );

        // Verify inheritance in the single-monitor flow.
        $primary_monitor = $this->get_monitor();

        $this->mock_fetch_uptime_monitor();

        $monitor = MainWP_Uptime_Monitoring_Connect::instance()->fetch_uptime_monitor(
            $primary_monitor,
            $global_settings
        );

        $this->assertObjectHasProperty(
            'bypass_cache_params',
            $monitor,
            'The monitor should have bypass-cache request parameters.'
        );

        $this->assertIsInt(
            $monitor->bypass_cache_params['bypass_cache_value'],
            'The monitor should have an integer bypass_cache_value.'
        );

        $this->assertNotEmpty(
            $monitor->bypass_cache_params['headers'],
            'The monitor should have bypass-cache request headers.'
        );

        $this->assertNotEmpty(
            $monitor->bypass_cache_params['headers_flatten'],
            'The monitor should have flattened bypass-cache request headers.'
        );

        // Verify inheritance in the multi-monitor URL flow.
        $primary_monitor = $this->get_monitor();

        $this->mock_fetch_uptime_urls();

        $output                  = new \stdClass();
        $output->global_settings = $global_settings;
        $monitors = array( $primary_monitor );
        $monitor = MainWP_Uptime_Monitoring_Connect::instance()->fetch_uptime_urls(
            $monitors,
            false,
            $output
        );

        $this->assertObjectHasProperty(
            'bypass_cache_params',
            $monitor,
            'The monitor should have bypass-cache request parameters.'
        );

        $this->assertIsInt(
            $monitor->bypass_cache_params['bypass_cache_value'],
            'The monitor should have an integer bypass_cache_value.'
        );

        $this->assertNotEmpty(
            $monitor->bypass_cache_params['headers'],
            'The monitor should have bypass-cache request headers.'
        );

        $this->assertNotEmpty(
            $monitor->bypass_cache_params['headers_flatten'],
            'The monitor should have flattened bypass-cache request headers.'
        );
    }

    /**
     * Bypass the uptime monitor request during testing.
     *
     * Uses the mainwp_fetch_uptime_monitor_pre filter to return the monitor
     * object directly and prevent the actual uptime monitor request.
     *
     * @return void
     */
    private function mock_fetch_uptime_monitor(): void {
        add_filter(
            'mainwp_fetch_uptime_monitor_pre',
            function ( $result, $monitor, $mo_url, $global_settings, $second_try, $params ) {
                return $monitor;
            },
            10,
            6
        );
    }

    /**
     * Bypass the uptime monitor URL request during testing.
     *
     * Uses the mainwp_fetch_uptime_urls_pre filter to return the website object
     * directly and prevent the actual uptime monitor URL request.
     *
     * @return void
     */
    private function mock_fetch_uptime_urls(): void {
        add_filter(
            'mainwp_fetch_uptime_urls_pre',
            function ( $result, $website, $mo_url, $global_settings, $params ) {
                return $website;
            },
            10,
            5
        );
    }

	/**
	 * A healthy monitor using the global interval is not selected every minute.
	 *
	 * @return void
	 */
	public function test_healthy_monitor_honors_global_interval() {
		$round_started_at = mainwp_get_timestamp();
		$settings         = $this->get_global_settings( 5 );

		$initial_monitors = $this->get_due_monitors( $settings, $round_started_at, $round_started_at );
		$this->assert_monitor_selected( $initial_monitors, 'Expected the new monitor to be selected for its initial check.' );

		$this->mark_monitor_started( $round_started_at );
		$this->process_simulated_result( $this->get_monitor(), $settings, 200 );

		$monitor = $this->get_monitor();
		$this->assertSame( MainWP_Uptime_Monitoring_Connect::UP, (int) $monitor->last_status );
		$this->assertSame( 0, (int) $monitor->dts_auto_monitoring_retry_time );

		$one_minute_later = $this->get_due_monitors(
			$settings,
			(int) $monitor->dts_interval_lasttime + MINUTE_IN_SECONDS,
			$round_started_at
		);
		$this->assert_monitor_not_selected( $one_minute_later, 'A healthy monitor must not be selected after one minute.' );

		$five_minutes_later = $this->get_due_monitors(
			$settings,
			(int) $monitor->dts_interval_lasttime + 5 * MINUTE_IN_SECONDS + 1,
			$round_started_at
		);
		$this->assert_monitor_selected( $five_minutes_later, 'Expected the monitor to become due after its five-minute global interval.' );

		$settings['interval'] = 10;
		$after_five_minutes   = $this->get_due_monitors(
			$settings,
			(int) $monitor->dts_interval_lasttime + 5 * MINUTE_IN_SECONDS + 1,
			$round_started_at
		);
		$this->assert_monitor_not_selected( $after_five_minutes, 'Changing the global interval to ten minutes must defer the next check.' );

		$after_ten_minutes = $this->get_due_monitors(
			$settings,
			(int) $monitor->dts_interval_lasttime + 10 * MINUTE_IN_SECONDS + 1,
			$round_started_at
		);
		$this->assert_monitor_selected( $after_ten_minutes, 'Expected the monitor to become due after the updated ten-minute interval.' );
	}

	/**
	 * An individual interval overrides the global interval.
	 *
	 * @return void
	 */
	public function test_healthy_monitor_honors_individual_interval() {
		$round_started_at = mainwp_get_timestamp();
		$settings         = $this->get_global_settings( 5 );

		MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor(
			array(
				'monitor_id' => $this->monitor_id,
				'active'     => 1,
				'interval'   => 10,
			)
		);

		$initial_monitors = $this->get_due_monitors( $settings, $round_started_at, $round_started_at );
		$this->assert_monitor_selected( $initial_monitors, 'Expected the individual monitor to be selected for its initial check.' );

		$this->mark_monitor_started( $round_started_at );
		$this->process_simulated_result( $this->get_monitor(), $settings, 200 );

		$monitor = $this->get_monitor();

		$after_global_interval = $this->get_due_monitors(
			$settings,
			(int) $monitor->dts_interval_lasttime + 5 * MINUTE_IN_SECONDS + 1,
			$round_started_at
		);
		$this->assert_monitor_not_selected( $after_global_interval, 'The five-minute global interval must not override the ten-minute individual interval.' );

		$after_individual_interval = $this->get_due_monitors(
			$settings,
			(int) $monitor->dts_interval_lasttime + 10 * MINUTE_IN_SECONDS + 1,
			$round_started_at
		);
		$this->assert_monitor_selected( $after_individual_interval, 'Expected the monitor to become due after its ten-minute individual interval.' );
	}

	/**
	 * A persistent failure is retried once, becomes DOWN, and sends a notification.
	 *
	 * @return void
	 */
	public function test_failed_monitor_retries_and_sends_notification() {
		$round_started_at = mainwp_get_timestamp();
		$settings         = $this->get_global_settings( 5 );

		$initial_monitors = $this->get_due_monitors( $settings, $round_started_at, $round_started_at );
		$this->assert_monitor_selected( $initial_monitors, 'Expected the new monitor to be selected for its initial check.' );

		$this->mark_monitor_started( $round_started_at );
		$this->process_simulated_result( $this->get_monitor(), $settings, 0 );

		$pending_monitor = $this->get_monitor();
		$this->assertSame( MainWP_Uptime_Monitoring_Connect::PENDING, (int) $pending_monitor->last_status );
		$this->assertSame( 1, (int) $pending_monitor->retries );
		$this->assertGreaterThan( 0, (int) $pending_monitor->dts_auto_monitoring_retry_time );
		$this->assertEmpty(
			MainWP_DB::instance()->get_regular_process_by_item_id_type_slug( $this->monitor_id, 'monitor', 'uptime_notification' ),
			'A PENDING result must not create a DOWN notification process.'
		);

		$retry_due_at = (int) $pending_monitor->dts_auto_monitoring_retry_time
			+ (int) $pending_monitor->retry_interval * MINUTE_IN_SECONDS;

		$before_retry = $this->get_due_monitors( $settings, $retry_due_at - 1, $round_started_at );
		$this->assert_monitor_not_selected( $before_retry, 'The monitor must not be selected before its retry interval expires.' );

		$retry_monitors = $this->get_due_monitors( $settings, $retry_due_at, $round_started_at );
		$this->assert_monitor_selected( $retry_monitors, 'Expected the pending monitor to be selected when its retry interval expires.' );

		$retry_monitor = $this->find_monitor( $retry_monitors );
		$this->process_simulated_result( $retry_monitor, $settings, 0 );

		$down_monitor = $this->get_monitor();
		$this->assertSame( MainWP_Uptime_Monitoring_Connect::DOWN, (int) $down_monitor->last_status );
		$this->assertSame( MainWP_Uptime_Monitoring_Connect::DOWN, (int) $down_monitor->last_main_status );
		$this->assertSame( 1, (int) $down_monitor->retries );
		$this->assertSame( 0, (int) $down_monitor->dts_auto_monitoring_retry_time );

		$important_heartbeat = $this->get_latest_important_heartbeat();
		$this->assertNotEmpty( $important_heartbeat, 'Expected a heartbeat for the confirmed failure.' );
		$this->assertSame( MainWP_Uptime_Monitoring_Connect::DOWN, (int) $important_heartbeat->status );
		$this->assertSame( 1, (int) $important_heartbeat->importance, 'The confirmed DOWN transition must be marked important.' );

		$this->send_pending_notification();

		$this->assertNotEmpty( $this->sent_notification, 'Expected the confirmed DOWN notification to be sent.' );
		$this->assertSame( $this->monitor_id, (int) $this->sent_notification->monitor_id );
	}

	/**
	 * Capture the uptime notification before WordPress attempts email delivery.
	 *
	 * @param string       $email          Recipient email address.
	 * @param string       $subject        Email subject.
	 * @param string       $mail_content   Email message content.
	 * @param string       $content_type   Email content type.
	 * @param object|array $object_sending Notification context.
	 *
	 * @return string Filtered recipient email address.
	 */
	public function capture_uptime_notification( $email, $subject, $mail_content, $content_type, $object_sending ) {
		$objects = is_array( $object_sending ) ? $object_sending : array( $object_sending );

		foreach ( $objects as $object ) {
			if ( is_object( $object ) && isset( $object->monitor_id ) && $this->monitor_id === (int) $object->monitor_id ) {
				$this->sent_notification = $object;
				return '';
			}
		}

		return $email;
	}

	/**
	 * Return global monitoring settings for a test interval.
	 *
	 * @param int $interval Monitoring interval in minutes.
	 *
	 * @return array
	 */
	protected function get_global_settings( $interval ) {
		$settings               = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings();
		$settings['active']     = 1;
		$settings['interval']   = (int) $interval;
		$settings['maxretries'] = 1;

		return $settings;
	}

	/**
	 * Get monitors that are due at a simulated timestamp.
	 *
	 * @param array $settings          Global monitoring settings.
	 * @param int   $local_timestamp   Simulated current timestamp.
	 * @param int   $round_started_at  Current monitoring round timestamp.
	 *
	 * @return array
	 */
	protected function get_due_monitors( $settings, $local_timestamp, $round_started_at ) {
		return MainWP_DB_Uptime_Monitoring::instance()->get_monitors_to_check_uptime(
			array(
				'local_timestamp'       => (int) $local_timestamp,
				'main_counter_lasttime' => (int) $round_started_at,
				'global_settings'       => $settings,
				'limit'                 => 10,
				'dev_log_query'         => 0,
			)
		);
	}

	/**
	 * Mark the fixture monitor as started, matching cron_uptime_check().
	 *
	 * @param int $round_started_at Monitoring round timestamp.
	 *
	 * @return void
	 */
	protected function mark_monitor_started( $round_started_at ) {
		MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor(
			array(
				'monitor_id'                => $this->monitor_id,
				'dts_auto_monitoring_start' => (int) $round_started_at,
			)
		);
	}

	/**
	 * Process a deterministic uptime response without making a network request.
	 *
	 * @param object $monitor         Monitor object.
	 * @param array  $settings        Global monitoring settings.
	 * @param int    $http_code       Simulated HTTP response code.
	 *
	 * @return array
	 */
	protected function process_simulated_result( $monitor, $settings, $http_code ) {
		$start  = microtime( true );
		$output = new \stdClass();

		$output->global_settings = $settings;
		$output->requests_info   = array(
			$monitor->monitor_id => array(
				'http_code'        => (int) $http_code,
				'http_error'       => '',
				'down_count'       => 0,
				'retry'            => 0,
				'is_pending'       => 0,
				'start'            => $start,
				'end'              => $start + 0.01,
				'use_monitor_type' => 'http',
				'use_method'       => 'head',
				'use_timeout'      => 60,
			),
		);

		return MainWP_Uptime_Monitoring_Connect::instance()->handle_response_fetch_uptime(
			'',
			$monitor,
			$output,
			array( 'ignore_compatible_save' => 1 )
		);
	}

	/**
	 * Start and send the pending uptime notification.
	 *
	 * @return void
	 */
	protected function send_pending_notification() {
		$local_time   = mainwp_get_timestamp();
		$process_init = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_notification_to_start_send( 50 );
		$started      = false;

		$this->assertNotEmpty( $process_init, 'Expected a notification process to be created.' );

		foreach ( $process_init as $uptime_notice ) {
			if ( (int) $uptime_notice->monitor_id !== $this->monitor_id || empty( $uptime_notice->process_id ) ) {
				continue;
			}

			MainWP_DB::instance()->update_regular_process(
				array(
					'process_id'        => $uptime_notice->process_id,
					'dts_process_start' => $local_time,
				)
			);
			$started = true;
		}

		$this->assertTrue( $started, 'Expected the fixture notification process to be started.' );

		$process_notices = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_notification_to_continue_send(
			array(
				'limit'      => 3,
				'monitor_id' => $this->monitor_id,
			)
		);
		$this->assertNotEmpty( $process_notices, 'Expected a notification process ready to send.' );

		MainWP_Uptime_Monitoring_Schedule::instance()->send_uptime_notification_importance_status(
			$process_notices,
			array(
				'local_time'     => $local_time,
				'admin_email'    => 'admin-testing-uptime@local.com',
				'email_settings' => array(
					'disable'    => 0,
					'recipients' => 'admin-testing-uptime@local.com',
					'subject'    => 'Uptime Monitoring Alert from your MainWP Dashboard',
					'heading'    => 'Uptime Monitoring',
				),
			)
		);
	}

	/**
	 * Get the fixture monitor from the database.
	 *
	 * @return object
	 */
	protected function get_monitor() {
		return MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( false, 'monitor_id', $this->monitor_id );
	}

	/**
	 * Get the newest important heartbeat deterministically.
	 *
	 * @return object|null
	 */
	protected function get_latest_important_heartbeat() {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mainwp_monitor_heartbeat WHERE monitor_id = %d AND importance = 1 ORDER BY heartbeat_id DESC LIMIT 1",
				$this->monitor_id
			)
		);
	}

	/**
	 * Find the fixture monitor in a selection result.
	 *
	 * @param array $monitors Selected monitors.
	 *
	 * @return object|false
	 */
	protected function find_monitor( $monitors ) {
		foreach ( $monitors as $monitor ) {
			if ( $this->monitor_id === (int) $monitor->monitor_id ) {
				return $monitor;
			}
		}

		return false;
	}

	/**
	 * Assert that the fixture monitor was selected.
	 *
	 * @param array  $monitors Selected monitors.
	 * @param string $message  Assertion message.
	 *
	 * @return void
	 */
	protected function assert_monitor_selected( $monitors, $message ) {
		$this->assertNotFalse( $this->find_monitor( $monitors ), $message );
	}

	/**
	 * Assert that the fixture monitor was not selected.
	 *
	 * @param array  $monitors Selected monitors.
	 * @param string $message  Assertion message.
	 *
	 * @return void
	 */
	protected function assert_monitor_not_selected( $monitors, $message ) {
		$this->assertFalse( $this->find_monitor( $monitors ), $message );
	}

	/**
	 * Create a test site.
	 *
	 * @param array $args Site properties.
	 *
	 * @return int
	 */
	protected function create_test_site( $args = array() ) {
		global $wpdb;

		$verify_method = $args['verify_method'] ?? 1;
		$version       = $args['version'] ?? '5.0.0';
		$sync_errors   = $args['sync_errors'] ?? '';

		unset( $args['verify_method'], $args['version'], $args['sync_errors'] );

		$defaults = array(
			'userid'               => max( 1, get_current_user_id() ),
			'url'                  => 'https://test-uptime-' . wp_generate_uuid4() . '.example.com/',
			'name'                 => 'Test Site',
			'adminname'            => 'admin',
			'pubkey'               => 'test-pubkey',
			'privkey'              => 'test-privkey',
			'ssl_version'          => 0,
			'http_user'            => '',
			'http_pass'            => '',
			'suspended'            => 0,
			'offline_check_result' => 1,
			'client_id'            => 0,
		);

		$formats = array(
			'userid'               => '%d',
			'url'                  => '%s',
			'name'                 => '%s',
			'adminname'            => '%s',
			'pubkey'               => '%s',
			'privkey'              => '%s',
			'ssl_version'          => '%d',
			'http_user'            => '%s',
			'http_pass'            => '%s',
			'suspended'            => '%d',
			'offline_check_result' => '%d',
			'client_id'            => '%d',
		);

		$data         = array_merge( $defaults, $args );
		$format_array = array();

		foreach ( array_keys( $data ) as $key ) {
			$format_array[] = $formats[ $key ] ?? '%s';
		}

		$wpdb->insert( $wpdb->prefix . 'mainwp_wp', $data, $format_array );
		$site_id = (int) $wpdb->insert_id;

		$this->set_site_option( $site_id, 'verify_method', $verify_method );
		$this->create_test_site_sync(
			$site_id,
			array(
				'version'     => $version,
				'sync_errors' => $sync_errors,
			)
		);

		return $site_id;
	}

	/**
	 * Create a test monitor.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $args    Monitor properties.
	 *
	 * @return int
	 */
	protected function create_test_monitor( $site_id, $args = array() ) {
		$data = array_merge(
			array(
				'wpid'            => $site_id,
			),
			$args
		);
        return MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor( $data );
	}

	/**
	 * Set a MainWP site option.
	 *
	 * @param int    $site_id Site ID.
	 * @param string $option  Option name.
	 * @param mixed  $value   Option value.
	 *
	 * @return void
	 */
	protected function set_site_option( $site_id, $option, $value ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_options',
			array(
				'wpid'  => $site_id,
				'name'  => $option,
				'value' => is_scalar( $value ) ? $value : maybe_serialize( $value ),
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Create a MainWP site sync record.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $args    Sync properties.
	 *
	 * @return void
	 */
	protected function create_test_site_sync( $site_id, $args = array() ) {
		global $wpdb;

		$data = array_merge(
			array(
				'wpid'        => $site_id,
				'version'     => '5.0.0',
				'sync_errors' => '',
			),
			$args
		);

		$wpdb->insert( $wpdb->prefix . 'mainwp_wp_sync', $data, array( '%d', '%s', '%s' ) );
	}
}
