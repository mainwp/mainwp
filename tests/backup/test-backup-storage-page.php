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

require_once __DIR__ . '/class-backup-pcloud-transport-fixture.php';

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
	 * Saving and testing must reject writable folders in either served tree.
	 *
	 * @dataProvider served_local_directories
	 * @param string $boundary Served directory.
	 * @return void
	 */
	public function test_save_and_test_destination_refuse_served_directories( string $boundary ) {
		$base = untrailingslashit( $boundary ) . '/mainwp-storage-refusal-' . uniqid();
		mkdir( $base, 0755, true );
		$this->temp_dirs[] = $base;
		$input             = array(
			'id'       => 0,
			'provider' => 'local',
			'name'     => 'Served backup folder',
			'settings' => array( 'base_path' => $base ),
		);
		foreach ( array( 'save_destination', 'test_saved_or_submitted' ) as $method ) {
			$result = MainWP_Backup_Storage_Handler::$method( $input );
			$this->assertArrayHasKey( 'error', $result, $method );
			$this->assertStringContainsString( $base, $result['error'] );
			$this->assertStringContainsString( 'outside the WordPress and wp-content folders', $result['error'] );
			$this->assertEmpty( $result['ok'] ?? false );
		}
		$this->assertSame( array(), MainWP_Backup_Storage_Destinations::instance()->list_destinations() );
		$this->assertSame( array( '.', '..' ), scandir( $base ) );
	}

	/**
	 * wp-content can be outside the WordPress installation.
	 *
	 * @return array
	 */
	public static function served_local_directories() {
		return array(
			'wordpress' => array( ABSPATH ),
			'content'   => array( WP_CONTENT_DIR ),
		);
	}

	/**
	 * Stored settings must not bypass location checks when testing an old row.
	 *
	 * @return void
	 */
	public function test_stored_local_destination_in_served_tree_is_refused() {
		$base = ABSPATH . 'mainwp-storage-stored-refusal-' . uniqid();
		mkdir( $base, 0755, true );
		$this->temp_dirs[] = $base;
		$id                = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Old served folder',
				'settings' => array( 'base_path' => $base ),
			)
		);
		$this->assertGreaterThan( 0, $id );
		$input = array(
			'id'       => $id,
			'provider' => 'local',
			'name'     => 'Old served folder',
		);
		foreach ( array( 'save_destination', 'test_saved_or_submitted' ) as $method ) {
			$result = MainWP_Backup_Storage_Handler::$method( $input );
			$this->assertArrayHasKey( 'error', $result, $method );
			$this->assertStringContainsString( $base, $result['error'] );
			$this->assertStringContainsString( 'outside the WordPress and wp-content folders', $result['error'] );
		}
		$this->assertSame( array( '.', '..' ), scandir( $base ) );
	}

	/**
	 * Missing folders beneath a served symlink must be classified before probing.
	 *
	 * @return void
	 */
	public function test_destination_refuses_missing_path_beneath_served_symlink() {
		$link = $this->make_temp_dir() . '/wordpress';
		$base = $link . '/mainwp-storage-missing-' . uniqid() . '/nested';
		if ( ! @symlink( ABSPATH, $link ) ) {
			$this->markTestSkipped( 'This platform cannot create the directory symlink.' );
		}
		try {
			$input = array(
				'id'       => 0,
				'provider' => 'local',
				'name'     => 'Missing served folder',
				'settings' => array( 'base_path' => $base ),
			);
			foreach ( array( 'save_destination', 'test_saved_or_submitted' ) as $method ) {
				$result = MainWP_Backup_Storage_Handler::$method( $input );
				$this->assertArrayHasKey( 'error', $result, $method );
				$this->assertStringContainsString( $base, $result['error'] );
				$this->assertStringContainsString( 'outside the WordPress and wp-content folders', $result['error'] );
			}
			$this->assertDirectoryDoesNotExist( $base );
			$this->assertSame( array(), MainWP_Backup_Storage_Destinations::instance()->list_destinations() );
		} finally {
			unlink( $link );
		}
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
			$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
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
			$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
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
	 * Saving or testing an S3 destination whose http:// endpoint is on a
	 * public address fails with the use-https error and sends nothing; the
	 * same endpoint on the LAN still saves.
	 *
	 * @return void
	 */
	public function test_save_and_test_refuse_public_http_s3_endpoint() {
		$sent = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$sent ) {
				$sent[] = $url;
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
		$input = function ( $endpoint ) {
			return array(
				'id'       => 0,
				'provider' => 's3',
				'name'     => 'MinIO',
				'settings' => array(
					'endpoint'   => $endpoint,
					'path_style' => '1',
					'bucket'     => 'b',
					'region'     => 'us-east-1',
					'access_key' => 'AKIA-MINIO',
					'secret_key' => 'minio-secret',
				),
			);
		};

		$saved = MainWP_Backup_Storage_Handler::save_destination( $input( 'http://203.0.113.10:9000' ) );
		$this->assertArrayHasKey( 'error', $saved );
		$this->assertStringContainsString( 'https://', $saved['error'] );
		$this->assertSame( array(), MainWP_Backup_Storage_Destinations::instance()->list_destinations() );

		$tested = MainWP_Backup_Storage_Handler::test_saved_or_submitted( $input( 'http://203.0.113.10:9000' ) );
		$this->assertFalse( $tested['ok'] );
		$this->assertStringContainsString( 'https://', $tested['error'] );
		$this->assertSame( array(), $sent, 'Nothing may be sent to a public http:// endpoint.' );

		$lan = MainWP_Backup_Storage_Handler::save_destination( $input( 'http://192.168.1.50:9000' ) );
		$this->assertArrayNotHasKey( 'error', $lan );
		$this->assertNotEmpty( $sent );
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

	/** Hidden Back Up Now tasks appear in the preview without changing the scheduled task list. */
	public function test_delete_preview_lists_hidden_backup_now_tasks() {
		$store   = MainWP_Backup_Storage_Destinations::instance();
		$dest_id = $store->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Hidden task destination',
				'settings' => array( 'base_path' => $this->make_temp_dir() ),
			)
		);
		$task_id = $this->insert_task( array( $dest_id ) );
		$db      = MainWP_DB_Backup::instance();
		$db->update_backup_task_with_values(
			$task_id,
			array(
				'template'       => 1,
				'name'           => 'On-demand backup',
				'manual_site_id' => 991,
			)
		);

		$this->assertNotContains( $task_id, array_map( 'intval', wp_list_pluck( $db->get_backup_tasks(), 'id' ) ) );
		$this->assertSame( array( 'tasks' => array( 'On-demand backup' ) ), MainWP_Backup_Storage_Handler::delete_destination_flow( $dest_id, false ) );
		$this->assertNotNull( $store->get_destination( $dest_id ) );
		$this->assertSame( array( $dest_id ), json_decode( $db->get_manual_backup_task( 991 )->destinations, true ) );
	}

	/** Every Back Up Now task shares one stored name, so the preview names each by its site. */
	public function test_delete_preview_names_hidden_tasks_by_site() {
		global $wpdb;
		$store    = MainWP_Backup_Storage_Destinations::instance();
		$dest_id  = $store->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Hidden task destination',
				'settings' => array( 'base_path' => $this->make_temp_dir() ),
			)
		);
		$db       = MainWP_DB_Backup::instance();
		$expected = array( 'Storage page task' );
		$this->insert_task( array( $dest_id ) );
		foreach ( array( 'Alpha Site', 'Beta Site' ) as $site_name ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
				MainWP_DB::instance()->get_table_name( 'wp' ),
				array(
					'userid'    => get_current_user_id(),
					'url'       => 'https://' . sanitize_title( $site_name ) . '.example.com/',
					'name'      => $site_name,
					'adminname' => 'admin',
					'pubkey'    => 'test-pubkey',
					'privkey'   => 'test-privkey',
				)
			);
			$site_id = (int) $wpdb->insert_id;
			$wpdb->insert( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'wpid' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$task_id = $this->insert_task( array( $dest_id ) );
			$db->update_backup_task_with_values(
				$task_id,
				array(
					'template'       => 1,
					'name'           => 'On-demand backup',
					'manual_site_id' => $site_id,
				)
			);
			$expected[] = 'Back Up Now for ' . $site_name;
		}

		$this->assertSame( array( 'tasks' => $expected ), MainWP_Backup_Storage_Handler::delete_destination_flow( $dest_id, false ) );
	}

	/** Confirmed deletion detaches every hidden template and preserves other destinations. */
	public function test_delete_detaches_hidden_backup_now_tasks() {
		$store   = MainWP_Backup_Storage_Destinations::instance();
		$dest_id = $store->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Hidden task destination',
				'settings' => array( 'base_path' => $this->make_temp_dir() ),
			)
		);
		$keep_id = $store->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Keep destination',
				'settings' => array( 'base_path' => $this->make_temp_dir() ),
			)
		);
		$db      = MainWP_DB_Backup::instance();
		$tasks   = array();
		foreach ( array( 0, 1, 2 ) as $template ) {
			$task_id = $this->insert_task( array( $dest_id, $keep_id ) );
			$tasks[] = $task_id;
			$db->update_backup_task_with_values( $task_id, array( 'template' => $template ) );
		}
		$unrelated = $this->insert_task( array( $keep_id ) );
		$db->update_backup_task_with_values( $unrelated, array( 'template' => 1 ) );

		$this->assertSame( array( 'deleted' => true ), MainWP_Backup_Storage_Handler::delete_destination_flow( $dest_id, true ) );
		foreach ( $tasks as $task_id ) {
			$this->assertSame( array( $keep_id ), json_decode( $db->get_backup_task_by_id( $task_id )->destinations, true ), 'Every referencing task must lose only the deleted destination.' );
		}
		$this->assertSame( array( $keep_id ), json_decode( $db->get_backup_task_by_id( $unrelated )->destinations, true ) );
		$this->assertNull( $store->get_destination( $dest_id ) );
		$this->assertNotNull( $store->get_destination( $keep_id ) );
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
	 * None carries a non-empty value: Fomantic turns an empty-value option
	 * into placeholder text and drops it from the menu, which left no way
	 * back to None once an encryption mode was picked.
	 *
	 * @return void
	 */
	public function test_s3_sse_none_option_is_selectable() {
		ob_start();
		MainWP_Backup_Storage_View::render_form();
		$output = ob_get_clean();

		$this->assertSame( 1, preg_match( '#<select id="mainwp-storage-sse"[^>]*>(.*?)</select>#s', $output, $select ) );
		$this->assertMatchesRegularExpression( '#<option value="none">\s*None\s*</option>#', $select[1] );
		$this->assertStringNotContainsString( 'value=""', $select[1], 'An empty-value option becomes the dropdown placeholder and cannot be picked.' );
	}

	/**
	 * Encryption modes for the reset-to-None tests.
	 *
	 * @return array
	 */
	public function sse_modes() {
		return array(
			'SSE-S3'  => array( 'AES256' ),
			'SSE-KMS' => array( 'aws:kms' ),
		);
	}

	/**
	 * Saving None over a stored encryption mode clears it, keeps the blank
	 * secret, and leaves the destination id and task assignment alone.
	 *
	 * @dataProvider sse_modes
	 * @param string $mode Stored encryption mode.
	 * @return void
	 */
	public function test_s3_sse_resets_to_none_on_edit( string $mode ) {
		$requests = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( &$requests ) {
				$requests[] = $args;
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
			2
		);
		$settings = array(
			'bucket'     => 'b',
			'region'     => 'us-east-1',
			'access_key' => 'AKIA-SSE',
			'secret_key' => 'sse-secret',
			'sse'        => $mode,
		);

		$created = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 's3',
				'name'     => 'Encrypted bucket',
				'settings' => $settings,
			)
		);
		$this->assertArrayNotHasKey( 'error', $created );
		$id      = (int) $created['id'];
		$task_id = $this->insert_task( array( $id ) );
		$this->assertSame( $mode, MainWP_Backup_Storage_Destinations::instance()->get_destination( $id )->settings['sse'] );

		$requests = array();
		$edited   = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => $id,
				'provider' => 's3',
				'name'     => 'Encrypted bucket',
				'settings' => array_merge(
					$settings,
					array(
						'secret_key' => '',
						'sse'        => 'none',
					)
				),
			)
		);
		$this->assertArrayNotHasKey( 'error', $edited );
		$this->assertSame( $id, (int) $edited['id'], 'Resetting encryption must edit the destination in place.' );
		$this->assertNotEmpty( $requests, 'The edit must run a connection test.' );
		foreach ( $requests as $request ) {
			$this->assertArrayNotHasKey( 'x-amz-server-side-encryption', array_change_key_case( (array) ( $request['headers'] ?? array() ) ), 'No encryption header may be sent once None is chosen.' );
		}

		$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
		$this->assertSame( '', $row->settings['sse'], 'None must store an empty SSE value.' );
		$this->assertSame( 'sse-secret', $row->settings['secret_key'], 'A blank secret on edit must keep the stored one.' );
		$this->assertSame( '', MainWP_Backup_Storage_Handler::destination_for_edit( $id )['settings']['sse'] );
		$this->assertCount( 1, MainWP_Backup_Storage_Destinations::instance()->list_destinations() );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
		$this->assertSame( array( $id ), array_map( 'intval', json_decode( $task->destinations, true ) ), 'The task must still point at the same destination.' );
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
		$this->assertStringContainsString( '<strong>Dashboard</strong>', $column );
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
	/**
	 * Secret input survives unslashing without text-field normalization.
	 *
	 * @return void
	 */
	public function test_secret_input_preserves_exact_bytes() {
		$original                = $_POST;
		$secret                  = "  p%2F<b>word</b>\\quote\"'\n\t  ";
		$settings                = array_fill_keys( MainWP_Backup_Storage_Handler::SECRET_FIELDS, $secret );
		$settings['private_key'] = "  -----BEGIN KEY-----\nabc\n-----END KEY-----  ";
		$settings['host']        = ' <b>host.example.com</b> ';
		$_POST['settings']       = wp_slash( $settings );
		$method                  = new \ReflectionMethod( MainWP_Backup_Storage_Handler::class, 'read_destination_input' );
		$method->setAccessible( true );
		$handler = ( new \ReflectionClass( MainWP_Backup_Storage_Handler::class ) )->newInstanceWithoutConstructor();
		try {
			$input = $method->invoke( $handler );
		} finally {
			$_POST = $original;
		}
		foreach ( array( 'secret_key', 'app_secret', 'refresh_token', 'password' ) as $field ) {
			$this->assertSame( $secret, $input['settings'][ $field ], $field );
		}
		$this->assertSame( sanitize_textarea_field( $settings['private_key'] ), $input['settings']['private_key'] );
		$this->assertSame( 'host.example.com', $input['settings']['host'] );
	}

	/**
	 * Blank secrets are retained only within the same provider and target.
	 *
	 * @dataProvider credential_target_changes
	 * @param string $provider Stored provider.
	 * @param array  $stored   Stored settings.
	 * @param string $field    Identity field changed by the candidate.
	 * @return void
	 */
	public function test_blank_secrets_do_not_cross_target_changes( $provider, $stored, $field ) {
		$secrets = array_fill_keys( MainWP_Backup_Storage_Handler::SECRET_FIELDS, 'saved-secret' );
		$id      = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => $provider,
				'name'     => 'Original',
				'settings' => array_merge( $stored, $secrets ),
			)
		);
		$this->assertGreaterThan( 0, $id );
		$method = new \ReflectionMethod( MainWP_Backup_Storage_Handler::class, 'merge_candidate' );
		$method->setAccessible( true );
		$input = array(
			'id'       => $id,
			'provider' => $provider,
			'name'     => 'Renamed',
			'settings' => array_merge( $stored, array_fill_keys( MainWP_Backup_Storage_Handler::SECRET_FIELDS, '' ) ),
		);
		$same  = $method->invoke( null, $input );
		foreach ( $secrets as $secret_field => $value ) {
			$this->assertSame( $value, $same['settings'][ $secret_field ], 'Same-target edits retain secrets.' );
		}
		if ( 'provider' === $field ) {
			$input['provider'] = 'local' === $provider ? 'sftp' : 'local';
		} else {
			$input['settings'][ $field ] = 'port' === $field ? '2222' : 'changed-target';
		}
		$changed = $method->invoke( null, $input );
		foreach ( $secrets as $secret_field => $value ) {
			$this->assertSame( '', $changed['settings'][ $secret_field ], $provider . ':' . $field . ' must not inherit ' . $secret_field );
		}
		if ( 'provider' === $field ) {
			unset( $input['settings'] );
			$this->assertSame( array(), $method->invoke( null, $input )['settings'], 'A provider switch cannot inherit omitted settings either.' );
		}
	}

	/**
	 * Folder-only edits retain every stored secret when submitted blank.
	 *
	 * @return void
	 */
	public function test_base_path_only_changes_keep_stored_secrets() {
		$method = new \ReflectionMethod( MainWP_Backup_Storage_Handler::class, 'merge_candidate' );
		$method->setAccessible( true );
		foreach ( array( 'dropbox', 'sftp' ) as $provider ) {
			$stored  = static::credential_target_changes()[ $provider . ':provider' ][1];
			$secrets = array();
			foreach ( MainWP_Backup_Storage_Handler::SECRET_FIELDS as $field ) {
				$secrets[ $field ] = 'saved-' . $field;
			}
			$id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
				array(
					'provider' => $provider,
					'name'     => 'Original',
					'settings' => array_merge( $stored, $secrets ),
				)
			);
			$this->assertGreaterThan( 0, $id );
			$input                          = array(
				'id'       => $id,
				'provider' => $provider,
				'name'     => 'Original',
				'settings' => array_merge( $stored, array_fill_keys( MainWP_Backup_Storage_Handler::SECRET_FIELDS, '' ) ),
			);
			$input['settings']['base_path'] = '/other-backups';
			$changed                        = $method->invoke( null, $input );
			$this->assertSame( '/other-backups', $changed['settings']['base_path'] );
			foreach ( $secrets as $field => $value ) {
				$this->assertSame( $value, $changed['settings'][ $field ], $provider . ':base_path must retain ' . $field );
			}
		}
	}

	/**
	 * Provider targets and account identifiers used by the connection code.
	 *
	 * @return array
	 */
	public static function credential_target_changes() {
		$providers = array(
			's3'      => array(
				'endpoint'   => 'https://s3.example.com',
				'bucket'     => 'backups',
				'region'     => 'us-east-1',
				'path_style' => '1',
				'access_key' => 'account',
			),
			'gcs'     => array(
				'bucket'     => 'backups',
				'region'     => 'auto',
				'access_key' => 'account',
			),
			'dropbox' => array(
				'app_key'   => 'account',
				'base_path' => '/backups',
			),
			'sftp'    => array(
				'host'      => 'sftp.example.com',
				'port'      => '22',
				'username'  => 'account',
				'base_path' => '/backups',
			),
			'local'   => array( 'base_path' => '/backups' ),
		);
		$cases     = array();
		foreach ( $providers as $provider => $settings ) {
			foreach ( array_merge( array_diff( array_keys( $settings ), array( 'base_path' ) ), array( 'provider' ) ) as $field ) {
				$cases[ $provider . ':' . $field ] = array( $provider, $settings, $field );
			}
		}
		return $cases;
	}

	/**
	 * Cosmetic SFTP host and port changes preserve blank credentials and pins.
	 *
	 * @return void
	 */
	public function test_blank_sftp_secrets_use_pin_target_normalization() {
		$this->assert_b13_sftp_save(
			array(
				'host'        => 'SFTP.Example.com.',
				'port'        => '022',
				'password'    => '',
				'private_key' => '',
			),
			false
		);
	}

	/**
	 * Retargeting cannot save blank credentials even if a provider accepts them.
	 *
	 * @return void
	 */
	public function test_retargeted_save_requires_reentered_secret() {
		$store    = MainWP_Backup_Storage_Destinations::instance();
		$id       = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Original bucket',
				'settings' => array(
					'bucket'     => 'old-bucket',
					'secret_key' => 'stored-secret',
				),
			)
		);
		$provider = $this->createMock( \MainWP\Dashboard\MainWP_Backup_Storage_Provider::class );
		$provider->method( 'connection_test' )->willReturn( array( 'ok' => true ) );
		$destinations = $this->getMockBuilder( MainWP_Backup_Storage_Destinations::class )->onlyMethods( array( 'make_provider' ) )->getMock();
		$destinations->method( 'make_provider' )->willReturn( $provider );
		$instance = new \ReflectionProperty( MainWP_Backup_Storage_Destinations::class, 'instance' );
		$instance->setValue( null, $destinations );
		try {
			$result = MainWP_Backup_Storage_Handler::save_destination(
				array(
					'id'       => $id,
					'provider' => 's3',
					'name'     => 'Other bucket',
					'settings' => array(
						'bucket'     => 'new-bucket',
						'secret_key' => '',
					),
				)
			);
			$this->assertArrayHasKey( 'error', $result, 'A target change requires explicit credentials before saving.' );
			$this->assertSame( 'old-bucket', $store->get_destination( $id )->settings['bucket'] );
		} finally {
			$instance->setValue( null, $store );
		}
	}

	/**
	 * The form's Test Connection result shows next to its buttons, and the
	 * clicked button shows a loading state while the request runs.
	 */
	public function test_form_test_result_renders_beside_the_buttons() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		MainWP_Backup_Storage_View::render();
		$output = ob_get_clean();

		$zone = strpos( $output, 'id="mainwp-storage-form-message-zone"' );
		$this->assertNotFalse( $zone );
		$this->assertGreaterThan( strpos( $output, 'id="mainwp-storage-form"' ), $zone );
		$this->assertLessThan( strpos( $output, 'id="mainwp-storage-test-only"' ), $zone );
		$this->assertStringContainsString( 'class="ui visible message" id="mainwp-storage-form-message-zone"', $output );
		$this->assertStringContainsString( 'feedback( zone, message, color );', $output, 'Messages go to core feedback(), which renders strings as text.' );
		$this->assertStringNotContainsString( '.text( message ).html()', $output, 'Pre-escaping would double-escape now that feedback() renders text.' );
		$this->assertMatchesRegularExpression( "/#mainwp-storage-test-only' \\).on\\( 'click'.*?busy\\( button, true \\);[^}]*formFeedback\\(/s", $output );
	}

	/**
	 * The destinations table names each provider the way the form does, and
	 * Edit links and Delete use the standard provider and action styles.
	 */
	public function test_destination_table_shows_provider_label_and_icon_delete() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved = MainWP_Backup_Storage_Handler::save_destination(
			array(
				'id'       => 0,
				'provider' => 'local',
				'name'     => 'Label test',
				'settings' => array( 'base_path' => $this->make_temp_dir() ),
			)
		);
		$this->assertArrayNotHasKey( 'error', $saved );
		ob_start();
		MainWP_Backup_Storage_View::render();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/<a href="#" class="mainwp-storage-edit">Label test<\/a>/', $output );
		$this->assertStringContainsString( '<strong>Local Path</strong>', $output );
		$this->assertStringContainsString( 'class="ui small text"', $output );
		$this->assertStringContainsString( 'class="ui mini red button mainwp-storage-delete"', $output );
		$this->assertStringNotContainsString( 'basic red button', $output );
	}
	/**
	 * Dropbox continuity uses provider metadata under each token's account.
	 *
	 * @param array $objects Stored remote objects by account and path.
	 * @param array $requests Captured provider requests.
	 * @return callable
	 */
	private function retention_dropbox_transport( array &$objects, array &$requests ) {
		return static function ( $pre, $args, $url ) use ( &$objects, &$requests ) {
			unset( $pre );
			$requests[] = array( $url, $args );
			$status     = 200;
			$data       = array();
			if ( str_ends_with( $url, '/oauth2/token' ) ) {
				$data = array( 'access_token' => $args['body']['refresh_token'] );
			} else {
				$token   = substr( $args['headers']['Authorization'], 7 );
				$account = str_starts_with( $token, 'other-' ) ? 'other' : 'original';
				$arg     = json_decode( $args['headers']['Dropbox-API-Arg'] ?? $args['body'], true );
				$path    = $arg['path'];
				if ( str_ends_with( $url, '/upload' ) ) {
					$id                           = $objects[ $account ][ $path ]['id'] ?? 'id:file' . count( $requests );
					$objects[ $account ][ $path ] = array(
						'.tag' => 'file',
						'id'   => $id,
						'size' => strlen( $args['body'] ),
					);
					$data                         = $objects[ $account ][ $path ];
				} elseif ( str_ends_with( $url, '/delete_v2' ) ) {
					unset( $objects[ $account ][ $path ] );
				} elseif ( 'broken-token' === $token && str_contains( $path, '.mainwp-retention-' ) ) {
					$status = 503;
				} elseif ( isset( $objects[ $account ][ $path ] ) ) {
					$data = $objects[ $account ][ $path ];
				} else {
					$status = 409;
					$data   = array( 'error_summary' => 'path/not_found/..' );
				}
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $data ),
				'response' => array(
					'code'    => $status,
					'message' => '',
				),
			);
		};
	}

	/** Renames keep an invalid receipt without testing the connection or marker. */
	public function test_dropbox_rename_with_invalid_receipt_never_uses_http() {
		$store    = MainWP_Backup_Storage_Destinations::instance();
		$field    = '_mainwp_retention_identity';
		$settings = array(
			'app_key'       => 'app-key',
			'app_secret'    => 'app-secret',
			'refresh_token' => 'original-token',
			'base_path'     => 'backups',
			$field          => array(
				'key'     => '.mainwp-retention-' . str_repeat( 'a', 32 ),
				'id'      => 'id:original',
				'receipt' => 'invalid',
			),
		);
		$id       = $store->add_destination(
			array(
				'provider' => 'dropbox',
				'name'     => 'Before',
				'settings' => $settings,
			)
		);
		$calls    = 0;
		$blocked  = static function () use ( &$calls ) {
			++$calls;
			return new \WP_Error( 'blocked', 'HTTP blocked' );
		};
		add_filter( 'pre_http_request', $blocked );
		try {
			foreach ( array(
				null,
				array(
					'app_key'       => 'app-key',
					'app_secret'    => '',
					'refresh_token' => '',
					'base_path'     => 'backups',
				),
			) as $submitted ) {
				$result = MainWP_Backup_Storage_Handler::save_destination(
					array(
						'id'       => $id,
						'provider' => 'dropbox',
						'name'     => 'Renamed',
						'settings' => $submitted,
					)
				);
				$this->assertSame( 0, $calls, 'A rename must skip the connection test as well as marker verification.' );
				$this->assertSame( array( 'id' => $id ), $result );
				$row = $store->get_destination( $id );
				$this->assertSame( 'Renamed', $row->name );
				$this->assertSame( $settings, $row->settings );
			}
		} finally {
			remove_filter( 'pre_http_request', $blocked );
		}
	}

	/** Target edits rebuild proof; OAuth on the same account preserves its binding. */
	public function test_dropbox_target_change_and_same_account_oauth_verify_identity() {
		$objects   = array();
		$requests  = array();
		$transport = $this->retention_dropbox_transport( $objects, $requests );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			$store      = MainWP_Backup_Storage_Destinations::instance();
			$protection = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
			$settings   = array(
				'app_key'       => 'app-key',
				'app_secret'    => 'app-secret',
				'refresh_token' => 'original-token',
				'base_path'     => 'backups',
			);
			$id         = $store->add_destination(
				array(
					'provider' => 'dropbox',
					'name'     => 'Dropbox',
					'settings' => $settings,
				)
			);
			$row        = $store->prepare_retention_identity( $store->get_destination( $id ) );
			$binding    = $protection::destination_binding( $row );
			$identity   = $row->settings['_mainwp_retention_identity'];
			$start      = MainWP_Backup_Storage_Handler::start_dropbox_oauth(
				array(
					'id'       => $id,
					'name'     => 'Reconnected',
					'settings' => $settings,
				)
			);
			parse_str( wp_parse_url( $start['authorize_url'], PHP_URL_QUERY ), $params );
			$exchange = static function () {
				return array(
					'body'     => wp_json_encode( array( 'refresh_token' => 'original-rotated' ) ),
					'response' => array( 'code' => 200 ),
				);
			};
			$requests = array();
			$this->assertSame( array( 'id' => $id ), MainWP_Backup_Storage_Handler::complete_dropbox_oauth( $params['state'], 'code', $exchange ) );
			$this->assertNotEmpty( $requests );
			$row = $store->get_destination( $id );
			$this->assertSame( $binding, $protection::destination_binding( $row ) );
			$this->assertSame( $identity['key'], $row->settings['_mainwp_retention_identity']['key'] );
			$this->assertTrue( $protection::dropbox_identity_valid( $row->settings ) );
			$settings              = $row->settings;
			$settings['base_path'] = 'other-target';
			$requests              = array();
			$this->assertSame(
				array( 'id' => $id ),
				MainWP_Backup_Storage_Handler::save_destination(
					array(
						'id'       => $id,
						'provider' => 'dropbox',
						'name'     => 'Retargeted',
						'settings' => $settings,
					)
				)
			);
			$this->assertNotEmpty( $requests );
			$row = $store->get_destination( $id );
			$this->assertTrue( $protection::dropbox_identity_valid( $row->settings ) );
			$this->assertNotSame( $binding, $protection::destination_binding( $row ) );
		} finally {
			remove_filter( 'pre_http_request', $transport );
		}
	}
	/** Replacement credentials must resolve to the original server-issued file ID. */
	public function test_retention_dropbox_rotation_proves_namespace_and_keeps_identity_private() {
		$objects   = array();
		$requests  = array();
		$transport = $this->retention_dropbox_transport( $objects, $requests );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			$store      = MainWP_Backup_Storage_Destinations::instance();
			$protection = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
			$field      = '_mainwp_retention_identity';
			$settings   = array(
				'app_key'       => 'app-key',
				'app_secret'    => 'app-secret',
				'refresh_token' => 'original-token',
				'base_path'     => 'backups',
			);
			$input      = array(
				'provider' => 'dropbox',
				'name'     => 'Dropbox',
				'settings' => $settings,
			);
			$created    = MainWP_Backup_Storage_Handler::save_destination( $input );
			$this->assertArrayHasKey( 'id', $created );
			$input['id'] = $created['id'];
			$row         = $store->get_destination( $created['id'] );
			$this->assertArrayNotHasKey( $field, $row->settings, 'Default saves must not create retention marker objects.' );
			$this->assertEmpty( array_filter( $objects['original'] ?? array() ) );
			$this->assertTrue( method_exists( $store, 'prepare_retention_identity' ) );
			$row      = $store->prepare_retention_identity( $row );
			$identity = $row->settings[ $field ];
			$binding  = $protection::destination_binding( $row );
			$this->assertTrue( $protection::dropbox_identity_valid( $row->settings ) );
			$input['settings']['refresh_token'] = 'original-rotated';
			$input['settings'][ $field ]        = array(
				'key'     => 'forged',
				'id'      => 'id:forged',
				'receipt' => 'forged',
			);
			$this->assertArrayHasKey( 'id', MainWP_Backup_Storage_Handler::save_destination( $input ) );
			$row = $store->get_destination( $created['id'] );
			$this->assertSame( $binding, $protection::destination_binding( $row ) );
			$this->assertSame( $identity['key'], $row->settings[ $field ]['key'] );
			$this->assertArrayNotHasKey( $field, MainWP_Backup_Storage_Handler::destination_for_edit( $created['id'] )['settings'] );
			$public = \MainWP\Dashboard\MainWP_Backup_Read_Model::get_destination( $created['id'] );
			$this->assertStringNotContainsString( $identity['key'], wp_json_encode( $public ) );
			$input['settings']['refresh_token'] = 'other-account';
			$this->assertArrayHasKey( 'id', MainWP_Backup_Storage_Handler::save_destination( $input ) );
			$this->assertNotSame( $binding, $protection::destination_binding( $store->get_destination( $created['id'] ) ) );
			$input['settings']['refresh_token'] = 'original-returned';
			$this->assertArrayHasKey( 'id', MainWP_Backup_Storage_Handler::save_destination( $input ) );
			$this->assertSame( $binding, $protection::destination_binding( $store->get_destination( $created['id'] ) ) );
			$input['settings']['refresh_token'] = 'broken-token';
			$this->assertArrayHasKey( 'error', MainWP_Backup_Storage_Handler::save_destination( $input ) );
			$this->assertSame( 'original-returned', $store->get_destination( $created['id'] )->settings['refresh_token'] );
		} finally {
			remove_filter( 'pre_http_request', $transport );
		}
	}
	/**
	 * Seed a server-owned OAuth row without implementing the callback.
	 *
	 * @param array  $overrides Settings overrides.
	 * @param string $userid Stored account identity.
	 * @param string $folderid Stored folder identity.
	 * @return int
	 */
	private function pcloud_destination( array $overrides = array(), string $userid = '101', string $folderid = '230807' ): int {
		$settings = array_merge(
			array(
				'client_id'    => 'client',
				'access_token' => 'original',
				'locationid'   => 1,
				'base_path'    => 'MainWP Backups',
			),
			$overrides
		);
		$settings = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::with_pcloud_identity( $settings, $userid, $folderid );
		return MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'pcloud',
				'name'     => 'pCloud',
				'settings' => $settings,
			)
		);
	}

	/** Unknown providers must refuse secret carry-over and connection testing. */
	public function test_pcloud_handler_unknown_provider_fails_closed() {
		$same = new \ReflectionMethod( MainWP_Backup_Storage_Handler::class, 'same_credential_target' );
		$test = new \ReflectionMethod( MainWP_Backup_Storage_Handler::class, 'run_connection_test' );
		$this->assertFalse( $same->invoke( null, 'unknown', array(), array() ) );
		$this->assertFalse(
			$test->invoke(
				null,
				array(
					'provider' => 'unknown',
					'settings' => array(),
				)
			)['ok']
		);
		$settings = array(
			'client_id'  => 'client',
			'locationid' => 2,
		);
		$this->assertTrue( $same->invoke( null, 'pcloud', $settings, $settings ) );
		$this->assertFalse( $same->invoke( null, 'pcloud', array_merge( $settings, array( 'client_id' => 'other' ) ), $settings ) );
		$this->assertFalse( $same->invoke( null, 'pcloud', array_merge( $settings, array( 'locationid' => 1 ) ), $settings ) );
	}

	/** Metadata saves keep either region and even an invalid cached receipt without HTTP. */
	public function test_pcloud_us_eu_metadata_edits_keep_proof_with_zero_http() {
		$store     = MainWP_Backup_Storage_Destinations::instance();
		$calls     = 0;
		$transport = static function () use ( &$calls ) {
			++$calls;
			return new \WP_Error( 'outage', 'Offline' );
		};
		add_filter( 'pre_http_request', $transport );
		try {
			foreach ( array( 1, 2 ) as $region ) {
				foreach ( array( true, false ) as $valid ) {
					$id     = $this->pcloud_destination(
						array(
							'locationid'    => $region,
							'account_email' => 'owner@example.com',
						)
					);
					$before = $store->get_destination( $id )->settings;
					if ( ! $valid ) {
						$before['_mainwp_retention_identity']['receipt'] = 'invalid';
						$this->assertTrue( $store->update_destination( $id, array( 'settings' => $before ) ) );
					}
					$result = MainWP_Backup_Storage_Handler::save_destination(
						array(
							'id'         => $id,
							'provider'   => 'pcloud',
							'name'       => 'Renamed',
							'is_default' => 1,
							'settings'   => array(
								'client_id'    => ' client ',
								'access_token' => '',
								'locationid'   => 3 - $region,
								'base_path'    => '/MainWP Backups/',
								'minimum_remote_retention_days' => 30,
								'_mainwp_retention_identity' => array( 'userid' => 'forged' ),
							),
						)
					);
					$this->assertSame( array( 'id' => $id ), $result );
					$after = $store->get_destination( $id );
					$this->assertSame( 'Renamed', $after->name );
					$this->assertSame( $region, $after->settings['locationid'] );
					$this->assertSame( 'original', $after->settings['access_token'] );
					$this->assertSame( 'owner@example.com', $after->settings['account_email'] );
					$this->assertSame( 30, $after->settings['minimum_remote_retention_days'] );
					$this->assertSame( $before['_mainwp_retention_identity'], $after->settings['_mainwp_retention_identity'] );
				}
			}
			$this->assertSame( 0, $calls );
		} finally {
			remove_filter( 'pre_http_request', $transport );
		}
	}

	/** Client secrets never survive generic saves, including stored-settings-only saves. */
	public function test_pcloud_generic_save_drops_client_secret_and_redacts_secrets() {
		$transport = function () {
			$this->fail( 'Generic metadata saves must not make HTTP requests.' );
		};
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		add_filter( 'pre_http_request', $transport );
		try {
			$id    = $this->pcloud_destination( array( 'client_secret' => 'legacy-secret' ) ); // gitleaks:allow -- test fixture value, not a credential.
			$store = MainWP_Backup_Storage_Destinations::instance();
			$input = array(
				'id'       => $id,
				'provider' => 'pcloud',
				'name'     => 'pCloud',
			);
			$this->assertArrayNotHasKey( 'client_secret', MainWP_Backup_Storage_Handler::destination_for_edit( $id )['settings'] );
			$this->assertSame( array( 'id' => $id ), MainWP_Backup_Storage_Handler::save_destination( $input ) );
			$this->assertArrayNotHasKey( 'client_secret', $store->get_destination( $id )->settings );
			$input['settings'] = array(
				'client_id'     => 'client',
				'access_token'  => '',
				'client_secret' => 'submitted-secret', // gitleaks:allow -- test fixture value, not a credential.
				'base_path'     => 'MainWP Backups',
			);
			$this->assertSame( array( 'id' => $id ), MainWP_Backup_Storage_Handler::save_destination( $input ) );
			$this->assertArrayNotHasKey( 'client_secret', $store->get_destination( $id )->settings );
			$this->assertSame( 'original', $store->get_destination( $id )->settings['access_token'] );
			$public = MainWP_Backup_Storage_Handler::destination_for_edit( $id )['settings'];
			foreach ( array( 'access_token', 'client_secret', '_mainwp_retention_identity' ) as $field ) {
				$this->assertArrayNotHasKey( $field, $public );
			}
		} finally {
			remove_filter( 'pre_http_request', $transport );
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** The browser cannot name the app, region, token or code; submitted identity and region cannot create a row. */
	public function test_pcloud_browser_cannot_supply_app_region_token_or_code() {
		$merge = new \ReflectionMethod( MainWP_Backup_Storage_Handler::class, 'merge_candidate' );
		$id    = $this->pcloud_destination(
			array(
				'locationid'    => 2,
				'account_email' => 'owner@example.com',
			)
		);
		$input = array(
			'id'       => $id,
			'provider' => 'pcloud',
			'name'     => 'pCloud',
			'settings' => array(
				'client_id'          => 'other',
				'client_secret'      => 'browser-secret', // gitleaks:allow -- test fixture value, not a credential.
				'access_token'       => 'browser-token',
				'locationid'         => 1,
				'authorization_code' => 'browser-code',
				'account_email'      => 'browser@example.com',
				'base_path'          => 'MainWP Backups',
			),
		);
		$row   = $merge->invoke( null, $input );
		$this->assertSame( 'client', $row['settings']['client_id'] );
		$this->assertSame( 'original', $row['settings']['access_token'] );
		$this->assertSame( 2, $row['settings']['locationid'] );
		$this->assertSame( 'owner@example.com', $row['settings']['account_email'] );
		foreach ( array( 'client_secret', 'authorization_code' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $row['settings'] );
		}
		$calls     = 0;
		$transport = static function () use ( &$calls ) {
			++$calls;
			return new \WP_Error( 'unexpected', 'No HTTP expected.' );
		};
		add_filter( 'pre_http_request', $transport );
		try {
			$this->assertSame( array( 'id' => $id ), MainWP_Backup_Storage_Handler::save_destination( $input ) );
			$stored = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id )->settings;
			$this->assertSame( 'client', $stored['client_id'] );
			$this->assertSame( 'original', $stored['access_token'] );
			$this->assertSame( 2, $stored['locationid'] );
			$this->assertSame( 'owner@example.com', $stored['account_email'] );
			$this->assertArrayNotHasKey( 'authorization_code', $stored );
			$input['id'] = 0;
			$this->assertArrayNotHasKey( 'account_email', $merge->invoke( null, $input )['settings'] );
			$input['settings']['_mainwp_retention_identity'] = array(
				'userid'  => 'forged',
				'receipt' => 'forged',
			);
			$this->assertSame( 'Click Connect pCloud and paste the authorization code to finish adding this destination.', MainWP_Backup_Storage_Handler::save_destination( $input )['error'] );
			$this->assertSame( 'Click Connect pCloud and paste the authorization code to finish adding this destination.', MainWP_Backup_Storage_Handler::test_saved_or_submitted( $input )['error'] );
			$this->assertSame( 0, $calls );
		} finally {
			remove_filter( 'pre_http_request', $transport );
		}
	}

	/** An undecryptable pCloud row asks for a reconnect from both Save and Test, without HTTP. */
	public function test_pcloud_undecryptable_row_points_to_reconnect() {
		global $wpdb;

		$id = $this->pcloud_destination( array( 'locationid' => 2 ) );
		$wpdb->update( // phpcs:ignore WordPress.DB -- test fixture: corrupt the stored envelope.
			MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ),
			array( 'settings' => 'garbage-not-an-envelope' ),
			array( 'id' => $id )
		);
		$this->assertTrue( MainWP_Backup_Storage_Destinations::instance()->get_destination( $id, true )->credentials_invalid );

		$calls     = 0;
		$transport = function () use ( &$calls ) {
			++$calls;
			return new \WP_Error( 'unexpected', 'No HTTP expected.' );
		};
		add_filter( 'pre_http_request', $transport );
		try {
			$input = array(
				'id'       => $id,
				'provider' => 'pcloud',
				'name'     => 'Renamed pCloud',
				'settings' => array(
					'client_id' => 'client',
					'base_path' => 'MainWP Backups',
				),
			);
			$this->assertSame( array( 'error' => 'Click Connect pCloud and paste the authorization code to reconnect this destination.' ), MainWP_Backup_Storage_Handler::save_destination( $input ) );
			$this->assertSame( 'Click Connect pCloud and paste the authorization code to reconnect this destination.', MainWP_Backup_Storage_Handler::test_saved_or_submitted( $input )['error'] );
			$this->assertSame( 0, $calls );
		} finally {
			remove_filter( 'pre_http_request', $transport );
		}
	}

	/** Folder edits preserve blank credentials in either region and re-prove the target. */
	public function test_pcloud_us_eu_folder_edits_reprove_and_preserve_region() {
		// pCloud probe P2: stored bearer tokens authorize both region hosts.
		// pCloud probe P5: the connection probe reports its stored byte count.
		$state     = array();
		$transport = MainWP_PCloud_Transport_Fixture::transport( $state );
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			foreach ( array( 1, 2 ) as $region ) {
				$id = $this->pcloud_destination( array( 'locationid' => $region ) );
				$this->assertSame(
					array( 'id' => $id ),
					MainWP_Backup_Storage_Handler::save_destination(
						array(
							'id'       => $id,
							'provider' => 'pcloud',
							'name'     => 'Folder edit',
							'settings' => array(
								'client_id'    => 'client',
								'access_token' => '',
								'locationid'   => 3 - $region,
								'base_path'    => '/Other Backups/',
							),
						)
					)
				);
				$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
				$this->assertSame( $region, $row->settings['locationid'] );
				$this->assertSame( 'original', $row->settings['access_token'] );
				$this->assertSame( 'Other Backups', $row->settings['base_path'] );
				$this->assertTrue( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::pcloud_identity_valid( $row->settings ) );
				$requests = $state['requests'];
				$this->assertStringStartsWith( 1 === $region ? 'https://api.pcloud.com/' : 'https://eapi.pcloud.com/', end( $requests )['url'] );
			}
		} finally {
			remove_filter( 'pre_http_request', $transport );
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** A re-proof failure refuses the entire edit after a successful connection probe. */
	public function test_pcloud_save_proof_http_failure_leaves_stored_row_unchanged() {
		// pCloud probe P5: the write/read/delete probe succeeds before identity proof.
		$store = MainWP_Backup_Storage_Destinations::instance();
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		try {
			foreach ( array( 'userinfo', 'createfolderifnotexists' ) as $failure ) {
				$id                      = $this->pcloud_destination();
				$before                  = $store->get_destination( $id );
				$state                   = array();
				$transport               = MainWP_PCloud_Transport_Fixture::transport( $state );
				$state['before_request'] = static function ( $method ) use ( &$state, $failure ) {
					if ( 'userinfo' === $method ) {
						$state['fail_method'] = $failure;
					}
				};
				add_filter( 'pre_http_request', $transport, 10, 3 );
				try {
					$result = MainWP_Backup_Storage_Handler::save_destination(
						array(
							'id'       => $id,
							'provider' => 'pcloud',
							'name'     => 'Never saved',
							'settings' => $before->settings,
						),
						array(
							'locationid'   => 1,
							'access_token' => 'new-token',
						)
					);
					$this->assertArrayHasKey( 'error', $result );
					$this->assertEquals( $before, $store->get_destination( $id ) );
					$this->assertGreaterThanOrEqual( 5, count( $state['requests'] ) );
				} finally {
					remove_filter( 'pre_http_request', $transport );
				}
			}
		} finally {
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** The list-row Test reads stored region and credentials without posted settings. */
	public function test_pcloud_list_row_test_uses_stored_settings_only() {
		// pCloud probe P2: a bearer token authorizes the connection probe.
		// pCloud probe P5: uploadfile returns the probe byte count.
		$id        = $this->pcloud_destination( array( 'locationid' => 2 ) );
		$state     = array();
		$transport = MainWP_PCloud_Transport_Fixture::transport( $state );
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			$result = MainWP_Backup_Storage_Handler::test_saved_or_submitted(
				array(
					'id'       => $id,
					'provider' => 'pcloud',
					'name'     => 'pCloud',
				)
			);
			$this->assertTrue( $result['ok'] );
			$this->assertCount( 4, $state['requests'] );
			foreach ( $state['requests'] as $request ) {
				$this->assertStringStartsWith( 'https://eapi.pcloud.com/', $request['url'] );
				$this->assertSame( 'Bearer original', $request['args']['headers']['Authorization'] );
			}
		} finally {
			remove_filter( 'pre_http_request', $transport );
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** Reconnecting must re-prove a folder even when OAuth returns the stored token. */
	public function test_pcloud_oauth_save_with_identical_token_reproves_identity(): void {
		// pCloud probe P2: the stored bearer token authorizes a fresh identity check.
		// pCloud probe P5: the connection probe confirms its stored byte count.
		$store = MainWP_Backup_Storage_Destinations::instance();
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		try {
			foreach ( array( 1, 2 ) as $region ) {
				$id        = $this->pcloud_destination( array( 'locationid' => $region ) );
				$http      = array(
					'userid'   => '202',
					'folderid' => '230808',
				);
				$transport = MainWP_PCloud_Transport_Fixture::transport( $http );
				add_filter( 'pre_http_request', $transport, 10, 3 );
				try {
					$this->assertSame(
						array( 'id' => $id ),
						MainWP_Backup_Storage_Handler::save_destination(
							array(
								'id'       => $id,
								'provider' => 'pcloud',
								'name'     => 'pCloud',
								'settings' => array(
									'client_id' => 'client',
									'base_path' => 'MainWP Backups',
								),
							),
							array(
								'locationid'   => $region,
								'access_token' => 'original',
							)
						)
					);
					$this->assertCount( 6, $http['requests'] );
					$row = $store->get_destination( $id );
					$this->assertSame( 'original', $row->settings['access_token'] );
					$this->assertSame( '202', $row->settings['_mainwp_retention_identity']['userid'] );
					$this->assertSame( '230808', $row->settings['_mainwp_retention_identity']['folderid'] );
					$this->assertTrue( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::pcloud_identity_valid( $row->settings ) );
				} finally {
					remove_filter( 'pre_http_request', $transport, 10 );
				}
			}
		} finally {
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}
	/** A missing code, a missing name or an unconfigured app refuses before any pCloud request. */
	public function test_pcloud_connect_refusals_send_nothing(): void {
		$calls     = 0;
		$transport = static function () use ( &$calls ) {
			++$calls;
			return new \WP_Error( 'unexpected', 'No HTTP expected.' );
		};
		add_filter( 'pre_http_request', $transport );
		try {
			$input = array(
				'provider' => 'pcloud',
				'name'     => 'pCloud',
				'settings' => array( 'base_path' => 'Archive' ),
			);
			foreach ( array( '', '   ' ) as $code ) {
				$this->assertSame( array( 'error' => 'Paste the authorization code from pCloud, then save.' ), MainWP_Backup_Storage_Handler::connect_pcloud( $input, $code ) );
			}
			$this->assertSame( array( 'error' => 'A destination name is required.' ), MainWP_Backup_Storage_Handler::connect_pcloud( array_merge( $input, array( 'name' => '' ) ), 'code' ) );
			$this->assertSame(
				array( 'error' => 'Minimum retention must be a whole number from 0 to 36500 days.' ),
				MainWP_Backup_Storage_Handler::connect_pcloud( array_merge( $input, array( 'settings' => array( 'minimum_remote_retention_days' => -1 ) ) ), 'code' )
			);
			$this->assertSame( 0, $calls );
			$this->assertEmpty( MainWP_Backup_Storage_Destinations::instance()->list_destinations() );
		} finally {
			remove_filter( 'pre_http_request', $transport );
		}
	}

	/**
	 * Extend the shared transport with an OAuth response; identity still comes from userinfo.
	 *
	 * @param array $state Mutable pCloud fixture state.
	 * @return \Closure
	 */
	private function pcloud_connect_transport( array &$state ): \Closure {
		$fixture = MainWP_PCloud_Transport_Fixture::transport( $state );
		return static function ( $pre, $args, $url ) use ( &$state, $fixture ) {
			if ( '/oauth2_token' !== wp_parse_url( $url, PHP_URL_PATH ) ) {
				return $fixture( $pre, $args, $url );
			}
			$state['exchanges'] = ( $state['exchanges'] ?? 0 ) + 1;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'result'       => 0,
						'access_token' => str_repeat( 't', 52 ),
						'locationid'   => $state['locationid'] ?? 1,
						'userid'       => '999',
					)
				),
				'response' => array( 'code' => 200 ),
			);
		};
	}

	/** A changed account or region needs consent before any destination write. */
	public function test_pcloud_different_account_reconnect_stashes_without_saving(): void {
		$store = MainWP_Backup_Storage_Destinations::instance();
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		try {
			foreach ( array( array( 'userid' => '202' ), array( 'locationid' => 2 ) ) as $change ) {
				$id        = $this->pcloud_destination( array( 'account_email' => 'old@example.com' ) );
				$before    = $store->get_destination( $id );
				$state     = $change + array( 'email' => 'new@example.com' );
				$transport = $this->pcloud_connect_transport( $state );
				add_filter( 'pre_http_request', $transport, 10, 3 );
				try {
					$result = MainWP_Backup_Storage_Handler::connect_pcloud(
						array(
							'id'   => $id,
							'name' => 'Switch',
						),
						'one-use-code'
					);
					$this->assertArrayHasKey( 'error', $result );
					$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/D', $result['stash_key'] );
					$this->assertSame( 'old@example.com', $result['old_account_email'] );
					$this->assertSame( 'new@example.com', $result['new_account_email'] );
					$this->assertStringContainsString( 'old@example.com', $result['error'] );
					$this->assertStringContainsString( 'new@example.com', $result['error'] );
					$this->assertStringContainsString( esc_html( "Switching doesn't revoke this Dashboard's access to old@example.com in pCloud." ), $result['error'] );
					$this->assertStringNotContainsString( '%1$s', $result['error'] );
					$this->assertStringNotContainsString( '%2$s', $result['error'] );
					$this->assertStringContainsString( 'Linked Apps', $result['error'] );
					$this->assertStringNotContainsString( str_repeat( 't', 52 ), wp_json_encode( $result ) );
					$this->assertEquals( $before, $store->get_destination( $id ) );
					$key      = 'mainwp_pcloud_connect_' . $result['stash_key'];
					$envelope = get_transient( $key );
					$this->assertTrue( \MainWP\Dashboard\MainWP_Credential_Storage::is_credential_envelope( $envelope ) );
					$stash = json_decode( \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $envelope ), true );
					$this->assertSame( get_current_user_id(), $stash['user_id'] );
					$this->assertSame( $id, $stash['destination_id'] );
					$this->assertSame( str_repeat( 't', 52 ), $stash['oauth']['access_token'] );
					$this->assertSame( $state['userid'], $stash['oauth']['userid'] );
					$this->assertStringNotContainsString( 'one-use-code', wp_json_encode( $stash ) );
					$this->assertEqualsWithDelta( time() + 600, (int) get_option( '_transient_timeout_' . $key ), 2 );
					$this->assertCount( 1, $state['requests'] );
					MainWP_Backup_Storage_Handler::connect_pcloud( array( 'id' => $id + 1000 ), '', $result['stash_key'] );
				} finally {
					remove_filter( 'pre_http_request', $transport );
				}
			}
		} finally {
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** Exchanging a code revokes sibling tokens even while an account switch awaits consent. */
	public function test_pcloud_stashed_account_change_refreshes_siblings_before_confirmation(): void {
		$store                   = MainWP_Backup_Storage_Destinations::instance();
		$app                     = \MainWP\Dashboard\MainWP_Backup_Storage_PCloud::app_credentials()['client_id'];
		$id                      = $this->pcloud_destination( array( 'account_email' => 'old@example.com' ) );
		$edited                  = $store->get_destination( $id );
		$sibling                 = $this->pcloud_destination(
			array(
				'client_id'     => $app,
				'locationid'    => 2,
				'base_path'     => 'Sibling',
				'account_email' => 'keep@example.com',
			),
			'202',
			'230808'
		);
		$before                  = $store->get_destination( $sibling );
		$binding                 = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( $before );
		$state                   = array(
			'userid'     => '202',
			'locationid' => 2,
			'folders'    => array(
				''         => '0',
				'/Sibling' => '230808',
			),
		);
		$sibling_proofs          = 0;
		$state['before_request'] = static function ( $method ) use ( &$state, &$sibling_proofs ) {
			$request = end( $state['requests'] );
			parse_str( wp_parse_url( $request['url'], PHP_URL_QUERY ) ?? '', $query );
			if ( 'createfolderifnotexists' === $method && 'Sibling' === ( $query['name'] ?? '' ) ) {
				++$sibling_proofs;
			}
		};
		$transport               = $this->pcloud_connect_transport( $state );
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			$start = MainWP_Backup_Storage_Handler::connect_pcloud(
				array(
					'id'   => $id,
					'name' => 'Switch',
				),
				'code'
			);
			$this->assertArrayHasKey( 'stash_key', $start );
			$this->assertEquals( $edited, $store->get_destination( $id ) );
			$row = $store->get_destination( $sibling );
			$this->assertSame( str_repeat( 't', 52 ), $row->settings['access_token'] );
			$this->assertTrue( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::pcloud_identity_valid( $row->settings ) );
			$this->assertSame( $binding, \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( $row ) );
			$expected                 = $before->settings;
			$expected['access_token'] = str_repeat( 't', 52 );
			$expected['_mainwp_retention_identity']['receipt'] = $row->settings['_mainwp_retention_identity']['receipt'];
			$this->assertSame( $expected, $row->settings );
			$this->assertSame( 1, $sibling_proofs );
			$this->assertSame( array( 'id' => $id ), MainWP_Backup_Storage_Handler::connect_pcloud( array( 'id' => $id ), '', $start['stash_key'] ) );
			$this->assertSame( 1, $sibling_proofs, 'Confirmation must not refresh siblings a second time.' );
			$this->assertSame( 1, $state['exchanges'] );
		} finally {
			remove_filter( 'pre_http_request', $transport );
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** A failed connection test or main identity proof cannot leave sibling tokens revoked. */
	public function test_pcloud_failed_save_after_exchange_still_refreshes_siblings(): void {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$app   = \MainWP\Dashboard\MainWP_Backup_Storage_PCloud::app_credentials()['client_id'];
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		try {
			foreach ( array( 'uploadfile', 'userinfo' ) as $failure ) {
				$id                      = $this->pcloud_destination( array(), '202' );
				$edited                  = $store->get_destination( $id );
				$sibling                 = $this->pcloud_destination(
					array(
						'client_id' => $app,
						'base_path' => 'Sibling',
					),
					'202',
					'230808'
				);
				$before                  = $store->get_destination( $sibling );
				$binding                 = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( $before );
				$state                   = array(
					'userid'  => '202',
					'folders' => array(
						''         => '0',
						'/Sibling' => '230808',
					),
				);
				$state['before_request'] = static function ( $method ) use ( &$state, $failure ) {
					$request = end( $state['requests'] );
					parse_str( wp_parse_url( $request['url'], PHP_URL_QUERY ) ?? '', $query );
					if ( 'createfolderifnotexists' === $method && 'Failing' === ( $query['name'] ?? '' ) ) {
						$state['main_started'] = true;
					}
					$state['fail_method'] = empty( $state['main_started'] ) ? '' : $failure;
				};
				$transport               = $this->pcloud_connect_transport( $state );
				add_filter( 'pre_http_request', $transport, 10, 3 );
				try {
					$result = MainWP_Backup_Storage_Handler::connect_pcloud(
						array(
							'id'       => $id,
							'name'     => 'Failed save',
							'settings' => array( 'base_path' => 'Failing' ),
						),
						'code'
					);
					$this->assertArrayHasKey( 'error', $result, $failure );
					$this->assertArrayNotHasKey( 'stash_key', $result );
					$this->assertStringContainsString( "ended this destination's earlier connection", html_entity_decode( $result['error'], ENT_QUOTES ), $failure );
					$this->assertTrue( $state['main_started'] );
					$this->assertSame( 1, $state['exchanges'] );
					$this->assertEquals( $edited, $store->get_destination( $id ) );
					$row = $store->get_destination( $sibling );
					$this->assertSame( str_repeat( 't', 52 ), $row->settings['access_token'] );
					$this->assertTrue( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::pcloud_identity_valid( $row->settings ) );
					$this->assertSame( $binding, \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( $row ) );
				} finally {
					remove_filter( 'pre_http_request', $transport );
				}
			}
		} finally {
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** A new destination has no earlier connection for a failed save to warn about. */
	public function test_pcloud_failed_save_for_new_destination_has_no_earlier_connection_warning(): void {
		$state                   = array( 'userid' => '202' );
		$state['before_request'] = static function ( $method ) use ( &$state ) {
			if ( 'createfolderifnotexists' === $method ) {
				$state['fail_method'] = 'uploadfile';
			}
		};
		$transport               = $this->pcloud_connect_transport( $state );
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			$result = MainWP_Backup_Storage_Handler::connect_pcloud(
				array(
					'name'     => 'New',
					'settings' => array( 'base_path' => 'Failing' ),
				),
				'code'
			);
		} finally {
			remove_filter( 'pre_http_request', $transport );
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 1, $state['exchanges'] );
		$this->assertStringNotContainsString( 'earlier connection', html_entity_decode( $result['error'], ENT_QUOTES ) );
	}

	/** Confirming uses the spent code's token once and re-proves the new target. */
	public function test_pcloud_account_change_confirmation_saves_once(): void {
		$id        = $this->pcloud_destination( array( 'account_email' => 'old@example.com' ) );
		$state     = array(
			'userid'     => '202',
			'email'      => 'new@example.com',
			'locationid' => 2,
			'folderid'   => '230808',
		);
		$transport = $this->pcloud_connect_transport( $state );
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			$input = array(
				'id'       => $id,
				'name'     => 'Confirmed account',
				'settings' => array(
					'base_path'                     => 'Archive',
					'minimum_remote_retention_days' => 30,
				),
			);
			$start = MainWP_Backup_Storage_Handler::connect_pcloud( $input, 'code' );
			$this->assertSame( array( 'id' => $id ), MainWP_Backup_Storage_Handler::connect_pcloud( array( 'id' => $id ), '', $start['stash_key'] ) );
			$this->assertFalse( get_transient( 'mainwp_pcloud_connect_' . $start['stash_key'] ) );
			$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
			$this->assertSame( 'Confirmed account', $row->name );
			$this->assertSame( 'Archive', $row->settings['base_path'] );
			$this->assertSame( 30, $row->settings['minimum_remote_retention_days'] );
			$this->assertSame( 'new@example.com', $row->settings['account_email'] );
			$this->assertSame( str_repeat( 't', 52 ), $row->settings['access_token'] );
			$this->assertSame( 2, $row->settings['locationid'] );
			$this->assertSame( '202', $row->settings['_mainwp_retention_identity']['userid'] );
			$this->assertSame( '230808', $row->settings['_mainwp_retention_identity']['folderid'] );
			$this->assertTrue( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::pcloud_identity_valid( $row->settings ) );
			$requests = count( $state['requests'] );
			$this->assertArrayHasKey( 'error', MainWP_Backup_Storage_Handler::connect_pcloud( $input, '', $start['stash_key'] ) );
			$this->assertSame( $requests, count( $state['requests'] ) );
			$this->assertSame( 1, $state['exchanges'] );
		} finally {
			remove_filter( 'pre_http_request', $transport );
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** A confirmation cannot overwrite an account another admin connected while the dialog was open. */
	public function test_pcloud_confirmation_refuses_destination_changed_since_dialog(): void {
		$store     = MainWP_Backup_Storage_Destinations::instance();
		$state     = array( 'userid' => '202' );
		$transport = $this->pcloud_connect_transport( $state );
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			foreach ( array( 'reconnected', 'region', 'deleted' ) as $change ) {
				$id    = $this->pcloud_destination();
				$input = array(
					'id'   => $id,
					'name' => 'Switch',
				);
				$start = MainWP_Backup_Storage_Handler::connect_pcloud( $input, 'code' );
				$this->assertArrayHasKey( 'stash_key', $start, $change );
				$row      = $store->get_destination( $id );
				$settings = $row->settings;
				if ( 'reconnected' === $change ) {
					$settings = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::with_pcloud_identity( $settings, '303', '230809' );
					$store->update_destination( $id, array( 'settings' => $settings ) );
				} elseif ( 'region' === $change ) {
					$settings['locationid'] = 2;
					$store->update_destination( $id, array( 'settings' => $settings ) );
				} else {
					$store->delete_destination( $id );
				}
				$before   = $store->get_destination( $id );
				$requests = count( $state['requests'] );
				$result   = MainWP_Backup_Storage_Handler::connect_pcloud( $input, '', $start['stash_key'] );
				$expected = 'deleted' === $change ? 'The storage destination no longer exists.' : 'This destination changed before your connection was saved. Click Connect pCloud and paste a new code.';
				$this->assertSame( $expected, $result['error'] ?? null, $change );
				$this->assertArrayNotHasKey( 'stash_key', $result, $change );
				$this->assertEquals( $before, $store->get_destination( $id ), $change );
				$this->assertSame( $requests, count( $state['requests'] ), $change );
				$this->assertFalse( get_transient( 'mainwp_pcloud_connect_' . $start['stash_key'] ), $change );
			}
		} finally {
			remove_filter( 'pre_http_request', $transport );
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** A same-account reconnect cannot overwrite an account another admin connected during its requests. */
	public function test_pcloud_same_account_reconnect_refuses_account_switched_mid_request(): void {
		$store                   = MainWP_Backup_Storage_Destinations::instance();
		$id                      = $this->pcloud_destination();
		$state                   = array( 'userid' => '101' );
		$switched                = false;
		$state['before_request'] = static function ( $method ) use ( $store, $id, &$switched ) {
			if ( $switched || 'userinfo' !== $method ) {
				return;
			}
			$switched = true;
			$row      = $store->get_destination( $id );
			$store->update_destination( $id, array( 'settings' => \MainWP\Dashboard\MainWP_Backup_Retention_Protection::with_pcloud_identity( $row->settings, '303', '230809' ) ) );
		};
		$transport               = $this->pcloud_connect_transport( $state );
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			$result = MainWP_Backup_Storage_Handler::connect_pcloud(
				array(
					'id'   => $id,
					'name' => 'Reconnect',
				),
				'code'
			);
			$this->assertTrue( $switched );
			$this->assertSame( array( 'error' => 'This destination changed before your connection was saved. Click Connect pCloud and paste a new code.' ), $result );
			$row = $store->get_destination( $id );
			$this->assertSame( '303', $row->settings['_mainwp_retention_identity']['userid'] );
			$this->assertSame( 'original', $row->settings['access_token'] );
			$this->assertSame( 'pCloud', $row->name );
		} finally {
			remove_filter( 'pre_http_request', $transport );
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** Missing, expired and transferred confirmations cannot save or exchange another code. */
	public function test_pcloud_confirmation_is_user_destination_bound_and_expires(): void {
		$store     = MainWP_Backup_Storage_Destinations::instance();
		$id        = $this->pcloud_destination();
		$other_id  = $this->pcloud_destination();
		$before    = $store->get_destination( $id );
		$other     = $store->get_destination( $other_id );
		$user_id   = get_current_user_id();
		$state     = array( 'userid' => '202' );
		$transport = $this->pcloud_connect_transport( $state );
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			foreach ( array( 'user', 'destination', 'expired', 'missing', 'invalid' ) as $failure ) {
				$start = MainWP_Backup_Storage_Handler::connect_pcloud(
					array(
						'id'   => $id,
						'name' => 'Switch',
					),
					'code'
				);
				$key   = 'mainwp_pcloud_connect_' . $start['stash_key'];
				if ( 'user' === $failure ) {
					wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
				} elseif ( 'expired' === $failure ) {
					update_option( '_transient_timeout_' . $key, time() - 1 );
				} elseif ( 'missing' === $failure ) {
					delete_transient( $key );
				}
				$requests  = count( $state['requests'] );
				$exchanges = $state['exchanges'];
				$result    = MainWP_Backup_Storage_Handler::connect_pcloud(
					array( 'id' => 'destination' === $failure ? $other_id : $id ),
					'',
					'invalid' === $failure ? 'invalid!' : $start['stash_key']
				);
				$this->assertArrayHasKey( 'error', $result, $failure );
				$this->assertSame( 'This confirmation expired or was already used. Click Connect pCloud and paste a new code.', $result['error'] );
				$this->assertSame( $requests, count( $state['requests'] ) );
				$this->assertSame( $exchanges, $state['exchanges'] );
				wp_set_current_user( $user_id );
				if ( 'invalid' === $failure ) {
					MainWP_Backup_Storage_Handler::connect_pcloud( array( 'id' => $other_id ), '', $start['stash_key'] );
				}
				$this->assertFalse( get_transient( $key ) );
				$this->assertArrayHasKey( 'error', MainWP_Backup_Storage_Handler::connect_pcloud( array( 'id' => $id ), '', $start['stash_key'] ) );
				$this->assertEquals( $before, $store->get_destination( $id ) );
				$this->assertEquals( $other, $store->get_destination( $other_id ) );
			}
		} finally {
			wp_set_current_user( $user_id );
			remove_filter( 'pre_http_request', $transport );
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** A failed proof consumes the confirmation while preserving the stored destination. */
	public function test_pcloud_confirmation_failure_keeps_row_and_spends_stash(): void {
		$id        = $this->pcloud_destination();
		$before    = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
		$state     = array( 'userid' => '202' );
		$transport = $this->pcloud_connect_transport( $state );
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			$input                = array(
				'id'   => $id,
				'name' => 'Switch',
			);
			$start                = MainWP_Backup_Storage_Handler::connect_pcloud( $input, 'code' );
			$state['fail_method'] = 'userinfo';
			$this->assertArrayHasKey( 'error', MainWP_Backup_Storage_Handler::connect_pcloud( $input, '', $start['stash_key'] ) );
			$this->assertEquals( $before, MainWP_Backup_Storage_Destinations::instance()->get_destination( $id ) );
			$this->assertFalse( get_transient( 'mainwp_pcloud_connect_' . $start['stash_key'] ) );
			$this->assertSame( 1, $state['exchanges'] );
		} finally {
			remove_filter( 'pre_http_request', $transport );
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** Same-account reconnects need no confirmation and retain the upload binding. */
	public function test_pcloud_same_account_reconnect_preserves_binding(): void {
		$id        = $this->pcloud_destination();
		$store     = MainWP_Backup_Storage_Destinations::instance();
		$binding   = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( $store->get_destination( $id ) );
		$state     = array();
		$transport = $this->pcloud_connect_transport( $state );
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			$this->assertSame(
				array( 'id' => $id ),
				MainWP_Backup_Storage_Handler::connect_pcloud(
					array(
						'id'   => $id,
						'name' => 'Reconnect',
					),
					'code'
				)
			);
			$row = $store->get_destination( $id );
			$this->assertSame( $binding, \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( $row ) );
			$this->assertSame( str_repeat( 't', 52 ), $row->settings['access_token'] );
			$this->assertTrue( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::pcloud_identity_valid( $row->settings ) );
		} finally {
			remove_filter( 'pre_http_request', $transport );
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** Sibling tokens need new receipts, but their proven account and folder must stay the same. */
	public function test_pcloud_connect_refreshes_only_reproved_siblings_and_keeps_binding(): void {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$app   = \MainWP\Dashboard\MainWP_Backup_Storage_PCloud::app_credentials()['client_id'];
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		try {
			foreach ( array( false, true ) as $reconnect ) {
				$sibling = $this->pcloud_destination(
					array(
						'client_id'     => $app,
						'base_path'     => 'Sibling',
						'account_email' => 'keep@example.com',
					),
					'101',
					'230808'
				);
				$store->update_destination( $sibling, array( 'name' => 'Z sibling' ) );
				$before    = $store->get_destination( $sibling );
				$binding   = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( $before );
				$untouched = array(
					$this->pcloud_destination( array( 'client_id' => $app ), '202' ),
					$this->pcloud_destination(
						array(
							'client_id'  => $app,
							'locationid' => 2,
						)
					),
					$this->pcloud_destination( array( 'client_id' => 'other-app' ) ),
					$this->pcloud_destination(
						array(
							'client_id' => $app,
							'base_path' => 'Moved',
						),
						'101',
						'230809'
					),
					$this->pcloud_destination(
						array(
							'client_id' => $app,
							'base_path' => 'Unavailable',
						)
					),
				);
				foreach ( $untouched as $untouched_id ) {
					$store->update_destination( $untouched_id, array( 'name' => 'A unchanged ' . $untouched_id ) );
				}
				$originals               = array_map( array( $store, 'get_destination' ), $untouched );
				$id                      = $reconnect ? $this->pcloud_destination( array( 'client_id' => $app ) ) : 0;
				$state                   = array(
					'folders' => array(
						''         => '0',
						'/Sibling' => '230808',
						'/Moved'   => '230810',
					),
				);
				$state['before_request'] = static function ( $method ) use ( &$state ) {
					$request = end( $state['requests'] );
					parse_str( wp_parse_url( $request['url'], PHP_URL_QUERY ) ?? '', $query );
					$state['fail_method'] = 'createfolderifnotexists' === $method && 'Unavailable' === ( $query['name'] ?? '' ) ? $method : '';
				};
				$transport               = $this->pcloud_connect_transport( $state );
				add_filter( 'pre_http_request', $transport, 10, 3 );
				try {
					$result = MainWP_Backup_Storage_Handler::connect_pcloud(
						array(
							'id'       => $id,
							'name'     => 'Connect',
							'settings' => array( 'base_path' => 'MainWP Backups' ),
						),
						'code'
					);
					$this->assertArrayHasKey( 'id', $result );
					$row = $store->get_destination( $sibling );
					$this->assertSame( str_repeat( 't', 52 ), $row->settings['access_token'] );
					$this->assertSame( $binding, \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( $row ) );
					$this->assertTrue( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::pcloud_identity_valid( $row->settings ) );
					$this->assertNotSame( $before->settings['_mainwp_retention_identity']['receipt'], $row->settings['_mainwp_retention_identity']['receipt'] );
					$expected                 = $before->settings;
					$expected['access_token'] = str_repeat( 't', 52 );
					$expected['_mainwp_retention_identity']['receipt'] = $row->settings['_mainwp_retention_identity']['receipt'];
					$this->assertSame( $expected, $row->settings );
					$this->assertSame( $before->name, $row->name );
					$this->assertSame( $before->is_default, $row->is_default );
					foreach ( $untouched as $index => $untouched_id ) {
						$this->assertEquals( $originals[ $index ], $store->get_destination( $untouched_id ) );
					}
				} finally {
					remove_filter( 'pre_http_request', $transport );
				}
			}
		} finally {
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** A token must prove the sibling's account before it can resolve or create a folder. */
	public function test_pcloud_sibling_account_mismatch_keeps_row_unchanged(): void {
		$store                   = MainWP_Backup_Storage_Destinations::instance();
		$app                     = \MainWP\Dashboard\MainWP_Backup_Storage_PCloud::app_credentials()['client_id'];
		$sibling                 = $this->pcloud_destination( array( 'client_id' => $app ) );
		$before                  = $store->get_destination( $sibling );
		$state                   = array();
		$checks                  = 0;
		$state['before_request'] = static function ( $method ) use ( &$state, &$checks ) {
			if ( 'userinfo' === $method ) {
				++$checks;
				$state['userid'] = 2 === $checks ? '202' : '101';
			}
		};
		$transport               = $this->pcloud_connect_transport( $state );
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		add_filter( 'pre_http_request', $transport, 10, 3 );
		try {
			$result = MainWP_Backup_Storage_Handler::connect_pcloud( array( 'name' => 'New connection' ), 'code' );
			$this->assertArrayHasKey( 'id', $result );
			$this->assertSame( 3, $checks );
			$this->assertEquals( $before, $store->get_destination( $sibling ) );
			$folders = array_filter(
				$state['requests'],
				static function ( $request ) {
					return '/createfolderifnotexists' === wp_parse_url( $request['url'], PHP_URL_PATH );
				}
			);
			$this->assertCount( 2, $folders, 'Only the main connection may resolve the folder after the sibling account mismatch.' );
		} finally {
			remove_filter( 'pre_http_request', $transport );
			remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		}
	}

	/** Registering via the post-base handler provides both the AJAX hook and its nonce. */
	public function test_pcloud_connect_ajax_and_nonce_are_registered(): void {
		$handler = MainWP_Backup_Storage_Handler::instance();
		$handler->init();
		$this->assertNotFalse( has_action( 'wp_ajax_mainwp_pcloud_connect', array( $handler, 'ajax_pcloud_connect' ) ) );
		$nonces = $handler->create_security_nonces();
		$this->assertNotFalse( wp_verify_nonce( $nonces['mainwp_pcloud_connect'], 'mainwp_pcloud_connect' ) );
	}

	/** The pCloud form takes a folder and a pasted code, never app credentials, and lists the region without secrets. */
	public function test_pcloud_form_offers_connect_and_code_fields(): void {
		$this->pcloud_destination(
			array(
				'locationid'    => 2,
				'access_token'  => 'private-render-token',
				'client_secret' => 'private-render-secret', // gitleaks:allow -- test fixture value, not a credential.
				'account_email' => 'owner@example.com',
			)
		);
		ob_start();
		MainWP_Backup_Storage_View::render_form();
		$html = ob_get_clean();
		foreach ( array( 'data-provider="pcloud"', 'mainwp-storage-pcloud-base-path', 'mainwp-storage-pcloud-connect', 'mainwp-storage-pcloud-authorization-code' ) as $expected ) {
			$this->assertStringContainsString( $expected, $html );
		}
		$this->assertStringContainsString( 'pCloud allows one MainWP connection per account. Connecting the same pCloud account from another Dashboard disconnects it here.', $html );
		foreach ( array( 'mainwp-storage-pcloud-client', 'mainwp-storage-pcloud-redirect', 'mainwp-storage-pcloud-copy', 'docs.pcloud.com/my_apps', 'redirect_uri', 'private-render-secret', 'mainwp-storage-pcloud-access-token', 'mainwp-storage-pcloud-locationid' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $html );
		}
		$order  = array_map(
			static function ( $id ) use ( $html ) {
				return strpos( $html, 'id="' . $id . '"' );
			},
			array( 'mainwp-storage-pcloud-base-path', 'mainwp-storage-minimum-remote-retention-days', 'mainwp-storage-pcloud-connect', 'mainwp-storage-pcloud-authorization-code', 'mainwp-storage-save' )
		);
		$sorted = $order;
		sort( $sorted );
		$this->assertSame( $sorted, $order );
		$this->assertStringNotContainsString( 'pCloud connections are not available on this Dashboard yet.', $html );
		$dom = new \DOMDocument();
		@$dom->loadHTML( $html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- HTML5 markup warnings.
		$connect = $dom->getElementById( 'mainwp-storage-pcloud-connect' );
		$this->assertSame( 'a', $connect->tagName );
		$this->assertFalse( $connect->hasAttribute( 'disabled' ) );
		$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Storage_PCloud::authorize_url(), $connect->getAttribute( 'href' ) );
		ob_start();
		MainWP_Backup_Storage_View::render_list_table();
		$list = ob_get_clean();
		$this->assertStringContainsString( 'pCloud (EU): /MainWP Backups', $list );
		$this->assertStringContainsString( '<div class="description"><span class="ui small text">' . esc_html( 'Connected as owner@example.com' ) . '</span></div>', $list );
		$this->assertStringNotContainsString( 'private-render-token', $list );
		$this->assertStringNotContainsString( 'private-render-secret', $list );
		ob_start();
		MainWP_Backup_Storage_View::render_list_table( true );
		$restricted = ob_get_clean();
		$this->assertStringNotContainsString( 'Connected as', $restricted );
		$this->assertStringNotContainsString( 'owner@example.com', $restricted );
	}
}
