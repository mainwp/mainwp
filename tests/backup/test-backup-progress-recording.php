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
	 * Archiving is the exception - it clears the stale processed count - so
	 * this guards a non-archiving phase; see
	 * test_archiving_phase_clears_stale_file_count.
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
			array( 'phase' => 'dumping_db' )
		);

		$row = $this->get_row();

		$this->assertSame( 'dumping_db', $row->child_phase );
		$this->assertSame( 5, (int) $row->files_processed );
	}

	/**
	 * Entering the archiving phase clears the stale processed-file count left
	 * by the database dump, so progress falls back to the byte signal instead
	 * of a misleading file ratio. The walk reports the phase before it knows
	 * any counts, so a phase-only archiving payload must zero the count.
	 *
	 * @return void
	 */
	public function test_archiving_phase_clears_stale_file_count() {
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'files_processed' => 5,
				'files_total'     => 5,
			)
		);

		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array( 'phase' => 'archiving_files' )
		);

		$row = $this->get_row();

		$this->assertSame( 'archiving_files', $row->child_phase );
		$this->assertSame( 0, (int) $row->files_processed );
	}

	/**
	 * The archiving raw byte total lands in the bytes_expected column, and a
	 * payload carrying archiving totals without a live count zeroes the
	 * processed count.
	 *
	 * @return void
	 */
	public function test_total_bytes_maps_to_bytes_expected() {
		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'phase'    => 'archiving_files',
				'progress' => array(
					'total_bytes' => 102400,
					'bytes_done'  => 1024,
					'files_total' => 50000,
				),
			)
		);

		$row = $this->get_row();

		$this->assertSame( 102400, (int) $row->bytes_expected );
		$this->assertSame( 50000, (int) $row->files_total );
		$this->assertSame( 0, (int) $row->files_processed );
	}

	/**
	 * A live scanned-file count maps onto the processed column and is not
	 * cleared, so the archiving phase can show a real climbing count.
	 *
	 * @return void
	 */
	public function test_files_scanned_maps_to_files_processed() {
		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'phase'    => 'archiving_files',
				'progress' => array(
					'files_scanned' => 1234,
					'files_total'   => 50000,
				),
			)
		);

		$row = $this->get_row();

		$this->assertSame( 1234, (int) $row->files_processed );
		$this->assertSame( 50000, (int) $row->files_total );
	}

	/**
	 * The upload artifact size still maps onto bytes_expected via bytes_total,
	 * alongside the archiving total_bytes mapping.
	 *
	 * @return void
	 */
	public function test_bytes_total_uploading_still_maps_bytes_expected() {
		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'phase'    => 'uploading',
				'progress' => array(
					'bytes_done'  => 500,
					'bytes_total' => 8192,
				),
			)
		);

		$row = $this->get_row();

		$this->assertSame( 8192, (int) $row->bytes_expected );
	}

	/**
	 * During archiving without a live count the percent is the rough
	 * compressed-versus-raw byte ratio, not the stale file ratio.
	 *
	 * @return void
	 */
	public function test_percent_archiving_uses_bytes_without_live_count() {
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'child_phase'      => 'archiving_files',
				'files_processed'  => 0,
				'files_total'      => 50000,
				'bytes_downloaded' => 5000,
				'bytes_expected'   => 100000,
			)
		);

		$response = \MainWP\Dashboard\MainWP_Post_Backup_Handler::build_task_progress_response( $this->get_row() );

		$this->assertSame( 5, $response['percent'] );
	}

	/**
	 * During archiving with a live scanned count the percent prefers the file
	 * ratio.
	 *
	 * @return void
	 */
	public function test_percent_archiving_live_count_uses_files_ratio() {
		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'phase'    => 'archiving_files',
				'progress' => array(
					'files_scanned' => 25000,
					'files_total'   => 50000,
					'bytes_done'    => 3000,
					'total_bytes'   => 100000,
				),
			)
		);

		$response = \MainWP\Dashboard\MainWP_Post_Backup_Handler::build_task_progress_response( $this->get_row() );

		$this->assertSame( 50, $response['percent'] );
	}

	/**
	 * A still-running phase never reports 100% even when the byte ratio would
	 * overshoot; completion is signalled by the terminal status.
	 *
	 * @return void
	 */
	public function test_percent_running_phase_caps_below_100() {
		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'phase'    => 'archiving_files',
				'progress' => array(
					'bytes_done'  => 120000,
					'total_bytes' => 100000,
				),
			)
		);

		$response = \MainWP\Dashboard\MainWP_Post_Backup_Handler::build_task_progress_response( $this->get_row() );

		$this->assertSame( 'running', $response['status'] );
		$this->assertSame( 99, $response['percent'] );
	}

	/**
	 * A terminal completed phase is allowed to report 100%.
	 *
	 * @return void
	 */
	public function test_percent_completed_phase_allows_100() {
		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'phase'    => 'completed',
				'progress' => array(
					'bytes_done'  => 100,
					'bytes_total' => 100,
				),
			)
		);

		$response = \MainWP\Dashboard\MainWP_Post_Backup_Handler::build_task_progress_response( $this->get_row() );

		$this->assertSame( 'completed', $response['status'] );
		$this->assertSame( 100, $response['percent'] );
	}

	/**
	 * Uploading after a database dump uses the byte ratio, so the dump's stale
	 * table count cannot misreport the upload as complete.
	 *
	 * @return void
	 */
	public function test_uploading_after_db_prefers_bytes_over_stale_file_count() {
		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'phase'    => 'dumping_db',
				'progress' => array(
					'files_processed' => 12,
					'files_total'     => 12,
				),
			)
		);

		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'phase'    => 'uploading',
				'progress' => array(
					'bytes_done'  => 6,
					'bytes_total' => 10,
				),
			)
		);

		$response = \MainWP\Dashboard\MainWP_Post_Backup_Handler::build_task_progress_response( $this->get_row() );

		$this->assertSame( 60, $response['percent'] );
	}

	/**
	 * Moving from the database dump into archiving clears the stale processed
	 * count, so the archiving percent comes from bytes, not the dump's tables.
	 *
	 * @return void
	 */
	public function test_archiving_after_db_clears_count_and_uses_bytes() {
		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'phase'    => 'dumping_db',
				'progress' => array(
					'files_processed' => 12,
					'files_total'     => 12,
				),
			)
		);

		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'phase'    => 'archiving_files',
				'progress' => array(
					'bytes_done'  => 5000,
					'total_bytes' => 100000,
				),
			)
		);

		$row = $this->get_row();

		$this->assertSame( 0, (int) $row->files_processed );
		$this->assertSame( 5, \MainWP\Dashboard\MainWP_Post_Backup_Handler::build_task_progress_response( $row )['percent'] );
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

	/**
	 * The poll response derives the label and percent from the recorded
	 * row: file counters win over bytes for the percentage.
	 *
	 * @return void
	 */
	public function test_progress_response_derives_label_and_percent() {
		MainWP_Backup_Executor::record_child_progress(
			self::TASK_ID,
			self::SITE_ID,
			array(
				'phase'    => 'dumping_db',
				'progress' => array(
					'files_processed' => 3,
					'files_total'     => 12,
					'bytes_done'      => 10,
					'bytes_total'     => 100,
				),
			)
		);

		$response = \MainWP\Dashboard\MainWP_Post_Backup_Handler::build_task_progress_response( $this->get_row() );

		$this->assertSame( 'running', $response['status'] );
		$this->assertSame( 'Exporting database', $response['label'] );
		$this->assertSame( 25, $response['percent'] );
	}

	/**
	 * A missing row reads as idle, a terminal phase as its status, and an
	 * unknown future phase stays a generic working state.
	 *
	 * @return void
	 */
	public function test_progress_response_status_classification() {
		$this->assertSame( 'idle', \MainWP\Dashboard\MainWP_Post_Backup_Handler::build_task_progress_response( null )['status'] );

		MainWP_Backup_Executor::record_child_progress( self::TASK_ID, self::SITE_ID, array( 'phase' => 'completed' ) );
		$this->assertSame( 'completed', \MainWP\Dashboard\MainWP_Post_Backup_Handler::build_task_progress_response( $this->get_row() )['status'] );

		MainWP_Backup_Executor::record_child_progress( self::TASK_ID, self::SITE_ID, array( 'phase' => 'failed' ) );
		$this->assertSame( 'failed', \MainWP\Dashboard\MainWP_Post_Backup_Handler::build_task_progress_response( $this->get_row() )['status'] );

		MainWP_Backup_Executor::record_child_progress( self::TASK_ID, self::SITE_ID, array( 'phase' => 'some_future_phase' ) );
		$response = \MainWP\Dashboard\MainWP_Post_Backup_Handler::build_task_progress_response( $this->get_row() );
		$this->assertSame( 'running', $response['status'] );
		$this->assertSame( 'Working', $response['label'] );
	}
}
