<?php
/**
 * MainWP Abilities Batch Cron Handlers Tests
 *
 * Tests for batch cron handler execution logic including chunk processing,
 * progress updates, reschedule behavior, completion detection, error handling,
 * timeout protection, and transient cleanup.
 *
 * ## Mocking Strategy
 *
 * These tests invoke cron handler methods directly with pre-seeded transient data.
 * External dependencies are mocked via the `mainwp_fetch_url_authed_pre` filter:
 * - For sync: Returns valid stats response array or throws MainWP_Exception
 * - For updates: Returns valid update response array or throws Exception
 *
 * ## Coverage
 *
 * | Handler | Coverage |
 * |---------|----------|
 * | process_sync_job() | Chunk processing, progress, reschedule, completion, errors, timeout |
 * | process_update_job() | Chunk processing, progress, reschedule, completion, errors, timeout |
 * | process_batch_job() | All operation types, chunk processing, reschedule, completion |
 * | Cross-handler | Chunk size filter, transient persistence, status transitions |
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Cron;

/**
 * Class MainWP_Abilities_Batch_Cron_Handlers_Test
 *
 * Tests for cron handler execution logic for batch operations.
 */
class MainWP_Abilities_Batch_Cron_Handlers_Test extends MainWP_Abilities_Test_Case { //phpcs:ignore -- NOSONAR - multi methods.

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Keep unrelated callbacks on shared hooks intact during cleanup.
	 *
	 * @var array<string, callable>
	 */
	private $scope_filters = array();

