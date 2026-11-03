<?php
/**
 * Regular automatic sync progress across a Trusted Updates run.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Auto_Updates_DB;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_Utility;

/** Drives the real cron dispatcher with offline Child responses. */
class Test_MWP_2122_Trusted_Updates_Keeps_Sync_Progress extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Fixture site IDs in regular sync queue order.
	 *
	 * @var int[]
	 */
	private $sites = array();
	/**
	 * Child stats requests made by the regular sync.
	 *
	 * @var int[]
	 */
	private $regular_requests = array();
	/**
	 * Child stats requests made by Trusted Updates.
	 *
	 * @var int[]
	 */
	private $trusted_requests = array();
	/**
	 * Which workflow the next cron pass is expected to run.
	 *
	 * @var string
	 */
	private $phase = 'regular';
	/**
	 * Completion hook count.
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

	/** Seeds sixteen sites; the last one is suspended. */
	public function setUp(): void {
		parent::setUp();
		$this->sites                 = array();
		$this->regular_requests      = array();
		$this->trusted_requests      = array();
		$this->phase                 = 'regular';
		$this->completed             = 0;
		$this->previous_wpid         = MainWP_System_Utility::get_current_wpid();
		$this->previous_ignore_abort = ignore_user_abort();
		$this->previous_time_limit   = (int) ini_get( 'max_execution_time' );

		// MainWP's cURL transport bypasses the WordPress HTTP API.
		$this->assertTrue( defined( 'MAINWP_TESTING_MODE' ) && MAINWP_TESTING_MODE );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'sync_response' ), PHP_INT_MAX, 4 );
		add_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
		add_action( 'mainwp_synced_all_sites', array( $this, 'cycle_completed' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_DB_Common::instance()->get_user_extension_by_user_id( get_current_user_id() );
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 1 );
		foreach ( array(
			'mainwp_batch_updates_is_running',
			'mainwp_batch_individual_updates_is_running',
			'mainwp_automatic_updates_is_running',
			'mainwp_automatic_updates_ready_to_send_notification',
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
		update_option( 'mainwp_last_synced_all_sites', 1 );
		update_option( 'mainwp_dailydigest_last', gmdate( 'd/m/Y', MainWP_Utility::get_timestamp() ) );
		delete_option( 'mainwp_auto_sync_manual_trigger_start_last_timestamp' );
		delete_option( 'mainwp_adjust_auto_sync_run_time' );
		$previous_finish = (int) MainWP_Utility::get_timestamp() - 3 * DAY_IN_SECONDS;
		update_option( 'mainwp_updatescheck_last_timestamp', $previous_finish );

		for ( $index = 0; $index < 16; ++$index ) {
			$site          = $this->create_test_site(
				array(
					'name'      => 'Overlap site ' . $index,
					'suspended' => 15 === $index ? 1 : 0,
				)
			);
			$this->sites[] = $site;
			// Distinct old markers pin the regular sync's ascending queue order.
			MainWP_DB::instance()->update_website_sync_values(
				$site,
				array(
					'dtsAutomaticSyncStart' => $previous_finish + $index,
					'dtsAutomaticSync'      => $previous_finish + $index,
				)
			);
		}
	}

	/** Restores process settings and removes the transport stubs. */
	public function tearDown(): void {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'sync_response' ), PHP_INT_MAX );
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX );
		remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
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
		return new \WP_Error( 'mwp_2122_offline', 'HTTP transport disabled in overlap tests.' );
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
		if ( 'trusted' === $this->phase ) {
			$this->trusted_requests[] = (int) $website->id;
		} else {
			$this->regular_requests[] = (int) $website->id;
		}
		return array(
			'version'             => '6.2',
			'wpversion'           => '6.2',
			'wp_updates'          => '',
			'plugin_updates'      => array(),
			'theme_updates'       => array(),
			'translation_updates' => array(),
		);
	}

	/** Counts regular sync completion hooks. */
	public function cycle_completed(): void {
		++$this->completed;
	}

	/** Supplies each way a Trusted Updates run can end. */
	public static function trusted_updates_endings(): array {
		return array(
			'finishes every site' => array( 'finish' ),
			'reaches the timeout' => array( 'timeout' ),
			'is turned off'       => array( 'disable' ),
		);
	}

	/**
	 * Sites the regular sync had not reached before Trusted Updates started must still be synced
	 * by the regular run, and the run reports completion only after them.
	 *
	 * @dataProvider trusted_updates_endings
	 * @param string $ending How the Trusted Updates run ends.
	 */
	public function test_regular_sync_resumes_its_pending_sites_after_trusted_updates( $ending ): void {
		$this->run_regular_pass( array_slice( $this->sites, 0, 4 ), 'The regular sync starts with the first four sites.' );
		$this->run_regular_pass( array_slice( $this->sites, 4, 4 ), 'The regular sync continues with the next four sites.' );
		$start = (int) get_option( 'mainwp_updatescheck_start_last_timestamp' );
		$this->assertSame( 'Y', get_option( 'mainwp_updatescheck_is_running' ) );
		wp_cache_flush();
		$this->assertSame( 8, (int) MainWP_Auto_Updates_DB::instance()->get_websites_check_updates_count( $start ) );

		// Trusted Updates starts on a later cron request; the forward offset stands in for elapsed time.
		update_option( 'gmt_offset', 2 );
		update_option( 'mainwp_pluginAutomaticDailyUpdate', 1 );
		update_option( 'mainwp_automatic_update_next_run_timestamp', (int) MainWP_Utility::get_timestamp() - MINUTE_IN_SECONDS );

		$this->phase = 'trusted';
		MainWP_System_Cron_Jobs::instance()->cron_updates_check();
		$this->assertSame( 1, (int) get_option( 'mainwp_automatic_updates_is_running' ), 'Trusted Updates must start.' );
		$this->assertCount( 3, $this->trusted_requests, 'Trusted Updates runs its first three sites.' );
		$this->assertSame( 8, count( $this->regular_requests ), 'The regular sync must wait while Trusted Updates runs.' );

		wp_cache_flush();
		$this->assertSame( 8, (int) MainWP_Auto_Updates_DB::instance()->get_websites_check_updates_count( $start ), 'Starting Trusted Updates must keep the regular sync queue.' );

		if ( 'finish' === $ending ) {
			for ( $pass = 0; $pass < 10 && 1 === (int) get_option( 'mainwp_automatic_updates_is_running' ); ++$pass ) {
				MainWP_System_Cron_Jobs::instance()->cron_updates_check();
			}
			$this->assertSame( 0, (int) get_option( 'mainwp_automatic_updates_is_running' ), 'Trusted Updates must finish.' );
			$expected = array_slice( $this->sites, 0, 15 );
			$actual   = $this->trusted_requests;
			sort( $expected );
			sort( $actual );
			$this->assertSame( $expected, $actual, 'Trusted Updates must process every unsuspended site once.' );
			$this->assertSame( 0, $this->completed );
			$this->pin_next_trusted_updates_run();
			$this->phase = 'regular';
			$this->run_regular_pass( array_slice( $this->sites, 8, 4 ), 'The regular sync must resume with the sites it had not reached.' );
		} else {
			if ( 'timeout' === $ending ) {
				update_option( 'mainwp_automatic_updates_start_lasttime', (int) MainWP_Utility::get_timestamp() - 5 * HOUR_IN_SECONDS );
				$this->pin_next_trusted_updates_run();
			} else {
				update_option( 'mainwp_pluginAutomaticDailyUpdate', 0 );
			}
			$this->phase = 'regular';
			$this->run_regular_pass( array_slice( $this->sites, 8, 4 ), 'The pass that ends Trusted Updates must resume the regular sync.' );
			$this->assertSame( 0, (int) get_option( 'mainwp_automatic_updates_is_running' ), 'Trusted Updates must stop.' );
			$this->assertCount( 3, $this->trusted_requests );
		}

		$this->assertSame( 'Y', get_option( 'mainwp_updatescheck_is_running' ) );
		$this->assertSame( 0, $this->completed, 'Completion must wait for the remaining sites.' );
		$this->assertSame( 1, (int) get_option( 'mainwp_last_synced_all_sites' ) );

		$this->run_regular_pass( array_slice( $this->sites, 12, 4 ), 'The regular sync must reach its last four sites.' );
		$this->assertSame( 0, $this->completed, 'Completion must wait for the remaining sites.' );
		$this->assertSame( 1, (int) get_option( 'mainwp_last_synced_all_sites' ) );

		$before = time();
		$this->run_regular_pass( array(), 'The regular sync completes once every site was synced.' );
		$this->assertSame( '', get_option( 'mainwp_updatescheck_is_running' ) );
		$this->assertSame( 1, $this->completed );
		$this->assertGreaterThanOrEqual( $before, (int) get_option( 'mainwp_last_synced_all_sites' ) );
		$this->assertSame( $this->sites, $this->regular_requests, 'The regular sync must request each site exactly once.' );
		$this->assertSame( $start, (int) get_option( 'mainwp_updatescheck_start_last_timestamp' ) );
	}

	/** The Trusted Updates queue keeps unfinished sites and drops finished and suspended ones. */
	public function test_trusted_updates_queue_follows_its_own_progress(): void {
		$start = (int) MainWP_Utility::get_timestamp();
		$sites = array(
			'pending'      => $this->sites[0],
			'disconnected' => $this->sites[1],
			'finished'     => $this->sites[2],
			'suspended'    => $this->sites[15],
		);
		foreach ( array_slice( $this->sites, 3, 12 ) as $site ) {
			MainWP_DB::instance()->update_website_sync_values( $site, array( 'dtsAutomaticSync' => $start + 1 ) );
		}
		MainWP_DB::instance()->update_website_sync_values( $sites['disconnected'], array( 'sync_errors' => 'Unreachable' ) );
		MainWP_DB::instance()->update_website_sync_values( $sites['finished'], array( 'dtsAutomaticSync' => $start ) );

		$queue = MainWP_Auto_Updates_DB::instance()->get_websites_to_continue_auto_updates( 10, $start, false, true );
		$ids   = array_map( 'intval', wp_list_pluck( $queue, 'id' ) );
		sort( $ids );
		$this->assertSame( array( $sites['pending'], $sites['disconnected'] ), $ids );

		$this->assertFalse( MainWP_Auto_Updates_DB::instance()->get_websites_to_continue_auto_updates( 10, 0, false, true ) );
	}

	/** Keeps a run scheduled near local midnight from restarting Trusted Updates mid-test. */
	private function pin_next_trusted_updates_run(): void {
		update_option( 'mainwp_automatic_update_next_run_timestamp', (int) MainWP_Utility::get_timestamp() + DAY_IN_SECONDS );
	}

	/**
	 * Runs the unmodified cron entry point and checks which sites the regular sync requested.
	 *
	 * @param int[]  $expected Expected site IDs.
	 * @param string $message Failure context.
	 */
	private function run_regular_pass( array $expected, string $message ): void {
		$before_regular = count( $this->regular_requests );
		$before_trusted = count( $this->trusted_requests );
		MainWP_System_Cron_Jobs::instance()->cron_updates_check();
		$this->assertSame( $expected, array_slice( $this->regular_requests, $before_regular ), $message );
		$this->assertSame( $before_trusted, count( $this->trusted_requests ), 'Trusted Updates must not run during a regular pass.' );
	}
}
