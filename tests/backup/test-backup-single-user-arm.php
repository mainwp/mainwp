<?php
/**
 * MainWP Single-User Gate Arming Tests
 *
 * Proves the mainwp_is_single_user filter arms the legacy row-ownership
 * gates (can_edit_website and friends) without changing default behavior,
 * and that the private-files root (pk0, encryption keys) never moves off
 * the userid-0 path regardless of the filter — a flip must be an
 * access-control change, never a silent key-storage reparenting.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_System;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Class MainWP_Backup_Single_User_Arm_Test
 */
class MainWP_Backup_Single_User_Arm_Test extends \WP_UnitTestCase {

	/**
	 * Remove any filter and user state the tests added.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'mainwp_is_single_user' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Default behavior is unchanged: single-user mode, ownership gates
	 * inert, exactly as every install has always run.
	 *
	 * @return void
	 */
	public function test_default_stays_single_user() {
		$this->assertTrue( MainWP_System::instance()->is_single_user() );
		$this->assertFalse( MainWP_System::instance()->is_multi_user() );
	}

	/**
	 * Flipping the filter arms multi-user mode through both accessors.
	 *
	 * @return void
	 */
	public function test_filter_arms_multi_user_mode() {
		add_filter( 'mainwp_is_single_user', '__return_false' );

		$this->assertFalse( MainWP_System::instance()->is_single_user() );
		$this->assertTrue( MainWP_System::instance()->is_multi_user() );
	}

	/**
	 * With multi-user armed, can_edit_website() becomes a live ownership
	 * gate: it refuses a site owned by another user and allows the owner.
	 *
	 * @return void
	 */
	public function test_armed_can_edit_website_enforces_ownership() {
		$owner    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$stranger = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$website  = (object) array( 'userid' => $owner );

		add_filter( 'mainwp_is_single_user', '__return_false' );

		wp_set_current_user( $stranger );
		$this->assertFalse( MainWP_System_Utility::can_edit_website( $website ), 'A stranger must be refused once multi-user is armed.' );

		wp_set_current_user( $owner );
		$this->assertTrue( MainWP_System_Utility::can_edit_website( $website ), 'The owner must keep access.' );
	}

	/**
	 * The private-files root never moves: with multi-user armed and a real
	 * current user, get_mainwp_specific_dir()/url() still resolve to the
	 * userid-0 root that holds every existing install's encryption keys.
	 *
	 * @return void
	 */
	public function test_private_files_root_pinned_to_userid_zero() {
		$single_dir = MainWP_System_Utility::get_mainwp_specific_dir();
		$single_url = MainWP_System_Utility::get_mainwp_specific_url( 'pk' );

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		add_filter( 'mainwp_is_single_user', '__return_false' );

		$this->assertSame( $single_dir, MainWP_System_Utility::get_mainwp_specific_dir(), 'Arming multi-user must never reparent the private-files directory.' );
		$this->assertSame( $single_url, MainWP_System_Utility::get_mainwp_specific_url( 'pk' ), 'Arming multi-user must never reparent the private-files URL.' );
	}
}
