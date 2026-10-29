<?php
/**
 * Sprint F-2 authorization-cluster regression tests (Codex findings F1, F3,
 * F10): a task cannot be re-scoped through the update endpoint to launder the
 * delete gate, a legitimately-empty authorized snapshot never auto-authorizes
 * a later group member, and a stale reset never touches a drift-excluded site.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/**
 * Class MainWP_Backup_Scope_Launder_Guard_Test
 */
class MainWP_Backup_Scope_Launder_Guard_Test extends MainWP_Abilities_Test_Case {

	use Backup_Cancel_Transport_Guard;

	/**
	 * Group ids to clean.
	 *
	 * @var int[]
	 */
	private $group_ids = array();

	/**
	 * Enable native backups.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Clean fixtures.
	 */
	public function tear_down() {
		global $wpdb;
		foreach ( $this->group_ids as $g ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_group', array( 'groupid' => $g ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup.
		}
		$this->group_ids = array();
		delete_option( 'mainwp_enableNativeBackups' );
		parent::tear_down();
	}

	/**
	 * Deny the per-site capability for one site id.
	 *
	 * @param int $denied Denied site id.
	 *
	 * @return callable The filter, for removal.
	 */
	private function deny_site( $denied ) {
		$deny = function ( $allowed, $type, $cap ) use ( $denied ) {
			if ( 'site' === $type && (int) $cap === (int) $denied ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		return $deny;
	}

	/**
	 * Put a site in a numeric group.
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
	 * F1 entry layer: updating a task the caller cannot fully access - even to
	 * an in-scope replacement - is refused, so a victim's task cannot be
	 * re-scoped and then deleted to launder the per-site purge.
	 */
	public function test_update_refuses_when_pre_edit_scope_is_out_of_reach() {
		$this->skip_when_capabilities_bypassed();
		$victim = $this->create_test_site( array( 'userid' => 1 ) );
		$mine   = $this->create_test_site( array( 'userid' => 1 ) );
		$task   = MainWP_DB_Backup::instance()->add_backup_task( 1, 'victim task', 'daily', 'full', '', (string) $victim, '', '', '', 0, 0, 0, 0, 0, 'site', 0, 1, 150, 0, 0 );

		$deny = $this->deny_site( $victim );
		try {
			// The attacker can reach $mine but not the task's current $victim
			// site. Re-scoping to $mine must be refused on the pre-edit scope.
			$error = MainWP_Manage_Backups_Handler::authorize_task_update_scope( $task->sites, $task->groups, (string) $mine, '' );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}

		$this->assertNotSame( '', $error, 'Re-scoping a task whose current sites are out of reach must be refused.' );
	}

	/**
	 * F3: a task saved over an empty group stamps an empty snapshot, and a
	 * victim site added to that group later is NOT auto-authorized on the next
	 * run - empty means zero authorized sites, not "pre-migration, adopt live".
	 */
	public function test_empty_saved_snapshot_does_not_adopt_later_group_member() {
		$victim = $this->create_test_site( array( 'userid' => 1 ) );
		wp_set_current_user( 1 );

		// Save over an empty group: snapshot is stamped '' (authorized to none).
		$task = MainWP_DB_Backup::instance()->add_backup_task( 1, 'empty group task', 'daily', 'full', '', '', '818181', '', '', 0, 0, 0, 0, 0, 'site', 0, 1, 150, 0, 0 );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );

		// The victim joins the group after the save.
		$this->add_site_to_group( $victim, 818181 );

		$fresh    = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$resolved = MainWP_Manage_Backups_Handler::resolve_task_run_sites( $fresh );

		$this->assertNotContains( (int) $victim, $resolved, 'A site added to an empty-saved group must not be auto-authorized.' );
		$this->assertSame( array(), $resolved, 'An empty authorized snapshot runs no sites.' );
	}

	/**
	 * F3 backfill: a genuine pre-migration row (authorized_sites was never
	 * stamped) still adopts its live expansion once. Modeled by clearing the
	 * column to the pre-migration sentinel after a normal save.
	 */
	public function test_pre_migration_row_backfills_once() {
		global $wpdb;
		// Establish the post-9.0.2.22 nullable schema FIRST (a persistent test
		// DB may predate it). The ALTER's implicit commit must land before any
		// fixture insert: run after them, it makes the site/group/task rows
		// durable past this test's rollback and the leaked site breaks every
		// later empty-DB assertion in the Abilities suite.
		$wpdb->query( 'ALTER TABLE ' . $wpdb->prefix . 'mainwp_wp_backup MODIFY COLUMN authorized_sites text NULL' ); // phpcs:ignore WordPress.DB -- align the test DB with the migration.
		$site = $this->create_test_site( array( 'userid' => 1 ) );
		$this->add_site_to_group( $site, 828282 );
		wp_set_current_user( 1 );
		$task = MainWP_DB_Backup::instance()->add_backup_task( 1, 'legacy row', 'daily', 'full', '', '', '828282', '', '', 0, 0, 0, 0, 0, 'site', 0, 1, 150, 0, 0 );
		// Force the never-stamped sentinel (NULL) a genuine
		// pre-authorized_sites row carries.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . 'mainwp_wp_backup SET authorized_sites = NULL WHERE id = %d', $task->id ) ); // phpcs:ignore WordPress.DB -- test forces the pre-migration state.

		$fresh    = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$resolved = MainWP_Manage_Backups_Handler::resolve_task_run_sites( $fresh );

		$this->assertContains( (int) $site, $resolved, 'A never-stamped legacy row adopts its live expansion once.' );
	}

	/**
	 * F10: a stale reset only touches snapshot-authorized sites, so a site
	 * added to the group after save (excluded from runs) is never sent a
	 * cancel that the child would resolve against an unrelated backup.
	 */
	public function test_reset_site_set_excludes_drifted_site() {
		$authorized = $this->create_test_site( array( 'userid' => 1 ) );
		$drifted    = $this->create_test_site( array( 'userid' => 1 ) );
		$this->add_site_to_group( $authorized, 838383 );
		wp_set_current_user( 1 );
		$task = MainWP_DB_Backup::instance()->add_backup_task( 1, 'reset drift task', 'daily', 'full', '', '', '838383', '', '', 0, 0, 0, 0, 0, 'site', 0, 1, 150, 0, 0 );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );
		$this->add_site_to_group( $drifted, 838383 );

		$fresh       = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$reset_sites = MainWP_Manage_Backups_Handler::resolve_reset_task_site_ids( $fresh );

		$this->assertContains( (int) $authorized, $reset_sites, 'The authorized site is reset.' );
		$this->assertNotContains( (int) $drifted, $reset_sites, 'A drift-excluded site must never be reset.' );
	}

