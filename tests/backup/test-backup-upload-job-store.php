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
use MainWP\Dashboard\MainWP_DB;

/**
 * Class MainWP_Backup_Upload_Job_Store_Test
 */
class MainWP_Backup_Upload_Job_Store_Test extends \WP_UnitTestCase {

	/**
	 * Equality belongs to the holder; one second past expiry belongs to the next worker.
	 *
	 * @return array Lease boundary offsets.
	 */
	public static function retry_lease_boundaries() {
		return array(
			'equal'   => array( 0 ),
			'expired' => array( -1 ),
		);
	}

	/**
	 * An expired retry leaves every column available for reclamation.
	 *
	 * @dataProvider retry_lease_boundaries
	 * @param int $offset Lease deadline relative to the store clock.
	 */
	public function test_retryable_write_requires_live_lease_at_clock_boundary( int $offset ) {
		global $wpdb;
		$now = time();
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = $now;
		try {
			$store = MainWP_Backup_Upload_Job_Store::instance();
			$store->enqueue_for_snapshot( 98201, array( 7 ) );
			$job = $store->acquire_next_job( 'old-holder', 300 );
			$this->assertNotNull( $job );
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'locked_until' => $now + $offset ), array( 'id' => $job->id ) );
			$before = $store->get_job( (int) $job->id );
			$this->assertSame( 0 === $offset, $store->fail_retryable( (int) $job->id, 'retry', $now + 60, 'old-holder' ) );
			if ( $offset < 0 ) {
				$this->assertEquals( $before, $store->get_job( (int) $job->id ) );
				$next = $store->acquire_next_job( 'next-holder', 300 );
				$this->assertNotNull( $next );
				$this->assertSame( (int) $job->id, (int) $next->id );
				$this->assertSame( 'next-holder', $next->locked_by );
			} else {
				$this->assertSame( $store::STATUS_FAILED_RETRYABLE, $store->get_job( (int) $job->id )->status );
			}
		} finally {
			MainWP_Backup_Incremental_Sender_Test::$fixed_time = null;
		}
	}

	/** The zero-row fallback still requires the same token and a live lease. */
	public function test_retryable_same_second_zero_row_fallback() {
		global $wpdb;
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 98202, array( 7 ) );
		$job = $store->acquire_next_job( 'same-second', 300 );
		$this->assertNotNull( $job );
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$noop  = static function ( $sql ) use ( $wpdb, $table, $job ) {
			if ( str_contains( $sql, 'attempt_count = attempt_count + 1' ) ) {
				return $wpdb->prepare( 'UPDATE ' . $table . ' SET locked_until = locked_until WHERE id = %d', $job->id );
			}
			return $sql;
		};
		add_filter( 'query', $noop );
		try {
			$this->assertTrue( $store->fail_retryable( (int) $job->id, 'retry', time() + 60, 'same-second' ) );
			$wpdb->update( $table, array( 'locked_until' => time() - 10 ), array( 'id' => $job->id ) );
			$this->assertFalse( $store->fail_retryable( (int) $job->id, 'retry', time() + 60, 'same-second' ) );
		} finally {
			remove_filter( 'query', $noop );
		}
	}

	/**
	 * Expiry fences progress even before another worker reclaims the token.
	 *
	 * @dataProvider fenced_lease_states
	 * @param bool $expired Whether the lease has expired.
	 */
	public function test_fenced_progress_requires_live_lease( bool $expired ) {
		global $wpdb;
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 901, array( 7 ) );
		$job = $store->acquire_next_job( 'holder', 300 );
		$this->assertNotNull( $job );
		if ( $expired ) {
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'locked_until' => time() - 60 ), array( 'id' => $job->id ) );
		}
		$before = $store->get_job( (int) $job->id );
		$this->assertSame( ! $expired, $store->save_checkpoint( (int) $job->id, array( 'cursor' => 9 ), 'holder' ) );
		$this->assertSame( ! $expired, $store->set_status( (int) $job->id, $store::STATUS_UPLOADING, 'holder' ) );
		$after = $store->get_job( (int) $job->id );
		$this->assertSame( $expired ? $before->checkpoint : array( 'cursor' => 9 ), $after->checkpoint );
		$this->assertSame( $expired ? $before->status : $store::STATUS_UPLOADING, $after->status );
		$this->assertSame( ! $expired, $store->save_checkpoint( (int) $job->id, $after->checkpoint, 'holder' ), 'An unchanged write must still check lease freshness.' );
		if ( $expired ) {
			$this->assertEquals( $before, $store->get_job( (int) $job->id ), 'Expired writes must leave every column unchanged.' );
		}
	}

	/**
	 * Expired holders cannot finish a job or announce settlement.
	 *
	 * @dataProvider fenced_lease_states
	 * @param bool $expired Whether the lease has expired.
	 */
	public function test_fenced_terminal_requires_live_lease( bool $expired ) {
		global $wpdb;
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 902, array( 7 ) );
		$job = $store->acquire_next_job( 'holder', 300 );
		$this->assertNotNull( $job );
		if ( $expired ) {
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'locked_until' => time() - 60 ), array( 'id' => $job->id ) );
		}
		$before  = $store->get_job( (int) $job->id );
		$settled = array();
		add_action(
			'mainwp_backup_upload_job_settled',
			static function ( $id ) use ( &$settled ) {
				$settled[] = $id;
			}
		);
		$this->assertSame( ! $expired, $store->complete_job( (int) $job->id, 'holder' ) );
		$this->assertSame( $expired ? array() : array( 902 ), $settled );
		if ( $expired ) {
			$this->assertEquals( $before, $store->get_job( (int) $job->id ) );
		} else {
			$this->assertSame( $store::STATUS_COMPLETE, $store->get_job( (int) $job->id )->status );
		}
	}

	/**
	 * Lease states for progress and terminal writes.
	 *
	 * @return array
	 */
	public static function fenced_lease_states(): array {
		return array(
			'expired' => array( true ),
			'live'    => array( false ),
		);
	}

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
	 * An already-queued delete job reports success: retention holds a
	 * snapshot's prune on this answer, and a duplicate misread as failure
	 * would wedge the prune forever. Only a real write failure is false.
	 *
	 * @return void
	 */
	public function test_enqueue_delete_job_duplicate_reports_success() {
		$store      = MainWP_Backup_Upload_Job_Store::instance();
		$checkpoint = array(
			'prefix' => '1/501/',
			'keys'   => array(),
			'cursor' => 0,
			'swept'  => false,
		);

		$this->assertTrue( $store->enqueue_delete_job( 501, 7, $checkpoint ), 'The first enqueue inserts.' );
		$this->assertTrue( $store->enqueue_delete_job( 501, 7, $checkpoint ), 'A duplicate enqueue is success, not failure.' );
		$this->assertCount( 1, $store->get_jobs_for_snapshot( 501, MainWP_Backup_Upload_Job_Store::KIND_DELETE ), 'Still exactly one row.' );
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
	 * Invalid column names refuse the whole write; valid columns remain quoted.
	 *
	 * @return void
	 */
	public function test_terminal_update_rejects_invalid_columns_without_query() {
		global $wpdb;

		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 109, array( 7 ) );
		$job    = $store->get_jobs_for_snapshot( 109 )[0];
		$method = new \ReflectionMethod( $store, 'terminal_update' );

		foreach ( array( 'status ', 'status`', '1status', "status\n", 0 ) as $column ) {
			$queries  = $wpdb->num_queries;
			$suppress = $wpdb->suppress_errors( true );
			try {
				$result = $method->invoke(
					$store,
					(int) $job->id,
					array(
						'last_error' => 'refused',
						$column      => MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE,
					)
				);
			} finally {
				$wpdb->suppress_errors( $suppress );
			}
			$this->assertSame( $queries, $wpdb->num_queries, 'An invalid column must not issue a query.' );
			$this->assertFalse( $result );
		}

		$fresh = $store->get_job( (int) $job->id );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_PENDING, $fresh->status );
		$this->assertSame( '', $fresh->last_error );
		$this->assertTrue(
			$method->invoke(
				$store,
				(int) $job->id,
				array(
					'status'     => MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE,
					'last_error' => "Owner's message",
				)
			)
		);
		foreach ( array( 'status', 'last_error', 'updated' ) as $column ) {
			$this->assertStringContainsString( '`' . $column . '` = ', $wpdb->last_query );
		}
		$fresh = $store->get_job( (int) $job->id );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $fresh->status );
		$this->assertSame( "Owner's message", $fresh->last_error );
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

		$now    = 1000000;
		$job    = $store->acquire_next_job( 'token-0', 300, $now );
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

	/**
	 * Lease renewal extends locked_until only for the token that holds the
	 * lease; a competitor's renewal attempt changes nothing.
	 *
	 * @return void
	 */
	public function test_renew_lease_extends_only_for_token_holder() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 115, array( 7 ) );

		$now = 1000000;
		$job = $store->acquire_next_job( 'token-a', 300, $now );

		$this->assertFalse( $store->renew_lease( (int) $job->id, 'token-b', 300, $now + 100 ), 'A non-holder must not renew the lease.' );
		$this->assertTrue( $store->renew_lease( (int) $job->id, 'token-a', 300, $now + 100 ), 'The holder renews its own lease.' );

		// Without the renewal the lease would have expired at now+300 and
		// this claim would succeed; the renewal pushed it to now+400.
		$this->assertNull( $store->acquire_next_job( 'token-b', 300, $now + 301 ), 'A renewed lease must not be reclaimable at the original expiry.' );
		$this->assertNotNull( $store->acquire_next_job( 'token-b', 300, $now + 401 ), 'The renewed lease still expires normally.' );
	}

	/**
	 * An already-expired lease cannot be renewed even by the token that
	 * held it: once the lease lapses the worker has lost it.
	 *
	 * @return void
	 */
	public function test_renew_lease_refuses_expired_lease() {
		global $wpdb;

		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 116, array( 7 ) );

		$now = 1000000;
		$job = $store->acquire_next_job( 'token-a', 300, $now );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture forcing lease expiry.
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array( 'locked_until' => 1 ),
			array( 'id' => (int) $job->id )
		);

		$this->assertFalse( $store->renew_lease( (int) $job->id, 'token-a', 300, $now + 100 ), 'An expired lease must not be renewable by its former holder.' );
	}

	/**
	 * Once a lease is reclaimed, the previous worker's token no longer
	 * writes: its checkpoint and status writes are refused, while the new
	 * holder's fenced writes land.
	 *
	 * @return void
	 */
	public function test_zombie_checkpoint_and_status_writes_refused_after_reclaim() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 117, array( 7 ) );

		$now    = time();
		$job    = $store->acquire_next_job( 'token-zombie', 300, $now );
		$job_id = (int) $job->id;

		$reclaimed = $store->acquire_next_job( 'token-new', 300, $now + 301 );
		$this->assertNotNull( $reclaimed, 'The expired lease is reclaimed by a new worker.' );
		$this->assertSame( 'token-new', $store->get_job( $job_id )->locked_by );

		$checkpoint = array( 'artifact_cursor' => 3 );
		$this->assertFalse( $store->save_checkpoint( $job_id, $checkpoint, 'token-zombie' ), 'A checkpoint fenced with a stale token must be refused.' );
		$this->assertSame( array(), $store->get_job( $job_id )->checkpoint, 'The refused checkpoint must not persist.' );

		$this->assertFalse( $store->set_status( $job_id, MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING, 'token-zombie' ), 'A status write fenced with a stale token must be refused.' );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_PENDING, $store->get_job( $job_id )->status );

		$this->assertTrue( $store->save_checkpoint( $job_id, $checkpoint, 'token-new' ), 'The live holder\'s fenced checkpoint lands.' );
		$this->assertTrue( $store->set_status( $job_id, MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING, 'token-new' ), 'The live holder\'s fenced status write lands.' );
		$this->assertSame( 3, $store->get_job( $job_id )->checkpoint['artifact_cursor'] );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING, $store->get_job( $job_id )->status );
	}

	/**
	 * A zombie worker must not terminally relabel a job another worker now
	 * owns: complete_job, fail_permanent, invalidate_credentials, and
	 * fail_retryable are all refused when fenced with a stale token.
	 *
	 * @return void
	 */
	public function test_zombie_cannot_terminally_relabel_reclaimed_job() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 118, array( 7 ) );

		$now    = time();
		$job    = $store->acquire_next_job( 'token-zombie', 300, $now );
		$job_id = (int) $job->id;

		$this->assertNotNull( $store->acquire_next_job( 'token-new', 300, $now + 301 ), 'The expired lease is reclaimed by a new worker.' );

		$this->assertFalse( $store->complete_job( $job_id, 'token-zombie' ), 'A fenced completion with a stale token must be refused.' );
		$this->assertFalse( $store->fail_permanent( $job_id, 'zombie verdict', 'token-zombie' ), 'A fenced terminal write with a stale token must be refused.' );
		$this->assertFalse( $store->invalidate_credentials( $job_id, 'zombie invalid', 'token-zombie' ), 'A fenced credentials-invalid write with a stale token must be refused.' );
		$this->assertFalse( $store->fail_retryable( $job_id, 'zombie retry', $now + 400, 'token-zombie' ), 'A fenced retryable failure with a stale token must be refused.' );

		$job_after = $store->get_job( $job_id );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_PENDING, $job_after->status, 'The reclaimed job is untouched by the zombie.' );
		$this->assertSame( 0, (int) $job_after->attempt_count, 'The refused fail_retryable must not bump attempt_count.' );

		$this->assertTrue( $store->complete_job( $job_id, 'token-new' ), 'The live holder\'s fenced terminal write lands.' );
	}

	/**
	 * A fenced terminal write refused by a stale token must not fire the
	 * settled event - that announcement belongs to the write that actually
	 * lands.
	 *
	 * @return void
	 */
	public function test_fenced_terminal_refusal_fires_no_settled_event() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 119, array( 7 ) );

		$now    = time();
		$job    = $store->acquire_next_job( 'token-zombie', 300, $now );
		$job_id = (int) $job->id;
		$this->assertNotNull( $store->acquire_next_job( 'token-new', 300, $now + 301 ) );

		$settled = array();
		add_action(
			'mainwp_backup_upload_job_settled',
			function ( $snapshot_id ) use ( &$settled ) {
				$settled[] = $snapshot_id;
			}
		);

		$this->assertFalse( $store->complete_job( $job_id, 'token-zombie' ) );
		$this->assertSame( array(), $settled, 'A refused fenced completion must not fire the settled event.' );

		$this->assertTrue( $store->complete_job( $job_id, 'token-new' ) );
		$this->assertSame( array( 119 ), $settled, 'The completion that actually lands fires the settled event once.' );
	}

	/**
	 * Tokenless writes keep working unchanged: the legacy/UI
	 * first-terminal-writer-wins semantics are preserved even while
	 * another lane holds the lease.
	 *
	 * @return void
	 */
	public function test_tokenless_writes_bypass_fencing() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 120, array( 7 ) );

		$now    = 1000000;
		$job    = $store->acquire_next_job( 'token-a', 300, $now );
		$job_id = (int) $job->id;
		$this->assertNotNull( $store->acquire_next_job( 'token-b', 300, $now + 301 ), 'The expired lease is reclaimed by a new worker.' );

		$this->assertTrue( $store->set_status( $job_id, MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING ), 'A tokenless status write is not fenced.' );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING, $store->get_job( $job_id )->status );
	}

	/**
	 * The widened fail_retryable() signature with the token omitted
	 * behaves exactly as before: a regression guard for the pre-fencing
	 * callers.
	 *
	 * @return void
	 */
	public function test_fail_retryable_without_token_still_lands() {
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 121, array( 7 ) );

		$now = 1000000;
		$job = $store->acquire_next_job( 'token-a', 300, $now );

		$this->assertTrue( $store->fail_retryable( (int) $job->id, 'network blip', $now + 600 ) );

		$stored = $store->get_job( (int) $job->id );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $stored->status );
		$this->assertSame( 1, (int) $stored->attempt_count );
		$this->assertSame( $now + 600, (int) $stored->next_retry_at );
		$this->assertSame( 'network blip', $stored->last_error );
		$this->assertSame( '', $stored->locked_by );
	}

	/** An expired prune claim is a fresh upload pickup, even at the reclaim ceiling. */
	public function test_x1_expired_prune_claim_does_not_count_as_upload_reclaim() {
		global $wpdb;
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( 901, array( 77 ) );
		$job = $store->get_jobs_for_snapshot( 901 )[0];
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array(
				'locked_by'     => 'prune:expired',
				'locked_until'  => time() - 1,
				'reclaim_count' => MainWP_Backup_Upload_Job_Store::RECLAIM_CEILING,
			),
			array( 'id' => $job->id )
		);
		$picked = $store->acquire_next_job( 'upload-after-prune' );
		$this->assertNotNull( $picked );
		$this->assertSame( (int) $job->id, (int) $picked->id );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::RECLAIM_CEILING, (int) $picked->reclaim_count );
	}
}
