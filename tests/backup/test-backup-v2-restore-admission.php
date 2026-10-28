<?php
/**
 * Restore dispatch admission over persisted plans and fresh Child reports.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_DB;

/** Exercise actual restore dispatch boundaries without a live Child. */
class MainWP_Backup_V2_Restore_Admission_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * Connected fixture site.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Local opaque payload.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Requests observed at the transport boundary.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Fresh capabilities for this dispatch.
	 *
	 * @var array
	 */
	private $report = array();

	/**
	 * Permanent remote refusal for classification probes.
	 *
	 * @var string
	 */
	private $refusal_code = '';

	/**
	 * Whether the transport throws the coded refusal.
	 *
	 * @var bool
	 */
	private $throw_refusal = false;

	/**
	 * Withdraw capability immediately after an authorized dispatch.
	 *
	 * @var bool
	 */
	private $lose_after_dispatch = false;

	/** Create private fixture storage and observe all Child requests. */
	public function set_up() {
		parent::set_up();
		$this->set_up_private_root();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->site_id = $this->create_test_site();
		$this->file    = tempnam( sys_get_temp_dir(), 'mainwp-admission-' );
		file_put_contents( $this->file, 'opaque-v2' );
		$this->calls  = array();
		$this->report = $this->capabilities();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $website, $what, $params ) {
				$this->calls[] = array( $what, $params );
				if ( $this->lose_after_dispatch && in_array( $what, array( 'restore_artifact_chunk', 'restore_task_run' ), true ) ) {
					$this->report['restore_layout_v2'] = 0;
				}
				if ( '' !== $this->refusal_code && in_array( $what, array( 'restore_artifact_chunk', 'restore_task_run', 'backup_task_run' ), true ) ) {
					if ( $this->throw_refusal ) {
						throw new \MainWP\Dashboard\MainWP_Exception( 'Fixture refusal.', null, $this->refusal_code );
					}
					return array(
						'error'      => 'Fixture refusal.',
						'error_code' => $this->refusal_code,
					);
				}
				if ( 'backup_preflight' === $what ) {
					return $this->report;
				}
				if ( 'restore_task_create' === $what ) {
					return array( 'task_id' => 'fixture-restore' );
				}
				if ( 'backup_task_create' === $what ) {
					return array( 'task_id' => 'fixture-backup' );
				}
				return array(
					'result'         => 'ok',
					'phase'          => 'pending',
					'bytes_received' => 9,
				);
			},
			10,
			4
		);
	}

	/** Reset the private switch and fixture bytes. */
	public function tear_down() {
		if ( property_exists( MainWP_Backup_Monitor::class, 'layout_v2_enabled' ) ) {
			$this->enable( false );
		}
		unlink( $this->file );
		$this->tear_down_private_root();
		parent::tear_down();
	}

	/**
	 * Test-only access to the internal rollout seam.
	 *
	 * @param bool $enabled Switch value.
	 */
	private function enable( $enabled ) {
		$property = new \ReflectionProperty( MainWP_Backup_Monitor::class, 'layout_v2_enabled' );
		$property->setValue( null, $enabled );
	}

	/**
	 * Approved source descriptor.
	 *
	 * @return array
	 */
	private function descriptor() {
		return array(
			'format'        => 2,
			'roots'         => array(
				array(
					'role'     => 'content',
					'coverage' => 'restore',
				),
			),
			'layout_id'     => str_repeat( 'a', 64 ),
			'core_policy'   => 'managed_preserve',
			'list_format'   => 2,
			'path_encoding' => 'role-prefix',
		);
	}

	/**
	 * A fresh v2-capable Child response.
	 *
	 * @return array
	 */
	private function capabilities() {
		return array(
			'backup_layout_v2'  => 1,
			'restore_layout_v2' => 1,
			'layout_id'         => str_repeat( 'a', 64 ),
			'layout_roles'      => array( 'content' ),
			'core_policy'       => 'managed_preserve',
		);
	}

	/**
	 * A persisted plan as supplied by the real catalog.
	 *
	 * @param string $source Artifact source.
	 * @return array Plan.
	 */
	private function plan( $source = 'local' ) {
		$descriptor = $this->descriptor();
		return array(
			array(
				'role'                     => 'full',
				'file'                     => 'backup.mwp2',
				'size'                     => 9,
				'sha256'                   => hash_file( 'sha256', $this->file ),
				'snapshot_id'              => 5,
				'source'                   => $source,
				'local_path'               => $this->file,
				'staged_path'              => $this->file,
				'remote_key'               => 'fixture/backup.mwp2',
				'adopt_only'               => 'adopt' === $source,
				'backup_format'            => 2,
				'layout_descriptor'        => $descriptor,
				'layout_descriptor_sha256' => hash( 'sha256', wp_json_encode( $descriptor ) ),
			),
		);
	}

	/**
	 * Create through the real persisted job store.
	 *
	 * @param array  $plan Plan.
	 * @param string $phase Stored phase.
	 * @param bool   $child Already has a Child task.
	 * @return object Job.
	 */
	private function job( $plan, $phase = 'staging', $child = false ) {
		$store      = MainWP_Backup_Restore_Job_Store::instance();
		$checkpoint = array(
			'restore_id' => 'admission-fixture',
			'sub_type'   => 'full',
			'plan'       => $plan,
			'options'    => array(
				'pre_snapshot'  => false,
				'file_rollback' => false,
			),
			'transfer'   => array(),
		);
		if ( isset( $plan[0]['backup_format'] ) ) {
			$checkpoint['target_layout_id'] = str_repeat( 'a', 64 );
		}
		$id = $store->create_job( $this->site_id, 5, $checkpoint );
		$this->assertGreaterThan( 0, $id );
		$this->assertTrue( $store->set_status( $id, $phase, array( 'child_task_id' => $child ? 'existing-task' : '' ) ) );
		return $store->get_job( $id );
	}

	/**
	 * Stored and resumed routes crossed with artifact sources.
	 *
	 * @return array
	 */
	public static function refused_routes() {
		$rows = array();
		foreach ( array( 'pending', 'preflight', 'pre_snapshot', 'staging', 'transferring', 'running' ) as $phase ) {
			foreach ( array( 'local', 'remote', 'remote_chunked', 'adopt' ) as $source ) {
				$rows[ $phase . '-' . $source ] = array( $phase, $source );
			}
		}
		return $rows;
	}

	/**
	 * The off switch stops persisted work before any Child or artifact work.
	 *
	 * @dataProvider refused_routes
	 * @param string $phase Stored phase.
	 * @param string $source Artifact source.
	 */
	public function test_off_switch_refuses_every_persisted_phase( $phase, $source ) {
		$job    = $this->job( $this->plan( $source ), $phase, in_array( $phase, array( 'transferring', 'running' ), true ) );
		$before = file_get_contents( $this->file );
		( new MainWP_Backup_Restore_Orchestrator() )->run_job( $job, microtime( true ) + 1 );
		$fresh = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		$this->assertSame( 'failed_permanent', $fresh->status );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $fresh->checkpoint['last_error_code'] );
		$this->assertSame( array(), $this->calls, 'Refusal must precede even a cancellation request.' );
		$this->assertSame( $before, file_get_contents( $this->file ) );
		$this->assertSame( $job->checkpoint['plan'], $fresh->checkpoint['plan'] );
	}

	/**
	 * The transfer boundary must also protect direct/resumed callers.
	 *
	 * @dataProvider transfer_sources
	 * @param string $source Source.
	 */
	public function test_direct_transfer_refuses_before_cursor_or_child_changes( $source ) {
		$cursor = array(
			'artifact_index' => 0,
			'offset'         => 0,
		);
		$before = $cursor;
		$plan   = $this->plan( $source );
		$result = ( new MainWP_Backup_Restore_Transfer_Driver() )->transfer_artifacts( MainWP_DB::instance()->get_website_by_id( $this->site_id ), 'existing-task', $plan, $cursor, microtime( true ) + 1 );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result['error_code'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( $before, $cursor );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Source variants.
	 *
	 * @return array
	 */
	public static function transfer_sources() {
		return array( array( 'local' ), array( 'remote' ), array( 'remote_chunked' ), array( 'adopt' ) );
	}

	/** A stale success cannot authorize the next resume. */
	public function test_resume_requires_a_new_preflight_and_preserves_cursor_on_refusal() {
		$this->enable( true );
		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$plan    = $this->plan();
		$this->assertIsArray( MainWP_Backup_Monitor::admit_restore_plan( $website, $plan, str_repeat( 'a', 64 ) ) );
		MainWP_DB::instance()->update_website_option( $website, 'restore_layout_v2_support', '1' );
		$this->report = array();
		$cursor       = array(
			'artifact_index' => 0,
			'offset'         => 0,
		);
		$result       = ( new MainWP_Backup_Restore_Transfer_Driver() )->transfer_artifacts( $website, 'existing-task', $plan, $cursor, microtime( true ) + 1 );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result['error_code'] );
		$this->assertSame( array( 'backup_preflight', 'backup_preflight' ), array_column( $this->calls, 0 ) );
		$this->assertSame(
			array(
				'artifact_index' => 0,
				'offset'         => 0,
			),
			$cursor
		);
	}

	/**
	 * Drive the actual create projection, including the replay boundary.
	 *
	 * @param object $job Job.
	 * @param array  $plan Plan.
	 * @return array Result.
	 */
	private function create( $job, $plan ) {
		$checkpoint = $job->checkpoint;
		$method     = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'create_child_restore_task' );
		$args       = array( $job, &$checkpoint, $plan );
		return $method->invokeArgs( new MainWP_Backup_Restore_Orchestrator(), $args );
	}

	/** Only a current matching report permits the exact v2 projection. */
	public function test_permitted_create_sends_exact_descriptor_and_artifact_format() {
		$this->enable( true );
		$plan   = $this->plan();
		$result = $this->create( $this->job( $plan ), $plan );
		$this->assertSame( 'fixture-restore', $result['task_id'] );
		$this->assertSame( array( 'backup_preflight', 'restore_task_create' ), array_column( $this->calls, 0 ) );
		$options = json_decode( $this->calls[1][1]['options_json'], true );
		$this->assertSame( 2, $options['backup_format'] );
		$this->assertSame( str_repeat( 'a', 64 ), $options['expected_layout_id'] );
		$this->assertSame( $plan[0]['layout_descriptor'], $options['layout_descriptor'] );
		$this->assertSame( 2, json_decode( $this->calls[1][1]['artifacts_json'], true )[0]['backup_format'] );
	}

	/** The create boundary refuses replay even if run_job was bypassed. */
	public function test_direct_create_refuses_without_a_fresh_matching_layout() {
		$this->enable( true );
		$this->report['layout_id'] = str_repeat( 'b', 64 );
		$plan                      = $this->plan();
		$job                       = $this->job( $plan );
		$result                    = $this->create( $job, $plan );
		$this->assertSame( '', $result['task_id'] );
		$this->assertSame( array( 'backup_preflight' ), array_column( $this->calls, 0 ) );
		$fresh = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		$this->assertSame( 'failed_permanent', $fresh->status );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_LAYOUT_CHANGED, $fresh->checkpoint['last_error_code'] );
		$this->assertArrayNotHasKey( 'child_create_attempted', $fresh->checkpoint );
	}

	/** A persisted job without a destination pin cannot borrow source identity. */
	public function test_resumed_job_without_destination_binding_is_refused() {
		$this->enable( true );
		$job        = $this->job( $this->plan() );
		$checkpoint = $job->checkpoint;
		unset( $checkpoint['target_layout_id'] );
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->save_checkpoint( (int) $job->id, $checkpoint ) );
		( new MainWP_Backup_Restore_Orchestrator() )->run_job( $store->get_job( (int) $job->id ), microtime( true ) + 1 );
		$fresh = $store->get_job( (int) $job->id );
		$this->assertSame( 'failed_permanent', $fresh->status );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_LAYOUT_CHANGED, $fresh->checkpoint['last_error_code'] );
		$this->assertSame( array( 'backup_preflight' ), array_column( $this->calls, 0 ) );
	}

	/** Rollback reconstruction must retain the safety source format. */
	public function test_rollback_cannot_relabel_v2_safety_artifacts_as_legacy() {
		$store                                = MainWP_Backup_Restore_Job_Store::instance();
		$job                                  = $this->job( array() );
		$checkpoint                           = $job->checkpoint;
		$full                                 = $this->plan( 'adopt' )[0];
		$full['layout_descriptor']            = array_reverse( $full['layout_descriptor'], true );
		$manifest                             = $full;
		$manifest['role']                     = 'manifest';
		$manifest['file']                     = 'files.list';
		$checkpoint['pre_snapshot_artifacts'] = array( $full, $manifest );
		$this->assertTrue( $store->save_checkpoint( (int) $job->id, $checkpoint ) );
		$this->assertTrue( $store->fail_permanent( (int) $job->id, 'Fixture stopped.' ) );
		$orchestrator = new MainWP_Backup_Restore_Orchestrator();
		$id           = $orchestrator->initiate_rollback( $store->get_job( (int) $job->id ) );
		$rollback     = $store->get_job( $id );
		$this->assertSame( 2, $rollback->checkpoint['plan'][0]['backup_format'] );
		$this->assertSame( $full['layout_descriptor'], $rollback->checkpoint['plan'][0]['layout_descriptor'] );
		$orchestrator->run_job( $rollback, microtime( true ) + 1 );
		$this->assertSame( 'failed_permanent', $store->get_job( $id )->status );
		$this->assertSame( array(), $this->calls );
	}

	/** A sandbox cannot use a live site's capability as its own decoder. */
	public function test_testrestore_refuses_before_staging_or_sandbox_application() {
		$catalog        = new class() extends \MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog {
			/**
			 * Source plan.
			 *
			 * @var array
			 */
			public $plan = array();

			/**
			 * Return the plan already resolved by storage-reader fixtures.
			 *
			 * @param int $snapshot_id Snapshot.
			 * @param int $expected_site_id Site.
			 * @return array Resolved source.
			 */
			public function resolve_restorable_chain( int $snapshot_id, int $expected_site_id = 0 ): array {
				return array(
					'ok'   => true,
					'plan' => $this->plan,
				);
			}
		};
		$catalog->plan  = $this->plan();
		$driver         = new class() extends \MainWP\Dashboard\MainWP_Backup_TestRestore_Driver {
			/**
			 * Catalog fixture.
			 *
			 * @var object
			 */
			public $source;

			/**
			 * Source catalog.
			 *
			 * @return \MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog
			 */
			protected function catalog(): \MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog {
				return $this->source;
			}
		};
		$driver->source = $catalog;
		$result         = $driver->verify_snapshot( 123456 );
		$this->assertSame( 'unsupported', $result['state'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result['error_code'] );
		$this->assertSame( array(), $this->calls );
	}

	/** Safety capture cannot silently create a legacy backup for v2. */
	public function test_v2_safety_backup_is_refused_until_its_producer_is_available() {
		$this->enable( true );
		$job                                   = $this->job( $this->plan(), 'preflight' );
		$checkpoint                            = $job->checkpoint;
		$checkpoint['options']['pre_snapshot'] = true;
		$store                                 = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->save_checkpoint( (int) $job->id, $checkpoint ) );
		( new MainWP_Backup_Restore_Orchestrator() )->run_job( $store->get_job( (int) $job->id ), microtime( true ) + 1 );
		$this->assertSame( 'failed_permanent', $store->get_job( (int) $job->id )->status );
		$this->assertSame( array( 'backup_preflight' ), array_column( $this->calls, 0 ) );
	}

	/**
	 * Each permanent code in both transport forms.
	 *
	 * @return array
	 */
	public static function permanent_codes() {
		return array(
			array( 'mainwp_backup_format_unsupported', false ),
			array( 'mainwp_backup_layout_changed', false ),
			array( 'mainwp_backup_format_unsupported', true ),
			array( 'mainwp_backup_layout_changed', true ),
		);
	}

	/**
	 * A coded refusal cannot shrink/retry a chunk or lose its code.
	 *
	 * @dataProvider permanent_codes
	 * @param string $code Refusal code.
	 * @param bool   $throws Throw instead of returning a response.
	 */
	public function test_remote_chunk_refusals_are_permanent( $code, $throws ) {
		$this->refusal_code  = $code;
		$this->throw_refusal = $throws;
		$plan                = $this->plan();
		unset( $plan[0]['backup_format'], $plan[0]['layout_descriptor'], $plan[0]['layout_descriptor_sha256'] );
		$plan[0]['file'] = 'backup.zip';
		$cursor          = array();
		$result          = ( new MainWP_Backup_Restore_Transfer_Driver() )->transfer_artifacts( MainWP_DB::instance()->get_website_by_id( $this->site_id ), 'existing-task', $plan, $cursor, microtime( true ) + 1 );
		$this->assertSame( $code, $result['error_code'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( array( 'restore_artifact_chunk' ), array_column( $this->calls, 0 ) );
	}

	/**
	 * Running-task responses retain permanent classification on resume.
	 *
	 * @dataProvider permanent_codes
	 * @param string $code Refusal code.
	 * @param bool   $throws Throw instead of returning a response.
	 */
	public function test_remote_running_refusals_are_permanent( $code, $throws ) {
		if ( $throws ) {
			// process_pending_restore_jobs owns thrown-error classification.
			$this->throw_refusal = true;
		}
		$this->refusal_code = $code;
		$plan               = $this->plan();
		unset( $plan[0]['backup_format'], $plan[0]['layout_descriptor'], $plan[0]['layout_descriptor_sha256'] );
		$job          = $this->job( $plan, 'running', true );
		$orchestrator = new MainWP_Backup_Restore_Orchestrator();
		if ( $throws ) {
			$orchestrator->process_pending_restore_jobs( 1, (int) $job->id );
		} else {
			$orchestrator->run_job( $job, microtime( true ) + 1 );
		}
		$fresh = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		$this->assertSame( 'failed_permanent', $fresh->status );
		$this->assertSame( $code, $fresh->checkpoint['last_error_code'] );
		$this->assertSame( array( 'restore_task_run' ), array_column( $this->calls, 0 ) );
	}

	/**
	 * A shapeless safety-poll envelope can still carry a permanent refusal.
	 *
	 * @dataProvider permanent_codes
	 * @param string $code Refusal.
	 * @param bool   $throws Throw instead of returning the envelope.
	 */
	public function test_remote_safety_poll_refusals_are_permanent( $code, $throws ) {
		$this->refusal_code  = $code;
		$this->throw_refusal = $throws;
		$plan                = $this->plan();
		unset( $plan[0]['backup_format'], $plan[0]['layout_descriptor'], $plan[0]['layout_descriptor_sha256'] );
		$job   = $this->job( $plan, 'pre_snapshot' );
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->set_status( (int) $job->id, 'pre_snapshot', array( 'pre_snapshot_task_id' => 'fixture-backup' ) ) );
		$orchestrator = new MainWP_Backup_Restore_Orchestrator();
		if ( $throws ) {
			$orchestrator->process_pending_restore_jobs( 1, (int) $job->id );
		} else {
			$orchestrator->run_job( $store->get_job( (int) $job->id ), microtime( true ) + 1 );
		}
		$fresh = $store->get_job( (int) $job->id );
		$this->assertSame( 'failed_permanent', $fresh->status );
		$this->assertSame( $code, $fresh->checkpoint['last_error_code'] );
		$this->assertSame( array( 'backup_task_run' ), array_column( $this->calls, 0 ) );
	}

	/** Each run within a slice needs its own current report. */
	public function test_capability_loss_stops_the_second_run_in_a_slice() {
		$this->enable( true );
		$this->lose_after_dispatch = true;
		$job                       = $this->job( $this->plan(), 'running', true );
		( new MainWP_Backup_Restore_Orchestrator() )->run_job( $job, microtime( true ) + 1 );
		$fresh = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		$this->assertSame( 'failed_permanent', $fresh->status );
		$this->assertSame( 1, count( array_filter( $this->calls, static fn( $call ) => 'restore_task_run' === $call[0] ) ) );
		$this->assertSame( 3, count( array_filter( $this->calls, static fn( $call ) => 'backup_preflight' === $call[0] ) ) );
	}

	/** A successful first chunk cannot authorize the second chunk. */
	public function test_capability_loss_stops_the_second_chunk_in_a_slice() {
		$this->enable( true );
		$this->lose_after_dispatch = true;
		file_put_contents( $this->file, str_repeat( 'x', MB_IN_BYTES ) );
		$plan            = $this->plan();
		$plan[0]['size'] = MB_IN_BYTES;
		$job             = $this->job( $plan, 'transferring', true );
		$cursor          = array( 'chunk_bytes' => MainWP_Backup_Restore_Transfer_Driver::CHUNK_MIN_BYTES );
		$result          = ( new MainWP_Backup_Restore_Transfer_Driver() )->transfer_artifacts( MainWP_DB::instance()->get_website_by_id( $this->site_id ), 'existing-task', $plan, $cursor, microtime( true ) + 1, null, (int) $job->id );
		$this->assertArrayHasKey( 'error_code', $result, wp_json_encode( $result ) );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result['error_code'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( 9, $cursor['offset'] );
		$this->assertSame( 1, count( array_filter( $this->calls, static fn( $call ) => 'restore_artifact_chunk' === $call[0] ) ) );
		$this->assertSame( 3, count( array_filter( $this->calls, static fn( $call ) => 'backup_preflight' === $call[0] ) ) );
	}

	/**
	 * A reconciliation refusal cannot be erased by another successful report.
	 *
	 * @dataProvider permanent_codes
	 * @param string $code Refusal.
	 * @param bool   $throws Throw instead of returning the envelope.
	 */
	public function test_reconciliation_refusal_stops_create( $code, $throws ) {
		$this->enable( true );
		$plan             = $this->plan();
		$manifest         = $plan[0];
		$manifest['role'] = 'manifest';
		$manifest['file'] = 'files.list';
		$manifest['walk'] = array( 'unarchived' => array() );
		$plan[]           = $manifest;
		$job              = $this->job( $plan );
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $website, $what ) use ( $code, $throws ) {
				if ( 'backup_preflight' === $what && 3 === count( $this->calls ) ) {
					if ( $throws ) {
						throw new \MainWP\Dashboard\MainWP_Exception( 'Fixture refusal.', null, $code );
					}
					return array(
						'error'      => 'Fixture refusal.',
						'error_code' => $code,
					);
				}
				return $pre;
			},
			20,
			3
		);
		( new MainWP_Backup_Restore_Orchestrator() )->process_pending_restore_jobs( 1, (int) $job->id );
		$fresh = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
		$this->assertSame( 'failed_permanent', $fresh->status );
		$this->assertSame( $code, $fresh->checkpoint['last_error_code'] );
		$this->assertSame( array( 'backup_preflight', 'backup_preflight', 'backup_preflight' ), array_column( $this->calls, 0 ) );
		$this->assertArrayNotHasKey( 'child_create_attempted', $fresh->checkpoint );
	}

	/** Terminal refusal releases local resources without cancelling on the Child. */
	public function test_refusal_cleans_staging_and_releases_owned_safety_handle() {
		$job   = $this->job( $this->plan(), 'staging' );
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->set_status( (int) $job->id, 'staging', array( 'pre_snapshot_task_id' => 'fixture-backup' ) ) );
		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		MainWP_DB::instance()->update_website_option(
			$website,
			\MainWP\Dashboard\MainWP_Backup_Executor::ADHOC_TASK_OPTION,
			wp_json_encode(
				array(
					'task_id' => 'fixture-backup',
					'owner'   => (string) $job->id,
				)
			)
		);
		$dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::staging_path( 'restore-staging', (string) $job->id );
		$this->assertIsString( $dir );
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/fixture.mwp2', 'staged' );
		$this->assertFileExists( $dir . '/fixture.mwp2' );
		$events = array();
		add_action(
			'mainwp_backup_restore_failed',
			static function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);
		( new MainWP_Backup_Restore_Orchestrator() )->run_job( $store->get_job( (int) $job->id ), microtime( true ) + 1 );
		$this->assertFileDoesNotExist( $dir . '/fixture.mwp2' );
		$this->assertSame( '', MainWP_DB::instance()->get_website_option( $website, \MainWP\Dashboard\MainWP_Backup_Executor::ADHOC_TASK_OPTION ) ?? '' );
		$this->assertCount( 1, $events );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $events[0]['error_code'] );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Completed-child verification and its retry resume.
	 *
	 * @return array
	 */
	public static function verification_phases() {
		return array( array( 'verifying' ), array( 'failed_retryable' ) );
	}

	/**
	 * Completed Child work must retain the existing reachability retry outcome.
	 *
	 * @dataProvider verification_phases
	 * @param string $phase Effective verification phase.
	 */
	public function test_completed_child_verification_needs_no_new_format_admission( $phase ) {
		$this->enable( true );
		$this->report                = array();
		$job                         = $this->job( $this->plan(), $phase, true );
		$store                       = MainWP_Backup_Restore_Job_Store::instance();
		$checkpoint                  = $job->checkpoint;
		$checkpoint['resume_status'] = 'verifying';
		$this->assertTrue( $store->save_checkpoint( (int) $job->id, $checkpoint ) );
		$orchestrator = new class() extends MainWP_Backup_Restore_Orchestrator {
			/**
			 * Keep the existing observational verification retry deterministic.
			 *
			 * @param object $website Destination.
			 * @return bool Reachability.
			 */
			protected function site_responds( object $website ): bool {
				return false;
			}
		};
		$orchestrator->run_job( $store->get_job( (int) $job->id ), microtime( true ) + 1 );
		$fresh = $store->get_job( (int) $job->id );
		$this->assertSame( 'failed_retryable', $fresh->status );
		$this->assertSame( 'verifying', $fresh->checkpoint['resume_status'] );
		$this->assertSame( array(), $this->calls );
	}

	/** A queued user cancellation keeps its existing local outcome. */
	public function test_user_cancel_intent_precedes_new_restore_admission() {
		$this->enable( true );
		$this->report                = array();
		$job                         = $this->job( $this->plan(), 'staging' );
		$store                       = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->arm_cancel_intent( (int) $job->id ) );
		( new MainWP_Backup_Restore_Orchestrator() )->run_job( $store->get_job( (int) $job->id ), microtime( true ) + 1 );
		$this->assertSame( 'cancelled', $store->get_job( (int) $job->id )->status );
		$this->assertSame( array(), $this->calls );
	}

	/** V1 options and artifact JSON stay identical to the start-SHA bytes. */
	public function test_v1_projection_golden_bytes_with_old_and_new_children() {
		$plan = $this->plan();
		unset( $plan[0]['backup_format'], $plan[0]['layout_descriptor'], $plan[0]['layout_descriptor_sha256'] );
		$plan[0]['file'] = 'backup.zip';
		foreach ( array( array(), $this->capabilities() ) as $report ) {
			$this->calls  = array();
			$this->report = $report;
			$job          = $this->job( $plan );
			$this->assertSame( 'fixture-restore', $this->create( $job, $plan )['task_id'] );
			$this->assertSame( array( 'restore_task_create' ), array_column( $this->calls, 0 ) );
			$this->assertSame( '{"sub_type":"full","has_foreign_keys":null,"file_rollback":false,"preserve_orders":false,"woo_order_since":0}', $this->calls[0][1]['options_json'] );
			$this->assertSame(
				wp_json_encode(
					array(
						array(
							'role'       => 'full',
							'file'       => 'backup.zip',
							'size'       => 9,
							'sha256'     => hash_file( 'sha256', $this->file ),
							'adopt_only' => false,
						),
					)
				),
				$this->calls[0][1]['artifacts_json']
			);
			$this->assertTrue( MainWP_Backup_Restore_Job_Store::instance()->fail_permanent( (int) $job->id, 'Fixture finished.' ) );
		}
	}

	/** Both Child generations receive the same legacy backup request bytes. */
	public function test_v1_backup_create_golden_bytes_with_old_and_new_children() {
		$website = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$golden  = null;
		foreach ( array( array(), $this->capabilities() ) as $capabilities ) {
			$this->calls  = array();
			$this->report = array_merge(
				array(
					'task_spine' => 1,
					'disk_free'  => 10 * GB_IN_BYTES,
				),
				$capabilities
			);
			MainWP_DB::instance()->update_website_option( $website, \MainWP\Dashboard\MainWP_Backup_Executor::ADHOC_TASK_OPTION, '' );
			$this->assertSame( 'fixture-backup', \MainWP\Dashboard\MainWP_Backup_Executor::trigger_backup( $this->site_id ) );
			$creates = array_values( array_filter( $this->calls, static fn( $call ) => 'backup_task_create' === $call[0] ) );
			$this->assertCount( 1, $creates );
			$params = $creates[0][1];
			$this->assertArrayNotHasKey( 'backup_format', $params );
			$this->assertArrayNotHasKey( 'expected_layout_id', $params );
			$this->assertArrayNotHasKey( 'parent_snapshot_id', $params );
			$this->assertArrayHasKey( 'pid', $params );
			unset( $params['pid'] );
			$expected = array(
				'type'                  => 'full',
				'exclude'               => '',
				'excludebackup'         => 0,
				'excludecache'          => 0,
				'excludenonwp'          => 0,
				'excludezip'            => 0,
				'ext'                   => \MainWP\Dashboard\MainWP_Backup_Settings::get_current_archive_extension( $website ),
				'file_descriptors_auto' => get_option( 'mainwp_maximumFileDescriptorsAuto' ),
				'file_descriptors'      => false === get_option( 'mainwp_maximumFileDescriptors' ) ? 150 : get_option( 'mainwp_maximumFileDescriptors' ),
				'loadFilesBeforeZip'    => false,
				'f'                     => '',
			);
			$this->assertSame( wp_json_encode( $expected ), wp_json_encode( $params ) );
			if ( null !== $golden ) {
				$this->assertSame( $golden, wp_json_encode( $params ) );
			}
			$golden = wp_json_encode( $params );
		}
	}
}
