<?php
/**
 * MainWP Backup Progress Recording Tests
 *
 * Tests for mapping a child task status payload onto the granular
 * progress fields of the wp_backup_progress row.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_DB_Backup;

/**
 * Class MainWP_Backup_Progress_Recording_Test
 */
class MainWP_Backup_Progress_Recording_Test extends \WP_UnitTestCase {

	/**
	 * Fixture row ids.
	 */
	const TASK_ID = 887766;

	/**
	 * Fixture site id.
	 */
	const SITE_ID = 887766;

	/**
	 * Create the fixture progress row.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		MainWP_DB_Backup::instance()->add_backup_task_progress( self::TASK_ID, self::SITE_ID, array() );
	}

	/**
	 * Remove the fixture progress row.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;
		$table = MainWP_DB_Backup::instance()->get_table_name( 'wp_backup_progress' );
		$wpdb->delete( $table, array( 'task_id' => self::TASK_ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup.
		parent::tear_down();
	}

	/**
	 * Read the fixture row back.
	 *
	 * @return object Row.
	 */
	private function get_row() {
		return MainWP_DB_Backup::instance()->get_backup_task_progress( self::TASK_ID, self::SITE_ID );
	}

	/**
	 * A status payload with counters lands in the granular columns, and
	 * the machine phase is mirrored verbatim.
	 *
	 * @return void
	 */
	public function test_payload_counters_recorded() {
		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'phase'    => 'dumping_db',
				'progress' => array(
					'files_processed' => 3,
					'files_total'     => 12,
					'current_file'    => 'wp_posts',
					'bytes_done'      => 2048,
					'bytes_total'     => 4096,
				),
			)
		);

		$row = $this->get_row();

		$this->assertSame( 'dumping_db', $row->child_phase );
		$this->assertSame( 3, (int) $row->files_processed );
		$this->assertSame( 12, (int) $row->files_total );
		$this->assertSame( 'wp_posts', $row->current_file );
		$this->assertSame( 2048, (int) $row->bytes_downloaded );
		$this->assertSame( 4096, (int) $row->bytes_expected );
	}

	/**
	 * A payload without the optional progress key still mirrors the phase
	 * and leaves prior counters untouched (older children, early phases).
	 *
	 * @return void
	 */
	public function test_payload_without_progress_only_updates_phase() {
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			self::TASK_ID,
			self::SITE_ID,
			array( 'files_processed' => 5 )
		);

		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array( 'phase' => 'archiving_files' )
		);

		$row = $this->get_row();

		$this->assertSame( 'archiving_files', $row->child_phase );
		$this->assertSame( 5, (int) $row->files_processed );
	}

	/**
	 * A payload with neither phase nor progress writes nothing.
	 *
	 * @return void
	 */
	public function test_empty_payload_writes_nothing() {
		$before = $this->get_row();

		MainWP_Backup_Executor::record_child_progress( self::TASK_ID, self::SITE_ID, array() );

		$after = $this->get_row();

		$this->assertEquals( $before, $after );
	}
}
