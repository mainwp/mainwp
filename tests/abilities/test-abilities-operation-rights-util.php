<?php
/**
 * Operation-right checks must enforce Team Control permissions across abilities
 * and queued jobs.
 *
 * Machine requests may bypass rights only without an authenticated user. Cron
 * restores the job owner so that their rights remain enforceable.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Abilities_Operation_Rights_Util extends \WP_UnitTestCase {

	public function test_c3_unignore_maps_to_ignore_unignore_right() {
		$map = MainWP_Abilities_Util::ABILITY_OPERATION_RIGHTS;
		$this->assertArrayHasKey( 'mainwp/unignore-site-updates-v1', $map );
		$this->assertSame( 'ignore_unignore_updates', $map['mainwp/unignore-site-updates-v1'] );
		$this->assertSame( [ 'ignore_unignore_updates' ], MainWP_Abilities_Util::required_rights_for_ability( 'mainwp/unignore-site-updates-v1' ) );
		$this->withhold_right( 'ignore_unignore_updates' );
		$callback = MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/unignore-site-updates-v1', static function () { return true; } );
		$result = $callback( [ 'confirm' => true ] );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_insufficient_right', $result->get_error_code() );
	}

	/**
	 * The exact right filter callback added by this test, for targeted removal.
	 * Never remove_all_filters on a shared hook (destabilises unrelated tests).
	 *
	 * @var callable|null
	 */
	private $right_filter = null;

	public function tearDown(): void {
		if ( null !== $this->right_filter ) {
			remove_filter( 'mainwp_currentusercan', $this->right_filter, 10 );
			$this->right_filter = null;
		}
		$this->set_machine_context_override( null );
		parent::tearDown();
	}

	/**
	 * Withhold a specific dashboard right from the current user via the
	 * Team Control enforcement filter.
	 *
	 * @param string $withheld_right The right slug to deny.
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
	 * Set the private machine-context override seam (constants cannot be
	 * defined per-test, so the bypass leg is tested through the seam).
	 *
	 * @param bool|null $value Override value, null to restore real detection.
	 */
	private function set_machine_context_override( $value ) {
		$prop = new \ReflectionProperty( MainWP_Abilities_Util::class, 'machine_context_override' );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}

	// ---------------------------------------------------------------------
	// required_rights_for_ability()
	// ---------------------------------------------------------------------

	/**
	 * A mapped mutating ability resolves to its single mapped right.
	 */
	public function test_mapped_ability_resolves_its_right() {
		$this->assertSame(
			array( 'delete_sites' ),
			MainWP_Abilities_Util::required_rights_for_ability( 'mainwp/delete-site-v1' )
		);
	}

	/**
	 * Read-only / unmapped abilities require no rights.
	 */
	public function test_unmapped_ability_requires_no_rights() {
		$this->assertSame( array(), MainWP_Abilities_Util::required_rights_for_ability( 'mainwp/list-sites-v1' ) );
	}

	/**
	 * The update family derives rights from the requested types only.
	 */
	public function test_update_family_derives_rights_from_types() {
		$this->assertSame(
			array( 'update_themes' ),
			MainWP_Abilities_Util::required_rights_for_ability( 'mainwp/run-updates-v1', array( 'types' => array( 'themes' ) ) )
		);

		$this->assertSame(
			array( 'update_wordpress', 'update_plugins' ),
			MainWP_Abilities_Util::required_rights_for_ability( 'mainwp/update-all-v1', array( 'types' => array( 'core', 'plugins' ) ) )
		);
	}

	/**
	 * Empty or missing types means every update type, so every update right.
	 */
	public function test_update_family_empty_types_means_all_rights() {
		$expected = array( 'update_wordpress', 'update_plugins', 'update_themes', 'update_translations' );

		$this->assertSame(
			$expected,
			MainWP_Abilities_Util::required_rights_for_ability( 'mainwp/run-updates-v1', array( 'types' => array() ) )
		);
		$this->assertSame(
			$expected,
			MainWP_Abilities_Util::required_rights_for_ability( 'mainwp/update-all-v1' )
		);
	}

	/**
	 * Unknown update types are ignored rather than mapped or fatal.
	 */
	public function test_update_family_ignores_unknown_types() {
		$this->assertSame(
			array( 'update_plugins' ),
			MainWP_Abilities_Util::required_rights_for_ability( 'mainwp/run-updates-v1', array( 'types' => array( 'plugins', 'bogus' ) ) )
		);
	}

	/**
	 * A dropped rights-map entry must not silently permit a mutation.
	 */
	public function test_relocated_map_carries_expected_entries() {
		$map = MainWP_Abilities_Util::ABILITY_OPERATION_RIGHTS;

		$this->assertSame( 'add_sites', $map['mainwp/add-site-v1'] );
		$this->assertSame( 'delete_sites', $map['mainwp/delete-site-v1'] );
		$this->assertSame( 'activate_deactivate_plugins', $map['mainwp/activate-site-plugins-v1'] );
		$this->assertSame( 'update_wordpress', $map['mainwp/update-site-core-v1'] );
		$this->assertSame( 'ignore_unignore_updates', $map['mainwp/set-ignored-updates-v1'] );
		$this->assertSame( 'manage_clients', $map['mainwp/delete-client-v1'] );
		$this->assertSame( 'manage_groups', $map['mainwp/delete-tag-v1'] );

		$this->assertSame(
			array(
				'core'         => 'update_wordpress',
				'plugins'      => 'update_plugins',
				'themes'       => 'update_themes',
				'translations' => 'update_translations',
			),
			MainWP_Abilities_Util::UPDATE_TYPE_RIGHTS
		);
	}

	// ---------------------------------------------------------------------
	// current_user_has_operation_right()
	// ---------------------------------------------------------------------

	/**
	 * With no Team Control filter attached the right is granted (default true).
	 */
	public function test_right_granted_by_default() {
		$this->assertTrue( MainWP_Abilities_Util::current_user_has_operation_right( 'delete_sites' ) );
	}

	/**
	 * A denying mainwp_currentusercan filter withholds the right.
	 */
	public function test_denying_filter_withholds_right() {
		$this->withhold_right( 'delete_sites' );

		$this->assertFalse( MainWP_Abilities_Util::current_user_has_operation_right( 'delete_sites' ) );
		$this->assertTrue( MainWP_Abilities_Util::current_user_has_operation_right( 'edit_sites' ), 'Only the withheld right is denied.' );
	}

	/**
	 * A machine execution context with no authenticated user bypasses the
	 * filter: Team Control fails closed on an empty current user, which would
	 * break plain WP-CLI automation.
	 */
	public function test_machine_context_without_a_user_bypasses_filter() {
		wp_set_current_user( 0 );
		$this->withhold_right( 'delete_sites' );
		$this->set_machine_context_override( true );

		$this->assertTrue( MainWP_Abilities_Util::current_user_has_operation_right( 'delete_sites' ) );
	}

	/**
	 * A machine execution context that DOES carry an authenticated user gets
	 * real evaluation. API-key REST authenticates as the key's owner through
	 * the determine_current_user filter, so MAINWP_REST_API_DOING must not
	 * hand that owner rights their Team Control role withholds.
	 */
	public function test_machine_context_with_a_user_evaluates_filter() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->withhold_right( 'delete_sites' );
		$this->set_machine_context_override( true );

		$this->assertFalse( MainWP_Abilities_Util::current_user_has_operation_right( 'delete_sites' ) );
		$this->assertTrue( MainWP_Abilities_Util::current_user_has_operation_right( 'edit_sites' ), 'Only the withheld right is denied.' );
	}

	/**
	 * With the override forced off, evaluation reaches the filter (guards the
	 * seam itself: null restores detection, false forces evaluation).
	 */
	public function test_forced_non_machine_context_evaluates_filter() {
		$this->withhold_right( 'delete_sites' );
		$this->set_machine_context_override( false );

		$this->assertFalse( MainWP_Abilities_Util::current_user_has_operation_right( 'delete_sites' ) );
	}
}
