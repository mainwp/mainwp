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
use MainWP\Dashboard\MainWP_DB_Backup;

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
	 * The archive-type field guides large sites toward tar.gz, which resumes
	 * across timeouts where ZIP restarts from the beginning.
	 *
	 * @return void
	 */
	public function test_render_task_details_outputs_archive_format_note() {
		ob_start();
		MainWP_Backup_Task_Form::render_task_details( null, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-archive-format-note', $output );
		$this->assertStringContainsString( 'resumes across timeouts', $output );
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
	 * The type dropdown offers the incremental type with an explanation of
	 * how chains behave.
	 *
	 * @return void
	 */
	public function test_render_task_details_offers_incremental_type() {
		ob_start();
		MainWP_Backup_Task_Form::render_task_details( null, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="incremental"', $output );
		$this->assertStringContainsString( 'mainwp-backup-incremental-note', $output );
	}

	/**
	 * An existing incremental task pre-selects the type, keeps the
	 * excludes section visible (the file walk honors them), and shows the
	 * chain note.
	 *
	 * @return void
	 */
	public function test_render_task_details_preselects_incremental_type() {
		$task = $this->make_task( array( 'type' => 'incremental' ) );

		ob_start();
		MainWP_Backup_Task_Form::render_task_details( $task, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="incremental" selected', $output );
		$this->assertStringNotContainsString( 'style="display: none;"', $output );
	}

	/**
	 * The type dropdown offers the files-only type with a note that the
	 * archive carries no database.
	 *
	 * @return void
	 */
	public function test_render_task_details_offers_files_type() {
		ob_start();
		MainWP_Backup_Task_Form::render_task_details( null, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="files"', $output );
		$this->assertStringContainsString( 'mainwp-backup-files-note', $output );
	}

	/**
	 * An existing files task pre-selects the type and keeps the excludes
	 * section visible (the file walk honors them).
	 *
	 * @return void
	 */
	public function test_render_task_details_preselects_files_type() {
		$task = $this->make_task( array( 'type' => 'files' ) );

		ob_start();
		MainWP_Backup_Task_Form::render_task_details( $task, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="files" selected', $output );
		$this->assertStringNotContainsString( 'style="display: none;"', $output );
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

	/**
	 * The schedule dropdown offers the every-N-hours interval mode and the
	 * wall-clock sub-fields.
	 *
	 * @return void
	 */
	public function test_render_task_details_offers_schedule_fields() {
		ob_start();
		MainWP_Backup_Task_Form::render_task_details( null, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="interval"', $output );
		$this->assertStringContainsString( 'mainwp-schedule-interval-hours', $output );
		$this->assertStringContainsString( 'mainwp-schedule-time-of-day', $output );
		$this->assertStringContainsString( 'mainwp-schedule-day-of-week', $output );
		$this->assertStringContainsString( 'mainwp-schedule-day-of-month', $output );
	}

	/**
	 * An existing interval task pre-selects the mode and its hours.
	 *
	 * @return void
	 */
	public function test_render_task_details_preselects_interval_schedule() {
		$task = $this->make_task(
			array(
				'schedule'       => 'interval',
				'interval_hours' => 6,
			)
		);

		ob_start();
		MainWP_Backup_Task_Form::render_task_details( $task, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="interval" selected', $output );
		$this->assertStringContainsString( 'value="6"', $output );
	}

	/**
	 * An existing weekly task with a time of day renders its wall-clock
	 * values.
	 *
	 * @return void
	 */
	public function test_render_task_details_preselects_weekly_time_of_day() {
		$task = $this->make_task(
			array(
				'schedule'    => 'weekly',
				'time_of_day' => '06:30',
				'day_of_week' => 3,
			)
		);

		ob_start();
		MainWP_Backup_Task_Form::render_task_details( $task, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="weekly" selected', $output );
		$this->assertStringContainsString( 'value="06:30"', $output );
		$this->assertStringContainsString( 'value="3" selected', $output );
	}

	/**
	 * The retention section renders with no-limit defaults and the cloud
	 * copies toggle on.
	 *
	 * @return void
	 */
	public function test_render_retention_fields_defaults() {
		ob_start();
		MainWP_Backup_Task_Form::render_task_details( null, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-backup-retention-count', $output );
		$this->assertStringContainsString( 'mainwp-backup-retention-days', $output );
		$this->assertStringContainsString( 'mainwp-backup-retention-storage-mb', $output );
		$this->assertStringContainsString( 'id="mainwp-backup-delete-cloud-copies" checked', $output );
		$this->assertStringContainsString( 'No limit', $output );
	}

	/**
	 * The chain-length limit renders for an incremental task with its
	 * saved value, and stays hidden for other types (the JS toggle shows
	 * it when the type changes).
	 *
	 * @return void
	 */
	public function test_render_max_chain_length_for_incremental_task() {
		$task = $this->make_task(
			array(
				'type'             => 'incremental',
				'max_chain_length' => 12,
			)
		);

		ob_start();
		MainWP_Backup_Task_Form::render_task_details( $task, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-backup-max-chain-length', $output );
		$this->assertStringContainsString( 'value="12"', $output );
		$this->assertMatchesRegularExpression(
			'/id="mainwp-backup-max-chain-length-wrapper"\s*>/',
			$output,
			'The chain-length field is visible for an incremental task.'
		);
	}

	/**
	 * A non-incremental task hides the chain-length field; the limit only
	 * applies to incremental chains.
	 *
	 * @return void
	 */
	public function test_render_max_chain_length_hidden_for_full_task() {
		ob_start();
		MainWP_Backup_Task_Form::render_task_details( $this->make_task(), 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-backup-max-chain-length', $output );
		$this->assertMatchesRegularExpression(
			'/id="mainwp-backup-max-chain-length-wrapper"\s+style="display:none"/',
			$output,
			'The chain-length field stays hidden for non-incremental tasks.'
		);
	}

	/**
	 * A populated retention policy renders its values, and a disabled
	 * cloud copies toggle stays off.
	 *
	 * @return void
	 */
	public function test_render_retention_fields_populated() {
		$task = $this->make_task(
			array(
				'retention_count'      => 5,
				'retention_days'       => 90,
				'retention_storage_mb' => 500,
				'delete_cloud_copies'  => 0,
			)
		);

		ob_start();
		MainWP_Backup_Task_Form::render_task_details( $task, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="5"', $output );
		$this->assertStringContainsString( 'value="90"', $output );
		$this->assertStringContainsString( 'value="500"', $output );
		$this->assertStringNotContainsString( 'id="mainwp-backup-delete-cloud-copies" checked', $output );
	}

	/**
	 * A Zip task shows the keep-Zip override field so the user can opt out of
	 * the large-site auto-switch to tar.gz.
	 *
	 * @return void
	 */
	public function test_render_task_details_shows_zip_override_for_zip_format() {
		ob_start();
		MainWP_Backup_Task_Form::render_task_details( $this->make_task( array( 'archiveFormat' => 'zip' ) ), 'Tar GZip', 'zip', false, false );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-backup-zip-force-override', $output );
		$this->assertMatchesRegularExpression(
			'/id="mainwp-backup-zip-force-override-wrapper"\s*>/',
			$output,
			'The override field is visible for a Zip task.'
		);
	}

	/**
	 * A non-Zip task keeps the override field hidden; the JS toggle reveals it
	 * when the format is switched to Zip.
	 *
	 * @return void
	 */
	public function test_render_task_details_hides_zip_override_for_non_zip_format() {
		ob_start();
		MainWP_Backup_Task_Form::render_task_details( null, 'Tar GZip', 'tar.gz', false, false );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-backup-zip-force-override', $output );
		$this->assertMatchesRegularExpression(
			'/id="mainwp-backup-zip-force-override-wrapper"\s+style="display:none"/',
			$output,
			'The override field stays hidden when the format is not Zip.'
		);
	}

	/**
	 * A task that already forces Zip renders the override checkbox checked.
	 *
	 * @return void
	 */
	public function test_render_task_details_checks_override_when_task_forces_zip() {
		$task = $this->make_task(
			array(
				'archiveFormat'      => 'zip',
				'zip_force_override' => 1,
			)
		);

		ob_start();
		MainWP_Backup_Task_Form::render_task_details( $task, 'Tar GZip', 'zip', false, false );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/id="mainwp-backup-zip-force-override"[^>]*checked/',
			$output,
			'A task with zip_force_override set renders the checkbox checked.'
		);
	}

	/**
	 * The override flag survives an add then load round-trip through the task
	 * table, and update_backup_task can clear it back to 0.
	 *
	 * @return void
	 */
	public function test_zip_force_override_add_and_update_round_trip() {
		$db   = MainWP_DB_Backup::instance();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$added = $db->add_backup_task(
			$user,
			'Round trip',
			'daily',
			'full',
			'',
			'',
			'',
			'',
			'rt-backup',
			0,
			0,
			0,
			0,
			0,
			'zip',
			0,
			1,
			150,
			0,
			1 // zip_force_override.
		);
		$this->assertNotFalse( $added );
		$this->assertSame( 1, (int) $db->get_backup_task_by_id( $added->id )->zip_force_override );

		$db->update_backup_task(
			$added->id,
			$user,
			'Round trip',
			'daily',
			'full',
			'',
			'',
			'',
			'',
			'rt-backup',
			0,
			0,
			0,
			0,
			'zip',
			0,
			1,
			150,
			0,
			0 // zip_force_override cleared.
		);
		$this->assertSame( 0, (int) $db->get_backup_task_by_id( $added->id )->zip_force_override );
	}
}
