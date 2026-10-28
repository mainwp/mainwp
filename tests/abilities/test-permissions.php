<?php
/**
 * MainWP Abilities Permission Tests
 *
 * Tests for permission callbacks and ACL enforcement.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class MainWP_Abilities_Permission_Test
 *
 * Tests for permission callbacks, ACL enforcement, and REST API key authentication.
 */
class MainWP_Abilities_Permission_Test extends MainWP_Abilities_Test_Case { //phpcs:ignore -- NOSONAR - multi methods.

	/**
	 * Test that permission is denied for subscriber (no manage_options).
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_permission_denied_for_subscriber() {
		$this->skip_if_no_abilities_api();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		// Create subscriber user.
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/list-sites-v1', [] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that permission is granted for administrator.
	 *
	 * @return void
	 */
	public function test_permission_granted_for_administrator() {
		$this->skip_if_no_abilities_api();

		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/list-sites-v1', [] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	/**
	 * Test that per-site access is denied when ACL check fails.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original ACL error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_per_site_access_denied() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$site_id = $this->create_test_site( [
			'name' => 'ACL Denied Site',
		] );

		// Mock site access denial.
		add_filter(
			'mainwp_check_site_access',
			function () {
				return false;
			}
		);

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that per-site access is granted when ACL check passes.
	 *
	 * @return void
	 */
	public function test_per_site_access_granted() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'ACL Granted Site',
		] );

		// Don't mock ACL - should allow by default.
		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertEquals( $site_id, $result['id'] );
	}

	/**
	 * Test that batch access check filters denied sites.
	 *
	 * @return void
	 */
	public function test_batch_access_check_filters_denied_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [ 'name' => 'Allowed Site 1' ] );
		$site2_id = $this->create_test_site( [ 'name' => 'Denied Site 2' ] );
		$site3_id = $this->create_test_site( [ 'name' => 'Allowed Site 3' ] );

		// Mock ACL to deny site2.
		add_filter(
			'mainwp_check_site_access',
			function ( $access, $site_id ) use ( $site2_id ) {
				if ( (int) $site_id === $site2_id ) {
					return false;
				}
				return $access;
			},
			10,
			2
		);

		// Call utility directly.
		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_batch_site_access(
			[ $site1_id, $site2_id, $site3_id ],
			[]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
		$this->assertArrayHasKey( 'denied', $result );

		// Should have 2 allowed.
		$allowed_ids = array_map(
			function ( $site ) {
				return (int) $site->id;
			},
			$result['allowed']
		);
		$this->assertContains( $site1_id, $allowed_ids );
		$this->assertContains( $site3_id, $allowed_ids );

		// Should have 1 denied.
		$this->assertCount( 1, $result['denied'] );
		$this->assertEquals( 'mainwp_access_denied', $result['denied'][0]['code'] );
	}

	/**
	 * Test that batch sync excludes ACL-denied sites.
	 *
	 * @return void
	 */
	public function test_batch_sync_excludes_acl_denied_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [
			'name'                 => 'Sync Allowed Site',
			'offline_check_result' => 1,
		] );

		$site2_id = $this->create_test_site( [
			'name'                 => 'Sync Denied Site',
			'offline_check_result' => 1,
		] );

		// Mock ACL to deny site2.
		add_filter(
			'mainwp_check_site_access',
			function ( $access, $site_id ) use ( $site2_id ) {
				if ( (int) $site_id === $site2_id ) {
					return false;
				}
				return $access;
			},
			10,
			2
		);

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => [ $site1_id, $site2_id ],
		] );

		$this->assertNotWPError( $result );

		// Site2 should be in errors with permission denied.
		// Errors use 'identifier' key per the documented schema.
		$denied_ids = [];
		foreach ( $result['errors'] as $error ) {
			if ( $error['code'] === 'mainwp_access_denied' || $error['code'] === 'mainwp_permission_denied' ) {
				// Normalize identifier to int when it's numeric for comparison.
				$error_id     = is_numeric( $error['identifier'] ) ? (int) $error['identifier'] : $error['identifier'];
				$denied_ids[] = $error_id;
			}
		}

		$this->assertContains( $site2_id, $denied_ids, 'ACL-denied site should be in errors.' );
	}

	/**
	 * Test that unauthenticated request is denied.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_unauthenticated_request_denied() {
		$this->skip_if_no_abilities_api();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/list-sites-v1', [] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that permission callback returns proper error code and message.
	 *
	 * Per Abilities API documentation, permission errors are wrapped with
	 * 'ability_invalid_permissions' error code. The error message should
	 * contain meaningful information about the permission failure.
	 *
	 * Note: The Abilities API may or may not preserve error_data from the
	 * underlying WP_Error when wrapping permission errors.
	 *
	 * @return void
	 */
	public function test_permission_error_has_status() {
		$this->skip_if_no_abilities_api();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		// No user logged in.
		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/list-sites-v1', [] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );

		// Per Abilities API docs, permission errors use 'ability_invalid_permissions' code.
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Permission error should have ability_invalid_permissions code.'
		);

		// Verify error has a meaningful message.
		$error_message = $result->get_error_message();
		$this->assertNotEmpty( $error_message, 'Permission error should have a message.' );

		// If error_data is preserved by the Abilities API, verify it has proper status.
		$error_data = $result->get_error_data();
		if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
			$this->assertContains(
				$error_data['status'],
				[ 401, 403 ],
				'HTTP status should be 401 (Unauthorized) or 403 (Forbidden).'
			);
		}
	}

	/**
	 * Test that editor role (without manage_options) is denied access.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_permission_checks_manage_options_capability() {
		$this->skip_if_no_abilities_api();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		// Create editor.
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		// Editor doesn't have manage_options.
		$result = $this->execute_ability( 'mainwp/list-sites-v1', [] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that site resolution error is returned before ACL check.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_site_not_found_returned_before_acl() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => 999999,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		// Original error message should mention the site was not found.
		$this->assertStringContainsString( 'site', strtolower( $result->get_error_message() ) );
	}

	/**
	 * Test that permission check function returns correct types.
	 *
	 * @return void
	 */
	public function test_check_rest_api_permission_return_types() {
		$this->skip_if_no_abilities_api();

		// Unauthenticated should return WP_Error.
		wp_set_current_user( 0 );
		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission( null );
		$this->assertWPError( $result );

		// Admin should return true.
		$this->set_current_user_as_admin();
		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission( null );
		$this->assertTrue( $result );
	}

	/**
	 * Test that check_site_access validates site parameter.
	 *
	 * @return void
	 */
	public function test_check_site_access_validates_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		// With valid site.
		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_site_access( $site_id, null );
		$this->assertTrue( $result );

		// With site object.
		$site = $this->get_test_site( $site_id );
		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_site_access( $site, null );
		$this->assertTrue( $result );
	}

	/**
	 * Test that can_access_site helper returns boolean.
	 *
	 * @return void
	 */
	public function test_can_access_site_returns_boolean() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::can_access_site( $site_id, null );
		$this->assertIsBool( $result );
		$this->assertTrue( $result );
	}

	/**
	 * Test that permission denied for write operations without proper access.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_write_operation_permission_denied() {
		$this->skip_if_no_abilities_api();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'ignore',
			'site_id_or_domain' => 1,
			'type'              => 'plugin',
			'slug'              => 'test-plugin',
		] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that multiple permission errors accumulate in batch operations.
	 *
	 * @return void
	 */
	public function test_batch_accumulates_permission_errors() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [ 'name' => 'Batch Test 1' ] );
		$site2_id = $this->create_test_site( [ 'name' => 'Batch Test 2' ] );
		$site3_id = $this->create_test_site( [ 'name' => 'Batch Test 3' ] );

		// Mock to deny sites 1 and 3.
		add_filter(
			'mainwp_check_site_access',
			function ( $access, $site_id ) use ( $site1_id, $site3_id ) {
				if ( in_array( (int) $site_id, [ $site1_id, $site3_id ], true ) ) {
					return false;
				}
				return $access;
			},
			10,
			2
		);

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_batch_site_access(
			[ $site1_id, $site2_id, $site3_id ],
			[]
		);

		$this->assertCount( 1, $result['allowed'], 'Only 1 site should be allowed.' );
		$this->assertCount( 2, $result['denied'], '2 sites should be denied.' );
	}

	/**
	 * Test that nonexistent sites in batch are returned as errors.
	 *
	 * @return void
	 */
	public function test_batch_nonexistent_sites_are_errors() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$valid_id = $this->create_test_site();

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_batch_site_access(
			[ $valid_id, 999999, 888888 ],
			[]
		);

		$this->assertCount( 1, $result['allowed'] );
		$this->assertCount( 2, $result['denied'] );

		// Both denied should have not_found code.
		foreach ( $result['denied'] as $denied ) {
			$this->assertEquals( 'mainwp_site_not_found', $denied['code'] );
		}
	}

	// =========================================================================
	// REST API Key Authentication Tests
	// =========================================================================

	/**
	 * Test that REST API key user with manage_options is granted access.
	 *
	 * @return void
	 */
	public function test_rest_api_key_user_with_manage_options_granted() {
		$this->skip_if_no_abilities_api();

		// Create admin user.
		$admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// Mock the REST authentication to return a valid user.
		$this->mock_rest_api_key_user( $admin_id );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission( null );

		$this->assertTrue( $result, 'REST API key user with manage_options should be granted access.' );

		// Clean up.
		$this->reset_rest_authentication();
	}

	/**
	 * Test that REST API key user without manage_options is denied.
	 *
	 * @return void
	 */
	public function test_rest_api_key_user_without_manage_options_denied() {
		$this->skip_if_no_abilities_api();

		// Create subscriber user (no manage_options).
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		// Mock the REST authentication to return this user.
		$this->mock_rest_api_key_user( $subscriber_id );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission( null );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_permission_denied', $result->get_error_code() );

		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertEquals( 403, $error_data['status'] );

		// Clean up.
		$this->reset_rest_authentication();
	}

	/**
	 * Mock the REST API key authentication by setting the user property on the singleton.
	 *
	 * Uses reflection to directly set the authenticated user on MainWP_REST_Authentication
	 * without requiring actual HTTP requests or database API key records. This enables
	 * testing permission logic for REST API key users without complex integration setup.
	 *
	 * @internal Tests depend on MainWP_REST_Authentication::$user property structure.
	 *           If this property name, type, or structure changes, this method and
	 *           reset_rest_authentication() must be updated accordingly.
	 *
	 * @param int $user_id User ID to set as the authenticated REST API user.
	 * @return void
	 */
	private function mock_rest_api_key_user( int $user_id ): void {
		if ( ! class_exists( 'MainWP_REST_Authentication' ) ) {
			$this->markTestSkipped( 'MainWP_REST_Authentication class not available.' );
		}

		$auth = \MainWP_REST_Authentication::get_instance();

		// Use reflection to set the protected $user property.
		$reflection = new \ReflectionClass( $auth );
		$user_prop  = $reflection->getProperty( 'user' );
		$user_prop->setAccessible( true );

		// Create a mock user object similar to what REST auth would store.
		$mock_user          = new \stdClass();
		$mock_user->user_id = $user_id;

		$user_prop->setValue( $auth, $mock_user );
	}

	/**
	 * Reset the REST authentication singleton's user property.
	 *
	 * @return void
	 */
	private function reset_rest_authentication(): void {
		if ( ! class_exists( 'MainWP_REST_Authentication' ) ) {
			return;
		}

		$auth = \MainWP_REST_Authentication::get_instance();

		$reflection = new \ReflectionClass( $auth );
		$user_prop  = $reflection->getProperty( 'user' );
		$user_prop->setAccessible( true );
		$user_prop->setValue( $auth, null );
	}
}
