<?php
/**
 * Per-extension license-key encryption tests (MWP-1546).
 *
 * Covers MainWP_Api_Manager::encrypt_activation_info() /
 * decrypt_activation_info() in isolation. The encrypt/decrypt path
 * round-trips through the mainwp_encrypt_key_value /
 * mainwp_decrypt_key_value filters when MainWP_Keys_Manager is wired
 * up; we register lightweight test stubs so the helpers can be
 * exercised without depending on a real keyfile in the test
 * environment.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Extension_License_Encryption extends \WP_UnitTestCase {

	/**
	 * Stub-encrypt: wraps the plaintext in the same {encrypted_val,
	 * file_key} envelope shape that MainWP_Keys_Manager produces, but
	 * with a trivially reversible body so the decrypt stub can recover
	 * the plaintext without depending on actual encryption.
	 *
	 * @param mixed  $unused   Filter chain initial value (false).
	 * @param string $value    Plaintext to encrypt.
	 * @param string $prefix   Keyfile prefix supplied by caller.
	 * @param mixed  $file_key Existing keyfile id, false on first encryption.
	 * @return array Envelope.
	 */
	public function stub_encrypt( $unused, $value, $prefix, $file_key ) {
		return array(
			'encrypted_val' => 'STUB:' . base64_encode( $value ),
			'file_key'      => $file_key ?: ( $prefix . 'stubkey' ),
		);
	}

	/**
	 * Stub-decrypt: reverses stub_encrypt.
	 *
	 * @param mixed $unused           Filter chain initial value (false).
	 * @param array $encrypted_data   The envelope produced by stub_encrypt.
	 * @param mixed $def_val          Default to return on failure.
	 * @return string|mixed
	 */
	public function stub_decrypt( $unused, $encrypted_data, $def_val ) {
		if ( is_array( $encrypted_data ) && isset( $encrypted_data['encrypted_val'] ) ) {
			$cipher = $encrypted_data['encrypted_val'];
			if ( is_string( $cipher ) && 0 === strpos( $cipher, 'STUB:' ) ) {
				$decoded = base64_decode( substr( $cipher, 5 ), true );
				if ( false !== $decoded ) {
					return $decoded;
				}
			}
		}
		return $def_val;
	}

	public function setUp(): void {
		parent::setUp();
		add_filter( 'mainwp_encrypt_key_value', array( $this, 'stub_encrypt' ), 10, 4 );
		add_filter( 'mainwp_decrypt_key_value', array( $this, 'stub_decrypt' ), 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'mainwp_encrypt_key_value', array( $this, 'stub_encrypt' ), 10 );
		remove_filter( 'mainwp_decrypt_key_value', array( $this, 'stub_decrypt' ), 10 );
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// encrypt_activation_info
	// ---------------------------------------------------------------------

	public function test_encrypt_replaces_plaintext_api_key_with_envelope(): void {
		$info = array(
			'api_key'         => 'real-license-key-1234567890',
			'activated_key'   => 'Activated',
			'product_id'      => 42,
		);

		$encrypted = \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'foo-extension', $info );

		$this->assertIsArray( $encrypted['api_key'] );
		$this->assertArrayHasKey( 'encrypted_val', $encrypted['api_key'] );
		$this->assertArrayHasKey( 'file_key', $encrypted['api_key'] );
		$this->assertNotSame( 'real-license-key-1234567890', $encrypted['api_key']['encrypted_val'] );
		// Sibling fields untouched.
		$this->assertSame( 'Activated', $encrypted['activated_key'] );
		$this->assertSame( 42, $encrypted['product_id'] );
	}

	public function test_encrypt_passes_through_when_api_key_missing(): void {
		$info = array( 'activated_key' => 'Deactivated' );
		$result = \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'foo', $info );
		$this->assertSame( $info, $result );
	}

	public function test_encrypt_passes_through_when_api_key_empty(): void {
		$info = array( 'api_key' => '' );
		$result = \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'foo', $info );
		$this->assertSame( $info, $result );
	}

	public function test_encrypt_returns_input_unchanged_when_not_array(): void {
		$this->assertSame( 'not-an-array', \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'foo', 'not-an-array' ) );
		$this->assertSame( null, \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'foo', null ) );
	}

	// ---------------------------------------------------------------------
	// decrypt_activation_info
	// ---------------------------------------------------------------------

	public function test_decrypt_recovers_plaintext_from_envelope(): void {
		$info = array(
			'api_key'         => 'real-license-key-9876543210',
			'activated_key'   => 'Activated',
		);
		$encrypted = \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'foo-extension', $info );
		$decrypted = \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( $encrypted );

		$this->assertSame( 'real-license-key-9876543210', $decrypted['api_key'] );
		$this->assertSame( 'Activated', $decrypted['activated_key'] );
	}

	public function test_decrypt_passes_through_legacy_plaintext_string(): void {
		// A pre-MWP-1546 row: api_key stored as a plain string.
		$info = array(
			'api_key'       => 'legacy-plaintext-key',
			'activated_key' => 'Activated',
		);
		$result = \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( $info );
		$this->assertSame( 'legacy-plaintext-key', $result['api_key'] );
	}

	public function test_decrypt_passes_through_when_api_key_missing(): void {
		$info = array( 'activated_key' => 'Deactivated' );
		$this->assertSame( $info, \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( $info ) );
	}

	public function test_decrypt_returns_empty_string_when_filter_fails(): void {
		// Simulate a missing keyfile: remove the decrypt stub mid-test so
		// the filter cannot recover the plaintext. The helper should drop
		// the unreadable envelope and surface an empty string rather than
		// returning the raw envelope array (which would otherwise be
		// truthy and forwarded to mainwp.com as garbage).
		remove_filter( 'mainwp_decrypt_key_value', array( $this, 'stub_decrypt' ), 10 );

		$encrypted = array(
			'api_key'       => array(
				'encrypted_val' => 'STUB:' . base64_encode( 'real-key' ),
				'file_key'      => 'extension_foo_stubkey',
			),
			'activated_key' => 'Activated',
		);
		$result = \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( $encrypted );
		$this->assertSame( '', $result['api_key'] );
		$this->assertSame( 'Activated', $result['activated_key'] );
	}

	public function test_decrypt_returns_input_unchanged_when_not_array(): void {
		$this->assertSame( null, \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( null ) );
		$this->assertSame( false, \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( false ) );
	}

	// ---------------------------------------------------------------------
	// End-to-end round-trip
	// ---------------------------------------------------------------------

	public function test_encrypt_then_decrypt_round_trip(): void {
		$plaintext = 'roundtrip-license-key-xyz';
		$info      = array( 'api_key' => $plaintext, 'product_id' => 7 );

		$encrypted = \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'roundtrip-ext', $info );
		$this->assertNotSame( $plaintext, $encrypted['api_key'] );

		$decrypted = \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( $encrypted );
		$this->assertSame( $plaintext, $decrypted['api_key'] );
		$this->assertSame( 7, $decrypted['product_id'] );
	}
}
