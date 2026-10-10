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
use MainWP\Dashboard\MainWP_DB;

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
}
