<?php
/**
 * Tests for granular Team Control operation-right enforcement in the assistant.
 *
 * Team Control can grant manage_options to a role while withholding individual
 * operation rights (delete_sites, update_plugins, manage_clients, ...). The
 * assistant runs abilities on the user's behalf, so it must honour those
 * per-operation rights the same way the dashboard UI does — the site-scoping
 * added earlier restricts WHICH sites, not WHICH operations.
 *
 * The enforcing wrapper (mainwp_current_user_can) bypasses under
 * DOING_CRON / WP_CLI / MAINWP_REST_API_DOING, matching product-wide Team
 * Control behaviour; those surfaces already require manage_options and are out
 * of scope for granular rights (breaking them would break MCP/CLI automation).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;

/**
 * Class Test_Assistant_Operation_Rights
 */
class Test_Assistant_Operation_Rights extends MainWP_Assistant_Test_Case {

	/**
	 * The exact right filter callback added this test, for targeted removal.
	 *
	 * @var callable|null
	 */
	private $right_filter = null;

	/**
	 * Remove only this test's own right filter — never remove_all_filters on a
	 * shared hook, which perturbs the global registry and destabilises the
	 * ordering of unrelated tests later in the process.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null !== $this->right_filter ) {
			remove_filter( 'mainwp_currentusercan', $this->right_filter, 10 );
			$this->right_filter = null;
		}
		parent::tearDown();
	}

	/**
	 * Withhold a specific dashboard right from the current user.
	 *
	 * @param string $withheld_right The right slug to deny.
	 *
	 * @return void
	 */
	private function withhold_right( $withheld_right ) {
		$this->right_filter = static function ( $allowed, $cap_type, $cap ) use ( $withheld_right ) {
			if ( 'dashboard' === $cap_type && $cap === $withheld_right ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $this->right_filter, 10, 3 );
	}

	/**
	 * Invoke the private check_operation_right() method.
	 *
	 * @param string $ability Ability id.
	 * @param array  $params  Ability params.
	 *
	 * @return true|\WP_Error
	 */
	private function invoke_check( $ability, array $params = array() ) {
		$method = new \ReflectionMethod( MainWP_Assistant::class, 'check_operation_right' );
		$method->setAccessible( true );
		return $method->invoke( MainWP_Assistant::instance(), $ability, $params );
	}

	/**
	 * A withheld right denies the mapped mutating ability.
	 *
	 * @return void
	 */
	public function test_withheld_right_denies_mapped_ability() {
		$this->set_current_user_as_admin();
		$this->withhold_right( 'delete_sites' );

		$result = $this->invoke_check( 'mainwp/delete-site-v1' );

		$this->assertWPError( $result, 'A user without delete_sites must be denied the delete-site ability.' );
		$this->assertSame( 'mainwp_insufficient_right', $result->get_error_code() );
	}

	/**
	 * Holding the right allows the mapped ability.
	 *
	 * @return void
	 */
	public function test_held_right_allows_mapped_ability() {
		$this->set_current_user_as_admin();
		$this->withhold_right( 'manage_clients' ); // Withhold a different right.

		$this->assertTrue( $this->invoke_check( 'mainwp/delete-site-v1' ), 'delete-site must be allowed when delete_sites is held.' );
	}

	/**
	 * Read-only / unmapped abilities require no operation right.
	 *
	 * @return void
	 */
	public function test_unmapped_ability_requires_no_right() {
		$this->set_current_user_as_admin();
		$this->withhold_right( 'delete_sites' );

		$this->assertTrue( $this->invoke_check( 'mainwp/list-sites-v1' ), 'A read ability must not require an operation right.' );
	}

	/**
	 * The update family derives its required rights from the requested types.
	 *
	 * @return void
	 */
	public function test_update_family_checks_requested_type_rights() {
		$this->set_current_user_as_admin();
		$this->withhold_right( 'update_plugins' );

		// Themes-only request: update_plugins withheld must not matter.
		$this->assertTrue(
			$this->invoke_check( 'mainwp/run-updates-v1', array( 'types' => array( 'themes' ) ) ),
			'A themes-only update must not require update_plugins.'
		);

		// Plugins request: the withheld right denies.
		$plugins = $this->invoke_check( 'mainwp/run-updates-v1', array( 'types' => array( 'plugins' ) ) );
		$this->assertWPError( $plugins, 'A plugins update must require update_plugins.' );
		$this->assertSame( 'mainwp_insufficient_right', $plugins->get_error_code() );

		// Empty types means all types, so the withheld right still denies.
		$all = $this->invoke_check( 'mainwp/update-all-v1', array( 'types' => array() ) );
		$this->assertWPError( $all, 'update-all with no types means all types and must require every update right.' );
	}

	/**
	 * Each mapped mutating ability is denied when its own right is withheld.
	 *
	 * Guards the map itself: a mis-keyed or dropped entry surfaces here rather
	 * than shipping an unenforced operation.
	 *
	 * @dataProvider mapped_ability_provider
	 *
	 * @param string $ability Ability id.
	 * @param string $right   The right that gates it.
	 *
	 * @return void
	 */
	public function test_each_mapped_ability_is_gated_by_its_right( $ability, $right ) {
		$this->set_current_user_as_admin();
		$this->withhold_right( $right );

		$result = $this->invoke_check( $ability );
		$this->assertWPError( $result, $ability . ' must be denied when ' . $right . ' is withheld.' );
		$this->assertSame( 'mainwp_insufficient_right', $result->get_error_code() );
	}

	/**
	 * Ability → required right pairs the map must enforce.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public function mapped_ability_provider() {
		return array(
			'add-site'          => array( 'mainwp/add-site-v1', 'add_sites' ),
			'update-site'       => array( 'mainwp/update-site-v1', 'edit_sites' ),
			'delete-site'       => array( 'mainwp/delete-site-v1', 'delete_sites' ),
			'suspend-site'      => array( 'mainwp/suspend-site-v1', 'edit_sites' ),
			'activate-plugins'  => array( 'mainwp/activate-site-plugins-v1', 'activate_deactivate_plugins' ),
			'delete-plugins'    => array( 'mainwp/delete-site-plugins-v1', 'delete_plugins' ),
			'activate-theme'    => array( 'mainwp/activate-site-theme-v1', 'activate_themes' ),
			'delete-themes'     => array( 'mainwp/delete-site-themes-v1', 'delete_themes' ),
			'update-core'       => array( 'mainwp/update-site-core-v1', 'update_wordpress' ),
			'update-plugins'    => array( 'mainwp/update-site-plugins-v1', 'update_plugins' ),
			'ignore-updates'    => array( 'mainwp/set-ignored-updates-v1', 'ignore_unignore_updates' ),
			'add-client'        => array( 'mainwp/add-client-v1', 'manage_clients' ),
			'delete-client'     => array( 'mainwp/delete-client-v1', 'manage_clients' ),
			'add-tag'           => array( 'mainwp/add-tag-v1', 'manage_groups' ),
			'delete-tag'        => array( 'mainwp/delete-tag-v1', 'manage_groups' ),
		);
	}
}
