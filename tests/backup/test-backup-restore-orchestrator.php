<?php
/**
 * Restore orchestrator tests: phase progression over mocked child
 * calls, failure handling with resume, and the completion hook.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Backoff;
use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Transfer driver with the cloud-pull decision and manifest scripted.
 */
class Cloud_Pull_Stub_Transfer_Driver extends MainWP_Backup_Restore_Transfer_Driver {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

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
class Testable_Restore_Orchestrator extends MainWP_Backup_Restore_Orchestrator {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double above.

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
		update_option( 'mainwp_enableNativeBackups', 1 );
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
					if ( $scripted instanceof \Throwable ) {
						throw $scripted;
					}
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
		// The shared teardown drops the site rows but not their options, and
		// wp_options has no cascade, so handle rows would outlive their site.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_options' ) . ' WHERE name = %s', MainWP_Backup_Executor::ADHOC_TASK_OPTION ) ); // phpcs:ignore WordPress.DB -- test cleanup.
		if ( ! empty( $this->chain_snapshot_ids ) ) {
			$ids = implode( ',', array_map( 'intval', $this->chain_snapshot_ids ) );
			$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . " WHERE id IN ( {$ids} )" ); // phpcs:ignore WordPress.DB -- test cleanup.
		}
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		delete_option( 'mainwp_enableNativeBackups' );
		parent::tear_down();
	}

	/**
	 * Create a job with a staged-ready local plan.
	 *
	 * @param array  $checkpoint_over Checkpoint overrides.
	 * @param string $status         Initial status.
	 * @param array  $extra           Extra columns.
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
	 * A database mutex write failure retries restore creation from staging.
	 */
	public function test_staging_mutex_write_failure_is_retryable() {
		$this->responses['restore_task_create'] = array(
			'error'      => 'Could not acquire the backup lock.',
			'error_code' => 'mainwp_backup_mutex_write_failed',
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( 'mainwp_backup_mutex_write_failed', $job->checkpoint['last_error_code'] );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->checkpoint['resume_status'] );
		$this->assertSame( 1, (int) $job->attempt_count );
		$this->assertGreaterThan( time(), (int) $job->next_retry_at );
	}

	/**
	 * A lock refusal without a code remains a permanent argument error.
	 */
	public function test_staging_uncoded_lock_failure_is_permanent() {
		$this->responses['restore_task_create'] = array(
			'error'      => 'Could not acquire the backup lock.',
			'error_code' => '',
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( '', $job->checkpoint['last_error_code'] );
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
	 * Snapshot ids inserted by make_chain_snapshot, cleaned in tear_down.
	 *
	 * @var int[]
	 */
	private $chain_snapshot_ids = array();

	/**
	 * Insert a snapshot row with on-disk artifacts, chain-linkable.
	 *
	 * Artifact fixture files are namespaced per snapshot on disk (real runs
	 * keep each snapshot in its own run dir) while the recorded archive
	 * 'file' stays the bare basename - every increment in a real chain
	 * repeats the same sidecar basenames.
	 *
	 * @param int|null $parent_id Parent snapshot id, or null for a base.
	 * @param string   $type      Snapshot type.
	 * @param array    $archives  Archive entries (file/role).
	 *
	 * @return int Snapshot id.
	 */
	private function make_chain_snapshot( $parent_id, $type, $archives ) {
		global $wpdb;

		$serial = count( $this->chain_snapshot_ids );
		foreach ( $archives as &$archive ) {
			$archive['path'] = $this->dir . '/snap' . $serial . '-' . $archive['file'];
			file_put_contents( $archive['path'], 'artifact-bytes-' . $serial . '-' . $archive['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
			$archive['size'] = (int) filesize( $archive['path'] );
		}
		unset( $archive );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'task_id'       => 991,
				'site_id'       => $this->site_id,
				'type'          => $type,
				'parent_id'     => $parent_id,
				'created'       => time() + $serial,
				'archives'      => wp_json_encode( $archives ),
				'manifest_path' => '',
				'status'        => 'complete',
			)
		);
		$id                         = (int) $wpdb->insert_id;
		$this->chain_snapshot_ids[] = $id;
		return $id;
	}

	/**
	 * Restoring the tip of an incremental chain ships the child the whole
	 * chain in apply order: the base full first, each increment's
	 * tombstones before its parts in chain order, and only the
	 * chain-newest db dump, last. Staged names carry the s<snapshot_id>-
	 * namespace so the increments' repeated sidecar basenames cannot
	 * collide on the child.
	 */
	public function test_staging_ships_incremental_chain_plan_in_order() {
		// Archives are recorded in the child's report order for an
		// incremental run: manifest, tombstones, db, parts.
		$base_id = $this->make_chain_snapshot(
			null,
			'full',
			array(
				array( 'file' => 'files.list', 'role' => 'manifest' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'full001.zip', 'role' => 'full' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			)
		);
		$inc1_id = $this->make_chain_snapshot(
			$base_id,
			'incremental',
			array(
				array( 'file' => 'files.list', 'role' => 'manifest' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'tombstones.list', 'role' => 'tombstones' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'db.zip', 'role' => 'db' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'plugins.part001.zip', 'role' => 'part' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'uploads.part001.zip', 'role' => 'part' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			)
		);
		$inc2_id = $this->make_chain_snapshot(
			$inc1_id,
			'incremental',
			array(
				array( 'file' => 'files.list', 'role' => 'manifest' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'tombstones.list', 'role' => 'tombstones' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'db.zip', 'role' => 'db' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				array( 'file' => 'plugins.part001.zip', 'role' => 'part' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			)
		);

		$this->responses['restore_task_create'] = array(
			'task_id' => 'chain-restore-task',
			'phase'   => 'queued',
		);

		$job_id = $this->orchestrator->initiate_restore( $this->site_id, $inc2_id );
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job    = $store->get_job( $job_id );

		$guard = 0;
		while ( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING !== $job->status && $guard < 5 ) {
			$job = $this->tick( $job );
			++$guard;
		}
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->status );
		$this->assertSame( 'chain-restore-task', $job->child_task_id );

		$create = null;
		foreach ( $this->calls as $call ) {
			if ( 'restore_task_create' === $call['what'] ) {
				$create = $call;
			}
		}
		$this->assertNotNull( $create );

		$options = json_decode( $create['params']['options_json'], true );
		$this->assertSame( 'full', $options['sub_type'], 'A chain with file parts restores as a full.' );

		$artifacts = json_decode( $create['params']['artifacts_json'], true );
		$this->assertSame(
			array(
				array( 'full', 's' . $base_id . '-full001.zip' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact expectation.
				array( 'tombstones', 's' . $inc1_id . '-tombstones.list' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact expectation.
				array( 'part', 's' . $inc1_id . '-plugins.part001.zip' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact expectation.
				array( 'part', 's' . $inc1_id . '-uploads.part001.zip' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact expectation.
				array( 'tombstones', 's' . $inc2_id . '-tombstones.list' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact expectation.
				array( 'part', 's' . $inc2_id . '-plugins.part001.zip' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact expectation.
				array( 'db', 's' . $inc2_id . '-db.zip' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact expectation.
			),
			array_map(
				function ( $artifact ) {
					return array( $artifact['role'], $artifact['file'] );
				},
				$artifacts
			),
			'The chain must ship base-first, per-increment tombstones before parts, chain-newest db last.'
		);

		// Exactly one db entry survives: increment 1's dump must not ship.
		$db_files = array();
		foreach ( $artifacts as $artifact ) {
			if ( 'db' === $artifact['role'] ) {
				$db_files[] = $artifact['file'];
			}
		}
		$this->assertSame( array( 's' . $inc2_id . '-db.zip' ), $db_files );

		// Staged names are unique on the wire despite repeated basenames.
		$names = array_column( $artifacts, 'file' );
		$this->assertSame( $names, array_unique( $names ) );

		// Every non-adopt artifact staged with a real hash of its bytes.
		foreach ( $artifacts as $artifact ) {
			$this->assertNotSame( '', $artifact['sha256'], $artifact['file'] . ' must carry a staging hash.' );
		}
	}

	/**
	 * A preserve-orders restore carries the flag and the backup timestamp
	 * through to the child task-create options.
	 */
	public function test_staging_sends_preserve_orders_and_woo_order_since() {
		$this->responses['restore_task_create'] = array(
			'task_id' => 'task-9-restore',
			'phase'   => 'queued',
		);

		$job = $this->make_job(
			array(
				'options' => array(
					'pre_snapshot'    => false,
					'file_rollback'   => false,
					'preserve_orders' => true,
					'woo_order_since' => 1700000000,
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_STAGING
		);
		$this->tick( $job );

		$create = null;
		foreach ( $this->calls as $call ) {
			if ( 'restore_task_create' === $call['what'] ) {
				$create = $call;
			}
		}
		$this->assertNotNull( $create );
		$options = json_decode( $create['params']['options_json'], true );
		$this->assertTrue( $options['preserve_orders'] );
		$this->assertSame( 1700000000, $options['woo_order_since'] );
	}

	/**
	 * Without the opt-in, the child task-create options carry preserve_orders
	 * off and a zero timestamp - a non-preserve restore is unchanged.
	 */
	public function test_staging_preserve_orders_default_off() {
		$this->responses['restore_task_create'] = array(
			'task_id' => 'task-9-restore',
			'phase'   => 'queued',
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$this->tick( $job );

		$create = null;
		foreach ( $this->calls as $call ) {
			if ( 'restore_task_create' === $call['what'] ) {
				$create = $call;
			}
		}
		$this->assertNotNull( $create );
		$options = json_decode( $create['params']['options_json'], true );
		$this->assertFalse( $options['preserve_orders'] );
		$this->assertSame( 0, $options['woo_order_since'] );
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
		$job                                    = $this->tick( $job );
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

		$job                                    = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$this->responses['restore_task_create'] = array(
			'task_id' => 'task-9-restore',
			'phase'   => 'queued',
		);
		$job                                    = $this->tick( $job ); // staging -> transferring.
		$job                                    = $this->tick( $job ); // transferring -> running.

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
	 * A plan whose artifact is already staged, for jobs entering the
	 * transferring phase directly.
	 *
	 * @return array Plan.
	 */
	private function staged_plan_fixture() {
		$artifact = $this->dir . '/staged-db.zip';
		if ( ! file_exists( $artifact ) ) {
			file_put_contents( $artifact, 'dump-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		}
		return array(
			array(
				'role'        => 'db',
				'file'        => 'db.zip',
				'size'        => 10,
				'sha256'      => hash( 'sha256', 'dump-bytes' ),
				'snapshot_id' => 5,
				'source'      => 'local',
				'local_path'  => $artifact,
				'staged_path' => $artifact,
				'remote_key'  => '',
				'adopt_only'  => false,
			),
		);
	}

	/**
	 * A retryable failure that follows real transfer progress resets the
	 * attempt budget: MAX_ATTEMPTS bounds consecutive no-progress
	 * failures, not the total hiccups a large transfer rides through.
	 */
	public function test_progress_since_last_failure_resets_attempt_budget() {
		$this->responses['restore_artifact_chunk'] = array( 'error' => 'The connection to the site dropped.' );

		$job   = $this->make_job(
			array(
				'plan'                  => $this->staged_plan_fixture(),
				'transfer'              => array(
					'artifact_index' => 0,
					'offset'         => 5,
					'chunk_bytes'    => 262144,
				),
				'last_failure_transfer' => array(
					'artifact_index' => 0,
					'offset'         => 0,
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$store->fail_retryable( (int) $job->id, 'seed', 5, 0 );
		$store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING );

		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( 1, (int) $job->attempt_count, 'Progress since the last failure must reset the attempt budget.' );
		$this->assertGreaterThan( time(), (int) $job->next_retry_at );
		$this->assertLessThanOrEqual( time() + 310, (int) $job->next_retry_at, 'The reset budget must ride the attempt-1 delay.' );
		$this->assertSame(
			array(
				'artifact_index' => 0,
				'offset'         => 5,
			),
			$job->checkpoint['last_failure_transfer'],
			'The failure must move the watermark to the current cursor.'
		);
	}

	/**
	 * A failure with no transfer progress since the previous one keeps
	 * counting up the attempt budget, exactly as before.
	 */
	public function test_no_progress_failure_still_increments_attempts() {
		$this->responses['restore_artifact_chunk'] = array( 'error' => 'The connection to the site dropped.' );

		$job   = $this->make_job(
			array(
				'plan'                  => $this->staged_plan_fixture(),
				'transfer'              => array(
					'artifact_index' => 0,
					'offset'         => 5,
					'chunk_bytes'    => 262144,
				),
				'last_failure_transfer' => array(
					'artifact_index' => 0,
					'offset'         => 5,
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$store->fail_retryable( (int) $job->id, 'seed', 5, 0 );
		$store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING );

		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( 6, (int) $job->attempt_count, 'A no-progress failure must keep counting.' );
		$this->assertGreaterThan( time() + 9000, (int) $job->next_retry_at, 'Attempt 6 must ride its own backoff delay.' );
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
	 * Completion must preserve a newer backup's site-wide handle.
	 *
	 * @dataProvider pre_snapshot_completion_handles
	 *
	 * @param string $handle_task_id Persisted task id when the child completes.
	 * @param bool   $cleared        Whether completion owns the handle.
	 */
	public function test_pre_snapshot_completion_releases_only_owned_handle( $handle_task_id, $cleared ) {
		$this->responses['backup_task_run'] = array(
			'task_id'   => 'task-7-pre',
			'phase'     => 'completed',
			'artifacts' => array(
				array(
					'file' => 'backup-pre.tar.gz',
					'size' => 1234,
					'role' => 'full',
				),
			),
		);
		$job                                = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => 'task-7-pre' ) );
		$handle                             = wp_json_encode(
			array(
				'task_id' => $handle_task_id,
				'owner'   => (string) $job->id,
			)
		);
		MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertSame( $this->responses['backup_task_run']['artifacts'], $job->checkpoint['pre_snapshot_artifacts'] );
		$remaining = MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION );
		$this->assertSame( $cleared ? null : $handle, $remaining );
		if ( ! $cleared ) {
			$this->assertSame( $handle_task_id, json_decode( $remaining, true )['task_id'] );
		}
	}

	/**
	 * Failure must release its own handle without displacing a newer backup.
	 *
	 * @dataProvider pre_snapshot_completion_handles
	 *
	 * @param string $handle_task_id Persisted task id when the child fails.
	 * @param bool   $cleared        Whether the failed task owns the handle.
	 */
	public function test_pre_snapshot_failure_releases_only_owned_handle( $handle_task_id, $cleared ) {
		$this->responses['backup_task_run'] = array(
			'task_id' => 'task-7-pre',
			'phase'   => 'failed',
			'error'   => 'Safety backup failed.',
		);
		$job                                = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => 'task-7-pre' ) );
		$handle                             = wp_json_encode(
			array(
				'task_id' => $handle_task_id,
				'owner'   => (string) $job->id,
			)
		);
		MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( $cleared ? null : $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
	}

	/**
	 * An unreadable response cannot establish that the child task stopped.
	 *
	 * @dataProvider pre_snapshot_answer_shapes
	 *
	 * @param mixed  $payload    Child response.
	 * @param int    $attempts   Prior failures.
	 * @param string $status     Expected failure status.
	 * @param bool   $cleared    Whether the handle must be released.
	 * @param string $last_error Expected stored message.
	 */
	public function test_pre_snapshot_answer_shapes( $payload, $attempts, $status, $cleared, $last_error ) {
		$this->responses['backup_task_run'] = $payload;
		$job                                = $this->make_job(
			array(),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array(
				'pre_snapshot_task_id' => 'task-7-pre',
				'attempt_count'        => $attempts,
			)
		);
		$handle                             = wp_json_encode(
			array(
				'task_id' => 'task-7-pre',
				'owner'   => (string) $job->id,
			)
		);
		MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		$job = $this->tick( $job );

		$this->assertSame( $status, $job->status );
		$this->assertSame( $last_error, $job->last_error );
		$this->assertSame( $cleared ? null : $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
		if ( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $status ) {
			$this->assertSame( 1, (int) $job->attempt_count );
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, $job->checkpoint['resume_status'] );
		}
	}

	/**
	 * The fetch stub bypasses scalar coercion, so both shapes need coverage.
	 *
	 * @return array
	 */
	public static function pre_snapshot_answer_shapes() {
		$unreadable = 'The site did not send a readable answer about the safety backup. The backup may still be running, so MainWP will try again.';
		return array(
			'unreadable response'  => array( '<html>Unavailable</html>', 0, 'failed_retryable', false, $unreadable ),
			'coerced response'     => array( array( 'fetch_url_output' => 'Service Unavailable' ), 0, 'failed_retryable', false, $unreadable ),
			'error payload'        => array( array( 'error' => 'Task not found.' ), 0, 'failed_permanent', true, 'Task not found.' ),
			'failed without error' => array( array( 'phase' => 'failed' ), 0, 'failed_permanent', true, 'The pre-restore safety backup failed.' ),
			'failed phase'         => array(
				array(
					'phase' => 'failed',
					'error' => 'x',
				),
				0,
				'failed_permanent',
				true,
				'x',
			),
			'attempts exhausted'   => array( array( 'fetch_url_output' => 'Service Unavailable' ), MainWP_Backup_Backoff::MAX_ATTEMPTS - 1, 'failed_permanent', true, $unreadable ),
		);
	}

	/**
	 * Terminal failure survives site deletion and releases before its event.
	 *
	 * @dataProvider terminal_failure_sites
	 *
	 * @param bool $deleted Whether the site was deleted while its job remained.
	 */
	public function test_terminal_failure_releases_before_hook_even_with_deleted_site( $deleted ) {
		$this->responses['backup_task_run'] = array( 'fetch_url_output' => 'Service Unavailable' );
		$job                                = $this->make_job(
			array(),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array(
				'pre_snapshot_task_id' => 'task-7-pre',
				'attempt_count'        => MainWP_Backup_Backoff::MAX_ATTEMPTS - 1,
			)
		);
		$handle                             = wp_json_encode(
			array(
				'task_id' => 'task-7-pre',
				'owner'   => (string) $job->id,
			)
		);
		MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		if ( $deleted ) {
			MainWP_DB::instance()->remove_website( $this->site_id );
			$this->assertEmpty( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		}

		$events   = array();
		$listener = function ( $event ) use ( &$events ) {
			$events[] = array(
				'job_id' => $event['job_id'],
				'handle' => MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ),
			);
		};
		add_action( 'mainwp_backup_restore_failed', $listener );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_action( 'mainwp_backup_restore_failed', $listener );
		}

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id )->status );
		$this->assertSame(
			array(
				array(
					'job_id' => (int) $job->id,
					'handle' => null,
				),
			),
			$events
		);
	}

	/**
	 * Both a surviving site and an orphaned job must publish the failure.
	 *
	 * @return array
	 */
	public static function terminal_failure_sites() {
		return array(
			'existing site' => array( false ),
			'deleted site'  => array( true ),
		);
	}

	/**
	 * Reclaim failures release owned handles before the event, even without a site.
	 *
	 * @dataProvider reclaim_failure_handles
	 *
	 * @param bool $owned   Whether this job owns the site's handle.
	 * @param bool $deleted Whether the site was deleted after the job was created.
	 */
	public function test_reclaim_ceiling_releases_owned_handle_before_failure_hook( $owned, $deleted ) {
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job    = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => 'task-7-pre' ) );
		$handle = wp_json_encode(
			array(
				'task_id' => 'task-7-pre',
				'owner'   => (string) ( $owned ? $job->id : $job->id + 1 ),
			)
		);
		MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		$this->expire_job_at_reclaim_ceiling( (int) $job->id );
		if ( $deleted ) {
			MainWP_DB::instance()->remove_website( $this->site_id );
		}
		$events   = array();
		$listener = function ( $event ) use ( &$events, $store ) {
			$events[] = array(
				'job_id' => $event['job_id'],
				'status' => $store->get_job( $event['job_id'] )->status,
				'handle' => MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ),
			);
		};
		add_action( 'mainwp_backup_restore_failed', $listener );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30 );
			$this->orchestrator->process_pending_restore_jobs( 30 );
		} finally {
			remove_action( 'mainwp_backup_restore_failed', $listener );
		}

		$fresh = $store->get_job( (int) $job->id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $fresh->status );
		$this->assertStringContainsString( sprintf( 'Reclaimed %d times', $store::RECLAIM_CEILING ), $fresh->last_error );
		$this->assertSame( '', $fresh->checkpoint['last_error_code'] );
		$this->assertSame(
			array(
				array(
					'job_id' => (int) $job->id,
					'status' => MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT,
					'handle' => $owned ? null : $handle,
				),
			),
			$events
		);
		$this->assertSame( $owned ? null : $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Ownership is checked on the same site's handle, including adopted task ids.
	 *
	 * @return array
	 */
	public static function reclaim_failure_handles() {
		return array(
			'owned handle'  => array( true, false ),
			'foreign owner' => array( false, false ),
			'deleted site'  => array( true, true ),
		);
	}

	/**
	 * A foreground poll must not fail another site's expired restore.
	 */
	public function test_scoped_poll_fails_only_its_over_ceiling_job() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT );
		$other = $store->create_job( $this->create_test_site(), 5, array( 'restore_id' => 'other-restore' ) );
		$this->expire_job_at_reclaim_ceiling( (int) $job->id );
		$this->expire_job_at_reclaim_ceiling( $other );
		$events   = array();
		$listener = function ( $event ) use ( &$events ) {
			$events[] = $event['job_id'];
		};
		add_action( 'mainwp_backup_restore_failed', $listener );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_action( 'mainwp_backup_restore_failed', $listener );
		}

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $store->get_job( (int) $job->id )->status );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PENDING, $store->get_job( $other )->status );
		$this->assertSame( array( (int) $job->id ), $events );
		$found = $store->find_jobs_over_reclaim_ceiling( $store::active_statuses(), time(), $other );
		$this->assertCount( 1, $found );
		$this->assertSame( $other, (int) $found[0]->id );
		$this->assertNull( $store->acquire_next_job( 'over-ceiling', 300, null, $other ) );
	}

	/**
	 * Simulate dead workers without waiting for wall-clock leases to expire.
	 *
	 * @param int $job_id Job to leave at the reclaim ceiling with an expired lease.
	 */
	private function expire_job_at_reclaim_ceiling( int $job_id ): void {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$now   = time() - ( $store::RECLAIM_CEILING + 2 ) * 301;
		$this->assertNotNull( $store->acquire_next_job( 'initial-' . $job_id, 300, $now, $job_id ) );
		for ( $i = 1; $i <= $store::RECLAIM_CEILING; ++$i ) {
			$now      += 301;
			$reclaimed = $store->acquire_next_job( 'reclaim-' . $job_id . '-' . $i, 300, $now, $job_id );
			$this->assertNotNull( $reclaimed );
			$this->assertSame( $i, (int) $reclaimed->reclaim_count );
		}
	}

	/**
	 * Exhausting retries does not authorize releasing another lane's handle.
	 */
	public function test_pre_snapshot_permanent_failure_preserves_foreign_owner_handle() {
		$this->responses['backup_task_run'] = array( 'fetch_url_output' => 'Service Unavailable' );
		$job                                = $this->make_job(
			array(),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array(
				'pre_snapshot_task_id' => 'task-7-pre',
				'attempt_count'        => MainWP_Backup_Backoff::MAX_ATTEMPTS - 1,
			)
		);
		$handle                             = wp_json_encode(
			array(
				'task_id' => 'task-7-pre',
				'owner'   => (string) ( $job->id + 1 ),
			)
		);
		MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
	}

	/**
	 * A child response can arrive after another backup replaces the handle.
	 *
	 * @return array
	 */
	public static function pre_snapshot_completion_handles() {
		return array(
			'own handle'     => array( 'task-7-pre', true ),
			'foreign handle' => array( 'task-8-other', false ),
		);
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
	 * With native backups disabled the restore kill switch refuses before
	 * any website lookup or job row is created - mirrors the executor's
	 * ERR_LEGACY_DISABLED gate on the backup-creation side.
	 */
	public function test_initiate_restore_refuses_when_native_backups_disabled() {
		update_option( 'mainwp_enableNativeBackups', 0 );

		try {
			$this->orchestrator->initiate_restore( $this->site_id, 5 );
			$this->fail( 'initiate_restore() must refuse while native backups are disabled.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED, $e->get_message_error_code() );
		}

		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id ), 'A refused initiate must create no job row.' );
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
	 * Cancellation preserves handles belonging to other lanes.
	 *
	 * @dataProvider cancel_job_matrix
	 *
	 * @param string       $status           Entry status.
	 * @param string       $child_task_id    Restore task id, or empty.
	 * @param string       $snapshot_task_id Backup task id, or empty.
	 * @param bool         $child_throws     Whether the child call throws.
	 * @param string|array $action           Expected child actions in order, or empty for none.
	 * @param bool         $marker_cleared   Whether the ad-hoc marker must be cleared.
	 * @param string       $outcome          Return value or refusal message fragment.
	 * @param string       $handle_state     Persisted handle fixture before cancellation.
	 * @param string       $handle_after     Expected handle disposition: unchanged or cleared.
	 * @param string       $child_answer     Child cancellation answer.
	 */
	public function test_cancel_job_matrix( $status, $child_task_id, $snapshot_task_id, $child_throws, $action, $marker_cleared, $outcome, $handle_state, $handle_after, $child_answer = 'ok' ) {
		global $wpdb;
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job    = $this->make_job();
		$job_id = (int) $job->id;
		if ( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $status ) {
			$checkpoint                  = $job->checkpoint;
			$checkpoint['resume_status'] = MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT;
			$this->assertTrue( $store->save_checkpoint( $job_id, $checkpoint ) );
		}
		$this->assertTrue(
			$store->set_status(
				$job_id,
				$status,
				array(
					'child_task_id'        => $child_task_id,
					'pre_snapshot_task_id' => $snapshot_task_id,
				)
			)
		);
		$handles       = array(
			'none'        => null,
			'own'         => wp_json_encode(
				array(
					'task_id' => 'task-7-pre',
					'owner'   => (string) $job_id,
				)
			),
			'foreign'     => wp_json_encode(
				array(
					'task_id' => 'task-8-other',
					'owner'   => (string) ( $job_id + 1 ),
				)
			),
			'adopted'     => wp_json_encode(
				array(
					'task_id' => 'task-7-pre',
					'owner'   => (string) ( $job_id + 1 ),
				)
			),
			'legacy'      => wp_json_encode( array( 'task_id' => 'task-7-pre' ) ),
			'undecodable' => '{invalid-json',
		);
		$handle_before = $handles[ $handle_state ];
		if ( null !== $handle_before ) {
			MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle_before );
		}
		$this->assertSame( $handle_before, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );

		$actions = '' === $action ? array() : (array) $action;
		foreach ( $actions as $child_action ) {
			$this->responses[ $child_action ] = array(
				'result' => in_array( $child_answer, array( 'completed', 'failed' ), true ) ? 'already_terminal' : $child_answer,
				'phase'  => $child_answer,
			);
		}
		if ( $child_throws ) {
			$this->responses[ $actions[0] ] = new \Error( 'Child cancellation failed.' );
		}

		$result = null;
		try {
			$result = $this->orchestrator->cancel_job( $job_id );
		} catch ( MainWP_Exception $exception ) {
			$result = $exception;
		}

		if ( 'cancelled' === $outcome ) {
			$this->assertSame( 'cancelled', $result );
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, $store->get_job( $job_id )->status );
		} elseif ( 'cancel_pending' === $outcome ) {
			$this->assertSame( 'cancel_pending', $result );
			$fresh = $store->get_job( $job_id );
			$this->assertSame( $status, $fresh->status );
			$this->assertTrue( $fresh->checkpoint['cancel_intent'] );
		} else {
			$this->assertInstanceOf( MainWP_Exception::class, $result );
			$this->assertStringContainsString( $outcome, $result->getMessage() );
			$this->assertSame( $status, $store->get_job( $job_id )->status );
		}

		$expected_calls = array();
		foreach ( $actions as $expected_action ) {
			$expected_calls[] = array(
				'what'   => $expected_action,
				'params' => array( 'task_id' => 'backup_task_cancel' === $expected_action ? json_decode( $handle_before, true )['task_id'] : $child_task_id ),
			);
		}
		$this->assertSame( $expected_calls, $this->calls );
		$marker = MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION );
		if ( $marker_cleared ) {
			// The clear writes an empty string; the option read answers null for it.
			$this->assertEmpty( $marker );
		}
		$this->assertSame( 'cleared' === $handle_after ? null : $handle_before, $marker );
		if ( 'foreign' === $handle_state ) {
			$this->assertSame( 'task-8-other', json_decode( $marker, true )['task_id'] );
		}
		if ( 'none' === $handle_state ) {
			// The getter also returns null for an empty value, so check row absence.
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MainWP_DB::instance()->get_table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = %s', $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) ); // phpcs:ignore WordPress.DB -- verify no option was inserted.
			$this->assertSame( 0, (int) $count, 'Cancelling without a handle must not create an option row.' );
		}
	}

	/**
	 * Cancellation decisions share the busy rule across child-task phases.
	 *
	 * @return array
	 */
	public static function cancel_job_matrix() {
		return array(
			'pending with task ids'           => array( 'pending', 'task-9-restore', 'task-7-pre', false, 'backup_task_cancel', true, 'cancelled', 'own', 'cleared' ),
			'preflight with task ids'         => array( 'preflight', 'task-9-restore', 'task-7-pre', false, 'backup_task_cancel', true, 'cancelled', 'own', 'cleared' ),
			'preflight no handle'             => array( 'preflight', 'task-9-restore', 'task-7-pre', false, '', false, 'cancelled', 'none', 'unchanged' ),
			'preflight handle before phase'   => array( 'preflight', '', '', false, 'backup_task_cancel', true, 'cancelled', 'own', 'cleared' ),
			'preflight foreign owner'         => array( 'preflight', '', '', false, '', false, 'cancelled', 'foreign', 'unchanged' ),
			'preflight adopted handle'        => array( 'preflight', '', 'task-7-pre', false, '', false, 'cancelled', 'adopted', 'unchanged' ),
			'preflight legacy handle'         => array( 'preflight', '', '', false, '', false, 'cancelled', 'legacy', 'unchanged' ),
			'preflight child throws'          => array( 'preflight', '', '', true, 'backup_task_cancel', true, 'cancelled', 'own', 'cleared' ),
			'preflight undecodable handle'    => array( 'preflight', '', '', false, '', false, 'cancelled', 'undecodable', 'unchanged' ),
			'staging with task ids'           => array( 'staging', 'task-9-restore', 'task-7-pre', false, 'backup_task_cancel', true, 'cancelled', 'own', 'cleared' ),
			'failed_retryable with task ids'  => array( 'failed_retryable', 'task-9-restore', 'task-7-pre', false, 'backup_task_cancel', true, 'cancelled', 'own', 'cleared' ),
			'failed_retryable owned handle'   => array( 'failed_retryable', '', 'task-7-pre', false, 'backup_task_cancel', true, 'cancelled', 'own', 'cleared' ),
			'failed_retryable foreign handle' => array( 'failed_retryable', '', 'task-7-pre', false, '', false, 'cancelled', 'foreign', 'unchanged' ),
			'transferring busy child'         => array( 'transferring', 'task-9-restore', 'task-7-pre', false, 'restore_task_cancel', false, 'cancel_pending', 'own', 'unchanged', 'busy' ),
			'running busy child'              => array( 'running', 'task-9-restore', 'task-7-pre', false, 'restore_task_cancel', false, 'cancel_pending', 'own', 'unchanged', 'busy' ),
			'transferring completed child'    => array( 'transferring', 'task-9-restore', 'task-7-pre', false, array( 'restore_task_cancel', 'backup_task_cancel' ), true, 'cancelled', 'own', 'cleared', 'completed' ),
			'transferring failed child'       => array( 'transferring', 'task-9-restore', 'task-7-pre', false, array( 'restore_task_cancel', 'backup_task_cancel' ), true, 'cancelled', 'own', 'cleared', 'failed' ),
			'transferring with task id'       => array( 'transferring', 'task-9-restore', 'task-7-pre', false, 'restore_task_cancel', false, 'cancelled', 'foreign', 'unchanged' ),
			'transferring with owned handle'  => array( 'transferring', 'task-9-restore', 'task-7-pre', false, array( 'restore_task_cancel', 'backup_task_cancel' ), true, 'cancelled', 'own', 'cleared' ),
			'transferring child throws'       => array( 'transferring', 'task-9-restore', 'task-7-pre', true, array( 'restore_task_cancel', 'backup_task_cancel' ), true, 'cancelled', 'own', 'cleared' ),
			'transferring without task id'    => array( 'transferring', '', 'task-7-pre', false, 'backup_task_cancel', true, 'cancelled', 'own', 'cleared' ),
			'pre_snapshot with task id'       => array( 'pre_snapshot', 'task-9-restore', 'task-7-pre', false, 'backup_task_cancel', true, 'cancelled', 'own', 'cleared' ),
			'pre_snapshot foreign handle'     => array( 'pre_snapshot', 'task-9-restore', 'task-7-pre', false, '', false, 'cancelled', 'foreign', 'unchanged' ),
			'pre_snapshot adopted handle'     => array( 'pre_snapshot', 'task-9-restore', 'task-7-pre', false, '', false, 'cancelled', 'adopted', 'unchanged' ),
			'pre_snapshot no handle'          => array( 'pre_snapshot', 'task-9-restore', 'task-7-pre', false, '', false, 'cancelled', 'none', 'unchanged' ),
			'pre_snapshot legacy'             => array( 'pre_snapshot', 'task-9-restore', 'task-7-pre', false, '', false, 'cancelled', 'legacy', 'unchanged' ),
			'pre_snapshot undecodable'        => array( 'pre_snapshot', 'task-9-restore', 'task-7-pre', false, '', false, 'cancelled', 'undecodable', 'unchanged' ),
			'pre_snapshot without task id'    => array( 'pre_snapshot', 'task-9-restore', '', false, 'backup_task_cancel', true, 'cancelled', 'own', 'cleared' ),
			'pre_snapshot child throws'       => array( 'pre_snapshot', 'task-9-restore', 'task-7-pre', true, 'backup_task_cancel', true, 'cancelled', 'own', 'cleared' ),
			'already cancelled refuses'       => array( 'cancelled', 'task-9-restore', 'task-7-pre', false, '', false, 'The restore is already cancelled.', 'own', 'unchanged' ),
			'verifying refuses'               => array( 'verifying', 'task-9-restore', 'task-7-pre', false, '', false, 'finished its work', 'own', 'unchanged' ),
			'completed refuses'               => array( 'completed', 'task-9-restore', 'task-7-pre', false, '', false, 'finished its work', 'own', 'unchanged' ),
			'failed_permanent refuses'        => array( 'failed_permanent', 'task-9-restore', 'task-7-pre', false, '', false, 'finished its work', 'own', 'unchanged' ),
		);
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
	 * Release is shared: this matrix pins ownership in both confirmed cancel lanes.
	 *
	 * @dataProvider running_cancel_handles
	 *
	 * @param string $handle_state Owned, foreign, or absent handle.
	 * @param bool   $throws       Whether cancelling the backup throws.
	 * @param bool   $cron         Whether cron applies a recorded intent.
	 */
	public function test_running_cancel_releases_only_owned_handle( $handle_state, $throws, $cron ) {
		global $wpdb;
		$this->responses['restore_task_cancel'] = array(
			'result' => 'ok',
			'phase'  => 'failed',
		);
		$job                                    = $this->make_job(
			$cron ? array( 'cancel_intent' => true ) : array(),
			MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
			array(
				'child_task_id'        => 'task-9-restore',
				'pre_snapshot_task_id' => 'task-7-pre',
			)
		);
		$handle                                 = null;
		if ( 'none' !== $handle_state ) {
			$handle = wp_json_encode(
				array(
					'task_id' => 'task-7-pre',
					'owner'   => (string) ( 'own' === $handle_state ? $job->id : $job->id + 1 ),
				)
			);
			MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		}
		if ( $throws ) {
			$this->responses['backup_task_cancel'] = new \Error( 'Child cancellation failed.' );
		}

		if ( $cron ) {
			$job = $this->tick( $job );
		} else {
			$this->assertSame( 'cancelled', $this->orchestrator->cancel_job( (int) $job->id ) );
			$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		}
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, $job->status );
		$this->assertSame( 'own' === $handle_state ? null : $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
		$expected = array(
			array(
				'what'   => 'restore_task_cancel',
				'params' => array( 'task_id' => 'task-9-restore' ),
			),
		);
		if ( 'own' === $handle_state ) {
			$expected[] = array(
				'what'   => 'backup_task_cancel',
				'params' => array( 'task_id' => 'task-7-pre' ),
			);
		}
		$this->assertSame( $expected, $this->calls );
		if ( 'none' === $handle_state ) {
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MainWP_DB::instance()->get_table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = %s', $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) ); // phpcs:ignore WordPress.DB -- verify no option was inserted.
			$this->assertSame( 0, (int) $count );
		}
	}

	/**
	 * Running confirmation has its own matrix because release is shared with cron.
	 *
	 * @return array
	 */
	public static function running_cancel_handles() {
		return array(
			'owned handle'         => array( 'own', false, false ),
			'foreign handle'       => array( 'foreign', false, false ),
			'no handle'            => array( 'none', false, false ),
			'backup cancel throws' => array( 'own', true, false ),
			'cron owned handle'    => array( 'own', false, true ),
			'cron foreign handle'  => array( 'foreign', false, true ),
		);
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

		$handle = wp_json_encode(
			array(
				'task_id' => 'task-7-pre',
				'owner'   => (string) $job->id,
			)
		);
		MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );

		$outcome = $this->orchestrator->cancel_job( (int) $job->id );
		$this->assertSame( 'cancel_pending', $outcome );
		$this->assertSame( $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
		$this->assertNotContains( 'backup_task_cancel', array_column( $this->calls, 'what' ) );

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
	 * A transfer cancel must be durable before the owned backup is cancelled.
	 */
	public function test_transferring_cancel_intent_stops_before_transfer() {
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job    = $this->make_job(
			array( 'cancel_intent' => true ),
			MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
			array(
				'child_task_id'        => 'task-9-restore',
				'pre_snapshot_task_id' => 'task-7-pre',
			)
		);
		$handle = wp_json_encode(
			array(
				'task_id' => 'task-7-pre',
				'owner'   => (string) $job->id,
			)
		);
		MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		$this->responses['restore_task_cancel'] = array( 'result' => 'ok' );
		$statuses                               = array();
		$observer                               = function ( $pre, $website, $what ) use ( &$statuses, $store, $job ) {
			if ( 'backup_task_cancel' === $what ) {
				$statuses[] = $store->get_job( (int) $job->id )->status;
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $observer, 20, 3 );
		try {
			$job = $this->tick( $job );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $observer, 20 );
		}

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, $job->status );
		$this->assertSame( array( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED ), $statuses );
		$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
		$this->assertSame( array( 'restore_task_cancel', 'backup_task_cancel' ), array_column( $this->calls, 'what' ) );
		$this->assertNotContains( 'restore_artifact_chunk', array_column( $this->calls, 'what' ) );
	}

	/**
	 * A cancel arriving during a chunk must be applied before the next send.
	 */
	public function test_transferring_cancel_after_first_chunk_stops_and_releases_handle() {
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job    = $this->make_job(
			array(
				'plan'     => $this->staged_plan_fixture(),
				'transfer' => array( 'chunk_bytes' => 4 ),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
			array(
				'child_task_id'        => 'task-9-restore',
				'pre_snapshot_task_id' => 'task-7-pre',
			)
		);
		$handle = wp_json_encode(
			array(
				'task_id' => 'task-7-pre',
				'owner'   => (string) $job->id,
			)
		);
		MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		$chunks = 0;
		$child  = function ( $pre, $website, $what, $params ) use ( &$chunks, $store, $job ) {
			if ( 'restore_artifact_chunk' !== $what ) {
				return $pre;
			}
			++$chunks;
			if ( 1 === $chunks ) {
				$this->assertTrue( $store->arm_cancel_intent( (int) $job->id ) );
			}
			return array(
				'result'         => 'ok',
				'bytes_received' => (int) $params['offset'] + strlen( base64_decode( $params['chunk_b64'] ) ),
			);
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $child, 20, 4 );
		try {
			$job = $this->tick( $job );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $child, 20 );
		}

		$this->assertSame( 1, $chunks );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, $job->status );
		$this->assertSame( 4, $job->checkpoint['transfer']['offset'] );
		$this->assertSame( array( 'restore_artifact_chunk', 'restore_task_cancel', 'backup_task_cancel' ), array_column( $this->calls, 'what' ) );
		$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
	}

	/**
	 * Both the entry checkpoint and a cancel arriving after it block transfer.
	 *
	 * @dataProvider transferring_intent_reads
	 *
	 * @param bool $stale Whether run_job receives a row read before the intent.
	 */
	public function test_transferring_busy_child_keeps_cancel_intent( $stale ) {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, array( 'child_task_id' => 'task-9-restore' ) );
		$this->assertTrue( $store->arm_cancel_intent( (int) $job->id ) );
		$this->responses['restore_task_cancel'] = array( 'result' => 'busy' );

		$this->orchestrator->run_job( $stale ? $job : $store->get_job( (int) $job->id ), microtime( true ) + 30 );

		$fresh = $store->get_job( (int) $job->id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $fresh->status );
		$this->assertTrue( $fresh->checkpoint['cancel_intent'] );
		$this->assertSame( array( 'restore_task_cancel' ), array_column( $this->calls, 'what' ) );
		$this->assertNotContains( 'restore_artifact_chunk', array_column( $this->calls, 'what' ) );
	}

	/**
	 * The transfer phase must re-read an intent missed by the entry check.
	 *
	 * @return array
	 */
	public static function transferring_intent_reads() {
		return array(
			'entry intent' => array( false ),
			'late intent'  => array( true ),
		);
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
	 * A completed child must reach verification without receiving more chunks.
	 */
	public function test_transferring_cancel_intent_when_child_completed_skips_chunks() {
		$this->responses['restore_task_cancel']    = array(
			'result' => 'already_terminal',
			'phase'  => 'completed',
		);
		$this->responses['restore_task_run']       = array(
			'task_id'   => 'task-9-restore',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'row_counts_ok' => true ) ),
		);
		$this->responses['restore_artifact_chunk'] = array( 'error' => 'ERR_RESTORE_WRONG_PHASE' );

		$job = $this->make_job(
			array(
				'cancel_intent' => true,
				'plan'          => $this->staged_plan_fixture(),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $job->status );
		$this->assertTrue( empty( $job->checkpoint['cancel_intent'] ) );
		$this->assertNotContains( 'restore_artifact_chunk', array_column( $this->calls, 'what' ) );
		$this->assertSame( array( 'restore_task_cancel', 'restore_task_run' ), array_column( $this->calls, 'what' ) );
	}

	/**
	 * A cancel recorded while a slice's child round trip was in
	 * flight must stop the loop before the NEXT slice. The intent lands
	 * during the first restore_task_run round trip (the UI's unfenced
	 * busy-cancel write); without the per-slice re-read the loop would
	 * drive the scripted second slice and complete the restore the user
	 * already cancelled.
	 */
	public function test_mid_slice_cancel_stops_next_child_slice() {
		$this->responses['restore_task_cancel'] = array(
			'result' => 'ok',
			'phase'  => 'failed',
		);
		$this->responses['restore_task_run']    = array(
			array(
				'task_id' => 'task-9-restore',
				'phase'   => 'replaying_db',
			),
			array(
				'task_id'   => 'task-9-restore',
				'phase'     => 'completed',
				'sub_state' => array( 'verification' => array( 'row_counts_ok' => true ) ),
			),
		);

		$job    = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$job_id = (int) $job->id;

		// Record the intent the moment the first slice's round trip fires,
		// mirroring a busy-cancel landing while the loop is on the wire.
		// Priority 5 runs before the scripted responder at 10.
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $website, $what ) use ( $job_id ) {
				if ( 'restore_task_run' === $what ) {
					MainWP_Backup_Restore_Job_Store::instance()->arm_cancel_intent( $job_id );
				}
				return $pre;
			},
			5,
			3
		);

		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, $job->status, 'The mid-slice cancel must cancel the job, not let the loop complete the restore.' );

		$run_calls = 0;
		foreach ( $this->calls as $call ) {
			if ( 'restore_task_run' === $call['what'] ) {
				++$run_calls;
			}
		}
		$this->assertSame( 1, $run_calls, 'No further child slice may be driven once the cancel landed.' );
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
	 * One budgeted slice drains every phase to completion instead of
	 * advancing a single phase per 5-minute tick: pending -> preflight ->
	 * staging -> running (the child poll runs to a terminal phase within the
	 * slice) -> verifying -> completed.
	 */
	public function test_process_pending_drains_to_completion_in_one_slice() {
		$this->use_cloud_pull_driver( $this->pull_manifest_fixture() );
		$this->responses['restore_task_create'] = array(
			'task_id' => 'task-9-restore',
			'phase'   => 'queued',
		);
		$this->responses['restore_task_run']    = array(
			'task_id'   => 'task-9-restore',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'row_counts_ok' => true ) ),
		);

		$this->make_job(); // pending, local plan, pre_snapshot off.

		$this->orchestrator->process_pending_restore_jobs( 30 );

		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $this->job_id_for_site() );
		$this->assertSame(
			MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED,
			$job->status,
			'A single slice must drain pending all the way to completed.'
		);
		$this->assertSame( 0, $this->orchestrator->continuation_count, 'A completed job leaves nothing to continue.' );
	}

	/**
	 * A slice that ends with the child still mid-restore schedules a
	 * near-term continuation so the next child poll does not wait for the
	 * 5-minute backstop. A zero budget makes phase_running return at the
	 * (already-passed) deadline without polling, isolating the re-kick.
	 */
	public function test_process_pending_rekicks_a_mid_flight_running_job() {
		$this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );

		$this->orchestrator->process_pending_restore_jobs( 0 );

		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $this->job_id_for_site() );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $job->status, 'A still-working restore stays running.' );
		$this->assertSame( 1, $this->orchestrator->continuation_count, 'A mid-flight job must schedule a continuation.' );
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
	 * A healthy-store preflight response.
	 *
	 * @param array $over Field overrides.
	 *
	 * @return array Preflight response.
	 */
	private function woo_preflight_response( $over = array() ) {
		return array_merge(
			array(
				'woo_hpos_enabled' => true,
				'woo_sync_enabled' => false,
				'woo_db_version'   => '9.4.0',
			),
			$over
		);
	}

	/**
	 * A snapshot row for the refusal checks, optionally carrying woo_meta.
	 *
	 * @param string     $type     Snapshot type.
	 * @param array|null $woo_meta Woo facts, or null for none.
	 *
	 * @return object Snapshot row.
	 */
	private function woo_snapshot( $type = 'full', $woo_meta = null ) {
		global $wpdb;
		$snapshot_id = $this->make_chain_snapshot( null, $type, array( array( 'file' => 'full001.zip', 'role' => 'full' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
		if ( null !== $woo_meta ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array( 'woo_meta' => wp_json_encode( $woo_meta ) ),
				array( 'id' => $snapshot_id )
			);
		}
		return MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
	}

	/**
	 * A store still synchronizing orders to the posts table refuses a
	 * preserve-orders restore at initiate time - the same verdict the
	 * apply-phase gate would deliver after the whole transfer.
	 */
	public function test_woo_preserve_refusal_on_sync_mode_store() {
		$this->responses['restore_preflight'] = $this->woo_preflight_response( array( 'woo_sync_enabled' => true ) );

		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$refusal = $this->orchestrator->woo_preserve_refusal( $website, $this->woo_snapshot() );

		$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_SYNC_MODE, $refusal );
	}

	/**
	 * A store not using HPOS as the authoritative order storage refuses.
	 */
	public function test_woo_preserve_refusal_on_non_hpos_store() {
		$this->responses['restore_preflight'] = $this->woo_preflight_response( array( 'woo_hpos_enabled' => false ) );

		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$refusal = $this->orchestrator->woo_preserve_refusal( $website, $this->woo_snapshot() );

		$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_LEGACY_ORDERS, $refusal );
	}

	/**
	 * An unreachable child defers to the apply-phase gate - the initiate
	 * check must never turn availability problems into refusals.
	 */
	public function test_woo_preserve_refusal_defers_when_child_unreachable() {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $website, $what ) {
				unset( $pre, $website );
				if ( 'restore_preflight' === $what ) {
					throw new MainWP_Exception( 'HTTPERROR', 'cURL error 7' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test mock.
				}
				return null;
			},
			5,
			3
		);

		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$this->assertNull( $this->orchestrator->woo_preserve_refusal( $website, $this->woo_snapshot() ) );
	}

	/**
	 * An older child without the preflight callable answers with an
	 * error payload; the check defers rather than refusing.
	 */
	public function test_woo_preserve_refusal_defers_on_malformed_preflight() {
		$this->responses['restore_preflight'] = array( 'error' => 'Required version has not been detected.' );

		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$this->assertNull( $this->orchestrator->woo_preserve_refusal( $website, $this->woo_snapshot() ) );
	}

	/**
	 * A healthy store with a snapshot that carries no woo_meta (an older
	 * backup) passes the live checks and defers the backup-content ones.
	 */
	public function test_woo_preserve_refusal_defers_without_woo_meta() {
		$this->responses['restore_preflight'] = $this->woo_preflight_response();

		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$this->assertNull( $this->orchestrator->woo_preserve_refusal( $website, $this->woo_snapshot( 'full', null ) ) );
	}

	/**
	 * Unambiguous meta on a full snapshot moves the version-skew check up
	 * to initiate time.
	 */
	public function test_woo_preserve_refusal_on_version_skew_with_meta() {
		$this->responses['restore_preflight'] = $this->woo_preflight_response( array( 'woo_db_version' => '9.5.0' ) );

		$website  = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$snapshot = $this->woo_snapshot(
			'full',
			array(
				'has_wc_orders'          => true,
				'woocommerce_db_version' => '9.4.0',
			)
		);

		$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_VERSION_SKEW, $this->orchestrator->woo_preserve_refusal( $website, $snapshot ) );
	}

	/**
	 * A backup stamped as carrying no wc_orders table refuses: reverting
	 * wp_posts would resurrect shop_order posts under the kept orders.
	 */
	public function test_woo_preserve_refusal_on_backup_without_wc_orders() {
		$this->responses['restore_preflight'] = $this->woo_preflight_response();

		$website  = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$snapshot = $this->woo_snapshot(
			'full',
			array(
				'has_wc_orders'          => false,
				'woocommerce_db_version' => '9.4.0',
			)
		);

		$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_LEGACY_ORDERS, $this->orchestrator->woo_preserve_refusal( $website, $snapshot ) );
	}

	/**
	 * An incremental leaf defers the backup-content checks even with meta:
	 * the post-replay woocommerce_db_version depends on which dump in the
	 * chain last wrote the options table, which meta cannot answer.
	 */
	public function test_woo_preserve_refusal_defers_for_incremental_leaf() {
		$this->responses['restore_preflight'] = $this->woo_preflight_response( array( 'woo_db_version' => '9.5.0' ) );

		$website  = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$snapshot = $this->woo_snapshot(
			'incremental',
			array(
				'has_wc_orders'          => true,
				'woocommerce_db_version' => '9.4.0',
			)
		);

		$this->assertNull( $this->orchestrator->woo_preserve_refusal( $website, $snapshot ) );
	}

	/**
	 * Invoke a wp_send_json AJAX handler and return the decoded response.
	 *
	 * @param string $method Handler method name.
	 *
	 * @return array|null Decoded response.
	 */
	private function invoke_ajax_handler( $method ) {
		$throw = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );

		$raw = '';
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->{$method}();
		} catch ( \RuntimeException $e ) {
			unset( $e ); // wp_send_json terminated the request as designed.
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $throw );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		return json_decode( $raw, true );
	}

	/**
	 * The initiate handler refuses a doomed preserve-orders restore with
	 * the humanized gate message before any job row exists - no transfer,
	 * no backoff, no waiting.
	 */
	public function test_initiate_refuses_preserve_orders_at_initiate() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->responses['restore_preflight'] = $this->woo_preflight_response( array( 'woo_sync_enabled' => true ) );

		$snapshot = $this->woo_snapshot();

		$nonce                        = wp_create_nonce( 'mainwp_restore_initiate' );
		$_POST['security']            = $nonce;
		$_REQUEST['security']         = $nonce;
		$_POST['site_id']             = (string) $this->site_id;
		$_POST['snapshot_id']         = (string) $snapshot->id;
		$_POST['preserve_orders']     = '1';
		$_POST['confirm_destructive'] = '1';

		try {
			$response = $this->invoke_ajax_handler( 'mainwp_restore_initiate' );
		} finally {
			unset( $_POST['security'], $_REQUEST['security'], $_POST['site_id'], $_POST['snapshot_id'], $_POST['preserve_orders'], $_POST['confirm_destructive'] );
		}

		$this->assertIsArray( $response );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_SYNC_MODE, $response['error_code'] );
		$this->assertStringContainsString( 'orders', $response['error'] );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id ), 'A refused initiate must create no job row.' );
	}

	/**
	 * With native backups disabled, the AJAX entry short-circuits right
	 * after secure_request() with an error_code'd JSON response - no site
	 * authorization, snapshot lookup, or job row, mirroring the direct
	 * orchestrator refusal.
	 */
	public function test_initiate_refuses_when_native_backups_disabled() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 0 );

		$nonce                = wp_create_nonce( 'mainwp_restore_initiate' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['site_id']     = (string) $this->site_id;
		$_POST['snapshot_id'] = '5';

		try {
			$response = $this->invoke_ajax_handler( 'mainwp_restore_initiate' );
		} finally {
			unset( $_POST['security'], $_REQUEST['security'], $_POST['site_id'], $_POST['snapshot_id'] );
		}

		$this->assertIsArray( $response );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED, $response['error_code'] );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id ), 'A refused initiate must create no job row.' );
	}

	/**
	 * The snapshots-list response carries the reload bootstrap: the active
	 * restore job id and whether an on-demand run is open, so a reloaded
	 * page (or a site switch) resumes what the server knows is running
	 * instead of looking hung.
	 */
	public function test_snapshots_list_response_includes_active_job_bootstrap() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$job     = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, array( 'child_task_id' => 'task-9-restore' ) );
		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$task    = \MainWP\Dashboard\MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
		\MainWP\Dashboard\MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'last_run'  => time() - 30,
				'completed' => time() - 3600,
			)
		);

		$nonce                = wp_create_nonce( 'mainwp_restore_snapshot_list' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['site_id']     = (string) $this->site_id;

		try {
			$response = $this->invoke_ajax_handler( 'mainwp_restore_snapshot_list' );
		} finally {
			unset( $_POST['security'], $_REQUEST['security'], $_POST['site_id'] );
		}

		$this->assertIsArray( $response );
		$this->assertSame( (int) $job->id, (int) ( $response['active_restore_job_id'] ?? 0 ), 'The list response must hand a reloaded page its running restore.' );
		$this->assertTrue( (bool) ( $response['manual_backup_running'] ?? false ), 'The list response must flag an open on-demand run.' );
	}

	/**
	 * The list exposes upload progress and inherited destination names, even after deletion.
	 */
	public function test_snapshots_list_includes_uploads_and_manual_destinations() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$destinations = MainWP_Backup_Storage_Destinations::instance();
		$dest_a       = $destinations->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Archive & storage',
			)
		);
		$dest_b       = $destinations->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Removed archive',
			)
		);
		$snapshot_id  = $this->make_chain_snapshot(
			null,
			'db',
			array(
				array(
					'file' => 'db.sql',
					'role' => 'db',
				),
			)
		);
		$store        = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_a, $dest_b ) );
		$jobs = $store->get_jobs_for_snapshot( $snapshot_id );
		$store->fail_permanent( (int) $jobs[1]->id, '<error>' . str_repeat( 'é', 210 ) );
		$store->enqueue_delete_job( $snapshot_id, $dest_a, array() );
		$destinations->delete_destination( $dest_b );

		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$task    = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'template'     => 0,
				'schedule'     => 'daily',
				'destinations' => wp_json_encode( array( $dest_b, $dest_a ) ),
			)
		);

		$nonce                = wp_create_nonce( 'mainwp_restore_snapshot_list' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['site_id']     = (string) $this->site_id;
		try {
			$response = $this->invoke_ajax_handler( 'mainwp_restore_snapshot_list' );
		} finally {
			unset( $_POST['security'], $_REQUEST['security'], $_POST['site_id'] );
		}

		$removed_name = 'Destination #' . $dest_b . ' (removed)';
		$this->assertFalse( $response['local_only'] );
		$this->assertSame( array( 'Archive & storage', $removed_name ), $response['manual_destinations'] );
		$rows = array_column( $response['snapshots'], null, 'id' );
		$this->assertArrayHasKey( $snapshot_id, $rows );
		$uploads = $rows[ $snapshot_id ]['uploads'];
		$this->assertCount( 2, $uploads, 'Delete jobs must not appear as uploads.' );
		$this->assertSame(
			array(
				'destination_id' => $dest_a,
				'name'           => 'Archive & storage',
				'provider'       => 'local',
				'status'         => 'pending',
				'updated'        => (int) $store->get_job( (int) $jobs[0]->id )->updated,
				'last_error'     => '',
			),
			$uploads[0]
		);
		$this->assertSame( $dest_b, $uploads[1]['destination_id'] );
		$this->assertSame( $removed_name, $uploads[1]['name'] );
		$this->assertSame( 's3', $uploads[1]['provider'] );
		$this->assertSame( 'failed_permanent', $uploads[1]['status'] );
		$this->assertIsInt( $uploads[1]['updated'] );
		$this->assertSame( '<error>' . str_repeat( 'é', 193 ), $uploads[1]['last_error'] );
	}

	/**
	 * With nothing running, the bootstrap fields read empty.
	 */
	public function test_snapshots_list_response_bootstrap_empty_when_idle() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$snapshot_id = $this->make_chain_snapshot(
			null,
			'db',
			array(
				array(
					'file' => 'db.sql',
					'role' => 'db',
				),
			)
		);

		$nonce                = wp_create_nonce( 'mainwp_restore_snapshot_list' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['site_id']     = (string) $this->site_id;

		try {
			$response = $this->invoke_ajax_handler( 'mainwp_restore_snapshot_list' );
		} finally {
			unset( $_POST['security'], $_REQUEST['security'], $_POST['site_id'] );
		}

		$this->assertIsArray( $response );
		$this->assertSame( 0, (int) ( $response['active_restore_job_id'] ?? -1 ) );
		$this->assertFalse( (bool) ( $response['manual_backup_running'] ?? true ) );
		$this->assertTrue( $response['local_only'] );
		$this->assertSame( array(), $response['manual_destinations'] );
		$rows = array_column( $response['snapshots'], null, 'id' );
		$this->assertSame( array(), $rows[ $snapshot_id ]['uploads'] );
	}

	/**
	 * The progress response surfaces next_retry_at so a 40-minute backoff
	 * reads as "retrying about HH:MM" instead of a silent red state.
	 */
	public function test_restore_progress_response_includes_next_retry_at() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$job      = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, array( 'child_task_id' => 'task-9-restore' ) );
		$retry_at = time() + 2400;
		MainWP_Backup_Restore_Job_Store::instance()->fail_retryable( (int) $job->id, 'The connection dropped.', 2, $retry_at );

		$skip_drive = static function () {
			return -1;
		};
		add_filter( 'mainwp_restore_foreground_budget', $skip_drive );

		$nonce                = wp_create_nonce( 'mainwp_restore_progress' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['job_id']      = (string) $job->id;

		try {
			$response = $this->invoke_ajax_handler( 'mainwp_restore_progress' );
		} finally {
			remove_filter( 'mainwp_restore_foreground_budget', $skip_drive );
			unset( $_POST['security'], $_REQUEST['security'], $_POST['job_id'] );
		}

		$this->assertIsArray( $response );
		$this->assertSame( 'failed_retryable', $response['status'] ?? '' );
		$this->assertSame( $retry_at, (int) ( $response['next_retry_at'] ?? 0 ), 'The poll must surface when the backoff releases the job.' );
	}

	/**
	 * A transport throw during the transfer stores a humanized last_error
	 * instead of the raw HTTPERROR token, while the diagnostics seam keeps
	 * the raw detail for the bundle.
	 */
	public function test_transfer_transport_error_stored_humanized() {
		$captured = array();
		add_action(
			'mainwp_backup_diag_restore_transfer',
			function ( $task_id, $file, $cursor, $result, $chunk_len, $duration, $error ) use ( &$captured ) {
				unset( $task_id, $file, $cursor, $chunk_len, $duration );
				$captured[] = array(
					'result' => $result,
					'error'  => $error,
				);
			},
			10,
			7
		);

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $website, $what ) {
				unset( $pre, $website );
				if ( 'restore_artifact_chunk' === $what ) {
					throw new MainWP_Exception( 'HTTPERROR', 'cURL error 7: connection refused' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test mock.
				}
				return null;
			},
			5,
			3
		);

		$job = $this->make_job(
			array(
				'plan'     => $this->staged_plan_fixture(),
				'transfer' => array(
					'artifact_index' => 0,
					'offset'         => 0,
					'chunk_bytes'    => 262144,
					'floor_fails'    => 2,
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertStringNotContainsString( 'HTTPERROR', (string) $job->last_error, 'The raw transport token must never reach the stored last_error.' );
		$this->assertStringContainsString( 'could not be reached', (string) $job->last_error );

		$this->assertNotEmpty( $captured );
		$this->assertSame( 'shrink', $captured[0]['result'] );
		$this->assertStringContainsString( 'cURL error 7', (string) $captured[0]['error'], 'Diagnostics must keep the raw transport detail.' );
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
	/**
	 * Only a matching stored value may be swapped; missing rows stay missing.
	 */
	public function test_compare_and_swap_website_option_requires_matching_value() {
		$db   = MainWP_DB::instance();
		$name = MainWP_Backup_Executor::ADHOC_TASK_OPTION;
		$this->assertFalse( $db->compare_and_swap_website_option( $this->site_id, $name, 'missing', 'new' ) );
		$db->update_website_option( $this->site_id, $name, 'old' );
		$this->assertFalse( $db->compare_and_swap_website_option( $this->site_id, $name, 'different', 'new' ) );
		$this->assertSame( 'old', $db->get_website_option( $this->site_id, $name ) );
		$this->assertTrue( $db->compare_and_swap_website_option( $this->site_id, $name, 'old', 'new' ) );
		$this->assertSame( 'new', $db->get_website_option( $this->site_id, $name ) );
		$this->assertFalse( $db->compare_and_swap_website_option( $this->site_id, $name, 'new', 'new' ) );
	}

	/**
	 * Releasing the owned handle must report that the matching value was cleared.
	 */
	public function test_release_pre_snapshot_handle_clears_matching_value() {
		$db      = MainWP_DB::instance();
		$website = $db->get_website_by_id( $this->site_id );
		$handle  = array(
			'task_id' => 'safety-task',
			'owner'   => '42',
		);
		$db->update_website_option( $website, MainWP_Backup_Executor::ADHOC_TASK_OPTION, wp_json_encode( $handle ) );
		$release = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'release_pre_snapshot_handle' );
		$this->assertTrue( $release->invoke( $this->orchestrator, $website, 'safety-task', '42' ) );
		global $wpdb;
		// wpdb::get_var() reads an empty value back as null, so read the row.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT value FROM ' . $db->get_table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = %s', $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) ); // phpcs:ignore WordPress.DB -- test probe.
		$this->assertNotNull( $row, 'the option row must survive the release' );
		$this->assertSame( '', $row->value );
	}

	/**
	 * Even the same task and owner can have a newer handle that must survive.
	 */
	public function test_release_pre_snapshot_handle_preserves_concurrent_replacement() {
		$db                = MainWP_DB::instance();
		$website           = $db->get_website_by_id( $this->site_id );
		$name              = MainWP_Backup_Executor::ADHOC_TASK_OPTION;
		$handle            = array(
			'task_id' => 'safety-task',
			'owner'   => '42',
			'started' => 1,
		);
		$old               = wp_json_encode( $handle );
		$handle['started'] = 2;
		$new               = wp_json_encode( $handle );
		$db->update_website_option( $website, $name, $old );
		$table = $db->get_table_name( 'wp_options' );
		$raced = false;
		$race  = function ( $query ) use ( &$race, &$raced, $table, $db, $website, $name, $new ) {
			if ( str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, $table ) && str_contains( $query, $name ) ) {
				remove_filter( 'query', $race );
				$raced = true;
				$db->update_website_option( $website, $name, $new );
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$release = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'release_pre_snapshot_handle' );
			$this->assertFalse( $release->invoke( $this->orchestrator, $website, 'safety-task', '42' ) );
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assertTrue( $raced, 'The replacement must land after the read and before the swap.' );
		$this->assertSame( $new, $db->get_website_option( $website, $name ) );
	}
}
