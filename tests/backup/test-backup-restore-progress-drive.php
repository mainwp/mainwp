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
	 * The progress poll drives a pending job one phase (to preflight) and
	 * reports the advanced status. The budget is filtered to zero so the
	 * slice advances exactly one phase with no child round trip, and a
	 * throwing wp_die handler captures the wp_send_json response without
	 * ending the test process.
	 */
	public function test_progress_poll_drives_one_slice_and_reports() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();

		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job_id = $store->create_job(
			$site_id,
			5,
			array(
				'restore_id' => 'drive01test',
				'sub_type'   => 'db',
				'plan'       => array(),
				'options'    => array(
					'pre_snapshot'  => false,
					'file_rollback' => false,
				),
				'transfer'   => array(),
			)
		);

		$nonce                = wp_create_nonce( 'mainwp_restore_progress' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['job_id']      = $job_id;

		$throw = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'mainwp_restore_foreground_budget', '__return_zero' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );

		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->mainwp_restore_progress();
		} catch ( \RuntimeException $e ) {
			// wp_send_json terminated the request as designed.
		}
		$raw = ob_get_clean();

		remove_filter( 'wp_die_ajax_handler', $throw );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'mainwp_restore_foreground_budget', '__return_zero' );

		$job = $store->get_job( $job_id );
		$this->assertSame(
			MainWP_Backup_Restore_Job_Store::STATUS_PREFLIGHT,
			$job->status,
			'The poll should advance the pending job one phase via the foreground drive.'
		);

		$response = json_decode( $raw, true );
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
		$site_id = $this->create_test_site();

		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job_id = $store->create_job(
			$site_id,
			5,
			array(
				'restore_id' => 'drive02test',
				'sub_type'   => 'db',
				'plan'       => array(),
				'options'    => array(
					'pre_snapshot'  => false,
					'file_rollback' => false,
				),
				'transfer'   => array(),
			)
		);

		$nonce                = wp_create_nonce( 'mainwp_restore_progress' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['job_id']      = $job_id;

		$minus_one = static function () {
			return -1;
		};
		$throw = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'mainwp_restore_foreground_budget', $minus_one );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );

		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->mainwp_restore_progress();
		} catch ( \RuntimeException $e ) {
			// wp_send_json terminated the request as designed.
		}
		ob_get_clean();

		remove_filter( 'wp_die_ajax_handler', $throw );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'mainwp_restore_foreground_budget', $minus_one );

		$job = $store->get_job( $job_id );
		$this->assertSame(
			MainWP_Backup_Restore_Job_Store::STATUS_PENDING,
			$job->status,
			'A negative budget should leave the job untouched.'
		);
	}
}
