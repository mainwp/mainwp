<?php
/**
 * Restore job store tests: lifecycle, the atomic claim, backoff, and
 * the one-active-job-per-site rule.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Install;

/**
 * Class MainWP_Backup_Restore_Job_Store_Test
 */
class MainWP_Backup_Restore_Job_Store_Test extends \WP_UnitTestCase {

	/**
	 * Store under test.
	 *
	 * @var MainWP_Backup_Restore_Job_Store
	 */
	private $store;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->store = MainWP_Backup_Restore_Job_Store::instance();
	}

	/**
	 * Clean the table.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) ); // phpcs:ignore WordPress.DB -- test cleanup.
		parent::tear_down();
	}

	/**
	 * A job round-trips with its checkpoint decoded.
	 */
	public function test_job_created_with_pending_status() {
		$job_id = $this->store->create_job( 8801, 42, array( 'restore_id' => 'abc123def456' ) );
		$this->assertGreaterThan( 0, $job_id );

		$job = $this->store->get_job( $job_id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PENDING, $job->status );
		$this->assertSame( 8801, (int) $job->site_id );
		$this->assertSame( 42, (int) $job->snapshot_id );
		$this->assertSame( 'abc123def456', $job->checkpoint['restore_id'] );
	}

	/**
	 * A cancel intent written from outside the lease (the UI's
	 * unfenced busy-cancel write) is sticky - a later checkpoint save
	 * built from a stale in-memory copy that lacks the flag must carry
	 * it forward instead of silently erasing the acknowledged cancel.
	 */
	public function test_save_checkpoint_preserves_stored_cancel_intent() {
		$job_id = $this->store->create_job( 8801, 42, array( 'restore_id' => 'cancelkeep01' ) );

		$stale = $this->store->get_job( $job_id )->checkpoint;

		// The UI records the intent unfenced.
		$this->assertTrue( $this->store->arm_cancel_intent( $job_id ) );

		// A worker saves phase data from its stale pre-cancel copy.
		$stale['transfer'] = array( 'cursor' => 3 );
		$this->assertTrue( $this->store->save_checkpoint( $job_id, $stale ) );

		$row = $this->store->get_job( $job_id );
		$this->assertTrue( ! empty( $row->checkpoint['cancel_intent'] ), 'The stale save must carry the stored cancel intent forward, not erase it.' );
		$this->assertSame( 3, $row->checkpoint['transfer']['cursor'], 'The phase data itself still lands.' );
	}

	/**
	 * Only the explicit clear flag (the applied-cancel path) may drop a
	 * stored cancel intent.
	 */
	public function test_save_checkpoint_explicit_clear_drops_cancel_intent() {
		$job_id = $this->store->create_job( 8801, 42, array( 'restore_id' => 'cancelclear1' ) );

		$this->assertTrue( $this->store->arm_cancel_intent( $job_id ) );

		$cleared = $this->store->get_job( $job_id )->checkpoint;
		$this->assertTrue( $this->store->save_checkpoint( $job_id, $cleared, null, true ) );

		$row = $this->store->get_job( $job_id );
		$this->assertTrue( empty( $row->checkpoint['cancel_intent'] ), 'The explicit clear must drop the applied intent.' );
	}

	/**
	 * Arming preserves every other checkpoint key (the swap injects the
	 * flag into the current row, never overwrites it with an older copy)
	 * and is idempotent; a missing job refuses.
	 */
	public function test_arm_cancel_intent_injects_without_clobbering() {
		$job_id = $this->store->create_job( 8801, 42, array( 'restore_id' => 'cancelarm001' ) );

		// A worker's newer checkpoint lands first.
		$fresh             = $this->store->get_job( $job_id )->checkpoint;
		$fresh['transfer'] = array( 'cursor' => 7 );
		$this->store->save_checkpoint( $job_id, $fresh );

		$this->assertTrue( $this->store->arm_cancel_intent( $job_id ) );
		$this->assertTrue( $this->store->arm_cancel_intent( $job_id ), 'Arming an already-armed job is idempotent.' );

		$row = $this->store->get_job( $job_id );
		$this->assertTrue( ! empty( $row->checkpoint['cancel_intent'] ) );
		$this->assertSame( 7, $row->checkpoint['transfer']['cursor'], 'Arming must not revert the worker\'s newer checkpoint.' );
		$this->assertSame( 'cancelarm001', $row->checkpoint['restore_id'] );

		$this->assertFalse( $this->store->arm_cancel_intent( 999999999 ), 'A missing job cannot be armed.' );
	}

	/**
	 * The claim is exclusive: a second token gets nothing while the
	 * first holds the lock.
	 */
	public function test_acquire_next_job_exclusive() {
		$this->store->create_job( 8801, 42, array() );

		$first = $this->store->acquire_next_job( 'token-a', 300 );
		$this->assertNotNull( $first );

		$second = $this->store->acquire_next_job( 'token-b', 300 );
		$this->assertNull( $second );

		$this->store->release_lock( (int) $first->id, 'token-a' );
		$third = $this->store->acquire_next_job( 'token-b', 300 );
		$this->assertNotNull( $third );
	}

	/**
	 * Per-site run serialization: two pending rows for one site never hold
	 * a lock at the same time. The duplicate row a racing double-submit can
	 * create only runs once the first job for that site has finished.
	 */
	public function test_acquire_next_job_one_locked_per_site() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );

		$this->store->create_job( 9901, 42, array() );
		// Seed the duplicate row directly. create_job's own guard suppresses
		// a second active row per site, so this row models the rare case of
		// two coexisting rows; the claim gate must still serialize them.
		$now = time();
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . $table . ' ( site_id, snapshot_id, status, checkpoint, created, updated ) VALUES ( %d, %d, %s, %s, %d, %d )', 9901, 43, MainWP_Backup_Restore_Job_Store::STATUS_PENDING, '[]', $now, $now ) ); // phpcs:ignore WordPress.DB -- direct seed of the second-row race.
		$job_b = (int) $wpdb->insert_id;

		$first = $this->store->acquire_next_job( 'token-a', 300 );
		$this->assertNotNull( $first );

		$second = $this->store->acquire_next_job( 'token-b', 300 );
		$this->assertNull( $second, 'A second job for the same site must not lock while the first is leased.' );

		// Terminalize the first - not release_lock, which would leave it
		// re-claimable at the lower id - so the second row becomes claimable.
		$this->store->complete_job( (int) $first->id );
		$third = $this->store->acquire_next_job( 'token-b', 300 );
		$this->assertNotNull( $third, 'The duplicate runs once the first job finishes.' );
		$this->assertSame( $job_b, (int) $third->id );
	}

	/**
	 * The atomic create gate refuses a second active job for a site, so a
	 * racing double-submit cannot persist a duplicate restore. A different
	 * site is unaffected, and once the first job goes terminal the site can
	 * start a fresh restore (the rollback-after-failure path depends on this).
	 */
	public function test_create_job_refuses_duplicate_active_per_site() {
		$first = $this->store->create_job( 7001, 5, array() );
		$this->assertGreaterThan( 0, $first );

		$dup = $this->store->create_job( 7001, 5, array() );
		$this->assertSame( 0, $dup, 'A second active job for the same site is suppressed.' );

		$other = $this->store->create_job( 7002, 5, array() );
		$this->assertGreaterThan( 0, $other, 'A different site is unaffected.' );

		$this->store->complete_job( $first );
		$after = $this->store->create_job( 7001, 6, array() );
		$this->assertGreaterThan( 0, $after, 'A terminal job no longer blocks a fresh restore for the site.' );
	}

	/**
	 * Terminal jobs are never claimed.
	 */
	public function test_terminal_jobs_not_claimed() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->store->fail_permanent( $job_id, 'broken' );

		$this->assertNull( $this->store->acquire_next_job( 'token-a', 300 ) );

		$job = $this->store->get_job( $job_id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'broken', $job->last_error );
	}

	/**
	 * A retryable failure waits out its backoff before being claimable.
	 */
	public function test_retryable_failure_respects_backoff() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->store->fail_retryable( $job_id, 'flaky', 1, time() + 600 );

		$this->assertNull( $this->store->acquire_next_job( 'token-a', 300 ), 'Backoff not elapsed.' );

		$claimed = $this->store->acquire_next_job( 'token-a', 300, time() + 601 );
		$this->assertNotNull( $claimed );
		$this->assertSame( 1, (int) $claimed->attempt_count );
	}

	/**
	 * Checkpoints persist and decode.
	 */
	public function test_checkpoint_saved_and_loaded() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->store->save_checkpoint( $job_id, array( 'transfer' => array( 'artifact_index' => 2 ) ) );

		$job = $this->store->get_job( $job_id );
		$this->assertSame( 2, $job->checkpoint['transfer']['artifact_index'] );
	}

	/**
	 * One active restore per site: a second active job is visible and
	 * blocks initiation; completed jobs do not.
	 */
	public function test_active_job_for_site() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->assertNotNull( $this->store->get_active_job_for_site( 8801 ) );
		$this->assertNull( $this->store->get_active_job_for_site( 8802 ) );

		$this->store->complete_job( $job_id );
		$this->assertNull( $this->store->get_active_job_for_site( 8801 ) );
	}

	/**
	 * Status transitions record handles.
	 */
	public function test_set_status_records_handles() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->store->set_status( $job_id, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, array( 'child_task_id' => 'task-1-abc' ) );

		$job = $this->store->get_job( $job_id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->status );
		$this->assertSame( 'task-1-abc', $job->child_task_id );
	}

	/**
	 * A job advancing out of a retryable failure sheds the recorded error;
	 * the message would otherwise keep displaying through later phases.
	 */
	public function test_set_status_to_non_failure_clears_last_error() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->store->fail_retryable( $job_id, 'network timeout', 1, time() - 1 );

		$this->assertSame( 'network timeout', $this->store->get_job( $job_id )->last_error );

		$this->store->set_status( $job_id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING );

		$job = $this->store->get_job( $job_id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertSame( '', $job->last_error );
	}

	/**
	 * Moving to a failure status through set_status leaves the recorded
	 * error alone - the failure writers own that column.
	 */
	public function test_set_status_to_failure_keeps_last_error() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->store->fail_retryable( $job_id, 'network timeout', 1, time() - 1 );

		$this->store->set_status( $job_id, MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE );

		$this->assertSame( 'network timeout', $this->store->get_job( $job_id )->last_error );
	}

	/**
	 * Cancelled jobs leave the active set.
	 */
	public function test_cancel_job() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->store->cancel_job( $job_id );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, $this->store->get_job( $job_id )->status );
		$this->assertNull( $this->store->get_active_job_for_site( 8801 ) );
	}

	/**
	 * Terminal writes succeed from any active status and report it.
	 */
	public function test_terminal_writes_succeed_from_active_statuses() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->store->set_status( $job_id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING );

		$this->assertTrue( $this->store->cancel_job( $job_id ) );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, $this->store->get_job( $job_id )->status );
	}

	/**
	 * A completed job can never be relabeled cancelled at the store layer.
	 */
	public function test_cancel_refused_after_complete() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->assertTrue( $this->store->complete_job( $job_id ) );

		$this->assertFalse( $this->store->cancel_job( $job_id ) );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED, $this->store->get_job( $job_id )->status );
	}

	/**
	 * A cancelled job stays cancelled: completion loses the race.
	 */
	public function test_complete_refused_after_cancel() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->assertTrue( $this->store->cancel_job( $job_id ) );

		$this->assertFalse( $this->store->complete_job( $job_id ) );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, $this->store->get_job( $job_id )->status );
	}

	/**
	 * A finished job cannot be relabeled failed.
	 */
	public function test_fail_permanent_refused_after_complete() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->assertTrue( $this->store->complete_job( $job_id ) );

		$this->assertFalse( $this->store->fail_permanent( $job_id, 'late failure' ) );

		$job = $this->store->get_job( $job_id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED, $job->status );
		$this->assertSame( '', (string) $job->last_error );
	}

	/**
	 * Reclaiming an expired lease counts the reclaim, but a worker that
	 * dies mid-restore before ever calling fail_retryable() never touches
	 * attempt_count - that counter only bumps on a caught retryable
	 * failure, never on the hard fatal a reclaim implies.
	 */
	public function test_reclaim_increments_reclaim_count_not_attempt_count() {
		$job_id = $this->store->create_job( 8801, 42, array() );

		$now = 1000000;
		$job = $this->store->acquire_next_job( 'token-a', 300, $now );
		$this->assertSame( 0, (int) $job->reclaim_count );

		// The worker fatals mid-phase; its lease expires untouched.
		$reclaimed = $this->store->acquire_next_job( 'token-b', 300, $now + 301 );
		$this->assertNotNull( $reclaimed );
		$this->assertSame( $job_id, (int) $reclaimed->id );
		$this->assertSame( 1, (int) $reclaimed->reclaim_count, 'The expired-lease pickup must count as a reclaim.' );
		$this->assertSame( 0, (int) $reclaimed->attempt_count, 'A reclaim is not a counted retry attempt.' );
	}

	/**
	 * A normal retryable-after-backoff pickup - fail_retryable() clears
	 * locked_by before the job becomes claimable again - must not be
	 * mistaken for a reclaim; attempt_count already bounds that path.
	 */
	public function test_backoff_pickup_after_fail_retryable_does_not_count_as_reclaim() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->store->fail_retryable( $job_id, 'flaky', 1, time() - 1 );

		$retried = $this->store->acquire_next_job( 'token-a', 300 );
		$this->assertNotNull( $retried );
		$this->assertSame( 0, (int) $retried->reclaim_count, 'A backed-off retry is not a lease reclaim.' );
		$this->assertSame( 1, (int) $retried->attempt_count );
	}

	/**
	 * The store leaves over-ceiling jobs for orchestrator cleanup without claiming them.
	 */
	public function test_reclaim_ceiling_leaves_job_findable_without_claiming() {
		$job_id = $this->store->create_job( 8801, 42, array() );

		$now = 1000000;
		$this->store->acquire_next_job( 'token-0', 300, $now );

		for ( $i = 1; $i <= MainWP_Backup_Restore_Job_Store::RECLAIM_CEILING; $i++ ) {
			$now      += 301;
			$reclaimed = $this->store->acquire_next_job( "token-{$i}", 300, $now );
			$this->assertNotNull( $reclaimed, "Reclaim {$i} must still be handed out below the ceiling." );
			$this->assertSame( $job_id, (int) $reclaimed->id );
			$this->assertSame( $i, (int) $reclaimed->reclaim_count );
		}

		$now += 301;
		$this->assertNull( $this->store->acquire_next_job( 'token-final', 300, $now ), 'A job over the reclaim ceiling must not be acquired again.' );

		$final = $this->store->get_job( $job_id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PENDING, $final->status );
		$found = $this->store->find_jobs_over_reclaim_ceiling( $this->store::active_statuses(), $now, $job_id );
		$this->assertCount( 1, $found );
		$this->assertEquals( $final, $found[0] );
		$this->assertIsArray( $found[0]->checkpoint );
	}

	/**
	 * Finding expired jobs honors the poll scope and never changes either row.
	 */
	public function test_scoped_poll_finds_only_its_over_ceiling_jobs() {
		$job_x = $this->store->create_job( 8801, 42, array() );
		$job_y = $this->store->create_job( 9901, 43, array() );

		$now = 1000000;

		// Drive job Y past the reclaim ceiling via repeated expired-lease
		// reclaims, scoped to Y throughout so the setup never touches X.
		$this->store->acquire_next_job( 'token-y0', 300, $now, $job_y );
		for ( $i = 1; $i <= MainWP_Backup_Restore_Job_Store::RECLAIM_CEILING; $i++ ) {
			$now      += 301;
			$reclaimed = $this->store->acquire_next_job( "token-y{$i}", 300, $now, $job_y );
			$this->assertNotNull( $reclaimed, "Reclaim {$i} of Y must still be handed out below the ceiling." );
		}

		// Y must remain findable for cleanup once its final lease expires.
		$now += 301;

		// A scoped foreground poll for job X must not touch Y, even
		// though Y is sitting over the ceiling.
		$this->store->acquire_next_job( 'token-x-poll', 300, $now, $job_x );
		$this->assertNotSame(
			MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT,
			$this->store->get_job( $job_y )->status,
			'A job_id-scoped poll must never fail-permanent an unrelated job.'
		);

		$this->assertNull( $this->store->acquire_next_job( 'token-y-poll', 300, $now, $job_y ) );
		$this->assertNull( $this->store->acquire_next_job( 'token-drain', 300, $now ) );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PENDING, $this->store->get_job( $job_y )->status );
		$statuses = $this->store::active_statuses();
		$this->assertSame( array(), $this->store->find_jobs_over_reclaim_ceiling( $statuses, $now, $job_x ) );
		$scoped = $this->store->find_jobs_over_reclaim_ceiling( $statuses, $now, $job_y );
		$this->assertCount( 1, $scoped );
		$this->assertSame( $job_y, (int) $scoped[0]->id );
		$this->assertEquals( $scoped, $this->store->find_jobs_over_reclaim_ceiling( $statuses, $now ) );
	}

	/**
	 * Lease renewal extends locked_until only for the token that holds the
	 * lease; a competitor's renewal attempt changes nothing.
	 */
	public function test_renew_lease_extends_only_for_token_holder() {
		$job_id = $this->store->create_job( 8801, 42, array() );

		$now = 1000000;
		$this->store->acquire_next_job( 'token-a', 300, $now );

		$this->assertFalse( $this->store->renew_lease( $job_id, 'token-b', 300, $now + 100 ), 'A non-holder must not renew the lease.' );
		$this->assertTrue( $this->store->renew_lease( $job_id, 'token-a', 300, $now + 100 ), 'The holder renews its own lease.' );

		// Without the renewal the lease would have expired at now+300 and
		// this claim would succeed; the renewal pushed it to now+400.
		$this->assertNull( $this->store->acquire_next_job( 'token-b', 300, $now + 301 ), 'A renewed lease must not be reclaimable at the original expiry.' );
		$this->assertNotNull( $this->store->acquire_next_job( 'token-b', 300, $now + 401 ), 'The renewed lease still expires normally.' );
	}

	/**
	 * The concurrent-slow-worker scenario the fencing work exists for: a
	 * live worker that keeps renewing is never reclaimed by a competing
	 * lane and never drifts toward the reclaim ceiling, no matter how long
	 * the restore runs.
	 */
	public function test_renewing_live_worker_never_reclaimed_or_ceilinged() {
		$job_id = $this->store->create_job( 8801, 42, array() );

		$now = 1000000;
		$this->store->acquire_next_job( 'token-live', 300, $now );

		for ( $i = 1; $i <= MainWP_Backup_Restore_Job_Store::RECLAIM_CEILING + 2; $i++ ) {
			$now += 250;
			$this->assertTrue( $this->store->renew_lease( $job_id, 'token-live', 300, $now ), "Renewal {$i} of the live lease must succeed." );
			$this->assertNull( $this->store->acquire_next_job( "token-thief-{$i}", 300, $now ), 'A renewed live lease must never be reclaimed.' );
		}

		$job = $this->store->get_job( $job_id );
		$this->assertSame( 0, (int) $job->reclaim_count, 'A renewing live worker accrues no reclaims.' );
		$this->assertNotSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
	}

	/**
	 * An already-expired lease cannot be renewed even by the token that held
	 * it (F8): once the lease lapses the worker has lost it, so a slow holder
	 * cannot rescue a job the reclaim-ceiling sweep is about to fail between
	 * that sweep's SELECT and its terminal write.
	 */
	public function test_renew_refused_once_lease_expired() {
		$job_id = $this->store->create_job( 8801, 42, array() );

		$now = 1000000;
		$this->store->acquire_next_job( 'token-a', 300, $now );

		// The lease lapsed at now+300; a renewal past that point is refused.
		$this->assertFalse( $this->store->renew_lease( $job_id, 'token-a', 300, $now + 301 ), 'An expired lease must not be renewable by its former holder.' );
	}

	/**
	 * The reclaim ceiling is not defeated by a slow holder renewing between
	 * the orchestrator's over-ceiling read and its terminal write (F8):
	 * because the expired lease cannot be renewed, the job stays unclaimed
	 * and findable for the orchestrator to fail.
	 */
	public function test_reclaim_ceiling_not_rescued_by_expired_renew() {
		$job_id = $this->store->create_job( 8801, 42, array() );

		$now = 1000000;
		$this->store->acquire_next_job( 'token-0', 300, $now );
		for ( $i = 1; $i <= MainWP_Backup_Restore_Job_Store::RECLAIM_CEILING; $i++ ) {
			$now += 301;
			$this->store->acquire_next_job( "token-{$i}", 300, $now );
		}

		// The job now sits over the ceiling with an expired lease. The last
		// holder tries to renew before the next acquire sweeps it - and fails.
		$now += 301;
		$this->assertFalse( $this->store->renew_lease( $job_id, 'token-' . MainWP_Backup_Restore_Job_Store::RECLAIM_CEILING, 300, $now ), 'A renewal on the expired over-ceiling lease must be refused.' );

		$this->assertNull( $this->store->acquire_next_job( 'token-sweep', 300, $now ), 'The over-ceiling job is not handed out.' );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PENDING, $this->store->get_job( $job_id )->status, 'The store leaves the terminal verdict to the orchestrator.' );
		$found = $this->store->find_jobs_over_reclaim_ceiling( $this->store::active_statuses(), $now, $job_id );
		$this->assertCount( 1, $found, 'The orchestrator can still find it to fail it.' );
	}

	/**
	 * Once a lease is reclaimed, the previous worker's token no longer
	 * writes: its checkpoint and status writes are refused, while the new
	 * holder's fenced writes land.
	 */
	public function test_zombie_writes_refused_after_reclaim() {
		$job_id = $this->store->create_job( 8801, 42, array( 'cursor' => 'initial' ) );

		$now = 1000000;
		$this->store->acquire_next_job( 'token-zombie', 300, $now );
		$this->assertNotNull( $this->store->acquire_next_job( 'token-new', 300, $now + 301 ), 'The expired lease is reclaimed by a new worker.' );

		$this->assertFalse( $this->store->save_checkpoint( $job_id, array( 'cursor' => 'zombie' ), 'token-zombie' ), 'A checkpoint fenced with a stale token must be refused.' );
		$this->assertSame( 'initial', $this->store->get_job( $job_id )->checkpoint['cursor'], 'The refused checkpoint must not persist.' );

		$this->assertFalse( $this->store->set_status( $job_id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array(), 'token-zombie' ), 'A status write fenced with a stale token must be refused.' );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PENDING, $this->store->get_job( $job_id )->status );

		$this->assertTrue( $this->store->save_checkpoint( $job_id, array( 'cursor' => 'fresh' ), 'token-new' ), 'The live holder\'s fenced checkpoint lands.' );
		$this->assertTrue( $this->store->set_status( $job_id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array(), 'token-new' ), 'The live holder\'s fenced status write lands.' );
		$this->assertSame( 'fresh', $this->store->get_job( $job_id )->checkpoint['cursor'] );
	}

	/**
	 * A zombie worker must not terminally fail a job another worker now
	 * owns: RECLAIM_CEILING exists for genuinely dead workers, not live
	 * slow ones, and a stale-token fail_permanent would mark a healthy
	 * restore failed mid-flight.
	 */
	public function test_zombie_cannot_terminally_fail_reclaimed_job() {
		$job_id = $this->store->create_job( 8801, 42, array() );

		$now = 1000000;
		$this->store->acquire_next_job( 'token-zombie', 300, $now );
		$this->assertNotNull( $this->store->acquire_next_job( 'token-new', 300, $now + 301 ) );

		$this->assertFalse( $this->store->fail_permanent( $job_id, 'zombie verdict', 'token-zombie' ), 'A fenced terminal write with a stale token must be refused.' );
		$this->assertFalse( $this->store->fail_retryable( $job_id, 'zombie retry', 1, $now + 400, 'token-zombie' ), 'A fenced retryable failure with a stale token must be refused.' );
		$this->assertFalse( $this->store->complete_job( $job_id, 'token-zombie' ), 'A fenced completion with a stale token must be refused.' );

		$job = $this->store->get_job( $job_id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PENDING, $job->status, 'The reclaimed job is untouched by the zombie.' );

		$this->assertTrue( $this->store->complete_job( $job_id, 'token-new' ), 'The live holder\'s fenced terminal write lands.' );
	}

	/**
	 * Tokenless writes keep working unchanged: the UI/CLI cancel path and
	 * the reclaim-ceiling sweep legitimately hold no lease.
	 */
	public function test_tokenless_writes_bypass_fencing() {
		$job_id = $this->store->create_job( 8801, 42, array() );

		$now = 1000000;
		$this->store->acquire_next_job( 'token-a', 300, $now );

		$this->assertTrue( $this->store->save_checkpoint( $job_id, array( 'cursor' => 'unfenced' ) ), 'A tokenless checkpoint write is not fenced.' );
		$this->assertTrue( $this->store->cancel_job( $job_id ), 'A tokenless cancel lands while another lane holds the lease.' );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, $this->store->get_job( $job_id )->status );
	}
	/**
	 * Every declared status must agree with the per-site uniqueness identity.
	 */
	public function test_set_status_maintains_active_site_identity_for_every_status() {
		$constants = ( new \ReflectionClass( MainWP_Backup_Restore_Job_Store::class ) )->getConstants();
		$site_id   = 77000;
		foreach ( $constants as $name => $status ) {
			if ( ! str_starts_with( $name, 'STATUS_' ) ) {
				continue;
			}
			++$site_id;
			$id = $this->store->create_job( $site_id, 42, array() );
			$this->assertGreaterThan( 0, $id, $status );
			$this->assertSame( $site_id, (int) $this->store->get_job( $id )->active_site_id );
			$this->assertTrue( $this->store->set_status( $id, $status ), $status );
			$row = $this->store->get_job( $id );
			$this->assertSame( $status, $row->status );
			if ( in_array( $status, MainWP_Backup_Restore_Job_Store::active_statuses(), true ) ) {
				$this->assertSame( $site_id, (int) $row->active_site_id, $status );
			} else {
				$this->assertNull( $row->active_site_id, $status );
			}
		}
	}

	/**
	 * Lifecycle helpers must preserve the same invariant as direct status writes.
	 */
	public function test_restore_lifecycle_helpers_maintain_active_site_identity() {
		foreach ( array( 'complete_job', 'cancel_job', 'fail_permanent' ) as $terminal ) {
			$id = $this->store->create_job( 77801, 42, array() );
			$this->assertGreaterThan( 0, $id );
			$job = $this->store->acquire_next_job( 'identity-owner', 300, null, $id );
			$this->assertNotNull( $job );
			$this->assertSame( 77801, (int) $job->active_site_id );
			$this->assertTrue( $this->store->save_checkpoint( $id, array( 'phase' => 'staging' ), 'identity-owner' ) );
			$this->assertTrue( $this->store->arm_cancel_intent( $id ) );
			$this->assertTrue( $this->store->fail_retryable( $id, 'retry', 1, time() + 60, 'identity-owner' ) );
			$this->assertSame( 77801, (int) $this->store->get_job( $id )->active_site_id );
			$this->assertFalse( $this->store->set_status( $id, 'cancelled', array(), 'lost-owner' ) );
			$this->assertSame( 77801, (int) $this->store->get_job( $id )->active_site_id );
			$result = 'fail_permanent' === $terminal ? $this->store->fail_permanent( $id, 'permanent' ) : $this->store->$terminal( $id );
			$this->assertTrue( $result, $terminal );
			$this->assertNull( $this->store->get_job( $id )->active_site_id, $terminal );
			if ( 'cancel_job' === $terminal ) {
				$this->assertSame( 'retry', $this->store->get_job( $id )->last_error );
			}
			$this->assertFalse( $this->store->cancel_job( $id ), 'Only the first terminal writer succeeds.' );
		}
	}

	/**
	 * A losing insert adopts the active job without English error text.
	 *
	 * @dataProvider insert_error_languages
	 * @param bool $localized Simulate a non-English duplicate message.
	 */
	public function test_create_job_duplicate_key_race_returns_zero( $localized ) {
		global $wpdb;
		$this->assertGreaterThan( 0, $this->store->create_job( 77901, 42, array() ) );
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$raced = false;
		$race  = function ( $query ) use ( $wpdb, $table, &$raced, $localized ) {
			if ( str_starts_with( $query, 'INSERT INTO ' . $table ) ) {
				$raced = true;
				if ( $localized ) {
					$wpdb->last_error = 'Doppelter Eintrag';
					return '';
				}
				return strstr( $query, ' WHERE NOT EXISTS', true );
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$this->assertSame( 0, $this->store->create_job( 77901, 43, array() ) );
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assertTrue( $raced );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE site_id = 77901" ) ); // phpcs:ignore WordPress.DB -- test probe.
	}

	/**
	 * An upgrade keeps the progressed job and preserves terminal history.
	 */
	public function test_install_deduplicates_active_restore_site_identity() {
		global $wpdb;
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$version = get_site_option( 'mainwp_db_version' );
		$ids     = array();
		try {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_active_site, DROP COLUMN active_site_id" ); // phpcs:ignore WordPress.DB -- test fixture.
			foreach ( array( 'completed', 'pending', 'running', 'pending', 'cancelled' ) as $status ) {
				$row = array(
					'site_id'     => 77902,
					'snapshot_id' => 42,
					'status'      => $status,
					'checkpoint'  => '{}',
				);
				$this->assertSame( 1, $wpdb->insert( $table, $row ) ); // phpcs:ignore WordPress.DB -- test fixture.
				$ids[] = (int) $wpdb->insert_id;
			}
			update_site_option( 'mainwp_db_version', '9.0.2.24' );
			MainWP_Install::instance()->install();
			foreach ( $ids as $index => $id ) {
				$row = $this->store->get_job( $id );
				$this->assertNotNull( $row );
				if ( 2 === $index ) {
					$this->assertSame( 'running', $row->status );
					$this->assertSame( 77902, (int) $row->active_site_id );
				} else {
					$this->assertNull( $row->active_site_id );
					$this->assertSame( 0 === $index ? 'completed' : 'cancelled', $row->status );
					if ( 1 === $index || 3 === $index ) {
						$this->assertSame( 'Superseded by the active restore uniqueness repair.', $row->last_error );
					}
				}
			}
			$this->assertNotEmpty( $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_active_site' AND Non_unique = 0" ) ); // phpcs:ignore WordPress.DB -- test probe.
		} finally {
			$wpdb->query( "DELETE FROM {$table} WHERE site_id = 77902" ); // phpcs:ignore WordPress.DB -- test cleanup.
			MainWP_Install::instance()->repair_backup_site_unique_keys();
			update_site_option( 'mainwp_db_version', $version );
			self::commit_transaction();
		}
	}

	/**
	 * An engine failure must not look like an occupied site.
	 */
	public function test_create_job_keeps_database_errors_distinct_from_duplicates() {
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$fail  = static function ( $query ) use ( $table ) {
			return str_starts_with( $query, 'INSERT INTO ' . $table ) ? str_replace( 'snapshot_id', 'missing_snapshot_column', $query ) : $query;
		};
		add_filter( 'query', $fail );
		try {
			$this->assertSame( -1, $this->store->create_job( 77903, 42, array() ) );
		} finally {
			remove_filter( 'query', $fail );
		}
		$this->assertNull( $this->store->get_active_job_for_site( 77903 ) );
	}

	/**
	 * A cancelled row cannot reclaim a site occupied by a newer restore.
	 */
	public function test_set_status_refuses_active_write_onto_cancelled_row() {
		$id = $this->store->create_job( 77904, 42, array() );
		$this->assertTrue( $this->store->cancel_job( $id ) );
		$this->assertGreaterThan( 0, $this->store->create_job( 77904, 43, array() ) );
		$this->assertFalse( $this->store->set_status( $id, 'running' ) );
		$this->assertSame( 'cancelled', $this->store->get_job( $id )->status );
	}

	/**
	 * The source-status fence also applies when the site has no sibling job.
	 */
	public function test_set_status_refuses_active_write_onto_lone_cancelled_row() {
		$id = $this->store->create_job( 77904, 42, array() );
		$this->assertTrue( $this->store->cancel_job( $id ) );
		$this->assertFalse( $this->store->set_status( $id, 'running' ) );
		$this->assertSame( 'cancelled', $this->store->get_job( $id )->status );
		$this->assertNull( $this->store->get_job( $id )->active_site_id );
	}

	/**
	 * Repeating a terminal write cannot acknowledge another writer's transition.
	 */
	public function test_set_status_tokenless_terminal_noop_returns_false() {
		foreach ( array( 'completed', 'cancelled', 'failed_permanent' ) as $status ) {
			$id = $this->store->create_job( 77904, 42, array() );
			$this->assertTrue( $this->store->set_status( $id, $status ) );
			$this->assertFalse( $this->store->set_status( $id, $status ), $status );
			$this->assertSame( $status, $this->store->get_job( $id )->status );
		}
	}

	/**
	 * A missing row cannot acknowledge a tokenless status write.
	 */
	public function test_set_status_missing_row_returns_false() {
		$id = $this->store->create_job( 77904, 42, array() );
		global $wpdb;
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB -- test fixture.
		$this->assertFalse( $this->store->set_status( $id, 'running' ) );
	}

	/**
	 * Freeze the UPDATE timestamp to exercise an actual zero-row no-op.
	 */
	public function test_set_status_same_second_noop_returns_true() {
		$id        = $this->store->create_job( 77904, 42, array() );
		$table     = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$updated   = (int) $this->store->get_job( $id )->updated;
		$read_back = false;
		$freeze    = static function ( $query ) use ( $table, $updated, &$read_back ) {
			if ( str_starts_with( $query, 'SELECT * FROM ' . $table . ' WHERE id = ' ) ) {
				$read_back = true;
			}
			return str_starts_with( $query, 'UPDATE ' . $table . ' SET ' ) ? preg_replace( '/`updated` = [0-9]+/', '`updated` = ' . $updated, $query ) : $query;
		};
		add_filter( 'query', $freeze );
		try {
			$this->assertTrue( $this->store->set_status( $id, 'pending' ) );
			$this->assertTrue( $read_back, 'A zero-row write must re-read the current row.' );
		} finally {
			remove_filter( 'query', $freeze );
		}
	}

	/**
	 * Throwing query filters must not leak error suppression from either write.
	 */
	public function test_restore_writes_restore_error_suppression_after_throw() {
		global $wpdb;
		$id       = $this->store->create_job( 77904, 42, array() );
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$suppress = $wpdb->suppress_errors( false );
		$throw    = static function ( $query ) use ( $table ) {
			if ( str_starts_with( $query, 'INSERT INTO ' . $table ) || str_starts_with( $query, 'UPDATE ' . $table ) ) {
				throw new \RuntimeException( 'query filter failed' );
			}
			return $query;
		};
		add_filter( 'query', $throw );
		try {
			foreach ( array( 'create', 'status' ) as $operation ) {
				try {
					if ( 'create' === $operation ) {
						$this->store->create_job( 77905, 42, array() );
					} else {
						$this->store->set_status( $id, 'running' );
					}
					$this->fail( 'The query filter must throw.' );
				} catch ( \RuntimeException $e ) {
					$this->assertSame( 'query filter failed', $e->getMessage() );
					$this->assertFalse( $wpdb->suppress_errors );
				}
			}
		} finally {
			remove_filter( 'query', $throw );
			$wpdb->suppress_errors( $suppress );
		}
	}

	/**
	 * Exercise both the real duplicate key and a localized failure response.
	 *
	 * @return array Error message modes.
	 */
	public static function insert_error_languages() {
		return array(
			'server message'    => array( false ),
			'localized message' => array( true ),
		);
	}
}
