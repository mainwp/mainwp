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
	 * Sign in as an admin; AJAX permission checks need a user. The
	 * destinations table starts empty: DDL in other suites (the db
	 * upgrade tests run install()) implicitly commits its transaction,
	 * so rows can leak across tests.
	 *
	 * @return void
	 */
	public function set_up() {
		global $wpdb;
		parent::set_up();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$wpdb->query( 'DELETE FROM ' . \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ) ); // phpcs:ignore WordPress.DB -- test cleanup.
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
	 * Testing a saved destination without re-submitting settings uses the
	 * stored settings wholesale (the list row's Test button posts none).
	 *
	 * @return void
	 */
	public function test_connection_test_of_saved_destination_uses_stored_settings() {
		$base    = $this->make_temp_dir();
		$created = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'local',
				'name'     => 'Saved test',
				'settings' => array( 'base_path' => $base ),
			)
		);

		$result = MainWP_Backup_Storage_Handler::test_saved_or_submitted(
			array(
				'id'       => $created['id'],
				'provider' => 'local',
				'name'     => 'Saved test',
				'settings' => null,
			)
		);

		$this->assertTrue( $result['ok'], 'The stored base path must be used when no settings are submitted: ' . ( $result['error'] ?? '' ) );
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
	 * A GCS destination saves through the handler: the provider is
	 * allowed, the connection test runs against the pinned endpoint, and
	 * the HMAC settings persist encrypted.
	 *
	 * @return void
	 */
	public function test_save_gcs_destination_persists() {
		$urls = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$urls ) {
				$urls[] = $url;
				return array(
					'headers'  => array( 'content-length' => '22' ),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);

		$result = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'gcs',
				'name'     => 'GCS bucket',
				'settings' => array(
					'bucket'     => 'gcs-bucket',
					'access_key' => 'GOOG1KEY',
					'secret_key' => 'gcs-secret',
				),
			)
		);

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertStringStartsWith( 'https://storage.googleapis.com/gcs-bucket', $urls[0] );

		$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $result['id'] );
		$this->assertSame( 'gcs', $row->provider );
		$this->assertSame( 'gcs-secret', $row->settings['secret_key'] );
		$this->assertInstanceOf(
			\MainWP\Dashboard\MainWP_Backup_Storage_GCS::class,
			MainWP_Backup_Storage_Destinations::instance()->make_provider( $row )
		);
	}

	/**
	 * A Dropbox destination saves through the handler, and editing with
	 * blank secrets keeps the stored app secret and refresh token.
	 *
	 * @return void
	 */
	public function test_save_dropbox_destination_keeps_secrets_on_edit() {
		add_filter(
			'pre_http_request',
			function () {
				// One shape serves every call in the connection test: the
				// token mint reads access_token, the probe head reads size.
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'access_token' => 'test-token',
							'expires_in'   => 14400,
							'.tag'         => 'file',
							'size'         => 22,
						)
					),
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
				'provider' => 'dropbox',
				'name'     => 'Dropbox app',
				'settings' => array(
					'app_key'       => 'app-key',
					'app_secret'    => 'original-app-secret',
					'refresh_token' => 'original-refresh-token',
					'base_path'     => 'mainwp',
				),
			)
		);
		$this->assertArrayNotHasKey( 'error', $created );

		$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $created['id'] );
		$this->assertInstanceOf(
			\MainWP\Dashboard\MainWP_Backup_Storage_Dropbox::class,
			MainWP_Backup_Storage_Destinations::instance()->make_provider( $row )
		);

		$updated = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => $created['id'],
				'provider' => 'dropbox',
				'name'     => 'Dropbox renamed',
				'settings' => array(
					'app_key'       => 'app-key',
					'app_secret'    => '',
					'refresh_token' => '',
					'base_path'     => 'mainwp',
				),
			)
		);
		$this->assertArrayNotHasKey( 'error', $updated );

		$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $created['id'] );
		$this->assertSame( 'original-app-secret', $row->settings['app_secret'], 'A blank app secret on edit must keep the stored one.' );
		$this->assertSame( 'original-refresh-token', $row->settings['refresh_token'], 'A blank refresh token on edit must keep the stored one.' );
	}

	/**
	 * The factory builds the SFTP provider for its slug. Constructing it
	 * opens no connection, so no network is touched here; the data-call
	 * behavior is covered by the SFTP provider tests.
	 *
	 * @return void
	 */
	public function test_factory_builds_sftp_provider() {
		$provider = MainWP_Backup_Storage_Destinations::instance()->make_provider(
			(object) array(
				'provider' => 'sftp',
				'settings' => array(
					'host'      => 'sftp.example.com',
					'username'  => 'u',
					'password'  => 'p',
					'base_path' => '/backups',
				),
			)
		);

		$this->assertInstanceOf( \MainWP\Dashboard\MainWP_Backup_Storage_SFTP::class, $provider );
	}

	/**
	 * The add/edit form offers every registered provider with its own
	 * settings fields block.
	 *
	 * @return void
	 */
	public function test_provider_dropdown_offers_registered_providers() {
		ob_start();
		MainWP_Backup_Storage_View::render_form();
		$output = ob_get_clean();

		foreach ( MainWP_Backup_Storage_Handler::ALLOWED_PROVIDERS as $provider ) {
			$this->assertStringContainsString( 'value="' . $provider . '"', $output, "The provider dropdown must offer {$provider}." );
			$this->assertStringContainsString( 'data-provider="' . $provider . '"', $output, "A settings fields block must exist for {$provider}." );
		}
	}

	/**
	 * The storage AJAX actions register through the handler.
	 *
	 * @return void
	 */
	public function test_storage_ajax_actions_register() {
		MainWP_Backup_Storage_Handler::instance()->init();

		foreach ( array( 'mainwp_storage_save', 'mainwp_storage_test', 'mainwp_storage_delete', 'mainwp_storage_default', 'mainwp_storage_get' ) as $action ) {
			$this->assertNotFalse( has_action( 'wp_ajax_' . $action ), "Action {$action} must register." );
		}
	}

	/**
	 * The edit prefill returns every non-secret setting and never a
	 * secret, so the form can pre-fill without secrets reaching the
	 * browser.
	 *
	 * @return void
	 */
	public function test_destination_for_edit_strips_secrets() {
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
				'name'     => 'Edit me',
				'settings' => array(
					'endpoint'   => 'https://minio.example:9000',
					'bucket'     => 'edit-bucket',
					'region'     => 'us-east-1',
					'access_key' => 'AKIA-EDIT',
					'secret_key' => 'edit-secret-value',
					'path_style' => 1,
				),
			)
		);
		$this->assertArrayNotHasKey( 'error', $created );

		$result = MainWP_Backup_Storage_Handler::destination_for_edit( $created['id'] );

		$this->assertSame( 'Edit me', $result['name'] );
		$this->assertSame( 's3', $result['provider'] );
		$this->assertSame( 'edit-bucket', $result['settings']['bucket'] );
		$this->assertSame( 'AKIA-EDIT', $result['settings']['access_key'] );
		$this->assertSame( 'https://minio.example:9000', $result['settings']['endpoint'] );
		$this->assertArrayNotHasKey( 'secret_key', $result['settings'], 'Secrets must never reach the browser.' );

		foreach ( MainWP_Backup_Storage_Handler::SECRET_FIELDS as $field ) {
			$this->assertArrayNotHasKey( $field, $result['settings'] );
		}

		$this->assertArrayHasKey( 'error', MainWP_Backup_Storage_Handler::destination_for_edit( 999999 ) );
	}

	/**
	 * A destination whose settings no longer decrypt prefills nothing
	 * but still reports its name and provider with the credentials flag.
	 *
	 * @return void
	 */
	public function test_destination_for_edit_handles_invalid_credentials() {
		global $wpdb;

		$base    = $this->make_temp_dir();
		$created = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'local',
				'name'     => 'Broken creds',
				'settings' => array( 'base_path' => $base ),
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB -- test fixture: corrupt the stored envelope.
			MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ),
			array( 'settings' => 'garbage-not-an-envelope' ),
			array( 'id' => $created['id'] )
		);

		$result = MainWP_Backup_Storage_Handler::destination_for_edit( $created['id'] );

		$this->assertSame( 'Broken creds', $result['name'] );
		$this->assertSame( array(), $result['settings'] );
		$this->assertTrue( $result['credentials_invalid'] );
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
