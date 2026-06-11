<?php
/**
 * MainWP Backup Storage Crypto Tests
 *
 * Verifies the encryption envelope used for cloud storage destination
 * settings: AES-256-GCM with a key derived from AUTH_KEY via HKDF. The
 * envelope must fail closed on any tampering or key change so that a
 * rotated AUTH_KEY surfaces as "re-enter credentials" rather than
 * garbage plaintext reaching a provider.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Storage_Crypto;

/**
 * Class MainWP_Backup_Storage_Crypto_Test
 */
class MainWP_Backup_Storage_Crypto_Test extends \WP_UnitTestCase {

	/**
	 * A representative settings payload with a secret in it.
	 *
	 * @return string
	 */
	private function secret_payload() {
		return wp_json_encode(
			array(
				'bucket'     => 'example-bucket',
				'region'     => 'us-east-1',
				'access_key' => 'AKIAIOSFODNN7EXAMPLE',
				'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
			)
		);
	}

	/**
	 * Encrypt then decrypt returns the original plaintext.
	 *
	 * @return void
	 */
	public function test_round_trip_returns_original_plaintext() {
		$plaintext = $this->secret_payload();

		$stored = MainWP_Backup_Storage_Crypto::encrypt( $plaintext );

		$this->assertIsString( $stored, 'Encrypt must produce a stored string.' );
		$this->assertSame( $plaintext, MainWP_Backup_Storage_Crypto::decrypt( $stored ) );
	}

	/**
	 * The stored value never contains the plaintext secret.
	 *
	 * @return void
	 */
	public function test_stored_value_does_not_contain_plaintext() {
		$plaintext = $this->secret_payload();

		$stored = MainWP_Backup_Storage_Crypto::encrypt( $plaintext );

		$this->assertIsString( $stored );
		$this->assertStringNotContainsString( 'wJalrXUtnFEMI', $stored, 'Secret key must not appear in the stored value.' );
		$this->assertStringNotContainsString( 'AKIAIOSFODNN7EXAMPLE', $stored, 'Access key must not appear in the stored value.' );
		$this->assertStringNotContainsString( 'example-bucket', $stored, 'Settings fields must not appear in the stored value.' );
	}

	/**
	 * Two encryptions of the same plaintext differ (random per-record IV).
	 *
	 * @return void
	 */
	public function test_encrypt_uses_random_iv_per_record() {
		$plaintext = $this->secret_payload();

		$first  = MainWP_Backup_Storage_Crypto::encrypt( $plaintext );
		$second = MainWP_Backup_Storage_Crypto::encrypt( $plaintext );

		$this->assertNotSame( $first, $second, 'A static IV would make ciphertexts repeat.' );
	}

	/**
	 * A flipped ciphertext byte fails closed.
	 *
	 * @return void
	 */
	public function test_tampered_ciphertext_fails_closed() {
		$stored = MainWP_Backup_Storage_Crypto::encrypt( $this->secret_payload() );
		$this->assertIsString( $stored );

		$prefix_len = strlen( MainWP_Backup_Storage_Crypto::ENVELOPE_PREFIX );
		$raw        = base64_decode( substr( $stored, $prefix_len ), true );
		$this->assertNotFalse( $raw );

		// Flip one bit in the middle of the ciphertext (past the 12-byte IV).
		$pos               = 12 + intdiv( strlen( $raw ) - 12, 2 );
		$raw[ $pos ]       = chr( ord( $raw[ $pos ] ) ^ 0x01 );
		$tampered          = MainWP_Backup_Storage_Crypto::ENVELOPE_PREFIX . base64_encode( $raw );

		$this->assertFalse( MainWP_Backup_Storage_Crypto::decrypt( $tampered ), 'GCM tag must reject tampered ciphertext.' );
	}

	/**
	 * A truncated auth tag fails closed.
	 *
	 * @return void
	 */
	public function test_truncated_envelope_fails_closed() {
		$stored = MainWP_Backup_Storage_Crypto::encrypt( $this->secret_payload() );
		$this->assertIsString( $stored );

		$prefix_len = strlen( MainWP_Backup_Storage_Crypto::ENVELOPE_PREFIX );
		$raw        = base64_decode( substr( $stored, $prefix_len ), true );
		$truncated  = MainWP_Backup_Storage_Crypto::ENVELOPE_PREFIX . base64_encode( substr( $raw, 0, strlen( $raw ) - 8 ) );

		$this->assertFalse( MainWP_Backup_Storage_Crypto::decrypt( $truncated ) );
	}

	/**
	 * Decrypting with a different key (AUTH_KEY rotation) returns false,
	 * never garbage plaintext.
	 *
	 * @return void
	 */
	public function test_rotated_key_fails_closed() {
		$stored = MainWP_Backup_Storage_Crypto::encrypt( $this->secret_payload(), 'old-auth-key-material' );
		$this->assertIsString( $stored );

		$this->assertSame( $this->secret_payload(), MainWP_Backup_Storage_Crypto::decrypt( $stored, 'old-auth-key-material' ) );
		$this->assertFalse( MainWP_Backup_Storage_Crypto::decrypt( $stored, 'new-auth-key-material' ), 'A rotated key must fail closed.' );
	}

	/**
	 * Empty key material fails closed on encrypt.
	 *
	 * @return void
	 */
	public function test_empty_key_material_fails_closed_on_encrypt() {
		$this->assertFalse( MainWP_Backup_Storage_Crypto::encrypt( $this->secret_payload(), '' ) );
	}

	/**
	 * Values without the envelope prefix are not decryptable.
	 *
	 * @return void
	 */
	public function test_non_envelope_value_fails_closed() {
		$this->assertFalse( MainWP_Backup_Storage_Crypto::decrypt( 'not-an-envelope' ) );
		$this->assertFalse( MainWP_Backup_Storage_Crypto::decrypt( '' ) );
		$this->assertFalse( MainWP_Backup_Storage_Crypto::decrypt( MainWP_Backup_Storage_Crypto::ENVELOPE_PREFIX . '!!!not-base64!!!' ) );
	}

	/**
	 * Envelope detection distinguishes encrypted values from plaintext.
	 *
	 * @return void
	 */
	public function test_is_encrypted_detection() {
		$stored = MainWP_Backup_Storage_Crypto::encrypt( $this->secret_payload() );

		$this->assertTrue( MainWP_Backup_Storage_Crypto::is_encrypted( $stored ) );
		$this->assertFalse( MainWP_Backup_Storage_Crypto::is_encrypted( $this->secret_payload() ) );
		$this->assertFalse( MainWP_Backup_Storage_Crypto::is_encrypted( '' ) );
	}
}
