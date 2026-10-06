<?php
/**
 * Restore orchestrator tests: phase progression over mocked child
 * calls, failure handling with resume, and the completion hook.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Backoff;
use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_Connect;
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
 * Supply elapsed probe time without delaying the test.
 */
class Capability_Probe_Test_Monitor extends MainWP_Backup_Monitor {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- clock test double.

	/**
	 * Scripted clock readings in seconds.
	 *
	 * @var array
	 */
	public static $times = array();

	/**
	 * Consume the next clock reading.
	 *
	 * @return float Scripted time.
	 */
	protected static function capability_probe_time(): float {
		return Capability_Probe_Test_Transport::$now;
	}
}

/**
 * Exercise the real root fallback while replacing only the HTTP attempt.
 */
class Capability_Probe_Test_Transport extends MainWP_Connect {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- transport test double.

	/**
	 * Simulated monotonic time.
	 *
	 * @var float
	 */
	public static $now = 100.0;

	/**
	 * Durations consumed by actual transport attempts.
	 *
	 * @var array
	 */
	public static $durations = array();

	/**
	 * Requested URLs and effective timeout values.
	 *
	 * @var array
	 */
	public static $attempts = array();

	/**
	 * Whether the root attempt succeeds.
	 *
	 * @var bool
	 */
	public static $root_succeeds = false;

	/**
	 * Capture one attempt without network access.
	 *
	 * @param object $website Website row.
	 * @param string $url Request URL.
	 * @param mixed  $postdata Request data.
	 * @param bool   $checkConstraints Connection constraints.
	 * @param mixed  $verifyCertificate Certificate setting.
	 * @param mixed  $http_user HTTP username.
	 * @param mixed  $http_pass HTTP password.
	 * @param int    $sslVersion SSL version.
	 * @param array  $others Request options.
	 * @param array  $output Output values.
	 * @return array Capability response.
	 * @throws MainWP_Exception For a blocked endpoint.
	 */
	public static function fetch_url_site( &$website, $url, $postdata, $checkConstraints = false, $verifyCertificate = null, $http_user = null, $http_pass = null, $sslVersion = 0, $others = array(), &$output = array() ) {
		$timeout          = apply_filters( 'mainwp_fetch_url_site_timeout', 72000, 'backup_preflight' );
		$duration         = min( $timeout, array_shift( self::$durations ) ?? 30 );
		self::$attempts[] = array( $url, $timeout );
		self::$now       += $duration;
		if ( self::$root_succeeds && $url === $website->url ) {
			return array( 'restore_create_idempotency' => 1 );
		}
		throw new MainWP_Exception( 'Endpoint blocked.' );
	}
}

/**
 * Class MainWP_Backup_Restore_Orchestrator_Test
 */
