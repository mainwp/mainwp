<?php
/**
 * Test-restore scheduler (sampling lane) tests.
 *
 * The lane turns the Stage-1 verified-restore engine into a periodic sample:
 * each tick it picks the newest complete snapshot per site that is overdue,
 * claims it atomically so overlapping ticks never double-run one snapshot, and
 * test-restores up to one per tick. A host that cannot host a sandbox is probed
 * once and skipped with an operator notice rather than retried; an unresolvable
 * snapshot is backed off, not looped; and a backlog drains through a
 * continuation rather than a single burst.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_TestRestore_Driver;
use MainWP\Dashboard\MainWP_Backup_TestRestore_Scheduler;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;

/**
 * Scheduler with the engine and the cron continuation stubbed, so selection,
 * claiming and continuation are observable without a real sandbox.
 */
class Testable_TestRestore_Scheduler extends MainWP_Backup_TestRestore_Scheduler {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

	/**
	 * Snapshot ids handed to the engine, in order.
	 *
	 * @var int[]
	 */
	public $verified = array();

	/**
	 * Deadlines handed to the engine alongside each snapshot id, in order.
	 *
	 * @var float[]
	 */
	public $deadlines = array();

	/**
	 * Continuation kicks counted instead of scheduling real cron.
	 *
	 * @var int
	 */
	public $continuation_count = 0;

	/**
	 * Result the stub engine returns.
	 *
	 * @var array
	 */
	public $verify_result = array(
		'state'      => 'pass',
		'error_code' => '',
	);

	/**
	 * Capability override: null uses the real cached probe.
	 *
	 * @var bool|null
	 */
	public $stub_capable = true;

	/**
	 * Record and stub.
	 *
	 * @param int   $snapshot_id Snapshot id.
	 * @param float $deadline    Absolute microtime(true) deadline for this tick's budget.
	 *
	 * @return array
	 */
	protected function verify( int $snapshot_id, float $deadline ): array {
		$this->verified[]  = $snapshot_id;
		$this->deadlines[] = $deadline;
		return $this->verify_result;
	}

	/**
	 * Count instead of scheduling.
	 *
	 * @return void
	 */
	protected function schedule_continuation(): void {
		++$this->continuation_count;
	}

	/**
	 * Real probe when stub_capable is null, otherwise the pinned verdict.
	 *
	 * @param int $now Current epoch.
	 *
	 * @return bool
	 */
	protected function ensure_capable( int $now ): bool {
		return null === $this->stub_capable ? parent::ensure_capable( $now ) : $this->stub_capable;
	}
}

/**
 * Class MainWP_Backup_TestRestore_Scheduler_Test
 */