	/**
	 * F1 delete guard: a restricted user cannot delete a task that has
	 * HISTORICAL snapshots for a site now outside its declared scope - the
	 * purge would otherwise destroy backups for a site they cannot reach.
	 * The task and its snapshots survive.
	 */
	public function test_delete_refused_when_historical_site_is_out_of_reach() {
		$this->skip_when_capabilities_bypassed();
		global $wpdb;

		$in_scope = $this->create_test_site( array( 'userid' => 1 ) );
		$victim   = $this->create_test_site( array( 'userid' => 1 ) );

		// The task currently declares only the in-scope site, but it ran on
		// the victim site earlier (a snapshot row records that history).
		$task = MainWP_DB_Backup::instance()->add_backup_task( 1, 'history delete', 'daily', 'full', '', (string) $in_scope, '', '', '', 0, 0, 0, 0, 0, 'site', 0, 1, 150, 0, 0 );
		$snap = $wpdb->prefix . 'mainwp_wp_backup_snapshot';
		$wpdb->insert(
			$snap,
			array(
				'task_id'       => $task->id,
				'site_id'       => $victim,
				'type'          => 'full',
				'created'       => time(),
				'archives'      => wp_json_encode( array() ),
				'manifest_path' => '',
				'status'        => 'complete',
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- historical-run fixture.

		$deny = $this->deny_site( $victim );
		try {
			$result = MainWP_Manage_Backups_Handler::remove_backup_result( $task->id );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
			$wpdb->delete( $snap, array( 'task_id' => $task->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cleanup.
		}

		$this->assertArrayHasKey( 'error', $result, 'Deleting a task with an out-of-reach historical site must be refused.' );
		$this->assertNotNull( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ), 'The refused task must survive.' );
	}
}
