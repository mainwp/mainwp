<?php
/**
 * Non-MainWP Changes on every sync entry point.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Sync;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\Module\Log\Log_Changes_Logs_Helper;

/**
 * The Child only returns Changes Logs events when the stats request carries
 * params_logs, and it purges unsynced events after its TTL (7 days by default).
 * Scheduled, REST and CLI syncs must ask for them the same way the UI sync does.
 */
class Test_MWP_2139_Sync_Changes_Logs_All_Paths extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Fixture site ID.
	 *
	 * @var int
	 */
	private $site_id = 0;

	/**
	 * params_logs value of each stats request, in transport order.
	 *
	 * @var array
	 */
	private $sent_params_logs = array();

	/**
	 * Child action, site ID and params_logs presence of each request; asserted in the
	 * test body because sync callers catch exceptions thrown inside the transport.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Whether the stubbed Child answers with changes_logs_data.
	 *
	 * @var bool
	 */
	private $child_returns_events = true;

	/**
	 * Event timestamp the stubbed Child returns.
	 *
	 * @var float
	 */
	private $event_created = 0.0;

	/**
	 * Sites that completed the real sync path.
	 *
	 * @var int[]
	 */
	private $synced = array();

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

	/** Seeds one connected site that the scheduled sync will pick. */
	public function setUp(): void {
		parent::setUp();
		$this->sent_params_logs      = array();
		$this->requests              = array();
		$this->synced                = array();
		$this->child_returns_events  = true;
		$this->event_created         = round( microtime( true ) - HOUR_IN_SECONDS, 4 );
		$this->previous_wpid         = MainWP_System_Utility::get_current_wpid();
		$this->previous_ignore_abort = ignore_user_abort();
		$this->previous_time_limit   = (int) ini_get( 'max_execution_time' );
		unset( $_POST['action'] );

		$this->reset_marker_cache();

		// MainWP's cURL transport bypasses the WordPress HTTP API.
		$this->assertTrue( defined( 'MAINWP_TESTING_MODE' ) && MAINWP_TESTING_MODE );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_response' ), PHP_INT_MAX, 4 );
		add_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
		add_action( 'mainwp_site_synced', array( $this, 'site_synced' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_DB_Common::instance()->get_user_extension_by_user_id( get_current_user_id() );

		// Scheduler options copied from Test_MWP_1813_Timezone_Sync_Cycle: a due daily cycle, no update work.
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
		$previous_finish = (int) MainWP_Utility::get_timestamp() - 3 * DAY_IN_SECONDS;
		update_option( 'mainwp_updatescheck_last_timestamp', $previous_finish );

		$this->site_id = $this->create_test_site( array( 'name' => 'MWP-2139 site' ) );
		MainWP_DB::instance()->update_website_sync_values(
			$this->site_id,
			array(
				'dtsAutomaticSyncStart' => $previous_finish,
				'dtsAutomaticSync'      => $previous_finish,
			)
		);
		// Pin the marker so the Child event is newer than what the Dashboard holds.
		MainWP_DB::instance()->update_website_option( $this->site_id, 'changes_logs_sync_last_created', $this->event_created - 60 );
	}

	/** Removes the transport stubs and request state. */
	public function tearDown(): void {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_response' ), PHP_INT_MAX );
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX );
		remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
		remove_filter( 'mainwp_module_logs_sync_changes_log', '__return_false' );
		remove_action( 'mainwp_site_synced', array( $this, 'site_synced' ) );
		unset( $_POST['action'] );
		MainWP_System_Utility::set_current_wpid( $this->previous_wpid );
		ignore_user_abort( $this->previous_ignore_abort );
		set_time_limit( $this->previous_time_limit );
		$this->reset_marker_cache();
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
		return new \WP_Error( 'mwp_2139_offline', 'HTTP transport disabled in sync tests.' );
	}

	/**
	 * Records the outgoing params_logs and answers like a Child.
	 *
	 * @param mixed  $preempt Previous response.
	 * @param object $website Selected site.
	 * @param string $what Child action.
	 * @param array  $params Child request arguments.
	 * @return array
	 */
	public function child_response( $preempt, $website, $what, $params ) {
		if ( 'get_site_icon' === $what ) {
			return array();
		}
		$this->requests[]         = array( $what, (int) $website->id, array_key_exists( 'params_logs', $params ) );
		$this->sent_params_logs[] = isset( $params['params_logs'] ) ? $params['params_logs'] : null;

		$information = array(
			'version'             => '6.2',
			'wpversion'           => '6.2',
			'wp_updates'          => '',
			'plugin_updates'      => array(),
			'theme_updates'       => array(),
			'translation_updates' => array(),
		);
		// A current Child answers only when params_logs is set, with events newer than
		// newer_than (class-changes-sync-data-helper.php); older Childs never answer.
		if ( $this->child_returns_events && ! empty( $params['params_logs'] ) && $this->event_created > (float) $params['params_logs']['newer_than'] ) {
			$information['changes_logs_data'] = array(
				array(
					'log_type_id' => 1461,
					'created_on'  => $this->event_created,
					'user_id'     => 1,
					'username'    => 'child-admin',
					'client_ip'   => '127.0.0.1',
					'action_name' => 'activated',
					'context'     => 'plugin',
					'meta_data'   => array( 'plugin' => 'Hello Dolly' ),
				),
			);
		}
		return $information;
	}

	/**
	 * Records successful syncs.
	 *
	 * @param object $website Synced site.
	 */
	public function site_synced( $website ): void {
		$this->synced[] = (int) $website->id;
	}

	/** The scheduled sync must ask for the events and store what the Child returns. */
	public function test_cron_sync_requests_and_stores_changes_logs(): void {
		MainWP_System_Cron_Jobs::instance()->cron_updates_check();

		$this->assertSame( array( $this->site_id ), $this->synced, 'The scheduled sync must sync the fixture site.' );
		$this->assert_params_logs_requested();
		$this->assert_event_stored();
	}

	/** A REST v2 site sync must ask for the events and store what the Child returns. */
	public function test_rest_v2_sync_requests_and_stores_changes_logs(): void {
		$request = new \WP_REST_Request( 'POST', '/mainwp/v2/sites/' . $this->site_id . '/sync' );
		$request->set_param( 'id_domain', (string) $this->site_id );

		$response = ( new \MainWP_Rest_Sites_Controller() )->sync_item( $request );

		$this->assertNotWPError( $response );
		$this->assertSame( 1, $response->get_data()['success'] );
		$this->assert_params_logs_requested();
		$this->assert_event_stored();
	}

	/** The UI Sync button keeps sending the same request. */
	public function test_ui_sync_still_requests_and_stores_changes_logs(): void {
		$_POST['action'] = 'mainwp_syncsites';

		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$this->assertTrue( MainWP_Sync::sync_site( $website ) );

		$this->assert_params_logs_requested();
		$this->assert_event_stored();
	}

	/** Returning false from the filter still turns the request off, for scheduled and UI syncs alike. */
	public function test_filter_false_suppresses_changes_logs_on_cron_and_ui(): void {
		add_filter( 'mainwp_module_logs_sync_changes_log', '__return_false' );

		MainWP_System_Cron_Jobs::instance()->cron_updates_check();
		$_POST['action'] = 'mainwp_syncsites';
		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$this->assertTrue( MainWP_Sync::sync_site( $website ) );

		$this->assertSame( array( $this->site_id, $this->site_id ), $this->synced );
		$this->assert_sync_requests( 2 );
		$this->assertSame( array( '', '' ), $this->sent_params_logs, 'An opted-out Dashboard must not ask the Child for events.' );
		$this->assertSame( 0, $this->stored_event_count() );
	}

	/** A Child that ignores params_logs still syncs, and nothing is stored or advanced. */
	public function test_child_without_changes_logs_support_still_syncs(): void {
		$this->child_returns_events = false;
		$marker                     = $this->sync_marker();

		MainWP_System_Cron_Jobs::instance()->cron_updates_check();

		$this->assertSame( array( $this->site_id ), $this->synced );
		$this->assert_sync_requests( 1 );
		$this->assertSame( 0, $this->stored_event_count() );
		$this->assertSame( $marker, $this->sync_marker() );
	}

	/**
	 * WP-Cron runs every due hook in one request, so the hourly reconnect job can sync a
	 * recovered site right before the scheduled sync picks it up. The second sync must
	 * ask from the marker the first one stored and must not store the events twice.
	 */
	public function test_second_sync_of_a_site_in_one_request_does_not_store_events_twice(): void {
		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$this->assertTrue( MainWP_Sync::sync_site( $website ) );
		$this->assert_event_stored();

		MainWP_System_Cron_Jobs::instance()->cron_updates_check();

		$this->assertSame( array( $this->site_id, $this->site_id ), $this->synced );
		$this->assert_sync_requests( 2 );
		$this->assertEqualsWithDelta( $this->event_created, (float) $this->sent_params_logs[1]['newer_than'], 0.0001, 'The second sync must ask for events after the stored marker.' );
		$this->assert_event_stored();
	}

	/**
	 * Asserts every Child request was a stats sync of the fixture site carrying params_logs.
	 *
	 * @param int $count Expected request count.
	 */
	private function assert_sync_requests( int $count ): void {
		$this->assertSame( array_fill( 0, $count, array( 'stats', $this->site_id, true ) ), $this->requests );
	}

	/** Asserts exactly one stats request carried the Changes Logs parameters. */
	private function assert_params_logs_requested(): void {
		$this->assert_sync_requests( 1 );
		$params = $this->sent_params_logs[0];
		$this->assertIsArray( $params, 'params_logs must be sent so the Child returns its events.' );
		$this->assertEqualsWithDelta( $this->event_created - 60, (float) $params['newer_than'], 0.0001 );
		$this->assertSame( 100, $params['events_count'] );
		$this->assertSame( 7, $params['child_logs_ttl'] );
		$this->assertArrayHasKey( 'child_logs_enabled', $params );
		$this->assertArrayHasKey( 'ignore_sync_changes_logs', $params );
		$this->assertArrayHasKey( 'ignore_sync_nonmainwp_actions', $params );
	}

	/** Asserts the returned event is stored once and the marker moved to it. */
	private function assert_event_stored(): void {
		$this->assertSame( 1, $this->stored_event_count(), 'The returned Non-MainWP Change must be stored.' );
		$this->assertEqualsWithDelta( $this->event_created, $this->sync_marker(), 0.0001 );
	}

	/** Clears the helper's per-request marker cache so each test starts from the stored option. */
	private function reset_marker_cache(): void {
		$cache = new \ReflectionProperty( Log_Changes_Logs_Helper::class, 'last_log_created' );
		$cache->setAccessible( true );
		$cache->setValue( null, array() );
	}

	/**
	 * Counts stored Non-MainWP Changes rows for the fixture site.
	 *
	 * @return int
	 */
	private function stored_event_count(): int {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_logs' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE site_id = %d AND connector = %s", $this->site_id, 'non-mainwp-changes' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the DB helper.
	}

	/**
	 * Reads the stored last-synced marker.
	 *
	 * @return float
	 */
	private function sync_marker(): float {
		return (float) MainWP_DB::instance()->get_website_option( MainWP_DB::instance()->get_website_by_id( $this->site_id ), 'changes_logs_sync_last_created' );
	}
}
