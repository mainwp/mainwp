<?php
/**
 * MainWP Backup List View Tests
 *
 * Tests for the backup task list table rendering: column content and
 * the table wrapper.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_List_View;

/**
 * Class MainWP_Backup_List_View_Test
 */
class MainWP_Backup_List_View_Test extends \WP_UnitTestCase {

	/**
	 * Build a minimal backup task row object.
	 *
	 * @param array $fields Field overrides.
	 *
	 * @return object Task row.
	 */
	private function make_item( array $fields = array() ) {
		return (object) array_merge(
			array(
				'id'       => 1,
				'name'     => 'Nightly',
				'type'     => 'full',
				'schedule' => 'daily',
				'paused'   => 0,
			),
			$fields
		);
	}

	/**
	 * The type column labels database and full backups.
	 *
	 * @return void
	 */
	public function test_column_type_labels_db_and_full() {
		$view = new MainWP_Backup_List_View();

		$this->assertStringContainsString( 'DATABASE', $view->column_type( $this->make_item( array( 'type' => 'db' ) ) ) );
		$this->assertStringContainsString( 'FULL', $view->column_type( $this->make_item( array( 'type' => 'full' ) ) ) );
	}

	/**
	 * The type column labels incremental backups.
	 *
	 * @return void
	 */
	public function test_column_type_labels_incremental() {
		$view = new MainWP_Backup_List_View();

		$this->assertStringContainsString( 'INCREMENTAL', $view->column_type( $this->make_item( array( 'type' => 'incremental' ) ) ) );
	}

	/**
	 * The schedule column upper-cases the schedule.
	 *
	 * @return void
	 */
	public function test_column_schedule_uppercases() {
		$view = new MainWP_Backup_List_View();

		$this->assertSame( 'WEEKLY', $view->column_schedule( $this->make_item( array( 'schedule' => 'weekly' ) ) ) );
	}

	/**
	 * The destination column falls back to SERVER without a filter.
	 *
	 * @return void
	 */
	public function test_column_destination_falls_back_to_server() {
		$view = new MainWP_Backup_List_View();

		$this->assertSame( 'SERVER', $view->column_destination( $this->make_item() ) );
	}

	/**
	 * The destination column returns the filter-provided destination.
	 *
	 * @return void
	 */
	public function test_column_destination_uses_filter_output() {
		$filter = static function () {
			return 'Amazon S3';
		};
		add_filter( 'mainwp_backuptask_column_destination', $filter );

		$view   = new MainWP_Backup_List_View();
		$result = $view->column_destination( $this->make_item() );

		remove_filter( 'mainwp_backuptask_column_destination', $filter );

		$this->assertSame( 'Amazon S3', $result );
	}

	/**
	 * The task name column strips slashes and escapes for HTML output, since
	 * single_row() echoes the column return value unescaped.
	 *
	 * @return void
	 */
	public function test_column_task_name_strips_slashes() {
		$view = new MainWP_Backup_List_View();

		$this->assertSame( 'Bob&#039;s backup', $view->column_task_name( $this->make_item( array( 'name' => "Bob\\'s backup" ) ) ) );
	}

	/**
	 * Display renders the backups table wrapper even with no items.
	 *
	 * @return void
	 */
	public function test_display_outputs_table_wrapper() {
		ob_start();
		( new MainWP_Backup_List_View() )->display( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-backups-table', $output );
	}

	/**
	 * The upload-state label shows Offsite Verified for a verified
	 * snapshot and the plain Uploaded label when it is not yet verified.
	 *
	 * @return void
	 */
	public function test_upload_state_label_marks_offsite_verified() {
		$view   = new MainWP_Backup_List_View();
		$method = new \ReflectionMethod( MainWP_Backup_List_View::class, 'upload_state_label' );
		$method->setAccessible( true );

		$jobs = array( (object) array( 'status' => 'complete', 'last_error' => '' ) );

		$this->assertStringContainsString( 'Offsite Verified', $method->invoke( $view, $jobs, (object) array( 'offsite_state' => 'verified' ) ) );
		$this->assertStringContainsString( 'Uploaded', $method->invoke( $view, $jobs, (object) array( 'offsite_state' => '' ) ) );
		$this->assertStringContainsString( 'Uploaded', $method->invoke( $view, $jobs, null ) );
	}

	/**
	 * The singleton accessor returns the same list view instance.
	 *
	 * @return void
	 */
	public function test_instance_returns_singleton() {
		$first  = MainWP_Backup_List_View::instance();
		$second = MainWP_Backup_List_View::instance();

		$this->assertInstanceOf( MainWP_Backup_List_View::class, $first );
		$this->assertSame( $first, $second );
	}
}
