<?php
/**
 * Update-check progress across Dashboard timezone changes.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Auto_Updates_DB;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Settings;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_Utility;

/** Exercises the real scheduler and database queue with offline Child responses. */
class Test_MWP_1813_Timezone_Sync_Cycle extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Fixture site IDs.
	 *
	 * @var int[]
	 */
	private $sites = array();
	/**
	 * Child stats requests in transport order.
	 *
	 * @var int[]
	 */
	private $requests = array();
	/**
	 * Successful syncs in hook order.
	 *
	 * @var int[]
	 */
	private $synced = array();
	/**
	 * Scheduled completion hook count.
	 *
	 * @var int
	 */
	private $completed = 0;
	/**
	 * Site context before the test.
	 *
	 * @var int
	 */
	private $previous_wpid = 0;
	/**
	 * Process abort setting before cron initialization.
	 *
	 * @var bool
	 */
	private $previous_ignore_abort = false;
	/**
	 * Process execution limit before cron initialization.
	 *
	 * @var int
	 */
	private $previous_time_limit = 0;
	/**
	 * Completion timestamp preceding the tested cycle.
	 *
	 * @var int
	 */
	private $previous_finish = 0;

	/** Seeds sixteen sites with distinct progress markers. */
	public function setUp(): void {
		parent::setUp();
		$this->sites                 = array();
		$this->requests              = array();
		$this->synced                = array();
		$this->completed             = 0;
		$this->previous_wpid         = MainWP_System_Utility::get_current_wpid();
		$this->previous_ignore_abort = ignore_user_abort();
		$this->previous_time_limit   = (int) ini_get( 'max_execution_time' );

		// MainWP's cURL transport bypasses the WordPress HTTP API.
		$this->assertTrue( defined( 'MAINWP_TESTING_MODE' ) && MAINWP_TESTING_MODE );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'sync_response' ), PHP_INT_MAX, 4 );
		add_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
		add_action( 'mainwp_site_synced', array( $this, 'site_synced' ) );
		add_action( 'mainwp_synced_all_sites', array( $this, 'cycle_completed' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_DB_Common::instance()->get_user_extension_by_user_id( get_current_user_id() );
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 1 );
		foreach ( array(
			'mainwp_batch_updates_is_running',
			'mainwp_batch_individual_updates_is_running',
			'mainwp_automatic_updates_is_running',
			'mainwp_automatic_update_next_run_timestamp',
			'mainwp_automaticDailyUpdate',
			'mainwp_pluginAutomaticDailyUpdate',
			'mainwp_themeAutomaticDailyUpdate',
			'mainwp_transAutomaticDailyUpdate',
			'mainwp_check_http_response',
			'mainwp_backup_before_upgrade',
			'mainwp_delay_autoupdate',
		) as $option ) {
			update_option( $option, 0 );
		}
		update_option( 'mainwp_frequency_AutoUpdate', 'daily' );
		update_option( 'mainwp_frequencyDailyUpdate', 2 );
		update_option( 'mainwp_timeDailyUpdate', '00:00' );
		update_option( 'mainwp_updatescheck_is_running', '' );
		update_option( 'mainwp_updatescheck_ready_sendmail', '' );
		update_option( 'mainwp_updatescheck_start_last_timestamp', 0 );
		update_option( 'mainwp_updatescheck_last_run', '[]' );
		update_option( 'mainwp_dailydigest_last', gmdate( 'd/m/Y', MainWP_Utility::get_timestamp() ) );
		delete_option( 'mainwp_auto_sync_manual_trigger_start_last_timestamp' );
		delete_option( 'mainwp_adjust_auto_sync_run_time' );
		$this->previous_finish = (int) MainWP_Utility::get_timestamp() - 3 * DAY_IN_SECONDS;
		update_option( 'mainwp_updatescheck_last_timestamp', $this->previous_finish );

		for ( $index = 0; $index < 16; ++$index ) {
			$site          = $this->create_test_site( array( 'name' => 'Timezone site ' . $index ) );
			$this->sites[] = $site;
			// Distinct old markers pin the SQL's existing ascending batch order.
			MainWP_DB::instance()->update_website_sync_values(
				$site,
				array(
					'dtsAutomaticSyncStart' => $this->previous_finish + $index,
					'dtsAutomaticSync'      => $this->previous_finish + $index,
				)
			);
		}
	}

	/** Restores process settings and removes the transport stubs. */
	public function tearDown(): void {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'sync_response' ), PHP_INT_MAX );
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX );
		remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
		remove_action( 'mainwp_site_synced', array( $this, 'site_synced' ) );
		remove_action( 'mainwp_synced_all_sites', array( $this, 'cycle_completed' ) );
		MainWP_System_Utility::set_current_wpid( $this->previous_wpid );
		ignore_user_abort( $this->previous_ignore_abort );
		set_time_limit( $this->previous_time_limit );
		parent::tearDown();
	}

	/**
	 * Blocks WordPress HTTP traffic, including ancillary sync requests.
	 *
	 * @param mixed  $preempt Previous response.
	 * @param array  $args Request arguments.
	 * @param string $url Request URL.
	 * @return \WP_Error
	 */
	public function block_http( $preempt, $args, $url ) {
		return new \WP_Error( 'mwp_1813_offline', 'HTTP transport disabled in timezone tests.' );
	}

	/**
	 * Supplies a successful no-update Child response before cURL starts.
	 *
	 * @param mixed  $preempt Previous response.
	 * @param object $website Selected site.
	 * @param string $what Child action.
	 * @param array  $params Child request arguments.
	 * @return array
	 */
	public function sync_response( $preempt, $website, $what, $params ) {
		if ( 'get_site_icon' === $what ) {
			return array();
		}
		$this->assertSame( 'stats', $what, 'Only the real sync path should request Child data.' );
		$this->assertContains( (int) $website->id, $this->sites );
		$this->requests[] = (int) $website->id;
		return array(
			'version'             => '6.2',
			'wpversion'           => '6.2',
			'wp_updates'          => '',
			'plugin_updates'      => array(),
			'theme_updates'       => array(),
			'translation_updates' => array(),
		);
	}

	/**
	 * Records successful syncs separately from transport attempts.
	 *
	 * @param object $website Synced site.
	 */
	public function site_synced( $website ): void {
		$this->synced[] = (int) $website->id;
	}

	/** Counts scheduled-cycle completion hooks. */
	public function cycle_completed(): void {
		++$this->completed;
	}

	/** Supplies the reported eight-hour and one-hour backward changes. */
	public static function backward_offsets(): array {
		return array(
			'eight hours' => array( -7 ),
			'one hour'    => array( 0 ),
		);
	}

	/**
	 * Completed sites must leave the queue even when local time moves backward.
	 *
	 * @dataProvider backward_offsets
	 * @param int $offset New UTC offset.
	 */
	public function test_backward_change_finishes_fourth_batch_and_fifth_invocation( $offset ): void {
		$start = $this->first_three_batches();
		update_option( 'gmt_offset', $offset );
		$this->finish_scheduled_cycle( $start );
	}

	/** Pins the existing four-site batches and completion options. */
	public function test_unchanged_timezone_keeps_batches_and_options(): void {
		$start = $this->first_three_batches();
		$this->finish_scheduled_cycle( $start );
	}

	/** A forward change must preserve all sixteen sites and cycle boundaries. */
	public function test_forward_change_keeps_batches_and_options(): void {
		$start = $this->first_three_batches();
		update_option( 'gmt_offset', 3 );
		$this->finish_scheduled_cycle( $start );
	}

	/** Completed cycles must retain their scheduled start and finish. */
	public function test_backward_change_after_completion_does_not_restart_cycle(): void {
		$start = $this->first_three_batches();
		$this->finish_scheduled_cycle( $start );
		$finished = get_option( 'mainwp_updatescheck_last_timestamp' );
		$history  = get_option( 'mainwp_updatescheck_last_run' );
		update_option( 'gmt_offset', -7 );
		$this->run_batch( array(), 'A timezone change after completion must wait for the next scheduled cycle.' );
		$this->assertSame( '', get_option( 'mainwp_updatescheck_is_running' ) );
		$this->assertSame( $finished, get_option( 'mainwp_updatescheck_last_timestamp' ) );
		$this->assertSame( $start, (int) get_option( 'mainwp_updatescheck_start_last_timestamp' ) );
		$this->assertSame( $history, get_option( 'mainwp_updatescheck_last_run' ) );
		$this->assertSame( 1, $this->completed );
		$this->assertSame( $this->sites, $this->synced );
	}

	/** Existing below-start completion markers must recover without user action. */
	public function test_existing_stuck_cycle_retries_remaining_sites_at_most_once(): void {
		$start = (int) MainWP_Utility::get_timestamp() - MINUTE_IN_SECONDS;
		update_option( 'mainwp_updatescheck_start_last_timestamp', $start );
		update_option( 'mainwp_updatescheck_is_running', 'Y' );
		update_option( 'gmt_offset', -7 );
		$stuck = (int) MainWP_Utility::get_timestamp() - MINUTE_IN_SECONDS;
		foreach ( $this->sites as $index => $site ) {
			$marker = $index < 12 ? $start : $stuck + $index;
			MainWP_DB::instance()->update_website_sync_values(
				$site,
				array(
					'dtsAutomaticSyncStart' => $marker,
					'dtsAutomaticSync'      => $marker,
				)
			);
		}
		$this->run_batch( array_slice( $this->sites, 12 ), 'Recover the four sites left below the saved cycle start.' );
		$this->run_batch( array(), 'Previously stuck sites must leave the queue after at most one additional sync.' );
		$this->assertSame( '', get_option( 'mainwp_updatescheck_is_running' ) );
		$this->assertSame( 'Y', get_option( 'mainwp_updatescheck_ready_sendmail' ) );
		$this->assertSame( 1, $this->completed );
		$this->assertSame( array_slice( $this->sites, 12 ), $this->synced );
		$this->assertSame( $start, (int) get_option( 'mainwp_updatescheck_start_last_timestamp' ) );
	}

	/** Returns manual cycles with changed clocks and a future saved marker. */
	public static function manual_offsets(): array {
		return array(
			'backward'                      => array( -7, -MINUTE_IN_SECONDS ),
			'unchanged'                     => array( 1, -MINUTE_IN_SECONDS ),
			'future saved scheduled marker' => array( -7, HOUR_IN_SECONDS ),
		);
	}

	/**
	 * Manual cycles keep their existing queue selection and finish across clock changes.
	 *
	 * @dataProvider manual_offsets
	 * @param int $offset Dashboard offset during continuation.
	 * @param int $scheduled_delta Scheduled marker seconds relative to the manual start.
	 */
	public function test_manual_cycle_finishes_with_its_existing_selector( $offset, $scheduled_delta ): void {
		$manual_start    = (int) MainWP_Utility::get_timestamp() - MINUTE_IN_SECONDS;
		$scheduled_start = $manual_start + $scheduled_delta;
		update_option( 'mainwp_updatescheck_start_last_timestamp', $scheduled_start );
		update_option( 'mainwp_auto_sync_manual_trigger_start_last_timestamp', $manual_start );
		foreach ( $this->sites as $index => $site ) {
			MainWP_DB::instance()->update_website_sync_values(
				$site,
				array(
					'dtsAutomaticSyncStart' => $manual_start - DAY_IN_SECONDS + $index,
					'dtsAutomaticSync'      => $manual_start - DAY_IN_SECONDS + $index,
				)
			);
		}
		$this->run_batch( array_slice( $this->sites, 0, 4 ), 'The manual marker admits the first batch.' );
		update_option( 'gmt_offset', $offset );
		for ( $batch = 1; $batch < 4; ++$batch ) {
			$this->run_batch( array_slice( $this->sites, 4 * $batch, 4 ), 'The manual cycle keeps its original four-site batch order.' );
			$this->assertSame( 'Y', get_option( 'mainwp_updatescheck_is_running' ) );
			$this->assertSame( $manual_start, (int) get_option( 'mainwp_auto_sync_manual_trigger_start_last_timestamp' ) );
		}
		$this->run_batch( array(), 'The fifth manual invocation completes the cycle.' );
		$this->assertFalse( get_option( 'mainwp_auto_sync_manual_trigger_start_last_timestamp' ) );
		$this->assertSame( '', get_option( 'mainwp_updatescheck_is_running' ) );
		$this->assertSame( $scheduled_start, (int) get_option( 'mainwp_updatescheck_start_last_timestamp' ) );
		$this->assertSame( $this->sites, $this->synced );
		$this->assertSame( 1, $this->completed );
	}

	/** The hours-interval entry point uses the same active-cycle progress. */
	public function test_hours_interval_cycle_finishes_after_backward_change(): void {
		add_filter( 'mainwp_updatescheck_hours_interval', array( $this, 'hourly_interval' ) );
		try {
			$start = $this->first_three_batches();
			update_option( 'gmt_offset', -7 );
			$this->finish_scheduled_cycle( $start );
		} finally {
			remove_filter( 'mainwp_updatescheck_hours_interval', array( $this, 'hourly_interval' ) );
		}
	}

	/** Returns the existing one-hour interval for its entry-path test. */
	public function hourly_interval(): int {
		return 1;
	}

	/** Runs three scheduled batches and returns the saved cycle start. */
	private function first_three_batches(): int {
		$before = (int) MainWP_Utility::get_timestamp();
		for ( $batch = 0; $batch < 3; ++$batch ) {
			$this->run_batch( array_slice( $this->sites, 4 * $batch, 4 ), 'Scheduled sync keeps its four-site ascending queue order.' );
			$this->assertSame( 'Y', get_option( 'mainwp_updatescheck_is_running' ) );
			$this->assertSame( '', get_option( 'mainwp_updatescheck_ready_sendmail' ) );
			$this->assertSame( $this->previous_finish, (int) get_option( 'mainwp_updatescheck_last_timestamp' ) );
			$this->assertSame( 0, $this->completed );
		}
		$start = (int) get_option( 'mainwp_updatescheck_start_last_timestamp' );
		$this->assert_timestamp_between( $before, (int) MainWP_Utility::get_timestamp(), $start );
		$this->assertSame( array( gmdate( 'Y-m-d H:i:s', $start ) ), json_decode( get_option( 'mainwp_updatescheck_last_run' ), true ) );
		$this->assertSame( array_slice( $this->sites, 0, 12 ), $this->synced );
		wp_cache_flush();
		$this->assertSame( 4, (int) MainWP_Auto_Updates_DB::instance()->get_websites_check_updates_count( $start ) );
		$this->assertSame( 'Processing your websites.', MainWP_Settings::get_websites_automatic_update_time()['next'] );
		return $start;
	}

	/**
	 * Requires exactly one final batch followed by the completion invocation.
	 *
	 * @param int $start Saved scheduled cycle start.
	 */
	private function finish_scheduled_cycle( int $start ): void {
		$history = get_option( 'mainwp_updatescheck_last_run' );
		$this->run_batch( array_slice( $this->sites, 12 ), 'The fourth batch must sync the remaining four sites.' );
		$this->assertSame( 'Y', get_option( 'mainwp_updatescheck_is_running' ) );
		$this->assertSame( '', get_option( 'mainwp_updatescheck_ready_sendmail' ) );
		$this->assertSame( 0, $this->completed );
		$before     = (int) MainWP_Utility::get_timestamp();
		$utc_before = time();
		$this->run_batch( array(), 'The fifth invocation must observe an empty queue, not repeat the fourth batch.' );
		$this->assertSame( '', get_option( 'mainwp_updatescheck_is_running' ) );
		$this->assertSame( 'Y', get_option( 'mainwp_updatescheck_ready_sendmail' ) );
		$this->assertSame( $start, (int) get_option( 'mainwp_updatescheck_start_last_timestamp' ) );
		$this->assertSame( $history, get_option( 'mainwp_updatescheck_last_run' ) );
		$this->assert_timestamp_between( $before, (int) MainWP_Utility::get_timestamp(), (int) get_option( 'mainwp_updatescheck_last_timestamp' ) );
		$this->assert_timestamp_between( $utc_before, time(), (int) get_option( 'mainwp_last_synced_all_sites' ) );
		$this->assertSame( gmdate( 'd/m/Y', (int) get_option( 'mainwp_updatescheck_last_timestamp' ) ), get_option( 'mainwp_updatescheck_last' ) );
		$this->assertSame( $this->sites, $this->requests, 'Each site must be requested exactly once across the five invocations.' );
		$this->assertSame( $this->sites, $this->synced );
		$this->assertSame( 1, $this->completed );
		$this->assertSame( array(), MainWP_Auto_Updates_DB::instance()->get_websites_check_updates( 4, $start ) );

		// A new web request has a fresh object cache for the summary queries.
		wp_cache_flush();
		$summary = MainWP_Settings::get_websites_automatic_update_time();
		$this->assertNotSame( 'Processing your websites.', $summary['next'] );
	}

	/**
	 * Runs the unmodified cron entry point and verifies successful batch order.
	 *
	 * @param int[]  $expected Expected site IDs.
	 * @param string $message Failure context.
	 */
	private function run_batch( array $expected, string $message ): void {
		$before = count( $this->requests );
		MainWP_System_Cron_Jobs::instance()->cron_updates_check();
		$this->assertSame( $expected, array_slice( $this->requests, $before ), $message );
		$this->assertSame( $this->requests, $this->synced, 'Every selected site must complete the real MainWP_Sync path successfully.' );
	}

	/**
	 * Allows a real-time call to cross a second boundary without sleeping.
	 *
	 * @param int $before Earliest timestamp.
	 * @param int $after Latest timestamp.
	 * @param int $actual Stored timestamp.
	 */
	private function assert_timestamp_between( int $before, int $after, int $actual ): void {
		$this->assertGreaterThanOrEqual( $before, $actual );
		$this->assertLessThanOrEqual( $after, $actual );
	}
}
