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
	 * Test resolve_site matches an exact site name case-insensitively.
	 *
	 * @return void
	 */
	public function test_resolve_site_exact_name_match() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Zebra Prod',
		] );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( 'zebra prod' );

		$this->assertIsObject( $result );
		$this->assertEquals( $site_id, (int) $result->id );
	}

	/**
	 * Test resolve_site matches a partial name when exactly one site matches.
	 *
	 * @return void
	 */
	public function test_resolve_site_unique_partial_name_match() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Zebra Prod',
		] );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( 'zebra' );

		$this->assertIsObject( $result );
		$this->assertEquals( $site_id, (int) $result->id );
	}

	/**
	 * Test resolve_site never guesses between multiple partial matches.
	 *
	 * The ambiguous error (not a silent pick) is the safety property for
	 * disruptive batch abilities that resolve sites by identifier.
	 *
	 * @return void
	 */
	public function test_resolve_site_ambiguous_match_returns_error_no_guess() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$west_id = $this->create_test_site( [
			'name' => 'Zebra West',
		] );
		$east_id = $this->create_test_site( [
			'name' => 'Zebra East',
		] );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( 'zebra' );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_ambiguous', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data['candidates'] );
		$this->assertContains( $west_id, array_map( 'intval', $data['candidates'] ) );
		$this->assertContains( $east_id, array_map( 'intval', $data['candidates'] ) );
	}

	/**
	 * A domain or URL with no matching site URL must not resolve to another
	 * site whose URL merely contains it: mutating abilities would act on the
	 * wrong site. An exact name match still resolves.
	 *
	 * @return void
	 */
	public function test_resolve_site_missing_domain_does_not_partial_match() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$this->create_test_site( [
			'name' => 'Shop',
			'url'  => 'https://shop.resolve-partial.example/',
		] );

		foreach ( [ 'resolve-partial.example', 'https://resolve-partial.example', 'resolve-partial.example/' ] as $identifier ) {
			$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( $identifier );
			$this->assertWPError( $result, $identifier );
			$this->assertEquals( 'mainwp_site_not_found', $result->get_error_code(), $identifier );
		}

		$named_id = $this->create_test_site( [
			'name' => 'resolve-named.example',
			'url'  => 'https://elsewhere.resolve-named.example/',
		] );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( 'resolve-named.example' );
		$this->assertIsObject( $result );
		$this->assertEquals( $named_id, (int) $result->id );
	}

	/**
	 * Test resolve_site still returns mainwp_site_not_found for zero matches.
	 *
	 * @return void
	 */
	public function test_resolve_site_still_not_found() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$this->create_test_site( [
			'name' => 'Zebra Prod',
		] );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( 'no-such-zzz-qwerty' );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_not_found', $result->get_error_code() );
	}

	/**
	 * Restrict the current user to an explicit list of site IDs.
	 *
	 * Exercises the real get_sql_where_allow_access_sites() scoping the same
	 * way MainWP Team Control style restrictions do.
	 *
	 * @param array $allowed_ids Site IDs the current user may access.
	 * @return callable The filter callback, for remove_filter().
	 */
	private function restrict_allowed_sites( array $allowed_ids ) {
		$filter = function () use ( $allowed_ids ) {
			return array_map( 'intval', $allowed_ids );
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $filter );
		return $filter;
	}

	/**
	 * Test resolve_site hides inaccessible sites from partial-name matches.
	 *
	 * A restricted user searching a name that only matches an inaccessible
	 * site gets the same not-found error as for a nonexistent site, with no
	 * candidate names or IDs leaked.
	 *
	 * @return void
	 */
	public function test_resolve_site_inaccessible_partial_match_returns_not_found() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$allowed_id = $this->create_test_site( [ 'name' => 'Alpha Home' ] );
		$this->create_test_site( [ 'name' => 'Zebra Hidden' ] );

		$filter = $this->restrict_allowed_sites( [ $allowed_id ] );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( 'zebra' );

		remove_filter( 'mainwp_currentuserallowedaccesssites', $filter );

		$this->assertNotNull( $result );
		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_not_found', $result->get_error_code() );
		$this->assertStringNotContainsString( 'Zebra Hidden', $result->get_error_message() );
		$this->assertArrayNotHasKey( 'candidates', (array) $result->get_error_data() );
	}

	/**
	 * Test resolve_site resolves the accessible site when an inaccessible
	 * site also matches by NAME only.
	 *
	 * This is the operator-precedence regression test: if the access scope is
	 * appended to the LIKE query without parenthesizing the OR, the name-match
	 * branch bypasses the restriction and this becomes an ambiguous error that
	 * leaks the hidden site.
	 *
	 * @return void
	 */
	public function test_resolve_site_name_only_mixed_access_resolves_accessible() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$west_id = $this->create_test_site( [ 'name' => 'Zebra West' ] );
		$this->create_test_site( [ 'name' => 'Zebra East' ] );

		$filter = $this->restrict_allowed_sites( [ $west_id ] );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( 'zebra' );

		remove_filter( 'mainwp_currentuserallowedaccesssites', $filter );

		$this->assertNotWPError( $result );
		$this->assertIsObject( $result );
		$this->assertEquals( $west_id, (int) $result->id );
	}

	/**
	 * Test ambiguous-match candidates only ever list accessible sites.
	 *
	 * @return void
	 */
	public function test_resolve_site_ambiguous_candidates_exclude_inaccessible() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$one_id = $this->create_test_site( [ 'name' => 'Zebra One' ] );
		$two_id = $this->create_test_site( [ 'name' => 'Zebra Two' ] );
		$hidden = $this->create_test_site( [ 'name' => 'Zebra Hidden Three' ] );

		$filter = $this->restrict_allowed_sites( [ $one_id, $two_id ] );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( 'zebra' );

		remove_filter( 'mainwp_currentuserallowedaccesssites', $filter );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_ambiguous', $result->get_error_code() );

		$data       = $result->get_error_data();
		$candidates = array_map( 'intval', $data['candidates'] );
		$this->assertContains( $one_id, $candidates );
		$this->assertContains( $two_id, $candidates );
		$this->assertNotContains( $hidden, $candidates );
		$this->assertStringNotContainsString( 'Zebra Hidden Three', $result->get_error_message() );
	}

	/**
	 * Test resolving an inaccessible site's URL returns not-found without
	 * falling through to the name search.
	 *
	 * The decoy is an accessible site whose name contains the searched domain
	 * string; a fallthrough would silently resolve to it.
	 *
	 * @return void
	 */
	public function test_resolve_site_inaccessible_url_returns_not_found_no_fallthrough() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$this->create_test_site(
			[
				'name' => 'Hidden Zone',
				'url'  => 'https://test-hiddenzone.example.com/',
			]
		);
		$decoy_id = $this->create_test_site( [ 'name' => 'Mirror of test-hiddenzone.example.com' ] );

		$filter = $this->restrict_allowed_sites( [ $decoy_id ] );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( 'test-hiddenzone.example.com' );

		remove_filter( 'mainwp_currentuserallowedaccesssites', $filter );

		$this->assertNotNull( $result );
		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_not_found', $result->get_error_code() );
	}

	/**
	 * Test a single exact-name match on an inaccessible site returns a
	 * WP_Error, never null.
	 *
	 * @return void
	 */
	public function test_resolve_site_single_inaccessible_exact_name_returns_error_not_null() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$this->create_test_site( [ 'name' => 'Zebra Solo' ] );
		$other_id = $this->create_test_site( [ 'name' => 'Alpha Other' ] );

		$filter = $this->restrict_allowed_sites( [ $other_id ] );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::resolve_site( 'Zebra Solo' );

		remove_filter( 'mainwp_currentuserallowedaccesssites', $filter );

		$this->assertNotNull( $result );
		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_not_found', $result->get_error_code() );
	}

	/**
	 * Test that queued response includes status_url.
	 *
	 * Dispatch the URL and verify it returns the queued job.
	 *
	 * @dataProvider queued_status_url_abilities
	 * @param string $ability_name Ability to queue.
	 * @param string $job_type Expected job type.
	 * @return void
	 */
	public function test_queued_response_status_url_resolves( $ability_name, $job_type ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$threshold_callback = function () {
			return 1;
		};
		add_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );

		try {
			$site_ids = array( $this->create_test_site(), $this->create_test_site() );
			$input    = array( 'site_ids_or_domains' => $site_ids );
			if ( 'update' === $job_type ) {
				$input['confirm'] = true;
			}
			$result = $this->execute_ability( $ability_name, $input );

			$this->assertNotWPError( $result );
			$this->assertTrue( $result['queued'] );
			$this->assertArrayHasKey( 'status_url', $result );
			if ( 'sync' === $job_type ) {
				$this->track_sync_job( $result['job_id'] );
			} else {
				$this->track_update_job( $result['job_id'] );
			}

			$url   = wp_parse_url( $result['status_url'] );
			$query = array();
			wp_parse_str( $url['query'] ?? '', $query );
			$route = $query['rest_route'] ?? substr( $url['path'], strlen( wp_parse_url( rest_url(), PHP_URL_PATH ) ) );
			unset( $query['rest_route'] );
			$request = new \WP_REST_Request( 'GET', '/' . ltrim( $route, '/' ) );
			$request->set_query_params( $query );
			$response = rest_do_request( $request );

			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$status = $response->get_data();
			$this->assertSame( $result['job_id'], $status['job_id'] );
			$this->assertSame( $job_type, $status['type'] );
			$this->assertSame( 'queued', $status['status'] );
			$this->assertSame( 2, $status['total'] );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}

	/**
	 * Queued response producers.
	 *
	 * @return array
	 */
	public function queued_status_url_abilities() {
		return array(
			'sync'        => array( 'mainwp/sync-sites-v1', 'sync' ),
			'run-updates' => array( 'mainwp/run-updates-v1', 'update' ),
			'update-all'  => array( 'mainwp/update-all-v1', 'update' ),
		);
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
