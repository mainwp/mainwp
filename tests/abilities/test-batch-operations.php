<?php
/**
 * MainWP Abilities Batch Operations Tests
 *
 * Tests for batch queuing, threshold behavior, and job management.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class MainWP_Abilities_Batch_Operations_Test
 *
 * Tests for batch operations including queuing, thresholds, and site resolution.
 */
class MainWP_Abilities_Batch_Operations_Test extends MainWP_Abilities_Test_Case { //phpcs:ignore -- NOSONAR - multi methods.

	// =========================================================================
	// Batch Sync Queuing Tests
	// =========================================================================

	/**
	 * Test that queue_batch_sync stores job data.
	 *
	 * @return void
	 */
	public function test_queue_batch_sync_stores_job_data() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Create test sites.
		$sites = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$site_id = $this->create_test_site();
			$sites[] = $this->get_test_site( $site_id );
		}

		$job_id = \MainWP\Dashboard\MainWP_Abilities_Util::queue_batch_sync( $sites );

		$this->assertIsString( $job_id, 'Job ID should be a string.' );
		$this->assertNotWPError( $job_id );

		// Track job for cleanup.
		$this->track_sync_job( $job_id );

		// Verify job data stored.
		$status = \MainWP\Dashboard\MainWP_Abilities_Util::get_batch_sync_status( $job_id );

		$this->assertIsArray( $status );
		$this->assertEquals( 'sync', $status['job_type'] );
		$this->assertEquals( 'queued', $status['status'] );
		$this->assertCount( 5, $status['sites'] );
		$this->assertEquals( 5, $status['total'] );
		$this->assertEquals( 0, $status['processed'] );
		$this->assertEquals( 0, $status['progress'] );
		$this->assertIsArray( $status['synced'] );
		$this->assertIsArray( $status['errors'] );
	}

	/**
	 * Test that queue_batch_sync schedules cron event.
	 *
	 * @return void
	 */
	public function test_queue_batch_sync_schedules_cron() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();
		$site = $this->get_test_site( $site_id );

		$job_id = \MainWP\Dashboard\MainWP_Abilities_Util::queue_batch_sync( [ $site ] );

		$this->assertNotWPError( $job_id );

		// Track job for cleanup.
		$this->track_sync_job( $job_id );

		// Check cron scheduled.
		$scheduled = wp_next_scheduled( 'mainwp_process_sync_job', [ $job_id ] );

		$this->assertIsInt( $scheduled, 'Cron event should be scheduled.' );
		$this->assertGreaterThan( 0, $scheduled, 'Scheduled time should be positive.' );
	}

	// =========================================================================
	// Batch Update Queuing Tests
	// =========================================================================

	/**
	 * Test that queue_batch_updates stores job data.
	 *
	 * @return void
	 */
	public function test_queue_batch_updates_stores_job_data() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$sites = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$site_id = $this->create_test_site();
			$sites[] = $this->get_test_site( $site_id );
		}

		$job_id = \MainWP\Dashboard\MainWP_Abilities_Util::queue_batch_updates(
			$sites,
			[
				'types'          => [ 'plugins' ],
				'specific_items' => [],
			]
		);

		$this->assertIsString( $job_id );
		$this->assertNotWPError( $job_id );

		// Track job for cleanup.
		$this->track_update_job( $job_id );

		$status = \MainWP\Dashboard\MainWP_Abilities_Util::get_batch_update_status( $job_id );

		$this->assertIsArray( $status );
		$this->assertEquals( 'update', $status['job_type'] );
		$this->assertEquals( 'queued', $status['status'] );
		$this->assertCount( 5, $status['sites'] );
		$this->assertEquals( [ 'plugins' ], $status['types'] );
		$this->assertEquals( [], $status['specific_items'] );
		$this->assertEquals( 5, $status['total'] );
		$this->assertEquals( 0, $status['processed'] );
	}

	/**
	 * Test that queue_batch_updates schedules cron event.
	 *
	 * @return void
	 */
	public function test_queue_batch_updates_schedules_cron() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();
		$site = $this->get_test_site( $site_id );

		$job_id = \MainWP\Dashboard\MainWP_Abilities_Util::queue_batch_updates(
			[ $site ],
			[ 'types' => [ 'plugins' ] ]
		);

		$this->assertNotWPError( $job_id );

		// Track job for cleanup.
		$this->track_update_job( $job_id );

		$scheduled = wp_next_scheduled( 'mainwp_process_update_job', [ $job_id ] );

		$this->assertIsInt( $scheduled );
		$this->assertGreaterThan( 0, $scheduled );
	}

	// =========================================================================
	// Site Resolution Tests
	// =========================================================================

	/**
	 * Test that resolve_sites handles mixed identifiers.
	 *
	 * @return void
	 */
	public function test_resolve_sites_handles_mixed_identifiers() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [
			'name' => 'ID Site',
		] );

		$site2_id = $this->create_test_site( [
			'name' => 'Domain Site',
			'url'  => 'https://test-mixedresolve.example.com/',
		] );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_sites( [
			$site1_id,
			'test-mixedresolve.example.com',
			999999,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'sites', $result );
		$this->assertArrayHasKey( 'errors', $result );

		// 2 should resolve.
		$this->assertCount( 2, $result['sites'] );

		// 1 should error.
		$this->assertCount( 1, $result['errors'] );
		$this->assertEquals( 'mainwp_site_not_found', $result['errors'][0]['code'] );
	}

	/**
	 * Test that batch includes not-found sites in errors.
	 *
	 * @return void
	 */
	public function test_batch_includes_not_found_sites_in_errors() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [
			'name'                 => 'Valid Site',
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => [ $site1_id, 999999 ],
		] );

		$this->assertNotWPError( $result );

		// Non-existent site should be in errors.
		$found_not_found = false;
		foreach ( $result['errors'] as $error ) {
			if ( $error['code'] === 'mainwp_site_not_found' ) {
				$found_not_found = true;
				break;
			}
		}

		$this->assertTrue( $found_not_found );
		// We expect at least 1 error (the not-found site).
		// The valid site may also fail sync if connection isn't mocked, which is fine.
		$this->assertGreaterThanOrEqual( 1, $result['total_errors'] );
	}

	// =========================================================================
	// Threshold Tests
	// =========================================================================

	/**
	 * Test that sync at threshold executes immediately.
	 *
	 * Uses filter to lower threshold to 5 for efficient testing.
	 *
	 * @return void
	 */
	public function test_sync_at_threshold_executes_immediately() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Lower threshold to 5 for efficient testing.
		$threshold_callback = function() { return 5; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );

		try {
			// Create exactly 5 sites (at threshold).
			$site_ids = [];
			for ( $i = 0; $i < 5; $i++ ) {
				$site_ids[] = $this->create_test_site( [
					'name'                 => "Threshold Site {$i}",
					'offline_check_result' => 1,
				] );
			}

			$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
				'site_ids_or_domains' => $site_ids,
			] );

			$this->assertNotWPError( $result );
			$this->assertFalse( $result['queued'] ?? false, 'Sites at threshold should execute immediately.' );
			$this->assertArrayHasKey( 'synced', $result );
			$this->assertArrayHasKey( 'errors', $result );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}

	/**
	 * Test that sync above threshold returns queued.
	 *
	 * Uses filter to lower threshold to 5 for efficient testing.
	 *
	 * @return void
	 */
	public function test_sync_threshold_51_returns_queued() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Lower threshold to 5 for efficient testing.
		$threshold_callback = function() { return 5; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );

		try {
			// Create 6 sites (above threshold).
			$site_ids = [];
			for ( $i = 0; $i < 6; $i++ ) {
				$site_ids[] = $this->create_test_site( [
					'name'                 => "Threshold Site {$i}",
					'offline_check_result' => 1,
				] );
			}

			$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
				'site_ids_or_domains' => $site_ids,
			] );

			$this->assertNotWPError( $result );
			$this->assertTrue( $result['queued'], 'Sites above threshold should be queued.' );
			$this->assertArrayHasKey( 'job_id', $result );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}

	/**
	 * Test that updates at threshold executes immediately.
	 *
	 * Uses filter to lower threshold to 5 for efficient testing.
	 *
	 * @return void
	 */
	public function test_updates_threshold_exactly_50_executes_immediately() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Lower threshold to 5 for efficient testing.
		$threshold_callback = function() { return 5; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );

		try {
			$site_ids = [];
			for ( $i = 0; $i < 5; $i++ ) {
				$site_ids[] = $this->create_test_site( [
					'name'                 => "Update Threshold Site {$i}",
					'offline_check_result' => 1,
					'version'              => '5.0.0',
				] );
			}

			$result = $this->execute_ability( 'mainwp/run-updates-v1', [
				'confirm'             => true,
				'site_ids_or_domains' => $site_ids,
				'types'               => [ 'plugins' ],
			] );

			$this->assertNotWPError( $result );
			$this->assertFalse( $result['queued'] ?? false, 'Sites at threshold should execute immediately.' );
			$this->assertArrayHasKey( 'updated', $result );
			$this->assertArrayHasKey( 'errors', $result );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}

	/**
	 * Test that updates above threshold returns queued.
	 *
	 * Uses filter to lower threshold to 5 for efficient testing.
	 *
	 * @return void
	 */
	public function test_updates_threshold_51_returns_queued() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Lower threshold to 5 for efficient testing.
		$threshold_callback = function() { return 5; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );

		try {
			$site_ids = [];
			for ( $i = 0; $i < 6; $i++ ) {
				$site_ids[] = $this->create_test_site( [
					'name'                 => "Update Threshold Site {$i}",
					'offline_check_result' => 1,
					'version'              => '5.0.0',
				] );
			}

			$result = $this->execute_ability( 'mainwp/run-updates-v1', [
				'confirm'             => true,
				'site_ids_or_domains' => $site_ids,
				'types'               => [ 'plugins' ],
			] );

			$this->assertNotWPError( $result );
			$this->assertTrue( $result['queued'], 'Sites above threshold should be queued.' );
			$this->assertArrayHasKey( 'job_id', $result );
			$this->assertArrayNotHasKey( 'updated', $result );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}

	// =========================================================================
	// Job Status Tests
	// =========================================================================

	/**
	 * Test that get_batch_sync_status returns null for unknown job.
	 *
	 * @return void
	 */
	public function test_get_batch_sync_status_unknown_job() {
		$this->skip_if_no_abilities_api();

		$status = \MainWP\Dashboard\MainWP_Abilities_Util::get_batch_sync_status( 'unknown_job_id' );

		$this->assertNull( $status );
	}

	/**
	 * Test that get_batch_update_status returns null for unknown job.
	 *
	 * @return void
	 */
	public function test_get_batch_update_status_unknown_job() {
		$this->skip_if_no_abilities_api();

		$status = \MainWP\Dashboard\MainWP_Abilities_Util::get_batch_update_status( 'unknown_job_id' );

		$this->assertNull( $status );
	}

	/**
	 * Test that job data has correct structure.
	 *
	 * @return void
	 */
	public function test_job_data_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();
		$site = $this->get_test_site( $site_id );

		$job_id = \MainWP\Dashboard\MainWP_Abilities_Util::queue_batch_sync( [ $site ] );

		// Track job for cleanup.
		$this->track_sync_job( $job_id );

		$status = \MainWP\Dashboard\MainWP_Abilities_Util::get_batch_sync_status( $job_id );

		// Verify all expected keys.
		$expected_keys = [
			'job_type',
			'sites',
			'status',
			'created',
			'started',
			'completed',
			'synced',
			'errors',
			'progress',
			'total',
			'processed',
		];

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $status, "Job data should have '{$key}' key." );
		}

		// Verify types.
		$this->assertIsString( $status['job_type'] );
		$this->assertIsArray( $status['sites'] );
		$this->assertIsString( $status['status'] );
		$this->assertIsInt( $status['created'] );
		$this->assertIsArray( $status['synced'] );
		$this->assertIsArray( $status['errors'] );
		$this->assertIsInt( $status['progress'] );
		$this->assertIsInt( $status['total'] );
		$this->assertIsInt( $status['processed'] );
	}

	/**
	 * Test that transient is stored correctly for sync job.
	 *
	 * @return void
	 */
	public function test_sync_transient_stored() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();
		$site = $this->get_test_site( $site_id );

		$job_id = \MainWP\Dashboard\MainWP_Abilities_Util::queue_batch_sync( [ $site ] );

		// Track job for cleanup.
		$this->track_sync_job( $job_id );

		// Verify transient exists.
		$transient = get_transient( 'mainwp_sync_job_' . $job_id );

		$this->assertIsArray( $transient );
		$this->assertEquals( 'sync', $transient['job_type'] );
	}

	/**
	 * Test that transient is stored correctly for update job.
	 *
	 * @return void
	 */
	public function test_update_transient_stored() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();
		$site = $this->get_test_site( $site_id );

		$job_id = \MainWP\Dashboard\MainWP_Abilities_Util::queue_batch_updates(
			[ $site ],
			[ 'types' => [ 'plugins' ] ]
		);

		// Track job for cleanup.
		$this->track_update_job( $job_id );

		$transient = get_transient( 'mainwp_update_job_' . $job_id );

		$this->assertIsArray( $transient );
		$this->assertEquals( 'update', $transient['job_type'] );
	}

	// =========================================================================
	// URL Normalization Tests
	// =========================================================================

	/**
	 * Test URL normalization strips protocol.
	 *
	 * @return void
	 */
	public function test_normalize_url_strips_protocol() {
		$this->skip_if_no_abilities_api();

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::normalize_url( 'https://example.com/' );
		$this->assertEquals( 'example.com/', $result );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::normalize_url( 'http://example.com/' );
		$this->assertEquals( 'example.com/', $result );
	}

	/**
	 * Test URL normalization strips www prefix.
	 *
	 * @return void
	 */
	public function test_normalize_url_strips_www() {
		$this->skip_if_no_abilities_api();

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::normalize_url( 'https://www.example.com/' );
		$this->assertEquals( 'example.com/', $result );
	}

	/**
	 * Test URL normalization adds trailing slash.
	 *
	 * @return void
	 */
	public function test_normalize_url_adds_trailing_slash() {
		$this->skip_if_no_abilities_api();

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::normalize_url( 'example.com' );
		$this->assertEquals( 'example.com/', $result );
	}

	/**
	 * Test resolve_site with numeric ID.
	 *
	 * @return void
	 */
	public function test_resolve_site_numeric_id() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( $site_id );

		$this->assertIsObject( $result );
		$this->assertEquals( $site_id, (int) $result->id );
	}

	/**
	 * Test resolve_site with string numeric ID.
	 *
	 * @return void
	 */
	public function test_resolve_site_string_numeric_id() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( (string) $site_id );

		$this->assertIsObject( $result );
		$this->assertEquals( $site_id, (int) $result->id );
	}

	/**
	 * Test resolve_site with domain.
	 *
	 * @return void
	 */
	public function test_resolve_site_domain() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'url' => 'https://test-resolvedomain.example.com/',
		] );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( 'test-resolvedomain.example.com' );

		$this->assertIsObject( $result );
		$this->assertEquals( $site_id, (int) $result->id );
	}

	/**
	 * Test that queued response includes status_url.
	 *
	 * Uses filter to lower threshold to 5 for efficient testing.
	 *
	 * @return void
	 */
	public function test_queued_response_includes_status_url() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Lower threshold to 5 for efficient testing.
		$threshold_callback = function() { return 5; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );

		try {
			$site_ids = [];
			for ( $i = 0; $i < 6; $i++ ) {
				$site_ids[] = $this->create_test_site( [
					'name'                 => "Status URL Site {$i}",
					'offline_check_result' => 1,
				] );
			}

			$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
				'site_ids_or_domains' => $site_ids,
			] );

			$this->assertNotWPError( $result );
			$this->assertTrue( $result['queued'] );
			$this->assertArrayHasKey( 'status_url', $result );
			$this->assertStringContainsString( $result['job_id'], $result['status_url'] );
			$this->assertStringContainsString( 'mainwp/v2/jobs/', $result['status_url'] );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}

	// =========================================================================
	// Transient Failure Tests
	// =========================================================================

	/**
	 * Test that queue_batch_sync returns error when transient storage fails.
	 *
	 * Simulates a failure to persist the transient by intercepting set_transient
	 * and immediately deleting the value, causing the verification to fail.
	 *
	 * @expectedDeprecated setted_transient
	 * @return void
	 */
	public function test_queue_batch_sync_transient_failure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();
		$site    = $this->get_test_site( $site_id );

		// Hook into transient actions to delete the sync job transient immediately after it's set.
		// This simulates a storage failure scenario where the transient doesn't persist.
		// Note: 'set_transient' was added in WP 6.8; 'setted_transient' is the legacy hook for pre-6.8.
		$delete_callback = function ( $transient, $value, $expiration ) {
			if ( 0 === strpos( $transient, 'mainwp_sync_job_' ) ) {
				// Delete the transient right after it's set to simulate storage failure.
				delete_transient( $transient );
			}
		};

		add_action( 'set_transient', $delete_callback, 10, 3 );
		add_action( 'setted_transient', $delete_callback, 10, 3 );

		try {
			$result = \MainWP\Dashboard\MainWP_Abilities_Util::queue_batch_sync( [ $site ] );

			$this->assertWPError( $result );
			$this->assertEquals( 'mainwp_queue_failed', $result->get_error_code() );

			$error_data = $result->get_error_data();
			$this->assertIsArray( $error_data );
			$this->assertEquals( 500, $error_data['status'] );
		} finally {
			// Clean up both hooks.
			remove_action( 'set_transient', $delete_callback, 10 );
			remove_action( 'setted_transient', $delete_callback, 10 );
		}
	}

	/**
	 * Test that queue_batch_updates returns error when transient storage fails.
	 *
	 * Simulates a failure to persist the transient by intercepting set_transient
	 * and immediately deleting the value, causing the verification to fail.
	 *
	 * @expectedDeprecated setted_transient
	 * @return void
	 */
	public function test_queue_batch_updates_transient_failure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();
		$site    = $this->get_test_site( $site_id );

		// Hook into transient actions to delete the update job transient immediately after it's set.
		// This simulates a storage failure scenario where the transient doesn't persist.
		// Note: 'set_transient' was added in WP 6.8; 'setted_transient' is the legacy hook for pre-6.8.
		$delete_callback = function ( $transient, $value, $expiration ) {
			if ( 0 === strpos( $transient, 'mainwp_update_job_' ) ) {
				// Delete the transient right after it's set to simulate storage failure.
				delete_transient( $transient );
			}
		};

		add_action( 'set_transient', $delete_callback, 10, 3 );
		add_action( 'setted_transient', $delete_callback, 10, 3 );

		try {
			$result = \MainWP\Dashboard\MainWP_Abilities_Util::queue_batch_updates(
				[ $site ],
				[ 'types' => [ 'plugins' ] ]
			);

			$this->assertWPError( $result );
			$this->assertEquals( 'mainwp_queue_failed', $result->get_error_code() );

			$error_data = $result->get_error_data();
			$this->assertIsArray( $error_data );
			$this->assertEquals( 500, $error_data['status'] );
		} finally {
			// Clean up both hooks.
			remove_action( 'set_transient', $delete_callback, 10 );
			remove_action( 'setted_transient', $delete_callback, 10 );
		}
	}
}
