<?php
/**
 * MainWP Get Site Ability Tests
 *
 * Tests for the mainwp/get-site-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class MainWP_Get_Site_Ability_Test
 *
 * Tests for the mainwp/get-site-v1 ability.
 *
 * @group abilities
 * @group abilities-sites
 */
class MainWP_Get_Site_Ability_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Test that get-site returns site by ID.
	 *
	 * @return void
	 */
	public function test_get_site_by_id_returns_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Get Site Test',
			'url'  => 'https://test-getsite.example.com/',
		] );

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertEquals( $site_id, $result['id'] );
		$this->assertEquals( 'Get Site Test', $result['name'] );
		$this->assertEquals( 'https://test-getsite.example.com/', $result['url'] );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'client_id', $result );
	}

	/**
	 * Test that get-site returns site by domain.
	 *
	 * @return void
	 */
	public function test_get_site_by_domain_returns_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Domain Test Site',
			'url'  => 'https://test-domaintest.example.com/',
		] );

		// Use domain without protocol.
		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => 'test-domaintest.example.com',
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertEquals( $site_id, $result['id'] );
		$this->assertEquals( 'Domain Test Site', $result['name'] );
	}

	/**
	 * Test that get-site returns error for non-existent site.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_get_site_not_found_returns_error() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => 999999,
		] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
		// Original error message should mention the site was not found.
		$this->assertStringContainsString( 'site', strtolower( $result->get_error_message() ) );
	}

	/**
	 * Test that get-site returns error for non-existent domain.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_get_site_not_found_by_domain_returns_error() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => 'nonexistent-domain.example.com',
		] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
		// Original error message should mention the site was not found.
		$this->assertStringContainsString( 'site', strtolower( $result->get_error_message() ) );
	}

	/**
	 * Test that get-site includes full details.
	 *
	 * @return void
	 */
	public function test_get_site_includes_full_details() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name'      => 'Full Details Site',
			'adminname' => 'testadmin',
			'version'   => '5.2.0',
		] );

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		// Verify extended fields are present.
		$this->assertArrayHasKey( 'admin_username', $result );
		$this->assertArrayHasKey( 'wp_version', $result );
		$this->assertArrayHasKey( 'php_version', $result );
		$this->assertArrayHasKey( 'child_version', $result );
		$this->assertArrayHasKey( 'last_sync', $result );
		$this->assertArrayHasKey( 'notes', $result );

		// Verify known values.
		$this->assertEquals( 'testadmin', $result['admin_username'] );
		$this->assertEquals( '5.2.0', $result['child_version'] );
	}

	/**
	 * Test that get-site handles URL with protocol.
	 *
	 * @return void
	 */
	public function test_get_site_by_full_url() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Full URL Site',
			'url'  => 'https://test-fullurl.example.com/',
		] );

		// Use full URL with protocol.
		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => 'https://test-fullurl.example.com/',
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertEquals( $site_id, $result['id'] );
	}

	/**
	 * Test that get-site handles URL with www prefix.
	 *
	 * The normalize_url() function strips the www prefix, so a query with
	 * 'www.example.com' should match a site stored as 'https://example.com/'.
	 *
	 * @return void
	 */
	public function test_get_site_by_domain_with_www() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Create site without www.
		$site_id = $this->create_test_site( [
			'name' => 'WWW Test Site',
			'url'  => 'https://test-wwwtest.example.com/',
		] );

		// Query with www prefix - should resolve due to URL normalization.
		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => 'www.test-wwwtest.example.com',
		] );

		// normalize_url() strips 'www.' prefix, so this should match.
		$this->assertNotWPError( $result, 'www-prefixed domain should resolve to non-www site.' );
		$this->assertIsArray( $result );
		$this->assertEquals( $site_id, $result['id'], 'Should resolve to the correct site.' );
		$this->assertEquals( 'WWW Test Site', $result['name'] );
	}

	/**
	 * Test that get-site returns proper status values.
	 *
	 * @return void
	 */
	public function test_get_site_status_values() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Test connected status.
		$online_id = $this->create_test_site( [
			'name'                 => 'Online Site',
			'offline_check_result' => 1,
			'suspended'            => 0,
		] );

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $online_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'connected', $result['status'] );

		// Test disconnected status.
		$offline_id = $this->create_test_site( [
			'name'                 => 'Offline Site',
			'offline_check_result' => -1,
		] );

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $offline_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'disconnected', $result['status'] );

		// Test suspended status.
		$suspended_id = $this->create_test_site( [
			'name'                 => 'Suspended Site',
			'suspended'            => 1,
			'offline_check_result' => 1,
		] );

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $suspended_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'suspended', $result['status'] );
	}

	/**
	 * Test that get-site handles string ID.
	 *
	 * @return void
	 */
	public function test_get_site_with_string_id() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'String ID Test',
		] );

		// Pass ID as string.
		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => (string) $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertEquals( $site_id, $result['id'] );
	}

	/**
	 * Test that get-site handles client_id properly.
	 *
	 * @return void
	 */
	public function test_get_site_client_id() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Site with client.
		$with_client = $this->create_test_site( [
			'name'      => 'Site With Client',
			'client_id' => 42,
		] );

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $with_client,
		] );

		$this->assertNotWPError( $result );
		$this->assertEquals( 42, $result['client_id'] );

		// Site without client.
		$without_client = $this->create_test_site( [
			'name'      => 'Site Without Client',
			'client_id' => 0,
		] );

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $without_client,
		] );

		$this->assertNotWPError( $result );
		$this->assertNull( $result['client_id'] );
	}

	/**
	 * Test that get-site requires site_id_or_domain input.
	 *
	 * @return void
	 */
	public function test_get_site_requires_input() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Call without required input.
		$result = $this->execute_ability( 'mainwp/get-site-v1', [] );

		// Should return error (schema validation).
		$this->assertWPError( $result );
	}

	/**
	 * Test that get-site stats health_score stays within the 0-100 schema range.
	 *
	 * The mainwp_wp_sync.health_value column stores a sortable composite
	 * (score - critical * 100) that goes negative when critical issues exist.
	 * The ability output must report the real 0-100 score computed from the
	 * health_site_status issue counts, or the Abilities API rejects the
	 * response against the output schema.
	 *
	 * @return void
	 */
	public function test_get_site_stats_health_score_within_schema_range() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		global $wpdb;

		$site_id = $this->create_test_site( [
			'name' => 'Health Score Site',
		] );

		// Site Health issue counts with one critical issue:
		// val = 100 - ceil( 2.5 / 13.5 * 100 ) = 81, composite = 81 - 100 = -19.
		$issue_counts = [
			'good'        => 10,
			'recommended' => 2,
			'critical'    => 1,
		];
		$this->set_site_option( $site_id, 'health_site_status', wp_json_encode( $issue_counts ) );
		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp_sync',
			[ 'health_value' => -19 ],
			[ 'wpid' => $site_id ],
			[ '%d' ],
			[ '%d' ]
		);

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $site_id,
			'include_stats'     => true,
		] );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'stats', $result );
		$this->assertSame( 81, $result['stats']['health_score'] );
	}

	/**
	 * Test that get-site stats health_score is null without health data.
	 *
	 * @return void
	 */
	public function test_get_site_stats_health_score_null_without_data() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'No Health Data Site',
		] );

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $site_id,
			'include_stats'     => true,
		] );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'stats', $result );
		$this->assertNull( $result['stats']['health_score'] );
	}

	/**
	 * Test that get-site returns proper format for last_sync.
	 *
	 * @return void
	 */
	public function test_get_site_last_sync_format() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		global $wpdb;

		$site_id = $this->create_test_site( [
			'name' => 'Sync Time Test',
		] );

		// Update dtsSync in the sync table (not mainwp_wp).
		$sync_time = time();
		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp_sync',
			[ 'dtsSync' => $sync_time ],
			[ 'wpid' => $site_id ],
			[ '%d' ],
			[ '%d' ]
		);

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );

		// Should be ISO 8601 format.
		if ( ! empty( $result['last_sync'] ) ) {
			$parsed = strtotime( $result['last_sync'] );
			$this->assertNotFalse( $parsed, 'last_sync should be parseable timestamp.' );
		}
	}
}
