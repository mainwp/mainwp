<?php
/**
 * Scheduled-task owner-scope pause tests: a task whose owner's live Team
 * Control authorization no longer covers its run set is paused before any
 * site work, run claim, or notification - revoking access revokes the
 * schedule, not just the UI. The owner's live scope is resolved without
 * request context by applying the Team Control allowed-sites filter under
 * a temporarily-set current user, which sidesteps the DOING_CRON
 * short-circuit inside mainwp_current_user_have_right() - these tests
 * therefore need no capability-bypass skip guard.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/**
 * Class MainWP_Backup_Task_Owner_Scope_Pause_Test
 */
class MainWP_Backup_Task_Owner_Scope_Pause_Test extends MainWP_Abilities_Test_Case {

	use Backup_Cancel_Transport_Guard;

	/**
	 * A resave restoring scope must win over a stale caller's pause decision.
	 */
	public function test_rescope_pause_rechecks_reloaded_task() {
		$owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_a = $this->create_test_site( array( 'userid' => $owner ) );
		$site_b = $this->create_test_site( array( 'userid' => $owner ) );
		$task   = $this->create_task( $owner, $site_a . ',' . $site_b );
		$db     = MainWP_DB_Backup::instance();
		$db->update_backup_task_with_values(
			$task->id,
			array(
				'authorized_sites' => $task->sites,
				'last_run'         => 100,
				'completed'        => 50,
			)
		);
		$this->restrict_user_sites( $owner, array( $site_a ) );
		$stale = $db->get_backup_task_by_id( $task->id );
		$this->assertSame( 'narrowed', MainWP_Manage_Backups_Handler::resolve_task_scope_pause( $stale ) );
		$db->update_backup_task_with_values(
			$task->id,
			array(
				'sites'            => (string) $site_a,
				'authorized_sites' => (string) $site_a,
			)
		);
		MainWP_Manage_Backups_Handler::pause_task_for_rescope( $stale );
		$fresh = $db->get_backup_task_by_id( $task->id );
		$this->assertSame( 0, (int) $fresh->paused );
		$this->assertSame( 50, (int) $fresh->completed );
		$this->assertEmpty( $fresh->backup_notices );
	}

	/**
	 * Group ids inserted by a test, cleaned in tear_down.
	 *
	 * @var int[]
	 */
	private $group_ids = array();

	/**
	 * Owner-scope filters added by a test, removed in tear_down.
	 *
	 * @var callable[]
	 */
	private $scope_filters = array();

