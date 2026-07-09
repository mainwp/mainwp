<?php
/**
 * MainWP Backup Archive Cipher Tests
 *
 * Verifies the per-unit AES-256-GCM primitive that seals backup archive
 * chunks before they leave the dashboard. Each sealed unit is the raw
 * framing nonce(12) . ciphertext . tag(16), so the on-the-wire size is
 * always plaintext + 28. The AAD binds a unit to its snapshot, artifact,
 * and offset, so a unit cannot be silently moved or swapped even when it
 * shares a data key with its neighbours.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Archive_Cipher;
use MainWP\Dashboard\MainWP_Backup_Cipher_Auth_Exception;
use MainWP\Dashboard\MainWP_Backup_Cipher_Malformed_Exception;

/**
 * Class MainWP_Backup_Archive_Cipher_Test
 */
class MainWP_Backup_Archive_Cipher_Test extends \WP_UnitTestCase {

	/**
	 * A 32-byte test key.
	 *
	 * @return string
	 */
	private function key() {
		return str_repeat( "\x2a", 32 );
	}

	/**
	 * seal then open returns the exact plaintext.
	 *
	 * @return void
	 */
	public function test_round_trip_recovers_plaintext() {
		$plain = 'the quick brown fox jumps over the lazy dog';
		$blob  = MainWP_Backup_Archive_Cipher::seal( $plain, $this->key(), 'snapshot:1:artifact:db.sql.gz:offset:0' );

		$this->assertNotSame( $plain, $blob );
		$this->assertStringNotContainsString( 'lazy dog', $blob, 'Ciphertext must not leak plaintext.' );
		$this->assertSame( $plain, MainWP_Backup_Archive_Cipher::open( $blob, $this->key(), 'snapshot:1:artifact:db.sql.gz:offset:0' ) );
	}

	/**
	 * A sealed unit is exactly plaintext + 28 bytes (nonce 12 + tag 16).
	 *
	 * @return void
	 */
	public function test_sealed_size_is_plaintext_plus_overhead() {
		$this->assertSame( 28, MainWP_Backup_Archive_Cipher::overhead() );

		foreach ( array( 0, 1, 16, 4096, 1048576 ) as $len ) {
			$blob = MainWP_Backup_Archive_Cipher::seal( str_repeat( 'x', $len ), $this->key(), 'aad' );
			$this->assertSame( $len + 28, strlen( $blob ), "Sealed length for {$len}-byte plaintext." );
		}
	}

	/**
	 * Two seals of identical plaintext differ (fresh random nonce each time).
	 *
	 * @return void
	 */
	public function test_each_seal_uses_a_fresh_nonce() {
		$a = MainWP_Backup_Archive_Cipher::seal( 'same', $this->key(), 'aad' );
		$b = MainWP_Backup_Archive_Cipher::seal( 'same', $this->key(), 'aad' );

		$this->assertNotSame( $a, $b, 'Nonce reuse: two seals of the same plaintext must differ.' );
		$this->assertSame( 'same', MainWP_Backup_Archive_Cipher::open( $a, $this->key(), 'aad' ) );
		$this->assertSame( 'same', MainWP_Backup_Archive_Cipher::open( $b, $this->key(), 'aad' ) );
	}

	/**
	 * Opening with the wrong key is rejected, not silently wrong - and
	 * classifies as an auth failure (indistinguishable at this layer from a
	 * wrong AAD or tampering), never as malformed/truncated.
	 *
	 * @return void
	 */
	public function test_open_with_wrong_key_throws() {
		$blob = MainWP_Backup_Archive_Cipher::seal( 'secret', $this->key(), 'aad' );

		$this->expectException( MainWP_Backup_Cipher_Auth_Exception::class );
		MainWP_Backup_Archive_Cipher::open( $blob, str_repeat( "\x00", 32 ), 'aad' );
	}

