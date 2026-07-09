<?php
/**
 * MainWP Backup Task Delete Cleanup Tests
 *
 * Deleting a wp_backup task must not orphan its per-site progress rows
 * or its diagnostics event files: the progress rows go with the task row
 * in remove_backup_task(), and the recorder purges the task's JSONL
 * files off the mainwp_backup_task_snapshots_purged action both delete
 * paths already fire - even when diagnostics recording is switched off,
 * because files from a previously-enabled period still need to leave
 * with the task.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Diagnostics;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;

/**
 * Class MainWP_Backup_Task_Delete_Cleanup_Test
 */
class MainWP_Backup_Task_Delete_Cleanup_Test extends \WP_UnitTestCase {

	/**
	 * Scratch diagnostics directory for the test.
	 *
	 * @var string
	 */
	private $diag_dir;

	/**
	 * Set up: point the recorder at a scratch directory.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->diag_dir = sys_get_temp_dir() . '/mainwp-diag-cleanup-test-' . uniqid();
		mkdir( $this->diag_dir );
		add_filter( 'mainwp_backup_diagnostics_dir', array( $this, 'filter_diag_dir' ) );
	}

	/**
	 * Clean up fixture rows, scratch files, and the diagnostics option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		$backup_table   = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$progress_table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		$wpdb->query( "DELETE FROM {$backup_table} WHERE name LIKE 'Delete cleanup test%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		$wpdb->query( "DELETE FROM {$progress_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		delete_option( MainWP_Backup_Diagnostics::OPTION_ENABLED );
		remove_filter( 'mainwp_backup_diagnostics_dir', array( $this, 'filter_diag_dir' ) );

		if ( is_dir( $this->diag_dir ) ) {
			foreach ( (array) glob( $this->diag_dir . '/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $this->diag_dir );
		}

		parent::tearDown();
	}

	/**
	 * Point the recorder at the scratch directory.
	 *
	 * @return string Scratch dir.
	 */
	public function filter_diag_dir() {
		return $this->diag_dir;
	}

	/**
	 * Insert a scheduled task row targeting some sites.
	 *
	 * @param string $sites Comma-separated site ids.
	 *
	 * @return int Task id.
	 */
	private function insert_task( $sites ) {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'userid'          => 1,
				'name'            => 'Delete cleanup test ' . uniqid(),
				'schedule'        => 'daily',
				'type'            => 'full',
				'exclude'         => '',
				'sites'           => $sites,
				'groups'          => '',
				'subfolder'       => '',
				'filename'        => '',
				'completed_sites' => '',
				'backup_errors'   => '',
				'archiveFormat'   => 'site',
				'template'        => 0,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Count progress rows for a task.
	 *
	 * @param int $task_id Task id.
	 *
	 * @return int Row count.
	 */
	private function count_progress_rows( $task_id ) {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE task_id = %d", $task_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test assertion, identifiers cannot be prepared.
	}

	/**
	 * remove_backup_task() must delete the task's progress rows with the
	 * task row, and only its own: another task's rows stay.
	 *
	 * @return void
	 */
	public function test_remove_backup_task_deletes_its_progress_rows() {
		$task_a = $this->insert_task( '9001,9002' );
		$task_b = $this->insert_task( '9001' );

		MainWP_DB_Backup::instance()->add_backup_task_progress( $task_a, 9001, array() );
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task_a, 9002, array() );
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task_b, 9001, array() );

		$this->assertSame( 2, $this->count_progress_rows( $task_a ) );
		$this->assertSame( 1, $this->count_progress_rows( $task_b ) );

		MainWP_DB_Backup::instance()->remove_backup_task( $task_a );

		$this->assertSame( 0, $this->count_progress_rows( $task_a ), 'Deleting a task must delete its progress rows.' );
		$this->assertSame( 1, $this->count_progress_rows( $task_b ), 'Another task\'s progress rows must survive.' );
	}

	/**
	 * The snapshots-purged action must remove the deleted task's
	 * diagnostics JSONL files and nothing else - including files from a
	 * task id that merely shares a prefix (task 1 vs task 10).
	 *
	 * @return void
	 */
	public function test_purge_task_files_removes_only_the_tasks_diagnostics() {
		update_option( MainWP_Backup_Diagnostics::OPTION_ENABLED, '0' );
		MainWP_Backup_Diagnostics::instance()->init();

		$dir = trailingslashit( $this->diag_dir );
		file_put_contents( $dir . '7-101.jsonl', "{}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		file_put_contents( $dir . '7-102-child.jsonl', "{}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		file_put_contents( $dir . '70-101.jsonl', "{}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		file_put_contents( $dir . 'terminal-101.jsonl', "{}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		do_action( 'mainwp_backup_task_snapshots_purged', 7, array() );

		$this->assertFileDoesNotExist( $dir . '7-101.jsonl', 'The deleted task\'s diagnostics files must be purged.' );
		$this->assertFileDoesNotExist( $dir . '7-102-child.jsonl', 'All of the deleted task\'s run files must be purged.' );
		$this->assertFileExists( $dir . '70-101.jsonl', 'A task id sharing a prefix must keep its files.' );
		$this->assertFileExists( $dir . 'terminal-101.jsonl', 'Non-task-keyed files must survive.' );
	}

	/**
	 * The purge hook must be registered even while diagnostics recording
	 * is off: cleanup is not recording.
	 *
	 * @return void
	 */
	public function test_purge_hook_registered_when_diagnostics_disabled() {
		update_option( MainWP_Backup_Diagnostics::OPTION_ENABLED, '0' );
		MainWP_Backup_Diagnostics::instance()->init();

		$this->assertNotFalse(
			has_action( 'mainwp_backup_task_snapshots_purged', array( MainWP_Backup_Diagnostics::instance(), 'purge_task_files' ) ),
			'The task-file purge must not depend on the recording switch.'
		);
	}
}
