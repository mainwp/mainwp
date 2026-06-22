<?php
/**
 * MainWP Backup Encryption Tests
 *
 * Verifies the key hierarchy that backs archive encryption: one stable
 * per-dashboard KEK in a pk/ key file, per-snapshot DEKs wrapped under it,
 * and a recovery passphrase that re-wraps the same KEK for disaster
 * recovery. Changing the passphrase must never regenerate the KEK, or
 * every already-wrapped DEK would be orphaned.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Encryption;
use MainWP\Dashboard\MainWP_Keys_Manager;

/**
 * Class MainWP_Backup_Encryption_Test
 */
class MainWP_Backup_Encryption_Test extends \WP_UnitTestCase {

	/**
	 * Remove any KEK key file and options so each test starts clean.
	 *
	 * @return void
	 */
	private function reset_state() {
		$enc = MainWP_Backup_Encryption::instance();
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		delete_option( MainWP_Backup_Encryption::ENABLED_OPTION );
		delete_option( MainWP_Backup_Encryption::RECOVERY_OPTION );
	}

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_state();
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->reset_state();
		parent::tear_down();
	}

	/**
	 * The KEK is created once and is stable across reads.
	 *
	 * @return void
	 */
	public function test_kek_is_created_once_and_stable() {
		$enc = MainWP_Backup_Encryption::instance();

		$kek = $enc->get_or_create_kek();
		$this->assertSame( 32, strlen( $kek ) );
		$this->assertSame( $kek, $enc->get_or_create_kek(), 'KEK must be stable across calls.' );
		$this->assertSame( $kek, $enc->load_kek(), 'load_kek must return the stored KEK.' );
	}

	/**
	 * A DEK wraps under the KEK and unwraps back, bound to its snapshot id.
	 *
	 * @return void
	 */
	public function test_dek_wrap_unwrap_round_trip() {
		$enc = MainWP_Backup_Encryption::instance();
		$kek = $enc->get_or_create_kek();
		$dek = $enc->generate_dek();
		$this->assertSame( 32, strlen( $dek ) );

		$wrapped = $enc->wrap_dek( $dek, 42, $kek );
		$this->assertSame( $dek, $enc->unwrap_dek( $wrapped, 42, $kek ) );
	}

	/**
	 * A DEK wrapped for one snapshot will not unwrap under another
	 * snapshot id (the wrap AAD binds the snapshot).
	 *
	 * @return void
	 */
	public function test_dek_unwrap_rejects_wrong_snapshot_id() {
		$enc     = MainWP_Backup_Encryption::instance();
		$kek     = $enc->get_or_create_kek();
		$wrapped = $enc->wrap_dek( $enc->generate_dek(), 42, $kek );

		$this->expectException( \RuntimeException::class );
		$enc->unwrap_dek( $wrapped, 43, $kek );
	}

	/**
	 * Unwrapping a DEK with the wrong KEK is rejected.
	 *
	 * @return void
	 */
	public function test_dek_unwrap_rejects_wrong_kek() {
		$enc     = MainWP_Backup_Encryption::instance();
		$kek     = $enc->get_or_create_kek();
		$wrapped = $enc->wrap_dek( $enc->generate_dek(), 7, $kek );

		$this->expectException( \RuntimeException::class );
		$enc->unwrap_dek( $wrapped, 7, str_repeat( "\x00", 32 ) );
	}

	/**
	 * The KEK version id is deterministic for a KEK and differs across KEKs.
	 *
	 * @return void
	 */
	public function test_kek_version_id_is_deterministic() {
		$enc = MainWP_Backup_Encryption::instance();
		$k1  = random_bytes( 32 );
		$k2  = random_bytes( 32 );

		$this->assertSame( $enc->kek_version_id( $k1 ), $enc->kek_version_id( $k1 ) );
		$this->assertNotSame( $enc->kek_version_id( $k1 ), $enc->kek_version_id( $k2 ) );
		$this->assertSame( 16, strlen( $enc->kek_version_id( $k1 ) ) );
	}

	/**
	 * Setting a recovery passphrase produces a self-describing blob that
	 * recovers the exact KEK with the right passphrase.
	 *
	 * @return void
	 */
	public function test_recovery_passphrase_round_trip() {
		$enc = MainWP_Backup_Encryption::instance();

		$blob = $enc->set_recovery_passphrase( 'correct horse battery staple' );

		$this->assertSame( 'pbkdf2-sha256', $blob['algo'] );
		$this->assertGreaterThanOrEqual( 600000, $blob['iters'] );
		$this->assertNotEmpty( $blob['salt'] );
		$this->assertNotEmpty( $blob['wrapped_kek'] );
		$this->assertNotEmpty( $blob['kek_version_id'] );

		$kek = $enc->load_kek();
		$this->assertSame( $kek, $enc->recover_kek( 'correct horse battery staple', $blob ) );
		$this->assertSame( $blob['kek_version_id'], $enc->kek_version_id( $kek ) );
	}

	/**
	 * Recovery with the wrong passphrase is rejected.
	 *
	 * @return void
	 */
	public function test_recovery_with_wrong_passphrase_throws() {
		$enc  = MainWP_Backup_Encryption::instance();
		$blob = $enc->set_recovery_passphrase( 'the right passphrase here' );

		$this->expectException( \RuntimeException::class );
		$enc->recover_kek( 'the wrong passphrase here', $blob );
	}

	/**
	 * Changing the passphrase keeps the same KEK, so a DEK wrapped before
	 * the change still unwraps after it (M2: never regenerate the KEK).
	 *
	 * @return void
	 */
	public function test_passphrase_change_keeps_existing_deks_readable() {
		$enc = MainWP_Backup_Encryption::instance();

		$enc->set_recovery_passphrase( 'first passphrase value here' );
		$kek_before = $enc->load_kek();
		$wrapped    = $enc->wrap_dek( $enc->generate_dek(), 99, $kek_before );
		$dek_before = $enc->unwrap_dek( $wrapped, 99, $kek_before );

		$blob2     = $enc->set_recovery_passphrase( 'second passphrase value here' );
		$kek_after = $enc->load_kek();

		$this->assertSame( $kek_before, $kek_after, 'Passphrase change must not regenerate the KEK.' );
		$this->assertSame( $dek_before, $enc->unwrap_dek( $wrapped, 99, $kek_after ), 'Old DEK must still unwrap.' );
		$this->assertSame( $kek_after, $enc->recover_kek( 'second passphrase value here', $blob2 ) );
	}

	/**
	 * The manifest HMAC is keyed by the KEK: deterministic, and any change
	 * to the signed bytes or the key changes it.
	 *
	 * @return void
	 */
	public function test_manifest_hmac_detects_tampering() {
		$enc  = MainWP_Backup_Encryption::instance();
		$kek  = $enc->get_or_create_kek();
		$body = '{"format":1,"artifacts":[]}';

		$mac = $enc->manifest_hmac( $body, $kek );
		$this->assertSame( $mac, $enc->manifest_hmac( $body, $kek ) );
		$this->assertNotSame( $mac, $enc->manifest_hmac( $body . ' ', $kek ) );
		$this->assertNotSame( $mac, $enc->manifest_hmac( $body, random_bytes( 32 ) ) );
		$this->assertTrue( hash_equals( $mac, $enc->manifest_hmac( $body, $kek ) ) );
	}

	/**
	 * Encryption is "armed" only when the toggle is on AND a KEK AND a
	 * recovery blob all exist. Enabling the toggle alone is not enough.
	 *
	 * @return void
	 */
	public function test_is_armed_requires_toggle_kek_and_recovery() {
		$enc = MainWP_Backup_Encryption::instance();

		$this->assertFalse( $enc->is_armed(), 'Fresh state is not armed.' );

		update_option( MainWP_Backup_Encryption::ENABLED_OPTION, '1' );
		$this->assertFalse( $enc->is_armed(), 'Toggle alone, no passphrase: not armed.' );

		$enc->set_recovery_passphrase( 'arming passphrase value here' );
		$this->assertTrue( $enc->is_armed(), 'Toggle + KEK + recovery: armed.' );

		update_option( MainWP_Backup_Encryption::ENABLED_OPTION, '' );
		$this->assertFalse( $enc->is_armed(), 'Toggle off: not armed even with keys present.' );
	}

	/**
	 * The stored recovery blob round-trips back to the KEK with the
	 * passphrase (the disaster-recovery read path: key file gone, option
	 * intact).
	 *
	 * @return void
	 */
	public function test_recover_from_stored_option_blob() {
		$enc = MainWP_Backup_Encryption::instance();
		$enc->set_recovery_passphrase( 'recover me from the option' );
		$kek = $enc->load_kek();

		// Simulate the pk/ KEK file being lost; the option blob remains.
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		$this->assertNull( $enc->load_kek(), 'KEK file is gone.' );

		$blob = $enc->get_recovery_blob();
		$this->assertSame( $kek, $enc->recover_kek( 'recover me from the option', $blob ) );
	}

	/**
	 * Snapshot encryption metadata carries the cipher markers and a wrapped
	 * DEK that recovers under the KEK, bound to the snapshot id it was minted
	 * for.
	 *
	 * @return void
	 */
	public function test_snapshot_encryption_meta_round_trip() {
		$enc  = MainWP_Backup_Encryption::instance();
		$meta = $enc->snapshot_encryption_meta( 5 );

		$this->assertSame( 1, $meta['enc'] );
		$this->assertSame( 'aes-256-gcm', $meta['cipher'] );
		$this->assertSame( 28, $meta['overhead'] );
		$this->assertNotEmpty( $meta['wrapped_dek'] );
		$this->assertNotEmpty( $meta['kek_version_id'] );

		$kek = $enc->load_kek();
		$dek = $enc->dek_from_meta( $meta, 5, $kek );
		$this->assertSame( 32, strlen( $dek ) );

		$this->expectException( \RuntimeException::class );
		$enc->dek_from_meta( $meta, 6, $kek );
	}

	/**
	 * meta_is_encrypted only treats an array with a truthy enc flag as
	 * encrypted; everything else (legacy empty metadata, non-arrays) is
	 * plaintext.
	 *
	 * @return void
	 */
	public function test_meta_is_encrypted_edge_cases() {
		$this->assertTrue( MainWP_Backup_Encryption::meta_is_encrypted( array( 'enc' => 1 ) ) );
		$this->assertFalse( MainWP_Backup_Encryption::meta_is_encrypted( array() ) );
		$this->assertFalse( MainWP_Backup_Encryption::meta_is_encrypted( array( 'enc' => 0 ) ) );
		$this->assertFalse( MainWP_Backup_Encryption::meta_is_encrypted( null ) );
		$this->assertFalse( MainWP_Backup_Encryption::meta_is_encrypted( 'enc' ) );
	}
}
