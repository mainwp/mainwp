<?php
/**
 * MainWP Backup Task Form Tests
 *
 * Tests for the backup task add/edit form rendering: the capability
 * gate and the task details fields.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Task_Form;

/**
 * Class MainWP_Backup_Task_Form_Test
 */
class MainWP_Backup_Task_Form_Test extends \WP_UnitTestCase {

	/**
	 * Build a minimal backup task row object for the details form.
	 *
	 * @param array $fields Field overrides.
	 *
	 * @return object Task row.
	 */
	private function make_task( array $fields = array() ) {
		return (object) array_merge(
			array(
				'id'            => 1,
				'name'          => 'Nightly',
				'filename'      => 'nightly-backup',
				'type'          => 'full',
				'schedule'      => 'daily',
				'archiveFormat' => 'site',
				'excludebackup' => 1,
				'excludecache'  => 1,
				'excludenonwp'  => 1,
				'excludezip'    => 1,
				'exclude'       => '',
			),
			$fields
		);
	}

	/**
	 * A user without the add capability gets the permission notice, not
	 * the form.
	 *
	 * @return void
	 */
	public function test_render_new_denied_without_capability() {
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING ) ) {
			$this->markTestSkipped( 'Capability checks are bypassed by design under cron, WP-CLI, and REST constants; another suite defined one for this process.' );
		}

		$deny = static function ( $allowed, $cap_type, $cap ) {
			return 'add_backup_tasks' === $cap ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );

		ob_start();
		MainWP_Backup_Task_Form::render_new();
		$output = ob_get_clean();

		remove_filter( 'mainwp_currentusercan', $deny, 10 );

		$this->assertStringContainsString( 'mainwp-permission-error', $output );
		$this->assertStringNotContainsString( 'mainwp-backup-task-form', $output );
	}

	/**
	 * The task details form renders the core fields.
	 *
	 * @return void
	 */
	public function test_render_task_details_outputs_core_fields() {
		ob_start();
		MainWP_Backup_Task_Form::render_task_details( null, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp_managebackups_add_name', $output );
		$this->assertStringContainsString( 'mainwp-backup-type', $output );
		$this->assertStringContainsString( 'mainwp-backup-task-schedule', $output );
		$this->assertStringContainsString( 'Backup Excludes', $output );
	}

	/**
	 * An existing database task pre-selects the db type and hides the
	 * full-backup excludes section.
	 *
	 * @return void
	 */
	public function test_render_task_details_preselects_db_type() {
		$task = $this->make_task( array( 'type' => 'db' ) );

		ob_start();
		MainWP_Backup_Task_Form::render_task_details( $task, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="db" selected', $output );
		$this->assertStringContainsString( 'style="display: none;"', $output );
	}

	/**
	 * The schedule form renders the details section when no task id is
	 * requested.
	 *
	 * @return void
	 */
	public function test_render_schedule_backup_outputs_details_section() {
		unset( $_GET['id'] );

		ob_start();
		MainWP_Backup_Task_Form::render_schedule_backup();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Backup Details', $output );
	}
}
