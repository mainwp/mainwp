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
use MainWP\Dashboard\MainWP_DB;

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
		global $wpdb;

		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'At rest',
				'settings' => $this->s3_settings(),
			)
		);

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- raw column probe, identifiers cannot be prepared.

		$this->assertTrue( MainWP_Backup_Storage_Crypto::is_encrypted( $raw ), 'Settings column must hold an envelope.' );
		$this->assertStringNotContainsString( 'wJalrXUtnFEMI', $raw );
		$this->assertStringNotContainsString( 'AKIAIOSFODNN7EXAMPLE', $raw );
		$this->assertStringNotContainsString( 'example-bucket', $raw );
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
	 * null settings, never garbage.
	 *
	 * @return void
	 */
	public function test_undecryptable_settings_surface_reentry_state() {
		global $wpdb;

		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Rotated key',
				'settings' => $this->s3_settings(),
			)
		);

		// Simulate AUTH_KEY rotation: replace the envelope with one sealed
		// under a different key.
		$foreign = MainWP_Backup_Storage_Crypto::encrypt( wp_json_encode( $this->s3_settings() ), 'some-other-auth-key' );
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$wpdb->update( $table, array( 'settings' => $foreign ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.

		$row = $store->get_destination( $id );

		$this->assertNotNull( $row, 'The destination row itself must still load.' );
		$this->assertNull( $row->settings );
		$this->assertTrue( $row->credentials_invalid );
	}
}