	/**
	 * Opening with a different AAD is rejected: a unit sealed for one
	 * (snapshot, artifact, offset) cannot be replayed at another. Classifies
	 * as an auth failure, same as a wrong key.
	 *
	 * @return void
	 */
	public function test_open_with_wrong_aad_throws() {
		$blob = MainWP_Backup_Archive_Cipher::seal( 'secret', $this->key(), 'snapshot:1:artifact:a.gz:offset:0' );

		$this->expectException( MainWP_Backup_Cipher_Auth_Exception::class );
		MainWP_Backup_Archive_Cipher::open( $blob, $this->key(), 'snapshot:1:artifact:b.gz:offset:0' );
	}

	/**
	 * A single flipped ciphertext byte fails authentication - classifies as
	 * an auth failure, same as a wrong key. By design this is the same
	 * exception type as test_open_with_wrong_key_throws: tampering and a
	 * wrong key are not distinguishable at the GCM layer.
	 *
	 * @return void
	 */
	public function test_tampered_ciphertext_throws() {
		$blob              = MainWP_Backup_Archive_Cipher::seal( 'authenticated bytes', $this->key(), 'aad' );
		$blob[20]          = ( "\x00" === $blob[20] ) ? "\x01" : "\x00";

		$this->expectException( MainWP_Backup_Cipher_Auth_Exception::class );
		MainWP_Backup_Archive_Cipher::open( $blob, $this->key(), 'aad' );
	}

	/**
	 * A blob shorter than the framing overhead is rejected, and classifies
	 * as malformed - genuinely distinguishable from an auth failure, since
	 * the overhead check runs before any key/tag verification.
	 *
	 * @return void
	 */
	public function test_truncated_blob_throws() {
		$this->expectException( MainWP_Backup_Cipher_Malformed_Exception::class );
		MainWP_Backup_Archive_Cipher::open( 'short', $this->key(), 'aad' );
	}

	/**
	 * The malformed and auth exception types are genuinely distinct classes
	 * (neither extends the other), so a caller can catch each separately.
	 *
	 * @return void
	 */
	public function test_malformed_and_auth_exceptions_are_distinct_types() {
		$this->assertFalse( is_a( MainWP_Backup_Cipher_Malformed_Exception::class, MainWP_Backup_Cipher_Auth_Exception::class, true ) );
		$this->assertFalse( is_a( MainWP_Backup_Cipher_Auth_Exception::class, MainWP_Backup_Cipher_Malformed_Exception::class, true ) );
		$this->assertTrue( is_subclass_of( MainWP_Backup_Cipher_Malformed_Exception::class, \RuntimeException::class ) );
		$this->assertTrue( is_subclass_of( MainWP_Backup_Cipher_Auth_Exception::class, \RuntimeException::class ) );
	}

	/**
	 * Empty plaintext round-trips (sealed unit is just nonce + tag).
	 *
	 * @return void
	 */
	public function test_empty_plaintext_round_trips() {
		$blob = MainWP_Backup_Archive_Cipher::seal( '', $this->key(), 'aad' );
		$this->assertSame( 28, strlen( $blob ) );
		$this->assertSame( '', MainWP_Backup_Archive_Cipher::open( $blob, $this->key(), 'aad' ) );
	}

	/**
	 * Binary plaintext with null bytes round-trips exactly.
	 *
	 * @return void
	 */
	public function test_binary_plaintext_round_trips() {
		$plain = random_bytes( 4096 );
		$blob  = MainWP_Backup_Archive_Cipher::seal( $plain, $this->key(), 'aad' );
		$this->assertSame( $plain, MainWP_Backup_Archive_Cipher::open( $blob, $this->key(), 'aad' ) );
	}

	/**
	 * A non-32-byte key is rejected at seal time.
	 *
	 * @return void
	 */
	public function test_short_key_throws() {
		$this->expectException( \RuntimeException::class );
		MainWP_Backup_Archive_Cipher::seal( 'data', 'too-short-key', 'aad' );
	}
}
