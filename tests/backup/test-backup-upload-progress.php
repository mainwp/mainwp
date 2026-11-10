<?php
/**
 * Upload progress reads confirmed bytes from stored checkpoints.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Read_Model;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;

require_once __DIR__ . '/class-mainwp-backup-read-fixtures.php';

/** Upload progress regression tests. */
class MainWP_Backup_Upload_Progress_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Fixtures;

	/** Seed the completed task and its upload. */
	public function set_up() {
		parent::set_up();
		$this->seed_read_fixtures();
	}

	/** Remove fixture rows. */
	public function tear_down() {
		$this->clear_read_fixtures();
		parent::tear_down();
	}

	/**
	 * Confirmed chunks and completed artifacts are mutually exclusive.
	 *
	 * @dataProvider checkpoints
	 * @param string $status Job status.
	 * @param array  $artifacts Artifact checkpoints.
	 * @param int    $done Confirmed bytes.
	 * @param string $state UI state.
	 */
	public function test_upload_progress_bytes_and_state( $status, $artifacts, $done, $state ) {
		global $wpdb;
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array(
				'status'        => $status,
				'checkpoint'    => wp_json_encode( array( 'artifacts' => $artifacts ) ),
				'next_retry_at' => 1700000300,
			),
			array( 'id' => 987675 )
		);
		$run      = MainWP_Backup_Read_Model::get_task_run( MainWP_DB_Backup::instance()->get_backup_task_by_id( 987671 ), 1700000000 );
		$progress = $run['sites'][0]['upload_progress'][0];
		$this->assertSame( $done, $progress['bytes_done'] );
		$this->assertSame( 100, $progress['bytes_total'] );
		$this->assertSame( $state, $progress['state'] );
		$this->assertSame( 1700000300, $progress['next_retry_at'] );
		$this->assertLessThanOrEqual( $progress['bytes_total'], $progress['bytes_done'] );
		$this->assertSame( 'failed' === $state ? 1 : 0, $run['sites'][0]['uploads']['failed'] );
		$this->assertSame( in_array( $state, array( 'done', 'failed' ), true ) ? 0 : 1, $run['sites'][0]['uploads']['pending'] );
		$entry = MainWP_Backup_Read_Model::get_backup_snapshot( 987673 );
		$this->assertSame( $progress, $entry['uploads'][0]->upload_progress );
	}

	/** Stored whole-file and chunked checkpoints. */
	public static function checkpoints() {
		$chunked         = array(
			array(
				'size'   => 100,
				'chunks' => array(
					array(
						'length'         => 40,
						'remote_present' => true,
					),
					array(
						'length'         => 60,
						'remote_present' => false,
					),
				),
			),
		);
		$done            = $chunked;
		$done[0]['done'] = true;
		return array(
			'chunked'                   => array( 'uploading', $chunked, 40, 'uploading' ),
			'whole pending'             => array(
				'uploading',
				array(
					array(
						'size' => 100,
						'done' => false,
					),
				),
				0,
				'uploading',
			),
			'whole done'                => array(
				'uploading',
				array(
					array(
						'size' => 100,
						'done' => true,
					),
				),
				100,
				'uploading',
			),
			'retrying'                  => array( 'failed_retryable', $chunked, 40, 'retrying' ),
			'done with retained chunks' => array( 'complete', $done, 100, 'done' ),
			'verifying'                 => array( 'submitting', $done, 100, 'verifying' ),
			'waiting'                   => array( 'pending', $chunked, 40, 'waiting' ),
			'failed'                    => array( 'failed_permanent', $chunked, 40, 'failed' ),
			'clamped'                   => array(
				'uploading',
				array(
					array(
						'size'   => 100,
						'chunks' => array(
							array(
								'length'         => 120,
								'remote_present' => true,
							),
						),
					),
				),
				100,
				'uploading',
			),
		);
	}
}
