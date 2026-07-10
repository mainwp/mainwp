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
	 * A restore that keeps killing its worker (reclaimed past the
	 * ceiling) is routed to fail_permanent instead of being handed out
	 * again, since a hard fatal never reaches fail_retryable to bound it
	 * via attempt_count.
	 */
	public function test_reclaim_ceiling_routes_job_to_fail_permanent() {
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
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $final->status );
	}

	/**
	 * A foreground progress poll passes the job id it is advancing so it
	 * only ever advances that restore (the acquire_next_job docblock's
	 * own contract). The reclaim-ceiling sweep must honor that scoping:
	 * polling job X must never fail-permanent an unrelated job Y sitting
	 * over the ceiling for a different site. The unscoped background/CLI
	 * drainer, which takes any runnable job, sweeps every over-ceiling
	 * job as before.
	 */
	public function test_scoped_poll_does_not_reclaim_fail_a_different_over_ceiling_job() {
		$job_x = $this->store->create_job( 8801, 42, array() );
		$job_y = $this->store->create_job( 9901, 43, array() );

		$now = 1000000;

		// Drive job Y past the reclaim ceiling via repeated expired-lease
		// reclaims, scoped to Y throughout so the setup never touches X.
		$this->store->acquire_next_job( 'token-y0', 300, $now, $job_y );
		for ( $i = 1; $i <= MainWP_Backup_Restore_Job_Store::RECLAIM_CEILING; $i++ ) {
			$now       += 301;
			$reclaimed = $this->store->acquire_next_job( "token-y{$i}", 300, $now, $job_y );
			$this->assertNotNull( $reclaimed, "Reclaim {$i} of Y must still be handed out below the ceiling." );
		}

		// Y now sits at the ceiling with an expired lease - eligible for
		// the sweep on the very next acquire call.
		$now += 301;

		// A scoped foreground poll for job X must not touch Y, even
		// though Y is sitting over the ceiling.
		$this->store->acquire_next_job( 'token-x-poll', 300, $now, $job_x );
		$this->assertNotSame(
			MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT,
			$this->store->get_job( $job_y )->status,
			'A job_id-scoped poll must never fail-permanent an unrelated job.'
		);

		// The unscoped background/CLI drainer sweeps every over-ceiling job.
		$this->store->acquire_next_job( 'token-drain', 300, $now );
		$this->assertSame(
			MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT,
			$this->store->get_job( $job_y )->status,
			'The unscoped drainer must fail-permanent an over-ceiling job.'
		);
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
}
