<?php
/**
 * Read-only guards catch work that would make status polling mutate state.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Read_Model;
use MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;

require_once __DIR__ . '/class-mainwp-backup-read-fixtures.php';

/** Class MainWP_Backup_Read_Model_Purity_Test. */
class MainWP_Backup_Read_Model_Purity_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Fixtures;
	use Backup_Read_Purity_Guard;

	/**
	 * Fixed records make handler responses repeatable.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->seed_read_fixtures();
		$this->insert_read_fixture(
			'wp_backup_storage',
			array(
				'id'       => 987676,
				'name'     => 'Read destination',
				'provider' => 's3',
				'settings' => 'unreadable',
			)
		);
	}

	/**
	 * Fixture records and request state must not escape the test.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->clear_read_fixtures();
		parent::tear_down();
	}

	/**
	 * SQL statements the guard must treat as writes, and the function form it must let through.
	 *
	 * @return array
	 */
	public static function write_statements() {
		return array(
			'replace into'            => array( 'REPLACE INTO wp_mainwp_backup (id) VALUES (1)', true ),
			'replace without into'    => array( 'REPLACE wp_mainwp_backup SET id = 1', true ),
			'replace low priority'    => array( 'REPLACE LOW_PRIORITY INTO wp_mainwp_backup (id) VALUES (1)', true ),
			'insert'                  => array( 'INSERT INTO wp_mainwp_backup (id) VALUES (1)', true ),
			'update'                  => array( 'UPDATE wp_mainwp_backup SET paused = 1', true ),
			'replace string function' => array( "SELECT id FROM wp_mainwp_wp WHERE REPLACE(url, 'www.', '') = 'example.org'", false ),
			'replace function spaced' => array( "SELECT REPLACE (url, 'www.', '') FROM wp_mainwp_wp", false ),
			'plain select'            => array( 'SELECT * FROM wp_mainwp_backup', false ),
		);
	}

	/**
	 * Exempting REPLACE() must not exempt REPLACE statements.
	 *
	 * @dataProvider write_statements
	 *
	 * @param string $sql Statement.
	 * @param bool   $writes Whether the guard must flag it.
	 * @return void
	 */
	public function test_write_statement_pattern( $sql, $writes ) {
		$this->assertSame( $writes, (bool) preg_match( self::write_statement_pattern(), $sql ) );
	}

	/**
	 * Every public reader is guarded in active and terminal states.
	 *
	 * @return void
	 */
	public function test_every_public_reader_is_pure() {
		$this->assertTrue( class_exists( MainWP_Backup_Read_Model::class ), 'The pure read model must exist.' );
		$calls  = array(
			'get_backup_status'        => static function () {
				return MainWP_Backup_Read_Model::get_backup_status(); },
			'list_history'             => static function () {
				return MainWP_Backup_Read_Model::list_history(); },
			'list_destinations'        => static function () {
				return MainWP_Backup_Read_Model::list_destinations(); },
			'get_destination'          => static function () {
				return MainWP_Backup_Read_Model::get_destination( 987676 ); },
			'get_settings'             => static function () {
				return MainWP_Backup_Read_Model::get_settings(); },
			'get_task_run'             => function () {
				return MainWP_Backup_Read_Model::get_task_run( MainWP_DB_Backup::instance()->get_backup_task_by_id( 987671 ), 1700000000, 0 );
			},
			'get_site_backup_progress' => function () {
				return MainWP_Backup_Read_Model::get_site_backup_progress( $this->site_id, 1700000000 );
			},
			'get_restore_job'          => static function () {
				return MainWP_Backup_Read_Model::get_restore_job( 987674 );
			},
			'list_backup_snapshots'    => function () {
				return array(
					MainWP_Backup_Read_Model::list_backup_snapshots( $this->site_id ),
					MainWP_Backup_Read_Model::list_backup_snapshots( $this->site_id, false ),
				);
			},
			'get_backup_snapshot'      => static function () {
				return MainWP_Backup_Read_Model::get_backup_snapshot( 987673 );
			},
			'list_restore_jobs'        => function () {
				return MainWP_Backup_Read_Model::list_restore_jobs( $this->site_id );
			},
			'list_tasks'               => function () {
				return MainWP_Backup_Read_Model::list_tasks(); },
			'get_task'                 => function () {
				return MainWP_Backup_Read_Model::get_task( MainWP_DB_Backup::instance()->get_backup_task_by_id( 987671 ) ); },
			'task_run_details'         => function () {
				return MainWP_Backup_Read_Model::task_run_details( MainWP_DB_Backup::instance()->get_backup_task_by_id( 987671 ) ); },
			'site_backup_state'        => function () {
				return MainWP_Backup_Read_Model::site_backup_state( $this->site_id ); },
			'query_backup_snapshots'   => function () {
				return MainWP_Backup_Read_Model::query_backup_snapshots( $this->site_id ); },
			'snapshot_details'         => function () {
				return MainWP_Backup_Read_Model::snapshot_details( 987673 ); },
			'query_restore_jobs'       => function () {
				return MainWP_Backup_Read_Model::query_restore_jobs(); },
			'restore_job_details'      => function () {
				return MainWP_Backup_Read_Model::restore_job_details( 987674 ); },
		);
		$public = array_map(
			static function ( $method ) {
				return $method->name;
			},
			( new \ReflectionClass( MainWP_Backup_Read_Model::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC )
		);
		sort( $public );
		$covered = array_keys( $calls );
		sort( $covered );
		$this->assertSame( $covered, $public, 'Every public method needs a guarded call.' );
		foreach ( array( 1700000001, 1699999999 ) as $completed ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values( 987671, array( 'completed' => $completed ) );
			MainWP_DB_Backup::instance()->update_backup_task_with_values( 987672, array( 'completed' => $completed ) );
			foreach ( $calls as $call ) {
				$this->assertIsArray( $this->guarded( $call ) );
			}
		}
		global $wpdb;
		foreach ( array( 'reserved', 'pending', 'preflight', 'pre_snapshot', 'staging', 'transferring', 'running', 'verifying', 'failed_retryable', 'completed', 'failed_permanent', 'cancelled' ) as $status ) {
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'status' => $status ), array( 'id' => 987674 ) );
			$this->guarded( $calls['get_restore_job'] );
			$this->assertIsArray( $this->guarded( $calls['list_restore_jobs'] ) );
		}
		foreach ( array( 'pruned', 'complete' ) as $status ) {
			$wpdb->update(
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'status'   => $status,
					'archives' => '[{"file":"full.zip","role":"full","path":"/missing/parity.zip"}]',
				),
				array( 'id' => 987673 )
			);
			$wpdb->update(
				MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
				array(
					'status'     => 'complete',
					'checkpoint' => '{"artifacts":[{"file":"full.zip","mode":"chunked"}]}',
				),
				array( 'id' => 987675 )
			);
			$this->guarded( $calls['list_backup_snapshots'] );
			$this->guarded( $calls['get_backup_snapshot'] );
			$this->guarded(
				function () {
					return MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( $this->site_id );
				}
			);
			$this->guarded(
				function () {
					return MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( 987673, $this->site_id );
				}
			);
		}
	}
	/**
	 * Expired run evidence must remain a read even when WordPress would delete it.
	 *
	 * @return void
	 */
	public function test_expired_saved_run_read_is_pure() {
		$key = 'mainwp_backup_run_987671_1699999990';
		set_transient(
			$key,
			array(
				'task'      => MainWP_DB_Backup::instance()->get_backup_task_by_id( 987671 ),
				'progress'  => array(),
				'snapshots' => array(),
			),
			30
		);
		update_option( '_transient_timeout_' . $key, time() - 1 );
		try {
			$response = $this->guarded(
				static function () {
					return MainWP_Backup_Read_Model::get_task_run( MainWP_DB_Backup::instance()->get_backup_task_by_id( 987671 ), 1699999990 );
				}
			);
			$this->assertSame( 'run_not_found', $response['code'] );
			$this->assertNotFalse( get_option( '_transient_' . $key ), 'A read must leave expired storage intact.' );
		} finally {
			delete_transient( $key );
		}
	}

	/**
	 * Stored markup that renders empty must not change the existing run status.
	 *
	 * @return void
	 */
	public function test_empty_rendered_error_preserves_run_status() {
		$task                = MainWP_DB_Backup::instance()->get_backup_task_by_id( 987671 );
		$task->backup_errors = '<br /><b> </b>';
		$result              = $this->guarded(
			static function () use ( $task ) {
				return MainWP_Backup_Read_Model::get_task_run( $task, 1700000000 );
			}
		);
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( $task->backup_errors, $result['errors']['source_text'], 'Neutral evidence preserves the stored text.' );
	}
}
