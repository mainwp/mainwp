<?php
/**
 * Scheduled-task authorization snapshot tests: the site set authorized at
 * save time is persisted on the task row, and the scheduled runner backs
 * up only that set - a site added to a task's group after the save is
 * skipped with a notice until an authorized re-save refreshes the
 * snapshot. Cron runs with no user context (the allow-list layer
 * early-returns under DOING_CRON), so write time is the only place the
 * per-site authorization can gate; this snapshot is what carries that
 * authorization to execution time.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/**
 * Class MainWP_Backup_Task_Scope_Snapshot_Test
 */
class MainWP_Backup_Task_Scope_Snapshot_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Group ids inserted by a test, cleaned in tear_down.
	 *
	 * @var int[]
	 */
	private $group_ids = array();

	/**
	 * Enable native backups so the kill switch does not mask the behavior
	 * under test.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Clean tasks and group fixtures.
	 */
	public function tear_down() {
		global $wpdb;
		foreach ( $this->group_ids as $group_id ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_group', array( 'groupid' => $group_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup.
		}
		$this->group_ids = array();
		delete_option( 'mainwp_enableNativeBackups' );
		parent::tear_down();
	}

	/**
	 * Put a site into a (numeric) group, tracking the group for cleanup.
	 *
	 * @param int $site_id  Site id.
	 * @param int $group_id Group id.
	 */
	private function add_site_to_group( $site_id, $group_id ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_group',
			array(
				'wpid'    => $site_id,
				'groupid' => $group_id,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		if ( ! in_array( $group_id, $this->group_ids, true ) ) {
			$this->group_ids[] = $group_id;
		}
	}

	/**
	 * Create a backup task over the given sites/groups selection.
	 *
	 * @param string $sites  Comma-separated site ids.
	 * @param string $groups Comma-separated group ids.
	 *
	 * @return object Task row.
	 */
	private function create_task( $sites, $groups ) {
		$task = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Scope snapshot test ' . uniqid(), 'daily', 'full', '', (string) $sites, (string) $groups, '', '', 0, 0, 0, 0, 0, 'site', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		return $task;
	}

	/**
	 * The expansion helper resolves direct sites and group members into one
	 * deduplicated id list - the same expansion authorize_task_scope()
	 * checks, so the snapshot is by construction the authorized set.
	 */
	public function test_expand_includes_direct_and_group_sites() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_a = $this->create_test_site( array( 'userid' => $user ) );
		$site_b = $this->create_test_site( array( 'userid' => $user ) );
		$this->add_site_to_group( $site_b, 515151 );

		$expanded = MainWP_Manage_Backups_Handler::expand_task_site_ids( (string) $site_a . ',' . $site_a, '515151' );

		$this->assertSame( array( (int) $site_a, (int) $site_b ), $expanded, 'Direct sites and group members must expand into one deduplicated list.' );
	}

	/**
	 * Stamping persists the expanded set on the task row.
	 */
	public function test_stamp_persists_authorized_snapshot() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_a = $this->create_test_site( array( 'userid' => $user ) );
		$this->add_site_to_group( $site_a, 525252 );
		$task = $this->create_task( '', '525252' );

		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( (string) $site_a, $fresh->authorized_sites, 'The stamped snapshot must persist the expanded, authorized site set.' );
	}

	/**
	 * The drift this work exists to stop: a site added to the task's group
	 * AFTER the save is not backed up - cron has no user context to
	 * authorize it against - and a notice tells the user to re-save.
	 */
	public function test_drifted_group_site_skipped_and_noticed() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_a = $this->create_test_site( array( 'userid' => $user ) );
		$this->add_site_to_group( $site_a, 535353 );
		$task = $this->create_task( '', '535353' );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );

		// The drift: a second site joins the group after the save.
		$site_b = $this->create_test_site( array( 'userid' => $user ) );
		$this->add_site_to_group( $site_b, 535353 );

		$task     = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$resolved = MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task );

		$this->assertContains( (int) $site_a, $resolved, 'The snapshot-authorized site still runs.' );
		$this->assertNotContains( (int) $site_b, $resolved, 'A site added to the group after the save must not run without an authorized re-save.' );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringContainsString( 'was added to a selected group after this task was last saved', (string) $fresh->backup_notices, 'The skipped site must be surfaced as a notice.' );
	}

	/**
	 * The drift notice is recorded once, not appended again by every
	 * continuation tick or scheduled run while the drift persists.
	 */
	public function test_drift_notice_not_duplicated() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_a = $this->create_test_site( array( 'userid' => $user ) );
		$this->add_site_to_group( $site_a, 545454 );
		$task = $this->create_task( '', '545454' );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );

		$site_b = $this->create_test_site( array( 'userid' => $user ) );
		$this->add_site_to_group( $site_b, 545454 );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, substr_count( (string) $fresh->backup_notices, 'was added to a selected group after this task was last saved' ), 'The drift notice must not accumulate across ticks.' );
	}

	/**
	 * An authorized re-save refreshes the snapshot, so the group's new
	 * member is included from the next run on.
	 */
	public function test_resave_refreshes_snapshot() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_a = $this->create_test_site( array( 'userid' => $user ) );
		$this->add_site_to_group( $site_a, 555555 );
		$task = $this->create_task( '', '555555' );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );

		$site_b = $this->create_test_site( array( 'userid' => $user ) );
		$this->add_site_to_group( $site_b, 555555 );

		// The re-save path re-runs authorize_task_scope under the real user
		// and stamps the fresh expansion.
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );

		$task     = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$resolved = MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task );

		$this->assertContains( (int) $site_b, $resolved, 'A re-saved task includes the group\'s new member.' );
	}

	/**
	 * A pre-migration row carries no snapshot: its first run adopts the
	 * live expansion (matching the pre-snapshot behavior at that instant)
	 * and stamps it, so the gate is live from then on.
	 */
	public function test_empty_snapshot_backfills_from_live_expansion() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_a = $this->create_test_site( array( 'userid' => $user ) );
		$site_b = $this->create_test_site( array( 'userid' => $user ) );
		$this->add_site_to_group( $site_a, 565656 );
		$this->add_site_to_group( $site_b, 565656 );
		$task = $this->create_task( '', '565656' );
		// No stamp: this row models a task saved before the column existed.

		$resolved = MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task );

		$this->assertContains( (int) $site_a, $resolved, 'A pre-migration task keeps its behavior on first run.' );
		$this->assertContains( (int) $site_b, $resolved, 'A pre-migration task keeps its behavior on first run.' );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertNotSame( '', (string) $fresh->authorized_sites, 'The first run stamps the snapshot so the gate is live afterwards.' );
	}

	/**
	 * A direct-sites task (no groups) is immutable after save and passes
	 * through unchanged - drift is a group phenomenon.
	 */
	public function test_direct_sites_task_unaffected() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_a = $this->create_test_site( array( 'userid' => $user ) );
		$task   = $this->create_task( (string) $site_a, '' );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );

		$task     = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$resolved = MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task );

		$this->assertSame( array( (int) $site_a ), $resolved, 'A direct-sites task resolves exactly its saved sites.' );
	}
}
