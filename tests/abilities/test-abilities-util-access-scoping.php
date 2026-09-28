<?php
/**
 * Tests for direct site, client and tag access checks.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;

/**
 * Class Test_Abilities_Util_Access_Scoping
 */
class Test_Abilities_Util_Access_Scoping extends MainWP_Abilities_Test_Case {

	/**
	 * Keep unrelated callbacks on shared hooks intact during cleanup.
	 *
	 * @var array<string, callable>
	 */
	private $scope_filters = array();

	/**
	 * Remove only callbacks installed by this test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->scope_filters as $hook => $callback ) {
			remove_filter( $hook, $callback );
		}
		$this->scope_filters = array();
		parent::tearDown();
	}

	/**
	 * Restrict the current user to the given site IDs via the sites filter.
	 *
	 * @param array $site_ids Allowed site IDs.
	 *
	 * @return void
	 */
	private function restrict_current_user_to_sites( array $site_ids = array( 1 ) ) {
		if ( isset( $this->scope_filters['mainwp_currentuserallowedaccesssites'] ) ) {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $this->scope_filters['mainwp_currentuserallowedaccesssites'] );
		}
		$callback = static function () use ( $site_ids ) {
			return $site_ids;
		};
		$this->scope_filters['mainwp_currentuserallowedaccesssites'] = $callback;
		add_filter( 'mainwp_currentuserallowedaccesssites', $callback );
	}

	/**
	 * Restrict the current user to the given group IDs via the groups filter.
	 *
	 * @param array $group_ids Allowed group IDs.
	 *
	 * @return void
	 */
	private function restrict_current_user_to_groups( array $group_ids = array( 1 ) ) {
		if ( isset( $this->scope_filters['mainwp_currentuserallowedaccessgroups'] ) ) {
			remove_filter( 'mainwp_currentuserallowedaccessgroups', $this->scope_filters['mainwp_currentuserallowedaccessgroups'] );
		}
		$callback = static function () use ( $group_ids ) {
			return $group_ids;
		};
		$this->scope_filters['mainwp_currentuserallowedaccessgroups'] = $callback;
		add_filter( 'mainwp_currentuserallowedaccessgroups', $callback );
	}

	// =========================================================================
	// get_allowed_site_ids() / get_allowed_group_ids()
	// =========================================================================

	/**
	 * Unrestricted users read 'all' from the sites helper.
	 *
	 * @return void
	 */
	public function test_get_allowed_site_ids_returns_all_for_unrestricted_user() {
		$this->set_current_user_as_admin();
		$this->assertSame( 'all', MainWP_Abilities_Util::get_allowed_site_ids() );
	}

	/**
	 * A restricted filter value comes back as a sanitized unique int list.
	 *
	 * @return void
	 */
	public function test_get_allowed_site_ids_sanitizes_restricted_list() {
		$this->set_current_user_as_admin();
		$this->restrict_current_user_to_sites( array( '3', 'junk', 4, 4, null ) );

		$this->assertSame( array( 3, 4 ), MainWP_Abilities_Util::get_allowed_site_ids() );
	}

	/**
	 * An unexpected filter shape fails closed to an empty list.
	 *
	 * @return void
	 */
	public function test_get_allowed_site_ids_fails_closed_on_invalid_shape() {
		$this->set_current_user_as_admin();
		$this->scope_filters['mainwp_currentuserallowedaccesssites'] = static function () {
			return 'everything';
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $this->scope_filters['mainwp_currentuserallowedaccesssites'] );

		$this->assertSame( array(), MainWP_Abilities_Util::get_allowed_site_ids() );
	}

	/**
	 * Unrestricted users read 'all' from the groups helper.
	 *
	 * @return void
	 */
	public function test_get_allowed_group_ids_returns_all_for_unrestricted_user() {
		$this->set_current_user_as_admin();
		$this->assertSame( 'all', MainWP_Abilities_Util::get_allowed_group_ids() );
	}

	/**
	 * A restricted groups filter value comes back sanitized.
	 *
	 * @return void
	 */
	public function test_get_allowed_group_ids_sanitizes_restricted_list() {
		$this->set_current_user_as_admin();
		$this->restrict_current_user_to_groups( array( 7, '8', 'x' ) );

		$this->assertSame( array( 7, 8 ), MainWP_Abilities_Util::get_allowed_group_ids() );
	}

	// =========================================================================
	// check_client_access()
	// =========================================================================

	/**
	 * Unrestricted admins pass the client gate.
	 *
	 * @return void
	 */
	public function test_check_client_access_true_for_unrestricted_admin() {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$this->create_test_site( array( 'client_id' => $client_id ) );

		$this->assertTrue( MainWP_Abilities_Util::check_client_access( $client_id ) );
	}

	/**
	 * One accessible site out of several grants read access to the client.
	 *
	 * @return void
	 */
	public function test_check_client_access_true_on_any_site_overlap() {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$in_scope  = $this->create_test_site( array( 'client_id' => $client_id ) );
		$this->create_test_site( array( 'client_id' => $client_id ) );

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		$this->assertTrue( MainWP_Abilities_Util::check_client_access( $client_id ) );
	}

	/**
	 * No overlap between the client's sites and the allowed set denies.
	 *
	 * @return void
	 */
	public function test_check_client_access_denies_without_overlap() {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$this->create_test_site( array( 'client_id' => $client_id ) );
		$other_site = $this->create_test_site();

		$this->restrict_current_user_to_sites( array( $other_site ) );

		$result = MainWP_Abilities_Util::check_client_access( $client_id );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_client_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * Destructive operations require the allowed set to cover ALL the
	 * client's sites: partial overlap is read-only.
	 *
	 * @return void
	 */
	public function test_check_client_access_full_coverage_denies_partial_overlap() {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$in_scope  = $this->create_test_site( array( 'client_id' => $client_id ) );
		$this->create_test_site( array( 'client_id' => $client_id ) );

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		$result = MainWP_Abilities_Util::check_client_access( $client_id, null, true );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_access_denied', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Full coverage passes the destructive-operation gate.
	 *
	 * @return void
	 */
	public function test_check_client_access_full_coverage_allows_when_all_sites_allowed() {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$site_a    = $this->create_test_site( array( 'client_id' => $client_id ) );
		$site_b    = $this->create_test_site( array( 'client_id' => $client_id ) );

		$this->restrict_current_user_to_sites( array( $site_a, $site_b ) );

		$this->assertTrue( MainWP_Abilities_Util::check_client_access( $client_id, null, true ) );
	}

	// =========================================================================
	// check_tag_access()
	// =========================================================================

	/**
	 * Unrestricted admins pass the tag gate.
	 *
	 * @return void
	 */
	public function test_check_tag_access_true_for_unrestricted_admin() {
		$this->set_current_user_as_admin();
		$tag_id = $this->create_test_tag();

		$this->assertTrue( MainWP_Abilities_Util::check_tag_access( $tag_id ) );
	}

	/**
	 * A tag inside the allowed groups passes.
	 *
	 * @return void
	 */
	public function test_check_tag_access_true_when_tag_in_allowed_groups() {
		$this->set_current_user_as_admin();
		$tag_id = $this->create_test_tag();

		$this->restrict_current_user_to_groups( array( $tag_id ) );

		$this->assertTrue( MainWP_Abilities_Util::check_tag_access( $tag_id ) );
	}

	/**
	 * A tag outside the allowed groups denies.
	 *
	 * @return void
	 */
	public function test_check_tag_access_denies_when_tag_not_in_allowed_groups() {
		$this->set_current_user_as_admin();
		$tag_id = $this->create_test_tag();
		$other  = $this->create_test_tag();

		$this->restrict_current_user_to_groups( array( $other ) );

		$result = MainWP_Abilities_Util::check_tag_access( $tag_id );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_access_denied', $result->get_error_code() );
	}

	// =========================================================================
	// get_accessible_client_ids()
	// =========================================================================

	/**
	 * Only clients with at least one site in the given set come back.
	 *
	 * @return void
	 */
	public function test_get_accessible_client_ids_intersects_by_site() {
		$this->set_current_user_as_admin();
		$client_a = $this->create_test_client();
		$client_b = $this->create_test_client();
		$site_a   = $this->create_test_site( array( 'client_id' => $client_a ) );
		$this->create_test_site( array( 'client_id' => $client_b ) );

		$accessible = MainWP_Abilities_Util::get_accessible_client_ids( array( $site_a ) );

		$this->assertContains( $client_a, $accessible );
		$this->assertNotContains( $client_b, $accessible );
	}

	/**
	 * An empty allowed set yields no accessible clients.
	 *
	 * @return void
	 */
	public function test_get_accessible_client_ids_empty_for_empty_set() {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$this->create_test_site( array( 'client_id' => $client_id ) );

		$this->assertSame( array(), MainWP_Abilities_Util::get_accessible_client_ids( array() ) );
	}

	// =========================================================================
	// check_site_access() hardening
	// =========================================================================

	/**
	 * A restricted user is denied on a site outside the allowed set via the
	 * direct filter leg, independent of DB-layer scoping (which vanishes
	 * under MAINWP_REST_API_DOING/DOING_CRON/WP_CLI).
	 *
	 * @return void
	 */
	public function test_check_site_access_denies_site_outside_allowed_set() {
		$this->set_current_user_as_admin();
		$in_scope = $this->create_test_site();
		$outside  = $this->create_test_site();

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		$result = MainWP_Abilities_Util::check_site_access( $outside );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_site_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * A restricted user passes on a site inside the allowed set.
	 *
	 * @return void
	 */
	public function test_check_site_access_allows_site_within_allowed_set() {
		$this->set_current_user_as_admin();
		$in_scope = $this->create_test_site();

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		$this->assertTrue( MainWP_Abilities_Util::check_site_access( $in_scope ) );

		$outside = $this->create_test_site();
		$denied  = MainWP_Abilities_Util::check_site_access( $outside );
		$this->assertWPError( $denied );
		$this->assertSame( 'mainwp_site_not_found', $denied->get_error_code() );
		$this->assertSame( 404, $denied->get_error_data()['status'] );
	}

	/**
	 * Batch site filtering routes out-of-scope sites into the denied bucket
	 * for restricted callers.
	 *
	 * @return void
	 */
	public function test_check_batch_site_access_filters_out_of_scope_sites() {
		$this->set_current_user_as_admin();
		$in_scope    = $this->create_test_site();
		$outside_url = 'https://test-' . wp_generate_uuid4() . '.example.com/';
		$outside     = $this->create_test_site( array( 'url' => $outside_url ) );

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		$result = MainWP_Abilities_Util::check_batch_site_access( array( $in_scope, $outside_url ) );

		$allowed_ids = array_map( static fn( $site ) => (int) $site->id, $result['allowed'] );
		$this->assertContains( $in_scope, $allowed_ids );
		$this->assertNotContains( $outside, $allowed_ids );
		$this->assertCount( 1, $result['denied'] );
		$this->assertSame( $outside_url, $result['denied'][0]['identifier'] );
		$this->assertSame( 'mainwp_site_not_found', $result['denied'][0]['code'] );
		$this->assertSame( 404, $result['denied'][0]['status'] );
	}

	/**
	 * Unrestricted control: batch filtering passes every site through.
	 *
	 * @return void
	 */
	public function test_check_batch_site_access_allows_all_for_unrestricted_admin() {
		$this->set_current_user_as_admin();
		$site_a = $this->create_test_site();
		$site_b = $this->create_test_site();

		$result = MainWP_Abilities_Util::check_batch_site_access( array( $site_a, $site_b ) );

		$allowed_ids = array_map( static fn( $site ) => (int) $site->id, $result['allowed'] );
		$this->assertContains( $site_a, $allowed_ids );
		$this->assertContains( $site_b, $allowed_ids );
		$this->assertSame( array(), $result['denied'] );

		$this->restrict_current_user_to_sites( array( $site_a ) );
		$scoped = MainWP_Abilities_Util::check_batch_site_access( array( $site_a, $site_b ) );
		$this->assertCount( 1, $scoped['allowed'] );
		$this->assertCount( 1, $scoped['denied'] );
	}

	public function test_partial_client_visibility_does_not_grant_full_coverage() {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$allowed = $this->create_test_site( array( 'client_id' => $client_id ) );
		$this->create_test_site( array( 'client_id' => $client_id ) );
		$this->restrict_current_user_to_sites( array( $allowed ) );

		$this->assertTrue( MainWP_Abilities_Util::check_client_access( $client_id ) );
		$result = MainWP_Abilities_Util::check_client_access( $client_id, null, true );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_access_denied', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_unrestricted_site_filter_denial_remains_forbidden() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->scope_filters['mainwp_check_site_access'] = static function () {
			return false;
		};
		add_filter( 'mainwp_check_site_access', $this->scope_filters['mainwp_check_site_access'] );

		$this->assertSame( 'all', MainWP_Abilities_Util::get_allowed_site_ids() );
		$result = MainWP_Abilities_Util::check_site_access( $site_id );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_access_denied', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_restricted_site_filter_denial_matches_missing_site() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->restrict_current_user_to_sites( array( $site_id ) );
		$this->scope_filters['mainwp_check_site_access'] = static function () {
			return false;
		};
		add_filter( 'mainwp_check_site_access', $this->scope_filters['mainwp_check_site_access'] );

		$result = MainWP_Abilities_Util::check_site_access( $site_id );
		$missing = MainWP_Abilities_Util::resolve_site( PHP_INT_MAX );
		$this->assertWPError( $result );
		$this->assertWPError( $missing );
		$this->assertSame( 'mainwp_site_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( str_replace( (string) PHP_INT_MAX, (string) $site_id, $missing->get_error_message() ), $result->get_error_message() );
	}

	public function test_restricted_tag_denial_matches_missing_tag() {
		$this->set_current_user_as_admin();
		$tag_id = $this->create_test_tag();
		$this->restrict_current_user_to_sites( array() );
		$this->restrict_current_user_to_groups( array() );

		$result = MainWP_Abilities_Util::check_tag_access( $tag_id );
		$missing = MainWP_Abilities_Util::resolve_tag( PHP_INT_MAX );
		$this->assertWPError( $result );
		$this->assertWPError( $missing );
		$this->assertSame( 'mainwp_tag_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( str_replace( (string) PHP_INT_MAX, (string) $tag_id, $missing->get_error_message() ), $result->get_error_message() );
	}

	public function test_restricted_batch_domain_denial_matches_missing_domain() {
		$this->set_current_user_as_admin();
		$url = 'https://test-' . wp_generate_uuid4() . '.example.com/';
		$this->create_test_site( array( 'url' => $url ) );
		$this->restrict_current_user_to_sites( array() );

		$result = MainWP_Abilities_Util::check_batch_site_access( array( $url, 'https://missing.example.com/' ) );
		$this->assertCount( 2, $result['denied'] );
		$this->assertSame( 'mainwp_site_not_found', $result['denied'][0]['code'] );
		$this->assertSame( 404, $result['denied'][0]['status'] );
		$this->assertSame( str_replace( 'https://missing.example.com/', $url, $result['denied'][1]['message'] ), $result['denied'][0]['message'] );
	}

	public function test_restricted_client_email_denial_matches_missing_email() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$email = 'scope-' . wp_generate_uuid4() . '@example.com';
		$this->create_test_client( array( 'client_email' => $email ) );
		$this->restrict_current_user_to_sites( array() );

		$result = $this->execute_ability( 'mainwp/get-client-v1', array( 'client_id_or_email' => $email ) );
		$missing = $this->execute_ability( 'mainwp/get-client-v1', array( 'client_id_or_email' => 'missing@example.com' ) );
		$this->assertWPError( $result );
		$this->assertWPError( $missing );
		$this->assertSame( 'mainwp_client_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( str_replace( 'missing@example.com', $email, $missing->get_error_message() ), $result->get_error_message() );
	}
}
