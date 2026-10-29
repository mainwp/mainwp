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

/** License encryption and retirement preserve stored credentials. */
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

	/** Install reversible encryption fixtures. */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'mainwp_encrypt_key_value', array( $this, 'stub_encrypt' ), 10, 4 );
		add_filter( 'mainwp_decrypt_key_value', array( $this, 'stub_decrypt' ), 10, 3 );
	}

	/** Remove the encryption fixtures. */
	public function tearDown(): void {
		remove_filter( 'mainwp_encrypt_key_value', array( $this, 'stub_encrypt' ), 10 );
		remove_filter( 'mainwp_decrypt_key_value', array( $this, 'stub_decrypt' ), 10 );
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// encrypt_activation_info
	// ---------------------------------------------------------------------

	/** License keys are encrypted without changing activation metadata. */
	public function test_encrypt_replaces_plaintext_api_key_with_envelope(): void {
		$info = array(
			'api_key'       => 'real-license-key-1234567890',
			'activated_key' => 'Activated',
			'product_id'    => 42,
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

	/** Activation metadata can be stored without a license key. */
	public function test_encrypt_passes_through_when_api_key_missing(): void {
		$info   = array( 'activated_key' => 'Deactivated' );
		$result = \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'foo', $info );
		$this->assertSame( $info, $result );
	}

	/** An empty key remains empty during deactivation. */
	public function test_encrypt_passes_through_when_api_key_empty(): void {
		$info   = array( 'api_key' => '' );
		$result = \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'foo', $info );
		$this->assertSame( $info, $result );
	}

	/** Non-array activation values pass through unchanged. */
	public function test_encrypt_returns_input_unchanged_when_not_array(): void {
		$this->assertSame( 'not-an-array', \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'foo', 'not-an-array' ) );
		$this->assertSame( null, \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'foo', null ) );
	}

	// ---------------------------------------------------------------------
	// decrypt_activation_info
	// ---------------------------------------------------------------------

	/** Stored envelopes recover the original license key. */
	public function test_decrypt_recovers_plaintext_from_envelope(): void {
		$info      = array(
			'api_key'       => 'real-license-key-9876543210',
			'activated_key' => 'Activated',
		);
		$encrypted = \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'foo-extension', $info );
		$decrypted = \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( $encrypted );

		$this->assertSame( 'real-license-key-9876543210', $decrypted['api_key'] );
		$this->assertSame( 'Activated', $decrypted['activated_key'] );
	}

	/** Legacy plaintext keys remain readable. */
	public function test_decrypt_passes_through_legacy_plaintext_string(): void {
		// A pre-MWP-1546 row: api_key stored as a plain string.
		$info   = array(
			'api_key'       => 'legacy-plaintext-key',
			'activated_key' => 'Activated',
		);
		$result = \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( $info );
		$this->assertSame( 'legacy-plaintext-key', $result['api_key'] );
	}

	/** Activation metadata needs no decryption. */
	public function test_decrypt_passes_through_when_api_key_missing(): void {
		$info = array( 'activated_key' => 'Deactivated' );
		$this->assertSame( $info, \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( $info ) );
	}

	/** Failed decryption must not expose an envelope as a usable key. */
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
		$result    = \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( $encrypted );
		$this->assertSame( '', $result['api_key'] );
		$this->assertSame( 'Activated', $result['activated_key'] );
	}

	/** Non-array activation values need no decryption. */
	public function test_decrypt_returns_input_unchanged_when_not_array(): void {
		$this->assertSame( null, \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( null ) );
		$this->assertSame( false, \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( false ) );
	}

	// ---------------------------------------------------------------------
	// Key-file retirement on license writes
	// ---------------------------------------------------------------------

	/**
	 * Observe key retirement through the shared deletion action.
	 *
	 * @param array $deleted Collector, by reference.
	 */
	private function capture_deletes( array &$deleted ): void {
		add_action(
			'mainwp_delete_key_file',
			function ( $file_key ) use ( &$deleted ) {
				$deleted[] = $file_key;
			}
		);
	}

	/**
	 * Seed the stored activation option with an envelope referencing a
	 * known key file. The stub encryptor reuses one file_key per prefix,
	 * so observing a retirement needs a distinct seeded key.
	 *
	 * @param string $ext_key  Extension key.
	 * @param string $file_key Key file id the seeded envelope references.
	 */
	private function seed_stored_activation( string $ext_key, string $file_key ): void {
		update_option(
			$ext_key . '_APIManAdder',
			array(
				'api_key'       => array(
					'encrypted_val' => 'STUB:' . base64_encode( 'old-key' ),
					'file_key'      => $file_key,
				),
				'activated_key' => 'Activated',
			)
		);
	}

	/** A replacement license retires the old key after persistence. */
	public function test_set_activation_info_retires_old_key_file_on_api_key_change(): void {
		$deleted = array();
		$this->capture_deletes( $deleted );
		$this->seed_stored_activation( 'foo', 'extension_foo_oldkey' );

		\MainWP\Dashboard\MainWP_Api_Manager::instance()->set_activation_info(
			'foo',
			array(
				'api_key'       => 'replacement-license-key',
				'activated_key' => 'Activated',
			)
		);

		$this->assertSame( array( 'extension_foo_oldkey' ), $deleted );
	}

	/** A reused key must survive the activation update. */
	public function test_set_activation_info_keeps_key_file_when_envelope_reuses_it(): void {
		$deleted = array();
		$this->capture_deletes( $deleted );
		// The stub reuses extension_foo_stubkey for every foo encryption;
		// deleting it would orphan the envelope just written.
		$this->seed_stored_activation( 'foo', 'extension_foo_stubkey' );

		\MainWP\Dashboard\MainWP_Api_Manager::instance()->set_activation_info(
			'foo',
			array(
				'api_key'       => 'replacement-license-key',
				'activated_key' => 'Activated',
			)
		);

		$this->assertSame( array(), $deleted );
	}

	/** Clearing a license retires its unused key. */
	public function test_set_activation_info_retires_key_file_when_api_key_cleared(): void {
		$deleted = array();
		$this->capture_deletes( $deleted );
		$this->seed_stored_activation( 'foo', 'extension_foo_oldkey' );

		// The deactivation path (remove_activation_info) writes an empty
		// api_key; the stored envelope's key file has nothing left to
		// decrypt.
		\MainWP\Dashboard\MainWP_Api_Manager::instance()->set_activation_info(
			'foo',
			array(
				'api_key'       => '',
				'activated_key' => 'Deactivated',
			)
		);

		$this->assertSame( array( 'extension_foo_oldkey' ), $deleted );
	}

	/** A first activation has no old key to retire. */
	public function test_set_activation_info_deletes_nothing_without_stored_envelope(): void {
		$deleted = array();
		$this->capture_deletes( $deleted );

		\MainWP\Dashboard\MainWP_Api_Manager::instance()->set_activation_info(
			'fresh-ext',
			array(
				'api_key'       => 'first-license-key',
				'activated_key' => 'Activated',
			)
		);

		$this->assertSame( array(), $deleted );
	}

	/** Only superseded nonempty keys are retired. */
	public function test_retire_key_file_if_changed_helper_contract(): void {
		$deleted = array();
		$this->capture_deletes( $deleted );

		\MainWP\Dashboard\MainWP_Credential_Storage::retire_key_file_if_changed( 'old-key', 'new-key' );
		\MainWP\Dashboard\MainWP_Credential_Storage::retire_key_file_if_changed( 'same-key', 'same-key' );
		\MainWP\Dashboard\MainWP_Credential_Storage::retire_key_file_if_changed( '', 'new-key' );
		\MainWP\Dashboard\MainWP_Credential_Storage::retire_key_file_if_changed( null, 'new-key' );
		\MainWP\Dashboard\MainWP_Credential_Storage::retire_key_file_if_changed( 'cleared-key', '' );

		$this->assertSame( array( 'old-key', 'cleared-key' ), $deleted );
	}

	// ---------------------------------------------------------------------
	// End-to-end round-trip
	// ---------------------------------------------------------------------

	/** License encryption preserves the key and sibling metadata. */
	public function test_encrypt_then_decrypt_round_trip(): void {
		$plaintext = 'roundtrip-license-key-xyz';
		$info      = array(
			'api_key'    => $plaintext,
			'product_id' => 7,
		);

		$encrypted = \MainWP\Dashboard\MainWP_Api_Manager::encrypt_activation_info( 'roundtrip-ext', $info );
		$this->assertNotSame( $plaintext, $encrypted['api_key'] );

		$decrypted = \MainWP\Dashboard\MainWP_Api_Manager::decrypt_activation_info( $encrypted );
		$this->assertSame( $plaintext, $decrypted['api_key'] );
		$this->assertSame( 7, $decrypted['product_id'] );
	}
	/** A stored key name cannot escape the private key directory. */
	public function test_activation_retirement_preserves_invalid_key_path(): void {
		$root   = sys_get_temp_dir() . '/license-retirement-' . wp_generate_uuid4();
		$upload = static function ( $dirs ) use ( $root ) {
			$dirs['basedir'] = $root;
			return $dirs;
		};
		wp_mkdir_p( $root );
		add_filter( 'mainwp_get_wp_upload_dir', $upload );
		$dir  = \MainWP\Dashboard\MainWP_Keys_Manager::get_keys_dir();
		$name = 'license-retirement-' . wp_generate_uuid4();
		$file = dirname( $dir ) . '/' . $name;
		$x    = dirname( $dir ) . '/x';
		wp_mkdir_p( $dir );
		file_put_contents( $file, 'fixture' );
		file_put_contents( $x, 'outside fixture' );
		$this->seed_stored_activation( 'invalid-retirement', '../' . $name );
		$delete = array( \MainWP\Dashboard\MainWP_Keys_Manager::instance(), 'delete_key_file' );
		$added  = false === has_action( 'mainwp_delete_key_file', $delete );
		if ( $added ) {
			add_action( 'mainwp_delete_key_file', $delete );
		}
		try {
			$this->assertTrue(
				\MainWP\Dashboard\MainWP_Api_Manager::instance()->set_activation_info(
					'invalid-retirement',
					array( 'api_key' => '' )
				)
			);
			clearstatcache( true, $file );
			$this->assertFileExists( $file );
			$this->assertSame( '', get_option( 'invalid-retirement_APIManAdder' )['api_key'] );
			do_action( 'mainwp_delete_key_file', '../x' );
			clearstatcache( true, $x );
			$this->assertFileExists( $x );
			$this->assertSame( 'outside fixture', file_get_contents( $x ) );
		} finally {
			if ( $added ) {
				remove_action( 'mainwp_delete_key_file', $delete );
			}
			remove_filter( 'mainwp_get_wp_upload_dir', $upload );
			foreach ( array( $file, $x, dirname( $dir ) . '/index.php' ) as $path ) {
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
			rmdir( $dir );
			rmdir( dirname( $dir ) );
			rmdir( $root );
		}
	}
}
