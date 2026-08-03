<?php
/**
 * MainWP Sync Sites Ability Tests
 *
 * Tests for the mainwp/sync-sites-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class MainWP_Sync_Sites_Ability_Test
 *
 * Tests for the mainwp/sync-sites-v1 ability.
 */
class MainWP_Sync_Sites_Ability_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Test that the ability is registered.
	 *
	 * @return void
	 */
	public function test_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$ability = wp_get_ability( 'mainwp/sync-sites-v1' );
		$this->assertNotNull( $ability, 'Ability mainwp/sync-sites-v1 should be registered.' );
	}

	/**
	 * Test that sync-sites with empty array syncs all sites.
	 *
	 * @return void
	 */
	public function test_sync_sites_with_empty_array_syncs_all() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Create 3 test sites.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_test_site( [
				'name'                 => "Sync All Test Site {$i}",
				'offline_check_result' => 1,
			] );
		}

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => [],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'synced', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'total_synced', $result );
		$this->assertArrayHasKey( 'total_errors', $result );
		$this->assertArrayHasKey( 'queued', $result );

		$this->assertIsArray( $result['synced'] );
		$this->assertIsArray( $result['errors'] );
		$this->assertIsInt( $result['total_synced'] );
		$this->assertIsInt( $result['total_errors'] );
		$this->assertIsBool( $result['queued'] );
	}

	/**
	 * Test input validation rejects invalid values.
	 *
	 * @return void
	 */
	public function test_sync_sites_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => 'not-an-array',
		] );

		$this->assertWPError( $result, 'Invalid input should return WP_Error.' );
		$this->assertEquals(
			'mainwp_invalid_input',
			$result->get_error_code(),
			'Non-array input should return mainwp_invalid_input error code.'
		);

		// A scalar exclude_ids must be rejected too, not silently dropped —
		// dropping it would sync sites the caller asked to exclude.
		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'exclude_ids' => '123',
		] );

		$this->assertWPError( $result, 'Scalar exclude_ids should return WP_Error.' );
		$this->assertEquals(
			'mainwp_invalid_input',
			$result->get_error_code(),
			'Scalar exclude_ids should return mainwp_invalid_input error code.'
		);
	}

	/**
	 * Test that sync-sites with specific IDs syncs those sites.
	 *
	 * @return void
	 */
	public function test_sync_sites_with_specific_ids() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [
			'name'                 => 'Sync Specific Site 1',
			'offline_check_result' => 1,
		] );

		$site2_id = $this->create_test_site( [
			'name'                 => 'Sync Specific Site 2',
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => [ $site1_id, $site2_id ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		// Verify sites are in synced or errors (depending on actual sync result).
		// Errors use 'identifier' key per the documented schema.
		$synced_ids = array_column( $result['synced'], 'id' );
		$error_ids  = array_map(
			function ( $id ) {
				return is_numeric( $id ) ? (int) $id : $id;
			},
			array_column( $result['errors'], 'identifier' )
		);
		$all_site_ids = array_merge( $synced_ids, $error_ids );

		$this->assertContains( $site1_id, $all_site_ids, 'Site 1 should be processed.' );
		$this->assertContains( $site2_id, $all_site_ids, 'Site 2 should be processed.' );
	}

	/**
	 * Test that sync-sites skips offline sites.
	 *
	 * @return void
	 */
	public function test_sync_sites_skips_offline_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$online_id = $this->create_test_site( [
			'name'                 => 'Online Site',
			'offline_check_result' => 1,
		] );

		$offline_id = $this->create_test_site( [
			'name'                 => 'Offline Site',
			'offline_check_result' => -1,
		] );

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => [ $online_id, $offline_id ],
		] );

		$this->assertNotWPError( $result );

		// Offline site should be in errors. Errors use 'identifier' key per the documented schema.
		$error_identifiers = array_map(
			function ( $id ) {
				return is_numeric( $id ) ? (int) $id : $id;
			},
			array_column( $result['errors'], 'identifier' )
		);
		$this->assertContains( $offline_id, $error_identifiers, 'Offline site should be in errors.' );

		// Find error for offline site.
		foreach ( $result['errors'] as $error ) {
			$error_id = is_numeric( $error['identifier'] ) ? (int) $error['identifier'] : $error['identifier'];
			if ( $error_id === $offline_id ) {
				$this->assertEquals( 'mainwp_site_offline', $error['code'], 'Error code should be mainwp_site_offline.' );
				break;
			}
		}
	}

	/**
	 * Test that sync-sites handles batch processing.
	 *
	 * Verifies that when syncing multiple sites, the result correctly
	 * categorizes sites into 'synced' and 'errors' arrays.
	 *
	 * Note: In a test environment without real child sites, all syncs will
	 * fail due to connection issues. This test verifies the structure and
	 * error handling rather than actual sync success.
	 *
	 * @return void
	 */
	public function test_sync_sites_partial_batch_failure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [
			'name'                 => 'Test Site 1',
			'offline_check_result' => 1,
		] );

		$site2_id = $this->create_test_site( [
			'name'                 => 'Test Site 2',
			'offline_check_result' => 1,
		] );

		// Mock sync failure for site2 with specific error code.
		$this->mock_sync_failure( 'mainwp_connection_failed', 'Connection timed out', $site2_id );

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => [ $site1_id, $site2_id ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result['synced'] );
		$this->assertIsArray( $result['errors'] );

		// Both sites should be processed (in either synced or errors).
		$this->assertEquals(
			2,
			$result['total_synced'] + $result['total_errors'],
			'Both sites should be processed (synced or errored).'
		);

		// Site 2 should be in errors with our specific error code.
		$error_ids = array_map(
			function ( $id ) {
				return is_numeric( $id ) ? (int) $id : $id;
			},
			array_column( $result['errors'], 'identifier' )
		);
		$this->assertContains( $site2_id, $error_ids, 'Site 2 should be in errors.' );

		// Verify the error has a valid error code.
		// Note: The mock may not take effect if sync throws an exception before
		// the filter is applied. Valid codes include our mock code or exception code.
		$site2_error = null;
		foreach ( $result['errors'] as $error ) {
			$error_id = is_numeric( $error['identifier'] ) ? (int) $error['identifier'] : $error['identifier'];
			if ( $error_id === $site2_id ) {
				$site2_error = $error;
				break;
			}
		}
		$this->assertNotNull( $site2_error, 'Site 2 error should exist.' );
		$this->assertContains(
			$site2_error['code'],
			[ 'mainwp_connection_failed', 'mainwp_sync_exception', 'mainwp_sync_failed' ],
			'Error code should indicate sync failure.'
		);
	}

	/**
	 * Test that sync-sites large batch returns queued response.
	 *
	 * @return void
	 */
	public function test_sync_sites_large_batch_returns_queued() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Lower threshold to 5 for efficient testing.
		$threshold_callback = function() { return 5; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );

		try {
			// Create 6 test sites (exceeds lowered threshold).
			$site_ids = [];
			for ( $i = 0; $i < 6; $i++ ) {
				$site_ids[] = $this->create_test_site( [
					'name'                 => "Large Batch Site {$i}",
					'offline_check_result' => 1,
				] );
			}

			$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
				'site_ids_or_domains' => $site_ids,
			] );

			$this->assertNotWPError( $result );
			$this->assertIsArray( $result );

			// Should be queued.
			$this->assertTrue( $result['queued'], 'Large batch should be queued.' );
			$this->assertArrayHasKey( 'job_id', $result, 'Queued response should have job_id.' );
			$this->assertArrayHasKey( 'status_url', $result, 'Queued response should have status_url.' );
			$this->assertArrayHasKey( 'sites_queued', $result, 'Queued response should have sites_queued.' );
			$this->assertEquals( 6, $result['sites_queued'] );

			// Track job for cleanup in tearDown.
			$this->track_sync_job( $result['job_id'] );

			// Queued jobs don't have synced key.
			$this->assertArrayNotHasKey( 'synced', $result, 'Queued response should not have synced key.' );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}

	/**
	 * Test that sync-sites small batch executes immediately.
	 *
	 * @return void
	 */
	public function test_sync_sites_small_batch_executes_immediately() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Create 5 test sites (under default 200 threshold).
		$site_ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$site_ids[] = $this->create_test_site( [
				'name'                 => "Small Batch Site {$i}",
				'offline_check_result' => 1,
			] );
		}

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => $site_ids,
		] );

		$this->assertNotWPError( $result );

		// Should not be queued.
		$this->assertFalse( $result['queued'] ?? false, 'Small batch should not be queued.' );
		$this->assertArrayHasKey( 'synced', $result, 'Immediate response should have synced key.' );
		$this->assertArrayHasKey( 'errors', $result, 'Immediate response should have errors key.' );
	}

	/**
	 * Test that sync-sites handles non-existent site ID.
	 *
	 * @return void
	 */
	public function test_sync_sites_with_nonexistent_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$valid_id = $this->create_test_site( [
			'name'                 => 'Valid Site',
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => [ $valid_id, 999999 ],
		] );

		$this->assertNotWPError( $result );

		// Non-existent site should be in errors with 'identifier' matching the invalid input.
		$found_not_found       = false;
		$matched_identifier    = false;
		$nonexistent_id        = 999999;

		foreach ( $result['errors'] as $error ) {
			if ( $error['code'] === 'mainwp_site_not_found' ) {
				$found_not_found = true;
				// Verify the identifier matches what we requested.
				$error_id = is_numeric( $error['identifier'] ) ? (int) $error['identifier'] : $error['identifier'];
				if ( $error_id === $nonexistent_id ) {
					$matched_identifier = true;
				}
				break;
			}
		}

		$this->assertTrue( $found_not_found, 'Non-existent site should produce mainwp_site_not_found error.' );
		$this->assertTrue( $matched_identifier, 'Error identifier should match the invalid site ID used in input.' );
	}

	/**
	 * Test that sync-sites accepts domain strings.
	 *
	 * @return void
	 */
	public function test_sync_sites_with_domain_strings() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Domain Sync Site',
			'url'                  => 'https://test-domainsync.example.com/',
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => [ 'test-domainsync.example.com' ],
		] );

		$this->assertNotWPError( $result );

		// Site should be processed. Errors use 'identifier' key per the documented schema.
		$synced_ids = array_column( $result['synced'], 'id' );
		$error_ids  = array_map(
			function ( $id ) {
				return is_numeric( $id ) ? (int) $id : $id;
			},
			array_column( $result['errors'], 'identifier' )
		);
		$all_site_ids = array_merge( $synced_ids, $error_ids );

		$this->assertContains( $site_id, $all_site_ids, 'Site resolved by domain should be processed.' );
	}

	/**
	 * Test that sync-sites handles mixed ID and domain input.
	 *
	 * @return void
	 */
	public function test_sync_sites_with_mixed_identifiers() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [
			'name'                 => 'ID Site',
			'offline_check_result' => 1,
		] );

		$site2_id = $this->create_test_site( [
			'name'                 => 'Domain Site',
			'url'                  => 'https://test-mixeddomain.example.com/',
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => [ $site1_id, 'test-mixeddomain.example.com' ],
		] );

		$this->assertNotWPError( $result );

		// Errors use 'identifier' key per the documented schema.
		$synced_ids = array_column( $result['synced'], 'id' );
		$error_ids  = array_map(
			function ( $id ) {
				return is_numeric( $id ) ? (int) $id : $id;
			},
			array_column( $result['errors'], 'identifier' )
		);
		$all_site_ids = array_merge( $synced_ids, $error_ids );

		$this->assertContains( $site1_id, $all_site_ids );
		$this->assertContains( $site2_id, $all_site_ids );
	}

	/**
	 * Test that sync-sites response has proper structure.
	 *
	 * @return void
	 */
	public function test_sync_sites_response_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Structure Test Site',
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => [ $site_id ],
		] );

		$this->assertNotWPError( $result );

		// Check synced item structure (if any).
		if ( ! empty( $result['synced'] ) ) {
			$synced_item = $result['synced'][0];
			$this->assertArrayHasKey( 'id', $synced_item );
			$this->assertArrayHasKey( 'url', $synced_item );
			$this->assertArrayHasKey( 'name', $synced_item );
		}

		// Check error item structure (if any).
		// Per the documented schema, errors use 'identifier', 'code', and 'message' keys.
		if ( ! empty( $result['errors'] ) ) {
			$error_item = $result['errors'][0];
			$this->assertArrayHasKey( 'identifier', $error_item, 'Error should have identifier key.' );
			$this->assertArrayHasKey( 'code', $error_item, 'Error should have code key.' );
			$this->assertArrayHasKey( 'message', $error_item, 'Error should have message key.' );
			// Identifier should be scalar (int or string).
			$this->assertTrue(
				is_int( $error_item['identifier'] ) || is_string( $error_item['identifier'] ),
				'Error identifier should be scalar (int or string).'
			);
		}
	}

	/**
	 * Test that sync-sites handles child version check.
	 *
	 * Verifies that sites with outdated child plugin versions are rejected
	 * during sync with the appropriate error code and message structure.
	 *
	 * The minimum version is defined in MainWP_Abilities_Util::MIN_CHILD_VERSION_FOR_ABILITIES.
	 * This test uses that constant to stay aligned when the version threshold changes.
	 *
	 * @return void
	 */
	public function test_sync_sites_child_version_check() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Get the minimum version from the centralized constant.
		$min_version = \MainWP\Dashboard\MainWP_Abilities_Util::MIN_CHILD_VERSION_FOR_ABILITIES;

		// Create site with outdated child (below minimum version).
		$outdated_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below MIN_CHILD_VERSION_FOR_ABILITIES.
		] );

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => [ $outdated_id ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'synced', $result );

		// Verify outdated site appears in errors with correct error code.
		$found_outdated_error = false;
		$error_message        = '';
		foreach ( $result['errors'] as $error ) {
			if ( isset( $error['identifier'] ) && $error['identifier'] === $outdated_id ) {
				if ( $error['code'] === 'mainwp_child_outdated' ) {
					$found_outdated_error = true;
					$error_message        = $error['message'] ?? '';
					break;
				}
			}
		}

		$this->assertTrue(
			$found_outdated_error,
			'Outdated child site should appear in errors with mainwp_child_outdated code.'
		);

		// Verify error message mentions the minimum required version from the constant.
		$this->assertStringContainsString(
			$min_version,
			$error_message,
			"Error message should mention the minimum required version ({$min_version})."
		);

		// Outdated site should NOT be in synced results.
		$synced_ids = array_column( $result['synced'], 'id' );
		$this->assertNotContains(
			$outdated_id,
			$synced_ids,
			'Outdated site should not appear in synced results.'
		);
	}

	/**
	 * Test that sync-sites exactly at threshold executes immediately.
	 *
	 * @return void
	 */
	public function test_sync_sites_at_threshold_executes_immediately() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Lower threshold to 50 for efficient testing.
		$threshold_callback = function () {
			return 50;
		};
		add_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );

		try {
			// Create exactly 50 test sites (at lowered threshold).
			$site_ids = [];
			for ( $i = 0; $i < 50; $i++ ) {
				$site_ids[] = $this->create_test_site( [
					'name'                 => "Threshold Site {$i}",
					'offline_check_result' => 1,
				] );
			}

			$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
				'site_ids_or_domains' => $site_ids,
			] );

			$this->assertNotWPError( $result );

			// At threshold (50) should execute immediately, not queue.
			$this->assertFalse( $result['queued'] ?? false, 'Exactly at threshold (50) should execute immediately.' );
			$this->assertArrayHasKey( 'synced', $result );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}
}
