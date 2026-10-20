<?php
/**
 * Source text must never become an agent-facing error message.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Read_Model;
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

require_once __DIR__ . '/class-mainwp-backup-read-fixtures.php';

/** Class MainWP_Backup_Safe_Error_Test. */
class MainWP_Backup_Safe_Error_Test extends MainWP_Abilities_Test_Case {

	use Backup_Cancel_Transport_Guard;
	use Backup_Read_Fixtures;

	/**
	 * Stored sources exercise the same evidence that serializers receive.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->seed_read_fixtures();
	}

	/**
	 * Seeded private text must not escape through another test's state.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->clear_read_fixtures();
		parent::tear_down();
	}

	/**
	 * Safe errors must exclude embedded secrets as well as secret properties.
	 *
	 * @return array Sensitive source strings shared with serializer tests.
	 */
	public static function sensitive_sources() {
		return array(
			'root'         => array( ABSPATH ),
			'private-root' => array( is_wp_error( MainWP_Backup_Private_Root::resolve() ) ? '/private/mainwp-backups-fixture/' : MainWP_Backup_Private_Root::resolve() ),
			'windows'      => array( 'C:\\private\\backups\\secret.zip' ),
			'sha'          => array( 'SHA256:fixture-fingerprint' ),
			'md5'          => array( 'MD5:fixture-fingerprint' ),
			'download'     => array( admin_url( 'admin.php?sig=secret-signature&mwpdl=secret-download' ) ),
			'presigned'    => array( 'https://objects.example.org/backup?X-Amz-Signature=secret-signature' ),
			'token'        => array( 'sl.fixture-dropbox-token' ),
		);
	}

