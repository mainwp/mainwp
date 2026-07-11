<?php
/**
 * MainWP Backup Task Ownership Authorization Tests
 *
 * `MainWP_System::is_single_user()` is hardcoded to always return true in
 * production, so the ownership comparison inside
 * `MainWP_Manage_Backups_Handler::can_edit_backuptask()` never actually runs
 * there today. These tests force the multi-user branch with a
 * reflection-swapped `MainWP_System` double to prove the ownership gate is
 * live logic (and future-proofed for if `is_single_user()` ever stops being
 * hardcoded), plus cover the two gates that DO run unconditionally today:
 * the task's own site/group scope, and the Team Control per-site capability
 * (`mainwp_current_user_can('site', $id)`) - the gate that actually protects
 * production right now.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_System;

/**
 * Test double that forces the multi-user branch of
 * MainWP_System::is_single_user(). Production hardcodes it to true; this
 * proves the downstream ownership comparisons are live logic rather than
 * unreachable dead code, in case that ever changes.
 */
class Multi_User_System_Double extends MainWP_System {

	/**
	 * Force the multi-user branch.
	 *
	 * @return bool Always false.
	 */
	public function is_single_user() {
		return false;
	}
}

/**
 * Class MainWP_Backup_Task_Ownership_Auth_Test
 */
