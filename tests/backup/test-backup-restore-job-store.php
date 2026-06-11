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
	 * Cancelled jobs leave the active set.
	 */
	public function test_cancel_job() {
		$job_id = $this->store->create_job( 8801, 42, array() );
		$this->store->cancel_job( $job_id );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, $this->store->get_job( $job_id )->status );
		$this->assertNull( $this->store->get_active_job_for_site( 8801 ) );
	}
}