	/**
	 * Enable native backups so the kill switch does not mask the behavior
	 * under test.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Clean group fixtures, scope filters, and options.
	 */
	public function tear_down() {
		global $wpdb;
		foreach ( $this->group_ids as $group_id ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_group', array( 'groupid' => $group_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup.
		}
		$this->group_ids = array();
		foreach ( $this->scope_filters as $filter ) {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $filter, 10 );
		}
		$this->scope_filters = array();
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_notificationOnBackupStart' );
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
	 * Create a backup task owned by the given user.
	 *
	 * @param int    $owner_id Owner user id.
	 * @param string $sites    Comma-separated site ids.
	 * @param string $groups   Comma-separated group ids.
	 *
	 * @return object Task row.
	 */
	private function create_task( $owner_id, $sites, $groups = '' ) {
		$task = MainWP_DB_Backup::instance()->add_backup_task( $owner_id, 'Owner scope pause test ' . uniqid(), 'daily', 'full', '', (string) $sites, (string) $groups, '', '', 0, 0, 0, 0, 0, 'site', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		return $task;
	}

	/**
	 * Restrict one user's Team-Control-allowed sites via the same filter
	 * the extension registers, keyed to the current user the way the real
	 * callback resolves it. Any other user stays unrestricted.
	 *
	 * @param int   $user_id Restricted user id.
	 * @param int[] $allowed Allowed site ids for that user.
	 *
	 * @return void
	 */
	private function restrict_user_sites( $user_id, array $allowed ) {
		$filter = function ( $default_sites ) use ( $user_id, $allowed ) {
			if ( get_current_user_id() === (int) $user_id ) {
				return array_map( 'intval', $allowed );
			}
			return $default_sites;
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $filter );
		$this->scope_filters[] = $filter;
	}

	/**
	 * With no Team Control filter registered, every owner is unrestricted
	 * - the fleet-wide single-user install must never see a pause.
	 */
	public function test_owner_without_team_control_is_unrestricted() {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertNull( MainWP_Manage_Backups_Handler::resolve_owner_allowed_site_ids( $owner ), 'No allowed-sites filter means unrestricted.' );
	}

	/**
	 * The resolver reads the OWNER's scope, not the caller's, and restores
	 * the current user afterward - cron has no user context and the swap
	 * must not leak into whatever runs next in the same request.
	 */
	public function test_resolver_reads_owner_scope_and_restores_current_user() {
		$owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_a = $this->create_test_site( array( 'userid' => $owner ) );

		$this->restrict_user_sites( $owner, array( (int) $site_a ) );

		wp_set_current_user( $other );
		$allowed = MainWP_Manage_Backups_Handler::resolve_owner_allowed_site_ids( $owner );

		$this->assertSame( array( (int) $site_a ), $allowed, 'The resolver must return the owner\'s allowed set while another user is current.' );
		$this->assertSame( $other, get_current_user_id(), 'The current user must be restored after the swap.' );
	}

	/**
	 * A deleted or never-valid owner resolves to no authority at all: an
	 * ownerless row must not keep executing unrestricted forever.
	 */
	public function test_unresolvable_owner_has_no_authority() {
		$this->assertSame( array(), MainWP_Manage_Backups_Handler::resolve_owner_allowed_site_ids( 999999321 ), 'A nonexistent owner must resolve to an empty allowed set.' );
		$this->assertSame( array(), MainWP_Manage_Backups_Handler::resolve_owner_allowed_site_ids( 0 ), 'A zero owner id must resolve to an empty allowed set.' );
	}

	/**
	 * A restricted owner whose live scope still covers the whole run set
	 * is not paused - narrowing is the trigger, not restriction itself.
	 */
	public function test_restricted_owner_covering_run_set_is_not_paused() {
		$owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_a = $this->create_test_site( array( 'userid' => $owner ) );
		wp_set_current_user( $owner );
		$task = $this->create_task( $owner, (string) $site_a );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );

		$this->restrict_user_sites( $owner, array( (int) $site_a ) );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertNull( MainWP_Manage_Backups_Handler::resolve_task_scope_pause( $task ), 'An owner still covering the run set must not pause the task.' );
	}