class MainWP_Backup_TestRestore_Scheduler_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Inserted snapshot ids for cleanup.
	 *
	 * @var int[]
	 */
	private $snapshot_ids = array();

	/**
	 * Scratch dir for end-to-end fixtures.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Enable native backups and a scratch dir.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->dir = sys_get_temp_dir() . '/mainwp-tr-sched-' . uniqid();
		mkdir( $this->dir );
	}

	/**
	 * Drop inserted snapshots, options, scratch dir, and any scheduled event.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;
		if ( ! empty( $this->snapshot_ids ) ) {
			$ids = implode( ',', array_map( 'intval', $this->snapshot_ids ) );
			$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . " WHERE id IN ( {$ids} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		}
		$this->rrmdir( $this->dir );
		delete_option( MainWP_Backup_TestRestore_Scheduler::CAPABILITY_OPTION );
		delete_option( 'mainwp_enableNativeBackups' );
		wp_clear_scheduled_hook( MainWP_Backup_TestRestore_Scheduler::CONTINUE_HOOK );
		parent::tear_down();
	}

	/**
	 * Recursive cleanup.
	 *
	 * @param string $dir Directory.
	 *
	 * @return void
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Insert a snapshot row directly so created/status/test_restore_at are
	 * exact, and remember it for cleanup.
	 *
	 * @param int    $site_id         Site id.
	 * @param int    $created         Created epoch.
	 * @param int    $test_restore_at Last test-restore epoch (0 = never).
	 * @param string $status          Lifecycle status.
	 * @param array  $archives        Archive entries.
	 *
	 * @return int Snapshot id.
	 */
	private function insert_snapshot( $site_id, $created, $test_restore_at = 0, $status = 'complete', array $archives = array() ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'task_id'         => 0,
				'site_id'         => $site_id,
				'type'            => 'db',
				'created'         => $created,
				'archives'        => wp_json_encode( $archives ),
				'manifest_path'   => '',
				'status'          => $status,
				'test_restore_at' => $test_restore_at,
			)
		);
		$id                   = (int) $wpdb->insert_id;
		$this->snapshot_ids[] = $id;
		return $id;
	}

	/**
	 * A two-row sentinel dump.
	 *
	 * @return string
	 */
	private function sentinel_sql() {
		return "CREATE TABLE wp_tr_sentinel (\n  id INT NOT NULL,\n  name VARCHAR(50) NOT NULL,\n  PRIMARY KEY (id)\n);\n"
			. "INSERT INTO wp_tr_sentinel (id, name) VALUES (1, 'alpha'), (2, 'beta');\n";
	}

	/**
	 * Write a gzipped dump fixture and return its path.
	 *
	 * @param string $name Basename.
	 * @param string $sql  SQL body.
	 *
	 * @return string
	 */
	private function write_db_dump( $name, $sql ) {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, gzencode( $sql ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		return $path;
	}

	/**
	 * The candidate query returns the newest complete snapshot per site and
	 * skips a site whose newest was test-restored inside the window.
	 *
	 * @return void
	 */
	public function test_candidates_are_newest_complete_per_site_and_skip_recent() {
		$now    = time();
		$site_a = $this->create_test_site();
		$site_b = $this->create_test_site();

		$a_old         = $this->insert_snapshot( $site_a, $now - 300 );
		$a_new         = $this->insert_snapshot( $site_a, $now - 100 );
		$b_recent_site = $this->insert_snapshot( $site_b, $now - 100, $now - 60 ); // tested a minute ago.

		$candidates = MainWP_Backup_Snapshots::instance()->get_test_restore_candidates( 10, $now - MainWP_Backup_TestRestore_Scheduler::CADENCE_SECONDS );
		$ids        = array_map(
			static function ( $row ) {
				return (int) $row->id;
			},
			$candidates
		);

		$this->assertContains( $a_new, $ids, 'The newest complete snapshot for a due site must be a candidate.' );
		$this->assertNotContains( $a_old, $ids, 'An older snapshot of the same site must not be a candidate.' );
		$this->assertNotContains( $b_recent_site, $ids, 'A site test-restored inside the window must be skipped.' );
	}

	/**
	 * The claim is a compare-and-swap: the first caller wins, a second caller
	 * on the same cutoff loses.
	 *
	 * @return void
	 */
	public function test_claim_is_compare_and_swap() {
		$now    = time();
		$cutoff = $now - MainWP_Backup_TestRestore_Scheduler::CADENCE_SECONDS;
		$id     = $this->insert_snapshot( $this->create_test_site(), $now - 100 );

		$first  = MainWP_Backup_Snapshots::instance()->claim_for_test_restore( $id, $now, $cutoff );
		$second = MainWP_Backup_Snapshots::instance()->claim_for_test_restore( $id, $now, $cutoff );

		$this->assertTrue( $first, 'The first claim must win.' );
		$this->assertFalse( $second, 'A second claim on the same cutoff must lose.' );
	}

	/**
	 * One tick test-restores at most one snapshot and never the older snapshot
	 * of a site.
	 *
	 * @return void
	 */
	public function test_samples_one_per_tick_newest_only() {
		$now   = time();
		$a_old = $this->insert_snapshot( $this->create_test_site(), $now - 300 );
		$this->insert_snapshot( $this->create_test_site(), $now - 100 );

		$scheduler = new Testable_TestRestore_Scheduler();
		$scheduler->process_pending_jobs( 120, $now );

		$this->assertCount( 1, $scheduler->verified, 'At most one snapshot is test-restored per tick.' );
		$this->assertNotContains( $a_old, $scheduler->verified, 'The older snapshot of a site is never sampled.' );
	}

	/**
	 * A claimed snapshot is not re-selected on the next tick at the same clock.
	 *
	 * @return void
	 */
	public function test_claimed_snapshot_backs_off_no_reselect() {
		$now = time();
		$this->insert_snapshot( $this->create_test_site(), $now - 100 );

		$scheduler = new Testable_TestRestore_Scheduler();
		$scheduler->process_pending_jobs( 120, $now );
		$scheduler->process_pending_jobs( 120, $now );

		$this->assertCount( 1, $scheduler->verified, 'A claimed snapshot must not be sampled twice.' );
	}

	/**
	 * An unresolvable snapshot is recorded as unavailable (not a fail) and
	 * backed off so it does not loop.
	 *
	 * @return void
	 */
	public function test_unresolvable_records_unavailable_and_backs_off() {
		$now = time();
		$id  = $this->insert_snapshot( $this->create_test_site(), $now - 100 );

		$scheduler                = new Testable_TestRestore_Scheduler();
		$scheduler->verify_result = array(
			'state'      => 'fail',
			'error_code' => MainWP_Backup_TestRestore_Driver::ERR_UNRESOLVABLE,
		);

		$scheduler->process_pending_jobs( 120, $now );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertSame( MainWP_Backup_TestRestore_Scheduler::STATE_UNAVAILABLE, (string) $snapshot->test_restore_state, 'An unresolvable chain records as unavailable, not fail.' );
		$this->assertGreaterThan( 0, (int) $snapshot->test_restore_at, 'The unresolvable run still stamps a backoff time.' );

		$scheduler->process_pending_jobs( 120, $now );
		$this->assertCount( 1, $scheduler->verified, 'An unavailable snapshot must not be re-selected next tick.' );
	}

	/**
	 * The seam receives an absolute deadline derived from the tick's own
	 * budget, so a slow verify cannot run past the slice this tick owns.
	 *
	 * @return void
	 */
	public function test_verify_receives_a_deadline_consistent_with_the_tick_budget() {
		$now = time();
		$this->insert_snapshot( $this->create_test_site(), $now - 100 );

		$scheduler = new Testable_TestRestore_Scheduler();
		$before    = microtime( true );
		$scheduler->process_pending_jobs( 120, $now );
		$after = microtime( true );

		$this->assertCount( 1, $scheduler->deadlines, 'The seam must receive exactly one deadline for the one snapshot sampled.' );
		$deadline = $scheduler->deadlines[0];
		$this->assertGreaterThanOrEqual( $before + 120, $deadline, 'The deadline must reflect the full tick budget measured from before the call.' );
		$this->assertLessThanOrEqual( $after + 120, $deadline, 'The deadline must not exceed the tick budget measured after the call.' );
	}

	/**
	 * A deadline miss inside the engine is recorded as unavailable (not a
	 * fail) and backed off so it does not loop within the same tick.
	 *
	 * @return void
	 */
	public function test_deadline_exceeded_records_unavailable_and_backs_off() {
		$now = time();
		$id  = $this->insert_snapshot( $this->create_test_site(), $now - 100 );

		$scheduler                = new Testable_TestRestore_Scheduler();
		$scheduler->verify_result = array(
			'state'      => 'fail',
			'error_code' => MainWP_Backup_TestRestore_Driver::ERR_DEADLINE_EXCEEDED,
		);

		$scheduler->process_pending_jobs( 120, $now );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertSame( MainWP_Backup_TestRestore_Scheduler::STATE_UNAVAILABLE, (string) $snapshot->test_restore_state, 'A deadline miss records as unavailable, not fail.' );
		$this->assertGreaterThan( 0, (int) $snapshot->test_restore_at, 'The deadline-missed run still stamps a backoff time so it does not loop within the tick.' );

		$scheduler->process_pending_jobs( 120, $now );
		$this->assertCount( 1, $scheduler->verified, 'A deadline-missed snapshot must not be re-selected within the same tick window.' );
	}

	/**
	 * A host that cannot host a sandbox is skipped without sampling, and the
	 * operator notice is set.
	 *
	 * @return void
	 */
	public function test_unsupported_host_skips_and_sets_notice() {
		update_option(
			MainWP_Backup_TestRestore_Scheduler::CAPABILITY_OPTION,
			array(
				'capable'    => false,
				'reason'     => 'no CREATE privilege',
				'checked_at' => time(),
			),
			false
		);

		$this->insert_snapshot( $this->create_test_site(), time() - 100 );

		$scheduler               = new Testable_TestRestore_Scheduler();
		$scheduler->stub_capable = null; // Use the real cached verdict.
		$scheduler->process_pending_jobs( 120, time() );

		$this->assertCount( 0, $scheduler->verified, 'An incapable host samples nothing.' );
		$this->assertNotSame( '', MainWP_Backup_TestRestore_Scheduler::instance()->get_capability_notice(), 'An incapable host surfaces an operator notice.' );
	}

	/**
	 * A backlog (more candidates than fit in a tick) schedules a continuation;
	 * a single candidate does not.
	 *
	 * @return void
	 */
	public function test_continuation_scheduled_only_on_backlog() {
		$now = time();
		$this->insert_snapshot( $this->create_test_site(), $now - 100 );
		$this->insert_snapshot( $this->create_test_site(), $now - 100 );

		// Two due snapshots, one processed per tick: a backlog remains.
		$first = new Testable_TestRestore_Scheduler();
		$first->process_pending_jobs( 120, $now );
		$this->assertSame( 1, $first->continuation_count, 'A backlog beyond one-per-tick must schedule a continuation.' );

		// The first tick claimed one snapshot; only one due remains now.
		$second = new Testable_TestRestore_Scheduler();
		$second->process_pending_jobs( 120, $now );
		$this->assertCount( 1, $second->verified, 'The remaining due snapshot is sampled.' );
		$this->assertSame( 0, $second->continuation_count, 'With no backlog left, no continuation is scheduled.' );
	}

	/**
	 * End to end: the real lane probes the host, test-restores a good local
	 * snapshot through the real driver, and records a pass on the row.
	 *
	 * @return void
	 */
	public function test_end_to_end_real_driver_records_pass() {
		$dump = $this->write_db_dump( 'db.sql.gz', $this->sentinel_sql() );

		$id                   = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => 0,
				'site_id'       => $this->create_test_site(),
				'type'          => 'db',
				'parent_id'     => null,
				'archives'      => array(
					array(
						'file' => 'db.sql.gz',
						'size' => filesize( $dump ),
						'role' => 'db',
						'path' => $dump,
					),
				),
				'manifest_path' => '',
			)
		);
		$this->snapshot_ids[] = $id;

		MainWP_Backup_TestRestore_Scheduler::instance()->process_pending_jobs( 120, time() );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertSame( 'pass', (string) $snapshot->test_restore_state, 'The real lane records a pass for a good snapshot.' );
		$this->assertGreaterThan( 0, (int) $snapshot->test_restore_at, 'The real lane records a test-restore time.' );
	}

	/**
	 * The continuation hook is a single event, never registered as a recurring
	 * cron job (WordPress would de-duplicate the single event against it).
	 *
	 * @return void
	 */
	public function test_continuation_hook_is_not_recurring() {
		$jobs = MainWP_System_Cron_Jobs::instance()->get_cron_jobs();
		$this->assertArrayNotHasKey( MainWP_Backup_TestRestore_Scheduler::CONTINUE_HOOK, $jobs, 'The continuation hook must not be a recurring cron job.' );
	}

	/**
	 * The immediate-kick handler does nothing while native backups are off.
	 *
	 * @return void
	 */
	public function test_continue_handler_gated_on_native_backups() {
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		$id = $this->insert_snapshot( $this->create_test_site(), time() - 100 );

		MainWP_System_Cron_Jobs::instance()->cron_testrestore_continue();

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertSame( '', (string) $snapshot->test_restore_state, 'A gated lane records nothing.' );
	}

	/**
	 * The five-minute backup-continue lane samples on the common tick that has
	 * no legacy task to continue - the sampler dispatch must sit before that
	 * early return, not after it.
	 *
	 * @return void
	 */
	public function test_backups_continue_samples_on_empty_tasks_tick() {
		$dump = $this->write_db_dump( 'db.sql.gz', $this->sentinel_sql() );

		$id                   = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => 0,
				'site_id'       => $this->create_test_site(),
				'type'          => 'db',
				'parent_id'     => null,
				'archives'      => array(
					array(
						'file' => 'db.sql.gz',
						'size' => filesize( $dump ),
						'role' => 'db',
						'path' => $dump,
					),
				),
				'manifest_path' => '',
			)
		);
		$this->snapshot_ids[] = $id;

		// No legacy backup tasks exist, so cron_backups_continue() hits its
		// empty-tasks early return; the sampler must still have run by then.
		MainWP_System_Cron_Jobs::instance()->cron_backups_continue();

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertSame( 'pass', (string) $snapshot->test_restore_state, 'The sampler must run on the five-minute lane even when no legacy task is pending.' );
	}
}