	/**
	 * Untrusted text cannot select or extend a registered message.
	 *
	 * @dataProvider sensitive_sources
	 * @param string $secret Injected source value.
	 * @return void
	 */
	public function test_only_registered_codes_select_messages( $secret ) {
		$this->assertTrue( class_exists( MainWP_Backup_Operations::class ), 'The safe-error formatter must exist.' );
		foreach ( array( $secret, 'mainwp_unknown_secret_' . $secret, '[mainwp_backup_disk_full] Failed at ' . $secret ) as $input ) {
			$error = MainWP_Backup_Operations::safe_error( $input );
			$this->assertSame( 'mainwp_backup_unclassified_error', $error['code'] );
			$this->assertStringNotContainsString( substr( wp_json_encode( $secret, JSON_UNESCAPED_SLASHES ), 1, -1 ), wp_json_encode( $error, JSON_UNESCAPED_SLASHES ) );
		}
		$known = MainWP_Backup_Operations::safe_error( MainWP_Backup_Error_Codes::ERR_DISK_FULL );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $known['code'] );
		$this->assertSame( wp_specialchars_decode( MainWP_Backup_Error_Codes::message( $known['code'] ), ENT_QUOTES ), $known['message'] );
	}
	/**
	 * Agent adapters will select codes from these neutral stored sources.
	 *
	 * @dataProvider sensitive_sources
	 * @param string $secret Sensitive value embedded in an ordinary sentence.
	 * @return void
	 */
	public function test_stored_sources_keep_text_separate_from_safe_errors( $secret ) {
		global $wpdb;
		$text   = 'Could not finish because ' . $secret . ' was unavailable.';
		$source = '[mainwp_backup_disk_full] ' . $text;
		foreach ( array( 987671, 987672 ) as $id ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				$id,
				array(
					'backup_errors'  => $source,
					'backup_notices' => $text,
				)
			);
		}
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ), array( 'last_error' => $source ), array( 'task_id' => 987671 ) );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'status' => 'pruned' ), array( 'id' => 987673 ) );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'last_error' => $source ), array( 'id' => 987675 ) );
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ),
			array(
				'last_error' => $text,
				'checkpoint' => wp_json_encode(
					array(
						'last_error_code'  => 'mainwp_restore_disk_full',
						'last_error'       => $text,
						'terminal_failure' => array( 'error' => $text ),
					)
				),
			),
			array( 'id' => 987674 )
		);
		$task     = MainWP_Backup_Read_Model::get_task_run( MainWP_DB_Backup::instance()->get_backup_task_by_id( 987671 ), 1700000000 );
		$site     = MainWP_Backup_Read_Model::get_site_backup_progress( $this->site_id, 1700000000 );
		$job      = MainWP_Backup_Read_Model::get_restore_job( 987674 );
		$snapshot = MainWP_Backup_Read_Model::get_backup_snapshot( 987673 );
		$errors   = array( $task['errors'], $task['notices'], $task['sites'][0]['error'], $site['error'], $job['last_error'], $snapshot['uploads'][0]->last_error );
		foreach ( $errors as $error ) {
			$this->assertIsArray( $error, 'Stored errors need both code and source text.' );
			$this->assertStringContainsString( $secret, $error['source_text'] );
			$safe       = MainWP_Backup_Operations::safe_error( $error['code'] );
			$wire_error = new \WP_Error( $safe['code'], $safe['message'], array( 'status' => 502 ) );
			$this->assertStringNotContainsString(
				substr( wp_json_encode( $secret, JSON_UNESCAPED_SLASHES ), 1, -1 ),
				wp_json_encode(
					array(
						'output'        => $safe,
						'error_message' => $wire_error->get_error_message(),
						'error_data'    => $wire_error->get_error_data(),
					),
					JSON_UNESCAPED_SLASHES
				)
			);
		}
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'status'   => 'complete',
				'archives' => wp_json_encode(
					array(
						array(
							'file' => $text,
							'role' => 'full',
							'path' => '/missing/safe-error-artifact',
						),
					)
				),
			),
			array( 'id' => 987673 )
		);
		$refusal = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( 987673, $this->site_id );
		$this->assertStringContainsString( $secret, $refusal['error'] );
		$child = array( 'error' => $text );
		$this->assertStringContainsString( $secret, $child['error'] );
		$exception = new MainWP_Exception( $text, null, MainWP_Backup_Error_Codes::ERR_DISK_FULL );
		foreach ( array( $refusal['code'], 'mainwp_operation_failed', $exception->get_message_error_code() ) as $code ) {
			$this->assertStringNotContainsString( substr( wp_json_encode( $secret, JSON_UNESCAPED_SLASHES ), 1, -1 ), wp_json_encode( MainWP_Backup_Operations::safe_error( $code ), JSON_UNESCAPED_SLASHES ) );
		}
	}
	/**
	 * Child failures need neutral evidence so transports can choose their text.
	 *
	 * @dataProvider sensitive_sources
	 * @param string $secret Sensitive value embedded in the Child answer.
	 * @return void
	 */
	public function test_child_failure_has_neutral_error_evidence( $secret ) {
		MainWP_DB_Backup::instance()->update_backup_task_with_values( 987672, array( 'completed' => 1699999999 ) );
		$child = static function ( $value, $website, $what ) use ( $secret ) {
			return 'backup_task_cancel' === $what ? array( 'error' => 'Could not finish because ' . $secret . ' was unavailable.' ) : $value;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $child, 10, 3 );
		try {
			$result = MainWP_Backup_Operations::cancel_site_backup( $this->site_id );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_operation_failed', $result->get_error_code() );
			$this->assertSame( MainWP_Backup_Operations::safe_error( 'mainwp_operation_failed' )['message'], $result->get_error_message() );
			$this->assertStringNotContainsString( substr( wp_json_encode( $secret, JSON_UNESCAPED_SLASHES ), 1, -1 ), wp_json_encode( MainWP_Backup_Operations::safe_error( $result->get_error_code() ), JSON_UNESCAPED_SLASHES ) );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $child, 10 );
		}
	}
	/**
	 * A known driver failure must keep its code when only the progress row survives.
	 *
	 * @return void
	 */
	public function test_progress_error_retains_driver_code() {
		global $wpdb;
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'id' => 987673 ) );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			987671,
			array(
				'last_run'  => time(),
				'completed' => time() - 1,
			)
		);
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			987671,
			$this->site_id,
			array(
				'child_phase' => 'archiving_files',
				'dtsFetched'  => time(),
				'last_error'  => '',
			)
		);
		$called = false;
		$child  = static function ( $value, $website, $what ) use ( &$called ) {
			if ( 'backup_task_cancel' === $what ) {
				return array( 'result' => 'ok' );
			}
			$called = true;
			return array(
				'task_id'    => 'parity-child',
				'phase'      => 'failed',
				'error'      => 'The private volume is full.',
				'error_code' => MainWP_Backup_Error_Codes::ERR_DISK_FULL,
			);
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $child, 10, 3 );
		try {
			MainWP_Manage_Backups_Handler::execute_backup_task( MainWP_DB_Backup::instance()->get_backup_task_by_id( 987671 ), 1, false );
			$this->assertTrue( $called, 'Precondition: the driver reached the failing Child request.' );
			$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( 987671, $this->site_id );
			$this->assertSame( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_DISK_FULL ), $progress->last_error, 'The UI must retain the registry message without a storage prefix.' );
			$this->assertSame( 'mainwp_backup_disk_full', $progress->fetchResult['error_code'] );
			$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( 987671 );
			$run  = MainWP_Backup_Read_Model::get_task_run( $task, (int) $task->last_run );
			$this->assertSame( 'mainwp_backup_disk_full', $run['sites'][0]['error']['code'] );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $child, 10 );
		}
	}

	/**
	 * Run state stored in the progress row's error column is not error text; other columns keep theirs.
	 *
	 * @return array Stored text, expected progress-column text.
	 */
	public function run_state_error_cases() {
		return array(
			'reconcile run state' => array( '{"last_progress_at":"2026-10-12 12:00:00","reconcile_pending":{"phase":"dumping_db"}}', '' ),
			'incremental marker'  => array( '{"incremental_sent":"run"}', '' ),
			'plain message'       => array( 'The private volume is full.', 'The private volume is full.' ),
			'prefixed code'       => array( '[mainwp_backup_disk_full] Full.', '[mainwp_backup_disk_full] Full.' ),
			'empty'               => array( '', '' ),
		);
	}

	/**
	 * Only the progress row's column drops run-state JSON; the shared error value keeps any text.
	 *
	 * @dataProvider run_state_error_cases
	 * @param string $stored Stored text.
	 * @param string $progress_text Expected text from the progress-row column.
	 */
	public function test_run_state_json_is_not_error_text( $stored, $progress_text ) {
		$model    = new \ReflectionClass( MainWP_Backup_Read_Model::class );
		$progress = $model->getMethod( 'progress_error_text' );
		$value    = $model->getMethod( 'error_value' );
		$progress->setAccessible( true );
		$value->setAccessible( true );
		$this->assertSame( $progress_text, $progress->invoke( null, $stored ) );
		$this->assertSame( $stored, $value->invoke( null, $stored )['source_text'], 'Restore and task errors keep their stored text.' );
	}
}
