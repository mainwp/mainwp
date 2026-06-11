<?php
/**
 * MainWP Backup Storage Page Tests
 *
 * Verifies the storage destinations UI layer: AJAX registration, the
 * save flow that proves a connection before persisting, secret retention
 * on edit, the delete flow that surfaces referencing tasks and prunes
 * them on confirm, default marking, the destinations page render that
 * never exposes secrets, and the task form's destination selection.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Storage_Handler;
use MainWP\Dashboard\MainWP_Backup_Storage_View;
use MainWP\Dashboard\MainWP_Backup_Task_Form;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;

/**
 * Class MainWP_Backup_Storage_Page_Test
 */
class MainWP_Backup_Storage_Page_Test extends \WP_UnitTestCase {

	/**
	 * Temp dirs created during a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	/**
	 * Sign in as an admin; AJAX permission checks need a user.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Remove temp fixtures.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->temp_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				array_map( 'unlink', glob( $dir . '/*' ) ?: array() );
				rmdir( $dir );
			}
		}
		$this->temp_dirs = array();
		parent::tear_down();
	}

	/**
	 * Create a temp dir usable as a Local destination base path.
	 *
	 * @return string
	 */
	private function make_temp_dir() {
		$dir = get_temp_dir() . 'mainwp-storage-page-' . uniqid();
		mkdir( $dir, 0755, true );
		$this->temp_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Insert a backup task row carrying destination ids.
	 *
	 * @param array $destination_ids Destination ids.
	 *
	 * @return int Task id.
	 */
	private function insert_task( array $destination_ids ) {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'userid'            => get_current_user_id(),
				'name'              => 'Storage page task',
				'schedule'          => 'daily',
				'type'              => 'full',
				'exclude'           => '',
				'sites'             => '',
				'groups'            => '',
				'last'              => 0,
				'last_run'          => 0,
				'last_run_manually' => 0,
				'completed_sites'   => '',
				'completed'         => 0,
				'backup_errors'     => '',
				'subfolder'         => '',
				'filename'          => '',
				'paused'            => 0,
				'archiveFormat'     => 'global',
				'destinations'      => wp_json_encode( array_map( 'intval', $destination_ids ) ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Saving a new Local destination proves the connection first and
	 * persists on success.
	 *
	 * @return void
	 */
	public function test_save_destination_persists_after_connection_test() {
		$base = $this->make_temp_dir();

		$result = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'local',
				'name'     => 'Local NAS',
				'settings' => array( 'base_path' => $base ),
			)
		);

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertGreaterThan( 0, $result['id'] );

		$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $result['id'] );
		$this->assertSame( 'Local NAS', $row->name );
		$this->assertSame( array( 'base_path' => $base ), $row->settings );
	}

