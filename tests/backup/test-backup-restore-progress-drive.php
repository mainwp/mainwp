<?php
/**
 * Restore progress-poll drive test: the foreground poll advances the
 * restore one slice and reports the updated status, so an open tab no
 * longer depends on cron to make progress.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_DB;

/**
 * Class MainWP_Backup_Restore_Progress_Drive_Test
 */
class MainWP_Backup_Restore_Progress_Drive_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Remove restore jobs and request state created by the test.
	 */
	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) ); // phpcs:ignore WordPress.DB -- test cleanup.
		unset( $_POST['security'], $_POST['job_id'], $_REQUEST['security'], $_REQUEST['job_id'] );
		parent::tearDown();
	}

	/**
	 * Create a pending restore job for a fresh test site.
	 *
	 * @param string $restore_id Restore id marker.
	 *
	 * @return array{0:int,1:int} Site id and job id.
	 */
	private function make_pending_job( $restore_id ) {
		$site_id = $this->create_test_site();
		$job_id  = MainWP_Backup_Restore_Job_Store::instance()->create_job(
			$site_id,
			5,
			array(
				'restore_id' => $restore_id,
				'sub_type'   => 'db',
				'plan'       => array(),
				'options'    => array(
					'pre_snapshot'  => false,
					'file_rollback' => false,
				),
				'transfer'   => array(),
			)
		);
		return array( $site_id, $job_id );
	}

	/**
	 * Invoke the progress poll for a job under a given foreground budget and
	 * return the decoded JSON payload. The wp_die handler throws so
	 * wp_send_json does not end the test process, and a finally block always
	 * removes the temporary filters even on an unexpected exception.
	 *
	 * @param int      $job_id        Job to poll.
	 * @param callable $budget_filter mainwp_restore_foreground_budget callback.
	 *
	 * @return array|null Decoded response payload.
	 */
	private function poll_progress( $job_id, $budget_filter ) {
		$nonce                = wp_create_nonce( 'mainwp_restore_progress' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['job_id']      = $job_id;

		$throw = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'mainwp_restore_foreground_budget', $budget_filter );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );

		$raw = '';
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->mainwp_restore_progress();
		} catch ( \RuntimeException $e ) {
			// wp_send_json terminated the request as designed.
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $throw );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'mainwp_restore_foreground_budget', $budget_filter );
		}

		return json_decode( $raw, true );
	}

	/**
	 * The progress poll drives a pending job one phase (to preflight) and
	 * reports the advanced status. The budget is filtered to zero so the
	 * slice advances exactly one phase with no child round trip.
	 */
	public function test_progress_poll_drives_one_slice_and_reports() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( , $job_id ) = $this->make_pending_job( 'drive01test' );

		$response = $this->poll_progress( $job_id, '__return_zero' );

		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id );
		$this->assertSame(
			MainWP_Backup_Restore_Job_Store::STATUS_PREFLIGHT,
			$job->status,
			'The poll should advance the pending job one phase via the foreground drive.'
		);

		$this->assertIsArray( $response, 'The poll should still return a status payload.' );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PREFLIGHT, $response['status'] );
	}

	/**
	 * A negative budget disables the foreground drive: the poll reports
	 * status without advancing the job, leaving the cron backstop as the
	 * sole driver.
	 */
	public function test_negative_budget_disables_drive() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( , $job_id ) = $this->make_pending_job( 'drive02test' );

		$minus_one = static function () {
			return -1;
		};
		$response = $this->poll_progress( $job_id, $minus_one );

		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id );
		$this->assertSame(
			MainWP_Backup_Restore_Job_Store::STATUS_PENDING,
			$job->status,
			'A negative budget should leave the job untouched.'
		);

		$this->assertIsArray( $response, 'The read-only poll should still return a status payload.' );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PENDING, $response['status'] );
	}

	/**
	 * The poll drives only the job it polls, never another site's active
	 * restore: a poll for job A advances A and leaves B untouched.
	 */
	public function test_progress_poll_drives_only_the_polled_job() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( , $job_a ) = $this->make_pending_job( 'drivescopeA' );
		list( , $job_b ) = $this->make_pending_job( 'drivescopeB' );

		$this->poll_progress( $job_a, '__return_zero' );

		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertSame(
			MainWP_Backup_Restore_Job_Store::STATUS_PREFLIGHT,
			$store->get_job( $job_a )->status,
			'The polled job advances.'
		);
		$this->assertSame(
			MainWP_Backup_Restore_Job_Store::STATUS_PENDING,
			$store->get_job( $job_b )->status,
			'Another site\'s restore must not be driven by a poll for a different job.'
		);
	}
}
