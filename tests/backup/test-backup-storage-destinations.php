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

use MainWP\Dashboard\MainWP_Backup_Storage_Crypto;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Credential_Storage;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Keys_Manager;

/**
 * Class MainWP_Backup_Storage_Destinations_Test
 */
class MainWP_Backup_Storage_Destinations_Test extends \WP_UnitTestCase {

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
	 * A pre-migration legacy envelope (the Sprint 5 AUTH_KEY-derived
	 * format) still decodes, so the cutover is order-independent.
	 *
	 * @return void
	 */
	public function test_legacy_gcm_envelope_still_decodes() {
		global $wpdb;

		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Legacy row',
				'settings' => array( 'bucket' => 'placeholder' ),
			)
		);

		$legacy = MainWP_Backup_Storage_Crypto::encrypt( (string) wp_json_encode( $this->s3_settings() ) );
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$wpdb->update( $table, array( 'settings' => $legacy ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.

		$row = $store->get_destination( $id );

		$this->assertFalse( $row->credentials_invalid );
		$this->assertSame( $this->s3_settings(), $row->settings );
	}

	/**
	 * The one-time migration re-encrypts legacy rows into credential
	 * envelopes and the settings round-trip unchanged.
	 *
	 * @return void
	 */
	public function test_migrate_legacy_settings_reencrypts() {
		global $wpdb;

		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'To migrate',
				'settings' => array( 'bucket' => 'placeholder' ),
			)
		);

		$legacy = MainWP_Backup_Storage_Crypto::encrypt( (string) wp_json_encode( $this->s3_settings() ) );
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$wpdb->update( $table, array( 'settings' => $legacy ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.

		$migrated = $store->migrate_legacy_settings();

		$this->assertSame( 1, $migrated );
		$this->assertTrue( MainWP_Credential_Storage::is_credential_envelope( $this->raw_settings( $id ) ), 'The migrated column holds a credential envelope.' );
		$this->assertSame( $this->s3_settings(), $store->get_destination( $id )->settings, 'Settings survive the re-encryption.' );

		$this->assertSame( 0, $store->migrate_legacy_settings(), 'The migration is idempotent.' );
	}

	/**
	 * Settings are never plaintext-passthrough: a non-envelope value is
	 * treated as unreadable, not as live credentials.
	 *
	 * @return void
	 */
	public function test_plaintext_settings_marked_invalid() {
		global $wpdb;

		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Garbage column',
				'settings' => $this->s3_settings(),
			)
		);

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$wpdb->update( $table, array( 'settings' => (string) wp_json_encode( $this->s3_settings() ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.

		$row = $store->get_destination( $id );

		$this->assertNull( $row->settings );
		$this->assertTrue( $row->credentials_invalid );
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
}
