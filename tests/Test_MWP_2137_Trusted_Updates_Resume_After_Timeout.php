<?php
/**
 * Trusted Updates runs that hit the four-hour cutoff resume with the sites they did not reach.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Auto_Updates_DB;
use MainWP\Dashboard\MainWP_Cron_Jobs_Auto_Updates;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_Utility;

/** Drives the Trusted Updates cron passes with offline Child responses. */
class Test_MWP_2137_Trusted_Updates_Resume_After_Timeout extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Fixture site IDs in ascending ID order; the last one is suspended.
	 *
	 * @var int[]
	 */
	private $sites = array();
	/**
	 * Sites Trusted Updates requested Child data for, in order.
	 *
	 * @var int[]
	 */
	private $requests = array();
	/**
	 * Sites whose Child keeps reporting a core update after every upgrade request.
	 *
	 * @var int[]
	 */
	private $stuck = array();
	/**
	 * Site whose sync request kills the cron request, standing in for a fatal error or a killed PHP process.
	 *
	 * @var int
	 */
	private $aborts = 0;
	/**
	 * Sites per pass.
	 *
	 * @var int
	 */
	private $sites_per_pass = 2;
	/**
	 * Log lines written while the logger is switched on.
	 *
	 * @var string[]
	 */
	private $log = array();

	/** Seeds thirteen sites with old sync stamps; the last one is suspended. */
	public function setUp(): void {
		parent::setUp();
		$this->sites    = array();
		$this->requests = array();
		$this->stuck    = array();
		$this->aborts   = 0;
		$this->log      = array();

		$this->sites_per_pass = 2;

		// MainWP's cURL transport bypasses the WordPress HTTP API.
		$this->assertTrue( defined( 'MAINWP_TESTING_MODE' ) && MAINWP_TESTING_MODE );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'sync_response' ), PHP_INT_MAX, 4 );
		add_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
		add_filter( 'mainwp_cron_bulk_update_sites_limit', array( $this, 'two_sites_per_pass' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_DB_Common::instance()->get_user_extension_by_user_id( get_current_user_id() );
		foreach ( array(
			'mainwp_batch_updates_is_running',
			'mainwp_batch_individual_updates_is_running',
			'mainwp_automatic_updates_is_running',
			'mainwp_automatic_updates_ready_to_send_notification',
			'mainwp_automatic_updates_start_lasttime',
			'mainwp_automaticDailyUpdate',
			'mainwp_themeAutomaticDailyUpdate',
			'mainwp_transAutomaticDailyUpdate',
			'mainwp_backup_before_upgrade',
			'mainwp_delay_autoupdate',
		) as $option ) {
			update_option( $option, 0 );
		}
		update_option( 'mainwp_pluginAutomaticDailyUpdate', 1 );
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 0 );
		update_option( 'mainwp_frequency_AutoUpdate', 'daily' );
		delete_option( 'mainwp_automatic_updates_resume_site_id' );
		delete_option( 'mainwp_automatic_updates_last_site_id' );
		$this->pin_next_run();

		$old = (int) MainWP_Utility::get_timestamp() - 3 * DAY_IN_SECONDS;
		for ( $index = 0; $index < 13; ++$index ) {
			$site          = $this->create_test_site(
				array(
					'name'             => 'Trusted site ' . $index,
					'suspended'        => 12 === $index ? 1 : 0,
					'automatic_update' => 1,
				)
			);
			$this->sites[] = $site;
			MainWP_DB::instance()->update_website_sync_values( $site, array( 'dtsAutomaticSync' => $old + $index ) );
		}
	}

	/** Removes the transport stubs. */
	public function tearDown(): void {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'sync_response' ), PHP_INT_MAX );
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX );
		remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
		remove_filter( 'mainwp_cron_bulk_update_sites_limit', array( $this, 'two_sites_per_pass' ) );
		parent::tearDown();
	}

	/**
	 * Keeps the per-pass budget small so a few passes stand in for four hours.
	 *
	 * @return int
	 */
	public function two_sites_per_pass() {
		return $this->sites_per_pass;
	}

	/**
	 * Collects log lines.
	 *
	 * @param string $text Log line.
	 */
	public function capture_log( $text ): void {
		$this->log[] = (string) $text;
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
		return new \WP_Error( 'mwp_2137_offline', 'HTTP transport disabled in Trusted Updates tests.' );
	}

	/**
	 * Supplies a successful no-update Child response, so each requested site finishes in its pass. Stuck sites
	 * report a core update that the upgrade request never clears.
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
		$stuck = in_array( (int) $website->id, $this->stuck, true );
		if ( 'upgrade' === $what && $stuck ) {
			return array();
		}
		$this->assertSame( 'stats', $what, 'Sites without updates only need the sync request.' );
		$this->requests[] = (int) $website->id;
		if ( (int) $website->id === $this->aborts ) {
			throw new \RuntimeException( 'mwp_2137_abort' );
		}
		return array(
			'version'             => '6.2',
			'wpversion'           => '6.2',
			'wp_updates'          => $stuck ? '6.9' : '',
			'plugin_updates'      => array(),
			'theme_updates'       => array(),
			'translation_updates' => array(),
		);
	}

	/**
	 * Each run that hits the cutoff hands the next run the sites it did not reach, even after the regular sync
	 * has restamped every site in between, so three timed-out runs cover the whole fleet and the fourth wraps.
	 */
	public function test_timed_out_runs_reach_every_site(): void {
		$this->assertSame( array_slice( $this->sites, 0, 4 ), $this->run_until_timeout( 2 ), 'The first run starts with the lowest site IDs.' );

		$this->regular_sync_restamps_every_site();
		$this->assertSame( array_slice( $this->sites, 4, 4 ), $this->run_until_timeout( 2 ), 'The second run must start with the sites the first run did not reach.' );

		$this->regular_sync_restamps_every_site();
		$this->assertSame( array_slice( $this->sites, 8, 4 ), $this->run_until_timeout( 2 ), 'The third run must start with the sites neither earlier run reached.' );

		$processed = array_unique( $this->requests );
		sort( $processed );
		$this->assertSame( array_slice( $this->sites, 0, 12 ), $processed, 'Three timed-out runs must reach every unsuspended site.' );

		$this->regular_sync_restamps_every_site();
		$this->assertSame( array_slice( $this->sites, 0, 4 ), $this->run_until_timeout( 2 ), 'Once every site was reached, the next run wraps to the lowest IDs.' );
		$this->assertNotContains( $this->sites[12], $this->requests, 'Suspended sites stay out of Trusted Updates.' );
	}

	/**
	 * Sites that stay pending all run (here a core update the Child keeps reporting) must not hold the next run's
	 * starting point, or every later run would spend its budget on them again.
	 */
	public function test_sites_that_never_finish_do_not_pin_later_runs(): void {
		update_option( 'mainwp_automaticDailyUpdate', 1 );
		$this->stuck = array( $this->sites[0], $this->sites[1] );

		$this->assertSame( array( $this->sites[0], $this->sites[1], $this->sites[0], $this->sites[1] ), $this->run_until_timeout( 2 ), 'Unfinished sites keep their place for the rest of the run.' );

		$this->regular_sync_restamps_every_site();
		$before = count( $this->requests );
		$this->start_run();
		for ( $pass = 1; $pass < 7; ++$pass ) {
			$this->run_pass();
		}
		$this->assertSame(
			array_merge( array_slice( $this->sites, 2, 10 ), array( $this->sites[0], $this->sites[1], $this->sites[0], $this->sites[1] ) ),
			array_slice( $this->requests, $before ),
			'The next run must move past the sites the previous run kept retrying, and reaches them again after the wrap.'
		);
		// A site added after the cursor passed its place was never tried, so the log must not claim every site was.
		$added = $this->create_test_site( array( 'automatic_update' => 1 ) );
		update_option( 'gmt_offset', (int) get_option( 'gmt_offset' ) + 5 );
		$this->with_log(
			function () {
				$this->assertFalse( MainWP_Cron_Jobs_Auto_Updates::instance()->check_to_run_auto_updates() );
			}
		);
		$this->assertSame( 'Automatic updates went once through the site list, but some sites are still pending. Next run starts after site ID ' . $this->sites[1] . '.', $this->last_log_line() );
		$this->assertNotContains( $added, $this->requests );

		$this->regular_sync_restamps_every_site();
		$this->assertSame( array_slice( $this->sites, 2, 4 ), $this->run_until_timeout( 2 ), 'A run that reached every site must not hand the next run the sites it kept retrying.' );
	}

	/** A site that kills the request during its sync must not make later runs skip the sites batched with it. */
	public function test_site_that_aborts_the_request_does_not_skip_its_batch(): void {
		$this->sites_per_pass = 3;
		$this->aborts         = $this->sites[0];

		$this->assertSame( array( $this->sites[0], $this->sites[0] ), $this->run_until_timeout( 2 ), 'The aborting site ends every pass before the rest of its batch.' );

		$this->regular_sync_restamps_every_site();
		$this->assertSame( array_slice( $this->sites, 1, 3 ), $this->run_until_timeout( 1 ), 'The next run must start right after the site that aborted.' );
	}

	/** A run that hits the cutoff leaves a resume point; a run that reaches every site clears it. */
	public function test_run_end_records_whether_every_site_was_processed(): void {
		$this->run_until_timeout( 2 );
		$this->assertSame( $this->sites[4], (int) get_option( 'mainwp_automatic_updates_resume_site_id' ), 'A timed-out run must record the first site it did not reach.' );
		$this->assertSame( 'Automatic updates ended before every site was processed. Next run starts with site ID ' . $this->sites[4] . '.', $this->last_log_line() );

		$this->regular_sync_restamps_every_site();
		$this->start_run();
		for ( $pass = 0; $pass < 10 && 1 === (int) get_option( 'mainwp_automatic_updates_is_running' ); ++$pass ) {
			$this->run_pass();
		}
		$this->assertSame( 0, (int) get_option( 'mainwp_automatic_updates_is_running' ), 'The run must finish on its own.' );
		$this->assertSame(
			array_merge( array_slice( $this->sites, 0, 4 ), array_slice( $this->sites, 4, 8 ), array_slice( $this->sites, 0, 4 ) ),
			$this->requests,
			'The resumed run must start at the recorded site, wrap around, and reach the earlier sites last.'
		);
		$this->assertSame( 0, (int) get_option( 'mainwp_automatic_updates_resume_site_id' ), 'A run that reaches every site must clear the resume point.' );
		$this->assertSame( 'Automatic updates finished. Every site was processed.', $this->last_log_line() );
		$this->assertSame( 1, (int) get_option( 'mainwp_automatic_updates_ready_to_send_notification' ) );
	}

	/** Turning Trusted Updates off mid-run also keeps the sites the run did not reach for later. */
	public function test_disabled_run_records_where_to_resume(): void {
		$this->start_run();
		update_option( 'mainwp_pluginAutomaticDailyUpdate', 0 );
		$this->assertFalse( MainWP_Cron_Jobs_Auto_Updates::instance()->check_to_run_auto_updates() );
		$this->assertSame( 0, (int) get_option( 'mainwp_automatic_updates_is_running' ) );
		$this->assertSame( $this->sites[2], (int) get_option( 'mainwp_automatic_updates_resume_site_id' ) );
	}

	/** A running flag without a start time gives no queue to judge, so the stored resume point stays. */
	public function test_run_without_start_time_keeps_the_resume_point(): void {
		update_option( 'mainwp_automatic_updates_is_running', 1 );
		update_option( 'mainwp_automatic_updates_start_lasttime', 0 );
		update_option( 'mainwp_automatic_updates_resume_site_id', $this->sites[5] );
		update_option( 'mainwp_pluginAutomaticDailyUpdate', 0 );
		$this->with_log(
			function () {
				$this->assertFalse( MainWP_Cron_Jobs_Auto_Updates::instance()->check_to_run_auto_updates() );
			}
		);
		$this->assertSame( 0, (int) get_option( 'mainwp_automatic_updates_is_running' ) );
		$this->assertSame( $this->sites[5], (int) get_option( 'mainwp_automatic_updates_resume_site_id' ) );
		$this->assertSame( array(), preg_grep( '/Every site was processed/', $this->log ), 'Nothing ran, so nothing may be reported as covered.' );
	}

	/** The queue starts at the resume site, skips finished and suspended sites, and wraps to the lowest IDs. */
	public function test_selector_starts_at_the_resume_site_and_wraps_around(): void {
		$start = (int) MainWP_Utility::get_timestamp();
		// Reverse stamps make plain sync-time order differ from ID order.
		foreach ( $this->sites as $index => $site ) {
			MainWP_DB::instance()->update_website_sync_values( $site, array( 'dtsAutomaticSync' => $start - 100 - $index ) );
		}
		MainWP_DB::instance()->update_website_sync_values( $this->sites[7], array( 'dtsAutomaticSync' => $start ) );
		$db = MainWP_Auto_Updates_DB::instance();

		$this->assertSame( array_slice( $this->sites, 0, 3 ), $this->ids( $db->get_websites_to_continue_auto_updates( 3, $start, false, true ) ), 'Without a resume point the queue goes in ID order.' );
		$this->assertSame( array( $this->sites[5], $this->sites[6], $this->sites[8], $this->sites[9] ), $this->ids( $db->get_websites_to_continue_auto_updates( 4, $start, false, true, $this->sites[5] ) ), 'Finished sites leave the queue.' );
		$this->assertSame( array( $this->sites[10], $this->sites[11], $this->sites[0], $this->sites[1] ), $this->ids( $db->get_websites_to_continue_auto_updates( 4, $start, false, true, $this->sites[10] ) ), 'The queue wraps past the highest ID; suspended sites stay out.' );
		$this->assertSame( array_slice( $this->sites, 0, 2 ), $this->ids( $db->get_websites_to_continue_auto_updates( 2, $start, false, true, $this->sites[12] + 1000 ) ), 'A resume point past the last site starts over.' );

		MainWP_DB::instance()->remove_website( $this->sites[3] );
		$this->assertSame( array( $this->sites[4], $this->sites[5] ), $this->ids( $db->get_websites_to_continue_auto_updates( 2, $start, false, true, $this->sites[3] ) ), 'A deleted resume site hands over to the next ID.' );

		$this->assertFalse( $db->get_websites_to_continue_auto_updates( 2, 0, false, true, $this->sites[5] ) );
	}

	/** MWP-1495: a run stuck past four hours still ends on the next check. */
	public function test_stale_run_still_times_out(): void {
		update_option( 'mainwp_automatic_updates_is_running', 1 );
		update_option( 'mainwp_automatic_updates_start_lasttime', (int) MainWP_Utility::get_timestamp() - 5 * HOUR_IN_SECONDS );
		$this->assertFalse( MainWP_Cron_Jobs_Auto_Updates::instance()->check_to_run_auto_updates() );
		$this->assertSame( 0, (int) get_option( 'mainwp_automatic_updates_is_running' ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_automatic_updates_ready_to_send_notification' ) );
	}

	/**
	 * Starts a scheduled run, runs more passes, then lets five hours pass so the four-hour check stops it.
	 * The forward offset stands in for elapsed time and keeps the run's real start.
	 *
	 * @param int $passes Passes in the run, counting the one that starts it.
	 * @return int[] Sites requested during the run.
	 */
	private function run_until_timeout( int $passes ): array {
		$before = count( $this->requests );
		$this->start_run();
		for ( $pass = 1; $pass < $passes; ++$pass ) {
			$this->run_pass();
		}
		$this->assertSame( 1, (int) get_option( 'mainwp_automatic_updates_is_running' ), 'The run must still have sites left when the cutoff arrives.' );
		update_option( 'gmt_offset', (int) get_option( 'gmt_offset' ) + 5 );
		$this->with_log(
			function () {
				$this->assertFalse( MainWP_Cron_Jobs_Auto_Updates::instance()->check_to_run_auto_updates(), 'The cutoff must stop the run.' );
			}
		);
		$this->assertSame( 0, (int) get_option( 'mainwp_automatic_updates_is_running' ) );
		return array_slice( $this->requests, $before );
	}

	/** Makes the scheduled run due and runs its first pass. */
	private function start_run(): void {
		update_option( 'mainwp_automatic_update_next_run_timestamp', (int) MainWP_Utility::get_timestamp() - MINUTE_IN_SECONDS );
		$this->run_pass();
		$this->assertSame( 1, (int) get_option( 'mainwp_automatic_updates_is_running' ), 'The scheduled run must start.' );
		$this->pin_next_run();
	}

	/** One cron pass, as cron_updates_check() runs it when no batch update is active. */
	private function run_pass(): void {
		wp_cache_flush();
		$this->with_log(
			function () {
				$cron = MainWP_Cron_Jobs_Auto_Updates::instance();
				try {
					if ( $cron->check_to_run_auto_updates() ) {
						$cron->handle_cron_auto_updates();
					}
				} catch ( \RuntimeException $e ) {
					// The aborting site ended this request; the run stays marked as running, as after a fatal error.
					$this->assertSame( 'mwp_2137_abort', $e->getMessage() );
				}
			}
		);
	}

	/**
	 * Runs a callback with the update-check log switched on and captured.
	 *
	 * @param callable $callback Code to run.
	 */
	private function with_log( callable $callback ): void {
		$logger   = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( $logger, 'logPriority' );
		$specific = new \ReflectionProperty( $logger, 'logSpecific' );
		$priority->setAccessible( true );
		$specific->setAccessible( true );
		$prior_priority = $priority->getValue( $logger );
		$prior_specific = $specific->getValue( $logger );
		$logger->set_log_priority( MainWP_Logger::UPDATE_CHECK_LOG_PRIORITY, 1 );
		add_action( 'mainwp_before_log_data', array( $this, 'capture_log' ) );
		try {
			$callback();
		} finally {
			remove_action( 'mainwp_before_log_data', array( $this, 'capture_log' ) );
			$logger->set_log_priority( $prior_priority, $prior_specific );
		}
	}

	/**
	 * The newest captured log line about the run's coverage.
	 *
	 * @return string
	 */
	private function last_log_line(): string {
		$lines = array_values( preg_grep( '/^Automatic updates (ended|finished|went)/', $this->log ) );
		$this->assertNotEmpty( $lines, 'The run end must be logged.' );
		return end( $lines );
	}

	/**
	 * Stands in for a regular sync cycle between runs: update_auto_sync_time() stamps every site in the
	 * regular queue order, which is ID order here, after the previous run started and before the next one.
	 */
	private function regular_sync_restamps_every_site(): void {
		$synced = (int) MainWP_Utility::get_timestamp() - HOUR_IN_SECONDS;
		foreach ( $this->sites as $index => $site ) {
			MainWP_DB::instance()->update_website_sync_values( $site, array( 'dtsAutomaticSync' => $synced + $index ) );
		}
	}

	/** Keeps a run scheduled near local midnight from restarting Trusted Updates mid-test. */
	private function pin_next_run(): void {
		update_option( 'mainwp_automatic_update_next_run_timestamp', (int) MainWP_Utility::get_timestamp() + DAY_IN_SECONDS );
	}

	/**
	 * Site IDs from a selector result.
	 *
	 * @param array|false $sites Selector result.
	 * @return int[]
	 */
	private function ids( $sites ): array {
		$this->assertIsArray( $sites );
		return array_map( 'intval', wp_list_pluck( $sites, 'id' ) );
	}
}
