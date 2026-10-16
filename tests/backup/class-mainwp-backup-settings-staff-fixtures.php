<?php
/**
 * Role fixtures keep staff refusals independent of site scope and capability bridges.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;

/** Shared actors for the backup settings write surfaces. */
trait Backup_Settings_Staff_Fixtures {

	/**
	 * Legacy roles and mixed-role users must retain their real WordPress role slugs.
	 *
	 * @param string $actor Actor code from the settings rights matrix.
	 * @return int User ID, or zero for a userless caller.
	 */
	protected function settings_actor( string $actor ): int {
		add_role(
			'mainwp_settings_staff',
			'Settings staff',
			array(
				'read'     => true,
				'level_10' => true,
			)
		);
		add_role( 'mainwp_settings_legacy', 'Legacy settings staff', get_role( 'administrator' )->capabilities );
		add_role( 'mainwpx', 'Unrelated prefix', array( 'read' => true ) );
		add_role( 'xmainwp_', 'Unrelated infix', array( 'read' => true ) );
		if ( 'C0' === $actor ) {
			wp_set_current_user( 0 );
			return 0;
		}
		$roles = array(
			'A'  => array( 'administrator' ),
			'A+' => array( 'administrator', 'mainwp_settings_staff' ),
			'S'  => array( 'subscriber', 'mainwp_settings_staff' ),
			'SL' => array( 'mainwp_settings_legacy' ),
			'SE' => array( 'editor', 'mainwp_settings_staff' ),
			'N'  => array( 'editor' ),
			'PX' => array( 'mainwpx' ),
			'IX' => array( 'xmainwp_' ),
		);
		$user  = self::factory()->user->create_and_get( array( 'role' => $roles[ $actor ][0] ) );
		foreach ( array_slice( $roles[ $actor ], 1 ) as $role ) {
			$user->add_role( $role );
		}
		wp_set_current_user( $user->ID );
		return $user->ID;
	}

	/**
	 * Both staff generations must fail with all other rights granted.
	 *
	 * @return array Actor and expected permission.
	 */
	public static function settings_writers(): array {
		return array(
			'admin'        => array( 'A', true ),
			'admin staff'  => array( 'A+', true ),
			'staff'        => array( 'S', false ),
			'legacy'       => array( 'SL', false ),
			'editor staff' => array( 'SE', false ),
		);
	}

	/**
	 * The armed Team Control bridge grants manage_options without changing roles.
	 *
	 * @param array $caps Effective capabilities.
	 * @return array
	 */
	public static function settings_bridge_caps( $caps ): array {
		$caps['manage_options'] = true;
		return $caps;
	}

	/** An all-sites role must reach the new staff guard rather than an older scope guard. */
	public static function settings_all_scope(): string {
		return 'all';
	}

	/** Every older authorization layer must allow the simulated future all-sites role. */
	protected function allow_unrestricted_settings_access(): void {
		add_filter( 'mainwp_currentusercan', '__return_true', PHP_INT_MAX );
		add_filter( 'mainwp_currentuserallowedaccesssites', array( self::class, 'settings_all_scope' ), PHP_INT_MAX );
		add_filter( 'mainwp_currentuserallowedaccessgroups', array( self::class, 'settings_all_scope' ), PHP_INT_MAX );
		add_filter( 'user_has_cap', array( self::class, 'settings_bridge_caps' ) );
		$this->assertTrue( MainWP_Abilities_Util::check_unrestricted_access() );
		$this->assertSame( 'all', MainWP_Abilities_Util::get_scoped_site_ids_or_all() );
		$this->assertSame( 'all', MainWP_Abilities_Util::get_scoped_group_ids_or_all() );
	}

	/** Role and capability caches outlive individual fixture users. */
	protected function clear_settings_staff_fixtures(): void {
		remove_filter( 'mainwp_currentusercan', '__return_true', PHP_INT_MAX );
		remove_filter( 'mainwp_currentuserallowedaccesssites', array( self::class, 'settings_all_scope' ), PHP_INT_MAX );
		remove_filter( 'mainwp_currentuserallowedaccessgroups', array( self::class, 'settings_all_scope' ), PHP_INT_MAX );
		remove_filter( 'user_has_cap', array( self::class, 'settings_bridge_caps' ) );
		foreach ( array( 'mainwp_settings_staff', 'mainwp_settings_legacy', 'mainwpx', 'xmainwp_' ) as $role ) {
			remove_role( $role );
		}
	}
}
