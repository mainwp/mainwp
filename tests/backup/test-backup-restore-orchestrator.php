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
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_Backup_Read_Model;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_Backup_Run_History;
use MainWP\Dashboard\MainWP_Backup_Retention_Executor;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Utility;
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
	 * Count artifact checks so disabled preflight cannot contact storage first.
	 *
	 * @var int
	 */
	public $remote_checks = 0;

	/**
	 * Runs during the artifact check, standing in for a save from another request.
	 *
	 * @var callable|null
	 */
	public $during_remote_check = null;

	/**
	 * Preserve the real artifact check while observing its ordering.
	 *
	 * @param array $plan Transfer plan.
	 * @return bool
	 */
	public function remote_artifacts_present( array $plan ): bool {
		++$this->remote_checks;
		if ( null !== $this->during_remote_check ) {
			call_user_func( $this->during_remote_check );
		}
		return parent::remote_artifacts_present( $plan );
	}

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
	 * Called during the reachability check, to stage what happens while it runs.
	 *
	 * @var callable|null
	 */
	public $on_probe = null;

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
	 * Null keeps the production throttle.
	 *
	 * @var float|null
	 */
	public $transfer_progress_interval = null;

	/**
	 * Null keeps the wall clock.
	 *
	 * @var float|null
	 */
	public $transfer_progress_now = null;

	/**
	 * Avoid waiting for a checkpoint window in transfer tests.
	 *
	 * @return float Seconds between progress saves.
	 */
	protected function transfer_progress_interval(): float {
		return $this->transfer_progress_interval ?? parent::transfer_progress_interval();
	}

	/**
	 * Keep throttle tests independent of machine speed.
	 *
	 * @return float Current time in seconds.
	 */
	protected function transfer_progress_time(): float {
		return $this->transfer_progress_now ?? parent::transfer_progress_time();
	}

	/**
	 * Pinned check.
	 *
	 * @param object $website Website.
	 *
	 * @return bool
	 */
	protected function site_responds( object $website ): bool {
		if ( is_callable( $this->on_probe ) ) {
			call_user_func( $this->on_probe );
		}
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

	use Backup_Cancel_Transport_Guard;

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
	 * Tombstones must end active jobs without retrying the cancelled identity.
	 *
	 * @dataProvider restore_create_paths
	 * @param bool $cloud Whether the create carries a pull manifest.
	 */
	public function test_cancelled_before_start_replay_is_permanent( $cloud ) {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array(), $store::STATUS_STAGING );
		if ( $cloud ) {
			$this->use_cloud_pull_driver( $this->pull_manifest_fixture() );
		}
		$this->responses['restore_task_create'] = array(
			'task_id'    => 'cancel-tombstone',
			'reused'     => true,
			'phase'      => 'failed',
			'error_code' => 'mainwp_restore_cancelled_before_start',
			'error'      => 'This restore was cancelled before the site started it.',
		);
		$this->responses['restore_task_cancel'] = array(
			'result' => 'already_terminal',
			'phase'  => 'failed',
		);
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'This restore was cancelled before the site started it.', $job->last_error );
		$this->assertSame( 'mainwp_restore_cancelled_before_start', $job->checkpoint['last_error_code'] );
		$this->assertSame( 'cancel-tombstone', $job->child_task_id );
		$this->assertSame( 0, (int) $job->attempt_count );
		$this->assertSame( 0, (int) $job->next_retry_at );
		$calls = $this->calls;
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$this->assertSame( $calls, $this->calls );
	}

	/**
	 * A terminal row must survive a late tombstone answer unchanged.
	 *
	 * @dataProvider restore_create_paths
	 * @param bool $cloud Whether the create carries a pull manifest.
	 */
	public function test_late_tombstone_replay_cancels_by_task_id( $cloud ) {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array(), $store::STATUS_STAGING );
		if ( $cloud ) {
			$this->use_cloud_pull_driver( $this->pull_manifest_fixture() );
		}
		$this->responses['restore_task_create'] = array(
			'task_id'    => 'late-tombstone',
			'reused'     => true,
			'phase'      => 'failed',
			'error_code' => 'mainwp_restore_cancelled_before_start',
			'error'      => 'This restore was cancelled before the site started it.',
		);
		$this->responses['restore_task_cancel'] = array(
			'result' => 'already_terminal',
			'phase'  => 'failed',
		);
		$terminal                               = null;
		$end                                    = function ( $pre, $website, $what ) use ( $store, $job, &$terminal ) {
			if ( 'restore_task_create' === $what ) {
				$this->assertSame( 'written', $store->finish_with_cancel_confirmation( (int) $job->id, $store::STATUS_CANCELLED, null, '', null ) );
				$terminal = $store->get_job( (int) $job->id );
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $end, 20, 3 );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $end, 20 );
		}
		$this->assertNotNull( $terminal );
		$this->assertEquals( $terminal, $store->get_job( (int) $job->id ) );
		$this->assertSame( array( 'restore_task_create', 'restore_task_cancel' ), $this->calls_without_report_reads() );
		$this->assertSame( array( 'task_id' => 'late-tombstone' ), $this->calls[1]['params'] );
	}

	/**
	 * Both create paths share the Child's restore identity contract.
	 *
	 * @return array
	 */
	public static function restore_create_paths() {
		return array(
			'standard'   => array( false ),
			'cloud pull' => array( true ),
		);
	}

	/**
	 * Lose the actual lease after the Child answers, before the fenced id write.
	 *
	 * @param bool       $cloud Whether to send a pull manifest.
	 * @param array|null $answer Optional failed replay instead of a fresh create.
	 * @return object Job held by the replacement worker.
	 */
	private function create_with_lost_lease( $cloud, $answer = null ) {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array(), $store::STATUS_STAGING );
		if ( $cloud ) {
			$this->use_cloud_pull_driver( $this->pull_manifest_fixture() );
		}
		$this->responses['restore_task_create'] = $answer ?? array(
			'task_id' => 'lease-task',
			'phase'   => 'queued',
		);
		$holder                                 = null;
		$swap                                   = function ( $pre, $website, $what ) use ( $wpdb, $store, $job, &$holder ) {
			if ( 'restore_task_create' === $what ) {
				$before = $store->get_job( (int) $job->id );
				$holder = $before->locked_by;
				$this->assertNotEmpty( $holder );
				$this->assertTrue( $before->checkpoint['child_create_attempted'] );
				$this->assertSame(
					1,
					$wpdb->update(
						MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ),
						array(
							'locked_by'    => 'replacement-holder',
							'locked_until' => time() + 300,
						),
						array( 'id' => $job->id )
					)
				);
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $swap, 20, 3 );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $swap, 20 );
		}
		$job = $store->get_job( (int) $job->id );
		$this->assertNotNull( $holder );
		$this->assertSame( 'replacement-holder', $job->locked_by );
		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertSame( '', $job->child_task_id );
		$this->assertSame( array( 'restore_task_create' ), $this->calls_without_report_reads() );
		return $job;
	}

	/**
	 * A new holder must recover the first Child task through the same restore identity.
	 *
	 * @dataProvider restore_create_paths
	 * @param bool $cloud Whether the create carries a pull manifest.
	 */
	public function test_lost_create_lease_reclaims_same_child_task( $cloud ) {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->create_with_lost_lease( $cloud );
		$this->assertSame( 1, $wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'locked_until' => time() - 1 ), array( 'id' => $job->id ) ) );
		$this->responses['restore_task_create'] = array(
			'task_id'   => 'lease-task',
			'reused'    => true,
			'phase'     => 'queued',
			'sub_state' => array( 'params' => array( 'pull_manifest' => $cloud ? $this->pull_manifest_fixture() : null ) ),
		);
		$this->responses['restore_task_run']    = array(
			'task_id'   => 'lease-task',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'db_ok' => true ) ),
		);
		$reclaim_token                          = null;
		$recorded                               = null;
		$observe                                = static function ( $pre, $website, $what ) use ( $store, $job, &$reclaim_token, &$recorded ) {
			if ( 'restore_task_create' === $what ) {
				$reclaim_token = $store->get_job( (int) $job->id )->locked_by;
			} elseif ( 'restore_task_run' === $what ) {
				$recorded = $store->get_job( (int) $job->id );
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $observe, 20, 3 );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $observe, 20 );
		}
		$job     = $store->get_job( (int) $job->id );
		$creates = array_values( array_filter( $this->calls, static fn( $call ) => 'restore_task_create' === $call['what'] ) );
		$this->assertCount( 2, $creates );
		$this->assertSame( 'orch01test', $creates[0]['params']['restore_id'] );
		$this->assertSame( $creates[0]['params']['restore_id'], $creates[1]['params']['restore_id'] );
		$this->assertSame( $cloud, isset( $creates[1]['params']['pull_manifest_json'] ) );
		$this->assertNotEmpty( $reclaim_token );
		$this->assertNotSame( 'replacement-holder', $reclaim_token );
		$this->assertNotNull( $recorded );
		$this->assertSame( $reclaim_token, $recorded->locked_by );
		$this->assertSame( 'lease-task', $recorded->child_task_id );
		$this->assertSame( 1, (int) $recorded->reclaim_count );
		$this->assertSame( $store::STATUS_COMPLETED, $job->status );
		$this->assertSame( 'lease-task', $job->child_task_id );
		$this->assertNotContains( 'restore_task_cancel', $this->calls_without_report_reads() );
	}

	/**
	 * A terminal decision must reach an answered task whose id the old lease could not record.
	 *
	 * @dataProvider lost_create_terminal_cases
	 * @param bool   $cloud Whether the create carries a pull manifest.
	 * @param string $trigger Terminal decision source.
	 */
	public function test_lost_create_lease_terminal_cancels_restore_identity( $cloud, $trigger ) {
		global $wpdb;
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->create_with_lost_lease( $cloud );
		$this->responses['restore_task_cancel'] = array( 'result' => 'ok' );
		if ( 'user' === $trigger ) {
			$this->assertSame( 'cancelled', $this->orchestrator->cancel_job( (int) $job->id ) );
		} elseif ( 'ceiling' === $trigger ) {
			$this->assertSame(
				1,
				$wpdb->update(
					MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ),
					array(
						'locked_until'  => time() - 1,
						'reclaim_count' => $store::RECLAIM_CEILING,
					),
					array( 'id' => $job->id )
				)
			);
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} else {
			$record = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'record_terminal_failure' );
			$record->invoke( $this->orchestrator, $job, 'Restore stopped.', 'test_restore_failure', 'replacement-holder' );
		}
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( 'user' === $trigger ? $store::STATUS_CANCELLED : $store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'confirmed', $job->checkpoint['cancel_confirmation']['outcome'] );
		$this->assertSame( array( 'restore_task_create', 'restore_task_cancel' ), $this->calls_without_report_reads() );
		$this->assertSame( array( 'restore_id' => $this->calls[0]['params']['restore_id'] ), $this->calls[1]['params'] );
	}

	/**
	 * Terminal sources apply equally to standard and cloud-pull creates.
	 *
	 * @return array
	 */
	public static function lost_create_terminal_cases() {
		$cases = array();
		foreach ( array( false, true ) as $cloud ) {
			foreach ( array( 'user', 'ceiling', 'failure' ) as $trigger ) {
				$cases[ ( $cloud ? 'cloud ' : 'standard ' ) . $trigger ] = array( $cloud, $trigger );
			}
		}
		return $cases;
	}

	/**
	 * Once a successor records the handle, UI cancellation must name that task.
	 *
	 * @dataProvider restore_create_paths
	 * @param bool $cloud Whether the create carries a pull manifest.
	 */
	public function test_lost_create_lease_recorded_by_successor_cancels_task_id( $cloud ) {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->create_with_lost_lease( $cloud );
		$this->assertSame( 1, $wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'locked_until' => time() - 1 ), array( 'id' => $job->id ) ) );
		$this->responses['restore_task_create'] = array(
			'task_id' => 'lease-task',
			'reused'  => true,
			'phase'   => 'queued',
		);
		// One phase records the recovered id before a later slice can run the task.
		$this->orchestrator->process_pending_restore_jobs( 0, (int) $job->id );
		$successor = $store->get_job( (int) $job->id );
		$this->assertSame( 'lease-task', $successor->child_task_id );
		$this->assertSame( 1, (int) $successor->reclaim_count );
		$this->responses['restore_task_cancel'] = array( 'result' => 'ok' );
		$this->assertSame( 'cancelled', $this->orchestrator->cancel_job( (int) $job->id ) );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $store::STATUS_CANCELLED, $job->status );
		$this->assertSame( 'confirmed', $job->checkpoint['cancel_confirmation']['outcome'] );
		$this->assertSame( array( 'restore_task_create', 'restore_task_create', 'restore_task_cancel' ), $this->calls_without_report_reads() );
		$this->assertSame( $this->calls[0]['params']['restore_id'], $this->calls[1]['params']['restore_id'] );
		$this->assertSame( array( 'task_id' => 'lease-task' ), $this->calls[2]['params'] );
	}

	/**
	 * A refused rotation must leave the failed identity available for the next holder's replay.
	 */
	public function test_cloud_pull_rotation_after_lost_create_lease_replays_original_identity() {
		global $wpdb;
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$answer = array(
			'task_id'    => 'failed-pull',
			'reused'     => true,
			'phase'      => 'failed',
			'error_code' => 'mainwp_cloud_pull_failed',
		);
		$job    = $this->create_with_lost_lease( true, $answer );
		$this->assertSame( 'orch01test', $job->checkpoint['restore_id'] );
		$this->assertArrayNotHasKey( 'cloud_pull_tried', $job->checkpoint );
		$this->assertTrue( $job->checkpoint['child_create_attempted'] );
		$this->assertSame( 1, $wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'locked_until' => time() - 1 ), array( 'id' => $job->id ) ) );
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertSame( '', $job->child_task_id );
		$this->assertNotSame( 'orch01test', $job->checkpoint['restore_id'] );
		$this->assertTrue( $job->checkpoint['cloud_pull_tried'] );
		$this->assertArrayNotHasKey( 'child_create_attempted', $job->checkpoint );
		$this->assertSame( array(), $job->checkpoint['transfer'] );
		$this->assertSame( array( 'restore_task_create', 'restore_task_create' ), $this->calls_without_report_reads() );
		$this->assertSame( 'orch01test', $this->calls[1]['params']['restore_id'] );
	}

	/**
	 * Disabling backups must release a restore before any safety or restore work starts.
	 *
	 * @dataProvider disabled_preflight_cases
	 * @param bool $safety Whether a safety backup was requested.
	 * @param bool $retry Whether the job resumes preflight from backoff.
	 * @param bool $rollback Whether this is a rollback job.
	 */
	public function test_native_disabled_preflight_releases_site_without_child_calls( $safety, $retry, $rollback ) {
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job    = $this->make_job(
			array(
				'options'       => array( 'pre_snapshot' => $safety ),
				'resume_status' => $store::STATUS_PREFLIGHT,
				'is_rollback'   => $rollback,
			),
			$retry ? $store::STATUS_FAILED_RETRYABLE : $store::STATUS_PREFLIGHT
		);
		$driver = $this->use_cloud_pull_driver( null );
		update_option( 'mainwp_enableNativeBackups', 0 );
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$this->assert_native_disabled_failure( $store->get_job( (int) $job->id ) );
		$this->assertSame( array(), $this->calls );
		$this->assertSame( 0, $driver->remote_checks );
	}

	/**
	 * Safety selection and retry routing cannot bypass the global toggle, including rollback.
	 *
	 * @return array
	 */
	public static function disabled_preflight_cases() {
		$cases = array();
		foreach ( array( false, true ) as $safety ) {
			foreach ( array( false, true ) as $retry ) {
				foreach ( array( false, true ) as $rollback ) {
					$cases[ (int) $safety . '-' . (int) $retry . '-' . (int) $rollback ] = array( $safety, $retry, $rollback );
				}
			}
		}
		return $cases;
	}

	/**
	 * A toggle change after preflight's check must receive the same permanent refusal.
	 */
	public function test_native_disabled_during_trigger_backup_is_permanent() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array( 'options' => array( 'pre_snapshot' => true ) ), $store::STATUS_PREFLIGHT );
		$reads = 0;
		// The orchestrator reads the stored toggle (still on); trigger_backup() reads it through get_option().
		$flip = static function () use ( &$reads ) {
			++$reads;
			return 0;
		};
		add_filter( 'option_mainwp_enableNativeBackups', $flip );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_filter( 'option_mainwp_enableNativeBackups', $flip );
		}
		$this->assertGreaterThanOrEqual( 1, $reads );
		$this->assert_native_disabled_failure( $store->get_job( (int) $job->id ) );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * A safety task already created by preflight must receive one best-effort cancellation.
	 *
	 * @dataProvider disabled_safety_cancel_answers
	 * @param array|\Throwable $answer Child cancellation answer.
	 * @param bool             $prune Whether an already-terminal answer permits pruning.
	 * @param string           $log Expected log fragment, or empty.
	 */
	public function test_native_disabled_pre_snapshot_cancels_once_and_finalizes_history( $answer, $prune, $log ) {
		global $wpdb;
		$store      = MainWP_Backup_Restore_Job_Store::instance();
		$job        = $this->make_owned_safety_job();
		$history_id = MainWP_Backup_Run_History::start( 0, $this->site_id, time(), 'restore_safety', 0, (int) $job->id, 'disabled-safety' );
		$this->assertGreaterThan( 0, $history_id );
		$this->responses['backup_task_cancel'] = $answer;
		$logs                                  = array();
		$listener                              = static function ( $message ) use ( &$logs ) {
			$logs[] = $message;
		};
		add_action( 'mainwp_log_info', $listener );
		update_option( 'mainwp_enableNativeBackups', 0 );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
			$job = $store->get_job( (int) $job->id );
			$this->assert_native_disabled_failure( $job );
			$this->assertSame( $prune ? array( 'backup_task_cancel', 'backup_task_prune' ) : array( 'backup_task_cancel' ), array_column( $this->calls, 'what' ) );
			$this->assertSame( array( 'task_id' => 'disabled-safety' ), $this->calls[0]['params'] );
			$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
			$history = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ) . ' WHERE id = %d', $history_id ) ); // phpcs:ignore WordPress.DB -- test assertion.
			$this->assertSame( 'failed', $history->outcome );
			$this->assertSame( 'mainwp_restore_native_backups_disabled', $history->error_code );
			if ( '' !== $log ) {
				$this->assertStringContainsString( $log, implode( "\n", $logs ) );
			}
			$calls = $this->calls;
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
			$this->assertSame( $calls, $this->calls );
		} finally {
			remove_action( 'mainwp_log_info', $listener );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ), array( 'id' => $history_id ) );
		}
	}

	/**
	 * These answers are also supported by older Children.
	 *
	 * @return array
	 */
	public static function disabled_safety_cancel_answers() {
		return array(
			'ok'                => array( array( 'result' => 'ok' ), false, '' ),
			'already completed' => array(
				array(
					'result' => 'already_terminal',
					'phase'  => 'completed',
				),
				true,
				'',
			),
			'already failed'    => array(
				array(
					'result' => 'already_terminal',
					'phase'  => 'failed',
				),
				true,
				'',
			),
			'busy'              => array( array( 'result' => 'busy' ), false, 'the site is still running its safety backup' ),
			'transport failure' => array( new MainWP_Exception( 'Site unreachable.' ), false, 'the site did not confirm cancelling its safety backup: Site unreachable.' ),
		);
	}

	/**
	 * A reconcile delay must not keep a disabled restore reserved when its phase is driven.
	 */
	public function test_native_disabled_pre_snapshot_precedes_reconcile_deferral() {
		$store    = MainWP_Backup_Restore_Job_Store::instance();
		$retry_at = time() + 3600;
		$job      = $this->make_owned_safety_job( array( 'pre_snapshot_reconcile_pending' => true ), array( 'next_retry_at' => $retry_at ) );
		update_option( 'mainwp_enableNativeBackups', 0 );
		$job = $this->tick( $job );
		$this->assert_native_disabled_failure( $job );
		$this->assertSame( array( 'backup_task_cancel' ), array_column( $this->calls, 'what' ) );
		$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
		$this->assertSame( $retry_at, (int) $store->get_job( (int) $job->id )->next_retry_at );
	}

	/**
	 * A slice keeps polling the safety backup, so a toggle another request turns off during a run request stops it before the next one.
	 */
	public function test_native_disabled_during_safety_backup_slice_stops_before_next_poll() {
		$store                                 = MainWP_Backup_Restore_Job_Store::instance();
		$job                                   = $this->make_owned_safety_job();
		$progress                              = static function ( $files ) {
			return array(
				'phase'    => 'archiving_files',
				'progress' => array( 'files_scanned' => $files ),
			);
		};
		$this->responses['backup_task_run']    = array( $progress( 10 ), $progress( 20 ), $progress( 30 ) );
		$this->responses['backup_task_cancel'] = array( 'result' => 'ok' );
		$turn_off                              = static function ( $pre, $website, $what ) {
			if ( 'backup_task_run' === $what ) {
				global $wpdb;
				// Another request saves the setting; this one still holds the old value in its option cache.
				$wpdb->update( $wpdb->options, array( 'option_value' => '0' ), array( 'option_name' => 'mainwp_enableNativeBackups' ) ); // phpcs:ignore WordPress.DB -- simulates a save from another request.
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $turn_off, 20, 3 );
		try {
			$this->orchestrator->process_pending_restore_jobs( 5, (int) $job->id );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $turn_off, 20 );
		}
		$this->assert_native_disabled_failure( $store->get_job( (int) $job->id ) );
		$this->assertSame( array( 'backup_task_run', 'backup_task_cancel' ), array_column( $this->calls, 'what' ) );
		$this->assertSame( array( 'task_id' => 'disabled-safety' ), $this->calls[1]['params'] );
	}

	/**
	 * A toggle saved while the last run request is in flight stops the restore before staging, and the finished safety backup is pruned.
	 */
	public function test_native_disabled_during_final_safety_backup_request_stops_before_staging() {
		$store                                 = MainWP_Backup_Restore_Job_Store::instance();
		$job                                   = $this->make_owned_safety_job();
		$this->responses['backup_task_run']    = array(
			'task_id'   => 'disabled-safety',
			'phase'     => 'completed',
			'artifacts' => array(
				array(
					'role' => 'full',
					'file' => 'full-site.zip',
					'size' => 1024,
				),
			),
		);
		$this->responses['backup_task_cancel'] = array(
			'result' => 'already_terminal',
			'phase'  => 'completed',
		);
		$turn_off                              = static function ( $pre, $website, $what ) {
			if ( 'backup_task_run' === $what ) {
				global $wpdb;
				$wpdb->update( $wpdb->options, array( 'option_value' => '0' ), array( 'option_name' => 'mainwp_enableNativeBackups' ) ); // phpcs:ignore WordPress.DB -- simulates a save from another request.
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $turn_off, 20, 3 );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $turn_off, 20 );
		}
		$job = $store->get_job( (int) $job->id );
		$this->assert_native_disabled_failure( $job );
		$this->assertArrayNotHasKey( 'pre_snapshot_artifacts', $job->checkpoint );
		$this->assertSame( array( 'backup_task_run', 'backup_task_cancel', 'backup_task_prune' ), array_column( $this->calls, 'what' ) );
		$this->assertSame( array( 'task_id' => 'disabled-safety' ), $this->calls[1]['params'] );
		$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
	}

	/**
	 * A toggle saved while older safety backups are pruned stops the restore before staging; the new safety backup stays recorded.
	 */
	public function test_native_disabled_during_superseded_prune_stops_before_staging() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->make_job( $this->safety_checkpoint(), $store::STATUS_FAILED_PERMANENT, array( 'pre_snapshot_task_id' => 'task-failed-pre' ) );
		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );
		$job                                  = $this->make_pre_snapshot_job( 'task-new-pre' );
		$turn_off                             = static function ( $pre, $website, $what ) {
			if ( 'backup_task_prune' === $what ) {
				global $wpdb;
				$wpdb->update( $wpdb->options, array( 'option_value' => '0' ), array( 'option_name' => 'mainwp_enableNativeBackups' ) ); // phpcs:ignore WordPress.DB -- simulates a save from another request.
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $turn_off, 20, 3 );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $turn_off, 20 );
		}
		$job = $store->get_job( (int) $job->id );
		$this->assert_native_disabled_failure( $job );
		$this->assertNotEmpty( $job->checkpoint['pre_snapshot_artifacts'] ?? array() );
		$this->assertSame( array( 'backup_task_run', 'backup_task_prune' ), array_column( $this->calls, 'what' ) );
	}

	/**
	 * A failed toggle read is no evidence the toggle was turned off, so the restore goes on.
	 */
	public function test_failed_toggle_read_does_not_stop_the_restore() {
		global $wpdb;
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job    = $this->make_pre_snapshot_job( 'task-new-pre' );
		$break  = static function ( $sql ) {
			if ( false !== strpos( $sql, 'SELECT option_name, option_value FROM' ) && false !== strpos( $sql, "'mainwp_enableNativeBackups'" ) ) {
				return str_replace( 'SELECT option_name, option_value FROM', 'SELECT no_such_column FROM', $sql );
			}
			return $sql;
		};
		$quiet  = $wpdb->suppress_errors( true );
		$failed = $wpdb->show_errors( false );
		add_filter( 'query', $break );
		try {
			$job = $this->tick( $job );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $quiet );
			$wpdb->show_errors( $failed );
		}
		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertNotSame( 'mainwp_restore_native_backups_disabled', $job->checkpoint['last_error_code'] ?? '' );
		$this->assertNotEmpty( $job->checkpoint['pre_snapshot_artifacts'] ?? array() );
	}

	/**
	 * A toggle saved during the artifact check stops preflight before the safety backup or staging.
	 *
	 * @dataProvider native_disabled_artifact_check_cases
	 * @param bool $safety Whether a safety backup was requested.
	 */
	public function test_native_disabled_during_artifact_check_stops_preflight( $safety ) {
		$store                       = MainWP_Backup_Restore_Job_Store::instance();
		$job                         = $this->make_job( array( 'options' => array( 'pre_snapshot' => $safety ) ), $store::STATUS_PREFLIGHT );
		$driver                      = $this->use_cloud_pull_driver( null );
		$driver->during_remote_check = static function () {
			global $wpdb;
			$wpdb->update( $wpdb->options, array( 'option_value' => '0' ), array( 'option_name' => 'mainwp_enableNativeBackups' ) ); // phpcs:ignore WordPress.DB -- simulates a save from another request.
		};
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$this->assert_native_disabled_failure( $store->get_job( (int) $job->id ) );
		$this->assertSame( 1, $driver->remote_checks );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Both preflight exits.
	 *
	 * @return array
	 */
	public static function native_disabled_artifact_check_cases() {
		return array(
			'no safety backup' => array( false ),
			'safety backup'    => array( true ),
		);
	}

	/**
	 * Only the worker whose terminal write lands may cancel the owned safety task.
	 */
	public function test_native_disabled_pre_snapshot_successor_cancels_after_terminal_write() {
		global $wpdb;
		$store   = MainWP_Backup_Restore_Job_Store::instance();
		$job     = $this->make_owned_safety_job();
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$swapped = false;
		$swap    = function ( $sql ) use ( $wpdb, $table, $job, &$swapped ) {
			if ( ! $swapped && str_starts_with( $sql, 'UPDATE ' . $table . " SET `status` = 'failed_permanent'" ) ) {
				$swapped = true;
				$this->assertSame(
					1,
					$wpdb->update(
						$table,
						array(
							'locked_by'    => 'safety-successor',
							'locked_until' => time() + 300,
						),
						array( 'id' => $job->id )
					)
				);
			}
			return $sql;
		};
		update_option( 'mainwp_enableNativeBackups', 0 );
		add_filter( 'query', $swap );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_filter( 'query', $swap );
		}
		$this->assertTrue( $swapped );
		$this->assertSame( array(), $this->calls );
		$fresh = $store->get_job( (int) $job->id );
		$this->assertSame( $store::STATUS_PRE_SNAPSHOT, $fresh->status );
		$this->assertSame( 'safety-successor', $fresh->locked_by );
		$this->assertNotNull( $fresh->active_site_id );
		$this->assertNotNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
		$this->assertSame( 1, $wpdb->update( $table, array( 'locked_until' => time() - 1 ), array( 'id' => $job->id ) ) );
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$this->assert_native_disabled_failure( $store->get_job( (int) $job->id ) );
		$this->assertSame( array( 'backup_task_cancel' ), array_column( $this->calls, 'what' ) );
		$this->assertSame( array( 'task_id' => 'disabled-safety' ), $this->calls[0]['params'] );
		$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
	}

	/**
	 * A user cancel of a safety-backup phase ends the job at once, so a later drive with backups off finds nothing to fail.
	 */
	public function test_cancel_precedes_native_disabled_failure() {
		$store                                 = MainWP_Backup_Restore_Job_Store::instance();
		$job                                   = $this->make_owned_safety_job();
		$this->responses['backup_task_cancel'] = array( 'result' => 'ok' );
		update_option( 'mainwp_enableNativeBackups', 0 );
		$this->orchestrator->cancel_job( (int) $job->id );
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $store::STATUS_CANCELLED, $job->status );
		$this->assertNotSame( 'mainwp_restore_native_backups_disabled', $job->checkpoint['last_error_code'] ?? '' );
		$this->assertNull( $job->active_site_id );
		$this->assertSame( array( 'backup_task_cancel' ), array_column( $this->calls, 'what' ) );
		$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
	}

	/**
	 * A stored cancel intent on a phase that has a Child restore task is applied before anything reads the toggle.
	 */
	public function test_cancel_intent_after_create_precedes_native_disabled() {
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job( array( 'cancel_intent' => true ), $store::STATUS_RUNNING, array( 'child_task_id' => 'intent-task' ) );
		$this->responses['restore_task_cancel'] = array( 'result' => 'ok' );
		update_option( 'mainwp_enableNativeBackups', 0 );
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $store::STATUS_CANCELLED, $job->status );
		$this->assertNotSame( 'mainwp_restore_native_backups_disabled', $job->checkpoint['last_error_code'] ?? '' );
		$this->assertSame( array( 'restore_task_cancel' ), array_slice( $this->calls_without_report_reads(), 0, 1 ) );
		$this->assertSame( array( 'task_id' => 'intent-task' ), $this->calls[0]['params'] );
	}

	/**
	 * Once a restore task exists, turning backups off must not interrupt its recovery work.
	 *
	 * @dataProvider native_toggle_later_phases
	 * @param string $phase Entry phase.
	 * @param string $expected Resulting phase.
	 * @param bool   $enabled Toggle state.
	 */
	public function test_native_toggle_does_not_interrupt_created_restore( $phase, $expected, $enabled ) {
		$overrides                           = 'transferring' === $phase ? array( 'plan' => $this->staged_plan_fixture() ) : array();
		$job                                 = $this->make_job( $overrides, $phase, array( 'child_task_id' => 'created-restore' ) );
		$this->responses['restore_task_run'] = array(
			'task_id'   => 'created-restore',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'db_ok' => true ) ),
		);
		update_option( 'mainwp_enableNativeBackups', $enabled ? 1 : 0 );
		$job = $this->tick( $job );
		$this->assertSame( $expected, $job->status );
		$this->assertSame( 'created-restore', $job->child_task_id );
		$this->assertSame( '', (string) $job->last_error );
		$this->assertSame( 'running' === $phase ? array( 'restore_task_run' ) : ( 'transferring' === $phase ? array( 'restore_artifact_chunk' ) : array() ), $this->calls_without_report_reads() );
	}

	/**
	 * The toggle gates admission, while existing restore work must finish.
	 *
	 * @return array
	 */
	public static function native_toggle_later_phases() {
		$cases = array();
		foreach ( array(
			'staging'      => 'transferring',
			'transferring' => 'running',
			'running'      => 'verifying',
			'verifying'    => 'completed',
		) as $phase => $next ) {
			foreach ( array( false, true ) as $enabled ) {
				$cases[ $phase . '-' . (int) $enabled ] = array( $phase, $next, $enabled );
			}
		}
		return $cases;
	}

	/**
	 * These fixtures represent the safety task and handle preflight already created.
	 *
	 * @param array $checkpoint Checkpoint overrides.
	 * @param array $extra Extra row columns.
	 * @return object
	 */
	private function make_owned_safety_job( $checkpoint = array(), $extra = array() ) {
		$job = $this->make_job( $checkpoint, MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array_merge( array( 'pre_snapshot_task_id' => 'disabled-safety' ), $extra ) );
		MainWP_DB::instance()->update_website_option(
			$this->site_id,
			MainWP_Backup_Executor::ADHOC_TASK_OPTION,
			wp_json_encode(
				array(
					'task_id' => 'disabled-safety',
					'owner'   => (string) $job->id,
				)
			)
		);
		return $job;
	}

	/**
	 * All early-toggle refusals must free the site's reservation without entering backoff.
	 *
	 * @param object $job Fresh job row.
	 */
	private function assert_native_disabled_failure( $job ) {
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'Native backups were turned off, so this restore stopped before changing the site.', $job->last_error );
		$this->assertSame( 'mainwp_restore_native_backups_disabled', $job->checkpoint['last_error_code'] );
		$this->assertSame( 0, (int) $job->attempt_count );
		$this->assertNull( $job->active_site_id );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id ) );
	}

	/**
	 * Terminal replies must retain the Child contract through either entry path.
	 *
	 * @dataProvider terminal_report_replies
	 * @param string $name Fixture suffix.
	 * @param bool   $reused Whether create returns an existing task.
	 */
	public function test_terminal_child_report_is_stored_before_status( $name, $reused ) {
		$payload = $this->restore_report_fixture( $name );
		$type    = array(
			'restore'       => 'full',
			'restore_files' => 'files',
			'restore_db'    => 'db',
		)[ $payload['type'] ];
		$report  = $payload['sub_state']['verification']['outcome'];
		$store   = MainWP_Backup_Restore_Job_Store::instance();
		$this->responses[ $reused ? 'restore_task_create' : 'restore_task_run' ] = $payload + array( 'reused' => true );
		$this->responses['restore_task_cancel']                                  = array( 'result' => 'not_found' );
		$job         = $this->make_job( array( 'sub_type' => $type ), $reused ? $store::STATUS_STAGING : $store::STATUS_RUNNING, $reused ? array() : array( 'child_task_id' => 'report-task' ) );
		$at_terminal = null;
		$observe     = static function ( $sql ) use ( $store, $job, &$at_terminal ) {
			if ( str_contains( $sql, "`status` = 'failed_permanent'" ) ) {
				$at_terminal = $store->get_job( (int) $job->id )->checkpoint['restore_outcome'] ?? null;
			}
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			$job = $this->tick( $job );
		} finally {
			remove_filter( 'query', $observe );
		}
		$this->assertSame( $report, $job->checkpoint['restore_outcome'] );
		$this->assertSame( 'report-task', $job->child_task_id );
		if ( 'completed' === $payload['phase'] ) {
			$this->assertSame( $store::STATUS_VERIFYING, $job->status );
			$this->assertSame( $payload['sub_state']['verification'], $job->checkpoint['verification'] );
			$job = $this->tick( $job );
			$this->assertSame( $store::STATUS_COMPLETED, $job->status );
		} else {
			$this->assertSame( $report, $at_terminal );
			$this->assertSame( $store::STATUS_FAILED_PERMANENT, $job->status );
			$this->assertSame( $payload['error'], $job->last_error );
			$this->assertSame( $payload['error_code'], $job->checkpoint['last_error_code'] );
		}
	}

	/**
	 * Each committed run/create fixture traverses both paths.
	 *
	 * @return array
	 */
	public static function terminal_report_replies() {
		$cases = array();
		foreach ( array( 'completed-full', 'completed-files-only', 'completed-db-only', 'failed-before-any-change', 'failed-mid-files', 'failed-after-files-before-swap', 'failed-after-swap', 'fk-rolled-back', 'fk-rollback-failed' ) as $name ) {
			foreach ( array( false, true ) as $reused ) {
				$cases[ $name . '-' . (int) $reused ] = array( $name, $reused );
			}
		}
		return $cases;
	}

	/**
	 * Task identity placeholders belong to the transport fixture, not the job.
	 *
	 * @param string $name Fixture suffix.
	 * @return array
	 */
	private function restore_report_fixture( $name ) {
		return json_decode( str_replace( '<task_id>', 'report-task', file_get_contents( __DIR__ . '/fixtures/child-restore-outcome/restore-outcome-' . $name . '.json' ) ), true );
	}

	/**
	 * Browser and worker cancellation must retain the same terminal evidence.
	 *
	 * @dataProvider cancelled_report_replies
	 * @param string $name Fixture suffix.
	 * @param string $lane Cancellation driver.
	 */
	public function test_cancel_report_is_atomic_in_each_lane( $name, $lane ) {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$payload                                = $this->restore_report_fixture( $name );
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job(
			array(
				'sub_type'      => 'full',
				'cancel_intent' => 'worker' === $lane,
			),
			$store::STATUS_RUNNING,
			array( 'child_task_id' => 'report-task' )
		);
		$this->responses['restore_task_cancel'] = $payload;
		$atomic                                 = false;
		$observe                                = static function ( $sql ) use ( &$atomic ) {
			if ( str_contains( $sql, "`status` = 'cancelled'" ) ) {
				$atomic = str_contains( $sql, 'restore_outcome' ) && str_contains( $sql, 'child_failure_code' );
			}
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			if ( 'worker' === $lane ) {
				$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
			} elseif ( 'ajax' === $lane ) {
				$this->assertSame( array( 'result' => 'ok' ), $this->post_ajax( 'mainwp_restore_cancel', array( 'job_id' => $job->id ) ) );
			} else {
				$result = \MainWP\Dashboard\MainWP_Abilities_Backups::execute_cancel_restore(
					array(
						'restore_job_id' => (int) $job->id,
						'confirm'        => true,
					)
				);
				$this->assertSame( 'cancelled', $result['result'] );
			}
		} finally {
			remove_filter( 'query', $observe );
		}
		$job = $store->get_job( (int) $job->id );
		$this->assertTrue( $atomic );
		$this->assertSame( $store::STATUS_CANCELLED, $job->status );
		$this->assertSame( $payload['verification']['outcome'], $job->checkpoint['restore_outcome'] );
		$this->assertSame( 'mainwp_restore_cancelled', $job->checkpoint['child_failure_code'] );
		$this->assertEmpty( $job->checkpoint['last_error_code'] ?? '' );
		$this->assertSame( '', $job->last_error );
		$this->assertNull( \MainWP\Dashboard\MainWP_Backup_Read_Model::restore_job_details( (int) $job->id )['job']['error'] );
		$this->assertSame( array( 'restore_task_cancel' ), array_column( $this->calls, 'what' ) );
	}

	/**
	 * All cancel fixtures are shared by the three public drivers.
	 *
	 * @return array
	 */
	public static function cancelled_report_replies() {
		$cases = array();
		foreach ( array( 'cancelled-before-any-change', 'cancelled-mid-files', 'cancelled-after-files-before-swap', 'cancelled-after-swap' ) as $name ) {
			foreach ( array( 'ajax', 'worker', 'ability' ) as $lane ) {
				$cases[ $name . '-' . $lane ] = array( $name, $lane );
			}
		}
		return $cases;
	}

	/**
	 * Site-state failure codes change presentation without replacing failure text.
	 *
	 * @dataProvider cancel_failure_report_codes
	 * @param string      $code Child code.
	 * @param string|null $error Dashboard failure, or a user cancel.
	 * @param string      $expected Stored display code.
	 */
	public function test_cancel_report_failure_code_precedence( $code, $error, $expected ) {
		$payload                                = $this->restore_report_fixture( 'cancelled-after-swap' );
		$payload['result']                      = 'already_terminal';
		$payload['failure']['code']             = $code;
		$this->responses['restore_task_cancel'] = $payload;
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job( array(), $store::STATUS_RUNNING, array( 'child_task_id' => 'report-task' ) );
		$call                                   = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'record_terminal_failure' );
		$call->invoke( $this->orchestrator, $job, $error, null === $error ? '' : 'mainwp_restore_failed', null );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( null === $error ? 'cancelled' : 'failed_permanent', $job->status );
		$this->assertSame( $expected, $job->checkpoint['last_error_code'] ?? '' );
		$this->assertSame( $expected, \MainWP\Dashboard\MainWP_Backup_Read_Model::get_restore_job( (int) $job->id )['error_code'] );
		$this->assertSame( $code, $job->checkpoint['child_failure_code'] );
		$this->assertSame( $error ?? '', $job->last_error );
	}

	/**
	 * Only recovery-specific codes replace a Dashboard failure code.
	 *
	 * @return array
	 */
	public static function cancel_failure_report_codes() {
		return array(
			'user rollback failed' => array( 'mainwp_restore_rollback_failed', null, 'mainwp_restore_rollback_failed' ),
			'user rolled back'     => array( 'mainwp_restore_rolled_back', null, 'mainwp_restore_rolled_back' ),
			'user other failure'   => array( 'mainwp_restore_db_replay', null, 'mainwp_restore_db_replay' ),
			'user plain cancel'    => array( 'mainwp_restore_cancelled', null, '' ),
			'failure rollback bad' => array( 'mainwp_restore_rollback_failed', 'Original failure', 'mainwp_restore_rollback_failed' ),
			'failure rollback ok'  => array( 'mainwp_restore_rolled_back', 'Original failure', 'mainwp_restore_rolled_back' ),
			'failure other'        => array( 'mainwp_restore_db_replay', 'Original failure', 'mainwp_restore_failed' ),
		);
	}

	/**
	 * A replacement task cannot inherit another task's cancellation evidence.
	 *
	 * @dataProvider cancel_report_task_binding_cases
	 * @param bool        $replaced Whether the task changes during cancellation.
	 * @param string|null $error Dashboard failure, or a user cancel.
	 * @param string      $child_code Child failure code.
	 */
	public function test_cancel_report_is_bound_to_decided_child_task( $replaced, $error, $child_code ) {
		$store   = MainWP_Backup_Restore_Job_Store::instance();
		$job     = $this->make_job( array( 'sub_type' => 'full' ), $store::STATUS_RUNNING, array( 'child_task_id' => 'report-task' ) );
		$payload = $this->restore_report_fixture( 'failed-before-any-change' );
		$report  = $payload['sub_state']['verification']['outcome'];

		$this->responses['restore_task_cancel'] = array(
			array(
				'result'       => 'already_terminal',
				'phase'        => 'failed',
				'verification' => array( 'outcome' => $report ),
				'failure'      => array( 'code' => $child_code ),
			),
			array( 'result' => 'ok' ),
		);

		$landed  = false;
		$replace = static function ( $pre, $website, $what, $params ) use ( $store, $job, $replaced, &$landed ) {
			if ( $replaced && 'restore_task_cancel' === $what && 'report-task' === ( $params['task_id'] ?? '' ) ) {
				$landed = $store->set_status( (int) $job->id, $store::STATUS_RUNNING, array( 'child_task_id' => 'replacement-task' ) );
			}
			return $pre;
		};
		$events  = array();
		$observe = static function ( $event ) use ( &$events ) {
			$events[] = $event;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $replace, 20, 4 );
		add_action( 'mainwp_backup_restore_failed', $observe );
		try {
			$call = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'record_terminal_failure' );
			$this->assertSame( MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, $call->invoke( $this->orchestrator, $job, $error, 'mainwp_restore_failed', null ) );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $replace, 20 );
			remove_action( 'mainwp_backup_restore_failed', $observe );
		}
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $replaced, $landed );
		$this->assertSame( $replaced ? 'replacement-task' : 'report-task', $job->child_task_id );
		$this->assertSame( null === $error ? 'cancelled' : 'failed_permanent', $job->status );
		$this->assertSame( 'confirmed', $job->checkpoint['cancel_confirmation']['outcome'] );
		$this->assertSame( $error ?? '', $job->last_error );
		$code = $replaced ? ( null === $error ? '' : 'mainwp_restore_failed' ) : $child_code;
		$this->assertSame( $code, $job->checkpoint['last_error_code'] ?? '' );
		if ( $replaced ) {
			$this->assertArrayNotHasKey( 'restore_outcome', $job->checkpoint );
			$this->assertArrayNotHasKey( 'child_failure_code', $job->checkpoint );
		} else {
			$this->assertSame( $report, $job->checkpoint['restore_outcome'] );
			$this->assertSame( $child_code, $job->checkpoint['child_failure_code'] );
		}
		$detail = \MainWP\Dashboard\MainWP_Backup_Read_Model::restore_job_details( (int) $job->id );
		$this->assertSame( $replaced ? 'possible' : 'none', $detail['partial_state_risk'] );
		$this->assertSame( $replaced ? array( 'child_apply_started', 'no_files_rollback', 'db_applied_after_files' ) : array(), $detail['partial_state_reasons'] );
		$this->assertSame( $replaced ? array( 'restore_task_cancel', 'restore_task_cancel' ) : array( 'restore_task_cancel' ), array_column( $this->calls, 'what' ) );
		$this->assertSame( array( 'task_id' => 'report-task' ), $this->calls[0]['params'] );
		if ( $replaced ) {
			$this->assertSame( array( 'task_id' => 'replacement-task' ), $this->calls[1]['params'] );
		}
		if ( null !== $error ) {
			$this->assertCount( 1, $events );
			$this->assertSame( $code, $events[0]['error_code'] );
		}
	}

	/**
	 * Both recovery codes and user cancellations remain bound to their task.
	 *
	 * @return array
	 */
	public static function cancel_report_task_binding_cases() {
		$cases = array();
		foreach ( array( false, true ) as $replaced ) {
			foreach ( array( null, 'Dashboard failure' ) as $error ) {
				foreach ( array( 'mainwp_restore_rolled_back', 'mainwp_restore_rollback_failed' ) as $code ) {
					$cases[] = array( $replaced, $error, $code );
				}
			}
		}
		return $cases;
	}

	/**
	 * Old terminal answers require one status read only when the task id is known.
	 *
	 * @dataProvider cancel_status_report_cases
	 * @param string $result Cancel result.
	 * @param string $phase Child phase.
	 * @param bool   $child Task id is recorded.
	 * @param string $status_reply Status reply variant.
	 * @param bool   $fetch Whether status is requested.
	 */
	public function test_cancel_status_report_fallback( $result, $phase, $child, $status_reply, $fetch ) {
		$store      = MainWP_Backup_Restore_Job_Store::instance();
		$checkpoint = array( 'child_create_attempted' => true );
		if ( 'error envelope' === $status_reply ) {
			$checkpoint['last_error_code'] = 'mainwp_restore_failed';
		}
		$job = $this->make_job( $checkpoint, $store::STATUS_RUNNING, array( 'child_task_id' => $child ? 'report-task' : '' ) );

		$this->responses['restore_task_cancel'] = array(
			'result' => $result,
			'phase'  => $phase,
		);
		$status                                 = $this->restore_report_fixture( 'failed-after-swap' );
		unset( $status['error_code'] );
		if ( 'no report' === $status_reply ) {
			unset( $status['sub_state']['verification'] );
		} elseif ( 'throws' === $status_reply ) {
			$status = new \RuntimeException( 'Status unavailable.' );
		} elseif ( 'empty' === $status_reply ) {
			$status = false;
		} elseif ( 'error envelope' === $status_reply ) {
			$status = array(
				'error'      => 'Restore task not found.',
				'error_code' => 'mainwp_restore_task_missing',
			);
		}
		$this->responses['restore_task_status'] = $status;
		if ( 'completed' === $phase ) {
			$this->expectException( MainWP_Exception::class );
		}
		try {
			$this->orchestrator->cancel_job( (int) $job->id );
		} finally {
			$this->assertSame( $fetch ? array( 'restore_task_cancel', 'restore_task_status' ) : array( 'restore_task_cancel' ), array_column( $this->calls, 'what' ) );
			$job = $store->get_job( (int) $job->id );
			if ( $fetch ) {
				$this->assertSame( array( 'task_id' => 'report-task' ), $this->calls[1]['params'] );
			}
			$usable = $fetch && in_array( $status_reply, array( 'report', 'no report' ), true );
			$this->assertSame( $usable, isset( $job->checkpoint['child_failure_code'] ) );
			$this->assertSame( $usable && 'report' === $status_reply, isset( $job->checkpoint['restore_outcome'] ) );
			if ( $usable ) {
				$this->assertSame( 'mainwp_restore_db_replay', $job->checkpoint['child_failure_code'] );
			}
			if ( 'error envelope' === $status_reply ) {
				$this->assertSame( 'mainwp_restore_failed', $job->checkpoint['last_error_code'] );
			}
			if ( 'completed' !== $phase ) {
				$this->assertSame( 'cancelled', $job->status );
				$this->assertSame( 'confirmed', $job->checkpoint['cancel_confirmation']['outcome'] );
			}
		}
	}

	/**
	 * Completed and absent tasks never trigger the fallback read.
	 *
	 * @return array
	 */
	public static function cancel_status_report_cases() {
		return array(
			'ok new status'      => array( 'ok', 'failed', true, 'report', true ),
			'already failed'     => array( 'already_terminal', 'failed', true, 'report', true ),
			'old status'         => array( 'ok', 'failed', true, 'no report', true ),
			'unreachable status' => array( 'ok', 'failed', true, 'throws', true ),
			'empty status'       => array( 'ok', 'failed', true, 'empty', true ),
			'error-only status'  => array( 'ok', 'failed', true, 'error envelope', true ),
			'already completed'  => array( 'already_terminal', 'completed', true, 'report', false ),
			'absent task'        => array( 'not_found', '', true, 'report', false ),
			'lost create'        => array( 'ok', 'failed', false, 'report', false ),
		);
	}

	/**
	 * A task id discovered during cancel must contribute the second answer's evidence.
	 */
	public function test_cancel_by_restore_id_keeps_second_answer_report() {
		global $wpdb;
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job( array( 'child_create_attempted' => true ), $store::STATUS_RUNNING );
		$payload                                = $this->restore_report_fixture( 'cancelled-after-swap' );
		$this->responses['restore_task_cancel'] = array( array( 'result' => 'not_found' ), $payload );
		$record                                 = static function ( $pre, $website, $what, $params ) use ( $wpdb, $job ) {
			if ( 'restore_task_cancel' === $what && isset( $params['restore_id'] ) ) {
				$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'child_task_id' => 'report-task' ), array( 'id' => $job->id ) );
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $record, 20, 4 );
		try {
			$this->assertSame( 'cancelled', $this->orchestrator->cancel_job( (int) $job->id ) );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $record, 20 );
		}
		$this->assertSame( array( 'restore_task_cancel', 'restore_task_cancel' ), array_column( $this->calls, 'what' ) );
		$this->assertSame( array( 'restore_id' => 'orch01test' ), $this->calls[0]['params'] );
		$this->assertSame( array( 'task_id' => 'report-task' ), $this->calls[1]['params'] );
		$this->assertSame( $payload['verification']['outcome'], $store->get_job( (int) $job->id )->checkpoint['restore_outcome'] );
	}

	/**
	 * Deferred answers cannot be mistaken for terminal evidence.
	 *
	 * @dataProvider deferred_report_replies
	 * @param string $result Child result.
	 */
	public function test_deferred_cancel_report_waits_for_terminal_answer( $result ) {
		$payload                                = $this->restore_report_fixture( 'cancelled-mid-files' );
		$this->responses['restore_task_cancel'] = array( array( 'result' => $result ), $payload );
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job( array(), $store::STATUS_RUNNING, array( 'child_task_id' => 'report-task' ) );
		$this->assertSame( 'cancel_pending', $this->orchestrator->cancel_job( (int) $job->id ) );
		$job = $store->get_job( (int) $job->id );
		$this->assertArrayNotHasKey( 'restore_outcome', $job->checkpoint );
		$this->assertArrayNotHasKey( 'child_failure_code', $job->checkpoint );
		$this->assertSame( 'cancelled', $this->orchestrator->cancel_job( (int) $job->id ) );
		$this->assertSame( $payload['verification']['outcome'], $store->get_job( (int) $job->id )->checkpoint['restore_outcome'] );
	}

	/**
	 * Both deferred result strings retain their meaning.
	 *
	 * @return array
	 */
	public static function deferred_report_replies() {
		return array( array( 'busy' ), array( 'rolling_back' ) );
	}

	/**
	 * Fully applied reports survive both ways Dashboard verification can end.
	 *
	 * @dataProvider verification_report_endings
	 * @param bool $stop Whether the user stops checking.
	 */
	public function test_applied_report_survives_verification_failure( $stop ) {
		$report                       = $this->restore_report_fixture( 'completed-full' )['sub_state']['verification']['outcome'];
		$store                        = MainWP_Backup_Restore_Job_Store::instance();
		$job                          = $this->make_job(
			$this->safety_checkpoint() + array(
				'sub_type'        => 'full',
				'restore_outcome' => $report,
				'resume_status'   => 'verifying',
			),
			$stop ? $store::STATUS_FAILED_RETRYABLE : $store::STATUS_VERIFYING,
			array(
				'child_task_id' => 'report-task',
				'attempt_count' => MainWP_Backup_Backoff::MAX_ATTEMPTS - 1,
				'last_error'    => 'Site unreachable.',
			)
		);
		$this->orchestrator->responds = false;
		if ( $stop ) {
			$this->assertSame( 'stopped', $this->orchestrator->cancel_job( (int) $job->id ) );
		} else {
			$this->tick( $job );
		}
		$job    = $store->get_job( (int) $job->id );
		$detail = \MainWP\Dashboard\MainWP_Backup_Read_Model::restore_job_details( (int) $job->id );
		$this->assertSame( 'failed_permanent', $job->status );
		$this->assertSame( $stop, ! empty( $job->checkpoint['verify_stopped'] ) );
		$this->assertSame( 'none', $detail['partial_state_risk'] );
		$this->assertSame( array(), $detail['partial_state_reasons'] );
		$this->assertTrue( $detail['job']['rollback_available'] );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Ending checks and exhausting retries share the same site-state reading.
	 *
	 * @return array
	 */
	public static function verification_report_endings() {
		return array( array( true ), array( false ) );
	}

	/**
	 * Transport failures have no report until the same task later answers.
	 */
	public function test_transport_retry_stores_only_the_later_report() {
		$store                               = MainWP_Backup_Restore_Job_Store::instance();
		$job                                 = $this->make_job( array(), $store::STATUS_RUNNING, array( 'child_task_id' => 'report-task' ) );
		$this->responses['restore_task_run'] = array( new \RuntimeException( 'Transport unavailable.' ), $this->restore_report_fixture( 'completed-db-only' ) );
		// The driver, not run_job(), turns a thrown transport error into a retry.
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertArrayNotHasKey( 'restore_outcome', $job->checkpoint );
		$job = $this->tick( $job );
		$this->assertSame( $store::STATUS_VERIFYING, $job->status );
		$this->assertSame( $this->restore_report_fixture( 'completed-db-only' )['sub_state']['verification']['outcome'], $job->checkpoint['restore_outcome'] );
		$this->assertSame( array( 'restore_task_run', 'restore_task_run' ), array_column( $this->calls, 'what' ) );
	}

	/**
	 * Capability refresh must clear a flag when an older Child omits it.
	 */
	public function test_refresh_stores_restore_outcome_capability() {
		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		foreach ( array( true, false ) as $supported ) {
			$this->responses['backup_preflight'] = $supported ? array(
				'restore_outcome_report'       => 1,
				'reconcile_unarchived_support' => 1,
			) : array();
			$this->assertTrue( MainWP_Backup_Monitor::refresh_negotiated_capabilities( $website ) );
			$this->assertSame( $supported ? '1' : '0', MainWP_DB::instance()->get_website_option( $this->site_id, 'restore_outcome_report' ) );
			$this->assertSame( $supported ? '1' : '0', MainWP_DB::instance()->get_website_option( $this->site_id, 'reconcile_unarchived_support' ) );
		}
	}


	/**
	 * Reclaimed workers cannot persist evidence after the Child round trip.
	 *
	 * @dataProvider lost_report_lease_cases
	 * @param string $name Fixture suffix.
	 */
	public function test_report_checkpoint_refuses_reclaimed_worker( $name ) {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array(), $store::STATUS_RUNNING, array( 'child_task_id' => 'report-task' ) );
		$job   = $store->acquire_next_job( 'report-old', 90, time(), (int) $job->id );
		$token = new \ReflectionProperty( MainWP_Backup_Restore_Orchestrator::class, 'lock_token' );
		$token->setValue( $this->orchestrator, 'report-old' );
		$this->responses['restore_task_run'] = $this->restore_report_fixture( $name );
		$table                               = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$stolen                              = false;
		$steal                               = function ( $sql ) use ( $wpdb, $table, $job, &$steal, &$stolen ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET checkpoint' ) && str_contains( $sql, 'restore_outcome' ) ) {
				remove_filter( 'query', $steal );
				$wpdb->update(
					$table,
					array(
						'locked_by'    => 'report-new',
						'locked_until' => time() + 90,
					),
					array( 'id' => $job->id )
				);
				$stolen = true;
			}
			return $sql;
		};
		add_filter( 'query', $steal );
		try {
			$fresh = $this->tick( $job );
		} finally {
			remove_filter( 'query', $steal );
			$token->setValue( $this->orchestrator, null );
		}
		$this->assertTrue( $stolen );
		$this->assertSame( 'report-new', $fresh->locked_by );
		$this->assertSame( $job->checkpoint, $fresh->checkpoint );
		$this->assertSame( $job->checkpoint_rev, $fresh->checkpoint_rev );
		$this->assertSame( $store::STATUS_RUNNING, $fresh->status );
		$this->assertSame( array( 'restore_task_run' ), array_column( $this->calls, 'what' ) );
	}

	/**
	 * Both success and failure evidence use a lease-fenced save.
	 *
	 * @return array
	 */
	public static function lost_report_lease_cases() {
		return array( array( 'completed-db-only' ), array( 'failed-after-swap' ) );
	}

	/**
	 * A lease lost at either prune boundary cannot authorize a Child request.
	 *
	 * @dataProvider prune_report_lease_cases
	 * @param string $loss Lease loss point.
	 */
	public function test_prune_checks_calling_lease_at_both_boundaries( $loss ) {
		global $wpdb;
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$source = $this->make_job( $this->safety_checkpoint(), $store::STATUS_COMPLETED, array( 'pre_snapshot_task_id' => 'safety-task' ) );
		$held   = $this->make_job( array(), $store::STATUS_VERIFYING );
		$held   = $store->acquire_next_job( 'prune-old', 90, time(), (int) $held->id );
		$token  = new \ReflectionProperty( MainWP_Backup_Restore_Orchestrator::class, 'lock_token' );
		$token->setValue( $this->orchestrator, 'prune-old' );
		$table                                = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$count                                = 0;
		$steal                                = function ( $sql ) use ( $wpdb, $table, $held, $loss, &$count, &$steal ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET locked_until' ) ) {
				++$count;
				if ( ( 'before mark' === $loss && 1 === $count ) || ( 'before request' === $loss && 2 === $count ) ) {
					remove_filter( 'query', $steal );
					$wpdb->update(
						$table,
						array(
							'locked_by'    => 'prune-new',
							'locked_until' => time() + 90,
						),
						array( 'id' => $held->id )
					);
				}
			}
			return $sql;
		};
		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );
		add_filter( 'query', $steal );
		try {
			$call   = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'prune_safety_backup' );
			$result = $call->invoke( $this->orchestrator, MainWP_DB::instance()->get_website_by_id( $this->site_id ), (int) $source->id, 'safety-task', $source->checkpoint, $held );
		} finally {
			remove_filter( 'query', $steal );
			$token->setValue( $this->orchestrator, null );
		}
		$fresh = $store->get_job( (int) $source->id );
		if ( 'held' === $loss ) {
			$this->assertTrue( $result );
			$this->assertTrue( $fresh->checkpoint['pre_snapshot_pruned'] );
			$this->assertSame( array( '["safety-task"]' ), $this->prune_requests() );
		} else {
			$this->assertNull( $result );
			$this->assertSame( array(), $this->prune_requests() );
			$this->assertArrayNotHasKey( 'pre_snapshot_pruned', $fresh->checkpoint );
			$this->assertSame( 'before request' === $loss, ! empty( $fresh->checkpoint['pre_snapshot_pruning'] ) );
			$this->assertSame( (int) $source->checkpoint_rev + ( 'before request' === $loss ? 1 : 0 ), (int) $fresh->checkpoint_rev );
		}
	}

	/**
	 * The mark intentionally survives a loss before sending the request.
	 *
	 * @return array
	 */
	public static function prune_report_lease_cases() {
		return array( array( 'before mark' ), array( 'before request' ), array( 'held' ) );
	}

	/**
	 * Poll and cron contenders cannot drive a second reply while the first holds the lease.
	 *
	 * @dataProvider lost_report_lease_cases
	 * @param string $name Child fixture suffix.
	 */
	public function test_poll_and_cron_share_one_report_write( $name ) {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array(), $store::STATUS_RUNNING, array( 'child_task_id' => 'report-task' ) );
		$job   = $store->acquire_next_job( 'report-holder', 90, time(), (int) $job->id );
		$token = new \ReflectionProperty( MainWP_Backup_Restore_Orchestrator::class, 'lock_token' );
		$token->setValue( $this->orchestrator, 'report-holder' );
		$payload                                = $this->restore_report_fixture( $name );
		$this->responses['restore_task_run']    = $payload;
		$this->responses['restore_task_cancel'] = array( 'result' => 'not_found' );
		$contended                              = false;
		$observe                                = function () use ( $job, &$contended ) {
			$contended = true;
			$other     = new Testable_Restore_Orchestrator();
			$other->process_pending_restore_jobs( 1, (int) $job->id );
			$other->process_pending_restore_jobs( 1 );
		};
		add_action( 'mainwp_backup_diag_restore_poll', $observe );
		try {
			$fresh = $this->tick( $job );
		} finally {
			remove_action( 'mainwp_backup_diag_restore_poll', $observe );
			$token->setValue( $this->orchestrator, null );
		}
		$this->assertTrue( $contended );
		$this->assertSame( $payload['sub_state']['verification']['outcome'], $fresh->checkpoint['restore_outcome'] );
		$this->assertSame( 1, array_count_values( array_column( $this->calls, 'what' ) )['restore_task_run'] );
		$this->assertSame( 'completed' === $payload['phase'] ? 'verifying' : 'failed_permanent', $fresh->status );
	}

	/**
	 * A failed pull report cannot describe a fresh standard-transfer task.
	 */
	public function test_cloud_pull_fallback_clears_restore_report() {
		$store                               = MainWP_Backup_Restore_Job_Store::instance();
		$job                                 = $this->make_job(
			array(
				'restore_outcome'    => array( 'files' => 'weird' ),
				'child_failure_code' => 'mainwp_cloud_pull_failed',
			),
			$store::STATUS_RUNNING,
			array( 'child_task_id' => 'report-task' )
		);
		$payload                             = $this->restore_report_fixture( 'failed-before-any-change' );
		$payload['error_code']               = 'mainwp_cloud_pull_failed';
		$this->responses['restore_task_run'] = $payload;
		$job                                 = $this->tick( $job );
		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertArrayNotHasKey( 'restore_outcome', $job->checkpoint );
		$this->assertArrayNotHasKey( 'child_failure_code', $job->checkpoint );
		$this->assertSame( '', $job->child_task_id );
	}

	/**
	 * Unknown values remain visible, but nested or oversized evidence is bounded.
	 */
	public function test_restore_report_storage_bounds_untrusted_values() {
		$call     = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'restore_report_evidence' );
		$report   = array(
			'files'            => str_repeat( 'x', 80 ),
			'database'         => 'weird',
			'files_copy_taken' => false,
			'phase_reached'    => array( 'nested' ),
			'extra'            => 'discard',
		);
		$expected = array(
			'restore_outcome' => array(
				'files'            => str_repeat( 'x', 64 ),
				'database'         => 'weird',
				'files_copy_taken' => false,
			),
		);
		$this->assertSame( $expected, $call->invoke( $this->orchestrator, array( 'outcome' => $report ) ) );
		$report['database']      = INF;
		$report['phase_reached'] = 17;
		unset( $expected['restore_outcome']['database'] );
		$evidence = $call->invoke( $this->orchestrator, array( 'outcome' => $report ) );
		$this->assertSame( $expected, $evidence );
		$job        = $this->make_job();
		$checkpoint = array_replace( $job->checkpoint, $evidence );
		$store      = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->save_checkpoint( (int) $job->id, $checkpoint ) );
		$this->assertSame( $checkpoint, $store->get_job( (int) $job->id )->checkpoint );
		foreach ( array( null, false, 'bad', 1 ) as $value ) {
			$this->assertSame( array( 'restore_outcome' => 'malformed' ), $call->invoke( $this->orchestrator, array( 'outcome' => $value ) ) );
		}
		$this->assertSame( array(), $call->invoke( $this->orchestrator, array() ) );
		foreach ( array( 'mainwp_BAD', 'bad', 'mainwp_a\nb', 'mainwp_' . str_repeat( 'a', 60 ), array(), 4 ) as $code ) {
			$this->assertSame( array(), $call->invoke( $this->orchestrator, array(), $code ) );
		}
		$this->assertSame( array( 'child_failure_code' => 'mainwp_restore_cancelled' ), $call->invoke( $this->orchestrator, array(), 'mainwp_restore_cancelled' ) );
	}


	/**
	 * Older archives can receive the same report from a newly updated Child.
	 */
	public function test_old_snapshot_restore_accepts_new_child_report() {
		$this->responses['backup_preflight']    = array( 'restore_create_idempotency' => 1 );
		$this->responses['restore_task_create'] = $this->restore_report_fixture( 'completed-db-only' ) + array( 'reused' => true );

		$store    = MainWP_Backup_Restore_Job_Store::instance();
		$readings = array();
		foreach ( array( false, true ) as $with_metadata ) {
			$archive = array(
				'file' => 'db.zip',
				'role' => 'db',
			);
			if ( $with_metadata ) {
				$archive['walk'] = array( 'unarchived' => array() );
			}
			$snapshot_id = $this->make_chain_snapshot( null, 'db', array( $archive ) );
			$job_id      = $this->orchestrator->initiate_restore( $this->site_id, $snapshot_id, array( 'confirm_destructive' => true ) );
			$job         = $store->get_job( $job_id );
			foreach ( array( 'preflight', 'staging', 'verifying', 'completed' ) as $status ) {
				$job = $this->tick( $job );
				$this->assertSame( $status, $job->status );
			}
			$detail     = \MainWP\Dashboard\MainWP_Backup_Read_Model::restore_job_details( $job_id );
			$readings[] = array( $detail['partial_state_risk'], $detail['partial_state_reasons'], $job->checkpoint['restore_outcome'] );
		}
		$this->assertSame( $readings[0], $readings[1] );
		$this->assertSame( array( 'none', array(), $this->restore_report_fixture( 'completed-db-only' )['sub_state']['verification']['outcome'] ), $readings[0] );
	}


	/**
	 * Decision tests predate the additive status read; report tests assert its exact calls.
	 *
	 * @return array Child actions other than final report reads.
	 */
	private function calls_without_report_reads() {
		return array_values( array_filter( array_column( $this->calls, 'what' ), static fn( $what ) => 'restore_task_status' !== $what ) );
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
	 * A provider outage must keep the staging phase available for a later retry.
	 */
	public function test_staging_provider_exception_schedules_retry() {
		$driver   = new class() extends MainWP_Backup_Restore_Transfer_Driver {
			/**
			 * Cloud providers throw when transport fails.
			 *
			 * @param int $snapshot_id Snapshot id.
			 * @return \MainWP\Dashboard\MainWP_Backup_Storage_Provider|null
			 */
			protected function provider_for_snapshot( int $snapshot_id ): ?\MainWP\Dashboard\MainWP_Backup_Storage_Provider {
				return new class( array() ) extends \MainWP\Dashboard\MainWP_Backup_Storage_Local {
					// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Transport fails before a bool can be returned.
					/**
					 * Credentials in transport errors must never reach stored state.
					 *
					 * @param string $key Object key.
					 * @param string $local_path Download target.
					 * @return bool
					 * @throws \RuntimeException When transport fails.
					 */
					public function get_object( string $key, string $local_path ): bool {
						throw new \RuntimeException( 'Transport failed with private provider details.' );
					}
					// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
				};
			}
		};
		$instance = new \ReflectionProperty( MainWP_Backup_Restore_Transfer_Driver::class, 'instance' );
		$original = $instance->getValue();
		$instance->setValue( null, $driver );
		$job                                 = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$checkpoint                          = $job->checkpoint;
		$checkpoint['plan'][0]['source']     = 'remote';
		$checkpoint['plan'][0]['remote_key'] = '4/5/files/db.zip';
		MainWP_Backup_Restore_Job_Store::instance()->save_checkpoint( (int) $job->id, $checkpoint );
		remove_filter( 'mainwp_backup_retry_delay', '__return_zero' );
		try {
			$job = $this->tick( $job );
		} finally {
			$instance->setValue( null, $original );
		}
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->checkpoint['resume_status'] );
		$this->assertSame( 1, (int) $job->attempt_count );
		$this->assertGreaterThan( time(), (int) $job->next_retry_at );
		$this->assertStringNotContainsString( 'private provider details', $job->last_error . wp_json_encode( $job->checkpoint ) );
		$this->assertArrayNotHasKey( 'retryable', $job->checkpoint['plan'][0] );
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
		$this->assertSame( 1, array_count_values( $this->calls_without_report_reads() )['restore_task_create'] );

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
		$this->assertSame( array( 'restore_task_create', 'restore_task_create' ), $this->calls_without_report_reads() );
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
		$this->assertSame( 1, array_count_values( $this->calls_without_report_reads() )['restore_task_create'] );
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
		$this->assertArrayNotHasKey( 'child_create_attempted', $job->checkpoint );
		$this->assertSame( str_repeat( 'a', 64 ), $job->checkpoint['plan'][0]['sha256'] );
		$this->assertSame( array(), $job->checkpoint['transfer'] );
		$this->assertEmpty( $job->last_error );
		$this->assertSame( array( 'restore_task_create' ), $this->calls_without_report_reads() );
		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->status );
		$this->assertSame( 'b14-standard-transfer', $job->child_task_id );
		$this->assertTrue( $job->checkpoint['child_create_attempted'] );
		$this->assertNotSame( 'b14-pull-failed', $job->child_task_id );
		$creates = array_values( array_filter( $this->calls, static fn( $call ) => 'restore_task_create' === $call['what'] ) );
		$this->assertCount( 2, $creates );
		$this->assertNotSame( $creates[0]['params']['restore_id'], $creates[1]['params']['restore_id'] );
	}

	/**
	 * The fallback identity has not reached the Child until its own create.
	 */
	public function test_cloud_pull_fallback_clears_attempt_marker_before_terminal_failure() {
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job( array( 'child_create_attempted' => true ), $store::STATUS_RUNNING, array( 'child_task_id' => 'b14-pull-failed' ) );
		$this->responses['restore_task_run']    = array(
			'task_id'    => 'b14-pull-failed',
			'phase'      => 'failed',
			'error_code' => 'mainwp_cloud_pull_failed',
		);
		$this->responses['restore_task_cancel'] = new MainWP_Exception( 'Site unreachable.' );

		$job = $this->tick( $job );
		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertSame( '', $job->child_task_id );
		$this->assertTrue( $job->checkpoint['cloud_pull_tried'] );
		$this->assertNotEmpty( $job->checkpoint['restore_id'] );
		$this->assertNotSame( 'orch01test', $job->checkpoint['restore_id'] );
		$this->assertArrayNotHasKey( 'child_create_attempted', $job->checkpoint );

		$record = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'record_terminal_failure' );
		$this->assertSame( MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, $record->invoke( $this->orchestrator, $job, 'Fallback staging failed.', 'test_restore_failure', null ) );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'Fallback staging failed.', $job->last_error );
		$this->assertSame( array( 'restore_task_run' ), $this->calls_without_report_reads() );
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
		$this->assertSame( array( 'restore_task_run' ), $this->calls_without_report_reads() );
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
		$this->assertSame( 1, array_count_values( $this->calls_without_report_reads() )['restore_task_create'] );
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
		$this->assertSame( 5, array_count_values( $this->calls_without_report_reads() )['restore_task_create'] );

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
		$this->assertSame( array( 'restore_task_create', 'restore_task_cancel' ), $this->calls_without_report_reads() );
		$this->assertSame( array( 'restore_id' => $this->calls[0]['params']['restore_id'] ), $this->calls[1]['params'] );
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

		$job_id = $this->orchestrator->initiate_restore( $this->site_id, $inc2_id, array( 'confirm_destructive' => true ) );
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
	 * Probe cache settings must preserve the site's URL and HTTP defaults.
	 *
	 * @dataProvider mwp1908_probe_urls
	 * @param string $url Site URL.
	 */
	public function test_mwp1908_probe_bypasses_cache_and_preserves_url( $url ) {
		$requests = array();
		$http     = static function ( $pre, $args, $request_url ) use ( &$requests ) {
			$requests[] = array( $request_url, $args );
			return array( 'response' => array( 'code' => 200 ) );
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		$before = (int) ( microtime( true ) * 1000000 );
		$probe  = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'site_responds' );
		try {
			$this->assertTrue(
				$probe->invoke(
					new MainWP_Backup_Restore_Orchestrator(),
					(object) array(
						'url'                => $url,
						'verify_certificate' => 1,
					)
				)
			);
		} finally {
			remove_filter( 'pre_http_request', $http );
		}
		$this->assertCount( 1, $requests );
		list( $request_url, $args ) = $requests[0];
		parse_str( (string) wp_parse_url( $request_url, PHP_URL_QUERY ), $query );
		$this->assertArrayHasKey( 'mots', $query );
		$this->assertMatchesRegularExpression( '/^\d+$/', $query['mots'] );
		$this->assertGreaterThanOrEqual( $before, (int) $query['mots'] );
		$this->assertLessThanOrEqual( (int) ( microtime( true ) * 1000000 ), (int) $query['mots'] );
		$this->assertSame( $url, remove_query_arg( 'mots', $request_url ) );
		$this->assertSame( 'no-cache, no-store, max-age=0', $args['headers']['Cache-Control'] );
		$this->assertSame( 'no-cache', $args['headers']['Pragma'] );
		$this->assertSame( 15, $args['timeout'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 5, $args['redirection'] );
	}

	/**
	 * Site URL variants.
	 *
	 * @return array
	 */
	public static function mwp1908_probe_urls() {
		return array(
			'query'        => array( 'https://example.org/?language=en&preview=1' ),
			'subdirectory' => array( 'https://example.org/wordpress/site/?language=en' ),
		);
	}

	/**
	 * The public reachability contract remains HTTP 200 through 499.
	 *
	 * @dataProvider mwp1908_probe_statuses
	 * @param int|false $status HTTP code, or false for a transport failure.
	 * @param bool      $expected Expected reachability.
	 */
	public function test_mwp1908_probe_status_boundaries( $status, $expected ) {
		$http = static function () use ( $status ) {
			return false === $status ? new \WP_Error( 'transport', 'Offline.' ) : array( 'response' => array( 'code' => $status ) );
		};
		add_filter( 'pre_http_request', $http );
		$probe = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'site_responds' );
		try {
			$this->assertSame( $expected, $probe->invoke( new MainWP_Backup_Restore_Orchestrator(), (object) array( 'url' => 'https://example.org/' ) ) );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}
	}

	/**
	 * Boundary and transport outcomes.
	 *
	 * @return array
	 */
	public static function mwp1908_probe_statuses() {
		return array(
			array( 199, false ),
			array( 200, true ),
			array( 499, true ),
			array( 500, false ),
			array( false, false ),
		);
	}

	/**
	 * Only a fresh capability answer can authorize the new reconcile metadata.
	 *
	 * @dataProvider mwp1908_reconcile_capabilities
	 * @param string|null $key Walk protection key.
	 * @param string      $capability Readiness response.
	 * @param bool        $reused Child completed on a replayed create.
	 */
	public function test_mwp1908_reconcile_requires_unarchived_capability( $key, $capability, $reused ) {
		$walk = array(
			'excludes'     => array(),
			'excludenonwp' => 0,
			'excludezip'   => 0,
		);
		if ( null !== $key ) {
			$walk[ $key ] = 'unarchived' === $key ? array( 'private/unreadable.php' ) : true;
		}
		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		if ( 'stale' === $capability ) {
			MainWP_DB::instance()->update_website_option( $website, 'reconcile_unarchived_support', '1' );
		}
		$this->responses['backup_preflight']    = in_array( $capability, array( 'error', 'stale' ), true )
			? new MainWP_Exception( 'Readiness unavailable.' )
			: ( 'supported' === $capability ? array( 'reconcile_unarchived_support' => 1 ) : array( 'zip_support' => 1 ) );
		$verification                           = array(
			'reconcile' => array(
				'status' => 'skipped',
				'reason' => 'no_manifest',
			),
		);
		$completed                              = array(
			'task_id'   => 'mwp1908',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => $verification ),
		);
		$this->responses['restore_task_create'] = $reused ? array_merge( $completed, array( 'reused' => true ) ) : array(
			'task_id' => 'mwp1908',
			'phase'   => 'queued',
		);
		$this->responses['restore_task_run']    = $completed;
		$path                                   = $this->dir . '/files.list';
		file_put_contents( $path, "index.php\t10\t100\n" );
		$job                    = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$entry                  = $job->checkpoint['plan'][0];
		$entry                  = array_merge(
			$entry,
			array(
				'role'       => 'manifest',
				'file'       => 's5-files.list',
				'local_path' => $path,
				'size'       => filesize( $path ),
				'walk'       => $walk,
			)
		);
		$checkpoint             = $job->checkpoint;
		$checkpoint['sub_type'] = 'files';
		$checkpoint['plan'][]   = $entry;
		MainWP_Backup_Restore_Job_Store::instance()->save_checkpoint( (int) $job->id, $checkpoint );
		$job     = $this->tick( $job );
		$creates = array_values( array_filter( $this->calls, static fn( $call ) => 'restore_task_create' === $call['what'] ) );
		$this->assertCount( 1, $creates );
		$options  = json_decode( $creates[0]['params']['options_json'], true );
		$withheld = null !== $key && 'supported' !== $capability;
		if ( $withheld ) {
			$this->assertArrayNotHasKey( 'reconcile', $options );
			$this->assertSame( 'unarchived_unsupported', $job->checkpoint['reconcile_withheld'] ?? null );
			if ( ! $reused ) {
				$this->assertArrayNotHasKey( 'reconcile', $job->checkpoint['verification'] ?? array() );
			}
		} else {
			$this->assertSame( $walk, $options['reconcile']['walk'] );
		}
		$probes = array_values( array_filter( $this->calls, static fn( $call ) => 'backup_preflight' === $call['what'] ) );
		$this->assertSame( null !== $key, ! empty( $probes ) );
		if ( null !== $key ) {
			$this->assertSame( array( 'capabilities_only' => 1 ), $probes[0]['params'] );
		}
		if ( $withheld ) {
			for ( $i = 0; $i < 4 && MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED !== $job->status; ++$i ) {
				$job = $this->tick( $job );
			}
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED, $job->status );
			$this->assertSame( 'unarchived_unsupported', $job->checkpoint['verification']['reconcile']['reason'] );
		}
	}

	/**
	 * Walk metadata, readiness and replay cases.
	 *
	 * @return array
	 */
	public static function mwp1908_reconcile_capabilities() {
		$cases = array();
		foreach ( array( 'unarchived', 'unarchived_overflow', null ) as $key ) {
			foreach ( array( 'supported', 'absent', 'error', 'stale' ) as $capability ) {
				foreach ( array( false, true ) as $reused ) {
					$cases[ ( $key ?? 'legacy' ) . '-' . $capability . '-' . (int) $reused ] = array( $key, $capability, $reused );
				}
			}
		}
		return $cases;
	}

	/**
	 * A stale permission must not authorize deletion after its replacement write fails.
	 */
	public function test_mwp1908_failed_capability_persistence_prevents_create() {
		global $wpdb;
		$this->set_site_option( $this->site_id, 'reconcile_unarchived_support', '1' );
		$this->responses['backup_preflight'] = array( 'zip_support' => 1 );
		$job                                 = $this->make_unarchived_restore_job();
		$table                               = MainWP_DB::instance()->get_table_name( 'wp_options' );
		$refused                             = 0;
		$refuse                              = static function ( $sql ) use ( $table, &$refused ) {
			if ( 0 === strpos( $sql, 'UPDATE `' . $table . '`' ) && false !== strpos( $sql, "'reconcile_unarchived_support'" ) ) {
				++$refused;
				return 'SELECT * FROM mainwp_test_no_such_table';
			}
			return $sql;
		};
		$suppress                            = $wpdb->suppress_errors( true );
		add_filter( 'query', $refuse );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
			$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		} finally {
			remove_filter( 'query', $refuse );
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertSame( 1, $refused );
		$this->assertSame( '1', MainWP_DB::instance()->get_website_option( $this->site_id, 'reconcile_unarchived_support' ) );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->checkpoint['resume_status'] );
		$this->assertStringContainsString( 'Could not save the child manifest capability.', $job->last_error );
		$this->assertNotContains( 'restore_task_create', $this->calls_without_report_reads() );
		$this->assertArrayNotHasKey( 'child_create_attempted', $job->checkpoint );
	}

	/**
	 * Replayed params cannot change the task created before a lost reply.
	 *
	 * @dataProvider mwp1908_reconcile_replays
	 * @param bool $first_supported First readiness result.
	 * @param bool $reused_completed Completion arrives in create rather than poll.
	 */
	public function test_mwp1908_reconcile_replay_keeps_child_outcome( $first_supported, $reused_completed ) {
		$job                                    = $this->make_unarchived_restore_job();
		$this->responses['backup_preflight']    = $first_supported ? array( 'reconcile_unarchived_support' => 1 ) : new MainWP_Exception( 'Readiness unavailable.' );
		$this->responses['restore_task_create'] = new MainWP_Exception( 'Create response lost.' );
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( '', $job->child_task_id );
		$first_create = array_values( array_filter( $this->calls, static fn( $call ) => 'restore_task_create' === $call['what'] ) );
		$this->assertCount( 1, $first_create );
		$this->assertSame( $first_supported, isset( json_decode( $first_create[0]['params']['options_json'], true )['reconcile'] ) );

		$reconcile                              = $first_supported ? array(
			'status'         => 'done',
			'removed'        => 3,
			'kept'           => 2,
			'kept_paths'     => array( 'wp-content/mu-plugins/host.php', 'private/unreadable.php' ),
			'kept_by_reason' => array(
				'mu_plugins' => 1,
				'unarchived' => 1,
			),
		) : array(
			'status' => 'skipped',
			'reason' => 'no_manifest',
		);
		$completed                              = array(
			'task_id'   => 'replayed-restore',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'reconcile' => $reconcile ) ),
		);
		$this->responses['backup_preflight']    = $first_supported ? new MainWP_Exception( 'Readiness unavailable.' ) : array( 'reconcile_unarchived_support' => 1 );
		$this->responses['restore_task_create'] = $reused_completed ? $completed + array( 'reused' => true ) : array(
			'task_id' => 'replayed-restore',
			'phase'   => 'reconciling',
			'reused'  => true,
		);
		$this->responses['restore_task_run']    = $completed;
		$job                                    = $this->tick( $job );
		if ( ! $reused_completed ) {
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $job->status );
			$job = $this->tick( $job );
		}
		$creates = array_values( array_filter( $this->calls, static fn( $call ) => 'restore_task_create' === $call['what'] ) );
		$this->assertCount( 2, $creates );
		$this->assertSame( $creates[0]['params']['restore_id'], $creates[1]['params']['restore_id'] );
		$this->assertSame( ! $first_supported, isset( json_decode( $creates[1]['params']['options_json'], true )['reconcile'] ) );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $job->status );
		$this->assertSame(
			$first_supported ? $reconcile : array(
				'status' => 'skipped',
				'reason' => 'unarchived_unsupported',
			),
			$job->checkpoint['verification']['reconcile']
		);
		$this->assertSame( 'unarchived_unsupported', $job->checkpoint['reconcile_withheld'] ?? null );
	}

	/**
	 * Both replay directions can complete through either response path.
	 *
	 * @return array
	 */
	public static function mwp1908_reconcile_replays() {
		return array(
			'sent then withheld, poll'   => array( true, false ),
			'sent then withheld, create' => array( true, true ),
			'withheld then sent, poll'   => array( false, false ),
			'withheld then sent, create' => array( false, true ),
		);
	}

	/**
	 * Only the missing-manifest result can explain an earlier withheld request.
	 *
	 * @dataProvider mwp1908_completed_reconcile_results
	 * @param bool       $withheld Whether reconcile is withheld.
	 * @param array|null $reconcile Child result, or an absent key.
	 * @param array|null $expected Stored result, or an absent key.
	 * @param bool       $reused_completed Completion arrives in create rather than poll.
	 */
	public function test_mwp1908_completed_reconcile_respects_child_result( $withheld, $reconcile, $expected, $reused_completed ) {
		$job                                 = $this->make_unarchived_restore_job();
		$this->responses['backup_preflight'] = $withheld ? array() : array( 'reconcile_unarchived_support' => 1 );
		$verification                        = array( 'files' => array( 'status' => 'done' ) );
		if ( null !== $reconcile ) {
			$verification['reconcile'] = $reconcile;
		}
		$completed                              = array(
			'task_id'   => 'completed-restore',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => $verification ),
		);
		$this->responses['restore_task_create'] = $reused_completed ? $completed + array( 'reused' => true ) : array(
			'task_id' => 'completed-restore',
			'phase'   => 'reconciling',
			'reused'  => true,
		);
		$this->responses['restore_task_run']    = $completed;
		$job                                    = $this->tick( $job );
		if ( ! $reused_completed ) {
			$job = $this->tick( $job );
		}
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $job->status );
		$this->assertSame( $expected, $job->checkpoint['verification']['reconcile'] ?? null );
		$this->assertSame( array( 'status' => 'done' ), $job->checkpoint['verification']['files'] );
	}

	/**
	 * Child outcomes that distinguish withholding from completed reconcile work.
	 *
	 * @return array
	 */
	public static function mwp1908_completed_reconcile_results() {
		$unsupported = array(
			'status' => 'skipped',
			'reason' => 'unarchived_unsupported',
		);
		$no_manifest = array(
			'status' => 'skipped',
			'reason' => 'no_manifest',
		);
		$other_skip  = array(
			'status' => 'skipped',
			'reason' => 'manifest_unverified',
		);
		$other_state = array(
			'status'  => 'done',
			'reason'  => 'no_manifest',
			'removed' => 2,
		);
		$cases       = array();
		foreach ( array( false, true ) as $reused ) {
			$path                                      = $reused ? 'create' : 'poll';
			$cases[ $path . ' withheld absent' ]       = array( true, null, $unsupported, $reused );
			$cases[ $path . ' withheld no_manifest' ]  = array( true, $no_manifest, $unsupported, $reused );
			$cases[ $path . ' sent no_manifest' ]      = array( false, $no_manifest, $no_manifest, $reused );
			$cases[ $path . ' sent absent' ]           = array( false, null, null, $reused );
			$cases[ $path . ' withheld other reason' ] = array( true, $other_skip, $other_skip, $reused );
			$cases[ $path . ' withheld other state' ]  = array( true, $other_state, $other_state, $reused );
		}
		return $cases;
	}

	/**
	 * A withholding decision cannot claim that a running task skipped reconcile.
	 */
	public function test_mwp1908_withheld_reconcile_waits_for_completion() {
		$this->responses['backup_preflight']    = array();
		$this->responses['restore_task_create'] = array(
			'task_id' => 'withheld-restore',
			'phase'   => 'queued',
		);
		$job                                    = $this->tick( $this->make_unarchived_restore_job() );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, $job->status );
		$this->assertArrayNotHasKey( 'reconcile', $job->checkpoint['verification'] ?? array() );
		$job = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $job->status );
		$this->assertArrayNotHasKey( 'reconcile', $job->checkpoint['verification'] ?? array() );
		$this->assertSame( 'unarchived_unsupported', $job->checkpoint['reconcile_withheld'] ?? null );
	}

	/**
	 * A failed cloud task cannot determine the replacement task's reconcile result.
	 */
	public function test_mwp1908_cloud_fallback_drops_withheld_reconcile() {
		$store                               = MainWP_Backup_Restore_Job_Store::instance();
		$job                                 = $this->make_job( array( 'reconcile_withheld' => 'unarchived_unsupported' ), $store::STATUS_RUNNING, array( 'child_task_id' => 'failed-cloud' ) );
		$this->responses['restore_task_run'] = array(
			'task_id'    => 'failed-cloud',
			'phase'      => 'failed',
			'error_code' => 'mainwp_cloud_pull_failed',
		);
		$job                                 = $this->tick( $job );
		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertNotSame( 'orch01test', $job->checkpoint['restore_id'] );
		$this->assertArrayNotHasKey( 'reconcile_withheld', $job->checkpoint );
	}

	/**
	 * Unarchived metadata reaches the gate through the persisted restore plan.
	 *
	 * @return object Staged job.
	 */
	private function make_unarchived_restore_job() {
		$job                 = $this->make_job( array( 'sub_type' => 'files' ), MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
		$checkpoint          = $job->checkpoint;
		$entry               = $checkpoint['plan'][0];
		$entry['role']       = 'manifest';
		$entry['file']       = 'files.list';
		$entry['walk']       = array( 'unarchived' => array( 'private/unreadable.php' ) );
		$entry['local_path'] = $this->dir . '/files.list';
		file_put_contents( $entry['local_path'], "index.php\t10\t100\n" );
		$entry['size']        = filesize( $entry['local_path'] );
		$checkpoint['plan'][] = $entry;
		MainWP_Backup_Restore_Job_Store::instance()->save_checkpoint( (int) $job->id, $checkpoint );
		return MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
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
			$this->assertSame( array(), $this->calls, 'A completed restore keeps its own safety backup so it can still be rolled back.' );
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
			if ( 'failed_retryable' === $status && 'verifying' === $resume ) {
				// Worker completion still clears an old cancel intent; the user's stop ends the checks instead.
				$stop_expected = $refused ? array( 'failed_retryable', null, array(), false, null, true, null ) : array(
					'failed_permanent',
					null,
					array(),
					true,
					array(
						'error'      => 'The site did not respond after the restore. The restore completed on the child; check the site directly.',
						'error_code' => '',
					),
					true,
					null,
				);
				$this->assert_cancellation_decision( $status, $resume, $answer, $records, $refused, $stop_expected, $refused ? 'throws:retry' : 'stopped', $child_task_id, $fixture );
			} else {
				$this->assert_cancellation_decision( $status, $resume, $answer, $records, $refused, $expected, $lane, $child_task_id, $fixture );
			}
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
				// The drive-on clear is the checkpoint write without a revision compare.
				$clear = str_contains( $sql, ' SET checkpoint = ' ) && ! str_contains( $sql, ' AND checkpoint_rev = ' );
				if ( 'checkpoint' === $refused && $clear ) {
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
					$this->assertStringContainsString( 'throws:retry' === $lane ? 'Try again in a moment.' : 'already finished on the site', $exception->getMessage() );
					$result = 'throws:retry' === $lane ? 'throws:retry' : 'throws:finished';
				}
				$this->assertSame( $lane, $result );
			}
			$fresh = $store->get_job( (int) $job->id );
			list( $expected_status, , $expected_calls, $cleanup, $event, $pending, $attempts ) = $expected;
			$this->assertSame( $expected_status, $fresh->status );
			$this->assertSame( $expected_calls, $this->calls_without_report_reads() );
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
				$this->assertSame( array( 'restore_task_cancel' ), $this->calls_without_report_reads() );
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

		// A failure while the safety backup runs stops that backup too.
		$pre_snapshot_failure  = array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'backup_task_cancel' ), true, $later, null, null );
		$pre_snapshot_original = array( 'failed_permanent', MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_TERMINAL, array( 'backup_task_cancel' ), true, $original, null, null );

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
			'pre_snapshot//failure without probe'          => array( 'pre_snapshot', '', 'threw', 'none', false, $pre_snapshot_failure, null ),
			'pre_snapshot//stored failure without probe'   => array( 'pre_snapshot', '', 'completed', 'failure', false, $pre_snapshot_original, null ),
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
			'failed_retryable/pre_snapshot/failure without probe' => array( 'failed_retryable', 'pre_snapshot', 'threw', 'none', false, $pre_snapshot_failure, null ),
			'failed_retryable/pre_snapshot/stored failure without probe' => array( 'failed_retryable', 'pre_snapshot', 'completed', 'failure', false, $pre_snapshot_original, null ),
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
			$this->assertSame( array( 'restore_task_cancel' ), $this->calls_without_report_reads(), 'The new holder must cancel before any restore slice.' );
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
		global $wpdb;
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
			$this->assertNotFalse(
				$wpdb->update(
					MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ),
					array( 'checkpoint' => wp_json_encode( $checkpoint ) ),
					array( 'id' => (int) $job->id )
				)
			);
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
		$this->assertNotContains( 'restore_task_run', $this->calls_without_report_reads() );
		$fresh = $store->get_job( (int) $job->id );
		$this->assertTrue( $fresh->checkpoint['cancel_intent'] );
		$this->assertStringContainsString( $sweep ? 'The restore was interrupted' : 'Original replay failure', $fresh->last_error );
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
		global $wpdb;
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
		$this->assertNotFalse(
			$wpdb->update(
				MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ),
				array( 'checkpoint' => wp_json_encode( $checkpoint ) ),
				array( 'id' => (int) $job->id )
			)
		);
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
		$this->assertSame( array_fill( 0, $ticks, 'restore_task_cancel' ), $this->calls_without_report_reads() );
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
		$this->assertSame( array_fill( 0, 4, 'restore_task_cancel' ), $this->calls_without_report_reads() );
	}

	/**
	 * A busy child must stop before the failure releases its safety backup.
	 *
	 * @dataProvider terminal_failure_cancellation_cases
	 * @param string $answer Child cancellation outcome.
	 * @param string $child_task_id Child task id, or empty before task creation.
	 * @param bool   $attempted Whether creation might have reached the Child.
	 */
	public function test_terminal_failure_cancels_child_before_finishing( $answer, $child_task_id, $attempted = false ) {
		$job    = $this->make_job(
			$attempted ? array( 'child_create_attempted' => true ) : array(),
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

		$this->assertSame( '' === $child_task_id && ! $attempted ? array() : array( 'restore_task_cancel' ), $this->calls_without_report_reads() );
		if ( '' !== $child_task_id ) {
			$this->assertSame( $child_task_id, $this->calls[0]['params']['task_id'] );
		} elseif ( $attempted ) {
			$this->assertSame( array( 'restore_id' => 'orch01test' ), $this->calls[0]['params'] );
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
			$this->assertSame( array( 'restore_task_cancel' ), $this->calls_without_report_reads(), 'Applying the failure must not issue a second cancellation or resume the restore.' );
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
			'confirmed'                   => array( 'ok', 'task-9-restore' ),
			'already failed'              => array( 'already_terminal', 'task-9-restore' ),
			'busy'                        => array( 'busy', 'task-9-restore' ),
			'rolling back'                => array( 'rolling_back', 'task-9-restore' ),
			'child error'                 => array( 'error', 'task-9-restore' ),
			'already completed'           => array( 'completed', 'task-9-restore' ),
			'unreachable'                 => array( 'unreachable', 'task-9-restore' ),
			'no child task'               => array( 'ok', '' ),
			'unrecorded child task'       => array( 'ok', '', true ),
			'unrecorded task absent'      => array( 'not_found', '', true ),
			'unrecorded task unreachable' => array( 'unreachable', '', true ),
		);
	}

	/**
	 * The Child may own a restore even when the fenced handle write was refused.
	 *
	 * @dataProvider unrecorded_child_cancel_paths
	 * @param string $path Creation path.
	 * @param string $trigger Terminal decision source.
	 */
	public function test_unrecorded_child_create_is_cancelled_by_restore_id( $path, $trigger ) {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array(), $store::STATUS_STAGING );
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		if ( 'standard' !== $path ) {
			$this->use_cloud_pull_driver( $this->pull_manifest_fixture() );
		}
		$this->responses['restore_task_create'] = array(
			'task_id'                => 'unrecorded-child',
			'phase'                  => 'queued',
			'pull_manifest_rejected' => 'rejected pull' === $path,
		);
		$at_create                              = null;
		$observe                                = static function ( $pre, $website, $what ) use ( $store, $job, &$at_create ) {
			if ( 'restore_task_create' === $what ) {
				$at_create = $store->get_job( (int) $job->id );
			}
			return $pre;
		};
		$refuse                                 = static function ( $sql ) use ( $table ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET ' ) && str_contains( $sql, "`child_task_id` = 'unrecorded-child'" ) ) {
				return $sql . ' AND 1 = 0';
			}
			return $sql;
		};
		add_filter( 'query', $refuse );
		add_filter( 'mainwp_fetch_url_authed_pre', $observe, 20, 3 );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_filter( 'query', $refuse );
			remove_filter( 'mainwp_fetch_url_authed_pre', $observe, 20 );
		}
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( '', $job->child_task_id );
		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertNotNull( $at_create );
		$this->assertNotEmpty( $at_create->locked_by );
		if ( 'user' === $trigger ) {
			$this->orchestrator->cancel_job( (int) $job->id );
		} elseif ( 'ceiling' === $trigger ) {
			$this->expire_job_at_reclaim_ceiling( (int) $job->id );
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} else {
			$record = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'record_terminal_failure' );
			$record->invoke( $this->orchestrator, $job, 'Restore stopped.', 'test_restore_failure', null );
		}
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( array( 'restore_task_create', 'restore_task_cancel' ), $this->calls_without_report_reads() );
		$this->assertSame( array( 'restore_id' => $this->calls[0]['params']['restore_id'] ), $this->calls[1]['params'] );
		$this->assertTrue( $at_create->checkpoint['child_create_attempted'] ?? false );
		$this->assertTrue( $job->checkpoint['child_create_attempted'] ?? false );
		$this->assertSame( 'confirmed', $job->checkpoint['cancel_confirmation']['outcome'] );
		$this->assertSame( 'user' === $trigger ? $store::STATUS_CANCELLED : $store::STATUS_FAILED_PERMANENT, $job->status );
	}

	/**
	 * All creation paths can lose their acknowledgement before any terminal decision.
	 *
	 * @return array Creation and terminal decision combinations.
	 */
	public static function unrecorded_child_cancel_paths() {
		$cases = array();
		foreach ( array( 'standard', 'cloud', 'rejected pull' ) as $path ) {
			foreach ( array( 'user', 'ceiling', 'failure' ) as $trigger ) {
				$cases[ $path . ' ' . $trigger ] = array( $path, $trigger );
			}
		}
		return $cases;
	}

	/**
	 * An unrecorded completed task recovers its handle before staging, which may no longer be possible.
	 *
	 * @dataProvider completed_unrecorded_recovery_cases
	 * @param bool $has_failure    Whether a terminal failure triggers cancellation.
	 * @param bool $source_present Whether the backup file can still be staged.
	 */
	public function test_cancel_unrecorded_completed_child_recovers_handle_before_staging( $has_failure, $source_present ) {
		$store    = MainWP_Backup_Restore_Job_Store::instance();
		$override = array( 'child_create_attempted' => true );
		if ( ! $source_present ) {
			$override['plan'] = array(
				array(
					'role'        => 'db',
					'file'        => 'db.zip',
					'size'        => 10,
					'sha256'      => '',
					'snapshot_id' => 5,
					'source'      => 'local',
					'local_path'  => $this->dir . '/deleted-db.zip',
					'remote_key'  => '',
					'adopt_only'  => false,
				),
			);
		}
		$job                                    = $this->make_job( $override, $store::STATUS_STAGING );
		$this->responses['restore_task_cancel'] = array(
			'result' => 'already_terminal',
			'phase'  => 'completed',
		);
		$this->responses['restore_task_create'] = array(
			'task_id'   => 'unrecorded-completed',
			'reused'    => true,
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'db_ok' => true ) ),
		);
		$this->assertSame( '', $job->child_task_id );

		if ( $has_failure ) {
			$record = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'record_terminal_failure' );
			$this->assertSame( MainWP_Backup_Restore_Orchestrator::TERMINAL_OUTCOME_DRIVE_ON, $record->invoke( $this->orchestrator, $job, 'Restore stopped.', 'test_restore_failure', null ) );
		} else {
			try {
				$this->orchestrator->cancel_job( (int) $job->id );
				$this->fail( 'Expected the completed Child task to refuse cancellation.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( 'The restore already finished on the site and can no longer be cancelled.', $e->getMessage() );
			}
		}

		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertSame( '', $job->child_task_id );
		$this->assertTrue( $job->checkpoint['child_completed_unrecorded'] );
		$this->assertTrue( empty( $job->checkpoint['cancel_intent'] ) );
		$this->assertArrayNotHasKey( 'terminal_failure', $job->checkpoint );
		$this->assertArrayNotHasKey( 'cancel_confirmation', $job->checkpoint );
		$this->assertSame( array( 'restore_task_cancel' ), $this->calls_without_report_reads() );
		$this->assertSame( array( 'restore_id' => 'orch01test' ), $this->calls[0]['params'] );

		$job = $this->tick( $job );
		$this->assertSame( $store::STATUS_VERIFYING, $job->status );
		$this->assertSame( 'unrecorded-completed', $job->child_task_id );
		$this->assertSame( '', (string) $job->last_error );
		$this->assertTrue( $job->checkpoint['verification']['db_ok'] );
		$this->assertArrayNotHasKey( 'child_completed_unrecorded', $job->checkpoint );
		$this->assertSame( array( 'restore_task_cancel', 'restore_task_create' ), $this->calls_without_report_reads() );
		$this->assertSame( $this->calls[0]['params']['restore_id'], $this->calls[1]['params']['restore_id'] );
	}

	/**
	 * Triggers crossed with whether staging is still possible.
	 *
	 * @return array
	 */
	public static function completed_unrecorded_recovery_cases() {
		return array(
			'user cancellation, source present' => array( false, true ),
			'terminal failure, source present'  => array( true, true ),
			'user cancellation, source deleted' => array( false, false ),
			'terminal failure, source deleted'  => array( true, false ),
		);
	}

	/**
	 * A recovery create that makes a new task falls back to staging into that task.
	 */
	public function test_unrecorded_completed_recovery_without_finished_task_stages_normally() {
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job(
			array(
				'child_create_attempted'     => true,
				'child_completed_unrecorded' => true,
			),
			$store::STATUS_STAGING
		);
		$this->responses['restore_task_create'] = array( 'task_id' => 'recreated-task' );

		$job = $this->tick( $job );
		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertSame( 'recreated-task', $job->child_task_id );
		$this->assertArrayNotHasKey( 'child_completed_unrecorded', $job->checkpoint );
		$this->assertArrayNotHasKey( 'staged_path', $job->checkpoint['plan'][0] );
		$this->assertSame( array( 'restore_task_create' ), $this->calls_without_report_reads() );

		$job = $this->tick( $job );
		$this->assertSame( $store::STATUS_TRANSFERRING, $job->status );
		$this->assertSame( 'recreated-task', $job->child_task_id );
		$this->assertNotEmpty( $job->checkpoint['plan'][0]['staged_path'] );
		$this->assertSame( array( 'restore_task_create' ), $this->calls_without_report_reads() );
	}

	/**
	 * A recovery task the job cannot record because a cancel ended it is cancelled by id.
	 */
	public function test_unrecorded_completed_recovery_task_refused_by_ended_job_is_cancelled() {
		global $wpdb;
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$table                                  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$job                                    = $this->make_job(
			array(
				'child_create_attempted'     => true,
				'child_completed_unrecorded' => true,
			),
			$store::STATUS_STAGING
		);
		$id                                     = (int) $job->id;
		$this->responses['restore_task_create'] = array( 'task_id' => 'probe-task' );
		$end                                    = static function ( $sql ) use ( $table, $id, $wpdb ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET ' ) && str_contains( $sql, "`child_task_id` = 'probe-task'" ) ) {
				$wpdb->update( $table, array( 'status' => MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED ), array( 'id' => $id ) );
			}
			return $sql;
		};
		add_filter( 'query', $end );
		try {
			$job = $this->tick( $job );
		} finally {
			remove_filter( 'query', $end );
		}

		$this->assertSame( $store::STATUS_CANCELLED, $job->status );
		$this->assertSame( '', (string) $job->child_task_id );
		$this->assertSame( array( 'restore_task_create', 'restore_task_cancel' ), $this->calls_without_report_reads() );
		$this->assertSame( array( 'task_id' => 'probe-task' ), $this->calls[1]['params'] );
	}

	/**
	 * A cancel sent by restore_id is decided against the task id the row carries.
	 *
	 * Keys name the rows of .mwpdev/reviews/s82-create-cancel-matrix.md.
	 *
	 * @dataProvider restore_id_cancel_landing_cases
	 * @param string $lane            'ui' (cancel_job) or 'sweep' (reclaim ceiling).
	 * @param string $landing         When the worker's task id write lands.
	 * @param array  $answers         Scripted restore_task_cancel answers, in order.
	 * @param bool   $timeout_elapsed Whether the cancel confirmation window has already run out.
	 * @param array  $expect          Expected outcome.
	 * @param bool   $marker          Whether the create marker was saved before the cancel read the row.
	 * @param string $initial_task_id Task id the row carries when the cancel reads it, '' for none.
	 */
	public function test_restore_id_cancel_decides_from_the_task_the_row_carries( $lane, $landing, $answers, $timeout_elapsed, $expect, $marker = true, $initial_task_id = '' ) {
		global $wpdb;
		$store    = MainWP_Backup_Restore_Job_Store::instance();
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$override = $marker ? array( 'child_create_attempted' => true ) : array();
		if ( $timeout_elapsed ) {
			$override['cancel_confirmation'] = array(
				'first_attempt_at' => time() - MainWP_Backup_Restore_Job_Store::CANCEL_CONFIRMATION_TIMEOUT_SECONDS,
				'attempt_count'    => 1,
				'outcome'          => 'pending',
			);
		}
		$job  = $this->make_job( $override, '' === $initial_task_id ? $store::STATUS_STAGING : $store::STATUS_RUNNING, '' === $initial_task_id ? array() : array( 'child_task_id' => $initial_task_id ) );
		$id   = (int) $job->id;
		$dead = null;
		if ( 'sweep' === $lane ) {
			$this->expire_job_at_reclaim_ceiling( $id );
			$dead = 'reclaim-' . $id . '-' . MainWP_Backup_Restore_Job_Store::RECLAIM_CEILING;
		}
		$this->responses['restore_task_cancel'] = $answers;
		$this->responses['restore_task_run']    = array(
			'task_id'   => 'late-task',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'db_ok' => true ) ),
		);
		$logs                                   = array();
		$log                                    = static function ( $message ) use ( &$logs ) {
			$logs[] = $message;
		};
		add_action( 'mainwp_log_info', $log );

		$landed = false;
		if ( 'during_request' === $landing ) {
			$inject = static function ( $pre, $website, $what, $params ) use ( $store, $id, $dead, $initial_task_id, &$landed ) {
				if ( 'restore_task_cancel' === $what && ! $landed && ( '' === $initial_task_id ? isset( $params['restore_id'] ) : ( $params['task_id'] ?? '' ) === $initial_task_id ) ) {
					$landed = $store->set_status( $id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'late-task' ), $dead );
				}
				return $pre;
			};
			add_filter( 'mainwp_fetch_url_authed_pre', $inject, 5, 4 );
		} else {
			$terminal = 'ui' === $lane ? MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED : MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT;
			// before_swap_select lands the id before the terminal write reads the row,
			// before_terminal_write between that read and its UPDATE.
			$prefix = 'before_swap_select' === $landing
				? 'SELECT checkpoint, status, locked_by, checkpoint_rev, child_task_id FROM ' . $table . ' WHERE id = '
				: 'UPDATE ' . $table . ' SET `status` = \'' . $terminal . '\'';
			$inject = static function ( $sql ) use ( $table, $id, $prefix, $wpdb, &$landed ) {
				if ( ! $landed && str_starts_with( $sql, $prefix ) ) {
					$landed = true;
					$wpdb->query( "UPDATE {$table} SET child_task_id = 'late-task', status = 'running', checkpoint_rev = checkpoint_rev + 1 WHERE id = {$id}" ); // phpcs:ignore WordPress.DB -- test interleaving.
				}
				return $sql;
			};
			add_filter( 'query', $inject );
		}

		$answer = null;
		$code   = null;
		try {
			if ( 'ui' === $lane ) {
				try {
					$answer = $this->orchestrator->cancel_job( $id );
				} catch ( MainWP_Exception $e ) {
					$code = $e->get_message_error_code();
				}
			} else {
				$this->orchestrator->process_pending_restore_jobs( 30, $id );
			}
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $inject, 5 );
			remove_filter( 'query', $inject );
			remove_action( 'mainwp_log_info', $log );
		}

		$this->assertTrue( $landed );
		$this->assertSame( $expect['answer'], $answer );
		$this->assertSame( $expect['code'], $code );
		$job = $store->get_job( $id );
		$this->assertSame( $expect['status'], $job->status );
		$this->assertSame( 'late-task', $job->child_task_id );
		$this->assertSame( $expect['confirmation'], $job->checkpoint['cancel_confirmation']['outcome'] ?? null );
		$this->assertSame( $expect['intent'], ! empty( $job->checkpoint['cancel_intent'] ) );
		$this->assertArrayNotHasKey( 'child_completed_unrecorded', $job->checkpoint );
		if ( 'during_request' === $landing && '' === $initial_task_id ) {
			$this->assertNotEmpty( preg_grep( '/recorded meanwhile/', $logs ) );
		}
		if ( isset( $expect['log'] ) ) {
			$this->assertNotEmpty( preg_grep( $expect['log'], $logs ) );
		}
		if ( isset( $expect['after_tick'] ) ) {
			$job = $this->tick( $job );
			$this->assertSame( $expect['after_tick'], $job->status );
		}
		$expected_calls = array();
		foreach ( $expect['calls'] as $call ) {
			$expected_calls[] = 'run' === $call ? array( 'restore_task_run', 'late-task' ) : array( 'restore_task_cancel', $call );
		}
		$actual_calls = array();
		foreach ( $this->calls as $call ) {
			// A cancel answer without a report adds an evidence-only status read; the decision is the cancels.
			if ( 'restore_task_status' !== $call['what'] ) {
				$actual_calls[] = array( $call['what'], $call['params']['task_id'] ?? 'restore_id' );
			}
		}
		$this->assertSame( $expected_calls, $actual_calls );
	}

	/**
	 * Orderings of a task id write against a cancel sent by restore_id.
	 *
	 * @return array
	 */
	public static function restore_id_cancel_landing_cases() {
		$not_found         = array( 'result' => 'not_found' );
		$ok                = array( 'result' => 'ok' );
		$finished          = array(
			'answer'       => 'cancelled',
			'code'         => null,
			'status'       => MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED,
			'confirmation' => 'confirmed',
			'intent'       => true,
			'calls'        => array( 'restore_id', 'late-task' ),
		);
		$pending           = array(
			'answer'       => 'cancel_pending',
			'code'         => null,
			'status'       => MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
			'confirmation' => 'pending',
			'intent'       => true,
			'after_tick'   => MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED,
			'calls'        => array( 'restore_id', 'late-task', 'late-task' ),
		);
		$swept             = array(
			'answer'       => null,
			'code'         => null,
			'status'       => MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT,
			'confirmation' => 'confirmed',
			'intent'       => true,
			'calls'        => array( 'restore_id', 'late-task' ),
		);
		$already_failed    = array(
			'result' => 'already_terminal',
			'phase'  => 'failed',
		);
		$replaced_ui       = array(
			'answer'       => 'cancelled',
			'code'         => null,
			'status'       => MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED,
			'confirmation' => 'confirmed',
			'intent'       => true,
			'calls'        => array( 'first-task', 'late-task' ),
			'log'          => '/created after the job ended/',
		);
		$replaced_sweep    = array(
			'answer'       => null,
			'code'         => null,
			'status'       => MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT,
			'confirmation' => 'confirmed',
			'intent'       => true,
			'calls'        => array( 'first-task', 'late-task' ),
			'log'          => '/created after the job ended/',
		);
		$unrequested_ui    = array(
			'answer'       => 'cancelled',
			'code'         => null,
			'status'       => MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED,
			'confirmation' => 'not_needed',
			'intent'       => false,
			'calls'        => array( 'late-task' ),
			'log'          => '/created after the job ended/',
		);
		$unrequested_sweep = array(
			'answer'       => null,
			'code'         => null,
			'status'       => MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT,
			'confirmation' => null,
			'intent'       => false,
			'calls'        => array( 'late-task' ),
			'log'          => '/created after the job ended/',
		);
		return array(
			'B3 ui, id before re-read, ok'               => array( 'ui', 'during_request', array( $not_found, $ok ), false, $finished ),
			'B4 ui, id before the swap SELECT, ok'       => array( 'ui', 'before_swap_select', array( $not_found, $ok ), false, $finished ),
			'B5 ui, id between the swap SELECT and UPDATE, ok' => array( 'ui', 'before_terminal_write', array( $not_found, $ok ), false, $finished ),
			'B8 ui, id before re-read, timeout'          => array( 'ui', 'during_request', array( $not_found, $ok ), true, $finished ),
			'B3 ui, busy'                                => array( 'ui', 'during_request', array( $not_found, array( 'result' => 'busy' ), $ok ), false, $pending ),
			'B3 ui, transport'                           => array( 'ui', 'during_request', array( $not_found, new MainWP_Exception( 'Site unreachable.' ), $ok ), false, $pending ),
			'B3 ui, completed'                           => array(
				'ui',
				'during_request',
				array(
					$not_found,
					array(
						'result' => 'already_terminal',
						'phase'  => 'completed',
					),
				),
				false,
				array(
					'answer'       => null,
					'code'         => MainWP_Backup_Restore_Orchestrator::CANCEL_FINISHED_ON_SITE,
					'status'       => MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
					'confirmation' => null,
					'intent'       => false,
					'after_tick'   => MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING,
					'calls'        => array( 'restore_id', 'late-task', 'run' ),
				),
			),
			'D2 sweep, zombie id before re-read'         => array( 'sweep', 'during_request', array( $not_found, $ok ), false, $swept ),
			'D3 sweep, zombie id before the swap SELECT' => array( 'sweep', 'before_swap_select', array( $not_found, $ok ), false, $swept ),
			'D3 sweep, zombie id between the swap SELECT and UPDATE' => array( 'sweep', 'before_terminal_write', array( $not_found, $ok ), false, $swept ),
			'B12b ui, no marker, id before the swap SELECT' => array( 'ui', 'before_swap_select', array( $ok ), false, $unrequested_ui, false ),
			'B12c ui, no marker, id between the swap SELECT and UPDATE' => array( 'ui', 'before_terminal_write', array( $ok ), false, $unrequested_ui, false ),
			'D9 sweep, no marker, zombie id before the swap SELECT' => array( 'sweep', 'before_swap_select', array( $ok ), false, $unrequested_sweep, false ),
			'F1b ui, replacement id before re-read'      => array( 'ui', 'during_request', array( $already_failed, $ok ), false, $replaced_ui, true, 'first-task' ),
			'F1c ui, replacement id before the swap SELECT' => array( 'ui', 'before_swap_select', array( $already_failed, $ok ), false, $replaced_ui, true, 'first-task' ),
			'F1d ui, replacement id between the swap SELECT and UPDATE' => array( 'ui', 'before_terminal_write', array( $already_failed, $ok ), false, $replaced_ui, true, 'first-task' ),
			'F5 sweep, zombie replacement id before re-read' => array( 'sweep', 'during_request', array( $already_failed, $ok ), false, $replaced_sweep, true, 'first-task' ),
		);
	}

	/**
	 * A UI cancel that finds no task while a create is in flight must not leave the created task running.
	 *
	 * @dataProvider task_created_after_ui_cancel_cases
	 * @param array $checkpoint Checkpoint overrides for the staging job.
	 * @param array $create     The create answer that lands after the cancel.
	 */
	public function test_task_created_after_ui_cancel_confirmed_is_cancelled_by_task_id( $checkpoint, $create ) {
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job( $checkpoint, $store::STATUS_STAGING );
		$this->responses['restore_task_cancel'] = array(
			array( 'result' => 'not_found' ),
			array( 'result' => 'ok' ),
		);
		$this->responses['restore_task_create'] = $create;
		$cancelled                              = null;
		$race                                   = function ( $pre, $website, $what ) use ( $job, &$cancelled ) {
			if ( 'restore_task_create' === $what && null === $cancelled ) {
				$cancelled = $this->orchestrator->cancel_job( (int) $job->id );
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $race, 5, 3 );
		try {
			$job = $this->tick( $job );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $race, 5 );
		}

		$this->assertSame( 'cancelled', $cancelled );
		$this->assertSame( $store::STATUS_CANCELLED, $job->status );
		$this->assertSame( '', (string) $job->child_task_id );
		$this->assertSame( 'confirmed', $job->checkpoint['cancel_confirmation']['outcome'] );
		$this->assertSame( array( 'restore_task_cancel', 'restore_task_create', 'restore_task_cancel' ), $this->calls_without_report_reads() );
		$this->assertSame( array( 'restore_id' => 'orch01test' ), $this->calls[0]['params'] );
		$this->assertSame( array( 'task_id' => 'late-task' ), $this->calls[2]['params'] );
	}

	/**
	 * Every create that can return a task id after the job ended.
	 *
	 * @return array
	 */
	public static function task_created_after_ui_cancel_cases() {
		return array(
			'standard create, new task'    => array( array(), array( 'task_id' => 'late-task' ) ),
			'standard create, reused task' => array(
				array(),
				array(
					'task_id' => 'late-task',
					'reused'  => true,
					'phase'   => 'queued',
				),
			),
			'recovery probe, new task'     => array(
				array(
					'child_create_attempted'     => true,
					'child_completed_unrecorded' => true,
				),
				array( 'task_id' => 'late-task' ),
			),
		);
	}

	/**
	 * A completed answer to the reclaim sweep's cancel must leave a job a worker can take.
	 *
	 * @dataProvider swept_completed_cases
	 * @param string $child_task_id Recorded Child task id, or '' when it never landed.
	 * @param string $next_call     The Child call that recovers the completed task.
	 */
	public function test_reclaim_sweep_completed_answer_releases_the_expired_lease( $child_task_id, $next_call ) {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$extra = '' === $child_task_id ? array() : array( 'child_task_id' => $child_task_id );
		$job   = $this->make_job( array( 'child_create_attempted' => true ), $store::STATUS_STAGING, $extra );
		$this->expire_job_at_reclaim_ceiling( (int) $job->id );
		$this->responses['restore_task_cancel'] = array(
			'result' => 'already_terminal',
			'phase'  => 'completed',
		);
		$this->responses['restore_task_create'] = array(
			'task_id'   => 'swept-completed',
			'reused'    => true,
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'db_ok' => true ) ),
		);
		$this->responses['restore_task_run']    = array(
			'task_id'   => 'swept-completed',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'db_ok' => true ) ),
		);

		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );

		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $store::STATUS_COMPLETED, $job->status );
		$this->assertSame( '' === $child_task_id ? 'swept-completed' : $child_task_id, $job->child_task_id );
		$this->assertSame( array( 'restore_task_cancel', $next_call ), $this->calls_without_report_reads() );
	}

	/**
	 * Swept jobs with and without a recorded Child task id.
	 *
	 * @return array
	 */
	public static function swept_completed_cases() {
		return array(
			'task id never recorded' => array( '', 'restore_task_create' ),
			'task id recorded'       => array( 'swept-recorded', 'restore_task_run' ),
		);
	}

	/** A refused checkpoint cannot authorize a Child side effect. */
	public function test_child_create_requires_durable_attempt_marker() {
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job( array(), $store::STATUS_STAGING );
		$table                                  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$this->responses['restore_task_create'] = array( 'task_id' => 'must-not-exist' );
		$this->responses['restore_task_run']    = array( 'phase' => 'completed' );
		$refuse                                 = static function ( $sql ) use ( $table ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET checkpoint' ) && str_contains( $sql, 'child_create_attempted' ) ) {
				return $sql . ' AND 1 = 0';
			}
			return $sql;
		};
		add_filter( 'query', $refuse );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_filter( 'query', $refuse );
		}
		$this->assertSame( array(), $this->calls_without_report_reads() );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertEmpty( $job->child_task_id );
		$this->assertArrayNotHasKey( 'child_create_attempted', $job->checkpoint );
	}

	/** No matching identity proves absence, while transport failure proves nothing. */
	public function test_cancel_unrecorded_child_not_found_is_confirmed() {
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job( array( 'child_create_attempted' => true ), $store::STATUS_STAGING );
		$this->responses['restore_task_cancel'] = array( 'result' => 'not_found' );
		$this->orchestrator->cancel_job( (int) $job->id );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( array( 'restore_task_cancel' ), $this->calls_without_report_reads() );
		$this->assertSame( array( 'restore_id' => 'orch01test' ), $this->calls[0]['params'] );
		$this->assertSame( $store::STATUS_CANCELLED, $job->status );
		$this->assertSame( 'confirmed', $job->checkpoint['cancel_confirmation']['outcome'] );
	}

	/** The existing retry window protects a Child whose response was lost. */
	public function test_cancel_unrecorded_child_unreachable_remains_pending_then_times_out() {
		global $wpdb;
		$store                                  = MainWP_Backup_Restore_Job_Store::instance();
		$job                                    = $this->make_job( array( 'child_create_attempted' => true ), $store::STATUS_STAGING );
		$this->responses['restore_task_cancel'] = new MainWP_Exception( 'Site unreachable.' );
		$this->assertSame( 'cancel_pending', $this->orchestrator->cancel_job( (int) $job->id ) );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertSame( 'pending', $job->checkpoint['cancel_confirmation']['outcome'] );
		$this->assertSame( array( 'restore_id' => 'orch01test' ), $this->calls[0]['params'] );
		$checkpoint = $job->checkpoint;
		$checkpoint['cancel_confirmation']['first_attempt_at'] = time() - $store::CANCEL_CONFIRMATION_TIMEOUT_SECONDS;
		$this->assertSame(
			1,
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' SET checkpoint = %s, checkpoint_rev = checkpoint_rev + 1 WHERE id = %d',
					wp_json_encode( $checkpoint ),
					(int) $job->id
				)
			)
		);
		$job = $this->tick( $job );
		$this->assertSame( $store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'unconfirmed', $job->checkpoint['cancel_confirmation']['outcome'] );
		$this->assertSame( 'mainwp_restore_cancel_unconfirmed', $job->checkpoint['last_error_code'] );
		$this->assertSame( array( 'restore_task_cancel', 'restore_task_cancel' ), $this->calls_without_report_reads() );
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
	 * A site whose wp-content sits outside the WordPress core folder refuses
	 * the full safety backup; that refusal is final, and a database-only
	 * restore tells the user how to run without the safety backup.
	 *
	 * @dataProvider unsupported_layout_safety_backup_cases
	 *
	 * @param string $sub_type     Restore sub-type.
	 * @param string $message_part Expected fragment of the stored error.
	 */
	public function test_pre_snapshot_refused_for_unsupported_layout_is_permanent( $sub_type, $message_part ) {
		$this->responses['backup_preflight']   = array(
			'disk_free'       => 10 * GB_IN_BYTES,
			'dir_writable'    => true,
			'db_connected'    => true,
			'zip_support'     => true,
			'gz_support'      => true,
			'backup_dir_hash' => 'abc123def456gh78',
			'task_spine'      => 1,
		);
		$this->responses['backup_task_create'] = array(
			'error'      => 'MainWP backups cannot run on this site yet.',
			'error_code' => MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_LAYOUT,
		);
		$job                                   = $this->make_job(
			array(
				'sub_type' => $sub_type,
				'options'  => array(
					'pre_snapshot'  => true,
					'file_rollback' => false,
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_PREFLIGHT
		);
		$job                                   = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertStringContainsString( $message_part, $job->last_error );
	}

	/**
	 * A layout refusal thrown anywhere in a slice is final; other coded throws still retry.
	 *
	 * @dataProvider thrown_code_retry_cases
	 *
	 * @param string $code   Error code carried by the thrown exception.
	 * @param string $status Expected job status.
	 */
	public function test_loop_catch_treats_unsupported_layout_as_permanent( $code, $status ) {
		$this->responses['backup_task_run'] = array( new MainWP_Exception( 'Refused.', null, $code ) );
		$job                                = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => 'task-7-pre' ) );
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );

		$this->assertSame( $status, $job->status );
	}

	/**
	 * Thrown codes and the status each must leave.
	 *
	 * @return array[]
	 */
	public function thrown_code_retry_cases() {
		return array(
			'unsupported layout' => array( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_LAYOUT, MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT ),
			'transient'          => array( MainWP_Backup_Error_Codes::ERR_TIMEOUT, MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE ),
		);
	}

	/**
	 * Restore sub-types and the message fragment each must store.
	 *
	 * @return array[]
	 */
	public function unsupported_layout_safety_backup_cases() {
		return array(
			'database only' => array( 'db', 'safety backup turned off' ),
			'files'         => array( 'files', 'backups your host provides' ),
		);
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
	 * Make the next safety-backup slice eligible without waiting in real time.
	 *
	 * @param object $job Restore job.
	 * @return object Updated job.
	 */
	private function a1_safety_tick( $job ) {
		if ( (int) $job->next_retry_at > 0 ) {
			MainWP_Backup_Restore_Job_Store::instance()->set_status( (int) $job->id, $job->status, array( 'next_retry_at' => 0 ) );
		}
		return $this->tick( $job );
	}

	/**
	 * A database cursor can move while the visible table and byte counters stay fixed.
	 *
	 * @param string|null $stamp Child progress timestamp.
	 * @return array Child reply.
	 */
	private function s83_safety_progress_reply( $stamp ) {
		$reply = array(
			'phase'    => 'dumping_db',
			'progress' => array(
				'files_processed' => 1,
				'bytes_done'      => 100,
			),
		);
		if ( null !== $stamp ) {
			$reply['last_progress_at'] = $stamp;
		}
		return $reply;
	}

	/**
	 * Equal status spends an attempt; eight timestamp-only advances complete the safety backup.
	 */
	public function test_s83_pre_snapshot_timestamp_progress_uses_last_observed_reply() {
		$job                                   = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => 's83-safety' ) );
		$same                                  = $this->s83_safety_progress_reply( '2026-10-12 12:00:00' );
		$auth                                  = array(
			'error'      => 'Replay.',
			'error_code' => 'AUTH_ERROR2',
		);
		$this->responses['backup_task_run']    = array( $same, $auth );
		$job                                   = $this->a1_safety_tick( $job );
		$this->responses['backup_task_run']    = $auth;
		$this->responses['backup_task_status'] = $same;
		$job                                   = $this->a1_safety_tick( $job );
		$this->assertSame( 2, (int) $job->attempt_count, 'The first status equals the last observed run reply.' );
		for ( $round = 1; $round <= 8; ++$round ) {
			$this->responses['backup_task_status'] = $this->s83_safety_progress_reply( '2026-10-12 12:00:0' . $round );
			$job                                   = $this->a1_safety_tick( $job );
			$this->assertSame( 0, (int) $job->attempt_count, 'Timestamp-only progress must reset attempts.' );
			$this->assertSame( '2026-10-12 12:00:0' . $round, $job->checkpoint['pre_snapshot_progress']['last_progress_at'] ?? null );
			$job = $this->a1_safety_tick( $job );
		}
		$this->responses['backup_task_status'] = array(
			'task_id'   => 's83-safety',
			'phase'     => 'completed',
			'artifacts' => array(
				array(
					'file' => 'safety.zip',
					'size' => 10,
					'role' => 'full',
				),
			),
		);
		$job                                   = $this->a1_safety_tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertSame( 'safety.zip', $job->checkpoint['pre_snapshot_artifacts'][0]['file'] );
		$this->assertSame( array_merge( array( 'backup_task_run', 'backup_task_run', 'backup_task_status' ), array_merge( ...array_fill( 0, 8, array( 'backup_task_status', 'backup_task_run' ) ) ), array( 'backup_task_status' ) ), $this->calls_without_report_reads() );
	}

	/**
	 * A confirmed replay gets seven attempts even after three failed transports.
	 */
	public function test_s83_pre_snapshot_replay_renews_full_unchanged_window() {
		$same                                  = $this->s83_safety_progress_reply( '2026-10-12 12:00:00' );
		$job                                   = $this->make_job(
			array(
				'pre_snapshot_progress' => array(
					'phase'            => 'dumping_db',
					'files'            => 1,
					'bytes'            => 100,
					'last_progress_at' => $same['last_progress_at'],
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array( 'pre_snapshot_task_id' => 's83-safety-stuck' )
		);
		$this->responses['backup_task_run']    = new MainWP_Exception( 'HTTPERROR' );
		$this->responses['backup_task_status'] = $same;
		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			MainWP_Backup_Restore_Job_Store::instance()->set_status( (int) $job->id, $job->status, array( 'next_retry_at' => 0 ) );
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
			$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, $job->status, 'Each transport failure must remain retryable.' );
			$this->assertSame( $attempt, (int) $job->attempt_count );
		}
		$this->responses['backup_task_run'] = array(
			'error'      => 'Replay.',
			'error_code' => 'AUTH_ERROR2',
		);
		MainWP_Backup_Restore_Job_Store::instance()->set_status( (int) $job->id, $job->status, array( 'next_retry_at' => 0 ) );
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		$this->assertSame( 1, (int) $job->attempt_count, 'A confirmed replay starts a fresh window.' );
		for ( $attempt = 2; $attempt < 7; ++$attempt ) {
			$job = $this->a1_safety_tick( $job );
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, $job->status );
			$this->assertSame( $attempt, (int) $job->attempt_count );
			$this->assertArrayNotHasKey( 'last_error_extra', $job->checkpoint );
		}
		$job = $this->a1_safety_tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_TIMEOUT, $job->checkpoint['last_error_code'] );
		$this->assertSame( 'AUTH_ERROR2', $job->checkpoint['last_error_extra'] ?? null );
		$operations = $this->calls_without_report_reads();
		$this->assertSame(
			4,
			count(
				array_filter(
					$operations,
					static function ( $op ) {
						return 'backup_task_run' === $op;
					}
				)
			)
		);
		$this->assertSame(
			9,
			count(
				array_filter(
					$operations,
					static function ( $op ) {
						return 'backup_task_status' === $op;
					}
				)
			)
		);
	}

	/**
	 * Both failures may happen before the Child receives the run request.
	 *
	 * @return array Run failure cases.
	 */
	public function s83_unconfirmed_run_cases() {
		return array(
			'thrown'    => array( new MainWP_Exception( 'HTTPERROR' ) ),
			'shapeless' => array( array() ),
		);
	}

	/**
	 * A replay discards prior no-progress attempts before charging its first attempt.
	 */
	public function test_s83_pre_snapshot_confirmed_replay_resets_prior_attempts() {
		$same = $this->s83_safety_progress_reply( '2026-10-12 12:00:00' );
		$job  = $this->make_job(
			array(
				'pre_snapshot_progress' => array(
					'phase'            => 'dumping_db',
					'files'            => 1,
					'bytes'            => 100,
					'last_progress_at' => $same['last_progress_at'],
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array(
				'pre_snapshot_task_id' => 's83-prior-attempts',
				'attempt_count'        => 3,
			)
		);
		MainWP_DB::instance()->update_website_option(
			$this->site_id,
			MainWP_Backup_Executor::ADHOC_TASK_OPTION,
			wp_json_encode(
				array(
					'task_id' => 's83-prior-attempts',
					'owner'   => (string) $job->id,
				)
			)
		);
		$this->responses['backup_task_run']    = array(
			'error'      => 'Replay.',
			'error_code' => 'AUTH_ERROR2',
		);
		$this->responses['backup_task_status'] = $same;
		$job                                   = $this->a1_safety_tick( $job );
		$this->assertSame( 1, (int) $job->attempt_count, 'A confirmed replay starts a fresh window.' );
		for ( $attempt = 2; $attempt < 7; ++$attempt ) {
			$job = $this->a1_safety_tick( $job );
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, $job->status );
			$this->assertSame( $attempt, (int) $job->attempt_count );
		}
		$job = $this->a1_safety_tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_TIMEOUT, $job->checkpoint['last_error_code'] );
		$this->assertSame( 'AUTH_ERROR2', $job->checkpoint['last_error_extra'] ?? null );
		$this->assertSame( array_merge( array( 'backup_task_run' ), array_fill( 0, 6, 'backup_task_status' ), array( 'backup_task_cancel' ) ), $this->calls_without_report_reads() );
	}

	/**
	 * An armed retry reads once and resends in that slice without charging the read.
	 *
	 * @dataProvider s83_unconfirmed_run_cases
	 * @param mixed $failure Run transport failure.
	 */
	public function test_s83_pre_snapshot_unconfirmed_retry_resends_run( $failure ) {
		$same                                  = $this->s83_safety_progress_reply( '2026-10-12 12:00:00' );
		$job                                   = $this->make_job(
			array(
				'pre_snapshot_progress' => array(
					'phase'            => 'dumping_db',
					'files'            => 1,
					'bytes'            => 100,
					'last_progress_at' => $same['last_progress_at'],
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array( 'pre_snapshot_task_id' => 's83-retry' )
		);
		$this->responses['backup_task_run']    = $failure;
		$this->responses['backup_task_status'] = $same;
		for ( $attempt = 1; $attempt <= 2; ++$attempt ) {
			MainWP_Backup_Restore_Job_Store::instance()->set_status( (int) $job->id, $job->status, array( 'next_retry_at' => 0 ) );
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
			$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
			$this->assertSame( $attempt, (int) $job->attempt_count, 'Only the failed run spends an attempt.' );
			$this->assertArrayNotHasKey( 'last_error_extra', $job->checkpoint );
		}
		$this->assertSame( array( 'backup_task_run', 'backup_task_status', 'backup_task_run' ), $this->calls_without_report_reads() );
		$this->responses['backup_task_run'] = array(
			'task_id'   => 's83-retry',
			'phase'     => 'completed',
			'artifacts' => array(
				array(
					'file' => 'safety.zip',
					'size' => 10,
					'role' => 'full',
				),
			),
		);
		$job                                = $this->a1_safety_tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertArrayNotHasKey( 'last_error_extra', $job->checkpoint );
		$this->assertSame( array( 'backup_task_run', 'backup_task_status', 'backup_task_run', 'backup_task_status', 'backup_task_run' ), $this->calls_without_report_reads() );
	}

	/**
	 * A safety-backup run that dies after its heartbeat keeps spending attempts (matrix M14).
	 */
	public function test_s83_pre_snapshot_heartbeat_after_failed_run_does_not_renew_attempts() {
		$job        = $this->make_job(
			array(
				'pre_snapshot_progress' => array(
					'phase'            => 'dumping_db',
					'files'            => 1,
					'bytes'            => 100,
					'last_progress_at' => '2026-10-12 12:00:00',
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array( 'pre_snapshot_task_id' => 's83-dying' )
		);
		$heartbeats = array();
		for ( $death = 1; $death <= 10; ++$death ) {
			$heartbeats[] = $this->s83_safety_progress_reply( sprintf( '2026-10-12 12:%02d:00', $death ) );
		}
		$this->responses['backup_task_run']    = new MainWP_Exception( 'HTTPERROR' );
		$this->responses['backup_task_status'] = $heartbeats;
		$previous                              = 0;
		for ( $slice = 0; $slice < 20 && MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT !== $job->status; ++$slice ) {
			MainWP_Backup_Restore_Job_Store::instance()->set_status( (int) $job->id, $job->status, array( 'next_retry_at' => 0 ) );
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
			$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
			$this->assertGreaterThanOrEqual( $previous, (int) $job->attempt_count, 'Attempts never drop between slices.' );
			$previous = (int) $job->attempt_count;
		}
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertArrayNotHasKey( 'last_error_extra', $job->checkpoint, 'No replay was seen, so none is reported.' );
		$whats = $this->calls_without_report_reads();
		$this->assertContains( 'backup_task_status', $whats );
		$this->assertSame( 7, count( array_keys( $whats, 'backup_task_run', true ) ), 'A heartbeat alone must not renew attempts: an in-slice reset shows as extra runs.' );
	}

	/**
	 * Missing and empty timestamps both leave the initial comparison unknown.
	 *
	 * @return array Baseline cases.
	 */
	public function s83_safety_unknown_timestamp_cases() {
		return array(
			'missing' => array( null ),
			'empty'   => array( '' ),
		);
	}

	/**
	 * A first nonempty safety timestamp proves movement without a prior token.
	 *
	 * @dataProvider s83_safety_unknown_timestamp_cases
	 * @param string|null $baseline Last observed timestamp.
	 */
	public function test_s83_pre_snapshot_unknown_timestamp_baseline( $baseline ) {
		$previous = array(
			'phase' => 'dumping_db',
			'files' => 1,
			'bytes' => 100,
		);
		if ( null !== $baseline ) {
			$previous['last_progress_at'] = $baseline;
		}
		$job                                   = $this->make_job(
			array(
				'pre_snapshot_progress'          => $previous,
				'pre_snapshot_reconcile_pending' => true,
				'pre_snapshot_replay_confirmed'  => true,
			),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array(
				'pre_snapshot_task_id' => 's83-safety-unknown',
				'attempt_count'        => 2,
			)
		);
		$this->responses['backup_task_status'] = $this->s83_safety_progress_reply( '2026-10-12 12:00:00' );
		$job                                   = $this->a1_safety_tick( $job );
		$this->assertSame( 0, (int) $job->attempt_count );
		$this->assertArrayNotHasKey( 'pre_snapshot_reconcile_pending', $job->checkpoint );
		$this->assertSame( array( 'backup_task_status' ), $this->calls_without_report_reads() );
	}

	/**
	 * A marker without the confirmation flag reads as armed, so a heartbeat alone keeps attempts.
	 */
	public function test_s83_pre_snapshot_marker_without_flag_is_armed_only() {
		$job                                   = $this->make_job(
			array(
				'pre_snapshot_progress'          => array(
					'phase'            => 'dumping_db',
					'files'            => 1,
					'bytes'            => 100,
					'last_progress_at' => '2026-10-12 12:00:00',
				),
				'pre_snapshot_reconcile_pending' => true,
			),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array(
				'pre_snapshot_task_id' => 's83-safety-legacy',
				'attempt_count'        => 2,
			)
		);
		$this->responses['backup_task_status'] = $this->s83_safety_progress_reply( '2026-10-12 12:00:40' );
		$job                                   = $this->a1_safety_tick( $job );
		$this->assertSame( 2, (int) $job->attempt_count );
		$this->assertArrayNotHasKey( 'pre_snapshot_reconcile_pending', $job->checkpoint );
		$this->assertSame( array( 'backup_task_status' ), $this->calls_without_report_reads() );
	}

	/**
	 * A successor reads the armed status before retrying a run whose reply was lost with the lease.
	 */
	public function test_s83_pre_snapshot_lease_successor_reads_before_retry() {
		$same                               = $this->s83_safety_progress_reply( '2026-10-12 12:00:00' );
		$job                                = $this->make_job(
			array(
				'pre_snapshot_progress' => array(
					'phase'            => 'dumping_db',
					'files'            => 1,
					'bytes'            => 100,
					'last_progress_at' => $same['last_progress_at'],
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array( 'pre_snapshot_task_id' => 's83-successor' )
		);
		$this->responses['backup_task_run'] = array(
			'error'      => 'Replay.',
			'error_code' => 'AUTH_ERROR2',
		);
		$reclaim                            = static function () use ( $job ) {
			MainWP_Backup_Restore_Job_Store::instance()->set_status(
				(int) $job->id,
				MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
				array(
					'locked_by'    => 'successor',
					'locked_until' => time() + 3600,
				)
			);
		};
		add_action( 'mainwp_backup_diag_poll', $reclaim );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_action( 'mainwp_backup_diag_poll', $reclaim );
		}
		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		$this->assertTrue( $job->checkpoint['pre_snapshot_reconcile_pending'] );
		$this->responses['backup_task_status'] = $same;
		$this->responses['backup_task_run']    = array(
			'task_id'   => 's83-successor',
			'phase'     => 'completed',
			'artifacts' => array(
				array(
					'file' => 'safety.zip',
					'size' => 10,
					'role' => 'full',
				),
			),
		);
		$job                                   = $this->a1_safety_tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertSame( array( 'backup_task_run', 'backup_task_status', 'backup_task_run' ), $this->calls_without_report_reads() );
	}

	/**
	 * More than seven lost run replies complete when status keeps advancing.
	 */
	public function test_a1_pre_snapshot_replay_progress_survives_eight_rounds() {
		$job    = $this->make_job(
			array(
				'pre_snapshot_progress' => array(
					'phase' => 'archiving_files',
					'files' => 0,
					'bytes' => 0,
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array( 'pre_snapshot_task_id' => 'a1-safety' )
		);
		$handle = wp_json_encode(
			array(
				'task_id' => 'a1-safety',
				'owner'   => (string) $job->id,
			)
		);
		MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		$this->responses['backup_task_run'] = array(
			'error'      => 'Replay.',
			'error_code' => 'AUTH_ERROR2',
		);
		for ( $round = 1; $round <= 8; ++$round ) {
			$job = $this->a1_safety_tick( $job );
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, $job->status, 'Replay must retain the safety backup phase without backoff.' );
			$this->assertGreaterThanOrEqual( time() + 44, (int) $job->next_retry_at );
			$this->assertLessThanOrEqual( time() + 45, (int) $job->next_retry_at );
			$this->assertSame( $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
			$this->responses['backup_task_status'] = array(
				'phase'    => 'archiving_files',
				'progress' => array(
					'files_processed' => $round,
					'bytes_done'      => 100 * $round,
				),
			);
			$job                                   = $this->a1_safety_tick( $job );
			$this->assertSame( 0, (int) $job->attempt_count );
			$this->assertSame( $round, $job->checkpoint['pre_snapshot_progress']['files'] );
		}
		$job                                   = $this->a1_safety_tick( $job );
		$this->responses['backup_task_status'] = array(
			'task_id'   => 'a1-safety',
			'phase'     => 'completed',
			'artifacts' => array(
				array(
					'file' => 'safety.zip',
					'size' => 10,
					'role' => 'full',
				),
			),
		);
		$job                                   = $this->a1_safety_tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_STAGING, $job->status );
		$this->assertSame( 'safety.zip', $job->checkpoint['pre_snapshot_artifacts'][0]['file'] );
		$this->assertSame( array_merge( ...array_fill( 0, 9, array( 'backup_task_run', 'backup_task_status' ) ) ), $this->calls_without_report_reads() );
		$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
	}

	/**
	 * An unchanged safety status spends the existing seven-attempt budget.
	 */
	public function test_a1_pre_snapshot_unchanged_status_exhausts() {
		$job = $this->make_job(
			array(
				'pre_snapshot_progress' => array(
					'phase' => 'archiving_files',
					'files' => 1,
					'bytes' => 100,
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array( 'pre_snapshot_task_id' => 'a1-stuck' )
		);
		MainWP_DB::instance()->update_website_option(
			$this->site_id,
			MainWP_Backup_Executor::ADHOC_TASK_OPTION,
			wp_json_encode(
				array(
					'task_id' => 'a1-stuck',
					'owner'   => (string) $job->id,
				)
			)
		);
		$this->responses['backup_task_run']    = array(
			'error'      => 'Replay.',
			'error_code' => 'AUTH_ERROR2',
		);
		$this->responses['backup_task_status'] = array(
			'phase'    => 'archiving_files',
			'progress' => array(
				'files_processed' => 1,
				'bytes_done'      => 100,
			),
		);
		for ( $attempt = 1; $attempt < 7; ++$attempt ) {
			$job = $this->a1_safety_tick( $job );
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, $job->status );
			$this->assertSame( $attempt, (int) $job->attempt_count );
			$this->assertGreaterThanOrEqual( time() + 44, (int) $job->next_retry_at );
			$this->assertLessThanOrEqual( time() + 45, (int) $job->next_retry_at );
		}
		$job = $this->a1_safety_tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( array_merge( array( 'backup_task_run' ), array_fill( 0, 6, 'backup_task_status' ), array( 'backup_task_cancel' ) ), $this->calls_without_report_reads() );
	}

	/**
	 * A terminal Child failure during reconciliation still fails the restore.
	 */
	public function test_a1_pre_snapshot_terminal_status_error_fails_restore() {
		$job                                   = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => 'a1-failed' ) );
		$this->responses['backup_task_run']    = array(
			'error'      => 'Replay.',
			'error_code' => 'AUTH_ERROR2',
		);
		$this->responses['backup_task_status'] = array(
			'phase'      => 'failed',
			'error'      => 'Could not read table.',
			'error_code' => 'mainwp_backup_db_query_failed',
		);
		$job                                   = $this->a1_safety_tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, $job->status );
		$job = $this->a1_safety_tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'Could not read table.', $job->last_error );
		$this->assertSame( 'mainwp_backup_db_query_failed', $job->checkpoint['last_error_code'] );
		$this->assertSame( array( 'backup_task_run', 'backup_task_status' ), $this->calls_without_report_reads() );
	}

	/**
	 * A reclaimed restore worker leaves its successor a status read after replay.
	 */
	public function test_a1_pre_snapshot_replay_after_lease_loss_keeps_reconciliation() {
		$job                                = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => 'a1-lease' ) );
		$this->responses['backup_task_run'] = array(
			'error'      => 'Replay.',
			'error_code' => 'AUTH_ERROR2',
		);
		$reclaim                            = static function () use ( $job ) {
			MainWP_Backup_Restore_Job_Store::instance()->set_status(
				(int) $job->id,
				MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
				array(
					'locked_by'    => 'new-holder',
					'locked_until' => time() + 3600,
				)
			);
		};
		add_action( 'mainwp_backup_diag_poll', $reclaim );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		} finally {
			remove_action( 'mainwp_backup_diag_poll', $reclaim );
		}
		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		$this->assertTrue( $job->checkpoint['pre_snapshot_reconcile_pending'] ?? false, 'A successor must read status before sending another run.' );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, $job->status );
		$this->assertSame( 0, (int) $job->attempt_count );
		$this->assertSame( array( 'backup_task_run' ), $this->calls_without_report_reads() );
	}

	/**
	 * A deferred safety backup is ineligible for direct and leased drives for 45 seconds.
	 */
	public function test_delta1_pre_snapshot_deferral_waits_for_reconcile_interval() {
		$job                                = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => 'delta1-safety' ) );
		$this->responses['backup_task_run'] = array(
			'error'      => 'Replay.',
			'error_code' => 'AUTH_ERROR2',
		);
		$before                             = time();
		$job                                = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, $job->status );
		$this->assertGreaterThanOrEqual( $before + 45, (int) $job->next_retry_at, 'Reconciliation must wait 45 seconds before spending another attempt.' );
		$this->assertLessThanOrEqual( time() + 45, (int) $job->next_retry_at );
		$this->assertSame( 1, (int) $job->attempt_count );
		$this->responses['backup_task_status'] = array( 'phase' => 'archiving_files' );
		$job                                   = $this->tick( $job );
		$this->orchestrator->process_pending_restore_jobs( 30, (int) $job->id );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->acquire_next_job( 'delta1-early', 60, time(), (int) $job->id ) );
		$this->assertSame( array( 'backup_task_run' ), $this->calls_without_report_reads() );
		$this->assertSame( 1, (int) MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id )->attempt_count );
	}

	/**
	 * A safety-backup timeout retains AUTH_ERROR2 as structured extra data.
	 */
	public function test_delta1_pre_snapshot_cap_retains_replay_timeout_extra() {
		$job                                   = $this->make_job(
			array(
				'pre_snapshot_reconcile_pending' => true,
				'pre_snapshot_replay_confirmed'  => true,
				'pre_snapshot_progress'          => array(
					'phase' => 'archiving_files',
					'files' => 1,
					'bytes' => 100,
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array(
				'pre_snapshot_task_id' => 'delta1-cap-safety',
				'attempt_count'        => 6,
			)
		);
		$this->responses['backup_task_status'] = array(
			'phase'    => 'archiving_files',
			'progress' => array(
				'files_processed' => 1,
				'bytes_done'      => 100,
			),
		);
		$job                                   = $this->tick( $job );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_TIMEOUT, $job->checkpoint['last_error_code'] );
		$this->assertSame( 'AUTH_ERROR2', $job->checkpoint['last_error_extra'] ?? null, 'The timeout must retain its replay cause as extra data.' );
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
			'error payload'        => array( array( 'error' => 'Task not found.' ), 0, 'failed_retryable', false, $unreadable ),
			'busy error'           => array(
				array(
					'error' => 'Busy.',
					'phase' => 'running',
				),
				0,
				'failed_retryable',
				false,
				$unreadable,
			),
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
		$this->assertSame( sprintf( 'The restore was interrupted %d times without finishing a step, so it was stopped. Run the restore again. If it stops again, check the PHP error log on the Dashboard server.', $store::RECLAIM_CEILING ), $fresh->last_error );
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
		// The interrupted job never recorded its safety backup, so it is stopped on a site that still exists.
		$this->assertSame( $owned && ! $deleted ? array( 'backup_task_cancel' ) : array(), $this->calls_without_report_reads() );
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
	 * A retryable failure whose resume point cannot be saved keeps its phase
	 * and backs off in place while the lease is still held, and records
	 * nothing once another worker holds it.
	 *
	 * @dataProvider refused_failure_saves
	 * @param bool $lease_lost Whether another worker takes the lease inside the save.
	 */
	public function test_retryable_failure_keeps_phase_when_checkpoint_swaps_miss( $lease_lost ) {
		global $wpdb;
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job    = $this->make_job( array(), $store::STATUS_RUNNING, array( 'child_task_id' => 'checkpoint-retry-task' ) );
		$id     = (int) $job->id;
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$misses = 0;
		$events = array();

		$this->responses['restore_task_run'] = array( 'error' => 'Temporary restore failure' );

		$race  = function ( $sql ) use ( $table, $id, $store, $lease_lost, &$misses, $wpdb ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET checkpoint = ' ) && str_contains( $sql, 'WHERE id = ' . $id . ' AND checkpoint_rev = ' ) ) {
				++$misses;
				if ( $lease_lost ) {
					$wpdb->update( $table, array( 'locked_by' => 'other-worker' ), array( 'id' => $id ) );
					return $sql;
				}
				$this->assertNotEmpty( $store->get_job( $id )->locked_by, 'The worker must still hold the lease at the missed swap.' );
				return $sql . ' AND 1 = 0';
			}
			return $sql;
		};
		$retry = static function ( $job_id ) use ( &$events ) {
			$events[] = $job_id;
		};
		$start = time();
		add_filter( 'query', $race );
		add_action( 'mainwp_backup_diag_restore_retry', $retry );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, $id );
		} finally {
			remove_filter( 'query', $race );
			remove_action( 'mainwp_backup_diag_restore_retry', $retry );
		}
		$fresh = $store->get_job( $id );
		$this->assertSame( 1, $misses, 'A statement that cannot land is reported after one miss.' );
		$this->assertSame( $store::STATUS_RUNNING, $fresh->status );
		$this->assertArrayNotHasKey( 'resume_status', $fresh->checkpoint );
		$this->assertSame( $job->checkpoint, $fresh->checkpoint );
		if ( $lease_lost ) {
			$this->assertSame( $job->attempt_count, $fresh->attempt_count );
			$this->assertSame( $job->last_error, $fresh->last_error );
			$this->assertSame( $job->next_retry_at, $fresh->next_retry_at );
			$this->assertSame( array(), $events );
			return;
		}
		$this->assertSame( (int) $job->attempt_count + 1, (int) $fresh->attempt_count );
		$this->assertGreaterThan( $start, (int) $fresh->next_retry_at );
		$this->assertNotSame( '', $fresh->last_error );
		$this->assertSame( array( $id ), $events );
	}

	/**
	 * Matrix rows M1-M4: while the revision column repair is pending, no
	 * restore is admitted, no slice drives the site, a cancel writes nothing,
	 * and the job resumes once the repair lands.
	 */
	public function test_schema_repair_pending_pauses_restore_lanes() {
		global $wpdb;
		$store   = MainWP_Backup_Restore_Job_Store::instance();
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$marker  = MainWP_Install::BACKUP_RESTORE_JOB_CHECKPOINT_REV_REPAIR_PENDING;
		$pending = get_site_option( $marker );
		$source  = $this->make_job(
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
		$job     = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'paused-task' ) );
		$id      = (int) $job->id;
		$rows    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB -- test fixture.

		$this->responses['restore_task_run'] = array(
			'task_id'   => 'paused-task',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'row_counts_ok' => true ) ),
		);

		update_site_option( $marker, time() );
		try {
			$kicks = $this->orchestrator->continuation_count;
			$this->orchestrator->process_pending_restore_jobs( 30, $id );
			$held = $store->get_job( $id );
			$this->assertSame( array(), $this->calls, 'A paused slice must not call the site.' );
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $held->status );
			$this->assertSame( '', $held->locked_by );
			$this->assertSame( $kicks, $this->orchestrator->continuation_count );

			foreach ( array( 'restore', 'rollback', 'cancel' ) as $lane ) {
				try {
					if ( 'restore' === $lane ) {
						$this->orchestrator->initiate_restore( $this->site_id, 5 );
					} elseif ( 'rollback' === $lane ) {
						$this->orchestrator->initiate_rollback( $source );
					} else {
						$this->orchestrator->cancel_job( $id );
					}
					$this->fail( $lane . ' must refuse while the repair is pending.' );
				} catch ( MainWP_Exception $e ) {
					$this->assertSame( MainWP_Backup_Error_Codes::ERR_RESTORE_SCHEMA_REPAIR_PENDING, $e->get_message_error_code(), $lane );
				}
			}
			$this->assertSame( $rows, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'A refused admission must not reserve a slot.' ); // phpcs:ignore WordPress.DB -- test fixture.
			$this->assertEquals( $held, $store->get_job( $id ), 'A refused cancel must write nothing.' );
		} finally {
			if ( false === $pending ) {
				delete_site_option( $marker );
			} else {
				update_site_option( $marker, $pending );
			}
		}

		$this->orchestrator->process_pending_restore_jobs( 30, $id );
		$this->assertCount( 1, array_filter( $this->calls, static fn( $call ) => 'restore_task_run' === $call['what'] ) );
		$resumed = $store->get_job( $id );
		$this->assertContains( $resumed->status, array( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED ), 'The repaired slice drives the job on.' );
		$this->assertTrue( $resumed->checkpoint['verification']['row_counts_ok'] );
	}

	/**
	 * Matrix row 32: a refused failure save after transfer progress still
	 * counts from the stored attempt, so a save that keeps failing exhausts
	 * the budget instead of restarting it on every failure.
	 */
	public function test_in_place_backoff_counts_from_the_stored_attempt_and_exhausts() {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$job   = $this->make_job(
			array(
				'transfer'              => array(
					'artifact_index' => 1,
					'offset'         => 500,
				),
				'last_failure_transfer' => array(
					'artifact_index' => 0,
					'offset'         => 0,
				),
			),
			MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING,
			array( 'attempt_count' => MainWP_Backup_Backoff::MAX_ATTEMPTS - 2 )
		);
		$id    = (int) $job->id;

		$this->orchestrator->responds = false;
		$retries                      = array();
		$failures                     = array();
		$refuse                       = static function ( $sql ) use ( $table, $id ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET checkpoint = ' ) && str_contains( $sql, 'WHERE id = ' . $id . ' AND checkpoint_rev = ' ) ) {
				return $sql . ' AND 1 = 0';
			}
			return $sql;
		};
		$retry                        = static function ( $job_id, $site_id, $attempts, $code, $next, $progress_reset ) use ( &$retries ) {
			$retries[] = array( $attempts, $progress_reset );
		};
		$failed                       = static function ( $event ) use ( &$failures ) {
			$failures[] = $event;
		};
		add_filter( 'query', $refuse );
		add_action( 'mainwp_backup_diag_restore_retry', $retry, 10, 6 );
		add_action( 'mainwp_backup_restore_failed', $failed );
		try {
			$start = time();
			$this->orchestrator->process_pending_restore_jobs( 30, $id );
			$first = $store->get_job( $id );
			$this->assertSame( MainWP_Backup_Backoff::MAX_ATTEMPTS - 1, (int) $first->attempt_count, 'The refused save must not reset the attempt budget.' );
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $first->status );
			$this->assertGreaterThan( $start, (int) $first->next_retry_at );
			$this->assertSame( array( array( MainWP_Backup_Backoff::MAX_ATTEMPTS - 1, false ) ), $retries );
			$this->assertSame( array(), $failures );

			$wpdb->update( $table, array( 'next_retry_at' => 0 ), array( 'id' => $id ) );
			$this->orchestrator->process_pending_restore_jobs( 30, $id );
			$final = $store->get_job( $id );
			$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $final->status );
			$this->assertSame( '', $final->locked_by );
			$this->assertNull( $final->active_site_id );
			$this->assertCount( 1, $failures );
		} finally {
			remove_filter( 'query', $refuse );
			remove_action( 'mainwp_backup_diag_restore_retry', $retry, 10 );
			remove_action( 'mainwp_backup_restore_failed', $failed );
		}
	}

	/**
	 * Matrix row 33: an exhausted failure whose terminal write is refused by
	 * the same fault backs off in place instead of being re-driven every tick.
	 */
	public function test_exhausted_failure_backs_off_when_the_terminal_write_is_refused() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$job   = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, array( 'attempt_count' => MainWP_Backup_Backoff::MAX_ATTEMPTS - 1 ) );
		$id    = (int) $job->id;

		$this->orchestrator->responds = false;
		$retries                      = array();
		$failures                     = array();
		$refuse                       = static function ( $sql ) use ( $table ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET ' ) && str_contains( $sql, ' checkpoint = ' ) ) {
				return $sql . ' AND 1 = 0';
			}
			return $sql;
		};
		$retry                        = static function ( $job_id, $site_id, $attempts ) use ( &$retries ) {
			$retries[] = $attempts;
		};
		$failed                       = static function ( $event ) use ( &$failures ) {
			$failures[] = $event;
		};
		add_filter( 'query', $refuse );
		add_action( 'mainwp_backup_diag_restore_retry', $retry, 10, 3 );
		add_action( 'mainwp_backup_restore_failed', $failed );
		try {
			$start = time();
			$this->orchestrator->process_pending_restore_jobs( 30, $id );
		} finally {
			remove_filter( 'query', $refuse );
			remove_action( 'mainwp_backup_diag_restore_retry', $retry, 10 );
			remove_action( 'mainwp_backup_restore_failed', $failed );
		}
		$fresh = $store->get_job( $id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, $fresh->status );
		$this->assertSame( MainWP_Backup_Backoff::MAX_ATTEMPTS, (int) $fresh->attempt_count );
		$this->assertGreaterThan( $start, (int) $fresh->next_retry_at, 'The refused terminal write must leave a backed-off row.' );
		$this->assertNotSame( '', $fresh->last_error );
		$this->assertSame( array( MainWP_Backup_Backoff::MAX_ATTEMPTS ), $retries );
		$this->assertSame( array(), $failures );
	}

	/**
	 * Matrix row 34: a deferral that armed a cancel intent is the Child
	 * confirmation window, so the job keeps polling without a backoff.
	 */
	public function test_exhausted_failure_waiting_for_child_confirmation_keeps_polling() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job(
			array(),
			MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
			array(
				'child_task_id' => 'busy-task',
				'attempt_count' => MainWP_Backup_Backoff::MAX_ATTEMPTS - 1,
			)
		);
		$id    = (int) $job->id;

		$this->responses['restore_task_run']    = array( 'error' => 'Temporary restore failure' );
		$this->responses['restore_task_cancel'] = array( 'result' => 'busy' );
		$retries                                = array();
		$retry                                  = static function ( $job_id ) use ( &$retries ) {
			$retries[] = $job_id;
		};
		add_action( 'mainwp_backup_diag_restore_retry', $retry );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, $id );
		} finally {
			remove_action( 'mainwp_backup_diag_restore_retry', $retry );
		}
		$fresh = $store->get_job( $id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, $fresh->status );
		$this->assertTrue( $fresh->checkpoint['cancel_intent'] );
		$this->assertSame( 'pending', $fresh->checkpoint['cancel_confirmation']['outcome'] );
		$this->assertSame( $job->next_retry_at, $fresh->next_retry_at );
		$this->assertSame( $job->attempt_count, $fresh->attempt_count );
		$this->assertSame( array(), $retries );
	}

	/**
	 * Matrix rows 29 and 30: lease still held, lease taken inside the save.
	 *
	 * @return array
	 */
	public static function refused_failure_saves() {
		return array(
			'lease held' => array( false ),
			'lease lost' => array( true ),
		);
	}

	/**
	 * Matrix row 31: a finished site task whose verification cannot be saved
	 * keeps the job running, so the next poll records the verification.
	 */
	public function test_running_phase_keeps_phase_when_verification_save_cannot_land() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array(), $store::STATUS_RUNNING, array( 'child_task_id' => 'task-9-restore' ) );
		$id    = (int) $job->id;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );

		$this->responses['restore_task_run'] = array(
			'task_id'   => 'task-9-restore',
			'phase'     => 'completed',
			'sub_state' => array( 'verification' => array( 'row_counts_ok' => true ) ),
		);

		$misses = 0;
		$race   = function ( $sql ) use ( $table, $id, &$misses ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET checkpoint = ' ) && str_contains( $sql, 'WHERE id = ' . $id . ' AND checkpoint_rev = ' ) && str_contains( $sql, 'row_counts_ok' ) ) {
				++$misses;
				return $sql . ' AND 1 = 0';
			}
			return $sql;
		};
		$kicks  = $this->orchestrator->continuation_count;
		add_filter( 'query', $race );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30, $id );
		} finally {
			remove_filter( 'query', $race );
		}
		$fresh = $store->get_job( $id );
		$this->assertSame( 1, $misses );
		$this->assertSame( $store::STATUS_RUNNING, $fresh->status );
		$this->assertArrayNotHasKey( 'verification', $fresh->checkpoint );
		$this->assertSame( '', $fresh->locked_by );
		$this->assertGreaterThan( $kicks, $this->orchestrator->continuation_count );

		$job = $this->tick( $fresh );
		$this->assertSame( $store::STATUS_VERIFYING, $job->status );
		$this->assertTrue( $job->checkpoint['verification']['row_counts_ok'] );
	}

	/**
	 * Lease changes and stored stop evidence must survive stale writes.
	 *
	 * @dataProvider lease_checkpoint_interleavings
	 * @param string $order Interleaving to exercise.
	 */
	public function test_lease_checkpoint_interleaving_matrix( $order ) {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array( 'cursor' => 1 ) );
		$id    = (int) $job->id;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$stale = $job->checkpoint;
		if ( str_starts_with( $order, 'sweep' ) ) {
			$this->expire_job_at_reclaim_ceiling( $id );
			$other = $store->create_job( $this->create_test_site(), 5, array() );
			$this->expire_job_at_reclaim_ceiling( $other );
			$captured    = $store->get_job( $id );
			$after_race  = null;
			$interleaved = false;
			$events      = array();
			$event       = static function ( $failure ) use ( &$events ) {
				$events[] = $failure['job_id'];
			};
			$race        = function ( $sql ) use ( &$race, &$interleaved, &$after_race, $order, $table, $id, $store, $captured ) {
				$at_read  = str_contains( $order, 'before-read' ) && str_starts_with( $sql, 'SELECT * FROM ' . $table . ' WHERE id = ' . $id );
				$at_write = str_contains( $order, 'at-write' ) && str_starts_with( $sql, 'UPDATE ' . $table . ' SET `status`' ) && str_contains( $sql, 'WHERE id = ' . $id . ' ' );
				if ( $at_read || $at_write ) {
					remove_filter( 'query', $race );
					$interleaved = true;
					if ( str_contains( $order, 'reacquire' ) ) {
						$store->release_lock( $id, $captured->locked_by );
						$this->assertNotNull( $store->acquire_next_job( 'fresh-worker', 300, null, $id ) );
					} else {
						$this->assertSame( 'written', $store->finish_with_cancel_confirmation( $id, $store::STATUS_CANCELLED, null, '', 'not_needed' ) );
					}
					$after_race = $store->get_job( $id );
				}
				return $sql;
			};
			add_filter( 'query', $race );
			add_action( 'mainwp_backup_restore_failed', $event );
			try {
				$this->orchestrator->process_pending_restore_jobs( 30 );
			} finally {
				remove_filter( 'query', $race );
				remove_action( 'mainwp_backup_restore_failed', $event );
			}
			$fresh = $store->get_job( $id );
			if ( 'sweep-success' === $order ) {
				$this->assertSame( $store::STATUS_FAILED_PERMANENT, $fresh->status );
				$this->assertSame( '', $fresh->locked_by );
				$this->assertSame( 1, $fresh->checkpoint['cursor'] );
				$this->assertSame( '', $fresh->checkpoint['last_error_code'] );
				$this->assertSame( array( $id, $other ), $events );
			} else {
				$this->assertTrue( $interleaved, 'The race must land at the requested boundary.' );
				$this->assertEquals( $after_race, $fresh, 'A lost fence must change no stored column.' );
				$this->assertSame( array( $other ), $events, 'A refused fence must not abort the sweep.' );
			}
			$this->assertSame( $store::STATUS_FAILED_PERMANENT, $store->get_job( $other )->status );
			return;
		}

		$this->assertNotNull( $store->acquire_next_job( 'worker-a', 300, time() - 301, $id ) );
		if ( 'reclaim-stale-fence' === $order ) {
			$this->assertNotNull( $store->acquire_next_job( 'worker-b', 300, null, $id ) );
			$before = $store->get_job( $id );
			$this->assertFalse( $store->save_checkpoint( $id, array( 'cursor' => 9 ), 'worker-a' ) );
			$this->assertEquals( $before, $store->get_job( $id ) );
			return;
		}
		$stale['cursor'] = 9;
		$incoming        = array(
			'cancel_confirmation' => array(
				'outcome'       => 'pending',
				'attempt_count' => 99,
			),
			'terminal_failure'    => array(
				'error'      => 'Stale error',
				'error_code' => 'stale_code',
			),
			'last_error_code'     => 'stale_code',
		);
		$token           = 'worker-a';
		if ( 'ui-defer-save' === $order || 'reclaim-defer-save' === $order || 'ui-during-save' === $order ) {
			$store->set_status( $id, $store::STATUS_RUNNING, array( 'child_task_id' => 'child-restore' ), $token );
			if ( 'ui-during-save' !== $order ) {
				$this->assertNotNull( $store->defer_terminal_failure( $id, 'reclaim-defer-save' === $order ? 'Original failure' : null, 'original_code' ) );
			}
		} elseif ( 'ui-terminal-save' === $order || 'reclaim-terminal-save' === $order ) {
			$cancel = 'ui-terminal-save' === $order;
			$this->assertSame( 'written', $store->finish_with_cancel_confirmation( $id, $cancel ? $store::STATUS_CANCELLED : $store::STATUS_FAILED_PERMANENT, $cancel ? null : 'Original failure', 'original_code', $cancel ? 'not_needed' : null ) );
			$token = null;
			$stale = array_merge( $stale, $incoming );
		} elseif ( 'incoming-only' === $order ) {
			$stale = array_merge( $stale, $incoming );
		} else {
			$key    = $order;
			$stored = array(
				$key => 'last_error_code' === $key ? 'original_code' : array(
					'outcome' => 'confirmed',
					'error'   => 'Original failure',
				),
			);
			$this->assertTrue( $store->save_checkpoint( $id, array_merge( $job->checkpoint, $stored ), $token ) );
			$stale = array_merge( $stale, $incoming );
		}
		$before      = $store->get_job( $id );
		$interleaved = false;
		$race        = function ( $sql ) use ( &$race, &$interleaved, &$before, $order, $table, $id, $store ) {
			if ( 'ui-during-save' === $order && str_starts_with( $sql, 'UPDATE ' . $table . ' SET checkpoint' ) ) {
				remove_filter( 'query', $race );
				$interleaved = true;
				$this->assertNotNull( $store->defer_terminal_failure( $id, 'Original failure', 'original_code' ) );
				$before = $store->get_job( $id );
			}
			return $sql;
		};
		add_filter( 'query', $race );
		try {
			$this->assertTrue( $store->save_checkpoint( $id, $stale, $token ) );
		} finally {
			remove_filter( 'query', $race );
		}
		if ( 'ui-during-save' === $order ) {
			$this->assertTrue( $interleaved );
		}
		$fresh = $store->get_job( $id );
		$this->assertSame( $before->status, $fresh->status );
		$this->assertSame( $before->locked_by, $fresh->locked_by );
		$this->assertSame( $before->last_error, $fresh->last_error );
		$this->assertSame( 9, $fresh->checkpoint['cursor'] );
		// A lone stored error code is the lease holder's own earlier write, so its newer save wins.
		$worker_owns_code = 'last_error_code' === $order;
		foreach ( array_merge( array( 'cancel_intent' ), array_keys( $incoming ) ) as $key ) {
			$expected = ( $worker_owns_code && 'last_error_code' === $key ) ? $stale[ $key ] : ( $before->checkpoint[ $key ] ?? $stale[ $key ] ?? null );
			$this->assertSame( $expected, $fresh->checkpoint[ $key ] ?? null, $key . ' must prefer stored evidence only when present.' );
		}
	}

	/**
	 * Both sides of each race need the same row-level assertions.
	 *
	 * @return array
	 */
	public static function lease_checkpoint_interleavings() {
		$orders = array(
			'sweep-success',
			'sweep-reacquire-before-read',
			'sweep-reacquire-at-write',
			'sweep-cancel-before-read',
			'sweep-cancel-at-write',
			'ui-defer-save',
			'reclaim-defer-save',
			'ui-terminal-save',
			'reclaim-terminal-save',
			'reclaim-stale-fence',
			'incoming-only',
			'cancel_confirmation',
			'terminal_failure',
			'last_error_code',
			'ui-during-save',
		);
		return array_combine( $orders, array_map( static fn( $order ) => array( $order ), $orders ) );
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
		$job_id      = $this->orchestrator->initiate_restore( $this->site_id, $snapshot_id, array( 'confirm_destructive' => true ) );
		$job         = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id );

		$this->assertGreaterThan( 0, $job_id );
		$this->assertSame( 'pending', $job->status );
		$this->assertSame( '1', MainWP_DB::instance()->get_website_option( $this->site_id, 'restore_create_idempotency_support' ) );
		$this->assertSame( array( 'backup_preflight' ), $this->calls_without_report_reads() );
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
		$this->assertSame( array( 'backup_preflight' ), $this->calls_without_report_reads() );
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
		$job_id      = $this->orchestrator->initiate_restore( $this->site_id, $snapshot_id, array( 'confirm_destructive' => true ) );
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
	 * Older Child jobs retain the history fields the page already consumes.
	 */
	public function test_restore_history_without_report_preserves_existing_fields() {
		$job = $this->make_job(
			array( 'last_error_code' => 'mainwp_restore_disk_full' ),
			MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT,
			array( 'last_error' => 'Restore stopped' )
		);
		$row = MainWP_Backup_Read_Model::list_restore_jobs( $this->site_id )['failed_restore_jobs'][0];
		unset( $row['restore_outcome'] );
		$this->assertSame(
			array(
				'job_id'       => (int) $job->id,
				'snapshot_id'  => 5,
				'status'       => 'failed_permanent',
				'date_label'   => MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( (int) $job->created ) ),
				'last_error'   => array(
					'code'        => 'mainwp_restore_disk_full',
					'source_text' => 'Restore stopped',
				),
				'error_code'   => 'mainwp_restore_disk_full',
				'has_rollback' => false,
				'is_rollback'  => false,
			),
			$row
		);
	}

	/**
	 * Missing reports must remain distinguishable from unreadable reports.
	 */
	public function test_restore_history_without_report_has_null_outcome() {
		$this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT );
		$row = MainWP_Backup_Read_Model::list_restore_jobs( $this->site_id )['failed_restore_jobs'][0];
		$this->assertArrayHasKey( 'restore_outcome', $row );
		$this->assertNull( $row['restore_outcome'] );
		$this->assertSame( array( 'job_id', 'snapshot_id', 'status', 'date_label', 'last_error', 'error_code', 'has_rollback', 'is_rollback', 'restore_outcome' ), array_keys( $row ) );
	}

	/**
	 * Reloaded history and modal polling must describe the same stored Child report.
	 *
	 * @dataProvider restore_history_child_reports
	 * @param string $fixture Child payload name.
	 * @param string $status Terminal job status.
	 * @param bool   $verification Report exists only in verification.
	 * @param string $database Expected database outcome.
	 */
	public function test_restore_history_child_report_matches_modal_and_ajax( $fixture, $status, $verification, $database ) {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$payload    = json_decode( file_get_contents( __DIR__ . '/fixtures/child-restore-outcome/restore-outcome-' . $fixture . '.json' ), true );
		$report     = $payload['sub_state']['verification']['outcome'] ?? $payload['verification']['outcome'];
		$checkpoint = array( 'sub_type' => 'full' );
		if ( $verification ) {
			$checkpoint['verification'] = array( 'outcome' => $report );
		} else {
			$checkpoint['restore_outcome'] = $report;
		}
		if ( 'completed' === $status ) {
			$checkpoint['pre_snapshot_artifacts'] = array( array( 'file' => 'backup-pre.tar.gz' ) );
		}
		$job      = $this->make_job( $checkpoint, $status );
		$row      = MainWP_Backup_Read_Model::list_restore_jobs( $this->site_id )['failed_restore_jobs'][0];
		$modal    = MainWP_Backup_Read_Model::get_restore_job( (int) $job->id );
		$response = $this->b194_reload_snapshots();
		$this->assertSame( (int) $job->id, $row['job_id'] );
		$this->assertArrayHasKey( 'restore_outcome', $row );
		$this->assertSame(
			array(
				'valid'    => true,
				'files'    => 'applied',
				'database' => $database,
			),
			$row['restore_outcome']
		);
		$this->assertSame( $modal['restore_outcome'], $row['restore_outcome'] );
		$this->assertSame( $row['restore_outcome'], $response['failed_restore_jobs'][0]['restore_outcome'] );
	}

	/**
	 * Both Child response shapes and the older verification location remain readable.
	 *
	 * @return array
	 */
	public static function restore_history_child_reports() {
		return array(
			'failed report'         => array( 'failed-after-swap', 'failed_permanent', false, 'left_partial' ),
			'cancelled report'      => array( 'cancelled-after-files-before-swap', 'cancelled', false, 'not_started' ),
			'verification fallback' => array( 'failed-after-swap', 'failed_permanent', true, 'left_partial' ),
			'completed report'      => array( 'completed-full', 'completed', false, 'applied' ),
		);
	}

	/**
	 * Corrupt or scalar checkpoints cannot satisfy the report helper's array contract.
	 *
	 * @dataProvider restore_history_non_array_checkpoints
	 * @param string $checkpoint Stored checkpoint JSON.
	 */
	public function test_restore_history_non_array_checkpoint_has_null_outcome( $checkpoint ) {
		global $wpdb;
		$job = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'checkpoint' => $checkpoint ), array( 'id' => $job->id ) );
		$row = MainWP_Backup_Read_Model::list_restore_jobs( $this->site_id )['failed_restore_jobs'][0];
		$this->assertSame( (int) $job->id, $row['job_id'] );
		$this->assertArrayHasKey( 'restore_outcome', $row );
		$this->assertNull( $row['restore_outcome'] );
	}

	/**
	 * JSON decoding can succeed without producing an array.
	 *
	 * @return array
	 */
	public static function restore_history_non_array_checkpoints() {
		return array(
			'invalid JSON' => array( '{not-json' ),
			'null'         => array( 'null' ),
			'boolean'      => array( 'false' ),
			'number'       => array( '7' ),
			'string'       => array( '"checkpoint"' ),
		);
	}

	/**
	 * Reports already live in the selected checkpoints and need no per-job read.
	 */
	public function test_restore_history_reports_do_not_add_queries() {
		global $wpdb;
		$jobs = array();
		for ( $index = 0; $index < 3; ++$index ) {
			$jobs[] = $this->make_job( array( 'sub_type' => 'full' ), MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT );
		}
		MainWP_Backup_Read_Model::list_restore_jobs( $this->site_id );
		$before        = $wpdb->num_queries;
		$plain         = MainWP_Backup_Read_Model::list_restore_jobs( $this->site_id );
		$plain_queries = $wpdb->num_queries - $before;
		foreach ( $jobs as $job ) {
			$checkpoint                    = $job->checkpoint;
			$checkpoint['restore_outcome'] = array(
				'files'    => 'applied',
				'database' => 'left_partial',
			);
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'checkpoint' => wp_json_encode( $checkpoint ) ), array( 'id' => $job->id ) );
		}
		$before  = $wpdb->num_queries;
		$reports = MainWP_Backup_Read_Model::list_restore_jobs( $this->site_id );
		$this->assertSame( $plain_queries, $wpdb->num_queries - $before, 'Stored reports must not add a query per row.' );
		$this->assertCount( 3, $plain['failed_restore_jobs'] );
		$this->assertSame( array_column( $plain['failed_restore_jobs'], 'job_id' ), array_column( $reports['failed_restore_jobs'], 'job_id' ) );
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
	 * Completed rollbacks must not crowd an available older source out of the list.
	 */
	public function test_failed_restore_list_prioritizes_available_rollback_over_completed_successors() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store      = MainWP_Backup_Restore_Job_Store::instance();
		$checkpoint = array( 'pre_snapshot_artifacts' => array( array( 'file' => 'backup-pre.tar.gz' ) ) );
		$source     = $this->make_job( $checkpoint, $store::STATUS_FAILED_PERMANENT );
		$ids        = array();
		for ( $index = 0; $index < 20; ++$index ) {
			$failed = $this->make_job( $checkpoint, $store::STATUS_FAILED_PERMANENT );
			$ids[]  = (int) $failed->id;
			$this->make_job( array( 'rollback_of' => (int) $failed->id ), $store::STATUS_COMPLETED );
		}

		$response = $this->b194_reload_snapshots();
		$rows     = array_column( $response['failed_restore_jobs'], null, 'job_id' );
		$this->assertCount( 20, $rows );
		$this->assertArrayHasKey( (int) $source->id, $rows, 'The older source must remain listed with Roll Back.' );
		$this->assertTrue( $rows[ $source->id ]['has_rollback'] );
		$this->assertArrayNotHasKey( $ids[0], $rows, 'The oldest consumed source yields its place.' );
		foreach ( $ids as $id ) {
			if ( isset( $rows[ $id ] ) ) {
				$this->assertFalse( $rows[ $id ]['has_rollback'] );
			}
		}
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
	 * Refusals distinguish sources that remain retryable from permanent refusals.
	 *
	 * @dataProvider rollback_refusal_states
	 * @param string $state Refusal trigger.
	 * @param bool   $retryable Whether the same source can be retried.
	 */
	public function test_rollback_handler_refusal_shapes( $state, $retryable ) {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store      = MainWP_Backup_Restore_Job_Store::instance();
		$checkpoint = array( 'pre_snapshot_artifacts' => array( array( 'file' => 'backup-pre.tar.gz' ) ) );
		$status     = 'completed' === $state ? $store::STATUS_COMPLETED : $store::STATUS_FAILED_PERMANENT;
		$source     = $this->make_job( 'no-source' === $state ? array() : ( 'completed' === $state ? $checkpoint + array( 'pre_snapshot_pruned' => true ) : $checkpoint ), $status );
		if ( 'busy' === $state ) {
			$this->make_job();
		} elseif ( 'consumed' === $state || 'successor-running' === $state ) {
			$this->make_job( array( 'rollback_of' => (int) $source->id ), 'consumed' === $state ? $store::STATUS_COMPLETED : $store::STATUS_PENDING );
		}
		$nonce                = wp_create_nonce( 'mainwp_restore_rollback' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['job_id']      = 'missing' === $state ? '0' : (string) $source->id;
		try {
			$response = $this->invoke_ajax_handler( 'mainwp_restore_rollback' );
		} finally {
			unset( $_POST['security'], $_REQUEST['security'], $_POST['job_id'] );
		}
		$this->assertNotEmpty( $response['error'] );
		$this->assertArrayNotHasKey( 'job_id', $response );
		$this->assertArrayHasKey( 'retryable', $response );
		$this->assertSame( $retryable, $response['retryable'] );
		$this->assertSame( $status, $store->get_job( (int) $source->id )->status );
	}

	/**
	 * A running unrelated restore leaves a source available for a later attempt.
	 *
	 * @return array Refusal states.
	 */
	public static function rollback_refusal_states() {
		return array(
			'busy site'           => array( 'busy', true ),
			'completed restore'   => array( 'completed', false ),
			'no safety backup'    => array( 'no-source', false ),
			'consumed source'     => array( 'consumed', false ),
			'successor in flight' => array( 'successor-running', false ),
			'missing restore job' => array( 'missing', false ),
		);
	}

	/**
	 * A rollback job builds an adopt-only plan from the pre-restore
	 * safety backup.
	 */
	public function test_rollback_builds_adopt_only_plan() {
		$failed = $this->make_job(
			array(
				'reconcile_withheld'     => 'unarchived_unsupported',
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
		$this->assertArrayNotHasKey( 'reconcile_withheld', $job->checkpoint );
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
	 * Rollback is only available once a restore has finished.
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
	 * Active restores must not be replaced by rollback jobs; a completed
	 * restore still holds its safety backup and can be rolled back.
	 *
	 * @return array
	 */
	public static function rollback_status_cases() {
		return array(
			'completed' => array( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED, true ),
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
			$this->assertEquals( $job, MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id ) );
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

	/** Stopping verification retains the failure and runs the terminal tail once. */
	public function test_stop_checking_preserves_failure_and_ends_retries() {
		global $wpdb;
		update_option( 'mainwp_enableNativeBackups', 1 );
		$store      = MainWP_Backup_Restore_Job_Store::instance();
		$job        = $this->make_job(
			array(
				'resume_status'          => 'verifying',
				'last_error_code'        => 'unreachable_test',
				'pre_snapshot_artifacts' => array( array( 'file' => 'safety.tar.gz' ) ),
			),
			'failed_retryable',
			array(
				'last_error'           => 'Stored unreachable message.',
				'next_retry_at'        => time() + 3600,
				'child_task_id'        => 'finished-task',
				'pre_snapshot_task_id' => 'safety-task',
			)
		);
		$history_id = MainWP_Backup_Run_History::start( 0, $this->site_id, time(), 'restore_safety', 0, (int) $job->id, 'safety-task' );
		$this->assertGreaterThan( 0, $history_id );
		$staging = MainWP_Backup_Restore_Transfer_Driver::instance()->staging_dir( (int) $job->id );
		wp_mkdir_p( $staging );
		file_put_contents( $staging . 'backup.zip', 'staged backup' );
		MainWP_DB::instance()->update_website_option(
			$this->site_id,
			MainWP_Backup_Executor::ADHOC_TASK_OPTION,
			wp_json_encode(
				array(
					'task_id' => 'safety-task',
					'owner'   => (string) $job->id,
				)
			)
		);
		$events = array();
		$failed = static function ( $event ) use ( &$events ) {
			$events[] = $event;
		};
		add_action( 'mainwp_backup_restore_failed', $failed );
		try {
			$this->assertSame( 'stopped', $this->orchestrator->cancel_job( (int) $job->id ) );
			$final = $store->get_job( (int) $job->id );
			$this->assertSame( 'failed_permanent', $final->status );
			$this->assertSame( $job->last_error, $final->last_error );
			$this->assertSame( 'unreachable_test', $final->checkpoint['last_error_code'] );
			$this->assertGreaterThan( 0, $final->checkpoint['verify_stopped'] );
			$this->assertTrue( $this->orchestrator->has_rollback_source( $final ) );
			$this->assertFileDoesNotExist( $staging . 'backup.zip' );
			$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
			$this->assertSame( 'failed', $wpdb->get_var( $wpdb->prepare( 'SELECT outcome FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ) . ' WHERE id = %d', $history_id ) ) ); // phpcs:ignore WordPress.DB -- test assertion.
			$this->orchestrator->process_pending_restore_jobs( 1, (int) $job->id );
			$this->assertEquals( $final, $store->get_job( (int) $job->id ) );
			$this->assertCount( 1, $events );
			$this->assertSame( $job->last_error, $events[0]['error'] );
			$this->assertEmpty( $this->calls );
		} finally {
			remove_action( 'mainwp_backup_restore_failed', $failed );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ), array( 'id' => $history_id ) );
			MainWP_Backup_Restore_Transfer_Driver::instance()->cleanup_staging( (int) $job->id );
		}
	}

	/**
	 * A worker whose lease lapsed after a good probe deletes nothing and leaves a row Stop Checking refuses.
	 */
	public function test_lapsed_verification_worker_skips_prune_and_blocks_stop_checking() {
		global $wpdb;
		$store   = MainWP_Backup_Restore_Job_Store::instance();
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$earlier = $this->make_job( $this->safety_checkpoint(), $store::STATUS_COMPLETED, array( 'pre_snapshot_task_id' => 'task-earlier-pre' ) );
		$job     = $this->make_job(
			array( 'resume_status' => 'verifying' ),
			'failed_retryable',
			array(
				'last_error'    => 'Stored unreachable message.',
				'next_retry_at' => 0,
				'child_task_id' => 'finished-task',
			)
		);

		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );
		$this->orchestrator->on_probe         = static function () use ( $wpdb, $table, $job ) {
			$wpdb->update( $table, array( 'locked_until' => time() - 1 ), array( 'id' => (int) $job->id ) ); // phpcs:ignore WordPress.DB -- test fixture.
		};
		try {
			$this->orchestrator->process_pending_restore_jobs( 15, (int) $job->id );
		} finally {
			$this->orchestrator->on_probe = null;
		}
		$lapsed = $store->get_job( (int) $job->id );
		$this->assertSame( 'failed_retryable', $lapsed->status );
		$this->assertSame( '', $lapsed->locked_by );
		$this->assertTrue( $lapsed->checkpoint['verification']['site_responds'] );
		$this->assertNotContains( 'backup_task_prune', $this->calls_without_report_reads() );
		$this->assertArrayNotHasKey( 'pre_snapshot_pruned', $store->get_job( (int) $earlier->id )->checkpoint );

		$this->assertSame( 'none', MainWP_Backup_Restore_Orchestrator::resolve_cancel_action( $lapsed ) );
		try {
			$this->orchestrator->cancel_job( (int) $job->id );
			$this->fail( 'A verified retry must not be stopped.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertNotSame( MainWP_Backup_Restore_Orchestrator::CANCEL_CHECKING_CHANGED, $e->get_message_error_code() );
		}
		$this->assertEquals( $lapsed, $store->get_job( (int) $job->id ) );

		$this->orchestrator->process_pending_restore_jobs( 15, (int) $job->id );
		$this->assertSame( $store::STATUS_COMPLETED, $store->get_job( (int) $job->id )->status );
		$this->assertContains( 'backup_task_prune', $this->calls_without_report_reads() );
		$this->assertTrue( $store->get_job( (int) $earlier->id )->checkpoint['pre_snapshot_pruned'] );
	}

	/** A cancel admitted before verification must not clear the newer retry's evidence. */
	public function test_cancel_reresolves_before_any_checkpoint_write() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( array(), 'running', array( 'child_task_id' => 'finished-task' ) );
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$reads = 0;
		$raced = null;
		$hook  = function ( $sql ) use ( &$hook, &$reads, &$raced, $table, $store, $job ) {
			if ( ( 'SELECT * FROM ' . $table . ' WHERE id = ' . $job->id ) === $sql && 2 === ++$reads ) {
				remove_filter( 'query', $hook );
				$this->assertTrue(
					$store->save_checkpoint(
						(int) $job->id,
						array(
							'resume_status'   => 'verifying',
							'last_error_code' => 'unreachable_test',
						)
					)
				);
				$store->set_status( (int) $job->id, 'failed_retryable', array( 'last_error' => 'Stored unreachable message.' ) );
				$raced = $store->get_job( (int) $job->id );
			}
			return $sql;
		};
		add_filter( 'query', $hook );
		try {
			$this->orchestrator->cancel_job( (int) $job->id );
			$this->fail( 'A changed action must require a fresh decision.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( MainWP_Backup_Restore_Orchestrator::CANCEL_CHECKING_CHANGED, $error->get_message_error_code() );
		} finally {
			remove_filter( 'query', $hook );
		}
		$this->assertNotNull( $raced );
		$this->assertEquals( $raced, $store->get_job( (int) $job->id ) );
		$this->assertEmpty( $this->calls );
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
		$this->assertSame( array( 'restore_task_cancel' ), $this->calls_without_report_reads() );
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
		$this->assertSame( $expected, array_values( array_filter( $this->calls, static fn( $call ) => 'restore_task_status' !== $call['what'] ) ) );
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
		$this->assertNotContains( 'backup_task_cancel', $this->calls_without_report_reads() );

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
		$this->assertSame( array( 'restore_task_cancel', 'backup_task_cancel' ), $this->calls_without_report_reads() );
		$this->assertNotContains( 'restore_artifact_chunk', $this->calls_without_report_reads() );
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
		$this->assertSame( array( 'restore_artifact_chunk', 'restore_task_cancel', 'backup_task_cancel' ), $this->calls_without_report_reads() );
		$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
	}

	/**
	 * Polls must see acknowledged bytes before the slice finishes.
	 */
	public function test_transferring_saves_only_acked_bytes_during_slice() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job(
			array(
				'plan'     => $this->staged_plan_fixture(),
				'transfer' => array( 'chunk_bytes' => 4 ),
			),
			$store::STATUS_TRANSFERRING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$this->orchestrator->transfer_progress_interval = 0.0;

		$offsets  = array();
		$observed = array();
		$child    = function ( $pre, $website, $what, $params ) use ( $store, $job, &$offsets, &$observed ) {
			if ( 'restore_artifact_chunk' !== $what ) {
				return $pre;
			}
			$fresh      = $store->get_job( (int) $job->id );
			$offsets[]  = (int) $params['offset'];
			$observed[] = array( $fresh->checkpoint['transfer']['offset'] ?? 0, $fresh->checkpoint['transfer']['artifact_index'] ?? 0, $fresh->status );
			return array(
				'result'         => 'ok',
				'bytes_received' => 1 === count( $offsets ) ? 2 : (int) $params['offset'] + strlen( base64_decode( $params['chunk_b64'] ) ),
			);
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $child, 20, 4 );
		try {
			$fresh = $this->tick( $job );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $child, 20 );
		}
		$this->assertSame( array( 0, 2, 6 ), $offsets );
		$this->assertSame( $offsets, array_column( $observed, 0 ) );
		$this->assertSame( array( 0, 0, 0 ), array_column( $observed, 1 ) );
		$this->assertSame( array_fill( 0, 3, $store::STATUS_TRANSFERRING ), array_column( $observed, 2 ) );
		$this->assertSame( $store::STATUS_RUNNING, $fresh->status );
		$this->assertSame( 1, $fresh->checkpoint['transfer']['artifact_index'] );
		$this->assertSame( 0, $fresh->checkpoint['transfer']['offset'] );
	}

	/**
	 * The progress save merges into the row as it is now, so a key written
	 * outside the lease after the slice started is not rolled back, even one
	 * the store's merge does not list.
	 */
	public function test_transferring_progress_save_keeps_checkpoint_written_after_slice_start() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job(
			array(
				'plan'     => $this->staged_plan_fixture(),
				'transfer' => array( 'chunk_bytes' => 4 ),
			),
			$store::STATUS_TRANSFERRING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$this->orchestrator->transfer_progress_interval = 0.0;

		$chunks   = 0;
		$observed = array();
		$child    = function ( $pre, $website, $what, $params ) use ( $store, $job, &$chunks, &$observed ) {
			if ( 'restore_artifact_chunk' !== $what ) {
				return $pre;
			}
			++$chunks;
			$fresh = $store->get_job( (int) $job->id );
			if ( 1 === $chunks ) {
				$checkpoint                      = $fresh->checkpoint;
				$checkpoint['outside_lease_key'] = 'kept';
				$this->assertTrue( $store->save_checkpoint( (int) $job->id, $checkpoint ) );
			} else {
				$observed[] = array( $fresh->checkpoint['outside_lease_key'] ?? null, $fresh->checkpoint['transfer']['offset'] ?? 0 );
			}
			return array(
				'result'         => 'ok',
				'bytes_received' => (int) $params['offset'] + strlen( base64_decode( $params['chunk_b64'] ) ),
			);
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $child, 20, 4 );
		try {
			$fresh = $this->tick( $job );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $child, 20 );
		}
		$this->assertSame( 3, $chunks );
		$this->assertSame( array( array( 'kept', 4 ), array( 'kept', 8 ) ), $observed );
		$this->assertSame( 'kept', $fresh->checkpoint['outside_lease_key'] ?? null );
	}

	/**
	 * A cancel racing the progress UPDATE must survive its CAS retry.
	 */
	public function test_transferring_progress_save_merges_concurrent_cancel() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job(
			array(
				'plan'     => $this->staged_plan_fixture(),
				'transfer' => array( 'chunk_bytes' => 4 ),
			),
			$store::STATUS_TRANSFERRING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$this->orchestrator->transfer_progress_interval = 0.0;

		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$chunks = 0;
		$armed  = false;
		$merged = null;
		$race   = function ( $sql ) use ( $store, $job, $table, &$chunks, &$armed, &$race ) {
			if ( 1 === $chunks && ! $armed && str_starts_with( $sql, 'UPDATE ' . $table . ' SET checkpoint = ' ) ) {
				remove_filter( 'query', $race );
				$armed = $store->arm_cancel_intent( (int) $job->id );
				$this->assertTrue( $armed );
			}
			return $sql;
		};
		$child  = function ( $pre, $website, $what, $params ) use ( $store, $job, &$chunks, &$merged ) {
			if ( 'restore_artifact_chunk' !== $what ) {
				return $pre;
			}
			++$chunks;
			if ( 2 === $chunks ) {
				$merged = $store->get_job( (int) $job->id )->checkpoint;
			}
			return array(
				'result'         => 'ok',
				'bytes_received' => (int) $params['offset'] + strlen( base64_decode( $params['chunk_b64'] ) ),
			);
		};
		add_filter( 'query', $race );
		add_filter( 'mainwp_fetch_url_authed_pre', $child, 20, 4 );
		try {
			$fresh = $this->tick( $job );
		} finally {
			remove_filter( 'query', $race );
			remove_filter( 'mainwp_fetch_url_authed_pre', $child, 20 );
		}
		$this->assertTrue( $armed );
		$this->assertSame( 2, $chunks );
		$this->assertTrue( $merged['cancel_intent'] ?? false );
		$this->assertSame( 4, $merged['transfer']['offset'] ?? 0 );
		$this->assertSame( $store::STATUS_CANCELLED, $fresh->status );
		$this->assertTrue( $fresh->checkpoint['cancel_intent'] );
		$this->assertSame( 8, $fresh->checkpoint['transfer']['offset'] );
	}

	/**
	 * A refused progress save must stop sends even if the status still permits them.
	 *
	 * @dataProvider transferring_progress_save_refusals
	 * @param bool $steal Whether another token claims the lease during the save.
	 */
	public function test_transferring_refused_progress_save_stops_chunks( $steal ) {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job(
			array(
				'plan'     => $this->staged_plan_fixture(),
				'transfer' => array( 'chunk_bytes' => 4 ),
			),
			$store::STATUS_TRANSFERRING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$job   = $store->acquire_next_job( 'transfer-old', 90, time(), (int) $job->id );
		$token = new \ReflectionProperty( MainWP_Backup_Restore_Orchestrator::class, 'lock_token' );
		$token->setValue( $this->orchestrator, 'transfer-old' );
		$this->orchestrator->transfer_progress_interval = 0.0;

		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$chunks  = 0;
		$refused = false;
		$before  = null;
		$refuse  = function ( $sql ) use ( $wpdb, $store, $table, $job, $steal, &$chunks, &$refused, &$before ) {
			if ( $chunks > 0 && str_starts_with( $sql, 'UPDATE ' . $table . ' SET checkpoint = ' ) ) {
				if ( ! $refused ) {
					$before  = $store->get_job( (int) $job->id );
					$refused = true;
					if ( $steal ) {
						$wpdb->update(
							$table,
							array(
								'locked_by'    => 'transfer-new',
								'locked_until' => time() + 90,
							),
							array( 'id' => $job->id )
						);
					}
				}
				return $steal ? $sql : $sql . ' AND 1 = 0';
			}
			return $sql;
		};
		$child   = function ( $pre, $website, $what, $params ) use ( &$chunks ) {
			if ( 'restore_artifact_chunk' !== $what ) {
				return $pre;
			}
			++$chunks;
			return array(
				'result'         => 'ok',
				'bytes_received' => (int) $params['offset'] + strlen( base64_decode( $params['chunk_b64'] ) ),
			);
		};
		add_filter( 'query', $refuse );
		add_filter( 'mainwp_fetch_url_authed_pre', $child, 20, 4 );
		try {
			$fresh = $this->tick( $job );
		} finally {
			remove_filter( 'query', $refuse );
			remove_filter( 'mainwp_fetch_url_authed_pre', $child, 20 );
			$token->setValue( $this->orchestrator, null );
		}
		$this->assertTrue( $refused );
		$this->assertSame( 1, $chunks );
		$this->assertSame( $steal ? 'transfer-new' : 'transfer-old', $fresh->locked_by );
		$this->assertSame( $before->checkpoint, $fresh->checkpoint );
		$this->assertSame( $before->checkpoint_rev, $fresh->checkpoint_rev );
		$this->assertSame( $store::STATUS_TRANSFERRING, $fresh->status );
	}

	/**
	 * A lease fence and a refused CAS both stop the transfer.
	 *
	 * @return array Save refusals.
	 */
	public static function transferring_progress_save_refusals() {
		return array(
			'lease reclaimed' => array( true ),
			'CAS refused'     => array( false ),
		);
	}

	/**
	 * The Child may have more bytes than the last durable cursor after a crash.
	 *
	 * @dataProvider transferring_resume_checksum_results
	 * @param bool $checksum_ok   Whether the Child accepts the final artifact checksum.
	 * @param bool $duplicate_ack Whether the Child acks a resent chunk instead of reporting a gap.
	 */
	public function test_transferring_resumes_saved_progress_with_realign_and_checksum( $checksum_ok, $duplicate_ack ) {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$plan  = $this->staged_plan_fixture();
		$job   = $this->make_job(
			array(
				'plan'     => $plan,
				'transfer' => array( 'chunk_bytes' => 4 ),
			),
			$store::STATUS_TRANSFERRING,
			array( 'child_task_id' => 'task-9-restore' )
		);
		$this->orchestrator->transfer_progress_interval = 0.0;

		$received     = '';
		$offsets      = array();
		$final_checks = 0;
		$child        = function ( $pre, $website, $what, $params ) use ( $plan, $checksum_ok, $duplicate_ack, &$received, &$offsets, &$final_checks ) {
			if ( 'restore_artifact_chunk' !== $what ) {
				return $pre;
			}
			$offsets[] = (int) $params['offset'];
			$raw       = base64_decode( $params['chunk_b64'] );
			$this->assertSame( hash( 'sha256', $raw ), $params['chunk_sha256'] );
			// The real Child acks a resend of bytes it already holds and reports a gap only for an offset past its size.
			if ( $duplicate_ack && (int) $params['offset'] < strlen( $received ) ) {
				return array(
					'result'         => 'ok',
					'bytes_received' => strlen( $received ),
				);
			}
			if ( strlen( $received ) !== (int) $params['offset'] ) {
				return array(
					'error_code'     => 'mainwp_restore_chunk_gap',
					'bytes_received' => strlen( $received ),
				);
			}
			$received .= $raw;
			if ( ! empty( $params['final'] ) ) {
				++$final_checks;
				$this->assertSame( $plan[0]['sha256'], hash( 'sha256', $received ) );
				if ( ! $checksum_ok ) {
					return array( 'error' => 'Artifact checksum mismatch.' );
				}
			}
			return array(
				'result'         => 'ok',
				'bytes_received' => strlen( $received ),
			);
		};
		$crash        = function ( $task_id, $file, $cursor ) {
			if ( 4 === $cursor['offset'] ) {
				throw new \RuntimeException( 'Simulated worker crash.' );
			}
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $child, 20, 4 );
		add_action( 'mainwp_backup_diag_restore_transfer', $crash, 20, 3 );
		try {
			$crashed = false;
			try {
				$this->tick( $job );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'Simulated worker crash.', $e->getMessage() );
				$crashed = true;
			} finally {
				remove_action( 'mainwp_backup_diag_restore_transfer', $crash, 20 );
			}
			$this->assertTrue( $crashed );
			$fresh = $store->get_job( (int) $job->id );
			$this->assertSame( $store::STATUS_TRANSFERRING, $fresh->status );
			$this->assertSame( 4, $fresh->checkpoint['transfer']['offset'] ?? 0 );
			$this->assertSame( 8, strlen( $received ) );
			$fresh = $this->tick( $fresh );
		} finally {
			remove_action( 'mainwp_backup_diag_restore_transfer', $crash, 20 );
			remove_filter( 'mainwp_fetch_url_authed_pre', $child, 20 );
		}
		$this->assertSame( array( 0, 4, 4, 8 ), $offsets );
		$this->assertSame( 'dump-bytes', $received );
		$this->assertSame( 1, $final_checks );
		$this->assertSame( $plan, $fresh->checkpoint['plan'] );
		$this->assertSame( $checksum_ok ? $store::STATUS_RUNNING : $store::STATUS_FAILED_RETRYABLE, $fresh->status );
		if ( ! $checksum_ok ) {
			$this->assertSame( 'Artifact checksum mismatch.', $fresh->last_error );
		}
	}

	/**
	 * Resume must preserve both successful verification and checksum rejection,
	 * whichever way the Child answers the resent chunk.
	 *
	 * @return array Child checksum results and resend replies.
	 */
	public static function transferring_resume_checksum_results() {
		return array(
			'checksum accepted, gap'           => array( true, false ),
			'checksum rejected, gap'           => array( false, false ),
			'checksum accepted, duplicate ack' => array( true, true ),
			'checksum rejected, duplicate ack' => array( false, true ),
		);
	}

	/**
	 * Fast chunks share a checkpoint window; the final save is independent.
	 */
	public function test_transferring_default_throttle_saves_once_per_window() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job(
			array(
				'plan'     => $this->staged_plan_fixture(),
				'transfer' => array( 'chunk_bytes' => 1 ),
			),
			$store::STATUS_TRANSFERRING,
			array( 'child_task_id' => 'task-9-restore' )
		);

		$this->orchestrator->transfer_progress_now = 100.0;

		$times   = array( 101.0, 102.999, 103.0, 103.001, 105.999, 106.0, 106.001, 108.999, 109.0, 109.001 );
		$chunks  = 0;
		$writes  = array();
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$observe = function ( $sql ) use ( $table, &$writes, &$chunks ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET checkpoint = ' ) ) {
				$writes[] = array( $this->orchestrator->transfer_progress_now, $chunks );
			}
			return $sql;
		};
		$child   = function ( $pre, $website, $what, $params ) use ( $times, &$chunks ) {
			if ( 'restore_artifact_chunk' !== $what ) {
				return $pre;
			}
			$this->orchestrator->transfer_progress_now = $times[ $chunks ];
			++$chunks;
			return array(
				'result'         => 'ok',
				'bytes_received' => (int) $params['offset'] + strlen( base64_decode( $params['chunk_b64'] ) ),
			);
		};
		add_filter( 'query', $observe );
		add_filter( 'mainwp_fetch_url_authed_pre', $child, 20, 4 );
		try {
			$fresh = $this->tick( $job );
		} finally {
			remove_filter( 'query', $observe );
			remove_filter( 'mainwp_fetch_url_authed_pre', $child, 20 );
		}
		$this->assertSame( 10, $chunks );
		$this->assertSame( array( array( 103.0, 3 ), array( 106.0, 6 ), array( 109.0, 9 ), array( 109.001, 10 ) ), $writes );
		$this->assertSame( $store::STATUS_RUNNING, $fresh->status );
		$this->assertSame( 1, $fresh->checkpoint['transfer']['artifact_index'] );
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
		$this->assertSame( array( 'restore_task_cancel' ), $this->calls_without_report_reads() );
		$this->assertNotContains( 'restore_artifact_chunk', $this->calls_without_report_reads() );
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
		$this->assertSame( array( 'restore_task_cancel', 'restore_task_run' ), $this->calls_without_report_reads() );

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
		$this->assertNotContains( 'restore_artifact_chunk', $this->calls_without_report_reads() );
		$this->assertSame( array( 'restore_task_cancel', 'restore_task_run' ), $this->calls_without_report_reads() );
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

		$this->assertNotContains( 'restore_artifact_chunk', $this->calls_without_report_reads() );
		$this->assertSame( array( 'restore_task_cancel' ), $this->calls_without_report_reads() );
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
			$this->assertSame( $expected_actions, $this->calls_without_report_reads(), $label );
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
		$destinations->add_destination(
			array(
				'provider'   => 'local',
				'name'       => 'Unused Default',
				'is_default' => 1,
			)
		);

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
		$store       = MainWP_Backup_Upload_Job_Store::instance();
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
		$this->assertSame( 'tasks', $response['destinations_source'] );
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
		$this->assertSame( 'none', $response['destinations_source'] );
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
			'task_id'   => 'safety-task',
			'owner'     => '42',
			'driven_at' => time(),
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
			'task_id'   => 'safety-task',
			'owner'     => '42',
			'started'   => 1,
			'driven_at' => time(),
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

	/**
	 * Safety artifacts recorded on a job, shared by the rollback tests below.
	 *
	 * @return array Checkpoint fragment.
	 */
	private function safety_checkpoint() {
		return array(
			'pre_snapshot_artifacts' => array(
				array(
					'file' => 'backup-pre.tar.gz',
					'size' => 1234,
					'role' => 'full',
				),
			),
		);
	}

	/**
	 * Start a rollback and return the refusal message.
	 *
	 * @param object $job Job to roll back.
	 *
	 * @return string Exception message, or '' when the rollback started.
	 */
	private function rollback_refusal( $job ) {
		try {
			$this->orchestrator->initiate_rollback( $job );
		} catch ( MainWP_Exception $e ) {
			return $e->getMessage();
		}
		return '';
	}

	/**
	 * A restore that ran without a safety backup says so.
	 */
	public function test_rollback_refusal_without_safety_backup_says_none_was_taken() {
		$failed = $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT );

		$this->assertSame( 'No safety backup was taken before this restore, so there is nothing to roll back to.', $this->rollback_refusal( $failed ) );
	}

	/**
	 * A restore whose safety backup already has a rollback says that instead
	 * of claiming no safety backup exists.
	 */
	public function test_rollback_refusal_after_started_rollback_says_already_started() {
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$failed = $this->make_job( $this->safety_checkpoint(), $store::STATUS_FAILED_PERMANENT );
		$this->make_job( array( 'rollback_of' => (int) $failed->id ), $store::STATUS_FAILED_PERMANENT );

		$this->assertSame( 'A rollback has already been started for this restore, so it cannot be rolled back again.', $this->rollback_refusal( $failed ) );
	}

	/**
	 * A completed restore keeps its safety backup and offers a rollback to it.
	 */
	public function test_completed_restore_keeps_safety_backup_for_rollback() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job( $this->safety_checkpoint(), $store::STATUS_VERIFYING, array( 'pre_snapshot_task_id' => 'task-own-pre' ) );

		$job = $this->tick( $job );
		$this->assertSame( $store::STATUS_COMPLETED, $job->status );
		$this->assertNotContains( 'backup_task_prune', $this->calls_without_report_reads(), 'The safety backup stays on the site after a completed restore.' );
		$this->assertTrue( $this->orchestrator->has_rollback_source( $job ) );

		$rollback_id = $this->orchestrator->initiate_rollback( $job );
		$rollback    = $store->get_job( $rollback_id );
		$this->assertTrue( $rollback->checkpoint['is_rollback'] );
		$this->assertSame( (int) $job->id, $rollback->checkpoint['rollback_of'] );
		$this->assertTrue( $rollback->checkpoint['plan'][0]['adopt_only'] );
		$this->assertFalse( $this->orchestrator->has_rollback_source( $job ), 'Its rollback now owns the safety backup.' );
	}

	/**
	 * The next completed restore on the site removes the earlier restore's
	 * safety backup, so the child keeps at most one from completed restores.
	 */
	public function test_later_completed_restore_prunes_earlier_safety_backup() {
		$store   = MainWP_Backup_Restore_Job_Store::instance();
		$earlier = $this->make_job( $this->safety_checkpoint(), $store::STATUS_COMPLETED, array( 'pre_snapshot_task_id' => 'task-earlier-pre' ) );
		$later   = $this->make_job( $this->safety_checkpoint(), $store::STATUS_VERIFYING, array( 'pre_snapshot_task_id' => 'task-later-pre' ) );

		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );
		$later                                = $this->tick( $later );
		$this->assertSame( $store::STATUS_COMPLETED, $later->status );

		$prunes = array_values(
			array_filter(
				$this->calls,
				static function ( $call ) {
					return 'backup_task_prune' === $call['what'];
				}
			)
		);
		$this->assertCount( 1, $prunes );
		$this->assertSame( '["task-earlier-pre"]', $prunes[0]['params']['task_ids'] );

		$earlier = $store->get_job( (int) $earlier->id );
		$this->assertTrue( $earlier->checkpoint['pre_snapshot_pruned'] );
		$this->assertFalse( $this->orchestrator->has_rollback_source( $earlier ) );
		$this->assertSame( 'The safety backup from this restore was replaced by a later restore, so there is nothing to roll back to.', $this->rollback_refusal( $earlier ) );
		$this->assertTrue( $this->orchestrator->has_rollback_source( $later ), 'The newest completed restore keeps its own safety backup.' );

		// A second completion must not prune the same safety backup again.
		$this->calls = array();
		$third       = $this->make_job( array(), $store::STATUS_VERIFYING );
		$this->tick( $third );
		$this->assertSame( array( '["task-later-pre"]' ), array_column( array_column( $this->calls, 'params' ), 'task_ids' ) );
	}

	/**
	 * The child's answer to a one-task prune request.
	 *
	 * @param string $outcome pruned, active or missing.
	 *
	 * @return array Child response.
	 */
	private function prune_answer( $outcome ) {
		$answer = array(
			'result'  => 'ok',
			'pruned'  => 0,
			'active'  => 0,
			'missing' => 0,
		);
		++$answer[ $outcome ];
		return $answer;
	}

	/**
	 * A rollback of the earlier restore cannot start while the completing
	 * restore removes that restore's safety backup, so no rollback is left
	 * pointing at artifacts the child is deleting.
	 */
	public function test_rollback_of_earlier_restore_is_refused_while_its_safety_backup_is_pruned() {
		$store   = MainWP_Backup_Restore_Job_Store::instance();
		$earlier = $this->make_job( $this->safety_checkpoint(), $store::STATUS_COMPLETED, array( 'pre_snapshot_task_id' => 'task-earlier-pre' ) );
		$later   = $this->make_job( $this->safety_checkpoint(), $store::STATUS_VERIFYING, array( 'pre_snapshot_task_id' => 'task-later-pre' ) );

		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );
		$refusal                              = null;
		$during_prune                         = function ( $pre, $website, $what ) use ( &$refusal, $store, $earlier ) {
			if ( 'backup_task_prune' === $what && null === $refusal ) {
				$refusal = $this->rollback_refusal( $store->get_job( (int) $earlier->id ) );
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $during_prune, 5, 3 );
		try {
			$later = $this->tick( $later );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $during_prune, 5 );
		}

		$this->assertSame( $store::STATUS_COMPLETED, $later->status );
		$this->assertSame( 'A restore is already running for this site. Wait for it to finish before rolling back.', $refusal, 'The completing restore must still hold the site while it prunes.' );
		$this->assertTrue( $store->get_job( (int) $earlier->id )->checkpoint['pre_snapshot_pruned'] );
		$this->assertNull( $store->get_active_job_for_site( $this->site_id ), 'No rollback was created.' );
	}

	/**
	 * A failed rollback of the earlier restore still restores from that
	 * safety backup, so a later completion leaves it on the site.
	 */
	public function test_prune_keeps_safety_backup_a_failed_rollback_can_retry() {
		$store    = MainWP_Backup_Restore_Job_Store::instance();
		$earlier  = $this->make_job( $this->safety_checkpoint(), $store::STATUS_COMPLETED, array( 'pre_snapshot_task_id' => 'task-earlier-pre' ) );
		$rollback = $this->make_job(
			$this->safety_checkpoint() + array(
				'is_rollback' => true,
				'rollback_of' => (int) $earlier->id,
			),
			$store::STATUS_FAILED_PERMANENT
		);
		$later    = $this->make_job( $this->safety_checkpoint(), $store::STATUS_VERIFYING, array( 'pre_snapshot_task_id' => 'task-later-pre' ) );

		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );
		$this->assertSame( $store::STATUS_COMPLETED, $this->tick( $later )->status );

		$this->assertNotContains( 'backup_task_prune', $this->calls_without_report_reads() );
		$this->assertArrayNotHasKey( 'pre_snapshot_pruned', $store->get_job( (int) $earlier->id )->checkpoint );
		$this->assertTrue( $this->orchestrator->has_rollback_source( $store->get_job( (int) $rollback->id ) ) );
	}

	/**
	 * Only a confirmed removal retires a safety backup: the child answers per
	 * task, and anything it did not confirm stays for the next completion.
	 *
	 * @dataProvider data_prune_answers
	 *
	 * @param mixed $answer  Child response, or a Throwable it throws.
	 * @param bool  $retired Whether the earlier safety backup counts as removed.
	 */
	public function test_prune_marks_only_confirmed_removals( $answer, $retired ) {
		$store   = MainWP_Backup_Restore_Job_Store::instance();
		$earlier = $this->make_job( $this->safety_checkpoint(), $store::STATUS_COMPLETED, array( 'pre_snapshot_task_id' => 'task-earlier-pre' ) );
		$later   = $this->make_job( $this->safety_checkpoint(), $store::STATUS_VERIFYING, array( 'pre_snapshot_task_id' => 'task-later-pre' ) );

		$this->responses['backup_task_prune'] = is_string( $answer ) ? $this->prune_answer( $answer ) : $answer;
		$this->assertSame( $store::STATUS_COMPLETED, $this->tick( $later )->status );

		$earlier = $store->get_job( (int) $earlier->id );
		$this->assertSame( $retired, ! empty( $earlier->checkpoint['pre_snapshot_pruned'] ) );
		$this->assertFalse( $this->orchestrator->has_rollback_source( $earlier ), 'The later completion supersedes it either way.' );
	}

	/**
	 * A completed restore followed by a later completed one offers no
	 * rollback even without a recorded prune, as for restores that completed
	 * before the prune was recorded.
	 */
	public function test_superseded_completed_restore_offers_no_rollback() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store   = MainWP_Backup_Restore_Job_Store::instance();
		$earlier = $this->make_job( $this->safety_checkpoint(), $store::STATUS_COMPLETED );
		$later   = $this->make_job( $this->safety_checkpoint(), $store::STATUS_COMPLETED );

		$this->assertFalse( $this->orchestrator->has_rollback_source( $earlier ) );
		$this->assertTrue( $this->orchestrator->has_rollback_source( $later ) );
		$this->assertSame( 'The safety backup from this restore was replaced by a later restore, so there is nothing to roll back to.', $this->rollback_refusal( $earlier ) );
		$this->assertSame( array( (int) $later->id ), array_column( $this->b194_reload_snapshots()['failed_restore_jobs'], 'job_id' ) );
	}

	/**
	 * Child answers to a prune request.
	 *
	 * @return array
	 */
	public function data_prune_answers() {
		return array(
			'pruned'          => array( 'pruned', true ),
			'already missing' => array( 'missing', true ),
			'still active'    => array( 'active', false ),
			'no counts'       => array( array( 'result' => 'ok' ), false ),
			'error envelope'  => array( array( 'error' => 'Denied' ), false ),
			'unreachable'     => array( new MainWP_Exception( 'Site unreachable.' ), false ),
			'junk counts'     => array(
				array(
					'result'  => 'ok',
					'pruned'  => array( 'junk' ),
					'active'  => 1,
					'missing' => 0,
				),
				false,
			),
			'ok with error'   => array(
				array(
					'result'  => 'ok',
					'error'   => 'Denied',
					'pruned'  => 1,
					'active'  => 0,
					'missing' => 0,
				),
				false,
			),
		);
	}

	/**
	 * A safety backup the child kept because its task was still active is
	 * retried by the next completed restore.
	 */
	public function test_unconfirmed_prune_is_retried_by_next_completion() {
		$store   = MainWP_Backup_Restore_Job_Store::instance();
		$earlier = $this->make_job( $this->safety_checkpoint(), $store::STATUS_COMPLETED, array( 'pre_snapshot_task_id' => 'task-earlier-pre' ) );
		$later   = $this->make_job( $this->safety_checkpoint(), $store::STATUS_VERIFYING, array( 'pre_snapshot_task_id' => 'task-later-pre' ) );

		$this->responses['backup_task_prune'] = $this->prune_answer( 'active' );
		$this->tick( $later );

		$this->calls                          = array();
		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );
		$third                                = $this->make_job( array(), $store::STATUS_VERIFYING );
		$this->tick( $third );

		$this->assertSame( array( '["task-earlier-pre"]', '["task-later-pre"]' ), array_column( array_column( $this->calls, 'params' ), 'task_ids' ) );
		$this->assertTrue( $store->get_job( (int) $earlier->id )->checkpoint['pre_snapshot_pruned'] );
	}

	/**
	 * The task_ids of every prune request sent so far, in order.
	 *
	 * @return array
	 */
	private function prune_requests() {
		$prunes = array();
		foreach ( $this->calls as $call ) {
			if ( 'backup_task_prune' === $call['what'] ) {
				$prunes[] = $call['params']['task_ids'];
			}
		}
		return $prunes;
	}

	/**
	 * Script a completed safety backup and return a job in pre_snapshot.
	 *
	 * @param string $task_id Safety backup task id.
	 *
	 * @return object Job row.
	 */
	private function make_pre_snapshot_job( $task_id ) {
		$this->responses['backup_task_run'] = array(
			'task_id'   => $task_id,
			'phase'     => 'completed',
			'artifacts' => array(
				array(
					'file' => 'backup-new.tar.gz',
					'size' => 1234,
					'role' => 'full',
				),
			),
		);
		return $this->make_job( array(), MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => $task_id ) );
	}

	/**
	 * Once a restore's new safety backup is confirmed, every older safety
	 * backup on the site goes, whatever the older restore's outcome, and none
	 * of those restores offers Roll Back any more.
	 */
	public function test_confirmed_safety_backup_retires_older_ones_of_any_outcome() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store     = MainWP_Backup_Restore_Job_Store::instance();
		$completed = $this->make_job( $this->safety_checkpoint(), $store::STATUS_COMPLETED, array( 'pre_snapshot_task_id' => 'task-completed-pre' ) );
		$failed    = $this->make_job( $this->safety_checkpoint(), $store::STATUS_FAILED_PERMANENT, array( 'pre_snapshot_task_id' => 'task-failed-pre' ) );
		$cancelled = $this->make_job( $this->safety_checkpoint(), $store::STATUS_CANCELLED, array( 'pre_snapshot_task_id' => 'task-cancelled-pre' ) );
		$this->assertTrue( $this->orchestrator->has_rollback_source( $failed ) );
		$this->assertTrue( $this->orchestrator->has_rollback_source( $cancelled ) );

		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );
		$job                                  = $this->tick( $this->make_pre_snapshot_job( 'task-new-pre' ) );

		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertSame( array( '["task-completed-pre"]', '["task-failed-pre"]', '["task-cancelled-pre"]' ), $this->prune_requests(), 'One task id per request, oldest first.' );
		// End the new restore so the refusal below is about the source, not the busy site.
		$this->assertTrue( $store->set_status( (int) $job->id, $store::STATUS_FAILED_PERMANENT ) );
		foreach ( array( $completed, $failed, $cancelled ) as $earlier ) {
			$earlier = $store->get_job( (int) $earlier->id );
			$this->assertTrue( $earlier->checkpoint['pre_snapshot_pruned'] );
			$this->assertFalse( $this->orchestrator->has_rollback_source( $earlier ) );
			$this->assertSame( 'The safety backup from this restore was replaced by a later restore, so there is nothing to roll back to.', $this->rollback_refusal( $earlier ) );
		}

		$rows = array_column( $this->b194_reload_snapshots()['failed_restore_jobs'], null, 'job_id' );
		$this->assertFalse( $rows[ $failed->id ]['has_rollback'] );
		$this->assertFalse( $rows[ $cancelled->id ]['has_rollback'] );
		$this->assertArrayNotHasKey( (int) $completed->id, $rows );
	}

	/**
	 * A safety backup the child did not confirm removing stays offered and is
	 * retried by the next confirmed safety backup.
	 *
	 * @dataProvider data_prune_answers
	 *
	 * @param mixed $answer  Child response, or a Throwable it throws.
	 * @param bool  $retired Whether the earlier safety backup counts as removed.
	 */
	public function test_confirmed_safety_backup_marks_only_confirmed_removals( $answer, $retired ) {
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$failed = $this->make_job( $this->safety_checkpoint(), $store::STATUS_FAILED_PERMANENT, array( 'pre_snapshot_task_id' => 'task-failed-pre' ) );

		$this->responses['backup_task_prune'] = is_string( $answer ) ? $this->prune_answer( $answer ) : $answer;
		$this->assertSame( $store::STATUS_STAGING, $this->tick( $this->make_pre_snapshot_job( 'task-new-pre' ) )->status );

		$failed = $store->get_job( (int) $failed->id );
		$this->assertSame( $retired, ! empty( $failed->checkpoint['pre_snapshot_pruned'] ) );
		$this->assertSame( ! $retired, $this->orchestrator->has_rollback_source( $failed ) );
	}

	/**
	 * A failed rollback can still be retried from its safety backup, so a
	 * new safety backup leaves that one on the site.
	 */
	public function test_confirmed_safety_backup_keeps_one_a_failed_rollback_can_retry() {
		$store    = MainWP_Backup_Restore_Job_Store::instance();
		$failed   = $this->make_job( $this->safety_checkpoint(), $store::STATUS_FAILED_PERMANENT, array( 'pre_snapshot_task_id' => 'task-failed-pre' ) );
		$rollback = $this->make_job(
			$this->safety_checkpoint() + array(
				'is_rollback' => true,
				'rollback_of' => (int) $failed->id,
			),
			$store::STATUS_FAILED_PERMANENT
		);

		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );
		$this->assertSame( $store::STATUS_STAGING, $this->tick( $this->make_pre_snapshot_job( 'task-new-pre' ) )->status );

		$this->assertSame( array(), $this->prune_requests() );
		$this->assertArrayNotHasKey( 'pre_snapshot_pruned', $store->get_job( (int) $failed->id )->checkpoint );
		$this->assertTrue( $this->orchestrator->has_rollback_source( $store->get_job( (int) $rollback->id ) ) );
	}

	/**
	 * A completed rollback retires the safety backup it restored from, also
	 * when it retried an earlier failed rollback.
	 *
	 * @dataProvider data_rollback_chain_lengths
	 *
	 * @param int $failed_rollbacks Failed rollbacks before the one that completes.
	 */
	public function test_completed_rollback_retires_its_safety_backup( $failed_rollbacks ) {
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$failed = $this->make_job( $this->safety_checkpoint(), $store::STATUS_FAILED_PERMANENT, array( 'pre_snapshot_task_id' => 'task-source-pre' ) );
		$source = $failed;
		for ( $i = 0; $i < $failed_rollbacks; ++$i ) {
			$id = $this->orchestrator->initiate_rollback( $source );
			$this->assertTrue( $store->set_status( $id, $store::STATUS_FAILED_PERMANENT ) );
			$source = $store->get_job( $id );
		}
		$rollback_id = $this->orchestrator->initiate_rollback( $source );
		$this->assertTrue( $store->set_status( $rollback_id, $store::STATUS_VERIFYING ) );

		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );
		$rollback                             = $this->tick( $store->get_job( $rollback_id ) );

		$this->assertSame( $store::STATUS_COMPLETED, $rollback->status );
		$this->assertSame( array( '["task-source-pre"]' ), $this->prune_requests() );
		$this->assertTrue( $store->get_job( (int) $failed->id )->checkpoint['pre_snapshot_pruned'] );
		$this->assertFalse( $this->orchestrator->has_rollback_source( $store->get_job( (int) $failed->id ) ) );
		$this->assertFalse( $this->orchestrator->has_rollback_source( $rollback ) );
	}

	/**
	 * Rollback chains: a direct rollback, and one retried after a failure.
	 *
	 * @return array
	 */
	public static function data_rollback_chain_lengths() {
		return array(
			'direct rollback'  => array( 0 ),
			'retried rollback' => array( 1 ),
		);
	}

	/**
	 * A restore that ends while its safety backup still runs on the child
	 * cancels that backup; one the child already finished is pruned, since no
	 * rollback can ever use it.
	 *
	 * @dataProvider data_running_safety_backup_cancel_answers
	 *
	 * @param array $answer Child answer to the cancel.
	 * @param array $prunes Expected prune requests.
	 */
	public function test_terminal_failure_cancels_running_safety_backup( $answer, $prunes ) {
		$this->responses['backup_task_run']    = array( 'fetch_url_output' => 'Service Unavailable' );
		$this->responses['backup_task_cancel'] = $answer;
		$this->responses['backup_task_prune']  = $this->prune_answer( 'pruned' );
		$job                                   = $this->make_job(
			array(),
			MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
			array(
				'pre_snapshot_task_id' => 'task-7-pre',
				'attempt_count'        => MainWP_Backup_Backoff::MAX_ATTEMPTS - 1,
			)
		);
		MainWP_DB::instance()->update_website_option(
			$this->site_id,
			MainWP_Backup_Executor::ADHOC_TASK_OPTION,
			wp_json_encode(
				array(
					'task_id' => 'task-7-pre',
					'owner'   => (string) $job->id,
				)
			)
		);
		$job = $this->tick( $job );

		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$cancels = array_values(
			array_filter(
				$this->calls,
				static function ( $call ) {
					return 'backup_task_cancel' === $call['what'];
				}
			)
		);
		$this->assertCount( 1, $cancels );
		$this->assertSame( 'task-7-pre', $cancels[0]['params']['task_id'] );
		$this->assertSame( $prunes, $this->prune_requests() );
		$this->assertNull( MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
	}

	/**
	 * Child answers to cancelling a safety backup.
	 *
	 * @return array
	 */
	public function data_running_safety_backup_cancel_answers() {
		return array(
			'cancelled'      => array(
				array(
					'result' => 'ok',
					'phase'  => 'failed',
				),
				array(),
			),
			'already done'   => array(
				array(
					'result' => 'already_terminal',
					'phase'  => 'completed',
				),
				array( '["task-7-pre"]' ),
			),
			'error envelope' => array( array( 'error' => 'Backup task not found.' ), array() ),
			'unreachable'    => array( new MainWP_Exception( 'Site unreachable.' ), array() ),
		);
	}

	/**
	 * Run a callback while restore-job UPDATEs whose SQL contains a marker
	 * fail, as a lost database write would.
	 *
	 * @param string   $marker   Text the failing UPDATE carries.
	 * @param callable $callback Work to run.
	 *
	 * @return mixed Callback result.
	 */
	private function with_failing_job_update( $marker, callable $callback ) {
		global $wpdb;
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$fail     = static function ( $sql ) use ( $marker, $table ) {
			if ( 0 === strpos( $sql, 'UPDATE ' . $table ) && false !== strpos( $sql, $marker ) ) {
				return 'SELECT * FROM mainwp_test_no_such_table';
			}
			return $sql;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			return $callback();
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
	}

	/**
	 * A stale rollback request re-reads its source after taking the site,
	 * so a safety backup a later restore retired meanwhile is refused.
	 */
	public function test_stale_rollback_request_rechecks_its_source() {
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$failed = $this->make_job( $this->safety_checkpoint(), $store::STATUS_FAILED_PERMANENT, array( 'pre_snapshot_task_id' => 'task-failed-pre' ) );
		$stale  = $store->get_job( (int) $failed->id );
		$this->assertTrue( $store->save_checkpoint( (int) $failed->id, $failed->checkpoint + array( 'pre_snapshot_pruned' => true ) ) );

		$this->assertSame( 'The safety backup from this restore was replaced by a later restore, so there is nothing to roll back to.', $this->rollback_refusal( $stale ) );
		$this->assertNull( $store->get_active_job_for_site( $this->site_id ), 'No rollback was created.' );
		$reservation = $store->reserve_site( $this->site_id );
		$this->assertGreaterThan( 0, $reservation, 'The refusal gave the site back.' );
		$store->release_reservation( $reservation );
	}

	/**
	 * A completed answer that names no usable artifact of the requested task
	 * fails the restore like a failed safety backup: older safety backups
	 * stay, and the unusable Child task is stopped.
	 *
	 * @dataProvider data_unusable_safety_backup_answers
	 *
	 * @param array $payload Child answer to backup_task_run.
	 */
	public function test_unusable_safety_backup_completion_fails_the_restore( $payload ) {
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$failed = $this->make_job( $this->safety_checkpoint(), $store::STATUS_FAILED_PERMANENT, array( 'pre_snapshot_task_id' => 'task-failed-pre' ) );
		$job    = $this->make_pre_snapshot_job( 'task-new-pre' );
		MainWP_DB::instance()->update_website_option(
			$this->site_id,
			MainWP_Backup_Executor::ADHOC_TASK_OPTION,
			wp_json_encode(
				array(
					'task_id' => 'task-new-pre',
					'owner'   => (string) $job->id,
				)
			)
		);
		$this->responses['backup_task_run']   = $payload;
		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );

		$job = $this->tick( $job );

		$this->assertSame( $store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'The pre-restore safety backup failed.', $job->last_error );
		$this->assertArrayNotHasKey( 'pre_snapshot_artifacts', $job->checkpoint );
		$this->assertNotContains( '["task-failed-pre"]', $this->prune_requests(), 'An older safety backup stays when no new one replaced it.' );
		$this->assertTrue( $this->orchestrator->has_rollback_source( $store->get_job( (int) $failed->id ) ) );
		$this->assertContains( 'backup_task_cancel', $this->calls_without_report_reads(), 'The unusable Child task is stopped.' );
	}

	/**
	 * Completed answers that cannot serve as a rollback source.
	 *
	 * @return array
	 */
	public static function data_unusable_safety_backup_answers() {
		$file = array(
			array(
				'file' => 'backup-new.tar.gz',
				'size' => 1234,
				'role' => 'full',
			),
		);
		return array(
			'empty artifacts' => array(
				array(
					'task_id'   => 'task-new-pre',
					'phase'     => 'completed',
					'artifacts' => array(),
				),
			),
			'another task'    => array(
				array(
					'task_id'   => 'task-other',
					'phase'     => 'completed',
					'artifacts' => $file,
				),
			),
			'no task id'      => array(
				array(
					'phase'     => 'completed',
					'artifacts' => $file,
				),
			),
			'path in name'    => array(
				array(
					'task_id'   => 'task-new-pre',
					'phase'     => 'completed',
					'artifacts' => array( array( 'file' => '../backup-new.tar.gz' ) ),
				),
			),
			'entry no file'   => array(
				array(
					'task_id'   => 'task-new-pre',
					'phase'     => 'completed',
					'artifacts' => array( array( 'size' => 3 ) ),
				),
			),
		);
	}

	/**
	 * A safety backup whose record cannot be written keeps its handle and
	 * phase and retries; nothing older is pruned and the restore does not
	 * advance.
	 */
	public function test_unrecorded_safety_backup_stays_in_pre_snapshot() {
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$failed = $this->make_job( $this->safety_checkpoint(), $store::STATUS_FAILED_PERMANENT, array( 'pre_snapshot_task_id' => 'task-failed-pre' ) );
		$job    = $this->make_pre_snapshot_job( 'task-new-pre' );
		$handle = wp_json_encode(
			array(
				'task_id' => 'task-new-pre',
				'owner'   => (string) $job->id,
			)
		);
		MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handle );
		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );

		$job = $this->with_failing_job_update(
			'backup-new.tar.gz',
			function () use ( $job ) {
				return $this->tick( $job );
			}
		);

		$this->assertSame( $store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( $store::STATUS_PRE_SNAPSHOT, $job->checkpoint['resume_status'] );
		$this->assertSame( $handle, MainWP_DB::instance()->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
		$this->assertSame( array(), $this->prune_requests() );
		$this->assertTrue( $this->orchestrator->has_rollback_source( $store->get_job( (int) $failed->id ) ) );

		// The next tick records it and moves on.
		$job = $this->tick( $job );
		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertSame( 'backup-new.tar.gz', $job->checkpoint['pre_snapshot_artifacts'][0]['file'] );
	}

	/**
	 * A removal the child confirmed but the Dashboard could not record must
	 * still stop that restore offering Roll Back.
	 */
	public function test_confirmed_prune_whose_record_fails_offers_no_rollback() {
		$store                                = MainWP_Backup_Restore_Job_Store::instance();
		$failed                               = $this->make_job( $this->safety_checkpoint(), $store::STATUS_FAILED_PERMANENT, array( 'pre_snapshot_task_id' => 'task-failed-pre' ) );
		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );

		$job = $this->with_failing_job_update(
			'pre_snapshot_pruned',
			function () {
				return $this->tick( $this->make_pre_snapshot_job( 'task-new-pre' ) );
			}
		);

		$this->assertSame( $store::STATUS_STAGING, $job->status );
		$this->assertSame( array( '["task-failed-pre"]' ), $this->prune_requests() );
		$this->assertFalse( $this->orchestrator->has_rollback_source( $store->get_job( (int) $failed->id ) ) );
	}

	/**
	 * A completing restore also retires failed and cancelled restores'
	 * safety backups that no earlier pass confirmed removing.
	 */
	public function test_completion_retires_failed_and_cancelled_safety_backups() {
		$store                                = MainWP_Backup_Restore_Job_Store::instance();
		$failed                               = $this->make_job( $this->safety_checkpoint(), $store::STATUS_FAILED_PERMANENT, array( 'pre_snapshot_task_id' => 'task-failed-pre' ) );
		$cancelled                            = $this->make_job( $this->safety_checkpoint(), $store::STATUS_CANCELLED, array( 'pre_snapshot_task_id' => 'task-cancelled-pre' ) );
		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );

		$this->assertSame( $store::STATUS_COMPLETED, $this->tick( $this->make_job( array(), $store::STATUS_VERIFYING ) )->status );

		$this->assertSame( array( '["task-failed-pre"]', '["task-cancelled-pre"]' ), $this->prune_requests() );
		$this->assertTrue( $store->get_job( (int) $failed->id )->checkpoint['pre_snapshot_pruned'] );
		$this->assertTrue( $store->get_job( (int) $cancelled->id )->checkpoint['pre_snapshot_pruned'] );
	}

	/**
	 * A busy answer to stopping a still-running safety backup leaves its
	 * task id on the job, so the next confirmed safety backup prunes it.
	 */
	public function test_busy_safety_backup_cancel_is_pruned_by_the_next_restore() {
		$store                                 = MainWP_Backup_Restore_Job_Store::instance();
		$this->responses['backup_task_run']    = array( 'fetch_url_output' => 'Service Unavailable' );
		$this->responses['backup_task_cancel'] = array(
			'result' => 'busy',
			'phase'  => 'archiving',
		);
		$job                                   = $this->make_job(
			array(),
			$store::STATUS_PRE_SNAPSHOT,
			array(
				'pre_snapshot_task_id' => 'task-7-pre',
				'attempt_count'        => MainWP_Backup_Backoff::MAX_ATTEMPTS - 1,
			)
		);
		MainWP_DB::instance()->update_website_option(
			$this->site_id,
			MainWP_Backup_Executor::ADHOC_TASK_OPTION,
			wp_json_encode(
				array(
					'task_id' => 'task-7-pre',
					'owner'   => (string) $job->id,
				)
			)
		);
		$job = $this->tick( $job );
		$this->assertSame( $store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 'task-7-pre', $job->pre_snapshot_task_id );

		$this->calls                          = array();
		$this->responses['backup_task_prune'] = $this->prune_answer( 'pruned' );
		$this->assertSame( $store::STATUS_STAGING, $this->tick( $this->make_pre_snapshot_job( 'task-new-pre' ) )->status );
		$this->assertSame( array( '["task-7-pre"]' ), $this->prune_requests() );
	}

	/**
	 * Clean Up's on-demand keep-count prune takes the site slot too, so a
	 * restore cannot be admitted for the on-demand snapshot it is deleting.
	 */
	public function test_cleanup_keep_count_prune_and_restore_admission_exclude_each_other() {
		global $wpdb;
		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$task    = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'delete_cloud_copies' => 0,
			)
		);
		// The hidden row's keep count follows the live on-demand setting.
		update_option( 'mainwp_on_demand_retention_count', 1 );
		$dir = MainWP_Backup_Private_Root::site_dir( $this->site_id );
		wp_mkdir_p( $dir );
		$ids = array();
		foreach ( array( 200, 100 ) as $age ) {
			$path = $dir . 'db-ondemand-' . uniqid() . '.sql.zip';
			file_put_contents( $path, 'on-demand-db-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'task_id'  => (int) $task->id,
					'site_id'  => $this->site_id,
					'type'     => 'db',
					'created'  => time() - $age,
					'archives' => wp_json_encode( array( array( 'file' => basename( $path ), 'size' => 18, 'role' => 'db', 'path' => $path ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
					'status'   => 'complete',
				)
			);
			$ids[]                      = (int) $wpdb->insert_id;
			$this->chain_snapshot_ids[] = (int) $wpdb->insert_id;
		}
		$older = $ids[0];

		$admitted = null;
		$race     = function ( $sql ) use ( &$admitted, $older ) {
			if ( null === $admitted && false !== strpos( $sql, 'WHERE snapshot_id IN' ) ) {
				$admitted = 'checking';
				try {
					$admitted = $this->orchestrator->initiate_restore( $this->site_id, $older, array( 'confirm_destructive' => true ) );
				} catch ( MainWP_Exception $e ) {
					$admitted = $e->getMessage();
				}
			}
			return $sql;
		};
		add_filter( 'query', $race );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $this->site_id );
		} finally {
			remove_filter( 'query', $race );
			MainWP_DB_Backup::instance()->remove_backup_task( (int) $task->id );
			delete_option( 'mainwp_on_demand_retention_count' );
		}

		$this->assertSame( 'Another backup action is working on this site. Try again in a minute.', $admitted );
		$this->assertSame( 1, $result['snapshots_pruned'] );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $older )->status );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id ) );
	}

	/**
	 * A completed safety backup that does not carry the full archive itself
	 * (a manifest alone, an empty archive, an error envelope) is unusable:
	 * the restore fails before any older safety backup is pruned.
	 *
	 * @dataProvider data_safety_backup_answers_without_archive
	 *
	 * @param array $payload Child answer to backup_task_run.
	 */
	public function test_safety_backup_without_full_archive_fails_the_restore( $payload ) {
		$this->test_unusable_safety_backup_completion_fails_the_restore( $payload );
	}

	/**
	 * Completed answers with no usable full archive.
	 *
	 * @return array
	 */
	public static function data_safety_backup_answers_without_archive() {
		$base = array(
			'task_id' => 'task-new-pre',
			'phase'   => 'completed',
		);
		return array(
			'manifest only'      => array(
				$base + array(
					'artifacts' => array(
						array(
							'file' => 'files.list',
							'role' => 'manifest',
							'size' => 20,
						),
					),
				),
			),
			'empty full archive' => array(
				$base + array(
					'artifacts' => array(
						array(
							'file' => 'backup-new.tar.gz',
							'role' => 'full',
							'size' => 0,
						),
					),
				),
			),
			'no role'            => array(
				$base + array(
					'artifacts' => array(
						array(
							'file' => 'backup-new.tar.gz',
							'size' => 1234,
						),
					),
				),
			),
			'error envelope'     => array(
				$base + array(
					'error'     => 'Disk full.',
					'artifacts' => array(
						array(
							'file' => 'backup-new.tar.gz',
							'role' => 'full',
							'size' => 1234,
						),
					),
				),
			),
			'legacy empty file'  => array(
				$base + array(
					'file' => 'backup-new.tar.gz',
					'size' => 0,
				),
			),
		);
	}

	/**
	 * Clean Up and restore admission take the same site slot, so a restore
	 * cannot be admitted for a snapshot Clean Up is deleting.
	 */
	public function test_cleanup_and_restore_admission_exclude_each_other() {
		global $wpdb;
		$dir = MainWP_Backup_Private_Root::site_dir( $this->site_id );
		wp_mkdir_p( $dir );
		$path = $dir . 'db-orphan-' . uniqid() . '.sql.zip';
		file_put_contents( $path, 'orphan-db-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'task_id'  => 991,
				'site_id'  => $this->site_id,
				'type'     => 'db',
				'created'  => time(),
				'archives' => wp_json_encode( array( array( 'file' => basename( $path ), 'size' => 15, 'role' => 'db', 'path' => $path ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				'status'   => 'complete',
			)
		);
		$snapshot_id                = (int) $wpdb->insert_id;
		$this->chain_snapshot_ids[] = $snapshot_id;

		$admitted = null;
		$race     = function ( $sql ) use ( &$admitted, $snapshot_id ) {
			if ( null === $admitted && false !== strpos( $sql, 'WHERE snapshot_id IN' ) ) {
				$admitted = 'checking';
				try {
					$admitted = $this->orchestrator->initiate_restore( $this->site_id, $snapshot_id, array( 'confirm_destructive' => true ) );
				} catch ( MainWP_Exception $e ) {
					$admitted = $e->getMessage();
				}
			}
			return $sql;
		};
		add_filter( 'query', $race );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $this->site_id );
		} finally {
			remove_filter( 'query', $race );
		}

		$this->assertSame( 'Another backup action is working on this site. Try again in a minute.', $admitted );
		$this->assertSame( 1, $result['orphaned_snapshots_pruned'] );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id ) );
		$reservation = MainWP_Backup_Restore_Job_Store::instance()->reserve_site( $this->site_id );
		$this->assertGreaterThan( 0, $reservation, 'Clean Up gave the site back.' );
		MainWP_Backup_Restore_Job_Store::instance()->release_reservation( $reservation );
	}

	/**
	 * After a reload the restore history still offers Roll Back for a
	 * completed restore whose safety backup is on the site, and leaves out
	 * completed restores that no longer have one.
	 */
	public function test_restore_list_offers_rollback_for_completed_restore_with_safety_backup() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store     = MainWP_Backup_Restore_Job_Store::instance();
		$pruned    = $this->make_job( $this->safety_checkpoint() + array( 'pre_snapshot_pruned' => true ), $store::STATUS_COMPLETED );
		$no_backup = $this->make_job( array(), $store::STATUS_COMPLETED );
		$failed    = $this->make_job( $this->safety_checkpoint(), $store::STATUS_FAILED_PERMANENT );
		$completed = $this->make_job( $this->safety_checkpoint(), $store::STATUS_COMPLETED );

		$rows = $this->b194_reload_snapshots()['failed_restore_jobs'];
		$this->assertSame( array( (int) $completed->id, (int) $failed->id ), array_column( $rows, 'job_id' ) );
		$this->assertSame( $store::STATUS_COMPLETED, $rows[0]['status'] );
		$this->assertTrue( $rows[0]['has_rollback'] );
		$this->assertFalse( $rows[0]['is_rollback'] );
		$this->assertNotSame( '', $rows[0]['date_label'] );
		$this->assertNotContains( (int) $pruned->id, array_column( $rows, 'job_id' ) );
		$this->assertNotContains( (int) $no_backup->id, array_column( $rows, 'job_id' ) );
	}

	/**
	 * The restore history marks rollback jobs so the page can label them as
	 * rollbacks, and carries the stable error code for the mapped message.
	 */
	public function test_failed_restore_list_marks_rollback_jobs_and_error_codes() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store    = MainWP_Backup_Restore_Job_Store::instance();
		$failed   = $this->make_job( $this->safety_checkpoint() + array( 'last_error_code' => 'mainwp_restore_rolled_back' ), $store::STATUS_FAILED_PERMANENT );
		$rollback = $this->make_job( $this->safety_checkpoint() + array( 'is_rollback' => true, 'rollback_of' => (int) $failed->id ), $store::STATUS_FAILED_PERMANENT ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test fixture.

		$rows = array_column( $this->b194_reload_snapshots()['failed_restore_jobs'], null, 'job_id' );
		$this->assertFalse( $rows[ $failed->id ]['is_rollback'] );
		$this->assertTrue( $rows[ $rollback->id ]['is_rollback'] );
		$this->assertSame( 'mainwp_restore_rolled_back', $rows[ $failed->id ]['error_code'] );
		$this->assertSame( '', $rows[ $rollback->id ]['error_code'] );
	}

	/**
	 * The halted-restore reason goes through translation.
	 */
	public function test_reclaim_ceiling_message_is_translatable() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $this->make_job();
		$this->expire_job_at_reclaim_ceiling( (int) $job->id );
		$seen      = array();
		$translate = static function ( $translation, $text, $domain ) use ( &$seen ) {
			if ( 'mainwp' === $domain ) {
				$seen[] = $text;
			}
			return $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );
		try {
			$this->orchestrator->process_pending_restore_jobs( 30 );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}

		$this->assertSame( $store::STATUS_FAILED_PERMANENT, $store->get_job( (int) $job->id )->status );
		$this->assertContains( 'The restore was interrupted %d times without finishing a step, so it was stopped. Run the restore again. If it stops again, check the PHP error log on the Dashboard server.', $seen );
	}

	/**
	 * Post one AJAX request with its nonce and return the decoded response.
	 *
	 * @param string $action Handler method and nonce action.
	 * @param array  $fields POST fields.
	 *
	 * @return array|null Decoded response.
	 */
	private function post_ajax( $action, array $fields ) {
		$nonce                = wp_create_nonce( $action );
		$_POST                = array_merge( $_POST, $fields, array( 'security' => $nonce ) );
		$_REQUEST['security'] = $nonce;
		try {
			return $this->invoke_ajax_handler( $action );
		} finally {
			foreach ( array_keys( $fields ) as $field ) {
				unset( $_POST[ $field ] );
			}
			unset( $_POST['security'], $_REQUEST['security'] );
		}
	}

	/**
	 * A site whose last sync failed is flagged on the list and refuses Back
	 * Up Now, restore, and rollback on the server, not only in the buttons.
	 */
	public function test_disconnected_site_refuses_backup_restore_and_rollback() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_DB::instance()->update_website_sync_values( $this->site_id, array( 'sync_errors' => 'HTTP error 500' ) );
		$refusal = 'This site is disconnected from the Dashboard. Reconnect it, then try again.';

		$list = $this->post_ajax( 'mainwp_restore_snapshot_list', array( 'site_id' => (string) $this->site_id ) );
		$this->assertTrue( $list['site_disconnected'] );
		$this->assertStringContainsString( 'page=managesites&dashboard=' . $this->site_id, $list['reconnect_url'] );

		$backup = $this->post_ajax( 'mainwp_native_backup_initiate', array( 'site_id' => (string) $this->site_id ) );
		$this->assertSame( $refusal, $backup['error'] );
		$this->assertEmpty( MainWP_DB_Backup::instance()->get_manual_backup_task( $this->site_id ), 'A refused Back Up Now must not prepare a task.' );

		// A poll for a click whose run never opened would open it, so it
		// takes the same refusal as the initiate.
		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		$poll = $this->post_ajax(
			'mainwp_native_backup_progress',
			array(
				'site_id' => (string) $this->site_id,
				'since'   => (string) time(),
			)
		);
		$this->assertSame( $refusal, $poll['error'] );
		$this->assertSame( (int) $task->last_run, (int) MainWP_DB_Backup::instance()->get_manual_backup_task( $this->site_id )->last_run, 'The refused poll must not open a run.' );

		$restore = $this->post_ajax(
			'mainwp_restore_initiate',
			array(
				'site_id'             => (string) $this->site_id,
				'snapshot_id'         => '5',
				'confirm_destructive' => '1',
			)
		);
		$this->assertSame( $refusal, $restore['error'] );

		$failed   = $this->make_job( $this->safety_checkpoint(), MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT );
		$rollback = $this->post_ajax( 'mainwp_restore_rollback', array( 'job_id' => (string) $failed->id ) );
		$this->assertSame( $refusal, $rollback['error'] );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->site_id ) );

		MainWP_DB::instance()->update_website_sync_values( $this->site_id, array( 'sync_errors' => '' ) );
		$list = $this->post_ajax( 'mainwp_restore_snapshot_list', array( 'site_id' => (string) $this->site_id ) );
		$this->assertFalse( $list['site_disconnected'] );
	}

	/**
	 * Restore refusals reach the page as plain text, so a ">" in the
	 * registry message is not shown as an entity by the page's .text() sink.
	 */
	public function test_restore_initiate_refusal_is_plain_text() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'mainwp_enableNativeBackups' );

		$response = $this->post_ajax( 'mainwp_restore_initiate', array( 'site_id' => (string) $this->site_id ) );

		$this->assertStringContainsString( 'Settings > Backup Settings', $response['error'] );
		$this->assertStringNotContainsString( '&gt;', $response['error'] );
	}

	/** The list must distinguish an uncovered site from an empty covering task. */
	public function test_snapshots_list_reports_default_and_empty_coverage() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider'   => 'local',
				'name'       => '<img src=x onerror=alert(1)> & archive',
				'is_default' => 1,
			)
		);
		$this->assertGreaterThan( 0, $id );
		$db = MainWP_DB_Backup::instance();
		foreach ( array( 'default', 'tasks' ) as $source ) {
			if ( 'tasks' === $source ) {
				$task = $db->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
				$db->update_backup_task_with_values(
					(int) $task->id,
					array(
						'template'     => 0,
						'schedule'     => 'daily',
						'destinations' => '[]',
					)
				);
			}
			$nonce                = wp_create_nonce( 'mainwp_restore_snapshot_list' );
			$_POST['security']    = $nonce;
			$_REQUEST['security'] = $nonce;
			$_POST['site_id']     = (string) $this->site_id;
			try {
				$response = $this->invoke_ajax_handler( 'mainwp_restore_snapshot_list' );
			} finally {
				unset( $_POST['security'], $_REQUEST['security'], $_POST['site_id'] );
			}
			$this->assertSame( $source, $response['destinations_source'] );
			$this->assertSame( 'tasks' === $source, $response['local_only'] );
			$this->assertSame( 'tasks' === $source ? array() : array( '<img src=x onerror=alert(1)> & archive' ), $response['manual_destinations'] );
		}
	}
}
