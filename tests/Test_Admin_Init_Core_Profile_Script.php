<?php
/**
 * Admin script loading tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_System;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * MainWP pages must not load WordPress's profile form script.
 */
class Test_Admin_Init_Core_Profile_Script extends \WP_UnitTestCase {

	/**
	 * Reset the script queue the test enqueues into.
	 */
	public function tear_down(): void {
		wp_dequeue_script( 'mainwp' );
		wp_dequeue_script( 'user-profile' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Core's user-profile.js only sets its form reference when a profile form
	 * renders; on MainWP pages its beforeunload handler then throws reading
	 * `serialize` of undefined on every navigation away (the Updates page error).
	 */
	public function test_admin_init_does_not_enqueue_core_user_profile_script(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		MainWP_System::instance()->admin_init();

		$this->assertTrue( wp_script_is( 'mainwp', 'enqueued' ), 'admin_init must have run its enqueue block.' );
		$this->assertFalse( wp_script_is( 'user-profile', 'enqueued' ) );
	}
}
