<?php
/**
 * MainWP Backup Storage Destinations Tests
 *
 * Verifies CRUD over the storage destinations table: settings encrypt on
 * write and decrypt on single-row reads, list views never expose
 * settings, the default flag stays unique, and an undecryptable settings
 * blob (AUTH_KEY rotation, tampering) surfaces as a re-enter-credentials
 * state instead of garbage.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Credential_Storage;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Keys_Manager;

/**
 * Class MainWP_Backup_Storage_Destinations_Test
 */
class MainWP_Backup_Storage_Destinations_Test extends \WP_UnitTestCase {

	/**
	 * Start from an empty destinations table: DDL in other suites (the db
	 * upgrade tests run install()) implicitly commits its transaction, so
	 * rows can leak across tests and even across runs.
	 */
	public function set_up() {
		parent::set_up();
		$this->truncate_destinations();
	}

	/**
	 * Drop rows this test may have committed past its transaction.
	 */
	public function tear_down() {
		$this->truncate_destinations();
		parent::tear_down();
	}

	/**
	 * Hard-delete all destination rows.
	 *
	 * @return void
	 */
	private function truncate_destinations() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ) ); // phpcs:ignore WordPress.DB -- test cleanup.
	}

	/**
	 * Representative S3 settings with a secret.
	 *
	 * @return array
	 */
	private function s3_settings() {
		return array(
			'bucket'     => 'example-bucket',
			'region'     => 'us-east-1',
			'access_key' => 'AKIAIOSFODNN7EXAMPLE',
			'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
		);
	}

	/**
	 * The raw settings column for a destination.
	 *
	 * @param int $id Destination id.
	 *
	 * @return string Raw column value.
	 */
	private function raw_settings( $id ) {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- raw column probe, identifiers cannot be prepared.
	}

	/**
	 * The key-file name referenced by a stored credential envelope.
	 *
	 * @param string $stored Raw column value.
	 *
	 * @return string Key-file name, '' when not an envelope.
	 */
	private function envelope_file_key( $stored ) {
		if ( ! MainWP_Credential_Storage::is_credential_envelope( $stored ) ) {
			return '';
		}
		$envelope = json_decode( substr( $stored, strlen( MainWP_Credential_Storage::ENVELOPE_PREFIX ) ), true );
		return is_array( $envelope ) && ! empty( $envelope['file_key'] ) ? (string) $envelope['file_key'] : '';
	}

	/**
	 * Absolute path of a key file.
	 *
	 * @param string $file_key Key-file name.
	 *
	 * @return string Path.
	 */
	private function key_file_path( $file_key ) {
		return MainWP_Keys_Manager::get_keys_dir() . $file_key;
	}

	/**
	 * Add a destination and read it back with settings decrypted.
	 *
	 * @return void
	 */
	public function test_add_and_get_round_trip() {
		$store = MainWP_Backup_Storage_Destinations::instance();

		$id = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Primary S3',
				'settings' => $this->s3_settings(),
			)
		);

		$this->assertGreaterThan( 0, $id );

		$row = $store->get_destination( $id );

		$this->assertNotNull( $row );
		$this->assertSame( 's3', $row->provider );
		$this->assertSame( 'Primary S3', $row->name );
		$this->assertSame( 0, (int) $row->is_default );
		$this->assertSame( $this->s3_settings(), $row->settings );
		$this->assertFalse( $row->credentials_invalid );
	}

	/**
	 * The stored settings column holds an encrypted envelope with no
	 * plaintext secret in it.
	 *
	 * @return void
	 */
	public function test_settings_are_encrypted_at_rest() {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'At rest',
				'settings' => $this->s3_settings(),
			)
		);

		$raw = $this->raw_settings( $id );

		$this->assertTrue( MainWP_Credential_Storage::is_credential_envelope( $raw ), 'Settings column must hold a credential envelope - the same system every other MainWP secret uses.' );
		$this->assertStringNotContainsString( 'wJalrXUtnFEMI', $raw );
		$this->assertStringNotContainsString( 'AKIAIOSFODNN7EXAMPLE', $raw );
		$this->assertStringNotContainsString( 'example-bucket', $raw );

		$file_key = $this->envelope_file_key( $raw );
		$this->assertNotSame( '', $file_key );
		$this->assertFileExists( $this->key_file_path( $file_key ), 'The per-secret key material lives in pk/, not the database.' );
	}

	/**
	 * Updating settings re-encrypts; updating other fields leaves
	 * settings intact.
	 *
	 * @return void
	 */
	public function test_update_destination() {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Before',
				'settings' => $this->s3_settings(),
			)
		);

		$this->assertTrue( $store->update_destination( $id, array( 'name' => 'After' ) ) );
		$row = $store->get_destination( $id );
		$this->assertSame( 'After', $row->name );
		$this->assertSame( $this->s3_settings(), $row->settings, 'Settings must survive a name-only update.' );

		$new_settings           = $this->s3_settings();
		$new_settings['bucket'] = 'rotated-bucket';
		$this->assertTrue( $store->update_destination( $id, array( 'settings' => $new_settings ) ) );
		$this->assertSame( $new_settings, $store->get_destination( $id )->settings );
	}

	/**
	 * Deleting a destination removes the row.
	 *
	 * @return void
	 */
	public function test_delete_destination() {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Doomed',
				'settings' => array( 'base_path' => '/tmp/x' ),
			)
		);

		$this->assertTrue( $store->delete_destination( $id ) );
		$this->assertNull( $store->get_destination( $id ) );
	}

	/**
	 * The list view exposes no settings, encrypted or otherwise.
	 *
	 * @return void
	 */
	public function test_list_destinations_excludes_settings() {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Listed',
				'settings' => $this->s3_settings(),
			)
		);

		$rows = $store->list_destinations();

		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertObjectNotHasProperty( 'settings', $row, 'List rows must not carry settings.' );
		}
	}

	/**
	 * Marking a destination default clears the previous default.
	 *
	 * @return void
	 */
	public function test_default_flag_is_unique() {
		$store = MainWP_Backup_Storage_Destinations::instance();

		$first = $store->add_destination(
			array(
				'provider'   => 'local',
				'name'       => 'First default',
				'settings'   => array( 'base_path' => '/tmp/a' ),
				'is_default' => 1,
			)
		);

		$second = $store->add_destination(
			array(
				'provider'   => 'local',
				'name'       => 'Second default',
				'settings'   => array( 'base_path' => '/tmp/b' ),
				'is_default' => 1,
			)
		);

		$this->assertSame( 0, (int) $store->get_destination( $first )->is_default );
		$this->assertSame( 1, (int) $store->get_destination( $second )->is_default );
		$this->assertSame( $second, (int) $store->get_default()->id );
	}

	/**
	 * An undecryptable settings blob surfaces as credentials_invalid with
	 * null settings, never garbage. Losing the pk/ key file is the failure
	 * mode under the credential envelope system.
	 *
	 * @return void
	 */
	public function test_undecryptable_settings_surface_reentry_state() {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Lost key file',
				'settings' => $this->s3_settings(),
			)
		);

		$file_key = $this->envelope_file_key( $this->raw_settings( $id ) );
		$this->assertNotSame( '', $file_key );
		MainWP_Keys_Manager::instance()->delete_key_file( $file_key );

		$row = $store->get_destination( $id );

		$this->assertNotNull( $row, 'The destination row itself must still load.' );
		$this->assertNull( $row->settings );
		$this->assertTrue( $row->credentials_invalid );
	}

	/**
	 * Settings are never plaintext-passthrough: a non-envelope value -
	 * plain JSON or the removed pre-release format - is treated as
	 * unreadable, not as live credentials.
	 *
	 * @return void
	 */
	public function test_non_envelope_settings_marked_invalid() {
		global $wpdb;

		$store = MainWP_Backup_Storage_Destinations::instance();
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );

		foreach ( array(
			'plain json'         => (string) wp_json_encode( $this->s3_settings() ),
			'pre-release format' => 'mwpenc-gcm1:bm90LXJlYWwtY2lwaGVydGV4dA==',
		) as $label => $stored ) {
			$id = $store->add_destination(
				array(
					'provider' => 's3',
					'name'     => 'Garbage column',
					'settings' => $this->s3_settings(),
				)
			);

			$wpdb->update( $table, array( 'settings' => $stored ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.

			$row = $store->get_destination( $id );

			$this->assertNull( $row->settings, "A {$label} value must not surface as settings." );
			$this->assertTrue( $row->credentials_invalid, "A {$label} value must surface the re-enter state." );
		}
	}

	/**
	 * Deleting a destination removes its key file from pk/ - no orphaned
	 * key material for a secret that no longer exists.
	 *
	 * @return void
	 */
	public function test_delete_destination_removes_key_file() {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Key hygiene',
				'settings' => $this->s3_settings(),
			)
		);

		$file_key = $this->envelope_file_key( $this->raw_settings( $id ) );
		$this->assertFileExists( $this->key_file_path( $file_key ) );

		$this->assertTrue( $store->delete_destination( $id ) );

		$this->assertFileDoesNotExist( $this->key_file_path( $file_key ), 'The key file must not outlive its destination.' );
	}

	/**
	 * Re-encrypting settings on update retires the previous key file.
	 *
	 * @return void
	 */
	public function test_settings_update_replaces_key_file() {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Rotating',
				'settings' => $this->s3_settings(),
			)
		);

		$old_file_key = $this->envelope_file_key( $this->raw_settings( $id ) );
		$this->assertFileExists( $this->key_file_path( $old_file_key ) );

		$new_settings           = $this->s3_settings();
		$new_settings['bucket'] = 'rotated-bucket';
		$this->assertTrue( $store->update_destination( $id, array( 'settings' => $new_settings ) ) );

		$new_file_key = $this->envelope_file_key( $this->raw_settings( $id ) );
		$this->assertNotSame( $old_file_key, $new_file_key );
		$this->assertFileExists( $this->key_file_path( $new_file_key ) );
		$this->assertFileDoesNotExist( $this->key_file_path( $old_file_key ), 'The superseded key file must be retired.' );
	}

	/**
	 * The key files currently in pk/, as a sorted list.
	 *
	 * @return string[]
	 */
	private function keys_dir_listing() {
		$files = glob( MainWP_Keys_Manager::get_keys_dir() . '*' ) ?: array();
		sort( $files );
		return $files;
	}

	/**
	 * A create whose INSERT fails must delete the key file its staging
	 * minted instead of orphaning it in pk/.
	 *
	 * @return void
	 */
	public function test_failed_add_rolls_back_fresh_key_file() {
		global $wpdb;
		$store  = MainWP_Backup_Storage_Destinations::instance();
		$before = $this->keys_dir_listing();

		$break = function ( $query ) {
			if ( false !== strpos( (string) $query, 'INSERT' ) && false !== strpos( (string) $query, 'wp_backup_storage' ) ) {
				return 'INSERT INTO nonexistent_dest_table VALUES (1)';
			}
			return $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );

		$id = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Never lands',
				'settings' => $this->s3_settings(),
			)
		);

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$this->assertSame( 0, $id );
		$this->assertSame( $before, $this->keys_dir_listing(), 'A failed insert must leave pk/ exactly as it was - no orphaned fresh key.' );
	}

	/**
	 * An update whose row write fails must delete the freshly minted key
	 * file and leave the stored envelope's key untouched, so the
	 * destination stays decryptable.
	 *
	 * @return void
	 */
	public function test_failed_update_rolls_back_fresh_key_and_keeps_old() {
		global $wpdb;
		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Sticky settings',
				'settings' => $this->s3_settings(),
			)
		);

		$old_file_key = $this->envelope_file_key( $this->raw_settings( $id ) );
		$this->assertFileExists( $this->key_file_path( $old_file_key ) );
		$before = $this->keys_dir_listing();

		$break = function ( $query ) {
			if ( false !== strpos( (string) $query, 'UPDATE' ) && false !== strpos( (string) $query, 'wp_backup_storage' ) ) {
				return 'UPDATE nonexistent_dest_table SET broken = 1';
			}
			return $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );

		$new_settings           = $this->s3_settings();
		$new_settings['bucket'] = 'never-lands-bucket';
		$updated                = $store->update_destination( $id, array( 'settings' => $new_settings ) );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$this->assertFalse( $updated );
		$this->assertSame( $before, $this->keys_dir_listing(), 'A failed update must leave pk/ exactly as it was - fresh key rolled back, old key kept.' );
		$this->assertSame( $old_file_key, $this->envelope_file_key( $this->raw_settings( $id ) ), 'The stored envelope still references the surviving old key.' );

		$row = $store->get_destination( $id );
		$this->assertSame( 'example-bucket', $row->settings['bucket'], 'The stored settings must still decrypt after the failed update.' );
	}

	/**
	 * is_safe_host() blocks only the never-legitimate high-impact ranges:
	 * IPv4 link-local 169.254.0.0/16 (the cloud-metadata endpoint) and IPv6
	 * link-local fe80::/10. Public addresses pass.
	 *
	 * @return void
	 */
	public function test_is_safe_host_blocks_link_local_allows_public() {
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( '8.8.8.8' ), 'A public IPv4 is safe.' );
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( '2001:4860:4860::8888' ), 'A public IPv6 is safe.' );

		$this->assertFalse( MainWP_Backup_Storage_Destinations::is_safe_host( '169.254.169.254' ), 'The cloud-metadata endpoint is blocked.' );
		$this->assertFalse( MainWP_Backup_Storage_Destinations::is_safe_host( '169.254.1.1' ), 'IPv4 link-local is blocked.' );
		$this->assertFalse( MainWP_Backup_Storage_Destinations::is_safe_host( 'fe80::1' ), 'IPv6 link-local is blocked.' );
		$this->assertFalse( MainWP_Backup_Storage_Destinations::is_safe_host( '::ffff:169.254.169.254' ), 'IPv4-mapped metadata address is blocked.' );
		$this->assertFalse( MainWP_Backup_Storage_Destinations::is_safe_host( 'fd00:ec2::254' ), 'The AWS IPv6 metadata endpoint is blocked.' );
	}

	/**
	 * Loopback and RFC1918 are allowed: self-hosted storage (MinIO on
	 * 127.0.0.1, a NAS on the LAN) is a supported configuration, so the SSRF
	 * check must not break it.
	 *
	 * @return void
	 */
	public function test_is_safe_host_allows_loopback_and_rfc1918() {
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( '127.0.0.1' ), 'Loopback (self-hosted MinIO) is allowed.' );
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( '::1' ), 'IPv6 loopback is allowed.' );
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( '10.0.0.5' ), 'RFC1918 10/8 (LAN storage) is allowed.' );
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( '172.16.4.4' ), 'RFC1918 172.16/12 is allowed.' );
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( '192.168.1.50' ), 'RFC1918 192.168/16 is allowed.' );
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( 'fd12:3456:789a::1' ), 'A normal IPv6 ULA (self-hosted storage) is allowed.' );
	}

	/**
	 * An unresolvable host fails open: a DNS blip is not evidence of a rebind,
	 * and blocking on it would turn a transient failure into a permanent one.
	 * An empty host is a no-op the transport handles.
	 *
	 * @return void
	 */
	public function test_is_safe_host_fails_open_on_unresolvable_and_empty() {
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( 'no-such-host.invalid' ), 'An unresolvable host fails open.' );
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( '' ), 'An empty host is a no-op.' );
	}

	/**
	 * The mainwp_backup_resolved_host_ips filter is the deterministic seam
	 * over name resolution: when resolution lands on a blocked address the
	 * host is refused, and an empty result preserves the fail-open contract.
	 *
	 * @return void
	 */
	public function test_is_safe_host_uses_filtered_resolution_results() {
		$seen_host = null;
		$resolver  = function ( $ips, $host ) use ( &$seen_host ) {
			$seen_host = $host;
			return array( '169.254.169.254' );
		};
		add_filter( 'mainwp_backup_resolved_host_ips', $resolver, 10, 2 );
		$blocked = MainWP_Backup_Storage_Destinations::is_safe_host( 'storage.example.test' );
		remove_filter( 'mainwp_backup_resolved_host_ips', $resolver );

		$this->assertFalse( $blocked, 'A host whose resolution lands on a blocked address is refused.' );
		$this->assertSame( 'storage.example.test', $seen_host, 'The filter receives the host being resolved.' );

		add_filter( 'mainwp_backup_resolved_host_ips', '__return_empty_array' );
		$open = MainWP_Backup_Storage_Destinations::is_safe_host( 'storage.example.test' );
		remove_filter( 'mainwp_backup_resolved_host_ips', '__return_empty_array' );

		$this->assertTrue( $open, 'An empty resolution result preserves the fail-open contract.' );
	}

	/**
	 * The mainwp_backup_skip_ssrf_check filter is an escape hatch for setups
	 * that must reach an otherwise-blocked address.
	 *
	 * @return void
	 */
	public function test_is_safe_host_skip_filter_bypasses_block() {
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		$safe = MainWP_Backup_Storage_Destinations::is_safe_host( '169.254.169.254' );
		remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );

		$this->assertTrue( $safe, 'The skip filter allows an otherwise-blocked address.' );
	}
}
