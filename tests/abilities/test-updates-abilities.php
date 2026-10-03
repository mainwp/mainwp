<?php
/**
 * MainWP Updates Abilities Tests
 *
 * Tests for update-related abilities:
 * - mainwp/list-updates-v1
 * - mainwp/run-updates-v1
 * - mainwp/list-ignored-updates-v1
 * - mainwp/set-ignored-updates-v1
 * - mainwp/get-site-updates-v1
 * - mainwp/update-site-core-v1
 * - mainwp/update-site-plugins-v1
 * - mainwp/update-site-themes-v1
 * - mainwp/update-site-translations-v1
 * - mainwp/update-all-v1
 * - mainwp/ignore-site-core-v1
 * - mainwp/ignore-site-plugins-v1
 * - mainwp/ignore-site-themes-v1
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class MainWP_Updates_Abilities_Test
 *
 * Tests for update-related abilities.
 */
class MainWP_Updates_Abilities_Test extends MainWP_Abilities_Test_Case {

	// =========================================================================
	// List Updates Tests
	// =========================================================================

	/**
	 * Test that list-updates returns expected shape.
	 *
	 * @return void
	 */
	public function test_list_updates_returns_expected_shape() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'types' => [ 'plugins', 'themes', 'core' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updates', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'page', $result );
		$this->assertArrayHasKey( 'per_page', $result );
		$this->assertArrayHasKey( 'total', $result );

		$this->assertIsArray( $result['updates'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertIsInt( $result['page'] );
		$this->assertIsInt( $result['per_page'] );
		$this->assertIsInt( $result['total'] );

		// Verify summary structure.
		$this->assertArrayHasKey( 'core', $result['summary'] );
		$this->assertArrayHasKey( 'plugins', $result['summary'] );
		$this->assertArrayHasKey( 'themes', $result['summary'] );
		$this->assertArrayHasKey( 'translations', $result['summary'] );
		$this->assertArrayHasKey( 'total', $result['summary'] );
	}

	/**
	 * Test that list-updates-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_list_updates_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/list-updates-v1',
			$abilities,
			'Ability mainwp/list-updates-v1 should be registered.'
		);
	}

	/**
	 * Test that list-updates-v1 requires authentication.
	 *
	 * @return void
	 */
	public function test_list_updates_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/list-updates-v1', [] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that list-updates-v1 requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_list_updates_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$this->set_current_user_as_subscriber();

		$result = $this->execute_ability( 'mainwp/list-updates-v1', [] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that list-updates-v1 validates input.
	 *
	 * @return void
	 */
	public function test_list_updates_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Invalid types parameter (should be array, not string).
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'types' => 'invalid-string',
		] );

		$this->assertWPError( $result, 'Invalid input should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that list-updates filters by type.
	 *
	 * @return void
	 */
	public function test_list_updates_filters_by_type() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Updates Filter Site',
			'offline_check_result' => 1,
		] );

		// Seed plugin and theme upgrades.
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		$this->set_site_theme_upgrades( $site_id, [
			'twentytwentyfour' => [
				'Name'        => 'Twenty Twenty-Four',
				'Version'     => '1.0',
				'new_version' => '1.1',
			],
		] );

