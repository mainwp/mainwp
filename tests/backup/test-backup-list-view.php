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

		$this->assertStringContainsString( 'Database backup', $view->column_type( $this->make_item( array( 'type' => 'db' ) ) ) );
		$this->assertStringContainsString( 'Full backup', $view->column_type( $this->make_item( array( 'type' => 'full' ) ) ) );
	}

	/**
	 * The type column labels incremental backups.
	 *
	 * @return void
	 */
	public function test_column_type_labels_incremental() {
		$view = new MainWP_Backup_List_View();

		$this->assertStringContainsString( 'Incremental backup', $view->column_type( $this->make_item( array( 'type' => 'incremental' ) ) ) );
	}

	/**
	 * The schedule column upper-cases the schedule.
	 *
	 * @return void
	 */
	public function test_column_schedule_renders_sentence_case_label() {
		$view = new MainWP_Backup_List_View();

		$this->assertSame( 'Weekly', $view->column_schedule( $this->make_item( array( 'schedule' => 'weekly' ) ) ) );
		$this->assertSame( 'Manual', $view->column_schedule( $this->make_item( array( 'schedule' => 'manual' ) ) ) );
	}

	/**
	 * The destination column falls back to SERVER without a filter.
	 *
	 * @return void
	 */
	public function test_column_destination_falls_back_to_server() {
		$view = new MainWP_Backup_List_View();

		$this->assertSame( 'Server', $view->column_destination( $this->make_item() ) );
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

		$jobs = array(
			(object) array(
				'status'     => 'complete',
				'last_error' => '',
			),
		);

		$this->assertStringContainsString( 'Offsite Verified', $method->invoke( $view, $jobs, (object) array( 'offsite_state' => 'verified' ) ) );
		$this->assertStringContainsString( 'Uploaded', $method->invoke( $view, $jobs, (object) array( 'offsite_state' => '' ) ) );
		$this->assertStringContainsString( 'Uploaded', $method->invoke( $view, $jobs, null ) );
	}

	/**
	 * Informational run notes (like the large-site ZIP auto-switch) render as
	 * an info label in the details column, distinct from the red error label:
	 * a successful run with a note must not look like a failed one.
	 *
	 * @return void
	 */
	public function test_column_details_shows_notices_as_info_not_errors() {
		$view = new MainWP_Backup_List_View();
		$item = $this->make_item(
			array(
				'last_run_manually' => 0,
				'last_run'          => 100,
				'completed'         => 200,
				'completed_sites'   => '',
				'the_sites'         => array(),
				'backup_errors'     => '',
				'backup_notices'    => 'ZIP was switched to tar.gz for this large site (estimated 3 GB).',
			)
		);

		$output = $view->column_details( $item );

		$this->assertStringContainsString( 'Last run notes', $output, 'Notices must surface in the details column.' );
		$this->assertStringContainsString( 'switched to tar.gz', $output, 'The notice text must be available (tooltip).' );
		$this->assertStringNotContainsString( 'Last run reported errors', $output, 'A notice-only run must not show the error label.' );
		$this->assertStringNotContainsString( 'red label', $output, 'The notice label must not use the error styling.' );
	}

	/**
	 * A running row within the executor's stall threshold shows CURRENTLY
	 * RUNNING with the elapsed time, not the stale state.
	 *
	 * @return void
	 */
	public function test_column_details_shows_running_within_threshold() {
		$view = new MainWP_Backup_List_View();
		$item = $this->make_item(
			array(
				'last_run_manually' => 0,
				'last_run'          => time() - ( 10 * MINUTE_IN_SECONDS ),
				'completed'         => time() - ( 20 * MINUTE_IN_SECONDS ),
				'completed_sites'   => '',
				'the_sites'         => array( 1, 2 ),
				'backup_errors'     => '',
				'backup_notices'    => '',
			)
		);

		$output = $view->column_details( $item );

		$this->assertStringContainsString( 'Currently running', $output, 'A run inside the stall threshold must still read as running.' );
		$this->assertStringContainsString( '10m', $output, 'The elapsed time since last_run must be shown.' );
		$this->assertStringNotContainsString( 'Stale', $output, 'A run inside the threshold must not be flagged stale.' );
		$this->assertStringNotContainsString( 'mainwp-native-backup-task-reset', $output, 'The reset affordance must not appear for a non-stale run.' );
	}

	/**
	 * A running row past the executor's stall threshold (the same
	 * HOUR_IN_SECONDS-based threshold used for the per-site busy-since
	 * check) flips to a stale state showing elapsed time and a
	 * task-scoped reset affordance, instead of showing CURRENTLY RUNNING
	 * forever for a crashed scheduled run.
	 *
	 * @return void
	 */
	public function test_column_details_flags_stale_run_past_threshold() {
		$view = new MainWP_Backup_List_View();
		$item = $this->make_item(
			array(
				'id'                => 42,
				'last_run_manually' => 0,
				'last_run'          => time() - ( 3 * HOUR_IN_SECONDS + 20 * MINUTE_IN_SECONDS ),
				'completed'         => time() - ( 4 * HOUR_IN_SECONDS ),
				'completed_sites'   => '',
				'the_sites'         => array( 1, 2 ),
				'backup_errors'     => '',
				'backup_notices'    => '',
			)
		);

		$output = $view->column_details( $item );

		$this->assertStringContainsString( 'Stale', $output, 'A run past the stall threshold must be flagged stale.' );
		$this->assertStringContainsString( '3h 20m', $output, 'The elapsed time since last_run must be shown.' );
		$this->assertStringContainsString( 'May be stuck.', $output );
		$this->assertStringContainsString( 'mainwp-native-backup-task-reset', $output, 'The stale row must expose a reset affordance.' );
		$this->assertStringContainsString( 'task_id="42"', $output, 'The reset affordance must be keyed to this task id.' );
		$this->assertStringNotContainsString( 'Currently running', $output, 'A stale run must not also read as plain running.' );
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

	/**
	 * A paused task says so and does not advertise its stored next run.
	 */
	public function test_paused_task_shows_paused_and_no_next_run() {
		$view = new MainWP_Backup_List_View();
		$item = $this->make_item(
			array(
				'paused'            => 1,
				'next_run'          => time() + YEAR_IN_SECONDS,
				'last_run'          => time() - DAY_IN_SECONDS,
				'last_run_manually' => 0,
				'completed'         => time() - DAY_IN_SECONDS,
				'the_sites'         => array( 1 ),
				'backup_errors'     => '',
				'backup_notices'    => '',
			)
		);

		$this->assertStringContainsString( '<span class="ui small grey label">Paused</span>', $view->column_schedule( $item ) );
		$details = $view->column_details( $item );
		$this->assertStringContainsString( '<strong>Next run:</strong> Paused', $details );
		$this->assertStringNotContainsString( gmdate( 'Y', time() + YEAR_IN_SECONDS ), $details );
	}

	/**
	 * Run Now is a mini button in the actions cell, Resume replaces it on a
	 * paused row, and the broken loader image is gone.
	 */
	public function test_row_actions_use_mini_buttons() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$view = new MainWP_Backup_List_View();

		$running = $view->column_actions( $this->make_item() );
		$this->assertStringContainsString( 'class="ui mini button backup_run_now"', $running );
		$this->assertStringNotContainsString( 'loader.gif', $running );

		$paused = $view->column_actions( $this->make_item( array( 'paused' => 1 ) ) );
		$this->assertStringContainsString( 'class="ui mini basic button" task_id="1" onClick="return managebackups_resume(this)"', $paused );
		$this->assertStringNotContainsString( 'backup_run_now', $paused );
	}

	/**
	 * A task with no sites flags the count with a theme label, not inline red.
	 */
	public function test_zero_sites_uses_red_label() {
		$view = new MainWP_Backup_List_View();
		ob_start();
		$view->column_websites( $this->make_item( array( 'the_sites' => array() ) ) );
		$output = ob_get_clean();

		$this->assertSame( '<span class="ui small red label">0</span>', $output );
	}
}