	/**
	 * A failed connection test blocks the save.
	 *
	 * @return void
	 */
	public function test_save_destination_blocks_on_failed_connection_test() {
		$result = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'local',
				'name'     => 'Broken',
				'settings' => array( 'base_path' => '/nonexistent/' . uniqid() ),
			)
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( array(), MainWP_Backup_Storage_Destinations::instance()->list_destinations() );
	}

	/**
	 * Unknown providers and missing names are rejected.
	 *
	 * @return void
	 */
	public function test_save_destination_validates_input() {
		$bad_provider = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'gopher',
				'name'     => 'Nope',
				'settings' => array(),
			)
		);
		$this->assertArrayHasKey( 'error', $bad_provider );

		$no_name = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'local',
				'name'     => '',
				'settings' => array( 'base_path' => $this->make_temp_dir() ),
			)
		);
		$this->assertArrayHasKey( 'error', $no_name );
	}

	/**
	 * Editing with a blank secret keeps the stored secret.
	 *
	 * @return void
	 */
	public function test_edit_with_blank_secret_keeps_stored_secret() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array( 'content-length' => '22' ),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}
		);

		$created = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 's3',
				'name'     => 'Bucket',
				'settings' => array(
					'bucket'     => 'b',
					'region'     => 'us-east-1',
					'access_key' => 'AKIA-ORIGINAL',
					'secret_key' => 'original-secret',
				),
			)
		);
		$this->assertArrayNotHasKey( 'error', $created );

		$updated = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => $created['id'],
				'provider' => 's3',
				'name'     => 'Bucket renamed',
				'settings' => array(
					'bucket'     => 'b',
					'region'     => 'us-east-1',
					'access_key' => 'AKIA-ORIGINAL',
					'secret_key' => '',
				),
			)
		);
		$this->assertArrayNotHasKey( 'error', $updated );

		$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $created['id'] );
		$this->assertSame( 'Bucket renamed', $row->name );
		$this->assertSame( 'original-secret', $row->settings['secret_key'], 'A blank secret on edit must keep the stored one.' );
	}

	/**
	 * The unconfirmed delete reports referencing tasks; the confirmed
	 * delete prunes them, fails pending jobs, and removes the row.
	 *
	 * @return void
	 */
	public function test_delete_flow_handles_referencing_tasks() {
		$base    = $this->make_temp_dir();
		$created = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'local',
				'name'     => 'Referenced',
				'settings' => array( 'base_path' => $base ),
			)
		);
		$dest_id = $created['id'];
		$task_id = $this->insert_task( array( $dest_id ) );

		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( 991, array( $dest_id ) );

		$unconfirmed = MainWP_Backup_Storage_Handler::delete_destination_flow( $dest_id, false );
		$this->assertArrayHasKey( 'tasks', $unconfirmed );
		$this->assertSame( array( 'Storage page task' ), $unconfirmed['tasks'] );
		$this->assertNotNull( MainWP_Backup_Storage_Destinations::instance()->get_destination( $dest_id ), 'Unconfirmed delete must not remove the row.' );

		$confirmed = MainWP_Backup_Storage_Handler::delete_destination_flow( $dest_id, true );
		$this->assertArrayNotHasKey( 'error', $confirmed );
		$this->assertNull( MainWP_Backup_Storage_Destinations::instance()->get_destination( $dest_id ) );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
		$this->assertSame( array(), json_decode( $task->destinations, true ), 'The destination id must be pruned from tasks.' );

		$jobs = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( 991 );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $jobs[0]->status );
	}

	/**
	 * Marking a default routes through the handler.
	 *
	 * @return void
	 */
	public function test_set_default_destination() {
		$created = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'local',
				'name'     => 'Default me',
				'settings' => array( 'base_path' => $this->make_temp_dir() ),
			)
		);

		$result = MainWP_Backup_Storage_Handler::set_default_destination( $created['id'] );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( $created['id'], (int) MainWP_Backup_Storage_Destinations::instance()->get_default()->id );
	}

	/**
	 * The storage AJAX actions register through the handler.
	 *
	 * @return void
	 */
	public function test_storage_ajax_actions_register() {
		MainWP_Backup_Storage_Handler::instance()->init();

		foreach ( array( 'mainwp_storage_save', 'mainwp_storage_test', 'mainwp_storage_delete', 'mainwp_storage_default' ) as $action ) {
			$this->assertNotFalse( has_action( 'wp_ajax_' . $action ), "Action {$action} must register." );
		}
	}

	/**
	 * The destinations page lists destinations without ever printing a
	 * secret.
	 *
	 * @return void
	 */
	public function test_render_page_lists_destinations_without_secrets() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array( 'content-length' => '22' ),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}
		);

		MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 's3',
				'name'     => 'Render bucket',
				'settings' => array(
					'bucket'     => 'render-bucket',
					'region'     => 'us-east-1',
					'access_key' => 'AKIA-RENDER',
					'secret_key' => 'render-secret-value',
				),
			)
		);

		ob_start();
		MainWP_Backup_Storage_View::render_list_table();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Render bucket', $output );
		$this->assertStringContainsString( 's3', $output );
		$this->assertStringNotContainsString( 'render-secret-value', $output );
		$this->assertStringNotContainsString( 'AKIA-RENDER', $output );
	}

	/**
	 * The task form offers a destinations selection when destinations
	 * exist, preselecting the task's own.
	 *
	 * @return void
	 */
	public function test_task_form_offers_destination_selection() {
		$created = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'local',
				'name'     => 'Form choice',
				'settings' => array( 'base_path' => $this->make_temp_dir() ),
			)
		);

		$task               = (object) array(
			'name'          => 'T',
			'type'          => 'full',
			'schedule'      => 'daily',
			'filename'      => '',
			'excludebackup' => 1,
			'excludecache'  => 1,
			'excludenonwp'  => 1,
			'excludezip'    => 1,
			'exclude'       => '',
			'destinations'  => wp_json_encode( array( $created['id'] ) ),
		);

		ob_start();
		MainWP_Backup_Task_Form::render_task_details( $task, 'Tar GZip', 'site', false, true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-backup-task-destinations', $output );
		$this->assertStringContainsString( 'Form choice', $output );
		$this->assertStringContainsString( 'value="' . $created['id'] . '" selected', $output );
	}

	/**
	 * The backup list destination column shows configured destinations
	 * and the latest run's upload state; tasks without destinations keep
	 * the SERVER label.
	 *
	 * @return void
	 */
	public function test_list_destination_column_shows_upload_state() {
		$created = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'local',
				'name'     => 'Column dest',
				'settings' => array( 'base_path' => $this->make_temp_dir() ),
			)
		);
		$task_id = $this->insert_task( array( $created['id'] ) );

		$snapshot_id = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'  => $task_id,
				'site_id'  => 4,
				'type'     => 'full',
				'archives' => array(),
			)
		);
		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $created['id'] ) );
		$jobs = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id );
		MainWP_Backup_Upload_Job_Store::instance()->complete_job( (int) $jobs[0]->id );

		$task   = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
		$column = \MainWP\Dashboard\MainWP_Backup_List_View::instance()->column_destination( $task );

		$this->assertStringContainsString( 'Column dest', $column );
		$this->assertStringContainsString( 'Uploaded', $column );

		$plain  = $this->insert_task( array() );
		$column = \MainWP\Dashboard\MainWP_Backup_List_View::instance()->column_destination( MainWP_DB_Backup::instance()->get_backup_task_by_id( $plain ) );
		$this->assertStringContainsString( 'SERVER', $column );
	}

	/**
	 * Task destination saves sanitize to existing destination ids.
	 *
	 * @return void
	 */
	public function test_save_task_destinations_round_trip() {
		$created = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'local',
				'name'     => 'Persisted',
				'settings' => array( 'base_path' => $this->make_temp_dir() ),
			)
		);
		$task_id = $this->insert_task( array() );

		MainWP_Backup_Storage_Handler::save_task_destinations( $task_id, array( (string) $created['id'], '999999', 'junk' ) );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
		$this->assertSame( array( $created['id'] ), json_decode( $task->destinations, true ), 'Only existing destination ids may persist.' );
	}
}
