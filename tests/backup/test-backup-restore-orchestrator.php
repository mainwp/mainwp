<?php
/**
 * Restore orchestrator tests: phase progression over mocked child
 * calls, failure handling with resume, and the completion hook.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Exception;

/**
 * Transfer driver with the cloud-pull decision and manifest scripted.
 */
class Cloud_Pull_Stub_Transfer_Driver extends MainWP_Backup_Restore_Transfer_Driver { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

	/**
	 * Whether the gates pass (the tried flag still wins).
	 *
	 * @var bool
	 */
	public $cloud_ok = false;

	/**
	 * Scripted manifest, or null for "cannot build one".
	 *
	 * @var array|null
	 */
	public $manifest = null;

	/**
	 * Scripted gate.
	 *
	 * @param object $website    Website.
	 * @param array  $plan       Plan.
	 * @param array  $checkpoint Checkpoint.
	 *
	 * @return bool
	 */
	public function can_use_cloud_pull( object $website, array $plan, array $checkpoint ): bool {
		return $this->cloud_ok && empty( $checkpoint['cloud_pull_tried'] );
	}

	/**
	 * Scripted manifest; fills plan hashes like the real one.
	 *
	 * @param array $plan Plan (by reference).
	 *
	 * @return array|null
	 */
	public function generate_pull_manifest( array &$plan ): ?array {
		if ( null === $this->manifest ) {
			return null;
		}
		foreach ( $plan as $index => $entry ) {
			if ( empty( $entry['adopt_only'] ) ) {
				$plan[ $index ]['sha256'] = str_repeat( 'a', 64 );
			}
		}
		return $this->manifest;
	}
}

/**
 * Orchestrator with the external reachability check pinned.
 */
class Testable_Restore_Orchestrator extends MainWP_Backup_Restore_Orchestrator { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double above.

	/**
	 * Scripted reachability.
	 *
	 * @var bool
	 */
	public $responds = true;

	/**
	 * Driver override.
	 *
	 * @var MainWP_Backup_Restore_Transfer_Driver|null
	 */
	public $driver = null;

	/**
	 * Count of continuation kicks, so tests can assert the immediate
	 * kick fires without scheduling real cron.
	 *
	 * @var int
	 */
	public $continuation_count = 0;

	/**
	 * Pinned check.
	 *
	 * @param object $website Website.
	 *
	 * @return bool
	 */
	protected function site_responds( object $website ): bool {
		return $this->responds;
	}

	/**
	 * Record the kick instead of scheduling real cron / spawning a loopback.
	 */
	protected function schedule_continuation(): void {
		++$this->continuation_count;
	}

	/**
	 * Pinned driver.
	 *
	 * @return MainWP_Backup_Restore_Transfer_Driver
	 */
	protected function transfer_driver(): MainWP_Backup_Restore_Transfer_Driver {
		return null !== $this->driver ? $this->driver : parent::transfer_driver();
	}
}

/**
 * Class MainWP_Backup_Restore_Orchestrator_Test
 */