	/**
	 * Shared hooks may also contain callbacks installed by other tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->scope_filters as $hook => $callback ) {
			remove_filter( $hook, $callback, 10 );
		}
		$this->scope_filters = array();
		parent::tearDown();
	}

	/**
	 * Mock all sync operations to succeed.
	 *
	 * Uses mainwp_fetch_url_authed_pre filter to return a valid stats response,
	 * which causes sync_site() to return true.
	 *
	 * @return void
	 */
	private function mock_sync_success(): void {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) {
				// Only intercept 'stats' calls (sync operations).
				if ( 'stats' === $what ) {
					// Return minimal valid sync response.
					return [
						'wpversion'  => '6.4.0',
						'siteurl'    => $website->url,
						'site_info'  => [],
						'wp_updates' => [],
					];
				}
				return $result;
			},
			10,
			4
		);
	}

	/**
	 * Mock sync operations to fail for specific site IDs.
	 *
	 * Sites in $fail_site_ids will have sync return false, others succeed.
	 *
	 * @param array $fail_site_ids Array of site IDs that should fail.
	 * @return void
	 */
	private function mock_sync_partial_failure( array $fail_site_ids ): void {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( $fail_site_ids ) {
				// Only intercept 'stats' calls (sync operations).
				if ( 'stats' !== $what ) {
					return $result;
				}

				if ( in_array( (int) $website->id, array_map( 'intval', $fail_site_ids ), true ) ) {
					// Throw exception to cause sync_site to return false via error path.
					throw new \MainWP\Dashboard\MainWP_Exception( 'HTTPERROR', 'Connection failed' );
				}

				// Return valid sync response for non-failing sites.
				return [
					'wpversion'  => '6.4.0',
					'siteurl'    => $website->url,
					'site_info'  => [],
					'wp_updates' => [],
				];
			},
			10,
			4
		);
	}

	/**
	 * Mock update operations to succeed for all sites.
	 *
	 * @return void
	 */
	private function mock_update_success(): void {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) {
				// Return a successful response structure.
				return [ 'sync' => [] ];
			},
			10,
			4
		);
	}

	/**
	 * Mock update operations to fail for specific site IDs.
	 *
	 * Sites in $fail_site_ids will throw exceptions, others succeed.
	 *
	 * @param array $fail_site_ids Array of site IDs that should fail.
	 * @return void
	 */
	private function mock_update_partial_failure( array $fail_site_ids ): void {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( $fail_site_ids ) {
				if ( in_array( (int) $website->id, array_map( 'intval', $fail_site_ids ), true ) ) {
					throw new \Exception( 'Update failed for site.' );
				}
				return [ 'sync' => [] ];
			},
			10,
			4
		);
	}

	/**
	 * Seed a sync job transient with default or custom values.
	 *
	 * @param string $job_id   Job ID.
	 * @param array  $site_ids Array of site IDs.
	 * @param array  $overrides Optional overrides for job data.
	 * @return array The seeded job data.
	 */
	private function seed_sync_job( string $job_id, array $site_ids, array $overrides = [] ): array {
		$defaults = [
			'job_type'  => 'sync',
			'sites'     => $site_ids,
			'status'    => 'queued',
			'created'   => time(),
			'started'   => null,
			'completed' => null,
			'synced'    => [],
			'errors'    => [],
			'progress'  => 0,
			'total'     => count( $site_ids ),
			'processed' => 0,
		];

		$job = array_merge( $defaults, $overrides );
		set_transient( 'mainwp_sync_job_' . $job_id, $job, DAY_IN_SECONDS );
		$this->track_sync_job( $job_id );

		return $job;
	}

	/**
	 * Seed an update job transient with default or custom values.
	 *
	 * @param string $job_id   Job ID.
	 * @param array  $site_ids Array of site IDs.
	 * @param array  $types    Array of update types.
	 * @param array  $overrides Optional overrides for job data.
	 * @return array The seeded job data.
	 */
	private function seed_update_job( string $job_id, array $site_ids, array $types, array $overrides = [] ): array {
		$defaults = [
			'job_type'       => 'update',
			'sites'          => $site_ids,
			'types'          => $types,
			'specific_items' => [],
			'status'         => 'queued',
			'created'        => time(),
			'started'        => null,
			'completed'      => null,
			'updated'        => [],
			'errors'         => [],
			'progress'       => 0,
			'total'          => count( $site_ids ),
			'processed'      => 0,
		];

		$job = array_merge( $defaults, $overrides );
		set_transient( 'mainwp_update_job_' . $job_id, $job, DAY_IN_SECONDS );
		$this->track_update_job( $job_id );

		return $job;
	}

	/**
	 * Seed a batch job transient with default or custom values.
	 *
	 * @param string $job_id   Job ID.
	 * @param array  $site_ids Array of site IDs.
	 * @param string $job_type Batch operation type (reconnect, disconnect, check, suspend).
	 * @param array  $overrides Optional overrides for job data.
	 * @return array The seeded job data.
	 */
	private function seed_batch_job( string $job_id, array $site_ids, string $job_type, array $overrides = [] ): array {
		$defaults = [
			'job_type'   => $job_type,
			'sites'      => $site_ids,
			'status'     => 'queued',
			'created'    => time(),
			'started'    => null,
			'completed'  => null,
			'successful' => [],
			'errors'     => [],
			'progress'   => 0,
			'total'      => count( $site_ids ),
			'processed'  => 0,
		];

		$job = array_merge( $defaults, $overrides );
		set_transient( 'mainwp_batch_job_' . $job_id, $job, DAY_IN_SECONDS );
		$this->track_batch_job( $job_id );

		return $job;
	}

	/**
	 * Assert that a job has a specific status.
	 *
	 * @param string $job_id        Job ID.
	 * @param string $transient_key Transient key prefix (e.g., 'mainwp_sync_job_').
	 * @param string $expected_status Expected status value.
	 * @return void
	 */
	private function assert_job_status( string $job_id, string $transient_key, string $expected_status ): void {
		$job = get_transient( $transient_key . $job_id );
		$this->assertIsArray( $job, 'Job transient should exist.' );
		$this->assertEquals( $expected_status, $job['status'], "Job status should be '{$expected_status}'." );
	}

	/**
	 * Assert that a cron event is scheduled (or not).
	 *
	 * @param string $hook     Cron hook name.
	 * @param array  $args     Hook arguments.
	 * @param bool   $expected Whether the event should be scheduled.
	 * @return void
	 */
	private function assert_cron_scheduled( string $hook, array $args, bool $expected = true ): void {
		$scheduled = wp_next_scheduled( $hook, $args );

		if ( $expected ) {
			$this->assertIsInt( $scheduled, "Cron event '{$hook}' should be scheduled." );
			$this->assertGreaterThan( 0, $scheduled, 'Scheduled time should be positive.' );
		} else {
			$this->assertFalse( $scheduled, "Cron event '{$hook}' should not be scheduled." );
		}
	}

	// =========================================================================
	// Sync Job Processing Tests
	// =========================================================================

	/**
	 * Test that process_sync_job processes a chunk of 20 sites.
	 *
	 * Creates 25 sites, seeds a sync job, mocks all syncs to succeed,
	 * invokes the handler once, and verifies exactly 20 sites were processed.
	 *
	 * @return void
	 */
	public function test_process_sync_job_processes_chunk_of_20_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Create 25 test sites.
		$site_ids = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Sync Chunk Site {$i}" ] );
		}

		$job_id = 'test_sync_chunk_' . uniqid();
		$this->seed_sync_job( $job_id, $site_ids );
		$this->mock_sync_success();

		// Invoke handler.
		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		// Verify state.
		$job = get_transient( 'mainwp_sync_job_' . $job_id );

		$this->assertIsArray( $job );
		$this->assertCount( 20, $job['synced'], 'Exactly 20 sites should be synced.' );
		$this->assertEquals( 20, $job['processed'], 'Processed count should be 20.' );
		$this->assertEquals( 80, $job['progress'], 'Progress should be 80%.' );
		$this->assertEquals( 'processing', $job['status'], 'Status should be processing.' );

		// Verify 5 sites remain unprocessed.
		$remaining = array_diff( $site_ids, $job['synced'] );
		$this->assertCount( 5, $remaining, '5 sites should remain unprocessed.' );
	}

	/**
	 * Test that process_sync_job updates progress correctly.
	 *
	 * Creates 10 sites, processes all in one chunk, and verifies progress
	 * updates from 0 to 100.
	 *
	 * @return void
	 */
	public function test_process_sync_job_updates_progress_correctly() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Sync Progress Site {$i}" ] );
		}

		$job_id = 'test_sync_progress_' . uniqid();
		$this->seed_sync_job( $job_id, $site_ids );
		$this->mock_sync_success();

		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		$job = get_transient( 'mainwp_sync_job_' . $job_id );

		$this->assertEquals( 10, $job['processed'], 'All 10 sites should be processed.' );
		$this->assertEquals( 100, $job['progress'], 'Progress should be 100%.' );
		$this->assertCount( 10, $job['synced'], 'All 10 sites should be in synced array.' );
	}

	/**
	 * Test that process_sync_job reschedules when sites remain.
	 *
	 * Creates 25 sites, processes one chunk, and verifies a new cron event
	 * is scheduled for the remaining sites.
	 *
	 * @return void
	 */
	public function test_process_sync_job_reschedules_when_sites_remain() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Sync Reschedule Site {$i}" ] );
		}

		$job_id = 'test_sync_reschedule_' . uniqid();
		$this->seed_sync_job( $job_id, $site_ids );
		$this->mock_sync_success();

		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		// Verify cron is scheduled.
		$this->assert_cron_scheduled( 'mainwp_process_sync_job', [ $job_id ], true );

		// Verify status is still processing.
		$this->assert_job_status( $job_id, 'mainwp_sync_job_', 'processing' );
	}

	/**
	 * Test that process_sync_job completes when all sites processed.
	 *
	 * Creates 10 sites (within one chunk), processes all, and verifies
	 * the job transitions to completed status.
	 *
	 * @return void
	 */
	public function test_process_sync_job_completes_when_all_sites_processed() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Sync Complete Site {$i}" ] );
		}

		$job_id = 'test_sync_complete_' . uniqid();
		$this->seed_sync_job( $job_id, $site_ids );
		$this->mock_sync_success();

		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		$job = get_transient( 'mainwp_sync_job_' . $job_id );

		$this->assertEquals( 'completed', $job['status'], 'Status should be completed.' );
		$this->assertIsInt( $job['completed'], 'Completed timestamp should be set.' );
		$this->assertEquals( 100, $job['progress'], 'Progress should be 100%.' );

		// Verify no cron scheduled.
		$this->assert_cron_scheduled( 'mainwp_process_sync_job', [ $job_id ], false );
	}

	/**
	 * Test that process_sync_job handles errors mid-batch.
	 *
	 * Creates 10 sites, mocks first 5 to succeed and last 5 to fail,
	 * and verifies errors are recorded correctly.
	 *
	 * @return void
	 */
	public function test_process_sync_job_handles_errors_mid_batch() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Sync Error Site {$i}" ] );
		}

		$job_id = 'test_sync_errors_' . uniqid();
		$this->seed_sync_job( $job_id, $site_ids );

		// Mock: first 5 succeed, last 5 fail.
		$fail_site_ids = array_slice( $site_ids, 5, 5 );
		$this->mock_sync_partial_failure( $fail_site_ids );

		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		$job = get_transient( 'mainwp_sync_job_' . $job_id );

		$this->assertCount( 5, $job['synced'], '5 sites should be synced.' );
		$this->assertCount( 5, $job['errors'], '5 sites should have errors.' );
		$this->assertEquals( 10, $job['processed'], 'All 10 should be processed.' );
		$this->assertEquals( 'completed', $job['status'], 'Job should be completed.' );

		// Verify error structure.
		foreach ( $job['errors'] as $error ) {
			$this->assertArrayHasKey( 'site_id', $error );
			$this->assertArrayHasKey( 'code', $error );
			$this->assertArrayHasKey( 'message', $error );
		}
	}

	/**
	 * Test that process_sync_job times out after 4 hours.
	 *
	 * Seeds a job with a started timestamp 5 hours ago and verifies
	 * the job fails with a timeout error and sets the job_timed_out flag.
	 *
	 * @return void
	 */
	public function test_process_sync_job_times_out_after_4_hours() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Sync Timeout Site {$i}" ] );
		}

		$job_id = 'test_sync_timeout_' . uniqid();
		$this->seed_sync_job(
			$job_id,
			$site_ids,
			[
				'status'  => 'processing',
				'started' => time() - ( 5 * HOUR_IN_SECONDS ), // 5 hours ago.
			]
		);

		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		$job = get_transient( 'mainwp_sync_job_' . $job_id );

		$this->assertEquals( 'failed', $job['status'], 'Status should be failed.' );
		$this->assertIsInt( $job['completed'], 'Completed timestamp should be set.' );

		// Verify job_timed_out flag is set.
		$this->assertTrue( $job['job_timed_out'] ?? false, 'job_timed_out flag should be true.' );

		// Verify timeout error recorded.
		$timeout_error = null;
		foreach ( $job['errors'] as $error ) {
			if ( 'timeout' === $error['code'] ) {
				$timeout_error = $error;
				break;
			}
		}

		$this->assertNotNull( $timeout_error, 'Timeout error should be recorded.' );
		$this->assertEquals( 0, $timeout_error['site_id'], 'Timeout error site_id should be 0.' );

		// Verify no reschedule.
		$this->assert_cron_scheduled( 'mainwp_process_sync_job', [ $job_id ], false );
	}

	/**
	 * Test that process_sync_job handles missing job gracefully.
	 *
	 * Invokes the handler with a non-existent job ID and verifies
	 * no errors are thrown.
	 *
	 * @return void
	 */
	public function test_process_sync_job_handles_missing_job() {
		$this->skip_if_no_abilities_api();

		$job_id = 'nonexistent_sync_job_' . uniqid();

		// Should not throw an error.
		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		// Verify no transient was created.
		$job = get_transient( 'mainwp_sync_job_' . $job_id );
		$this->assertFalse( $job, 'No transient should be created for missing job.' );
	}

	/**
	 * Test that process_sync_job handles site not found.
	 *
	 * Creates a job with a non-existent site ID and verifies
	 * the error is recorded correctly.
	 *
	 * @return void
	 */
	public function test_process_sync_job_handles_site_not_found() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$job_id = 'test_sync_site_not_found_' . uniqid();
		$this->seed_sync_job( $job_id, [ 999999 ] ); // Non-existent site ID.

		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		$job = get_transient( 'mainwp_sync_job_' . $job_id );

		$this->assertEquals( 1, $job['processed'], 'Site should be counted as processed.' );
		$this->assertCount( 1, $job['errors'], 'One error should be recorded.' );
		$this->assertEquals( 'site_not_found', $job['errors'][0]['code'] );
	}

	// =========================================================================
	// Update Job Processing Tests
	// =========================================================================

	/**
	 * Test that process_update_job processes a chunk of 20 sites.
	 *
	 * Creates 25 sites with plugin upgrades, seeds an update job,
	 * mocks updates to succeed, and verifies 20 sites processed.
	 *
	 * @return void
	 */
	public function test_process_update_job_processes_chunk_of_20_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$site_id    = $this->create_test_site( [ 'name' => "Update Chunk Site {$i}" ] );
			$site_ids[] = $site_id;

			// Set up plugin upgrades.
			$this->set_site_plugin_upgrades(
				$site_id,
				[
					'test-plugin/test-plugin.php' => [
						'Name'        => 'Test Plugin',
						'Version'     => '1.0.0',
						'new_version' => '2.0.0',
					],
				]
			);
		}

		$job_id = 'test_update_chunk_' . uniqid();
		$this->seed_update_job( $job_id, $site_ids, [ 'plugins' ] );
		$this->mock_update_success();

		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );

		$job = get_transient( 'mainwp_update_job_' . $job_id );

		$this->assertIsArray( $job );
		$this->assertCount( 20, $job['updated'], 'Exactly 20 sites should be updated.' );
		$this->assertEquals( 20, $job['processed'], 'Processed count should be 20.' );
		$this->assertEquals( 'processing', $job['status'], 'Status should be processing.' );
	}

	/**
	 * Test that process_update_job updates progress correctly.
	 *
	 * @return void
	 */
	public function test_process_update_job_updates_progress_correctly() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$site_id    = $this->create_test_site( [ 'name' => "Update Progress Site {$i}" ] );
			$site_ids[] = $site_id;

			$this->set_site_plugin_upgrades(
				$site_id,
				[
					'test-plugin/test-plugin.php' => [
						'Name'        => 'Test Plugin',
						'Version'     => '1.0.0',
						'new_version' => '2.0.0',
					],
				]
			);
		}

		$job_id = 'test_update_progress_' . uniqid();
		$this->seed_update_job( $job_id, $site_ids, [ 'plugins' ] );
		$this->mock_update_success();

		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );

		$job = get_transient( 'mainwp_update_job_' . $job_id );

		$this->assertEquals( 10, $job['processed'], 'All 10 sites should be processed.' );
		$this->assertEquals( 100, $job['progress'], 'Progress should be 100%.' );
		$this->assertCount( 10, $job['updated'], 'All 10 sites should be in updated array.' );
	}

	/**
	 * Test that process_update_job reschedules when sites remain.
	 *
	 * @return void
	 */
	public function test_process_update_job_reschedules_when_sites_remain() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$site_id    = $this->create_test_site( [ 'name' => "Update Reschedule Site {$i}" ] );
			$site_ids[] = $site_id;

			$this->set_site_plugin_upgrades(
				$site_id,
				[
					'test-plugin/test-plugin.php' => [
						'Name'        => 'Test Plugin',
						'Version'     => '1.0.0',
						'new_version' => '2.0.0',
					],
				]
			);
		}

		$job_id = 'test_update_reschedule_' . uniqid();
		$this->seed_update_job( $job_id, $site_ids, [ 'plugins' ] );
		$this->mock_update_success();

		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );

		$this->assert_cron_scheduled( 'mainwp_process_update_job', [ $job_id ], true );
		$this->assert_job_status( $job_id, 'mainwp_update_job_', 'processing' );
	}

	/**
	 * Test that process_update_job completes when all sites processed.
	 *
	 * @return void
	 */
	public function test_process_update_job_completes_when_all_sites_processed() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$site_id    = $this->create_test_site( [ 'name' => "Update Complete Site {$i}" ] );
			$site_ids[] = $site_id;

			$this->set_site_plugin_upgrades(
				$site_id,
				[
					'test-plugin/test-plugin.php' => [
						'Name'        => 'Test Plugin',
						'Version'     => '1.0.0',
						'new_version' => '2.0.0',
					],
				]
			);
		}

		$job_id = 'test_update_complete_' . uniqid();
		$this->seed_update_job( $job_id, $site_ids, [ 'plugins' ] );
		$this->mock_update_success();

		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );

		$job = get_transient( 'mainwp_update_job_' . $job_id );

		$this->assertEquals( 'completed', $job['status'], 'Status should be completed.' );
		$this->assertIsInt( $job['completed'], 'Completed timestamp should be set.' );
		$this->assertEquals( 100, $job['progress'], 'Progress should be 100%.' );
		$this->assert_cron_scheduled( 'mainwp_process_update_job', [ $job_id ], false );
	}

	/**
	 * Test that process_update_job handles errors mid-batch.
	 *
	 * @return void
	 */
	public function test_process_update_job_handles_errors_mid_batch() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$site_id    = $this->create_test_site( [ 'name' => "Update Error Site {$i}" ] );
			$site_ids[] = $site_id;

			$this->set_site_plugin_upgrades(
				$site_id,
				[
					'test-plugin/test-plugin.php' => [
						'Name'        => 'Test Plugin',
						'Version'     => '1.0.0',
						'new_version' => '2.0.0',
					],
				]
			);
		}

		$job_id = 'test_update_errors_' . uniqid();
		$this->seed_update_job( $job_id, $site_ids, [ 'plugins' ] );

		// Mock: first 5 succeed, last 5 fail.
		$fail_site_ids = array_slice( $site_ids, 5, 5 );
		$this->mock_update_partial_failure( $fail_site_ids );

		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );

		$job = get_transient( 'mainwp_update_job_' . $job_id );

		$this->assertCount( 5, $job['updated'], '5 sites should be updated.' );
		$this->assertCount( 5, $job['errors'], '5 sites should have errors.' );
		$this->assertEquals( 10, $job['processed'], 'All 10 should be processed.' );
		$this->assertEquals( 'completed', $job['status'], 'Job should be completed.' );
	}

	/**
	 * Test that process_update_job times out after 4 hours.
	 *
	 * @return void
	 */
	public function test_process_update_job_times_out_after_4_hours() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$site_id    = $this->create_test_site( [ 'name' => "Update Timeout Site {$i}" ] );
			$site_ids[] = $site_id;
		}

		$job_id = 'test_update_timeout_' . uniqid();
		$this->seed_update_job(
			$job_id,
			$site_ids,
			[ 'plugins' ],
			[
				'status'  => 'processing',
				'started' => time() - ( 5 * HOUR_IN_SECONDS ),
			]
		);

		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );

		$job = get_transient( 'mainwp_update_job_' . $job_id );

		$this->assertEquals( 'failed', $job['status'], 'Status should be failed.' );
		$this->assertIsInt( $job['completed'], 'Completed timestamp should be set.' );

		// Verify job_timed_out flag is set.
		$this->assertTrue( $job['job_timed_out'] ?? false, 'job_timed_out flag should be true.' );

		// Verify timeout error recorded.
		$timeout_error = null;
		foreach ( $job['errors'] as $error ) {
			if ( 'timeout' === $error['code'] ) {
				$timeout_error = $error;
				break;
			}
		}

		$this->assertNotNull( $timeout_error, 'Timeout error should be recorded.' );
	}

	/**
	 * Test that process_update_job handles multiple update types.
	 *
	 * Creates sites with plugin, theme, and translation upgrades, runs the job
	 * with all types, and verifies completion.
	 *
	 * @return void
	 */
	public function test_process_update_job_handles_multiple_types() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$site_id    = $this->create_test_site( [ 'name' => "Multi Update Site {$i}" ] );
			$site_ids[] = $site_id;

			$this->set_site_plugin_upgrades(
				$site_id,
				[
					'test-plugin/test-plugin.php' => [
						'Name'        => 'Test Plugin',
						'Version'     => '1.0.0',
						'new_version' => '2.0.0',
					],
				]
			);

			$this->set_site_theme_upgrades(
				$site_id,
				[
					'twentytwentyfour' => [
						'Name'        => 'Twenty Twenty-Four',
						'Version'     => '1.0',
						'new_version' => '1.1',
					],
				]
			);
		}

		$job_id = 'test_update_multi_' . uniqid();
		$this->seed_update_job( $job_id, $site_ids, [ 'plugins', 'themes', 'translations' ] );
		$this->mock_update_success();

		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );

		$job = get_transient( 'mainwp_update_job_' . $job_id );

		$this->assertEquals( 'completed', $job['status'], 'Job should be completed.' );
		$this->assertCount( 5, $job['updated'], 'All 5 sites should be updated.' );
	}

	/**
	 * Test that process_update_job filters to specific items.
	 *
	 * Creates a site with multiple plugin upgrades, seeds job with specific_items,
	 * and verifies only those plugins are targeted.
	 *
	 * @return void
	 */
	public function test_process_update_job_filters_specific_items() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Specific Items Site' ] );

		// Set up multiple plugin upgrades.
		$this->set_site_plugin_upgrades(
			$site_id,
			[
				'plugin-1/plugin-1.php' => [
					'Name'        => 'Plugin 1',
					'Version'     => '1.0.0',
					'new_version' => '2.0.0',
				],
				'plugin-2/plugin-2.php' => [
					'Name'        => 'Plugin 2',
					'Version'     => '1.0.0',
					'new_version' => '2.0.0',
				],
				'plugin-3/plugin-3.php' => [
					'Name'        => 'Plugin 3',
					'Version'     => '1.0.0',
					'new_version' => '2.0.0',
				],
			]
		);

		$job_id = 'test_update_specific_' . uniqid();
		$this->seed_update_job(
			$job_id,
			[ $site_id ],
			[ 'plugins' ],
			[
				'specific_items' => [ 'plugin-1/plugin-1.php', 'plugin-2/plugin-2.php' ],
			]
		);
		$this->mock_update_success();

		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );

		$job = get_transient( 'mainwp_update_job_' . $job_id );

		$this->assertEquals( 'completed', $job['status'], 'Job should be completed.' );
		$this->assertCount( 1, $job['updated'], 'Site should be in updated array.' );
	}

	// =========================================================================
	// Batch Job Processing Tests
	// =========================================================================

	/**
	 * Test that process_batch_job processes a chunk of 20 sites.
	 *
	 * @return void
	 */
	public function test_process_batch_job_processes_chunk_of_20_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Batch Chunk Site {$i}" ] );
		}

		$job_id = 'test_batch_chunk_' . uniqid();
		$this->seed_batch_job( $job_id, $site_ids, 'disconnect' );

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$job = get_transient( 'mainwp_batch_job_' . $job_id );

		$this->assertIsArray( $job );
		$this->assertCount( 20, $job['successful'], 'Exactly 20 sites should be successful.' );
		$this->assertEquals( 20, $job['processed'], 'Processed count should be 20.' );
	}

	/**
	 * Test that process_batch_job handles reconnect operation.
	 *
	 * Note: Reconnect requires network calls which we can't easily mock,
	 * so we verify the job structure and that sites are attempted.
	 *
	 * @return void
	 */
	public function test_process_batch_job_handles_reconnect_operation() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Batch Reconnect Site {$i}" ] );
		}

		$job_id = 'test_batch_reconnect_' . uniqid();
		$this->seed_batch_job( $job_id, $site_ids, 'reconnect' );

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$job = get_transient( 'mainwp_batch_job_' . $job_id );

		// Reconnect will likely fail without network mocking, but job should process.
		$this->assertEquals( 5, $job['processed'], 'All 5 sites should be processed.' );
		$this->assertEquals( 'completed', $job['status'], 'Job should be completed.' );
	}

	/**
	 * Test that process_batch_job handles disconnect operation.
	 *
	 * Disconnect is a DB operation that always succeeds.
	 *
	 * @return void
	 */
	public function test_process_batch_job_handles_disconnect_operation() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Batch Disconnect Site {$i}" ] );
		}

		$job_id = 'test_batch_disconnect_' . uniqid();
		$this->seed_batch_job( $job_id, $site_ids, 'disconnect' );

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$job = get_transient( 'mainwp_batch_job_' . $job_id );

		$this->assertCount( 5, $job['successful'], 'All 5 sites should be successful.' );
		$this->assertEquals( 'completed', $job['status'], 'Job should be completed.' );

		// Verify sync_errors was updated in database.
		global $wpdb;
		foreach ( $site_ids as $site_id ) {
			$sync_errors = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT sync_errors FROM {$wpdb->prefix}mainwp_wp_sync WHERE wpid = %d",
					$site_id
				)
			);
			$this->assertStringContainsString( 'disconnected', strtolower( $sync_errors ), 'Site should be marked as disconnected.' );
		}
	}

	/**
	 * Test that process_batch_job handles check operation.
	 *
	 * Check operation calls the monitoring handler.
	 *
	 * @return void
	 */
	public function test_process_batch_job_handles_check_operation() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$site_ids[] = $this->create_test_site( [
				'name'                 => "Batch Check Site {$i}",
				'offline_check_result' => 1,
			] );
		}

		$job_id = 'test_batch_check_' . uniqid();
		$this->seed_batch_job( $job_id, $site_ids, 'check' );

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$job = get_transient( 'mainwp_batch_job_' . $job_id );

		// Check operation may succeed or fail based on site configuration.
		$this->assertEquals( 5, $job['processed'], 'All 5 sites should be processed.' );
		$this->assertEquals( 'completed', $job['status'], 'Job should be completed.' );
	}

	/**
	 * Test that process_batch_job handles suspend operation.
	 *
	 * Suspend is a DB operation that always succeeds.
	 *
	 * @return void
	 */
	public function test_process_batch_job_handles_suspend_operation() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$site_ids[] = $this->create_test_site( [
				'name'      => "Batch Suspend Site {$i}",
				'suspended' => 0,
			] );
		}

		// Track action firing.
		$suspended_sites = [];
		add_action(
			'mainwp_site_suspended',
			function ( $website, $status ) use ( &$suspended_sites ) {
				$suspended_sites[] = (int) $website->id;
			},
			10,
			2
		);

		$job_id = 'test_batch_suspend_' . uniqid();
		$this->seed_batch_job( $job_id, $site_ids, 'suspend' );

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$job = get_transient( 'mainwp_batch_job_' . $job_id );

		$this->assertCount( 5, $job['successful'], 'All 5 sites should be successful.' );
		$this->assertEquals( 'completed', $job['status'], 'Job should be completed.' );

		// Verify suspended column was updated.
		global $wpdb;
		foreach ( $site_ids as $site_id ) {
			$suspended = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT suspended FROM {$wpdb->prefix}mainwp_wp WHERE id = %d",
					$site_id
				)
			);
			$this->assertEquals( 1, (int) $suspended, 'Site should be suspended.' );
		}

		// Verify action was fired for each site.
		foreach ( $site_ids as $site_id ) {
			$this->assertContains( $site_id, $suspended_sites, 'mainwp_site_suspended action should fire.' );
		}
	}

	/**
	 * Test that process_batch_job handles unknown operation type.
	 *
	 * @return void
	 */
	public function test_process_batch_job_handles_unknown_operation() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Unknown Op Site' ] );

		$job_id = 'test_batch_unknown_' . uniqid();
		$this->seed_batch_job( $job_id, [ $site_id ], 'invalid_operation' );

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$job = get_transient( 'mainwp_batch_job_' . $job_id );

		$this->assertEquals( 'completed', $job['status'], 'Job should complete.' );
		$this->assertCount( 1, $job['errors'], 'Error should be recorded.' );
		$this->assertStringContainsString( 'Unknown operation type', $job['errors'][0]['message'] );
	}

	/**
	 * Test that process_batch_job reschedules when sites remain.
	 *
	 * @return void
	 */
	public function test_process_batch_job_reschedules_when_sites_remain() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Batch Reschedule Site {$i}" ] );
		}

		$job_id = 'test_batch_reschedule_' . uniqid();
		$this->seed_batch_job( $job_id, $site_ids, 'disconnect' );

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$this->assert_cron_scheduled( 'mainwp_process_batch_job', [ $job_id ], true );
		$this->assert_job_status( $job_id, 'mainwp_batch_job_', 'processing' );
	}

	/**
	 * Test that process_batch_job completes when all sites processed.
	 *
	 * @return void
	 */
	public function test_process_batch_job_completes_when_all_sites_processed() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Batch Complete Site {$i}" ] );
		}

		$job_id = 'test_batch_complete_' . uniqid();
		$this->seed_batch_job( $job_id, $site_ids, 'disconnect' );

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$job = get_transient( 'mainwp_batch_job_' . $job_id );

		$this->assertEquals( 'completed', $job['status'], 'Status should be completed.' );
		$this->assertIsInt( $job['completed'], 'Completed timestamp should be set.' );
		$this->assertEquals( 100, $job['progress'], 'Progress should be 100%.' );
		$this->assert_cron_scheduled( 'mainwp_process_batch_job', [ $job_id ], false );
	}

	/**
	 * Test that process_batch_job times out after 4 hours.
	 *
	 * @return void
	 */
	public function test_process_batch_job_times_out_after_4_hours() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Batch Timeout Site {$i}" ] );
		}

		$job_id = 'test_batch_timeout_' . uniqid();
		$this->seed_batch_job(
			$job_id,
			$site_ids,
			'disconnect',
			[
				'status'  => 'processing',
				'started' => time() - ( 5 * HOUR_IN_SECONDS ),
			]
		);

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$job = get_transient( 'mainwp_batch_job_' . $job_id );

		$this->assertEquals( 'failed', $job['status'], 'Status should be failed.' );
		$this->assertIsInt( $job['completed'], 'Completed timestamp should be set.' );

		// Verify job_timed_out flag is set.
		$this->assertTrue( $job['job_timed_out'] ?? false, 'job_timed_out flag should be true.' );

		// Verify timeout error.
		$timeout_error = null;
		foreach ( $job['errors'] as $error ) {
			if ( 'timeout' === $error['code'] ) {
				$timeout_error = $error;
				break;
			}
		}

		$this->assertNotNull( $timeout_error, 'Timeout error should be recorded.' );
	}

	// =========================================================================
	// Common Cron Behavior Tests
	// =========================================================================

	/**
	 * Test that chunk size filter applies to all handlers.
	 *
	 * Sets chunk size to 5, processes 10 sites, and verifies only 5 processed.
	 *
	 * @return void
	 */
	public function test_chunk_size_filter_applies_to_all_handlers() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Set chunk size to 5.
		add_filter(
			'mainwp_abilities_cron_chunk_size',
			function () {
				return 5;
			}
		);

		$site_ids = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Chunk Filter Site {$i}" ] );
		}

		$job_id = 'test_chunk_filter_' . uniqid();
		$this->seed_sync_job( $job_id, $site_ids );
		$this->mock_sync_success();

		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		$job = get_transient( 'mainwp_sync_job_' . $job_id );

		$this->assertEquals( 5, $job['processed'], 'Only 5 sites should be processed with filter.' );
		$this->assertCount( 5, $job['synced'], '5 sites should be synced.' );
		$this->assertEquals( 'processing', $job['status'], 'Status should be processing.' );

		remove_all_filters( 'mainwp_abilities_cron_chunk_size' );
	}

	/**
	 * Test that transient is saved after each chunk.
	 *
	 * @return void
	 */
	public function test_transient_saved_after_each_chunk() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Transient Save Site {$i}" ] );
		}

		$job_id = 'test_transient_save_' . uniqid();
		$this->seed_sync_job( $job_id, $site_ids );
		$this->mock_sync_success();

		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		// Verify transient exists with updated data.
		$job = get_transient( 'mainwp_sync_job_' . $job_id );

		$this->assertIsArray( $job, 'Transient should exist.' );
		$this->assertEquals( 20, $job['processed'], 'Progress should be saved.' );
		$this->assertCount( 20, $job['synced'], 'Synced array should be saved.' );
	}

	/**
	 * Test that status transitions from queued to processing.
	 *
	 * @return void
	 */
	public function test_status_transitions_from_queued_to_processing() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Status Transition Site {$i}" ] );
		}

		$job_id = 'test_status_transition_' . uniqid();
		$this->seed_sync_job( $job_id, $site_ids, [ 'status' => 'queued', 'started' => null ] );
		$this->mock_sync_success();

		// Verify initial state.
		$job_before = get_transient( 'mainwp_sync_job_' . $job_id );
		$this->assertEquals( 'queued', $job_before['status'], 'Initial status should be queued.' );
		$this->assertNull( $job_before['started'], 'Started should be null initially.' );

		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		$job_after = get_transient( 'mainwp_sync_job_' . $job_id );
		$this->assertEquals( 'processing', $job_after['status'], 'Status should transition to processing.' );
		$this->assertIsInt( $job_after['started'], 'Started timestamp should be set.' );
	}

	/**
	 * Test that reschedule prevents duplicate events.
	 *
	 * @return void
	 */
	public function test_reschedule_prevents_duplicate_events() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Duplicate Prevention Site {$i}" ] );
		}

		$job_id = 'test_duplicate_prevention_' . uniqid();
		$this->seed_sync_job( $job_id, $site_ids );
		$this->mock_sync_success();

		// Manually schedule an event.
		wp_schedule_single_event( time() + 60, 'mainwp_process_sync_job', [ $job_id ] );

		// Get the scheduled time.
		$first_scheduled = wp_next_scheduled( 'mainwp_process_sync_job', [ $job_id ] );
		$this->assertIsInt( $first_scheduled, 'Event should be scheduled.' );

		// Process job - should not create duplicate.
		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		// Verify still only one event.
		$scheduled_events = _get_cron_array();
		$count            = 0;
		foreach ( $scheduled_events as $timestamp => $crons ) {
			if ( isset( $crons['mainwp_process_sync_job'] ) ) {
				foreach ( $crons['mainwp_process_sync_job'] as $hook_data ) {
					if ( isset( $hook_data['args'] ) && $hook_data['args'] === [ $job_id ] ) {
						$count++;
					}
				}
			}
		}

		$this->assertEquals( 1, $count, 'Only one cron event should exist.' );
	}

	/**
	 * Test that transient TTL is DAY_IN_SECONDS and no reschedule on terminal states.
	 *
	 * Verifies that:
	 * 1. Transients are stored with DAY_IN_SECONDS expiration
	 * 2. Completed jobs do not reschedule cron events
	 *
	 * Uses the 'setted_transient' action to capture set_transient calls and verify
	 * the expiration value.
	 *
	 * @return void
	 */
	public function test_transient_ttl_and_no_reschedule_on_terminal_states() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Track set_transient calls and their expiration values.
		$transient_captures = [];

		// Use 'set_transient' action which fires after set_transient().
		// Parameters: $transient, $value, $expiration.
		// Note: 'setted_transient' was deprecated in WP 6.8.0 in favor of 'set_transient'.
		add_action(
			'set_transient',
			function ( $transient, $value, $expiration ) use ( &$transient_captures ) {
				// Only capture job transients.
				if ( preg_match( '/^mainwp_(sync|update|batch)_job_/', $transient ) ) {
					$transient_captures[ $transient ] = $expiration;
				}
			},
			10,
			3
		);

		// Test 1: Sync job - seed as completed and verify TTL + no reschedule.
		$sync_job_id = 'test_ttl_sync_' . uniqid();
		$site_id     = $this->create_test_site( [ 'name' => 'TTL Test Site Sync' ] );

		$this->seed_sync_job(
			$sync_job_id,
			[ $site_id ],
			[
				'status'    => 'processing',
				'started'   => time(),
				'synced'    => [], // Empty so it processes the site.
				'errors'    => [],
				'processed' => 0,
			]
		);
		$this->mock_sync_success();

		// Process the sync job (completes in one chunk).
		MainWP_Abilities_Cron::instance()->process_sync_job( $sync_job_id );

		// Verify sync job transient was set with DAY_IN_SECONDS.
		$sync_transient_key = 'mainwp_sync_job_' . $sync_job_id;
		$this->assertArrayHasKey(
			$sync_transient_key,
			$transient_captures,
			'Sync job transient should have been set.'
		);
		$this->assertEquals(
			DAY_IN_SECONDS,
			$transient_captures[ $sync_transient_key ],
			'Sync job transient TTL should be DAY_IN_SECONDS.'
		);

		// Verify no cron scheduled for completed sync job.
		$this->assertFalse(
			wp_next_scheduled( 'mainwp_process_sync_job', [ $sync_job_id ] ),
			'Completed sync job should not have a scheduled cron event.'
		);

		// Reset captures for next test.
		$transient_captures = [];

		// Test 2: Update job - seed as completed and verify TTL + no reschedule.
		$update_job_id = 'test_ttl_update_' . uniqid();
		$site_id_2     = $this->create_test_site( [ 'name' => 'TTL Test Site Update' ] );

		$this->set_site_plugin_upgrades(
			$site_id_2,
			[
				'test-plugin/test-plugin.php' => [
					'Name'        => 'Test Plugin',
					'Version'     => '1.0.0',
					'new_version' => '2.0.0',
				],
			]
		);

		$this->seed_update_job(
			$update_job_id,
			[ $site_id_2 ],
			[ 'plugins' ],
			[
				'status'    => 'processing',
				'started'   => time(),
				'updated'   => [],
				'errors'    => [],
				'processed' => 0,
			]
		);
		$this->mock_update_success();

		// Process the update job.
		MainWP_Abilities_Cron::instance()->process_update_job( $update_job_id );

		// Verify update job transient TTL.
		$update_transient_key = 'mainwp_update_job_' . $update_job_id;
		$this->assertArrayHasKey(
			$update_transient_key,
			$transient_captures,
			'Update job transient should have been set.'
		);
		$this->assertEquals(
			DAY_IN_SECONDS,
			$transient_captures[ $update_transient_key ],
			'Update job transient TTL should be DAY_IN_SECONDS.'
		);

		// Verify no cron scheduled for completed update job.
		$this->assertFalse(
			wp_next_scheduled( 'mainwp_process_update_job', [ $update_job_id ] ),
			'Completed update job should not have a scheduled cron event.'
		);

		// Reset captures for next test.
		$transient_captures = [];

		// Test 3: Batch job - seed as completed and verify TTL + no reschedule.
		$batch_job_id = 'test_ttl_batch_' . uniqid();
		$site_id_3    = $this->create_test_site( [ 'name' => 'TTL Test Site Batch' ] );

		$this->seed_batch_job(
			$batch_job_id,
			[ $site_id_3 ],
			'disconnect',
			[
				'status'     => 'processing',
				'started'    => time(),
				'successful' => [],
				'errors'     => [],
				'processed'  => 0,
			]
		);

		// Process the batch job.
		MainWP_Abilities_Cron::instance()->process_batch_job( $batch_job_id );

		// Verify batch job transient TTL.
		$batch_transient_key = 'mainwp_batch_job_' . $batch_job_id;
		$this->assertArrayHasKey(
			$batch_transient_key,
			$transient_captures,
			'Batch job transient should have been set.'
		);
		$this->assertEquals(
			DAY_IN_SECONDS,
			$transient_captures[ $batch_transient_key ],
			'Batch job transient TTL should be DAY_IN_SECONDS.'
		);

		// Verify no cron scheduled for completed batch job.
		$this->assertFalse(
			wp_next_scheduled( 'mainwp_process_batch_job', [ $batch_job_id ] ),
			'Completed batch job should not have a scheduled cron event.'
		);

		// Clean up action.
		remove_all_actions( 'set_transient' );
	}

	/**
	 * Test that failed jobs also use DAY_IN_SECONDS TTL and don't reschedule.
	 *
	 * Failed jobs (e.g., from timeout) should behave the same as completed jobs
	 * regarding TTL and not rescheduling.
	 *
	 * @return void
	 */
	public function test_failed_job_ttl_and_no_reschedule() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Track set_transient calls.
		$transient_captures = [];

		add_action(
			'set_transient',
			function ( $transient, $value, $expiration ) use ( &$transient_captures ) {
				if ( preg_match( '/^mainwp_(sync|update|batch)_job_/', $transient ) ) {
					$transient_captures[ $transient ] = $expiration;
				}
			},
			10,
			3
		);

		// Seed a job that will timeout (started 5 hours ago).
		$job_id  = 'test_failed_ttl_' . uniqid();
		$site_id = $this->create_test_site( [ 'name' => 'Failed TTL Test Site' ] );

		$this->seed_sync_job(
			$job_id,
			[ $site_id ],
			[
				'status'  => 'processing',
				'started' => time() - ( 5 * HOUR_IN_SECONDS ), // 5 hours ago - will timeout.
			]
		);

		// Process - will hit timeout protection.
		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		// Verify job status is failed.
		$job = get_transient( 'mainwp_sync_job_' . $job_id );
		$this->assertEquals( 'failed', $job['status'], 'Job should be failed due to timeout.' );

		// Verify transient was set with DAY_IN_SECONDS.
		$transient_key = 'mainwp_sync_job_' . $job_id;
		$this->assertArrayHasKey( $transient_key, $transient_captures );
		$this->assertEquals(
			DAY_IN_SECONDS,
			$transient_captures[ $transient_key ],
			'Failed job transient TTL should be DAY_IN_SECONDS.'
		);

		// Verify no cron scheduled for failed job.
		$this->assertFalse(
			wp_next_scheduled( 'mainwp_process_sync_job', [ $job_id ] ),
			'Failed job should not have a scheduled cron event.'
		);

		remove_all_actions( 'set_transient' );
	}

	/**
	 * Test that progress calculation handles zero total without division by zero.
	 *
	 * When a job has zero sites, it completes immediately with 100% progress
	 * (not 0%) because there's nothing left to process. The key test here is
	 * that no division by zero error occurs.
	 *
	 * @return void
	 */
	public function test_progress_calculation_handles_zero_total() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$job_id = 'test_zero_total_' . uniqid();
		$this->seed_sync_job( $job_id, [], [ 'total' => 0 ] );

		// Should not throw division by zero error.
		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		$job = get_transient( 'mainwp_sync_job_' . $job_id );

		$this->assertIsArray( $job );
		// Job completes immediately when no sites to process.
		$this->assertEquals( 'completed', $job['status'], 'Job should complete immediately.' );
		// Progress is 100 because job is complete (no work to do = all work done).
		$this->assertEquals( 100, $job['progress'], 'Progress should be 100 when completed.' );
	}

	/**
	 * Test that batch job status API returns job_error_code and job_error_message for timed out jobs.
	 *
	 * When a job times out, the get-batch-job-status-v1 ability should include
	 * job_error_code and job_error_message fields at the top level for easy detection.
	 *
	 * @return void
	 */
	public function test_batch_status_returns_error_fields_for_timed_out_job() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Status Error Test Site {$i}" ] );
		}

		// Create a sync job with job_id prefixed correctly for the status API.
		$job_id = 'sync_status_error_test_' . uniqid();
		$this->seed_sync_job(
			$job_id,
			$site_ids,
			[
				'status'        => 'processing',
				'started'       => time() - ( 5 * HOUR_IN_SECONDS ), // Will timeout.
			]
		);

		// Process the job - it will timeout.
		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		// Verify the job has timed out and has the flag set.
		$job = get_transient( 'mainwp_sync_job_' . $job_id );
		$this->assertTrue( $job['job_timed_out'] ?? false, 'Job should have job_timed_out flag.' );

		// Now call the batch status ability to get the status.
		$result = $this->execute_ability( 'mainwp/get-batch-job-status-v1', [
			'job_id' => $job_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertEquals( 'failed', $result['status'], 'Status should be failed.' );

		// Verify job_error_code and job_error_message are present.
		$this->assertArrayHasKey( 'job_error_code', $result, 'Timed out job should have job_error_code.' );
		$this->assertArrayHasKey( 'job_error_message', $result, 'Timed out job should have job_error_message.' );
		$this->assertEquals( 'timeout', $result['job_error_code'], 'job_error_code should be timeout.' );
		$this->assertNotEmpty( $result['job_error_message'], 'job_error_message should not be empty.' );
	}

	/**
	 * Test that batch job status API does NOT include error fields for successful jobs.
	 *
	 * When a job completes successfully, job_error_code and job_error_message
	 * should NOT be present in the response.
	 *
	 * @return void
	 */
	public function test_batch_status_omits_error_fields_for_successful_job() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Status Success Test Site {$i}" ] );
		}

		// Create a sync job that will complete successfully.
		$job_id = 'sync_status_success_test_' . uniqid();
		$this->seed_sync_job( $job_id, $site_ids );
		$this->mock_sync_success();

		// Process the job - it will complete.
		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		// Verify the job completed successfully.
		$job = get_transient( 'mainwp_sync_job_' . $job_id );
		$this->assertEquals( 'completed', $job['status'], 'Job should be completed.' );
		$this->assertArrayNotHasKey( 'job_timed_out', $job, 'Successful job should not have job_timed_out.' );

		// Call the batch status ability.
		$result = $this->execute_ability( 'mainwp/get-batch-job-status-v1', [
			'job_id' => $job_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertEquals( 'completed', $result['status'], 'Status should be completed.' );

		// Verify job_error_code and job_error_message are NOT present.
		$this->assertArrayNotHasKey( 'job_error_code', $result, 'Successful job should not have job_error_code.' );
		$this->assertArrayNotHasKey( 'job_error_message', $result, 'Successful job should not have job_error_message.' );
	}

	/**
	 * Restrict a user to a set of allowed sites via the scope filter.
	 *
	 * @param int[] $site_ids Allowed site IDs.
	 * @return void
	 */
	private function restrict_owner_to_sites( array $site_ids ): void {
		$this->scope_filters['mainwp_currentuserallowedaccesssites'] = static function () use ( $site_ids ) {
			return $site_ids;
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $this->scope_filters['mainwp_currentuserallowedaccesssites'], 10 );
	}

	/**
	 * A batch job skips sites the owner lost access to, with no remote call.
	 *
	 * @return void
	 */
	public function test_batch_job_marks_out_of_scope_sites_access_revoked() {
		$this->skip_if_no_abilities_api();
		$owner = $this->set_current_user_as_admin();

		$in_scope  = $this->create_test_site( [ 'name' => 'Cron In Scope' ] );
		$out_scope = $this->create_test_site( [ 'name' => 'Cron Out Of Scope' ] );

		$this->restrict_owner_to_sites( [ $in_scope ] );

		$this->scope_filters['mainwp_fetch_url_authed_pre'] = static function () {
			return true;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $this->scope_filters['mainwp_fetch_url_authed_pre'], 10, 4 );

		$job_id = 'cron_scope_revoke_' . uniqid();
		$this->seed_batch_job( $job_id, [ $in_scope, $out_scope ], 'suspend', [ 'user_id' => $owner ] );

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$job = get_transient( 'mainwp_batch_job_' . $job_id );

		$this->assertContains( $in_scope, $job['successful'], 'The in-scope site must be processed.' );
		$revoked = array_filter( $job['errors'], static fn( $e ) => 'access_revoked' === $e['code'] );
		$this->assertCount( 1, $revoked, 'The out-of-scope site must be marked access_revoked.' );
		$revoked_row = array_values( $revoked )[0];
		$this->assertSame( $out_scope, $revoked_row['site_id'] );
	}

	/**
	 * A batch job whose owner no longer exists fails without processing.
	 *
	 * @return void
	 */
	public function test_batch_job_fails_when_owner_deleted() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$ghost = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$site  = $this->create_test_site( [ 'name' => 'Cron Ghost Owner' ] );

		$job_id = 'cron_ghost_owner_' . uniqid();
		$this->seed_batch_job( $job_id, [ $site ], 'suspend', [ 'user_id' => $ghost ] );

		wp_delete_user( $ghost );

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$job = get_transient( 'mainwp_batch_job_' . $job_id );

		$this->assertSame( 'failed', $job['status'], 'A job whose owner was deleted must fail.' );
		$this->assertNotContains( $site, $job['successful'], 'No site may be processed for a deleted owner.' );
		$codes = array_column( $job['errors'], 'code' );
		$this->assertContains( 'access_revoked', $codes, 'The failure must be reported as access_revoked.' );
	}

	/**
	 * A sync job with an unrestricted owner processes every site (control).
	 *
	 * @return void
	 */
	public function test_sync_job_with_unrestricted_owner_processes_all() {
		$this->skip_if_no_abilities_api();
		$owner = $this->set_current_user_as_admin();

		$site_ids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$site_ids[] = $this->create_test_site( [ 'name' => "Cron Unrestricted {$i}" ] );
		}
		$this->mock_sync_success();

		$job_id = 'cron_unrestricted_' . uniqid();
		$this->seed_sync_job( $job_id, $site_ids, [ 'user_id' => $owner ] );

		MainWP_Abilities_Cron::instance()->process_sync_job( $job_id );

		$job = get_transient( 'mainwp_sync_job_' . $job_id );
		$revoked = array_filter( $job['errors'], static fn( $e ) => 'access_revoked' === $e['code'] );
		$this->assertCount( 0, $revoked, 'An unrestricted owner must not trigger access_revoked.' );
		$this->assertCount( 3, $job['synced'], 'All sites must sync for an unrestricted owner.' );
	}

	/**
	 * Later callbacks must not inherit the owner of a completed job.
	 *
	 * @return void
	 */
	public function test_batch_job_restores_previous_current_user() {
		$this->skip_if_no_abilities_api();
		$previous_user_id = $this->set_current_user_as_admin();
		$owner_id         = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$site_id          = $this->create_test_site( [ 'name' => 'Cron Restored User' ] );
		$observed_user_id = null;

		$this->scope_filters['mainwp_currentuserallowedaccesssites'] = static function () use ( &$observed_user_id ) {
			$observed_user_id = get_current_user_id();
			return 'all';
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $this->scope_filters['mainwp_currentuserallowedaccesssites'], 10 );

		$job_id = 'cron_restore_user_' . uniqid();
		$this->seed_batch_job( $job_id, [ $site_id ], 'suspend', [ 'user_id' => $owner_id ] );

		MainWP_Abilities_Cron::instance()->process_batch_job( $job_id );

		$this->assertSame( $previous_user_id, get_current_user_id(), 'The processor must restore the previous current user.' );
		$this->assertSame( $owner_id, $observed_user_id, 'Site access must be checked as the job owner.' );
		$job = get_transient( 'mainwp_batch_job_' . $job_id );
		$this->assertSame( 'completed', $job['status'], 'The job must finish under its owner.' );
		$this->assertContains( $site_id, $job['successful'], 'The owned job must process its site.' );
	}
}