class MainWP_Backup_Task_Ownership_Auth_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Enable native backups so the kill switch in
	 * MainWP_Backup_Executor::backup() doesn't mask the auth gates under
	 * test with an unrelated "not enabled" refusal.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Clean up the enablement option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( 'mainwp_enableNativeBackups' );
		parent::tearDown();
	}

	/**
	 * Swap MainWP_System::$instance for the forced-multi-user double for the
	 * duration of the callback, restoring the real instance afterward even
	 * if the callback throws.
	 *
	 * @param callable $callback Test body to run under the double.
	 *
	 * @return mixed The callback's return value.
	 */
	private function with_forced_multi_user( callable $callback ) {
		$reflection = new \ReflectionClass( MainWP_System::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$original = $property->getValue();

		$double = ( new \ReflectionClass( Multi_User_System_Double::class ) )->newInstanceWithoutConstructor();
		$property->setValue( null, $double );

		try {
			return $callback();
		} finally {
			$property->setValue( null, $original );
		}
	}

	/**
	 * Create a manual backup task owned by the given user over one site.
	 *
	 * @param int $owner_id Task owner user id.
	 * @param int $site_id  Site id in the task's sites column.
	 *
	 * @return object Task row.
	 */
	private function create_task( $owner_id, $site_id ) {
		$task = MainWP_DB_Backup::instance()->add_backup_task( $owner_id, 'Ownership test task', 'manual', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		return $task;
	}

	/**
	 * Deny the per-site capability for one site id via the capability
	 * filter, simulating a Team Control site restriction.
	 *
	 * @param int $site_id Site id to deny.
	 *
	 * @return callable The filter, for later removal.
	 */
	private function deny_site( $site_id ) {
		$deny = function ( $allowed, $cap_type, $cap ) use ( $site_id ) {
			if ( 'site' === $cap_type && (int) $cap === (int) $site_id ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		return $deny;
	}

	/**
	 * A non-owner cannot run another user's backup task once the multi-user
	 * ownership branch is live - the currently-dead-code path this fix
	 * future-proofs. Asserted on the exact message: `can_edit_website()`
	 * shares the same is_single_user()-short-circuit shape and runs further
	 * downstream inside the executor, so a generic "some MainWP_Exception
	 * was thrown" assertion could pass for the wrong reason once the
	 * forced-multi-user double is in play.
	 *
	 * @return void
	 */
	public function test_backup_refuses_non_owner_in_forced_multi_user_mode() {
		$owner    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$intruder = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_id  = $this->create_test_site( array( 'userid' => $owner ) );
		$task     = $this->create_task( $owner, $site_id );

		wp_set_current_user( $intruder );

		$thrown = null;
		$this->with_forced_multi_user(
			function () use ( $task, $site_id, &$thrown ) {
				try {
					MainWP_Manage_Backups_Handler::backup( $task->id, $site_id, 'uid-ownership-test' );
				} catch ( MainWP_Exception $e ) {
					$thrown = $e;
				}
			}
		);

		$this->assertInstanceOf( MainWP_Exception::class, $thrown, 'A non-owner must be refused once ownership actually gates.' );
		$this->assertStringContainsString( 'Insufficient permissions', $thrown->getMessage(), 'The task-ownership gate specifically, not a downstream executor check, must be what refuses this call.' );
	}

	/**
	 * A non-owner cannot list another user's backup task sites once the
	 * multi-user ownership branch is live.
	 *
	 * @return void
	 */
	public function test_get_backup_task_sites_denies_non_owner_in_forced_multi_user_mode() {
		$owner    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$intruder = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_id  = $this->create_test_site( array( 'userid' => $owner ) );
		$task     = $this->create_task( $owner, $site_id );

		wp_set_current_user( $intruder );

		$thrown = null;
		$this->with_forced_multi_user(
			function () use ( $task, &$thrown ) {
				try {
					MainWP_Manage_Backups_Handler::get_backup_task_sites( $task->id );
				} catch ( MainWP_Exception $e ) {
					$thrown = $e;
				}
			}
		);

		$this->assertInstanceOf( MainWP_Exception::class, $thrown, 'A non-owner must be refused once ownership actually gates.' );
		$this->assertStringContainsString( 'Insufficient permissions', $thrown->getMessage(), 'The task-ownership gate must be what refuses this call.' );
	}

	/**
	 * The real production gate: a site-restricted user is refused even in
	 * today's default single-user mode, because
	 * mainwp_current_user_can('site', $id) does not depend on
	 * is_single_user() at all. No reflection swap needed - this is the
	 * concrete MainWP Team Control scenario, and the one that actually
	 * protects production today.
	 *
	 * @return void
	 */
	public function test_backup_refuses_site_restricted_user_even_in_single_user_mode() {
		$this->skip_when_capabilities_bypassed();

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$task    = $this->create_task( $user, $site_id );

		$deny = $this->deny_site( $site_id );

		$thrown = null;
		try {
			MainWP_Manage_Backups_Handler::backup( $task->id, $site_id, 'uid-team-control-test' );
		} catch ( MainWP_Exception $e ) {
			$thrown = $e;
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}

		$this->assertInstanceOf( MainWP_Exception::class, $thrown, 'A Team Control site restriction must refuse the run even for the task owner in single-user mode.' );
		$this->assertStringContainsString( 'not allowed to manage backups', $thrown->getMessage(), 'The Team Control per-site gate must be what refuses this call.' );
	}

	/**
	 * The task create/update write path must refuse a sites list containing
	 * a site the caller's Team Control access has been scoped away from -
	 * once the row is stored, the scheduled cron runs it with no user
	 * context to re-check against, so write time is the only gate.
	 *
	 * @return void
	 */
	public function test_task_scope_refuses_site_restricted_user() {
		$this->skip_when_capabilities_bypassed();

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$allowed = $this->create_test_site( array( 'userid' => $user ) );
		$denied  = $this->create_test_site( array( 'userid' => $user ) );

		$deny = $this->deny_site( $denied );
		try {
			$error = MainWP_Manage_Backups_Handler::authorize_task_scope( $allowed . ',' . $denied, '' );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}

		$this->assertNotSame( '', $error, 'A restricted site in the task sites list must refuse the write.' );
	}

	/**
	 * A group is expanded at write time and refused when it contains a
	 * restricted site: scheduling by group must not become the bypass for
	 * the per-site check the direct sites list gets.
	 *
	 * @return void
	 */
	public function test_task_scope_refuses_group_containing_restricted_site() {
		$this->skip_when_capabilities_bypassed();
		global $wpdb;

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id  = $this->create_test_site( array( 'userid' => $user ) );
		$group_id = 424242;
		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_group',
			array(
				'wpid'    => $site_id,
				'groupid' => $group_id,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.

		$deny = $this->deny_site( $site_id );
		try {
			$error = MainWP_Manage_Backups_Handler::authorize_task_scope( '', (string) $group_id );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_group', array( 'groupid' => $group_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup.
		}

		$this->assertNotSame( '', $error, 'A group containing a restricted site must refuse the write.' );
	}

	/**
	 * A site id that does not exist must refuse the write rather than be
	 * stored for the cron to chew on.
	 *
	 * @return void
	 */
	public function test_task_scope_refuses_unknown_site() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$this->assertNotSame( '', MainWP_Manage_Backups_Handler::authorize_task_scope( '999999321', '' ), 'A nonexistent site id must refuse the write.' );
	}

	/**
	 * The positive control: a caller with access to every listed site
	 * passes the scope gate.
	 *
	 * @return void
	 */
	public function test_task_scope_allows_accessible_sites() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_a = $this->create_test_site( array( 'userid' => $user ) );
		$site_b = $this->create_test_site( array( 'userid' => $user ) );

		$this->assertSame( '', MainWP_Manage_Backups_Handler::authorize_task_scope( $site_a . ',' . $site_b, '' ), 'Accessible sites must pass the scope gate.' );
	}

	/**
	 * A caller cannot run a backup task against a site that is not part of
	 * the task's own site/group scope, even in today's default single-user
	 * mode - this gate is a plain membership check independent of
	 * is_single_user().
	 *
	 * @return void
	 */
	public function test_backup_refuses_site_outside_task_scope() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$task_site  = $this->create_test_site( array( 'userid' => $user ) );
		$other_site = $this->create_test_site( array( 'userid' => $user ) );
		$task       = $this->create_task( $user, $task_site );

		$thrown = null;
		try {
			MainWP_Manage_Backups_Handler::backup( $task->id, $other_site, 'uid-scope-test' );
		} catch ( MainWP_Exception $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( MainWP_Exception::class, $thrown, 'A site outside the task scope must be refused.' );
		$this->assertStringContainsString( 'not part of', $thrown->getMessage(), 'The task-scope gate must be what refuses this call.' );
	}
}