class MainWP_Backup_Restore_Orchestrator_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Test site id.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Orchestrator under test.
	 *
	 * @var Testable_Restore_Orchestrator
	 */
	private $orchestrator;

	/**
	 * Captured child calls.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Scripted child responses by action.
	 *
	 * @var array
	 */
	private $responses = array();

	/**
	 * Fixture dir.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Set up site, mocks, scratch.
	 */
	public function set_up() {
		parent::set_up();
		$this->site_id      = $this->create_test_site();
		$this->orchestrator = new Testable_Restore_Orchestrator();
		$this->dir          = sys_get_temp_dir() . '/mainwp-orch-test-' . uniqid();
		mkdir( $this->dir );

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $website, $what, $params ) {
				$this->calls[] = array(
					'what'   => $what,
					'params' => $params,
				);
				if ( isset( $this->responses[ $what ] ) ) {
					$scripted = $this->responses[ $what ];
					return is_array( $scripted ) && isset( $scripted[0] ) && is_array( $scripted[0] )
						? array_shift( $this->responses[ $what ] )
						: $scripted;
				}
				return array( 'result' => 'ok' );
			},
			10,
			4
		);
	}

	/**
	 * Cleanup.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) ); // phpcs:ignore WordPress.DB -- test cleanup.
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		parent::tear_down();
	}

	/**
	 * Create a job with a staged-ready local plan.
	 *
	 * @param array $checkpoint_over Checkpoint overrides.
	 * @param string $status         Initial status.
	 * @param array $extra           Extra columns.
	 *
	 * @return object Job row.
	 */
	private function make_job( $checkpoint_over = array(), $status = MainWP_Backup_Restore_Job_Store::STATUS_PENDING, $extra = array() ) {
		$artifact = $this->dir . '/db.zip';
		if ( ! file_exists( $artifact ) ) {
			file_put_contents( $artifact, 'dump-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		}

		$checkpoint = array_merge(
			array(
				'restore_id' => 'orch01test',
				'sub_type'   => 'db',
				'plan'       => array(
					array(
						'role'        => 'db',
						'file'        => 'db.zip',
						'size'        => 10,
						'sha256'      => '',
						'snapshot_id' => 5,
						'source'      => 'local',
						'local_path'  => $artifact,
						'remote_key'  => '',
						'adopt_only'  => false,
					),
				),
				'options'    => array(
					'pre_snapshot'  => false,
					'file_rollback' => false,
				),
				'transfer'   => array(),
			),
			$checkpoint_over
		);

		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job_id = $store->create_job( $this->site_id, 5, $checkpoint );
		if ( MainWP_Backup_Restore_Job_Store::STATUS_PENDING !== $status ) {
			$store->set_status( $job_id, $status, $extra );
		}
		return $store->get_job( $job_id );
	}

	/**
	 * Run the job one slice.
	 *
	 * @param object $job Job row.
	 *
	 * @return object Fresh job row.
	 */
	private function tick( $job ) {
		$this->orchestrator->run_job( MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id ), microtime( true ) + 30 );
		return MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
	}

	/**
	 * Pending routes to preflight first; preflight with a clean local plan
	 * (nothing to pull from cloud) then routes on to staging.
	 */
	public function test_pending_routes_through_preflight_to_staging() {
		$job = $this->make_job();
		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PREFLIGHT, $job->status );
		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
	}

	/**
	 * Staging hashes the plan and creates the child restore task with
	 * artifact metadata.
	 */
	public function test_staging_creates_child_task() {
		$this->responses['restore_task_create'] = array(
			'task_id' => 'task-9-restore',
			'phase'   => 'queued',
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->status );
		$this->assertSame( 'task-9-restore', $job->child_task_id );
		$this->assertSame( hash( 'sha256', 'dump-bytes' ), $job->checkpoint['plan'][0]['sha256'] );

		$create = null;
		foreach ( $this->calls as $call ) {
			if ( 'restore_task_create' === $call['what'] ) {
				$create = $call;
			}
		}
		$this->assertNotNull( $create );
		$artifacts = json_decode( $create['params']['artifacts_json'], true );
		$this->assertSame( 'db.zip', $artifacts[0]['file'] );
		$this->assertSame( hash( 'sha256', 'dump-bytes' ), $artifacts[0]['sha256'] );
	}

	/**
	 * A files-only restore job carries sub_type files through to the
	 * child task-create options untouched.
	 */
	public function test_staging_sends_files_sub_type_for_files_snapshot() {
		$this->responses['restore_task_create'] = array(
			'task_id' => 'task-9-restore',
			'phase'   => 'queued',
		);

		$job = $this->make_job( array( 'sub_type' => 'files' ), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$this->tick( $job );

		$create = null;
		foreach ( $this->calls as $call ) {
			if ( 'restore_task_create' === $call['what'] ) {
				$create = $call;
			}
		}
		$this->assertNotNull( $create );
		$options = json_decode( $create['params']['options_json'], true );
		$this->assertSame( 'files', $options['sub_type'] );
	}

	/**
	 * Wire a cloud-pull-capable stub driver into the orchestrator.
	 *
	 * @param array|null $manifest Scripted manifest.
	 *
	 * @return Cloud_Pull_Stub_Transfer_Driver
	 */
	private function use_cloud_pull_driver( $manifest ) {
		$driver                     = new Cloud_Pull_Stub_Transfer_Driver();
		$driver->cloud_ok           = true;
		$driver->manifest           = $manifest;
		$this->orchestrator->driver = $driver;
		return $driver;
	}

	/**
	 * The scripted pull manifest used by the cloud-pull tests.
	 *
	 * @return array
	 */
	private function pull_manifest_fixture() {
		return array(
			'artifacts' => array(
				array(
					'file'   => 'db.zip',
					'sha256' => str_repeat( 'a', 64 ),
					'size'   => 10,
					'url'    => 'https://bucket.example/5/files/db.zip?X-Amz-Signature=stub',
				),
			),
		);
	}

	/**
	 * When the destination presigns and the child can pull, staging is
	 * skipped: the child task is created with the manifest and the job
	 * goes straight to running.
	 */
	public function test_staging_uses_cloud_pull_when_available() {
		$this->use_cloud_pull_driver( $this->pull_manifest_fixture() );
		$this->responses['restore_task_create'] = array(
			'task_id' => 'task-9-restore',
			'phase'   => 'queued',
		);

		$logged = array();
		add_action(
			'mainwp_log_info',
			function ( $message ) use ( &$logged ) {
				$logged[] = $message;
			}
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $job->status );

		$this->assertNotEmpty( $logged, 'The direct cloud download must leave a log trail.' );
		$this->assertStringContainsString( 'directly from cloud storage', implode( ' ', $logged ) );
		$this->assertStringNotContainsString( 'X-Amz', implode( ' ', $logged ), 'Presigned URLs must never be logged.' );
		$this->assertSame( 'task-9-restore', $job->child_task_id );
		$this->assertSame( str_repeat( 'a', 64 ), $job->checkpoint['plan'][0]['sha256'], 'The plan must carry the upload-recorded hash, not a staged one.' );

		$create = null;
		foreach ( $this->calls as $call ) {
			if ( 'restore_task_create' === $call['what'] ) {
				$create = $call;
			}
		}
		$this->assertNotNull( $create );
		$this->assertSame( $this->pull_manifest_fixture(), json_decode( $create['params']['pull_manifest_json'], true ) );

		$chunks = 0;
		foreach ( $this->calls as $call ) {
			if ( 'restore_artifact_chunk' === $call['what'] ) {
				++$chunks;
			}
		}
		$this->assertSame( 0, $chunks, 'No bytes may stream through the dashboard on the pull path.' );
	}

	/**
	 * A child that rejects the manifest keeps its created task: the job
	 * stages once and streams into that same task without a second
	 * create call.
	 */
	public function test_rejected_pull_manifest_falls_back_to_staged_transfer() {
		$this->use_cloud_pull_driver( $this->pull_manifest_fixture() );
		$this->responses['restore_task_create'] = array(
			'task_id'                => 'task-9-restore',
			'phase'                  => 'queued',
			'pull_manifest_rejected' => true,
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertSame( 'task-9-restore', $job->child_task_id );
		$this->assertTrue( ! empty( $job->checkpoint['cloud_pull_tried'] ) );

		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->status );

		$creates = 0;
		foreach ( $this->calls as $call ) {
			if ( 'restore_task_create' === $call['what'] ) {
				++$creates;
			}
		}
		$this->assertSame( 1, $creates, 'The rejected manifest must not trigger a second task create.' );
	}

	/**
	 * A failed cloud pull on the child re-stages the job once with a
	 * fresh task instead of failing the restore.
	 */
	public function test_running_pull_failure_falls_back_to_staging() {
		$this->responses['restore_task_run'] = array(
			'task_id'    => 'task-9-restore',
			'phase'      => 'failed',
			'error'      => 'This site could not download the backup from cloud storage directly.',
			'error_code' => 'mainwp_cloud_pull_failed',
		);

		$logged = array();
		add_action(
			'mainwp_log_warning',
			function ( $message ) use ( &$logged ) {
				$logged[] = $message;
			}
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertSame( '', (string) $job->child_task_id, 'The failed task handle must clear so staging creates a fresh one.' );
		$this->assertTrue( ! empty( $job->checkpoint['cloud_pull_tried'] ) );
		$this->assertNotEmpty( $logged, 'The fallback must leave a log trail.' );
		$this->assertStringContainsString( 'standard transfer', implode( ' ', $logged ) );

		$this->responses['restore_task_create'] = array(
			'task_id' => 'task-10-restore',
			'phase'   => 'queued',
		);
		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->status );
		$this->assertSame( 'task-10-restore', $job->child_task_id );
	}

	/**
	 * A pull failure after the fallback already ran fails the job: the
	 * fallback never loops.
	 */
	public function test_running_pull_failure_after_fallback_goes_permanent() {
		$this->responses['restore_task_run'] = array(
			'task_id'    => 'task-10-restore',
			'phase'      => 'failed',
			'error'      => 'This site could not download the backup from cloud storage directly.',
			'error_code' => 'mainwp_cloud_pull_failed',
		);

		$job = $this->make_job( array( 'cloud_pull_tried' => true ), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-10-restore' ) );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
	}

	/**
	 * Transfer streams chunks and advances to running.
	 */
	public function test_transfer_advances_to_running() {
		$this->responses['restore_artifact_chunk'] = array(
			'result'         => 'ok',
			'file'           => 'db.zip',
			'bytes_received' => 10,
			'phase'          => 'receiving',
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$this->responses['restore_task_create'] = array(
			'task_id' => 'task-9-restore',
			'phase'   => 'queued',
		);
		$job = $this->tick( $job ); // staging -> transferring.
		$job = $this->tick( $job ); // transferring -> running.

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $job->status );

		$chunks = 0;
		foreach ( $this->calls as $call ) {
			if ( 'restore_artifact_chunk' === $call['what'] ) {
				++$chunks;
				$this->assertSame( 'task-9-restore', $call['params']['task_id'] );
			}
		}
		$this->assertGreaterThan( 0, $chunks );
	}

	/**
	 * A completed child task moves to verifying; a responding site
	 * completes the job and fires the restored hook.
	 */
	public function test_running_to_completed_fires_hook() {
		$this->responses['restore_task_run'] = array(
			'task_id'   => 'task-9-restore',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'row_counts_ok' => true ) ),
		);

		$fired = array();
		add_action(
			'mainwp_backup_restored',
			function ( $event ) use ( &$fired ) {
				$fired[] = $event;
			}
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$job = $this->tick( $job ); // running -> verifying.
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $job->status );

		$job = $this->tick( $job ); // verifying -> completed.
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED, $job->status );
		$this->assertCount( 1, $fired );
		$this->assertSame( $this->site_id, $fired[0]['site_id'] );
		$this->assertTrue( $job->checkpoint['verification']['site_responds'] );
	}

	/**
	 * A failed child task fails the job permanently with the child's
	 * error.
	 */
	public function test_child_failure_goes_permanent() {
		$this->responses['restore_task_run'] = array(
			'task_id'   => 'task-9-restore',
			'phase'     => 'failed',
			'error'     => 'Replay failed on table posts',
			'sub_state' => array( 'error' => array( 'message' => 'Replay failed on table posts' ) ),
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertStringContainsString( 'Replay failed', $job->last_error );
	}

	/**
	 * A failed child task carries its error code through to the job
	 * checkpoint so the progress poll can report a stable code for support
	 * and AI diagnosis alongside the human-readable last_error.
	 */
	public function test_child_failure_surfaces_error_code() {
		$this->responses['restore_task_run'] = array(
			'task_id'    => 'task-9-restore',
			'phase'      => 'failed',
			'error'      => 'The database could not be replayed from the backup.',
			'error_code' => 'mainwp_restore_db_replay',
			'sub_state'  => array(
				'error' => array(
					'message' => 'The database could not be replayed from the backup.',
					'code'    => 'mainwp_restore_db_replay',
				),
			),
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'mainwp_restore_db_replay', $job->checkpoint['last_error_code'] );
	}

	/**
	 * An unreachable site after restore is a retryable failure that
	 * resumes the verifying phase.
	 */
	public function test_unreachable_site_retries_verifying() {
		$this->orchestrator->responds = false;

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, array( 'child_task_id' => 'task-9-restore' ) );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $job->checkpoint['resume_status'] );
		$this->assertSame( 1, (int) $job->attempt_count );
		$this->assertGreaterThan( time(), (int) $job->next_retry_at );
	}

	/**
	 * The pre-snapshot phase records the safety backup's artifacts and
	 * moves to staging.
	 */
	public function test_pre_snapshot_records_artifacts() {
		$this->responses['backup_task_run'] = array(
			'task_id'   => 'task-7-pre',
			'phase'     => 'completed',
			'file'      => 'backup-pre.tar.gz',
			'size'      => 1234,
			'artifacts' => array(
				array(
					'file' => 'backup-pre.tar.gz',
					'size' => 1234,
					'role' => 'full',
				),
			),
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => 'task-7-pre' ) );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertSame( 'backup-pre.tar.gz', $job->checkpoint['pre_snapshot_artifacts'][0]['file'] );
	}

	/**
	 * Initiation refuses while another restore is active for the site.
	 */
	public function test_initiate_refuses_second_active_restore() {
		$this->make_job();

		$this->expectException( MainWP_Exception::class );
		$this->orchestrator->initiate_restore( $this->site_id, 5 );
	}

	/**
	 * A rollback job builds an adopt-only plan from the pre-restore
	 * safety backup.
	 */
	public function test_rollback_builds_adopt_only_plan() {
		$failed = $this->make_job(
			array(
				'pre_snapshot_artifacts' => array(
					array(
						'file' => 'backup-pre.tar.gz',
						'size' => 1234,
						'role' => 'full',
					),
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT
		);

		$job_id = $this->orchestrator->initiate_rollback( $failed );
		$job    = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id );

		$this->assertTrue( $job->checkpoint['plan'][0]['adopt_only'] );
		$this->assertSame( 'backup-pre.tar.gz', $job->checkpoint['plan'][0]['file'] );
		$this->assertSame( 'full', $job->checkpoint['sub_type'] );
	}

	/**
	 * Rollback honors the one-active-restore-per-site guard: with another
	 * restore already active for the site it refuses, so a double-click or
	 * a rollback during a running restore cannot open a second active job.
	 */
	public function test_rollback_refuses_while_restore_active() {
		$failed = $this->make_job(
			array(
				'pre_snapshot_artifacts' => array(
					array(
						'file' => 'backup-pre.tar.gz',
						'size' => 1234,
						'role' => 'full',
					),
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT
		);

		// A second, still-active restore for the same site.
		$this->make_job();

		$this->expectException( MainWP_Exception::class );
		$this->orchestrator->initiate_rollback( $failed );
	}

	/**
	 * Cancel is allowed before the child task starts, and refused once
	 * the dashboard-side verify began (the child's work is done).
	 */
	public function test_cancel_window() {
		$early = $this->make_job();
		$this->orchestrator->cancel_job( (int) $early->id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $early->id )->status );

		$late = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, array( 'child_task_id' => 'task-9-restore' ) );
		$this->expectException( MainWP_Exception::class );
		$this->orchestrator->cancel_job( (int) $late->id );
	}

	/**
	 * Cancelling a running job cancels the child task first and only
	 * marks the job cancelled once the child confirmed.
	 */
	public function test_cancel_running_cancels_child_task() {
		$this->responses['restore_task_cancel'] = array(
			'result' => 'ok',
			'phase'  => 'failed',
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$this->orchestrator->cancel_job( (int) $job->id );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id )->status );

		$cancel = null;
		foreach ( $this->calls as $call ) {
			if ( 'restore_task_cancel' === $call['what'] ) {
				$cancel = $call;
			}
		}
		$this->assertNotNull( $cancel );
		$this->assertSame( 'task-9-restore', $cancel['params']['task_id'] );
	}

	/**
	 * A child mid-slice reports busy; instead of blocking the request on
	 * retries, the cancel is recorded as an intent the cron lane applies
	 * between slices. The job stays running until the child confirms.
	 */
	public function test_cancel_running_busy_child_records_intent() {
		$this->responses['restore_task_cancel'] = array(
			'result' => 'busy',
			'phase'  => 'replaying_db',
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );

		$outcome = $this->orchestrator->cancel_job( (int) $job->id );
		$this->assertSame( 'cancel_pending', $outcome );

		$fresh = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $fresh->status );
		$this->assertTrue( ! empty( $fresh->checkpoint['cancel_intent'] ) );

		$cancel_calls = 0;
		foreach ( $this->calls as $call ) {
			if ( 'restore_task_cancel' === $call['what'] ) {
				++$cancel_calls;
			}
		}
		$this->assertSame( 1, $cancel_calls, 'The busy child is asked once; the intent covers the rest.' );
	}

	/**
	 * The cron lane applies a recorded cancel intent before driving the
	 * child: the child confirms and the job cancels without slice work.
	 */
	public function test_run_job_applies_cancel_intent() {
		$this->responses['restore_task_cancel'] = array(
			'result' => 'ok',
			'phase'  => 'failed',
		);

		$job = $this->make_job(
			array( 'cancel_intent' => true ),
			MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, $job->status );

		foreach ( $this->calls as $call ) {
			$this->assertNotSame( 'restore_task_run', $call['what'], 'A pending cancel must not drive the restore forward.' );
		}
	}

	/**
	 * A still-busy child keeps the intent for the next tick; the restore
	 * is not driven forward under a pending cancel.
	 */
	public function test_run_job_keeps_intent_while_child_busy() {
		$this->responses['restore_task_cancel'] = array(
			'result' => 'busy',
			'phase'  => 'replaying_db',
		);

		$job = $this->make_job(
			array( 'cancel_intent' => true ),
			MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $job->status );
		$this->assertTrue( ! empty( $job->checkpoint['cancel_intent'] ) );

		foreach ( $this->calls as $call ) {
			$this->assertNotSame( 'restore_task_run', $call['what'], 'A pending cancel must not drive the restore forward.' );
		}
	}

	/**
	 * A child that completed before the intent could land finished its
	 * restore: the intent clears and the job follows the normal
	 * completion path instead of being relabeled cancelled.
	 */
	public function test_run_job_clears_intent_when_child_completed() {
		$this->responses['restore_task_cancel'] = array(
			'result' => 'already_terminal',
			'phase'  => 'completed',
		);
		$this->responses['restore_task_run']    = array(
			'task_id'   => 'task-9-restore',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'row_counts_ok' => true ) ),
		);

		$job = $this->make_job(
			array( 'cancel_intent' => true ),
			MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $job->status );
		$this->assertTrue( empty( $job->checkpoint['cancel_intent'] ) );
	}

	/**
	 * A child task that already completed cannot be cancelled - the
	 * restore happened; the job is left for the normal completion path.
	 */
	public function test_cancel_running_with_completed_child_refuses() {
		$this->responses['restore_task_cancel'] = array(
			'result' => 'already_terminal',
			'phase'  => 'completed',
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );

		try {
			$this->orchestrator->cancel_job( (int) $job->id );
			$this->fail( 'Expected the completed child task to refuse the cancel' );
		} catch ( MainWP_Exception $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id )->status );
	}

	/**
	 * The cron lane can advance the job while the child cancel is being
	 * confirmed; a job that reached verifying meanwhile must never be
	 * overwritten with cancelled (the restore happened).
	 */
	public function test_cancel_running_refuses_when_job_advanced_meanwhile() {
		$this->responses['restore_task_cancel'] = array(
			'result' => 'ok',
			'phase'  => 'failed',
		);

		$job   = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$store = MainWP_Backup_Restore_Job_Store::instance();

		// Simulate the concurrent cron write: the moment the child cancel
		// call goes out, the job has already moved to verifying.
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $website, $what ) use ( $job, $store ) {
				if ( 'restore_task_cancel' === $what ) {
					$store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING );
				}
				return $pre;
			},
			5,
			3
		);

		try {
			$this->orchestrator->cancel_job( (int) $job->id );
			$this->fail( 'Expected the advanced job to refuse the cancel' );
		} catch ( MainWP_Exception $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $store->get_job( (int) $job->id )->status );
	}

	/**
	 * A child task that already failed needs no child-side work; the
	 * job cancels directly.
	 */
	public function test_cancel_running_with_failed_child_cancels() {
		$this->responses['restore_task_cancel'] = array(
			'result' => 'already_terminal',
			'phase'  => 'failed',
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$this->orchestrator->cancel_job( (int) $job->id );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id )->status );
	}

	/**
	 * Initiating a job kicks an immediate continuation so the restore
	 * starts within seconds instead of waiting for the next 5-minute tick.
	 */
	public function test_initiate_rollback_kicks_immediate_continuation() {
		$failed = $this->make_job(
			array(
				'pre_snapshot_artifacts' => array(
					array(
						'file' => 'backup-pre.tar.gz',
						'size' => 1234,
						'role' => 'full',
					),
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT
		);

		$this->assertSame( 0, $this->orchestrator->continuation_count );
		$this->orchestrator->initiate_rollback( $failed );
		$this->assertSame( 1, $this->orchestrator->continuation_count, 'initiate_rollback must kick a continuation.' );
	}

	/**
	 * One budgeted slice drains the cheap phases (pending -> preflight ->
	 * staging -> running) instead of advancing a single phase per tick, and
	 * schedules a near-term continuation because the job is still mid-flight.
	 */
	public function test_process_pending_drains_multiple_phases_in_one_slice() {
		$this->use_cloud_pull_driver( $this->pull_manifest_fixture() );
		$this->responses['restore_task_create'] = array(
			'task_id' => 'task-9-restore',
			'phase'   => 'queued',
		);

		$this->make_job(); // pending, local plan, pre_snapshot off.

		$this->orchestrator->process_pending_restore_jobs( 30 );

		$job = MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id );
		$this->assertNotNull( $job );
		$this->assertSame(
			MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
			$job->status,
			'A single slice must drain pending -> preflight -> staging -> running.'
		);
		$this->assertGreaterThanOrEqual( 1, $this->orchestrator->continuation_count, 'A mid-flight job must schedule a continuation.' );
	}

	/**
	 * A slice that reaches a terminal state does not schedule a
	 * continuation - there is nothing left to drive.
	 */
	public function test_process_pending_no_continuation_when_terminal() {
		$this->orchestrator->responds = true;
		$this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, array( 'child_task_id' => 'task-9-restore' ) );

		$this->orchestrator->process_pending_restore_jobs( 30 );

		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $this->job_id_for_site() );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED, $job->status );
		$this->assertSame( 0, $this->orchestrator->continuation_count, 'A terminal job must not kick a continuation.' );
	}

	/**
	 * Newest job id for the test site (active or terminal).
	 *
	 * @return int
	 */
	private function job_id_for_site() {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB -- test helper.
			$wpdb->prepare(
				'SELECT id FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' WHERE site_id = %d ORDER BY id DESC LIMIT 1',
				$this->site_id
			)
		);
	}
}
