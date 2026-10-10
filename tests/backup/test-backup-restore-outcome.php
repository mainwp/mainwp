<?php
/**
 * Cancellation confirmation remains independent of the failure that prompted it.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Read_Model;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Exception;

require_once __DIR__ . '/class-mainwp-backup-read-fixtures.php';

/** Class MainWP_Backup_Restore_Outcome_Test. */
class MainWP_Backup_Restore_Outcome_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Fixtures;
	use Backup_Cancel_Transport_Guard;

	/**
	 * Seed stored restore evidence.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->seed_read_fixtures();
	}

	/**
	 * Keep fixtures isolated across cases.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->clear_read_fixtures();
		parent::tear_down();
	}

	/**
	 * Stored failures survive each distinct cancellation outcome.
	 *
	 * @dataProvider cancellation_outcomes
	 * @param string $reply Child reply.
	 * @param bool   $expired Confirmation window elapsed.
	 * @param bool   $prior Original failure exists.
	 * @param bool   $child Child task exists.
	 * @param string $confirmation Persisted confirmation.
	 * @param string $outcome Read outcome.
	 * @param string $result Cancellation result.
	 * @return void
	 */
	public function test_persisted_confirmation_and_read_derivation( $reply, $expired, $prior, $child, $confirmation, $outcome, $result ) {
		global $wpdb;
		$checkpoint = array();
		if ( $expired ) {
			$checkpoint['cancel_confirmation'] = array( 'first_attempt_at' => time() - MainWP_Backup_Restore_Job_Store::CANCEL_CONFIRMATION_TIMEOUT_SECONDS );
		}
		if ( $prior ) {
			$checkpoint['terminal_failure'] = array(
				'error'      => 'Original missing artifact',
				'error_code' => 'mainwp_restore_artifact_missing',
			);
		}
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ),
			array(
				'status'        => $child ? 'running' : 'pending',
				'child_task_id' => $child ? 'owned-restore' : '',
				'checkpoint'    => wp_json_encode( $checkpoint ),
				'last_error'    => '',
			),
			array( 'id' => 987674 )
		);
		$stub = static function ( $value, $site, $what ) use ( $reply ) {
			return 'restore_task_cancel' === $what ? ( 'failed' === $reply ? array(
				'result' => 'already_terminal',
				'phase'  => 'failed',
			) : array( 'result' => $reply ) ) : $value;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $stub, 10, 3 );
		try {
			$response = MainWP_Backup_Operations::cancel_restore( 987674 );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $stub, 10 );
		}
		$this->assertIsArray( $response );
		$this->assertSame( $result, $response['result'] );
		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( 987674 );
		$this->assertSame( $confirmation, $job->checkpoint['cancel_confirmation']['outcome'] );
		$read = MainWP_Backup_Read_Model::get_restore_job( 987674 );
		$this->assertSame( $outcome, $read['outcome'] );
		$this->assertSame( $confirmation, $read['cancel_confirmation'] );
		$this->assertSame( 'cancel_unconfirmed' === $outcome, in_array( 'cancel_unconfirmed', $read['partial_state_reasons'], true ) );
		if ( $prior ) {
			$this->assertSame( 'mainwp_restore_artifact_missing', $read['error']['code'] );
			$this->assertStringContainsString( 'Original missing artifact', $job->last_error );
		}
	}

	/**
	 * Cases distinguish state from cancellation evidence.
	 *
	 * @return array
	 */
	public static function cancellation_outcomes() {
		return array(
			'plain timeout'              => array( 'busy', true, false, true, 'unconfirmed', 'cancel_unconfirmed', 'cancel_unconfirmed' ),
			'earlier failure timeout'    => array( 'busy', true, true, true, 'unconfirmed', 'cancel_unconfirmed', 'cancel_unconfirmed' ),
			'earlier failure confirmed'  => array( 'ok', false, true, true, 'confirmed', 'failed', 'ended_failed' ),
			'child failed confirms stop' => array( 'failed', false, true, true, 'confirmed', 'failed', 'ended_failed' ),
			'confirmed cancellation'     => array( 'ok', false, false, true, 'confirmed', 'cancelled', 'cancelled' ),
			'pending confirmation'       => array( 'busy', false, false, true, 'pending', 'in_progress', 'cancel_pending' ),
			'no child task'              => array( 'ok', false, false, false, 'not_needed', 'cancelled', 'cancelled' ),
		);
	}

	/**
	 * An ordinary failure has no implication that cancellation was unconfirmed.
	 *
	 * @return void
	 */
	public function test_ordinary_failure_is_failed() {
		$this->assertSame( 'failed', MainWP_Backup_Read_Model::get_restore_job( 987674 )['outcome'] );
	}

	/**
	 * An intent saved before confirmation fields existed still has a pending stop.
	 *
	 * @return void
	 */
	public function test_already_armed_intent_persists_pending_confirmation() {
		global $wpdb;
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ),
			array(
				'status'     => 'running',
				'checkpoint' => '{"cancel_intent":true}',
			),
			array( 'id' => 987674 )
		);
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->arm_cancel_intent( 987674 ) );
		$this->assertSame( 'pending', $store->get_job( 987674 )->checkpoint['cancel_confirmation']['outcome'] );
	}

	/**
	 * Rows of the terminal-write matrix: setup, call, concurrent write and the stored result.
	 *
	 * Setup keys: status, child (Child task id), intent (stored cancel intent), prior (stored
	 * failure), expired (confirmation window elapsed), token (row lease holder).
	 * Call keys: error and code (null error is a user cancellation), token (caller lease), reply.
	 * Race: none, or array( position: read|swap, write: defer|save|terminal|foreign|contend ).
	 * Expect keys: result, status, confirmation (null = key absent), code (null = not checked),
	 * artifacts (worker checkpoint kept), intent (cancel intent on the row).
	 *
	 * @return array
	 */
	public static function terminal_write_matrix() {
		$cancel = array(
			'error' => null,
			'code'  => '',
			'token' => null,
		);
		$fail   = array(
			'error' => 'Disk full on the site.',
			'code'  => 'mainwp_restore_disk_full',
			'token' => 'worker-token',
		);
		return array(
			'T1 cancel without a Child task'       => array(
				array( 'status' => 'pending' ),
				$cancel + array( 'reply' => '' ),
				'none',
				array(
					'result'       => 'terminal',
					'status'       => 'cancelled',
					'confirmation' => 'not_needed',
				),
			),
			'T2 cancel confirmed by ok'            => array(
				array(
					'status' => 'running',
					'child'  => 'owned-restore',
				),
				$cancel + array( 'reply' => 'ok' ),
				'none',
				array(
					'result'       => 'terminal',
					'status'       => 'cancelled',
					'confirmation' => 'confirmed',
				),
			),
			'T3 cancel confirmed by failed phase'  => array(
				array(
					'status' => 'running',
					'child'  => 'owned-restore',
				),
				$cancel + array( 'reply' => 'failed' ),
				'none',
				array(
					'result'       => 'terminal',
					'status'       => 'cancelled',
					'confirmation' => 'confirmed',
				),
			),
			'T4 cancel unconfirmed at timeout'     => array(
				array(
					'status'  => 'running',
					'child'   => 'owned-restore',
					'expired' => true,
				),
				$cancel + array( 'reply' => 'busy' ),
				'none',
				array(
					'result'       => 'terminal',
					'status'       => 'failed_permanent',
					'confirmation' => 'unconfirmed',
					'code'         => 'mainwp_restore_cancel_unconfirmed',
				),
			),
			'T5 stored failure, stop confirmed'    => array(
				array(
					'status' => 'running',
					'child'  => 'owned-restore',
					'prior'  => true,
				),
				$cancel + array( 'reply' => 'ok' ),
				'none',
				array(
					'result'       => 'terminal',
					'status'       => 'failed_permanent',
					'confirmation' => 'confirmed',
					'code'         => 'mainwp_restore_artifact_missing',
				),
			),
			'T6 stored failure, unconfirmed'       => array(
				array(
					'status'  => 'running',
					'child'   => 'owned-restore',
					'prior'   => true,
					'expired' => true,
				),
				$cancel + array( 'reply' => 'busy' ),
				'none',
				array(
					'result'       => 'terminal',
					'status'       => 'failed_permanent',
					'confirmation' => 'unconfirmed',
					'code'         => 'mainwp_restore_artifact_missing',
				),
			),
			'T7 worker failure, no cancel'         => array(
				array(
					'status' => 'pending',
					'token'  => 'worker-token',
				),
				$fail + array( 'reply' => '' ),
				'none',
				array(
					'result'       => 'terminal',
					'status'       => 'failed_permanent',
					'confirmation' => null,
					'code'         => 'mainwp_restore_disk_full',
				),
			),
			'T8 worker failure after a cancel'     => array(
				array(
					'status' => 'pending',
					'token'  => 'worker-token',
					'intent' => true,
				),
				$fail + array( 'reply' => '' ),
				'none',
				array(
					'result'       => 'terminal',
					'status'       => 'failed_permanent',
					'confirmation' => 'not_needed',
					'code'         => 'mainwp_restore_disk_full',
				),
			),
			'T9 re-deferral lands before the swap' => array(
				array(
					'status'  => 'running',
					'child'   => 'owned-restore',
					'prior'   => true,
					'expired' => true,
					'token'   => 'worker-token',
				),
				$cancel + array( 'reply' => 'busy' ),
				array( 'swap', 'defer' ),
				array(
					'result'       => 'terminal',
					'status'       => 'failed_permanent',
					'confirmation' => 'unconfirmed',
					'code'         => 'mainwp_restore_artifact_missing',
				),
			),
			'T10 worker save before the read'      => array(
				array(
					'status' => 'pending',
					'token'  => 'worker-token',
					'intent' => true,
				),
				$cancel + array( 'reply' => '' ),
				array( 'read', 'save' ),
				array(
					'result'       => 'terminal',
					'status'       => 'cancelled',
					'confirmation' => 'not_needed',
					'artifacts'    => true,
				),
			),
			'T10 worker save before the swap'      => array(
				array(
					'status' => 'pending',
					'token'  => 'worker-token',
					'intent' => true,
				),
				$cancel + array( 'reply' => '' ),
				array( 'swap', 'save' ),
				array(
					'result'       => 'terminal',
					'status'       => 'cancelled',
					'confirmation' => 'not_needed',
					'artifacts'    => true,
				),
			),
			'T11 another writer finished it'       => array(
				array( 'status' => 'pending' ),
				$cancel + array( 'reply' => '' ),
				array( 'read', 'terminal' ),
				array(
					'result'       => 'terminal',
					'status'       => 'failed_permanent',
					'confirmation' => null,
				),
			),
			'T12 worker lost its lease'            => array(
				array(
					'status' => 'pending',
					'token'  => 'worker-token',
				),
				$fail + array( 'reply' => '' ),
				array( 'read', 'foreign' ),
				array(
					'result'       => 'deferred',
					'status'       => 'pending',
					'confirmation' => null,
				),
			),
			'T13 UI cancel keeps losing the swap'  => array(
				array(
					'status' => 'pending',
					'token'  => 'worker-token',
				),
				$cancel + array( 'reply' => '' ),
				array( 'swap', 'contend' ),
				array(
					'result'       => 'deferred',
					'status'       => 'pending',
					'confirmation' => 'pending',
					'intent'       => true,
				),
			),
			'T14 worker keeps losing the swap'     => array(
				array(
					'status' => 'pending',
					'token'  => 'worker-token',
				),
				$fail + array( 'reply' => '' ),
				array( 'swap', 'contend' ),
				array(
					'result'       => 'deferred',
					'status'       => 'pending',
					'confirmation' => null,
					'intent'       => false,
				),
			),
		);
	}

	/**
	 * The terminal status and its stop evidence are one write under every concurrent writer.
	 *
	 * @dataProvider terminal_write_matrix
	 * @param array        $setup  Stored row.
	 * @param array        $call   record_terminal_failure() arguments and the Child reply.
	 * @param string|array $race   Concurrent write and where it lands.
	 * @param array        $expect Result and stored row.
	 * @return void
	 */
	public function test_terminal_write_matrix( $setup, $call, $race, $expect ) {
		global $wpdb;
		$table      = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$store      = MainWP_Backup_Restore_Job_Store::instance();
		$checkpoint = array();
		if ( ! empty( $setup['intent'] ) ) {
			$checkpoint['cancel_intent'] = true;
		}
		if ( ! empty( $setup['expired'] ) ) {
			$checkpoint['cancel_confirmation'] = array( 'first_attempt_at' => time() - MainWP_Backup_Restore_Job_Store::CANCEL_CONFIRMATION_TIMEOUT_SECONDS );
		}
		if ( ! empty( $setup['prior'] ) ) {
			$checkpoint['terminal_failure'] = array(
				'error'      => 'Original missing artifact',
				'error_code' => 'mainwp_restore_artifact_missing',
			);
		}
		$wpdb->update(
			$table,
			array(
				'status'        => $setup['status'],
				'child_task_id' => $setup['child'] ?? '',
				'checkpoint'    => wp_json_encode( (object) $checkpoint ),
				'last_error'    => '',
				'locked_by'     => $setup['token'] ?? '',
			),
			array( 'id' => 987674 )
		);
		$artifacts = array(
			array(
				'file' => 'safety.zip',
				'role' => 'full',
				'size' => 1024,
			),
		);
		$fired     = 0;
		$hook      = static function ( $sql ) use ( $wpdb, $table, $store, $race, $artifacts, &$fired ) {
			if ( ! is_array( $race ) || false === strpos( $sql, $table ) ) {
				return $sql;
			}
			$frames = array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'function' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- the hook must land inside the terminal write.
			if ( ! in_array( 'finish_with_cancel_confirmation', $frames, true ) || in_array( 'save_checkpoint', $frames, true ) || in_array( 'defer_terminal_failure', $frames, true ) ) {
				return $sql;
			}
			$at = 'read' === $race[0] ? 'SELECT checkpoint, status, locked_by' : 'UPDATE ';
			if ( 0 !== strpos( $sql, $at ) || ( $fired > 0 && 'contend' !== $race[1] ) ) {
				return $sql;
			}
			++$fired;
			switch ( $race[1] ) {
				case 'defer':
					$store->defer_terminal_failure( 987674, 'Original missing artifact', 'mainwp_restore_artifact_missing', 'worker-token' );
					break;
				case 'save':
					$current                           = $store->get_job( 987674 )->checkpoint;
					$current['pre_snapshot_artifacts'] = $artifacts;
					$store->save_checkpoint( 987674, $current, 'worker-token' );
					break;
				case 'contend':
					$current         = $store->get_job( 987674 )->checkpoint;
					$current['tick'] = $fired;
					$store->save_checkpoint( 987674, $current, 'worker-token' );
					break;
				case 'terminal':
					$wpdb->update( $table, array( 'status' => 'failed_permanent' ), array( 'id' => 987674 ) );
					break;
				case 'foreign':
					$wpdb->update( $table, array( 'locked_by' => 'other-worker' ), array( 'id' => 987674 ) );
					break;
			}
			return $sql;
		};
		$reply     = $call['reply'];
		$stub      = static function ( $value, $site, $what ) use ( $reply ) {
			if ( 'restore_task_cancel' !== $what ) {
				return $value;
			}
			return 'failed' === $reply ? array(
				'result' => 'already_terminal',
				'phase'  => 'failed',
			) : array( 'result' => $reply );
		};
		add_filter( 'query', $hook );
		add_filter( 'mainwp_fetch_url_authed_pre', $stub, 10, 3 );
		try {
			$method = new \ReflectionMethod( MainWP_Backup_Restore_Orchestrator::class, 'record_terminal_failure' );
			$method->setAccessible( true );
			$result = $method->invoke( MainWP_Backup_Restore_Orchestrator::instance(), $store->get_job( 987674 ), $call['error'], $call['code'], $call['token'] );
		} finally {
			remove_filter( 'query', $hook );
			remove_filter( 'mainwp_fetch_url_authed_pre', $stub, 10 );
		}

		if ( is_array( $race ) ) {
			$this->assertGreaterThan( 0, $fired, 'The concurrent write must land inside the terminal write.' );
		}
		$this->assertSame( $expect['result'], $result );
		$job = $store->get_job( 987674 );
		$this->assertSame( $expect['status'], $job->status );
		$this->assertSame( $expect['confirmation'], $job->checkpoint['cancel_confirmation']['outcome'] ?? null );
		if ( isset( $expect['code'] ) ) {
			$this->assertSame( $expect['code'], $job->checkpoint['last_error_code'] ?? null );
		}
		if ( ! empty( $expect['artifacts'] ) ) {
			$this->assertSame( $artifacts, $job->checkpoint['pre_snapshot_artifacts'] ?? null, 'The worker checkpoint written meanwhile must survive.' );
		}
		if ( isset( $expect['intent'] ) ) {
			$this->assertSame( $expect['intent'], ! empty( $job->checkpoint['cancel_intent'] ) );
		}
	}

	/**
	 * A pending confirmation left on a finished job is not a cancel still in flight.
	 *
	 * @return void
	 */
	public function test_pending_confirmation_on_finished_job_is_not_cancel_pending() {
		global $wpdb;
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ),
			array(
				'status'     => 'completed',
				'checkpoint' => '{"cancel_intent":true,"cancel_confirmation":{"outcome":"pending"}}',
			),
			array( 'id' => 987674 )
		);
		$this->assertNotSame( 'cancel_pending', MainWP_Backup_Operations::cancel_restore( 987674 )['result'] );
	}

	/**
	 * A call answer can be stale by the time its job is re-read.
	 *
	 * @return array
	 */
	public static function cancel_restore_result_matrix() {
		$all      = array( null, 'pending', 'confirmed', 'unconfirmed', 'not_needed' );
		$other    = array( null, 'confirmed', 'unconfirmed', 'not_needed' );
		$answers  = array( 'not called', 'cancel_pending', 'cancelled' );
		$rows     = array();
		$id       = 0;
		$statuses = array( 'pending', 'preflight', 'pre_snapshot', 'staging', 'transferring', 'running', 'failed_retryable' );
		foreach ( $statuses as $status ) {
			$rows[ 'C' . ( ++$id ) ] = array( $status, array( 'pending' ), $answers, 'any', 'cancel_pending', 200 );
			$rows[ 'C' . ( ++$id ) ] = array( $status, $other, $answers, 'changed', 'not_cancellable', 200 );
			$rows[ 'C' . ( ++$id ) ] = array( $status, $all, array( 'exception' ), 'unchanged', 'mainwp_operation_failed', 502 );
			$rows[ 'C' . ( ++$id ) ] = array( $status, array( 'pending' ), array( 'exception' ), 'changed', 'cancel_pending', 200 );
			$rows[ 'C' . ( ++$id ) ] = array( $status, $other, array( 'exception' ), 'changed', 'not_cancellable', 200 );
		}
		$answers[] = 'exception';
		foreach ( array(
			array( 'verifying', $all, 'not_cancellable' ),
			array( 'completed', $all, 'not_cancellable' ),
			array( 'cancelled', array( 'unconfirmed' ), 'cancel_unconfirmed' ),
			array( 'cancelled', array( null, 'pending', 'confirmed', 'not_needed' ), 'cancelled' ),
			array( 'failed_permanent', array( 'unconfirmed' ), 'cancel_unconfirmed' ),
			array( 'failed_permanent', array( 'confirmed' ), 'ended_failed' ),
			array( 'failed_permanent', array( null, 'pending', 'not_needed' ), 'not_cancellable' ),
		) as $terminal ) {
			$rows[ 'C' . ( ++$id ) ] = array( $terminal[0], $terminal[1], $answers, 'any', $terminal[2], 200 );
		}
		$child_statuses = array( 'staging', 'transferring', 'running', 'failed_retryable' );
		foreach ( $child_statuses as $offset => $status ) {
			$rows[ 'C' . ( 46 + $offset ) ]          = array( $status, $other, array( 'cancel_pending unwritten' ), 'unchanged', 'mainwp_operation_failed', 502 );
			$rows[ 'C' . ( 16 + 5 * $offset ) ][2][] = 'cancel_pending unwritten';
		}
		$rows['C50'] = array( $statuses, $all, array( 'exception' ), 'lease only', 'mainwp_operation_failed', 502 );
		$rows['C51'] = array( $child_statuses, $other, array( 'cancel_pending unwritten' ), 'lease only', 'mainwp_operation_failed', 502 );
		return $rows;
	}

	/**
	 * A concurrent writer can invalidate either a call answer or its exception.
	 *
	 * @dataProvider cancel_restore_result_matrix
	 * @param string|array $status        Re-read status cases.
	 * @param array        $confirmations Stored confirmation cases.
	 * @param array        $answers       Call outcome cases.
	 * @param string       $change        Whether the persisted job changed.
	 * @param string       $expected      Result enum or error code.
	 * @param int          $http          Expected HTTP status.
	 * @return void
	 */
	public function test_cancel_restore_result_matrix( $status, $confirmations, $answers, $change, $expected, $http ) {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$cases = array();
		foreach ( (array) $status as $case_status ) {
			foreach ( $confirmations as $confirmation ) {
				$cases[] = array( $case_status, $confirmation );
			}
		}
		foreach ( $cases as $case ) {
			list( $status, $confirmation ) = $case;
			foreach ( $answers as $answer ) {
				$unwritten  = 'cancel_pending unwritten' === $answer;
				$checkpoint = null === $confirmation ? array() : array( 'cancel_confirmation' => array( 'outcome' => $confirmation ) );
				if ( $unwritten && 'failed_retryable' === $status ) {
					$checkpoint['resume_status'] = 'running';
				}
				if ( $unwritten && 'pending' === $confirmation ) {
					$checkpoint['cancel_intent'] = true;
				}
				$initial = $unwritten || ( 'exception' === $answer && 'any' !== $change ) ? $status : ( 'not called' === $answer ? 'verifying' : 'running' );
				$this->assertNotFalse(
					$wpdb->update(
						$table,
						array(
							'status'        => $initial,
							'checkpoint'    => wp_json_encode( (object) $checkpoint ),
							'child_task_id' => $unwritten || 'cancel_pending' === $answer ? 'owned-restore' : '',
							'last_error'    => '',
							'locked_by'     => '',
							'locked_until'  => 1700000000,
							'updated'       => 1700000000,
						),
						array( 'id' => 987674 )
					)
				);
				$fired   = 0;
				$blocked = array(
					'defer_terminal_failure' => 0,
					'arm_cancel_intent'      => 0,
				);
				$hook    = static function ( $sql ) use ( $wpdb, $table, $status, $checkpoint, $answer, $change, $unwritten, &$fired, &$blocked ) {
					if ( $unwritten && 0 === strpos( $sql, 'UPDATE ' . $table . ' SET ' ) ) {
						$frames = array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'function' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- only cancellation-intent writes must be refused.
						foreach ( array_keys( $blocked ) as $writer ) {
							if ( in_array( $writer, $frames, true ) ) {
								++$blocked[ $writer ];
								return $sql . ' AND 1 = 0';
							}
						}
					}
					if ( $fired || 0 !== strpos( $sql, 'SELECT * FROM ' . $table . ' WHERE id = 987674' ) ) {
						return $sql;
					}
					$frames = array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'function' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- the race must occur after admission.
					if ( ! in_array( 'cancel_restore', $frames, true ) || in_array( 'authorize_restore_job', $frames, true ) ) {
						return $sql;
					}
					$in_cancel = in_array( 'cancel_job', $frames, true );
					if ( ( 'exception' === $answer || $unwritten ) !== $in_cancel ) {
						return $sql;
					}
					++$fired;
					if ( 'changed' === $change ) {
						$checkpoint['worker_tick'] = 1;
					}
					if ( 'lease only' === $change ) {
						$wpdb->update(
							$table,
							array(
								'locked_until' => 1700000060,
								'updated'      => 1700000001,
							),
							array( 'id' => 987674 )
						);
					} elseif ( ! $unwritten ) {
						$wpdb->update(
							$table,
							array(
								'status'     => $status,
								'checkpoint' => wp_json_encode( (object) $checkpoint ),
							),
							array( 'id' => 987674 )
						);
					}
					if ( 'exception' === $answer && 'any' !== $change ) {
						throw new MainWP_Exception( 'Cancellation interrupted.' );
					}
					return $sql;
				};
				$stub    = static function ( $value, $site, $what ) {
					return 'restore_task_cancel' === $what ? array( 'result' => 'busy' ) : $value;
				};
				add_filter( 'query', $hook );
				add_filter( 'mainwp_fetch_url_authed_pre', $stub, 10, 3 );
				try {
					if ( $unwritten ) {
						$store  = MainWP_Backup_Restore_Job_Store::instance();
						$before = $store->get_job( 987674 );
						$this->assertSame( 'cancel_pending', MainWP_Backup_Restore_Orchestrator::instance()->cancel_job( 987674 ), 'Refused intent writes must return a deferred cancellation.' );
						$this->assertSame( get_object_vars( $before ), get_object_vars( $store->get_job( 987674 ) ), 'The deferred answer must leave the row untouched.' );
						$this->assertGreaterThan( 0, $blocked['defer_terminal_failure'] );
						if ( 'pending' !== $confirmation ) {
							$this->assertGreaterThan( 0, $blocked['arm_cancel_intent'] );
						}
					}
					$response = MainWP_Backup_Operations::cancel_restore( 987674 );
				} finally {
					remove_filter( 'query', $hook );
					remove_filter( 'mainwp_fetch_url_authed_pre', $stub, 10 );
				}
				$case = $status . ' / ' . ( $confirmation ?? 'absent' ) . ' / ' . $answer . ' / ' . $change;
				$this->assertSame( 1, $fired, $case . ': the concurrent write must land.' );
				if ( 'lease only' === $change ) {
					$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( 987674 );
					$this->assertSame( $initial, $job->status, $case );
					$this->assertSame( $checkpoint, $job->checkpoint, $case );
					$this->assertSame( 1700000060, (int) $job->locked_until, $case );
					$this->assertSame( 1700000001, (int) $job->updated, $case );
				}
				if ( 502 === $http ) {
					$this->assertWPError( $response, $case );
					$this->assertSame( $expected, $response->get_error_code(), $case );
					$this->assertSame( $http, $response->get_error_data()['status'], $case );
				} else {
					$this->assertIsArray( $response, $case );
					$this->assertSame(
						array(
							'restore_job_id' => 987674,
							'status'         => $status,
							'result'         => $expected,
						),
						$response,
						$case
					);
					$this->assertSame( $http, rest_ensure_response( $response )->get_status(), $case );
				}
			}
		}
	}
}
