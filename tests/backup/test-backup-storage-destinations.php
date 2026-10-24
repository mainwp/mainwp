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

	/** Repeated reads share one query and decrypt, while callers own their rows. */
	public function test_request_memo_returns_independent_rows() {
		global $wpdb;
		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Memo',
				'settings' => $this->s3_settings(),
			)
		);
		$calls = 0;
		$count = static function ( $value ) use ( &$calls ) {
			++$calls;
			return $value;
		};
		add_filter( 'mainwp_decrypt_key_value', $count, PHP_INT_MAX );
		$queries = $wpdb->num_queries;
		try {
			$first                     = $store->get_destination( $id );
			$first->name               = 'Changed by caller';
			$first->settings['bucket'] = 'changed';
			$second                    = $store->get_destination( $id );
			$this->assertSame( 'Memo', $second->name );
			$this->assertSame( $this->s3_settings(), $second->settings );
			$this->assertNotSame( $first, $second );
			$this->assertSame( 1, $wpdb->num_queries - $queries );
			$this->assertSame( 1, $calls );
		} finally {
			remove_filter( 'mainwp_decrypt_key_value', $count, PHP_INT_MAX );
		}
	}

	/** Every mutation makes subsequent reads observe the stored row. */
	public function test_request_memo_invalidates_writes_defaults_and_delete() {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$first = $store->add_destination(
			array(
				'provider'   => 's3',
				'name'       => 'First',
				'settings'   => $this->s3_settings(),
				'is_default' => 1,
			)
		);
		$this->assertSame( 1, (int) $store->get_destination( $first )->is_default );
		$second = $store->add_destination(
			array(
				'provider'   => 's3',
				'name'       => 'Second',
				'settings'   => $this->s3_settings(),
				'is_default' => 1,
			)
		);
		$this->assertSame( 0, (int) $store->get_destination( $first )->is_default );
		$this->assertSame( 1, (int) $store->get_destination( $second )->is_default );
		$this->assertTrue( $store->update_destination( $first, array( 'is_default' => 1 ), $store->get_destination( $first ) ) );
		$this->assertSame( 0, (int) $store->get_destination( $second )->is_default );
		$this->assertTrue( $store->update_destination( $second, array( 'is_default' => 1 ) ) );
		$this->assertSame( 0, (int) $store->get_destination( $first )->is_default );
		$this->assertTrue( $store->update_destination( $second, array( 'name' => 'Renamed' ) ) );
		$this->assertSame( 'Renamed', $store->get_destination( $second )->name );
		$settings           = $this->s3_settings();
		$settings['bucket'] = 'new-bucket';
		$this->assertTrue( $store->update_destination( $second, array( 'settings' => $settings ) ) );
		$this->assertSame( $settings, $store->get_destination( $second )->settings );
		$this->assertTrue( $store->delete_destination( $second ) );
		$this->assertNull( $store->get_destination( $second ) );
		$this->assertNull( $store->get_default() );
	}

	/** Default and explicit reads reuse the decrypted settings. */
	public function test_default_and_id_reads_share_decryption() {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider'   => 's3',
				'name'       => 'Default',
				'settings'   => $this->s3_settings(),
				'is_default' => 1,
			)
		);
		$calls = 0;
		$count = static function ( $value ) use ( &$calls ) {
			++$calls;
			return $value;
		};
		add_filter( 'mainwp_decrypt_key_value', $count, PHP_INT_MAX );
		try {
			$default                     = $store->get_default();
			$default->settings['bucket'] = 'caller-change';
			$this->assertSame( $this->s3_settings(), $store->get_destination( $id )->settings );
			$this->assertSame( 1, $calls );
		} finally {
			remove_filter( 'mainwp_decrypt_key_value', $count, PHP_INT_MAX );
		}
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
	 * Resolver differences must not let numeric spellings bypass the metadata guard.
	 *
	 * @dataProvider data_ip_like_host_safety
	 *
	 * @param string $host Host as received.
	 * @param bool   $safe Expected safety verdict.
	 *
	 * @return void
	 */
	public function test_is_safe_host_rejects_noncanonical_ips_without_dns( $host, $safe ) {
		add_filter( 'mainwp_backup_resolved_host_ips', '__return_empty_array' );
		try {
			$this->assertSame( $safe, MainWP_Backup_Storage_Destinations::is_safe_host( $host ), $host . ' must not depend on resolver support for numeric spellings.' );
		} finally {
			remove_filter( 'mainwp_backup_resolved_host_ips', '__return_empty_array' );
		}
	}

	/**
	 * Ordinary storage names and LAN addresses remain supported alongside the guard.
	 *
	 * @return array
	 */
	public function data_ip_like_host_safety() {
		return array(
			'dotted hex metadata'   => array( '0xa9.0xfe.0xa9.0xfe', false ),
			'octal metadata'        => array( '0251.0376.0251.0376', false ),
			'decimal metadata'      => array( '2852039166', false ),
			'hex metadata'          => array( '0xa9fea9fe', false ),
			'dotted metadata'       => array( '169.254.169.254', false ),
			'percent-encoded dots'  => array( '169%2e254%2e169%2e254', false ),
			'percent-encoded octet' => array( '%31%36%39.254.169.254', false ),
			'percent-encoded hex'   => array( '0xa9%2e0xfe%2e0xa9%2e0xfe', false ),
			'hostname'              => array( 'storage.example.test', true ),
			'LAN address'           => array( '192.168.1.10', true ),
		);
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

	/**
	 * Hosts as wp_parse_url() and admins hand them over: bracketed IPv6,
	 * IPv4-mapped IPv6 in either notation, and a trailing dot.
	 *
	 * @return array
	 */
	public function data_blocked_host_spellings() {
		return array(
			'bracketed mapped metadata'     => array( '[::ffff:169.254.169.254]' ),
			'bracketed hex mapped metadata' => array( '[::ffff:a9fe:a9fe]' ),
			'bracketed link-local'          => array( '[fe80::1]' ),
			'bracketed AWS IPv6 metadata'   => array( '[fd00:ec2::254]' ),
			'trailing dot metadata'         => array( '169.254.169.254.' ),
			'hex mapped metadata'           => array( '::ffff:a9fe:a9fe' ),
		);
	}

	/**
	 * Every spelling of a metadata or link-local address is blocked, so the
	 * S3, SFTP and Dropbox guards cannot be walked around by notation.
	 *
	 * @dataProvider data_blocked_host_spellings
	 *
	 * @param string $host Host as received.
	 *
	 * @return void
	 */
	public function test_is_safe_host_blocks_every_spelling( $host ) {
		$this->assertFalse( MainWP_Backup_Storage_Destinations::is_safe_host( $host ), $host . ' must be blocked.' );
	}

	/**
	 * Normalizing the notation does not block ordinary bracketed addresses.
	 *
	 * @return void
	 */
	public function test_is_safe_host_allows_bracketed_ordinary_addresses() {
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( '[::1]' ) );
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( '[2001:4860:4860::8888]' ) );
		$this->assertTrue( MainWP_Backup_Storage_Destinations::is_safe_host( '[::ffff:10.0.0.5]' ) );
	}

	/**
	 * Plain HTTP to storage is allowed only when the host stays on this
	 * machine or the private network: loopback, RFC1918 and IPv6 ULA,
	 * including bracketed IPv6 literals as wp_parse_url returns them.
	 *
	 * @return void
	 */
	public function test_is_private_network_host_accepts_loopback_rfc1918_and_ula() {
		foreach ( array( '127.0.0.1', '::1', '10.0.0.5', '172.16.4.4', '172.31.255.1', '192.168.1.50', 'fd12:3456:789a::1', '[fd12:3456:789a::1]', '::ffff:10.0.0.1' ) as $host ) {
			$this->assertTrue( MainWP_Backup_Storage_Destinations::is_private_network_host( $host ), $host . ' is a private network address.' );
		}
	}

	/**
	 * Public addresses, the ranges next to RFC1918, and hosts that do not
	 * resolve are not private: the check fails closed, unlike is_safe_host().
	 *
	 * @return void
	 */
	public function test_is_private_network_host_refuses_public_and_unresolvable() {
		foreach ( array( '8.8.8.8', '172.32.0.1', '192.169.0.1', '11.0.0.1', '2001:4860:4860::8888', '', 'no-such-host.invalid' ) as $host ) {
			$this->assertFalse( MainWP_Backup_Storage_Destinations::is_private_network_host( $host ), '"' . $host . '" is not a private network address.' );
		}
	}

	/**
	 * A hostname is private only when every address it resolves to is: one
	 * public record is enough to send the request over the internet.
	 *
	 * @return void
	 */
	public function test_is_private_network_host_uses_every_resolved_address() {
		$resolver = function ( $ips, $host ) {
			return 'nas.lan' === $host ? array( '192.168.1.20' ) : array( '192.168.1.20', '203.0.113.10' );
		};
		add_filter( 'mainwp_backup_resolved_host_ips', $resolver, 10, 2 );
		$lan   = MainWP_Backup_Storage_Destinations::is_private_network_host( 'nas.lan' );
		$mixed = MainWP_Backup_Storage_Destinations::is_private_network_host( 'mixed.example.test' );
		remove_filter( 'mainwp_backup_resolved_host_ips', $resolver );

		$this->assertTrue( $lan, 'A hostname resolving only to a LAN address is private.' );
		$this->assertFalse( $mixed, 'A hostname with any public address is not private.' );
	}

	/**
	 * CGNAT 100.64.0.0/10 counts as private: Tailscale and similar VPNs hand
	 * out these addresses, and plain-HTTP storage over them is supported.
	 * The neighbors on either side of the /10 stay public.
	 *
	 * @return void
	 */
	public function test_is_private_network_host_accepts_cgnat_range_only() {
		foreach ( array( '100.64.0.1', '100.100.1.2', '100.127.255.254', '::ffff:100.64.0.1' ) as $host ) {
			$this->assertTrue( MainWP_Backup_Storage_Destinations::is_private_network_host( $host ), $host . ' is in 100.64.0.0/10.' );
		}
		foreach ( array( '100.63.255.255', '100.128.0.1', '::ffff:8.8.8.8' ) as $host ) {
			$this->assertFalse( MainWP_Backup_Storage_Destinations::is_private_network_host( $host ), $host . ' is public.' );
		}
	}

	/**
	 * The metadata and link-local addresses the SSRF guard blocks are never
	 * eligible for HTTP, even fd00:ec2::254 inside ULA space.
	 *
	 * @return void
	 */
	public function test_is_private_network_host_refuses_blocked_addresses() {
		foreach ( array( 'fd00:ec2::254', '[fd00:ec2::254]', '169.254.169.254', 'fe80::1', '::ffff:169.254.169.254' ) as $host ) {
			$this->assertFalse( MainWP_Backup_Storage_Destinations::is_private_network_host( $host ), $host . ' is not eligible for HTTP.' );
		}
	}
	/** Credential replacement preserves a location; account/path changes do not. */
	public function test_retention_binding_survives_credentials_but_not_location_changes() {
		$protection = '\\MainWP\\Dashboard\\MainWP_Backup_Retention_Protection';
		$cases      = array(
			's3'   => array(
				array(
					'bucket'     => 'archive',
					'access_key' => 'old-key',
					'secret_key' => 'old-secret',
				),
				array(
					'access_key' => 'new-key',
					'secret_key' => 'new-secret',
					'sse'        => 'AES256',
				),
				'bucket',
			),
			'gcs'  => array(
				array(
					'bucket'     => 'archive',
					'access_key' => 'old-key',
					'secret_key' => 'old-secret',
				),
				array(
					'access_key' => 'new-key',
					'secret_key' => 'new-secret',
					'region'     => 'us-east1',
				),
				'bucket',
			),
			'sftp' => array(
				array(
					'host'      => 'server.example',
					'username'  => 'alice',
					'base_path' => '/archive',
					'password'  => 'old',
				),
				array(
					'password'         => 'new',
					'private_key'      => 'new-key',
					'host_fingerprint' => 'SHA256:known',
				),
				'username',
			),
		);
		foreach ( $cases as $provider => list( $settings, $rotation, $location ) ) {
			$row           = (object) array(
				'provider' => $provider,
				'settings' => $settings,
			);
			$binding       = $protection::destination_binding( $row );
			$row->settings = array_merge( $settings, $rotation );
			$this->assertSame( $binding, $protection::destination_binding( $row ), $provider . ' credential rotation must preserve archive eligibility.' );
			$row->settings[ $location ] = 'another-target';
			$this->assertNotSame( $binding, $protection::destination_binding( $row ), $provider . ' target change must invalidate old evidence.' );
		}
	}

	/** A stale background identity save cannot replace an intervening settings edit. */
	public function test_retention_identity_compare_and_swap_preserves_concurrent_edit() {
		$store  = MainWP_Backup_Storage_Destinations::instance();
		$id     = $store->add_destination(
			array(
				'provider' => 'dropbox',
				'name'     => 'Before',
				'settings' => array( 'refresh_token' => 'old-token' ),
			)
		);
		$before = $store->get_destination( $id );
		$this->assertTrue(
			$store->update_destination(
				$id,
				array(
					'name'     => 'Concurrent edit',
					'settings' => array( 'refresh_token' => 'new-token' ),
				)
			)
		);
		$this->assertFalse( $store->update_destination( $id, array( 'settings' => $before->settings ), $before ) );
		$after = $store->get_destination( $id );
		$this->assertSame( 'Concurrent edit', $after->name );
		$this->assertSame( 'new-token', $after->settings['refresh_token'] );
	}

	/** A zero-row CAS rolls back staged encryption while preserving the live key. */
	public function test_retention_identity_cas_refusal_rolls_back_encryption() {
		$store    = MainWP_Backup_Storage_Destinations::instance();
		$id       = $store->add_destination(
			array(
				'provider' => 'dropbox',
				'name'     => 'Dropbox',
				'settings' => array( 'refresh_token' => 'live-token' ),
			)
		);
		$source   = $store->get_destination( $id );
		$before   = $this->keys_dir_listing();
		$envelope = $this->raw_settings( $id );
		$refuse   = static function ( $sql ) {
			return str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, 'wp_backup_storage' ) ? 'SELECT 1 WHERE 0=1' : $sql;
		};
		add_filter( 'query', $refuse );
		try {
			$this->assertFalse( $store->update_destination( $id, array( 'settings' => array( 'refresh_token' => 'never-landed' ) ), $source ) );
		} finally {
			remove_filter( 'query', $refuse );
		}
		$this->assertSame( $before, $this->keys_dir_listing() );
		$this->assertSame( $envelope, $this->raw_settings( $id ) );
		$this->assertSame( 'live-token', $store->get_destination( $id )->settings['refresh_token'] );
	}
	/** The SQL predicate fences an edit landing after the initial source read. */
	public function test_retention_identity_cas_fences_a_write_between_read_and_update() {
		$store     = MainWP_Backup_Storage_Destinations::instance();
		$id        = $store->add_destination(
			array(
				'provider' => 'dropbox',
				'name'     => 'Dropbox',
				'settings' => array( 'refresh_token' => 'old-token' ),
			)
		);
		$source    = $store->get_destination( $id );
		$key_count = count( $this->keys_dir_listing() );
		$raced     = false;
		$race      = static function ( $sql ) use ( $store, $id, &$raced ) {
			if ( ! $raced && str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, 'wp_backup_storage' ) ) {
				$raced = true;
				$store->update_destination( $id, array( 'settings' => array( 'refresh_token' => 'concurrent-token' ) ) );
			}
			return $sql;
		};
		add_filter( 'query', $race );
		try {
			$this->assertFalse( $store->update_destination( $id, array( 'settings' => array( 'refresh_token' => 'stale-worker-token' ) ), $source ) );
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assertTrue( $raced );
		$this->assertSame( 'concurrent-token', $store->get_destination( $id )->settings['refresh_token'] );
		$this->assertCount( $key_count, $this->keys_dir_listing(), 'The refused worker must retire only its unpersisted key.' );
	}
}