	/**
	 * The revocation case M9 names: the owner's scope narrows below the
	 * stamped run set after save, and the scheduled entry point pauses the
	 * task before any run claim, site operation, progress row, or
	 * notification. "Revoke owner before cron -> no operation, progress,
	 * or notification."
	 */
	public function test_narrowed_owner_pauses_task_with_no_operation_or_notification() {
		global $wpdb;

		$owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_a = $this->create_test_site( array( 'userid' => $owner ) );
		$site_b = $this->create_test_site( array( 'userid' => $owner ) );
		wp_set_current_user( $owner );
		$task = $this->create_task( $owner, $site_a . ',' . $site_b );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );
		$site_b_url = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_b )->url;

		// The revocation: site_b leaves the owner's live scope after save.
		$this->restrict_user_sites( $owner, array( (int) $site_a ) );

		// Arm the start-notification path to prove the pause beats it.
		update_option( 'mainwp_notificationOnBackupStart', 1 );
		$mail_calls = 0;
		$mail_spy   = function () use ( &$mail_calls ) {
			++$mail_calls;
			return false;
		};
		add_filter( 'pre_wp_mail', $mail_spy );

		try {
			$task   = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$result = MainWP_Manage_Backups_Handler::execute_backup_task( $task, 0, true );
		} finally {
			remove_filter( 'pre_wp_mail', $mail_spy );
		}

		$this->assertFalse( $result, 'A paused-on-narrowing task must not execute.' );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, (int) $fresh->paused, 'The task must be paused, not narrowed or run.' );
		$this->assertSame( 0, (int) $fresh->last_run, 'No run may be claimed for a paused-on-narrowing task.' );
		$this->assertSame( 0, (int) $fresh->lastStartNotificationSent, 'No start notification may be recorded.' );
		$this->assertSame( 0, $mail_calls, 'No email of any kind may be sent for the paused run.' );

		$progress_rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mainwp_wp_backup_progress WHERE task_id = %d', $task->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test assertion.
		$this->assertSame( 0, $progress_rows, 'No per-site progress row may be written.' );

		$this->assertStringContainsString( 'paused', (string) $fresh->backup_notices, 'The pause must be surfaced as a task notice.' );
		$this->assertStringNotContainsString( $site_b_url, (string) $fresh->backup_notices, 'The notice must not name a site the owner can no longer access.' );
	}

	/**
	 * The migration boundary: a pre-snapshot (NULL-sentinel) row owned by
	 * a restricted owner pauses for re-save instead of stamping its live
	 * group expansion - the backfill must never grant per-site authority
	 * no one verified. The out-of-scope group member is never executed and
	 * never stamped.
	 */
	public function test_legacy_null_snapshot_with_restricted_owner_pauses_without_stamping() {
		$owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_a = $this->create_test_site( array( 'userid' => $owner ) );
		$site_b = $this->create_test_site( array( 'userid' => $owner ) );
		wp_set_current_user( $owner );
		$this->add_site_to_group( $site_a, 616161 );
		$task = $this->create_task( $owner, '', '616161' );
		// No stamp: this row models a task saved before the column existed.

		// The group gains a site outside the owner's scope after save.
		$this->add_site_to_group( $site_b, 616161 );
		$this->restrict_user_sites( $owner, array( (int) $site_a ) );

		$task   = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$result = MainWP_Manage_Backups_Handler::execute_backup_task( $task, 0, true );

		$this->assertFalse( $result, 'A legacy row with a restricted owner must pause, not adopt its live expansion.' );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, (int) $fresh->paused, 'The legacy task must pause for an authorized re-save.' );
		$this->assertNull( $fresh->authorized_sites, 'The backfill stamp must never happen for a restricted owner.' );
	}

	/**
	 * A legacy NULL-sentinel row with an unrestricted owner keeps today's
	 * adopt-and-stamp behavior: pausing every pre-upgrade task on installs
	 * without Team Control would break scheduled backups fleet-wide for
	 * zero security gain.
	 */
	public function test_legacy_null_snapshot_with_unrestricted_owner_is_not_paused() {
		$owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_a = $this->create_test_site( array( 'userid' => $owner ) );
		wp_set_current_user( $owner );
		$this->add_site_to_group( $site_a, 626262 );
		$task = $this->create_task( $owner, '', '626262' );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertNull( MainWP_Manage_Backups_Handler::resolve_task_scope_pause( $task ), 'An unrestricted owner keeps the existing backfill behavior.' );
	}

	/**
	 * The pause notice is recorded once, not appended again when the
	 * paused condition is re-evaluated (a resume without re-save simply
	 * re-pauses at the next tick).
	 */
	public function test_pause_notice_not_duplicated() {
		$owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_a = $this->create_test_site( array( 'userid' => $owner ) );
		wp_set_current_user( $owner );
		$task = $this->create_task( $owner, (string) $site_a );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );

		$this->restrict_user_sites( $owner, array() );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 0, true );
		// The re-pause after a resume-without-re-save.
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'paused' => 0 ) );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 0, true );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, (int) $fresh->paused, 'The task must re-pause after a resume without re-save.' );
		$this->assertSame( 1, substr_count( (string) $fresh->backup_notices, 'paused' ), 'The pause notice must not accumulate across ticks.' );
	}

	/**
	 * The on-demand hidden task stamps its single site at create time, so
	 * it never trips the legacy-row pause: its site list is by
	 * construction what the authorized AJAX initiator requested.
	 */
	public function test_on_demand_task_is_stamped_at_create() {
		$owner   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_id = $this->create_test_site( array( 'userid' => $owner ) );
		$website = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );

		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );

		$this->assertNotFalse( $task, 'Precondition: the on-demand task must be created.' );
		$this->assertSame( (string) $site_id, (string) $task->authorized_sites, 'The on-demand task must stamp its single site at create time.' );
	}
}
