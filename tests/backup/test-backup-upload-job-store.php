<?php
/**
 * MainWP Backup Upload Job Store Tests
 *
 * Verifies the resumable upload job rows: one row per snapshot and
 * destination, idempotent enqueue, atomic lock acquisition so two cron
 * lanes never run the same job, lease expiry reclaim, retry scheduling,
 * and checkpoint persistence across re-entries.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;

/**
 * Class MainWP_Backup_Upload_Job_Store_Test
 */
class MainWP_Backup_Upload_Job_Store_Test extends \WP_UnitTestCase {

	/**
	 * Enqueue inserts one pending row per destination, idempotently.
	 *
	 * @return void
	 */
	public function test_enqueue_is_idempotent_per_destination() {
		$store = MainWP_Backup_Upload_Job_Store::instance();

		$this->assertSame( 2, $store->enqueue_for_snapshot( 101, array( 7, 8 ) ) );
		$this->assertSame( 0, $store->enqueue_for_snapshot( 101, array( 7, 8 ) ), 'Re-enqueue must not duplicate rows.' );
		$this->assertSame( 1, $store->enqueue_for_snapshot( 101, array( 9 ) ), 'A new destination still enqueues.' );

		$jobs = $store->get_jobs_for_snapshot( 101 );
		$this->assertCount( 3, $jobs );
		foreach ( $jobs as $job ) {
			$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_PENDING, $job->status );
		}
	}

	/**
	 * Enqueue with no destinations is a no-op.
	 *
	 * @return void
	 */
	public function test_enqueue_with_no_destinations_is_noop() {
		$store = MainWP_Backup_Upload_Job_Store::instance();

		$this->assertSame( 0, $store->enqueue_for_snapshot( 102, array() ) );
		$this->assertSame( array(), $store->get_jobs_for_snapshot( 102 ) );
	}

	/**
	 * Acquiring locks the job; a concurrent acquirer gets nothing until
	 * the lease expires.
	 *
	 * @return void
	 */
	public function test_acquire_locks_out_concurrent_acquirer() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 103, array( 7 ) );

		$now = 1000000;

		$job = $store->acquire_next_job( 'token-a', 300, $now );
		$this->assertNotNull( $job );
		$this->assertSame( 103, (int) $job->snapshot_id );
		$this->assertSame( 'token-a', $job->locked_by );

		$this->assertNull( $store->acquire_next_job( 'token-b', 300, $now + 60 ), 'A live lease must block other acquirers.' );

		$reclaimed = $store->acquire_next_job( 'token-b', 300, $now + 301 );
		$this->assertNotNull( $reclaimed, 'An expired lease must be reclaimable.' );
		$this->assertSame( 'token-b', $reclaimed->locked_by );
	}

	/**
	 * Terminal jobs are never acquirable.
	 *
	 * @return void
	 */
	public function test_terminal_jobs_are_not_acquirable() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 104, array( 7, 8, 9 ) );

		$jobs = $store->get_jobs_for_snapshot( 104 );

		$store->complete_job( (int) $jobs[0]->id );
		$store->fail_permanent( (int) $jobs[1]->id, 'gone for good' );
		$store->invalidate_credentials( (int) $jobs[2]->id, 'auth rejected' );

		$this->assertNull( $store->acquire_next_job( 'token-a', 300, 1000000 ) );

		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $store->get_job( (int) $jobs[0]->id )->status );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $store->get_job( (int) $jobs[1]->id )->status );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_CREDENTIALS_INVALID, $store->get_job( (int) $jobs[2]->id )->status );
		$this->assertSame( 'gone for good', $store->get_job( (int) $jobs[1]->id )->last_error );
	}

	/**
	 * A retryable failure schedules the next attempt and is acquirable
	 * only after next_retry_at passes.
	 *
	 * @return void
	 */
	public function test_retryable_failure_schedules_backoff() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 105, array( 7 ) );

		$now = 1000000;
		$job = $store->acquire_next_job( 'token-a', 300, $now );

		$store->fail_retryable( (int) $job->id, 'network blip', $now + 600 );

		$stored = $store->get_job( (int) $job->id );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $stored->status );
		$this->assertSame( 1, (int) $stored->attempt_count );
		$this->assertSame( $now + 600, (int) $stored->next_retry_at );
		$this->assertSame( 'network blip', $stored->last_error );

		$this->assertNull( $store->acquire_next_job( 'token-b', 300, $now + 599 ), 'Backoff window must hold.' );
		$this->assertNotNull( $store->acquire_next_job( 'token-b', 300, $now + 600 ), 'Job must be retryable after the window.' );

		$this->assertSame( 1, (int) $store->get_job( (int) $job->id )->attempt_count, 'Attempts count failures, not acquisitions.' );
	}

	/**
	 * Checkpoints persist as structured data across re-entries.
	 *
	 * @return void
	 */
	public function test_checkpoint_round_trip() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 106, array( 7 ) );
		$job = $store->acquire_next_job( 'token-a', 300, 1000000 );

		$checkpoint = array(
			'artifacts'          => array(
				array(
					'path'          => '/srv/snap/part-1.zip',
					'role'          => 'part',
					'size'          => 8388608,
					'chunks'        => array(
						array(
							'hash'           => 'abc123',
							'algo'           => 'xxh128',
							'offset'         => 0,
							'length'         => 4194304,
							'remote_present' => true,
						),
					),
					'chunks_cursor' => 1,
				),
			),
			'artifact_cursor'    => 0,
			'manifest_submitted' => false,
		);

		$store->save_checkpoint( (int) $job->id, $checkpoint );

		$this->assertSame( $checkpoint, $store->get_job( (int) $job->id )->checkpoint );
	}

	/**
	 * Releasing a lock requires the holder's token.
	 *
	 * @return void
	 */
	public function test_release_lock_requires_matching_token() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 107, array( 7 ) );

		$now = 1000000;
		$job = $store->acquire_next_job( 'token-a', 300, $now );

		$store->release_lock( (int) $job->id, 'token-b' );
		$this->assertNull( $store->acquire_next_job( 'token-c', 300, $now + 60 ), 'A foreign token must not release the lock.' );

		$store->release_lock( (int) $job->id, 'token-a' );
		$this->assertNotNull( $store->acquire_next_job( 'token-c', 300, $now + 60 ), 'The holder token must release the lock.' );
	}

	/**
	 * Status transitions persist through the setter used by the driver.
	 *
	 * @return void
	 */
	public function test_set_status_progression() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 108, array( 7 ) );
		$job = $store->acquire_next_job( 'token-a', 300, 1000000 );

		foreach ( array(
			MainWP_Backup_Upload_Job_Store::STATUS_CHUNKING,
			MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING,
			MainWP_Backup_Upload_Job_Store::STATUS_SUBMITTING,
		) as $status ) {
			$store->set_status( (int) $job->id, $status );
			$this->assertSame( $status, $store->get_job( (int) $job->id )->status );
		}
	}

	/**
	 * Terminal writes succeed from any active status and report it.
	 *
	 * @return void
	 */
	public function test_terminal_writes_succeed_from_active_statuses() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 109, array( 7 ) );
		$job = $store->acquire_next_job( 'token-a', 300, 1000000 );

		$store->set_status( (int) $job->id, MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING );

		$this->assertTrue( $store->complete_job( (int) $job->id ) );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $store->get_job( (int) $job->id )->status );
	}

	/**
	 * A completed upload can never be relabeled failed by a racing lane.
	 *
	 * @return void
	 */
	public function test_fail_refused_after_complete() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 110, array( 7 ) );
		$job = $store->acquire_next_job( 'token-a', 300, 1000000 );

		$this->assertTrue( $store->complete_job( (int) $job->id ) );

		$this->assertFalse( $store->fail_permanent( (int) $job->id, 'late failure' ) );
		$this->assertFalse( $store->invalidate_credentials( (int) $job->id, 'late invalid' ) );

		$fresh = $store->get_job( (int) $job->id );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $fresh->status );
		$this->assertSame( '', (string) $fresh->last_error );
	}

	/**
	 * A permanently failed job stays failed: completion loses the race,
	 * and a credentials-parked job is not overwritten either.
	 *
	 * @return void
	 */
	public function test_terminal_states_keep_first_writer() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 111, array( 7, 8 ) );

		$failed = $store->acquire_next_job( 'token-a', 300, 1000000 );
		$this->assertTrue( $store->fail_permanent( (int) $failed->id, 'broken' ) );
		$this->assertFalse( $store->complete_job( (int) $failed->id ) );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $store->get_job( (int) $failed->id )->status );

		$parked = $store->acquire_next_job( 'token-b', 300, 1000000 );
		$this->assertTrue( $store->invalidate_credentials( (int) $parked->id, 'needs re-entry' ) );
		$this->assertFalse( $store->fail_permanent( (int) $parked->id, 'late failure' ) );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_CREDENTIALS_INVALID, $store->get_job( (int) $parked->id )->status );
	}

	/**
	 * Reclaiming an expired lease counts the reclaim, but a worker that
	 * dies mid-job before ever calling fail_retryable() never touches
	 * attempt_count - that counter only bumps on a caught retryable
	 * failure, never on the hard fatal a reclaim implies.
	 *
	 * @return void
	 */
	public function test_reclaim_increments_reclaim_count_not_attempt_count() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 112, array( 7 ) );

		$now = 1000000;
		$job = $store->acquire_next_job( 'token-a', 300, $now );
		$this->assertSame( 0, (int) $job->reclaim_count );

		// The worker fatals mid-phase; its lease expires untouched.
		$reclaimed = $store->acquire_next_job( 'token-b', 300, $now + 301 );
		$this->assertNotNull( $reclaimed );
		$this->assertSame( 1, (int) $reclaimed->reclaim_count, 'The expired-lease pickup must count as a reclaim.' );
		$this->assertSame( 0, (int) $reclaimed->attempt_count, 'A reclaim is not a counted retry attempt.' );
	}

	/**
	 * A normal retryable-after-backoff pickup - fail_retryable() clears
	 * locked_by before the job becomes claimable again - must not be
	 * mistaken for a reclaim; attempt_count already bounds that path.
	 *
	 * @return void
	 */
	public function test_backoff_pickup_after_fail_retryable_does_not_count_as_reclaim() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 113, array( 7 ) );

		$now = 1000000;
		$job = $store->acquire_next_job( 'token-a', 300, $now );
		$store->fail_retryable( (int) $job->id, 'network blip', $now + 600 );

		$retried = $store->acquire_next_job( 'token-b', 300, $now + 600 );
		$this->assertNotNull( $retried );
		$this->assertSame( 0, (int) $retried->reclaim_count, 'A backed-off retry is not a lease reclaim.' );
		$this->assertSame( 1, (int) $retried->attempt_count );
	}

	/**
	 * A job that keeps killing its worker (reclaimed past the ceiling)
	 * is routed to fail_permanent instead of being handed out again,
	 * since a hard fatal never reaches fail_retryable to bound it via
	 * attempt_count.
	 *
	 * @return void
	 */
	public function test_reclaim_ceiling_routes_job_to_fail_permanent() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 114, array( 7 ) );

		$now = 1000000;
		$job = $store->acquire_next_job( 'token-0', 300, $now );
		$job_id = (int) $job->id;

		// Each reclaim simulates the worker fataling before it can call
		// fail_retryable() or set_status(); the lease is simply left to
		// expire.
		for ( $i = 1; $i <= MainWP_Backup_Upload_Job_Store::RECLAIM_CEILING; $i++ ) {
			$now      += 301;
			$reclaimed = $store->acquire_next_job( "token-{$i}", 300, $now );
			$this->assertNotNull( $reclaimed, "Reclaim {$i} must still be handed out below the ceiling." );
			$this->assertSame( $job_id, (int) $reclaimed->id );
			$this->assertSame( $i, (int) $reclaimed->reclaim_count );
		}

		// The next attempt would be reclaim ceiling + 1: the job must be
		// routed to fail_permanent instead of handed out again.
		$now += 301;
		$this->assertNull( $store->acquire_next_job( 'token-final', 300, $now ), 'A job over the reclaim ceiling must not be acquired again.' );

		$final = $store->get_job( $job_id );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $final->status );
	}
}
