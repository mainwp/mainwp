<?php
/**
 * Regular automatic sync progress across a REST update-all batch.
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
class Test_MWP_2161_Rest_Update_All_Keeps_Sync_Progress extends \WP_UnitTestCase {
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
	 * Child plugin update requests made by the batch.
	 *
	 * @var int[]
	 */
	private $batch_requests = array();
	/**
	 * Pending plugin update kept by the stats response.
	 *
	 * @var array
	 */
	private $plugin_updates = array(
		'sample/plugin.php' => array(
			'Name'    => 'Sample',
			'Version' => '1.0',
			'update'  => array( 'new_version' => '1.1' ),
		),
	);
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

	/** Seeds sixteen sites, including a disconnected site and a suspended site. */
	public function setUp(): void {
		parent::setUp();
		$this->sites                 = array();
		$this->regular_requests      = array();
		$this->batch_requests        = array();
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
					'name'            => 'Overlap site ' . $index,
					'suspended'       => 15 === $index ? 1 : 0,
					'sync_errors'     => 10 === $index ? 'Unreachable' : '',
					'plugin_upgrades' => wp_json_encode( $this->plugin_updates ),
					'theme_upgrades'  => '[]',
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
		return new \WP_Error( 'mwp_2161_offline', 'HTTP transport disabled in overlap tests.' );
	}

	/**
	 * Keeps the pending update available after a regular sync.
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
		$this->assertContains( (int) $website->id, $this->sites );
		if ( 'upgradeplugintheme' === $what ) {
			$this->batch_requests[] = (int) $website->id;
			return array( 'upgrades' => array( 'sample/plugin.php' => true ) );
		}
		$this->assertSame( 'stats', $what, 'Only sync and plugin update requests are expected.' );
		$this->regular_requests[] = (int) $website->id;
		return array(
			'version'             => '6.2',
			'wpversion'           => '6.2',
			'wp_updates'          => '',
			'plugin_updates'      => $this->plugin_updates,
			'theme_updates'       => array(),
			'translation_updates' => array(),
		);
	}

	/** Counts regular sync completion hooks. */
	public function cycle_completed(): void {
		++$this->completed;
	}

	/** A scoped batch must leave the regular sync's pending sites and completion intact. */
	public function test_regular_sync_resumes_its_pending_sites_after_rest_update_all(): void {
		$this->run_regular_pass( array_slice( $this->sites, 0, 4 ), 'The regular sync starts with the first four sites.' );
		$this->run_regular_pass( array_slice( $this->sites, 4, 4 ), 'The regular sync continues with the next four sites.' );
		$start   = (int) get_option( 'mainwp_updatescheck_start_last_timestamp' );
		$markers = array();
		foreach ( $this->sites as $site ) {
			$markers[ $site ] = (int) MainWP_DB::instance()->get_website_by_id( $site )->dtsAutomaticSyncStart;
		}
		$this->assertSame( 'Y', get_option( 'mainwp_updatescheck_is_running' ) );

		$subset = array( $this->sites[1], $this->sites[5], $this->sites[8], $this->sites[10], $this->sites[13], $this->sites[15] );
		$scope  = static function () use ( $subset ) {
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- API-key scope bypasses the DB layer without defining a process-wide constant.
				if ( MainWP_DB::class === ( $frame['class'] ?? '' ) ) {
					return 'all';
				}
			}
			return $subset;
		};
		// The forward offset stands in for elapsed time between cron and REST requests.
		update_option( 'gmt_offset', 2 );
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope, 10 );
		try {
			$response = $this->without_ability(
				'mainwp/run-updates-v1',
				static function () {
					return ( new \MainWP_Rest_Updates_Controller() )->update_all_items_start( new \WP_REST_Request( 'POST', '/mainwp/v2/updates' ) );
				}
			);
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope, 10 );
		}
		$this->assertNotWPError( $response );
		$this->assertSame( 1, $response->get_data()['success'] );

		wp_cache_flush();
		$this->assertSame( 8, (int) MainWP_Auto_Updates_DB::instance()->get_websites_check_updates_count( $start ), 'Starting the batch must keep the regular sync queue.' );
		foreach ( $markers as $site => $marker ) {
			$this->assertSame( $marker, (int) MainWP_DB::instance()->get_website_by_id( $site )->dtsAutomaticSyncStart, 'The batch must preserve every regular sync claim.' );
		}
		$this->assertSame( 1, (int) get_option( 'mainwp_batch_updates_is_running' ) );

		$regular_before_batch = $this->regular_requests;
		for ( $pass = 0; $pass < 20 && 1 === (int) get_option( 'mainwp_batch_updates_is_running' ); ++$pass ) { // phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed -- A cron pass can finish the batch before the next iteration.
			MainWP_System_Cron_Jobs::instance()->cron_updates_check();
			$this->assertSame( $regular_before_batch, $this->regular_requests, 'The regular sync must wait while the batch runs.' );
		}
		$this->assertSame( 0, (int) get_option( 'mainwp_batch_updates_is_running' ), 'The batch must finish.' );
		$expected = array( $this->sites[1], $this->sites[5], $this->sites[8], $this->sites[13] );
		$actual   = $this->batch_requests;
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual, 'The batch must update each scoped connected unsuspended site exactly once.' );
		$this->assertSame( 0, $this->completed );
		$this->assertSame( 1, (int) get_option( 'mainwp_last_synced_all_sites' ) );

		$this->run_regular_pass( array_slice( $this->sites, 8, 4 ), 'The regular sync must resume with the sites it had not reached.' );
		$this->assertSame( 0, $this->completed, 'Completion must wait for the remaining sites.' );
		$this->assertSame( 1, (int) get_option( 'mainwp_last_synced_all_sites' ) );
		$this->run_regular_pass( array_slice( $this->sites, 12, 4 ), 'The regular sync must reach its last four sites.' );
		$this->assertSame( 0, $this->completed, 'Completion must wait for the empty pass.' );
		$this->assertSame( 1, (int) get_option( 'mainwp_last_synced_all_sites' ) );

		$before = time();
		$this->run_regular_pass( array(), 'The regular sync completes once every site was synced.' );
		$this->assertSame( 1, $this->completed );
		$this->assertGreaterThanOrEqual( $before, (int) get_option( 'mainwp_last_synced_all_sites' ) );
		$this->assertSame( '', get_option( 'mainwp_updatescheck_is_running' ) );
		$this->assertSame( $this->sites, $this->regular_requests, 'The regular sync must request each site exactly once.' );
		$this->assertSame( $start, (int) get_option( 'mainwp_updatescheck_start_last_timestamp' ) );
	}

	/** Stale, absent and finished batch membership must not cause updates outside the run. */
	public function test_batch_queue_follows_its_own_marker(): void {
		$start = (int) MainWP_Utility::get_timestamp();
		$cases = array(
			'queued pending'      => array( $this->sites[0], $start, $start - 1 ),
			'queued finished'     => array( $this->sites[1], $start, $start ),
			'older run'           => array( $this->sites[2], $start - 10, $start - 1 ),
			'never queued'        => array( $this->sites[3], null, $start - 1 ),
			'queued disconnected' => array( $this->sites[10], $start, $start - 1 ),
			'queued suspended'    => array( $this->sites[15], $start, $start - 1 ),
		);
		foreach ( $cases as list( $site, $queued_at, $synced_at ) ) {
			if ( null !== $queued_at ) {
				MainWP_DB::instance()->update_website_option( $site, 'batch_updates_queue_time', $queued_at );
			}
			MainWP_DB::instance()->update_website_sync_values( $site, array( 'dtsAutomaticSync' => $synced_at ) );
		}

		$queue = MainWP_Auto_Updates_DB::instance()->get_websites_to_continue_updates( 10, $start, true, true );
		$ids   = array_map( 'intval', wp_list_pluck( $queue, 'id' ) );
		sort( $ids );
		$this->assertSame( array( $this->sites[0] ), $ids );
		$this->assertFalse( MainWP_Auto_Updates_DB::instance()->get_websites_to_continue_updates( 10, 0, true, true ) );
	}

	/**
	 * Forces the legacy path without changing the process-wide Abilities API availability.
	 *
	 * @param string   $name Ability name.
	 * @param callable $run Endpoint call.
	 * @return mixed
	 */
	private function without_ability( $name, callable $run ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $run();
		}
		$registry = \WP_Abilities_Registry::get_instance();
		$property = new \ReflectionProperty( $registry, 'registered_abilities' );
		$property->setAccessible( true );
		$abilities = $property->getValue( $registry );
		$remaining = $abilities;
		unset( $remaining[ $name ] );
		$property->setValue( $registry, $remaining );
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		try {
			return $run();
		} finally {
			$property->setValue( $registry, $abilities );
		}
	}

	/**
	 * Runs the unmodified cron entry point and checks which sites the regular sync requested.
	 *
	 * @param int[]  $expected Expected site IDs.
	 * @param string $message Failure context.
	 */
	private function run_regular_pass( array $expected, string $message ): void {
		$before_regular = count( $this->regular_requests );
		$before_batch   = count( $this->batch_requests );
		MainWP_System_Cron_Jobs::instance()->cron_updates_check();
		$this->assertSame( $expected, array_slice( $this->regular_requests, $before_regular ), $message );
		$this->assertSame( $before_batch, count( $this->batch_requests ), 'Batch updates must not run during a regular pass.' );
	}
}