class MainWP_Backup_Restore_Orchestrator_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

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
		$this->set_up_private_root();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->site_id      = $this->create_test_site();
		$this->orchestrator = new Testable_Restore_Orchestrator();
		$this->dir          = sys_get_temp_dir() . '/mainwp-orch-test-' . uniqid();
		mkdir( $this->dir );
		add_filter( 'mainwp_backup_retry_delay', '__return_zero' );

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $website, $what, $params ) {
				$this->calls[] = array(
					'what'   => $what,
					'params' => $params,
				);
				if ( isset( $this->responses[ $what ] ) ) {
					$scripted = $this->responses[ $what ];
					if ( is_array( $scripted ) && isset( $scripted[0] ) && ( is_array( $scripted[0] ) || $scripted[0] instanceof \Throwable ) ) {
						$scripted = array_shift( $this->responses[ $what ] );
					}
					if ( $scripted instanceof \Throwable ) {
						throw $scripted;
					}
					return $scripted;
				}
				if ( 'backup_preflight' === $what ) {
					throw new MainWP_Exception( 'No backup preflight response scripted.' );
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
		$this->tear_down_private_root();
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
	 * A lost acknowledgement must retain the identity persisted before create.
	 *
	 * @test
	 */
	public function b14_lost_create_response_adopts_original_task() {
		$this->responses['restore_task_create'] = array(
			new MainWP_Exception( 'Lost create response.' ),
			array(
				'task_id' => 'b14-original',
				'phase'   => 'receiving',
				'reused'  => true,
			),
		);

		$job   = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertEmpty( $job->child_task_id );
		$this->assertSame( 'orch01test', $job->checkpoint['restore_id'] );
		$this->assertSame( 1, array_count_values( array_column( $this->calls, 'what' ) )['restore_task_create'] );

		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->status );
		$this->assertSame( 'b14-original', $job->child_task_id );
		$creates = $this->calls;
		$this->assertCount( 2, $creates );
		$this->assertSame( 'orch01test', $creates[0]['params']['restore_id'] );
		$this->assertSame( $creates[0]['params'], $creates[1]['params'] );
	}

	/**
	 * Completion recovered at create must never return to artifact transfer.
	 *
	 * @test
	 */
	public function b14_reused_completed_task_is_not_created_again() {
		$this->responses['restore_task_create'] = array(
			new MainWP_Exception( 'Lost create response.' ),
			array(
				'task_id'   => 'b14-completed',
				'phase'     => 'completed',
				'reused'    => true,
				'sub_state' => array( 'verification' => array( 'db_ok' => true ) ),
			),
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$job = $this->tick( $job );
		$this->assertSame( 'b14-completed', $job->child_task_id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $job->status );
		$this->assertTrue( $job->checkpoint['verification']['db_ok'] );
		$job = $this->tick( $job );
		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED, $job->status );
		$creates = $this->calls;
		$this->assertCount( 2, $creates );
		$this->assertSame( 'orch01test', $creates[0]['params']['restore_id'] );
		$this->assertSame( $creates[0]['params'], $creates[1]['params'] );
		$this->assertSame( array( 'restore_task_create', 'restore_task_create' ), array_column( $this->calls, 'what' ) );
	}

	/**
	 * A recovered failure retains the original handle instead of staging another task.
	 *
	 * @test
	 */
	public function b14_reused_failed_task_records_failure() {
		$this->responses['restore_task_create'] = array(
			'task_id'    => 'b14-failed',
			'phase'      => 'failed',
			'reused'     => true,
			'error'      => 'Original restore failed.',
			'error_code' => 'mainwp_restore_failed',
		);
		$this->responses['restore_task_cancel'] = array(
			'result' => 'already_terminal',
			'phase'  => 'failed',
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$job = $this->tick( $job );
		$this->assertSame( 'b14-failed', $job->child_task_id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'Original restore failed.', $job->last_error );
		$this->assertSame( 'mainwp_restore_failed', $job->checkpoint['last_error_code'] );
		$this->tick( $job );
		$this->assertSame( 1, array_count_values( array_column( $this->calls, 'what' ) )['restore_task_create'] );
	}

	/**
	 * A reused cloud failure returns to staging without adopting the failed handle.
	 *
	 * @test
	 */
	public function b14_reused_cloud_pull_failure_falls_back_to_staging() {
		$this->use_cloud_pull_driver( $this->pull_manifest_fixture() );
		$this->responses['restore_task_create'] = array(
			array(
				'task_id'   => 'b14-pull-failed',
				'phase'     => 'failed',
				'reused'    => true,
				'sub_state' => array(
					'error' => array(
						'message' => 'Cloud download failed.',
						'code'    => 'mainwp_cloud_pull_failed',
					),
				),
			),
			array(
				'task_id' => 'b14-standard-transfer',
				'phase'   => 'receiving',
			),
		);
		$job                                    = $this->make_job( array( 'transfer' => array( 'stale' => true ) ), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$job                                    = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertSame( '', (string) $job->child_task_id );
		$this->assertTrue( $job->checkpoint['cloud_pull_tried'] );
		$this->assertSame( str_repeat( 'a', 64 ), $job->checkpoint['plan'][0]['sha256'] );
		$this->assertSame( array(), $job->checkpoint['transfer'] );
		$this->assertEmpty( $job->last_error );
		$this->assertSame( array( 'restore_task_create' ), array_column( $this->calls, 'what' ) );
		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->status );
		$this->assertSame( 'b14-standard-transfer', $job->child_task_id );
		$this->assertNotSame( 'b14-pull-failed', $job->child_task_id );
		$creates = array_values( array_filter( $this->calls, static fn( $call ) => 'restore_task_create' === $call['what'] ) );
		$this->assertCount( 2, $creates );
		$this->assertNotSame( $creates[0]['params']['restore_id'], $creates[1]['params']['restore_id'] );
	}

	/**
	 * A refused fallback status write must retain the running task and identity.
	 */
	public function test_cloud_pull_fallback_status_failure_keeps_running_handle() {
		global $wpdb;
		$this->responses['restore_task_run'] = array(
			'task_id'    => 'b14-pull-failed',
			'phase'      => 'failed',
			'error_code' => 'mainwp_cloud_pull_failed',
		);
		$job                                 = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'b14-pull-failed' ) );
		$table                               = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$refuse                              = static function ( $sql ) use ( $table ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET ' ) && str_contains( $sql, "`status` = 'staging'" ) && str_contains( $sql, "`child_task_id` = ''" ) ) {
				return 'SELECT mainwp_test_refused_status_write';
			}
			return $sql;
		};
		$suppress                            = $wpdb->suppress_errors();
		add_filter( 'query', $refuse );
		try {
			$job = $this->tick( $job );
		} finally {
			remove_filter( 'query', $refuse );
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $job->status );
		$this->assertSame( 'b14-pull-failed', $job->child_task_id );
		$this->assertSame( 'orch01test', $job->checkpoint['restore_id'] );
		$this->assertArrayNotHasKey( 'cloud_pull_tried', $job->checkpoint );
	}

	/**
	 * A torn fallback heals through cloud-task reuse before creating a standard transfer.
	 */
	public function test_cloud_pull_fallback_checkpoint_failure_converges_through_staging() {
		global $wpdb;
		$this->use_cloud_pull_driver( $this->pull_manifest_fixture() );
		$this->responses['restore_task_run'] = array(
			'task_id'    => 'b14-pull-failed',
			'phase'      => 'failed',
			'error_code' => 'mainwp_cloud_pull_failed',
		);
		$job                                 = $this->make_job( array( 'transfer' => array( 'stale' => true ) ), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'b14-pull-failed' ) );
		$table                               = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$refuse                              = static function ( $sql ) use ( $table ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET checkpoint' ) && str_contains( $sql, 'cloud_pull_tried' ) ) {
				return 'SELECT mainwp_test_refused_checkpoint_write';
			}
			return $sql;
		};
		$suppress                            = $wpdb->suppress_errors();
		add_filter( 'query', $refuse );
		try {
			$job = $this->tick( $job );
		} finally {
			remove_filter( 'query', $refuse );
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertSame( '', (string) $job->child_task_id );
		$this->assertSame( 'orch01test', $job->checkpoint['restore_id'] );
		$this->assertArrayNotHasKey( 'cloud_pull_tried', $job->checkpoint );
		$this->assertSame( array( 'stale' => true ), $job->checkpoint['transfer'] );
		$this->assertSame( array( 'restore_task_run' ), array_column( $this->calls, 'what' ) );
		$this->responses['restore_task_create'] = array(
			array(
				'task_id'    => 'b14-pull-failed',
				'phase'      => 'failed',
				'reused'     => true,
				'error_code' => 'mainwp_cloud_pull_failed',
			),
			array(
				'task_id' => 'b14-standard-transfer',
				'phase'   => 'receiving',
			),
		);
		$job                                    = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertSame( '', (string) $job->child_task_id );
		$this->assertTrue( $job->checkpoint['cloud_pull_tried'] );
		$this->assertSame( array(), $job->checkpoint['transfer'] );
		$this->assertNotSame( 'orch01test', $job->checkpoint['restore_id'] );
		$fallback_id = $job->checkpoint['restore_id'];
		$creates     = array_values( array_filter( $this->calls, static fn( $call ) => 'restore_task_create' === $call['what'] ) );
		$this->assertCount( 1, $creates );
		$this->assertSame( 'orch01test', $creates[0]['params']['restore_id'] );
		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->status );
		$this->assertSame( 'b14-standard-transfer', $job->child_task_id );
		$this->assertNotSame( 'b14-pull-failed', $job->child_task_id );
		$this->assertSame( $fallback_id, $job->checkpoint['restore_id'] );
		$creates = array_values( array_filter( $this->calls, static fn( $call ) => 'restore_task_create' === $call['what'] ) );
		$this->assertCount( 2, $creates );
		$this->assertSame( $fallback_id, $creates[1]['params']['restore_id'] );
		$this->assertNotSame( 'orch01test', $creates[1]['params']['restore_id'] );
		$this->assertArrayHasKey( 'pull_manifest_json', $creates[0]['params'] );
		$this->assertArrayNotHasKey( 'pull_manifest_json', $creates[1]['params'] );
	}

	/**
	 * A reused standard restore failure remains terminal after cloud fallback.
	 *
	 * @test
	 */
	public function b14_reused_restore_failure_after_fallback_is_terminal() {
		$this->responses['restore_task_create'] = array(
			'task_id'    => 'b14-pull-failed',
			'phase'      => 'failed',
			'reused'     => true,
			'error'      => 'Standard restore failed.',
			'error_code' => 'mainwp_restore_failed',
		);
		$this->responses['restore_task_cancel'] = array(
			'result' => 'already_terminal',
			'phase'  => 'failed',
		);
		$job                                    = $this->make_job( array( 'cloud_pull_tried' => true ), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$job                                    = $this->tick( $job );
		$this->assertSame( 'b14-pull-failed', $job->child_task_id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'Standard restore failed.', $job->last_error );
		$this->assertSame( 'mainwp_restore_failed', $job->checkpoint['last_error_code'] );
		$this->assertTrue( $job->checkpoint['cloud_pull_tried'] );
		$this->tick( $job );
		$this->assertSame( 1, array_count_values( array_column( $this->calls, 'what' ) )['restore_task_create'] );
	}

	/**
	 * A reused cloud task may still need bytes or may already be applying them.
	 *
	 * @test
	 */
	public function b14_reused_cloud_task_routes_by_stored_phase() {
		$manifest = $this->pull_manifest_fixture();
		$this->use_cloud_pull_driver( $manifest );
		foreach ( array( 'queued', 'receiving', 'pulling', 'replaying_db', 'completed' ) as $phase ) {
			$this->responses['restore_task_create'] = array(
				'task_id' => 'b14-' . $phase,
				'phase'   => $phase,
				'reused'  => true,
			);

			$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
			$job = $this->tick( $job );
			$this->assertSame( 'b14-' . $phase, $job->child_task_id );
			if ( in_array( $phase, array( 'queued', 'receiving' ), true ) ) {
				$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
				$job = $this->tick( $job );
				$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->status );
			} elseif ( 'completed' === $phase ) {
				$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $job->status );
			} else {
				$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $job->status );
			}
			$this->assertTrue( MainWP_Backup_Restore_Job_Store::instance()->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED ) );
		}
		$this->assertSame( 5, array_count_values( array_column( $this->calls, 'what' ) )['restore_task_create'] );

		$this->responses['restore_task_create'] = array(
			'task_id'   => 'b14-accepted-pull',
			'phase'     => 'queued',
			'reused'    => true,
			'sub_state' => array( 'params' => array( 'pull_manifest' => $manifest ) ),
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $job->status );
		$this->assertSame( 'b14-accepted-pull', $job->child_task_id );
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
	 * A lock refusal without a code remains permanent.
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
				array( 'file' => 'files.list', 'role' => 'manifest', 'walk' => array( 'excludes' => array(), 'excludenonwp' => 0, 'excludezip' => 0 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
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
				array( 'manifest', 's' . $inc2_id . '-files.list' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact expectation.
				array( 'db', 's' . $inc2_id . '-db.zip' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact expectation.
			),
			array_map(
				function ( $artifact ) {
					return array( $artifact['role'], $artifact['file'] );
				},
				$artifacts
			),
			'The chain must ship base-first, per-increment tombstones before parts, the leaf manifest after the file ops, chain-newest db last.'
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
	 * Only the leaf's manifest and recorded walk can authorize reconciliation.
	 *
	 * @dataProvider reconcile_option_cases
	 * @param bool   $cloud    Pull directly from cloud storage.
	 * @param string $metadata Available leaf metadata.
	 */
	public function test_restore_create_reconcile_requires_leaf_manifest_and_walk( $cloud, $metadata ) {
		$walk = array(
			'excludes'     => array( 'private', 'wp-content/cache' ),
			'excludenonwp' => 1,
			'excludezip'   => 0,
		);

		$this->responses['restore_task_create'] = array(
			'task_id' => 'task-reconcile',
			'phase'   => 'queued',
		);

		$job             = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$plan            = $job->checkpoint['plan'];
		$plan[0]['walk'] = $walk;
		if ( 'no_manifest' !== $metadata ) {
			$path = $this->dir . '/files.list';
			file_put_contents( $path, "index.php\t10\t100\n" );
			$entry = array(
				'role'        => 'manifest',
				'file'        => 's5-files.list',
				'source_file' => 'files.list',
				'size'        => (int) filesize( $path ),
				'sha256'      => '',
				'snapshot_id' => 'ancestor' === $metadata ? 4 : 5,
				'source'      => 'local',
				'local_path'  => $path,
				'remote_key'  => '',
				'adopt_only'  => false,
			);
			if ( 'no_walk' !== $metadata ) {
				$entry['walk'] = $walk;
			}
			$plan[] = $entry;
		}
		$checkpoint             = $job->checkpoint;
		$checkpoint['sub_type'] = 'db_only' === $metadata ? 'db' : 'full';
		$checkpoint['plan']     = $plan;
		MainWP_Backup_Restore_Job_Store::instance()->save_checkpoint( (int) $job->id, $checkpoint );
		if ( $cloud ) {
			$manifest = $this->pull_manifest_fixture();
			if ( 'no_manifest' !== $metadata ) {
				$manifest['artifacts'][] = array(
					'file'   => 's5-files.list',
					'sha256' => str_repeat( 'a', 64 ),
					'size'   => (int) filesize( $path ),
					'url'    => 'https://bucket.example/5/files/files.list',
				);
			}
			$this->use_cloud_pull_driver( $manifest );
		}
		$job = $this->tick( $job );
		$this->assertSame( $cloud ? MainWP_Backup_Restore_Job_Store::STATUS_RUNNING : MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->status );
		$creates = array_values( array_filter( $this->calls, static fn( $call ) => 'restore_task_create' === $call['what'] ) );
		$this->assertCount( 1, $creates );
		$options = json_decode( $creates[0]['params']['options_json'], true );
		if ( 'both' === $metadata ) {
			$this->assertSame(
				array(
					'manifest' => 's5-files.list',
					'walk'     => $walk,
				),
				$options['reconcile']
			);
			$artifacts = json_decode( $creates[0]['params']['artifacts_json'], true );
			$this->assertSame( 'manifest', $artifacts[1]['role'] );
			$this->assertSame( 's5-files.list', $artifacts[1]['file'] );
			$this->assertSame( $cloud ? str_repeat( 'a', 64 ) : hash_file( 'sha256', $path ), $artifacts[1]['sha256'] );
			$this->assertSame( (int) filesize( $path ), $artifacts[1]['size'] );
		} else {
			$this->assertArrayNotHasKey( 'reconcile', $options );
		}
	}

	/**
	 * Staging and direct pull must apply the same metadata gate.
	 *
	 * @return array
	 */
	public static function reconcile_option_cases() {
		return array(
			'staged complete'    => array( false, 'both' ),
			'staged no manifest' => array( false, 'no_manifest' ),
			'staged no walk'     => array( false, 'no_walk' ),
			'staged ancestor'    => array( false, 'ancestor' ),
			'pull complete'      => array( true, 'both' ),
			'pull no manifest'   => array( true, 'no_manifest' ),
			'pull no walk'       => array( true, 'no_walk' ),
			'pull ancestor'      => array( true, 'ancestor' ),
			'staged db only'     => array( false, 'db_only' ),
			'pull db only'       => array( true, 'db_only' ),
		);
	}

	/**
	 * Completion must retain the child's reconcile result for the progress poll.
	 */
	public function test_completed_restore_retains_reconcile_verification() {
		$reconcile = array(
			'status'   => 'done',
			'removed'  => 2,
			'examined' => 20,
		);

		$this->responses['restore_task_run'] = array(
			'task_id'   => 'task-reconcile',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'reconcile' => $reconcile ) ),
		);

		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-reconcile' ) );
		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $job->status );
		$this->assertSame( $reconcile, $job->checkpoint['verification']['reconcile'] );
		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED, $job->status );
		$this->assertSame( $reconcile, $job->checkpoint['verification']['reconcile'] );
		$this->assertTrue( $job->checkpoint['verification']['site_responds'] );
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
	 * A refused completion retains the rollback source and staging.
	 */
	public function test_verifying_completion_write_guards_cleanup() {
		$store     = MainWP_Backup_Restore_Job_Store::instance();
		$artifacts = array(
			array(
				'file' => 'safety.zip',
				'role' => 'db',
			),
		);
		$job       = $this->make_job(
			array( 'pre_snapshot_artifacts' => $artifacts ),
			$store::STATUS_VERIFYING,
			array( 'pre_snapshot_task_id' => 'task-7-pre' )
		);
		$job       = $store->acquire_next_job( 'verifying-holder', 90, time(), (int) $job->id );
		$this->assertNotNull( $job );
		$token = new \ReflectionProperty( MainWP_Backup_Restore_Orchestrator::class, 'lock_token' );
		$token->setValue( $this->orchestrator, 'verifying-holder' );
		$driver  = MainWP_Backup_Restore_Transfer_Driver::instance();
		$staging = $driver->staging_dir( (int) $job->id );
		wp_mkdir_p( $staging );
		file_put_contents( $staging . 'backup.zip', 'staged backup' );
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$refuse = static function ( $sql ) use ( $table ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table ) && str_contains( $sql, "`status` = 'completed'" ) ) {
				return 'SELECT 1 WHERE 0 = 1';
			}
			return $sql;
		};
		$events = array();
		$record = static function ( $event ) use ( &$events ) {
			$events[] = $event;
		};
		add_action( 'mainwp_backup_restored', $record );
		add_filter( 'query', $refuse );
		try {
			$job = $this->tick( $job );
			$this->assertSame( 'verifying', $job->status );
			$this->assertSame( array(), $this->calls );
			$this->assertFileExists( $staging . 'backup.zip' );
			$this->assertSame( $artifacts, $job->checkpoint['pre_snapshot_artifacts'] );
			$this->assertSame( array(), $events );

			remove_filter( 'query', $refuse );
			$job = $this->tick( $job );
			$this->assertSame( 'completed', $job->status );
			$this->assertSame( array( 'backup_task_prune' ), array_column( $this->calls, 'what' ) );
			$this->assertSame(
				array(
					'task_ids' => '["task-7-pre"]',
					'sweep'    => 0,
				),
				$this->calls[0]['params']
			);
			$this->assertFileDoesNotExist( $staging . 'backup.zip' );
			$this->assertCount( 1, $events );
			$this->assertSame( (int) $job->id, $events[0]['job_id'] );
		} finally {
			remove_filter( 'query', $refuse );
			remove_action( 'mainwp_backup_restored', $record );
			$token->setValue( $this->orchestrator, null );
			$driver->cleanup_staging( (int) $job->id );
		}
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
	 * The reclaim sweep can issue child HTTP even when no job is acquired.
	 */
	public function test_reclaim_cancellation_has_timeout_cap_and_removes_it() {
		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$this->expire_job_at_reclaim_ceiling( (int) $job->id );
		$timeouts = array();
		$observe  = static function ( $pre, $website, $what ) use ( &$timeouts ) {
			if ( 'restore_task_cancel' === $what ) {
				$timeouts[] = apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS );
			}
			return $pre;
		};
		$before   = apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS );
		add_filter( 'mainwp_fetch_url_authed_pre', $observe, 20, 3 );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $observe, 20 );
		}
		$this->assertSame( array( 90 ), $timeouts, 'The sweep must use the same request bound as a leased slice.' );
		$this->assertSame( $before, apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS ), 'The no-job return must remove its timeout filter.' );
	}

	/**
	 * Every phase, child reply and durable decision must agree on ownership and cleanup.
	 *
	 * @dataProvider cancellation_decision_matrix
	 * @param string      $status        Job status before the terminal write.
	 * @param string      $resume        Resume status recorded in the checkpoint, or empty.
	 * @param string      $answer        Child cancellation outcome.
	 * @param string      $records       Checkpoint state to seed (none/intent/failure/retry/expired variants).
	 * @param bool|string $refused       Which durable writes the DB refuses.
	 * @param array       $expected      Expected status, outcome, calls, cleanup, event, pending failure, attempts.
	 * @param string|null $lane          Expected UI result, or null when admission or incoming error excludes it.
	 * @param string      $child_task_id Child task id on the job.
	 * @param array       $fixture       Safety-backup fixture options.
	 */
	public function test_cancellation_decision_matrix( $status, $resume, $answer, $records, $refused, $expected, $lane, $child_task_id = 'task-9-restore', $fixture = array() ) {
		if ( null !== $lane ) {
			$this->assert_cancellation_decision( $status, $resume, $answer, $records, $refused, $expected, $lane, $child_task_id, $fixture );
		}
		$this->assert_cancellation_decision( $status, $resume, $answer, $records, $refused, $expected, null, $child_task_id, $fixture );
	}

	/**
	 * Seed a fresh job and assert the same decision through either entry point.
	 *
	 * @param string      $status        Initial status.
	 * @param string|null $resume        Resume phase.
	 * @param string      $answer        Child reply.
	 * @param string      $records       Durable records to seed.
	 * @param bool|string $refused       Writes to refuse.
	 * @param array       $expected      Literal expected state tuple.
	 * @param string|null $lane          UI result, or null for the direct call.
	 * @param string      $child_task_id Child task id.
	 * @param array       $fixture       Safety-backup fixture options.
	 */
	private function assert_cancellation_decision( $status, $resume, $answer, $records, $refused, $expected, $lane, $child_task_id, $fixture ) {
		global $wpdb;
		$this->calls = array();
		$store       = MainWP_Backup_Restore_Job_Store::instance();
		$checkpoint  = null === $resume ? array() : array( 'resume_status' => $resume );
		$original    = array(
			'error'      => 'Original replay failure',
			'error_code' => 'mainwp_restore_db_replay',
		);
		$incoming    = in_array( $records, array( 'intent', 'expired intent', 'cancel', 'stored failure' ), true ) ? null : 'Later failure';
		if ( in_array( $records, array( 'intent', 'expired intent', 'failure', 'expired failure' ), true ) ) {
			$checkpoint['cancel_intent'] = true;
		}
		if ( in_array( $records, array( 'failure', 'expired failure', 'stored failure' ), true ) ) {
			$checkpoint['terminal_failure'] = $original;
			$checkpoint['last_error_code']  = 'mainwp_restore_db_replay';
		}
		if ( in_array( $records, array( 'retry', 'expired failure', 'expired intent' ), true ) ) {
			$checkpoint['cancel_confirmation'] = array(
				'attempt_count'    => 99,
				'first_attempt_at' => time() - ( 'retry' === $records ? 30 : 901 ),
			);
		}
		$job = $this->make_job(
			$checkpoint,
			$status,
			array(
				'child_task_id'        => $child_task_id,
				'pre_snapshot_task_id' => $fixture['snapshot_task_id'] ?? 'task-7-pre',
			)
		);
		if ( 'pending' === $status ) {
			$store->set_status(
				(int) $job->id,
				$status,
				array(
					'child_task_id'        => $child_task_id,
					'pre_snapshot_task_id' => $fixture['snapshot_task_id'] ?? 'task-7-pre',
				)
			);
		}
		$lock_token = $fixture['lock_token'] ?? null;
		if ( null !== $lock_token ) {
			$job = $store->acquire_next_job( $lock_token, 90, time(), (int) $job->id );
			$this->assertNotNull( $job );
		}
		$advance = static function ( $pre, $website, $what ) use ( $store, $job, $fixture ) {
			if ( 'restore_task_cancel' === $what && isset( $fixture['advance_to'] ) ) {
				$store->set_status( (int) $job->id, $fixture['advance_to'] );
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $advance, 20, 3 );

		$handles      = array(
			'own'         => wp_json_encode(
				array(
					'task_id' => 'task-7-pre',
					'owner'   => (string) $job->id,
				)
			),
			'foreign'     => wp_json_encode(
				array(
					'task_id' => 'task-8-other',
					'owner'   => (string) ( $job->id + 1 ),
				)
			),
			'adopted'     => wp_json_encode(
				array(
					'task_id' => 'task-7-pre',
					'owner'   => (string) ( $job->id + 1 ),
				)
			),
			'legacy'      => wp_json_encode( array( 'task_id' => 'task-7-pre' ) ),
			'undecodable' => '{invalid-json',
			'none'        => null,
		);
		$handle_state = $fixture['handle'] ?? 'own';
		$handle       = $handles[ $handle_state ];
		if ( null !== $handle ) {
			MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		}
		$this->responses['backup_task_cancel'] = ! empty( $fixture['backup_throws'] ) ? new \Error( 'Child cancellation failed.' ) : array( 'result' => 'ok' );
		$driver                                = MainWP_Backup_Restore_Transfer_Driver::instance();
		$staging                               = $driver->staging_dir( (int) $job->id );
		wp_mkdir_p( $staging );
		file_put_contents( $staging . 'backup.zip', 'staged backup' );
		$this->responses['restore_task_cancel'] = array( 'result' => $answer );
		if ( in_array( $answer, array( 'completed', 'failed' ), true ) ) {
			$this->responses['restore_task_cancel'] = array(
				'result' => 'already_terminal',
				'phase'  => $answer,
			);
		} elseif ( 'threw' === $answer ) {
			$this->responses['restore_task_cancel'] = new MainWP_Exception( 'Site unreachable.' );
		} elseif ( in_array( $answer, array( 'error', 'unknown task' ), true ) ) {
			$this->responses['restore_task_cancel'] = array( 'error' => $answer );
		}
		$events       = array();
		$record_event = static function ( $event ) use ( &$events ) {
			$events[] = $event;
		};
		add_action( 'mainwp_backup_restore_failed', $record_event );
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$refuse   = static function ( $sql ) use ( $table, $refused ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table ) || str_starts_with( $sql, 'UPDATE `' . $table . '`' ) ) {
				$combined     = str_contains( $sql, 'last_error = ' );
				$status_write = str_contains( $sql, '`status` = ' );
				if ( 'checkpoint' === $refused && str_contains( $sql, '`checkpoint` = ' ) ) {
					return 'SELECT mainwp_test_refused_checkpoint_write';
				}
				if ( ( true === $refused && ( $combined || $status_write ) )
					|| ( 'terminal' === $refused && $status_write )
				) {
					return 'SELECT 1 WHERE 0 = 1';
				}
			}
			return $sql;
		};
		$suppress = $wpdb->suppress_errors( 'checkpoint' === $refused );
		add_filter( 'query', $refuse );
		try {
			if ( null === $lane ) {
				$record  = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'record_terminal_failure' );
				$outcome = $record->invoke( $this->orchestrator, $job, $incoming, null === $incoming ? '' : 'later_code', $lock_token );
				$this->assertSame( $expected[1], $outcome );
			} else {
				try {
					$result = $this->orchestrator->cancel_job( (int) $job->id );
				} catch ( MainWP_Exception $exception ) {
					$this->assertStringContainsString( 'already finished on the site', $exception->getMessage() );
					$result = 'throws:finished';
				}
				$this->assertSame( $lane, $result );
			}
			$fresh = $store->get_job( (int) $job->id );
			list( $expected_status, , $expected_calls, $cleanup, $event, $pending, $attempts ) = $expected;
			$this->assertSame( $expected_status, $fresh->status );
			$this->assertSame( $expected_calls, array_column( $this->calls, 'what' ) );
			$this->assertSame( ! $cleanup, file_exists( $staging . 'backup.zip' ), 'Only the winning terminal write may clean staging.' );
			$this->assertSame( 'unchanged' === ( $fixture['handle_after'] ?? ( $cleanup ? 'cleared' : 'unchanged' ) ) ? $handle : null, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
			if ( 'none' === $handle_state ) {
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MainWP_DB::instance()->get_table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = %s', $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) ); // phpcs:ignore WordPress.DB -- verify no option was inserted.
				$this->assertSame( 0, (int) $count );
			}
			if ( null === $event ) {
				$this->assertSame( array(), $events );
			} else {
				$this->assertCount( 1, $events );
				$this->assertSame( $event['error'], $fresh->last_error );
				$this->assertSame( $event['error_code'], $fresh->checkpoint['last_error_code'] );
				$this->assertSame( $event['error'], $events[0]['error'] );
				$this->assertSame( $event['error_code'], $events[0]['error_code'] );
			}
			if ( false === $pending ) {
				$this->assertArrayNotHasKey( 'cancel_intent', $fresh->checkpoint );
				$this->assertArrayNotHasKey( 'terminal_failure', $fresh->checkpoint );
				$this->assertArrayNotHasKey( 'cancel_confirmation', $fresh->checkpoint );
				$this->assertArrayNotHasKey( 'last_error_code', $fresh->checkpoint );
			} elseif ( null !== $pending ) {
				$this->assertTrue( $fresh->checkpoint['cancel_intent'] );
				$this->assertSame( $pending, $fresh->checkpoint['terminal_failure'] ?? true );
			}
			if ( null !== $attempts ) {
				$this->assertSame( $attempts, $fresh->checkpoint['cancel_confirmation']['attempt_count'] );
			}
			if ( ! empty( $fixture['second_tick'] ) ) {
				$failure = $fresh->checkpoint['terminal_failure'];
				$this->assertSame( MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, $record->invoke( $this->orchestrator, $fresh, $failure['error'], $failure['error_code'], $lock_token ) );
				$fresh = $store->get_job( (int) $job->id );
				$this->assertSame( 'verifying', $fresh->status );
				$this->assertArrayNotHasKey( 'terminal_failure', $fresh->checkpoint );
				$this->assertArrayNotHasKey( 'cancel_intent', $fresh->checkpoint );
				$this->assertArrayNotHasKey( 'cancel_confirmation', $fresh->checkpoint );
				$this->assertArrayNotHasKey( 'last_error_code', $fresh->checkpoint );
				$this->assertSame( array(), $events );
				$this->assertSame( array( 'restore_task_cancel' ), array_column( $this->calls, 'what' ) );
				$this->assertFileExists( $staging . 'backup.zip' );
				$this->assertSame( $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
			}
		} finally {
			remove_filter( 'query', $refuse );
			$wpdb->suppress_errors( $suppress );
			remove_action( 'mainwp_backup_restore_failed', $record_event );
			remove_filter( 'mainwp_fetch_url_authed_pre', $advance, 20 );
			$driver->cleanup_staging( (int) $job->id );
			$wpdb->delete( $table, array( 'id' => (int) $job->id ) );
			$wpdb->delete(
				MainWP_DB::instance()->get_table_name( 'wp_options' ),
				array(
					'wpid' => $this->site_id,
					'name' => MainWP_Backup_Executor::ADHOC_TASK_OPTION,
				)
			);
		}
	}

	/**
	 * Every phase/reply combination and its expected terminal outcome.
	 *
	 * @return array
	 */
	public static function cancellation_decision_matrix() {
		$later    = array(
			'error'      => 'Later failure',
			'error_code' => 'later_code',
		);
		$original = array(
			'error'      => 'Original replay failure',
			'error_code' => 'mainwp_restore_db_replay',
		);
		$expired  = array(
			'error'      => 'Original replay failure The child site did not confirm that it stopped restoring. The site may need manual cleanup.',
			'error_code' => 'mainwp_restore_db_replay',
		);
		// Expectations are independent of the phase and reply rules under test.
		// Each tuple is status, outcome, calls, cleanup, event, pending failure, attempts.
		$failed            = array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'restore_task_cancel' ), true, $later, null, null );
		$failed_original   = array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'restore_task_cancel' ), true, $original, null, null );
		$failed_expired    = array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'restore_task_cancel' ), true, $expired, null, 100 );
		$cancelled         = array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'restore_task_cancel', 'backup_task_cancel' ), true, null, null, null );
		$completed         = array( 'running', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array( 'restore_task_cancel' ), false, null, false, null );
		$pending_later     = array( 'running', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 );
		$pending_original  = array( 'running', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 );
		$pending_intent    = array( 'running', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 );
		$pending_retry     = array( 'running', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 );
		$refused_later     = array( 'running', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null );
		$refused_original  = array( 'running', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null );
		$refused_intent    = array( 'running', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null );
		$no_child_failure  = array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), true, $later, null, null );
		$no_child_original = array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), true, $original, null, null );

		$no_child_cancel = array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'backup_task_cancel' ), true, null, null, null );
		$unowned_cancel  = array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), true, null, null, null );

		return array(
			'staging//ok/none/written'                     => array( 'staging', '', 'ok', 'none', false, $failed, null ),
			'staging//ok/none/refused'                     => array( 'staging', '', 'ok', 'none', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//ok/intent/written'                   => array( 'staging', '', 'ok', 'intent', false, $cancelled, 'cancelled' ),
			'staging//ok/intent/refused'                   => array( 'staging', '', 'ok', 'intent', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'staging//ok/failure/written'                  => array( 'staging', '', 'ok', 'failure', false, $failed_original, null ),
			'staging//ok/failure/refused'                  => array( 'staging', '', 'ok', 'failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//ok/retry/written'                    => array( 'staging', '', 'ok', 'retry', false, $failed, null ),
			'staging//ok/retry/refused'                    => array( 'staging', '', 'ok', 'retry', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//ok/expired failure/written'          => array( 'staging', '', 'ok', 'expired failure', false, $failed_original, null ),
			'staging//ok/expired failure/refused'          => array( 'staging', '', 'ok', 'expired failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//failed/none/written'                 => array( 'staging', '', 'failed', 'none', false, $failed, null ),
			'staging//failed/none/refused'                 => array( 'staging', '', 'failed', 'none', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//failed/intent/written'               => array( 'staging', '', 'failed', 'intent', false, $cancelled, 'cancelled' ),
			'staging//failed/intent/refused'               => array( 'staging', '', 'failed', 'intent', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'staging//failed/failure/written'              => array( 'staging', '', 'failed', 'failure', false, $failed_original, null ),
			'staging//failed/failure/refused'              => array( 'staging', '', 'failed', 'failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//failed/retry/written'                => array( 'staging', '', 'failed', 'retry', false, $failed, null ),
			'staging//failed/retry/refused'                => array( 'staging', '', 'failed', 'retry', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//failed/expired failure/written'      => array( 'staging', '', 'failed', 'expired failure', false, $failed_original, null ),
			'staging//failed/expired failure/refused'      => array( 'staging', '', 'failed', 'expired failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//completed/none/written'              => array( 'staging', '', 'completed', 'none', false, $completed, null ),
			'staging//completed/none/refused'              => array( 'staging', '', 'completed', 'none', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//completed/intent/written'            => array( 'staging', '', 'completed', 'intent', false, $completed, 'throws:finished' ),
			'staging//completed/intent/refused'            => array( 'staging', '', 'completed', 'intent', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'staging//completed/failure/written'           => array( 'staging', '', 'completed', 'failure', false, $completed, null ),
			'staging//completed/failure/refused'           => array( 'staging', '', 'completed', 'failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//completed/retry/written'             => array( 'staging', '', 'completed', 'retry', false, $completed, null ),
			'staging//completed/retry/refused'             => array( 'staging', '', 'completed', 'retry', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//completed/expired failure/written'   => array( 'staging', '', 'completed', 'expired failure', false, $completed, null ),
			'staging//completed/expired failure/refused'   => array( 'staging', '', 'completed', 'expired failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//busy/none/written'                   => array( 'staging', '', 'busy', 'none', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'staging//busy/none/refused'                   => array( 'staging', '', 'busy', 'none', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//busy/intent/written'                 => array( 'staging', '', 'busy', 'intent', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'staging//busy/intent/refused'                 => array( 'staging', '', 'busy', 'intent', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'staging//busy/failure/written'                => array( 'staging', '', 'busy', 'failure', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'staging//busy/failure/refused'                => array( 'staging', '', 'busy', 'failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//busy/retry/written'                  => array( 'staging', '', 'busy', 'retry', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'staging//busy/retry/refused'                  => array( 'staging', '', 'busy', 'retry', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//busy/expired failure/written'        => array( 'staging', '', 'busy', 'expired failure', false, $failed_expired, null ),
			'staging//busy/expired failure/refused'        => array( 'staging', '', 'busy', 'expired failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//rolling_back/none/written'           => array( 'staging', '', 'rolling_back', 'none', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'staging//rolling_back/none/refused'           => array( 'staging', '', 'rolling_back', 'none', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//rolling_back/intent/written'         => array( 'staging', '', 'rolling_back', 'intent', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'staging//rolling_back/intent/refused'         => array( 'staging', '', 'rolling_back', 'intent', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'staging//rolling_back/failure/written'        => array( 'staging', '', 'rolling_back', 'failure', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'staging//rolling_back/failure/refused'        => array( 'staging', '', 'rolling_back', 'failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//rolling_back/retry/written'          => array( 'staging', '', 'rolling_back', 'retry', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'staging//rolling_back/retry/refused'          => array( 'staging', '', 'rolling_back', 'retry', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//rolling_back/expired failure/written' => array( 'staging', '', 'rolling_back', 'expired failure', false, $failed_expired, null ),
			'staging//rolling_back/expired failure/refused' => array( 'staging', '', 'rolling_back', 'expired failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//threw/none/written'                  => array( 'staging', '', 'threw', 'none', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'staging//threw/none/refused'                  => array( 'staging', '', 'threw', 'none', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//threw/intent/written'                => array( 'staging', '', 'threw', 'intent', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'staging//threw/intent/refused'                => array( 'staging', '', 'threw', 'intent', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'staging//threw/failure/written'               => array( 'staging', '', 'threw', 'failure', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'staging//threw/failure/refused'               => array( 'staging', '', 'threw', 'failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//threw/retry/written'                 => array( 'staging', '', 'threw', 'retry', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'staging//threw/retry/refused'                 => array( 'staging', '', 'threw', 'retry', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//threw/expired failure/written'       => array( 'staging', '', 'threw', 'expired failure', false, $failed_expired, null ),
			'staging//threw/expired failure/refused'       => array( 'staging', '', 'threw', 'expired failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//error/none/written'                  => array( 'staging', '', 'error', 'none', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'staging//error/none/refused'                  => array( 'staging', '', 'error', 'none', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//error/intent/written'                => array( 'staging', '', 'error', 'intent', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'staging//error/intent/refused'                => array( 'staging', '', 'error', 'intent', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'staging//error/failure/written'               => array( 'staging', '', 'error', 'failure', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'staging//error/failure/refused'               => array( 'staging', '', 'error', 'failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//error/retry/written'                 => array( 'staging', '', 'error', 'retry', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'staging//error/retry/refused'                 => array( 'staging', '', 'error', 'retry', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//error/expired failure/written'       => array( 'staging', '', 'error', 'expired failure', false, $failed_expired, null ),
			'staging//error/expired failure/refused'       => array( 'staging', '', 'error', 'expired failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//unknown task/none/written'           => array( 'staging', '', 'unknown task', 'none', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'staging//unknown task/none/refused'           => array( 'staging', '', 'unknown task', 'none', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//unknown task/intent/written'         => array( 'staging', '', 'unknown task', 'intent', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'staging//unknown task/intent/refused'         => array( 'staging', '', 'unknown task', 'intent', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'staging//unknown task/failure/written'        => array( 'staging', '', 'unknown task', 'failure', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'staging//unknown task/failure/refused'        => array( 'staging', '', 'unknown task', 'failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'staging//unknown task/retry/written'          => array( 'staging', '', 'unknown task', 'retry', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'staging//unknown task/retry/refused'          => array( 'staging', '', 'unknown task', 'retry', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'staging//unknown task/expired failure/written' => array( 'staging', '', 'unknown task', 'expired failure', false, $failed_expired, null ),
			'staging//unknown task/expired failure/refused' => array( 'staging', '', 'unknown task', 'expired failure', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//ok/none/written'                => array( 'transferring', '', 'ok', 'none', false, $failed, null ),
			'transferring//ok/none/refused'                => array( 'transferring', '', 'ok', 'none', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//ok/intent/written'              => array( 'transferring', '', 'ok', 'intent', false, $cancelled, 'cancelled' ),
			'transferring//ok/intent/refused'              => array( 'transferring', '', 'ok', 'intent', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'transferring//ok/failure/written'             => array( 'transferring', '', 'ok', 'failure', false, $failed_original, null ),
			'transferring//ok/failure/refused'             => array( 'transferring', '', 'ok', 'failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//ok/retry/written'               => array( 'transferring', '', 'ok', 'retry', false, $failed, null ),
			'transferring//ok/retry/refused'               => array( 'transferring', '', 'ok', 'retry', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//ok/expired failure/written'     => array( 'transferring', '', 'ok', 'expired failure', false, $failed_original, null ),
			'transferring//ok/expired failure/refused'     => array( 'transferring', '', 'ok', 'expired failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//failed/none/written'            => array( 'transferring', '', 'failed', 'none', false, $failed, null ),
			'transferring//failed/none/refused'            => array( 'transferring', '', 'failed', 'none', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//failed/intent/written'          => array( 'transferring', '', 'failed', 'intent', false, $cancelled, 'cancelled' ),
			'transferring//failed/intent/refused'          => array( 'transferring', '', 'failed', 'intent', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'transferring//failed/failure/written'         => array( 'transferring', '', 'failed', 'failure', false, $failed_original, null ),
			'transferring//failed/failure/refused'         => array( 'transferring', '', 'failed', 'failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//failed/retry/written'           => array( 'transferring', '', 'failed', 'retry', false, $failed, null ),
			'transferring//failed/retry/refused'           => array( 'transferring', '', 'failed', 'retry', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//failed/expired failure/written' => array( 'transferring', '', 'failed', 'expired failure', false, $failed_original, null ),
			'transferring//failed/expired failure/refused' => array( 'transferring', '', 'failed', 'expired failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//completed/none/written'         => array( 'transferring', '', 'completed', 'none', false, $completed, null ),
			'transferring//completed/none/refused'         => array( 'transferring', '', 'completed', 'none', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//completed/intent/written'       => array( 'transferring', '', 'completed', 'intent', false, $completed, 'throws:finished' ),
			'transferring//completed/intent/refused'       => array( 'transferring', '', 'completed', 'intent', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'transferring//completed/failure/written'      => array( 'transferring', '', 'completed', 'failure', false, $completed, null ),
			'transferring//completed/failure/refused'      => array( 'transferring', '', 'completed', 'failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//completed/retry/written'        => array( 'transferring', '', 'completed', 'retry', false, $completed, null ),
			'transferring//completed/retry/refused'        => array( 'transferring', '', 'completed', 'retry', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//completed/expired failure/written' => array( 'transferring', '', 'completed', 'expired failure', false, $completed, null ),
			'transferring//completed/expired failure/refused' => array( 'transferring', '', 'completed', 'expired failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//busy/none/written'              => array( 'transferring', '', 'busy', 'none', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'transferring//busy/none/refused'              => array( 'transferring', '', 'busy', 'none', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//busy/intent/written'            => array( 'transferring', '', 'busy', 'intent', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'transferring//busy/intent/refused'            => array( 'transferring', '', 'busy', 'intent', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'transferring//busy/failure/written'           => array( 'transferring', '', 'busy', 'failure', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'transferring//busy/failure/refused'           => array( 'transferring', '', 'busy', 'failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//busy/retry/written'             => array( 'transferring', '', 'busy', 'retry', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'transferring//busy/retry/refused'             => array( 'transferring', '', 'busy', 'retry', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//busy/expired failure/written'   => array( 'transferring', '', 'busy', 'expired failure', false, $failed_expired, null ),
			'transferring//busy/expired failure/refused'   => array( 'transferring', '', 'busy', 'expired failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//rolling_back/none/written'      => array( 'transferring', '', 'rolling_back', 'none', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'transferring//rolling_back/none/refused'      => array( 'transferring', '', 'rolling_back', 'none', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//rolling_back/intent/written'    => array( 'transferring', '', 'rolling_back', 'intent', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'transferring//rolling_back/intent/refused'    => array( 'transferring', '', 'rolling_back', 'intent', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'transferring//rolling_back/failure/written'   => array( 'transferring', '', 'rolling_back', 'failure', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'transferring//rolling_back/failure/refused'   => array( 'transferring', '', 'rolling_back', 'failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//rolling_back/retry/written'     => array( 'transferring', '', 'rolling_back', 'retry', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'transferring//rolling_back/retry/refused'     => array( 'transferring', '', 'rolling_back', 'retry', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//rolling_back/expired failure/written' => array( 'transferring', '', 'rolling_back', 'expired failure', false, $failed_expired, null ),
			'transferring//rolling_back/expired failure/refused' => array( 'transferring', '', 'rolling_back', 'expired failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//threw/none/written'             => array( 'transferring', '', 'threw', 'none', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'transferring//threw/none/refused'             => array( 'transferring', '', 'threw', 'none', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//threw/intent/written'           => array( 'transferring', '', 'threw', 'intent', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'transferring//threw/intent/refused'           => array( 'transferring', '', 'threw', 'intent', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'transferring//threw/failure/written'          => array( 'transferring', '', 'threw', 'failure', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'transferring//threw/failure/refused'          => array( 'transferring', '', 'threw', 'failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//threw/retry/written'            => array( 'transferring', '', 'threw', 'retry', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'transferring//threw/retry/refused'            => array( 'transferring', '', 'threw', 'retry', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//threw/expired failure/written'  => array( 'transferring', '', 'threw', 'expired failure', false, $failed_expired, null ),
			'transferring//threw/expired failure/refused'  => array( 'transferring', '', 'threw', 'expired failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//error/none/written'             => array( 'transferring', '', 'error', 'none', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'transferring//error/none/refused'             => array( 'transferring', '', 'error', 'none', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//error/intent/written'           => array( 'transferring', '', 'error', 'intent', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'transferring//error/intent/refused'           => array( 'transferring', '', 'error', 'intent', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'transferring//error/failure/written'          => array( 'transferring', '', 'error', 'failure', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'transferring//error/failure/refused'          => array( 'transferring', '', 'error', 'failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//error/retry/written'            => array( 'transferring', '', 'error', 'retry', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'transferring//error/retry/refused'            => array( 'transferring', '', 'error', 'retry', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//error/expired failure/written'  => array( 'transferring', '', 'error', 'expired failure', false, $failed_expired, null ),
			'transferring//error/expired failure/refused'  => array( 'transferring', '', 'error', 'expired failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//unknown task/none/written'      => array( 'transferring', '', 'unknown task', 'none', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'transferring//unknown task/none/refused'      => array( 'transferring', '', 'unknown task', 'none', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//unknown task/intent/written'    => array( 'transferring', '', 'unknown task', 'intent', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'transferring//unknown task/intent/refused'    => array( 'transferring', '', 'unknown task', 'intent', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'transferring//unknown task/failure/written'   => array( 'transferring', '', 'unknown task', 'failure', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'transferring//unknown task/failure/refused'   => array( 'transferring', '', 'unknown task', 'failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'transferring//unknown task/retry/written'     => array( 'transferring', '', 'unknown task', 'retry', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'transferring//unknown task/retry/refused'     => array( 'transferring', '', 'unknown task', 'retry', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'transferring//unknown task/expired failure/written' => array( 'transferring', '', 'unknown task', 'expired failure', false, $failed_expired, null ),
			'transferring//unknown task/expired failure/refused' => array( 'transferring', '', 'unknown task', 'expired failure', true, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'running//ok/none/written'                     => array( 'running', '', 'ok', 'none', false, $failed, null ),
			'running//ok/none/refused'                     => array( 'running', '', 'ok', 'none', true, $refused_later, null ),
			'running//ok/intent/written'                   => array( 'running', '', 'ok', 'intent', false, $cancelled, 'cancelled' ),
			'running//ok/intent/refused'                   => array( 'running', '', 'ok', 'intent', true, $refused_intent, 'cancel_pending' ),
			'running//ok/failure/written'                  => array( 'running', '', 'ok', 'failure', false, $failed_original, null ),
			'running//ok/failure/refused'                  => array( 'running', '', 'ok', 'failure', true, $refused_original, null ),
			'running//ok/retry/written'                    => array( 'running', '', 'ok', 'retry', false, $failed, null ),
			'running//ok/retry/refused'                    => array( 'running', '', 'ok', 'retry', true, $refused_later, null ),
			'running//ok/expired failure/written'          => array( 'running', '', 'ok', 'expired failure', false, $failed_original, null ),
			'running//ok/expired failure/refused'          => array( 'running', '', 'ok', 'expired failure', true, $refused_original, null ),
			'running//failed/none/written'                 => array( 'running', '', 'failed', 'none', false, $failed, null ),
			'running//failed/none/refused'                 => array( 'running', '', 'failed', 'none', true, $refused_later, null ),
			'running//failed/intent/written'               => array( 'running', '', 'failed', 'intent', false, $cancelled, 'cancelled' ),
			'running//failed/intent/refused'               => array( 'running', '', 'failed', 'intent', true, $refused_intent, 'cancel_pending' ),
			'running//failed/failure/written'              => array( 'running', '', 'failed', 'failure', false, $failed_original, null ),
			'running//failed/failure/refused'              => array( 'running', '', 'failed', 'failure', true, $refused_original, null ),
			'running//failed/retry/written'                => array( 'running', '', 'failed', 'retry', false, $failed, null ),
			'running//failed/retry/refused'                => array( 'running', '', 'failed', 'retry', true, $refused_later, null ),
			'running//failed/expired failure/written'      => array( 'running', '', 'failed', 'expired failure', false, $failed_original, null ),
			'running//failed/expired failure/refused'      => array( 'running', '', 'failed', 'expired failure', true, $refused_original, null ),
			'running//completed/none/written'              => array( 'running', '', 'completed', 'none', false, $completed, null ),
			'running//completed/none/refused'              => array( 'running', '', 'completed', 'none', true, $refused_later, null ),
			'running//completed/intent/written'            => array( 'running', '', 'completed', 'intent', false, $completed, 'throws:finished' ),
			'running//completed/intent/refused'            => array( 'running', '', 'completed', 'intent', true, $refused_intent, 'cancel_pending' ),
			'running//completed/failure/written'           => array( 'running', '', 'completed', 'failure', false, $completed, null ),
			'running//completed/failure/refused'           => array( 'running', '', 'completed', 'failure', true, $refused_original, null ),
			'running//completed/retry/written'             => array( 'running', '', 'completed', 'retry', false, $completed, null ),
			'running//completed/retry/refused'             => array( 'running', '', 'completed', 'retry', true, $refused_later, null ),
			'running//completed/expired failure/written'   => array( 'running', '', 'completed', 'expired failure', false, $completed, null ),
			'running//completed/expired failure/refused'   => array( 'running', '', 'completed', 'expired failure', true, $refused_original, null ),
			'running//busy/none/written'                   => array( 'running', '', 'busy', 'none', false, $pending_later, null ),
			'running//busy/none/refused'                   => array( 'running', '', 'busy', 'none', true, $refused_later, null ),
			'running//busy/intent/written'                 => array( 'running', '', 'busy', 'intent', false, $pending_intent, 'cancel_pending' ),
			'running//busy/intent/refused'                 => array( 'running', '', 'busy', 'intent', true, $refused_intent, 'cancel_pending' ),
			'running//busy/failure/written'                => array( 'running', '', 'busy', 'failure', false, $pending_original, null ),
			'running//busy/failure/refused'                => array( 'running', '', 'busy', 'failure', true, $refused_original, null ),
			'running//busy/retry/written'                  => array( 'running', '', 'busy', 'retry', false, $pending_retry, null ),
			'running//busy/retry/refused'                  => array( 'running', '', 'busy', 'retry', true, $refused_later, null ),
			'running//busy/expired failure/written'        => array( 'running', '', 'busy', 'expired failure', false, $failed_expired, null ),
			'running//busy/expired failure/refused'        => array( 'running', '', 'busy', 'expired failure', true, $refused_original, null ),
			'running//rolling_back/none/written'           => array( 'running', '', 'rolling_back', 'none', false, $pending_later, null ),
			'running//rolling_back/none/refused'           => array( 'running', '', 'rolling_back', 'none', true, $refused_later, null ),
			'running//rolling_back/intent/written'         => array( 'running', '', 'rolling_back', 'intent', false, $pending_intent, 'cancel_pending' ),
			'running//rolling_back/intent/refused'         => array( 'running', '', 'rolling_back', 'intent', true, $refused_intent, 'cancel_pending' ),
			'running//rolling_back/failure/written'        => array( 'running', '', 'rolling_back', 'failure', false, $pending_original, null ),
			'running//rolling_back/failure/refused'        => array( 'running', '', 'rolling_back', 'failure', true, $refused_original, null ),
			'running//rolling_back/retry/written'          => array( 'running', '', 'rolling_back', 'retry', false, $pending_retry, null ),
			'running//rolling_back/retry/refused'          => array( 'running', '', 'rolling_back', 'retry', true, $refused_later, null ),
			'running//rolling_back/expired failure/written' => array( 'running', '', 'rolling_back', 'expired failure', false, $failed_expired, null ),
			'running//rolling_back/expired failure/refused' => array( 'running', '', 'rolling_back', 'expired failure', true, $refused_original, null ),
			'running//threw/none/written'                  => array( 'running', '', 'threw', 'none', false, $pending_later, null ),
			'running//threw/none/refused'                  => array( 'running', '', 'threw', 'none', true, $refused_later, null ),
			'running//threw/intent/written'                => array( 'running', '', 'threw', 'intent', false, $pending_intent, 'cancel_pending' ),
			'running//threw/intent/refused'                => array( 'running', '', 'threw', 'intent', true, $refused_intent, 'cancel_pending' ),
			'running//threw/failure/written'               => array( 'running', '', 'threw', 'failure', false, $pending_original, null ),
			'running//threw/failure/refused'               => array( 'running', '', 'threw', 'failure', true, $refused_original, null ),
			'running//threw/retry/written'                 => array( 'running', '', 'threw', 'retry', false, $pending_retry, null ),
			'running//threw/retry/refused'                 => array( 'running', '', 'threw', 'retry', true, $refused_later, null ),
			'running//threw/expired failure/written'       => array( 'running', '', 'threw', 'expired failure', false, $failed_expired, null ),
			'running//threw/expired failure/refused'       => array( 'running', '', 'threw', 'expired failure', true, $refused_original, null ),
			'running//error/none/written'                  => array( 'running', '', 'error', 'none', false, $pending_later, null ),
			'running//error/none/refused'                  => array( 'running', '', 'error', 'none', true, $refused_later, null ),
			'running//error/intent/written'                => array( 'running', '', 'error', 'intent', false, $pending_intent, 'cancel_pending' ),
			'running//error/intent/refused'                => array( 'running', '', 'error', 'intent', true, $refused_intent, 'cancel_pending' ),
			'running//error/failure/written'               => array( 'running', '', 'error', 'failure', false, $pending_original, null ),
			'running//error/failure/refused'               => array( 'running', '', 'error', 'failure', true, $refused_original, null ),
			'running//error/retry/written'                 => array( 'running', '', 'error', 'retry', false, $pending_retry, null ),
			'running//error/retry/refused'                 => array( 'running', '', 'error', 'retry', true, $refused_later, null ),
			'running//error/expired failure/written'       => array( 'running', '', 'error', 'expired failure', false, $failed_expired, null ),
			'running//error/expired failure/refused'       => array( 'running', '', 'error', 'expired failure', true, $refused_original, null ),
			'running//unknown task/none/written'           => array( 'running', '', 'unknown task', 'none', false, $pending_later, null ),
			'running//unknown task/none/refused'           => array( 'running', '', 'unknown task', 'none', true, $refused_later, null ),
			'running//unknown task/intent/written'         => array( 'running', '', 'unknown task', 'intent', false, $pending_intent, 'cancel_pending' ),
			'running//unknown task/intent/refused'         => array( 'running', '', 'unknown task', 'intent', true, $refused_intent, 'cancel_pending' ),
			'running//unknown task/failure/written'        => array( 'running', '', 'unknown task', 'failure', false, $pending_original, null ),
			'running//unknown task/failure/refused'        => array( 'running', '', 'unknown task', 'failure', true, $refused_original, null ),
			'running//unknown task/retry/written'          => array( 'running', '', 'unknown task', 'retry', false, $pending_retry, null ),
			'running//unknown task/retry/refused'          => array( 'running', '', 'unknown task', 'retry', true, $refused_later, null ),
			'running//unknown task/expired failure/written' => array( 'running', '', 'unknown task', 'expired failure', false, $failed_expired, null ),
			'running//unknown task/expired failure/refused' => array( 'running', '', 'unknown task', 'expired failure', true, $refused_original, null ),
			'failed_retryable/staging/ok/none/written'     => array( 'failed_retryable', 'staging', 'ok', 'none', false, $failed, null ),
			'failed_retryable/staging/ok/none/refused'     => array( 'failed_retryable', 'staging', 'ok', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/ok/intent/written'   => array( 'failed_retryable', 'staging', 'ok', 'intent', false, $cancelled, 'cancelled' ),
			'failed_retryable/staging/ok/intent/refused'   => array( 'failed_retryable', 'staging', 'ok', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/staging/ok/failure/written'  => array( 'failed_retryable', 'staging', 'ok', 'failure', false, $failed_original, null ),
			'failed_retryable/staging/ok/failure/refused'  => array( 'failed_retryable', 'staging', 'ok', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/ok/retry/written'    => array( 'failed_retryable', 'staging', 'ok', 'retry', false, $failed, null ),
			'failed_retryable/staging/ok/retry/refused'    => array( 'failed_retryable', 'staging', 'ok', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/ok/expired failure/written' => array( 'failed_retryable', 'staging', 'ok', 'expired failure', false, $failed_original, null ),
			'failed_retryable/staging/ok/expired failure/refused' => array( 'failed_retryable', 'staging', 'ok', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/failed/none/written' => array( 'failed_retryable', 'staging', 'failed', 'none', false, $failed, null ),
			'failed_retryable/staging/failed/none/refused' => array( 'failed_retryable', 'staging', 'failed', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/failed/intent/written' => array( 'failed_retryable', 'staging', 'failed', 'intent', false, $cancelled, 'cancelled' ),
			'failed_retryable/staging/failed/intent/refused' => array( 'failed_retryable', 'staging', 'failed', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/staging/failed/failure/written' => array( 'failed_retryable', 'staging', 'failed', 'failure', false, $failed_original, null ),
			'failed_retryable/staging/failed/failure/refused' => array( 'failed_retryable', 'staging', 'failed', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/failed/retry/written' => array( 'failed_retryable', 'staging', 'failed', 'retry', false, $failed, null ),
			'failed_retryable/staging/failed/retry/refused' => array( 'failed_retryable', 'staging', 'failed', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/failed/expired failure/written' => array( 'failed_retryable', 'staging', 'failed', 'expired failure', false, $failed_original, null ),
			'failed_retryable/staging/failed/expired failure/refused' => array( 'failed_retryable', 'staging', 'failed', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/completed/none/written' => array( 'failed_retryable', 'staging', 'completed', 'none', false, $completed, null ),
			'failed_retryable/staging/completed/none/refused' => array( 'failed_retryable', 'staging', 'completed', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/completed/intent/written' => array( 'failed_retryable', 'staging', 'completed', 'intent', false, $completed, 'throws:finished' ),
			'failed_retryable/staging/completed/intent/refused' => array( 'failed_retryable', 'staging', 'completed', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/staging/completed/failure/written' => array( 'failed_retryable', 'staging', 'completed', 'failure', false, $completed, null ),
			'failed_retryable/staging/completed/failure/refused' => array( 'failed_retryable', 'staging', 'completed', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/completed/retry/written' => array( 'failed_retryable', 'staging', 'completed', 'retry', false, $completed, null ),
			'failed_retryable/staging/completed/retry/refused' => array( 'failed_retryable', 'staging', 'completed', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/completed/expired failure/written' => array( 'failed_retryable', 'staging', 'completed', 'expired failure', false, $completed, null ),
			'failed_retryable/staging/completed/expired failure/refused' => array( 'failed_retryable', 'staging', 'completed', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/busy/none/written'   => array( 'failed_retryable', 'staging', 'busy', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/staging/busy/none/refused'   => array( 'failed_retryable', 'staging', 'busy', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/busy/intent/written' => array( 'failed_retryable', 'staging', 'busy', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/staging/busy/intent/refused' => array( 'failed_retryable', 'staging', 'busy', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/staging/busy/failure/written' => array( 'failed_retryable', 'staging', 'busy', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/staging/busy/failure/refused' => array( 'failed_retryable', 'staging', 'busy', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/busy/retry/written'  => array( 'failed_retryable', 'staging', 'busy', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/staging/busy/retry/refused'  => array( 'failed_retryable', 'staging', 'busy', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/busy/expired failure/written' => array( 'failed_retryable', 'staging', 'busy', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/staging/busy/expired failure/refused' => array( 'failed_retryable', 'staging', 'busy', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/rolling_back/none/written' => array( 'failed_retryable', 'staging', 'rolling_back', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/staging/rolling_back/none/refused' => array( 'failed_retryable', 'staging', 'rolling_back', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/rolling_back/intent/written' => array( 'failed_retryable', 'staging', 'rolling_back', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/staging/rolling_back/intent/refused' => array( 'failed_retryable', 'staging', 'rolling_back', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/staging/rolling_back/failure/written' => array( 'failed_retryable', 'staging', 'rolling_back', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/staging/rolling_back/failure/refused' => array( 'failed_retryable', 'staging', 'rolling_back', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/rolling_back/retry/written' => array( 'failed_retryable', 'staging', 'rolling_back', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/staging/rolling_back/retry/refused' => array( 'failed_retryable', 'staging', 'rolling_back', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/rolling_back/expired failure/written' => array( 'failed_retryable', 'staging', 'rolling_back', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/staging/rolling_back/expired failure/refused' => array( 'failed_retryable', 'staging', 'rolling_back', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/threw/none/written'  => array( 'failed_retryable', 'staging', 'threw', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/staging/threw/none/refused'  => array( 'failed_retryable', 'staging', 'threw', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/threw/intent/written' => array( 'failed_retryable', 'staging', 'threw', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/staging/threw/intent/refused' => array( 'failed_retryable', 'staging', 'threw', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/staging/threw/failure/written' => array( 'failed_retryable', 'staging', 'threw', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/staging/threw/failure/refused' => array( 'failed_retryable', 'staging', 'threw', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/threw/retry/written' => array( 'failed_retryable', 'staging', 'threw', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/staging/threw/retry/refused' => array( 'failed_retryable', 'staging', 'threw', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/threw/expired failure/written' => array( 'failed_retryable', 'staging', 'threw', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/staging/threw/expired failure/refused' => array( 'failed_retryable', 'staging', 'threw', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/error/none/written'  => array( 'failed_retryable', 'staging', 'error', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/staging/error/none/refused'  => array( 'failed_retryable', 'staging', 'error', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/error/intent/written' => array( 'failed_retryable', 'staging', 'error', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/staging/error/intent/refused' => array( 'failed_retryable', 'staging', 'error', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/staging/error/failure/written' => array( 'failed_retryable', 'staging', 'error', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/staging/error/failure/refused' => array( 'failed_retryable', 'staging', 'error', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/error/retry/written' => array( 'failed_retryable', 'staging', 'error', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/staging/error/retry/refused' => array( 'failed_retryable', 'staging', 'error', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/error/expired failure/written' => array( 'failed_retryable', 'staging', 'error', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/staging/error/expired failure/refused' => array( 'failed_retryable', 'staging', 'error', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/unknown task/none/written' => array( 'failed_retryable', 'staging', 'unknown task', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/staging/unknown task/none/refused' => array( 'failed_retryable', 'staging', 'unknown task', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/unknown task/intent/written' => array( 'failed_retryable', 'staging', 'unknown task', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/staging/unknown task/intent/refused' => array( 'failed_retryable', 'staging', 'unknown task', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/staging/unknown task/failure/written' => array( 'failed_retryable', 'staging', 'unknown task', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/staging/unknown task/failure/refused' => array( 'failed_retryable', 'staging', 'unknown task', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/staging/unknown task/retry/written' => array( 'failed_retryable', 'staging', 'unknown task', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/staging/unknown task/retry/refused' => array( 'failed_retryable', 'staging', 'unknown task', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/staging/unknown task/expired failure/written' => array( 'failed_retryable', 'staging', 'unknown task', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/staging/unknown task/expired failure/refused' => array( 'failed_retryable', 'staging', 'unknown task', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/ok/none/written' => array( 'failed_retryable', 'transferring', 'ok', 'none', false, $failed, null ),
			'failed_retryable/transferring/ok/none/refused' => array( 'failed_retryable', 'transferring', 'ok', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/ok/intent/written' => array( 'failed_retryable', 'transferring', 'ok', 'intent', false, $cancelled, 'cancelled' ),
			'failed_retryable/transferring/ok/intent/refused' => array( 'failed_retryable', 'transferring', 'ok', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/transferring/ok/failure/written' => array( 'failed_retryable', 'transferring', 'ok', 'failure', false, $failed_original, null ),
			'failed_retryable/transferring/ok/failure/refused' => array( 'failed_retryable', 'transferring', 'ok', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/ok/retry/written' => array( 'failed_retryable', 'transferring', 'ok', 'retry', false, $failed, null ),
			'failed_retryable/transferring/ok/retry/refused' => array( 'failed_retryable', 'transferring', 'ok', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/ok/expired failure/written' => array( 'failed_retryable', 'transferring', 'ok', 'expired failure', false, $failed_original, null ),
			'failed_retryable/transferring/ok/expired failure/refused' => array( 'failed_retryable', 'transferring', 'ok', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/failed/none/written' => array( 'failed_retryable', 'transferring', 'failed', 'none', false, $failed, null ),
			'failed_retryable/transferring/failed/none/refused' => array( 'failed_retryable', 'transferring', 'failed', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/failed/intent/written' => array( 'failed_retryable', 'transferring', 'failed', 'intent', false, $cancelled, 'cancelled' ),
			'failed_retryable/transferring/failed/intent/refused' => array( 'failed_retryable', 'transferring', 'failed', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/transferring/failed/failure/written' => array( 'failed_retryable', 'transferring', 'failed', 'failure', false, $failed_original, null ),
			'failed_retryable/transferring/failed/failure/refused' => array( 'failed_retryable', 'transferring', 'failed', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/failed/retry/written' => array( 'failed_retryable', 'transferring', 'failed', 'retry', false, $failed, null ),
			'failed_retryable/transferring/failed/retry/refused' => array( 'failed_retryable', 'transferring', 'failed', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/failed/expired failure/written' => array( 'failed_retryable', 'transferring', 'failed', 'expired failure', false, $failed_original, null ),
			'failed_retryable/transferring/failed/expired failure/refused' => array( 'failed_retryable', 'transferring', 'failed', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/completed/none/written' => array( 'failed_retryable', 'transferring', 'completed', 'none', false, $completed, null ),
			'failed_retryable/transferring/completed/none/refused' => array( 'failed_retryable', 'transferring', 'completed', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/completed/intent/written' => array( 'failed_retryable', 'transferring', 'completed', 'intent', false, $completed, 'throws:finished' ),
			'failed_retryable/transferring/completed/intent/refused' => array( 'failed_retryable', 'transferring', 'completed', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/transferring/completed/failure/written' => array( 'failed_retryable', 'transferring', 'completed', 'failure', false, $completed, null ),
			'failed_retryable/transferring/completed/failure/refused' => array( 'failed_retryable', 'transferring', 'completed', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/completed/retry/written' => array( 'failed_retryable', 'transferring', 'completed', 'retry', false, $completed, null ),
			'failed_retryable/transferring/completed/retry/refused' => array( 'failed_retryable', 'transferring', 'completed', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/completed/expired failure/written' => array( 'failed_retryable', 'transferring', 'completed', 'expired failure', false, $completed, null ),
			'failed_retryable/transferring/completed/expired failure/refused' => array( 'failed_retryable', 'transferring', 'completed', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/busy/none/written' => array( 'failed_retryable', 'transferring', 'busy', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/transferring/busy/none/refused' => array( 'failed_retryable', 'transferring', 'busy', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/busy/intent/written' => array( 'failed_retryable', 'transferring', 'busy', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/transferring/busy/intent/refused' => array( 'failed_retryable', 'transferring', 'busy', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/transferring/busy/failure/written' => array( 'failed_retryable', 'transferring', 'busy', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/transferring/busy/failure/refused' => array( 'failed_retryable', 'transferring', 'busy', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/busy/retry/written' => array( 'failed_retryable', 'transferring', 'busy', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/transferring/busy/retry/refused' => array( 'failed_retryable', 'transferring', 'busy', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/busy/expired failure/written' => array( 'failed_retryable', 'transferring', 'busy', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/transferring/busy/expired failure/refused' => array( 'failed_retryable', 'transferring', 'busy', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/rolling_back/none/written' => array( 'failed_retryable', 'transferring', 'rolling_back', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/transferring/rolling_back/none/refused' => array( 'failed_retryable', 'transferring', 'rolling_back', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/rolling_back/intent/written' => array( 'failed_retryable', 'transferring', 'rolling_back', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/transferring/rolling_back/intent/refused' => array( 'failed_retryable', 'transferring', 'rolling_back', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/transferring/rolling_back/failure/written' => array( 'failed_retryable', 'transferring', 'rolling_back', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/transferring/rolling_back/failure/refused' => array( 'failed_retryable', 'transferring', 'rolling_back', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/rolling_back/retry/written' => array( 'failed_retryable', 'transferring', 'rolling_back', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/transferring/rolling_back/retry/refused' => array( 'failed_retryable', 'transferring', 'rolling_back', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/rolling_back/expired failure/written' => array( 'failed_retryable', 'transferring', 'rolling_back', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/transferring/rolling_back/expired failure/refused' => array( 'failed_retryable', 'transferring', 'rolling_back', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/threw/none/written' => array( 'failed_retryable', 'transferring', 'threw', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/transferring/threw/none/refused' => array( 'failed_retryable', 'transferring', 'threw', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/threw/intent/written' => array( 'failed_retryable', 'transferring', 'threw', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/transferring/threw/intent/refused' => array( 'failed_retryable', 'transferring', 'threw', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/transferring/threw/failure/written' => array( 'failed_retryable', 'transferring', 'threw', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/transferring/threw/failure/refused' => array( 'failed_retryable', 'transferring', 'threw', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/threw/retry/written' => array( 'failed_retryable', 'transferring', 'threw', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/transferring/threw/retry/refused' => array( 'failed_retryable', 'transferring', 'threw', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/threw/expired failure/written' => array( 'failed_retryable', 'transferring', 'threw', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/transferring/threw/expired failure/refused' => array( 'failed_retryable', 'transferring', 'threw', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/error/none/written' => array( 'failed_retryable', 'transferring', 'error', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/transferring/error/none/refused' => array( 'failed_retryable', 'transferring', 'error', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/error/intent/written' => array( 'failed_retryable', 'transferring', 'error', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/transferring/error/intent/refused' => array( 'failed_retryable', 'transferring', 'error', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/transferring/error/failure/written' => array( 'failed_retryable', 'transferring', 'error', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/transferring/error/failure/refused' => array( 'failed_retryable', 'transferring', 'error', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/error/retry/written' => array( 'failed_retryable', 'transferring', 'error', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/transferring/error/retry/refused' => array( 'failed_retryable', 'transferring', 'error', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/error/expired failure/written' => array( 'failed_retryable', 'transferring', 'error', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/transferring/error/expired failure/refused' => array( 'failed_retryable', 'transferring', 'error', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/unknown task/none/written' => array( 'failed_retryable', 'transferring', 'unknown task', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/transferring/unknown task/none/refused' => array( 'failed_retryable', 'transferring', 'unknown task', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/unknown task/intent/written' => array( 'failed_retryable', 'transferring', 'unknown task', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/transferring/unknown task/intent/refused' => array( 'failed_retryable', 'transferring', 'unknown task', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/transferring/unknown task/failure/written' => array( 'failed_retryable', 'transferring', 'unknown task', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/transferring/unknown task/failure/refused' => array( 'failed_retryable', 'transferring', 'unknown task', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/transferring/unknown task/retry/written' => array( 'failed_retryable', 'transferring', 'unknown task', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/transferring/unknown task/retry/refused' => array( 'failed_retryable', 'transferring', 'unknown task', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/transferring/unknown task/expired failure/written' => array( 'failed_retryable', 'transferring', 'unknown task', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/transferring/unknown task/expired failure/refused' => array( 'failed_retryable', 'transferring', 'unknown task', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/ok/none/written'     => array( 'failed_retryable', 'running', 'ok', 'none', false, $failed, null ),
			'failed_retryable/running/ok/none/refused'     => array( 'failed_retryable', 'running', 'ok', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/ok/intent/written'   => array( 'failed_retryable', 'running', 'ok', 'intent', false, $cancelled, 'cancelled' ),
			'failed_retryable/running/ok/intent/refused'   => array( 'failed_retryable', 'running', 'ok', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/running/ok/failure/written'  => array( 'failed_retryable', 'running', 'ok', 'failure', false, $failed_original, null ),
			'failed_retryable/running/ok/failure/refused'  => array( 'failed_retryable', 'running', 'ok', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/ok/retry/written'    => array( 'failed_retryable', 'running', 'ok', 'retry', false, $failed, null ),
			'failed_retryable/running/ok/retry/refused'    => array( 'failed_retryable', 'running', 'ok', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/ok/expired failure/written' => array( 'failed_retryable', 'running', 'ok', 'expired failure', false, $failed_original, null ),
			'failed_retryable/running/ok/expired failure/refused' => array( 'failed_retryable', 'running', 'ok', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/failed/none/written' => array( 'failed_retryable', 'running', 'failed', 'none', false, $failed, null ),
			'failed_retryable/running/failed/none/refused' => array( 'failed_retryable', 'running', 'failed', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/failed/intent/written' => array( 'failed_retryable', 'running', 'failed', 'intent', false, $cancelled, 'cancelled' ),
			'failed_retryable/running/failed/intent/refused' => array( 'failed_retryable', 'running', 'failed', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/running/failed/failure/written' => array( 'failed_retryable', 'running', 'failed', 'failure', false, $failed_original, null ),
			'failed_retryable/running/failed/failure/refused' => array( 'failed_retryable', 'running', 'failed', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/failed/retry/written' => array( 'failed_retryable', 'running', 'failed', 'retry', false, $failed, null ),
			'failed_retryable/running/failed/retry/refused' => array( 'failed_retryable', 'running', 'failed', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/failed/expired failure/written' => array( 'failed_retryable', 'running', 'failed', 'expired failure', false, $failed_original, null ),
			'failed_retryable/running/failed/expired failure/refused' => array( 'failed_retryable', 'running', 'failed', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/completed/none/written' => array( 'failed_retryable', 'running', 'completed', 'none', false, $completed, null ),
			'failed_retryable/running/completed/none/refused' => array( 'failed_retryable', 'running', 'completed', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/completed/intent/written' => array( 'failed_retryable', 'running', 'completed', 'intent', false, $completed, 'throws:finished' ),
			'failed_retryable/running/completed/intent/refused' => array( 'failed_retryable', 'running', 'completed', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/running/completed/failure/written' => array( 'failed_retryable', 'running', 'completed', 'failure', false, $completed, null ),
			'failed_retryable/running/completed/failure/refused' => array( 'failed_retryable', 'running', 'completed', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/completed/retry/written' => array( 'failed_retryable', 'running', 'completed', 'retry', false, $completed, null ),
			'failed_retryable/running/completed/retry/refused' => array( 'failed_retryable', 'running', 'completed', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/completed/expired failure/written' => array( 'failed_retryable', 'running', 'completed', 'expired failure', false, $completed, null ),
			'failed_retryable/running/completed/expired failure/refused' => array( 'failed_retryable', 'running', 'completed', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/busy/none/written'   => array( 'failed_retryable', 'running', 'busy', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/running/busy/none/refused'   => array( 'failed_retryable', 'running', 'busy', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/busy/intent/written' => array( 'failed_retryable', 'running', 'busy', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/running/busy/intent/refused' => array( 'failed_retryable', 'running', 'busy', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/running/busy/failure/written' => array( 'failed_retryable', 'running', 'busy', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/running/busy/failure/refused' => array( 'failed_retryable', 'running', 'busy', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/busy/retry/written'  => array( 'failed_retryable', 'running', 'busy', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/running/busy/retry/refused'  => array( 'failed_retryable', 'running', 'busy', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/busy/expired failure/written' => array( 'failed_retryable', 'running', 'busy', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/running/busy/expired failure/refused' => array( 'failed_retryable', 'running', 'busy', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/rolling_back/none/written' => array( 'failed_retryable', 'running', 'rolling_back', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/running/rolling_back/none/refused' => array( 'failed_retryable', 'running', 'rolling_back', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/rolling_back/intent/written' => array( 'failed_retryable', 'running', 'rolling_back', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/running/rolling_back/intent/refused' => array( 'failed_retryable', 'running', 'rolling_back', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/running/rolling_back/failure/written' => array( 'failed_retryable', 'running', 'rolling_back', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/running/rolling_back/failure/refused' => array( 'failed_retryable', 'running', 'rolling_back', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/rolling_back/retry/written' => array( 'failed_retryable', 'running', 'rolling_back', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/running/rolling_back/retry/refused' => array( 'failed_retryable', 'running', 'rolling_back', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/rolling_back/expired failure/written' => array( 'failed_retryable', 'running', 'rolling_back', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/running/rolling_back/expired failure/refused' => array( 'failed_retryable', 'running', 'rolling_back', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/threw/none/written'  => array( 'failed_retryable', 'running', 'threw', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/running/threw/none/refused'  => array( 'failed_retryable', 'running', 'threw', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/threw/intent/written' => array( 'failed_retryable', 'running', 'threw', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/running/threw/intent/refused' => array( 'failed_retryable', 'running', 'threw', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/running/threw/failure/written' => array( 'failed_retryable', 'running', 'threw', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/running/threw/failure/refused' => array( 'failed_retryable', 'running', 'threw', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/threw/retry/written' => array( 'failed_retryable', 'running', 'threw', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/running/threw/retry/refused' => array( 'failed_retryable', 'running', 'threw', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/threw/expired failure/written' => array( 'failed_retryable', 'running', 'threw', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/running/threw/expired failure/refused' => array( 'failed_retryable', 'running', 'threw', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/error/none/written'  => array( 'failed_retryable', 'running', 'error', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/running/error/none/refused'  => array( 'failed_retryable', 'running', 'error', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/error/intent/written' => array( 'failed_retryable', 'running', 'error', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/running/error/intent/refused' => array( 'failed_retryable', 'running', 'error', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/running/error/failure/written' => array( 'failed_retryable', 'running', 'error', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/running/error/failure/refused' => array( 'failed_retryable', 'running', 'error', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/error/retry/written' => array( 'failed_retryable', 'running', 'error', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/running/error/retry/refused' => array( 'failed_retryable', 'running', 'error', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/error/expired failure/written' => array( 'failed_retryable', 'running', 'error', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/running/error/expired failure/refused' => array( 'failed_retryable', 'running', 'error', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/unknown task/none/written' => array( 'failed_retryable', 'running', 'unknown task', 'none', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 1 ), null ),
			'failed_retryable/running/unknown task/none/refused' => array( 'failed_retryable', 'running', 'unknown task', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/unknown task/intent/written' => array( 'failed_retryable', 'running', 'unknown task', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'failed_retryable/running/unknown task/intent/refused' => array( 'failed_retryable', 'running', 'unknown task', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, true, null ), 'cancel_pending' ),
			'failed_retryable/running/unknown task/failure/written' => array( 'failed_retryable', 'running', 'unknown task', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'failed_retryable/running/unknown task/failure/refused' => array( 'failed_retryable', 'running', 'unknown task', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'failed_retryable/running/unknown task/retry/written' => array( 'failed_retryable', 'running', 'unknown task', 'retry', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $later, 100 ), null ),
			'failed_retryable/running/unknown task/retry/refused' => array( 'failed_retryable', 'running', 'unknown task', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'failed_retryable/running/unknown task/expired failure/written' => array( 'failed_retryable', 'running', 'unknown task', 'expired failure', false, $failed_expired, null ),
			'failed_retryable/running/unknown task/expired failure/refused' => array( 'failed_retryable', 'running', 'unknown task', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'verifying//ok/none/written'                   => array( 'verifying', '', 'ok', 'none', false, $no_child_failure, null ),
			'verifying//ok/none/refused'                   => array( 'verifying', '', 'ok', 'none', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//ok/intent/written'                 => array( 'verifying', '', 'ok', 'intent', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//ok/intent/refused'                 => array( 'verifying', '', 'ok', 'intent', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//ok/failure/written'                => array( 'verifying', '', 'ok', 'failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//ok/failure/refused'                => array( 'verifying', '', 'ok', 'failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//ok/retry/written'                  => array( 'verifying', '', 'ok', 'retry', false, $no_child_failure, null ),
			'verifying//ok/retry/refused'                  => array( 'verifying', '', 'ok', 'retry', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//ok/expired failure/written'        => array( 'verifying', '', 'ok', 'expired failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//ok/expired failure/refused'        => array( 'verifying', '', 'ok', 'expired failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//failed/none/written'               => array( 'verifying', '', 'failed', 'none', false, $no_child_failure, null ),
			'verifying//failed/none/refused'               => array( 'verifying', '', 'failed', 'none', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//failed/intent/written'             => array( 'verifying', '', 'failed', 'intent', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//failed/intent/refused'             => array( 'verifying', '', 'failed', 'intent', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//failed/failure/written'            => array( 'verifying', '', 'failed', 'failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//failed/failure/refused'            => array( 'verifying', '', 'failed', 'failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//failed/retry/written'              => array( 'verifying', '', 'failed', 'retry', false, $no_child_failure, null ),
			'verifying//failed/retry/refused'              => array( 'verifying', '', 'failed', 'retry', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//failed/expired failure/written'    => array( 'verifying', '', 'failed', 'expired failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//failed/expired failure/refused'    => array( 'verifying', '', 'failed', 'expired failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//completed/none/written'            => array( 'verifying', '', 'completed', 'none', false, $no_child_failure, null ),
			'verifying//completed/none/refused'            => array( 'verifying', '', 'completed', 'none', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//completed/intent/written'          => array( 'verifying', '', 'completed', 'intent', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//completed/intent/refused'          => array( 'verifying', '', 'completed', 'intent', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//completed/failure/written'         => array( 'verifying', '', 'completed', 'failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//completed/failure/refused'         => array( 'verifying', '', 'completed', 'failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//completed/retry/written'           => array( 'verifying', '', 'completed', 'retry', false, $no_child_failure, null ),
			'verifying//completed/retry/refused'           => array( 'verifying', '', 'completed', 'retry', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//completed/expired failure/written' => array( 'verifying', '', 'completed', 'expired failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//completed/expired failure/refused' => array( 'verifying', '', 'completed', 'expired failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//busy/none/written'                 => array( 'verifying', '', 'busy', 'none', false, $no_child_failure, null ),
			'verifying//busy/none/refused'                 => array( 'verifying', '', 'busy', 'none', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//busy/intent/written'               => array( 'verifying', '', 'busy', 'intent', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//busy/intent/refused'               => array( 'verifying', '', 'busy', 'intent', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//busy/failure/written'              => array( 'verifying', '', 'busy', 'failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//busy/failure/refused'              => array( 'verifying', '', 'busy', 'failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//busy/retry/written'                => array( 'verifying', '', 'busy', 'retry', false, $no_child_failure, null ),
			'verifying//busy/retry/refused'                => array( 'verifying', '', 'busy', 'retry', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//busy/expired failure/written'      => array( 'verifying', '', 'busy', 'expired failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//busy/expired failure/refused'      => array( 'verifying', '', 'busy', 'expired failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//rolling_back/none/written'         => array( 'verifying', '', 'rolling_back', 'none', false, $no_child_failure, null ),
			'verifying//rolling_back/none/refused'         => array( 'verifying', '', 'rolling_back', 'none', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//rolling_back/intent/written'       => array( 'verifying', '', 'rolling_back', 'intent', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//rolling_back/intent/refused'       => array( 'verifying', '', 'rolling_back', 'intent', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//rolling_back/failure/written'      => array( 'verifying', '', 'rolling_back', 'failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//rolling_back/failure/refused'      => array( 'verifying', '', 'rolling_back', 'failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//rolling_back/retry/written'        => array( 'verifying', '', 'rolling_back', 'retry', false, $no_child_failure, null ),
			'verifying//rolling_back/retry/refused'        => array( 'verifying', '', 'rolling_back', 'retry', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//rolling_back/expired failure/written' => array( 'verifying', '', 'rolling_back', 'expired failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//rolling_back/expired failure/refused' => array( 'verifying', '', 'rolling_back', 'expired failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//threw/none/written'                => array( 'verifying', '', 'threw', 'none', false, $no_child_failure, null ),
			'verifying//threw/none/refused'                => array( 'verifying', '', 'threw', 'none', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//threw/intent/written'              => array( 'verifying', '', 'threw', 'intent', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//threw/intent/refused'              => array( 'verifying', '', 'threw', 'intent', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//threw/failure/written'             => array( 'verifying', '', 'threw', 'failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//threw/failure/refused'             => array( 'verifying', '', 'threw', 'failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//threw/retry/written'               => array( 'verifying', '', 'threw', 'retry', false, $no_child_failure, null ),
			'verifying//threw/retry/refused'               => array( 'verifying', '', 'threw', 'retry', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//threw/expired failure/written'     => array( 'verifying', '', 'threw', 'expired failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//threw/expired failure/refused'     => array( 'verifying', '', 'threw', 'expired failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//error/none/written'                => array( 'verifying', '', 'error', 'none', false, $no_child_failure, null ),
			'verifying//error/none/refused'                => array( 'verifying', '', 'error', 'none', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//error/intent/written'              => array( 'verifying', '', 'error', 'intent', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//error/intent/refused'              => array( 'verifying', '', 'error', 'intent', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//error/failure/written'             => array( 'verifying', '', 'error', 'failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//error/failure/refused'             => array( 'verifying', '', 'error', 'failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//error/retry/written'               => array( 'verifying', '', 'error', 'retry', false, $no_child_failure, null ),
			'verifying//error/retry/refused'               => array( 'verifying', '', 'error', 'retry', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//error/expired failure/written'     => array( 'verifying', '', 'error', 'expired failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//error/expired failure/refused'     => array( 'verifying', '', 'error', 'expired failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//unknown task/none/written'         => array( 'verifying', '', 'unknown task', 'none', false, $no_child_failure, null ),
			'verifying//unknown task/none/refused'         => array( 'verifying', '', 'unknown task', 'none', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//unknown task/intent/written'       => array( 'verifying', '', 'unknown task', 'intent', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//unknown task/intent/refused'       => array( 'verifying', '', 'unknown task', 'intent', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//unknown task/failure/written'      => array( 'verifying', '', 'unknown task', 'failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//unknown task/failure/refused'      => array( 'verifying', '', 'unknown task', 'failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//unknown task/retry/written'        => array( 'verifying', '', 'unknown task', 'retry', false, $no_child_failure, null ),
			'verifying//unknown task/retry/refused'        => array( 'verifying', '', 'unknown task', 'retry', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'verifying//unknown task/expired failure/written' => array( 'verifying', '', 'unknown task', 'expired failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//unknown task/expired failure/refused' => array( 'verifying', '', 'unknown task', 'expired failure', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/ok/none/written'   => array( 'failed_retryable', 'verifying', 'ok', 'none', false, $no_child_failure, null ),
			'failed_retryable/verifying/ok/none/refused'   => array( 'failed_retryable', 'verifying', 'ok', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/ok/intent/written' => array( 'failed_retryable', 'verifying', 'ok', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/ok/intent/refused' => array( 'failed_retryable', 'verifying', 'ok', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/ok/failure/written' => array( 'failed_retryable', 'verifying', 'ok', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/ok/failure/refused' => array( 'failed_retryable', 'verifying', 'ok', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/ok/retry/written'  => array( 'failed_retryable', 'verifying', 'ok', 'retry', false, $no_child_failure, null ),
			'failed_retryable/verifying/ok/retry/refused'  => array( 'failed_retryable', 'verifying', 'ok', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/ok/expired failure/written' => array( 'failed_retryable', 'verifying', 'ok', 'expired failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/ok/expired failure/refused' => array( 'failed_retryable', 'verifying', 'ok', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/failed/none/written' => array( 'failed_retryable', 'verifying', 'failed', 'none', false, $no_child_failure, null ),
			'failed_retryable/verifying/failed/none/refused' => array( 'failed_retryable', 'verifying', 'failed', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/failed/intent/written' => array( 'failed_retryable', 'verifying', 'failed', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/failed/intent/refused' => array( 'failed_retryable', 'verifying', 'failed', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/failed/failure/written' => array( 'failed_retryable', 'verifying', 'failed', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/failed/failure/refused' => array( 'failed_retryable', 'verifying', 'failed', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/failed/retry/written' => array( 'failed_retryable', 'verifying', 'failed', 'retry', false, $no_child_failure, null ),
			'failed_retryable/verifying/failed/retry/refused' => array( 'failed_retryable', 'verifying', 'failed', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/failed/expired failure/written' => array( 'failed_retryable', 'verifying', 'failed', 'expired failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/failed/expired failure/refused' => array( 'failed_retryable', 'verifying', 'failed', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/completed/none/written' => array( 'failed_retryable', 'verifying', 'completed', 'none', false, $no_child_failure, null ),
			'failed_retryable/verifying/completed/none/refused' => array( 'failed_retryable', 'verifying', 'completed', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/completed/intent/written' => array( 'failed_retryable', 'verifying', 'completed', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/completed/intent/refused' => array( 'failed_retryable', 'verifying', 'completed', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/completed/failure/written' => array( 'failed_retryable', 'verifying', 'completed', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/completed/failure/refused' => array( 'failed_retryable', 'verifying', 'completed', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/completed/retry/written' => array( 'failed_retryable', 'verifying', 'completed', 'retry', false, $no_child_failure, null ),
			'failed_retryable/verifying/completed/retry/refused' => array( 'failed_retryable', 'verifying', 'completed', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/completed/expired failure/written' => array( 'failed_retryable', 'verifying', 'completed', 'expired failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/completed/expired failure/refused' => array( 'failed_retryable', 'verifying', 'completed', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/busy/none/written' => array( 'failed_retryable', 'verifying', 'busy', 'none', false, $no_child_failure, null ),
			'failed_retryable/verifying/busy/none/refused' => array( 'failed_retryable', 'verifying', 'busy', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/busy/intent/written' => array( 'failed_retryable', 'verifying', 'busy', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/busy/intent/refused' => array( 'failed_retryable', 'verifying', 'busy', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/busy/failure/written' => array( 'failed_retryable', 'verifying', 'busy', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/busy/failure/refused' => array( 'failed_retryable', 'verifying', 'busy', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/busy/retry/written' => array( 'failed_retryable', 'verifying', 'busy', 'retry', false, $no_child_failure, null ),
			'failed_retryable/verifying/busy/retry/refused' => array( 'failed_retryable', 'verifying', 'busy', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/busy/expired failure/written' => array( 'failed_retryable', 'verifying', 'busy', 'expired failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/busy/expired failure/refused' => array( 'failed_retryable', 'verifying', 'busy', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/rolling_back/none/written' => array( 'failed_retryable', 'verifying', 'rolling_back', 'none', false, $no_child_failure, null ),
			'failed_retryable/verifying/rolling_back/none/refused' => array( 'failed_retryable', 'verifying', 'rolling_back', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/rolling_back/intent/written' => array( 'failed_retryable', 'verifying', 'rolling_back', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/rolling_back/intent/refused' => array( 'failed_retryable', 'verifying', 'rolling_back', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/rolling_back/failure/written' => array( 'failed_retryable', 'verifying', 'rolling_back', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/rolling_back/failure/refused' => array( 'failed_retryable', 'verifying', 'rolling_back', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/rolling_back/retry/written' => array( 'failed_retryable', 'verifying', 'rolling_back', 'retry', false, $no_child_failure, null ),
			'failed_retryable/verifying/rolling_back/retry/refused' => array( 'failed_retryable', 'verifying', 'rolling_back', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/rolling_back/expired failure/written' => array( 'failed_retryable', 'verifying', 'rolling_back', 'expired failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/rolling_back/expired failure/refused' => array( 'failed_retryable', 'verifying', 'rolling_back', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/threw/none/written' => array( 'failed_retryable', 'verifying', 'threw', 'none', false, $no_child_failure, null ),
			'failed_retryable/verifying/threw/none/refused' => array( 'failed_retryable', 'verifying', 'threw', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/threw/intent/written' => array( 'failed_retryable', 'verifying', 'threw', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/threw/intent/refused' => array( 'failed_retryable', 'verifying', 'threw', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/threw/failure/written' => array( 'failed_retryable', 'verifying', 'threw', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/threw/failure/refused' => array( 'failed_retryable', 'verifying', 'threw', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/threw/retry/written' => array( 'failed_retryable', 'verifying', 'threw', 'retry', false, $no_child_failure, null ),
			'failed_retryable/verifying/threw/retry/refused' => array( 'failed_retryable', 'verifying', 'threw', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/threw/expired failure/written' => array( 'failed_retryable', 'verifying', 'threw', 'expired failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/threw/expired failure/refused' => array( 'failed_retryable', 'verifying', 'threw', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/error/none/written' => array( 'failed_retryable', 'verifying', 'error', 'none', false, $no_child_failure, null ),
			'failed_retryable/verifying/error/none/refused' => array( 'failed_retryable', 'verifying', 'error', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/error/intent/written' => array( 'failed_retryable', 'verifying', 'error', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/error/intent/refused' => array( 'failed_retryable', 'verifying', 'error', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/error/failure/written' => array( 'failed_retryable', 'verifying', 'error', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/error/failure/refused' => array( 'failed_retryable', 'verifying', 'error', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/error/retry/written' => array( 'failed_retryable', 'verifying', 'error', 'retry', false, $no_child_failure, null ),
			'failed_retryable/verifying/error/retry/refused' => array( 'failed_retryable', 'verifying', 'error', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/error/expired failure/written' => array( 'failed_retryable', 'verifying', 'error', 'expired failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/error/expired failure/refused' => array( 'failed_retryable', 'verifying', 'error', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/unknown task/none/written' => array( 'failed_retryable', 'verifying', 'unknown task', 'none', false, $no_child_failure, null ),
			'failed_retryable/verifying/unknown task/none/refused' => array( 'failed_retryable', 'verifying', 'unknown task', 'none', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/unknown task/intent/written' => array( 'failed_retryable', 'verifying', 'unknown task', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/unknown task/intent/refused' => array( 'failed_retryable', 'verifying', 'unknown task', 'intent', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'failed_retryable/verifying/unknown task/failure/written' => array( 'failed_retryable', 'verifying', 'unknown task', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/unknown task/failure/refused' => array( 'failed_retryable', 'verifying', 'unknown task', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/unknown task/retry/written' => array( 'failed_retryable', 'verifying', 'unknown task', 'retry', false, $no_child_failure, null ),
			'failed_retryable/verifying/unknown task/retry/refused' => array( 'failed_retryable', 'verifying', 'unknown task', 'retry', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_retryable/verifying/unknown task/expired failure/written' => array( 'failed_retryable', 'verifying', 'unknown task', 'expired failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/unknown task/expired failure/refused' => array( 'failed_retryable', 'verifying', 'unknown task', 'expired failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'completed//ok/none/written'                   => array( 'completed', '', 'ok', 'none', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//ok/none/refused'                   => array( 'completed', '', 'ok', 'none', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//ok/intent/written'                 => array( 'completed', '', 'ok', 'intent', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//ok/intent/refused'                 => array( 'completed', '', 'ok', 'intent', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//ok/failure/written'                => array( 'completed', '', 'ok', 'failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//ok/failure/refused'                => array( 'completed', '', 'ok', 'failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//ok/retry/written'                  => array( 'completed', '', 'ok', 'retry', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//ok/retry/refused'                  => array( 'completed', '', 'ok', 'retry', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//ok/expired failure/written'        => array( 'completed', '', 'ok', 'expired failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//ok/expired failure/refused'        => array( 'completed', '', 'ok', 'expired failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//failed/none/written'               => array( 'completed', '', 'failed', 'none', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//failed/none/refused'               => array( 'completed', '', 'failed', 'none', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//failed/intent/written'             => array( 'completed', '', 'failed', 'intent', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//failed/intent/refused'             => array( 'completed', '', 'failed', 'intent', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//failed/failure/written'            => array( 'completed', '', 'failed', 'failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//failed/failure/refused'            => array( 'completed', '', 'failed', 'failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//failed/retry/written'              => array( 'completed', '', 'failed', 'retry', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//failed/retry/refused'              => array( 'completed', '', 'failed', 'retry', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//failed/expired failure/written'    => array( 'completed', '', 'failed', 'expired failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//failed/expired failure/refused'    => array( 'completed', '', 'failed', 'expired failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//completed/none/written'            => array( 'completed', '', 'completed', 'none', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//completed/none/refused'            => array( 'completed', '', 'completed', 'none', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//completed/intent/written'          => array( 'completed', '', 'completed', 'intent', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//completed/intent/refused'          => array( 'completed', '', 'completed', 'intent', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//completed/failure/written'         => array( 'completed', '', 'completed', 'failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//completed/failure/refused'         => array( 'completed', '', 'completed', 'failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//completed/retry/written'           => array( 'completed', '', 'completed', 'retry', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//completed/retry/refused'           => array( 'completed', '', 'completed', 'retry', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//completed/expired failure/written' => array( 'completed', '', 'completed', 'expired failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//completed/expired failure/refused' => array( 'completed', '', 'completed', 'expired failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//busy/none/written'                 => array( 'completed', '', 'busy', 'none', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//busy/none/refused'                 => array( 'completed', '', 'busy', 'none', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//busy/intent/written'               => array( 'completed', '', 'busy', 'intent', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//busy/intent/refused'               => array( 'completed', '', 'busy', 'intent', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//busy/failure/written'              => array( 'completed', '', 'busy', 'failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//busy/failure/refused'              => array( 'completed', '', 'busy', 'failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//busy/retry/written'                => array( 'completed', '', 'busy', 'retry', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//busy/retry/refused'                => array( 'completed', '', 'busy', 'retry', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//busy/expired failure/written'      => array( 'completed', '', 'busy', 'expired failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//busy/expired failure/refused'      => array( 'completed', '', 'busy', 'expired failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//rolling_back/none/written'         => array( 'completed', '', 'rolling_back', 'none', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//rolling_back/none/refused'         => array( 'completed', '', 'rolling_back', 'none', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//rolling_back/intent/written'       => array( 'completed', '', 'rolling_back', 'intent', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//rolling_back/intent/refused'       => array( 'completed', '', 'rolling_back', 'intent', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//rolling_back/failure/written'      => array( 'completed', '', 'rolling_back', 'failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//rolling_back/failure/refused'      => array( 'completed', '', 'rolling_back', 'failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//rolling_back/retry/written'        => array( 'completed', '', 'rolling_back', 'retry', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//rolling_back/retry/refused'        => array( 'completed', '', 'rolling_back', 'retry', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//rolling_back/expired failure/written' => array( 'completed', '', 'rolling_back', 'expired failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//rolling_back/expired failure/refused' => array( 'completed', '', 'rolling_back', 'expired failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//threw/none/written'                => array( 'completed', '', 'threw', 'none', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//threw/none/refused'                => array( 'completed', '', 'threw', 'none', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//threw/intent/written'              => array( 'completed', '', 'threw', 'intent', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//threw/intent/refused'              => array( 'completed', '', 'threw', 'intent', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//threw/failure/written'             => array( 'completed', '', 'threw', 'failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//threw/failure/refused'             => array( 'completed', '', 'threw', 'failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//threw/retry/written'               => array( 'completed', '', 'threw', 'retry', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//threw/retry/refused'               => array( 'completed', '', 'threw', 'retry', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//threw/expired failure/written'     => array( 'completed', '', 'threw', 'expired failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//threw/expired failure/refused'     => array( 'completed', '', 'threw', 'expired failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//error/none/written'                => array( 'completed', '', 'error', 'none', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//error/none/refused'                => array( 'completed', '', 'error', 'none', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//error/intent/written'              => array( 'completed', '', 'error', 'intent', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//error/intent/refused'              => array( 'completed', '', 'error', 'intent', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//error/failure/written'             => array( 'completed', '', 'error', 'failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//error/failure/refused'             => array( 'completed', '', 'error', 'failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//error/retry/written'               => array( 'completed', '', 'error', 'retry', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//error/retry/refused'               => array( 'completed', '', 'error', 'retry', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//error/expired failure/written'     => array( 'completed', '', 'error', 'expired failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//error/expired failure/refused'     => array( 'completed', '', 'error', 'expired failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//unknown task/none/written'         => array( 'completed', '', 'unknown task', 'none', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//unknown task/none/refused'         => array( 'completed', '', 'unknown task', 'none', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//unknown task/intent/written'       => array( 'completed', '', 'unknown task', 'intent', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//unknown task/intent/refused'       => array( 'completed', '', 'unknown task', 'intent', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'completed//unknown task/failure/written'      => array( 'completed', '', 'unknown task', 'failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//unknown task/failure/refused'      => array( 'completed', '', 'unknown task', 'failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//unknown task/retry/written'        => array( 'completed', '', 'unknown task', 'retry', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//unknown task/retry/refused'        => array( 'completed', '', 'unknown task', 'retry', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//unknown task/expired failure/written' => array( 'completed', '', 'unknown task', 'expired failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'completed//unknown task/expired failure/refused' => array( 'completed', '', 'unknown task', 'expired failure', true, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//ok/none/written'            => array( 'failed_permanent', '', 'ok', 'none', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//ok/none/refused'            => array( 'failed_permanent', '', 'ok', 'none', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//ok/intent/written'          => array( 'failed_permanent', '', 'ok', 'intent', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//ok/intent/refused'          => array( 'failed_permanent', '', 'ok', 'intent', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//ok/failure/written'         => array( 'failed_permanent', '', 'ok', 'failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//ok/failure/refused'         => array( 'failed_permanent', '', 'ok', 'failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//ok/retry/written'           => array( 'failed_permanent', '', 'ok', 'retry', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//ok/retry/refused'           => array( 'failed_permanent', '', 'ok', 'retry', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//ok/expired failure/written' => array( 'failed_permanent', '', 'ok', 'expired failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//ok/expired failure/refused' => array( 'failed_permanent', '', 'ok', 'expired failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//failed/none/written'        => array( 'failed_permanent', '', 'failed', 'none', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//failed/none/refused'        => array( 'failed_permanent', '', 'failed', 'none', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//failed/intent/written'      => array( 'failed_permanent', '', 'failed', 'intent', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//failed/intent/refused'      => array( 'failed_permanent', '', 'failed', 'intent', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//failed/failure/written'     => array( 'failed_permanent', '', 'failed', 'failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//failed/failure/refused'     => array( 'failed_permanent', '', 'failed', 'failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//failed/retry/written'       => array( 'failed_permanent', '', 'failed', 'retry', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//failed/retry/refused'       => array( 'failed_permanent', '', 'failed', 'retry', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//failed/expired failure/written' => array( 'failed_permanent', '', 'failed', 'expired failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//failed/expired failure/refused' => array( 'failed_permanent', '', 'failed', 'expired failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//completed/none/written'     => array( 'failed_permanent', '', 'completed', 'none', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//completed/none/refused'     => array( 'failed_permanent', '', 'completed', 'none', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//completed/intent/written'   => array( 'failed_permanent', '', 'completed', 'intent', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//completed/intent/refused'   => array( 'failed_permanent', '', 'completed', 'intent', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//completed/failure/written'  => array( 'failed_permanent', '', 'completed', 'failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//completed/failure/refused'  => array( 'failed_permanent', '', 'completed', 'failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//completed/retry/written'    => array( 'failed_permanent', '', 'completed', 'retry', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//completed/retry/refused'    => array( 'failed_permanent', '', 'completed', 'retry', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//completed/expired failure/written' => array( 'failed_permanent', '', 'completed', 'expired failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//completed/expired failure/refused' => array( 'failed_permanent', '', 'completed', 'expired failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//busy/none/written'          => array( 'failed_permanent', '', 'busy', 'none', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//busy/none/refused'          => array( 'failed_permanent', '', 'busy', 'none', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//busy/intent/written'        => array( 'failed_permanent', '', 'busy', 'intent', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//busy/intent/refused'        => array( 'failed_permanent', '', 'busy', 'intent', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//busy/failure/written'       => array( 'failed_permanent', '', 'busy', 'failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//busy/failure/refused'       => array( 'failed_permanent', '', 'busy', 'failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//busy/retry/written'         => array( 'failed_permanent', '', 'busy', 'retry', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//busy/retry/refused'         => array( 'failed_permanent', '', 'busy', 'retry', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//busy/expired failure/written' => array( 'failed_permanent', '', 'busy', 'expired failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//busy/expired failure/refused' => array( 'failed_permanent', '', 'busy', 'expired failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//rolling_back/none/written'  => array( 'failed_permanent', '', 'rolling_back', 'none', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//rolling_back/none/refused'  => array( 'failed_permanent', '', 'rolling_back', 'none', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//rolling_back/intent/written' => array( 'failed_permanent', '', 'rolling_back', 'intent', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//rolling_back/intent/refused' => array( 'failed_permanent', '', 'rolling_back', 'intent', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//rolling_back/failure/written' => array( 'failed_permanent', '', 'rolling_back', 'failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//rolling_back/failure/refused' => array( 'failed_permanent', '', 'rolling_back', 'failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//rolling_back/retry/written' => array( 'failed_permanent', '', 'rolling_back', 'retry', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//rolling_back/retry/refused' => array( 'failed_permanent', '', 'rolling_back', 'retry', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//rolling_back/expired failure/written' => array( 'failed_permanent', '', 'rolling_back', 'expired failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//rolling_back/expired failure/refused' => array( 'failed_permanent', '', 'rolling_back', 'expired failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//threw/none/written'         => array( 'failed_permanent', '', 'threw', 'none', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//threw/none/refused'         => array( 'failed_permanent', '', 'threw', 'none', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//threw/intent/written'       => array( 'failed_permanent', '', 'threw', 'intent', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//threw/intent/refused'       => array( 'failed_permanent', '', 'threw', 'intent', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//threw/failure/written'      => array( 'failed_permanent', '', 'threw', 'failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//threw/failure/refused'      => array( 'failed_permanent', '', 'threw', 'failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//threw/retry/written'        => array( 'failed_permanent', '', 'threw', 'retry', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//threw/retry/refused'        => array( 'failed_permanent', '', 'threw', 'retry', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//threw/expired failure/written' => array( 'failed_permanent', '', 'threw', 'expired failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//threw/expired failure/refused' => array( 'failed_permanent', '', 'threw', 'expired failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//error/none/written'         => array( 'failed_permanent', '', 'error', 'none', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//error/none/refused'         => array( 'failed_permanent', '', 'error', 'none', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//error/intent/written'       => array( 'failed_permanent', '', 'error', 'intent', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//error/intent/refused'       => array( 'failed_permanent', '', 'error', 'intent', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//error/failure/written'      => array( 'failed_permanent', '', 'error', 'failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//error/failure/refused'      => array( 'failed_permanent', '', 'error', 'failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//error/retry/written'        => array( 'failed_permanent', '', 'error', 'retry', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//error/retry/refused'        => array( 'failed_permanent', '', 'error', 'retry', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//error/expired failure/written' => array( 'failed_permanent', '', 'error', 'expired failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//error/expired failure/refused' => array( 'failed_permanent', '', 'error', 'expired failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//unknown task/none/written'  => array( 'failed_permanent', '', 'unknown task', 'none', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//unknown task/none/refused'  => array( 'failed_permanent', '', 'unknown task', 'none', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//unknown task/intent/written' => array( 'failed_permanent', '', 'unknown task', 'intent', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//unknown task/intent/refused' => array( 'failed_permanent', '', 'unknown task', 'intent', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), 'throws:finished' ),
			'failed_permanent//unknown task/failure/written' => array( 'failed_permanent', '', 'unknown task', 'failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//unknown task/failure/refused' => array( 'failed_permanent', '', 'unknown task', 'failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//unknown task/retry/written' => array( 'failed_permanent', '', 'unknown task', 'retry', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//unknown task/retry/refused' => array( 'failed_permanent', '', 'unknown task', 'retry', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//unknown task/expired failure/written' => array( 'failed_permanent', '', 'unknown task', 'expired failure', false, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'failed_permanent//unknown task/expired failure/refused' => array( 'failed_permanent', '', 'unknown task', 'expired failure', true, array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//ok/none/written'                   => array( 'cancelled', '', 'ok', 'none', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//ok/none/refused'                   => array( 'cancelled', '', 'ok', 'none', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//ok/intent/written'                 => array( 'cancelled', '', 'ok', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//ok/intent/refused'                 => array( 'cancelled', '', 'ok', 'intent', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//ok/failure/written'                => array( 'cancelled', '', 'ok', 'failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//ok/failure/refused'                => array( 'cancelled', '', 'ok', 'failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//ok/retry/written'                  => array( 'cancelled', '', 'ok', 'retry', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//ok/retry/refused'                  => array( 'cancelled', '', 'ok', 'retry', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//ok/expired failure/written'        => array( 'cancelled', '', 'ok', 'expired failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//ok/expired failure/refused'        => array( 'cancelled', '', 'ok', 'expired failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//failed/none/written'               => array( 'cancelled', '', 'failed', 'none', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//failed/none/refused'               => array( 'cancelled', '', 'failed', 'none', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//failed/intent/written'             => array( 'cancelled', '', 'failed', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//failed/intent/refused'             => array( 'cancelled', '', 'failed', 'intent', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//failed/failure/written'            => array( 'cancelled', '', 'failed', 'failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//failed/failure/refused'            => array( 'cancelled', '', 'failed', 'failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//failed/retry/written'              => array( 'cancelled', '', 'failed', 'retry', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//failed/retry/refused'              => array( 'cancelled', '', 'failed', 'retry', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//failed/expired failure/written'    => array( 'cancelled', '', 'failed', 'expired failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//failed/expired failure/refused'    => array( 'cancelled', '', 'failed', 'expired failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//completed/none/written'            => array( 'cancelled', '', 'completed', 'none', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//completed/none/refused'            => array( 'cancelled', '', 'completed', 'none', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//completed/intent/written'          => array( 'cancelled', '', 'completed', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//completed/intent/refused'          => array( 'cancelled', '', 'completed', 'intent', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//completed/failure/written'         => array( 'cancelled', '', 'completed', 'failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//completed/failure/refused'         => array( 'cancelled', '', 'completed', 'failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//completed/retry/written'           => array( 'cancelled', '', 'completed', 'retry', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//completed/retry/refused'           => array( 'cancelled', '', 'completed', 'retry', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//completed/expired failure/written' => array( 'cancelled', '', 'completed', 'expired failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//completed/expired failure/refused' => array( 'cancelled', '', 'completed', 'expired failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//busy/none/written'                 => array( 'cancelled', '', 'busy', 'none', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//busy/none/refused'                 => array( 'cancelled', '', 'busy', 'none', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//busy/intent/written'               => array( 'cancelled', '', 'busy', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//busy/intent/refused'               => array( 'cancelled', '', 'busy', 'intent', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//busy/failure/written'              => array( 'cancelled', '', 'busy', 'failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//busy/failure/refused'              => array( 'cancelled', '', 'busy', 'failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//busy/retry/written'                => array( 'cancelled', '', 'busy', 'retry', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//busy/retry/refused'                => array( 'cancelled', '', 'busy', 'retry', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//busy/expired failure/written'      => array( 'cancelled', '', 'busy', 'expired failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//busy/expired failure/refused'      => array( 'cancelled', '', 'busy', 'expired failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//rolling_back/none/written'         => array( 'cancelled', '', 'rolling_back', 'none', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//rolling_back/none/refused'         => array( 'cancelled', '', 'rolling_back', 'none', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//rolling_back/intent/written'       => array( 'cancelled', '', 'rolling_back', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//rolling_back/intent/refused'       => array( 'cancelled', '', 'rolling_back', 'intent', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//rolling_back/failure/written'      => array( 'cancelled', '', 'rolling_back', 'failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//rolling_back/failure/refused'      => array( 'cancelled', '', 'rolling_back', 'failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//rolling_back/retry/written'        => array( 'cancelled', '', 'rolling_back', 'retry', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//rolling_back/retry/refused'        => array( 'cancelled', '', 'rolling_back', 'retry', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//rolling_back/expired failure/written' => array( 'cancelled', '', 'rolling_back', 'expired failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//rolling_back/expired failure/refused' => array( 'cancelled', '', 'rolling_back', 'expired failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//threw/none/written'                => array( 'cancelled', '', 'threw', 'none', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//threw/none/refused'                => array( 'cancelled', '', 'threw', 'none', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//threw/intent/written'              => array( 'cancelled', '', 'threw', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//threw/intent/refused'              => array( 'cancelled', '', 'threw', 'intent', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//threw/failure/written'             => array( 'cancelled', '', 'threw', 'failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//threw/failure/refused'             => array( 'cancelled', '', 'threw', 'failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//threw/retry/written'               => array( 'cancelled', '', 'threw', 'retry', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//threw/retry/refused'               => array( 'cancelled', '', 'threw', 'retry', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//threw/expired failure/written'     => array( 'cancelled', '', 'threw', 'expired failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//threw/expired failure/refused'     => array( 'cancelled', '', 'threw', 'expired failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//error/none/written'                => array( 'cancelled', '', 'error', 'none', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//error/none/refused'                => array( 'cancelled', '', 'error', 'none', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//error/intent/written'              => array( 'cancelled', '', 'error', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//error/intent/refused'              => array( 'cancelled', '', 'error', 'intent', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//error/failure/written'             => array( 'cancelled', '', 'error', 'failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//error/failure/refused'             => array( 'cancelled', '', 'error', 'failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//error/retry/written'               => array( 'cancelled', '', 'error', 'retry', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//error/retry/refused'               => array( 'cancelled', '', 'error', 'retry', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//error/expired failure/written'     => array( 'cancelled', '', 'error', 'expired failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//error/expired failure/refused'     => array( 'cancelled', '', 'error', 'expired failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//unknown task/none/written'         => array( 'cancelled', '', 'unknown task', 'none', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//unknown task/none/refused'         => array( 'cancelled', '', 'unknown task', 'none', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//unknown task/intent/written'       => array( 'cancelled', '', 'unknown task', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//unknown task/intent/refused'       => array( 'cancelled', '', 'unknown task', 'intent', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//unknown task/failure/written'      => array( 'cancelled', '', 'unknown task', 'failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//unknown task/failure/refused'      => array( 'cancelled', '', 'unknown task', 'failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//unknown task/retry/written'        => array( 'cancelled', '', 'unknown task', 'retry', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//unknown task/retry/refused'        => array( 'cancelled', '', 'unknown task', 'retry', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//unknown task/expired failure/written' => array( 'cancelled', '', 'unknown task', 'expired failure', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'cancelled//unknown task/expired failure/refused' => array( 'cancelled', '', 'unknown task', 'expired failure', true, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'pending//failure without probe'               => array( 'pending', '', 'threw', 'none', false, $no_child_failure, null ),
			'pending//stored failure without probe'        => array( 'pending', '', 'completed', 'failure', false, $no_child_original, null ),
			'pending//intent without probe'                => array( 'pending', '', 'threw', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'backup_task_cancel' ), true, null, null, null ), 'cancelled' ),
			'preflight//failure without probe'             => array( 'preflight', '', 'threw', 'none', false, $no_child_failure, null ),
			'preflight//stored failure without probe'      => array( 'preflight', '', 'completed', 'failure', false, $no_child_original, null ),
			'preflight//intent without probe'              => array( 'preflight', '', 'threw', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'backup_task_cancel' ), true, null, null, null ), 'cancelled' ),
			'pre_snapshot//failure without probe'          => array( 'pre_snapshot', '', 'threw', 'none', false, $no_child_failure, null ),
			'pre_snapshot//stored failure without probe'   => array( 'pre_snapshot', '', 'completed', 'failure', false, $no_child_original, null ),
			'pre_snapshot//intent without probe'           => array( 'pre_snapshot', '', 'threw', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'backup_task_cancel' ), true, null, null, null ), 'cancelled' ),
			'verifying/stored failure completion checkpoint refused' => array( 'verifying', '', 'threw', 'failure', 'checkpoint', array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'verifying//failure without probe'             => array( 'verifying', '', 'threw', 'none', false, $no_child_failure, null ),
			'verifying//stored failure without probe'      => array( 'verifying', '', 'completed', 'failure', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'verifying//intent without probe'              => array( 'verifying', '', 'threw', 'intent', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/pending/failure without probe' => array( 'failed_retryable', 'pending', 'threw', 'none', false, $no_child_failure, null ),
			'failed_retryable/pending/stored failure without probe' => array( 'failed_retryable', 'pending', 'completed', 'failure', false, $no_child_original, null ),
			'failed_retryable/pending/intent without probe' => array( 'failed_retryable', 'pending', 'threw', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'backup_task_cancel' ), true, null, null, null ), 'cancelled' ),
			'failed_retryable/preflight/failure without probe' => array( 'failed_retryable', 'preflight', 'threw', 'none', false, $no_child_failure, null ),
			'failed_retryable/preflight/stored failure without probe' => array( 'failed_retryable', 'preflight', 'completed', 'failure', false, $no_child_original, null ),
			'failed_retryable/preflight/intent without probe' => array( 'failed_retryable', 'preflight', 'threw', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'backup_task_cancel' ), true, null, null, null ), 'cancelled' ),
			'failed_retryable/pre_snapshot/failure without probe' => array( 'failed_retryable', 'pre_snapshot', 'threw', 'none', false, $no_child_failure, null ),
			'failed_retryable/pre_snapshot/stored failure without probe' => array( 'failed_retryable', 'pre_snapshot', 'completed', 'failure', false, $no_child_original, null ),
			'failed_retryable/pre_snapshot/intent without probe' => array( 'failed_retryable', 'pre_snapshot', 'threw', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'backup_task_cancel' ), true, null, null, null ), 'cancelled' ),
			'failed_retryable/verifying/failure without probe' => array( 'failed_retryable', 'verifying', 'threw', 'none', false, $no_child_failure, null ),
			'failed_retryable/verifying/stored failure without probe' => array( 'failed_retryable', 'verifying', 'completed', 'failure', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), null ),
			'failed_retryable/verifying/intent without probe' => array( 'failed_retryable', 'verifying', 'threw', 'intent', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, array(), false, null, false, null ), 'throws:finished' ),
			'retry missing resume/failure'                 => array( 'failed_retryable', null, 'threw', 'none', false, $no_child_failure, null ),
			'retry missing resume/intent'                  => array( 'failed_retryable', null, 'threw', 'intent', false, array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'backup_task_cancel' ), true, null, null, null ), 'cancelled' ),
			'running/expired user unconfirmed'             => array(
				'running',
				'',
				'threw',
				'expired intent',
				false,
				array(
					'failed_permanent',
					MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL,
					array( 'restore_task_cancel' ),
					true,
					array(
						'error'      => 'The child site did not confirm that it stopped restoring. The site may need manual cleanup.',
						'error_code' => 'mainwp_restore_cancel_unconfirmed',
					),
					null,
					100,
				),
				'cancelled',
			),
			'staging/refused before probe'                 => array( 'staging', '', 'busy', 'none', true, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $later, null ), null ),
			'retry staging/refused before probe'           => array( 'failed_retryable', 'staging', 'busy', 'failure', true, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array(), false, null, $original, null ), null ),
			'running/refused terminal after probe'         => array( 'running', '', 'ok', 'failure', 'terminal', array( 'running', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'transferring/refused completion transition'   => array( 'transferring', '', 'completed', 'failure', 'terminal', array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, $original, 1 ), null ),
			'transferring/no task'                         => array( 'transferring', '', 'busy', 'none', false, $no_child_failure, null, '' ),
			'staging/no task'                              => array( 'staging', '', 'busy', 'none', false, $no_child_failure, null, '' ),
			'running/no task'                              => array( 'running', '', 'busy', 'none', false, $no_child_failure, null, '' ),
			'verifying/refused terminal'                   => array( 'verifying', '', 'threw', 'none', true, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array(), false, null, null, null ), null ),
			'lane/pending with task ids'                   => array( 'pending', '', 'ok', 'cancel', false, $no_child_cancel, 'cancelled' ),
			'lane/preflight with task ids'                 => array( 'preflight', '', 'ok', 'cancel', false, $no_child_cancel, 'cancelled' ),
			'lane/preflight no handle'                     => array(
				'preflight',
				'',
				'ok',
				'cancel',
				false,
				$unowned_cancel,
				'cancelled',
				'task-9-restore',
				array(
					'handle'       => 'none',
					'handle_after' => 'unchanged',
				),
			),
			'lane/preflight handle before phase'           => array( 'preflight', '', 'ok', 'cancel', false, $no_child_cancel, 'cancelled', '', array( 'snapshot_task_id' => '' ) ),
			'lane/preflight foreign owner'                 => array(
				'preflight',
				'',
				'ok',
				'cancel',
				false,
				$unowned_cancel,
				'cancelled',
				'',
				array(
					'snapshot_task_id' => '',
					'handle'           => 'foreign',
					'handle_after'     => 'unchanged',
				),
			),
			'lane/preflight adopted handle'                => array(
				'preflight',
				'',
				'ok',
				'cancel',
				false,
				$unowned_cancel,
				'cancelled',
				'',
				array(
					'handle'       => 'adopted',
					'handle_after' => 'unchanged',
				),
			),
			'lane/preflight legacy handle'                 => array(
				'preflight',
				'',
				'ok',
				'cancel',
				false,
				$unowned_cancel,
				'cancelled',
				'',
				array(
					'snapshot_task_id' => '',
					'handle'           => 'legacy',
					'handle_after'     => 'unchanged',
				),
			),
			'lane/preflight child throws'                  => array(
				'preflight',
				'',
				'ok',
				'cancel',
				false,
				$no_child_cancel,
				'cancelled',
				'',
				array(
					'snapshot_task_id' => '',
					'backup_throws'    => true,
				),
			),
			'lane/preflight undecodable handle'            => array(
				'preflight',
				'',
				'ok',
				'cancel',
				false,
				$unowned_cancel,
				'cancelled',
				'',
				array(
					'snapshot_task_id' => '',
					'handle'           => 'undecodable',
					'handle_after'     => 'unchanged',
				),
			),
			'lane/staging with task ids'                   => array( 'staging', '', 'ok', 'cancel', false, $cancelled, 'cancelled' ),
			'lane/failed_retryable with task ids'          => array( 'failed_retryable', 'pre_snapshot', 'ok', 'cancel', false, $no_child_cancel, 'cancelled' ),
			'lane/failed_retryable owned handle'           => array( 'failed_retryable', 'pre_snapshot', 'ok', 'cancel', false, $no_child_cancel, 'cancelled', '' ),
			'lane/failed_retryable foreign handle'         => array(
				'failed_retryable',
				'pre_snapshot',
				'ok',
				'cancel',
				false,
				$unowned_cancel,
				'cancelled',
				'',
				array(
					'handle'       => 'foreign',
					'handle_after' => 'unchanged',
				),
			),
			'lane/transferring busy child'                 => array( 'transferring', '', 'busy', 'cancel', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'lane/running busy child'                      => array( 'running', '', 'busy', 'cancel', false, $pending_intent, 'cancel_pending' ),
			'lane/transferring completed child'            => array( 'transferring', '', 'completed', 'cancel', false, $completed, 'throws:finished' ),
			'lane/transferring failed child'               => array( 'transferring', '', 'failed', 'cancel', false, $cancelled, 'cancelled' ),
			'lane/transferring with task id'               => array(
				'transferring',
				'',
				'ok',
				'cancel',
				false,
				array( 'cancelled', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'restore_task_cancel' ), true, null, null, null ),
				'cancelled',
				'task-9-restore',
				array(
					'handle'       => 'foreign',
					'handle_after' => 'unchanged',
				),
			),
			'lane/transferring with owned handle'          => array( 'transferring', '', 'ok', 'cancel', false, $cancelled, 'cancelled' ),
			'lane/transferring child throws'               => array( 'transferring', '', 'threw', 'cancel', false, array( 'transferring', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'lane/transferring without task id'            => array( 'transferring', '', 'ok', 'cancel', false, $no_child_cancel, 'cancelled', '' ),
			'lane/pre_snapshot with task id'               => array( 'pre_snapshot', '', 'ok', 'cancel', false, $no_child_cancel, 'cancelled' ),
			'lane/pre_snapshot foreign handle'             => array(
				'pre_snapshot',
				'',
				'ok',
				'cancel',
				false,
				$unowned_cancel,
				'cancelled',
				'task-9-restore',
				array(
					'handle'       => 'foreign',
					'handle_after' => 'unchanged',
				),
			),
			'lane/pre_snapshot adopted handle'             => array(
				'pre_snapshot',
				'',
				'ok',
				'cancel',
				false,
				$unowned_cancel,
				'cancelled',
				'task-9-restore',
				array(
					'handle'       => 'adopted',
					'handle_after' => 'unchanged',
				),
			),
			'lane/pre_snapshot no handle'                  => array(
				'pre_snapshot',
				'',
				'ok',
				'cancel',
				false,
				$unowned_cancel,
				'cancelled',
				'task-9-restore',
				array(
					'handle'       => 'none',
					'handle_after' => 'unchanged',
				),
			),
			'lane/pre_snapshot legacy'                     => array(
				'pre_snapshot',
				'',
				'ok',
				'cancel',
				false,
				$unowned_cancel,
				'cancelled',
				'task-9-restore',
				array(
					'handle'       => 'legacy',
					'handle_after' => 'unchanged',
				),
			),
			'lane/pre_snapshot undecodable'                => array(
				'pre_snapshot',
				'',
				'ok',
				'cancel',
				false,
				$unowned_cancel,
				'cancelled',
				'task-9-restore',
				array(
					'handle'       => 'undecodable',
					'handle_after' => 'unchanged',
				),
			),
			'lane/pre_snapshot without task id'            => array( 'pre_snapshot', '', 'ok', 'cancel', false, $no_child_cancel, 'cancelled', 'task-9-restore', array( 'snapshot_task_id' => '' ) ),
			'lane/pre_snapshot child throws'               => array( 'pre_snapshot', '', 'ok', 'cancel', false, $no_child_cancel, 'cancelled', 'task-9-restore', array( 'backup_throws' => true ) ),
			'lane/failed_retryable running ok'             => array( 'failed_retryable', 'running', 'ok', 'cancel', false, $cancelled, 'cancelled' ),
			'lane/failed_retryable running busy'           => array( 'failed_retryable', 'running', 'busy', 'cancel', false, array( 'failed_retryable', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'lane/staging child throws'                    => array( 'staging', '', 'threw', 'cancel', false, array( 'staging', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, array( 'restore_task_cancel' ), false, null, true, 1 ), 'cancel_pending' ),
			'lane/stored failure ok'                       => array( 'running', '', 'ok', 'stored failure', false, $failed_original, 'cancelled' ),
			'lane/stored failure busy'                     => array( 'running', '', 'busy', 'stored failure', false, $pending_original, 'cancel_pending' ),
			'lane/stored failure completed'                => array( 'running', '', 'completed', 'stored failure', false, $completed, 'throws:finished' ),
			'lane/completion checkpoint refused'           => array( 'transferring', '', 'completed', 'cancel', 'checkpoint', $pending_intent, 'cancel_pending' ),

			'fenced/refused cancel write'                  => array( 'running', '', 'ok', 'cancel', 'terminal', $pending_intent, null, 'task-9-restore', array( 'lock_token' => 'holder' ) ),
			'fenced/refused failure write'                 => array( 'running', '', 'ok', 'failure', 'terminal', $pending_original, null, 'task-9-restore', array( 'lock_token' => 'holder' ) ),
			'lane/advanced to verifying during cancel'     => array( 'running', '', 'ok', 'cancel', false, array( 'verifying', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'restore_task_cancel' ), false, null, true, 1 ), 'throws:finished', 'task-9-restore', array( 'advance_to' => 'verifying' ) ),
			'lane/completed during stored failure cancel'  => array( 'running', '', 'ok', 'stored failure', false, array( 'completed', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'restore_task_cancel' ), false, null, $original, 1 ), 'throws:finished', 'task-9-restore', array( 'advance_to' => 'completed' ) ),

		);
	}

	/**
	 * Child completion during cancellation outranks the deferred failure on the next tick.
	 */
	public function test_terminal_failure_advanced_to_verifying_drives_on_next_tick() {
		$this->assert_cancellation_decision(
			'running',
			'',
			'ok',
			'none',
			false,
			array(
				'verifying',
				MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL,
				array( 'restore_task_cancel' ),
				false,
				null,
				array(
					'error'      => 'Later failure',
					'error_code' => 'later_code',
				),
				1,
			),
			null,
			'task-9-restore',
			array(
				'advance_to'  => 'verifying',
				'second_tick' => true,
			)
		);
	}

	/**
	 * The next lease holder must inherit the failure while cancellation is in flight.
	 */
	public function test_terminal_failure_survives_lease_handoff_during_cancel() {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job(
			array(),
			$store::STATUS_RUNNING,
			array(
				'child_task_id'        => 'task-9-restore',
				'pre_snapshot_task_id' => 'task-7-pre',
			)
		);
		$job   = $store->acquire_next_job( 'old-holder', 90, time(), (int) $job->id );
		$this->assertNotNull( $job );
		$handle = wp_json_encode(
			array(
				'task_id' => 'task-7-pre',
				'owner'   => (string) $job->id,
			)
		);
		MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		$driver  = MainWP_Backup_Restore_Transfer_Driver::instance();
		$staging = $driver->staging_dir( (int) $job->id );
		wp_mkdir_p( $staging );
		file_put_contents( $staging . 'backup.zip', 'staged backup' );
		$table     = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$inherited = null;
		$reclaim   = static function ( $pre, $website, $what ) use ( $wpdb, $table, $store, $job, &$inherited ) {
			if ( 'restore_task_cancel' === $what ) {
				$wpdb->update( $table, array( 'locked_until' => time() - 1 ), array( 'id' => (int) $job->id ) );
				$inherited = $store->acquire_next_job( 'new-holder', 90, time(), (int) $job->id );
			}
			return $pre;
		};
		$events    = array();
		add_action(
			'mainwp_backup_restore_failed',
			static function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);
		$this->responses['restore_task_cancel'] = array( 'result' => 'busy' );
		add_filter( 'mainwp_fetch_url_authed_pre', $reclaim, 20, 3 );
		try {
			$record = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'record_terminal_failure' );
			$this->assertSame( MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DEFERRED, $record->invoke( $this->orchestrator, $job, 'Original replay failure', 'mainwp_restore_db_replay', 'old-holder' ) );
			remove_filter( 'mainwp_fetch_url_authed_pre', $reclaim, 20 );
			$this->assertNotNull( $inherited, 'The request must cross an actual lease acquisition.' );
			$this->assertSame( 'new-holder', $inherited->locked_by );
			$this->assertSame( 1, (int) $inherited->reclaim_count );
			$this->assertTrue( $inherited->checkpoint['cancel_intent'] );
			$this->assertSame(
				array(
					'error'      => 'Original replay failure',
					'error_code' => 'mainwp_restore_db_replay',
				),
				$inherited->checkpoint['terminal_failure']
			);
			$this->assertSame( 1, $inherited->checkpoint['cancel_confirmation']['attempt_count'] );

			$next  = new Testable_Restore_Orchestrator();
			$token = new \ReflectionProperty( MainWP_Backup_Restore_Orchestrator::class, 'lock_token' );
			$token->setValue( $next, 'new-holder' );
			$this->calls                         = array();
			$this->responses['restore_task_run'] = array( 'phase' => 'completed' );
			$next->run_job( $inherited, microtime( true ) + 30 );
			$this->assertSame( array( 'restore_task_cancel' ), array_column( $this->calls, 'what' ), 'The new holder must cancel before any restore slice.' );
			$fresh = $store->get_job( (int) $job->id );
			$this->assertSame( $store::STATUS_RUNNING, $fresh->status );
			$this->assertSame( 'new-holder', $fresh->locked_by );
			$this->assertSame( $inherited->checkpoint['terminal_failure'], $fresh->checkpoint['terminal_failure'] );
			$this->assertSame( 2, $fresh->checkpoint['cancel_confirmation']['attempt_count'] );
			$this->assertFileExists( $staging . 'backup.zip' );
			$this->assertSame( $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
			$this->assertSame( array(), $events );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $reclaim, 20 );
			$driver->cleanup_staging( (int) $job->id );
		}
	}

	/**
	 * Cleanup must happen after terminalization and before observers receive the failure.
	 *
	 * @dataProvider bounded_cancellation_cleanup_cases
	 * @param bool $terminal_failure Whether the cancellation was driven by a terminal failure.
	 */
	public function test_bounded_cancellation_cleans_staging( $terminal_failure ) {
		$store      = MainWP_Backup_Restore_Job_Store::instance();
		$checkpoint = array( 'cancel_intent' => true );
		if ( $terminal_failure ) {
			$checkpoint['terminal_failure'] = array(
				'error'      => 'Original replay failure',
				'error_code' => 'mainwp_restore_db_replay',
			);
		}
		$job    = $this->make_job(
			$checkpoint,
			$store::STATUS_RUNNING,
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
		$driver  = MainWP_Backup_Restore_Transfer_Driver::instance();
		$staging = $driver->staging_dir( (int) $job->id );
		wp_mkdir_p( $staging );
		file_put_contents( $staging . 'backup.zip', 'staged backup' );
		$this->responses['restore_task_cancel'] = array( 'result' => 'rolling_back' );
		$observed                               = array();
		add_action(
			'mainwp_backup_restore_failed',
			static function () use ( &$observed, $staging, $store, $job ) {
				$observed[] = array( $store->get_job( (int) $job->id )->status, file_exists( $staging . 'backup.zip' ) );
			}
		);
		try {
			$job = $this->tick( $job );
			$this->assertFileExists( $staging . 'backup.zip', 'Rollback deferral must retain staging.' );
			$this->assertSame( $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
			$checkpoint = $job->checkpoint;
			$checkpoint['cancel_confirmation']['first_attempt_at'] = time() - $store::CANCEL_CONFIRMATION_TIMEOUT_SECONDS;
			$store->save_checkpoint( (int) $job->id, $checkpoint );
			$job = $this->tick( $store->get_job( (int) $job->id ) );
			$this->assertSame( $store::STATUS_FAILED_PERMANENT, $job->status );
			$this->assertFileDoesNotExist( $staging . 'backup.zip' );
			$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
			$this->assertSame( array( array( $store::STATUS_FAILED_PERMANENT, false ) ), $observed );
			$job = $this->tick( $job );
			$this->assertCount( 1, $observed, 'A terminal retry must not publish the failure twice.' );
		} finally {
			$driver->cleanup_staging( (int) $job->id );
		}
	}

	/**
	 * A user cancel on a job that already deferred a permanent failure must
	 * publish that failure rather than quietly ending as cancelled, or the
	 * only record of why the restore failed never reaches the logs.
	 */
	public function test_ui_cancel_publishes_a_stored_terminal_failure() {
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job(
			array(
				'cancel_intent'    => true,
				'terminal_failure' => array(
					'error'      => 'Original replay failure',
					'error_code' => 'mainwp_restore_db_replay',
				),
			),
			$store::STATUS_RUNNING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$this->responses['restore_task_cancel'] = array( 'result' => 'ok' );
		$published                              = array();
		add_action(
			'mainwp_backup_restore_failed',
			static function () use ( &$published ) {
				$published[] = true;
			}
		);

		$outcome = $this->orchestrator->cancel_job( (int) $job->id );

		$fresh = $store->get_job( (int) $job->id );
		$this->assertSame( 'cancelled', $outcome );
		$this->assertSame( $store::STATUS_FAILED_PERMANENT, $fresh->status );
		$this->assertSame( 'Original replay failure', $fresh->last_error );
		$this->assertSame( 'mainwp_restore_db_replay', $fresh->checkpoint['last_error_code'] );
		$this->assertCount( 1, $published, 'The failure event carries the original cause to the logs connector.' );
	}

	/**
	 * The request timeout cap is installed before the reclaim sweep, so a
	 * throw inside the sweep must not leave it bounding every later child
	 * request in this process.
	 */
	public function test_sweep_throw_removes_the_request_timeout_cap() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array(), $store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$this->expire_job_at_reclaim_ceiling( (int) $job->id );
		$exploded = static function () {
			throw new \RuntimeException( 'listener exploded' );
		};
		add_action( 'mainwp_backup_restore_failed', $exploded );
		$caught = null;
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} catch ( \Throwable $e ) {
			$caught = $e;
		} finally {
			remove_action( 'mainwp_backup_restore_failed', $exploded );
		}
		$this->assertNotNull( $caught, 'The sweep was expected to rethrow the listener failure.' );
		$this->assertSame( 'listener exploded', $caught->getMessage() );

		$this->assertSame(
			20 * 60 * 60,
			(int) apply_filters( 'mainwp_fetch_url_site_timeout', 20 * 60 * 60 ),
			'A throw in the sweep must not leave the restore cap on every later child request.'
		);
	}

	/**
	 * A cancellation driven by the user versus one driven by a terminal failure.
	 *
	 * @return array
	 */
	public static function bounded_cancellation_cleanup_cases() {
		return array(
			'user cancellation' => array( false ),
			'terminal failure'  => array( true ),
		);
	}

	/**
	 * Exhausted reachability retries retain the verdict after child completion.
	 *
	 * @dataProvider verification_failure_phases
	 * @param string $status Job status the exhausted retry is entered with.
	 */
	public function test_exhausted_verification_failure_never_cancels_child( $status ) {
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job(
			array( 'resume_status' => $store::STATUS_VERIFYING ),
			$status,
			array(
				'child_task_id' => 'task-9-restore',
				'attempt_count' => MainWP_Backup_Backoff::MAX_ATTEMPTS - 1,
			)
		);
		$this->orchestrator->responds           = false;
		$this->responses['restore_task_cancel'] = new MainWP_Exception( 'Site unreachable.' );
		$job                                    = $this->tick( $job );
		$this->assertSame( $store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'The site did not respond after the restore. The restore completed on the child; check the site directly.', $job->last_error );
		$this->assertSame( '', $job->checkpoint['last_error_code'] );
		$this->assertArrayNotHasKey( 'cancel_confirmation', $job->checkpoint );
		$this->assertEmpty( $this->calls );
	}

	/**
	 * Job statuses that mark an exhausted verification retry.
	 *
	 * @return array
	 */
	public static function verification_failure_phases() {
		return array(
			'verifying'             => array( 'verifying' ),
			'retrying verification' => array( 'failed_retryable' ),
		);
	}

	/**
	 * A worker dying after its first write must leave a resumable cancellation.
	 */
	public function test_terminal_deferral_is_atomic_at_worker_exit() {
		global $wpdb;
		$job                                    = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$this->responses['restore_task_cancel'] = array( 'result' => 'busy' );
		$table                                  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$interrupted                            = false;
		$interrupt                              = static function ( $sql ) use ( &$interrupt, &$interrupted, $wpdb, $table ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET checkpoint' ) && str_contains( $sql, 'terminal_failure' ) ) {
				remove_filter( 'query', $interrupt );
				$wpdb->query( $sql );
				$interrupted = true;
				throw new \RuntimeException( 'Simulated worker exit after the first write.' );
			}
			return $sql;
		};
		add_filter( 'query', $interrupt );
		try {
			$record = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'record_terminal_failure' );
			$record->invoke( $this->orchestrator, $job, 'Original replay failure', 'mainwp_restore_db_replay', null );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Simulated worker exit after the first write.', $e->getMessage() );
		} finally {
			remove_filter( 'query', $interrupt );
		}
		$this->assertTrue( $interrupted );
		$fresh = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		$this->assertSame( 'Original replay failure', $fresh->checkpoint['terminal_failure']['error'] );
		$this->assertTrue( ! empty( $fresh->checkpoint['cancel_intent'] ), 'The first write must include the cancel intent.' );
		$this->assertSame( 'Original replay failure', $fresh->last_error );
	}

	/**
	 * Failed intent writes must not escape the sweep or replace a caught failure.
	 *
	 * @dataProvider terminal_deferral_failure_lanes
	 * @param bool $sweep Whether the terminal write is driven by the reclaim sweep rather than a caught failure.
	 */
	public function test_terminal_deferral_write_refusal_does_not_abort_worker( $sweep ) {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job(
			array(),
			$store::STATUS_RUNNING,
			array(
				'child_task_id' => 'task-9-restore',
				'attempt_count' => MainWP_Backup_Backoff::MAX_ATTEMPTS - 1,
			)
		);
		$other = $store->create_job( $this->create_test_site(), 5, array() );
		if ( $sweep ) {
			$this->expire_job_at_reclaim_ceiling( (int) $job->id );
			$this->expire_job_at_reclaim_ceiling( $other );
		} else {
			$this->responses['restore_task_run'] = new MainWP_Exception( 'Original replay failure', '', 'mainwp_restore_db_replay' );
		}
		$this->responses['restore_task_cancel'] = array( 'result' => 'busy' );
		$table                                  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$refuse                                 = static function ( $sql ) use ( $table ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET checkpoint' ) && str_contains( $sql, 'last_error = ' ) ) {
				return 'SELECT 1 WHERE 0 = 1';
			}
			return $sql;
		};
		$caught                                 = null;
		add_filter( 'query', $refuse );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30 );
		} catch ( \Throwable $e ) {
			$caught = $e->getMessage();
		} finally {
			remove_filter( 'query', $refuse );
		}
		$this->assertNull( $caught, 'A refused cancellation write must remain retryable by the next tick.' );
		$this->assertSame( $store::STATUS_RUNNING, $store->get_job( (int) $job->id )->status );
		$this->assertSame( $sweep ? $store::STATUS_FAILED_PERMANENT : $store::STATUS_PENDING, $store->get_job( $other )->status );
		$fresh = $store->get_job( (int) $job->id );
		$this->assertTrue( $fresh->checkpoint['cancel_intent'], 'The refused combined write must still leave a durable cancellation gate.' );
		$this->calls                         = array();
		$this->responses['restore_task_run'] = array( 'phase' => 'completed' );
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$this->assertSame( 'restore_task_cancel', $this->calls[0]['what'], 'The first resumed child call must cancel, even if a restore slice could now succeed.' );
		$this->assertNotContains( 'restore_task_run', array_column( $this->calls, 'what' ) );
		$fresh = $store->get_job( (int) $job->id );
		$this->assertTrue( $fresh->checkpoint['cancel_intent'] );
		$this->assertStringContainsString( $sweep ? 'Reclaimed' : 'Original replay failure', $fresh->last_error );
	}

	/**
	 * A terminal write driven by the reclaim sweep versus one driven by a caught failure.
	 *
	 * @return array
	 */
	public static function terminal_deferral_failure_lanes() {
		return array(
			'expired lease sweep'  => array( true ),
			'caught phase failure' => array( false ),
		);
	}

	/**
	 * Browser polling cannot consume the rollback window; sparse ticks still expire it.
	 *
	 * @dataProvider unconfirmed_cancellation_cases
	 * @param string $answer           Child cancellation outcome.
	 * @param bool   $sweep            Whether the terminal write is driven by the reclaim sweep.
	 * @param bool   $terminal_failure Whether a caught failure is already pending in the checkpoint.
	 */
	public function test_unconfirmed_cancellation_attempts_are_bounded( $answer, $sweep, $terminal_failure ) {
		$store      = MainWP_Backup_Restore_Job_Store::instance();
		$checkpoint = array( 'cancel_intent' => true );
		if ( $terminal_failure ) {
			$checkpoint['terminal_failure'] = array(
				'error'      => 'Original replay failure',
				'error_code' => 'mainwp_restore_db_replay',
			);
		}
		$job = $this->make_job( $checkpoint, $store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		if ( $sweep ) {
			$this->expire_job_at_reclaim_ceiling( (int) $job->id );
		}
		$this->responses['restore_task_cancel'] = 'unreachable' === $answer ? new MainWP_Exception( 'Site unreachable.' ) : array( 'result' => $answer );
		$events                                 = array();
		add_action(
			'mainwp_backup_restore_failed',
			static function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);
		$first_attempt_at = null;
		$ticks            = 36;
		for ( $attempt = 1; $attempt <= $ticks; ++$attempt ) {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
			$fresh = $store->get_job( (int) $job->id );
			$this->assertSame( $attempt, $fresh->checkpoint['cancel_confirmation']['attempt_count'] );
			$first_attempt_at = $first_attempt_at ?? $fresh->checkpoint['cancel_confirmation']['first_attempt_at'];
			$this->assertSame( $first_attempt_at, $fresh->checkpoint['cancel_confirmation']['first_attempt_at'] );
			$this->assertSame( $store::STATUS_RUNNING, $fresh->status, 'Polls inside one lease window must not terminalize the job.' );
			$this->assertEmpty( $events );
		}
		$original   = $terminal_failure ? 'Original replay failure' : ( $sweep ? $fresh->last_error : null );
		$checkpoint = $fresh->checkpoint;
		$checkpoint['cancel_confirmation']['first_attempt_at'] = time() - $store::CANCEL_CONFIRMATION_TIMEOUT_SECONDS;
		$store->save_checkpoint( (int) $job->id, $checkpoint );
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$fresh = $store->get_job( (int) $job->id );
		++$ticks;
		$warning        = 'The child site did not confirm that it stopped restoring. The site may need manual cleanup.';
		$expected_error = null === $original ? $warning : $original . ' ' . $warning;
		$expected_code  = $terminal_failure ? 'mainwp_restore_db_replay' : ( $sweep ? '' : 'mainwp_restore_cancel_unconfirmed' );
		$this->assertSame( $store::STATUS_FAILED_PERMANENT, $fresh->status );
		$this->assertSame( $expected_code, $fresh->checkpoint['last_error_code'] );
		$this->assertSame( $expected_error, $fresh->last_error );
		$this->assertCount( 1, $events );
		$this->assertSame( $expected_code, $events[0]['error_code'] );
		$this->assertSame( $expected_error, $events[0]['error'] );
		$this->assertSame( array_fill( 0, $ticks, 'restore_task_cancel' ), array_column( $this->calls, 'what' ) );
	}

	/**
	 * Cancellation outcomes that must exhaust the unconfirmed-attempt window.
	 *
	 * @return array
	 */
	public static function unconfirmed_cancellation_cases() {
		return array(
			'busy failure'        => array( 'busy', false, true ),
			'unreachable failure' => array( 'unreachable', false, true ),
			'rollback failure'    => array( 'rolling_back', false, true ),
			'busy sweep'          => array( 'busy', true, false ),
			'unreachable sweep'   => array( 'unreachable', true, false ),
			'user cancellation'   => array( 'busy', false, false ),
		);
	}

	/**
	 * A sparse cron must also stop retrying after the elapsed-time limit.
	 */
	public function test_unconfirmed_cancellation_elapsed_time_is_bounded() {
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job(
			array(
				'cancel_intent'       => true,
				'terminal_failure'    => array(
					'error'      => 'Original replay failure',
					'error_code' => 'mainwp_restore_db_replay',
				),
				'cancel_confirmation' => array(
					'attempt_count'    => 1,
					'first_attempt_at' => time() - 15 * MINUTE_IN_SECONDS,
				),
			),
			$store::STATUS_RUNNING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$this->responses['restore_task_cancel'] = array( 'result' => 'busy' );
		$job                                    = $this->tick( $job );
		$this->assertSame( $store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 2, $job->checkpoint['cancel_confirmation']['attempt_count'] );
		$this->assertSame( 'mainwp_restore_db_replay', $job->checkpoint['last_error_code'] );
	}

	/**
	 * A valid rollback reply must keep both UI and cron cancellation pending.
	 */
	public function test_rolling_back_cancellation_remains_pending_until_terminal() {
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job( array(), $store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$this->responses['restore_task_cancel'] = array(
			'result' => 'rolling_back',
			'phase'  => 'fk_rollback',
		);
		$request                                = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'request_child_cancel' );
		$this->assertSame( 'rolling_back', $request->invoke( $this->orchestrator, $job ) );
		$this->assertSame( 'cancel_pending', $this->orchestrator->cancel_job( (int) $job->id ) );
		$job = $this->tick( $job );
		$this->assertSame( $store::STATUS_RUNNING, $job->status );
		$this->assertTrue( $job->checkpoint['cancel_intent'] );
		$this->assertArrayNotHasKey( 'terminal_failure', $job->checkpoint );
		$this->assertSame( 2, $job->checkpoint['cancel_confirmation']['attempt_count'] );
		$this->responses['restore_task_cancel'] = array(
			'result' => 'already_terminal',
			'phase'  => 'failed',
		);
		$job                                    = $this->tick( $job );
		$this->assertSame( $store::STATUS_CANCELLED, $job->status );
		$this->assertSame( array_fill( 0, 4, 'restore_task_cancel' ), array_column( $this->calls, 'what' ) );
	}

	/**
	 * A busy child must stop before the failure releases its safety backup.
	 *
	 * @dataProvider terminal_failure_cancellation_cases
	 * @param string $answer Child cancellation outcome.
	 * @param string $child_task_id Child task id, or empty before task creation.
	 */
	public function test_terminal_failure_cancels_child_before_finishing( $answer, $child_task_id ) {
		$job    = $this->make_job(
			array(),
			MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
			array(
				'child_task_id'        => $child_task_id,
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
		$this->responses['restore_task_cancel'] = 'unreachable' === $answer
			? new MainWP_Exception( 'Site unreachable.' )
			: array(
				'result' => $answer,
				'phase'  => 'completed' === $answer ? 'completed' : 'failed',
			);
		if ( 'completed' === $answer ) {
			$this->responses['restore_task_cancel']['result'] = 'already_terminal';
		}
		if ( 'error' === $answer ) {
			$this->responses['restore_task_cancel'] = array( 'error' => 'The child could not cancel its restore.' );
		}
		$events = array();
		$logs   = array();
		add_action(
			'mainwp_backup_restore_failed',
			function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);
		add_action(
			'mainwp_log_info',
			function ( $message ) use ( &$logs ) {
				$logs[] = $message;
			}
		);

		$record = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'record_terminal_failure' );
		$record->invoke( $this->orchestrator, $job, 'Replay failed on table posts', 'mainwp_restore_db_replay', null );
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $store->get_job( (int) $job->id );

		$this->assertSame( '' === $child_task_id ? array() : array( 'restore_task_cancel' ), array_column( $this->calls, 'what' ) );
		if ( '' !== $child_task_id ) {
			$this->assertSame( $child_task_id, $this->calls[0]['params']['task_id'] );
		}
		// A child that answered busy or rolling_back may still be writing, and
		// a request that threw proves nothing either way, so all four defer.
		if ( in_array( $answer, array( 'busy', 'rolling_back', 'error', 'unreachable' ), true ) ) {
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $job->status );
			$this->assertTrue( $job->checkpoint['cancel_intent'] );
			$this->assertSame(
				array(
					'error'      => 'Replay failed on table posts',
					'error_code' => 'mainwp_restore_db_replay',
				),
				$job->checkpoint['terminal_failure']
			);
			$this->assertSame( $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
			$this->assertEmpty( $events );

			$this->responses['restore_task_cancel'] = array( 'result' => 'ok' );
			$this->calls                            = array();
			$job                                    = $this->tick( $job );
			$this->assertSame( array( 'restore_task_cancel' ), array_column( $this->calls, 'what' ), 'Applying the failure must not issue a second cancellation or resume the restore.' );
		}
		if ( 'unreachable' === $answer ) {
			$this->assertStringContainsString( 'did not confirm cancelling its child task', implode( "\n", $logs ) );
		}
		if ( 'completed' === $answer ) {
			// The child is the authority on whether the restore happened, so a
			// completed answer outranks the failure the Dashboard had pending.
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $job->status );
			$this->assertArrayNotHasKey( 'terminal_failure', $job->checkpoint );
			$this->assertArrayNotHasKey( 'cancel_intent', $job->checkpoint );
			$this->assertSame( $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
			$this->assertEmpty( $events );
			return;
		}

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'Replay failed on table posts', $job->last_error );
		$this->assertSame( 'mainwp_restore_db_replay', $job->checkpoint['last_error_code'] );
		$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
		$this->assertCount( 1, $events );
		$this->assertSame( 'Replay failed on table posts', $events[0]['error'] );
		$this->assertSame( 'mainwp_restore_db_replay', $events[0]['error_code'] );
	}

	/**
	 * Unconfirmed cancellations keep the original failure pending.
	 *
	 * @return array
	 */
	public static function terminal_failure_cancellation_cases() {
		return array(
			'confirmed'         => array( 'ok', 'task-9-restore' ),
			'already failed'    => array( 'already_terminal', 'task-9-restore' ),
			'busy'              => array( 'busy', 'task-9-restore' ),
			'rolling back'      => array( 'rolling_back', 'task-9-restore' ),
			'child error'       => array( 'error', 'task-9-restore' ),
			'already completed' => array( 'completed', 'task-9-restore' ),
			'unreachable'       => array( 'unreachable', 'task-9-restore' ),
			'no child task'     => array( 'ok', '' ),
		);
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
	 * Stored safety-backup errors reach the restore modal and must not expose its secret directory.
	 */
	public function test_pre_snapshot_failure_redacts_backup_directory_in_last_error() {
		$this->responses['backup_task_run'] = array(
			'task_id' => 'task-7-pre',
			'phase'   => 'failed',
			'error'   => 'Unable to create directory wp-content/uploads/mainwp/backup-tnQjEHx99TA2ZYTh/. Is its parent directory writable by the server?',
		);
		$job                                = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => 'task-7-pre' ) );
		$job                                = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertStringContainsString( 'backup-[redacted]', $job->last_error );
		$this->assertStringNotContainsString( 'tnQjEHx99TA2ZYTh', $job->last_error );
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
	 * Restore refuses a child that reported no create-idempotency support.
	 */
	public function test_initiate_restore_refuses_reported_missing_create_idempotency() {
		$this->set_site_option( $this->site_id, 'restore_create_idempotency_support', '0' );

		// Without a scripted preflight response, the fetch fails and the stored '0' stands.
		try {
			$this->orchestrator->initiate_restore( $this->site_id, 5 );
			$this->fail( 'A reported lack of create idempotency must refuse restore.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'The MainWP Child plugin on this site is too old to restore a backup safely. Update MainWP Child on the site, then run the restore again.', $e->getMessage() );
			$this->assertSame( 'mainwp_restore_child_update_required', $e->get_message_error_code() );
		}

		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id ) );
	}

	/** Ordinary requests retain the existing admin-ajax and root fallback. */
	public function test_non_probe_transport_keeps_existing_fallback() {
		$website                                        = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		Capability_Probe_Test_Transport::$now           = 100.0;
		Capability_Probe_Test_Transport::$durations     = array( 0.1, 0.1 );
		Capability_Probe_Test_Transport::$attempts      = array();
		Capability_Probe_Test_Transport::$root_succeeds = true;
		Capability_Probe_Test_Transport::fetch_url( $website, $website->url, array() );
		$this->assertSame(
			array( array( rtrim( $website->url, '/' ) . '/wp-admin/admin-ajax.php', 72000 ), array( $website->url, 72000 ) ),
			Capability_Probe_Test_Transport::$attempts
		);
	}

	/**
	 * Spend one deadline across actual transport attempts, keeping the clamp scoped to the probe.
	 *
	 * @dataProvider capability_probe_failures
	 * @param float $elapsed First failure duration.
	 * @param bool  $root_succeeds Whether the site-root fallback succeeds.
	 */
	public function test_capability_probe_retry_matrix( $elapsed, $root_succeeds ) {
		$this->set_site_option( $this->site_id, 'restore_create_idempotency_support', '0' );
		$this->set_site_option( $this->site_id, 'incremental_support', '1' );
		$this->responses['backup_preflight']            = array();
		$website                                        = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		Capability_Probe_Test_Transport::$now           = 100.0;
		Capability_Probe_Test_Transport::$durations     = array( $elapsed, $root_succeeds ? 0.1 : 30.0 );
		Capability_Probe_Test_Transport::$attempts      = array();
		Capability_Probe_Test_Transport::$root_succeeds = $root_succeeds;
		$retries                                        = array();
		$observe                                        = static function ( $pre, $site, $what, $params, $verify_signature, $retry_failed, $options = array() ) use ( &$retries ) {
			if ( 'backup_preflight' !== $what ) {
				return $pre;
			}
			$retries[] = $retry_failed;
			return Capability_Probe_Test_Transport::fetch_url( $site, $site->url, $params, false, null, $retry_failed, null, null, 0, $options );
		};
		$offer = static function () {
			return 72000;
		};
		add_filter( 'mainwp_fetch_url_site_timeout', $offer, 10 );
		add_filter( 'mainwp_fetch_url_authed_pre', $observe, 20, 7 );
		try {
			Capability_Probe_Test_Monitor::refresh_negotiated_capabilities( $website );
			$this->assertLessThanOrEqual( 30.0, Capability_Probe_Test_Transport::$now - 100.0, 'All actual attempt durations share one budget.' );
			$fast     = floor( 30 - $elapsed ) >= 20;
			$admin    = rtrim( $website->url, '/' ) . '/wp-admin/admin-ajax.php';
			$attempts = array( array( $admin, 30 ) );
			if ( $fast ) {
				$attempts[] = array( $website->url, (int) floor( 30 - $elapsed ) );
			}
			$this->assertSame( $fast ? array( false, false ) : array( false ), $retries );
			$this->assertSame( $attempts, Capability_Probe_Test_Transport::$attempts );
			$this->assertSame( 30, MainWP_Backup_Monitor::CAPABILITY_PROBE_TIMEOUT_SECONDS );
			$this->assertSame( 72000, apply_filters( 'mainwp_fetch_url_site_timeout', 72000 ), 'Remove the clamp after success and failure.' );
			$this->assertSame( $fast && $root_succeeds ? '1' : '0', MainWP_DB::instance()->get_website_option( $this->site_id, 'restore_create_idempotency_support' ) );
			if ( ! $fast || ! $root_succeeds ) {
				$this->assertSame( '1', MainWP_DB::instance()->get_website_option( $this->site_id, 'incremental_support' ), 'A failed probe must preserve every stored flag.' );
			}
			foreach ( Capability_Probe_Test_Transport::$attempts as $attempt ) {
				$this->assertGreaterThanOrEqual( 20, $attempt[1], 'Never race the PHP timer rearm.' );
			}
			$this->assertSame( 72000, apply_filters( 'mainwp_fetch_url_site_timeout', 72000, 'ordinary_request' ) );
			$this->assertCount( $fast ? 2 : 1, $this->calls );
			foreach ( $this->calls as $call ) {
				$this->assertSame( 'backup_preflight', $call['what'] );
				$this->assertSame( array( 'capabilities_only' => 1 ), $call['params'] );
			}
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $observe, 20 );
			remove_filter( 'mainwp_fetch_url_site_timeout', $offer, 10 );
			Capability_Probe_Test_Monitor::$times = array();
		}
	}

	/**
	 * A root attempt requires at least twenty seconds remaining.
	 *
	 * @return array
	 */
	public static function capability_probe_failures() {
		return array(
			'fast refusal then root succeeds' => array( 0.1, true ),
			'fast refusal then root fails'    => array( 0.1, false ),
			'just below retry cutoff'         => array( 4.999, true ),
			'five second failure'             => array( 5.0, true ),
			'exact minimum fallback budget'   => array( 10.0, false ),
			'below minimum fallback budget'   => array( 10.001, false ),
			'stalled child timeout'           => array( 30.0, false ),
		);
	}

	/**
	 * A live child update replaces a stale refusal before restore starts.
	 */
	public function test_initiate_restore_refreshes_stale_create_idempotency() {
		$this->set_site_option( $this->site_id, 'restore_create_idempotency_support', '0' );
		$this->responses['backup_preflight'] = array( 'restore_create_idempotency' => 1 );

		$snapshot_id = $this->make_chain_snapshot(
			null,
			'db',
			array( array( 'file' => 'db.zip', 'role' => 'db' ) ) // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
		);
		$job_id      = $this->orchestrator->initiate_restore( $this->site_id, $snapshot_id );
		$job         = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id );

		$this->assertGreaterThan( 0, $job_id );
		$this->assertSame( 'pending', $job->status );
		$this->assertSame( '1', MainWP_DB::instance()->get_website_option( $this->site_id, 'restore_create_idempotency_support' ) );
		$this->assertSame( array( 'backup_preflight' ), array_column( $this->calls, 'what' ) );
	}

	/**
	 * A live report without create idempotency confirms the refusal.
	 */
	public function test_initiate_restore_refuses_confirmed_missing_create_idempotency() {
		$this->set_site_option( $this->site_id, 'restore_create_idempotency_support', '0' );
		$this->responses['backup_preflight'] = array( 'task_spine' => 1 );

		try {
			$this->orchestrator->initiate_restore( $this->site_id, 5 );
			$this->fail( 'A live report without create idempotency must refuse restore.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'The MainWP Child plugin on this site is too old to restore a backup safely. Update MainWP Child on the site, then run the restore again.', $e->getMessage() );
			$this->assertSame( 'mainwp_restore_child_update_required', $e->get_message_error_code() );
		}

		$this->assertSame( '0', MainWP_DB::instance()->get_website_option( $this->site_id, 'restore_create_idempotency_support' ) );
		$this->assertSame( array( 'backup_preflight' ), array_column( $this->calls, 'what' ) );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id ) );
	}

	/**
	 * A capability that has never been negotiated does not block restore.
	 */
	public function test_initiate_restore_allows_unknown_create_idempotency() {
		// No scripted preflight response is needed: a failed fetch leaves unknown support unchanged.
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'mainwp_wp_options',
			array(
				'wpid' => $this->site_id,
				'name' => 'restore_create_idempotency_support',
			)
		);
		$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, 'restore_create_idempotency_support' ) );

		$snapshot_id = $this->make_chain_snapshot(
			null,
			'db',
			array( array( 'file' => 'db.zip', 'role' => 'db' ) ) // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
		);
		$job_id      = $this->orchestrator->initiate_restore( $this->site_id, $snapshot_id );
		$job         = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id );

		$this->assertGreaterThan( 0, $job_id );
		$this->assertSame( 'pending', $job->status );
		$this->assertSame( 'db', $job->checkpoint['sub_type'] );
	}

	/**
	 * Rollback remains available when create-idempotency support is absent.
	 */
	public function test_rollback_allows_reported_missing_create_idempotency() {
		$this->set_site_option( $this->site_id, 'restore_create_idempotency_support', '0' );
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

		$this->assertGreaterThan( 0, $job_id );
		$this->assertSame( 'pending', $job->status );
		$this->assertTrue( $job->checkpoint['plan'][0]['adopt_only'] );
	}

	/**
	 * Reload the snapshots list without any popup job state.
	 *
	 * @return array Decoded list response.
	 */
	private function b194_reload_snapshots() {
		$nonce                = wp_create_nonce( 'mainwp_restore_snapshot_list' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['site_id']     = (string) $this->site_id;
		try {
			return $this->invoke_ajax_handler( 'mainwp_restore_snapshot_list' );
		} finally {
			unset( $_POST['security'], $_REQUEST['security'], $_POST['site_id'] );
		}
	}

	/**
	 * Old failures must not make a reload response grow without a bound.
	 */
	public function test_b194_failed_restore_list_limits_to_twenty_newest_jobs() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$ids = array();
		for ( $index = 0; $index < 25; ++$index ) {
			$job   = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT );
			$ids[] = (int) $job->id;
		}

		$response = $this->b194_reload_snapshots();
		$this->assertCount( 20, $response['failed_restore_jobs'] );
		$this->assertSame( array_slice( array_reverse( $ids ), 0, 20 ), array_column( $response['failed_restore_jobs'], 'job_id' ) );
	}

	/**
	 * Later failures without a safety backup must not push the only Roll Back out of the list.
	 */
	public function test_b194_failed_restore_list_keeps_an_older_rollback_source() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$source = $this->make_job(
			array( 'pre_snapshot_artifacts' => array( array( 'file' => 'backup-pre.tar.gz' ) ) ),
			MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT
		);
		$ids    = array();
		for ( $index = 0; $index < 21; ++$index ) {
			$job   = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED );
			$ids[] = (int) $job->id;
		}

		$response = $this->b194_reload_snapshots();
		$expected = array_merge( array_slice( array_reverse( $ids ), 0, 19 ), array( (int) $source->id ) );
		$this->assertSame( $expected, array_column( $response['failed_restore_jobs'], 'job_id' ) );
		$this->assertTrue( $response['failed_restore_jobs'][19]['has_rollback'] );
	}

	/**
	 * A fresh request lists this site's failures and their rollback availability.
	 */
	public function test_b194_failed_restore_is_listed_after_reload() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store     = MainWP_Backup_Restore_Job_Store::instance();
		$artifacts = array(
			array(
				'file' => 'backup-pre.tar.gz',
				'size' => 1234,
				'role' => 'full',
			),
		);
		$failed    = $this->make_job(
			array( 'pre_snapshot_artifacts' => $artifacts ),
			$store::STATUS_FAILED_PERMANENT,
			array( 'last_error' => 'Replay failed on table posts' )
		);
		$no_source = $this->make_job( array(), $store::STATUS_FAILED_PERMANENT );
		$foreign   = $store->create_job( $this->create_test_site(), 5, array( 'pre_snapshot_artifacts' => $artifacts ) );
		$this->assertTrue( $store->fail_permanent( $foreign, 'Foreign failure' ) );

		$response = $this->b194_reload_snapshots();
		$this->assertArrayHasKey( 'failed_restore_jobs', $response, 'Reload must fetch failed jobs from storage.' );
		$rows = array_column( $response['failed_restore_jobs'], null, 'job_id' );
		$this->assertCount( 2, $rows, 'Failures must be scoped to the requested site.' );
		$this->assertSame( 5, $rows[ $failed->id ]['snapshot_id'] );
		$this->assertSame( $store::STATUS_FAILED_PERMANENT, $rows[ $failed->id ]['status'] );
		$this->assertSame( 'Replay failed on table posts', $rows[ $failed->id ]['last_error'] );
		$this->assertTrue( $rows[ $failed->id ]['has_rollback'] );
		$this->assertFalse( $rows[ $no_source->id ]['has_rollback'] );
		$this->assertSame( 0, $response['active_restore_job_id'] );
	}

	/**
	 * A failed rollback retains the safety artifacts and can start another attempt.
	 */
	public function test_b194_failed_rollback_keeps_artifacts_and_can_retry() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store     = MainWP_Backup_Restore_Job_Store::instance();
		$artifacts = array(
			array(
				'file' => 'backup-pre.tar.gz',
				'size' => 1234,
				'role' => 'full',
			),
		);
		$failed    = $this->make_job( array( 'pre_snapshot_artifacts' => $artifacts ), $store::STATUS_FAILED_PERMANENT );
		$id        = $this->orchestrator->initiate_rollback( $failed );
		$this->assertTrue( $store->set_status( $id, $store::STATUS_RUNNING, array( 'child_task_id' => 'task-rollback' ) ) );
		$this->responses['restore_task_run'] = array(
			'task_id'   => 'task-rollback',
			'phase'     => 'failed',
			'error'     => 'Rollback replay failed',
			'sub_state' => array( 'error' => array( 'message' => 'Rollback replay failed' ) ),
		);

		$rollback = $this->tick( $store->get_job( $id ) );
		$this->assertSame( $store::STATUS_FAILED_PERMANENT, $rollback->status );
		$this->assertSame( $artifacts, $rollback->checkpoint['pre_snapshot_artifacts'] ?? array(), 'The failure path must keep the durable rollback source.' );
		$response = $this->b194_reload_snapshots();
		$rows     = array_column( $response['failed_restore_jobs'], null, 'job_id' );
		$this->assertTrue( $rows[ $id ]['has_rollback'] );
		$this->assertFalse( $rows[ $failed->id ]['has_rollback'], 'Retry belongs to the failed rollback, not its earlier attempt.' );

		$retry_id = $this->orchestrator->initiate_rollback( $store->get_job( $id ) );
		$retry    = $store->get_job( $retry_id );
		$this->assertGreaterThan( $id, $retry_id );
		$this->assertSame( $artifacts, $retry->checkpoint['pre_snapshot_artifacts'] );
		$this->assertTrue( $retry->checkpoint['plan'][0]['adopt_only'] );
		$this->assertSame( 'backup-pre.tar.gz', $retry->checkpoint['plan'][0]['file'] );
	}

	/**
	 * Unrelated restores must not load their checkpoints for rollback availability.
	 */
	public function test_b194_rollback_source_fetches_only_later_rollback_candidates() {
		global $wpdb;
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$failed = $this->make_job(
			array( 'pre_snapshot_artifacts' => array( array( 'file' => 'backup-pre.tar.gz' ) ) ),
			$store::STATUS_FAILED_PERMANENT
		);
		for ( $index = 0; $index < 25; ++$index ) {
			$this->make_job( array(), $store::STATUS_FAILED_PERMANENT );
		}
		$this->make_job( array( 'rollback_of' => (int) $failed->id + 10000 ), $store::STATUS_FAILED_PERMANENT );

		$this->assertTrue( $this->orchestrator->has_rollback_source( $failed ), 'Another job\'s rollback must not consume this source.' );
		$this->assertCount( 1, $wpdb->last_result, 'Only rollback candidates should cross the DB boundary.' );

		$this->make_job( array( 'rollback_of' => (int) $failed->id ), $store::STATUS_FAILED_PERMANENT );
		$this->assertFalse( $this->orchestrator->has_rollback_source( $failed ), 'A matching rollback must consume this source.' );
		$this->assertCount( 2, $wpdb->last_result );
	}

	/**
	 * Successful rollback removes both its own and its predecessor's actions.
	 */
	public function test_b194_successful_rollback_is_not_offered_again() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store     = MainWP_Backup_Restore_Job_Store::instance();
		$artifacts = array(
			array(
				'file' => 'backup-pre.tar.gz',
				'size' => 1234,
				'role' => 'full',
			),
		);
		$failed    = $this->make_job( array( 'pre_snapshot_artifacts' => $artifacts ), $store::STATUS_FAILED_PERMANENT );
		$id        = $this->orchestrator->initiate_rollback( $failed );
		$this->assertTrue( $store->set_status( $id, $store::STATUS_VERIFYING ) );
		$rollback = $this->tick( $store->get_job( $id ) );
		$this->assertSame( $store::STATUS_COMPLETED, $rollback->status );

		$response = $this->b194_reload_snapshots();
		$this->assertArrayHasKey( 'failed_restore_jobs', $response );
		$rows = array_column( $response['failed_restore_jobs'], null, 'job_id' );
		$this->assertArrayNotHasKey( $id, $rows );
		$this->assertFalse( $rows[ $failed->id ]['has_rollback'], 'A completed rollback must not revive its predecessor.' );
		foreach ( array( $failed, $rollback ) as $job ) {
			$nonce                = wp_create_nonce( 'mainwp_restore_progress' );
			$_POST['security']    = $nonce;
			$_REQUEST['security'] = $nonce;
			$_POST['job_id']      = (string) $job->id;
			try {
				$progress = $this->invoke_ajax_handler( 'mainwp_restore_progress' );
			} finally {
				unset( $_POST['security'], $_REQUEST['security'], $_POST['job_id'] );
			}
			$this->assertFalse( $progress['has_rollback'] );
		}
		$this->expectException( MainWP_Exception::class );
		$this->orchestrator->initiate_rollback( $failed );
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
		$this->assertTrue( $job->checkpoint['is_rollback'] );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$skip_drive           = static fn() => -1;
		add_filter( 'mainwp_restore_foreground_budget', $skip_drive );
		$nonce                = wp_create_nonce( 'mainwp_restore_progress' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['job_id']      = (string) $job_id;
		try {
			$response = $this->invoke_ajax_handler( 'mainwp_restore_progress' );
			$this->assertTrue( $response['is_rollback'] );
		} finally {
			remove_filter( 'mainwp_restore_foreground_budget', $skip_drive );
			unset( $_POST['security'], $_REQUEST['security'], $_POST['job_id'] );
		}
	}

	/**
	 * Retained safety artifacts must not enable another file rollback during a rollback.
	 *
	 * @dataProvider safety_artifact_rollback_cases
	 * @param bool $rollback Whether this task rolls back a failed restore.
	 */
	public function test_child_task_file_rollback_with_safety_artifacts( $rollback ) {
		$store      = MainWP_Backup_Restore_Job_Store::instance();
		$checkpoint = array(
			'pre_snapshot_artifacts' => array(
				array(
					'file' => 'backup-pre.tar.gz',
					'size' => 1234,
					'role' => 'full',
				),
			),
		);
		if ( $rollback ) {
			$failed = $this->make_job( $checkpoint, $store::STATUS_FAILED_PERMANENT );
			$id     = $this->orchestrator->initiate_rollback( $failed );
			$this->assertTrue( $store->set_status( $id, $store::STATUS_STAGING ) );
			$job = $store->get_job( $id );
		} else {
			$job = $this->make_job( $checkpoint, $store::STATUS_STAGING );
		}
		$this->assertFalse( $job->checkpoint['options']['file_rollback'] );
		$this->responses['restore_task_create'] = array(
			'task_id' => 'safety-artifacts-task',
			'phase'   => 'queued',
		);

		$job = $this->tick( $job );
		$this->assertSame( $store::STATUS_TRANSFERRING, $job->status );
		$calls = array_column( $this->calls, null, 'what' );
		$this->assertArrayHasKey( 'restore_task_create', $calls );
		$options = json_decode( $calls['restore_task_create']['params']['options_json'], true );
		$this->assertSame( ! $rollback, $options['file_rollback'] );
	}

	/**
	 * The same safety artifacts serve different purposes in restores and rollbacks.
	 *
	 * @return array Task cases.
	 */
	public static function safety_artifact_rollback_cases() {
		return array(
			'rollback'       => array( true ),
			'normal restore' => array( false ),
		);
	}

	/**
	 * Rollback is only available after a failed or cancelled restore.
	 *
	 * @dataProvider rollback_status_cases
	 * @param string $status Original job status.
	 * @param bool   $allowed Whether rollback may start.
	 */
	public function test_rollback_enforces_job_status( $status, $allowed ) {
		$original = $this->make_job(
			array(
				'pre_snapshot_artifacts' => array(
					array(
						'file' => 'backup-pre.tar.gz',
						'size' => 1234,
						'role' => 'full',
					),
				),
			),
			$status
		);
		$store    = MainWP_Backup_Restore_Job_Store::instance();

		if ( $allowed ) {
			$job_id = $this->orchestrator->initiate_rollback( $original );
			$job    = $store->get_job( $job_id );
			$this->assertGreaterThan( (int) $original->id, $job_id );
			$this->assertTrue( $job->checkpoint['plan'][0]['adopt_only'] );
			$this->assertSame( 'backup-pre.tar.gz', $job->checkpoint['plan'][0]['file'] );
		} else {
			try {
				$this->orchestrator->initiate_rollback( $original );
				$this->fail( 'Ineligible jobs must not start a rollback.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( 'mainwp_restore_rollback_status', $e->get_message_error_code() );
			}
			$active = $store->get_active_job_for_site( $this->site_id );
			$this->assertSame( 'running' === $status ? (int) $original->id : null, null === $active ? null : (int) $active->id );
		}
		$this->assertSame( $status, $store->get_job( (int) $original->id )->status );
	}

	/**
	 * Completed and active restores must not be replaced by rollback jobs.
	 *
	 * @return array
	 */
	public static function rollback_status_cases() {
		return array(
			'completed' => array( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED, false ),
			'running'   => array( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, false ),
			'cancelled' => array( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, true ),
		);
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
	 * Admission refusals leave terminal and verifying jobs untouched.
	 */
	public function test_cancel_job_admission_refusals() {
		foreach ( array( 'verifying', 'completed', 'failed_permanent' ) as $status ) {
			$job = $this->make_job( array(), $status );
			try {
				$this->orchestrator->cancel_job( (int) $job->id );
				$this->fail( 'Cancellation must be refused for ' . $status );
			} catch ( MainWP_Exception $exception ) {
				$this->assertSame(
					'This restore has already finished on the site and can no longer be cancelled. If it failed, you can roll it back instead.',
					$exception->getMessage()
				);
			}
			$this->assertSame( $status, MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id )->status );
			$this->assertSame( array(), $this->calls );
			MainWP_Backup_Restore_Job_Store::instance()->cancel_job( (int) $job->id );
		}
		$job = $this->make_job( array(), 'cancelled' );
		try {
			$this->orchestrator->cancel_job( (int) $job->id );
			$this->fail( 'An already cancelled job must refuse cancellation.' );
		} catch ( MainWP_Exception $exception ) {
			$this->assertSame( 'The restore is already cancelled.', $exception->getMessage() );
		}
		$this->assertSame( 'cancelled', MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id )->status );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * A staging cancellation must remain gated when the next worker resumes it.
	 */
	public function test_staging_cancel_intent_defers_before_creating_child_task() {
		$job                                    = $this->make_job( array(), 'staging', array( 'child_task_id' => 'task-9-restore' ) );
		$this->responses['restore_task_cancel'] = new MainWP_Exception( 'Site unreachable.' );
		$this->assertSame( 'cancel_pending', $this->orchestrator->cancel_job( (int) $job->id ) );
		$this->calls = array();
		$job         = $this->tick( $job );
		$this->assertSame( 'staging', $job->status );
		$this->assertTrue( $job->checkpoint['cancel_intent'] );
		$this->assertSame( 2, $job->checkpoint['cancel_confirmation']['attempt_count'] );
		$this->assertSame( array( 'restore_task_cancel' ), array_column( $this->calls, 'what' ) );
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
	 * completion path because the child is the authority on whether it restored.
	 *
	 * @dataProvider completed_child_intent_cases
	 * @param bool $has_failure Whether a caught failure is already pending in the checkpoint.
	 */
	public function test_run_job_clears_intent_when_child_completed( $has_failure ) {
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
			array(
				'cancel_intent'       => true,
				'cancel_confirmation' => array(
					'attempt_count'    => 2,
					'first_attempt_at' => time() - 60,
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		if ( $has_failure ) {
			$checkpoint                     = $job->checkpoint;
			$checkpoint['terminal_failure'] = array(
				'error'      => 'Replay failed on table posts',
				'error_code' => 'mainwp_restore_db_replay',
			);
			MainWP_Backup_Restore_Job_Store::instance()->save_checkpoint( (int) $job->id, $checkpoint );
		}
		$job = $this->tick( $job );
		$this->assertArrayNotHasKey( 'last_error_code', $job->checkpoint );
		$this->assertSame( array( 'restore_task_cancel', 'restore_task_run' ), array_column( $this->calls, 'what' ) );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $job->status );
		$this->assertTrue( empty( $job->checkpoint['cancel_intent'] ) );
		$this->assertArrayNotHasKey( 'terminal_failure', $job->checkpoint );
		$this->assertArrayNotHasKey( 'cancel_confirmation', $job->checkpoint );
	}

	/**
	 * A cancellation driven by the user versus one driven by a caught failure.
	 *
	 * @return array
	 */
	public static function completed_child_intent_cases() {
		return array(
			'user cancellation' => array( false ),
			'terminal failure'  => array( true ),
		);
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
	 * A cancel arriving after the entry read must never resume transfer to a completed child.
	 */
	public function test_transferring_cancel_landing_after_run_job_read_skips_chunks_when_child_completed() {
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

		$job   = $this->make_job(
			array( 'plan' => $this->staged_plan_fixture() ),
			MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$id    = (int) $job->id;
		$stale = $store->get_job( $id );
		$this->assertTrue( $store->arm_cancel_intent( $id ) );

		$this->orchestrator->run_job( $stale, microtime( true ) + 30 );
		$job = $store->get_job( $id );

		$this->assertNotContains( 'restore_artifact_chunk', array_column( $this->calls, 'what' ) );
		$this->assertSame( array( 'restore_task_cancel' ), array_column( $this->calls, 'what' ) );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $job->status );
		$this->assertTrue( empty( $job->checkpoint['cancel_intent'] ) );
	}

	/**
	 * Transfer results must not overwrite a row advanced by a concurrent cancel.
	 */
	public function test_transferring_mid_chunk_status_change_preserves_other_lane() {
		$ack   = array( 'bytes_received' => 10 );
		$wrong = array( 'error' => 'ERR_RESTORE_WRONG_PHASE' );
		$rows  = array(
			'advanced_after_ack'       => array( true, array( $ack, $wrong ), 1, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, null, false ),
			'advanced_with_error'      => array( true, array( $wrong ), 1, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, null, false ),
			'transfer_completes'       => array( false, array( $ack, $ack ), 2, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, null, false ),
			'transfer_retry_error'     => array( false, array( $wrong ), 1, MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, null, false ),
			'cancel_busy_with_error'   => array( false, array( $wrong ), 1, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, 'busy', false ),
			'cancel_deferred_busy'     => array( false, array( $wrong ), 1, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, 'busy', false ),
			'cancel_ok_with_error'     => array( false, array( $wrong ), 1, MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, 'ok', false ),
			'retry_transfer_completes' => array( false, array( $ack, $ack ), 2, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, null, true ),
		);

		foreach ( $rows as $label => list( $advance, $responses, $expected_calls, $expected_status, $cancel_answer, $resume ) ) {
			$this->calls                               = array();
			$this->responses['restore_artifact_chunk'] = $responses;
			$this->responses['restore_task_cancel']    = array( 'result' => $cancel_answer );
			$plan                                      = $this->staged_plan_fixture();
			$plan[]                                    = array_merge( $plan[0], array( 'file' => 'db-second.zip' ) );
			$job                                       = $this->make_job(
				array( 'plan' => $plan ),
				MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
				array( 'child_task_id' => 'task-9-restore' )
			);
			$store                                     = MainWP_Backup_Restore_Job_Store::instance();
			$id                                        = (int) $job->id;
			if ( $resume ) {
				$checkpoint                  = $job->checkpoint;
				$checkpoint['resume_status'] = MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING;
				$this->assertTrue( $store->save_checkpoint( $id, $checkpoint ) );
				$this->assertTrue( $store->fail_retryable( $id, '', 1, 0 ) );
				$job = $store->get_job( $id );
			}
			$attempts              = (int) $job->attempt_count;
			$concurrent_checkpoint = $job->checkpoint;
			$chunk_calls           = 0;
			$confirmation          = null;
			$defer_cancel          = 'cancel_deferred_busy' === $label;
			$on_chunk              = function ( $pre, $website, $what ) use ( $advance, $cancel_answer, $store, $id, $concurrent_checkpoint, &$chunk_calls, $defer_cancel, &$confirmation ) {
				if ( 'restore_artifact_chunk' === $what && 1 === ++$chunk_calls && $advance ) {
					// Match the row left by a UI cancel when the child already completed.
					$this->assertTrue( $store->save_checkpoint( $id, $concurrent_checkpoint, null, true ) );
					$this->assertTrue( $store->set_status( $id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING ) );
				}
				if ( 'restore_artifact_chunk' === $what && 1 === $chunk_calls && null !== $cancel_answer ) {
					if ( $defer_cancel ) {
						$confirmation = $store->defer_terminal_failure( $id, null, '' );
						$this->assertNotNull( $confirmation );
						$this->assertSame( 1, $confirmation['attempt_count'] );
					} else {
						$this->assertTrue( $store->arm_cancel_intent( $id ) );
					}
				}
				return $pre;
			};
			add_filter( 'mainwp_fetch_url_authed_pre', $on_chunk, 20, 3 );
			try {
				$job = $this->tick( $job );
			} finally {
				remove_filter( 'mainwp_fetch_url_authed_pre', $on_chunk, 20 );
			}

			$expected_actions = array_fill( 0, $expected_calls, 'restore_artifact_chunk' );
			if ( null !== $cancel_answer ) {
				$expected_actions[] = 'restore_task_cancel';
			}
			$this->assertSame( $expected_actions, array_column( $this->calls, 'what' ), $label );
			$this->assertSame( $expected_status, $job->status, $label );
			if ( $defer_cancel ) {
				$this->assertSame( $confirmation['first_attempt_at'], $job->checkpoint['cancel_confirmation']['first_attempt_at'], $label );
				$this->assertSame( 2, $job->checkpoint['cancel_confirmation']['attempt_count'], $label );
			}
			if ( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $expected_status ) {
				$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->checkpoint['resume_status'], $label );
				$this->assertNotEmpty( $job->last_error, $label );
				$this->assertSame( $attempts + 1, (int) $job->attempt_count, $label );
			} else {
				$this->assertEmpty( $job->last_error, $label );
				$this->assertSame( $attempts, (int) $job->attempt_count, $label );
				if ( $resume ) {
					$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->checkpoint['resume_status'], $label );
				} else {
					$this->assertArrayNotHasKey( 'resume_status', $job->checkpoint, $label );
				}
				if ( $advance ) {
					$this->assertSame( $concurrent_checkpoint, $job->checkpoint, $label );
				} elseif ( null !== $cancel_answer ) {
					$this->assertSame( 0, $job->checkpoint['transfer']['artifact_index'], $label );
					if ( 'busy' === $cancel_answer ) {
						$this->assertNotEmpty( $job->checkpoint['cancel_intent'], $label );
					}
				} else {
					$this->assertSame( count( $plan ), $job->checkpoint['transfer']['artifact_index'], $label );
				}
			}
			// Release the site's restore slot before the next matrix row; a cancelled row already has.
			if ( in_array( $job->status, $store::active_statuses(), true ) ) {
				$this->assertTrue( $store->set_status( $id, MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED ), $label );
			}
		}
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
	 * Restore initiate must not reveal a snapshot owned by another site.
	 *
	 * @return void
	 */
	public function test_initiate_hides_foreign_site_snapshot() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$snapshot             = $this->woo_snapshot();
		$site_b               = $this->create_test_site();
		$nonce                = wp_create_nonce( 'mainwp_restore_initiate' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['site_id']     = (string) $site_b;
		$_POST['snapshot_id'] = (string) $snapshot->id;

		try {
			$response = $this->invoke_ajax_handler( 'mainwp_restore_initiate' );
		} finally {
			unset( $_POST['security'], $_REQUEST['security'], $_POST['site_id'], $_POST['snapshot_id'] );
		}

		$this->assertSame( array( 'error' => 'Snapshot not found for this site.' ), $response, 'A foreign snapshot must reveal no confirmation or age.' );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_b ) );
	}

	/**
	 * A snapshot id that does not exist gets the same refusal as a foreign one.
	 */
	public function test_initiate_refuses_missing_snapshot() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id              = $this->create_test_site();
		$nonce                = wp_create_nonce( 'mainwp_restore_initiate' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['site_id']     = (string) $site_id;
		$_POST['snapshot_id'] = '987654321';

		try {
			$response = $this->invoke_ajax_handler( 'mainwp_restore_initiate' );
		} finally {
			unset( $_POST['security'], $_REQUEST['security'], $_POST['site_id'], $_POST['snapshot_id'] );
		}

		$this->assertSame( array( 'error' => 'Snapshot not found for this site.' ), $response );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id ) );
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

	/**
	 * Private-root errors survive both restore phases in the persisted job.
	 */
	public function test_private_root_failure_is_recorded_in_restore_job() {
		add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
		\MainWP\Dashboard\MainWP_Backup_Private_Root::reset();
		foreach ( array( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING ) as $phase ) {
			$job                    = $this->make_job( array(), $phase, array( 'child_task_id' => 'task-existing' ) );
			$plan                   = $job->checkpoint['plan'];
			$plan[0]['source']      = 'remote';
			$plan[0]['staged_path'] = $this->dir . '/db.zip';
			$checkpoint             = $job->checkpoint;
			$checkpoint['plan']     = $plan;
			MainWP_Backup_Restore_Job_Store::instance()->save_checkpoint( (int) $job->id, $checkpoint );
			$job = $this->tick( $job );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $job->checkpoint['last_error_code'] );
		}
	}

	/**
	 * A resumed transfer saves the cleared plan before returning to staging.
	 */
	public function test_legacy_transfer_checkpoint_returns_to_staging() {
		$job                   = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, array( 'child_task_id' => 'task-existing' ) );
		$checkpoint            = $job->checkpoint;
		$checkpoint['plan'][0] = array_merge(
			$checkpoint['plan'][0],
			array(
				'source'      => 'remote_chunked',
				'staged_path' => $this->dir . '/db.zip',
				'sha256'      => hash( 'sha256', 'dump-bytes' ),
			)
		);
		MainWP_Backup_Restore_Job_Store::instance()->save_checkpoint( (int) $job->id, $checkpoint );
		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertSame( '', $job->checkpoint['plan'][0]['staged_path'] );
		$this->assertSame( '', $job->checkpoint['plan'][0]['sha256'] );
		$this->assertSame( 'task-existing', $job->child_task_id );
		$this->assertEmpty( $this->calls );
	}
}
