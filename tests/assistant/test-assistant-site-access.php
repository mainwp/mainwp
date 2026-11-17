<?php
/**
 * Tests for MainWP_Assistant::user_can_access_site() scope enforcement.
 *
 * Team Control can grant manage_options to a site-restricted role, so the
 * capability alone must not bypass the allowed-sites filter. These tests pin
 * the fix for the short-circuit that returned true for any manage_options
 * user before the scope filter ever ran.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;

/**
 * Class Test_Assistant_Site_Access
 */
class Test_Assistant_Site_Access extends MainWP_Assistant_Test_Case {

	/**
	 * Clean up scope filters between tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'mainwp_currentuserallowedaccesssites' );
		parent::tearDown();
	}

	/**
	 * Restrict the current user to the given site IDs via the scope filter.
	 *
	 * @param int[] $site_ids Allowed site IDs.
	 *
	 * @return void
	 */
	private function restrict_current_user_to_sites( array $site_ids = array( 1 ) ) {
		add_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $site_ids ) {
				return $site_ids;
			}
		);
	}

	/**
	 * Invoke the private user_can_access_site() method.
	 *
	 * @param int $user_id User ID.
	 * @param int $site_id Site ID.
	 *
	 * @return true|\WP_Error
	 */
	private function invoke_user_can_access_site( $user_id, $site_id ) {
		$method = new \ReflectionMethod( MainWP_Assistant::class, 'user_can_access_site' );
		$method->setAccessible( true );
		return $method->invoke( MainWP_Assistant::instance(), $user_id, $site_id );
	}

	/**
	 * A manage_options admin restricted to a site subset is denied on a site
	 * outside that subset — the capability must not bypass the scope filter.
	 *
	 * In this admin-ajax context the DB layer's own scoping hides the site at
	 * resolution (mainwp_site_not_found); under REST/cron/CLI the DB is
	 * unscoped and the explicit filter leg denies (mainwp_access_denied).
	 * Either code is a denial; the REST-context leg is pinned separately in
	 * the abilities REST scoping tests.
	 *
	 * @return void
	 */
	public function test_denies_manage_options_admin_outside_scope() {
		$user_id  = $this->set_current_user_as_admin();
		$in_scope = $this->create_test_site( array( 'name' => 'In Scope', 'url' => 'https://in-scope.example' ) );
		$outside  = $this->create_test_site( array( 'name' => 'Outside', 'url' => 'https://outside.example' ) );

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		$result = $this->invoke_user_can_access_site( $user_id, $outside );

		$this->assertWPError( $result );
		$this->assertContains( $result->get_error_code(), array( 'mainwp_access_denied', 'mainwp_site_not_found' ), 'An out-of-scope site must be denied, whichever leg catches it first.' );
	}

	/**
	 * The same restricted admin is allowed on a site inside the subset.
	 *
	 * @return void
	 */
	public function test_allows_manage_options_admin_inside_scope() {
		$user_id  = $this->set_current_user_as_admin();
		$in_scope = $this->create_test_site( array( 'name' => 'In Scope', 'url' => 'https://in-scope.example' ) );

		$this->restrict_current_user_to_sites( array( $in_scope ) );

		$this->assertTrue( $this->invoke_user_can_access_site( $user_id, $in_scope ) );
	}

	/**
	 * An unrestricted admin (filter returns 'all') keeps access to any site.
	 *
	 * @return void
	 */
	public function test_allows_unrestricted_admin_any_site() {
		$user_id = $this->set_current_user_as_admin();
		$site_id = $this->create_test_site( array( 'name' => 'Any Site', 'url' => 'https://any-site.example' ) );

		$this->assertTrue( $this->invoke_user_can_access_site( $user_id, $site_id ) );
	}
}
