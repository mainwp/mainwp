<?php
/**
 * MainWP Backup Task Pause/Resume Authorization Tests
 *
 * Pausing and resuming a scheduled backup task acts on every site the task
 * covers. The handlers were gated only by can_edit_backuptask(), a no-op
 * under the default single-user mode, so a Team Control-restricted user
 * could pause or resume the backups of a site outside their scope. They
 * must gate on the same per-site access the run/reset/delete paths
 * enforce.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/**
 * Class MainWP_Backup_Pause_Resume_Auth_Test
 */
class MainWP_Backup_Pause_Resume_Auth_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Enable native backups so no unrelated kill switch interferes.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Clean fixture rows and the enablement option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		$backup_table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->query( "DELETE FROM {$backup_table} WHERE name LIKE 'Pause resume auth test%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.

		delete_option( 'mainwp_enableNativeBackups' );
		parent::tearDown();
	}

	/**
	 * Deny the per-site capability for one site id, simulating a Team
	 * Control site restriction.
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
	 * Create a backup task over one site.
	 *
	 * @param int $site_id Site id in the task's sites column.
	 *
	 * @return object Task row.
	 */
	private function create_task( $site_id ) {
		$task = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Pause resume auth test ' . uniqid(), 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'site', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		return $task;
	}

	/**
	 * A Team Control-restricted site refuses the pause: the task stays
	 * unpaused. This is the security gate.
	 *
	 * @return void
	 */
	public function test_pause_denies_site_restricted_user() {
		$this->skip_when_capabilities_bypassed();

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$task    = $this->create_task( $site_id );

		$deny = $this->deny_site( $site_id );
		try {
			$response = MainWP_Manage_Backups_Handler::pause_backup_result( $task->id );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}

		$this->assertArrayHasKey( 'notask', $response, 'A restricted site must refuse the pause.' );
		$this->assertSame( 0, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->paused, 'A refused pause must not flip the flag.' );
	}

	/**
	 * A Team Control-restricted site refuses the resume: the task stays
	 * paused. This is the security gate.
	 *
	 * @return void
	 */
	public function test_resume_denies_site_restricted_user() {
		$this->skip_when_capabilities_bypassed();

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$task    = $this->create_task( $site_id );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'paused' => 1 ) );

		$deny = $this->deny_site( $site_id );
		try {
			$response = MainWP_Manage_Backups_Handler::resume_backup_result( $task->id );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}

		$this->assertArrayHasKey( 'notask', $response, 'A restricted site must refuse the resume.' );
		$this->assertSame( 1, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->paused, 'A refused resume must not flip the flag.' );
	}

	/**
	 * The positive control: a caller with access to the task's site can
	 * pause it and then resume it.
	 *
	 * @return void
	 */
	public function test_pause_then_resume_allows_accessible_site() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$task    = $this->create_task( $site_id );

		$pause_response = MainWP_Manage_Backups_Handler::pause_backup_result( $task->id );
		$this->assertSame( 'SUCCESS', $pause_response['result'] ?? null, 'An accessible task must pause.' );
		$this->assertSame( 1, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->paused, 'A successful pause must flip the flag.' );

		$resume_response = MainWP_Manage_Backups_Handler::resume_backup_result( $task->id );
		$this->assertSame( 'SUCCESS', $resume_response['result'] ?? null, 'An accessible task must resume.' );
		$this->assertSame( 0, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->paused, 'A successful resume must flip the flag.' );
	}
}
