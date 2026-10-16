<?php
/**
 * Restore actions must preserve consent, scope and cancellation evidence.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_Backup_CLI_Restore_Runner;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Read_Model;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

require_once __DIR__ . '/../backup/class-mainwp-backup-read-fixtures.php';
require_once __DIR__ . '/../backup/test-backup-safe-error.php';

/** Restore previews and results must agree with persisted evidence. */
class MainWP_Backups_Restore_Abilities_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Purity_Guard;

	/**
	 * Site owned by this case.
	 *
	 * @var int
	 */
	private $site_id;
	/**
	 * Snapshot owned by this case.
	 *
	 * @var int
	 */
	private $snapshot_id;
	/**
	 * Source job owned by this case.
	 *
	 * @var int
	 */
	private $job_id;
	/**
	 * Local artifact needed for real catalog admission.
	 *
	 * @var string
	 */
	private $artifact;
	/**
	 * Child calls prove preview purity.
	 *
	 * @var array
	 */
	private $calls = array();
	/**
	 * Cancellation answer controls stop evidence.
	 *
	 * @var array
	 */
	private $cancel_reply = array( 'result' => 'ok' );

	/** Allocated identities keep the shared database safe across test invocations. */
	public function set_up() {
		parent::set_up();
		$this->set_current_user_as_admin();
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->site_id  = $this->create_test_site();
		$this->artifact = tempnam( sys_get_temp_dir(), 'mainwp-restore-ability-' );
		file_put_contents( $this->artifact, 'snapshot fixture' );
		$this->snapshot_id = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'  => 0,
				'site_id'  => $this->site_id,
				'type'     => 'db',
				'created'  => time() - 3 * DAY_IN_SECONDS,
				'archives' => array(
					array(
						'file' => 'db.sql.gz',
						'role' => 'db',
						'path' => $this->artifact,
						'size' => 16,
					),
				),
			)
		);
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'created' => time() - 3 * DAY_IN_SECONDS ), array( 'id' => $this->snapshot_id ) );
		$this->job_id = MainWP_Backup_Restore_Job_Store::instance()->create_job(
			$this->site_id,
			$this->snapshot_id,
			array(
				'sub_type'               => 'db',
				'pre_snapshot_artifacts' => array(
					array(
						'file' => 'safety.tar.gz',
						'role' => 'full',
						'size' => 16,
					),
				),
			)
		);
		MainWP_Backup_Restore_Job_Store::instance()->set_status( $this->job_id, 'failed_permanent' );
		$this->calls        = array();
		$this->cancel_reply = array( 'result' => 'ok' );
		wp_clear_scheduled_hook( 'mainwp_restore_continue_action' );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ), 10, 3 );
		add_filter( 'pre_http_request', array( $this, 'http_reply' ) );
	}

	/** Cleanup owns only rows and artifacts allocated by this case. */
	public function tear_down() {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'http_reply' ) );
		wp_clear_scheduled_hook( 'mainwp_restore_continue_action' );
		global $wpdb;
		foreach ( array( 'wp_backup_snapshot', 'wp_backup_restore_job', 'wp_backup_run_history' ) as $table ) {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( $table ), array( 'site_id' => $this->site_id ) );
		}
		unlink( $this->artifact );
		$_POST    = array();
		$_REQUEST = array();
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		parent::tear_down();
	}

	/**
	 * Refusal and cancellation fixtures must never reach a real Child.
	 *
	 * @param mixed  $prior Prior response.
	 * @param object $site Target site.
	 * @param string $action Child operation.
	 * @return array
	 */
	public function child_reply( $prior, $site, $action ) {
		$this->calls[] = $action;
		return 'restore_preflight' === $action ? array( 'woo_hpos_enabled' => false ) : $this->cancel_reply;
	}

	/**
	 * A reachable site permits the verification dispatch test.
	 *
	 * @return array
	 */
	public function http_reply() {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '',
			'headers'  => array(),
		);
	}

	/**
	 * Every restore action shares the consent envelope.
	 *
	 * @return array
	 */
	public static function actions() {
		return array( array( 'restore-site-snapshot', 'POST', false ), array( 'cancel-restore', 'DELETE', true ), array( 'rollback-restore', 'POST', false ) );
	}

	/**
	 * A missing registration must fail before callback dispatch.
	 *
	 * @param string $name Slug.
	 * @return \WP_Ability
	 */
	private function ability( $name ) {
		$this->assertTrue( wp_has_ability( 'mainwp/' . $name . '-v1' ), $name );
		return wp_get_ability( 'mainwp/' . $name . '-v1' );
	}

	/**
	 * Fixtures use only allocated identities.
	 *
	 * @param string $name Slug.
	 * @return array
	 */
	private function input( $name ) {
		return 'restore-site-snapshot' === $name ? array(
			'site_id_or_domain' => $this->site_id,
			'snapshot_id'       => $this->snapshot_id,
		) : array( 'restore_job_id' => $this->job_id );
	}

	/**
	 * Every preview, including an error, must stay pure.
	 *
	 * @param string $name Slug.
	 * @param array  $input Caller input.
	 * @param bool   $direct Bypass core's permission wrapper.
	 * @return mixed
	 */
	private function execute( $name, array $input, $direct = false ) {
		$ability = $this->ability( $name );
		$call    = static function () use ( $ability, $name, $input, $direct ) {
			$method = 'execute_' . str_replace( '-', '_', $name );
			return $direct ? MainWP_Abilities_Backups::$method( $input ) : $ability->execute( $input );
		};
		return ! empty( $input['dry_run'] ) ? $this->guarded( $call ) : $call();
	}

	/**
	 * Closed output schemas must match each returned result.
	 *
	 * @param string $name Slug.
	 * @param mixed  $output Result.
	 */
	private function assert_output( $name, $output ) {
		$this->assertIsArray( $output, is_wp_error( $output ) ? $output->get_error_message() : '' );
		$this->assertTrue( rest_validate_value_from_schema( $output, $this->ability( $name )->get_output_schema() ) );
	}

	/**
	 * Discovery must advertise the same authority and transport as execution.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 * @param string $method Method.
	 * @param bool   $idempotent Annotation.
	 */
	public function test_ability_is_registered( $name, $method, $idempotent ) {
		$ability = $this->ability( $name );
		$this->assertSame( 'mainwp-backups', $ability->get_category() );
		$this->assertNotEmpty( $ability->get_label() );
		$this->assertStringContainsString( 'Possible errors:', $ability->get_description() );
		$this->assertSame( array( 'execute_backups', 'restore_backups' ), MainWP_Abilities_Util::required_rights_for_ability( $ability->get_name() ) );
		$meta = $ability->get_meta();
		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertTrue( $meta['annotations']['destructive'] );
		$this->assertSame( $idempotent, $meta['annotations']['idempotent'] );
	}

	/**
	 * Successful starts expose a pinned poll handle and persisted admission time.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_action_returns_expected_structure( $name ) {
		$output = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
		$this->assert_output( $name, $output );
		$this->assertFalse( $output['dry_run'] );
		if ( 'cancel-restore' !== $name ) {
			$this->assertSame( 'started', $output['status'] );
			$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $output['restore_job_id'] );
			$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $job->created ), $output['started_at'] );
			$this->assertSame(
				array(
					'ability'          => 'mainwp/get-restore-job-v1',
					'input'            => array( 'restore_job_id' => (int) $job->id ),
					'interval_seconds' => 30,
				),
				$output['poll']
			);
			$this->assertNotFalse( wp_next_scheduled( 'mainwp_restore_continue_action' ) );
			$checkpoint = $job->checkpoint;
			$this->assertSame( 'rollback-restore' === $name, ! empty( $checkpoint['is_rollback'] ) );
			$this->assertSame( 'restore-site-snapshot' === $name, $checkpoint['options']['pre_snapshot'] );
		}
	}

	/**
	 * Permission denials must precede reads and work.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_action_requires_authentication( $name ) {
		wp_set_current_user( 0 );
		$error = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ), true );
		$this->assertSame( 'mainwp_permission_denied', $error->get_error_code() );
		$this->assertSame( 401, $error->get_error_data()['status'] );
	}

	/**
	 * Site ownership cannot substitute for Dashboard authority.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_action_requires_manage_options( $name ) {
		$this->set_current_user_as_subscriber();
		$error = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ), true );
		$this->assertSame( 'mainwp_permission_denied', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	/**
	 * Undocumented fields cannot reach an action.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_action_validates_input( $name ) {
		$this->assertSame( 'ability_invalid_input', $this->execute( $name, array( 'unexpected' => true ) )->get_error_code() );
	}

	/**
	 * Previews must remain pure even with database-bearing snapshots.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_action_dry_run_returns_preview( $name ) {
		$output = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ) );
		$this->assert_output( $name, $output );
		$this->assertTrue( $output['dry_run'] );
		$this->assertSame( array(), $this->calls );
		$this->assertFalse( wp_next_scheduled( 'mainwp_restore_continue_action' ) );
		if ( 'restore-site-snapshot' === $name ) {
			$effect = $output['would_affect'];
			$this->assertTrue( $effect['restorable'] );
			$this->assertSame( 'db', $effect['restore_type'] );
			$this->assertTrue( $effect['replaces_database'] );
			$this->assertSame( array( $this->snapshot_id ), $effect['chain_ids'] );
			$this->assertSame(
				array(
					'will_take' => true,
					'type'      => 'full',
				),
				$effect['safety_backup']
			);
			$this->assertSame(
				array(
					'requested'  => false,
					'checked_at' => 'confirm',
				),
				$effect['preserve_orders']
			);
			$this->assertStringContainsString( '3 days', implode( ' ', $output['warnings'] ) );
		} elseif ( 'rollback-restore' === $name ) {
			$this->assertTrue( $output['would_affect']['rollback_available'] );
		}
	}

	/**
	 * Conflicting modes must not reach a write path.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_action_rejects_dry_run_and_confirm_together( $name ) {
		$error = $this->execute(
			$name,
			$this->input( $name ) + array(
				'dry_run' => true,
				'confirm' => true,
			)
		);
		$this->assertSame( 'mainwp_invalid_input', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/**
	 * Missing confirmation must refuse before restore admission.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_action_requires_confirmation( $name ) {
		$error = $this->execute( $name, $this->input( $name ) );
		$this->assertSame( 'mainwp_confirmation_required', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/**
	 * Stop-work remains available after the native setting changes.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_native_disabled_behavior( $name ) {
		update_option( 'mainwp_enableNativeBackups', 0 );
		foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
			$output = $this->execute( $name, $this->input( $name ) + array( $mode => true ) );
			if ( 'cancel-restore' === $name ) {
				$this->assert_output( $name, $output );
			} else {
				$this->assertSame( 'mainwp_backup_legacy_disabled', $output->get_error_code() );
				$this->assertSame( 409, $output->get_error_data()['status'] );
			}
		}
	}

	/**
	 * Restore, rollback and cancel explain a pending schema repair instead of
	 * starting work nothing can record.
	 */
	public function test_restore_abilities_refuse_while_schema_repair_pending() {
		$marker  = MainWP_Install::BACKUP_RESTORE_JOB_CHECKPOINT_REV_REPAIR_PENDING;
		$pending = get_site_option( $marker );
		update_site_option( $marker, time() );
		try {
			$this->fixture_job( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, array( 'pre_snapshot_artifacts' => array( array( 'file' => 'safety.tar.gz' ) ) ) );
			$results = array(
				'restore'  => MainWP_Backup_Operations::restore_snapshot( $this->site_id, $this->snapshot_id, array( 'confirm_destructive' => true ), 'ability' ),
				'rollback' => MainWP_Backup_Operations::rollback_restore( $this->job_id, 'ability' ),
			);
			$this->fixture_job( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array(), 'paused-task' );
			$before            = MainWP_Backup_Restore_Job_Store::instance()->get_job( $this->job_id );
			$results['cancel'] = MainWP_Backup_Operations::cancel_restore( $this->job_id );
			foreach ( $results as $lane => $result ) {
				$this->assertWPError( $result, $lane );
				$this->assertSame( 'mainwp_restore_schema_repair_pending', $result->get_error_code(), $lane );
				$this->assertSame( 409, $result->get_error_data()['status'], $lane );
			}
			$this->assertEquals( $before, MainWP_Backup_Restore_Job_Store::instance()->get_job( $this->job_id ) );
			foreach ( array( 'restore-site-snapshot', 'rollback-restore', 'cancel-restore' ) as $name ) {
				$this->assertStringContainsString( 'mainwp_restore_schema_repair_pending', $this->ability( $name )->get_description(), $name );
			}
		} finally {
			if ( false === $pending ) {
				delete_site_option( $marker );
			} else {
				update_site_option( $marker, $pending );
			}
		}
	}

	/**
	 * Handler output must hide whether a job exists outside the caller's scope.
	 *
	 * @return array
	 */
	public static function handlers() {
		return array( array( 'mainwp_restore_progress' ), array( 'mainwp_restore_cancel' ), array( 'mainwp_restore_rollback' ) );
	}

	/**
	 * Missing, denied and reserved identities must share one response.
	 *
	 * @dataProvider handlers
	 * @param string $handler AJAX action.
	 */
	public function test_ajax_missing_denied_and_reserved_jobs_are_indistinguishable( $handler ) {
		$deny = static function ( $allowed, $type ) {
			return 'site' === $type ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		try {
			$denied = $this->ajax( $handler, array( 'job_id' => $this->job_id ) );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}
		$missing = $this->ajax( $handler, array( 'job_id' => 9007199254740991 ) );
		$this->assertSame( $missing, $denied );
		$this->assertSame( 'Restore job not found.', $missing['error'] );
		$this->fixture_job( 'reserved', array() );
		$this->assertSame( $missing, $this->ajax( $handler, array( 'job_id' => $this->job_id ) ) );
	}

	/**
	 * JSON termination is intercepted without changing handler behavior.
	 *
	 * @param string $handler AJAX action.
	 * @param array  $input Request fields.
	 * @return array
	 */
	private function ajax( $handler, $input ) {
		$_POST    = $input + array( 'security' => wp_create_nonce( $handler ) );
		$_REQUEST = $_POST;
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'restore-json-end' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->$handler();
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'restore-json-end', $error->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die );
		}
		return json_decode( $raw, true );
	}

	/** The retry phase remains authoritative after a verification drive-on clears the intent. */
	public function test_drive_on_resolves_retry_resume_status_before_dispatch() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->fixture_job(
			'failed_retryable',
			array(
				'resume_status'    => 'verifying',
				'cancel_intent'    => true,
				'terminal_failure' => array(
					'error'      => 'Earlier failure',
					'error_code' => 'mainwp_restore_artifact_missing',
				),
			)
		);
		MainWP_Backup_Restore_Orchestrator::instance()->run_job( $store->get_job( $this->job_id ), microtime( true ) + 1 );
		$job = $store->get_job( $this->job_id );
		$this->assertSame( 'completed', $job->status );
		$this->assertTrue( $job->checkpoint['verification']['site_responds'] );
		$this->assertArrayNotHasKey( 'cancel_intent', $job->checkpoint );
	}

	/** The cancellation description must distinguish a recorded request from a confirmed stop. */
	public function test_cancel_copy_distinguishes_pending_request_from_stop() {
		$ability = $this->ability( 'cancel-restore' );
		$this->assertStringContainsString( 'cancel_pending means the request is recorded but the job has not stopped; a request recorded before staging is applied when the job reaches staging.', $ability->get_description() );
		$this->assertStringContainsString( 'mainwp_operation_failed', $ability->get_description() );
		$this->assertSame( 'Requires confirm:true or dry_run:true. After cancel_pending or cancel_unconfirmed read get-restore-job-v1 before reporting the site state.', $ability->get_meta()['annotations']['instructions'] );
		$schema = $ability->get_output_schema()['oneOf'][1]['properties']['result'];
		$this->assertSame( array( 'cancelled', 'cancel_pending', 'cancel_unconfirmed', 'ended_failed', 'not_cancellable' ), $schema['enum'] );
		$this->assertStringContainsString( 'cancel_pending: the request is recorded but the job has not stopped; a request recorded before staging is applied when the job reaches staging.', $schema['description'] );
		$this->assertStringNotContainsString( 'next restore step', $schema['description'] );
	}

	/**
	 * Matrix C: every job state the cancel preview can see, with the result confirm reports.
	 *
	 * @return array
	 */
	public static function cancel_previews() {
		$finished    = 'This job has already finished. Confirming changes nothing and reports not_cancellable.';
		$cancelled   = 'This job is already cancelled. Confirming changes nothing and reports cancelled.';
		$unconfirmed = 'This job already ended without the site confirming that it stopped. Confirming changes nothing and reports cancel_unconfirmed.';
		$ended       = 'This job already failed and the site confirmed that it stopped. Confirming changes nothing and reports ended_failed.';
		$verifying   = 'The restore has reached verification, so the job can no longer be cancelled. Confirming reports not_cancellable.';
		$local       = 'The site is not applying the restore yet, so a cancel normally stops the job at once. If the stop cannot be recorded right away, it is applied when the job reaches staging.';
		$site        = 'The site is asked to stop. Confirmation can take up to 15 minutes; poll get-restore-job-v1 until the result is final.';
		$outcome     = static function ( $value ) {
			return array( 'cancel_confirmation' => array( 'outcome' => $value ) );
		};
		return array(
			'C1 completed'                          => array( 'completed', array(), '', false, false, $finished, 'not_cancellable' ),
			'C2 cancelled, not needed'              => array( 'cancelled', $outcome( 'not_needed' ), '', false, false, $cancelled, 'cancelled' ),
			'C2 cancelled, confirmed'               => array( 'cancelled', $outcome( 'confirmed' ), 'owned-restore', false, false, $cancelled, 'cancelled' ),
			'C3 cancelled, unconfirmed'             => array( 'cancelled', $outcome( 'unconfirmed' ), 'owned-restore', false, false, $unconfirmed, 'cancel_unconfirmed' ),
			'C4 failed, confirmed'                  => array( 'failed_permanent', $outcome( 'confirmed' ), 'owned-restore', false, false, $ended, 'ended_failed' ),
			'C5 failed, unconfirmed'                => array( 'failed_permanent', $outcome( 'unconfirmed' ), 'owned-restore', false, false, $unconfirmed, 'cancel_unconfirmed' ),
			'C6 failed, no confirmation'            => array( 'failed_permanent', array(), '', false, false, $finished, 'not_cancellable' ),
			'C7 verifying'                          => array( 'verifying', array(), 'owned-restore', false, false, $verifying, 'not_cancellable' ),
			'C8 retrying verification'              => array( 'failed_retryable', array( 'resume_status' => 'verifying' ), 'owned-restore', true, false, 'Confirming stops the checks and ends this job as failed. Rollback stays available where a safety backup exists.', null ),
			'C9 pending'                            => array( 'pending', array(), '', true, false, $local, null ),
			'C9 preflight'                          => array( 'preflight', array(), '', true, false, $local, null ),
			'C9 pre_snapshot'                       => array( 'pre_snapshot', array(), '', true, false, $local, null ),
			'C10 staging'                           => array( 'staging', array(), 'owned-restore', true, true, $site, null ),
			'C10 transferring'                      => array( 'transferring', array(), 'owned-restore', true, true, $site, null ),
			'C10 running'                           => array( 'running', array(), 'owned-restore', true, true, $site, null ),
			'C11 staging without a Child task'      => array( 'staging', array(), '', true, false, $local, null ),
			'C9 armed intent'                       => array( 'pending', array( 'cancel_intent' => true ), '', true, false, $local, null ),
			'C10 retrying a transfer'               => array( 'failed_retryable', array( 'resume_status' => 'transferring' ), 'owned-restore', true, true, $site, null ),
			'C12 retrying a failure, no Child task' => array(
				'failed_retryable',
				array(
					'resume_status'    => 'pending',
					'terminal_failure' => array(
						'error'      => 'Stored failure.',
						'error_code' => 'mainwp_restore_failed',
					),
				),
				'',
				true,
				false,
				$local,
				null,
			),
		);
	}

	/**
	 * A finished job's preview must name the result confirm reports; an active job's must say whether the site is asked.
	 *
	 * @dataProvider cancel_previews
	 * @param string      $status Fixture status.
	 * @param array       $checkpoint Fixture checkpoint.
	 * @param string      $child Child task identity.
	 * @param bool        $cancellable Expected cancellation eligibility.
	 * @param bool        $asks_site Expected Child request.
	 * @param string      $warning Expected single warning.
	 * @param string|null $result Confirm result when confirm makes no Child request.
	 */
	public function test_cancel_preview_warning_follows_job_state( $status, $checkpoint, $child, $cancellable, $asks_site, $warning, $result ) {
		$this->fixture_job( $status, $checkpoint, $child );
		$output = $this->execute( 'cancel-restore', $this->input( 'cancel-restore' ) + array( 'dry_run' => true ) );
		$this->assert_output( 'cancel-restore', $output );
		$this->assertSame( $cancellable, $output['would_affect']['cancellable'] );
		$this->assertSame( $asks_site, $output['would_affect']['site_will_be_asked'] );
		$this->assertTrue( $output['can_proceed'] );
		$this->assertSame( array( $warning ), $output['warnings'] );
		$this->assertSame( array(), $this->calls );
		if ( null !== $result ) {
			$output = $this->execute( 'cancel-restore', $this->input( 'cancel-restore' ) + array( 'confirm' => true ) );
			$this->assert_output( 'cancel-restore', $output );
			$this->assertSame( $result, $output['result'] );
			$this->assertSame( array(), $this->calls );
		}
	}

	/** Contention arms an early-phase cancel, which must be reported without claiming a stop. */
	public function test_contended_early_phase_cancel_remains_pending_until_staging() {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->fixture_job( 'pending', array() );
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$ticks = 0;
		$hook  = function ( $sql ) use ( $store, $table, &$ticks ) {
			if ( 0 === strpos( $sql, 'UPDATE ' . $table . ' SET `status` = ' ) ) {
				$store->save_checkpoint( $this->job_id, array( 'worker_tick' => ++$ticks ) );
				return $sql . ' AND 1 = 0';
			}
			return $sql;
		};
		add_filter( 'query', $hook );
		try {
			$output = $this->execute( 'cancel-restore', $this->input( 'cancel-restore' ) + array( 'confirm' => true ) );
		} finally {
			remove_filter( 'query', $hook );
		}
		$this->assert_output( 'cancel-restore', $output );
		$this->assertGreaterThan( 0, $ticks );
		$this->assertSame( 'cancel_pending', $output['result'] );
		$this->assertSame( 'pending', $output['status'] );
		$store->set_status( $this->job_id, 'preflight' );
		MainWP_Backup_Restore_Orchestrator::instance()->run_job( $store->get_job( $this->job_id ), microtime( true ) + 1 );
		$this->assertSame( 'staging', $store->get_job( $this->job_id )->status );
		MainWP_Backup_Restore_Orchestrator::instance()->run_job( $store->get_job( $this->job_id ), microtime( true ) + 1 );
		$this->assertSame( 'cancelled', $store->get_job( $this->job_id )->status );
	}

	/**
	 * IDs must retain their exact identity before schema coercion.
	 *
	 * @return array
	 */
	public static function id_shapes() {
		return array(
			'I01' => array( '1.9999999999999999', null, false ),
			'I02' => array( '1.00000000000000001', null, false ),
			'I03' => array( '9007199254740993.0', null, false ),
			'I04' => array( '9.007199254740993e15', null, false ),
			'I05' => array( '9223372036854775808', null, false ),
			'I06' => array( '9007199254740991', 9007199254740991, true ),
			'I07' => array( '+5', null, false ),
			'I08' => array( ' 05 ', null, false ),
			'I09' => array( '5.0', null, false ),
			'I10' => array( '5e0', null, false ),
			'I11' => array( 5, 5, true ),
			'I12' => array( '5', 5, true ),
			'I13' => array( 0, null, false ),
			'I14' => array( -1, null, false ),
			'I15' => array( 9007199254740991, 9007199254740991, true ),
			'I16' => array( 9007199254740992, null, false ),
			'I17' => array( 5.0, null, true ),
			'I18' => array( true, null, false ),
			'I19' => array( null, null, false ),
			'I20' => array( array(), null, false ),
			'I21' => array( '9007199254740992', null, true ),
			'I22' => array( '05', null, false ),
			'I23' => array( "5\n", null, true ),
		);
	}


	/**
	 * Terminal fixtures cannot be reopened through the worker's guarded transition API.
	 *
	 * @param string $status Fixture phase.
	 * @param array  $checkpoint Fixture evidence.
	 * @param string $child Child task identity.
	 */
	private function fixture_job( $status, array $checkpoint, $child = '' ) {
		global $wpdb;
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ),
			array(
				'status'         => $status,
				'checkpoint'     => wp_json_encode( $checkpoint ),
				'child_task_id'  => $child,
				'active_site_id' => in_array( $status, MainWP_Backup_Restore_Job_Store::active_statuses(), true ) || 'reserved' === $status ? $this->site_id : null,
			),
			array( 'id' => $this->job_id )
		);
	}

	/** The two callers keep terminal evidence and rollback available after stopping checks. */
	public function test_stop_checking_ajax_and_ability_keep_failure_and_history() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		foreach ( array( 'ajax', 'ability' ) as $caller ) {
			$this->fixture_job(
				'failed_retryable',
				array(
					'resume_status'          => 'verifying',
					'last_error_code'        => 'unreachable_test',
					'pre_snapshot_artifacts' => array( array( 'file' => 'safety.tar.gz' ) ),
				),
				'finished-task'
			);
			$store->set_status(
				$this->job_id,
				'failed_retryable',
				array(
					'last_error'    => 'Stored unreachable message.',
					'next_retry_at' => time() + 3600,
				)
			);
			$preview = $this->execute( 'cancel-restore', $this->input( 'cancel-restore' ) + array( 'dry_run' => true ) );
			$this->assert_output( 'cancel-restore', $preview );
			$this->assertTrue( $preview['would_affect']['cancellable'] );
			$this->assertFalse( $preview['would_affect']['site_will_be_asked'] );
			$this->assertStringContainsString( 'ends this job as failed', implode( ' ', $preview['warnings'] ) );
			$progress = MainWP_Backup_Read_Model::get_restore_job( $this->job_id );
			$this->assertSame( 'stop_checking', $progress['cancel_action'] );
			$this->assertFalse( $progress['verify_stopped'] );
			if ( 'ajax' === $caller ) {
				$this->assertSame( array( 'result' => 'stopped' ), $this->ajax( 'mainwp_restore_cancel', array( 'job_id' => $this->job_id ) ) );
			} else {
				$result = $this->execute( 'cancel-restore', $this->input( 'cancel-restore' ) + array( 'confirm' => true ) );
				$this->assert_output( 'cancel-restore', $result );
				$this->assertSame( 'ended_failed', $result['result'] );
				$this->assertSame( 'failed_permanent', $result['status'] );
			}
			$final = $store->get_job( $this->job_id );
			$this->assertSame( 'Stored unreachable message.', $final->last_error );
			$this->assertSame( 'unreachable_test', $final->checkpoint['last_error_code'] );
			$this->assertNotEmpty( $final->checkpoint['verify_stopped'] );
			$again = $this->execute( 'cancel-restore', $this->input( 'cancel-restore' ) + array( 'confirm' => true ) );
			$this->assertSame( 'ended_failed', $again['result'] );
			$this->assertEquals( $final, $store->get_job( $this->job_id ) );
			$list = MainWP_Backup_Read_Model::list_restore_jobs( $this->site_id );
			$this->assertSame( 0, $list['active_restore_job_id'] );
			$this->assertSame( $this->job_id, $list['failed_restore_jobs'][0]['job_id'] );
			$this->assertTrue( $list['failed_restore_jobs'][0]['has_rollback'] );
			$progress = $this->ajax( 'mainwp_restore_progress', array( 'job_id' => $this->job_id ) );
			$this->assertSame( '', $progress['cancel_action'] );
			$this->assertTrue( $progress['verify_stopped'] );
			$this->assertEmpty( $this->calls );
		}
	}

	/** A first check and terminal verdicts refuse both callers without changing the row. */
	public function test_stop_checking_refuses_finished_and_first_verification() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		foreach ( array( 'completed', 'failed_permanent', 'verifying' ) as $status ) {
			$this->fixture_job( $status, array() );
			$before = $store->get_job( $this->job_id );
			$this->assertArrayHasKey( 'error', $this->ajax( 'mainwp_restore_cancel', array( 'job_id' => $this->job_id ) ) );
			$result = $this->execute( 'cancel-restore', $this->input( 'cancel-restore' ) + array( 'confirm' => true ) );
			$this->assertSame( 'not_cancellable', $result['result'] );
			$this->assertEquals( $before, $store->get_job( $this->job_id ) );
			$this->assertSame( '', MainWP_Backup_Read_Model::get_restore_job( $this->job_id )['cancel_action'] );
		}
		$this->fixture_job( 'running', array() );
		$this->assertSame( 'cancel', MainWP_Backup_Read_Model::get_restore_job( $this->job_id )['cancel_action'] );
		$this->assertFalse( MainWP_Backup_Read_Model::get_restore_job( $this->job_id )['verify_stopped'] );
		$this->assertEmpty( $this->calls );
	}

	/** A live worker can be checking without moving the checkpoint revision. */
	public function test_stop_checking_ajax_refuses_live_lease_and_accepts_expired_lease() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->fixture_job( 'failed_retryable', array( 'resume_status' => 'verifying' ) );
		$before = $store->get_job( $this->job_id );
		$leased = $store->acquire_next_job( 'checking-worker', 300, time(), $this->job_id );
		$this->assertNotNull( $leased );
		$this->assertSame( $before->checkpoint_rev, $leased->checkpoint_rev );
		$result = $this->ajax( 'mainwp_restore_cancel', array( 'job_id' => $this->job_id ) );
		$this->assertSame( 'The Dashboard is checking the site right now, or the restore status just changed. Try again in a moment.', $result['error'] );
		$this->assertEquals( $leased, $store->get_job( $this->job_id ) );
		$ability = $this->execute( 'cancel-restore', $this->input( 'cancel-restore' ) + array( 'confirm' => true ) );
		$this->assertSame( 'not_cancellable', $ability['result'] );
		$this->assertEquals( $leased, $store->get_job( $this->job_id ) );
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'locked_until' => time() - 1 ), array( 'id' => $this->job_id ) );
		$this->assertSame( array( 'result' => 'stopped' ), $this->ajax( 'mainwp_restore_cancel', array( 'job_id' => $this->job_id ) ) );
		$this->assertEmpty( $this->calls );
	}

	/** A stale stop must preserve completion or cancel from the new in-progress phase. */
	public function test_stop_checking_race_reresolves_phase_for_each_caller() {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		foreach ( array( 'completed', 'running' ) as $phase ) {
			foreach ( array( 'ajax', 'ability' ) as $caller ) {
				$this->calls = array();
				$this->fixture_job( 'failed_retryable', array( 'resume_status' => 'verifying' ), 'finished-task' );
				$raced = null;
				$hook  = function ( $sql ) use ( &$hook, &$raced, $table, $store, $phase ) {
					if ( null === $raced && str_starts_with( $sql, 'UPDATE ' . $table . ' SET `status`' ) && str_contains( $sql, 'verify_stopped' ) ) {
						remove_filter( 'query', $hook );
						$store->set_status( $this->job_id, $phase );
						$raced = $store->get_job( $this->job_id );
					}
					return $sql;
				};
				add_filter( 'query', $hook );
				try {
					if ( 'ajax' === $caller ) {
						$result = $this->ajax( 'mainwp_restore_cancel', array( 'job_id' => $this->job_id ) );
						$this->assertArrayHasKey( 'error', $result );
					} else {
						$result = $this->execute( 'cancel-restore', $this->input( 'cancel-restore' ) + array( 'confirm' => true ) );
						$this->assert_output( 'cancel-restore', $result );
						$this->assertSame( 'completed' === $phase ? 'not_cancellable' : 'cancelled', $result['result'] );
					}
				} finally {
					remove_filter( 'query', $hook );
				}
				$this->assertNotNull( $raced );
				$final = $store->get_job( $this->job_id );
				$this->assertArrayNotHasKey( 'verify_stopped', $final->checkpoint );
				if ( 'running' === $phase && 'ability' === $caller ) {
					$this->assertSame( 'cancelled', $final->status );
					$this->assertSame( array( 'restore_task_cancel' ), $this->calls );
				} else {
					$this->assertEquals( $raced, $final );
					$this->assertEmpty( $this->calls );
				}
			}
		}
	}

	/**
	 * Every ID input follows the same contract through PHP and REST.
	 *
	 * @dataProvider id_shapes
	 * @param mixed    $id Raw ID.
	 * @param int|null $expected Accepted identity.
	 * @param bool     $schema_accepts Core schema acceptance.
	 */
	public function test_every_id_input_preserves_exact_identity( $id, $expected, $schema_accepts ) {
		foreach ( self::actions() as $action ) {
			list( $name, $method ) = $action;
			foreach ( array_keys( $this->input( $name ) ) as $key ) {
				$input  = array_replace(
					$this->input( $name ),
					array(
						$key      => $id,
						'dry_run' => true,
					)
				);
				$output = $this->execute( $name, $input );
				$this->assertWPError( $output );
				$this->assertSame( null === $expected ? 400 : 404, $output->get_error_data()['status'], $name . ':' . $key );
				$response = $this->rest( $name, $method, $input );
				$this->assertSame( null === $expected ? 400 : 404, $response->get_status(), wp_json_encode( $response->get_data() ) );
			}
		}
	}

	/**
	 * Body routes must use the same raw IDs as direct PHP calls.
	 *
	 * @param string $name Slug.
	 * @param string $method Transport method.
	 * @param array  $input Caller input.
	 * @return \WP_REST_Response
	 */
	private function rest( $name, $method, $input ) {
		global $wp_rest_server;
		$previous       = $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		$request = new \WP_REST_Request( $method, '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ), JSON_PRESERVE_ZERO_FRACTION ) );
		try {
			return $this->guarded(
				static function () use ( $request ) {
					return rest_do_request( $request );
				}
			);
		} finally {
			$wp_rest_server = $previous;
		}
	}

	/** Canonical digit strings and domains resolve to the allocated fixture on both transports. */
	public function test_canonical_ids_and_domains_succeed() {
		foreach ( self::actions() as $action ) {
			list( $name, $method ) = $action;
			$input                 = array_map( 'strval', $this->input( $name ) ) + array( 'dry_run' => true );
			$this->assert_output( $name, $this->execute( $name, $input ) );
			$response = $this->rest( $name, $method, $input );
			$this->assertSame( 200, $response->get_status() );
			$this->assert_output( $name, $response->get_data() );
		}
		$input                      = $this->input( 'restore-site-snapshot' ) + array( 'dry_run' => true );
		$input['site_id_or_domain'] = MainWP_DB::instance()->get_website_by_id( $this->site_id )->url;
		$this->assert_output( 'restore-site-snapshot', $this->execute( 'restore-site-snapshot', $input ) );
	}

	/**
	 * The shared refusals keep their codes through every transport.
	 *
	 * @return array
	 */
	public static function refusals() {
		return array(
			array( 'disconnected', 'mainwp_backup_site_disconnected' ),
			array( 'consent', 'mainwp_confirmation_required' ),
			array( 'woo', 'mainwp_restore_woo_legacy_orders' ),
			array( 'rollback-native-off', 'mainwp_backup_legacy_disabled' ),
		);
	}

	/**
	 * AJAX's legacy consent and disconnection shapes still identify the shared refusal.
	 *
	 * @dataProvider refusals
	 * @param string $fixture Refusal fixture.
	 * @param string $code Shared refusal code.
	 */
	public function test_shared_refusals_through_ability_ajax_and_cli( $fixture, $code ) {
		global $wpdb;
		if ( 'disconnected' === $fixture ) {
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'sync_errors' => 'Disconnected fixture' ), array( 'wpid' => $this->site_id ) );
		}
		$rollback = 'rollback-native-off' === $fixture;
		if ( $rollback ) {
			update_option( 'mainwp_enableNativeBackups', 0 );
		}
		$name  = $rollback ? 'rollback-restore' : 'restore-site-snapshot';
		$input = $this->input( $name );
		if ( 'consent' !== $fixture ) {
			$input['confirm'] = true;
		}
		if ( 'woo' === $fixture ) {
			$input['preserve_orders'] = true;
		}
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$before = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A );
		$error  = $this->execute( $name, $input );
		$this->assertSame( $code, $error->get_error_code() );
		$ajax = $this->ajax(
			$rollback ? 'mainwp_restore_rollback' : 'mainwp_restore_initiate',
			array(
				'site_id'             => $this->site_id,
				'snapshot_id'         => $this->snapshot_id,
				'job_id'              => $this->job_id,
				'confirm_destructive' => 'consent' === $fixture ? '0' : '1',
				'preserve_orders'     => 'woo' === $fixture ? '1' : '0',
			)
		);
		if ( 'consent' === $fixture ) {
			$this->assertTrue( $ajax['needs_confirmation'] );
			$this->assertSame( 3, $ajax['age_days'] );
		} elseif ( 'disconnected' === $fixture ) {
			$this->assertSame( array( 'error' => MainWP_Post_Backup_Handler::disconnected_site_error() ), $ajax );
		} else {
			$this->assertSame( $code, $ajax['error_code'] );
		}
		$cli = ( new MainWP_Backup_CLI_Restore_Runner( null, null, null, 30, 0, 0, 1 ) )->run( $this->site_id, $this->snapshot_id, array( 'preserve_orders' => 'woo' === $fixture ), 'consent' !== $fixture );
		$this->assertSame( $code, $cli['error_code'] );
		$this->assertSame( $before, $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A ) );
	}

	/**
	 * Cancellation results derive from the persisted stop confirmation.
	 *
	 * @return array
	 */
	public static function cancel_outcomes() {
		return array(
			array( 'busy', true, false, true, 'cancel_unconfirmed' ),
			array( 'busy', true, true, true, 'cancel_unconfirmed' ),
			array( 'ok', false, true, true, 'ended_failed' ),
			array( 'already_terminal', false, true, true, 'ended_failed' ),
			array( 'ok', false, false, true, 'cancelled' ),
			array( 'busy', false, false, true, 'cancel_pending' ),
			array( 'ok', false, false, false, 'cancelled' ),
		);
	}

	/**
	 * A stored failure must not hide an unconfirmed cancellation.
	 *
	 * @dataProvider cancel_outcomes
	 * @param string $reply Child answer.
	 * @param bool   $expired Confirmation window elapsed.
	 * @param bool   $prior Earlier failure.
	 * @param bool   $child Child task exists.
	 * @param string $expected Result.
	 */
	public function test_cancel_preserves_result_enum_and_confirmation( $reply, $expired, $prior, $child, $expected ) {
		$checkpoint = array();
		if ( $expired ) {
			$checkpoint['cancel_confirmation'] = array( 'first_attempt_at' => time() - 900 );
		}
		if ( $prior ) {
			$checkpoint['terminal_failure'] = array(
				'error'      => 'Earlier artifact failure',
				'error_code' => 'mainwp_restore_artifact_missing',
			);
		}
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->fixture_job( $child ? 'running' : 'pending', $checkpoint, $child ? 'owned-restore' : '' );
		$this->cancel_reply = array(
			'result' => $reply,
			'phase'  => 'failed',
		);
		$output             = $this->execute( 'cancel-restore', $this->input( 'cancel-restore' ) + array( 'confirm' => true ) );
		$this->assert_output( 'cancel-restore', $output );
		$this->assertSame( $expected, $output['result'] );
		$read = wp_get_ability( 'mainwp/get-restore-job-v1' )->execute( array( 'restore_job_id' => $this->job_id ) );
		$this->assertSame( 'cancel_unconfirmed' === $expected, 'cancel_unconfirmed' === $read['job']['outcome'] );
		if ( $prior ) {
			$this->assertSame( 'mainwp_restore_artifact_missing', $read['job']['error']['code'] );
		}
	}

	/**
	 * Operation rights must be checked before target reads in every mode.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_right_and_site_scope_are_enforced( $name ) {
		if ( 'cancel-restore' === $name ) {
			$this->fixture_job( 'failed_retryable', array( 'resume_status' => 'verifying' ) );
		}
		global $wpdb;
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$before = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A );
		foreach ( array( 'execute_backups', 'restore_backups' ) as $missing ) {
			$deny = static function ( $allowed, $type, $right ) use ( $missing ) {
				return 'dashboard' === $type && $missing === $right ? false : $allowed;
			};
			add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
			try {
				foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
					$input = $this->input( $name ) + array( $mode => true );
					foreach ( array( $this->ability( $name )->check_permissions( $input ), $this->execute( $name, $input, true ) ) as $error ) {
						$this->assertWPError( $error );
						$this->assertSame( 'mainwp_insufficient_right', $error->get_error_code() );
						$this->assertSame(
							array(
								'status' => 403,
								'right'  => $missing,
							),
							$error->get_error_data()
						);
					}
				}
			} finally {
				remove_filter( 'mainwp_currentusercan', $deny, 10 );
			}
		}
		$this->assertSame( $before, $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A ) );
		$this->assertSame( array(), $this->calls );
		$deny = static function ( $allowed, $type ) {
			return 'site' === $type ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 2 );
		try {
			foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
				$error = $this->execute( $name, $this->input( $name ) + array( $mode => true ) );
				$this->assertSame( 'restore-site-snapshot' === $name ? 'mainwp_backup_snapshot_not_found' : 'mainwp_backup_restore_job_not_found', $error->get_error_code() );
				$this->assertSame( 404, $error->get_error_data()['status'] );
			}
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}
	}

	/**
	 * Direct service callers must not bypass either restore right.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_service_restore_rights_match_previews( $name ) {
		if ( 'cancel-restore' === $name ) {
			$this->fixture_job( 'failed_retryable', array( 'resume_status' => 'verifying' ) );
		}
		$execute = array(
			'restore-site-snapshot' => array( 'restore_snapshot', array( $this->site_id, $this->snapshot_id, array( 'confirm_destructive' => true ), 'ability' ) ),
			'rollback-restore'      => array( 'rollback_restore', array( $this->job_id ) ),
			'cancel-restore'        => array( 'cancel_restore', array( $this->job_id ) ),
		);
		$preview = array(
			'restore-site-snapshot' => array( 'preview_restore_snapshot', array( $this->site_id, $this->snapshot_id, array() ) ),
			'rollback-restore'      => array( 'preview_restore_rollback', array( $this->job_id ) ),
			'cancel-restore'        => array( 'preview_restore_cancel', array( $this->job_id ) ),
		);
		$before  = MainWP_Backup_Restore_Job_Store::instance()->get_job( $this->job_id );
		foreach ( array( 'execute_backups', 'restore_backups' ) as $missing ) {
			$deny = static function ( $allowed, $type, $right ) use ( $missing ) {
				return 'dashboard' === $type && $missing === $right ? false : $allowed;
			};
			add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
			try {
				foreach ( array( $execute[ $name ], $preview[ $name ] ) as list( $method, $args ) ) {
					$error = $this->guarded(
						static function () use ( $method, $args ) {
							return MainWP_Backup_Operations::$method( ...$args );
						}
					);
					$this->assertWPError( $error );
					$this->assertSame( 'mainwp_insufficient_right', $error->get_error_code() );
					$this->assertSame(
						array(
							'status' => 403,
							'right'  => $missing,
						),
						$error->get_error_data()
					);
				}
			} finally {
				remove_filter( 'mainwp_currentusercan', $deny, 10 );
			}
		}
		$this->assertEquals( $before, MainWP_Backup_Restore_Job_Store::instance()->get_job( $this->job_id ) );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Handler calls remain protected after registration has already happened.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_ajax_restore_writes_recheck_both_rights( $name ) {
		if ( 'cancel-restore' === $name ) {
			$this->fixture_job( 'failed_retryable', array( 'resume_status' => 'verifying' ) );
		}
		$handler = array(
			'restore-site-snapshot' => 'mainwp_restore_initiate',
			'rollback-restore'      => 'mainwp_restore_rollback',
			'cancel-restore'        => 'mainwp_restore_cancel',
		)[ $name ];
		global $wpdb;
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$before = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A );
		foreach ( array( 'execute_backups', 'restore_backups' ) as $missing ) {
			$deny = static function ( $allowed, $type, $right ) use ( $missing ) {
				return 'dashboard' === $type && $missing === $right ? false : $allowed;
			};
			add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
			try {
				$result = $this->ajax(
					$handler,
					array(
						'site_id'             => $this->site_id,
						'snapshot_id'         => $this->snapshot_id,
						'job_id'              => $this->job_id,
						'confirm_destructive' => '1',
					)
				);
				$this->assertArrayHasKey( 'error', $result );
				$this->assertSame( $before, $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A ) );
				$this->assertSame( array(), $this->calls );
			} finally {
				remove_filter( 'mainwp_currentusercan', $deny, 10 );
			}
		}
		$result = $this->ajax(
			$handler,
			array(
				'site_id'             => $this->site_id,
				'snapshot_id'         => $this->snapshot_id,
				'job_id'              => $this->job_id,
				'confirm_destructive' => '1',
			)
		);
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertArrayHasKey( 'cancel-restore' === $name ? 'result' : 'job_id', $result );
	}

	/** Withholding restore permission must leave existing job and snapshot reads usable. */
	public function test_ajax_restore_reads_keep_execute_backups_right() {
		$deny = static function ( $allowed, $type, $right ) {
			return 'dashboard' === $type && 'restore_backups' === $right ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		add_filter( 'mainwp_restore_foreground_budget', '__return_zero' );
		try {
			foreach ( array( 'mainwp_restore_snapshot_list', 'mainwp_restore_progress' ) as $handler ) {
				$result = $this->ajax(
					$handler,
					array(
						'site_id' => $this->site_id,
						'job_id'  => $this->job_id,
					)
				);
				$this->assertIsArray( $result );
				$this->assertArrayNotHasKey( 'error', $result );
			}
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
			remove_filter( 'mainwp_restore_foreground_budget', '__return_zero' );
		}
	}

	/** Catalog admission must be reflected in preview refusals without exposing artifact paths. */
	public function test_preview_reports_unrestorable_and_disconnected_refusals() {
		unlink( $this->artifact );
		file_put_contents( $this->artifact, '' );
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'status' => 'pruned' ), array( 'id' => $this->snapshot_id ) );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'sync_errors' => 'Disconnected fixture' ), array( 'wpid' => $this->site_id ) );
		$output = $this->execute(
			'restore-site-snapshot',
			$this->input( 'restore-site-snapshot' ) + array(
				'dry_run'            => true,
				'take_safety_backup' => false,
				'preserve_orders'    => true,
			)
		);
		$this->assert_output( 'restore-site-snapshot', $output );
		$this->assertFalse( $output['can_proceed'] );
		$this->assertFalse( $output['would_affect']['restorable'] );
		$this->assertFalse( $output['would_affect']['safety_backup']['will_take'] );
		$this->assertSame( array( 'mainwp_backup_site_disconnected', 'mainwp_backup_snapshot_unrestorable_pruned' ), array_column( $output['refusals'], 'code' ) );
		$this->assertStringNotContainsString( $this->artifact, wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) );
	}

	/** A refused files restore still describes the snapshot's own type. */
	public function test_unrestorable_files_preview_does_not_warn_about_database_replacement() {
		global $wpdb;
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'type'   => 'files',
				'status' => 'pruned',
			),
			array( 'id' => $this->snapshot_id )
		);
		$output = $this->execute( 'restore-site-snapshot', $this->input( 'restore-site-snapshot' ) + array( 'dry_run' => true ) );
		$this->assert_output( 'restore-site-snapshot', $output );
		$this->assertFalse( $output['would_affect']['restorable'] );
		$this->assertSame( 'files', $output['would_affect']['restore_type'] );
		$this->assertFalse( $output['would_affect']['replaces_database'] );
		$this->assertNotContains( 'This restore replaces the database. Content changed since the snapshot will be lost.', $output['warnings'] );
	}

	/** Restore starts cannot attach to a busy site, so the schema must describe only emitted statuses. */
	public function test_restore_start_schemas_exclude_already_running() {
		foreach ( array( 'restore-site-snapshot', 'rollback-restore' ) as $name ) {
			$this->assertSame( array( 'started', 'not_started' ), $this->ability( $name )->get_output_schema()['oneOf'][1]['properties']['status']['enum'] );
		}
	}

	/**
	 * Restore and rollback previews must not promise admission on a busy site.
	 *
	 * @return array
	 */
	public static function busy_sites() {
		return array( array( 'restore-site-snapshot', false ), array( 'rollback-restore', false ), array( 'restore-site-snapshot', true ), array( 'rollback-restore', true ) );
	}

	/**
	 * Busy-site errors must preserve the same stable code advertised by the preview.
	 *
	 * @dataProvider busy_sites
	 * @param string $name Slug.
	 * @param bool   $manual Open on-demand backup instead of a restore.
	 */
	public function test_busy_site_preview_and_confirm_share_code( $name, $manual ) {
		$task = null;
		if ( $manual ) {
			$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				(int) $task->id,
				array(
					'last_run'  => time(),
					'completed' => 0,
				)
			);
		} else {
			MainWP_Backup_Restore_Job_Store::instance()->create_job( $this->site_id, $this->snapshot_id, array() );
		}
		try {
			$preview = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ) );
			$this->assert_output( $name, $preview );
			$this->assertFalse( $preview['can_proceed'] );
			$this->assertContains( 'mainwp_backup_already_running', array_column( $preview['refusals'], 'code' ) );
			$error = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
			$this->assertWPError( $error );
			$this->assertSame( 'mainwp_backup_already_running', $error->get_error_code() );
			$this->assertSame( 409, $error->get_error_data()['status'] );
		} finally {
			if ( $task ) {
				global $wpdb;
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => (int) $task->id ) );
			}
		}
	}

	/**
	 * Every serializer uses the same set of embedded private values.
	 *
	 * @return array
	 */
	public static function sensitive_sources() {
		return MainWP_Backup_Safe_Error_Test::sensitive_sources();
	}

	/**
	 * Private source text must not escape through a preview, confirmation or error.
	 *
	 * @dataProvider sensitive_sources
	 * @param string $secret Injected private value.
	 */
	public function test_private_source_values_never_reach_ability_outputs( $secret ) {
		$checkpoint = array(
			'sub_type'               => 'db',
			'last_error_code'        => 'mainwp_restore_artifact_missing',
			'terminal_failure'       => array(
				'error'      => 'Failure at ' . $secret,
				'error_code' => 'mainwp_restore_artifact_missing',
			),
			'pre_snapshot_artifacts' => array(
				array(
					'file' => 'safety.tar.gz',
					'role' => 'full',
					'path' => $secret,
				),
			),
		);
		$this->fixture_job( 'failed_permanent', $checkpoint );
		foreach ( self::actions() as $action ) {
			$name   = $action[0];
			$output = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ) );
			$this->assert_output( $name, $output );
			$this->assertStringNotContainsString( $secret, wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) );
		}
		$this->fixture_job( 'running', $checkpoint, 'owned-restore' );
		$this->cancel_reply = array( 'result' => 'ok' );
		$output             = $this->execute( 'cancel-restore', $this->input( 'cancel-restore' ) + array( 'confirm' => true ) );
		$this->assertSame( 'ended_failed', $output['result'] );
		$this->assertStringNotContainsString( $secret, wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) );
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$throw = static function ( $sql ) use ( $table, $secret ) {
			if ( str_starts_with( $sql, 'INSERT INTO ' . $table ) ) {
				throw new \RuntimeException( 'Unexpected failure at ' . $secret );
			}
			return $sql;
		};
		add_filter( 'query', $throw );
		try {
			$error = $this->execute( 'restore-site-snapshot', $this->input( 'restore-site-snapshot' ) + array( 'confirm' => true ) );
		} finally {
			remove_filter( 'query', $throw );
		}
		$this->assertSame( 'mainwp_internal_error', $error->get_error_code() );
		$this->assertStringNotContainsString( $secret, wp_json_encode( array( $error->get_error_message(), $error->get_error_data() ), JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * A vanished admitted row cannot support a start time or attach to another site job.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_start_uses_own_job_read_and_clears_time_when_not_started( $name ) {
		if ( 'cancel-restore' === $name ) {
			$this->assertTrue( $this->ability( $name )->get_meta()['annotations']['idempotent'] );
			return;
		}
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$misses = 0;
		$hide   = function ( $sql ) use ( $table, &$misses ) {
			if ( preg_match( '/^SELECT \* FROM ' . preg_quote( $table, '/' ) . ' WHERE id = (\d+)$/', $sql, $match ) && (int) $match[1] !== $this->job_id ) {
				++$misses;
				return $sql . ' AND 1 = 0';
			}
			return $sql;
		};
		add_filter( 'query', $hide );
		try {
			$output = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
		} finally {
			remove_filter( 'query', $hide );
		}
		$this->assert_output( $name, $output );
		$this->assertSame( 1, $misses );
		$this->assertSame( 'not_started', $output['status'] );
		$this->assertNull( $output['started_at'] );
		$this->assertSame( $output['restore_job_id'], $output['poll']['input']['restore_job_id'] );
	}

	/**
	 * Known local refusals must agree between preview and confirmation.
	 *
	 * @return array
	 */
	public static function local_refusals() {
		return array(
			array( 'pruned', 'restore-site-snapshot', 'mainwp_backup_snapshot_unrestorable_pruned' ),
			array( 'artifact-missing', 'restore-site-snapshot', 'mainwp_backup_snapshot_unrestorable_artifact_unavailable' ),
			array( 'safety-missing', 'rollback-restore', 'mainwp_restore_rollback_status' ),
			array( 'safety-pruned', 'rollback-restore', 'mainwp_restore_rollback_status' ),
		);
	}

	/**
	 * A refused consent preview must not degrade into an internal error at confirm.
	 *
	 * @dataProvider local_refusals
	 * @param string $fixture Local refusal.
	 * @param string $name Slug.
	 * @param string $code Expected refusal.
	 */
	public function test_known_local_refusals_keep_their_codes( $fixture, $name, $code ) {
		global $wpdb;
		if ( 'pruned' === $fixture ) {
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'status' => 'pruned' ), array( 'id' => $this->snapshot_id ) );
		} elseif ( 'artifact-missing' === $fixture ) {
			unlink( $this->artifact );
		} else {
			$checkpoint = 'safety-missing' === $fixture ? array() : array(
				'pre_snapshot_pruned'    => true,
				'pre_snapshot_artifacts' => array( array( 'file' => 'safety.tar.gz' ) ),
			);
			$this->fixture_job( 'failed_permanent', $checkpoint );
		}
		try {
			$preview = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ) );
			$this->assert_output( $name, $preview );
			$this->assertFalse( $preview['can_proceed'] );
			$this->assertContains( $code, array_column( $preview['refusals'], 'code' ) );
			$error = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
			$this->assertSame( $code, $error->get_error_code() );
			$this->assertSame( 409, $error->get_error_data()['status'] );
		} finally {
			if ( ! file_exists( $this->artifact ) ) {
				file_put_contents( $this->artifact, 'snapshot fixture' );
			}
		}
	}
}