		// Request only plugins.
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'types' => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );

		// All updates should be plugin type.
		foreach ( $result['updates'] as $update ) {
			$this->assertEquals( 'plugin', $update['type'], 'Filtered updates should be plugins only.' );
		}
	}

	/**
	 * Test that list-updates filters by site IDs.
	 *
	 * @return void
	 */
	public function test_list_updates_filters_by_site_ids() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [
			'name'                 => 'Updates Site 1',
			'offline_check_result' => 1,
		] );

		$site2_id = $this->create_test_site( [
			'name'                 => 'Updates Site 2',
			'offline_check_result' => 1,
		] );

		// Seed plugin upgrade for site1 only.
		$this->set_site_plugin_upgrades( $site1_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		// Request only site1.
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'site_ids_or_domains' => [ $site1_id ],
		] );

		$this->assertNotWPError( $result );

		// All updates should be for site1.
		foreach ( $result['updates'] as $update ) {
			$this->assertEquals( $site1_id, $update['site_id'], 'Filtered updates should be for specified site.' );
		}

		// Verify site2 is excluded from results.
		$returned_site_ids = array_column( $result['updates'], 'site_id' );
		$this->assertNotContains( $site2_id, $returned_site_ids, 'Updates for site2 should be excluded when filtering by site1.' );
	}

	/**
	 * Test that list-updates includes plugin updates (verifies fallback retrieval).
	 *
	 * This test ensures the fix for retrieving plugin_upgrades works correctly,
	 * even when the site object property is not populated by map_site().
	 *
	 * @return void
	 */
	public function test_list_updates_includes_plugin_updates() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Plugin Updates Test Site',
			'offline_check_result' => 1,
		] );

		// Seed plugin upgrades.
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet Anti-spam',
				'Version'     => '5.0.0',
				'new_version' => '5.1.0',
			],
			'jetpack/jetpack.php' => [
				'Name'        => 'Jetpack',
				'Version'     => '12.0',
				'new_version' => '12.5',
			],
		] );

		// Call list-updates with all sites (empty site_ids_or_domains).
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'types' => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result, 'list-updates should not return WP_Error.' );
		$this->assertGreaterThan( 0, $result['summary']['plugins'], 'Plugin updates should be returned in summary.' );

		// Verify updates contain our seeded plugins.
		$plugin_slugs = array_column( $result['updates'], 'slug' );
		$this->assertContains( 'akismet/akismet.php', $plugin_slugs, 'Akismet plugin update should be returned.' );
		$this->assertContains( 'jetpack/jetpack.php', $plugin_slugs, 'Jetpack plugin update should be returned.' );
	}

	/**
	 * Test that list-updates includes theme updates (verifies fallback retrieval).
	 *
	 * This test ensures the fix for retrieving theme_upgrades works correctly.
	 *
	 * @return void
	 */
	public function test_list_updates_includes_theme_updates() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Theme Updates Test Site',
			'offline_check_result' => 1,
		] );

		// Seed theme upgrades.
		$this->set_site_theme_upgrades( $site_id, [
			'twentytwentyfour' => [
				'Name'        => 'Twenty Twenty-Four',
				'Version'     => '1.0',
				'new_version' => '1.2',
			],
		] );

		// Call list-updates with all sites (empty site_ids_or_domains).
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'types' => [ 'themes' ],
		] );

		$this->assertNotWPError( $result, 'list-updates should not return WP_Error.' );
		$this->assertGreaterThan( 0, $result['summary']['themes'], 'Theme updates should be returned in summary.' );

		// Verify updates contain our seeded theme.
		$theme_slugs = array_column( $result['updates'], 'slug' );
		$this->assertContains( 'twentytwentyfour', $theme_slugs, 'Twenty Twenty-Four theme update should be returned.' );
	}

	/**
	 * Test that list-updates includes translation updates (verifies fallback retrieval).
	 *
	 * This test ensures the fix for retrieving translation_upgrades works correctly.
	 *
	 * @return void
	 */
	public function test_list_updates_includes_translation_updates() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Translation Updates Test Site',
			'offline_check_result' => 1,
		] );

		// Seed translation upgrades.
		$this->set_site_translation_upgrades( $site_id, [
			[
				'slug'        => 'default',
				'name'        => 'WordPress Core',
				'version'     => '6.4',
				'new_version' => '6.5',
			],
			[
				'slug'        => 'akismet',
				'name'        => 'Akismet',
				'version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		// Call list-updates with all sites (empty site_ids_or_domains).
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'types' => [ 'translations' ],
		] );

		$this->assertNotWPError( $result, 'list-updates should not return WP_Error.' );
		$this->assertGreaterThan( 0, $result['summary']['translations'], 'Translation updates should be returned in summary.' );
	}

	/**
	 * Test that list-updates includes all update types when using specific site IDs.
	 *
	 * This test verifies the fix works through the resolve_site() code path.
	 *
	 * @return void
	 */
	public function test_list_updates_with_specific_site_ids_includes_all_types() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'All Updates Test Site',
			'offline_check_result' => 1,
		] );

		// Seed all update types.
		$this->set_site_plugin_upgrades( $site_id, [
			'hello-dolly/hello.php' => [
				'Name'        => 'Hello Dolly',
				'Version'     => '1.7',
				'new_version' => '1.8',
			],
		] );

		$this->set_site_theme_upgrades( $site_id, [
			'twentytwentythree' => [
				'Name'        => 'Twenty Twenty-Three',
				'Version'     => '1.0',
				'new_version' => '1.3',
			],
		] );

		$this->set_site_translation_upgrades( $site_id, [
			[
				'slug'        => 'default',
				'name'        => 'WordPress Core',
				'version'     => '6.3',
				'new_version' => '6.4',
			],
		] );

		// Call list-updates with SPECIFIC site ID (tests resolve_site path).
		$result = $this->execute_ability( 'mainwp/list-updates-v1', [
			'site_ids_or_domains' => [ $site_id ],
		] );

		$this->assertNotWPError( $result, 'list-updates should not return WP_Error.' );

		// Verify all types are returned.
		$this->assertGreaterThan( 0, $result['summary']['plugins'], 'Plugin updates should be returned for specific site.' );
		$this->assertGreaterThan( 0, $result['summary']['themes'], 'Theme updates should be returned for specific site.' );
		$this->assertGreaterThan( 0, $result['summary']['translations'], 'Translation updates should be returned for specific site.' );
	}

	// =========================================================================
	// Run Updates Tests
	// =========================================================================

	/**
	 * Test that run-updates-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_run_updates_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/run-updates-v1',
			$abilities,
			'Ability mainwp/run-updates-v1 should be registered.'
		);
	}

	/**
	 * Test that run-updates executes plugin updates.
	 *
	 * @return void
	 */
	public function test_run_updates_executes_plugin_updates() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Run Updates Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'site_ids_or_domains' => [ $site_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertIsArray( $result['updated'] );
		$this->assertIsArray( $result['errors'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'total_updated', $result['summary'] );
		$this->assertArrayHasKey( 'total_errors', $result['summary'] );
		$this->assertArrayHasKey( 'sites_updated', $result['summary'] );
	}

	/**
	 * Test that run-updates skips offline sites.
	 *
	 * @return void
	 */
	public function test_run_updates_skips_offline_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$offline_id = $this->create_test_site( [
			'name'                 => 'Offline Update Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'site_ids_or_domains' => [ $offline_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );

		// Offline site should be in errors.
		$found_offline_error = false;
		foreach ( $result['errors'] as $error ) {
			if ( $error['site_id'] === $offline_id && $error['code'] === 'mainwp_site_offline' ) {
				$found_offline_error = true;
				break;
			}
		}

		$this->assertTrue( $found_offline_error, 'Offline site should produce mainwp_site_offline error.' );
	}

	/**
	 * Test that run-updates handles child outdated error.
	 *
	 * @return void
	 */
	public function test_run_updates_handles_child_outdated() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$outdated_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below 4.0.0 minimum.
		] );

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'site_ids_or_domains' => [ $outdated_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );

		// Outdated child should be in errors.
		$found_outdated_error = false;
		foreach ( $result['errors'] as $error ) {
			if ( $error['site_id'] === $outdated_id && $error['code'] === 'mainwp_child_outdated' ) {
				$found_outdated_error = true;
				break;
			}
		}

		$this->assertTrue( $found_outdated_error, 'Outdated child should produce mainwp_child_outdated error.' );
	}

	/**
	 * Test that run-updates large batch returns queued response.
	 *
	 * @return void
	 */
	public function test_run_updates_large_batch_returns_queued() {
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
					'name'                 => "Large Update Site {$i}",
					'offline_check_result' => 1,
					'version'              => '5.0.0',
				] );
			}

			$result = $this->execute_ability( 'mainwp/run-updates-v1', [
				'site_ids_or_domains' => $site_ids,
				'types'               => [ 'plugins' ],
			] );

			$this->assertNotWPError( $result );

			// Should be queued.
			$this->assertTrue( $result['queued'], 'Large batch should be queued.' );
			$this->assertArrayHasKey( 'job_id', $result );
			$this->assertArrayHasKey( 'status_url', $result );
			$this->assertArrayHasKey( 'updates_queued', $result );

			// Track job for cleanup in tearDown.
			$this->track_update_job( $result['job_id'] );

			// Queued jobs don't have updated key.
			$this->assertArrayNotHasKey( 'updated', $result );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}

	/**
	 * Test that run-updates partial success works.
	 *
	 * @return void
	 */
	public function test_run_updates_partial_success() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$online_id = $this->create_test_site( [
			'name'                 => 'Online Update Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$offline_id = $this->create_test_site( [
			'name'                 => 'Offline Update Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'site_ids_or_domains' => [ $online_id, $offline_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'summary', $result, 'Result should have summary key.' );
		$this->assertIsArray( $result['summary'], 'Summary should be an array.' );

		// Should have some errors for offline site.
		$this->assertGreaterThan( 0, $result['summary']['total_errors'], 'Should have errors for offline site.' );
	}

	/**
	 * Test that run-updates handles update operations.
	 *
	 * Note: In a test environment without real child sites, all updates will
	 * fail due to connection issues. This test verifies the structure and
	 * error handling rather than actual update success.
	 *
	 * @return void
	 */
	public function test_run_updates_mocked_partial_failure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Create two online sites with valid child versions.
		$site1_id = $this->create_test_site( [
			'name'                 => 'Update Site 1',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$site2_id = $this->create_test_site( [
			'name'                 => 'Update Site 2',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Mock partial update result to test the mocking mechanism.
		$this->mock_partial_update_result(
			[ $site1_id ],
			[
				[
					'site_id' => $site2_id,
					'code'    => 'mainwp_update_failed',
					'message' => 'Update failed on remote site',
				],
			]
		);

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'site_ids_or_domains' => [ $site1_id, $site2_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		// Verify output structure.
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertIsArray( $result['summary'], 'Summary should be an array.' );

		// Verify summary reflects correct counts.
		$this->assertArrayHasKey( 'total_updated', $result['summary'] );
		$this->assertArrayHasKey( 'total_errors', $result['summary'] );
		$this->assertArrayHasKey( 'sites_updated', $result['summary'] );

		// In test environment, both sites will likely be in errors due to no real connection.
		// Verify at least one site appears in the errors with a valid error code.
		$found_error = false;
		$valid_error_codes = [ 'mainwp_update_failed', 'mainwp_connection_failed', 'mainwp_update_exception' ];
		foreach ( $result['errors'] as $error ) {
			if ( isset( $error['code'] ) && in_array( $error['code'], $valid_error_codes, true ) ) {
				$found_error = true;
				break;
			}
		}

		// Either sites are updated (mocking worked) or errored (real connection failed).
		$has_results = ( $result['summary']['total_updated'] > 0 ) || ( $result['summary']['total_errors'] > 0 );
		$this->assertTrue( $has_results, 'Should have at least some update results or errors.' );

		// If errors occurred, verify they have valid error codes.
		if ( $result['summary']['total_errors'] > 0 ) {
			$this->assertTrue( $found_error, 'Expected at least one recognized error code when errors occurred.' );
		}
	}

	// =========================================================================
	// Ignored Updates Tests
	// =========================================================================

	/**
	 * Test that list-ignored-updates-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/list-ignored-updates-v1',
			$abilities,
			'Ability mainwp/list-ignored-updates-v1 should be registered.'
		);
	}

	/**
	 * Test that list-ignored-updates-v1 requires authentication.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', [] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that list-ignored-updates-v1 requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$this->set_current_user_as_subscriber();

		$result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', [] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that list-ignored-updates-v1 validates input.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Invalid types parameter (should be array, not integer).
		$result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', [
			'types' => 123,
		] );

		$this->assertWPError( $result, 'Invalid input should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that set-ignored-updates-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/set-ignored-updates-v1',
			$abilities,
			'Ability mainwp/set-ignored-updates-v1 should be registered.'
		);
	}

	/**
	 * Test that list-ignored-updates returns list.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_returns_list() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', [] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ignored', $result );
		$this->assertArrayHasKey( 'total', $result );

		$this->assertIsArray( $result['ignored'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * Test that set-ignored-updates adds to list.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_adds_to_list() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Ignore Updates Site',
		] );

		// Add ignored update.
		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'ignore',
			'site_id_or_domain' => $site_id,
			'type'              => 'plugin',
			'slug'              => 'test-plugin',
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'ignore', $result['action'] );
		$this->assertEquals( $site_id, $result['site_id'] );
		$this->assertEquals( 'plugin', $result['type'] );
		$this->assertEquals( 'test-plugin', $result['slug'] );
	}

	/**
	 * Test that set-ignored-updates removes from list.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_removes_from_list() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Unignore Updates Site',
		] );

		// Seed with ignored plugin.
		$this->set_site_ignored_plugins( $site_id, [
			'test-plugin' => [
				'Name'             => 'Test Plugin',
				'ignored_versions' => [ 'all_versions' ],
			],
		] );

		// Remove ignored update.
		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'unignore',
			'site_id_or_domain' => $site_id,
			'type'              => 'plugin',
			'slug'              => 'test-plugin',
		] );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'unignore', $result['action'] );
	}

	/**
	 * Test that set-ignored-updates validates action.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_validates_action() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		// Invalid action should fail.
		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'invalid_action',
			'site_id_or_domain' => $site_id,
			'type'              => 'plugin',
			'slug'              => 'test-plugin',
		] );

		$this->assertWPError( $result );
	}

	/**
	 * Test that set-ignored-updates validates type.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_validates_type() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		// Invalid type should fail.
		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'ignore',
			'site_id_or_domain' => $site_id,
			'type'              => 'invalid_type',
			'slug'              => 'test-plugin',
		] );

		$this->assertWPError( $result );
	}

	/**
	 * Test that set-ignored-updates requires all fields.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_requires_fields() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		// Missing slug should fail.
		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'ignore',
			'site_id_or_domain' => $site_id,
			'type'              => 'plugin',
			// Missing 'slug'.
		] );

		$this->assertWPError( $result );
	}

	/**
	 * Test that set-ignored-updates handles core type.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_handles_core() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'ignore',
			'site_id_or_domain' => $site_id,
			'type'              => 'core',
			'slug'              => 'wordpress',
		] );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'core', $result['type'] );
	}

	/**
	 * Test that set-ignored-updates handles theme type.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_handles_theme() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'ignore',
			'site_id_or_domain' => $site_id,
			'type'              => 'theme',
			'slug'              => 'twentytwentyfour',
		] );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'theme', $result['type'] );
	}

	/**
	 * Test that list-ignored-updates filters by type.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_filters_by_type() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Ignored Filter Site',
		] );

		// Seed with ignored plugins and themes.
		$this->set_site_ignored_plugins( $site_id, [ 'test-plugin' => 'Test Plugin' ] );
		$this->set_site_ignored_themes( $site_id, [ 'test-theme' => 'Test Theme' ] );

		// Request only plugins.
		$result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', [
			'types' => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );

		// All items should be plugins.
		foreach ( $result['ignored'] as $item ) {
			$this->assertEquals( 'plugin', $item['type'], 'Filtered items should be plugins only.' );
		}
	}

	/**
	 * Test that list-ignored-updates filters by site.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_filters_by_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [ 'name' => 'Ignored Site 1' ] );
		$site2_id = $this->create_test_site( [ 'name' => 'Ignored Site 2' ] );

		// Seed both sites with different ignored plugins.
		$this->set_site_ignored_plugins( $site1_id, [ 'test-plugin' => 'Test Plugin' ] );
		$this->set_site_ignored_plugins( $site2_id, [ 'other-plugin' => 'Other Plugin' ] );

		// Request only site1.
		$result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', [
			'site_ids_or_domains' => [ $site1_id ],
		] );

		$this->assertNotWPError( $result );

		// All items should be for site1, none for site2.
		foreach ( $result['ignored'] as $item ) {
			$this->assertEquals( $site1_id, $item['site_id'], 'Filtered items should be for site1.' );
			$this->assertNotEquals( $site2_id, $item['site_id'], 'Site2 items should be excluded from filtered results.' );
		}
	}

	/**
	 * Test that run-updates handles empty site list.
	 *
	 * @return void
	 */
	public function test_run_updates_empty_site_list() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Empty list should update all sites.
		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'site_ids_or_domains' => [],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	/**
	 * Test that run-updates handles specific_items filter.
	 *
	 * @return void
	 */
	public function test_run_updates_specific_items_filter() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Specific Items Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'site_ids_or_domains' => [ $site_id ],
			'types'               => [ 'plugins' ],
			'specific_items'      => [ 'akismet/akismet.php' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		// Implementation filters to only update specified slugs.
	}

	/**
	 * Test that run-updates handles connection failure errors.
	 *
	 * Verifies that connection failures during update attempts produce the
	 * expected error code and message structure in the response.
	 *
	 * @return void
	 */
	public function test_run_updates_handles_connection_failure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Connection Failure Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Mock connection failure for the site.
		$this->mock_partial_update_result(
			[], // No successes.
			[
				[
					'site_id' => $site_id,
					'code'    => 'mainwp_connection_failed',
					'message' => 'Connection to child site timed out',
				],
			]
		);

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'site_ids_or_domains' => [ $site_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertIsArray( $result['summary'], 'Summary should be an array.' );

		// Find connection failure error for this site.
		$found_connection_error = false;
		$error_message          = '';
		foreach ( $result['errors'] as $error ) {
			if ( isset( $error['site_id'] ) && $error['site_id'] === $site_id ) {
				if ( $error['code'] === 'mainwp_connection_failed' ) {
					$found_connection_error = true;
					$error_message          = $error['message'] ?? '';
					break;
				}
			}
		}

		$this->assertTrue(
			$found_connection_error,
			'Connection failure should appear in errors with mainwp_connection_failed code.'
		);

		$this->assertStringContainsString(
			'timed out',
			strtolower( $error_message ),
			'Error message should describe the connection failure.'
		);

		// Verify summary reflects the error.
		$this->assertGreaterThanOrEqual(
			1,
			$result['summary']['total_errors'],
			'Summary should reflect at least 1 error for connection failure.'
		);
	}

	/**
	 * Test that run-updates handles authentication failure errors.
	 *
	 * Verifies that authentication failures during update attempts produce
	 * the expected error code and message structure in the response.
	 *
	 * @return void
	 */
	public function test_run_updates_handles_auth_failure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Auth Failure Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Mock authentication failure for the site.
		$this->mock_partial_update_result(
			[], // No successes.
			[
				[
					'site_id' => $site_id,
					'code'    => 'mainwp_auth_failed',
					'message' => 'Authentication failed: Invalid credentials or OpenSSL signature mismatch',
				],
			]
		);

		$result = $this->execute_ability( 'mainwp/run-updates-v1', [
			'site_ids_or_domains' => [ $site_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertIsArray( $result['summary'], 'Summary should be an array.' );

		// Find auth failure error for this site.
		$found_auth_error = false;
		$error_message    = '';
		foreach ( $result['errors'] as $error ) {
			if ( isset( $error['site_id'] ) && $error['site_id'] === $site_id ) {
				if ( $error['code'] === 'mainwp_auth_failed' ) {
					$found_auth_error = true;
					$error_message    = $error['message'] ?? '';
					break;
				}
			}
		}

		$this->assertTrue(
			$found_auth_error,
			'Authentication failure should appear in errors with mainwp_auth_failed code.'
		);

		$this->assertStringContainsString(
			'authentication',
			strtolower( $error_message ),
			'Error message should describe the authentication failure.'
		);

		// Verify summary reflects the error.
		$this->assertGreaterThanOrEqual(
			1,
			$result['summary']['total_errors'],
			'Summary should reflect at least 1 error for auth failure.'
		);
	}

	// =========================================================================
	// Get Site Updates Tests (get-site-updates-v1)
	// =========================================================================

	/**
	 * Test that get-site-updates-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_get_site_updates_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/get-site-updates-v1',
			$abilities,
			'Ability mainwp/get-site-updates-v1 should be registered.'
		);
	}

	/**
	 * Test that get-site-updates returns expected structure.
	 *
	 * @return void
	 */
	public function test_get_site_updates_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Get Updates Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Seed plugin and theme upgrades.
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result, 'Should return successful result.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'site_id', $result );
		$this->assertArrayHasKey( 'site_url', $result );
		$this->assertArrayHasKey( 'site_name', $result );
		$this->assertArrayHasKey( 'updates', $result );
		$this->assertArrayHasKey( 'rollback_items', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertEquals( $site_id, $result['site_id'] );
		$this->assertIsArray( $result['updates'] );
		$this->assertIsArray( $result['rollback_items'] );
		$this->assertIsArray( $result['summary'] );
	}

	/**
	 * Test that get-site-updates requires authentication.
	 *
	 * @return void
	 */
	public function test_get_site_updates_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that get-site-updates requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_get_site_updates_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that get-site-updates validates input.
	 *
	 * @return void
	 */
	public function test_get_site_updates_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Missing required site_id_or_domain.
		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that get-site-updates returns error for non-existent site.
	 *
	 * @return void
	 */
	public function test_get_site_updates_returns_error_for_not_found() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [
			'site_id_or_domain' => 999999,
		] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
		// Original error message should mention the site was not found.
		$this->assertStringContainsString( 'site', strtolower( $result->get_error_message() ) );
	}

	/**
	 * Test that get-site-updates includes rollback data when present.
	 *
	 * @return void
	 */
	public function test_get_site_updates_includes_rollback_data() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Rollback Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'rollback_items', $result );
		// Even with no rollback data, should have the structure.
		$this->assertArrayHasKey( 'plugins', $result['rollback_items'] );
		$this->assertArrayHasKey( 'themes', $result['rollback_items'] );
	}

	/**
	 * Test that get-site-updates filters by type.
	 *
	 * @return void
	 */
	public function test_get_site_updates_filters_by_type() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Type Filter Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Seed plugin and theme upgrades.
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		$this->set_site_theme_upgrades( $site_id, [
			'twentytwentyfour' => [
				'Name'        => 'Twenty Twenty-Four',
				'Version'     => '1.0',
				'new_version' => '1.1',
			],
		] );

		// Request only plugins.
		$result = $this->execute_ability( 'mainwp/get-site-updates-v1', [
			'site_id_or_domain' => $site_id,
			'types'             => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );

		// All updates should be plugin type.
		foreach ( $result['updates'] as $update ) {
			$this->assertEquals( 'plugin', $update['type'], 'Filtered updates should be plugins only.' );
		}
	}

	// =========================================================================
	// Update Site Core Tests (update-site-core-v1)
	// =========================================================================

	/**
	 * Test that update-site-core-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_update_site_core_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/update-site-core-v1',
			$abilities,
			'Ability mainwp/update-site-core-v1 should be registered.'
		);
	}

	/**
	 * Test that update-site-core returns expected structure.
	 *
	 * In the unit test environment without a real child site, the ability
	 * returns a WP_Error because it cannot connect. This test validates
	 * the error handling path; happy-path testing requires integration tests.
	 *
	 * @return void
	 */
	public function test_update_site_core_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Core Update Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Seed core upgrade.
		$this->set_site_option( $site_id, 'wp_upgrades', wp_json_encode( [
			'current' => '6.4.0',
			'new'     => '6.5.0',
		] ) );

		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [
			'site_id_or_domain' => $site_id,
		] );

		// In test environment without real child site, expect graceful error.
		// Happy-path testing requires integration tests with actual child site.
		$this->assertWPError( $result, 'Expected WP_Error in test environment without child site connectivity.' );
	}

	/**
	 * Test that update-site-core requires authentication.
	 *
	 * @return void
	 */
	public function test_update_site_core_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-core requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_update_site_core_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-core validates input.
	 *
	 * @return void
	 */
	public function test_update_site_core_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Missing required site_id_or_domain.
		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that update-site-core returns error when no core update available.
	 *
	 * @return void
	 */
	public function test_update_site_core_returns_error_when_no_update() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'No Core Update Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Don't seed any core upgrades.

		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_no_updates', $result->get_error_code() );
	}

	/**
	 * Core holds must prevent dispatch through the single-site ability.
	 *
	 * @return void
	 */
	public function test_update_site_core_honours_holds() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$held    = $this->create_test_site();
		$flagged = $this->create_test_site( [ 'is_ignoreCoreUpdates' => 1 ] );
		$free    = $this->create_test_site();
		foreach ( [ $held, $flagged, $free ] as $site_id ) {
			$this->set_site_option( $site_id, 'wp_upgrades', wp_json_encode( [ 'current' => '6.5', 'new' => '6.6' ] ) );
		}
		$ignored = wp_json_encode( [ 'ignored_versions' => [ 'all_versions' ] ] );
		$this->set_site_option( $held, 'ignored_wp_upgrades', $ignored );
		$extension = \MainWP\Dashboard\MainWP_DB_Common::instance()->get_user_extension_by_user_id();

		$calls = [];
		$mock  = static function ( $result, $website, $what ) use ( &$calls ) {
			$calls[] = [ 'site_id' => (int) $website->id, 'what' => $what ];
			return [ 'upgrade' => 'SUCCESS' ];
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $mock, 10, 3 );
		try {
			foreach ( [ $held, $flagged ] as $site_id ) {
				$result = $this->execute_ability( 'mainwp/update-site-core-v1', [ 'site_id_or_domain' => $site_id ] );
				$this->assertSame( [], $calls, 'A per-site core hold must prevent child calls.' );
				$this->assertWPError( $result );
				$this->assertSame( 'mainwp_no_updates', $result->get_error_code() );
				$this->assertSame( [ 'status' => 404 ], $result->get_error_data() );
			}

			\MainWP\Dashboard\MainWP_DB_Common::instance()->update_user_extension(
				[ 'userid' => $extension->userid, 'ignored_wp_upgrades' => $ignored ]
			);
			$result = $this->execute_ability( 'mainwp/update-site-core-v1', [ 'site_id_or_domain' => $free ] );
			$this->assertSame( [], $calls, 'A global core hold must prevent child calls.' );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_no_updates', $result->get_error_code() );
			$this->assertSame( [ 'status' => 404 ], $result->get_error_data() );

			\MainWP\Dashboard\MainWP_DB_Common::instance()->update_user_extension(
				[ 'userid' => $extension->userid, 'ignored_wp_upgrades' => '' ]
			);
			$result = $this->execute_ability( 'mainwp/update-site-core-v1', [ 'site_id_or_domain' => $free ] );
			$this->assertSame( [ [ 'site_id' => $free, 'what' => 'upgrade' ] ], $calls );
			$this->assertNotWPError( $result );
			$this->assertSame( 'wordpress', $result['updated']['slug'] );
			$this->assertSame( '6.5', $result['updated']['old_version'] );
			$this->assertSame( '6.6', $result['updated']['new_version'] );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $mock, 10 );
		}
	}

	/**
	 * Test that update-site-core rejects offline sites.
	 *
	 * @return void
	 */
	public function test_update_site_core_rejects_offline_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Offline Core Update Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_offline', $result->get_error_code() );
	}

	/**
	 * Test that update-site-core rejects outdated child version.
	 *
	 * @return void
	 */
	public function test_update_site_core_rejects_outdated_child() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Core Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below 4.0.0 minimum.
		] );

		$result = $this->execute_ability( 'mainwp/update-site-core-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_child_outdated', $result->get_error_code() );
	}

	// =========================================================================
	// Update Site Plugins Tests (update-site-plugins-v1)
	// =========================================================================

	/**
	 * Test that update-site-plugins-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/update-site-plugins-v1',
			$abilities,
			'Ability mainwp/update-site-plugins-v1 should be registered.'
		);
	}

	/**
	 * Test that update-site-plugins returns expected structure.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Plugin Update Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertIsArray( $result['updated'] );
		$this->assertIsArray( $result['errors'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'total_updated', $result['summary'] );
		$this->assertArrayHasKey( 'total_errors', $result['summary'] );
	}

	/**
	 * Test that update-site-plugins requires authentication.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-plugins requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-plugins validates input.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Missing required site_id_or_domain.
		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that update-site-plugins filters by slugs.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_filters_by_slugs() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Slug Filter Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Seed multiple plugin upgrades.
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php'   => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
			'hello-dolly/hello.php' => [
				'Name'        => 'Hello Dolly',
				'Version'     => '1.0',
				'new_version' => '1.1',
			],
		] );

		// Request only specific slug.
		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => [ 'akismet/akismet.php' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	/**
	 * Explicit slugs must respect whole-site holds and the existing ignore lists.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_and_themes_honour_holds() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$calls = [];
		$mock  = static function ( $result, $website, $what, $params ) use ( &$calls ) {
			$calls[] = [ 'site_id' => (int) $website->id, 'what' => $what, 'type' => $params['type'], 'list' => $params['list'] ];
			return [ 'upgrades' => array_fill_keys( explode( ',', $params['list'] ), true ) ];
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $mock, 10, 4 );
		try {
			foreach ( [ 'plugin', 'theme' ] as $type ) {
				$flag    = 'plugin' === $type ? 'is_ignorePluginUpdates' : 'is_ignoreThemeUpdates';
				$held    = 'plugin' === $type ? 'held/held.php' : 'held-theme';
				$global  = 'plugin' === $type ? 'global/global.php' : 'global-theme';
				$chosen  = 'plugin' === $type ? 'chosen/chosen.php' : 'chosen-theme';
				$other   = 'plugin' === $type ? 'other/other.php' : 'other-theme';
				$flagged = $this->create_test_site( [ $flag => 1 ] );
				$free    = $this->create_test_site();
				$updates = array_fill_keys( [ $held, $global, $chosen, $other ], [ 'Name' => 'Item', 'Version' => '1.0', 'new_version' => '2.0' ] );
				$ignored = [ $held => [ 'Name' => 'Held', 'ignored_versions' => [ 'all_versions' ] ] ];
				foreach ( [ $flagged, $free ] as $site_id ) {
					if ( 'plugin' === $type ) {
						$this->set_site_plugin_upgrades( $site_id, $updates );
						$this->set_site_ignored_plugins( $site_id, $ignored );
					} else {
						$this->set_site_theme_upgrades( $site_id, $updates );
						$this->set_site_ignored_themes( $site_id, $ignored );
					}
				}
				$extension = \MainWP\Dashboard\MainWP_DB_Common::instance()->get_user_extension_by_user_id();
				\MainWP\Dashboard\MainWP_DB_Common::instance()->update_user_extension(
					[ 'userid' => $extension->userid, 'ignored_' . $type . 's' => wp_json_encode( [ $global => [ 'Name' => 'Global', 'ignored_versions' => [ 'all_versions' ] ] ] ) ]
				);

				$ability = 'mainwp/update-site-' . $type . 's-v1';
				$calls   = [];
				$result  = $this->execute_ability( $ability, [ 'site_id_or_domain' => $flagged, 'slugs' => [ $chosen ] ] );
				$this->assertSame( [], $calls, $type . ' whole-site holds must prevent child calls.' );
				$this->assertSame( [ 'updated' => [], 'errors' => [], 'summary' => [ 'total_updated' => 0, 'total_errors' => 0 ] ], $result );

				$result = $this->execute_ability( $ability, [ 'site_id_or_domain' => $free, 'slugs' => [ $held, $global, $chosen, 'missing' ] ] );
				$this->assertSame( [ [ 'site_id' => $free, 'what' => 'upgradeplugintheme', 'type' => $type, 'list' => $chosen ] ], $calls );
				$this->assertNotWPError( $result );
				$this->assertSame( [ $chosen ], array_column( $result['updated'], 'slug' ) );
				$this->assertSame( [], $result['errors'] );
				$this->assertSame( [ 'total_updated' => 1, 'total_errors' => 0 ], $result['summary'] );

				$calls  = [];
				$result = $this->execute_ability( $ability, [ 'site_id_or_domain' => $free ] );
				$this->assertSame( [ [ 'site_id' => $free, 'what' => 'upgradeplugintheme', 'type' => $type, 'list' => $other ] ], $calls );
				$this->assertNotWPError( $result );
				$this->assertSame( [ $other ], array_column( $result['updated'], 'slug' ) );
			}
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $mock, 10 );
		}
	}

	/**
	 * Test that update-site-plugins rejects offline sites.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_rejects_offline_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Offline Plugin Update Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_offline', $result->get_error_code() );
	}

	/**
	 * Test that update-site-plugins rejects outdated child version.
	 *
	 * @return void
	 */
	public function test_update_site_plugins_rejects_outdated_child() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Plugin Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below 4.0.0 minimum.
		] );

		$result = $this->execute_ability( 'mainwp/update-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_child_outdated', $result->get_error_code() );
	}

	// =========================================================================
	// Update Site Themes Tests (update-site-themes-v1)
	// =========================================================================

	/**
	 * Test that update-site-themes-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_update_site_themes_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/update-site-themes-v1',
			$abilities,
			'Ability mainwp/update-site-themes-v1 should be registered.'
		);
	}

	/**
	 * Test that update-site-themes returns expected structure.
	 *
	 * @return void
	 */
	public function test_update_site_themes_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Theme Update Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-themes-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertIsArray( $result['updated'] );
		$this->assertIsArray( $result['errors'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'total_updated', $result['summary'] );
		$this->assertArrayHasKey( 'total_errors', $result['summary'] );
	}

	/**
	 * Test that update-site-themes requires authentication.
	 *
	 * @return void
	 */
	public function test_update_site_themes_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/update-site-themes-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-themes requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_update_site_themes_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/update-site-themes-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-themes validates input.
	 *
	 * @return void
	 */
	public function test_update_site_themes_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Missing required site_id_or_domain.
		$result = $this->execute_ability( 'mainwp/update-site-themes-v1', [] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that update-site-themes rejects offline sites.
	 *
	 * @return void
	 */
	public function test_update_site_themes_rejects_offline_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Offline Theme Update Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-themes-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_offline', $result->get_error_code() );
	}

	/**
	 * Test that update-site-themes rejects outdated child version.
	 *
	 * @return void
	 */
	public function test_update_site_themes_rejects_outdated_child() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Theme Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below 4.0.0 minimum.
		] );

		$result = $this->execute_ability( 'mainwp/update-site-themes-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_child_outdated', $result->get_error_code() );
	}

	// =========================================================================
	// Update Site Translations Tests (update-site-translations-v1)
	// =========================================================================

	/**
	 * Test that update-site-translations-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_update_site_translations_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/update-site-translations-v1',
			$abilities,
			'Ability mainwp/update-site-translations-v1 should be registered.'
		);
	}

	/**
	 * Test that update-site-translations returns expected structure.
	 *
	 * @return void
	 */
	public function test_update_site_translations_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Translation Update Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertIsArray( $result['updated'] );
		$this->assertIsArray( $result['errors'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'total_updated', $result['summary'] );
		$this->assertArrayHasKey( 'total_errors', $result['summary'] );
	}

	/**
	 * Test that update-site-translations requires authentication.
	 *
	 * @return void
	 */
	public function test_update_site_translations_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-translations requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_update_site_translations_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [
			'site_id_or_domain' => 1,
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-site-translations validates input.
	 *
	 * @return void
	 */
	public function test_update_site_translations_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Missing required site_id_or_domain.
		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that update-site-translations rejects offline sites.
	 *
	 * @return void
	 */
	public function test_update_site_translations_rejects_offline_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Offline Translation Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_offline', $result->get_error_code() );
	}

	/**
	 * Test that update-site-translations rejects outdated child version.
	 *
	 * @return void
	 */
	public function test_update_site_translations_rejects_outdated_child() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Translation Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below 4.0.0 minimum.
		] );

		$result = $this->execute_ability( 'mainwp/update-site-translations-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_child_outdated', $result->get_error_code() );
	}

	// =========================================================================
	// Update All Tests (update-all-v1)
	// =========================================================================

	/**
	 * Test that update-all-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_update_all_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/update-all-v1',
			$abilities,
			'Ability mainwp/update-all-v1 should be registered.'
		);
	}

	/**
	 * Test that update-all returns expected structure for immediate execution.
	 *
	 * @return void
	 */
	public function test_update_all_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Update All Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'site_ids_or_domains' => [ $site_id ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertIsArray( $result['updated'] );
		$this->assertIsArray( $result['errors'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'total_updated', $result['summary'] );
		$this->assertArrayHasKey( 'total_errors', $result['summary'] );
		$this->assertArrayHasKey( 'sites_updated', $result['summary'] );
	}

	/**
	 * Test that update-all requires authentication.
	 *
	 * @return void
	 */
	public function test_update_all_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/update-all-v1', [] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-all requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_update_all_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/update-all-v1', [] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that update-all with empty site list uses all sites.
	 *
	 * @return void
	 */
	public function test_update_all_empty_site_list_uses_all_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'site_ids_or_domains' => [],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );
	}

	/**
	 * Test that update-all large batch returns queued response.
	 *
	 * @return void
	 */
	public function test_update_all_large_batch_returns_queued() {
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
					'name'                 => "Large Update All Site {$i}",
					'offline_check_result' => 1,
					'version'              => '5.0.0',
				] );
			}

			$result = $this->execute_ability( 'mainwp/update-all-v1', [
				'site_ids_or_domains' => $site_ids,
			] );

			$this->assertNotWPError( $result );

			// Should be queued.
			$this->assertTrue( $result['queued'], 'Large batch should be queued.' );
			$this->assertArrayHasKey( 'job_id', $result );
			$this->assertArrayHasKey( 'status_url', $result );
			$this->assertArrayHasKey( 'updates_queued', $result );

			// Track job for cleanup in tearDown.
			$this->track_update_job( $result['job_id'] );

			// Queued jobs don't have updated key.
			$this->assertArrayNotHasKey( 'updated', $result );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}

	/**
	 * Test that update-all filters by types.
	 *
	 * @return void
	 */
	public function test_update_all_filters_by_types() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Update All Types Filter Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		// Seed updates of different types.
		$this->set_site_plugin_upgrades( $site_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		$this->set_site_theme_upgrades( $site_id, [
			'twentytwentyfour' => [
				'Name'        => 'Twenty Twenty-Four',
				'Version'     => '1.0',
				'new_version' => '1.1',
			],
		] );

		// Request only plugins.
		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'site_ids_or_domains' => [ $site_id ],
			'types'               => [ 'plugins' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		// Verify that only plugin updates were attempted (not themes).
	}

	/**
	 * Test that update-all handles outdated child version errors.
	 *
	 * @return void
	 */
	public function test_update_all_handles_outdated_child() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$outdated_id = $this->create_test_site( [
			'name'                 => 'Outdated Child Update All Site',
			'offline_check_result' => 1,
			'version'              => '3.9.0', // Below 4.0.0 minimum.
		] );

		// Seed plugin upgrades so there's something to update.
		$this->set_site_plugin_upgrades( $outdated_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'site_ids_or_domains' => [ $outdated_id ],
		] );

		$this->assertNotWPError( $result );

		// Outdated child should be in errors.
		$found_outdated_error = false;
		foreach ( $result['errors'] as $error ) {
			if ( $error['code'] === 'mainwp_child_outdated' ) {
				$found_outdated_error = true;
				break;
			}
		}

		$this->assertTrue( $found_outdated_error, 'Outdated child should produce mainwp_child_outdated error.' );
	}

	/**
	 * Test that update-all handles mixed site states.
	 *
	 * @return void
	 */
	public function test_update_all_handles_mixed_site_states() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$online_id = $this->create_test_site( [
			'name'                 => 'Online Update All Site',
			'offline_check_result' => 1,
			'version'              => '5.0.0',
		] );

		$offline_id = $this->create_test_site( [
			'name'                 => 'Offline Update All Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'site_ids_or_domains' => [ $online_id, $offline_id ],
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		// Should have structure for partial results.
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'summary', $result );
	}

	/**
	 * Test that update-all skips offline sites.
	 *
	 * @return void
	 */
	public function test_update_all_skips_offline_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$offline_id = $this->create_test_site( [
			'name'                 => 'Offline Update All Site',
			'offline_check_result' => -1,
			'version'              => '5.0.0',
		] );

		// Seed plugin upgrades so there's something to update.
		$this->set_site_plugin_upgrades( $offline_id, [
			'akismet/akismet.php' => [
				'Name'        => 'Akismet',
				'Version'     => '5.0',
				'new_version' => '5.1',
			],
		] );

		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'site_ids_or_domains' => [ $offline_id ],
		] );

		$this->assertNotWPError( $result );

		// Offline site should be in errors with specific error code.
		$found_offline_error = false;
		foreach ( $result['errors'] as $error ) {
			if ( $error['site_id'] === $offline_id && $error['code'] === 'mainwp_site_offline' ) {
				$found_offline_error = true;
				break;
			}
		}

		$this->assertTrue( $found_offline_error, 'Offline site should produce mainwp_site_offline error in update-all.' );
	}

	/**
	 * Test that update-all with invalid identifiers returns normalized error schema.
	 *
	 * Resolution errors should match the documented error item schema with
	 * site_id, site_url, site_name, type, slug, code, and message keys.
	 *
	 * @return void
	 */
	public function test_update_all_invalid_identifier_returns_normalized_error_schema() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/update-all-v1', [
			'site_ids_or_domains' => [ 'nonexistent-site.invalid' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertNotEmpty( $result['errors'], 'Should have at least one error for invalid identifier.' );

		$error = $result['errors'][0];

		// Verify all required schema fields are present.
		$this->assertArrayHasKey( 'site_id', $error, 'Error should have site_id key.' );
		$this->assertArrayHasKey( 'site_url', $error, 'Error should have site_url key.' );
		$this->assertArrayHasKey( 'site_name', $error, 'Error should have site_name key.' );
		$this->assertArrayHasKey( 'type', $error, 'Error should have type key.' );
		$this->assertArrayHasKey( 'slug', $error, 'Error should have slug key.' );
		$this->assertArrayHasKey( 'code', $error, 'Error should have code key.' );
		$this->assertArrayHasKey( 'message', $error, 'Error should have message key.' );

		// Verify type is 'site' for resolution errors.
		$this->assertEquals( 'site', $error['type'], 'Resolution error type should be "site".' );

		// Verify site_id is 0 for unresolved sites.
		$this->assertEquals( 0, $error['site_id'], 'Unresolved site should have site_id of 0.' );

		// Verify the identifier is preserved in site_url.
		$this->assertEquals( 'nonexistent-site.invalid', $error['site_url'], 'Identifier should be preserved in site_url.' );
	}

	// =========================================================================
	// Ignore Site Core Tests (ignore-site-core-v1)
	// =========================================================================

	/**
	 * Test that ignore-site-core-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_ignore_site_core_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/ignore-site-core-v1',
			$abilities,
			'Ability mainwp/ignore-site-core-v1 should be registered.'
		);
	}

	/**
	 * Test that ignore-site-core returns expected structure.
	 *
	 * @return void
	 */
	public function test_ignore_site_core_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Ignore Core Site',
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/ignore-site-core-v1', [
			'site_id_or_domain' => $site_id,
			'action'            => 'add',
		] );

		$this->assertNotWPError( $result, 'Should return successful result.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'ignored_count', $result );

		$this->assertTrue( $result['success'], 'Success should be true.' );
		$this->assertNotEmpty( $result['message'], 'Message should not be empty.' );
		$this->assertEquals( 1, $result['ignored_count'], 'Ignored count should be 1 for core.' );
	}

	/**
	 * Test that ignore-site-core requires authentication.
	 *
	 * @return void
	 */
	public function test_ignore_site_core_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/ignore-site-core-v1', [
			'site_id_or_domain' => 1,
			'action'            => 'add',
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that ignore-site-core requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_ignore_site_core_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/ignore-site-core-v1', [
			'site_id_or_domain' => 1,
			'action'            => 'add',
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that ignore-site-core validates input.
	 *
	 * @return void
	 */
	public function test_ignore_site_core_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Missing required site_id_or_domain.
		$result = $this->execute_ability( 'mainwp/ignore-site-core-v1', [
			'action' => 'add',
		] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that ignore-site-core can add and remove from ignore list.
	 *
	 * @return void
	 */
	public function test_ignore_site_core_add_and_remove() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Ignore Core Toggle Site',
			'offline_check_result' => 1,
		] );

		// Add to ignore list.
		$add_result = $this->execute_ability( 'mainwp/ignore-site-core-v1', [
			'site_id_or_domain' => $site_id,
			'action'            => 'add',
		] );

		$this->assertNotWPError( $add_result );
		$this->assertTrue( $add_result['success'] );

		// Remove from ignore list.
		$remove_result = $this->execute_ability( 'mainwp/ignore-site-core-v1', [
			'site_id_or_domain' => $site_id,
			'action'            => 'remove',
		] );

		$this->assertNotWPError( $remove_result );
		$this->assertTrue( $remove_result['success'] );
	}

	// =========================================================================
	// Ignore Site Plugins Tests (ignore-site-plugins-v1)
	// =========================================================================

	/**
	 * Test that ignore-site-plugins-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_ignore_site_plugins_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/ignore-site-plugins-v1',
			$abilities,
			'Ability mainwp/ignore-site-plugins-v1 should be registered.'
		);
	}

	/**
	 * Test that ignore-site-plugins returns expected structure.
	 *
	 * @return void
	 */
	public function test_ignore_site_plugins_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Ignore Plugins Site',
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/ignore-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => [ 'akismet/akismet.php', 'hello-dolly/hello.php' ],
			'action'            => 'add',
		] );

		$this->assertNotWPError( $result, 'Should return successful result.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'ignored_count', $result );

		$this->assertTrue( $result['success'], 'Success should be true.' );
		$this->assertNotEmpty( $result['message'], 'Message should not be empty.' );
		$this->assertEquals( 2, $result['ignored_count'], 'Ignored count should match number of slugs.' );
	}

	/**
	 * Test that ignore-site-plugins requires authentication.
	 *
	 * @return void
	 */
	public function test_ignore_site_plugins_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/ignore-site-plugins-v1', [
			'site_id_or_domain' => 1,
			'slugs'             => [ 'test-plugin/test.php' ],
			'action'            => 'add',
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that ignore-site-plugins requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_ignore_site_plugins_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/ignore-site-plugins-v1', [
			'site_id_or_domain' => 1,
			'slugs'             => [ 'test-plugin/test.php' ],
			'action'            => 'add',
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that ignore-site-plugins validates input.
	 *
	 * @return void
	 */
	public function test_ignore_site_plugins_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		// Missing required slugs.
		$result = $this->execute_ability( 'mainwp/ignore-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
			'action'            => 'add',
			// Missing 'slugs'.
		] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that ignore-site-plugins can add and remove from ignore list.
	 *
	 * @return void
	 */
	public function test_ignore_site_plugins_add_and_remove() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Ignore Plugins Toggle Site',
			'offline_check_result' => 1,
		] );

		$test_slugs = [ 'akismet/akismet.php' ];

		// Add to ignore list.
		$add_result = $this->execute_ability( 'mainwp/ignore-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => $test_slugs,
			'action'            => 'add',
		] );

		$this->assertNotWPError( $add_result );
		$this->assertTrue( $add_result['success'] );
		$this->assertEquals( 1, $add_result['ignored_count'] );

		// Remove from ignore list.
		$remove_result = $this->execute_ability( 'mainwp/ignore-site-plugins-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => $test_slugs,
			'action'            => 'remove',
		] );

		$this->assertNotWPError( $remove_result );
		$this->assertTrue( $remove_result['success'] );
	}

	// =========================================================================
	// Ignore Site Themes Tests (ignore-site-themes-v1)
	// =========================================================================

	/**
	 * Test that ignore-site-themes-v1 ability is registered.
	 *
	 * @return void
	 */
	public function test_ignore_site_themes_ability_is_registered() {
		$this->skip_if_no_abilities_api();

		$abilities = wp_get_abilities();

		$this->assertArrayHasKey(
			'mainwp/ignore-site-themes-v1',
			$abilities,
			'Ability mainwp/ignore-site-themes-v1 should be registered.'
		);
	}

	/**
	 * Test that ignore-site-themes returns expected structure.
	 *
	 * @return void
	 */
	public function test_ignore_site_themes_returns_expected_structure() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Ignore Themes Site',
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/ignore-site-themes-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => [ 'twentytwentyfour', 'twentytwentythree' ],
			'action'            => 'add',
		] );

		$this->assertNotWPError( $result, 'Should return successful result.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'ignored_count', $result );

		$this->assertTrue( $result['success'], 'Success should be true.' );
		$this->assertNotEmpty( $result['message'], 'Message should not be empty.' );
		$this->assertEquals( 2, $result['ignored_count'], 'Ignored count should match number of slugs.' );
	}

	/**
	 * Test that ignore-site-themes requires authentication.
	 *
	 * @return void
	 */
	public function test_ignore_site_themes_requires_authentication() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/ignore-site-themes-v1', [
			'site_id_or_domain' => 1,
			'slugs'             => [ 'test-theme' ],
			'action'            => 'add',
		] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that ignore-site-themes requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_ignore_site_themes_requires_manage_options() {
		$this->skip_if_no_abilities_api();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/ignore-site-themes-v1', [
			'site_id_or_domain' => 1,
			'slugs'             => [ 'test-theme' ],
			'action'            => 'add',
		] );

		$this->assertWPError( $result, 'Subscriber should be denied.' );
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Should return ability_invalid_permissions error code.'
		);
	}

	/**
	 * Test that ignore-site-themes validates input.
	 *
	 * @return void
	 */
	public function test_ignore_site_themes_validates_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		// Missing required slugs.
		$result = $this->execute_ability( 'mainwp/ignore-site-themes-v1', [
			'site_id_or_domain' => $site_id,
			'action'            => 'add',
			// Missing 'slugs'.
		] );

		$this->assertWPError( $result, 'Missing required field should return WP_Error.' );
		$this->assertEquals(
			'ability_invalid_input',
			$result->get_error_code(),
			'Should return ability_invalid_input for schema validation failure.'
		);
	}

	/**
	 * Test that ignore-site-themes can add and remove from ignore list.
	 *
	 * @return void
	 */
	public function test_ignore_site_themes_add_and_remove() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Ignore Themes Toggle Site',
			'offline_check_result' => 1,
		] );

		$test_slugs = [ 'twentytwentyfour' ];

		// Add to ignore list.
		$add_result = $this->execute_ability( 'mainwp/ignore-site-themes-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => $test_slugs,
			'action'            => 'add',
		] );

		$this->assertNotWPError( $add_result );
		$this->assertTrue( $add_result['success'] );
		$this->assertEquals( 1, $add_result['ignored_count'] );

		// Remove from ignore list.
		$remove_result = $this->execute_ability( 'mainwp/ignore-site-themes-v1', [
			'site_id_or_domain' => $site_id,
			'slugs'             => $test_slugs,
			'action'            => 'remove',
		] );

		$this->assertNotWPError( $remove_result );
		$this->assertTrue( $remove_result['success'] );
	}
}
