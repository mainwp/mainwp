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
	 * Ordinary edits must not make the next connection trust an unknown key.
	 *
	 * @dataProvider b13_unchanged_sftp_settings
	 * @param array $changes Submitted settings changes.
	 * @return void
	 */
	public function test_b13_save_renamed_sftp_destination_preserves_pin( array $changes ) {
		$this->assert_b13_sftp_save( $changes, false );
	}

	/**
	 * These edits still connect to the trusted server with the saved credentials.
	 *
	 * @return array
	 */
	public static function b13_unchanged_sftp_settings() {
		return array(
			'name only'          => array( array() ),
			'blank secrets'      => array(
				array(
					'password'    => '',
					'private_key' => '',
				),
			),
			'normalized server'  => array(
				array(
					'host' => 'SFTP.Example.com.',
					'port' => '022',
				),
			),
			'default port'       => array( array( 'port' => '' ) ),
			'unrelated settings' => array( array( 'base_path' => '/other-backups' ) ),
		);
	}

	/**
	 * A different server needs fresh trust and a visible explanation.
	 *
	 * @return void
	 */
	public function test_b13_save_sftp_host_change_clears_pin_and_returns_notice() {
		$this->assert_b13_sftp_save( array( 'host' => 'other.example.com' ), true );
	}

	/**
	 * A different port can serve a different SSH host key.
	 *
	 * @return void
	 */
	public function test_b13_save_sftp_port_change_clears_pin_and_returns_notice() {
		$this->assert_b13_sftp_save( array( 'port' => '2222' ), true );
	}

	/**
	 * A credential edit on the same server keeps the pin, so the next
	 * connection cannot hand the new credential to an unpinned key.
	 *
	 * @dataProvider b13_changed_sftp_credentials
	 * @param array $changes Submitted credential changes.
	 * @return void
	 */
	public function test_b13_save_sftp_credential_change_keeps_pin( array $changes ) {
		$this->assert_b13_sftp_save( $changes, false );
	}

	/**
	 * Re-entering unreadable credentials must preserve and enforce the learned pin.
	 *
	 * @return void
	 */
	public function test_b13_save_sftp_unreadable_credentials_keeps_pin() {
		global $wpdb;

		$settings = array(
			'host'        => 'sftp.example.com',
			'port'        => '22',
			'username'    => 'backup-user',
			'password'    => 'saved-password',
			'private_key' => 'saved-private-key',
			'base_path'   => '/backups',
		);
		$store    = MainWP_Backup_Storage_Destinations::instance();
		$id       = $store->add_destination(
			array(
				'provider' => 'sftp',
				'name'     => 'Original SFTP',
				'settings' => $settings,
			)
		);
		$this->assertGreaterThan( 0, $id );
		$pin = \MainWP\Dashboard\MainWP_Backup_Storage_SFTP::fingerprint_host_key( 'original-host-key' );
		update_option( 'mainwp_backup_sftp_hostkeys', array( $id => $pin ), false );

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$raw   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- raw credential envelope fixture.
		$this->assertTrue( \MainWP\Dashboard\MainWP_Credential_Storage::is_credential_envelope( $raw ) );
		$envelope = json_decode( substr( $raw, strlen( \MainWP\Dashboard\MainWP_Credential_Storage::ENVELOPE_PREFIX ) ), true );
		$this->assertNotEmpty( $envelope['file_key'] );
		\MainWP\Dashboard\MainWP_Keys_Manager::instance()->delete_key_file( $envelope['file_key'] );
		$row = $store->get_destination( $id );
		$this->assertNull( $row->settings );
		$this->assertTrue( $row->credentials_invalid );
		$settings['password'] = 'replacement-password';

		$provider = $this->createMock( \MainWP\Dashboard\MainWP_Backup_Storage_Provider::class );
		$provider->expects( $this->once() )->method( 'connection_test' )->willReturn( array( 'ok' => true ) );
		$destinations = $this->getMockBuilder( MainWP_Backup_Storage_Destinations::class )->onlyMethods( array( 'make_provider' ) )->getMock();
		$destinations->expects( $this->once() )->method( 'make_provider' )->willReturnCallback(
			function ( $candidate ) use ( $provider, $id ) {
				$this->assertSame( $id, $candidate->id, 'Credential recovery must enforce the surviving pin before login.' );
				return $provider;
			}
		);
		$instance = new \ReflectionProperty( MainWP_Backup_Storage_Destinations::class, 'instance' );
		$instance->setValue( null, $destinations );
		try {
			$result = MainWP_Backup_Storage_Handler::save_destination(
				array(
					'id'       => $id,
					'provider' => 'sftp',
					'name'     => 'Renamed SFTP',
					'settings' => $settings,
				)
			);
			$this->assertArrayNotHasKey( 'error', $result );
			$this->assertSame( $id, $result['id'] );
			$row = $store->get_destination( $id );
			$this->assertSame( 'Renamed SFTP', $row->name );
			$this->assertSame( $settings, $row->settings );
			$this->assertFalse( $row->credentials_invalid );
			$pins = get_option( 'mainwp_backup_sftp_hostkeys' );
			$this->assertSame( $pin, $pins[ $id ] ?? null );
			$this->assertArrayNotHasKey( 'notice', $result );
		} finally {
			$instance->setValue( null, $store );
			delete_option( 'mainwp_backup_sftp_hostkeys' );
		}
	}

	/**
	 * Switching away from unreadable SFTP credentials must discard the old pin.
	 *
	 * @return void
	 */
	public function test_b225_save_unreadable_sftp_provider_round_trip_clears_pin() {
		global $wpdb;

		$settings = array(
			'host'        => 'sftp.example.com',
			'port'        => '22',
			'username'    => 'backup-user',
			'password'    => 'saved-password',
			'private_key' => 'saved-private-key',
			'base_path'   => '/backups',
		);
		$store    = MainWP_Backup_Storage_Destinations::instance();
		$id       = $store->add_destination(
			array(
				'provider' => 'sftp',
				'name'     => 'Original SFTP',
				'settings' => $settings,
			)
		);
		$this->assertGreaterThan( 0, $id );
		$pin = \MainWP\Dashboard\MainWP_Backup_Storage_SFTP::fingerprint_host_key( 'original-host-key' );
		update_option( 'mainwp_backup_sftp_hostkeys', array( $id => $pin ), false );

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$raw   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- raw credential envelope fixture.
		$this->assertTrue( \MainWP\Dashboard\MainWP_Credential_Storage::is_credential_envelope( $raw ) );
		$envelope = json_decode( substr( $raw, strlen( \MainWP\Dashboard\MainWP_Credential_Storage::ENVELOPE_PREFIX ) ), true );
		$this->assertNotEmpty( $envelope['file_key'] );
		\MainWP\Dashboard\MainWP_Keys_Manager::instance()->delete_key_file( $envelope['file_key'] );
		$row = $store->get_destination( $id );
		$this->assertNull( $row->settings );
		$this->assertTrue( $row->credentials_invalid );
		$settings['host'] = 'new.example.com';
		$base             = $this->make_temp_dir();
		$candidate_ids    = array();

		$provider = $this->createMock( \MainWP\Dashboard\MainWP_Backup_Storage_Provider::class );
		$provider->expects( $this->exactly( 2 ) )->method( 'connection_test' )->willReturn( array( 'ok' => true ) );
		$destinations = $this->getMockBuilder( MainWP_Backup_Storage_Destinations::class )->onlyMethods( array( 'make_provider' ) )->getMock();
		$destinations->expects( $this->exactly( 2 ) )->method( 'make_provider' )->willReturnCallback(
			function ( $candidate ) use ( $provider, &$candidate_ids ) {
				$candidate_ids[] = $candidate->id;
				return $provider;
			}
		);
		$instance = new \ReflectionProperty( MainWP_Backup_Storage_Destinations::class, 'instance' );
		$instance->setValue( null, $destinations );
		try {
			$result = MainWP_Backup_Storage_Handler::save_destination(
				array(
					'id'       => $id,
					'provider' => 'local',
					'name'     => 'Local NAS',
					'settings' => array( 'base_path' => $base ),
				)
			);
			$this->assertArrayNotHasKey( 'error', $result );
			$this->assertSame( $id, $result['id'] );
			$this->assertArrayNotHasKey( $id, get_option( 'mainwp_backup_sftp_hostkeys', array() ) );

			$result = MainWP_Backup_Storage_Handler::save_destination(
				array(
					'id'       => $id,
					'provider' => 'sftp',
					'name'     => 'Renamed SFTP',
					'settings' => $settings,
				)
			);
			$this->assertArrayNotHasKey( 'error', $result );
			$this->assertSame( $id, $result['id'] );
			$row = $store->get_destination( $id );
			$this->assertSame( 'Renamed SFTP', $row->name );
			$this->assertSame( $settings, $row->settings );
			$this->assertFalse( $row->credentials_invalid );
			$this->assertSame( 'sftp', $row->provider );
			$this->assertSame( array( 0, 0 ), $candidate_ids, 'Both provider changes must test without the previous SFTP pin.' );
			$this->assertArrayNotHasKey( $id, get_option( 'mainwp_backup_sftp_hostkeys', array() ) );
			$this->assertArrayNotHasKey( 'notice', $result );
		} finally {
			$instance->setValue( null, $store );
			delete_option( 'mainwp_backup_sftp_hostkeys' );
		}
	}

	/**
	 * Each credential field is checked on its own.
	 *
	 * @return array
	 */
	public static function b13_changed_sftp_credentials() {
		return array(
			'username'    => array( array( 'username' => 'other-user' ) ),
			'password'    => array( array( 'password' => 'replacement-password' ) ),
			'private key' => array( array( 'private_key' => 'replacement-private-key' ) ),
		);
	}

	/**
	 * Keep persistence real so the regression cannot hide behind a mocked save.
	 *
	 * @param array $changes     Submitted settings changes.
	 * @param bool  $reset_trust Whether this edit should clear the learned pin.
	 * @return void
	 */
	private function assert_b13_sftp_save( array $changes, bool $reset_trust ) {
		$settings = array(
			'host'        => 'sftp.example.com',
			'port'        => '22',
			'username'    => 'backup-user',
			'password'    => 'saved-password',
			'private_key' => 'saved-private-key',
			'base_path'   => '/backups',
		);
		$store    = MainWP_Backup_Storage_Destinations::instance();
		$id       = $store->add_destination(
			array(
				'provider' => 'sftp',
				'name'     => 'Original SFTP',
				'settings' => $settings,
			)
		);
		$this->assertGreaterThan( 0, $id );
		$pin = \MainWP\Dashboard\MainWP_Backup_Storage_SFTP::fingerprint_host_key( 'original-host-key' );
		update_option( 'mainwp_backup_sftp_hostkeys', array( $id => $pin ), false );

		$provider = $this->createMock( \MainWP\Dashboard\MainWP_Backup_Storage_Provider::class );
		$provider->expects( $this->once() )->method( 'connection_test' )->willReturn( array( 'ok' => true ) );
		$destinations = $this->getMockBuilder( MainWP_Backup_Storage_Destinations::class )->onlyMethods( array( 'make_provider' ) )->getMock();
		$destinations->expects( $this->once() )->method( 'make_provider' )->willReturnCallback(
			function ( $candidate ) use ( $provider, $changes, $id ) {
				$new_server = isset( $changes['host'] ) && 'other.example.com' === $changes['host'];
				$new_server = $new_server || ( isset( $changes['port'] ) && '2222' === $changes['port'] );
				$this->assertSame( $new_server ? 0 : $id, $candidate->id, 'Same-server credential edits must still enforce the old pin before login.' );
				return $provider;
			}
		);
		$instance = new \ReflectionProperty( MainWP_Backup_Storage_Destinations::class, 'instance' );
		$instance->setValue( null, $destinations );
		try {
			$result = MainWP_Backup_Storage_Handler::save_destination(
				array(
					'id'       => $id,
					'provider' => 'sftp',
					'name'     => 'Renamed SFTP',
					'settings' => array_merge( $settings, $changes ),
				)
			);
			$this->assertArrayNotHasKey( 'error', $result );
			$this->assertSame( $id, $result['id'] );
			$row = $store->get_destination( $id );
			$this->assertSame( 'Renamed SFTP', $row->name );
			$expected_settings = array_merge( $settings, $changes );
			foreach ( array( 'password', 'private_key' ) as $field ) {
				if ( '' === $expected_settings[ $field ] ) {
					$expected_settings[ $field ] = $settings[ $field ];
				}
			}
			$this->assertSame( $expected_settings, $row->settings );
			$pins = get_option( 'mainwp_backup_sftp_hostkeys' );
			if ( $reset_trust ) {
				$this->assertArrayNotHasKey( $id, $pins );
				$this->assertArrayHasKey( 'notice', $result );
				$this->assertSame( 'The SFTP host key for this destination was reset because the host or port changed and will be learned again on the next connection.', $result['notice'] );
			} else {
				$this->assertSame( $pin, $pins[ $id ] ?? null );
				$this->assertArrayNotHasKey( 'notice', $result );
			}
		} finally {
			$instance->setValue( null, $store );
			delete_option( 'mainwp_backup_sftp_hostkeys' );
		}
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
	 * Starting the Dropbox connection stashes the pending destination
	 * encrypted server-side and returns the authorize URL; secrets never
	 * appear in the response.
	 *
	 * @return void
	 */
	public function test_dropbox_oauth_start_stashes_and_returns_authorize_url() {
		$result = MainWP_Backup_Storage_Handler::start_dropbox_oauth(
			array(
				'id'         => 0,
				'name'       => 'OAuth Dropbox',
				'is_default' => 0,
				'settings'   => array(
					'app_key'    => 'oauth-app-key',
					'app_secret' => 'oauth-app-secret',
					'base_path'  => 'mainwp',
				),
			)
		);

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertStringStartsWith( 'https://www.dropbox.com/oauth2/authorize?', $result['authorize_url'] );
		$this->assertStringNotContainsString( 'oauth-app-secret', wp_json_encode( $result ), 'The app secret must never reach the browser.' );

		parse_str( (string) wp_parse_url( $result['authorize_url'], PHP_URL_QUERY ), $params );
		$this->assertSame( 'oauth-app-key', $params['client_id'] );
		$this->assertSame( 'code', $params['response_type'] );
		$this->assertSame( 'offline', $params['token_access_type'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $params['state'] );
		$this->assertStringContainsString( 'mainwp_dropbox_oauth=1', $params['redirect_uri'] );

		$envelope = get_transient( 'mainwp_dropbox_oauth_' . $params['state'] );
		$this->assertTrue( \MainWP\Dashboard\MainWP_Credential_Storage::is_credential_envelope( (string) $envelope ), 'The stash must be encrypted at rest.' );

		$stash = json_decode( \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( (string) $envelope ), true );
		$this->assertSame( 'oauth-app-secret', $stash['app_secret'] );
		$this->assertSame( get_current_user_id(), (int) $stash['user_id'] );
	}

	/**
	 * The connection cannot start without the fields the exchange needs.
	 *
	 * @return void
	 */
	public function test_dropbox_oauth_start_requires_fields() {
		$result = MainWP_Backup_Storage_Handler::start_dropbox_oauth(
			array(
				'id'       => 0,
				'name'     => 'No secret',
				'settings' => array( 'app_key' => 'k' ),
			)
		);

		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * Completing the connection consumes the stash exactly once,
	 * exchanges the code, and saves the destination with the issued
	 * refresh token.
	 *
	 * @return void
	 */
	public function test_complete_dropbox_oauth_saves_destination_once() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'access_token' => 'short',
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

		$start = MainWP_Backup_Storage_Handler::start_dropbox_oauth(
			array(
				'id'         => 0,
				'name'       => 'OAuth Dropbox',
				'is_default' => 0,
				'settings'   => array(
					'app_key'    => 'oauth-app-key',
					'app_secret' => 'oauth-app-secret',
					'base_path'  => 'mainwp',
				),
			)
		);
		parse_str( (string) wp_parse_url( $start['authorize_url'], PHP_URL_QUERY ), $params );

		$exchange = function ( $url, $args ) {
			$this->assertSame( 'https://api.dropboxapi.com/oauth2/token', $url );
			$this->assertSame( 'authorization_code', $args['body']['grant_type'] );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'refresh_token' => 'issued-refresh-token' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		$result = MainWP_Backup_Storage_Handler::complete_dropbox_oauth( $params['state'], 'auth-code', $exchange );

		$this->assertArrayNotHasKey( 'error', $result );
		$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $result['id'] );
		$this->assertSame( 'dropbox', $row->provider );
		$this->assertSame( 'issued-refresh-token', $row->settings['refresh_token'] );
		$this->assertSame( 'oauth-app-secret', $row->settings['app_secret'] );

		$replay = MainWP_Backup_Storage_Handler::complete_dropbox_oauth( $params['state'], 'auth-code', $exchange );
		$this->assertArrayHasKey( 'error', $replay, 'The state stash must be single use.' );
	}

	/**
	 * A stash started by a different user, an unknown state, or a failed
	 * exchange all refuse without saving anything.
	 *
	 * @return void
	 */
	public function test_complete_dropbox_oauth_refusals() {
		$this->assertArrayHasKey( 'error', MainWP_Backup_Storage_Handler::complete_dropbox_oauth( str_repeat( 'a', 32 ), 'code' ) );
		$this->assertArrayHasKey( 'error', MainWP_Backup_Storage_Handler::complete_dropbox_oauth( 'not-a-state!', 'code' ) );

		$start = MainWP_Backup_Storage_Handler::start_dropbox_oauth(
			array(
				'id'       => 0,
				'name'     => 'Other user',
				'settings' => array(
					'app_key'    => 'k',
					'app_secret' => 's',
				),
			)
		);
		parse_str( (string) wp_parse_url( $start['authorize_url'], PHP_URL_QUERY ), $params );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = MainWP_Backup_Storage_Handler::complete_dropbox_oauth( $params['state'], 'code' );
		$this->assertArrayHasKey( 'error', $result, 'A different user must not be able to complete the connection.' );
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
	 * The S3 settings block offers the server-side encryption select with
	 * its three values, and no other provider block carries it: only S3
	 * understands the x-amz-server-side-encryption header.
	 *
	 * @return void
	 */
	public function test_s3_block_offers_server_side_encryption_select() {
		ob_start();
		MainWP_Backup_Storage_View::render_form();
		$output = ob_get_clean();

		$s3_start  = strpos( $output, 'data-provider="s3"' );
		$gcs_start = strpos( $output, 'data-provider="gcs"' );
		$this->assertNotFalse( $s3_start );
		$this->assertNotFalse( $gcs_start );

		$s3_block = substr( $output, $s3_start, $gcs_start - $s3_start );
		$this->assertStringContainsString( 'id="mainwp-storage-sse"', $s3_block, 'The SSE select belongs in the S3 block.' );
		$this->assertStringContainsString( 'value="AES256"', $s3_block );
		$this->assertStringContainsString( 'value="aws:kms"', $s3_block );

		$after_s3 = substr( $output, $gcs_start );
		$this->assertStringNotContainsString( 'id="mainwp-storage-sse"', $after_s3, 'No non-S3 block may carry the SSE select.' );
	}

	/**
	 * An S3 destination persists its server-side encryption choice, and the
	 * edit prefill returns it so the form round-trips the value.
	 *
	 * @return void
	 */
	public function test_s3_destination_persists_and_prefills_sse() {
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
				'name'     => 'Encrypted bucket',
				'settings' => array(
					'bucket'     => 'b',
					'region'     => 'us-east-1',
					'access_key' => 'AKIA-SSE',
					'secret_key' => 'sse-secret',
					'sse'        => 'AES256',
				),
			)
		);
		$this->assertArrayNotHasKey( 'error', $created );

		$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $created['id'] );
		$this->assertSame( 'AES256', $row->settings['sse'], 'The SSE choice must persist on the destination.' );

		$edit = MainWP_Backup_Storage_Handler::destination_for_edit( $created['id'] );
		$this->assertSame( 'AES256', $edit['settings']['sse'], 'The edit prefill must round-trip the SSE choice.' );
	}

	/**
	 * The Dropbox block offers the Connect button and shows the exact
	 * redirect URI the app must register.
	 *
	 * @return void
	 */
	public function test_dropbox_block_offers_connect_button_and_redirect_uri() {
		ob_start();
		MainWP_Backup_Storage_View::render_form();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-storage-dropbox-connect', $output );
		$this->assertStringContainsString( esc_attr( MainWP_Backup_Storage_Handler::dropbox_redirect_uri() ), $output, 'The redirect URI to register must be displayed.' );
	}

	/**
	 * A pending OAuth result renders once as a notice and is consumed.
	 *
	 * @return void
	 */
	public function test_oauth_notice_renders_once() {
		set_transient( 'mainwp_dropbox_oauth_notice_' . get_current_user_id(), array( 'error' => 'Dropbox declined.' ), 60 );

		ob_start();
		MainWP_Backup_Storage_View::render_oauth_notice();
		$first = ob_get_clean();

		$this->assertStringContainsString( 'Dropbox declined.', $first );

		ob_start();
		MainWP_Backup_Storage_View::render_oauth_notice();
		$second = ob_get_clean();

		$this->assertSame( '', $second, 'The notice must consume on first render.' );

		set_transient( 'mainwp_dropbox_oauth_notice_' . get_current_user_id(), array( 'id' => 7 ), 60 );
		ob_start();
		MainWP_Backup_Storage_View::render_oauth_notice();
		$success = ob_get_clean();
		$this->assertStringContainsString( 'connected', $success );
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

		$task = (object) array(
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
	 * the Server label.
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
		$this->assertStringContainsString( 'Server', $column );
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
