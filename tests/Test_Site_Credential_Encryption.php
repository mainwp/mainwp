<?php
/**
 * Site credential at-rest encryption tests (MWP-1548).
 *
 * Covers MainWP_Credential_Storage::encrypt_credential() /
 * decrypt_credential() / is_credential_envelope() in isolation. The
 * encrypt/decrypt path round-trips through the
 * mainwp_encrypt_key_value / mainwp_decrypt_key_value filters when
 * MainWP_Keys_Manager is wired up; we register lightweight test stubs
 * so the helpers can be exercised without depending on a real keyfile
 * in the test environment.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Site_Credential_Encryption extends \WP_UnitTestCase {

	const ENVELOPE_PREFIX = '__mwpenc__:';

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
	// encrypt_credential
	// ---------------------------------------------------------------------

	public function test_encrypt_wraps_plaintext_in_prefixed_envelope(): void {
		$plaintext = 'super-secret-basic-auth-pw';
		$result    = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( $plaintext, 'http_pass' );

		$this->assertIsString( $result );
		$this->assertStringStartsWith( self::ENVELOPE_PREFIX, $result );
		$this->assertStringNotContainsString( $plaintext, $result );
	}

	public function test_encrypt_passes_through_empty_string(): void {
		$this->assertSame( '', \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( '', 'http_pass' ) );
	}

	public function test_encrypt_passes_through_null(): void {
		$this->assertNull( \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( null, 'http_pass' ) );
	}

	public function test_encrypt_passes_through_non_string_input(): void {
		$arr = array( 'not', 'a', 'string' );
		$this->assertSame( $arr, \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( $arr, 'http_pass' ) );
	}

	public function test_encrypt_is_idempotent_on_existing_envelope(): void {
		$plaintext = 'idempotent-test';
		$once      = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( $plaintext, 'http_pass' );
		$twice     = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( $once, 'http_pass' );
		$this->assertSame( $once, $twice );
	}

	public function test_encrypt_returns_false_when_filter_fails(): void {
		// Replace BOTH the test stub and the real MainWP_Keys_Manager
		// handler with a stub that returns false (simulates "encryption
		// layer down" -- missing keyfile, un-writable uploads dir, etc.).
		// remove_all_filters covers any priority the real handler used.
		remove_all_filters( 'mainwp_encrypt_key_value' );
		add_filter( 'mainwp_encrypt_key_value', '__return_false', 10, 4 );

		$result = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( 'should-fail', 'http_pass' );
		$this->assertFalse( $result );
	}

	// ---------------------------------------------------------------------
	// decrypt_credential
	// ---------------------------------------------------------------------

	public function test_decrypt_recovers_plaintext_from_envelope(): void {
		$plaintext = 'decrypt-target';
		$envelope  = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( $plaintext, 'http_pass' );
		$result    = \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $envelope );

		$this->assertSame( $plaintext, $result );
	}

	public function test_decrypt_passes_through_legacy_plaintext(): void {
		// A pre-MWP-1548 row: http_pass stored as a plain string.
		$legacy = 'plaintext-from-legacy-row';
		$result = \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $legacy );
		$this->assertSame( $legacy, $result );
	}

	public function test_decrypt_passes_through_empty_string(): void {
		$this->assertSame( '', \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( '' ) );
	}

	public function test_decrypt_passes_through_null(): void {
		$this->assertNull( \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( null ) );
	}

	public function test_decrypt_returns_empty_string_when_filter_fails(): void {
		// Simulate a missing keyfile: remove the decrypt stub mid-test so
		// the filter cannot recover the plaintext. The helper should drop
		// the unreadable envelope and surface an empty string rather than
		// returning the raw envelope (which would be forwarded as garbage
		// in a Basic Auth header).
		$envelope = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( 'real-pw', 'http_pass' );

		remove_filter( 'mainwp_decrypt_key_value', array( $this, 'stub_decrypt' ), 10 );

		$result = \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $envelope );
		$this->assertSame( '', $result );
	}

	public function test_decrypt_returns_empty_when_envelope_is_malformed(): void {
		// Prefixed marker but the JSON body is missing required keys.
		$broken = self::ENVELOPE_PREFIX . '{"encrypted_val":""}';
		$this->assertSame( '', \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $broken ) );
	}

	public function test_decrypt_returns_empty_when_envelope_json_is_invalid(): void {
		$broken = self::ENVELOPE_PREFIX . 'not-json';
		$this->assertSame( '', \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $broken ) );
	}

	// ---------------------------------------------------------------------
	// is_credential_envelope detection
	// ---------------------------------------------------------------------

	public function test_is_credential_envelope_true_for_prefixed_value(): void {
		$envelope = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( 'detect-me', 'http_pass' );
		$this->assertTrue( \MainWP\Dashboard\MainWP_Credential_Storage::is_credential_envelope( $envelope ) );
	}

	public function test_is_credential_envelope_false_for_plaintext(): void {
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Storage::is_credential_envelope( 'just-plaintext' ) );
	}

	public function test_is_credential_envelope_false_for_jsonish_password(): void {
		// A real password that happens to be JSON-shaped but lacks the
		// envelope prefix must NOT be misclassified as ciphertext.
		$jsonish = '{"encrypted_val":"sneaky","file_key":"yes"}';
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Storage::is_credential_envelope( $jsonish ) );
	}

	public function test_is_credential_envelope_false_for_empty(): void {
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Storage::is_credential_envelope( '' ) );
	}

	public function test_is_credential_envelope_false_for_non_string(): void {
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Storage::is_credential_envelope( null ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Storage::is_credential_envelope( 42 ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Credential_Storage::is_credential_envelope( array() ) );
	}

	// ---------------------------------------------------------------------
	// End-to-end round-trip
	// ---------------------------------------------------------------------

	public function test_encrypt_then_decrypt_round_trip(): void {
		$plaintext = 'roundtrip-basic-auth-pw-9876';
		$envelope  = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( $plaintext, 'http_pass' );
		$this->assertNotSame( $plaintext, $envelope );

		$decrypted = \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $envelope );
		$this->assertSame( $plaintext, $decrypted );
	}

	public function test_round_trip_handles_unicode_and_special_chars(): void {
		// HTTP Basic Auth passwords may contain colons, percent-encoding,
		// or non-ASCII bytes. Round-trip must preserve them exactly.
		$plaintext = "p@\$\$:w0rd!#% \xc3\xa9\xc3\xa8 \xe6\x97\xa5\xe6\x9c\xac";
		$envelope  = \MainWP\Dashboard\MainWP_Credential_Storage::encrypt_credential( $plaintext, 'http_pass' );
		$decrypted = \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $envelope );
		$this->assertSame( $plaintext, $decrypted );
	}

	// ---------------------------------------------------------------------
	// DB-level integration: add_website persists encrypted, raw column
	// holds an envelope, get_website_by_id returns it as-is for callers
	// to decrypt at the boundary.
	// ---------------------------------------------------------------------

	public function test_add_website_persists_encrypted_http_pass(): void {
		global $wpdb;

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$pubkey  = base64_encode( 'fake-pub-' . wp_generate_uuid4() );
		$privkey = base64_encode( 'fake-priv-' . wp_generate_uuid4() );

		$site_id = \MainWP\Dashboard\MainWP_DB::instance()->add_website(
			$user_id,
			'Test Site',
			'http://test-site-mwp1548-' . wp_generate_uuid4() . '.example/',
			'admin',
			$pubkey,
			$privkey,
			array(
				'http_user' => 'basicauthuser',
				'http_pass' => 'super-secret-pw-mwp1548',
			)
		);

		$this->assertNotFalse( $site_id, 'add_website returned false; insert should succeed' );

		// Read the raw column directly, bypassing any helper, to prove
		// the column stores ciphertext and not the plaintext.
		$table     = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$row_user  = $wpdb->get_var( $wpdb->prepare( "SELECT http_user FROM `{$table}` WHERE id = %d", $site_id ) );
		$row_pass  = $wpdb->get_var( $wpdb->prepare( "SELECT http_pass FROM `{$table}` WHERE id = %d", $site_id ) );

		$this->assertStringStartsWith( self::ENVELOPE_PREFIX, $row_user );
		$this->assertStringStartsWith( self::ENVELOPE_PREFIX, $row_pass );
		$this->assertStringNotContainsString( 'super-secret-pw-mwp1548', $row_pass );
		$this->assertStringNotContainsString( 'basicauthuser', $row_user );

		// Decrypt round-trip recovers plaintext.
		$this->assertSame( 'basicauthuser', \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $row_user ) );
		$this->assertSame( 'super-secret-pw-mwp1548', \MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $row_pass ) );
	}

	public function test_legacy_plaintext_row_survives_decrypt(): void {
		global $wpdb;

		// Manually insert a row that mimics a pre-MWP-1548 install where
		// http_pass was persisted as raw plaintext. Decrypt-on-use at
		// every consumer site MUST keep working for these rows.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$table = \MainWP\Dashboard\MainWP_DB::instance()->get_table_name( 'wp' );
		$wpdb->insert(
			$table,
			array(
				'userid'    => $user_id,
				'adminname' => 'admin',
				'name'      => 'Legacy Test',
				'url'       => 'http://legacy-site-' . wp_generate_uuid4() . '.example/',
				'pubkey'    => base64_encode( 'legacy-pub' ),
				'privkey'   => base64_encode( 'legacy-priv' ),
				'http_user' => 'legacy-user',
				'http_pass' => 'legacy-plaintext-pw',
			)
		);
		$site_id = $wpdb->insert_id;

		// Read the row directly (get_website_by_id JOINs against wp_sync
		// which we haven't populated). What matters here is that the raw
		// http_pass column is still plaintext, and decrypt_credential
		// pass-throughs it unchanged.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT http_user, http_pass FROM `{$table}` WHERE id = %d", $site_id ) );

		$this->assertSame( 'legacy-plaintext-pw', $row->http_pass );
		$this->assertSame(
			'legacy-plaintext-pw',
			\MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $row->http_pass )
		);
		$this->assertSame(
			'legacy-user',
			\MainWP\Dashboard\MainWP_Credential_Storage::decrypt_credential( $row->http_user )
		);
	}
}
